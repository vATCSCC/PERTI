/**
 * ATFM Flight Engine - HTTP API Server
 *
 * Exposes simulation endpoints for PERTI integration:
 *
 * Simulation Management:
 * - POST /simulation/create
 * - GET  /simulation
 * - GET  /simulation/:id
 * - DELETE /simulation/:id
 *
 * Aircraft:
 * - POST /simulation/:id/aircraft
 * - GET  /simulation/:id/aircraft
 * - GET  /simulation/:id/aircraft/:callsign
 * - DELETE /simulation/:id/aircraft/:callsign
 *
 * Simulation Control:
 * - POST /simulation/:id/tick
 * - POST /simulation/:id/run
 * - POST /simulation/:id/pause
 * - POST /simulation/:id/resume
 * - POST /simulation/:id/command
 *
 * TMI - Ground Stop:
 * - POST /simulation/:id/tmi/groundstop
 * - GET  /simulation/:id/tmi/groundstop
 * - GET  /simulation/:id/tmi/groundstop/:airport
 * - PUT  /simulation/:id/tmi/groundstop/:airport
 * - DELETE /simulation/:id/tmi/groundstop/:airport
 * - GET  /simulation/:id/tmi/held
 */

const express = require('express');
const path = require('path');
const fs = require('fs');

const SimulationController = require('./SimulationController');

// Create Express app
const app = express();
app.use(express.json({ limit: '10mb' }));

// CORS for PERTI domains
app.use((req, res, next) => {
    const allowedOrigins = [
        'https://perti.vatcscc.org',
        'https://vatcscc.azurewebsites.net',
        'http://localhost',
        'http://127.0.0.1',
    ];
    const origin = req.headers.origin;
    if (allowedOrigins.some(allowed => origin?.startsWith(allowed))) {
        res.header('Access-Control-Allow-Origin', origin);
    } else {
        res.header('Access-Control-Allow-Origin', 'https://perti.vatcscc.org');
    }
    res.header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    res.header('Access-Control-Allow-Headers', 'Content-Type');
    if (req.method === 'OPTIONS') {
        return res.sendStatus(200);
    }
    next();
});

// Create simulation controller
const simController = new SimulationController();

// Load aircraft types from config
const aircraftConfigPath = path.join(__dirname, '..', 'config', 'aircraftTypes.json');
if (fs.existsSync(aircraftConfigPath)) {
    const aircraftData = JSON.parse(fs.readFileSync(aircraftConfigPath, 'utf8'));
    simController.loadAircraftTypes(aircraftData);
} else {
    console.warn('Warning: aircraftTypes.json not found, using defaults');
}

// Configure nav data source from environment
const PERTI_API_URL = process.env.PERTI_API_URL || 'https://perti.vatcscc.org/api';
simController.configureNavData({ baseUrl: PERTI_API_URL });

// ============================================================================
// Health check
// ============================================================================

app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        service: 'atfm-flight-engine',
        version: '0.3.0',
        features: ['aircraft', 'ground-stop', 'scenarios', 'traffic-generation', 'historical-replay'],
        simulations: simController.listSimulations().length,
        aircraftTypes: simController.aircraftTypes.size,
    });
});

// ============================================================================
// Simulation endpoints
// ============================================================================

app.post('/simulation/create', (req, res) => {
    try {
        const simId = simController.createSimulation({
            name: req.body.name,
            startTime: req.body.startTime ? new Date(req.body.startTime) : undefined,
        });

        const sim = simController.getSimulation(simId);

        res.json({
            success: true,
            simulation: {
                id: simId,
                name: sim.name,
                startTime: sim.startTime,
                currentTime: sim.currentTime,
            },
        });
    } catch (error) {
        res.status(500).json({ success: false, error: error.message });
    }
});

app.get('/simulation', (req, res) => {
    res.json({
        success: true,
        simulations: simController.listSimulations(),
    });
});

app.get('/simulation/:id', (req, res) => {
    const sim = simController.getSimulation(req.params.id);

    if (!sim) {
        return res.status(404).json({ success: false, error: 'Simulation not found' });
    }

    res.json({
        success: true,
        simulation: {
            id: sim.id,
            name: sim.name,
            startTime: sim.startTime,
            currentTime: sim.currentTime,
            aircraftCount: sim.aircraft.size,
            tickCount: sim.tickCount,
            isPaused: sim.isPaused,
            activeGroundStops: sim.groundStopManager.getActiveGroundStops().length,
            heldFlights: sim.departureQueue.size,
        },
    });
});

