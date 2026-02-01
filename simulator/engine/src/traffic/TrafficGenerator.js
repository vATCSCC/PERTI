/**
 * TrafficGenerator - Generates realistic traffic for simulator scenarios
 *
 * Provides three modes of operation:
 *   A) Scenario-based: Pre-built training scenarios with curated traffic
 *   B) Pattern-based: Generate from historical route patterns
 *   C) Historical replay: Replay actual VATSIM traffic from a past date
 */

class TrafficGenerator {
    constructor(options = {}) {
        this.pertiApiUrl = options.pertiApiUrl || 'https://perti.vatcscc.org/api';
        this.defaultAircraftTypes = ['B738', 'A320', 'B739', 'A321', 'E75L', 'B77W', 'B788'];
    }

    /**
     * Load a pre-built training scenario
     * @param {string} scenarioId - Scenario identifier (e.g., 'jfk_afternoon_rush')
     * @returns {Promise<Object>} Scenario definition with flights
     */
    async loadScenario(scenarioId) {
        try {
            // Fetch scenario definitions
            const response = await fetch(`${this.pertiApiUrl}/simulator/traffic.php?action=scenarios`);
            const data = await response.json();

            if (!data.scenarios) {
                throw new Error('Failed to load scenarios');
            }

            const scenario = data.scenarios.find(s => s.id === scenarioId);
            if (!scenario) {
                throw new Error(`Scenario '${scenarioId}' not found`);
            }

            // Generate flights for this scenario
            const flights = await this.generateFromPatterns({
                destination: scenario.dest,
                startHour: scenario.startHour,
                endHour: scenario.endHour,
                targetCount: scenario.targetCount,
                demandLevel: scenario.demandLevel,
            });

            return {
                ...scenario,
                flights,
                generatedAt: new Date().toISOString(),
            };
        } catch (error) {
            console.error('TrafficGenerator.loadScenario error:', error.message);
            throw error;
        }
    }

    /**
     * Get list of available scenarios
     * @returns {Promise<Array>} List of scenario definitions
     */
    async getScenarios() {
        try {
            const response = await fetch(`${this.pertiApiUrl}/simulator/traffic.php?action=scenarios`);
            const data = await response.json();
            return data.scenarios || [];
        } catch (error) {
            console.error('TrafficGenerator.getScenarios error:', error.message);
            return this._getFallbackScenarios();
        }
    }

    /**
     * Generate traffic from route patterns (Option B)
     * @param {Object} params
     * @param {string} params.destination - Destination airport ICAO
     * @param {number} params.startHour - Start hour UTC (0-23)
     * @param {number} params.endHour - End hour UTC (0-23)
     * @param {number} params.targetCount - Approximate number of flights
     * @param {string} params.demandLevel - 'light', 'normal', 'heavy', 'extreme'
     * @returns {Promise<Array>} Array of flight objects ready for spawning
     */
    async generateFromPatterns(params) {
        const { destination, startHour, endHour, targetCount, demandLevel } = params;

        try {
            const url = new URL(`${this.pertiApiUrl}/simulator/traffic.php`);
            url.searchParams.set('action', 'generate');
            url.searchParams.set('dest', destination);
            url.searchParams.set('start_hour', startHour);
            url.searchParams.set('end_hour', endHour);
            url.searchParams.set('count', targetCount);
            url.searchParams.set('level', demandLevel || 'normal');

            const response = await fetch(url.toString());
            const data = await response.json();

            if (data.status !== 'ok') {
                throw new Error(data.message || 'Generation failed');
            }

            return data.flights || [];
        } catch (error) {
            console.error('TrafficGenerator.generateFromPatterns error:', error.message);
            // Generate fallback flights locally
            return this._generateFallbackFlights(destination, startHour, endHour, targetCount);
        }
    }

    /**
     * Replay historical VATSIM traffic (Option C)
     * @param {Object} params
     * @param {string} params.destination - Destination airport ICAO
     * @param {string} params.date - Date to replay (YYYY-MM-DD)
     * @param {number} params.startHour - Start hour UTC
     * @param {number} params.endHour - End hour UTC
     * @returns {Promise<Object>} Historical flight data
     */
    async loadHistorical(params) {
        const { destination, date, startHour, endHour } = params;

        try {
            const url = new URL(`${this.pertiApiUrl}/simulator/traffic.php`);
            url.searchParams.set('action', 'historical');
            url.searchParams.set('dest', destination);
            url.searchParams.set('date', date);
            url.searchParams.set('start_hour', startHour || 12);
            url.searchParams.set('end_hour', endHour || 18);

            const response = await fetch(url.toString());
            const data = await response.json();

            return {
                source: data.source,
                destination,
                date,
                timeRange: data.timeRange,
                flights: data.flights || [],
                count: data.count || 0,
            };
        } catch (error) {
            console.error('TrafficGenerator.loadHistorical error:', error.message);
            return {
                source: 'fallback',
                destination,
                date,
                flights: this._generateFallbackFlights(destination, startHour || 12, endHour || 18, 60),
                count: 60,
            };
        }
    }

    /**
     * Get route patterns for a destination
     * @param {string} destination - Airport ICAO
     * @returns {Promise<Array>} Route patterns with frequencies
     */
    async getPatterns(destination) {
        try {
            const response = await fetch(
                `${this.pertiApiUrl}/simulator/traffic.php?action=patterns&dest=${destination}&limit=50`,
            );
            const data = await response.json();
            return data.patterns || [];
        } catch (error) {
            console.error('TrafficGenerator.getPatterns error:', error.message);
            return [];
        }
    }

