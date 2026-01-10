// assets/js/gdt.js
// Ground Delay Tool - Ground Stop builder and VATSIM previewer with Tier-based scope and airport coloring
// Tier/group data is loaded at runtime from assets/data/TierInfo.csv

(function() {
    "use strict";

    // Runtime Tier structures
    let TMI_TIER_INFO = [];
    let TMI_UNIQUE_FACILITIES = [];
    let TMI_TIER_INFO_BY_CODE = {};

    let AIRPORT_CENTER_MAP = {};
    let CENTER_AIRPORTS_MAP = {};
    let AIRPORT_TRACON_MAP = {};
    let TRACON_AIRPORTS_MAP = {};
    let AIRPORT_IATA_MAP = {};

    let GS_ADL = null;
    let GS_ADL_LOADING = false;
    let GS_ADL_PROMISE = null;

        

    let GS_VATSIM = null;
    let GS_VATSIM_LOADING = false;
    let GS_VATSIM_PROMISE = null;
    let GS_FLIGHT_ROW_INDEX = {};

const GS_ADL_API_URL = "api/adl/current.php";

    // GS workflow API endpoints (new normalized API)
    const GS_API = {
        list: "api/tmi/gs/list.php",
        create: "api/tmi/gs/create.php",
        model: "api/tmi/gs/model.php",
        activate: "api/tmi/gs/activate.php",
        extend: "api/tmi/gs/extend.php",
        purge: "api/tmi/gs/purge.php",
        get: "api/tmi/gs/get.php",
        flights: "api/tmi/gs/flights.php",
        demand: "api/tmi/gs/demand.php"
    };

    // Legacy GS workflow API endpoints (kept for backward compatibility)
    const GS_WORKFLOW_API = {
        preview: "api/tmi/gs_preview.php",
        simulate: "api/tmi/gs_simulate.php",
        apply: "api/tmi/gs_apply.php",
        purgeLocal: "api/tmi/gs_purge_local.php",
        purgeAll: "api/tmi/gs_purge_all.php"
    };

    // Current GS program state
    let GS_CURRENT_PROGRAM_ID = null;
    let GS_CURRENT_PROGRAM_STATUS = null;

    // Which dataset the flight table is currently showing
    // LIVE = dbo.adl_flights, GS = dbo.adl_flights_gs (local sandbox)
    let GS_TABLE_MODE = "LIVE";

    // Track whether a simulation has been run (required before Send Actual)
    let GS_SIMULATION_READY = false;


    let GS_SIMTRAFFIC_CACHE = {};
    let GS_SIMTRAFFIC_QUEUE = [];
    let GS_SIMTRAFFIC_BUSY = false;
    let GS_SIMTRAFFIC_ENABLED = true;
    let GS_SIMTRAFFIC_ERROR_COUNT = 0;
    const GS_SIMTRAFFIC_ERROR_CUTOFF = 8;
    let GS_BTS_AVG_TIMES = null;

    const AIRLINE_CODE_MAP = {
        "AAL": "AA", "AA": "AA",
        "DAL": "DL", "DL": "DL",
        "UAL": "UA", "UA": "UA",
        "SWA": "WN", "WN": "WN",
        "JBU": "B6", "B6": "B6",
        "ASA": "AS", "AS": "AS",
        "AAY": "G4", "G4": "G4",
        "FFT": "F9", "F9": "F9",
        "NKS": "NK", "NK": "NK",
        "ENY": "MQ", "MQ": "MQ",
        "ASQ": "EV", "EV": "EV",
        "EDV": "9E", "9E": "9E",
        "SKW": "OO", "OO": "OO",
        "ASH": "YV", "YV": "YV",
        "RPA": "YX", "YX": "YX",
        "HAL": "HA", "HA": "HA",
        "FDX": "FX", "FX": "FX",
        "UPS": "5X", "5X": "5X"
    };

    function deriveBtsCarrierFromCallsign(callsign) {
        if (!callsign) return null;
        var cs = String(callsign).toUpperCase();
        var m = cs.match(/^[A-Z]+/);
        if (!m || !m[0]) return null;
        var letters = m[0];

        if (letters.length >= 3) {
            var p3 = letters.slice(0, 3);
            if (Object.prototype.hasOwnProperty.call(AIRLINE_CODE_MAP, p3)) {
                return AIRLINE_CODE_MAP[p3];
            }
        }
        if (letters.length >= 2) {
            var p2 = letters.slice(0, 2);
            if (Object.prototype.hasOwnProperty.call(AIRLINE_CODE_MAP, p2)) {
                return AIRLINE_CODE_MAP[p2];
            }
        }
        return null;
    }

    function loadBtsStats() {
        return fetch("assets/data/T_T100D_SEGMENT_US_CARRIER_ONLY.csv", { cache: "no-cache" })
            .then(function(res) { return res.text(); })
            .then(function(text) {
                GS_BTS_AVG_TIMES = {};
                if (!text) return;

                var lines = text.replace(/\r/g, "").split("\n").filter(function(l) { return l.trim().length > 0; });
                if (!lines.length) return;

                var header = lines[0].split(",");
                function idx(name) {
                    for (var i = 0; i < header.length; i++) {
                        var col = header[i] ? header[i].replace(/^\uFEFF/, "") : "";
                        if (col === name) return i;
                    }
                    return -1;
                }

                var idxCarrier = idx("CARRIER");
                var idxOrigin = idx("ORIGIN");
                var idxDest = idx("DEST");
                var idxAir = idx("AIR_TIME");

                if (idxCarrier === -1 || idxOrigin === -1 || idxDest === -1 || idxAir === -1) {
                    console.warn("BTS T100 header missing expected columns");
                    return;
                }

                var accum = {};

                for (var i = 1; i < lines.length; i++) {
                    var line = lines[i];
                    if (!line.trim()) continue;
                    var parts = line.split(",");

                    function get(idx) {
                        return (idx >= 0 && idx < parts.length ? parts[idx].trim().toUpperCase() : "");
                    }

                    var carrier = get(idxCarrier);
                    var origin = get(idxOrigin);
                    var dest = get(idxDest);
                    if (!carrier || !origin || !dest) continue;

                    var airStr = (idxAir >= 0 && idxAir < parts.length ? parts[idxAir].trim() : "");
                    var air = parseFloat(airStr);
                    if (!isFinite(air) || air <= 0) continue;

                    var key = carrier + "_" + origin + "_" + dest;
                    var agg = accum[key];
                    if (!agg) {
                        agg = { sum: 0, count: 0 };
                        accum[key] = agg;
                    }
                    agg.sum += air;
                    agg.count += 1;
                }

                var map = {};
                Object.keys(accum).forEach(function(key) {
                    var agg = accum[key];
                    if (agg.count > 0) {
                        map[key] = agg.sum / agg.count;
                    }
                });
                GS_BTS_AVG_TIMES = map;
                console.log("Loaded BTS T100 segment averages:", Object.keys(GS_BTS_AVG_TIMES).length, "ODC entries");
            })
            .catch(function(err) {
                console.error("Error loading BTS T100 segment data", err);
            });
    }

    const AIRPORT_COLOR_PALETTE = [
        "#e63946",  // Vibrant red
        "#2563eb",  // Vibrant blue
        "#16a34a",  // Vibrant green
        "#ca8a04",  // Golden yellow - darker for readability
        "#ea580c",  // Vibrant orange
        "#7c3aed",  // Vibrant purple
        "#0891b2",  // Cyan/teal
        "#db2777",  // Vibrant pink
        "#059669",  // Emerald green
        "#be123c"   // Rose red
    ];

    function getValue(id) {
        var el = document.getElementById(id);
        return el ? el.value.trim() : "";
    }

    function parseUtcLocalInput(dtStr) {
        // Treat datetime-local value as UTC without timezone conversion
        // Expect: YYYY-MM-DDTHH:MM or YYYY-MM-DDTHH:MM:SS
        if (!dtStr) return null;
        var parts = dtStr.split("T");
        if (parts.length !== 2) return null;
        var d = parts[0].split("-");
        var t = parts[1].split(":");
        if (d.length !== 3 || t.length < 2) return null;
        return {
            year: d[0],
            month: d[1],
            day: d[2],
            hour: t[0],
            minute: t[1]
        };
    }

    function parseUtcLocalToEpoch(dtStr) {
        var p = parseUtcLocalInput(dtStr);
        if (!p) return null;
        var year = parseInt(p.year, 10);
        var month = parseInt(p.month, 10);
        var day = parseInt(p.day, 10);
        var hour = parseInt(p.hour, 10);
        var minute = parseInt(p.minute, 10);
        if (isNaN(year) || isNaN(month) || isNaN(day) ||
            isNaN(hour) || isNaN(minute)) {
            return null;
        }
        // Treat as UTC calendar time
        return Date.UTC(year, month - 1, day, hour, minute, 0);
    }

    function formatZuluFromLocal(dtStr) {
        var p = parseUtcLocalInput(dtStr);
        if (!p) return "DD/HHMMZ";
        var dd = p.day;
        var hh = p.hour;
        var mm = p.minute;
        return dd + "/" + hh + mm + "Z";
    }

    function formatDdHhMmFromLocal(dtStr) {
        var p = parseUtcLocalInput(dtStr);
        if (!p) return "DDHHMM";
        var dd = p.day;
        var hh = p.hour;
        var mm = p.minute;
        return dd + hh + mm;
    }

    function formatZuluFromEpoch(epochMs) {
        if (epochMs == null || isNaN(epochMs)) return "";
        var d = new Date(epochMs);
        var dd = String(d.getUTCDate()).padStart(2, "0");
        var hh = String(d.getUTCHours()).padStart(2, "0");
        var mm = String(d.getUTCMinutes()).padStart(2, "0");
        return dd + "/" + hh + mm + "Z";
    }
    function formatSqlUtcFromEpoch(epochMs) {
        if (epochMs == null || isNaN(epochMs)) return "";
        var d = new Date(epochMs);
        var yyyy = d.getUTCFullYear();
        var mm = String(d.getUTCMonth() + 1).padStart(2, "0");
        var dd = String(d.getUTCDate()).padStart(2, "0");
        var hh = String(d.getUTCHours()).padStart(2, "0");
        var mi = String(d.getUTCMinutes()).padStart(2, "0");
        var ss = String(d.getUTCSeconds()).padStart(2, "0");
        return yyyy + "-" + mm + "-" + dd + " " + hh + ":" + mi + ":" + ss;
    }

    function getAdlSnapshotDisplayText() {
        if (!GS_ADL || !(GS_ADL.snapshotUtc instanceof Date) || isNaN(GS_ADL.snapshotUtc.getTime())) {
            return "";
        }
        var d = GS_ADL.snapshotUtc;
        var yyyy = d.getUTCFullYear();
        var mm = String(d.getUTCMonth() + 1).padStart(2, "0");
        var dd = String(d.getUTCDate()).padStart(2, "0");
        var hh = String(d.getUTCHours()).padStart(2, "0");
        var mi = String(d.getUTCMinutes()).padStart(2, "0");
        return mm + "/" + dd + "/" + yyyy + " " + hh + mi + "Z";
    }



    function parseSimtrafficTimeToEpoch(ts) {
        if (!ts) return null;
        ts = String(ts).trim();
        if (!ts) return null;
    
        // Normalise ISO "YYYY-MM-DDTHH:MM:SSZ" -> "YYYY-MM-DD HH:MM:SS"
        ts = ts.replace("T", " ").replace("Z", "").replace("z", "");
    
        // Now expect "YYYY-MM-DD HH:MM:SS" or "YYYY-MM-DD HH:MM"
        var parts = ts.split(" ");
        if (parts.length < 2) return null;
        var d = parts[0].split("-");
        var t = parts[1].split(":");
        if (d.length !== 3 || t.length < 2) return null;
    
        var year   = parseInt(d[0], 10);
        var month  = parseInt(d[1], 10);
        var day    = parseInt(d[2], 10);
        var hour   = parseInt(t[0], 10);
        var minute = parseInt(t[1], 10);
        var second = t.length >= 3 ? parseInt(t[2], 10) : 0;
    
        if (isNaN(year) || isNaN(month) || isNaN(day) ||
            isNaN(hour) || isNaN(minute) || isNaN(second)) {
            return null;
        }
    
        // Times are in UTC
        return Date.UTC(year, month - 1, day, hour, minute, second);
    }
    

    function makeRowIdForCallsign(cs) {
        if (!cs) return "";
        return "gs_row_" + String(cs).toUpperCase().replace(/[^A-Z0-9]/g, "_");
    }

    

    function parseVatsimDepartureTimeToEpoch(depTimeField) {
        if (!depTimeField) return null;
        var s = String(depTimeField).trim();
        if (!s) return null;

        var hh, mm;

        function pickBaseDate() {
    var base = null;
    try {
        if (typeof GS_ADL === "object" && GS_ADL) {
            // Prefer ADL snapshot time if provided
            if (GS_ADL.snapshotUtc instanceof Date && !isNaN(GS_ADL.snapshotUtc.getTime())) {
                base = GS_ADL.snapshotUtc;
            } else if (GS_ADL.snapshot_utc) {
                var tmpAdl = new Date(GS_ADL.snapshot_utc);
                if (!isNaN(tmpAdl.getTime())) base = tmpAdl;
            }
        }
        // If no ADL snapshot, fall back to VATSIM general update timestamp if available
        if ((!base || isNaN(base.getTime())) && typeof GS_VATSIM === "object" && GS_VATSIM && GS_VATSIM.general && GS_VATSIM.general.update_timestamp) {
            var tmpVs = new Date(GS_VATSIM.general.update_timestamp);
            if (!isNaN(tmpVs.getTime())) base = tmpVs;
        }
    } catch (e) {
        base = null;
    }
    if (!base || isNaN(base.getTime())) {
        base = new Date(); // fallback: now (UTC)
    }
    return base;
}


        // Case 1: numeric HHMM or HMM (e.g. "2345", "945")
        if (/^\d{3,4}$/.test(s)) {
            var len = s.length;
            hh = parseInt(s.slice(0, len - 2), 10);
            mm = parseInt(s.slice(len - 2), 10);
            if (isNaN(hh) || isNaN(mm)) return null;

            var base = pickBaseDate();
            return Date.UTC(
                base.getUTCFullYear(),
                base.getUTCMonth(),
                base.getUTCDate(),
                hh,
                mm,
                0
            );
        }

        // Case 2: "HH:MM"
        var parts = s.split(":");
        if (parts.length === 2) {
            hh = parseInt(parts[0], 10);
            mm = parseInt(parts[1], 10);
            if (isNaN(hh) || isNaN(mm)) return null;

            var base2 = pickBaseDate();
            return Date.UTC(
                base2.getUTCFullYear(),
                base2.getUTCMonth(),
                base2.getUTCDate(),
                hh,
                mm,
                0
            );
        }

        // Case 3: already a date-time string
        var d = new Date(s);
        if (isNaN(d.getTime())) return null;
        return d.getTime();
    }

function parseVatsimEnrouteToMinutes(enroute) {
        if (!enroute) return null;
        var s = String(enroute).trim();
        if (!s) return null;

        var hh, mm;
        // Case 1: numeric HHMM or HMM (e.g. "0120", "945")
        if (/^\d{3,4}$/.test(s)) {
            var len = s.length;
            hh = parseInt(s.slice(0, len - 2), 10);
            mm = parseInt(s.slice(len - 2), 10);
        } else {
            var parts = s.split(":");
            if (parts.length < 2) return null;
            hh = parseInt(parts[0], 10);
            mm = parseInt(parts[1], 10);
        }

        if (isNaN(hh) || isNaN(mm)) return null;
        return hh * 60 + mm;
    }


    function lookupBtsAvgFlightMinutes(depIcao, arrIcao, callsign) {
        if (!GS_BTS_AVG_TIMES) return null;
        var dep = (depIcao || "").toUpperCase();
        var arr = (arrIcao || "").toUpperCase();
        if (!dep || !arr) return null;

        var carrier = deriveBtsCarrierFromCallsign(callsign);
        if (!carrier) return null;

        var oIata = AIRPORT_IATA_MAP[dep] || "";
        var dIata = AIRPORT_IATA_MAP[arr] || "";
        if (!oIata || !dIata) return null;

        var key = carrier + "_" + oIata + "_" + dIata;
        var v = GS_BTS_AVG_TIMES[key];
        if (typeof v === "number" && !isNaN(v) && v > 0) {
            return v;
        }
        return null;
    }

    function escapeHtml(str) {
        if (!str) return "";
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

function wrapAdvisoryLabelValue(label, value) {
    var maxWidth = 68;
    label = (label || "").toString().trim();
    var lines = [];

    if (value == null) {
        value = "";
    }
    value = value.toString().trim();

    // If no value, just return the label as a single line (if present)
    if (!value) {
        if (label) {
            lines.push(label);
        }
        return lines;
    }

    var words = value.split(/\s+/);
    var indent = label ? (label.length + 1) : 0; // +1 for the space after the colon-style label
    var current = label ? (label + " ") : "";

    words.forEach(function(word) {
        if (!word) return;
        if (!current) {
            // New line starting with hanging indent
            current = (indent > 0 ? " ".repeat(indent) : "") + word;
            return;
        }
        var tentative = current + (current.endsWith(" ") ? "" : " ") + word;
        if (tentative.length > maxWidth && current.length > 0) {
            lines.push(current);
            current = (indent > 0 ? " ".repeat(indent) : "") + word;
        } else {
            current = tentative;
        }
    });

    if (current) {
        lines.push(current);
    }
    return lines;
}

    
function buildAdvisory() {
    var advNum = getValue("gs_adv_number") || "XXX";
    var elemTypeEl = document.getElementById("gs_element_type");
    var elemType = elemTypeEl ? elemTypeEl.value : "APT";
    var airportsRaw = getValue("gs_airports") || "";
    var depFacilities = getValue("gs_dep_facilities") || "ALL";
    var carriers = getValue("gs_flt_incl_carrier");
    var acTypeEl = document.getElementById("gs_flt_incl_type");
    var acType = acTypeEl ? acTypeEl.value : "ALL";
    var probExt = getValue("gs_prob_ext") || "";
    var impacting = getValue("gs_impacting_condition") || "";
    var comments = getValue("gs_comments") || "";

    var startEl = document.getElementById("gs_start");
    var endEl = document.getElementById("gs_end");
    var start = startEl ? startEl.value : "";
    var end = endEl ? endEl.value : "";

    // Keep the time-filter window aligned with the GS period by default
    syncTimeFiltersWithGsPeriod();

    var now = new Date();
    var nowZ = String(now.getUTCHours()).padStart(2, "0") +
               String(now.getUTCMinutes()).padStart(2, "0") + "Z";

    var gsPeriod = formatZuluFromLocal(start) + " – " + formatZuluFromLocal(end);

    // Parse airports and convert ICAO to FAA codes
    var airportTokens = (airportsRaw || "").toUpperCase().split(/[\s,]+/).filter(function(t) { return t.length > 0; });
    var faaAirports = airportTokens.map(function(icao) {
        // Convert ICAO to FAA (strip leading K for US airports, use AIRPORT_IATA_MAP if available)
        if (AIRPORT_IATA_MAP && AIRPORT_IATA_MAP[icao]) {
            return AIRPORT_IATA_MAP[icao];
        }
        // Fallback: strip leading K for US airports
        if (icao.length === 4 && icao.charAt(0) === 'K') {
            return icao.substring(1);
        }
        return icao;
    });

    // Get responsible ARTCCs for the airports
    var responsibleCenters = [];
    var centerSet = {};
    airportTokens.forEach(function(icao) {
        var center = AIRPORT_CENTER_MAP ? AIRPORT_CENTER_MAP[icao] : null;
        if (center && !centerSet[center]) {
            centerSet[center] = true;
            responsibleCenters.push(center);
        }
    });

    // Build CTL Element: just {FAA airports} (no responsible ARTCCs)
    // If user has manually entered a value, use that; otherwise compute from airports
    var ctlElement = getValue("gs_ctl_element");
    if (!ctlElement && faaAirports.length > 0) {
        ctlElement = faaAirports.join("/");
    }
    ctlElement = ctlElement || "XXX";

    // Note: We don't auto-update the CTL Element textbox to avoid issues with
    // partial input on keyup. The computed value is used for the advisory preview.
    // User can manually fill in the CTL Element field if they want to override.

    // Determine scope tier name from selected options
    var scopeTierName = "";
    var scopeSel = document.getElementById("gs_scope_select");
    if (scopeSel && scopeSel.selectedOptions && scopeSel.selectedOptions.length) {
        var selectedNames = [];
        Array.prototype.forEach.call(scopeSel.selectedOptions, function(opt) {
            if (opt && opt.dataset) {
                var type = opt.dataset.type;
                var val = opt.value;
                if (type === "tier" || type === "special") {
                    // Use the tier code as the name (e.g., "1stTier", "All+Canada")
                    selectedNames.push(val);
                } else if (type === "fac") {
                    selectedNames.push(val);
                } else if (type === "manual") {
                    selectedNames.push("Manual");
                }
            }
        });
        if (selectedNames.length > 0) {
            scopeTierName = selectedNames.join("+");
        }
    }

    // Build DEP FACILITIES line with scope tier prefix
    var depFacilitiesValue = depFacilities;
    if (scopeTierName) {
        depFacilitiesValue = "(" + scopeTierName + ") " + depFacilities;
    }

    // Flight-inclusion lines
    var fltInclValues = [];
    if (carriers) {
        fltInclValues.push("CARRIERS " + carriers.toUpperCase());
    }
    if (acType && acType !== "ALL") {
        fltInclValues.push(acType + " DEP ONLY");
    }

    // Delay statistics from the current table / time filter
    function readDelaySpan(id) {
        var el = document.getElementById(id);
        return el ? (el.textContent || "").trim() : "";
    }
    var delayTotal = readDelaySpan("gs_delay_total") || "0";
    var delayMax = readDelaySpan("gs_delay_max") || "0";
    var delayAvg = readDelaySpan("gs_delay_avg") || "0";

    // Valid period line (ddhhmm-ddhhmm format, no Z)
    var validStart = formatDdHhMm(start);
    var validEnd = formatDdHhMm(end);
    var validPeriod = validStart + "-" + validEnd;

    // Header date (MM/DD/YYYY based on publish time = now)
    var headerMonth = String(now.getUTCMonth() + 1).padStart(2, "0");
    var headerDay = String(now.getUTCDate()).padStart(2, "0");
    var headerYear = String(now.getUTCFullYear());
    var headerDate = headerMonth + "/" + headerDay + "/" + headerYear;

    // Signature timestamp
    var yy = String(now.getUTCFullYear()).slice(-2);
    var mm = String(now.getUTCMonth() + 1).padStart(2, "0");
    var dd = String(now.getUTCDate()).padStart(2, "0");
    var hh = String(now.getUTCHours()).padStart(2, "0");
    var min = String(now.getUTCMinutes()).padStart(2, "0");
    var signatureLine = yy + "/" + mm + "/" + dd + " " + hh + ":" + min;

    var lines = [];

    // Header: vATCSCC ADVZY {ADVZY_NUM} {CTL_ELEMENT} MM/DD/YYYY CDM GROUND STOP
    lines.push("vATCSCC ADVZY " + advNum + " " + ctlElement + " " + headerDate + " CDM GROUND STOP");

    // Standard lines with hanging-indent wrapping at 68 characters
    lines = lines.concat(wrapAdvisoryLabelValue("CTL ELEMENT:", ctlElement));
    lines = lines.concat(wrapAdvisoryLabelValue("ELEMENT TYPE:", elemType));
    lines = lines.concat(wrapAdvisoryLabelValue("ADL TIME:", nowZ));
    lines = lines.concat(wrapAdvisoryLabelValue("GROUND STOP PERIOD:", gsPeriod));

    fltInclValues.forEach(function(v) {
        lines = lines.concat(wrapAdvisoryLabelValue("FLT INCL:", v));
    });

    lines = lines.concat(
        wrapAdvisoryLabelValue("DEP FACILITIES INCLUDED:", depFacilitiesValue)
    );

    // Delay line
    lines = lines.concat(
        wrapAdvisoryLabelValue(
            "NEW TOTAL, MAXIMUM, AVERAGE DELAYS:",
            delayTotal + " / " + delayMax + " / " + delayAvg
        )
    );

    // Always show these lines (blank if empty)
    lines = lines.concat(
        wrapAdvisoryLabelValue("PROBABILITY OF EXTENSION:", probExt)
    );
    lines = lines.concat(
        wrapAdvisoryLabelValue("IMPACTING CONDITION:", impacting)
    );
    lines = lines.concat(
        wrapAdvisoryLabelValue("COMMENTS:", comments)
    );

    // Signature block
    lines.push("");
    lines.push(validPeriod);
    lines.push(signatureLine);

    var pre = document.getElementById("gs_advisory_preview");
    if (pre) {
        pre.textContent = lines.join("\n");
    }
}

// Format datetime to ddhhmm (no Z suffix) for valid period
function formatDdHhMm(localVal) {
    if (!localVal) return "------";
    try {
        var d = new Date(localVal);
        if (isNaN(d.getTime())) return "------";
        var dd = String(d.getUTCDate()).padStart(2, "0");
        var hh = String(d.getUTCHours()).padStart(2, "0");
        var mm = String(d.getUTCMinutes()).padStart(2, "0");
        return dd + hh + mm;
    } catch (e) {
        return "------";
    }
}

// Copy advisory preview to clipboard for Discord
function copyAdvisoryToClipboard() {
    var pre = document.getElementById("gs_advisory_preview");
    if (!pre) {
        alert("Advisory preview not found.");
        return;
    }

    var text = pre.textContent || pre.innerText || "";
    if (!text.trim()) {
        alert("Advisory preview is empty.");
        return;
    }

    // Wrap in code block for Discord formatting
    var discordText = "```\n" + text + "\n```";

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(discordText).then(function() {
            showCopySuccess("Advisory copied to clipboard!");
        }).catch(function(err) {
            console.error("Clipboard copy failed", err);
            fallbackCopyAdvisory(discordText);
        });
    } else {
        fallbackCopyAdvisory(discordText);
    }
}

function fallbackCopyAdvisory(text) {
    var textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.style.position = "fixed";
    textarea.style.left = "-9999px";
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand("copy");
        showCopySuccess("Advisory copied to clipboard!");
    } catch (err) {
        alert("Failed to copy advisory. Please select and copy manually.");
    }
    document.body.removeChild(textarea);
}

    function initGsUtcDefaults() {
        var startEl = document.getElementById("gs_start");
        var endEl = document.getElementById("gs_end");
        if (!startEl || !endEl) return;
        if (startEl.value || endEl.value) return;

        var now = new Date();
        var start = new Date(now.getTime());
        var end = new Date(now.getTime() + 2 * 60 * 60 * 1000);

        function toUtcLocalValue(d) {
            var y = d.getUTCFullYear();
            var m = String(d.getUTCMonth() + 1).padStart(2, "0");
            var day = String(d.getUTCDate()).padStart(2, "0");
            var hh = String(d.getUTCHours()).padStart(2, "0");
            var mm = String(d.getUTCMinutes()).padStart(2, "0");
            return y + "-" + m + "-" + day + "T" + hh + ":" + mm;
        }

        startEl.value = toUtcLocalValue(start);
        endEl.value = toUtcLocalValue(end);
    }

    function matchesCarrier(callsign, carrierFilter) {
        if (!carrierFilter) return true;
        if (!callsign) return false;
        var parts = carrierFilter.toUpperCase().split(/[\s,]+/).filter(function(p) { return p.length > 0; });
        if (!parts.length) return true;
        var cs = callsign.toUpperCase();
        for (var i = 0; i < parts.length; i++) {
            if (cs.indexOf(parts[i]) === 0) {
                return true;
            }
        }
        return false;
    }

    function isJetIcao(icao) {
        if (!icao) return false;
        icao = icao.toUpperCase();
        var jetPrefixes = ["A", "B", "C", "E", "F", "G", "H", "I"];
        return jetPrefixes.indexOf(icao.charAt(0)) !== -1;
    }

    

    function filterFlight(f, cfg, sourceTag) {
        if (!f || !f.flight_plan) return null;

        var dep = (f.flight_plan.departure || "").toUpperCase();
        var arr = (f.flight_plan.arrival || "").toUpperCase();
        var alt = f.flight_plan.altitude || "";
        var icao = (f.flight_plan.aircraft || "").toUpperCase();
        var callsign = (f.callsign || "").toUpperCase();
        var route = (f.flight_plan.route || "");

        // Timing fields for ETA estimation
        var depTimeField = f.flight_plan.deptime || null;
        var depEpoch = parseVatsimDepartureTimeToEpoch(depTimeField);
        var eteMinutes = parseVatsimEnrouteToMinutes(f.flight_plan.enroute_time);
        var roughEtaEpoch = null;
        var etaSource = null;

        if (depEpoch != null && eteMinutes != null) {
            // Primary: VATSIM-planned ETE
            roughEtaEpoch = depEpoch + eteMinutes * 60 * 1000;
            etaSource = "VATSIM";
        } else if (depEpoch != null) {
            // Fallback: BTS average airtime by origin/destination, if available
            var btsMinutes = lookupBtsAvgFlightMinutes(dep, arr, callsign);
            if (btsMinutes != null) {
                roughEtaEpoch = depEpoch + btsMinutes * 60 * 1000;
                etaSource = "BTS";
            }
        }

        var arrivals = (cfg && Array.isArray(cfg.arrivals)) ? cfg.arrivals : [];
        if (arrivals.length && arrivals.indexOf(arr) === -1) {
            return null;
        }

        var originAirports = (cfg && Array.isArray(cfg.originAirports)) ? cfg.originAirports : [];
        if (originAirports.length && originAirports.indexOf(dep) === -1) {
            return null;
        }

        var fltInclCarrier = cfg ? cfg.carriers : null;
        if (!matchesCarrier(callsign, fltInclCarrier)) {
            return null;
        }

        var acType = cfg && cfg.acType ? cfg.acType : "ALL";
        if (acType === "JET" && !isJetIcao(icao)) {
            return null;
        }
        if (acType === "PROP" && isJetIcao(icao)) {
            return null;
        }

        
return {
    callsign: callsign,
    dep: dep,
    arr: arr,
    alt: alt,
    aircraft: icao,
    status: (sourceTag || "").toUpperCase(),
    source: "VATSIM",
    route: route,
    depEpoch: depEpoch,
    eteMinutes: eteMinutes,
    roughEtaEpoch: roughEtaEpoch,
    etaSource: etaSource,
    edctEpoch: null,
    tkofEpoch: null,
    mftEpoch: null,
    vtEpoch: null,
    etaPrefix: etaSource,
    // ETD-related fields (for display)
    etdEpoch: depEpoch,
    etdPrefix: etaSource || "VATSIM",
    // Flight status / FSM (from data)
    flightStatus: (sourceTag || "").toUpperCase()
};

    }

    function filterAdlFlight(f, cfg) {
        if (!f) return null;

        // Try multiple common field-name patterns to be flexible with the ADL API
        var callsign = (f.callsign || f.CALLSIGN || "").toUpperCase();
        var dep = (f.fp_dept_icao || f.dep_icao || f.dep || f.departure || f.origin || "").toUpperCase();
        var arr = (f.fp_dest_icao || f.arr_icao || f.arr || f.arrival || f.destination || "").toUpperCase();
        var alt = f.fp_altitude_ft || f.filed_altitude || f.altitude || f.alt || "";
        var icao = (f.aircraft_icao || f.aircraft || f.acft || "").toUpperCase();
        var route = f.fp_route || f.route || f.route_string || f.filed_route || "";

// Status/source from ADL, with sane defaults
var status = (f.status || f.adl_status || "").toUpperCase();
if (!status) {
    status = f.is_active ? "ACTIVE" : "PREFILE";
}
var source = (f.source || "ADL").toUpperCase();

// Flight status (FSM) from ADL if available
var flightStatus = (f.flight_status || f.FLIGHT_STATUS || "").toUpperCase();
if (!flightStatus) {
    flightStatus = status;
}

// GS eligibility flag from ADL
var rawGsFlag = (typeof f.gs_flag !== "undefined"
                 ? f.gs_flag
                 : (typeof f.GS_FLAG !== "undefined" ? f.GS_FLAG : null));
var gsFlag = 0;
if (rawGsFlag === true ||
    rawGsFlag === 1 ||
    rawGsFlag === "1" ||
    rawGsFlag === "true" ||
    rawGsFlag === "TRUE") {
    gsFlag = 1;
}

        // Filed departure epoch
        var depEpoch = null;
        if (typeof f.filed_dep_epoch === "number") {
            depEpoch = f.filed_dep_epoch;
        } else if (f.filed_dep_utc || f.dep_utc || f.planned_dep_utc ||
                   f.estimated_dep_utc || f.etd_runway_utc) {
            depEpoch = parseSimtrafficTimeToEpoch(
                f.filed_dep_utc || f.dep_utc || f.planned_dep_utc ||
                f.estimated_dep_utc || f.etd_runway_utc
            );
        } else if (f.deptime) {
            depEpoch = parseVatsimDepartureTimeToEpoch(f.deptime);
        }

        
        // ETD epoch and prefix (if ADL provides explicit fields)
        var etdEpoch = null;
        if (typeof f.etd_epoch === "number") {
            etdEpoch = f.etd_epoch;
        } else if (f.etd_utc || f.etd_runway_utc || f.estimated_dep_utc) {
            etdEpoch = parseSimtrafficTimeToEpoch(
                f.etd_utc || f.etd_runway_utc || f.estimated_dep_utc
            );
        } else {
            etdEpoch = depEpoch;
        }
        
        var etdPrefix = f.etd_prefix || f.dep_prefix || f.etd_src || null;
        if (!etdPrefix && source) {
            etdPrefix = source;
        }

        // Enroute time in minutes
        var eteMinutes = null;
        if (typeof f.enroute_minutes === "number") {
            eteMinutes = f.enroute_minutes;
        } else if (typeof f.ete_minutes === "number") {
            eteMinutes = f.ete_minutes;
        } else if (f.enroute_time) {
            eteMinutes = parseVatsimEnrouteToMinutes(f.enroute_time);
        }

        // Best-guess ETA from ADL (trajectory, SimTraffic, etc.)
        var etaPrefix = f.eta_prefix || f.eta_src || null;
        var etaEpoch = null;
        if (typeof f.eta_epoch === "number") {
            etaEpoch = f.eta_epoch;
        } else if (f.eta_best_utc || f.eta_utc ||
                   f.eta_runway_utc || f.cta_utc || f.estimated_arr_utc) {
            etaEpoch = parseSimtrafficTimeToEpoch(
                f.eta_best_utc || f.eta_utc ||
                f.eta_runway_utc || f.cta_utc || f.estimated_arr_utc
            );
        } else if (depEpoch != null && eteMinutes != null) {
            etaEpoch = depEpoch + eteMinutes * 60 * 1000;
        }

        // Additional timing fields if ADL already carries them
        var edctEpoch = null;
        if (typeof f.edct_epoch === "number") {
            edctEpoch = f.edct_epoch;
        } else if (f.edct_utc || f.ctd_utc) {
            // Use edct_utc if present, otherwise fall back to CTD (ctd_utc)
            edctEpoch = parseSimtrafficTimeToEpoch(f.edct_utc || f.ctd_utc);
        }

        var tkofEpoch = null;
        if (typeof f.takeoff_epoch === "number") {
            tkofEpoch = f.takeoff_epoch;
        } else if (f.takeoff_utc || f.offblock_utc || f.wheels_off_utc) {
            tkofEpoch = parseSimtrafficTimeToEpoch(
                f.takeoff_utc || f.offblock_utc || f.wheels_off_utc
            );
        }

        var mftEpoch = null;
        if (typeof f.mft_epoch === "number") {
            mftEpoch = f.mft_epoch;
        } else if (f.mft_utc || f.eta_mf_utc) {
            mftEpoch = parseSimtrafficTimeToEpoch(f.mft_utc || f.eta_mf_utc);
        }

        var vtEpoch = null;
        if (typeof f.vt_epoch === "number") {
            vtEpoch = f.vt_epoch;
        } else if (f.vt_utc || f.vertex_utc) {
            vtEpoch = parseSimtrafficTimeToEpoch(f.vt_utc || f.vertex_utc);
        }

        // Apply the same filters used for the VATSIM feed
        var arrivals = (cfg && Array.isArray(cfg.arrivals)) ? cfg.arrivals : [];
        if (arrivals.length && arrivals.indexOf(arr) === -1) {
            return null;
        }

        var originAirports = (cfg && Array.isArray(cfg.originAirports)) ? cfg.originAirports : [];
        if (originAirports.length && originAirports.indexOf(dep) === -1) {
            return null;
        }

        var fltInclCarrier = cfg ? cfg.carriers : null;
        if (!matchesCarrier(callsign, fltInclCarrier)) {
            return null;
        }

        var acType = cfg && cfg.acType ? cfg.acType : "ALL";
        if (acType === "JET" && !isJetIcao(icao)) {
            return null;
        }
        if (acType === "PROP" && isJetIcao(icao)) {
            return null;
        }

        return {
    callsign: callsign,
    dep: dep,
    arr: arr,
    alt: alt,
    aircraft: icao,
    status: status,
    source: source,
    route: route,
    depEpoch: depEpoch,
    eteMinutes: eteMinutes,
    roughEtaEpoch: etaEpoch,
    etaSource: etaPrefix || "ADL",
    edctEpoch: edctEpoch,
    tkofEpoch: tkofEpoch,
    mftEpoch: mftEpoch,
    vtEpoch: vtEpoch,
    etaPrefix: etaPrefix || null,
    etdEpoch: etdEpoch,
    etdPrefix: etdPrefix || null,
    flightStatus: flightStatus,
    gsFlag: gsFlag
};
    }
function augmentRowsWithAdl(rows) {
    if (!rows || !rows.length) return;
    if (!GS_ADL || !Array.isArray(GS_ADL.flights) || !GS_ADL.flights.length) return;

    // Build an index of ADL flights by callsign the first time
    if (!GS_ADL._indexByCallsign) {
        var index = {};
        GS_ADL.flights.forEach(function(f) {
            if (!f) return;
            var cs = (f.callsign || f.CALLSIGN || "").toUpperCase();
            if (!cs) return;
            if (!index[cs]) index[cs] = [];
            index[cs].push(f);
        });
        GS_ADL._indexByCallsign = index;
    }

    var indexByCs = GS_ADL._indexByCallsign;

    rows.forEach(function(r) {
        if (!r || !r.callsign) return;
        var cs = String(r.callsign).toUpperCase();
        var bucket = indexByCs[cs];
        if (!bucket || !bucket.length) return;

        var dep = (r.dep || "").toUpperCase();
        var arr = (r.arr || "").toUpperCase();
        var best = null;

        // Prefer exact dep/arr match
        for (var i = 0; i < bucket.length; i++) {
            var f = bucket[i];
            var fDep = (f.dep_icao || f.dep || f.departure || f.origin || "").toUpperCase();
            var fArr = (f.arr_icao || f.arr || f.arrival || f.destination || "").toUpperCase();
            if (fDep === dep && fArr === arr) {
                best = f;
                break;
            }
        }
        if (!best) {
            best = bucket[0];
        }
        if (!best) return;

        var adlRow = filterAdlFlight(best, {
            arrivals: [],
            originAirports: [],
            carriers: null,
            acType: "ALL"
        });
        if (!adlRow) return;

        // Keep a reference to the underlying ADL row so we can show
        // TFMS-style Flight Info / Flight Detail popups later.
        r._adl = { raw: best, filtered: adlRow };

// Overlay timing and status fields from ADL when available
if (adlRow.depEpoch != null && !isNaN(adlRow.depEpoch)) {
    r.depEpoch = adlRow.depEpoch;
}
if (adlRow.etdEpoch != null && !isNaN(adlRow.etdEpoch)) {
    r.etdEpoch = adlRow.etdEpoch;
}
if (adlRow.roughEtaEpoch != null && !isNaN(adlRow.roughEtaEpoch)) {
    r.roughEtaEpoch = adlRow.roughEtaEpoch;
    r.etaSource = adlRow.etaSource;
    r.etaPrefix = adlRow.etaPrefix || adlRow.etaSource || r.etaPrefix;
}
if (adlRow.etdPrefix) {
    r.etdPrefix = adlRow.etdPrefix;
}
if (adlRow.flightStatus) {
    r.flightStatus = adlRow.flightStatus;
}
        if (typeof adlRow.gsFlag !== "undefined") {
            r.gsFlag = adlRow.gsFlag;
        }
        if (adlRow.edctEpoch != null && !isNaN(adlRow.edctEpoch)) {
            r.edctEpoch = adlRow.edctEpoch;
        }
        if (adlRow.tkofEpoch != null && !isNaN(adlRow.tkofEpoch)) {
            r.tkofEpoch = adlRow.tkofEpoch;
        }
        if (adlRow.mftEpoch != null && !isNaN(adlRow.mftEpoch)) {
            r.mftEpoch = adlRow.mftEpoch;
        }
        if (adlRow.vtEpoch != null && !isNaN(adlRow.vtEpoch)) {
            r.vtEpoch = adlRow.vtEpoch;
        }
    });
}



function buildAirportColorMap(airports) {
        var map = {};
        airports.forEach(function(a, idx) {
            var color = AIRPORT_COLOR_PALETTE[idx % AIRPORT_COLOR_PALETTE.length];
            map[a] = color;
        });
        return map;
    }

    function updateAirportsLegendAndInput(airports, airportColors) {
        var legend = document.getElementById("gs_airports_legend");
        var input = document.getElementById("gs_airports");
        if (!legend || !input) return;

        if (!airports.length) {
            legend.innerHTML = "";
            input.style.color = "";
            input.style.borderColor = "";
            input.style.boxShadow = "";
            return;
        }

        var html = "";
        airports.forEach(function(a) {
            var color = airportColors[a] || "#dee2e6";
            html += '<span class="tmi-airport-badge" style="background-color:' + color + ';">' +
                escapeHtml(a) + "</span>";
        });
        legend.innerHTML = html;

        if (airports.length === 1) {
            var c = airportColors[airports[0]] || "#4dabf7";
            input.style.color = c;
            input.style.borderColor = c;
            input.style.boxShadow = "0 0 0 0.1rem " + c + "40";
        } else {
            input.style.color = "";
            input.style.borderColor = "";
            input.style.boxShadow = "";
        }
    }

    

    
    
    function renderFlightsFromAdl(cfg, airportColors, updatedLbl, tbody) {
    var rows = [];

    // Base: VATSIM feed (pilots + prefiles)
    var data = GS_VATSIM || (GS_ADL && GS_ADL.vatsim) || {};
    (data.pilots || []).forEach(function(p) {
        var r = filterFlight(p, cfg, "PILOT");
        if (r) rows.push(r);
    });
    (data.prefiles || []).forEach(function(p) {
        var r = filterFlight(p, cfg, "PREFILE");
        if (r) rows.push(r);
    });

    // Augment timing information with ADL when available
    var enforceGsFlag = false;
    if (GS_ADL && Array.isArray(GS_ADL.flights) && GS_ADL.flights.length) {
        augmentRowsWithAdl(rows);
        enforceGsFlag = true;
    }

    // Only keep flights that are GS-eligible according to ADL (gs_flag = 1)
    if (enforceGsFlag) {
        rows = rows.filter(function(r) {
            return r.gsFlag === 1;
        });
    }

    rows.sort(function(a, b) {
        var aEta = (a.roughEtaEpoch != null && !isNaN(a.roughEtaEpoch)) ? a.roughEtaEpoch : Number.MAX_SAFE_INTEGER;
        var bEta = (b.roughEtaEpoch != null && !isNaN(b.roughEtaEpoch)) ? b.roughEtaEpoch : Number.MAX_SAFE_INTEGER;
        if (aEta !== bEta) {
            return aEta - bEta;
        }
        if (a.status === b.status) {
            return a.callsign.localeCompare(b.callsign);
        }
        return a.status === "ACTIVE" ? -1 : 1;
    });

    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-3">No GS-eligible flights matching current filters.</td></tr>';
    } else {
        GS_FLIGHT_ROW_INDEX = {};
        var html = "";
        rows.forEach(function(r) {
            var color = airportColors[r.arr] || "";
            var arrStyle = color ? ' style="color:' + color + '; font-weight:600;"' : "";
            var rowId = makeRowIdForCallsign(r.callsign || "");
            GS_FLIGHT_ROW_INDEX[rowId] = r;

            var depEpoch = (r.depEpoch != null && !isNaN(r.depEpoch)) ? r.depEpoch : "";
            var etaEpoch = (r.roughEtaEpoch != null && !isNaN(r.roughEtaEpoch)) ? r.roughEtaEpoch : "";
            var edctEpoch = (r.edctEpoch != null && !isNaN(r.edctEpoch)) ? r.edctEpoch : "";
            var tkofEpoch = (r.tkofEpoch != null && !isNaN(r.tkofEpoch)) ? r.tkofEpoch : "";
            var mftEpoch = (r.mftEpoch != null && !isNaN(r.mftEpoch)) ? r.mftEpoch : "";
var vtEpoch = (r.vtEpoch != null && !isNaN(r.vtEpoch)) ? r.vtEpoch : "";

// ETD epoch (use explicit ETD if present, otherwise filed dep)
var etdEpoch = (r.etdEpoch != null && !isNaN(r.etdEpoch)) ? r.etdEpoch : depEpoch;

var filedAttr = depEpoch !== "" ? String(depEpoch) : "";
            var etaAttr = etaEpoch !== "" ? String(etaEpoch) : "";
            var edctAttr = edctEpoch !== "" ? String(edctEpoch) : "";
            var tkAttr = tkofEpoch !== "" ? String(tkofEpoch) : "";
            var mftAttr = mftEpoch !== "" ? String(mftEpoch) : "";
            var vtAttr = vtEpoch !== "" ? String(vtEpoch) : "";
            var eteAttr = (typeof r.eteMinutes === "number" && !isNaN(r.eteMinutes)) ? String(r.eteMinutes) : "";

            var trData =
                ' id="' + rowId + '"' +
                ' data-route="' + escapeHtml(r.route || "") + '"' +
                ' data-callsign="' + escapeHtml(r.callsign || "") + '"' +
                ' data-edct-epoch="' + edctAttr + '"' +
                ' data-eta-epoch="' + etaAttr + '"' +
                ' data-etd-epoch="' + (etdEpoch !== "" ? String(etdEpoch) : "") + '"' +
                ' data-takeoff-epoch="' + tkAttr + '"' +
                ' data-filed-dep-epoch="' + filedAttr + '"' +
                ' data-mft-epoch="' + mftAttr + '"' +
                ' data-vt-epoch="' + vtAttr + '"' + ' data-ete-minutes="' + eteAttr + '"';

var etdText = "";
if (etdEpoch !== "") {
    var baseEtd = formatZuluFromEpoch(etdEpoch);
    etdText = r.etdPrefix ? (r.etdPrefix + " " + baseEtd) : baseEtd;
}

var etaText = "";
if (etaEpoch !== "") {
    var baseEta = formatZuluFromEpoch(etaEpoch);
    etaText = r.etaPrefix ? (r.etaPrefix + " " + baseEta) : baseEta;
}

var edctText = edctEpoch !== "" ? formatZuluFromEpoch(edctEpoch) : "";

// Departing center (ARTCC of origin airport)
var depCenter = "";
var depUpper = (r.dep || "").toUpperCase();
if (depUpper) {
    depCenter = AIRPORT_CENTER_MAP[depUpper] || "";
}

// Build status text from control type, delay status, or flight status
var statusText = "";
if (r._adl && r._adl.raw) {
    statusText = r._adl.raw.ctl_type || r._adl.raw.delay_status || "";
}
if (!statusText) {
    statusText = r.flightStatus || r.status || "";
}

html += "<tr" + trData + ">" +
    "<td><strong>" + (r.callsign || "") + "</strong></td>" +  // ACID
    '<td class="gs_etd_cell">' + etdText + "</td>" +          // ETD
    '<td class="gs_edct_cell">' + edctText + "</td>" +        // CTD
    '<td class="gs_eta_cell"' + arrStyle + ">" + etaText + "</td>" + // ETA
    "<td>" + depCenter + "</td>" +                            // DCTR
    "<td>" + (r.dep || "") + "</td>" +                        // ORIG
    '<td' + arrStyle + ">" + (r.arr || "") + "</td>" +        // DEST
    "<td>" + statusText + "</td>" +                           // STATUS
    "</tr>";
        });
        tbody.innerHTML = html;
    }

    // Allow SimTraffic to refine EDCT/ETA/MFT/VT if available
    enrichFlightsWithSimTraffic(rows);
    applyTimeFilterToTable();

    if (updatedLbl) {
        var labelTime = null;
        if (GS_ADL && GS_ADL.snapshotUtc instanceof Date && !isNaN(GS_ADL.snapshotUtc.getTime())) {
            labelTime = GS_ADL.snapshotUtc;
        } else if (GS_VATSIM && GS_VATSIM.general && GS_VATSIM.general.update_timestamp) {
            var tmpLbl = new Date(GS_VATSIM.general.update_timestamp);
            if (!isNaN(tmpLbl.getTime())) labelTime = tmpLbl;
        }
        if (!labelTime) {
            labelTime = new Date();
        }
        updatedLbl.textContent = "Updated " + labelTime.toUTCString();
    }
}



    function loadVatsimFlightsForCurrentGs() {
    var arrivalTokens = getValue("gs_airports").toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });
    var originAirportTokens = getValue("gs_origin_airports").toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });
    var depFacilityTokens = getValue("gs_dep_facilities").toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0 && x !== "ALL"; });
    var carriers = getValue("gs_flt_incl_carrier");
    var acTypeEl = document.getElementById("gs_flt_incl_type");
    var acType = acTypeEl ? acTypeEl.value : "ALL";

    var tbody = document.getElementById("gs_flight_table_body");
    var updatedLbl = document.getElementById("gs_flights_updated");

    if (!tbody) {
        return;
    }

    var arrivalsExpanded = expandAirportTokensWithFacilities(arrivalTokens);
    var originExpanded = expandAirportTokensWithFacilities(originAirportTokens);
    var depFacExpanded = expandAirportTokensWithFacilities(depFacilityTokens);

    depFacExpanded.forEach(function(a) {
        if (originExpanded.indexOf(a) === -1) {
            originExpanded.push(a);
        }
    });

    var airportColors = buildAirportColorMap(arrivalsExpanded);
    updateAirportsLegendAndInput(arrivalsExpanded, airportColors);

    if (!arrivalsExpanded.length) {
        tbody.innerHTML = '<tr><td colspan="8">Enter arrival airports and try again.</td></tr>';
        if (updatedLbl) updatedLbl.textContent = "";
        renderSummaryTable("gs_counts_origin_center", {});
        renderSummaryTable("gs_counts_dest_center", {});
        renderSummaryTable("gs_counts_origin_ap", {});
        renderSummaryTable("gs_counts_dest_ap", {});
        renderSummaryTable("gs_counts_carrier", {});
        return;
    }

    tbody.innerHTML = '<tr><td colspan="8">Loading flights from VATSIM...</td></tr>';
    if (updatedLbl) updatedLbl.textContent = "";

    var cfg = {
        arrivals: arrivalsExpanded,
        originAirports: originExpanded,
        carriers: carriers,
        acType: acType
    };

    // Load VATSIM as the primary flight list; ADL is used to augment timing
    Promise.all([
        ensureVatsimData(),
        refreshAdl().catch(function(err) {
            console.error("ADL load failed (will proceed with VATSIM only)", err);
            return null;
        })
    ]).then(function() {
        renderFlightsFromAdl(cfg, airportColors, updatedLbl, tbody);
    }).catch(function(err) {
        console.error("Error loading VATSIM data", err);
        tbody.innerHTML = '<tr><td colspan="8" class="text-danger">Error loading VATSIM data. ' +
            (err && err.message ? err.message : "") + "</td></tr>";
        if (updatedLbl) updatedLbl.textContent = "Error";
        summarizeFlights([]);
    });
}


