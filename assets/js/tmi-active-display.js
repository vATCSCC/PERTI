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
        expired: [],        // Expired TMIs (end time passed)
        lastRefresh: null,
        refreshTimer: null,
        countdownTimer: null,
        secondsUntilRefresh: 60,
        filters: {
            source: 'PRODUCTION',
            reqFacilities: [],   // Array for multi-select
            provFacilities: [],  // Array for multi-select
            type: 'ALL',
            status: 'ACTIVE',
            date: ''             // Date filter (YYYY-MM-DD format, empty = today/current)
        }
    };

    // ===========================================
    // Use Global Facility Hierarchy
    // ===========================================
    // References to global FacilityHierarchy (from facility-hierarchy.js)
    // These are shortcuts for convenience; the global object has full data
    const getARTCCS = () => FacilityHierarchy.ARTCCS;
    const getDCC_REGIONS = () => FacilityHierarchy.DCC_REGIONS;
    const getFACILITY_GROUPS = () => FacilityHierarchy.FACILITY_GROUPS;
    const getARTCC_TO_REGION = () => FacilityHierarchy.ARTCC_TO_REGION;
    const getFACILITY_HIERARCHY = () => FacilityHierarchy.FACILITY_HIERARCHY;
    const getTRACON_TO_ARTCC = () => FacilityHierarchy.TRACON_TO_ARTCC;
    const getAIRPORT_TO_TRACON = () => FacilityHierarchy.AIRPORT_TO_TRACON;
    const getAIRPORT_TO_ARTCC = () => FacilityHierarchy.AIRPORT_TO_ARTCC;
    const getALL_TRACONS = () => FacilityHierarchy.ALL_TRACONS;

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

        // Load facility hierarchy first, then initialize controls
        loadFacilityHierarchy().then(() => {
            buildFilterControls();
            initFacilitySelects();
            loadSavedFilters();
            bindEvents();
            loadActiveTmis();
            startAutoRefresh();
        });
    }

    // ===========================================
    // Facility Hierarchy (uses global FacilityHierarchy)
    // ===========================================

    async function loadFacilityHierarchy() {
        // Use the global FacilityHierarchy.load() method
        try {
            await FacilityHierarchy.load();
            console.log('[TMI-Active] Facility hierarchy loaded via global FacilityHierarchy:', {
                artccs: getARTCCS().length,
                tracons: getALL_TRACONS().size
            });
        } catch (e) {
            console.warn('[TMI-Active] Failed to load facility hierarchy:', e);
        }
    }

    function expandFacilitySelection(facilities) {
        // Use the global FacilityHierarchy.expandSelection method
        return FacilityHierarchy.expandSelection(facilities);
    }

    function initFacilitySelects() {
        // Build facility options with grouping
        const $reqFac = $('#filterReqFac');
        const $provFac = $('#filterProvFac');

        if (!$reqFac.length || !$provFac.length) return;

        // Add class for CSS styling
        $reqFac.addClass('facility-filter-select');
        $provFac.addClass('facility-filter-select');

        // Get data from global FacilityHierarchy
        const ARTCCS = getARTCCS();
        const DCC_REGIONS = getDCC_REGIONS();
        const FACILITY_GROUPS = getFACILITY_GROUPS();
        const ARTCC_TO_REGION = getARTCC_TO_REGION();
        const ALL_TRACONS = getALL_TRACONS();
        const FACILITY_HIERARCHY = getFACILITY_HIERARCHY();
        const TRACON_TO_ARTCC = getTRACON_TO_ARTCC();

        // Build grouped options HTML
        let optionsHtml = '';

        // Quick-select groups (special prefix for identification)
        optionsHtml += '<optgroup label="Quick Select">';
        Object.entries(FACILITY_GROUPS).forEach(([key, group]) => {
            optionsHtml += `<option value="GROUP:${key}" data-group="${key}">${group.name}</option>`;
        });
        optionsHtml += '</optgroup>';

        // DCC Regions
        optionsHtml += '<optgroup label="DCC Regions">';
        Object.entries(DCC_REGIONS).forEach(([key, region]) => {
            optionsHtml += `<option value="REGION:${key}" data-region="${key}" style="color: ${region.color}">${region.name}</option>`;
        });
        optionsHtml += '</optgroup>';

        // ARTCCs grouped by DCC Region
        Object.entries(DCC_REGIONS).forEach(([regionKey, region]) => {
            const artccsInRegion = region.artccs.filter(a => ARTCCS.includes(a));
            if (artccsInRegion.length > 0) {
                optionsHtml += `<optgroup label="ARTCCs - ${region.name}" data-region="${regionKey}">`;
                artccsInRegion.forEach(artcc => {
                    optionsHtml += `<option value="${artcc}" data-artcc-region="${regionKey}" style="color: ${region.color}">${artcc}</option>`;
                });
                optionsHtml += '</optgroup>';
            }
        });

        // ARTCCs not in any region
        const otherArtccs = ARTCCS.filter(a => !ARTCC_TO_REGION[a]);
        if (otherArtccs.length > 0) {
            optionsHtml += '<optgroup label="ARTCCs - Other">';
            otherArtccs.forEach(artcc => {
                optionsHtml += `<option value="${artcc}">${artcc}</option>`;
            });
            optionsHtml += '</optgroup>';
        }

        // TRACONs group (sorted alphabetically, limited to common ones)
        const sortedTracons = Array.from(ALL_TRACONS).sort();
        const commonTracons = sortedTracons.filter(t =>
            ['N90', 'PHL', 'A80', 'C90', 'D10', 'D01', 'I90', 'L30', 'NCT', 'PCT', 'P50', 'P80', 'SCT', 'T75', 'MIA', 'CLE', 'IND', 'BOS', 'DEN', 'ORD'].includes(t) ||
            (FACILITY_HIERARCHY[t] && FACILITY_HIERARCHY[t].length >= 3)
        );
        if (commonTracons.length > 0) {
            optionsHtml += '<optgroup label="Major TRACONs">';
            commonTracons.slice(0, 50).forEach(tracon => {
                const parentArtcc = TRACON_TO_ARTCC[tracon] || '';
                const region = ARTCC_TO_REGION[parentArtcc];
                const color = region ? DCC_REGIONS[region]?.color : '';
                optionsHtml += `<option value="${tracon}"${color ? ` style="color: ${color}"` : ''}>${tracon}${parentArtcc ? ' (' + parentArtcc + ')' : ''}</option>`;
            });
            optionsHtml += '</optgroup>';
        }

        // Apply options to both selects
        $reqFac.html(optionsHtml);
        $provFac.html(optionsHtml);

        // Initialize Select2 with multi-select
        const select2Config = {
            placeholder: 'All Facilities',
            allowClear: true,
            width: '100%',
            closeOnSelect: false,
            templateResult: formatFacilityOption,
            templateSelection: formatFacilitySelection
        };

        if ($.fn.select2) {
            $reqFac.select2(select2Config);
            $provFac.select2(select2Config);

            // Handle group/region selection expansion
            $reqFac.on('select2:select', handleGroupSelection);
            $provFac.on('select2:select', handleGroupSelection);

            // Initialize Type and Status filters with Select2 (simpler config)
            const simpleSelect2Config = {
                placeholder: 'All',
                allowClear: true,
                width: '100%',
                closeOnSelect: false
            };
            $('#filterType').select2({...simpleSelect2Config, placeholder: 'All Types'});
            $('#filterStatus').select2({...simpleSelect2Config, placeholder: 'All Status'});

            // Initialize Source filter with Select2 (single-select)
            $('#filterSource').select2({
                placeholder: 'All Sources',
                allowClear: false,
                width: '100%',
                minimumResultsForSearch: Infinity // Hide search box for simple dropdown
            });

            // Set default status selection
            $('#filterStatus').val(['ACTIVE', 'SCHEDULED']).trigger('change.select2');

            console.log('[TMI-Active] Select2 initialized for all filters');
        } else {
            console.warn('[TMI-Active] Select2 not available');
        }
    }

    function handleGroupSelection(e) {
        const value = e.params.data.id;
        const $select = $(e.target);
        const FACILITY_GROUPS = getFACILITY_GROUPS();
        const DCC_REGIONS = getDCC_REGIONS();

        // Check if this is a group or region selection
        if (value.startsWith('GROUP:')) {
            const groupKey = value.replace('GROUP:', '');
            const group = FACILITY_GROUPS[groupKey];
            if (group) {
                // Get current values and remove the group placeholder
                let currentVals = $select.val() || [];
                currentVals = currentVals.filter(v => v !== value);
                // Add all ARTCCs from this group
                const newVals = [...new Set([...currentVals, ...group.artccs])];
                $select.val(newVals).trigger('change.select2');
            }
        } else if (value.startsWith('REGION:')) {
            const regionKey = value.replace('REGION:', '');
            const region = DCC_REGIONS[regionKey];
            if (region) {
                // Get current values and remove the region placeholder
                let currentVals = $select.val() || [];
                currentVals = currentVals.filter(v => v !== value);
                // Add all ARTCCs from this region
                const newVals = [...new Set([...currentVals, ...region.artccs])];
                $select.val(newVals).trigger('change.select2');
            }
        }
    }

    function formatFacilityOption(option) {
        if (!option.id) return option.text;

        const fac = option.id;
        let badge = '';
        let style = '';
        const FACILITY_GROUPS = getFACILITY_GROUPS();
        const DCC_REGIONS = getDCC_REGIONS();
        const ARTCCS = getARTCCS();
        const ARTCC_TO_REGION = getARTCC_TO_REGION();
        const FACILITY_HIERARCHY = getFACILITY_HIERARCHY();

        // Quick-select group
        if (fac.startsWith('GROUP:')) {
            const groupKey = fac.replace('GROUP:', '');
            const group = FACILITY_GROUPS[groupKey];
            if (group) {
                badge = `<span class="badge badge-secondary ml-1">${group.artccs.length} ARTCCs</span>`;
            }
            return $(`<span><i class="fas fa-layer-group mr-1"></i>${option.text} ${badge}</span>`);
        }

        // DCC Region
        if (fac.startsWith('REGION:')) {
            const regionKey = fac.replace('REGION:', '');
            const region = DCC_REGIONS[regionKey];
            if (region) {
                badge = `<span class="badge ml-1" style="background-color: ${region.color}; color: white">${region.artccs.length} ARTCCs</span>`;
            }
            return $(`<span><i class="fas fa-map-marked-alt mr-1"></i>${option.text} ${badge}</span>`);
        }

        // ARTCC with region color
        if (ARTCCS.includes(fac)) {
            const childCount = FACILITY_HIERARCHY[fac]?.length || 0;
            const region = ARTCC_TO_REGION[fac];
            const regionColor = region ? DCC_REGIONS[region]?.color : '#007bff';
            badge = `<span class="badge ml-1" style="background-color: ${regionColor}; color: white">${childCount} fac</span>`;
            return $(`<span style="color: ${regionColor}">${option.text} ${badge}</span>`);
        }

        // TRACON
        const ALL_TRACONS = getALL_TRACONS();
        const TRACON_TO_ARTCC = getTRACON_TO_ARTCC();
        if (ALL_TRACONS.has(fac)) {
            const childCount = FACILITY_HIERARCHY[fac]?.length || 0;
            const parentArtcc = TRACON_TO_ARTCC[fac];
            const region = ARTCC_TO_REGION[parentArtcc];
            const regionColor = region ? DCC_REGIONS[region]?.color : '#17a2b8';
            badge = `<span class="badge badge-info ml-1">${childCount} apt</span>`;
            return $(`<span style="color: ${regionColor}">${option.text} ${badge}</span>`);
        }

        return $(`<span>${option.text} ${badge}</span>`);
    }

    function formatFacilitySelection(option) {
        const fac = option.id || option.text;

        // Don't show GROUP: or REGION: prefixed items in selection
        if (fac.startsWith('GROUP:') || fac.startsWith('REGION:')) {
            return null;  // Will be replaced with actual values
        }

        const DCC_REGIONS = getDCC_REGIONS();
        const ARTCC_TO_REGION = getARTCC_TO_REGION();
        const TRACON_TO_ARTCC = getTRACON_TO_ARTCC();

        // Get color for the tag based on region
        const region = ARTCC_TO_REGION[fac] || ARTCC_TO_REGION[TRACON_TO_ARTCC[fac]];
        let bgColor = '#6c757d'; // Default gray

        if (region && DCC_REGIONS[region]) {
            bgColor = DCC_REGIONS[region].color;
        }

        // Return styled span - Select2 will use this for the tag content
        // We also set a data attribute that we can use to color the parent element
        const $el = $(`<span class="fac-tag" data-region="${region || ''}" data-fac="${fac}">${fac}</span>`);

        // Apply background color to parent choice element after render
        setTimeout(function() {
            $el.closest('.select2-selection__choice').css({
                'background-color': bgColor,
                'border-color': bgColor
            });
        }, 0);

        return $el;
    }

    function getRegionForFacility(facility) {
        // Use global FacilityHierarchy.getRegion method
        return FacilityHierarchy.getRegion(facility);
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
                // Handle migration from old single-value format to multi-select
                state.filters = {
                    source: savedFilters.source || 'PRODUCTION',
                    reqFacilities: Array.isArray(savedFilters.reqFacilities) ? savedFilters.reqFacilities :
                                   (savedFilters.reqFacility && savedFilters.reqFacility !== 'ALL' ? [savedFilters.reqFacility] : []),
                    provFacilities: Array.isArray(savedFilters.provFacilities) ? savedFilters.provFacilities :
                                    (savedFilters.provFacility && savedFilters.provFacility !== 'ALL' ? [savedFilters.provFacility] : []),
                    // Migrate type from single to multi-select
                    types: Array.isArray(savedFilters.types) ? savedFilters.types :
                           (savedFilters.type && savedFilters.type !== 'ALL' ? [savedFilters.type] : []),
                    // Migrate status from single to multi-select, default to ACTIVE+SCHEDULED
                    statuses: Array.isArray(savedFilters.statuses) ? savedFilters.statuses :
                              (savedFilters.status && savedFilters.status !== 'ACTIVE' ? [savedFilters.status] : ['ACTIVE', 'SCHEDULED']),
                    // Date filter (don't persist - always start with today/current)
                    date: ''
                };
            } else {
                // Default filters for first-time users
                state.filters = {
                    source: 'PRODUCTION',
                    reqFacilities: [],
                    provFacilities: [],
                    types: [],
                    statuses: ['ACTIVE', 'SCHEDULED'],
                    date: ''
                };
            }
            // Update UI to reflect saved state
            $('#filterSource').val(state.filters.source);
            $('#filterDate').val(state.filters.date);
            if ($.fn.select2) {
                $('#filterReqFac').val(state.filters.reqFacilities).trigger('change.select2');
                $('#filterProvFac').val(state.filters.provFacilities).trigger('change.select2');
                $('#filterType').val(state.filters.types).trigger('change.select2');
                $('#filterStatus').val(state.filters.statuses).trigger('change.select2');
            }
            console.log('[TMI-Active] Filters restored:', state.filters);
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
                entityType: $(this).data('type'),
                type: $(this).data('subtype') || null  // For distinguishing publicroute vs reroute
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
        // Create floating progress indicator (non-blocking)
        const progressId = 'batchCancelProgress_' + Date.now();
        const progressHtml = `
            <div id="${progressId}" class="batch-cancel-progress" style="
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: #fff;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 15px 20px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 9999;
                min-width: 280px;
                font-family: inherit;
            ">
                <div style="display: flex; align-items: center; margin-bottom: 10px;">
                    <i class="fas fa-spinner fa-spin text-primary mr-2"></i>
                    <strong>Batch Cancel</strong>
                    <small class="ml-auto text-muted" id="${progressId}_count">0 / ${items.length}</small>
                </div>
                <div class="progress" style="height: 6px;">
                    <div id="${progressId}_bar" class="progress-bar bg-primary" role="progressbar" style="width: 0%"></div>
                </div>
                <small class="text-muted d-block mt-2" id="${progressId}_status">Starting...</small>
            </div>
        `;
        $('body').append(progressHtml);

        const userCid = window.TMI_PUBLISHER_CONFIG?.userCid || null;
        const userName = window.TMI_PUBLISHER_CONFIG?.userName || 'Unknown';
        const advisoryChannel = window.TMI_PUBLISHER_CONFIG?.advisoryChannel || 'advzy_staging';

        let successes = 0;
        let failures = [];
        let advisoriesPosted = 0;

        const updateProgress = (index) => {
            const pct = Math.round(((index + 1) / items.length) * 100);
            $(`#${progressId}_count`).text(`${index + 1} / ${items.length}`);
            $(`#${progressId}_bar`).css('width', pct + '%');
            $(`#${progressId}_status`).text(`Cancelling item ${index + 1}...`);
        };

        const processNext = (index) => {
            if (index >= items.length) {
                // Remove progress indicator
                $(`#${progressId}`).fadeOut(300, function() { $(this).remove(); });
                // Show result
                showBatchResult(successes, failures, advisoriesPosted);
                loadActiveTmis();
                return;
            }

            const item = items[index];
            updateProgress(index);

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
                    advisoryChannel: advisoryChannel,
                    type: item.type || null  // Pass subtype to distinguish publicroute vs reroute
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
            let message = `Cancelled ${successes} TMI${successes > 1 ? 's' : ''}.`;
            if (advisoriesPosted > 0) {
                message += ` Posted ${advisoriesPosted} advisor${advisoriesPosted > 1 ? 'ies' : 'y'}.`;
            }
            // Use toast for success (non-blocking)
            Swal.fire({
                toast: true,
                position: 'bottom-end',
                icon: 'success',
                title: message,
                timer: 4000,
                showConfirmButton: false,
                timerProgressBar: true
            });
        } else {
            // Show modal only if there were failures (user needs to see errors)
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

    // Advisory batch controls
    function updateAdvisoryBatchControls() {
        const selectedCount = $('.batch-select-advisory:checked').length;
        $('#advisorySelectedCount').text(selectedCount);
        if (selectedCount > 0) {
            $('#advisoryBatchCancelControls').show();
        } else {
            $('#advisoryBatchCancelControls').hide();
        }
    }

    function performAdvisoryBatchCancel() {
        const selected = [];
        $('.batch-select-advisory:checked').each(function() {
            selected.push({
                entityId: $(this).data('id'),
                entityType: $(this).data('type'),
                type: $(this).data('subtype') || null  // For distinguishing publicroute vs reroute
            });
        });

        if (selected.length === 0) {
            return;
        }

        // All advisory types support cancellation advisories
        const supportsAdvisory = true;

        Swal.fire({
            title: `Cancel ${selected.length} Advisory${selected.length > 1 ? 's' : ''}?`,
            html: `<p>You are about to cancel <strong>${selected.length}</strong> advisor${selected.length > 1 ? 'ies' : 'y'}.</p>
                   <p class="text-danger">This action cannot be undone.</p>
                   <hr class="my-3">
                   <div class="form-check text-left">
                       <input type="checkbox" class="form-check-input" id="postCancelAdvisoryBatch" checked>
                       <label class="form-check-label" for="postCancelAdvisoryBatch">
                           <strong>Post Cancellation Advisories</strong><br>
                           <small class="text-muted">Auto-generates cancellation advisories for reroutes and hotlines</small>
                       </label>
                   </div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: '<i class="fas fa-times-circle"></i> Cancel All Selected',
            cancelButtonText: 'Nevermind',
            preConfirm: () => {
                return {
                    postAdvisory: document.getElementById('postCancelAdvisoryBatch')?.checked
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                executeBatchCancel(selected, result.value?.postAdvisory || false);
            }
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

        // Build API params - include date if filtering by historical date
        const apiParams = {
            type: 'all',
            source: state.filters.source || 'PRODUCTION',
            include_scheduled: '1',
            include_cancelled: '1',
            cancelled_hours: 4,
            limit: 200
        };
        if (state.filters.date) {
            apiParams.date = state.filters.date;
        }

        $.ajax({
            url: CONFIG.apiEndpoint,
            method: 'GET',
            data: apiParams,
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
        state.expired = [];

        // Process active items
        allActive.forEach(item => {
            if (item.entityType === 'ADVISORY') {
                state.advisories.push(item);
            } else if (item.entityType === 'REROUTE') {
                // Reroutes go to advisories section (they have advisory text to display)
                state.advisories.push(item);
                state.reroutes.push(item);  // Also keep in reroutes for filtering purposes
            } else if (item.entityType === 'PROGRAM') {
                state.programs.push(item);
            } else {
                state.restrictions.push(item);
            }
        });

        // Process scheduled
        allScheduled.forEach(item => {
            state.scheduled.push(item);
        });

        // Process cancelled/expired - separate by status field
        allCancelled.forEach(item => {
            if (item.status === 'EXPIRED') {
                state.expired.push(item);
            } else {
                state.cancelled.push(item);
            }
        });

        console.log('[TMI-Active] Processed:', {
            restrictions: state.restrictions.length,
            advisories: state.advisories.length,
            programs: state.programs.length,
            reroutes: state.reroutes.length,
            scheduled: state.scheduled.length,
            cancelled: state.cancelled.length,
            expired: state.expired.length
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
            const subtype = $(this).data('subtype');
            cancelTmi(id, type, subtype);
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
            <tr class="restriction-row ${statusClass}" data-id="${item.entityId}" data-type="${item.entityType}" data-subtype="${item.type || ''}" style="cursor: pointer;">
                <td class="text-center" style="width: 30px;">
                    ${canSelect ? `<input type="checkbox" class="batch-select-checkbox" data-id="${item.entityId}" data-type="${item.entityType}" data-subtype="${item.type || ''}" onclick="event.stopPropagation();">` : ''}
                </td>
                <td class="font-weight-bold">${escapeHtml(reqFac)}</td>
                <td class="font-weight-bold">${escapeHtml(provFac)}</td>
                <td class="restriction-text">${typeBadge}${escapeHtml(restriction)}${statusBadge}</td>
                <td class="text-monospace small">${startTime}</td>
                <td class="text-monospace small">${stopTime}</td>
                <td class="text-center">
                    ${canSelect ? `
                    <div class="btn-group btn-group-sm" role="group">
                        <button class="btn btn-xs btn-outline-primary btn-edit-tmi" data-id="${item.entityId}" data-type="${item.entityType}" data-subtype="${item.type || ''}" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-xs btn-outline-danger btn-cancel-tmi" data-id="${item.entityId}" data-type="${item.entityType}" data-subtype="${item.type || ''}" title="Cancel">
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

        // Reset batch controls for advisories
        $('#selectAllAdvisories').prop('checked', false);
        $('#advisoryBatchCancelControls').hide();
        $('#advisorySelectedCount').text('0');

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

        // Build batch controls header
        const hasSelectableItems = items.some(i => !['CANCELLED', 'PURGED', 'EXPIRED'].includes(i.status));
        let html = '';
        if (hasSelectableItems) {
            html += `
                <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="selectAllAdvisories">
                        <label class="form-check-label small" for="selectAllAdvisories">Select All</label>
                    </div>
                    <div id="advisoryBatchCancelControls" style="display: none;">
                        <span class="badge badge-secondary mr-2"><span id="advisorySelectedCount">0</span> selected</span>
                        <button class="btn btn-sm btn-danger" id="advisoryBatchCancelBtn">
                            <i class="fas fa-times-circle"></i> Cancel Selected
                        </button>
                    </div>
                </div>
            `;
        }

        items.forEach(item => {
            html += buildAdvisoryCard(item);
        });

        $container.html(html);

        // Bind batch controls
        $('#selectAllAdvisories').on('change', function() {
            const isChecked = $(this).is(':checked');
            $('.batch-select-advisory').prop('checked', isChecked);
            updateAdvisoryBatchControls();
        });

        // Individual advisory checkbox changes
        $container.on('change', '.batch-select-advisory', function() {
            updateAdvisoryBatchControls();
        });

        // Advisory batch cancel button
        $('#advisoryBatchCancelBtn').on('click', performAdvisoryBatchCancel);

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
            const type = $(this).data('type') || 'ADVISORY';
            const subtype = $(this).data('subtype');
            cancelAdvisory(id, type, subtype);
        });

        // Bind edit buttons
        $container.find('.btn-edit-advisory').on('click', function(e) {
            e.stopPropagation();
            const id = $(this).data('id');
            const type = $(this).data('type') || 'ADVISORY';
            editAdvisory(id, type);
        });
    }

    function buildAdvisoryCard(item) {
        const isReroute = item.entityType === 'REROUTE';
        const advNum = item.advisoryNumber || '???';
        const advType = isReroute ? 'REROUTE' : (item.entryType || 'ADVISORY');
        const subject = isReroute ? (item.name || item.summary || 'Reroute') : (item.subject || advType);
        const effectiveTime = formatFaaDateTime(item.validFrom);
        const status = item.status || 'ACTIVE';

        const headerColor = isReroute ? 'bg-info' :
                           advType === 'HOTLINE' ? 'bg-danger' :
                           advType === 'SWAP' ? 'bg-warning text-dark' :
                           advType === 'OPSPLAN' ? 'bg-primary' : 'bg-secondary';

        const statusBadge = status === 'CANCELLED' ? '<span class="badge badge-secondary">CXLD</span>' :
                           status === 'SCHEDULED' ? '<span class="badge badge-info">SCHEDULED</span>' : '';

        // For reroutes, use advisoryText; for advisories use bodyText/rawText
        const bodyText = isReroute
            ? (item.advisoryText || item.routeString || 'No route details available.')
            : (item.bodyText || item.rawText || 'No details available.');

        // Build the header label
        const headerLabel = isReroute
            ? `REROUTE ${advNum !== '???' ? advNum : ''}`
            : `ADVZY ${escapeHtml(advNum)}`;

        // Build entity type for data attributes
        const entityType = isReroute ? 'REROUTE' : 'ADVISORY';

        // Additional info for reroutes (origin/dest, validity time)
        let validityInfo = '';
        if (isReroute) {
            const validUntil = formatFaaDateTime(item.validUntil);
            if (item.constrainedArea || item.originCenters || item.destCenters) {
                const scope = item.constrainedArea ||
                    [item.originCenters, item.destCenters].filter(Boolean).join(' → ');
                validityInfo = `<div class="small text-muted mb-2"><strong>Scope:</strong> ${escapeHtml(scope)} | <strong>Valid:</strong> ${effectiveTime} - ${validUntil}</div>`;
            }
        }

        const canSelect = !['CANCELLED', 'PURGED', 'EXPIRED'].includes(status);

        return `
            <div class="card advisory-card mb-2 ${status === 'CANCELLED' ? 'border-secondary' : ''}" data-id="${item.entityId}" data-type="${entityType}" data-subtype="${item.type || ''}">
                <div class="card-header ${headerColor} py-2 advisory-header" style="cursor: pointer;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            ${canSelect ? `<input type="checkbox" class="batch-select-advisory mr-2" data-id="${item.entityId}" data-type="${entityType}" data-subtype="${item.type || ''}" onclick="event.stopPropagation();">` : ''}
                            <span class="font-weight-bold">${headerLabel}</span>
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
                    ${validityInfo}
                    <pre class="advisory-text mb-2">${escapeHtml(bodyText)}</pre>
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            Created: ${formatFaaDateTime(item.createdAt)}
                            ${item.createdByName || item.createdBy ? ` by ${escapeHtml(item.createdByName || item.createdBy)}` : ''}
                        </small>
                        ${status !== 'CANCELLED' ? `
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-sm btn-outline-primary btn-edit-advisory" data-id="${item.entityId}" data-type="${entityType}" data-subtype="${item.type || ''}">
                                <i class="fas fa-edit mr-1"></i> Edit
                            </button>
                            <button class="btn btn-sm btn-outline-danger btn-cancel-advisory" data-id="${item.entityId}" data-type="${entityType}" data-subtype="${item.type || ''}">
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
        const previousDate = state.filters.date;

        state.filters.source = $('#filterSource').val() || 'PRODUCTION';
        state.filters.reqFacilities = $('#filterReqFac').val() || [];
        state.filters.provFacilities = $('#filterProvFac').val() || [];
        state.filters.types = $('#filterType').val() || [];
        state.filters.statuses = $('#filterStatus').val() || ['ACTIVE', 'SCHEDULED'];
        state.filters.date = $('#filterDate').val() || '';

        console.log('[TMI-Active] Applying filters:', state.filters);

        // Save filters to localStorage for persistence
        saveFilters();

        // If date changed, reload data from API (date changes what data we fetch)
        if (state.filters.date !== previousDate) {
            loadActiveTmis();
        } else {
            // Re-render with new filters (no need to reload data)
            renderDisplay();
        }
    }

    function resetFilters() {
        state.filters = {
            source: 'PRODUCTION',
            reqFacilities: [],
            provFacilities: [],
            types: [],
            statuses: ['ACTIVE', 'SCHEDULED'],
            date: ''
        };

        $('#filterSource').val('PRODUCTION');
        $('#filterDate').val('');
        if ($.fn.select2) {
            $('#filterReqFac').val([]).trigger('change.select2');
            $('#filterProvFac').val([]).trigger('change.select2');
            $('#filterType').val([]).trigger('change.select2');
            $('#filterStatus').val(['ACTIVE', 'SCHEDULED']).trigger('change.select2');
        }

        // Save reset filters to localStorage
        saveFilters();

        loadActiveTmis();
    }

    function getFilteredRestrictions() {
        let items = [];
        const statuses = state.filters.statuses || ['ACTIVE', 'SCHEDULED'];
        const showAll = statuses.length === 0;

        // Add based on status filter - includes ENTRY, PROGRAM (NOT REROUTE - shown in advisories)
        if (showAll || statuses.includes('ACTIVE')) {
            items = items.concat(state.restrictions);
            items = items.concat(state.programs);
            // Reroutes now show in advisories section, not here
        }
        if (showAll || statuses.includes('SCHEDULED')) {
            items = items.concat(state.scheduled.filter(i => i.entityType !== 'ADVISORY' && i.entityType !== 'REROUTE'));
        }
        if (showAll || statuses.includes('CANCELLED')) {
            items = items.concat(state.cancelled.filter(i => i.entityType !== 'ADVISORY' && i.entityType !== 'REROUTE'));
        }
        if (showAll || statuses.includes('EXPIRED')) {
            items = items.concat(state.expired.filter(i => i.entityType !== 'ADVISORY' && i.entityType !== 'REROUTE'));
        }

        // Apply requesting facility filter with hierarchy expansion
        if (state.filters.reqFacilities && state.filters.reqFacilities.length > 0) {
            const expandedReq = expandFacilitySelection(state.filters.reqFacilities);
            items = items.filter(i => {
                // For PROGRAM, check ctlElement or scopeCenters
                if (i.entityType === 'PROGRAM') {
                    const centers = (i.scopeCenters || '').toUpperCase().split(/[,\s]+/);
                    const ctlEl = (i.ctlElement || '').toUpperCase();
                    return centers.some(c => expandedReq.has(c)) || expandedReq.has(ctlEl);
                }
                // For REROUTE, check origin centers/airports
                if (i.entityType === 'REROUTE') {
                    const origins = (i.originCenters || '').toUpperCase().split(/[,\s]+/);
                    const originApts = (i.originAirports || '').toUpperCase().split(/[,\s]+/);
                    return origins.some(o => expandedReq.has(o)) || originApts.some(a => expandedReq.has(a));
                }
                // For ENTRY, check requesting facility
                const reqFac = (i.requestingFacility || '').toUpperCase();
                return expandedReq.has(reqFac);
            });
        }

        // Apply providing facility filter with hierarchy expansion
        if (state.filters.provFacilities && state.filters.provFacilities.length > 0) {
            const expandedProv = expandFacilitySelection(state.filters.provFacilities);
            const AIRPORT_TO_ARTCC = getAIRPORT_TO_ARTCC();
            items = items.filter(i => {
                // For PROGRAM, check ctlElement (the airport)
                if (i.entityType === 'PROGRAM') {
                    const ctlEl = (i.ctlElement || '').toUpperCase();
                    // Also check if the ARTCC for this airport is in the filter
                    const artcc = AIRPORT_TO_ARTCC[ctlEl] || '';
                    return expandedProv.has(ctlEl) || expandedProv.has(artcc);
                }
                // For REROUTE, check destination centers/airports
                if (i.entityType === 'REROUTE') {
                    const dests = (i.destCenters || '').toUpperCase().split(/[,\s]+/);
                    const destApts = (i.destAirports || '').toUpperCase().split(/[,\s]+/);
                    return dests.some(d => expandedProv.has(d)) || destApts.some(a => expandedProv.has(a));
                }
                // For ENTRY, check providing facility
                const provFac = (i.providingFacility || '').toUpperCase();
                return expandedProv.has(provFac);
            });
        }

        // Apply type filter (multi-select)
        const types = state.filters.types || [];
        if (types.length > 0) {
            const filterTypes = types.map(t => t.toUpperCase());
            items = items.filter(i => {
                const entryType = (i.entryType || '').toUpperCase();

                // Check each selected type
                for (const filterType of filterTypes) {
                    if (filterType === 'GDP' && i.entityType === 'PROGRAM' && entryType.includes('GDP')) return true;
                    if (filterType === 'STOP' && i.entityType === 'PROGRAM' && entryType === 'GS') return true;
                    if (filterType === 'GS' && i.entityType === 'PROGRAM' && entryType === 'GS') return true;
                    if (filterType === 'REROUTE' && i.entityType === 'REROUTE') return true;
                    if (entryType === filterType) return true;
                }
                return false;
            });
        }

        return items;
    }

    function getFilteredAdvisories() {
        let items = [];
        const statuses = state.filters.statuses || ['ACTIVE', 'SCHEDULED'];
        const showAll = statuses.length === 0;

        if (showAll || statuses.includes('ACTIVE')) {
            items = items.concat(state.advisories);  // Already includes REROUTE items
        }
        if (showAll || statuses.includes('SCHEDULED')) {
            // Include both ADVISORY and REROUTE scheduled items
            items = items.concat(state.scheduled.filter(i => i.entityType === 'ADVISORY' || i.entityType === 'REROUTE'));
        }
        if (showAll || statuses.includes('CANCELLED')) {
            // Include both ADVISORY and REROUTE cancelled items
            items = items.concat(state.cancelled.filter(i => i.entityType === 'ADVISORY' || i.entityType === 'REROUTE'));
        }
        if (showAll || statuses.includes('EXPIRED')) {
            // Include both ADVISORY and REROUTE expired items
            items = items.concat(state.expired.filter(i => i.entityType === 'ADVISORY' || i.entityType === 'REROUTE'));
        }

        return items;
    }

    // ===========================================
    // Actions
    // ===========================================
    
    function showRestrictionDetails(id, type) {
        // Find the item - also check programs and reroutes
        // Use type parameter to ensure we find the correct item (avoid ID collisions between entity types)
        const allItems = [...state.restrictions, ...state.programs, ...state.reroutes, ...state.advisories, ...state.scheduled, ...state.cancelled, ...state.expired];

        // Map display type to entity type
        const entityTypeMap = {
            'PROGRAM': 'PROGRAM',
            'REROUTE': 'REROUTE',
            'publicroute': 'REROUTE',
            'ENTRY': 'ENTRY',
            'ADVISORY': 'ADVISORY'
        };
        const expectedEntityType = entityTypeMap[type] || type;

        // First try to find by both ID and entity type for exact match
        let item = allItems.find(i => i.entityId === id && i.entityType === expectedEntityType);

        // Fallback to just ID match if type match fails
        if (!item) {
            item = allItems.find(i => i.entityId === id);
        }

        if (!item) {
            console.warn('[TMI-Active] Item not found:', id, type);
            return;
        }

        let detailHtml = '';
        let title = 'TMI Details';
        let editUrl = null;

        if (item.entityType === 'PROGRAM') {
            // GDP/GS Program details
            title = `${item.entryType || 'Program'} Details`;
            editUrl = 'gdt?edit=' + item.entityId;

            const scopeCenters = item.scopeCenters ?
                (typeof item.scopeCenters === 'string' ? JSON.parse(item.scopeCenters) : item.scopeCenters).join(', ') : '-';

            detailHtml = `
                <div class="restriction-detail">
                    <table class="table table-sm table-borderless">
                        <tr><th width="140">Program Type:</th><td><span class="badge badge-${item.entryType === 'GS' ? 'danger' : 'warning'}">${escapeHtml(item.entryType || 'PROGRAM')}</span></td></tr>
                        <tr><th>Airport:</th><td class="font-weight-bold">${escapeHtml(item.ctlElement || '-')}</td></tr>
                        <tr><th>Program Rate:</th><td>${item.programRate ? item.programRate + '/hr' : '-'}</td></tr>
                        <tr><th>Scope Type:</th><td>${escapeHtml(item.scopeType || '-')}</td></tr>
                        <tr><th>Scope Centers:</th><td>${escapeHtml(scopeCenters)}</td></tr>
                        <tr><th>Cause:</th><td>${escapeHtml(item.impactingCondition || '-')}</td></tr>
                        <tr><th>Cause Detail:</th><td>${escapeHtml(item.causeText || '-')}</td></tr>
                        <tr><th>Total Flights:</th><td>${item.totalFlights || '-'}</td></tr>
                        <tr><th>Controlled:</th><td>${item.controlledFlights || '-'}</td></tr>
                        <tr><th>Exempt:</th><td>${item.exemptFlights || '-'}</td></tr>
                        <tr><th>Start Time:</th><td>${formatFaaDateTime(item.validFrom)}</td></tr>
                        <tr><th>End Time:</th><td>${formatFaaDateTime(item.validUntil)}</td></tr>
                        <tr><th>Status:</th><td><span class="badge badge-${item.status === 'ACTIVE' ? 'success' : item.status === 'CANCELLED' || item.status === 'COMPLETED' ? 'secondary' : 'info'}">${item.status || 'UNKNOWN'}</span></td></tr>
                        <tr><th>Created:</th><td>${formatFaaDateTime(item.createdAt)} ${item.createdBy ? 'by ' + escapeHtml(item.createdBy) : ''}</td></tr>
                    </table>
                    ${item.comments ? `
                    <hr>
                    <div class="small text-muted mb-1">Comments:</div>
                    <div class="bg-light p-2 small">${escapeHtml(item.comments)}</div>
                    ` : ''}
                </div>
            `;
        } else if (item.entityType === 'REROUTE') {
            // Reroute details
            title = 'Reroute Details';
            editUrl = 'route?edit=' + item.entityId;

            // Link to view route on map (type=publicroute means it's from tmi_public_routes)
            const viewMapUrl = item.type === 'publicroute'
                ? `route?view=${item.entityId}`
                : `route?view_reroute=${item.entityId}`;

            detailHtml = `
                <div class="restriction-detail">
                    <div class="text-center mb-3">
                        <a href="${viewMapUrl}" target="_blank" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-map-marked-alt mr-1"></i> View Route on Map
                        </a>
                    </div>
                    <table class="table table-sm table-borderless">
                        <tr><th width="140">Name:</th><td class="font-weight-bold">${escapeHtml(item.name || '-')}</td></tr>
                        <tr><th>Advisory #:</th><td>${escapeHtml(item.advisoryNumber || '-')}</td></tr>
                        <tr><th>Origin Centers:</th><td>${escapeHtml(item.originCenters || '-')}</td></tr>
                        <tr><th>Origin Airports:</th><td>${escapeHtml(item.originAirports || '-')}</td></tr>
                        <tr><th>Dest Centers:</th><td>${escapeHtml(item.destCenters || '-')}</td></tr>
                        <tr><th>Dest Airports:</th><td>${escapeHtml(item.destAirports || '-')}</td></tr>
                        <tr><th>Constrained Area:</th><td>${escapeHtml(item.constrainedArea || '-')}</td></tr>
                        <tr><th>Protected Segment:</th><td>${escapeHtml(item.protectedSegment || item.protectedFixes || '-')}</td></tr>
                        <tr><th>Avoid Fixes:</th><td>${escapeHtml(item.avoidFixes || '-')}</td></tr>
                        <tr><th>Cause:</th><td>${escapeHtml(item.impactingCondition || '-')}</td></tr>
                        <tr><th>Start Time:</th><td>${formatFaaDateTime(item.validFrom)}</td></tr>
                        <tr><th>End Time:</th><td>${formatFaaDateTime(item.validUntil)}</td></tr>
                        <tr><th>Status:</th><td><span class="badge badge-${item.status === 'ACTIVE' ? 'success' : item.status === 'CANCELLED' || item.status === 'EXPIRED' ? 'secondary' : 'info'}">${item.status || 'UNKNOWN'}</span></td></tr>
                        <tr><th>Created:</th><td>${formatFaaDateTime(item.createdAt)} ${item.createdBy ? 'by ' + escapeHtml(item.createdBy) : ''}</td></tr>
                    </table>
                    ${item.routeString ? `
                    <hr>
                    <div class="small text-muted mb-1">Route String:</div>
                    <pre class="bg-light p-2 small" style="white-space: pre-wrap;">${escapeHtml(item.routeString)}</pre>
                    ` : ''}
                    ${item.advisoryText ? `
                    <hr>
                    <div class="small text-muted mb-1">Advisory Text:</div>
                    <pre class="bg-light p-2 small" style="white-space: pre-wrap;">${escapeHtml(item.advisoryText)}</pre>
                    ` : ''}
                    ${item.comments ? `
                    <hr>
                    <div class="small text-muted mb-1">Comments:</div>
                    <div class="bg-light p-2 small">${escapeHtml(item.comments)}</div>
                    ` : ''}
                </div>
            `;
        } else {
            // NTML Entry details
            detailHtml = `
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
        }

        const isActive = !['CANCELLED', 'PURGED', 'COMPLETED', 'EXPIRED'].includes(item.status);

        Swal.fire({
            title: `<i class="fas fa-info-circle text-primary"></i> ${title}`,
            html: detailHtml,
            width: 650,
            showCancelButton: isActive,
            showDenyButton: isActive && editUrl,
            confirmButtonText: 'Close',
            denyButtonText: '<i class="fas fa-edit"></i> Edit',
            denyButtonColor: '#007bff',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel',
            cancelButtonColor: '#dc3545'
        }).then((result) => {
            if (result.isDenied && editUrl) {
                window.location.href = editUrl;
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                cancelTmi(id, type);
            }
        });
    }

    function cancelTmi(id, type, subtype) {
        // Check if this is a GS/GDP program - use specialized modal
        if (type === 'PROGRAM' && (subtype === 'GS' || subtype === 'GDP' || subtype?.indexOf('GDP') !== -1)) {
            // Find the program to get its details
            const program = state.programs.find(p => p.entityId == id) ||
                           state.scheduled.find(p => p.entityId == id && p.entityType === 'PROGRAM');

            if (program && window.TMI_GSGDP?.openCancelModal) {
                window.TMI_GSGDP.openCancelModal(
                    program.programId || id,
                    subtype || program.type || 'GS',
                    program.ctlElement || program.element || 'UNKN'
                );
                return;
            }
        }

        // Default cancellation dialog for other TMI types
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
                performCancel(id, type, result.value, subtype);
            }
        });
    }

    function performCancel(id, type, reason, subtype) {
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
                userName: userName,
                type: subtype || null  // Pass subtype to distinguish publicroute vs reroute
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

    function cancelAdvisory(id, type, subtype) {
        // For reroutes, use REROUTE type; otherwise use ADVISORY
        cancelTmi(id, type || 'ADVISORY', subtype);
    }

    // ===========================================
    // Edit Functions
    // ===========================================

    function editTmi(id, type) {
        // Route to appropriate edit page based on type
        if (type === 'PROGRAM') {
            // Redirect to GDT page for GDP/GS editing
            window.location.href = 'gdt?edit=' + id;
            return;
        }
        if (type === 'REROUTE') {
            // Redirect to route page for reroute editing
            window.location.href = 'route?edit=' + id;
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

    function editAdvisory(id, type) {
        // For reroutes, redirect to route page
        if (type === 'REROUTE') {
            window.location.href = 'route?edit=' + id;
            return;
        }

        // Find the advisory in state
        const allItems = [...state.advisories.filter(i => i.entityType === 'ADVISORY'), ...state.scheduled.filter(i => i.entityType === 'ADVISORY')];
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

        // Build type-specific form HTML
        let typeSpecificHtml = '';
        let preConfirmFn = null;

        switch (advType) {
            case 'HOTLINE':
                typeSpecificHtml = buildHotlineEditForm(item, formatForInput);
                preConfirmFn = getHotlinePreConfirm;
                break;
            case 'OPS_PLAN':
            case 'OPSPLAN':
                typeSpecificHtml = buildOpsPlanEditForm(item, formatForInput);
                preConfirmFn = getOpsPlanPreConfirm;
                break;
            case 'GDP':
            case 'GS':
            case 'STOP':
                typeSpecificHtml = buildProgramEditForm(item, formatForInput, advType);
                preConfirmFn = getProgramPreConfirm;
                break;
            default:
                // FREEFORM or other
                typeSpecificHtml = buildFreeformEditForm(item, formatForInput);
                preConfirmFn = getFreeformPreConfirm;
        }

        Swal.fire({
            title: `<i class="fas fa-edit text-primary"></i> Edit ${advType === 'HOTLINE' ? 'Hotline' : advType === 'OPS_PLAN' || advType === 'OPSPLAN' ? 'Ops Plan' : 'Advisory'}`,
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
                    ${typeSpecificHtml}
                </div>
            `,
            width: 650,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-save"></i> Save Changes',
            confirmButtonColor: '#28a745',
            cancelButtonText: 'Cancel',
            preConfirm: preConfirmFn
        }).then((result) => {
            if (result.isConfirmed) {
                performEdit(id, 'ADVISORY', result.value);
            }
        });
    }

    // =========================================
    // Type-specific edit form builders
    // =========================================

    function buildHotlineEditForm(item, formatForInput) {
        const hotlineNames = ['NY Metro', 'DC Metro', 'Chicago', 'Atlanta', 'Florida', 'Texas',
                            'East Coast', 'West Coast', 'Canada East', 'Canada West', 'Mexico', 'Caribbean'];
        const participationOptions = ['MANDATORY', 'EXPECTED', 'STRONGLY ENCOURAGED', 'STRONGLY RECOMMENDED',
                                     'ENCOURAGED', 'RECOMMENDED', 'OPTIONAL'];
        const hotlineActions = ['ACTIVATION', 'UPDATE', 'TERMINATION'];

        const hotlineNameOpts = hotlineNames.map(n =>
            `<option value="${n}" ${item.hotlineName === n ? 'selected' : ''}>${n}</option>`
        ).join('');

        const participationOpts = participationOptions.map(p =>
            `<option value="${p}" ${item.participation === p ? 'selected' : ''}>${p}</option>`
        ).join('');

        const actionOpts = hotlineActions.map(a =>
            `<option value="${a}" ${item.hotlineAction === a ? 'selected' : ''}>${a}</option>`
        ).join('');

        return `
            <div class="row mb-2">
                <div class="col-6">
                    <label class="small font-weight-bold">Action</label>
                    <select id="editHotlineAction" class="form-control form-control-sm">
                        ${actionOpts}
                    </select>
                </div>
                <div class="col-6">
                    <label class="small font-weight-bold">Hotline Name</label>
                    <select id="editHotlineName" class="form-control form-control-sm">
                        ${hotlineNameOpts}
                    </select>
                </div>
            </div>
            <div class="row mb-2">
                <div class="col-6">
                    <label class="small font-weight-bold">Start Date/Time (UTC)</label>
                    <input type="datetime-local" id="editValidFrom" class="form-control form-control-sm" value="${formatForInput(item.validFrom)}">
                </div>
                <div class="col-6">
                    <label class="small font-weight-bold">End Date/Time (UTC)</label>
                    <input type="datetime-local" id="editValidUntil" class="form-control form-control-sm" value="${formatForInput(item.validUntil)}">
                </div>
            </div>
            <div class="row mb-2">
                <div class="col-6">
                    <label class="small font-weight-bold">Constrained Facilities</label>
                    <input type="text" id="editConstrainedFacilities" class="form-control form-control-sm text-uppercase"
                           value="${escapeHtml(item.constrainedFacilities || item.scopeFacilities || '')}" placeholder="e.g., ZNY, ZBW, ZDC">
                </div>
                <div class="col-6">
                    <label class="small font-weight-bold">Attending Facilities</label>
                    <input type="text" id="editAttendingFacilities" class="form-control form-control-sm text-uppercase"
                           value="${escapeHtml(item.attendingFacilities || '')}" placeholder="e.g., ZNY, ZBW, ZDC, ZOB">
                </div>
            </div>
            <div class="row mb-2">
                <div class="col-6">
                    <label class="small font-weight-bold">Participation</label>
                    <select id="editParticipation" class="form-control form-control-sm">
                        ${participationOpts}
                    </select>
                </div>
                <div class="col-6">
                    <label class="small font-weight-bold">Impacting Condition</label>
                    <select id="editReasonCode" class="form-control form-control-sm">
                        <option value="">-- Select --</option>
                        <option value="WEATHER" ${item.reasonCode === 'WEATHER' ? 'selected' : ''}>Weather</option>
                        <option value="VOLUME" ${item.reasonCode === 'VOLUME' ? 'selected' : ''}>Volume</option>
                        <option value="EQUIPMENT" ${item.reasonCode === 'EQUIPMENT' ? 'selected' : ''}>Equipment</option>
                        <option value="STAFFING" ${item.reasonCode === 'STAFFING' ? 'selected' : ''}>Staffing</option>
                        <option value="RUNWAY CONSTRUCTION" ${item.reasonCode === 'RUNWAY CONSTRUCTION' ? 'selected' : ''}>Runway Construction</option>
                        <option value="SPECIAL EVENT" ${item.reasonCode === 'SPECIAL EVENT' ? 'selected' : ''}>Special Event</option>
                        <option value="OTHER" ${item.reasonCode === 'OTHER' ? 'selected' : ''}>Other</option>
                    </select>
                </div>
            </div>
            <div class="row mb-2">
                <div class="col-6">
                    <label class="small font-weight-bold">Location of Impact</label>
                    <input type="text" id="editImpactedArea" class="form-control form-control-sm"
                           value="${escapeHtml(item.impactedArea || item.reasonDetail || '')}" placeholder="e.g., NY Metro, EWR/JFK/LGA arrivals">
                </div>
                <div class="col-6">
                    <label class="small font-weight-bold">Hotline Address</label>
                    <select id="editHotlineAddress" class="form-control form-control-sm">
                        <option value="ts.vatusa.net" ${item.hotlineAddress === 'ts.vatusa.net' ? 'selected' : ''}>VATUSA TeamSpeak (ts.vatusa.net)</option>
                        <option value="ts.vatcan.ca" ${item.hotlineAddress === 'ts.vatcan.ca' ? 'selected' : ''}>VATCAN TeamSpeak (ts.vatcan.ca)</option>
                        <option value="discord" ${item.hotlineAddress === 'discord' ? 'selected' : ''}>vATCSCC Discord, Hotline Backup</option>
                    </select>
                </div>
            </div>
            <div class="form-group mb-2">
                <label class="small font-weight-bold">Additional Remarks</label>
                <textarea id="editNotes" class="form-control form-control-sm" rows="2">${escapeHtml(item.notes || item.bodyText || '')}</textarea>
            </div>
        `;
    }

    function getHotlinePreConfirm() {
        return {
            validFrom: document.getElementById('editValidFrom').value,
            validUntil: document.getElementById('editValidUntil').value,
            hotlineAction: document.getElementById('editHotlineAction').value,
            hotlineName: document.getElementById('editHotlineName').value,
            constrainedFacilities: document.getElementById('editConstrainedFacilities').value,
            attendingFacilities: document.getElementById('editAttendingFacilities').value,
            participation: document.getElementById('editParticipation').value,
            reasonCode: document.getElementById('editReasonCode').value,
            impactedArea: document.getElementById('editImpactedArea').value,
            hotlineAddress: document.getElementById('editHotlineAddress').value,
            notes: document.getElementById('editNotes').value
        };
    }

    function buildOpsPlanEditForm(item, formatForInput) {
        return `
            <div class="row mb-2">
                <div class="col-6">
                    <label class="small font-weight-bold">Facility</label>
                    <input type="text" id="editFacility" class="form-control form-control-sm text-uppercase"
                           value="${escapeHtml(item.facility || 'DCC')}">
                </div>
                <div class="col-6">
                    <label class="small font-weight-bold">Subject</label>
                    <input type="text" id="editSubject" class="form-control form-control-sm"
                           value="${escapeHtml(item.subject || '')}">
                </div>
            </div>
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
                <label class="small font-weight-bold">Key Initiatives</label>
                <textarea id="editInitiatives" class="form-control form-control-sm" rows="4"
                          placeholder="List key TMIs and initiatives...">${escapeHtml(item.initiatives || item.bodyText || '')}</textarea>
            </div>
            <div class="form-group mb-2">
                <label class="small font-weight-bold">Terminal/Enroute Constraints</label>
                <textarea id="editConstraints" class="form-control form-control-sm" rows="2"
                          placeholder="Weather impacts and constraints...">${escapeHtml(item.constraints || item.weatherImpacts || '')}</textarea>
            </div>
            <div class="form-group mb-2">
                <label class="small font-weight-bold">Special Events</label>
                <textarea id="editEvents" class="form-control form-control-sm" rows="2"
                          placeholder="Special events affecting traffic...">${escapeHtml(item.specialEvents || '')}</textarea>
            </div>
        `;
    }

    function getOpsPlanPreConfirm() {
        return {
            validFrom: document.getElementById('editValidFrom').value,
            validUntil: document.getElementById('editValidUntil').value,
            facility: document.getElementById('editFacility').value,
            subject: document.getElementById('editSubject').value,
            initiatives: document.getElementById('editInitiatives').value,
            constraints: document.getElementById('editConstraints').value,
            specialEvents: document.getElementById('editEvents').value
        };
    }

    function buildProgramEditForm(item, formatForInput, advType) {
        return `
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
                <textarea id="editBodyText" class="form-control form-control-sm" rows="4" style="font-family: monospace; font-size: 11px;">${escapeHtml(item.bodyText || item.rawText || '')}</textarea>
            </div>
        `;
    }

    function getProgramPreConfirm() {
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

    function buildFreeformEditForm(item, formatForInput) {
        return `
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
        `;
    }

    function getFreeformPreConfirm() {
        return {
            validFrom: document.getElementById('editValidFrom').value,
            validUntil: document.getElementById('editValidUntil').value,
            subject: document.getElementById('editSubject').value,
            ctlElement: document.getElementById('editCtlElement').value,
            scopeFacilities: document.getElementById('editScopeFac').value,
            reasonCode: document.getElementById('editReasonCode').value,
            reasonDetail: document.getElementById('editReasonDetail').value,
            bodyText: document.getElementById('editBodyText').value
        };
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
