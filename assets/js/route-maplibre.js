// route-maplibre.js - MapLibre GL JS implementation for PERTI route visualization
// Migration from Leaflet - Phase 1 + Phase 2 + Phase 3 + Phase 4 + Phase 5 (Interactivity)
// ═══════════════════════════════════════════════════════════════════════════════

$(document).ready(function() {
    'use strict';

    // ═══════════════════════════════════════════════════════════════════════════
    // GLOBAL STATE
    // ═══════════════════════════════════════════════════════════════════════════
    
    let graphic_map = null;
    let mapReady = false;
    let lastExpandedRoutes = []; // Store expanded route strings for public routes feature
    let overlays = [];
    
    let points = {};
    let facilityCodes = new Set();
    let areaCenters = {
        // ═══════════════════════════════════════════════════════════════════════════
        // ARTCC / FIR / Regional Pseudo-Fixes
        // Used as origin/destination placeholders in Playbook routes
        // ═══════════════════════════════════════════════════════════════════════════
        
        // US ARTCCs (CONUS)
        'ZBW': { lat: 42.36, lon: -71.06 },   // Boston Center
        'ZNY': { lat: 40.78, lon: -73.97 },   // New York Center
        'ZDC': { lat: 38.95, lon: -77.45 },   // Washington Center
        'ZOB': { lat: 41.40, lon: -81.85 },   // Cleveland Center
        'ZID': { lat: 39.70, lon: -86.20 },   // Indianapolis Center
        'ZTL': { lat: 33.64, lon: -84.43 },   // Atlanta Center
        'ZJX': { lat: 30.33, lon: -81.66 },   // Jacksonville Center
        'ZMA': { lat: 25.80, lon: -80.29 },   // Miami Center
        'ZME': { lat: 35.04, lon: -89.98 },   // Memphis Center
        'ZKC': { lat: 39.10, lon: -94.58 },   // Kansas City Center
        'ZAU': { lat: 41.98, lon: -87.90 },   // Chicago Center
        'ZMP': { lat: 44.88, lon: -93.22 },   // Minneapolis Center
        'ZFW': { lat: 32.90, lon: -97.04 },   // Fort Worth Center
        'ZHU': { lat: 29.99, lon: -95.34 },   // Houston Center
        'ZAB': { lat: 35.04, lon: -106.61 },  // Albuquerque Center
        'ZDV': { lat: 39.86, lon: -104.67 },  // Denver Center
        'ZLC': { lat: 40.79, lon: -111.98 },  // Salt Lake Center
        'ZLA': { lat: 33.94, lon: -118.41 },  // Los Angeles Center
        'ZOA': { lat: 37.62, lon: -122.38 },  // Oakland Center
        'ZSE': { lat: 47.45, lon: -122.31 },  // Seattle Center
        
        // US ARTCCs (Non-CONUS)
        'ZAN': { lat: 61.17, lon: -150.00 },  // Anchorage Center
        'ZHN': { lat: 21.32, lon: -157.92 },  // Honolulu Center
        'ZSU': { lat: 18.44, lon: -66.00 },   // San Juan Center
        
        // Canadian FIRs (short codes)
        'CZY': { lat: 43.68, lon: -79.63 },   // Toronto FIR (CZYZ)
        'CZU': { lat: 45.47, lon: -73.74 },   // Montreal FIR (CZUL)
        'CZV': { lat: 49.19, lon: -123.18 },  // Vancouver FIR (CZVR)
        'CZW': { lat: 49.91, lon: -97.24 },   // Winnipeg FIR (CZWG)
        'CZE': { lat: 53.31, lon: -113.58 },  // Edmonton FIR (CZEG)
        
        // Caribbean / Central America FIRs
        'MDPO': { lat: 18.43, lon: -69.67 },  // Santo Domingo FIR (Dominican Republic)
        'MDDJ': { lat: 18.57, lon: -69.99 },  // Dominican Republic alternate
        'TJSJ': { lat: 18.44, lon: -66.00 },  // San Juan FIR
        'MMZT': { lat: 23.16, lon: -106.27 }, // Mazatlan FIR
        'MMMX': { lat: 19.44, lon: -99.07 },  // Mexico City FIR
        'ZMZ': { lat: 23.16, lon: -106.27 },  // Mazatlan FIR (short)
        'ZMX': { lat: 19.44, lon: -99.07 },   // Mexico City FIR (short)
        
        // International / Generic
        'ZEU': { lat: 51.47, lon: -0.46 },    // Europe (London area)
        'UNKN': { lat: 40.00, lon: -60.00 },  // Unknown (mid-Atlantic)
        
        // TRACON codes (when used as pseudo-origins)
        'TPA': { lat: 27.98, lon: -82.53 },   // Tampa TRACON
        'N90': { lat: 40.78, lon: -73.87 },   // New York TRACON
        'A80': { lat: 33.64, lon: -84.43 },   // Atlanta TRACON
        'C90': { lat: 41.98, lon: -87.90 },   // Chicago TRACON
        'D10': { lat: 32.90, lon: -97.04 },   // Dallas TRACON
        'I90': { lat: 29.99, lon: -95.34 },   // Houston TRACON
        'L30': { lat: 33.94, lon: -118.41 },  // Los Angeles TRACON
        'NCT': { lat: 37.62, lon: -122.38 },  // Norcal TRACON
        'SCT': { lat: 33.94, lon: -118.41 },  // Socal TRACON
        'PCT': { lat: 38.95, lon: -77.45 },   // Potomac TRACON
        'MIA': { lat: 25.80, lon: -80.29 },   // Miami TRACON
        'F11': { lat: 28.43, lon: -81.31 },   // Orlando TRACON
        'RSW': { lat: 26.54, lon: -81.76 },   // Ft Myers TRACON
        'PBI': { lat: 26.68, lon: -80.10 },   // Palm Beach TRACON
        'M98': { lat: 44.88, lon: -93.22 },   // Minneapolis TRACON
        
        // Small airports often used as pseudo-fixes
        'MMU': { lat: 40.80, lon: -74.42 }    // Morristown Municipal, NJ
    };
    let cdrMap = {};
    let playbookRoutes = [];
    let playbookByPlayName = {};
    let awyIndexMap = {};
    let awyIndexBuilt = false;

    // Departure procedure datasets (DPs)
    let dpByComputerCode = {};
    let dpPatternIndex = {};
    let dpByLeftCode = {};
    let dpByRootName = {};
    let dpRouteBodyIndex = {};
    let dpRouteTransitionIndex = {};
    let dpDataLoaded = false;
    let dpBodyPointSet = new Set();
    let dpActiveBodyPoints = new Set();

    // STAR procedure datasets
    let starFullRoutesByTransition = {};
    let starFullRoutesByStarCode = {};
    let starDataLoaded = false;

    let warnedFixes = new Set();
    
    // Route rendering state
    const baseRouteWeight = 3;
    let seenSegmentKeys = new Set();
    
    // Phase 5: Route label toggle tracking
    let routeLabelsVisible = new Set();  // Track which route IDs have labels visible
    let routeFixesByRouteId = {};        // Map routeId -> array of fix features
    let currentRouteId = 0;              // Counter for unique route IDs
    
    // Phase 5: Draggable label support
    let labelOffsets = {};               // Map "fixName|routeId" -> {dx, dy} offset in pixels
    let draggingLabel = null;            // Currently dragged label info
    let dragStartPos = null;             // Starting position for drag
    
    // Export data storage
    let lastExportData = {
        routes: [],           // Array of route metadata objects
        routeFeatures: [],    // GeoJSON line features
        fixFeatures: [],      // GeoJSON point features  
        timestamp: null
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // COORDINATE COMPATIBILITY LAYER
    // ═══════════════════════════════════════════════════════════════════════════
    
    const MapCompat = {
        toLngLat: function(latLng) {
            if (Array.isArray(latLng)) return [latLng[1], latLng[0]];
            return [latLng.lng, latLng.lat];
        },
        toLatLng: function(lngLat) {
            if (Array.isArray(lngLat)) return { lat: lngLat[1], lng: lngLat[0] };
            return { lat: lngLat.lat, lng: lngLat.lng };
        },
        getZoom: function() { return graphic_map ? graphic_map.getZoom() : 4; },
        getCenter: function() {
            if (!graphic_map) return { lat: 39.5, lng: -98.35 };
            const c = graphic_map.getCenter();
            return { lat: c.lat, lng: c.lng };
        },
        setView: function(lat, lng, zoom) {
            if (graphic_map) {
                graphic_map.setCenter([lng, lat]);
                if (zoom !== undefined) graphic_map.setZoom(zoom);
            }
        },
        fitBounds: function(bounds, options) {
            if (!graphic_map || !bounds) return;
            const sw = bounds[0] || bounds.getSouthWest();
            const ne = bounds[1] || bounds.getNorthEast();
            graphic_map.fitBounds([
                [sw[1] || sw.lng, sw[0] || sw.lat],
                [ne[1] || ne.lng, ne[0] || ne.lat]
            ], options);
        },
        project: function(lat, lng) {
            if (!graphic_map) return { x: 0, y: 0 };
            return graphic_map.project([lng, lat]);
        },
        unproject: function(x, y) {
            if (!graphic_map) return { lat: 0, lng: 0 };
            const lngLat = graphic_map.unproject([x, y]);
            return { lat: lngLat.lat, lng: lngLat.lng };
        }
    };
    window.MapCompat = MapCompat;

    // ═══════════════════════════════════════════════════════════════════════════
    // UTILITY FUNCTIONS
    // ═══════════════════════════════════════════════════════════════════════════

    function distanceToPoint(lat1, lon1, lat2, lon2) {
        const R = 6371; // km
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon/2) * Math.sin(dLon/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }

    function confirmReasonableDistance(pointData, previousPointData, nextPointData) {
        let maxReasonableDistance = 4000;
        if (previousPointData && nextPointData) {
            const dist = distanceToPoint(previousPointData[1], previousPointData[2], 
                                         nextPointData[1], nextPointData[2]);
            maxReasonableDistance = Math.min(maxReasonableDistance, dist * 1.5);
        }
        if (previousPointData) {
            const dist = distanceToPoint(pointData[1], pointData[2], 
                                         previousPointData[1], previousPointData[2]);
            if (dist > maxReasonableDistance) return undefined;
        }
        if (nextPointData) {
            const dist = distanceToPoint(pointData[1], pointData[2], 
                                         nextPointData[1], nextPointData[2]);
            if (dist > maxReasonableDistance) return undefined;
        }
        return pointData;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // COORDINATE PARSING (ICAO, NAT, ARINC formats)
    // ═══════════════════════════════════════════════════════════════════════════

    function parseLatComponent(part) {
        if (!part) return null;
        const s = String(part).toUpperCase().trim();
        let m = s.match(/^(\d{2})(\d{2})([NS])$/);
        if (m) {
            let lat = parseInt(m[1], 10) + parseInt(m[2], 10) / 60.0;
            if (m[3] === 'S') lat = -lat;
            return lat;
        }
        m = s.match(/^(\d{2})([NS])$/);
        if (m) {
            let lat = parseInt(m[1], 10);
            if (m[2] === 'S') lat = -lat;
            return lat;
        }
        return null;
    }

    function parseLonComponent(part) {
        if (!part) return null;
        const s = String(part).toUpperCase().trim();
        let m = s.match(/^(\d{3})(\d{2})([EW])$/);
        if (m) {
            let lon = parseInt(m[1], 10) + parseInt(m[2], 10) / 60.0;
            if (m[3] === 'W') lon = -lon;
            return lon;
        }
        m = s.match(/^(\d{2})(\d{2})([EW])$/);
        if (m) {
            let lon = parseInt(m[1], 10) + parseInt(m[2], 10) / 60.0;
            if (m[3] === 'W') lon = -lon;
            return lon;
        }
        m = s.match(/^(\d{3})([EW])$/);
        if (m) {
            let lon = parseInt(m[1], 10);
            if (m[2] === 'W') lon = -lon;
            return lon;
        }
        m = s.match(/^(\d{2})([EW])$/);
        if (m) {
            let lon = parseInt(m[1], 10);
            if (m[2] === 'W') lon = -lon;
            return lon;
        }
        return null;
    }

    function parseArincFiveCharCoordinate(token) {
        if (!token) return null;
        const s = String(token).toUpperCase().trim();
        let m = s.match(/^(\d{2})(\d{2})([NSEW])$/);
        let latDeg, lonDeg, letter;
        if (m) {
            latDeg = parseInt(m[1], 10);
            lonDeg = parseInt(m[2], 10);
            letter = m[3];
        } else {
            m = s.match(/^(\d{2})([NSEW])(\d{2})$/);
            if (!m) return null;
            latDeg = parseInt(m[1], 10);
            lonDeg = 100 + parseInt(m[3], 10);
            letter = m[2];
        }
        let lat = latDeg, lon = lonDeg;
        switch (letter) {
            case 'N': lat = latDeg; lon = -lonDeg; break;
            case 'E': lat = latDeg; lon = lonDeg; break;
            case 'S': lat = -latDeg; lon = -lonDeg; break;
            case 'W': lat = -latDeg; lon = lonDeg; break;
        }
        return { lat, lon };
    }

    function parseNatSlashCoordinate(token) {
        if (!token) return null;
        const s = String(token).trim();
        const m = s.match(/^(\d{2})\/(\d{2,3})$/);
        if (!m) return null;
        const lat = parseInt(m[1], 10);
        const lon = -parseInt(m[2], 10);
        return { lat, lon };
    }

    function parseNatHalfDegreeCoordinate(token) {
        if (!token) return null;
        const s = String(token).toUpperCase().trim();
        if (s.length !== 5 || s[0] !== 'H') return null;
        const latPart = s.substring(1, 3);
        const lonPart = s.substring(3, 5);
        const lat = parseInt(latPart, 10) + 0.5;
        const lon = -(parseInt(lonPart, 10));
        return { lat, lon };
    }

    function parseIcaoSlashLatLon(token) {
        if (!token) return null;
        const s = String(token).toUpperCase().trim();
        const parts = s.split('/');
        if (parts.length !== 2) return null;
        const lat = parseLatComponent(parts[0]);
        const lon = parseLonComponent(parts[1]);
        if (lat === null || lon === null) return null;
        return { lat, lon };
    }

    function parseIcaoCompactLatLon(token) {
        if (!token) return null;
        const s = String(token).toUpperCase().trim();
        const m = s.match(/^(\d{2,4})([NS])(\d{3,5})([EW])$/);
        if (!m) return null;
        let latStr = m[1], latH = m[2], lonStr = m[3], lonH = m[4];
        let lat, lon;
        if (latStr.length === 2) lat = parseInt(latStr, 10);
        else if (latStr.length === 4) lat = parseInt(latStr.substring(0,2), 10) + parseInt(latStr.substring(2,4), 10)/60;
        else return null;
        if (lonStr.length === 3) lon = parseInt(lonStr, 10);
        else if (lonStr.length === 5) lon = parseInt(lonStr.substring(0,3), 10) + parseInt(lonStr.substring(3,5), 10)/60;
        else return null;
        if (latH === 'S') lat = -lat;
        if (lonH === 'W') lon = -lon;
        return { lat, lon };
    }

    function parseCoordinateToken(token) {
        if (!token) return null;
        const s = String(token).toUpperCase().trim();
        let coord = parseNatSlashCoordinate(s);
        if (coord) return coord;
        coord = parseNatHalfDegreeCoordinate(s);
        if (coord) return coord;
        coord = parseIcaoSlashLatLon(s);
        if (coord) return coord;
        coord = parseIcaoCompactLatLon(s);
        if (coord) return coord;
        coord = parseArincFiveCharCoordinate(s);
        if (coord) return coord;
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // POINT LOOKUP FUNCTIONS
    // ═══════════════════════════════════════════════════════════════════════════

    function getPointByName(pointName, previousPointData, nextPointData) {
        if (!pointName) return undefined;
        const name = String(pointName).toUpperCase();
        let key = null;

        if (name.startsWith('ZZ_')) {
            if (points[name]) key = name;
        } else if (facilityCodes.has(name)) {
            const zzKey = 'ZZ_' + name;
            if (points[zzKey]) key = zzKey;
            else if (points[name]) key = name;
            else if (areaCenters[name]) {
                const center = areaCenters[name];
                return confirmReasonableDistance([name, center.lat, center.lon], previousPointData, nextPointData);
            }
        } else {
            if (points[name]) key = name;
        }

        if (key && points[key]) {
            const pointList = points[key];
            if (pointList.length === 1) {
                return confirmReasonableDistance(pointList[0], previousPointData, nextPointData);
            }
            if (!previousPointData && !nextPointData) {
                return confirmReasonableDistance(pointList[0], previousPointData, nextPointData);
            }
            let centerPosition = previousPointData || nextPointData;
            if (previousPointData && nextPointData) {
                centerPosition = [centerPosition[0],
                    (previousPointData[1] + nextPointData[1]) / 2,
                    (previousPointData[2] + nextPointData[2]) / 2];
            }
            const errorMap = pointList.map(p => 
                Math.abs(centerPosition[1] - p[1]) + Math.abs(centerPosition[2] - p[2]));
            const idx = errorMap.indexOf(Math.min(...errorMap));
            return confirmReasonableDistance(pointList[idx], previousPointData, nextPointData);
        }

        const coord = parseCoordinateToken(name);
        if (coord) {
            return confirmReasonableDistance([name, coord.lat, coord.lon], previousPointData, nextPointData);
        }
        
        // Final fallback: check areaCenters for ARTCC/FIR/regional pseudo-fixes
        if (areaCenters[name]) {
            const center = areaCenters[name];
            return confirmReasonableDistance([name, center.lat, center.lon], previousPointData, nextPointData);
        }
        
        return undefined;
    }

    function countOccurrencesOfPointName(pointName) {
        if (!pointName) return 0;
        const name = String(pointName).toUpperCase();
        if (name.startsWith('ZZ_')) return points[name] ? points[name].length : 0;
        if (facilityCodes.has(name)) {
            if (points['ZZ_' + name]) return points['ZZ_' + name].length;
            if (points[name]) return points[name].length;
            return 0;
        }
        if (points[name]) return points[name].length;
        if (parseCoordinateToken(name)) return 1;
        return 0;
    }

    function isAirportIdent(pointId) {
        if (!pointId) return false;
        const code = String(pointId).toUpperCase();
        if (code.startsWith('ZZ_')) return true;
        if (code.length === 4) return true;
        if (areaCenters[code]) return true;
        return false;
    }

    // Detect facility type for endpoint icons (Airport, TRACON, ARTCC)
    function detectFacilityType(code) {
        if (!code) return 'airport';
        const c = String(code).toUpperCase().trim();
        // ARTCC: Z + 2 letters (ZNY, ZDC, ZTL, etc.)
        if (/^Z[A-Z]{2}$/.test(c)) return 'artcc';
        // TRACON: Letter + 2 digits (N90, A80, C90, etc.) or 3-letter codes like PCT, NCT, SCT
        if (/^[A-Z]\d{2}$/.test(c)) return 'tracon';
        if (['PCT', 'NCT', 'SCT', 'MIA', 'TPA', 'RSW', 'PBI', 'F11', 'M98'].includes(c)) return 'tracon';
        // Default to airport (4-letter ICAO codes)
        return 'airport';
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // AIRWAY INDEX
    // ═══════════════════════════════════════════════════════════════════════════

    function buildAirwayIndex() {
        if (awyIndexBuilt) return;
        if (typeof awys === 'undefined' || !Array.isArray(awys)) {
            console.warn('[MAPLIBRE] awys array not available');
            return;
        }
        const startTime = performance.now();
        for (let i = 0; i < awys.length; i++) {
            const row = awys[i];
            if (!Array.isArray(row) || row.length < 2 || !row[0] || !row[1]) continue;
            const airwayId = String(row[0]).toUpperCase();
            const fixes = String(row[1]).split(' ').filter(f => f !== '');
            const fixPosMap = {};
            for (let j = 0; j < fixes.length; j++) {
                const fix = fixes[j].toUpperCase();
                if (!(fix in fixPosMap)) fixPosMap[fix] = j;
            }
            awyIndexMap[airwayId] = { fixes, fixPosMap };
        }
        awyIndexBuilt = true;
        console.log('[MAPLIBRE] Airway index built:', Object.keys(awyIndexMap).length, 'airways in', 
                    (performance.now() - startTime).toFixed(1) + 'ms');
    }

    function ConvertRoute(route) {
        if (!route || typeof route !== 'string') return route;
        if (!awyIndexBuilt) buildAirwayIndex();
        
        const split_route = route.split(' ').filter(t => t !== '');
        if (split_route.length < 3) return route;

        const expandedTokens = [];
        for (let i = 0; i < split_route.length; i++) {
            const point = split_route[i];
            const pointUpper = point.toUpperCase();

            if (i > 0 && i < split_route.length - 1) {
                const prevTok = split_route[i - 1].toUpperCase();
                const nextTok = split_route[i + 1].toUpperCase();
                const airwayData = awyIndexMap[pointUpper];

                if (airwayData) {
                    const { fixes, fixPosMap } = airwayData;
                    const fromIdx = fixPosMap[prevTok];
                    const toIdx = fixPosMap[nextTok];

                    if (fromIdx !== undefined && toIdx !== undefined && Math.abs(fromIdx - toIdx) > 1) {
                        let middleFixes = fromIdx < toIdx 
                            ? fixes.slice(fromIdx + 1, toIdx)
                            : fixes.slice(toIdx + 1, fromIdx).reverse();
                        expandedTokens.push(...middleFixes);
                        continue;
                    }
                }
            }
            expandedTokens.push(point);
        }
        return expandedTokens.join(' ');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // PLAYBOOK FUNCTIONS
    // ═══════════════════════════════════════════════════════════════════════════

    function normalizePlayName(name) {
        return (name || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    function normalizePlayEndpointTokenForAirport(tok) {
        if (!tok) return null;
        let t = String(tok).toUpperCase().trim();
        if (!t) return null;

        // 4-char: assume ICAO (e.g. KJFK, CYYZ, EGLL)
        if (/^[A-Z0-9]{4}$/.test(t)) return t;

        // ARTCC style (ZDC, ZNY, etc) – do NOT add 'K'
        if (/^Z[A-Z0-9]{2}$/.test(t)) return t;

        // TRACON/facility codes like N90, A80, L30 – don't touch them
        if (/^[A-Z]\d{2}$/.test(t)) return t;

        // 3-letter non-Z tokens: treat as IATA and map to ICAO (BWI → KBWI)
        if (/^[A-Z]{3}$/.test(t) && !t.startsWith('Z')) return 'K' + t;

        return t;
    }

    function expandPlaybookDirective(bodyUpper, isMandatory, color) {
        if (!bodyUpper) return [];
        const parts = bodyUpper.split('.');
        const playPart = (parts[0] || '').trim();
        if (!playPart) return [];

        const playNorm = normalizePlayName(playPart);
        const originPart = parts.length > 1 ? parts[1].trim() : '';
        const destPart = parts.length > 2 ? parts[2].trim() : '';

        console.log('[MAPLIBRE] Playbook lookup:', {
            raw: bodyUpper,
            playPart: playPart,
            playNorm: playNorm,
            originPart: originPart,
            destPart: destPart
        });

        const originTokens = originPart ? originPart.toUpperCase().split(/\s+/).filter(Boolean) : [];
        const destTokens = destPart ? destPart.toUpperCase().split(/\s+/).filter(Boolean) : [];

        const out = [];
        const candidateRoutes = playbookByPlayName[playNorm];
        
        // Debug: show available play names if no match
        if (!candidateRoutes || !candidateRoutes.length) {
            console.warn('[MAPLIBRE] No playbook routes for normalized play:', playNorm);
            // Show similar play names for debugging
            const allPlays = Object.keys(playbookByPlayName);
            const similar = allPlays.filter(p => p.includes(playNorm.substring(0, 5))).slice(0, 5);
            console.log('[MAPLIBRE] Similar play names:', similar);
            return out;
        }
        
        console.log('[MAPLIBRE] Found', candidateRoutes.length, 'candidate routes for play:', playNorm);

        for (const pr of candidateRoutes) {
            if (originTokens.length) {
                let match = false;
                for (const tok of originTokens) {
                    const tokAirport = normalizePlayEndpointTokenForAirport(tok);
                    if ((pr.originAirportsSet && pr.originAirportsSet.has(tokAirport)) ||
                        (pr.originTraconsSet && pr.originTraconsSet.has(tok)) ||
                        (pr.originArtccsSet && pr.originArtccsSet.has(tok))) {
                        match = true; break;
                    }
                }
                if (!match) continue;
            }
            if (destTokens.length) {
                let match = false;
                for (const tok of destTokens) {
                    const tokAirport = normalizePlayEndpointTokenForAirport(tok);
                    if ((pr.destAirportsSet && pr.destAirportsSet.has(tokAirport)) ||
                        (pr.destTraconsSet && pr.destTraconsSet.has(tok)) ||
                        (pr.destArtccsSet && pr.destArtccsSet.has(tok))) {
                        match = true; break;
                    }
                }
                if (!match) continue;
            }

            let routeText = pr.fullRoute;
            if (isMandatory) {
                const tokens = routeText.split(/\s+/).filter(Boolean);
                if (tokens.length > 2) {
                    tokens[1] = '>' + tokens[1];
                    tokens[tokens.length - 2] = tokens[tokens.length - 2] + '<';
                    routeText = tokens.join(' ');
                } else {
                    routeText = '>' + routeText + '<';
                }
            }
            out.push(color ? routeText + ';' + color : routeText);
        }
        return out;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ADVISORY PARSING
    // ═══════════════════════════════════════════════════════════════════════════

    function splitAdvisoriesFromInput(upperText) {
        const regex = /VATCSCC ADVZY[\s\S]*?(?=VATCSCC ADVZY|$)/g;
        const blocks = [];
        let match;
        while ((match = regex.exec(upperText)) !== null) {
            const block = match[0].trim();
            if (block) blocks.push(block);
        }
        return blocks.length ? blocks : [upperText];
    }

    function parseAdvisoryRoutes(rawText) {
        const routes = [];
        const lines = rawText.split(/\r?\n/);
        let currentOrig = '', currentDest = '';
        
        for (const line of lines) {
            const trimmed = line.trim().toUpperCase();
            const odMatch = trimmed.match(/FROM\s+([A-Z0-9]+)\s+TO\s+([A-Z0-9]+)/);
            if (odMatch) {
                currentOrig = odMatch[1];
                currentDest = odMatch[2];
            }
            const rteMatch = trimmed.match(/REROUTE[:\s]+(.+)/);
            if (rteMatch && currentOrig && currentDest) {
                let rte = rteMatch[1].trim();
                if (!rte.startsWith(currentOrig)) rte = currentOrig + ' ' + rte;
                if (!rte.endsWith(currentDest)) rte = rte + ' ' + currentDest;
                routes.push(rte);
            }
        }
        return routes;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // GROUP EXPANSION
    // ═══════════════════════════════════════════════════════════════════════════

    function expandGroupsInRouteInput(text) {
        text = (text || '').toString();
        const lines = text.split(/\r?\n/);
        const outLines = [];
        let currentGroupColor = null;
        let currentGroupMandatory = false;

        lines.forEach(line => {
            const trimmed = (line || '').trim();
            
            // Blank line resets group context
            if (!trimmed) {
                currentGroupColor = null;
                currentGroupMandatory = false;
                return;
            }

            const headerMatch = trimmed.match(/^(>?)\s*\[([^\]]+)\]\s*(<?)\s*(?:;(.+))?$/i);
            if (headerMatch) {
                currentGroupColor = null;
                currentGroupMandatory = false;
                if (headerMatch[4]) currentGroupColor = headerMatch[4].trim().toUpperCase();
                if (headerMatch[1] === '>' || headerMatch[3] === '<') currentGroupMandatory = true;
                return;
            }

            if (trimmed.startsWith('[') && trimmed.endsWith(']')) {
                currentGroupColor = null;
                currentGroupMandatory = false;
                let inner = trimmed.slice(1, -1).trim();
                if (inner.includes('><')) {
                    currentGroupMandatory = true;
                    inner = inner.replace('><', '').trim();
                }
                const semiIdx = inner.indexOf(';');
                if (semiIdx !== -1) {
                    currentGroupColor = inner.slice(semiIdx + 1).trim().toUpperCase();
                }
                return;
            }

            let baseLine = trimmed;
            let colorSuffix = '';
            const semiIdx = baseLine.indexOf(';');
            if (semiIdx !== -1) {
                colorSuffix = baseLine.slice(semiIdx);
                baseLine = baseLine.slice(0, semiIdx).trim();
            }
            if (!colorSuffix && currentGroupColor) {
                colorSuffix = ';' + currentGroupColor;
            }
            let body = baseLine;
            if (currentGroupMandatory && !(body.startsWith('>') && body.endsWith('<'))) {
                body = '>' + body.trim() + '<';
            }
            outLines.push(body + (colorSuffix || ''));
        });

        return outLines.join('\n');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DATA LOADING
    // ═══════════════════════════════════════════════════════════════════════════

    $.ajax({
        type: 'GET',
        url: 'assets/data/points.csv',
        async: false
    }).done(function(data) {
        const lines = data.split('\n');
        for (const line of lines) {
            const parts = line.split(',');
            if (parts.length < 3) continue;
            const idRaw = (parts[0] || '').trim();
            const lat = parts[1], lon = parts[2];
            if (!idRaw) continue;
            const id = idRaw.toUpperCase();
            if (id.startsWith('ZZ_') && id.length > 3) {
                facilityCodes.add(id.substring(3));
            }
            if (!points[id]) points[id] = [];
            points[id].push([id, +lat, +lon]);
        }
        window.routePoints = points;
        console.log('[MAPLIBRE] Loaded points.csv:', Object.keys(points).length, 'fixes');
    }).fail(function(xhr, status, error) {
        console.error('[MAPLIBRE] Failed to load points.csv:', error);
    });

    $.ajax({
        type: 'GET',
        url: 'assets/data/cdrs.csv',
        async: false
    }).done(function(data) {
        const lines = data.split('\n');
        lines.forEach(line => {
            line = line.trim();
            if (!line) return;
            const idx = line.indexOf(',');
            if (idx <= 0) return;
            const code = line.slice(0, idx).trim().toUpperCase();
            const route = line.slice(idx + 1).trim();
            if (code && route) cdrMap[code] = route;
        });
        console.log('[MAPLIBRE] Loaded cdrs.csv:', Object.keys(cdrMap).length, 'CDRs');
        
        // Expose to global scope for PlaybookCDRSearch module
        window.cdrMap = cdrMap;
    }).fail(function(xhr, status, error) {
        console.error('[MAPLIBRE] Failed to load cdrs.csv:', error);
    });

    // Helper to parse CSV with quoted fields
    function parseCsvLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (ch === '"') {
                inQuotes = !inQuotes;
            } else if (ch === ',' && !inQuotes) {
                result.push(current.trim());
                current = '';
            } else {
                current += ch;
            }
        }
        result.push(current.trim());
        return result;
    }

    $.ajax({
        type: 'GET',
        url: 'assets/data/playbook_routes.csv',
        async: false
    }).done(function(data) {
        const lines = data.split(/\r?\n/);
        if (lines.length < 2) return;
        
        // Parse header to find column indices
        const header = parseCsvLine(lines[0]).map(h => h.toLowerCase().trim());
        
        function idxOf(...names) {
            for (const name of names) {
                const i = header.indexOf(name.toLowerCase());
                if (i !== -1) return i;
            }
            return -1;
        }
        
        const idxPlay = idxOf('play_name', 'play');
        const idxRoute = idxOf('full_route', 'route string', 'route', 'route_string');
        const idxOrigAirports = idxOf('origins', 'origin', 'origin_airports');
        const idxOrigTracons = idxOf('origin_tracons', 'origin_tracon');
        const idxOrigArtccs = idxOf('origin_artccs', 'origin_artcc');
        const idxDestAirports = idxOf('destinations', 'dest', 'dest_airports');
        const idxDestTracons = idxOf('dest_tracons', 'dest_tracon');
        const idxDestArtccs = idxOf('dest_artccs', 'dest_artcc');
        
        console.log('[MAPLIBRE] Playbook column indices:', { 
            play: idxPlay, route: idxRoute, 
            origAirports: idxOrigAirports, destAirports: idxDestAirports 
        });
        
        if (idxPlay === -1 || idxRoute === -1) {
            console.error('[MAPLIBRE] playbook_routes.csv missing required columns');
            return;
        }
        
        function splitField(str) {
            if (!str) return [];
            return String(str).toUpperCase().split(/\s+/).filter(Boolean);
        }
        
        for (let i = 1; i < lines.length; i++) {
            const line = lines[i].trim();
            if (!line) continue;
            
            const cols = parseCsvLine(line);
            const playNameRaw = (cols[idxPlay] || '').trim();
            const routeRaw = (cols[idxRoute] || '').trim();
            
            if (!playNameRaw || !routeRaw || playNameRaw.toLowerCase() === 'nan') continue;
            
            const origAirports = idxOrigAirports >= 0 ? splitField(cols[idxOrigAirports]) : [];
            const origTracons = idxOrigTracons >= 0 ? splitField(cols[idxOrigTracons]) : [];
            const origArtccs = idxOrigArtccs >= 0 ? splitField(cols[idxOrigArtccs]) : [];
            const destAirports = idxDestAirports >= 0 ? splitField(cols[idxDestAirports]) : [];
            const destTracons = idxDestTracons >= 0 ? splitField(cols[idxDestTracons]) : [];
            const destArtccs = idxDestArtccs >= 0 ? splitField(cols[idxDestArtccs]) : [];
            
            const entry = {
                playName: playNameRaw,
                playNameNorm: normalizePlayName(playNameRaw),
                fullRoute: routeRaw.toUpperCase(),
                originAirportsSet: new Set(origAirports),
                originTraconsSet: new Set(origTracons),
                originArtccsSet: new Set(origArtccs),
                destAirportsSet: new Set(destAirports),
                destTraconsSet: new Set(destTracons),
                destArtccsSet: new Set(destArtccs),
                // Keep arrays for PlaybookCDRSearch module compatibility
                originAirports: origAirports,
                originTracons: origTracons,
                originArtccs: origArtccs,
                destAirports: destAirports,
                destTracons: destTracons,
                destArtccs: destArtccs
            };
            
            playbookRoutes.push(entry);
            const pnorm = entry.playNameNorm;
            if (!playbookByPlayName[pnorm]) playbookByPlayName[pnorm] = [];
            playbookByPlayName[pnorm].push(entry);
        }
        
        console.log('[MAPLIBRE] Loaded', playbookRoutes.length, 'playbook routes');
        console.log('[MAPLIBRE] Unique play names:', Object.keys(playbookByPlayName).length);
        // Show first 10 play names for debugging
        console.log('[MAPLIBRE] Sample play names:', Object.keys(playbookByPlayName).slice(0, 10));
        
        // Expose to global scope for PlaybookCDRSearch module
        window.playbookRoutes = playbookRoutes;
        window.playbookByPlayName = playbookByPlayName;
    }).fail(function(xhr, status, error) {
        console.error('[MAPLIBRE] Failed to load playbook_routes.csv:', error);
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // MAP INITIALIZATION
    // ═══════════════════════════════════════════════════════════════════════════

    function initMap() {
        console.log('[MAPLIBRE] Initializing map...');
        
        if (graphic_map) {
            graphic_map.remove();
            graphic_map = null;
        }
        
        const container = document.getElementById('graphic');
        if (!container) {
            console.error('[MAPLIBRE] Map container #graphic not found');
            return;
        }

        graphic_map = new maplibregl.Map({
            container: 'graphic',
            style: {
                version: 8,
                name: 'PERTI Dark',
                sources: {
                    'carto-dark': {
                        type: 'raster',
                        tiles: [
                            'https://a.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png',
                            'https://b.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png',
                            'https://c.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png',
                            'https://d.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png'
                        ],
                        tileSize: 256,
                        attribution: '&copy; CARTO | Iowa State | Srinath Nandakumar'
                    }
                },
                layers: [{
                    id: 'carto-dark-layer',
                    type: 'raster',
                    source: 'carto-dark'
                }],
                glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf'
            },
            center: [-98.35, 39.5],
            zoom: 4,
            minZoom: 2,
            maxZoom: 12
        });

        graphic_map.addControl(new maplibregl.NavigationControl(), 'top-left');

        graphic_map.on('load', function() {
            console.log('[MAPLIBRE] Map loaded');
            mapReady = true;
            addStaticLayers();
            addSuaLayers();  // Add SUA/TFR layer (after boundaries, before routes/flights)
            addDynamicSources();
            setupLayerControl();
            setupInteractivity();
            
            window.graphic_map = graphic_map;
            $(document).trigger('maplibre:ready');
            
            const rawInput = $('#routeSearch').val();
            if (rawInput && rawInput.trim()) {
                processAndDisplayRoutes();
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // STATIC LAYERS - Order matches nod.js for consistency:
    // 1. Weather Radar (BOTTOM)
    // 2. TRACON Boundaries
    // 3. Sector Boundaries (High, Low, Superhigh)
    // 4. ARTCC Boundaries
    // 5. SIGMETs
    // 6. NAVAIDs
    // (Dynamic layers added on top by addDynamicSources/addSuaLayers)
    // ═══════════════════════════════════════════════════════════════════════════

    function addStaticLayers() {
        const emptyGeoJSON = { type: 'FeatureCollection', features: [] };

        // Helper to load GeoJSON data into an existing source
        function loadGeoJsonData(sourceId, url) {
            fetch(url)
                .then(response => {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('[MAPLIBRE] Loaded', sourceId, '- features:',
                        data.features ? data.features.length : 'N/A');
                    if (graphic_map.getSource(sourceId)) {
                        graphic_map.getSource(sourceId).setData(data);
                    }
                })
                .catch(err => {
                    console.error('[MAPLIBRE] Failed to load', sourceId, ':', err);
                });
        }

        // 1. Weather Radar (BOTTOM)
        graphic_map.addSource('weather-cells', {
            type: 'raster',
            tiles: ['https://mesonet.agron.iastate.edu/cache/tile.py/1.0.0/nexrad-n0q/{z}/{x}/{y}.png'],
            tileSize: 256,
            attribution: '© <a href="https://mesonet.agron.iastate.edu">Iowa Environmental Mesonet</a>'
        });
        graphic_map.addLayer({
            id: 'weather-cells-layer', type: 'raster', source: 'weather-cells',
            paint: { 'raster-opacity': 0.3 },
            layout: { 'visibility': 'none' }
        });
        setInterval(function() {
            if (graphic_map && graphic_map.getLayoutProperty('weather-cells-layer', 'visibility') === 'visible') {
                refreshWeatherRadarMaplibre();
            }
        }, 300000);
        console.log('[WX-MAPLIBRE] Iowa Mesonet NEXRAD radar initialized');

        // 2. TRACON Boundaries
        graphic_map.addSource('tracon', { type: 'geojson', data: emptyGeoJSON });
        graphic_map.addLayer({
            id: 'tracon-lines', type: 'line', source: 'tracon',
            paint: { 'line-color': '#303030', 'line-width': 1.5 },
            layout: { 'visibility': 'none' }
        });

        // 3. Sector Boundaries (High, Low, Superhigh)
        graphic_map.addSource('high-splits', { type: 'geojson', data: emptyGeoJSON });
        graphic_map.addLayer({
            id: 'high-splits-lines', type: 'line', source: 'high-splits',
            paint: { 'line-color': '#303030', 'line-width': 1.5 },
            layout: { 'visibility': 'none' }
        });

        graphic_map.addSource('low-splits', { type: 'geojson', data: emptyGeoJSON });
        graphic_map.addLayer({
            id: 'low-splits-lines', type: 'line', source: 'low-splits',
            paint: { 'line-color': '#303030', 'line-width': 1.5 },
            layout: { 'visibility': 'none' }
        });

        graphic_map.addSource('superhigh-splits', { type: 'geojson', data: emptyGeoJSON });
        graphic_map.addLayer({
            id: 'superhigh-splits-lines', type: 'line', source: 'superhigh-splits',
            paint: { 'line-color': '#303030', 'line-width': 1.5 },
            layout: { 'visibility': 'none' }
        });

        // 4. ARTCC Boundaries
        graphic_map.addSource('artcc', { type: 'geojson', data: emptyGeoJSON });
        graphic_map.addLayer({
            id: 'artcc-lines', type: 'line', source: 'artcc',
            paint: { 'line-color': '#515151', 'line-width': 1.5 }
        });

        // 5. SIGMETs
        graphic_map.addSource('sigmets', { type: 'geojson', data: emptyGeoJSON });
        graphic_map.addLayer({
            id: 'sigmets-fill', type: 'fill', source: 'sigmets',
            paint: { 'fill-color': '#d8da5b', 'fill-opacity': 0.1 },
            layout: { 'visibility': 'none' }
        });

        // 6. NAVAIDs
        graphic_map.addSource('navaids', { type: 'geojson', data: emptyGeoJSON });
        graphic_map.addLayer({
            id: 'navaids-circles', type: 'circle', source: 'navaids',
            paint: { 'circle-radius': 3, 'circle-color': '#ffffff', 'circle-opacity': 0.25 },
            layout: { 'visibility': 'none' }
        });

        // Load GeoJSON data asynchronously (layer order already set above)
        loadGeoJsonData('tracon', 'assets/geojson/tracon.json');
        loadGeoJsonData('high-splits', 'assets/geojson/high.json');
        loadGeoJsonData('low-splits', 'assets/geojson/low.json');
        loadGeoJsonData('superhigh-splits', 'assets/geojson/superhigh.json');
        loadGeoJsonData('artcc', 'assets/geojson/artcc.json');
        loadNavaidsData();

        console.log('[MAPLIBRE] Static layers added in nod.js order');
    }

    function loadNavaidsData() {
        $.ajax({ type: 'GET', url: 'assets/data/navaids.csv', async: true }).done(function(data) {
            const features = [];
            data.split('\n').forEach(line => {
                const parts = line.split(',');
                if (parts[0] && parts[1] && parts[2]) {
                    features.push({
                        type: 'Feature',
                        properties: { name: parts[0].trim() },
                        geometry: { type: 'Point', coordinates: [parseFloat(parts[2]), parseFloat(parts[1])] }
                    });
                }
            });
            if (graphic_map && graphic_map.getSource('navaids')) {
                graphic_map.getSource('navaids').setData({ type: 'FeatureCollection', features });
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // WEATHER RADAR (Iowa Environmental Mesonet NEXRAD)
    // ═══════════════════════════════════════════════════════════════════════════
    
    function refreshWeatherRadarMaplibre() {
        // IEM tiles update automatically on the server (~5 min)
        // Force browser to reload tiles by removing and re-adding the source
        if (graphic_map && graphic_map.getSource('weather-cells')) {
            var wasVisible = graphic_map.getLayoutProperty('weather-cells-layer', 'visibility') === 'visible';
            
            // Remove existing layer and source
            if (graphic_map.getLayer('weather-cells-layer')) {
                graphic_map.removeLayer('weather-cells-layer');
            }
            if (graphic_map.getSource('weather-cells')) {
                graphic_map.removeSource('weather-cells');
            }
            
            // Re-add source with cache-bust parameter
            var cacheBust = Date.now();
            graphic_map.addSource('weather-cells', {
                type: 'raster',
                tiles: ['https://mesonet.agron.iastate.edu/cache/tile.py/1.0.0/nexrad-n0q/{z}/{x}/{y}.png?_=' + cacheBust],
                tileSize: 256,
                attribution: '© <a href="https://mesonet.agron.iastate.edu">Iowa Environmental Mesonet</a>'
            });
            graphic_map.addLayer({
                id: 'weather-cells-layer',
                type: 'raster',
                source: 'weather-cells',
                paint: { 'raster-opacity': 0.3 },
                layout: { 'visibility': wasVisible ? 'visible' : 'none' }
            }, 'sigmets-fill'); // Insert before sigmets
            
            console.log('[WX-MAPLIBRE] Radar tiles refreshed');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SUA (Special Use Airspace) Layer
    // ═══════════════════════════════════════════════════════════════════════════
    
    // SUA color mapping by type
    const SUA_COLORS = {
        'TFR_VIP':   'magenta',
        'TFR_EMERG': 'red',
        'TFR_SEC':   'red',
        'TFR_HAZ':   '#FF8C00',  // DarkOrange
        'TFR_HID':   '#FF8C00',
        'TFR_EVT':   'cyan',
        'TFR_SPC':   '#40E0D0',  // Turquoise
        'TFR_HBP':   'yellow',
        'UASPG':     'purple',
        'P':         'magenta',
        'R':         'red',
        'W':         '#FF8C00',
        'A':         'yellow',
        'MOA':       '#9400D3',  // DarkViolet
        'NSA':       '#0066ff',  // Blue - National Security Area
        'ATCAA':     '#A0522D',  // Sienna
        'IR':        '#C19A6B',
        'VR':        '#C19A6B',
        'SR':        '#BDB76B',  // DarkKhaki
        'AR':        '#4682B4',  // SteelBlue
        'OTHER':     'gray'
    };
    
    // SUA line widths (TFRs get thicker outlines)
    const SUA_WEIGHTS = {
        'TFR_VIP': 3, 'TFR_EMERG': 3, 'TFR_SEC': 3, 'TFR_HAZ': 3, 
        'TFR_HID': 3, 'TFR_EVT': 3, 'TFR_SPC': 3, 'TFR_HBP': 3
    };
    
    let suaDataLoaded = false;
    let suaRefreshInterval = null;
    
    function addSuaLayers() {
        if (!graphic_map) return;
        
        // Add SUA source
        graphic_map.addSource('sua', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        // SUA fill layer
        graphic_map.addLayer({
            id: 'sua-fill',
            type: 'fill',
            source: 'sua',
            paint: {
                'fill-color': [
                    'match', ['get', 'suaType'],
                    'TFR_VIP', 'magenta',
                    'TFR_EMERG', 'red',
                    'TFR_SEC', 'red',
                    'TFR_HAZ', '#FF8C00',
                    'TFR_HID', '#FF8C00',
                    'TFR_EVT', 'cyan',
                    'TFR_SPC', '#40E0D0',
                    'TFR_HBP', 'yellow',
                    'UASPG', 'purple',
                    'P', 'magenta',
                    'R', 'red',
                    'W', '#FF8C00',
                    'A', 'yellow',
                    'MOA', '#9400D3',
                    'NSA', '#0066ff',
                    'ATCAA', '#A0522D',
                    'IR', '#C19A6B',
                    'VR', '#C19A6B',
                    'SR', '#BDB76B',
                    'AR', '#4682B4',
                    'gray'  // default
                ],
                'fill-opacity': 0.15
            },
            layout: { 'visibility': 'none' }
        });
        
        // SUA outline layer
        graphic_map.addLayer({
            id: 'sua-outline',
            type: 'line',
            source: 'sua',
            paint: {
                'line-color': [
                    'match', ['get', 'suaType'],
                    'TFR_VIP', 'magenta',
                    'TFR_EMERG', 'red',
                    'TFR_SEC', 'red',
                    'TFR_HAZ', '#FF8C00',
                    'TFR_HID', '#FF8C00',
                    'TFR_EVT', 'cyan',
                    'TFR_SPC', '#40E0D0',
                    'TFR_HBP', 'yellow',
                    'UASPG', 'purple',
                    'P', 'magenta',
                    'R', 'red',
                    'W', '#FF8C00',
                    'A', 'yellow',
                    'MOA', '#9400D3',
                    'NSA', '#0066ff',
                    'ATCAA', '#A0522D',
                    'IR', '#C19A6B',
                    'VR', '#C19A6B',
                    'SR', '#BDB76B',
                    'AR', '#4682B4',
                    'gray'
                ],
                'line-width': [
                    'match', ['get', 'suaType'],
                    'TFR_VIP', 3,
                    'TFR_EMERG', 3,
                    'TFR_SEC', 3,
                    'TFR_HAZ', 3,
                    'TFR_HID', 3,
                    'TFR_EVT', 3,
                    'TFR_SPC', 3,
                    'TFR_HBP', 3,
                    2  // default
                ],
                'line-opacity': 0.8
            },
            layout: { 'visibility': 'none' }
        });
        
        // Click handler for SUA popups
        graphic_map.on('click', 'sua-fill', function(e) {
            if (e.features && e.features.length > 0) {
                var props = e.features[0].properties;
                var content = '<div class="sua-popup" style="font-family: Inconsolata, monospace; font-size: 0.85rem;">';
                content += '<strong style="font-size: 1.1em; color: #239BCD;">' + (props.name || props.designator || 'Unknown SUA') + '</strong>';
                
                if (props.suaType) {
                    // Map type codes to readable names
                    var typeLabels = {
                        'P': 'Prohibited',
                        'R': 'Restricted',
                        'W': 'Warning',
                        'A': 'Alert',
                        'MOA': 'MOA',
                        'NSA': 'National Security Area',
                        'ATCAA': 'ATC Assigned',
                        'TFR_VIP': 'TFR: VIP',
                        'TFR_EMERG': 'TFR: Emergency',
                        'TFR_SEC': 'TFR: Security',
                        'TFR_HAZ': 'TFR: Hazard',
                        'TFR_HID': 'TFR: Hazard',
                        'TFR_EVT': 'TFR: Event',
                        'TFR_SPC': 'TFR: Space Ops',
                        'TFR_HBP': 'TFR: High Barometric'
                    };
                    var typeLabel = typeLabels[props.suaType] || props.suaType;
                    content += '<br><span style="color: #888; font-size: 0.85em;">' + typeLabel + '</span>';
                }
                
                if (props.notamId) {
                    content += '<br><span style="font-size: 0.85em;">NOTAM: ' + props.notamId + '</span>';
                }
                
                // Support both old (altLow/altHigh) and new (lowerLimit/upperLimit) property names
                var altLow = props.altLow || props.lowerLimit;
                var altHigh = props.altHigh || props.upperLimit;
                if (altLow || altHigh) {
                    content += '<br><span style="font-size: 0.85em;">ALT: ';
                    if (altLow) content += altLow;
                    if (altLow && altHigh) content += ' - ';
                    if (altHigh) content += altHigh;
                    content += '</span>';
                }
                
                if (props.schedule) {
                    content += '<br><span style="font-size: 0.8em; color: #666;">Schedule: ' + props.schedule + '</span>';
                }
                
                if (props.artcc) {
                    content += '<br><span style="font-size: 0.8em; color: #666;">ARTCC: ' + props.artcc + '</span>';
                }
                
                content += '</div>';
                
                new maplibregl.Popup({ closeButton: false, maxWidth: '300px' })
                    .setLngLat(e.lngLat)
                    .setHTML(content)
                    .addTo(graphic_map);
            }
        });
        
        // Cursor change on hover
        graphic_map.on('mouseenter', 'sua-fill', function() {
            graphic_map.getCanvas().style.cursor = 'pointer';
        });
        graphic_map.on('mouseleave', 'sua-fill', function() {
            graphic_map.getCanvas().style.cursor = '';
        });
    }
    
    function loadSuaData() {
        if (suaDataLoaded) return;
        
        console.log('[SUA-MAPLIBRE] Fetching SUA data...');
        $.ajax({
            type: 'GET',
            url: 'api/data/sua',
            dataType: 'json',
            timeout: 60000
        }).done(function(data) {
            if (data && data.features && graphic_map && graphic_map.getSource('sua')) {
                graphic_map.getSource('sua').setData(data);
                suaDataLoaded = true;
                console.log('[SUA-MAPLIBRE] Loaded ' + data.features.length + ' SUA features');
            } else {
                console.warn('[SUA-MAPLIBRE] No SUA features returned or source not ready');
            }
        }).fail(function(xhr, status, error) {
            console.warn('[SUA-MAPLIBRE] Failed to fetch SUA data:', error);
        });
    }
    
    function startSuaRefresh() {
        if (suaRefreshInterval) return;
        suaRefreshInterval = setInterval(function() {
            if (graphic_map && 
                graphic_map.getLayoutProperty('sua-fill', 'visibility') === 'visible') {
                suaDataLoaded = false;
                loadSuaData();
            }
        }, 600000); // 10 minutes
    }
    
    function stopSuaRefresh() {
        if (suaRefreshInterval) {
            clearInterval(suaRefreshInterval);
            suaRefreshInterval = null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DYNAMIC SOURCES
    // ═══════════════════════════════════════════════════════════════════════════

    function addDynamicSources() {
        graphic_map.addSource('filtered-airways', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        graphic_map.addLayer({
            id: 'filtered-airways-lines', type: 'line', source: 'filtered-airways',
            paint: { 'line-color': '#ffffff', 'line-width': 1, 'line-opacity': 0.25 }
        });
        graphic_map.addLayer({
            id: 'filtered-airways-labels', type: 'symbol', source: 'filtered-airways',
            layout: { 'symbol-placement': 'line', 'text-field': ['get', 'name'], 'text-size': 10 },
            paint: { 'text-color': '#ffffff', 'text-halo-color': '#000000', 'text-halo-width': 1, 'text-opacity': 0.5 }
        });

        graphic_map.addSource('routes', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        graphic_map.addLayer({
            id: 'routes-solid', type: 'line', source: 'routes',
            filter: ['all', ['==', ['get', 'solid'], true], ['!=', ['get', 'isFan'], true]],
            paint: { 'line-color': ['get', 'color'], 'line-width': 3 }
        });
        graphic_map.addLayer({
            id: 'routes-dashed', type: 'line', source: 'routes',
            filter: ['all', ['==', ['get', 'solid'], false], ['!=', ['get', 'isFan'], true]],
            paint: { 'line-color': ['get', 'color'], 'line-width': 3, 'line-dasharray': [4, 4] }
        });
        graphic_map.addLayer({
            id: 'routes-fan', type: 'line', source: 'routes',
            filter: ['==', ['get', 'isFan'], true],
            paint: { 'line-color': ['get', 'color'], 'line-width': 1.5, 'line-dasharray': [1, 3] }
        });

        graphic_map.addSource('fixes', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        graphic_map.addLayer({
            id: 'fixes-circles', type: 'circle', source: 'fixes',
            paint: {
                'circle-radius': ['interpolate', ['linear'], ['zoom'], 4, 2, 8, 4, 12, 6],
                'circle-color': ['get', 'color'],
                'circle-stroke-width': 1, 'circle-stroke-color': '#000000'
            },
            minzoom: 5
        });

        graphic_map.addSource('airports', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        graphic_map.addLayer({
            id: 'airports-triangles', type: 'symbol', source: 'airports',
            layout: { 'text-field': '▲', 'text-size': 14, 'text-allow-overlap': true },
            paint: { 'text-color': '#ffffff' }
        });

        // Flight routes source - added BEFORE aircraft so routes draw underneath
        graphic_map.addSource('flight-routes', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        graphic_map.addLayer({
            id: 'flight-routes-behind', type: 'line', source: 'flight-routes',
            filter: ['==', ['get', 'segment'], 'behind'],
            paint: { 'line-color': ['get', 'color'], 'line-width': 2.5 }
        });
        graphic_map.addLayer({
            id: 'flight-routes-ahead', type: 'line', source: 'flight-routes',
            filter: ['==', ['get', 'segment'], 'ahead'],
            paint: { 'line-color': ['get', 'color'], 'line-width': 2.5, 'line-dasharray': [4, 4] }
        });
        
        // ADL Flight Waypoints - TSD style with labels (separate from route-fixes)
        graphic_map.addSource('adl-flight-fixes', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        graphic_map.addLayer({
            id: 'adl-flight-fixes-circles', type: 'circle', source: 'adl-flight-fixes',
            paint: {
                'circle-radius': [
                    'interpolate', ['linear'], ['zoom'],
                    4, 1.5,
                    8, 2.5,
                    12, 3.5
                ],
                'circle-color': ['get', 'color'],
                'circle-stroke-width': 0.5,
                'circle-stroke-color': '#222222',
                'circle-opacity': 0.8
            },
            minzoom: 5
        });
        graphic_map.addLayer({
            id: 'adl-flight-fixes-labels', type: 'symbol', source: 'adl-flight-fixes',
            layout: {
                'text-field': ['get', 'name'],
                'text-font': ['Noto Sans Bold'],
                'text-size': 9,
                'text-anchor': 'bottom-left',
                'text-offset': [0.3, -0.2],
                'text-allow-overlap': false,
                'text-optional': true
            },
            paint: {
                'text-color': ['get', 'color'],
                'text-halo-color': '#000000',
                'text-halo-width': 2
            },
            minzoom: 6
        });
        
        // ═══════════════════════════════════════════════════════════════════════════
        // ORIGIN/DESTINATION ENDPOINT ICONS
        // Separate icons for airports (triangle), TRACONs (diamond), ARTCCs (square)
        // ═══════════════════════════════════════════════════════════════════════════
        
        graphic_map.addSource('route-endpoints', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        
        // Create endpoint icons
        const ENDPOINT_ICONS = {
            // Airport - Triangle (pointing up for origin, down for destination)
            'airport-origin': `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20">
                <polygon points="10,2 18,18 2,18" fill="white" stroke="white" stroke-width="1"/>
            </svg>`,
            'airport-dest': `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20">
                <polygon points="2,2 18,2 10,18" fill="white" stroke="white" stroke-width="1"/>
            </svg>`,
            // TRACON - Diamond
            'tracon-origin': `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20">
                <polygon points="10,1 19,10 10,19 1,10" fill="white" stroke="white" stroke-width="1"/>
            </svg>`,
            'tracon-dest': `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20">
                <polygon points="10,1 19,10 10,19 1,10" fill="none" stroke="white" stroke-width="2"/>
            </svg>`,
            // ARTCC - Square
            'artcc-origin': `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20">
                <rect x="2" y="2" width="16" height="16" fill="white" stroke="white" stroke-width="1"/>
            </svg>`,
            'artcc-dest': `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20">
                <rect x="2" y="2" width="16" height="16" fill="none" stroke="white" stroke-width="2"/>
            </svg>`
        };
        
        // Load endpoint icons
        Object.entries(ENDPOINT_ICONS).forEach(([name, svg]) => {
            const img = new Image();
            img.onload = () => {
                if (!graphic_map.hasImage(`endpoint-${name}`)) {
                    graphic_map.addImage(`endpoint-${name}`, img, { sdf: true });
                }
            };
            img.onerror = (e) => console.error(`[MAPLIBRE] Failed to load endpoint icon ${name}:`, e);
            img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
        });
        
        // Endpoint symbols layer with icon selection by facility type and endpoint type
        graphic_map.addLayer({
            id: 'route-endpoints-symbols', type: 'symbol', source: 'route-endpoints',
            layout: {
                'icon-image': [
                    'case',
                    // Origin icons (filled)
                    ['all', ['==', ['get', 'isOrigin'], true], ['==', ['get', 'facilityType'], 'airport']], 'endpoint-airport-origin',
                    ['all', ['==', ['get', 'isOrigin'], true], ['==', ['get', 'facilityType'], 'tracon']], 'endpoint-tracon-origin',
                    ['all', ['==', ['get', 'isOrigin'], true], ['==', ['get', 'facilityType'], 'artcc']], 'endpoint-artcc-origin',
                    // Destination icons (outline)
                    ['all', ['==', ['get', 'isDest'], true], ['==', ['get', 'facilityType'], 'airport']], 'endpoint-airport-dest',
                    ['all', ['==', ['get', 'isDest'], true], ['==', ['get', 'facilityType'], 'tracon']], 'endpoint-tracon-dest',
                    ['all', ['==', ['get', 'isDest'], true], ['==', ['get', 'facilityType'], 'artcc']], 'endpoint-artcc-dest',
                    // Default to airport
                    'endpoint-airport-origin'
                ],
                'icon-size': [
                    'interpolate', ['linear'], ['zoom'],
                    4, 0.6,
                    8, 0.8,
                    12, 1.0
                ],
                'icon-allow-overlap': true,
                'icon-ignore-placement': true
            },
            paint: {
                'icon-color': ['get', 'color'],
                'icon-halo-color': '#000000',
                'icon-halo-width': 1
            },
            minzoom: 4
        });
        
        // Endpoint labels
        graphic_map.addLayer({
            id: 'route-endpoints-labels', type: 'symbol', source: 'route-endpoints',
            layout: {
                'text-field': ['get', 'name'],
                'text-font': ['Noto Sans Bold'],
                'text-size': 10,
                'text-anchor': 'top',
                'text-offset': [0, 0.8],
                'text-allow-overlap': false,
                'text-optional': true
            },
            paint: {
                'text-color': ['get', 'color'],
                'text-halo-color': '#000000',
                'text-halo-width': 2
            },
            minzoom: 5
        });

        // Aircraft source
        graphic_map.addSource('aircraft', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        
        // ═══════════════════════════════════════════════════════════════════════════
        // TSD-STYLE AIRCRAFT SYMBOLOGY - Single jet icon for all aircraft
        // Simplified from FSM Table 3-6 weight class variants
        // ═══════════════════════════════════════════════════════════════════════════
        
        // Create SDF-compatible jet icon (white fill for proper color tinting)
        const TSD_ICONS = {
            // Jet - Standard aircraft silhouette (used for all aircraft)
            jet: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">
                <path fill="white" d="M21,16V14L13,9V3.5A1.5,1.5 0 0,0 11.5,2A1.5,1.5 0 0,0 10,3.5V9L2,14V16L10,13.5V19L8,20.5V22L11.5,21L15,22V20.5L13,19V13.5L21,16Z"/>
            </svg>`
        };
        
        // Load jet icon as SDF image
        const jetSvg = TSD_ICONS.jet;
        const jetImg = new Image();
        jetImg.onload = () => {
            if (!graphic_map.hasImage('tsd-jet')) {
                graphic_map.addImage('tsd-jet', jetImg, { sdf: true });
                console.log('[MAPLIBRE] TSD jet icon loaded');
                // Hide fallback layer once icon is ready
                if (graphic_map.getLayer('aircraft-circles-fallback')) {
                    graphic_map.setLayoutProperty('aircraft-circles-fallback', 'visibility', 'none');
                }
            }
        };
        jetImg.onerror = (e) => console.error('[MAPLIBRE] Failed to load jet icon:', e);
        jetImg.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(jetSvg);
        
        // Aircraft symbol layer - uniform jet icon for all aircraft
        graphic_map.addLayer({
            id: 'aircraft-symbols', type: 'symbol', source: 'aircraft',
            layout: {
                'icon-image': 'tsd-jet',
                'icon-size': [
                    'interpolate', ['linear'], ['zoom'],
                    3, 0.5,
                    6, 0.7,
                    10, 1.0,
                    14, 1.2
                ],
                'icon-rotate': ['get', 'heading'],
                'icon-rotation-alignment': 'map',
                'icon-allow-overlap': true,
                'icon-ignore-placement': true
            },
            paint: {
                'icon-color': ['get', 'color'],
                'icon-halo-color': '#000000',
                'icon-halo-width': 1
            }
        });
        
        // Fallback circle layer (visible while icon loads)
        graphic_map.addLayer({
            id: 'aircraft-circles-fallback', type: 'circle', source: 'aircraft',
            paint: {
                'circle-radius': [
                    'interpolate', ['linear'], ['zoom'],
                    3, 3,
                    8, 5
                ],
                'circle-color': ['get', 'color'],
                'circle-stroke-width': 1,
                'circle-stroke-color': '#000000'
            }
        });
        
        // ═══════════════════════════════════════════════════════════════════════════
        // ROUTE FIX LABELS (per QGIS Route_Labels style)
        // Small circles at fix points with labeled boxes matching route color
        // Style: Black background rectangle with colored text (per QGIS Route_Labels.qml)
        // 
        // Architecture: Three separate sources to allow independent label movement:
        //   1. route-fix-points - circles at original positions (never move)
        //   2. route-fix-labels - text labels (can be dragged)
        //   3. route-fix-leaders - lines connecting moved labels to their points
        // ═══════════════════════════════════════════════════════════════════════════
        
        // Route fix POINTS source (circles at original positions - never move)
        graphic_map.addSource('route-fix-points', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        
        // Route fix LABELS source (text that can be dragged)
        graphic_map.addSource('route-fix-labels', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        
        // Route fix LEADERS source (lines from moved labels to points)
        graphic_map.addSource('route-fix-leaders', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        
        // Legacy source name for compatibility
        graphic_map.addSource('route-fixes', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        
        // Leader lines (drawn first so they appear behind labels)
        graphic_map.addLayer({
            id: 'route-fix-leaders-lines', type: 'line', source: 'route-fix-leaders',
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 1,
                'line-opacity': 0.7,
                'line-dasharray': [2, 2]
            },
            minzoom: 5
        });
        
        // Route fix circles (small dots at each waypoint - ALWAYS at original position)
        graphic_map.addLayer({
            id: 'route-fixes-circles', type: 'circle', source: 'route-fix-points',
            paint: {
                'circle-radius': [
                    'interpolate', ['linear'], ['zoom'],
                    4, 2,
                    8, 3,
                    12, 4
                ],
                'circle-color': ['get', 'color'],
                'circle-stroke-width': 1,
                'circle-stroke-color': '#222222'
            },
            minzoom: 5
        });
        
        // Route fix labels - QGIS style: black background box, colored text
        // Uses text-variable-anchor to try multiple positions to avoid route lines
        graphic_map.addLayer({
            id: 'route-fixes-labels', type: 'symbol', source: 'route-fix-labels',
            layout: {
                'text-field': ['get', 'name'],
                'text-font': ['Noto Sans Bold'],
                'text-size': [
                    'interpolate', ['linear'], ['zoom'],
                    5, 9,
                    8, 10,
                    12, 11,
                    16, 12
                ],
                'text-transform': 'uppercase',
                // Try multiple anchor positions to avoid overlapping route lines
                'text-variable-anchor': ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'top', 'bottom', 'left', 'right'],
                'text-radial-offset': 0.5,
                'text-justify': 'auto',
                'text-allow-overlap': false,
                'text-ignore-placement': false,
                'text-optional': true,
                'text-padding': 6,
                'text-max-width': 50  // Prevent wrapping
            },
            paint: {
                // Text color matches route color
                'text-color': ['get', 'color'],
                // Black halo creates the solid background box effect
                'text-halo-color': '#000000',
                'text-halo-width': 3,
                'text-halo-blur': 0
            },
            minzoom: 5
        });
        
        console.log('[MAPLIBRE] Dynamic sources added with TSD symbology');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // LAYER CONTROL
    // ═══════════════════════════════════════════════════════════════════════════

    const layerConfig = {
        'superhigh_splits': { layerIds: ['superhigh-splits-lines'], label: 'Superhigh Splits' },
        'high_splits': { layerIds: ['high-splits-lines'], label: 'High Splits' },
        'low_splits': { layerIds: ['low-splits-lines'], label: 'Low Splits' },
        'tracon': { layerIds: ['tracon-lines'], label: 'TRACON Boundaries' },
        'navaids': { layerIds: ['navaids-circles'], label: 'All NAVAIDs' },
        'cells': { layerIds: ['weather-cells-layer'], label: 'WX Radar' },
        'sigmets': { layerIds: ['sigmets-fill'], label: 'SIGMETs' },
        'sua': { layerIds: ['sua-fill', 'sua-outline'], label: 'Active SUA/TFR', onEnable: loadSuaData },
        'route_labels': { layerIds: ['route-fixes-circles', 'route-fixes-labels', 'route-fix-leaders-lines'], label: 'Route Fix Labels', defaultOn: true }
    };

    let layerControlExpanded = false;

    function setupLayerControl() {
        // Build overlay list - include items with defaultOn set
        Object.entries(layerConfig).forEach(([key, cfg]) => {
            if (cfg.defaultOn && !overlays.includes(key)) {
                overlays.push(key);
            }
        });
        
        const html = `
            <div class="maplibre-layer-control" id="maplibre-layer-control" style="
                position: absolute; top: 10px; right: 10px; z-index: 999;
                font-size: 12px;
            ">
                <button id="layer-control-toggle" style="
                    background: white; border: none; padding: 6px 10px; border-radius: 4px;
                    box-shadow: 0 1px 5px rgba(0,0,0,0.4); cursor: pointer;
                    font-size: 12px; font-weight: bold;
                " title="Toggle Overlays">
                    <i class="fas fa-layer-group"></i> Overlays
                </button>
                <div id="layer-control-panel" style="
                    display: none; background: white; padding: 10px; border-radius: 4px;
                    box-shadow: 0 1px 5px rgba(0,0,0,0.4); margin-top: 5px;
                ">
                    ${Object.entries(layerConfig).map(([key, cfg]) => `
                        <label style="display: block; cursor: pointer; margin: 3px 0;">
                            <input type="checkbox" data-layer="${key}" ${overlays.includes(key) || cfg.defaultOn ? 'checked' : ''}>
                            ${cfg.label}
                        </label>
                    `).join('')}
                </div>
            </div>
        `;
        $('#map_wrapper').append(html);
        
        // Toggle panel visibility
        $('#layer-control-toggle').on('click', function(e) {
            e.stopPropagation();
            layerControlExpanded = !layerControlExpanded;
            $('#layer-control-panel').slideToggle(150);
        });
        
        // Close panel when clicking outside
        $(document).on('click', function(e) {
            if (layerControlExpanded && !$(e.target).closest('#maplibre-layer-control').length) {
                layerControlExpanded = false;
                $('#layer-control-panel').slideUp(150);
            }
        });
        
        // Layer toggle handlers
        $('.maplibre-layer-control input[type="checkbox"]').on('change', function() {
            toggleLayer($(this).data('layer'), $(this).prop('checked'));
        });
    }

    function toggleLayer(layerKey, visible) {
        console.log('[DEBUG-LABELS] toggleLayer() called - key:', layerKey, 'visible:', visible);
        const cfg = layerConfig[layerKey];
        if (!cfg) {
            console.warn('[DEBUG-LABELS] No config found for layerKey:', layerKey);
            return;
        }
        console.log('[DEBUG-LABELS] Layer IDs to toggle:', cfg.layerIds);
        cfg.layerIds.forEach(layerId => {
            if (graphic_map.getLayer(layerId)) {
                graphic_map.setLayoutProperty(layerId, 'visibility', visible ? 'visible' : 'none');
                console.log('[DEBUG-LABELS] Set', layerId, 'visibility to:', visible ? 'visible' : 'none');
            } else {
                console.warn('[DEBUG-LABELS] Layer NOT FOUND:', layerId);
            }
        });
        
        // Handle SUA layer enable/disable
        if (layerKey === 'sua') {
            if (visible) {
                loadSuaData();
                startSuaRefresh();
            } else {
                stopSuaRefresh();
            }
        }
        
        // Call onEnable callback if defined
        if (visible && cfg.onEnable && typeof cfg.onEnable === 'function') {
            cfg.onEnable();
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ROUTE PROCESSING AND DISPLAY
    // ═══════════════════════════════════════════════════════════════════════════

    function processAndDisplayRoutes() {
        if (!mapReady || !graphic_map) {
            console.warn('[MAPLIBRE] processAndDisplayRoutes called but map not ready');
            return;
        }

        const rawInput = $('#routeSearch').val() || '';
        console.log('[MAPLIBRE] Processing routes, input length:', rawInput.length);
        
        const routeFeatures = [], fixFeatures = [], airportFeatures = [];
        const endpointFeatures = [];  // For origin/destination icons
        const seenFixes = new Set(), seenAirports = new Set();
        const seenEndpoints = new Set();  // Deduplicate endpoints
        seenSegmentKeys = new Set();
        
        // Phase 5: Reset route tracking
        routeLabelsVisible.clear();
        routeFixesByRouteId = {};
        currentRouteId = 0;
        labelOffsets = {};  // Clear any dragged label positions

        let routeStrings = [];

        if (/VATCSCC ADVZY/i.test(rawInput)) {
            splitAdvisoriesFromInput(rawInput.toUpperCase()).forEach(block => {
                const advRoutes = parseAdvisoryRoutes(block);
                if (advRoutes) routeStrings = routeStrings.concat(advRoutes);
            });
        } else {
            const expanded = expandGroupsInRouteInput(rawInput);
            expanded.split(/\r?\n/).forEach(line => {
                const trimmed = line.trim();
                if (!trimmed) return;

                let body = trimmed, color = null;
                const semiIdx = trimmed.indexOf(';');
                if (semiIdx !== -1) {
                    body = trimmed.slice(0, semiIdx).trim();
                    color = trimmed.slice(semiIdx + 1).trim().toUpperCase();
                }

                let isMandatory = false, spec = body;
                if (spec.startsWith('>') && spec.endsWith('<')) {
                    isMandatory = true;
                    spec = spec.slice(1, -1).trim();
                }

                const specUpper = spec.toUpperCase();
                if (specUpper.startsWith('PB.')) {
                    const pbDirective = specUpper.slice(3).trim();
                    console.log('[MAPLIBRE] Expanding playbook directive:', pbDirective);
                    const pbRoutes = expandPlaybookDirective(pbDirective, isMandatory, color);
                    console.log('[MAPLIBRE] Playbook returned', pbRoutes ? pbRoutes.length : 0, 'routes');
                    if (pbRoutes) routeStrings = routeStrings.concat(pbRoutes);
                } else {
                    let rteBody = spec.toUpperCase();
                    if (isMandatory && !(rteBody.startsWith('>') && rteBody.endsWith('<'))) {
                        rteBody = '>' + rteBody + '<';
                    }
                    routeStrings.push(color ? rteBody + ';' + color : rteBody);
                }
            });
        }

        console.log('[MAPLIBRE] Parsed routeStrings:', routeStrings.length, routeStrings.slice(0, 5));

        // Array to collect expanded routes (after CDR expansion) for public routes feature
        const expandedRoutesCollector = [];

        routeStrings.forEach((rte, idx) => {
            if (!rte || rte.trim() === '') return;
            rte = rte.replace(/\s+/g, ' ').trim();
            
            console.log('[MAPLIBRE] Processing route #' + idx + ':', (rte || '').substring(0, 50));
            
            // Phase 5: Assign unique route ID
            const thisRouteId = ++currentRouteId;
            routeFixesByRouteId[thisRouteId] = [];

            let routeColor = '#C70039', routeText = rte;
            if (rte.includes(';')) {
                const parts = rte.split(';');
                routeText = parts[0].trim();
                if (parts[1]) routeColor = parts[1].trim();
            }

            // CDR expansion - strip mandatory markers before lookup
            const cdrTokens = routeText.split(/\s+/).filter(t => t !== '');
            // For CDR lookup, strip the >< markers
            const cdrTokensClean = cdrTokens.map(t => t.replace(/[<>]/g, '').toUpperCase());
            
            if (cdrTokensClean.length === 1 && cdrMap[cdrTokensClean[0]]) {
                console.log('[MAPLIBRE] CDR expanded:', cdrTokensClean[0], '->', cdrMap[cdrTokensClean[0]]);
                // Preserve mandatory markers on the expanded route
                const hadOpenMarker = cdrTokens[0].includes('>');
                const hadCloseMarker = cdrTokens[0].includes('<');
                let expanded = cdrMap[cdrTokensClean[0]];
                if (hadOpenMarker || hadCloseMarker) {
                    // Apply mandatory markers to entire expanded route
                    const expTokens = expanded.split(/\s+/).filter(t => t);
                    if (expTokens.length > 0) {
                        if (hadOpenMarker) expTokens[0] = '>' + expTokens[0];
                        if (hadCloseMarker) expTokens[expTokens.length - 1] = expTokens[expTokens.length - 1] + '<';
                        expanded = expTokens.join(' ');
                    }
                }
                routeText = expanded;
            } else if (cdrTokensClean.length > 1) {
                const expandedTokens = [];
                cdrTokens.forEach((tok, i) => {
                    const code = cdrTokensClean[i];
                    if (cdrMap[code]) {
                        expandedTokens.push(...cdrMap[code].toUpperCase().split(/\s+/).filter(t => t !== ''));
                    } else {
                        expandedTokens.push(tok.toUpperCase());  // Keep original with markers
                    }
                });
                routeText = expandedTokens.join(' ');
            }
            
            console.log('[MAPLIBRE] After CDR expansion, routeText:', (routeText || '').substring(0, 80));
            
            // Collect expanded route for public routes feature (includes CDR expansion with airports)
            expandedRoutesCollector.push(routeText);

            // Parse solid/dashed markers
            const rawTokens = routeText.split(' ').filter(t => t !== '');
            const origTokensClean = [], solidMask = [];
            let insideSolid = false;

            rawTokens.forEach(tok => {
                const cleanTok = tok.replace(/[<>]/g, '');
                if (!cleanTok) return;
                const hasOpen = tok.indexOf('>') !== -1, hasClose = tok.indexOf('<') !== -1;
                let solid;
                if (hasOpen && hasClose) solid = true;
                else if (hasOpen) { insideSolid = true; solid = true; }
                else if (hasClose) { solid = true; insideSolid = false; }
                else solid = insideSolid;
                origTokensClean.push(cleanTok.toUpperCase());
                solidMask.push(solid);
            });

            if (origTokensClean.length === 0) return;

            let tokens = origTokensClean.slice();
            let currentSolidMask = solidMask.slice();

            if (typeof preprocessRouteProcedures === 'function') {
                const procInfo = preprocessRouteProcedures(tokens);
                if (procInfo && procInfo.tokens) tokens = procInfo.tokens;
            }
            if (typeof expandRouteProcedures === 'function') {
                const expanded = expandRouteProcedures(tokens, currentSolidMask);
                if (expanded && expanded.tokens) {
                    tokens = expanded.tokens;
                    currentSolidMask = expanded.solidMask || currentSolidMask;
                }
            }

            const expandedRouteString = ConvertRoute(tokens.join(' '));
            const expandedTokens = expandedRouteString.split(' ').filter(t => t !== '');

            const expandedToOriginalIdx = new Array(expandedTokens.length).fill(null);
            let searchStart = 0;
            for (let t = 0; t < expandedTokens.length; t++) {
                for (let j = searchStart; j < tokens.length; j++) {
                    if (tokens[j] === expandedTokens[t]) {
                        expandedToOriginalIdx[t] = j;
                        searchStart = j + 1;
                        break;
                    }
                }
            }

            const solidExpanded = expandedTokens.map((_, t) => {
                const oi = expandedToOriginalIdx[t];
                return (oi !== null && currentSolidMask[oi]) || false;
            });

            for (let idx = 0; idx < expandedTokens.length; ) {
                if (expandedToOriginalIdx[idx] !== null) { idx++; continue; }
                const runStart = idx;
                while (idx < expandedTokens.length && expandedToOriginalIdx[idx] === null) idx++;
                let before = runStart - 1;
                while (before >= 0 && expandedToOriginalIdx[before] === null) before--;
                let after = idx;
                while (after < expandedTokens.length && expandedToOriginalIdx[after] === null) after++;
                let runSolid = false;
                if (before >= 0 && after < expandedTokens.length) runSolid = solidExpanded[before] && solidExpanded[after];
                else if (before >= 0) runSolid = solidExpanded[before];
                else if (after < expandedTokens.length) runSolid = solidExpanded[after];
                for (let k = runStart; k < idx; k++) solidExpanded[k] = runSolid;
            }

            const routePoints = [], routeExpandedIndex = [];
            let previousPointData;

            for (let i = 0; i < expandedTokens.length; i++) {
                const pointName = expandedTokens[i].toUpperCase();
                let nextPointData;
                if (i < expandedTokens.length - 1) {
                    let dataForCurrentFix;
                    if (countOccurrencesOfPointName(pointName) === 1) dataForCurrentFix = getPointByName(pointName);
                    nextPointData = getPointByName(expandedTokens[i + 1], previousPointData, dataForCurrentFix);
                }

                if (pointName.length === 6 && /\d/.test(pointName)) {
                    const root = pointName.slice(0, -1);
                    const rootData = getPointByName(root, previousPointData, nextPointData);
                    if (rootData && rootData.length >= 3) {
                        previousPointData = rootData;
                        routePoints.push(rootData);
                        routeExpandedIndex.push(i);
                        continue;
                    }
                }

                const pointData = getPointByName(pointName, previousPointData, nextPointData);
                if (!pointData || pointData.length < 3) {
                    if (areaCenters[pointName]) {
                        const center = areaCenters[pointName];
                        const areaPointData = [pointName, center.lat, center.lon];
                        previousPointData = areaPointData;
                        routePoints.push(areaPointData);
                        routeExpandedIndex.push(i);
                    }
                    continue;
                }

                previousPointData = pointData;
                routePoints.push(pointData);
                routeExpandedIndex.push(i);

                const [id, lat, lon] = pointData;
                if (!isAirportIdent(id)) {
                    const fixKey = id + '|' + lat.toFixed(4) + '|' + lon.toFixed(4);
                    if (!seenFixes.has(fixKey)) {
                        seenFixes.add(fixKey);
                        const fixFeature = {
                            type: 'Feature',
                            properties: { 
                                name: id, 
                                color: routeColor,
                                routeId: thisRouteId,
                                lat: lat.toFixed(4),
                                lon: lon.toFixed(4)
                            },
                            geometry: { type: 'Point', coordinates: [lon, lat] }
                        };
                        fixFeatures.push(fixFeature);
                    }
                    // Phase 5: Track this fix for the route (even if already seen globally)
                    routeFixesByRouteId[thisRouteId].push({ name: id, lat, lon, color: routeColor });
                } else if (!seenAirports.has(id)) {
                    seenAirports.add(id);
                    airportFeatures.push({
                        type: 'Feature',
                        properties: { name: id },
                        geometry: { type: 'Point', coordinates: [lon, lat] }
                    });
                }
            }

            const nPoints = routePoints.length;
            console.log('[MAPLIBRE] Route resolved', nPoints, 'points from', expandedTokens.length, 'tokens');
            if (nPoints < 2) return;

            let firstNavIndex = 0;
            while (firstNavIndex < nPoints && isAirportIdent(routePoints[firstNavIndex][0])) firstNavIndex++;
            let lastNavIndex = nPoints - 1;
            while (lastNavIndex >= 0 && isAirportIdent(routePoints[lastNavIndex][0])) lastNavIndex--;

            const hasNonAirport = firstNavIndex <= lastNavIndex && lastNavIndex >= 0;

            if (hasNonAirport && (firstNavIndex > 0 || lastNavIndex < nPoints - 1)) {
                const firstNav = routePoints[firstNavIndex];
                const lastNav = routePoints[lastNavIndex];

                for (let i = 0; i < firstNavIndex; i++) {
                    const ap = routePoints[i];
                    addSegmentFeature(routeFeatures, [[ap[2], ap[1]], [firstNav[2], firstNav[1]]], routeColor, false, true, thisRouteId, ap[0], firstNav[0]);
                }

                if (lastNavIndex > firstNavIndex) {
                    for (let i = firstNavIndex; i < lastNavIndex; i++) {
                        const from = routePoints[i], to = routePoints[i + 1];
                        const idxFrom = routeExpandedIndex[i], idxTo = routeExpandedIndex[i + 1];
                        const segSolid = solidExpanded[idxFrom] && solidExpanded[idxTo];
                        addSegmentFeature(routeFeatures, [[from[2], from[1]], [to[2], to[1]]], routeColor, segSolid, false, thisRouteId, from[0], to[0]);
                    }
                }

                for (let i = lastNavIndex + 1; i < nPoints; i++) {
                    const ap = routePoints[i];
                    addSegmentFeature(routeFeatures, [[lastNav[2], lastNav[1]], [ap[2], ap[1]]], routeColor, false, true, thisRouteId, lastNav[0], ap[0]);
                }
            } else {
                for (let i = 0; i < nPoints - 1; i++) {
                    const from = routePoints[i], to = routePoints[i + 1];
                    const idxFrom = routeExpandedIndex[i], idxTo = routeExpandedIndex[i + 1];
                    const segSolid = solidExpanded[idxFrom] && solidExpanded[idxTo];
                    addSegmentFeature(routeFeatures, [[from[2], from[1]], [to[2], to[1]]], routeColor, segSolid, false, thisRouteId, from[0], to[0]);
                }
            }
            
            // Add origin/destination endpoint icons
            if (nPoints >= 1) {
                // Origin (first point)
                const origin = routePoints[0];
                const originKey = `${origin[0]}|${origin[1].toFixed(4)}|${origin[2].toFixed(4)}|origin`;
                if (!seenEndpoints.has(originKey)) {
                    seenEndpoints.add(originKey);
                    endpointFeatures.push({
                        type: 'Feature',
                        properties: {
                            name: origin[0],
                            color: routeColor,
                            isOrigin: true,
                            isDest: false,
                            facilityType: detectFacilityType(origin[0])
                        },
                        geometry: { type: 'Point', coordinates: [origin[2], origin[1]] }
                    });
                }
                
                // Destination (last point, if different from origin)
                if (nPoints >= 2) {
                    const dest = routePoints[nPoints - 1];
                    const destKey = `${dest[0]}|${dest[1].toFixed(4)}|${dest[2].toFixed(4)}|dest`;
                    if (!seenEndpoints.has(destKey)) {
                        seenEndpoints.add(destKey);
                        endpointFeatures.push({
                            type: 'Feature',
                            properties: {
                                name: dest[0],
                                color: routeColor,
                                isOrigin: false,
                                isDest: true,
                                facilityType: detectFacilityType(dest[0])
                            },
                            geometry: { type: 'Point', coordinates: [dest[2], dest[1]] }
                        });
                    }
                }
            }
        });

        // Update lastExpandedRoutes with CDR-expanded routes (for public routes feature)
        // This ensures extractRouteEndpoints() can find airports in CDR-expanded routes
        lastExpandedRoutes = expandedRoutesCollector.filter(r => r && r.trim());
        console.log('[MAPLIBRE] Stored', lastExpandedRoutes.length, 'expanded routes for public routes feature');

        graphic_map.getSource('routes').setData({ type: 'FeatureCollection', features: routeFeatures });
        graphic_map.getSource('fixes').setData({ type: 'FeatureCollection', features: fixFeatures });
        graphic_map.getSource('airports').setData({ type: 'FeatureCollection', features: airportFeatures });
        
        // Populate route-endpoints source for origin/destination icons
        if (graphic_map.getSource('route-endpoints')) {
            graphic_map.getSource('route-endpoints').setData({ type: 'FeatureCollection', features: endpointFeatures });
        }

        // Phase 5: Populate route-fix-points and route-fix-labels with deduplication
        // Use a Map to deduplicate fixes by name+coords
        const uniqueFixes = new Map();
        
        Object.keys(routeFixesByRouteId).forEach(routeId => {
            const fixes = routeFixesByRouteId[routeId];
            if (!fixes) return;
            
            // Mark all routes as having visible labels by default
            routeLabelsVisible.add(parseInt(routeId));
            
            fixes.forEach(fix => {
                // Create unique key from name + rounded coords
                const uniqueKey = `${fix.name}|${fix.lat.toFixed(4)}|${fix.lon.toFixed(4)}`;
                
                // Only add if not already seen (deduplication)
                if (!uniqueFixes.has(uniqueKey)) {
                    uniqueFixes.set(uniqueKey, {
                        name: fix.name,
                        lat: fix.lat,
                        lon: fix.lon,
                        color: fix.color,
                        routeId: parseInt(routeId),
                        uniqueKey: uniqueKey
                    });
                }
            });
        });
        
        // Build features from deduplicated fixes
        const pointFeatures = [];
        const labelFeatures = [];
        
        uniqueFixes.forEach((fix, uniqueKey) => {
            // Point feature (always at original location)
            pointFeatures.push({
                type: 'Feature',
                properties: { 
                    name: fix.name, 
                    color: fix.color,
                    routeId: fix.routeId,
                    uniqueKey: uniqueKey
                },
                geometry: { 
                    type: 'Point', 
                    coordinates: [fix.lon, fix.lat] 
                }
            });
            
            // Label feature (starts at original location, can be moved)
            labelFeatures.push({
                type: 'Feature',
                properties: { 
                    name: fix.name, 
                    color: fix.color,
                    routeId: fix.routeId,
                    uniqueKey: uniqueKey,
                    origLon: fix.lon,
                    origLat: fix.lat,
                    hasMoved: false
                },
                geometry: { 
                    type: 'Point', 
                    coordinates: [fix.lon, fix.lat] 
                }
            });
        });
        
        // Update point source (circles - never move)
        if (graphic_map.getSource('route-fix-points')) {
            graphic_map.getSource('route-fix-points').setData({ 
                type: 'FeatureCollection', 
                features: pointFeatures 
            });
        }
        
        // Update label source
        if (graphic_map.getSource('route-fix-labels')) {
            graphic_map.getSource('route-fix-labels').setData({ 
                type: 'FeatureCollection', 
                features: labelFeatures 
            });
        }
        
        // Clear leader lines initially (no labels have been moved yet)
        if (graphic_map.getSource('route-fix-leaders')) {
            graphic_map.getSource('route-fix-leaders').setData({ 
                type: 'FeatureCollection', 
                features: [] 
            });
        }
        
        // Legacy compatibility - update route-fixes source too
        if (graphic_map.getSource('route-fixes')) {
            graphic_map.getSource('route-fixes').setData({ 
                type: 'FeatureCollection', 
                features: pointFeatures 
            });
        }

        console.log('[MAPLIBRE] Rendered', routeFeatures.length, 'segments,', fixFeatures.length, 'fixes,', airportFeatures.length, 'airports,', endpointFeatures.length, 'endpoints,', uniqueFixes.size, 'unique route labels');
        if (routeFeatures.length > 0) {
            console.log('[MAPLIBRE] First route feature:', JSON.stringify(routeFeatures[0]).slice(0, 200));
        }
    }

    function addSegmentFeature(features, coords, color, solid, isFan, routeId, fromFix, toFix) {
        if (coords.length === 2 && typeof turf !== 'undefined') {
            const dist = turf.distance(turf.point(coords[0]), turf.point(coords[1]), { units: 'nauticalmiles' });
            if (dist > 100) {
                try {
                    const arc = turf.greatCircle(turf.point(coords[0]), turf.point(coords[1]), { npoints: 50 });
                    coords = arc.geometry.coordinates;
                } catch (e) {}
            }
        }

        const key = coords.map(c => c[0].toFixed(4) + ',' + c[1].toFixed(4)).join('|') + '|' + color + '|' + solid + '|' + isFan;
        if (seenSegmentKeys.has(key)) return;
        seenSegmentKeys.add(key);

        // Calculate segment distance for popup
        let segmentDistance = 0;
        if (coords.length >= 2 && typeof turf !== 'undefined') {
            const line = turf.lineString(coords);
            segmentDistance = Math.round(turf.length(line, { units: 'nauticalmiles' }));
        }

        features.push({
            type: 'Feature',
            properties: { 
                color, 
                solid, 
                isFan,
                routeId: routeId || 0,
                fromFix: fromFix || '',
                toFix: toFix || '',
                distance: segmentDistance
            },
            geometry: { type: 'LineString', coordinates: coords }
        });
    }

    function updateFilteredAirways() {
        if (!mapReady || !graphic_map) return;
        const filterVal = ($('#filter').val() || '').toUpperCase();
        const airwayNames = filterVal.split(' ').filter(Boolean);
        if (!awyIndexBuilt) buildAirwayIndex();

        const features = [];
        airwayNames.forEach(name => {
            const airwayData = awyIndexMap[name];
            if (!airwayData) return;
            const coords = [];
            airwayData.fixes.forEach(fixName => {
                const pt = getPointByName(fixName);
                if (pt && pt.length >= 3) coords.push([pt[2], pt[1]]);
            });
            if (coords.length >= 2) {
                features.push({
                    type: 'Feature',
                    properties: { name },
                    geometry: { type: 'LineString', coordinates: coords }
                });
            }
        });

        graphic_map.getSource('filtered-airways').setData({ type: 'FeatureCollection', features });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // INTERACTIVITY (Phase 5 Enhanced)
    // ═══════════════════════════════════════════════════════════════════════════

    function setupInteractivity() {
        // Cursor change handlers
        ['routes-solid', 'routes-dashed', 'routes-fan', 'fixes-circles', 'aircraft-symbols', 'aircraft-circles-fallback', 'filtered-airways-lines', 'flight-routes-ahead', 'flight-routes-behind'].forEach(layerId => {
            if (!graphic_map.getLayer(layerId)) return;
            graphic_map.on('mouseenter', layerId, () => { graphic_map.getCanvas().style.cursor = 'pointer'; });
            graphic_map.on('mouseleave', layerId, () => { graphic_map.getCanvas().style.cursor = ''; });
        });

        // ─────────────────────────────────────────────────────────────────────
        // FIX/WAYPOINT CLICK - Enhanced popup with coordinates
        // ─────────────────────────────────────────────────────────────────────
        graphic_map.on('click', 'fixes-circles', e => {
            if (!e.features || !e.features[0]) return;
            const props = e.features[0].properties;
            const coords = e.features[0].geometry.coordinates;
            
            const content = `
                <div class="fix-popup" style="font-family: 'Inconsolata', monospace; font-size: 12px;">
                    <div style="font-weight: bold; font-size: 14px; color: ${props.color || '#333'};">${props.name}</div>
                    <div style="color: #666; margin-top: 4px;">
                        ${coords[1].toFixed(4)}° N, ${Math.abs(coords[0]).toFixed(4)}° ${coords[0] < 0 ? 'W' : 'E'}
                    </div>
                </div>
            `;
            new maplibregl.Popup({ closeButton: true, closeOnClick: true })
                .setLngLat(e.lngLat)
                .setHTML(content)
                .addTo(graphic_map);
        });

        // ─────────────────────────────────────────────────────────────────────
        // ROUTE SEGMENT CLICK - Handle overlapping routes with picker
        // ─────────────────────────────────────────────────────────────────────
        const handleRouteSegmentClick = (e) => {
            if (!e.features || !e.features.length) return;
            
            // Query ALL route features at this point across all route layers
            const allFeatures = graphic_map.queryRenderedFeatures(e.point, {
                layers: ['routes-solid', 'routes-dashed', 'routes-fan']
            });
            
            // Group by routeId to find unique routes
            const routeMap = new Map();
            allFeatures.forEach(f => {
                const routeId = f.properties.routeId;
                if (routeId && !routeMap.has(routeId)) {
                    routeMap.set(routeId, f.properties);
                }
            });
            
            const uniqueRoutes = Array.from(routeMap.entries());
            
            if (uniqueRoutes.length === 0) return;
            
            // Single route - toggle directly
            if (uniqueRoutes.length === 1) {
                const [routeId, props] = uniqueRoutes[0];
                showRoutePopupAndToggle(e.lngLat, routeId, props);
                return;
            }
            
            // Multiple overlapping routes - show picker
            showRoutePickerPopup(e.lngLat, uniqueRoutes);
        };
        
        function showRoutePopupAndToggle(lngLat, routeId, props) {
            let segmentInfo = '';
            if (props.fromFix && props.toFix) {
                segmentInfo = `<div style="font-weight: bold;">${props.fromFix} → ${props.toFix}</div>`;
            }
            if (props.distance > 0) {
                segmentInfo += `<div style="color: #666;">${props.distance} nm</div>`;
            }
            
            const lineType = props.isFan ? 'Fan' : (props.solid ? 'Mandatory' : 'Dashed');
            const isVisible = routeLabelsVisible.has(routeId);
            
            // Create segment key for symbology
            const segmentKey = `${props.fromFix || ''}|${props.toFix || ''}|${routeId}`;
            
            // Check if symbology module is available
            const hasSymbology = typeof RouteSymbology !== 'undefined';
            
            const content = `
                <div class="route-popup" style="font-family: 'Inconsolata', monospace; font-size: 12px;">
                    ${segmentInfo}
                    <div style="margin-top: 4px;">
                        <span style="display: inline-block; width: 12px; height: 12px; background: ${props.color}; border-radius: 2px; vertical-align: middle;"></span>
                        <span style="color: #888; margin-left: 4px;">${lineType}</span>
                    </div>
                    <div style="display: flex; gap: 6px; margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee;">
                        ${hasSymbology ? `
                        <button class="btn btn-sm btn-outline-primary route-popup-style-btn" 
                                data-route-id="${routeId}" 
                                data-segment-key="${segmentKey}"
                                data-seg-type="${props.isFan ? 'fan' : (props.solid ? 'solid' : 'dashed')}"
                                data-color="${props.color}"
                                style="flex: 1; font-size: 10px; padding: 2px 6px;">
                            <i class="fas fa-paint-brush"></i> Style
                        </button>
                        ` : ''}
                        <button class="btn btn-sm btn-outline-secondary route-popup-labels-btn" 
                                data-route-id="${routeId}" 
                                style="flex: 1; font-size: 10px; padding: 2px 6px;">
                            <i class="fas fa-tag"></i> ${isVisible ? 'Hide' : 'Show'}
                        </button>
                    </div>
                </div>
            `;
            
            const popup = new maplibregl.Popup({ closeButton: true, closeOnClick: true })
                .setLngLat(lngLat)
                .setHTML(content)
                .addTo(graphic_map);
            
            // Bind button handlers after popup is added to DOM
            setTimeout(() => {
                const styleBtn = document.querySelector('.route-popup-style-btn');
                const labelsBtn = document.querySelector('.route-popup-labels-btn');
                
                if (styleBtn && hasSymbology) {
                    styleBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        popup.remove();
                        
                        const segType = this.dataset.segType;
                        const segKey = this.dataset.segmentKey;
                        const rId = parseInt(this.dataset.routeId);
                        const clr = this.dataset.color;
                        
                        const currentStyle = RouteSymbology.getSegmentSymbology(segKey, rId, segType, clr);
                        
                        RouteSymbology.showSegmentEditor(lngLat, segKey, rId, currentStyle, function() {
                            RouteSymbology.applyToMapLibre(graphic_map);
                        }).addTo(graphic_map);
                    });
                }
                
                if (labelsBtn) {
                    labelsBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        popup.remove();
                        const rId = parseInt(this.dataset.routeId);
                        if (rId && routeFixesByRouteId[rId]) {
                            toggleRouteLabelsForRoute(rId, props.color);
                        }
                    });
                }
            }, 50);
        }
        
        function showRoutePickerPopup(lngLat, routes) {
            // Build picker content with clickable route options
            let options = routes.map(([routeId, props], idx) => {
                const isVisible = routeLabelsVisible.has(routeId);
                const labelStatus = isVisible ? '👁' : '○';
                const fromTo = (props.fromFix && props.toFix) 
                    ? `${props.fromFix}→${props.toFix}` 
                    : `Route ${routeId}`;
                return `
                    <div class="route-picker-option" data-route-id="${routeId}" data-color="${props.color || '#C70039'}"
                         style="padding: 6px 8px; cursor: pointer; border-bottom: 1px solid #eee; display: flex; align-items: center;"
                         onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='white'">
                        <span style="display: inline-block; width: 12px; height: 12px; background: ${props.color}; border-radius: 2px; margin-right: 8px;"></span>
                        <span style="flex: 1; font-size: 11px;">${fromTo}</span>
                        <span style="font-size: 10px; color: #666;">${labelStatus}</span>
                    </div>
                `;
            }).join('');
            
            const content = `
                <div class="route-picker" style="font-family: 'Inconsolata', monospace; min-width: 180px;">
                    <div style="font-weight: bold; font-size: 11px; color: #666; padding: 6px 8px; border-bottom: 2px solid #ddd; text-transform: uppercase;">
                        ${routes.length} Overlapping Routes
                    </div>
                    ${options}
                    <div style="font-size: 9px; color: #999; padding: 4px 8px; text-align: center;">
                        Click to toggle labels
                    </div>
                </div>
            `;
            
            const popup = new maplibregl.Popup({ closeButton: true, closeOnClick: false, maxWidth: '250px' })
                .setLngLat(lngLat)
                .setHTML(content)
                .addTo(graphic_map);
            
            // Add click handlers to options
            setTimeout(() => {
                document.querySelectorAll('.route-picker-option').forEach(el => {
                    el.addEventListener('click', function() {
                        const routeId = parseInt(this.dataset.routeId);
                        const color = this.dataset.color;
                        toggleRouteLabelsForRoute(routeId, color);
                        
                        // Update the label status indicator
                        const isNowVisible = routeLabelsVisible.has(routeId);
                        const statusSpan = this.querySelector('span:last-child');
                        if (statusSpan) {
                            statusSpan.textContent = isNowVisible ? '👁' : '○';
                        }
                    });
                });
            }, 50);
        }
        
        graphic_map.on('click', 'routes-solid', handleRouteSegmentClick);
        graphic_map.on('click', 'routes-dashed', handleRouteSegmentClick);
        graphic_map.on('click', 'routes-fan', handleRouteSegmentClick);

        // ─────────────────────────────────────────────────────────────────────
        // DRAGGABLE LABEL SUPPORT
        // Click and drag on route-fixes-labels to reposition ONLY the label (not the point)
        // A leader line is drawn from the label to the original point position
        // ─────────────────────────────────────────────────────────────────────
        graphic_map.on('mousedown', 'route-fixes-labels', (e) => {
            if (!e.features || !e.features[0]) return;
            
            const props = e.features[0].properties;
            const coords = e.features[0].geometry.coordinates;
            
            // Use uniqueKey if available, otherwise construct from name + coords
            const uniqueKey = props.uniqueKey || `${props.name}|${(props.origLat || coords[1]).toFixed(4)}|${(props.origLon || coords[0]).toFixed(4)}`;
            
            draggingLabel = {
                name: props.name,
                routeId: props.routeId,
                uniqueKey: uniqueKey,
                // Store ORIGINAL point coordinates (not current label position)
                originalPointCoords: [props.origLon || coords[0], props.origLat || coords[1]],
                // Store current label coordinates for drag calculation
                currentLabelCoords: coords.slice(),
                color: props.color
            };
            dragStartPos = { x: e.point.x, y: e.point.y };
            
            graphic_map.getCanvas().style.cursor = 'grabbing';
            
            // Prevent map panning while dragging label
            graphic_map.dragPan.disable();
        });
        
        graphic_map.on('mousemove', (e) => {
            if (!draggingLabel) return;
            
            // Calculate pixel offset from start
            const dx = e.point.x - dragStartPos.x;
            const dy = e.point.y - dragStartPos.y;
            
            // Convert current label position to new lng/lat
            const currentPixel = graphic_map.project(draggingLabel.currentLabelCoords);
            const newPos = graphic_map.unproject([
                currentPixel.x + dx,
                currentPixel.y + dy
            ]);
            
            // Store in labelOffsets using uniqueKey
            // This only affects the LABEL position, not the point
            labelOffsets[draggingLabel.uniqueKey] = { 
                lng: newPos.lng, 
                lat: newPos.lat,
                color: draggingLabel.color,
                // Store original point coords for leader line
                origLng: draggingLabel.originalPointCoords[0],
                origLat: draggingLabel.originalPointCoords[1]
            };
            
            // Update display immediately (only labels and leader lines will move)
            updateRouteLabelDisplay();
            
            // Update drag start for continuous dragging
            dragStartPos = { x: e.point.x, y: e.point.y };
            draggingLabel.currentLabelCoords = [newPos.lng, newPos.lat];
        });
        
        graphic_map.on('mouseup', () => {
            if (draggingLabel) {
                console.log('[MAPLIBRE] Label moved:', draggingLabel.name, '(point stays at original position)');
                graphic_map.getCanvas().style.cursor = '';
                graphic_map.dragPan.enable();  // Re-enable map panning
                draggingLabel = null;
                dragStartPos = null;
            }
        });
        
        // Also handle mouseup outside map (in case drag ends off-canvas)
        document.addEventListener('mouseup', () => {
            if (draggingLabel) {
                graphic_map.getCanvas().style.cursor = '';
                graphic_map.dragPan.enable();
                draggingLabel = null;
                dragStartPos = null;
            }
        });
        
        // Change cursor on label hover
        graphic_map.on('mouseenter', 'route-fixes-labels', () => {
            if (!draggingLabel) {
                graphic_map.getCanvas().style.cursor = 'grab';
            }
        });
        graphic_map.on('mouseleave', 'route-fixes-labels', () => {
            if (!draggingLabel) {
                graphic_map.getCanvas().style.cursor = '';
            }
        });

        // ─────────────────────────────────────────────────────────────────────
        // FILTERED AIRWAY CLICK - Show airway name
        // ─────────────────────────────────────────────────────────────────────
        graphic_map.on('click', 'filtered-airways-lines', e => {
            if (!e.features || !e.features[0]) return;
            const props = e.features[0].properties;
            
            const content = `
                <div style="font-family: 'Inconsolata', monospace; font-weight: bold;">
                    ${props.name || 'Airway'}
                </div>
            `;
            new maplibregl.Popup({ closeButton: true, closeOnClick: true })
                .setLngLat(e.lngLat)
                .setHTML(content)
                .addTo(graphic_map);
        });

        // ─────────────────────────────────────────────────────────────────────
        // FLIGHT ROUTE SEGMENT CLICK - Show flight route segment info
        // ─────────────────────────────────────────────────────────────────────
        const handleFlightRouteClick = (e) => {
            if (!e.features || !e.features[0]) return;
            const props = e.features[0].properties;
            const isAhead = props.segment === 'ahead';
            
            const content = `
                <div class="flight-route-popup" style="font-family: 'Inconsolata', monospace; font-size: 12px;">
                    <div style="font-weight: bold; color: ${props.color || '#fff'};">${props.callsign}</div>
                    <div style="color: #888; margin-top: 4px;">
                        ${isAhead ? 'Route Ahead' : 'Route Behind'}
                    </div>
                </div>
            `;
            
            new maplibregl.Popup({ closeButton: true, closeOnClick: true })
                .setLngLat(e.lngLat)
                .setHTML(content)
                .addTo(graphic_map);
        };
        
        if (graphic_map.getLayer('flight-routes-ahead')) {
            graphic_map.on('click', 'flight-routes-ahead', handleFlightRouteClick);
        }
        if (graphic_map.getLayer('flight-routes-behind')) {
            graphic_map.on('click', 'flight-routes-behind', handleFlightRouteClick);
        }

        // Aircraft click is handled by ADL module
    }

    // ─────────────────────────────────────────────────────────────────────
    // ROUTE LABEL TOGGLE SYSTEM
    // Toggle visibility of fix labels for a specific route
    // ─────────────────────────────────────────────────────────────────────
    function toggleRouteLabelsForRoute(routeId, routeColor) {
        if (!routeId || !routeFixesByRouteId[routeId]) return;
        
        const wasVisible = routeLabelsVisible.has(routeId);
        
        if (wasVisible) {
            // Remove labels for this route
            routeLabelsVisible.delete(routeId);
        } else {
            // Add labels for this route
            routeLabelsVisible.add(routeId);
        }
        
        // Rebuild the route-fixes source with only visible route labels
        updateRouteLabelDisplay();
        
        console.log(`[MAPLIBRE] Route ${routeId} labels ${wasVisible ? 'hidden' : 'shown'}`);
    }
    
    // Calculate leader line start point at the edge of label's bounding box
    // Uses pixel-based calculation for accurate results at any zoom level
    // Returns [lon, lat] of the point on the bbox edge closest to the target point
    function getLeaderLineStart(labelLon, labelLat, pointLon, pointLat, textLength) {
        if (!graphic_map) return [labelLon, labelLat];
        
        // Convert to screen coordinates
        const labelPixel = graphic_map.project([labelLon, labelLat]);
        const pointPixel = graphic_map.project([pointLon, pointLat]);
        
        // Approximate label dimensions in pixels
        // Based on typical font size of 10-11px and ~6px per character
        const charWidth = 7;
        const labelHeight = 14;
        const padding = 6;  // Text halo/padding
        
        const halfWidth = (textLength * charWidth + padding) / 2;
        const halfHeight = (labelHeight + padding) / 2;
        
        // Direction from label center to target point in pixels
        const dx = pointPixel.x - labelPixel.x;
        const dy = pointPixel.y - labelPixel.y;
        
        // If point is at same location as label, return label center
        if (dx === 0 && dy === 0) {
            return [labelLon, labelLat];
        }
        
        // Find parametric t where line from center hits bbox edge
        // We want the smallest positive t that lands on a valid edge
        let tMin = Infinity;
        
        // Check left/right edges
        if (dx !== 0) {
            // Right edge
            let t = halfWidth / dx;
            if (t > 0) {
                const yAtT = t * dy;
                if (Math.abs(yAtT) <= halfHeight && t < tMin) tMin = t;
            }
            // Left edge
            t = -halfWidth / dx;
            if (t > 0) {
                const yAtT = t * dy;
                if (Math.abs(yAtT) <= halfHeight && t < tMin) tMin = t;
            }
        }
        
        // Check top/bottom edges
        if (dy !== 0) {
            // Bottom edge (positive y is down in screen coords)
            let t = halfHeight / dy;
            if (t > 0) {
                const xAtT = t * dx;
                if (Math.abs(xAtT) <= halfWidth && t < tMin) tMin = t;
            }
            // Top edge
            t = -halfHeight / dy;
            if (t > 0) {
                const xAtT = t * dx;
                if (Math.abs(xAtT) <= halfWidth && t < tMin) tMin = t;
            }
        }
        
        if (tMin === Infinity) {
            return [labelLon, labelLat];
        }
        
        // Calculate intersection point in pixels
        const edgePixelX = labelPixel.x + tMin * dx;
        const edgePixelY = labelPixel.y + tMin * dy;
        
        // Convert back to lat/lon
        const edgeLatLng = graphic_map.unproject([edgePixelX, edgePixelY]);
        return [edgeLatLng.lng, edgeLatLng.lat];
    }
    
    function updateRouteLabelDisplay() {
        console.log('[DEBUG-LABELS] updateRouteLabelDisplay() called');
        if (!graphic_map) {
            console.warn('[DEBUG-LABELS] graphic_map is null, aborting');
            return;
        }
        console.log('[DEBUG-LABELS] routeLabelsVisible:', Array.from(routeLabelsVisible));

        // Build features for visible route fixes with deduplication
        const uniqueFixes = new Map();

        routeLabelsVisible.forEach(routeId => {
            const fixes = routeFixesByRouteId[routeId];
            if (!fixes) return;
            
            fixes.forEach(fix => {
                // Create unique key from name + rounded coords
                const uniqueKey = `${fix.name}|${fix.lat.toFixed(4)}|${fix.lon.toFixed(4)}`;
                
                // Only add if not already seen (deduplication)
                if (!uniqueFixes.has(uniqueKey)) {
                    uniqueFixes.set(uniqueKey, {
                        name: fix.name,
                        lat: fix.lat,
                        lon: fix.lon,
                        color: fix.color,
                        routeId: routeId,
                        uniqueKey: uniqueKey
                    });
                }
            });
        });
        
        const pointFeatures = [];
        const labelFeatures = [];
        const leaderFeatures = [];
        
        uniqueFixes.forEach((fix, uniqueKey) => {
            // Check if this label has been dragged to a custom position
            const customPos = labelOffsets[uniqueKey];
            const hasMoved = !!customPos;
            
            // Point feature - ALWAYS at original position
            pointFeatures.push({
                type: 'Feature',
                properties: { 
                    name: fix.name, 
                    color: fix.color,
                    routeId: fix.routeId,
                    uniqueKey: uniqueKey
                },
                geometry: { 
                    type: 'Point', 
                    coordinates: [fix.lon, fix.lat] 
                }
            });
            
            // Label position (original or moved)
            const labelLon = customPos ? customPos.lng : fix.lon;
            const labelLat = customPos ? customPos.lat : fix.lat;
            
            // Label feature
            labelFeatures.push({
                type: 'Feature',
                properties: { 
                    name: fix.name, 
                    color: fix.color,
                    routeId: fix.routeId,
                    uniqueKey: uniqueKey,
                    origLon: fix.lon,
                    origLat: fix.lat,
                    hasMoved: hasMoved
                },
                geometry: { 
                    type: 'Point', 
                    coordinates: [labelLon, labelLat] 
                }
            });
            
            // Leader line (only if label has been moved)
            if (hasMoved) {
                // Calculate leader line start at edge of label bounding box
                const leaderStart = getLeaderLineStart(
                    labelLon, labelLat, 
                    fix.lon, fix.lat, 
                    fix.name.length
                );
                
                leaderFeatures.push({
                    type: 'Feature',
                    properties: { 
                        color: fix.color,
                        name: fix.name
                    },
                    geometry: { 
                        type: 'LineString', 
                        coordinates: [
                            leaderStart,           // From label bbox edge
                            [fix.lon, fix.lat]     // To original point
                        ]
                    }
                });
            }
        });
        
        console.log('[DEBUG-LABELS] Feature counts - points:', pointFeatures.length, 'labels:', labelFeatures.length, 'leaders:', leaderFeatures.length);

        // Update point source (circles - always at original positions)
        if (graphic_map.getSource('route-fix-points')) {
            graphic_map.getSource('route-fix-points').setData({
                type: 'FeatureCollection',
                features: pointFeatures
            });
            console.log('[DEBUG-LABELS] Updated route-fix-points source');
        } else {
            console.warn('[DEBUG-LABELS] Source route-fix-points NOT FOUND');
        }

        // Update label source (text - can be moved)
        if (graphic_map.getSource('route-fix-labels')) {
            graphic_map.getSource('route-fix-labels').setData({
                type: 'FeatureCollection',
                features: labelFeatures
            });
            console.log('[DEBUG-LABELS] Updated route-fix-labels source');
        } else {
            console.warn('[DEBUG-LABELS] Source route-fix-labels NOT FOUND');
        }

        // Update leader lines source
        if (graphic_map.getSource('route-fix-leaders')) {
            graphic_map.getSource('route-fix-leaders').setData({
                type: 'FeatureCollection',
                features: leaderFeatures
            });
        }

        // Legacy compatibility
        if (graphic_map.getSource('route-fixes')) {
            graphic_map.getSource('route-fixes').setData({
                type: 'FeatureCollection',
                features: pointFeatures
            });
        }
        console.log('[DEBUG-LABELS] updateRouteLabelDisplay() complete');
    }
    
    // Reset all label positions to original
    function resetLabelPositions() {
        labelOffsets = {};
        updateRouteLabelDisplay();
        console.log('[MAPLIBRE] All label positions reset');
    }
    
    // Toggle ALL route labels on/off (for Toggle Labels button)
    function toggleAllLabels() {
        console.log('[DEBUG-LABELS] toggleAllLabels() called');
        console.log('[DEBUG-LABELS] routeLabelsVisible size BEFORE:', routeLabelsVisible.size);
        console.log('[DEBUG-LABELS] routeFixesByRouteId keys:', Object.keys(routeFixesByRouteId));

        try {
            const anyVisible = routeLabelsVisible.size > 0;
            console.log('[DEBUG-LABELS] anyVisible:', anyVisible, '-> will', anyVisible ? 'HIDE' : 'SHOW', 'labels');

            if (anyVisible) {
                // Hide all labels
                routeLabelsVisible.clear();
                console.log('[MAPLIBRE] All labels hidden');
            } else {
                // Show all labels
                Object.keys(routeFixesByRouteId).forEach(id => {
                    routeLabelsVisible.add(parseInt(id));
                });
                console.log('[MAPLIBRE] All labels shown');
            }

            console.log('[DEBUG-LABELS] routeLabelsVisible size AFTER:', routeLabelsVisible.size);
            updateRouteLabelDisplay();
        } catch (e) {
            console.error('[MAPLIBRE] toggleAllLabels error:', e);
        }
    }
    
    // Expose toggleAllLabels globally for the button onclick
    window.toggleAllLabels = toggleAllLabels;

    // ═══════════════════════════════════════════════════════════════════════════
    // ADL LIVE FLIGHTS MODULE - TSD Symbology Display (MapLibre)
    // ═══════════════════════════════════════════════════════════════════════════

    const ADL = (function() {
        'use strict';

        // State management
        const state = {
            enabled: false,
            flights: [],
            filteredFlights: [],
            drawnRoutes: new Map(),  // flight_key -> { color, behindCoords, aheadCoords }
            refreshInterval: null,
            refreshRateMs: 15000,
            lastRefresh: null,
            trackedFlight: null,
            selectedFlight: null,
            colorBy: 'weight_class',
            filters: {
                weightClasses: ['SUPER', 'HEAVY', 'LARGE', 'SMALL', 'J', 'H', 'L', 'S', ''],
                origin: '',
                dest: '',
                carrier: '',
                altitudeMin: null,
                altitudeMax: null
            },
            // Custom color filter rules (user-defined, evaluated in order)
            colorRules: []
        };

        // Route colors for drawn flight plans
        const ROUTE_COLORS = ['#e74c3c', '#3498db', '#2ecc71', '#9b59b6', '#f39c12', '#1abc9c', '#e91e63', '#00bcd4'];
        let routeColorIndex = 0;

        // ═══════════════════════════════════════════════════════════════════════════
        // PROFESSIONAL COLOR PALETTES
        // Curated from Tableau, D3, ColorBrewer for maximum distinction
        // ═══════════════════════════════════════════════════════════════════════════

        // Tableau 10 - highly distinguishable
        const TABLEAU_10 = [
            '#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f',
            '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac'
        ];

        // D3 Category 20 - extended palette
        const D3_CATEGORY_20 = [
            '#1f77b4', '#aec7e8', '#ff7f0e', '#ffbb78', '#2ca02c',
            '#98df8a', '#d62728', '#ff9896', '#9467bd', '#c5b0d5',
            '#8c564b', '#c49c94', '#e377c2', '#f7b6d2', '#7f7f7f',
            '#c7c7c7', '#bcbd22', '#dbdb8d', '#17becf', '#9edae5'
        ];

        // Named colors for user convenience (CSS standard names mapped to hex)
        const NAMED_COLORS = {
            'red': '#e15759', 'blue': '#4e79a7', 'green': '#59a14f', 'orange': '#f28e2b',
            'purple': '#b07aa1', 'teal': '#76b7b2', 'yellow': '#edc948', 'pink': '#ff9da7',
            'brown': '#9c755f', 'gray': '#bab0ac', 'cyan': '#17becf', 'lime': '#98df8a',
            'navy': '#1f3a5f', 'maroon': '#800000', 'olive': '#808000', 'aqua': '#00ffff',
            'magenta': '#e377c2', 'gold': '#ffd700', 'coral': '#ff7f50', 'salmon': '#fa8072',
            'indigo': '#4b0082', 'violet': '#ee82ee', 'turquoise': '#40e0d0', 'slate': '#708090'
        };

        // ─────────────────────────────────────────────────────────────────────
        // COLOR SCHEMES (FSM/TSD Standard + Extended)
        // ─────────────────────────────────────────────────────────────────────

        const WEIGHT_CLASS_COLORS = {
            'SUPER': '#ffc107', 'J': '#ffc107',  // Amber/Gold for Jumbo
            'HEAVY': '#dc3545', 'H': '#dc3545',  // Red for Heavy
            'LARGE': '#28a745', 'L': '#28a745',  // Green for Large/Jet
            'SMALL': '#17a2b8', 'S': '#17a2b8',  // Cyan for Small/Prop
            '': '#6c757d'
        };

        const AIRCRAFT_CATEGORY_COLORS = {
            'J': '#dc3545', 'JET': '#dc3545',
            'T': '#28a745', 'TURBO': '#28a745',
            'P': '#17a2b8', 'PROP': '#17a2b8',
            '': '#ffffff'
        };

        // ─────────────────────────────────────────────────────────────────────
        // DCC REGION DEFINITIONS (5 regions per user spec)
        // ─────────────────────────────────────────────────────────────────────
        const DCC_REGIONS = {
            'WEST':         ['ZAK', 'ZAN', 'ZHN', 'ZLA', 'ZLC', 'ZOA', 'ZSE'],
            'SOUTH_CENTRAL': ['ZAB', 'ZFW', 'ZHO', 'ZHU', 'ZME'],
            'MIDWEST':      ['ZAU', 'ZDV', 'ZKC', 'ZMP'],
            'SOUTHEAST':    ['ZID', 'ZJX', 'ZMA', 'ZMO', 'ZTL'],
            'NORTHEAST':    ['ZBW', 'ZDC', 'ZNY', 'ZOB', 'ZWY']
        };

        const DCC_REGION_COLORS = {
            'WEST': '#e15759',           // Red
            'SOUTH_CENTRAL': '#f28e2b',  // Orange
            'MIDWEST': '#59a14f',        // Green
            'SOUTHEAST': '#edc948',      // Yellow
            'NORTHEAST': '#4e79a7',      // Blue
            '': '#6c757d'
        };

        // ARTCC colors - inherit from DCC region with variations
        const CENTER_COLORS = {
            // West (Red family)
            'ZAK': '#e15759', 'ZAN': '#ff6b6b', 'ZHN': '#c9302c', 'ZLA': '#e15759', 
            'ZLC': '#ff8787', 'ZOA': '#d64545', 'ZSE': '#f28080',
            // South Central (Orange family)
            'ZAB': '#f28e2b', 'ZFW': '#ff9f43', 'ZHO': '#e67e22', 'ZHU': '#f5a623', 'ZME': '#d68910',
            // Midwest (Green family)
            'ZAU': '#59a14f', 'ZDV': '#27ae60', 'ZKC': '#2ecc71', 'ZMP': '#45b39d',
            // Southeast (Yellow family)
            'ZID': '#edc948', 'ZJX': '#f1c40f', 'ZMA': '#f4d03f', 'ZMO': '#d4ac0d', 'ZTL': '#e9b824',
            // Northeast (Blue family)
            'ZBW': '#4e79a7', 'ZDC': '#3498db', 'ZNY': '#2980b9', 'ZOB': '#5dade2', 'ZWY': '#1a5276',
            '': '#6c757d'
        };

        // TRACON to ARTCC mapping (major TRACONs)
        const TRACON_TO_ARTCC = {
            // Northeast
            'N90': 'ZNY', 'A90': 'ZBW', 'PCT': 'ZDC', 'PHL': 'ZNY', 'Y90': 'ZDC',
            // Southeast
            'A80': 'ZTL', 'C90': 'ZTL', 'F11': 'ZJX', 'MIA': 'ZMA', 'JAX': 'ZJX', 'TPA': 'ZJX',
            // Midwest
            'C90': 'ZAU', 'D21': 'ZMP', 'I90': 'ZAU', 'M98': 'ZMP', 'R90': 'ZKC',
            // South Central
            'D10': 'ZFW', 'I90': 'ZHU', 'AUS': 'ZHU', 'SAT': 'ZHU',
            // West
            'L30': 'ZLA', 'SCT': 'ZLA', 'NCT': 'ZOA', 'S46': 'ZSE', 'P50': 'ZSE', 'D01': 'ZDV'
        };

        // TRACON colors - inherit from parent ARTCC
        function getTraconColor(tracon) {
            const artcc = TRACON_TO_ARTCC[tracon];
            if (artcc && CENTER_COLORS[artcc]) return CENTER_COLORS[artcc];
            return '#6c757d';
        }

        // ─────────────────────────────────────────────────────────────────────
        // AIRPORT TIER COLORS
        // ─────────────────────────────────────────────────────────────────────
        const CORE30_AIRPORTS = [
            'KATL', 'KBOS', 'KBWI', 'KCLE', 'KCLT', 'KDCA', 'KDEN', 'KDFW', 'KDTW',
            'KEWR', 'KFLL', 'KIAD', 'KIAH', 'KJFK', 'KLAS', 'KLAX', 'KLGA', 'KMCO',
            'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KORD', 'KPHL', 'KPHX', 'KSAN', 'KSEA',
            'KSFO', 'KSLC', 'KTPA'
        ];

        const OEP35_AIRPORTS = [
            ...CORE30_AIRPORTS,
            'KSTL', 'KPDX', 'KHON', 'KPIT', 'KCVG'
        ];

        const ASPM77_AIRPORTS = [
            ...OEP35_AIRPORTS,
            'KABQ', 'KAUS', 'KBDL', 'KBNA', 'KBUF', 'KBURB', 'KCMH', 'KDAL',
            'KHOU', 'KIND', 'KJAX', 'KMCI', 'KMKE', 'KMSY', 'KOAK', 'KOMA',
            'KONT', 'KPBI', 'KPVD', 'KRDU', 'KRNO', 'KRSW', 'KSAT', 'KSDF',
            'KSJC', 'KSMF', 'KSNA', 'KSTL', 'KTUL', 'KAUS', 'KBHM', 'KELP',
            'KGSO', 'KICT', 'KLIT', 'KLUBB', 'KOKC', 'KRIC', 'KSAV', 'KSYR'
        ];

        const AIRPORT_TIER_COLORS = {
            'CORE30': '#e15759',    // Red
            'OEP35': '#4e79a7',     // Blue
            'ASPM77': '#edc948',    // Yellow
            'OTHER': '#59a14f',     // Green
            '': '#6c757d'
        };

        function getAirportTier(icao) {
            if (!icao) return 'OTHER';
            const apt = icao.toUpperCase();
            if (CORE30_AIRPORTS.includes(apt)) return 'CORE30';
            if (OEP35_AIRPORTS.includes(apt)) return 'OEP35';
            if (ASPM77_AIRPORTS.includes(apt)) return 'ASPM77';
            return 'OTHER';
        }

        // ─────────────────────────────────────────────────────────────────────
        // AIRCRAFT TYPE (Manufacturer) COLORS
        // ─────────────────────────────────────────────────────────────────────
        const AIRCRAFT_MANUFACTURER_PATTERNS = {
            'AIRBUS':     /^A[0-9]{3}|^A[0-9]{2}[NK]/i,
            'BOEING':     /^B7[0-9]{2}|^B3[0-9]M|^B3XM|^B77[A-Z]|^B74[A-Z]|^B74[0-9][A-Z]|^B78X/i,
            'EMBRAER':    /^E[0-9]{3}|^ERJ|^EMB|^E[0-9][0-9][A-Z]/i,
            'BOMBARDIER': /^CRJ|^CL[0-9]{2}|^BD[0-9]{3}|^GL[0-9]{2}|^DHC|^BCS[0-9]|^Q[0-9]{3}/i,
            'MD_DC':      /^MD[0-9]{2}|^DC[0-9]{1,2}|^L10|^L101|^C130|^C17/i,
            'SAAB_OTHER': /^SF34|^SB20|^F[0-9]{2,3}|^D[0-9]{3}|^BAE|^B?146|^RJ[0-9]{2}|^AT[0-9]{2}|^PC[0-9]{2}/i,
            'RUSSIAN':    /^AN[0-9]{2,3}|^IL[0-9]{2,3}|^TU[0-9]{3}|^SU[0-9]{2}|^YAK|^BE20[0-9]/i,
            'CHINESE':    /^ARJ|^C9[0-9]{2}|^MA[0-9]{2}|^Y[0-9]{1,2}/i
        };

        const AIRCRAFT_MANUFACTURER_COLORS = {
            'AIRBUS': '#e15759',       // Red
            'BOEING': '#4e79a7',       // Blue
            'EMBRAER': '#59a14f',      // Green
            'BOMBARDIER': '#f28e2b',   // Orange
            'MD_DC': '#b07aa1',        // Purple
            'SAAB_OTHER': '#76b7b2',   // Teal
            'RUSSIAN': '#9c755f',      // Brown
            'CHINESE': '#edc948',      // Yellow
            'OTHER': '#6c757d'         // Gray
        };

        function getAircraftManufacturer(acType) {
            if (!acType) return 'OTHER';
            const type = acType.toUpperCase();
            for (const [mfr, pattern] of Object.entries(AIRCRAFT_MANUFACTURER_PATTERNS)) {
                if (pattern.test(type)) return mfr;
            }
            return 'OTHER';
        }

        // ─────────────────────────────────────────────────────────────────────
        // AIRCRAFT CONFIGURATION COLORS
        // ─────────────────────────────────────────────────────────────────────
        const AIRCRAFT_CONFIG_PATTERNS = {
            'A380':        /^A38[0-9]/i,
            'QUAD_JET':    /^B74[0-9]|^B74[A-Z]|^B74[0-9][A-Z]|^A34[0-6]|^A340|^IL96/i,
            'HEAVY_TWIN':  /^B77[0-9]|^B77[A-Z]|^B78[0-9]|^B78X|^A33[0-9]|^A35[0-9]|^A35K|^B76[0-9]/i,
            'TRI_JET':     /^MD11|^DC10|^L101|^TU154/i,
            'TWIN_JET':    /^A32[0-9]|^A31[0-9]|^A2[0-9][NK]|^A22[0-9]|^B73[0-9]|^B3[0-9]M|^B3XM|^B75[0-9]|^MD[89][0-9]|^BCS[0-9]/i,
            'REGIONAL_JET': /^CRJ|^ERJ|^E[0-9]{3}|^E[0-9][0-9][A-Z]/i,
            'TURBOPROP':   /^AT[0-9]{2}|^DH8|^DHC8|^Q[0-9]{3}|^SF34|^SB20|^B190|^JS[0-9]{2}|^PC12|^PC24|^C208|^BE[0-9]{2}[0-9]/i,
            'PROP':        /^C1[0-9]{2}|^C2[0-9]{2}|^P28|^PA[0-9]{2}|^SR2[0-9]|^DA[0-9]{2}|^M20|^BE[0-9]{2}[^0-9]/i
        };

        const AIRCRAFT_CONFIG_COLORS = {
            'A380': '#9c27b0',          // Deep Purple
            'QUAD_JET': '#e15759',      // Red
            'HEAVY_TWIN': '#f28e2b',    // Orange
            'TRI_JET': '#edc948',       // Yellow
            'TWIN_JET': '#59a14f',      // Green
            'REGIONAL_JET': '#4e79a7',  // Blue
            'TURBOPROP': '#76b7b2',     // Teal
            'PROP': '#17a2b8',          // Cyan
            'OTHER': '#6c757d'          // Gray
        };

        function getAircraftConfig(acType) {
            if (!acType) return 'OTHER';
            const type = acType.toUpperCase();
            for (const [cfg, pattern] of Object.entries(AIRCRAFT_CONFIG_PATTERNS)) {
                if (pattern.test(type)) return cfg;
            }
            return 'OTHER';
        }

        // ─────────────────────────────────────────────────────────────────────
        // EXTENDED CARRIER COLORS (US Focus)
        // ─────────────────────────────────────────────────────────────────────
        const CARRIER_COLORS = {
            // US Legacy
            'AAL': '#0078d2', 'UAL': '#0033a0', 'DAL': '#e01933',
            // US Low-Cost
            'SWA': '#f9b612', 'JBU': '#003876', 'NKS': '#ffd200', 'FFT': '#2b8542',
            'VXP': '#e51937', 'SYX': '#ff5a00',
            // US ULCCs
            'AAY': '#f9b612', 'G4': '#6ec8e4',
            // US Regional - Major
            'SKW': '#6cace4', 'RPA': '#00b5ad', 'ENY': '#4e79a7', 'ASH': '#003876',
            // US Regional - AA
            'PDT': '#76b7b2', 'PSA': '#ff7f0e', 'ENY': '#0078d2',
            // US Regional - UA
            'ASQ': '#0033a0', 'GJS': '#0033a0', 'RPA': '#0033a0', 'SKW': '#0033a0',
            // US Regional - DL
            'CPZ': '#e01933', 'EDV': '#e01933', 'GJS': '#e01933',
            // Alaska Group
            'ASA': '#00a8e0', 'HAL': '#5b2e91', 'QXE': '#00a8e0',
            // Cargo - Major
            'FDX': '#ff6600', 'UPS': '#351c15', 'ABX': '#00529b', 'GTI': '#002d72',
            'ATN': '#e15759', 'CLX': '#003087', 'PAC': '#b07aa1', 'KAL': '#0064d2',
            // Cargo - Regional
            'MTN': '#ffc107', 'SRR': '#28a745', 'WCW': '#17a2b8',
            // International - European
            'BAW': '#075aaa', 'DLH': '#00195c', 'AFR': '#002157', 'KLM': '#00a1e4',
            'EZY': '#ff6600', 'RYR': '#073590', 'VIR': '#e01933', 'SAS': '#00195c',
            'AZA': '#006341', 'IBE': '#e01933', 'TAP': '#00a651', 'FIN': '#0057a8',
            'SWR': '#e01933', 'AUA': '#e01933', 'BEL': '#003366', 'LOT': '#00538b',
            'CSA': '#d7141a', 'AEE': '#00529b', 'THY': '#cc0000',
            // International - Americas
            'ACA': '#f01428', 'WJA': '#003082', 'TAM': '#1a1760', 'GOL': '#ff6600',
            'AVA': '#e01933', 'CMP': '#003087', 'AMX': '#000000', 'VOI': '#ffc907',
            'ARG': '#75aadb', 'LAN': '#1a1760',
            // International - Asia/Pacific
            'UAE': '#c8a96b', 'QTR': '#5c0632', 'ETD': '#b8a36e', 'SIA': '#f9ba00',
            'CPA': '#006a4e', 'JAL': '#e01933', 'ANA': '#003370', 'KAL': '#0064d2',
            'CES': '#004b87', 'CSN': '#e01933', 'CCA': '#e01933', 'QFA': '#e01933',
            'ANZ': '#000000', 'THT': '#672d91', 'MAS': '#e01933', 'SLK': '#0b3c7d',
            'EVA': '#00674b', 'CAL': '#003d7c', 'HVN': '#f7e500',
            // International - Middle East/Africa
            'SAA': '#009639', 'ETH': '#00844e', 'RAM': '#c9262c', 'RJA': '#000000',
            'GIA': '#003057', 'ELY': '#0033a1', 'MEA': '#006341',
            // Military/Government
            'AIO': '#556b2f', 'RCH': '#556b2f', 'RRR': '#556b2f',
            // Default
            '': '#6c757d'
        };

        // ─────────────────────────────────────────────────────────────────────
        // OPERATOR GROUP COLORS
        // ─────────────────────────────────────────────────────────────────────
        const MAJOR_CARRIERS = ['AAL', 'UAL', 'DAL', 'SWA', 'JBU', 'ASA', 'HAL', 'NKS', 'FFT', 'AAY', 'VXP', 'SYX'];
        const REGIONAL_CARRIERS = ['SKW', 'RPA', 'ENY', 'PDT', 'PSA', 'ASQ', 'GJS', 'CPZ', 'EDV', 'QXE', 'ASH', 'OO', 'AIP', 'MES', 'JIA', 'SCX'];
        const FREIGHT_CARRIERS = ['FDX', 'UPS', 'ABX', 'GTI', 'ATN', 'CLX', 'PAC', 'KAL', 'MTN', 'SRR', 'WCW', 'CAO'];
        const MILITARY_PREFIXES = ['AIO', 'RCH', 'RRR', 'CNV', 'PAT', 'NAVY', 'ARMY', 'USAF', 'USCG', 'EXEC'];

        const OPERATOR_GROUP_COLORS = {
            'MAJOR': '#4e79a7',      // Blue
            'REGIONAL': '#59a14f',   // Green
            'FREIGHT': '#f28e2b',    // Orange
            'GA': '#76b7b2',         // Teal
            'MILITARY': '#556b2f',   // Olive
            'OTHER': '#6c757d'       // Gray
        };

        function getOperatorGroup(callsign) {
            if (!callsign) return 'OTHER';
            const carrier = extractCarrier(callsign);
            if (MAJOR_CARRIERS.includes(carrier)) return 'MAJOR';
            if (REGIONAL_CARRIERS.includes(carrier)) return 'REGIONAL';
            if (FREIGHT_CARRIERS.includes(carrier)) return 'FREIGHT';
            // Check military prefixes
            for (const prefix of MILITARY_PREFIXES) {
                if (callsign.toUpperCase().startsWith(prefix)) return 'MILITARY';
            }
            // GA typically has N-numbers or short callsigns
            if (/^N[0-9]/.test(callsign.toUpperCase()) || callsign.length <= 5) return 'GA';
            return 'OTHER';
        }

        // ─────────────────────────────────────────────────────────────────────
        // ALTITUDE COLORS (Gradient)
        // ─────────────────────────────────────────────────────────────────────
        const ALTITUDE_BLOCK_COLORS = {
            'GROUND': '#666666',     // Gray - Ground/Taxi
            'SURFACE': '#8b4513',    // Brown - Surface ops (<1000)
            'LOW': '#17a2b8',        // Cyan - <FL100
            'LOWMED': '#28a745',     // Green - FL100-180
            'MED': '#59a14f',        // Light Green - FL180-240
            'MEDHIGH': '#edc948',    // Yellow - FL240-290
            'HIGH': '#f28e2b',       // Orange - FL290-350
            'VHIGH': '#e15759',      // Red - FL350-410
            'SUPERHIGH': '#9c27b0',  // Purple - >FL410
            '': '#6c757d'
        };

        function getAltitudeBlockColor(altitude) {
            const alt = parseInt(altitude) || 0;
            const fl = alt / 100;
            if (fl < 5) return ALTITUDE_BLOCK_COLORS['GROUND'];
            if (fl < 10) return ALTITUDE_BLOCK_COLORS['SURFACE'];
            if (fl < 100) return ALTITUDE_BLOCK_COLORS['LOW'];
            if (fl < 180) return ALTITUDE_BLOCK_COLORS['LOWMED'];
            if (fl < 240) return ALTITUDE_BLOCK_COLORS['MED'];
            if (fl < 290) return ALTITUDE_BLOCK_COLORS['MEDHIGH'];
            if (fl < 350) return ALTITUDE_BLOCK_COLORS['HIGH'];
            if (fl < 410) return ALTITUDE_BLOCK_COLORS['VHIGH'];
            return ALTITUDE_BLOCK_COLORS['SUPERHIGH'];
        }

        // ─────────────────────────────────────────────────────────────────────
        // WAKE TURBULENCE CATEGORY (FAA JO 7110.126B)
        // ─────────────────────────────────────────────────────────────────────
        const WAKE_CATEGORY_COLORS = {
            'SUPER': '#9c27b0',     // Purple - A380
            'HEAVY': '#e15759',     // Red - Heavy jets
            'B757': '#f28e2b',      // Orange - Special B757 category
            'LARGE': '#edc948',     // Yellow - Large aircraft
            'SMALL': '#59a14f',     // Green - Small aircraft
            '': '#6c757d'
        };

        // Aircraft type to wake category mapping
        const WAKE_CATEGORY_MAP = {
            'A388': 'SUPER', 'A380': 'SUPER',
            'B757': 'B757', 'B752': 'B757', 'B753': 'B757',
            // Heavy
            'B744': 'HEAVY', 'B748': 'HEAVY', 'B772': 'HEAVY', 'B773': 'HEAVY', 'B77L': 'HEAVY', 'B77W': 'HEAVY',
            'B788': 'HEAVY', 'B789': 'HEAVY', 'B78X': 'HEAVY', 'B763': 'HEAVY', 'B764': 'HEAVY',
            'A333': 'HEAVY', 'A332': 'HEAVY', 'A339': 'HEAVY', 'A359': 'HEAVY', 'A35K': 'HEAVY',
            'A346': 'HEAVY', 'A345': 'HEAVY', 'A343': 'HEAVY', 'A342': 'HEAVY',
            'MD11': 'HEAVY', 'DC10': 'HEAVY', 'L101': 'HEAVY'
        };

        function getWakeCategory(acType, weightClass) {
            if (!acType) {
                // Fall back to weight class
                const wc = (weightClass || '').toUpperCase();
                if (wc === 'J' || wc === 'SUPER') return 'SUPER';
                if (wc === 'H' || wc === 'HEAVY') return 'HEAVY';
                if (wc === 'L' || wc === 'LARGE') return 'LARGE';
                return 'SMALL';
            }
            const type = acType.toUpperCase();
            if (WAKE_CATEGORY_MAP[type]) return WAKE_CATEGORY_MAP[type];
            // Heuristics based on type code
            if (/^A38/.test(type)) return 'SUPER';
            if (/^B75[0-9]/.test(type)) return 'B757';
            if (/^B7[4678]|^A3[345]|^MD11|^DC10|^IL96/.test(type)) return 'HEAVY';
            if (/^B73[0-9]|^A3[12]|^MD[89]|^CRJ|^E[0-9]{3}/.test(type)) return 'LARGE';
            return 'SMALL';
        }

        // ─────────────────────────────────────────────────────────────────────
        // ETA COLORS (Relative and Hour-based)
        // ─────────────────────────────────────────────────────────────────────
        const ETA_RELATIVE_COLORS = {
            'ETA_15': '#e15759',     // Red - imminent
            'ETA_30': '#f28e2b',     // Orange
            'ETA_60': '#edc948',     // Yellow
            'ETA_90': '#59a14f',     // Green
            'ETA_120': '#4e79a7',    // Blue
            'ETA_180': '#76b7b2',    // Teal
            'ETA_300': '#b07aa1',    // Purple
            'ETA_480': '#9c755f',    // Brown
            'ETA_OVER': '#6c757d',   // Gray - >8 hours
            '': '#6c757d'
        };

        function getEtaRelativeCategory(etaUtc) {
            if (!etaUtc) return '';
            const now = new Date();
            const eta = new Date(etaUtc);
            const diffMin = (eta - now) / 60000;
            if (diffMin <= 15) return 'ETA_15';
            if (diffMin <= 30) return 'ETA_30';
            if (diffMin <= 60) return 'ETA_60';
            if (diffMin <= 90) return 'ETA_90';
            if (diffMin <= 120) return 'ETA_120';
            if (diffMin <= 180) return 'ETA_180';
            if (diffMin <= 300) return 'ETA_300';
            if (diffMin <= 480) return 'ETA_480';
            return 'ETA_OVER';
        }

        // ETA Hour gradient (0-23 hours mapped to color wheel)
        function getEtaHourColor(etaUtc) {
            if (!etaUtc) return '#6c757d';
            const eta = new Date(etaUtc);
            const hour = eta.getUTCHours();
            // Map hour to hue (0-360 degrees)
            const hue = (hour / 24) * 360;
            return `hsl(${hue}, 70%, 50%)`;
        }

        const SPEED_COLORS = { 'SLOW': '#17a2b8', 'FAST': '#dc3545' };
        const ARR_DEP_COLORS = { 'ARR': '#59a14f', 'DEP': '#4e79a7' };

        // ─────────────────────────────────────────────────────────────────────
        // USER-DEFINED COLOR FILTER RULES
        // ─────────────────────────────────────────────────────────────────────
        
        // Load saved rules from localStorage
        function loadColorRules() {
            try {
                const saved = localStorage.getItem('adl_color_rules');
                if (saved) {
                    state.colorRules = JSON.parse(saved);
                    console.log('[ADL-ML] Loaded', state.colorRules.length, 'color rules');
                }
            } catch (e) {
                console.error('[ADL-ML] Failed to load color rules:', e);
            }
        }

        // Save rules to localStorage
        function saveColorRules() {
            try {
                localStorage.setItem('adl_color_rules', JSON.stringify(state.colorRules));
            } catch (e) {
                console.error('[ADL-ML] Failed to save color rules:', e);
            }
        }

        // Parse color - supports hex, named colors, and CSS colors
        function parseColor(colorStr) {
            if (!colorStr) return null;
            const c = colorStr.toLowerCase().trim();
            if (NAMED_COLORS[c]) return NAMED_COLORS[c];
            if (/^#[0-9a-f]{3,8}$/i.test(c)) return c;
            return colorStr; // Return as-is for CSS colors like rgb()
        }

        // Check if value matches pattern (supports wildcards)
        function matchesPattern(value, pattern) {
            if (!value || !pattern) return false;
            const v = String(value).toUpperCase();
            const p = String(pattern).toUpperCase().trim();
            
            // Wildcard matching
            if (p.includes('*')) {
                const regex = new RegExp('^' + p.replace(/\*/g, '.*') + '$');
                return regex.test(v);
            }
            return v === p;
        }

        // Check if flight matches a color rule
        function flightMatchesRule(flight, rule) {
            if (!rule.field || !rule.values || rule.values.length === 0) return false;

            const values = rule.values;
            let flightValue = '';

            switch (rule.field) {
                case 'origin':
                case 'orig':
                case 'dep':
                    flightValue = flight.fp_dept_icao || '';
                    break;
                case 'dest':
                case 'destination':
                case 'arr':
                    flightValue = flight.fp_dest_icao || '';
                    break;
                case 'carrier':
                    flightValue = extractCarrier(flight.callsign);
                    break;
                case 'callsign':
                case 'acid':
                    flightValue = flight.callsign || '';
                    break;
                case 'aircraft_type':
                case 'actype':
                case 'type':
                    flightValue = flight.aircraft_type || '';
                    break;
                case 'weight_class':
                case 'weight':
                case 'wc':
                    flightValue = flight.weight_class || '';
                    break;
                case 'dep_center':
                case 'origin_center':
                    flightValue = getAirportCenter(flight.fp_dept_icao);
                    break;
                case 'arr_center':
                case 'dest_center':
                    flightValue = getAirportCenter(flight.fp_dest_icao);
                    break;
                default:
                    return false;
            }

            // Check if any value pattern matches
            return values.some(pattern => matchesPattern(flightValue, pattern));
        }

        // Get color for flight using custom rules first, then fall back to scheme
        function getFlightColorWithRules(flight) {
            // Check custom rules in order (first match wins)
            for (const rule of state.colorRules) {
                if (rule.enabled !== false && flightMatchesRule(flight, rule)) {
                    return parseColor(rule.color) || '#ffffff';
                }
            }
            // Fall back to standard color scheme
            return getFlightColorByScheme(flight);
        }

        // ─────────────────────────────────────────────────────────────────────
        // COLOR RULE MANAGEMENT UI
        // ─────────────────────────────────────────────────────────────────────

        function renderColorRulesUI() {
            const $container = $('#adl_color_rules_container');
            if (!$container.length) return;

            let html = `
                <div class="color-rules-header d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted">Custom Color Rules (evaluated in order)</small>
                    <button type="button" class="btn btn-sm btn-outline-success" id="adl_add_color_rule">
                        <i class="fas fa-plus"></i> Add
                    </button>
                </div>
                <div class="color-rules-list" id="adl_color_rules_list">
            `;

            if (state.colorRules.length === 0) {
                html += '<div class="text-muted small">No custom rules defined</div>';
            } else {
                state.colorRules.forEach((rule, idx) => {
                    const color = parseColor(rule.color) || '#6c757d';
                    html += `
                        <div class="color-rule-item d-flex align-items-center mb-1 p-1 border rounded ${rule.enabled === false ? 'opacity-50' : ''}" data-idx="${idx}">
                            <span class="color-swatch mr-2" style="display:inline-block;width:16px;height:16px;background:${color};border:1px solid #333;border-radius:2px;flex-shrink:0;"></span>
                            <span class="rule-text flex-grow-1 small text-truncate" title="${rule.field}: ${rule.values.join(' ')}">
                                <strong>${rule.field}</strong>: ${rule.values.join(', ')}
                            </span>
                            <button type="button" class="btn btn-xs btn-link text-info p-0 mx-1 rule-edit" data-idx="${idx}" title="Edit"><i class="fas fa-edit"></i></button>
                            <button type="button" class="btn btn-xs btn-link text-warning p-0 mx-1 rule-toggle" data-idx="${idx}" title="Toggle"><i class="fas fa-${rule.enabled === false ? 'eye-slash' : 'eye'}"></i></button>
                            <button type="button" class="btn btn-xs btn-link text-danger p-0 rule-delete" data-idx="${idx}" title="Delete"><i class="fas fa-times"></i></button>
                        </div>
                    `;
                });
            }
            html += '</div>';
            $container.html(html);

            // Bind events
            $('#adl_add_color_rule').off('click').on('click', () => showColorRuleModal());
            $('.rule-edit').off('click').on('click', function() { showColorRuleModal($(this).data('idx')); });
            $('.rule-toggle').off('click').on('click', function() { toggleColorRule($(this).data('idx')); });
            $('.rule-delete').off('click').on('click', function() { deleteColorRule($(this).data('idx')); });
        }

        function showColorRuleModal(editIdx = null) {
            const isEdit = editIdx !== null;
            const rule = isEdit ? state.colorRules[editIdx] : { field: 'carrier', values: [], color: 'blue', enabled: true };

            // Remove existing modal
            $('#colorRuleModal').remove();

            const modalHtml = `
                <div class="modal fade" id="colorRuleModal" tabindex="-1">
                    <div class="modal-dialog modal-sm">
                        <div class="modal-content">
                            <div class="modal-header py-2">
                                <h6 class="modal-title">${isEdit ? 'Edit' : 'Add'} Color Rule</h6>
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                            </div>
                            <div class="modal-body">
                                <div class="form-group mb-2">
                                    <label class="small mb-1">Field</label>
                                    <select class="form-control form-control-sm" id="rule_field">
                                        <option value="carrier" ${rule.field === 'carrier' ? 'selected' : ''}>Carrier (3-letter code)</option>
                                        <option value="origin" ${rule.field === 'origin' ? 'selected' : ''}>Origin Airport</option>
                                        <option value="dest" ${rule.field === 'dest' ? 'selected' : ''}>Destination Airport</option>
                                        <option value="aircraft_type" ${rule.field === 'aircraft_type' ? 'selected' : ''}>Aircraft Type</option>
                                        <option value="weight_class" ${rule.field === 'weight_class' ? 'selected' : ''}>Weight Class</option>
                                        <option value="dep_center" ${rule.field === 'dep_center' ? 'selected' : ''}>Departure Center</option>
                                        <option value="arr_center" ${rule.field === 'arr_center' ? 'selected' : ''}>Arrival Center</option>
                                        <option value="callsign" ${rule.field === 'callsign' ? 'selected' : ''}>Callsign</option>
                                    </select>
                                </div>
                                <div class="form-group mb-2">
                                    <label class="small mb-1">Values <span class="text-muted">(space-separated, * for wildcard)</span></label>
                                    <input type="text" class="form-control form-control-sm" id="rule_values" 
                                        placeholder="AAL DAL UAL or B76* or KATL KJFK" 
                                        value="${rule.values.join(' ')}">
                                    <small class="text-muted">Examples: AAL SWA JBU, B73*, KATL KORD</small>
                                </div>
                                <div class="form-group mb-2">
                                    <label class="small mb-1">Color</label>
                                    <div class="d-flex align-items-center">
                                        <input type="text" class="form-control form-control-sm mr-2" id="rule_color" 
                                            placeholder="blue, #ff6600, or color name" 
                                            value="${rule.color}" style="flex:1;">
                                        <input type="color" class="form-control form-control-sm" id="rule_color_picker" 
                                            value="${parseColor(rule.color) || '#4e79a7'}" style="width:40px;padding:2px;">
                                    </div>
                                    <small class="text-muted">Named: red, blue, green, orange, teal, purple, etc.</small>
                                </div>
                                <div class="quick-colors mb-2">
                                    <small class="text-muted d-block mb-1">Quick colors:</small>
                                    ${TABLEAU_10.map(c => `<span class="quick-color" data-color="${c}" style="display:inline-block;width:20px;height:20px;background:${c};border:1px solid #333;border-radius:2px;margin:1px;cursor:pointer;" title="${c}"></span>`).join('')}
                                </div>
                            </div>
                            <div class="modal-footer py-2">
                                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-sm btn-primary" id="save_color_rule">${isEdit ? 'Save' : 'Add'}</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            $('body').append(modalHtml);

            // Color picker sync
            $('#rule_color_picker').on('input', function() {
                $('#rule_color').val($(this).val());
            });
            $('#rule_color').on('input', function() {
                const parsed = parseColor($(this).val());
                if (parsed) $('#rule_color_picker').val(parsed);
            });

            // Quick color selection
            $('.quick-color').on('click', function() {
                const color = $(this).data('color');
                $('#rule_color').val(color);
                $('#rule_color_picker').val(color);
            });

            // Save handler
            $('#save_color_rule').on('click', function() {
                const field = $('#rule_field').val();
                const valuesStr = $('#rule_values').val().trim();
                const color = $('#rule_color').val().trim();

                if (!valuesStr || !color) {
                    alert('Please enter values and a color');
                    return;
                }

                const values = valuesStr.split(/\s+/).filter(v => v);
                const newRule = { field, values, color, enabled: true };

                if (isEdit) {
                    state.colorRules[editIdx] = newRule;
                } else {
                    state.colorRules.push(newRule);
                }

                saveColorRules();
                renderColorRulesUI();
                updateDisplay();
                $('#colorRuleModal').modal('hide');
            });

            $('#colorRuleModal').modal('show');
        }

        function toggleColorRule(idx) {
            if (state.colorRules[idx]) {
                state.colorRules[idx].enabled = state.colorRules[idx].enabled === false ? true : false;
                saveColorRules();
                renderColorRulesUI();
                updateDisplay();
            }
        }

        function deleteColorRule(idx) {
            if (confirm('Delete this color rule?')) {
                state.colorRules.splice(idx, 1);
                saveColorRules();
                renderColorRulesUI();
                updateDisplay();
            }
        }

        // Helper functions
        function extractCarrier(callsign) {
            if (!callsign) return '';
            const match = callsign.match(/^([A-Z]{3})/);
            return match ? match[1] : '';
        }

        function getAirportCenter(icao) {
            if (!icao) return '';
            const code = icao.toUpperCase();
            // Comprehensive airport to ARTCC mapping
            const centerMap = {
                // ZNY - New York
                'KJFK': 'ZNY', 'KEWR': 'ZNY', 'KLGA': 'ZNY', 'KTEB': 'ZNY', 'KISP': 'ZNY', 'KHPN': 'ZNY', 'KSWF': 'ZNY',
                // ZBW - Boston
                'KBOS': 'ZBW', 'KBDL': 'ZBW', 'KPVD': 'ZBW', 'KMHT': 'ZBW', 'KPWM': 'ZBW', 'KACK': 'ZBW',
                // ZDC - Washington
                'KDCA': 'ZDC', 'KIAD': 'ZDC', 'KBWI': 'ZDC', 'KRIC': 'ZDC', 'KORF': 'ZDC', 'KRDU': 'ZDC',
                // ZOB - Cleveland
                'KCLE': 'ZOB', 'KPIT': 'ZOB', 'KDTW': 'ZOB', 'KCMH': 'ZOB', 'KDAY': 'ZOB', 'KBUF': 'ZOB', 'KROC': 'ZOB', 'KSYR': 'ZOB',
                // ZAU - Chicago
                'KORD': 'ZAU', 'KMDW': 'ZAU', 'KMKE': 'ZAU', 'KGRR': 'ZAU',
                // ZID - Indianapolis
                'KIND': 'ZID', 'KCVG': 'ZID', 'KSDF': 'ZID', 'KLEX': 'ZID',
                // ZTL - Atlanta
                'KATL': 'ZTL', 'KCLT': 'ZTL', 'KGSP': 'ZTL', 'KBHM': 'ZTL', 'KHSV': 'ZTL',
                // ZJX - Jacksonville
                'KJAX': 'ZJX', 'KMCO': 'ZJX', 'KTPA': 'ZJX', 'KPIE': 'ZJX', 'KSRQ': 'ZJX', 'KRSW': 'ZJX',
                // ZMA - Miami
                'KMIA': 'ZMA', 'KFLL': 'ZMA', 'KPBI': 'ZMA',
                // ZME - Memphis
                'KMEM': 'ZME', 'KBNA': 'ZME', 'KLIT': 'ZME',
                // ZKC - Kansas City
                'KMCI': 'ZKC', 'KSTL': 'ZKC', 'KSGF': 'ZKC', 'KICT': 'ZKC', 'KTUL': 'ZKC', 'KOKC': 'ZKC',
                // ZMP - Minneapolis
                'KMSP': 'ZMP', 'KFAR': 'ZMP', 'KFSD': 'ZMP',
                // ZFW - Fort Worth
                'KDFW': 'ZFW', 'KDAL': 'ZFW', 'KAUS': 'ZFW', 'KSAT': 'ZFW', 'KELP': 'ZFW',
                // ZHU - Houston
                'KIAH': 'ZHU', 'KHOU': 'ZHU', 'KMSY': 'ZHU', 'KBTR': 'ZHU',
                // ZAB - Albuquerque
                'KPHX': 'ZAB', 'KABQ': 'ZAB', 'KTUS': 'ZAB',
                // ZDV - Denver
                'KDEN': 'ZDV', 'KCOS': 'ZDV', 'KASE': 'ZDV', 'KEGE': 'ZDV',
                // ZLC - Salt Lake City
                'KSLC': 'ZLC', 'KBOI': 'ZLC',
                // ZLA - Los Angeles
                'KLAX': 'ZLA', 'KSAN': 'ZLA', 'KLAS': 'ZLA', 'KONT': 'ZLA', 'KBUR': 'ZLA', 'KSNA': 'ZLA', 'KLGB': 'ZLA', 'KPSP': 'ZLA',
                // ZOA - Oakland
                'KSFO': 'ZOA', 'KOAK': 'ZOA', 'KSJC': 'ZOA', 'KSMF': 'ZOA', 'KRNO': 'ZOA',
                // ZSE - Seattle
                'KSEA': 'ZSE', 'KPDX': 'ZSE', 'KGEG': 'ZSE',
                // ZAN - Anchorage
                'PANC': 'ZAN', 'PAFA': 'ZAN',
                // ZHN - Honolulu
                'PHNL': 'ZHN', 'PHOG': 'ZHN', 'PHKO': 'ZHN', 'PHLI': 'ZHN'
            };
            return centerMap[code] || '';
        }

        // Get TRACON for an airport
        function getAirportTracon(icao) {
            if (!icao) return '';
            const code = icao.toUpperCase();
            // Major airport to TRACON mapping
            const traconMap = {
                // N90 - New York
                'KJFK': 'N90', 'KEWR': 'N90', 'KLGA': 'N90', 'KTEB': 'N90', 'KISP': 'N90', 'KHPN': 'N90',
                // A90 - Boston
                'KBOS': 'A90', 'KBDL': 'A90', 'KPVD': 'A90',
                // PCT - Potomac
                'KDCA': 'PCT', 'KIAD': 'PCT', 'KBWI': 'PCT',
                // A80 - Atlanta
                'KATL': 'A80',
                // CLT - Charlotte
                'KCLT': 'CLT',
                // C90 - Chicago
                'KORD': 'C90', 'KMDW': 'C90',
                // D21 - Detroit
                'KDTW': 'D21',
                // D10 - Dallas
                'KDFW': 'D10', 'KDAL': 'D10',
                // I90 - Houston
                'KIAH': 'I90', 'KHOU': 'I90',
                // MIA - Miami
                'KMIA': 'MIA', 'KFLL': 'MIA', 'KPBI': 'MIA',
                // F11 - Orlando
                'KMCO': 'F11',
                // TPA - Tampa
                'KTPA': 'TPA',
                // L30 - SoCal
                'KLAX': 'L30', 'KSAN': 'L30', 'KONT': 'L30', 'KBUR': 'L30', 'KSNA': 'L30', 'KLGB': 'L30',
                // NCT - NorCal
                'KSFO': 'NCT', 'KOAK': 'NCT', 'KSJC': 'NCT',
                // S46 - Seattle
                'KSEA': 'S46',
                // P50 - Phoenix
                'KPHX': 'P50',
                // D01 - Denver
                'KDEN': 'D01',
                // R90 - Kansas City
                'KMCI': 'R90',
                // M98 - Minneapolis
                'KMSP': 'M98',
                // LAS - Las Vegas
                'KLAS': 'LAS',
                // SLC - Salt Lake
                'KSLC': 'SLC'
            };
            return traconMap[code] || '';
        }

        // Get color by standard scheme (when no custom rules match)
        function getFlightColorByScheme(flight) {
            switch (state.colorBy) {
                case 'weight_class':
                    const wc = (flight.weight_class || '').toUpperCase().trim();
                    return WEIGHT_CLASS_COLORS[wc] || WEIGHT_CLASS_COLORS[''];
                case 'aircraft_category':
                    const wcCat = (flight.weight_class || '').toUpperCase().trim();
                    if (wcCat === 'J' || wcCat === 'SUPER' || wcCat === 'HEAVY' || wcCat === 'H') return AIRCRAFT_CATEGORY_COLORS['JET'];
                    if (wcCat === 'T') return AIRCRAFT_CATEGORY_COLORS['TURBO'];
                    return AIRCRAFT_CATEGORY_COLORS['PROP'];
                case 'aircraft_type':
                    const mfr = getAircraftManufacturer(flight.aircraft_type);
                    return AIRCRAFT_MANUFACTURER_COLORS[mfr] || AIRCRAFT_MANUFACTURER_COLORS['OTHER'];
                case 'aircraft_config':
                    const cfg = getAircraftConfig(flight.aircraft_type);
                    return AIRCRAFT_CONFIG_COLORS[cfg] || AIRCRAFT_CONFIG_COLORS['OTHER'];
                case 'wake_category':
                    const wake = getWakeCategory(flight.aircraft_type, flight.weight_class);
                    return WAKE_CATEGORY_COLORS[wake] || WAKE_CATEGORY_COLORS[''];
                case 'carrier':
                    const carrier = extractCarrier(flight.callsign);
                    return CARRIER_COLORS[carrier] || CARRIER_COLORS[''];
                case 'operator_group':
                    const opGroup = getOperatorGroup(flight.callsign);
                    return OPERATOR_GROUP_COLORS[opGroup] || OPERATOR_GROUP_COLORS['OTHER'];
                case 'dep_center':
                    const depCenter = getAirportCenter(flight.fp_dept_icao);
                    return CENTER_COLORS[depCenter] || CENTER_COLORS[''];
                case 'arr_center':
                    const arrCenter = getAirportCenter(flight.fp_dest_icao);
                    return CENTER_COLORS[arrCenter] || CENTER_COLORS[''];
                case 'dep_tracon':
                    const depTracon = getAirportTracon(flight.fp_dept_icao);
                    return getTraconColor(depTracon);
                case 'arr_tracon':
                    const arrTracon = getAirportTracon(flight.fp_dest_icao);
                    return getTraconColor(arrTracon);
                case 'dcc_region':
                    const center = getAirportCenter(flight.fp_dept_icao) || getAirportCenter(flight.fp_dest_icao);
                    for (const [region, centers] of Object.entries(DCC_REGIONS)) {
                        if (centers.includes(center)) return DCC_REGION_COLORS[region];
                    }
                    return DCC_REGION_COLORS[''];
                case 'dep_airport':
                    const depTier = getAirportTier(flight.fp_dept_icao);
                    return AIRPORT_TIER_COLORS[depTier];
                case 'arr_airport':
                    const arrTier = getAirportTier(flight.fp_dest_icao);
                    return AIRPORT_TIER_COLORS[arrTier];
                case 'altitude':
                    return getAltitudeBlockColor(flight.altitude);
                case 'eta_relative':
                    const etaRelCat = getEtaRelativeCategory(flight.eta_runway_utc || flight.eta_utc);
                    return ETA_RELATIVE_COLORS[etaRelCat] || ETA_RELATIVE_COLORS[''];
                case 'eta_hour':
                    return getEtaHourColor(flight.eta_runway_utc || flight.eta_utc);
                case 'speed':
                    const spd = parseInt(flight.groundspeed_kts || flight.groundspeed) || 0;
                    return spd < 250 ? SPEED_COLORS['SLOW'] : SPEED_COLORS['FAST'];
                case 'arr_dep':
                    const altVal = parseInt(flight.altitude) || 0;
                    if (flight.groundspeed && parseInt(flight.groundspeed) < 50) {
                        return '#666666'; // Parked
                    }
                    return altVal > 10000 ? ARR_DEP_COLORS['ARR'] : ARR_DEP_COLORS['DEP'];
                case 'custom':
                    // Custom mode uses only user-defined rules
                    return '#6c757d';
                case 'reroute_match':
                    return getRerouteMatchColor(flight);
                case 'status':
                    // Phase-based coloring from phase-colors.js
                    const phase = (flight.phase || '').toLowerCase();
                    if (typeof PHASE_COLORS !== 'undefined' && PHASE_COLORS[phase]) {
                        return PHASE_COLORS[phase];
                    }
                    // Fallback for unknown phases
                    if (!phase) {
                        return (typeof PHASE_COLORS !== 'undefined') ? PHASE_COLORS['unknown'] : '#eab308';
                    }
                    return '#999999';
                default:
                    return '#ffffff';
            }
        }

        /**
         * Get color for flight based on matching public reroutes
         * Only matches against visible (active/future) routes
         * Supports airports (KJFK), ARTCCs (ZMA, ZNY), and TRACONs (A80, N90)
         */
        function getRerouteMatchColor(flight) {
            const origin = (flight.fp_dept_icao || '').toUpperCase();
            const dest = (flight.fp_dest_icao || '').toUpperCase();
            const depArtcc = (flight.fp_dept_artcc || '').toUpperCase();
            const arrArtcc = (flight.fp_dest_artcc || '').toUpperCase();
            const depTracon = (flight.dep_tracon || '').toUpperCase();
            const arrTracon = (flight.arr_tracon || '').toUpperCase();
            
            if (!origin && !dest && !depArtcc && !arrArtcc) return '#666666'; // Gray - no data
            
            // Known ARTCC codes pattern (3 letters starting with Z)
            const artccPattern = /^Z[A-Z]{2}$/;
            // TRACON codes pattern
            const traconPattern = /^[A-Z][0-9]{2}$|^(NCT|PCT|SCT|A80|N90|C90|D10|I90|L30)$/;
            
            /**
             * Check if a flight matches a single filter value
             */
            function matchesFilter(filterValue, isOrigin) {
                if (!filterValue) return false;
                
                const f = filterValue.toUpperCase().trim();
                const airport = isOrigin ? origin : dest;
                const artcc = isOrigin ? depArtcc : arrArtcc;
                const tracon = isOrigin ? depTracon : arrTracon;
                
                // Direct airport match
                if (airport === f) return true;
                
                // ARTCC match
                if (artccPattern.test(f) && artcc === f) return true;
                if (artcc === f) return true;  // Also handle non-pattern ARTCC matches
                
                // TRACON match
                if (traconPattern.test(f) && tracon === f) return true;
                
                // Prefix matching (e.g., "K" matches all K-prefixed airports)
                if (f.length <= 2 && airport.startsWith(f)) return true;
                
                return false;
            }
            
            // Get VISIBLE public routes only (active + future, not hidden)
            const publicRoutes = (window.PublicRoutes && typeof window.PublicRoutes.getRoutes === 'function') 
                ? window.PublicRoutes.getRoutes() 
                : [];
            
            if (!publicRoutes || publicRoutes.length === 0) return '#666666';
            
            // Check each public route for a match
            for (const route of publicRoutes) {
                const originFilter = route.origin_filter || [];
                const destFilter = route.dest_filter || [];
                
                // Parse filters if they're strings
                const origins = Array.isArray(originFilter) ? originFilter : 
                                (typeof originFilter === 'string' ? originFilter.split(',').map(s => s.trim()) : []);
                const dests = Array.isArray(destFilter) ? destFilter : 
                              (typeof destFilter === 'string' ? destFilter.split(',').map(s => s.trim()) : []);
                
                // Normalize filter values to uppercase
                const normalizedOrigins = origins.map(o => (o || '').toUpperCase().trim()).filter(o => o);
                const normalizedDests = dests.map(d => (d || '').toUpperCase().trim()).filter(d => d);
                
                // Check origin match - requires non-empty origin filter to match
                let originMatch = false;
                if (normalizedOrigins.length > 0) {
                    for (const o of normalizedOrigins) {
                        if (matchesFilter(o, true)) {
                            originMatch = true;
                            break;
                        }
                    }
                }
                
                // Check dest match - requires non-empty dest filter to match
                let destMatch = false;
                if (normalizedDests.length > 0) {
                    for (const d of normalizedDests) {
                        if (matchesFilter(d, false)) {
                            destMatch = true;
                            break;
                        }
                    }
                }
                
                // If both origin and dest match (requires both filters to be non-empty)
                if (originMatch && destMatch) {
                    return route.color || '#17a2b8';
                }
            }
            
            // No match - return lighter gray for better visibility
            return '#666666';
        }

        /**
         * Strip ICAO equipment/navigation suffixes from aircraft type
         * e.g., B738/L -> B738, A320/G -> A320, C172/U -> C172
         */
        function stripAircraftSuffixes(acType) {
            if (!acType) return '';
            // Remove everything after first slash (equipment suffix)
            // Also handles formats like B738-L or B738_L
            return acType.split(/[\/\-_]/)[0].toUpperCase();
        }

        // Main color function - uses custom rules + scheme
        function getFlightColor(flight) {
            return getFlightColorWithRules(flight);
        }

        function renderColorLegend() {
            const $legend = $('#adl_color_legend');
            if (!$legend.length) return;
            let items = [];

            switch (state.colorBy) {
                case 'weight_class':
                    items = [
                        { color: WEIGHT_CLASS_COLORS['SUPER'], label: 'Super (▬▬)' },
                        { color: WEIGHT_CLASS_COLORS['HEAVY'], label: 'Heavy (═)' },
                        { color: WEIGHT_CLASS_COLORS['LARGE'], label: 'Large (✈)' },
                        { color: WEIGHT_CLASS_COLORS['SMALL'], label: 'Small (○)' }
                    ];
                    break;
                case 'aircraft_category':
                    items = [
                        { color: AIRCRAFT_CATEGORY_COLORS['JET'], label: 'Jet' },
                        { color: AIRCRAFT_CATEGORY_COLORS['TURBO'], label: 'Turbo' },
                        { color: AIRCRAFT_CATEGORY_COLORS['PROP'], label: 'Prop' }
                    ];
                    break;
                case 'aircraft_type':
                    items = [
                        { color: AIRCRAFT_MANUFACTURER_COLORS['AIRBUS'], label: 'Airbus' },
                        { color: AIRCRAFT_MANUFACTURER_COLORS['BOEING'], label: 'Boeing' },
                        { color: AIRCRAFT_MANUFACTURER_COLORS['EMBRAER'], label: 'Embraer' },
                        { color: AIRCRAFT_MANUFACTURER_COLORS['BOMBARDIER'], label: 'Bombardier' },
                        { color: AIRCRAFT_MANUFACTURER_COLORS['MD_DC'], label: 'MD/DC' },
                        { color: AIRCRAFT_MANUFACTURER_COLORS['OTHER'], label: 'Other' }
                    ];
                    break;
                case 'aircraft_config':
                    items = [
                        { color: AIRCRAFT_CONFIG_COLORS['A380'], label: 'A380' },
                        { color: AIRCRAFT_CONFIG_COLORS['QUAD_JET'], label: 'Quad-jet' },
                        { color: AIRCRAFT_CONFIG_COLORS['HEAVY_TWIN'], label: 'Heavy Twin' },
                        { color: AIRCRAFT_CONFIG_COLORS['TRI_JET'], label: 'Tri-jet' },
                        { color: AIRCRAFT_CONFIG_COLORS['TWIN_JET'], label: 'Twin-jet' },
                        { color: AIRCRAFT_CONFIG_COLORS['REGIONAL_JET'], label: 'Regional' },
                        { color: AIRCRAFT_CONFIG_COLORS['TURBOPROP'], label: 'Turboprop' },
                        { color: AIRCRAFT_CONFIG_COLORS['PROP'], label: 'Prop' }
                    ];
                    break;
                case 'wake_category':
                    items = [
                        { color: WAKE_CATEGORY_COLORS['SUPER'], label: 'Super' },
                        { color: WAKE_CATEGORY_COLORS['HEAVY'], label: 'Heavy' },
                        { color: WAKE_CATEGORY_COLORS['B757'], label: 'B757' },
                        { color: WAKE_CATEGORY_COLORS['LARGE'], label: 'Large' },
                        { color: WAKE_CATEGORY_COLORS['SMALL'], label: 'Small' }
                    ];
                    break;
                case 'altitude':
                    items = [
                        { color: ALTITUDE_BLOCK_COLORS['GROUND'], label: 'Ground' },
                        { color: ALTITUDE_BLOCK_COLORS['LOW'], label: '<FL100' },
                        { color: ALTITUDE_BLOCK_COLORS['LOWMED'], label: 'FL100-180' },
                        { color: ALTITUDE_BLOCK_COLORS['MED'], label: 'FL180-240' },
                        { color: ALTITUDE_BLOCK_COLORS['MEDHIGH'], label: 'FL240-290' },
                        { color: ALTITUDE_BLOCK_COLORS['HIGH'], label: 'FL290-350' },
                        { color: ALTITUDE_BLOCK_COLORS['VHIGH'], label: 'FL350-410' },
                        { color: ALTITUDE_BLOCK_COLORS['SUPERHIGH'], label: '>FL410' }
                    ];
                    break;
                case 'arr_dep':
                    items = [
                        { color: ARR_DEP_COLORS['ARR'], label: 'Enroute' },
                        { color: ARR_DEP_COLORS['DEP'], label: 'Climbing' },
                        { color: '#666666', label: 'Ground' }
                    ];
                    break;
                case 'carrier':
                    items = [
                        { color: CARRIER_COLORS['AAL'], label: 'AAL' },
                        { color: CARRIER_COLORS['UAL'], label: 'UAL' },
                        { color: CARRIER_COLORS['DAL'], label: 'DAL' },
                        { color: CARRIER_COLORS['SWA'], label: 'SWA' },
                        { color: CARRIER_COLORS['JBU'], label: 'JBU' },
                        { color: CARRIER_COLORS['ASA'], label: 'ASA' },
                        { color: CARRIER_COLORS['FDX'], label: 'FDX' },
                        { color: CARRIER_COLORS[''], label: '...' }
                    ];
                    break;
                case 'operator_group':
                    items = [
                        { color: OPERATOR_GROUP_COLORS['MAJOR'], label: 'Major' },
                        { color: OPERATOR_GROUP_COLORS['REGIONAL'], label: 'Regional' },
                        { color: OPERATOR_GROUP_COLORS['FREIGHT'], label: 'Freight' },
                        { color: OPERATOR_GROUP_COLORS['GA'], label: 'GA' },
                        { color: OPERATOR_GROUP_COLORS['MILITARY'], label: 'Military' },
                        { color: OPERATOR_GROUP_COLORS['OTHER'], label: 'Other' }
                    ];
                    break;
                case 'dep_center':
                case 'arr_center':
                    items = Object.entries(CENTER_COLORS)
                        .filter(([k]) => k && k !== '')
                        .slice(0, 12)
                        .map(([k, v]) => ({ color: v, label: k }));
                    items.push({ color: CENTER_COLORS[''], label: '...' });
                    break;
                case 'dep_tracon':
                case 'arr_tracon':
                    items = [
                        { color: DCC_REGION_COLORS['WEST'], label: 'West' },
                        { color: DCC_REGION_COLORS['SOUTH_CENTRAL'], label: 'S.Central' },
                        { color: DCC_REGION_COLORS['MIDWEST'], label: 'Midwest' },
                        { color: DCC_REGION_COLORS['SOUTHEAST'], label: 'Southeast' },
                        { color: DCC_REGION_COLORS['NORTHEAST'], label: 'Northeast' }
                    ];
                    break;
                case 'dcc_region':
                    items = [
                        { color: DCC_REGION_COLORS['WEST'], label: 'West' },
                        { color: DCC_REGION_COLORS['SOUTH_CENTRAL'], label: 'South Central' },
                        { color: DCC_REGION_COLORS['MIDWEST'], label: 'Midwest' },
                        { color: DCC_REGION_COLORS['SOUTHEAST'], label: 'Southeast' },
                        { color: DCC_REGION_COLORS['NORTHEAST'], label: 'Northeast' }
                    ];
                    break;
                case 'dep_airport':
                case 'arr_airport':
                    items = [
                        { color: AIRPORT_TIER_COLORS['CORE30'], label: 'Core 30' },
                        { color: AIRPORT_TIER_COLORS['OEP35'], label: 'OEP 35' },
                        { color: AIRPORT_TIER_COLORS['ASPM77'], label: 'ASPM 77' },
                        { color: AIRPORT_TIER_COLORS['OTHER'], label: 'Other' }
                    ];
                    break;
                case 'eta_relative':
                    items = [
                        { color: ETA_RELATIVE_COLORS['ETA_15'], label: '≤15m' },
                        { color: ETA_RELATIVE_COLORS['ETA_30'], label: '≤30m' },
                        { color: ETA_RELATIVE_COLORS['ETA_60'], label: '≤1h' },
                        { color: ETA_RELATIVE_COLORS['ETA_120'], label: '≤2h' },
                        { color: ETA_RELATIVE_COLORS['ETA_180'], label: '≤3h' },
                        { color: ETA_RELATIVE_COLORS['ETA_300'], label: '≤5h' },
                        { color: ETA_RELATIVE_COLORS['ETA_OVER'], label: '>8h' }
                    ];
                    break;
                case 'eta_hour':
                    items = [
                        { color: 'hsl(0, 70%, 50%)', label: '00Z' },
                        { color: 'hsl(90, 70%, 50%)', label: '06Z' },
                        { color: 'hsl(180, 70%, 50%)', label: '12Z' },
                        { color: 'hsl(270, 70%, 50%)', label: '18Z' }
                    ];
                    break;
                case 'speed':
                    items = [
                        { color: SPEED_COLORS['SLOW'], label: '<250kts' },
                        { color: SPEED_COLORS['FAST'], label: '≥250kts' }
                    ];
                    break;
                case 'custom':
                    items = state.colorRules
                        .filter(r => r.enabled !== false)
                        .slice(0, 6)
                        .map(r => ({ color: parseColor(r.color), label: r.values[0] + (r.values.length > 1 ? '...' : '') }));
                    break;
                case 'reroute_match':
                    // Show only VISIBLE (active + future) public routes with their colors
                    const publicRoutes = (window.PublicRoutes && typeof window.PublicRoutes.getRoutes === 'function')
                        ? window.PublicRoutes.getRoutes()
                        : [];
                    items = publicRoutes.map(route => ({
                        color: route.color || '#17a2b8',
                        label: route.name || 'Route'
                    }));
                    items.push({ color: '#666666', label: 'No Match' });
                    break;
                case 'status':
                    // Flight phases from phase-colors.js
                    const PC = (typeof PHASE_COLORS !== 'undefined') ? PHASE_COLORS : {};
                    const PL = (typeof PHASE_LABELS !== 'undefined') ? PHASE_LABELS : {};
                    items = [
                        { color: PC['prefile'] || '#3b82f6', label: PL['prefile'] || 'Prefile' },
                        { color: PC['taxiing'] || '#22c55e', label: PL['taxiing'] || 'Taxiing' },
                        { color: PC['departed'] || '#f87171', label: PL['departed'] || 'Departed' },
                        { color: PC['enroute'] || '#dc2626', label: PL['enroute'] || 'Enroute' },
                        { color: PC['descending'] || '#991b1b', label: PL['descending'] || 'Descending' },
                        { color: PC['arrived'] || '#1a1a1a', label: PL['arrived'] || 'Arrived' },
                        { color: PC['disconnected'] || '#f97316', label: PL['disconnected'] || 'Disconnected' }
                    ];
                    break;
                default:
                    items = [{ color: '#6c757d', label: state.colorBy }];
            }

            const html = items.map(item =>
                `<span class="d-inline-flex align-items-center mr-2">
                    <span style="display:inline-block;width:12px;height:12px;background:${item.color};border:1px solid #333;border-radius:2px;margin-right:3px;"></span>
                    <span style="font-size:11px;">${item.label}</span></span>`
            ).join('');
            $legend.html(html);
        }

        // ─────────────────────────────────────────────────────────────────────
        // FLIGHT ROUTE BUILDING
        // ─────────────────────────────────────────────────────────────────────

        function buildFlightRouteString(flight) {
            const parts = [];
            if (flight.fp_dept_icao) parts.push(flight.fp_dept_icao.toUpperCase());
            if (flight.fp_route) parts.push(flight.fp_route.toUpperCase());
            if (flight.fp_dest_icao) parts.push(flight.fp_dest_icao.toUpperCase());
            return parts.join(' ');
        }

        function buildRouteCoords(flight) {
            const routeString = buildFlightRouteString(flight);
            if (!routeString) return [];
            console.log('[ADL-ML] Building route for:', flight.callsign, 'Route:', routeString);

            let tokens = routeString.split(/\s+/).filter(t => t);
            const expandedRoute = ConvertRoute(tokens.join(' '));
            const expandedTokens = expandedRoute.split(/\s+/).filter(t => t);

            const coords = [];
            let prevData;
            for (let i = 0; i < expandedTokens.length; i++) {
                const tok = expandedTokens[i].toUpperCase();
                let nextData;
                if (i < expandedTokens.length - 1) {
                    nextData = getPointByName(expandedTokens[i + 1], prevData);
                }
                const pointData = getPointByName(tok, prevData, nextData);
                if (pointData && pointData.length >= 3) {
                    coords.push({ lat: pointData[1], lon: pointData[2], name: tok });
                    prevData = pointData;
                }
            }

            // Fallback: if no route coords, try origin-dest direct
            if (coords.length < 2 && flight.fp_dept_icao && flight.fp_dest_icao) {
                const orig = getPointByName(flight.fp_dept_icao.toUpperCase());
                const dest = getPointByName(flight.fp_dest_icao.toUpperCase());
                if (orig && dest) {
                    coords.length = 0;
                    coords.push({ lat: orig[1], lon: orig[2], name: flight.fp_dept_icao });
                    coords.push({ lat: dest[1], lon: dest[2], name: flight.fp_dest_icao });
                }
            }

            console.log('[ADL-ML] Route coords:', coords.length, 'points');
            return coords;
        }

        function findClosestSegmentIndex(coords, aircraftLat, aircraftLon) {
            if (coords.length < 2) return 0;
            let minDist = Infinity, minIdx = 0;
            for (let i = 0; i < coords.length; i++) {
                const dist = Math.pow(coords[i].lat - aircraftLat, 2) + Math.pow(coords[i].lon - aircraftLon, 2);
                if (dist < minDist) {
                    minDist = dist;
                    minIdx = i;
                }
            }
            return minIdx;
        }

        // Normalize coordinates for International Date Line crossing
        function normalizeForIDL(coords) {
            if (!coords || coords.length < 2) return coords;
            const normalized = [[coords[0][0], coords[0][1]]];
            for (let i = 1; i < coords.length; i++) {
                let prevLon = normalized[i - 1][0];
                let currLon = coords[i][0];
                const currLat = coords[i][1];
                const lonDiff = currLon - prevLon;
                if (Math.abs(lonDiff) > 180) {
                    currLon += (lonDiff > 0) ? -360 : 360;
                }
                normalized.push([currLon, currLat]);
            }
            return normalized;
        }

        function toggleFlightRoute(flight) {
            const key = flight.flight_key || flight.callsign;
            console.log('[ADL-ML] Toggle route for:', key);

            if (state.drawnRoutes.has(key)) {
                state.drawnRoutes.delete(key);
                updateFlightRouteDisplay();
                console.log('[ADL-ML] Route removed for:', key);
                return false;
            }

            // Build route coordinates - use pre-parsed ADL data if available
            let coords;
            if (flight.waypoints_json && Array.isArray(flight.waypoints_json) && flight.waypoints_json.length >= 2) {
                // Use pre-parsed waypoints from ADL (no client-side parsing needed)
                coords = flight.waypoints_json.map(wp => ({
                    lat: parseFloat(wp.lat),
                    lon: parseFloat(wp.lon),
                    name: wp.fix_name || ''
                }));
                console.log('[ADL-ML] Using pre-parsed waypoints:', coords.length, 'points');
            } else {
                // Fallback: parse route client-side (for user-entered routes or missing ADL data)
                coords = buildRouteCoords(flight);
                console.log('[ADL-ML] Using client-side parsed route:', coords.length, 'points');
            }

            if (coords.length < 2) {
                console.log('[ADL-ML] Not enough coords to draw route');
                return false;
            }

            const color = getFlightColor(flight);
            const aircraftLat = parseFloat(flight.lat);
            const aircraftLon = parseFloat(flight.lon);
            const splitIdx = findClosestSegmentIndex(coords, aircraftLat, aircraftLon);

            // Behind: from origin up to aircraft position
            const behindRaw = coords.slice(0, splitIdx + 1).map(c => [c.lon, c.lat]);
            behindRaw.push([aircraftLon, aircraftLat]);

            // Ahead: from aircraft position to destination
            const aheadRaw = [[aircraftLon, aircraftLat]];
            for (let i = splitIdx; i < coords.length; i++) {
                aheadRaw.push([coords[i].lon, coords[i].lat]);
            }

            // Normalize for IDL crossing (prevents routes wrapping around globe)
            const behindCoords = normalizeForIDL(behindRaw);
            const aheadCoords = normalizeForIDL(aheadRaw);

            state.drawnRoutes.set(key, { color, behindCoords, aheadCoords, flight, fixes: coords });
            updateFlightRouteDisplay();
            console.log('[ADL-ML] Route drawn for:', key, 'with', coords.length, 'points');
            return true;
        }
        
        // Facility type detection helpers
        function detectFacilityType(code) {
            if (!code) return 'airport';
            const c = String(code).toUpperCase().trim();
            // ARTCC: Z + 2 letters (ZNY, ZDC, ZTL, etc.)
            if (/^Z[A-Z]{2}$/.test(c)) return 'artcc';
            // TRACON: Letter + 2 digits (N90, A80, C90, etc.) or 3-letter codes like PCT, NCT, SCT
            if (/^[A-Z]\d{2}$/.test(c)) return 'tracon';
            if (['PCT', 'NCT', 'SCT', 'MIA', 'TPA', 'RSW', 'PBI'].includes(c)) return 'tracon';
            // Default to airport (4-letter ICAO codes)
            return 'airport';
        }

        function updateFlightRouteDisplay() {
            if (!graphic_map || !graphic_map.getSource('flight-routes')) return;

            const lineFeatures = [];
            const fixFeatures = [];
            const endpointFeatures = [];
            
            state.drawnRoutes.forEach((routeData, key) => {
                // Line segments
                if (routeData.behindCoords && routeData.behindCoords.length >= 2) {
                    lineFeatures.push({
                        type: 'Feature',
                        properties: { segment: 'behind', color: routeData.color, callsign: key },
                        geometry: { type: 'LineString', coordinates: routeData.behindCoords }
                    });
                }
                if (routeData.aheadCoords && routeData.aheadCoords.length >= 2) {
                    lineFeatures.push({
                        type: 'Feature',
                        properties: { segment: 'ahead', color: routeData.color, callsign: key },
                        geometry: { type: 'LineString', coordinates: routeData.aheadCoords }
                    });
                }
                
                // Fix points (for labels) - only intermediate fixes, not endpoints
                if (routeData.fixes && routeData.fixes.length > 0) {
                    routeData.fixes.forEach((fix, idx) => {
                        const isOrigin = idx === 0;
                        const isDest = idx === routeData.fixes.length - 1;
                        
                        // Add to fix features (intermediate fixes get circles/labels)
                        if (!isOrigin && !isDest) {
                            fixFeatures.push({
                                type: 'Feature',
                                properties: {
                                    name: fix.name,
                                    color: routeData.color,
                                    callsign: key,
                                    index: idx
                                },
                                geometry: { type: 'Point', coordinates: [fix.lon, fix.lat] }
                            });
                        }
                        
                        // Add origin/destination to endpoint features (icons)
                        if (isOrigin || isDest) {
                            endpointFeatures.push({
                                type: 'Feature',
                                properties: {
                                    name: fix.name,
                                    color: routeData.color,
                                    callsign: key,
                                    isOrigin: isOrigin,
                                    isDest: isDest,
                                    facilityType: detectFacilityType(fix.name)
                                },
                                geometry: { type: 'Point', coordinates: [fix.lon, fix.lat] }
                            });
                        }
                    });
                }
            });

            graphic_map.getSource('flight-routes').setData({ type: 'FeatureCollection', features: lineFeatures });
            
            // Update ADL fix labels (intermediate fixes) - uses dedicated ADL source
            if (graphic_map.getSource('adl-flight-fixes')) {
                graphic_map.getSource('adl-flight-fixes').setData({ type: 'FeatureCollection', features: fixFeatures });
            }
            
            // Update endpoint icons if source exists
            if (graphic_map.getSource('route-endpoints')) {
                graphic_map.getSource('route-endpoints').setData({ type: 'FeatureCollection', features: endpointFeatures });
            }
        }

        function clearAllRoutes() {
            state.drawnRoutes.clear();
            updateFlightRouteDisplay();
            console.log('[ADL-ML] All routes cleared');
        }
        
        function showAllRoutes() {
            // Draw routes for all currently filtered flights
            let count = 0;
            const maxRoutes = 50; // Limit to prevent performance issues
            
            state.filteredFlights.forEach(flight => {
                if (count >= maxRoutes) return;
                
                const key = flight.flight_key || flight.callsign;
                // Only draw if not already drawn
                if (!state.drawnRoutes.has(key)) {
                    if (toggleFlightRoute(flight)) {
                        count++;
                    }
                }
            });
            
            console.log('[ADL-ML] Drew', count, 'routes (max:', maxRoutes, ')');
            if (count >= maxRoutes) {
                console.log('[ADL-ML] Route limit reached, some flights not drawn');
            }
        }
        
        function filterRoutesByCurrentFilter() {
            // Remove routes for flights that are no longer in filteredFlights
            const keysToRemove = [];
            
            state.drawnRoutes.forEach((routeData, key) => {
                // Check if this flight is in filtered list
                const inFiltered = state.filteredFlights.some(f => 
                    (f.flight_key || f.callsign) === key
                );
                
                if (!inFiltered) {
                    keysToRemove.push(key);
                }
            });
            
            // Remove the routes
            keysToRemove.forEach(key => {
                state.drawnRoutes.delete(key);
            });
            
            if (keysToRemove.length > 0) {
                updateFlightRouteDisplay();
                console.log('[ADL-ML] Filtered out', keysToRemove.length, 'routes');
            }
        }
        
        function refreshRouteColors() {
            // Update colors of all drawn routes based on current color scheme
            state.drawnRoutes.forEach((routeData, key) => {
                // Find the flight data for this route
                let flight = state.filteredFlights.find(f => 
                    (f.flight_key || f.callsign) === key
                );
                
                if (!flight) {
                    // Try in all flights
                    flight = state.flights.find(f => 
                        (f.flight_key || f.callsign) === key
                    );
                }
                
                if (flight) {
                    const newColor = getFlightColor(flight);
                    routeData.color = newColor;
                }
            });
            
            updateFlightRouteDisplay();
            console.log('[ADL-ML] Route colors refreshed');
        }

        // Toggle ADL route fix labels visibility (ADL-specific layers)
        function toggleRouteLabels(visible) {
            if (!graphic_map) return;
            const vis = visible ? 'visible' : 'none';
            // Toggle ADL flight fix layers
            if (graphic_map.getLayer('adl-flight-fixes-circles')) {
                graphic_map.setLayoutProperty('adl-flight-fixes-circles', 'visibility', vis);
            }
            if (graphic_map.getLayer('adl-flight-fixes-labels')) {
                graphic_map.setLayoutProperty('adl-flight-fixes-labels', 'visibility', vis);
            }
            // Also toggle endpoint icons
            if (graphic_map.getLayer('route-endpoints-symbols')) {
                graphic_map.setLayoutProperty('route-endpoints-symbols', 'visibility', vis);
            }
            if (graphic_map.getLayer('route-endpoints-labels')) {
                graphic_map.setLayoutProperty('route-endpoints-labels', 'visibility', vis);
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // DATA FETCHING
        // ─────────────────────────────────────────────────────────────────────

        function fetchFlights() {
            console.log('[ADL-ML] Fetching flights...');
            
            // Store previous data for buffered update
            var previousFlights = state.flights ? state.flights.slice() : [];
            
            return $.ajax({
                url: 'api/adl/current.php?limit=10000&active=1',
                method: 'GET',
                dataType: 'json'
            }).done(function(data) {
                if (data.error) {
                    console.error('[ADL-ML] API error:', data.error);
                    updateRefreshStatus('Error');
                    // BUFFERED: Keep previous data on error
                    return;
                }

                const allFlights = data.flights || data.rows || [];
                console.log('[ADL-ML] Total flights from API:', allFlights.length);

                const validFlights = allFlights.filter(f =>
                    f.lat != null && f.lon != null &&
                    !isNaN(parseFloat(f.lat)) && !isNaN(parseFloat(f.lon))
                );
                console.log('[ADL-ML] Flights with valid lat/lon:', validFlights.length);

                // BUFFERED: Only update if we got data, or had no prior data
                if (validFlights.length > 0 || previousFlights.length === 0) {
                    state.flights = validFlights;
                } else {
                    console.log('[ADL-ML] Empty response, keeping previous data (' + previousFlights.length + ' flights)');
                    // Keep previous flights - don't update state.flights
                }

                state.lastRefresh = new Date();
                applyFilters();
                console.log('[ADL-ML] Filtered flights:', state.filteredFlights.length);

                updateDisplay();
                updateStats();
                updateRefreshStatus();
            }).fail(function(xhr, status, err) {
                console.error('[ADL-ML] Fetch failed:', status, err);
                updateRefreshStatus('Error');
                // BUFFERED: Don't clear state.flights on error - keep showing old data
                console.log('[ADL-ML] Keeping previous data due to error (' + previousFlights.length + ' flights)');
            });
        }

        // ─────────────────────────────────────────────────────────────────────
        // FILTERING
        // ─────────────────────────────────────────────────────────────────────

        function applyFilters() {
            const f = state.filters;
            state.filteredFlights = state.flights.filter(flight => {
                // Weight class filter
                const wc = (flight.weight_class || '').toUpperCase().trim();
                const wcMatch = f.weightClasses.some(w => {
                    if (w === '' && wc === '') return true;
                    if (w === wc) return true;
                    if ((w === 'SUPER' || w === 'J') && (wc === 'SUPER' || wc === 'J')) return true;
                    if ((w === 'HEAVY' || w === 'H') && (wc === 'HEAVY' || wc === 'H')) return true;
                    if ((w === 'LARGE' || w === 'L') && (wc === 'LARGE' || wc === 'L' || wc === '')) return true;
                    if ((w === 'SMALL' || w === 'S') && (wc === 'SMALL' || wc === 'S')) return true;
                    return false;
                });
                if (!wcMatch) return false;

                // Origin filter
                if (f.origin && f.origin.length > 0) {
                    const deptIcao = (flight.fp_dept_icao || '').toUpperCase().trim();
                    const deptArtcc = (flight.fp_dept_artcc || '').toUpperCase().trim();
                    if (!(deptIcao.includes(f.origin) || deptArtcc.includes(f.origin))) return false;
                }

                // Destination filter
                if (f.dest && f.dest.length > 0) {
                    const destIcao = (flight.fp_dest_icao || '').toUpperCase().trim();
                    const destArtcc = (flight.fp_dest_artcc || '').toUpperCase().trim();
                    if (!(destIcao.includes(f.dest) || destArtcc.includes(f.dest))) return false;
                }

                // Carrier filter
                if (f.carrier && f.carrier.length > 0) {
                    const callsign = (flight.callsign || '').toUpperCase().trim();
                    if (!callsign.startsWith(f.carrier)) return false;
                }

                // Altitude filter
                if (f.altitudeMin !== null) {
                    const alt = parseInt(flight.altitude) || 0;
                    if (alt < f.altitudeMin) return false;
                }
                if (f.altitudeMax !== null) {
                    const alt = parseInt(flight.altitude) || 0;
                    if (alt > f.altitudeMax) return false;
                }

                return true;
            });
        }

        function collectFiltersFromUI() {
            const wcs = [];
            $('#adl_wc_super').is(':checked') && wcs.push('SUPER', 'J');
            $('#adl_wc_heavy').is(':checked') && wcs.push('HEAVY', 'H');
            $('#adl_wc_large').is(':checked') && wcs.push('LARGE', 'L', '');
            $('#adl_wc_small').is(':checked') && wcs.push('SMALL', 'S');
            state.filters.weightClasses = wcs.length > 0 ? wcs : ['SUPER', 'HEAVY', 'LARGE', 'SMALL', 'J', 'H', 'L', 'S', ''];

            state.filters.origin = ($('#adl_origin').val() || '').toUpperCase().trim();
            state.filters.dest = ($('#adl_dest').val() || '').toUpperCase().trim();
            state.filters.carrier = ($('#adl_carrier').val() || '').toUpperCase().trim();

            const altMin = parseInt($('#adl_alt_min').val());
            const altMax = parseInt($('#adl_alt_max').val());
            state.filters.altitudeMin = !isNaN(altMin) ? altMin * 100 : null;
            state.filters.altitudeMax = !isNaN(altMax) ? altMax * 100 : null;
        }

        function resetFilters() {
            state.filters = {
                weightClasses: ['SUPER', 'HEAVY', 'LARGE', 'SMALL', 'J', 'H', 'L', 'S', ''],
                origin: '', dest: '', carrier: '',
                altitudeMin: null, altitudeMax: null
            };
            $('#adl_wc_super, #adl_wc_heavy, #adl_wc_large, #adl_wc_small').prop('checked', true);
            $('#adl_origin, #adl_dest, #adl_carrier, #adl_alt_min, #adl_alt_max').val('');
            applyFilters();
            updateDisplay();
            updateStats();
        }

        // ─────────────────────────────────────────────────────────────────────
        // MAP DISPLAY
        // ─────────────────────────────────────────────────────────────────────

        function updateDisplay() {
            if (!graphic_map || !state.enabled) return;
            console.log('[ADL-ML] Rendering', state.filteredFlights.length, 'flights');

            const features = state.filteredFlights.map(flight => {
                const isTracked = state.trackedFlight === (flight.flight_key || flight.callsign);
                const color = getFlightColor(flight);
                const heading = parseFloat(flight.heading_deg) || 0;

                return {
                    type: 'Feature',
                    properties: {
                        callsign: flight.callsign,
                        color: color,
                        heading: heading,
                        isTracked: isTracked,
                        weight_class: flight.weight_class || '',
                        fp_dept_icao: flight.fp_dept_icao || '',
                        fp_dest_icao: flight.fp_dest_icao || '',
                        altitude: flight.altitude || 0,
                        groundspeed: flight.groundspeed_kts || flight.groundspeed || 0,
                        aircraft_type: stripAircraftSuffixes(flight.aircraft_icao || flight.aircraft_type || ''),
                        phase: flight.phase || '',
                        fp_route: flight.fp_route || '',
                        flight_key: flight.flight_key || flight.callsign
                    },
                    geometry: {
                        type: 'Point',
                        coordinates: [parseFloat(flight.lon), parseFloat(flight.lat)]
                    }
                };
            });

            if (graphic_map.getSource('aircraft')) {
                graphic_map.getSource('aircraft').setData({ type: 'FeatureCollection', features });
            }

            // Update route positions for drawn routes
            state.drawnRoutes.forEach((routeData, key) => {
                const flight = state.filteredFlights.find(f => (f.flight_key || f.callsign) === key);
                if (flight) {
                    // Update route based on new aircraft position
                    const coords = buildRouteCoords(flight);
                    if (coords.length >= 2) {
                        const aircraftLat = parseFloat(flight.lat);
                        const aircraftLon = parseFloat(flight.lon);
                        const splitIdx = findClosestSegmentIndex(coords, aircraftLat, aircraftLon);

                        const behindRaw = coords.slice(0, splitIdx + 1).map(c => [c.lon, c.lat]);
                        behindRaw.push([aircraftLon, aircraftLat]);

                        const aheadRaw = [[aircraftLon, aircraftLat]];
                        for (let i = splitIdx; i < coords.length; i++) {
                            aheadRaw.push([coords[i].lon, coords[i].lat]);
                        }

                        // Normalize for IDL crossing
                        routeData.behindCoords = normalizeForIDL(behindRaw);
                        routeData.aheadCoords = normalizeForIDL(aheadRaw);
                        routeData.color = getFlightColor(flight);
                    }
                }
            });
            updateFlightRouteDisplay();

            // If tracking a flight, center on it
            if (state.trackedFlight) {
                const tracked = state.filteredFlights.find(f => (f.flight_key || f.callsign) === state.trackedFlight);
                if (tracked) {
                    graphic_map.easeTo({
                        center: [parseFloat(tracked.lon), parseFloat(tracked.lat)],
                        duration: 500
                    });
                }
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // UI UPDATES
        // ─────────────────────────────────────────────────────────────────────

        function updateStats() {
            $('#adl_stats_display').html('<strong>' + state.filteredFlights.length + '</strong> shown');
            $('#adl_stats_total').html('<strong>' + state.flights.length + '</strong> total');
        }

        function updateRefreshStatus(status) {
            const el = $('#adl_refresh_status');
            if (!el.length) return;
            if (status === 'Error') {
                el.html('<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Error</span>');
                return;
            }
            if (state.lastRefresh) {
                const time = state.lastRefresh.toISOString().substr(11, 8) + 'Z';
                el.html('<i class="fas fa-sync-alt"></i> ' + time);
            }
        }

        function updateStatusBadge() {
            const badge = $('#adl_status_badge');
            if (!badge.length) return;
            if (state.enabled) {
                badge.removeClass('badge-dark').addClass('badge-success live');
                badge.html('<i class="fas fa-plane mr-1"></i> LIVE');
            } else {
                badge.removeClass('badge-success live').addClass('badge-dark');
                badge.html('<i class="fas fa-plane mr-1"></i> Live Flights');
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // POPUPS & INTERACTION
        // ─────────────────────────────────────────────────────────────────────

        function showFlightPopup(flight, lngLat) {
            const alt = flight.altitude ? 'FL' + Math.round(flight.altitude / 100) : '--';
            const spd = flight.groundspeed ? flight.groundspeed + ' kts' : '--';
            const hdg = flight.heading ? flight.heading + '°' : '--';
            const route = flight.fp_route || '';
            const popupId = 'flight-popup-' + Date.now();

            const content = `
                <div class="adl-popup" id="${popupId}">
                    <div class="callsign" style="font-weight:bold;font-size:1.1em;">${flight.callsign || '--'}</div>
                    <div class="route mb-2">
                        <strong>${flight.fp_dept_icao || '????'}</strong>
                        <i class="fas fa-long-arrow-alt-right mx-1"></i>
                        <strong>${flight.fp_dest_icao || '????'}</strong>
                    </div>
                    <div><small>Aircraft:</small> ${flight.aircraft_type || '--'}</div>
                    <div><small>Altitude:</small> ${alt}</div>
                    <div><small>Speed:</small> ${spd}</div>
                    <div><small>Phase:</small> ${flight.phase || '--'}</div>
                    ${route ? `
                    <div class="mt-2 pt-2" style="border-top: 1px solid #444;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-muted">Route:</small>
                            <button class="btn btn-xs btn-outline-info copy-route-btn" 
                                    data-route="${route.replace(/"/g, '&quot;')}"
                                    style="padding: 0 6px; font-size: 0.65rem; line-height: 1.4;">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                        <div class="route-display" style="font-family: 'Inconsolata', monospace; font-size: 0.75rem; 
                             word-break: break-all; max-height: 100px; overflow-y: auto; 
                             background: rgba(0,0,0,0.3); padding: 4px 6px; border-radius: 3px;">
                            ${route}
                        </div>
                    </div>
                    ` : ''}
                    <div class="text-muted text-center mt-1" style="font-size:0.7rem">Click aircraft to toggle route</div>
                </div>
            `;

            const popup = new maplibregl.Popup({ closeButton: true, closeOnClick: true, maxWidth: '320px' })
                .setLngLat(lngLat)
                .setHTML(content)
                .addTo(graphic_map);
            
            // Bind copy button click handler after popup is added to DOM
            setTimeout(() => {
                const copyBtn = document.querySelector(`#${popupId} .copy-route-btn`);
                if (copyBtn) {
                    copyBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const routeText = this.getAttribute('data-route');
                        navigator.clipboard.writeText(routeText).then(() => {
                            // Visual feedback
                            const originalHtml = this.innerHTML;
                            this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                            this.classList.remove('btn-outline-info');
                            this.classList.add('btn-success');
                            setTimeout(() => {
                                this.innerHTML = originalHtml;
                                this.classList.remove('btn-success');
                                this.classList.add('btn-outline-info');
                            }, 1500);
                        }).catch(err => {
                            console.error('[ADL] Failed to copy route:', err);
                            // Fallback for older browsers
                            const textarea = document.createElement('textarea');
                            textarea.value = routeText;
                            textarea.style.position = 'fixed';
                            textarea.style.opacity = '0';
                            document.body.appendChild(textarea);
                            textarea.select();
                            try {
                                document.execCommand('copy');
                                this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                            } catch (e) {
                                this.innerHTML = '<i class="fas fa-times"></i> Failed';
                            }
                            document.body.removeChild(textarea);
                        });
                    });
                }
            }, 50);
        }

        function setupAircraftInteractivity() {
            if (!graphic_map) return;

            // Handler for aircraft click (works for both symbol and fallback circle layers)
            const handleAircraftClick = (e) => {
                if (!e.features || !e.features[0]) return;
                const props = e.features[0].properties;

                // Find the full flight data
                const flight = state.filteredFlights.find(f =>
                    (f.flight_key || f.callsign) === props.flight_key
                );

                if (flight) {
                    const wasDrawn = toggleFlightRoute(flight);
                    showFlightPopup({
                        callsign: props.callsign,
                        fp_dept_icao: props.fp_dept_icao,
                        fp_dest_icao: props.fp_dest_icao,
                        aircraft_type: props.aircraft_type,
                        altitude: props.altitude,
                        groundspeed: props.groundspeed,
                        heading: props.heading,
                        phase: props.phase,
                        fp_route: props.fp_route || flight.fp_route || ''
                    }, e.lngLat);
                }
            };

            // Register click handlers for both aircraft layers
            graphic_map.on('click', 'aircraft-symbols', handleAircraftClick);
            graphic_map.on('click', 'aircraft-circles-fallback', handleAircraftClick);

            // Cursor change handlers
            ['aircraft-symbols', 'aircraft-circles-fallback'].forEach(layerId => {
                graphic_map.on('mouseenter', layerId, () => {
                    graphic_map.getCanvas().style.cursor = 'pointer';
                });
                graphic_map.on('mouseleave', layerId, () => {
                    graphic_map.getCanvas().style.cursor = '';
                });
            });
        }

        function zoomToFlight(flight) {
            if (!flight.lat || !flight.lon || !graphic_map) return;
            graphic_map.flyTo({
                center: [parseFloat(flight.lon), parseFloat(flight.lat)],
                zoom: 8,
                duration: 500
            });
        }

        function toggleTrackFlight(flight) {
            const key = flight.flight_key || flight.callsign;
            if (state.trackedFlight === key) {
                state.trackedFlight = null;
            } else {
                state.trackedFlight = key;
                zoomToFlight(flight);
            }
            updateDisplay();
        }

        // ─────────────────────────────────────────────────────────────────────
        // ENABLE / DISABLE
        // ─────────────────────────────────────────────────────────────────────

        function enable() {
            console.log('[ADL-ML] Enable called, state.enabled:', state.enabled);
            if (state.enabled) return;

            if (!graphic_map) {
                console.warn('[ADL-ML] Map not initialized yet.');
                alert('Please wait for the map to initialize.');
                $('#adl_toggle').prop('checked', false);
                return;
            }

            state.enabled = true;
            console.log('[ADL-ML] Enabling live flights...');

            // Enable filter button
            $('#adl_filter_toggle').prop('disabled', false);

            // Initialize color scheme
            state.colorBy = $('#adl_color_by').val() || 'weight_class';
            renderColorLegend();

            // Setup aircraft click handler
            setupAircraftInteractivity();

            // Initial fetch
            fetchFlights();

            // Start auto-refresh
            state.refreshInterval = setInterval(() => fetchFlights(), state.refreshRateMs);

            updateStatusBadge();
            console.log('[ADL-ML] Live flights enabled successfully');
        }

        function disable() {
            if (!state.enabled) return;
            state.enabled = false;

            // Stop refresh
            if (state.refreshInterval) {
                clearInterval(state.refreshInterval);
                state.refreshInterval = null;
            }

            // Clear drawn routes
            clearAllRoutes();

            // Clear aircraft layer
            if (graphic_map && graphic_map.getSource('aircraft')) {
                graphic_map.getSource('aircraft').setData({ type: 'FeatureCollection', features: [] });
            }

            // Hide filter panel
            $('#adl_filter_panel').hide();
            $('#adl_filter_toggle').prop('disabled', true);
            $('#adl_refresh_status').html('');

            state.trackedFlight = null;
            updateStatusBadge();
        }

        function toggle() {
            state.enabled ? disable() : enable();
        }

        // ─────────────────────────────────────────────────────────────────────
        // INITIALIZATION
        // ─────────────────────────────────────────────────────────────────────

        function init() {
            console.log('[ADL-ML] init() called, attaching event handlers');

            // Load saved color rules from localStorage
            loadColorRules();

            // Toggle switch
            $('#adl_toggle').on('change', function() {
                $(this).is(':checked') ? enable() : disable();
            });

            // Filter panel toggle
            $('#adl_filter_toggle').on('click', function() {
                $('#adl_filter_panel').slideToggle(150);
            });

            // Filter panel close button
            $('#adl_filter_close').on('click', function() {
                $('#adl_filter_panel').slideUp(150);
            });

            // Apply filters
            $('#adl_filter_apply').on('click', function() {
                collectFiltersFromUI();
                applyFilters();
                updateDisplay();
                updateStats();
                // If filter routes checkbox is checked, remove routes for non-matching flights
                if ($('#adl_routes_filter_only').is(':checked')) {
                    filterRoutesByCurrentFilter();
                }
            });

            // Reset filters
            $('#adl_filter_reset').on('click', resetFilters);

            // Color scheme change
            $('#adl_color_by').on('change', function() {
                state.colorBy = $(this).val();
                renderColorLegend();
                updateDisplay();
                // Also refresh route colors
                if (state.enabled) {
                    refreshRouteColors();
                }
            });

            // Add 'Custom Rules' option to color dropdown if not present
            if ($('#adl_color_by option[value="custom"]').length === 0) {
                $('#adl_color_by').append('<option value="custom">Custom Rules</option>');
            }

            // Inject color rules container if not present
            if ($('#adl_color_rules_container').length === 0) {
                const rulesHtml = `
                    <div id="adl_color_rules_container" class="mt-3 pt-2 border-top">
                    </div>
                `;
                // Insert after color legend or at end of filter panel
                if ($('#adl_color_legend').length) {
                    $('#adl_color_legend').after(rulesHtml);
                } else {
                    $('#adl_filter_panel .card-body').append(rulesHtml);
                }
            }

            // Render color rules UI
            renderColorRulesUI();

            // Modal button handlers
            $('#adl_modal_zoom').on('click', function() {
                const flight = $('#adlFlightDetailModal').data('flight');
                if (flight) zoomToFlight(flight);
            });
            $('#adl_modal_track').on('click', function() {
                const flight = $('#adlFlightDetailModal').data('flight');
                if (flight) toggleTrackFlight(flight);
            });

            // Context menu handlers
            $(document).on('click', '#adl_context_menu .menu-item', function() {
                const action = $(this).data('action');
                const flight = state.selectedFlight;
                if (!flight) return;

                $('#adl_context_menu').hide();

                switch (action) {
                    case 'zoom': zoomToFlight(flight); break;
                    case 'track': toggleTrackFlight(flight); break;
                    case 'copy':
                        navigator.clipboard && navigator.clipboard.writeText(flight.callsign);
                        break;
                }
            });

            // ─────────────────────────────────────────────────────────────────────
            // ROUTE BUTTON HANDLERS
            // ─────────────────────────────────────────────────────────────────────
            
            // Show All Routes button
            $('#adl_routes_show_all').on('click', function() {
                showAllRoutes();
            });
            
            // Clear All Routes button
            $('#adl_routes_clear').on('click', function() {
                clearAllRoutes();
            });
            
            // Filter Routes checkbox - apply immediately when toggled on
            $('#adl_routes_filter_only').on('change', function() {
                if ($(this).is(':checked')) {
                    filterRoutesByCurrentFilter();
                }
            });

            console.log('[ADL-ML] Event handlers attached');
        }

        // Public API
        return {
            init: init,
            enable: enable,
            disable: disable,
            toggle: toggle,
            getState: () => state,
            selectFlight: (callsign) => {
                const flight = state.filteredFlights.find(f => f.callsign === callsign);
                if (flight) toggleFlightRoute(flight);
            },
            fetchFlights: fetchFlights,
            updateDisplay: updateDisplay,
            // Color rule management exposed for external use
            addColorRule: (rule) => {
                state.colorRules.push(rule);
                saveColorRules();
                renderColorRulesUI();
                updateDisplay();
            },
            getColorRules: () => state.colorRules,
            clearColorRules: () => {
                state.colorRules = [];
                saveColorRules();
                renderColorRulesUI();
                updateDisplay();
            },
            // Route labels toggle
            toggleRouteLabels: toggleRouteLabels,
            // Route management
            showAllRoutes: showAllRoutes,
            clearAllRoutes: clearAllRoutes,
            filterRoutesByCurrentFilter: filterRoutesByCurrentFilter,
            refreshRouteColors: refreshRouteColors
        };
    })();

    // Expose ADL globally
    window.ADL = ADL;

    // Initialize ADL when map is ready
    $(document).on('maplibre:ready', function() {
        console.log('[ADL-ML] maplibre:ready event received, initializing ADL');
        ADL.init();
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // ADVISORY BUILDER (MapLibre version)
    // ═══════════════════════════════════════════════════════════════════════════

    const ADV_INDENT   = '';
    const ADV_MAX_LINE = 68;
    let   ADV_ORIG_COL = 20;
    let   ADV_DEST_COL = 20;

    const ADV_FACILITY_CODES = [
        'ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
        'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL'
    ];

    const ADV_US_FACILITY_CODES = [
        'ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
        'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL'
    ];

    // Check if token is an ARTCC (Z + 2 chars)
    function advIsArtcc(code) {
        if (!code) return false;
        const t = String(code).toUpperCase().trim().replace(/[<>]/g, '');
        return /^Z[A-Z]{2}$/.test(t);
    }

    // Check if token is a TRACON (letter + 2 digits)
    function advIsTracon(code) {
        if (!code) return false;
        const t = String(code).toUpperCase().trim().replace(/[<>]/g, '');
        return /^[A-Z][0-9]{2}$/.test(t);
    }

    // Check if token is an airport (4 chars with ICAO prefix)
    // K = US, C = Canada, P = Pacific, M = Mexico/Central America
    // Also handle European (E, L), Asian (R, Z not ARTCC)
    function advIsAirport(code) {
        if (!code) return false;
        const t = String(code).toUpperCase().trim().replace(/[<>]/g, '');
        if (!/^[A-Z]{4}$/.test(t)) return false;
        // US airports start with K
        if (/^K[A-Z]{3}$/.test(t)) return true;
        // Canadian airports start with C (but not like CABLO which is a fix)
        if (/^CY[A-Z]{2}$/.test(t)) return true;  // CYYZ, CYUL, etc.
        if (/^CZ[A-Z]{2}$/.test(t)) return true;  // CZ airports
        // Pacific (Hawaii, Guam, etc.) start with P
        if (/^P[A-Z]{3}$/.test(t)) return true;
        // Mexico starts with MM
        if (/^MM[A-Z]{2}$/.test(t)) return true;
        // Don't match generic 4-letter codes - those are likely fixes
        return false;
    }

    // Check if token is any facility (ARTCC, TRACON, or Airport)
    function advIsFacility(code) {
        if (!code) return false;
        const t = String(code).toUpperCase().trim().replace(/[<>]/g, '');
        if (!t) return false;
        if (advIsAirport(t)) return true;  // Check with proper airport logic
        if (/^Z[A-Z]{2}$/.test(t)) return true;  // ARTCC
        if (/^[A-Z][0-9]{2}$/.test(t)) return true;  // TRACON
        return false;
    }

    // Check if token is an airspace element (not a NAVAID/fix)
    // Airways: J###, Q###, V###, T###, etc.
    function advIsAirway(code) {
        if (!code) return false;
        const t = String(code).toUpperCase().trim().replace(/[<>]/g, '');
        return /^[JQVT]\d+$/.test(t);
    }

    // Check if token is a NAVAID or fix (not facility, not airway, not DP/STAR)
    function advIsNavaidOrFix(code) {
        if (!code) return false;
        const t = String(code).toUpperCase().trim().replace(/[<>]/g, '');
        if (!t) return false;
        if (advIsFacility(t)) return false;
        if (advIsAirway(t)) return false;
        // 2-5 char alphanumeric, not ending in digit (which would be DP/STAR)
        if (/^[A-Z]{2,5}$/.test(t)) return true;
        // VORs can be 3 chars
        if (/^[A-Z]{3}$/.test(t)) return true;
        return false;
    }

    // Strip facilities from route string, keeping only NAVAIDs, fixes, and airways
    function advStripFacilitiesFromRoute(routeText) {
        if (!routeText) return '';
        const tokens = routeText.split(/\s+/).filter(Boolean);
        const filtered = tokens.filter(tok => {
            const clean = tok.replace(/[<>]/g, '').toUpperCase();
            // Keep if not a facility
            return !advIsFacility(clean);
        });
        return filtered.join(' ');
    }

    // Extract just the NAVAID/fix tokens (no facilities, no airways) for ORIG/DEST display
    function advExtractNavaidTokens(text) {
        if (!text) return [];
        const tokens = text.split(/\s+/).filter(Boolean);
        return tokens.filter(tok => {
            const clean = tok.replace(/[<>]/g, '').toUpperCase();
            // Only keep NAVAIDs/fixes, not facilities or airways
            if (advIsFacility(clean)) return false;
            if (advIsAirway(clean)) return false;
            return true;
        });
    }

    // Tokenize label values, splitting slash-lists for proper wrapping
    function advTokenizeLabelValue(raw) {
        raw = (raw || '').toString().trim();
        if (!raw) return [];
        const parts = raw.split(/\s+/);
        const tokens = [];
        parts.forEach(part => {
            // If it's a slash-list like "KLAX/KOAK/KPDX/KSAN/..." break it up
            if (part.indexOf('/') !== -1 && part.length > 8) {
                const pieces = part.split('/');
                pieces.forEach((piece, idx) => {
                    if (!piece) return;
                    if (idx < pieces.length - 1) {
                        tokens.push(piece + '/');
                    } else {
                        tokens.push(piece);
                    }
                });
            } else {
                tokens.push(part);
            }
        });
        return tokens;
    }

    // Check if a token looks like a DP or STAR (ends with digit, 4-7 chars)
    function advLooksLikeProcedure(token) {
        if (!token) return false;
        const clean = token.replace(/[<>]/g, '').toUpperCase();
        // Typical DP/STAR: 4-7 alphanumeric chars ending in a digit
        // Examples: TERPZ4, CAMRN5, BROSS7, WHINY4
        if (clean.length < 4 || clean.length > 7) return false;
        if (!/^[A-Z]+\d$/.test(clean)) return false;
        // Exclude pure airport codes like K123 or airports
        if (/^K[A-Z]{3}$/.test(clean)) return false;
        return true;
    }

    // Auto-correct mandatory segments to NOT include DPs or STARs
    // >{DP#}.{TRANSITION} {ROUTE} → {DP#} >{TRANSITION} {ROUTE}
    // {ROUTE} {TRANSITION}.{STAR#}< → {ROUTE} {TRANSITION}< {STAR#}
    function advCorrectMandatoryProcedures(routeText) {
        if (!routeText) return routeText;
        
        let tokens = routeText.split(/\s+/).filter(Boolean);
        if (tokens.length < 2) return routeText;
        
        // Scan all tokens for patterns needing correction
        for (let i = 0; i < tokens.length; i++) {
            let tok = tokens[i];
            
            // Check for >{DP#}.{TRANS} pattern (DP at start of mandatory segment)
            if (tok.startsWith('>') && tok.includes('.')) {
                const withoutMarker = tok.slice(1);
                const dotIdx = withoutMarker.indexOf('.');
                if (dotIdx > 0) {
                    const dpPart = withoutMarker.slice(0, dotIdx);
                    const transPart = withoutMarker.slice(dotIdx + 1);
                    if (advLooksLikeProcedure(dpPart)) {
                        // Move DP outside mandatory: DP# >{TRANS}
                        tokens[i] = dpPart + ' >' + transPart;
                    }
                }
            }
            // Check for >{DP#} pattern (standalone DP with open marker)
            else if (tok.startsWith('>') && !tok.includes('.') && !tok.endsWith('<')) {
                const withoutMarker = tok.slice(1);
                if (advLooksLikeProcedure(withoutMarker)) {
                    // Move marker to next token
                    tokens[i] = withoutMarker;
                    if (i + 1 < tokens.length && !tokens[i + 1].startsWith('>')) {
                        tokens[i + 1] = '>' + tokens[i + 1];
                    }
                }
            }
            
            // Check for {TRANS}.{STAR#}< pattern (STAR at end of mandatory segment)
            if (tok.endsWith('<') && tok.includes('.')) {
                const withoutMarker = tok.slice(0, -1);
                const dotIdx = withoutMarker.lastIndexOf('.');
                if (dotIdx > 0 && dotIdx < withoutMarker.length - 1) {
                    const transPart = withoutMarker.slice(0, dotIdx);
                    const starPart = withoutMarker.slice(dotIdx + 1);
                    if (advLooksLikeProcedure(starPart)) {
                        // Move STAR outside mandatory: {TRANS}< STAR#
                        tokens[i] = transPart + '< ' + starPart;
                    }
                }
            }
            // Check for {STAR#}< pattern (standalone STAR with close marker)
            else if (tok.endsWith('<') && !tok.includes('.') && !tok.startsWith('>')) {
                const withoutMarker = tok.slice(0, -1);
                if (advLooksLikeProcedure(withoutMarker)) {
                    // Move marker to previous token
                    tokens[i] = withoutMarker;
                    if (i > 0 && !tokens[i - 1].endsWith('<')) {
                        tokens[i - 1] = tokens[i - 1] + '<';
                    }
                }
            }
        }
        
        // Rejoin - may have created new tokens with embedded spaces
        return tokens.join(' ').replace(/\s+/g, ' ').trim();
    }

    function advComputeColumnWidths(routes) {
        const totalWidth = ADV_MAX_LINE - ADV_INDENT.length;
        let maxOrigLen = 4, maxDestLen = 4;
        (routes || []).forEach(r => {
            const ol = (r.orig || '').length;
            const dl = (r.dest || '').length;
            if (ol > maxOrigLen) maxOrigLen = ol;
            if (dl > maxDestLen) maxDestLen = dl;
        });
        const origWidth = Math.min(maxOrigLen + 2, 24);
        const destWidth = Math.min(maxDestLen + 2, 24);
        ADV_ORIG_COL = origWidth;
        ADV_DEST_COL = destWidth;
    }

    function advAddLabeledField(lines, label, value) {
        label = (label || '').toString().toUpperCase();
        const basePrefix = ADV_INDENT + label + ':';
        const raw = (value == null ? '' : String(value)).trim();
        if (!raw.length) return;  // Don't add empty fields
        
        const firstPrefix = basePrefix + ' ';
        const hangPrefix = ' '.repeat(firstPrefix.length);
        const maxWidth = ADV_MAX_LINE;
        
        const words = advTokenizeLabelValue(raw);
        if (!words.length) return;
        
        // If it's a single huge "word", just force it on one line with the label
        if (words.length === 1 && (firstPrefix.length + words[0].length > maxWidth)) {
            lines.push(firstPrefix + words[0]);
            return;
        }
        
        let prefix = firstPrefix;
        let content = '';
        
        words.forEach(word => {
            if (!word) return;
            if (!content) {
                if (prefix.length + word.length <= maxWidth) {
                    content = word;
                } else {
                    lines.push(prefix + word);
                    prefix = hangPrefix;
                    content = '';
                }
            } else {
                const lastChar = content[content.length - 1];
                const joiner = (lastChar === '/' ? '' : ' ');
                if (prefix.length + content.length + joiner.length + word.length <= maxWidth) {
                    content += joiner + word;
                } else {
                    lines.push((prefix + content).trimEnd());
                    prefix = hangPrefix;
                    content = word;
                }
            }
        });
        if (content) {
            lines.push((prefix + content).trimEnd());
        }
    }

    function advFormatRouteRow(orig, dest, routeText) {
        orig = (orig || '').toUpperCase();
        dest = (dest || '').toUpperCase();
        routeText = (routeText || '').toUpperCase().trim();
        const lines = [];
        const origTokens = orig.trim().length ? orig.trim().split(/\s+/).filter(Boolean) : [];
        const destTokens = dest.trim().length ? dest.trim().split(/\s+/).filter(Boolean) : [];
        let routeTokens = routeText.length ? routeText.split(/\s+/).filter(Boolean) : [];
        if (routeTokens.length && origTokens.length && advIsFacility(routeTokens[0]) && routeTokens[0] === origTokens[0]) {
            routeTokens = routeTokens.slice(1);
        }
        if (routeTokens.length && destTokens.length && advIsFacility(routeTokens[routeTokens.length - 1]) && routeTokens[routeTokens.length - 1] === destTokens[destTokens.length - 1]) {
            routeTokens = routeTokens.slice(0, -1);
        }
        function chunkTokens(tokens) {
            if (!tokens || !tokens.length) return [''];
            const chunks = [];
            for (let i = 0; i < tokens.length; i += 3) {
                chunks.push(tokens.slice(i, i + 3).join(' '));
            }
            return chunks;
        }
        const origChunks = chunkTokens(origTokens);
        const destChunks = chunkTokens(destTokens);
        const maxColumnLines = Math.max(origChunks.length, destChunks.length, 1);
        const words = routeTokens.slice();
        let lineIndex = 0;
        while (lineIndex < maxColumnLines || words.length) {
            const oStr = (lineIndex < origChunks.length) ? origChunks[lineIndex] : '';
            const dStr = (lineIndex < destChunks.length) ? destChunks[lineIndex] : '';
            const origCol = oStr.padEnd(ADV_ORIG_COL, ' ').slice(0, ADV_ORIG_COL);
            const destCol = dStr.padEnd(ADV_DEST_COL, ' ').slice(0, ADV_DEST_COL);
            const basePrefix = ADV_INDENT + origCol + destCol;
            let current = basePrefix;
            const baseLen = current.length;
            if (words.length) {
                while (words.length) {
                    const word = words[0];
                    const atStart = (current.length === baseLen);
                    const addition = atStart ? word : ' ' + word;
                    if (current.length + addition.length <= ADV_MAX_LINE) {
                        current += addition;
                        words.shift();
                    } else {
                        if (atStart && current.length + word.length > ADV_MAX_LINE) {
                            current += word;
                            words.shift();
                        }
                        break;
                    }
                }
            }
            lines.push(current.trimEnd());
            lineIndex++;
        }
        if (!lines.length) lines.push('');
        return lines;
    }

    function advParseRoutesFromStrings(routeStrings) {
        const routes = [];
        (routeStrings || []).forEach(raw => {
            let line = (raw || '').trim();
            if (!line) return;
            const semiIdx = line.indexOf(';');
            if (semiIdx !== -1) line = line.slice(0, semiIdx).trim();
            if (!line) return;
            const tokens = line.split(/\s+/).filter(Boolean);
            if (!tokens.length) return;
            if (tokens.length === 1) {
                const code = tokens[0].toUpperCase();
                if (cdrMap && cdrMap[code]) {
                    const expanded = advParseRoutesFromStrings([cdrMap[code]]);
                    (expanded || []).forEach(er => routes.push(er));
                }
                return;
            }
            const facilityIdxs = [];
            for (let i = 0; i < tokens.length; i++) {
                if (advIsFacility(tokens[i])) facilityIdxs.push(i);
            }
            if (facilityIdxs.length < 2) return;
            const firstFacilityIdx = facilityIdxs[0];
            const lastFacilityIdx = facilityIdxs[facilityIdxs.length - 1];
            if (lastFacilityIdx <= firstFacilityIdx) return;
            let groupEndExclusive = firstFacilityIdx;
            while (groupEndExclusive < lastFacilityIdx && advIsFacility(tokens[groupEndExclusive])) {
                groupEndExclusive++;
            }
            const origTokens = tokens.slice(firstFacilityIdx, groupEndExclusive);
            const destToken = tokens[lastFacilityIdx];
            const routeTokens = tokens.slice(groupEndExclusive, lastFacilityIdx);
            routes.push({
                orig: origTokens.join(' ').toUpperCase(),
                dest: (destToken || '').toUpperCase(),
                assignedRoute: routeTokens.map(t => t.toUpperCase()).join(' ')
            });
        });
        return routes;
    }

    function advGenerateTmiId(advAction, advFacility, advNumber, existingId) {
        existingId = (existingId || '').trim().toUpperCase();
        advAction = (advAction || '').trim().toUpperCase();
        advFacility = (advFacility || '').trim().toUpperCase();
        advNumber = (advNumber || '').trim();
        if (existingId) return existingId;
        if (!advFacility || !advNumber) return '';
        let digits = advNumber.replace(/\D/g, '');
        digits = digits.length ? digits.padStart(3, '0') : advNumber;
        let prefix = '';
        if (advAction.indexOf('ROUTE') !== -1) prefix = 'RR';
        if (!prefix) return '';
        return prefix + advFacility + digits;
    }

    function advEnsureDefaultIncludedTraffic(parsedRoutes) {
        const $field = $('#advIncludeTraffic');
        if (!$field.length) return;
        
        const currentVal = ($field.val() || '').toString().trim();
        if (currentVal) return;
        
        const routes = parsedRoutes || [];
        if (!routes.length) return;
        
        const origSet = new Set();
        const destSet = new Set();
        
        routes.forEach(r => {
            const origRaw = (r.orig || '').toString().trim().toUpperCase();
            const destRaw = (r.dest || '').toString().trim().toUpperCase();
            
            if (origRaw && advIsFacility(origRaw)) {
                origSet.add(origRaw);
            }
            if (destRaw && advIsFacility(destRaw)) {
                destSet.add(destRaw);
            }
        });
        
        if (!origSet.size || !destSet.size) return;
        
        const origins = Array.from(origSet).sort();
        const dests = Array.from(destSet).sort();
        
        $field.val(origins.join('/') + ' DEPARTURES TO ' + dests.join('/'));
    }

    function collectRouteStringsForAdvisory(rawInput) {
        let routeStrings = [];
        let usedProcedures = new Set();  // Track playbook and CDR names
        const text = (rawInput || '').toString();
        if (/VATCSCC ADVZY/i.test(text)) {
            const advBlocks = splitAdvisoriesFromInput(text.toUpperCase());
            advBlocks.forEach(block => {
                const advRoutes = parseAdvisoryRoutes(block);
                if (advRoutes && advRoutes.length) {
                    routeStrings = routeStrings.concat(advRoutes);
                }
            });
        } else {
            // Use same expansion as map plotting
            const expanded = expandGroupsInRouteInput(text);
            const lines = expanded.split(/\r?\n/);
            lines.forEach(line => {
                const trimmed = line.trim();
                if (!trimmed) return;
                
                let body = trimmed;
                let color = null;
                const semiIdx = trimmed.indexOf(';');
                if (semiIdx !== -1) {
                    body = trimmed.slice(0, semiIdx).trim();
                    color = trimmed.slice(semiIdx + 1).trim().toUpperCase();
                }
                if (!body) return;
                
                let isMandatory = false;
                let spec = body;
                if (spec.startsWith('>') && spec.endsWith('<')) {
                    isMandatory = true;
                    spec = spec.slice(1, -1).trim();
                }
                
                const specUpper = spec.toUpperCase();
                if (specUpper.startsWith('PB.')) {
                    // Expand playbook directive just like the map does
                    const pbDirective = specUpper.slice(3).trim();
                    // Extract play name for tracking
                    const pbParts = pbDirective.split('.');
                    const playName = (pbParts[0] || '').trim();
                    if (playName) usedProcedures.add('PB: ' + playName);
                    
                    const pbRoutes = expandPlaybookDirective(pbDirective, isMandatory, null);
                    if (pbRoutes && pbRoutes.length) {
                        routeStrings = routeStrings.concat(pbRoutes);
                    }
                } else {
                    // Check for CDR code
                    const tokens = specUpper.split(/\s+/).filter(Boolean);
                    tokens.forEach(tok => {
                        const clean = tok.replace(/[<>]/g, '');
                        if (cdrMap && cdrMap[clean]) {
                            usedProcedures.add('CDR: ' + clean);
                        }
                    });
                    routeStrings.push(specUpper);
                }
            });
        }
        // CDR expansion
        routeStrings = routeStrings.map(rte => {
            if (!rte) return rte;
            let routeText = rte;
            const semiIdx = routeText.indexOf(';');
            if (semiIdx !== -1) routeText = routeText.slice(0, semiIdx).trim();
            const cdrTokens = routeText.split(/\s+/).filter(t => t !== '');
            if (!cdrTokens.length) return '';
            if (cdrTokens.length === 1) {
                const rawToken = cdrTokens[0];
                const hasMandatoryStart = rawToken.startsWith('>');
                const hasMandatoryEnd = rawToken.endsWith('<');
                const code = rawToken.replace(/[<>]/g, '').toUpperCase();
                if (cdrMap[code]) {
                    usedProcedures.add('CDR: ' + code);
                    let expanded = cdrMap[code].toUpperCase();
                    // Apply mandatory markers to expanded route
                    if (hasMandatoryStart || hasMandatoryEnd) {
                        const expTokens = expanded.split(/\s+/).filter(Boolean);
                        if (expTokens.length > 2) {
                            // Put > after first facility (origin), < before last facility (dest)
                            // Find first non-facility (where route starts)
                            let routeStartIdx = 0;
                            for (let i = 0; i < expTokens.length; i++) {
                                if (!advIsFacility(expTokens[i])) {
                                    routeStartIdx = i;
                                    break;
                                }
                            }
                            // Find last non-facility (where route ends)
                            let routeEndIdx = expTokens.length - 1;
                            for (let i = expTokens.length - 1; i >= 0; i--) {
                                if (!advIsFacility(expTokens[i])) {
                                    routeEndIdx = i;
                                    break;
                                }
                            }
                            if (hasMandatoryStart && routeStartIdx < expTokens.length) {
                                expTokens[routeStartIdx] = '>' + expTokens[routeStartIdx];
                            }
                            if (hasMandatoryEnd && routeEndIdx >= 0) {
                                expTokens[routeEndIdx] = expTokens[routeEndIdx] + '<';
                            }
                            expanded = expTokens.join(' ');
                        } else if (hasMandatoryStart && hasMandatoryEnd) {
                            expanded = '>' + expanded + '<';
                        }
                    }
                    routeText = expanded;
                } else {
                    routeText = rawToken.toUpperCase();
                }
            } else {
                const expandedTokens = [];
                cdrTokens.forEach(tok => {
                    const code = tok.replace(/[<>]/g, '').toUpperCase();
                    if (cdrMap[code]) {
                        usedProcedures.add('CDR: ' + code);
                        expandedTokens.push(...cdrMap[code].toUpperCase().split(/\s+/).filter(Boolean));
                    } else {
                        expandedTokens.push(tok.toUpperCase());
                    }
                });
                routeText = expandedTokens.join(' ');
            }
            // Apply DP/STAR mandatory marker correction
            routeText = advCorrectMandatoryProcedures(routeText);
            return routeText;
        });
        return {
            routes: routeStrings.filter(r => r && r.trim().length),
            procedures: Array.from(usedProcedures)
        };
    }

    // Format route row for FULL format (traditional ORIG/DEST/ROUTE)
    function advFormatFullRouteRow(orig, dest, routeText, colWidths) {
        const origCol = colWidths.orig || 20;
        const destCol = colWidths.dest || 20;
        const maxWidth = ADV_MAX_LINE;
        
        orig = (orig || '').toUpperCase();
        dest = (dest || '').toUpperCase();
        routeText = (routeText || '').toUpperCase().trim();
        
        const lines = [];
        
        // Handle slash-separated origins by chunking them appropriately
        // Split by slash first, then group to fit in column width
        const origItems = orig.trim().length ? orig.trim().split('/').filter(Boolean) : [];
        const destTokens = dest.trim().length ? dest.trim().split(/\s+/).filter(Boolean) : [];
        const routeTokens = routeText.length ? routeText.split(/\s+/).filter(Boolean) : [];
        
        // Chunk origin items to fit within column width (with slashes)
        function chunkOriginsToFit(items, maxLen) {
            if (!items || !items.length) return [''];
            const chunks = [];
            let current = [];
            let currentLen = 0;
            
            items.forEach((item, idx) => {
                const itemLen = item.length;
                const slashLen = current.length > 0 ? 1 : 0; // Add 1 for slash if not first
                
                if (currentLen + slashLen + itemLen > maxLen && current.length > 0) {
                    // Start new chunk
                    chunks.push(current.join('/'));
                    current = [item];
                    currentLen = itemLen;
                } else {
                    current.push(item);
                    currentLen += slashLen + itemLen;
                }
            });
            
            if (current.length > 0) {
                chunks.push(current.join('/'));
            }
            
            return chunks.length ? chunks : [''];
        }
        
        function chunkTokens(tokens, maxPerLine) {
            if (!tokens || !tokens.length) return [''];
            const chunks = [];
            for (let i = 0; i < tokens.length; i += maxPerLine) {
                chunks.push(tokens.slice(i, i + maxPerLine).join(' '));
            }
            return chunks;
        }
        
        const origChunks = chunkOriginsToFit(origItems, origCol - 2);
        const destChunks = chunkTokens(destTokens, 2);
        const maxColumnLines = Math.max(origChunks.length, destChunks.length, 1);
        const words = routeTokens.slice();
        let lineIndex = 0;
        
        while (lineIndex < maxColumnLines || words.length) {
            const oStr = (lineIndex < origChunks.length) ? origChunks[lineIndex] : '';
            const dStr = (lineIndex < destChunks.length) ? destChunks[lineIndex] : '';
            const origPad = oStr.padEnd(origCol, ' ').slice(0, origCol);
            const destPad = dStr.padEnd(destCol, ' ').slice(0, destCol);
            const basePrefix = ADV_INDENT + origPad + destPad;
            let current = basePrefix;
            const baseLen = current.length;
            
            if (words.length) {
                while (words.length) {
                    const word = words[0];
                    const atStart = (current.length === baseLen);
                    const addition = atStart ? word : ' ' + word;
                    if (current.length + addition.length <= maxWidth) {
                        current += addition;
                        words.shift();
                    } else {
                        if (atStart && current.length + word.length > maxWidth) {
                            current += word;
                            words.shift();
                        }
                        break;
                    }
                }
            }
            lines.push(current.trimEnd());
            lineIndex++;
        }
        if (!lines.length) lines.push('');
        return lines;
    }

    // Format row for SPLIT format (single column with route)
    function advFormatSplitRow(label, routeText, colWidth) {
        const maxWidth = ADV_MAX_LINE;
        label = (label || '').toUpperCase();
        routeText = (routeText || '').toUpperCase().trim();
        
        const lines = [];
        const labelTokens = label.trim().length ? label.trim().split(/\s+/).filter(Boolean) : [];
        const routeTokens = routeText.length ? routeText.split(/\s+/).filter(Boolean) : [];
        
        function chunkTokens(tokens, maxPerLine) {
            if (!tokens || !tokens.length) return [''];
            const chunks = [];
            for (let i = 0; i < tokens.length; i += maxPerLine) {
                chunks.push(tokens.slice(i, i + maxPerLine).join(' '));
            }
            return chunks;
        }
        
        const labelChunks = chunkTokens(labelTokens, 3);
        const maxLabelLines = labelChunks.length;
        const words = routeTokens.slice();
        let lineIndex = 0;
        
        while (lineIndex < maxLabelLines || words.length) {
            const lStr = (lineIndex < labelChunks.length) ? labelChunks[lineIndex] : '';
            const labelPad = lStr.padEnd(colWidth, ' ').slice(0, colWidth);
            const basePrefix = ADV_INDENT + labelPad;
            let current = basePrefix;
            const baseLen = current.length;
            
            if (words.length) {
                while (words.length) {
                    const word = words[0];
                    const atStart = (current.length === baseLen);
                    const addition = atStart ? word : ' ' + word;
                    if (current.length + addition.length <= maxWidth) {
                        current += addition;
                        words.shift();
                    } else {
                        if (atStart && current.length + word.length > maxWidth) {
                            current += word;
                            words.shift();
                        }
                        break;
                    }
                }
            }
            lines.push(current.trimEnd());
            lineIndex++;
        }
        if (!lines.length) lines.push('');
        return lines;
    }

    // Extract just airports from a string
    function advExtractAirports(text) {
        if (!text) return [];
        const tokens = text.split(/\s+/).filter(Boolean);
        return tokens.filter(tok => {
            const clean = tok.replace(/[<>]/g, '').toUpperCase();
            return advIsAirport(clean);
        }).map(t => t.replace(/[<>]/g, '').toUpperCase());
    }

    // Extract ARTCCs from a string
    function advExtractArtccs(text) {
        if (!text) return [];
        const tokens = text.split(/\s+/).filter(Boolean);
        return tokens.filter(tok => {
            const clean = tok.replace(/[<>]/g, '').toUpperCase();
            return advIsArtcc(clean);
        }).map(t => t.replace(/[<>]/g, '').toUpperCase());
    }

    // Strip all facilities from route, keep only NAVAIDs/fixes/airways
    function advCleanRouteString(routeText) {
        if (!routeText) return '';
        const tokens = routeText.split(/\s+/).filter(Boolean);
        const cleaned = [];
        
        tokens.forEach(tok => {
            const clean = tok.replace(/[<>]/g, '').toUpperCase();
            // Skip facilities (airports, ARTCCs, TRACONs)
            if (advIsFacility(clean)) return;
            // Keep everything else (NAVAIDs, fixes, airways, DP/STAR codes)
            cleaned.push(tok.toUpperCase());
        });
        
        return cleaned.join(' ');
    }

    // Parse routes with enhanced information for advisory generation
    function advParseRoutesEnhanced(routeStrings) {
        const routes = [];
        (routeStrings || []).forEach(raw => {
            let line = (raw || '').trim();
            if (!line) return;
            const semiIdx = line.indexOf(';');
            if (semiIdx !== -1) line = line.slice(0, semiIdx).trim();
            if (!line) return;
            
            const tokens = line.split(/\s+/).filter(Boolean);
            if (!tokens.length) return;
            
            // Find all facilities in the route (excluding airways which start with J/Q/V/T + digits)
            const facilityIdxs = [];
            const airportIdxs = [];
            const artccIdxs = [];
            
            for (let i = 0; i < tokens.length; i++) {
                const clean = tokens[i].replace(/[<>]/g, '').toUpperCase();
                // Skip airways - they are NOT facilities
                if (advIsAirway(clean)) continue;
                
                if (advIsAirport(clean)) {
                    facilityIdxs.push(i);
                    airportIdxs.push(i);
                } else if (advIsArtcc(clean)) {
                    facilityIdxs.push(i);
                    artccIdxs.push(i);
                } else if (advIsTracon(clean)) {
                    facilityIdxs.push(i);
                }
            }
            
            if (facilityIdxs.length < 2) return;
            
            const firstFacilityIdx = facilityIdxs[0];
            const lastFacilityIdx = facilityIdxs[facilityIdxs.length - 1];
            if (lastFacilityIdx <= firstFacilityIdx) return;
            
            // Gather origin facilities (consecutive facilities at start)
            let origEndIdx = firstFacilityIdx;
            while (origEndIdx <= lastFacilityIdx) {
                const clean = tokens[origEndIdx].replace(/[<>]/g, '').toUpperCase();
                if (advIsFacility(clean) && !advIsAirway(clean)) {
                    origEndIdx++;
                } else {
                    break;
                }
            }
            
            // Gather destination facilities (consecutive facilities at end)
            let destStartIdx = lastFacilityIdx;
            while (destStartIdx > origEndIdx) {
                const prevClean = tokens[destStartIdx - 1].replace(/[<>]/g, '').toUpperCase();
                if (advIsFacility(prevClean) && !advIsAirway(prevClean)) {
                    destStartIdx--;
                } else {
                    break;
                }
            }
            
            // If origins and destinations overlap, adjust
            if (destStartIdx < origEndIdx) {
                // Split evenly or favor origins
                destStartIdx = origEndIdx;
            }
            
            const origTokens = tokens.slice(firstFacilityIdx, origEndIdx);
            const destTokens = tokens.slice(destStartIdx, lastFacilityIdx + 1);
            const routeTokens = tokens.slice(origEndIdx, destStartIdx);
            
            // Separate airports and ARTCCs in origin
            const origAirports = origTokens.filter(t => advIsAirport(t.replace(/[<>]/g, ''))).map(t => t.replace(/[<>]/g, '').toUpperCase());
            const origArtccs = origTokens.filter(t => advIsArtcc(t.replace(/[<>]/g, ''))).map(t => t.replace(/[<>]/g, '').toUpperCase());
            
            // Separate airports and ARTCCs in destination
            const destAirports = destTokens.filter(t => advIsAirport(t.replace(/[<>]/g, ''))).map(t => t.replace(/[<>]/g, '').toUpperCase());
            const destArtccs = destTokens.filter(t => advIsArtcc(t.replace(/[<>]/g, ''))).map(t => t.replace(/[<>]/g, '').toUpperCase());
            
            routes.push({
                orig: origTokens.map(t => t.replace(/[<>]/g, '').toUpperCase()).join(' '),
                origAirports: origAirports,
                origArtccs: origArtccs,
                dest: destTokens.map(t => t.replace(/[<>]/g, '').toUpperCase()).join(' '),
                destAirports: destAirports,
                destArtccs: destArtccs,
                assignedRoute: routeTokens.map(t => t.toUpperCase()).join(' '),
                cleanRoute: advCleanRouteString(routeTokens.join(' '))
            });
        });
        return routes;
    }

    function generateRerouteAdvisory() {
        const rawInput = $('#routeSearch').val() || '';
        const collectResult = collectRouteStringsForAdvisory(rawInput);
        const routeStrings = collectResult.routes || [];
        const usedProcedures = collectResult.procedures || [];
        
        if (!routeStrings.length) {
            alert('No routes found to build an advisory.');
            return;
        }
        
        const parsedRoutes = advParseRoutesEnhanced(routeStrings);
        
        // Dedupe
        const consolidated = [];
        const seenKeys = new Set();
        (parsedRoutes || []).forEach(r => {
            const key = (r.orig || '') + '|' + (r.dest || '') + '|' + (r.cleanRoute || '');
            if (!seenKeys.has(key)) {
                seenKeys.add(key);
                consolidated.push(r);
            }
        });
        
        // Determine format based on action type or checkbox
        const advAction = ($('#advAction').val() || 'ROUTE RQD').trim().toUpperCase();
        const useSplitFormat = advAction.indexOf('FCA') !== -1 || $('#advSplitFormat').is(':checked');
        
        advEnsureDefaultIncludedTraffic(parsedRoutes);
        const advNumber = ($('#advNumber').val() || '001').trim().toUpperCase();
        const advFacility = ($('#advFacility').val() || 'DCC').trim().toUpperCase();
        const advDate = ($('#advDate').val() || '').trim();
        const advName = ($('#advName').val() || '').trim().toUpperCase();
        const advConArea = ($('#advConstrainedArea').val() || '').trim().toUpperCase();
        const advReason = ($('#advReason').val() || '').trim().toUpperCase();
        const advInclTraff = ($('#advIncludeTraffic').val() || '').trim().toUpperCase();
        const advFacilsInc = ($('#advFacilities').val() || '').trim().toUpperCase();
        const advFltStatus = ($('input[name="advFlightStatus"]:checked').val() || 'ALL FLIGHTS').trim().toUpperCase();
        const advValidFrom = ($('#advValidStart').val() || '').trim().toUpperCase();
        const advValidTo = ($('#advValidEnd').val() || '').trim().toUpperCase();
        const advTimeBasis = ($('input[name="advTimeBasis"]:checked').val() || 'ETD').trim().toUpperCase();
        const advProb = ($('#advProb').val() || '').trim().toUpperCase();
        let advRemarks = ($('#advRemarks').val() || '').trim().toUpperCase();
        const advRestr = ($('#advRestrictions').val() || '').trim().toUpperCase();
        const advMods = ($('#advMods').val() || '').trim().toUpperCase();
        const rawTmiId = ($('#advTmiId').val() || '').toString();
        const advEffTime = ($('#advEffectiveTime').val() || '').trim().toUpperCase();
        const advTmiId = advGenerateTmiId(advAction, advFacility, advNumber, rawTmiId);
        
        // Add procedure references to REMARKS if any were used
        if (usedProcedures.length > 0) {
            const procList = usedProcedures.join(', ');
            if (advRemarks) {
                advRemarks = advRemarks + '. BASED ON ' + procList;
            } else {
                advRemarks = 'BASED ON ' + procList;
            }
        }
        
        const lines = [];
        let header = AdvisoryConfig.getPrefix() + ' ADVZY ' + advNumber + ' ' + advFacility + ' ' + advDate + ' ' + advAction;
        lines.push(header);
        advAddLabeledField(lines, 'NAME', advName);
        advAddLabeledField(lines, 'CONSTRAINED AREA', advConArea);
        advAddLabeledField(lines, 'REASON', advReason);
        advAddLabeledField(lines, 'INCLUDE TRAFFIC', advInclTraff);
        advAddLabeledField(lines, 'FACILITIES INCLUDED', advFacilsInc);
        advAddLabeledField(lines, 'FLIGHT STATUS', advFltStatus);
        
        let validText = '';
        if (advValidFrom || advValidTo) {
            const fromStr = advValidFrom || 'DDHHMM';
            const toStr = advValidTo || 'DDHHMM';
            if (advTimeBasis === 'ENTRY') {
                validText = 'ENTRY TIME FROM ' + fromStr + ' TO ' + toStr;
            } else {
                validText = advTimeBasis + ' ' + fromStr + ' TO ' + toStr;
            }
        }
        advAddLabeledField(lines, 'VALID', validText);
        advAddLabeledField(lines, 'PROBABILITY OF EXTENSION', advProb);
        advAddLabeledField(lines, 'REMARKS', advRemarks);
        advAddLabeledField(lines, 'ASSOCIATED RESTRICTIONS', advRestr);
        advAddLabeledField(lines, 'MODIFICATIONS', advMods);
        
        lines.push('');
        lines.push('ROUTES:');
        lines.push('');
        
        if (useSplitFormat) {
            // SPLIT FORMAT: FROM section and TO section
            // Group by origin facilities for FROM section
            const byOrigKey = {};
            consolidated.forEach(r => {
                // Use ARTCCs if available, otherwise airports
                const origKey = r.origArtccs.length ? r.origArtccs.sort().join(' ') : 
                               (r.origAirports.length ? r.origAirports.sort().join(' ') : r.orig);
                if (!byOrigKey[origKey]) byOrigKey[origKey] = [];
                byOrigKey[origKey].push(r);
            });
            
            // Group by destination for TO section (use airports when available)
            const byDest = {};
            consolidated.forEach(r => {
                const destKey = r.destAirports && r.destAirports.length ? r.destAirports.join(' ') : r.dest;
                if (!byDest[destKey]) byDest[destKey] = [];
                byDest[destKey].push(r);
            });
            
            // Calculate column width
            const origColWidth = 36;
            
            // FROM section
            lines.push('FROM:');
            lines.push('ORIG                                 ROUTE - ORIGIN SEGMENTS');
            lines.push('----                                 -----------------------');
            
            const sortedOrigs = Object.keys(byOrigKey).sort();
            sortedOrigs.forEach(origKey => {
                const routes = byOrigKey[origKey];
                // Get unique origin segments
                const seenSegs = new Set();
                routes.forEach(r => {
                    const routeClean = r.cleanRoute || '';
                    if (!routeClean) return;
                    
                    // For FROM section, show full route up to and including < marker
                    // Keep any DP before the > marker
                    let seg = routeClean;
                    
                    // Find the mandatory close marker and truncate there
                    const closeIdx = seg.indexOf('<');
                    if (closeIdx > 0) {
                        // Keep everything up to but NOT including < for FROM display
                        seg = seg.slice(0, closeIdx).trim();
                    }
                    
                    // Ensure there's a > marker if mandatory segment exists
                    if (seg.indexOf('>') === -1) {
                        // No > found - the whole thing is the route
                        seg = '>' + seg;
                    }
                    
                    if (seg && !seenSegs.has(seg)) {
                        seenSegs.add(seg);
                        advFormatSplitRow(origKey, seg, origColWidth).forEach(l => lines.push(l));
                    }
                });
            });
            
            lines.push('');
            
            // TO section
            lines.push('TO:');
            lines.push('DEST                                 ROUTE - DESTINATION SEGMENTS');
            lines.push('----                                 ----------------------------');
            
            const sortedDests = Object.keys(byDest).sort();
            sortedDests.forEach(dest => {
                const routes = byDest[dest];
                const seenSegs = new Set();
                routes.forEach(r => {
                    const routeClean = r.cleanRoute || '';
                    if (!routeClean) return;
                    
                    // For TO section, show from after > to end (including STAR after <)
                    let seg = routeClean;
                    
                    // Find where mandatory segment starts (after >) and take from there
                    const openIdx = seg.indexOf('>');
                    if (openIdx >= 0) {
                        seg = seg.slice(openIdx + 1).trim();
                    }
                    
                    // Ensure there's a < marker for destination
                    if (seg.indexOf('<') === -1) {
                        // Find a good place to put < - before any STAR at the end
                        const tokens = seg.split(/\s+/).filter(Boolean);
                        if (tokens.length > 0) {
                            const lastToken = tokens[tokens.length - 1];
                            if (advLooksLikeProcedure(lastToken)) {
                                // Last token is STAR, put < before it
                                tokens[tokens.length - 1] = '<';
                                seg = tokens.join(' ') + ' ' + lastToken;
                            } else {
                                seg = seg + '<';
                            }
                        } else {
                            seg = seg + '<';
                        }
                    }
                    
                    if (seg && seg !== '<' && !seenSegs.has(seg)) {
                        seenSegs.add(seg);
                        advFormatSplitRow(dest, seg, origColWidth).forEach(l => lines.push(l));
                    }
                });
            });
            
        } else {
            // FULL FORMAT: Standard ORIG/DEST/ROUTE table
            // First, consolidate routes that share the same (dest, route) pair
            const routeGroups = {};
            consolidated.forEach(r => {
                const destKey = r.destAirports && r.destAirports.length ? r.destAirports.join(' ') : r.dest;
                const routeKey = (r.cleanRoute || '').toUpperCase().trim();
                const groupKey = destKey + '|||' + routeKey;
                
                if (!routeGroups[groupKey]) {
                    routeGroups[groupKey] = {
                        dest: destKey,
                        route: routeKey,
                        origins: []
                    };
                }
                
                // Add origin(s) to the group
                const origDisplay = r.origAirports && r.origAirports.length ? r.origAirports : [r.orig];
                origDisplay.forEach(o => {
                    if (o && !routeGroups[groupKey].origins.includes(o)) {
                        routeGroups[groupKey].origins.push(o);
                    }
                });
            });
            
            // Convert to array and sort origins within each group
            const groupedRoutes = Object.values(routeGroups).map(g => {
                g.origins.sort();
                return g;
            });
            
            // Calculate column widths based on combined origins
            let maxOrigLen = 4, maxDestLen = 4;
            groupedRoutes.forEach(g => {
                // Format origins as slash-separated if they share a route
                const origDisplay = g.origins.join('/');
                const ol = origDisplay.length;
                const dl = g.dest.length;
                if (ol > maxOrigLen) maxOrigLen = ol;
                if (dl > maxDestLen) maxDestLen = dl;
            });
            const colWidths = {
                orig: Math.min(maxOrigLen + 2, 40),  // Allow wider for combined origins
                dest: Math.min(maxDestLen + 2, 20)
            };
            
            // Header
            advFormatFullRouteRow('ORIG', 'DEST', 'ROUTE', colWidths).forEach(l => lines.push(l));
            advFormatFullRouteRow('----', '----', '-----', colWidths).forEach(l => lines.push(l));
            
            // Group by destination for output ordering
            const routesByDest = {};
            groupedRoutes.forEach(g => {
                if (!routesByDest[g.dest]) routesByDest[g.dest] = [];
                routesByDest[g.dest].push(g);
            });
            
            const sortedDests = Object.keys(routesByDest).sort();
            
            // Sort routes within each destination by first origin
            sortedDests.forEach(dest => {
                routesByDest[dest].sort((a, b) => (a.origins[0] || '').localeCompare(b.origins[0] || ''));
            });
            
            let firstGroup = true;
            sortedDests.forEach(dest => {
                const destRoutes = routesByDest[dest];
                if (!firstGroup) lines.push('');
                firstGroup = false;
                
                destRoutes.forEach(g => {
                    // Format origins as slash-separated
                    const origDisplay = g.origins.join('/');
                    advFormatFullRouteRow(origDisplay, g.dest, g.route, colWidths).forEach(l => lines.push(l));
                });
            });
        }
        
        lines.push('');
        if (advTmiId) lines.push('TMI ID: ' + advTmiId);
        lines.push('EFFECTIVE TIME: ' + (advEffTime || ''));
        $('#advOutput').val(lines.join('\n'));
    }

    function advInitFacilitiesDropdown() {
        const $grid = $('#advFacilitiesGrid');
        if (!$grid.length) return;
        $grid.empty();
        ADV_FACILITY_CODES.forEach(code => {
            const id = 'advFacility_' + code;
            const $check = $('<input>').attr('type', 'checkbox').addClass('form-check-input').attr('id', id).attr('data-code', code).val(code);
            const $label = $('<label>').addClass('form-check-label').attr('for', id).text(code);
            const $wrapper = $('<div>').addClass('form-check');
            $wrapper.append($check).append($label);
            $grid.append($wrapper);
        });
        $('#advFacilitiesToggle').on('click', function(e) {
            e.stopPropagation();
            const current = ($('#advFacilities').val() || '').toUpperCase();
            const selected = new Set(current.split(/[\/\s]+/).filter(Boolean));
            $('#advFacilitiesGrid input[type="checkbox"]').each(function() {
                const code = ($(this).attr('data-code') || '').toUpperCase();
                $(this).prop('checked', selected.has(code));
            });
            $('#advFacilitiesDropdown').toggle();
        });
        $('#advFacilitiesApply').on('click', function(e) {
            e.stopPropagation();
            const selected = [];
            $('#advFacilitiesGrid input[type="checkbox"]:checked').each(function() {
                const code = ($(this).attr('data-code') || '').toUpperCase();
                if (code) selected.push(code);
            });
            selected.sort();
            $('#advFacilities').val(selected.join('/'));
            $('#advFacilitiesDropdown').hide();
        });
        $('#advFacilitiesClear').on('click', function(e) {
            e.stopPropagation();
            $('#advFacilitiesGrid input[type="checkbox"]').prop('checked', false);
            $('#advFacilities').val('');
        });
        $('#advFacilitiesSelectAll').on('click', function(e) {
            e.stopPropagation();
            $('#advFacilitiesGrid input[type="checkbox"]').prop('checked', true);
        });
        $('#advFacilitiesSelectUs').on('click', function(e) {
            e.stopPropagation();
            const usSet = new Set(ADV_US_FACILITY_CODES.map(c => c.toUpperCase()));
            $('#advFacilitiesGrid input[type="checkbox"]').each(function() {
                const code = ($(this).attr('data-code') || '').toUpperCase();
                $(this).prop('checked', usSet.has(code));
            });
        });
        $(document).on('click', function(e) {
            const $wrap = $('.adv-facilities-wrapper');
            if (!$wrap.length) return;
            if (!$wrap.is(e.target) && $wrap.has(e.target).length === 0) {
                $('#advFacilitiesDropdown').hide();
            }
        });
    }

    /**
     * Set default advisory times in UTC
     * Start: current UTC time
     * End: start + 3 hours, snapped to next :14, :29, :44, or :59
     * Mirrors GS time handling in tmi.js initializeDefaultGsTimes()
     */
    function advSetDefaultTimesUtc() {
        function pad2(n) { return n.toString().padStart(2, '0'); }
        
        const now = new Date();
        
        // Advisory Date: use current UTC date (mm/dd/yyyy format)
        const mm = pad2(now.getUTCMonth() + 1);
        const dd = pad2(now.getUTCDate());
        const yyyy = now.getUTCFullYear();
        $('#advDate').val(mm + '/' + dd + '/' + yyyy);
        
        // Start: current UTC time (no offset)
        const start = new Date(now.getTime());
        const ddStart = pad2(start.getUTCDate());
        const hhStart = pad2(start.getUTCHours());
        const mnStart = pad2(start.getUTCMinutes());
        
        // End: start + 3 hours, snapped to :14, :29, :44, or :59
        let end = new Date(start.getTime() + 3 * 60 * 60 * 1000);
        let endMinutes = end.getUTCMinutes();
        
        // Find nearest :14, :29, :44, or :59 endpoint
        const endPoints = [14, 29, 44, 59];
        let targetMin = 59; // default to :59 if current minutes > 59 (shouldn't happen)
        for (let i = 0; i < endPoints.length; i++) {
            if (endMinutes <= endPoints[i]) {
                targetMin = endPoints[i];
                break;
            }
        }
        
        // Apply snapped minutes
        end.setUTCMinutes(targetMin);
        end.setUTCSeconds(0);
        end.setUTCMilliseconds(0);
        
        const ddEnd = pad2(end.getUTCDate());
        const hhEnd = pad2(end.getUTCHours());
        const mnEnd = pad2(end.getUTCMinutes());
        
        const validStartStr = ddStart + hhStart + mnStart;
        const validEndStr = ddEnd + hhEnd + mnEnd;
        
        $('#advValidStart').val(validStartStr);
        $('#advValidEnd').val(validEndStr);
        $('#advEffectiveTime').val(validStartStr + '-' + validEndStr);
    }
    
    /**
     * Update advisory effective time when valid start/end fields change
     * Auto-updates date in effective time string based on actual input values
     */
    function advUpdateEffectiveTime() {
        const startVal = ($('#advValidStart').val() || '').trim();
        const endVal = ($('#advValidEnd').val() || '').trim();
        
        if (startVal && endVal) {
            $('#advEffectiveTime').val(startVal + '-' + endVal);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // EVENT BINDINGS
    // ═══════════════════════════════════════════════════════════════════════════

    $(document).on('click', '#plot_r', function() {
        console.log('[MAPLIBRE] Plot button clicked');
        processAndDisplayRoutes();
    });
    $(document).on('click', '#plot_c', function() {
        // Copy functionality - leave for now
    });
    $(document).on('keyup change', '#filter', updateFilteredAirways);
    $(document).on('click', '#filter_c', function() { $('#filter').val(''); updateFilteredAirways(); });

    // Advisory Builder event bindings
    $('#adv_generate').on('click', function() {
        generateRerouteAdvisory();
    });
    $('#adv_copy').on('click', function() {
        const txt = $('#advOutput').val();
        if (!txt) return;
        navigator.clipboard.writeText(txt);
    });
    $('#adv_panel_toggle').on('click', function() {
        const body = $('#adv_panel_body');
        const isVisible = body.is(':visible');
        body.slideToggle(150);
        $(this).text(isVisible ? 'Show' : 'Hide');
    });
    
    // Auto-update effective time when valid start/end change
    $(document).on('input change', '#advValidStart, #advValidEnd', function() {
        advUpdateEffectiveTime();
    });

    // Initialize advisory defaults when MapLibre is used
    if (localStorage.getItem('useMapLibre') === 'true' || 
        (typeof window.PERTI_USE_MAPLIBRE !== 'undefined' && window.PERTI_USE_MAPLIBRE)) {
        advSetDefaultTimesUtc();
        advInitFacilitiesDropdown();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════════

    window.MapLibreRoute = {
        init: initMap,
        isReady: () => mapReady,
        getMap: () => graphic_map,
        compat: MapCompat,
        processRoutes: processAndDisplayRoutes,
        updateFilteredAirways,
        convertRoute: ConvertRoute,
        getPointByName,
        getPoints: () => points,
        getCdrMap: () => cdrMap,
        getPlaybookRoutes: () => playbookRoutes,
        getLastExpandedRoutes: () => lastExpandedRoutes, // For public routes feature
        // Export current route features for public routes capture
        getCurrentRouteFeatures: () => {
            if (!graphic_map || !graphic_map.getSource('routes')) return null;
            const source = graphic_map.getSource('routes');
            if (source && source._data && source._data.features) {
                return JSON.parse(JSON.stringify(source._data)); // Deep copy
            }
            return null;
        },
        // Phase 5: Route label control
        toggleRouteLabels: toggleRouteLabelsForRoute,
        clearRouteLabels: () => { routeLabelsVisible.clear(); updateRouteLabelDisplay(); },
        showAllRouteLabels: () => {
            Object.keys(routeFixesByRouteId).forEach(id => routeLabelsVisible.add(parseInt(id)));
            updateRouteLabelDisplay();
        },
        resetLabelPositions: resetLabelPositions
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // INITIALIZATION
    // ═══════════════════════════════════════════════════════════════════════════

    const USE_MAPLIBRE = localStorage.getItem('useMapLibre') === 'true' || 
                         (typeof window.PERTI_USE_MAPLIBRE !== 'undefined' && window.PERTI_USE_MAPLIBRE);

    if (USE_MAPLIBRE) {
        console.log('[MAPLIBRE] Feature flag enabled, initializing MapLibre GL JS');
        setTimeout(initMap, 100);
    } else {
        console.log('[MAPLIBRE] Feature flag disabled, Leaflet will be used');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ROUTE SYMBOLOGY INTEGRATION
    // ═══════════════════════════════════════════════════════════════════════════
    
    function initRouteSymbologyIntegration() {
        if (typeof RouteSymbology === 'undefined') {
            console.log('[MAPLIBRE] RouteSymbology not available, skipping integration');
            return;
        }
        
        if (!graphic_map) {
            console.log('[MAPLIBRE] Map not ready, deferring symbology init');
            setTimeout(initRouteSymbologyIntegration, 500);
            return;
        }
        
        // Load and apply symbology settings
        RouteSymbology.load();
        RouteSymbology.applyToMapLibre(graphic_map);
        
        // Register change callback
        RouteSymbology.onChange(function() {
            RouteSymbology.applyToMapLibre(graphic_map);
        });
        
        // Add double-click handler for quick style editing on segments
        enhanceSegmentClickHandler();
        
        console.log('[MAPLIBRE] Route Symbology integration complete');
    }
    
    function enhanceSegmentClickHandler() {
        // Add double-click handler for quick style editing
        ['routes-solid', 'routes-dashed', 'routes-fan'].forEach(layerId => {
            if (!graphic_map || !graphic_map.getLayer(layerId)) return;
            
            graphic_map.on('dblclick', layerId, function(e) {
                if (!e.features || !e.features[0]) return;
                if (typeof RouteSymbology === 'undefined') return;
                
                e.preventDefault();
                
                const props = e.features[0].properties;
                const routeId = props.routeId || 0;
                const segmentKey = `${props.fromFix || ''}|${props.toFix || ''}|${routeId}`;
                const segType = props.isFan ? 'fan' : (props.solid ? 'solid' : 'dashed');
                
                const currentStyle = RouteSymbology.getSegmentSymbology(segmentKey, routeId, segType, props.color);
                
                RouteSymbology.showSegmentEditor(e.lngLat, segmentKey, routeId, currentStyle, function() {
                    RouteSymbology.applyToMapLibre(graphic_map);
                }).addTo(graphic_map);
            });
        });
    }
    
    // Expose symbology methods on public API
    if (typeof window.MapLibreRoute !== 'undefined') {
        window.MapLibreRoute.applySymbology = function() {
            if (typeof RouteSymbology !== 'undefined' && graphic_map) {
                RouteSymbology.applyToMapLibre(graphic_map);
            }
        };
        window.MapLibreRoute.getSymbology = function() {
            return typeof RouteSymbology !== 'undefined' ? RouteSymbology : null;
        };
    }
    
    // Auto-initialize symbology integration when map is ready
    if (USE_MAPLIBRE) {
        setTimeout(initRouteSymbologyIntegration, 1000);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // EXPORT FUNCTIONALITY
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Parse route input and collect comprehensive metadata for export
     */
    function collectExportData() {
        const rawInput = $('#routeSearch').val() || '';
        const routes = [];
        const lines = rawInput.split(/\r?\n/);
        
        let currentGroupName = null;
        let currentGroupColor = null;
        let currentGroupMandatory = false;
        
        // First pass: identify groups and individual routes
        const routeEntries = [];
        
        lines.forEach((line, lineIdx) => {
            const trimmed = (line || '').trim();
            if (!trimmed) {
                currentGroupName = null;
                currentGroupColor = null;
                currentGroupMandatory = false;
                return;
            }
            
            // Check for group header
            const headerMatch = trimmed.match(/^(>?)\s*\[([^\]]+)\]\s*(<?)\s*(?:;(.+))?$/i);
            if (headerMatch) {
                currentGroupName = headerMatch[2].trim();
                currentGroupColor = headerMatch[4] ? headerMatch[4].trim().toUpperCase() : null;
                currentGroupMandatory = headerMatch[1] === '>' || headerMatch[3] === '<';
                return;
            }
            
            // Simple group header
            if (trimmed.startsWith('[') && trimmed.endsWith(']')) {
                let inner = trimmed.slice(1, -1).trim();
                currentGroupMandatory = inner.includes('><');
                inner = inner.replace('><', '').trim();
                const semiIdx = inner.indexOf(';');
                if (semiIdx !== -1) {
                    currentGroupName = inner.slice(0, semiIdx).trim();
                    currentGroupColor = inner.slice(semiIdx + 1).trim().toUpperCase();
                } else {
                    currentGroupName = inner;
                    currentGroupColor = null;
                }
                return;
            }
            
            // This is a route line
            routeEntries.push({
                line: trimmed,
                lineNumber: lineIdx + 1,
                groupName: currentGroupName,
                groupColor: currentGroupColor,
                groupMandatory: currentGroupMandatory
            });
        });
        
        // Process each route entry
        routeEntries.forEach((entry, routeIdx) => {
            let baseLine = entry.line;
            let color = entry.groupColor;
            let isMandatory = entry.groupMandatory;
            
            // Extract color from route line if present
            const semiIdx = baseLine.indexOf(';');
            if (semiIdx !== -1) {
                color = baseLine.slice(semiIdx + 1).trim().toUpperCase() || color;
                baseLine = baseLine.slice(0, semiIdx).trim();
            }
            
            // Check for mandatory markers
            if (baseLine.startsWith('>') && baseLine.endsWith('<')) {
                isMandatory = true;
                baseLine = baseLine.slice(1, -1).trim();
            }
            
            const routeUpper = baseLine.toUpperCase();
            
            // Detect playbook directive
            let playbookName = null;
            let playbookOrigins = null;
            let playbookDests = null;
            
            if (routeUpper.startsWith('PB.')) {
                const pbParts = routeUpper.slice(3).split('.');
                playbookName = pbParts[0] || null;
                playbookOrigins = pbParts[1] || null;
                playbookDests = pbParts[2] || null;
                
                // Expand playbook routes
                const pbRoutes = expandPlaybookDirective(routeUpper.slice(3), isMandatory, color);
                pbRoutes.forEach((pbRoute, pbIdx) => {
                    const routeMeta = parseRouteMetadata(pbRoute, routeIdx * 1000 + pbIdx);
                    routeMeta.groupName = entry.groupName;
                    routeMeta.playbookName = playbookName;
                    routeMeta.playbookOrigins = playbookOrigins;
                    routeMeta.playbookDests = playbookDests;
                    routeMeta.isMandatory = isMandatory;
                    routes.push(routeMeta);
                });
                return;
            }
            
            // Detect CDR
            let cdrCode = null;
            const tokens = routeUpper.split(/\s+/).filter(t => t);
            if (tokens.length === 1 && cdrMap[tokens[0]]) {
                cdrCode = tokens[0];
            }
            
            // Parse route metadata
            const routeMeta = parseRouteMetadata(entry.line, routeIdx);
            routeMeta.groupName = entry.groupName;
            routeMeta.cdrCode = cdrCode;
            routeMeta.isMandatory = isMandatory;
            routes.push(routeMeta);
        });
        
        // Collect GeoJSON features from map sources
        let routeFeatures = [];
        let fixFeatures = [];
        
        if (graphic_map) {
            try {
                const routeSource = graphic_map.getSource('routes');
                if (routeSource && routeSource._data) {
                    routeFeatures = routeSource._data.features || [];
                }
            } catch (e) {}
            
            try {
                const fixSource = graphic_map.getSource('route-fixes');
                if (fixSource && fixSource._data) {
                    fixFeatures = fixSource._data.features || [];
                }
            } catch (e) {}
        }
        
        lastExportData = {
            routes: routes,
            routeFeatures: routeFeatures,
            fixFeatures: fixFeatures,
            timestamp: new Date().toISOString()
        };
        
        return lastExportData;
    }
    
    /**
     * Parse a single route string and extract metadata
     */
    function parseRouteMetadata(routeString, routeId) {
        let routeText = (routeString || '').toString().trim();
        let color = null;
        
        // Extract color
        const semiIdx = routeText.indexOf(';');
        if (semiIdx !== -1) {
            color = routeText.slice(semiIdx + 1).trim();
            routeText = routeText.slice(0, semiIdx).trim();
        }
        
        // Strip mandatory markers for parsing
        let cleanRoute = routeText.replace(/[<>]/g, '').trim().toUpperCase();
        
        // CDR expansion
        let cdrExpanded = null;
        const routeTokens = cleanRoute.split(/\s+/).filter(t => t);
        if (routeTokens.length === 1 && cdrMap[routeTokens[0]]) {
            cdrExpanded = cdrMap[routeTokens[0]];
            cleanRoute = cdrExpanded;
        }
        
        // Parse tokens
        const tokens = cleanRoute.split(/\s+/).filter(t => t);
        
        // Detect origins and destinations
        const origins = [];
        const destinations = [];
        let routeBody = [];
        let foundFirstNonAirport = false;
        let lastNonAirportIdx = -1;
        
        tokens.forEach((tok, i) => {
            if (isAirportIdent(tok)) {
                if (!foundFirstNonAirport) {
                    origins.push(tok);
                }
            } else {
                foundFirstNonAirport = true;
                lastNonAirportIdx = i;
            }
        });
        
        // Work backwards to find destinations
        for (let i = tokens.length - 1; i >= 0; i--) {
            if (isAirportIdent(tokens[i]) && i > lastNonAirportIdx) {
                destinations.unshift(tokens[i]);
            } else {
                break;
            }
        }
        
        // Get route body (middle portion)
        const startIdx = origins.length;
        const endIdx = tokens.length - destinations.length;
        routeBody = tokens.slice(startIdx, endIdx);
        
        // Detect DP/STAR
        let departureProc = null;
        let departureTransition = null;
        let arrivalProc = null;
        let arrivalTransition = null;
        
        tokens.forEach(tok => {
            // DP pattern: NAME#.TRANSITION or TRANSITION.NAME#
            const dpMatch = tok.match(/^([A-Z]{4,5}\d)\.([A-Z]{3,5})$/) || tok.match(/^([A-Z]{3,5})\.([A-Z]{4,5}\d)$/);
            if (dpMatch) {
                if (tok.includes('#') || /\d$/.test(tok.split('.')[0])) {
                    // Likely a DP
                    if (!departureProc) {
                        departureProc = dpMatch[1];
                        departureTransition = dpMatch[2];
                    }
                }
            }
            
            // STAR pattern: FIX.STAR#
            const starMatch = tok.match(/^([A-Z]{3,5})\.([A-Z]{4,5}\d?)#?$/);
            if (starMatch && tok.includes('#')) {
                arrivalTransition = starMatch[1];
                arrivalProc = starMatch[2];
            }
        });
        
        // Get expanded route
        let expandedRoute = cleanRoute;
        if (typeof ConvertRoute === 'function') {
            try {
                expandedRoute = ConvertRoute(cleanRoute);
            } catch (e) {}
        }
        
        // Get symbology
        let symbology = null;
        if (typeof RouteSymbology !== 'undefined') {
            try {
                symbology = RouteSymbology.getRouteSymbology(routeId);
            } catch (e) {}
        }
        
        return {
            routeId: routeId,
            originalString: routeString,
            routeString: cleanRoute,
            expandedRouteString: expandedRoute,
            color: color,
            origins: origins,
            destinations: destinations,
            routeBody: routeBody.join(' '),
            departureProc: departureProc,
            departureTransition: departureTransition,
            arrivalProc: arrivalProc,
            arrivalTransition: arrivalTransition,
            symbology: symbology,
            groupName: null,
            playbookName: null,
            playbookOrigins: null,
            playbookDests: null,
            cdrCode: null,
            isMandatory: false
        };
    }
    
    /**
     * Generate GeoJSON FeatureCollection for a route string
     * This mirrors the main route processing logic for consistent results
     * Used to pre-generate route_geojson for public routes storage
     * 
     * @param {string} routeString - Route string (can be multi-line for multiple routes)
     * @param {object} options - Optional settings { color: '#00ffff' }
     * @returns {object} GeoJSON FeatureCollection
     */
    function generateRouteGeoJSON(routeString, options) {
        options = options || {};
        const defaultColor = options.color || '#00ffff';
        
        if (!routeString || typeof routeString !== 'string') {
            return { type: 'FeatureCollection', features: [] };
        }
        
        const features = [];
        const lines = routeString.split(/[\r\n]+/);
        
        lines.forEach(function(line, lineIdx) {
            let lineTrimmed = (line || '').trim();
            if (!lineTrimmed) return;
            
            // Extract color suffix if present
            let lineColor = defaultColor;
            const semiIdx = lineTrimmed.indexOf(';');
            if (semiIdx !== -1) {
                const colorPart = lineTrimmed.slice(semiIdx + 1).trim();
                if (colorPart) lineColor = colorPart;
                lineTrimmed = lineTrimmed.slice(0, semiIdx).trim();
            }
            
            // Strip mandatory markers for processing
            lineTrimmed = lineTrimmed.replace(/[<>]/g, '').toUpperCase();
            if (!lineTrimmed) return;
            
            // CDR expansion
            const cdrTokens = lineTrimmed.split(/\s+/).filter(function(t) { return t; });
            if (cdrTokens.length === 1 && cdrMap && cdrMap[cdrTokens[0]]) {
                lineTrimmed = cdrMap[cdrTokens[0]];
            } else if (cdrTokens.length > 1 && cdrMap) {
                const expandedCdr = [];
                cdrTokens.forEach(function(tok) {
                    if (cdrMap[tok]) {
                        expandedCdr.push.apply(expandedCdr, cdrMap[tok].split(/\s+/).filter(function(t) { return t; }));
                    } else {
                        expandedCdr.push(tok);
                    }
                });
                lineTrimmed = expandedCdr.join(' ');
            }
            
            // Expand airways
            let expanded = lineTrimmed;
            if (typeof ConvertRoute === 'function') {
                try {
                    expanded = ConvertRoute(lineTrimmed);
                } catch (e) {
                    console.warn('[generateRouteGeoJSON] ConvertRoute failed:', e);
                }
            }
            
            const tokens = expanded.split(/\s+/).filter(function(t) { return t; });
            if (tokens.length < 2) return;
            
            // Resolve points with context (two-pass like main plotter)
            const routePoints = [];
            let previousPointData = null;
            
            for (let i = 0; i < tokens.length; i++) {
                const pointName = tokens[i].toUpperCase();
                
                // Skip pure airways that weren't expanded (shouldn't happen but safety check)
                if (/^[JQTV]\d+$/i.test(pointName)) continue;
                
                // Skip speed/altitude restrictions
                if (/^[NMFSA]\d{3,4}$/.test(pointName)) continue;
                if (/^\d{3,}$/.test(pointName)) continue;
                
                // Handle DP/STAR dotted notation - extract fix name
                let lookupName = pointName;
                if (pointName.includes('.')) {
                    const dotParts = pointName.split('.');
                    // Try first part (usually the fix for DPs like THHMP.CAVLR6)
                    lookupName = dotParts[0];
                }
                
                // Look ahead for next point (for context)
                let nextPointData = null;
                for (let j = i + 1; j < tokens.length && j < i + 3; j++) {
                    let nextName = tokens[j].toUpperCase();
                    if (/^[JQTV]\d+$/i.test(nextName)) continue;
                    if (/^[NMFSA]\d{3,4}$/.test(nextName)) continue;
                    if (nextName.includes('.')) nextName = nextName.split('.')[0];
                    
                    const testPoint = getPointByName(nextName);
                    if (testPoint && Array.isArray(testPoint) && testPoint.length >= 3) {
                        nextPointData = testPoint;
                        break;
                    }
                }
                
                // Resolve current point
                let pointData = getPointByName(lookupName, previousPointData, nextPointData);
                
                // Fallback to area centers for facility codes
                if (!pointData || pointData.length < 3) {
                    if (typeof areaCenters !== 'undefined' && areaCenters[lookupName]) {
                        const center = areaCenters[lookupName];
                        pointData = [lookupName, center.lat, center.lon];
                    }
                }
                
                if (pointData && Array.isArray(pointData) && pointData.length >= 3) {
                    routePoints.push({
                        name: pointData[0],
                        lat: pointData[1],
                        lon: pointData[2]
                    });
                    previousPointData = pointData;
                }
            }
            
            // Create line segments
            if (routePoints.length >= 2) {
                for (let i = 0; i < routePoints.length - 1; i++) {
                    const from = routePoints[i];
                    const to = routePoints[i + 1];
                    
                    features.push({
                        type: 'Feature',
                        properties: {
                            featureType: 'route_segment',
                            lineIndex: lineIdx,
                            fromFix: from.name,
                            toFix: to.name,
                            color: lineColor
                        },
                        geometry: {
                            type: 'LineString',
                            coordinates: [
                                [from.lon, from.lat],
                                [to.lon, to.lat]
                            ]
                        }
                    });
                }
                
                // Add fix point features
                routePoints.forEach(function(pt, ptIdx) {
                    features.push({
                        type: 'Feature',
                        properties: {
                            featureType: 'fix',
                            name: pt.name,
                            lineIndex: lineIdx,
                            pointIndex: ptIdx,
                            color: lineColor
                        },
                        geometry: {
                            type: 'Point',
                            coordinates: [pt.lon, pt.lat]
                        }
                    });
                });
            }
        });
        
        return {
            type: 'FeatureCollection',
            features: features,
            metadata: {
                generatedAt: new Date().toISOString(),
                source: 'PERTI Route Visualization',
                featureCount: features.length
            }
        };
    }
    
    /**
     * Export routes to GeoJSON format
     */
    function exportToGeoJSON() {
        const data = collectExportData();
        
        // Build comprehensive feature collection
        const features = [];
        
        // Add route line features with metadata
        data.routeFeatures.forEach((feat, idx) => {
            const props = feat.properties || {};
            const routeId = props.routeId || 0;
            const routeMeta = data.routes.find(r => r.routeId === routeId) || {};
            
            features.push({
                type: 'Feature',
                properties: {
                    featureType: 'route_segment',
                    routeId: routeId,
                    fromFix: props.fromFix || '',
                    toFix: props.toFix || '',
                    color: props.color || '',
                    solid: props.solid || false,
                    isFan: props.isFan || false,
                    distance_nm: props.distance || 0,
                    groupName: routeMeta.groupName || '',
                    playbookName: routeMeta.playbookName || '',
                    cdrCode: routeMeta.cdrCode || '',
                    origins: (routeMeta.origins || []).join('/'),
                    destinations: (routeMeta.destinations || []).join('/'),
                    routeString: routeMeta.routeString || '',
                    expandedRouteString: routeMeta.expandedRouteString || '',
                    departureProc: routeMeta.departureProc || '',
                    departureTransition: routeMeta.departureTransition || '',
                    arrivalProc: routeMeta.arrivalProc || '',
                    arrivalTransition: routeMeta.arrivalTransition || '',
                    isMandatory: routeMeta.isMandatory || false
                },
                geometry: feat.geometry
            });
        });
        
        // Add fix point features
        data.fixFeatures.forEach(feat => {
            features.push({
                type: 'Feature',
                properties: {
                    featureType: 'fix',
                    name: feat.properties.name || '',
                    routeId: feat.properties.routeId || 0,
                    color: feat.properties.color || ''
                },
                geometry: feat.geometry
            });
        });
        
        const geojson = {
            type: 'FeatureCollection',
            name: 'PERTI_Routes_Export',
            crs: { type: 'name', properties: { name: 'urn:ogc:def:crs:OGC:1.3:CRS84' } },
            metadata: {
                exportedAt: data.timestamp,
                routeCount: data.routes.length,
                source: 'PERTI Route Visualization'
            },
            features: features
        };
        
        downloadFile(JSON.stringify(geojson, null, 2), 'perti_routes.geojson', 'application/geo+json');
    }
    
    /**
     * Export routes to KML format
     */
    function exportToKML() {
        const data = collectExportData();
        
        // Build unique routes with their features grouped
        const routeGroups = {};
        data.routeFeatures.forEach(feat => {
            const routeId = feat.properties.routeId || 0;
            if (!routeGroups[routeId]) routeGroups[routeId] = [];
            routeGroups[routeId].push(feat);
        });
        
        let kml = `<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
<Document>
    <name>PERTI Routes Export</name>
    <description>Exported from PERTI Route Visualization - ${data.timestamp}</description>
`;
        
        // Define styles for each color
        const colors = new Set();
        data.routeFeatures.forEach(f => colors.add(f.properties.color || '#C70039'));
        
        colors.forEach(color => {
            const kmlColor = colorToKML(color);
            kml += `    <Style id="style_${color.replace('#', '')}">
        <LineStyle>
            <color>${kmlColor}</color>
            <width>3</width>
        </LineStyle>
        <PolyStyle>
            <color>${kmlColor}</color>
        </PolyStyle>
    </Style>
`;
        });
        
        // Add route folders
        Object.keys(routeGroups).forEach(routeId => {
            const features = routeGroups[routeId];
            const routeMeta = data.routes.find(r => r.routeId == routeId) || {};
            const routeName = routeMeta.groupName || routeMeta.routeString || `Route ${routeId}`;
            
            kml += `    <Folder>
        <name>${escapeXml(routeName)}</name>
        <description><![CDATA[
Route ID: ${routeId}
Group: ${routeMeta.groupName || 'N/A'}
Playbook: ${routeMeta.playbookName || 'N/A'}
CDR: ${routeMeta.cdrCode || 'N/A'}
Origins: ${(routeMeta.origins || []).join(', ') || 'N/A'}
Destinations: ${(routeMeta.destinations || []).join(', ') || 'N/A'}
Route: ${routeMeta.routeString || 'N/A'}
Expanded: ${routeMeta.expandedRouteString || 'N/A'}
Departure: ${routeMeta.departureProc || 'N/A'} / ${routeMeta.departureTransition || 'N/A'}
Arrival: ${routeMeta.arrivalProc || 'N/A'} / ${routeMeta.arrivalTransition || 'N/A'}
        ]]></description>
`;
            
            features.forEach((feat, idx) => {
                const coords = feat.geometry.coordinates.map(c => `${c[0]},${c[1]},0`).join(' ');
                const styleId = (feat.properties.color || '#C70039').replace('#', '');
                const segName = `${feat.properties.fromFix || '?'} → ${feat.properties.toFix || '?'}`;
                
                kml += `        <Placemark>
            <name>${escapeXml(segName)}</name>
            <styleUrl>#style_${styleId}</styleUrl>
            <ExtendedData>
                <Data name="fromFix"><value>${escapeXml(feat.properties.fromFix || '')}</value></Data>
                <Data name="toFix"><value>${escapeXml(feat.properties.toFix || '')}</value></Data>
                <Data name="distance_nm"><value>${feat.properties.distance || 0}</value></Data>
                <Data name="solid"><value>${feat.properties.solid || false}</value></Data>
                <Data name="isFan"><value>${feat.properties.isFan || false}</value></Data>
            </ExtendedData>
            <LineString>
                <tessellate>1</tessellate>
                <coordinates>${coords}</coordinates>
            </LineString>
        </Placemark>
`;
            });
            
            kml += `    </Folder>
`;
        });
        
        // Add fixes folder
        if (data.fixFeatures.length > 0) {
            kml += `    <Folder>
        <name>Fixes / Waypoints</name>
`;
            data.fixFeatures.forEach(feat => {
                const coord = feat.geometry.coordinates;
                kml += `        <Placemark>
            <name>${escapeXml(feat.properties.name || '')}</name>
            <Point>
                <coordinates>${coord[0]},${coord[1]},0</coordinates>
            </Point>
        </Placemark>
`;
            });
            kml += `    </Folder>
`;
        }
        
        kml += `</Document>
</kml>`;
        
        downloadFile(kml, 'perti_routes.kml', 'application/vnd.google-earth.kml+xml');
    }
    
    /**
     * Export routes to GeoPackage format
     * Note: GPKG is a SQLite-based format. We'll create a simplified version using sql.js if available,
     * or fall back to a well-structured GeoJSON that can be converted.
     */
    function exportToGPKG() {
        // Check if sql.js is available for native GPKG creation
        if (typeof initSqlJs !== 'undefined') {
            exportToGPKGNative();
            return;
        }
        
        // Fallback: Create a zip with GeoJSON + conversion instructions
        // For now, we'll create a comprehensive GeoJSON that tools like QGIS can easily convert
        const data = collectExportData();
        
        // Create separate layers as GeoJSON files bundled info
        const exportBundle = {
            format: 'PERTI_GPKG_Bundle',
            version: '1.0',
            description: 'Import this GeoJSON into QGIS/ArcGIS and export as GeoPackage, or use ogr2ogr',
            timestamp: data.timestamp,
            layers: {
                route_segments: {
                    type: 'FeatureCollection',
                    features: data.routeFeatures.map((feat, idx) => {
                        const routeId = feat.properties.routeId || 0;
                        const routeMeta = data.routes.find(r => r.routeId === routeId) || {};
                        return {
                            type: 'Feature',
                            properties: {
                                fid: idx + 1,
                                route_id: routeId,
                                from_fix: feat.properties.fromFix || '',
                                to_fix: feat.properties.toFix || '',
                                color: feat.properties.color || '',
                                is_solid: feat.properties.solid ? 1 : 0,
                                is_fan: feat.properties.isFan ? 1 : 0,
                                distance_nm: feat.properties.distance || 0,
                                group_name: routeMeta.groupName || '',
                                playbook: routeMeta.playbookName || '',
                                cdr_code: routeMeta.cdrCode || '',
                                origins: (routeMeta.origins || []).join('/'),
                                destinations: (routeMeta.destinations || []).join('/'),
                                route_str: routeMeta.routeString || '',
                                expanded_str: routeMeta.expandedRouteString || '',
                                dep_proc: routeMeta.departureProc || '',
                                dep_trans: routeMeta.departureTransition || '',
                                arr_proc: routeMeta.arrivalProc || '',
                                arr_trans: routeMeta.arrivalTransition || '',
                                mandatory: routeMeta.isMandatory ? 1 : 0
                            },
                            geometry: feat.geometry
                        };
                    })
                },
                fixes: {
                    type: 'FeatureCollection', 
                    features: data.fixFeatures.map((feat, idx) => ({
                        type: 'Feature',
                        properties: {
                            fid: idx + 1,
                            name: feat.properties.name || '',
                            route_id: feat.properties.routeId || 0,
                            color: feat.properties.color || ''
                        },
                        geometry: feat.geometry
                    }))
                },
                routes_meta: data.routes.map((r, idx) => ({
                    fid: idx + 1,
                    route_id: r.routeId,
                    original_str: r.originalString,
                    route_str: r.routeString,
                    expanded_str: r.expandedRouteString,
                    color: r.color,
                    group_name: r.groupName,
                    playbook: r.playbookName,
                    pb_origins: r.playbookOrigins,
                    pb_dests: r.playbookDests,
                    cdr_code: r.cdrCode,
                    origins: (r.origins || []).join('/'),
                    destinations: (r.destinations || []).join('/'),
                    route_body: r.routeBody,
                    dep_proc: r.departureProc,
                    dep_trans: r.departureTransition,
                    arr_proc: r.arrivalProc,
                    arr_trans: r.arrivalTransition,
                    mandatory: r.isMandatory ? 1 : 0
                }))
            },
            conversion_command: 'ogr2ogr -f GPKG output.gpkg input.geojson'
        };
        
        downloadFile(JSON.stringify(exportBundle, null, 2), 'perti_routes_gpkg_bundle.json', 'application/json');
    }
    
    /**
     * Convert hex color to KML format (aabbggrr)
     */
    function colorToKML(color) {
        // Handle named colors
        const namedColors = {
            'RED': '#FF0000', 'GREEN': '#00FF00', 'BLUE': '#0000FF',
            'YELLOW': '#FFFF00', 'MAGENTA': '#FF00FF', 'CYAN': '#00FFFF',
            'WHITE': '#FFFFFF', 'BLACK': '#000000', 'GRAY': '#808080',
            'ORANGE': '#FFA500', 'PINK': '#FFC0CB', 'PURPLE': '#800080',
            'BROWN': '#8B4513', 'TEAL': '#008080', 'NAVY': '#000080'
        };
        
        let hex = namedColors[color.toUpperCase()] || color;
        if (!hex.startsWith('#')) hex = '#' + hex;
        
        // Parse RGB
        let r, g, b;
        if (hex.length === 4) {
            r = parseInt(hex[1] + hex[1], 16);
            g = parseInt(hex[2] + hex[2], 16);
            b = parseInt(hex[3] + hex[3], 16);
        } else {
            r = parseInt(hex.slice(1, 3), 16);
            g = parseInt(hex.slice(3, 5), 16);
            b = parseInt(hex.slice(5, 7), 16);
        }
        
        // KML uses aabbggrr format
        const toHex = n => (isNaN(n) ? '00' : n.toString(16).padStart(2, '0'));
        return 'ff' + toHex(b) + toHex(g) + toHex(r);
    }
    
    /**
     * Escape XML special characters
     */
    function escapeXml(str) {
        if (!str) return '';
        return str.toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&apos;');
    }
    
    /**
     * Download file helper
     */
    function downloadFile(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
    
    // Set up export button handlers
    $('#export_geojson').on('click', function() {
        exportToGeoJSON();
    });
    
    $('#export_kml').on('click', function() {
        exportToKML();
    });
    
    $('#export_gpkg').on('click', function() {
        exportToGPKG();
    });
    
    // Expose export functions globally
    window.MapLibreExport = {
        toGeoJSON: exportToGeoJSON,
        toKML: exportToKML,
        toGPKG: exportToGPKG,
        collectData: collectExportData,
        generateRouteGeoJSON: generateRouteGeoJSON  // For pre-generating public route GeoJSON
    };

    /**
     * Utility to regenerate route_geojson for public routes
     * Can be called from console or integrated into PublicRoutes UI
     * 
     * Usage:
     *   window.regeneratePublicRouteGeoJSON()           // Regenerate all routes
     *   window.regeneratePublicRouteGeoJSON(7)          // Regenerate specific route ID
     *   window.regeneratePublicRouteGeoJSON([7, 8, 9])  // Regenerate specific route IDs
     */
    window.regeneratePublicRouteGeoJSON = async function(routeIds) {
        // Check if PublicRoutes module is available
        if (typeof window.PublicRoutes === 'undefined' || !window.PublicRoutes.getRoutes) {
            console.error('[RegenerateGeoJSON] PublicRoutes module not available');
            return { success: false, error: 'PublicRoutes module not available' };
        }
        
        const allRoutes = window.PublicRoutes.getRoutes();
        if (!allRoutes || allRoutes.length === 0) {
            console.warn('[RegenerateGeoJSON] No public routes loaded');
            return { success: false, error: 'No public routes loaded' };
        }
        
        // Filter routes if specific IDs provided
        let routesToProcess = allRoutes;
        if (routeIds !== undefined) {
            const idsArray = Array.isArray(routeIds) ? routeIds : [routeIds];
            routesToProcess = allRoutes.filter(r => idsArray.includes(r.id));
            if (routesToProcess.length === 0) {
                console.warn('[RegenerateGeoJSON] No matching routes found for IDs:', idsArray);
                return { success: false, error: 'No matching routes found' };
            }
        }
        
        console.log('[RegenerateGeoJSON] Processing', routesToProcess.length, 'routes...');
        
        const updates = [];
        const skipped = [];
        
        routesToProcess.forEach(function(route) {
            const routeString = route.route_string;
            if (!routeString) {
                console.warn('[RegenerateGeoJSON] Route', route.id, '(' + route.name + ') has no route_string, skipping');
                skipped.push({ id: route.id, name: route.name, reason: 'No route_string' });
                return;
            }
            
            // Generate GeoJSON using same logic as export
            const geojson = generateRouteGeoJSON(routeString, { color: route.color || '#00ffff' });
            
            if (geojson.features.length === 0) {
                console.warn('[RegenerateGeoJSON] Route', route.id, '(' + route.name + ') generated 0 features, skipping');
                skipped.push({ id: route.id, name: route.name, reason: 'Generated 0 features' });
                return;
            }
            
            console.log('[RegenerateGeoJSON] Route', route.id, '(' + route.name + '):', geojson.features.length, 'features');
            
            updates.push({
                id: route.id,
                route_geojson: geojson
            });
        });
        
        if (updates.length === 0) {
            console.warn('[RegenerateGeoJSON] No routes to update');
            return { success: false, error: 'No routes to update', skipped: skipped };
        }
        
        // POST to API
        console.log('[RegenerateGeoJSON] Sending', updates.length, 'updates to server...');
        
        try {
            const response = await fetch('api/mgt/tmi/reroutes/update_geojson.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ routes: updates })
            });
            
            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }
            
            const result = await response.json();
            
            console.log('[RegenerateGeoJSON] Server response:', result);
            
            if (result.success) {
                console.log('[RegenerateGeoJSON] Successfully updated', result.summary.updated, 'routes');
            } else {
                console.warn('[RegenerateGeoJSON] Some updates failed:', result.errors);
            }
            
            result.skipped = skipped;
            return result;
            
        } catch (err) {
            console.error('[RegenerateGeoJSON] API error:', err);
            return { success: false, error: err.message, skipped: skipped };
        }
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // PUBLIC ROUTES MAPLIBRE INTEGRATION
    // ═══════════════════════════════════════════════════════════════════════════

    (function() {
        var sourceName = 'public-routes-source';
        var labelSourceName = 'public-routes-labels-source';
        
        // Wait for map and PublicRoutes module
        var initInterval = setInterval(function() {
            if (typeof graphic_map !== 'undefined' && graphic_map && graphic_map.isStyleLoaded && graphic_map.isStyleLoaded() && typeof window.PublicRoutes !== 'undefined') {
                clearInterval(initInterval);
                initPublicRoutesIntegration();
            }
        }, 100);
        
        function initPublicRoutesIntegration() {
            console.log('[PublicRoutes-MapLibre] Initializing integration...');
            
            // Add source for route lines
            if (!graphic_map.getSource(sourceName)) {
                graphic_map.addSource(sourceName, {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: [] }
                });
            }

            // Add source for labels
            if (!graphic_map.getSource(labelSourceName)) {
                graphic_map.addSource(labelSourceName, {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: [] }
                });
            }

            // Add line layers - three separate layers like main route plotter
            // Solid segments (mandatory)
            if (!graphic_map.getLayer('public-routes-solid')) {
                graphic_map.addLayer({
                    id: 'public-routes-solid',
                    type: 'line',
                    source: sourceName,
                    filter: ['all', ['==', ['get', 'solid'], true], ['!=', ['get', 'isFan'], true]],
                    layout: {
                        'line-cap': 'round',
                        'line-join': 'round'
                    },
                    paint: {
                        'line-color': ['get', 'color'],
                        'line-width': ['coalesce', ['get', 'weight'], 3],
                        'line-opacity': 0.9
                    }
                });
            }
            
            // Dashed segments (non-mandatory)
            if (!graphic_map.getLayer('public-routes-dashed')) {
                graphic_map.addLayer({
                    id: 'public-routes-dashed',
                    type: 'line',
                    source: sourceName,
                    filter: ['all', ['==', ['get', 'solid'], false], ['!=', ['get', 'isFan'], true]],
                    layout: {
                        'line-cap': 'round',
                        'line-join': 'round'
                    },
                    paint: {
                        'line-color': ['get', 'color'],
                        'line-width': ['coalesce', ['get', 'weight'], 3],
                        'line-opacity': 0.9,
                        'line-dasharray': [4, 4]
                    }
                });
            }
            
            // Fan segments (airport fans)
            if (!graphic_map.getLayer('public-routes-fan')) {
                graphic_map.addLayer({
                    id: 'public-routes-fan',
                    type: 'line',
                    source: sourceName,
                    filter: ['==', ['get', 'isFan'], true],
                    layout: {
                        'line-cap': 'round',
                        'line-join': 'round'
                    },
                    paint: {
                        'line-color': ['get', 'color'],
                        'line-width': 1.5,
                        'line-opacity': 0.9,
                        'line-dasharray': [1, 3]
                    }
                });
            }
            
            // Legacy fallback layer for routes without solid/isFan properties
            if (!graphic_map.getLayer('public-routes-line')) {
                graphic_map.addLayer({
                    id: 'public-routes-line',
                    type: 'line',
                    source: sourceName,
                    filter: ['all', 
                        ['!', ['has', 'solid']], 
                        ['!', ['has', 'isFan']]
                    ],
                    layout: {
                        'line-cap': 'round',
                        'line-join': 'round'
                    },
                    paint: {
                        'line-color': ['get', 'color'],
                        'line-width': ['coalesce', ['get', 'weight'], 3],
                        'line-opacity': 0.9
                    }
                });
            }

            // Add label layer
            if (!graphic_map.getLayer('public-routes-labels')) {
                graphic_map.addLayer({
                    id: 'public-routes-labels',
                    type: 'symbol',
                    source: labelSourceName,
                    layout: {
                        'text-field': ['get', 'name'],
                        'text-size': 12,
                        'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
                        'text-anchor': 'center',
                        'text-allow-overlap': true
                    },
                    paint: {
                        'text-color': '#ffffff',
                        'text-halo-color': ['get', 'color'],
                        'text-halo-width': 3
                    }
                });
            }

            // Override render function
            window.PublicRoutes.setRenderFunction(renderPublicRoutes);
            window.PublicRoutes.setZoomFunction(zoomToPublicRoute);
            window.PublicRoutes.setClearFunction(clearPublicRoutes);

            // Add click handler for routes (all layers)
            ['public-routes-solid', 'public-routes-dashed', 'public-routes-fan', 'public-routes-line'].forEach(function(layerId) {
                graphic_map.on('click', layerId, function(e) {
                    if (!e.features || !e.features[0]) return;
                    var props = e.features[0].properties;
                    
                    new maplibregl.Popup({ closeButton: true, maxWidth: '320px' })
                        .setLngLat(e.lngLat)
                        .setHTML(createRoutePopup(props))
                        .addTo(graphic_map);
                });
                
                // Cursor change
                graphic_map.on('mouseenter', layerId, function() {
                    graphic_map.getCanvas().style.cursor = 'pointer';
                });
                graphic_map.on('mouseleave', layerId, function() {
                    graphic_map.getCanvas().style.cursor = '';
                });
            });

            console.log('[PublicRoutes-MapLibre] Integration ready');
        }

        function renderPublicRoutes() {
            if (!graphic_map || !graphic_map.getSource(sourceName)) return;

            var routes = window.PublicRoutes.getRoutes();
            console.log('[PublicRoutes-MapLibre] Rendering', routes.length, 'routes');

            var lineFeatures = [];
            var labelFeatures = [];

            routes.forEach(function(route) {
                var routeColor = route.color || '#e74c3c';
                var routeWeight = route.line_weight || 3;
                
                // Try to use pre-computed GeoJSON first
                if (route.route_geojson) {
                    var geojson = null;
                    try {
                        geojson = typeof route.route_geojson === 'string' 
                            ? JSON.parse(route.route_geojson) 
                            : route.route_geojson;
                    } catch (e) {
                        console.warn('[PublicRoutes-MapLibre] Failed to parse route_geojson for:', route.name);
                    }
                    
                    if (geojson && geojson.features && geojson.features.length > 0) {
                        console.log('[PublicRoutes-MapLibre] Using stored GeoJSON for:', route.name, 'with', geojson.features.length, 'features');
                        
                        // Add each feature from stored GeoJSON, preserving style properties
                        geojson.features.forEach(function(feature, idx) {
                            if (feature.geometry && feature.geometry.coordinates && feature.geometry.coordinates.length >= 2) {
                                // Preserve solid/isFan from stored feature, apply route color
                                var storedProps = feature.properties || {};
                                lineFeatures.push({
                                    type: 'Feature',
                                    properties: {
                                        id: route.id,
                                        name: route.name,
                                        color: routeColor,  // Always use the route's current color
                                        weight: routeWeight,
                                        solid: storedProps.solid !== undefined ? storedProps.solid : true,
                                        isFan: storedProps.isFan || false,
                                        fromFix: storedProps.fromFix || '',
                                        toFix: storedProps.toFix || '',
                                        distance: storedProps.distance || 0,
                                        constrained_area: route.constrained_area,
                                        reason: route.reason,
                                        valid_start_utc: route.valid_start_utc,
                                        valid_end_utc: route.valid_end_utc,
                                        route_string: route.route_string,
                                        featureIndex: idx
                                    },
                                    geometry: feature.geometry
                                });
                            }
                        });
                        
                        // Add label at center of first feature
                        var firstFeature = geojson.features[0];
                        if (firstFeature && firstFeature.geometry && firstFeature.geometry.coordinates) {
                            var coords = firstFeature.geometry.coordinates;
                            var midIdx = Math.floor(coords.length / 2);
                            labelFeatures.push({
                                type: 'Feature',
                                properties: {
                                    name: route.name,
                                    color: routeColor
                                },
                                geometry: {
                                    type: 'Point',
                                    coordinates: coords[midIdx]
                                }
                            });
                        }
                        return; // Done with this route
                    }
                }
                
                // Fallback: try to parse route_string (legacy routes)
                var coords = parseRouteToCoords(route.route_string);
                if (coords.length < 2) {
                    console.warn('[PublicRoutes-MapLibre] Could not parse route:', route.name, '- no stored GeoJSON and route_string parsing failed');
                    return;
                }

                // Line feature - legacy routes render as solid (no solid/isFan so goes to fallback layer)
                lineFeatures.push({
                    type: 'Feature',
                    properties: {
                        id: route.id,
                        name: route.name,
                        color: routeColor,
                        weight: routeWeight,
                        constrained_area: route.constrained_area,
                        reason: route.reason,
                        valid_start_utc: route.valid_start_utc,
                        valid_end_utc: route.valid_end_utc,
                        route_string: route.route_string
                    },
                    geometry: {
                        type: 'LineString',
                        coordinates: coords
                    }
                });

                // Label feature at midpoint
                if (coords.length >= 2) {
                    var midIdx = Math.floor(coords.length / 2);
                    labelFeatures.push({
                        type: 'Feature',
                        properties: {
                            name: route.name,
                            color: routeColor
                        },
                        geometry: {
                            type: 'Point',
                            coordinates: coords[midIdx]
                        }
                    });
                }
            });

            graphic_map.getSource(sourceName).setData({
                type: 'FeatureCollection',
                features: lineFeatures
            });

            graphic_map.getSource(labelSourceName).setData({
                type: 'FeatureCollection',
                features: labelFeatures
            });
        }

        function parseRouteToCoords(routeString) {
            if (!routeString) return [];
            
            var allCoords = [];
            // Handle multi-line route strings (from expanded playbooks - each line is a separate route)
            var lines = routeString.split(/[\n\r]+/);
            
            lines.forEach(function(line) {
                var lineCoords = [];
                var lineTrimmed = line.trim();
                if (!lineTrimmed) return;
                
                // Strip color suffix if present
                var semiIdx = lineTrimmed.indexOf(';');
                if (semiIdx !== -1) {
                    lineTrimmed = lineTrimmed.substring(0, semiIdx).trim();
                }
                
                // Strip mandatory markers
                lineTrimmed = lineTrimmed.replace(/[<>]/g, '').toUpperCase();
                
                // Expand airways using ConvertRoute (same as main route plotter)
                var expanded = lineTrimmed;
                if (typeof ConvertRoute === 'function') {
                    try {
                        expanded = ConvertRoute(lineTrimmed);
                    } catch (e) {
                        console.warn('[PublicRoutes] ConvertRoute failed:', e);
                    }
                }
                
                var tokens = expanded.split(/\s+/).filter(function(t) { return t; });
                
                // First pass: collect all resolvable tokens (for context)
                var resolvedPoints = [];
                tokens.forEach(function(token, idx) {
                    // Skip airways (shouldn't be any after ConvertRoute, but just in case)
                    if (/^[JQTV]\d+$/i.test(token)) return;
                    
                    // Skip pure numbers (altitudes, speeds)
                    if (/^\d+$/.test(token)) return;
                    
                    // Skip speed/altitude restrictions like N0450, F350
                    if (/^[NMFSA]\d{3,4}$/.test(token)) return;
                    
                    // Handle DP/STAR notation (e.g., JFK.ROBUC3, THHMP.CAVLR6)
                    var cleanToken = token;
                    if (token.includes('.')) {
                        var dotParts = token.split('.');
                        // Try both parts - the fix name is usually one of them
                        // For DPs like THHMP.CAVLR6, THHMP is the fix
                        // For STARs like JFK.ROBUC3, JFK is the fix
                        cleanToken = dotParts[0]; // Try first part
                    }
                    
                    resolvedPoints.push({
                        token: cleanToken,
                        index: idx,
                        point: null
                    });
                });
                
                // Second pass: resolve points with context
                resolvedPoints.forEach(function(item, i) {
                    var prevPoint = (i > 0 && resolvedPoints[i-1].point) ? resolvedPoints[i-1].point : null;
                    var nextPoint = null;
                    
                    // Look ahead for next resolvable point (for context)
                    for (var j = i + 1; j < resolvedPoints.length && j < i + 3; j++) {
                        var lookAheadToken = resolvedPoints[j].token;
                        var testPoint = getPointByName(lookAheadToken);
                        if (testPoint && Array.isArray(testPoint) && testPoint.length >= 3) {
                            nextPoint = testPoint;
                            break;
                        }
                    }
                    
                    // Resolve with context
                    var point = getPointByName(item.token, prevPoint, nextPoint);
                    if (point && Array.isArray(point) && point.length >= 3) {
                        item.point = point;
                        lineCoords.push([point[2], point[1]]); // MapLibre uses [lng, lat]
                    }
                });
                
                // Add this line's coords to the overall collection
                if (lineCoords.length >= 2) {
                    allCoords = allCoords.concat(lineCoords);
                }
            });
            
            return allCoords;
        }

        function createRoutePopup(props) {
            var validStart = props.valid_start_utc ? new Date(props.valid_start_utc).toISOString().slice(0, 16).replace('T', ' ') + 'Z' : '--';
            var validEnd = props.valid_end_utc ? new Date(props.valid_end_utc).toISOString().slice(0, 16).replace('T', ' ') + 'Z' : '--';
            
            // Calculate time remaining
            var expText = '--';
            var expColor = '#6c757d';
            if (props.valid_end_utc) {
                var end = new Date(props.valid_end_utc);
                var now = new Date();
                var diffMs = end - now;
                
                if (diffMs <= 0) {
                    expText = 'Expired';
                    expColor = '#dc3545';
                } else {
                    var diffMins = Math.floor(diffMs / 60000);
                    var diffHours = Math.floor(diffMins / 60);
                    var remainingMins = diffMins % 60;
                    
                    if (diffHours >= 24) {
                        var days = Math.floor(diffHours / 24);
                        var hrs = diffHours % 24;
                        expText = days + 'd ' + hrs + 'h';
                        expColor = '#28a745';
                    } else if (diffHours >= 4) {
                        expText = diffHours + 'h ' + remainingMins + 'm';
                        expColor = '#28a745';
                    } else if (diffHours >= 2) {
                        expText = diffHours + 'h ' + remainingMins + 'm';
                        expColor = '#17a2b8';
                    } else if (diffHours >= 1) {
                        expText = diffHours + 'h ' + remainingMins + 'm';
                        expColor = '#ffc107';
                    } else {
                        expText = diffMins + 'm';
                        expColor = '#dc3545';
                    }
                }
            }
            
            return '<div style="font-size: 12px; min-width: 200px;">' +
                '<div style="font-weight: bold; margin-bottom: 6px; display: flex; align-items: center;">' +
                '<span style="display: inline-block; width: 12px; height: 12px; background: ' + (props.color || '#e74c3c') + '; border-radius: 2px; margin-right: 6px;"></span>' +
                (props.name || 'Unnamed Route') +
                '</div>' +
                '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">' +
                '<span><strong>Valid:</strong> ' + validStart + ' - ' + validEnd + '</span>' +
                '</div>' +
                '<div style="display: flex; align-items: center; margin-bottom: 4px;">' +
                '<span style="color: ' + expColor + '; font-weight: bold;"><i class="fas fa-hourglass-half" style="margin-right: 4px;"></i>' + expText + ' remaining</span>' +
                '</div>' +
                (props.constrained_area ? '<div style="margin-bottom: 2px;"><strong>Area:</strong> ' + props.constrained_area + '</div>' : '') +
                (props.reason ? '<div style="margin-bottom: 2px;"><strong>Reason:</strong> ' + props.reason + '</div>' : '') +
                (props.fromFix && props.toFix ? '<div style="margin-top: 6px; padding-top: 4px; border-top: 1px solid #ddd; font-size: 11px;"><strong>Segment:</strong> ' + props.fromFix + ' → ' + props.toFix + (props.distance ? ' (' + props.distance + ' nm)' : '') + '</div>' : '') +
                '</div>';
        }

        function zoomToPublicRoute(route) {
            if (!route) return;
            
            var bounds = new maplibregl.LngLatBounds();
            var hasCoords = false;
            
            // Try to use stored GeoJSON first
            if (route.route_geojson) {
                var geojson = null;
                try {
                    geojson = typeof route.route_geojson === 'string' 
                        ? JSON.parse(route.route_geojson) 
                        : route.route_geojson;
                } catch (e) {}
                
                if (geojson && geojson.features) {
                    geojson.features.forEach(function(feature) {
                        if (feature.geometry && feature.geometry.coordinates) {
                            feature.geometry.coordinates.forEach(function(coord) {
                                bounds.extend(coord);
                                hasCoords = true;
                            });
                        }
                    });
                }
            }
            
            // Fallback to parsing route_string
            if (!hasCoords && route.route_string) {
                var coords = parseRouteToCoords(route.route_string);
                coords.forEach(function(coord) {
                    bounds.extend(coord);
                    hasCoords = true;
                });
            }
            
            if (hasCoords) {
                graphic_map.fitBounds(bounds, { padding: 50 });
            }
        }

        function clearPublicRoutes() {
            if (!graphic_map) return;
            
            if (graphic_map.getSource(sourceName)) {
                graphic_map.getSource(sourceName).setData({
                    type: 'FeatureCollection',
                    features: []
                });
            }
            
            if (graphic_map.getSource(labelSourceName)) {
                graphic_map.getSource(labelSourceName).setData({
                    type: 'FeatureCollection',
                    features: []
                });
            }
        }
    })();

});
