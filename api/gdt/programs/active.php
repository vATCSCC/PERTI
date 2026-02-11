<?php
/**
 * GDT Programs - Active Programs API
 *
 * GET /api/gdt/programs/active.php
 *
 * Returns all active, proposed, and modeling programs for the dashboard.
 * Includes summary metrics (flight counts, avg delay) and chain info.
 *
 * Query parameters:
 *   include_recent - If "1", also include programs completed/cancelled in last 2 hours
 *
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "programs": [ ... ],
 *     "server_utc": "2026-02-11T14:00:00Z"
 *   }
 * }
 *
 * @version 1.0.0
 * @date 2026-02-11
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use GET.'
    ]);
}

$conn_tmi = gdt_get_conn_tmi();
$include_recent = isset($_GET['include_recent']) && $_GET['include_recent'] === '1';

// Build query: active + proposed + modeling, optionally recent completed/cancelled
$sql = "
    SELECT
        p.program_id,
        p.program_guid,
        p.ctl_element,
        p.element_type,
        p.program_type,
        p.program_name,
        p.adv_number,
        p.status,
        p.is_proposed,
        p.is_active,
        p.start_utc,
        p.end_utc,
        p.cumulative_start,
        p.program_rate,
        p.delay_limit_min,
        p.impacting_condition,
        p.cause_text,
        p.comments,
        p.gs_probability,
        p.total_flights,
        p.controlled_flights,
        p.exempt_flights,
        p.airborne_flights,
        p.avg_delay_min,
        p.max_delay_min,
        p.total_delay_min,
        p.parent_program_id,
        p.advisory_chain_id,
        p.transition_type,
        p.revision_number,
        p.superseded_by_id,
        p.scope_json,
        p.rates_hourly_json,
        p.created_by,
        p.created_at,
        p.activated_at,
        p.updated_at,
        -- Computed: status sort order (needed for UNION ORDER BY)
        CASE p.status
            WHEN 'ACTIVE' THEN 1
            WHEN 'MODELING' THEN 2
            WHEN 'PROPOSED' THEN 3
            WHEN 'PENDING_COORD' THEN 4
            WHEN 'TRANSITIONED' THEN 5
            WHEN 'COMPLETED' THEN 6
            WHEN 'CANCELLED' THEN 7
            ELSE 8
        END AS status_sort_order,
        -- Computed: elapsed percentage
        CASE
            WHEN p.start_utc IS NOT NULL AND p.end_utc IS NOT NULL
                 AND p.end_utc > p.start_utc AND SYSUTCDATETIME() >= p.start_utc
            THEN CAST(
                DATEDIFF(SECOND, p.start_utc,
                    CASE WHEN SYSUTCDATETIME() < p.end_utc THEN SYSUTCDATETIME() ELSE p.end_utc END
                ) * 100.0 / NULLIF(DATEDIFF(SECOND, p.start_utc, p.end_utc), 0)
            AS DECIMAL(5,1))
            ELSE 0
        END AS elapsed_pct,
        -- Computed: minutes remaining
        CASE
            WHEN p.end_utc IS NOT NULL AND SYSUTCDATETIME() < p.end_utc
            THEN DATEDIFF(MINUTE, SYSUTCDATETIME(), p.end_utc)
            ELSE 0
        END AS minutes_remaining
    FROM dbo.tmi_programs p
    WHERE (
        p.status IN ('ACTIVE', 'PROPOSED', 'MODELING', 'PENDING_COORD')
    )
";

if ($include_recent) {
    $sql .= "
    UNION ALL
    SELECT
        p.program_id, p.program_guid, p.ctl_element, p.element_type,
        p.program_type, p.program_name, p.adv_number, p.status,
        p.is_proposed, p.is_active, p.start_utc, p.end_utc,
        p.cumulative_start, p.program_rate, p.delay_limit_min,
        p.impacting_condition, p.cause_text, p.comments, p.gs_probability,
        p.total_flights, p.controlled_flights, p.exempt_flights,
        p.airborne_flights, p.avg_delay_min, p.max_delay_min, p.total_delay_min,
        p.parent_program_id, p.advisory_chain_id, p.transition_type,
        p.revision_number, p.superseded_by_id, p.scope_json, p.rates_hourly_json,
        p.created_by, p.created_at, p.activated_at, p.updated_at,
        CASE p.status
            WHEN 'ACTIVE' THEN 1
            WHEN 'MODELING' THEN 2
            WHEN 'PROPOSED' THEN 3
            WHEN 'PENDING_COORD' THEN 4
            WHEN 'TRANSITIONED' THEN 5
            WHEN 'COMPLETED' THEN 6
            WHEN 'CANCELLED' THEN 7
            ELSE 8
        END AS status_sort_order,
        CAST(100.0 AS DECIMAL(5,1)) AS elapsed_pct,
        0 AS minutes_remaining
    FROM dbo.tmi_programs p
    WHERE p.status IN ('COMPLETED', 'CANCELLED', 'TRANSITIONED')
    AND p.updated_at >= DATEADD(HOUR, -2, SYSUTCDATETIME())
    ";
}

$sql .= " ORDER BY status_sort_order, start_utc ASC";

$result = fetch_all($conn_tmi, $sql);

if (!$result['success']) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to fetch active programs',
        'errors' => $result['error']
    ]);
}

$now_utc = new DateTime('now', new DateTimeZone('UTC'));

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'programs' => $result['data'],
        'server_utc' => $now_utc->format('Y-m-d\TH:i:s\Z')
    ]
]);
