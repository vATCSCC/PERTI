/**
 * Advisory Builder Module
 *
 * FAA TFMS-style advisory generator with Discord integration
 *
 * Key constraints from FAA spec:
 * - Max 68 characters per line (IATA Type B message format)
 * - Discord message limit: 2000 characters (split if needed)
 * - Auto-calculate signature fields (date, advisory number, etc.)
 */
(function() {
    'use strict';

    // ===========================================
    // Constants
    // ===========================================
    const MAX_LINE_LENGTH = 68;
    const DISCORD_MAX_LENGTH = 2000;

    // Advisory type configurations
    const ADVISORY_TYPES = {
        GDP: {
            sections: ['timing', 'gdp'],
            headerClass: 'adv-header-gdp',
            format: formatGDP,
            validate: validateGDP
        },
        GS: {
            sections: ['timing', 'gs'],
            headerClass: 'adv-header-gs',
            format: formatGS,
            validate: validateGS
        },
        AFP: {
            sections: ['timing', 'afp'],
            headerClass: 'adv-header-afp',
            format: formatAFP,
            validate: validateAFP
        },
        CTOP: {
            sections: ['timing', 'ctop'],
            headerClass: 'adv-header-ctop',
            format: formatCTOP,
            validate: validateCTOP
        },
        REROUTE: {
            sections: ['timing', 'reroute'],
            headerClass: 'adv-header-reroute',
            format: formatReroute,
            validate: validateReroute
        },
        ATCSCC: {
            sections: ['timing', 'atcscc'],
            headerClass: 'adv-header-atcscc',
            format: formatATCSCC,
            validate: validateATCSCC
        },
        MIT: {
            sections: ['timing', 'mit'],
            headerClass: 'adv-header-mit',
            format: formatMIT,
            validate: validateMIT
        },
        CNX: {
            sections: ['cnx'],
            headerClass: 'adv-header-cnx',
            format: formatCNX,
            validate: validateCNX
        }
    };

    // ===========================================
    // State
    // ===========================================
    const state = {
        selectedType: null,
        previewText: '',
        isDirty: false,
        routeExpansionPending: false,
        lastExpandedRoute: null
    };

    // ===========================================
    // Route String Expansion (GIS Integration)
    // ===========================================

    /**
     * Expand a route string via the GIS API and return ARTCCs traversed
     * @param {string} routeString - Route string (e.g., "KDFW BNA KMCO")
     * @returns {Promise<{artccs: string[], distance_nm: number, waypoint_count: number}>}
     */
    async function expandRouteString(routeString) {
        if (!routeString || routeString.trim().length < 4) {
            return null;
        }

        try {
            const encoded = encodeURIComponent(routeString.trim());
            const response = await fetch(`api/gis/boundaries?action=expand_route&route=${encoded}`);
            const data = await response.json();

            if (data.success) {
                return {
                    artccs: data.artccs || [],
                    artccs_display: data.artccs_display || '',
                    distance_nm: data.distance_nm || 0,
                    waypoint_count: data.waypoint_count || 0,
                    geojson: data.geojson
                };
            }
            return null;
        } catch (error) {
            console.error('Route expansion error:', error);
            return null;
        }
    }

    /**
     * Debounce helper function
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Handle route string change - expand and populate facilities
     */
    async function handleRouteStringChange() {
        const routeInput = document.getElementById('reroute_string');
        const facilitiesInput = document.getElementById('reroute_facilities');

        if (!routeInput || !facilitiesInput) return;

        const routeString = routeInput.value.trim();

        // Skip if empty or same as last
        if (!routeString || routeString === state.lastExpandedRoute) {
            return;
        }

        // Show loading indicator
        facilitiesInput.placeholder = 'Calculating...';
        state.routeExpansionPending = true;

        try {
            const result = await expandRouteString(routeString);

            if (result && result.artccs && result.artccs.length > 0) {
                // Only update if user hasn't manually changed it or it's empty
                if (!facilitiesInput.value || facilitiesInput.dataset.autoCalculated === 'true') {
                    facilitiesInput.value = result.artccs.join(' ');
                    facilitiesInput.dataset.autoCalculated = 'true';
                    facilitiesInput.title = `Auto-calculated: ${result.artccs_display} (${result.waypoint_count} waypoints, ${result.distance_nm} nm)`;

                    // Show AUTO badge
                    const autoBadge = document.getElementById('facilities_auto_badge');
                    if (autoBadge) {
                        autoBadge.style.display = 'inline';
                        autoBadge.title = `${result.waypoint_count} waypoints, ${result.distance_nm} nm`;
                    }
                }
                state.lastExpandedRoute = routeString;
            }
        } catch (error) {
            console.error('Failed to expand route:', error);
        } finally {
            state.routeExpansionPending = false;
            facilitiesInput.placeholder = 'ZNY ZDC ZTL';
        }

        // Trigger preview update
        updatePreview();
    }

    // Create debounced handler (500ms delay)
    const debouncedRouteChange = debounce(handleRouteStringChange, 500);

    // ===========================================
    // Utility Functions
    // ===========================================

    /**
     * Format date as DD/HHMMZ (FAA Zulu format)
     * Note: datetime-local inputs provide values without timezone info.
     * We treat all input values as UTC by appending 'Z' before parsing.
     */
    function formatZulu(dateStr) {
        if (!dateStr) return '--/----Z';
        // Treat datetime-local value as UTC (append Z if not present)
        const utcDateStr = dateStr.endsWith('Z') ? dateStr : dateStr + 'Z';
        const d = new Date(utcDateStr);
        if (isNaN(d.getTime())) return '--/----Z';

        const day = String(d.getUTCDate()).padStart(2, '0');
        const hour = String(d.getUTCHours()).padStart(2, '0');
        const min = String(d.getUTCMinutes()).padStart(2, '0');
        return `${day}/${hour}${min}Z`;
    }

    /**
     * Format date as DD/HHMM (without Z suffix)
     * Note: Treats datetime-local values as UTC.
     */
    function formatZuluNoSuffix(dateStr) {
        if (!dateStr) return '--/----';
        // Treat datetime-local value as UTC (append Z if not present)
        const utcDateStr = dateStr.endsWith('Z') ? dateStr : dateStr + 'Z';
        const d = new Date(utcDateStr);
        if (isNaN(d.getTime())) return '--/----';

        const day = String(d.getUTCDate()).padStart(2, '0');
        const hour = String(d.getUTCHours()).padStart(2, '0');
        const min = String(d.getUTCMinutes()).padStart(2, '0');
        return `${day}/${hour}${min}`;
    }

    /**
     * Get current UTC date in DD/HHMMZ format
     */
    function getCurrentZulu() {
        return formatZulu(new Date().toISOString());
    }

    /**
     * Get current date for advisory header (MM/DD/YYYY format per TFMS spec)
     */
    function getAdvDateHeader() {
        const d = new Date();
        const day = String(d.getUTCDate()).padStart(2, '0');
        const month = String(d.getUTCMonth() + 1).padStart(2, '0');
        const year = d.getUTCFullYear();
        return `${month}/${day}/${year}`;
    }

    /**
     * Get valid time range for advisory footer (ddhhmm-ddhhmm format per TFMS spec)
     */
    function getValidTimeRange(startStr, endStr) {
        const formatTime = (dateStr) => {
            if (!dateStr) return '------';
            const utcDateStr = dateStr.endsWith('Z') ? dateStr : dateStr + 'Z';
            const d = new Date(utcDateStr);
            if (isNaN(d.getTime())) return '------';
            const day = String(d.getUTCDate()).padStart(2, '0');
            const hour = String(d.getUTCHours()).padStart(2, '0');
            const min = String(d.getUTCMinutes()).padStart(2, '0');
            return `${day}${hour}${min}`;
        };
        return `${formatTime(startStr)}-${formatTime(endStr)}`;
    }

    /**
     * Get ADL time (current Zulu time in HHMMZ format)
     */
    function getAdlTime() {
        const d = new Date();
        const hour = String(d.getUTCHours()).padStart(2, '0');
        const min = String(d.getUTCMinutes()).padStart(2, '0');
        return `${hour}${min}Z`;
    }

    /**
     * Wrap text to max line length (68 chars per IATA Type B)
     */
    function wrapText(text, maxLen = MAX_LINE_LENGTH) {
        if (!text) return '';

        const lines = [];
        const paragraphs = text.split('\n');

        for (const para of paragraphs) {
            if (para.length <= maxLen) {
                lines.push(para);
            } else {
                // Word wrap
                const words = para.split(' ');
                let currentLine = '';

                for (const word of words) {
                    if (currentLine.length + word.length + 1 <= maxLen) {
                        currentLine += (currentLine ? ' ' : '') + word;
                    } else {
                        if (currentLine) lines.push(currentLine);
                        currentLine = word;
                    }
                }
                if (currentLine) lines.push(currentLine);
            }
        }

        return lines.join('\n');
    }

    /**
     * Split message into Discord-safe parts (< 2000 chars each)
     */
    function splitForDiscord(text) {
        if (text.length < DISCORD_MAX_LENGTH) {
            return [text];
        }

        const parts = [];
        const lines = text.split('\n');
        let currentPart = '';
        const partNum = { current: 1, total: 0 };

        // First pass: count parts needed
        let tempPart = '';
        for (const line of lines) {
            if ((tempPart + '\n' + line).length >= DISCORD_MAX_LENGTH - 50) { // Reserve space for header
                partNum.total++;
                tempPart = line;
            } else {
                tempPart += (tempPart ? '\n' : '') + line;
            }
        }
        partNum.total++;

        // Second pass: build parts with headers
        for (const line of lines) {
            const testLength = currentPart.length + line.length + 1;

            if (testLength >= DISCORD_MAX_LENGTH - 50) {
                if (currentPart) {
                    parts.push(`[PART ${partNum.current} OF ${partNum.total}]\n\n${currentPart}`);
                    partNum.current++;
                }
                currentPart = line;
            } else {
                currentPart += (currentPart ? '\n' : '') + line;
            }
        }

        if (currentPart) {
            parts.push(`[PART ${partNum.current} OF ${partNum.total}]\n\n${currentPart}`);
        }

        return parts;
    }

    /**
     * Get value from form element
     */
    function getValue(id) {
        const el = document.getElementById(id);
        if (!el) return '';
        if (el.type === 'select-multiple') {
            return Array.from(el.selectedOptions).map(o => o.value);
        }
        return el.value.trim();
    }

    /**
     * Generate automatic advisory number based on current date
     */
    function generateAdvNumber() {
        const existing = getValue('adv_number');
        if (existing) return existing;

        // Auto-generate: use current UTC hour + random suffix
        const d = new Date();
        const num = String(d.getUTCHours()).padStart(2, '0') + String(Math.floor(Math.random() * 10));
        return num;
    }

    // ===========================================
    // Collect Form Data
    // ===========================================
    function collectFormData() {
        return {
            // Basic
            advNumber: getValue('adv_number') || generateAdvNumber(),
            facility: getValue('adv_facility') || 'DCC',
            ctlElement: getValue('adv_ctl_element').toUpperCase(),
            priority: getValue('adv_priority'),

            // Timing
            startTime: getValue('adv_start'),
            endTime: getValue('adv_end'),
            startZulu: formatZulu(getValue('adv_start')),
            endZulu: formatZulu(getValue('adv_end')),

            // Auto-calculated signature fields
            dateHeader: getAdvDateHeader(),
            currentZulu: getCurrentZulu(),

            // GDP
            gdpRate: getValue('gdp_rate'),
            gdpDelayCap: getValue('gdp_delay_cap'),
            gdpReason: getValue('gdp_reason'),
            gdpScopeCenters: getValue('gdp_scope_centers'),
            gdpScopeTiers: getValue('gdp_scope_tiers'),

            // GS
            gsReason: getValue('gs_reason'),
            gsProbability: getValue('gs_probability'),
            gsScopeCenters: getValue('gs_scope_centers'),
            gsDepAirports: getValue('gs_dep_airports'),

            // AFP
            afpFca: getValue('afp_fca'),
            afpRate: getValue('afp_rate'),
            afpReason: getValue('afp_reason'),
            afpScope: getValue('afp_scope'),

            // CTOP
            ctopName: getValue('ctop_name'),
            ctopReason: getValue('ctop_reason'),
            ctopFcas: getValue('ctop_fcas'),
            ctopCaps: getValue('ctop_caps'),

            // Reroute
            rerouteName: getValue('reroute_name'),
            rerouteArea: getValue('reroute_area'),
            rerouteReason: getValue('reroute_reason'),
            rerouteString: getValue('reroute_string'),
            rerouteFrom: getValue('reroute_from'),
            rerouteTo: getValue('reroute_to'),
            rerouteFacilities: getValue('reroute_facilities'),

            // Free-form
            atcsccSubject: getValue('atcscc_subject'),
            atcsccBody: getValue('atcscc_body'),

            // MIT
            mitFacility: getValue('mit_facility'),
            mitMiles: getValue('mit_miles'),
            mitType: getValue('mit_type'),
            mitFix: getValue('mit_fix'),
            mitReason: getValue('mit_reason'),

            // CNX
            cnxRefNumber: getValue('cnx_ref_number'),
            cnxRefType: getValue('cnx_ref_type'),
            cnxComments: getValue('cnx_comments'),

            // Comments
            comments: getValue('adv_comments')
        };
    }

    // ===========================================
    // Format Functions (FAA TFMS Spec Compliant)
    // ===========================================

    function formatGDP(data) {
        const lines = [];

        // Header line per TFMS spec: ATCSCC ADVZY ### APT/CTR MM/DD/YYYY CDM GROUND DELAY PROGRAM
        const ctlElement = data.ctlElement || 'TBD';
        const artcc = data.gdpScopeCenters ?
            (Array.isArray(data.gdpScopeCenters) ? data.gdpScopeCenters[0] : data.gdpScopeCenters.split(' ')[0]) :
            data.facility;
        lines.push(`${AdvisoryConfig.getPrefix()} ADVZY ${data.advNumber} ${ctlElement}/${artcc} ${data.dateHeader} CDM GROUND DELAY PROGRAM`);
        lines.push('');

        // CTL ELEMENT and ELEMENT TYPE per TFMS spec
        lines.push(`CTL ELEMENT...............: ${ctlElement}`);
        lines.push(`ELEMENT TYPE..............: ARPT`);
        lines.push(`ADL TIME..................: ${getAdlTime()}`);

        // Delay assignment mode (GDP typically uses RBS+)
        lines.push(`DELAY ASSIGNMENT MODE.....: RBS+`);

        // Program scope
        if (data.gdpScopeCenters && data.gdpScopeCenters.length > 0) {
            const scope = Array.isArray(data.gdpScopeCenters) ? data.gdpScopeCenters.join(' ') : data.gdpScopeCenters;
            lines.push(`SCOPE - CENTERS...........: ${scope}`);
        }
        if (data.gdpScopeTiers) {
            lines.push(`SCOPE - TIERS.............: ${data.gdpScopeTiers}`);
        }

        // Program rate
        if (data.gdpRate) {
            lines.push(`PROGRAM RATE..............: ${data.gdpRate}/HR`);
        }

        // Max delay (cap)
        if (data.gdpDelayCap) {
            lines.push(`MAX DELAY.................: ${data.gdpDelayCap} MINS`);
        }

        // Impacting condition
        lines.push(`IMPACTING CONDITION.......: ${data.gdpReason || 'WEATHER'}`);

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Valid time range at footer per TFMS spec (ddhhmm-ddhhmm format)
        lines.push('');
        lines.push(getValidTimeRange(data.startTime, data.endTime));

        return lines.join('\n');
    }

    function formatGS(data) {
        const lines = [];

        // Header line per TFMS spec: ATCSCC ADVZY ### APT/CTR MM/DD/YYYY CDM GROUND STOP
        const ctlElement = data.ctlElement || 'TBD';
        const artcc = data.gsScopeCenters ?
            (Array.isArray(data.gsScopeCenters) ? data.gsScopeCenters[0] : data.gsScopeCenters.split(' ')[0]) :
            data.facility;
        lines.push(`${AdvisoryConfig.getPrefix()} ADVZY ${data.advNumber} ${ctlElement}/${artcc} ${data.dateHeader} CDM GROUND STOP`);
        lines.push('');

        // CTL ELEMENT and ELEMENT TYPE per TFMS spec
        lines.push(`CTL ELEMENT...............: ${ctlElement}`);
        lines.push(`ELEMENT TYPE..............: ARPT`);
        lines.push(`ADL TIME..................: ${getAdlTime()}`);

        // Program scope
        if (data.gsScopeCenters && data.gsScopeCenters.length > 0) {
            const scope = Array.isArray(data.gsScopeCenters) ? data.gsScopeCenters.join(' ') : data.gsScopeCenters;
            lines.push(`SCOPE - CENTERS...........: ${scope}`);
        }

        // Departure airports filter
        if (data.gsDepAirports) {
            lines.push(`DEP ARPTS INCLUDED........: ${data.gsDepAirports.toUpperCase()}`);
        }

        // Impacting condition
        lines.push(`IMPACTING CONDITION.......: ${data.gsReason || 'WEATHER'}`);

        // Probability of extension
        if (data.gsProbability) {
            lines.push(`PROBABILITY OF EXTENSION..: ${data.gsProbability}%`);
        }

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Valid time range at footer per TFMS spec (ddhhmm-ddhhmm format)
        lines.push('');
        lines.push(getValidTimeRange(data.startTime, data.endTime));

        return lines.join('\n');
    }

    function formatAFP(data) {
        const lines = [];

        // Header line per TFMS spec: ATCSCC ADVZY ### FCAxxxx MM/DD/YYYY CDM AIRSPACE FLOW PROGRAM
        const fcaId = data.afpFca || 'FCA001';
        lines.push(`${AdvisoryConfig.getPrefix()} ADVZY ${data.advNumber} ${fcaId} ${data.dateHeader} CDM AIRSPACE FLOW PROGRAM`);
        lines.push('');

        // CTL ELEMENT (FCA) and ELEMENT TYPE per TFMS spec
        lines.push(`CTL ELEMENT...............: ${fcaId}`);
        lines.push(`ELEMENT TYPE..............: FCA`);
        lines.push(`ADL TIME..................: ${getAdlTime()}`);

        // Delay assignment mode
        lines.push(`DELAY ASSIGNMENT MODE.....: RBS+`);

        // Program rate
        if (data.afpRate) {
            lines.push(`PROGRAM RATE..............: ${data.afpRate}/HR`);
        }

        // Scope description (affected airspace)
        if (data.afpScope) {
            lines.push(`SCOPE.....................: ${data.afpScope}`);
        }

        // Impacting condition
        lines.push(`IMPACTING CONDITION.......: ${data.afpReason || 'WEATHER'}`);

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Valid time range at footer per TFMS spec (ddhhmm-ddhhmm format)
        lines.push('');
        lines.push(getValidTimeRange(data.startTime, data.endTime));

        return lines.join('\n');
    }

    function formatCTOP(data) {
        const lines = [];

        // Header line per TFMS spec: ATCSCC ADVZY ### CTPxxx MM/DD/YYYY ACTUAL CTOP
        const ctopName = data.ctopName || 'CTP001';
        lines.push(`${AdvisoryConfig.getPrefix()} ADVZY ${data.advNumber} ${ctopName} ${data.dateHeader} ACTUAL CTOP`);
        lines.push('');

        // CTL ELEMENT and ELEMENT TYPE per TFMS spec
        lines.push(`CTL ELEMENT...............: ${ctopName}`);
        lines.push(`ELEMENT TYPE..............: CTOP`);
        lines.push(`ADL TIME..................: ${getAdlTime()}`);

        // FCAs assigned to this CTOP
        if (data.ctopFcas) {
            lines.push(`ASSIGNED FCAS.............: ${data.ctopFcas.toUpperCase()}`);
        }

        // Caps (capacity values for each FCA)
        if (data.ctopCaps) {
            lines.push(`CAPACITY VALUES...........: ${data.ctopCaps}`);
        }

        // Impacting condition
        lines.push(`IMPACTING CONDITION.......: ${data.ctopReason || 'WEATHER'}`);

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Valid time range at footer per TFMS spec (ddhhmm-ddhhmm format)
        lines.push('');
        lines.push(getValidTimeRange(data.startTime, data.endTime));

        return lines.join('\n');
    }

    function formatReroute(data) {
        const lines = [];

        // Header line per TFMS spec for reroutes
        const routeName = data.rerouteName || 'RTE001';
        lines.push(`${AdvisoryConfig.getPrefix()} ADVZY ${data.advNumber} ${routeName} ${data.dateHeader} PLAYBOOK ROUTE`);
        lines.push('');

        // Route identification
        lines.push(`ROUTE DESIGNATOR..........: ${routeName}`);
        lines.push(`ADL TIME..................: ${getAdlTime()}`);

        // Constrained area (the area causing the reroute)
        if (data.rerouteArea) {
            lines.push(`CONSTRAINED AREA..........: ${data.rerouteArea.toUpperCase()}`);
        }

        // Traffic filter - origin/destination
        if (data.rerouteFrom) {
            lines.push(`TRAFFIC FROM..............: ${data.rerouteFrom.toUpperCase()}`);
        }
        if (data.rerouteTo) {
            lines.push(`TRAFFIC TO................: ${data.rerouteTo.toUpperCase()}`);
        }

        // Impacting condition
        lines.push(`IMPACTING CONDITION.......: ${data.rerouteReason || 'WEATHER'}`);

        // Route string (the actual route to fly)
        if (data.rerouteString) {
            lines.push('');
            lines.push('ROUTE:');
            lines.push(wrapText(data.rerouteString.toUpperCase()));
        }

        // Participating facilities
        if (data.rerouteFacilities) {
            lines.push('');
            lines.push(`PARTICIPATING FACS........: ${data.rerouteFacilities.toUpperCase()}`);
        }

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Valid time range at footer per TFMS spec (ddhhmm-ddhhmm format)
        lines.push('');
        lines.push(getValidTimeRange(data.startTime, data.endTime));

        return lines.join('\n');
    }

    function formatATCSCC(data) {
        const lines = [];

        // Header line per TFMS spec for general messages
        lines.push(`${AdvisoryConfig.getPrefix()} ADVZY ${data.advNumber} ${data.facility} ${data.dateHeader} GENERAL MESSAGE`);
        lines.push('');

        // Subject
        if (data.atcsccSubject) {
            lines.push(`SUBJECT...................: ${data.atcsccSubject.toUpperCase()}`);
            lines.push(`ADL TIME..................: ${getAdlTime()}`);
            lines.push('');
        }

        // Body
        if (data.atcsccBody) {
            lines.push(wrapText(data.atcsccBody.toUpperCase()));
        }

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Valid time range at footer (if times set)
        if (data.startTime || data.endTime) {
            lines.push('');
            lines.push(getValidTimeRange(data.startTime, data.endTime));
        } else {
            lines.push('');
            lines.push('END OF MESSAGE');
        }

        return lines.join('\n');
    }

    function formatMIT(data) {
        const lines = [];

        // Header line per TFMS spec for MIT/MINIT restrictions
        const type = data.mitType || 'MIT';
        const facility = data.mitFacility || 'TBD';
        lines.push(`${AdvisoryConfig.getPrefix()} ADVZY ${data.advNumber} ${facility} ${data.dateHeader} ${type}`);
        lines.push('');

        // Facility and restriction type
        lines.push(`FACILITY..................: ${facility}`);
        lines.push(`ADL TIME..................: ${getAdlTime()}`);

        // MIT/MINIT value with appropriate unit
        const unit = type === 'MIT' ? 'NM' : 'MIN';
        lines.push(`RESTRICTION...............: ${data.mitMiles || '0'} ${unit} ${type}`);

        // Fix (if applicable)
        if (data.mitFix) {
            lines.push(`AT FIX....................: ${data.mitFix.toUpperCase()}`);
        }

        // Impacting condition
        lines.push(`IMPACTING CONDITION.......: ${data.mitReason || 'VOLUME'}`);

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Valid time range at footer per TFMS spec (ddhhmm-ddhhmm format)
        lines.push('');
        lines.push(getValidTimeRange(data.startTime, data.endTime));

        return lines.join('\n');
    }

    function formatCNX(data) {
        const lines = [];

        // Header line per TFMS spec for cancellation
        const refType = data.cnxRefType || 'GDP';
        lines.push(`${AdvisoryConfig.getPrefix()} ADVZY ${data.advNumber} ${data.facility} ${data.dateHeader} ${refType} CANCELLATION`);
        lines.push('');

        // Reference to original advisory
        lines.push(`CANCEL ADVISORY...........: ${refType} ${data.cnxRefNumber || 'XXX'}`);
        lines.push(`ADL TIME..................: ${getAdlTime()}`);
        lines.push(`EFFECTIVE IMMEDIATELY`);

        // Reason for cancellation
        if (data.cnxComments) {
            lines.push('');
            lines.push('REASON:');
            lines.push(wrapText(data.cnxComments));
        }

        // End of message
        lines.push('');
        lines.push('END OF MESSAGE');

        return lines.join('\n');
    }

    // ===========================================
    // Validation Functions
    // ===========================================

    function validateGDP(data) {
        const errors = [];
        if (!data.ctlElement) errors.push('CTL Element (airport) is required');
        if (!data.gdpRate || data.gdpRate < 1) errors.push('Program rate is required');
        if (!data.startTime) errors.push('Start time is required');
        if (!data.endTime) errors.push('End time is required');
        return errors;
    }

    function validateGS(data) {
        const errors = [];
        if (!data.ctlElement) errors.push('CTL Element (airport) is required');
        if (!data.startTime) errors.push('Start time is required');
        return errors;
    }

    function validateAFP(data) {
        const errors = [];
        if (!data.afpFca) errors.push('FCA is required');
        if (!data.startTime) errors.push('Start time is required');
        if (!data.endTime) errors.push('End time is required');
        return errors;
    }

    function validateCTOP(data) {
        const errors = [];
        if (!data.ctopName) errors.push('CTOP name is required');
        if (!data.startTime) errors.push('Start time is required');
        if (!data.endTime) errors.push('End time is required');
        return errors;
    }

    function validateReroute(data) {
        const errors = [];
        if (!data.rerouteString) errors.push('Route string is required');
        if (!data.startTime) errors.push('Start time is required');
        if (!data.endTime) errors.push('End time is required');
        return errors;
    }

    function validateATCSCC(data) {
        const errors = [];
        if (!data.atcsccSubject && !data.atcsccBody) {
            errors.push('Subject or body is required');
        }
        return errors;
    }

    function validateMIT(data) {
        const errors = [];
        if (!data.mitFacility) errors.push('Facility is required');
        if (!data.mitMiles) errors.push('MIT/MINIT value is required');
        if (!data.startTime) errors.push('Start time is required');
        return errors;
    }

    function validateCNX(data) {
        const errors = [];
        if (!data.cnxRefNumber) errors.push('Original advisory number is required');
        return errors;
    }

    // ===========================================
    // UI Functions
    // ===========================================

    function updateFormSections(type) {
        // Hide all dynamic sections
        document.querySelectorAll('.section-card').forEach(el => {
            el.classList.remove('active');
        });

        // Show sections for selected type
        if (type && ADVISORY_TYPES[type]) {
            const config = ADVISORY_TYPES[type];
            config.sections.forEach(section => {
                const el = document.getElementById('section_' + section);
                if (el) {
                    el.classList.add('active');
                }
            });

            // Update basic section header color
            const basicHeader = document.getElementById('section_basic_header');
            if (basicHeader) {
                // Remove all header classes
                basicHeader.className = 'card-header ' + config.headerClass;
            }
        }

        // Update type card selection
        document.querySelectorAll('.advisory-type-card').forEach(card => {
            card.classList.remove('selected');
            if (card.dataset.type === type) {
                card.classList.add('selected');
            }
        });
    }

    function updatePreview() {
        if (!state.selectedType) {
            document.getElementById('adv_preview').textContent = 'Select an advisory type to begin...';
            updateCharCount(0);
            return;
        }

        const config = ADVISORY_TYPES[state.selectedType];
        if (!config) return;

        const data = collectFormData();
        state.previewText = config.format(data);

        document.getElementById('adv_preview').textContent = state.previewText;
        updateCharCount(state.previewText.length);
    }

    function updateCharCount(count) {
        const el = document.getElementById('preview_char_count');
        if (!el) return;

        el.textContent = `${count} / ${DISCORD_MAX_LENGTH}`;
        el.classList.remove('warning', 'danger');

        if (count >= DISCORD_MAX_LENGTH) {
            el.classList.add('danger');
            el.textContent += ' (will split)';
        } else if (count >= DISCORD_MAX_LENGTH * 0.8) {
            el.classList.add('warning');
        }
    }

    function updateEffectiveDisplay() {
        const startZulu = formatZulu(getValue('adv_start'));
        const endZulu = formatZulu(getValue('adv_end'));
        const display = document.getElementById('adv_effective_display');
        if (display) {
            display.textContent = `${startZulu} - ${endZulu}`;
        }
    }

    function updateClock() {
        const now = new Date();

        // UTC Clock
        const utcClock = document.getElementById('adv_utc_clock');
        if (utcClock) {
            const h = String(now.getUTCHours()).padStart(2, '0');
            const m = String(now.getUTCMinutes()).padStart(2, '0');
            const s = String(now.getUTCSeconds()).padStart(2, '0');
            utcClock.textContent = `${h}:${m}:${s}Z`;
        }

        // Date display
        const dateDisplay = document.getElementById('adv_date_display');
        if (dateDisplay) {
            const months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
            dateDisplay.textContent = `${now.getUTCDate()} ${months[now.getUTCMonth()]} ${now.getUTCFullYear()}`;
        }
    }

    // ===========================================
    // Action Functions
    // ===========================================

    function copyToClipboard() {
        if (!state.previewText) {
            showToast('error', 'Nothing to copy');
            return;
        }

        navigator.clipboard.writeText(state.previewText).then(() => {
            showToast('success', 'Copied to clipboard');
        }).catch(err => {
            showToast('error', 'Failed to copy: ' + err.message);
        });
    }

    async function postToDiscord() {
        if (!state.selectedType) {
            showToast('error', 'Please select an advisory type');
            return;
        }

        const config = ADVISORY_TYPES[state.selectedType];
        const data = collectFormData();

        // Validate
        const errors = config.validate(data);
        if (errors.length > 0) {
            showToast('error', 'Validation errors:\n' + errors.join('\n'));
            return;
        }

        // Split if needed
        const parts = splitForDiscord(state.previewText);

        // Confirm
        const confirmMsg = parts.length > 1
            ? `Post ${parts.length} message parts to Discord?`
            : 'Post advisory to Discord?';

        const result = await Swal.fire({
            title: 'Confirm Discord Post',
            html: `<pre style="text-align: left; max-height: 200px; overflow-y: auto; font-size: 0.75rem;">${state.previewText.substring(0, 500)}${state.previewText.length > 500 ? '...' : ''}</pre>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Post to Discord',
            confirmButtonColor: '#7289DA'
        });

        if (!result.isConfirmed) return;

        // Send to API
        try {
            const response = await fetch('api/nod/discord-post.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    advisory_type: state.selectedType,
                    advisory_data: data,
                    formatted_parts: parts,
                    formatted_text: state.previewText
                })
            });

            const result = await response.json();

            if (result.success) {
                showToast('success', `Advisory posted to Discord (${parts.length} part${parts.length > 1 ? 's' : ''})`);
                loadAdvisoryHistory();
            } else {
                throw new Error(result.error || 'Discord post failed');
            }
        } catch (error) {
            showToast('error', error.message);
        }
    }

    async function saveDraft() {
        if (!state.selectedType) {
            showToast('error', 'Please select an advisory type');
            return;
        }

        const data = collectFormData();

        try {
            const response = await fetch('api/nod/advisories.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    adv_type: state.selectedType,
                    adv_number: data.advNumber,
                    subject: data.atcsccSubject || `${state.selectedType} - ${data.ctlElement}`,
                    body_text: state.previewText,
                    valid_start_utc: data.startTime,
                    valid_end_utc: data.endTime,
                    status: 'DRAFT',
                    source: 'ADVISORY_BUILDER'
                })
            });

            const result = await response.json();

            if (result.success) {
                showToast('success', 'Draft saved');
                loadAdvisoryHistory();
            } else {
                throw new Error(result.error || 'Save failed');
            }
        } catch (error) {
            showToast('error', error.message);
        }
    }

    function resetForm() {
        // Reset all form fields
        document.querySelectorAll('#section_basic input, #section_basic select').forEach(el => {
            if (el.id === 'adv_facility') {
                el.value = 'DCC';
            } else if (el.id === 'adv_priority') {
                el.value = '2';
            } else {
                el.value = '';
            }
        });

        document.querySelectorAll('.section-card input, .section-card select, .section-card textarea').forEach(el => {
            if (el.type === 'select-multiple') {
                el.selectedIndex = -1;
            } else if (el.type === 'select-one') {
                el.selectedIndex = 0;
            } else {
                el.value = '';
            }
        });

        document.getElementById('adv_comments').value = '';

        updatePreview();
        updateEffectiveDisplay();
    }

    async function loadAdvisoryHistory() {
        try {
            const response = await fetch('api/nod/advisories.php?limit=10');
            const data = await response.json();

            const tbody = document.getElementById('advisory_history_body');
            if (!tbody) return;

            if (!data.advisories || data.advisories.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" class="text-center text-muted py-3">
                            <i class="fas fa-inbox"></i> No recent advisories
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = data.advisories.map(adv => {
                const statusClass = {
                    'ACTIVE': 'badge-success',
                    'DRAFT': 'badge-secondary',
                    'CANCELLED': 'badge-danger',
                    'EXPIRED': 'badge-warning'
                }[adv.status] || 'badge-light';

                const created = adv.created_at ? new Date(adv.created_at).toLocaleString() : '-';

                return `
                    <tr>
                        <td>${adv.adv_number || '-'}</td>
                        <td><span class="badge badge-info">${adv.adv_type || '-'}</span></td>
                        <td>${adv.subject || '-'}</td>
                        <td><small>${created}</small></td>
                        <td><span class="badge ${statusClass}">${adv.status || '-'}</span></td>
                    </tr>
                `;
            }).join('');
        } catch (error) {
            console.error('Failed to load advisory history:', error);
        }
    }

    async function checkDiscordStatus() {
        try {
            const response = await fetch('api/nod/discord-post.php?action=status');
            const data = await response.json();

            const statusEl = document.getElementById('discord_status');
            if (!statusEl) return;

            if (data.configured) {
                statusEl.innerHTML = '<span class="badge badge-success">Configured</span>';
            } else {
                statusEl.innerHTML = `
                    <span class="badge badge-warning">Not Configured</span>
                    <small class="d-block mt-1 text-muted">${data.message || 'Add DISCORD_WEBHOOK_ADVISORIES to config'}</small>
                `;
            }
        } catch (error) {
            const statusEl = document.getElementById('discord_status');
            if (statusEl) {
                statusEl.innerHTML = '<span class="badge badge-danger">Error checking status</span>';
            }
        }
    }

    function showToast(icon, title) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                toast: true,
                position: 'bottom-right',
                icon: icon,
                title: title,
                timer: 3000,
                showConfirmButton: false
            });
        } else {
            alert(title);
        }
    }

    // ===========================================
    // Initialization
    // ===========================================

    function init() {
        // Type selector cards
        document.querySelectorAll('.advisory-type-card').forEach(card => {
            card.addEventListener('click', () => {
                state.selectedType = card.dataset.type;
                updateFormSections(state.selectedType);
                updatePreview();
            });
        });

        // Form input listeners for live preview
        document.querySelectorAll('input, select, textarea').forEach(el => {
            el.addEventListener('input', () => {
                state.isDirty = true;
                updatePreview();

                // Special handling for route string - auto-calculate facilities
                if (el.id === 'reroute_string') {
                    debouncedRouteChange();
                }
            });
            el.addEventListener('change', () => {
                state.isDirty = true;
                updatePreview();
                if (el.id === 'adv_start' || el.id === 'adv_end') {
                    updateEffectiveDisplay();
                }
            });
        });

        // Clear auto-calculated flag when user manually edits facilities
        const facilitiesInput = document.getElementById('reroute_facilities');
        if (facilitiesInput) {
            facilitiesInput.addEventListener('input', () => {
                facilitiesInput.dataset.autoCalculated = 'false';
                // Hide AUTO badge
                const autoBadge = document.getElementById('facilities_auto_badge');
                if (autoBadge) autoBadge.style.display = 'none';
            });
        }

        // Action buttons
        document.getElementById('btn_copy')?.addEventListener('click', copyToClipboard);
        document.getElementById('btn_post_discord')?.addEventListener('click', postToDiscord);
        document.getElementById('btn_save_draft')?.addEventListener('click', saveDraft);
        document.getElementById('btn_reset')?.addEventListener('click', resetForm);
        document.getElementById('btn_refresh_history')?.addEventListener('click', loadAdvisoryHistory);

        // Initialize clock
        updateClock();
        setInterval(updateClock, 1000);

        // Load initial data
        checkDiscordStatus();
        loadAdvisoryHistory();

        // Set default times (round to next 15 min)
        const now = new Date();
        now.setMinutes(Math.ceil(now.getMinutes() / 15) * 15, 0, 0);
        const startInput = document.getElementById('adv_start');
        if (startInput) {
            startInput.value = now.toISOString().slice(0, 16);
        }

        const endTime = new Date(now.getTime() + 4 * 60 * 60 * 1000); // +4 hours
        const endInput = document.getElementById('adv_end');
        if (endInput) {
            endInput.value = endTime.toISOString().slice(0, 16);
        }

        updateEffectiveDisplay();
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Export for global access
    window.AdvisoryBuilder = {
        init,
        updatePreview,
        postToDiscord,
        splitForDiscord,
        wrapText,
        expandRouteString,
        handleRouteStringChange
    };

})();
