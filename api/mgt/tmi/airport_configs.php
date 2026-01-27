<?php
/**
 * TMI Airport Configs API
 * 
 * Returns airport configuration presets for use in the TMI Publisher CONFIG form.
 * Queries the airport_config database tables.
 * 
 * GET /api/mgt/tmi/airport_configs.php?airport=JFK
 * 
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.0.0
 * @date 2026-01-27
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load dependencies
try {
    require_once __DIR__ . '/../../../load/config.php';
    require_once __DIR__ . '/../../../load/connect.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config load error']);
    exit;
}

// Get airport parameter (FAA or ICAO code)
$airport = strtoupper(trim($_GET['airport'] ?? ''));
$activeOnly = ($_GET['active_only'] ?? '1') === '1';

if (empty($airport) || strlen($airport) < 3 || strlen($airport) > 4) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid airport code. Provide 3-4 character FAA or ICAO code.']);
    exit;
}

// Normalize: if 4 chars starting with K, strip it for FAA lookup
$faaCode = $airport;
$icaoCode = $airport;
if (strlen($airport) === 4 && $airport[0] === 'K') {
    $faaCode = substr($airport, 1);
}
if (strlen($airport) === 3) {
    $icaoCode = 'K' . $airport;
}

$configs = [];

// Try ADL SQL Server first (preferred)
if (isset($conn_adl) && $conn_adl) {
    $sql = "
        SELECT
            s.config_id,
            s.airport_faa,
            s.airport_icao,
            s.config_name,
            s.config_code,
            s.is_active,
            s.arr_runways,
            s.dep_runways,
            r.vatsim_vmc_aar,
            r.vatsim_imc_aar,
            r.vatsim_vmc_adr,
            r.vatsim_imc_adr
        FROM dbo.vw_airport_config_summary s
        LEFT JOIN dbo.vw_airport_config_rates r ON s.config_id = r.config_id
        WHERE (s.airport_faa = ? OR s.airport_icao = ?)
    ";
    
    if ($activeOnly) {
        $sql .= " AND s.is_active = 1";
    }
    
    $sql .= " ORDER BY s.config_name ASC";
    
    $stmt = sqlsrv_query($conn_adl, $sql, [$faaCode, $icaoCode]);
    
    if ($stmt === false) {
        // Log error but don't fail - try fallback
        error_log("ADL airport_configs query failed: " . print_r(sqlsrv_errors(), true));
    } else {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $configs[] = [
                'configId' => intval($row['config_id']),
                'airportFaa' => $row['airport_faa'],
                'airportIcao' => $row['airport_icao'],
                'configName' => $row['config_name'],
                'configCode' => $row['config_code'] ?? null,
                'isActive' => (bool)$row['is_active'],
                'arrRunways' => $row['arr_runways'] ?? '',
                'depRunways' => $row['dep_runways'] ?? '',
                'rates' => [
                    'vmcAar' => $row['vatsim_vmc_aar'] ?? null,
                    'imcAar' => $row['vatsim_imc_aar'] ?? null,
                    'vmcAdr' => $row['vatsim_vmc_adr'] ?? null,
                    'imcAdr' => $row['vatsim_imc_adr'] ?? null
                ]
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
}

// Fallback to MySQL if no results and MySQL available
if (empty($configs) && isset($conn_sqli) && $conn_sqli) {
    $escapedFaa = mysqli_real_escape_string($conn_sqli, $faaCode);
    $query = "SELECT * FROM config_data WHERE airport = '$escapedFaa' ORDER BY config_name ASC";
    
    if ($activeOnly) {
        $query = "SELECT * FROM config_data WHERE airport = '$escapedFaa' AND (is_active = 1 OR is_active IS NULL) ORDER BY config_name ASC";
    }
    
    $result = mysqli_query($conn_sqli, $query);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $configs[] = [
                'configId' => intval($row['id']),
                'airportFaa' => $row['airport'],
                'airportIcao' => 'K' . $row['airport'],
                'configName' => $row['config_name'] ?? ($row['arr'] . ' / ' . $row['dep']),
                'configCode' => $row['config_code'] ?? null,
                'isActive' => true,
                'arrRunways' => $row['arr'] ?? '',
                'depRunways' => $row['dep'] ?? '',
                'rates' => [
                    'vmcAar' => $row['vmc_aar'] ?? null,
                    'imcAar' => $row['imc_aar'] ?? null,
                    'vmcAdr' => $row['vmc_adr'] ?? null,
                    'imcAdr' => $row['imc_adr'] ?? null
                ]
            ];
        }
        mysqli_free_result($result);
    }
}

// Return results
echo json_encode([
    'success' => true,
    'airport' => [
        'faa' => $faaCode,
        'icao' => $icaoCode
    ],
    'count' => count($configs),
    'configs' => $configs
]);
