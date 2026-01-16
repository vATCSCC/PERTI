<?php
/**
 * API: Update hourly AAR/ADR data for an event
 */

header('Content-Type: application/json');

include("../../load/config.php");
include("../../load/connect.php");

if (!$conn_adl) {
    http_response_code(500);
    echo json_encode(['error' => 'ADL database connection not available']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$event_idx = $_POST['event_idx'] ?? null;
$airport_icao = strtoupper($_POST['airport_icao'] ?? '');

if (!$event_idx || !$airport_icao) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing event_idx or airport_icao']);
    exit;
}

// Collect hourly entries from form data
$hourly_entries = [];
foreach ($_POST as $key => $value) {
    if (strpos($key, 'vatsim_aar_') === 0) {
        $hour_utc = str_replace('vatsim_aar_', '', $key);
        $vatsim_aar = $value !== '' ? intval($value) : null;
        $vatsim_adr = isset($_POST['vatsim_adr_' . $hour_utc]) && $_POST['vatsim_adr_' . $hour_utc] !== ''
                      ? intval($_POST['vatsim_adr_' . $hour_utc])
                      : null;
        $hour_offset = isset($_POST['hour_offset_' . $hour_utc])
                      ? intval($_POST['hour_offset_' . $hour_utc])
                      : 0;

        if ($vatsim_aar !== null || $vatsim_adr !== null) {
            $hourly_entries[] = [
                'hour_utc' => $hour_utc,
                'hour_offset' => $hour_offset,
                'vatsim_aar' => $vatsim_aar,
                'vatsim_adr' => $vatsim_adr
            ];
        }
    }
}

$updated = 0;
$inserted = 0;

// Begin transaction
sqlsrv_begin_transaction($conn_adl);

try {
    foreach ($hourly_entries as $entry) {
        // Check if row exists
        $check_sql = "SELECT id FROM dbo.vatusa_event_hourly
                      WHERE event_idx = ? AND airport_icao = ? AND hour_utc = ?";
        $check_params = [$event_idx, $airport_icao, $entry['hour_utc']];
        $check_stmt = sqlsrv_query($conn_adl, $check_sql, $check_params);

        if ($check_stmt === false) {
            throw new Exception('Check query failed: ' . adl_sql_error_message());
        }

        $existing = sqlsrv_fetch_array($check_stmt);
        sqlsrv_free_stmt($check_stmt);

        if ($existing) {
            // Update
            $update_sql = "UPDATE dbo.vatusa_event_hourly
                          SET vatsim_aar = ?, vatsim_adr = ?
                          WHERE event_idx = ? AND airport_icao = ? AND hour_utc = ?";
            $update_params = [
                $entry['vatsim_aar'],
                $entry['vatsim_adr'],
                $event_idx,
                $airport_icao,
                $entry['hour_utc']
            ];
            $stmt = sqlsrv_query($conn_adl, $update_sql, $update_params);
            if ($stmt === false) {
                throw new Exception('Update failed: ' . adl_sql_error_message());
            }
            sqlsrv_free_stmt($stmt);
            $updated++;
        } else {
            // Insert
            $insert_sql = "INSERT INTO dbo.vatusa_event_hourly
                          (event_idx, airport_icao, hour_utc, hour_offset, vatsim_aar, vatsim_adr, created_utc)
                          VALUES (?, ?, ?, ?, ?, ?, GETUTCDATE())";
            $insert_params = [
                $event_idx,
                $airport_icao,
                $entry['hour_utc'],
                $entry['hour_offset'],
                $entry['vatsim_aar'],
                $entry['vatsim_adr']
            ];
            $stmt = sqlsrv_query($conn_adl, $insert_sql, $insert_params);
            if ($stmt === false) {
                throw new Exception('Insert failed: ' . adl_sql_error_message());
            }
            sqlsrv_free_stmt($stmt);
            $inserted++;
        }
    }

    // Calculate summary values
    if (count($hourly_entries) > 0) {
        $aar_values = array_filter(array_column($hourly_entries, 'vatsim_aar'), function($v) { return $v !== null; });
        $adr_values = array_filter(array_column($hourly_entries, 'vatsim_adr'), function($v) { return $v !== null; });

        $peak_aar = count($aar_values) > 0 ? max($aar_values) : null;
        $avg_aar = count($aar_values) > 0 ? array_sum($aar_values) / count($aar_values) : null;
        $avg_adr = count($adr_values) > 0 ? array_sum($adr_values) / count($adr_values) : null;

        // Update airport summary
        $summary_sql = "UPDATE dbo.vatusa_event_airport
                       SET peak_vatsim_aar = ?,
                           avg_vatsim_aar = ?,
                           avg_vatsim_adr = ?,
                           aar_source = 'MANUAL'
                       WHERE event_idx = ? AND airport_icao = ?";
        $summary_params = [$peak_aar, $avg_aar, $avg_adr, $event_idx, $airport_icao];
        $stmt = sqlsrv_query($conn_adl, $summary_sql, $summary_params);
        if ($stmt === false) {
            throw new Exception('Summary update failed: ' . adl_sql_error_message());
        }
        sqlsrv_free_stmt($stmt);
    }

    sqlsrv_commit($conn_adl);

    echo json_encode([
        'success' => true,
        'message' => "Saved: {$updated} updated, {$inserted} inserted",
        'updated' => $updated,
        'inserted' => $inserted
    ]);

} catch (Exception $e) {
    sqlsrv_rollback($conn_adl);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
