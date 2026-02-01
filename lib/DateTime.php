<?php
/**
 * PERTI DateTime Utilities
 *
 * Centralized date/time handling - ALL times in UTC.
 * Prevents timezone bugs by enforcing UTC throughout.
 *
 * @package PERTI\Lib
 * @version 1.0.0
 */

namespace PERTI\Lib;

class DateTime {

    /**
     * Get current UTC timestamp in standard format
     * Use instead of: date('Y-m-d H:i:s')
     *
     * @param string $format PHP date format (default: SQL datetime)
     * @return string Formatted UTC timestamp
     */
    public static function nowUtc(string $format = 'Y-m-d H:i:s'): string {
        return gmdate($format);
    }

    /**
     * Get current UTC date only
     * Use instead of: date('Y-m-d') or date('Ymd')
     */
    public static function todayUtc(string $format = 'Y-m-d'): string {
        return gmdate($format);
    }

    /**
     * Format a timestamp/DateTime as UTC
     * Use instead of: date('format', $timestamp)
     *
     * @param int|string|\DateTime $time Unix timestamp, datetime string, or DateTime object
     * @param string $format PHP date format
     * @return string|null Formatted UTC string or null if invalid
     */
    public static function formatUtc($time, string $format = 'Y-m-d H:i:s'): ?string {
        if ($time instanceof \DateTime) {
            $time = $time->getTimestamp();
        } elseif (is_string($time)) {
            $time = strtotime($time);
            if ($time === false) {
                return null;
            }
        }

        if (!is_int($time)) {
            return null;
        }

        return gmdate($format, $time);
    }

    /**
     * Parse a datetime string or timestamp and return UTC timestamp
     * Handles timezone conversion if TZ specified
     *
     * @param string|int $datetime Datetime string or Unix timestamp
     * @param string|null $timezone Source timezone (null = assume UTC)
     * @return int|null Unix timestamp or null if parse failed
     */
    public static function parseToTimestamp($datetime, ?string $timezone = null): ?int {
        // If already an integer timestamp, return as-is
        if (is_int($datetime)) {
            return $datetime;
        }

        // If numeric string, treat as timestamp
        if (is_numeric($datetime)) {
            return (int)$datetime;
        }

        try {
            $tz = $timezone ? new \DateTimeZone($timezone) : new \DateTimeZone('UTC');
            $dt = new \DateTime($datetime, $tz);
            return $dt->getTimestamp();
        } catch (\Exception $e) {
            error_log("[PERTI DateTime] Parse failed: {$datetime} - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get relative time offset from now (UTC)
     * Use instead of: date('format', strtotime('+30 minutes'))
     *
     * @param string $offset Relative offset (e.g., '+30 minutes', '-1 hour', '+2 days')
     * @param string $format Output format
     * @return string Formatted UTC datetime
     */
    public static function offsetUtc(string $offset, string $format = 'Y-m-d H:i:s'): string {
        return gmdate($format, strtotime($offset));
    }

    /**
     * Format for NTML/FAA advisory signatures: YY/MM/DD HH:MM
     *
     * @param string|int|null $time Datetime string, timestamp, or null for now
     * @return string Formatted string
     */
    public static function formatSignature($time = null): string {
        $ts = $time !== null ? self::parseToTimestamp($time) : time();
        if ($ts === null) {
            $ts = time(); // Fallback to now if parse failed
        }
        return gmdate('y/m/d H:i', $ts);
    }

    /**
     * Format for program times: DD/HHMMZ
     *
     * @param string|int|null $time Datetime string, timestamp, or null for now
     * @return string Formatted string
     */
    public static function formatProgramTime($time = null): string {
        $ts = $time !== null ? self::parseToTimestamp($time) : time();
        if ($ts === null) {
            $ts = time();
        }
        return gmdate('d/Hi', $ts) . 'Z';
    }

    /**
     * Format for ADL times: HHMMZ
     *
     * @param string|int|null $time Datetime string, timestamp, or null for now
     * @return string Formatted string
     */
    public static function formatAdlTime($time = null): string {
        $ts = $time !== null ? self::parseToTimestamp($time) : time();
        if ($ts === null) {
            $ts = time();
        }
        return gmdate('Hi', $ts) . 'Z';
    }

    /**
     * Format for SQL Server DATETIME2 columns
     * Note: SQL Server accepts 'YYYY-MM-DD HH:MM:SS.mmm' format
     */
    public static function formatSqlServer($time = null): string {
        $ts = $time ? self::parseToTimestamp($time) : time();
        // gmdate doesn't support .v (milliseconds), so we append .000
        // For true milliseconds, use DateTime object instead
        return gmdate('Y-m-d H:i:s', $ts) . '.000';
    }

    /**
     * Format for SQL Server DATETIME2 with milliseconds precision
     * Uses DateTime object for full precision
     */
    public static function formatSqlServerPrecise(\DateTime $datetime = null): string {
        if ($datetime === null) {
            $datetime = new \DateTime('now', new \DateTimeZone('UTC'));
        }
        $datetime->setTimezone(new \DateTimeZone('UTC'));
        return $datetime->format('Y-m-d H:i:s.v');
    }

    /**
     * Format for ISO 8601 (API responses)
     */
    public static function formatIso($time = null): string {
        $ts = $time ? self::parseToTimestamp($time) : time();
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * Format for logging with timezone indicator
     */
    public static function formatLog($time = null): string {
        $ts = $time ? self::parseToTimestamp($time) : time();
        return gmdate('[Y-m-d H:i:s UTC]', $ts);
    }

    /**
     * Calculate time difference in minutes
     *
     * @param string|int $from Start time
     * @param string|int|null $to End time (null = now)
     * @return int Minutes difference (can be negative)
     */
    public static function diffMinutes($from, $to = null): int {
        $fromTs = is_int($from) ? $from : strtotime($from);
        $toTs = $to === null ? time() : (is_int($to) ? $to : strtotime($to));

        return (int)(($toTs - $fromTs) / 60);
    }

    /**
     * Check if a datetime is in the past (UTC)
     */
    public static function isPast($datetime): bool {
        $ts = is_int($datetime) ? $datetime : strtotime($datetime);
        return $ts < time();
    }

    /**
     * Check if a datetime is in the future (UTC)
     */
    public static function isFuture($datetime): bool {
        $ts = is_int($datetime) ? $datetime : strtotime($datetime);
        return $ts > time();
    }

    /**
     * Generate a 2-digit UTC year (for GUFIs, etc.)
     */
    public static function yearShort(): string {
        return gmdate('y');
    }

    /**
     * Generate date portion for GUFI: YYYYMMDD (UTC)
     */
    public static function gufiDate(): string {
        return gmdate('Ymd');
    }
}