    /**
     * Get carrier lookup table
     * @returns {Promise<Array>} Carrier definitions
     */
    async getCarriers() {
        try {
            const response = await fetch(`${this.pertiApiUrl}/simulator/traffic.php?action=carriers`);
            const data = await response.json();
            return data.carriers || [];
        } catch (error) {
            console.error('TrafficGenerator.getCarriers error:', error.message);
            return this._getFallbackCarriers();
        }
    }

    /**
     * Get demand profile for an airport
     * @param {string} airport - Airport ICAO
     * @returns {Promise<Object>} Hourly demand data
     */
    async getDemandProfile(airport) {
        try {
            const response = await fetch(
                `${this.pertiApiUrl}/simulator/traffic.php?action=demand&airport=${airport}`,
            );
            const data = await response.json();
            return {
                airport,
                source: data.source,
                hourly: data.hourly || [],
            };
        } catch (error) {
            console.error('TrafficGenerator.getDemandProfile error:', error.message);
            return { airport, source: 'error', hourly: [] };
        }
    }

    /**
     * Convert generated flights to spawn-ready format with absolute times
     * @param {Array} flights - Array of flight objects from generation
     * @param {Date} simStartTime - Simulation start time
     * @returns {Array} Flights with absolute timestamps
     */
    convertToSpawnFormat(flights, simStartTime) {
        return flights.map(flight => {
            // Parse ETA time string (HH:MM:SSZ)
            const etaParts = flight.eta.match(/(\d+):(\d+):(\d+)/);
            const etaHour = parseInt(etaParts[1]);
            const etaMinute = parseInt(etaParts[2]);

            // Create absolute ETA
            const eta = new Date(simStartTime);
            eta.setUTCHours(etaHour, etaMinute, 0, 0);

            // If ETA is before sim start, it's probably next day
            if (eta < simStartTime) {
                eta.setUTCDate(eta.getUTCDate() + 1);
            }

            // Calculate departure time based on flight time
            const flightTimeMs = (flight.flightTimeMin || 120) * 60 * 1000;
            const etd = new Date(eta.getTime() - flightTimeMs);

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
                flightTimeMin: flight.flightTimeMin,
            };
        });
    }

    // =========================================================================
    // Fallback methods (when API unavailable)
    // =========================================================================

    _generateFallbackFlights(destination, startHour, endHour, count) {
        const carriers = ['DAL', 'UAL', 'AAL', 'SWA', 'JBU'];
        const origins = ['KATL', 'KORD', 'KDFW', 'KDEN', 'KLAX', 'KSFO', 'KMIA', 'KBOS', 'KEWR', 'KSEA'];
        const aircraftTypes = ['B738', 'A320', 'B739', 'A321', 'E75L'];

        const flights = [];
        const durationMinutes = (endHour - startHour) * 60;

        for (let i = 0; i < count; i++) {
            const carrier = carriers[i % carriers.length];
            const origin = origins.filter(o => o !== destination)[i % (origins.length - 1)];
            const flightNum = 100 + Math.floor(Math.random() * 9000);

            // Distribute ETAs across time window
            const etaOffset = Math.floor(Math.random() * durationMinutes);
            const etaHour = (startHour + Math.floor(etaOffset / 60)) % 24;
            const etaMinute = etaOffset % 60;

            const flightTimeMin = 90 + Math.floor(Math.random() * 180);

            flights.push({
                callsign: `${carrier}${flightNum}`,
                origin,
                destination,
                aircraftType: aircraftTypes[Math.floor(Math.random() * aircraftTypes.length)],
                carrier,
                eta: `${String(etaHour).padStart(2, '0')}:${String(etaMinute).padStart(2, '0')}:00Z`,
                altitude: [31000, 33000, 35000, 37000, 39000][Math.floor(Math.random() * 5)],
                speed: 440 + Math.floor(Math.random() * 40),
                flightTimeMin,
            });
        }

        // Sort by ETA
        flights.sort((a, b) => a.eta.localeCompare(b.eta));

        return flights;
    }

    _getFallbackScenarios() {
        return [
            {
                id: 'jfk_afternoon_rush',
                name: 'JFK Afternoon Rush',
                description: 'Heavy afternoon arrival bank at JFK',
                dest: 'KJFK',
                startHour: 14,
                endHour: 18,
                targetCount: 75,
                demandLevel: 'heavy',
            },
            {
                id: 'atl_weather_event',
                name: 'ATL Weather Event',
                description: 'Atlanta with reduced capacity',
                dest: 'KATL',
                startHour: 15,
                endHour: 20,
                targetCount: 100,
                demandLevel: 'heavy',
            },
        ];
    }

    _getFallbackCarriers() {
        return [
            { icao: 'DAL', name: 'Delta Air Lines', callsign: 'DELTA' },
            { icao: 'UAL', name: 'United Airlines', callsign: 'UNITED' },
            { icao: 'AAL', name: 'American Airlines', callsign: 'AMERICAN' },
            { icao: 'SWA', name: 'Southwest Airlines', callsign: 'SOUTHWEST' },
            { icao: 'JBU', name: 'JetBlue Airways', callsign: 'JETBLUE' },
        ];
    }
}

module.exports = TrafficGenerator;
