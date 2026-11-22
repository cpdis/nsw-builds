<?php

namespace App\Sources\Nsw;

/** One page of a feed, as the eplanning envelope describes it. */
final readonly class FeedPage
{
    /** @param list<array<string, mixed>> $records */
    public function __construct(
        public array $records,
        public int $pageNumber,
        public int $totalPages,
    ) {}

    public function isLast(): bool
    {
        return $this->records === [] || $this->pageNumber >= $this->totalPages;
    }
}
