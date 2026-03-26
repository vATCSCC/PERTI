<?php
/**
 * Callsign normalization for flight number grouping.
 *
 * Strips trailing alphabetic suffixes from callsigns to produce a
 * base flight number for grouping. VATSIM callsigns commonly append
 * suffix letters (e.g., UAE5NM, DAL45E) which represent the same
 * scheduled flight.
 *
 * Usage:
 *   require_once __DIR__ . '/normalize_callsign.php';
 *   normalize_callsign('UAE5NM');   // => 'UAE5'
 *   normalize_callsign('AAL1234');  // => 'AAL1234'
 *   normalize_callsign('DAL45E');   // => 'DAL45'
 *   normalize_callsign('N172SP');   // => 'N172SP' (GA, kept as-is)
 */

/**
 * Normalize a callsign to its base flight number.
 *
 * Rules:
 * 1. Airline callsigns (2-3 letter prefix + digits + optional alpha suffix):
 *    Strip trailing alpha characters after the last digit.
 *    e.g., UAE5NM → UAE5, BAW256G → BAW256
 *
 * 2. GA/military N-numbers and other formats: return as-is.
 *    e.g., N172SP → N172SP, BLOCKED → BLOCKED
 *
 * @param string $callsign Raw callsign
 * @return string Normalized base flight number
 */
function normalize_callsign(string $callsign): string
{
    $cs = strtoupper(trim($callsign));
    if ($cs === '') return '';

    // Match airline-style: 2-3 alpha prefix + at least 1 digit + optional alpha suffix
    // e.g., UAE5NM, AAL1234, JBU623, BA256G
    if (preg_match('/^([A-Z]{2,3}\d+)[A-Z]+$/', $cs, $m)) {
        return $m[1];
    }

    // Already a base number (no trailing alpha) or non-airline format
    return $cs;
}
