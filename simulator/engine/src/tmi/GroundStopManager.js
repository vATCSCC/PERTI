/**
 * GroundStopManager - Manages Ground Stop TMIs
 * 
 * A Ground Stop (GS) holds departures at origin airports destined for
 * the GS airport until the GS ends or is purged.
 * 
 * Key concepts:
 * - Scope: Which origin centers/airports are included (tier-based)
 * - Exemptions: Carriers, aircraft types, airports that bypass the GS
 * - Held flights: Flights that cannot depart until GS ends
 */

const {
    TMI_TYPE,
    TMI_STATUS,
    SCOPE_TYPE,
    TIER_KEYWORD,
    CONTROL_TYPE,
    GS_MIN_DURATION_MINUTES
} = require('./tmiConstants');

class GroundStopManager {
    constructor(artccReference) {
        this.artccReference = artccReference;
        this.groundStops = new Map();  // key: airport ICAO, value: GS object
        this.nextGsId = 1;
    }

    /**
     * Issue a new Ground Stop
     * @param {Object} params
     * @param {string} params.airport - Destination airport (e.g., 'KJFK')
     * @param {Date} params.startTime - GS start time
     * @param {Date} params.endTime - GS end time (must be at least 1 hour after start)
     * @param {string} params.reason - Reason for GS (e.g., 'WEATHER', 'VOLUME', 'EQUIPMENT')
     * @param {Object} params.scope - Scope definition
     * @param {Object} params.exemptions - Exemptions
     */
    issueGroundStop(params) {
        const { airport, startTime, endTime, reason, scope, exemptions } = params;

        // Validate
        if (!airport) throw new Error('Airport required for Ground Stop');
        if (!startTime || !endTime) throw new Error('Start and end time required');
        
        const durationMs = endTime.getTime() - startTime.getTime();
        const durationMinutes = durationMs / (1000 * 60);
        
        if (durationMinutes < GS_MIN_DURATION_MINUTES) {
            throw new Error(`Ground Stop must be at least ${GS_MIN_DURATION_MINUTES} minutes`);
        }

        // Check for existing active GS at this airport
        const existing = this.groundStops.get(airport.toUpperCase());
        if (existing && existing.status === TMI_STATUS.ACTIVE) {
            throw new Error(`Active Ground Stop already exists for ${airport}`);
        }

        const gsId = `GS_${airport.toUpperCase()}_${this.nextGsId++}`;
        
        const gs = {
            id: gsId,
            type: TMI_TYPE.GROUND_STOP,
            airport: airport.toUpperCase(),
            startTime: new Date(startTime),
            endTime: new Date(endTime),
            reason: reason || 'UNSPECIFIED',
            status: TMI_STATUS.ACTIVE,
            scope: this._normalizeScope(airport, scope),
            exemptions: this._normalizeExemptions(exemptions),
            issuedAt: new Date(),
            heldFlights: new Map(),  // callsign -> hold info
            stats: {
                totalHeld: 0,
                totalExempt: 0,
                totalReleased: 0
            }
        };

        this.groundStops.set(airport.toUpperCase(), gs);
        
        return gs;
    }

    /**
     * Check if a flight is affected by any Ground Stop
     * @param {Object} flight - Flight object with origin, destination, originArtcc, etc.
     * @param {Date} currentTime - Current simulation time
     * @returns {Object|null} GS info if held, null if clear to depart
     */
    checkFlightStatus(flight, currentTime) {
        const dest = flight.destination?.toUpperCase();
        if (!dest) return null;

        const gs = this.groundStops.get(dest);
        if (!gs || gs.status !== TMI_STATUS.ACTIVE) return null;

        // Check if within GS time window
        if (currentTime < gs.startTime || currentTime >= gs.endTime) {
            return null;
        }

        // Check scope - is origin center included?
        if (!this._isInScope(flight, gs)) {
            return null;
        }

        // Check exemptions
        if (this._isExempt(flight, gs)) {
            return {
                groundStop: gs,
                status: 'EXEMPT',
                reason: 'Exemption applies'
            };
        }

        // Flight is held
        return {
            groundStop: gs,
            status: 'HELD',
            gsEndTime: gs.endTime,
            reason: gs.reason
        };
    }

    /**
     * Apply Ground Stop to a departing flight
     * Returns whether the flight can depart
     */
    canDepart(flight, currentTime) {
        const status = this.checkFlightStatus(flight, currentTime);
        
        if (!status) return { canDepart: true };
        if (status.status === 'EXEMPT') return { canDepart: true, exempt: true };
        
        // Hold the flight
        const gs = status.groundStop;
        if (!gs.heldFlights.has(flight.callsign)) {
            gs.heldFlights.set(flight.callsign, {
                callsign: flight.callsign,
                origin: flight.origin,
                heldAt: new Date(currentTime),
                originalEtd: flight.scheduledDeparture
            });
            gs.stats.totalHeld++;
        }

        return {
            canDepart: false,
            heldBy: gs.id,
            gsEndTime: gs.endTime,
            reason: gs.reason
        };
    }

