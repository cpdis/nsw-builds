<?php

namespace App\Sources\Nsw;

/**
 * Decides whether a builder name is a company we may publish. Anything that
 * isn't clearly a company is treated as an individual and suppressed, free
 * and Pro alike: a sole trader's name is personal information.
 */
class BuilderNameClassifier
{
    /**
     * Only legal-entity suffixes. TRUST, TRUSTEE, GROUP and HOLDINGS are not on
     * the list: "J SMITH TRUSTEE" and "J SMITH T/A SMITH BUILDING GROUP" are people.
     */
    private const COMPANY_PATTERN = '/\b(PTY\.?\s*LTD|PTY\.?\s*LIMITED|PROPRIETARY|LTD|LIMITED|INC|INCORPORATED|CORPORATION|CORP|CO-OP|COOPERATIVE)\b\.?/i';

    /**
     * "X ATF Y", "X as trustee for Y", "X T/A Y", "X trading as Y": X is the
     * legal entity, so only X decides. "The Trustee for Y" names no entity at all.
     */
    private const ENTITY_SEPARATOR = '/\s(?:ATF|A\/T\/F|AS\s+TRUSTEES?\s+FOR|T\/AS?|TRADING\s+AS)\s/i';

    private const UNNAMED_TRUSTEE = '/^\s*THE\s+TRUSTEES?\s+FOR\b/i';

    private const OWNER_BUILDER_PATTERN = '/OWNER[\s\-]*BUILDER/i';

    public static function isCompany(?string $name): bool
    {
        $name = trim((string) $name);
        if ($name === '' || preg_match(self::OWNER_BUILDER_PATTERN, $name) || preg_match(self::UNNAMED_TRUSTEE, $name)) {
            return false;
        }

        $entity = preg_split(self::ENTITY_SEPARATOR, " {$name} ", 2)[0];

        return (bool) preg_match(self::COMPANY_PATTERN, $entity);
    }

    public static function key(string $name): string
    {
        $k = strtoupper($name);
        $k = preg_replace('/\bPTY\.?\s*(LTD|LIMITED)\b|\bPROPRIETARY\s+LIMITED\b/', 'PTY LTD', $k);
        $k = preg_replace('/[^A-Z0-9 ]/', ' ', $k);

        return trim(preg_replace('/\s+/', ' ', $k));
    }
}
