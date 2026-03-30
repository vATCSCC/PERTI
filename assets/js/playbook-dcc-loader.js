/**
 * PlaybookDccLoader — Injects non-FAA plays (DCC/ECFMP/CANOC) into the global
 * playbookByPlayName / playbookRoutes objects so that PB. directives resolve
 * on both route.php and playbook.php.
 *
 * Must be loaded AFTER route-maplibre.js (which creates the globals from CSV).
 *
 * Uses a single bulk_routes.php request instead of N+1 individual get.php calls.
 */
(function() {
    'use strict';

    var API_BULK = 'api/data/playbook/bulk_routes.php';

    function csvSplit(s) {
        return (s || '').split(',').map(function(x) { return x.trim().toUpperCase(); }).filter(Boolean);
    }

    function normalizePlayName(name) {
        return (name || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    function injectPlay(play, routes) {
        if (!window.playbookByPlayName) window.playbookByPlayName = {};
        if (!window.playbookRoutes) window.playbookRoutes = [];

        var norm = normalizePlayName(play.play_name);

        // Skip if already loaded (e.g. from FAA CSV)
        if (window.playbookByPlayName[norm] && window.playbookByPlayName[norm].length) return;

        window.playbookByPlayName[norm] = [];

        routes.forEach(function(r) {
            var entry = {
                playName: play.play_name,
                playNameNorm: norm,
                fullRoute: (r.route_string || '').toUpperCase(),
                // Set forms — used by expandPlaybookDirective() in route-maplibre.js
                originAirportsSet: new Set(csvSplit(r.origin_airports)),
                originTraconsSet:  new Set(csvSplit(r.origin_tracons)),
                originArtccsSet:   new Set(csvSplit(r.origin_artccs)),
                destAirportsSet:   new Set(csvSplit(r.dest_airports)),
                destTraconsSet:    new Set(csvSplit(r.dest_tracons)),
                destArtccsSet:     new Set(csvSplit(r.dest_artccs)),
                originField: r.origin || '',
                destField:   r.dest || '',
                // Array forms — used by playbook-cdr-search.js
                originAirports: csvSplit(r.origin_airports),
                originTracons:  csvSplit(r.origin_tracons),
                originArtccs:   csvSplit(r.origin_artccs),
                destAirports:   csvSplit(r.dest_airports),
                destTracons:    csvSplit(r.dest_tracons),
                destArtccs:     csvSplit(r.dest_artccs)
            };
            window.playbookRoutes.push(entry);
            window.playbookByPlayName[norm].push(entry);
        });
    }

    function loadNonFaaPlays() {
        // Single bulk request replaces the old pattern of 1 list.php + 95 get.php calls
        $.getJSON(API_BULK + '?source_exclude=FAA&hide_legacy=1', function(data) {
            if (!data || !data.success || !data.plays) return;

            console.log('[DCC-Loader] Injecting', data.plays.length, 'non-FAA plays (' + data.count + ' with routes)');

            data.plays.forEach(function(p) {
                if (p.routes && p.routes.length) {
                    injectPlay(p, p.routes);
                }
            });
        });
    }

    // Wait for playbookByPlayName to be initialized by route-maplibre.js CSV load,
    // then inject non-FAA plays on top of it.
    var attempts = 0;
    var timer = setInterval(function() {
        attempts++;
        if (window.playbookByPlayName || attempts > 50) {
            clearInterval(timer);
            // Ensure globals exist even if CSV load failed
            if (!window.playbookByPlayName) window.playbookByPlayName = {};
            if (!window.playbookRoutes) window.playbookRoutes = [];
            loadNonFaaPlays();
        }
    }, 200);
})();
