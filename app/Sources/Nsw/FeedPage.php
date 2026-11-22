<?php

namespace App\Sources\Nsw;

/** One page of a feed, as the API's envelope describes it. */
final class FeedPage
{
    /** @param list<array<string, mixed>> $records */
    public function __construct(
        public readonly array $records,
        public readonly int $pageNumber,
        public readonly int $totalPages,
        public readonly int $totalCount,
    ) {}

    public function isLast(): bool
    {
        return $this->pageNumber >= $this->totalPages;
    }
}
