<?php

namespace App\Sources\Nsw;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the NSW Planning Portal open data API.
 *
 * The API is keyless but header-driven: PageSize, PageNumber and a JSON
 * `filters` header. Without them it answers "Required parameters not met".
 */
class EplanningClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('nsw.base_url'), config('nsw.timeout'));
    }

    /**
     * Fetch one page. Returns the list of application records on that page.
     *
     * @param  array<string, mixed>  $filters  e.g. ['ApplicationLastUpdatedFrom' => '2026-11-20']
     * @return list<array<string, mixed>>
     */
    public function page(Feed $feed, int $pageNumber, int $pageSize, array $filters = []): array
    {
        $response = $this->request()
            ->withHeaders([
                'PageSize' => (string) $pageSize,
                'PageNumber' => (string) $pageNumber,
                'filters' => json_encode(['filters' => (object) $filters], JSON_THROW_ON_ERROR),
            ])
            ->get($this->baseUrl.'/'.$feed->endpoint());

        $response->throw();

        return self::extractRecords($response->json());
    }

    /**
     * The envelope shape is unconfirmed until the data spike runs, so accept a
     * bare list or the first list-valued key of an object.
     *
     * @return list<array<string, mixed>>
     */
    public static function extractRecords(mixed $body): array
    {
        if (! is_array($body)) {
            throw new RuntimeException('Unexpected eplanning response: '.json_encode($body));
        }

        if (array_is_list($body)) {
            return $body;
        }

        foreach (['Application', 'Applications', 'data', 'Data', 'records'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                return array_values($body[$key]);
            }
        }

        foreach ($body as $value) {
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        return [];
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($this->timeout)
            // Exponential backoff on 429/5xx and connection errors.
            ->retry(5, fn (int $attempt) => 2 ** $attempt * 1000, throw: true);
    }
}
