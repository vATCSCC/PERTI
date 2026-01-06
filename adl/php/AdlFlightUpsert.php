<?php
/**
 * ADL Flight Upsert Helper
 * 
 * PHP wrapper for sp_UpsertFlight stored procedure.
 * Use this in your VATSIM/SimTraffic data ingestion daemons.
 * 
 * IMPORTANT: This class requires the $conn_adl connection from load/connect.php
 * which connects to the VATSIM_ADL Azure SQL database (not the PERTI MySQL database).
 * 
 * Usage:
 *   require_once __DIR__ . '/../../load/connect.php';  // Sets up $conn_adl
 *   require_once __DIR__ . '/AdlFlightUpsert.php';
 *   
 *   if (!$conn_adl) {
 *       die("ADL database connection not available");
 *   }
 *   
 *   $adl = new AdlFlightUpsert($conn_adl);
 *   
 *   // Upsert a flight
 *   $flightUid = $adl->upsert([
 *       'cid' => 1234567,
 *       'callsign' => 'AAL123',
 *       'source' => 'vatsim',
 *       'lat' => 40.6399,
 *       'lon' => -73.7787,
 *       'altitude_ft' => 35000,
 *       'groundspeed_kts' => 450,
 *       'heading_deg' => 270,
 *       'dept_icao' => 'KJFK',
 *       'dest_icao' => 'KLAX',
 *       'route' => 'SKORR5 RNGRR RBV Q430 ...',
 *       'aircraft_type' => 'B738'
 *   ]);
 */

class AdlFlightUpsert
{
    private $conn;
    
    /**
     * @param resource $conn_adl  The sqlsrv connection to VATSIM_ADL database
     */
    public function __construct($conn_adl)
    {
        $this->conn = $conn_adl;
    }
    
    /**
     * Upsert a flight into the normalized ADL schema
     * 
     * @param array $data Flight data
     * @return int|null flight_uid on success, null on failure
     */
    public function upsert(array $data): ?int
    {
        // Required fields
        if (empty($data['cid']) || empty($data['callsign'])) {
            return null;
        }
        
        $sql = "
            DECLARE @flight_uid BIGINT;
            EXEC dbo.sp_UpsertFlight
                @cid = ?,
                @callsign = ?,
                @source = ?,
                @lat = ?,
                @lon = ?,
                @altitude_ft = ?,
                @groundspeed_kts = ?,
                @heading_deg = ?,
                @vertical_rate_fpm = ?,
                @fp_rule = ?,
                @dept_icao = ?,
                @dest_icao = ?,
                @alt_icao = ?,
                @route = ?,
                @remarks = ?,
                @altitude_filed = ?,
                @tas_filed = ?,
                @dep_time_z = ?,
                @enroute_minutes = ?,
                @fuel_minutes = ?,
                @aircraft_type = ?,
                @aircraft_equip = ?,
                @flight_id = ?,
                @logon_time = ?,
                @qnh_in_hg = ?,
                @qnh_mb = ?,
                @flight_uid = @flight_uid OUTPUT;
            SELECT @flight_uid AS flight_uid;
        ";
        
        $params = [
            $data['cid'],
            $data['callsign'],
            $data['source'] ?? 'vatsim',
            $data['lat'] ?? null,
            $data['lon'] ?? null,
            $data['altitude_ft'] ?? null,
            $data['groundspeed_kts'] ?? null,
            $data['heading_deg'] ?? null,
            $data['vertical_rate_fpm'] ?? null,
            $data['fp_rule'] ?? null,
            $data['dept_icao'] ?? null,
            $data['dest_icao'] ?? null,
            $data['alt_icao'] ?? null,
            $data['route'] ?? null,
            $data['remarks'] ?? null,
            $data['altitude_filed'] ?? null,
            $data['tas_filed'] ?? null,
            $data['dep_time_z'] ?? null,
            $data['enroute_minutes'] ?? null,
            $data['fuel_minutes'] ?? null,
            $data['aircraft_type'] ?? null,
            $data['aircraft_equip'] ?? null,
            $data['flight_id'] ?? null,
            $data['logon_time'] ?? null,
            $data['qnh_in_hg'] ?? null,
            $data['qnh_mb'] ?? null,
        ];
        
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log("AdlFlightUpsert error: " . print_r($errors, true));
            return null;
        }
        
