<?php

namespace App\Sources\Nsw;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * Client for the NSW Planning Portal open data API.
 *
 * The API is keyless but header-driven: PageSize, PageNumber and a JSON
 * `filters` header. Without them it answers "Required parameters not met".
 */
class EplanningClient
{
    /** Attempts per page. With the default 20 s timeout: 3 × 20 s + 1 s + 2 s = 63 s, inside the job's 85 s. */
    public const ATTEMPTS = 3;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 20,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('nsw.base_url'), config('nsw.timeout'));
    }

    /** @param  array<string, mixed>  $filters  e.g. ['ApplicationLastUpdatedFrom' => '2026-11-20'] */
    public function page(Feed $feed, int $pageNumber, int $pageSize, array $filters = []): FeedPage
    {
        $response = $this->request()
            ->withHeaders([
                'PageSize' => (string) $pageSize,
                'PageNumber' => (string) $pageNumber,
                'filters' => json_encode(['filters' => (object) $filters], JSON_THROW_ON_ERROR),
            ])
            ->get($this->baseUrl.'/'.$feed->endpoint());

        return self::parse($response->json(), $pageNumber);
    }

    /**
     * The envelope is `{PageSize, PageNumber, TotalPages, TotalCount, Application: [...]}`
     * (docs/data-spike.md). Anything else is an error, never an empty page: an
     * empty page would mark the run finished and move the watermark.
     */
    public static function parse(mixed $body, int $pageNumber): FeedPage
    {
        if (! is_array($body) || ! isset($body['Application']) || ! is_array($body['Application']) || ! is_numeric($body['TotalPages'] ?? null)) {
            throw new UnexpectedValueException('Unexpected eplanning response: '.substr((string) json_encode($body), 0, 300));
        }

        return new FeedPage(array_values($body['Application']), $pageNumber, (int) $body['TotalPages']);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($this->timeout)
            ->retry(self::ATTEMPTS, fn (int $attempt) => 2 ** ($attempt - 1) * 1000, self::retryable(...));
    }

    /** Retry connection errors, 429 and 5xx. A 4xx will not get better by asking again. */
    private static function retryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError());
    }
}
