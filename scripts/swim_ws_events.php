<?php
/**
 * SWIM WebSocket Event Detection and Publishing
 * 
 * This module detects flight data changes after each ADL refresh cycle
 * and publishes events to the WebSocket server.
 * 
 * Include this file in vatsim_adl_daemon.php to enable real-time events.
 * 
 * @package PERTI\SWIM\WebSocket
 * @version 1.0.1
 * @since 2026-01-16
 */

/**
 * Detect flight events since last refresh
 * 
 * @param resource $conn Database connection
 * @param string $lastRefresh Last refresh timestamp (UTC)
 * @return array Events array
 */
function swim_detectFlightEvents($conn, string $lastRefresh): array
{
    $events = [];
    
    // 1. New flights (created since last refresh)
    $sql = "
        SELECT 
            c.callsign,
            c.flight_uid,
            p.fp_dept_icao,
            p.fp_dest_icao,
            p.aircraft_equip,
            p.fp_route,
            pos.lat,
            pos.lon,
            pos.altitude_ft,
            pos.groundspeed_kts,
            pos.heading_deg
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
        WHERE c.first_seen_utc > ?
          AND c.is_active = 1
    ";
    
    $stmt = @sqlsrv_query($conn, $sql, [$lastRefresh]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $events[] = [
                'type' => 'flight.created',
                'data' => [
                    'callsign' => $row['callsign'],
                    'flight_uid' => $row['flight_uid'],
                    'dep' => $row['fp_dept_icao'],
                    'arr' => $row['fp_dest_icao'],
                    'equipment' => $row['aircraft_equip'],
                    'route' => $row['fp_route'],
                    'latitude' => $row['lat'],
                    'longitude' => $row['lon'],
                    'altitude_ft' => $row['altitude_ft'],
                    'groundspeed_kts' => $row['groundspeed_kts'],
                    'heading_deg' => $row['heading_deg'],
                ],
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
    
    // 2. Departed flights (OFF time set since last refresh)
    $sql = "
        SELECT 
            c.callsign,
            c.flight_uid,
            t.off_utc,
            p.fp_dept_icao,
            p.fp_dest_icao
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
        WHERE t.off_utc > ?
          AND c.is_active = 1
    ";
    
    $stmt = @sqlsrv_query($conn, $sql, [$lastRefresh]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $offUtc = $row['off_utc'];
            if ($offUtc instanceof DateTime) {
                $offUtc = $offUtc->format('Y-m-d\TH:i:s\Z');
            }
            
            $events[] = [
                'type' => 'flight.departed',
                'data' => [
                    'callsign' => $row['callsign'],
                    'flight_uid' => $row['flight_uid'],
                    'dep' => $row['fp_dept_icao'],
                    'arr' => $row['fp_dest_icao'],
                    'off_utc' => $offUtc,
                ],
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
    
    // 3. Arrived flights (IN time set since last refresh)
    $sql = "
        SELECT 
            c.callsign,
            c.flight_uid,
            t.in_utc,
            p.fp_dept_icao,
            p.fp_dest_icao
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
        WHERE t.in_utc > ?
    ";
    
    $stmt = @sqlsrv_query($conn, $sql, [$lastRefresh]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $inUtc = $row['in_utc'];
            if ($inUtc instanceof DateTime) {
                $inUtc = $inUtc->format('Y-m-d\TH:i:s\Z');
            }
            
            $events[] = [
                'type' => 'flight.arrived',
                'data' => [
                    'callsign' => $row['callsign'],
                    'flight_uid' => $row['flight_uid'],
                    'dep' => $row['fp_dept_icao'],
                    'arr' => $row['fp_dest_icao'],
                    'in_utc' => $inUtc,
                ],
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
    
    // 4. Deleted flights (marked inactive since last refresh)
    $sql = "
        SELECT 
            c.callsign,
            c.flight_uid,
            c.last_seen_utc
        FROM dbo.adl_flight_core c
        WHERE c.is_active = 0
          AND c.last_seen_utc > ?
    ";
    
    $stmt = @sqlsrv_query($conn, $sql, [$lastRefresh]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $events[] = [
                'type' => 'flight.deleted',
                'data' => [
                    'callsign' => $row['callsign'],
                    'flight_uid' => $row['flight_uid'],
                ],
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
    
    return $events;
}

/**
 * Detect position updates (batched)
 * 
 * @param resource $conn Database connection
 * @param string $lastRefresh Last refresh timestamp
 * @return array Position events
 */
function swim_detectPositionUpdates($conn, string $lastRefresh): array
{
    $positions = [];
    
    $sql = "
        SELECT 
            c.callsign,
            c.flight_uid,
            c.current_artcc_id AS current_artcc,
            pos.lat,
            pos.lon,
            pos.altitude_ft,
            pos.groundspeed_kts,
            pos.heading_deg,
            pos.vertical_rate_fpm,
            p.fp_dept_icao,
            p.fp_dest_icao
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND pos.updated_at > ?
          AND pos.lat IS NOT NULL
    ";
    
    $stmt = @sqlsrv_query($conn, $sql, [$lastRefresh]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $positions[] = [
                'callsign' => $row['callsign'],
                'flight_uid' => $row['flight_uid'],
                'latitude' => (float)$row['lat'],
                'longitude' => (float)$row['lon'],
                'altitude_ft' => (int)$row['altitude_ft'],
                'groundspeed_kts' => (int)$row['groundspeed_kts'],
                'heading_deg' => (int)$row['heading_deg'],
                'vertical_rate_fpm' => (int)($row['vertical_rate_fpm'] ?? 0),
                'current_artcc' => $row['current_artcc'],
                'dep' => $row['fp_dept_icao'],
                'arr' => $row['fp_dest_icao'],
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
    
    if (empty($positions)) {
        return [];
    }
    
    // Return as batched position event
    return [[
        'type' => 'flight.positions',
        'data' => [
            'count' => count($positions),
            'positions' => $positions,
        ],
    ]];
}

/**
 * Detect TMI events
 * 
 * @param resource $conn Database connection  
 * @param string $lastRefresh Last refresh timestamp
 * @return array TMI events
 */
function swim_detectTmiEvents($conn, string $lastRefresh): array
{
    $events = [];
    
    // New TMIs issued
    $sql = "
        SELECT 
            program_id,
            program_type,
            airport_icao,
            start_time,
            end_time,
            reason,
            created_at
        FROM dbo.tmi_programs
        WHERE created_at > ?
    ";
    
    $stmt = @sqlsrv_query($conn, $sql, [$lastRefresh]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $events[] = [
                'type' => 'tmi.issued',
                'data' => [
                    'program_id' => $row['program_id'],
                    'program_type' => $row['program_type'],
                    'airport' => $row['airport_icao'],
                    'start_time' => $row['start_time'] instanceof DateTime 
                        ? $row['start_time']->format('Y-m-d\TH:i:s\Z') 
                        : $row['start_time'],
                    'end_time' => $row['end_time'] instanceof DateTime
                        ? $row['end_time']->format('Y-m-d\TH:i:s\Z')
                        : $row['end_time'],
                    'reason' => $row['reason'],
                ],
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
    
    // TMIs released/ended
    $sql = "
        SELECT 
            program_id,
            program_type,
            airport_icao,
            end_time,
            status
        FROM dbo.tmi_programs
        WHERE updated_at > ?
          AND status IN ('RELEASED', 'PURGED', 'CANCELLED')
    ";
    
    $stmt = @sqlsrv_query($conn, $sql, [$lastRefresh]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $events[] = [
                'type' => 'tmi.released',
                'data' => [
                    'program_id' => $row['program_id'],
                    'program_type' => $row['program_type'],
                    'airport' => $row['airport_icao'],
                    'status' => $row['status'],
                    'end_time' => $row['end_time'] instanceof DateTime
                        ? $row['end_time']->format('Y-m-d\TH:i:s\Z')
                        : $row['end_time'],
                ],
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
    
    return $events;
}

/**
 * Publish events to WebSocket server
 * 
 * @param array $events Events to publish
 * @param string|null $publishUrl Internal publish endpoint URL
 * @return bool Success
 */
function swim_publishToWebSocket(array $events, ?string $publishUrl = null): bool
{
    if (empty($events)) {
        return true;
    }
    
    // Default: write to event file for WebSocket server to poll
    $eventFile = sys_get_temp_dir() . '/swim_ws_events.json';
    
    // Read existing events
    $existingEvents = [];
    if (file_exists($eventFile)) {
        $content = @file_get_contents($eventFile);
        if ($content) {
            $existingEvents = json_decode($content, true) ?: [];
        }
    }
    
    // Append new events with timestamp
    foreach ($events as $event) {
        $existingEvents[] = array_merge($event, [
            '_received_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }
    
    // Limit queue size
    if (count($existingEvents) > 10000) {
        $existingEvents = array_slice($existingEvents, -5000);
    }
    
    // Write atomically
    $tempFile = $eventFile . '.tmp.' . getmypid();
    if (file_put_contents($tempFile, json_encode($existingEvents)) !== false) {
        return @rename($tempFile, $eventFile);
    }
    
    return false;
}

/**
 * Main event detection function for ADL daemon
 * 
 * Call this after each refresh cycle in the daemon.
 * 
 * @param resource $conn Database connection
 * @param string $lastRefresh ISO timestamp of previous refresh
 * @param bool $includePositions Include position updates (high volume)
 * @return array Result with event counts
 */
function swim_processWebSocketEvents($conn, string $lastRefresh, bool $includePositions = true): array
{
    $allEvents = [];
    
    // Detect flight events
    $flightEvents = swim_detectFlightEvents($conn, $lastRefresh);
    $allEvents = array_merge($allEvents, $flightEvents);
    
    // Detect position updates (optionally)
    $positionEvents = [];
    if ($includePositions) {
        $positionEvents = swim_detectPositionUpdates($conn, $lastRefresh);
        $allEvents = array_merge($allEvents, $positionEvents);
    }
    
    // Detect TMI events
    $tmiEvents = swim_detectTmiEvents($conn, $lastRefresh);
    $allEvents = array_merge($allEvents, $tmiEvents);
    
    // Publish to WebSocket server
    $published = swim_publishToWebSocket($allEvents);
    
    return [
        'flight_events' => count($flightEvents),
        'position_events' => $includePositions ? count($positionEvents) : 0,
        'tmi_events' => count($tmiEvents),
        'total_events' => count($allEvents),
        'published' => $published,
    ];
}
