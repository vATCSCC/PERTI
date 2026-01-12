/**
 * SimulationController - Manages simulation instances
 * 
 * Each simulation contains:
 * - Multiple aircraft
 * - Simulation time tracking
 * - Tick-based advancement
 */

const AircraftModel = require('./aircraft/AircraftModel');
const NavDataClient = require('./navigation/NavDataClient');

class SimulationController {
    constructor() {
        this.simulations = new Map();
        this.aircraftTypes = new Map();
        this.navData = new NavDataClient();
        this.nextSimId = 1;
    }

    loadAircraftTypes(aircraftData) {
        const aircraft = aircraftData.aircraft || aircraftData;
        
        for (const ac of aircraft) {
            this.aircraftTypes.set(ac.icao.toUpperCase(), {
                name: ac.name,
                icao: ac.icao,
                engines: ac.engines?.number || 2,
                engineType: ac.engines?.type || 'J',
                weightClass: ac.weightClass || 'L',
                ceiling: ac.ceiling || 41000,
                climbRate: ac.rate?.climb || 2500,
                descentRate: ac.rate?.descent || 2000,
                accelRate: ac.rate?.accelerate || 5,
                decelRate: ac.rate?.decelerate || 4,
                minSpeed: ac.speed?.min || 110,
                maxSpeed: ac.speed?.max || 500,
                cruiseSpeed: ac.speed?.cruise || 450,
                cruiseMach: ac.speed?.cruiseM || null,
                landingSpeed: ac.speed?.landing || 130
            });
        }
        
        console.log(`SimulationController: Loaded ${this.aircraftTypes.size} aircraft types`);
    }

    configureNavData(options) {
        this.navData = new NavDataClient(options);
    }

    createSimulation(options = {}) {
        const simId = `sim_${this.nextSimId++}`;
        
        this.simulations.set(simId, {
            id: simId,
            name: options.name || simId,
            startTime: options.startTime || new Date(),
            currentTime: options.startTime || new Date(),
            aircraft: new Map(),
            tickCount: 0,
            isPaused: false,
            events: []
        });
        
        console.log(`SimulationController: Created simulation ${simId}`);
        return simId;
    }

    getSimulation(simId) {
        return this.simulations.get(simId) || null;
    }

    listSimulations() {
        return Array.from(this.simulations.values()).map(sim => ({
            id: sim.id,
            name: sim.name,
            startTime: sim.startTime,
            currentTime: sim.currentTime,
            aircraftCount: sim.aircraft.size,
            tickCount: sim.tickCount,
            isPaused: sim.isPaused
        }));
    }

    async spawnAircraft(simId, params) {
        const sim = this.simulations.get(simId);
        if (!sim) {
            throw new Error(`Simulation ${simId} not found`);
        }
        
        if (sim.aircraft.has(params.callsign)) {
            throw new Error(`Aircraft ${params.callsign} already exists in simulation`);
        }
        
        const perfData = this.aircraftTypes.get(params.aircraftType?.toUpperCase());
        const performance = perfData ? {
            ceiling: perfData.ceiling,
            climbRate: perfData.climbRate,
            descentRate: perfData.descentRate,
            accelRate: perfData.accelRate,
            decelRate: perfData.decelRate,
            minSpeed: perfData.minSpeed,
            maxSpeed: perfData.maxSpeed,
            cruiseSpeed: perfData.cruiseSpeed,
            cruiseMach: perfData.cruiseMach
        } : undefined;
        
        const originApt = await this.navData.getAirport(params.origin);
        if (!originApt) {
            throw new Error(`Origin airport ${params.origin} not found`);
        }
        
        let flightPlan = [];
        if (params.route) {
            flightPlan = await this.navData.resolveRoute(
                params.origin,
                params.destination,
                params.route
            );
        } else {
            const destApt = await this.navData.getAirport(params.destination);
            if (destApt) {
                flightPlan = [
                    { name: params.origin, lat: originApt.lat, lon: originApt.lon, type: 'AIRPORT' },
                    { name: params.destination, lat: destApt.lat, lon: destApt.lon, type: 'AIRPORT' }
                ];
            }
        }
        
        let initialHeading = params.heading;
        if (initialHeading === undefined && flightPlan.length >= 2) {
            const { bearingTo } = require('./math/flightMath');
            initialHeading = bearingTo(
                flightPlan[0].lat, flightPlan[0].lon,
                flightPlan[1].lat, flightPlan[1].lon
            );
        }
        
        const aircraft = new AircraftModel({
            callsign: params.callsign,
            aircraftType: params.aircraftType,
            origin: params.origin,
            destination: params.destination,
            lat: params.lat ?? originApt.lat,
            lon: params.lon ?? originApt.lon,
            altitude: params.altitude ?? originApt.elevation_ft ?? 0,
            heading: initialHeading ?? 0,
            speed: params.speed ?? 0,
            performance,
            flightPlan
        });
        
        if (params.altitude && params.altitude > 1000) {
            aircraft.targetAltitude = params.cruiseAltitude || params.altitude;
            aircraft.targetSpeed = performance?.cruiseSpeed || 280;
        }
        
        sim.aircraft.set(params.callsign, aircraft);
        this._logSimEvent(sim, 'SPAWN', `${params.callsign} spawned at ${params.origin}`);
        
        return aircraft.toStateObject();
    }

