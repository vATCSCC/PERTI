<?php
/**
 * CTP Session Statistics API
 *
 * GET /api/ctp/stats.php?session_id=N
 *
 * Returns aggregated statistics for a CTP session.
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed. Use GET.']);
}

$conn_tmi = ctp_get_conn_tmi();

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($session_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
}

$sql = "
    SELECT
        COUNT(*) AS total_flights,
        SUM(CASE WHEN is_excluded = 0 THEN 1 ELSE 0 END) AS active_flights,
        SUM(CASE WHEN is_excluded = 1 THEN 1 ELSE 0 END) AS excluded_flights,
        SUM(CASE WHEN is_event_flight = 1 THEN 1 ELSE 0 END) AS event_flights,
        SUM(CASE WHEN edct_status != 'NONE' AND is_excluded = 0 THEN 1 ELSE 0 END) AS slotted_flights,
        SUM(CASE WHEN route_status = 'MODIFIED' AND is_excluded = 0 THEN 1 ELSE 0 END) AS modified_flights,
        SUM(CASE WHEN route_status = 'VALIDATED' AND is_excluded = 0 THEN 1 ELSE 0 END) AS validated_flights,

        -- EDCT stats
        AVG(CASE WHEN slot_delay_min IS NOT NULL AND is_excluded = 0 THEN slot_delay_min END) AS avg_delay_min,
        MAX(CASE WHEN slot_delay_min IS NOT NULL AND is_excluded = 0 THEN slot_delay_min END) AS max_delay_min,
        MIN(CASE WHEN slot_delay_min IS NOT NULL AND is_excluded = 0 THEN slot_delay_min END) AS min_delay_min,

        -- Compliance
        SUM(CASE WHEN compliance_status = 'ON_TIME' THEN 1 ELSE 0 END) AS compliant_flights,
        SUM(CASE WHEN compliance_status = 'EARLY' THEN 1 ELSE 0 END) AS early_flights,
        SUM(CASE WHEN compliance_status = 'LATE' THEN 1 ELSE 0 END) AS late_flights,
        SUM(CASE WHEN compliance_status = 'NO_SHOW' THEN 1 ELSE 0 END) AS no_show_flights,
        AVG(CASE WHEN compliance_delta_min IS NOT NULL THEN compliance_delta_min END) AS avg_compliance_delta_min,

        -- Segment stats
        SUM(CASE WHEN seg_na_status = 'MODIFIED' THEN 1 ELSE 0 END) AS na_modified,
        SUM(CASE WHEN seg_oceanic_status = 'MODIFIED' THEN 1 ELSE 0 END) AS oceanic_modified,
        SUM(CASE WHEN seg_eu_status = 'MODIFIED' THEN 1 ELSE 0 END) AS eu_modified,

        -- Top entry FIRs
        (SELECT TOP 1 oceanic_entry_fir FROM dbo.ctp_flight_control
         WHERE session_id = ? AND is_excluded = 0 AND oceanic_entry_fir IS NOT NULL
         GROUP BY oceanic_entry_fir ORDER BY COUNT(*) DESC) AS top_entry_fir,

        -- Top entry fix
        (SELECT TOP 1 oceanic_entry_fix FROM dbo.ctp_flight_control
         WHERE session_id = ? AND is_excluded = 0 AND oceanic_entry_fix IS NOT NULL
         GROUP BY oceanic_entry_fix ORDER BY COUNT(*) DESC) AS top_entry_fix

    FROM dbo.ctp_flight_control
    WHERE session_id = ?
";

$result = ctp_fetch_one($conn_tmi, $sql, [$session_id, $session_id, $session_id]);
if (!$result['success'] || !$result['data']) {
    respond_json(200, ['status' => 'ok', 'data' => ['total_flights' => 0]]);
}

$data = $result['data'];
foreach ($data as $k => $v) {
    if ($v === null) continue;
    if (is_numeric($v) && (strpos($k, 'avg_') === 0 || strpos($k, 'min_') === 0 || strpos($k, 'max_') === 0)) {
        $data[$k] = round((float)$v, 1);
    } elseif (is_numeric($v) && !is_string($v)) {
        $data[$k] = (int)$v;
    }
}

respond_json(200, ['status' => 'ok', 'data' => $data]);
