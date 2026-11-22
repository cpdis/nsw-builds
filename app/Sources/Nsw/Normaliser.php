<?php

namespace App\Sources\Nsw;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Small pure helpers for the quirks of the feed: mixed date formats,
 * lot/plan labels and addresses.
 */
class Normaliser
{
    /** Dates arrive as ISO 8601 or DD/MM/YYYY. */
    public static function date(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $value, $m)) {
                return CarbonImmutable::createSafe((int) $m[3], (int) $m[2], (int) $m[1], 0, 0, 0, config('nsw.timezone')) ?: null;
            }

            return CarbonImmutable::parse($value, config('nsw.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * "Lot 12" + "DP123456" -> "12/DP123456". A section, whether it arrives in
     * the feed's own Section field or inside the lot ("Lot 3 Sec 2"), is kept:
     * "3/2/DP758000". Same shape as spike/q4_linkrate.py lot_keys().
     */
    public static function lotKey(?string $lot, ?string $plan, ?string $section = null): ?string
    {
        $lot = strtoupper(trim((string) $lot));
        $plan = strtoupper(preg_replace('/\s+/', '', (string) $plan));
        $section = strtoupper(preg_replace('/\s+/', '', (string) $section));

        $lot = preg_replace('/^LOT\s*/', '', $lot);
        $lot = preg_replace('/\s*SEC(TION)?\s*/', '/', $lot);
        $lot = preg_replace('/\s+/', '', $lot);

        if ($lot === '' || $plan === '' || ! preg_match('/^(DP|SP|CP|PP)\d+$/', $plan)) {
            return null;
        }

        if ($section !== '' && ! str_contains($lot, '/')) {
            $lot .= '/'.$section;
        }

        return $lot.'/'.$plan;
    }

    /** Abbreviation -> long form. Must stay identical to STREET_TYPES in spike/q4_linkrate.py. */
    private const STREET_TYPES = [
        'ST' => 'STREET', 'RD' => 'ROAD', 'AVE' => 'AVENUE', 'AV' => 'AVENUE', 'DR' => 'DRIVE',
        'PL' => 'PLACE', 'CRES' => 'CRESCENT', 'CR' => 'CRESCENT', 'PDE' => 'PARADE',
        'HWY' => 'HIGHWAY', 'LN' => 'LANE', 'CT' => 'COURT', 'TCE' => 'TERRACE', 'CL' => 'CLOSE',
    ];

    /**
     * Linker rule 3. Mirrors addr_key() in spike/q4_linkrate.py exactly, so the
     * rule-3 link rates in docs/data-spike.md describe this code: upper-case,
     * every non-alphanumeric becomes a space, abbreviated street types expand.
     */
    public static function addressKey(?string $address): ?string
    {
        $words = preg_split('/\s+/', trim(preg_replace('/[^A-Z0-9 ]/', ' ', strtoupper((string) $address))), -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_map(fn (string $w) => self::STREET_TYPES[$w] ?? $w, $words)) ?: null;
    }
}
