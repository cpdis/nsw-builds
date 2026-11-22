<?php

namespace App\Sources\Nsw;

use Illuminate\Support\Arr;

/**
 * Maps a raw feed record onto `applications` columns.
 *
 * Field names come from working third-party code, not from our own calls yet.
 * Revisit after the data spike (docs/data-spike.md) and fill in
 * PARENT_REFERENCE_FIELDS if CCs or OCs turn out to name their parent.
 */
class ApplicationMapper
{
    /** Candidate fields that might hold a parent DA, consent or CC number. */
    public const PARENT_REFERENCE_FIELDS = [
        'DevelopmentApplicationNumber',
        'ConsentNumber',
        'DANumber',
        'LinkedApplicationNumber',
        'ConstructionCertificateNumber',
    ];

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public static function toAttributes(Feed $feed, array $r): array
    {
        $location = Arr::first(Arr::wrap($r['Location'] ?? [])) ?? [];

        $lotKeys = collect(Arr::wrap($r['Location'] ?? []))
            ->flatMap(fn ($loc) => Arr::wrap($loc['Lot'] ?? []))
            ->map(fn ($lot) => Normaliser::lotKey($lot['Lot'] ?? null, $lot['PlanLabel'] ?? null))
            ->filter()->unique()->values()->all();

        $x = $location['X'] ?? null; // longitude
        $y = $location['Y'] ?? null; // latitude

        return [
            'feed' => $feed->value,
            'pan' => (string) ($r['PlanningPortalApplicationNumber'] ?? $r['PAN'] ?? ''),
            'application_type' => $r['ApplicationType'] ?? null,
            'status' => $r['ApplicationStatus'] ?? null,
            'council_name' => data_get($r, 'Council.CouncilName'),
            'development_types' => self::list($r['DevelopmentType'] ?? null),
            'building_classes' => self::list($r['BuildingCodeClass'] ?? null),
            'lodged_at' => Normaliser::date($r['LodgementDate'] ?? null)?->toDateString(),
            'determined_at' => Normaliser::date($r['DeterminationDate'] ?? null)?->toDateString(),
            'exhibition_starts_at' => Normaliser::date($r['AssessmentExhibitionStartDate'] ?? null)?->toDateString(),
            'exhibition_ends_at' => Normaliser::date($r['AssessmentExhibitionEndDate'] ?? null)?->toDateString(),
            'source_updated_at' => Normaliser::date($r['DateLastUpdated'] ?? null),
            'cost' => self::number($r['CostOfDevelopment'] ?? null),
            'storeys' => self::int($r['StoreysProposed'] ?? $r['NumberOfStoreys'] ?? null),
            'units' => self::int($r['UnitsProposed'] ?? null),
            'gross_floor_area' => self::number($r['ProposedGrossFloorArea'] ?? null),
            'parent_reference' => self::parentReference($r),
            'address' => $location['FullAddress'] ?? null,
            'address_key' => Normaliser::addressKey($location['FullAddress'] ?? null),
            'suburb' => $location['Suburb'] ?? null,
            'postcode' => isset($location['Postcode']) ? substr((string) $location['Postcode'], 0, 4) : null,
            'lot_keys' => $lotKeys,
            'lng' => is_numeric($x) ? (float) $x : null,
            'lat' => is_numeric($y) ? (float) $y : null,
            'builder_legal_name' => self::str($r['BuilderLegalName'] ?? null),
            'builder_trading_name' => self::str($r['BuilderTradingName'] ?? null),
        ];
    }

    private static function parentReference(array $r): ?string
    {
        foreach (self::PARENT_REFERENCE_FIELDS as $field) {
            if (! empty($r[$field]) && is_scalar($r[$field])) {
                return trim((string) $r[$field]);
            }
        }

        return null;
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

        return is_numeric($v) ? (float) $v : null;
    }

    private static function int(mixed $v): ?int
    {
        $n = self::number($v);

        return $n === null ? null : (int) round($n);
    }

    private static function str(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