        // Move past any intermediate result sets to get to SELECT
        while (sqlsrv_next_result($stmt)) {
            // Skip through results
        }
        
        // Re-execute to get the SELECT result (sqlsrv quirk with OUTPUT params)
        sqlsrv_free_stmt($stmt);
        
        // Simpler approach: query for the flight_uid after
        $lookupSql = "
            SELECT flight_uid 
            FROM dbo.adl_flight_core 
            WHERE cid = ? AND callsign = ?
            ORDER BY last_seen_utc DESC
        ";
        $stmt = sqlsrv_query($this->conn, $lookupSql, [$data['cid'], $data['callsign']]);
        
        if ($stmt === false) {
            return null;
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        return $row ? (int)$row['flight_uid'] : null;
    }
    
    /**
     * Upsert multiple flights in a batch
     * 
     * @param array $flights Array of flight data arrays
     * @return array Results with flight_uid for each
     */
    public function upsertBatch(array $flights): array
    {
        $results = [];
        foreach ($flights as $flight) {
            $results[] = [
                'callsign' => $flight['callsign'] ?? 'UNKNOWN',
                'flight_uid' => $this->upsert($flight)
            ];
        }
        return $results;
    }
    
    /**
     * Mark stale flights as inactive
     * 
     * @param int $staleMinutes Minutes threshold
     * @return int Number of flights marked inactive
     */
    public function markInactive(int $staleMinutes = 5): int
    {
        $sql = "EXEC dbo.sp_MarkFlightInactive @stale_minutes = ?";
        $stmt = sqlsrv_query($this->conn, $sql, [$staleMinutes]);
        
        if ($stmt === false) return 0;
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        return $row ? (int)$row['flights_marked_inactive'] : 0;
    }
    
    /**
     * Process the parse queue
     * 
     * @param int $maxIterations Max batch iterations
     * @param int $batchSize Flights per batch
     * @return int Number of iterations run
     */
    public function processParseQueue(int $maxIterations = 10, int $batchSize = 50): int
    {
        $sql = "EXEC dbo.sp_ProcessParseQueue @max_iterations = ?, @batch_size = ?";
        $stmt = sqlsrv_query($this->conn, $sql, [$maxIterations, $batchSize]);
        
        if ($stmt === false) return 0;
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        return $row ? (int)$row['iterations_run'] : 0;
    }
    
    /**
     * Get active flight statistics
     * 
     * @return array Stats
     */
    public function getStats(): array
    {
        $sql = "EXEC dbo.sp_GetActiveFlightStats";
        $stmt = sqlsrv_query($this->conn, $sql);
        
        if ($stmt === false) return [];
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        return $row ?: [];
    }
    
