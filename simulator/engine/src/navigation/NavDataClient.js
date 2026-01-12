/**
 * NavDataClient - Fetches navigation data from PERTI's existing API
 * 
 * Uses PERTI's VATSIM_ADL database tables:
 * - nav_fixes: Fixes, VORs, NDBs, airports
 * - nav_procedures: SIDs, STARs
 * - nav_procedure_legs: Detailed procedure leg info
 * - nav_airways: Airway definitions
 * - apts: Airport data
 */

const { distanceNm } = require('../math/flightMath');

class NavDataClient {
    constructor(options = {}) {
        this.baseUrl = options.baseUrl || 'https://perti.vatcscc.org/api';
        this.queryDatabase = options.queryDatabase || null;
        
        // In-memory cache
        this.cache = {
            fixes: new Map(),
            airports: new Map(),
            procedures: new Map(),
            airways: new Map()
        };
        
        this.cacheExpiry = 3600000; // 1 hour
        this.lastCacheUpdate = 0;
    }

    async getFix(fixName) {
        const cached = this.cache.fixes.get(fixName.toUpperCase());
        if (cached) return cached;
        
        const fixes = await this._fetchFixes(fixName);
        this.cache.fixes.set(fixName.toUpperCase(), fixes);
        return fixes;
    }

    async getFixNearest(fixName, refLat, refLon) {
        const candidates = await this.getFix(fixName);
        
        if (!candidates || candidates.length === 0) return null;
        
        let nearest = null;
        let minDist = Infinity;
        
        for (const fix of candidates) {
            const dist = distanceNm(refLat, refLon, fix.lat, fix.lon);
            if (dist < minDist) {
                minDist = dist;
                nearest = { ...fix, distance_nm: dist };
            }
        }
        
        return nearest;
    }

    async getAirport(icao) {
        const code = icao.toUpperCase();
        const cached = this.cache.airports.get(code);
        if (cached) return cached;
        
        const airport = await this._fetchAirport(code);
        if (airport) {
            this.cache.airports.set(code, airport);
        }
        return airport;
    }

    async getSid(airportIcao, sidName, transition = null) {
        const key = `SID:${airportIcao}:${sidName}:${transition || 'RWY'}`;
        const cached = this.cache.procedures.get(key);
        if (cached) return cached;
        
        const procedure = await this._fetchProcedure(airportIcao, 'SID', sidName, transition);
        if (procedure) {
            this.cache.procedures.set(key, procedure);
        }
        return procedure;
    }

    async getStar(airportIcao, starName, transition = null) {
        const key = `STAR:${airportIcao}:${starName}:${transition || 'RWY'}`;
        const cached = this.cache.procedures.get(key);
        if (cached) return cached;
        
        const procedure = await this._fetchProcedure(airportIcao, 'STAR', starName, transition);
        if (procedure) {
            this.cache.procedures.set(key, procedure);
        }
        return procedure;
    }

    async expandAirway(airwayId, startFix, endFix) {
        const key = `AWY:${airwayId}:${startFix}:${endFix}`;
        const cached = this.cache.airways.get(key);
        if (cached) return cached;
        
        const fixes = await this._fetchAirwaySegment(airwayId, startFix, endFix);
        if (fixes && fixes.length > 0) {
            this.cache.airways.set(key, fixes);
        }
        return fixes;
    }

    async resolveRoute(origin, destination, routeString) {
        const waypoints = [];
        
        // Add origin
        const originApt = await this.getAirport(origin);
        if (originApt) {
            waypoints.push({
                fix_name: origin,
                lat: originApt.lat,
                lon: originApt.lon,
                type: 'AIRPORT',
                cum_dist_nm: 0
            });
        }
        
        // Parse route tokens
        const tokens = routeString.toUpperCase().split(/\s+/).filter(t => t.length > 0);
        let lastLat = originApt?.lat || 0;
        let lastLon = originApt?.lon || 0;
        let cumDist = 0;
        
        for (const token of tokens) {
            if (token.match(/^[NK]\d{4}/)) continue;
            if (token.match(/^\/[ABFMS]\d+/)) continue;
            if (token === 'DCT') continue;
            
            const fix = await this.getFixNearest(token, lastLat, lastLon);
            if (fix) {
                const dist = distanceNm(lastLat, lastLon, fix.lat, fix.lon);
                cumDist += dist;
                
                waypoints.push({
                    fix_name: fix.fix_name,
                    lat: fix.lat,
                    lon: fix.lon,
                    type: fix.fix_type || 'FIX',
                    cum_dist_nm: cumDist
                });
                
                lastLat = fix.lat;
                lastLon = fix.lon;
            }
        }
        
        // Add destination
        const destApt = await this.getAirport(destination);
        if (destApt) {
            const dist = distanceNm(lastLat, lastLon, destApt.lat, destApt.lon);
            cumDist += dist;
            
            waypoints.push({
                fix_name: destination,
                lat: destApt.lat,
                lon: destApt.lon,
                type: 'AIRPORT',
                cum_dist_nm: cumDist
            });
        }
        
        return waypoints;
    }

    // Private fetch methods
    async _fetchFixes(fixName) {
        try {
            if (this.baseUrl) {
                const response = await fetch(
                    `${this.baseUrl}/simulator/navdata.php?action=fix&name=${encodeURIComponent(fixName)}`
                );
                if (response.ok) {
                    const data = await response.json();
                    return data.fixes || [];
                }
            }
            return [];
        } catch (error) {
            console.error(`NavDataClient._fetchFixes error for ${fixName}:`, error.message);
            return [];
        }
    }

    async _fetchAirport(icao) {
        try {
            if (this.baseUrl) {
                const response = await fetch(
                    `${this.baseUrl}/simulator/navdata.php?action=airport&icao=${encodeURIComponent(icao)}`
                );
                if (response.ok) {
                    const data = await response.json();
                    if (data.airport) {
                        return {
                            icao: data.airport.icao,
                            lat: data.airport.lat,
                            lon: data.airport.lon,
                            elevation_ft: data.airport.elevation_ft || 0,
                            name: data.airport.name || icao
                        };
                    }
                }
            }
            return null;
        } catch (error) {
            console.error(`NavDataClient._fetchAirport error for ${icao}:`, error.message);
            return null;
        }
    }

    async _fetchProcedure(airportIcao, procedureType, procedureName, transition) {
        // Future implementation - query nav_procedures table
        return null;
    }

    async _fetchAirwaySegment(airwayId, startFix, endFix) {
        // Future implementation - query nav_airways table
        return [];
    }

    async preloadCache(airports = []) {
        console.log(`NavDataClient: Preloading cache for ${airports.length} airports...`);
        for (const icao of airports) {
            await this.getAirport(icao);
        }
        this.lastCacheUpdate = Date.now();
    }

    clearCache() {
        this.cache.fixes.clear();
        this.cache.airports.clear();
        this.cache.procedures.clear();
        this.cache.airways.clear();
        this.lastCacheUpdate = 0;
    }
}

module.exports = NavDataClient;
