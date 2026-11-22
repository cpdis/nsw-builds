<?php

namespace App\Sources\Nsw;

use App\Models\Application;
use App\Models\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Writes mapped feed records into `applications`, keyed on (feed, pan). */
class ApplicationUpserter
{
    /**
     * @param  list<array<string, mixed>>  $records
     * @return array{stored: int, skipped: int}
     */
    public function upsert(Feed $feed, array $records): array
    {
        $mapped = array_map(fn (array $raw) => [$raw, ApplicationMapper::toAttributes($feed, $raw)], $records);

        // Builders first, outside the page transaction and in name_key order,
        // so two feeds' pages creating the same builders can't deadlock.
        $builderIds = $this->builderIds(array_column($mapped, 1));

        $stored = $skipped = 0;

        DB::transaction(function () use ($mapped, $builderIds, &$stored, &$skipped) {
            foreach ($mapped as [$raw, $a]) {
                if ($a['pan'] === '') {
                    $skipped++;

                    continue;
                }

                try {
                    // A savepoint per row: one bad record is logged and skipped
                    // instead of rolling back the page and halting the feed.
                    DB::transaction(fn () => $this->store($raw, $a, $builderIds));
                    $stored++;
                } catch (Throwable $e) {
                    $skipped++;
                    Log::warning('nsw: skipped record', ['feed' => $a['feed'], 'pan' => $a['pan'], 'error' => $e->getMessage()]);
                }
            }
        });

        return ['stored' => $stored, 'skipped' => $skipped];
    }

    /** @param array<string, int> $builderIds */
    private function store(array $raw, array $a, array $builderIds): void
    {
        $builderId = $a['builder_legal_name'] ? $builderIds[BuilderNameClassifier::key($a['builder_legal_name'])] ?? null : null;
        [$lng, $lat, $lotKeys] = [$a['lng'], $a['lat'], $a['lot_keys']];
        unset($a['lng'], $a['lat'], $a['lot_keys'], $a['builder_legal_name'], $a['builder_trading_name']);

        $app = Application::updateOrCreate(
            ['feed' => $a['feed'], 'pan' => $a['pan']],
            $a + ['builder_id' => $builderId, 'raw' => $raw, 'fetched_at' => now()],
        );

        DB::update(
            'UPDATE applications SET lot_keys = ?::text[], geom = CASE WHEN ?::float8 IS NULL OR ?::float8 IS NULL THEN NULL ELSE ST_SetSRID(ST_MakePoint(?::float8, ?::float8), 4326) END WHERE id = ?',
            [self::pgArray($lotKeys), $lng, $lat, $lng, $lat, $app->id],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $mapped
     * @return array<string, int> name_key => builder id
     */
    private function builderIds(array $mapped): array
    {
        $rows = [];
        foreach ($mapped as $a) {
            $legal = $a['builder_legal_name'];
            if ($legal === null || ($key = BuilderNameClassifier::key($legal)) === '') {
                continue;
            }

            $isCompany = BuilderNameClassifier::isCompany($legal);
            $rows[$key] ??= [
                'name_key' => $key,
                'legal_name' => mb_substr($legal, 0, 255),
                'trading_name' => $a['builder_trading_name'] ? mb_substr($a['builder_trading_name'], 0, 255) : null,
                'slug' => Str::limit(Str::slug($legal), 200, '').'-'.substr(md5($key), 0, 6),
                'is_company' => $isCompany,
                'suppressed' => ! $isCompany,
                'suppressed_reason' => $isCompany ? null : 'individual',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows === []) {
            return [];
        }

        ksort($rows);
        Builder::insertOrIgnore(array_values($rows));

        return Builder::whereIn('name_key', array_keys($rows))->pluck('id', 'name_key')->all();
    }

    /** @param list<string> $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn ($v) => '"'.addcslashes($v, '"\\').'"', $values)).'}';
    }
}
