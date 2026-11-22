<?php

namespace App\Sources\Nsw;

use App\Models\SyncRun;

/** Fetches and stores one page of a sync run. Shared by the queued job and `--now`. */
class FeedPageSyncer
{
    public function __construct(
        private readonly EplanningClient $client,
        private readonly ApplicationUpserter $upserter,
    ) {}

    /** @return bool whether there is another page after this one */
    public function sync(SyncRun $run, Feed $feed, int $pageNumber): bool
    {
        $page = $this->client->page($feed, $pageNumber, config('nsw.page_size'), $run->filters);
        $result = $this->upserter->upsert($feed, $page->records);

        $run->increment('pages');
        $run->increment('records', $result['stored']);
        $run->increment('skipped', $result['skipped']);
        $run->update(['total_count' => $page->totalCount]);

        if ($page->isLast() || $page->records === []) {
            $run->update(['finished_at' => now()]);
            // TODO(milestone 2): dispatch the linker here once it exists.

            return false;
        }

        return true;
    }
}