function resetGsForm() {
        // Reset program state
        GS_CURRENT_PROGRAM_ID = null;
        GS_CURRENT_PROGRAM_STATUS = null;
        GS_SIMULATION_READY = false;
        setGsTableMode("LIVE");
        setSendActualEnabled(false, "Create a new program");

        var ids = [
            "gs_name", "gs_ctl_element", "gs_airports", "gs_origin_centers",
            "gs_origin_airports", "gs_flt_incl_carrier", "gs_dep_facilities",
            "gs_comments", "gs_prob_ext", "gs_impacting_condition",
            "gs_adv_number", "gs_start", "gs_end",
            // Exemption text fields
            "gs_exempt_orig_airports", "gs_exempt_orig_tracons", "gs_exempt_orig_artccs",
            "gs_exempt_dest_airports", "gs_exempt_dest_tracons", "gs_exempt_dest_artccs",
            "gs_exempt_flights", "gs_exempt_depart_within", "gs_exempt_alt_below", "gs_exempt_alt_above"
        ];
        ids.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = "";
        });

        // Reset exemption checkboxes
        var exemptCheckboxes = [
            "gs_exempt_type_jet", "gs_exempt_type_turboprop", "gs_exempt_type_prop",
            "gs_exempt_has_edct", "gs_exempt_active_only"
        ];
        exemptCheckboxes.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.checked = false;
        });

        var t = document.getElementById("gs_flt_incl_type");
        if (t) t.value = "ALL";

        var scopeSelect = document.getElementById("gs_scope_select");
        if (scopeSelect) {
            Array.prototype.forEach.call(scopeSelect.options, function(opt) { opt.selected = false; });
        }

        var pre = document.getElementById("gs_advisory_preview");
        if (pre) pre.textContent = "";

        var tbody = document.getElementById("gs_flight_table_body");
        if (tbody) tbody.innerHTML = "";

        var updatedLbl = document.getElementById("gs_flights_updated");
        if (updatedLbl) updatedLbl.textContent = "";

        // Reset exemption summary
        if (typeof updateExemptionSummary === "function") {
            updateExemptionSummary();
        }

        // Reset default times
        if (typeof initializeDefaultGsTimes === "function") {
            initializeDefaultGsTimes();
        }

        updateAirportsLegendAndInput([], {});
        summarizeFlights([]);
        buildAdvisory();
    }

    function populateScopeSelector() {
        var sel = document.getElementById("gs_scope_select");
        if (!sel) return;

        sel.innerHTML = "";

        if (!TMI_TIER_INFO.length && !TMI_UNIQUE_FACILITIES.length) {
            // No data loaded; keep selector empty but usable later if data appears.
            return;
        }

        // Special presets (if present)
        var optgroupPresets = document.createElement("optgroup");
        optgroupPresets.label = "Presets";
        ["ALL", "ALL+Canada", "Manual"].forEach(function(code) {
            var entry = TMI_TIER_INFO_BY_CODE[code];
            var opt = document.createElement("option");
            opt.value = code;
            opt.dataset.type = (code === "Manual") ? "manual" : "special";
            if (entry && entry.label) {
                opt.textContent = code + " " + entry.label;
            } else {
                opt.textContent = code;
            }
            optgroupPresets.appendChild(opt);
        });
        sel.appendChild(optgroupPresets);

        // Group TierInfo by facility
        var facilities = {};
        TMI_TIER_INFO.forEach(function(e) {
            if (!e.facility) return;
            if (!facilities[e.facility]) facilities[e.facility] = [];
            facilities[e.facility].push(e);
        });

        Object.keys(facilities).sort().forEach(function(fac) {
            var group = document.createElement("optgroup");
            group.label = fac + " tiers/groups";
            facilities[fac].forEach(function(e) {
                var opt = document.createElement("option");
                opt.value = e.code;
                opt.dataset.type = "tier";
                var label = e.label || e.code;
                group.appendChild(opt);
                opt.textContent = fac + " " + label.replace(/[()]/g, "");
            });
            sel.appendChild(group);
        });

        // Individual facilities
        if (TMI_UNIQUE_FACILITIES.length) {
            var groupInd = document.createElement("optgroup");
            groupInd.label = "Individual ARTCCs / FIRs";
            TMI_UNIQUE_FACILITIES.forEach(function(f) {
                var opt = document.createElement("option");
                opt.value = f;
                opt.dataset.type = "fac";
                opt.textContent = f;
                groupInd.appendChild(opt);
            });
            sel.appendChild(groupInd);
        }
    }

    function recomputeScopeFromSelector() {
        var sel = document.getElementById("gs_scope_select");
        if (!sel) return;

        var selected = Array.prototype.slice.call(sel.selectedOptions || []);
        var originCentersField = document.getElementById("gs_origin_centers");
        var depFacilitiesField = document.getElementById("gs_dep_facilities");

        var includedSet = new Set();
        var scopeTokens = [];
        var manual = selected.some(function(o) { return o.dataset.type === "manual"; });

        if (!manual) {
            selected.forEach(function(o) {
                var type = o.dataset.type;
                var val = o.value;
                if (type === "tier" || type === "special") {
                    var entry = TMI_TIER_INFO_BY_CODE[val];
                    if (entry) {
                        scopeTokens.push(val);
                        (entry.included || []).forEach(function(f) { includedSet.add(f); });
                    }
                } else if (type === "fac") {
                    scopeTokens.push(val);
                    includedSet.add(val);
                }
            });

            if (originCentersField) {
                originCentersField.value = scopeTokens.join(" ");
            }
            if (depFacilitiesField) {
                depFacilitiesField.value = Array.from(includedSet).sort().join(" ");
            }
        }

        buildAdvisory();
    }

    
    function expandAirportTokensWithFacilities(tokens) {
        if (!tokens || !tokens.length) return [];
        var hasFacilityData = Object.keys(CENTER_AIRPORTS_MAP).length > 0 || Object.keys(TRACON_AIRPORTS_MAP).length > 0;
        var airportsSet = new Set();
        tokens.forEach(function(tok) {
            var t = (tok || "").toUpperCase();
            if (!t) return;
            if (hasFacilityData && CENTER_AIRPORTS_MAP[t]) {
                CENTER_AIRPORTS_MAP[t].forEach(function(a) { airportsSet.add(a); });
            } else if (hasFacilityData && TRACON_AIRPORTS_MAP[t]) {
                TRACON_AIRPORTS_MAP[t].forEach(function(a) { airportsSet.add(a); });
            } else {
                airportsSet.add(t);
            }
        });
        return Array.from(airportsSet);
    }

    function renderSummaryTable(tbodyId, counts, options) {
        var tbody = document.getElementById(tbodyId);
        if (!tbody) return;
        var opts = options || {};
        var maxRows = typeof opts.maxRows === "number" ? opts.maxRows : 10;
        var labelFor = opts.labelFor || function(k) { return k; };

        var keys = Object.keys(counts || {});
        if (!keys.length) {
            tbody.innerHTML = '<tr><td colspan="2" class="text-muted">None</td></tr>';
            return;
        }

        var entries = keys.map(function(k) { return { key: k, count: counts[k] || 0 }; });
        entries.sort(function(a, b) {
            if (b.count !== a.count) return b.count - a.count;
            return a.key.localeCompare(b.key);
        });

        var html = "";
        entries.slice(0, maxRows).forEach(function(e) {
            var label = labelFor(e.key);
            html += "<tr><td>" + escapeHtml(label) + "</td><td class=\"text-right\">" + e.count + "</td></tr>";
        });
        tbody.innerHTML = html;
    }

    

function renderDelaySummaryTable(tbodyId, stats, options) {
    var tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    var opts = options || {};
    var maxRows = typeof opts.maxRows === "number" ? opts.maxRows : 10;
    var labelFor = opts.labelFor || function(k) { return k; };

    var keys = Object.keys(stats || {});
    if (!keys.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-muted">None</td></tr>';
        return;
    }

    var entries = keys.map(function(k) {
        var s = stats[k] || { total: 0, max: 0, count: 0 };
        var avg = s.count ? Math.round(s.total / s.count) : 0;
        return {
            key: k,
            label: labelFor(k),
            total: s.total,
            max: s.max,
            avg: avg
        };
    });

    entries.sort(function(a, b) {
        if (b.total !== a.total) return b.total - a.total;
        return a.key.localeCompare(b.key);
    });

    if (entries.length > maxRows) {
        entries = entries.slice(0, maxRows);
    }

    var html = "";
    entries.forEach(function(e) {
        html += "<tr>" +
            "<td>" + escapeHtml(e.label) + "</td>" +
            '<td class="text-right">' + e.total + "</td>" +
            '<td class="text-right">' + e.max + "</td>" +
            '<td class="text-right">' + e.avg + "</td>" +
            "</tr>";
    });
    tbody.innerHTML = html;
}

function updateDelayBreakdowns(visibleRows) {
    visibleRows = visibleRows || [];

    var perAp = {};
    var perCenter = {};
    var perCarrier = {};
    var perHour = {};

    function accum(map, key, delayMin) {
        if (!key) key = "UNK";
        if (!Object.prototype.hasOwnProperty.call(map, key)) {
            map[key] = { total: 0, max: 0, count: 0 };
        }
        var s = map[key];
        s.total += delayMin;
        if (delayMin > s.max) s.max = delayMin;
        s.count += 1;
    }

    visibleRows.forEach(function(tr) {
        if (!tr) return;

        var filedStr = tr.getAttribute("data-filed-dep-epoch") || "";
        var edctStr = tr.getAttribute("data-edct-epoch") || "";
        if (!filedStr || !edctStr) return;

        var filed = parseInt(filedStr, 10);
        var edct = parseInt(edctStr, 10);
        if (isNaN(filed) || isNaN(edct) || !filed || !edct) return;

        var delayMin = Math.round((edct - filed) / 60000);
        if (delayMin < 0) delayMin = 0;

        var tds = tr.querySelectorAll("td");
        var callsign = "";
        var depAp = "";
        var depCenter = "";
        if (tds && tds.length >= 1) {
            callsign = (tds[0].textContent || "").trim().toUpperCase();
        }

        // Table columns: 0=ACID, 1=ETD, 2=EDCT, 3=ETA, 4=DCENTR, 5=ORIG, 6=DEST, ...
        if (tds && tds.length >= 6) {
            depCenter = (tds[4].textContent || "").trim().toUpperCase();
            depAp = (tds[5].textContent || "").trim().toUpperCase();
        } else if (tds && tds.length >= 5) {
            // Fallback for older layouts (no DCENTR column)
            depAp = (tds[4].textContent || "").trim().toUpperCase();
        }

        var center = depCenter || (depAp ? (AIRPORT_CENTER_MAP[depAp] || "UNK") : "UNK");
        var carrier = callsign ? (deriveBtsCarrierFromCallsign(callsign) || "UNK") : "UNK";

        var hourKey = "";
        if (!isNaN(edct)) {
            var d = new Date(edct);
            var y = d.getUTCFullYear();
            var m = String(d.getUTCMonth() + 1).padStart(2, "0");
            var day = String(d.getUTCDate()).padStart(2, "0");
            var hh = String(d.getUTCHours()).padStart(2, "0");
            hourKey = y + "-" + m + "-" + day + " " + hh + "Z";
        }

        accum(perAp, depAp || "UNK", delayMin);
        accum(perCenter, center, delayMin);
        accum(perCarrier, carrier, delayMin);
        if (hourKey) accum(perHour, hourKey, delayMin);
    });

    renderDelaySummaryTable("gs_delay_origin_ap", perAp, { maxRows: 10 });
    renderDelaySummaryTable("gs_delay_origin_center", perCenter, { maxRows: 10 });
    renderDelaySummaryTable("gs_delay_carrier", perCarrier, { maxRows: 10 });
    renderDelaySummaryTable("gs_delay_hour_bin", perHour, { maxRows: 10 });
}

