<?php

namespace App\Sources\Nsw;

use App\Models\Application;
use App\Models\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Writes mapped feed records into `applications`, keyed on (feed, pan). */
class ApplicationUpserter
{
    /** @param list<array<string, mixed>> $records */
    public function upsert(Feed $feed, array $records): int
    {
        $count = 0;

        DB::transaction(function () use ($feed, $records, &$count) {
            foreach ($records as $raw) {
                $a = ApplicationMapper::toAttributes($feed, $raw);
                if ($a['pan'] === '') {
                    continue;
                }

                $builderId = $this->builderId($a['builder_legal_name'], $a['builder_trading_name']);
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
                $count++;
            }
        });

        return $count;
    }

    private function builderId(?string $legal, ?string $trading): ?int
    {
        if ($legal === null) {
            return null;
        }

        $isCompany = BuilderNameClassifier::isCompany($legal);
        $key = BuilderNameClassifier::key($legal);

        return Builder::firstOrCreate(['name_key' => $key], [
            'legal_name' => $legal,
            'trading_name' => $trading,
            'slug' => Str::slug($legal).'-'.substr(md5($key), 0, 6),
            'is_company' => $isCompany,
            'suppressed' => ! $isCompany,
            'suppressed_reason' => $isCompany ? null : 'individual',
        ])->id;
    }

    /** @param list<string> $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn ($v) => '"'.addcslashes($v, '"\\').'"', $values)).'}';
    }
}