    /**
     * Release flights when GS ends or is purged
     */
    releaseFlights(airport) {
        const gs = this.groundStops.get(airport.toUpperCase());
        if (!gs) return [];

        const released = [];
        for (const [callsign, holdInfo] of gs.heldFlights) {
            released.push({
                callsign,
                origin: holdInfo.origin,
                heldDuration: Date.now() - holdInfo.heldAt.getTime()
            });
            gs.stats.totalReleased++;
        }
        
        gs.heldFlights.clear();
        return released;
    }

    /**
     * Update GS parameters (extend, reduce end time)
     */
    updateGroundStop(airport, updates) {
        const gs = this.groundStops.get(airport.toUpperCase());
        if (!gs) throw new Error(`No Ground Stop found for ${airport}`);
        if (gs.status !== TMI_STATUS.ACTIVE) throw new Error('Cannot update inactive Ground Stop');

        if (updates.endTime) {
            gs.endTime = new Date(updates.endTime);
        }
        if (updates.scope) {
            gs.scope = this._normalizeScope(airport, updates.scope);
        }
        if (updates.exemptions) {
            gs.exemptions = this._normalizeExemptions(updates.exemptions);
        }
        if (updates.reason) {
            gs.reason = updates.reason;
        }

        return gs;
    }

    /**
     * Purge (cancel) a Ground Stop
     */
    purgeGroundStop(airport) {
        const gs = this.groundStops.get(airport.toUpperCase());
        if (!gs) throw new Error(`No Ground Stop found for ${airport}`);

        gs.status = TMI_STATUS.PURGED;
        gs.purgedAt = new Date();
        
        const released = this.releaseFlights(airport);
        
        return { groundStop: gs, releasedFlights: released };
    }

    /**
     * Process time advancement - check for expired GS
     */
    tick(currentTime) {
        const expired = [];
        
        for (const [airport, gs] of this.groundStops) {
            if (gs.status === TMI_STATUS.ACTIVE && currentTime >= gs.endTime) {
                gs.status = TMI_STATUS.EXPIRED;
                gs.expiredAt = currentTime;
                const released = this.releaseFlights(airport);
                expired.push({ groundStop: gs, releasedFlights: released });
            }
        }

        return expired;
    }

    /**
     * Get active Ground Stops
     */
    getActiveGroundStops() {
        const active = [];
        for (const gs of this.groundStops.values()) {
            if (gs.status === TMI_STATUS.ACTIVE) {
                active.push(this._toPublicObject(gs));
            }
        }
        return active;
    }

    /**
     * Get Ground Stop for specific airport
     */
    getGroundStop(airport) {
        const gs = this.groundStops.get(airport.toUpperCase());
        return gs ? this._toPublicObject(gs) : null;
    }

    /**
     * Get all held flights across all GS
     */
    getAllHeldFlights() {
        const held = [];
        for (const gs of this.groundStops.values()) {
            if (gs.status === TMI_STATUS.ACTIVE) {
                for (const [callsign, info] of gs.heldFlights) {
                    held.push({
                        callsign,
                        destination: gs.airport,
                        gsId: gs.id,
                        ...info
                    });
                }
            }
        }
        return held;
    }

    // =========================================================================
    // Private methods
    // =========================================================================

    _normalizeScope(airport, scope) {
        if (!scope) {
            // Default: Tier 1 scope
            return {
                type: SCOPE_TYPE.TIER,
                tier: TIER_KEYWORD.TIER1,
                includedCenters: this._getCentersForTier(airport, TIER_KEYWORD.TIER1),
                excludedCenters: [],
                includedAirports: [],
                excludedAirports: []
            };
        }

        const normalized = {
            type: scope.type || SCOPE_TYPE.TIER,
            tier: scope.tier || TIER_KEYWORD.TIER1,
            includedCenters: [],
            excludedCenters: scope.excludedCenters || [],
            includedAirports: scope.includedAirports || [],
            excludedAirports: scope.excludedAirports || []
        };

        if (scope.includedCenters && scope.includedCenters.length > 0) {
            normalized.includedCenters = scope.includedCenters.map(c => c.toUpperCase());
        } else {
            normalized.includedCenters = this._getCentersForTier(airport, normalized.tier);
        }

        return normalized;
    }

