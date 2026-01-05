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
        isDirty: false
    };

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

        // Header line
        lines.push(`vATCSCC ADVZY ${data.advNumber} ${data.facility} ${data.currentZulu} CDM GROUND DELAY PROGRAM`);
        lines.push('');

        // Control element
        lines.push(`CTL: ${data.ctlElement || 'TBD'}`);

        // Effective period
        lines.push(`EFFECTIVE: ${data.startZulu} - ${data.endZulu}`);

        // Program parameters
        if (data.gdpRate) {
            lines.push(`PROGRAM RATE: ${data.gdpRate}/HR`);
        }
        if (data.gdpDelayCap) {
            lines.push(`MAX DELAY: ${data.gdpDelayCap} MIN`);
        }

        // Impacting condition
        lines.push(`IMPACTING CONDITION: ${data.gdpReason || 'WEATHER'}`);

        // Scope
        if (data.gdpScopeCenters && data.gdpScopeCenters.length > 0) {
            lines.push(`SCOPE: ${Array.isArray(data.gdpScopeCenters) ? data.gdpScopeCenters.join(' ') : data.gdpScopeCenters}`);
        }

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Signature
        lines.push('');
        lines.push('END ADVZY');

        return lines.join('\n');
    }

    function formatGS(data) {
        const lines = [];

        // Header line
        lines.push(`vATCSCC ADVZY ${data.advNumber} ${data.facility} ${data.currentZulu} CDM GROUND STOP`);
        lines.push('');

        // Control element
        lines.push(`CTL: ${data.ctlElement || 'TBD'}`);

        // Effective period
        lines.push(`EFFECTIVE: ${data.startZulu} - ${data.endZulu}`);

        // Reason
        lines.push(`REASON: ${data.gsReason || 'WEATHER'}`);

        // Probability of extension
        if (data.gsProbability) {
            lines.push(`PROBABILITY OF EXTENSION: ${data.gsProbability}`);
        }

        // Scope
        if (data.gsScopeCenters && data.gsScopeCenters.length > 0) {
            lines.push(`SCOPE: ${Array.isArray(data.gsScopeCenters) ? data.gsScopeCenters.join(' ') : data.gsScopeCenters}`);
        }

        // Departure airports
        if (data.gsDepAirports) {
            lines.push(`DEP AIRPORTS: ${data.gsDepAirports.toUpperCase()}`);
        }

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Signature
        lines.push('');
        lines.push('END ADVZY');

        return lines.join('\n');
    }

    function formatAFP(data) {
        const lines = [];

        // Header line
        lines.push(`vATCSCC ADVZY ${data.advNumber} ${data.facility} ${data.currentZulu} AIRSPACE FLOW PROGRAM`);
        lines.push('');

        // FCA
        lines.push(`FCA: ${data.afpFca || 'TBD'}`);

        // Effective period
        lines.push(`EFFECTIVE: ${data.startZulu} - ${data.endZulu}`);

        // Rate
        if (data.afpRate) {
            lines.push(`PROGRAM RATE: ${data.afpRate}/HR`);
        }

        // Reason
        lines.push(`IMPACTING CONDITION: ${data.afpReason || 'WEATHER'}`);

        // Scope description
        if (data.afpScope) {
            lines.push(`SCOPE: ${data.afpScope}`);
        }

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Signature
        lines.push('');
        lines.push('END ADVZY');

        return lines.join('\n');
    }

    function formatCTOP(data) {
        const lines = [];

        // Header line
        lines.push(`vATCSCC ADVZY ${data.advNumber} ${data.facility} ${data.currentZulu} CTOP`);
        lines.push('');

        // CTOP Name
        lines.push(`CTOP: ${data.ctopName || 'TBD'}`);

        // Effective period
        lines.push(`EFFECTIVE: ${data.startZulu} - ${data.endZulu}`);

        // Reason
        lines.push(`IMPACTING CONDITION: ${data.ctopReason || 'WEATHER'}`);

        // FCAs and Caps
        if (data.ctopFcas) {
            lines.push(`FCAS: ${data.ctopFcas}`);
        }
        if (data.ctopCaps) {
            lines.push(`CAPS: ${data.ctopCaps}`);
        }

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Signature
        lines.push('');
        lines.push('END ADVZY');

        return lines.join('\n');
    }

    function formatReroute(data) {
        const lines = [];

        // Header line
        lines.push(`vATCSCC ADVZY ${data.advNumber} ${data.facility} ${data.currentZulu} ROUTE ADVISORY`);
        lines.push('');

        // Route name
        if (data.rerouteName) {
            lines.push(`ROUTE: ${data.rerouteName}`);
        }

        // Constrained area
        if (data.rerouteArea) {
            lines.push(`CONSTRAINED AREA: ${data.rerouteArea}`);
        }

        // Effective period
        lines.push(`EFFECTIVE: ${data.startZulu} - ${data.endZulu}`);

        // Reason
        lines.push(`REASON: ${data.rerouteReason || 'WEATHER'}`);

        // Traffic filter
        if (data.rerouteFrom) {
            lines.push(`TRAFFIC FROM: ${data.rerouteFrom.toUpperCase()}`);
        }
        if (data.rerouteTo) {
            lines.push(`TRAFFIC TO: ${data.rerouteTo.toUpperCase()}`);
        }

        // Route string
        if (data.rerouteString) {
            lines.push('');
            lines.push('ROUTE REQUIRED:');
            lines.push(wrapText(data.rerouteString.toUpperCase()));
        }

        // Facilities
        if (data.rerouteFacilities) {
            lines.push(`FACILITIES: ${data.rerouteFacilities.toUpperCase()}`);
        }

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Signature
        lines.push('');
        lines.push('END ADVZY');

        return lines.join('\n');
    }

    function formatATCSCC(data) {
        const lines = [];

        // Header line
        lines.push(`vATCSCC ADVZY ${data.advNumber} ${data.facility} ${data.currentZulu}`);
        lines.push('');

        // Subject
        if (data.atcsccSubject) {
            lines.push(`SUBJECT: ${data.atcsccSubject.toUpperCase()}`);
            lines.push('');
        }

        // Effective period (if set)
        if (data.startTime || data.endTime) {
            lines.push(`EFFECTIVE: ${data.startZulu} - ${data.endZulu}`);
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

        // Signature
        lines.push('');
        lines.push('END ADVZY');

        return lines.join('\n');
    }

    function formatMIT(data) {
        const lines = [];

        // Header line
        lines.push(`vATCSCC ADVZY ${data.advNumber} ${data.facility} ${data.currentZulu} ${data.mitType || 'MIT'}`);
        lines.push('');

        // Facility
        lines.push(`FACILITY: ${data.mitFacility || 'TBD'}`);

        // MIT/MINIT value
        const type = data.mitType || 'MIT';
        const unit = type === 'MIT' ? 'NM' : 'MIN';
        lines.push(`${type}: ${data.mitMiles || '0'} ${unit}`);

        // Fix
        if (data.mitFix) {
            lines.push(`AT FIX: ${data.mitFix.toUpperCase()}`);
        }

        // Effective period
        lines.push(`EFFECTIVE: ${data.startZulu} - ${data.endZulu}`);

        // Reason
        lines.push(`REASON: ${data.mitReason || 'VOLUME'}`);

        // Comments
        if (data.comments) {
            lines.push('');
            lines.push('COMMENTS:');
            lines.push(wrapText(data.comments));
        }

        // Signature
        lines.push('');
        lines.push('END ADVZY');

        return lines.join('\n');
    }

    function formatCNX(data) {
        const lines = [];

        // Header line
        lines.push(`vATCSCC ADVZY ${data.advNumber} ${data.facility} ${data.currentZulu} CANCELLATION`);
        lines.push('');

        // Reference
        lines.push(`CANCEL: ${data.cnxRefType || 'GDP'} ADVISORY ${data.cnxRefNumber || 'XXX'}`);
        lines.push(`EFFECTIVE: ${data.currentZulu}`);

        // Comments
        if (data.cnxComments) {
            lines.push('');
            lines.push('REASON:');
            lines.push(wrapText(data.cnxComments));
        }

        // Signature
        lines.push('');
        lines.push('END ADVZY');

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
            });
            el.addEventListener('change', () => {
                state.isDirty = true;
                updatePreview();
                if (el.id === 'adv_start' || el.id === 'adv_end') {
                    updateEffectiveDisplay();
                }
            });
        });

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
        wrapText
    };

})();
