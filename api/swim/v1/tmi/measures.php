<?php
/**
 * VATSIM SWIM API v1 - Unified TMI Measures Endpoint
 *
 * Returns ALL traffic management measures from both:
 *   - VATCSCC (USA): tmi_programs (GS/GDP/AFP), tmi_entries (MIT/MINIT)
 *   - External providers: tmi_flow_measures (ECFMP, NavCanada, VATPAC, etc.)
 *
 * Unified TFMS/FIXM-aligned output format for global interoperability.
 *
 * GET /api/swim/v1/tmi/measures
 * GET /api/swim/v1/tmi/measures?provider=VATCSCC,ECFMP
 * GET /api/swim/v1/tmi/measures?type=GS,GDP,MIT
 * GET /api/swim/v1/tmi/measures?airport=KJFK
 * GET /api/swim/v1/tmi/measures?region=NAM,EUR
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

global $conn_tmi;

if (!$conn_tmi) {
    SwimResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(false, false);

// Get filter parameters
$provider = swim_get_param('provider');             // VATCSCC, ECFMP, etc.
$measure_type = swim_get_param('type');             // GS, GDP, AFP, MIT, MINIT, MDI, etc.
$airport = swim_get_param('airport');               // Control element filter
$region = swim_get_param('region');                 // NAM, EUR, etc.
$status = swim_get_param('status');                 // ACTIVE, EXPIRED, etc.
$active_only = swim_get_param('active_only', 'true') === 'true';
$include_history = swim_get_param('include_history', 'false') === 'true';
$source = swim_get_param('source');                 // usa, external, all (default)

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', SWIM_DEFAULT_PAGE_SIZE, 1, SWIM_MAX_PAGE_SIZE);

$measures = [];
$stats = [
    'by_provider' => [],
    'by_type' => [],
    'by_source' => ['usa' => 0, 'external' => 0]
];

// Parse provider filter
$include_vatcscc = true;
$include_external = true;
if ($provider) {
    $provider_list = array_map('trim', explode(',', strtoupper($provider)));
    $include_vatcscc = in_array('VATCSCC', $provider_list);
    $include_external = count(array_diff($provider_list, ['VATCSCC'])) > 0;
}
if ($source === 'usa') {
    $include_external = false;
} elseif ($source === 'external') {
    $include_vatcscc = false;
}

// ============================================================================
// USA TMI Data (VATCSCC) - from tmi_programs
// ============================================================================
if ($include_vatcscc) {
    $usa_where = [];
    $usa_params = [];

    if ($active_only && !$include_history) {
        $usa_where[] = "status = 'ACTIVE'";
        $usa_where[] = "end_utc > SYSUTCDATETIME()";
    }

    if ($measure_type) {
        $type_list = array_map('trim', explode(',', strtoupper($measure_type)));
        // Map to program types
        $program_types = [];
        foreach ($type_list as $t) {
            if (in_array($t, ['GS', 'GDP', 'AFP'])) {
                if ($t === 'GDP') {
                    $program_types = array_merge($program_types, ['GDP-DAS', 'GDP-GAAP', 'GDP-UDP']);
                } elseif ($t === 'AFP') {
                    $program_types = array_merge($program_types, ['AFP-DAS', 'AFP-GAAP', 'AFP-UDP']);
                } else {
                    $program_types[] = $t;
                }
            }
        }
        if (!empty($program_types)) {
            $placeholders = implode(',', array_fill(0, count($program_types), '?'));
            $usa_where[] = "program_type IN ($placeholders)";
            $usa_params = array_merge($usa_params, $program_types);
        }
    }

    if ($airport) {
        $airport_list = array_map('trim', explode(',', strtoupper($airport)));
        $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
        $usa_where[] = "ctl_element IN ($placeholders)";
        $usa_params = array_merge($usa_params, $airport_list);
    }

    $usa_where_sql = !empty($usa_where) ? 'WHERE ' . implode(' AND ', $usa_where) : '';

    $usa_sql = "
        SELECT
            program_id,
            program_guid,
            program_type,
            ctl_element,
            element_type,
            adv_number,
            impacting_condition,
            start_utc,
            end_utc,
            program_rate,
            delay_limit_minutes,
            scope_centers,
            status,
            created_at
        FROM dbo.tmi_programs
        $usa_where_sql
        ORDER BY start_utc DESC
    ";

    $usa_stmt = sqlsrv_query($conn_tmi, $usa_sql, $usa_params);
    if ($usa_stmt !== false) {
        while ($row = sqlsrv_fetch_array($usa_stmt, SQLSRV_FETCH_ASSOC)) {
            // Normalize program type to TFMS standard
            $raw_type = $row['program_type'];
            $normalized_type = 'OTHER';
            if ($raw_type === 'GS') {
                $normalized_type = 'GS';
            } elseif (strpos($raw_type, 'GDP') === 0) {
                $normalized_type = 'GDP';
            } elseif (strpos($raw_type, 'AFP') === 0) {
                $normalized_type = 'AFP';
            }

            // Determine value and unit based on type
            $value = null;
            $unit = null;
            if ($normalized_type === 'GDP' || $normalized_type === 'AFP') {
                $value = $row['program_rate'];
                $unit = 'PER_HOUR';
            }

            $measure = [
                'id' => 'USA-' . $row['program_id'],
                'guid' => $row['program_guid'],

                'provider' => [
                    'code' => 'VATCSCC',
                    'name' => 'VATSIM Command Center (USA)'
                ],

                'ident' => $raw_type . '_' . $row['ctl_element'] . '_' . $row['program_id'],
                'revision' => 1,

                'event' => null,

                'controlElement' => $row['ctl_element'],
                'elementType' => $row['element_type'] ?? 'APT',

                'type' => $normalized_type,
                'value' => $value,
                'unit' => $unit,

                'reason' => $row['impacting_condition'],

                'filters' => [
                    'arrivalAerodrome' => [$row['ctl_element']],
                    'departureFir' => $row['scope_centers'] ? explode(',', $row['scope_centers']) : null
                ],

                'exemptions' => [],

                'mandatoryRoute' => null,

                'timeRange' => [
                    'start' => formatDT($row['start_utc']),
                    'end' => formatDT($row['end_utc'])
                ],

                'status' => $row['status'],
                'withdrawnAt' => null,

                '_source' => 'usa',
                '_table' => 'tmi_programs',
                '_created_at' => formatDT($row['created_at'])
            ];

            $measure['filters'] = array_filter($measure['filters'], fn($v) => $v !== null);

            $measures[] = $measure;

            // Stats
            $stats['by_provider']['VATCSCC'] = ($stats['by_provider']['VATCSCC'] ?? 0) + 1;
            $stats['by_type'][$normalized_type] = ($stats['by_type'][$normalized_type] ?? 0) + 1;
            $stats['by_source']['usa']++;
        }
        sqlsrv_free_stmt($usa_stmt);
    }

    // Also include tmi_entries (MIT, MINIT) as measures
    $entry_types = [];
    if ($measure_type) {
        $type_list = array_map('trim', explode(',', strtoupper($measure_type)));
        foreach (['MIT', 'MINIT', 'STOP', 'DELAY'] as $et) {
            if (in_array($et, $type_list)) {
                $entry_types[] = $et;
            }
        }
    }

    if (empty($measure_type) || !empty($entry_types)) {
        $entry_where = [];
        $entry_params = [];

        if ($active_only && !$include_history) {
            $entry_where[] = "status = 'ACTIVE'";
            $entry_where[] = "(valid_until IS NULL OR valid_until > SYSUTCDATETIME())";
        }

        if (!empty($entry_types)) {
            $placeholders = implode(',', array_fill(0, count($entry_types), '?'));
            $entry_where[] = "entry_type IN ($placeholders)";
            $entry_params = array_merge($entry_params, $entry_types);
        } elseif (empty($measure_type)) {
            $entry_where[] = "entry_type IN ('MIT', 'MINIT', 'STOP', 'DELAY')";
        }

        if ($airport) {
            $airport_list = array_map('trim', explode(',', strtoupper($airport)));
            $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
            $entry_where[] = "ctl_element IN ($placeholders)";
            $entry_params = array_merge($entry_params, $airport_list);
        }

        $entry_where_sql = !empty($entry_where) ? 'WHERE ' . implode(' AND ', $entry_where) : '';

        $entry_sql = "
            SELECT
                entry_id,
                entry_guid,
                entry_type,
                ctl_element,
                restriction_value,
                restriction_unit,
                reason_code,
                reason_detail,
                valid_from,
                valid_until,
                status,
                created_at
            FROM dbo.tmi_entries
            $entry_where_sql
            ORDER BY created_at DESC
        ";

        $entry_stmt = sqlsrv_query($conn_tmi, $entry_sql, $entry_params);
        if ($entry_stmt !== false) {
            while ($row = sqlsrv_fetch_array($entry_stmt, SQLSRV_FETCH_ASSOC)) {
                $entry_type = $row['entry_type'];
                $unit = $row['restriction_unit'];
                if (!$unit) {
                    $unit = ($entry_type === 'MIT') ? 'NM' : 'MIN';
                }

                $measure = [
                    'id' => 'USA-ENTRY-' . $row['entry_id'],
                    'guid' => $row['entry_guid'],

                    'provider' => [
                        'code' => 'VATCSCC',
                        'name' => 'VATSIM Command Center (USA)'
                    ],

                    'ident' => $entry_type . '_' . ($row['ctl_element'] ?? 'FLOW') . '_' . $row['entry_id'],
                    'revision' => 1,

                    'event' => null,

                    'controlElement' => $row['ctl_element'],
                    'elementType' => $row['ctl_element'] ? 'APT' : null,

                    'type' => $entry_type,
                    'value' => $row['restriction_value'],
                    'unit' => $unit,

                    'reason' => $row['reason_detail'] ?? $row['reason_code'],

                    'filters' => [],
                    'exemptions' => [],
                    'mandatoryRoute' => null,

                    'timeRange' => [
                        'start' => formatDT($row['valid_from']),
                        'end' => formatDT($row['valid_until'])
                    ],

                    'status' => $row['status'],
                    'withdrawnAt' => null,

                    '_source' => 'usa',
                    '_table' => 'tmi_entries',
                    '_created_at' => formatDT($row['created_at'])
                ];

                $measures[] = $measure;

                // Stats
                $stats['by_provider']['VATCSCC'] = ($stats['by_provider']['VATCSCC'] ?? 0) + 1;
                $stats['by_type'][$entry_type] = ($stats['by_type'][$entry_type] ?? 0) + 1;
                $stats['by_source']['usa']++;
            }
            sqlsrv_free_stmt($entry_stmt);
        }
    }
}

// ============================================================================
// External Flow Measures (ECFMP, NavCanada, VATPAC, etc.)
// ============================================================================
if ($include_external) {
    $ext_where = ["p.is_active = 1", "p.provider_code != 'VATCSCC'"];
    $ext_params = [];

    if ($active_only && !$include_history) {
        $ext_where[] = "m.status IN ('NOTIFIED', 'ACTIVE')";
        $ext_where[] = "m.end_utc > SYSUTCDATETIME()";
    }

    if ($provider) {
        $provider_list = array_map('trim', explode(',', strtoupper($provider)));
        $ext_providers = array_diff($provider_list, ['VATCSCC']);
        if (!empty($ext_providers)) {
            $placeholders = implode(',', array_fill(0, count($ext_providers), '?'));
            $ext_where[] = "p.provider_code IN ($placeholders)";
            $ext_params = array_merge($ext_params, array_values($ext_providers));
        }
    }

    if ($measure_type) {
        $type_list = array_map('trim', explode(',', strtoupper($measure_type)));
        $placeholders = implode(',', array_fill(0, count($type_list), '?'));
        $ext_where[] = "m.measure_type IN ($placeholders)";
        $ext_params = array_merge($ext_params, $type_list);
    }

    if ($airport) {
        $airport_list = array_map('trim', explode(',', strtoupper($airport)));
        $placeholders = implode(',', array_fill(0, count($airport_list), '?'));
        $ext_where[] = "m.ctl_element IN ($placeholders)";
        $ext_params = array_merge($ext_params, $airport_list);
    }

    $ext_where_sql = 'WHERE ' . implode(' AND ', $ext_where);

    $ext_sql = "
        SELECT
            m.measure_id,
            m.measure_guid,
            p.provider_code,
            p.provider_name,
            m.ident,
            m.revision,
            m.event_id,
            e.event_code,
            e.event_name,
            m.ctl_element,
            m.element_type,
            m.measure_type,
            m.measure_value,
            m.measure_unit,
            m.reason,
            m.filters_json,
            m.exemptions_json,
            m.mandatory_route_json,
            m.start_utc,
            m.end_utc,
            m.status,
            m.withdrawn_at,
            m.created_at
        FROM dbo.tmi_flow_measures m
        JOIN dbo.tmi_flow_providers p ON m.provider_id = p.provider_id
        LEFT JOIN dbo.tmi_flow_events e ON m.event_id = e.event_id
        $ext_where_sql
        ORDER BY m.start_utc DESC
    ";

    $ext_stmt = sqlsrv_query($conn_tmi, $ext_sql, $ext_params);
    if ($ext_stmt !== false) {
        while ($row = sqlsrv_fetch_array($ext_stmt, SQLSRV_FETCH_ASSOC)) {
            $filters = json_decode($row['filters_json'] ?? '{}', true) ?: [];
            $exemptions = json_decode($row['exemptions_json'] ?? '{}', true) ?: [];
            $mandatory_route = json_decode($row['mandatory_route_json'] ?? '[]', true) ?: [];

            $measure = [
                'id' => $row['provider_code'] . '-' . $row['measure_id'],
                'guid' => $row['measure_guid'],

                'provider' => [
                    'code' => $row['provider_code'],
                    'name' => $row['provider_name']
                ],

                'ident' => $row['ident'],
                'revision' => $row['revision'],

                'event' => $row['event_id'] ? [
                    'id' => $row['event_id'],
                    'code' => $row['event_code'],
                    'name' => $row['event_name']
                ] : null,

                'controlElement' => $row['ctl_element'],
                'elementType' => $row['element_type'],

                'type' => $row['measure_type'],
                'value' => $row['measure_value'],
                'unit' => $row['measure_unit'],

                'reason' => $row['reason'],

                'filters' => [
                    'departureAerodrome' => $filters['adep'] ?? null,
                    'arrivalAerodrome' => $filters['ades'] ?? null,
                    'departureFir' => $filters['adep_fir'] ?? null,
                    'arrivalFir' => $filters['ades_fir'] ?? null,
                    'waypoints' => $filters['waypoints'] ?? null,
                    'airways' => $filters['airways'] ?? null,
                    'flightLevel' => $filters['levels'] ?? null,
                    'aircraftType' => $filters['aircraft_type'] ?? null
                ],

                'exemptions' => [
                    'eventFlights' => $exemptions['event_flights'] ?? false,
                    'carriers' => $exemptions['carriers'] ?? null,
                    'aircraftTypes' => $exemptions['aircraft_types'] ?? null
                ],

                'mandatoryRoute' => !empty($mandatory_route) ? $mandatory_route : null,

                'timeRange' => [
                    'start' => formatDT($row['start_utc']),
                    'end' => formatDT($row['end_utc'])
                ],

                'status' => $row['status'],
                'withdrawnAt' => formatDT($row['withdrawn_at']),

                '_source' => 'external',
                '_table' => 'tmi_flow_measures',
                '_created_at' => formatDT($row['created_at'])
            ];

            $measure['filters'] = array_filter($measure['filters'], fn($v) => $v !== null);
            $measure['exemptions'] = array_filter($measure['exemptions'], fn($v) => $v !== null && $v !== false);

            $measures[] = $measure;

            // Stats
            $prov = $row['provider_code'];
            $stats['by_provider'][$prov] = ($stats['by_provider'][$prov] ?? 0) + 1;
            $stats['by_type'][$row['measure_type']] = ($stats['by_type'][$row['measure_type']] ?? 0) + 1;
            $stats['by_source']['external']++;
        }
        sqlsrv_free_stmt($ext_stmt);
    }
}

// Sort all measures by start time descending
usort($measures, fn($a, $b) => strcmp($b['timeRange']['start'] ?? '', $a['timeRange']['start'] ?? ''));

// Paginate
$total = count($measures);
$offset = ($page - 1) * $per_page;
$measures = array_slice($measures, $offset, $per_page);

$response = [
    'measures' => $measures,
    'statistics' => $stats,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page),
        'has_more' => $page < ceil($total / $per_page)
    ],
    'filters' => [
        'provider' => $provider,
        'type' => $measure_type,
        'airport' => $airport,
        'region' => $region,
        'source' => $source,
        'active_only' => $active_only
    ],
    'sources' => [
        'usa' => ['tmi_programs', 'tmi_entries'],
        'external' => ['tmi_flow_measures']
    ]
];

SwimResponse::success($response, [
    'source' => 'vatsim_tmi',
    'unified' => true
]);

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