    getAircraft(simId, callsign) {
        const sim = this.simulations.get(simId);
        if (!sim) return null;
        
        const aircraft = sim.aircraft.get(callsign);
        return aircraft ? aircraft.toStateObject() : null;
    }

    getAllAircraft(simId) {
        const sim = this.simulations.get(simId);
        if (!sim) return [];
        
        return Array.from(sim.aircraft.values())
            .filter(ac => ac.isActive)
            .map(ac => ac.toStateObject());
    }

    issueCommand(simId, callsign, command, params = {}) {
        const sim = this.simulations.get(simId);
        if (!sim) return false;
        
        const aircraft = sim.aircraft.get(callsign);
        if (!aircraft || !aircraft.isActive) return false;
        
        switch (command.toUpperCase()) {
            case 'FH':
            case 'FLY_HEADING':
                aircraft.flyHeading(params.heading);
                break;
            case 'TL':
            case 'TURN_LEFT':
                aircraft.turnLeftHeading(params.heading);
                break;
            case 'TR':
            case 'TURN_RIGHT':
                aircraft.turnRightHeading(params.heading);
                break;
            case 'CM':
            case 'CLIMB':
                aircraft.climbMaintain(params.altitude);
                break;
            case 'DM':
            case 'DESCEND':
                aircraft.descendMaintain(params.altitude);
                break;
            case 'SP':
            case 'SPEED':
                aircraft.maintainSpeed(params.speed);
                break;
            case 'D':
            case 'DIRECT':
                return aircraft.directTo(params.fix);
            case 'RESUME':
                aircraft.resumeNav();
                break;
            default:
                console.warn(`Unknown command: ${command}`);
                return false;
        }
        
        this._logSimEvent(sim, 'CMD', `${callsign}: ${command} ${JSON.stringify(params)}`);
        return true;
    }

    tick(simId, deltaSeconds = 1) {
        const sim = this.simulations.get(simId);
        if (!sim || sim.isPaused) {
            return { success: false, reason: sim ? 'paused' : 'not found' };
        }
        
        for (const aircraft of sim.aircraft.values()) {
            if (aircraft.isActive) {
                aircraft.tick(deltaSeconds);
            }
        }
        
        sim.currentTime = new Date(sim.currentTime.getTime() + deltaSeconds * 1000);
        sim.tickCount++;
        
        const aircraftStates = this.getAllAircraft(simId);
        
        return {
            success: true,
            simId,
            currentTime: sim.currentTime,
            tickCount: sim.tickCount,
            deltaSeconds,
            aircraftCount: aircraftStates.length,
            aircraft: aircraftStates
        };
    }

    runFor(simId, durationSeconds, tickInterval = 1) {
        const sim = this.simulations.get(simId);
        if (!sim) {
            return { success: false, reason: 'not found' };
        }
        
        const numTicks = Math.ceil(durationSeconds / tickInterval);
        
        for (let i = 0; i < numTicks; i++) {
            this.tick(simId, tickInterval);
        }
        
        return {
            success: true,
            simId,
            currentTime: sim.currentTime,
            tickCount: sim.tickCount,
            aircraft: this.getAllAircraft(simId)
        };
    }

    pause(simId) {
        const sim = this.simulations.get(simId);
        if (sim) {
            sim.isPaused = true;
            this._logSimEvent(sim, 'SYS', 'Simulation paused');
        }
    }

    resume(simId) {
        const sim = this.simulations.get(simId);
        if (sim) {
            sim.isPaused = false;
            this._logSimEvent(sim, 'SYS', 'Simulation resumed');
        }
    }

    deleteSimulation(simId) {
        this.simulations.delete(simId);
        console.log(`SimulationController: Deleted simulation ${simId}`);
    }

    removeAircraft(simId, callsign) {
        const sim = this.simulations.get(simId);
        if (sim) {
            const aircraft = sim.aircraft.get(callsign);
            if (aircraft) {
                aircraft.remove();
                this._logSimEvent(sim, 'DESPAWN', `${callsign} removed`);
            }
        }
    }

    _logSimEvent(sim, type, message) {
        sim.events.push({ time: sim.currentTime, type, message });
        if (sim.events.length > 500) {
            sim.events = sim.events.slice(-500);
        }
    }
}

module.exports = SimulationController;
