<?php
/**
 * Airport Configuration Lookup API
 * 
 * Returns available airport configurations for NTML Config entries.
 * 
 * GET /api/mgt/tmi/configs.php
 *   ?airport=KJFK - Get configs for specific airport
 *   ?search=JFK - Search airports by FAA/ICAO code
 * 
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.0.0
 * @date 2026-01-27
 */

header('Content-Type: application/json');
header('Cache-Control: max-age=300'); // Cache for 5 minutes
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

// Parse query params
$airport = strtoupper(trim($_GET['airport'] ?? ''));
$search = strtoupper(trim($_GET['search'] ?? ''));

$results = [];

// Get database connection
global $conn_sqli;

if (!$conn_sqli) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

try {
    if ($airport) {
        // Get configs for specific airport
        $sql = "SELECT 
                    c.id as config_id,
                    c.airport_faa,
                    c.airport_icao,
                    c.config_code,
                    c.config_name,
                    c.arr_runways,
                    c.dep_runways,
                    c.vatsim_vmc_aar,
                    c.vatsim_lvmc_aar,
                    c.vatsim_imc_aar,
                    c.vatsim_limc_aar,
                    c.vatsim_vlimc_aar,
                    c.vatsim_vmc_adr,
                    c.vatsim_imc_adr,
                    c.is_active
                FROM p_configs c
                WHERE (c.airport_faa = ? OR c.airport_icao = ?)
                  AND c.is_active = 1
                ORDER BY c.config_name";
        
        $stmt = $conn_sqli->prepare($sql);
        $stmt->bind_param('ss', $airport, $airport);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $results[] = formatConfig($row);
        }
        
    } elseif ($search) {
        // Search airports with configs
        $searchPattern = "%{$search}%";
        
        $sql = "SELECT DISTINCT 
                    c.airport_faa,
                    c.airport_icao,
                    COUNT(*) as config_count
                FROM p_configs c
                WHERE (c.airport_faa LIKE ? OR c.airport_icao LIKE ?)
                  AND c.is_active = 1
                GROUP BY c.airport_faa, c.airport_icao
                ORDER BY c.airport_faa
                LIMIT 20";
        
        $stmt = $conn_sqli->prepare($sql);
        $stmt->bind_param('ss', $searchPattern, $searchPattern);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $results[] = [
                'airport_faa' => $row['airport_faa'],
                'airport_icao' => $row['airport_icao'],
                'config_count' => intval($row['config_count'])
            ];
        }
        
    } else {
        // Return list of airports with configs
        $sql = "SELECT DISTINCT 
                    c.airport_faa,
                    c.airport_icao,
                    COUNT(*) as config_count
                FROM p_configs c
                WHERE c.is_active = 1
                GROUP BY c.airport_faa, c.airport_icao
                ORDER BY c.airport_faa
                LIMIT 100";
        
        $result = $conn_sqli->query($sql);
        
        while ($row = $result->fetch_assoc()) {
            $results[] = [
                'airport_faa' => $row['airport_faa'],
                'airport_icao' => $row['airport_icao'],
                'config_count' => intval($row['config_count'])
            ];
        }
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query failed: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'airport' => $airport ?: null,
    'search' => $search ?: null,
    'count' => count($results),
    'configs' => $results
]);

// ===========================================
// Helper Functions
// ===========================================

function formatConfig($row) {
    return [
        'id' => intval($row['config_id']),
        'airport_faa' => $row['airport_faa'],
        'airport_icao' => $row['airport_icao'],
        'code' => $row['config_code'],
        'name' => $row['config_name'],
        'arr_runways' => $row['arr_runways'],
        'dep_runways' => $row['dep_runways'],
        'aar' => [
            'vmc' => intval($row['vatsim_vmc_aar'] ?? 0),
            'lvmc' => intval($row['vatsim_lvmc_aar'] ?? 0),
            'imc' => intval($row['vatsim_imc_aar'] ?? 0),
            'limc' => intval($row['vatsim_limc_aar'] ?? 0),
            'vlimc' => intval($row['vatsim_vlimc_aar'] ?? 0)
        ],
        'adr' => [
            'vmc' => intval($row['vatsim_vmc_adr'] ?? 0),
            'imc' => intval($row['vatsim_imc_adr'] ?? 0)
        ]
    ];
}
