/**
 * public-routes.js
 * vATCSCC PERTI - Public Routes Management Module
 * Handles fetching, displaying, and managing globally shared routes
 */

window.PublicRoutes = (function() {
    'use strict';

    // ─────────────────────────────────────────────────────────────────────
    // STATE
    // ─────────────────────────────────────────────────────────────────────

    const state = {
        enabled: false,
        routes: [],
        refreshInterval: null,
        refreshRateMs: 30000,  // Refresh every 30 seconds
        lastRefresh: null,
        // Map layer references (set by integration code)
        layer: null,
        layerVisible: true,
        // Editing state
        editingRouteId: null,  // Track if we're editing an existing route
        editingRoute: null,    // The full route object being edited
        // Visibility toggles for each status type
        showActive: true,
        showFuture: true,
        showPast: false,       // Past routes hidden by default
        // Individual route visibility (Set of hidden route IDs)
        hiddenRouteIds: new Set(),
        // Track if initial auto-hide has been done
        initialAutoHideDone: false,
        // API key for write operations (set via setApiKey or SWIM_PUBLIC_ROUTES_KEY global)
        apiKey: null
    };

    // Route colors for new routes (will cycle through)
    const ROUTE_COLORS = [
        '#e74c3c', '#3498db', '#2ecc71', '#9b59b6', '#f39c12',
        '#1abc9c', '#e91e63', '#00bcd4', '#ff5722', '#607d8b',
        '#8bc34a', '#ff9800', '#673ab7', '#03a9f4', '#cddc39'
    ];

    // ─────────────────────────────────────────────────────────────────────
    // API FUNCTIONS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Check if a route is more than N days past its end time
     */
    function isOldPastRoute(route, daysThreshold) {
        if (!route.valid_end_utc) return false;
        const endTime = new Date(route.valid_end_utc);
        const now = new Date();
        const diffMs = now - endTime;
        const diffDays = diffMs / (1000 * 60 * 60 * 24);
        return diffDays > daysThreshold;
    }
    
    /**
     * Fetch all public routes from the API
     * Visibility is controlled client-side via toggles
     */
    function fetchRoutes() {
        console.log('[PublicRoutes] Fetching all routes...');
        
        // Store previous data for buffered update
        var previousRoutes = state.routes ? state.routes.slice() : [];
        
        return $.ajax({
            url: 'api/swim/v1/tmi/routes',
            method: 'GET',
            data: { filter: 'all' },
            dataType: 'json'
        }).done(function(data) {
            if (data.success) {
                // Support both 'routes' (legacy) and 'data' (VATSWIM) response formats
                var newRoutes = data.routes || data.data || [];

                // Always update from API response - empty is valid (all routes deleted/expired)
                // Client-side filtering handles visibility by time status
                state.routes = newRoutes;

                state.lastRefresh = new Date();
                console.log('[PublicRoutes] Loaded', state.routes.length, 'routes');
                
                // Auto-hide past routes more than 2 days old (on first load only)
                if (!state.initialAutoHideDone) {
                    state.routes.forEach(function(route) {
                        const status = route.computed_status || getTimeStatus(route);
                        if (status === 'past' && isOldPastRoute(route, 2)) {
                            state.hiddenRouteIds.add(route.id);
                        }
                    });
                    state.initialAutoHideDone = true;
                }
                
                // Trigger update event
                $(document).trigger('publicRoutes:updated', [state.routes]);
                
                updateStatusIndicator();
                updatePanel();
                
                if (state.enabled && state.layerVisible) {
                    renderRoutes();
                }
            } else {
                console.error('[PublicRoutes] API error:', data.error);
                // BUFFERED: Keep previous data on API error
            }
        }).fail(function(xhr, status, err) {
            console.error('[PublicRoutes] Fetch failed:', status, err);
            // BUFFERED: Keep previous data on network error
            console.log('[PublicRoutes] Keeping previous data due to error (' + previousRoutes.length + ' routes)');
        });
    }
    
    /**
     * Get routes filtered by current visibility settings
     * Note: Time status is computed in real-time via getTimeStatus(), so expired routes
     * automatically get status='past' and are filtered out when showPast=false (default)
     */
    function getVisibleRoutes() {
        return state.routes.filter(function(route) {
            // Check if individually hidden
            if (state.hiddenRouteIds.has(route.id)) return false;

            // Real-time time status check - getTimeStatus compares valid_end_utc against now
            const status = route.computed_status || getTimeStatus(route);
            if (status === 'active' && state.showActive) return true;
            if (status === 'future' && state.showFuture) return true;
            if (status === 'past' && state.showPast) return true;
            return false;
        });
    }
    
    /**
     * Get routes filtered by category only (for panel display)
     * This includes individually hidden routes so they can be shown in the list
     */
    function getCategoryFilteredRoutes() {
        return state.routes.filter(function(route) {
            const status = route.computed_status || getTimeStatus(route);
            if (status === 'active' && state.showActive) return true;
            if (status === 'future' && state.showFuture) return true;
            if (status === 'past' && state.showPast) return true;
            return false;
        });
    }
    
    /**
     * Toggle visibility of a single route
     */
    function toggleRouteVisibility(routeId) {
        if (state.hiddenRouteIds.has(routeId)) {
            state.hiddenRouteIds.delete(routeId);
        } else {
            state.hiddenRouteIds.add(routeId);
        }
        updatePanel();  // Full re-render for collapsed/expanded layout change
        updateStatusIndicator();
        renderRoutes();
    }
    
    /**
     * Show a single route (remove from hidden)
     */
    function showRoute(routeId) {
        state.hiddenRouteIds.delete(routeId);
        updatePanel();
        updateStatusIndicator();
        renderRoutes();
    }
    
    /**
     * Hide a single route (add to hidden)
     */
    function hideRoute(routeId) {
        state.hiddenRouteIds.add(routeId);
        updatePanel();
        updateStatusIndicator();
        renderRoutes();
    }
    
    /**
     * Show all individually hidden routes
     */
    function showAllRoutes() {
        state.hiddenRouteIds.clear();
        updatePanel();
        renderRoutes();
    }

    /**
     * Get the API key for write operations
     */
    function getApiKey() {
        return state.apiKey || window.SWIM_PUBLIC_ROUTES_KEY || null;
    }

    /**
     * Create or update a public route
     */
    function saveRoute(routeData) {
        console.log('[PublicRoutes] Saving route:', routeData.name);
        console.log('[PublicRoutes] Route data keys:', Object.keys(routeData));
        console.log('[PublicRoutes] route_geojson present:', !!routeData.route_geojson);
        if (routeData.route_geojson) {
            console.log('[PublicRoutes] route_geojson length:', routeData.route_geojson.length);
        }

        const apiKey = getApiKey();
        if (!apiKey) {
            console.error('[PublicRoutes] No API key configured for write operations');
            alert('API key not configured. Contact administrator.');
            return $.Deferred().reject('No API key');
        }

        return $.ajax({
            url: 'api/swim/v1/tmi/routes',
            method: 'POST',
            contentType: 'application/json',
            headers: { 'X-API-Key': apiKey },
            data: JSON.stringify(routeData),
            dataType: 'json'
        }).done(function(data) {
            if (data.success) {
                console.log('[PublicRoutes] Route saved:', data.data || data.route);
                fetchRoutes();  // Refresh the list
                return data.route;
            } else {
                console.error('[PublicRoutes] Save error:', data.error);
                alert('Failed to save route: ' + data.error);
            }
        }).fail(function(xhr, status, err) {
            console.error('[PublicRoutes] Save failed:', status, err);
            alert('Failed to save route: ' + err);
        });
    }

    /**
     * Delete a public route
     */
    function deleteRoute(routeId, hard = false) {
        if (!confirm('Are you sure you want to ' + (hard ? 'permanently delete' : 'deactivate') + ' this route?')) {
            return $.Deferred().reject();
        }

        const apiKey = getApiKey();
        if (!apiKey) {
            console.error('[PublicRoutes] No API key configured for write operations');
            alert('API key not configured. Contact administrator.');
            return $.Deferred().reject('No API key');
        }

        console.log('[PublicRoutes] Deleting route:', routeId);

        return $.ajax({
            url: 'api/swim/v1/tmi/routes?id=' + routeId + (hard ? '&hard=1' : ''),
            method: 'DELETE',
            headers: { 'X-API-Key': apiKey },
            dataType: 'json'
        }).done(function(data) {
            if (data.success) {
                console.log('[PublicRoutes] Route deleted:', data.data || data.message);
                fetchRoutes();  // Refresh the list
            } else {
                console.error('[PublicRoutes] Delete error:', data.error);
                alert('Failed to delete route: ' + data.error);
            }
        }).fail(function(xhr, status, err) {
            console.error('[PublicRoutes] Delete failed:', status, err);
            alert('Failed to delete route: ' + err);
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // UI FUNCTIONS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Update the status indicator in the toolbar
     */
    function updateStatusIndicator() {
        const $indicator = $('#public_routes_indicator');
        if (!$indicator.length) return;
        
        // Count routes by status
        const counts = { active: 0, future: 0, past: 0 };
        state.routes.forEach(r => {
            const status = r.computed_status || getTimeStatus(r);
            if (counts[status] !== undefined) counts[status]++;
        });
        
        // Count visible routes (on map) and individually hidden
        const visibleRoutes = getVisibleRoutes();
        const visibleCount = visibleRoutes.length;
        const totalCount = state.routes.length;
        const individuallyHiddenCount = state.hiddenRouteIds.size;
        
        if (totalCount > 0 && state.enabled) {
            $indicator.removeClass('d-none').addClass('d-inline-flex');
            
            // Show visible/total in badge
            let badgeText = visibleCount.toString();
            if (visibleCount < totalCount) {
                badgeText = visibleCount + '/' + totalCount;
            }
            
            let title = visibleCount + ' route(s) on map';
            if (visibleCount < totalCount) {
                title += ' of ' + totalCount + ' total';
            }
            title += '\n\nCategories:\n';
            if (state.showActive) title += '  ✓ Active: ' + counts.active + '\n';
            else title += '  ○ Active: ' + counts.active + ' (hidden)\n';
            if (state.showFuture) title += '  ✓ Future: ' + counts.future + '\n';
            else title += '  ○ Future: ' + counts.future + ' (hidden)\n';
            if (state.showPast) title += '  ✓ Past: ' + counts.past;
            else title += '  ○ Past: ' + counts.past + ' (hidden)';
            
            if (individuallyHiddenCount > 0) {
                title += '\n\n' + individuallyHiddenCount + ' route(s) individually hidden';
            }
            
            $indicator.find('.badge').text(badgeText);
            $indicator.attr('title', title);
        } else {
            $indicator.addClass('d-none').removeClass('d-inline-flex');
        }
    }

    /**
     * Update the public routes panel
     */
    function updatePanel() {
        const $list = $('#public_routes_list');
        if (!$list.length) return;
        
        $list.empty();
        
        // Count routes by status
        const counts = { active: 0, future: 0, past: 0 };
        state.routes.forEach(function(route) {
            const status = route.computed_status || getTimeStatus(route);
            if (counts[status] !== undefined) counts[status]++;
        });
        
        // Add visibility toggle controls if not present (use ID to prevent duplicates)
        let $filterControls = $('#pr_filter_controls');
        if (!$filterControls.length) {
            $filterControls = $(`
                <div id="pr_filter_controls" class="pr-filter-controls p-2 border-bottom">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-muted font-weight-bold">Show:</span>
                        <div class="btn-group btn-group-xs">
                            <button class="btn btn-xs btn-outline-secondary pr-show-all" title="Show all categories">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-xs btn-outline-secondary pr-hide-all" title="Hide all categories">
                                <i class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap" style="gap: 8px;">
                        <label class="pr-toggle-label mb-0" title="Show currently active routes">
                            <input type="checkbox" class="pr-visibility-toggle" data-status="active" ${state.showActive ? 'checked' : ''}>
                            <span class="badge badge-success"><i class="fas fa-play-circle mr-1"></i>Active <span class="pr-count-active">(${counts.active})</span></span>
                        </label>
                        <label class="pr-toggle-label mb-0" title="Show scheduled future routes">
                            <input type="checkbox" class="pr-visibility-toggle" data-status="future" ${state.showFuture ? 'checked' : ''}>
                            <span class="badge badge-info"><i class="fas fa-calendar-alt mr-1"></i>Future <span class="pr-count-future">(${counts.future})</span></span>
                        </label>
                        <label class="pr-toggle-label mb-0" title="Show expired routes">
                            <input type="checkbox" class="pr-visibility-toggle" data-status="past" ${state.showPast ? 'checked' : ''}>
                            <span class="badge badge-secondary"><i class="fas fa-history mr-1"></i>Past <span class="pr-count-past">(${counts.past})</span></span>
                        </label>
                    </div>
                </div>
            `);
            $list.before($filterControls);
            
            // Bind visibility toggle events
            $filterControls.on('change', '.pr-visibility-toggle', function() {
                const status = $(this).data('status');
                const isChecked = $(this).prop('checked');
                
                if (status === 'active') state.showActive = isChecked;
                else if (status === 'future') state.showFuture = isChecked;
                else if (status === 'past') state.showPast = isChecked;
                
                updatePanelRouteVisibility();
                updateStatusIndicator();
                renderRoutes();
            });
            
            // Show all button - shows all categories AND clears individual hidden routes
            $filterControls.on('click', '.pr-show-all', function() {
                state.showActive = true;
                state.showFuture = true;
                state.showPast = true;
                state.hiddenRouteIds.clear();  // Also clear individual hidden routes
                $filterControls.find('.pr-visibility-toggle').prop('checked', true);
                updatePanel();  // Full refresh to update individual toggle buttons
                renderRoutes();
            });
            
            // Hide all button - hides all categories
            $filterControls.on('click', '.pr-hide-all', function() {
                state.showActive = false;
                state.showFuture = false;
                state.showPast = false;
                $filterControls.find('.pr-visibility-toggle').prop('checked', false);
                updatePanelRouteVisibility();
                updateStatusIndicator();
                renderRoutes();
            });
        } else {
            // Update counts
            $filterControls.find('.pr-count-active').text('(' + counts.active + ')');
            $filterControls.find('.pr-count-future').text('(' + counts.future + ')');
            $filterControls.find('.pr-count-past').text('(' + counts.past + ')');
            
            // Update checkbox states
            $filterControls.find('.pr-visibility-toggle[data-status="active"]').prop('checked', state.showActive);
            $filterControls.find('.pr-visibility-toggle[data-status="future"]').prop('checked', state.showFuture);
            $filterControls.find('.pr-visibility-toggle[data-status="past"]').prop('checked', state.showPast);
        }
        
        // Get category-filtered routes for panel (includes individually hidden routes)
        const categoryRoutes = getCategoryFilteredRoutes();
        const hiddenCount = state.hiddenRouteIds.size;
        
        if (categoryRoutes.length === 0) {
            const totalRoutes = state.routes.length;
            let emptyMessage = 'No public routes';
            if (totalRoutes > 0) {
                emptyMessage = totalRoutes + ' route(s) hidden by category filters';
            }
            $list.html('<div class="text-muted text-center py-3"><i class="fas fa-route mr-2"></i>' + emptyMessage + '</div>');
            return;
        }
        
        categoryRoutes.forEach(function(route) {
            const validStart = formatTime(route.valid_start_utc);
            const validEnd = formatTime(route.valid_end_utc);
            const timeStatus = route.time_status || getTimeStatus(route);
            const timeInfo = getTimeStatusInfo(route);
            const isHidden = state.hiddenRouteIds.has(route.id);
            
            // Determine item styling based on time status and visibility
            let itemClass = 'public-route-item';
            if (isHidden) itemClass += ' pr-individually-hidden pr-collapsed';
            let statusBadge = '';
            if (timeStatus === 'future') {
                itemClass += ' pr-future';
                statusBadge = '<span class="badge badge-info badge-sm mr-1" title="Starts in the future"><i class="fas fa-calendar-alt"></i></span>';
            } else if (timeStatus === 'past') {
                itemClass += ' pr-past';
                statusBadge = '<span class="badge badge-secondary badge-sm mr-1" title="Expired"><i class="fas fa-history"></i></span>';
            } else if (timeStatus === 'active') {
                itemClass += ' pr-active';
            }
            
            // Visibility toggle button
            const visToggleIcon = isHidden ? 'fa-eye-slash' : 'fa-eye';
            const visToggleClass = isHidden ? 'btn-outline-warning' : 'btn-outline-secondary';
            const visToggleTitle = isHidden ? 'Show on map' : 'Hide from map';
            
            // Build item - collapsed view for hidden routes, full view for visible
            let $item;
            if (isHidden) {
                // Collapsed single-line view for hidden routes
                $item = $(`
                    <div class="${itemClass}" data-route-id="${route.id}" data-time-status="${timeStatus}" data-hidden="${isHidden}">
                        <div class="d-flex align-items-center">
                            <button class="btn btn-xs ${visToggleClass} route-visibility-toggle mr-2 flex-shrink-0" title="${visToggleTitle}" style="padding: 2px 5px;">
                                <i class="fas ${visToggleIcon}"></i>
                            </button>
                            <div class="route-color-indicator mr-2" style="background-color: ${route.color}; width: 8px; height: 8px;"></div>
                            <span class="route-name text-truncate mr-2" style="max-width: 120px; font-size: 0.75rem;">${statusBadge}${escapeHtml(route.name)}</span>
                            <span class="text-muted ml-auto" style="font-size: 0.65rem;">${timeInfo.text}</span>
                        </div>
                    </div>
                `);
            } else {
                // Full view for visible routes
                $item = $(`
                    <div class="${itemClass}" data-route-id="${route.id}" data-time-status="${timeStatus}" data-hidden="${isHidden}">
                        <div class="d-flex align-items-start">
                            <button class="btn btn-xs ${visToggleClass} route-visibility-toggle mr-2 flex-shrink-0" title="${visToggleTitle}" style="padding: 2px 5px; margin-top: 2px;">
                                <i class="fas ${visToggleIcon}"></i>
                            </button>
                            <div class="route-color-indicator mr-2" style="background-color: ${route.color};"></div>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="route-name text-truncate" style="max-width: 130px;">${statusBadge}${escapeHtml(route.name)}</strong>
                                    <div class="route-actions flex-shrink-0">
                                        <button class="btn btn-xs btn-outline-primary route-zoom" title="Zoom to route">
                                            <i class="fas fa-search-plus"></i>
                                        </button>
                                        <button class="btn btn-xs btn-outline-secondary route-edit" title="Edit route">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-xs btn-outline-danger route-delete" title="Delete route">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="route-details small">
                                    ${route.constrained_area ? '<div class="text-muted"><i class="fas fa-map-marker-alt mr-1"></i>' + escapeHtml(route.constrained_area) + '</div>' : ''}
                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <span class="text-muted" style="font-size: 0.75rem;">
                                            <i class="far fa-clock mr-1"></i>${validStart} - ${validEnd}
                                        </span>
                                        <span class="${timeInfo.class} font-weight-bold" style="font-size: 0.8rem;" title="${timeInfo.title}">
                                            <i class="fas ${timeInfo.icon} mr-1"></i>${timeInfo.text}
                                        </span>
                                    </div>
                                    ${route.reason ? '<div class="text-info mt-1" style="font-size: 0.75rem;"><i class="fas fa-info-circle mr-1"></i>' + escapeHtml(truncateString(route.reason, 50)) + '</div>' : ''}
                                    ${route.facilities ? '<div class="text-secondary mt-1" style="font-size: 0.7rem;"><i class="fas fa-building mr-1"></i>' + escapeHtml(route.facilities) + '</div>' : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }
            
            // Bind visibility toggle - calls toggleRouteVisibility which does full panel update
            $item.find('.route-visibility-toggle').on('click', function(e) {
                e.stopPropagation();
                toggleRouteVisibility(route.id);
            });
            
            // Bind events
            $item.find('.route-zoom').on('click', function(e) {
                e.stopPropagation();
                zoomToRoute(route);
            });
            
            $item.find('.route-edit').on('click', function(e) {
                e.stopPropagation();
                showRouteDetails(route, true);  // Open in edit mode
            });
            
            $item.find('.route-delete').on('click', function(e) {
                e.stopPropagation();
                deleteRoute(route.id);
            });
            
            $item.on('click', function() {
                showRouteDetails(route);
            });
            
            $list.append($item);
        });
    }
    
    /**
     * Update visibility of route items in the panel based on toggle states
     * This is called when toggles change to avoid re-rendering everything
     */
    function updatePanelRouteVisibility() {
        const $list = $('#public_routes_list');
        
        // Show/hide items based on their time status
        $list.find('.public-route-item').each(function() {
            const $item = $(this);
            const status = $item.data('time-status');
            
            let visible = false;
            if (status === 'active' && state.showActive) visible = true;
            else if (status === 'future' && state.showFuture) visible = true;
            else if (status === 'past' && state.showPast) visible = true;
            
            $item.toggle(visible);
        });
        
        // Check if any items are visible
        const visibleCount = $list.find('.public-route-item:visible').length;
        const $emptyMsg = $list.find('.pr-empty-message');
        
        if (visibleCount === 0) {
            if (!$emptyMsg.length) {
                const totalRoutes = state.routes.length;
                let emptyMessage = 'No public routes';
                if (totalRoutes > 0) {
                    emptyMessage = totalRoutes + ' route(s) hidden by filters';
                }
                $list.append('<div class="pr-empty-message text-muted text-center py-3"><i class="fas fa-eye-slash mr-2"></i>' + emptyMessage + '</div>');
            }
        } else {
            $emptyMsg.remove();
        }
    }

    /**
     * Show route details modal
     */
    function showRouteDetails(route, editMode = false) {
        // Create modal if doesn't exist
        let $modal = $('#publicRouteDetailModal');
        if (!$modal.length) {
            $modal = $(`
                <div class="modal fade" id="publicRouteDetailModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header py-2">
                                <h6 class="modal-title"><i class="fas fa-route mr-2"></i>Route Details</h6>
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                            </div>
                            <div class="modal-body" id="publicRouteDetailBody"></div>
                            <div class="modal-footer py-2">
                                <button type="button" class="btn btn-sm btn-success d-none" id="publicRouteSaveBtn">
                                    <i class="fas fa-save mr-1"></i>Save Changes
                                </button>
                                <button type="button" class="btn btn-sm btn-warning" id="publicRouteEditBuilderBtn" title="Edit this route in the Advisory Builder">
                                    <i class="fas fa-file-alt mr-1"></i>Edit in Builder
                                </button>
                                <button type="button" class="btn btn-sm btn-primary" id="publicRouteZoomBtn">
                                    <i class="fas fa-search-plus mr-1"></i>Zoom
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="publicRouteEditBtn">
                                    <i class="fas fa-edit mr-1"></i>Quick Edit
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `);
            $('body').append($modal);
        }
        
        const validStart = formatTime(route.valid_start_utc);
        const validEnd = formatTime(route.valid_end_utc);
        const timeStatus = route.time_status || getTimeStatus(route);
        const timeInfo = getTimeStatusInfo(route);
        const expInfo = getExpirationInfo(route.valid_end_utc);
        
        // Status badge for future/past routes
        let statusBadge = '';
        if (timeStatus === 'future') {
            statusBadge = '<span class="badge badge-info ml-2">Future</span>';
        } else if (timeStatus === 'past') {
            statusBadge = '<span class="badge badge-secondary ml-2">Expired</span>';
        }
        
        const viewContent = `
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" width="130">Name</td><td><strong>${escapeHtml(route.name)}</strong>${statusBadge}</td></tr>
                        <tr><td class="text-muted">Advisory #</td><td>${route.adv_number || '--'}</td></tr>
                        <tr><td class="text-muted">Constrained Area</td><td>${route.constrained_area || '--'}</td></tr>
                        <tr><td class="text-muted">Reason</td><td>${route.reason || '--'}</td></tr>
                        <tr><td class="text-muted">Facilities</td><td>${route.facilities || '--'}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" width="130">Valid Start</td><td>${validStart}</td></tr>
                        <tr><td class="text-muted">Valid End</td><td>${validEnd}</td></tr>
                        <tr>
                            <td class="text-muted">${timeStatus === 'future' ? 'Starts In' : timeStatus === 'past' ? 'Expired' : 'Time Remaining'}</td>
                            <td><span class="${timeInfo.class} font-weight-bold"><i class="fas ${timeInfo.icon} mr-1"></i>${timeInfo.text}</span></td>
                        </tr>
                        <tr><td class="text-muted">Created By</td><td>${route.created_by || '--'}</td></tr>
                        <tr>
                            <td class="text-muted">Color</td>
                            <td>
                                <span class="badge" style="background-color: ${route.color}; color: white;">${route.color}</span>
                                <button class="btn btn-xs btn-outline-secondary ml-2 edit-color-btn" title="Change color"><i class="fas fa-palette"></i></button>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            ${route.advisory_text ? `
                <hr class="my-2">
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="small text-muted mb-0">Advisory Text</label>
                    </div>
                    <pre class="p-2 bg-light rounded mb-0" style="font-size: 0.75rem; white-space: pre-wrap; max-height: 150px; overflow-y: auto;">${escapeHtml(route.advisory_text)}</pre>
                </div>
            ` : ''}
        `;
        
        const editContent = `
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group mb-2">
                        <label class="small text-muted mb-1">Name</label>
                        <input type="text" class="form-control form-control-sm" id="editRouteName" value="${escapeHtml(route.name)}">
                    </div>
                    <div class="form-group mb-2">
                        <label class="small text-muted mb-1">Constrained Area</label>
                        <input type="text" class="form-control form-control-sm" id="editRouteArea" value="${escapeHtml(route.constrained_area || '')}">
                    </div>
                    <div class="form-group mb-2">
                        <label class="small text-muted mb-1">Reason</label>
                        <input type="text" class="form-control form-control-sm" id="editRouteReason" value="${escapeHtml(route.reason || '')}">
                    </div>
                    <div class="form-group mb-2">
                        <label class="small text-muted mb-1">Facilities</label>
                        <input type="text" class="form-control form-control-sm" id="editRouteFacilities" value="${escapeHtml(route.facilities || '')}">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group mb-2">
                        <label class="small text-muted mb-1">Color</label>
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend">
                                <input type="color" id="editRouteColorPicker" value="${route.color}" 
                                    style="width: 40px; height: 31px; padding: 0; border: 1px solid #ced4da; cursor: pointer;">
                            </div>
                            <input type="text" class="form-control form-control-sm font-monospace" id="editRouteColor" value="${route.color}">
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small text-muted mb-1">Valid Times</label>
                        <div class="small text-muted">${validStart} - ${validEnd}</div>
                        <div class="${expInfo.class} small"><i class="fas fa-hourglass-half mr-1"></i>${expInfo.text} remaining</div>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small text-muted mb-1">Created By</label>
                        <div class="small">${route.created_by || '--'}</div>
                    </div>
                </div>
            </div>
            <hr class="my-2">
            <div class="form-group mb-0">
                <label class="small text-muted mb-1">Advisory Text</label>
                <textarea class="form-control form-control-sm" id="editRouteAdvisory" rows="5" style="font-family: Inconsolata, monospace; font-size: 0.75rem;">${escapeHtml(route.advisory_text || '')}</textarea>
            </div>
        `;
        
        $('#publicRouteDetailBody').html(editMode ? editContent : viewContent);
        
        // Color picker update handlers
        if (editMode) {
            $('#editRouteColorPicker').on('input', function() {
                const color = $(this).val();
                $('#editRouteColor').val(color);
            });
            $('#editRouteColor').on('input', function() {
                let color = $(this).val().trim();
                if (!color.startsWith('#')) color = '#' + color;
                if (/^#[0-9A-Fa-f]{6}$/.test(color)) {
                    $('#editRouteColorPicker').val(color);
                }
            });
        }
        
        // Color edit button in view mode
        $('#publicRouteDetailBody').find('.edit-color-btn').on('click', function(e) {
            e.preventDefault();
            showRouteDetails(route, true);
        });
        
        // Wire up buttons
        $('#publicRouteZoomBtn').off('click').on('click', function() {
            zoomToRoute(route);
            $modal.modal('hide');
        });
        
        $('#publicRouteEditBtn').off('click').on('click', function() {
            showRouteDetails(route, true);
        });
        
        $('#publicRouteEditBuilderBtn').off('click').on('click', function() {
            editRouteInBuilder(route);
        });
        
        $('#publicRouteSaveBtn').off('click').on('click', function() {
            const updateData = {
                name: $('#editRouteName').val(),
                constrained_area: $('#editRouteArea').val(),
                reason: $('#editRouteReason').val(),
                facilities: $('#editRouteFacilities').val(),
                color: $('#editRouteColor').val(),
                advisory_text: $('#editRouteAdvisory').val()
            };
            
            updateRoute(route.id, updateData).then(function() {
                $modal.modal('hide');
            });
        });
        
        // Toggle edit/save buttons
        if (editMode) {
            $('#publicRouteEditBtn').addClass('d-none');
            $('#publicRouteEditBuilderBtn').addClass('d-none');
            $('#publicRouteSaveBtn').removeClass('d-none');
        } else {
            $('#publicRouteEditBtn').removeClass('d-none');
            $('#publicRouteEditBuilderBtn').removeClass('d-none');
            $('#publicRouteSaveBtn').addClass('d-none');
        }
        
        $modal.data('route', route);
        $modal.modal('show');
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLISH FROM ADVISORY BUILDER
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Compute GeoJSON FeatureCollection from expanded route strings
     * This pre-computes geometry for storage in database
     */
    /**
     * Capture current route features from the MapLibre map
     * This preserves all styling properties (solid/dashed/fan) from the route plotter
     */
    function captureRouteFeatures() {
        // Try to get features from MapLibre route source
        if (window.MapLibreRoute && typeof window.MapLibreRoute.getCurrentRouteFeatures === 'function') {
            const geojson = window.MapLibreRoute.getCurrentRouteFeatures();
            if (geojson && geojson.features && geojson.features.length > 0) {
                console.log('[PublicRoutes] Captured', geojson.features.length, 'features from MapLibre routes source');
                return geojson;
            }
        }
        
        console.warn('[PublicRoutes] Could not capture route features from map');
        return null;
    }

    function computeRouteGeoJSON(expandedRoutes) {
        // First try to capture actual rendered features from the map
        // This preserves solid/dashed/fan styling
        const captured = captureRouteFeatures();
        if (captured) {
            return captured;
        }
        
        // Fallback: compute from route strings (won't have solid/fan properties)
        if (!expandedRoutes || !expandedRoutes.length) {
            console.warn('[PublicRoutes] computeRouteGeoJSON: No expanded routes provided');
            return null;
        }
        
        console.log('[PublicRoutes] computeRouteGeoJSON: Fallback - Processing', expandedRoutes.length, 'routes');
        
        // Get the appropriate getPointByName function
        let getPointByName = null;
        if (window.MapLibreRoute && typeof window.MapLibreRoute.getPointByName === 'function') {
            getPointByName = window.MapLibreRoute.getPointByName;
            console.log('[PublicRoutes] Using MapLibreRoute.getPointByName');
        } else if (typeof window.getPointByName === 'function') {
            getPointByName = window.getPointByName;
            console.log('[PublicRoutes] Using global getPointByName');
        }
        
        if (!getPointByName) {
            console.warn('[PublicRoutes] No getPointByName function available');
            console.log('[PublicRoutes] window.MapLibreRoute:', typeof window.MapLibreRoute);
            console.log('[PublicRoutes] window.getPointByName:', typeof window.getPointByName);
            return null;
        }
        
        const features = [];
        
        expandedRoutes.forEach(function(routeStr, idx) {
            if (!routeStr) return;
            
            const coords = [];
            const tokens = routeStr.trim().split(/\s+/);
            
            tokens.forEach(function(token) {
                if (!token) return;
                
                // Skip airways (J/Q/T/V followed by digits)
                if (/^[JQTV]\d+$/i.test(token)) return;
                
                // Skip pure numbers (altitudes, speeds)
                if (/^\d+$/.test(token)) return;
                
                // Skip speed/altitude restrictions
                if (/^[A-Z]\d{3,4}$/.test(token)) return;
                
                // Clean the token
                const clean = token.replace(/[<>]/g, '').toUpperCase();
                
                // Try to get coordinates
                try {
                    const point = getPointByName(clean);
                    // getPointByName returns [name, lat, lon] array OR {lat, lon} object
                    if (point) {
                        let lat, lon;
                        if (Array.isArray(point)) {
                            // MapLibre format: [name, lat, lon]
                            lat = point[1];
                            lon = point[2];
                        } else if (point.lat !== undefined && point.lon !== undefined) {
                            // Object format: {lat, lon}
                            lat = point.lat;
                            lon = point.lon;
                        }
                        if (lat !== undefined && lon !== undefined) {
                            coords.push([lon, lat]); // GeoJSON uses [lng, lat]
                        }
                    }
                } catch (e) {
                    // Ignore errors for individual points
                }
            });
            
            if (coords.length >= 2) {
                features.push({
                    type: 'Feature',
                    properties: {
                        routeIndex: idx,
                        routeString: routeStr.substring(0, 200) // Truncate for storage
                    },
                    geometry: {
                        type: 'LineString',
                        coordinates: coords
                    }
                });
            }
        });
        
        console.log('[PublicRoutes] computeRouteGeoJSON: Created', features.length, 'features');
        
        if (features.length === 0) return null;
        
        return {
            type: 'FeatureCollection',
            features: features
        };
    }

    /**
     * Extract origin and destination filters from route strings
     * Returns { origins: [], dests: [] } with ICAO codes or ARTCC/TRACON codes
     */
    function extractRouteEndpoints(routeStrings) {
        const origins = new Set();
        const dests = new Set();
        
        // Common patterns for airports
        // - 4-letter ICAO: KJFK, KLAX, EGLL, etc.
        // - 3-letter FAA: JFK, LAX, ORD, etc.
        const icaoPattern = /^[A-Z]{4}$/;
        const faaPattern = /^[A-Z]{3}$/;
        
        // US ARTCC codes (3-letter starting with Z)
        // These should NOT be converted to "K" prefix airports
        const usArtccCodes = new Set([
            'ZAB', 'ZAK', 'ZAN', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHN', 'ZHU',
            'ZID', 'ZJX', 'ZKC', 'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA',
            'ZOB', 'ZSE', 'ZSU', 'ZTL', 'ZUA'
        ]);
        
        // Common TRACON codes (alphanumeric, 3-char)
        // Examples: A80 (Atlanta), A90 (Boston), C90 (Chicago), D01 (Denver), 
        //           D10 (Dallas), I90 (Houston), L30 (Las Vegas), N90 (New York),
        //           NCT (NorCal), P50 (Phoenix), PCT (Potomac), S46 (Seattle), SCT (SoCal)
        const traconPattern = /^[A-Z][0-9][0-9]$|^[A-Z]{3}$/;
        const knownTraconCodes = new Set([
            'A80', 'A90', 'C90', 'D01', 'D10', 'D21', 'F11', 'I90', 'L30', 'M03',
            'M98', 'N90', 'NCT', 'P31', 'P50', 'P80', 'PCT', 'R90', 'S46', 'S56',
            'SCT', 'T75', 'U90', 'Y90'
        ]);
        
        // Navigation words to skip
        const skipWords = new Set(['DCT', 'DIRECT', 'VIA', 'TO', 'FROM', 'THEN', 'J', 'Q', 'V', 'T']);
        
        /**
         * Classify a token as ARTCC, TRACON, airport (ICAO/FAA), or unknown
         * Returns { type: 'artcc'|'tracon'|'airport'|'unknown', value: string }
         */
        function classifyToken(tok) {
            // Check if it's a US ARTCC code
            if (usArtccCodes.has(tok)) {
                return { type: 'artcc', value: tok };
            }
            
            // Check if it's a known TRACON code
            if (knownTraconCodes.has(tok)) {
                return { type: 'tracon', value: tok };
            }
            
            // Check if it's a 4-letter ICAO airport code
            if (icaoPattern.test(tok)) {
                return { type: 'airport', value: tok };
            }
            
            // Check if it's a 3-letter code
            if (faaPattern.test(tok)) {
                // If it starts with 'Z' and looks like an ARTCC pattern, treat as ARTCC
                // (catches any ARTCCs not in our explicit list)
                if (tok.startsWith('Z') && /^Z[A-Z]{2}$/.test(tok)) {
                    return { type: 'artcc', value: tok };
                }
                
                // If it matches TRACON pattern (letter + 2 digits), treat as TRACON
                if (/^[A-Z][0-9]{2}$/.test(tok)) {
                    return { type: 'tracon', value: tok };
                }
                
                // Otherwise it's likely a 3-letter FAA airport code - convert to ICAO
                return { type: 'airport', value: 'K' + tok };
            }
            
            return { type: 'unknown', value: tok };
        }
        
        // Process each route string
        const routes = Array.isArray(routeStrings) ? routeStrings : [routeStrings];
        
        for (const routeStr of routes) {
            if (!routeStr || typeof routeStr !== 'string') continue;
            
            // Tokenize the route string
            const tokens = routeStr.toUpperCase().trim().split(/\s+/).filter(t => t && !skipWords.has(t));
            if (tokens.length === 0) continue;
            
            // Find first token that looks like an airport/ARTCC/TRACON (origin)
            let foundOrigin = false;
            for (let i = 0; i < Math.min(3, tokens.length) && !foundOrigin; i++) {
                // Strip mandatory markers <> and equipment suffixes
                const tok = tokens[i].replace(/[<>]/g, '').replace(/\/.*$/, '');
                const classified = classifyToken(tok);
                
                if (classified.type !== 'unknown') {
                    origins.add(classified.value);
                    foundOrigin = true;
                    console.log('[PublicRoutes] Origin classified:', tok, '->', classified.type, classified.value);
                }
            }
            
            // Find last token that looks like an airport/ARTCC/TRACON (destination)
            let foundDest = false;
            for (let i = tokens.length - 1; i >= Math.max(0, tokens.length - 3) && !foundDest; i--) {
                // Strip mandatory markers <> and equipment suffixes
                const tok = tokens[i].replace(/[<>]/g, '').replace(/\/.*$/, '');
                const classified = classifyToken(tok);
                
                if (classified.type !== 'unknown') {
                    dests.add(classified.value);
                    foundDest = true;
                    console.log('[PublicRoutes] Destination classified:', tok, '->', classified.type, classified.value);
                }
            }
        }
        
        return {
            origins: Array.from(origins),
            dests: Array.from(dests)
        };
    }
    
    /**
     * Collect data from advisory builder and create a public route
     */
    function publishFromAdvisoryBuilder() {
        // Check if we're in editing mode
        const isEditing = state.editingRouteId !== null;
        const editingRoute = state.editingRoute;
        
        // Get values from advisory builder form
        const name = $('#advName').val() || 'Unnamed Route';
        const advNumber = $('#advNumber').val();
        const constrainedArea = $('#advConstrainedArea').val();
        const reason = $('#advReason').val();
        const facilities = $('#advFacilities').val();
        const validStart = $('#advValidStart').val();
        const validEnd = $('#advValidEnd').val();
        const advisoryText = $('#advOutput').val();
        
        // Get route string - prefer expanded routes from map library if available
        let routeString = '';
        let expandedRoutes = [];
        
        // Try to get expanded routes from MapLibre (handles playbook expansion)
        if (window.MapLibreRoute && typeof window.MapLibreRoute.getLastExpandedRoutes === 'function') {
            expandedRoutes = window.MapLibreRoute.getLastExpandedRoutes() || [];
            if (expandedRoutes.length > 0) {
                routeString = expandedRoutes.join('\n');
                console.log('[PublicRoutes] Using', expandedRoutes.length, 'expanded routes from MapLibre');
            }
        }
        // Try global function (Leaflet fallback)
        else if (typeof window.getLastExpandedRoutes === 'function') {
            expandedRoutes = window.getLastExpandedRoutes() || [];
            if (expandedRoutes.length > 0) {
                routeString = expandedRoutes.join('\n');
                console.log('[PublicRoutes] Using', expandedRoutes.length, 'expanded routes from Leaflet');
            }
        }
        
        // Fallback to raw input if no expanded routes available
        if (!routeString) {
            routeString = $('#routeSearch').val();
        }
        
        // For editing, we may not need new route geometry
        if (!routeString && !isEditing) {
            alert('Please plot routes first using the "Plot Routes" button.');
            return;
        }
        
        if (!validStart || !validEnd) {
            alert('Please enter valid start and end times (DDHHMM format).');
            return;
        }
        
        // Parse DDHHMM to datetime
        const now = new Date();
        const startUtc = parseDDHHMM(validStart, now);
        const endUtc = parseDDHHMM(validEnd, now);
        
        if (!startUtc || !endUtc) {
            alert('Invalid time format. Use DDHHMM (e.g., 251430).');
            return;
        }
        
        // Pre-compute GeoJSON geometry from expanded routes (only if we have new routes)
        let routeGeojson = null;
        if (expandedRoutes.length > 0) {
            routeGeojson = computeRouteGeoJSON(expandedRoutes);
            console.log('[PublicRoutes] Pre-computed GeoJSON with', routeGeojson ? routeGeojson.features.length : 0, 'features');
        }
        
        // Extract origin/destination filters from route strings
        const endpoints = extractRouteEndpoints(expandedRoutes.length > 0 ? expandedRoutes : (routeString ? [routeString] : []));
        console.log('[PublicRoutes] Extracted endpoints - origins:', endpoints.origins, 'dests:', endpoints.dests);
        
        // Build route data
        const routeData = {
            name: name,
            adv_number: advNumber,
            advisory_text: advisoryText,
            constrained_area: constrainedArea,
            reason: reason,
            facilities: facilities,
            valid_start_utc: startUtc.toISOString(),
            valid_end_utc: endUtc.toISOString()
        };
        
        // Add origin/destination filters (for flight matching)
        if (endpoints.origins.length > 0) {
            routeData.origin_filter = endpoints.origins;
        }
        if (endpoints.dests.length > 0) {
            routeData.dest_filter = endpoints.dests;
        }
        
        // Only include route geometry if we have it
        if (routeString) {
            routeData.route_string = routeString;
        }
        if (routeGeojson) {
            routeData.route_geojson = JSON.stringify(routeGeojson);
        }
        
        // Preserve existing color when editing
        if (isEditing && editingRoute) {
            routeData.color = editingRoute.color;
            routeData.created_by = editingRoute.created_by;
        }
        
        // Show color picker dialog
        showPublishDialog(routeData, isEditing);
    }

    /**
     * Show publish dialog for selecting color and confirming
     */
    function showPublishDialog(routeData, isEditing = false) {
        console.log('[PublicRoutes] showPublishDialog received keys:', Object.keys(routeData));
        console.log('[PublicRoutes] showPublishDialog route_geojson present:', !!routeData.route_geojson);
        console.log('[PublicRoutes] isEditing:', isEditing);
        if (routeData.route_geojson) {
            console.log('[PublicRoutes] showPublishDialog route_geojson length:', routeData.route_geojson.length);
        }
        
        // Create modal if doesn't exist
        let $modal = $('#publishRouteModal');
        if (!$modal.length) {
            const colorOptions = ROUTE_COLORS.map(c => 
                `<div class="color-option" data-color="${c}" style="background-color: ${c};" title="${c}"></div>`
            ).join('');
            
            // Add styles first (separate from modal to avoid Bootstrap modal init issues)
            if (!$('#publishRouteStyles').length) {
                $('head').append(`
                    <style id="publishRouteStyles">
                        .color-picker-grid {
                            display: flex;
                            flex-wrap: wrap;
                            gap: 6px;
                            margin-bottom: 8px;
                        }
                        .color-option {
                            width: 28px;
                            height: 28px;
                            border-radius: 4px;
                            cursor: pointer;
                            border: 2px solid transparent;
                            transition: all 0.15s;
                        }
                        .color-option:hover {
                            transform: scale(1.1);
                        }
                        .color-option.selected {
                            border-color: #333;
                            box-shadow: 0 0 0 2px white, 0 0 0 4px #333;
                        }
                        .color-picker-custom {
                            display: flex;
                            align-items: center;
                            gap: 8px;
                            margin-top: 8px;
                            padding-top: 8px;
                            border-top: 1px solid #eee;
                        }
                        .color-picker-custom input[type="color"] {
                            width: 40px;
                            height: 32px;
                            padding: 0;
                            border: 1px solid #ccc;
                            border-radius: 4px;
                            cursor: pointer;
                        }
                        .color-picker-custom input[type="text"] {
                            width: 90px;
                            font-family: monospace;
                        }
                    </style>
                `);
            }
            
            // Create modal HTML separately
            const modalHtml = `
                <div class="modal fade" id="publishRouteModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header py-2" id="publishModalHeader">
                                <h6 class="modal-title" id="publishModalTitle"><i class="fas fa-globe mr-2"></i>Publish Route</h6>
                                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                            </div>
                            <div class="modal-body">
                                <p class="small text-muted mb-3" id="publishModalDesc">
                                    Publishing will make this route visible to all users on the map.
                                </p>
                                <div class="form-group">
                                    <label class="small mb-1">Route Name</label>
                                    <input type="text" class="form-control form-control-sm" id="publishRouteName" placeholder="e.g., GOLDDR">
                                </div>
                                <div class="form-group">
                                    <label class="small mb-1">Route Color</label>
                                    <div class="color-picker-grid">${colorOptions}</div>
                                    <div class="color-picker-custom">
                                        <input type="color" id="publishRouteColorPicker" value="#e74c3c" title="Custom color">
                                        <input type="text" class="form-control form-control-sm" id="publishRouteColorHex" value="#e74c3c" placeholder="#hex">
                                        <span class="small text-muted">Custom</span>
                                    </div>
                                    <input type="hidden" id="publishRouteColor" value="#e74c3c">
                                </div>
                                <div class="form-group mb-0">
                                    <label class="small mb-1">Your Name/CID (optional)</label>
                                    <input type="text" class="form-control form-control-sm" id="publishRouteCreator" placeholder="e.g., John D.">
                                </div>
                            </div>
                            <div class="modal-footer py-2">
                                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-sm btn-success" id="confirmPublishBtn">
                                    <i class="fas fa-check mr-1"></i>Publish
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            $('body').append(modalHtml);
            $modal = $('#publishRouteModal');
            
            // Color grid selection
            $modal.find('.color-option').on('click', function() {
                $modal.find('.color-option').removeClass('selected');
                $(this).addClass('selected');
                const color = $(this).data('color');
                $('#publishRouteColor').val(color);
                $('#publishRouteColorPicker').val(color);
                $('#publishRouteColorHex').val(color);
            });
            
            // Custom color picker
            $('#publishRouteColorPicker').on('input', function() {
                const color = $(this).val();
                $modal.find('.color-option').removeClass('selected');
                $('#publishRouteColor').val(color);
                $('#publishRouteColorHex').val(color);
            });
            
            // Hex input
            $('#publishRouteColorHex').on('change', function() {
                let color = $(this).val().trim();
                if (!color.startsWith('#')) color = '#' + color;
                if (/^#[0-9A-Fa-f]{6}$/.test(color)) {
                    $modal.find('.color-option').removeClass('selected');
                    $('#publishRouteColor').val(color);
                    $('#publishRouteColorPicker').val(color);
                    // Check if it matches a preset
                    $modal.find('.color-option').each(function() {
                        if ($(this).data('color').toLowerCase() === color.toLowerCase()) {
                            $(this).addClass('selected');
                        }
                    });
                }
            });
            
            // Select first color by default
            $modal.find('.color-option').first().addClass('selected');
        }
        
        // Update modal appearance based on edit vs new
        if (isEditing) {
            $('#publishModalHeader').removeClass('bg-success').addClass('bg-warning');
            $('#publishModalTitle').html('<i class="fas fa-edit mr-2"></i>Update Route');
            $('#publishModalDesc').text('Update this route\'s display settings. Changes will be visible to all users.');
            $('#confirmPublishBtn').removeClass('btn-success').addClass('btn-warning').html('<i class="fas fa-save mr-1"></i>Update');
        } else {
            $('#publishModalHeader').removeClass('bg-warning').addClass('bg-success');
            $('#publishModalTitle').html('<i class="fas fa-globe mr-2"></i>Publish Route');
            $('#publishModalDesc').text('Publishing will make this route visible to all users on the map.');
            $('#confirmPublishBtn').removeClass('btn-warning').addClass('btn-success').html('<i class="fas fa-check mr-1"></i>Publish');
        }
        
        // Pre-fill values
        $('#publishRouteName').val(routeData.name || '');
        $('#publishRouteCreator').val(routeData.created_by || localStorage.getItem('perti_username') || '');
        
        // Set color
        const routeColor = routeData.color || '#e74c3c';
        $('#publishRouteColor').val(routeColor);
        $('#publishRouteColorPicker').val(routeColor);
        $('#publishRouteColorHex').val(routeColor);
        $modal.find('.color-option').removeClass('selected');
        $modal.find(`.color-option[data-color="${routeColor}"]`).addClass('selected');
        if (!$modal.find('.color-option.selected').length) {
            // Custom color not in grid - leave grid unselected
        }
        
        // Store route data and edit state
        $modal.data('routeData', routeData);
        $modal.data('isEditing', isEditing);
        
        // Confirm handler
        $('#confirmPublishBtn').off('click').on('click', function() {
            const finalData = $modal.data('routeData');
            const editing = $modal.data('isEditing');
            console.log('[PublicRoutes] Modal routeData keys:', Object.keys(finalData));
            console.log('[PublicRoutes] Modal route_geojson present:', !!finalData.route_geojson);
            
            finalData.name = $('#publishRouteName').val() || 'Unnamed Route';
            finalData.color = $('#publishRouteColor').val();
            finalData.created_by = $('#publishRouteCreator').val();
            
            // Save username for next time
            if (finalData.created_by) {
                localStorage.setItem('perti_username', finalData.created_by);
            }
            
            const savePromise = editing && state.editingRouteId 
                ? updateRoute(state.editingRouteId, finalData)
                : saveRoute(finalData);
            
            savePromise.then(function() {
                $modal.modal('hide');
                // Clear editing state
                state.editingRouteId = null;
                state.editingRoute = null;
                clearEditingMode();
                // Enable public routes layer if not already
                if (!state.enabled) {
                    enable();
                    $('#public_routes_toggle').prop('checked', true);
                }
            });
        });
        
        $modal.modal('show');
    }

    /**
     * Edit an existing route in the advisory builder
     */
    function editRouteInBuilder(route) {
        if (!route) return;
        
        console.log('[PublicRoutes] Editing route in builder:', route.name);
        
        // Set editing state
        state.editingRouteId = route.id;
        state.editingRoute = route;
        
        // Populate advisory builder form fields
        $('#advName').val(route.name || '');
        $('#advNumber').val(route.adv_number || '');
        $('#advConstrainedArea').val(route.constrained_area || '');
        $('#advReason').val(route.reason || '');
        $('#advFacilities').val(route.facilities || '');
        
        // Parse valid times back to DDHHMM format
        if (route.valid_start_utc) {
            const start = new Date(route.valid_start_utc);
            const startStr = String(start.getUTCDate()).padStart(2, '0') +
                           String(start.getUTCHours()).padStart(2, '0') +
                           String(start.getUTCMinutes()).padStart(2, '0');
            $('#advValidStart').val(startStr);
        }
        if (route.valid_end_utc) {
            const end = new Date(route.valid_end_utc);
            const endStr = String(end.getUTCDate()).padStart(2, '0') +
                         String(end.getUTCHours()).padStart(2, '0') +
                         String(end.getUTCMinutes()).padStart(2, '0');
            $('#advValidEnd').val(endStr);
        }
        
        // Set advisory text if available
        if (route.advisory_text) {
            $('#advOutput').val(route.advisory_text);
        }
        
        // Update publish button to show editing mode
        setEditingMode(route);
        
        // Scroll to advisory builder section
        const $advBuilder = $('#advisory_builder_collapse');
        if ($advBuilder.length) {
            // Expand the advisory builder panel if collapsed
            if (!$advBuilder.hasClass('show')) {
                $advBuilder.collapse('show');
            }
            // Scroll to it
            setTimeout(function() {
                $advBuilder[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 300);
        }
        
        // Close the details modal if open
        $('#publicRouteDetailModal').modal('hide');
    }

    /**
     * Set the advisory builder to editing mode
     */
    function setEditingMode(route) {
        const $publishBtn = $('#adv_publish');
        if (!$publishBtn.length) return;
        
        // Store original button state
        if (!$publishBtn.data('originalHtml')) {
            $publishBtn.data('originalHtml', $publishBtn.html());
            $publishBtn.data('originalClass', $publishBtn.attr('class'));
        }
        
        // Update button appearance
        $publishBtn
            .removeClass('btn-success')
            .addClass('btn-warning')
            .html('<i class="fas fa-save mr-1"></i> Update Route');
        
        // Add editing indicator
        let $indicator = $('#adv_editing_indicator');
        if (!$indicator.length) {
            $indicator = $(`
                <div id="adv_editing_indicator" class="alert alert-warning py-2 px-3 mb-2 d-flex align-items-center justify-content-between">
                    <span>
                        <i class="fas fa-edit mr-2"></i>
                        <strong>Editing:</strong> <span id="adv_editing_name"></span>
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="adv_cancel_edit">
                        <i class="fas fa-times mr-1"></i>Cancel Edit
                    </button>
                </div>
            `);
            $publishBtn.parent().before($indicator);
            
            // Cancel edit handler
            $('#adv_cancel_edit').on('click', function() {
                clearEditingMode();
                // Clear form fields
                $('#advName, #advNumber, #advConstrainedArea, #advReason, #advFacilities, #advValidStart, #advValidEnd').val('');
                $('#advOutput').val('');
            });
        }
        
        $('#adv_editing_name').text(route.name || 'Unnamed Route');
        $indicator.show();
    }

    /**
     * Clear editing mode and restore publish button
     */
    function clearEditingMode() {
        state.editingRouteId = null;
        state.editingRoute = null;
        
        const $publishBtn = $('#adv_publish');
        if ($publishBtn.length && $publishBtn.data('originalHtml')) {
            $publishBtn
                .attr('class', $publishBtn.data('originalClass'))
                .html($publishBtn.data('originalHtml'));
        }
        
        $('#adv_editing_indicator').hide();
    }

    // ─────────────────────────────────────────────────────────────────────
    // MAP RENDERING (to be overridden by integration)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Render routes on the map - this is a placeholder that gets overridden
     * by the Leaflet or MapLibre integration
     */
    function renderRoutes() {
        const visibleRoutes = getVisibleRoutes();
        console.log('[PublicRoutes] renderRoutes() - rendering', visibleRoutes.length, 'of', state.routes.length, 'routes');
        $(document).trigger('publicRoutes:render', [visibleRoutes]);
    }

    /**
     * Zoom to a specific route - placeholder
     */
    function zoomToRoute(route) {
        console.log('[PublicRoutes] zoomToRoute() - override this in integration');
        $(document).trigger('publicRoutes:zoom', [route]);
    }

    /**
     * Clear route display - placeholder
     */
    function clearRoutes() {
        console.log('[PublicRoutes] clearRoutes() - override this in integration');
        $(document).trigger('publicRoutes:clear');
    }

    // ─────────────────────────────────────────────────────────────────────
    // ENABLE / DISABLE
    // ─────────────────────────────────────────────────────────────────────

    function enable() {
        if (state.enabled) return;
        state.enabled = true;
        
        console.log('[PublicRoutes] Enabling...');
        
        // Initial fetch
        fetchRoutes();
        
        // Start auto-refresh
        state.refreshInterval = setInterval(fetchRoutes, state.refreshRateMs);
        
        updateStatusIndicator();
    }

    function disable() {
        if (!state.enabled) return;
        state.enabled = false;
        
        console.log('[PublicRoutes] Disabling...');
        
        // Stop refresh
        if (state.refreshInterval) {
            clearInterval(state.refreshInterval);
            state.refreshInterval = null;
        }
        
        // Clear map display
        clearRoutes();
        
        updateStatusIndicator();
    }

    function toggle() {
        state.enabled ? disable() : enable();
    }

    function setLayerVisible(visible) {
        state.layerVisible = visible;
        if (state.enabled) {
            if (visible) {
                renderRoutes();
            } else {
                clearRoutes();
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // UTILITY FUNCTIONS
    // ─────────────────────────────────────────────────────────────────────

    function formatTime(isoString) {
        if (!isoString) return '--';
        try {
            const d = new Date(isoString);
            const day = String(d.getUTCDate()).padStart(2, '0');
            const hours = String(d.getUTCHours()).padStart(2, '0');
            const mins = String(d.getUTCMinutes()).padStart(2, '0');
            return day + hours + mins + 'Z';
        } catch (e) {
            return '--';
        }
    }

    function isExpiringSoonCheck(endUtc) {
        if (!endUtc) return false;
        const end = new Date(endUtc);
        const now = new Date();
        const diffHours = (end - now) / (1000 * 60 * 60);
        return diffHours > 0 && diffHours < 2;
    }

    /**
     * Get expiration info with time remaining and color class
     */
    function getExpirationInfo(endUtc) {
        if (!endUtc) return { text: '--', class: 'text-muted', expired: false };
        
        const end = new Date(endUtc);
        const now = new Date();
        const diffMs = end - now;
        
        if (diffMs <= 0) {
            return { text: 'Expired', class: 'text-danger', expired: true };
        }
        
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMins / 60);
        const remainingMins = diffMins % 60;
        
        let text, colorClass;
        
        if (diffHours >= 24) {
            const days = Math.floor(diffHours / 24);
            const hrs = diffHours % 24;
            text = days + 'd ' + hrs + 'h';
            colorClass = 'text-success';
        } else if (diffHours >= 4) {
            text = diffHours + 'h ' + remainingMins + 'm';
            colorClass = 'text-success';
        } else if (diffHours >= 2) {
            text = diffHours + 'h ' + remainingMins + 'm';
            colorClass = 'text-info';
        } else if (diffHours >= 1) {
            text = diffHours + 'h ' + remainingMins + 'm';
            colorClass = 'text-warning';
        } else {
            text = diffMins + 'm';
            colorClass = 'text-danger';
        }
        
        return { text: text, class: colorClass, expired: false };
    }

    /**
     * Determine the time status of a route
     * @returns {string} 'active', 'future', 'past', or 'unknown'
     */
    function getTimeStatus(route) {
        if (!route.valid_start_utc || !route.valid_end_utc) return 'unknown';
        
        const now = new Date();
        const start = new Date(route.valid_start_utc);
        const end = new Date(route.valid_end_utc);
        
        if (now < start) return 'future';
        if (now > end) return 'past';
        return 'active';
    }

    /**
     * Get time status info for display (text, icon, class, title)
     */
    function getTimeStatusInfo(route) {
        const timeStatus = route.time_status || getTimeStatus(route);
        
        if (timeStatus === 'future') {
            // Calculate time until start
            const start = new Date(route.valid_start_utc);
            const now = new Date();
            const diffMs = start - now;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMins / 60);
            
            let text;
            if (diffHours >= 24) {
                const days = Math.floor(diffHours / 24);
                const hrs = diffHours % 24;
                text = 'Starts in ' + days + 'd ' + hrs + 'h';
            } else if (diffHours >= 1) {
                text = 'Starts in ' + diffHours + 'h ' + (diffMins % 60) + 'm';
            } else {
                text = 'Starts in ' + diffMins + 'm';
            }
            
            return {
                text: text,
                icon: 'fa-calendar-alt',
                class: 'text-info',
                title: 'Route starts in the future'
            };
        } else if (timeStatus === 'past') {
            // Calculate time since end
            const end = new Date(route.valid_end_utc);
            const now = new Date();
            const diffMs = now - end;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMins / 60);
            
            let text;
            if (diffHours >= 24) {
                const days = Math.floor(diffHours / 24);
                text = 'Expired ' + days + 'd ago';
            } else if (diffHours >= 1) {
                text = 'Expired ' + diffHours + 'h ago';
            } else {
                text = 'Expired ' + diffMins + 'm ago';
            }
            
            return {
                text: text,
                icon: 'fa-history',
                class: 'text-secondary',
                title: 'Route has expired'
            };
        } else {
            // Active - use existing expiration info
            const expInfo = getExpirationInfo(route.valid_end_utc);
            return {
                text: expInfo.text,
                icon: 'fa-hourglass-half',
                class: expInfo.class,
                title: 'Time until expiration'
            };
        }
    }

    /**
     * Update an existing public route
     */
    function updateRoute(routeId, updateData) {
        console.log('[PublicRoutes] Updating route:', routeId, updateData);
        
        return $.ajax({
            url: 'api/swim/v1/tmi/routes?id=' + routeId,
            method: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify(updateData),
            dataType: 'json'
        }).done(function(data) {
            if (data.success) {
                console.log('[PublicRoutes] Route updated:', data.data || data.route);
                fetchRoutes();  // Refresh the list
                return data.route;
            } else {
                console.error('[PublicRoutes] Update error:', data.error);
                alert('Failed to update route: ' + data.error);
            }
        }).fail(function(xhr, status, err) {
            console.error('[PublicRoutes] Update failed:', status, err);
            alert('Failed to update route: ' + err);
        });
    }

    function parseDDHHMM(str, referenceDate) {
        if (!str || str.length !== 6) return null;
        
        const day = parseInt(str.substr(0, 2), 10);
        const hours = parseInt(str.substr(2, 2), 10);
        const mins = parseInt(str.substr(4, 2), 10);
        
        if (isNaN(day) || isNaN(hours) || isNaN(mins)) return null;
        if (day < 1 || day > 31 || hours > 23 || mins > 59) return null;
        
        const result = new Date(referenceDate);
        result.setUTCDate(day);
        result.setUTCHours(hours, mins, 0, 0);
        
        // Only bump to next month if the date is more than 7 days in the past.
        // Routes are posted at most 7 days before activation, and start times
        // may be slightly in the past (e.g., a few minutes ago).
        const sevenDaysMs = 7 * 24 * 60 * 60 * 1000;
        if ((referenceDate - result) > sevenDaysMs) {
            result.setUTCMonth(result.getUTCMonth() + 1);
        }
        
        return result;
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function truncateString(str, maxLen) {
        if (!str) return '';
        if (str.length <= maxLen) return str;
        return str.substr(0, maxLen) + '...';
    }

    function getNextColor() {
        const usedColors = state.routes.map(r => r.color);
        for (const color of ROUTE_COLORS) {
            if (!usedColors.includes(color)) return color;
        }
        return ROUTE_COLORS[Math.floor(Math.random() * ROUTE_COLORS.length)];
    }

    // ─────────────────────────────────────────────────────────────────────
    // INITIALIZATION
    // ─────────────────────────────────────────────────────────────────────

    function init() {
        console.log('[PublicRoutes] Initializing...');
        
        // Toggle switch
        $('#public_routes_toggle').on('change', function() {
            $(this).is(':checked') ? enable() : disable();
        });
        
        // Panel toggle
        $('#public_routes_panel_toggle').on('click', function() {
            const $body = $('#public_routes_panel_body');
            const isVisible = $body.is(':visible');
            $body.slideToggle(200);
            $(this).text(isVisible ? 'Show' : 'Hide');
        });
        
        // Publish button in advisory builder
        $('#adv_publish').on('click', function() {
            publishFromAdvisoryBuilder();
        });
        
        // Layer visibility toggle
        $('#public_routes_layer_toggle').on('change', function() {
            setLayerVisible($(this).is(':checked'));
        });
        
        // Refresh button
        $('#public_routes_refresh').on('click', function() {
            fetchRoutes();
        });
        
        console.log('[PublicRoutes] Initialized');
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    return {
        init: init,
        enable: enable,
        disable: disable,
        toggle: toggle,
        fetchRoutes: fetchRoutes,
        saveRoute: saveRoute,
        updateRoute: updateRoute,
        deleteRoute: deleteRoute,
        publishFromAdvisoryBuilder: publishFromAdvisoryBuilder,
        editRouteInBuilder: editRouteInBuilder,
        clearEditingMode: clearEditingMode,
        setLayerVisible: setLayerVisible,
        getState: function() { return state; },
        getRoutes: function() { return getVisibleRoutes(); },  // Returns filtered routes for map
        getAllRoutes: function() { return state.routes; },     // Returns all routes
        getCategoryFilteredRoutes: getCategoryFilteredRoutes,  // Routes matching category toggles
        showRouteDetails: showRouteDetails,
        getExpirationInfo: getExpirationInfo,
        getTimeStatus: getTimeStatus,
        getTimeStatusInfo: getTimeStatusInfo,
        // Category visibility toggles
        setShowActive: function(show) { state.showActive = show; updatePanel(); renderRoutes(); },
        setShowFuture: function(show) { state.showFuture = show; updatePanel(); renderRoutes(); },
        setShowPast: function(show) { state.showPast = show; updatePanel(); renderRoutes(); },
        showAllCategories: function() { state.showActive = state.showFuture = state.showPast = true; updatePanel(); renderRoutes(); },
        hideAllCategories: function() { state.showActive = state.showFuture = state.showPast = false; updatePanel(); renderRoutes(); },
        // Individual route visibility
        toggleRouteVisibility: toggleRouteVisibility,
        showRoute: showRoute,
        hideRoute: hideRoute,
        showAllRoutes: showAllRoutes,
        isRouteHidden: function(routeId) { return state.hiddenRouteIds.has(routeId); },
        getHiddenRouteIds: function() { return Array.from(state.hiddenRouteIds); },
        ROUTE_COLORS: ROUTE_COLORS,
        // Allow integration to override these
        setRenderFunction: function(fn) { renderRoutes = fn; },
        setZoomFunction: function(fn) { zoomToRoute = fn; },
        setClearFunction: function(fn) { clearRoutes = fn; },
        // API key for write operations
        setApiKey: function(key) { state.apiKey = key; }
    };
})();

// Initialize on DOM ready
$(document).ready(function() {
    if (typeof window.PublicRoutes !== 'undefined') {
        window.PublicRoutes.init();
    }
});