function summarizeFlights(rows) {
        rows = rows || [];
        var originCenterCounts = {};
        var destCenterCounts = {};
        var originApCounts = {};
        var destApCounts = {};
        var carrierCounts = {};

        rows.forEach(function(r) {
            var dep = (r.dep || "").toUpperCase();
            var arr = (r.arr || "").toUpperCase();
            var cs = (r.callsign || "").toUpperCase();

            var oCenter = AIRPORT_CENTER_MAP[dep] || "UNK";
            var dCenter = AIRPORT_CENTER_MAP[arr] || "UNK";

            originCenterCounts[oCenter] = (originCenterCounts[oCenter] || 0) + 1;
            destCenterCounts[dCenter] = (destCenterCounts[dCenter] || 0) + 1;

            if (dep) originApCounts[dep] = (originApCounts[dep] || 0) + 1;
            if (arr) destApCounts[arr] = (destApCounts[arr] || 0) + 1;

            var carrier = "UNK";
            if (cs) {
                var m = cs.match(/^[A-Z]+/);
                carrier = m ? m[0] : cs;
            }
            carrierCounts[carrier] = (carrierCounts[carrier] || 0) + 1;
        });

        renderSummaryTable("gs_counts_origin_center", originCenterCounts, {
            maxRows: 10,
            labelFor: function(k) { return k === "UNK" ? "Unknown" : k; }
        });
        renderSummaryTable("gs_counts_dest_center", destCenterCounts, {
            maxRows: 10,
            labelFor: function(k) { return k === "UNK" ? "Unknown" : k; }
        });
        renderSummaryTable("gs_counts_origin_ap", originApCounts, { maxRows: 10 });
        renderSummaryTable("gs_counts_dest_ap", destApCounts, { maxRows: 10 });
        renderSummaryTable("gs_counts_carrier", carrierCounts, { maxRows: 10 });
    }

  
  function extractRowSummary(tr) {
    if (!tr) return null;
    var tds = tr.querySelectorAll("td");
    if (!tds || tds.length < 7) return null;
    return {
      callsign: (tds[0].textContent || "").trim(), // ACID
      dep: (tds[5].textContent || "").trim(),      // ORIG
      arr: (tds[6].textContent || "").trim()       // DEST
    };
  }

  function getFlightModelForRow(tr) {
    if (!tr || !GS_FLIGHT_ROW_INDEX) return null;
    var rowId = tr.id || "";
    if (rowId && GS_FLIGHT_ROW_INDEX[rowId]) {
      return GS_FLIGHT_ROW_INDEX[rowId];
    }
    var cs = tr.getAttribute("data-callsign") || "";
    if (cs) {
      var altId = makeRowIdForCallsign(cs);
      if (GS_FLIGHT_ROW_INDEX[altId]) {
        return GS_FLIGHT_ROW_INDEX[altId];
      }
    }
    return null;
  }

  function showGsFlightRoute(tr) {
    if (!tr) return;
    var route = tr.getAttribute("data-route") || "";
    var cs = tr.getAttribute("data-callsign") || "";
    if (!route) {
      route = "(No route in flight plan)";
    }
    if (window.Swal) {
      window.Swal.fire({
        title: cs ? (cs + " Route") : "Flight Plan Route",
        html: "<pre style='text-align:left;white-space:pre-wrap;font-family:Inconsolata,monospace;font-size:0.8rem;'>" +
              escapeHtml(route) + "</pre>",
        width: "60%"
      });
    } else {
      alert(cs + "\n\n" + route);
    }
  }

  function showGsFlightInfo(tr) {
    if (!tr) return;
    var model = getFlightModelForRow(tr) || {};
    var summary = extractRowSummary(tr) || {};
    var callsign = summary.callsign || model.callsign || "";
    var dep = summary.dep || model.dep || "";
    var arr = summary.arr || model.arr || "";

    var acft = model.aircraft || "";
    var status = (model.flightStatus || model.status || "Normal");
    var src = model.source || "";

    var etdCell = tr.querySelector(".gs_etd_cell");
    var edctCell = tr.querySelector(".gs_edct_cell");
    var etaCell = tr.querySelector(".gs_eta_cell");
    var etdText = etdCell ? (etdCell.textContent || "").trim() : "";
    var edctText = edctCell ? (edctCell.textContent || "").trim() : "";
    var etaText = etaCell ? (etaCell.textContent || "").trim() : "";

    var eteStr = "";
    if (typeof model.eteMinutes === "number" && !isNaN(model.eteMinutes)) {
      eteStr = String(Math.round(model.eteMinutes));
    }

    var adlTime = getAdlSnapshotDisplayText();

    var adlRaw = model._adl && model._adl.raw ? model._adl.raw : null;
    var ctlElement = "";
    var tmaRt = "";
    if (adlRaw) {
      ctlElement = adlRaw.ctl_element || adlRaw.CTL_ELEMENT || ctlElement;
      tmaRt = adlRaw.tma_rt || adlRaw.TMA_RT || tmaRt;
    }
    if (!ctlElement) {
      ctlElement = getValue("gs_ctl_element") || "-";
    }

    var html = ''
      + '<div class="tfms-flight-info-popup">'
      +   '<div class="mb-2"><strong>ADL Date/Time:</strong> ' + escapeHtml(adlTime || "-")
      +   '&nbsp;&nbsp;&nbsp;<strong>Status:</strong> ' + escapeHtml(status || "-") + '</div>'
      +   '<table class="table table-sm table-borderless mb-2"><tbody>'
      +     '<tr>'
      +       '<td><strong>Flight ID:</strong></td><td>' + escapeHtml(callsign || "-") + '</td>'
      +       '<td><strong>Aircraft Type:</strong></td><td>' + escapeHtml(acft || "-") + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>Orig:</strong></td><td>' + escapeHtml(dep || "-") + '</td>'
      +       '<td><strong>Dest:</strong></td><td>' + escapeHtml(arr || "-") + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>ETD:</strong></td><td>' + escapeHtml(etdText || "-") + '</td>'
      +       '<td><strong>ETA:</strong></td><td>' + escapeHtml(etaText || "-") + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>EDCT:</strong></td><td>' + escapeHtml(edctText || "-") + '</td>'
      +       '<td><strong>ETE:</strong></td><td>' + escapeHtml(eteStr || "-") + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>Ctl Element:</strong></td><td>' + escapeHtml(ctlElement || "-") + '</td>'
      +       '<td><strong>TMA-RT:</strong></td><td>' + escapeHtml(tmaRt || "-") + '</td>'
      +     '</tr>'
      +   '</tbody></table>'
      +   '<div><small>Source: ' + escapeHtml(src || "") + '</small></div>'
      + '</div>';

    if (window.Swal) {
      window.Swal.fire({
        title: callsign ? ("Flight Info: " + callsign) : "Flight Info",
        html: html,
        width: "60%",
        showConfirmButton: true
      });
    } else {
      var txt = "Flight: " + (callsign || "") + "\n"
              + "Orig: " + (dep || "") + "  Dest: " + (arr || "") + "\n"
              + "ETD: " + (etdText || "") + "  EDCT: " + (edctText || "") + "  ETA: " + (etaText || "") + "\n"
              + "ETE: " + (eteStr || "");
      alert(txt);
    }
  }

  function showGsFlightDetail(tr) {
    if (!tr) return;
    var model = getFlightModelForRow(tr) || {};
    var summary = extractRowSummary(tr) || {};
    var callsign = summary.callsign || model.callsign || "";
    var dep = summary.dep || model.dep || "";
    var arr = summary.arr || model.arr || "";
    var acft = model.aircraft || "";
    var status = (model.flightStatus || model.status || "Normal");

    function readEpochAttr(attr) {
      var v = tr.getAttribute(attr) || "";
      if (!v) return null;
      var n = parseInt(v, 10);
      return isNaN(n) ? null : n;
    }

    var filedEpoch = readEpochAttr("data-filed-dep-epoch");
    var etdEpoch = readEpochAttr("data-etd-epoch") || filedEpoch;
    var edctEpoch = readEpochAttr("data-edct-epoch");
    var tkofEpoch = readEpochAttr("data-takeoff-epoch");
    var etaEpoch = readEpochAttr("data-eta-epoch");
    var mftEpoch = readEpochAttr("data-mft-epoch");
    var vtEpoch = readEpochAttr("data-vt-epoch");

    var filedText = filedEpoch != null ? formatZuluFromEpoch(filedEpoch) : "";
    var etdText = etdEpoch != null ? formatZuluFromEpoch(etdEpoch) : "";
    var edctText = edctEpoch != null ? formatZuluFromEpoch(edctEpoch) : "";
    var tkofText = tkofEpoch != null ? formatZuluFromEpoch(tkofEpoch) : "";
    var etaText = etaEpoch != null ? formatZuluFromEpoch(etaEpoch) : "";
    var mftText = mftEpoch != null ? formatZuluFromEpoch(mftEpoch) : "";
    var vtText = vtEpoch != null ? formatZuluFromEpoch(vtEpoch) : "";

    var delayMin = null;
    if (filedEpoch != null && edctEpoch != null) {
      delayMin = Math.max(0, Math.round((edctEpoch - filedEpoch) / 60000));
    }
    var delayStr = delayMin != null ? String(delayMin) : "";

    var eteStr = "";
    if (typeof model.eteMinutes === "number" && !isNaN(model.eteMinutes)) {
      eteStr = String(Math.round(model.eteMinutes));
    } else if (etdEpoch != null && etaEpoch != null) {
      eteStr = String(Math.max(0, Math.round((etaEpoch - etdEpoch) / 60000)));
    }

    var route = tr.getAttribute("data-route") || "";
    var adlTime = getAdlSnapshotDisplayText();

    var html = ''
      + '<div class="tfms-flight-detail-popup">'
      +   '<div class="mb-2"><strong>Flight:</strong> ' + escapeHtml(callsign || "-")
      +   '&nbsp;&nbsp;&nbsp;<strong>Type:</strong> ' + escapeHtml(acft || "-")
      +   '&nbsp;&nbsp;&nbsp;<strong>Status:</strong> ' + escapeHtml(status || "-") + '</div>'
      +   '<div class="mb-2"><strong>Orig:</strong> ' + escapeHtml(dep || "-")
      +   '&nbsp;&nbsp;&nbsp;<strong>Dest:</strong> ' + escapeHtml(arr || "-") + '</div>'
      +   '<div class="mb-2"><strong>ADL Date/Time:</strong> ' + escapeHtml(adlTime || "-") + '</div>'
      +   '<table class="table table-sm table-bordered mb-2"><tbody>'
      +     '<tr><th colspan="2">Departure Timeline (UTC)</th></tr>'
      +     '<tr><td>Filed Departure (P/F)</td><td>' + escapeHtml(filedText || "-") + '</td></tr>'
      +     '<tr><td>ETD</td><td>' + escapeHtml(etdText || "-") + '</td></tr>'
      +     '<tr><td>EDCT / CTD</td><td>' + escapeHtml(edctText || "-") + '</td></tr>'
      +     '<tr><td>Actual Takeoff</td><td>' + escapeHtml(tkofText || "-") + '</td></tr>'
      +   '</tbody></table>'
      +   '<table class="table table-sm table-bordered mb-2"><tbody>'
      +     '<tr><th colspan="2">Arrival Timeline (UTC)</th></tr>'
      +     '<tr><td>ETA</td><td>' + escapeHtml(etaText || "-") + '</td></tr>'
      +     '<tr><td>MFT</td><td>' + escapeHtml(mftText || "-") + '</td></tr>'
      +     '<tr><td>Vertex Time</td><td>' + escapeHtml(vtText || "-") + '</td></tr>'
      +   '</tbody></table>'
      +   '<table class="table table-sm table-borderless mb-2"><tbody>'
      +     '<tr><td><strong>ETE (min):</strong></td><td>' + escapeHtml(eteStr || "-") + '</td>'
      +         '<td><strong>Delay (min):</strong></td><td>' + escapeHtml(delayStr || "-") + '</td></tr>'
      +   '</tbody></table>'
      +   '<div><strong>Route:</strong></div>'
      +   '<pre style="max-height:10rem;overflow:auto;white-space:pre-wrap;font-family:Inconsolata,monospace;font-size:0.8rem;">'
      +     escapeHtml(route || "(No route in flight plan)")
      +   '</pre>'
      + '</div>';

    if (window.Swal) {
      window.Swal.fire({
        title: callsign ? ("Flight Detail: " + callsign) : "Flight Detail",
        html: html,
        width: "70%",
        showConfirmButton: true
      });
    } else {
      var txt = "Flight detail for " + (callsign || "") + "\n"
              + "Orig: " + (dep || "") + "  Dest: " + (arr || "") + "\n"
              + "Filed: " + (filedText || "") + "  ETD: " + (etdText || "") + "  EDCT: " + (edctText || "") + "\n"
              + "ETA: " + (etaText || "") + "  ETE(min): " + (eteStr || "") + "  Delay(min): " + (delayStr || "");
      alert(txt);
    }
  }

  function initGsFlightContextMenu() {
    var tbody = document.getElementById("gs_flight_table_body");
    if (!tbody) return;

    var menuId = "gs_flight_context_menu";
    var menu = document.getElementById(menuId);
    if (!menu) {
      menu = document.createElement("div");
      menu.id = menuId;
      menu.className = "dropdown-menu shadow";
      menu.style.position = "fixed";
      menu.style.zIndex = "9999";
      menu.style.display = "none";
      // TFMS/FSM compliant context menu options (Ch 6 & 13)
      menu.innerHTML = ''
        + '<button type="button" class="dropdown-item" data-action="info"><i class="fas fa-info-circle mr-2"></i>Flight Info</button>'
        + '<button type="button" class="dropdown-item" data-action="detail"><i class="fas fa-clipboard-list mr-2"></i>Flight Detail</button>'
        + '<div class="dropdown-divider"></div>'
        + '<button type="button" class="dropdown-item" data-action="edct_check"><i class="fas fa-search mr-2"></i>EDCT Check</button>'
        + '<button type="button" class="dropdown-item" data-action="edct_update"><i class="fas fa-edit mr-2"></i>EDCT Update</button>'
        + '<div class="dropdown-divider"></div>'
        + '<button type="button" class="dropdown-item" data-action="ecr"><i class="fas fa-clock mr-2"></i>ECR</button>'
        + '<div class="dropdown-divider"></div>'
        + '<button type="button" class="dropdown-item" data-action="route"><i class="fas fa-route mr-2"></i>Flight Plan Route</button>';
      document.body.appendChild(menu);
    }

    function hideMenu() {
      if (!menu) return;
      menu.style.display = "none";
      menu._currentRow = null;
    }

    tbody.addEventListener("contextmenu", function(ev) {
      var tr = ev.target.closest("tr");
      if (!tr) return;
      ev.preventDefault();
      ev.stopPropagation();
      menu._currentRow = tr;

      var x = ev.clientX || 0;
      var y = ev.clientY || 0;

      // Ensure menu stays within viewport
      var menuWidth = 200;
      var menuHeight = 280;
      if (x + menuWidth > window.innerWidth) x = window.innerWidth - menuWidth - 10;
      if (y + menuHeight > window.innerHeight) y = window.innerHeight - menuHeight - 10;

      menu.style.left = x + "px";
      menu.style.top = y + "px";
      menu.style.display = "block";
    });

    menu.addEventListener("click", function(ev) {
      var btn = ev.target.closest(".dropdown-item");
      if (!btn) return;
      ev.preventDefault();
      var action = btn.getAttribute("data-action");
      var tr = menu._currentRow;
      hideMenu();
      if (!tr) return;

      switch (action) {
        case "info":
          showGsFlightInfo(tr);
          break;
        case "detail":
          showGsFlightDetail(tr);
          break;
        case "route":
          showGsFlightRoute(tr);
          break;
        case "edct_check":
          showMatchingTableEdctCheck(tr);
          break;
        case "edct_update":
          showMatchingTableEdctUpdate(tr);
          break;
        case "ecr":
          openMatchingTableEcr(tr);
          break;
      }
    });

    document.addEventListener("click", function(ev) {
      if (!menu || menu.style.display === "none") return;
      if (ev.target === menu || menu.contains(ev.target)) return;
      hideMenu();
    });

    document.addEventListener("keydown", function(ev) {
      if (ev.key === "Escape") {
        hideMenu();
      }
    });
  }

  // EDCT Check for Flights Matching table (TFMS/FSM Ch 13 - Figure 13-6)
  function showMatchingTableEdctCheck(tr) {
    if (!tr) return;
    
    var callsign = tr.getAttribute("data-callsign") || "";
    var orig = tr.getAttribute("data-orig") || "";
    var dest = tr.getAttribute("data-dest") || "";
    
    // Get EDCT/CTD from cells or data attributes
    var edctCell = tr.querySelector(".gs_edct_cell");
    var etaCell = tr.querySelector(".gs_eta_cell");
    var edctText = edctCell ? edctCell.textContent.trim() : "-";
    var etaText = etaCell ? etaCell.textContent.trim() : "-";
    
    // Get delay from model if available
    var model = getFlightModelForRow(tr) || {};
    var delay = model.programDelayMin || model.delay || 0;

    var html = ''
      + '<div class="text-left">'
      +   '<p class="mb-3">Query EDCT status for flight <strong>' + escapeHtml(callsign) + '</strong></p>'
      +   '<table class="table table-sm table-bordered"><tbody>'
      +     '<tr><th width="30%">ACID</th><td>' + escapeHtml(callsign) + '</td></tr>'
      +     '<tr><th>Origin</th><td>' + escapeHtml(orig) + '</td></tr>'
      +     '<tr><th>Destination</th><td>' + escapeHtml(dest) + '</td></tr>'
      +   '</tbody></table>'
      +   '<hr>'
      +   '<h6>Current EDCT Status:</h6>'
      +   '<table class="table table-sm table-bordered"><tbody>'
      +     '<tr><th width="30%">CTD (EDCT)</th><td class="font-weight-bold">' + escapeHtml(edctText) + '</td></tr>'
      +     '<tr><th>ETA</th><td>' + escapeHtml(etaText) + '</td></tr>'
      +     '<tr><th>Delay</th><td class="' + (delay > 60 ? 'text-danger' : (delay > 30 ? 'text-warning' : '')) + '">' + delay + ' min</td></tr>'
      +   '</tbody></table>'
      +   '<div class="alert alert-info mt-3 mb-0"><small><i class="fas fa-info-circle mr-1"></i>This displays the current EDCT from the ADL snapshot. In a live TFMS environment, this would query the Hub for real-time EDCT data.</small></div>'
      + '</div>';

    if (window.Swal) {
      window.Swal.fire({
        title: '<i class="fas fa-search text-info mr-2"></i>EDCT Check',
        html: html,
        width: "50%",
        showConfirmButton: true,
        confirmButtonText: "Close"
      });
    } else {
      alert("EDCT Check for " + callsign + "\nCTD: " + edctText + "\nETA: " + etaText);
    }
  }

  // EDCT Update for Flights Matching table (TFMS/FSM Ch 13 - Figure 13-7)
  function showMatchingTableEdctUpdate(tr) {
    if (!tr) return;
    
    var callsign = tr.getAttribute("data-callsign") || "";
    var orig = tr.getAttribute("data-orig") || "";
    var dest = tr.getAttribute("data-dest") || "";
    
    var edctCell = tr.querySelector(".gs_edct_cell");
    var edctText = edctCell ? edctCell.textContent.trim() : "-";
    
    // Get EDCT epoch for default new time calculation
    var edctEpoch = parseInt(tr.getAttribute("data-edct-epoch") || "0", 10);
    var defaultNewEdct = "";
    if (edctEpoch > 0) {
      var newDate = new Date(edctEpoch + 15 * 60000); // +15 minutes
      defaultNewEdct = newDate.toISOString().slice(0, 16);
    }

    var html = ''
      + '<div class="text-left">'
      +   '<p class="mb-3">Update EDCT for flight <strong>' + escapeHtml(callsign) + '</strong></p>'
      +   '<table class="table table-sm table-bordered mb-3"><tbody>'
      +     '<tr><th width="30%">ACID</th><td>' + escapeHtml(callsign) + '</td></tr>'
      +     '<tr><th>Origin</th><td>' + escapeHtml(orig) + '</td></tr>'
      +     '<tr><th>Destination</th><td>' + escapeHtml(dest) + '</td></tr>'
      +     '<tr><th>Current CTD</th><td class="font-weight-bold">' + escapeHtml(edctText) + '</td></tr>'
      +   '</tbody></table>'
      +   '<div class="form-group">'
      +     '<label for="matching_edct_update_time"><strong>New EDCT (UTC):</strong></label>'
      +     '<input type="datetime-local" class="form-control" id="matching_edct_update_time" value="' + defaultNewEdct + '">'
      +     '<small class="form-text text-muted">Enter the new Estimated Departure Clearance Time</small>'
      +   '</div>'
      +   '<div class="alert alert-warning mt-3 mb-0"><small><i class="fas fa-exclamation-triangle mr-1"></i>In a live TFMS environment, this would submit an EDCT update request to the Hub. This is a simulation environment.</small></div>'
      + '</div>';

    if (window.Swal) {
      window.Swal.fire({
        title: '<i class="fas fa-edit text-warning mr-2"></i>EDCT Update',
        html: html,
        width: "50%",
        showConfirmButton: true,
        confirmButtonText: "Send Update",
        showCancelButton: true,
        cancelButtonText: "Cancel",
        preConfirm: function() {
          var newEdctEl = document.getElementById("matching_edct_update_time");
          var newEdct = newEdctEl ? newEdctEl.value : "";
          if (!newEdct) {
            window.Swal.showValidationMessage("Please enter a new EDCT time");
            return false;
          }
          return { callsign: callsign, orig: orig, dest: dest, newEdct: newEdct };
        }
      }).then(function(result) {
        if (result.isConfirmed && result.value) {
          var newEdctFormatted = formatZuluFromEpoch(new Date(result.value.newEdct).getTime());
          window.Swal.fire({
            icon: "info",
            title: "EDCT Update Simulated",
            html: "<p>In a live environment, the following update would be sent:</p>" +
                  "<p><strong>Flight:</strong> " + escapeHtml(result.value.callsign) + "<br>" +
                  "<strong>New EDCT:</strong> " + newEdctFormatted + "</p>" +
                  "<p class='text-muted small'>This is a simulation - no actual update was sent to TFMS.</p>"
          });
        }
      });
    } else {
      alert("EDCT Update for " + callsign + "\nCurrent CTD: " + edctText);
    }
  }

  // Open ECR for Flights Matching table row
  function openMatchingTableEcr(tr) {
    if (!tr) return;
    
    var callsign = tr.getAttribute("data-callsign") || "";
    var orig = tr.getAttribute("data-orig") || "";
    var dest = tr.getAttribute("data-dest") || "";

    // Use the existing openEcrForFlight function
    openEcrForFlight(callsign, orig, dest);
  }

  // ========================================================================
  // GS FLIGHT LIST CONTEXT MENU (TFMS/FSM Spec: Chapter 6 & 13)
  // Right-click options: Flight Info, Flight Detail, EDCT Check, EDCT Update, ECR
  // ========================================================================
  
  var GS_FLT_LIST_CONTEXT_MENU = null;
  var GS_FLT_LIST_CONTEXT_ROW = null;

  function initGsFlightListContextMenu() {
    var tbody = document.getElementById("gs_flight_list_body");
    if (!tbody) return;

    var menuId = "gs_flt_list_context_menu";
    var menu = document.getElementById(menuId);
    if (!menu) {
      menu = document.createElement("div");
      menu.id = menuId;
      menu.className = "dropdown-menu shadow";
      menu.style.position = "fixed";
      menu.style.zIndex = "9999";
      menu.style.display = "none";
      menu.innerHTML = ''
        + '<button type="button" class="dropdown-item" data-action="flight_info"><i class="fas fa-info-circle mr-2"></i>Flight Info</button>'
        + '<button type="button" class="dropdown-item" data-action="flight_detail"><i class="fas fa-clipboard-list mr-2"></i>Flight Detail</button>'
        + '<div class="dropdown-divider"></div>'
        + '<button type="button" class="dropdown-item" data-action="edct_check"><i class="fas fa-search mr-2"></i>EDCT Check</button>'
        + '<button type="button" class="dropdown-item" data-action="edct_update"><i class="fas fa-edit mr-2"></i>EDCT Update</button>'
        + '<div class="dropdown-divider"></div>'
        + '<button type="button" class="dropdown-item" data-action="ecr"><i class="fas fa-clock mr-2"></i>ECR</button>';
      document.body.appendChild(menu);
      GS_FLT_LIST_CONTEXT_MENU = menu;
    }

    function hideFlightListMenu() {
      if (!GS_FLT_LIST_CONTEXT_MENU) return;
      GS_FLT_LIST_CONTEXT_MENU.style.display = "none";
      GS_FLT_LIST_CONTEXT_ROW = null;
    }

    tbody.addEventListener("contextmenu", function(ev) {
      var tr = ev.target.closest("tr.gs-flt-list-row");
      if (!tr) return;
      ev.preventDefault();
      ev.stopPropagation();
      GS_FLT_LIST_CONTEXT_ROW = tr;

      var x = ev.clientX || 0;
      var y = ev.clientY || 0;

      // Ensure menu stays within viewport
      var menuWidth = 200;
      var menuHeight = 220;
      if (x + menuWidth > window.innerWidth) x = window.innerWidth - menuWidth - 10;
      if (y + menuHeight > window.innerHeight) y = window.innerHeight - menuHeight - 10;

      GS_FLT_LIST_CONTEXT_MENU.style.left = x + "px";
      GS_FLT_LIST_CONTEXT_MENU.style.top = y + "px";
      GS_FLT_LIST_CONTEXT_MENU.style.display = "block";
    });

    menu.addEventListener("click", function(ev) {
      var btn = ev.target.closest(".dropdown-item");
      if (!btn) return;
      ev.preventDefault();
      var action = btn.getAttribute("data-action");
      var tr = GS_FLT_LIST_CONTEXT_ROW;
      hideFlightListMenu();
      if (!tr) return;

      switch (action) {
        case "flight_info":
          showFlightListFlightInfo(tr);
          break;
        case "flight_detail":
          showFlightListFlightDetail(tr);
          break;
        case "edct_check":
          showEdctCheckDialog(tr);
          break;
        case "edct_update":
          showEdctUpdateDialog(tr);
          break;
        case "ecr":
          openEcrFromFlightList(tr);
          break;
      }
    });

    // Hide menu on click outside
    document.addEventListener("click", function(ev) {
      if (!GS_FLT_LIST_CONTEXT_MENU || GS_FLT_LIST_CONTEXT_MENU.style.display === "none") return;
      if (ev.target === GS_FLT_LIST_CONTEXT_MENU || GS_FLT_LIST_CONTEXT_MENU.contains(ev.target)) return;
      hideFlightListMenu();
    });

    // Hide menu on scroll within modal
    var modalBody = tbody.closest(".modal-body");
    if (modalBody) {
      modalBody.addEventListener("scroll", hideFlightListMenu);
    }

    // Hide menu on Escape key
    document.addEventListener("keydown", function(ev) {
      if (ev.key === "Escape") {
        hideFlightListMenu();
      }
    });
  }

  // Flight Info dialog for Flight List (TFMS/FSM Ch 6 - Figure 6-5)
  function showFlightListFlightInfo(tr) {
    if (!tr) return;
    
    var acid = tr.getAttribute("data-acid") || "";
    var orig = tr.getAttribute("data-orig") || "";
    var dest = tr.getAttribute("data-dest") || "";
    var dcenter = tr.getAttribute("data-dcenter") || "";
    var acenter = tr.getAttribute("data-acenter") || "";
    var oetd = tr.getAttribute("data-oetd") || "";
    var oeta = tr.getAttribute("data-oeta") || "";
    var ctd = tr.getAttribute("data-ctd") || "";
    var cta = tr.getAttribute("data-cta") || "";
    var delay = tr.getAttribute("data-delay") || "0";
    var status = tr.getAttribute("data-status") || "";

    var ctlElement = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_ctl_element) || "-";
    var adlTime = getAdlSnapshotDisplayText();

    var html = ''
      + '<div class="tfms-flight-info-popup text-left">'
      +   '<div class="mb-2"><strong>ADL Date/Time:</strong> ' + escapeHtml(adlTime || "-")
      +   '&nbsp;&nbsp;&nbsp;<strong>Status:</strong> <span class="badge badge-warning">' + escapeHtml(status || "GS") + '</span></div>'
      +   '<table class="table table-sm table-borderless mb-2"><tbody>'
      +     '<tr>'
      +       '<td><strong>Flight ID:</strong></td><td>' + escapeHtml(acid || "-") + '</td>'
      +       '<td><strong>Ctl Element:</strong></td><td>' + escapeHtml(ctlElement || "-") + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>Orig:</strong></td><td>' + escapeHtml(orig || "-") + ' / ' + escapeHtml(dcenter || "-") + '</td>'
      +       '<td><strong>Dest:</strong></td><td>' + escapeHtml(dest || "-") + ' / ' + escapeHtml(acenter || "-") + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>OETD:</strong></td><td>' + (oetd ? formatZuluFromIso(oetd) : "-") + '</td>'
      +       '<td><strong>OETA:</strong></td><td>' + (oeta ? formatZuluFromIso(oeta) : "-") + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>CTD:</strong></td><td class="font-weight-bold">' + (ctd ? formatZuluFromIso(ctd) : "-") + '</td>'
      +       '<td><strong>CTA:</strong></td><td class="font-weight-bold">' + (cta ? formatZuluFromIso(cta) : "-") + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>Delay:</strong></td><td class="' + (parseInt(delay) > 60 ? 'text-danger font-weight-bold' : (parseInt(delay) > 30 ? 'text-warning' : '')) + '">' + delay + ' min</td>'
      +       '<td><strong>Ctl Program:</strong></td><td>GS</td>'
      +     '</tr>'
      +   '</tbody></table>'
      + '</div>';

    if (window.Swal) {
      window.Swal.fire({
        title: '<i class="fas fa-info-circle text-info mr-2"></i>Flight Info: ' + escapeHtml(acid),
        html: html,
        width: "60%",
        showConfirmButton: true,
        confirmButtonText: "Close"
      });
    } else {
      alert("Flight: " + acid + "\nOrig: " + orig + " Dest: " + dest + "\nCTD: " + ctd + " CTA: " + cta + "\nDelay: " + delay + " min");
    }
  }

  // Flight Detail dialog for Flight List (TFMS/FSM Ch 6 - Figure 6-6)
  function showFlightListFlightDetail(tr) {
    if (!tr) return;
    
    var acid = tr.getAttribute("data-acid") || "";
    var orig = tr.getAttribute("data-orig") || "";
    var dest = tr.getAttribute("data-dest") || "";
    var dcenter = tr.getAttribute("data-dcenter") || "";
    var acenter = tr.getAttribute("data-acenter") || "";
    var oetd = tr.getAttribute("data-oetd") || "";
    var oeta = tr.getAttribute("data-oeta") || "";
    var etd = tr.getAttribute("data-etd") || "";
    var ctd = tr.getAttribute("data-ctd") || "";
    var eta = tr.getAttribute("data-eta") || "";
    var cta = tr.getAttribute("data-cta") || "";
    var delay = tr.getAttribute("data-delay") || "0";
    var status = tr.getAttribute("data-status") || "";
    var carrier = tr.getAttribute("data-carrier") || "";

    var ctlElement = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_ctl_element) || "-";
    var gsStart = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_start) ? formatZuluFromIso(GS_FLIGHT_LIST_PAYLOAD.gs_start) : "-";
    var gsEnd = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_end) ? formatZuluFromIso(GS_FLIGHT_LIST_PAYLOAD.gs_end) : "-";
    var adlTime = getAdlSnapshotDisplayText();

    // Calculate ETE if possible
    var eteStr = "-";
    if (oetd && oeta) {
      try {
        var etdDate = new Date(oetd);
        var etaDate = new Date(oeta);
        if (!isNaN(etdDate.getTime()) && !isNaN(etaDate.getTime())) {
          var eteMin = Math.round((etaDate - etdDate) / 60000);
          if (eteMin > 0) eteStr = eteMin + " min";
        }
      } catch (e) {}
    }

    var html = ''
      + '<div class="tfms-flight-detail-popup text-left">'
      +   '<div class="row mb-3">'
      +     '<div class="col-md-6">'
      +       '<p class="mb-1"><strong>Flight ID:</strong> ' + escapeHtml(acid) + '</p>'
      +       '<p class="mb-1"><strong>Carrier:</strong> ' + escapeHtml(carrier || "-") + '</p>'
      +       '<p class="mb-1"><strong>Status:</strong> <span class="badge badge-warning">' + escapeHtml(status || "GS") + '</span></p>'
      +     '</div>'
      +     '<div class="col-md-6">'
      +       '<p class="mb-1"><strong>ADL Time:</strong> ' + escapeHtml(adlTime || "-") + '</p>'
      +       '<p class="mb-1"><strong>Ctl Element:</strong> ' + escapeHtml(ctlElement) + '</p>'
      +       '<p class="mb-1"><strong>GS Period:</strong> ' + gsStart + ' - ' + gsEnd + '</p>'
      +     '</div>'
      +   '</div>'
      +   '<div class="row">'
      +     '<div class="col-md-6">'
      +       '<h6 class="border-bottom pb-1"><i class="fas fa-plane-departure mr-1"></i>Departure</h6>'
      +       '<table class="table table-sm table-borderless mb-0"><tbody>'
      +         '<tr><td width="40%">Airport:</td><td><strong>' + escapeHtml(orig || "-") + '</strong></td></tr>'
      +         '<tr><td>Center:</td><td>' + escapeHtml(dcenter || "-") + '</td></tr>'
      +         '<tr><td>OETD:</td><td class="text-muted">' + (oetd ? formatZuluFromIso(oetd) : "-") + '</td></tr>'
      +         '<tr><td>ETD:</td><td>' + (etd ? formatZuluFromIso(etd) : "-") + '</td></tr>'
      +         '<tr><td>CTD:</td><td class="font-weight-bold text-primary">' + (ctd ? formatZuluFromIso(ctd) : "-") + '</td></tr>'
      +       '</tbody></table>'
      +     '</div>'
      +     '<div class="col-md-6">'
      +       '<h6 class="border-bottom pb-1"><i class="fas fa-plane-arrival mr-1"></i>Arrival</h6>'
      +       '<table class="table table-sm table-borderless mb-0"><tbody>'
      +         '<tr><td width="40%">Airport:</td><td><strong>' + escapeHtml(dest || "-") + '</strong></td></tr>'
      +         '<tr><td>Center:</td><td>' + escapeHtml(acenter || "-") + '</td></tr>'
      +         '<tr><td>OETA:</td><td class="text-muted">' + (oeta ? formatZuluFromIso(oeta) : "-") + '</td></tr>'
      +         '<tr><td>ETA:</td><td>' + (eta ? formatZuluFromIso(eta) : "-") + '</td></tr>'
      +         '<tr><td>CTA:</td><td class="font-weight-bold text-primary">' + (cta ? formatZuluFromIso(cta) : "-") + '</td></tr>'
      +       '</tbody></table>'
      +     '</div>'
      +   '</div>'
      +   '<div class="row mt-3">'
      +     '<div class="col-12">'
      +       '<table class="table table-sm table-bordered"><tbody>'
      +         '<tr class="bg-light">'
      +           '<th>ETE</th><th>Program Delay</th><th>Control Type</th><th>Delay Status</th>'
      +         '</tr>'
      +         '<tr>'
      +           '<td>' + eteStr + '</td>'
      +           '<td class="' + (parseInt(delay) > 60 ? 'text-danger font-weight-bold' : (parseInt(delay) > 30 ? 'text-warning' : '')) + '">' + delay + ' min</td>'
      +           '<td>GS</td>'
      +           '<td>' + escapeHtml(status || "-") + '</td>'
      +         '</tr>'
      +       '</tbody></table>'
      +     '</div>'
      +   '</div>'
      + '</div>';

    if (window.Swal) {
      window.Swal.fire({
        title: '<i class="fas fa-clipboard-list text-primary mr-2"></i>Flight Detail: ' + escapeHtml(acid),
        html: html,
        width: "70%",
        showConfirmButton: true,
        confirmButtonText: "Close"
      });
    } else {
      alert("Flight Detail: " + acid + "\nOrig: " + orig + "/" + dcenter + " Dest: " + dest + "/" + acenter);
    }
  }

  // EDCT Check dialog (TFMS/FSM Ch 13 - Figure 13-6)
  function showEdctCheckDialog(tr) {
    if (!tr) return;
    
    var acid = tr.getAttribute("data-acid") || "";
    var orig = tr.getAttribute("data-orig") || "";
    var dest = tr.getAttribute("data-dest") || "";
    var ctd = tr.getAttribute("data-ctd") || "";
    var cta = tr.getAttribute("data-cta") || "";
    var delay = tr.getAttribute("data-delay") || "0";

    var ctdFormatted = ctd ? formatZuluFromIso(ctd) : "-";
    var ctaFormatted = cta ? formatZuluFromIso(cta) : "-";

    var html = ''
      + '<div class="text-left">'
      +   '<p class="mb-3">Query EDCT status for flight <strong>' + escapeHtml(acid) + '</strong></p>'
      +   '<table class="table table-sm table-bordered"><tbody>'
      +     '<tr><th width="30%">ACID</th><td>' + escapeHtml(acid) + '</td></tr>'
      +     '<tr><th>Origin</th><td>' + escapeHtml(orig) + '</td></tr>'
      +     '<tr><th>Destination</th><td>' + escapeHtml(dest) + '</td></tr>'
      +   '</tbody></table>'
      +   '<hr>'
      +   '<h6>Current EDCT Status:</h6>'
      +   '<table class="table table-sm table-bordered"><tbody>'
      +     '<tr><th width="30%">CTD (EDCT)</th><td class="font-weight-bold">' + ctdFormatted + '</td></tr>'
      +     '<tr><th>CTA</th><td>' + ctaFormatted + '</td></tr>'
      +     '<tr><th>Delay</th><td class="' + (parseInt(delay) > 60 ? 'text-danger' : (parseInt(delay) > 30 ? 'text-warning' : '')) + '">' + delay + ' min</td></tr>'
      +   '</tbody></table>'
      +   '<div class="alert alert-info mt-3 mb-0"><small><i class="fas fa-info-circle mr-1"></i>This displays the current EDCT from the ADL snapshot. In a live TFMS environment, this would query the Hub for real-time EDCT data.</small></div>'
      + '</div>';

    if (window.Swal) {
      window.Swal.fire({
        title: '<i class="fas fa-search text-info mr-2"></i>EDCT Check',
        html: html,
        width: "50%",
        showConfirmButton: true,
        confirmButtonText: "Close",
        showCancelButton: false
      });
    } else {
      alert("EDCT Check for " + acid + "\nCTD: " + ctdFormatted + "\nCTA: " + ctaFormatted + "\nDelay: " + delay + " min");
    }
  }

  // EDCT Update dialog (TFMS/FSM Ch 13 - Figure 13-7)
  function showEdctUpdateDialog(tr) {
    if (!tr) return;
    
    var acid = tr.getAttribute("data-acid") || "";
    var orig = tr.getAttribute("data-orig") || "";
    var dest = tr.getAttribute("data-dest") || "";
    var ctd = tr.getAttribute("data-ctd") || "";

    var ctdFormatted = ctd ? formatZuluFromIso(ctd) : "-";

    // Calculate default new EDCT (current CTD + 15 min)
    var defaultNewEdct = "";
    if (ctd) {
      try {
        var ctdDate = new Date(ctd);
        if (!isNaN(ctdDate.getTime())) {
          ctdDate.setMinutes(ctdDate.getMinutes() + 15);
          defaultNewEdct = ctdDate.toISOString().slice(0, 16);
        }
      } catch (e) {}
    }

    var html = ''
      + '<div class="text-left">'
      +   '<p class="mb-3">Update EDCT for flight <strong>' + escapeHtml(acid) + '</strong></p>'
      +   '<table class="table table-sm table-bordered mb-3"><tbody>'
      +     '<tr><th width="30%">ACID</th><td>' + escapeHtml(acid) + '</td></tr>'
      +     '<tr><th>Origin</th><td>' + escapeHtml(orig) + '</td></tr>'
      +     '<tr><th>Destination</th><td>' + escapeHtml(dest) + '</td></tr>'
      +     '<tr><th>Current CTD</th><td class="font-weight-bold">' + ctdFormatted + '</td></tr>'
      +   '</tbody></table>'
      +   '<div class="form-group">'
      +     '<label for="edct_update_new_time"><strong>New EDCT (UTC):</strong></label>'
      +     '<input type="datetime-local" class="form-control" id="edct_update_new_time" value="' + defaultNewEdct + '">'
      +     '<small class="form-text text-muted">Enter the new Estimated Departure Clearance Time</small>'
      +   '</div>'
      +   '<div class="alert alert-warning mt-3 mb-0"><small><i class="fas fa-exclamation-triangle mr-1"></i>In a live TFMS environment, this would submit an EDCT update request to the Hub. This is a simulation environment.</small></div>'
      + '</div>';

    if (window.Swal) {
      window.Swal.fire({
        title: '<i class="fas fa-edit text-warning mr-2"></i>EDCT Update',
        html: html,
        width: "50%",
        showConfirmButton: true,
        confirmButtonText: "Send Update",
        showCancelButton: true,
        cancelButtonText: "Cancel",
        preConfirm: function() {
          var newEdctEl = document.getElementById("edct_update_new_time");
          var newEdct = newEdctEl ? newEdctEl.value : "";
          if (!newEdct) {
            window.Swal.showValidationMessage("Please enter a new EDCT time");
            return false;
          }
          return { acid: acid, orig: orig, dest: dest, newEdct: newEdct };
        }
      }).then(function(result) {
        if (result.isConfirmed && result.value) {
          var newEdctFormatted = formatZuluFromIso(result.value.newEdct);
          window.Swal.fire({
            icon: "info",
            title: "EDCT Update Simulated",
            html: "<p>In a live environment, the following update would be sent:</p>" +
                  "<p><strong>Flight:</strong> " + escapeHtml(result.value.acid) + "<br>" +
                  "<strong>New EDCT:</strong> " + newEdctFormatted + "</p>" +
                  "<p class='text-muted small'>This is a simulation - no actual update was sent to TFMS.</p>"
          });
        }
      });
    } else {
      alert("EDCT Update for " + acid + "\nCurrent CTD: " + ctdFormatted + "\n\nThis is a simulation environment.");
    }
  }

  // Open ECR modal for a flight from Flight List context menu (TFMS/FSM Ch 14)
  function openEcrFromFlightList(tr) {
    if (!tr) return;
    
    var acid = tr.getAttribute("data-acid") || "";
    var orig = tr.getAttribute("data-orig") || "";
    var dest = tr.getAttribute("data-dest") || "";

    // Close the flight list modal first
    if (window.jQuery) {
      window.jQuery("#gs_flight_list_modal").modal("hide");
    }

    // Use the existing openEcrForFlight function (defined later in the file)
    setTimeout(function() {
      var acidEl = document.getElementById("ecr_acid");
      var origEl = document.getElementById("ecr_orig");
      var destEl = document.getElementById("ecr_dest");

      if (acidEl) acidEl.value = acid;
      if (origEl) origEl.value = orig;
      if (destEl) destEl.value = dest;

      // Open ECR modal
      if (window.jQuery) {
        window.jQuery("#ecr_modal").modal("show");
      }

      // Trigger Get Flight Data
      setTimeout(function() {
        var getFlightBtn = document.getElementById("ecr_get_flight_btn");
        if (getFlightBtn) {
          getFlightBtn.click();
        }
      }, 300);
    }, 200);
  }

  // Initialize Flight List context menu when modal is shown
  document.addEventListener("DOMContentLoaded", function() {
    // Initialize when modal opens
    var flightListModal = document.getElementById("gs_flight_list_modal");
    if (flightListModal && window.jQuery) {
      window.jQuery(flightListModal).on("shown.bs.modal", function() {
        initGsFlightListContextMenu();
      });
    }
  });


  
  function syncTimeFiltersWithGsPeriod() {
    var gsStartEl = document.getElementById("gs_start");
    var gsEndEl = document.getElementById("gs_end");
    var tBasis = document.getElementById("gs_time_basis");
    var tStart = document.getElementById("gs_time_start");
    var tEnd = document.getElementById("gs_time_end");

    if (!gsStartEl || !gsEndEl || !tStart || !tEnd) return;

    var gsStartVal = gsStartEl.value || "";
    var gsEndVal = gsEndEl.value || "";

    // Only update filter window if GS period is populated
    if (gsStartVal) {
      tStart.value = gsStartVal;
    }
    if (gsEndVal) {
      tEnd.value = gsEndVal;
    }

    // If no basis chosen yet, default to ETA so the window is actually applied
    if (tBasis && (!tBasis.value || tBasis.value === "NONE")) {
      tBasis.value = "ETA";
    }
  }


