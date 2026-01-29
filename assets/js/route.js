// route.js - merged groups_v4 + updated_v3
/* Integrated by ChatGPT on user request */
// assets/js/route.js

$(document).ready(function() {

    let cached_geojson_linestring = [];
    let cached_geojson_multipoint = [];
    let overlays = [];
    let graphic_map; // Exposed for ADL Live Flights module
    let routesLayerGroup = null; // Layer group for plotted routes (for z-ordering)
    let fixMarkersLayerGroup = null; // Layer group for fix markers (for z-ordering)
    
    let points = {};
    // Derived facility codes from ZZ_* dummy fixes (ARTCCs / TRACONs / FIRs, etc.)
    let facilityCodes = new Set();
    // TRACON / ARTCC centers (for pseudo-airport behavior)
    let areaCenters = {};
    // CDR map: CODE -> full route string
    let cdrMap = {};
    // Playbook routes
    let playbookRoutes = [];
    // OPTIMIZATION: Playbook index by normalized play name for O(1) lookup
    let playbookByPlayName = {};
    // Store last expanded route strings for public routes feature
    let lastExpandedRoutes = [];
    
    // OPTIMIZATION: Airway index for O(1) lookup (built after awys.js loads)
    // awyIndex[AIRWAY_ID] = { fixes: ["FIX1", "FIX2", ...], fixPos: { "FIX1": 0, "FIX2": 1, ... } }
    let awyIndex = {};

    // Departure procedure datasets (DPs)
    let dpByComputerCode = {};
    let dpPatternIndex   = {};
    let dpByLeftCode     = {};
    let dpByRootName     = {};
    let dpRouteBodyIndex = {};
    let dpRouteTransitionIndex = {};
    let dpDataLoaded     = false;
    let dpBodyPointSet  = new Set();
    let dpActiveBodyPoints = new Set();

    // STAR procedure datasets (STARs)
    let starFullRoutesByTransition = {};
    let starFullRoutesByStarCode   = {};
    let starDataLoaded             = false;

    // Cache for warning messages to reduce console spam
    let warnedFixes = new Set();

    // ═══════════════════════════════════════════════════════════════════════════
    // PERFORMANCE OPTIMIZATION: Airway index for O(1) lookups
    // awyIndex["J60"] = { fixes: ["FIX1", "FIX2", ...], fixPosMap: { "FIX1": 0, ... } }
    // ═══════════════════════════════════════════════════════════════════════════
    let awyIndexMap = {};
    let awyIndexBuilt = false;

    function buildAirwayIndex() {
        if (awyIndexBuilt) return;
        if (typeof awys === 'undefined' || !Array.isArray(awys)) {
            console.warn('[PERF] awys array not available for indexing');
            return;
        }
        
        const startTime = performance.now();
        
        for (let i = 0; i < awys.length; i++) {
            const row = awys[i];
            if (!Array.isArray(row) || row.length < 2 || !row[0] || !row[1]) continue;
            
            const airwayId = String(row[0]).toUpperCase();
            const fixString = String(row[1]);
            
            // Pre-parse fixes into array ONCE (not on every lookup)
            const fixes = fixString.split(' ').filter(f => f !== '');
            
            // Build position map for O(1) fix position lookup
            const fixPosMap = {};
            for (let j = 0; j < fixes.length; j++) {
                const fix = fixes[j].toUpperCase();
                // Store first occurrence only
                if (!(fix in fixPosMap)) {
                    fixPosMap[fix] = j;
                }
            }
            
            awyIndexMap[airwayId] = {
                fixes: fixes,
                fixPosMap: fixPosMap
            };
        }
        
        awyIndexBuilt = true;
        console.log('[PERF] Airway index built: ' + Object.keys(awyIndexMap).length + ' airways in ' + (performance.now() - startTime).toFixed(1) + 'ms');
    }

    // Load fixes/points (includes ZZ_* ARTCC/FIR centerpoints)
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
            const lat = parts[1];
            const lon = parts[2];
    
            if (!idRaw) continue;
    
            const id = idRaw.toUpperCase();
    
            // Any ZZ_* entry defines an underlying facility code for lookup
            if (id.startsWith('ZZ_') && id.length > 3) {
                facilityCodes.add(id.substring(3)); // e.g. ZZ_ZMP -> facilityCodes has "ZMP"
            }
    
            if (!points[id]) {
                points[id] = [];
            }
            points[id].push([id, +lat, +lon]);
        }

        // Expose points globally for procs_enhanced.js
        window.routePoints = points;
    });
    
    // Load CDRs
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
            if (code && route) {
                cdrMap[code] = route;
            }
        });
        
        // Expose to global scope for PlaybookCDRSearch module
        window.cdrMap = cdrMap;
        console.log('[PERF] CDR loaded: ' + Object.keys(cdrMap).length + ' routes');
    });
    
    // --- Playbook routes (CSV) ---
    
    function normalizePlayName(name) {
        if (!name) return '';
        return String(name).toUpperCase().replace(/[\s\-_]/g, '');
    }
    
    // Basic CSV parser that understands quotes + embedded newlines
    function parseCsvWithQuotes(text) {
        const rows = [];
        let field = '';
        let row = [];
        let inQuotes = false;
    
        for (let i = 0; i < text.length; i++) {
            const c = text[i];
    
            if (inQuotes) {
                if (c === '"') {
                    if (i + 1 < text.length && text[i + 1] === '"') {
                        // Escaped quote
                        field += '"';
                        i++;
                    } else {
                        inQuotes = false;
                    }
                } else {
                    field += c;
                }
            } else {
                if (c === '"') {
                    inQuotes = true;
                } else if (c === ',') {
                    row.push(field);
                    field = '';
                } else if (c === '\r') {
                    // ignore
                } else if (c === '\n') {
                    row.push(field);
                    field = '';
                    if (row.some(v => v !== '')) {
                        rows.push(row);
                    }
                    row = [];
                } else {
                    field += c;
                }
            }
        }
    
        if (field !== '' || row.length) {
            row.push(field);
            if (row.some(v => v !== '')) {
                rows.push(row);
            }
        }
    
        return rows;
    }
    
    function loadPlaybookRoutes() {
        $.ajax({
            type: 'GET',
            url: 'assets/data/playbook_routes.csv',
            async: false
        }).done(function(data) {
            const startTime = performance.now();
            const rows = parseCsvWithQuotes(data);
            if (!rows || !rows.length) {
                return;
            }
    
            const header = rows[0].map(h => (h || '').trim());
            const lower  = header.map(h => h.toLowerCase());
    
            function idxOf() {
                for (let n = 0; n < arguments.length; n++) {
                    const name = arguments[n];
                    if (!name) continue;
                    const i = lower.indexOf(String(name).toLowerCase());
                    if (i !== -1) return i;
                }
                return -1;
            }
    
            // Required
            const idxPlay  = idxOf('play_name', 'play');
            const idxRoute = idxOf('full_route', 'route string', 'route', 'route_string');
    
            // Optional / new ones
            const idxOrigAirports = idxOf('origins', 'origin', 'origin_airports', 'origin_bases');
            const idxOrigTracons  = idxOf('origin_tracons', 'origin_tracon');
            const idxOrigArtccs   = idxOf('origin_artccs', 'origin_artcc');
    
            const idxDestAirports = idxOf('destinations', 'dest', 'dest_airports', 'dest_bases');
            const idxDestTracons  = idxOf('dest_tracons', 'dest_tracon');
            const idxDestArtccs   = idxOf('dest_artccs', 'dest_artcc');
    
            if (idxPlay === -1 || idxRoute === -1) {
                console.warn('playbook_routes.csv: missing required columns (Play / Route String)');
                return;
            }
    
            function splitField(str) {
                if (!str) return [];
                return String(str).toUpperCase().split(/\s+/).filter(Boolean);
            }
    
            for (let r = 1; r < rows.length; r++) {
                const cols = rows[r];
                if (!cols || !cols.length) continue;
    
                const playNameRaw = (cols[idxPlay]  || '').trim();
                const routeRaw    = (cols[idxRoute] || '').trim();
    
                // Skip blank or non-plays
                if (!playNameRaw || !routeRaw || playNameRaw.toLowerCase() === 'nan') {
                    continue;
                }
    
                const origAirportsStr = (idxOrigAirports >= 0 && idxOrigAirports < cols.length ? (cols[idxOrigAirports] || '') : '').trim();
                const origTraconsStr  = (idxOrigTracons  >= 0 && idxOrigTracons  < cols.length ? (cols[idxOrigTracons]  || '') : '').trim();
                const origArtccsStr   = (idxOrigArtccs   >= 0 && idxOrigArtccs   < cols.length ? (cols[idxOrigArtccs]   || '') : '').trim();
    
                const destAirportsStr = (idxDestAirports >= 0 && idxDestAirports < cols.length ? (cols[idxDestAirports] || '') : '').trim();
                const destTraconsStr  = (idxDestTracons  >= 0 && idxDestTracons  < cols.length ? (cols[idxDestTracons]  || '') : '').trim();
                const destArtccsStr   = (idxDestArtccs   >= 0 && idxDestArtccs   < cols.length ? (cols[idxDestArtccs]   || '') : '').trim();
    
                const originAirports = splitField(origAirportsStr);
                const originTracons  = splitField(origTraconsStr);
                const originArtccs   = splitField(origArtccsStr);
    
                const destAirports   = splitField(destAirportsStr);
                const destTracons    = splitField(destTraconsStr);
                const destArtccs     = splitField(destArtccsStr);
    
                // OPTIMIZATION: Use Sets for O(1) membership testing
                const routeEntry = {
                    playName: playNameRaw,
                    playNameNorm: normalizePlayName(playNameRaw),
                    // used later when building the actual route string
                    fullRoute: routeRaw.toUpperCase(),

                    // Use Sets for O(1) lookup instead of arrays with indexOf
                    originAirportsSet: new Set(originAirports),
                    originTraconsSet:  new Set(originTracons),
                    originArtccsSet:   new Set(originArtccs),
                    destAirportsSet:   new Set(destAirports),
                    destTraconsSet:    new Set(destTracons),
                    destArtccsSet:     new Set(destArtccs),
                    
                    // Keep arrays for backwards compatibility if needed
                    originAirports: originAirports,
                    originTracons:  originTracons,
                    originArtccs:   originArtccs,
                    destAirports:   destAirports,
                    destTracons:    destTracons,
                    destArtccs:     destArtccs
                };
                
                playbookRoutes.push(routeEntry);
                
                // OPTIMIZATION: Build index by normalized play name for O(1) lookup
                const pnorm = routeEntry.playNameNorm;
                if (!playbookByPlayName[pnorm]) {
                    playbookByPlayName[pnorm] = [];
                }
                playbookByPlayName[pnorm].push(routeEntry);
            }
            
            console.log('[PERF] Playbook loaded: ' + playbookRoutes.length + ' routes, ' + Object.keys(playbookByPlayName).length + ' plays in ' + (performance.now() - startTime).toFixed(1) + 'ms');
            
            // Expose to global scope for PlaybookCDRSearch module
            window.playbookRoutes = playbookRoutes;
            window.playbookByPlayName = playbookByPlayName;
        });
    }
    
    // Normalize a PB origin/destination token for *airport* matching only.
    // We do NOT want to turn TRACON/ARTCC codes into KXXX.
    function normalizePlayEndpointTokenForAirport(token) {
        if (!token) return null;
        let t = String(token).toUpperCase().trim();
        if (!t) return null;
    
        // 4-char: assume ICAO (e.g. KJFK, CYYZ, EGLL)
        if (/^[A-Z0-9]{4}$/.test(t)) {
            return t;
        }
    
        // ARTCC style (ZDC, ZNY, etc) – do NOT add 'K'
        if (/^Z[A-Z0-9]{2}$/.test(t)) {
            return t;
        }
    
        // TRACON/facility codes like N90, A80, L30 – don't touch them
        if (/^[A-Z]\d{2}$/.test(t)) {
            return t;
        }
    
        // 3-letter non-Z tokens: treat as IATA and map to ICAO (BWI → KBWI)
        if (/^[A-Z]{3}$/.test(t) && !t.startsWith('Z')) {
            return 'K' + t;
        }
    
        // Fallback: leave it alone
        return t;
    }

    /**
     * Expand a PB directive body (everything after "PB.")
     * bodyUpper examples:
     *   "ABI"
     *   "ABI.KBWI"
     *   "ABI.KBWI KSLC"
     *   "ABI..KSFO"
     *   "ABI.ZDC" (origin ARTCC)
     *   "ABI.ZDC.ZLA" (origin ARTCC → dest ARTCC)
     *
     * Rules:
     *   PB.{play}                         -> all routes for that play
     *   PB.{play}.{origin}                -> all routes from that origin
     *   PB.{play}.{origins}               -> all routes from any of those origins
     *   PB.{play}..{destination}          -> all routes to that destination
     *   PB.{play}..{destinations}         -> all routes to any of those destinations
     *   PB.{play}.{origin}.{destination}  -> from that origin to that destination
     *   PB.{play}.{origin}.{destinations} -> from that origin to any of those destinations
     *   PB.{play}.{origins}.{destination} -> from any of those origins to that destination
     *   PB.{play}.{origins}.{destinations}-> from any of those origins to any of those destinations
     *
     * Origins/Destinations:
     *   - Airport tokens use Origins / Destinations
     *   - TRACON/ARTCC tokens use Origin_TRACONs / Origin_ARTCCs, Dest_TRACONs / Dest_ARTCCs
     */
    function expandPlaybookDirective(bodyUpper, isMandatory, color) {
        if (!bodyUpper) return [];
    
        const parts    = bodyUpper.split('.');
        const playPart = (parts[0] || '').trim();
        if (!playPart) return [];
    
        const playNorm   = normalizePlayName(playPart);
        const originPart = (parts.length > 1 ? parts[1] : '').trim();
        const destPart   = (parts.length > 2 ? parts[2] : '').trim();
    
        const originTokens = originPart ? originPart.toUpperCase().split(/\s+/).filter(Boolean) : [];
        const destTokens   = destPart   ? destPart.toUpperCase().split(/\s+/).filter(Boolean)   : [];
    
        const out = [];
    
        // OPTIMIZATION: Use indexed lookup instead of scanning all 77K routes
        const candidateRoutes = playbookByPlayName[playNorm];
        if (!candidateRoutes || !candidateRoutes.length) {
            return out;
        }

        for (let idx = 0; idx < candidateRoutes.length; idx++) {
            const pr = candidateRoutes[idx];
    
            // --- Origin filter (OPTIMIZED: using Set.has() for O(1)) ---
            if (originTokens.length) {
                let originMatch = false;
    
                for (let i = 0; i < originTokens.length && !originMatch; i++) {
                    const tok        = originTokens[i];
                    const tokAirport = normalizePlayEndpointTokenForAirport(tok);
    
                    // OPTIMIZATION: Use Set.has() for O(1) lookup
                    if (pr.originAirportsSet && pr.originAirportsSet.has(tokAirport)) {
                        originMatch = true;
                    } else if (pr.originTraconsSet && pr.originTraconsSet.has(tok)) {
                        originMatch = true;
                    } else if (pr.originArtccsSet && pr.originArtccsSet.has(tok)) {
                        originMatch = true;
                    }
                }
    
                if (!originMatch) continue;
            }
    
            // --- Destination filter (OPTIMIZED: using Set.has() for O(1)) ---
            if (destTokens.length) {
                let destMatch = false;
    
                for (let i = 0; i < destTokens.length && !destMatch; i++) {
                    const tok        = destTokens[i];
                    const tokAirport = normalizePlayEndpointTokenForAirport(tok);
    
                    // OPTIMIZATION: Use Set.has() for O(1) lookup
                    if (pr.destAirportsSet && pr.destAirportsSet.has(tokAirport)) {
                        destMatch = true;
                    } else if (pr.destTraconsSet && pr.destTraconsSet.has(tok)) {
                        destMatch = true;
                    } else if (pr.destArtccsSet && pr.destArtccsSet.has(tok)) {
                        destMatch = true;
                    }
                }
    
                if (!destMatch) continue;
            }
    
            // --- Build route string ----------------------------------------------
            let routeText = pr.fullRoute;
    
            // If the PB directive is wrapped with ><, mark the route segment as mandatory:
            // we put ">" on first interior token, "<" on last interior token.
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
    
            if (color) {
                out.push(routeText + ';' + color);
            } else {
                out.push(routeText);
            }
        }
    
        return out;
    }


    // --- Departure procedure (DP) handling -----------------------------------

    function dpParseEffDate(str) {
        if (!str) return 0;
        const cleaned = String(str).replace(/[^\d]/g, '');
        if (!cleaned) return 0;
        const n = parseInt(cleaned, 10);
        return isNaN(n) ? 0 : n;
    }

    function buildDpPattern(computerCode) {
        if (!computerCode) return null;
        const code = String(computerCode).toUpperCase();
        const parts = code.split('.');
        if (parts.length !== 2) return null;
        const left  = parts[0];
        const right = parts[1];
        const leftLetters = left.replace(/[0-9]/g, '');
        if (!leftLetters) return null;
        return leftLetters + '#.' + right;
    }

    function loadDepartureProcedures() {
        $.ajax({
            type: 'GET',
            url: 'assets/data/DP_BASE.csv',
            async: false
        }).done(function(data) {
            const rows = parseCsvWithQuotes(data);
            if (!rows || rows.length < 2) {
                return;
            }

            const header = rows[0].map(h => (h || '').toString().replace(/"/g, '').trim().toUpperCase());

            const idxEffDate      = header.indexOf('EFF_DATE');
            const idxDpName       = header.indexOf('DP_NAME');
            const idxComputerCode = header.indexOf('DP_COMPUTER_CODE');
            const idxServedArpt   = header.indexOf('SERVED_ARPT');

            if (idxComputerCode === -1 || idxServedArpt === -1) {
                console.warn('DP_BASE.csv missing required columns DP_COMPUTER_CODE / SERVED_ARPT');
                return;
            }

            for (let r = 1; r < rows.length; r++) {
                const cols = rows[r];
                if (!cols || !cols.length) continue;

                const codeRaw = (cols[idxComputerCode] || '').toString().replace(/"/g, '').trim();
                if (!codeRaw) continue;
                const code = codeRaw.toUpperCase();

                const effDate = idxEffDate !== -1 ? dpParseEffDate(cols[idxEffDate]) : 0;
                const name    = idxDpName !== -1 ? (cols[idxDpName] || '').toString().replace(/"/g, '').trim().toUpperCase() : '';

                const servedStrRaw = (cols[idxServedArpt] || '').toString();
                const servedList = servedStrRaw.replace(/"/g, '').toUpperCase().split(/\s+/).filter(Boolean);

                const recObj = {
                    code: code,
                    name: name,
                    servedAirports: servedList,
                    effDate: effDate
                };

                dpByComputerCode[code] = recObj;

                const partsCode = code.split('.');
                if (partsCode.length === 2) {
                    const leftPart = partsCode[0];
                    const existingLeft = dpByLeftCode[leftPart];
                    if (!existingLeft || effDate > existingLeft.effDate) {
                        dpByLeftCode[leftPart] = recObj;
                    }

                    const rootLetters = leftPart.replace(/[0-9]/g, '');
                    if (rootLetters) {
                        const existingRoot = dpByRootName[rootLetters];
                        if (!existingRoot || effDate > existingRoot.effDate) {
                            dpByRootName[rootLetters] = recObj;
                        }
                    }
                }

                const pattern = buildDpPattern(code);
                if (pattern) {
                    const existing = dpPatternIndex[pattern];
                    if (!existing || effDate > existing.effDate) {
                        dpPatternIndex[pattern] = {
                            code: code,
                            effDate: effDate
                        };
                    }
                }
            }

            loadDpRouteBodies();
            dpDataLoaded = true;
        }).fail(function() {
            console.warn('DP_BASE.csv not found or failed to load; DP handling disabled.');
        });
    }

    

    function loadDpRouteBodies() {
        // Load DP_RTE.csv and build a lightweight index of BODY points by DP_COMPUTER_CODE
        $.ajax({
            type: 'GET',
            url: 'assets/data/DP_RTE.csv',
            async: false
        }).done(function(data) {
            const rows = parseCsvWithQuotes(data);
            if (!rows || rows.length < 2) {
                return;
            }

            const header = rows[0].map(h => (h || '').toString().replace(/"/g, '').trim().toUpperCase());

            const idxComputerCode = header.indexOf('DP_COMPUTER_CODE');
            const idxPortionType  = header.indexOf('ROUTE_PORTION_TYPE');
            const idxPointSeq     = header.indexOf('POINT_SEQ');
            const idxPoint        = header.indexOf('POINT');
            const idxArptRwy      = header.indexOf('ARPT_RWY_ASSOC');

            if (idxComputerCode < 0 || idxPortionType < 0 || idxPointSeq < 0 || idxPoint < 0) {
                return;
            }

            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                if (!row || row.length <= idxComputerCode) continue;

                const codeRaw  = row[idxComputerCode];
                const typeRaw  = row[idxPortionType];
                const pointRaw = row[idxPoint];
                const seqRaw   = row[idxPointSeq];

                if (!codeRaw || !typeRaw || !pointRaw || !seqRaw) {
                    continue;
                }

                const code = codeRaw.toString().replace(/"/g, '').trim().toUpperCase();
                if (!code) continue;

                // Only keep DPs that we know about from DP_BASE
                if (!dpByComputerCode[code]) continue;

                const portionType = typeRaw.toString().replace(/"/g, '').trim().toUpperCase();

                const point = pointRaw.toString().replace(/"/g, '').trim().toUpperCase();
                if (!point) continue;

                const seq = parseInt(seqRaw.toString().replace(/"/g, '').trim(), 10);
                if (!Number.isFinite(seq)) continue;

                let arptRwy = '';
                if (idxArptRwy >= 0 && row.length > idxArptRwy && row[idxArptRwy] != null) {
                    arptRwy = row[idxArptRwy].toString().replace(/"/g, '').trim().toUpperCase();
                }

                if (portionType === 'BODY') {
                    if (!dpRouteBodyIndex[code]) {
                        dpRouteBodyIndex[code] = [];
                    }

                    dpRouteBodyIndex[code].push({
                        point: point,
                        pointSeq: seq,
                        arptRwyAssoc: arptRwy
                    });
                    dpBodyPointSet.add(point);
                } else if (portionType === 'TRANSITION') {
                    if (!dpRouteTransitionIndex[code]) {
                        dpRouteTransitionIndex[code] = [];
                    }

                    dpRouteTransitionIndex[code].push({
                        point: point,
                        pointSeq: seq,
                        arptRwyAssoc: arptRwy
                    });
                }
            }
        }).fail(function() {
            console.warn('DP_RTE.csv not found or failed to load; DP path expansion disabled.');
        });
    }

    function dpRowMatchesOrigin(arptRwyAssoc, originCodeUpper) {
        if (!arptRwyAssoc) return false;

        const origin = (originCodeUpper || '').toString().toUpperCase();
        if (!origin) return false;

        const candidates = [];
        candidates.push(origin);
        if (origin.length === 4 && origin.charAt(0) === 'K') {
            candidates.push(origin.substring(1));
        }
        if (origin.length === 3) {
            candidates.push('K' + origin);
        }

        for (let i = 0; i < candidates.length; i++) {
            const base = candidates[i];
            if (!base) continue;
            const needle = base + '/';
            if (arptRwyAssoc.indexOf(needle) !== -1) {
                return true;
            }
        }

        return false;
    }

    function getDpBodySequence(dpCode, originCode) {
        if (!dpRouteBodyIndex || !dpCode) return null;

        const code        = dpCode.toString().toUpperCase();
        const originUpper = (originCode || '').toString().toUpperCase();

        const allRows = dpRouteBodyIndex[code];
        if (!allRows || !allRows.length) {
            return null;
        }

        // Try to filter rows that explicitly reference the origin airport/runway;
        // if none match, fall back to "all rows" for this DP.
        let filtered = allRows.filter(r => dpRowMatchesOrigin(r.arptRwyAssoc, originUpper));
        if (!filtered.length) {
            filtered = allRows.slice();
        }

        if (!filtered.length) return null;

        filtered.sort(function(a, b) {
            return a.pointSeq - b.pointSeq;
        });

        const seq = [];
        const seen = {};
        for (let i = 0; i < filtered.length; i++) {
            const p = filtered[i].point;
            if (!p) continue;
            if (!seen[p]) {
                seen[p] = true;
                seq.push(p);
            }
        }

        if (!seq.length) return null;

        // Determine the "root fix" from the DP_COMPUTER_CODE (e.g. REVSS6.REVSS -> REVSS)
        const parts = code.split('.');
        let rootFix = parts.length > 1 ? parts[1] : parts[0];
        rootFix     = (rootFix || '').toUpperCase();

        const forward  = seq.slice();
        const reversed = seq.slice().reverse();

        const idxForward  = forward.lastIndexOf(rootFix);
        const idxReversed = reversed.lastIndexOf(rootFix);

        let chosen = forward;

        if (idxForward === forward.length - 1) {
            chosen = forward;
        } else if (idxReversed === reversed.length - 1) {
            chosen = reversed;
        } else if (idxForward !== -1 || idxReversed !== -1) {
            const distForward  = idxForward  === -1 ? forward.length  : (forward.length  - 1 - idxForward);
            const distReversed = idxReversed === -1 ? reversed.length : (reversed.length - 1 - idxReversed);
            chosen = distReversed < distForward ? reversed : forward;
        }

        return chosen;
    }

    function getDpTransitionSequence(dpTransCode, originCode) {
        if (!dpRouteTransitionIndex || !dpTransCode) return null;

        const code        = dpTransCode.toString().toUpperCase();
        const originUpper = (originCode || '').toString().toUpperCase();

        const allRows = dpRouteTransitionIndex[code];
        if (!allRows || !allRows.length) {
            return null;
        }

        // Try to filter rows that explicitly reference the origin airport/runway;
        // if none match, fall back to "all rows" for this DP transition.
        let filtered = allRows.filter(r => dpRowMatchesOrigin(r.arptRwyAssoc, originUpper));
        if (!filtered.length) {
            filtered = allRows.slice();
        }
        if (!filtered.length) return null;

        filtered.sort(function(a, b) {
            return a.pointSeq - b.pointSeq;
        });

        const seq = [];
        const seen = {};
        for (let i = 0; i < filtered.length; i++) {
            const p = filtered[i].point;
            if (!p) continue;
            if (!seen[p]) {
                seen[p] = true;
                seq.push(p);
            }
        }

        return seq.length ? seq : null;
    }


    
    function findRouteOriginForDp(tokens) {
        if (!tokens || !tokens.length) return null;

        // Try to find the first token that looks like an origin airport/TRACON/ARTCC
        for (let i = 0; i < tokens.length; i++) {
            let t = (tokens[i] || '').toString().trim();
            if (!t) continue;

            // Strip common wrapper characters (>, <, [, ], group markers, etc.)
            t = t.replace(/^[>\[]+/, '').replace(/[<\];]+$/, '');
            if (!t) continue;

            const cand = t.toUpperCase();

            // If this candidate exists in the points table (airports, fixes, ZZ_ centers, etc.),
            // use it as the origin for DP matching.
            if (points[cand]) {
                return cand;
            }
        }
        return null;
    }

function expandDepartureProcedures(tokens, solidMask) {
        dpActiveBodyPoints = new Set();

        if (!dpDataLoaded || !tokens || !tokens.length) {
            return {
                tokens: tokens || [],
                solidMask: solidMask || []
            };
        }

        const origin = findRouteOriginForDp(tokens);
        if (!origin) {
            return {
                tokens: tokens,
                solidMask: solidMask
            };
        }

        const outTokens = [];
        const outSolid  = [];

        for (let i = 0; i < tokens.length; i++) {
            const rawTok = (tokens[i] || '').toString().trim();
            if (!rawTok) continue;

            const upperTok = rawTok.toUpperCase();
            const dpRec    = getDpForToken(upperTok, origin);

            
if (dpRec) {
                const dpCode  = dpRec.code;
                const bodySeq = getDpBodySequence(dpCode, origin);
                let fullDpSeq = [];
                if (bodySeq && bodySeq.length) {
                    fullDpSeq = bodySeq.slice();
                }

                let consumedNextAsTransition = false;

                // Try to append a transition (e.g. DEEZZ5 + TOWIN -> DEEZZ5.TOWIN)
                if (i + 1 < tokens.length) {
                    const nextTokUpper = (tokens[i + 1] || '').toString().toUpperCase();

                    const codeParts = dpCode.split('.');
                    const leftPart  = codeParts[0] || '';
                    const transCode = (leftPart ? leftPart + '.' : '') + nextTokUpper;

                    const transSeqRaw = getDpTransitionSequence(transCode, origin);

                    if (transSeqRaw && transSeqRaw.length) {
                        const bodyLast = fullDpSeq.length ? fullDpSeq[fullDpSeq.length - 1] : null;
                        const forward  = transSeqRaw.slice();
                        const reversed = transSeqRaw.slice().reverse();
                        let chosen = forward;

                        if (bodyLast) {
                            if (forward.length && forward[0] === bodyLast) {
                                chosen = forward;
                            } else if (reversed.length && reversed[0] === bodyLast) {
                                chosen = reversed;
                            }
                        }

                        if (bodyLast && chosen.length && chosen[0] === bodyLast) {
                            chosen = chosen.slice(1);
                        }

                        fullDpSeq = fullDpSeq.concat(chosen);
                        consumedNextAsTransition = true;
                    }
                }

                if (fullDpSeq.length) {
                    for (let j = 0; j < fullDpSeq.length; j++) {
                        const p = fullDpSeq[j];
                        if (p) {
                            dpActiveBodyPoints.add(p.toUpperCase());
                        }
                    }
                    for (let j = 0; j < fullDpSeq.length; j++) {
                        outTokens.push(fullDpSeq[j].toUpperCase());
                        outSolid.push(solidMask[i]);
                    }
                } else {
                    outTokens.push(dpCode.toUpperCase());
                    outSolid.push(solidMask[i]);
                }

                if (consumedNextAsTransition) {
                    i++;
                }

            } else {
                outTokens.push(upperTok);
                outSolid.push(solidMask[i]);
            }
        }

        return {
            tokens: outTokens,
            solidMask: outSolid
        };
    }

function findRouteOriginForDp(tokens) {
        if (!tokens || !tokens.length) return null;

        for (let i = 0; i < tokens.length; i++) {
            const rawTok = (tokens[i] || '').toString().trim();
            if (!rawTok) continue;

            const code = rawTok.toUpperCase();

            // Ignore pseudo-airports / facility centers
            if (code.startsWith('ZZ_')) continue;
            if (facilityCodes.has(code)) continue;

            // Treat 3-4 character codes as candidate airports
            if (code.length === 3 || code.length === 4) {
                return code;
            }
        }

        return null;
    }

    

    function originMatchesServed(originUpper, servedAirports) {
        if (!servedAirports || !servedAirports.length) return false;
        const origin = (originUpper || '').toString().toUpperCase();
        if (!origin) return false;

        const candidates = [];
        candidates.push(origin);
        if (origin.length === 4 && origin.charAt(0) === 'K') {
            candidates.push(origin.substring(1));
        }
        if (origin.length === 3) {
            candidates.push('K' + origin);
        }

        for (let i = 0; i < candidates.length; i++) {
            const cand = candidates[i];
            if (!cand) continue;
            if (servedAirports.indexOf(cand) !== -1) {
                return true;
            }
        }
        return false;
    }


    // === vATCSCC: STAR handling using star_full_routes.csv ===
    // New full-route indexes from star_full_routes.csv
    function loadStarProcedures() {
        $.ajax({
            type: 'GET',
            url: 'assets/data/star_full_routes.csv',
            async: false
        }).done(function(data) {
            const rows = parseCsvWithQuotes(data);
            if (!rows || rows.length < 2) {
                starDataLoaded = false;
                return;
            }

            const header = rows[0].map(h => (h || '').toString().replace(/"/g, '').trim().toUpperCase());

            const idxEffDate      = header.indexOf('EFF_DATE');
            const idxArrivalName  = header.indexOf('ARRIVAL_NAME');
            const idxStarCode     = header.indexOf('STAR_COMPUTER_CODE');
            const idxDestGroup    = header.indexOf('DEST_GROUP');
            const idxTransCode    = header.indexOf('TRANSITION_COMPUTER_CODE');
            const idxRoutePoints  = header.indexOf('ROUTE_POINTS');

            if (idxStarCode < 0 || idxTransCode < 0 || idxDestGroup < 0 || idxRoutePoints < 0) {
                console.warn('star_full_routes.csv missing required columns; STAR handling disabled.');
                starDataLoaded = false;
                return;
            }

            // Reset STAR structures
            starFullRoutesByTransition = {};
            starFullRoutesByStarCode   = {};

            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                if (!row || !row.length) continue;

                const starCodeRaw = (row[idxStarCode] || '').toString().replace(/"/g, '').trim();
                const transCodeRaw = (row[idxTransCode] || '').toString().replace(/"/g, '').trim();
                const destGroupRaw = (row[idxDestGroup] || '').toString().replace(/"/g, '').trim();
                const routePtsRaw  = (row[idxRoutePoints] || '').toString().replace(/"/g, '').trim().toUpperCase();

                if (!transCodeRaw || !routePtsRaw) continue;

                const starCode  = starCodeRaw.toUpperCase();
                const transCode = transCodeRaw.toUpperCase();
                const destGroup = destGroupRaw.toUpperCase();
                const routePoints = routePtsRaw ? routePtsRaw.split(/\s+/).filter(Boolean) : [];

                const effDate = idxEffDate !== -1 ? dpParseEffDate(row[idxEffDate]) : 0;
                const arrivalName = idxArrivalName !== -1
                    ? (row[idxArrivalName] || '').toString().replace(/"/g, '').trim().toUpperCase()
                    : '';

                const rec = {
                    effDate: effDate,
                    starCode: starCode,
                    transitionCode: transCode,
                    destGroup: destGroup,
                    arrivalName: arrivalName,
                    routePoints: routePoints
                };

                if (transCode) {
                    if (!starFullRoutesByTransition[transCode]) {
                        starFullRoutesByTransition[transCode] = [];
                    }
                    starFullRoutesByTransition[transCode].push(rec);
                }

                if (starCode) {
                    if (!starFullRoutesByStarCode[starCode]) {
                        starFullRoutesByStarCode[starCode] = [];
                    }
                    starFullRoutesByStarCode[starCode].push(rec);
                }
            }

            starDataLoaded = true;
        }).fail(function() {
            console.warn('star_full_routes.csv not found or failed to load; STAR path expansion disabled.');
            starDataLoaded = false;
        });
    }

    function expandStarProcedures(tokens, solidMask) {
        // Lazy-load STAR data if it has not been loaded yet
        if (!starDataLoaded && typeof loadStarProcedures === 'function') {
            try {
                loadStarProcedures();
            } catch (e) {
                console.warn('loadStarProcedures() threw an error:', e);
            }
        }

        if (!starDataLoaded) {
            return {
                tokens: tokens || [],
                solidMask: solidMask || []
            };
        }

        if (!tokens || !tokens.length) {
            return {
                tokens: tokens || [],
                solidMask: solidMask || []
            };
        }

        const inSolid = (solidMask && solidMask.length === tokens.length)
            ? solidMask
            : new Array(tokens.length).fill(true);

        // Determine destination token (last non-empty token)
        let destToken = null;
        for (let i = tokens.length - 1; i >= 0; i--) {
            const t = (tokens[i] || '').toString().trim();
            if (!t) continue;
            destToken = t.toUpperCase();
            break;
        }

        function matchesDest(destGroup, destTok) {
            if (!destGroup || !destTok) return true;
            const group = destGroup.toString().toUpperCase();
            const parts = group.split(/\s+/);
            for (let p of parts) {
                p = (p || '').trim();
                if (!p) continue;
                const apt = p.split('/')[0]; // handle KDEN/25|26 style strings
                if (apt === destTok) {
                    return true;
                }
            }
            return false;
        }

        const outTokens = [];
        const outSolid  = [];

        for (let i = 0; i < tokens.length; i++) {
            const tok = tokens[i];
            const upperTok = (tok || '').toString().toUpperCase();
            if (!upperTok) {
                outTokens.push(tok);
                outSolid.push(inSolid[i]);
                continue;
            }

            let candidates = [];
            const byTrans = starFullRoutesByTransition[upperTok];
            const byStar  = starFullRoutesByStarCode[upperTok];

            if (byTrans && byTrans.length) {
                candidates = candidates.concat(byTrans);
            }
            if (byStar && byStar.length) {
                candidates = candidates.concat(byStar);
            }

            let chosen = null;
            if (candidates.length) {
                let filtered = destToken
                    ? candidates.filter(rec => matchesDest(rec.destGroup, destToken))
                    : candidates.slice();

                if (!filtered.length) {
                    filtered = candidates.slice();
                }

                filtered.sort(function(a, b) {
                    return (a.effDate || 0) - (b.effDate || 0);
                });
                chosen = filtered[filtered.length - 1];
            }

            if (chosen && chosen.routePoints && chosen.routePoints.length) {
                const seq = chosen.routePoints;
                for (let j = 0; j < seq.length; j++) {
                    const p = seq[j];
                    if (!p) continue;
                    outTokens.push(p.toUpperCase());
                    outSolid.push(inSolid[i]);
                }
                continue; // consume this STAR token
            }

            outTokens.push(upperTok);
            outSolid.push(inSolid[i]);
        }

        return {
            tokens: outTokens,
            solidMask: outSolid
        };
    }
function getDpForToken(token, originCode) {
        if (!dpDataLoaded) return null;
        if (!token || !originCode) return null;

        const tokUpper    = token.toString().toUpperCase();
        const originUpper = originCode.toString().toUpperCase();

        // 1) Exact DP_COMPUTER_CODE match
        let rec = dpByComputerCode[tokUpper];
        if (rec && originMatchesServed(originUpper, rec.servedAirports)) {
            return rec;
        }

        const parts = tokUpper.split('.');

        // 2) Tokens that include a '.' (e.g. REVSS6.REVSS, CLVIN1.STAZE)
        if (parts.length === 2) {
            const left  = parts[0];
            const right = parts[1];

            // 2a) Pattern with explicit transition, e.g. SKORR#.SKORR
            const leftLetters = left.replace(/[#0-9]/g, '');
            if (leftLetters) {
                const pattern = leftLetters + '#.' + right;
                const patternInfo = dpPatternIndex[pattern];
                if (patternInfo) {
                    const rec2 = dpByComputerCode[patternInfo.code];
                    if (rec2 && originMatchesServed(originUpper, rec2.servedAirports)) {
                        return rec2;
                    }
                }
            }

            // 2b) If that fails, fall back to root-name only (handles wrong number / wrong transition)
            const rootOnly = left.replace(/[#0-9]/g, '');
            if (rootOnly && dpByRootName[rootOnly]) {
                const rec3 = dpByRootName[rootOnly];
                if (rec3 && originMatchesServed(originUpper, rec3.servedAirports)) {
                    return rec3;
                }
            }
        }

        // 3) Tokens like BANNG3, CLTCH3, SKORR5 with no explicit "."
        const recLeft = dpByLeftCode[tokUpper];
        if (recLeft && originMatchesServed(originUpper, recLeft.servedAirports)) {
            return recLeft;
        }

        // 3b) Legacy-numbered tokens like CLTCH2, NTHNS8, etc.
        // If we see digits but no direct match, strip digits and use the
        // "current" DP for that root (latest effDate in DP_BASE) for this origin.
        if (/[0-9]/.test(tokUpper)) {
            const rootLetters = tokUpper.replace(/[0-9]/g, '');
            if (rootLetters && dpByRootName[rootLetters]) {
                const recRootNum = dpByRootName[rootLetters];
                if (recRootNum && originMatchesServed(originUpper, recRootNum.servedAirports)) {
                    return recRootNum;
                }
            }
        }

        // 4) Tokens like CLTCH# (generic "#")
        if (tokUpper.indexOf('#') !== -1) {
            const rootLetters = tokUpper.replace(/[#0-9]/g, '');
            if (rootLetters && dpByRootName[rootLetters]) {
                const recRoot = dpByRootName[rootLetters];
                if (recRoot && originMatchesServed(originUpper, recRoot.servedAirports)) {
                    return recRoot;
                }
            }
        }

        return null;
    }

function applyDepartureProcedures(tokens) {
        if (!dpDataLoaded) return tokens;
        if (!tokens || !tokens.length) return tokens;

        const origin = findRouteOriginForDp(tokens);
        if (!origin) return tokens;

        const out = tokens.slice();

        for (let i = 0; i < out.length; i++) {
            const tok = out[i];
            const dpRec = getDpForToken(tok, origin);
            if (dpRec) {
                out[i] = dpRec.code; // normalize to current DP_COMPUTER_CODE (e.g. SKORR5.RNGRR)
            }
        }

        return out;
    }


    
    // === vATCSCC: Override DP loading to use dp_full_routes.csv ===
    // New full-route indexes from dp_full_routes.csv
    let dpFullRoutesByTransition = {};
    let dpFullRoutesByDpCode = {};

    // Override loadDepartureProcedures to load dp_full_routes.csv instead of DP_BASE/DP_RTE
    loadDepartureProcedures = function() {
        $.ajax({
            type: 'GET',
            url: 'assets/data/dp_full_routes.csv',
            async: false
        }).done(function(data) {
            const rows = parseCsvWithQuotes(data);
            if (!rows || rows.length < 2) {
                return;
            }

            const header = rows[0].map(h => (h || '').toString().replace(/"/g, '').trim().toUpperCase());

            const idxEffDate      = header.indexOf('EFF_DATE');
            const idxDpName       = header.indexOf('DP_NAME');
            const idxComputerCode = header.indexOf('DP_COMPUTER_CODE');
            const idxOrigGroup    = header.indexOf('ORIG_GROUP');
            const idxTransCode    = header.indexOf('TRANSITION_COMPUTER_CODE');
            const idxRoutePoints  = header.indexOf('ROUTE_POINTS');

            if (idxComputerCode < 0 || idxOrigGroup < 0 || idxTransCode < 0 || idxRoutePoints < 0) {
                console.warn('dp_full_routes.csv missing required columns; DP handling disabled.');
                return;
            }

            // Reset DP structures
            dpByComputerCode = {};
            dpByLeftCode     = {};
            dpByRootName     = {};
            dpPatternIndex   = {};
            dpFullRoutesByTransition = {};
            dpFullRoutesByDpCode     = {};

            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                if (!row || !row.length) continue;

                const codeRaw = (row[idxComputerCode] || '').toString().replace(/"/g, '').trim();
                if (!codeRaw) continue;
                const code = codeRaw.toUpperCase();

                const effDate = idxEffDate !== -1 ? dpParseEffDate(row[idxEffDate]) : 0;

                const dpNameRaw = (row[idxDpName] || '').toString().replace(/"/g, '').trim();
                const dpName    = dpNameRaw.toUpperCase();

                const origGroupRaw = (row[idxOrigGroup] || '').toString().replace(/"/g, '').trim().toUpperCase();
                if (!origGroupRaw) continue;

                const originTokens = origGroupRaw.split(/\s+/).filter(Boolean);
                const originList = [];
                originTokens.forEach(function(tok) {
                    if (!tok) return;
                    const slashIndex = tok.indexOf('/');
                    const apt = slashIndex >= 0 ? tok.substring(0, slashIndex) : tok;
                    if (apt && originList.indexOf(apt) === -1) {
                        originList.push(apt);
                    }
                });

                const transCodeRaw = (row[idxTransCode] || '').toString().replace(/"/g, '').trim();
                const transCode    = transCodeRaw.toUpperCase();

                const routePtsRaw = (row[idxRoutePoints] || '').toString().replace(/"/g, '').trim().toUpperCase();
                const routePoints = routePtsRaw ? routePtsRaw.split(/\s+/).filter(Boolean) : [];

                // Build / update base DP record keyed by DP_COMPUTER_CODE
                let rec = dpByComputerCode[code];
                if (!rec) {
                    rec = {
                        code: code,
                        name: dpName,
                        servedAirports: [],
                        effDate: effDate
                    };
                    dpByComputerCode[code] = rec;
                } else {
                    if (effDate > rec.effDate) {
                        rec.effDate = effDate;
                    }
                }

                originList.forEach(function(apt) {
                    if (rec.servedAirports.indexOf(apt) === -1) {
                        rec.servedAirports.push(apt);
                    }
                });

                // Populate left-code and root-name indexes (e.g., ARDIA7 -> rec, ARDIA -> rec)
                const partsCode = code.split('.');
                if (partsCode.length === 2) {
                    const leftPart = partsCode[0];

                    const existingLeft = dpByLeftCode[leftPart];
                    if (!existingLeft || effDate > existingLeft.effDate) {
                        dpByLeftCode[leftPart] = rec;
                    }

                    const rootLetters = leftPart.replace(/[0-9]/g, '');
                    if (rootLetters) {
                        const existingRoot = dpByRootName[rootLetters];
                        if (!existingRoot || effDate > existingRoot.effDate) {
                            dpByRootName[rootLetters] = rec;
                        }
                    }
                }

                // Pattern index used for tokens like CLTCH#.TOWIN
                const pattern = buildDpPattern(code);
                if (pattern) {
                    const existing = dpPatternIndex[pattern];
                    if (!existing || effDate > existing.effDate) {
                        dpPatternIndex[pattern] = {
                            code: code,
                            effDate: effDate
                        };
                    }
                }

                // Store full-route info by transition and by DP code
                const fullEntry = {
                    dpCode: code,
                    dpName: dpName,
                    transCode: transCode,
                    effDate: effDate,
                    origins: originList.slice(),
                    routePoints: routePoints
                };

                if (transCode) {
                    if (!dpFullRoutesByTransition[transCode]) {
                        dpFullRoutesByTransition[transCode] = [];
                    }
                    dpFullRoutesByTransition[transCode].push(fullEntry);
                }

                if (!dpFullRoutesByDpCode[code]) {
                    dpFullRoutesByDpCode[code] = [];
                }
                dpFullRoutesByDpCode[code].push(fullEntry);
            }

            loadDpRouteBodies();
            dpDataLoaded = true;
        }).fail(function() {
            console.warn('dp_full_routes.csv not found or failed to load; DP handling disabled.');
        });
    };

    // Helper: get all full sequences for a given TRANSITION_COMPUTER_CODE and origin
    function getDpFullSequencesForTransCode(transCode, originUpper) {
        if (!transCode) return [];
        const key = transCode.toString().toUpperCase();
        const recs = dpFullRoutesByTransition[key];
        if (!recs || !recs.length) return [];

        const out = [];
        const origin = (originUpper || '').toString().toUpperCase();

        for (let i = 0; i < recs.length; i++) {
            const rec = recs[i];
            if (!rec) continue;

            if (origin && rec.origins && rec.origins.length) {
                if (!originMatchesServed(origin, rec.origins)) {
                    continue;
                }
            }

            const seq = [];
            const chosenOrigin = origin || (rec.origins && rec.origins.length ? rec.origins[0] : null);
            if (chosenOrigin) {
                seq.push(chosenOrigin);
            }

            if (rec.routePoints && rec.routePoints.length) {
                for (let j = 0; j < rec.routePoints.length; j++) {
                    const p = rec.routePoints[j];
                    if (p) {
                        seq.push(p.toUpperCase());
                    }
                }
            }

            if (seq.length) {
                out.push(seq);
            }
        }

        return out;
    }

    // Helper: when only a base DP (no explicit transition) is given, pick any available route
    function getDpFullSequenceForDpCode(dpCode, originUpper) {
        if (!dpCode) return null;
        const key = dpCode.toString().toUpperCase();
        const recs = dpFullRoutesByDpCode[key];
        if (!recs || !recs.length) return null;

        const origin = (originUpper || '').toString().toUpperCase();
        let best = null;

        for (let i = 0; i < recs.length; i++) {
            const rec = recs[i];
            if (!rec) continue;

            if (origin && rec.origins && rec.origins.length) {
                if (!originMatchesServed(origin, rec.origins)) {
                    continue;
                }
            }

            if (!best || (rec.routePoints && rec.routePoints.length > (best.routePoints ? best.routePoints.length : 0))) {
                best = rec;
            }
        }

        if (!best) return null;

        const seq = [];
        const chosenOrigin = origin || (best.origins && best.origins.length ? best.origins[0] : null);
        if (chosenOrigin) {
            seq.push(chosenOrigin);
        }

        if (best.routePoints && best.routePoints.length) {
            for (let j = 0; j < best.routePoints.length; j++) {
                const p = best.routePoints[j];
                if (p) {
                    seq.push(p.toUpperCase());
                }
            }
        }

        return seq.length ? seq : null;
    }

    // Override expandDepartureProcedures to use dp_full_routes full-route data
    function expandDepartureProcedures(tokens, solidMask) {
        dpActiveBodyPoints = new Set();

        if (!dpDataLoaded || !tokens || !tokens.length) {
            return {
                tokens: tokens || [],
                solidMask: solidMask || []
            };
        }

        const origin = findRouteOriginForDp(tokens);
        if (!origin) {
            return {
                tokens: tokens || [],
                solidMask: solidMask || []
            };
        }

        const originUpper = origin.toString().toUpperCase();
        const outTokens = [];
        const outSolid  = [];

        const inSolid = solidMask && solidMask.length === tokens.length
            ? solidMask
            : new Array(tokens.length).fill(true);

        for (let i = 0; i < tokens.length; i++) {
            const tok = tokens[i];
            const upperTok = (tok || '').toString().toUpperCase();

            if (!upperTok) {
                outTokens.push(tok);
                outSolid.push(inSolid[i]);
                continue;
            }

            let consumedNextAsTransition = false;
            let fullSeq = null;

            // Only consider tokens that look DP-like (contain a digit or a '#')
            if (/[0-9#]/.test(upperTok)) {
                const dpRec = getDpForToken(upperTok, originUpper);

                if (dpRec) {
                    const tokParts  = upperTok.split('.');
                    const codeParts = dpRec.code.split('.');

                    let dpTransCode = '';

                    // Case 1: token has explicit ".TRANS" (e.g., ARDIA7.CLL)
                    if (tokParts.length === 2) {
                        const leftFromCode = (codeParts.length === 2 ? codeParts[0] : tokParts[0]);
                        const rightFromTok = tokParts[1];
                        dpTransCode = (leftFromCode + '.' + rightFromTok).toUpperCase();
                    } else {
                        // Case 2: token is just DP name (e.g., ARDIA7) and the next token might be a transition fix
                        const nextTok = tokens[i + 1] ? tokens[i + 1].toString().trim().toUpperCase() : '';
                        if (nextTok && /^[A-Z0-9]{3,6}$/.test(nextTok)) {
                            const leftFromCode = (codeParts.length === 2 ? codeParts[0] : tokParts[0]);
                            dpTransCode = (leftFromCode + '.' + nextTok).toUpperCase();
                            consumedNextAsTransition = true;
                        }
                    }

                    // Prefer full sequences for TRANSITION_COMPUTER_CODE if available
                    let sequences = dpTransCode ? getDpFullSequencesForTransCode(dpTransCode, originUpper) : [];


                    // If no explicit transition match, fall back to any route for this DP code
                    if ((!sequences || !sequences.length) && dpRec && dpRec.code) {
                        const seqFallback = getDpFullSequenceForDpCode(dpRec.code, originUpper);
                        if (seqFallback && seqFallback.length) {
                            sequences = [seqFallback];
                        }
                    }

                    // Final fallback: use legacy DP_RTE-based BODY/TRANSITION sequences if dp_full_routes has no match
                    if ((!sequences || !sequences.length) && dpRec && dpRec.code) {
                        const legacyDpCode = dpRec.code;
                        const bodySeq = typeof getDpBodySequence === 'function'
                            ? getDpBodySequence(legacyDpCode, originUpper)
                            : null;

                        let combined = [];
                        if (bodySeq && bodySeq.length) {
                            combined = bodySeq.slice();
                        }

                        // If we have a specific transition code, try to append its path
                        if (dpTransCode && typeof getDpTransitionSequence === 'function') {
                            const transSeqRaw = getDpTransitionSequence(dpTransCode, originUpper);
                            if (transSeqRaw && transSeqRaw.length) {
                                const bodyLast = combined.length ? combined[combined.length - 1] : null;
                                const forward  = transSeqRaw.slice();
                                const reversed = transSeqRaw.slice().reverse();
                                let chosen = forward;

                                if (bodyLast) {
                                    if (forward.length && forward[0] === bodyLast) {
                                        chosen = forward;
                                    } else if (reversed.length && reversed[0] === bodyLast) {
                                        chosen = reversed;
                                    }
                                }

                                if (bodyLast && chosen.length && chosen[0] === bodyLast) {
                                    chosen = chosen.slice(1);
                                }

                                combined = combined.concat(chosen);
                            }
                        }

                        if (combined.length) {
                            // Legacy sequences do not include the origin; prepend the actual origin
                            const fullFromLegacy = [originUpper].concat(combined.map(p => p.toUpperCase()));
                            sequences = [fullFromLegacy];
                        }
                    }

                    if ((!sequences || !sequences.length) && dpRec && dpRec.code) {
                        const seqFallback = getDpFullSequenceForDpCode(dpRec.code, originUpper);
                        if (seqFallback && seqFallback.length) {
                            sequences = [seqFallback];
                        }
                    }

                    if (sequences && sequences.length) {
                        // For now, pick the "longest" sequence as representative
                        let best = sequences[0];
                        for (let s = 1; s < sequences.length; s++) {
                            if (sequences[s].length > best.length) {
                                best = sequences[s];
                            }
                        }
                        fullSeq = best;

                        // Mark all non-airport fixes in the DP path as DP body points
                        for (let j = 0; j < fullSeq.length; j++) {
                            const p = fullSeq[j];
                            if (!p) continue;
                            const pUpper = p.toUpperCase();

                            // Heuristic: treat 4-letter K*/C* or matching origin as airports, skip those
                            if (pUpper.length === 4 && /^[A-Z]{3,4}$/.test(pUpper) &&
                                (pUpper === originUpper || pUpper[0] === 'K' || pUpper[0] === 'C')) {
                                continue;
                            }

                            dpActiveBodyPoints.add(pUpper);
                        }
                    }
                }
            }

            if (fullSeq && fullSeq.length) {
                for (let j = 0; j < fullSeq.length; j++) {
                    outTokens.push(fullSeq[j].toUpperCase());
                    outSolid.push(inSolid[i]);
                }

                if (consumedNextAsTransition) {
                    i++;
                }
            } else {
                outTokens.push(upperTok);
                outSolid.push(inSolid[i]);
            }
        }

        return {
            tokens: outTokens,
            solidMask: outSolid
        };
    }
// Load playbook routes now
    loadPlaybookRoutes();

    // Load DP/STAR data - prefer enhanced versions from procs_enhanced.js
    if (typeof loadDepartureProceduresEnhanced === 'function') {
        loadDepartureProceduresEnhanced();
        console.log('[PROCS] Using enhanced DP loading');
    } else {
        loadDepartureProcedures();
    }

    if (typeof loadStarProceduresEnhanced === 'function') {
        loadStarProceduresEnhanced();
        console.log('[PROCS] Using enhanced STAR loading');
    } else {
        loadStarProcedures();
    }
    
    /**
     * convert degrees to radians
     */
    const degreesToRadians = (degrees) => {
        return (degrees / 360) * (Math.PI * 2);
    };
    
    /**
     * Haversine
     */
    const distanceToPoint = (startLatitude, startLongitude, endLatitude, endLongitude) => {
        const EARTH_RADIUS_KM = 6371;
        const startLatitudeRadians = degreesToRadians(startLatitude);
        const endLatitudeRadians = degreesToRadians(endLatitude);
        const distanceLatitude = degreesToRadians(startLatitude - endLatitude);
        const distanceLongitude = degreesToRadians(startLongitude - endLongitude);
    
        const a = Math.pow(Math.sin(distanceLatitude / 2), 2) +
            (Math.cos(startLatitudeRadians) * Math.cos(endLatitudeRadians) * Math.pow(Math.sin(distanceLongitude / 2), 2));
    
        const angularDistanceInRadians = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    
        return angularDistanceInRadians * EARTH_RADIUS_KM;
    };
    
    function confirmReasonableDistance(pointData, previousPointData, nextPointData) {
        let maxReasonableDistance = 4000; // km
    
        if (previousPointData && nextPointData) {
            const distanceFromPreviousToNextPoint = distanceToPoint(previousPointData[1], previousPointData[2], nextPointData[1], nextPointData[2]);
            maxReasonableDistance = Math.min(maxReasonableDistance, distanceFromPreviousToNextPoint * 1.5);
        }
    
        if (previousPointData) {
            const distanceToPreviousPoint = distanceToPoint(pointData[1], pointData[2], previousPointData[1], previousPointData[2]);
    
            if (distanceToPreviousPoint > maxReasonableDistance) {
                return undefined;
            }
        }
    
        if (nextPointData) {
            const distanceToNextPoint = distanceToPoint(pointData[1], pointData[2], nextPointData[1], nextPointData[2]);
    
            if (distanceToNextPoint > maxReasonableDistance) {
                return undefined;
            }
        }
    
        return pointData;
    }
    
    // --- Coordinate parsing helpers (ICAO, NAT, ARINC) ---
    
    function parseLatComponent(part) {
        if (!part) return null;
        const s = String(part).toUpperCase().trim();
    
        // ddmmN / ddmmS
        let m = s.match(/^(\d{2})(\d{2})([NS])$/);
        if (m) {
            const deg = parseInt(m[1], 10);
            const minute = parseInt(m[2], 10);
            let lat = deg + minute / 60.0;
            if (m[3] === 'S') lat = -lat;
            return lat;
        }
    
        // ddN / ddS
        m = s.match(/^(\d{2})([NS])$/);
        if (m) {
            const deg = parseInt(m[1], 10);
            let lat = deg;
            if (m[2] === 'S') lat = -lat;
            return lat;
        }
    
        return null;
    }
    
    function parseLonComponent(part) {
        if (!part) return null;
        const s = String(part).toUpperCase().trim();
    
        // dddmmE/W
        let m = s.match(/^(\d{3})(\d{2})([EW])$/);
        if (m) {
            const deg = parseInt(m[1], 10);
            const minute = parseInt(m[2], 10);
            let lon = deg + minute / 60.0;
            if (m[3] === 'W') lon = -lon;
            return lon;
        }
    
        // ddmmE/W (lon < 100)
        m = s.match(/^(\d{2})(\d{2})([EW])$/);
        if (m) {
            const deg = parseInt(m[1], 10);
            const minute = parseInt(m[2], 10);
            let lon = deg + minute / 60.0;
            if (m[3] === 'W') lon = -lon;
            return lon;
        }
    
        // dddE/W
        m = s.match(/^(\d{3})([EW])$/);
        if (m) {
            const deg = parseInt(m[1], 10);
            let lon = deg;
            if (m[2] === 'W') lon = -lon;
            return lon;
        }
    
        // ddE/W (lon < 100)
        m = s.match(/^(\d{2})([EW])$/);
        if (m) {
            const deg = parseInt(m[1], 10);
            let lon = deg;
            if (m[2] === 'W') lon = -lon;
            return lon;
        }
    
        return null;
    }
    
    // ARINC 5-char shorthand (e.g. 5275N, 75N70, 5020S, 5275W)
    function parseArincFiveCharCoordinate(token) {
        if (!token) return null;
        const s = String(token).toUpperCase().trim();
    
        let m = s.match(/^(\d{2})(\d{2})([NSEW])$/);
        let latDeg, lonDeg, letter;
        if (m) {
            latDeg = parseInt(m[1], 10);
            const lonLast = parseInt(m[2], 10);
            lonDeg = lonLast; // longitude < 100
            letter = m[3];
        } else {
            m = s.match(/^(\d{2})([NSEW])(\d{2})$/);
            if (!m) return null;
            latDeg = parseInt(m[1], 10);
            const lonLast = parseInt(m[3], 10);
            lonDeg = 100 + lonLast; // longitude >= 100
            letter = m[2];
        }
    
        let lat = latDeg;
        let lon = lonDeg;
    
        // Letter encodes quadrants (per ARINC shorthand convention)
        switch (letter) {
            case 'N': // North / West
                lat = +lat;
                lon = -lon;
                break;
            case 'S': // South / East
                lat = -lat;
                lon = +lon;
                break;
            case 'E': // North / East
                lat = +lat;
                lon = +lon;
                break;
            case 'W': // South / West
                lat = -lat;
                lon = -lon;
                break;
            default:
                return null;
        }
    
        return { lat, lon };
    }
    
    // NAT half-degree HxxYY -> xx°30'N yy°W (e.g. H5250 = 52°30N 50W)
    function parseNatHalfDegreeCoordinate(token) {
        if (!token) return null;
        const s = String(token).toUpperCase().trim();
        const m = s.match(/^H(\d{2})(\d{2})$/);
        if (!m) return null;
    
        const latDeg = parseInt(m[1], 10);
        const lonDeg = parseInt(m[2], 10);
        const lat = latDeg + 0.5; // 30'
        const lon = -lonDeg;      // West
    
        return { lat, lon };
    }
    
    // NAT shorthand dd/dd -> dd°N dd°W (e.g. 51/53)
    function parseNatSlashCoordinate(token) {
        if (!token) return null;
        const s = String(token).toUpperCase().trim();
        const m = s.match(/^(\d{2})\/(\d{2})$/);
        if (!m) return null;
    
        const latDeg = parseInt(m[1], 10);
        const lonDeg = parseInt(m[2], 10);
        const lat = latDeg;
        const lon = -lonDeg;
    
        return { lat, lon };
    }
    
    // ICAO-style "LAT/LON" with slash, e.g. 5230N/05000W
    function parseIcaoSlashLatLon(token) {
        if (!token) return null;
        const s = String(token).toUpperCase().trim();
        if (s.indexOf('/') === -1) return null;
    
        const parts = s.split('/');
        if (parts.length !== 2) return null;
    
        const lat = parseLatComponent(parts[0]);
        const lon = parseLonComponent(parts[1]);
        if (lat === null || lon === null) return null;
    
        return { lat, lon };
    }
    
    // ICAO / NAT compact forms in a single token:
    //  - ddmmNdddmmW (e.g. 7500N13400W, 5230N05000W)
    //  - ddNdddW (e.g. 52N030W)
    //  - ddmmNdddW, ddNdddmmW (mixed minutes)
    function parseIcaoCompactLatLon(token) {
        if (!token) return null;
        const s = String(token).toUpperCase().trim();
    
        // ddmmNdddmmW/E
        let m = s.match(/^(\d{2})(\d{2})([NS])(\d{3})(\d{2})([EW])$/);
        if (m) {
            const latDeg = parseInt(m[1], 10);
            const latMin = parseInt(m[2], 10);
            const lonDeg = parseInt(m[4], 10);
            const lonMin = parseInt(m[5], 10);
    
            let lat = latDeg + latMin / 60.0;
            let lon = lonDeg + lonMin / 60.0;
    
            if (m[3] === 'S') lat = -lat;
            if (m[6] === 'W') lon = -lon;
    
            return { lat, lon };
        }
    
        // ddNdddW/E (whole degrees)
        m = s.match(/^(\d{2})([NS])(\d{3})([EW])$/);
        if (m) {
            const latDeg = parseInt(m[1], 10);
            const lonDeg = parseInt(m[3], 10);
    
            let lat = latDeg;
            let lon = lonDeg;
    
            if (m[2] === 'S') lat = -lat;
            if (m[4] === 'W') lon = -lon;
    
            return { lat, lon };
        }
    
        // ddmmNdddW/E (lat deg+min, lon deg only)
        m = s.match(/^(\d{2})(\d{2})([NS])(\d{3})([EW])$/);
        if (m) {
            const latDeg = parseInt(m[1], 10);
            const latMin = parseInt(m[2], 10);
            const lonDeg = parseInt(m[4], 10);
    
            let lat = latDeg + latMin / 60.0;
            let lon = lonDeg;
    
            if (m[3] === 'S') lat = -lat;
            if (m[5] === 'W') lon = -lon;
    
            return { lat, lon };
        }
    
        // ddNdddmmW/E (lat deg only, lon deg+min)
        m = s.match(/^(\d{2})([NS])(\d{3})(\d{2})([EW])$/);
        if (m) {
            const latDeg = parseInt(m[1], 10);
            const lonDeg = parseInt(m[3], 10);
            const lonMin = parseInt(m[4], 10);
    
            let lat = latDeg;
            let lon = lonDeg + lonMin / 60.0;
    
            if (m[2] === 'S') lat = -lat;
            if (m[5] === 'W') lon = -lon;
    
            return { lat, lon };
        }
    
        return null;
    }
    
    // Unified coordinate parser
    function parseCoordinateToken(token) {
        if (!token) return null;
        const s = String(token).toUpperCase().trim();
    
        // Order matters: try the most specific / least ambiguous first
        let coord = parseNatSlashCoordinate(s);      // e.g. 51/53
        if (coord) return coord;
    
        coord = parseNatHalfDegreeCoordinate(s);     // e.g. H5250
        if (coord) return coord;
    
        coord = parseIcaoSlashLatLon(s);             // e.g. 5230N/05000W
        if (coord) return coord;
    
        coord = parseIcaoCompactLatLon(s);           // e.g. 7500N13400W, 52N030W
        if (coord) return coord;
    
        coord = parseArincFiveCharCoordinate(s);     // e.g. 5275N, 75N70, 5020S
        if (coord) return coord;
    
        return null;
    }
    
    // ARTCC/FIR-aware point lookup:
    // Prefer ZZ_{NAME} (dummy centerpoint) for any facility we detect from ZZ_* entries,
    // fall back to raw fix only if no ZZ_ exists, and finally to polygon center if needed.
    function getPointByName(pointName, previousPointData, nextPointData) {
        if (!pointName) {
            return undefined;
        }
    
        const name = String(pointName).toUpperCase();
        let key = null;
    
        // Direct ZZ_* reference
        if (name.startsWith('ZZ_')) {
            if (points[name]) {
                key = name;
            }
        }
        // Known facility (ARTCC/TRACON/FIR) derived from ZZ_* entries
        else if (facilityCodes.has(name)) {
            const zzKey = 'ZZ_' + name;
            if (points[zzKey]) {
                key = zzKey; // prefer dummy centerpoint
            } else if (points[name]) {
                key = name;  // fallback to raw fix only if ZZ_* isn't present
            } else if (areaCenters[name]) {
                // Last-ditch fallback to polygon center
                const center = areaCenters[name];
                const pseudo = [name, center.lat, center.lon];
                return confirmReasonableDistance(pseudo, previousPointData, nextPointData);
            }
        }
        // Normal fix / NAVAID / airport
        else {
            if (points[name]) {
                key = name;
            }
        }
    
        // If we found a known point in the database, pick the best candidate
        if (key && points[key]) {
            const pointList = points[key];
    
            if (pointList.length === 1) {
                const selectedPoint = pointList[0];
                return confirmReasonableDistance(selectedPoint, previousPointData, nextPointData);
            }
    
            if (!previousPointData && !nextPointData) {
                const selectedPoint = pointList[0];
                return confirmReasonableDistance(selectedPoint, previousPointData, nextPointData);
            }
    
            let centerPosition = previousPointData;
    
            if (!previousPointData) {
                centerPosition = nextPointData;
            }
    
            if (previousPointData && nextPointData) {
                centerPosition = [
                    centerPosition[0],
                    (previousPointData[1] + nextPointData[1]) / 2,
                    (previousPointData[2] + nextPointData[2]) / 2
                ];
            }
    
            const errorMap = pointList.map(p => {
                const totalError = Math.abs(centerPosition[1] - p[1]) + Math.abs(centerPosition[2] - p[2]);
                return totalError;
            });
    
            const indexOfClosestFix = errorMap.indexOf(Math.min(...errorMap));
            const selectedPoint = pointList[indexOfClosestFix];
    
            return confirmReasonableDistance(selectedPoint, previousPointData, nextPointData);
        }
    
        // Fallback: treat as an on-the-fly coordinate (ICAO / NAT / ARINC)
        const coord = parseCoordinateToken(name);
        if (coord) {
            const pseudo = [name, coord.lat, coord.lon];
            return confirmReasonableDistance(pseudo, previousPointData, nextPointData);
        }
    
        return undefined;
    }
    
    function countOccurrencesOfPointName(pointName) {
        if (!pointName) {
            return 0;
        }
    
        const name = String(pointName).toUpperCase();
    
        if (name.startsWith('ZZ_')) {
            return points[name] ? points[name].length : 0;
        }
    
        // For known facilities, mirror getPointByName: prefer ZZ_* then raw
        if (facilityCodes.has(name)) {
            const zzName = 'ZZ_' + name;
            if (points[zzName]) {
                return points[zzName].length;
            }
            if (points[name]) {
                return points[name].length;
            }
            return 0;
        }
    
        if (points[name]) {
            return points[name].length;
        }
    
        // Treat any recognizable coordinate token as a single unique "point"
        if (parseCoordinateToken(name)) {
            return 1;
        }
    
        return 0;
    }
    
    function isAirportIdent(pointId) {
        if (!pointId) {
            return false;
        }
        const code = String(pointId).toUpperCase();
    
        // ARTCC/FIR centerpoints (ZZ_*) are "airport-like" endpoints
        if (code.startsWith('ZZ_')) {
            return true;
        }
    
        // Treat 4-letter ICAOs as "airport-like"
        if (code.length === 4) {
            return true;
        }
    
        // TRACON / ARTCC centers from polygons
        if (areaCenters[code]) {
            return true;
        }
        return false;
    }
    
    // --- Register TRACON / ARTCC polygons as pseudo-airports (centers only) ---
    function registerFacilityFeature(feature, layer) {
        if (!feature || !layer) return;
    
        const props = feature.properties || {};
        const bounds = layer.getBounds();
        if (!bounds.isValid()) return;
    
        const center = bounds.getCenter();
        const codes = [];
    
        // Identify facilities from properties (do not outline)
        ['label', 'LABEL', 'artcc', 'ARTCC', 'tracon', 'TRACON', 'facility', 'FACILITY'].forEach(key => {
            if (props[key]) {
                const val = String(props[key]).trim();
                if (val && val.toUpperCase() !== 'NULL') {
                    codes.push(val.toUpperCase());
                }
            }
        });
    
        codes.forEach(code => {
            if (!areaCenters[code]) {
                areaCenters[code] = { lat: center.lat, lon: center.lng };
            }
        });
    }
    
    // --- Advisory / reroute parsing helpers ---
    
    function normalizeAirportIdent(token) {
        if (!token) return token;
        token = token.toUpperCase().trim();
    
        // Treat 3-letter non-Z codes as IATA and convert to K+IATA
        if (token.length === 3 && !token.startsWith('Z')) {
            return 'K' + token;
        }
        return token;
    }
    
    function isAirportToken(tok) {
        if (!tok) return false;
        tok = tok.toUpperCase().trim();
        if (!/^[A-Z0-9]{3,4}$/.test(tok)) return false;
        if (tok.length === 3 && tok.startsWith('Z')) return false;
        return true;
    }
    
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
    
    function parseFromToStyle(lines) {
        const upper = lines.map(l => l.toUpperCase());
        const fromIdx = upper.findIndex(l => l.trim().startsWith('FROM:'));
        const toIdx   = upper.findIndex(l => l.trim().startsWith('TO:'));
    
        if (fromIdx === -1 || toIdx === -1 || toIdx <= fromIdx) {
            return [];
        }
    
        const fromBlock = lines.slice(fromIdx + 1, toIdx);
        const toBlock   = lines.slice(toIdx + 1);
    
        const originSegments = [];
        const destSegments   = [];
    
        // FROM block
        for (let line of fromBlock) {
            const trimmed = line.trim();
            if (!trimmed) continue;
            const up = trimmed.toUpperCase();
            if (up.startsWith('ORIG') || up.startsWith('----')) continue;
    
            const gtIndex = line.indexOf('>');
            const prefix  = (gtIndex >= 0 ? line.slice(0, gtIndex) : line).trim();
            const segment = (gtIndex >= 0 ? line.slice(gtIndex) : '').trim();
            if (!segment) continue;
    
            const prefixTokens = prefix.split(/\s+/);
            const origAirports = prefixTokens
                .filter(isAirportToken)
                .map(normalizeAirportIdent);
    
            originSegments.push({
                origAirports,
                segment
            });
        }
    
        // TO block
        for (let line of toBlock) {
            const trimmed = line.trim();
            if (!trimmed) continue;
            const up = trimmed.toUpperCase();
            if (up.startsWith('DEST') || up.startsWith('----')) continue;
            if (up.startsWith('TMI ID')) break;
    
            const tokens = trimmed.split(/\s+/);
            if (tokens.length < 2) continue;
    
            const destAirport = tokens[0];
            if (!isAirportToken(destAirport)) continue;
    
            const segmentTokens = tokens.slice(1);
            if (!segmentTokens.length) continue;
    
            destSegments.push({
                destAirports: [normalizeAirportIdent(destAirport)],
                segment: segmentTokens.join(' ')
            });
        }
    
        const routeLines = [];
    
        originSegments.forEach(o => {
            destSegments.forEach(d => {
                const originTokens = o.segment.split(/\s+/);
                const destTokens   = d.segment.split(/\s+/);
    
                let combined;
                if (originTokens.length && destTokens.length) {
                    const lastO  = originTokens[originTokens.length - 1].replace(/[<>]/g, '');
                    const firstD = destTokens[0].replace(/[<>]/g, '');
                    if (lastO === firstD) {
                        combined = originTokens.concat(destTokens.slice(1));
                    } else {
                        combined = originTokens.concat(destTokens);
                    }
                } else if (originTokens.length) {
                    combined = originTokens;
                } else {
                    combined = destTokens;
                }
    
                const oAirports = o.origAirports || [];
                const dAirports = d.destAirports || [];
    
                let tokens = [];
                if (oAirports.length) tokens = tokens.concat(oAirports);
                tokens = tokens.concat(combined);
                if (dAirports.length) tokens = tokens.concat(dAirports);
    
                const routeLine = tokens.join(' ').split(';')[0].trim();
                if (routeLine) routeLines.push(routeLine);
            });
        });
    
        return routeLines;
    }
    
    // helper to convert ORIG/DEST tokens to endpoint codes (airport or facility)
    function convertEndpointToken(token) {
        if (!token) return null;
        const t = token.toUpperCase().trim();
        if (!t) return null;
    
        // 3-letter non-Z -> treat as IATA and make ICAO
        if (/^[A-Z]{3}$/.test(t) && !t.startsWith('Z')) {
            return 'K' + t;
        }
        // 3 or 4 letters/digits (e.g. ZTL, ZNY, N90, A80, KJFK, KMIA)
        if (/^[A-Z0-9]{3,4}$/.test(t)) {
            return t;
        }
        return t;
    }
    
    function parseOrigDestRouteTable(lines) {
        const upper = lines.map(l => l.toUpperCase());
        let headerIndex = -1;
    
        for (let i = 0; i < upper.length; i++) {
            const tr = upper[i].trim();
            if (tr.startsWith('ORIG') && tr.includes('DEST') && tr.includes('ROUTE')) {
                headerIndex = i;
                break;
            }
        }
        if (headerIndex === -1) return [];
    
        const headerUpper = upper[headerIndex];
    
        // If header has no "big gaps", treat it as simple space-separated style
        const headerSimple = !/\s{2,}/.test(headerUpper.replace(/\s+$/, ''));
    
        const routeLines = [];
    
        function makeRouteLinesForRow(origTokensRaw, destTokensRaw, routeField) {
            const routeTokens = routeField.split(/\s+/).filter(Boolean);
            if (!routeTokens.length) return;
    
            const origEndpoints = origTokensRaw
                .map(convertEndpointToken)
                .filter(Boolean);
            const destEndpoints = destTokensRaw
                .map(convertEndpointToken)
                .filter(Boolean);
    
            const origList = origEndpoints.length ? origEndpoints : [null];
            const destList = destEndpoints.length ? destEndpoints : [null];
    
            origList.forEach(o => {
                destList.forEach(d => {
                    const tokens = [];
                    if (o) tokens.push(o);
                    tokens.push(...routeTokens);
                    if (d) tokens.push(d);
                    const routeLine = tokens.join(' ').replace(/\s+/g, ' ').trim();
                    if (routeLine) routeLines.push(routeLine);
                });
            });
        }
    
        // Simple: "ORIG DEST ROUTE"
        if (headerSimple) {
            for (let i = headerIndex + 1; i < lines.length; i++) {
                const line     = lines[i];
                const lineUpper = upper[i];
                const trimmed   = line.trim();
                const trimmedUp = lineUpper.trim();
    
                if (!trimmed) continue;
                if (trimmedUp.startsWith('----')) continue;
                if (trimmedUp.startsWith('TMI ID')) break;
    
                const tokens = trimmed.split(/\s+/);
                if (tokens.length < 3) continue;
    
                const origTokensRaw = [tokens[0]];
                const destTokensRaw = [tokens[1]];
                const routeField    = tokens.slice(2).join(' ');
    
                makeRouteLinesForRow(origTokensRaw, destTokensRaw, routeField);
            }
    
            return routeLines;
        }
    
        // Column-aligned style: "ORIG   DEST   ROUTE"
        const destColStart  = headerUpper.indexOf('DEST');
        const routeColStart = headerUpper.indexOf('ROUTE');
        if (destColStart === -1 || routeColStart === -1) return [];
    
        let currentOrigTokensRaw = [];
    
        for (let i = headerIndex + 1; i < lines.length; i++) {
            const line      = lines[i];
            const lineUpper = upper[i];
            const trimmed   = line.trim();
            const trimmedUp = lineUpper.trim();
    
            if (!trimmed) continue;
            if (trimmedUp.startsWith('----')) continue;
            if (trimmedUp.startsWith('TMI ID')) break;
    
            const originRaw  = line.slice(0, destColStart);
            const destRaw    = line.slice(destColStart, routeColStart);
            const routeField = line.slice(routeColStart).trim();
            if (!routeField) continue;
    
            let origTokensRaw = originRaw.trim()
                ? originRaw.trim().split(/\s+/)
                : [];
    
            if (origTokensRaw.length) {
                currentOrigTokensRaw = origTokensRaw.slice();
            } else {
                // Inherit ORIG from previous row when ORIG column is blank
                origTokensRaw = currentOrigTokensRaw.slice();
            }
    
            const destTokensRaw = destRaw.trim()
                ? destRaw.trim().split(/\s+/)
                : [];
    
            if (!origTokensRaw.length && !destTokensRaw.length) continue;
    
            makeRouteLinesForRow(origTokensRaw, destTokensRaw, routeField);
        }
    
        return routeLines;
    }
    
    function parseOrigDestSegmentsStyle(lines) {
        const upper = lines.map(l => l.toUpperCase());
    
        const origHeaderIdx = upper.findIndex(l =>
            l.trim().startsWith('ORIG') && l.includes('ROUTE SEGMENTS')
        );
        if (origHeaderIdx === -1) return [];
    
        const destHeaderIdx = upper.findIndex((l, i) =>
            i > origHeaderIdx && l.trim().startsWith('DEST') && l.includes('ROUTE SEGMENTS')
        );
        if (destHeaderIdx === -1) return [];
    
        const origBlock = lines.slice(origHeaderIdx + 1, destHeaderIdx);
        const destBlock = lines.slice(destHeaderIdx + 1);
    
        const originSegments = [];
        const destSegments   = [];
    
        // ORIG block
        for (let line of origBlock) {
            const trimmed = line.trim();
            if (!trimmed) continue;
            const up = trimmed.toUpperCase();
            if (up.startsWith('----')) continue;
    
            const parts = line.replace(/\s+$/, '').split(/\s{2,}/);
            if (parts.length < 2) continue;
    
            const originField = parts[0].trim();
            const routeField  = parts[1].trim();
            if (!routeField) continue;
    
            const origTokensRaw = originField.split(/\s+/).filter(Boolean);
    
            originSegments.push({
                origTokensRaw,
                segment: routeField
            });
        }
    
        // DEST block
        for (let line of destBlock) {
            const trimmed = line.trim();
            if (!trimmed) continue;
            const up = trimmed.toUpperCase();
            if (up.startsWith('----')) continue;
            if (up.startsWith('TMI ID')) break;
    
            const parts = line.replace(/\s+$/, '').split(/\s{2,}/);
            if (parts.length < 2) continue;
    
            const destField  = parts[0].trim();
            const routeField = parts[1].trim();
            if (!routeField) continue;
    
            const destTokensRaw = destField.split(/\s+/).filter(Boolean);
    
            destSegments.push({
                destTokensRaw,
                segment: routeField
            });
        }
    
        const routeLines = [];
    
        originSegments.forEach(o => {
            const originTokens = o.segment.split(/\s+/);
    
            destSegments.forEach(d => {
                const destTokens = d.segment.split(/\s+/);
    
                let combined;
                if (originTokens.length && destTokens.length) {
                    const lastO  = originTokens[originTokens.length - 1].replace(/[<>]/g, '');
                    const firstD = destTokens[0].replace(/[<>]/g, '');
                    if (lastO === firstD) {
                        combined = originTokens.concat(destTokens.slice(1));
                    } else {
                        combined = originTokens.concat(destTokens);
                    }
                } else if (originTokens.length) {
                    combined = originTokens;
                } else {
                    combined = destTokens;
                }
    
                const origTokensRaw = (o.origTokensRaw && o.origTokensRaw.length)
                    ? o.origTokensRaw
                    : [null];
                const destTokensRaw = (d.destTokensRaw && d.destTokensRaw.length)
                    ? d.destTokensRaw
                    : [null];
    
                origTokensRaw.forEach(oa => {
                    destTokensRaw.forEach(da => {
                        const endpointsOrig = oa ? [convertEndpointToken(oa)] : [null];
                        const endpointsDest = da ? [convertEndpointToken(da)] : [null];
    
                        endpointsOrig.forEach(oEnd => {
                            endpointsDest.forEach(dEnd => {
                                const tokens = [];
                                if (oEnd) tokens.push(oEnd);
                                tokens.push(...combined);
                                if (dEnd) tokens.push(dEnd);
    
                                const routeLine = tokens.join(' ').replace(/\s+/g, ' ').trim();
                                if (routeLine) routeLines.push(routeLine);
                            });
                        });
                    });
                });
            });
        });
    
        return routeLines;
    }
    
    function parseAdvisoryRoutes(rawText) {
        if (!rawText) return [];
    
        const lines = rawText.split(/\r?\n/);
        const upper = lines.map(l => l.toUpperCase());
    
        const routesIdx = upper.findIndex(l =>
            l.includes('ROUTES:') || l.includes('ROUTE:')
        );
        if (routesIdx === -1) return [];
    
        const after      = lines.slice(routesIdx + 1);
        const upperAfter = upper.slice(routesIdx + 1);
    
        // FROM: / TO: segmented style
        if (upperAfter.some(l => l.trim().startsWith('FROM:'))) {
            return parseFromToStyle(after);
        }
    
        // ORIG / DEST / ROUTE table style
        const tableRoutes = parseOrigDestRouteTable(after);
        if (tableRoutes && tableRoutes.length) {
            return tableRoutes;
        }
    
        // ORIG / DEST "ROUTE SEGMENTS" style
        if (upperAfter.some(l => l.includes('ROUTE SEGMENTS'))) {
            const segRoutes = parseOrigDestSegmentsStyle(after);
            if (segRoutes && segRoutes.length) {
                return segRoutes;
            }
        }
    
        return [];
    }
    
    function drawMapCall() {
        var container = L.DomUtil.get('graphic');
    
        if (container != null) {
            container._leaflet_id = '';
        }
    
        $('#graphic').remove();
        $('<div id="graphic" style="height: 750px;"></div>').insertAfter('#placeholder');
    
        drawMap(overlays);
    }
    
    function drawMap(overlays) {
    
        // Clean up ADL layer before removing map (safe check - ADL defined later in file)
        if (typeof window.ADL !== 'undefined' && window.ADL.getState && window.ADL.getState().enabled) {
            window.ADL.disable();
            $('#adl_toggle').prop('checked', false);
        }
    
        // Robust map cleanup
        if (graphic_map) {
            try {
                graphic_map.off();
                graphic_map.remove();
            } catch (e) {
                console.warn('Map cleanup error:', e);
            }
            graphic_map = null;
        }
        
        // Also clear any Leaflet reference on the container element
        var container = document.getElementById('graphic');
        if (container && container._leaflet_id) {
            delete container._leaflet_id;
        }
    
        // Map Configuration (START)
        graphic_map = L.map('graphic', {
            preferCanvas: true,
            zoomControl: true,
            scrollWheelZoom: true,
            dragging: true,
            doubleClickZoom: true,
            zoomSnap: 0.25
        }).setView([39.5, -98.35], 4);

        // -----------------------------------------------------------
        // Labeling state & helpers for route/fix/NAVAID labels
        // -----------------------------------------------------------
        const labelStateByKey = {};
        const labelMetaByKey = {};
        const pointRouteColorByKey = {};

        const labelBBoxes = [];

        function estimateLabelSize(text) {
            const t = (text || '').toString();
            const len = t.length || 1;
            // Rough estimate: ~7px per character + small padding
            const width = (len * 7) + 8;
            const height = 14;
            return { width: width, height: height };
        }

        function rectsOverlap(a, b) {
            return !(
                a.x + a.w <= b.x ||
                b.x + b.w <= a.x ||
                a.y + a.h <= b.y ||
                b.y + b.h <= a.y
            );
        }


        function connectorPointOnRectEdge(basePt, rect) {
            const bx = basePt.x;
            const by = basePt.y;
            const cx = rect.x + rect.w / 2;
            const cy = rect.y + rect.h / 2;
            const dx = cx - bx;
            const dy = cy - by;

            // If the base is already inside rectangle or direction is zero, just use center
            if ((bx >= rect.x && bx <= rect.x + rect.w &&
                 by >= rect.y && by <= rect.y + rect.h) ||
                (dx === 0 && dy === 0)) {
                return { x: cx, y: cy };
            }

            const candidates = [];

            function addCandidate(t, x, y) {
                if (t > 0 && t <= 1) {
                    candidates.push({ t: t, x: x, y: y });
                }
            }

            // Intersect with left/right sides (vertical lines)
            if (dx !== 0) {
                // Left
                let t = (rect.x - bx) / dx;
                let y = by + t * dy;
                if (y >= rect.y && y <= rect.y + rect.h) {
                    addCandidate(t, rect.x, y);
                }
                // Right
                t = (rect.x + rect.w - bx) / dx;
                y = by + t * dy;
                if (y >= rect.y && y <= rect.y + rect.h) {
                    addCandidate(t, rect.x + rect.w, y);
                }
            }

            // Intersect with top/bottom sides (horizontal lines)
            if (dy !== 0) {
                // Top
                let t = (rect.y - by) / dy;
                let x = bx + t * dx;
                if (x >= rect.x && x <= rect.x + rect.w) {
                    addCandidate(t, x, rect.y);
                }
                // Bottom
                t = (rect.y + rect.h - by) / dy;
                x = bx + t * dx;
                if (x >= rect.x && x <= rect.x + rect.w) {
                    addCandidate(t, x, rect.y + rect.h);
                }
            }

            if (!candidates.length) {
                return { x: cx, y: cy };
            }

            // Choose the closest intersection along the ray
            candidates.sort(function(a, b) { return a.t - b.t; });
            return { x: candidates[0].x, y: candidates[0].y };
        }


        function chooseLabelPlacement(baseLatLng, text) {
            const basePt = graphic_map.latLngToLayerPoint(baseLatLng);
            const size = estimateLabelSize(text);

            // Candidate offsets (in pixels) around the point
            const candidates = [
                { dx: 10, dy: -size.height - 4 },                    // NE, above
                { dx: -size.width - 10, dy: -size.height - 4 },     // NW, above
                { dx: 10, dy: 4 },                                  // SE, below
                { dx: -size.width - 10, dy: 4 }                     // SW, below
            ];

            function makeRect(pt) {
                return { x: pt.x, y: pt.y, w: size.width, h: size.height };
            }

            function isFree(rect) {
                for (let i = 0; i < labelBBoxes.length; i++) {
                    if (rectsOverlap(rect, labelBBoxes[i])) {
                        return false;
                    }
                }
                return true;
            }

            let chosenPt = L.point(basePt.x + 12, basePt.y - size.height - 4);
            let chosenRect = makeRect(chosenPt);

            for (let i = 0; i < candidates.length; i++) {
                const cand = candidates[i];
                const pt = L.point(basePt.x + cand.dx, basePt.y + cand.dy);
                const rect = makeRect(pt);
                if (isFree(rect)) {
                    chosenPt = pt;
                    chosenRect = rect;
                    break;
                }
            }

            const labelLatLng = graphic_map.layerPointToLatLng(chosenPt);

            // connector goes to the edge of the label box nearest the point center
            const edgePtRaw = connectorPointOnRectEdge(basePt, chosenRect);
            const edgePt = L.point(edgePtRaw.x, edgePtRaw.y);
            const connectorLatLng = graphic_map.layerPointToLatLng(edgePt);

            labelBBoxes.push(chosenRect);

            return {
                labelLatLng: labelLatLng,
                connectorLatLng: connectorLatLng,
                rect: chosenRect
            };
        }


        function buildLabelKey(name, lat, lon) {
            const id = (name || '').toString().toUpperCase();
            const la = Number(lat);
            const lo = Number(lon);
            if (!isFinite(la) || !isFinite(lo)) {
                return id;
            }
            return id + '|' + la.toFixed(6) + ',' + lo.toFixed(6);
        }

        function parseHexColor(color) {
            if (!color || typeof color !== 'string') return null;
            let c = color.trim();
            if (c.charAt(0) !== '#') return null;
            c = c.substring(1);
            if (c.length === 3) {
                const r = parseInt(c.charAt(0) + c.charAt(0), 16);
                const g = parseInt(c.charAt(1) + c.charAt(1), 16);
                const b = parseInt(c.charAt(2) + c.charAt(2), 16);
                if (isNaN(r) || isNaN(g) || isNaN(b)) return null;
                return { r: r, g: g, b: b };
            } else if (c.length === 6) {
                const r = parseInt(c.substring(0, 2), 16);
                const g = parseInt(c.substring(2, 4), 16);
                const b = parseInt(c.substring(4, 6), 16);
                if (isNaN(r) || isNaN(g) || isNaN(b)) return null;
                return { r: r, g: g, b: b };
            }
            return null;
        }

        function pickLabelBackground(routeColor) {
            // Always use black background for labels to avoid white boxes
            return '#000000';
        }

        function ensureLabelLayers(name, lat, lon, routeColor) {
            const key = buildLabelKey(name, lat, lon);
            let state = labelStateByKey[key];
            if (!state) {
                state = {
                    visible: false,
                    marker: null,
                    connector: null,
                    color: routeColor
                };
                labelStateByKey[key] = state;
            }

            // Update stored color to latest route color (helps when a point appears on multiple routes)
            if (routeColor) {
                state.color = routeColor;
            }

            if (!state.marker) {
                const displayName = (name || '').toString().toUpperCase();
                const color = state.color || '#C70039';
                const bgColor = pickLabelBackground(color);
                const isGroupLabel = /GROUP/i.test(displayName);
                const fontSize = isGroupLabel ? '10pt' : '8pt';

                const html =
                    "<div style=\"" +
                        "padding:1px 3px;" +
                        "border-radius:2px;" +
                        "background:" + bgColor + ";" +
                        "color:" + color + ";" +
                        "font-family:Consolas, 'Courier New', monospace;" +
                        "font-size:" + fontSize + ";" +
                        "font-weight:bold;" +
                        "white-space:nowrap;" +
                        "box-sizing:border-box;" +
                        "display:flex;" +
                        "align-items:center;" +
                        "justify-content:center;" +
                    "\">" +
                        displayName +
                    "</div>";

                const baseLatLng = L.latLng(lat, lon);
                let labelLatLng = baseLatLng;
                let connectorLatLng = baseLatLng;
                let placementRect = null;

                try {
                    const placement = chooseLabelPlacement(baseLatLng, displayName);
                    labelLatLng = placement.labelLatLng;
                    connectorLatLng = placement.connectorLatLng;
                    placementRect = placement.rect;
                } catch (e) {
                    labelLatLng = baseLatLng;
                    connectorLatLng = baseLatLng;
                }

                const marker = L.marker(labelLatLng, {
                    icon: L.divIcon({
                        className: 'route-label',
                        html: html,
                        iconAnchor: [0, 0]
                    }),
                    interactive: true,
                    draggable: true
                });

                const connector = L.polyline([baseLatLng, connectorLatLng], {
                    color: color,
                    weight: 1,
                    opacity: 1,
                    interactive: false
                });

                state.marker = marker;
                state.connector = connector;
                state.anchorLatLng = baseLatLng;
                state.rect = placementRect;

                // Clicking directly on a label hides it (without affecting the rest of the route)
                marker.on('click', function(e) {
                    try {
                        if (typeof L !== 'undefined' && L.DomEvent && e) {
                            L.DomEvent.stopPropagation(e);
                        }
                    } catch (err) {}
                    hideLabel(name, lat, lon);
                });

                // Once the marker is added to the map, recompute the label rectangle
                // using the actual DOM size so connector hits the true edge.
                marker.on('add', function() {
                    try {
                        const anchorLat = state.anchorLatLng || baseLatLng;
                        const basePt = graphic_map.latLngToLayerPoint(anchorLat);
                        const topLeftPt = graphic_map.latLngToLayerPoint(marker.getLatLng());
                        const icon = marker._icon;
                        if (!icon) return;
                        const width = icon.offsetWidth || icon.getBoundingClientRect().width || 0;
                        const height = icon.offsetHeight || icon.getBoundingClientRect().height || 0;
                        const rect = { x: topLeftPt.x, y: topLeftPt.y, w: width, h: height };
                        state.rect = rect;
                        const edgeRaw = connectorPointOnRectEdge(basePt, rect);
                        const edgePt = L.point(edgeRaw.x, edgeRaw.y);
                        const edgeLatLng = graphic_map.layerPointToLatLng(edgePt);
                        if (state.connector) {
                            state.connector.setLatLngs([anchorLat, edgeLatLng]);
                        }
                    } catch (err) {}
                });

                // Keep connector tied to the point while the label box is dragged
                marker.on('move', function(e) {
                    try {
                        const newLatLng = e.latlng;
                        const anchorLat = state.anchorLatLng || baseLatLng;
                        const basePt = graphic_map.latLngToLayerPoint(anchorLat);
                        const topLeftPt = graphic_map.latLngToLayerPoint(newLatLng);
                        let rect = state.rect;
                        if (!rect) {
                            const icon = marker._icon;
                            let w = 0, h = 0;
                            if (icon) {
                                w = icon.offsetWidth || icon.getBoundingClientRect().width || 0;
                                h = icon.offsetHeight || icon.getBoundingClientRect().height || 0;
                            }
                            rect = { x: topLeftPt.x, y: topLeftPt.y, w: w, h: h };
                        } else {
                            rect = { x: topLeftPt.x, y: topLeftPt.y, w: rect.w, h: rect.h };
                        }
                        state.rect = rect;
                        const edgeRaw = connectorPointOnRectEdge(basePt, rect);
                        const edgePt = L.point(edgeRaw.x, edgeRaw.y);
                        const edgeLatLng = graphic_map.layerPointToLatLng(edgePt);
                        if (state.connector) {
                            state.connector.setLatLngs([anchorLat, edgeLatLng]);
                        }
                    } catch (err) {}
                });
            }

            return { key: key, state: state };
        }

        function showLabel(name, lat, lon, routeColor) {
            const info = ensureLabelLayers(name, lat, lon, routeColor);
            const state = info.state;
            if (!state.visible) {
                if (state.marker) {
                    state.marker.addTo(graphic_map);
                }
                if (state.connector) {
                    state.connector.addTo(graphic_map);
                }
                state.visible = true;
            }
        }

        function hideLabel(name, lat, lon) {
            const key = buildLabelKey(name, lat, lon);
            const state = labelStateByKey[key];
            if (!state) return;
            if (state.marker) {
                graphic_map.removeLayer(state.marker);
            }
            if (state.connector) {
                graphic_map.removeLayer(state.connector);
            }
            state.visible = false;
        }

        function togglePointLabel(name, lat, lon, routeColor) {
            const info = ensureLabelLayers(name, lat, lon, routeColor);
            const state = info.state;
            if (state.visible) {
                hideLabel(name, lat, lon);
            } else {
                showLabel(name, lat, lon, routeColor);
            }
        }


    
    
        
        function toggleAllLabels() {
            try {
                var anyVisible = false;
                for (var key in labelStateByKey) {
                    if (!Object.prototype.hasOwnProperty.call(labelStateByKey, key)) continue;
                    var st = labelStateByKey[key];
                    if (st && st.visible) {
                        anyVisible = true;
                        break;
                    }
                }
                var targetShow = !anyVisible;
                for (var key2 in labelStateByKey) {
                    if (!Object.prototype.hasOwnProperty.call(labelStateByKey, key2)) continue;
                    var state = labelStateByKey[key2];
                    if (!state) continue;
                    var parts = key2.split('|');
                    var nm = parts[0];
                    if (parts.length < 2) continue;
                    var coord = parts[1].split(',');
                    if (coord.length < 2) continue;
                    var la = parseFloat(coord[0]);
                    var lo = parseFloat(coord[1]);
                    if (!isFinite(la) || !isFinite(lo)) continue;
                    if (targetShow) {
                        showLabel(nm, la, lo, state.color);
                    } else {
                        hideLabel(nm, la, lo);
                    }
                }
            } catch (e) {
                if (typeof console !== 'undefined' && console && console.error) {
                    console.error('toggleAllLabels error:', e);
                }
            }
        }
        window.toggleAllLabels = toggleAllLabels;

var CartoDB_Dark = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; <a href="https://carto.com/attributions">CARTO</a> | &copy; <a href="https://mesonet.agron.iastate.edu/">Iowa State University</a> | &copy; <a href="http://web.ics.purdue.edu/~snandaku/">Srinath Nandakumar</a>',
            subdomains: 'abcd'
        }).addTo(graphic_map);
    
        function sigmetBind(feature, layer) {
            if (feature.properties && feature.properties.data) {
                layer.bindPopup(
                    "<b>Center:</b> " + feature.properties.icaoId + "<br>" +
                    "<b>Valid From:</b> " + feature.properties.validTimeFrom + "<br>" +
                    "<b>Valid To:</b> " + feature.properties.validTimeTo + "<br>" +
                    "<b>Top Altitude:</b> " + feature.properties.altitudeHi1 + "<hr>" +
                    "<pre>" + feature.properties.rawAirSigmet + "</pre>"
                , {closeButton: false});
    
                var sigmetSplit = feature.properties.rawAirSigmet.split('\n');
                var sigmetCodeString = sigmetSplit[2].split(' ');
    
                layer.bindTooltip(sigmetCodeString[2], {direction: 'center', offset: L.point(0, 14), permanent: true, className: '0 Incon'});
                layer.openTooltip();
            }
        }
    
        // Build a key for a segment so we can deduplicate overlapping segments
        function buildSegmentKey(coords, options, isAirportFan) {
            if (!coords || coords.length < 2) return '';
    
            const forward = coords.map(function(c) {
                return c[0].toFixed(4) + ',' + c[1].toFixed(4);
            }).join('|');
    
            const reverse = coords.slice().reverse().map(function(c) {
                return c[0].toFixed(4) + ',' + c[1].toFixed(4);
            }).join('|');
    
            const geomPart = forward < reverse ? forward : reverse;
    
            const stylePart = [
                options.color || '',
                options.dashArray || 'solid',
                options.weight || '',
                isAirportFan ? 'fan' : 'main'
            ].join('|');
    
            return stylePart + '||' + geomPart;
        }
    
        // Marker icons (PERTI shapes)
        /**
         * Airport: triangle, ~75% of previous size, centered on coordinates
         */
        var airport_icon = L.divIcon({
            html: '' +
                '<div style="width:15px;height:15px;display:flex;align-items:center;justify-content:center;">' +
                    '<div style="' +
                        'width:0;height:0;' +
                        'border-left:6px solid transparent;' +
                        'border-right:6px solid transparent;' +
                        'border-bottom:10px solid #ffffff;' +
                    '"></div>' +
                '</div>',
            className: '0',
            iconSize: [15, 15],
            iconAnchor: [7.5, 7.5]
        });
    
        /**
         * Navaid: small square, half of previous size, centered
         */
        var navaid_icon = L.divIcon({
            html: '' +
                '<div style="width:6px;height:6px;display:flex;align-items:center;justify-content:center;">' +
                    '<div style="' +
                        'width:4px;height:4px;' +
                        'background:#ffffff;' +
                        'border:1px solid #000000;' +
                        'box-sizing:border-box;' +
                    '"></div>' +
                '</div>',
            className: '0',
            iconSize: [6, 6],
            iconAnchor: [3, 3]
        });
    
        /**
         * ARTCC/FIR center: larger square, unchanged, centered
         */
        var artcc_icon = L.divIcon({
            html: '' +
                '<div style="width:20px;height:20px;display:flex;align-items:center;justify-content:center;">' +
                    '<div style="' +
                        'width:14px;height:14px;' +
                        'background:#ffffff;' +
                        'border:2px solid #000000;' +
                        'box-sizing:border-box;' +
                    '"></div>' +
                '</div>',
            className: '0',
            iconSize: [20, 20],
            iconAnchor: [10, 10]
        });
    
        /**
         * Filtered airway overlay icons – same shapes, resized to match
         */
        var f_airport_icon = L.divIcon({
            html: '' +
                '<div style="width:15px;height:15px;display:flex;align-items:center;justify-content:center;">' +
                    '<div style="' +
                        'width:0;height:0;' +
                        'border-left:6px solid transparent;' +
                        'border-right:6px solid transparent;' +
                        'border-bottom:10px solid #ffffff;' +
                    '"></div>' +
                '</div>',
            className: '0',
            iconSize: [15, 15],
            iconAnchor: [7.5, 7.5]
        });
    
        var f_navaid_icon = L.divIcon({
            html: '' +
                '<div style="width:6px;height:6px;display:flex;align-items:center;justify-content:center;">' +
                    '<div style="' +
                        'width:4px;height:4px;' +
                        'background:#ffffff;' +
                        'border:1px solid #000000;' +
                        'box-sizing:border-box;' +
                    '"></div>' +
                '</div>',
            className: '0',
            iconSize: [6, 6],
            iconAnchor: [3, 3]
        });
    
        // OVERLAYS (S)
    
        let superhigh_splits = new L.geoJson(null, {style: {"color": "#303030", "weight": 1.5, "opacity": 1, "fillOpacity": 0}});
        let high_splits = new L.geoJson(null, {style: {"color": "#303030", "weight": 1.5, "opacity": 1, "fillOpacity": 0}});
        let low_splits  = new L.geoJson(null, {style: {"color": "#303030", "weight": 1.5, "opacity": 1, "fillOpacity": 0}});
        let tracon      = new L.geoJson(null, {
            style: {"color": "#303030", "weight": 1.5, "opacity": 1, "fillOpacity": 0},
            onEachFeature: function(feature, layer) {
                registerFacilityFeature(feature, layer);
            }
        });
    
        $.ajax({
            type: 'GET',
            url: 'assets/geojson/superhigh.json',
            async: false
        }).done(function(data) {
            $(data.features).each(function(key, data) {
                superhigh_splits.addData(data);
            });
        });

        $.ajax({
            type: 'GET',
            url: 'assets/geojson/high.json',
            async: false
        }).done(function(data) {
            $(data.features).each(function(key, data) {
                high_splits.addData(data);
            });
        });
    
        $.ajax({
            type: 'GET',
            url: 'assets/geojson/low.json',
            async: false
        }).done(function(data) {
            $(data.features).each(function(key, data) {
                low_splits.addData(data);
            });
        });
    
        $.ajax({
            type: 'GET',
            url: 'assets/geojson/tracon.json',
            async: false
        }).done(function(data) {
            $(data.features).each(function(key, data) {
                tracon.addData(data);
            });
        });
    
        // SIGMETs (START)
        var sigmets = L.geoJson(null, {
            onEachFeature: sigmetBind,
            style: {
                "fillColor": "#d8da5b",
                "color": "#fbff00",
                "weight": 2,
                "opacity": 0.5,
                "fillOpacity": 0.1
            }
        });
    
        $.ajax({
            type: 'GET',
            url: 'api/data/sigmets',
            async: false
        }).done(function(result) {
            // sigmets.addData(JSON.parse(result))
        });
        //SIGMETs (END)
    
        // WX Cells (START) - Iowa Environmental Mesonet NEXRAD Radar
        var wxRadarLayer = L.tileLayer('https://mesonet.agron.iastate.edu/cache/tile.py/1.0.0/nexrad-n0q/{z}/{x}/{y}.png', {
            tileSize: 256,
            opacity: 0.3,
            attribution: '&copy; <a href="https://mesonet.agron.iastate.edu">Iowa Environmental Mesonet</a>'
        });
        
        // Layer group wrapper for the overlay control
        var cells = L.layerGroup([wxRadarLayer]);
        
        // Auto-refresh radar every 5 minutes by replacing the tile layer
        setInterval(function() {
            if (graphic_map.hasLayer(cells)) {
                var cacheBust = Date.now();
                var newLayer = L.tileLayer('https://mesonet.agron.iastate.edu/cache/tile.py/1.0.0/nexrad-n0q/{z}/{x}/{y}.png?_=' + cacheBust, {
                    tileSize: 256,
                    opacity: 0.3,
                    attribution: '&copy; <a href="https://mesonet.agron.iastate.edu">Iowa Environmental Mesonet</a>'
                });
                cells.removeLayer(wxRadarLayer);
                wxRadarLayer = newLayer;
                cells.addLayer(wxRadarLayer);
                console.log('[WX] Iowa Mesonet radar refreshed');
            }
        }, 300000); // 5 minutes
        
        console.log('[WX] Iowa Mesonet NEXRAD radar initialized');
        // WX Cells (END)
    
        // ARTCC boundaries (always on, also registered for areaCenters)
        let artcc = new L.geoJson(null, {
            style: {"color": "#515151", "weight": 1.5, "opacity": 1, "fillOpacity": 0},
            onEachFeature: function(feature, layer) {
                registerFacilityFeature(feature, layer);
            }
        }).addTo(graphic_map);
    
        $.ajax({
            type: 'GET',
            url: 'assets/geojson/artcc.json',
            async: false
        }).done(function(data) {
            $(data.features).each(function(key, data) {
                artcc.addData(data);
            });
        });
    
        // ═══════════════════════════════════════════════════════════════════════════
        // SUA (Special Use Airspace) Layer - TFRs, MOAs, Restricted, etc.
        // Data from FAA sua.faa.gov
        // ═══════════════════════════════════════════════════════════════════════════
        
        // SUA color mapping by type
        var SUA_COLORS = {
            'TFR_VIP':   { fill: 'magenta',    stroke: 'magenta',    weight: 3 },
            'TFR_EMERG': { fill: 'red',        stroke: 'red',        weight: 3 },
            'TFR_SEC':   { fill: 'red',        stroke: 'red',        weight: 3 },
            'TFR_HAZ':   { fill: 'DarkOrange', stroke: 'DarkOrange', weight: 3 },
            'TFR_HID':   { fill: 'DarkOrange', stroke: 'DarkOrange', weight: 3 },
            'TFR_EVT':   { fill: 'cyan',       stroke: 'cyan',       weight: 3 },
            'TFR_SPC':   { fill: 'turquoise',  stroke: 'turquoise',  weight: 3 },
            'TFR_HBP':   { fill: 'yellow',     stroke: 'yellow',     weight: 3 },
            'UASPG':     { fill: 'purple',     stroke: 'purple',     weight: 2 },
            'P':         { fill: 'magenta',    stroke: 'magenta',    weight: 2 },  // Prohibited
            'R':         { fill: 'red',        stroke: 'red',        weight: 2 },  // Restricted
            'W':         { fill: 'DarkOrange', stroke: 'DarkOrange', weight: 2 },  // Warning
            'A':         { fill: 'yellow',     stroke: 'yellow',     weight: 2 },  // Alert
            'MOA':       { fill: 'DarkViolet', stroke: 'DarkViolet', weight: 2 },
            'ATCAA':     { fill: 'Sienna',     stroke: 'Sienna',     weight: 2 },
            'IR':        { fill: '#C19A6B',    stroke: '#C19A6B',    weight: 2 },  // Instrument Route
            'VR':        { fill: '#C19A6B',    stroke: '#C19A6B',    weight: 2 },  // Visual Route
            'SR':        { fill: 'DarkKhaki',  stroke: 'DarkKhaki',  weight: 2 },  // Slow Route
            'AR':        { fill: 'SteelBlue',  stroke: 'SteelBlue',  weight: 2 },  // Air Refueling
            'OTHER':     { fill: 'gray',       stroke: 'gray',       weight: 1 }
        };
        
        // Style function for SUA features
        function suaStyle(feature) {
            var suaType = (feature.properties && feature.properties.suaType) || 'OTHER';
            var colors = SUA_COLORS[suaType] || SUA_COLORS['OTHER'];
            
            return {
                fillColor: colors.fill,
                color: colors.stroke,
                weight: colors.weight,
                opacity: 0.8,
                fillOpacity: 0.15
            };
        }
        
        // Popup content for SUA features
        function suaPopup(feature, layer) {
            if (feature.properties) {
                var props = feature.properties;
                var content = '<div class="sua-popup">';
                content += '<strong style="font-size: 1.1em;">' + (props.name || 'Unknown SUA') + '</strong>';
                
                if (props.suaType) {
                    var typeLabel = props.suaType.replace('TFR_', 'TFR: ').replace('_', ' ');
                    content += '<br><span class="text-muted small">' + typeLabel + '</span>';
                }
                
                if (props.notamId) {
                    content += '<br><span class="small">NOTAM: ' + props.notamId + '</span>';
                }
                
                if (props.altLow || props.altHigh) {
                    content += '<br><span class="small">ALT: ';
                    if (props.altLow) content += props.altLow;
                    if (props.altLow && props.altHigh) content += ' - ';
                    if (props.altHigh) content += props.altHigh;
                    content += '</span>';
                }
                
                if (props.effectiveStart || props.effectiveEnd) {
                    content += '<br><span class="small">TIME: ';
                    if (props.effectiveStart) content += props.effectiveStart;
                    if (props.effectiveStart && props.effectiveEnd) content += ' - ';
                    if (props.effectiveEnd) content += props.effectiveEnd;
                    content += '</span>';
                }
                
                content += '</div>';
                layer.bindPopup(content, { closeButton: false, maxWidth: 300 });
            }
        }
        
        // Create SUA layer group
        var suaLayer = L.geoJson(null, {
            style: suaStyle,
            onEachFeature: suaPopup
        });
        
        // Fetch SUA data from our API - now integrates with scheduled activations
        var suaDataLoaded = false;
        function loadSuaData() {
            if (suaDataLoaded) return;

            console.log('[SUA] Fetching SUA data with activations...');

            // First, get currently active/scheduled activations
            $.ajax({
                type: 'GET',
                url: 'api/data/sua/activations.php?status=SCHEDULED,ACTIVE&include_geometry=true',
                dataType: 'json',
                timeout: 30000
            }).done(function(activations) {
                var activeIds = new Set();
                var customTfrs = [];

                if (activations && activations.status === 'ok' && activations.data) {
                    // Build set of active SUA IDs and collect custom TFRs
                    activations.data.forEach(function(act) {
                        // Check if currently within time window
                        var now = new Date();
                        var start = new Date(act.start_utc);
                        var end = new Date(act.end_utc);

                        if (now >= start && now <= end) {
                            if (act.sua_id) {
                                activeIds.add(act.sua_id);
                            }
                            // Custom TFRs have geometry but no sua_id
                            if (act.sua_type === 'TFR' && act.geometry && !act.sua_id) {
                                customTfrs.push({
                                    type: 'Feature',
                                    properties: {
                                        name: act.name,
                                        suaType: 'TFR_' + (act.tfr_subtype || 'OTHER'),
                                        designator: 'TFR',
                                        lowerLimit: act.lower_alt,
                                        upperLimit: act.upper_alt,
                                        remarks: act.remarks
                                    },
                                    geometry: act.geometry
                                });
                            }
                        }
                    });
                }

                console.log('[SUA] Found ' + activeIds.size + ' active SUA IDs, ' + customTfrs.length + ' custom TFRs');

                // Now fetch SUA boundaries and filter to active ones
                $.ajax({
                    type: 'GET',
                    url: 'api/data/sua',
                    dataType: 'json',
                    timeout: 60000
                }).done(function(data) {
                    suaLayer.clearLayers();

                    if (data && data.features && data.features.length > 0) {
                        // Filter to only active SUAs
                        var filteredFeatures = data.features.filter(function(f) {
                            var designator = f.properties && f.properties.designator;
                            return designator && activeIds.has(designator);
                        });

                        // Add filtered SUA features
                        if (filteredFeatures.length > 0) {
                            var filteredData = {
                                type: 'FeatureCollection',
                                features: filteredFeatures
                            };
                            suaLayer.addData(filteredData);
                        }

                        // Add custom TFRs
                        if (customTfrs.length > 0) {
                            customTfrs.forEach(function(tfr) {
                                suaLayer.addData(tfr);
                            });
                        }

                        suaDataLoaded = true;
                        console.log('[SUA] Loaded ' + filteredFeatures.length + ' active SUAs, ' + customTfrs.length + ' custom TFRs');
                    } else {
                        // Just add custom TFRs if any
                        if (customTfrs.length > 0) {
                            customTfrs.forEach(function(tfr) {
                                suaLayer.addData(tfr);
                            });
                            suaDataLoaded = true;
                            console.log('[SUA] Loaded ' + customTfrs.length + ' custom TFRs (no base SUA data)');
                        } else {
                            console.warn('[SUA] No active SUA features');
                        }
                    }
                }).fail(function(xhr, status, error) {
                    console.warn('[SUA] Failed to fetch SUA data:', error);
                    // Still try to show custom TFRs
                    if (customTfrs.length > 0) {
                        customTfrs.forEach(function(tfr) {
                            suaLayer.addData(tfr);
                        });
                        console.log('[SUA] Loaded ' + customTfrs.length + ' custom TFRs (SUA fetch failed)');
                    }
                });

            }).fail(function(xhr, status, error) {
                console.warn('[SUA] Failed to fetch activations, falling back to all SUAs:', error);
                // Fallback: load all SUAs without filtering
                $.ajax({
                    type: 'GET',
                    url: 'api/data/sua',
                    dataType: 'json',
                    timeout: 60000
                }).done(function(data) {
                    if (data && data.features && data.features.length > 0) {
                        suaLayer.clearLayers();
                        suaLayer.addData(data);
                        suaDataLoaded = true;
                        console.log('[SUA] Loaded ' + data.features.length + ' SUA features (fallback)');
                    }
                });
            });
        }
        
        // Refresh SUA data every 10 minutes when layer is active
        var suaRefreshInterval = null;
        function startSuaRefresh() {
            if (suaRefreshInterval) return;
            suaRefreshInterval = setInterval(function() {
                if (graphic_map.hasLayer(suaLayer)) {
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
        // SUA Layer (END)
    
        const awy_points = [];
        var filtered_airways = L.layerGroup().addTo(graphic_map);
    
        $('#filter').val().toUpperCase().split(' ').forEach(awy => {
            airwaysDraw(awy);
        });
    
        function airwaysDraw(airwayName) {
            if (airwayName === '') {
                return;
            }
    
            const airwayData = awys.find(a => a[0] === airwayName);
    
            if (!airwayData) {
                return;
            }
    
            const [airwayId, routeString] = airwayData;
            const fixes = routeString.split(' ');
            const pointList = [];
            let previousPointData;
    
            for (let i = 0; i < fixes.length; i++) {
                const pointName = fixes[i];
                let nextPointData;
    
                if (i < fixes.length - 1) {
                    nextPointData = getPointByName(fixes[i + 1], previousPointData, fixes[i]);
                }
    
                const pointData = getPointByName(pointName, previousPointData, nextPointData);
                
                if (!pointData || pointData.length < 3) {
                    console.warn(`Invalid or unreliable fix definition for fix "${pointName}"`);
                    continue;
                }
                
                const [id, lat, lon] = pointData;
                previousPointData = pointData;
    
                awy_points.push(pointData);
                pointList.push([lat, lon]);
            }
    
            if (airwayName.includes('Q') || airwayName.includes('T')) {
                filtered_airways.addLayer(new L.geodesic(pointList, {color: '#7588fd'}).setText('        ' + airwayName + '         ', {center: true, repeat: true, attributes: {fill: '#fff', opacity: 0.6}}));
            } else {
                filtered_airways.addLayer(new L.geodesic(pointList, {color: '#bfbfbf'}).setText('        ' + airwayName + '         ', {center: true, repeat: true, attributes: {fill: '#fff', opacity: 0.6}}));
            }
        }
    
        awy_points.forEach(point => {
            var des = point[0];
            var lat = point[1];
            var lon = point[2];
    
            if (lat && lon) {
                if (des.length < 4) {
                    // NAVAID
                    filtered_airways.addLayer(L.marker([lat, lon], {icon: f_navaid_icon}).bindTooltip(des, {className: 'Incon bg-dark text-light', permanent: false, direction: 'center'}));
                } else if (des.length < 5) {
                    // AIRPORT
                    filtered_airways.addLayer(L.marker([lat, lon], {icon: f_airport_icon}).bindTooltip(des, {className: 'Incon bg-dark text-light', permanent: false, direction: 'center'}));
                } else {
                    filtered_airways.addLayer(L.marker([lat, lon], {icon: f_point_icon}).bindTooltip(des, {className: 'Incon bg-dark text-light', permanent: false, direction: 'center'}));
                }
            }
        });
    
        // Plot all NAVAIDs
        var navaids = L.layerGroup();
    
        $.ajax({
            type: 'GET',
            url: 'assets/data/navaids.csv',
            async: false
        }).done(function(data) {
            let split = data.split('\n');
    
            split.forEach(data => {
                let split = data.split(',');
    
                if (split[1] && split[2]) {
                    navaids.addLayer(L.marker([split[1], split[2]], {icon: navaid_icon, opacity: '0.25'}).bindTooltip(split[0], {className: 'Incon bg-light text-dark', permanent: false, direction: 'center'}));
                }
            });
        });
    
        // Layer order matches nod.js (bottom to top):
        // 1. Weather Radar (BOTTOM)
        // 2. TRACON Boundaries
        // 3. Sector Boundaries (High, Low, Superhigh)
        // 4. SIGMETs
        // 5. NAVAIDs
        // 6. SUA/TFR
        // (ARTCC added separately and brought to front)
        // (Routes and flights added dynamically on top)
        var overlaysArray = [
            ['cells', cells],
            ['tracon', tracon],
            ['high_splits', high_splits],
            ['low_splits', low_splits],
            ['superhigh_splits', superhigh_splits],
            ['sigmets', sigmets],
            ['navaids', navaids],
            ['sua', suaLayer]
        ];
    
        var layers = {
            "Superhigh Splits": superhigh_splits,
            "High Splits": high_splits,
            "Low Splits": low_splits,
            "TRACON Boundaries<hr>": tracon,
            "All NAVAIDs<hr>": navaids,
            "WX Radar": cells,
            "SIGMETs": sigmets,
            "Active SUA/TFR<hr>": suaLayer
        };  
    
        overlaysArray.forEach(o => {
            if (overlays.includes(o[0])) {
                o[1].addTo(graphic_map);
                // Load SUA data when layer is initially enabled
                if (o[0] === 'sua') {
                    loadSuaData();
                    startSuaRefresh();
                }
            }
        });

        // Ensure proper layer order after initial setup: overlays → ARTCC → routes → ADL flights
        artcc.bringToFront();
        if (routesLayerGroup) routesLayerGroup.bringToFront();
        if (fixMarkersLayerGroup) fixMarkersLayerGroup.bringToFront();

        graphic_map.on('overlayadd', function(eventlayer) {
            overlaysArray.forEach(o => {
                if (eventlayer.layer == o[1]) {
                    overlayAdd(o[0]);
                    // Load SUA data when layer is added
                    if (o[0] === 'sua') {
                        loadSuaData();
                        startSuaRefresh();
                    }
                }
            });

            // Maintain layer order: overlays → ARTCC → routes → ADL flights (top)
            artcc.bringToFront();
            if (routesLayerGroup) routesLayerGroup.bringToFront();
            if (fixMarkersLayerGroup) fixMarkersLayerGroup.bringToFront();
            if (typeof ADL !== 'undefined' && ADL.getState && ADL.getState().layer) {
                if (ADL.getState().routeLayer) ADL.getState().routeLayer.bringToFront();
                ADL.getState().layer.bringToFront();
            }
        });

        graphic_map.on('overlayremove', function(eventlayer) {
            overlaysArray.forEach(o => {
                if (eventlayer.layer == o[1]) {
                    overlayRemove(o[0]);
                    // Stop SUA refresh when layer is removed
                    if (o[0] === 'sua') {
                        stopSuaRefresh();
                    }
                }
            });

            // Maintain layer order: overlays → ARTCC → routes → ADL flights (top)
            artcc.bringToFront();
            if (routesLayerGroup) routesLayerGroup.bringToFront();
            if (fixMarkersLayerGroup) fixMarkersLayerGroup.bringToFront();
            if (typeof ADL !== 'undefined' && ADL.getState && ADL.getState().layer) {
                if (ADL.getState().routeLayer) ADL.getState().routeLayer.bringToFront();
                ADL.getState().layer.bringToFront();
            }
        });
    
        L.control.layers(null, layers).addTo(graphic_map);
        // OVERLAYS (E)
    
        // Route (S)
        // Route (S)
        // ═══════════════════════════════════════════════════════════════════════
        // OPTIMIZED ConvertRoute: O(1) airway lookup with pre-parsed fix arrays
        // Previous: O(n) findIndex + O(m) indexOf per airway = ~1.5M ops for 100 routes
        // Now: O(1) object lookup + O(1) position map lookup = ~3K ops for 100 routes
        // ═══════════════════════════════════════════════════════════════════════
        function ConvertRoute(route) {
            if (!route || typeof route !== 'string') {
                return route;
            }

            // Lazy-build airway index on first use
            if (!awyIndexBuilt) {
                buildAirwayIndex();
            }

            const split_route = route.split(' ').filter(t => t !== '');
            if (split_route.length < 3) {
                return route;
            }

            const expandedTokens = [];

            for (let i = 0; i < split_route.length; i++) {
                const point = split_route[i];
                const pointUpper = point.toUpperCase();

                // Only attempt airway expansion if there is a previous and next token
                if (i > 0 && i < split_route.length - 1) {
                    const prevTok = split_route[i - 1].toUpperCase();
                    const nextTok = split_route[i + 1].toUpperCase();

                    // OPTIMIZATION: O(1) lookup instead of O(n) findIndex
                    const airwayData = awyIndexMap[pointUpper];

                    if (airwayData) {
                        const fixes = airwayData.fixes;
                        const fixPosMap = airwayData.fixPosMap;

                        // OPTIMIZATION: O(1) position lookup instead of O(m) indexOf
                        const fromIdx = fixPosMap[prevTok];
                        const toIdx = fixPosMap[nextTok];

                        // Only expand if both endpoints are present and separated
                        if (fromIdx !== undefined && toIdx !== undefined && Math.abs(fromIdx - toIdx) > 1) {
                            let middleFixes;
                            if (fromIdx < toIdx) {
                                // Same direction as airway definition
                                middleFixes = fixes.slice(fromIdx + 1, toIdx);
                            } else {
                                // Opposite direction: reverse the slice
                                middleFixes = fixes.slice(toIdx + 1, fromIdx).reverse();
                            }

                            // Insert intermediate fixes (avoid forEach for micro-optimization)
                            for (let j = 0; j < middleFixes.length; j++) {
                                expandedTokens.push(middleFixes[j]);
                            }
                            continue; // skip pushing `point` itself
                        }
                    }
                }

                // Default: keep the original token
                expandedTokens.push(point);
            }

            return expandedTokens.join(' ');
        }

    
        const baseRouteWeight = 3;

        const rawInput = $('#routeSearch').val();
    
        const route_lat_long_for_indiv = [];
        // Use module-level layer groups for z-ordering control
        // Clear and recreate each time routes are drawn
        if (routesLayerGroup && graphic_map) {
            graphic_map.removeLayer(routesLayerGroup);
        }
        if (fixMarkersLayerGroup && graphic_map) {
            graphic_map.removeLayer(fixMarkersLayerGroup);
        }
        routesLayerGroup = new L.layerGroup();
        fixMarkersLayerGroup = new L.layerGroup();
        const linestring = routesLayerGroup; // Alias for backward compatibility
        const fixMarkersGroup = fixMarkersLayerGroup; // Alias for backward compatibility
        const multipoint = {
            type: 'FeatureCollection',
            features: []
        };
    
        let routeStrings = [];
    
        // If input contains an advisory, use the advisory parsing path (no PB.* handling here)
        if (/VATCSCC ADVZY/i.test(rawInput)) {
            const advBlocks = splitAdvisoriesFromInput(rawInput.toUpperCase());
            advBlocks.forEach(block => {
                const advRoutes = parseAdvisoryRoutes(block);
                if (advRoutes && advRoutes.length) {
                    routeStrings = routeStrings.concat(advRoutes);
                }
            });
        } else {
            // Pre-process for group headers, then line-by-line parsing with PB.* support
            const expanded = expandGroupsInRouteInput(rawInput);
            const linesRaw = expanded.split(/\r?\n/);
    
            linesRaw.forEach(line => {
                const trimmed = line.trim();
                if (!trimmed) return;
    
                // Optional color: everything after first ';'
                let body = trimmed;
                let color = null;
                const semiIdx = trimmed.indexOf(';');
                if (semiIdx !== -1) {
                    body = trimmed.slice(0, semiIdx).trim();
                    const colorPart = trimmed.slice(semiIdx + 1).trim();
                    if (colorPart) {
                        color = colorPart.toUpperCase();
                    }
                }
    
                // If body is wrapped with ><, treat as "mandatory" for PB.* routes
                let isMandatory = false;
                let spec = body;
                if (spec.startsWith('>') && spec.endsWith('<')) {
                    isMandatory = true;
                    spec = spec.slice(1, -1).trim();
                }
    
                const specUpper = spec.toUpperCase();
    
                if (specUpper.startsWith('PB.')) {
                    // Playbook directive
                    const bodyUpper = specUpper.slice(3).trim(); // everything after "PB."
                    const pbRoutes = expandPlaybookDirective(bodyUpper, isMandatory, color);
    
                    if (pbRoutes && pbRoutes.length) {
                        routeStrings = routeStrings.concat(pbRoutes);
                    } else {
                        console.warn('No playbook routes matched for', spec);
                    }
                } else {
                    // Normal manual route line. If the original body was wrapped in ><,
                    // re-apply the wrapper here so mandatory segments work for non-PB lines.
                    let rteBody = spec.toUpperCase();
                    if (isMandatory && !(rteBody.startsWith('>') && rteBody.endsWith('<'))) {
                        rteBody = '>' + rteBody + '<';
                    }
                    let rte = rteBody;
                    if (color) {
                        rte = rte + ';' + color;
                    }
                    routeStrings.push(rte);
                }
            });
        }
    
        // Store expanded routes for public routes feature
        lastExpandedRoutes = routeStrings.map(function(rte) {
            // Strip color suffix for storage
            var semiIdx = (rte || '').indexOf(';');
            return semiIdx !== -1 ? rte.slice(0, semiIdx).trim() : (rte || '').trim();
        }).filter(function(r) { return r; });

        // For deduplicating drawn segments (geometry + style)
        const seenSegmentKeys = new Set();
    
        routeStrings.forEach(rte => {
            if (rte.trim() === '') {
                return;
            }
    
            rte = rte.replace(/\s+/g, ' ').trim();
    
            let routeColor = '#C70039';
            let routeText  = rte;
    
            if (rte.includes(';')) {
                const parts = rte.split(';');
                routeText = parts[0].trim();
                if (parts[1]) {
                    routeColor = parts[1].trim();
                }
            }
    
            // --- CDR expansion: ACKMKEN0 -> KACK LFV ... KMKE, etc. ---
            const cdrTokens = routeText.split(/\s+/).filter(t => t !== '');
            if (cdrTokens.length === 1) {
                const code = cdrTokens[0].toUpperCase();
                if (cdrMap[code]) {
                    // Entire line is a single CDR code: replace with full route
                    routeText = cdrMap[code].toUpperCase();
                }
            } else if (cdrTokens.length > 1) {
                // Inline CDR token(s) within a larger route string
                let expandedTokens = [];
                cdrTokens.forEach(tok => {
                    const code = tok.toUpperCase();
                    if (cdrMap[code]) {
                        const rtoks = cdrMap[code].toUpperCase().split(/\s+/).filter(t => t !== '');
                        expandedTokens = expandedTokens.concat(rtoks);
                    } else {
                        expandedTokens.push(code);
                    }
                });
                routeText = expandedTokens.join(' ');
            }
            // --- end CDR expansion ---
    
            const rawTokens        = routeText.split(' ').filter(t => t !== '');
            let origTokensClean  = [];
            let solidMask        = [];
            let insideSolid        = false;
    
            rawTokens.forEach(tok => {
                const cleanTok = tok.replace(/[<>]/g, '');
                if (!cleanTok) {
                    return;
                }
    
                const hasOpen  = tok.indexOf('>') !== -1;
                const hasClose = tok.indexOf('<') !== -1;
    
                let solid;
    
                if (hasOpen && hasClose) {
                    solid = true;
                } else if (hasOpen) {
                    insideSolid = true;
                    solid = true;
                } else if (hasClose) {
                    solid = true;
                    insideSolid = false;
                } else {
                    solid = insideSolid;
                }
    
                origTokensClean.push(cleanTok.toUpperCase());
                solidMask.push(solid);
            });
    
            if (origTokensClean.length === 0) {
                    return;
                }

                // ═══════════════════════════════════════════════════════════════════
                // ENHANCED DP/STAR PROCESSING (procs_enhanced.js)
                // Handles: combined tokens, missing dots, version inference, fan routes
                // ═══════════════════════════════════════════════════════════════════
                let procInfo = null;
                let fanRoutes = [];

                if (typeof preprocessRouteProcedures === 'function') {
                    // Use enhanced preprocessing for better DP/STAR detection
                    procInfo = preprocessRouteProcedures(origTokensClean);
                    if (procInfo && procInfo.tokens && procInfo.tokens.length) {
                        origTokensClean = procInfo.tokens;
                    }
                }

                if (typeof expandRouteProcedures === 'function') {
                    // Use enhanced expansion that handles both DP and STAR together
                    const expanded = expandRouteProcedures(origTokensClean, solidMask, procInfo);
                    if (expanded && expanded.tokens && expanded.tokens.length) {
                        origTokensClean = expanded.tokens;
                        solidMask = expanded.solidMask || solidMask;
                        fanRoutes = expanded.fanRoutes || [];
                    }
                } else {
                    // Fallback to legacy DP/STAR expansion
                    // Normalize any SID/DP tokens (wrong number / "#" placeholder -> current DP_COMPUTER_CODE)
                    if (typeof applyDepartureProcedures === 'function') {
                        origTokensClean = applyDepartureProcedures(origTokensClean);
                    }

                    // Expand those DP tokens into their waypoint sequences from DP_RTE.csv
                    if (typeof expandDepartureProcedures === 'function' && dpDataLoaded) {
                        const dpExpanded = expandDepartureProcedures(origTokensClean, solidMask);
                        if (dpExpanded && dpExpanded.tokens && dpExpanded.tokens.length) {
                            origTokensClean = dpExpanded.tokens;
                            solidMask       = dpExpanded.solidMask;
                        }
                    }

                    // STAR expansion: expand any STAR/transition tokens (e.g. SLT.FQM3, BCE.BLAID2)
                    if (typeof expandStarProcedures === 'function' && starDataLoaded) {
                        const starExpanded = expandStarProcedures(origTokensClean, solidMask);
                        if (starExpanded && starExpanded.tokens && starExpanded.tokens.length) {
                            origTokensClean = starExpanded.tokens;
                            solidMask       = starExpanded.solidMask;
                        }
                    }
                }

                const baseRouteString      = origTokensClean.join(' ');
                const starExpandedRouteString = origTokensClean.join(' ');
            const expandedRouteString  = ConvertRoute(starExpandedRouteString);
            const expandedTokens       = expandedRouteString.split(' ').filter(t => t !== '');

    
            const expandedToOriginalIdx = new Array(expandedTokens.length).fill(null);
            let searchStart = 0;
    
            for (let t = 0; t < expandedTokens.length; t++) {
                const tok = expandedTokens[t];
                let foundAt = -1;
    
                for (let j = searchStart; j < origTokensClean.length; j++) {
                    if (origTokensClean[j] === tok) {
                        foundAt = j;
                        break;
                    }
                }
    
                if (foundAt !== -1) {
                    expandedToOriginalIdx[t] = foundAt;
                    searchStart = foundAt + 1;
                }
            }
    
            const solidExpanded = new Array(expandedTokens.length).fill(false);
    
            for (let t = 0; t < expandedTokens.length; t++) {
                const oi = expandedToOriginalIdx[t];
                if (oi !== null && oi !== -1) {
                    solidExpanded[t] = solidMask[oi];
                }
            }
    
            let idx = 0;
            while (idx < expandedTokens.length) {
                if (expandedToOriginalIdx[idx] !== null && expandedToOriginalIdx[idx] !== -1) {
                    idx++;
                    continue;
                }
    
                const runStart = idx;
                while (
                    idx < expandedTokens.length &&
                    (expandedToOriginalIdx[idx] === null || expandedToOriginalIdx[idx] === -1)
                ) {
                    idx++;
                }
                const runEnd = idx - 1;
    
                let before = runStart - 1;
                while (
                    before >= 0 &&
                    (expandedToOriginalIdx[before] === null || expandedToOriginalIdx[before] === -1)
                ) {
                    before--;
                }
    
                let after = idx;
                while (
                    after < expandedTokens.length &&
                    (expandedToOriginalIdx[after] === null || expandedToOriginalIdx[after] === -1)
                ) {
                    after++;
                }
    
                let runSolid = false;
                if (before >= 0 && after < expandedTokens.length) {
                    runSolid = solidExpanded[before] && solidExpanded[after];
                } else if (before >= 0) {
                    runSolid = solidExpanded[before];
                } else if (after < expandedTokens.length) {
                    runSolid = solidExpanded[after];
                }
    
                for (let k = runStart; k <= runEnd; k++) {
                    solidExpanded[k] = runSolid;
                }
            }
    
            var route_lat_long = [];
            let previousPointData;
            const routePointsForThisRoute = [];
            const routeExpandedIndex      = [];
    
            for (let i = 0; i < expandedTokens.length; i++) {
                const pointName = expandedTokens[i].toUpperCase();
                let nextPointData;
    
                if (i < expandedTokens.length - 1) {
                    let dataForCurrentFix;
                    if (countOccurrencesOfPointName(pointName) === 1) {
                        dataForCurrentFix = getPointByName(pointName);
                    }
    
                    nextPointData = getPointByName(expandedTokens[i + 1], previousPointData, dataForCurrentFix);
                }
    
                // SID/STAR shorthand like BIGGY5, CAMRN4, etc.
                if (pointName.length === 6 && /\d/.test(pointName)) {
                    let procedureRootName = pointName.slice(0, -1).toUpperCase();
    
                    if (!(procedureRootName in points)) {
                        const zzProc = 'ZZ_' + procedureRootName;
                        if (!(zzProc in points)) {
                            continue;
                        }
                    }
    
                    const rootPointData = getPointByName(procedureRootName, previousPointData, nextPointData);
    
                    if (!rootPointData || rootPointData.length < 3) {
                        console.warn(`Invalid or unreliable fix definition for fix "${procedureRootName}"`);
                        continue;
                    }
    
                    const [id, lat, lon] = rootPointData;
                    previousPointData = rootPointData;
    
                    route_lat_long_for_indiv.push(rootPointData);
                    route_lat_long.push([lat, lon]);
                    routePointsForThisRoute.push(rootPointData);
                    routeExpandedIndex.push(i);
    
                    continue;
                }
    
                const pointData = getPointByName(pointName, previousPointData, nextPointData);
    
                if (!pointData || pointData.length < 3) {
                    // Fallback: TRACON/ARTCC centers from polygons
                    const areaCode = pointName.toUpperCase();
                    if (areaCenters[areaCode]) {
                        const center = areaCenters[areaCode];
                        const areaPointData = [areaCode, center.lat, center.lon];
    
                        previousPointData = areaPointData;
    
                        route_lat_long_for_indiv.push(areaPointData);
                        route_lat_long.push([center.lat, center.lon]);
                        routePointsForThisRoute.push(areaPointData);
                        routeExpandedIndex.push(i);
    
                        continue;
                    }
    
                    // Suppress duplicate warnings
                    if (!warnedFixes.has(pointName)) {
                        console.warn(`Can't find fix "${pointName}"!`);
                        warnedFixes.add(pointName);
                    }
                    continue;
                }
    
                const [id, lat, lon] = pointData;
                previousPointData = pointData;
    
                route_lat_long_for_indiv.push(pointData);
                route_lat_long.push([lat, lon]);
                routePointsForThisRoute.push(pointData);
                routeExpandedIndex.push(i);

                if (!isAirportIdent(id)) {
                    const fixMarker = L.circleMarker([lat, lon], {
                        radius: baseRouteWeight * 0.6,
                        color: routeColor,
                        fillColor: routeColor,
                        weight: 1,
                        fillOpacity: 1
                    });
                    fixMarkersGroup.addLayer(fixMarker); // Add to group, not directly to map

                    // Clicking on a fix/NAVAID point toggles its label visibility
                    const labelKey = buildLabelKey(id, lat, lon);
                    if (!labelMetaByKey[labelKey]) {
                        labelMetaByKey[labelKey] = { name: id, lat: lat, lon: lon };
                    }
                    const colorForPoint = pointRouteColorByKey[labelKey] || routeColor;
                    fixMarker.on('click', function() {
                        togglePointLabel(id, lat, lon, colorForPoint);
                    });
                }
            }
    
            const segmentsToDraw = [];
            const nPoints = routePointsForThisRoute.length;
    
            if (nPoints >= 2) {
                let firstNavIndex = 0;
                while (firstNavIndex < nPoints && isAirportIdent(routePointsForThisRoute[firstNavIndex][0])) {
                    firstNavIndex++;
                }
    
                let lastNavIndex = nPoints - 1;
                while (lastNavIndex >= 0 && isAirportIdent(routePointsForThisRoute[lastNavIndex][0])) {
                    lastNavIndex--;
                }
    
                const hasNonAirport       = firstNavIndex <= lastNavIndex && lastNavIndex >= 0;
                const hasLeadingAirports  = hasNonAirport && firstNavIndex > 0;
                const hasTrailingAirports = hasNonAirport && lastNavIndex < nPoints - 1;
    
                if (hasNonAirport && (hasLeadingAirports || hasTrailingAirports)) {
                    const firstNavPoint = routePointsForThisRoute[firstNavIndex];
                    const lastNavPoint  = routePointsForThisRoute[lastNavIndex];
    
                    const firstNavLat = firstNavPoint[1];
                    const firstNavLon = firstNavPoint[2];
                    const lastNavLat  = lastNavPoint[1];
                    const lastNavLon  = lastNavPoint[2];
    
                    if (hasLeadingAirports) {
                        for (let i = 0; i < firstNavIndex; i++) {
                            const ap = routePointsForThisRoute[i];
                            segmentsToDraw.push({
                                coords: [
                                    [ap[1], ap[2]],
                                    [firstNavLat, firstNavLon]
                                ],
                                solid: false,
                                isAirportFan: true
                            });
                        }
                    }
    
                    if (lastNavIndex > firstNavIndex) {
                        let currentCoords = [];
                        let currentSolid  = null;
    
                        for (let i = firstNavIndex; i < lastNavIndex; i++) {
                            const fromPt   = routePointsForThisRoute[i];
                            const toPt     = routePointsForThisRoute[i + 1];
                            const idxFrom  = routeExpandedIndex[i];
                            const idxTo    = routeExpandedIndex[i + 1];
                            const segSolid =
                                solidExpanded[idxFrom] && solidExpanded[idxTo];
    
                            if (currentSolid === null || segSolid !== currentSolid || currentCoords.length === 0) {
                                if (currentCoords.length >= 2) {
                                    segmentsToDraw.push({
                                        coords: currentCoords.slice(),
                                        solid: currentSolid,
                                        isAirportFan: false
                                    });
                                }
    
                                currentCoords = [
                                    [fromPt[1], fromPt[2]],
                                    [toPt[1], toPt[2]]
                                ];
                                currentSolid = segSolid;
                            } else {
                                currentCoords.push([toPt[1], toPt[2]]);
                            }
                        }
    
                        if (currentCoords.length >= 2) {
                            segmentsToDraw.push({
                                coords: currentCoords,
                                solid: currentSolid,
                                isAirportFan: false
                            });
                        }
                    }
    
                    if (hasTrailingAirports) {
                        for (let i = lastNavIndex + 1; i < nPoints; i++) {
                            const ap = routePointsForThisRoute[i];
                            segmentsToDraw.push({
                                coords: [
                                    [lastNavLat, lastNavLon],
                                    [ap[1], ap[2]]
                                ],
                                solid: false,
                                isAirportFan: true
                            });
                        }
                    }
                } else {
                    let currentCoords = [];
                    let currentSolid  = null;
    
                    for (let i = 0; i < nPoints - 1; i++) {
                        const fromPt  = routePointsForThisRoute[i];
                        const toPt    = routePointsForThisRoute[i + 1];
                        const idxFrom = routeExpandedIndex[i];
                        const idxTo   = routeExpandedIndex[i + 1];
                        const segSolid =
                            solidExpanded[idxFrom] && solidExpanded[idxTo];
    
                        if (currentSolid === null || segSolid !== currentSolid || currentCoords.length === 0) {
                            if (currentCoords.length >= 2) {
                                segmentsToDraw.push({
                                    coords: currentCoords.slice(),
                                    solid: currentSolid,
                                    isAirportFan: false
                                });
                            }
    
                            currentCoords = [
                                [fromPt[1], fromPt[2]],
                                [toPt[1], toPt[2]]
                            ];
                            currentSolid = segSolid;
                        } else {
                            currentCoords.push([toPt[1], toPt[2]]);
                        }
                    }
    
                    if (currentCoords.length >= 2) {
                        segmentsToDraw.push({
                            coords: currentCoords,
                            solid: currentSolid,
                            isAirportFan: false
                        });
                    }
                }
            }
    


            const pointIdByLatLonKey = {};
            const routeLabelKeysForThisRoute = [];
            const routeLabelKeysSeen = new Set();

            routePointsForThisRoute.forEach(function(pt) {
                const id  = (pt[0] || '').toString().toUpperCase();
                const lat = Number(pt[1]);
                const lon = Number(pt[2]);
                const latLonKey = lat.toFixed(6) + ',' + lon.toFixed(6);

                pointIdByLatLonKey[latLonKey] = id;

                const labelKey = buildLabelKey(id, lat, lon);

                if (!labelMetaByKey[labelKey]) {
                    labelMetaByKey[labelKey] = { name: id, lat: lat, lon: lon };
                }
                pointRouteColorByKey[labelKey] = routeColor;

                if (!routeLabelKeysSeen.has(labelKey)) {
                    routeLabelKeysSeen.add(labelKey);
                    routeLabelKeysForThisRoute.push(labelKey);
                }
            });

// Draw all segments for this route, deduplicating overlapping geometry+style
            segmentsToDraw.forEach(seg => {
                if (!seg || !seg.coords || seg.coords.length < 2) {
                    return;
                }

                const normalWeight = baseRouteWeight;
                const fanWeight    = normalWeight / 2;

                // Determine if this segment is part of a DP body based on its endpoints
                const start = seg.coords[0];
                const end   = seg.coords[seg.coords.length - 1];

                const startKey = Number(start[0]).toFixed(6) + ',' + Number(start[1]).toFixed(6);
                const endKey   = Number(end[0]).toFixed(6)   + ',' + Number(end[1]).toFixed(6);

                const startId = pointIdByLatLonKey[startKey];
                const endId   = pointIdByLatLonKey[endKey];

                
                let weight;
                let options;

                // We no longer differentiate DP body segments with special symbology;
                // all non-airport-fan segments use the standard mandatory / dashed styles.
                weight = seg.isAirportFan ? fanWeight : normalWeight;

                if (seg.solid) {
                    // Mandatory segment inside > < : solid
                    options = { color: routeColor, weight: weight };
                } else if (seg.isAirportFan) {
                    // Connector from airport/TRACON/ARTCC to route: dotted
                    options = { color: routeColor, dashArray: '2, 6', weight: weight };
                } else {
                    // Non-mandatory route segment: dashed
                    options = { color: routeColor, dashArray: '8, 8', weight: weight };
                }

                const key = buildSegmentKey(seg.coords, options, seg.isAirportFan);
                if (seenSegmentKeys.has(key)) {
                    return;
                }
                seenSegmentKeys.add(key);

                // Calculate segment distance to decide rendering method
                const from = seg.coords[0];
                const to = seg.coords[seg.coords.length - 1];
                const latDiff = Math.abs(to[0] - from[0]);
                const lonDiff = Math.abs(to[1] - from[1]);
                const approxDist = Math.sqrt(latDiff * latDiff + lonDiff * lonDiff);
                
                let line;
                if (approxDist < 3) {
                    // Short segments: use simple polyline (faster, no great-circle calc)
                    line = L.polyline(seg.coords, options);
                } else {
                    // Longer segments: use geodesic with reduced steps for performance
                    options.steps = approxDist < 10 ? 8 : 12;
                    line = new L.geodesic(seg.coords, options);
                }
                
                // Attach click handler: toggle labels for all points on this route
                line._routeLabelKeys = routeLabelKeysForThisRoute;

                line.on('click', function() {
                    if (!this._routeLabelKeys || !this._routeLabelKeys.length) {
                        return;
                    }
                    this._routeLabelKeys.forEach(function(labelKey) {
                        const meta = labelMetaByKey[labelKey];
                        if (!meta) return;
                        const colorForPoint = pointRouteColorByKey[labelKey] || routeColor;
                        togglePointLabel(meta.name, meta.lat, meta.lon, colorForPoint);
                    });
                });



                linestring.addLayer(line);
            });

            // ═══════════════════════════════════════════════════════════════════
            // DRAW FAN ROUTES for inferred origins/destinations from DP/STAR
            // ═══════════════════════════════════════════════════════════════════
            if (fanRoutes && fanRoutes.length) {
                const fanWeight = baseRouteWeight / 2;
                fanRoutes.forEach(fan => {
                    if (!fan || !fan.from || !fan.to) return;

                    // Get coordinates for the from/to points
                    let fromData = null;
                    let toData = null;

                    // Try to find the points in our data
                    const fromUpper = fan.from.toUpperCase();
                    const toUpper = fan.to.toUpperCase();

                    if (points[fromUpper] && points[fromUpper].length) {
                        fromData = points[fromUpper][0];
                    } else if (areaCenters[fromUpper]) {
                        fromData = [fromUpper, areaCenters[fromUpper].lat, areaCenters[fromUpper].lon];
                    }

                    if (points[toUpper] && points[toUpper].length) {
                        toData = points[toUpper][0];
                    } else if (areaCenters[toUpper]) {
                        toData = [toUpper, areaCenters[toUpper].lat, areaCenters[toUpper].lon];
                    }

                    if (!fromData || !toData) return;

                    const fanCoords = [
                        [fromData[1], fromData[2]],
                        [toData[1], toData[2]]
                    ];

                    const fanOptions = {
                        color: routeColor,
                        dashArray: '2, 6',  // Dotted for fan connectors
                        weight: fanWeight
                    };

                    const fanKey = buildSegmentKey(fanCoords, fanOptions, true);
                    if (!seenSegmentKeys.has(fanKey)) {
                        seenSegmentKeys.add(fanKey);
                        // Fan routes are typically short - use simple polyline
                        const fanLine = L.polyline(fanCoords, fanOptions);
                        linestring.addLayer(fanLine);
                    }
                });
            }
        });
    
        // Place markers and build multipoint GeoJSON
        route_lat_long_for_indiv.forEach(point => {
            var des = point[0];
            var lat = point[1];
            var lon = point[2];
    
            multipoint.features.push({
                type: 'Feature',
                properties: {name: des},
                geometry: {type: 'Point', coordinates: [Number(lon), Number(lat)]}
            });
    
            const desUpper = String(des).toUpperCase();
    
            // Show markers for airports/TRACONs/ARTCCs:
            // - 3–4 char codes (original behavior)
            // - plus ZZ_* ARTCC/FIR centerpoints
            if (desUpper.startsWith('ZZ_') || (desUpper.length >= 3 && desUpper.length < 5)) {
                let icon = airport_icon;
                let label = desUpper;
    
                if (desUpper.startsWith('ZZ_')) {
                    icon = artcc_icon;
                    // Label with underlying center code, e.g. ZZ_ZNY -> ZNY
                    if (desUpper.length > 3) {
                        label = desUpper.substring(3);
                    }
                }
    

                const marker = L.marker([lat, lon], {icon: icon})
                    .bindTooltip(label, {className: 'Incon bg-dark text-light', permanent: false, direction: 'center'})
                    .addTo(graphic_map);

                // Clicking a point toggles its labeled box
                marker.on('click', function() {
                    const key = buildLabelKey(label, lat, lon);
                    if (!labelMetaByKey[key]) {
                        labelMetaByKey[key] = { name: label, lat: lat, lon: lon };
                    }
                    const colorForPoint = pointRouteColorByKey[key] || '#C70039';
                    togglePointLabel(label, lat, lon, colorForPoint);
                });

            }
        });
        // Route (E)

        // Add routes layer group to map and bring to front (above boundaries)
        linestring.addTo(graphic_map);
        linestring.bringToFront();

        // Add fix markers group to map with zoom-based visibility
        fixMarkersGroup.addTo(graphic_map);
        fixMarkersGroup.bringToFront();

        // Ensure ADL flights stay on top after routes are drawn
        if (typeof ADL !== 'undefined' && ADL.getState && ADL.getState().layer) {
            if (ADL.getState().routeLayer) ADL.getState().routeLayer.bringToFront();
            ADL.getState().layer.bringToFront();
        }

        // Hide fix markers when zoomed out for performance
        const FIX_MARKER_MIN_ZOOM = 5;
        function updateFixMarkerVisibility() {
            const currentZoom = graphic_map.getZoom();
            if (currentZoom >= FIX_MARKER_MIN_ZOOM) {
                if (!graphic_map.hasLayer(fixMarkersGroup)) {
                    graphic_map.addLayer(fixMarkersGroup);
                    // Bring fix markers to front after re-adding
                    fixMarkersGroup.bringToFront();
                    // Also bring ADL flights to front to keep them on top
                    if (typeof ADL !== 'undefined' && ADL.getState && ADL.getState().layer) {
                        if (ADL.getState().routeLayer) ADL.getState().routeLayer.bringToFront();
                        ADL.getState().layer.bringToFront();
                    }
                }
            } else {
                if (graphic_map.hasLayer(fixMarkersGroup)) {
                    graphic_map.removeLayer(fixMarkersGroup);
                }
            }
        }
        
        graphic_map.on('zoomend', updateFixMarkerVisibility);
        updateFixMarkerVisibility(); // Initial check
    
        // Store GeoJSON for Export
        cached_geojson_linestring = linestring.toGeoJSON();
        cached_geojson_multipoint = multipoint;
    
    }
    
    drawMap([]);
    
    $('#plot_r').on('click', function() {
        drawMapCall();
    });
    
    $('#plot_c').on('click', function() {
        navigator.clipboard.writeText($('#routeSearch').val());
    });
    
    $('#filter_c').on('click', function() {
        $('#filter').val('');
        drawMapCall();
    });
    
    $('#export_ls').on('click', function() {
        let element = document.createElement('a');
        element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(JSON.stringify(cached_geojson_linestring)));
        element.setAttribute('download', 'linestring.json');
    
        element.style.display = 'none';
        document.body.appendChild(element);
    
        element.click();
    
        document.body.removeChild(element);
    });
    
    $('#export_mp').on('click', function() {
        let element = document.createElement('a');
        element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(JSON.stringify(cached_geojson_multipoint)));
        element.setAttribute('download', 'multipoint.json');
    
        element.style.display = 'none';
        document.body.appendChild(element);
    
        element.click();
    
        document.body.removeChild(element);
    });
    
    // -----------------------------------------------------------
    // Reroute advisory builder (vATCSCC-style ROUTE RQD advisory)
    // Uses: cdrMap, playbookRoutes, expandPlaybookDirective,
    //       splitAdvisoriesFromInput, parseAdvisoryRoutes
    // -----------------------------------------------------------

    const ADV_INDENT   = '';
    const ADV_MAX_LINE = 68;   // per FAA spec
    let   ADV_ORIG_COL = 20;
    let   ADV_DEST_COL = 20;

    const ADV_FACILITY_CODES = [
        'ZAB','ZAU','ZBW','ZDC','ZDV','ZFW','ZHU','ZID','ZJX','ZKC','ZLA','ZLC','ZMA','ZME','ZMP','ZNY','ZOA','ZOB','ZSE','ZTL',
        'CZE','CZM','CZU','CZV','CZW','CZY',
        'ZEU','ZMX','CAR'
    ];

    const ADV_US_FACILITY_CODES = [
        'ZAB','ZAU','ZBW','ZDC','ZDV','ZFW','ZHU','ZID','ZJX','ZKC',
        'ZLA','ZLC','ZMA','ZME','ZMP','ZNY','ZOA','ZOB','ZSE','ZTL'
    ];


    function advComputeColumnWidths(routes) {
        const totalWidth    = ADV_MAX_LINE - ADV_INDENT.length;
        const minOrigWidth  = 8;
        const minDestWidth  = 8;
        const maxOrigWidth  = 18;
        const maxDestWidth  = 18;
        const minRouteWidth = 32;   // try to keep ROUTE reasonably wide

        let maxOrigLen = 'ORIG'.length;
        let maxDestLen = 'DEST'.length;

        (routes || []).forEach(function (r) {
            const o = (r.orig || '').toString().toUpperCase();
            const d = (r.dest || '').toString().toUpperCase();
            if (o.length > maxOrigLen) maxOrigLen = o.length;
            if (d.length > maxDestLen) maxDestLen = d.length;
        });

        // Base widths: longest text + 2 spaces padding,
        // clamped to min/max to keep things reasonable
        let origWidth = Math.min(
            Math.max(maxOrigLen + 2, minOrigWidth),
            maxOrigWidth
        );
        let destWidth = Math.min(
            Math.max(maxDestLen + 2, minDestWidth),
            maxDestWidth
        );

        let routeWidth = totalWidth - origWidth - destWidth;

        // If the route column is still too skinny, shave from ORIG/DEST down to their mins
        if (routeWidth < minRouteWidth) {
            let need           = minRouteWidth - routeWidth;
            const shrinkOrig   = origWidth - minOrigWidth;
            const shrinkDest   = destWidth - minDestWidth;
            const takeFromOrig = Math.min(need, shrinkOrig);

            origWidth -= takeFromOrig;
            need      -= takeFromOrig;

            if (need > 0) {
                const takeFromDest = Math.min(need, shrinkDest);
                destWidth -= takeFromDest;
                need      -= takeFromDest;
            }

            routeWidth = totalWidth - origWidth - destWidth;
        }

        ADV_ORIG_COL = origWidth;
        ADV_DEST_COL = destWidth;
    }


    function advWrapWordsToWidth(text, width) {
        const words = (text || '').toString().split(/\s+/).filter(Boolean);
        const lines = [];
        let current = '';

        words.forEach(function(word) {
            if (!current.length) {
                current = word;
            } else if ((current + ' ' + word).length <= width) {
                current += ' ' + word;
            } else {
                lines.push(current);
                current = word;
            }
        });

        if (current.length) {
            lines.push(current);
        }

        return lines;
    }

    function advTokenizeLabelValue(raw) {
        raw = (raw || '').toString().trim();
        if (!raw) return [];

        // First split on whitespace
        const parts = raw.split(/\s+/);
        const tokens = [];

        parts.forEach(function(part) {
            // If it's a slash-list like "KLAX/KOAK/KPDX/KSAN/..." break it up
            if (part.indexOf('/') !== -1 && part.length > 8) {
                const pieces = part.split('/');
                pieces.forEach(function(piece, idx) {
                    if (!piece) return; // skip empty fragments
                    if (idx < pieces.length - 1) {
                        // keep the slash attached
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
        var clean = token.replace(/[<>]/g, '').toUpperCase();
        if (clean.length < 4 || clean.length > 7) return false;
        if (!/^[A-Z]+\d$/.test(clean)) return false;
        if (/^K[A-Z]{3}$/.test(clean)) return false;
        return true;
    }

    // Auto-correct mandatory segments to NOT include DPs or STARs
    // Also strip procedure.transition dots even in non-mandatory routes
    // Handles chains like proc.trans.proc -> proc trans proc or proc >trans< proc
    function advCorrectMandatoryProcedures(routeText) {
        if (!routeText) return routeText;

        var tokens = routeText.split(/\s+/).filter(Boolean);
        if (tokens.length < 1) return routeText;

        // Scan all tokens for patterns needing correction
        for (var i = 0; i < tokens.length; i++) {
            var tok = tokens[i];

            // Handle fully wrapped token with dots: >{...}<
            // Could be >{PROC}.{TRANS}<, >{TRANS}.{PROC}<, or >{PROC}.{TRANS}.{PROC}<
            if (tok.startsWith('>') && tok.endsWith('<') && tok.indexOf('.') !== -1) {
                var inner = tok.slice(1, -1);  // Remove > and <
                var parts = inner.split('.');
                if (parts.length >= 2 && parts.every(function(p) { return p.length > 0; })) {
                    // Classify each part as procedure or not
                    var resultParts = [];
                    var inMandatory = false;

                    for (var j = 0; j < parts.length; j++) {
                        var part = parts[j];
                        var isProc = advLooksLikeProcedure(part);

                        if (isProc) {
                            // Procedures go outside mandatory
                            if (inMandatory) {
                                // Close mandatory before procedure
                                resultParts.push('<');
                                inMandatory = false;
                            }
                            resultParts.push(part);
                        } else {
                            // Non-procedures go inside mandatory
                            if (!inMandatory) {
                                // Open mandatory before non-procedure
                                resultParts.push('>');
                                inMandatory = true;
                            }
                            resultParts.push(part);
                        }
                    }

                    // Close mandatory if still open at end
                    if (inMandatory) {
                        resultParts.push('<');
                    }

                    // Combine result, attaching markers to adjacent parts
                    var combined = '';
                    for (var k = 0; k < resultParts.length; k++) {
                        var rp = resultParts[k];
                        if (rp === '>') {
                            combined += (combined.length > 0 ? ' ' : '') + '>';
                        } else if (rp === '<') {
                            combined += '<';
                        } else {
                            if (combined.length > 0 && !combined.endsWith('>')) {
                                combined += ' ';
                            }
                            combined += rp;
                        }
                    }
                    tokens[i] = combined;
                }
                continue;  // Skip other checks for this token
            }

            // Handle non-mandatory token with dots: {PROC}.{TRANS} or chains like {PROC}.{TRANS}.{PROC}
            if (!tok.startsWith('>') && !tok.endsWith('<') && tok.indexOf('.') !== -1) {
                // Simply replace all dots with spaces for non-mandatory
                tokens[i] = tok.split('.').filter(Boolean).join(' ');
                continue;
            }

            // Check for >{...} pattern (starts with >, has dots, no < at end)
            if (tok.startsWith('>') && tok.indexOf('.') !== -1 && !tok.endsWith('<')) {
                var withoutMarker = tok.slice(1);
                var dotParts = withoutMarker.split('.').filter(Boolean);
                if (dotParts.length >= 2) {
                    // Find first non-procedure part to start mandatory
                    var resultArr = [];
                    var mandatoryStarted = false;

                    for (var m = 0; m < dotParts.length; m++) {
                        var dp = dotParts[m];
                        if (advLooksLikeProcedure(dp) && !mandatoryStarted) {
                            // Procedure before mandatory starts - put outside
                            resultArr.push(dp);
                        } else {
                            // Non-procedure or procedure after mandatory started
                            if (!mandatoryStarted) {
                                resultArr.push('>' + dp);
                                mandatoryStarted = true;
                            } else {
                                resultArr.push(dp);
                            }
                        }
                    }
                    tokens[i] = resultArr.join(' ');
                }
            }
            // Check for >{DP#} pattern (no dots)
            else if (tok.startsWith('>') && tok.indexOf('.') === -1 && !tok.endsWith('<')) {
                var withoutMarker2 = tok.slice(1);
                if (advLooksLikeProcedure(withoutMarker2)) {
                    tokens[i] = withoutMarker2;
                    if (i + 1 < tokens.length && !tokens[i + 1].startsWith('>')) {
                        tokens[i + 1] = '>' + tokens[i + 1];
                    }
                }
            }

            // Check for {...}< pattern (ends with <, has dots, no > at start)
            if (tok.endsWith('<') && tok.indexOf('.') !== -1 && !tok.startsWith('>')) {
                var withoutMarker3 = tok.slice(0, -1);
                var dotParts2 = withoutMarker3.split('.').filter(Boolean);
                if (dotParts2.length >= 2) {
                    // Find last non-procedure part to end mandatory
                    var resultArr2 = [];
                    var lastNonProcIdx = -1;

                    // Find the last non-procedure
                    for (var n = dotParts2.length - 1; n >= 0; n--) {
                        if (!advLooksLikeProcedure(dotParts2[n])) {
                            lastNonProcIdx = n;
                            break;
                        }
                    }

                    for (var p = 0; p < dotParts2.length; p++) {
                        var dp2 = dotParts2[p];
                        if (p === lastNonProcIdx) {
                            resultArr2.push(dp2 + '<');
                        } else if (p > lastNonProcIdx && advLooksLikeProcedure(dp2)) {
                            // Procedure after mandatory ends
                            resultArr2.push(dp2);
                        } else {
                            resultArr2.push(dp2);
                        }
                    }
                    tokens[i] = resultArr2.join(' ');
                }
            }
            // Check for {STAR#}< pattern (no dots)
            else if (tok.endsWith('<') && tok.indexOf('.') === -1 && !tok.startsWith('>')) {
                var withoutMarker4 = tok.slice(0, -1);
                if (advLooksLikeProcedure(withoutMarker4)) {
                    tokens[i] = withoutMarker4;
                    if (i > 0 && !tokens[i - 1].endsWith('<')) {
                        tokens[i - 1] = tokens[i - 1] + '<';
                    }
                }
            }
        }

        return tokens.join(' ').replace(/\s+/g, ' ').trim();
    }

    function advAddLabeledField(lines, label, value) {
        label = (label || '').toString().toUpperCase();
        const basePrefix = ADV_INDENT + label + ':';

        const raw = (value == null ? '' : String(value)).trim();
        if (!raw.length) {
            lines.push(basePrefix);
            return;
        }

        const firstPrefix = basePrefix + ' ';
        const hangPrefix  = ' '.repeat(firstPrefix.length);
        const maxWidth    = ADV_MAX_LINE;

        const words = advTokenizeLabelValue(raw);
        if (!words.length) {
            lines.push(basePrefix);
            return;
        }

        // If it’s a single huge "word", just force it on one line with the label
        if (words.length === 1 && (firstPrefix.length + words[0].length > maxWidth)) {
            lines.push(firstPrefix + words[0]);
            return;
        }

        let prefix  = firstPrefix;  // current line’s prefix ("NAME: ", "INCLUDE TRAFFIC: ", etc.)
        let content = '';           // everything after the prefix on this line

        words.forEach(function (word) {
            if (!word) return;

            if (!content) {
                // First word on this line
                if (prefix.length + word.length <= maxWidth) {
                    content = word;
                } else {
                    // Extremely rare: word itself doesn’t fit; just push it
                    lines.push(prefix + word);
                    prefix  = hangPrefix;
                    content = '';
                }
            } else {
                // Subsequent words: decide if we need a space or not
                const lastChar = content[content.length - 1];
                const joiner   = (lastChar === '/' ? '' : ' ');  // no space after slash

                if (prefix.length + content.length + joiner.length + word.length <= maxWidth) {
                    content += joiner + word;
                } else {
                    // wrap to next line
                    lines.push((prefix + content).trimEnd());
                    prefix  = hangPrefix;
                    content = word;
                }
            }
        });

        if (content) {
            lines.push((prefix + content).trimEnd());
        }
    }

    
function advEnsureDefaultIncludedTraffic(parsedRoutes) {
        const $field = $('#advIncludeTraffic');
        if (!$field.length) return;

        const existing = ($field.val() || '').toString().trim();
        if (existing.length) {
            return;
        }

        const routes = parsedRoutes || [];
        if (!routes.length) {
            return;
        }

        const origSet = new Set();
        const destSet = new Set();

        routes.forEach(function(r) {
            const origRaw = (r.orig || '').toString().trim().toUpperCase();
            const destRaw = (r.dest || '').toString().trim().toUpperCase();

            if (origRaw.length) {
                origRaw.split(/\s+/).forEach(function(token) {
                    if (token && advIsFacility(token)) {
                        origSet.add(token);
                    }
                });
            }

            if (destRaw.length && advIsFacility(destRaw)) {
                destSet.add(destRaw);
            }
        });

        if (!origSet.size || !destSet.size) {
            return;
        }

        const origins = Array.from(origSet).sort();
        const dests   = Array.from(destSet).sort();

        const text = origins.join('/') + ' DEPARTURES TO ' + dests.join('/');
        $field.val(text);
    }


    function advInitFacilitiesDropdown() {
        const $grid = $('#advFacilitiesGrid');
        if (!$grid.length) return;

        // Build checkbox grid
        $grid.empty();
        ADV_FACILITY_CODES.forEach(function(code) {
            const id = 'advFacility_' + code;
            const $check = $('<input>')
                .attr('type', 'checkbox')
                .addClass('form-check-input')
                .attr('id', id)
                .attr('data-code', code)
                .val(code);

            const $label = $('<label>')
                .addClass('form-check-label')
                .attr('for', id)
                .text(code);

            const $wrapper = $('<div>').addClass('form-check');
            $wrapper.append($check).append($label);
            $grid.append($wrapper);
        });

        $('#advFacilitiesToggle').on('click', function(e) {
            e.stopPropagation();

            // Sync checkboxes from current text field before showing
            const current = ($('#advFacilities').val() || '').toString().toUpperCase();
            const selected = new Set(current.split(/[\/\s]+/).filter(Boolean));

            $('#advFacilitiesGrid input[type="checkbox"]').each(function() {
                const code = ($(this).attr('data-code') || '').toString().toUpperCase();
                $(this).prop('checked', selected.has(code));
            });

            $('#advFacilitiesDropdown').toggle();
        });

        $('#advFacilitiesApply').on('click', function(e) {
            e.stopPropagation();
            const selected = [];

            $('#advFacilitiesGrid input[type="checkbox"]:checked').each(function() {
                const code = ($(this).attr('data-code') || '').toString().toUpperCase();
                if (code) {
                    selected.push(code);
                }
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
            const usSet = new Set(ADV_US_FACILITY_CODES.map(function(c) { return c.toUpperCase(); }));
            $('#advFacilitiesGrid input[type="checkbox"]').each(function() {
                const code = ($(this).attr('data-code') || '').toString().toUpperCase();
                $(this).prop('checked', usSet.has(code));
            });
        });

// Hide dropdown if clicking outside of the wrapper
        $(document).on('click', function(e) {
            const $wrap = $('.adv-facilities-wrapper');
            if (!$wrap.length) return;

            if (!$wrap.is(e.target) && $wrap.has(e.target).length === 0) {
                $('#advFacilitiesDropdown').hide();
            }
        });

        // Auto-calculate facilities from GIS
        $('#advFacilitiesAuto').on('click', function(e) {
            e.stopPropagation();
            advCalculateFacilitiesFromGIS();
        });
    }

    /**
     * Calculate facilities from routes using the GIS API
     * Calls the PostGIS route expansion endpoint for each route and aggregates ARTCCs
     */
    async function advCalculateFacilitiesFromGIS() {
        console.log('[ADV] advCalculateFacilitiesFromGIS() called');

        const $btn = $('#advFacilitiesAuto');
        const $field = $('#advFacilities');
        const $badge = $('#advFacilitiesAutoBadge');

        console.log('[ADV] Button found:', $btn.length > 0, 'Field found:', $field.length > 0);

        if (!$field.length) {
            console.log('[ADV] Field not found, returning');
            return;
        }

        // Check for routes in textarea
        const rawInput = $('#routeSearch').val() || '';
        console.log('[ADV] Raw input length:', rawInput.length);
        if (!rawInput.trim()) {
            alert('No routes in the Plot Routes box to analyze.');
            return;
        }

        // Show loading state
        const originalText = $btn.html();
        $btn.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
        $field.attr('placeholder', 'Calculating...');

        try {
            // PREFER already-expanded routes from MapLibre (playbooks & CDRs already resolved)
            let uniqueRoutes = [];

            if (window.MapLibreRoute && typeof window.MapLibreRoute.getLastExpandedRoutes === 'function') {
                const expandedRoutes = window.MapLibreRoute.getLastExpandedRoutes() || [];
                if (expandedRoutes.length > 0) {
                    // Use MapLibre's expanded routes (already resolved playbooks, CDRs, etc.)
                    uniqueRoutes = expandedRoutes.map(function(rte) {
                        if (!rte) return '';
                        // Remove mandatory markers <> if present
                        return rte.replace(/[<>]/g, '').trim();
                    }).filter(Boolean);
                    console.log('[ADV] Using', uniqueRoutes.length, 'routes from MapLibre expansion');
                }
            }

            // Fallback: parse from textarea if MapLibre routes unavailable
            if (!uniqueRoutes.length) {
                const routeStrings = collectRouteStringsForAdvisory(rawInput);
                if (!routeStrings.length) {
                    $field.attr('placeholder', 'ZBW/ZNY/ZDC');
                    $btn.html(originalText).prop('disabled', false);
                    alert('No valid routes found. Try clicking "Plot" first.');
                    return;
                }

                uniqueRoutes = routeStrings.map(function(rte) {
                    if (!rte) return '';
                    const semiIdx = rte.indexOf(';');
                    const routeText = semiIdx !== -1 ? rte.slice(0, semiIdx).trim() : rte.trim();
                    return routeText.replace(/[<>]/g, '').trim();
                }).filter(Boolean);
                console.log('[ADV] Fallback: parsed', uniqueRoutes.length, 'routes from textarea');
            }

            // Deduplicate
            uniqueRoutes = [...new Set(uniqueRoutes)];
            console.log('[ADV] Unique routes to send to GIS:', uniqueRoutes.length, uniqueRoutes);

            // Call GIS API for batch expansion
            const allArtccs = new Set();
            let totalDistance = 0;
            let totalWaypoints = 0;
            let successCount = 0;

            // Use batch endpoint if available, otherwise call one-by-one
            console.log('[ADV] Calling GIS API...');
            const response = await fetch('/api/gis/boundaries?action=expand_routes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ routes: uniqueRoutes })
            });

            console.log('[ADV] API response status:', response.status, response.ok);

            if (response.ok) {
                const data = await response.json();
                console.log('[ADV] API response data:', data);
                if (data.success && data.routes) {
                    data.routes.forEach(function(result) {
                        if (result.artccs && result.artccs.length) {
                            result.artccs.forEach(function(artcc) {
                                allArtccs.add(artcc);
                            });
                            successCount++;
                            totalDistance += result.distance_nm || 0;
                            totalWaypoints += result.waypoint_count || 0;
                        }
                    });
                }
            } else {
                // Fallback to single-route calls
                for (const route of uniqueRoutes) {
                    try {
                        const encoded = encodeURIComponent(route);
                        const singleResponse = await fetch('/api/gis/boundaries?action=expand_route&route=' + encoded);
                        if (singleResponse.ok) {
                            const singleData = await singleResponse.json();
                            if (singleData.success && singleData.artccs) {
                                singleData.artccs.forEach(function(artcc) {
                                    allArtccs.add(artcc);
                                });
                                successCount++;
                                totalDistance += singleData.distance_nm || 0;
                                totalWaypoints += singleData.waypoint_count || 0;
                            }
                        }
                    } catch (e) {
                        console.warn('Failed to expand route:', route, e);
                    }
                }
            }

            if (allArtccs.size > 0) {
                // Sort ARTCCs alphabetically and join with /
                const sortedArtccs = Array.from(allArtccs).sort();
                $field.val(sortedArtccs.join('/'));
                $field.data('autoCalculated', true);

                // Update checkboxes in dropdown
                const artccSet = new Set(sortedArtccs);
                $('#advFacilitiesGrid input[type="checkbox"]').each(function() {
                    const code = ($(this).attr('data-code') || '').toString().toUpperCase();
                    $(this).prop('checked', artccSet.has(code));
                });

                // Show badge with info
                if ($badge.length) {
                    $badge.show()
                        .attr('title', successCount + ' routes analyzed, ' + totalWaypoints + ' waypoints, ' + Math.round(totalDistance) + ' nm total');
                }

                console.log('GIS auto-calculated facilities:', sortedArtccs.join('/'),
                    '(' + successCount + '/' + uniqueRoutes.length + ' routes, ' + totalWaypoints + ' waypoints, ' + Math.round(totalDistance) + ' nm)');
            } else {
                alert('Could not determine facilities from routes. The GIS service may be unavailable or the routes contain unrecognized fixes.');
            }

        } catch (error) {
            console.error('[ADV] GIS facilities calculation error:', error);
            console.error('[ADV] Error stack:', error.stack);
            alert('Error calculating facilities: ' + error.message);
        } finally {
            $field.attr('placeholder', 'ZBW/ZNY/ZDC');
            $btn.html(originalText).prop('disabled', false);
        }
    }

    // Check if token is an ARTCC (Z + 2 chars)
    function advIsArtcc(code) {
        if (!code) return false;
        var t = String(code).toUpperCase().trim().replace(/[<>]/g, '');
        return /^Z[A-Z]{2}$/.test(t);
    }

    // Check if token is a TRACON (letter + 2 digits)
    function advIsTracon(code) {
        if (!code) return false;
        var t = String(code).toUpperCase().trim().replace(/[<>]/g, '');
        return /^[A-Z][0-9]{2}$/.test(t);
    }

    // Check if token is an airport (4-letter ICAO code)
    function advIsAirport(code) {
        if (!code) return false;
        var t = String(code).toUpperCase().trim().replace(/[<>]/g, '');
        // Accept any valid 4-letter ICAO code (supports all global regions)
        // ICAO prefixes: K=US, CY/CZ=Canada, P=Pacific, MM=Mexico, E=N.Europe,
        // L=S.Europe, M=Central America/Caribbean, S=South America, etc.
        return /^[A-Z]{4}$/.test(t);
    }

function advIsFacility(code) {
        if (!code) return false;
        const t = String(code).toUpperCase().trim().replace(/[<>]/g, '');
        if (!t) return false;

        // Check with proper airport logic
        if (advIsAirport(t)) return true;

        // ARTCC (ZNY, ZOB, etc.)
        if (/^Z[A-Z]{2}$/.test(t)) return true;

        // TRACON-style (A80, N90, L30, etc.)
        if (/^[A-Z][0-9]{2}$/.test(t)) return true;

        return false;
    }

    // Strip all facilities from route, keep only NAVAIDs/fixes/airways
    // Convert DP/STAR dot notation to spaces (e.g., KORRY5.KORRY -> KORRY5 KORRY)
    function advCleanRouteString(routeText) {
        if (!routeText) return '';

        // First pass: convert all dots to spaces (procedure.transition -> procedure transition)
        // This handles both mandatory and non-mandatory routes uniformly
        // Also handles chains like proc.trans.proc
        var normalized = routeText;

        // Replace procedure.transition patterns (handles any alphanumeric.alphanumeric)
        // Pattern: word.word where both parts are at least 2 chars
        // Apply repeatedly to handle chains like A.B.C -> A B.C -> A B C
        var prevNormalized;
        do {
            prevNormalized = normalized;
            normalized = normalized.replace(/([A-Z0-9]{2,})\.([A-Z0-9]{2,})/gi, '$1 $2');
        } while (normalized !== prevNormalized);

        var tokens = normalized.split(/\s+/).filter(Boolean);
        var cleaned = [];

        tokens.forEach(function(tok) {
            var clean = tok.replace(/[<>]/g, '').toUpperCase();
            // Skip facilities (airports, ARTCCs, TRACONs)
            if (advIsFacility(clean)) return;

            // Double-check for any remaining dots (shouldn't happen after normalization)
            // Convert DP/STAR dot notation to space
            // Patterns: {PROC}{#}.{TRANSITION} or {TRANSITION}.{PROC}{#}
            // e.g., KORRY5.KORRY -> KORRY5 KORRY, CAMRN.CAMRN4 -> CAMRN CAMRN4
            if (clean.indexOf('.') !== -1) {
                var parts = clean.split('.');
                if (parts.length === 2 && parts[0] && parts[1]) {
                    // Both parts are valid - add them as separate tokens with space
                    cleaned.push(parts[0]);
                    cleaned.push(parts[1]);
                    return;
                }
            }

            // Keep everything else (NAVAIDs, fixes, airways, DP/STAR codes without dots)
            cleaned.push(clean);
        });

        return cleaned.join(' ');
    }

    
function advParseRoutesFromStrings(routeStrings) {
        const routes = [];

        (routeStrings || []).forEach(function(raw) {
            let line = (raw || '').trim();
            if (!line) return;

            // Strip any trailing color suffix like ";BLUE" or ";#fff"
            const semiIdx = line.indexOf(';');
            if (semiIdx !== -1) {
                line = line.slice(0, semiIdx).trim();
            }
            if (!line) return;

            const tokens = line.split(/\s+/).filter(Boolean);
            if (!tokens.length) return;

            // Single-token line: treat as a possible CDR code and expand via cdrMap
            if (tokens.length === 1) {
                const code = tokens[0].toUpperCase();
                if (cdrMap && Object.prototype.hasOwnProperty.call(cdrMap, code)) {
                    const mapped = (cdrMap[code] || '').toString();
                    if (mapped.trim().length) {
                        const expanded = advParseRoutesFromStrings([mapped]);
                        (expanded || []).forEach(function(er) {
                            routes.push(er);
                        });
                    }
                }
                return;
            }

            // Identify all facility tokens in the line
            const facilityIdxs = [];
            for (let i = 0; i < tokens.length; i++) {
                if (advIsFacility(tokens[i])) {
                    facilityIdxs.push(i);
                }
            }

            // Need at least an origin and a destination
            if (facilityIdxs.length < 2) {
                return;
            }

            const firstFacilityIdx = facilityIdxs[0];
            const lastFacilityIdx  = facilityIdxs[facilityIdxs.length - 1];

            if (lastFacilityIdx <= firstFacilityIdx) {
                return;
            }

            // Group all contiguous facilities from the first facility onward.
            // This allows patterns like:
            //   "ZMA ZJX GRUBR Y299 ... WHINY4 KDFW"
            // where ZMA and ZJX are jointly the origin facilities.
            let groupEndExclusive = firstFacilityIdx;
            while (groupEndExclusive < lastFacilityIdx && advIsFacility(tokens[groupEndExclusive])) {
                groupEndExclusive++;
            }

            const origTokens  = tokens.slice(firstFacilityIdx, groupEndExclusive);
            const destToken   = tokens[lastFacilityIdx];
            const routeTokens = tokens.slice(groupEndExclusive, lastFacilityIdx);

            const orig = origTokens.join(' ').toUpperCase();
            const dest = (destToken || '').toUpperCase();
            const assignedRoute = routeTokens.map(function(t) { return t.toUpperCase(); }).join(' ');

            routes.push({
                orig: orig,
                dest: dest,
                assignedRoute: assignedRoute
            });
        });

        return routes;
    }


function advConsolidateRoutes(parsedRoutes) {
        const edges = (parsedRoutes || []).map(function(r, idx) {
            return {
                id: idx,
                orig: (r.orig || '').toUpperCase(),
                dest: (r.dest || '').toUpperCase(),
                tokens: (r.assignedRoute || '')
                    .toUpperCase()
                    .split(/\s+/)
                    .filter(Boolean)
            };
        });

        const adjacency = {};
        edges.forEach(function(e) {
            if (!adjacency[e.orig]) adjacency[e.orig] = [];
            adjacency[e.orig].push(e);
        });

        const consolidated = [];
        const seen = new Set();
        const MAX_DEPTH = 10;

        function dfs(currentNode, pathEdges, depth) {
            const startNode = pathEdges[0].orig;
            const currentIsFacility = advIsFacility(currentNode);

            // Only output when we reach a new facility destination
            if (currentIsFacility && currentNode !== startNode) {
                const allTokens = [];
                pathEdges.forEach(function(edge) {
                    allTokens.push.apply(allTokens, edge.tokens);
                });

                // Deduplicate consecutive duplicate tokens (e.g. BIZEX BIZEX)
                const dedupTokens = [];
                allTokens.forEach(function(tok) {
                    if (!dedupTokens.length || dedupTokens[dedupTokens.length - 1] !== tok) {
                        dedupTokens.push(tok);
                    }
                });

                const routeStr = dedupTokens.join(' ');
                const key = startNode + '|' + currentNode + '|' + routeStr;

                if (!seen.has(key)) {
                    seen.add(key);
                    consolidated.push({
                        orig: startNode,
                        dest: currentNode,
                        assignedRoute: routeStr
                    });
                }
            }

            if (depth >= MAX_DEPTH) return;

            const nextEdges = adjacency[currentNode] || [];
            for (let i = 0; i < nextEdges.length; i++) {
                const e = nextEdges[i];
                // Avoid cycles: don't reuse same edge in a path
                if (pathEdges.some(function(pe) { return pe.id === e.id; })) continue;
                pathEdges.push(e);
                dfs(e.dest, pathEdges, depth + 1);
                pathEdges.pop();
            }
        }

        // Starting edges:
        //  - any facility origin, or
        //  - non-facility origin that feeds a single facility dest with no onward edges
        const startEdges = edges.filter(function(e) {
            if (advIsFacility(e.orig)) return true;
            if (!advIsFacility(e.orig) && advIsFacility(e.dest) && !(adjacency[e.dest] && adjacency[e.dest].length)) {
                return true;
            }
            return false;
        });

        startEdges.forEach(function(e) {
            dfs(e.dest, [e], 1);
        });

        if (!consolidated.length) {
            // Fallback: no chaining detected, just return input
            return (parsedRoutes || []).map(function(r) {
                return {
                    orig: (r.orig || '').toUpperCase(),
                    dest: (r.dest || '').toUpperCase(),
                    assignedRoute: (r.assignedRoute || '').toUpperCase()
                };
            });
        }

        return consolidated;
    }

    function advFormatRouteRow(orig, dest, routeText) {
        orig      = (orig      || '').toString().toUpperCase();
        dest      = (dest      || '').toString().toUpperCase();
        routeText = (routeText || '').toString().toUpperCase().trim();

        const lines = [];

        // Split ORIG/DEST into individual facility tokens
        const origTokens = orig.trim().length ? orig.trim().split(/\s+/).filter(Boolean) : [];
        const destTokens = dest.trim().length ? dest.trim().split(/\s+/).filter(Boolean) : [];

        // Strip ORIG/DEST tokens from the *ends* of the route text when they
        // appear as facilities, using the first/last facility tokens.
        let routeTokens = routeText.length ? routeText.split(/\s+/).filter(Boolean) : [];

        if (routeTokens.length && origTokens.length &&
            advIsFacility(routeTokens[0]) &&
            routeTokens[0] === origTokens[0]) {
            routeTokens = routeTokens.slice(1);
        }

        if (routeTokens.length && destTokens.length &&
            advIsFacility(routeTokens[routeTokens.length - 1]) &&
            routeTokens[routeTokens.length - 1] === destTokens[destTokens.length - 1]) {
            routeTokens = routeTokens.slice(0, -1);
        }

        // Group ORIG/DEST tokens into chunks of up to 3 facilities per column line.
        function chunkTokens(tokens) {
            if (!tokens || !tokens.length) {
                return [''];
            }
            const chunks = [];
            const MAX_PER_LINE = 3;
            for (let i = 0; i < tokens.length; i += MAX_PER_LINE) {
                chunks.push(tokens.slice(i, i + MAX_PER_LINE).join(' '));
            }
            return chunks;
        }

        const origChunks = chunkTokens(origTokens);
        const destChunks = chunkTokens(destTokens);

        const maxColumnLines = Math.max(origChunks.length, destChunks.length, 1);
        const words = routeTokens.slice(); // copy
        const maxWidth = ADV_MAX_LINE;

        let lineIndex = 0;

        // Produce lines until we've exhausted both ORIG/DEST chunks and all route words.
        while (lineIndex < maxColumnLines || words.length) {
            const oStr = (lineIndex < origChunks.length) ? origChunks[lineIndex] : '';
            const dStr = (lineIndex < destChunks.length) ? destChunks[lineIndex] : '';

            const origCol = oStr.padEnd(ADV_ORIG_COL, ' ').slice(0, ADV_ORIG_COL);
            const destCol = dStr.padEnd(ADV_DEST_COL, ' ').slice(0, ADV_DEST_COL);

            const basePrefix = ADV_INDENT + origCol + destCol;
            let current = basePrefix;
            const baseLen = current.length;

            // Append as many route words as will fit on this line.
            if (words.length) {
                while (words.length) {
                    const word = words[0];
                    const atStart = (current.length === baseLen);
                    const addition = atStart ? word : ' ' + word;

                    if (current.length + addition.length <= maxWidth) {
                        current += addition;
                        words.shift();
                    } else {
                        // If nothing fits on an otherwise empty route area, force the word.
                        if (atStart && current.length + word.length > maxWidth) {
                            current += word;
                            words.shift();
                        }
                        break;
                    }
                }
            }

            lines.push(current.trimEnd());
            lineIndex += 1;
        }

        if (!lines.length) {
            lines.push('');
        }

        return lines;
    }

    // Build list of route strings from #routeSearch, including:
    //  - Advisory text (if user pasted an ADVZY)
    //  - PB.* playbook directives (via expandPlaybookDirective)
    //  - CDR expansion via cdrMap
    function collectRouteStringsForAdvisory(rawInput) {
        let routeStrings = [];
        const text = (rawInput || '').toString();

        // If they pasted an advisory, reuse the existing advisory parser
        if (/VATCSCC ADVZY/i.test(text)) {
            const advBlocks = splitAdvisoriesFromInput(text.toUpperCase());
            advBlocks.forEach(function(block) {
                const advRoutes = parseAdvisoryRoutes(block);
                if (advRoutes && advRoutes.length) {
                    routeStrings = routeStrings.concat(advRoutes);
                }
            });
        } else {
            // Pre-process for group headers, then line-by-line: handle PB.* and colors the same way as the map
            const expanded = expandGroupsInRouteInput(text);
            const linesRaw = expanded.split(/\r?\n/);

            linesRaw.forEach(function(line) {
                const trimmed = line.trim();
                if (!trimmed) return;

                let body = trimmed;
                let color = null;

                const semiIdx = trimmed.indexOf(';');
                if (semiIdx !== -1) {
                    body  = trimmed.slice(0, semiIdx).trim();
                    color = trimmed.slice(semiIdx + 1).trim();
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
                    // Example: PB.ABI.KDCA.KLAX KSFO KSAN
                    const bodyUpper = specUpper.slice(3).trim();
                    const pbRoutes = expandPlaybookDirective(bodyUpper, isMandatory, color);

                    if (pbRoutes && pbRoutes.length) {
                        routeStrings = routeStrings.concat(pbRoutes);
                    } else {
                        console.warn('No playbook routes matched for', spec);
                    }
                } else {
                    let rte = specUpper;
                    if (color) {
                        rte = rte + ';' + color;
                    }
                    routeStrings.push(rte);
                }
            });
        }

        // CDR expansion using the same semantics as the map renderer
        routeStrings = routeStrings.map(function(rte) {
            if (!rte) return rte;
            let routeText = rte;

            const semiIdx = routeText.indexOf(';');
            if (semiIdx !== -1) {
                routeText = routeText.slice(0, semiIdx).trim();
            }

            var cdrTokens = routeText.split(/\s+/).filter(function(t) { return t !== ''; });
            if (!cdrTokens.length) {
                return '';
            }

            if (cdrTokens.length === 1) {
                var rawToken = cdrTokens[0];
                var hasMandatoryStart = rawToken.startsWith('>');
                var hasMandatoryEnd = rawToken.endsWith('<');
                var code = rawToken.replace(/[<>]/g, '').toUpperCase();
                if (cdrMap[code]) {
                    var expanded = cdrMap[code].toUpperCase();
                    // Apply mandatory markers to expanded route
                    if (hasMandatoryStart || hasMandatoryEnd) {
                        var expTokens = expanded.split(/\s+/).filter(Boolean);
                        if (expTokens.length > 2) {
                            // Find first non-facility (where route starts)
                            var routeStartIdx = 0;
                            for (var si = 0; si < expTokens.length; si++) {
                                if (!advIsFacility(expTokens[si])) {
                                    routeStartIdx = si;
                                    break;
                                }
                            }
                            // Find last non-facility (where route ends)
                            var routeEndIdx = expTokens.length - 1;
                            for (var ei = expTokens.length - 1; ei >= 0; ei--) {
                                if (!advIsFacility(expTokens[ei])) {
                                    routeEndIdx = ei;
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
                var expandedTokens = [];
                cdrTokens.forEach(function(tok) {
                    var code2 = tok.replace(/[<>]/g, '').toUpperCase();
                    if (cdrMap[code2]) {
                        var rtoks = cdrMap[code2].toUpperCase().split(/\s+/).filter(Boolean);
                        expandedTokens.push.apply(expandedTokens, rtoks);
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

        return routeStrings.filter(function(r) {
            return r && r.trim().length;
        });
    }

    
    function advGenerateTmiId(advAction, advFacility, advNumber, existingId) {
        existingId  = (existingId || '').toString().trim().toUpperCase();
        advAction   = (advAction || '').toString().trim().toUpperCase();
        advFacility = (advFacility || '').toString().trim().toUpperCase();
        advNumber   = (advNumber || '').toString().trim();

        // User-supplied ID (if the field still exists) wins
        if (existingId) return existingId;
        if (!advFacility || !advNumber) return '';

        let digits = advNumber.replace(/\D/g, '');
        if (digits.length) {
            digits = digits.padStart(3, '0');
        } else {
            digits = advNumber;
        }

        let prefix = '';
        // Simple mapping: ROUTE RQD → RR*. You can extend this later for GDP/GS/etc.
        if (advAction.indexOf('ROUTE') !== -1) {
            prefix = 'RR';
        }

        if (!prefix) return '';
        return prefix + advFacility + digits;
    }



function expandGroupsInRouteInput(text) {
    text = (text || '').toString();

    const lines = text.split(/\r?\n/);
    const outLines = [];

    let currentGroupColor = null;
    let currentGroupMandatory = false;

    lines.forEach(function(line) {
        if (!line) {
            // Blank line resets group context
            currentGroupColor = null;
            currentGroupMandatory = false;
            return;
        }

        const trimmed = line.trim();
        if (!trimmed) {
            // Blank line resets group context
            currentGroupColor = null;
            currentGroupMandatory = false;
            return;
        }

        // New group-header syntax:
        //   [GROUP]
        //   [GROUP];red
        //   >[GROUP]<
        //   >[GROUP]<;red
        const headerMatch = trimmed.match(/^(>?)\s*\[([^\]]+)\]\s*(<?)\s*(?:;(.+))?$/i);

        if (headerMatch) {
            // Reset group modifiers for the new group
            currentGroupColor = null;
            currentGroupMandatory = false;

            const leadingArrow  = headerMatch[1] || '';   // '>' or ''
            const trailingArrow = headerMatch[3] || '';   // '<' or ''
            const colorPart     = headerMatch[4];         // text after ';' (if any)

            if (colorPart) {
                currentGroupColor = colorPart.trim().toUpperCase();
            }

            if (leadingArrow === '>' || trailingArrow === '<') {
                currentGroupMandatory = true;
            }

            // Header line does not directly produce a route
            return;
        }

        // Optional: backward-compat for old-style headers with modifiers *inside* the brackets
        // e.g. [GROUP><;RED] (safe to keep; you can remove if you don't need it)
        if (trimmed.startsWith('[') && trimmed.endsWith(']')) {
            currentGroupColor = null;
            currentGroupMandatory = false;

            let inner = trimmed.slice(1, -1).trim();

            if (inner.includes('><')) {
                currentGroupMandatory = true;
                inner = inner.replace('><', '').trim();
            }

            const semiIdxOld = inner.indexOf(';');
            if (semiIdxOld !== -1) {
                const colorInside = inner.slice(semiIdxOld + 1).trim();
                if (colorInside) {
                    currentGroupColor = colorInside.toUpperCase();
                }
            }

            return; // header only
        }

        // Normal route line (may still pick up group modifiers)
        let baseLine = trimmed;
        let colorSuffix = '';

        // If the individual route line itself has a ;color, keep that
        const semiIdxLine = baseLine.indexOf(';');
        if (semiIdxLine !== -1) {
            colorSuffix = baseLine.slice(semiIdxLine); // includes ';'
            baseLine = baseLine.slice(0, semiIdxLine).trim();
        }

        // Apply group color if route has no explicit color
        if (!colorSuffix && currentGroupColor) {
            colorSuffix = ';' + currentGroupColor.toUpperCase();
        }

        // Apply group mandatory wrapper if requested
        let body = baseLine;
        if (currentGroupMandatory) {
            const bodyTrim = body.trim();
            if (!(bodyTrim.startsWith('>') && bodyTrim.endsWith('<'))) {
                body = '>' + bodyTrim + '<';
            } else {
                body = bodyTrim;
            }
        }

        outLines.push(body + (colorSuffix || ''));
    });

    return outLines.join('\n');
}

function generateRerouteAdvisory() {
        const rawInput     = $('#routeSearch').val() || '';
        const routeStrings = collectRouteStringsForAdvisory(rawInput);

        if (!routeStrings.length) {
            alert('No routes found to build an advisory.');
            return;
        }

        const parsedRoutes = advParseRoutesFromStrings(routeStrings);

        // Simple dedupe of identical ORIG/DEST/ROUTE triples; avoid graph-based consolidation
        const consolidated = [];
        const seenKeys = new Set();
        (parsedRoutes || []).forEach(function(r) {
            const key = (r.orig || '') + '|' + (r.dest || '') + '|' + (r.assignedRoute || '');
            if (!seenKeys.has(key)) {
                seenKeys.add(key);
                consolidated.push(r);
            }
        });

        // Auto-populate "Include Traffic" field with a default based on
        // the currently displayed routes if the user has not specified one.
        advEnsureDefaultIncludedTraffic(parsedRoutes);

        const advNumber    = ($('#advNumber').val() || '001').toString().trim().toUpperCase();
        const advFacility  = ($('#advFacility').val() || 'DCC').toString().trim().toUpperCase();
        const advDate      = ($('#advDate').val() || '').toString().trim();
        const advAction    = ($('#advAction').val() || 'ROUTE RQD').toString().trim().toUpperCase();

        const advName      = ($('#advName').val() || '').toString().trim().toUpperCase();
        const advConArea   = ($('#advConstrainedArea').val() || '').toString().trim().toUpperCase();
        const advReason    = ($('#advReason').val() || '').toString().trim().toUpperCase();
        const advInclTraff = ($('#advIncludeTraffic').val() || '').toString().trim().toUpperCase();
        const advFacilsInc = ($('#advFacilities').val() || '').toString().trim().toUpperCase();
        const advFltStatus = ($('input[name="advFlightStatus"]:checked').val() || 'ALL FLIGHTS').toString().trim().toUpperCase();
        const advValidFrom = ($('#advValidStart').val() || '').toString().trim().toUpperCase();
        const advValidTo   = ($('#advValidEnd').val() || '').toString().trim().toUpperCase();
        const advTimeBasis = ($('input[name="advTimeBasis"]:checked').val() || 'ETD').toString().trim().toUpperCase();
        const advProb      = ($('#advProb').val() || '').toString().trim().toUpperCase();
        const advRemarks   = ($('#advRemarks').val() || '').toString().trim().toUpperCase();
        const advRestr     = ($('#advRestrictions').val() || '').toString().trim().toUpperCase();
        const advMods      = ($('#advMods').val() || '').toString().trim().toUpperCase();
        const rawTmiId   = ($('#advTmiId').val() || '').toString();
        const advEffTime = ($('#advEffectiveTime').val() || '').toString().trim().toUpperCase();

        const advTmiId = advGenerateTmiId(advAction, advFacility, advNumber, rawTmiId);

        const lines = [];

        // Header line – {ORG_PREFIX} ADVZY NNN FAC MM/DD/YYYY ROUTE RQD
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
            const toStr   = advValidTo   || 'DDHHMM';

            if (advTimeBasis === 'ENTRY') {
                validText = 'ENTRY TIME ' + fromStr + ' TO ' + toStr;
            } else {
                // ETD or ETA
                validText = advTimeBasis + ' ' + fromStr + ' TO ' + toStr;
            }
        }
        advAddLabeledField(lines, 'VALID', validText);
        advAddLabeledField(lines, 'PROBABILITY OF EXTENSION', advProb);
        advAddLabeledField(lines, 'REMARKS', advRemarks);
        advAddLabeledField(lines, 'ASSOCIATED RESTRICTIONS', advRestr);
        advAddLabeledField(lines, 'MODIFICATIONS', advMods);

        // First, group routes by (route string) → collect all (origin, dest) pairs
        // Then consolidate: routes with same origin set get destinations grouped
        
        // Step 1: Build a map of route → array of {orig, dest}
        const routeMap = {};
        consolidated.forEach(function(r) {
            const cleanRoute = advCleanRouteString(r.assignedRoute).toUpperCase().trim();
            if (!routeMap[cleanRoute]) {
                routeMap[cleanRoute] = [];
            }
            
            // Extract individual airports/facilities from origin
            var origTokens = (r.orig || '').split(/\s+/).filter(Boolean);
            var origItems = origTokens.filter(function(t) { return advIsAirport(t) || advIsFacility(t); });
            if (!origItems.length) origItems = [r.orig];
            
            // Extract individual airports/facilities from dest
            var destTokens = (r.dest || '').split(/\s+/).filter(Boolean);
            var destItems = destTokens.filter(function(t) { return advIsAirport(t) || advIsFacility(t); });
            if (!destItems.length) destItems = [r.dest];
            
            // Add each origin-dest pair
            origItems.forEach(function(o) {
                destItems.forEach(function(d) {
                    routeMap[cleanRoute].push({ orig: o, dest: d });
                });
            });
        });
        
        // Step 2: For each route, group by origin to find shared destinations
        // Then further consolidate: if multiple origins share the same destination set, group them
        const finalGroups = [];
        
        Object.keys(routeMap).forEach(function(route) {
            const pairs = routeMap[route];
            
            // Group by origin → set of destinations
            const origToDestsMap = {};
            pairs.forEach(function(p) {
                if (!origToDestsMap[p.orig]) {
                    origToDestsMap[p.orig] = new Set();
                }
                origToDestsMap[p.orig].add(p.dest);
            });
            
            // Now reverse: group origins that share the same destination set
            // Key = sorted destinations joined, Value = array of origins
            const destSetToOrigins = {};
            Object.keys(origToDestsMap).forEach(function(orig) {
                const dests = Array.from(origToDestsMap[orig]).sort();
                const destKey = dests.join('|');
                if (!destSetToOrigins[destKey]) {
                    destSetToOrigins[destKey] = { dests: dests, origins: [] };
                }
                destSetToOrigins[destKey].origins.push(orig);
            });
            
            // Create final groups
            Object.keys(destSetToOrigins).forEach(function(destKey) {
                const group = destSetToOrigins[destKey];
                group.origins.sort();
                finalGroups.push({
                    origins: group.origins,
                    dests: group.dests,
                    route: route
                });
            });
        });
        
        // Step 3: Sort groups for consistent output
        // Primary: by first destination, Secondary: by first origin
        finalGroups.sort(function(a, b) {
            const destCmp = (a.dests[0] || '').localeCompare(b.dests[0] || '');
            if (destCmp !== 0) return destCmp;
            return (a.origins[0] || '').localeCompare(b.origins[0] || '');
        });
        
        // Calculate column widths based on space-separated format
        var maxOrigLen = 4;
        var maxDestLen = 4;
        finalGroups.forEach(function(g) {
            var origDisplay = g.origins.join(' ');
            var destDisplay = g.dests.join(' ');
            if (origDisplay.length > maxOrigLen) maxOrigLen = origDisplay.length;
            if (destDisplay.length > maxDestLen) maxDestLen = destDisplay.length;
        });
        
        // Constrain column widths to leave room for route text within 68 char max
        // Reserve at least 20 chars for route text on first line
        const maxTotalCols = ADV_MAX_LINE - 20;  // 48 chars max for ORIG + DEST
        
        // Start with ideal widths
        var idealOrigCol = Math.min(maxOrigLen + 2, 30);
        var idealDestCol = Math.min(maxDestLen + 2, 20);
        
        // If combined exceeds max, scale down proportionally
        if (idealOrigCol + idealDestCol > maxTotalCols) {
            var ratio = maxTotalCols / (idealOrigCol + idealDestCol);
            idealOrigCol = Math.max(6, Math.floor(idealOrigCol * ratio));
            idealDestCol = Math.max(6, Math.floor(idealDestCol * ratio));
        }
        
        ADV_ORIG_COL = idealOrigCol;
        ADV_DEST_COL = idealDestCol;

        lines.push('');
        lines.push('ROUTES:');
        lines.push('');

        // Header row
        advFormatRouteRow('ORIG', 'DEST', 'ROUTE').forEach(function(l) { lines.push(l); });
        advFormatRouteRow('----', '----', '-----').forEach(function(l) { lines.push(l); });

        // Output routes - group by first destination for visual separation
        var currentFirstDest = null;
        finalGroups.forEach(function(g) {
            var firstDest = g.dests[0] || '';
            if (currentFirstDest !== null && currentFirstDest !== firstDest) {
                lines.push('');  // Blank line between destination groups
            }
            currentFirstDest = firstDest;
            
            // Format as space-separated
            var origDisplay = g.origins.join(' ');
            var destDisplay = g.dests.join(' ');
            advFormatRouteRow(origDisplay, destDisplay, g.route).forEach(function(l) { lines.push(l); });
        });

        lines.push('');

        if (advTmiId) {
            lines.push('TMI ID: ' + advTmiId);
        }

        lines.push('EFFECTIVE TIME: ' + (advEffTime || ''));
        $('#advOutput').val(lines.join('\n'));
    }

    // Wire up buttons + collapsible panel
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

        // Auto-calculate facilities when panel is shown (if routes exist and field is empty)
        if (!isVisible) {
            const hasRoutes = ($('#routeSearch').val() || '').trim().length > 0;
            const facilitiesEmpty = ($('#advFacilities').val() || '').trim().length === 0;
            if (hasRoutes && facilitiesEmpty) {
                // Small delay to let panel animate open
                setTimeout(function() {
                    advCalculateFacilitiesFromGIS();
                }, 200);
            }
        }
    });

    // Clear auto badge when user manually edits facilities field
    $('#advFacilities').on('input', function() {
        $(this).data('autoCalculated', false);
        $('#advFacilitiesAutoBadge').hide();
    });

    // Set default advisory date/time values in UTC
    /**
     * Set default advisory times in UTC
     * Start: current UTC time
     * End: start + 3 hours, snapped to next :14, :29, :44, or :59
     * Mirrors GS time handling in tmi.js initializeDefaultGsTimes()
     */
    function advSetDefaultTimesUtc() {
        function pad2(n) {
            return n.toString().padStart(2, '0');
        }

        const now = new Date();

        // Date field (today's date in UTC, MM/DD/YYYY)
        const mm  = pad2(now.getUTCMonth() + 1);
        const dd  = pad2(now.getUTCDate());
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

        const ddEnd   = pad2(end.getUTCDate());
        const hhEnd   = pad2(end.getUTCHours());
        const mnEnd   = pad2(end.getUTCMinutes());

        const validStartStr = ddStart + hhStart + mnStart;
        const validEndStr   = ddEnd   + hhEnd   + mnEnd;

        $('#advValidStart').val(validStartStr);
        $('#advValidEnd').val(validEndStr);

        // Effective time block: DDHHMM-DDHHMM (UTC)
        $('#advEffectiveTime').val(validStartStr + '-' + validEndStr);
    }
    
    /**
     * Update advisory effective time when valid start/end fields change
     */
    function advUpdateEffectiveTime() {
        const startVal = ($('#advValidStart').val() || '').trim();
        const endVal = ($('#advValidEnd').val() || '').trim();
        
        if (startVal && endVal) {
            $('#advEffectiveTime').val(startVal + '-' + endVal);
        }
    }
    
    // Auto-update effective time when valid start/end change
    $(document).on('input change', '#advValidStart, #advValidEnd', function() {
        advUpdateEffectiveTime();
    });

    function overlayAdd(overlay) {
        overlays.push(overlay);
    }

    function overlayRemove(overlay) {
        overlays = overlays.filter(function(i) { return i !== overlay; });
    }

    // Initialize default advisory times on load
    advSetDefaultTimesUtc();
    advInitFacilitiesDropdown();

    // ═══════════════════════════════════════════════════════════════════════════
    // ADL LIVE FLIGHTS MODULE - TSD Symbology Display
    // ═══════════════════════════════════════════════════════════════════════════

    const ADL = (function() {
        'use strict';

        // State management
        const state = {
            enabled: false,
            flights: [],
            filteredFlights: [],
            markers: new Map(),
            drawnRoutes: new Map(),  // flight_key -> { polyline, color }
            layer: null,
            routeLayer: null,  // Separate layer for flight routes
            refreshInterval: null,
            refreshRateMs: 15000,
            lastRefresh: null,
            trackedFlight: null,
            selectedFlight: null,
            colorBy: 'weight_class',  // Current color scheme
            filters: {
                weightClasses: ['SUPER', 'HEAVY', 'LARGE', 'SMALL', 'J', 'H', 'L', 'S', ''],
                origin: '',
                dest: '',
                carrier: '',
                altitudeMin: null,
                altitudeMax: null
            }
        };
        
        // Route colors for drawn flight plans
        const ROUTE_COLORS = ['#e74c3c', '#3498db', '#2ecc71', '#9b59b6', '#f39c12', '#1abc9c', '#e91e63', '#00bcd4'];
        var routeColorIndex = 0;

        // ─────────────────────────────────────────────────────────────────────
        // COLOR SCHEMES (FSM/TSD Standard)
        // ─────────────────────────────────────────────────────────────────────
        
        // Weight Class coloring (default)
        const WEIGHT_CLASS_COLORS = {
            'SUPER': '#ffc107',  // Yellow - Jumbo (A380, AN225)
            'J': '#ffc107',
            'HEAVY': '#dc3545',  // Red - Heavy (B777, B787, A330)
            'H': '#dc3545',
            'LARGE': '#28a745',  // Green - Large (B737, A320)
            'L': '#28a745',
            'SMALL': '#000000',  // Black - Small
            'S': '#000000',
            '': '#6c757d'        // Gray - Unknown
        };
        
        // Aircraft Category coloring
        const AIRCRAFT_CATEGORY_COLORS = {
            'J': '#dc3545',      // Red - Jet
            'JET': '#dc3545',
            'T': '#28a745',      // Green - Turbo
            'TURBO': '#28a745',
            'P': '#000000',      // Black - Prop
            'PROP': '#000000',
            '': '#ffffff'        // White - Unknown
        };
        
        // Carrier coloring - use FILTER_CONFIG if available, fallback to local
        const CARRIER_COLORS = (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.carrier)
            ? Object.assign({}, FILTER_CONFIG.carrier.colors, { '': FILTER_CONFIG.carrier.colors['OTHER'] || '#6c757d' })
            : {
            // US Majors
            'AAL': '#0078d2',    // American - Royal Blue
            'UAL': '#0033a0',    // United - Dark Blue
            'DAL': '#e01933',    // Delta - Delta Red
            'SWA': '#f9b612',    // Southwest - Mustard Yellow
            'JBU': '#003876',    // JetBlue - Navy Blue
            'ASA': '#00a8e0',    // Alaska - Alaska Teal
            'FFT': '#2b8542',    // Frontier - Green
            'NKS': '#ffd200',    // Spirit - Yellow
            'HAL': '#5b2e91',    // Hawaiian - Purple
            // US Cargo
            'FDX': '#ff6600',    // FedEx - Orange
            'UPS': '#351c15',    // UPS - Brown
            'GTI': '#002d72',    // Atlas - Dark Blue
            'ABX': '#cc0000',    // ABX - Red
            // US Regionals
            'SKW': '#6cace4',    // SkyWest - Light Blue
            'RPA': '#00b5ad',    // Republic - Teal
            'ENY': '#0078d2',    // Envoy - American Blue
            'PDT': '#0078d2',    // Piedmont - American Blue
            'JIA': '#0033a0',    // PSA - United Blue
            'ASH': '#0033a0',    // Mesa - United Blue
            'CPZ': '#00a8e0',    // Compass - Alaska Blue
            'GJS': '#e01933',    // GoJet - Delta Red
            'EDV': '#e01933',    // Endeavor - Delta Red
            // International
            'BAW': '#075aaa',    // British Airways - BA Blue
            'DLH': '#00195c',    // Lufthansa - Lufthansa Blue
            'AFR': '#002157',    // Air France - AF Blue
            'KLM': '#00a1e4',    // KLM - KLM Blue
            'ACA': '#f01428',    // Air Canada - AC Red
            'WJA': '#003082',    // WestJet - WJ Blue
            'UAE': '#c8a96b',    // Emirates - Gold
            'QTR': '#5c0632',    // Qatar - Maroon
            'SIA': '#f7c917',    // Singapore - Gold
            'CPA': '#006564',    // Cathay - CX Green
            'JAL': '#cc0000',    // Japan Airlines - Red
            'ANA': '#1a3b73',    // All Nippon - Blue
            'QFA': '#e40000',    // Qantas - Red
            'VIR': '#da291c',    // Virgin Atlantic - Red
            'IBE': '#d71920',    // Iberia - Red
            'AZA': '#006642',    // Alitalia - Green
            'SAS': '#00005f',    // SAS - Dark Blue
            'TAP': '#008542',    // TAP - Green
            'THY': '#c80815',    // Turkish - Red
            'ETH': '#4ca22f',    // Ethiopian - Green
            'SAA': '#0a3161',    // South African - Blue
            'MEX': '#00295b',    // Aeromexico - Blue
            'AVA': '#ed1c24',    // Avianca - Red
            'LAN': '#1e22aa',    // LATAM - Blue
            'GLO': '#ff5a00',    // GOL - Orange
            // Private/Charter
            'EJA': '#8b4513',    // NetJets - Brown
            'XOJ': '#4a4a4a',    // XOJET - Dark Gray
            'LEJ': '#1a1a1a',    // LJ - Black
            '': '#6c757d'        // Default - Gray
        };
        
        // Center (ARTCC) coloring - all 22 CONUS + offshore
        const CENTER_COLORS = {
            'ZAB': '#e6194b',    // Albuquerque - Red
            'ZAU': '#3cb44b',    // Chicago - Green
            'ZBW': '#ffe119',    // Boston - Yellow
            'ZDC': '#4363d8',    // Washington - Blue
            'ZDV': '#f58231',    // Denver - Orange
            'ZFW': '#911eb4',    // Fort Worth - Purple
            'ZHU': '#46f0f0',    // Houston - Cyan
            'ZID': '#f032e6',    // Indianapolis - Magenta
            'ZJX': '#bcf60c',    // Jacksonville - Lime
            'ZKC': '#fabebe',    // Kansas City - Pink
            'ZLA': '#008080',    // Los Angeles - Teal
            'ZLC': '#e6beff',    // Salt Lake - Lavender
            'ZMA': '#9a6324',    // Miami - Brown
            'ZME': '#800080',    // Memphis - Purple
            'ZMP': '#800000',    // Minneapolis - Maroon
            'ZNY': '#00ff00',    // New York - Bright Green
            'ZOA': '#808000',    // Oakland - Olive
            'ZOB': '#ffa07a',    // Cleveland - Light Salmon
            'ZSE': '#000075',    // Seattle - Navy
            'ZTL': '#ff4500',    // Atlanta - Orange Red
            'ZAN': '#ffffff',    // Anchorage - White
            'ZHN': '#ff69b4',    // Honolulu - Hot Pink
            '': '#6c757d'        // Unknown - Gray
        };
        
        // DCC Region coloring - use FILTER_CONFIG if available
        const DCC_REGION_COLORS = (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.dccRegion)
            ? Object.assign({}, FILTER_CONFIG.dccRegion.colors, { '': FILTER_CONFIG.dccRegion.colors['OTHER'] || '#6c757d' })
            : {
            'WEST': '#dc3545',           // West - Red
            'SOUTH_CENTRAL': '#fd7e14',  // South Central - Orange
            'MIDWEST': '#28a745',        // Midwest - Green
            'SOUTHEAST': '#ffc107',      // Southeast - Yellow
            'NORTHEAST': '#007bff',      // Northeast - Blue
            'CANADA_EAST': '#9b59b6',    // Canada East - Purple
            'CANADA_WEST': '#ff69b4',    // Canada West - Pink
            '': '#6c757d'                // Unknown - Gray
        };

        // Map ARTCCs to DCC regions - use FILTER_CONFIG if available
        const ARTCC_TO_DCC = (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.dccRegion && FILTER_CONFIG.dccRegion.mapping)
            ? FILTER_CONFIG.dccRegion.mapping
            : {
            // DCC West (Red)
            'ZAK': 'WEST', 'ZAN': 'WEST', 'ZHN': 'WEST', 'ZLA': 'WEST',
            'ZLC': 'WEST', 'ZOA': 'WEST', 'ZSE': 'WEST',
            // DCC South Central (Orange)
            'ZAB': 'SOUTH_CENTRAL', 'ZFW': 'SOUTH_CENTRAL', 'ZHO': 'SOUTH_CENTRAL',
            'ZHU': 'SOUTH_CENTRAL', 'ZME': 'SOUTH_CENTRAL',
            // DCC Midwest (Green)
            'ZAU': 'MIDWEST', 'ZDV': 'MIDWEST', 'ZKC': 'MIDWEST', 'ZMP': 'MIDWEST',
            // DCC Southeast (Yellow)
            'ZID': 'SOUTHEAST', 'ZJX': 'SOUTHEAST', 'ZMA': 'SOUTHEAST',
            'ZMO': 'SOUTHEAST', 'ZTL': 'SOUTHEAST',
            // DCC Northeast (Blue)
            'ZBW': 'NORTHEAST', 'ZDC': 'NORTHEAST', 'ZNY': 'NORTHEAST',
            'ZOB': 'NORTHEAST', 'ZWY': 'NORTHEAST',
            // Canada East (Purple)
            'CZYZ': 'CANADA_EAST', 'CZUL': 'CANADA_EAST', 'CZZV': 'CANADA_EAST',
            'CZQM': 'CANADA_EAST', 'CZQX': 'CANADA_EAST', 'CZQO': 'CANADA_EAST',
            // Canada West (Pink)
            'CZWG': 'CANADA_WEST', 'CZEG': 'CANADA_WEST', 'CZVR': 'CANADA_WEST'
        };
        
        // TRACON coloring - major TRACONs
        const TRACON_COLORS = {
            'N90': '#00ff00',    // New York TRACON - Bright Green
            'SCT': '#008080',    // SoCal - Teal
            'NCT': '#808000',    // NorCal - Olive
            'PCT': '#4363d8',    // Potomac - Blue
            'A80': '#ff4500',    // Atlanta - Orange Red
            'C90': '#3cb44b',    // Chicago - Green
            'D10': '#911eb4',    // Dallas - Purple
            'I90': '#f032e6',    // Houston - Magenta
            'D21': '#f58231',    // Detroit - Orange
            'M98': '#fabebe',    // Minneapolis - Pink
            'S46': '#000075',    // Seattle - Navy
            'D01': '#800000',    // Denver - Maroon
            'R90': '#bcf60c',    // Orlando - Lime
            'MIA': '#9a6324',    // Miami - Brown
            'BOS': '#ffe119',    // Boston - Yellow
            'PHL': '#e6194b',    // Philadelphia - Red
            'LAS': '#ff69b4',    // Las Vegas - Hot Pink
            'PHX': '#e6194b',    // Phoenix - Red
            '': '#6c757d'        // Unknown - Gray
        };
        
        // Map airports to TRACONs
        const AIRPORT_TO_TRACON = {
            // N90
            'KJFK': 'N90', 'KLGA': 'N90', 'KEWR': 'N90', 'KTEB': 'N90', 'KPHL': 'N90',
            // SCT
            'KLAX': 'SCT', 'KSAN': 'SCT', 'KONT': 'SCT', 'KBUR': 'SCT', 'KSNA': 'SCT', 'KLGB': 'SCT',
            // NCT  
            'KSFO': 'NCT', 'KOAK': 'NCT', 'KSJC': 'NCT',
            // PCT
            'KDCA': 'PCT', 'KIAD': 'PCT', 'KBWI': 'PCT',
            // A80
            'KATL': 'A80',
            // C90
            'KORD': 'C90', 'KMDW': 'C90',
            // D10
            'KDFW': 'D10', 'KDAL': 'D10',
            // I90
            'KIAH': 'I90', 'KHOU': 'I90',
            // D21
            'KDTW': 'D21',
            // M98
            'KMSP': 'M98',
            // S46
            'KSEA': 'S46',
            // D01
            'KDEN': 'D01',
            // Others
            'KMCO': 'R90', 'KMIA': 'MIA', 'KFLL': 'MIA', 
            'KBOS': 'BOS', 'KLAS': 'LAS', 'KPHX': 'PHX'
        };
        
        // Arrival/Departure coloring
        const ARR_DEP_COLORS = {
            'ARR': '#90ee90',    // Light Green - Arriving
            'DEP': '#4363d8',    // Blue - Departing
            '': '#6c757d'        // Unknown - Gray
        };
        
        // Altitude block coloring (in hundreds of feet / flight levels)
        const ALTITUDE_BLOCK_COLORS = {
            'GROUND': '#8b4513',  // Brown - On ground
            'LOW': '#32cd32',     // Lime Green - Below FL180
            'MED': '#1e90ff',     // Dodger Blue - FL180-FL290
            'HIGH': '#9370db',    // Medium Purple - FL290-FL410
            'VHIGH': '#ff1493',   // Deep Pink - Above FL410
            '': '#6c757d'         // Unknown - Gray
        };
        
        // Speed coloring (above/below 250kts)
        const SPEED_COLORS = {
            'SLOW': '#ffa500',    // Orange - Below 250kts
            'FAST': '#1e90ff',    // Blue - 250kts and above
            '': '#6c757d'         // Unknown - Gray
        };
        
        // Aircraft type colors - use FILTER_CONFIG if available, fallback to local
        const AIRCRAFT_TYPE_COLORS = (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.equipment && FILTER_CONFIG.equipment.colors)
            ? Object.assign({}, FILTER_CONFIG.equipment.colors, { '': FILTER_CONFIG.equipment.colors['OTHER'] || '#6c757d' })
            : {
            // Boeing Narrowbody
            'B737': '#0078d2', 'B738': '#0078d2', 'B739': '#0078d2', 'B38M': '#0078d2', 'B39M': '#0078d2',
            'B752': '#0055a5', 'B753': '#0055a5',
            // Boeing Widebody
            'B763': '#4169e1', 'B764': '#4169e1',
            'B772': '#1e90ff', 'B773': '#1e90ff', 'B77W': '#1e90ff', 'B77L': '#1e90ff',
            'B788': '#00bfff', 'B789': '#00bfff', 'B78X': '#00bfff',
            'B744': '#6495ed', 'B748': '#6495ed',
            // Airbus Narrowbody
            'A319': '#e01933', 'A320': '#e01933', 'A321': '#e01933', 'A20N': '#e01933', 'A21N': '#e01933',
            // Airbus Widebody
            'A332': '#dc143c', 'A333': '#dc143c', 'A339': '#dc143c',
            'A342': '#b22222', 'A343': '#b22222', 'A345': '#b22222', 'A346': '#b22222',
            'A359': '#ff4500', 'A35K': '#ff4500',
            'A388': '#ff6347',
            // Embraer
            'E170': '#228b22', 'E75S': '#228b22', 'E75L': '#228b22',
            'E190': '#32cd32', 'E195': '#32cd32', 'E290': '#32cd32', 'E295': '#32cd32',
            // CRJ
            'CRJ2': '#8b4513', 'CRJ7': '#a0522d', 'CRJ9': '#cd853f', 'CRJX': '#deb887',
            // Turboprops
            'DH8A': '#808000', 'DH8B': '#808000', 'DH8C': '#808000', 'DH8D': '#9acd32',
            'AT43': '#6b8e23', 'AT45': '#6b8e23', 'AT72': '#556b2f', 'AT76': '#556b2f',
            // Business Jets
            'C56X': '#4a4a4a', 'C68A': '#4a4a4a', 'C700': '#4a4a4a', 'C750': '#4a4a4a',
            'CL30': '#5a5a5a', 'CL35': '#5a5a5a', 'CL60': '#5a5a5a',
            'GL5T': '#3a3a3a', 'GL7T': '#3a3a3a', 'GLEX': '#3a3a3a',
            'GLF4': '#2a2a2a', 'GLF5': '#2a2a2a', 'GLF6': '#2a2a2a', 'G280': '#2a2a2a',
            'E55P': '#6a6a6a', 'E545': '#6a6a6a', 'E550': '#6a6a6a',
            'LJ35': '#7a7a7a', 'LJ45': '#7a7a7a', 'LJ60': '#7a7a7a',
            // Cargo specific
            'MD11': '#ff8c00', 'DC10': '#ff8c00',
            'A306': '#ff7f50', 'A30B': '#ff7f50',
            '': '#6c757d'
        };
        
        // Dynamic color generator for airports (hash-based)
        function getAirportColor(code) {
            if (!code) return '#6c757d';
            // Generate consistent color from airport code
            var hash = 0;
            for (var i = 0; i < code.length; i++) {
                hash = code.charCodeAt(i) + ((hash << 5) - hash);
            }
            var hue = Math.abs(hash) % 360;
            return 'hsl(' + hue + ', 70%, 50%)';
        }

        // TSD Icon Colors by weight class (legacy - kept for size reference)
        const WEIGHT_COLORS = WEIGHT_CLASS_COLORS;

        // TSD Icon Sizes by weight class - made larger for visibility
        const WEIGHT_SIZES = {
            'SUPER': 28, 'J': 28,
            'HEAVY': 24, 'H': 24,
            'LARGE': 20, 'L': 20,
            'SMALL': 16, 'S': 16,
            '': 18
        };

        // ─────────────────────────────────────────────────────────────────────
        // SVG ICON GENERATION
        // ─────────────────────────────────────────────────────────────────────

        // Get carrier code from callsign (first 3 letters typically)
        function extractCarrier(callsign) {
            if (!callsign) return '';
            var cs = callsign.toUpperCase().trim();
            // Most callsigns are 3-letter carrier + flight number
            var match = cs.match(/^([A-Z]{3})/);
            return match ? match[1] : '';
        }
        
        // Get center from airport code (lookup or derive from position)
        function getAirportCenter(airportCode) {
            // Simple mapping of major airports to ARTCCs
            // This could be expanded with a full database lookup
            var centerMap = {
                // ZNY
                'KJFK': 'ZNY', 'KLGA': 'ZNY', 'KEWR': 'ZNY', 'KTEB': 'ZNY',
                // ZBW  
                'KBOS': 'ZBW', 'KBDL': 'ZBW', 'KPVD': 'ZBW',
                // ZDC
                'KDCA': 'ZDC', 'KIAD': 'ZDC', 'KBWI': 'ZDC', 'KPHL': 'ZDC',
                // ZTL
                'KATL': 'ZTL', 'KCLT': 'ZTL',
                // ZMA
                'KMIA': 'ZMA', 'KFLL': 'ZMA', 'KPBI': 'ZMA',
                // ZJX
                'KJAX': 'ZJX', 'KMCO': 'ZJX', 'KTPA': 'ZJX',
                // ZAU
                'KORD': 'ZAU', 'KMDW': 'ZAU',
                // ZID
                'KIND': 'ZID', 'KCVG': 'ZID', 'KSDF': 'ZID',
                // ZOB
                'KCLE': 'ZOB', 'KPIT': 'ZOB', 'KDTW': 'ZOB',
                // ZKC
                'KMCI': 'ZKC', 'KSTL': 'ZKC',
                // ZMP
                'KMSP': 'ZMP',
                // ZME
                'KMEM': 'ZME', 'KBNA': 'ZME',
                // ZFW
                'KDFW': 'ZFW', 'KHOU': 'ZFW', 'KIAH': 'ZFW', 'KAUS': 'ZFW', 'KSAT': 'ZFW',
                // ZHU
                'KHOU': 'ZHU', 'KIAH': 'ZHU',
                // ZDV
                'KDEN': 'ZDV', 'KCOS': 'ZDV',
                // ZAB
                'KPHX': 'ZAB', 'KABQ': 'ZAB', 'KTUS': 'ZAB', 'KELP': 'ZAB',
                // ZLA
                'KLAX': 'ZLA', 'KSAN': 'ZLA', 'KLAS': 'ZLA', 'KONT': 'ZLA', 'KBURB': 'ZLA',
                // ZOA
                'KSFO': 'ZOA', 'KOAK': 'ZOA', 'KSJC': 'ZOA',
                // ZSE
                'KSEA': 'ZSE', 'KPDX': 'ZSE',
                // ZLC
                'KSLC': 'ZLC'
            };
            if (!airportCode) return '';
            var code = airportCode.toUpperCase().trim();
            return centerMap[code] || '';
        }
        
        /**
         * Spectral color interpolation (red → orange → yellow → green → cyan → blue)
         * @param {number} t - Value from 0 (red/close) to 1 (blue/far)
         * @returns {string} RGB color
         */
        function getSpectralColor(t) {
            // Clamp t to 0-1
            t = Math.max(0, Math.min(1, t));
            
            // Spectral color stops
            var stops = [
                { t: 0.00, r: 255, g: 0,   b: 0   },   // Red
                { t: 0.20, r: 255, g: 128, b: 0   },   // Orange
                { t: 0.40, r: 255, g: 255, b: 0   },   // Yellow
                { t: 0.60, r: 0,   g: 200, b: 0   },   // Green
                { t: 0.80, r: 0,   g: 200, b: 255 },   // Cyan
                { t: 1.00, r: 0,   g: 80,  b: 255 }    // Blue
            ];
            
            // Find the two stops to interpolate between
            var i = 0;
            while (i < stops.length - 1 && stops[i + 1].t < t) i++;
            
            var s1 = stops[i];
            var s2 = stops[Math.min(i + 1, stops.length - 1)];
            
            // Interpolate
            var range = s2.t - s1.t;
            var localT = range > 0 ? (t - s1.t) / range : 0;
            
            var r = Math.round(s1.r + (s2.r - s1.r) * localT);
            var g = Math.round(s1.g + (s2.g - s1.g) * localT);
            var b = Math.round(s1.b + (s2.b - s1.b) * localT);
            
            return 'rgb(' + r + ', ' + g + ', ' + b + ')';
        }
        
        // Helper: ETA relative color - spectral colormap (red = close, blue = far)
        function getEtaRelativeColor(etaUtc) {
            if (!etaUtc) return '#6c757d';
            var now = new Date();
            var eta = new Date(etaUtc);
            var diffMin = (eta - now) / 60000;
            
            if (diffMin <= 0) return '#6c757d'; // Past ETA
            
            // Map 0-480+ minutes to 0-1 (clamped)
            var maxMinutes = 480; // 8 hours
            var t = Math.min(diffMin / maxMinutes, 1);
            
            // Spectral interpolation: red → blue
            return getSpectralColor(t);
        }
        
        // Helper: ETA hour color - cyclical spectral colormap
        function getEtaHourColor(etaUtc) {
            if (!etaUtc) return '#6c757d';
            var eta = new Date(etaUtc);
            var hour = eta.getUTCHours();
            var minute = eta.getUTCMinutes();
            
            // Convert to fraction of day (0-1), cyclical
            var dayFraction = (hour + minute / 60) / 24;
            
            // Use HSL with full hue rotation for cyclical coloring
            // Shift so midnight is at red (hue 0)
            var hue = dayFraction * 360;
            return 'hsl(' + hue + ', 85%, 50%)';
        }
        
        // Helper: Strip ICAO equipment suffixes from aircraft type
        // e.g., "A359/L" -> "A359", "B738-L" -> "B738"
        function stripAircraftSuffixes(acType) {
            if (!acType) return '';
            return acType.split(/[\/\-_]/)[0].toUpperCase();
        }

        // Helper: Normalize weight class to standard form
        // Normalizes weight_class codes: J->SUPER, H->HEAVY, L->LARGE, S->SMALL
        // FSM Table 3-6: SUPER/J, HEAVY/H, LARGE/L, SMALL/S
        function getWeightClass(flight) {
            if (flight.weight_class) {
                var wc = flight.weight_class.toUpperCase();
                if (wc === 'SUPER' || wc === 'J' || wc === 'JUMBO') return 'SUPER';
                if (wc === 'HEAVY' || wc === 'H') return 'HEAVY';
                if (wc === 'LARGE' || wc === 'L') return 'LARGE';
                if (wc === 'SMALL' || wc === 'S') return 'SMALL';
            }

            // Default to LARGE for unknown/jets
            return 'LARGE';
        }

        // Get flight color based on current color scheme
        function getFlightColor(flight) {
            var scheme = state.colorBy || 'weight_class';
            
            switch (scheme) {
                case 'weight_class':
                    var wc = getWeightClass(flight);
                    return WEIGHT_CLASS_COLORS[wc] || WEIGHT_CLASS_COLORS[''];

                case 'aircraft_category':
                    // Derive category from normalized weight class
                    var wc = getWeightClass(flight);
                    if (wc === 'SMALL') return AIRCRAFT_CATEGORY_COLORS['P'];
                    if (wc === 'LARGE' || wc === 'HEAVY' || wc === 'SUPER') {
                        return AIRCRAFT_CATEGORY_COLORS['J'];
                    }
                    return AIRCRAFT_CATEGORY_COLORS[''];

                case 'aircraft_type':
                    // Prefer aircraft_icao (clean code from DB), fallback to flight plan field
                    var acType = stripAircraftSuffixes(flight.aircraft_icao || flight.aircraft_type || '');
                    return AIRCRAFT_TYPE_COLORS[acType] || AIRCRAFT_TYPE_COLORS[''];
                    
                case 'carrier':
                    var carrier = extractCarrier(flight.callsign);
                    return CARRIER_COLORS[carrier] || CARRIER_COLORS[''];
                    
                case 'dep_center':
                    var depCenter = getAirportCenter(flight.fp_dept_icao);
                    return CENTER_COLORS[depCenter] || CENTER_COLORS[''];
                    
                case 'arr_center':
                    var arrCenter = getAirportCenter(flight.fp_dest_icao);
                    return CENTER_COLORS[arrCenter] || CENTER_COLORS[''];
                    
                case 'dep_tracon':
                    var depApt = (flight.fp_dept_icao || '').toUpperCase();
                    var depTracon = AIRPORT_TO_TRACON[depApt] || '';
                    return TRACON_COLORS[depTracon] || TRACON_COLORS[''];
                    
                case 'arr_tracon':
                    var arrApt = (flight.fp_dest_icao || '').toUpperCase();
                    var arrTracon = AIRPORT_TO_TRACON[arrApt] || '';
                    return TRACON_COLORS[arrTracon] || TRACON_COLORS[''];
                    
                case 'dcc_region':
                    var center = getAirportCenter(flight.fp_dept_icao) || getAirportCenter(flight.fp_dest_icao);
                    var dccRegion = ARTCC_TO_DCC[center] || '';
                    return DCC_REGION_COLORS[dccRegion] || DCC_REGION_COLORS[''];
                    
                case 'dep_airport':
                    return getAirportColor(flight.fp_dept_icao);
                    
                case 'arr_airport':
                    return getAirportColor(flight.fp_dest_icao);
                    
                case 'altitude':
                    var alt = parseInt(flight.altitude) || 0;
                    var altFl = alt / 100; // Convert to flight level
                    if (alt < 100) return ALTITUDE_BLOCK_COLORS['GROUND'];
                    if (altFl < 180) return ALTITUDE_BLOCK_COLORS['LOW'];
                    if (altFl < 290) return ALTITUDE_BLOCK_COLORS['MED'];
                    if (altFl < 410) return ALTITUDE_BLOCK_COLORS['HIGH'];
                    return ALTITUDE_BLOCK_COLORS['VHIGH'];
                    
                case 'speed':
                    var spd = parseInt(flight.groundspeed_kts || flight.groundspeed) || 0;
                    if (spd < 250) return SPEED_COLORS['SLOW'];
                    return SPEED_COLORS['FAST'];
                    
                case 'arr_dep':
                    // Determine if arriving or departing based on altitude/ground status
                    var alt = parseInt(flight.altitude) || 0;
                    if (flight.groundspeed && parseInt(flight.groundspeed) < 50) {
                        return ARR_DEP_COLORS['DEP']; // On ground = departing
                    }
                    return ARR_DEP_COLORS['ARR']; // In air = arriving
                    
                case 'eta_relative':
                    return getEtaRelativeColor(flight.eta_runway_utc);
                    
                case 'eta_hour':
                    return getEtaHourColor(flight.eta_runway_utc);

                case 'status':
                    // Phase-based coloring from phase-colors.js
                    var phase = (flight.phase || '').toLowerCase();
                    if (typeof PHASE_COLORS !== 'undefined' && PHASE_COLORS[phase]) {
                        return PHASE_COLORS[phase];
                    }
                    // Fallback for unknown phases
                    if (!phase) {
                        return (typeof PHASE_COLORS !== 'undefined') ? PHASE_COLORS['unknown'] : '#9333ea';
                    }
                    return '#999999';

                default:
                    return WEIGHT_CLASS_COLORS[''];
            }
        }
        
        // Render the color legend based on current scheme
        function renderColorLegend() {
            var $legend = $('#adl_color_legend');
            if (!$legend.length) return;
            
            var scheme = state.colorBy || 'weight_class';
            var items = [];
            
            switch (scheme) {
                case 'weight_class':
                    items = [
                        { color: WEIGHT_CLASS_COLORS['SUPER'], label: 'Super' },
                        { color: WEIGHT_CLASS_COLORS['HEAVY'], label: 'Heavy' },
                        { color: WEIGHT_CLASS_COLORS['LARGE'], label: 'Large' },
                        { color: WEIGHT_CLASS_COLORS['SMALL'], label: 'Small' }
                    ];
                    break;
                    
                case 'aircraft_category':
                    items = [
                        { color: AIRCRAFT_CATEGORY_COLORS['J'], label: 'Jet' },
                        { color: AIRCRAFT_CATEGORY_COLORS['T'], label: 'Turbo' },
                        { color: AIRCRAFT_CATEGORY_COLORS['P'], label: 'Prop' }
                    ];
                    break;
                    
                case 'aircraft_type':
                    items = [
                        { color: AIRCRAFT_TYPE_COLORS['B738'], label: 'B737' },
                        { color: AIRCRAFT_TYPE_COLORS['B772'], label: 'B777' },
                        { color: AIRCRAFT_TYPE_COLORS['B788'], label: 'B787' },
                        { color: AIRCRAFT_TYPE_COLORS['A320'], label: 'A320' },
                        { color: AIRCRAFT_TYPE_COLORS['A332'], label: 'A330' },
                        { color: AIRCRAFT_TYPE_COLORS['E75S'], label: 'E175' },
                        { color: AIRCRAFT_TYPE_COLORS['CRJ9'], label: 'CRJ' },
                        { color: AIRCRAFT_TYPE_COLORS[''], label: 'Other' }
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
                        { color: CARRIER_COLORS['UPS'], label: 'UPS' },
                        { color: CARRIER_COLORS['SKW'], label: 'SKW' },
                        { color: CARRIER_COLORS['BAW'], label: 'BAW' },
                        { color: CARRIER_COLORS[''], label: 'Other' }
                    ];
                    break;
                    
                case 'dep_center':
                case 'arr_center':
                    items = [
                        { color: CENTER_COLORS['ZNY'], label: 'ZNY' },
                        { color: CENTER_COLORS['ZDC'], label: 'ZDC' },
                        { color: CENTER_COLORS['ZBW'], label: 'ZBW' },
                        { color: CENTER_COLORS['ZTL'], label: 'ZTL' },
                        { color: CENTER_COLORS['ZJX'], label: 'ZJX' },
                        { color: CENTER_COLORS['ZMA'], label: 'ZMA' },
                        { color: CENTER_COLORS['ZAU'], label: 'ZAU' },
                        { color: CENTER_COLORS['ZID'], label: 'ZID' },
                        { color: CENTER_COLORS['ZOB'], label: 'ZOB' },
                        { color: CENTER_COLORS['ZKC'], label: 'ZKC' },
                        { color: CENTER_COLORS['ZMP'], label: 'ZMP' },
                        { color: CENTER_COLORS['ZME'], label: 'ZME' },
                        { color: CENTER_COLORS['ZFW'], label: 'ZFW' },
                        { color: CENTER_COLORS['ZHU'], label: 'ZHU' },
                        { color: CENTER_COLORS['ZDV'], label: 'ZDV' },
                        { color: CENTER_COLORS['ZAB'], label: 'ZAB' },
                        { color: CENTER_COLORS['ZLC'], label: 'ZLC' },
                        { color: CENTER_COLORS['ZLA'], label: 'ZLA' },
                        { color: CENTER_COLORS['ZOA'], label: 'ZOA' },
                        { color: CENTER_COLORS['ZSE'], label: 'ZSE' },
                        { color: CENTER_COLORS['ZAN'], label: 'ZAN' },
                        { color: CENTER_COLORS['ZHN'], label: 'ZHN' }
                    ];
                    break;
                    
                case 'dep_tracon':
                case 'arr_tracon':
                    items = [
                        { color: TRACON_COLORS['N90'], label: 'N90' },
                        { color: TRACON_COLORS['SCT'], label: 'SCT' },
                        { color: TRACON_COLORS['NCT'], label: 'NCT' },
                        { color: TRACON_COLORS['PCT'], label: 'PCT' },
                        { color: TRACON_COLORS['A80'], label: 'A80' },
                        { color: TRACON_COLORS['C90'], label: 'C90' },
                        { color: TRACON_COLORS['D10'], label: 'D10' },
                        { color: TRACON_COLORS['I90'], label: 'I90' },
                        { color: TRACON_COLORS['D21'], label: 'D21' },
                        { color: TRACON_COLORS['S46'], label: 'S46' },
                        { color: TRACON_COLORS['D01'], label: 'D01' },
                        { color: TRACON_COLORS[''], label: 'Other' }
                    ];
                    break;
                    
                case 'dcc_region':
                    items = [
                        { color: DCC_REGION_COLORS['WEST'], label: 'West' },
                        { color: DCC_REGION_COLORS['CENTRAL'], label: 'Central' },
                        { color: DCC_REGION_COLORS['EAST'], label: 'East' }
                    ];
                    break;
                    
                case 'dep_airport':
                case 'arr_airport':
                    items = [
                        { color: getAirportColor('KJFK'), label: 'JFK' },
                        { color: getAirportColor('KLAX'), label: 'LAX' },
                        { color: getAirportColor('KORD'), label: 'ORD' },
                        { color: getAirportColor('KATL'), label: 'ATL' },
                        { color: getAirportColor('KDFW'), label: 'DFW' },
                        { color: getAirportColor('KDEN'), label: 'DEN' },
                        { color: '#6c757d', label: 'Other' }
                    ];
                    break;
                    
                case 'altitude':
                    items = [
                        { color: ALTITUDE_BLOCK_COLORS['GROUND'], label: 'Ground' },
                        { color: ALTITUDE_BLOCK_COLORS['LOW'], label: '<FL180' },
                        { color: ALTITUDE_BLOCK_COLORS['MED'], label: 'FL180-290' },
                        { color: ALTITUDE_BLOCK_COLORS['HIGH'], label: 'FL290-410' },
                        { color: ALTITUDE_BLOCK_COLORS['VHIGH'], label: '>FL410' }
                    ];
                    break;
                    
                case 'speed':
                    items = [
                        { color: SPEED_COLORS['SLOW'], label: '<250kts' },
                        { color: SPEED_COLORS['FAST'], label: '≥250kts' }
                    ];
                    break;
                    
                case 'arr_dep':
                    items = [
                        { color: ARR_DEP_COLORS['ARR'], label: 'Arriving' },
                        { color: ARR_DEP_COLORS['DEP'], label: 'Departing' }
                    ];
                    break;

                case 'status':
                    // Flight phases from phase-colors.js
                    var PC = (typeof PHASE_COLORS !== 'undefined') ? PHASE_COLORS : {};
                    var PL = (typeof PHASE_LABELS !== 'undefined') ? PHASE_LABELS : {};
                    items = [
                        { color: PC['prefile'] || '#3b82f6', label: PL['prefile'] || 'Prefile' },
                        { color: PC['taxiing'] || '#22c55e', label: PL['taxiing'] || 'Taxiing' },
                        { color: PC['departed'] || '#f87171', label: PL['departed'] || 'Departed' },
                        { color: PC['enroute'] || '#dc2626', label: PL['enroute'] || 'Enroute' },
                        { color: PC['descending'] || '#991b1b', label: PL['descending'] || 'Descending' },
                        { color: PC['arrived'] || '#1a1a1a', label: PL['arrived'] || 'Arrived' },
                        { color: PC['disconnected'] || '#f97316', label: PL['disconnected'] || 'Disconnected' },
                        { color: PC['exempt'] || '#6b7280', label: PL['exempt'] || 'Exempt' },
                        { color: PC['actual_gs'] || '#eab308', label: 'GS' },
                        { color: PC['actual_gdp'] || '#92400e', label: 'GDP' },
                        { color: PC['unknown'] || '#9333ea', label: PL['unknown'] || 'Unknown' }
                    ];
                    break;

                case 'eta_relative':
                    // Spectral gradient legend for ETA relative
                    items = [
                        { color: getSpectralColor(0.0), label: '0 min' },
                        { color: getSpectralColor(0.0625), label: '30 min' },
                        { color: getSpectralColor(0.125), label: '1 hr' },
                        { color: getSpectralColor(0.25), label: '2 hr' },
                        { color: getSpectralColor(0.375), label: '3 hr' },
                        { color: getSpectralColor(0.625), label: '5 hr' },
                        { color: getSpectralColor(1.0), label: '8+ hr' }
                    ];
                    break;
                    
                case 'eta_hour':
                    // Cyclical hour legend showing 24-hour cycle
                    for (var h = 0; h < 24; h += 3) {
                        var hue = (h / 24) * 360;
                        items.push({ color: 'hsl(' + hue + ', 85%, 50%)', label: (h < 10 ? '0' : '') + h + 'Z' });
                    }
                    break;
            }
            
            var html = items.map(function(item) {
                return '<span class="d-inline-flex align-items-center mr-2">' +
                    '<span style="display:inline-block;width:12px;height:12px;background:' + item.color + ';border:1px solid #333;border-radius:2px;margin-right:3px;"></span>' +
                    '<span>' + item.label + '</span></span>';
            }).join('');
            
            $legend.html(html);
        }

        function createTsdIcon(weightClass, heading, isTracked, customColor) {
            const wc = (weightClass || '').toUpperCase().trim();
            const color = customColor || WEIGHT_COLORS[wc] || WEIGHT_COLORS[''];
            const size = WEIGHT_SIZES[wc] || WEIGHT_SIZES[''];
            const rotation = heading || 0; // SVG points north (up), rotate by heading
            
            // Tracked flight styling
            const displayColor = isTracked ? '#ffffff' : color;
            const strokeColor = isTracked ? color : '#111';
            const strokeWidth = isTracked ? '1.5' : '0.5';
            
            // Aircraft silhouette SVG path (pointing up/north)
            const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="' + size + '" height="' + size + '">' +
                '<g transform="rotate(' + rotation + ' 12 12)">' +
                '<path fill="' + displayColor + '" stroke="' + strokeColor + '" stroke-width="' + strokeWidth + '" d="M21,16V14L13,9V3.5A1.5,1.5 0 0,0 11.5,2A1.5,1.5 0 0,0 10,3.5V9L2,14V16L10,13.5V19L8,20.5V22L11.5,21L15,22V20.5L13,19V13.5L21,16Z"/>' +
                '</g></svg>';
            
            return L.divIcon({
                className: 'adl-flight-icon' + (isTracked ? ' tracked' : ''),
                html: svg,
                iconSize: [size, size],
                iconAnchor: [size/2, size/2]
            });
        }

        // ─────────────────────────────────────────────────────────────────────
        // FLIGHT ROUTE DRAWING
        // ─────────────────────────────────────────────────────────────────────

        function buildFlightRouteString(flight) {
            // Build route string as: {origin} {fp_route} {destination}
            var parts = [];
            
            // Origin
            if (flight.fp_dept_icao) {
                parts.push(flight.fp_dept_icao.toUpperCase());
            }
            
            // Route
            if (flight.fp_route) {
                parts.push(flight.fp_route.toUpperCase());
            }
            
            // Destination
            if (flight.fp_dest_icao) {
                parts.push(flight.fp_dest_icao.toUpperCase());
            }
            
            return parts.join(' ');
        }

        // Helper: Check if a token looks like a SID/DP (letters followed by digit or #)
        function looksLikeSid(tok) {
            if (!tok) return false;
            // Matches: KAYLN3, KAYLN#, RNAV1, CLTCH5, etc.
            // Must have letters followed by digit or #, optionally with .transition
            return /^[A-Z]{2,}[0-9#](\.[A-Z0-9]+)?$/i.test(tok);
        }
        
        // Helper: Check if a token looks like a STAR (letters followed by digit or #)
        function looksLikeStar(tok) {
            if (!tok) return false;
            // STARs have same format as SIDs - context determines which is which
            return /^[A-Z]{2,}[0-9#](\.[A-Z0-9]+)?$/i.test(tok);
        }
        
        // Helper: Check if token is a plain fix (not a procedure)
        function looksLikeFix(tok) {
            if (!tok) return false;
            // 3-5 letter fix names, no digits at end (or only digits like coordinates)
            return /^[A-Z]{3,5}$/i.test(tok);
        }
        
        // Normalize ADL route strings to canonical format for DP/STAR expansion
        // Handles: KAYLN3.SMUUV.WYNDE3, KAYLN3 SMUUV WYNDE3, KAYLN# SMUUV WYNDE#, etc.
        function normalizeRouteForExpansion(tokens, originCode, destCode) {
            if (!tokens || tokens.length === 0) return tokens;
            
            var result = [];
            var i = 0;
            
            while (i < tokens.length) {
                var tok = tokens[i].toUpperCase();
                
                // Handle combined tokens like KAYLN3.SMUUV.WYNDE3
                if (tok.indexOf('.') !== -1) {
                    var parts = tok.split('.');
                    
                    if (parts.length === 3) {
                        // Format: DP.TRANS.STAR (e.g., KAYLN3.SMUUV.WYNDE3)
                        // Split into: DP.TRANS and TRANS.STAR
                        var dpPart = parts[0];
                        var transPart = parts[1];
                        var starPart = parts[2];
                        
                        if (looksLikeSid(dpPart) || /[0-9#]$/.test(dpPart)) {
                            result.push(dpPart + '.' + transPart);
                        } else {
                            result.push(dpPart);
                            result.push(transPart);
                        }
                        
                        if (looksLikeStar(starPart) || /[0-9#]$/.test(starPart)) {
                            result.push(transPart + '.' + starPart);
                        } else {
                            result.push(starPart);
                        }
                        i++;
                        continue;
                    } else if (parts.length === 2) {
                        // Already in good format: DP.TRANS or TRANS.STAR
                        result.push(tok);
                        i++;
                        continue;
                    } else {
                        // More than 3 parts or 1 part - just pass through
                        result.push(tok);
                        i++;
                        continue;
                    }
                }
                
                // Handle space-separated format: look for DP FIX STAR pattern
                // Check if current token looks like a SID and next token is a fix
                if ((looksLikeSid(tok) || /^[A-Z]{2,}[0-9#]$/i.test(tok)) && i < tokens.length - 1) {
                    var nextTok = tokens[i + 1].toUpperCase();
                    
                    // If next token is a plain fix (potential transition), combine them
                    if (looksLikeFix(nextTok)) {
                        // Check if there's a STAR after the fix
                        var hasStarAfter = false;
                        if (i < tokens.length - 2) {
                            var afterFix = tokens[i + 2].toUpperCase();
                            hasStarAfter = looksLikeStar(afterFix) || /^[A-Z]{2,}[0-9#]$/i.test(afterFix);
                        }
                        
                        // Combine SID.TRANSITION
                        result.push(tok + '.' + nextTok);
                        
                        // If there's a STAR after, also create TRANSITION.STAR
                        if (hasStarAfter) {
                            var starTok = tokens[i + 2].toUpperCase();
                            result.push(nextTok + '.' + starTok);
                            i += 3; // Skip SID, FIX, and STAR
                            continue;
                        } else {
                            i += 2; // Skip SID and FIX
                            continue;
                        }
                    }
                }
                
                // Check if this is a fix followed by a STAR (transition.STAR pattern)
                if (looksLikeFix(tok) && i < tokens.length - 1) {
                    var nextTok = tokens[i + 1].toUpperCase();
                    if (looksLikeStar(nextTok) || /^[A-Z]{2,}[0-9#]$/i.test(nextTok)) {
                        // Check if previous token was already a SID (don't double-add the fix)
                        var prevWasSid = i > 0 && result.length > 0 && 
                            result[result.length - 1].indexOf('.' + tok) !== -1;
                        
                        if (!prevWasSid) {
                            // Standalone fix before STAR - add as transition.STAR
                            result.push(tok);
                        }
                        result.push(tok + '.' + nextTok);
                        i += 2;
                        continue;
                    }
                }
                
                // Default: pass through unchanged
                result.push(tok);
                i++;
            }
            
            console.log('[ADL] Normalized route:', result.join(' '));
            return result;
        }

        function buildRouteCoords(flight) {
            // Build array of {lat, lon, name} for a flight's route using existing parsing
            var routeString = buildFlightRouteString(flight);
            console.log('[ADL] Building route for:', flight.callsign, 'Route:', routeString);
            
            if (!routeString) return [];
            
            // Debug: Check data availability
            console.log('[ADL] Data status - dpDataLoaded:', typeof dpDataLoaded !== 'undefined' ? dpDataLoaded : 'undefined',
                        'starDataLoaded:', typeof starDataLoaded !== 'undefined' ? starDataLoaded : 'undefined',
                        'points count:', typeof points !== 'undefined' ? Object.keys(points).length : 'undefined');
            
            // Get origin/dest for context
            var originCode = (flight.fp_dept_icao || '').toUpperCase();
            var destCode = (flight.fp_dest_icao || '').toUpperCase();
            
            // Parse initial tokens
            var tokens = routeString.split(/\s+/).filter(function(t) { return t && t.length > 0; });
            tokens = tokens.map(function(t) { return t.toUpperCase(); });
            
            // Normalize route format for DP/STAR expansion
            tokens = normalizeRouteForExpansion(tokens, originCode, destCode);
            
            // Create solidMask for expansion functions (all true = all solid)
            var solidMask = new Array(tokens.length).fill(true);
            
            // Step 1: Apply departure procedure normalization (if available)
            if (typeof applyDepartureProcedures === 'function') {
                try {
                    tokens = applyDepartureProcedures(tokens);
                    solidMask = new Array(tokens.length).fill(true);
                } catch (e) {
                    console.warn('[ADL] applyDepartureProcedures error:', e);
                }
            }
            
            // Step 2: Expand departure procedures (SIDs) into waypoint sequences
            if (typeof expandDepartureProcedures === 'function' && typeof dpDataLoaded !== 'undefined' && dpDataLoaded) {
                try {
                    var dpExpanded = expandDepartureProcedures(tokens, solidMask);
                    if (dpExpanded && dpExpanded.tokens && dpExpanded.tokens.length) {
                        tokens = dpExpanded.tokens;
                        solidMask = dpExpanded.solidMask || new Array(tokens.length).fill(true);
                        console.log('[ADL] After DP expansion:', tokens.join(' '));
                    }
                } catch (e) {
                    console.warn('[ADL] expandDepartureProcedures error:', e);
                }
            } else {
                console.log('[ADL] DP expansion skipped - expandDepartureProcedures:', typeof expandDepartureProcedures, 'dpDataLoaded:', typeof dpDataLoaded !== 'undefined' ? dpDataLoaded : 'undefined');
            }
            
            // Step 3: Expand STAR procedures into waypoint sequences
            if (typeof expandStarProcedures === 'function' && typeof starDataLoaded !== 'undefined' && starDataLoaded) {
                try {
                    var starExpanded = expandStarProcedures(tokens, solidMask);
                    if (starExpanded && starExpanded.tokens && starExpanded.tokens.length) {
                        tokens = starExpanded.tokens;
                        solidMask = starExpanded.solidMask || new Array(tokens.length).fill(true);
                        console.log('[ADL] After STAR expansion:', tokens.join(' '));
                    }
                } catch (e) {
                    console.warn('[ADL] expandStarProcedures error:', e);
                }
            } else {
                console.log('[ADL] STAR expansion skipped - expandStarProcedures:', typeof expandStarProcedures, 'starDataLoaded:', typeof starDataLoaded !== 'undefined' ? starDataLoaded : 'undefined');
            }
            
            // Step 4: Expand airways (Q22, J174, etc.) into intermediate fixes
            var expandedRoute = tokens.join(' ');
            if (typeof ConvertRoute === 'function') {
                expandedRoute = ConvertRoute(expandedRoute);
            }
            console.log('[ADL] Expanded route:', expandedRoute);
            
            // Parse final expanded tokens
            tokens = expandedRoute.split(/\s+/).filter(function(t) { return t && t.length > 0; });
            
            // Helper: Check if token looks like an unexpanded procedure (letters + single digit at end)
            function isUnexpandedProcedure(tok) {
                // Matches things like KAYLN3, WYNDE3, RNAV1, but not fixes like SMUUV or FL350
                return /^[A-Z]{3,}[0-9]$/.test(tok);
            }
            
            // Get coordinates for each waypoint
            var coords = [];
            var previousPointData = null;
            var skippedProcedures = [];
            
            for (var i = 0; i < tokens.length; i++) {
                var pointName = tokens[i].toUpperCase();
                
                // Skip common route keywords
                if (pointName === 'DCT' || pointName === 'DIRECT' || pointName === '..' || 
                    pointName === 'SID' || pointName === 'STAR') continue;
                
                // Skip altitude/speed restrictions like FL350, /N0450
                if (/^FL\d+$/.test(pointName) || /^\//.test(pointName) || /^\d{4,}$/.test(pointName)) continue;
                
                // Track unexpanded procedures but don't skip - try to look them up anyway
                var isProcedure = isUnexpandedProcedure(pointName);
                
                // Get next point for disambiguation
                var nextPointData = null;
                if (i < tokens.length - 1 && typeof getPointByName === 'function') {
                    var nextToken = tokens[i + 1].toUpperCase();
                    if (nextToken !== 'DCT' && nextToken !== 'DIRECT' && nextToken !== '..') {
                        try {
                            nextPointData = getPointByName(nextToken, previousPointData, null);
                        } catch (e) { /* ignore */ }
                    }
                }
                
                // Look up coordinates
                var pointData = null;
                if (typeof getPointByName === 'function') {
                    try {
                        pointData = getPointByName(pointName, previousPointData, nextPointData);
                    } catch (e) {
                        console.warn('[ADL] getPointByName error for', pointName, ':', e);
                    }
                }
                
                if (pointData && pointData.length >= 3) {
                    coords.push({
                        lat: pointData[1],
                        lon: pointData[2],
                        name: pointName
                    });
                    previousPointData = pointData;
                } else {
                    if (isProcedure) {
                        skippedProcedures.push(pointName);
                    } else {
                        console.log('[ADL] No coords for:', pointName);
                    }
                }
            }
            
            if (skippedProcedures.length > 0) {
                console.log('[ADL] Unexpanded procedures (no coords):', skippedProcedures.join(', '));
            }
            
            console.log('[ADL] Route coords:', coords.length, 'points');
            
            // If we got very few points, at minimum try to connect origin to destination
            if (coords.length < 2 && originCode && destCode && typeof getPointByName === 'function') {
                console.log('[ADL] Fallback: attempting origin-dest direct line');
                coords = [];
                try {
                    var originData = getPointByName(originCode, null, null);
                    var destData = getPointByName(destCode, null, null);
                    if (originData && originData.length >= 3) {
                        coords.push({ lat: originData[1], lon: originData[2], name: originCode });
                    }
                    if (destData && destData.length >= 3) {
                        coords.push({ lat: destData[1], lon: destData[2], name: destCode });
                    }
                    console.log('[ADL] Fallback result:', coords.length, 'points');
                } catch (e) {
                    console.warn('[ADL] Fallback error:', e);
                }
            }
            
            return coords;
        }

        function findClosestSegmentIndex(coords, aircraftLat, aircraftLon) {
            // Find the index of the segment the aircraft is closest to
            // Returns the index where the "behind" portion ends (i.e., coords[0..index] are behind)
            if (coords.length < 2) return 0;
            
            var minDist = Infinity;
            var closestIdx = 0;
            
            for (var i = 0; i < coords.length; i++) {
                var dist = Math.sqrt(
                    Math.pow(coords[i].lat - aircraftLat, 2) + 
                    Math.pow(coords[i].lon - aircraftLon, 2)
                );
                if (dist < minDist) {
                    minDist = dist;
                    closestIdx = i;
                }
            }
            
            return closestIdx;
        }

        /**
         * Split a coordinate array at International Date Line crossings.
         * Returns an array of coordinate arrays, each segment staying on one side of the IDL.
         * This prevents Leaflet from drawing lines across the entire map.
         * @param {Array} coords - Array of [lat, lon] coordinate pairs
         * @returns {Array} Array of coordinate arrays
         */
        function splitAtIDL(coords) {
            if (!coords || coords.length < 2) return [coords];

            var segments = [];
            var currentSegment = [coords[0]];

            for (var i = 1; i < coords.length; i++) {
                var prevLon = coords[i - 1][1];
                var currLon = coords[i][1];

                // Detect IDL crossing: large longitude jump (> 180 degrees difference)
                var lonDiff = Math.abs(currLon - prevLon);

                if (lonDiff > 180) {
                    // This segment crosses the IDL - split here
                    // Calculate where the line crosses the antimeridian
                    var prevLat = coords[i - 1][0];
                    var currLat = coords[i][0];

                    // Normalize longitudes to handle the crossing
                    var adjustedPrevLon = prevLon;
                    var adjustedCurrLon = currLon;

                    if (prevLon > 0 && currLon < 0) {
                        // Crossing from east (positive) to west (negative)
                        adjustedCurrLon = currLon + 360;
                    } else if (prevLon < 0 && currLon > 0) {
                        // Crossing from west (negative) to east (positive)
                        adjustedPrevLon = prevLon + 360;
                    }

                    // Linear interpolation to find latitude at lon = 180 or -180
                    var t = (180 - Math.min(adjustedPrevLon, adjustedCurrLon)) /
                            (Math.max(adjustedPrevLon, adjustedCurrLon) - Math.min(adjustedPrevLon, adjustedCurrLon));
                    if (t < 0) t = 0;
                    if (t > 1) t = 1;
                    var crossingLat = prevLat + t * (currLat - prevLat);

                    // End current segment at the crossing point (on the correct side)
                    var endLon = prevLon > 0 ? 180 : -180;
                    currentSegment.push([crossingLat, endLon]);
                    segments.push(currentSegment);

                    // Start new segment from the crossing point (on the opposite side)
                    var startLon = currLon > 0 ? 180 : -180;
                    currentSegment = [[crossingLat, startLon], coords[i]];
                } else {
                    currentSegment.push(coords[i]);
                }
            }

            // Add the final segment
            if (currentSegment.length > 0) {
                segments.push(currentSegment);
            }

            return segments;
        }

        function toggleFlightRoute(flight) {
            var key = flight.flight_key || flight.callsign;
            console.log('[ADL] Toggle route for:', key);
            
            // Check if route is already drawn
            if (state.drawnRoutes.has(key)) {
                // Remove the route
                var routeData = state.drawnRoutes.get(key);
                if (routeData.behindLine && state.routeLayer) {
                    state.routeLayer.removeLayer(routeData.behindLine);
                }
                if (routeData.aheadLine && state.routeLayer) {
                    state.routeLayer.removeLayer(routeData.aheadLine);
                }
                if (routeData.markers) {
                    routeData.markers.forEach(function(m) {
                        if (state.routeLayer) state.routeLayer.removeLayer(m);
                    });
                }
                state.drawnRoutes.delete(key);
                console.log('[ADL] Route removed for:', key);
                return false;
            }
            
            // Build route coordinates - use pre-parsed ADL data if available
            var coords;
            if (flight.waypoints_json && Array.isArray(flight.waypoints_json) && flight.waypoints_json.length >= 2) {
                // Use pre-parsed waypoints from ADL (no client-side parsing needed)
                coords = flight.waypoints_json.map(function(wp) {
                    return {
                        lat: parseFloat(wp.lat),
                        lon: parseFloat(wp.lon),
                        name: wp.fix_name || ''
                    };
                });
                console.log('[ADL] Using pre-parsed waypoints:', coords.length, 'points');
            } else {
                // Fallback: parse route client-side (for user-entered routes or missing ADL data)
                coords = buildRouteCoords(flight);
                console.log('[ADL] Using client-side parsed route:', coords.length, 'points');
            }

            if (coords.length < 2) {
                console.log('[ADL] Not enough coords to draw route');
                return false;
            }
            
            // Create route layer if needed
            if (!state.routeLayer) {
                state.routeLayer = L.layerGroup();
                if (graphic_map) {
                    state.routeLayer.addTo(graphic_map);
                    // Bring flight routes below aircraft markers but above other layers
                    state.routeLayer.bringToFront();
                    if (state.layer) state.layer.bringToFront();
                }
            }
            
            // Pick a color based on current color scheme
            var color = getFlightColor(flight);
            
            // Find where aircraft is along the route
            var aircraftLat = parseFloat(flight.lat);
            var aircraftLon = parseFloat(flight.lon);
            var splitIdx = findClosestSegmentIndex(coords, aircraftLat, aircraftLon);
            
            // Build coordinate arrays for behind/ahead
            var behindCoords = [];
            var aheadCoords = [];
            
            // Behind: from origin up to aircraft position
            for (var i = 0; i <= splitIdx; i++) {
                behindCoords.push([coords[i].lat, coords[i].lon]);
            }
            // Add aircraft position as end of behind segment
            behindCoords.push([aircraftLat, aircraftLon]);
            
            // Ahead: from aircraft position to destination
            aheadCoords.push([aircraftLat, aircraftLon]);
            for (var j = splitIdx; j < coords.length; j++) {
                aheadCoords.push([coords[j].lat, coords[j].lon]);
            }
            
            var behindLine = null;
            var aheadLine = null;
            var markers = [];

            // Draw behind segment (solid, thin) - split at IDL to avoid cross-map lines
            if (behindCoords.length >= 2) {
                var behindSegments = splitAtIDL(behindCoords);
                behindLine = L.layerGroup();
                behindSegments.forEach(function(segment) {
                    if (segment && segment.length >= 2) {
                        var line = L.polyline(segment, {
                            color: color,
                            weight: 2,
                            opacity: 0.7,
                            className: 'adl-flight-route-behind'
                        });
                        line.addTo(behindLine);
                    }
                });
                behindLine.addTo(state.routeLayer);
            }

            // Draw ahead segment (dashed, thin) - split at IDL to avoid cross-map lines
            if (aheadCoords.length >= 2) {
                var aheadSegments = splitAtIDL(aheadCoords);
                aheadLine = L.layerGroup();
                aheadSegments.forEach(function(segment) {
                    if (segment && segment.length >= 2) {
                        var line = L.polyline(segment, {
                            color: color,
                            weight: 2,
                            opacity: 0.9,
                            dashArray: '8, 6',
                            className: 'adl-flight-route-ahead'
                        });
                        line.addTo(aheadLine);
                    }
                });
                aheadLine.addTo(state.routeLayer);
            }
            
            // Add waypoint markers with labels
            coords.forEach(function(coord, idx) {
                // Skip origin/destination (first and last) - they're airports
                var isEndpoint = (idx === 0 || idx === coords.length - 1);
                var markerSize = isEndpoint ? 3 : 1.5;
                var markerOpacity = isEndpoint ? 0.8 : 0.6;
                
                // Create circle marker for waypoint
                var waypointMarker = L.circleMarker([coord.lat, coord.lon], {
                    radius: markerSize,
                    fillColor: color,
                    color: '#222',
                    weight: 0.3,
                    opacity: markerOpacity,
                    fillOpacity: markerOpacity,
                    className: 'adl-waypoint-marker'
                });
                
                // Add tooltip with waypoint name
                waypointMarker.bindTooltip(coord.name, {
                    permanent: false,
                    direction: 'top',
                    offset: [0, -5],
                    className: 'adl-waypoint-tooltip'
                });
                
                waypointMarker.addTo(state.routeLayer);
                markers.push(waypointMarker);
            });
            
            // Store reference
            state.drawnRoutes.set(key, {
                behindLine: behindLine,
                aheadLine: aheadLine,
                markers: markers,
                color: color,
                flight: flight
            });
            
            console.log('[ADL] Route drawn for:', key, 'with', coords.length, 'points, split at', splitIdx);
            return true;
        }

        function clearAllRoutes() {
            if (state.routeLayer) {
                state.routeLayer.clearLayers();
            }
            state.drawnRoutes.clear();
            routeColorIndex = 0;
            console.log('[ADL] All routes cleared');
        }
        
        function refreshRouteColors() {
            // Update colors of all drawn routes based on current color scheme
            state.drawnRoutes.forEach(function(routeData, key) {
                // Find the flight data for this route
                var flight = state.filteredFlights.find(function(f) {
                    return (f.flight_key || f.callsign) === key;
                });
                
                if (!flight) {
                    // Try in all flights
                    flight = state.flights.find(function(f) {
                        return (f.flight_key || f.callsign) === key;
                    });
                }
                
                if (flight) {
                    var newColor = getFlightColor(flight);
                    routeData.color = newColor;

                    // behindLine and aheadLine are layer groups containing polylines
                    if (routeData.behindLine && routeData.behindLine.eachLayer) {
                        routeData.behindLine.eachLayer(function(layer) {
                            if (layer.setStyle) layer.setStyle({ color: newColor });
                        });
                    }
                    if (routeData.aheadLine && routeData.aheadLine.eachLayer) {
                        routeData.aheadLine.eachLayer(function(layer) {
                            if (layer.setStyle) layer.setStyle({ color: newColor });
                        });
                    }
                }
            });
            console.log('[ADL] Route colors refreshed');
        }
        
        function showAllRoutes() {
            // Draw routes for all currently filtered flights
            var count = 0;
            var maxRoutes = 50; // Limit to prevent performance issues
            
            state.filteredFlights.forEach(function(flight) {
                if (count >= maxRoutes) return;
                
                var key = flight.flight_key || flight.callsign;
                // Only draw if not already drawn
                if (!state.drawnRoutes.has(key)) {
                    if (toggleFlightRoute(flight)) {
                        count++;
                    }
                }
            });
            
            console.log('[ADL] Drew', count, 'routes (max:', maxRoutes, ')');
            if (count >= maxRoutes) {
                console.log('[ADL] Route limit reached, some flights not drawn');
            }
        }
        
        function filterRoutesByCurrentFilter() {
            // Remove routes for flights that are no longer in filteredFlights
            var keysToRemove = [];
            
            state.drawnRoutes.forEach(function(routeData, key) {
                // Check if this flight is in filtered list
                var inFiltered = state.filteredFlights.some(function(f) {
                    return (f.flight_key || f.callsign) === key;
                });
                
                if (!inFiltered) {
                    keysToRemove.push(key);
                }
            });
            
            // Remove the routes
            keysToRemove.forEach(function(key) {
                var routeData = state.drawnRoutes.get(key);
                if (routeData) {
                    if (routeData.behindLine && state.routeLayer) {
                        state.routeLayer.removeLayer(routeData.behindLine);
                    }
                    if (routeData.aheadLine && state.routeLayer) {
                        state.routeLayer.removeLayer(routeData.aheadLine);
                    }
                    if (routeData.markers) {
                        routeData.markers.forEach(function(m) {
                            if (state.routeLayer) state.routeLayer.removeLayer(m);
                        });
                    }
                    state.drawnRoutes.delete(key);
                }
            });
            
            if (keysToRemove.length > 0) {
                console.log('[ADL] Filtered out', keysToRemove.length, 'routes');
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // DATA FETCHING
        // ─────────────────────────────────────────────────────────────────────

        function fetchFlights() {
            console.log('[ADL] Fetching flights...');
            
            // Store previous data for buffered update
            var previousFlights = state.flights ? state.flights.slice() : [];
            
            return $.ajax({
                url: 'api/adl/current.php?limit=3000&active=1',
                method: 'GET',
                dataType: 'json'
            }).done(function(data) {
                console.log('[ADL] Raw response:', data);
                
                if (data.error) {
                    console.error('[ADL] API error:', data.error);
                    updateRefreshStatus('Error');
                    // BUFFERED: Keep previous data on error
                    return;
                }
                
                var allFlights = data.flights || data.rows || [];
                console.log('[ADL] Total flights from API:', allFlights.length);
                
                var validFlights = allFlights.filter(function(f) {
                    return f.lat != null && f.lon != null && 
                        !isNaN(parseFloat(f.lat)) && !isNaN(parseFloat(f.lon));
                });
                console.log('[ADL] Flights with valid lat/lon:', validFlights.length);
                
                // BUFFERED: Only update if we got data, or had no prior data
                if (validFlights.length > 0 || previousFlights.length === 0) {
                    state.flights = validFlights;
                } else {
                    console.log('[ADL] Empty response, keeping previous data (' + previousFlights.length + ' flights)');
                    // Keep previous flights - don't update state.flights
                }
                
                state.lastRefresh = new Date();
                
                applyFilters();
                console.log('[ADL] Filtered flights:', state.filteredFlights.length);
                
                updateDisplay();
                updateStats();
                updateRefreshStatus();
            }).fail(function(xhr, status, err) {
                console.error('[ADL] Fetch failed:', status, err);
                console.error('[ADL] Response:', xhr.responseText);
                updateRefreshStatus('Error');
                // BUFFERED: Don't clear state.flights on error - keep showing old data
                console.log('[ADL] Keeping previous data due to error (' + previousFlights.length + ' flights)');
            });
        }

        // ─────────────────────────────────────────────────────────────────────
        // FILTERING
        // ─────────────────────────────────────────────────────────────────────

        function applyFilters() {
            var f = state.filters;
            console.log('[ADL] Applying filters:', JSON.stringify(f));
            
            var beforeCount = state.flights.length;
            
            state.filteredFlights = state.flights.filter(function(flight) {
                // Weight class filter - use normalized weight class
                var wc = getWeightClass(flight);
                var wcMatch = f.weightClasses.some(function(w) {
                    if (w === wc) return true;
                    // Map aliases to normalized form
                    if ((w === 'SUPER' || w === 'J') && wc === 'SUPER') return true;
                    if ((w === 'HEAVY' || w === 'H') && wc === 'HEAVY') return true;
                    if ((w === 'LARGE' || w === 'L' || w === '') && wc === 'LARGE') return true;
                    if ((w === 'SMALL' || w === 'S') && wc === 'SMALL') return true;
                    return false;
                });
                if (!wcMatch) return false;
                
                // Origin filter (ARTCC or airport)
                if (f.origin && f.origin.length > 0) {
                    var deptIcao = (flight.fp_dept_icao || '').toUpperCase().trim();
                    var deptArtcc = (flight.fp_dept_artcc || '').toUpperCase().trim();
                    // Match if filter is contained in either field, or exact match
                    var originMatch = deptIcao.indexOf(f.origin) !== -1 || 
                                      deptArtcc.indexOf(f.origin) !== -1 ||
                                      deptIcao === f.origin ||
                                      deptArtcc === f.origin;
                    if (!originMatch) return false;
                }
                
                // Destination filter
                if (f.dest && f.dest.length > 0) {
                    var destIcao = (flight.fp_dest_icao || '').toUpperCase().trim();
                    var destArtcc = (flight.fp_dest_artcc || '').toUpperCase().trim();
                    var destMatch = destIcao.indexOf(f.dest) !== -1 || 
                                    destArtcc.indexOf(f.dest) !== -1 ||
                                    destIcao === f.dest ||
                                    destArtcc === f.dest;
                    if (!destMatch) return false;
                }
                
                // Carrier filter
                if (f.carrier && f.carrier.length > 0) {
                    var airlineIcao = (flight.airline_icao || '').toUpperCase().trim();
                    var callsign = (flight.callsign || '').toUpperCase().trim();
                    // Match carrier prefix in airline_icao or callsign
                    var carrierMatch = airlineIcao.indexOf(f.carrier) === 0 ||
                                       callsign.indexOf(f.carrier) === 0;
                    if (!carrierMatch) return false;
                }
                
                // Altitude filter (use current altitude_ft or filed fp_altitude_ft)
                var alt = flight.altitude_ft || flight.fp_altitude_ft || 0;
                var altFL = Math.round(alt / 100);
                if (f.altitudeMin != null && altFL < f.altitudeMin) return false;
                if (f.altitudeMax != null && altFL > f.altitudeMax) return false;
                
                return true;
            });
            
            console.log('[ADL] Filter result:', beforeCount, '->', state.filteredFlights.length, 'flights');
        }

        function collectFiltersFromUI() {
            console.log('[ADL] Collecting filters from UI...');
            
            // Weight classes
            var wcChecked = [];
            $('.adl-weight-filter:checked').each(function() {
                wcChecked.push($(this).val());
            });
            console.log('[ADL] Weight classes checked:', wcChecked);
            
            // Include empty string for unclassified if LARGE/jet is checked
            if (wcChecked.indexOf('LARGE') !== -1) wcChecked.push('');
            state.filters.weightClasses = wcChecked.length ? wcChecked : ['SUPER', 'HEAVY', 'LARGE', 'SMALL', ''];
            
            // Geographic
            state.filters.origin = ($('#adl_origin').val() || '').trim().toUpperCase();
            state.filters.dest = ($('#adl_dest').val() || '').trim().toUpperCase();
            
            // Carrier
            state.filters.carrier = ($('#adl_carrier').val() || '').trim().toUpperCase();
            
            // Altitude
            var altMin = $('#adl_alt_min').val();
            var altMax = $('#adl_alt_max').val();
            state.filters.altitudeMin = altMin ? parseInt(altMin, 10) : null;
            state.filters.altitudeMax = altMax ? parseInt(altMax, 10) : null;
            
            console.log('[ADL] Filters collected:', JSON.stringify(state.filters));
        }

        function clearFiltersUI() {
            $('.adl-weight-filter').prop('checked', true);
            $('#adl_origin').val('');
            $('#adl_dest').val('');
            $('#adl_carrier').val('');
            $('#adl_alt_min').val('');
            $('#adl_alt_max').val('');
            
            collectFiltersFromUI();
            applyFilters();
            updateDisplay();
            updateStats();
        }

        // ─────────────────────────────────────────────────────────────────────
        // MAP DISPLAY
        // ─────────────────────────────────────────────────────────────────────

        function updateDisplay() {
            console.log('[ADL] updateDisplay called, layer:', !!state.layer, 'enabled:', state.enabled);
            if (!state.layer || !state.enabled) return;
            
            console.log('[ADL] Rendering', state.filteredFlights.length, 'flights');
            
            // Log first few flights for debugging
            if (state.filteredFlights.length > 0) {
                console.log('[ADL] Sample flights:', state.filteredFlights.slice(0, 3).map(function(f) {
                    return {
                        callsign: f.callsign,
                        lat: f.lat,
                        lon: f.lon,
                        weight_class: getWeightClass(f),
                        aircraft_type: stripAircraftSuffixes(f.aircraft_type || f.aircraft_icao),
                        heading: f.heading_deg
                    };
                }));
            }
            
            var currentMarkerKeys = [];
            state.markers.forEach(function(v, k) { currentMarkerKeys.push(k); });
            var newFlightKeys = [];
            
            state.filteredFlights.forEach(function(flight) {
                var key = flight.flight_key || flight.callsign;
                newFlightKeys.push(key);
                
                var lat = parseFloat(flight.lat);
                var lon = parseFloat(flight.lon);
                var heading = flight.heading_deg || 0;
                var isTracked = state.trackedFlight === key;
                var flightColor = getFlightColor(flight);
                
                if (state.markers.has(key)) {
                    // Update existing marker
                    var marker = state.markers.get(key);
                    marker.setLatLng([lat, lon]);
                    marker.setIcon(createTsdIcon(getWeightClass(flight), heading, isTracked, flightColor));
                    marker._flightData = flight;
                } else {
                    // Create new marker
                    var marker = L.marker([lat, lon], {
                        icon: createTsdIcon(getWeightClass(flight), heading, isTracked, flightColor),
                        zIndexOffset: isTracked ? 1000 : 0
                    });
                    
                    marker._flightData = flight;
                    
                    // Left-click: toggle route drawing + show popup
                    marker.on('click', function(e) {
                        var flt = this._flightData;
                        var routeDrawn = toggleFlightRoute(flt);
                        
                        // Show brief status popup with key flight info
                        var key = flt.flight_key || flt.callsign;
                        var routeData = state.drawnRoutes.get(key);
                        var statusHtml = routeDrawn ? 
                            '<span style="color:' + routeData.color + '">✓ Route shown</span>' :
                            '<span style="color:#999">Route hidden</span>';
                        
                        // Format altitude and ETA
                        var altStr = flt.altitude_ft ? 'FL' + Math.round(flt.altitude_ft / 100) : '--';
                        var etaStr = '--';
                        if (flt.eta_runway_utc) {
                            try {
                                var etaDate = new Date(flt.eta_runway_utc);
                                etaStr = etaDate.toISOString().substr(11, 5) + 'Z';
                            } catch (e) { etaStr = '--'; }
                        }
                        
                        var content = '<div class="adl-popup">' +
                            '<div class="callsign">' + escHtml(flt.callsign) + '</div>' +
                            '<div class="route mb-2">' +
                            '<strong>' + (flt.fp_dept_icao || '????') + '</strong>' +
                            ' <i class="fas fa-long-arrow-alt-right mx-1"></i> ' +
                            '<strong>' + (flt.fp_dest_icao || '????') + '</strong>' +
                            '</div>' +
                            '<div class="small text-center mb-1"><span class="mr-2">Alt: ' + altStr + '</span><span>ETA: ' + etaStr + '</span></div>' +
                            '<div class="text-center small">' + statusHtml + '</div>' +
                            '<div class="text-muted text-center mt-1" style="font-size:0.7rem">Right-click for more options</div>' +
                            '</div>';
                        
                        L.popup({ closeButton: true, className: 'adl-flight-popup', autoClose: true, closeOnClick: true })
                            .setLatLng(e.latlng)
                            .setContent(content)
                            .openOn(graphic_map);
                    });
                    
                    // Right-click: context menu
                    marker.on('contextmenu', function(e) {
                        L.DomEvent.preventDefault(e);
                        showContextMenu(this._flightData, e.originalEvent);
                    });
                    
                    marker.addTo(state.layer);
                    state.markers.set(key, marker);
                }
            });
            
            console.log('[ADL] Total markers now:', state.markers.size);
            
            // Remove markers for flights no longer visible
            currentMarkerKeys.forEach(function(key) {
                if (newFlightKeys.indexOf(key) === -1) {
                    var marker = state.markers.get(key);
                    if (marker) {
                        state.layer.removeLayer(marker);
                        state.markers.delete(key);
                    }
                }
            });
            
            // If tracking a flight, center on it
            if (state.trackedFlight && state.markers.has(state.trackedFlight)) {
                var trackedMarker = state.markers.get(state.trackedFlight);
                if (typeof graphic_map !== 'undefined') {
                    graphic_map.panTo(trackedMarker.getLatLng(), { animate: true, duration: 0.5 });
                }
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // POPUPS & CONTEXT MENU
        // ─────────────────────────────────────────────────────────────────────

        function showFlightPopup(flight, latlng) {
            var alt = flight.altitude_ft ? 'FL' + Math.round(flight.altitude_ft / 100) : '--';
            var spd = flight.groundspeed_kts ? flight.groundspeed_kts + ' kts' : '--';
            var hdg = flight.heading_deg ? flight.heading_deg + '°' : '--';
            
            // Format ETA
            var eta = '--';
            if (flight.eta_runway_utc) {
                try {
                    var etaDate = new Date(flight.eta_runway_utc);
                    eta = etaDate.toISOString().substr(11, 5) + 'Z';
                } catch (e) { eta = '--'; }
            }
            
            var content = '<div class="adl-popup">' +
                '<div class="callsign">' + escHtml(flight.callsign) + '</div>' +
                '<div class="route mb-2">' +
                '<strong>' + (flight.fp_dept_icao || '????') + '</strong>' +
                ' <i class="fas fa-long-arrow-alt-right mx-1"></i> ' +
                '<strong>' + (flight.fp_dest_icao || '????') + '</strong>' +
                '</div>' +
                '<div class="detail-row"><span class="detail-label">Aircraft</span><span class="detail-value">' + (stripAircraftSuffixes(flight.aircraft_type || flight.aircraft_icao) || '--') + ' (' + getWeightClass(flight) + ')</span></div>' +
                '<div class="detail-row"><span class="detail-label">Altitude</span><span class="detail-value">' + alt + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Speed</span><span class="detail-value">' + spd + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Heading</span><span class="detail-value">' + hdg + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">ETA</span><span class="detail-value">' + eta + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Phase</span><span class="detail-value">' + (flight.phase || '--') + '</span></div>' +
                '</div>';
            
            L.popup({ closeButton: true, className: 'adl-flight-popup' })
                .setLatLng(latlng)
                .setContent(content)
                .openOn(graphic_map);
        }

        function showContextMenu(flight, event) {
            state.selectedFlight = flight;
            
            var menu = document.getElementById('adl_context_menu');
            if (!menu) return;
            
            document.getElementById('adl_ctx_callsign').textContent = flight.callsign;
            
            // Position menu at click location using clientX/Y (viewport coordinates)
            // Account for scroll position
            var x = event.clientX;
            var y = event.clientY;
            
            // Ensure menu doesn't go off screen
            menu.style.display = 'block';
            var menuWidth = menu.offsetWidth;
            var menuHeight = menu.offsetHeight;
            
            if (x + menuWidth > window.innerWidth) {
                x = window.innerWidth - menuWidth - 10;
            }
            if (y + menuHeight > window.innerHeight) {
                y = window.innerHeight - menuHeight - 10;
            }
            
            menu.style.left = x + 'px';
            menu.style.top = y + 'px';
            
            // Close on outside click
            setTimeout(function() {
                $(document).one('click', hideContextMenu);
            }, 10);
        }

        function hideContextMenu() {
            var menu = document.getElementById('adl_context_menu');
            if (menu) menu.style.display = 'none';
        }

        function handleContextMenuAction(action) {
            var flight = state.selectedFlight;
            if (!flight) return;
            
            hideContextMenu();
            
            switch (action) {
                case 'info':
                    var marker = state.markers.get(flight.flight_key || flight.callsign);
                    if (marker) {
                        showFlightPopup(flight, marker.getLatLng());
                    }
                    break;
                    
                case 'detail':
                    showFlightDetailModal(flight);
                    break;
                    
                case 'zoom':
                    zoomToFlight(flight);
                    break;
                    
                case 'track':
                    toggleTrackFlight(flight);
                    break;
                    
                case 'copy':
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(flight.callsign);
                    }
                    break;
            }
        }

        function showFlightDetailModal(flight) {
            var formatTime = function(t) {
                if (!t) return '--';
                try {
                    var d = new Date(t);
                    return d.toISOString().substr(11, 5) + 'Z';
                } catch (e) { return '--'; }
            };
            
            $('#adl_modal_callsign').text(flight.callsign);
            $('#adl_detail_callsign').text(flight.callsign);
            $('#adl_detail_route').text((flight.fp_dept_icao || '????') + ' → ' + (flight.fp_dest_icao || '????'));
            $('#adl_detail_aircraft').text(stripAircraftSuffixes(flight.aircraft_type || flight.aircraft_icao) || '--');
            $('#adl_detail_weight').text(getWeightClass(flight));
            $('#adl_detail_carrier').text(flight.airline_icao || flight.airline_name || '--');
            $('#adl_detail_phase').text(flight.phase || '--');
            $('#adl_detail_position').text(
                (flight.lat && flight.lon) ? 
                parseFloat(flight.lat).toFixed(3) + ', ' + parseFloat(flight.lon).toFixed(3) : '--'
            );
            $('#adl_detail_altitude').text(
                flight.altitude_ft ? 'FL' + Math.round(flight.altitude_ft / 100) + ' (' + flight.altitude_ft + ' ft)' : '--'
            );
            $('#adl_detail_speed').text(flight.groundspeed_kts ? flight.groundspeed_kts + ' kts' : '--');
            $('#adl_detail_heading').text(flight.heading_deg ? flight.heading_deg + '°' : '--');
            $('#adl_detail_filed_alt').text(flight.fp_altitude_ft ? 'FL' + Math.round(flight.fp_altitude_ft / 100) : '--');
            $('#adl_detail_etd').text(formatTime(flight.etd_runway_utc));
            $('#adl_detail_eta').text(formatTime(flight.eta_runway_utc));
            $('#adl_detail_fp_route').text(flight.fp_route || '--');
            
            // Store reference for modal buttons
            $('#adlFlightDetailModal').data('flight', flight);
            $('#adlFlightDetailModal').modal('show');
        }

        function zoomToFlight(flight) {
            if (!flight.lat || !flight.lon) return;
            if (typeof graphic_map !== 'undefined') {
                graphic_map.setView([parseFloat(flight.lat), parseFloat(flight.lon)], 8, {
                    animate: true,
                    duration: 0.5
                });
            }
        }

        function toggleTrackFlight(flight) {
            var key = flight.flight_key || flight.callsign;
            
            if (state.trackedFlight === key) {
                // Stop tracking
                state.trackedFlight = null;
            } else {
                // Start tracking
                state.trackedFlight = key;
                zoomToFlight(flight);
            }
            
            // Update all markers to reflect tracking state
            updateDisplay();
        }

        // ─────────────────────────────────────────────────────────────────────
        // UI UPDATES
        // ─────────────────────────────────────────────────────────────────────

        function updateStats() {
            $('#adl_stats_display').html('<strong>' + state.filteredFlights.length + '</strong> shown');
            $('#adl_stats_total').html('<strong>' + state.flights.length + '</strong> total');
        }

        function updateRefreshStatus(status) {
            var el = $('#adl_refresh_status');
            if (!el.length) return;
            
            if (status === 'Error') {
                el.html('<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Error</span>');
                return;
            }
            
            if (state.lastRefresh) {
                var time = state.lastRefresh.toISOString().substr(11, 8) + 'Z';
                el.html('<i class="fas fa-sync-alt"></i> ' + time);
            }
        }

        function updateStatusBadge() {
            var badge = $('#adl_status_badge');
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
        // ENABLE / DISABLE
        // ─────────────────────────────────────────────────────────────────────

        function enable() {
            console.log('[ADL] Enable called, state.enabled:', state.enabled);
            console.log('[ADL] graphic_map exists:', !!graphic_map);
            
            if (state.enabled) return;
            
            // Check if map exists
            if (!graphic_map) {
                console.warn('[ADL] Map not initialized yet. Plot a route first.');
                alert('Please plot a route first to initialize the map.');
                $('#adl_toggle').prop('checked', false);
                return;
            }
            
            state.enabled = true;
            console.log('[ADL] Enabling live flights...');
            
            // Create layer if needed
            if (!state.layer) {
                state.layer = L.layerGroup();
                console.log('[ADL] Created new layer group');
            }
            
            // Add to map and bring to front (flights should be on top)
            state.layer.addTo(graphic_map);
            state.layer.bringToFront();
            console.log('[ADL] Layer added to map and brought to front');
            
            // Enable filter button
            $('#adl_filter_toggle').prop('disabled', false);
            
            // Initialize color scheme from dropdown and render legend
            state.colorBy = $('#adl_color_by').val() || 'weight_class';
            renderColorLegend();
            
            // Initial fetch
            fetchFlights();
            
            // Start auto-refresh
            state.refreshInterval = setInterval(function() {
                fetchFlights();
            }, state.refreshRateMs);
            
            updateStatusBadge();
            console.log('[ADL] Live flights enabled successfully');
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
            if (state.routeLayer && graphic_map) {
                graphic_map.removeLayer(state.routeLayer);
                state.routeLayer = null;
            }
            
            // Remove layer from map
            if (state.layer && graphic_map) {
                graphic_map.removeLayer(state.layer);
            }
            
            // Clear markers
            state.markers.clear();
            if (state.layer) state.layer.clearLayers();
            
            // Hide filter panel
            $('#adl_filter_panel').hide();
            
            // Disable filter button
            $('#adl_filter_toggle').prop('disabled', true);
            
            // Clear status
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
            console.log('[ADL] init() called, attaching event handlers');
            
            // Toggle switch
            $('#adl_toggle').on('change', function() {
                console.log('[ADL] Toggle changed to:', $(this).is(':checked'));
                $(this).is(':checked') ? enable() : disable();
            });
            
            // Filter panel toggle
            $('#adl_filter_toggle').on('click', function() {
                console.log('[ADL] Filter toggle button clicked');
                var panel = $('#adl_filter_panel');
                console.log('[ADL] Filter panel element:', panel.length, 'visible:', panel.is(':visible'));
                panel.slideToggle(150);
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
                // If filter routes is checked, remove routes for non-matching flights
                if ($('#adl_routes_filter_only').is(':checked')) {
                    filterRoutesByCurrentFilter();
                }
            });
            
            // Clear filters
            $('#adl_filter_clear').on('click', clearFiltersUI);
            
            // Route buttons
            $('#adl_routes_show_all').on('click', function() {
                showAllRoutes();
            });
            
            $('#adl_routes_clear').on('click', function() {
                clearAllRoutes();
            });
            
            // Filter routes checkbox - apply immediately when toggled on
            $('#adl_routes_filter_only').on('change', function() {
                if ($(this).is(':checked')) {
                    filterRoutesByCurrentFilter();
                }
            });
            
            // Color-by dropdown change
            $('#adl_color_by').on('change', function() {
                state.colorBy = $(this).val();
                console.log('[ADL] Color scheme changed to:', state.colorBy);
                renderColorLegend();
                // Refresh all markers and routes with new colors
                if (state.enabled) {
                    updateDisplay();
                    refreshRouteColors();
                }
            });
            
            // Initialize legend on load
            renderColorLegend();
            
            // Filter inputs - apply on Enter
            $('.adl-filter-input').on('keypress', function(e) {
                if (e.which === 13) {
                    collectFiltersFromUI();
                    applyFilters();
                    updateDisplay();
                    updateStats();
                    // If filter routes is checked, remove routes for non-matching flights
                    if ($('#adl_routes_filter_only').is(':checked')) {
                        filterRoutesByCurrentFilter();
                    }
                }
            });
            
            // Context menu actions
            $('#adl_context_menu .menu-item').on('click', function() {
                handleContextMenuAction($(this).data('action'));
            });
            
            // Modal buttons
            $('#adl_modal_zoom').on('click', function() {
                var flight = $('#adlFlightDetailModal').data('flight');
                if (flight) zoomToFlight(flight);
            });
            
            $('#adl_modal_track').on('click', function() {
                var flight = $('#adlFlightDetailModal').data('flight');
                if (flight) {
                    toggleTrackFlight(flight);
                    $('#adlFlightDetailModal').modal('hide');
                }
            });
            
            // Close context menu on map click
            if (typeof graphic_map !== 'undefined') {
                graphic_map.on('click', hideContextMenu);
            }
        }

        // Utility
        function escHtml(str) {
            var div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        // Initialize when graphic_map is ready
        // The map is created inside the plot function, so we wait for it
        var initWatcher = setInterval(function() {
            if (graphic_map) {
                console.log('[ADL] Map detected, initializing ADL module');
                clearInterval(initWatcher);
                init();
            }
        }, 500);
        
        // Also init immediately if map already exists
        $(document).ready(function() {
            console.log('[ADL] Module loaded, waiting for map...');
        });

        // Expose public API
        return {
            enable: enable,
            disable: disable,
            toggle: toggle,
            refresh: fetchFlights,
            getState: function() { return state; }
        };
    })();

    // Make ADL available globally for debugging
    window.ADL = ADL;

    // ═══════════════════════════════════════════════════════════════════
    // PUBLIC ROUTES INTEGRATION (Leaflet)
    // ═══════════════════════════════════════════════════════════════════

    (function() {
        'use strict';

        // Wait for map and PublicRoutes module
        var checkInterval = setInterval(function() {
            if (typeof graphic_map !== 'undefined' && graphic_map && typeof window.PublicRoutes !== 'undefined') {
                clearInterval(checkInterval);
                initPublicRoutesIntegration();
            }
        }, 500);

        var publicRoutesLayer = null;
        var publicRoutesLabels = null;

        function initPublicRoutesIntegration() {
            console.log('[PublicRoutes-Leaflet] Initializing integration...');

            // Create layer group for public routes
            publicRoutesLayer = L.layerGroup().addTo(graphic_map);
            publicRoutesLabels = L.layerGroup().addTo(graphic_map);

            // Override render function
            window.PublicRoutes.setRenderFunction(renderPublicRoutes);
            window.PublicRoutes.setZoomFunction(zoomToPublicRoute);
            window.PublicRoutes.setClearFunction(clearPublicRoutes);

            // Listen for route updates
            $(document).on('publicRoutes:updated', function(e, routes) {
                console.log('[PublicRoutes-Leaflet] Routes updated:', routes.length);
            });

            // Expose getLastExpandedRoutes for public routes feature
            window.getLastExpandedRoutes = function() {
                return lastExpandedRoutes || [];
            };

            console.log('[PublicRoutes-Leaflet] Integration ready');
        }

        function renderPublicRoutes() {
            if (!publicRoutesLayer || !graphic_map) return;

            // Clear existing
            publicRoutesLayer.clearLayers();
            publicRoutesLabels.clearLayers();

            var routes = window.PublicRoutes.getRoutes();
            console.log('[PublicRoutes-Leaflet] Rendering', routes.length, 'routes');

            routes.forEach(function(route) {
                var routeColor = route.color || '#e74c3c';
                var lineStyle = {
                    color: routeColor,
                    weight: route.line_weight || 3,
                    opacity: 0.9
                };

                if (route.line_style === 'dashed') {
                    lineStyle.dashArray = '10, 5';
                } else if (route.line_style === 'dotted') {
                    lineStyle.dashArray = '2, 4';
                }

                var allCoords = [];
                var usedGeoJSON = false;

                // Try to use stored GeoJSON first
                if (route.route_geojson) {
                    var geojson = null;
                    try {
                        geojson = typeof route.route_geojson === 'string' 
                            ? JSON.parse(route.route_geojson) 
                            : route.route_geojson;
                    } catch (e) {
                        console.warn('[PublicRoutes-Leaflet] Failed to parse route_geojson for:', route.name);
                    }

                    if (geojson && geojson.features && geojson.features.length > 0) {
                        console.log('[PublicRoutes-Leaflet] Using stored GeoJSON for:', route.name, 'with', geojson.features.length, 'features');
                        usedGeoJSON = true;

                        // Add each feature as a polyline
                        geojson.features.forEach(function(feature) {
                            if (feature.geometry && feature.geometry.coordinates && feature.geometry.coordinates.length >= 2) {
                                // Convert from [lng, lat] to [lat, lng] for Leaflet
                                var coords = feature.geometry.coordinates.map(function(c) {
                                    return [c[1], c[0]];
                                });

                                // Split at IDL to avoid cross-map lines
                                var segments = splitAtIDL(coords);
                                segments.forEach(function(segment) {
                                    if (segment && segment.length >= 2) {
                                        var polyline = L.polyline(segment, lineStyle);
                                        polyline._publicRouteData = route;
                                        polyline.bindPopup(createRoutePopup(route), { maxWidth: 300 });
                                        polyline.addTo(publicRoutesLayer);
                                    }
                                });

                                // Collect for label placement
                                allCoords = allCoords.concat(coords);
                            }
                        });
                    }
                }

                // Fallback: parse route_string
                if (!usedGeoJSON) {
                    var coords = parseRouteToCoords(route.route_string);
                    if (coords.length < 2) {
                        console.warn('[PublicRoutes-Leaflet] Could not parse route:', route.name, '- no stored GeoJSON and route_string parsing failed');
                        return;
                    }

                    // Split at IDL to avoid cross-map lines
                    var segments = splitAtIDL(coords);
                    segments.forEach(function(segment) {
                        if (segment && segment.length >= 2) {
                            var polyline = L.polyline(segment, lineStyle);
                            polyline._publicRouteData = route;
                            polyline.bindPopup(createRoutePopup(route), { maxWidth: 300 });
                            polyline.addTo(publicRoutesLayer);
                        }
                    });
                    allCoords = coords;
                }

                // Add label at midpoint of first line
                if (allCoords.length >= 2) {
                    var midIdx = Math.floor(allCoords.length / 2);
                    var midPoint = allCoords[midIdx];
                    if (midPoint) {
                        var label = L.marker(midPoint, {
                            icon: L.divIcon({
                                className: 'public-route-label',
                                html: '<div style="background: ' + routeColor + '; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.3);">' + escHtml(route.name) + '</div>',
                                iconAnchor: [0, 0]
                            })
                        });
                        label.addTo(publicRoutesLabels);
                    }
                }
            });
        }

        function parseRouteToCoords(routeString) {
            // Parse route string and get coordinates for each fix
            // Uses same logic as main route plotter for consistent results
            // including DP/STAR expansion via procs_enhanced.js
            if (!routeString) return [];

            var allCoords = [];
            var lines = routeString.split(/[\r\n]+/);
            
            lines.forEach(function(line) {
                var lineCoords = [];
                var lineTrimmed = (line || '').trim();
                if (!lineTrimmed) return;
                
                // Strip color suffix if present
                var semiIdx = lineTrimmed.indexOf(';');
                if (semiIdx !== -1) {
                    lineTrimmed = lineTrimmed.substring(0, semiIdx).trim();
                }
                
                // Strip mandatory markers and normalize
                lineTrimmed = lineTrimmed.replace(/[<>]/g, '').toUpperCase();
                
                // --- CDR expansion (same as main route plotter) ---
                var cdrTokens = lineTrimmed.split(/\s+/).filter(function(t) { return t !== ''; });
                if (cdrTokens.length === 1 && typeof cdrMap !== 'undefined' && cdrMap[cdrTokens[0]]) {
                    lineTrimmed = cdrMap[cdrTokens[0]].toUpperCase();
                } else if (cdrTokens.length > 1 && typeof cdrMap !== 'undefined') {
                    var expandedTokens = [];
                    cdrTokens.forEach(function(tok) {
                        var code = tok.toUpperCase();
                        if (cdrMap[code]) {
                            var rtoks = cdrMap[code].toUpperCase().split(/\s+/).filter(function(t) { return t !== ''; });
                            expandedTokens = expandedTokens.concat(rtoks);
                        } else {
                            expandedTokens.push(code);
                        }
                    });
                    lineTrimmed = expandedTokens.join(' ');
                }
                // --- end CDR expansion ---
                
                var origTokensClean = lineTrimmed.split(/\s+/).filter(function(t) { return t !== ''; });
                var solidMask = origTokensClean.map(function() { return false; });
                
                // --- DP/STAR expansion using procs_enhanced.js (same as main route plotter) ---
                var procInfo = null;
                
                if (typeof preprocessRouteProcedures === 'function') {
                    try {
                        procInfo = preprocessRouteProcedures(origTokensClean);
                        if (procInfo && procInfo.tokens && procInfo.tokens.length) {
                            origTokensClean = procInfo.tokens;
                        }
                    } catch (e) {
                        console.warn('[PublicRoutes] preprocessRouteProcedures failed:', e);
                    }
                }
                
                if (typeof expandRouteProcedures === 'function') {
                    try {
                        var expanded = expandRouteProcedures(origTokensClean, solidMask, procInfo);
                        if (expanded && expanded.tokens && expanded.tokens.length) {
                            origTokensClean = expanded.tokens;
                            solidMask = expanded.solidMask || solidMask;
                        }
                    } catch (e) {
                        console.warn('[PublicRoutes] expandRouteProcedures failed:', e);
                    }
                } else {
                    // Fallback to legacy DP/STAR expansion
                    if (typeof applyDepartureProcedures === 'function') {
                        try {
                            origTokensClean = applyDepartureProcedures(origTokensClean);
                        } catch (e) {
                            console.warn('[PublicRoutes] applyDepartureProcedures failed:', e);
                        }
                    }
                    
                    if (typeof expandDepartureProcedures === 'function' && typeof dpDataLoaded !== 'undefined' && dpDataLoaded) {
                        try {
                            var dpExpanded = expandDepartureProcedures(origTokensClean, solidMask);
                            if (dpExpanded && dpExpanded.tokens && dpExpanded.tokens.length) {
                                origTokensClean = dpExpanded.tokens;
                                solidMask = dpExpanded.solidMask || solidMask;
                            }
                        } catch (e) {
                            console.warn('[PublicRoutes] expandDepartureProcedures failed:', e);
                        }
                    }
                    
                    if (typeof expandStarProcedures === 'function' && typeof starDataLoaded !== 'undefined' && starDataLoaded) {
                        try {
                            var starExpanded = expandStarProcedures(origTokensClean, solidMask);
                            if (starExpanded && starExpanded.tokens && starExpanded.tokens.length) {
                                origTokensClean = starExpanded.tokens;
                                solidMask = starExpanded.solidMask || solidMask;
                            }
                        } catch (e) {
                            console.warn('[PublicRoutes] expandStarProcedures failed:', e);
                        }
                    }
                }
                // --- end DP/STAR expansion ---
                
                // Expand airways using ConvertRoute
                var expandedStr = origTokensClean.join(' ');
                if (typeof ConvertRoute === 'function') {
                    try {
                        expandedStr = ConvertRoute(expandedStr);
                    } catch (e) {
                        console.warn('[PublicRoutes] ConvertRoute failed:', e);
                    }
                }
                
                var tokens = expandedStr.split(/\s+/).filter(function(t) { return t; });
                
                // Collect all resolvable tokens (for context)
                var resolvedPoints = [];
                tokens.forEach(function(token, idx) {
                    // Skip airways (shouldn't be any after ConvertRoute, but just in case)
                    if (/^[JQTV]\d+$/i.test(token)) return;
                    
                    // Skip pure numbers (altitudes, speeds)
                    if (/^\d+$/.test(token)) return;
                    
                    // Skip speed/altitude restrictions like N0450, F350
                    if (/^[NMFSA]\d{3,4}$/.test(token)) return;
                    
                    // Handle any remaining DP/STAR notation (e.g., JFK.ROBUC3, THHMP.CAVLR6)
                    var cleanToken = token;
                    if (token.indexOf('.') !== -1) {
                        var dotParts = token.split('.');
                        cleanToken = dotParts[0]; // Try first part
                    }
                    
                    resolvedPoints.push({
                        token: cleanToken,
                        index: idx,
                        point: null
                    });
                });
                
                // Resolve points with context
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
                        lineCoords.push([point[1], point[2]]); // Leaflet uses [lat, lon]
                    }
                });
                
                // Add this line's coords to the overall collection
                if (lineCoords.length >= 2) {
                    allCoords = allCoords.concat(lineCoords);
                }
            });

            return allCoords;
        }

        function createRoutePopup(route) {
            var validStart = formatPublicRouteTime(route.valid_start_utc);
            var validEnd = formatPublicRouteTime(route.valid_end_utc);

            return '<div class="public-route-popup">' +
                '<div style="font-weight: bold; font-size: 1.1em; margin-bottom: 8px;">' +
                '<span style="display: inline-block; width: 12px; height: 12px; background: ' + route.color + '; border-radius: 2px; margin-right: 6px;"></span>' +
                escHtml(route.name) +
                '</div>' +
                '<div class="small">' +
                (route.constrained_area ? '<div><strong>Area:</strong> ' + escHtml(route.constrained_area) + '</div>' : '') +
                '<div><strong>Valid:</strong> ' + validStart + ' - ' + validEnd + '</div>' +
                (route.reason ? '<div><strong>Reason:</strong> ' + escHtml(route.reason) + '</div>' : '') +
                '<div style="font-family: Inconsolata, monospace; margin-top: 8px; padding: 6px; background: #f8f9fa; border-radius: 4px; word-break: break-all;">' +
                escHtml(route.route_string) +
                '</div>' +
                '</div>' +
                '</div>';
        }

        function zoomToPublicRoute(route) {
            if (!graphic_map || !route) return;

            var coords = [];
            
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
                            feature.geometry.coordinates.forEach(function(c) {
                                coords.push([c[1], c[0]]); // Convert [lng, lat] to [lat, lng]
                            });
                        }
                    });
                }
            }
            
            // Fallback to parsing route_string
            if (coords.length === 0 && route.route_string) {
                coords = parseRouteToCoords(route.route_string);
            }
            
            if (coords.length > 0) {
                var bounds = L.latLngBounds(coords);
                graphic_map.fitBounds(bounds, { padding: [50, 50] });
            }
        }

        function clearPublicRoutes() {
            if (publicRoutesLayer) publicRoutesLayer.clearLayers();
            if (publicRoutesLabels) publicRoutesLabels.clearLayers();
        }

        function formatPublicRouteTime(isoString) {
            if (!isoString) return '--';
            try {
                var d = new Date(isoString);
                var day = String(d.getUTCDate()).padStart(2, '0');
                var hours = String(d.getUTCHours()).padStart(2, '0');
                var mins = String(d.getUTCMinutes()).padStart(2, '0');
                return day + hours + mins + 'Z';
            } catch (e) {
                return '--';
            }
        }

        function escHtml(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    })();

});