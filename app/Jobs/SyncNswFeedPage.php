<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Sources\Nsw\Feed;
use App\Sources\Nsw\FeedPageSyncer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetches and stores one page, then queues the next one. One page per job
 * keeps each job well under Laravel Cloud's 90 s cap. `nsw:sync --now` does
 * not use this job; it loops pages in the command instead.
 */
class SyncNswFeedPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 85;

    public function __construct(
        public Feed $feed,
        public int $pageNumber,
        public int $syncRunId,
    ) {}

    public function handle(FeedPageSyncer $syncer): void
    {
        $run = SyncRun::findOrFail($this->syncRunId);

        if ($syncer->sync($run, $this->feed, $this->pageNumber)) {
            self::dispatch($this->feed, $this->pageNumber + 1, $this->syncRunId)
                ->delay(now()->addMilliseconds(config('nsw.page_delay_ms')));
        }
    }

    public function failed(\Throwable $e): void
    {
        SyncRun::whereKey($this->syncRunId)->update(['error' => "page {$this->pageNumber}: ".$e->getMessage()]);
    }
}
