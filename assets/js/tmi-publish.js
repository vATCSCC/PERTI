/**
 * TMI Publisher - Unified NTML & Advisory Publishing
 * 
 * Combines NTML Quick Entry and Advisory Builder functionality
 * with multi-Discord posting support and staging workflow.
 * 
 * @package PERTI
 * @version 1.0.0
 * @date 2026-01-27
 */

(function() {
    'use strict';

    // ===========================================
    // Configuration from PHP
    // ===========================================
    const CONFIG = window.TMI_PUBLISHER_CONFIG || {
        userCid: null,
        userName: 'Unknown',
        userPrivileged: false,
        userHomeOrg: 'vatcscc',
        discordOrgs: { vatcscc: { name: 'vATCSCC', region: 'US', default: true } },
        stagingRequired: true,
        crossBorderAutoDetect: true
    };

    // ===========================================
    // Constants
    // ===========================================
    const MAX_LINE_LENGTH = 68;
    const DISCORD_MAX_LENGTH = 2000;
    
    const ARTCCS = {
        'ZAB': 'Albuquerque', 'ZAU': 'Chicago', 'ZBW': 'Boston',
        'ZDC': 'Washington', 'ZDV': 'Denver', 'ZFW': 'Fort Worth',
        'ZHU': 'Houston', 'ZID': 'Indianapolis', 'ZJX': 'Jacksonville',
        'ZKC': 'Kansas City', 'ZLA': 'Los Angeles', 'ZLC': 'Salt Lake',
        'ZMA': 'Miami', 'ZME': 'Memphis', 'ZMP': 'Minneapolis',
        'ZNY': 'New York', 'ZOA': 'Oakland', 'ZOB': 'Cleveland',
        'ZSE': 'Seattle', 'ZTL': 'Atlanta',
        // Canadian
        'CZYZ': 'Toronto', 'CZUL': 'Montreal', 'CZVR': 'Vancouver',
        'CZWG': 'Winnipeg', 'CZEG': 'Edmonton', 'CZQX': 'Gander'
    };
    
    // Cross-border facilities
    const CROSS_BORDER_FACILITIES = {
        US_CA: ['ZBW', 'ZMP', 'ZSE', 'ZLC', 'ZOB', 'CZYZ', 'CZWG', 'CZVR', 'CZEG']
    };
    
    const ADVISORY_TYPES = {
        GDP: { sections: ['timing', 'gdp'], headerClass: 'adv-header-gdp' },
        GS: { sections: ['timing', 'gs'], headerClass: 'adv-header-gs' },
        AFP: { sections: ['timing', 'afp'], headerClass: 'adv-header-afp' },
        REROUTE: { sections: ['timing', 'reroute'], headerClass: 'adv-header-reroute' },
        MIT: { sections: ['timing', 'mit'], headerClass: 'adv-header-mit' },
        ATCSCC: { sections: ['timing', 'atcscc'], headerClass: 'adv-header-atcscc' },
        CNX: { sections: ['cnx'], headerClass: 'adv-header-cnx' }
    };

    // ===========================================
    // State
    // ===========================================
    const state = {
        queue: [],
        productionMode: false,
        selectedAdvisoryType: null,
        lastCrossBorderOrgs: []
    };

    // ===========================================
    // Initialization
    // ===========================================
    
    function init() {
        initClock();
        initEventHandlers();
        initAdvisoryTypeSelector();
        loadSavedState();
        updateUI();
    }
    
    function initClock() {
        function updateClock() {
            const now = new Date();
            const utc = now.toISOString().substr(11, 8);
            $('#utc_clock').text(utc);
        }
        updateClock();
        setInterval(updateClock, 1000);
    }
    
    function initEventHandlers() {
        // Production mode toggle
        $('#productionMode').on('change', function() {
            state.productionMode = $(this).is(':checked');
            updateModeIndicator();
            saveState();
            
            if (state.productionMode) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Production Mode Enabled',
                    text: 'Entries will post directly to LIVE Discord channels.',
                    confirmButtonColor: '#dc3545'
                });
            }
        });
        
        // Quick input
        $('#quickInput').on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addNtmlFromInput();
            }
        });
        
        // Templates
        $('.template-btn').on('click', function() {
            const template = $(this).data('template');
            applyTemplate(template);
        });
        
        // Discord org checkboxes - NTML
        $('.discord-org-checkbox').on('change', function() {
            updateTargetOrgsDisplay();
        });
        
        // Discord org checkboxes - Advisory
        $('.discord-org-checkbox-adv').on('change', function() {
            updateTargetOrgsDisplay();
        });
        
        // Queue management
        $('#clearQueue').on('click', clearQueue);
        $('#submitAllBtn').on('click', submitAll);
        
        // Advisory
        $('#adv_add_to_queue').on('click', addAdvisoryToQueue);
        $('#adv_reset').on('click', resetAdvisoryForm);
        $('#adv_copy').on('click', copyAdvisoryToClipboard);
        
        // Tab changes
        $('a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
            if ($(e.target).attr('href') === '#queuePanel') {
                updateQueueDisplay();
            }
        });
        
        // Sync org checkboxes between tabs
        $('.discord-org-checkbox').on('change', function() {
            const code = $(this).val();
            const checked = $(this).is(':checked');
            $(`#adv_org_${code}`).prop('checked', checked);
        });
        
        $('.discord-org-checkbox-adv').on('change', function() {
            const code = $(this).val();
            const checked = $(this).is(':checked');
            $(`#org_${code}`).prop('checked', checked);
        });
    }
    
    function initAdvisoryTypeSelector() {
        $('.advisory-type-card').on('click', function() {
            $('.advisory-type-card').removeClass('selected');
            $(this).addClass('selected');
            
            const type = $(this).data('type');
            state.selectedAdvisoryType = type;
            
            showAdvisorySections(type);
            updateAdvisoryPreview();
        });
        
        // Update preview on any input change
        $(document).on('input change', '[id^="adv_"], [id^="gdp_"], [id^="gs_"], [id^="afp_"], [id^="reroute_"], [id^="mit_"], [id^="atcscc_"], [id^="cnx_"]', function() {
            updateAdvisoryPreview();
        });
    }
    
    // ===========================================
    // Mode Management
    // ===========================================
    
    function updateModeIndicator() {
        const indicator = $('#modeIndicator');
        const warning = $('#prodWarning');
        const submitBtn = $('#submitBtnText');
        const targetDisplay = $('#targetModeDisplay');
        const hint = $('#submitHint');
        
        if (state.productionMode) {
            indicator.removeClass('badge-warning').addClass('badge-danger').text('PRODUCTION');
            warning.show();
            submitBtn.text('Submit to Production');
            targetDisplay.removeClass('badge-warning').addClass('badge-danger').text('PRODUCTION');
            hint.text('⚠️ Entries will post to LIVE channels');
        } else {
            indicator.removeClass('badge-danger').addClass('badge-warning').text('STAGING');
            warning.hide();
            submitBtn.text('Submit to Staging');
            targetDisplay.removeClass('badge-danger').addClass('badge-warning').text('STAGING');
            hint.text('Entries will post to staging channels for review');
        }
    }
    
    // ===========================================
    // NTML Quick Entry
    // ===========================================
    
    function addNtmlFromInput() {
        const input = $('#quickInput').val().trim();
        if (!input) return;
        
        const parsed = parseNtmlInput(input);
        if (!parsed) {
            Swal.fire({
                icon: 'error',
                title: 'Parse Error',
                text: 'Could not parse the NTML entry. Check syntax.',
                toast: true,
                position: 'top-end',
                timer: 3000,
                showConfirmButton: false
            });
            return;
        }
        
        // Get selected orgs
        const selectedOrgs = getSelectedOrgs('ntml');
        
        // Check for cross-border
        if (CONFIG.crossBorderAutoDetect) {
            const crossBorderOrgs = detectCrossBorderOrgs(parsed);
            if (crossBorderOrgs.length > 0) {
                highlightCrossBorderOrgs(crossBorderOrgs);
            }
        }
        
        const entry = {
            id: generateId(),
            type: 'ntml',
            entryType: parsed.type,
            data: parsed,
            rawInput: input,
            orgs: selectedOrgs,
            createdAt: new Date().toISOString()
        };
        
        state.queue.push(entry);
        $('#quickInput').val('');
        
        updateUI();
        saveState();
        
        // Show success toast
        Swal.fire({
            icon: 'success',
            title: 'Added to Queue',
            text: `${parsed.type} entry added`,
            toast: true,
            position: 'top-end',
            timer: 2000,
            showConfirmButton: false
        });
    }
    
    function parseNtmlInput(input) {
        // Simplified parser - handles common patterns
        const upperInput = input.toUpperCase();
        
        // MIT/MINIT pattern: 20MIT ZBW→ZNY JFK LENDY VOLUME 1400-1800
        const mitMatch = upperInput.match(/^(\d+)(MIT|MINIT)\s+(\w+)[→\->]+(\w+)\s+(\w+)\s+(\w*)\s*(\w*)\s*(\d{4})?[-]?(\d{4})?/);
        if (mitMatch) {
            return {
                type: mitMatch[2],
                distance: parseInt(mitMatch[1]),
                fromFacility: mitMatch[3],
                toFacility: mitMatch[4],
                airport: mitMatch[5],
                fix: mitMatch[6] || null,
                reason: mitMatch[7] || 'VOLUME',
                startTime: mitMatch[8] || null,
                endTime: mitMatch[9] || null
            };
        }
        
        // DELAY pattern: DELAY JFK 45min INC WEATHER
        const delayMatch = upperInput.match(/^DELAY\s+(\w+)\s+(\d+)\s*MIN\s*(\w*)\s*(\w*)/);
        if (delayMatch) {
            return {
                type: 'DELAY',
                facility: delayMatch[1],
                minutes: parseInt(delayMatch[2]),
                trend: delayMatch[3] || 'STABLE',
                reason: delayMatch[4] || 'VOLUME'
            };
        }
        
        // CONFIG pattern: CONFIG JFK IMC ARR:22L/22R DEP:31L
        const configMatch = upperInput.match(/^CONFIG\s+(\w+)\s+(\w+)/);
        if (configMatch) {
            return {
                type: 'CONFIG',
                airport: configMatch[1],
                weather: configMatch[2],
                rawConfig: input
            };
        }
        
        // GS pattern: GS KJFK WEATHER
        const gsMatch = upperInput.match(/^GS\s+([A-Z]{3,4})\s*(\w*)/);
        if (gsMatch) {
            return {
                type: 'GS',
                airport: gsMatch[1],
                reason: gsMatch[2] || 'WEATHER'
            };
        }
        
        // STOP pattern
        const stopMatch = upperInput.match(/^STOP\s+(\w+)[→\->]+(\w+)/);
        if (stopMatch) {
            return {
                type: 'STOP',
                fromFacility: stopMatch[1],
                toFacility: stopMatch[2]
            };
        }
        
        // Generic fallback
        return {
            type: 'OTHER',
            raw: input
        };
    }
    
    function applyTemplate(template) {
        const templates = {
            'mit-arr': '20MIT ZBW→ZNY JFK LENDY VOLUME 1400-1800',
            'mit-dep': '15MIT JFK→ZNY GREKI VOLUME 1400-1800',
            'minit': '10MINIT ZDC→ZNY EWR WEATHER 1500-1800',
            'delay': 'DELAY JFK 45min INC WEATHER',
            'config': 'CONFIG JFK IMC ARR:22L/22R DEP:31L AAR:40 ADR:45',
            'gs': 'GS KJFK WEATHER'
        };
        
        if (templates[template]) {
            $('#quickInput').val(templates[template]).focus();
        }
    }
    
    // ===========================================
    // Advisory Builder
    // ===========================================
    
    function showAdvisorySections(type) {
        // Hide all dynamic sections
        $('#adv_dynamic_sections').empty();
        
        const typeConfig = ADVISORY_TYPES[type];
        if (!typeConfig) return;
        
        // Update header color
        $('#adv_section_basic .card-header').removeClass(function(index, className) {
            return (className.match(/adv-header-\w+/g) || []).join(' ');
        }).addClass(typeConfig.headerClass);
        
        // Load type-specific sections
        if (type === 'GDP') {
            loadGDPSection();
        } else if (type === 'GS') {
            loadGSSection();
        } else if (type === 'AFP') {
            loadAFPSection();
        } else if (type === 'REROUTE') {
            loadRerouteSection();
        } else if (type === 'MIT') {
            loadMITSection();
        } else if (type === 'ATCSCC') {
            loadFreeformSection();
        } else if (type === 'CNX') {
            loadCancelSection();
        }
    }
    
    function loadGDPSection() {
        const html = `
            <div class="card shadow-sm mb-3">
                <div class="card-header adv-header-gdp">
                    <span class="tmi-section-title"><i class="fas fa-hourglass-half mr-1"></i> GDP Configuration</span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0">Program Rate (/hr)</label>
                            <input type="number" class="form-control form-control-sm" id="gdp_rate" placeholder="40">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0">Max Delay (min)</label>
                            <input type="number" class="form-control form-control-sm" id="gdp_delay_cap" placeholder="90">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0">Reason</label>
                            <select class="form-control form-control-sm" id="gdp_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="VOLUME">Volume</option>
                                <option value="RUNWAY">Runway</option>
                                <option value="EQUIPMENT">Equipment</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="tmi-label mb-0">Scope (Centers)</label>
                        <input type="text" class="form-control form-control-sm" id="gdp_scope" placeholder="TIER1 or ZBW ZNY ZDC">
                    </div>
                </div>
            </div>`;
        $('#adv_dynamic_sections').html(html);
    }
    
    function loadGSSection() {
        const html = `
            <div class="card shadow-sm mb-3">
                <div class="card-header adv-header-gs">
                    <span class="tmi-section-title"><i class="fas fa-ban mr-1"></i> Ground Stop Configuration</span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0">Reason</label>
                            <select class="form-control form-control-sm" id="gs_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="RUNWAY_CLOSURE">Runway Closure</option>
                                <option value="EQUIPMENT">Equipment</option>
                                <option value="SECURITY">Security</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0">Probability of Extension</label>
                            <select class="form-control form-control-sm" id="gs_probability">
                                <option value="">None</option>
                                <option value="LOW">LOW</option>
                                <option value="MODERATE">MODERATE</option>
                                <option value="HIGH">HIGH</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="tmi-label mb-0">Scope (Centers)</label>
                        <input type="text" class="form-control form-control-sm" id="gs_scope" placeholder="CONUS or ZBW ZNY ZDC">
                    </div>
                </div>
            </div>`;
        $('#adv_dynamic_sections').html(html);
    }
    
    function loadAFPSection() {
        const html = `
            <div class="card shadow-sm mb-3">
                <div class="card-header adv-header-afp">
                    <span class="tmi-section-title"><i class="fas fa-vector-square mr-1"></i> AFP Configuration</span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0">FCA Name</label>
                            <input type="text" class="form-control form-control-sm" id="afp_fca" placeholder="FCA_XXXX">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0">Rate (/hr)</label>
                            <input type="number" class="form-control form-control-sm" id="afp_rate" placeholder="30">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0">Reason</label>
                            <select class="form-control form-control-sm" id="afp_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="VOLUME">Volume</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>`;
        $('#adv_dynamic_sections').html(html);
    }
    
    function loadRerouteSection() {
        const html = `
            <div class="card shadow-sm mb-3">
                <div class="card-header adv-header-reroute">
                    <span class="tmi-section-title"><i class="fas fa-directions mr-1"></i> Reroute Configuration</span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0">Route Name</label>
                            <input type="text" class="form-control form-control-sm" id="reroute_name" placeholder="GOLDDR">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0">Reason</label>
                            <select class="form-control form-control-sm" id="reroute_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="VOLUME">Volume</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="tmi-label mb-0">Route String</label>
                        <textarea class="form-control form-control-sm font-monospace" id="reroute_string" rows="2" placeholder="KJFK..MERIT..J75..MPASS..KATL"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0">Traffic From</label>
                            <input type="text" class="form-control form-control-sm" id="reroute_from" placeholder="KJFK/KLGA">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0">Traffic To</label>
                            <input type="text" class="form-control form-control-sm" id="reroute_to" placeholder="KCLT/KATL">
                        </div>
                    </div>
                </div>
            </div>`;
        $('#adv_dynamic_sections').html(html);
    }
    
    function loadMITSection() {
        const html = `
            <div class="card shadow-sm mb-3">
                <div class="card-header adv-header-mit">
                    <span class="tmi-section-title"><i class="fas fa-ruler-horizontal mr-1"></i> MIT/MINIT Configuration</span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0">Facility</label>
                            <input type="text" class="form-control form-control-sm" id="mit_facility" placeholder="ZNY">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0">Miles/Minutes</label>
                            <input type="number" class="form-control form-control-sm" id="mit_miles" placeholder="20">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0">Type</label>
                            <select class="form-control form-control-sm" id="mit_type">
                                <option value="MIT">MIT (Miles)</option>
                                <option value="MINIT">MINIT (Minutes)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0">At Fix</label>
                            <input type="text" class="form-control form-control-sm" id="mit_fix" placeholder="MERIT">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0">Reason</label>
                            <select class="form-control form-control-sm" id="mit_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="VOLUME">Volume</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>`;
        $('#adv_dynamic_sections').html(html);
    }
    
    function loadFreeformSection() {
        const html = `
            <div class="card shadow-sm mb-3">
                <div class="card-header adv-header-atcscc">
                    <span class="tmi-section-title"><i class="fas fa-file-alt mr-1"></i> Free-Form Advisory</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="tmi-label mb-0">Subject</label>
                        <input type="text" class="form-control form-control-sm" id="atcscc_subject" placeholder="Advisory Subject">
                    </div>
                    <div class="form-group">
                        <label class="tmi-label mb-0">Body</label>
                        <textarea class="form-control form-control-sm" id="atcscc_body" rows="6" placeholder="Advisory body text..."></textarea>
                    </div>
                </div>
            </div>`;
        $('#adv_dynamic_sections').html(html);
    }
    
    function loadCancelSection() {
        const html = `
            <div class="card shadow-sm mb-3">
                <div class="card-header adv-header-cnx">
                    <span class="tmi-section-title"><i class="fas fa-times-circle mr-1"></i> Cancel Advisory</span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0">Original Advisory #</label>
                            <input type="text" class="form-control form-control-sm" id="cnx_ref_number" placeholder="001">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0">Original Type</label>
                            <select class="form-control form-control-sm" id="cnx_ref_type">
                                <option value="GDP">GDP</option>
                                <option value="GS">GS</option>
                                <option value="AFP">AFP</option>
                                <option value="REROUTE">Reroute</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>`;
        $('#adv_dynamic_sections').html(html);
    }
    
    function updateAdvisoryPreview() {
        if (!state.selectedAdvisoryType) {
            $('#adv_preview').text('Select an advisory type to begin...');
            return;
        }
        
        const preview = formatAdvisoryPreview(state.selectedAdvisoryType);
        $('#adv_preview').text(preview);
        
        // Update char count
        const len = preview.length;
        const countEl = $('#preview_char_count');
        countEl.text(`${len} / ${DISCORD_MAX_LENGTH}`);
        countEl.removeClass('warning danger');
        if (len > DISCORD_MAX_LENGTH) countEl.addClass('danger');
        else if (len > DISCORD_MAX_LENGTH * 0.8) countEl.addClass('warning');
    }
    
    function formatAdvisoryPreview(type) {
        const advNum = $('#adv_number').val() || '001';
        const facility = $('#adv_facility').val() || 'DCC';
        const ctlElement = $('#adv_ctl_element').val() || '';
        const startTime = $('#adv_start').val();
        const endTime = $('#adv_end').val();
        const comments = $('#adv_comments').val();
        
        const now = new Date();
        const dateStr = `${(now.getUTCMonth()+1).toString().padStart(2,'0')}/${now.getUTCDate().toString().padStart(2,'0')}/${now.getUTCFullYear()}`;
        const sigTime = `${now.getUTCFullYear().toString().slice(-2)}/${(now.getUTCMonth()+1).toString().padStart(2,'0')}/${now.getUTCDate().toString().padStart(2,'0')} ${now.getUTCHours().toString().padStart(2,'0')}:${now.getUTCMinutes().toString().padStart(2,'0')}`;
        
        const formatZulu = (dateStr) => {
            if (!dateStr) return '--/----Z';
            const d = new Date(dateStr + 'Z');
            return `${d.getUTCDate().toString().padStart(2,'0')}/${d.getUTCHours().toString().padStart(2,'0')}${d.getUTCMinutes().toString().padStart(2,'0')}Z`;
        };
        
        const validRange = `${formatZulu(startTime).replace('Z','')} - ${formatZulu(endTime)}`;
        
        let lines = [];
        
        // Header
        lines.push(`vATCSCC ADVZY ${advNum.padStart(3,'0')}   ${facility}   ${dateStr}`);
        lines.push('');
        
        // Type-specific content
        if (type === 'GDP') {
            const rate = $('#gdp_rate').val() || '40';
            const delay = $('#gdp_delay_cap').val() || '90';
            const reason = $('#gdp_reason').val() || 'WEATHER';
            const scope = $('#gdp_scope').val() || 'TIER1';
            
            lines.push(`${ctlElement || 'KXXX'} GDP`);
            lines.push(`REASON: ${reason}`);
            lines.push(`PROGRAM RATE: ${rate}/HR`);
            lines.push(`MAX DELAY: ${delay} MINUTES`);
            lines.push(`SCOPE: ${scope}`);
            lines.push(`VALID: ${validRange}`);
        } else if (type === 'GS') {
            const reason = $('#gs_reason').val() || 'WEATHER';
            const prob = $('#gs_probability').val();
            const scope = $('#gs_scope').val() || 'CONUS';
            
            lines.push(`${ctlElement || 'KXXX'} GROUND STOP`);
            lines.push(`REASON: ${reason}`);
            lines.push(`SCOPE: ${scope}`);
            if (prob) lines.push(`PROBABILITY OF EXTENSION: ${prob}`);
            lines.push(`VALID: ${validRange}`);
        } else if (type === 'AFP') {
            const fca = $('#afp_fca').val() || 'FCA_XXXX';
            const rate = $('#afp_rate').val() || '30';
            const reason = $('#afp_reason').val() || 'WEATHER';
            
            lines.push(`${fca} AFP`);
            lines.push(`REASON: ${reason}`);
            lines.push(`RATE: ${rate}/HR`);
            lines.push(`VALID: ${validRange}`);
        } else if (type === 'REROUTE') {
            const name = $('#reroute_name').val() || 'ROUTE';
            const routeStr = $('#reroute_string').val() || '';
            const from = $('#reroute_from').val() || '';
            const to = $('#reroute_to').val() || '';
            
            lines.push(`REROUTE ADVISORY: ${name}`);
            if (from && to) lines.push(`TRAFFIC: ${from} TO ${to}`);
            if (routeStr) lines.push(`ROUTE: ${routeStr}`);
            lines.push(`VALID: ${validRange}`);
        } else if (type === 'MIT') {
            const fac = $('#mit_facility').val() || 'ZXX';
            const miles = $('#mit_miles').val() || '20';
            const mitType = $('#mit_type').val() || 'MIT';
            const fix = $('#mit_fix').val() || '';
            const reason = $('#mit_reason').val() || 'VOLUME';
            
            lines.push(`${fac} ${miles}${mitType}${fix ? ' AT ' + fix : ''}`);
            lines.push(`REASON: ${reason}`);
            lines.push(`VALID: ${validRange}`);
        } else if (type === 'ATCSCC') {
            const subject = $('#atcscc_subject').val() || 'ADVISORY';
            const body = $('#atcscc_body').val() || '';
            
            lines.push(subject.toUpperCase());
            lines.push('');
            if (body) lines.push(body);
        } else if (type === 'CNX') {
            const refNum = $('#cnx_ref_number').val() || '001';
            const refType = $('#cnx_ref_type').val() || 'GDP';
            
            lines.push(`CANCEL ${refType} ADVISORY ${refNum.padStart(3,'0')}`);
            lines.push(`${ctlElement || 'KXXX'} ${refType} CANCELLED`);
        }
        
        // Comments
        if (comments) {
            lines.push('');
            lines.push(`COMMENTS: ${comments}`);
        }
        
        // Footer
        lines.push('');
        lines.push(`${sigTime}   ${facility}`);
        
        return lines.join('\n');
    }
    
    function addAdvisoryToQueue() {
        if (!state.selectedAdvisoryType) {
            Swal.fire({
                icon: 'warning',
                title: 'Select Advisory Type',
                text: 'Please select an advisory type first.',
                toast: true,
                position: 'top-end',
                timer: 3000,
                showConfirmButton: false
            });
            return;
        }
        
        const preview = formatAdvisoryPreview(state.selectedAdvisoryType);
        const selectedOrgs = getSelectedOrgs('advisory');
        
        const entry = {
            id: generateId(),
            type: 'advisory',
            advisoryType: state.selectedAdvisoryType,
            preview: preview,
            orgs: selectedOrgs,
            createdAt: new Date().toISOString()
        };
        
        state.queue.push(entry);
        updateUI();
        saveState();
        
        // Switch to queue tab
        $('#queue-tab').tab('show');
        
        Swal.fire({
            icon: 'success',
            title: 'Added to Queue',
            text: `${state.selectedAdvisoryType} advisory added`,
            toast: true,
            position: 'top-end',
            timer: 2000,
            showConfirmButton: false
        });
    }
    
    function resetAdvisoryForm() {
        state.selectedAdvisoryType = null;
        $('.advisory-type-card').removeClass('selected');
        $('#adv_dynamic_sections').empty();
        $('#adv_number').val('');
        $('#adv_ctl_element').val('');
        $('#adv_start').val('');
        $('#adv_end').val('');
        $('#adv_comments').val('');
        $('#adv_preview').text('Select an advisory type to begin...');
    }
    
    function copyAdvisoryToClipboard() {
        const text = $('#adv_preview').text();
        navigator.clipboard.writeText(text).then(() => {
            Swal.fire({
                icon: 'success',
                title: 'Copied!',
                toast: true,
                position: 'top-end',
                timer: 1500,
                showConfirmButton: false
            });
        });
    }
    
    // ===========================================
    // Cross-Border Detection
    // ===========================================
    
    function detectCrossBorderOrgs(parsed) {
        if (!CONFIG.crossBorderAutoDetect) return [];
        
        const facilities = [];
        if (parsed.fromFacility) facilities.push(parsed.fromFacility.toUpperCase());
        if (parsed.toFacility) facilities.push(parsed.toFacility.toUpperCase());
        if (parsed.facility) facilities.push(parsed.facility.toUpperCase());
        
        // Check for Canadian airports (C prefix)
        if (parsed.airport && parsed.airport.match(/^C[A-Z]{3}$/)) {
            return ['vatcan'];
        }
        
        // Check for US-Canada border facilities
        const usInvolved = facilities.some(f => f.match(/^Z[A-Z]{2}$/));
        const caInvolved = facilities.some(f => f.match(/^CZ[A-Z]{2}$/));
        
        if (usInvolved && caInvolved) {
            return ['vatcscc', 'vatcan'].filter(org => CONFIG.discordOrgs[org]);
        }
        
        // Check for border-area US facilities
        const borderFacs = CROSS_BORDER_FACILITIES.US_CA;
        const isBorderFacility = facilities.some(f => borderFacs.includes(f));
        
        if (isBorderFacility && caInvolved) {
            return ['vatcan'];
        }
        
        return [];
    }
    
    function highlightCrossBorderOrgs(orgCodes) {
        orgCodes.forEach(code => {
            const checkbox = $(`#org_${code}`);
            if (checkbox.length && !checkbox.is(':checked')) {
                checkbox.prop('checked', true);
                checkbox.closest('.custom-control').addClass('cross-border-detected');
                setTimeout(() => {
                    checkbox.closest('.custom-control').removeClass('cross-border-detected');
                }, 2000);
            }
        });
        
        state.lastCrossBorderOrgs = orgCodes;
        updateTargetOrgsDisplay();
    }
    
    // ===========================================
    // Queue Management
    // ===========================================
    
    function updateQueueDisplay() {
        const container = $('#entryQueueList');
        const emptyMsg = $('#emptyQueueMsg');
        
        if (state.queue.length === 0) {
            emptyMsg.show();
            container.find('.queue-item').remove();
            return;
        }
        
        emptyMsg.hide();
        container.find('.queue-item').remove();
        
        state.queue.forEach((entry, index) => {
            const item = createQueueItem(entry, index);
            container.append(item);
        });
    }
    
    function createQueueItem(entry, index) {
        const typeLabel = entry.type === 'ntml' ? entry.entryType : entry.advisoryType;
        const typeClass = entry.type === 'ntml' ? 'ntml-entry' : 'advisory-entry';
        const content = entry.type === 'ntml' ? entry.rawInput : entry.preview.substring(0, 100) + '...';
        
        return $(`
            <div class="queue-item ${typeClass}" data-index="${index}">
                <button class="remove-btn" onclick="TMIPublisher.removeFromQueue(${index})">
                    <i class="fas fa-times"></i>
                </button>
                <div class="entry-type">${entry.type.toUpperCase()} - ${typeLabel}</div>
                <div class="entry-content">${escapeHtml(content)}</div>
                <div class="entry-meta">
                    <span class="mr-3"><i class="fab fa-discord"></i> ${entry.orgs.join(', ')}</span>
                    <button class="preview-btn" onclick="TMIPublisher.previewEntry(${index})">
                        <i class="fas fa-eye"></i> Preview
                    </button>
                </div>
            </div>
        `);
    }
    
    function removeFromQueue(index) {
        state.queue.splice(index, 1);
        updateUI();
        saveState();
    }
    
    function clearQueue() {
        if (state.queue.length === 0) return;
        
        Swal.fire({
            title: 'Clear Queue?',
            text: `Remove all ${state.queue.length} entries?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Clear All'
        }).then((result) => {
            if (result.isConfirmed) {
                state.queue = [];
                updateUI();
                saveState();
            }
        });
    }
    
    function previewEntry(index) {
        const entry = state.queue[index];
        if (!entry) return;
        
        let content = '';
        if (entry.type === 'ntml') {
            content = formatNtmlPreview(entry.data);
        } else {
            content = entry.preview;
        }
        
        $('#previewModalContent').text(content);
        $('#previewModal').modal('show');
    }
    
    function formatNtmlPreview(data) {
        // Simple NTML format
        const lines = [];
        const now = new Date();
        const logTime = `${now.getUTCDate().toString().padStart(2,'0')}/${now.getUTCHours().toString().padStart(2,'0')}${now.getUTCMinutes().toString().padStart(2,'0')}`;
        
        if (data.type === 'MIT' || data.type === 'MINIT') {
            lines.push(`${logTime}    ${data.airport || 'XXX'} via ${data.fix || 'FIX'} ${data.distance}${data.type} ${data.reason || 'VOLUME'} EXCL:NONE ${data.startTime || '----'}-${data.endTime || '----'} ${data.toFacility}:${data.fromFacility}`);
        } else if (data.type === 'GS') {
            lines.push(`${logTime}    ${data.airport} GROUND STOP ${data.reason}`);
        } else if (data.type === 'DELAY') {
            lines.push(`${logTime}    ${data.facility} DELAY ${data.minutes}MIN ${data.trend} ${data.reason}`);
        } else {
            lines.push(`${logTime}    ${data.raw || JSON.stringify(data)}`);
        }
        
        return lines.join('\n');
    }
    
    // ===========================================
    // Submit
    // ===========================================
    
    function submitAll() {
        if (state.queue.length === 0) {
            Swal.fire({
                icon: 'info',
                title: 'Queue Empty',
                text: 'Add entries to the queue first.',
                toast: true,
                position: 'top-end',
                timer: 2000,
                showConfirmButton: false
            });
            return;
        }
        
        const mode = state.productionMode ? 'PRODUCTION' : 'STAGING';
        
        Swal.fire({
            title: `Submit to ${mode}?`,
            html: `<p>Post <strong>${state.queue.length}</strong> entries to Discord.</p>
                   <p class="${state.productionMode ? 'text-danger' : 'text-warning'}">
                   Mode: <strong>${mode}</strong></p>`,
            icon: state.productionMode ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonColor: state.productionMode ? '#dc3545' : '#28a745',
            confirmButtonText: `Submit to ${mode}`
        }).then((result) => {
            if (result.isConfirmed) {
                performSubmit();
            }
        });
    }
    
    function performSubmit() {
        const submitBtn = $('#submitAllBtn');
        submitBtn.addClass('submitting').prop('disabled', true);
        submitBtn.find('i').removeClass('fa-paper-plane').addClass('fa-spinner');
        
        // Prepare payload
        const payload = {
            entries: state.queue.map(e => ({
                type: e.type,
                entryType: e.entryType || e.advisoryType,
                data: e.data || null,
                preview: e.preview || null,
                orgs: e.orgs
            })),
            production: state.productionMode,
            userCid: CONFIG.userCid
        };
        
        // Submit to API
        $.ajax({
            url: 'api/mgt/tmi/publish.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function(response) {
                submitBtn.removeClass('submitting').prop('disabled', false);
                submitBtn.find('i').removeClass('fa-spinner').addClass('fa-paper-plane');
                
                if (response.success) {
                    // Clear queue
                    state.queue = [];
                    updateUI();
                    saveState();
                    
                    // Show results
                    showSubmitResults(response);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Submit Failed',
                        text: response.error || 'Unknown error'
                    });
                }
            },
            error: function(xhr) {
                submitBtn.removeClass('submitting').prop('disabled', false);
                submitBtn.find('i').removeClass('fa-spinner').addClass('fa-paper-plane');
                
                Swal.fire({
                    icon: 'error',
                    title: 'Submit Error',
                    text: 'Failed to connect to server'
                });
            }
        });
    }
    
    function showSubmitResults(response) {
        let html = '<div class="text-left">';
        
        if (response.results) {
            response.results.forEach((r, i) => {
                const icon = r.success ? '✓' : '✗';
                const color = r.success ? 'text-success' : 'text-danger';
                html += `<div class="mb-2 ${color}">
                    <strong>${icon}</strong> Entry ${i+1}: ${r.orgs?.join(', ') || 'Unknown'}
                    ${r.error ? `<br><small class="text-muted">${r.error}</small>` : ''}
                </div>`;
            });
        }
        
        html += '</div>';
        
        $('#resultModalContent').html(html);
        $('#resultModal').modal('show');
    }
    
    // ===========================================
    // UI Updates
    // ===========================================
    
    function updateUI() {
        // Queue count
        const count = state.queue.length;
        $('#queueBadge').text(count);
        $('#submitCount').text(count);
        $('#submitAllBtn').prop('disabled', count === 0);
        
        // Update queue display if on queue tab
        if ($('#queuePanel').hasClass('active')) {
            updateQueueDisplay();
        }
        
        // Target orgs display
        updateTargetOrgsDisplay();
        
        // Mode indicator
        updateModeIndicator();
    }
    
    function updateTargetOrgsDisplay() {
        const selectedOrgs = getSelectedOrgs('ntml');
        $('#targetOrgsDisplay').text(selectedOrgs.map(code => {
            return CONFIG.discordOrgs[code]?.name || code;
        }).join(', ') || 'None selected');
    }
    
    function getSelectedOrgs(source) {
        const selector = source === 'advisory' ? '.discord-org-checkbox-adv:checked' : '.discord-org-checkbox:checked';
        return $(selector).map(function() { return $(this).val(); }).get();
    }
    
    // ===========================================
    // State Persistence
    // ===========================================
    
    function saveState() {
        try {
            localStorage.setItem('tmi_publisher_queue', JSON.stringify(state.queue));
            localStorage.setItem('tmi_publisher_production', state.productionMode);
        } catch (e) {
            console.warn('Could not save state:', e);
        }
    }
    
    function loadSavedState() {
        try {
            const savedQueue = localStorage.getItem('tmi_publisher_queue');
            if (savedQueue) {
                state.queue = JSON.parse(savedQueue);
            }
            
            const savedMode = localStorage.getItem('tmi_publisher_production');
            if (savedMode === 'true') {
                state.productionMode = true;
                $('#productionMode').prop('checked', true);
            }
        } catch (e) {
            console.warn('Could not load saved state:', e);
        }
    }
    
    // ===========================================
    // Utilities
    // ===========================================
    
    function generateId() {
        return 'entry_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // ===========================================
    // Public API
    // ===========================================
    
    window.TMIPublisher = {
        removeFromQueue: removeFromQueue,
        previewEntry: previewEntry,
        clearQueue: clearQueue
    };
    
    // ===========================================
    // Initialize
    // ===========================================
    
    $(document).ready(init);
    
})();