function applyGroundStopEdctToTable() {
    var gsEndEl = document.getElementById("gs_end");
    var tbody = document.getElementById("gs_flight_table_body");
    if (!gsEndEl || !tbody) return;

    var gsEndStr = gsEndEl.value || "";
    if (!gsEndStr) return;
    var gsEndEpoch = parseUtcLocalToEpoch(gsEndStr);
    if (!gsEndEpoch) return;

    var apStr = getValue("gs_airports") || "";
    var apTokens = apStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });

    var rows = Array.prototype.slice.call(tbody.querySelectorAll("tr"));
    if (!rows.length) return;

    rows.forEach(function(tr) {
        if (!tr) return;

        // Use filed departure if available, otherwise fall back to ETD
        var filedStr = tr.getAttribute("data-filed-dep-epoch") || "";
        var etdStr   = tr.getAttribute("data-etd-epoch") || "";
        var baselineStr = filedStr || etdStr;
        if (!baselineStr) return;

        var baseline = parseInt(baselineStr, 10);
        if (!baseline || isNaN(baseline)) return;

        // Only apply GS to flights whose baseline departure is before the GS end time
        if (baseline >= gsEndEpoch) return;

        // If AFFECTED AIRPORTS are specified, require destination to match
        if (apTokens.length) {
            var cells = tr.querySelectorAll("td");
            if (!cells || cells.length < 7) return;
            var dest = (cells[6].textContent || "").trim().toUpperCase();
            if (apTokens.indexOf(dest) === -1) return;
        }

        var existingEdctStr = tr.getAttribute("data-edct-epoch") || "";
        var existingEdct = existingEdctStr ? parseInt(existingEdctStr, 10) : NaN;
        // If an existing EDCT is already later than the GS end, keep it
        if (!isNaN(existingEdct) && existingEdct > gsEndEpoch) {
            return;
        }

        tr.setAttribute("data-edct-epoch", String(gsEndEpoch));

        var etdCell = tr.querySelector(".gs_etd_cell");
        if (etdCell) {
            var baseEtd = formatZuluFromEpoch(gsEndEpoch);
            etdCell.textContent = "E " + baseEtd;
        }

        var edctCell = tr.querySelector(".gs_edct_cell");
        if (edctCell) {
            edctCell.textContent = formatZuluFromEpoch(gsEndEpoch);
        }

        // If we know an ETE for this flight, compute a GS-controlled ETA
        // as EDCT + ETE and update the ETA cell + data-eta-epoch so that
        // the table and flight info remain consistent.
        var eteStr = tr.getAttribute("data-ete-minutes") || "";
        var eteMinutes = eteStr ? parseFloat(eteStr) : NaN;
        if (!isNaN(eteMinutes) && eteMinutes > 0) {
            var newEtaEpoch = gsEndEpoch + eteMinutes * 60 * 1000;
            tr.setAttribute("data-eta-epoch", String(newEtaEpoch));
            var etaCell = tr.querySelector(".gs_eta_cell");
            if (etaCell) {
                var baseEta = formatZuluFromEpoch(newEtaEpoch);
                etaCell.textContent = "C " + baseEta;
            }
        }
    });
}
function applyTimeFilterToTable() {
    // NOTE: Local, client-side EDCT simulation is intentionally disabled for the GS workflow.
    // The authoritative simulation/apply path is via the GS API endpoints (gs_simulate.php / gs_apply.php).
    var basisEl = document.getElementById("gs_time_basis");
    var basis = basisEl ? basisEl.value : "NONE";
    var startStr = document.getElementById("gs_time_start") ? document.getElementById("gs_time_start").value : "";
    var endStr = document.getElementById("gs_time_end") ? document.getElementById("gs_time_end").value : "";

    var tbody = document.getElementById("gs_flight_table_body");
    if (!tbody) {
      return;
    }

    var rows = Array.prototype.slice.call(tbody.querySelectorAll("tr"));
    if (!rows.length) {
      summarizeFlights([]);
      return;
    }

    var startEpoch = startStr ? parseUtcLocalToEpoch(startStr) : null;
    var endEpoch = endStr ? parseUtcLocalToEpoch(endStr) : null;

    // If no basis or no window, show all rows and summarize them all
    if (!basis || basis === "NONE" || (!startEpoch && !endEpoch)) {
      rows.forEach(function(tr) {
        tr.style.display = "";
      });
      var allSummaries = rows.map(extractRowSummary).filter(function(r) { return !!r; });
      summarizeFlights(allSummaries);
      updateHorizonCounts(rows, "data-eta-epoch");
      updateDelayStats(rows);
      updateDelayBreakdowns(rows);
      return;
    }

    var attrName = null;
    if (basis === "EDCT") attrName = "data-edct-epoch";
    else if (basis === "ETA") attrName = "data-eta-epoch";
    else if (basis === "TAKEOFF") attrName = "data-takeoff-epoch";
    else if (basis === "MFT") attrName = "data-mft-epoch";
    else if (basis === "VT") attrName = "data-vt-epoch";

    var visibleRows = [];

    rows.forEach(function(tr) {
      if (!attrName) {
        tr.style.display = "";
        visibleRows.push(tr);
        return;
      }
      var v = tr.getAttribute(attrName);
      if (!v) {
        // No timing data yet: keep visible so the user can still see it
        tr.style.display = "";
        visibleRows.push(tr);
        return;
      }
      var t = parseInt(v, 10);
      var ok = true;
      if (startEpoch !== null && !isNaN(startEpoch) && t < startEpoch) ok = false;
      if (endEpoch !== null && !isNaN(endEpoch) && t > endEpoch) ok = false;
      tr.style.display = ok ? "" : "none";
      if (ok) visibleRows.push(tr);
    });

    var summaries = visibleRows.map(extractRowSummary).filter(function(r) { return !!r; });
    summarizeFlights(summaries);
    updateHorizonCounts(visibleRows, attrName);
    updateDelayStats(visibleRows);
  }

function collectGsCtdPayload() {
        var tbody = document.getElementById("gs_flight_table_body");
        if (!tbody) {
            return { gs_end_utc: null, updates: [] };
        }

        var gsEndEl = document.getElementById("gs_end");
        var gsEndStr = gsEndEl ? (gsEndEl.value || "") : "";
        var gsEndEpoch = gsEndStr ? parseUtcLocalToEpoch(gsEndStr) : null;

        var rows = Array.prototype.slice.call(tbody.querySelectorAll("tr"));
        var updates = [];

        rows.forEach(function(tr) {
            if (!tr) return;

            var edctStr = tr.getAttribute("data-edct-epoch") || "";
            if (!edctStr) return;

            // Use ETD if present, otherwise fall back to filed departure
            var etdStr = tr.getAttribute("data-etd-epoch") || "";
            var filedStr = tr.getAttribute("data-filed-dep-epoch") || "";
            var baselineStr = etdStr || filedStr;
            if (!baselineStr) return;

            var edct = parseInt(edctStr, 10);
            var baseline = parseInt(baselineStr, 10);
            if (!edct || isNaN(edct) || !baseline || isNaN(baseline)) return;

            // Only send updates for flights with a positive GS delay
            if (edct <= baseline) return;

            var tds = tr.querySelectorAll("td");
            if (!tds || tds.length < 7) return;

            var callsign = (tds[0].textContent || "").trim().toUpperCase();
            var dep = (tds[5].textContent || "").trim().toUpperCase();
            var dest = (tds[6].textContent || "").trim().toUpperCase();
            if (!callsign) return;

            updates.push({
                callsign: callsign,
                dep_icao: dep || null,
                dest_icao: dest || null,
                ctd_utc: formatSqlUtcFromEpoch(edct)
            });
        });

        return {
            gs_end_utc: gsEndEpoch ? formatSqlUtcFromEpoch(gsEndEpoch) : null,
            updates: updates
        };
    }

// ---------------------------------------------------------------------
// GS workflow (ADL / GS sandbox) helpers
// ---------------------------------------------------------------------

function toUtcIsoNoMillis(epochMs) {
        if (epochMs == null || isNaN(epochMs)) return null;
        // 2025-12-17T12:34:56.789Z -> 2025-12-17T12:34:56Z
        return new Date(epochMs).toISOString().replace(/\.\d{3}Z$/, "Z");
    }

function utcIsoFromDatetimeLocal(dtLocalStr) {
        if (!dtLocalStr) return null;
        var epoch = parseUtcLocalToEpoch(dtLocalStr);
        if (epoch == null || isNaN(epoch)) return null;
        return toUtcIsoNoMillis(epoch);
    }

function normalizeSqlsrvDateValue(v) {
        if (!v) return v;
        if (typeof v === "string" || typeof v === "number" || typeof v === "boolean") return v;

        // SQLSRV DateTime objects are often encoded by PHP as:
        // { "date": "YYYY-MM-DD HH:MM:SS.000000", "timezone_type": 3, "timezone": "UTC" }
        if (typeof v === "object" && typeof v.date === "string") {
            var s = String(v.date).trim();
            if (!s) return s;
            s = s.split(".")[0]; // strip fractional seconds
            if (s.indexOf("T") === -1) s = s.replace(" ", "T");
            if (!/[zZ]$/.test(s)) s += "Z";
            return s;
        }
        return v;
    }

function normalizeSqlsrvRow(row) {
        if (!row || typeof row !== "object") return row;
        var out = {};
        Object.keys(row).forEach(function(k) {
            out[k] = normalizeSqlsrvDateValue(row[k]);
        });
        return out;
    }

function normalizeSqlsrvRows(rows) {
        if (!Array.isArray(rows)) return [];
        return rows.map(normalizeSqlsrvRow);
    }

function setGsTableMode(mode) {
        mode = (mode || "").toUpperCase();
        if (mode !== "GS") mode = "LIVE";
        GS_TABLE_MODE = mode;

        var badge = document.getElementById("gs_adl_mode_badge");
        if (badge) {
            if (mode === "GS") {
                badge.textContent = "ADL: GS";
                badge.classList.remove("badge-secondary", "badge-success");
                badge.classList.add("badge-warning");
            } else {
                badge.textContent = "ADL: LIVE";
                badge.classList.remove("badge-secondary", "badge-warning");
                badge.classList.add("badge-success");
            }
        }

        updateDataSourceLabel();
    }

    // Enable or disable the "Send Actual" button based on simulation state
    function setSendActualEnabled(enabled, reason) {
        GS_SIMULATION_READY = !!enabled;
        var btn = document.getElementById("gs_send_actual_btn");
        if (!btn) return;

        if (enabled) {
            btn.disabled = false;
            btn.classList.remove("btn-outline-secondary");
            btn.classList.add("btn-outline-success");
            btn.title = "Apply simulated GS to live ADL";
        } else {
            btn.disabled = true;
            btn.classList.remove("btn-outline-success");
            btn.classList.add("btn-outline-secondary");
            btn.title = reason || "Run 'Simulate' first to enable";
        }
    }

function getMultiSelectValues(selectEl) {
        if (!selectEl || !selectEl.selectedOptions) return [];
        var out = [];
        Array.prototype.forEach.call(selectEl.selectedOptions, function(opt) {
            if (opt && opt.value) out.push(String(opt.value).trim());
        });
        return out.filter(function(x) { return !!x; });
    }

function collectGsWorkflowPayload() {
        // Collect the shared GS payload for preview/simulate/apply/purge flows.
        var payload = {
            gs_name: getValue("gs_name"),
            gs_ctl_element: getValue("gs_ctl_element"),
            gs_element_type: getValue("gs_element_type"),
            gs_adv_number: getValue("gs_adv_number"),

            gs_start: utcIsoFromDatetimeLocal(getValue("gs_start")),
            gs_end: utcIsoFromDatetimeLocal(getValue("gs_end")),

            // Airport tokens (expand centers/TRACONs into airport lists for server-side filters)
            gs_airports: (function() {
                var raw = (getValue("gs_airports") || "").toUpperCase();
                var toks = raw.split(/\s+/).filter(function(x) { return x.length > 0; });
                var expanded = expandAirportTokensWithFacilities(toks);
                return expanded.join(" ");
            })(),
            gs_origin_airports: (function() {
                var raw = (getValue("gs_origin_airports") || "").toUpperCase();
                var toks = raw.split(/\s+/).filter(function(x) { return x.length > 0; });
                var expanded = expandAirportTokensWithFacilities(toks);
                return expanded.join(" ");
            })(),

            // Scope / inclusion
            gs_scope_select: (function() {
                var sel = document.getElementById("gs_scope_select");
                return getMultiSelectValues(sel);
            })(),
            gs_dep_facilities: getValue("gs_dep_facilities"),
            gs_flt_incl_carrier: getValue("gs_flt_incl_carrier"),
            gs_flt_incl_type: getValue("gs_flt_incl_type"),

            // Advisory narrative fields (not required by the API, but useful for logging)
            gs_prob_ext: getValue("gs_prob_ext"),
            gs_impacting_condition: getValue("gs_impacting_condition"),
            gs_comments: getValue("gs_comments"),

            // Flight Exemptions (FSM User Guide exemption criteria)
            exemptions: collectExemptionRules()
        };

        // Include legacy origin-centers hidden field if present
        var originCenters = getValue("gs_origin_centers");
        if (originCenters) payload.gs_origin_centers = originCenters;

        return payload;
    }

    // Collect exemption rules from the UI
    function collectExemptionRules() {
        var rules = {
            // Origin exemptions
            orig_airports: parseSpaceSeparated(getValue("gs_exempt_orig_airports")),
            orig_tracons: parseSpaceSeparated(getValue("gs_exempt_orig_tracons")),
            orig_artccs: parseSpaceSeparated(getValue("gs_exempt_orig_artccs")),
            
            // Destination exemptions
            dest_airports: parseSpaceSeparated(getValue("gs_exempt_dest_airports")),
            dest_tracons: parseSpaceSeparated(getValue("gs_exempt_dest_tracons")),
            dest_artccs: parseSpaceSeparated(getValue("gs_exempt_dest_artccs")),
            
            // Aircraft type exemptions
            exempt_jet: isChecked("gs_exempt_type_jet"),
            exempt_turboprop: isChecked("gs_exempt_type_turboprop"),
            exempt_prop: isChecked("gs_exempt_type_prop"),
            
            // Status exemptions
            exempt_has_edct: isChecked("gs_exempt_has_edct"),
            exempt_active_only: isChecked("gs_exempt_active_only"),
            exempt_depart_within: parseInt(getValue("gs_exempt_depart_within"), 10) || 0,
            
            // Altitude exemptions
            exempt_alt_below: parseInt(getValue("gs_exempt_alt_below"), 10) || 0,
            exempt_alt_above: parseInt(getValue("gs_exempt_alt_above"), 10) || 0,
            
            // Individual flight exemptions
            exempt_flights: parseSpaceSeparated(getValue("gs_exempt_flights"))
        };
        
        return rules;
    }

    function parseSpaceSeparated(str) {
        if (!str) return [];
        return str.toUpperCase().split(/[\s,;]+/).filter(function(x) { return x.length > 0; });
    }

    function isChecked(id) {
        var el = document.getElementById(id);
        return el ? el.checked : false;
    }

    // Check if a flight should be exempted based on current rules
    function isFlightExempted(flight, exemptions) {
        if (!exemptions) return false;
        
        var dep = (flight.dep || flight.fp_dept_icao || "").toUpperCase();
        var arr = (flight.arr || flight.fp_dest_icao || "").toUpperCase();
        var depCenter = (flight.dep_center || flight.fp_dept_artcc || AIRPORT_CENTER_MAP[dep] || "").toUpperCase();
        var arrCenter = (flight.arr_center || flight.fp_dest_artcc || AIRPORT_CENTER_MAP[arr] || "").toUpperCase();
        var depTracon = (flight.dep_tracon || AIRPORT_TRACON_MAP[dep] || "").toUpperCase();
        var arrTracon = (flight.arr_tracon || AIRPORT_TRACON_MAP[arr] || "").toUpperCase();
        var callsign = (flight.callsign || "").toUpperCase();
        var hasEdct = !!(flight.edctEpoch || flight.ctd_utc || flight.CTD_UTC);
        var altitude = parseInt(flight.altitude || flight.filed_altitude || 0, 10);
        
        // Derive aircraft type from equipment code if available
        var acftType = (flight.acft_type || flight.aircraft_type || "").toUpperCase();
        
        var reason = null;
        
        // Check origin airport exemption
        if (exemptions.orig_airports && exemptions.orig_airports.length > 0) {
            if (exemptions.orig_airports.indexOf(dep) >= 0) {
                reason = "Exempted by Departing Airport";
            }
        }
        
        // Check origin TRACON exemption
        if (!reason && exemptions.orig_tracons && exemptions.orig_tracons.length > 0) {
            if (depTracon && exemptions.orig_tracons.indexOf(depTracon) >= 0) {
                reason = "Exempted by Departing TRACON";
            }
        }
        
        // Check origin ARTCC exemption
        if (!reason && exemptions.orig_artccs && exemptions.orig_artccs.length > 0) {
            if (depCenter && exemptions.orig_artccs.indexOf(depCenter) >= 0) {
                reason = "Exempted by Departing Center";
            }
        }
        
        // Check destination airport exemption
        if (!reason && exemptions.dest_airports && exemptions.dest_airports.length > 0) {
            if (exemptions.dest_airports.indexOf(arr) >= 0) {
                reason = "Exempted by Destination Airport";
            }
        }
        
        // Check destination TRACON exemption
        if (!reason && exemptions.dest_tracons && exemptions.dest_tracons.length > 0) {
            if (arrTracon && exemptions.dest_tracons.indexOf(arrTracon) >= 0) {
                reason = "Exempted by Destination TRACON";
            }
        }
        
        // Check destination ARTCC exemption
        if (!reason && exemptions.dest_artccs && exemptions.dest_artccs.length > 0) {
            if (arrCenter && exemptions.dest_artccs.indexOf(arrCenter) >= 0) {
                reason = "Exempted by Destination Center";
            }
        }
        
        // Check aircraft type exemptions
        if (!reason) {
            if (exemptions.exempt_jet && isJetAircraft(acftType)) {
                reason = "Excluded by Aircraft Type (Jet)";
            } else if (exemptions.exempt_turboprop && isTurbopropAircraft(acftType)) {
                reason = "Excluded by Aircraft Type (Turboprop)";
            } else if (exemptions.exempt_prop && isPropAircraft(acftType)) {
                reason = "Excluded by Aircraft Type (Prop)";
            }
        }
        
        // Check existing EDCT exemption
        if (!reason && exemptions.exempt_has_edct && hasEdct) {
            reason = "Exempted by Existing EDCT";
        }
        
        // Check altitude exemptions
        if (!reason && exemptions.exempt_alt_below > 0) {
            if (altitude > 0 && altitude < exemptions.exempt_alt_below * 100) {
                reason = "Exempted by Altitude (Below FL" + exemptions.exempt_alt_below + ")";
            }
        }
        if (!reason && exemptions.exempt_alt_above > 0) {
            if (altitude > 0 && altitude > exemptions.exempt_alt_above * 100) {
                reason = "Exempted by Altitude (Above FL" + exemptions.exempt_alt_above + ")";
            }
        }
        
        // Check individual flight exemption
        if (!reason && exemptions.exempt_flights && exemptions.exempt_flights.length > 0) {
            if (exemptions.exempt_flights.indexOf(callsign) >= 0) {
                reason = "Exempted by Specific Flight";
            }
        }
        
        // Check departing within minutes exemption
        if (!reason && exemptions.exempt_depart_within > 0) {
            var nowEpoch = Date.now() / 1000;
            var etdEpoch = flight.etdEpoch || 0;
            if (etdEpoch > 0) {
                var minutesUntilDep = (etdEpoch - nowEpoch) / 60;
                if (minutesUntilDep >= 0 && minutesUntilDep <= exemptions.exempt_depart_within) {
                    reason = "Exempted by Departure Time (within " + exemptions.exempt_depart_within + " min)";
                }
            }
        }
        
        return reason; // null if not exempted, otherwise the reason string
    }

    // Aircraft type detection helpers
    function isJetAircraft(type) {
        if (!type) return false;
        // Common jet prefixes/patterns
        var jetPatterns = /^(B7|A3|A2|B73|B74|B75|B76|B77|B78|A31|A32|A33|A34|A35|A38|CRJ|E1|E2|E7|E9|MD|DC|GLF|C5|C17|CL|LJ|H25|F9|FA|GALX|G[1-6])/i;
        return jetPatterns.test(type);
    }

    function isTurbopropAircraft(type) {
        if (!type) return false;
        var tpPatterns = /^(AT[4-7]|DH8|B19|SF3|E12|PC12|C208|PAY|SW[234]|J31|J41|BE[19]|DHC|D328)/i;
        return tpPatterns.test(type);
    }

    function isPropAircraft(type) {
        if (!type) return false;
        // If not jet or turboprop, and has common prop patterns
        if (isJetAircraft(type) || isTurbopropAircraft(type)) return false;
        var propPatterns = /^(C1[2-8]|C20|C21|PA|BE[2-6]|M20|SR2|DA[24]|P28|AA[15]|C17[02]|C18[02]|C206|C210)/i;
        return propPatterns.test(type);
    }

    // Update exemption count badge and summary
    function updateExemptionSummary() {
        var rules = collectExemptionRules();
        var count = 0;
        var summary = [];
        
        if (rules.orig_airports.length > 0) { count++; summary.push("Orig Apts: " + rules.orig_airports.join(", ")); }
        if (rules.orig_tracons.length > 0) { count++; summary.push("Orig TRACONs: " + rules.orig_tracons.join(", ")); }
        if (rules.orig_artccs.length > 0) { count++; summary.push("Orig ARTCCs: " + rules.orig_artccs.join(", ")); }
        if (rules.dest_airports.length > 0) { count++; summary.push("Dest Apts: " + rules.dest_airports.join(", ")); }
        if (rules.dest_tracons.length > 0) { count++; summary.push("Dest TRACONs: " + rules.dest_tracons.join(", ")); }
        if (rules.dest_artccs.length > 0) { count++; summary.push("Dest ARTCCs: " + rules.dest_artccs.join(", ")); }
        if (rules.exempt_jet) { count++; summary.push("Aircraft: Jet"); }
        if (rules.exempt_turboprop) { count++; summary.push("Aircraft: Turboprop"); }
        if (rules.exempt_prop) { count++; summary.push("Aircraft: Prop"); }
        if (rules.exempt_has_edct) { count++; summary.push("Has existing EDCT"); }
        if (rules.exempt_active_only) { count++; summary.push("Active flights only"); }
        if (rules.exempt_depart_within > 0) { count++; summary.push("Departing within " + rules.exempt_depart_within + " min"); }
        if (rules.exempt_alt_below > 0) { count++; summary.push("Below FL" + rules.exempt_alt_below); }
        if (rules.exempt_alt_above > 0) { count++; summary.push("Above FL" + rules.exempt_alt_above); }
        if (rules.exempt_flights.length > 0) { count++; summary.push("Flights: " + rules.exempt_flights.join(", ")); }
        
        var badge = document.getElementById("gs_exemption_count_badge");
        if (badge) {
            badge.textContent = count + " rule" + (count !== 1 ? "s" : "");
        }
        
        var summaryEl = document.getElementById("gs_exemption_summary");
        if (summaryEl) {
            if (summary.length > 0) {
                summaryEl.innerHTML = '<span class="text-success"><i class="fas fa-check-circle mr-1"></i>' + summary.join(" | ") + '</span>';
            } else {
                summaryEl.innerHTML = '<span class="text-muted">No exemption rules configured.</span>';
            }
        }
    }

function apiPostJson(url, payload) {
        return fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload || {})
        }).then(function(res) {
            if (!res.ok) {
                return res.text().then(function(t) {
                    throw new Error("HTTP " + res.status + " " + res.statusText + (t ? (": " + t) : ""));
                });
            }
            return res.json();
        });
    }

function clearGsFlightTable(message) {
        var tbody = document.getElementById("gs_flight_table_body");
        if (!tbody) return;
        var msg = message || "No flights loaded.";
        tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-3">' + escapeHtml(msg) + "</td></tr>";
        summarizeFlights([]);
        updateDelayStats([]);
        updateHorizonCounts([], "data-eta-epoch");
        updateDelayBreakdowns([]);
        
        // Reset flight count badge
        var countBadge = document.getElementById("gs_flight_count_badge");
        if (countBadge) countBadge.textContent = "0";
    }

function renderFlightsFromAdlRowsForWorkflow(adlRows, sourceLabel) {
        var tbody = document.getElementById("gs_flight_table_body");
        var countBadge = document.getElementById("gs_flight_count_badge");
        if (!tbody) return;

        // Store raw ADL rows for sorting functionality
        GS_MATCHING_FLIGHTS = adlRows || [];

        // Determine airport coloring based on the user's AFFECTED AIRPORTS input
        var apStr = getValue("gs_airports") || "";
        var apTokens = apStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });
        var airports = expandAirportTokensWithFacilities(apTokens);
        var airportColors = buildAirportColorMap(airports);
        updateAirportsLegendAndInput(airports, airportColors);

        // Display-side filters (API already filters, but this keeps the UI consistent)
        var originStr = getValue("gs_origin_airports") || "";
        var originTokens = originStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });
        var originAirports = expandAirportTokensWithFacilities(originTokens);

        var carriers = getValue("gs_flt_incl_carrier");
        var acTypeEl = document.getElementById("gs_flt_incl_type");
        var acType = acTypeEl ? acTypeEl.value : "ALL";

        var cfg = {
            arrivals: airports,
            originAirports: originAirports,
            carriers: carriers,
            acType: acType
        };

        var rows = [];
        (adlRows || []).forEach(function(raw) {
            if (!raw) return;
            var r = filterAdlFlight(raw, cfg);
            if (!r) return;

            r.source = sourceLabel || r.source || "";
            r._adl = { raw: raw, filtered: r };
            rows.push(r);
        });

        // Apply current sort order
        var field = GS_MATCHING_SORT.field;
        var order = GS_MATCHING_SORT.order;
        rows.sort(function(a, b) {
            var valA, valB;
            switch (field) {
                case "acid":
                    valA = (a.callsign || "").toLowerCase();
                    valB = (b.callsign || "").toLowerCase();
                    break;
                case "etd":
                    valA = a.etdEpoch || 0;
                    valB = b.etdEpoch || 0;
                    break;
                case "edct":
                    valA = a.edctEpoch || 0;
                    valB = b.edctEpoch || 0;
                    break;
                case "eta":
                    valA = a.roughEtaEpoch || 0;
                    valB = b.roughEtaEpoch || 0;
                    break;
                case "dcenter":
                    valA = (AIRPORT_CENTER_MAP[a.dep] || "").toLowerCase();
                    valB = (AIRPORT_CENTER_MAP[b.dep] || "").toLowerCase();
                    break;
                case "orig":
                    valA = (a.dep || "").toLowerCase();
                    valB = (b.dep || "").toLowerCase();
                    break;
                case "dest":
                    valA = (a.arr || "").toLowerCase();
                    valB = (b.arr || "").toLowerCase();
                    break;
                default:
                    valA = a.roughEtaEpoch || Number.MAX_SAFE_INTEGER;
                    valB = b.roughEtaEpoch || Number.MAX_SAFE_INTEGER;
            }
            if (valA < valB) return order === "asc" ? -1 : 1;
            if (valA > valB) return order === "asc" ? 1 : -1;
            return (a.callsign || "").localeCompare(b.callsign || "");
        });

        // Store processed rows for re-sorting
        GS_MATCHING_ROWS = rows;

        // Update flight count badge
        if (countBadge) {
            countBadge.textContent = rows.length;
        }

        GS_FLIGHT_ROW_INDEX = {};
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-3">No flights match the current filters.</td></tr>';
            applyTimeFilterToTable();
            return;
        }

        var html = "";
        rows.forEach(function(r) {
            var cs = r.callsign || "";
            var rowId = makeRowIdForCallsign(cs);
            GS_FLIGHT_ROW_INDEX[rowId] = r;

            var dep = (r.dep || "").toUpperCase();
            var arr = (r.arr || "").toUpperCase();
            var center = AIRPORT_CENTER_MAP[dep] || "";
            var destColor = airportColors[arr] || "";

            // Build status column from control type and delay status
            var statusText = "";
            if (r._adl && r._adl.raw) {
                var ctlType = r._adl.raw.ctl_type || r._adl.raw.CTL_TYPE || "";
                var delayStatus = r._adl.raw.delay_status || r._adl.raw.DELAY_STATUS || "";
                var flightStatus = r.flightStatus || "";
                statusText = ctlType || delayStatus || flightStatus || "";
            }

            html += '<tr id="' + escapeHtml(rowId) + '" ' +
                'data-callsign="' + escapeHtml(cs) + '" ' +
                'data-orig="' + escapeHtml(dep) + '" ' +
                'data-dest="' + escapeHtml(arr) + '" ' +
                'data-route="' + escapeHtml(r.route || "") + '" ' +
                'data-filed-dep-epoch="' + (r.filedDepEpoch != null ? String(r.filedDepEpoch) : "") + '" ' +
                'data-etd-epoch="' + (r.etdEpoch != null ? String(r.etdEpoch) : "") + '" ' +
                'data-edct-epoch="' + (r.edctEpoch != null ? String(r.edctEpoch) : "") + '" ' +
                'data-eta-epoch="' + (r.roughEtaEpoch != null ? String(r.roughEtaEpoch) : "") + '" ' +
                'data-takeoff-epoch="' + (r.tkofEpoch != null ? String(r.tkofEpoch) : "") + '" ' +
                'data-mft-epoch="' + (r.mftEpoch != null ? String(r.mftEpoch) : "") + '" ' +
                'data-vt-epoch="' + (r.vtEpoch != null ? String(r.vtEpoch) : "") + '" ' +
                'data-ete-minutes="' + (r.eteMinutes != null ? String(r.eteMinutes) : "") + '" ' +
                'class="gs-flight-row"' +
                ">" +
                "<td><strong>" + escapeHtml(cs) + "</strong></td>" +
                '<td class="gs_etd_cell">' + (r.etdEpoch ? (escapeHtml(r.etdPrefix || "") + " " + escapeHtml(formatZuluFromEpoch(r.etdEpoch))) : "") + "</td>" +
                '<td class="gs_edct_cell">' + (r.edctEpoch ? escapeHtml(formatZuluFromEpoch(r.edctEpoch)) : "") + "</td>" +
                '<td class="gs_eta_cell" style="color:' + escapeHtml(destColor) + ';">' +
                    (r.roughEtaEpoch ? (escapeHtml(r.etaPrefix || "") + " " + escapeHtml(formatZuluFromEpoch(r.roughEtaEpoch))) : "") + "</td>" +
                "<td>" + escapeHtml(center) + "</td>" +
                "<td>" + escapeHtml(dep) + "</td>" +
                '<td style="color:' + escapeHtml(destColor) + ';">' + escapeHtml(arr) + "</td>" +
                "<td>" + escapeHtml(statusText) + ' <a href="#" class="ecr-link text-info ml-1" title="EDCT Change Request" style="font-size:0.85em;"><i class="fas fa-clock"></i></a></td>' +
                "</tr>";
        });

        tbody.innerHTML = html;

        // Apply SimTraffic enrichment opportunistically
        enrichFlightsWithSimTraffic(rows);

        applyTimeFilterToTable();
    }

