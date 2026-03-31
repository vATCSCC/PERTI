/**
 * RAD Controller — Tab management, map<->tab coordination, init.
 */
window.RADController = (function() {
    var mapInitialized = false;

    function init() {
        // Initialize event bus consumers for map
        RADEventBus.on('route:plot', function(data) {
            if (window.MapLibreRoute) {
                window.MapLibreRoute.processRoutes(data.routeString, {
                    color: data.color || '#FF6600',
                    id: data.id || 'rad-route-' + Date.now()
                });
            }
        });

        RADEventBus.on('route:clear', function(data) {
            // Clear specific route layer from map
            var map = window.MapLibreRoute ? window.MapLibreRoute.getMap() : null;
            if (map && data.id) {
                if (map.getLayer(data.id)) map.removeLayer(data.id);
                if (map.getSource(data.id)) map.removeSource(data.id);
            }
        });

        RADEventBus.on('flight:highlighted', function(data) {
            // Highlight flight on map (TSD symbology)
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
                    { label: 'Amend Route', action: function(flight) {
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