    _getCentersForTier(airport, tier) {
        // Find the ARTCC for this airport
        const destArtcc = this._getArtccForAirport(airport);
        if (!destArtcc) return [];

        const artccData = this.artccReference.artccs[destArtcc];
        if (!artccData) return [destArtcc];

        switch (tier) {
            case TIER_KEYWORD.INTERNAL:
                return [destArtcc];
            case TIER_KEYWORD.TIER1:
                return [destArtcc, ...(artccData.tier1 || [])];
            case TIER_KEYWORD.TIER2:
                return [destArtcc, ...(artccData.tier1 || []), ...(artccData.tier2 || [])];
            case TIER_KEYWORD.ALL:
            case TIER_KEYWORD.CONUS:
                return this.artccReference.regions?.CONUS || Object.keys(this.artccReference.artccs);
            default:
                // Check if it's a region keyword
                if (this.artccReference.regions && this.artccReference.regions[tier]) {
                    return this.artccReference.regions[tier];
                }
                return [destArtcc];
        }
    }

    _getArtccForAirport(airport) {
        const icao = airport.toUpperCase();
        
        // Check majorAirportsByArtcc
        if (this.artccReference.majorAirportsByArtcc) {
            for (const [artcc, airports] of Object.entries(this.artccReference.majorAirportsByArtcc)) {
                if (airports.includes(icao)) {
                    return artcc;
                }
            }
        }

        // Fallback: could query database, but for now return null
        return null;
    }

    _normalizeExemptions(exemptions) {
        if (!exemptions) {
            return {
                carriers: [],
                aircraftTypes: [],
                arrivalFixes: [],
                originAirports: [],
                originCenters: [],
                flightTypes: [],
                props: false
            };
        }

        return {
            carriers: (exemptions.carriers || []).map(c => c.toUpperCase()),
            aircraftTypes: (exemptions.aircraftTypes || []).map(t => t.toUpperCase()),
            arrivalFixes: (exemptions.arrivalFixes || []).map(f => f.toUpperCase()),
            originAirports: (exemptions.originAirports || []).map(a => a.toUpperCase()),
            originCenters: (exemptions.originCenters || []).map(c => c.toUpperCase()),
            flightTypes: exemptions.flightTypes || [],
            props: exemptions.props || false
        };
    }

    _isInScope(flight, gs) {
        const scope = gs.scope;
        const originAirport = flight.origin?.toUpperCase();
        const originArtcc = flight.originArtcc?.toUpperCase() || this._getArtccForAirport(originAirport);

        // Check excluded airports first
        if (scope.excludedAirports.includes(originAirport)) {
            return false;
        }

        // Check excluded centers
        if (originArtcc && scope.excludedCenters.includes(originArtcc)) {
            return false;
        }

        // Check if specifically included airport
        if (scope.includedAirports.length > 0 && scope.includedAirports.includes(originAirport)) {
            return true;
        }

        // Check if origin center is in scope
        if (originArtcc && scope.includedCenters.includes(originArtcc)) {
            return true;
        }

        return false;
    }

    _isExempt(flight, gs) {
        const exemptions = gs.exemptions;

        // Carrier exemption
        if (exemptions.carriers.length > 0) {
            const carrier = this._extractCarrier(flight.callsign);
            if (carrier && exemptions.carriers.includes(carrier)) {
                return true;
            }
        }

        // Aircraft type exemption
        if (exemptions.aircraftTypes.length > 0) {
            const acType = flight.aircraftType?.toUpperCase();
            if (acType && exemptions.aircraftTypes.includes(acType)) {
                return true;
            }
        }

        // Origin airport exemption
        if (exemptions.originAirports.length > 0) {
            const origin = flight.origin?.toUpperCase();
            if (origin && exemptions.originAirports.includes(origin)) {
                return true;
            }
        }

        // Origin center exemption
        if (exemptions.originCenters.length > 0) {
            const originArtcc = flight.originArtcc?.toUpperCase();
            if (originArtcc && exemptions.originCenters.includes(originArtcc)) {
                return true;
            }
        }

        // Props exemption (turboprops only)
        if (exemptions.props && flight.engineType === 'P') {
            return true;
        }

        return false;
    }

    _extractCarrier(callsign) {
        if (!callsign) return null;
        // Extract 2-3 letter carrier code from callsign (e.g., 'DAL123' -> 'DAL')
        const match = callsign.match(/^([A-Z]{2,3})/);
        return match ? match[1] : null;
    }

    _toPublicObject(gs) {
        return {
            id: gs.id,
            type: gs.type,
            airport: gs.airport,
            startTime: gs.startTime,
            endTime: gs.endTime,
            reason: gs.reason,
            status: gs.status,
            scope: {
                type: gs.scope.type,
                tier: gs.scope.tier,
                includedCenters: gs.scope.includedCenters,
                excludedCenters: gs.scope.excludedCenters
            },
            exemptions: gs.exemptions,
            issuedAt: gs.issuedAt,
            heldFlightCount: gs.heldFlights.size,
            stats: { ...gs.stats }
        };
    }
}

module.exports = GroundStopManager;