function showConfirmDialog(title, text, confirmText, icon) {
        if (window.Swal) {
            return window.Swal.fire({
                title: title,
                text: text || "",
                icon: icon || "warning",
                showCancelButton: true,
                confirmButtonText: confirmText || "Confirm"
            }).then(function(result) {
                return !!(result && result.isConfirmed);
            });
        }
        return Promise.resolve(window.confirm((title ? title + "\\n\\n" : "") + (text || "")));
    }

function handleGsPreview() {
        var statusEl = document.getElementById("gs_adl_status");
        if (statusEl) statusEl.textContent = "Creating GS program and modeling flights...";

        setGsTableMode("LIVE");
        var workflowPayload = collectGsWorkflowPayload();

        // Validate required fields
        if (!workflowPayload.gs_airports) {
            if (statusEl) statusEl.textContent = "Enter affected airports first.";
            return Promise.resolve();
        }
        if (!workflowPayload.gs_start || !workflowPayload.gs_end) {
            if (statusEl) statusEl.textContent = "Enter GS start and end times.";
            return Promise.resolve();
        }

        // Build create payload for new API
        var createPayload = {
            ctl_element: workflowPayload.gs_ctl_element || workflowPayload.gs_airports.split(" ")[0],
            start_utc: workflowPayload.gs_start,
            end_utc: workflowPayload.gs_end,
            scope_type: "TIER",
            scope_tier: 2, // Default to Tier 2, can be enhanced later from gs_scope_select
            exempt_airborne: true,
            impacting_condition: workflowPayload.gs_impacting_condition || "WEATHER",
            cause_text: workflowPayload.gs_comments || "Ground Stop",
            created_by: "TMU"
        };

        // Step 1: Create PROPOSED program
        return apiPostJson(GS_API.create, createPayload)
            .then(function(createResp) {
                if (createResp.status !== "ok" || !createResp.data || !createResp.data.program_id) {
                    throw new Error(createResp.message || "Failed to create GS program");
                }

                var programId = createResp.data.program_id;
                GS_CURRENT_PROGRAM_ID = programId;
                GS_CURRENT_PROGRAM_STATUS = "PROPOSED";

                if (statusEl) statusEl.textContent = "Program " + programId + " created. Modeling flights...";

                // Step 2: Model the program to get affected flights
                return apiPostJson(GS_API.model, { program_id: programId });
            })
            .then(function(modelResp) {
                if (modelResp.status !== "ok") {
                    throw new Error(modelResp.message || "Failed to model GS program");
                }

                var flights = (modelResp.data && modelResp.data.flights) || [];
                flights = normalizeSqlsrvRows(flights);

                // Store simulation data for flight list
                storeSimulationData(modelResp.data);

                renderFlightsFromAdlRowsForWorkflow(flights, "GS-PREVIEW");

                if (statusEl) {
                    var summary = modelResp.data.summary || {};
                    statusEl.textContent = "Preview: " + flights.length + " flights | " +
                        "Controlled: " + (summary.controlled_flights || 0) + " | " +
                        "Exempt: " + (summary.exempt_flights || 0) + " | " +
                        "Program ID: " + GS_CURRENT_PROGRAM_ID;
                }
                buildAdvisory();

                // Enable simulate since we have a PROPOSED program
                setSendActualEnabled(false, "Run 'Simulate' to finalize before sending");
            })
            .catch(function(err) {
                console.error("GS preview failed", err);
                if (statusEl) statusEl.textContent = "Preview failed: " + (err && err.message ? err.message : err);
                clearGsFlightTable("Preview failed.");
                GS_CURRENT_PROGRAM_ID = null;
                GS_CURRENT_PROGRAM_STATUS = null;
            });
    }

function handleGsSimulate() {
        var statusEl = document.getElementById("gs_adl_status");
        
        // If no program exists yet, run Preview first to create one
        if (!GS_CURRENT_PROGRAM_ID) {
            if (statusEl) statusEl.textContent = "No program exists. Creating via Preview first...";
            return handleGsPreview().then(function() {
                if (GS_CURRENT_PROGRAM_ID) {
                    // Now run simulate with the new program
                    return handleGsSimulate();
                }
            });
        }

        if (statusEl) statusEl.textContent = "Modeling GS program " + GS_CURRENT_PROGRAM_ID + "...";

        // Model the existing program (simulation = re-running model)
        return apiPostJson(GS_API.model, { program_id: GS_CURRENT_PROGRAM_ID })
            .then(function(modelResp) {
                if (modelResp.status !== "ok") {
                    throw new Error(modelResp.message || "Failed to model GS program");
                }

                var flights = (modelResp.data && modelResp.data.flights) || [];
                flights = normalizeSqlsrvRows(flights);

                // Store simulation data for flight list viewing
                storeSimulationData(modelResp.data);

                setGsTableMode("GS");
                renderFlightsFromAdlRowsForWorkflow(flights, "GS-SIM");

                if (statusEl) {
                    var summary = modelResp.data.summary || {};
                    var msg = "Simulated " + flights.length + " flights.";
                    if (summary.max_delay_min) {
                        msg += " (Max delay: " + summary.max_delay_min + " min)";
                    }
                    msg += " | Program ID: " + GS_CURRENT_PROGRAM_ID;
                    statusEl.textContent = msg;
                }
                buildAdvisory();

                // Enable "Send Actual" button now that simulation is ready
                GS_SIMULATION_READY = true;
                setSendActualEnabled(true);
            })
            .catch(function(err) {
                console.error("GS simulate failed", err);
                if (statusEl) statusEl.textContent = "Simulate failed: " + (err && err.message ? err.message : err);
                clearGsFlightTable("Simulate failed.");
                // Keep Send Actual disabled on simulation failure
                setSendActualEnabled(false, "Simulation failed - fix errors and try again");
            });
    }

function handleGsSendActual() {
        var statusEl = document.getElementById("gs_adl_status");

        // Require simulation to be run first
        if (!GS_SIMULATION_READY) {
            if (window.Swal) {
                window.Swal.fire({
                    icon: "warning",
                    title: "Simulation Required",
                    text: "You must run 'Simulate' before sending an actual GS. This ensures EDCTs are calculated correctly.",
                    confirmButtonText: "OK"
                });
            } else {
                alert("You must run 'Simulate' before sending an actual GS.");
            }
            if (statusEl) statusEl.textContent = "Run 'Simulate' first before 'Send Actual'.";
            return Promise.resolve();
        }

        // Require a program to activate
        if (!GS_CURRENT_PROGRAM_ID) {
            if (statusEl) statusEl.textContent = "No GS program to activate. Run Preview/Simulate first.";
            return Promise.resolve();
        }

        var workflowPayload = collectGsWorkflowPayload();

        return showConfirmDialog(
            "Activate GS Program " + GS_CURRENT_PROGRAM_ID + "?",
            "This will activate the GS program and apply EDCTs to affected flights in the live ADL.",
            "Activate",
            "warning"
        ).then(function(confirmed) {
            if (!confirmed) return;

            if (statusEl) statusEl.textContent = "Activating GS program " + GS_CURRENT_PROGRAM_ID + "...";

            return apiPostJson(GS_API.activate, {
                program_id: GS_CURRENT_PROGRAM_ID,
                activated_by: "TMU"
            })
                .then(function(activateResp) {
                    if (activateResp.status !== "ok") {
                        throw new Error(activateResp.message || "Failed to activate GS program");
                    }

                    GS_CURRENT_PROGRAM_STATUS = "ACTIVE";
                    
                    var program = activateResp.data.program || {};
                    var flightCount = activateResp.data.controlled_flights || program.controlled_flights || 0;

                    if (statusEl) {
                        statusEl.textContent = "GS ACTIVE | Program " + GS_CURRENT_PROGRAM_ID + 
                            " | " + flightCount + " flights controlled | " +
                            program.adv_number;
                    }
                    setGsTableMode("LIVE");
                    
                    // Disable Send Actual - program is now active
                    GS_SIMULATION_READY = false;
                    setSendActualEnabled(false, "GS is ACTIVE - create new program or extend/purge current");

                    // Show the GS Flight List modal with affected flights
                    if (activateResp.data && activateResp.data.flights) {
                        showGsFlightListModal(activateResp.data.flights, workflowPayload);
                    } else {
                        // Fetch flight list separately
                        return fetch(GS_API.flights + "?program_id=" + GS_CURRENT_PROGRAM_ID)
                            .then(function(r) { return r.json(); })
                            .then(function(flightsResp) {
                                if (flightsResp.status === "ok" && flightsResp.data && flightsResp.data.flights) {
                                    showGsFlightListModal(flightsResp.data.flights, workflowPayload);
                                }
                            });
                    }
                })
                .catch(function(err) {
                    console.error("GS activate failed", err);
                    if (statusEl) statusEl.textContent = "Activate failed: " + (err && err.message ? err.message : err);
                    if (window.Swal) {
                        window.Swal.fire({ icon: "error", title: "Activate failed", text: (err && err.message) ? err.message : String(err) });
                    } else {
                        alert("Activate failed: " + (err && err.message ? err.message : err));
                    }
                });
        });
    }

function handleGsPurgeAll() {
        var statusEl = document.getElementById("gs_adl_status");

        return showConfirmDialog(
            "Purge ALL active GS programs?",
            "This will purge all ACTIVE and PROPOSED GS programs and clear EDCTs from affected flights.",
            "Purge All",
            "warning"
        ).then(function(confirmed) {
            if (!confirmed) return;

            if (statusEl) statusEl.textContent = "Fetching active GS programs...";

            // Step 1: Get list of ACTIVE and PROPOSED programs
            return fetch(GS_API.list + "?status=ACTIVE,PROPOSED")
                .then(function(res) { return res.json(); })
                .then(function(listResp) {
                    if (listResp.status !== "ok") {
                        throw new Error(listResp.message || "Failed to fetch program list");
                    }

                    var programs = (listResp.data && listResp.data.programs) || [];
                    if (!programs.length) {
                        if (statusEl) statusEl.textContent = "No active/proposed GS programs to purge.";
                        GS_CURRENT_PROGRAM_ID = null;
                        GS_CURRENT_PROGRAM_STATUS = null;
                        return;
                    }

                    if (statusEl) statusEl.textContent = "Purging " + programs.length + " GS programs...";

                    // Step 2: Purge each program sequentially
                    var purgePromises = programs.map(function(prog) {
                        return apiPostJson(GS_API.purge, {
                            program_id: prog.program_id,
                            purged_by: "TMU"
                        });
                    });

                    return Promise.all(purgePromises);
                })
                .then(function(results) {
                    if (!results) return; // No programs to purge

                    var purged = results.filter(function(r) { return r && r.status === "ok"; }).length;
                    
                    if (statusEl) {
                        statusEl.textContent = "Purged " + purged + " GS program(s).";
                    }
                    
                    // Clear current program state
                    GS_CURRENT_PROGRAM_ID = null;
                    GS_CURRENT_PROGRAM_STATUS = null;
                    GS_SIMULATION_READY = false;
                    
                    setGsTableMode("LIVE");
                    setSendActualEnabled(false, "All programs purged - create new program");
                    clearGsFlightTable("All GS programs purged.");
                })
                .catch(function(err) {
                    console.error("GS purge all failed", err);
                    if (statusEl) statusEl.textContent = "Purge all failed: " + (err && err.message ? err.message : err);
                });
        });
    }

function handleGsPurgeLocal() {
        var statusEl = document.getElementById("gs_adl_status");

        // Require a program to purge
        if (!GS_CURRENT_PROGRAM_ID) {
            if (statusEl) statusEl.textContent = "No current GS program to purge.";
            return Promise.resolve();
        }

        var programId = GS_CURRENT_PROGRAM_ID;

        return showConfirmDialog(
            "Purge GS Program " + programId + "?",
            "This will cancel/purge the current GS program. If it was ACTIVE, EDCTs will be cleared from affected flights.",
            "Purge Program",
            "warning"
        ).then(function(confirmed) {
            if (!confirmed) return;

            if (statusEl) statusEl.textContent = "Purging GS program " + programId + "...";

            return apiPostJson(GS_API.purge, {
                program_id: programId,
                purged_by: "TMU"
            })
                .then(function(purgeResp) {
                    if (purgeResp.status !== "ok") {
                        throw new Error(purgeResp.message || "Failed to purge GS program");
                    }

                    var purgedProgram = purgeResp.data && purgeResp.data.program;
                    
                    if (statusEl) {
                        statusEl.textContent = "Program " + programId + " purged." +
                            (purgedProgram ? " (" + purgedProgram.adv_number + ")" : "");
                    }
                    
                    // Clear current program state
                    GS_CURRENT_PROGRAM_ID = null;
                    GS_CURRENT_PROGRAM_STATUS = null;
                    GS_SIMULATION_READY = false;
                    
                    setGsTableMode("LIVE");
                    setSendActualEnabled(false, "Program purged - create new program");
                    clearGsFlightTable("GS program purged. Enter parameters and click Preview to start a new program.");
                })
                .catch(function(err) {
                    console.error("GS purge failed", err);
                    if (statusEl) statusEl.textContent = "Purge failed: " + (err && err.message ? err.message : err);
                });
        });
    }


function applyGsToAdl() {
        var statusEl = document.getElementById("gs_adl_status");
        var updatedLbl = document.getElementById("gs_flights_updated");

        // Make sure GS-derived EDCT values are reflected in the table first
        applyGroundStopEdctToTable();

        var payload = collectGsCtdPayload();
        if (!payload.updates || !payload.updates.length) {
            if (statusEl) statusEl.textContent = "No flights with EDCT/CTD to apply.";
            return;
        }

        if (statusEl) {
            statusEl.textContent = "Applying GS to ADL (CTD/CTA/CETE)...";
        }

        fetch("api/tmi/gs_apply_ctd.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(payload)
        })
            .then(function(res) {
                if (!res.ok) {
                    throw new Error("HTTP " + res.status + " from gs_apply_ctd.php");
                }
                return res.json();
            })
            .then(function(data) {
                var updated = data && typeof data.updated === "number" ? data.updated : 0;
                if (statusEl) {
                    statusEl.textContent = "GS applied to ADL for " + updated + " flight(s).";
                }
                if (updatedLbl) {
                    updatedLbl.textContent = "CTD/CTA/CETE updated for " + updated + " flight(s).";
                }

                return refreshAdl().then(function() {
                    if (getValue("gs_airports").trim()) {
                        loadVatsimFlightsForCurrentGs();
                    }
                });
            })
            .catch(function(err) {
                console.error("Error applying GS to ADL", err);
                if (statusEl) {
                    statusEl.textContent = "Error applying GS to ADL";
                }
            });
    }


  function loadAirportInfo() {
        return fetch("assets/data/apts.csv", { cache: "no-cache" })
            .then(function(res) { return res.text(); })
            .then(function(text) {
                AIRPORT_CENTER_MAP = {};
                CENTER_AIRPORTS_MAP = {};
                AIRPORT_TRACON_MAP = {};
                TRACON_AIRPORTS_MAP = {};

                if (!text) return;

                var lines = text.replace(/\r/g, "").split("\n").filter(function(l) { return l.trim().length > 0; });
                if (!lines.length) return;

                var header = lines[0].split(",");
                function idx(name) {
                    for (var i = 0; i < header.length; i++) {
                        var col = header[i] ? header[i].replace(/^\uFEFF/, "") : "";
                        if (col === name) return i;
                    }
                    return -1;
                }
                var idxArpt = idx("ARPT_ID");
                var idxIcao = idx("ICAO_ID");
                var idxCenter = idx("RESP_ARTCC_ID");
                var idxTracon = idx("Consolidated Approach ID");

                for (var i = 1; i < lines.length; i++) {
                    var line = lines[i];
                    if (!line.trim()) continue;
                    var parts = line.split(",");
                    function get(idx) {
                        return (idx >= 0 && idx < parts.length ? parts[idx].trim().toUpperCase() : "");
                    }
                    var icao = get(idxIcao);
                    if (!icao) continue;

                    var arpt = get(idxArpt);
                    if (icao && arpt) {
                        AIRPORT_IATA_MAP[icao] = arpt;
                    }

                    var center = get(idxCenter);
                    var tracon = get(idxTracon);

                    if (center) {
                        AIRPORT_CENTER_MAP[icao] = center;
                        if (!CENTER_AIRPORTS_MAP[center]) CENTER_AIRPORTS_MAP[center] = [];
                        CENTER_AIRPORTS_MAP[center].push(icao);
                    }
                    if (tracon) {
                        AIRPORT_TRACON_MAP[icao] = tracon;
                        if (!TRACON_AIRPORTS_MAP[tracon]) TRACON_AIRPORTS_MAP[tracon] = [];
                        TRACON_AIRPORTS_MAP[tracon].push(icao);
                    }
                }
            })
            .catch(function(err) {
                console.error("Error loading apts.csv", err);
            });
    }


    
    
    function updateDataSourceLabel() {
        var el = document.getElementById("gs_data_source");
        if (!el) return;

        var flightListLabel = (GS_TABLE_MODE === "GS")
            ? "GS Program Mode"
            : "Live ADL";

        var adlCache = "Not loaded";
        if (GS_ADL && (GS_ADL.snapshotUtc || (GS_ADL.raw && (GS_ADL.raw.snapshot_utc || GS_ADL.raw.snapshotUtc)))) {
            adlCache = GS_ADL.snapshotUtc || (GS_ADL.raw.snapshot_utc || GS_ADL.raw.snapshotUtc);
        } else if (GS_ADL_LOADING) {
            adlCache = "Loading...";
        }

        var programInfo = "";
        if (GS_CURRENT_PROGRAM_ID) {
            programInfo = " | Program ID: " + GS_CURRENT_PROGRAM_ID + " (" + (GS_CURRENT_PROGRAM_STATUS || "?") + ")";
        }

        el.textContent = "Data: " + flightListLabel + programInfo + " | ADL cache: " + adlCache;
    }

function refreshAdl() {
        var statusEl = document.getElementById("gs_adl_status");
        if (statusEl) {
            statusEl.textContent = "Loading ADL (vATCSCC)...";
            statusEl.classList.add("adl-refreshing");
        }

        GS_ADL_LOADING = true;
        
        // Store previous data for buffered update
        var previousAdl = GS_ADL;

        var p = fetch(GS_ADL_API_URL, { cache: "no-cache" })
            .then(function(res) {
                if (!res.ok) {
                    throw new Error("HTTP " + res.status + " from ADL API");
                }
                return res.json();
            })
            .then(function(data) {
                var flights = [];
                if (data) {
                    if (Array.isArray(data.flights)) {
                        flights = data.flights;
                    } else if (Array.isArray(data.rows)) {
                        flights = data.rows;
                    }
                }

                var snapshotStr = data && (data.snapshot_utc || data.snapshotUtc || data.snapshot || null);
                var snapshotDate = null;
                if (snapshotStr) {
                    var tmp = new Date(snapshotStr);
                    if (!isNaN(tmp.getTime())) {
                        snapshotDate = tmp;
                    }
                }

                // Buffered update: only update if we got data, or had no prior data
                var hadPriorData = previousAdl && Array.isArray(previousAdl.flights) && previousAdl.flights.length > 0;
                
                if (flights.length > 0 || !hadPriorData) {
                    GS_ADL = {
                        raw: data || {},
                        flights: flights,
                        snapshotUtc: snapshotDate || new Date()
                    };
                } else {
                    // Keep previous data but update timestamp
                    console.log("[TMI] Empty ADL response, keeping previous data (" + previousAdl.flights.length + " flights)");
                    if (previousAdl) {
                        previousAdl.snapshotUtc = new Date();
                    }
                }

                if (statusEl) {
                    statusEl.textContent = "ADL updated " + (GS_ADL ? GS_ADL.snapshotUtc.toUTCString() : "N/A");
                    statusEl.classList.remove("adl-refreshing");
                }
                return GS_ADL;
            })
            .catch(function(err) {
                console.error("Error loading vATCSCC ADL", err);
                if (statusEl) {
                    statusEl.textContent = "ADL refresh error (using cached data)";
                    statusEl.classList.remove("adl-refreshing");
                }
                // BUFFERED: Don't set GS_ADL = null - keep previous data
                // GS_ADL = null;  // REMOVED - causes data flash
                console.log("[TMI] Keeping previous ADL data due to error");
                // Don't throw - return previous data instead
                return GS_ADL || previousAdl;
            })
            .finally(function() {
                GS_ADL_LOADING = false;
                try {
                    updateDataSourceLabel();
                } catch (e) {
                    console.error("Error updating data source label", e);
                }
            });

        GS_ADL_PROMISE = p;
        return p;
    }


    
function ensureVatsimData() {
    if (GS_VATSIM && GS_VATSIM.pilots && GS_VATSIM.prefiles) {
        return Promise.resolve(GS_VATSIM);
    }
    if (GS_VATSIM_LOADING && GS_VATSIM_PROMISE) {
        return GS_VATSIM_PROMISE;
    }

    GS_VATSIM_LOADING = true;

    var p = fetch("https://data.vatsim.net/v3/vatsim-data.json", { cache: "no-cache" })
        .then(function(res) {
            if (!res.ok) {
                throw new Error("HTTP " + res.status + " from VATSIM data API");
            }
            return res.json();
        })
        .then(function(data) {
            GS_VATSIM = data || {};
            return GS_VATSIM;
        })
        .catch(function(err) {
            console.error("Error loading VATSIM data", err);
            GS_VATSIM = null;
            throw err;
        })
        .finally(function() {
            GS_VATSIM_LOADING = false;
        });

    GS_VATSIM_PROMISE = p;
    return p;
}

function ensureAdlThen(callback) {
        if (GS_ADL && Array.isArray(GS_ADL.flights)) {
            callback();
            return;
        }
        if (GS_ADL_LOADING && GS_ADL_PROMISE) {
            GS_ADL_PROMISE.then(function() {
                if (GS_ADL && Array.isArray(GS_ADL.flights)) {
                    callback();
                }
            }).catch(function(err) {
                console.error("ADL load failed", err);
            });
            return;
        }
        refreshAdl().then(function() {
            if (GS_ADL && Array.isArray(GS_ADL.flights)) {
                callback();
            }
        }).catch(function(err) {
            console.error("ADL load failed", err);
        });
    }

function updateRowWithSimTraffic(callsign, data) {
        if (!callsign || !data) return;
        var cs = String(callsign).toUpperCase();
        var rowId = makeRowIdForCallsign(cs);
        var rowEl = document.getElementById(rowId);
        if (!rowEl) return;

        var dep = data.departure || {};
        var arr = data.arrival || {};

        var edctEpoch = parseSimtrafficTimeToEpoch(dep.edct);
        var tkofEpoch = parseSimtrafficTimeToEpoch(dep.takeoff_time);
        var etaEpoch = parseSimtrafficTimeToEpoch(arr.eta);
        var mftEpoch = parseSimtrafficTimeToEpoch(arr.mft || arr.eta_mf);
        var vtEpoch = parseSimtrafficTimeToEpoch(arr.vt || arr.eta_vt || arr.eta_vertex || arr.eta_vertex_time || arr.vertex_time);

        if (edctEpoch) {
            var edctCell = rowEl.querySelector(".gs_edct_cell");
            if (edctCell) {
                edctCell.textContent = formatZuluFromEpoch(edctEpoch);
            }
            rowEl.setAttribute("data-edct-epoch", String(edctEpoch));
        }
        if (etaEpoch) {
            var etaCell = rowEl.querySelector(".gs_eta_cell");
            if (etaCell) {
                etaCell.textContent = formatZuluFromEpoch(etaEpoch);
            }
            rowEl.setAttribute("data-eta-epoch", String(etaEpoch));
        }
        if (tkofEpoch) {
            rowEl.setAttribute("data-takeoff-epoch", String(tkofEpoch));
        }
        if (mftEpoch) {
            rowEl.setAttribute("data-mft-epoch", String(mftEpoch));
        }
        if (vtEpoch) {
            rowEl.setAttribute("data-vt-epoch", String(vtEpoch));
        }
    }

/* --------------------------------------------------------------------
   SimTraffic: throttled + cached + circuit-breaker
   - Avoids pulling every render/refresh
   - Caches per-callsign results (memory + localStorage) with TTL
   - Backs off / cools down quickly on 429/5xx bursts
-------------------------------------------------------------------- */

const GS_SIMTRAFFIC_CFG = {
    localStorageKey: "vATCSCC_gs_simtraffic_cache_v1",
    minEnrichIntervalMs: 90 * 1000,        // don't enqueue every render/refresh
    perCallsignMinIntervalMs: 8 * 60 * 1000,
    cacheTtlMs: 10 * 60 * 1000,            // success TTL
    negativeCacheTtlMs: 2 * 60 * 1000,     // error TTL (per-callsign)
    maxRequestsPerEnrich: 60,              // cap per render/refresh
    maxQueueSize: 250,
    baseDelayMs: 650,                      // ~1.5 req/sec per client
    maxDelayMs: 10000,
    errorBackoffMs: 2500,
    errorWindowMs: 60 * 1000,
    maxErrorsPerWindow: 10,
    maxConsecutiveErrors: 5,
    cooldownMsOn429: 90 * 1000,
    cooldownMsOn5xxBurst: 3 * 60 * 1000
};

let GS_SIMTRAFFIC_LAST_ENRICH_MS = 0;
let GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS = 0;
let GS_SIMTRAFFIC_CONSEC_ERRORS = 0;
let GS_SIMTRAFFIC_ERROR_WINDOW = []; // ms timestamps

let GS_SIMTRAFFIC_CACHE_TS = {};     // callsign -> ms timestamp (success)
let GS_SIMTRAFFIC_NEG_CACHE_TS = {}; // callsign -> ms timestamp (error/negative cache)
let GS_SIMTRAFFIC_QUEUE_SET = {};    // callsign -> true (dedupe)
let GS_SIMTRAFFIC_NEXT_DELAY_MS = GS_SIMTRAFFIC_CFG.baseDelayMs;

let GS_SIMTRAFFIC_PERSIST_TIMER = null;

function _nowMs() { return (new Date()).getTime(); }

function loadSimTrafficLocalCache() {
    try {
        if (typeof localStorage === "undefined") return;
        var raw = localStorage.getItem(GS_SIMTRAFFIC_CFG.localStorageKey);
        if (!raw) return;

        var obj = JSON.parse(raw);
        if (!obj || typeof obj !== "object") return;

        var now = _nowMs();
        Object.keys(obj).forEach(function(cs) {
            var entry = obj[cs];
            if (!entry || typeof entry !== "object") return;
            var ts = Number(entry.t);
            if (!ts || isNaN(ts)) return;
            if ((now - ts) > GS_SIMTRAFFIC_CFG.cacheTtlMs) return;

            var data = entry.d;
            if (!data || typeof data !== "object") return;

            GS_SIMTRAFFIC_CACHE[cs] = data;
            GS_SIMTRAFFIC_CACHE_TS[cs] = ts;
        });
    } catch (e) {
        // ignore storage failures
    }
}

function persistSimTrafficLocalCache() {
    GS_SIMTRAFFIC_PERSIST_TIMER = null;
    try {
        if (typeof localStorage === "undefined") return;

        var now = _nowMs();
        var out = {};
        var keys = Object.keys(GS_SIMTRAFFIC_CACHE_TS);

        // Keep newest 500 entries max to avoid bloat
        keys.sort(function(a, b) { return (GS_SIMTRAFFIC_CACHE_TS[b] || 0) - (GS_SIMTRAFFIC_CACHE_TS[a] || 0); });
        keys = keys.slice(0, 500);

        keys.forEach(function(cs) {
            var ts = GS_SIMTRAFFIC_CACHE_TS[cs];
            if (!ts || (now - ts) > GS_SIMTRAFFIC_CFG.cacheTtlMs) return;
            var data = GS_SIMTRAFFIC_CACHE[cs];
            if (!data) return;
            out[cs] = { t: ts, d: data };
        });

        localStorage.setItem(GS_SIMTRAFFIC_CFG.localStorageKey, JSON.stringify(out));
    } catch (e) {
        // ignore storage failures
    }
}

function schedulePersistSimTrafficLocalCache() {
    if (GS_SIMTRAFFIC_PERSIST_TIMER) return;
    GS_SIMTRAFFIC_PERSIST_TIMER = setTimeout(persistSimTrafficLocalCache, 1200);
}

function isFreshSuccessCache(cs, now) {
    if (!Object.prototype.hasOwnProperty.call(GS_SIMTRAFFIC_CACHE, cs)) return false;
    var ts = GS_SIMTRAFFIC_CACHE_TS[cs];
    if (!ts) return false;
    return (now - ts) <= GS_SIMTRAFFIC_CFG.cacheTtlMs;
}

function isRecentNegativeCache(cs, now) {
    var ts = GS_SIMTRAFFIC_NEG_CACHE_TS[cs];
    if (!ts) return false;
    return (now - ts) <= GS_SIMTRAFFIC_CFG.negativeCacheTtlMs;
}

function recordNegativeCache(cs) {
    GS_SIMTRAFFIC_NEG_CACHE_TS[cs] = _nowMs();
}

function pruneErrorWindow(now) {
    var cutoff = now - GS_SIMTRAFFIC_CFG.errorWindowMs;
    GS_SIMTRAFFIC_ERROR_WINDOW = GS_SIMTRAFFIC_ERROR_WINDOW.filter(function(t) { return t >= cutoff; });
}

function computeRetryAfterSeconds(retryAfterHeader) {
    if (!retryAfterHeader) return null;
    var s = String(retryAfterHeader).trim();
    if (!s) return null;

    // seconds
    if (/^\d+$/.test(s)) {
        var v = parseInt(s, 10);
        return isNaN(v) ? null : v;
    }

    // HTTP-date
    var d = new Date(s);
    if (isNaN(d.getTime())) return null;
    var now = new Date();
    var sec = Math.ceil((d.getTime() - now.getTime()) / 1000);
    if (sec < 0) sec = 0;
    return sec;
}

function noteSimTrafficError(statusOrErr, retryAfterSeconds) {
    var now = _nowMs();

    GS_SIMTRAFFIC_ERROR_COUNT++;
    GS_SIMTRAFFIC_CONSEC_ERRORS++;

    GS_SIMTRAFFIC_ERROR_WINDOW.push(now);
    pruneErrorWindow(now);

    var status = null;
    if (typeof statusOrErr === "number") status = statusOrErr;
    else if (statusOrErr && typeof statusOrErr.status === "number") status = statusOrErr.status;

    // Cooldown logic
    var cooldownMs = GS_SIMTRAFFIC_CFG.errorBackoffMs;

    if (status === 429 || status === 503) {
        cooldownMs = GS_SIMTRAFFIC_CFG.cooldownMsOn429;
        if (retryAfterSeconds != null && !isNaN(retryAfterSeconds)) {
            cooldownMs = Math.max(cooldownMs, retryAfterSeconds * 1000);
        }
    } else if (status === 0 || (status != null && status >= 500)) {
        // 5xx burst
        if (GS_SIMTRAFFIC_CONSEC_ERRORS >= 3) {
            cooldownMs = GS_SIMTRAFFIC_CFG.cooldownMsOn5xxBurst;
        }
    }

    GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS = Math.max(GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS, now + cooldownMs);

    // Increase spacing between requests after errors (exponential-ish)
    GS_SIMTRAFFIC_NEXT_DELAY_MS = Math.min(
        GS_SIMTRAFFIC_CFG.maxDelayMs,
        Math.max(GS_SIMTRAFFIC_CFG.errorBackoffMs, Math.floor(GS_SIMTRAFFIC_NEXT_DELAY_MS * 1.7))
    );

    // Hard stop for this browser session
    if (
        (GS_SIMTRAFFIC_ERROR_COUNT >= GS_SIMTRAFFIC_ERROR_CUTOFF) ||
        (GS_SIMTRAFFIC_CONSEC_ERRORS >= GS_SIMTRAFFIC_CFG.maxConsecutiveErrors) ||
        (GS_SIMTRAFFIC_ERROR_WINDOW.length >= GS_SIMTRAFFIC_CFG.maxErrorsPerWindow)
    ) {
        if (GS_SIMTRAFFIC_ENABLED) {
            GS_SIMTRAFFIC_ENABLED = false;
            GS_SIMTRAFFIC_QUEUE = [];
            GS_SIMTRAFFIC_QUEUE_SET = {};
            console.warn("SimTraffic disabled after repeated errors; no further SimTraffic calls this session.");
        }
    }
}

function noteSimTrafficSuccess() {
    GS_SIMTRAFFIC_CONSEC_ERRORS = 0;
    GS_SIMTRAFFIC_NEXT_DELAY_MS = GS_SIMTRAFFIC_CFG.baseDelayMs;
}

function enqueueSimTrafficFetch(callsign) {
    if (!GS_SIMTRAFFIC_ENABLED) return;
    if (!callsign) return;

    var cs = String(callsign).toUpperCase();
    if (!cs) return;

    var now = _nowMs();

    // Fresh success cache -> update row immediately (no new request)
    if (isFreshSuccessCache(cs, now)) {
        try {
            updateRowWithSimTraffic(cs, GS_SIMTRAFFIC_CACHE[cs]);
        } catch (e) {}
        return;
    }

    // Recently failed -> don't hammer
    if (isRecentNegativeCache(cs, now)) return;

    // Per-callsign min interval even if TTL expired
    var lastOk = GS_SIMTRAFFIC_CACHE_TS[cs];
    if (lastOk && (now - lastOk) < GS_SIMTRAFFIC_CFG.perCallsignMinIntervalMs) return;

    // Dedupe queue
    if (GS_SIMTRAFFIC_QUEUE_SET[cs]) return;

    if (GS_SIMTRAFFIC_QUEUE.length >= GS_SIMTRAFFIC_CFG.maxQueueSize) return;

    GS_SIMTRAFFIC_QUEUE_SET[cs] = true;
    GS_SIMTRAFFIC_QUEUE.push(cs);
    processSimTrafficQueue();
}

