<?php
/**
 * api/stats/StatsHelper.php
 * Helper class for flight statistics API endpoints
 */

class StatsHelper {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /**
     * Get daily summary statistics
     */
    public function getDailyStats($date = null, $days = 7) {
        if ($date === null) {
            $sql = "SELECT TOP (?) * FROM dbo.flight_stats_daily ORDER BY stats_date DESC";
            $params = [$days];
        } else {
            $sql = "SELECT * FROM dbo.flight_stats_daily WHERE stats_date = ?";
            $params = [$date];
        }

        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            return ['error' => $this->getSqlError()];
        }

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = $this->formatRow($row);
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    /**
     * Get hourly statistics
     */
    public function getHourlyStats($hours = 24, $start = null, $end = null) {
        if ($start !== null && $end !== null) {
            $sql = "SELECT * FROM dbo.flight_stats_hourly WHERE bucket_utc BETWEEN ? AND ? ORDER BY bucket_utc";
            $params = [$start, $end];
        } else {
            $sql = "SELECT TOP (?) * FROM dbo.flight_stats_hourly ORDER BY bucket_utc DESC";
            $params = [$hours];
        }

        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            return ['error' => $this->getSqlError()];
        }

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = $this->formatRow($row);
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    /**
     * Get airport statistics
     */
    public function getAirportStats($icao = null, $date = null, $days = 7) {
        $params = [];
        $conditions = [];

        if ($icao !== null) {
            $conditions[] = "icao = ?";
            $params[] = strtoupper($icao);
        }

        if ($date !== null) {
            $conditions[] = "stats_date = ?";
            $params[] = $date;
        } else {
            $conditions[] = "stats_date >= DATEADD(DAY, -?, CAST(GETUTCDATE() AS DATE))";
            $params[] = $days;
        }

        $where = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";
        $sql = "SELECT * FROM dbo.flight_stats_airport $where ORDER BY stats_date DESC, icao";

        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            return ['error' => $this->getSqlError()];
        }

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = $this->formatRow($row);
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    /**
     * Get city pair statistics
     */
    public function getCitypairStats($origin = null, $dest = null, $date = null, $days = 7, $limit = 100) {
        $params = [];
        $conditions = [];

        if ($origin !== null) {
            $conditions[] = "origin_icao = ?";
            $params[] = strtoupper($origin);
        }

        if ($dest !== null) {
            $conditions[] = "dest_icao = ?";
            $params[] = strtoupper($dest);
        }

        if ($date !== null) {
            $conditions[] = "stats_date = ?";
            $params[] = $date;
        } else {
            $conditions[] = "stats_date >= DATEADD(DAY, -?, CAST(GETUTCDATE() AS DATE))";
            $params[] = $days;
        }

        $where = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";
        $sql = "SELECT TOP (?) * FROM dbo.flight_stats_citypair $where ORDER BY stats_date DESC, flight_count DESC";
        array_unshift($params, $limit);

        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            return ['error' => $this->getSqlError()];
        }

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Parse JSON fields
            if (isset($row['top_aircraft_types']) && $row['top_aircraft_types']) {
                $row['top_aircraft_types'] = json_decode($row['top_aircraft_types'], true);
            }
            $results[] = $this->formatRow($row);
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    /**
     * Get ARTCC statistics
     */
    public function getArtccStats($artcc = null, $date = null, $days = 7) {
        $params = [];
        $conditions = [];

        if ($artcc !== null) {
            $conditions[] = "artcc = ?";
            $params[] = strtoupper($artcc);
        }

        if ($date !== null) {
            $conditions[] = "stats_date = ?";
            $params[] = $date;
        } else {
            $conditions[] = "stats_date >= DATEADD(DAY, -?, CAST(GETUTCDATE() AS DATE))";
            $params[] = $days;
        }

        $where = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";
        $sql = "SELECT * FROM dbo.flight_stats_artcc $where ORDER BY stats_date DESC, artcc";

        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            return ['error' => $this->getSqlError()];
        }

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Parse JSON fields
            if (isset($row['hourly_entries']) && $row['hourly_entries']) {
                $row['hourly_entries'] = json_decode($row['hourly_entries'], true);
            }
            if (isset($row['top_origins']) && $row['top_origins']) {
                $row['top_origins'] = json_decode($row['top_origins'], true);
            }
            if (isset($row['top_destinations']) && $row['top_destinations']) {
                $row['top_destinations'] = json_decode($row['top_destinations'], true);
            }
            $results[] = $this->formatRow($row);
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    /**
     * Get TMI impact statistics
     */
    public function getTmiStats($tmi_type = null, $airport = null, $date = null, $days = 7) {
        $params = [];
        $conditions = [];

        if ($tmi_type !== null) {
            $conditions[] = "tmi_type = ?";
            $params[] = strtoupper($tmi_type);
        }

        if ($airport !== null) {
            $conditions[] = "airport_icao = ?";
            $params[] = strtoupper($airport);
        }

        if ($date !== null) {
            $conditions[] = "stats_date = ?";
            $params[] = $date;
        } else {
            $conditions[] = "stats_date >= DATEADD(DAY, -?, CAST(GETUTCDATE() AS DATE))";
            $params[] = $days;
        }

        $where = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";
        $sql = "SELECT * FROM dbo.flight_stats_tmi $where ORDER BY stats_date DESC, tmi_type";

        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            return ['error' => $this->getSqlError()];
        }

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Parse JSON fields
            if (isset($row['hourly_affected']) && $row['hourly_affected']) {
                $row['hourly_affected'] = json_decode($row['hourly_affected'], true);
            }
            $results[] = $this->formatRow($row);
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    /**
     * Get real-time statistics (live from flight tables)
     */
    public function getRealtimeStats() {
        $sql = "
            SELECT
                COUNT(*) AS total_flights,
                COUNT(CASE WHEN c.phase = 'enroute' THEN 1 END) AS enroute,
                COUNT(CASE WHEN c.phase = 'taxiing' THEN 1 END) AS taxiing,
                COUNT(CASE WHEN c.phase = 'departed' THEN 1 END) AS departed,
                COUNT(CASE WHEN c.phase = 'descending' THEN 1 END) AS descending,

                -- Domestic vs International
                COUNT(CASE WHEN LEFT(p.fp_dept_icao, 1) = 'K' AND LEFT(p.fp_dest_icao, 1) = 'K' THEN 1 END) AS domestic,
                COUNT(CASE WHEN LEFT(p.fp_dept_icao, 1) <> 'K' OR LEFT(p.fp_dest_icao, 1) <> 'K' THEN 1 END) AS international,

                -- TMI affected
                COUNT(CASE WHEN tmi.ctl_type IS NOT NULL THEN 1 END) AS tmi_affected,
                AVG(CAST(tmi.delay_minutes AS DECIMAL)) AS avg_tmi_delay,

                -- Recent OOOI stats (last hour)
                AVG(CASE WHEN t.off_utc >= DATEADD(HOUR, -1, GETUTCDATE())
                    AND t.out_utc IS NOT NULL AND t.off_utc IS NOT NULL
                    THEN DATEDIFF(SECOND, t.out_utc, t.off_utc) / 60.0 END) AS avg_taxi_out_last_hour,
                AVG(CASE WHEN t.in_utc >= DATEADD(HOUR, -1, GETUTCDATE())
                    AND t.on_utc IS NOT NULL AND t.in_utc IS NOT NULL
                    THEN DATEDIFF(SECOND, t.on_utc, t.in_utc) / 60.0 END) AS avg_taxi_in_last_hour

            FROM dbo.adl_flight_core c
            LEFT JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            LEFT JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
            LEFT JOIN dbo.adl_flight_tmi tmi ON c.flight_uid = tmi.flight_uid
            WHERE c.is_active = 1
        ";

        $stmt = sqlsrv_query($this->conn, $sql);
        if ($stmt === false) {
            return ['error' => $this->getSqlError()];
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return [
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'total_flights' => (int)($row['total_flights'] ?? 0),
            'by_phase' => [
                'enroute' => (int)($row['enroute'] ?? 0),
                'taxiing' => (int)($row['taxiing'] ?? 0),
                'departed' => (int)($row['departed'] ?? 0),
                'descending' => (int)($row['descending'] ?? 0)
            ],
            'domestic' => (int)($row['domestic'] ?? 0),
            'international' => (int)($row['international'] ?? 0),
            'tmi_affected' => (int)($row['tmi_affected'] ?? 0),
            'avg_tmi_delay_min' => round((float)($row['avg_tmi_delay'] ?? 0), 1),
            'last_hour' => [
                'avg_taxi_out_min' => round((float)($row['avg_taxi_out_last_hour'] ?? 0), 1),
                'avg_taxi_in_min' => round((float)($row['avg_taxi_in_last_hour'] ?? 0), 1)
            ]
        ];
    }

    /**
     * Get job status
     */
    public function getJobStatus() {
        $sql = "SELECT * FROM dbo.vw_flight_stats_job_status";
        $stmt = sqlsrv_query($this->conn, $sql);

        if ($stmt === false) {
            return ['error' => $this->getSqlError()];
        }

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = $this->formatRow($row);
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    /**
     * Format a database row (handle DateTime objects)
     */
    private function formatRow($row) {
        foreach ($row as $key => $value) {
            if ($value instanceof DateTime) {
                $row[$key] = $value->format('Y-m-d\TH:i:s\Z');
            }
        }
        return $row;
    }

    /**
     * Get SQL error message
     */
    private function getSqlError() {
        $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        if (!$errs) return "Unknown SQL error";
        $msgs = [];
        foreach ($errs as $e) {
            $msgs[] = trim($e['message'] ?? '');
        }
        return implode(" | ", $msgs);
    }
}
