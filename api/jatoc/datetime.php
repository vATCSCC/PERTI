<?php
/**
 * JATOC DateTime Utilities
 *
 * Provides consistent datetime handling across all JATOC API endpoints.
 * All times are stored and transmitted in UTC.
 */

class JatocDateTime {

    /**
     * Format datetime to consistent UTC output string
     *
     * @param mixed $dt DateTime object, string, or null
     * @return string|null Formatted as 'Y-m-d H:i:s' with Z suffix, or null
     */
    public static function formatUTC($dt) {
        if ($dt === null) {
            return null;
        }

        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s') . 'Z';
        }

        if (is_string($dt) && !empty($dt)) {
            // Already a string, ensure Z suffix
            $dt = rtrim($dt, 'Z');
            return $dt . 'Z';
        }

        return null;
    }

    /**
     * Parse various input formats to DateTime object
     *
     * Handles:
     * - ISO 8601 with T separator (2025-01-10T14:30:00)
     * - Space separator (2025-01-10 14:30:00)
     * - Missing seconds (2025-01-10 14:30)
     * - Z suffix (2025-01-10T14:30:00Z)
     *
     * @param string $input Input datetime string
     * @return DateTime|null DateTime object in UTC, or null if invalid
     */
    public static function parseInput($input) {
        if (empty($input)) {
            return null;
        }

        $input = trim($input);

        // Handle 'T' separator from HTML datetime-local input
        $input = str_replace('T', ' ', $input);

        // Remove Z suffix if present before parsing
        $input = rtrim($input, 'Z');

        // Add seconds if missing (16 chars = YYYY-MM-DD HH:MM)
        if (strlen($input) === 16) {
            $input .= ':00';
        }

        try {
            return new DateTime($input, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Calculate duration between two datetimes
     *
     * @param mixed $start Start time (DateTime, string, or null)
     * @param mixed $end End time (DateTime, string, or null). If null, uses current UTC time.
     * @return string|null Human readable duration (e.g., "2h 15m"), or null if invalid
     */
    public static function calcDuration($start, $end = null) {
        if (!$start) {
            return null;
        }

        // Parse start time
        $s = ($start instanceof DateTime) ? $start : self::parseInput($start);
        if (!$s) {
            return null;
        }

        // Parse end time (default to now)
        if ($end === null) {
            $e = new DateTime('now', new DateTimeZone('UTC'));
        } else {
            $e = ($end instanceof DateTime) ? $end : self::parseInput($end);
            if (!$e) {
                $e = new DateTime('now', new DateTimeZone('UTC'));
            }
        }

        $diff = $e->getTimestamp() - $s->getTimestamp();
        if ($diff < 0) {
            $diff = 0;
        }

        $hours = floor($diff / 3600);
        $mins = floor(($diff % 3600) / 60);

        if ($hours > 0) {
            return "{$hours}h {$mins}m";
        }
        return "{$mins}m";
    }

    /**
     * Format for SQL Server DATETIME2 column
     *
     * @param mixed $dt DateTime object or string
     * @return string|null Formatted as 'Y-m-d H:i:s' without Z suffix, or null
     */
    public static function toSqlServer($dt) {
        if (!$dt) {
            return null;
        }

        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }

        $parsed = self::parseInput($dt);
        return $parsed ? $parsed->format('Y-m-d H:i:s') : null;
    }

    /**
     * Get current UTC time formatted for SQL Server
     *
     * @return string Current UTC time as 'Y-m-d H:i:s'
     */
    public static function nowSqlServer() {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Get current UTC time formatted for API response
     *
     * @return string Current UTC time as 'Y-m-d H:i:sZ'
     */
    public static function nowUTC() {
        return gmdate('Y-m-d H:i:s') . 'Z';
    }

    /**
     * Validate datetime string format
     *
     * @param string $input Input to validate
     * @return bool True if valid datetime format
     */
    public static function isValid($input) {
        return self::parseInput($input) !== null;
    }
}