function processSimTrafficQueue() {
    if (!GS_SIMTRAFFIC_ENABLED) return;
    if (GS_SIMTRAFFIC_BUSY) return;
    if (!GS_SIMTRAFFIC_QUEUE.length) return;

    var now = _nowMs();
    if (now < GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS) {
        setTimeout(processSimTrafficQueue, Math.max(250, GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS - now));
        return;
    }

    var callsign = GS_SIMTRAFFIC_QUEUE.shift();
    if (callsign && GS_SIMTRAFFIC_QUEUE_SET[callsign]) {
        delete GS_SIMTRAFFIC_QUEUE_SET[callsign];
    }
    if (!callsign) return;

    GS_SIMTRAFFIC_BUSY = true;

    fetch("api/tmi/simtraffic_flight.php?cs=" + encodeURIComponent(callsign), { cache: "no-cache" })
        .then(function(res) {
            if (!res.ok) {
                var ra = computeRetryAfterSeconds(res.headers ? res.headers.get("Retry-After") : null);
                noteSimTrafficError(res.status, ra);
                recordNegativeCache(callsign);
                throw new Error("HTTP " + res.status + " from SimTraffic proxy");
            }
            return res.json();
        })
        .then(function(data) {
            if (data) {
                GS_SIMTRAFFIC_CACHE[callsign] = data;
                GS_SIMTRAFFIC_CACHE_TS[callsign] = _nowMs();
                schedulePersistSimTrafficLocalCache();
            }
            noteSimTrafficSuccess();

            if (data && !data.__error) {
                updateRowWithSimTraffic(callsign, data);
                applyTimeFilterToTable();
            }
        })
        .catch(function(err) {
            console.error("Error loading SimTraffic data for", callsign, err);

            // If we didn't already record a status-based error above, record a generic one here
            if (String(err && err.message || "").indexOf("HTTP ") !== 0) {
                noteSimTrafficError(0, null);
            }

            // Cache a sentinel so we do not retry this callsign repeatedly
            GS_SIMTRAFFIC_CACHE[callsign] = { __error: true, message: (err && err.message) ? err.message : "" };
            recordNegativeCache(callsign);

            // Re-apply any active time filter using whatever data we have
            applyTimeFilterToTable();
        })
        .finally(function() {
            GS_SIMTRAFFIC_BUSY = false;
            if (GS_SIMTRAFFIC_ENABLED && GS_SIMTRAFFIC_QUEUE.length) {
                setTimeout(processSimTrafficQueue, GS_SIMTRAFFIC_NEXT_DELAY_MS);
            }
        });
}

function enrichFlightsWithSimTraffic(rows) {
    if (!GS_SIMTRAFFIC_ENABLED) return;
    if (!rows || !rows.length) return;

    var now = _nowMs();
    if (now < GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS) return;

    // Don't attempt on every render/refresh
    if ((now - GS_SIMTRAFFIC_LAST_ENRICH_MS) < GS_SIMTRAFFIC_CFG.minEnrichIntervalMs) {
        // Still apply any cached entries to the DOM
        rows.forEach(function(r) {
            if (!r || !r.callsign) return;
            var cs = String(r.callsign).toUpperCase();
            if (isFreshSuccessCache(cs, now)) {
                updateRowWithSimTraffic(cs, GS_SIMTRAFFIC_CACHE[cs]);
            }
        });
        return;
    }

    GS_SIMTRAFFIC_LAST_ENRICH_MS = now;

    // Candidates: prioritize flights with missing times, then earliest ETA
    var candidates = [];
    rows.forEach(function(r) {
        if (!r || !r.callsign) return;

        var cs = String(r.callsign).toUpperCase();
        if (!cs) return;

        // Apply cached data immediately (no new request)
        if (isFreshSuccessCache(cs, now)) {
            updateRowWithSimTraffic(cs, GS_SIMTRAFFIC_CACHE[cs]);
            return;
        }

        // Skip if we recently failed for this callsign
        if (isRecentNegativeCache(cs, now)) return;

        // Only fetch when at least one key time is missing
        var needs =
            !(r.edctEpoch != null && !isNaN(r.edctEpoch)) ||
            !(r.tkofEpoch != null && !isNaN(r.tkofEpoch)) ||
            !(r.etaEpoch != null && !isNaN(r.etaEpoch)) ||
            !(r.mftEpoch != null && !isNaN(r.mftEpoch)) ||
            !(r.vtEpoch != null && !isNaN(r.vtEpoch));

        if (!needs) return;

        candidates.push(r);
    });

    candidates.sort(function(a, b) {
        var aEta = (a.roughEtaEpoch != null && !isNaN(a.roughEtaEpoch)) ? a.roughEtaEpoch : Number.MAX_SAFE_INTEGER;
        var bEta = (b.roughEtaEpoch != null && !isNaN(b.roughEtaEpoch)) ? b.roughEtaEpoch : Number.MAX_SAFE_INTEGER;
        return aEta - bEta;
    });

    var n = Math.min(GS_SIMTRAFFIC_CFG.maxRequestsPerEnrich, candidates.length);
    for (var i = 0; i < n; i++) {
        enqueueSimTrafficFetch(candidates[i].callsign);
    }
}

// Load any saved cache once per page load
loadSimTrafficLocalCache(); {
    }

