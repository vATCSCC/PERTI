<?php
/**
 * api/simulator/engine.php
 * 
 * PHP proxy to the Node.js flight engine API
 * Provides a unified API for the simulator frontend
 * 
 * POST /api/simulator/engine.php
 * Body: { action: string, ... }
 * 
 * Actions:
 *   - create: Create new simulation
 *   - list: List all simulations
 *   - status: Get simulation status
 *   - spawn: Spawn aircraft
 *   - aircraft: Get all aircraft
 *   - tick: Advance simulation
 *   - command: Issue ATC command
 *   - pause: Pause simulation
 *   - resume: Resume simulation
 *   - delete: Delete simulation
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Node.js engine URL - configure based on environment
define('ENGINE_URL', getenv('ATFM_ENGINE_URL') ?: 'http://localhost:3001');

// Handle both GET and POST
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_GET;
}

$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'health':
            echo proxyGet('/health');
            break;
            
        case 'create':
            echo proxyPost('/simulation/create', [
                'name' => $input['name'] ?? 'Training Session',
                'startTime' => $input['startTime'] ?? null
            ]);
            break;
            
        case 'list':
            echo proxyGet('/simulation');
            break;
            
        case 'status':
            $simId = $input['simId'] ?? '';
            echo proxyGet("/simulation/{$simId}");
            break;
            
        case 'spawn':
            $simId = $input['simId'] ?? '';
            echo proxyPost("/simulation/{$simId}/aircraft", [
                'callsign' => $input['callsign'],
                'aircraftType' => $input['aircraftType'],
                'origin' => $input['origin'],
                'destination' => $input['destination'],
                'route' => $input['route'] ?? null,
                'altitude' => intval($input['altitude'] ?? 0),
                'speed' => intval($input['speed'] ?? 0),
                'heading' => isset($input['heading']) ? intval($input['heading']) : null,
                'cruiseAltitude' => intval($input['cruiseAltitude'] ?? 35000),
                'lat' => isset($input['lat']) ? floatval($input['lat']) : null,
                'lon' => isset($input['lon']) ? floatval($input['lon']) : null
            ]);
            break;
            
        case 'aircraft':
            $simId = $input['simId'] ?? '';
            $callsign = $input['callsign'] ?? '';
            if ($callsign) {
                echo proxyGet("/simulation/{$simId}/aircraft/{$callsign}");
            } else {
                echo proxyGet("/simulation/{$simId}/aircraft");
            }
            break;
            
        case 'tick':
            $simId = $input['simId'] ?? '';
            $delta = intval($input['deltaSeconds'] ?? 1);
            echo proxyPost("/simulation/{$simId}/tick", [
                'deltaSeconds' => $delta
            ]);
            break;
            
        case 'run':
            $simId = $input['simId'] ?? '';
            echo proxyPost("/simulation/{$simId}/run", [
                'durationSeconds' => intval($input['durationSeconds'] ?? 60),
                'tickInterval' => intval($input['tickInterval'] ?? 1)
            ]);
            break;
            
        case 'command':
            $simId = $input['simId'] ?? '';
            echo proxyPost("/simulation/{$simId}/command", [
                'callsign' => $input['callsign'],
                'command' => $input['command'],
                'params' => $input['params'] ?? []
            ]);
            break;
            
        case 'commands':
            $simId = $input['simId'] ?? '';
            echo proxyPost("/simulation/{$simId}/commands", [
                'commands' => $input['commands']
            ]);
            break;
            
        case 'pause':
            $simId = $input['simId'] ?? '';
            echo proxyPost("/simulation/{$simId}/pause", []);
            break;
            
        case 'resume':
            $simId = $input['simId'] ?? '';
            echo proxyPost("/simulation/{$simId}/resume", []);
            break;
            
        case 'delete':
            $simId = $input['simId'] ?? '';
            echo proxyDelete("/simulation/{$simId}");
            break;
            
        case 'remove_aircraft':
            $simId = $input['simId'] ?? '';
            $callsign = $input['callsign'] ?? '';
            echo proxyDelete("/simulation/{$simId}/aircraft/{$callsign}");
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
                'valid_actions' => [
                    'health', 'create', 'list', 'status', 'spawn', 
                    'aircraft', 'tick', 'run', 'command', 'commands',
                    'pause', 'resume', 'delete', 'remove_aircraft'
                ]
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'hint' => 'Is the flight engine running? Start with: cd simulator/engine && npm start'
    ]);
}

/**
 * HTTP GET to Node.js engine
 */
function proxyGet($endpoint) {
    $ch = curl_init(ENGINE_URL . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception("Engine connection failed: {$error}");
    }
    
    if ($httpCode >= 400) {
        http_response_code($httpCode);
    }
    
    return $response;
}

/**
 * HTTP POST to Node.js engine
 */
function proxyPost($endpoint, $data) {
    $ch = curl_init(ENGINE_URL . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception("Engine connection failed: {$error}");
    }
    
    if ($httpCode >= 400) {
        http_response_code($httpCode);
    }
    
    return $response;
}

/**
 * HTTP DELETE to Node.js engine
 */
function proxyDelete($endpoint) {
    $ch = curl_init(ENGINE_URL . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception("Engine connection failed: {$error}");
    }
    
    if ($httpCode >= 400) {
        http_response_code($httpCode);
    }
    
    return $response;
}