app.delete('/simulation/:id', (req, res) => {
    simController.deleteSimulation(req.params.id);
    res.json({ success: true });
});

// ============================================================================
// Aircraft endpoints
// ============================================================================

app.post('/simulation/:id/aircraft', async (req, res) => {
    try {
        const aircraft = await simController.spawnAircraft(req.params.id, req.body);
        res.json({ success: true, aircraft });
    } catch (error) {
        res.status(400).json({ success: false, error: error.message });
    }
});

app.get('/simulation/:id/aircraft', (req, res) => {
    const aircraft = simController.getAllAircraft(req.params.id);
    res.json({ success: true, aircraft });
});

app.get('/simulation/:id/aircraft/:callsign', (req, res) => {
    const aircraft = simController.getAircraft(req.params.id, req.params.callsign);

    if (!aircraft) {
        return res.status(404).json({ success: false, error: 'Aircraft not found' });
    }

    res.json({ success: true, aircraft });
});

app.delete('/simulation/:id/aircraft/:callsign', (req, res) => {
    simController.removeAircraft(req.params.id, req.params.callsign);
    res.json({ success: true });
});

// ============================================================================
// Simulation control endpoints
// ============================================================================

app.post('/simulation/:id/tick', (req, res) => {
    const deltaSeconds = req.body.deltaSeconds || req.body.delta_seconds || 1;
    const result = simController.tick(req.params.id, deltaSeconds);

    if (!result.success) {
        return res.status(400).json(result);
    }

    res.json(result);
});

app.post('/simulation/:id/run', (req, res) => {
    const { durationSeconds, tickInterval } = req.body;

    if (!durationSeconds) {
        return res.status(400).json({ success: false, error: 'durationSeconds required' });
    }

    const result = simController.runFor(req.params.id, durationSeconds, tickInterval || 1);

    if (!result.success) {
        return res.status(400).json(result);
    }

    res.json(result);
});

app.post('/simulation/:id/pause', (req, res) => {
    simController.pause(req.params.id);
    res.json({ success: true });
});

app.post('/simulation/:id/resume', (req, res) => {
    simController.resume(req.params.id);
    res.json({ success: true });
});

// ============================================================================
// Command endpoint
// ============================================================================

app.post('/simulation/:id/command', (req, res) => {
    const { callsign, command, params } = req.body;

    if (!callsign || !command) {
        return res.status(400).json({ success: false, error: 'callsign and command required' });
    }

    const result = simController.issueCommand(req.params.id, callsign, command, params || {});

    if (!result) {
        return res.status(400).json({ success: false, error: 'Command failed' });
    }

    const aircraft = simController.getAircraft(req.params.id, callsign);
    res.json({ success: true, aircraft });
});

app.post('/simulation/:id/commands', (req, res) => {
    const { commands } = req.body;

    if (!Array.isArray(commands)) {
        return res.status(400).json({ success: false, error: 'commands array required' });
    }

    const results = commands.map(cmd => ({
        callsign: cmd.callsign,
        success: simController.issueCommand(req.params.id, cmd.callsign, cmd.command, cmd.params || {}),
    }));

    res.json({ success: true, results });
});

// ============================================================================
// TMI - Ground Stop endpoints
// ============================================================================

/**
 * Issue a Ground Stop
 * POST /simulation/:id/tmi/groundstop
 * Body: {
 *   airport: "KJFK",
 *   endTime: "2026-01-12T15:00:00Z",
 *   reason: "WEATHER",
 *   scope: {
 *     tier: "TIER1",
 *     excludedCenters: ["ZBW"],
 *     excludedAirports: ["KBOS"]
 *   },
 *   exemptions: {
 *     carriers: ["DAL", "UAL"],
 *     aircraftTypes: ["B738"],
 *     originAirports: ["KLGA"]
 *   }
 * }
 */
app.post('/simulation/:id/tmi/groundstop', (req, res) => {
    try {
        const gs = simController.issueGroundStop(req.params.id, req.body);
        res.json({ success: true, groundStop: gs });
    } catch (error) {
        res.status(400).json({ success: false, error: error.message });
    }
});

/**
 * Get all active Ground Stops
 */
