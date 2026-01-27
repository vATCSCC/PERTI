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
        // Separate restrictions (NTML entries) from advisories
        const allActive = data.active || [];
        const allScheduled = data.scheduled || [];
        const allCancelled = data.cancelled || [];
        
        state.restrictions = [];
        state.advisories = [];
        state.scheduled = [];
        state.cancelled = [];
        
        // Process active items
        allActive.forEach(item => {
            if (item.entityType === 'ADVISORY') {
                state.advisories.push(item);
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
            scheduled: state.scheduled.length,
            cancelled: state.cancelled.length
        });
    }

    // ===========================================
    // Rendering
    // ===========================================
    
    function renderDisplay() {
        renderRestrictions();
        renderAdvisories();
        updateCounts();
    }

    function renderRestrictions() {
        const $tbody = $('#restrictionsTableBody');
        
        // Get filtered restrictions
        let items = getFilteredRestrictions();
        
        if (items.length === 0) {
            $tbody.html(`
                <tr>
                    <td colspan="5" class="text-center py-4 text-muted">
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
        const reqFac = item.requestingFacility || '-';
        const provFac = item.providingFacility || '-';
        const restriction = buildRestrictionText(item);
        const startTime = formatFaaDateTime(item.validFrom);
        const stopTime = formatFaaDateTime(item.validUntil);
        const status = item.status || 'ACTIVE';
        
        const statusClass = status === 'CANCELLED' ? 'table-secondary' : 
                           status === 'SCHEDULED' ? 'table-info' : '';
        const statusBadge = status === 'CANCELLED' ? '<span class="badge badge-secondary ml-1">CXLD</span>' :
                           status === 'SCHEDULED' ? '<span class="badge badge-info ml-1">SCHED</span>' : '';
        
        return `
            <tr class="restriction-row ${statusClass}" data-id="${item.entityId}" data-type="${item.entityType}" style="cursor: pointer;">
                <td class="font-weight-bold">${escapeHtml(reqFac)}</td>
                <td class="font-weight-bold">${escapeHtml(provFac)}</td>
                <td class="restriction-text">${escapeHtml(restriction)}${statusBadge}</td>
                <td class="text-monospace small">${startTime}</td>
                <td class="text-monospace small">${stopTime}</td>
                <td class="text-center">
                    ${status !== 'CANCELLED' ? `
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

    function buildRestrictionText(item) {
        const parts = [];
        
        // Time range at start (FAA format: HHMM-HHMM)
        const startHHMM = item.validFrom ? formatTimeOnly(item.validFrom) : '';
        const endHHMM = item.validUntil ? formatTimeOnly(item.validUntil) : '';
        if (startHHMM || endHHMM) {
            parts.push(`${startHHMM}-${endHHMM}`);
        }
        
        // Entry type and value
        const entryType = item.entryType || '';
        const value = item.restrictionValue || '';
        const unit = item.restrictionUnit || '';
        
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
        
        // Add based on status filter
        if (state.filters.status === 'ALL' || state.filters.status === 'ACTIVE') {
            items = items.concat(state.restrictions);
        }
        if (state.filters.status === 'ALL' || state.filters.status === 'SCHEDULED') {
            items = items.concat(state.scheduled.filter(i => i.entityType !== 'ADVISORY'));
        }
        if (state.filters.status === 'ALL' || state.filters.status === 'CANCELLED') {
            items = items.concat(state.cancelled.filter(i => i.entityType !== 'ADVISORY'));
        }
        
        // Apply facility filters
        if (state.filters.reqFacility !== 'ALL') {
            items = items.filter(i => 
                (i.requestingFacility || '').toUpperCase() === state.filters.reqFacility
            );
        }
        
        if (state.filters.provFacility !== 'ALL') {
            items = items.filter(i => 
                (i.providingFacility || '').toUpperCase() === state.filters.provFacility
            );
        }
        
        // Apply type filter
        if (state.filters.type !== 'ALL') {
            items = items.filter(i => 
                (i.entryType || '').toUpperCase() === state.filters.type
            );
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

        $.ajax({
            url: CONFIG.cancelEndpoint,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                entityType: type === 'ADVISORY' ? 'ADVISORY' : 'ENTRY',
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
        // Find the item in state
        const allItems = [...state.restrictions, ...state.scheduled];
        const item = allItems.find(i => i.entityId === id);

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

        Swal.fire({
            title: '<i class="fas fa-edit text-primary"></i> Edit TMI Entry',
            html: `
                <div class="text-left">
                    <div class="mb-2">
                        <strong>Type:</strong> ${escapeHtml(item.entryType || '-')}
                    </div>
                    <div class="mb-2">
                        <strong>Control Element:</strong> ${escapeHtml(item.ctlElement || '-')}
                    </div>
                    <hr>
                    <div class="form-group">
                        <label class="small font-weight-bold">Valid From (UTC)</label>
                        <input type="datetime-local" id="editValidFrom" class="form-control" value="${formatForInput(item.validFrom)}">
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold">Valid Until (UTC)</label>
                        <input type="datetime-local" id="editValidUntil" class="form-control" value="${formatForInput(item.validUntil)}">
                    </div>
                    ${item.restrictionValue ? `
                    <div class="form-group">
                        <label class="small font-weight-bold">Value</label>
                        <input type="number" id="editValue" class="form-control" value="${item.restrictionValue}">
                    </div>
                    ` : ''}
                </div>
            `,
            width: 500,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-save"></i> Save Changes',
            confirmButtonColor: '#28a745',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return {
                    validFrom: document.getElementById('editValidFrom').value,
                    validUntil: document.getElementById('editValidUntil').value,
                    restrictionValue: document.getElementById('editValue')?.value || null
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

        Swal.fire({
            title: '<i class="fas fa-edit text-primary"></i> Edit Advisory',
            html: `
                <div class="text-left">
                    <div class="mb-2">
                        <strong>Advisory #:</strong> ${escapeHtml(item.advisoryNumber || '-')}
                    </div>
                    <div class="mb-2">
                        <strong>Type:</strong> ${escapeHtml(item.entryType || '-')}
                    </div>
                    <hr>
                    <div class="form-group">
                        <label class="small font-weight-bold">Effective From (UTC)</label>
                        <input type="datetime-local" id="editValidFrom" class="form-control" value="${formatForInput(item.validFrom)}">
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold">Effective Until (UTC)</label>
                        <input type="datetime-local" id="editValidUntil" class="form-control" value="${formatForInput(item.validUntil)}">
                    </div>
                </div>
            `,
            width: 500,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-save"></i> Save Changes',
            confirmButtonColor: '#28a745',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return {
                    validFrom: document.getElementById('editValidFrom').value,
                    validUntil: document.getElementById('editValidUntil').value
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                performEdit(id, 'ADVISORY', result.value);
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

        $.ajax({
            url: 'api/mgt/tmi/edit.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                entityType: type === 'ADVISORY' ? 'ADVISORY' : 'ENTRY',
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
        const activeCount = state.restrictions.length + state.advisories.length;
        const schedCount = state.scheduled.length;
        const cxldCount = state.cancelled.length;
        
        $('#activeCount').text(activeCount);
        $('#scheduledCount').text(schedCount);
        $('#cancelledCount').text(cxldCount);
        
        // Update restriction count
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
            
            return `${month}/${day}/${year} ${hours}${minutes}`;
        } catch (e) {
            return '-';
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