    /**
     * Parse VATSIM API response and upsert all pilots
     * 
     * @param array $vatsimData Decoded VATSIM data JSON
     * @return int Number of flights processed
     */
    public function processVatsimData(array $vatsimData): int
    {
        $count = 0;
        $pilots = $vatsimData['pilots'] ?? [];
        
        foreach ($pilots as $pilot) {
            $flightPlan = $pilot['flight_plan'] ?? null;
            
            $data = [
                'cid' => $pilot['cid'],
                'callsign' => $pilot['callsign'],
                'source' => 'vatsim',
                'lat' => $pilot['latitude'],
                'lon' => $pilot['longitude'],
                'altitude_ft' => $pilot['altitude'],
                'groundspeed_kts' => $pilot['groundspeed'],
                'heading_deg' => $pilot['heading'],
                'qnh_in_hg' => $pilot['qnh_i_hg'] ?? null,
                'qnh_mb' => $pilot['qnh_mb'] ?? null,
                'flight_id' => $pilot['server'] ?? null,
                'logon_time' => $pilot['logon_time'] ?? null,
            ];
            
            if ($flightPlan) {
                $data['fp_rule'] = $flightPlan['flight_rules'] ?? null;
                $data['dept_icao'] = $flightPlan['departure'] ?? null;
                $data['dest_icao'] = $flightPlan['arrival'] ?? null;
                $data['alt_icao'] = $flightPlan['alternate'] ?? null;
                $data['route'] = $flightPlan['route'] ?? null;
                $data['remarks'] = $flightPlan['remarks'] ?? null;
                $data['altitude_filed'] = $this->parseAltitude($flightPlan['altitude'] ?? null);
                $data['tas_filed'] = $this->parseTas($flightPlan['cruise_tas'] ?? null);
                $data['dep_time_z'] = $flightPlan['deptime'] ?? null;
                $data['enroute_minutes'] = $this->parseTime($flightPlan['enroute_time'] ?? null);
                $data['fuel_minutes'] = $this->parseTime($flightPlan['fuel_time'] ?? null);
                $data['aircraft_type'] = $this->parseAircraftType($flightPlan['aircraft_faa'] ?? $flightPlan['aircraft_short'] ?? null);
            }
            
            if ($this->upsert($data)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Process SimTraffic data
     * 
     * @param array $simTrafficData SimTraffic flight array
     * @return int Number of flights processed
     */
    public function processSimTrafficData(array $simTrafficData): int
    {
        $count = 0;
        
        foreach ($simTrafficData as $flight) {
            $data = [
                'cid' => $flight['cid'] ?? 0,
                'callsign' => $flight['callsign'],
                'source' => 'simtraffic',
                'lat' => $flight['lat'] ?? $flight['latitude'] ?? null,
                'lon' => $flight['lon'] ?? $flight['longitude'] ?? null,
                'altitude_ft' => $flight['altitude'] ?? null,
                'groundspeed_kts' => $flight['groundspeed'] ?? null,
                'heading_deg' => $flight['heading'] ?? null,
                'dept_icao' => $flight['departure'] ?? $flight['dept_icao'] ?? null,
                'dest_icao' => $flight['arrival'] ?? $flight['dest_icao'] ?? null,
                'route' => $flight['route'] ?? null,
                'aircraft_type' => $flight['aircraft'] ?? $flight['aircraft_type'] ?? null,
            ];
            
            if ($this->upsert($data)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    // Helper methods
    
    private function parseAltitude($alt): ?int
    {
        if ($alt === null || $alt === '') return null;
        // Handle FL350 or 35000 formats
        $alt = strtoupper(trim($alt));
        if (strpos($alt, 'FL') === 0) {
            return (int)substr($alt, 2) * 100;
        }
        return (int)$alt;
    }
    
    private function parseTas($tas): ?int
    {
        if ($tas === null || $tas === '') return null;
        // Handle N0450 or 450 formats
        $tas = strtoupper(trim($tas));
        if (strpos($tas, 'N') === 0) {
            return (int)substr($tas, 1);
        }
        return (int)$tas;
    }
    
    private function parseTime($time): ?int
    {
        if ($time === null || $time === '') return null;
        // Handle HHMM format
        $time = trim($time);
        if (strlen($time) === 4 && is_numeric($time)) {
            return (int)substr($time, 0, 2) * 60 + (int)substr($time, 2, 2);
        }
        return (int)$time;
    }
    
    private function parseAircraftType($aircraft): ?string
    {
        if ($aircraft === null || $aircraft === '') return null;
        // Extract type from H/B738/L format
        $parts = explode('/', trim($aircraft));
        if (count($parts) >= 2) {
            return $parts[1];
        }
        return $aircraft;
    }
}
