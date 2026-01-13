/**
 * SimulationController - Manages simulation instances
 * 
 * Each simulation contains:
 * - Multiple aircraft
 * - Simulation time tracking
 * - Tick-based advancement
 * - TMI management (Ground Stops, GDP, AFP)
 */

const AircraftModel = require('./aircraft/AircraftModel');
const NavDataClient = require('./navigation/NavDataClient');
const { GroundStopManager } = require('./tmi');
const path = require('path');
const fs = require('fs');

class SimulationController {
    constructor() {
        this.simulations = new Map();
        this.aircraftTypes = new Map();
        this.navData = new NavDataClient();
        this.nextSimId = 1;
        
        // Load ARTCC reference data
        this.artccReference = this._loadArtccReference();
    }

    _loadArtccReference() {
        const configPath = path.join(__dirname, '..', 'config', 'artccReference.json');
        try {
            if (fs.existsSync(configPath)) {
                return JSON.parse(fs.readFileSync(configPath, 'utf8'));
            }
        } catch (error) {
            console.warn('Warning: Could not load artccReference.json:', error.message);
        }
        
        // Return minimal default
        return {
            artccs: {},
            regions: { CONUS: [] },
            majorAirportsByArtcc: {}
        };
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
            events: [],
            
            // TMI Managers
            groundStopManager: new GroundStopManager(this.artccReference),
            
            // Departure queue (flights waiting to depart)
            departureQueue: new Map()  // callsign -> { flight, scheduledDep, holdReason }
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
            isPaused: sim.isPaused,
            activeGroundStops: sim.groundStopManager.getActiveGroundStops().length,
            heldFlights: sim.departureQueue.size
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
        
        // Determine origin ARTCC
        const originArtcc = this._getArtccForAirport(params.origin);
        
        let flightPlan = [];
        
        // Use pre-expanded waypoints if provided
        if (params.waypoints && params.waypoints.length > 0) {
            flightPlan = params.waypoints;
        } else if (params.route && params.route !== 'DCT') {
            // Resolve route string via navData
            flightPlan = await this.navData.resolveRoute(
                params.origin,
                params.destination,
                params.route
            );
        } else {
            // Direct route
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
        
        // Create flight data object for TMI checks
        const flightData = {
            callsign: params.callsign,
            aircraftType: params.aircraftType,
            origin: params.origin,
            destination: params.destination,
            originArtcc: originArtcc,
            engineType: perfData?.engineType || 'J',
            scheduledDeparture: params.scheduledDeparture || sim.currentTime
        };
        
        // Check if flight is subject to Ground Stop
        const gsCheck = sim.groundStopManager.canDepart(flightData, sim.currentTime);
        
        if (!gsCheck.canDepart) {
            // Flight is held - add to departure queue
            sim.departureQueue.set(params.callsign, {
                flightData,
                params,
                flightPlan,
                performance,
                originApt,
                initialHeading,
                heldBy: gsCheck.heldBy,
                gsEndTime: gsCheck.gsEndTime,
                reason: gsCheck.reason
            });
            
            this._logSimEvent(sim, 'GS_HOLD', `${params.callsign} held at ${params.origin} - GS to ${params.destination}`);
            
            return {
                callsign: params.callsign,
                status: 'HELD',
                heldBy: gsCheck.heldBy,
                gsEndTime: gsCheck.gsEndTime,
                origin: params.origin,
                destination: params.destination,
                reason: gsCheck.reason
            };
        }
        
        // Flight can depart - create aircraft
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
        
        // Store additional metadata
        aircraft.originArtcc = originArtcc;
        aircraft.gsExempt = gsCheck.exempt || false;
        
        if (params.altitude && params.altitude > 1000) {
            aircraft.targetAltitude = params.cruiseAltitude || params.altitude;
            aircraft.targetSpeed = performance?.cruiseSpeed || 280;
        }
        
        sim.aircraft.set(params.callsign, aircraft);
        this._logSimEvent(sim, 'SPAWN', `${params.callsign} spawned at ${params.origin}${gsCheck.exempt ? ' (GS EXEMPT)' : ''}`);
        
        return aircraft.toStateObject();
    }

    _getArtccForAirport(airport) {
        const icao = airport.toUpperCase();
        
        if (this.artccReference.majorAirportsByArtcc) {
            for (const [artcc, airports] of Object.entries(this.artccReference.majorAirportsByArtcc)) {
                if (airports.includes(icao)) {
                    return artcc;
                }
            }
        }
        
        return null;
    }

    getAircraft(simId, callsign) {
        const sim = this.simulations.get(simId);
        if (!sim) return null;
        
        // Check if in departure queue (held)
        const held = sim.departureQueue.get(callsign);
        if (held) {
            return {
                callsign: held.flightData.callsign,
                status: 'HELD',
                origin: held.flightData.origin,
                destination: held.flightData.destination,
                heldBy: held.heldBy,
                gsEndTime: held.gsEndTime,
                reason: held.reason
            };
        }
        
        const aircraft = sim.aircraft.get(callsign);
        return aircraft ? aircraft.toStateObject() : null;
    }

    getAllAircraft(simId) {
        const sim = this.simulations.get(simId);
        if (!sim) return [];
        
        const active = Array.from(sim.aircraft.values())
            .filter(ac => ac.isActive)
            .map(ac => ac.toStateObject());
        
        // Include held flights with special status
        for (const [callsign, held] of sim.departureQueue) {
            active.push({
                callsign: held.flightData.callsign,
                aircraftType: held.flightData.aircraftType,
                origin: held.flightData.origin,
                destination: held.flightData.destination,
                status: 'HELD',
                heldBy: held.heldBy,
                gsEndTime: held.gsEndTime,
                lat: held.originApt?.lat,
                lon: held.originApt?.lon,
                altitude: 0,
                groundSpeed: 0,
                phase: 'HELD'
            });
        }
        
        return active;
    }

    // =========================================================================
    // Ground Stop Operations
    // =========================================================================

    issueGroundStop(simId, params) {
        const sim = this.simulations.get(simId);
        if (!sim) throw new Error(`Simulation ${simId} not found`);

        const gs = sim.groundStopManager.issueGroundStop({
            airport: params.airport,
            startTime: params.startTime ? new Date(params.startTime) : sim.currentTime,
            endTime: new Date(params.endTime),
            reason: params.reason,
            scope: params.scope,
            exemptions: params.exemptions
        });

        this._logSimEvent(sim, 'GS_ISSUE', `Ground Stop issued for ${params.airport} until ${gs.endTime.toISOString()}`);
        
        // Re-evaluate existing departure queue
        this._reevaluateDepartureQueue(sim);
        
        return gs;
    }

    updateGroundStop(simId, airport, updates) {
        const sim = this.simulations.get(simId);
        if (!sim) throw new Error(`Simulation ${simId} not found`);

        const gs = sim.groundStopManager.updateGroundStop(airport, updates);
        this._logSimEvent(sim, 'GS_UPDATE', `Ground Stop for ${airport} updated`);
        
        return gs;
    }

    purgeGroundStop(simId, airport) {
        const sim = this.simulations.get(simId);
        if (!sim) throw new Error(`Simulation ${simId} not found`);

        const result = sim.groundStopManager.purgeGroundStop(airport);
        this._logSimEvent(sim, 'GS_PURGE', `Ground Stop for ${airport} purged, ${result.releasedFlights.length} flights released`);
        
        // Release held flights
        this._releaseHeldFlights(sim, airport);
        
        return result;
    }

    getGroundStops(simId) {
        const sim = this.simulations.get(simId);
        if (!sim) return [];
        
        return sim.groundStopManager.getActiveGroundStops();
    }

    getGroundStop(simId, airport) {
        const sim = this.simulations.get(simId);
        if (!sim) return null;
        
        return sim.groundStopManager.getGroundStop(airport);
    }

    getHeldFlights(simId, airport = null) {
        const sim = this.simulations.get(simId);
        if (!sim) return [];
        
        const held = [];
        for (const [callsign, info] of sim.departureQueue) {
            if (!airport || info.flightData.destination.toUpperCase() === airport.toUpperCase()) {
                held.push({
                    callsign,
                    origin: info.flightData.origin,
                    destination: info.flightData.destination,
                    aircraftType: info.flightData.aircraftType,
                    heldBy: info.heldBy,
                    gsEndTime: info.gsEndTime,
                    reason: info.reason
                });
            }
        }
        
        return held;
    }

    _reevaluateDepartureQueue(sim) {
        // Check if any held flights are now affected by new/changed GS
        // (This handles the case where a GS scope changes)
        for (const [callsign, held] of sim.departureQueue) {
            const newCheck = sim.groundStopManager.canDepart(held.flightData, sim.currentTime);
            if (newCheck.canDepart && !newCheck.exempt) {
                // GS no longer affects this flight - but we keep it held
                // until the original GS ends (conservative approach)
            }
        }
    }

    async _releaseHeldFlights(sim, airport) {
        const toRelease = [];
        
        for (const [callsign, held] of sim.departureQueue) {
            if (held.flightData.destination.toUpperCase() === airport.toUpperCase()) {
                toRelease.push(callsign);
            }
        }
        
        for (const callsign of toRelease) {
            const held = sim.departureQueue.get(callsign);
            sim.departureQueue.delete(callsign);
            
            // Now spawn the aircraft
            const aircraft = new AircraftModel({
                callsign: held.flightData.callsign,
                aircraftType: held.flightData.aircraftType,
                origin: held.flightData.origin,
                destination: held.flightData.destination,
                lat: held.originApt?.lat,
                lon: held.originApt?.lon,
                altitude: held.originApt?.elevation_ft ?? 0,
                heading: held.initialHeading ?? 0,
                speed: 0,
                performance: held.performance,
                flightPlan: held.flightPlan
            });
            
            aircraft.originArtcc = held.flightData.originArtcc;
            
            if (held.params.altitude && held.params.altitude > 1000) {
                aircraft.targetAltitude = held.params.cruiseAltitude || held.params.altitude;
                aircraft.targetSpeed = held.performance?.cruiseSpeed || 280;
            }
            
            sim.aircraft.set(callsign, aircraft);
            this._logSimEvent(sim, 'GS_RELEASE', `${callsign} released from GS hold`);
        }
        
        return toRelease;
    }

    // =========================================================================
    // Original simulation control methods
    // =========================================================================

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
        
        // Advance time
        sim.currentTime = new Date(sim.currentTime.getTime() + deltaSeconds * 1000);
        sim.tickCount++;
        
        // Process TMI time advancement (check for expired GS)
        const expiredGS = sim.groundStopManager.tick(sim.currentTime);
        for (const { groundStop, releasedFlights } of expiredGS) {
            this._logSimEvent(sim, 'GS_EXPIRE', `Ground Stop for ${groundStop.airport} expired`);
            this._releaseHeldFlights(sim, groundStop.airport);
        }
        
        // Update aircraft
        for (const aircraft of sim.aircraft.values()) {
            if (aircraft.isActive) {
                aircraft.tick(deltaSeconds);
            }
        }
        
        const aircraftStates = this.getAllAircraft(simId);
        
        return {
            success: true,
            simId,
            currentTime: sim.currentTime,
            tickCount: sim.tickCount,
            deltaSeconds,
            aircraftCount: aircraftStates.length,
            aircraft: aircraftStates,
            activeGroundStops: sim.groundStopManager.getActiveGroundStops(),
            heldFlightCount: sim.departureQueue.size
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
            aircraft: this.getAllAircraft(simId),
            activeGroundStops: sim.groundStopManager.getActiveGroundStops()
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
            // Check departure queue
            if (sim.departureQueue.has(callsign)) {
                sim.departureQueue.delete(callsign);
                this._logSimEvent(sim, 'REMOVE', `${callsign} removed from departure queue`);
                return;
            }
            
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

    // =========================================================================
    // Batch Spawn / Scenario Loading
    // =========================================================================

    /**
     * Spawn multiple aircraft at once from a flight list
     * @param {string} simId - Simulation ID
     * @param {Array} flights - Array of flight objects
     * @returns {Object} Results summary
     */
    async spawnBatch(simId, flights) {
        const sim = this.simulations.get(simId);
        if (!sim) {
            throw new Error(`Simulation ${simId} not found`);
        }

        const results = {
            total: flights.length,
            spawned: 0,
            held: 0,
            failed: 0,
            errors: []
        };

        for (const flight of flights) {
            try {
                const result = await this.spawnAircraft(simId, {
                    callsign: flight.callsign,
                    aircraftType: flight.aircraftType || 'B738',
                    origin: flight.origin,
                    destination: flight.destination,
                    altitude: flight.altitude || 35000,
                    speed: flight.speed || 0,
                    cruiseAltitude: flight.cruiseAltitude || flight.altitude || 35000,
                    scheduledDeparture: flight.scheduledDeparture,
                    route: flight.route,
                    waypoints: flight.waypoints  // Pass pre-expanded waypoints
                });

                if (result.status === 'HELD') {
                    results.held++;
                } else {
                    results.spawned++;
                }
            } catch (error) {
                results.failed++;
                results.errors.push({
                    callsign: flight.callsign,
                    error: error.message
                });
            }
        }

        this._logSimEvent(sim, 'BATCH', 
            `Batch spawn: ${results.spawned} active, ${results.held} held, ${results.failed} failed`);

        return results;
    }

    /**
     * Load a scenario and spawn all flights
     * @param {string} simId - Simulation ID
     * @param {Object} scenario - Scenario object with flights array
     * @returns {Object} Load results
     */
    async loadScenario(simId, scenario) {
        const sim = this.simulations.get(simId);
        if (!sim) {
            throw new Error(`Simulation ${simId} not found`);
        }

        // Store scenario metadata
        sim.scenario = {
            id: scenario.id,
            name: scenario.name,
            dest: scenario.dest,
            suggestedAAR: scenario.suggestedAAR,
            reducedAAR: scenario.reducedAAR,
            loadedAt: new Date()
        };

        // Convert flights to spawn format with proper times
        const simStartTime = sim.currentTime;
        const spawnFlights = this._convertScenarioFlights(scenario.flights, simStartTime);

        this._logSimEvent(sim, 'SCENARIO', `Loading scenario: ${scenario.name} (${spawnFlights.length} flights)`);

        // Spawn all flights
        const results = await this.spawnBatch(simId, spawnFlights);

        return {
            scenario: sim.scenario,
            ...results
        };
    }

    /**
     * Convert scenario flights to spawn-ready format
     */
    _convertScenarioFlights(flights, simStartTime) {
        return flights.map(flight => {
            // Parse ETA time string (HH:MM:SSZ)
            let eta;
            if (flight.eta) {
                const etaParts = flight.eta.match(/(\d+):(\d+):(\d+)/);
                if (etaParts) {
                    const etaHour = parseInt(etaParts[1]);
                    const etaMinute = parseInt(etaParts[2]);

                    eta = new Date(simStartTime);
                    eta.setUTCHours(etaHour, etaMinute, 0, 0);

                    // If ETA is before sim start, it's next day
                    if (eta < simStartTime) {
                        eta.setUTCDate(eta.getUTCDate() + 1);
                    }
                }
            }

            // Calculate departure time
            const flightTimeMs = (flight.flightTimeMin || 120) * 60 * 1000;
            const etd = eta ? new Date(eta.getTime() - flightTimeMs) : simStartTime;

            return {
                callsign: flight.callsign,
                aircraftType: flight.aircraftType || 'B738',
                origin: flight.origin,
                destination: flight.destination,
                altitude: flight.altitude || 35000,
                speed: flight.speed || 450,
                cruiseAltitude: flight.altitude || 35000,
                scheduledDeparture: etd,
                scheduledArrival: eta,
                carrier: flight.carrier,
                route: flight.route,           // Preserve route string
                waypoints: flight.waypoints    // Preserve pre-expanded waypoints
            };
        });
    }

    /**
     * Clear all aircraft from simulation (reset for new scenario)
     */
    clearAircraft(simId) {
        const sim = this.simulations.get(simId);
        if (!sim) {
            throw new Error(`Simulation ${simId} not found`);
        }

        const count = sim.aircraft.size + sim.departureQueue.size;
        
        sim.aircraft.clear();
        sim.departureQueue.clear();
        
        this._logSimEvent(sim, 'CLEAR', `Cleared ${count} aircraft`);
        
        return { cleared: count };
    }

    /**
     * Get scenario info for current simulation
     */
    getScenarioInfo(simId) {
        const sim = this.simulations.get(simId);
        if (!sim) return null;
        
        return sim.scenario || null;
    }
}

module.exports = SimulationController;
