<?php

namespace App\Sources\Nsw;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Maps a raw feed record onto `applications` columns.
 *
 * Field names are confirmed by the Milestone 1 spike (docs/data-spike.md).
 * Values that don't fit their column are nulled or truncated here, so one
 * odd record can't fail a page.
 */
class ApplicationMapper
{
    /**
     * The only explicit parent reference in any feed: a DA or CDC
     * modification's parent application. CCs and OCs carry none.
     */
    public const PARENT_REFERENCE_FIELDS = ['ModificationApplicationNumber'];

    /** Column limits: Postgres smallint, integer, numeric(12,2), numeric(15,2). */
    private const MAX = [
        'storeys' => 32767,
        'units' => 2147483647,
        'gross_floor_area' => 9999999999.99,
        'cost' => 9999999999999.99,
    ];

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public static function toAttributes(Feed $feed, array $r): array
    {
        $location = Arr::first(Arr::wrap($r['Location'] ?? [])) ?? [];
        $pan = (string) ($r['PlanningPortalApplicationNumber'] ?? '');

        $lotKeys = collect(Arr::wrap($r['Location'] ?? []))
            ->flatMap(fn ($loc) => Arr::wrap($loc['Lot'] ?? []))
            ->map(fn ($lot) => Normaliser::lotKey($lot['Lot'] ?? null, $lot['PlanLabel'] ?? null, $lot['Section'] ?? null))
            ->filter()->unique()->values()->all();

        $x = $location['X'] ?? null; // longitude
        $y = $location['Y'] ?? null; // latitude
        $address = self::str($location['FullAddress'] ?? null, null);

        return [
            'feed' => $feed->value,
            'pan' => mb_substr($pan, 0, 64),
            'application_type' => self::str($r['ApplicationType'] ?? null),
            'status' => self::str($r['ApplicationStatus'] ?? null),
            'council_name' => self::str(data_get($r, 'Council.CouncilName')),
            'development_types' => self::list($r['DevelopmentType'] ?? null),
            'building_classes' => self::list($r['BuildingCodeClass'] ?? null),
            'lodged_at' => Normaliser::date($r['LodgementDate'] ?? null)?->toDateString(),
            'determined_at' => Normaliser::date($r['DeterminationDate'] ?? null)?->toDateString(),
            'exhibition_starts_at' => Normaliser::date($r['AssessmentExhibitionStartDate'] ?? null)?->toDateString(),
            'exhibition_ends_at' => Normaliser::date($r['AssessmentExhibitionEndDate'] ?? null)?->toDateString(),
            // Feed times are Sydney wall-clock; the column is UTC like every other timestamp.
            'source_updated_at' => Normaliser::date($r['DateLastUpdated'] ?? null)?->utc(),
            'cost' => self::bounded('cost', self::number($r['CostOfDevelopment'] ?? null), $pan),
            'storeys' => self::bounded('storeys', self::int($r['StoreysProposed'] ?? $r['NumberOfStoreys'] ?? null), $pan),
            // CCs use UnitsProposed; DAs and CDCs use NumberOfNewDwellings.
            'units' => self::bounded('units', self::int($r['UnitsProposed'] ?? $r['NumberOfNewDwellings'] ?? null), $pan),
            'gross_floor_area' => self::bounded('gross_floor_area', self::number($r['ProposedGrossFloorArea'] ?? null), $pan),
            'parent_reference' => self::parentReference($r),
            'address' => $address,
            'address_key' => ($k = Normaliser::addressKey($address)) === null ? null : mb_substr($k, 0, 255),
            'suburb' => self::str($location['Suburb'] ?? null),
            'postcode' => isset($location['Postcode']) && preg_match('/^\d{4}$/', (string) $location['Postcode']) ? (string) $location['Postcode'] : null,
            'lot_keys' => $lotKeys,
            'lng' => is_numeric($x) && abs((float) $x) <= 180 ? (float) $x : null,
            'lat' => is_numeric($y) && abs((float) $y) <= 90 ? (float) $y : null,
            'builder_legal_name' => self::str($r['BuilderLegalName'] ?? null, null),
            'builder_trading_name' => self::str($r['BuilderTradingName'] ?? null, null),
        ];
    }

    private static function parentReference(array $r): ?string
    {
        foreach (self::PARENT_REFERENCE_FIELDS as $field) {
            if (isset($r[$field]) && is_scalar($r[$field]) && ($v = self::str($r[$field])) !== null) {
                return $v;
            }
        }

        return null;
    }

    private static function bounded(string $column, int|float|null $value, string $pan): int|float|null
    {
        if ($value === null) {
            return null;
        }

        if ($value < 0 || $value > self::MAX[$column]) {
            Log::notice('nsw: out-of-range value nulled', compact('column', 'value', 'pan'));

            return null;
        }

        return $value;
    }

    private static function list(mixed $v): ?array
    {
        if ($v === null || $v === '') {
            return null;
        }

        return collect(Arr::wrap($v))
            ->map(fn ($item) => is_array($item) ? (Arr::first($item) ?? null) : $item)
            ->filter()->values()->all();
    }

    private static function number(mixed $v): ?float
    {
        if (is_string($v)) {
            $v = str_replace([',', '$', ' '], '', $v);
        }

        return is_numeric($v) && is_finite((float) $v) ? (float) $v : null;
    }

    private static function int(mixed $v): ?int
    {
        $n = self::number($v);

        return $n === null || abs($n) > PHP_INT_MAX / 2 ? null : (int) round($n);
    }

    /** Trims, treats the feed's literal "null" as empty, and truncates to $max characters. */
    private static function str(mixed $v, ?int $max = 255): ?string
    {
        if (! is_scalar($v)) {
            return null;
        }

        $v = trim((string) $v);
        if ($v === '' || strcasecmp($v, 'null') === 0) {
            return null;
        }

        return $max === null ? $v : mb_substr($v, 0, $max);
    }
}
