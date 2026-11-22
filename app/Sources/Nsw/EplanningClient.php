<?php

namespace App\Sources\Nsw;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Client for the NSW Planning Portal open data API.
 *
 * The API is keyless but header-driven: PageSize, PageNumber and a JSON
 * `filters` header. Every feed answers with the same envelope:
 * {PageSize, PageNumber, TotalPages, TotalCount, Application: [...]}.
 */
class EplanningClient
{
    /**
     * Filters the spike confirmed on all four feeds. The API silently ignores
     * an unknown filter name and returns the whole feed, so anything else is
     * rejected here rather than sent.
     */
    public const FILTERS = [
        'LodgementDateFrom', 'LodgementDateTo',
        'DeterminationDateFrom', 'DeterminationDateTo',
        'CostOfDevelopmentFrom', 'CostOfDevelopmentTo',
        'CouncilName', 'ApplicationLastUpdatedFrom', 'ApplicationStatus',
        'DevelopmentCategory',
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 20,
        private readonly int $attempts = 3,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('nsw.base_url'), config('nsw.timeout'), config('nsw.attempts'));
    }

    /** @param array<string, mixed> $filters e.g. ['ApplicationLastUpdatedFrom' => '2026-11-20'] */
    public function page(Feed $feed, int $pageNumber, int $pageSize, array $filters = []): FeedPage
    {
        if ($unknown = array_diff(array_keys($filters), self::FILTERS)) {
            throw new InvalidArgumentException('Unsupported eplanning filter(s): '.implode(', ', $unknown));
        }

        $response = $this->request()
            ->withHeaders([
                'PageSize' => (string) $pageSize,
                'PageNumber' => (string) $pageNumber,
                'filters' => json_encode(['filters' => (object) $filters], JSON_THROW_ON_ERROR),
            ])
            ->get($this->baseUrl.'/'.$feed->endpoint());

        return self::parse($response->json(), $pageNumber);
    }

    /** Anything but the documented envelope is an error, never an empty last page. */
    public static function parse(mixed $body, int $requestedPage): FeedPage
    {
        if (! is_array($body) || ! isset($body['Application']) || ! is_array($body['Application'])
            || ! isset($body['TotalPages']) || ! is_numeric($body['TotalPages'])) {
            throw new UnexpectedResponse('Unexpected eplanning response: '.mb_substr((string) json_encode($body), 0, 500));
        }

        return new FeedPage(
            records: array_values($body['Application']),
            pageNumber: (int) ($body['PageNumber'] ?? $requestedPage),
            totalPages: (int) $body['TotalPages'],
            totalCount: (int) ($body['TotalCount'] ?? 0),
        );
    }

    /** Worst case: attempts × timeout + 1 s + 2 s backoff, well inside the job's 85 s. */
    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($this->timeout)
            ->retry(
                $this->attempts,
                fn (int $attempt) => $attempt * 1000,
                fn (Throwable $e) => self::isRetryable($e),
            );
    }

    public static function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }
}
