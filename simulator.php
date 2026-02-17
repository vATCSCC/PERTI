<?php
/**
 * simulator.php
 * 
 * ATFM Training Simulator - Main Page
 * 
 * Features:
 * - MapLibre GL JS for aircraft display
 * - Simulation controls (create, spawn, tick)
 * - Ground Stop management
 * - Real-time aircraft position updates
 * - TMI visualization
 */

/**
 * OPTIMIZED: Public page - no session handler or DB needed
 * Session state is read by nav_public.php for login display
 */
include("load/config.php");
include("load/i18n.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $page_title = "vATCSCC ATFM Simulator"; include("load/header.php"); ?>
    
    <!-- MapLibre GL CSS -->
    <link href="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.css" rel="stylesheet">
    
    <style>
        /* Layout */
        body { overflow: hidden; margin: 0; padding: 0; }
        .cs-footer { display: none !important; }

        :root {
            --navbar-height: 77px;
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
            position: fixed;
            top: var(--navbar-height);
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
        }

        /* Map */
        .sim-map-container {
            flex: 1;
            position: relative;
            min-width: 0;
            overflow: hidden;
        }

        #sim-map {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
        }
        
        /* Control Panel */
        .sim-panel {
            width: 420px;
            min-width: 360px;
            max-width: 420px;
            flex-shrink: 0;
            height: 100%;
            background: #1a1a2e;
            border-left: 1px solid #333;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        @media (max-width: 992px) {
            .sim-panel {
                width: 360px;
                min-width: 320px;
            }
        }

        @media (max-width: 768px) {
            .sim-panel {
                width: 300px;
                min-width: 280px;
            }
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
        
        /* Tabs */
        .sim-tabs {
            display: flex;
            border-bottom: 1px solid #333;
            background: #16213e;
        }
        
        .sim-tab {
            flex: 1;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            color: #888;
            font-size: 0.85rem;
            border: none;
            background: transparent;
            transition: all 0.2s;
        }
        
        .sim-tab:hover {
            background: #252540;
            color: #ccc;
        }
        
        .sim-tab.active {
            background: #252540;
            color: #4dd0e1;
            border-bottom: 2px solid #4dd0e1;
        }
        
        .sim-tab-content {
            display: none;
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            color: #ccc;
        }
        
        .sim-tab-content.active {
            display: block;
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
            cursor: pointer;
        }
        
        .sim-btn-primary { background: #2196f3; border: none; color: #fff; }
        .sim-btn-success { background: #4caf50; border: none; color: #fff; }
        .sim-btn-warning { background: #ff9800; border: none; color: #fff; }
        .sim-btn-danger { background: #f44336; border: none; color: #fff; }
        .sim-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
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
        
        .sim-select {
            background: #1a1a2e;
            border: 1px solid #444;
            color: #fff;
            font-size: 0.85rem;
            padding: 6px 10px;
            border-radius: 4px;
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
        
        .aircraft-item.held {
            background: #f44336;
            color: #fff;
        }
        
        .aircraft-callsign {
            font-weight: bold;
            color: #4dd0e1;
        }
        
        .aircraft-item.held .aircraft-callsign {
            color: #fff;
        }
        
        .aircraft-info {
            color: #888;
        }
        
        .aircraft-item.held .aircraft-info {
            color: #ffcdd2;
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
        
        /* Ground Stop styles */
        .gs-active {
            background: linear-gradient(135deg, #b71c1c 0%, #c62828 100%);
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 15px;
            color: #fff;
        }
        
        .gs-active h6 {
            color: #fff;
            margin-bottom: 8px;
            border-bottom: 1px solid rgba(255,255,255,0.3);
        }
        
        .gs-badge {
            display: inline-block;
            background: #f44336;
            color: #fff;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }
        
        .gs-info {
            font-size: 0.85rem;
            margin-bottom: 4px;
        }
        
        .gs-countdown {
            font-family: monospace;
            font-size: 1.2rem;
            color: #ffcdd2;
        }
        
        /* Scope checkboxes */
        .scope-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4px;
            margin-top: 8px;
        }
        
        .scope-item {
            display: flex;
            align-items: center;
            font-size: 0.75rem;
        }
        
        .scope-item input {
            margin-right: 4px;
        }
        
        .scope-item.tier0 { color: #4dd0e1; }
        .scope-item.tier1 { color: #81c784; }
        .scope-item.tier2 { color: #fff59d; }
        
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
        .sim-log-entry.gs { color: #ff5252; }
    </style>
</head>

<body>
<?php include('load/nav_public.php'); ?>

<div class="simulator-container">
    <!-- Map -->
    <div class="sim-map-container">
        <div id="sim-map"></div>
    </div>
    
    <!-- Control Panel -->
    <div class="sim-panel">
        <div class="sim-panel-header">
            <h5><i class="fas fa-plane"></i> <?= __('simulator.page.title') ?></h5>
        </div>
        
        <!-- Tabs -->
        <div class="sim-tabs">
            <button class="sim-tab active" data-tab="simulation"><?= __('simulator.page.simulationTab') ?></button>
            <button class="sim-tab" data-tab="tmi"><?= __('simulator.page.tmiTab') ?><span class="gs-badge" id="gs-count-badge" style="display:none;">0</span></button>
            <button class="sim-tab" data-tab="traffic"><?= __('simulator.page.trafficTab') ?></button>
        </div>
        
        <!-- Simulation Tab -->
        <div class="sim-tab-content active" id="tab-simulation">
            <!-- Simulation Status -->
            <div class="sim-section">
                <h6><i class="fas fa-info-circle"></i> <?= __('simulator.page.simulationStatus') ?></h6>
                <div class="sim-time" id="sim-time">--:--:--Z</div>
                <div class="sim-status">
                    <span class="sim-status-label"><?= __('simulator.page.statusLabel') ?></span>
                    <span class="sim-status-value" id="sim-status">Not Started</span>
                </div>
                <div class="sim-status">
                    <span class="sim-status-label"><?= __('simulator.page.simulationId') ?></span>
                    <span class="sim-status-value" id="sim-id">-</span>
                </div>
                <div class="sim-status">
                    <span class="sim-status-label"><?= __('simulator.page.aircraftLabel') ?></span>
                    <span class="sim-status-value" id="sim-aircraft-count">0</span>
                </div>
                <div class="sim-status">
                    <span class="sim-status-label">Held (GS):</span>
                    <span class="sim-status-value" id="sim-held-count">0</span>
                </div>
            </div>
            
            <!-- Simulation Controls -->
            <div class="sim-section">
                <h6><i class="fas fa-play-circle"></i> <?= __('simulator.page.simulationControls') ?></h6>
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
                    <label class="text-muted" style="font-size: 0.8rem;"><?= __('simulator.page.speed') ?></label>
                    <select class="sim-select" id="sim-speed" style="width: 100px;">
                        <option value="1">1x</option>
                        <option value="5">5x</option>
                        <option value="15" selected>15x</option>
                        <option value="60">60x</option>
                        <option value="300">5min/s</option>
                    </select>
                </div>
            </div>
            
            <!-- Spawn Aircraft -->
            <div class="sim-section">
                <h6><i class="fas fa-plane-departure"></i> <?= __('simulator.page.spawnAircraft') ?></h6>
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
                <button class="sim-btn sim-btn-success w-100" id="btn-spawn" onclick="spawnAircraft()" disabled>
                    <i class="fas fa-plus"></i> Spawn Aircraft
                </button>
            </div>
            
            <!-- Log -->
            <div class="sim-section">
                <h6><i class="fas fa-terminal"></i> <?= __('simulator.page.log') ?></h6>
                <div class="sim-log" id="sim-log"></div>
            </div>
        </div>
        
        <!-- TMI Tab -->
        <div class="sim-tab-content" id="tab-tmi">
            <!-- Active Ground Stops -->
            <div id="active-gs-container"></div>
            
            <!-- Issue Ground Stop -->
            <div class="sim-section">
                <h6><i class="fas fa-hand-paper"></i> <?= __('simulator.page.issueGroundStop') ?></h6>
                <div class="mb-2">
                    <label class="text-muted" style="font-size: 0.8rem;"><?= __('simulator.page.airportLabel') ?></label>
                    <input type="text" class="sim-input w-100" id="gs-airport" placeholder="KJFK">
                </div>
                <div class="mb-2">
                    <label class="text-muted" style="font-size: 0.8rem;"><?= __('simulator.page.durationLabel') ?></label>
                    <select class="sim-select w-100" id="gs-duration">
                        <option value="60">1 hour</option>
                        <option value="90">1.5 hours</option>
                        <option value="120" selected>2 hours</option>
                        <option value="180">3 hours</option>
                        <option value="240">4 hours</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="text-muted" style="font-size: 0.8rem;"><?= __('simulator.page.reasonLabel') ?></label>
                    <select class="sim-select w-100" id="gs-reason">
                        <option value="WEATHER">Weather</option>
                        <option value="VOLUME">Volume</option>
                        <option value="EQUIPMENT">Equipment</option>
                        <option value="RUNWAY">Runway</option>
                        <option value="SECURITY">Security</option>
                        <option value="OTHER">Other</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="text-muted" style="font-size: 0.8rem;"><?= __('simulator.page.scopeLabel') ?></label>
                    <select class="sim-select w-100" id="gs-scope" onchange="updateScopeDisplay()">
                        <option value="INTERNAL">Internal (Controlling Center Only)</option>
                        <option value="TIER1" selected>Tier 1 (Adjacent Centers)</option>
                        <option value="TIER2">Tier 2 (Extended)</option>
                        <option value="ALL">All CONUS</option>
                    </select>
                </div>
                <div class="mb-2" id="scope-centers-container">
                    <label class="text-muted" style="font-size: 0.8rem;"><?= __('simulator.page.includedCenters') ?></label>
                    <div class="scope-grid" id="scope-centers"></div>
                </div>
                <button class="sim-btn sim-btn-danger w-100" id="btn-issue-gs" onclick="issueGroundStop()" disabled>
                    <i class="fas fa-hand-paper"></i> Issue Ground Stop
                </button>
            </div>
            
            <!-- Held Flights -->
            <div class="sim-section">
                <h6><i class="fas fa-clock"></i> <?= __('simulator.page.heldFlights') ?> (<span id="held-count">0</span>)</h6>
                <div class="aircraft-list" id="held-flights-list">
                    <div class="text-muted text-center" style="font-size: 0.85rem;">No held flights</div>
                </div>
            </div>
        </div>
        
        <!-- Traffic Tab -->
        <div class="sim-tab-content" id="tab-traffic">
            <!-- Load Scenario -->
            <div class="sim-section">
                <h6><i class="fas fa-folder-open"></i> <?= __('simulator.page.loadScenario') ?></h6>
                <div class="mb-2">
                    <select class="sim-select w-100" id="scenario-select" onchange="updateScenarioInfo()">
                        <option value="">-- Select a scenario --</option>
                    </select>
                </div>
                <div id="scenario-info" style="font-size: 0.8rem; color: #888; margin-bottom: 10px;"></div>
                <button class="sim-btn sim-btn-success w-100" id="btn-load-scenario" onclick="loadScenario()" disabled>
                    <i class="fas fa-download"></i> Load Scenario
                </button>
            </div>
            
            <!-- Custom Generation -->
            <div class="sim-section">
                <h6><i class="fas fa-magic"></i> <?= __('simulator.page.generateTraffic') ?></h6>
                <div class="row mb-2">
                    <div class="col-6">
                        <input type="text" class="sim-input w-100" id="gen-dest" placeholder="Destination (KJFK)">
                    </div>
                    <div class="col-6">
                        <input type="number" class="sim-input w-100" id="gen-count" placeholder="Count" value="60">
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-6">
                        <select class="sim-select w-100" id="gen-level">
                            <option value="light">Light</option>
                            <option value="normal" selected>Normal</option>
                            <option value="heavy">Heavy</option>
                            <option value="extreme">Extreme</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <select class="sim-select w-100" id="gen-hours">
                            <option value="4">4 hours</option>
                            <option value="6" selected>6 hours</option>
                            <option value="8">8 hours</option>
                        </select>
                    </div>
                </div>
                <button class="sim-btn sim-btn-primary w-100" id="btn-generate" onclick="generateTraffic()" disabled>
                    <i class="fas fa-random"></i> Generate Traffic
                </button>
            </div>
            
            <!-- Historical Replay -->
            <div class="sim-section">
                <h6><i class="fas fa-history"></i> <?= __('simulator.page.historicalReplay') ?></h6>
                <div class="row mb-2">
                    <div class="col-6">
                        <input type="text" class="sim-input w-100" id="hist-dest" placeholder="Destination">
                    </div>
                    <div class="col-6">
                        <input type="date" class="sim-input w-100" id="hist-date">
                    </div>
                </div>
                <button class="sim-btn sim-btn-primary w-100" id="btn-historical" onclick="loadHistorical()" disabled>
                    <i class="fas fa-play-circle"></i> Load Historical
                </button>
            </div>
            
            <!-- Clear & Aircraft List -->
            <div class="sim-section">
                <h6><i class="fas fa-list"></i> <?= __('simulator.page.aircraftList') ?> (<span id="aircraft-list-count">0</span>)
                    <button class="sim-btn sim-btn-danger float-right" style="padding: 2px 8px; font-size: 0.7rem;" onclick="clearAllAircraft()">
                        <i class="fas fa-trash"></i> Clear
                    </button>
                </h6>
                <div class="aircraft-list" id="aircraft-list">
                    <div class="text-muted text-center" style="font-size: 0.85rem;">No aircraft</div>
                </div>
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
let selectedCallsign = null;
let artccReference = null;
let activeGroundStops = [];

// Engine URL - Azure deployment
const ENGINE_URL = 'https://vatcscc-atfm-engine.azurewebsites.net';

// ============================================================================
// Tab Navigation
// ============================================================================

document.querySelectorAll('.sim-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.sim-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.sim-tab-content').forEach(c => c.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById(`tab-${tab.dataset.tab}`).classList.add('active');
    });
});

// ============================================================================
// Map Initialization
// ============================================================================

function initMap() {
    map = new maplibregl.Map({
        container: 'sim-map',
        style: {
            version: 8,
            glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
            sources: {
                'carto-dark': {
                    type: 'raster',
                    tiles: ['https://basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png'],
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
        center: [-95.7, 39.0],
        zoom: 4
    });

    map.addControl(new maplibregl.NavigationControl(), 'top-left');

    // Handle window resize
    window.addEventListener('resize', () => {
        if (map) map.resize();
    });

    map.on('load', () => {
        log('Map initialized', 'info');

        // Ensure map fills container after load
        setTimeout(() => map.resize(), 100);
        
        // Aircraft source
        map.addSource('aircraft', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        // Active aircraft layer
        map.addLayer({
            id: 'aircraft-layer',
            type: 'circle',
            source: 'aircraft',
            filter: ['!=', ['get', 'status'], 'HELD'],
            paint: {
                'circle-radius': 8,
                'circle-color': '#4dd0e1',
                'circle-stroke-width': 2,
                'circle-stroke-color': '#fff'
            }
        });
        
        // Held aircraft layer (red)
        map.addLayer({
            id: 'aircraft-held-layer',
            type: 'circle',
            source: 'aircraft',
            filter: ['==', ['get', 'status'], 'HELD'],
            paint: {
                'circle-radius': 8,
                'circle-color': '#f44336',
                'circle-stroke-width': 2,
                'circle-stroke-color': '#fff'
            }
        });
        
        // Labels
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

async function apiCall(endpoint, method = 'GET', body = null) {
    try {
        const options = {
            method,
            headers: { 'Content-Type': 'application/json' }
        };
        
        if (body && method !== 'GET') {
            options.body = JSON.stringify(body);
        }
        
        const response = await fetch(`${ENGINE_URL}${endpoint}`, options);
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
    const result = await apiCall('/simulation/create', 'POST', { name: 'Training Session' });
    
    if (result.success) {
        simId = result.simulation.id;
        document.getElementById('sim-id').textContent = simId;
        document.getElementById('sim-status').textContent = 'Ready';
        document.getElementById('btn-spawn').disabled = false;
        document.getElementById('btn-start').disabled = false;
        document.getElementById('btn-issue-gs').disabled = false;
        document.getElementById('btn-load-scenario').disabled = false;
        document.getElementById('btn-generate').disabled = false;
        document.getElementById('btn-historical').disabled = false;
        log(`Simulation created: ${simId}`, 'success');
        updateTimeDisplay(new Date(result.simulation.currentTime));
        
        // Load ARTCC reference
        loadArtccReference();
        
        // Load scenarios
        loadScenarios();
    } else {
        log(`Failed to create simulation: ${result.error}`, 'error');
    }
}

async function loadArtccReference() {
    const result = await apiCall('/reference/artcc');
    if (result.success) {
        artccReference = result;
        log('ARTCC reference data loaded', 'info');
    }
}

async function startSimulation() {
    if (!simId || isRunning) return;
    
    isRunning = true;
    document.getElementById('sim-status').textContent = 'Running';
    document.getElementById('btn-start').disabled = true;
    document.getElementById('btn-pause').disabled = false;
    log('Simulation started', 'info');
    
    const speed = parseInt(document.getElementById('sim-speed').value);
    tickInterval = setInterval(() => tickSimulation(speed), 1000);
}

async function pauseSimulation() {
    if (!simId || !isRunning) return;
    
    isRunning = false;
    clearInterval(tickInterval);
    
    await apiCall(`/simulation/${simId}/pause`, 'POST', {});
    
    document.getElementById('sim-status').textContent = 'Paused';
    document.getElementById('btn-start').disabled = false;
    document.getElementById('btn-pause').disabled = true;
    log('Simulation paused', 'warning');
}

async function tickSimulation(deltaSeconds = 1) {
    if (!simId || !isRunning) return;
    
    const result = await apiCall(`/simulation/${simId}/tick`, 'POST', { deltaSeconds });
    
    if (result.success) {
        document.getElementById('sim-aircraft-count').textContent = result.aircraftCount;
        document.getElementById('sim-held-count').textContent = result.heldFlightCount || 0;
        updateTimeDisplay(new Date(result.currentTime));
        updateAircraftDisplay(result.aircraft);
        updateGroundStopDisplay(result.activeGroundStops || []);
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
    
    const result = await apiCall(`/simulation/${simId}/aircraft`, 'POST', {
        callsign,
        aircraftType,
        origin,
        destination,
        altitude: 35000,
        speed: 280,
        cruiseAltitude: 35000
    });
    
    if (result.success) {
        const ac = result.aircraft;
        if (ac.status === 'HELD') {
            log(`${callsign} HELD at ${origin} - Ground Stop to ${destination}`, 'gs');
        } else {
            log(`Spawned ${callsign} (${aircraftType}) ${origin}→${destination}`, 'success');
        }
        
        document.getElementById('spawn-callsign').value = '';
        
        // Refresh aircraft
        const aircraftList = await apiCall(`/simulation/${simId}/aircraft`);
        if (aircraftList.success) {
            updateAircraftDisplay(aircraftList.aircraft);
        }
    } else {
        log(`Spawn failed: ${result.error}`, 'error');
    }
}

function updateAircraftDisplay(aircraft) {
    const features = aircraft.map(ac => ({
        type: 'Feature',
        geometry: {
            type: 'Point',
            coordinates: [ac.lon || 0, ac.lat || 0]
        },
        properties: {
            callsign: ac.callsign,
            altitude: ac.altitude,
            heading: ac.heading,
            speed: ac.groundSpeed || ac.speed,
            status: ac.status || 'ACTIVE',
            phase: ac.phase
        }
    }));
    
    map.getSource('aircraft')?.setData({
        type: 'FeatureCollection',
        features
    });
    
    // Update aircraft list
    const listEl = document.getElementById('aircraft-list');
    const activeAircraft = aircraft.filter(ac => ac.status !== 'HELD');
    document.getElementById('aircraft-list-count').textContent = activeAircraft.length;
    
    if (activeAircraft.length === 0) {
        listEl.innerHTML = '<div class="text-muted text-center" style="font-size: 0.85rem;">No aircraft</div>';
    } else {
        listEl.innerHTML = activeAircraft.map(ac => `
            <div class="aircraft-item ${ac.callsign === selectedCallsign ? 'selected' : ''}" 
                 onclick="selectAircraft('${ac.callsign}')">
                <span class="aircraft-callsign">${ac.callsign}</span>
                <span class="aircraft-info">
                    ${ac.origin}→${ac.destination} 
                    FL${Math.round((ac.altitude || 0)/100)} 
                    ${ac.groundSpeed || 0}kt
                </span>
            </div>
        `).join('');
    }
    
    // Update held flights list
    const heldAircraft = aircraft.filter(ac => ac.status === 'HELD');
    updateHeldFlightsDisplay(heldAircraft);
}

function updateHeldFlightsDisplay(heldFlights) {
    const listEl = document.getElementById('held-flights-list');
    document.getElementById('held-count').textContent = heldFlights.length;
    
    if (heldFlights.length === 0) {
        listEl.innerHTML = '<div class="text-muted text-center" style="font-size: 0.85rem;">No held flights</div>';
    } else {
        listEl.innerHTML = heldFlights.map(ac => `
            <div class="aircraft-item held">
                <span class="aircraft-callsign">${ac.callsign}</span>
                <span class="aircraft-info">
                    ${ac.origin}→${ac.destination}
                </span>
            </div>
        `).join('');
    }
}

function selectAircraft(callsign) {
    selectedCallsign = callsign;
    document.querySelectorAll('.aircraft-item').forEach(el => {
        const cs = el.querySelector('.aircraft-callsign')?.textContent;
        el.classList.toggle('selected', cs === callsign);
    });
}

// ============================================================================
// Ground Stop Functions
// ============================================================================

async function issueGroundStop() {
    if (!simId) {
        log('Create a simulation first', 'warning');
        return;
    }
    
    const airport = document.getElementById('gs-airport').value.toUpperCase();
    if (!airport) {
        log('Enter an airport for Ground Stop', 'warning');
        return;
    }
    
    const durationMinutes = parseInt(document.getElementById('gs-duration').value);
    const reason = document.getElementById('gs-reason').value;
    const scopeTier = document.getElementById('gs-scope').value;
    
    // Calculate end time
    const simStatus = await apiCall(`/simulation/${simId}`);
    const currentTime = new Date(simStatus.simulation.currentTime);
    const endTime = new Date(currentTime.getTime() + durationMinutes * 60 * 1000);
    
    const result = await apiCall(`/simulation/${simId}/tmi/groundstop`, 'POST', {
        airport,
        endTime: endTime.toISOString(),
        reason,
        scope: {
            tier: scopeTier
        }
    });
    
    if (result.success) {
        log(`Ground Stop issued for ${airport} - ${reason}`, 'gs');
        document.getElementById('gs-airport').value = '';
        updateGroundStopDisplay([result.groundStop]);
        
        // Switch to TMI tab
        document.querySelector('[data-tab="tmi"]').click();
    } else {
        log(`GS failed: ${result.error}`, 'error');
    }
}

async function purgeGroundStop(airport) {
    if (!simId) return;
    
    const result = await apiCall(`/simulation/${simId}/tmi/groundstop/${airport}`, 'DELETE');
    
    if (result.success) {
        log(`Ground Stop for ${airport} PURGED - ${result.releasedFlights} flights released`, 'success');
        
        // Refresh
        const gsResult = await apiCall(`/simulation/${simId}/tmi/groundstop`);
        updateGroundStopDisplay(gsResult.groundStops || []);
    } else {
        log(`Purge failed: ${result.error}`, 'error');
    }
}

function updateGroundStopDisplay(groundStops) {
    activeGroundStops = groundStops;
    const container = document.getElementById('active-gs-container');
    const badge = document.getElementById('gs-count-badge');
    
    if (groundStops.length === 0) {
        container.innerHTML = '';
        badge.style.display = 'none';
        return;
    }
    
    badge.textContent = groundStops.length;
    badge.style.display = 'inline-block';
    
    container.innerHTML = groundStops.map(gs => {
        const endTime = new Date(gs.endTime);
        const countdown = formatCountdown(endTime);
        
        return `
            <div class="gs-active">
                <h6><i class="fas fa-hand-paper"></i> ${gs.airport} Ground Stop</h6>
                <div class="gs-info"><strong>Reason:</strong> ${gs.reason}</div>
                <div class="gs-info"><strong>Scope:</strong> ${gs.scope?.tier || 'TIER1'} (${gs.scope?.includedCenters?.length || 0} centers)</div>
                <div class="gs-info"><strong>Ends:</strong> ${endTime.toISOString().substr(11,8)}Z</div>
                <div class="gs-info"><strong>Held:</strong> ${gs.heldFlightCount || 0} flights</div>
                <div class="gs-countdown">${countdown}</div>
                <button class="sim-btn sim-btn-warning mt-2" onclick="purgeGroundStop('${gs.airport}')">
                    <i class="fas fa-times"></i> Purge
                </button>
            </div>
        `;
    }).join('');
}

function formatCountdown(endTime) {
    const now = new Date();
    const diff = endTime - now;
    
    if (diff <= 0) return 'EXPIRED';
    
    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
    
    return `${hours.toString().padStart(2,'0')}:${minutes.toString().padStart(2,'0')}:${seconds.toString().padStart(2,'0')} remaining`;
}

function updateScopeDisplay() {
    const airport = document.getElementById('gs-airport').value.toUpperCase();
    const tier = document.getElementById('gs-scope').value;
    
    // For now, show placeholder - in future, query /reference/artcc/:airport/tiers
    const container = document.getElementById('scope-centers');
    
    if (!artccReference) {
        container.innerHTML = '<span class="text-muted">Load simulation first</span>';
        return;
    }
    
    // Show all CONUS centers with tier coloring
    const allCenters = artccReference.regions?.CONUS || Object.keys(artccReference.artccs);
    
    container.innerHTML = allCenters.map(c => {
        const tierClass = 'tier1'; // Simplified for now
        return `
            <label class="scope-item ${tierClass}">
                <input type="checkbox" checked> ${c}
            </label>
        `;
    }).join('');
}

// ============================================================================
// Scenario / Traffic Functions
// ============================================================================

let scenarios = [];

async function loadScenarios() {
    const result = await apiCall('/scenarios');
    if (result.success && result.scenarios) {
        scenarios = result.scenarios;
        const select = document.getElementById('scenario-select');
        select.innerHTML = '<option value="">-- Select a scenario --</option>' +
            scenarios.filter(s => s.id !== 'custom').map(s => 
                `<option value="${s.id}">${s.name}</option>`
            ).join('');
        log(`Loaded ${scenarios.length} training scenarios`, 'info');
    }
}

function updateScenarioInfo() {
    const scenarioId = document.getElementById('scenario-select').value;
    const infoEl = document.getElementById('scenario-info');
    
    if (!scenarioId) {
        infoEl.innerHTML = '';
        return;
    }
    
    const scenario = scenarios.find(s => s.id === scenarioId);
    if (scenario) {
        infoEl.innerHTML = `
            <div><strong>${scenario.dest}</strong> - ${scenario.description}</div>
            <div>Flights: ~${scenario.targetCount} | Time: ${scenario.startHour}:00-${scenario.endHour}:00Z</div>
            <div>AAR: ${scenario.suggestedAAR || 'N/A'} (reduced: ${scenario.reducedAAR || 'N/A'})</div>
            <div style="color: #4dd0e1;">Focus: ${scenario.trainingFocus || 'General'}</div>
        `;
    }
}

async function loadScenario() {
    if (!simId) {
        log('Create a simulation first', 'warning');
        return;
    }
    
    const scenarioId = document.getElementById('scenario-select').value;
    if (!scenarioId) {
        log('Select a scenario first', 'warning');
        return;
    }
    
    log(`Loading scenario: ${scenarioId}...`, 'info');
    document.getElementById('btn-load-scenario').disabled = true;
    
    const result = await apiCall(`/simulation/${simId}/scenario`, 'POST', { scenarioId });
    
    document.getElementById('btn-load-scenario').disabled = false;
    
    if (result.success) {
        log(`Scenario loaded: ${result.spawned} spawned, ${result.held} held, ${result.failed} failed`, 'success');
        
        // Refresh aircraft
        const aircraftList = await apiCall(`/simulation/${simId}/aircraft`);
        if (aircraftList.success) {
            updateAircraftDisplay(aircraftList.aircraft);
        }
        
        // Auto-fill GS airport with scenario destination
        if (result.scenario?.dest) {
            document.getElementById('gs-airport').value = result.scenario.dest;
        }
    } else {
        log(`Failed to load scenario: ${result.error}`, 'error');
    }
}

async function generateTraffic() {
    if (!simId) {
        log('Create a simulation first', 'warning');
        return;
    }
    
    const destination = document.getElementById('gen-dest').value.toUpperCase();
    if (!destination) {
        log('Enter a destination airport', 'warning');
        return;
    }
    
    const targetCount = parseInt(document.getElementById('gen-count').value) || 60;
    const demandLevel = document.getElementById('gen-level').value;
    const hours = parseInt(document.getElementById('gen-hours').value) || 6;
    
    log(`Generating ${targetCount} flights to ${destination}...`, 'info');
    document.getElementById('btn-generate').disabled = true;
    
    // Get current sim time for start hour
    const simStatus = await apiCall(`/simulation/${simId}`);
    const currentTime = new Date(simStatus.simulation.currentTime);
    const startHour = currentTime.getUTCHours();
    const endHour = (startHour + hours) % 24;
    
    const result = await apiCall(`/simulation/${simId}/traffic/generate`, 'POST', {
        destination,
        startHour,
        endHour,
        targetCount,
        demandLevel
    });
    
    document.getElementById('btn-generate').disabled = false;
    
    if (result.success) {
        log(`Generated traffic: ${result.spawned} spawned, ${result.held} held`, 'success');
        
        // Refresh aircraft
        const aircraftList = await apiCall(`/simulation/${simId}/aircraft`);
        if (aircraftList.success) {
            updateAircraftDisplay(aircraftList.aircraft);
        }
        
        // Auto-fill GS airport
        document.getElementById('gs-airport').value = destination;
    } else {
        log(`Generation failed: ${result.error}`, 'error');
    }
}

async function loadHistorical() {
    if (!simId) {
        log('Create a simulation first', 'warning');
        return;
    }
    
    const destination = document.getElementById('hist-dest').value.toUpperCase();
    const date = document.getElementById('hist-date').value;
    
    if (!destination || !date) {
        log('Enter destination and date', 'warning');
        return;
    }
    
    log(`Loading historical traffic for ${destination} on ${date}...`, 'info');
    document.getElementById('btn-historical').disabled = true;
    
    const result = await apiCall(`/simulation/${simId}/traffic/historical`, 'POST', {
        destination,
        date,
        startHour: 12,
        endHour: 20
    });
    
    document.getElementById('btn-historical').disabled = false;
    
    if (result.success) {
        log(`Historical loaded (${result.source}): ${result.spawned} spawned, ${result.held} held`, 'success');
        
        // Refresh aircraft
        const aircraftList = await apiCall(`/simulation/${simId}/aircraft`);
        if (aircraftList.success) {
            updateAircraftDisplay(aircraftList.aircraft);
        }
        
        // Auto-fill GS airport
        document.getElementById('gs-airport').value = destination;
    } else {
        log(`Historical load failed: ${result.error}`, 'error');
    }
}

async function clearAllAircraft() {
    if (!simId) return;
    
    if (!confirm('Clear all aircraft from simulation?')) return;
    
    const result = await apiCall(`/simulation/${simId}/aircraft`, 'DELETE');
    
    if (result.success) {
        log(`Cleared ${result.cleared} aircraft`, 'warning');
        updateAircraftDisplay([]);
    } else {
        log(`Clear failed: ${result.error}`, 'error');
    }
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
    
    while (logEl.children.length > 50) {
        logEl.removeChild(logEl.firstChild);
    }
}

// ============================================================================
// Initialize
// ============================================================================

document.addEventListener('DOMContentLoaded', async () => {
    initMap();
    log('ATFM Simulator initialized', 'info');

    // Ensure map fills container after DOM fully renders
    setTimeout(() => {
        if (map) map.resize();
    }, 250);

    const health = await apiCall('/health');
    if (health.status === 'ok') {
        log(`Flight engine v${health.version} connected`, 'success');
        log(`Features: ${health.features?.join(', ') || 'aircraft'}`, 'info');
        log('Click "New Simulation" to begin', 'info');
    } else {
        log('Flight engine not running!', 'error');
        document.getElementById('sim-status').textContent = 'Engine Offline';
    }
});
</script>

</body>
</html>
