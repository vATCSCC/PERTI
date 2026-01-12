<?php
/**
 * simulator.php
 * 
 * ATFM Training Simulator - Main Page
 * 
 * Features:
 * - MapLibre GL JS for aircraft display
 * - Simulation controls (create, spawn, tick, commands)
 * - Real-time aircraft position updates
 * - TMI management (future: GS, GDP, AFP, reroutes)
 */

// Session handling
include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) { 
    session_start(); 
    ob_start(); 
}
include("load/config.php");
include("load/connect.php");

$user_name = trim(($_SESSION['VATSIM_FIRST_NAME'] ?? '') . ' ' . ($_SESSION['VATSIM_LAST_NAME'] ?? '')) ?: 'Guest';
$user_cid = $_SESSION['VATSIM_CID'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $page_title = "vATCSCC ATFM Simulator"; include("load/header.php"); ?>
    
    <!-- MapLibre GL CSS -->
    <link href="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.css" rel="stylesheet">
    
    <style>
        /* Layout */
        body { overflow: hidden; }
        .cs-footer { display: none !important; }
        
        :root {
            --navbar-height: 60px;
        }
        
        .cs-header.navbar-floating {
            background: rgba(26, 26, 46, 0.98) !important;
            border-bottom: 1px solid #333;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
        }
        
        .simulator-container {
            display: flex;
            height: calc(100vh - var(--navbar-height));
            width: 100%;
            margin-top: var(--navbar-height);
        }
        
        /* Map */
        .sim-map-container {
            flex: 1;
            position: relative;
            min-width: 0;
        }
        
        #sim-map {
            width: 100%;
            height: 100%;
        }
        
        /* Control Panel */
        .sim-panel {
            width: 380px;
            min-width: 380px;
            height: 100%;
            background: #1a1a2e;
            border-left: 1px solid #333;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .sim-panel-header {
            background: linear-gradient(135deg, #16213e 0%, #1a1a2e 100%);
            padding: 12px 15px;
            border-bottom: 1px solid #333;
            color: #fff;
        }
        
        .sim-panel-header h5 {
            margin: 0;
            font-size: 1rem;
        }
        
        .sim-panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            color: #ccc;
        }
        
        .sim-section {
            background: #252540;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 15px;
        }
        
        .sim-section h6 {
            color: #fff;
            margin-bottom: 10px;
            font-size: 0.9rem;
            border-bottom: 1px solid #444;
            padding-bottom: 5px;
        }
        
        .sim-status {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.85rem;
        }
        
        .sim-status-label {
            color: #888;
        }
        
        .sim-status-value {
            color: #4dd0e1;
            font-family: monospace;
        }
        
        /* Controls */
        .sim-btn {
            padding: 6px 12px;
            font-size: 0.85rem;
            border-radius: 4px;
            margin: 2px;
        }
        
        .sim-btn-primary { background: #2196f3; border: none; color: #fff; }
        .sim-btn-success { background: #4caf50; border: none; color: #fff; }
        .sim-btn-warning { background: #ff9800; border: none; color: #fff; }
        .sim-btn-danger { background: #f44336; border: none; color: #fff; }
        
        .sim-input {
            background: #1a1a2e;
            border: 1px solid #444;
            color: #fff;
            font-size: 0.85rem;
            padding: 6px 10px;
            border-radius: 4px;
        }
        
        .sim-input:focus {
            border-color: #4dd0e1;
            outline: none;
        }
        
        /* Aircraft List */
        .aircraft-list {
            max-height: 200px;
            overflow-y: auto;
        }
        
        .aircraft-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 8px;
            background: #1a1a2e;
            border-radius: 4px;
            margin-bottom: 4px;
            font-size: 0.8rem;
            cursor: pointer;
        }
        
        .aircraft-item:hover {
            background: #2a2a4e;
        }
        
        .aircraft-item.selected {
            background: #2196f3;
            color: #fff;
        }
        
        .aircraft-callsign {
            font-weight: bold;
            color: #4dd0e1;
        }
        
        .aircraft-info {
            color: #888;
        }
        
        /* Time display */
        .sim-time {
            font-size: 1.5rem;
            font-family: monospace;
            color: #4dd0e1;
            text-align: center;
            padding: 10px;
            background: #1a1a2e;
            border-radius: 4px;
        }
        
        /* Map markers */
        .aircraft-marker {
            width: 20px;
            height: 20px;
            background: #4dd0e1;
            border: 2px solid #fff;
            border-radius: 50%;
            cursor: pointer;
            position: relative;
        }
        
        .aircraft-marker::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-bottom: 12px solid #4dd0e1;
            transform: translate(-50%, -80%);
        }
        
        /* Log */
        .sim-log {
            font-family: monospace;
            font-size: 0.75rem;
            background: #1a1a2e;
            padding: 8px;
            border-radius: 4px;
            max-height: 150px;
            overflow-y: auto;
            color: #888;
        }
        
        .sim-log-entry {
            margin-bottom: 2px;
        }
        
        .sim-log-entry.info { color: #4dd0e1; }
        .sim-log-entry.success { color: #4caf50; }
        .sim-log-entry.warning { color: #ff9800; }
        .sim-log-entry.error { color: #f44336; }
    </style>
</head>

<body>
<?php include('load/nav.php'); ?>

<div class="simulator-container">
    <!-- Map -->
    <div class="sim-map-container">
        <div id="sim-map"></div>
    </div>
    
    <!-- Control Panel -->
    <div class="sim-panel">
        <div class="sim-panel-header">
            <h5><i class="fas fa-plane"></i> ATFM Training Simulator</h5>
        </div>
        
        <div class="sim-panel-body">
            <!-- Simulation Status -->
            <div class="sim-section">
                <h6><i class="fas fa-info-circle"></i> Simulation Status</h6>
                <div class="sim-time" id="sim-time">--:--:--Z</div>
                <div class="sim-status">
                    <span class="sim-status-label">Status:</span>
                    <span class="sim-status-value" id="sim-status">Not Started</span>
                </div>
                <div class="sim-status">
                    <span class="sim-status-label">Simulation ID:</span>
                    <span class="sim-status-value" id="sim-id">-</span>
                </div>
                <div class="sim-status">
                    <span class="sim-status-label">Aircraft:</span>
                    <span class="sim-status-value" id="sim-aircraft-count">0</span>
                </div>
                <div class="sim-status">
                    <span class="sim-status-label">Ticks:</span>
                    <span class="sim-status-value" id="sim-tick-count">0</span>
                </div>
            </div>
            
            <!-- Simulation Controls -->
            <div class="sim-section">
                <h6><i class="fas fa-play-circle"></i> Simulation Controls</h6>
                <div class="mb-2">
                    <button class="sim-btn sim-btn-success" id="btn-create" onclick="createSimulation()">
                        <i class="fas fa-plus"></i> New Simulation
                    </button>
                    <button class="sim-btn sim-btn-primary" id="btn-start" onclick="startSimulation()" disabled>
                        <i class="fas fa-play"></i> Start
                    </button>
                    <button class="sim-btn sim-btn-warning" id="btn-pause" onclick="pauseSimulation()" disabled>
                        <i class="fas fa-pause"></i> Pause
                    </button>
                </div>
                <div class="mb-2">
                    <label class="text-muted" style="font-size: 0.8rem;">Speed:</label>
                    <select class="sim-input" id="sim-speed" style="width: 100px;">
                        <option value="1">1x</option>
                        <option value="5">5x</option>
                        <option value="15" selected>15x</option>
                        <option value="60">60x</option>
                    </select>
                </div>
            </div>
            
            <!-- Spawn Aircraft -->
            <div class="sim-section">
                <h6><i class="fas fa-plane-departure"></i> Spawn Aircraft</h6>
                <div class="row mb-2">
                    <div class="col-6">
                        <input type="text" class="sim-input w-100" id="spawn-callsign" placeholder="Callsign (DAL123)">
                    </div>
                    <div class="col-6">
                        <input type="text" class="sim-input w-100" id="spawn-type" placeholder="Type (B738)" value="B738">
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-6">
                        <input type="text" class="sim-input w-100" id="spawn-origin" placeholder="Origin (KATL)">
                    </div>
                    <div class="col-6">
                        <input type="text" class="sim-input w-100" id="spawn-dest" placeholder="Dest (KJFK)">
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-6">
                        <input type="number" class="sim-input w-100" id="spawn-altitude" placeholder="Alt (35000)" value="35000">
                    </div>
                    <div class="col-6">
                        <input type="number" class="sim-input w-100" id="spawn-speed" placeholder="Speed (280)" value="280">
                    </div>
                </div>
                <button class="sim-btn sim-btn-success w-100" id="btn-spawn" onclick="spawnAircraft()" disabled>
                    <i class="fas fa-plus"></i> Spawn Aircraft
                </button>
            </div>
            
            <!-- Aircraft List -->
            <div class="sim-section">
                <h6><i class="fas fa-list"></i> Aircraft (<span id="aircraft-list-count">0</span>)</h6>
                <div class="aircraft-list" id="aircraft-list">
                    <div class="text-muted text-center" style="font-size: 0.85rem;">No aircraft</div>
                </div>
            </div>
            
            <!-- Log -->
            <div class="sim-section">
                <h6><i class="fas fa-terminal"></i> Log</h6>
                <div class="sim-log" id="sim-log"></div>
            </div>
        </div>
    </div>
</div>

<?php include('load/footer.php'); ?>

<!-- MapLibre GL JS -->
<script src="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.js"></script>

<script>
// ============================================================================
// State
// ============================================================================

let map = null;
let simId = null;
let isRunning = false;
let tickInterval = null;
let aircraftMarkers = new Map();
let selectedCallsign = null;

// ============================================================================
// Map Initialization
// ============================================================================

function initMap() {
    map = new maplibregl.Map({
        container: 'sim-map',
        style: {
            version: 8,
            sources: {
                'carto-dark': {
                    type: 'raster',
                    tiles: [
                        'https://basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png'
                    ],
                    tileSize: 256,
                    attribution: '&copy; OpenStreetMap, &copy; CARTO'
                }
            },
            layers: [{
                id: 'carto-dark-layer',
                type: 'raster',
                source: 'carto-dark'
            }]
        },
        center: [-95.7, 39.0],  // Center of CONUS
        zoom: 4
    });
    
    map.addControl(new maplibregl.NavigationControl(), 'top-left');
    
    map.on('load', () => {
        log('Map initialized', 'info');
        
        // Add aircraft source and layer
        map.addSource('aircraft', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        map.addLayer({
            id: 'aircraft-layer',
            type: 'circle',
            source: 'aircraft',
            paint: {
                'circle-radius': 8,
                'circle-color': '#4dd0e1',
                'circle-stroke-width': 2,
                'circle-stroke-color': '#fff'
            }
        });
        
        // Add labels
        map.addLayer({
            id: 'aircraft-labels',
            type: 'symbol',
            source: 'aircraft',
            layout: {
                'text-field': ['get', 'callsign'],
                'text-size': 11,
                'text-offset': [0, 1.5],
                'text-anchor': 'top'
            },
            paint: {
                'text-color': '#fff',
                'text-halo-color': '#000',
                'text-halo-width': 1
            }
        });
    });
}

// ============================================================================
// API Functions
// ============================================================================

async function apiCall(data) {
    try {
        const response = await fetch('api/simulator/engine.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        return await response.json();
    } catch (error) {
        log(`API Error: ${error.message}`, 'error');
        return { success: false, error: error.message };
    }
}

// ============================================================================
// Simulation Controls
// ============================================================================

async function createSimulation() {
    const result = await apiCall({ action: 'create', name: 'Training Session' });
    
    if (result.success) {
        simId = result.simulation.id;
        document.getElementById('sim-id').textContent = simId;
        document.getElementById('sim-status').textContent = 'Ready';
        document.getElementById('btn-spawn').disabled = false;
        document.getElementById('btn-start').disabled = false;
        log(`Simulation created: ${simId}`, 'success');
        updateTimeDisplay(new Date(result.simulation.currentTime));
    } else {
        log(`Failed to create simulation: ${result.error}`, 'error');
    }
}

async function startSimulation() {
    if (!simId || isRunning) return;
    
    isRunning = true;
    document.getElementById('sim-status').textContent = 'Running';
    document.getElementById('btn-start').disabled = true;
    document.getElementById('btn-pause').disabled = false;
    log('Simulation started', 'info');
    
    // Start tick loop
    const speed = parseInt(document.getElementById('sim-speed').value);
    tickInterval = setInterval(() => tickSimulation(speed), 1000);
}

async function pauseSimulation() {
    if (!simId || !isRunning) return;
    
    isRunning = false;
    clearInterval(tickInterval);
    
    await apiCall({ action: 'pause', simId });
    
    document.getElementById('sim-status').textContent = 'Paused';
    document.getElementById('btn-start').disabled = false;
    document.getElementById('btn-pause').disabled = true;
    log('Simulation paused', 'warning');
}

async function tickSimulation(deltaSeconds = 1) {
    if (!simId || !isRunning) return;
    
    const result = await apiCall({ action: 'tick', simId, deltaSeconds });
    
    if (result.success) {
        document.getElementById('sim-tick-count').textContent = result.tickCount;
        document.getElementById('sim-aircraft-count').textContent = result.aircraftCount;
        updateTimeDisplay(new Date(result.currentTime));
        updateAircraftDisplay(result.aircraft);
    }
}

// ============================================================================
// Aircraft Functions
// ============================================================================

async function spawnAircraft() {
    if (!simId) {
        log('Create a simulation first', 'warning');
        return;
    }
    
    const callsign = document.getElementById('spawn-callsign').value.toUpperCase() || 
                     'TEST' + Math.floor(Math.random() * 900 + 100);
    const aircraftType = document.getElementById('spawn-type').value.toUpperCase() || 'B738';
    const origin = document.getElementById('spawn-origin').value.toUpperCase() || 'KATL';
    const destination = document.getElementById('spawn-dest').value.toUpperCase() || 'KJFK';
    const altitude = parseInt(document.getElementById('spawn-altitude').value) || 35000;
    const speed = parseInt(document.getElementById('spawn-speed').value) || 280;
    
    const result = await apiCall({
        action: 'spawn',
        simId,
        callsign,
        aircraftType,
        origin,
        destination,
        altitude,
        speed,
        cruiseAltitude: altitude
    });
    
    if (result.success) {
        log(`Spawned ${callsign} (${aircraftType}) ${origin}→${destination}`, 'success');
        
        // Clear callsign for next spawn
        document.getElementById('spawn-callsign').value = '';
        
        // Update display
        const aircraftList = await apiCall({ action: 'aircraft', simId });
        if (aircraftList.success) {
            updateAircraftDisplay(aircraftList.aircraft);
        }
        
        // Center map on new aircraft
        if (result.aircraft) {
            map.flyTo({
                center: [result.aircraft.lon, result.aircraft.lat],
                zoom: 6
            });
        }
    } else {
        log(`Spawn failed: ${result.error}`, 'error');
    }
}

function updateAircraftDisplay(aircraft) {
    // Update map
    const features = aircraft.map(ac => ({
        type: 'Feature',
        geometry: {
            type: 'Point',
            coordinates: [ac.lon, ac.lat]
        },
        properties: {
            callsign: ac.callsign,
            altitude: ac.altitude,
            heading: ac.heading,
            speed: ac.speed
        }
    }));
    
    map.getSource('aircraft').setData({
        type: 'FeatureCollection',
        features
    });
    
    // Update list
    const listEl = document.getElementById('aircraft-list');
    document.getElementById('aircraft-list-count').textContent = aircraft.length;
    
    if (aircraft.length === 0) {
        listEl.innerHTML = '<div class="text-muted text-center" style="font-size: 0.85rem;">No aircraft</div>';
        return;
    }
    
    listEl.innerHTML = aircraft.map(ac => `
        <div class="aircraft-item ${ac.callsign === selectedCallsign ? 'selected' : ''}" 
             onclick="selectAircraft('${ac.callsign}')">
            <span class="aircraft-callsign">${ac.callsign}</span>
            <span class="aircraft-info">
                ${ac.origin}→${ac.destination} 
                FL${Math.round(ac.altitude/100)} 
                ${ac.groundSpeed}kt
            </span>
        </div>
    `).join('');
}

function selectAircraft(callsign) {
    selectedCallsign = callsign;
    document.querySelectorAll('.aircraft-item').forEach(el => {
        el.classList.toggle('selected', el.querySelector('.aircraft-callsign').textContent === callsign);
    });
}

// ============================================================================
// Utilities
// ============================================================================

function updateTimeDisplay(date) {
    const h = date.getUTCHours().toString().padStart(2, '0');
    const m = date.getUTCMinutes().toString().padStart(2, '0');
    const s = date.getUTCSeconds().toString().padStart(2, '0');
    document.getElementById('sim-time').textContent = `${h}:${m}:${s}Z`;
}

function log(message, type = 'info') {
    const logEl = document.getElementById('sim-log');
    const entry = document.createElement('div');
    entry.className = `sim-log-entry ${type}`;
    entry.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
    logEl.appendChild(entry);
    logEl.scrollTop = logEl.scrollHeight;
    
    // Keep last 50 entries
    while (logEl.children.length > 50) {
        logEl.removeChild(logEl.firstChild);
    }
}

// ============================================================================
// Initialize
// ============================================================================

document.addEventListener('DOMContentLoaded', () => {
    initMap();
    log('ATFM Simulator initialized', 'info');
    log('Click "New Simulation" to begin', 'info');
});
</script>

</body>
</html>
