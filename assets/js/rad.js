/**
 * RAD Controller — Tab management, map<->tab coordination, init.
 */
window.RADController = (function() {
    var mapInitialized = false;

    function init() {
        // Initialize event bus consumers for map
        RADEventBus.on('route:plot', function(data) {
            if (window.MapLibreRoute && data.routeString) {
                // Put route in textarea and trigger plot (processRoutes reads from #routeSearch)
                var $textarea = $('#routeSearch');
                var existing = $textarea.val().trim();
                var route = data.routeString.trim();
                // Avoid duplicating the same route
                if (existing.indexOf(route) === -1) {
                    $textarea.val(existing ? existing + '\n' + route : route);
                }
                window.MapLibreRoute.processRoutes();
            }
        });

        RADEventBus.on('route:clear', function(data) {
            // Clear all routes by emptying textarea and re-processing
            if (data && data.routeString) {
                // Remove specific route from textarea
                var $textarea = $('#routeSearch');
                var lines = $textarea.val().split('\n').filter(function(l) {
                    return l.trim() !== data.routeString.trim();
                });
                $textarea.val(lines.join('\n'));
            } else {
                $('#routeSearch').val('');
            }
            if (window.MapLibreRoute) {
                window.MapLibreRoute.processRoutes();
            }
        });

        RADEventBus.on('flight:highlighted', function(data) {
            // Focus map on flight's origin if MapLibre is available
            if (window.MapLibreRoute && data && data.origin) {
                // MapLibre will handle visual highlighting through route:plot
            }
        });

        // Initialize sub-modules
        if (window.RADFlightSearch) RADFlightSearch.init();
        if (window.RADFlightDetail) RADFlightDetail.init();
        if (window.RADAmendment) RADAmendment.init();
        if (window.RADMonitoring) RADMonitoring.init();

        // Tab change: start/stop monitoring poll
        $('#radTabs a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
            if (e.target.id === 'tab-monitoring' && window.RADMonitoring) {
                RADMonitoring.startPolling();
            } else if (window.RADMonitoring) {
                RADMonitoring.stopPolling();
            }
        });

        // Playbook/CDR/Preferred panel toggle
        var pbcdrInited = false;
        $('#rad_btn_pbcdr').on('click', function() {
            var panel = document.getElementById('pbcdr_search_panel');
            if (!panel) return;
            panel.classList.toggle('show');
            if (panel.classList.contains('show') && !pbcdrInited) {
                if (window.PlaybookCDRSearch) {
                    PlaybookCDRSearch.init();
                    pbcdrInited = true;
                }
            }
            // Auto-default origin/dest from Detail flights
            if (panel.classList.contains('show') && window.RADFlightDetail) {
                var flights = RADFlightDetail.getFlights();
                if (flights.length > 0) {
                    var origins = [], dests = [];
                    flights.forEach(function(f) {
                        if (f.origin && origins.indexOf(f.origin) === -1) origins.push(f.origin);
                        if (f.dest && dests.indexOf(f.dest) === -1) dests.push(f.dest);
                    });
                    if (origins.length > 0) $('#pbcdr_orig_apt').val(origins.join(','));
                    if (dests.length > 0) $('#pbcdr_dest_apt').val(dests.join(','));
                }
            }
        });

        // PBCDR panel close button
        $(document).on('click', '#pbcdr_panel_close', function() {
            var panel = document.getElementById('pbcdr_search_panel');
            if (panel) panel.classList.remove('show');
        });

        // PBCDR panel collapse button
        $(document).on('click', '#pbcdr_collapse_btn', function() {
            var panel = document.getElementById('pbcdr_search_panel');
            if (!panel) return;
            panel.classList.toggle('collapsed');
            var icon = $(this).find('i');
            if (panel.classList.contains('collapsed')) {
                icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            } else {
                icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            }
        });

        // Live traffic toggle
        var liveTrafficEnabled = false;
        $('#rad_btn_live_traffic').on('click', function() {
            if (!window.ADL) return;
            liveTrafficEnabled = !liveTrafficEnabled;
            var $btn = $(this);
            if (liveTrafficEnabled) {
                $('#adl_toggle').prop('checked', true).trigger('change');
                $btn.removeClass('btn-outline-light').addClass('btn-success');
            } else {
                $('#adl_toggle').prop('checked', false).trigger('change');
                $btn.removeClass('btn-success').addClass('btn-outline-light');
            }
        });

        // PBCDR tab click handler
        $(document).on('click', '.pbcdr-tab', function() {
            var tabType = $(this).data('tab');
            $('.pbcdr-tab').removeClass('active');
            $(this).addClass('active');

            var nameLabel = document.getElementById('pbcdr_name_label');
            var nameInput = document.getElementById('pbcdr_name');

            if (window.PlaybookCDRSearch) {
                PlaybookCDRSearch.setSearchType(tabType);
            }

            if (nameLabel && nameInput) {
                if (tabType === 'playbook') {
                    nameLabel.textContent = PERTII18n.t('rad.edit.pbPlayName');
                    nameInput.placeholder = PERTII18n.t('rad.edit.pbPlayPlaceholder');
                } else if (tabType === 'cdr') {
                    nameLabel.textContent = PERTII18n.t('rad.edit.pbCdrCode');
                    nameInput.placeholder = PERTII18n.t('rad.edit.pbCdrPlaceholder');
                } else if (tabType === 'preferred') {
                    nameLabel.textContent = PERTII18n.t('rad.edit.pbCityPair');
                    nameInput.placeholder = PERTII18n.t('rad.edit.pbCityPairPlaceholder');
                } else {
                    nameLabel.textContent = PERTII18n.t('rad.edit.pbNameCode');
                    nameInput.placeholder = PERTII18n.t('rad.edit.pbSearchAll');
                }
            }
        });

        // Initialize map (same as route.php)
        initMap();
    }

    function initMap() {
        if (mapInitialized) return;
        if (window.MapLibreRoute) {
            window.MapLibreRoute.init({
                containerId: 'graphic',
                enableFlights: true,
                enableContextMenu: true,
                contextMenuItems: [
                    { label: PERTII18n.t('rad.edit.amendRoute'), action: function(flight) {
                        RADEventBus.emit('flight:selected', flight);
                        $('#tab-edit').tab('show');
                    }}
                ]
            });
            mapInitialized = true;
        }
    }

    return { init: init };
})();

// Auto-init on DOM ready
$(document).ready(function() {
    RADController.init();
});
