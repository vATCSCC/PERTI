// route.js - merged groups_v4 + updated_v3
/* Integrated by ChatGPT on user request */
// assets/js/route.js

$(document).ready(function() {

    let cached_geojson_linestring = [];
    let cached_geojson_multipoint = [];
    let overlays = [];
    let graphic_map; // Exposed for ADL Live Flights module
    
    let points = {};
    // Derived facility codes from ZZ_* dummy fixes (ARTCCs / TRACONs / FIRs, etc.)
    let facilityCodes = new Set();
    // TRACON / ARTCC centers (for pseudo-airport behavior)
    let areaCenters = {};
    // CDR map: CODE -> full route string
    let cdrMap = {};
    // Playbook routes
    let playbookRoutes = [];

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
    
                playbookRoutes.push({
                    playName: playNameRaw,
                    playNameNorm: normalizePlayName(playNameRaw),
                    // used later when building the actual route string
                    fullRoute: routeRaw.toUpperCase(),
    
                    // new fields for matching
                    originAirports: originAirports,
                    originTracons:  originTracons,
                    originArtccs:   originArtccs,
                    destAirports:   destAirports,
                    destTracons:    destTracons,
                    destArtccs:     destArtccs
                });
            }
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
    
        playbookRoutes.forEach(pr => {
            if (pr.playNameNorm !== playNorm) return;
    
            // --- Origin filter ----------------------------------------------------
            if (originTokens.length) {
                let originMatch = false;
    
                for (let i = 0; i < originTokens.length && !originMatch; i++) {
                    const tok        = originTokens[i].toUpperCase();
                    const tokAirport = normalizePlayEndpointTokenForAirport(tok);
    
                    // Airport match: Origins column
                    if (pr.originAirports && pr.originAirports.indexOf(tokAirport) !== -1) {
                        originMatch = true;
                        break;
                    }
                    // TRACON match
                    if (pr.originTracons && pr.originTracons.indexOf(tok) !== -1) {
                        originMatch = true;
                        break;
                    }
                    // ARTCC match
                    if (pr.originArtccs && pr.originArtccs.indexOf(tok) !== -1) {
                        originMatch = true;
                        break;
                    }
                }
    
                if (!originMatch) return;
            }
    
            // --- Destination filter ----------------------------------------------
            if (destTokens.length) {
                let destMatch = false;
    
                for (let i = 0; i < destTokens.length && !destMatch; i++) {
                    const tok        = destTokens[i].toUpperCase();
                    const tokAirport = normalizePlayEndpointTokenForAirport(tok);
    
                    // Airport match: Destinations column
                    if (pr.destAirports && pr.destAirports.indexOf(tokAirport) !== -1) {
                        destMatch = true;
                        break;
                    }
                    // TRACON match
                    if (pr.destTracons && pr.destTracons.indexOf(tok) !== -1) {
                        destMatch = true;
                        break;
                    }
                    // ARTCC match
                    if (pr.destArtccs && pr.destArtccs.indexOf(tok) !== -1) {
                        destMatch = true;
                        break;
                    }
                }
    
                if (!destMatch) return;
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
        });
    
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
    loadDepartureProcedures();
    loadStarProcedures();
    
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
    
        if (graphic_map) {
            graphic_map.off();
            graphic_map.remove();
            graphic_map = null;
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
    
        // WX Cells (START)
        var cells = L.tileLayer(
            'https://web.ics.purdue.edu/~snandaku/atc/processor.php?x={x}&y={y}&z={z}', {
                tileSize: 256,
                opacity: 0.7,
                ts: function() {
                    return Date.now();
                }
            });
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
    
        var overlaysArray = [
            ['high_splits', high_splits],
            ['low_splits', low_splits],
            ['tracon', tracon],
            ['navaids', navaids],
            ['sigmets', sigmets],
            ['cells', cells]
        ];
    
        var layers = {
            "High Splits": high_splits,
            "Low Splits": low_splits,
            "TRACON Boundaries<hr>": tracon,
            "All NAVAIDs<hr>": navaids,
            "WX Cells": cells,
            "SIGMETs": sigmets
        };  
    
        overlaysArray.forEach(o => {
            if (overlays.includes(o[0])) {
                o[1].addTo(graphic_map);
            }
        }); 
    
        graphic_map.on('overlayadd', function(eventlayer) {
            overlaysArray.forEach(o => {
                if (eventlayer.layer == o[1]) {
                    overlayAdd(o[0]);
                }
            }); 
    
            artcc.bringToFront();
        });
    
        graphic_map.on('overlayremove', function(eventlayer) {
            overlaysArray.forEach(o => {
                if (eventlayer.layer == o[1]) {
                    overlayRemove(o[0]);
                }
            }); 
    
            artcc.bringToFront();
        });
    
        L.control.layers(null, layers).addTo(graphic_map);
        // OVERLAYS (E)
    
        // Route (S)
        // Route (S)
        function ConvertRoute(route) {
            // Expand airways (e.g. BURGG Q22 RBV) into intermediate fixes
            // using the global `awys` table from awys.csv:
            //   [ airwayId, "FIX1 FIX2 ... FIXN" ]
            //
            // This is called *after* PB, CDR, DP, and STAR expansion so that
            // airways embedded in those expanded routes are also handled.
            if (!route || typeof route !== 'string') {
                return route;
            }

            const split_route = route.split(' ').filter(t => t !== '');
            if (split_route.length < 3 || typeof awys === 'undefined' || !Array.isArray(awys)) {
                return route;
            }

            const expandedTokens = [];

            for (let i = 0; i < split_route.length; i++) {
                const point = split_route[i];

                // Only attempt airway expansion if there is a previous and next token
                if (i > 0 && i < split_route.length - 1) {
                    const prevTok = split_route[i - 1];
                    const nextTok = split_route[i + 1];

                    // Find airway row whose ID (first column) exactly matches the token
                    const awyIndex = awys.findIndex(row =>
                        Array.isArray(row) &&
                        row.length >= 2 &&
                        String(row[0]).toUpperCase() === String(point).toUpperCase()
                    );

                    if (awyIndex !== -1) {
                        const airwayRouteString = String(awys[awyIndex][1] || '');
                        const airwayFixes = airwayRouteString.split(' ').filter(t => t !== '');

                        if (airwayFixes.length > 0) {
                            const fromIdx = airwayFixes.indexOf(prevTok.toUpperCase());
                            const toIdx   = airwayFixes.indexOf(nextTok.toUpperCase());

                            // Only expand if both endpoints are present and separated
                            if (fromIdx !== -1 && toIdx !== -1 && Math.abs(fromIdx - toIdx) > 1) {
                                let middleFixes;
                                if (fromIdx < toIdx) {
                                    // Same direction as airway definition
                                    middleFixes = airwayFixes.slice(fromIdx + 1, toIdx);
                                } else {
                                    // Opposite direction: reverse the slice
                                    middleFixes = airwayFixes.slice(toIdx + 1, fromIdx).reverse();
                                }

                                // Insert intermediate fixes instead of the airway token
                                middleFixes.forEach(f => expandedTokens.push(f));
                                continue; // skip pushing `point` itself
                            }
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
        const linestring = new L.layerGroup();
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

                const baseRouteString      = origTokensClean.join(' ');
                // STAR expansion: expand any STAR/transition tokens (e.g. SLT.FQM3, BCE.BLAID2)
                if (typeof expandStarProcedures === 'function' && starDataLoaded) {
                    const starExpanded = expandStarProcedures(origTokensClean, solidMask);
                    if (starExpanded && starExpanded.tokens && starExpanded.tokens.length) {
                        origTokensClean = starExpanded.tokens;
                        solidMask       = starExpanded.solidMask;
                    }
                }
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
    
                    console.warn(`Can't find fix "${pointName}"!`);
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
                    }).addTo(graphic_map);

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

                // Hint to the geodesic plugin: use fewer interpolation steps
                options.steps = options.steps || 16;

                const line = new L.geodesic(seg.coords, options);
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



                line.addTo(graphic_map);
                linestring.addLayer(line);
            });
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
    }
function advIsFacility(code) {
        if (!code) return false;
        const t = String(code).toUpperCase().trim();
        if (!t) return false;

        // 4-char ICAO-style airport / facility (KATL, KMCO, CYYZ, etc.)
        if (/^[A-Z0-9]{4}$/.test(t)) return true;

        // ARTCC (ZNY, ZOB, etc.)
        if (/^Z[A-Z0-9]{2}$/.test(t)) return true;

        // TRACON-style (A80, N90, L30, etc.)
        if (/^[A-Z][0-9]{2}$/.test(t)) return true;

        return false;
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

            const cdrTokens = routeText.split(/\s+/).filter(function(t) { return t !== ''; });
            if (!cdrTokens.length) {
                return '';
            }

            if (cdrTokens.length === 1) {
                const code = cdrTokens[0].toUpperCase();
                if (cdrMap[code]) {
                    routeText = cdrMap[code].toUpperCase();
                } else {
                    routeText = code;
                }
            } else {
                const expandedTokens = [];
                cdrTokens.forEach(function(tok) {
                    const code = tok.toUpperCase();
                    if (cdrMap[code]) {
                        const rtoks = cdrMap[code].toUpperCase().split(/\s+/).filter(Boolean);
                        expandedTokens.push.apply(expandedTokens, rtoks);
                    } else {
                        expandedTokens.push(code);
                    }
                });
                routeText = expandedTokens.join(' ');
            }

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
            return;
        }

        const trimmed = line.trim();
        if (!trimmed) {
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

        // Header line – vATCSCC ADVZY NNN FAC MM/DD/YYYY ROUTE RQD
        let header = 'vATCSCC ADVZY ' + advNumber + ' ' + advFacility + ' ' + advDate + ' ' + advAction;
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

        // Pick column widths based on the routes we’re about to print
        advComputeColumnWidths(consolidated);

        lines.push('ROUTES:');
        lines.push('');

        // Header row
        advFormatRouteRow('ORIG', 'DEST', 'ROUTE').forEach(function(l) { lines.push(l); });
        advFormatRouteRow('----', '----', '-----').forEach(function(l) { lines.push(l); });

        // Actual consolidated routes
        consolidated.forEach(function(r) {
            advFormatRouteRow(r.orig, r.dest, r.assignedRoute).forEach(function(l) { lines.push(l); });
        });

        lines.push('');

        if (advTmiId) {
            lines.push('TMI ID: ' + advTmiId);
        }

        lines.push('EFFECTIVE TIME: ' + (advEffTime || ''));$('#advOutput').val(lines.join('\n'));
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
    });

    // Set default advisory date/time values in UTC
    function advSetDefaultTimesUtc() {
        function pad2(n) {
            return n.toString().padStart(2, '0');
        }

        const now = new Date();
        const start = new Date(now.getTime() + 15 * 60 * 1000);       // now + 15 min
        const end   = new Date(start.getTime() + 4 * 60 * 60 * 1000); // + 4 hours

        // Date field (today's date in UTC, MM/DD/YYYY)
        const mm  = pad2(now.getUTCMonth() + 1);
        const dd  = pad2(now.getUTCDate());
        const yyyy = now.getUTCFullYear();
        $('#advDate').val(mm + '/' + dd + '/' + yyyy);

        // Valid start / end in DDHHMM (UTC)
        const ddStart = pad2(start.getUTCDate());
        const hhStart = pad2(start.getUTCHours());
        const mnStart = pad2(start.getUTCMinutes());

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
            layer: null,
            refreshInterval: null,
            refreshRateMs: 15000,
            lastRefresh: null,
            trackedFlight: null,
            selectedFlight: null,
            filters: {
                weightClasses: ['SUPER', 'HEAVY', 'LARGE', 'SMALL', 'J', 'H', 'L', 'S', ''],
                origin: '',
                dest: '',
                carrier: '',
                altitudeMin: null,
                altitudeMax: null
            }
        };

        // TSD Icon Colors by weight class
        const WEIGHT_COLORS = {
            'SUPER': '#17a2b8',  // Cyan - Jumbo (A380, B747)
            'J': '#17a2b8',
            'HEAVY': '#20c997',  // Teal - Heavy (B777, B787, A330)
            'H': '#20c997',
            'LARGE': '#6c757d',  // Gray - Jet (B737, A320)
            'L': '#6c757d',
            'SMALL': '#ffc107',  // Yellow - Prop
            'S': '#ffc107',
            '': '#adb5bd'        // Default
        };

        // TSD Icon Sizes by weight class
        const WEIGHT_SIZES = {
            'SUPER': 22, 'J': 22,
            'HEAVY': 18, 'H': 18,
            'LARGE': 14, 'L': 14,
            'SMALL': 11, 'S': 11,
            '': 12
        };

        // ─────────────────────────────────────────────────────────────────────
        // SVG ICON GENERATION
        // ─────────────────────────────────────────────────────────────────────

        function createTsdIcon(weightClass, heading, isTracked) {
            const wc = (weightClass || '').toUpperCase().trim();
            const color = WEIGHT_COLORS[wc] || WEIGHT_COLORS[''];
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
                // BUFFERED: Don't clear state.flights on error
                console.log('[ADL] Keeping previous data due to error (' + previousFlights.length + ' flights)');
            });
        }

        // ─────────────────────────────────────────────────────────────────────
        // FILTERING
        // ─────────────────────────────────────────────────────────────────────

        function applyFilters() {
            var f = state.filters;
            
            state.filteredFlights = state.flights.filter(function(flight) {
                // Weight class filter
                var wc = (flight.weight_class || '').toUpperCase().trim();
                var wcMatch = f.weightClasses.some(function(w) {
                    if (w === '' && wc === '') return true;
                    if (w === wc) return true;
                    // Map aliases
                    if ((w === 'SUPER' || w === 'J') && (wc === 'SUPER' || wc === 'J')) return true;
                    if ((w === 'HEAVY' || w === 'H') && (wc === 'HEAVY' || wc === 'H')) return true;
                    if ((w === 'LARGE' || w === 'L') && (wc === 'LARGE' || wc === 'L' || wc === '')) return true;
                    if ((w === 'SMALL' || w === 'S') && (wc === 'SMALL' || wc === 'S')) return true;
                    return false;
                });
                if (!wcMatch) return false;
                
                // Origin filter (ARTCC or airport)
                if (f.origin) {
                    var orig = f.origin.toUpperCase();
                    var deptIcao = (flight.fp_dept_icao || '').toUpperCase().trim();
                    var deptArtcc = (flight.fp_dept_artcc || '').toUpperCase().trim();
                    if (deptIcao.indexOf(orig) === -1 && deptArtcc.indexOf(orig) === -1) return false;
                }
                
                // Destination filter
                if (f.dest) {
                    var dest = f.dest.toUpperCase();
                    var destIcao = (flight.fp_dest_icao || '').toUpperCase().trim();
                    var destArtcc = (flight.fp_dest_artcc || '').toUpperCase().trim();
                    if (destIcao.indexOf(dest) === -1 && destArtcc.indexOf(dest) === -1) return false;
                }
                
                // Carrier filter
                if (f.carrier) {
                    var carrier = f.carrier.toUpperCase();
                    var flightCarrier = (flight.major_carrier || flight.callsign || '').toUpperCase();
                    if (flightCarrier.indexOf(carrier) !== 0) return false;
                }
                
                // Altitude filter (use current altitude_ft or filed fp_altitude_ft)
                var alt = flight.altitude_ft || flight.fp_altitude_ft || 0;
                var altFL = Math.round(alt / 100);
                if (f.altitudeMin != null && altFL < f.altitudeMin) return false;
                if (f.altitudeMax != null && altFL > f.altitudeMax) return false;
                
                return true;
            });
        }

        function collectFiltersFromUI() {
            // Weight classes
            var wcChecked = [];
            $('.adl-weight-filter:checked').each(function() {
                wcChecked.push($(this).val());
            });
            // Include empty string for unclassified if LARGE/jet is checked
            if (wcChecked.indexOf('LARGE') !== -1) wcChecked.push('');
            state.filters.weightClasses = wcChecked.length ? wcChecked : ['SUPER', 'HEAVY', 'LARGE', 'SMALL', ''];
            
            // Geographic
            state.filters.origin = ($('#adl_origin').val() || '').trim();
            state.filters.dest = ($('#adl_dest').val() || '').trim();
            
            // Carrier
            state.filters.carrier = ($('#adl_carrier').val() || '').trim();
            
            // Altitude
            var altMin = $('#adl_alt_min').val();
            var altMax = $('#adl_alt_max').val();
            state.filters.altitudeMin = altMin ? parseInt(altMin, 10) : null;
            state.filters.altitudeMax = altMax ? parseInt(altMax, 10) : null;
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
                
                if (state.markers.has(key)) {
                    // Update existing marker
                    var marker = state.markers.get(key);
                    marker.setLatLng([lat, lon]);
                    marker.setIcon(createTsdIcon(flight.weight_class, heading, isTracked));
                    marker._flightData = flight;
                } else {
                    // Create new marker
                    var marker = L.marker([lat, lon], {
                        icon: createTsdIcon(flight.weight_class, heading, isTracked),
                        zIndexOffset: isTracked ? 1000 : 0
                    });
                    
                    marker._flightData = flight;
                    
                    // Left-click: popup
                    marker.on('click', function(e) {
                        showFlightPopup(this._flightData, e.latlng);
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
            
            var content = '<div class="adl-popup">' +
                '<div class="callsign">' + escHtml(flight.callsign) + '</div>' +
                '<div class="route mb-2">' +
                '<strong>' + (flight.fp_dept_icao || '????') + '</strong>' +
                ' <i class="fas fa-long-arrow-alt-right mx-1"></i> ' +
                '<strong>' + (flight.fp_dest_icao || '????') + '</strong>' +
                '</div>' +
                '<div class="detail-row"><span class="detail-label">Aircraft</span><span class="detail-value">' + (flight.aircraft_type || '--') + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Altitude</span><span class="detail-value">' + alt + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Speed</span><span class="detail-value">' + spd + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Heading</span><span class="detail-value">' + hdg + '</span></div>' +
                '<div class="detail-row"><span class="detail-label">Phase</span><span class="detail-value">' + (flight.phase || flight.flight_status || '--') + '</span></div>' +
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
            
            // Position menu at click location
            menu.style.left = event.pageX + 'px';
            menu.style.top = event.pageY + 'px';
            menu.style.display = 'block';
            
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
            $('#adl_detail_aircraft').text(flight.aircraft_type || '--');
            $('#adl_detail_weight').text(flight.weight_class || '--');
            $('#adl_detail_carrier').text(flight.major_carrier || '--');
            $('#adl_detail_phase').text(flight.phase || '--');
            $('#adl_detail_status').text(flight.flight_status || '--');
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
            
            // Add to map
            state.layer.addTo(graphic_map);
            console.log('[ADL] Layer added to map');
            
            // Enable filter button
            $('#adl_filter_toggle').prop('disabled', false);
            
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
            });
            
            // Clear filters
            $('#adl_filter_clear').on('click', clearFiltersUI);
            
            // Filter inputs - apply on Enter
            $('.adl-filter-input').on('keypress', function(e) {
                if (e.which === 13) {
                    collectFiltersFromUI();
                    applyFilters();
                    updateDisplay();
                    updateStats();
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

});