<?php

namespace App\Sources\Nsw;

/**
 * Decides whether a builder name is a company we may publish. Anything that
 * isn't clearly a company is treated as an individual and suppressed, free
 * and Pro alike: a sole trader's name is personal information.
 *
 * Only incorporation suffixes count. TRUST, TRUSTEE, GROUP and HOLDINGS do
 * not: "John Smith ATF Smith Family Trust" and "Sam Grosser T/A Grosser
 * Building Group" are people. A name with a trustee or trading-as clause is
 * judged on the party before the clause, so "Acme Pty Ltd ATF Acme Trust"
 * is a company and "J Smith T/A Acme Pty Ltd" is not.
 */
class BuilderNameClassifier
{
    private const COMPANY_PATTERN = '/\b(PTY\.?\s*LTD|PTY\.?\s*LIMITED|PROPRIETARY\s+LIMITED|LTD|LIMITED|INC|INCORPORATED|CORPORATION|CO-?OPERATIVE(\s+LTD)?)\b\.?/i';

    private const OWNER_BUILDER_PATTERN = '/OWNER[\s\-]*BUILDER/i';

    /** "ATF", "AS TRUSTEE FOR", "T/A", "T/AS", "TRADING AS", "TRUSTEE FOR". */
    private const CLAUSE_PATTERN = '#\s(?:A\.?T\.?F\.?|AS\s+TRUSTEE\s+FOR|TRUSTEE\s+FOR|AS\s+TRUSTEE|T\s*/\s*AS?|TRADING\s+AS)\b#i';

    public static function isCompany(?string $name): bool
    {
        $name = trim((string) $name);
        if ($name === '' || preg_match(self::OWNER_BUILDER_PATTERN, $name)) {
            return false;
        }

        $party = preg_split(self::CLAUSE_PATTERN, ' '.$name, 2)[0];

        return (bool) preg_match(self::COMPANY_PATTERN, $party);
    }

    public static function key(string $name): string
    {
        $k = strtoupper($name);
        $k = preg_replace('/\bPTY\.?\s*(LTD|LIMITED)\b|\bPROPRIETARY\s+LIMITED\b/', 'PTY LTD', $k);
        $k = preg_replace('/[^A-Z0-9 ]/', ' ', $k);

        return trim(preg_replace('/\s+/', ' ', $k));
    }
}