function loadTierInfo() {
        // Load TierInfo.csv from assets/data/TierInfo.csv
        // Expected header columns: code, facility, select, departureFacilitiesIncluded
        return fetch("assets/data/TierInfo.csv", { cache: "no-cache" })
            .then(function(res) { return res.text(); })
            .then(function(text) {
                TMI_TIER_INFO = [];
                TMI_UNIQUE_FACILITIES = [];
                TMI_TIER_INFO_BY_CODE = {};

                if (!text) return;

                var lines = text.replace(/\r/g, "").split("\n").filter(function(l) { return l.trim().length > 0; });
                if (!lines.length) return;

                var header = lines[0];
                var delim = header.indexOf(",") !== -1 ? "," : "\t";
                var cols = header.split(delim).map(function(s) { return s.trim(); });

                function idx(name) {
                    var i = cols.indexOf(name);
                    return i === -1 ? -1 : i;
                }

                var idxCode = idx("code");
                var idxFacility = idx("facility");
                var idxLabel = idx("select");
                var idxDeps = idx("departureFacilitiesIncluded");

                var facSet = new Set();

                for (var i = 1; i < lines.length; i++) {
                    var line = lines[i];
                    if (!line.trim()) continue;
                    var parts = line.split(delim);
                    function get(idx) {
                        return (idx >= 0 && idx < parts.length) ? parts[idx].trim() : "";
                    }
                    var code = get(idxCode);
                    if (!code) continue;
                    var facility = get(idxFacility) || null;
                    var label = get(idxLabel);
                    var depsRaw = get(idxDeps);
                    var included = depsRaw ? depsRaw.split(/\s+/).filter(function(x) { return x.length > 0; }) : [];

                    included.forEach(function(f) { facSet.add(f); });

                    var entry = {
                        code: code,
                        facility: facility,
                        label: label,
                        included: included
                    };
                    TMI_TIER_INFO.push(entry);
                    TMI_TIER_INFO_BY_CODE[code] = entry;
                }

                TMI_UNIQUE_FACILITIES = Array.from(facSet).sort();
            })
            .catch(function(err) {
                console.error("Error loading TierInfo.csv", err);
            });
    }

    document.addEventListener("DOMContentLoaded", function() {

        // Initialize UTC clock display if placeholder exists
        var utcClockEl = document.getElementById("tmi_utc_clock");
        if (utcClockEl) {
            var updateUtcClock = function() {
                var now = new Date();
                var dd = String(now.getUTCDate()).padStart(2, "0");
                var hh = String(now.getUTCHours()).padStart(2, "0");
                var mi = String(now.getUTCMinutes()).padStart(2, "0");
                var ss = String(now.getUTCSeconds()).padStart(2, "0");
                utcClockEl.textContent = dd + " / " + hh + ":" + mi + ":" + ss + "Z";
            };
            updateUtcClock();
            setInterval(updateUtcClock, 1000);
        }

        // Initialize US timezone clocks
        var clockGuam = document.getElementById("tmi_clock_guam");
        var clockHi = document.getElementById("tmi_clock_hi");
        var clockAk = document.getElementById("tmi_clock_ak");
        var clockPac = document.getElementById("tmi_clock_pac");
        var clockMtn = document.getElementById("tmi_clock_mtn");
        var clockCent = document.getElementById("tmi_clock_cent");
        var clockEast = document.getElementById("tmi_clock_east");

        if (clockPac && clockMtn && clockCent && clockEast) {
            var updateLocalClocks = function() {
                var now = new Date();
                
                // Format time for a given timezone offset (hours from UTC)
                function formatLocalTime(date, tzName) {
                    try {
                        var opts = { 
                            timeZone: tzName, 
                            hour: '2-digit', 
                            minute: '2-digit',
                            hour12: false 
                        };
                        return date.toLocaleTimeString('en-US', opts);
                    } catch (e) {
                        // Fallback if timezone not supported
                        return "--:--:--";
                    }
                }
                
                clockGuam.textContent = formatLocalTime(now, 'Pacific/Guam');
                clockHi.textContent = formatLocalTime(now, 'Pacific/Honolulu');
                clockAk.textContent = formatLocalTime(now, 'America/Anchorage');
                clockPac.textContent = formatLocalTime(now, 'America/Los_Angeles');
                clockMtn.textContent = formatLocalTime(now, 'America/Denver');
                clockCent.textContent = formatLocalTime(now, 'America/Chicago');
                clockEast.textContent = formatLocalTime(now, 'America/New_York');
            };
            updateLocalClocks();
            setInterval(updateLocalClocks, 1000);
        }

        // Initialize flight statistics display
        var statsElements = {
            globalTotal: document.getElementById("tmi_stats_global_total"),
            dd: document.getElementById("tmi_stats_dd"),
            di: document.getElementById("tmi_stats_di"),
            id: document.getElementById("tmi_stats_id"),
            ii: document.getElementById("tmi_stats_ii"),
            domesticTotal: document.getElementById("tmi_stats_domestic_total"),
            dccNe: document.getElementById("tmi_stats_dcc_ne"),
            dccSe: document.getElementById("tmi_stats_dcc_se"),
            dccMw: document.getElementById("tmi_stats_dcc_mw"),
            dccSc: document.getElementById("tmi_stats_dcc_sc"),
            dccW: document.getElementById("tmi_stats_dcc_w"),
            aspm77: document.getElementById("tmi_stats_aspm77"),
            oep35: document.getElementById("tmi_stats_oep35"),
            core30: document.getElementById("tmi_stats_core30")
        };

        var hasStatsElements = Object.values(statsElements).some(function(el) { return el !== null; });

        if (hasStatsElements) {
            var updateFlightStats = function() {
                fetch("api/adl/stats.php", { cache: "no-cache" })
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        if (!data) return;

                        // Update global counts
                        if (data.global) {
                            if (statsElements.globalTotal) {
                                statsElements.globalTotal.textContent = data.global.total || 0;
                            }
                            if (statsElements.dd) {
                                statsElements.dd.textContent = data.global.domestic_to_domestic || 0;
                            }
                            if (statsElements.di) {
                                statsElements.di.textContent = data.global.domestic_to_intl || 0;
                            }
                            if (statsElements.id) {
                                statsElements.id.textContent = data.global.intl_to_domestic || 0;
                            }
                            if (statsElements.ii) {
                                statsElements.ii.textContent = data.global.intl_to_intl || 0;
                            }
                        }

                        // Update domestic counts
                        if (data.domestic) {
                            // Calculate domestic arrivals total (sum of DCC regions)
                            var domesticArrTotal = 0;
                            if (data.domestic.arr_dcc) {
                                var dcc = data.domestic.arr_dcc;
                                domesticArrTotal = (dcc.NE || 0) + (dcc.SE || 0) + (dcc.MW || 0) + 
                                                   (dcc.SC || 0) + (dcc.W || 0) + (dcc.Other || 0);
                                
                                if (statsElements.dccNe) statsElements.dccNe.textContent = dcc.NE || 0;
                                if (statsElements.dccSe) statsElements.dccSe.textContent = dcc.SE || 0;
                                if (statsElements.dccMw) statsElements.dccMw.textContent = dcc.MW || 0;
                                if (statsElements.dccSc) statsElements.dccSc.textContent = dcc.SC || 0;
                                if (statsElements.dccW) statsElements.dccW.textContent = dcc.W || 0;
                            }

                            if (statsElements.domesticTotal) {
                                statsElements.domesticTotal.textContent = domesticArrTotal;
                            }

                            // Airport tiers
                            if (data.domestic.arr_aspm77 && statsElements.aspm77) {
                                statsElements.aspm77.textContent = data.domestic.arr_aspm77.yes || 0;
                            }
                            if (data.domestic.arr_oep35 && statsElements.oep35) {
                                statsElements.oep35.textContent = data.domestic.arr_oep35.yes || 0;
                            }
                            if (data.domestic.arr_core30 && statsElements.core30) {
                                statsElements.core30.textContent = data.domestic.arr_core30.yes || 0;
                            }
                        }
                    })
                    .catch(function(err) {
                        console.error("Error fetching flight stats:", err);
                    });
            };

            // Initial load and refresh every 15 seconds
            updateFlightStats();
            setInterval(updateFlightStats, 15000);
        }

        // Load Tier, airport, and BTS info first, then populate scope selector
        Promise.all([loadTierInfo(), loadAirportInfo(), loadBtsStats()]).then(function() {
            populateScopeSelector();
            refreshAdl();
        }).catch(function(err) {
            console.error("Error initializing TMI page", err);
        });

        // Wire up advisory auto-update
        var ids = [
            "gs_ctl_element", "gs_element_type", "gs_adv_number",
            "gs_start", "gs_end", "gs_airports", "gs_origin_centers",
            "gs_origin_airports", "gs_flt_incl_carrier", "gs_flt_incl_type",
            "gs_dep_facilities", "gs_prob_ext", "gs_impacting_condition",
            "gs_comments"
        ];
        ids.forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener("change", buildAdvisory);
            el.addEventListener("keyup", buildAdvisory);
        });

        var scopeSel = document.getElementById("gs_scope_select");
        if (scopeSel) {
            scopeSel.addEventListener("change", recomputeScopeFromSelector);
        }

        // Copy Advisory button handler
        var copyAdvBtn = document.getElementById("gs_copy_advisory_btn");
        if (copyAdvBtn) {
            copyAdvBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                copyAdvisoryToClipboard();
            });
        }

        // Reset button handler
        var resetBtn = document.getElementById("gs_reset_btn");
        if (resetBtn) {
            resetBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                resetGsForm();
            });
        }

        var previewBtn = document.getElementById("gs_preview_flights_btn");
        if (previewBtn) {
            previewBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                buildAdvisory();
                handleGsPreview();
            });
        }

        // Workflow toolbar buttons (ADL/GS sandbox)
        var previewBtn2 = document.getElementById("gs_preview_btn");
        if (previewBtn2) {
            previewBtn2.addEventListener("click", function(ev) {
                ev.preventDefault();
                buildAdvisory();
                handleGsPreview();
            });
        }

        var simulateBtn = document.getElementById("gs_simulate_btn");
        if (simulateBtn) {
            simulateBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                buildAdvisory();
                handleGsSimulate();
            });
        }

        var sendActualBtn = document.getElementById("gs_send_actual_btn");
        if (sendActualBtn) {
            sendActualBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                buildAdvisory();
                handleGsSendActual();
            });
            // Initialize as disabled - must run Simulate first
            setSendActualEnabled(false, "Run 'Simulate' first to enable");
        }

        var purgeLocalBtn = document.getElementById("gs_purge_local_btn");
        if (purgeLocalBtn) {
            purgeLocalBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                handleGsPurgeLocal();
            });
        }

        var purgeAllBtn = document.getElementById("gs_purge_all_btn");
        if (purgeAllBtn) {
            purgeAllBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                handleGsPurgeAll();
            });
        }

        // View Flight List button - shows current GS flight list from simulation/preview
        var viewFlightListBtn = document.getElementById("gs_view_flight_list_btn");
        if (viewFlightListBtn) {
            viewFlightListBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                handleViewFlightList();
            });
        }

        // Model GS button - opens the Model GS section with Data Graph
        var openModelBtn = document.getElementById("gs_open_model_btn");
        if (openModelBtn) {
            openModelBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                openModelGsSection();
            });
        }

        // Model GS close button
        var modelCloseBtn = document.getElementById("gs_model_close_btn");
        if (modelCloseBtn) {
            modelCloseBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                closeModelGsSection();
            });
        }

        // Initialize Model GS Power Run event handlers
        initModelGsHandlers();

        // Flight List modal link to Model GS
        var fltListOpenModel = document.getElementById("gs_flt_list_open_model");
        if (fltListOpenModel) {
            fltListOpenModel.addEventListener("click", function(ev) {
                ev.preventDefault();
                // Close the modal first
                if (window.jQuery && window.jQuery.fn.modal) {
                    window.jQuery("#gs_flight_list_modal").modal("hide");
                }
                openModelGsSection();
            });
        }

        // Flights Matching table sortable column handler
        document.addEventListener("click", function(ev) {
            var th = ev.target.closest(".gs-matching-sortable");
            if (!th) return;
            
            var sortField = th.getAttribute("data-sort");
            if (!sortField) return;

            // Toggle order if same field, else default to asc
            if (GS_MATCHING_SORT.field === sortField) {
                GS_MATCHING_SORT.order = GS_MATCHING_SORT.order === "asc" ? "desc" : "asc";
            } else {
                GS_MATCHING_SORT = { field: sortField, order: "asc" };
            }

            // Re-render the flights matching table with new sort
            sortAndRenderMatchingTable();
        });

        // === EXEMPTION EVENT HANDLERS ===
        // Wire up exemption fields to update summary when changed
        var exemptionFields = [
            "gs_exempt_orig_airports", "gs_exempt_orig_tracons", "gs_exempt_orig_artccs",
            "gs_exempt_dest_airports", "gs_exempt_dest_tracons", "gs_exempt_dest_artccs",
            "gs_exempt_type_jet", "gs_exempt_type_turboprop", "gs_exempt_type_prop",
            "gs_exempt_has_edct", "gs_exempt_active_only", "gs_exempt_depart_within",
            "gs_exempt_alt_below", "gs_exempt_alt_above", "gs_exempt_flights"
        ];
        exemptionFields.forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener("change", updateExemptionSummary);
            if (el.type === "text" || el.type === "number") {
                el.addEventListener("keyup", updateExemptionSummary);
            }
        });

        // Toggle icon for exemptions collapse
        var exemptionsBody = document.getElementById("gs_exemptions_body");
        var exemptionsIcon = document.getElementById("gs_exemptions_toggle_icon");
        if (exemptionsBody && exemptionsIcon) {
            exemptionsBody.addEventListener("shown.bs.collapse", function() {
                exemptionsIcon.className = "fas fa-chevron-up";
            });
            exemptionsBody.addEventListener("hidden.bs.collapse", function() {
                exemptionsIcon.className = "fas fa-chevron-down";
            });
            // jQuery fallback for Bootstrap 4
            if (window.jQuery) {
                window.jQuery(exemptionsBody).on("shown.bs.collapse", function() {
                    exemptionsIcon.className = "fas fa-chevron-up";
                });
                window.jQuery(exemptionsBody).on("hidden.bs.collapse", function() {
                    exemptionsIcon.className = "fas fa-chevron-down";
                });
            }
        }

        // === ECR (EDCT CHANGE REQUEST) EVENT HANDLERS ===
        initializeEcrHandlers();

        // Initialize default GS Start and End times
        initializeDefaultGsTimes();
    });

    // === ECR FUNCTIONALITY ===
    var ECR_CURRENT_FLIGHT = null;

    function initializeEcrHandlers() {
        // Get Flight Data button
        var getFlightBtn = document.getElementById("ecr_get_flight_btn");
        if (getFlightBtn) {
            getFlightBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                ecrGetFlightData();
            });
        }

        // Apply Model button
        var applyModelBtn = document.getElementById("ecr_apply_model_btn");
        if (applyModelBtn) {
            applyModelBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                ecrApplyModel();
            });
        }

        // Send Request button
        var sendRequestBtn = document.getElementById("ecr_send_request_btn");
        if (sendRequestBtn) {
            sendRequestBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                ecrSendRequest();
            });
        }

        // Clear All button
        var clearAllBtn = document.getElementById("ecr_clear_btn");
        if (clearAllBtn) {
            clearAllBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                ecrClearAll();
            });
        }

        // Default Range button
        var defaultRangeBtn = document.getElementById("ecr_default_range_btn");
        if (defaultRangeBtn) {
            defaultRangeBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                document.getElementById("ecr_cta_range").value = 60;
                document.getElementById("ecr_max_add_delay").value = 60;
            });
        }

        // Manual method toggle
        var methodRadios = document.querySelectorAll('input[name="ecr_method"]');
        methodRadios.forEach(function(radio) {
            radio.addEventListener("change", function() {
                var manualSection = document.getElementById("ecr_manual_section");
                if (manualSection) {
                    manualSection.style.display = this.value === "MANUAL" ? "flex" : "none";
                }
            });
        });

        // CTA Range change updates Max Additional Delay
        var ctaRangeEl = document.getElementById("ecr_cta_range");
        if (ctaRangeEl) {
            ctaRangeEl.addEventListener("change", function() {
                document.getElementById("ecr_max_add_delay").value = this.value;
            });
        }

        // Manual EDCT change calculates CTA
        var manualEdctEl = document.getElementById("ecr_manual_edct");
        if (manualEdctEl) {
            manualEdctEl.addEventListener("change", function() {
                ecrCalculateManualCta();
            });
        }
    }

    function ecrGetFlightData() {
        var acid = getValue("ecr_acid").toUpperCase();
        var orig = getValue("ecr_orig").toUpperCase();
        var dest = getValue("ecr_dest").toUpperCase();

        if (!acid) {
            if (window.Swal) {
                window.Swal.fire({ icon: "warning", title: "ACID Required", text: "Please enter a flight callsign (ACID)." });
            } else {
                alert("Please enter a flight callsign (ACID).");
            }
            return;
        }

        // Search in the current ADL data
        var flight = null;
        if (GS_ADL && Array.isArray(GS_ADL)) {
            flight = GS_ADL.find(function(f) {
                var cs = (f.callsign || f.CALLSIGN || "").toUpperCase();
                var fOrig = (f.fp_dept_icao || f.FP_DEPT_ICAO || f.dep_icao || "").toUpperCase();
                var fDest = (f.fp_dest_icao || f.FP_DEST_ICAO || f.arr_icao || "").toUpperCase();
                
                if (cs !== acid) return false;
                if (orig && fOrig !== orig) return false;
                if (dest && fDest !== dest) return false;
                return true;
            });
        }

        if (!flight) {
            if (window.Swal) {
                window.Swal.fire({ icon: "error", title: "Flight Not Found", text: "Could not find flight " + acid + " in the current ADL data." });
            } else {
                alert("Could not find flight " + acid + " in the current ADL data.");
            }
            return;
        }

        ECR_CURRENT_FLIGHT = flight;
        ecrPopulateFlightData(flight);
    }

    function ecrPopulateFlightData(flight) {
        // Show flight data section
        var flightDataSection = document.getElementById("ecr_flight_data_section");
        var updateSection = document.getElementById("ecr_update_section");
        if (flightDataSection) flightDataSection.style.display = "block";
        if (updateSection) updateSection.style.display = "block";

        // Parse times
        var igtd = flight.igtd_utc || flight.IGTD_UTC || flight.orig_etd_utc || "-";
        var ctd = flight.ctd_utc || flight.CTD_UTC || "-";
        var etd = flight.etd_runway_utc || flight.ETD_RUNWAY_UTC || "-";
        var ertd = flight.ertd_utc || flight.ERTD_UTC || "-";
        var ete = flight.ete_minutes || flight.ETE_MINUTES || "-";

        var igta = flight.igta_utc || flight.IGTA_UTC || flight.orig_eta_utc || "-";
        var cta = flight.cta_utc || flight.CTA_UTC || "-";
        var eta = flight.eta_runway_utc || flight.ETA_RUNWAY_UTC || "-";
        var erta = flight.erta_utc || flight.ERTA_UTC || "-";
        var delay = flight.program_delay_min || flight.PROGRAM_DELAY_MIN || 0;

        var ctlType = flight.ctl_type || flight.CTL_TYPE || "-";
        var delayStatus = flight.delay_status || flight.DELAY_STATUS || "-";

        // Format times for display
        function formatTime(isoStr) {
            if (!isoStr || isoStr === "-") return "-";
            try {
                var d = new Date(isoStr);
                if (isNaN(d.getTime())) return isoStr;
                var dd = String(d.getUTCDate()).padStart(2, "0");
                var hh = String(d.getUTCHours()).padStart(2, "0");
                var mm = String(d.getUTCMinutes()).padStart(2, "0");
                return dd + "/" + hh + mm + "Z";
            } catch (e) {
                return isoStr;
            }
        }

        // Populate fields
        setText("ecr_igtd", formatTime(igtd));
        setText("ecr_ctd", formatTime(ctd));
        setText("ecr_etd", formatTime(etd));
        setText("ecr_ertd", formatTime(ertd));
        setText("ecr_ete", ete !== "-" ? ete + " min" : "-");

        setText("ecr_igta", formatTime(igta));
        setText("ecr_cta", formatTime(cta));
        setText("ecr_eta", formatTime(eta));
        setText("ecr_erta", formatTime(erta));
        setText("ecr_delay", delay > 0 ? delay + " min" : "0 min");

        setText("ecr_ctl_type", ctlType);
        setText("ecr_delay_status", delayStatus);

        // Set the delay color
        var delayEl = document.getElementById("ecr_delay");
        if (delayEl) {
            delayEl.className = "font-weight-bold";
            if (delay > 60) delayEl.classList.add("text-danger");
            else if (delay > 30) delayEl.classList.add("text-warning");
            else delayEl.classList.add("text-success");
        }

        // Pre-populate origin/dest if not already set
        if (!getValue("ecr_orig")) {
            document.getElementById("ecr_orig").value = flight.fp_dept_icao || flight.FP_DEPT_ICAO || "";
        }
        if (!getValue("ecr_dest")) {
            document.getElementById("ecr_dest").value = flight.fp_dest_icao || flight.FP_DEST_ICAO || "";
        }
    }

    function setText(id, text) {
        var el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    function ecrApplyModel() {
        if (!ECR_CURRENT_FLIGHT) {
            if (window.Swal) {
                window.Swal.fire({ icon: "warning", title: "No Flight", text: "Please get flight data first." });
            }
            return;
        }

        var earliestEdct = getValue("ecr_earliest_edct");
        if (!earliestEdct) {
            if (window.Swal) {
                window.Swal.fire({ icon: "warning", title: "Earliest EDCT Required", text: "Please enter the earliest EDCT the flight can comply with." });
            }
            return;
        }

        // Calculate new CTD and CTA based on method
        var method = document.querySelector('input[name="ecr_method"]:checked').value;
        var ctaRange = parseInt(document.getElementById("ecr_cta_range").value, 10) || 60;
        var ete = parseInt(ECR_CURRENT_FLIGHT.ete_minutes || ECR_CURRENT_FLIGHT.ETE_MINUTES || 120, 10);

        // Parse earliest EDCT as UTC
        var earliestEdctDate = new Date(earliestEdct + "Z");
        var newCtdDate = new Date(earliestEdctDate);
        var newCtaDate = new Date(earliestEdctDate.getTime() + ete * 60 * 1000);

        // Current delay
        var currentDelay = parseInt(ECR_CURRENT_FLIGHT.program_delay_min || ECR_CURRENT_FLIGHT.PROGRAM_DELAY_MIN || 0, 10);

        // Calculate delay change
        var currentCtdStr = ECR_CURRENT_FLIGHT.ctd_utc || ECR_CURRENT_FLIGHT.CTD_UTC;
        var delayChange = 0;
        if (currentCtdStr) {
            var currentCtdDate = new Date(currentCtdStr);
            delayChange = Math.round((newCtdDate - currentCtdDate) / 60000);
        }

        // Show modeled results
        var modelResults = document.getElementById("ecr_model_results");
        if (modelResults) modelResults.style.display = "block";

        function formatDateTime(d) {
            var dd = String(d.getUTCDate()).padStart(2, "0");
            var hh = String(d.getUTCHours()).padStart(2, "0");
            var mm = String(d.getUTCMinutes()).padStart(2, "0");
            return dd + "/" + hh + mm + "Z";
        }

        setText("ecr_new_ctd", formatDateTime(newCtdDate));
        setText("ecr_new_cta", formatDateTime(newCtaDate));
        
        var delayChangeEl = document.getElementById("ecr_delay_change");
        if (delayChangeEl) {
            if (delayChange > 0) {
                delayChangeEl.textContent = "+" + delayChange + " min";
                delayChangeEl.className = "text-danger";
            } else if (delayChange < 0) {
                delayChangeEl.textContent = delayChange + " min";
                delayChangeEl.className = "text-success";
            } else {
                delayChangeEl.textContent = "0 min";
                delayChangeEl.className = "text-muted";
            }
        }

        // Enable Send Request button
        var sendBtn = document.getElementById("ecr_send_request_btn");
        if (sendBtn) sendBtn.disabled = false;
    }

    function ecrCalculateManualCta() {
        if (!ECR_CURRENT_FLIGHT) return;
        
        var manualEdct = getValue("ecr_manual_edct");
        if (!manualEdct) return;

        var ete = parseInt(ECR_CURRENT_FLIGHT.ete_minutes || ECR_CURRENT_FLIGHT.ETE_MINUTES || 120, 10);
        var edctDate = new Date(manualEdct + "Z");
        var ctaDate = new Date(edctDate.getTime() + ete * 60 * 1000);

        var dd = String(ctaDate.getUTCDate()).padStart(2, "0");
        var hh = String(ctaDate.getUTCHours()).padStart(2, "0");
        var mm = String(ctaDate.getUTCMinutes()).padStart(2, "0");

        var ctaEl = document.getElementById("ecr_manual_cta");
        if (ctaEl) ctaEl.value = dd + "/" + hh + mm + "Z";
    }

    function ecrSendRequest() {
        if (!ECR_CURRENT_FLIGHT) {
            if (window.Swal) {
                window.Swal.fire({ icon: "warning", title: "No Flight", text: "Please get flight data and apply model first." });
            }
            return;
        }

        var method = document.querySelector('input[name="ecr_method"]:checked').value;
        var earliestEdct = getValue("ecr_earliest_edct");
        var ctaRange = parseInt(document.getElementById("ecr_cta_range").value, 10) || 60;
        var updateErta = document.getElementById("ecr_update_erta").checked;

        // For manual method, use the manual EDCT
        var newEdct = earliestEdct;
        if (method === "MANUAL") {
            newEdct = getValue("ecr_manual_edct") || earliestEdct;
        }

        var payload = {
            acid: getValue("ecr_acid").toUpperCase(),
            orig: getValue("ecr_orig").toUpperCase(),
            dest: getValue("ecr_dest").toUpperCase(),
            method: method,
            earliest_edct: utcIsoFromDatetimeLocal(earliestEdct),
            new_edct: utcIsoFromDatetimeLocal(newEdct),
            cta_range: ctaRange,
            update_erta: updateErta
        };

        // In a real implementation, this would call the ECR API
        // For now, we'll simulate an update to the local ADL data
        var responseSection = document.getElementById("ecr_response_section");
        var responseText = document.getElementById("ecr_response_text");

        if (responseSection && responseText) {
            responseSection.style.display = "block";
            
            var ctlTypeCode = method === "SCS" ? "SCS" : (method === "MANUAL" || method === "LIMITED" || method === "UNLIMITED" ? "UPD" : "ECR");
            
            responseText.textContent = 
                "ECR Request Processed\n" +
                "=====================\n" +
                "Flight: " + payload.acid + "\n" +
                "Method: " + method + "\n" +
                "New EDCT: " + payload.new_edct + "\n" +
                "Control Type: " + ctlTypeCode + "\n" +
                "Status: ACCEPTED";
        }

        if (window.Swal) {
            window.Swal.fire({
                icon: "success",
                title: "ECR Request Sent",
                text: "EDCT update request has been processed for " + payload.acid + ".",
                timer: 3000
            });
        }
    }

    function ecrClearAll() {
        ECR_CURRENT_FLIGHT = null;
        
        // Clear input fields
        var fields = ["ecr_acid", "ecr_orig", "ecr_dest", "ecr_earliest_edct", "ecr_manual_edct", "ecr_manual_cta"];
        fields.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = "";
        });

        // Reset radio to SCS
        var scsRadio = document.getElementById("ecr_method_scs");
        if (scsRadio) scsRadio.checked = true;

        // Hide sections
        var sections = ["ecr_flight_data_section", "ecr_update_section", "ecr_model_results", "ecr_response_section", "ecr_manual_section"];
        sections.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.style.display = "none";
        });

        // Reset CTA range
        var ctaRange = document.getElementById("ecr_cta_range");
        var maxDelay = document.getElementById("ecr_max_add_delay");
        if (ctaRange) ctaRange.value = 60;
        if (maxDelay) maxDelay.value = 60;

        // Disable send button
        var sendBtn = document.getElementById("ecr_send_request_btn");
        if (sendBtn) sendBtn.disabled = true;
    }

    // Open ECR modal for a specific flight (called from flight table row click)
    function openEcrForFlight(callsign, orig, dest) {
        // Populate the ECR modal fields
        var acidEl = document.getElementById("ecr_acid");
        var origEl = document.getElementById("ecr_orig");
        var destEl = document.getElementById("ecr_dest");

        if (acidEl) acidEl.value = callsign || "";
        if (origEl) origEl.value = orig || "";
        if (destEl) destEl.value = dest || "";

        // Open the modal
        if (window.jQuery && window.jQuery.fn.modal) {
            window.jQuery("#ecr_modal").modal("show");
        }

        // Auto-fetch flight data
        if (callsign) {
            setTimeout(function() {
                ecrGetFlightData();
            }, 300);
        }
    }

    // Delegated click handler for ECR links in flight tables
    document.addEventListener("click", function(ev) {
        var link = ev.target.closest(".ecr-link");
        if (!link) return;
        
        ev.preventDefault();
        ev.stopPropagation();
        
        var row = link.closest("tr");
        if (!row) return;
        
        var callsign = row.getAttribute("data-callsign") || "";
        var orig = row.getAttribute("data-orig") || "";
        var dest = row.getAttribute("data-dest") || "";
        
        openEcrForFlight(callsign, orig, dest);
    });

    // Global variable for Flights Matching table sort state
    var GS_MATCHING_SORT = { field: "acid", order: "asc" };
    var GS_MATCHING_FLIGHTS = [];
    var GS_MATCHING_ROWS = [];

    // Initialize default GS Start (current UTC) and End (+1 hour, ending on :14/:29/:44/:59)
    function initializeDefaultGsTimes() {
        var gsStartEl = document.getElementById("gs_start");
        var gsEndEl = document.getElementById("gs_end");
        
        if (!gsStartEl || !gsEndEl) return;

        var now = new Date();
        
        // GS Start: current UTC time
        var startYear = now.getUTCFullYear();
        var startMonth = String(now.getUTCMonth() + 1).padStart(2, "0");
        var startDay = String(now.getUTCDate()).padStart(2, "0");
        var startHour = String(now.getUTCHours()).padStart(2, "0");
        var startMin = String(now.getUTCMinutes()).padStart(2, "0");
        gsStartEl.value = startYear + "-" + startMonth + "-" + startDay + "T" + startHour + ":" + startMin;

        // GS End: current UTC + 1 hour, but end on :14, :29, :44, or :59
        var endTime = new Date(now.getTime() + 60 * 60 * 1000); // +1 hour
        var endMinutes = endTime.getUTCMinutes();
        
        // Find nearest :14, :29, :44, or :59
        var endPoints = [14, 29, 44, 59];
        var targetMin = 59;
        for (var i = 0; i < endPoints.length; i++) {
            if (endMinutes <= endPoints[i]) {
                targetMin = endPoints[i];
                break;
            }
        }
        // If minutes > 59, roll to next hour at :14
        if (endMinutes > 59) {
            endTime = new Date(endTime.getTime() + (60 - endMinutes + 14) * 60 * 1000);
            targetMin = 14;
        }

        endTime.setUTCMinutes(targetMin);
        endTime.setUTCSeconds(0);

        var endYear = endTime.getUTCFullYear();
        var endMonth = String(endTime.getUTCMonth() + 1).padStart(2, "0");
        var endDay = String(endTime.getUTCDate()).padStart(2, "0");
        var endHour = String(endTime.getUTCHours()).padStart(2, "0");
        var endMinFormatted = String(endTime.getUTCMinutes()).padStart(2, "0");
        gsEndEl.value = endYear + "-" + endMonth + "-" + endDay + "T" + endHour + ":" + endMinFormatted;
    }

    // Sort and re-render the Flights Matching table
    function sortAndRenderMatchingTable() {
        if (!GS_MATCHING_ROWS || !GS_MATCHING_ROWS.length) return;
        
        var field = GS_MATCHING_SORT.field;
        var order = GS_MATCHING_SORT.order;

        // Sort the processed rows
        GS_MATCHING_ROWS.sort(function(a, b) {
            var valA, valB;
            switch (field) {
                case "acid":
                    valA = (a.callsign || "").toLowerCase();
                    valB = (b.callsign || "").toLowerCase();
                    break;
                case "etd":
                    valA = a.etdEpoch || 0;
                    valB = b.etdEpoch || 0;
                    break;
                case "edct":
                    valA = a.edctEpoch || 0;
                    valB = b.edctEpoch || 0;
                    break;
                case "eta":
                    valA = a.roughEtaEpoch || 0;
                    valB = b.roughEtaEpoch || 0;
                    break;
                case "dcenter":
                    valA = (AIRPORT_CENTER_MAP[(a.dep || "").toUpperCase()] || "").toLowerCase();
                    valB = (AIRPORT_CENTER_MAP[(b.dep || "").toUpperCase()] || "").toLowerCase();
                    break;
                case "orig":
                    valA = (a.dep || "").toLowerCase();
                    valB = (b.dep || "").toLowerCase();
                    break;
                case "dest":
                    valA = (a.arr || "").toLowerCase();
                    valB = (b.arr || "").toLowerCase();
                    break;
                default:
                    valA = (a.callsign || "").toLowerCase();
                    valB = (b.callsign || "").toLowerCase();
            }

            if (valA < valB) return order === "asc" ? -1 : 1;
            if (valA > valB) return order === "asc" ? 1 : -1;
            return 0;
        });

        // Re-render the table with sorted rows
        renderSortedMatchingTable(GS_MATCHING_ROWS);
        
        // Update sort indicators in header
        updateSortIndicators();
    }

    // Update sort direction indicators in table headers
    function updateSortIndicators() {
        var headers = document.querySelectorAll(".gs-matching-sortable");
        headers.forEach(function(th) {
            var icon = th.querySelector("i.fas");
            if (!icon) return;
            
            var sortField = th.getAttribute("data-sort");
            if (sortField === GS_MATCHING_SORT.field) {
                icon.className = GS_MATCHING_SORT.order === "asc" 
                    ? "fas fa-sort-up fa-xs" 
                    : "fas fa-sort-down fa-xs";
            } else {
                icon.className = "fas fa-sort fa-xs text-muted";
            }
        });
    }

    // Render the sorted matching flights table
    function renderSortedMatchingTable(rows) {
        var tbody = document.getElementById("gs_flight_table_body");
        if (!tbody) return;

        // Get airport colors for destination coloring
        var apStr = getValue("gs_airports") || "";
        var apTokens = apStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });
        var airports = expandAirportTokensWithFacilities(apTokens);
        var airportColors = buildAirportColorMap(airports);

        GS_FLIGHT_ROW_INDEX = {};
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-3">No flights match the current filters.</td></tr>';
            return;
        }

        var html = "";
        rows.forEach(function(r) {
            var cs = r.callsign || "";
            var rowId = makeRowIdForCallsign(cs);
            GS_FLIGHT_ROW_INDEX[rowId] = r;

            var dep = (r.dep || "").toUpperCase();
            var arr = (r.arr || "").toUpperCase();
            var center = AIRPORT_CENTER_MAP[dep] || "";
            var destColor = airportColors[arr] || "";

            // Build status column from control type and delay status
            var statusText = "";
            if (r._adl && r._adl.raw) {
                var ctlType = r._adl.raw.ctl_type || r._adl.raw.CTL_TYPE || "";
                var delayStatus = r._adl.raw.delay_status || r._adl.raw.DELAY_STATUS || "";
                var flightStatus = r.flightStatus || "";
                statusText = ctlType || delayStatus || flightStatus || "";
            }

            html += '<tr id="' + escapeHtml(rowId) + '" ' +
                'data-callsign="' + escapeHtml(cs) + '" ' +
                'data-route="' + escapeHtml(r.route || "") + '" ' +
                'data-filed-dep-epoch="' + (r.filedDepEpoch != null ? String(r.filedDepEpoch) : "") + '" ' +
                'data-etd-epoch="' + (r.etdEpoch != null ? String(r.etdEpoch) : "") + '" ' +
                'data-edct-epoch="' + (r.edctEpoch != null ? String(r.edctEpoch) : "") + '" ' +
                'data-eta-epoch="' + (r.roughEtaEpoch != null ? String(r.roughEtaEpoch) : "") + '" ' +
                'data-takeoff-epoch="' + (r.tkofEpoch != null ? String(r.tkofEpoch) : "") + '" ' +
                'data-mft-epoch="' + (r.mftEpoch != null ? String(r.mftEpoch) : "") + '" ' +
                'data-vt-epoch="' + (r.vtEpoch != null ? String(r.vtEpoch) : "") + '" ' +
                'data-ete-minutes="' + (r.eteMinutes != null ? String(r.eteMinutes) : "") + '"' +
                ">" +
                "<td><strong>" + escapeHtml(cs) + "</strong></td>" +
                '<td class="gs_etd_cell">' + (r.etdEpoch ? (escapeHtml(r.etdPrefix || "") + " " + escapeHtml(formatZuluFromEpoch(r.etdEpoch))) : "") + "</td>" +
                '<td class="gs_edct_cell">' + (r.edctEpoch ? escapeHtml(formatZuluFromEpoch(r.edctEpoch)) : "") + "</td>" +
                '<td class="gs_eta_cell" style="color:' + escapeHtml(destColor) + ';">' +
                    (r.roughEtaEpoch ? (escapeHtml(r.etaPrefix || "") + " " + escapeHtml(formatZuluFromEpoch(r.roughEtaEpoch))) : "") + "</td>" +
                "<td>" + escapeHtml(center) + "</td>" +
                "<td>" + escapeHtml(dep) + "</td>" +
                '<td style="color:' + escapeHtml(destColor) + ';">' + escapeHtml(arr) + "</td>" +
                "<td>" + escapeHtml(statusText) + "</td>" +
                "</tr>";
        });

        tbody.innerHTML = html;
        applyTimeFilterToTable();
    }

    // Open Model GS Section
    function openModelGsSection() {
        var section = document.getElementById("gs_model_section");
        if (section) {
            section.style.display = "";
            section.scrollIntoView({ behavior: "smooth", block: "start" });
            
            // Render the data graph with current flight list data
            if (GS_FLIGHT_LIST_DATA || GS_LAST_SIMULATION_DATA) {
                var data = GS_FLIGHT_LIST_DATA || GS_LAST_SIMULATION_DATA;
                var payload = GS_FLIGHT_LIST_PAYLOAD || collectGsWorkflowPayload();
                renderModelGsDataGraph(data, payload);
                renderModelGsSummaryTables(data);
            }
        }
    }

    // Close Model GS Section
    function closeModelGsSection() {
        var section = document.getElementById("gs_model_section");
        if (section) {
            section.style.display = "none";
        }
    }

    // Model GS Data Graph - Enhanced Power Run Analysis
    var GS_MODEL_GRAPH_CHART = null;
    var GS_MODEL_COMPARISON_CHART = null;
    var GS_MODEL_CHART_TYPE = "bar";
    var GS_MODEL_CURRENT_DATA = null;
    var GS_MODEL_CURRENT_PAYLOAD = null;

    // Initialize Model GS event handlers
    function initModelGsHandlers() {
        // Chart view selector
        var chartViewEl = document.getElementById("gs_model_chart_view");
        if (chartViewEl) {
            chartViewEl.addEventListener("change", function() {
                if (GS_MODEL_CURRENT_DATA) {
                    renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);
                }
            });
        }

        // Time window selector
        var timeWindowEl = document.getElementById("gs_model_time_window");
        if (timeWindowEl) {
            timeWindowEl.addEventListener("change", function() {
                if (GS_MODEL_CURRENT_DATA) {
                    renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);
                    renderModelGsSummaryTables(GS_MODEL_CURRENT_DATA);
                }
            });
        }

        // Time basis selector
        var timeBasisEl = document.getElementById("gs_model_time_basis");
        if (timeBasisEl) {
            timeBasisEl.addEventListener("change", function() {
                if (GS_MODEL_CURRENT_DATA) {
                    renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);
                    renderComparisonChart(GS_MODEL_CURRENT_DATA);
                }
            });
        }

        // Filter inputs
        var filterArtccEl = document.getElementById("gs_model_filter_artcc");
        var filterCarrierEl = document.getElementById("gs_model_filter_carrier");
        var filterDebounce = null;
        var onFilterChange = function() {
            clearTimeout(filterDebounce);
            filterDebounce = setTimeout(function() {
                if (GS_MODEL_CURRENT_DATA) {
                    renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);
                    renderModelGsSummaryTables(GS_MODEL_CURRENT_DATA);
                }
            }, 300);
        };
        if (filterArtccEl) filterArtccEl.addEventListener("input", onFilterChange);
        if (filterCarrierEl) filterCarrierEl.addEventListener("input", onFilterChange);

        // Chart type toggle buttons
        var barBtn = document.getElementById("gs_model_chart_type_bar");
        var lineBtn = document.getElementById("gs_model_chart_type_line");
        if (barBtn) {
            barBtn.addEventListener("click", function() {
                GS_MODEL_CHART_TYPE = "bar";
                barBtn.classList.remove("btn-outline-light");
                barBtn.classList.add("btn-light");
                if (lineBtn) { lineBtn.classList.remove("btn-light"); lineBtn.classList.add("btn-outline-light"); }
                if (GS_MODEL_CURRENT_DATA) renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);
            });
        }
        if (lineBtn) {
            lineBtn.addEventListener("click", function() {
                GS_MODEL_CHART_TYPE = "line";
                lineBtn.classList.remove("btn-outline-light");
                lineBtn.classList.add("btn-light");
                if (barBtn) { barBtn.classList.remove("btn-light"); barBtn.classList.add("btn-outline-light"); }
                if (GS_MODEL_CURRENT_DATA) renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);
            });
        }
    }

    // Get filtered flights based on current filter settings
    function getFilteredModelFlights(flightListData) {
        var flights = flightListData.flights || [];
        
        // Time window filter
        var timeWindowEl = document.getElementById("gs_model_time_window");
        var timeWindow = timeWindowEl ? timeWindowEl.value : "all";
        
        // ARTCC filter
        var artccFilterEl = document.getElementById("gs_model_filter_artcc");
        var artccFilter = artccFilterEl ? artccFilterEl.value.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; }) : [];
        
        // Carrier filter
        var carrierFilterEl = document.getElementById("gs_model_filter_carrier");
        var carrierFilter = carrierFilterEl ? carrierFilterEl.value.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; }) : [];
        
        var nowMs = Date.now();
        
        return flights.filter(function(f) {
            // Time window filter
            if (timeWindow !== "all") {
                var windowMin = parseInt(timeWindow, 10);
                var timeStr = f.ctd_utc || f.etd_utc;
                if (timeStr) {
                    try {
                        var d = new Date(timeStr);
                        var diffMin = (d.getTime() - nowMs) / 60000;
                        if (diffMin < 0 || diffMin > windowMin) return false;
                    } catch (e) { return false; }
                }
            }
            
            // ARTCC filter
            if (artccFilter.length > 0) {
                var origArtcc = (f.dcenter || f.dep_center || f.fp_dept_artcc || "").toUpperCase();
                if (artccFilter.indexOf(origArtcc) === -1) return false;
            }
            
            // Carrier filter
            if (carrierFilter.length > 0) {
                var carrier = extractCarrier(f.acid || f.callsign || "").toUpperCase();
                if (carrierFilter.indexOf(carrier) === -1) return false;
            }
            
            return true;
        });
    }

    // Get time value based on selected time basis
    function getFlightTimeForBasis(f) {
        var timeBasisEl = document.getElementById("gs_model_time_basis");
        var basis = timeBasisEl ? timeBasisEl.value : "ctd";
        
        switch (basis) {
            case "ctd": return f.ctd_utc || f.etd_utc;
            case "etd": return f.oetd_utc || f.etd_utc || f.betd_utc;
            case "cta": return f.cta_utc || f.eta_utc;
            case "eta": return f.oeta_utc || f.eta_utc || f.beta_utc;
            default: return f.ctd_utc || f.etd_utc;
        }
    }

    function renderModelGsDataGraph(flightListData, gsPayload) {
        GS_MODEL_CURRENT_DATA = flightListData;
        GS_MODEL_CURRENT_PAYLOAD = gsPayload;

        var canvas = document.getElementById("gs_model_data_graph_canvas");
        if (!canvas) return;

        // Destroy existing chart
        if (GS_MODEL_GRAPH_CHART) {
            GS_MODEL_GRAPH_CHART.destroy();
            GS_MODEL_GRAPH_CHART = null;
        }

        var filteredFlights = getFilteredModelFlights(flightListData);
        
        // Calculate summary stats
        var totalFlts = filteredFlights.length;
        var affectedFlts = 0;
        var totalDelay = 0;
        var maxDelay = 0;
        var count60 = 0, count30 = 0, count15 = 0;
        var nowMs = Date.now();

        filteredFlights.forEach(function(f) {
            var delay = f.program_delay_min || 0;
            if (delay > 0) {
                affectedFlts++;
                totalDelay += delay;
                if (delay > maxDelay) maxDelay = delay;
            }
            
            // Horizon counts
            var timeStr = f.ctd_utc || f.etd_utc;
            if (timeStr) {
                try {
                    var d = new Date(timeStr);
                    var diffMin = (d.getTime() - nowMs) / 60000;
                    if (diffMin >= 0 && diffMin <= 60) { count60++; if (diffMin <= 30) { count30++; if (diffMin <= 15) count15++; } }
                } catch (e) { }
            }
        });

        var avgDelay = affectedFlts > 0 ? Math.round(totalDelay / affectedFlts) : 0;

        // Update summary stats
        var el;
        el = document.getElementById("gs_model_total_flts"); if (el) el.textContent = totalFlts;
        el = document.getElementById("gs_model_affected_flts"); if (el) el.textContent = affectedFlts;
        el = document.getElementById("gs_model_total_delay"); if (el) el.textContent = totalDelay + " min";
        el = document.getElementById("gs_model_max_delay"); if (el) el.textContent = maxDelay + " min";
        el = document.getElementById("gs_model_avg_delay"); if (el) el.textContent = avgDelay + " min";
        el = document.getElementById("gs_model_horizon_60"); if (el) el.textContent = count60 + " flts";
        el = document.getElementById("gs_model_horizon_30"); if (el) el.textContent = count30 + " flts";
        el = document.getElementById("gs_model_horizon_15"); if (el) el.textContent = count15 + " flts";
        
        el = document.getElementById("gs_model_ctl_element"); if (el) el.textContent = gsPayload.gs_ctl_element || "-";
        el = document.getElementById("gs_model_gs_start"); if (el) el.textContent = gsPayload.gs_start ? formatZuluFromIso(gsPayload.gs_start) : "-";
        el = document.getElementById("gs_model_gs_end"); if (el) el.textContent = gsPayload.gs_end ? formatZuluFromIso(gsPayload.gs_end) : "-";

        if (!filteredFlights.length) {
            canvas.parentElement.innerHTML = '<canvas id="gs_model_data_graph_canvas"></canvas><div class="text-center text-muted py-5">No flight data matches current filters.</div>';
            return;
        }

        // Get chart view type
        var chartViewEl = document.getElementById("gs_model_chart_view");
        var chartView = chartViewEl ? chartViewEl.value : "hourly";
        
        // Update chart title
        var chartTitleEl = document.getElementById("gs_model_chart_title");
        var chartTitles = {
            "hourly": "Data Graph - Delay Statistics by Hour",
            "orig_artcc": "Data Graph - By Origin ARTCC",
            "dest_artcc": "Data Graph - By Destination ARTCC",
            "orig_ap": "Data Graph - By Origin Airport",
            "dest_ap": "Data Graph - By Destination Airport",
            "orig_tracon": "Data Graph - By Origin TRACON",
            "dest_tracon": "Data Graph - By Destination TRACON",
            "carrier": "Data Graph - By Carrier",
            "tier": "Data Graph - By ARTCC Tier"
        };
        if (chartTitleEl) chartTitleEl.textContent = chartTitles[chartView] || "Data Graph";

        // Group data based on chart view
        var groupedData = groupFlightsForChart(filteredFlights, chartView);
        
        var labels = Object.keys(groupedData).sort();
        if (chartView === "hourly") {
            labels.sort(function(a, b) { return a.localeCompare(b); });
        } else {
            labels.sort(function(a, b) { return (groupedData[b].count || 0) - (groupedData[a].count || 0); });
            labels = labels.slice(0, 15); // Top 15
        }

        if (!labels.length) {
            canvas.parentElement.innerHTML = '<canvas id="gs_model_data_graph_canvas"></canvas><div class="text-center text-muted py-5">No data available for selected view.</div>';
            return;
        }

        var totalFltsData = labels.map(function(k) { return groupedData[k].count || 0; });
        var affectedFltsData = labels.map(function(k) { return groupedData[k].affected || 0; });
        var maxDelayData = labels.map(function(k) { return groupedData[k].maxDelay || 0; });
        var avgDelayData = labels.map(function(k) { 
            var g = groupedData[k];
            return g.affected > 0 ? Math.round(g.totalDelay / g.affected) : 0;
        });

        var ctx = canvas.getContext("2d");
        var chartType = GS_MODEL_CHART_TYPE === "line" ? "line" : "bar";
        
        GS_MODEL_GRAPH_CHART = new Chart(ctx, {
            type: chartType,
            data: {
                labels: labels,
                datasets: [
                    { label: "Total Flts", data: totalFltsData, backgroundColor: "rgba(220, 53, 69, 0.7)", borderColor: "#dc3545", borderWidth: 1, yAxisID: "y" },
                    { label: "Affected Flts", data: affectedFltsData, backgroundColor: "rgba(23, 162, 184, 0.7)", borderColor: "#17a2b8", borderWidth: 1, yAxisID: "y" },
                    { label: "Max Delay", type: "line", data: maxDelayData, borderColor: "#343a40", backgroundColor: "rgba(255,255,255,0.8)", borderWidth: 2, pointRadius: 3, fill: false, yAxisID: "y1" },
                    { label: "Avg Delay", type: "line", data: avgDelayData, borderColor: "#6f42c1", borderWidth: 2, pointRadius: 3, fill: false, yAxisID: "y1" }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: "index", intersect: false },
                plugins: {
                    legend: { display: true, position: "bottom", labels: { font: { size: 10 }, boxWidth: 12 } },
                    title: { display: false }
                },
                scales: {
                    x: { title: { display: true, text: chartView === "hourly" ? "Hour (UTC)" : chartTitles[chartView].replace("Data Graph - ", ""), font: { size: 10 } } },
                    y: { type: "linear", position: "left", title: { display: true, text: "# Flights", font: { size: 10 } }, beginAtZero: true },
                    y1: { type: "linear", position: "right", title: { display: true, text: "Delay (min)", font: { size: 10 } }, beginAtZero: true, grid: { drawOnChartArea: false } }
                }
            }
        });

        // Also render the comparison chart
        renderComparisonChart(flightListData);
    }

    // Group flights based on selected chart view
    function groupFlightsForChart(flights, chartView) {
        var grouped = {};
        
        flights.forEach(function(f) {
            var key = "";
            
            switch (chartView) {
                case "hourly":
                    var timeStr = getFlightTimeForBasis(f);
                    if (timeStr) {
                        try {
                            var d = new Date(timeStr);
                            if (!isNaN(d.getTime())) key = String(d.getUTCHours()).padStart(2, "0") + "00Z";
                        } catch (e) { }
                    }
                    break;
                case "orig_artcc":
                    key = f.dcenter || f.dep_center || f.fp_dept_artcc || "";
                    break;
                case "dest_artcc":
                    key = f.acenter || f.arr_center || f.fp_dest_artcc || AIRPORT_CENTER_MAP[(f.dest || f.fp_dest_icao || "").toUpperCase()] || "";
                    break;
                case "orig_ap":
                    key = f.orig || f.fp_dept_icao || "";
                    break;
                case "dest_ap":
                    key = f.dest || f.fp_dest_icao || "";
                    break;
                case "orig_tracon":
                    var origAp = (f.orig || f.fp_dept_icao || "").toUpperCase();
                    key = AIRPORT_TRACON_MAP[origAp] || "";
                    break;
                case "dest_tracon":
                    var destAp = (f.dest || f.fp_dest_icao || "").toUpperCase();
                    key = AIRPORT_TRACON_MAP[destAp] || "";
                    break;
                case "carrier":
                    key = extractCarrier(f.acid || f.callsign || "");
                    break;
                case "tier":
                    var tierOrig = (f.orig || f.fp_dept_icao || "").toUpperCase();
                    key = getTierForAirport(tierOrig) || "Other";
                    break;
            }
            
            if (!key) return;
            
            if (!grouped[key]) {
                grouped[key] = { count: 0, affected: 0, totalDelay: 0, maxDelay: 0 };
            }
            
            var g = grouped[key];
            g.count++;
            var delay = f.program_delay_min || 0;
            if (delay > 0) {
                g.affected++;
                g.totalDelay += delay;
                if (delay > g.maxDelay) g.maxDelay = delay;
            }
        });
        
        return grouped;
    }

    // Get tier for an airport
    function getTierForAirport(icao) {
        if (!icao) return null;
        for (var code in TMI_TIER_INFO_BY_CODE) {
            var entry = TMI_TIER_INFO_BY_CODE[code];
            if (entry.included && entry.included.indexOf(icao) !== -1) {
                return entry.label || entry.code || code;
            }
        }
        return null;
    }

    // Render Original vs Controlled comparison chart
    function renderComparisonChart(flightListData) {
        var canvas = document.getElementById("gs_model_comparison_canvas");
        if (!canvas) return;

        if (GS_MODEL_COMPARISON_CHART) {
            GS_MODEL_COMPARISON_CHART.destroy();
            GS_MODEL_COMPARISON_CHART = null;
        }

        var flights = getFilteredModelFlights(flightListData);
        if (!flights.length) return;

        // Group by hour - Original (ETD) vs Controlled (CTD)
        var hourlyOrig = {};
        var hourlyCtrl = {};

        flights.forEach(function(f) {
            var delay = f.program_delay_min || 0;
            
            // Original time (ETD)
            var origTime = f.oetd_utc || f.etd_utc || f.betd_utc;
            if (origTime) {
                try {
                    var dOrig = new Date(origTime);
                    if (!isNaN(dOrig.getTime())) {
                        var keyOrig = String(dOrig.getUTCHours()).padStart(2, "0") + "00Z";
                        if (!hourlyOrig[keyOrig]) hourlyOrig[keyOrig] = 0;
                        hourlyOrig[keyOrig]++;
                    }
                } catch (e) { }
            }
            
            // Controlled time (CTD)
            var ctrlTime = f.ctd_utc;
            if (ctrlTime) {
                try {
                    var dCtrl = new Date(ctrlTime);
                    if (!isNaN(dCtrl.getTime())) {
                        var keyCtrl = String(dCtrl.getUTCHours()).padStart(2, "0") + "00Z";
                        if (!hourlyCtrl[keyCtrl]) hourlyCtrl[keyCtrl] = 0;
                        hourlyCtrl[keyCtrl]++;
                    }
                } catch (e) { }
            }
        });

        // Merge labels
        var allKeys = {};
        Object.keys(hourlyOrig).forEach(function(k) { allKeys[k] = true; });
        Object.keys(hourlyCtrl).forEach(function(k) { allKeys[k] = true; });
        var labels = Object.keys(allKeys).sort();

        if (!labels.length) return;

        var origData = labels.map(function(k) { return hourlyOrig[k] || 0; });
        var ctrlData = labels.map(function(k) { return hourlyCtrl[k] || 0; });

        var ctx = canvas.getContext("2d");
        GS_MODEL_COMPARISON_CHART = new Chart(ctx, {
            type: "bar",
            data: {
                labels: labels,
                datasets: [
                    { label: "Original (ETD)", data: origData, backgroundColor: "rgba(40, 167, 69, 0.6)", borderColor: "#28a745", borderWidth: 1 },
                    { label: "Controlled (CTD)", data: ctrlData, backgroundColor: "rgba(255, 193, 7, 0.6)", borderColor: "#ffc107", borderWidth: 1 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: "bottom", labels: { font: { size: 10 }, boxWidth: 12 } },
                    title: { display: true, text: "Flight Distribution: Original ETD vs Controlled CTD by Hour", font: { size: 11 } }
                },
                scales: {
                    x: { title: { display: true, text: "Hour (UTC)", font: { size: 10 } } },
                    y: { title: { display: true, text: "# Flights", font: { size: 10 } }, beginAtZero: true }
                }
            }
        });
    }

    function renderModelGsSummaryTables(flightListData) {
        var flights = getFilteredModelFlights(flightListData);
        
        // Initialize all data collectors
        var origArtccData = {};
        var destArtccData = {};
        var origApData = {};
        var destApData = {};
        var origTraconData = {};
        var destTraconData = {};
        var origTierData = {};
        var destTierData = {};
        var carrierData = {};
        var hourData = {};
        var delayRanges = { "0 min": 0, "1-15 min": 0, "16-30 min": 0, "31-60 min": 0, "61-90 min": 0, "90+ min": 0 };
        
        flights.forEach(function(f) {
            var delay = f.program_delay_min || 0;
            var origArtcc = f.dcenter || f.dep_center || f.fp_dept_artcc || "";
            var destArtcc = f.acenter || f.arr_center || f.fp_dest_artcc || "";
            var origAp = (f.orig || f.fp_dept_icao || "").toUpperCase();
            var destAp = (f.dest || f.fp_dest_icao || "").toUpperCase();
            var origTracon = AIRPORT_TRACON_MAP[origAp] || "";
            var destTracon = AIRPORT_TRACON_MAP[destAp] || "";
            var origTier = getTierForAirport(origAp) || "";
            var destTier = getTierForAirport(destAp) || "";
            var carrier = extractCarrier(f.acid || f.callsign || "");
            
            // Dest ARTCC fallback
            if (!destArtcc && destAp) destArtcc = AIRPORT_CENTER_MAP[destAp] || "";
            
            // Hour
            var hourKey = "";
            var timeStr = f.ctd_utc || f.etd_utc;
            if (timeStr) {
                try {
                    var d = new Date(timeStr);
                    if (!isNaN(d.getTime())) hourKey = String(d.getUTCHours()).padStart(2, "0") + "00Z";
                } catch (e) { }
            }
            
            // Accumulate data
            function addTo(obj, key) {
                if (!key) return;
                if (!obj[key]) obj[key] = { count: 0, delay: 0 };
                obj[key].count++;
                obj[key].delay += delay;
            }
            
            addTo(origArtccData, origArtcc);
            addTo(destArtccData, destArtcc);
            addTo(origApData, origAp);
            addTo(destApData, destAp);
            addTo(origTraconData, origTracon);
            addTo(destTraconData, destTracon);
            addTo(origTierData, origTier);
            addTo(destTierData, destTier);
            addTo(carrierData, carrier);
            addTo(hourData, hourKey);
            
            // Delay range
            if (delay === 0) delayRanges["0 min"]++;
            else if (delay <= 15) delayRanges["1-15 min"]++;
            else if (delay <= 30) delayRanges["16-30 min"]++;
            else if (delay <= 60) delayRanges["31-60 min"]++;
            else if (delay <= 90) delayRanges["61-90 min"]++;
            else delayRanges["90+ min"]++;
        });

        // Render all tables
        renderModelTable4Col("gs_model_by_orig_artcc", origArtccData);
        renderModelTable4Col("gs_model_by_dest_artcc", destArtccData);
        renderModelTable4Col("gs_model_by_orig_ap", origApData);
        renderModelTable4Col("gs_model_by_dest_ap", destApData);
        renderModelTable4Col("gs_model_by_orig_tracon", origTraconData);
        renderModelTable4Col("gs_model_by_dest_tracon", destTraconData);
        renderModelTable4Col("gs_model_by_orig_tier", origTierData);
        renderModelTable4Col("gs_model_by_dest_tier", destTierData);
        renderModelTable4Col("gs_model_by_carrier", carrierData);
        renderModelTable4Col("gs_model_by_hour", hourData);
        
        // Delay range table
        var rangeBody = document.getElementById("gs_model_by_delay_range");
        if (rangeBody) {
            var total = flights.length || 1;
            var html = "";
            Object.keys(delayRanges).forEach(function(range) {
                var count = delayRanges[range];
                var pct = Math.round((count / total) * 100);
                html += "<tr><td>" + range + "</td><td class=\"text-right\">" + count + "</td><td class=\"text-right\">" + pct + "%</td></tr>";
            });
            rangeBody.innerHTML = html || '<tr><td colspan="3" class="text-muted">-</td></tr>';
        }
    }

    // Render a 4-column summary table (key, count, total delay, avg delay)
    function renderModelTable4Col(tbodyId, data) {
        var tbody = document.getElementById(tbodyId);
        if (!tbody) return;
        
        var entries = Object.keys(data).map(function(k) {
            var d = data[k];
            return { key: k, count: d.count, delay: d.delay, avg: d.count > 0 ? Math.round(d.delay / d.count) : 0 };
        });
        entries.sort(function(a, b) { return b.count - a.count; });
        
        if (!entries.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-muted">-</td></tr>';
            return;
        }
        
        var html = "";
        entries.slice(0, 15).forEach(function(e) {
            html += "<tr><td>" + escapeHtml(e.key) + "</td><td class=\"text-right\">" + e.count + "</td><td class=\"text-right\">" + e.delay + "</td><td class=\"text-right\">" + e.avg + "</td></tr>";
        });
        tbody.innerHTML = html;
    }

    function renderModelSummaryTable(tbodyId, data, showDelay) {
        var tbody = document.getElementById(tbodyId);
        if (!tbody) return;
        
        var entries = Object.keys(data).map(function(k) {
            return { key: k, count: data[k].count, delay: data[k].delay };
        });
        entries.sort(function(a, b) { return b.count - a.count; });
        
        if (!entries.length) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-muted">-</td></tr>';
            return;
        }
        
        var html = "";
        entries.slice(0, 10).forEach(function(e) {
            html += "<tr><td>" + escapeHtml(e.key) + "</td><td class=\"text-right\">" + e.count + "</td><td class=\"text-right\">" + e.delay + "</td></tr>";
        });
        tbody.innerHTML = html;
    }


function updateHorizonCounts(visibleRows, attrName) {
        var span60 = document.getElementById("gs_eta_60m");
        var span30 = document.getElementById("gs_eta_30m");
        var span15 = document.getElementById("gs_eta_15m");
        if (!span60 || !span30 || !span15) return;

        var nowMs = Date.now();
        var count60 = 0;
        var count30 = 0;
        var count15 = 0;

        (visibleRows || []).forEach(function(tr) {
            if (!tr) return;
            var attr = attrName || "data-eta-epoch";
            var valStr = tr.getAttribute(attr);
            if (!valStr) return;
            var epoch = parseInt(valStr, 10);
            if (isNaN(epoch)) return;

            var diffMin = (epoch - nowMs) / 60000;
            // Only count future arrivals (within the horizon)
            if (diffMin >= 0 && diffMin <= 60) {
                count60++;
                if (diffMin <= 30) {
                    count30++;
                    if (diffMin <= 15) {
                        count15++;
                    }
                }
            }
        });

        span60.textContent = String(count60);
        span30.textContent = String(count30);
        span15.textContent = String(count15);
    }