app.get('/simulation/:id/tmi/groundstop', (req, res) => {
    try {
        const groundStops = simController.getGroundStops(req.params.id);
        res.json({ success: true, groundStops });
    } catch (error) {
        res.status(400).json({ success: false, error: error.message });
    }
});

/**
 * Get Ground Stop for specific airport
 */
app.get('/simulation/:id/tmi/groundstop/:airport', (req, res) => {
    try {
        const gs = simController.getGroundStop(req.params.id, req.params.airport);
        if (!gs) {
            return res.status(404).json({ success: false, error: `No Ground Stop found for ${req.params.airport}` });
        }
        res.json({ success: true, groundStop: gs });
    } catch (error) {
        res.status(400).json({ success: false, error: error.message });
    }
});

/**
 * Update Ground Stop (extend, change scope, etc.)
 */
app.put('/simulation/:id/tmi/groundstop/:airport', (req, res) => {
    try {
        const gs = simController.updateGroundStop(req.params.id, req.params.airport, req.body);
        res.json({ success: true, groundStop: gs });
    } catch (error) {
        res.status(400).json({ success: false, error: error.message });
    }
});

/**
 * Purge (cancel) Ground Stop
 */
app.delete('/simulation/:id/tmi/groundstop/:airport', (req, res) => {
    try {
        const result = simController.purgeGroundStop(req.params.id, req.params.airport);
        res.json({
            success: true,
            groundStop: result.groundStop,
            releasedFlights: result.releasedFlights.length,
        });
    } catch (error) {
        res.status(400).json({ success: false, error: error.message });
    }
});

/**
 * Get held flights
 * Optional query param: ?airport=KJFK to filter by destination
 */
app.get('/simulation/:id/tmi/held', (req, res) => {
    try {
        const airport = req.query.airport;
        const held = simController.getHeldFlights(req.params.id, airport);
        res.json({ success: true, heldFlights: held, count: held.length });
    } catch (error) {
        res.status(400).json({ success: false, error: error.message });
    }
});

// ============================================================================
// Reference Data endpoints
// ============================================================================

/**
 * Get ARTCC reference data (tiers, regions)
 */
app.get('/reference/artcc', (req, res) => {
    res.json({
        success: true,
        artccs: simController.artccReference.artccs,
        regions: simController.artccReference.regions,
    });
});

/**
 * Get tier centers for an airport
 */
app.get('/reference/artcc/:airport/tiers', (req, res) => {
    const airport = req.params.airport.toUpperCase();
    const artccRef = simController.artccReference;

    // Find ARTCC for airport
    let destArtcc = null;
    for (const [artcc, airports] of Object.entries(artccRef.majorAirportsByArtcc || {})) {
        if (airports.includes(airport)) {
            destArtcc = artcc;
            break;
        }
    }

    if (!destArtcc) {
        return res.status(404).json({ success: false, error: `Airport ${airport} not found in reference data` });
    }

    const artccData = artccRef.artccs[destArtcc];

    res.json({
        success: true,
        airport,
        artcc: destArtcc,
        tiers: {
            internal: [destArtcc],
            tier1: [destArtcc, ...(artccData?.tier1 || [])],
            tier2: [destArtcc, ...(artccData?.tier1 || []), ...(artccData?.tier2 || [])],
        },
    });
});

// ============================================================================
// Traffic / Scenario endpoints
// ============================================================================

const { TrafficGenerator } = require('./traffic');
const trafficGenerator = new TrafficGenerator({ pertiApiUrl: PERTI_API_URL });

/**
 * Get available training scenarios
 */
app.get('/scenarios', async (req, res) => {
    try {
        const scenarios = await trafficGenerator.getScenarios();
        res.json({ success: true, scenarios });
    } catch (error) {
        res.status(500).json({ success: false, error: error.message });
    }
});

/**
 * Load a scenario into a simulation
 * POST /simulation/:id/scenario
 * Body: { scenarioId: 'jfk_afternoon_rush' } or full scenario object
 */
app.post('/simulation/:id/scenario', async (req, res) => {
    try {
        let scenario;

        if (req.body.scenarioId) {
            // Load pre-built scenario
            scenario = await trafficGenerator.loadScenario(req.body.scenarioId);
        } else if (req.body.flights) {
            // Custom scenario with flights array
            scenario = req.body;
        } else {
            return res.status(400).json({ success: false, error: 'scenarioId or flights array required' });
        }

        const result = await simController.loadScenario(req.params.id, scenario);
        res.json({ success: true, ...result });
    } catch (error) {
        res.status(400).json({ success: false, error: error.message });
    }
});

