<?php
/**
 * CTP Nattrak Booking Sync
 *
 * Fetches CTP event bookings from the Nattrak API and upserts them into
 * dbo.ctp_event_bookings in VATSIM_ADL. Called from the ADL daemon every
 * 60 minutes when CTP_EVENT_CODE is configured.
 *
 * Nattrak CSV columns:
 *   ID, VATSIM ID, Departure field, Arrival field, Oceanic track,
 *   Route, Take-off time, Flight level, Domestic flight, SELCAL code
 *
 * @param resource $conn_adl  sqlsrv connection to VATSIM_ADL
 * @param string   $eventCode CTP_EVENT_CODE (e.g. 'CTPE26')
 * @return array   ['inserted' => int, 'updated' => int, 'total' => int, 'ms' => int]
 */
function syncNattrakBookings($conn_adl, string $eventCode): array {
    $start = microtime(true);
    $result = ['inserted' => 0, 'updated' => 0, 'total' => 0, 'ms' => 0, 'errors' => []];

    if (empty($eventCode) || !defined('CTP_API_KEY') || CTP_API_KEY === '') {
        $result['errors'][] = 'CTP_EVENT_CODE or CTP_API_KEY not configured';
        return $result;
    }

    if (!defined('CTP_API_URL') || CTP_API_URL === '') {
        $result['errors'][] = 'CTP_API_URL not configured';
        return $result;
    }

    // Fetch from Nattrak API
    // Endpoint: GET /api/events/{id}/bookings/import/nattrak
    // The event ID is extracted from the API URL configuration or defaults to 1
    $eventId = defined('CTP_NATTRAK_EVENT_ID') ? CTP_NATTRAK_EVENT_ID : 1;
    $url = rtrim(CTP_API_URL, '/') . "/api/events/{$eventId}/bookings/import/nattrak";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . CTP_API_KEY,
            'Accept: text/csv',
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        $result['errors'][] = "Nattrak API failed: HTTP {$httpCode}, curl: {$curlErr}";
        return $result;
    }

    // Parse CSV response — API may return raw CSV or JSON {"csv": "..."}
    $csvData = $response;
    $decoded = @json_decode($response, true);
    if (is_array($decoded) && isset($decoded['csv'])) {
        $csvData = $decoded['csv'];
    }

    $lines = explode("\n", trim($csvData));
    if (count($lines) < 2) {
        $result['errors'][] = 'Nattrak CSV has no data rows';
        return $result;
    }

    // Skip header row
    $header = str_getcsv(array_shift($lines));
    $bookings = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $cols = str_getcsv($line);
        if (count($cols) < 10) continue;

        $cid = (int)trim($cols[1]);
        if ($cid <= 0) continue;

        $dep = strtoupper(trim($cols[2]));
        $arr = strtoupper(trim($cols[3]));
        if (strlen($dep) < 3 || strlen($arr) < 3) continue;

        $bookings[] = [
            'cid'           => $cid,
            'dep_airport'   => substr($dep, 0, 4),
            'arr_airport'   => substr($arr, 0, 4),
            'oceanic_track' => trim($cols[4]) !== '' ? substr(trim($cols[4]), 0, 16) : null,
            'route'         => trim($cols[5]) !== '' ? trim($cols[5]) : null,
            'takeoff_time'  => trim($cols[6]) !== '' ? substr(trim($cols[6]), 0, 8) : null,
            'flight_level'  => is_numeric(trim($cols[7])) ? (int)trim($cols[7]) : null,
            'selcal'        => trim($cols[9]) !== '' ? substr(trim($cols[9]), 0, 8) : null,
        ];
    }

    $result['total'] = count($bookings);

    if (empty($bookings)) {
        $result['ms'] = (int)((microtime(true) - $start) * 1000);
        return $result;
    }

    // Upsert bookings using MERGE via batch approach
    // Build batch VALUES for a temp table, then MERGE into ctp_event_bookings
    $batchSize = 100;
    $batches = array_chunk($bookings, $batchSize);

    foreach ($batches as $batch) {
        // Build VALUES clause with literal values (safe: all values are validated above)
        $values = [];
        foreach ($batch as $b) {
            $cidSafe    = (int)$b['cid'];
            $depSafe    = str_replace("'", "''", $b['dep_airport']);
            $arrSafe    = str_replace("'", "''", $b['arr_airport']);
            $trackSafe  = $b['oceanic_track'] !== null ? "'" . str_replace("'", "''", $b['oceanic_track']) . "'" : 'NULL';
            $routeSafe  = $b['route'] !== null ? "'" . str_replace("'", "''", $b['route']) . "'" : 'NULL';
            $totSafe    = $b['takeoff_time'] !== null ? "'" . str_replace("'", "''", $b['takeoff_time']) . "'" : 'NULL';
            $flSafe     = $b['flight_level'] !== null ? (int)$b['flight_level'] : 'NULL';
            $selcalSafe = $b['selcal'] !== null ? "'" . str_replace("'", "''", $b['selcal']) . "'" : 'NULL';
            $evtSafe    = str_replace("'", "''", $eventCode);

            $values[] = "('{$evtSafe}', {$cidSafe}, '{$depSafe}', '{$arrSafe}', {$trackSafe}, {$routeSafe}, {$totSafe}, {$flSafe}, {$selcalSafe})";
        }

        $valuesSql = implode(",\n            ", $values);

        $sql = "
            MERGE dbo.ctp_event_bookings AS t
            USING (
                SELECT event_code, cid, dep_airport, arr_airport,
                       oceanic_track, route, takeoff_time, flight_level, selcal
                FROM (VALUES
                    {$valuesSql}
                ) AS v(event_code, cid, dep_airport, arr_airport, oceanic_track, route, takeoff_time, flight_level, selcal)
            ) AS s ON t.event_code = s.event_code AND t.cid = s.cid
                  AND t.dep_airport = s.dep_airport AND t.arr_airport = s.arr_airport

            WHEN MATCHED THEN UPDATE SET
                t.oceanic_track = s.oceanic_track,
                t.route = s.route,
                t.takeoff_time = s.takeoff_time,
                t.flight_level = s.flight_level,
                t.selcal = s.selcal,
                t.updated_at = SYSUTCDATETIME()

            WHEN NOT MATCHED BY TARGET THEN INSERT
                (event_code, cid, dep_airport, arr_airport, oceanic_track, route, takeoff_time, flight_level, selcal)
            VALUES
                (s.event_code, s.cid, s.dep_airport, s.arr_airport, s.oceanic_track, s.route, s.takeoff_time, s.flight_level, s.selcal)

            OUTPUT \$action;
        ";

        $stmt = @sqlsrv_query($conn_adl, $sql);
        if ($stmt === false) {
            $result['errors'][] = 'MERGE failed: ' . print_r(sqlsrv_errors(), true);
            continue;
        }

        // Count inserts vs updates from OUTPUT
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUM)) {
            if ($row[0] === 'INSERT') {
                $result['inserted']++;
            } else {
                $result['updated']++;
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    $result['ms'] = (int)((microtime(true) - $start) * 1000);
    return $result;
}
