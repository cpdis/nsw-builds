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
     * "Lot 12" + "DP123456" -> "12/DP123456". Section numbers ("Lot 3 Sec 2
     * DP758000") are kept as "3/2/DP758000".
     */
    public static function lotKey(?string $lot, ?string $plan): ?string
    {
        $lot = strtoupper(trim((string) $lot));
        $plan = strtoupper(preg_replace('/\s+/', '', (string) $plan));

        $lot = preg_replace('/^LOT\s*/', '', $lot);
        $lot = preg_replace('/\s*SEC(TION)?\s*/', '/', $lot);
        $lot = preg_replace('/\s+/', '', $lot);

        if ($lot === '' || $plan === '' || ! preg_match('/^(DP|SP|CP|PP)\d+$/', $plan)) {
            return null;
        }

        return $lot.'/'.$plan;
    }

    private const STREET_TYPES = [
        'STREET' => 'ST', 'ROAD' => 'RD', 'AVENUE' => 'AVE', 'PARADE' => 'PDE',
        'DRIVE' => 'DR', 'PLACE' => 'PL', 'HIGHWAY' => 'HWY', 'LANE' => 'LN',
        'CRESCENT' => 'CRES', 'COURT' => 'CT', 'CLOSE' => 'CL', 'TERRACE' => 'TCE',
        'BOULEVARD' => 'BVD', 'CIRCUIT' => 'CCT', 'ESPLANADE' => 'ESP', 'WAY' => 'WAY',
    ];

    /** Upper-case, abbreviated street type, punctuation removed: linker rule 3. */
    public static function addressKey(?string $address): ?string
    {
        $a = strtoupper(trim((string) $address));
        if ($a === '') {
            return null;
        }

        $a = preg_replace('/[^A-Z0-9\/\- ]/', ' ', $a);
        $a = preg_replace('/\bNSW\b|\b\d{4}\s*$/', ' ', $a);
        foreach (self::STREET_TYPES as $long => $short) {
            $a = preg_replace('/\b'.$long.'\b/', $short, $a);
        }

        return trim(preg_replace('/\s+/', ' ', $a)) ?: null;
    }
}
