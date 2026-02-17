/**
 * Initiative Timeline - PERTI Style
 * Beautiful Bootstrap modals with support for all element types
 */

class InitiativeTimeline {
    constructor(config) {
        this.type = config.type;
        this.containerId = config.containerId;
        this.planId = config.planId;
        this.eventStart = config.eventStart ? new Date(config.eventStart) : null;
        this.eventEnd = config.eventEnd ? new Date(config.eventEnd) : null;
        this.hasPerm = config.hasPerm || false;

        this.apiEndpoint = this.type === 'terminal'
            ? 'api/data/plans/term_inits_timeline.php'
            : 'api/data/plans/enroute_inits_timeline.php';

        this.timeRangeHours = 16;
        this.timeOffsetHours = 0; // Offset from center (for scrolling)
        this.sortOrder = 'geographical';
        this.filteredOut = new Set();
        this.data = [];
        this.rowHeight = 32;

        // Level definitions
        this.levels = {
            'CDW': { name: PERTII18n.t('initiative.level.cdw'), category: 'cdw', icon: 'fa-clock' },
            'Possible': { name: PERTII18n.t('initiative.level.possibleTmi'), category: 'tmi', icon: 'fa-question-circle' },
            'Probable': { name: PERTII18n.t('initiative.level.probableTmi'), category: 'tmi', icon: 'fa-exclamation-circle' },
            'Expected': { name: PERTII18n.t('initiative.level.expectedTmi'), category: 'tmi', icon: 'fa-check-circle' },
            'Active': { name: PERTII18n.t('initiative.level.activeTmi'), category: 'tmi', icon: 'fa-broadcast-tower' },
            'Advisory_Terminal': { name: PERTII18n.t('initiative.level.tmiAdvisory'), category: 'tmi', icon: 'fa-info-circle' },
            'Advisory_EnRoute': { name: PERTII18n.t('initiative.level.advisory'), category: 'tmi', icon: 'fa-info-circle' },
            'Constraint_Terminal': { name: PERTII18n.t('initiative.level.terminalConstraint'), category: 'constraint', icon: 'fa-exclamation-triangle' },
            'Constraint_EnRoute': { name: PERTII18n.t('initiative.level.enRouteConstraint'), category: 'constraint', icon: 'fa-exclamation-triangle' },
            'Special_Event': { name: PERTII18n.t('initiative.level.specialEvent'), category: 'event', icon: 'fa-star' },
            'Space_Op': { name: PERTII18n.t('initiative.level.spaceOperation'), category: 'space', icon: 'fa-rocket' },
            'VIP': { name: PERTII18n.t('initiative.level.vipMovement'), category: 'vip', icon: 'fa-user-shield' },
            'Staffing': { name: PERTII18n.t('initiative.level.staffingTrigger'), category: 'staffing', icon: 'fa-users' },
            'Misc': { name: PERTII18n.t('initiative.level.miscellaneous'), category: 'misc', icon: 'fa-ellipsis-h' },
        };

        this.terminalLevels = ['CDW', 'Possible', 'Probable', 'Expected', 'Active', 'Advisory_Terminal', 'Constraint_Terminal', 'VIP', 'Misc'];
        this.enrouteLevels = ['CDW', 'Possible', 'Probable', 'Expected', 'Active', 'Advisory_EnRoute', 'Constraint_EnRoute', 'Special_Event', 'Space_Op', 'VIP', 'Staffing', 'Misc'];

        // PERTI namespace as source of truth, hardcoded fallback
        const _P = (typeof PERTI !== 'undefined') ? PERTI : null;
        this.tmiTypes = (_P && _P.ATFM) ? [..._P.ATFM.TMI_UI_TYPES]
            : ['GS', 'GDP', 'MIT', 'MINIT', 'CFR', 'APREQ', 'Reroute', 'AFP', 'FEA', 'FCA', 'CTOP', 'ICR', 'TBO', 'Metering', 'TBM', 'TBFM', 'Other'];
        this.constraintTypes = (_P && _P.ATFM) ? [..._P.ATFM.CONSTRAINT_TYPES]
            : ['Weather', 'Volume', 'Runway', 'Equipment', 'Construction', 'Staffing', 'Military', 'TFR', 'Airspace', 'Other'];
        this.vipTypes = (_P && _P.ATFM) ? [..._P.ATFM.VIP_TYPES]
            : ['VIP Arrival', 'VIP Departure', 'VIP Overflight', 'TFR'];
        this.spaceTypes = (_P && _P.ATFM) ? [..._P.ATFM.SPACE_TYPES]
            : ['Rocket Launch', 'Reentry', 'Launch Window', 'Hazard Area'];
        this.shifts = ['Day', 'Mid', 'Swing', 'All'];

        this.facilities = this.buildFacilitiesList();
        this.tooltip = null;
        this.modalId = `${this.containerId}-modal`;

        this.init();
    }