/**
 * Generate traffic from patterns
 * POST /simulation/:id/traffic/generate
 */
app.post('/simulation/:id/traffic/generate', async (req, res) => {
    try {
        const { destination, startHour, endHour, targetCount, demandLevel } = req.body;

        if (!destination) {
            return res.status(400).json({ success: false, error: 'destination required' });
        }

        const flights = await trafficGenerator.generateFromPatterns({
            destination,
            startHour: startHour || 12,
            endHour: endHour || 18,
            targetCount: targetCount || 60,
            demandLevel: demandLevel || 'normal',
        });

        // Load into simulation
        const result = await simController.loadScenario(req.params.id, {
            id: 'custom',
            name: `Custom ${destination}`,
            dest: destination,
            flights,
        });

        res.json({ success: true, ...result });
    } catch (error) {
        res.status(400).json({ success: false, error: error.message });
    }
});

/**
 * Load historical VATSIM traffic
 * POST /simulation/:id/traffic/historical
 */
app.post('/simulation/:id/traffic/historical', async (req, res) => {
    try {
        const { destination, date, startHour, endHour } = req.body;

        if (!destination || !date) {
            return res.status(400).json({ success: false, error: 'destination and date required' });
        }

        const historical = await trafficGenerator.loadHistorical({
            destination,
            date,
            startHour: startHour || 12,
            endHour: endHour || 18,
        });

        // Load into simulation
        const result = await simController.loadScenario(req.params.id, {
            id: 'historical',
            name: `Historical ${destination} ${date}`,
            dest: destination,
            flights: historical.flights,
        });

        res.json({
            success: true,
            source: historical.source,
            date,
            ...result,
        });
    } catch (error) {
        res.status(400).json({ success: false, error: error.message });
    }
});

/**
 * Clear all aircraft (reset for new scenario)
 */
app.delete('/simulation/:id/aircraft', (req, res) => {
    try {
        const result = simController.clearAircraft(req.params.id);
        res.json({ success: true, ...result });
    } catch (error) {
        res.status(400).json({ success: false, error: error.message });
    }
});

/**
 * Get current scenario info
 */
app.get('/simulation/:id/scenario', (req, res) => {
    const scenario = simController.getScenarioInfo(req.params.id);
    res.json({ success: true, scenario });
});

// ============================================================================
// Error handling
// ============================================================================

app.use((err, req, res, next) => {
    console.error('Unhandled error:', err);
    res.status(500).json({ success: false, error: 'Internal server error', message: err.message });
});

// ============================================================================
// Start server
// ============================================================================

const PORT = process.env.PORT || 3001;

app.listen(PORT, () => {
    console.log('====================================');
    console.log('ATFM Flight Engine API v0.3.0');
    console.log('====================================');
    console.log(`Port: ${PORT}`);
    console.log(`PERTI API: ${PERTI_API_URL}`);
    console.log(`Aircraft types loaded: ${simController.aircraftTypes.size}`);
    console.log(`ARTCCs configured: ${Object.keys(simController.artccReference.artccs || {}).length}`);
    console.log('====================================');
    console.log('');
    console.log('Simulation Endpoints:');
    console.log('  POST /simulation/create');
    console.log('  GET  /simulation');
    console.log('  GET  /simulation/:id');
    console.log('  POST /simulation/:id/aircraft');
    console.log('  POST /simulation/:id/tick');
    console.log('');
    console.log('TMI Endpoints:');
    console.log('  POST   /simulation/:id/tmi/groundstop');
    console.log('  GET    /simulation/:id/tmi/groundstop');
    console.log('  PUT    /simulation/:id/tmi/groundstop/:airport');
    console.log('  DELETE /simulation/:id/tmi/groundstop/:airport');
    console.log('  GET    /simulation/:id/tmi/held');
    console.log('');
    console.log('Traffic/Scenario Endpoints:');
    console.log('  GET  /scenarios');
    console.log('  POST /simulation/:id/scenario');
    console.log('  POST /simulation/:id/traffic/generate');
    console.log('  POST /simulation/:id/traffic/historical');
    console.log('  DELETE /simulation/:id/aircraft (clear all)');
    console.log('');
    console.log('Reference Endpoints:');
    console.log('  GET /reference/artcc');
    console.log('  GET /reference/artcc/:airport/tiers');
    console.log('');
});

module.exports = app;
