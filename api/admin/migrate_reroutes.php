<?php
/**
 * Admin Migration: Reroutes ADL -> TMI
 *
 * One-time migration endpoint. Access via browser:
 *   GET /api/admin/migrate_reroutes.php
 *   GET /api/admin/migrate_reroutes.php?include_flights=1  (also migrate flight assignments)
 *
 * Migrates data from VATSIM_ADL.dbo.tmi_reroutes to VATSIM_TMI.dbo.tmi_reroutes
 * Safe to run multiple times (uses deduplication by name + start_utc).
 *
 * NOTE: Run the SQL migration first to create tables:
 *   adl/migrations/tmi/010_reroute_tables.sql
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../../load/connect.php';

echo "===========================================\n";
echo "Reroutes Migration: VATSIM_ADL -> VATSIM_TMI\n";
echo "===========================================\n\n";

// Check connections
if (!$conn_adl) {
    die("ERROR: VATSIM_ADL connection not available\n");
}
if (!$conn_tmi) {
    die("ERROR: VATSIM_TMI connection not available\n");
}

echo "[OK] Both database connections available\n\n";

$includeFlights = isset($_GET['include_flights']) && $_GET['include_flights'] === '1';
echo "Include flight assignments: " . ($includeFlights ? "YES" : "NO") . "\n\n";

// =============================================================================
// STEP 1: Check source table exists and count records
// =============================================================================
echo "Step 1: Checking source table (VATSIM_ADL.dbo.tmi_reroutes)...\n";

$count_sql = "SELECT COUNT(*) as total FROM dbo.tmi_reroutes";
$stmt = sqlsrv_query($conn_adl, $count_sql);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    die("ERROR: Cannot query source table. Table may not exist.\n" . print_r($errors, true));
}
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$source_count = (int)$row['total'];
sqlsrv_free_stmt($stmt);
echo "  Source has $source_count reroutes\n\n";

if ($source_count === 0) {
    echo "No data to migrate. Exiting.\n";
    exit(0);
}

// =============================================================================
// STEP 2: Check target table exists
// =============================================================================
echo "Step 2: Checking target table (VATSIM_TMI.dbo.tmi_reroutes)...\n";

$check_sql = "SELECT COUNT(*) as total FROM dbo.tmi_reroutes";
$stmt = sqlsrv_query($conn_tmi, $check_sql);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    die("ERROR: Target table doesn't exist. Please run the SQL migration first:\n" .
        "  adl/migrations/tmi/010_reroute_tables.sql\n\n" .
        print_r($errors, true));
}
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$target_count = (int)$row['total'];
sqlsrv_free_stmt($stmt);
echo "  Target has $target_count existing reroutes\n\n";

// =============================================================================
// STEP 3: Fetch all source records
// =============================================================================
echo "Step 3: Fetching source records...\n";

$select_sql = "SELECT
    id, status, name, adv_number,
    start_utc, end_utc, time_basis,
    protected_segment, protected_fixes, avoid_fixes, route_type,
    origin_airports, origin_tracons, origin_centers,
    dest_airports, dest_tracons, dest_centers,
    departure_fix, arrival_fix, thru_centers, thru_fixes, use_airway,
    include_ac_cat, include_ac_types, include_carriers, weight_class,
    altitude_min, altitude_max, rvsm_filter,
    exempt_airports, exempt_carriers, exempt_flights, exempt_active_only,
    airborne_filter,
    comments, impacting_condition, advisory_text,
    created_by, created_utc, updated_utc, activated_utc
FROM dbo.tmi_reroutes";

$stmt = sqlsrv_query($conn_adl, $select_sql);
if ($stmt === false) {
    die("ERROR: Cannot fetch source data: " . print_r(sqlsrv_errors(), true));
}

$reroutes = [];
$id_map = [];  // Maps old ADL id -> new TMI reroute_id
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $reroutes[] = $row;
}
sqlsrv_free_stmt($stmt);
echo "  Fetched " . count($reroutes) . " reroutes from source\n\n";

// =============================================================================
// STEP 4: Get existing names in target (for deduplication)
// =============================================================================
echo "Step 4: Checking for duplicates...\n";

$existing_sql = "SELECT name, start_utc, reroute_id FROM dbo.tmi_reroutes";
$stmt = sqlsrv_query($conn_tmi, $existing_sql);
$existing = [];
$existing_ids = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $startStr = $row['start_utc'] instanceof DateTime ? $row['start_utc']->format('Y-m-d H:i:s') : $row['start_utc'];
        $key = $row['name'] . '|' . $startStr;
        $existing[$key] = true;
        $existing_ids[$key] = $row['reroute_id'];
    }
    sqlsrv_free_stmt($stmt);
}
echo "  Found " . count($existing) . " existing reroutes in target\n\n";

// =============================================================================
// STEP 5: Insert new records
// =============================================================================
echo "Step 5: Migrating reroutes...\n";

$insert_sql = "INSERT INTO dbo.tmi_reroutes (
    status, name, adv_number,
    start_utc, end_utc, time_basis,
    protected_segment, protected_fixes, avoid_fixes, route_type,
    origin_airports, origin_tracons, origin_centers,
    dest_airports, dest_tracons, dest_centers,
    departure_fix, arrival_fix, thru_centers, thru_fixes, use_airway,
    include_ac_cat, include_ac_types, include_carriers, weight_class,
    altitude_min, altitude_max, rvsm_filter,
    exempt_airports, exempt_carriers, exempt_flights, exempt_active_only,
    airborne_filter,
    comments, impacting_condition, advisory_text,
    created_by, created_at, updated_at, activated_utc
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
SELECT SCOPE_IDENTITY() AS new_id;";

$migrated = 0;
$skipped = 0;
$errors = 0;

foreach ($reroutes as $rr) {
    // Build dedup key
    $startStr = $rr['start_utc'] instanceof DateTime ? $rr['start_utc']->format('Y-m-d H:i:s') : $rr['start_utc'];
    $key = $rr['name'] . '|' . $startStr;

    if (isset($existing[$key])) {
        // Record mapping for flight migration
        $id_map[$rr['id']] = $existing_ids[$key];
        $skipped++;
        continue;
    }

    // Prepare parameters
    $params = [
        $rr['status'] ?? 0,
        $rr['name'],
        $rr['adv_number'],
        $rr['start_utc'],
        $rr['end_utc'],
        $rr['time_basis'] ?? 'ETD',
        $rr['protected_segment'],
        $rr['protected_fixes'],
        $rr['avoid_fixes'],
        $rr['route_type'] ?? 'FULL',
        $rr['origin_airports'],
        $rr['origin_tracons'],
        $rr['origin_centers'],
        $rr['dest_airports'],
        $rr['dest_tracons'],
        $rr['dest_centers'],
        $rr['departure_fix'],
        $rr['arrival_fix'],
        $rr['thru_centers'],
        $rr['thru_fixes'],
        $rr['use_airway'],
        $rr['include_ac_cat'] ?? 'ALL',
        $rr['include_ac_types'],
        $rr['include_carriers'],
        $rr['weight_class'] ?? 'ALL',
        $rr['altitude_min'],
        $rr['altitude_max'],
        $rr['rvsm_filter'] ?? 'ALL',
        $rr['exempt_airports'],
        $rr['exempt_carriers'],
        $rr['exempt_flights'],
        $rr['exempt_active_only'] ?? 0,
        $rr['airborne_filter'] ?? 'NOT_AIRBORNE',
        $rr['comments'],
        $rr['impacting_condition'],
        $rr['advisory_text'],
        $rr['created_by'],
        $rr['created_utc'] ?? date('Y-m-d H:i:s'),
        $rr['updated_utc'] ?? date('Y-m-d H:i:s'),
        $rr['activated_utc']
    ];

    $stmt = sqlsrv_query($conn_tmi, $insert_sql, $params);
    if ($stmt === false) {
        $errors++;
        $err = sqlsrv_errors();
        echo "  ERROR inserting '{$rr['name']}': " . ($err[0]['message'] ?? 'Unknown') . "\n";
    } else {
        // Get new ID for flight migration
        sqlsrv_next_result($stmt);
        $newRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($newRow && $newRow['new_id']) {
            $id_map[$rr['id']] = (int)$newRow['new_id'];
        }
        sqlsrv_free_stmt($stmt);
        $migrated++;
    }
}

echo "\n";
echo "  Reroutes migrated: $migrated\n";
echo "  Reroutes skipped (duplicates): $skipped\n";
echo "  Errors: $errors\n\n";

// =============================================================================
// STEP 6: Migrate flight assignments (if requested)
// =============================================================================
$flights_migrated = 0;
$flights_skipped = 0;
$flights_errors = 0;

if ($includeFlights && !empty($id_map)) {
    echo "Step 6: Migrating flight assignments...\n";

    // Get source flights
    $flight_sql = "SELECT * FROM dbo.tmi_reroute_flights WHERE reroute_id IN (" . implode(',', array_keys($id_map)) . ")";
    $stmt = sqlsrv_query($conn_adl, $flight_sql);

    if ($stmt === false) {
        echo "  WARNING: Cannot query flight assignments: " . print_r(sqlsrv_errors(), true) . "\n";
    } else {
        $flights = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $flights[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        echo "  Found " . count($flights) . " flight assignments to migrate\n";

        // Check existing flights in target
        $existing_flights = [];
        $existing_flights_sql = "SELECT reroute_id, flight_key FROM dbo.tmi_reroute_flights";
        $stmt = sqlsrv_query($conn_tmi, $existing_flights_sql);
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $existing_flights[$row['reroute_id'] . '|' . $row['flight_key']] = true;
            }
            sqlsrv_free_stmt($stmt);
        }

        // Insert flights
        $flight_insert = "INSERT INTO dbo.tmi_reroute_flights (
            reroute_id, flight_key, callsign,
            dep_icao, dest_icao, ac_type, filed_altitude,
            route_at_assign, assigned_route, current_route, current_route_utc, final_route,
            last_lat, last_lon, last_altitude, last_position_utc,
            compliance_status, protected_fixes_crossed, avoid_fixes_crossed, compliance_pct, compliance_notes,
            assigned_utc, departed_utc, arrived_utc,
            route_distance_original_nm, route_distance_assigned_nm, route_delta_nm,
            ete_original_min, ete_assigned_min, ete_delta_min,
            manual_status, override_by, override_utc, override_reason
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        foreach ($flights as $f) {
            $newRerouteId = $id_map[$f['reroute_id']] ?? null;
            if (!$newRerouteId) {
                $flights_errors++;
                continue;
            }

            // Check for duplicate
            $fkey = $newRerouteId . '|' . $f['flight_key'];
            if (isset($existing_flights[$fkey])) {
                $flights_skipped++;
                continue;
            }

            $fparams = [
                $newRerouteId,
                $f['flight_key'],
                $f['callsign'],
                $f['dep_icao'],
                $f['dest_icao'],
                $f['ac_type'],
                $f['filed_altitude'],
                $f['route_at_assign'],
                $f['assigned_route'],
                $f['current_route'],
                $f['current_route_utc'],
                $f['final_route'],
                $f['last_lat'],
                $f['last_lon'],
                $f['last_altitude'],
                $f['last_position_utc'],
                $f['compliance_status'] ?? 'PENDING',
                $f['protected_fixes_crossed'],
                $f['avoid_fixes_crossed'],
                $f['compliance_pct'],
                $f['compliance_notes'],
                $f['assigned_utc'],
                $f['departed_utc'],
                $f['arrived_utc'],
                $f['route_distance_original_nm'],
                $f['route_distance_assigned_nm'],
                $f['route_delta_nm'],
                $f['ete_original_min'],
                $f['ete_assigned_min'],
                $f['ete_delta_min'],
                $f['manual_status'] ?? 0,
                $f['override_by'],
                $f['override_utc'],
                $f['override_reason']
            ];

            $stmt = sqlsrv_query($conn_tmi, $flight_insert, $fparams);
            if ($stmt === false) {
                $flights_errors++;
            } else {
                sqlsrv_free_stmt($stmt);
                $flights_migrated++;
            }
        }

        echo "  Flights migrated: $flights_migrated\n";
        echo "  Flights skipped: $flights_skipped\n";
        echo "  Flights errors: $flights_errors\n\n";
    }
} elseif ($includeFlights) {
    echo "Step 6: Skipped (no reroute ID mappings available)\n\n";
} else {
    echo "Step 6: Skipped (flight migration not requested)\n";
    echo "  Add ?include_flights=1 to also migrate flight assignments\n\n";
}

// =============================================================================
// SUMMARY
// =============================================================================
echo "===========================================\n";
echo "Migration Complete!\n";
echo "===========================================\n";

// Verify final counts
$count_sql = "SELECT COUNT(*) as total FROM dbo.tmi_reroutes";
$stmt = sqlsrv_query($conn_tmi, $count_sql);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$final_reroutes = (int)$row['total'];
sqlsrv_free_stmt($stmt);

$final_flights = 0;
$count_sql = "SELECT COUNT(*) as total FROM dbo.tmi_reroute_flights";
$stmt = sqlsrv_query($conn_tmi, $count_sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $final_flights = (int)$row['total'];
    sqlsrv_free_stmt($stmt);
}

echo "  VATSIM_TMI.dbo.tmi_reroutes: $final_reroutes total\n";
echo "  VATSIM_TMI.dbo.tmi_reroute_flights: $final_flights total\n";
echo "\n[DONE] You can now update API endpoints to use \$conn_tmi.\n";
