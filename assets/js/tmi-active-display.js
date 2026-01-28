/**
 * TMI Active Display Controller
 *
 * FAA-style display of active TMIs (Restrictions & Advisories)
 * Modeled after:
 *   - https://www.fly.faa.gov/restrictions/restrictions
 *   - https://www.fly.faa.gov/adv/advADB
 *
 * @package PERTI
 * @subpackage Assets/JS
 * @version 1.2.0
 * @date 2026-01-28
 *
 * v1.2.0 Changes:
 *   - Added filter state persistence to localStorage
 *   - Fixed cancel functionality to use unified API endpoint
 *   - Added edit button functionality for TMI entries
 *
 * v1.1.0 Changes:
 *   - Added source filter support (Production/Staging/All)
 *   - Simplified buildFilterControls (filters now in PHP)
 *   - Updated filter state to include source
 *   - API calls now pass source parameter
 */

(function() {
    'use strict';

    // ===========================================
    // Configuration
    // ===========================================
    
    const CONFIG = {
        refreshIntervalMs: 60000,  // 60 seconds
        apiEndpoint: 'api/mgt/tmi/active.php',
        cancelEndpoint: 'api/mgt/tmi/cancel.php'
    };

    // ===========================================
    // State
    // ===========================================
    
    let state = {
        restrictions: [],
        advisories: [],
        programs: [],       // GDT programs (Ground Stops, GDPs)
        reroutes: [],       // Reroutes
        scheduled: [],
        cancelled: [],
        lastRefresh: null,
        refreshTimer: null,
        countdownTimer: null,
        secondsUntilRefresh: 60,
        filters: {
            source: 'PRODUCTION',
            reqFacility: 'ALL',
            provFacility: 'ALL',
            type: 'ALL',
            status: 'ACTIVE'
        }
    };

    // Facility list for dropdowns
    const FACILITIES = [
        'ALL', 'ZAB', 'ZAN', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHN', 'ZHU',
        'ZID', 'ZJX', 'ZKC', 'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA',
        'ZOB', 'ZSE', 'ZTL', 'CZEG', 'CZVR', 'CZWG', 'CZYZ', 'CZQM', 'CZQX'
    ];

    const ENTRY_TYPES = [
        { code: 'ALL', label: 'All Types' },
        { code: 'MIT', label: 'Miles-In-Trail' },
        { code: 'MINIT', label: 'Minutes-In-Trail' },
        { code: 'STOP', label: 'Ground Stop' },
        { code: 'APREQ', label: 'APREQ/CFR' },
        { code: 'TBM', label: 'Time-Based Metering' },
        { code: 'CONFIG', label: 'Configuration' },
        { code: 'DELAY', label: 'Delay Advisory' },
        { code: 'GDP', label: 'Ground Delay Program' },
        { code: 'REROUTE', label: 'Reroute' }
    ];

    // ===========================================
    // Initialization
    // ===========================================

    function init() {
        console.log('[TMI-Active] Initializing Active TMI Display');

        // Only initialize if the active panel exists
        if (!$('#activePanel').length) {
            console.log('[TMI-Active] Active panel not found, skipping init');
            return;
        }

        buildFilterControls();
        loadSavedFilters();  // Restore saved filter state
        bindEvents();
        loadActiveTmis();
        startAutoRefresh();
    }

    function buildFilterControls() {
        // Filters are now defined in PHP (tmi-publish.php)
        // This function just ensures the filter container exists
        const $filterContainer = $('#activeTmiFilters');
        if (!$filterContainer.length) {
            console.warn('[TMI-Active] Filter container not found');
            return;
        }

        console.log('[TMI-Active] Filter controls ready');
    }

    // ===========================================
    // Filter State Persistence
    // ===========================================

    const FILTER_STORAGE_KEY = 'tmi_active_filters';

    function saveFilters() {
        try {
            localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(state.filters));
            console.log('[TMI-Active] Filters saved to localStorage');
        } catch (e) {
            console.warn('[TMI-Active] Failed to save filters:', e);
        }
    }

    function loadSavedFilters() {
        try {
            const saved = localStorage.getItem(FILTER_STORAGE_KEY);
            if (saved) {
                const savedFilters = JSON.parse(saved);
                // Merge with defaults to ensure all keys exist
                state.filters = {
                    source: savedFilters.source || 'PRODUCTION',
                    reqFacility: savedFilters.reqFacility || 'ALL',
                    provFacility: savedFilters.provFacility || 'ALL',
                    type: savedFilters.type || 'ALL',
                    status: savedFilters.status || 'ACTIVE'
                };
                // Update UI to reflect saved state
                $('#filterSource').val(state.filters.source);
                $('#filterReqFac').val(state.filters.reqFacility);
                $('#filterProvFac').val(state.filters.provFacility);
                $('#filterType').val(state.filters.type);
                $('#filterStatus').val(state.filters.status);
                console.log('[TMI-Active] Filters restored from localStorage:', state.filters);
            }
        } catch (e) {
            console.warn('[TMI-Active] Failed to load saved filters:', e);
        }
    }

    function bindEvents() {
        // Filter controls
        $('#applyFilters').on('click', applyFilters);
        $('#resetFilters').on('click', resetFilters);

        // Refresh button
        $('#refreshActiveTmis').off('click').on('click', function() {
            loadActiveTmis();
        });

        // Tab shown event
        $('a[href="#activePanel"]').on('shown.bs.tab', function() {
            loadActiveTmis();
        });

        // Batch selection - Select All
        $('#selectAllRestrictions').on('change', function() {
            const isChecked = $(this).is(':checked');
            $('.batch-select-checkbox').prop('checked', isChecked);
            updateBatchControls();
        });

        // Individual checkbox changes
        $(document).on('change', '.batch-select-checkbox', function() {
            updateBatchControls();
        });

        // Batch cancel button
        $('#batchCancelBtn').on('click', performBatchCancel);
    }

    function updateBatchControls() {
        const selectedCount = $('.batch-select-checkbox:checked').length;
        $('#selectedCount').text(selectedCount);
        if (selectedCount > 0) {
            $('#batchCancelControls').show();
        } else {
            $('#batchCancelControls').hide();
        }
    }

    function performBatchCancel() {
        const selected = [];
        $('.batch-select-checkbox:checked').each(function() {
            selected.push({
                entityId: $(this).data('id'),
                entityType: $(this).data('type')
            });
        });

        if (selected.length === 0) {
            return;
        }

        // Check if any selected items support cancellation advisories
        const supportsAdvisory = selected.some(item =>
            ['PROGRAM', 'REROUTE'].includes(item.entityType) ||
            (item.entityType === 'ADVISORY' && item.advisoryType && item.advisoryType.includes('HOTLINE'))
        );

        Swal.fire({
            title: `Cancel ${selected.length} TMI${selected.length > 1 ? 's' : ''}?`,
            html: `<p>You are about to cancel <strong>${selected.length}</strong> TMI entr${selected.length > 1 ? 'ies' : 'y'}.</p>
                   <p class="text-danger">This action cannot be undone.</p>
                   ${supportsAdvisory ? `
                   <hr class="my-3">
                   <div class="form-check text-left">
                       <input type="checkbox" class="form-check-input" id="postCancelAdvisory" checked>
                       <label class="form-check-label" for="postCancelAdvisory">
                           <strong>Post Cancellation Advisories</strong><br>
                           <small class="text-muted">Auto-generates GS CNX, GDP CNX, Reroute Cancellation, or Hotline Termination advisories</small>
                       </label>
                   </div>
                   ` : ''}`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: '<i class="fas fa-times-circle"></i> Cancel All Selected',
            cancelButtonText: 'Nevermind',
            preConfirm: () => {
                return {
                    postAdvisory: supportsAdvisory && document.getElementById('postCancelAdvisory')?.checked
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                executeBatchCancel(selected, result.value?.postAdvisory || false);
            }
        });
    }

    function executeBatchCancel(items, postAdvisory = false) {
        Swal.fire({
            title: 'Cancelling...',
            html: `<div id="batchProgress">Processing 0 of ${items.length}...</div>`,
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        const userCid = window.TMI_PUBLISHER_CONFIG?.userCid || null;
        const userName = window.TMI_PUBLISHER_CONFIG?.userName || 'Unknown';
        const advisoryChannel = window.TMI_PUBLISHER_CONFIG?.advisoryChannel || 'advzy_staging';

        let completed = 0;
        let successes = 0;
        let failures = [];
        let advisoriesPosted = 0;

        const processNext = (index) => {
            if (index >= items.length) {
                Swal.close();
                showBatchResult(successes, failures, advisoriesPosted);
                loadActiveTmis();
                return;
            }

            const item = items[index];
            $('#batchProgress').text(`Processing ${index + 1} of ${items.length}...`);

            // Determine if this item supports advisory posting
            const canPostAdvisory = postAdvisory && (
                ['PROGRAM', 'REROUTE'].includes(item.entityType) ||
                (item.entityType === 'ADVISORY' && item.advisoryType && item.advisoryType.includes('HOTLINE'))
            );

            $.ajax({
                url: CONFIG.cancelEndpoint,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    entityType: item.entityType,
                    entityId: item.entityId,
                    reason: 'Batch cancellation',
                    userCid: userCid,
                    userName: userName,
                    postAdvisory: canPostAdvisory,
                    advisoryChannel: advisoryChannel
                }),
                success: function(response) {
                    if (response.success) {
                        successes++;
                        if (response.advisory && response.advisory.success) {
                            advisoriesPosted++;
                        }
                    } else {
                        failures.push({ id: item.entityId, error: response.error || 'Unknown error' });
                    }
                },
                error: function(xhr) {
                    failures.push({ id: item.entityId, error: xhr.responseJSON?.error || 'Request failed' });
                },
                complete: function() {
                    processNext(index + 1);
                }
            });
        };

        processNext(0);
    }

    function showBatchResult(successes, failures, advisoriesPosted = 0) {
        if (failures.length === 0) {
            let message = `Successfully cancelled ${successes} TMI${successes > 1 ? 's' : ''}.`;
            if (advisoriesPosted > 0) {
                message += ` Posted ${advisoriesPosted} cancellation advisor${advisoriesPosted > 1 ? 'ies' : 'y'}.`;
            }
            Swal.fire({
                icon: 'success',
                title: 'Batch Cancel Complete',
                text: message,
                timer: 3500,
                showConfirmButton: false
            });
        } else {
            let failureHtml = failures.map(f => `<li>#${f.id}: ${f.error}</li>`).join('');
            Swal.fire({
                icon: 'warning',
                title: 'Batch Cancel Partial',
                html: `<p>Cancelled ${successes} TMI${successes > 1 ? 's' : ''}.</p>
                       <p class="text-danger">Failed to cancel ${failures.length}:</p>
                       <ul class="text-left small">${failureHtml}</ul>`
            });
        }
    }

    // ===========================================
    // Data Loading
    // ===========================================
    
    function loadActiveTmis() {
        console.log('[TMI-Active] Loading active TMIs...');
        
        // Show loading state
        $('#restrictionsTableBody').html(`
            <tr>
                <td colspan="5" class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                    <div class="small text-muted mt-2">Loading restrictions...</div>
                </td>
            </tr>
        `);
        
        $('#advisoriesContainer').html(`
            <div class="text-center py-4">
                <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                <div class="small text-muted mt-2">Loading advisories...</div>
            </div>
        `);

        $.ajax({
            url: CONFIG.apiEndpoint,
            method: 'GET',
            data: {
                type: 'all',
                source: state.filters.source || 'PRODUCTION',
                include_scheduled: '1',
                include_cancelled: '1',
                cancelled_hours: 4,
                limit: 200
            },
            success: function(response) {
                console.log('[TMI-Active] Data received:', response);
                
                if (response.success) {
                    processData(response.data);
                    renderDisplay();
                    updateLastRefreshTime();
                } else {
                    showError('Failed to load TMIs: ' + (response.error || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('[TMI-Active] API error:', error);
                showError('Connection error. Database may not be configured.');
            }
        });
    }

    function processData(data) {
        // Separate by entity type: ENTRY, ADVISORY, PROGRAM, REROUTE
        const allActive = data.active || [];
        const allScheduled = data.scheduled || [];
        const allCancelled = data.cancelled || [];

        state.restrictions = [];
        state.advisories = [];
        state.programs = [];
        state.reroutes = [];
        state.scheduled = [];
        state.cancelled = [];

        // Process active items
        allActive.forEach(item => {
            if (item.entityType === 'ADVISORY') {
                state.advisories.push(item);
            } else if (item.entityType === 'PROGRAM') {
                state.programs.push(item);
            } else if (item.entityType === 'REROUTE') {
                state.reroutes.push(item);
            } else {
                state.restrictions.push(item);
            }
        });

        // Process scheduled
        allScheduled.forEach(item => {
            state.scheduled.push(item);
        });

        // Process cancelled
        allCancelled.forEach(item => {
            state.cancelled.push(item);
        });

        console.log('[TMI-Active] Processed:', {
            restrictions: state.restrictions.length,
            advisories: state.advisories.length,
            programs: state.programs.length,
            reroutes: state.reroutes.length,
            scheduled: state.scheduled.length,
            cancelled: state.cancelled.length
        });
    }

    // ===========================================
    // Rendering
    // ===========================================
    
    function renderDisplay() {
        renderRestrictions();  // Also includes programs and reroutes
        renderAdvisories();
        updateCounts();
    }

    function renderRestrictions() {
        const $tbody = $('#restrictionsTableBody');

        // Reset batch controls
        $('#selectAllRestrictions').prop('checked', false);
        $('#batchCancelControls').hide();
        $('#selectedCount').text('0');

        // Get filtered restrictions
        let items = getFilteredRestrictions();

        if (items.length === 0) {
            $tbody.html(`
                <tr>
                    <td colspan="7" class="text-center py-4 text-muted">
                        <i class="fas fa-check-circle fa-2x mb-2"></i>
                        <div>No active restrictions</div>
                    </td>
                </tr>
            `);
            return;
        }

        let html = '';
        items.forEach(item => {
            html += buildRestrictionRow(item);
        });

        $tbody.html(html);

        // Bind row click events
        $tbody.find('.restriction-row').on('click', function() {
            const id = $(this).data('id');
            const type = $(this).data('type');
            showRestrictionDetails(id, type);
        });

        // Bind cancel button events
        $tbody.find('.btn-cancel-tmi').on('click', function(e) {
            e.stopPropagation();
            const id = $(this).data('id');
            const type = $(this).data('type');
            cancelTmi(id, type);
        });

        // Bind edit button events
        $tbody.find('.btn-edit-tmi').on('click', function(e) {
            e.stopPropagation();
            const id = $(this).data('id');
            const type = $(this).data('type');
            editTmi(id, type);
        });
    }

    function buildRestrictionRow(item) {
        let reqFac, provFac, restriction;

        // Handle different entity types
        if (item.entityType === 'PROGRAM') {
            // GDT Programs (GS, GDP)
            reqFac = item.scopeCenters ? JSON.parse(item.scopeCenters || '[]').join(',') : 'ALL';
            provFac = item.ctlElement || '-';
            restriction = buildProgramText(item);
        } else if (item.entityType === 'REROUTE') {
            // Reroutes
            reqFac = item.originCenters || item.originAirports || '-';
            provFac = item.destCenters || item.destAirports || '-';
            restriction = buildRerouteText(item);
        } else {
            // NTML entries
            reqFac = item.requestingFacility || '-';
            provFac = item.providingFacility || '-';
            restriction = buildRestrictionText(item);
        }

        const startTime = formatFaaDateTime(item.validFrom);
        const stopTime = formatFaaDateTime(item.validUntil);
        const status = item.status || 'ACTIVE';

        const statusClass = status === 'CANCELLED' || status === 'PURGED' ? 'table-secondary' :
                           status === 'SCHEDULED' || status === 'PROPOSED' ? 'table-info' : '';
        const statusBadge = (status === 'CANCELLED' || status === 'PURGED') ? '<span class="badge badge-secondary ml-1">CXLD</span>' :
                           (status === 'SCHEDULED' || status === 'PROPOSED') ? '<span class="badge badge-info ml-1">SCHED</span>' : '';

        // Type badge for programs and reroutes
        let typeBadge = '';
        if (item.entityType === 'PROGRAM') {
            const pType = item.entryType || 'PROGRAM';
            typeBadge = pType === 'GS' ? '<span class="badge badge-danger mr-1">GS</span>' :
                       pType.includes('GDP') ? '<span class="badge badge-warning text-dark mr-1">GDP</span>' :
                       '<span class="badge badge-primary mr-1">' + pType + '</span>';
        } else if (item.entityType === 'REROUTE') {
            typeBadge = '<span class="badge badge-info mr-1">REROUTE</span>';
        }

        const canSelect = !['CANCELLED', 'PURGED', 'EXPIRED'].includes(status);

        return `
            <tr class="restriction-row ${statusClass}" data-id="${item.entityId}" data-type="${item.entityType}" style="cursor: pointer;">
                <td class="text-center" style="width: 30px;">
                    ${canSelect ? `<input type="checkbox" class="batch-select-checkbox" data-id="${item.entityId}" data-type="${item.entityType}" onclick="event.stopPropagation();">` : ''}
                </td>
                <td class="font-weight-bold">${escapeHtml(reqFac)}</td>
                <td class="font-weight-bold">${escapeHtml(provFac)}</td>
                <td class="restriction-text">${typeBadge}${escapeHtml(restriction)}${statusBadge}</td>
                <td class="text-monospace small">${startTime}</td>
                <td class="text-monospace small">${stopTime}</td>
                <td class="text-center">
                    ${canSelect ? `
                    <div class="btn-group btn-group-sm" role="group">
                        <button class="btn btn-xs btn-outline-primary btn-edit-tmi" data-id="${item.entityId}" data-type="${item.entityType}" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-xs btn-outline-danger btn-cancel-tmi" data-id="${item.entityId}" data-type="${item.entityType}" title="Cancel">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    ` : ''}
                </td>
            </tr>
        `;
    }

    function buildProgramText(item) {
        const parts = [];
        const programType = item.entryType || 'PROGRAM';

        // Airport
        if (item.ctlElement) {
            parts.push(item.ctlElement);
        }

        // Rate
        if (item.programRate) {
            parts.push(`${item.programRate}/hr`);
        }

        // Cause
        if (item.impactingCondition) {
            parts.push(item.impactingCondition);
        }

        // Flights info
        if (item.controlledFlights) {
            parts.push(`(${item.controlledFlights} flt)`);
        }

        return parts.join(' ') || item.summary || programType;
    }

    function buildRerouteText(item) {
        const parts = [];

        // Name
        if (item.name) {
            parts.push(item.name);
        }

        // Protected segment
        if (item.protectedSegment) {
            parts.push(`via ${item.protectedSegment}`);
        } else if (item.protectedFixes) {
            parts.push(`via ${item.protectedFixes}`);
        }

        // Cause
        if (item.impactingCondition) {
            parts.push(`(${item.impactingCondition})`);
        }

        return parts.join(' ') || item.summary || 'Reroute';
    }

    function buildRestrictionText(item) {
        const entryType = item.entryType || '';

        // For CONFIG and DELAY entries, show the raw text which has all the details
        if ((entryType === 'CONFIG' || entryType === 'DELAY') && item.rawText) {
            // Strip the timestamp prefix if present (format: DD/HHMM    )
            let text = item.rawText.replace(/^\d{2}\/\d{4}\s+/, '');
            return text;
        }

        const parts = [];

        // Time range at start (FAA format: HHMM-HHMM)
        const startHHMM = item.validFrom ? formatTimeOnly(item.validFrom) : '';
        const endHHMM = item.validUntil ? formatTimeOnly(item.validUntil) : '';
        if (startHHMM || endHHMM) {
            parts.push(`${startHHMM}-${endHHMM}`);
        }

        // Entry type and value
        const value = item.restrictionValue || '';

        if (entryType === 'MIT' && value) {
            parts.push(`${value}MIT`);
        } else if (entryType === 'MINIT' && value) {
            parts.push(`${value}MINIT`);
        } else if (entryType === 'STOP') {
            parts.push('STOP');
        } else if (entryType === 'APREQ' || entryType === 'CFR') {
            parts.push('APREQ');
        } else if (entryType === 'TBM') {
            parts.push('TBM');
        } else if (entryType) {
            parts.push(entryType);
        }

        // Control element
        if (item.ctlElement) {
            parts.push(item.ctlElement);
        }

        // Via/condition
        if (item.conditionText) {
            parts.push(`VIA ${item.conditionText}`);
        }

        // Qualifiers
        if (item.qualifiers) {
            parts.push(item.qualifiers);
        }

        // Reason
        if (item.reasonCode) {
            let reasonStr = item.reasonCode;
            if (item.reasonDetail && item.reasonDetail !== item.reasonCode) {
                reasonStr = `${item.reasonCode}:${item.reasonDetail}`;
            }
            parts.push(reasonStr);
        }

        // Facility codes at end
        if (item.requestingFacility && item.providingFacility) {
            parts.push(`${item.requestingFacility}:${item.providingFacility}`);
        }

        return parts.join(' ') || item.summary || item.rawText || 'Restriction';
    }

    function renderAdvisories() {
        const $container = $('#advisoriesContainer');
        
        // Get filtered advisories
        let items = getFilteredAdvisories();
        
        if (items.length === 0) {
            $container.html(`
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <div>No active advisories</div>
                </div>
            `);
            return;
        }
        
        let html = '';
        items.forEach(item => {
            html += buildAdvisoryCard(item);
        });
        
        $container.html(html);
        
        // Bind expand/collapse
        $container.find('.advisory-header').on('click', function() {
            const $card = $(this).closest('.advisory-card');
            $card.find('.advisory-body').slideToggle(200);
            $(this).find('.expand-icon').toggleClass('fa-chevron-down fa-chevron-up');
        });
        
        // Bind cancel buttons
        $container.find('.btn-cancel-advisory').on('click', function(e) {
            e.stopPropagation();
            const id = $(this).data('id');
            cancelAdvisory(id);
        });

        // Bind edit buttons
        $container.find('.btn-edit-advisory').on('click', function(e) {
            e.stopPropagation();
            const id = $(this).data('id');
            editAdvisory(id);
        });
    }

    function buildAdvisoryCard(item) {
        const advNum = item.advisoryNumber || '???';
        const advType = item.entryType || 'ADVISORY';
        const subject = item.subject || advType;
        const effectiveTime = formatFaaDateTime(item.validFrom);
        const status = item.status || 'ACTIVE';
        
        const headerColor = advType === 'HOTLINE' ? 'bg-danger' :
                           advType === 'SWAP' ? 'bg-warning text-dark' :
                           advType === 'OPSPLAN' ? 'bg-primary' : 'bg-secondary';
        
        const statusBadge = status === 'CANCELLED' ? '<span class="badge badge-secondary">CXLD</span>' :
                           status === 'SCHEDULED' ? '<span class="badge badge-info">SCHEDULED</span>' : '';
        
        // Parse body text for display
        const bodyText = item.bodyText || item.rawText || 'No details available.';
        
        return `
            <div class="card advisory-card mb-2 ${status === 'CANCELLED' ? 'border-secondary' : ''}">
                <div class="card-header ${headerColor} py-2 advisory-header" style="cursor: pointer;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="font-weight-bold">ADVZY ${escapeHtml(advNum)}</span>
                            <span class="mx-2">|</span>
                            <span>${escapeHtml(subject)}</span>
                            ${statusBadge}
                        </div>
                        <div class="d-flex align-items-center">
                            <span class="small mr-3">${effectiveTime}</span>
                            <i class="fas fa-chevron-down expand-icon"></i>
                        </div>
                    </div>
                </div>
                <div class="card-body advisory-body py-2" style="display: none;">
                    <pre class="advisory-text mb-2">${escapeHtml(bodyText)}</pre>
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            Created: ${formatFaaDateTime(item.createdAt)}
                            ${item.createdByName ? ` by ${escapeHtml(item.createdByName)}` : ''}
                        </small>
                        ${status !== 'CANCELLED' ? `
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-sm btn-outline-primary btn-edit-advisory" data-id="${item.entityId}">
                                <i class="fas fa-edit mr-1"></i> Edit
                            </button>
                            <button class="btn btn-sm btn-outline-danger btn-cancel-advisory" data-id="${item.entityId}">
                                <i class="fas fa-times mr-1"></i> Cancel
                            </button>
                        </div>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
    }

    // ===========================================
    // Filtering
    // ===========================================
    
    function applyFilters() {
        state.filters.source = $('#filterSource').val() || 'PRODUCTION';
        state.filters.reqFacility = $('#filterReqFac').val() || 'ALL';
        state.filters.provFacility = $('#filterProvFac').val() || 'ALL';
        state.filters.type = $('#filterType').val() || 'ALL';
        state.filters.status = $('#filterStatus').val() || 'ACTIVE';

        console.log('[TMI-Active] Applying filters:', state.filters);

        // Save filters to localStorage for persistence
        saveFilters();

        // Reload data with new source filter
        loadActiveTmis();
    }

    function resetFilters() {
        state.filters = {
            source: 'PRODUCTION',
            reqFacility: 'ALL',
            provFacility: 'ALL',
            type: 'ALL',
            status: 'ACTIVE'
        };

        $('#filterSource').val('PRODUCTION');
        $('#filterReqFac').val('ALL');
        $('#filterProvFac').val('ALL');
        $('#filterType').val('ALL');
        $('#filterStatus').val('ACTIVE');

        // Save reset filters to localStorage
        saveFilters();

        loadActiveTmis();
    }

    function getFilteredRestrictions() {
        let items = [];

        // Add based on status filter - includes ENTRY, PROGRAM, REROUTE
        if (state.filters.status === 'ALL' || state.filters.status === 'ACTIVE') {
            items = items.concat(state.restrictions);
            items = items.concat(state.programs);
            items = items.concat(state.reroutes);
        }
        if (state.filters.status === 'ALL' || state.filters.status === 'SCHEDULED') {
            items = items.concat(state.scheduled.filter(i => i.entityType !== 'ADVISORY'));
        }
        if (state.filters.status === 'ALL' || state.filters.status === 'CANCELLED') {
            items = items.concat(state.cancelled.filter(i => i.entityType !== 'ADVISORY'));
        }

        // Apply facility filters (for NTML entries)
        if (state.filters.reqFacility !== 'ALL') {
            items = items.filter(i => {
                // For PROGRAM/REROUTE, check ctlElement or scopeCenters
                if (i.entityType === 'PROGRAM') {
                    const centers = i.scopeCenters || '';
                    return centers.includes(state.filters.reqFacility) ||
                           (i.ctlElement || '').toUpperCase() === state.filters.reqFacility;
                }
                if (i.entityType === 'REROUTE') {
                    const origins = (i.originCenters || '').toUpperCase();
                    return origins.includes(state.filters.reqFacility);
                }
                return (i.requestingFacility || '').toUpperCase() === state.filters.reqFacility;
            });
        }

        if (state.filters.provFacility !== 'ALL') {
            items = items.filter(i => {
                if (i.entityType === 'PROGRAM') {
                    return (i.ctlElement || '').toUpperCase() === state.filters.provFacility;
                }
                if (i.entityType === 'REROUTE') {
                    const dests = (i.destCenters || '').toUpperCase();
                    return dests.includes(state.filters.provFacility);
                }
                return (i.providingFacility || '').toUpperCase() === state.filters.provFacility;
            });
        }

        // Apply type filter
        if (state.filters.type !== 'ALL') {
            items = items.filter(i => {
                const entryType = (i.entryType || '').toUpperCase();
                const filterType = state.filters.type.toUpperCase();

                // Map type filters to entity types
                if (filterType === 'GDP' && i.entityType === 'PROGRAM' && entryType.includes('GDP')) return true;
                if (filterType === 'STOP' && i.entityType === 'PROGRAM' && entryType === 'GS') return true;
                if (filterType === 'REROUTE' && i.entityType === 'REROUTE') return true;

                return entryType === filterType;
            });
        }

        return items;
    }

    function getFilteredAdvisories() {
        let items = [];
        
        if (state.filters.status === 'ALL' || state.filters.status === 'ACTIVE') {
            items = items.concat(state.advisories);
        }
        if (state.filters.status === 'ALL' || state.filters.status === 'SCHEDULED') {
            items = items.concat(state.scheduled.filter(i => i.entityType === 'ADVISORY'));
        }
        if (state.filters.status === 'ALL' || state.filters.status === 'CANCELLED') {
            items = items.concat(state.cancelled.filter(i => i.entityType === 'ADVISORY'));
        }
        
        return items;
    }

    // ===========================================
    // Actions
    // ===========================================
    
    function showRestrictionDetails(id, type) {
        // Find the item
        const allItems = [...state.restrictions, ...state.scheduled, ...state.cancelled];
        const item = allItems.find(i => i.entityId === id);
        
        if (!item) {
            console.warn('[TMI-Active] Item not found:', id);
            return;
        }
        
        const detailHtml = `
            <div class="restriction-detail">
                <table class="table table-sm table-borderless">
                    <tr><th width="140">Type:</th><td>${escapeHtml(item.entryType || '-')}</td></tr>
                    <tr><th>Control Element:</th><td>${escapeHtml(item.ctlElement || '-')}</td></tr>
                    <tr><th>Requesting:</th><td>${escapeHtml(item.requestingFacility || '-')}</td></tr>
                    <tr><th>Providing:</th><td>${escapeHtml(item.providingFacility || '-')}</td></tr>
                    <tr><th>Value:</th><td>${item.restrictionValue ? item.restrictionValue + ' ' + (item.restrictionUnit || '') : '-'}</td></tr>
                    <tr><th>Via/Condition:</th><td>${escapeHtml(item.conditionText || '-')}</td></tr>
                    <tr><th>Qualifiers:</th><td>${escapeHtml(item.qualifiers || '-')}</td></tr>
                    <tr><th>Exclusions:</th><td>${escapeHtml(item.exclusions || '-')}</td></tr>
                    <tr><th>Reason:</th><td>${escapeHtml(item.reasonCode || '-')} ${item.reasonDetail ? ': ' + escapeHtml(item.reasonDetail) : ''}</td></tr>
                    <tr><th>Valid From:</th><td>${formatFaaDateTime(item.validFrom)}</td></tr>
                    <tr><th>Valid Until:</th><td>${formatFaaDateTime(item.validUntil)}</td></tr>
                    <tr><th>Status:</th><td><span class="badge badge-${item.status === 'ACTIVE' ? 'success' : item.status === 'CANCELLED' ? 'secondary' : 'info'}">${item.status || 'UNKNOWN'}</span></td></tr>
                    <tr><th>Created:</th><td>${formatFaaDateTime(item.createdAt)} ${item.createdByName ? 'by ' + escapeHtml(item.createdByName) : ''}</td></tr>
                    ${item.cancelledAt ? `<tr><th>Cancelled:</th><td>${formatFaaDateTime(item.cancelledAt)} ${item.cancelReason ? '- ' + escapeHtml(item.cancelReason) : ''}</td></tr>` : ''}
                </table>
                ${item.rawText ? `
                <hr>
                <div class="small text-muted mb-1">Raw Text:</div>
                <pre class="bg-light p-2 small">${escapeHtml(item.rawText)}</pre>
                ` : ''}
            </div>
        `;
        
        Swal.fire({
            title: `<i class="fas fa-info-circle text-primary"></i> TMI Details`,
            html: detailHtml,
            width: 600,
            showCancelButton: item.status !== 'CANCELLED',
            confirmButtonText: 'Close',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel TMI',
            cancelButtonColor: '#dc3545'
        }).then((result) => {
            if (result.dismiss === Swal.DismissReason.cancel) {
                cancelTmi(id, type);
            }
        });
    }

    function cancelTmi(id, type) {
        Swal.fire({
            title: 'Cancel TMI?',
            html: `<p>Are you sure you want to cancel this TMI?</p>
                   <div class="form-group">
                       <label class="small">Cancel Reason (optional):</label>
                       <input type="text" id="cancelReason" class="form-control" placeholder="e.g., Weather improved">
                   </div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Cancel TMI',
            preConfirm: () => {
                return document.getElementById('cancelReason').value || 'Cancelled via TMI Publisher';
            }
        }).then((result) => {
            if (result.isConfirmed) {
                performCancel(id, type, result.value);
            }
        });
    }

    function performCancel(id, type, reason) {
        Swal.fire({
            title: 'Cancelling...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        // Get user info from TMI_PUBLISHER_CONFIG if available
        const userCid = window.TMI_PUBLISHER_CONFIG?.userCid || null;
        const userName = window.TMI_PUBLISHER_CONFIG?.userName || 'Unknown';

        // Map entity type to API type
        const entityType = ['ADVISORY', 'PROGRAM', 'REROUTE'].includes(type) ? type : 'ENTRY';

        $.ajax({
            url: CONFIG.cancelEndpoint,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                entityType: entityType,
                entityId: id,
                reason: reason,
                userCid: userCid,
                userName: userName
            }),
            success: function(response) {
                Swal.close();

                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'TMI Cancelled',
                        text: response.message || 'The TMI has been cancelled successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    });

                    // Reload data
                    loadActiveTmis();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Cancel Failed',
                        text: response.error || 'Unknown error'
                    });
                }
            },
            error: function(xhr, status, error) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Cancel Failed',
                    text: 'Failed to connect to server: ' + error
                });
            }
        });
    }

    function cancelAdvisory(id) {
        cancelTmi(id, 'ADVISORY');
    }

    // ===========================================
    // Edit Functions
    // ===========================================

    function editTmi(id, type) {
        // Route to appropriate edit function based on type
        if (type === 'PROGRAM') {
            editProgram(id);
            return;
        }
        if (type === 'REROUTE') {
            editReroute(id);
            return;
        }

        // Find the item in state (ENTRY type)
        const allItems = [...state.restrictions, ...state.scheduled];
        const item = allItems.find(i => i.entityId === id && i.entityType === 'ENTRY');

        if (!item) {
            Swal.fire('Error', 'Could not find TMI entry data', 'error');
            return;
        }

        // Format current dates for datetime-local inputs
        const formatForInput = (dateStr) => {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toISOString().slice(0, 16);
        };

        const entryType = item.entryType || '';
        const showValueField = ['MIT', 'MINIT'].includes(entryType);

        Swal.fire({
            title: '<i class="fas fa-edit text-primary"></i> Edit TMI Entry',
            html: `
                <div class="text-left" style="max-height: 60vh; overflow-y: auto;">
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Type</label>
                            <input type="text" class="form-control form-control-sm bg-light" value="${escapeHtml(entryType)}" readonly>
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Control Element</label>
                            <input type="text" id="editCtlElement" class="form-control form-control-sm" value="${escapeHtml(item.ctlElement || '')}">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Requesting Facility</label>
                            <input type="text" id="editReqFac" class="form-control form-control-sm" value="${escapeHtml(item.requestingFacility || '')}" placeholder="e.g., ZDC,ZNY">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Providing Facility</label>
                            <input type="text" id="editProvFac" class="form-control form-control-sm" value="${escapeHtml(item.providingFacility || '')}" placeholder="e.g., ZOB">
                        </div>
                    </div>
                    ${showValueField ? `
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Value</label>
                            <input type="number" id="editValue" class="form-control form-control-sm" value="${item.restrictionValue || ''}">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Unit</label>
                            <select id="editUnit" class="form-control form-control-sm">
                                <option value="MIT" ${item.restrictionUnit === 'MIT' ? 'selected' : ''}>MIT (Miles)</option>
                                <option value="MIN" ${item.restrictionUnit === 'MIN' ? 'selected' : ''}>MIN (Minutes)</option>
                            </select>
                        </div>
                    </div>
                    ` : ''}
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Valid From (UTC)</label>
                            <input type="datetime-local" id="editValidFrom" class="form-control form-control-sm" value="${formatForInput(item.validFrom)}">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Valid Until (UTC)</label>
                            <input type="datetime-local" id="editValidUntil" class="form-control form-control-sm" value="${formatForInput(item.validUntil)}">
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Via / Condition</label>
                        <input type="text" id="editCondition" class="form-control form-control-sm" value="${escapeHtml(item.conditionText || '')}" placeholder="e.g., BRISS">
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Qualifiers</label>
                            <input type="text" id="editQualifiers" class="form-control form-control-sm" value="${escapeHtml(item.qualifiers || '')}" placeholder="e.g., JETS">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Exclusions</label>
                            <input type="text" id="editExclusions" class="form-control form-control-sm" value="${escapeHtml(item.exclusions || '')}" placeholder="e.g., PROPS">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Reason Code</label>
                            <select id="editReasonCode" class="form-control form-control-sm">
                                <option value="">-- Select --</option>
                                <option value="VOLUME" ${item.reasonCode === 'VOLUME' ? 'selected' : ''}>VOLUME</option>
                                <option value="WEATHER" ${item.reasonCode === 'WEATHER' ? 'selected' : ''}>WEATHER</option>
                                <option value="STAFFING" ${item.reasonCode === 'STAFFING' ? 'selected' : ''}>STAFFING</option>
                                <option value="RUNWAY" ${item.reasonCode === 'RUNWAY' ? 'selected' : ''}>RUNWAY</option>
                                <option value="EQUIPMENT" ${item.reasonCode === 'EQUIPMENT' ? 'selected' : ''}>EQUIPMENT</option>
                                <option value="OTHER" ${item.reasonCode === 'OTHER' ? 'selected' : ''}>OTHER</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Reason Detail</label>
                            <input type="text" id="editReasonDetail" class="form-control form-control-sm" value="${escapeHtml(item.reasonDetail || '')}">
                        </div>
                    </div>
                </div>
            `,
            width: 600,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-save"></i> Save Changes',
            confirmButtonColor: '#28a745',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return {
                    validFrom: document.getElementById('editValidFrom').value,
                    validUntil: document.getElementById('editValidUntil').value,
                    restrictionValue: document.getElementById('editValue')?.value || null,
                    restrictionUnit: document.getElementById('editUnit')?.value || null,
                    ctlElement: document.getElementById('editCtlElement').value,
                    requestingFacility: document.getElementById('editReqFac').value,
                    providingFacility: document.getElementById('editProvFac').value,
                    conditionText: document.getElementById('editCondition').value,
                    qualifiers: document.getElementById('editQualifiers').value,
                    exclusions: document.getElementById('editExclusions').value,
                    reasonCode: document.getElementById('editReasonCode').value,
                    reasonDetail: document.getElementById('editReasonDetail').value
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                performEdit(id, type, result.value);
            }
        });
    }

    function editAdvisory(id) {
        // Find the advisory in state
        const allItems = [...state.advisories, ...state.scheduled.filter(i => i.entityType === 'ADVISORY')];
        const item = allItems.find(i => i.entityId === id);

        if (!item) {
            Swal.fire('Error', 'Could not find advisory data', 'error');
            return;
        }

        const formatForInput = (dateStr) => {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toISOString().slice(0, 16);
        };

        const advType = item.entryType || 'FREEFORM';
        const showProgramFields = ['GDP', 'GS', 'STOP'].includes(advType);

        Swal.fire({
            title: '<i class="fas fa-edit text-primary"></i> Edit Advisory',
            html: `
                <div class="text-left" style="max-height: 65vh; overflow-y: auto;">
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Advisory #</label>
                            <input type="text" class="form-control form-control-sm bg-light" value="${escapeHtml(item.advisoryNumber || '-')}" readonly>
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Type</label>
                            <input type="text" class="form-control form-control-sm bg-light" value="${escapeHtml(advType)}" readonly>
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Subject</label>
                        <input type="text" id="editSubject" class="form-control form-control-sm" value="${escapeHtml(item.subject || '')}">
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Control Element</label>
                            <input type="text" id="editCtlElement" class="form-control form-control-sm" value="${escapeHtml(item.ctlElement || '')}" placeholder="e.g., KJFK">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Scope Facilities</label>
                            <input type="text" id="editScopeFac" class="form-control form-control-sm" value="${escapeHtml(item.scopeFacilities || '')}" placeholder="e.g., ZNY,ZBW">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Effective From (UTC)</label>
                            <input type="datetime-local" id="editValidFrom" class="form-control form-control-sm" value="${formatForInput(item.validFrom)}">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Effective Until (UTC)</label>
                            <input type="datetime-local" id="editValidUntil" class="form-control form-control-sm" value="${formatForInput(item.validUntil)}">
                        </div>
                    </div>
                    ${showProgramFields ? `
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Program Rate</label>
                            <input type="number" id="editProgramRate" class="form-control form-control-sm" value="${item.programRate || ''}" placeholder="Flights/hr">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Delay Cap (min)</label>
                            <input type="number" id="editDelayCap" class="form-control form-control-sm" value="${item.delayCap || ''}" placeholder="Minutes">
                        </div>
                    </div>
                    ` : ''}
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Reason Code</label>
                            <select id="editReasonCode" class="form-control form-control-sm">
                                <option value="">-- Select --</option>
                                <option value="VOLUME" ${item.reasonCode === 'VOLUME' ? 'selected' : ''}>VOLUME</option>
                                <option value="WEATHER" ${item.reasonCode === 'WEATHER' ? 'selected' : ''}>WEATHER</option>
                                <option value="STAFFING" ${item.reasonCode === 'STAFFING' ? 'selected' : ''}>STAFFING</option>
                                <option value="RUNWAY" ${item.reasonCode === 'RUNWAY' ? 'selected' : ''}>RUNWAY</option>
                                <option value="EQUIPMENT" ${item.reasonCode === 'EQUIPMENT' ? 'selected' : ''}>EQUIPMENT</option>
                                <option value="OTHER" ${item.reasonCode === 'OTHER' ? 'selected' : ''}>OTHER</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Reason Detail</label>
                            <input type="text" id="editReasonDetail" class="form-control form-control-sm" value="${escapeHtml(item.reasonDetail || '')}">
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Body Text</label>
                        <textarea id="editBodyText" class="form-control form-control-sm" rows="6" style="font-family: monospace; font-size: 11px;">${escapeHtml(item.bodyText || item.rawText || '')}</textarea>
                    </div>
                </div>
            `,
            width: 650,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-save"></i> Save Changes',
            confirmButtonColor: '#28a745',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return {
                    validFrom: document.getElementById('editValidFrom').value,
                    validUntil: document.getElementById('editValidUntil').value,
                    subject: document.getElementById('editSubject').value,
                    ctlElement: document.getElementById('editCtlElement').value,
                    scopeFacilities: document.getElementById('editScopeFac').value,
                    reasonCode: document.getElementById('editReasonCode').value,
                    reasonDetail: document.getElementById('editReasonDetail').value,
                    bodyText: document.getElementById('editBodyText').value,
                    programRate: document.getElementById('editProgramRate')?.value || null,
                    delayCap: document.getElementById('editDelayCap')?.value || null
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                performEdit(id, 'ADVISORY', result.value);
            }
        });
    }

    function editProgram(id) {
        // Find the program in state
        const allItems = [...state.programs, ...state.scheduled.filter(i => i.entityType === 'PROGRAM')];
        const item = allItems.find(i => i.entityId === id);

        if (!item) {
            Swal.fire('Error', 'Could not find program data', 'error');
            return;
        }

        const formatForInput = (dateStr) => {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toISOString().slice(0, 16);
        };

        const programType = item.entryType || 'GS';
        const isGDP = programType.includes('GDP');

        Swal.fire({
            title: `<i class="fas fa-edit text-primary"></i> Edit ${programType === 'GS' ? 'Ground Stop' : 'GDP'}`,
            html: `
                <div class="text-left" style="max-height: 60vh; overflow-y: auto;">
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Type</label>
                            <input type="text" class="form-control form-control-sm bg-light" value="${escapeHtml(programType)}" readonly>
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Airport</label>
                            <input type="text" class="form-control form-control-sm bg-light" value="${escapeHtml(item.ctlElement || '')}" readonly>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Start Time (UTC)</label>
                            <input type="datetime-local" id="editValidFrom" class="form-control form-control-sm" value="${formatForInput(item.validFrom)}">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">End Time (UTC)</label>
                            <input type="datetime-local" id="editValidUntil" class="form-control form-control-sm" value="${formatForInput(item.validUntil)}">
                        </div>
                    </div>
                    ${isGDP ? `
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Program Rate (/hr)</label>
                            <input type="number" id="editProgramRate" class="form-control form-control-sm" value="${item.programRate || ''}">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Scope Type</label>
                            <select id="editScopeType" class="form-control form-control-sm">
                                <option value="">-- Select --</option>
                                <option value="TIER" ${item.scopeType === 'TIER' ? 'selected' : ''}>Tier</option>
                                <option value="DISTANCE" ${item.scopeType === 'DISTANCE' ? 'selected' : ''}>Distance</option>
                                <option value="CENTER" ${item.scopeType === 'CENTER' ? 'selected' : ''}>Center</option>
                                <option value="ALL" ${item.scopeType === 'ALL' ? 'selected' : ''}>All</option>
                            </select>
                        </div>
                    </div>
                    ` : ''}
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Impacting Condition</label>
                            <select id="editImpactingCondition" class="form-control form-control-sm">
                                <option value="">-- Select --</option>
                                <option value="WEATHER" ${item.impactingCondition === 'WEATHER' ? 'selected' : ''}>Weather</option>
                                <option value="VOLUME" ${item.impactingCondition === 'VOLUME' ? 'selected' : ''}>Volume</option>
                                <option value="RUNWAY" ${item.impactingCondition === 'RUNWAY' ? 'selected' : ''}>Runway</option>
                                <option value="EQUIPMENT" ${item.impactingCondition === 'EQUIPMENT' ? 'selected' : ''}>Equipment</option>
                                <option value="OTHER" ${item.impactingCondition === 'OTHER' ? 'selected' : ''}>Other</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Cause Detail</label>
                            <input type="text" id="editCauseText" class="form-control form-control-sm" value="${escapeHtml(item.causeText || '')}">
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Comments</label>
                        <textarea id="editComments" class="form-control form-control-sm" rows="3">${escapeHtml(item.comments || '')}</textarea>
                    </div>
                </div>
            `,
            width: 600,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-save"></i> Save Changes',
            confirmButtonColor: '#28a745',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return {
                    validFrom: document.getElementById('editValidFrom').value,
                    validUntil: document.getElementById('editValidUntil').value,
                    programRate: document.getElementById('editProgramRate')?.value || null,
                    scopeType: document.getElementById('editScopeType')?.value || null,
                    impactingCondition: document.getElementById('editImpactingCondition').value,
                    causeText: document.getElementById('editCauseText').value,
                    comments: document.getElementById('editComments').value
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                performEdit(id, 'PROGRAM', result.value);
            }
        });
    }

    function editReroute(id) {
        // Find the reroute in state
        const allItems = [...state.reroutes, ...state.scheduled.filter(i => i.entityType === 'REROUTE')];
        const item = allItems.find(i => i.entityId === id);

        if (!item) {
            Swal.fire('Error', 'Could not find reroute data', 'error');
            return;
        }

        const formatForInput = (dateStr) => {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toISOString().slice(0, 16);
        };

        Swal.fire({
            title: '<i class="fas fa-edit text-primary"></i> Edit Reroute',
            html: `
                <div class="text-left" style="max-height: 60vh; overflow-y: auto;">
                    <div class="row mb-2">
                        <div class="col-12">
                            <label class="small font-weight-bold">Name</label>
                            <input type="text" id="editName" class="form-control form-control-sm" value="${escapeHtml(item.name || '')}">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Start Time (UTC)</label>
                            <input type="datetime-local" id="editValidFrom" class="form-control form-control-sm" value="${formatForInput(item.validFrom)}">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">End Time (UTC)</label>
                            <input type="datetime-local" id="editValidUntil" class="form-control form-control-sm" value="${formatForInput(item.validUntil)}">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Origin Centers</label>
                            <input type="text" id="editOriginCenters" class="form-control form-control-sm" value="${escapeHtml(item.originCenters || '')}" placeholder="e.g., ZNY,ZBW">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Dest Centers</label>
                            <input type="text" id="editDestCenters" class="form-control form-control-sm" value="${escapeHtml(item.destCenters || '')}" placeholder="e.g., ZLA,ZOA">
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Protected Segment / Fixes</label>
                        <input type="text" id="editProtectedSegment" class="form-control form-control-sm" value="${escapeHtml(item.protectedSegment || item.protectedFixes || '')}" placeholder="Route or fixes">
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Avoid Fixes</label>
                        <input type="text" id="editAvoidFixes" class="form-control form-control-sm" value="${escapeHtml(item.avoidFixes || '')}" placeholder="Fixes to avoid">
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Impacting Condition</label>
                            <select id="editImpactingCondition" class="form-control form-control-sm">
                                <option value="">-- Select --</option>
                                <option value="WEATHER" ${item.impactingCondition === 'WEATHER' ? 'selected' : ''}>Weather</option>
                                <option value="VOLUME" ${item.impactingCondition === 'VOLUME' ? 'selected' : ''}>Volume</option>
                                <option value="RUNWAY" ${item.impactingCondition === 'RUNWAY' ? 'selected' : ''}>Runway</option>
                                <option value="OTHER" ${item.impactingCondition === 'OTHER' ? 'selected' : ''}>Other</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">&nbsp;</label>
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Comments</label>
                        <textarea id="editComments" class="form-control form-control-sm" rows="2">${escapeHtml(item.comments || '')}</textarea>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Advisory Text</label>
                        <textarea id="editAdvisoryText" class="form-control form-control-sm" rows="3" style="font-family: monospace; font-size: 11px;">${escapeHtml(item.advisoryText || '')}</textarea>
                    </div>
                </div>
            `,
            width: 600,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-save"></i> Save Changes',
            confirmButtonColor: '#28a745',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return {
                    validFrom: document.getElementById('editValidFrom').value,
                    validUntil: document.getElementById('editValidUntil').value,
                    name: document.getElementById('editName').value,
                    originCenters: document.getElementById('editOriginCenters').value,
                    destCenters: document.getElementById('editDestCenters').value,
                    protectedSegment: document.getElementById('editProtectedSegment').value,
                    avoidFixes: document.getElementById('editAvoidFixes').value,
                    impactingCondition: document.getElementById('editImpactingCondition').value,
                    comments: document.getElementById('editComments').value,
                    advisoryText: document.getElementById('editAdvisoryText').value
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                performEdit(id, 'REROUTE', result.value);
            }
        });
    }

    function performEdit(id, type, data) {
        Swal.fire({
            title: 'Saving...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        const userCid = window.TMI_PUBLISHER_CONFIG?.userCid || null;
        const userName = window.TMI_PUBLISHER_CONFIG?.userName || 'Unknown';

        // Map type to API entity type
        const entityType = ['ADVISORY', 'PROGRAM', 'REROUTE'].includes(type) ? type : 'ENTRY';

        $.ajax({
            url: 'api/mgt/tmi/edit.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                entityType: entityType,
                entityId: id,
                updates: data,
                userCid: userCid,
                userName: userName
            }),
            success: function(response) {
                Swal.close();

                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Changes Saved',
                        text: response.message || 'The TMI has been updated successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    });

                    // Reload data
                    loadActiveTmis();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Update Failed',
                        text: response.error || 'Unknown error'
                    });
                }
            },
            error: function(xhr, status, error) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Update Failed',
                    text: 'Failed to connect to server: ' + error
                });
            }
        });
    }

    // ===========================================
    // Auto-Refresh
    // ===========================================
    
    function startAutoRefresh() {
        // Clear existing timers
        if (state.refreshTimer) clearInterval(state.refreshTimer);
        if (state.countdownTimer) clearInterval(state.countdownTimer);
        
        state.secondsUntilRefresh = 60;
        
        // Countdown timer (every second)
        state.countdownTimer = setInterval(function() {
            state.secondsUntilRefresh--;
            updateCountdownDisplay();
            
            if (state.secondsUntilRefresh <= 0) {
                state.secondsUntilRefresh = 60;
                loadActiveTmis();
            }
        }, 1000);
    }

    function updateCountdownDisplay() {
        const $countdown = $('#refreshCountdown');
        if ($countdown.length) {
            $countdown.text(`${state.secondsUntilRefresh}s`);
        }
    }

    function updateLastRefreshTime() {
        state.lastRefresh = new Date();
        const $lastRefresh = $('#lastRefreshTime');
        if ($lastRefresh.length) {
            $lastRefresh.text(state.lastRefresh.toISOString().substr(11, 8) + ' UTC');
        }
        state.secondsUntilRefresh = 60;
    }

    function updateCounts() {
        const activeCount = state.restrictions.length + state.advisories.length +
                           state.programs.length + state.reroutes.length;
        const schedCount = state.scheduled.length;
        const cxldCount = state.cancelled.length;

        $('#activeCount').text(activeCount);
        $('#scheduledCount').text(schedCount);
        $('#cancelledCount').text(cxldCount);

        // Update restriction count (includes programs and reroutes)
        const filteredRestrictions = getFilteredRestrictions();
        const filteredAdvisories = getFilteredAdvisories();
        $('#restrictionCount').text(filteredRestrictions.length);
        $('#advisoryCount').text(filteredAdvisories.length);
    }

    // ===========================================
    // Utilities
    // ===========================================
    
    function formatFaaDateTime(isoString) {
        if (!isoString) return '-';

        try {
            const d = new Date(isoString);
            if (isNaN(d.getTime())) return '-';

            const month = String(d.getUTCMonth() + 1).padStart(2, '0');
            const day = String(d.getUTCDate()).padStart(2, '0');
            const year = d.getUTCFullYear();
            const hours = String(d.getUTCHours()).padStart(2, '0');
            const minutes = String(d.getUTCMinutes()).padStart(2, '0');

            const dateStr = `${month}/${day}/${year} ${hours}${minutes}`;
            const relativeStr = getRelativeTime(d);

            return `${dateStr}<br><small class="text-muted">${relativeStr}</small>`;
        } catch (e) {
            return '-';
        }
    }

    function getRelativeTime(date) {
        const now = new Date();
        const diffMs = date.getTime() - now.getTime();
        const diffMins = Math.round(diffMs / 60000);
        const diffHours = Math.round(diffMins / 60);
        const diffDays = Math.round(diffHours / 24);

        if (diffMins === 0) return 'now';

        if (diffMins > 0) {
            // Future
            if (diffMins < 60) return `in ${diffMins}m`;
            if (diffHours < 24) return `in ${diffHours}h`;
            return `in ${diffDays}d`;
        } else {
            // Past
            const absMins = Math.abs(diffMins);
            const absHours = Math.abs(diffHours);
            const absDays = Math.abs(diffDays);
            if (absMins < 60) return `${absMins}m ago`;
            if (absHours < 24) return `${absHours}h ago`;
            return `${absDays}d ago`;
        }
    }

    function formatTimeOnly(isoString) {
        if (!isoString) return '';
        
        try {
            const d = new Date(isoString);
            if (isNaN(d.getTime())) return '';
            
            const hours = String(d.getUTCHours()).padStart(2, '0');
            const minutes = String(d.getUTCMinutes()).padStart(2, '0');
            
            return `${hours}${minutes}`;
        } catch (e) {
            return '';
        }
    }

    function showError(message) {
        $('#restrictionsTableBody').html(`
            <tr>
                <td colspan="5" class="text-center py-4 text-muted">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                    <div>${escapeHtml(message)}</div>
                </td>
            </tr>
        `);
        
        $('#advisoriesContainer').html(`
            <div class="text-center py-4 text-muted">
                <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                <div>${escapeHtml(message)}</div>
            </div>
        `);
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const str = String(text);
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ===========================================
    // Public API
    // ===========================================
    
    window.TMIActiveDisplay = {
        init: init,
        refresh: loadActiveTmis,
        applyFilters: applyFilters,
        resetFilters: resetFilters,
        cancelTmi: cancelTmi
    };

    // Auto-init when document ready
    $(document).ready(function() {
        // Small delay to ensure other scripts loaded
        setTimeout(init, 100);
    });

})();
