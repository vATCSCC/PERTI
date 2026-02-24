<?php
/**
 * TMI Active Data API
 * 
 * Returns all currently active TMI data in a single call.
 * Useful for dashboard displays and real-time monitoring.
 * 
 * Endpoints:
 *   GET /api/tmi/active.php - Get all active TMI data
 * 
 * Query Parameters:
 *   include - Comma-separated list of data types to include
 *             (entries, programs, advisories, reroutes, routes)
 *             Default: all
 * 
 * @package PERTI
 * @subpackage TMI
 */

require_once __DIR__ . '/helpers.php';

$method = tmi_method();

if ($method !== 'GET') {
    TmiResponse::error('Method not allowed', 405);
}

// No auth required for read
tmi_init(false);

global $conn_tmi;

// Get org scope for filtering (global users see all)
$org_filter = tmi_org_scope_sql();
$org_params = tmi_org_scope_params();

// Parse include parameter
$include_param = tmi_param('include', 'entries,programs,advisories,reroutes,routes');
$include = array_map('trim', explode(',', strtolower($include_param)));

$response = [
    'timestamp' => gmdate('c')
];

// Active NTML Entries
if (in_array('entries', $include)) {
    $entries = tmi_query(
        "SELECT
            entry_id, entry_guid, determinant_code, protocol_type, entry_type,
            ctl_element, element_type, requesting_facility, providing_facility,
            restriction_value, restriction_unit, condition_text, qualifiers,
            exclusions, reason_code, reason_detail, valid_from, valid_until,
            status, source_type, created_at
         FROM dbo.vw_tmi_active_entries
         WHERE $org_filter
         ORDER BY created_at DESC",
        $org_params
    );
    $response['entries'] = $entries ?: [];
    $response['entry_count'] = count($response['entries']);
}

// Active Programs (GS/GDP)
if (in_array('programs', $include)) {
    $programs = tmi_query(
        "SELECT
            program_id, program_guid, ctl_element, element_type, program_type,
            program_name, adv_number, start_utc, end_utc, status,
            is_proposed, is_active, program_rate, reserve_rate,
            delay_limit_min, impacting_condition, cause_text,
            total_flights, controlled_flights, exempt_flights,
            avg_delay_min, max_delay_min, created_at, activated_at
         FROM dbo.vw_tmi_active_programs
         WHERE $org_filter
         ORDER BY start_utc ASC",
        $org_params
    );
    $response['programs'] = $programs ?: [];
    $response['program_count'] = count($response['programs']);
    
    // Count by type
    $gs_count = 0;
    $gdp_count = 0;
    foreach ($response['programs'] as $p) {
        if ($p['program_type'] === 'GS') {
            $gs_count++;
        } else {
            $gdp_count++;
        }
    }
    $response['ground_stops'] = $gs_count;
    $response['ground_delays'] = $gdp_count;
}

// Active Advisories
if (in_array('advisories', $include)) {
    $advisories = tmi_query(
        "SELECT
            advisory_id, advisory_guid, advisory_number, advisory_type,
            ctl_element, element_type, program_id, program_rate, delay_cap,
            effective_from, effective_until, subject, reason_code,
            reroute_name, mit_miles, mit_type, mit_fix,
            status, source_type, created_at
         FROM dbo.vw_tmi_active_advisories
         WHERE $org_filter
         ORDER BY created_at DESC",
        $org_params
    );
    $response['advisories'] = $advisories ?: [];
    $response['advisory_count'] = count($response['advisories']);
}

// Active Reroutes
if (in_array('reroutes', $include)) {
    $reroutes = tmi_query(
        "SELECT
            reroute_id, reroute_guid, status, name, adv_number,
            start_utc, end_utc, time_basis, protected_segment,
            route_type, impacting_condition, color, line_weight,
            total_assigned, compliant_count, non_compliant_count,
            compliance_rate, created_at, activated_at
         FROM dbo.vw_tmi_active_reroutes
         WHERE $org_filter
         ORDER BY start_utc ASC",
        $org_params
    );
    $response['reroutes'] = $reroutes ?: [];
    $response['reroute_count'] = count($response['reroutes']);
}

// Active Public Routes
if (in_array('routes', $include)) {
    $routes = tmi_query(
        "SELECT
            route_id, route_guid, status, name, adv_number,
            route_string, advisory_text, color, line_weight, line_style,
            valid_start_utc, valid_end_utc, constrained_area, reason,
            route_geojson, created_at
         FROM dbo.vw_tmi_active_public_routes
         WHERE $org_filter
         ORDER BY created_at DESC",
        $org_params
    );
    $response['public_routes'] = $routes ?: [];
    $response['route_count'] = count($response['public_routes']);
}

// Summary stats
$response['summary'] = [
    'entries' => $response['entry_count'] ?? 0,
    'programs' => $response['program_count'] ?? 0,
    'ground_stops' => $response['ground_stops'] ?? 0,
    'ground_delays' => $response['ground_delays'] ?? 0,
    'advisories' => $response['advisory_count'] ?? 0,
    'reroutes' => $response['reroute_count'] ?? 0,
    'public_routes' => $response['route_count'] ?? 0
];

TmiResponse::success($response);
