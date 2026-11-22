<?php

namespace App\Sources\Nsw;

/**
 * Decides whether a builder name is a company we may publish. Anything that
 * isn't clearly a company is treated as an individual and suppressed, free
 * and Pro alike: a sole trader's name is personal information.
 */
class BuilderNameClassifier
{
    private const COMPANY_PATTERN = '/\b(PTY\.?\s*LTD|PTY\.?\s*LIMITED|PROPRIETARY|LTD|LIMITED|INC|INCORPORATED|CORPORATION|CORP|CO-OP|COOPERATIVE|TRUST|TRUSTEE|HOLDINGS|GROUP)\b\.?/i';

    private const OWNER_BUILDER_PATTERN = '/OWNER[\s\-]*BUILDER/i';

    public static function isCompany(?string $name): bool
    {
        $name = trim((string) $name);
        if ($name === '' || preg_match(self::OWNER_BUILDER_PATTERN, $name)) {
            return false;
        }

        return (bool) preg_match(self::COMPANY_PATTERN, $name);
    }

    public static function key(string $name): string
    {
        $k = strtoupper($name);
        $k = preg_replace('/\bPTY\.?\s*(LTD|LIMITED)\b|\bPROPRIETARY\s+LIMITED\b/', 'PTY LTD', $k);
        $k = preg_replace('/[^A-Z0-9 ]/', ' ', $k);

        return trim(preg_replace('/\s+/', ' ', $k));
    }
}
