/**
 * Route Advisory Parser — shared utility for parsing advisory ROUTES sections.
 *
 * Supports two formats:
 *   1. Standard:  ORIG / DEST / ROUTE  column layout
 *   2. Split:     FROM: section (origins + segments) + TO: section (dests + segments)
 *                 with automatic pivot-fix combination
 *
 * Usage:
 *   RouteAdvisoryParser.parse(text)
 *     → [{origin, origin_filter, dest, dest_filter, route_string}]
 *
 *   RouteAdvisoryParser.toRouteStrings(text)
 *     → ["KABC FIX1 J60 FIX2 KXYZ", ...]  (for route plotting)
 *
 *   RouteAdvisoryParser.isAdvisorySection(text)
 *     → boolean
 *
 * @global {Object} RouteAdvisoryParser
 */
(function(global) {
    'use strict';

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Detect whether text looks like an advisory ROUTES section.
     * Returns true if it contains an ORIG/DEST/ROUTE header or FROM:/TO: markers.
     */
    function isAdvisorySection(text) {
        if (!text) return false;
        var lines = text.split(/\r?\n/);
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var oi = line.indexOf('ORIG');
            var di = line.indexOf('DEST');
            var ri = line.indexOf('ROUTE');
            if (oi >= 0 && di > oi && ri > di) return true;
            if (/^\s*FROM:\s*$/i.test(line)) return true;
            if (/^\s*TO:\s*$/i.test(line)) return true;
        }
        return false;
    }

    /**
     * Parse advisory text into structured route objects.
     * @param {string} text - Advisory ROUTES section text
     * @returns {Array<{origin:string, origin_filter:string, dest:string, dest_filter:string, route_string:string}>}
     */
    function parse(text) {
        if (!text) return [];
        var lines = text.split('\n');

        // Detect format: split (FROM:/TO:) vs standard (ORIG/DEST/ROUTE)
        var hasFROM = false, hasTO = false;
        for (var i = 0; i < lines.length; i++) {
            if (/^\s*FROM:\s*$/i.test(lines[i])) hasFROM = true;
            if (/^\s*TO:\s*$/i.test(lines[i])) hasTO = true;
        }

        if (hasFROM && hasTO) return parseSplitAdvisory(lines);
        return parseStandardAdvisory(lines);
    }

    /**
     * Parse advisory text into simple route strings suitable for map plotting.
     * Prepends origin airport and appends dest airport when they look like ICAO codes.
     * @param {string} text - Advisory ROUTES section text
     * @returns {string[]}
     */
    function toRouteStrings(text) {
        var parsed = parse(text);
        var routeStrings = [];
        parsed.forEach(function(r) {
            var str = r.route_string;
            // Prepend origin if it looks like an ICAO airport (4 alpha chars, not UNKN)
            if (r.origin && /^[A-Z]{4}$/.test(r.origin) && r.origin !== 'UNKN') {
                str = r.origin + ' ' + str;
            }
            // Append dest if it looks like an ICAO airport (not UNKN)
            if (r.dest && /^[A-Z]{4}$/.test(r.dest) && r.dest !== 'UNKN') {
                str = str + ' ' + r.dest;
            }
            routeStrings.push(str);
        });
        return routeStrings;
    }

    // ── Standard Format Parser ──────────────────────────────────────────────

    /**
     * Parse standard format: ORIG / DEST / ROUTE columns
     */
    function parseStandardAdvisory(lines) {
        // Find header line with ORIG and DEST and ROUTE
        var origCol = -1, destCol = -1, routeCol = -1;
        var headerIdx = -1;
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var oi = line.indexOf('ORIG');
            var di = line.indexOf('DEST');
            var ri = line.indexOf('ROUTE');
            if (oi >= 0 && di > oi && ri > di) {
                origCol = oi; destCol = di; routeCol = ri;
                headerIdx = i;
                break;
            }
        }
        if (headerIdx < 0) return [];

        // Collect raw entries (join multi-line continuations)
        var entries = [];
        var current = null;
        for (var i = headerIdx + 1; i < lines.length; i++) {
            var line = lines[i];
            if (/^\s*-+(\s+-+)*\s*$/.test(line)) continue; // dash separator
            if (!line.trim()) continue;
            if (/^\s*(FROM|TO):\s*$/i.test(line)) break; // hit split section

            var origPart = line.substring(origCol, destCol).trimEnd();
            var destPart = line.substring(destCol, routeCol).trimEnd();
            var routePart = line.substring(routeCol).trimEnd();

            // New entry: has orig text AND route starts with > or UPT RTE:
            var isNew = origPart.trim() && /^\s*(>|UPT RTE:)/i.test(routePart);
            if (isNew) {
                if (current) entries.push(current);
                current = { origRaw: origPart.trim(), destRaw: destPart.trim(), routeRaw: routePart.trim() };
            } else if (current) {
                // Continuation line
                if (origPart.trim()) current.origRaw += ' ' + origPart.trim();
                if (destPart.trim()) current.destRaw += ' ' + destPart.trim();
                if (routePart.trim()) current.routeRaw += ' ' + routePart.trim();
            }
        }
        if (current) entries.push(current);

        // Expand each entry into route rows
        var results = [];
        entries.forEach(function(e) {
            // Skip UNKN/UPT RTE entries
            if (/^UPT RTE:/i.test(e.routeRaw)) return;

            var routeStr = cleanRouteString(e.routeRaw);
            if (!routeStr) return;

            var origins = parseOriginDestField(e.origRaw);
            var dests = parseOriginDestField(e.destRaw);

            // Cross-product: each origin × each dest
            if (!origins.length) origins = [{ code: '', filter: '' }];
            if (!dests.length) dests = [{ code: '', filter: '' }];

            origins.forEach(function(o) {
                dests.forEach(function(d) {
                    results.push({
                        origin: o.code,
                        origin_filter: o.filter,
                        dest: d.code,
                        dest_filter: d.filter,
                        route_string: routeStr
                    });
                });
            });
        });

        return results;
    }

    // ── Split Format Parser ─────────────────────────────────────────────────

    /**
     * Parse split format: FROM section (origins + segments) + TO section (dests + segments)
     * Auto-combines matching pivot fixes
     */
    function parseSplitAdvisory(lines) {
        // Also check for a standard ORIG/DEST/ROUTE header before FROM:
        var stdRoutes = [];
        var stdHeaderIdx = -1;
        for (var i = 0; i < lines.length; i++) {
            if (/^\s*FROM:\s*$/i.test(lines[i])) break;
            var line = lines[i];
            var oi = line.indexOf('ORIG');
            var di = line.indexOf('DEST');
            var ri = line.indexOf('ROUTE');
            if (oi >= 0 && di > oi && ri > di) {
                stdHeaderIdx = i;
                break;
            }
        }
        if (stdHeaderIdx >= 0) {
            // Parse the standard section before FROM:
            var stdLines = [];
            for (var i = 0; i < lines.length; i++) {
                if (/^\s*FROM:\s*$/i.test(lines[i])) break;
                stdLines.push(lines[i]);
            }
            stdRoutes = parseStandardAdvisory(stdLines);
        }

        // Find FROM and TO section boundaries
        var fromStart = -1, toStart = -1;
        for (var i = 0; i < lines.length; i++) {
            if (/^\s*FROM:\s*$/i.test(lines[i]) && fromStart < 0) fromStart = i;
            if (/^\s*TO:\s*$/i.test(lines[i])) toStart = i;
        }

        var fromEntries = fromStart >= 0 ? parseSplitSection(lines, fromStart, toStart >= 0 ? toStart : lines.length, 'from') : [];
        var toEntries = toStart >= 0 ? parseSplitSection(lines, toStart, lines.length, 'to') : [];

        // Auto-combine FROM + TO via pivot fix matching
        var results = stdRoutes.slice();
        var usedFrom = {};
        var usedTo = {};

        fromEntries.forEach(function(fe, fi) {
            var lastFix = getLastFix(fe.segment);
            if (!lastFix) return;

            toEntries.forEach(function(te, ti) {
                var firstFix = getFirstFix(te.segment);
                if (!firstFix) return;

                if (lastFix.toUpperCase() === firstFix.toUpperCase()) {
                    // Combine: FROM segment + TO segment (remove duplicate pivot from TO)
                    var toTokens = te.segment.trim().split(/\s+/);
                    toTokens.shift(); // remove first token (pivot fix)
                    var combined = fe.segment.trim() + ' ' + toTokens.join(' ');
                    var routeStr = cleanRouteString(combined);
                    if (!routeStr) return;

                    fe.origins.forEach(function(o) {
                        te.dests.forEach(function(d) {
                            results.push({
                                origin: o.code,
                                origin_filter: o.filter,
                                dest: d.code,
                                dest_filter: d.filter,
                                route_string: routeStr
                            });
                        });
                    });
                    usedFrom[fi] = true;
                    usedTo[ti] = true;
                }
            });
        });

        // Import unmatched FROM entries (origin only)
        fromEntries.forEach(function(fe, fi) {
            if (usedFrom[fi]) return;
            var routeStr = cleanRouteString(fe.segment);
            if (!routeStr) return;
            fe.origins.forEach(function(o) {
                results.push({
                    origin: o.code,
                    origin_filter: o.filter,
                    dest: '',
                    dest_filter: '',
                    route_string: routeStr
                });
            });
        });

        // Import unmatched TO entries (dest only)
        toEntries.forEach(function(te, ti) {
            if (usedTo[ti]) return;
            var routeStr = cleanRouteString(te.segment);
            if (!routeStr) return;
            te.dests.forEach(function(d) {
                results.push({
                    origin: '',
                    origin_filter: '',
                    dest: d.code,
                    dest_filter: d.filter,
                    route_string: routeStr
                });
            });
        });

        return results;
    }

    /**
     * Parse a FROM or TO section into entries with origins/dests + route segments
     */
    function parseSplitSection(lines, sectionStart, sectionEnd, type) {
        // Find column header (ORIG + ROUTE or DEST + ROUTE)
        var codeCol = -1, routeCol = -1;
        var headerIdx = -1;
        var codeLabel = (type === 'from') ? 'ORIG' : 'DEST';

        for (var i = sectionStart + 1; i < sectionEnd; i++) {
            var line = lines[i];
            var ci = line.indexOf(codeLabel);
            var ri = line.indexOf('ROUTE');
            if (ci >= 0 && ri > ci) {
                codeCol = ci; routeCol = ri;
                headerIdx = i;
                break;
            }
        }
        if (headerIdx < 0) return [];

        var entries = [];
        var current = null;

        for (var i = headerIdx + 1; i < sectionEnd; i++) {
            var line = lines[i];
            if (/^\s*-+(\s+-+)*\s*$/.test(line)) continue;
            if (!line.trim()) continue;
            if (/^\s*(FROM|TO):\s*$/i.test(line)) break;

            var codePart = line.substring(codeCol, routeCol).trimEnd();
            var routePart = line.substring(routeCol).trimEnd();

            // New entry detection for FROM: code text AND route starts with >
            // For TO: code text AND route text present (TO routes don't have >)
            var isNew;
            if (type === 'from') {
                isNew = codePart.trim() && /^\s*>/.test(routePart);
            } else {
                isNew = codePart.trim() && routePart.trim();
            }

            if (isNew) {
                if (current) entries.push(current);
                current = { codeRaw: codePart.trim(), segment: routePart.trim() };
            } else if (current) {
                if (codePart.trim()) current.codeRaw += ' ' + codePart.trim();
                if (routePart.trim()) current.segment += ' ' + routePart.trim();
            }
        }
        if (current) entries.push(current);

        // Parse code fields into origin/dest arrays
        return entries.map(function(e) {
            var parsed = parseOriginDestField(e.codeRaw);
            if (type === 'from') {
                return { origins: parsed, dests: [], segment: e.segment };
            } else {
                return { origins: [], dests: parsed, segment: e.segment };
            }
        });
    }

    // ── Utilities ────────────────────────────────────────────────────────────

    /**
     * Parse an origin/dest field like "ZLA(-LAS -SAN)", "KEWR KJFK KLGA",
     * or "FIR:EB..,ED..,EP..,LO.." into array of {code, filter}
     */
    function parseOriginDestField(raw) {
        if (!raw || raw === 'UNKN') return [];
        var str = raw.replace(/\s+/g, ' ').trim();

        // Handle FIR: pattern — expand using FacilityHierarchy if available
        // Single dot OK (e.g. LIC. = 3-letter prefix), two dots also OK (e.g. LS.. = 2-letter prefix)
        var firMatch = str.match(/^FIR:([A-Z0-9]{1,4}\.+(?:,[A-Z0-9]{1,4}\.+)*)$/i);
        if (firMatch) {
            if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.expandFirPattern) {
                var expanded = [];
                firMatch[1].split(',').forEach(function(pat) {
                    pat = pat.trim();
                    if (!pat) return;
                    var codes = FacilityHierarchy.expandFirPattern('FIR:' + pat);
                    expanded = expanded.concat(codes);
                });
                // Deduplicate
                var seen = {};
                return expanded.filter(function(c) {
                    if (seen[c]) return false;
                    seen[c] = true;
                    return true;
                }).map(function(c) { return { code: c, filter: '' }; });
            }
            // Fallback: return the raw pattern as a single entry
            return [{ code: str, filter: '' }];
        }

        var results = [];
        var re = /([A-Z][A-Z0-9]{1,4})(\([^)]*\))?/g;
        var m;
        while ((m = re.exec(str)) !== null) {
            var code = m[1];
            var filterRaw = m[2] || '';
            var filter = filterRaw.replace(/^\(/, '').replace(/\)$/, '').trim();
            results.push({ code: code, filter: filter });
        }
        return results;
    }

    /**
     * Clean route string: strip > < markers, normalize whitespace
     */
    function cleanRouteString(raw) {
        return raw
            .replace(/>/g, '')
            .replace(/<\s*/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    /**
     * Get the last waypoint fix from a route segment
     */
    function getLastFix(segment) {
        var clean = segment.replace(/[><]/g, '').trim();
        var tokens = clean.split(/\s+/);
        for (var i = tokens.length - 1; i >= 0; i--) {
            if (/^[A-Z][A-Z0-9]{1,4}[0-9]?$/.test(tokens[i])) return tokens[i];
        }
        return tokens[tokens.length - 1] || '';
    }

    /**
     * Get the first waypoint fix from a route segment
     */
    function getFirstFix(segment) {
        var clean = segment.replace(/[><]/g, '').trim();
        var tokens = clean.split(/\s+/);
        return tokens[0] || '';
    }

    // ── Export ───────────────────────────────────────────────────────────────

    global.RouteAdvisoryParser = {
        isAdvisorySection: isAdvisorySection,
        parse: parse,
        toRouteStrings: toRouteStrings
    };

})(window);
