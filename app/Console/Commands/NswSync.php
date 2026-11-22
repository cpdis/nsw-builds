<?php

namespace App\Console\Commands;

use App\Jobs\SyncNswFeedPage;
use App\Models\SyncRun;
use App\Sources\Nsw\Feed;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Nightly incremental sync, and the same path for backfills:
 *
 *   php artisan nsw:sync                      # all feeds, updated since last run minus 2 days
 *   php artisan nsw:sync cc --since=2026-11-01
 *   php artisan nsw:sync da --from=2023-01-01 --to=2023-12-31 --council="CITY OF PARRAMATTA"
 *   php artisan nsw:sync oc --now             # run inline instead of queueing (backfills from a Mac)
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

    public function handle(): int
    {
        $feeds = collect($this->argument('feed') ?: array_column(Feed::cases(), 'value'))
            ->map(fn (string $f) => Feed::tryFrom(strtolower($f)) ?? $this->fail("Unknown feed: {$f}"));

        foreach ($feeds as $feed) {
            $filters = $this->filtersFor($feed);
            $run = SyncRun::create(['feed' => $feed->value, 'filters' => $filters, 'started_at' => now()]);

            $this->info("{$feed->endpoint()}: ".json_encode($filters));

            $job = new SyncNswFeedPage($feed, $filters, 1, $run->id);
            $this->option('now') ? dispatch_sync($job) : dispatch($job);
        }

        return self::SUCCESS;
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
        $last = SyncRun::where('feed', $feed->value)
            ->whereNotNull('finished_at')->whereNull('error')
            ->latest('started_at')->value('started_at');

        // Coverage is complete from 1 Jul 2021; earlier lodgements exist but are patchy.
        $since = $last ? CarbonImmutable::parse($last)->subDays(config('nsw.overlap_days')) : CarbonImmutable::parse('2019-01-01');

        return $since->timezone(config('nsw.timezone'))->toDateString();
    }
}