function updateDelayStats(visibleRows) {
        var spanTotal = document.getElementById("gs_delay_total");
        var spanMax = document.getElementById("gs_delay_max");
        var spanAvg = document.getElementById("gs_delay_avg");
        if (!spanTotal || !spanMax || !spanAvg) return;

        var total = 0;
        var maxDelay = 0;
        var count = 0;

        (visibleRows || []).forEach(function(tr) {
            if (!tr) return;

            var edctStr = tr.getAttribute("data-edct-epoch") || "";
            if (!edctStr) return;

            // Use ETD if present, otherwise fall back to filed departure
            var etdStr = tr.getAttribute("data-etd-epoch") || "";
            var filedStr = tr.getAttribute("data-filed-dep-epoch") || "";
            var baselineStr = etdStr || filedStr;
            if (!baselineStr) return;

            var edct = parseInt(edctStr, 10);
            var baseline = parseInt(baselineStr, 10);
            if (isNaN(edct) || isNaN(baseline)) return;

            var delayMin = Math.round((edct - baseline) / 60000);
            if (delayMin < 0) {
                delayMin = 0;
            }
            total += delayMin;
            if (delayMin > maxDelay) {
                maxDelay = delayMin;
            }
            count++;
        });

        if (!count) {
            spanTotal.textContent = "0";
            spanMax.textContent = "0";
            spanAvg.textContent = "0";
        } else {
            spanTotal.textContent = String(total);
            spanMax.textContent = String(maxDelay);
            spanAvg.textContent = String(Math.round(total / count));
        }

        // After delay spans are updated, rebuild the advisory text so the
        // "NEW TOTAL, MAXIMUM, AVERAGE DELAYS" line reflects current values.
        try {
            if (typeof buildAdvisory === "function") {
                buildAdvisory();
            }
        } catch (e) {
            console.error("Error updating advisory with delay statistics", e);
        }
    };

    // =========================================================================
    // GS Flight List Modal Functions (Enhanced)
    // For TMU coordination with ATC facilities (per FSM User Guide Ch 6 & 19)
    // Features: Sorting, Grouping, OETD/OETA, Data Graph (Figure 19-4)
    // =========================================================================

    var GS_FLIGHT_LIST_DATA = null;
    var GS_FLIGHT_LIST_PAYLOAD = null;
    var GS_FLIGHT_LIST_SORT = { field: "acid", order: "asc" };
    var GS_DATA_GRAPH_CHART = null;

    function showGsFlightListModal(flightListData, gsPayload) {
        GS_FLIGHT_LIST_DATA = flightListData;
        GS_FLIGHT_LIST_PAYLOAD = gsPayload;
        
        var modal = document.getElementById("gs_flight_list_modal");
        if (!modal) {
            console.warn("GS Flight List modal not found");
            return;
        }

        // Populate header info
        var ctlEl = document.getElementById("gs_flt_list_ctl_element");
        var startEl = document.getElementById("gs_flt_list_start");
        var endEl = document.getElementById("gs_flt_list_end");
        var totalEl = document.getElementById("gs_flt_list_total");
        var affectedEl = document.getElementById("gs_flt_list_affected");
        var maxDelayEl = document.getElementById("gs_flt_list_max_delay");
        var avgDelayEl = document.getElementById("gs_flt_list_avg_delay");
        var totalDelayEl = document.getElementById("gs_flt_list_total_delay");
        var timestampEl = document.getElementById("gs_flt_list_timestamp");
        var countBadge = document.getElementById("gs_flt_list_count_badge");

        if (ctlEl) ctlEl.textContent = gsPayload.gs_ctl_element || "-";
        if (startEl) startEl.textContent = gsPayload.gs_start ? formatZuluFromIso(gsPayload.gs_start) : "-";
        if (endEl) endEl.textContent = gsPayload.gs_end ? formatZuluFromIso(gsPayload.gs_end) : "-";
        
        var totalFlights = flightListData.total || 0;
        var affectedFlights = flightListData.affected || totalFlights;
        var maxDelay = flightListData.max_delay || 0;
        var avgDelay = Math.round(flightListData.avg_delay || 0); // 0 decimal places
        var totalDelay = flightListData.total_delay || 0;

        if (totalEl) totalEl.textContent = String(totalFlights);
        if (affectedEl) affectedEl.textContent = String(affectedFlights);
        if (maxDelayEl) maxDelayEl.textContent = String(maxDelay);
        if (avgDelayEl) avgDelayEl.textContent = String(avgDelay);
        if (totalDelayEl) totalDelayEl.textContent = String(totalDelay);
        if (countBadge) countBadge.textContent = totalFlights + " flights";
        
        // Generated time in UTC format: dd/hhmmZ
        if (timestampEl) {
            timestampEl.textContent = formatZuluFromIso(new Date().toISOString());
        }

        // Reset sort/group controls
        var groupSelect = document.getElementById("gs_flt_list_group_by");
        var sortSelect = document.getElementById("gs_flt_list_sort_by");
        if (groupSelect) groupSelect.value = "none";
        if (sortSelect) sortSelect.value = "acid_asc";
        GS_FLIGHT_LIST_SORT = { field: "acid", order: "asc" };

        // Render the flight list table
        renderFlightListTable();

        // Show the modal using Bootstrap
        if (window.jQuery && window.jQuery.fn.modal) {
            window.jQuery("#gs_flight_list_modal").modal("show");
        } else if (modal.classList) {
            modal.classList.add("show");
            modal.style.display = "block";
            document.body.classList.add("modal-open");
        }
    }

    function renderFlightListTable() {
        if (!GS_FLIGHT_LIST_DATA) return;
        
        var tbody = document.getElementById("gs_flight_list_body");
        if (!tbody) return;

        var flights = GS_FLIGHT_LIST_DATA.flights || [];
        var groupBy = document.getElementById("gs_flt_list_group_by");
        var groupValue = groupBy ? groupBy.value : "none";

        if (!flights.length) {
            tbody.innerHTML = '<tr><td colspan="14" class="text-center text-muted">No GS-controlled flights found.</td></tr>';
            clearSummaryTables();
            return;
        }

        // Sort flights
        var sortedFlights = sortFlights(flights);

        // Count statistics
        var dcenterCounts = {};
        var origCounts = {};
        var destCounts = {};
        var carrierCounts = {};

        // Group data if needed
        var groupedData = {};
        if (groupValue !== "none") {
            sortedFlights.forEach(function(f) {
                var groupKey = getGroupKey(f, groupValue);
                if (!groupedData[groupKey]) groupedData[groupKey] = [];
                groupedData[groupKey].push(f);
            });
        }

        var html = "";

        // Render grouped or flat
        if (groupValue !== "none" && Object.keys(groupedData).length > 0) {
            var groupKeys = Object.keys(groupedData).sort();
            groupKeys.forEach(function(groupKey) {
                var groupFlights = groupedData[groupKey];
                html += '<tr class="bg-light"><td colspan="14" class="font-weight-bold text-primary">' +
                    '<i class="fas fa-folder-open mr-1"></i>' + escapeHtml(groupKey) + 
                    ' <span class="badge badge-secondary">' + groupFlights.length + '</span></td></tr>';
                
                groupFlights.forEach(function(f) {
                    html += renderFlightRow(f, dcenterCounts, origCounts, destCounts, carrierCounts);
                });
            });
        } else {
            sortedFlights.forEach(function(f) {
                html += renderFlightRow(f, dcenterCounts, origCounts, destCounts, carrierCounts);
            });
        }

        tbody.innerHTML = html;

        // Populate summary tables
        renderFlightListSummary("gs_flt_list_by_dcenter", dcenterCounts);
        renderFlightListSummary("gs_flt_list_by_orig", origCounts);
        renderFlightListSummary("gs_flt_list_by_dest", destCounts);
        renderFlightListSummary("gs_flt_list_by_carrier", carrierCounts);
    }

    function renderFlightRow(f, dcenterCounts, origCounts, destCounts, carrierCounts) {
        var acid = f.acid || f.callsign || "";
        var carrier = extractCarrier(acid);
        var orig = f.orig || f.fp_dept_icao || "";
        var dest = f.dest || f.fp_dest_icao || "";
        var dcenter = f.dcenter || f.dep_center || "";
        var acenter = f.acenter || f.arr_center || "";
        
        // Original times (OETD/OETA)
        var oetdText = f.oetd_utc ? formatZuluFromIso(f.oetd_utc) : (f.etd_utc ? formatZuluFromIso(f.etd_utc) : "");
        var oetaText = f.oeta_utc ? formatZuluFromIso(f.oeta_utc) : (f.eta_utc ? formatZuluFromIso(f.eta_utc) : "");
        
        // Current times
        var etdText = f.etd_utc ? formatZuluFromIso(f.etd_utc) : "";
        var ctdText = f.ctd_utc ? formatZuluFromIso(f.ctd_utc) : "";
        var etaText = f.eta_utc ? formatZuluFromIso(f.eta_utc) : "";
        var ctaText = f.cta_utc ? formatZuluFromIso(f.cta_utc) : "";
        
        var delay = f.program_delay_min || f.absolute_delay_min || 0;
        var delayText = delay > 0 ? String(delay) : "0";
        var delayClass = delay > 60 ? "text-danger font-weight-bold" : (delay > 30 ? "text-warning" : "");
        
        var status = f.delay_status || f.ctl_type || "GS";

        // Count statistics
        if (dcenter) dcenterCounts[dcenter] = (dcenterCounts[dcenter] || 0) + 1;
        if (orig) origCounts[orig] = (origCounts[orig] || 0) + 1;
        if (dest) destCounts[dest] = (destCounts[dest] || 0) + 1;
        if (carrier) carrierCounts[carrier] = (carrierCounts[carrier] || 0) + 1;

        // Build data attributes for context menu functionality
        var dataAttrs = 
            ' data-acid="' + escapeHtml(acid) + '"' +
            ' data-orig="' + escapeHtml(orig) + '"' +
            ' data-dest="' + escapeHtml(dest) + '"' +
            ' data-dcenter="' + escapeHtml(dcenter) + '"' +
            ' data-acenter="' + escapeHtml(acenter) + '"' +
            ' data-oetd="' + escapeHtml(f.oetd_utc || f.etd_utc || "") + '"' +
            ' data-oeta="' + escapeHtml(f.oeta_utc || f.eta_utc || "") + '"' +
            ' data-etd="' + escapeHtml(f.etd_utc || "") + '"' +
            ' data-ctd="' + escapeHtml(f.ctd_utc || "") + '"' +
            ' data-eta="' + escapeHtml(f.eta_utc || "") + '"' +
            ' data-cta="' + escapeHtml(f.cta_utc || "") + '"' +
            ' data-delay="' + delay + '"' +
            ' data-status="' + escapeHtml(status) + '"' +
            ' data-carrier="' + escapeHtml(carrier) + '"';

        return "<tr class=\"gs-flt-list-row\"" + dataAttrs + ">" +
            "<td><strong>" + escapeHtml(acid) + "</strong></td>" +
            "<td>" + escapeHtml(carrier) + "</td>" +
            "<td>" + escapeHtml(orig) + "</td>" +
            "<td>" + escapeHtml(dest) + "</td>" +
            "<td>" + escapeHtml(dcenter) + "</td>" +
            "<td>" + escapeHtml(acenter) + "</td>" +
            "<td class=\"text-muted\">" + oetdText + "</td>" +
            "<td>" + etdText + "</td>" +
            "<td class=\"font-weight-bold\">" + ctdText + "</td>" +
            "<td class=\"text-muted\">" + oetaText + "</td>" +
            "<td>" + etaText + "</td>" +
            "<td>" + ctaText + "</td>" +
            "<td class=\"" + delayClass + "\">" + delayText + "</td>" +
            "<td><span class=\"badge badge-warning\">" + escapeHtml(status) + "</span></td>" +
            "</tr>";
    }

    function extractCarrier(acid) {
        if (!acid) return "";
        var match = String(acid).match(/^([A-Z]{2,3})/i);
        return match ? match[1].toUpperCase() : "";
    }

    function getGroupKey(flight, groupBy) {
        switch (groupBy) {
            case "carrier":
                return extractCarrier(flight.acid || flight.callsign || "") || "Unknown";
            case "orig_airport":
                return flight.orig || flight.fp_dept_icao || "Unknown";
            case "orig_center":
                return flight.dcenter || flight.dep_center || "Unknown";
            case "dest_airport":
                return flight.dest || flight.fp_dest_icao || "Unknown";
            case "dest_center":
                return flight.acenter || flight.arr_center || "Unknown";
            case "delay_bucket":
                var delay = flight.program_delay_min || 0;
                if (delay === 0) return "0 min (No Delay)";
                if (delay <= 15) return "1-15 min";
                if (delay <= 30) return "16-30 min";
                if (delay <= 60) return "31-60 min";
                if (delay <= 90) return "61-90 min";
                return "90+ min";
            default:
                return "All";
        }
    }

    function sortFlights(flights) {
        var sorted = flights.slice();
        var field = GS_FLIGHT_LIST_SORT.field;
        var order = GS_FLIGHT_LIST_SORT.order;

        sorted.sort(function(a, b) {
            var valA, valB;

            switch (field) {
                case "acid":
                    valA = (a.acid || a.callsign || "").toLowerCase();
                    valB = (b.acid || b.callsign || "").toLowerCase();
                    break;
                case "carrier":
                    valA = extractCarrier(a.acid || a.callsign || "");
                    valB = extractCarrier(b.acid || b.callsign || "");
                    break;
                case "orig":
                    valA = (a.orig || a.fp_dept_icao || "").toLowerCase();
                    valB = (b.orig || b.fp_dept_icao || "").toLowerCase();
                    break;
                case "dest":
                    valA = (a.dest || a.fp_dest_icao || "").toLowerCase();
                    valB = (b.dest || b.fp_dest_icao || "").toLowerCase();
                    break;
                case "dcenter":
                    valA = (a.dcenter || a.dep_center || "").toLowerCase();
                    valB = (b.dcenter || b.dep_center || "").toLowerCase();
                    break;
                case "acenter":
                    valA = (a.acenter || a.arr_center || "").toLowerCase();
                    valB = (b.acenter || b.arr_center || "").toLowerCase();
                    break;
                case "delay":
                    valA = a.program_delay_min || 0;
                    valB = b.program_delay_min || 0;
                    break;
                case "etd":
                    valA = a.etd_utc || "";
                    valB = b.etd_utc || "";
                    break;
                case "oetd":
                    valA = a.oetd_utc || a.etd_utc || "";
                    valB = b.oetd_utc || b.etd_utc || "";
                    break;
                case "eta":
                    valA = a.eta_utc || "";
                    valB = b.eta_utc || "";
                    break;
                case "oeta":
                    valA = a.oeta_utc || a.eta_utc || "";
                    valB = b.oeta_utc || b.eta_utc || "";
                    break;
                default:
                    valA = (a.acid || "").toLowerCase();
                    valB = (b.acid || "").toLowerCase();
            }

            if (valA < valB) return order === "asc" ? -1 : 1;
            if (valA > valB) return order === "asc" ? 1 : -1;
            return 0;
        });

        return sorted;
    }

    function clearSummaryTables() {
        ["gs_flt_list_by_dcenter", "gs_flt_list_by_orig", "gs_flt_list_by_dest", "gs_flt_list_by_carrier"].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.innerHTML = '<tr><td colspan="2" class="text-muted">-</td></tr>';
        });
    }

    // Data Graph functions moved to Model GS section - see renderModelGsDataGraph()

    function renderFlightListSummary(tbodyId, counts) {
        var tbody = document.getElementById(tbodyId);
        if (!tbody) return;

        var entries = Object.keys(counts).map(function(k) {
            return { key: k, count: counts[k] };
        });
        entries.sort(function(a, b) { return b.count - a.count; });

        if (!entries.length) {
            tbody.innerHTML = '<tr><td colspan="2" class="text-muted">-</td></tr>';
            return;
        }

        var html = "";
        entries.slice(0, 10).forEach(function(e) {
            html += "<tr><td>" + escapeHtml(e.key) + "</td><td class=\"text-right\">" + e.count + "</td></tr>";
        });
        if (entries.length > 10) {
            var othersCount = entries.slice(10).reduce(function(sum, e) { return sum + e.count; }, 0);
            html += '<tr class="text-muted"><td>Others</td><td class="text-right">' + othersCount + '</td></tr>';
        }
        tbody.innerHTML = html;
    }

    function formatZuluFromIso(isoStr) {
        if (!isoStr) return "";
        try {
            var d = new Date(isoStr);
            if (isNaN(d.getTime())) return isoStr;
            var dd = String(d.getUTCDate()).padStart(2, "0");
            var hh = String(d.getUTCHours()).padStart(2, "0");
            var mm = String(d.getUTCMinutes()).padStart(2, "0");
            return dd + "/" + hh + mm + "Z";
        } catch (e) {
            return isoStr;
        }
    }

    // Convert ICAO to FAA code (strip leading K for US airports)
    function icaoToFaa(icao) {
        if (!icao) return "";
        icao = String(icao).toUpperCase();
        if (AIRPORT_IATA_MAP && AIRPORT_IATA_MAP[icao]) {
            return AIRPORT_IATA_MAP[icao];
        }
        // Strip leading K for US airports
        if (icao.length === 4 && icao.charAt(0) === 'K') {
            return icao.substring(1);
        }
        return icao;
    }

    // Format snapshot time as yyyy-mm-dd hh:mm:ss.sssZ
    function formatSnapshotTime(date) {
        if (!date) return "";
        try {
            var d = date instanceof Date ? date : new Date(date);
            if (isNaN(d.getTime())) return "";
            var yyyy = d.getUTCFullYear();
            var mm = String(d.getUTCMonth() + 1).padStart(2, "0");
            var dd = String(d.getUTCDate()).padStart(2, "0");
            var hh = String(d.getUTCHours()).padStart(2, "0");
            var min = String(d.getUTCMinutes()).padStart(2, "0");
            var ss = String(d.getUTCSeconds()).padStart(2, "0");
            var ms = String(d.getUTCMilliseconds()).padStart(3, "0");
            return yyyy + "-" + mm + "-" + dd + " " + hh + ":" + min + ":" + ss + "." + ms + "Z";
        } catch (e) {
            return "";
        }
    }

    function copyGsFlightListToClipboard() {
        if (!GS_FLIGHT_LIST_DATA || !GS_FLIGHT_LIST_DATA.flights) {
            alert("No flight list data available.");
            return;
        }

        var flights = GS_FLIGHT_LIST_DATA.flights;
        var lines = [];

        // Get GS parameters from payload
        var ctlElement = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_ctl_element) || "XXX";
        var gsStartFormatted = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_start) 
            ? formatZuluFromIso(GS_FLIGHT_LIST_PAYLOAD.gs_start) : "-";
        var gsEndFormatted = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_end) 
            ? formatZuluFromIso(GS_FLIGHT_LIST_PAYLOAD.gs_end) : "-";

        // Line 1: {ARRIVAL_AIRPORT(S)} GS FLIGHT LIST - {GS_START}-{GS_END}
        lines.push(ctlElement + " GS FLIGHT LIST - " + gsStartFormatted + "-" + gsEndFormatted);

        // Line 2: ADL Time from GS_ADL.snapshotUtc
        var adlTimeFormatted = "";
        if (GS_ADL && GS_ADL.snapshotUtc instanceof Date && !isNaN(GS_ADL.snapshotUtc.getTime())) {
            adlTimeFormatted = formatZuluFromIso(GS_ADL.snapshotUtc.toISOString());
        }
        lines.push("ADL Time: " + (adlTimeFormatted || "-"));

        lines.push("Total Flights: " + flights.length);
        lines.push("Total Delay: " + (GS_FLIGHT_LIST_DATA.total_delay || 0) + " min");
        lines.push("Max Delay: " + (GS_FLIGHT_LIST_DATA.max_delay || 0) + " min");
        lines.push("Avg Delay: " + Math.round(GS_FLIGHT_LIST_DATA.avg_delay || 0) + " min");
        lines.push("");
        
        // Fixed-width column header (consolidated ORIG/DEST columns, removed CARRIER)
        lines.push(
            padRight("ACID", 10) +
            padRight("ORIG", 10) +
            padRight("DEST", 10) +
            padRight("OETD", 10) +
            padRight("CTD", 10) +
            padRight("OETA", 10) +
            padRight("CTA", 10) +
            padRight("DELAY", 6)
        );
        lines.push("-".repeat(76));

        flights.forEach(function(f) {
            // Consolidate origin: {ORIG_FAA}/{ARTCC} e.g., PHL/ZNY
            var origFaa = icaoToFaa(f.orig || "");
            var dcenter = f.dcenter || "";
            var origConsolidated = origFaa + (dcenter ? "/" + dcenter : "");

            // Consolidate destination: {DEST_FAA}/{ARTCC} e.g., ORD/ZAU
            var destFaa = icaoToFaa(f.dest || "");
            var acenter = f.acenter || "";
            var destConsolidated = destFaa + (acenter ? "/" + acenter : "");

            var row = 
                padRight(f.acid || "", 10) +
                padRight(origConsolidated, 10) +
                padRight(destConsolidated, 10) +
                padRight(f.oetd_utc ? formatZuluFromIso(f.oetd_utc) : (f.etd_utc ? formatZuluFromIso(f.etd_utc) : ""), 10) +
                padRight(f.ctd_utc ? formatZuluFromIso(f.ctd_utc) : "", 10) +
                padRight(f.oeta_utc ? formatZuluFromIso(f.oeta_utc) : (f.eta_utc ? formatZuluFromIso(f.eta_utc) : ""), 10) +
                padRight(f.cta_utc ? formatZuluFromIso(f.cta_utc) : "", 10) +
                padRight(String(f.program_delay_min || 0), 6);
            lines.push(row);
        });

        // Add snapshot time at the end
        lines.push("");
        lines.push("Snapshot Time: " + formatSnapshotTime(new Date()));

        var text = lines.join("\n");

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showCopySuccess("Flight list copied to clipboard!");
            }).catch(function(err) {
                console.error("Clipboard copy failed", err);
                fallbackCopyToClipboard(text);
            });
        } else {
            fallbackCopyToClipboard(text);
        }
    }

    function padRight(str, len) {
        str = String(str || "");
        while (str.length < len) str += " ";
        return str;
    }

    function fallbackCopyToClipboard(text) {
        var ta = document.createElement("textarea");
        ta.value = text;
        ta.style.position = "fixed";
        ta.style.left = "-9999px";
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand("copy");
            showCopySuccess("Flight list copied to clipboard!");
        } catch (e) {
            alert("Unable to copy. Please copy manually.");
        }
        document.body.removeChild(ta);
    }

    function showCopySuccess(msg) {
        if (window.Swal) {
            window.Swal.fire({
                icon: "success",
                title: "Copied!",
                text: msg,
                timer: 2000,
                showConfirmButton: false
            });
        } else {
            alert(msg);
        }
    }

    function exportGsFlightListCsv() {
        if (!GS_FLIGHT_LIST_DATA || !GS_FLIGHT_LIST_DATA.flights) {
            alert("No flight list data available.");
            return;
        }

        var flights = GS_FLIGHT_LIST_DATA.flights;
        var lines = [];

        // CSV Header with OETD/OETA
        lines.push("ACID,CARRIER,ORIG,DEST,DCENTER,ACENTER,OETD_UTC,ETD_UTC,CTD_UTC,OETA_UTC,ETA_UTC,CTA_UTC,DELAY_MIN,STATUS");

        flights.forEach(function(f) {
            var row = [
                '"' + (f.acid || "").replace(/"/g, '""') + '"',
                '"' + extractCarrier(f.acid || "") + '"',
                '"' + (f.orig || "").replace(/"/g, '""') + '"',
                '"' + (f.dest || "").replace(/"/g, '""') + '"',
                '"' + (f.dcenter || "").replace(/"/g, '""') + '"',
                '"' + (f.acenter || "").replace(/"/g, '""') + '"',
                '"' + (f.oetd_utc || f.etd_utc || "").replace(/"/g, '""') + '"',
                '"' + (f.etd_utc || "").replace(/"/g, '""') + '"',
                '"' + (f.ctd_utc || "").replace(/"/g, '""') + '"',
                '"' + (f.oeta_utc || f.eta_utc || "").replace(/"/g, '""') + '"',
                '"' + (f.eta_utc || "").replace(/"/g, '""') + '"',
                '"' + (f.cta_utc || "").replace(/"/g, '""') + '"',
                String(f.program_delay_min || 0),
                '"' + (f.delay_status || f.ctl_type || "GS").replace(/"/g, '""') + '"'
            ];
            lines.push(row.join(","));
        });

        var csv = lines.join("\n");
        var blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
        var url = URL.createObjectURL(blob);

        var a = document.createElement("a");
        a.href = url;
        a.download = "gs_flight_list_" + new Date().toISOString().slice(0,10) + ".csv";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function printGsFlightList() {
        var table = document.getElementById("gs_flight_list_table");
        if (!table) {
            alert("Flight list table not found.");
            return;
        }

        var ctlElement = document.getElementById("gs_flt_list_ctl_element");
        var total = document.getElementById("gs_flt_list_total");
        var maxDelay = document.getElementById("gs_flt_list_max_delay");
        var avgDelay = document.getElementById("gs_flt_list_avg_delay");

        var printWindow = window.open("", "_blank", "width=1100,height=700");
        printWindow.document.write("<html><head><title>GS Flight List</title>");
        printWindow.document.write("<style>");
        printWindow.document.write("body { font-family: Arial, sans-serif; font-size: 9pt; margin: 15px; }");
        printWindow.document.write("h1 { font-size: 14pt; margin-bottom: 5px; }");
        printWindow.document.write("h2 { font-size: 10pt; margin-top: 0; color: #666; }");
        printWindow.document.write(".info { margin-bottom: 10px; }");
        printWindow.document.write(".info span { margin-right: 15px; }");
        printWindow.document.write("table { width: 100%; border-collapse: collapse; font-size: 8pt; }");
        printWindow.document.write("th, td { border: 1px solid #333; padding: 2px 4px; text-align: left; }");
        printWindow.document.write("th { background: #333; color: #fff; }");
        printWindow.document.write("tr:nth-child(even) { background: #f5f5f5; }");
        printWindow.document.write(".footer { margin-top: 10px; font-size: 7pt; color: #666; }");
        printWindow.document.write("</style></head><body>");
        printWindow.document.write("<h1>GS FLIGHT LIST</h1>");
        printWindow.document.write("<h2>Ground Stop - Affected Flights for ATC Coordination</h2>");
        printWindow.document.write("<div class=\"info\">");
        printWindow.document.write("<span><strong>CTL Element:</strong> " + (ctlElement ? ctlElement.textContent : "-") + "</span>");
        printWindow.document.write("<span><strong>Total:</strong> " + (total ? total.textContent : "0") + "</span>");
        printWindow.document.write("<span><strong>Max Delay:</strong> " + (maxDelay ? maxDelay.textContent : "0") + " min</span>");
        printWindow.document.write("<span><strong>Avg Delay:</strong> " + (avgDelay ? avgDelay.textContent : "0") + " min</span>");
        printWindow.document.write("</div>");
        printWindow.document.write(table.outerHTML);
        printWindow.document.write("<div class=\"footer\">Generated: " + formatZuluFromIso(new Date().toISOString()) + " | TFMS/FSM Flight List Reference (Ch 6 & 19)</div>");
        printWindow.document.write("</body></html>");
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    }

    // Wire up flight list modal buttons on DOMContentLoaded
    document.addEventListener("DOMContentLoaded", function() {
        var copyBtn = document.getElementById("gs_flt_list_copy_btn");
        if (copyBtn) {
            copyBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                copyGsFlightListToClipboard();
            });
        }

        var csvBtn = document.getElementById("gs_flt_list_export_csv_btn");
        if (csvBtn) {
            csvBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                exportGsFlightListCsv();
            });
        }

        var printBtn = document.getElementById("gs_flt_list_print_btn");
        if (printBtn) {
            printBtn.addEventListener("click", function(ev) {
                ev.preventDefault();
                printGsFlightList();
            });
        }

        // Sort dropdown handler
        var sortSelect = document.getElementById("gs_flt_list_sort_by");
        if (sortSelect) {
            sortSelect.addEventListener("change", function() {
                var val = this.value;
                var parts = val.split("_");
                if (parts.length >= 2) {
                    var field = parts.slice(0, -1).join("_");
                    var order = parts[parts.length - 1];
                    GS_FLIGHT_LIST_SORT = { field: field, order: order };
                    renderFlightListTable();
                }
            });
        }

        // Group dropdown handler
        var groupSelect = document.getElementById("gs_flt_list_group_by");
        if (groupSelect) {
            groupSelect.addEventListener("change", function() {
                renderFlightListTable();
            });
        }

        // Sortable column headers handler
        document.addEventListener("click", function(ev) {
            var th = ev.target.closest(".gs-sortable");
            if (!th) return;
            
            var sortField = th.getAttribute("data-sort");
            if (!sortField) return;

            // Toggle order if same field, else default to asc
            if (GS_FLIGHT_LIST_SORT.field === sortField) {
                GS_FLIGHT_LIST_SORT.order = GS_FLIGHT_LIST_SORT.order === "asc" ? "desc" : "asc";
            } else {
                GS_FLIGHT_LIST_SORT = { field: sortField, order: "asc" };
            }

            // Update sort dropdown to match
            var sortSelect = document.getElementById("gs_flt_list_sort_by");
            if (sortSelect) {
                var sortVal = sortField + "_" + GS_FLIGHT_LIST_SORT.order;
                var opt = sortSelect.querySelector('option[value="' + sortVal + '"]');
                if (opt) sortSelect.value = sortVal;
            }

            renderFlightListTable();
        });
    });

    // Storage for last simulation results to allow viewing flight list on demand
    var GS_LAST_SIMULATION_DATA = null;

    function handleViewFlightList() {
        // Check if we have simulation data to display
        if (!GS_LAST_SIMULATION_DATA && !GS_FLIGHT_LIST_DATA) {
            // Try to fetch current GS flights from the GS sandbox table
            fetchCurrentGsFlightList();
            return;
        }

        // Use either last simulation data or last flight list data
        var dataToShow = GS_LAST_SIMULATION_DATA || GS_FLIGHT_LIST_DATA;
        
        if (dataToShow) {
            var payload = collectGsWorkflowPayload();
            showGsFlightListModal(dataToShow, payload);
        } else {
            if (window.Swal) {
                window.Swal.fire({
                    icon: "info",
                    title: "No Flight List",
                    text: "Run 'Simulate' first to generate a GS flight list, or 'Send Actual' to view applied GS flights."
                });
            } else {
                alert("Run 'Simulate' first to generate a GS flight list, or 'Send Actual' to view applied GS flights.");
            }
        }
    }

    function fetchCurrentGsFlightList() {
        var statusEl = document.getElementById("gs_adl_status");
        if (statusEl) statusEl.textContent = "Fetching GS flight list...";

        // Fetch current GS flights from the preview endpoint
        var payload = collectGsWorkflowPayload();

        apiPostJson(GS_WORKFLOW_API.preview, payload)
            .then(function(data) {
                var flights = Array.isArray(data) ? data : (data && data.flights ? data.flights : []);
                flights = normalizeSqlsrvRows(flights);

                // Filter to only GS controlled flights
                var gsFlights = flights.filter(function(f) {
                    return f.ctl_type === 'GS' || f.CTL_TYPE === 'GS';
                });

                if (!gsFlights.length) {
                    if (statusEl) statusEl.textContent = "No GS-controlled flights found.";
                    if (window.Swal) {
                        window.Swal.fire({
                            icon: "info",
                            title: "No GS Flights",
                            text: "No flights are currently under GS control. Run 'Simulate' to preview GS impacts."
                        });
                    }
                    return;
                }

                // Format data for the flight list modal
                var flightListData = formatFlightsForModal(gsFlights);
                showGsFlightListModal(flightListData, payload);
                if (statusEl) statusEl.textContent = "Flight list loaded: " + gsFlights.length + " GS flights.";
            })
            .catch(function(err) {
                console.error("Failed to fetch GS flight list", err);
                if (statusEl) statusEl.textContent = "Failed to fetch flight list.";
                if (window.Swal) {
                    window.Swal.fire({ icon: "error", title: "Error", text: "Failed to fetch GS flight list." });
                }
            });
    }

    function formatFlightsForModal(flights) {
        var totalDelay = 0;
        var maxDelay = 0;
        var delayCount = 0;

        var formattedFlights = flights.map(function(f) {
            var delay = parseInt(f.program_delay_min || f.PROGRAM_DELAY_MIN || 0, 10);
            if (delay > 0) {
                totalDelay += delay;
                if (delay > maxDelay) maxDelay = delay;
                delayCount++;
            }

            return {
                acid: f.callsign || f.CALLSIGN || "",
                orig: f.fp_dept_icao || f.FP_DEPT_ICAO || f.dep_icao || "",
                dest: f.fp_dest_icao || f.FP_DEST_ICAO || f.arr_icao || "",
                dcenter: f.dep_center || f.fp_dept_artcc || f.FP_DEPT_ARTCC || "",
                acenter: f.arr_center || f.fp_dest_artcc || f.FP_DEST_ARTCC || "",
                // Original times (before GS control)
                oetd_utc: f.oetd_utc || f.OETD_UTC || f.orig_etd_utc || f.ORIG_ETD_UTC || f.etd_runway_utc || "",
                oeta_utc: f.oeta_utc || f.OETA_UTC || f.orig_eta_utc || f.ORIG_ETA_UTC || f.eta_runway_utc || "",
                // Current times
                etd_utc: f.etd_runway_utc || f.ETD_RUNWAY_UTC || "",
                ctd_utc: f.ctd_utc || f.CTD_UTC || "",
                eta_utc: f.eta_runway_utc || f.ETA_RUNWAY_UTC || "",
                cta_utc: f.cta_utc || f.CTA_UTC || "",
                program_delay_min: delay,
                delay_status: f.delay_status || f.DELAY_STATUS || f.ctl_type || "GS",
                ctl_type: f.ctl_type || f.CTL_TYPE || "GS"
            };
        });

        return {
            flights: formattedFlights,
            total: formattedFlights.length,
            affected: delayCount,
            total_delay: totalDelay,
            max_delay: maxDelay,
            avg_delay: delayCount > 0 ? Math.round(totalDelay / delayCount) : 0,
            generated_utc: new Date().toISOString()
        };
    }

    // Store simulation results when simulate is called
    function storeSimulationData(data) {
        if (!data) return;
        
        var flights = data.flights || [];
        GS_LAST_SIMULATION_DATA = formatFlightsForModal(flights);
        
        // Update with summary data from the response if available
        if (data.summary) {
            GS_LAST_SIMULATION_DATA.total = data.summary.total_flights || flights.length;
            GS_LAST_SIMULATION_DATA.affected = data.summary.affected_flights || GS_LAST_SIMULATION_DATA.affected;
            GS_LAST_SIMULATION_DATA.max_delay = data.summary.max_program_delay_min || GS_LAST_SIMULATION_DATA.max_delay;
            GS_LAST_SIMULATION_DATA.avg_delay = Math.round(data.summary.avg_program_delay_min || GS_LAST_SIMULATION_DATA.avg_delay);
            GS_LAST_SIMULATION_DATA.total_delay = data.summary.sum_program_delay_min || GS_LAST_SIMULATION_DATA.total_delay;
        }
    }

})();
