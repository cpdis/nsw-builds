<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Sources\Nsw\ApplicationUpserter;
use App\Sources\Nsw\EplanningClient;
use App\Sources\Nsw\Feed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetches and stores one page, then queues the next one. One page per job
 * keeps each job well under Laravel Cloud's 90 s cap. `nsw:sync --now` calls
 * syncPage() in a loop instead, so a backfill never touches the queue.
 */
class SyncNswFeedPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 85;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /** @param array<string, mixed> $filters */
    public function __construct(
        public Feed $feed,
        public array $filters,
        public int $pageNumber,
        public int $syncRunId,
    ) {}

    public function handle(EplanningClient $client, ApplicationUpserter $upserter): void
    {
        if ($this->syncPage($client, $upserter)) {
            return;
        }

        self::dispatch($this->feed, $this->filters, $this->pageNumber + 1, $this->syncRunId)
            ->delay(now()->addMilliseconds(config('nsw.page_delay_ms')));
    }

    /** Fetch and store this page. Returns true when it was the last page and the run is finished. */
    public function syncPage(EplanningClient $client, ApplicationUpserter $upserter): bool
    {
        $page = $client->page($this->feed, $this->pageNumber, config('nsw.page_size'), $this->filters);
        $stored = $upserter->upsert($this->feed, $page->records);

        $run = SyncRun::findOrFail($this->syncRunId);
        $run->increment('pages');
        $run->increment('records', $stored);

        if (! $page->isLast()) {
            return false;
        }

        $run->update(['finished_at' => now()]);
        // TODO(milestone 2): dispatch the linker here once it exists.

        return true;
    }

    public function failed(\Throwable $e): void
    {
        SyncRun::whereKey($this->syncRunId)->update(['error' => "page {$this->pageNumber}: ".$e->getMessage()]);
    }
}
