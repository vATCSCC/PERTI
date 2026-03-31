<?php
/**
 * VATSWIM Splits - Shared Helpers
 *
 * Common functions for splits query endpoints. Handles parameter parsing,
 * strata filtering, and position loading from SWIM mirror tables.
 *
 * @package PERTI\SWIM\Splits
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

/**
 * Parse common splits query parameters.
 *
 * @return array [facility, strata, format]
 */
function splits_parse_params(): array {
    return [
        'facility' => strtoupper(trim(swim_get_param('facility', ''))),
        'strata'   => strtolower(trim(swim_get_param('strata', 'all'))),
        'format'   => swim_validate_format(swim_get_param('format', 'json'), 'reference'),
    ];
}

/**
 * Load positions for a set of config IDs from splits_positions_swim.
 *
 * @param resource $conn SWIM sqlsrv connection
 * @param array $config_ids Array of config IDs
 * @param string $strata Strata filter: all, high, low, superhigh
 * @return array Map of config_id => [positions]
 */
function splits_load_positions($conn, array $config_ids, string $strata = 'all'): array {
    if (empty($config_ids)) return [];

    // Build IN clause with integer placeholders
    $placeholders = implode(',', array_map('intval', $config_ids));
    $sql = "SELECT id, config_id, position_name, sectors, color, sort_order,
                   frequency, controller_oi, strata_filter, start_time_utc, end_time_utc
            FROM dbo.splits_positions_swim
            WHERE config_id IN ($placeholders)
            ORDER BY config_id, sort_order";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) return [];

    $positions_map = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Parse JSON fields
        if (isset($row['sectors']) && is_string($row['sectors'])) {
            $row['sectors'] = json_decode($row['sectors'], true) ?? [];
        }
        if (isset($row['strata_filter']) && is_string($row['strata_filter'])) {
            $row['strata_filter'] = json_decode($row['strata_filter'], true);
        }

        // Apply strata filter on positions
        if ($strata !== 'all' && $row['strata_filter'] !== null) {
            if (!($row['strata_filter'][$strata] ?? true)) {
                continue; // Position not visible in this stratum
            }
        }

        // Convert DateTime objects
        foreach (['start_time_utc', 'end_time_utc'] as $field) {
            if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                $row[$field] = $row[$field]->format('Y-m-d\TH:i:s\Z');
            }
        }

        $cid = $row['config_id'];
        unset($row['config_id']); // Don't duplicate in nested response
        $positions_map[$cid][] = $row;
    }
    sqlsrv_free_stmt($stmt);

    return $positions_map;
}

/**
 * Format a config row for API response.
 *
 * @param array $row Raw database row
 * @return array Formatted config
 */
function splits_format_config(array $row): array {
    $datetime_fields = ['start_time_utc', 'end_time_utc', 'created_at', 'updated_at', 'activated_at', 'synced_utc'];
    foreach ($datetime_fields as $field) {
        if (isset($row[$field]) && $row[$field] instanceof DateTime) {
            $row[$field] = $row[$field]->format('Y-m-d\TH:i:s\Z');
        }
    }
    // Remove synced_utc from public response (internal field)
    unset($row['synced_utc']);
    return $row;
}

/**
 * Build WHERE clause for strata filtering on configs.
 *
 * @param string $strata all, high, low, superhigh
 * @param array &$params Parameters array to append to
 * @return string SQL WHERE fragment (empty string if no filter)
 */
function splits_strata_where(string $strata, array &$params): string {
    if ($strata === 'all') return '';
    // sector_type on configs: 'high' or 'low'. superhigh is a strata_filter on positions, not a config type.
    if (in_array($strata, ['high', 'low'])) {
        $params[] = $strata;
        return ' AND c.sector_type = ?';
    }
    // For superhigh, we don't filter configs — we filter positions via strata_filter
    return '';
}
