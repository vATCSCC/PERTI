// procs_enhanced.js - Enhanced DP/STAR procedure handling
// Handles: Combined tokens, missing dots, version inference, fan-style airport handling
// Format: DPs = {NAME}{VERSION#}.{TRANSITION_FIX}, STARs = {TRANSITION_FIX}.{NAME}{VERSION#}

(function() {
    'use strict';

    // ═══════════════════════════════════════════════════════════════════════════
    // DATA STRUCTURES
    // ═══════════════════════════════════════════════════════════════════════════

    // DP Indexes
    window.dpFullRoutesByTransition = {};   // KAYLN3.SMUUV -> [records]
    window.dpFullRoutesByDpCode = {};       // KAYLN3.KAYLN -> [records]
    window.dpByLeftCode = {};               // KAYLN3 -> {code, effDate, servedAirports, ...}
    window.dpByRootName = {};               // KAYLN -> most recent record
    window.dpByTransitionFix = {};          // SMUUV -> [all DP records with that transition]
    window.dpPatternIndex = {};             // KAYLN#.SMUUV -> {code, effDate}
    window.dpAllRootNames = new Set();      // Set of all DP root names (KAYLN, ARDIA, etc.)

    // STAR Indexes
    window.starFullRoutesByTransition = {}; // SMUUV.WYNDE3 -> [records]
    window.starFullRoutesByStarCode = {};   // WYNDE.WYNDE3 -> [records]
    window.starByRightCode = {};            // WYNDE3 -> {code, effDate, servedAirports, ...}
    window.starByRootName = {};             // WYNDE -> most recent record
    window.starByTransitionFix = {};        // SMUUV -> [all STAR records with that transition]
    window.starPatternIndex = {};           // SMUUV.WYNDE# -> {code, effDate}
    window.starAllRootNames = new Set();    // Set of all STAR root names (WYNDE, BLAID, etc.)

    // Load state
    window.dpDataLoaded = false;
    window.starDataLoaded = false;

    // ═══════════════════════════════════════════════════════════════════════════
    // UTILITY FUNCTIONS
    // ═══════════════════════════════════════════════════════════════════════════

    function parseEffDate(str) {
        if (!str) {return 0;}
        const cleaned = String(str).replace(/[^\d]/g, '');
        if (!cleaned) {return 0;}
        const n = parseInt(cleaned, 10);
        return isNaN(n) ? 0 : n;
    }

    // Extract root name (letters only) from a procedure code
    // KAYLN3 -> KAYLN, WYNDE3 -> WYNDE, BLAID2 -> BLAID
    function extractRootName(code) {
        if (!code) {return '';}
        return code.replace(/[0-9#]/g, '').toUpperCase();
    }

    // Extract version number from a procedure code
    // KAYLN3 -> 3, WYNDE3 -> 3, KAYLN# -> null
    function extractVersion(code) {
        if (!code) {return null;}
        const match = code.match(/(\d+)$/);
        return match ? parseInt(match[1], 10) : null;
    }

    // Check if a token looks like a DP pattern: letters followed by digit(s)
    // KAYLN3 -> true, KAYLN -> false, KAYLN# -> true
    function looksLikeDpLeft(tok) {
        if (!tok) {return false;}
        return /^[A-Z]{2,}[0-9#]+$/i.test(tok);
    }

    // Check if a token looks like a STAR pattern: letters followed by digit(s)
    function looksLikeStarRight(tok) {
        if (!tok) {return false;}
        return /^[A-Z]{2,}[0-9#]+$/i.test(tok);
    }

    // Check if a token is a simple fix (3-5 letters, no numbers)
    function isSimpleFix(tok) {
        if (!tok) {return false;}
        return /^[A-Z]{3,5}$/i.test(tok);
    }

    // Check if token is an airport identifier
    function isAirportCode(tok) {
        if (!tok) {return false;}
        tok = tok.toUpperCase();
        // 4-letter ICAO or 3-letter + K prefix pattern
        if (tok.length === 4 && /^[A-Z]{4}$/.test(tok)) {return true;}
        if (tok.length === 3 && /^[A-Z]{3}$/.test(tok) && !tok.startsWith('Z')) {return true;}
        return false;
    }

    // Normalize airport code to 4-letter ICAO
    function normalizeAirport(tok) {
        if (!tok) {return tok;}
        tok = tok.toUpperCase().trim();
        if (tok.length === 3 && /^[A-Z]{3}$/.test(tok) && !tok.startsWith('Z')) {
            return 'K' + tok;
        }
        return tok;
    }

    // Extract airport code from origin group string like "KDTW/27L|27R KORD/09R"
    function extractAirportsFromGroup(groupStr) {
        if (!groupStr) {return [];}
        const airports = [];
        const tokens = groupStr.split(/\s+/);
        for (const tok of tokens) {
            if (!tok) {continue;}
            const slashIdx = tok.indexOf('/');
            const apt = slashIdx >= 0 ? tok.substring(0, slashIdx) : tok;
            if (apt && isAirportCode(apt)) {
                const normalized = normalizeAirport(apt);
                if (airports.indexOf(normalized) === -1) {
                    airports.push(normalized);
                }
            }
        }
        return airports;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DP LOADING - loads dp_full_routes.csv
    // ═══════════════════════════════════════════════════════════════════════════

    window.loadDepartureProceduresEnhanced = function() {
        $.ajax({
            type: 'GET',
            url: 'assets/data/dp_full_routes.csv',
            async: false,
        }).done(function(data) {
            const rows = typeof parseCsvWithQuotes === 'function'
                ? parseCsvWithQuotes(data)
                : data.split('\n').map(line => line.split(','));

            if (!rows || rows.length < 2) {
                console.warn('[DP] dp_full_routes.csv is empty or invalid');
                return;
            }

            const header = rows[0].map(h => (h || '').toString().replace(/"/g, '').trim().toUpperCase());

            const idxEffDate = header.indexOf('EFF_DATE');
            const idxDpName = header.indexOf('DP_NAME');
            const idxDpCode = header.indexOf('DP_COMPUTER_CODE');
            const idxOrigGroup = header.indexOf('ORIG_GROUP');
            const idxTransCode = header.indexOf('TRANSITION_COMPUTER_CODE');
            const idxRoutePoints = header.indexOf('ROUTE_POINTS');

            if (idxDpCode < 0 || idxOrigGroup < 0 || idxRoutePoints < 0) {
                console.warn('[DP] dp_full_routes.csv missing required columns');
                return;
            }

            // Reset all indexes
            window.dpFullRoutesByTransition = {};
            window.dpFullRoutesByDpCode = {};
            window.dpByLeftCode = {};
            window.dpByRootName = {};
            window.dpByTransitionFix = {};
            window.dpPatternIndex = {};
            window.dpAllRootNames = new Set();

            const startTime = performance.now();

            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                if (!row || !row.length) {continue;}

                const dpCodeRaw = (row[idxDpCode] || '').toString().replace(/"/g, '').trim();
                if (!dpCodeRaw) {continue;}
                const dpCode = dpCodeRaw.toUpperCase();

                const effDate = idxEffDate >= 0 ? parseEffDate(row[idxEffDate]) : 0;
                const dpName = idxDpName >= 0 ? (row[idxDpName] || '').toString().replace(/"/g, '').trim().toUpperCase() : '';
                const origGroup = (row[idxOrigGroup] || '').toString().replace(/"/g, '').trim().toUpperCase();
                const transCodeRaw = idxTransCode >= 0 ? (row[idxTransCode] || '').toString().replace(/"/g, '').trim() : '';
                const transCode = transCodeRaw.toUpperCase();
                const routePtsRaw = (row[idxRoutePoints] || '').toString().replace(/"/g, '').trim().toUpperCase();
                const routePoints = routePtsRaw ? routePtsRaw.split(/\s+/).filter(Boolean) : [];

                const origins = extractAirportsFromGroup(origGroup);

                // Build the full route record
                const record = {
                    dpCode: dpCode,
                    dpName: dpName,
                    transCode: transCode,
                    effDate: effDate,
                    origins: origins,
                    origGroup: origGroup,
                    routePoints: routePoints,
                };

                // Index by DP_COMPUTER_CODE (e.g., KAYLN3.KAYLN)
                if (!window.dpFullRoutesByDpCode[dpCode]) {
                    window.dpFullRoutesByDpCode[dpCode] = [];
                }
                window.dpFullRoutesByDpCode[dpCode].push(record);

                // Index by TRANSITION_COMPUTER_CODE (e.g., KAYLN3.SMUUV)
                if (transCode) {
                    if (!window.dpFullRoutesByTransition[transCode]) {
                        window.dpFullRoutesByTransition[transCode] = [];
                    }
                    window.dpFullRoutesByTransition[transCode].push(record);

                    // Also index by transition fix alone (e.g., SMUUV -> [all DPs using SMUUV])
                    const transParts = transCode.split('.');
                    if (transParts.length === 2) {
                        const transFix = transParts[1];
                        if (!window.dpByTransitionFix[transFix]) {
                            window.dpByTransitionFix[transFix] = [];
                        }
                        window.dpByTransitionFix[transFix].push(record);
                    }
                }

                // Index by left code (e.g., KAYLN3)
                const dpParts = dpCode.split('.');
                if (dpParts.length >= 1) {
                    const leftCode = dpParts[0];
                    const existing = window.dpByLeftCode[leftCode];
                    if (!existing || effDate > existing.effDate) {
                        window.dpByLeftCode[leftCode] = {
                            code: dpCode,
                            leftCode: leftCode,
                            effDate: effDate,
                            origins: origins,
                            dpName: dpName,
                        };
                    }

                    // Index by root name (e.g., KAYLN)
                    const rootName = extractRootName(leftCode);
                    if (rootName) {
                        window.dpAllRootNames.add(rootName);
                        const existingRoot = window.dpByRootName[rootName];
                        if (!existingRoot || effDate > existingRoot.effDate) {
                            window.dpByRootName[rootName] = {
                                code: dpCode,
                                leftCode: leftCode,
                                rootName: rootName,
                                effDate: effDate,
                                origins: origins,
                                dpName: dpName,
                            };
                        }
                    }

                    // Build pattern index for wildcard matching (KAYLN#.SMUUV)
                    if (transCode) {
                        const transParts = transCode.split('.');
                        if (transParts.length === 2) {
                            const pattern = rootName + '#.' + transParts[1];
                            const existingPattern = window.dpPatternIndex[pattern];
                            if (!existingPattern || effDate > existingPattern.effDate) {
                                window.dpPatternIndex[pattern] = {
                                    code: transCode,
                                    dpCode: dpCode,
                                    effDate: effDate,
                                };
                            }
                        }
                    }
                }
            }

            window.dpDataLoaded = true;
            console.log('[DP] Loaded ' + Object.keys(window.dpFullRoutesByTransition).length +
                        ' transitions, ' + window.dpAllRootNames.size + ' root names in ' +
                        (performance.now() - startTime).toFixed(1) + 'ms');

        }).fail(function() {
            console.warn('[DP] dp_full_routes.csv not found');
            window.dpDataLoaded = false;
        });
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // STAR LOADING - loads star_full_routes.csv
    // ═══════════════════════════════════════════════════════════════════════════

    window.loadStarProceduresEnhanced = function() {
        $.ajax({
            type: 'GET',
            url: 'assets/data/star_full_routes.csv',
            async: false,
        }).done(function(data) {
            const rows = typeof parseCsvWithQuotes === 'function'
                ? parseCsvWithQuotes(data)
                : data.split('\n').map(line => line.split(','));

            if (!rows || rows.length < 2) {
                console.warn('[STAR] star_full_routes.csv is empty or invalid');
                return;
            }

            const header = rows[0].map(h => (h || '').toString().replace(/"/g, '').trim().toUpperCase());

            const idxEffDate = header.indexOf('EFF_DATE');
            const idxArrivalName = header.indexOf('ARRIVAL_NAME');
            const idxStarCode = header.indexOf('STAR_COMPUTER_CODE');
            const idxDestGroup = header.indexOf('DEST_GROUP');
            const idxTransCode = header.indexOf('TRANSITION_COMPUTER_CODE');
            const idxRoutePoints = header.indexOf('ROUTE_POINTS');

            if (idxStarCode < 0 || idxDestGroup < 0 || idxRoutePoints < 0) {
                console.warn('[STAR] star_full_routes.csv missing required columns');
                return;
            }

            // Reset all indexes
            window.starFullRoutesByTransition = {};
            window.starFullRoutesByStarCode = {};
            window.starByRightCode = {};
            window.starByRootName = {};
            window.starByTransitionFix = {};
            window.starPatternIndex = {};
            window.starAllRootNames = new Set();

            const startTime = performance.now();

            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                if (!row || !row.length) {continue;}

                const starCodeRaw = (row[idxStarCode] || '').toString().replace(/"/g, '').trim();
                if (!starCodeRaw) {continue;}
                const starCode = starCodeRaw.toUpperCase();

                const effDate = idxEffDate >= 0 ? parseEffDate(row[idxEffDate]) : 0;
                const arrivalName = idxArrivalName >= 0 ? (row[idxArrivalName] || '').toString().replace(/"/g, '').trim().toUpperCase() : '';
                const destGroup = (row[idxDestGroup] || '').toString().replace(/"/g, '').trim().toUpperCase();
                const transCodeRaw = idxTransCode >= 0 ? (row[idxTransCode] || '').toString().replace(/"/g, '').trim() : '';
                const transCode = transCodeRaw.toUpperCase();
                const routePtsRaw = (row[idxRoutePoints] || '').toString().replace(/"/g, '').trim().toUpperCase();
                const routePoints = routePtsRaw ? routePtsRaw.split(/\s+/).filter(Boolean) : [];

                const destinations = extractAirportsFromGroup(destGroup);

                // Build the full route record
                const record = {
                    starCode: starCode,
                    arrivalName: arrivalName,
                    transCode: transCode,
                    effDate: effDate,
                    destinations: destinations,
                    destGroup: destGroup,
                    routePoints: routePoints,
                };

                // Index by STAR_COMPUTER_CODE (e.g., WYNDE.WYNDE3)
                if (!window.starFullRoutesByStarCode[starCode]) {
                    window.starFullRoutesByStarCode[starCode] = [];
                }
                window.starFullRoutesByStarCode[starCode].push(record);

                // Index by TRANSITION_COMPUTER_CODE (e.g., SMUUV.WYNDE3)
                if (transCode) {
                    if (!window.starFullRoutesByTransition[transCode]) {
                        window.starFullRoutesByTransition[transCode] = [];
                    }
                    window.starFullRoutesByTransition[transCode].push(record);

                    // Also index by transition fix alone (e.g., SMUUV -> [all STARs using SMUUV])
                    const transParts = transCode.split('.');
                    if (transParts.length === 2) {
                        const transFix = transParts[0];
                        if (!window.starByTransitionFix[transFix]) {
                            window.starByTransitionFix[transFix] = [];
                        }
                        window.starByTransitionFix[transFix].push(record);
                    }
                }

                // Index by right code (e.g., WYNDE3)
                const starParts = starCode.split('.');
                if (starParts.length >= 2) {
                    const rightCode = starParts[1];
                    const existing = window.starByRightCode[rightCode];
                    if (!existing || effDate > existing.effDate) {
                        window.starByRightCode[rightCode] = {
                            code: starCode,
                            rightCode: rightCode,
                            effDate: effDate,
                            destinations: destinations,
                            arrivalName: arrivalName,
                        };
                    }

                    // Index by root name (e.g., WYNDE)
                    const rootName = extractRootName(rightCode);
                    if (rootName) {
                        window.starAllRootNames.add(rootName);
                        const existingRoot = window.starByRootName[rootName];
                        if (!existingRoot || effDate > existingRoot.effDate) {
                            window.starByRootName[rootName] = {
                                code: starCode,
                                rightCode: rightCode,
                                rootName: rootName,
                                effDate: effDate,
                                destinations: destinations,
                                arrivalName: arrivalName,
                            };
                        }
                    }

                    // Build pattern index for wildcard matching (SMUUV.WYNDE#)
                    if (transCode) {
                        const transParts = transCode.split('.');
                        if (transParts.length === 2) {
                            const pattern = transParts[0] + '.' + rootName + '#';
                            const existingPattern = window.starPatternIndex[pattern];
                            if (!existingPattern || effDate > existingPattern.effDate) {
                                window.starPatternIndex[pattern] = {
                                    code: transCode,
                                    starCode: starCode,
                                    effDate: effDate,
                                };
                            }
                        }
                    }
                }
            }

            window.starDataLoaded = true;
            console.log('[STAR] Loaded ' + Object.keys(window.starFullRoutesByTransition).length +
                        ' transitions, ' + window.starAllRootNames.size + ' root names in ' +
                        (performance.now() - startTime).toFixed(1) + 'ms');

        }).fail(function() {
            console.warn('[STAR] star_full_routes.csv not found');
            window.starDataLoaded = false;
        });
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // TOKEN PARSING - Detects and splits DP/STAR tokens
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Attempts to parse a token as a DP reference.
     * Returns: { type: 'dp', transCode: 'KAYLN3.SMUUV', record: {...} } or null
     *
     * Handles:
     * - KAYLN3.SMUUV (explicit transition)
     * - KAYLN3SMUUV (missing dot)
     * - KAYLN#.SMUUV (placeholder version)
     * - KAYLN2.SMUUV (wrong version -> upgrade to current)
     * - KAYLN3 (DP name only, needs next token for transition)
     */
    function parseDpToken(token, nextToken) {
        if (!token) {return null;}
        const upper = token.toUpperCase();

        // Case 1: Explicit transition code with dot (KAYLN3.SMUUV)
        if (upper.indexOf('.') !== -1) {
            const parts = upper.split('.');
            if (parts.length === 2) {
                const left = parts[0];
                const right = parts[1];

                // Check if this is a DP pattern (left has digits, right is a fix)
                if (looksLikeDpLeft(left) && isSimpleFix(right)) {
                    // Direct lookup
                    let records = window.dpFullRoutesByTransition[upper];
                    if (records && records.length) {
                        return { type: 'dp', transCode: upper, records: records };
                    }

                    // Try pattern matching for wrong version
                    const rootName = extractRootName(left);
                    const pattern = rootName + '#.' + right;
                    const patternMatch = window.dpPatternIndex[pattern];
                    if (patternMatch) {
                        records = window.dpFullRoutesByTransition[patternMatch.code];
                        if (records && records.length) {
                            return { type: 'dp', transCode: patternMatch.code, records: records, inferred: true };
                        }
                    }
                }
            }
        }

        // Case 2: Missing dot - try to detect boundary (KAYLN3SMUUV)
        // Look for pattern: [ROOT][VERSION][TRANSITION_FIX]
        if (!upper.includes('.') && /[0-9]/.test(upper) && upper.length >= 6) {
            // Try to find a known DP root at the start
            for (const rootName of window.dpAllRootNames) {
                if (upper.startsWith(rootName)) {
                    const remainder = upper.substring(rootName.length);
                    // Check if remainder starts with digit(s) followed by letters
                    const versionMatch = remainder.match(/^(\d+)([A-Z]{3,5})$/i);
                    if (versionMatch) {
                        const version = versionMatch[1];
                        const transFix = versionMatch[2].toUpperCase();
                        const transCode = rootName + version + '.' + transFix;

                        let records = window.dpFullRoutesByTransition[transCode];
                        if (records && records.length) {
                            return { type: 'dp', transCode: transCode, records: records, reconstructed: true };
                        }

                        // Try pattern match
                        const pattern = rootName + '#.' + transFix;
                        const patternMatch = window.dpPatternIndex[pattern];
                        if (patternMatch) {
                            records = window.dpFullRoutesByTransition[patternMatch.code];
                            if (records && records.length) {
                                return { type: 'dp', transCode: patternMatch.code, records: records, reconstructed: true, inferred: true };
                            }
                        }
                    }
                }
            }
        }

        // Case 3: DP name only (KAYLN3) - need next token for transition
        if (looksLikeDpLeft(upper) && nextToken) {
            const nextUpper = nextToken.toUpperCase();
            if (isSimpleFix(nextUpper)) {
                const transCode = upper + '.' + nextUpper;
                let records = window.dpFullRoutesByTransition[transCode];
                if (records && records.length) {
                    return { type: 'dp', transCode: transCode, records: records, consumeNext: true };
                }

                // Try pattern match
                const rootName = extractRootName(upper);
                const pattern = rootName + '#.' + nextUpper;
                const patternMatch = window.dpPatternIndex[pattern];
                if (patternMatch) {
                    records = window.dpFullRoutesByTransition[patternMatch.code];
                    if (records && records.length) {
                        return { type: 'dp', transCode: patternMatch.code, records: records, consumeNext: true, inferred: true };
                    }
                }
            }
        }

        // Case 4: Just DP left code without transition (KAYLN3)
        if (looksLikeDpLeft(upper)) {
            const dpInfo = window.dpByLeftCode[upper];
            if (dpInfo) {
                const records = window.dpFullRoutesByDpCode[dpInfo.code];
                if (records && records.length) {
                    return { type: 'dp_only', dpCode: dpInfo.code, records: records };
                }
            }

            // Try by root name
            const rootName = extractRootName(upper);
            const rootInfo = window.dpByRootName[rootName];
            if (rootInfo) {
                const records = window.dpFullRoutesByDpCode[rootInfo.code];
                if (records && records.length) {
                    return { type: 'dp_only', dpCode: rootInfo.code, records: records, inferred: true };
                }
            }
        }

        return null;
    }

    /**
     * Attempts to parse a token as a STAR reference.
     * Returns: { type: 'star', transCode: 'SMUUV.WYNDE3', record: {...} } or null
     *
     * Handles:
     * - SMUUV.WYNDE3 (explicit transition)
     * - SMUUVWYNDE3 (missing dot)
     * - SMUUV.WYNDE# (placeholder version)
     * - SMUUV.WYNDE2 (wrong version -> upgrade to current)
     * - WYNDE3 (STAR name only, needs previous token for transition)
     */
    function parseStarToken(token, prevToken) {
        if (!token) {return null;}
        const upper = token.toUpperCase();

        // Case 1: Explicit transition code with dot (SMUUV.WYNDE3)
        if (upper.indexOf('.') !== -1) {
            const parts = upper.split('.');
            if (parts.length === 2) {
                const left = parts[0];
                const right = parts[1];

                // Check if this is a STAR pattern (left is a fix, right has digits)
                if (isSimpleFix(left) && looksLikeStarRight(right)) {
                    // Direct lookup
                    let records = window.starFullRoutesByTransition[upper];
                    if (records && records.length) {
                        return { type: 'star', transCode: upper, records: records };
                    }

                    // Try pattern matching for wrong version
                    const rootName = extractRootName(right);
                    const pattern = left + '.' + rootName + '#';
                    const patternMatch = window.starPatternIndex[pattern];
                    if (patternMatch) {
                        records = window.starFullRoutesByTransition[patternMatch.code];
                        if (records && records.length) {
                            return { type: 'star', transCode: patternMatch.code, records: records, inferred: true };
                        }
                    }
                }
            }
        }

        // Case 2: Missing dot - try to detect boundary (SMUUVWYNDE3)
        if (!upper.includes('.') && /[0-9]/.test(upper) && upper.length >= 6) {
            // Try to find a known STAR root near the end
            for (const rootName of window.starAllRootNames) {
                // Look for pattern: [TRANSITION_FIX][ROOT][VERSION]
                const regex = new RegExp('^([A-Z]{3,5})(' + rootName + ')(\\d+)$', 'i');
                const match = upper.match(regex);
                if (match) {
                    const transFix = match[1].toUpperCase();
                    const version = match[3];
                    const transCode = transFix + '.' + rootName + version;

                    let records = window.starFullRoutesByTransition[transCode];
                    if (records && records.length) {
                        return { type: 'star', transCode: transCode, records: records, reconstructed: true };
                    }

                    // Try pattern match
                    const pattern = transFix + '.' + rootName + '#';
                    const patternMatch = window.starPatternIndex[pattern];
                    if (patternMatch) {
                        records = window.starFullRoutesByTransition[patternMatch.code];
                        if (records && records.length) {
                            return { type: 'star', transCode: patternMatch.code, records: records, reconstructed: true, inferred: true };
                        }
                    }
                }
            }
        }

        // Case 3: STAR name only (WYNDE3) - need previous token for transition
        if (looksLikeStarRight(upper) && prevToken) {
            const prevUpper = prevToken.toUpperCase();
            if (isSimpleFix(prevUpper)) {
                const transCode = prevUpper + '.' + upper;
                let records = window.starFullRoutesByTransition[transCode];
                if (records && records.length) {
                    return { type: 'star', transCode: transCode, records: records, consumePrev: true };
                }

                // Try pattern match
                const rootName = extractRootName(upper);
                const pattern = prevUpper + '.' + rootName + '#';
                const patternMatch = window.starPatternIndex[pattern];
                if (patternMatch) {
                    records = window.starFullRoutesByTransition[patternMatch.code];
                    if (records && records.length) {
                        return { type: 'star', transCode: patternMatch.code, records: records, consumePrev: true, inferred: true };
                    }
                }
            }
        }

        // Case 4: Just STAR right code without transition (WYNDE3)
        if (looksLikeStarRight(upper)) {
            const starInfo = window.starByRightCode[upper];
            if (starInfo) {
                const records = window.starFullRoutesByStarCode[starInfo.code];
                if (records && records.length) {
                    return { type: 'star_only', starCode: starInfo.code, records: records };
                }
            }

            // Try by root name
            const rootName = extractRootName(upper);
            const rootInfo = window.starByRootName[rootName];
            if (rootInfo) {
                const records = window.starFullRoutesByStarCode[rootInfo.code];
                if (records && records.length) {
                    return { type: 'star_only', starCode: rootInfo.code, records: records, inferred: true };
                }
            }
        }

        return null;
    }

    /**
     * Parse a combined DP.TRANSITION.STAR token like KAYLN3.SMUUV.WYNDE3
     * Returns: { dp: {...}, star: {...}, sharedFix: 'SMUUV' } or null
     */
    function parseCombinedToken(token) {
        if (!token) {return null;}
        const upper = token.toUpperCase();
        const parts = upper.split('.');

        if (parts.length !== 3) {return null;}

        const left = parts[0];   // KAYLN3
        const middle = parts[1]; // SMUUV (shared transition fix)
        const right = parts[2];  // WYNDE3

        // Validate pattern: DP_NAME# . FIX . STAR_NAME#
        if (!looksLikeDpLeft(left) || !isSimpleFix(middle) || !looksLikeStarRight(right)) {
            return null;
        }

        // Build DP and STAR transition codes
        const dpTransCode = left + '.' + middle;
        const starTransCode = middle + '.' + right;

        const dpResult = parseDpToken(dpTransCode, null);
        const starResult = parseStarToken(starTransCode, null);

        if (dpResult && starResult) {
            return {
                dp: dpResult,
                star: starResult,
                sharedFix: middle,
            };
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ROUTE PREPROCESSING - Normalizes and expands DP/STAR tokens
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Preprocess route tokens to:
     * 1. Split combined tokens (KAYLN3.SMUUV.WYNDE3)
     * 2. Detect and insert dots for concatenated tokens
     * 3. Infer origins/destinations from procedure served airports when missing
     *
     * @param {string[]} tokens - Array of route tokens
     * @returns {Object} { tokens: [...], origins: [...], destinations: [...], dpInfo: {...}, starInfo: {...} }
     */
    window.preprocessRouteProcedures = function(tokens) {
        if (!window.dpDataLoaded) {
            window.loadDepartureProceduresEnhanced();
        }
        if (!window.starDataLoaded) {
            window.loadStarProceduresEnhanced();
        }

        if (!tokens || !tokens.length) {
            return { tokens: [], origins: [], destinations: [], dpInfo: null, starInfo: null };
        }

        const result = [];
        let inferredOrigins = [];
        let inferredDestinations = [];
        let dpInfo = null;
        let starInfo = null;
        let i = 0;

        // First pass: identify explicit origin/destination
        let hasExplicitOrigin = false;
        let hasExplicitDest = false;

        if (tokens.length > 0) {
            const firstTok = normalizeAirport(tokens[0].toUpperCase());
            if (isAirportCode(firstTok) && typeof window.routePoints !== 'undefined' && window.routePoints[firstTok]) {
                hasExplicitOrigin = true;
            }
        }
        if (tokens.length > 1) {
            const lastTok = normalizeAirport(tokens[tokens.length - 1].toUpperCase());
            if (isAirportCode(lastTok) && typeof window.routePoints !== 'undefined' && window.routePoints[lastTok]) {
                hasExplicitDest = true;
            }
        }

        while (i < tokens.length) {
            const tok = tokens[i];
            const upper = tok.toUpperCase();
            const nextTok = i + 1 < tokens.length ? tokens[i + 1] : null;
            const prevTok = result.length > 0 ? result[result.length - 1] : null;

            // Check for combined DP.FIX.STAR token
            const combined = parseCombinedToken(upper);
            if (combined) {
                // Split into DP transition and STAR transition
                result.push(combined.dp.transCode);
                result.push(combined.star.transCode);
                dpInfo = combined.dp;
                starInfo = combined.star;

                // Infer origins from DP if not explicit
                if (!hasExplicitOrigin && combined.dp.records && combined.dp.records.length) {
                    const origins = new Set();
                    combined.dp.records.forEach(r => {
                        if (r.origins) {r.origins.forEach(o => origins.add(o));}
                    });
                    inferredOrigins = Array.from(origins);
                }

                // Infer destinations from STAR if not explicit
                if (!hasExplicitDest && combined.star.records && combined.star.records.length) {
                    const dests = new Set();
                    combined.star.records.forEach(r => {
                        if (r.destinations) {r.destinations.forEach(d => dests.add(d));}
                    });
                    inferredDestinations = Array.from(dests);
                }

                i++;
                continue;
            }

            // Check for DP token
            const dp = parseDpToken(upper, nextTok ? nextTok.toUpperCase() : null);
            if (dp && (dp.type === 'dp' || dp.type === 'dp_only')) {
                if (dp.consumeNext) {
                    // Token was DP_NAME, next was transition fix - combine them
                    result.push(dp.transCode);
                    i += 2; // Skip next token too
                } else {
                    result.push(dp.transCode || dp.dpCode);
                    i++;
                }
                dpInfo = dp;

                // Infer origins from DP if not explicit
                if (!hasExplicitOrigin && dp.records && dp.records.length) {
                    const origins = new Set();
                    dp.records.forEach(r => {
                        if (r.origins) {r.origins.forEach(o => origins.add(o));}
                    });
                    inferredOrigins = Array.from(origins);
                }
                continue;
            }

            // Check for STAR token
            const star = parseStarToken(upper, prevTok);
            if (star && (star.type === 'star' || star.type === 'star_only')) {
                if (star.consumePrev && result.length > 0) {
                    // Previous token was transition fix - replace it with full transition code
                    result[result.length - 1] = star.transCode;
                } else {
                    result.push(star.transCode || star.starCode);
                }
                starInfo = star;

                // Infer destinations from STAR if not explicit
                if (!hasExplicitDest && star.records && star.records.length) {
                    const dests = new Set();
                    star.records.forEach(r => {
                        if (r.destinations) {r.destinations.forEach(d => dests.add(d));}
                    });
                    inferredDestinations = Array.from(dests);
                }
                i++;
                continue;
            }

            // No special handling - keep original token
            result.push(upper);
            i++;
        }

        return {
            tokens: result,
            origins: inferredOrigins,
            destinations: inferredDestinations,
            dpInfo: dpInfo,
            starInfo: starInfo,
            hasExplicitOrigin: hasExplicitOrigin,
            hasExplicitDest: hasExplicitDest,
        };
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // EXPANSION FUNCTIONS - Expand DP/STAR tokens to waypoint sequences
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Get the best matching DP route for a given transition code and origin.
     */
    window.getDpRoutePoints = function(transCode, originAirport) {
        if (!transCode) {return null;}
        const upper = transCode.toUpperCase();
        const records = window.dpFullRoutesByTransition[upper];

        if (!records || !records.length) {
            // Try pattern matching
            const parts = upper.split('.');
            if (parts.length === 2) {
                const rootName = extractRootName(parts[0]);
                const pattern = rootName + '#.' + parts[1];
                const patternMatch = window.dpPatternIndex[pattern];
                if (patternMatch) {
                    return window.getDpRoutePoints(patternMatch.code, originAirport);
                }
            }
            return null;
        }

        // Filter by origin if specified
        let filtered = records;
        if (originAirport) {
            const originUpper = normalizeAirport(originAirport.toUpperCase());
            const originFiltered = records.filter(r => r.origins && r.origins.indexOf(originUpper) !== -1);
            if (originFiltered.length > 0) {
                filtered = originFiltered;
            }
        }

        // Sort by effDate descending and take most recent
        filtered.sort((a, b) => (b.effDate || 0) - (a.effDate || 0));
        const best = filtered[0];

        return best.routePoints ? best.routePoints.slice() : null;
    };

    /**
     * Get the best matching STAR route for a given transition code and destination.
     */
    window.getStarRoutePoints = function(transCode, destAirport) {
        if (!transCode) {return null;}
        const upper = transCode.toUpperCase();
        const records = window.starFullRoutesByTransition[upper];

        if (!records || !records.length) {
            // Try pattern matching
            const parts = upper.split('.');
            if (parts.length === 2) {
                const rootName = extractRootName(parts[1]);
                const pattern = parts[0] + '.' + rootName + '#';
                const patternMatch = window.starPatternIndex[pattern];
                if (patternMatch) {
                    return window.getStarRoutePoints(patternMatch.code, destAirport);
                }
            }
            return null;
        }

        // Filter by destination if specified
        let filtered = records;
        if (destAirport) {
            const destUpper = normalizeAirport(destAirport.toUpperCase());
            const destFiltered = records.filter(r => r.destinations && r.destinations.indexOf(destUpper) !== -1);
            if (destFiltered.length > 0) {
                filtered = destFiltered;
            }
        }

        // Sort by effDate descending and take most recent
        filtered.sort((a, b) => (b.effDate || 0) - (a.effDate || 0));
        const best = filtered[0];

        return best.routePoints ? best.routePoints.slice() : null;
    };

    /**
     * Expand DP/STAR tokens in a route to their full waypoint sequences.
     * Also handles fan-style drawing for inferred origins/destinations.
     *
     * @param {string[]} tokens - Preprocessed route tokens
     * @param {boolean[]} solidMask - Solid/dashed mask for each token
     * @param {Object} procInfo - Info from preprocessRouteProcedures
     * @returns {Object} { tokens: [...], solidMask: [...], fanRoutes: [...] }
     */
    window.expandRouteProcedures = function(tokens, solidMask, procInfo) {
        if (!tokens || !tokens.length) {
            return { tokens: [], solidMask: [], fanRoutes: [] };
        }

        procInfo = procInfo || {};
        const inSolid = solidMask && solidMask.length === tokens.length
            ? solidMask
            : new Array(tokens.length).fill(true);

        const outTokens = [];
        const outSolid = [];
        const fanRoutes = [];

        // Determine origin and destination
        let originAirport = null;
        let destAirport = null;

        // Check first token for origin
        if (tokens.length > 0) {
            const firstTok = normalizeAirport(tokens[0]);
            if (isAirportCode(firstTok)) {
                originAirport = firstTok;
            }
        }

        // Check last token for destination
        if (tokens.length > 1) {
            const lastTok = normalizeAirport(tokens[tokens.length - 1]);
            if (isAirportCode(lastTok)) {
                destAirport = lastTok;
            }
        }

        // Process each token
        for (let i = 0; i < tokens.length; i++) {
            const tok = tokens[i];
            const upper = tok.toUpperCase();
            const solid = inSolid[i];

            // Try to expand as DP transition
            const dpRoute = window.getDpRoutePoints(upper, originAirport);
            if (dpRoute && dpRoute.length) {
                for (const pt of dpRoute) {
                    outTokens.push(pt);
                    outSolid.push(solid);
                }
                continue;
            }

            // Try to expand as STAR transition
            const starRoute = window.getStarRoutePoints(upper, destAirport);
            if (starRoute && starRoute.length) {
                for (const pt of starRoute) {
                    outTokens.push(pt);
                    outSolid.push(solid);
                }
                continue;
            }

            // No expansion - keep original
            outTokens.push(upper);
            outSolid.push(solid);
        }

        // Handle inferred origins (fan-style)
        if (!procInfo.hasExplicitOrigin && procInfo.origins && procInfo.origins.length > 1) {
            // Find the first non-airport fix in the route
            let firstFix = null;
            for (const tok of outTokens) {
                if (!isAirportCode(tok)) {
                    firstFix = tok;
                    break;
                }
            }

            if (firstFix) {
                // Create fan routes from each inferred origin to the first fix
                for (const origin of procInfo.origins) {
                    fanRoutes.push({
                        type: 'origin_fan',
                        from: origin,
                        to: firstFix,
                        dashed: true,
                    });
                }
            }
        }

        // Handle inferred destinations (fan-style)
        if (!procInfo.hasExplicitDest && procInfo.destinations && procInfo.destinations.length > 1) {
            // Find the last non-airport fix in the route
            let lastFix = null;
            for (let j = outTokens.length - 1; j >= 0; j--) {
                if (!isAirportCode(outTokens[j])) {
                    lastFix = outTokens[j];
                    break;
                }
            }

            if (lastFix) {
                // Create fan routes from last fix to each inferred destination
                for (const dest of procInfo.destinations) {
                    fanRoutes.push({
                        type: 'dest_fan',
                        from: lastFix,
                        to: dest,
                        dashed: true,
                    });
                }
            }
        }

        return {
            tokens: outTokens,
            solidMask: outSolid,
            fanRoutes: fanRoutes,
        };
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // INTEGRATION - Replace existing functions
    // ═══════════════════════════════════════════════════════════════════════════

    // Make functions available globally for route.js integration
    window.parseDpToken = parseDpToken;
    window.parseStarToken = parseStarToken;
    window.parseCombinedToken = parseCombinedToken;
    window.extractRootName = extractRootName;
    window.isSimpleFix = isSimpleFix;
    window.looksLikeDpLeft = looksLikeDpLeft;
    window.looksLikeStarRight = looksLikeStarRight;

    // Debug helper: search for STARs by partial name
    window.debugSearchStars = function(search) {
        const upper = (search || '').toUpperCase();
        const results = [];

        for (const key of Object.keys(window.starFullRoutesByTransition || {})) {
            if (key.includes(upper)) {
                results.push(key);
            }
        }

        console.log('[STAR-DEBUG] Found', results.length, 'transitions matching "' + upper + '":', results.slice(0, 20));
        return results;
    };

    // Debug helper: check what patterns are indexed
    window.debugSearchStarPatterns = function(search) {
        const upper = (search || '').toUpperCase();
        const results = [];

        for (const key of Object.keys(window.starPatternIndex || {})) {
            if (key.includes(upper)) {
                results.push({ pattern: key, code: window.starPatternIndex[key].code });
            }
        }

        console.log('[STAR-DEBUG] Found', results.length, 'patterns matching "' + upper + '":', results.slice(0, 20));
        return results;
    };

    console.log('[PROCS] Enhanced DP/STAR handling module loaded');
})();