    buildFacilitiesList() {
        const _P = (typeof PERTI !== 'undefined' && PERTI.FACILITY) ? PERTI.FACILITY.FACILITY_LISTS : null;
        const artccs = _P ? [..._P.ARTCC_ALL]
            : ['ZAB', 'ZAN', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC', 'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZSU', 'ZTL'];
        const tracons = _P ? [..._P.TRACON]
            : ['A11', 'A80', 'A90', 'C90', 'D01', 'D10', 'D21', 'F11', 'I90', 'L30', 'M03', 'M98', 'N90', 'NCT', 'P31', 'P50', 'P80', 'PCT', 'R90', 'S46', 'S56', 'SCT', 'T75', 'U90', 'Y90'];
        const airports = _P ? [..._P.ATCT]
            : ['KATL', 'KBOS', 'KORD', 'KDFW', 'KDEN', 'KDTW', 'KEWR', 'KFLL', 'KHOU', 'KIAD', 'KIAH', 'KJFK', 'KLAS', 'KLAX', 'KLGA', 'KMCO', 'KMEM', 'KMIA', 'KMSP', 'KPHL', 'KPHX', 'KPIT', 'KSAN', 'KSEA', 'KSFO', 'KSLC', 'KSTL', 'KTPA'];
        const special = ['NAS', 'NAV CANADA'];
        const canadian = _P ? [..._P.FIR_CANADA]
            : ['CZEG', 'CZUL', 'CZWG', 'CZVR', 'CZYZ'];
        return [...special, ...artccs, ...tracons, ...airports, ...canadian].sort();
    }

    /**
     * Parse a datetime string as UTC - returns Date object
     * MySQL returns "2024-01-03 23:59:00" which must be treated as UTC
     */
    parseUTC(datetime) {
        if (!datetime) {return new Date();}

        const dtStr = String(datetime).trim();

        // Parse the datetime components manually to avoid timezone issues
        // Expected formats: "2024-01-03 23:59:00" or "2024-01-03T23:59:00" or "2024-01-03T23:59:00Z"
        const match = dtStr.match(/(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/);
        if (!match) {return new Date();}

        const [, year, month, day, hour, minute, second = '00'] = match;

        // Create date using Date.UTC to ensure UTC interpretation
        return new Date(Date.UTC(
            parseInt(year, 10),
            parseInt(month, 10) - 1, // JS months are 0-indexed
            parseInt(day, 10),
            parseInt(hour, 10),
            parseInt(minute, 10),
            parseInt(second, 10),
        ));
    }

    init() {
        this.render();
        this.createModal();
        this.loadData();
        this.startNowLineUpdater();
    }

    render() {
        const container = document.getElementById(this.containerId);
        if (!container) {return;}

        const activeLevels = this.type === 'terminal' ? this.terminalLevels : this.enrouteLevels;

        container.innerHTML = `
            <div class="dcccp-timeline-wrapper" id="${this.containerId}-wrapper">
                <div class="dcccp-controls">
                    <div class="dcccp-controls-left">
                        <span class="dcccp-control-label">${PERTII18n.t('initiative.sort')}:</span>
                        <label class="dcccp-radio"><input type="radio" name="${this.containerId}-sort" value="chronological"> ${PERTII18n.t('initiative.chronological')}</label>
                        <label class="dcccp-radio"><input type="radio" name="${this.containerId}-sort" value="geographical" checked> ${PERTII18n.t('initiative.geographical')}</label>
                        <label class="dcccp-radio"><input type="radio" name="${this.containerId}-sort" value="alphabetical"> ${PERTII18n.t('initiative.alphabetical')}</label>
                    </div>
                    <div class="dcccp-controls-center">
                        <span class="dcccp-control-label">${PERTII18n.t('initiative.range')}:</span>
                        <label class="dcccp-radio"><input type="radio" name="${this.containerId}-range" value="8"> 8h</label>
                        <label class="dcccp-radio"><input type="radio" name="${this.containerId}-range" value="16" checked> 16h</label>
                        <label class="dcccp-radio"><input type="radio" name="${this.containerId}-range" value="24"> 24h</label>
                        <label class="dcccp-radio"><input type="radio" name="${this.containerId}-range" value="48"> 48h</label>
                        <label class="dcccp-radio"><input type="radio" name="${this.containerId}-range" value="72"> 72h</label>
                    </div>
                    <div class="dcccp-controls-right">
                        <div class="dcccp-filter-box">
                            <div class="dcccp-filter-header">
                                <span class="dcccp-filter-label">${PERTII18n.t('initiative.filteredOut')}:</span>
                                <div class="dcccp-filter-buttons">
                                    <button class="dcccp-filter-clear" title="Clear filters">&times;</button>
                                    <button class="dcccp-filter-toggle">&#9662;</button>
                                </div>
                            </div>
                            <div class="dcccp-filter-tags" id="${this.containerId}-filter-tags">
                                <span class="dcccp-filter-tag-none">${PERTII18n.t('common.none')}</span>
                            </div>
                            <div class="dcccp-filter-dropdown" id="${this.containerId}-filter-dropdown">
                                <div class="dcccp-filter-grid">
                                    ${activeLevels.map(l => `
                                        <label class="dcccp-filter-item">
                                            <input type="checkbox" class="dcccp-filter-cb" data-level="${l}">
                                            <span class="dcccp-filter-badge level-${l}">${this.levels[l].name}</span>
                                        </label>
                                    `).join('')}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Time Navigation -->
                <div class="dcccp-time-nav">
                    <button class="dcccp-nav-btn" id="${this.containerId}-nav-prev" title="Previous period">
                        <i class="fas fa-chevron-left"></i> ${PERTII18n.t('initiative.earlier')}
                    </button>
                    <button class="dcccp-nav-btn" id="${this.containerId}-nav-now" title="Center on current time">
                        <i class="fas fa-crosshairs"></i> ${PERTII18n.t('initiative.now')}
                    </button>
                    <span class="dcccp-nav-label" id="${this.containerId}-nav-label"></span>
                    <button class="dcccp-nav-btn" id="${this.containerId}-nav-next" title="Next period">
                        ${PERTII18n.t('initiative.later')} <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                
                <div class="dcccp-timeline-container" id="${this.containerId}-timeline">
                    <div class="dcccp-facility-column" id="${this.containerId}-facilities"></div>
                    <div class="dcccp-timeline-area" id="${this.containerId}-area">
                        <div class="dcccp-grid-lines" id="${this.containerId}-grid"></div>
                        <div class="dcccp-timeline-rows" id="${this.containerId}-rows"></div>
                        <div class="dcccp-now-line" id="${this.containerId}-now" style="display:none;"></div>
                    </div>
                </div>
                
                <div class="dcccp-time-axis">
                    <div class="dcccp-time-labels" id="${this.containerId}-time-labels"></div>
                </div>
                
                <div class="dcccp-legend">
                    <span class="dcccp-legend-label">${PERTII18n.t('initiative.legend')}</span>
                    ${activeLevels.map(l => `<span class="dcccp-legend-item level-${l}">${this.levels[l].name}</span>`).join('')}
                </div>
            </div>
        `;

        this.bindEvents();
    }

    createModal() {
        const existing = document.getElementById(this.modalId);
        if (existing) {existing.remove();}

        const activeLevels = this.type === 'terminal' ? this.terminalLevels : this.enrouteLevels;
        const facilityOptions = this.facilities.map(f => `<option value="${f}">`).join('');

        const modalHtml = `
        <div class="modal fade init-modal" id="${this.modalId}" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white" id="${this.modalId}-header">
                        <h5 class="modal-title"><i class="fas fa-plus-circle mr-2"></i><span id="${this.modalId}-title">${PERTII18n.t('initiative.addElement')}</span></h5>
                        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="${this.modalId}-id">
                        <datalist id="${this.modalId}-facilities">${facilityOptions}</datalist>
                        
                        <!-- Element Type Selector -->
                        <div class="element-type-card">
                            <label><i class="fas fa-layer-group mr-1"></i> ${PERTII18n.t('initiative.elementType')}</label>
                            <select class="form-control form-control-lg" id="${this.modalId}-level">
                                <optgroup label="${PERTII18n.t('initiative.category.trafficManagement')}">
                                    ${activeLevels.filter(l => this.levels[l].category === 'tmi').map(l =>
        `<option value="${l}">${this.levels[l].name}</option>`).join('')}
                                </optgroup>
                                <optgroup label="${PERTII18n.t('initiative.category.constraints')}">
                                    ${activeLevels.filter(l => this.levels[l].category === 'constraint').map(l =>
        `<option value="${l}">${this.levels[l].name}</option>`).join('')}
                                </optgroup>
                                <optgroup label="${PERTII18n.t('initiative.category.specialOperations')}">
                                    ${activeLevels.filter(l => ['vip', 'space', 'staffing'].includes(this.levels[l].category)).map(l =>
        `<option value="${l}">${this.levels[l].name}</option>`).join('')}
                                </optgroup>
                                <optgroup label="${PERTII18n.t('initiative.category.eventsDecisionWindows')}">
                                    ${activeLevels.filter(l => ['cdw', 'event'].includes(this.levels[l].category)).map(l =>
        `<option value="${l}">${this.levels[l].name}</option>`).join('')}
                                </optgroup>
                                <optgroup label="${PERTII18n.t('initiative.category.other')}">
                                    ${activeLevels.filter(l => this.levels[l].category === 'misc').map(l =>
        `<option value="${l}">${this.levels[l].name}</option>`).join('')}
                                </optgroup>
                            </select>
                        </div>
                        
                        <!-- Dynamic Form Sections -->
                        <div id="${this.modalId}-sections">
                            
                            <!-- TMI Section -->
                            <div class="form-section-card" id="${this.modalId}-sec-tmi">
                                <div class="form-section-header section-tmi">
                                    <i class="fas fa-broadcast-tower"></i> ${PERTII18n.t('initiative.section.tmi')}
                                </div>
                                <div class="form-section-body">
                                    <div class="row compact-row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.facility')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-tmi-facility" list="${this.modalId}-facilities" placeholder="ZDC, N90...">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.area')}</label>
                                            <input type="text" class="form-control" id="${this.modalId}-tmi-area" placeholder="METRO, AREA 6...">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.tmiType')} <span class="required">*</span></label>
                                            <select class="form-control" id="${this.modalId}-tmi-type">
                                                ${this.tmiTypes.map(t => `<option value="${t}">${t}</option>`).join('')}
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row compact-row">
                                        <div class="col-md-4 mb-3" id="${this.modalId}-tmi-other-wrap" style="display:none;">
                                            <label class="form-label">${PERTII18n.t('initiative.label.otherType')}</label>
                                            <input type="text" class="form-control" id="${this.modalId}-tmi-other" placeholder="Specify type">
                                        </div>
                                        <div class="col-md-4 mb-3" id="${this.modalId}-tmi-cause-wrap">
                                            <label class="form-label">${PERTII18n.t('initiative.label.cause')}</label>
                                            <input type="text" class="form-control" id="${this.modalId}-tmi-cause" placeholder="WEATHER, VOLUME...">
                                        </div>
                                        <div class="col-md-4 mb-3" id="${this.modalId}-advzy-wrap" style="display:none;">
                                            <label class="form-label">${PERTII18n.t('initiative.label.advzyNumber')}</label>
                                            <input type="text" class="form-control" id="${this.modalId}-advzy-number" placeholder="e.g., 001, 042">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- VIP Section -->
                            <div class="form-section-card" id="${this.modalId}-sec-vip" style="display:none;">
                                <div class="form-section-header section-vip">
                                    <i class="fas fa-user-shield"></i> ${PERTII18n.t('initiative.section.vip')}
                                </div>
                                <div class="form-section-body">
                                    <div class="row compact-row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.movementType')} <span class="required">*</span></label>
                                            <select class="form-control" id="${this.modalId}-vip-type">
                                                ${this.vipTypes.map(t => `<option value="${t}">${t}</option>`).join('')}
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.callsignId')}</label>
                                            <input type="text" class="form-control" id="${this.modalId}-vip-callsign" placeholder="AF1, SAM 28000...">
                                        </div>
                                    </div>
                                    <div class="row compact-row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.origin')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-vip-origin" list="${this.modalId}-facilities" placeholder="KADW, KJBA...">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.destination')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-vip-dest" list="${this.modalId}-facilities" placeholder="KLAX, KSFO...">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Space Operation Section -->
                            <div class="form-section-card" id="${this.modalId}-sec-space" style="display:none;">
                                <div class="form-section-header section-space">
                                    <i class="fas fa-rocket"></i> ${PERTII18n.t('initiative.section.space')}
                                </div>
                                <div class="form-section-body">
                                    <div class="row compact-row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.operationType')} <span class="required">*</span></label>
                                            <select class="form-control" id="${this.modalId}-space-type">
                                                ${this.spaceTypes.map(t => `<option value="${t}">${t}</option>`).join('')}
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.missionName')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-space-mission" placeholder="SpaceX Starlink 6-71">
                                        </div>
                                    </div>
                                    <div class="row compact-row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.launchSite')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-space-facility" list="${this.modalId}-facilities" placeholder="ZMA, ZJX...">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.affectedAreas')}</label>
                                            <input type="text" class="form-control" id="${this.modalId}-space-areas" placeholder="ATLANTIC routes...">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Staffing Section -->
                            <div class="form-section-card" id="${this.modalId}-sec-staffing" style="display:none;">
                                <div class="form-section-header section-staffing">
                                    <i class="fas fa-users"></i> ${PERTII18n.t('initiative.section.staffing')}
                                </div>
                                <div class="form-section-body">
                                    <div class="row compact-row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.facility')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-staff-facility" list="${this.modalId}-facilities" placeholder="ZDC, ZKC...">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.area')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-staff-area" placeholder="AREA 6 METRO">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.shift')}</label>
                                            <select class="form-control" id="${this.modalId}-staff-shift">
                                                ${this.shifts.map(s => `<option value="${s}">${s}</option>`).join('')}
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">${PERTII18n.t('initiative.label.triggerDescription')}</label>
                                        <input type="text" class="form-control" id="${this.modalId}-staff-trigger" placeholder="OTTO RAMAY MIT, Q221 MIT...">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- CDW Section -->
                            <div class="form-section-card" id="${this.modalId}-sec-cdw" style="display:none;">
                                <div class="form-section-header section-cdw">
                                    <i class="fas fa-clock"></i> ${PERTII18n.t('initiative.section.cdw')}
                                </div>
                                <div class="form-section-body">
                                    <div class="row compact-row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.facility')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-cdw-facility" list="${this.modalId}-facilities" placeholder="ZMA, ZSU...">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.decisionPoint')}</label>
                                            <input type="text" class="form-control" id="${this.modalId}-cdw-decision" placeholder="Route closure decision...">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Special Event Section -->
                            <div class="form-section-card" id="${this.modalId}-sec-event" style="display:none;">
                                <div class="form-section-header section-event">
                                    <i class="fas fa-star"></i> ${PERTII18n.t('initiative.section.event')}
                                </div>
                                <div class="form-section-body">
                                    <div class="row compact-row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.affectedFacility')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-event-facility" list="${this.modalId}-facilities" placeholder="ZDC, NAS...">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.eventName')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-event-name" placeholder="Super Bowl, Inauguration...">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Misc Section -->
                            <div class="form-section-card" id="${this.modalId}-sec-misc" style="display:none;">
                                <div class="form-section-header section-misc">
                                    <i class="fas fa-ellipsis-h"></i> ${PERTII18n.t('initiative.section.misc')}
                                </div>
                                <div class="form-section-body">
                                    <div class="row compact-row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.facility')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-misc-facility" list="${this.modalId}-facilities" placeholder="ZDC, NAS...">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.description')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-misc-desc" placeholder="Brief description">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Constraint Section -->
                            <div class="form-section-card" id="${this.modalId}-sec-constraint" style="display:none;">
                                <div class="form-section-header section-constraint">
                                    <i class="fas fa-exclamation-triangle"></i> ${PERTII18n.t('initiative.section.constraint')}
                                </div>
                                <div class="form-section-body">
                                    <div class="row compact-row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.location')} <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="${this.modalId}-constraint-facility" list="${this.modalId}-facilities" placeholder="ZDC, KATL, METRO...">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.cause')} <span class="required">*</span></label>
                                            <select class="form-control" id="${this.modalId}-constraint-type">
                                                ${this.constraintTypes.map(t => `<option value="${t}">${t}</option>`).join('')}
                                            </select>
                                        </div>
                                        <div class="col-md-4 mb-3" id="${this.modalId}-constraint-other-wrap" style="display:none;">
                                            <label class="form-label">${PERTII18n.t('initiative.label.otherCause')}</label>
                                            <input type="text" class="form-control" id="${this.modalId}-constraint-other" placeholder="Specify cause">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">${PERTII18n.t('initiative.label.impactDescription')} <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="${this.modalId}-constraint-impact" placeholder="RWY 27L/R CLSD, REDUCED ARRIVAL RATES...">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Time Section (Always Visible) -->
                            <div class="form-section-card">
                                <div class="form-section-header section-time">
                                    <i class="fas fa-calendar-alt"></i> ${PERTII18n.t('initiative.section.timePeriod')}
                                </div>
                                <div class="form-section-body">
                                    <div class="row compact-row">
                                        <div class="col-md-5 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.startTime')} <span class="required">*</span></label>
                                            <input type="datetime-local" class="form-control" id="${this.modalId}-start">
                                        </div>
                                        <div class="col-md-5 mb-3">
                                            <label class="form-label">${PERTII18n.t('initiative.label.endTime')} <span class="required">*</span></label>
                                            <input type="datetime-local" class="form-control" id="${this.modalId}-end">
                                        </div>
                                        <div class="col-md-2 mb-3 d-flex align-items-end">
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="${this.modalId}-global">
                                                <label class="custom-control-label" for="${this.modalId}-global">${PERTII18n.t('initiative.label.global')}</label>
                                                <small class="form-text text-muted d-block">${PERTII18n.t('initiative.label.showOnAllPlans')}</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-0">
                                        <label class="form-label">${PERTII18n.t('initiative.label.notes')}</label>
                                        <textarea class="form-control" id="${this.modalId}-notes" rows="2" placeholder="Additional notes..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-delete" id="${this.modalId}-delete" style="display:none;">
                            <i class="fas fa-trash-alt mr-1"></i> ${PERTII18n.t('common.delete')}
                        </button>
                        <div class="ml-auto">
                            <button type="button" class="btn btn-secondary mr-2" data-dismiss="modal">${PERTII18n.t('common.cancel')}</button>
                            <button type="button" class="btn btn-primary" id="${this.modalId}-save">
                                <i class="fas fa-save mr-1"></i> ${PERTII18n.t('common.save')}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        this.bindModalEvents();
    }

    bindModalEvents() {
        // Level change
        document.getElementById(`${this.modalId}-level`).addEventListener('change', () => this.updateFormSections());

        // TMI type change
        document.getElementById(`${this.modalId}-tmi-type`).addEventListener('change', (e) => {
            const isOther = e.target.value === 'Other';
            document.getElementById(`${this.modalId}-tmi-other-wrap`).style.display = isOther ? 'block' : 'none';
            document.getElementById(`${this.modalId}-tmi-cause-wrap`).style.display = isOther ? 'none' : 'block';
        });

        // Constraint type change
        document.getElementById(`${this.modalId}-constraint-type`)?.addEventListener('change', (e) => {
            const isOther = e.target.value === 'Other';
            document.getElementById(`${this.modalId}-constraint-other-wrap`).style.display = isOther ? 'block' : 'none';
        });

        // Save
        document.getElementById(`${this.modalId}-save`).addEventListener('click', () => this.handleSave());

        // Delete
        document.getElementById(`${this.modalId}-delete`).addEventListener('click', () => this.handleDelete());
    }

    updateFormSections() {
        const level = document.getElementById(`${this.modalId}-level`).value;
        const category = this.levels[level]?.category || 'tmi';

        // Hide all
        ['tmi', 'vip', 'space', 'staffing', 'cdw', 'event', 'misc', 'constraint'].forEach(sec => {
            const el = document.getElementById(`${this.modalId}-sec-${sec}`);
            if (el) {el.style.display = 'none';}
        });

        // Show relevant
        const secMap = { tmi: 'tmi', vip: 'vip', space: 'space', staffing: 'staffing', cdw: 'cdw', event: 'event', misc: 'misc', constraint: 'constraint' };
        const secId = secMap[category] || 'tmi';
        const sec = document.getElementById(`${this.modalId}-sec-${secId}`);
        if (sec) {sec.style.display = 'block';}

        // Show ADVZY field for Advisory levels
        const advzyWrap = document.getElementById(`${this.modalId}-advzy-wrap`);
        if (advzyWrap) {
            const isAdvisory = level === 'Advisory_Terminal' || level === 'Advisory_EnRoute';
            advzyWrap.style.display = isAdvisory ? 'block' : 'none';
        }

        // Update header color
        const header = document.getElementById(`${this.modalId}-header`);
        header.className = 'modal-header text-white';
        const colorMap = {
            tmi: 'bg-primary', vip: 'bg-warning', space: 'bg-success',
            staffing: 'bg-danger', cdw: 'bg-warning', event: 'bg-info', misc: 'bg-secondary',
            constraint: 'bg-danger',
        };
        header.classList.add(colorMap[category] || 'bg-primary');

        // Update default end time based on category (only for new items)
        const itemId = document.getElementById(`${this.modalId}-id`).value;
        if (!itemId) {
            const startInput = document.getElementById(`${this.modalId}-start`);
            const endInput = document.getElementById(`${this.modalId}-end`);
            if (startInput.value) {
                const start = new Date(startInput.value);
                let durationHours = 4; // Default 4 hours

                // VIP movements: 1 hour default
                if (category === 'vip') {durationHours = 1;}
                // CDW: 30 minutes
                else if (category === 'cdw') {durationHours = 0.5;}
                // Space ops: 2 hours
                else if (category === 'space') {durationHours = 2;}
                // Constraints: 8 hours default
                else if (category === 'constraint') {durationHours = 8;}

                const end = new Date(start.getTime() + durationHours * 3600000);
                endInput.value = this.toInputFmt(end.toISOString());
            }
        }
    }

    bindEvents() {
        const wrapper = document.getElementById(`${this.containerId}-wrapper`);
        if (!wrapper) {return;}

        // Sort
        wrapper.querySelectorAll(`input[name="${this.containerId}-sort"]`).forEach(r => {
            r.addEventListener('change', (e) => { this.sortOrder = e.target.value; this.renderTimeline(); });
        });

        // Range
        wrapper.querySelectorAll(`input[name="${this.containerId}-range"]`).forEach(r => {
            r.addEventListener('change', (e) => { this.timeRangeHours = parseInt(e.target.value); this.renderTimeline(); });
        });

        // Time navigation
        document.getElementById(`${this.containerId}-nav-prev`)?.addEventListener('click', () => {
            this.timeOffsetHours -= this.timeRangeHours / 2;
            this.renderTimeline();
        });

        document.getElementById(`${this.containerId}-nav-next`)?.addEventListener('click', () => {
            this.timeOffsetHours += this.timeRangeHours / 2;
            this.renderTimeline();
        });

        document.getElementById(`${this.containerId}-nav-now`)?.addEventListener('click', () => {
            this.timeOffsetHours = 0;
            this.renderTimeline();
        });

        // Filter toggle
        const toggle = wrapper.querySelector('.dcccp-filter-toggle');
        const dropdown = document.getElementById(`${this.containerId}-filter-dropdown`);
        if (toggle && dropdown) {
            toggle.addEventListener('click', () => dropdown.classList.toggle('show'));
        }

        // Filter clear
        const clear = wrapper.querySelector('.dcccp-filter-clear');
        if (clear) {
            clear.addEventListener('click', () => {
                this.filteredOut.clear();
                wrapper.querySelectorAll('.dcccp-filter-cb').forEach(cb => cb.checked = false);
                this.updateFilterTags();
                this.renderTimeline();
            });
        }

        // Filter checkboxes
        wrapper.querySelectorAll('.dcccp-filter-cb').forEach(cb => {
            cb.addEventListener('change', (e) => {
                const level = e.target.dataset.level;
                if (e.target.checked) {this.filteredOut.add(level);}
                else {this.filteredOut.delete(level);}
                this.updateFilterTags();
                this.renderTimeline();
            });
        });

        // Close dropdown on outside click
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.dcccp-filter-box')) {dropdown?.classList.remove('show');}
        });
    }

    updateFilterTags() {
        const container = document.getElementById(`${this.containerId}-filter-tags`);
        if (!container) {return;}

        if (this.filteredOut.size === 0) {
            container.innerHTML = `<span class="dcccp-filter-tag-none">${PERTII18n.t('common.none')}</span>`;
        } else {
            container.innerHTML = Array.from(this.filteredOut)
                .map(l => `<span class="dcccp-filter-tag">${this.levels[l].name}</span>`).join('');
        }
    }

    async loadData() {
        try {
            const response = await fetch(`${this.apiEndpoint}?p_id=${this.planId}`);
            const result = await response.json();
            this.data = result.success ? (result.data || []) : [];
            this.renderTimeline();
        } catch (e) {
            console.error('Error loading timeline:', e);
            this.data = [];
            this.renderTimeline();
        }
    }

    getTimelineBounds() {
        const now = new Date();
        let centerTime;

        if (this.eventStart && this.eventEnd) {
            // Calculate event duration in days
            const durationMs = this.eventEnd.getTime() - this.eventStart.getTime();
            const durationDays = durationMs / (1000 * 60 * 60 * 24);

            // For long-duration plans (> 7 days), center on NOW if within event range
            // Otherwise center on the event midpoint
            if (durationDays > 7) {
                // If now is within event range, center on now
                if (now >= this.eventStart && now <= this.eventEnd) {
                    centerTime = now;
                } else if (now < this.eventStart) {
                    // Event hasn't started, center on start
                    centerTime = new Date(this.eventStart.getTime() + (this.timeRangeHours / 2) * 3600000);
                } else {
                    // Event has ended, center on end
                    centerTime = new Date(this.eventEnd.getTime() - (this.timeRangeHours / 2) * 3600000);
                }
            } else {
                // Short event: center on midpoint
                centerTime = new Date((this.eventStart.getTime() + this.eventEnd.getTime()) / 2);
            }
        } else {
            centerTime = now;
        }

        // Apply time offset for scrolling
        centerTime = new Date(centerTime.getTime() + this.timeOffsetHours * 3600000);

        const startTime = new Date(centerTime.getTime() - (this.timeRangeHours / 2) * 3600000);
        const endTime = new Date(centerTime.getTime() + (this.timeRangeHours / 2) * 3600000);

        return { startTime, endTime, centerTime };
    }

    formatTimeLabel(d) {
        return `${String(d.getUTCDate()).padStart(2,'0')}/${String(d.getUTCHours()).padStart(2,'0')}${String(d.getUTCMinutes()).padStart(2,'0')}`;
    }

    formatTooltipTime(s, e) {
        const fmt = d => {
            const dt = this.parseUTC(d);
            return `${String(dt.getUTCMonth()+1).padStart(2,'0')}-${String(dt.getUTCDate()).padStart(2,'0')}-${dt.getUTCFullYear()} ${String(dt.getUTCHours()).padStart(2,'0')}${String(dt.getUTCMinutes()).padStart(2,'0')}Z`;
        };
        return `${fmt(s)} to ${fmt(e)}`;
    }

    renderTimeline() {
        const { startTime, endTime } = this.getTimelineBounds();
        const totalMs = endTime.getTime() - startTime.getTime();

        // Update nav label
        const navLabel = document.getElementById(`${this.containerId}-nav-label`);
        if (navLabel) {
            const fmt = d => `${String(d.getUTCMonth()+1).padStart(2,'0')}/${String(d.getUTCDate()).padStart(2,'0')} ${String(d.getUTCHours()).padStart(2,'0')}${String(d.getUTCMinutes()).padStart(2,'0')}Z`;
            navLabel.textContent = `${fmt(startTime)} — ${fmt(endTime)}`;
        }

        const data = this.data.filter(i => !this.filteredOut.has(i.level));

        const groups = {};
        data.forEach(i => {
            const f = i.facility || PERTII18n.t('common.unknown');
            if (!groups[f]) {groups[f] = [];}
            groups[f].push(i);
        });

        const facilities = Object.keys(groups);
        if (this.sortOrder === 'chronological') {
            facilities.sort((a, b) => Math.min(...groups[a].map(i => this.parseUTC(i.start_datetime).getTime())) - Math.min(...groups[b].map(i => this.parseUTC(i.start_datetime).getTime())));
        } else if (this.sortOrder === 'alphabetical') {
            facilities.sort();
        } else {
            facilities.sort((a, b) => this.geoOrder(a) - this.geoOrder(b));
        }

        const facCol = document.getElementById(`${this.containerId}-facilities`);
        const rowsEl = document.getElementById(`${this.containerId}-rows`);
        if (!facCol || !rowsEl) {return;}

        if (!facilities.length) {
            facCol.innerHTML = '';
            rowsEl.innerHTML = `<div class="dcccp-no-data">${PERTII18n.t('initiative.noData')}</div>`;
            this.renderTimeAxis(startTime, endTime);
            this.updateNowLine(startTime, totalMs);
            return;
        }

        const heights = {};
        const laneData = {};
        facilities.forEach(f => {
            const result = this.assignLanes(groups[f]);
            laneData[f] = result.assignments;
            // Height: 26px per lane (20px item + 6px gap) + 8px padding
            heights[f] = Math.max(this.rowHeight, result.laneCount * 26 + 8);
        });

        facCol.innerHTML = facilities.map(f => `<div class="dcccp-facility-label" style="height:${heights[f]}px">${f}</div>`).join('');

        rowsEl.innerHTML = facilities.map(f => {
            const items = groups[f].map(i => this.renderItem(i, startTime, totalMs, laneData[f][i.id] || 0)).join('');
            return `<div class="dcccp-timeline-row" style="height:${heights[f]}px">${items}</div>`;
        }).join('');

        rowsEl.querySelectorAll('.dcccp-item').forEach(el => {
            el.addEventListener('mouseenter', e => this.showTooltip(e, el.dataset.id));
            el.addEventListener('mouseleave', () => this.hideTooltip());
            el.addEventListener('click', () => { if (this.hasPerm) { this.hideTooltip(); this.openEditModal(el.dataset.id); }});
        });

        this.renderGridLines(startTime, endTime);
        this.renderTimeAxis(startTime, endTime);
        this.updateNowLine(startTime, totalMs);
    }

    /**
     * Assign items to lanes to prevent overlapping
     * Returns an object mapping item.id to lane number (0-based)
     */
    assignLanes(items) {
        if (!items.length) {return {};}

        // Sort by start time
        const sorted = [...items].sort((a, b) =>
            this.parseUTC(a.start_datetime).getTime() - this.parseUTC(b.start_datetime).getTime(),
        );

        // Track end times for each lane
        const laneEndTimes = [];
        const assignments = {};

        for (const item of sorted) {
            const itemStart = this.parseUTC(item.start_datetime).getTime();
            const itemEnd = this.parseUTC(item.end_datetime).getTime();

            // Find first available lane
            let assignedLane = -1;
            for (let lane = 0; lane < laneEndTimes.length; lane++) {
                if (laneEndTimes[lane] <= itemStart) {
                    assignedLane = lane;
                    break;
                }
            }

            // If no lane available, create new one
            if (assignedLane === -1) {
                assignedLane = laneEndTimes.length;
                laneEndTimes.push(0);
            }

            // Assign item to lane and update end time
            laneEndTimes[assignedLane] = itemEnd;
            assignments[item.id] = assignedLane;
        }

        return { assignments, laneCount: laneEndTimes.length };
    }

    renderItem(item, startTime, totalMs, lane = 0) {
        const s = this.parseUTC(item.start_datetime);
        const e = this.parseUTC(item.end_datetime);
        const left = Math.max(0, ((s.getTime() - startTime.getTime()) / totalMs) * 100);
        const width = Math.max(0.5, Math.min(100 - left, ((e.getTime() - s.getTime()) / totalMs) * 100));
        if (left >= 100 || left + width <= 0) {return '';}

        const label = this.buildLabel(item);

        // CDW items are small markers centered, others stack in lanes
        let top;
        if (item.level === 'CDW') {
            top = '50%';
        } else {
            // Each lane is 26px tall (20px item + 6px gap), starting at 4px
            top = `${4 + lane * 26}px`;
        }

        return `<div class="dcccp-item level-${item.level}" data-id="${item.id}" style="left:${left}%;width:${width}%;top:${top};" title="${label}"><span class="dcccp-item-text">${label}</span></div>`;
    }

    buildLabel(item) {
        const cat = this.levels[item.level]?.category || 'tmi';
        switch (cat) {
            case 'tmi': {
                let l = item.level === 'Active' ? '' : (this.levels[item.level]?.name.split(' ')[0] || '');
                l = `${l} ${item.tmi_type || ''}`.trim();
                if (item.cause) {l += ` - ${item.cause}`;}
                return l;
            }
            case 'vip': return `${item.tmi_type || 'VIP'}: ${item.facility}→${item.area}`;
            case 'space': return `${item.tmi_type}: ${item.cause || ''}`;
            case 'staffing': return `${PERTII18n.t('initiative.label.staffingPrefix')}: ${item.area || item.facility}`;
            case 'event': return item.cause || PERTII18n.t('initiative.label.specialEvent');
            case 'cdw': return item.cause || PERTII18n.t('initiative.label.cdw');
            case 'misc': return item.cause || PERTII18n.t('initiative.label.misc');
            default: return item.tmi_type || item.cause || '';
        }
    }

    renderGridLines(startTime, endTime) {
        const el = document.getElementById(`${this.containerId}-grid`);
        if (!el) {return;}

        const totalMs = endTime.getTime() - startTime.getTime();
        let interval = 3600000;
        if (this.timeRangeHours > 24) {interval = 7200000;}
        if (this.timeRangeHours > 48) {interval = 14400000;}

        const first = new Date(startTime);
        first.setUTCMinutes(0, 0, 0);
        first.setUTCHours(first.getUTCHours() + 1);

        let html = '';
        let cur = first;
        while (cur < endTime) {
            const pct = ((cur.getTime() - startTime.getTime()) / totalMs) * 100;
            if (pct > 0 && pct < 100) {
                // Check if this is 00Z (midnight UTC)
                const isMidnight = cur.getUTCHours() === 0;
                const lineClass = isMidnight ? 'dcccp-grid-line dcccp-grid-line-midnight' : 'dcccp-grid-line';
                html += `<div class="${lineClass}" style="left:${pct}%"></div>`;
            }
            cur = new Date(cur.getTime() + interval);
        }
        el.innerHTML = html;
    }

    renderTimeAxis(startTime, endTime) {
        const el = document.getElementById(`${this.containerId}-time-labels`);
        if (!el) {return;}

        const totalMs = endTime.getTime() - startTime.getTime();
        let interval = 3600000;
        if (this.timeRangeHours > 24) {interval = 7200000;}
        if (this.timeRangeHours > 48) {interval = 14400000;}

        const first = new Date(startTime);
        first.setUTCMinutes(0, 0, 0);
        first.setUTCHours(first.getUTCHours() + 1);

        let html = '';
        let cur = first;
        while (cur < endTime) {
            const pct = ((cur.getTime() - startTime.getTime()) / totalMs) * 100;
            if (pct > 2 && pct < 98) {
                // Check if this is 00Z (midnight UTC)
                const isMidnight = cur.getUTCHours() === 0;
                const labelClass = isMidnight ? 'dcccp-time-label dcccp-time-label-midnight' : 'dcccp-time-label';
                html += `<span class="${labelClass}" style="left:${pct}%">${this.formatTimeLabel(cur)}</span>`;
            }
            cur = new Date(cur.getTime() + interval);
        }
        el.innerHTML = html;
    }

    updateNowLine(startTime, totalMs) {
        const el = document.getElementById(`${this.containerId}-now`);
        if (!el) {return;}
        const pct = ((Date.now() - startTime.getTime()) / totalMs) * 100;
        el.style.display = (pct >= 0 && pct <= 100) ? 'block' : 'none';
        el.style.left = `${pct}%`;
    }

    startNowLineUpdater() {
        setInterval(() => {
            const { startTime, endTime } = this.getTimelineBounds();
            this.updateNowLine(startTime, endTime.getTime() - startTime.getTime());
        }, 60000);
    }

    geoOrder(f) {
        const order = { 'NAS': 0, 'ZBW': 10, 'N90': 11, 'ZNY': 12, 'ZDC': 14, 'ZJX': 20, 'ZTL': 21, 'ZMA': 23, 'ZAU': 30, 'ZID': 32, 'ZMP': 33, 'ZKC': 34, 'ZME': 35, 'ZFW': 40, 'ZHU': 42, 'ZDV': 50, 'ZLC': 52, 'ZAB': 53, 'ZLA': 60, 'ZOA': 62, 'ZSE': 64, 'ZAN': 70, 'ZSU': 71 };
        return order[f] ?? 100;
    }

    showTooltip(event, id) {
        const item = this.data.find(d => d.id == id);
        if (!item) {return;}
        this.hideTooltip();

        const label = this.buildLabel(item);
        const cat = this.levels[item.level]?.category || 'tmi';

        // Build location rows based on category
        let locationRows = '';
        if (cat === 'vip') {
            // VIP: facility = origin, area = destination
            locationRows = `
                <div class="dcccp-tooltip-row"><span class="dcccp-tooltip-label">${PERTII18n.t('initiative.label.origin')}</span><span class="dcccp-tooltip-value">${item.facility}</span></div>
                ${item.area ? `<div class="dcccp-tooltip-row"><span class="dcccp-tooltip-label">${PERTII18n.t('initiative.label.destination')}</span><span class="dcccp-tooltip-value">${item.area}</span></div>` : ''}
                ${item.tmi_type_other ? `<div class="dcccp-tooltip-row"><span class="dcccp-tooltip-label">${PERTII18n.t('initiative.label.callsign')}</span><span class="dcccp-tooltip-value">${item.tmi_type_other}</span></div>` : ''}
            `;
        } else if (cat === 'space') {
            locationRows = `
                <div class="dcccp-tooltip-row"><span class="dcccp-tooltip-label">${PERTII18n.t('initiative.tooltip.siteArtcc')}</span><span class="dcccp-tooltip-value">${item.facility}</span></div>
                ${item.area ? `<div class="dcccp-tooltip-row"><span class="dcccp-tooltip-label">${PERTII18n.t('initiative.tooltip.affected')}</span><span class="dcccp-tooltip-value">${item.area}</span></div>` : ''}
                ${item.cause ? `<div class="dcccp-tooltip-row"><span class="dcccp-tooltip-label">${PERTII18n.t('initiative.tooltip.mission')}</span><span class="dcccp-tooltip-value">${item.cause}</span></div>` : ''}
            `;
        } else {
            // Default: Facility/Area
            locationRows = `
                <div class="dcccp-tooltip-row"><span class="dcccp-tooltip-label">${PERTII18n.t('initiative.label.facility')}</span><span class="dcccp-tooltip-value">${item.facility}</span></div>
                ${item.area ? `<div class="dcccp-tooltip-row"><span class="dcccp-tooltip-label">${PERTII18n.t('initiative.label.area')}</span><span class="dcccp-tooltip-value">${item.area}</span></div>` : ''}
            `;
        }

        const tip = document.createElement('div');
        tip.className = 'dcccp-tooltip';
        tip.innerHTML = `
            <div class="dcccp-tooltip-header">
                <span class="dcccp-tooltip-title">${label}</span>
                ${this.hasPerm ? `<button class="dcccp-tooltip-edit-btn" data-id="${item.id}">${PERTII18n.t('common.edit')}</button>` : ''}
            </div>
            <div class="dcccp-tooltip-body">
                ${locationRows}
                <div class="dcccp-tooltip-row"><span class="dcccp-tooltip-label">${PERTII18n.t('initiative.tooltip.level')}</span><span class="dcccp-tooltip-value">${this.levels[item.level]?.name || item.level}</span></div>
                ${item.notes ? `<div class="dcccp-tooltip-row"><span class="dcccp-tooltip-label">${PERTII18n.t('initiative.label.notes')}</span><span class="dcccp-tooltip-value">${item.notes}</span></div>` : ''}
                <div class="dcccp-tooltip-time">${this.formatTooltipTime(item.start_datetime, item.end_datetime)}</div>
            </div>
        `;

        document.body.appendChild(tip);

        const rect = event.target.getBoundingClientRect();
        const tipRect = tip.getBoundingClientRect();
        let left = rect.left + rect.width / 2;
        let top = rect.bottom + 10;
        if (left + tipRect.width / 2 > window.innerWidth) {left = window.innerWidth - tipRect.width / 2 - 10;}
        if (left - tipRect.width / 2 < 0) {left = tipRect.width / 2 + 10;}
        if (top + tipRect.height > window.innerHeight) {top = rect.top - tipRect.height - 10;}
        tip.style.left = `${left}px`;
        tip.style.top = `${top}px`;

        tip.querySelector('.dcccp-tooltip-edit-btn')?.addEventListener('click', () => { this.hideTooltip(); this.openEditModal(id); });
        this.tooltip = tip;
    }

    hideTooltip() { if (this.tooltip) { this.tooltip.remove(); this.tooltip = null; } }

    showAddModal() {
        if (!this.hasPerm) {return;}

        document.getElementById(`${this.modalId}-id`).value = '';
        document.getElementById(`${this.modalId}-title`).textContent = PERTII18n.t('initiative.addElement');
        document.getElementById(`${this.modalId}-delete`).style.display = 'none';

        this.resetModalFields();

        const now = new Date();
        const end = new Date(now.getTime() + 4 * 3600000);
        document.getElementById(`${this.modalId}-start`).value = this.toInputFmt(now.toISOString());
        document.getElementById(`${this.modalId}-end`).value = this.toInputFmt(end.toISOString());

        this.updateFormSections();
        $(`#${this.modalId}`).modal('show');
    }

    openEditModal(id) {
        const item = this.data.find(d => d.id == id);
        if (!item) {return;}

        document.getElementById(`${this.modalId}-id`).value = item.id;
        document.getElementById(`${this.modalId}-title`).textContent = PERTII18n.t('initiative.editElement');
        document.getElementById(`${this.modalId}-delete`).style.display = 'inline-block';

        document.getElementById(`${this.modalId}-level`).value = item.level;
        this.updateFormSections();

        const cat = this.levels[item.level]?.category || 'tmi';

        switch (cat) {
            case 'tmi':
                document.getElementById(`${this.modalId}-tmi-facility`).value = item.facility || '';
                document.getElementById(`${this.modalId}-tmi-area`).value = item.area || '';
                document.getElementById(`${this.modalId}-tmi-type`).value = item.tmi_type || 'GS';
                document.getElementById(`${this.modalId}-tmi-other`).value = item.tmi_type_other || '';
                document.getElementById(`${this.modalId}-tmi-cause`).value = item.cause || '';
                document.getElementById(`${this.modalId}-advzy-number`).value = item.advzy_number || '';
                if (item.tmi_type === 'Other') {
                    document.getElementById(`${this.modalId}-tmi-other-wrap`).style.display = 'block';
                    document.getElementById(`${this.modalId}-tmi-cause-wrap`).style.display = 'none';
                }
                break;
            case 'vip':
                document.getElementById(`${this.modalId}-vip-type`).value = item.tmi_type || 'VIP Movement';
                document.getElementById(`${this.modalId}-vip-callsign`).value = item.tmi_type_other || '';
                document.getElementById(`${this.modalId}-vip-origin`).value = item.facility || '';
                document.getElementById(`${this.modalId}-vip-dest`).value = item.area || '';
                break;
            case 'space':
                document.getElementById(`${this.modalId}-space-type`).value = item.tmi_type || 'Rocket Launch';
                document.getElementById(`${this.modalId}-space-mission`).value = item.cause || '';
                document.getElementById(`${this.modalId}-space-facility`).value = item.facility || '';
                document.getElementById(`${this.modalId}-space-areas`).value = item.area || '';
                break;
            case 'staffing':
                document.getElementById(`${this.modalId}-staff-facility`).value = item.facility || '';
                document.getElementById(`${this.modalId}-staff-area`).value = item.area || '';
                document.getElementById(`${this.modalId}-staff-shift`).value = item.tmi_type || 'Day';
                document.getElementById(`${this.modalId}-staff-trigger`).value = item.cause || '';
                break;
            case 'cdw':
                document.getElementById(`${this.modalId}-cdw-facility`).value = item.facility || '';
                document.getElementById(`${this.modalId}-cdw-decision`).value = item.cause || '';
                break;
            case 'event':
                document.getElementById(`${this.modalId}-event-facility`).value = item.facility || '';
                document.getElementById(`${this.modalId}-event-name`).value = item.cause || '';
                break;
            case 'misc':
                document.getElementById(`${this.modalId}-misc-facility`).value = item.facility || '';
                document.getElementById(`${this.modalId}-misc-desc`).value = item.cause || '';
                break;
            case 'constraint':
                document.getElementById(`${this.modalId}-constraint-facility`).value = item.facility || '';
                document.getElementById(`${this.modalId}-constraint-type`).value = item.tmi_type || 'Weather';
                document.getElementById(`${this.modalId}-constraint-other`).value = item.tmi_type_other || '';
                document.getElementById(`${this.modalId}-constraint-impact`).value = item.cause || '';
                if (item.tmi_type === 'Other') {
                    document.getElementById(`${this.modalId}-constraint-other-wrap`).style.display = 'block';
                }
                break;
        }

        document.getElementById(`${this.modalId}-start`).value = this.toInputFmt(item.start_datetime);
        document.getElementById(`${this.modalId}-end`).value = this.toInputFmt(item.end_datetime);
        document.getElementById(`${this.modalId}-notes`).value = item.notes || '';
        document.getElementById(`${this.modalId}-global`).checked = item.is_global == 1;

        $(`#${this.modalId}`).modal('show');
    }

    resetModalFields() {
        document.querySelectorAll(`#${this.modalId} input[type="text"], #${this.modalId} textarea`).forEach(el => el.value = '');
        document.querySelectorAll(`#${this.modalId} select`).forEach(el => el.selectedIndex = 0);
        document.querySelectorAll(`#${this.modalId} input[type="checkbox"]`).forEach(el => el.checked = false);
        document.getElementById(`${this.modalId}-tmi-other-wrap`).style.display = 'none';
        document.getElementById(`${this.modalId}-tmi-cause-wrap`).style.display = 'block';
        document.getElementById(`${this.modalId}-advzy-wrap`).style.display = 'none';
        document.getElementById(`${this.modalId}-constraint-other-wrap`).style.display = 'none';
    }

    handleSave() {
        const level = document.getElementById(`${this.modalId}-level`).value;
        const cat = this.levels[level]?.category || 'tmi';
        const id = document.getElementById(`${this.modalId}-id`).value;

        const data = {
            p_id: this.planId,
            level: level,
            start_datetime: this.toISO(document.getElementById(`${this.modalId}-start`).value),
            end_datetime: this.toISO(document.getElementById(`${this.modalId}-end`).value),
            notes: document.getElementById(`${this.modalId}-notes`).value,
            is_global: document.getElementById(`${this.modalId}-global`).checked ? 1 : 0,
        };

        if (id) {data.id = id;}

        switch (cat) {
            case 'tmi':
                data.facility = document.getElementById(`${this.modalId}-tmi-facility`).value;
                data.area = document.getElementById(`${this.modalId}-tmi-area`).value;
                data.tmi_type = document.getElementById(`${this.modalId}-tmi-type`).value;
                data.tmi_type_other = document.getElementById(`${this.modalId}-tmi-other`).value;
                data.cause = document.getElementById(`${this.modalId}-tmi-cause`).value;
                // ADVZY number for Advisory levels
                if (level === 'Advisory_Terminal' || level === 'Advisory_EnRoute') {
                    data.advzy_number = document.getElementById(`${this.modalId}-advzy-number`).value;
                }
                break;
            case 'vip':
                data.facility = document.getElementById(`${this.modalId}-vip-origin`).value;
                data.area = document.getElementById(`${this.modalId}-vip-dest`).value;
                data.tmi_type = document.getElementById(`${this.modalId}-vip-type`).value;
                data.tmi_type_other = document.getElementById(`${this.modalId}-vip-callsign`).value;
                break;
            case 'space':
                data.facility = document.getElementById(`${this.modalId}-space-facility`).value;
                data.area = document.getElementById(`${this.modalId}-space-areas`).value;
                data.tmi_type = document.getElementById(`${this.modalId}-space-type`).value;
                data.cause = document.getElementById(`${this.modalId}-space-mission`).value;
                break;
            case 'staffing':
                data.facility = document.getElementById(`${this.modalId}-staff-facility`).value;
                data.area = document.getElementById(`${this.modalId}-staff-area`).value;
                data.tmi_type = document.getElementById(`${this.modalId}-staff-shift`).value;
                data.cause = document.getElementById(`${this.modalId}-staff-trigger`).value;
                break;
            case 'cdw':
                data.facility = document.getElementById(`${this.modalId}-cdw-facility`).value;
                data.cause = document.getElementById(`${this.modalId}-cdw-decision`).value;
                data.tmi_type = 'CDW';
                break;
            case 'event':
                data.facility = document.getElementById(`${this.modalId}-event-facility`).value;
                data.cause = document.getElementById(`${this.modalId}-event-name`).value;
                data.tmi_type = 'Special Event';
                break;
            case 'misc':
                data.facility = document.getElementById(`${this.modalId}-misc-facility`).value;
                data.cause = document.getElementById(`${this.modalId}-misc-desc`).value;
                data.tmi_type = 'Misc';
                break;
            case 'constraint': {
                data.facility = document.getElementById(`${this.modalId}-constraint-facility`).value;
                const constraintType = document.getElementById(`${this.modalId}-constraint-type`).value;
                data.tmi_type = constraintType;
                data.tmi_type_other = constraintType === 'Other' ? document.getElementById(`${this.modalId}-constraint-other`).value : '';
                data.cause = document.getElementById(`${this.modalId}-constraint-impact`).value;
                break;
            }
        }

        if (!data.facility) { this.alert('error', PERTII18n.t('initiative.error.facilityRequired')); return; }
        if (!data.start_datetime || !data.end_datetime) { this.alert('error', PERTII18n.t('initiative.error.timesRequired')); return; }

        this.saveItem(data, !!id);
    }

    async saveItem(data, isUpdate) {
        try {
            const resp = await fetch(this.apiEndpoint, {
                method: isUpdate ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            const result = await resp.json();

            if (result.success) {
                $(`#${this.modalId}`).modal('hide');
                this.alert('success', isUpdate ? PERTII18n.t('initiative.elementUpdated') : PERTII18n.t('initiative.elementAdded'));
                this.loadData();
            } else {
                this.alert('error', result.error || PERTII18n.t('initiative.error.saveFailed'));
            }
        } catch (e) {
            this.alert('error', PERTII18n.t('error.network'));
        }
    }

    handleDelete() {
        const id = document.getElementById(`${this.modalId}-id`).value;
        if (!id) {return;}
        if (confirm(PERTII18n.t('initiative.confirmDelete'))) {this.deleteItem(id);}
    }

    async deleteItem(id) {
        try {
            const resp = await fetch(`${this.apiEndpoint}?id=${id}`, { method: 'DELETE' });
            const result = await resp.json();
            if (result.success) {
                $(`#${this.modalId}`).modal('hide');
                this.alert('success', PERTII18n.t('initiative.elementDeleted'));
                this.loadData();
            } else {
                this.alert('error', result.error || PERTII18n.t('initiative.error.deleteFailed'));
            }
        } catch (e) {
            this.alert('error', PERTII18n.t('error.network'));
        }
    }

    alert(type, msg) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: type, title: type === 'success' ? PERTII18n.t('common.success') : PERTII18n.t('common.error'), text: msg, timer: type === 'success' ? 2000 : undefined, showConfirmButton: type !== 'success' });
        } else {
            alert(msg);
        }
    }

    /**
     * Convert ISO/MySQL datetime to datetime-local input format (YYYY-MM-DDTHH:MM)
     * Input is UTC, output is formatted string for the datetime-local input
     * User sees and enters UTC times
     */
    toInputFmt(datetime) {
        if (!datetime) {return '';}

        const dtStr = String(datetime).trim();

        // Parse components manually - no Date object involved to avoid timezone issues
        // Expected: "2024-01-03 23:59:00" or "2024-01-03T23:59:00" or "2024-01-03T23:59:00Z"
        const match = dtStr.match(/(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
        if (!match) {return '';}

        const [, year, month, day, hour, minute] = match;

        // Return in datetime-local format: YYYY-MM-DDTHH:MM
        return `${year}-${month}-${day}T${hour}:${minute}`;
    }

    /**
     * Convert datetime-local input value to ISO string for API
     * User enters UTC time, we send as UTC
     */
    toISO(inputValue) {
        if (!inputValue) {return '';}

        // Input is YYYY-MM-DDTHH:MM - append seconds and Z to mark as UTC
        return inputValue + ':00Z';
    }
}

window.InitiativeTimeline = InitiativeTimeline;
