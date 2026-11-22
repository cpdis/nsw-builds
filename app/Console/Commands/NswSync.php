<?php

namespace App\Console\Commands;

use App\Jobs\SyncNswFeedPage;
use App\Models\SyncRun;
use App\Sources\Nsw\ApplicationUpserter;
use App\Sources\Nsw\EplanningClient;
use App\Sources\Nsw\Feed;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Nightly incremental sync, and the same path for backfills:
 *
 *   php artisan nsw:sync                      # all feeds, updated since last run minus 2 days
 *   php artisan nsw:sync cc --since=2026-11-01
 *   php artisan nsw:sync da --from=2023-01-01 --to=2023-12-31 --council="CITY OF PARRAMATTA"
 *   php artisan nsw:sync oc --now             # run every page inline instead of queueing (backfills from a Mac)
 *
 * Only the plain scheduled form (no --since, --from, --to or --council) is an
 * incremental run, and only a finished incremental run moves the watermark.
 */
class NswSync extends Command
{
    protected $signature = 'nsw:sync
        {feed?* : da, cdc, cc, oc (default: all)}
        {--since= : ApplicationLastUpdatedFrom (YYYY-MM-DD)}
        {--from= : LodgementDateFrom, for backfills}
        {--to= : LodgementDateTo, for backfills}
        {--council=* : Council name(s), upper case}
        {--now : Process pages inline rather than queueing}';

    protected $description = 'Pull NSW Planning Portal DA, CDC, CC and OC records into applications';

    public function handle(EplanningClient $client, ApplicationUpserter $upserter): int
    {
        $feeds = collect($this->argument('feed') ?: array_column(Feed::cases(), 'value'))
            ->map(fn (string $f) => Feed::tryFrom(strtolower($f)) ?? $this->fail("Unknown feed: {$f}"));

        $status = self::SUCCESS;

        foreach ($feeds as $feed) {
            $filters = $this->filtersFor($feed);
            $run = SyncRun::create([
                'feed' => $feed->value,
                'filters' => $filters,
                'incremental' => $this->isIncremental(),
                'started_at' => now(),
            ]);

            $this->info("{$feed->endpoint()}: ".json_encode($filters));

            if (! $this->option('now')) {
                dispatch(new SyncNswFeedPage($feed, $filters, 1, $run->id));

                continue;
            }

            if (! $this->runInline($client, $upserter, $feed, $filters, $run->id)) {
                $status = self::FAILURE;
            }
        }

        return $status;
    }

    /**
     * Loop the pages here rather than letting each job dispatch the next: a
     * self-dispatched job would land on the real queue (or nest, under the sync
     * driver, and grow memory with every page).
     *
     * @param  array<string, mixed>  $filters
     */
    private function runInline(EplanningClient $client, ApplicationUpserter $upserter, Feed $feed, array $filters, int $runId): bool
    {
        for ($pageNumber = 1; ; $pageNumber++) {
            $job = new SyncNswFeedPage($feed, $filters, $pageNumber, $runId);

            try {
                if ($job->syncPage($client, $upserter)) {
                    return true;
                }
            } catch (Throwable $e) {
                $job->failed($e);
                $this->error("{$feed->endpoint()} page {$pageNumber}: {$e->getMessage()}");

                return false;
            }

            Sleep::for(config('nsw.page_delay_ms'))->milliseconds();
        }
    }

    private function isIncremental(): bool
    {
        return ! $this->option('since') && ! $this->option('from') && ! $this->option('to') && ! $this->option('council');
    }

    /** @return array<string, mixed> */
    private function filtersFor(Feed $feed): array
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
        $last = SyncRun::where('feed', $feed->value)->where('incremental', true)
            ->whereNotNull('finished_at')->whereNull('error')
            ->latest('started_at')->value('started_at');

        // Coverage is complete from 1 Jul 2021; earlier lodgements exist but are patchy.
        $since = $last ? CarbonImmutable::parse($last)->subDays(config('nsw.overlap_days')) : CarbonImmutable::parse('2019-01-01');

        return $since->timezone(config('nsw.timezone'))->toDateString();
    }
}
