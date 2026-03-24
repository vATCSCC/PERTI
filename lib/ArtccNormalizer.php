<?php
namespace PERTI\Lib;

/**
 * Canonical ARTCC code normalization.
 *
 * Handles three categories:
 * 1. K-prefix stripping: KZNY → ZNY (US ARTCCs in ICAO format)
 * 2. Canadian 3→4 letter: CZE → CZEG (abbreviated FIR codes)
 * 3. ICAO→FAA mappings: PAZA → ZAN, KZAK → ZAK, etc.
 */
class ArtccNormalizer
{
    private const ALIASES = [
        'CZE'  => 'CZEG', 'CZU'  => 'CZUL', 'CZV'  => 'CZVR',
        'CZW'  => 'CZWG', 'CZY'  => 'CZYZ', 'CZM'  => 'CZQM',
        'CZQ'  => 'CZQX', 'CZO'  => 'CZQO', 'CZX'  => 'CZQX',
        'PAZA' => 'ZAN',  'KZAK' => 'ZAK',  'KZWY' => 'ZWY',
        'PGZU' => 'ZUA',  'PAZN' => 'ZAP',  'PHZH' => 'ZHN',
    ];

    private const PSEUDO_FIXES = ['UNKN', 'VARIOUS'];

    /**
     * Normalize a single ARTCC code.
     * Idempotent: normalize(normalize(x)) === normalize(x).
     */
    public static function normalize(string $code): string
    {
        $upper = strtoupper(trim($code));
        if ($upper === '') return $upper;
        if (isset(self::ALIASES[$upper])) {
            return self::ALIASES[$upper];
        }
        if (preg_match('/^KZ[A-Z]{2}$/', $upper)) {
            return substr($upper, 1);
        }
        return $upper;
    }

    /**
     * Normalize a comma-separated list of ARTCC codes.
     * Filters out empty strings and pseudo-fixes (UNKN, VARIOUS).
     * Returns deduplicated, comma-separated string.
     */
    public static function normalizeCsv(string $csv): string
    {
        if (trim($csv) === '') return '';
        $codes = array_map('trim', explode(',', $csv));
        $codes = array_filter($codes, function ($c) {
            $u = strtoupper(trim($c));
            return $u !== '' && !in_array($u, self::PSEUDO_FIXES, true);
        });
        $codes = array_map([self::class, 'normalize'], $codes);
        return implode(',', array_unique($codes));
    }

    /**
     * Strip sub-sector suffixes to produce L1-only ARTCC codes.
     * Sub-sectors always have a hyphen (e.g. BIRD-E, CZQO-GOTA, SBBS-SE).
     * Splits on $sep, strips everything after the first '-' in each token,
     * normalizes, deduplicates, and rejoins.
     */
    public static function toL1Csv(string $csv, string $sep = ','): string
    {
        if (trim($csv) === '') return '';
        $codes = array_map('trim', explode($sep, $csv));
        $result = [];
        foreach ($codes as $code) {
            if ($code === '') continue;
            $hyphen = strpos($code, '-');
            if ($hyphen !== false) {
                $code = substr($code, 0, $hyphen);
            }
            $normalized = self::normalize($code);
            if ($normalized !== '' && !in_array(strtoupper($normalized), self::PSEUDO_FIXES, true)) {
                $result[] = $normalized;
            }
        }
        return implode($sep, array_unique($result));
    }

    /**
     * Normalize an array of ARTCC codes.
     * Filters pseudo-fixes and deduplicates.
     */
    public static function normalizeArray(array $codes): array
    {
        $result = [];
        foreach ($codes as $code) {
            $normalized = self::normalize($code);
            if ($normalized !== '' && !in_array(strtoupper($normalized), self::PSEUDO_FIXES, true)) {
                $result[] = $normalized;
            }
        }
        return array_values(array_unique($result));
    }
}
