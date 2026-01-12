/**
 * ATFM Flight Engine - HTTP API Server
 * 
 * Exposes simulation endpoints for PHP integration:
 * - POST /simulation/create
 * - POST /simulation/:id/aircraft
 * - POST /simulation/:id/tick
 * - POST /simulation/:id/command
 * - GET  /simulation/:id/aircraft
 * - GET  /simulation/:id/aircraft/:callsign
 * - DELETE /simulation/:id
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
        'http://127.0.0.1'
    ];
    const origin = req.headers.origin;
    if (allowedOrigins.some(allowed => origin?.startsWith(allowed))) {
        res.header('Access-Control-Allow-Origin', origin);
    } else {
        res.header('Access-Control-Allow-Origin', 'https://perti.vatcscc.org');
    }
    res.header('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
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
        version: '0.1.0',
        simulations: simController.listSimulations().length,
        aircraftTypes: simController.aircraftTypes.size
    });
});

// ============================================================================
// Simulation endpoints
// ============================================================================

app.post('/simulation/create', (req, res) => {
    try {
        const simId = simController.createSimulation({
            name: req.body.name,
            startTime: req.body.startTime ? new Date(req.body.startTime) : undefined
        });
        
        const sim = simController.getSimulation(simId);
        
        res.json({
            success: true,
            simulation: {
                id: simId,
                name: sim.name,
                startTime: sim.startTime,
                currentTime: sim.currentTime
            }
        });
    } catch (error) {
        res.status(500).json({ success: false, error: error.message });
    }
});

app.get('/simulation', (req, res) => {
    res.json({
        success: true,
        simulations: simController.listSimulations()
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
            isPaused: sim.isPaused
        }
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
        success: simController.issueCommand(req.params.id, cmd.callsign, cmd.command, cmd.params || {})
    }));
    
    res.json({ success: true, results });
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
    console.log('ATFM Flight Engine API');
    console.log('====================================');
    console.log(`Port: ${PORT}`);
    console.log(`PERTI API: ${PERTI_API_URL}`);
    console.log(`Aircraft types loaded: ${simController.aircraftTypes.size}`);
    console.log('====================================');
    console.log('');
    console.log('Endpoints:');
    console.log('  GET  /health');
    console.log('  POST /simulation/create');
    console.log('  GET  /simulation');
    console.log('  GET  /simulation/:id');
    console.log('  POST /simulation/:id/aircraft');
    console.log('  GET  /simulation/:id/aircraft');
    console.log('  POST /simulation/:id/tick');
    console.log('  POST /simulation/:id/run');
    console.log('  POST /simulation/:id/command');
    console.log('');
});

module.exports = app;
