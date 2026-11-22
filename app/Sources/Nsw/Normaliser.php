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
     * "Lot 12" + "DP123456" -> "12/DP123456". With a section (the feed's
     * `Section` field, or "3 Sec 2" written into the lot) -> "3/2/DP758000",
     * so Lot 44 Sec 4 and Lot 44 Sec 7 of the same DP stay distinct.
     */
    public static function lotKey(?string $lot, ?string $plan, ?string $section = null): ?string
    {
        $lot = strtoupper(trim((string) $lot));
        $plan = strtoupper(preg_replace('/\s+/', '', (string) $plan));
        $section = strtoupper(preg_replace('/\s+/', '', (string) $section));
        if ($section === 'NULL') {
            $section = '';
        }

        $lot = preg_replace('/^LOT\s*/', '', $lot);
        if (preg_match('/^(.+?)\s*SEC(?:TION)?\s*(\S+)$/', $lot, $m)) {
            [$lot, $section] = [$m[1], $section !== '' ? $section : $m[2]];
        }
        $lot = preg_replace('/\s+/', '', $lot);

        if ($lot === '' || $lot === 'NULL' || ! preg_match('/^(DP|SP|CP|PP)\d+$/', $plan)) {
            return null;
        }

        return $section === '' ? "{$lot}/{$plan}" : "{$lot}/{$section}/{$plan}";
    }

    /**
     * Same rule as `addr_key` in spike/q4_linkrate.py, so the spike's link
     * rates describe the linker: upper-case, anything but A-Z/0-9 becomes a
     * space, and street-type abbreviations are expanded. State and postcode
     * are kept. Change both together.
     */
    public const STREET_TYPES = [
        'ST' => 'STREET', 'RD' => 'ROAD', 'AVE' => 'AVENUE', 'AV' => 'AVENUE', 'DR' => 'DRIVE',
        'PL' => 'PLACE', 'CRES' => 'CRESCENT', 'CR' => 'CRESCENT', 'PDE' => 'PARADE',
        'HWY' => 'HIGHWAY', 'LN' => 'LANE', 'CT' => 'COURT', 'TCE' => 'TERRACE', 'CL' => 'CLOSE',
    ];

    public static function addressKey(?string $address): ?string
    {
        $a = strtoupper(trim((string) $address));
        if ($a === '' || $a === 'NULL') {
            return null;
        }

        $words = preg_split('/\s+/', trim(preg_replace('/[^A-Z0-9 ]/', ' ', $a)), -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_map(fn ($w) => self::STREET_TYPES[$w] ?? $w, $words)) ?: null;
    }
}
