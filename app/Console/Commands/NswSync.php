<?php

namespace App\Console\Commands;

use App\Jobs\SyncNswFeedPage;
use App\Models\SyncRun;
use App\Sources\Nsw\Feed;
use App\Sources\Nsw\FeedPageSyncer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Nightly incremental sync, and the same path for backfills:
 *
 *   php artisan nsw:sync                      # all feeds, updated since last incremental run minus 2 days
 *   php artisan nsw:sync cc --since=2026-11-01
 *   php artisan nsw:sync da --from=2023-01-01 --to=2023-12-31 --council="CITY OF PARRAMATTA"
 *   php artisan nsw:sync oc --now             # every page inline, no queue (backfills from a Mac)
 *
 * Only a plain run (no --since, --from, --to or --council) is "incremental"
 * and moves the nightly watermark. Scoped runs never do.
 */
class NswSync extends Command
{
    protected $signature = 'nsw:sync
        {feed?* : da, cdc, cc, oc (default: all)}
        {--since= : ApplicationLastUpdatedFrom (YYYY-MM-DD)}
        {--from= : LodgementDateFrom, for backfills}
        {--to= : LodgementDateTo, for backfills}
        {--council=* : Council name(s), upper case}
        {--now : Process every page inline rather than queueing}';

    protected $description = 'Pull NSW Planning Portal DA, CDC, CC and OC records into applications';

    public function handle(FeedPageSyncer $syncer): int
    {
        $feeds = collect($this->argument('feed') ?: array_column(Feed::cases(), 'value'))
            ->map(fn (string $f) => Feed::tryFrom(strtolower($f)) ?? $this->fail("Unknown feed: {$f}"));

        $kind = $this->isScoped() ? SyncRun::SCOPED : SyncRun::INCREMENTAL;
        $status = self::SUCCESS;

        foreach ($feeds as $feed) {
            $filters = $this->filtersFor($feed, $kind);
            $run = SyncRun::create(['feed' => $feed->value, 'kind' => $kind, 'filters' => $filters, 'started_at' => now()]);

            $this->info("{$feed->endpoint()} ({$kind}): ".json_encode($filters));

            if (! $this->option('now')) {
                SyncNswFeedPage::dispatch($feed, 1, $run->id);

                continue;
            }

            $page = 1;
            try {
                while ($syncer->sync($run, $feed, $page)) {
                    $page++;
                    Sleep::for(config('nsw.page_delay_ms'))->milliseconds();
                }
                $this->line("  {$run->fresh()->records} records in {$page} page(s)");
            } catch (Throwable $e) {
                $run->update(['error' => "page {$page}: ".$e->getMessage()]);
                $this->error("  failed on page {$page}: {$e->getMessage()}");
                $status = self::FAILURE;
            }
        }

        return $status;
    }

    private function isScoped(): bool
    {
        return $this->option('since') || $this->option('from') || $this->option('to') || $this->option('council');
    }

    /** @return array<string, mixed> */
    private function filtersFor(Feed $feed, string $kind): array
    {
        $filters = [];

        if ($this->option('from') || $this->option('to')) {
            $filters['LodgementDateFrom'] = $this->option('from');
            $filters['LodgementDateTo'] = $this->option('to');
        } else {
            $filters['ApplicationLastUpdatedFrom'] = $this->option('since') ?? $this->defaultSince($feed);
        }

        if ($councils = $this->option('council')) {
            $filters['CouncilName'] = array_map('strtoupper', $councils);
        }

        return array_filter($filters, fn ($v) => $v !== null && $v !== []);
    }

    private function defaultSince(Feed $feed): string
    {
        $last = SyncRun::where('feed', $feed->value)
            ->where('kind', SyncRun::INCREMENTAL)
            ->whereNotNull('finished_at')->whereNull('error')
            ->latest('started_at')->value('started_at');

        // Coverage is complete from 1 Jul 2021; earlier lodgements exist but are patchy.
        $since = $last ? CarbonImmutable::parse($last)->subDays(config('nsw.overlap_days')) : CarbonImmutable::parse('2019-01-01');

        return $since->timezone(config('nsw.timezone'))->toDateString();
    }
}
