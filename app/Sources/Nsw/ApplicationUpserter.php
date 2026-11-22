<?php

namespace App\Sources\Nsw;

use App\Models\Application;
use App\Models\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Writes mapped feed records into `applications`, keyed on (feed, pan). */
class ApplicationUpserter
{
    /** @param list<array<string, mixed>> $records */
    public function upsert(Feed $feed, array $records): int
    {
        $mapped = array_values(array_filter(
            array_map(fn (array $raw) => [ApplicationMapper::toAttributes($feed, $raw), $raw], $records),
            fn (array $pair) => $pair[0]['pan'] !== '',
        ));

        $builderIds = $this->builderIds(array_column($mapped, 0));
        $count = 0;

        DB::transaction(function () use ($mapped, $builderIds, &$count) {
            foreach ($mapped as [$a, $raw]) {
                try {
                    // Savepoint per row: one row the database rejects is logged and skipped, not the page.
                    DB::transaction(fn () => $this->upsertRow($a, $raw, $builderIds));
                    $count++;
                } catch (QueryException $e) {
                    Log::warning('nsw: skipped record', ['feed' => $a['feed'], 'pan' => $a['pan'], 'error' => $e->getMessage()]);
                }
            }
        });

        return $count;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $raw
     * @param  array<string, int>  $builderIds
     */
    private function upsertRow(array $a, array $raw, array $builderIds): void
    {
        $builderId = $a['builder_legal_name'] === null ? null : $builderIds[BuilderNameClassifier::key($a['builder_legal_name'])];
        [$lng, $lat, $lotKeys] = [$a['lng'], $a['lat'], $a['lot_keys']];
        unset($a['lng'], $a['lat'], $a['lot_keys'], $a['builder_legal_name'], $a['builder_trading_name']);

        $app = Application::updateOrCreate(
            ['feed' => $a['feed'], 'pan' => $a['pan']],
            $a + ['builder_id' => $builderId, 'raw' => $raw, 'fetched_at' => now()],
        );

        DB::update(
            'UPDATE applications SET lot_keys = ?::text[], geom = CASE WHEN ?::float8 IS NULL THEN NULL ELSE ST_SetSRID(ST_MakePoint(?::float8, ?::float8), 4326) END WHERE id = ?',
            [self::pgArray($lotKeys), $lng, $lng, $lat, $app->id],
        );
    }

    /**
     * Builders are shared across feeds, and feeds sync in parallel. Creating
     * them before the page transaction, one short statement each and in key
     * order, means two workers never hold builder rows while waiting on each other.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int> name_key => id
     */
    private function builderIds(array $rows): array
    {
        $names = [];
        foreach ($rows as $a) {
            if ($a['builder_legal_name'] !== null) {
                $names[BuilderNameClassifier::key($a['builder_legal_name'])] ??= [$a['builder_legal_name'], $a['builder_trading_name']];
            }
        }
        ksort($names, SORT_STRING);

        $ids = [];
        foreach ($names as $key => [$legal, $trading]) {
            $isCompany = BuilderNameClassifier::isCompany($legal);
            $ids[$key] = Builder::firstOrCreate(['name_key' => $key], [
                'legal_name' => $legal,
                'trading_name' => $trading,
                'slug' => Str::slug($legal).'-'.substr(md5($key), 0, 6),
                'is_company' => $isCompany,
                'suppressed' => ! $isCompany,
                'suppressed_reason' => $isCompany ? null : 'individual',
            ])->id;
        }

        return $ids;
    }

    /** @param list<string> $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn ($v) => '"'.addcslashes($v, '"\\').'"', $values)).'}';
    }
}
