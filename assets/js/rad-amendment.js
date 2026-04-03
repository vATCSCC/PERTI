/**
 * RAD Amendment Module
 * Route editing, validation, and amendment creation
 */
window.RADAmendment = (function() {
    var currentFlights = [];
    var currentRoute = '';
    var perFlightRoutes = {}; // { gufi: computedRoute } for substring replace
    var autoPlotEnabled = true;
    var distanceCache = {};
    var distanceTimer = null;

    function init() {
        bindEvents();

        // Listen for flight selections
        RADEventBus.on('flight:selected', function(data) {
            if (!currentFlights.some(function(f) { return f.gufi === data.gufi; })) {
                currentFlights.push(data);
                updateCurrentRoutes();
            }
        });

        RADEventBus.on('flight:deselected', function(data) {
            currentFlights = currentFlights.filter(function(f) { return f.gufi !== data.gufi; });
            updateCurrentRoutes();
        });

        // Initialize delivery channels
        $('#rad_ch_cpdlc').prop('checked', true);
        $('#rad_ch_swim').prop('checked', true);
    }

    function bindEvents() {
        $('#rad_btn_recent').on('click', showRecentRoutes);
        $('#rad_btn_search_db').on('click', searchDatabase);
        $('#rad_btn_get_cdr').on('click', getCDR);
        $('#rad_btn_validate').on('click', validateRoute);
        $('#rad_btn_plot').on('click', plotRoute);
        $('#rad_btn_route_options').on('click', showRouteOptions);
        $('#rad_btn_save_draft').on('click', saveDraft);
        $('#rad_btn_send_amendment').on('click', sendAmendment);

        $('#rad_btn_apply_substr').on('click', applySubstring);

        // Clear computed per-flight routes when find/replace inputs change
        $('#rad_find, #rad_replace').on('input', function() {
            perFlightRoutes = {};
            updatePreview();
        });

        $('#rad_manual_route').on('input', function() {
            currentRoute = $(this).val().trim();
            updatePreview();
        });

        // Color palette swatch click
        $(document).on('click', '.rad-color-swatch', function() {
            $('.rad-color-swatch').removeClass('active');
            $(this).addClass('active');
            $('#rad_route_color').val($(this).data('color'));
            autoPlotRoutes();
        });

        // Load TMI programs
        loadTMIPrograms();
    }

    function showRecentRoutes() {
        var origin = '';
        var destination = '';
        if (currentFlights.length > 0) {
            origin = currentFlights[0].origin || '';
            destination = currentFlights[0].dest || '';
        }

        $.get('api/rad/routes.php', { source: 'recent', origin: origin, destination: destination })
            .done(function(response) {
                if (response.status === 'ok') {
                    var routes = response.data || [];
                    if (routes.length === 0) {
                        PERTIDialog.warning(PERTII18n.t('rad.amendment.noRecentRoutes'));
                        return;
                    }

                    var html = '<table class="table table-sm table-hover">' +
                        '<thead><tr><th>' + PERTII18n.t('rad.amendment.route') + '</th><th>' + PERTII18n.t('rad.amendment.usage') + '</th></tr></thead>' +
                        '<tbody>';

                    routes.forEach(function(route) {
                        html += '<tr class="rad-route-row" data-route="' + route.route_string + '">' +
                            '<td class="text-monospace">' + route.route_string + '</td>' +
                            '<td>' + route.usage_count + '</td>' +
                            '</tr>';
                    });

                    html += '</tbody></table>';

                    Swal.fire({
                        title: PERTII18n.t('rad.amendment.recentRoutes'),
                        html: html,
                        width: '80%',
                        showCloseButton: true,
                        showConfirmButton: false
                    });

                    $(document).off('click.radRoute').on('click.radRoute', '.rad-route-row', function() {
                        setRoute($(this).data('route'));
                        Swal.close();
                    });
                }
            })
            .fail(function() {
                PERTIDialog.warning(PERTII18n.t('error.networkError'));
            });
    }

    function searchDatabase() {
        // Open the Playbook/CDR/Preferred panel if available
        var panel = document.getElementById('pbcdr_search_panel');
        if (panel && window.PlaybookCDRSearch) {
            panel.classList.add('show');
            PlaybookCDRSearch.init();
            PlaybookCDRSearch.setSearchType('all');
            // Activate the "All" tab visually
            $('.pbcdr-tab').removeClass('active');
            $('.pbcdr-tab[data-tab="all"]').addClass('active');
        } else {
            // Fallback to API
            var gufi = currentFlights.length > 0 ? currentFlights[0].gufi : null;
            if (!gufi) {
                PERTIDialog.warning(PERTII18n.t('rad.amendment.selectFlightFirst'));
                return;
            }

            $.get('api/rad/routes.php', { source: 'options', gufi: gufi })
                .done(function(response) {
                    if (response.status === 'ok') {
                        showRouteOptionsDialog(response.data || []);
                    }
                })
                .fail(function() {
                    PERTIDialog.warning(PERTII18n.t('error.networkError'));
                });
        }
    }

    function getCDR() {
        var code = $('#rad_cdr_code').val().trim();
        if (!code) {
            PERTIDialog.warning(PERTII18n.t('rad.amendment.enterCDRCode'));
            return;
        }

        $.get('api/rad/routes.php', { source: 'cdr', code: code })
            .done(function(response) {
                if (response.status === 'ok' && response.data) {
                    setRoute(response.data.route_string);
                } else {
                    PERTIDialog.warning(response.message || PERTII18n.t('rad.amendment.cdrNotFound'));
                }
            })
            .fail(function() {
                PERTIDialog.warning(PERTII18n.t('error.networkError'));
            });
    }

    function validateRoute() {
        var route = $('#rad_manual_route').val().trim();
        if (!route) {
            PERTIDialog.warning(PERTII18n.t('rad.amendment.enterRoute'));
            return;
        }

        $.post('api/rad/routes.php', { route: route, action: 'validate' })
            .done(function(response) {
                if (response.status === 'ok') {
                    Swal.fire({
                        icon: 'success',
                        title: PERTII18n.t('rad.amendment.validRoute'),
                        text: response.message || PERTII18n.t('rad.amendment.routeValid'),
                        toast: true,
                        position: 'top-end',
                        timer: 3000,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: PERTII18n.t('rad.amendment.invalidRoute'),
                        text: response.message || PERTII18n.t('rad.amendment.routeInvalid'),
                        toast: true,
                        position: 'top-end',
                        timer: 5000,
                        showConfirmButton: false
                    });
                }
            })
            .fail(function() {
                PERTIDialog.warning(PERTII18n.t('error.networkError'));
            });
    }

    function plotRoute() {
        var route = $('#rad_manual_route').val().trim();
        if (!route) {
            PERTIDialog.warning(PERTII18n.t('rad.amendment.enterRoute'));
            return;
        }

        var color = $('#rad_route_color').val() || '#FF6600';

        RADEventBus.emit('route:plot', {
            routeString: route,
            color: color,
            id: 'rad-current-route'
        });

        PERTIDialog.success(PERTII18n.t('rad.amendment.routePlotted'));
    }

    function showRouteOptions() {
        if (currentFlights.length === 0) {
            PERTIDialog.warning(PERTII18n.t('rad.amendment.selectFlightFirst'));
            return;
        }

        var gufi = currentFlights[0].gufi;

        $.get('api/rad/routes.php', { source: 'options', gufi: gufi })
            .done(function(response) {
                if (response.status === 'ok') {
                    showRouteOptionsDialog(response.data || []);
                }
            })
            .fail(function() {
                PERTIDialog.warning(PERTII18n.t('error.networkError'));
            });
    }

    function showRouteOptionsDialog(options) {
        var html = '<div class="rad-route-options">';

        // TMI Routes section
        if (options.tmi_routes && options.tmi_routes.length > 0) {
            html += '<h5>' + PERTII18n.t('rad.amendment.tmiRoutes') + '</h5>';
            html += '<table class="table table-sm table-hover mb-4">';
            html += '<thead><tr><th>' + PERTII18n.t('rad.amendment.route') + '</th><th>' + PERTII18n.t('rad.amendment.tmi') + '</th></tr></thead><tbody>';
            options.tmi_routes.forEach(function(route) {
                html += '<tr class="rad-route-row" data-route="' + route.route_string + '">' +
                    '<td class="text-monospace">' + route.route_string + '</td>' +
                    '<td>' + route.tmi_name + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
        }

        // Playbook routes
        if (options.playbook_routes && options.playbook_routes.length > 0) {
            html += '<h5>' + PERTII18n.t('rad.amendment.playbookRoutes') + '</h5>';
            html += '<table class="table table-sm table-hover mb-4">';
            html += '<thead><tr><th>' + PERTII18n.t('rad.amendment.route') + '</th><th>' + PERTII18n.t('rad.amendment.type') + '</th></tr></thead><tbody>';
            options.playbook_routes.forEach(function(route) {
                html += '<tr class="rad-route-row" data-route="' + route.route_string + '">' +
                    '<td class="text-monospace">' + route.route_string + '</td>' +
                    '<td>' + route.route_type + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
        }

        html += '</div>';

        Swal.fire({
            title: PERTII18n.t('rad.amendment.routeOptions'),
            html: html,
            width: '80%',
            showCloseButton: true,
            showConfirmButton: false
        });

        $(document).off('click.radRoute').on('click.radRoute', '.rad-route-row', function() {
            setRoute($(this).data('route'));
            Swal.close();
        });
    }

    function applySubstring() {
        var find = $('#rad_find').val().trim().toUpperCase();
        var replace = $('#rad_replace').val().trim().toUpperCase();
        if (!find) {
            PERTIDialog.warning(PERTII18n.t('rad.edit.enterFindPattern'));
            return;
        }
        if (currentFlights.length === 0) {
            PERTIDialog.warning(PERTII18n.t('rad.amendment.selectFlightFirst'));
            return;
        }

        perFlightRoutes = {};
        var notFound = [];
        var applied = 0;

        currentFlights.forEach(function(flight) {
            var route = (flight.route || '').toUpperCase();
            if (route.indexOf(find) === -1) {
                notFound.push(flight.callsign);
            } else {
                perFlightRoutes[flight.gufi] = route.replace(find, replace);
                applied++;
            }
        });

        if (notFound.length > 0) {
            PERTIDialog.warning(PERTII18n.t('rad.edit.patternNotFound', { callsigns: notFound.join(', ') }));
        }
        if (applied > 0) {
            PERTIDialog.success(PERTII18n.t('rad.edit.substringApplied', { count: applied }));
        }

        updatePreview();
    }

    /**
     * Auto-plot original routes (gray) and amended routes (user color) on the map.
     * Directly sets #routeSearch textarea and calls processRoutes().
     */
    function autoPlotRoutes() {
        if (!autoPlotEnabled || currentFlights.length === 0) return;

        var lines = [];
        var hasPerFlight = Object.keys(perFlightRoutes).length > 0;
        var amendColor = $('#rad_route_color').val() || '#FF6600';

        // Original filed routes in gray
        currentFlights.forEach(function(flight) {
            if (flight.route) {
                lines.push(flight.route + ';#808080');
            }
        });

        // Amended routes in user-specified color
        if (hasPerFlight) {
            Object.keys(perFlightRoutes).forEach(function(gufi) {
                if (perFlightRoutes[gufi]) {
                    lines.push(perFlightRoutes[gufi] + ';' + amendColor);
                }
            });
        } else if (currentRoute) {
            lines.push(currentRoute + ';' + amendColor);
        }

        $('#routeSearch').val(lines.join('\n'));
        if (window.MapLibreRoute) {
            window.MapLibreRoute.processRoutes();
        }
    }

    // =========================================================================
    // Route distance helpers
    // =========================================================================

    function buildFullRoute(origin, routeStr, dest) {
        var full = (routeStr || '').trim();
        if (!full) return '';
        if (origin) {
            var first = full.split(/\s+/)[0] || '';
            if (first.toUpperCase() !== origin.toUpperCase()) full = origin + ' ' + full;
        }
        if (dest) {
            var parts = full.split(/\s+/);
            var last = parts[parts.length - 1] || '';
            if (last.toUpperCase() !== dest.toUpperCase()) full = full + ' ' + dest;
        }
        return full.toUpperCase();
    }

    function fetchDistances(routes, callback) {
        var uncached = routes.filter(function(r) { return r && distanceCache[r] === undefined; });
        if (uncached.length === 0) { callback(); return; }

        $.ajax({
            url: 'api/rad/distance.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ routes: uncached })
        }).done(function(response) {
            if (response.status === 'ok' && response.data) {
                for (var key in response.data) {
                    distanceCache[key] = response.data[key];
                }
            }
            uncached.forEach(function(r) {
                if (distanceCache[r] === undefined) distanceCache[r] = null;
            });
            callback();
        }).fail(function() {
            uncached.forEach(function(r) { distanceCache[r] = null; });
            callback();
        });
    }

    function debouncedComputeDeltas() {
        clearTimeout(distanceTimer);
        distanceTimer = setTimeout(computeDeltas, 800);
    }

    function computeDeltas() {
        var hasPerFlight = Object.keys(perFlightRoutes).length > 0;
        if (currentFlights.length === 0) return;

        var routeStrings = [];
        currentFlights.forEach(function(flight) {
            var original = flight.route || '';
            var assigned = hasPerFlight ? (perFlightRoutes[flight.gufi] || '') : currentRoute;
            if (original) routeStrings.push(buildFullRoute(flight.origin, original, flight.dest));
            if (assigned) routeStrings.push(buildFullRoute(flight.origin, assigned, flight.dest));
        });

        // Deduplicate
        routeStrings = routeStrings.filter(function(v, i, a) { return v && a.indexOf(v) === i; });
        if (routeStrings.length === 0) return;

        fetchDistances(routeStrings, function() {
            currentFlights.forEach(function(flight) {
                var $cell = $('#rad_amendment_preview tr[data-gufi="' + flight.gufi + '"] .rad-delta-cell');
                if ($cell.length === 0) return;

                var original = flight.route || '';
                var assigned = hasPerFlight ? (perFlightRoutes[flight.gufi] || '') : currentRoute;
                var noMatch = hasPerFlight && !perFlightRoutes[flight.gufi];
                if (noMatch || !original || !assigned) { $cell.html('--'); return; }

                var origFull = buildFullRoute(flight.origin, original, flight.dest);
                var assignedFull = buildFullRoute(flight.origin, assigned, flight.dest);
                var origDist = distanceCache[origFull];
                var assignedDist = distanceCache[assignedFull];

                if (origDist == null || assignedDist == null) {
                    $cell.html('<span class="text-muted">--</span>');
                    return;
                }

                $cell.html(formatDelta(assignedDist - origDist, flight.ete_minutes, origDist));
            });
        });
    }

    function formatDelta(deltaNm, eteMinutes, origDist) {
        var deltaMin;
        if (eteMinutes && origDist > 0) {
            var avgSpeed = origDist / (eteMinutes / 60);
            deltaMin = deltaNm / avgSpeed * 60;
        } else {
            deltaMin = deltaNm / 7; // ~420 kts estimate
        }

        var sign = deltaNm >= 0 ? '+' : '';
        var color = deltaNm > 0 ? '#fd7e14' : (deltaNm < 0 ? '#28a745' : '#999');

        return '<span style="color:' + color + ';">' + sign + Math.round(deltaNm) + ' nm</span>' +
            '<br><span style="color:' + color + '; font-size:0.85em;">' + sign + Math.round(deltaMin) + ' min</span>';
    }

    function setRoute(routeString) {
        currentRoute = routeString;
        $('#rad_manual_route').val(routeString);
        updatePreview();
    }

    function updateCurrentRoutes() {
        var html = '';
        if (currentFlights.length === 0) {
            html = '<div class="text-muted">' + PERTII18n.t('rad.amendment.noFlightsSelected') + '</div>';
        } else {
            currentFlights.forEach(function(flight, idx) {
                var csColor = RADEventBus.callsignColor(flight.callsign);
                var bg = idx % 2 === 0 ? '#111' : '#0d1117';
                var statusBadge = flight.amendment_status
                    ? '<span class="rad-badge rad-badge-warning">' + flight.amendment_status + '</span>'
                    : '<span class="rad-badge rad-badge-default">&mdash;</span>';

                html += '<div class="rad-current-route-card" style="background:' + bg + ';">' +
                    '<span class="rad-cs" style="color:' + csColor + ';">' + (flight.callsign || '') + '</span>' +
                    '<span class="rad-current-route-text">' + (flight.route || '') + '</span>' +
                    statusBadge +
                    '</div>';
            });
        }
        $('#rad_current_routes').html(html);
        updatePreview();
    }

    function updatePreview() {
        var hasPerFlight = Object.keys(perFlightRoutes).length > 0;

        if (currentFlights.length === 0 || (!currentRoute && !hasPerFlight)) {
            $('#rad_amendment_preview').html('<div class="text-muted">' + PERTII18n.t('rad.amendment.noPreview') + '</div>');
            autoPlotRoutes();
            return;
        }

        var html = '<table class="table table-sm table-striped">';
        html += '<thead><tr><th>' + PERTII18n.t('common.callsign') + '</th><th>' + PERTII18n.t('rad.amendment.original') + '</th><th>' + PERTII18n.t('rad.amendment.assigned') + '</th><th>' + PERTII18n.t('rad.amendment.diff') + '</th><th>' + PERTII18n.t('rad.amendment.delta') + '</th></tr></thead><tbody>';

        currentFlights.forEach(function(flight) {
            var original = flight.route || '';
            var assigned = hasPerFlight ? (perFlightRoutes[flight.gufi] || '') : currentRoute;
            var diff = hasPerFlight ? generateSubstringDiff(original, assigned) : generateDiff(original, assigned);
            var noMatch = hasPerFlight && !perFlightRoutes[flight.gufi];

            html += '<tr data-gufi="' + flight.gufi + '"' + (noMatch ? ' class="text-muted"' : '') + '>' +
                '<td>' + flight.callsign + '</td>' +
                '<td class="text-monospace">' + original + '</td>' +
                '<td class="text-monospace">' + (noMatch ? '<em>' + PERTII18n.t('rad.monitoring.noMatch') + '</em>' : assigned) + '</td>' +
                '<td class="text-monospace">' + (noMatch ? '--' : diff) + '</td>' +
                '<td class="rad-delta-cell">' + (noMatch ? '--' : '<i class="fas fa-spinner fa-spin text-muted"></i>') + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        $('#rad_amendment_preview').html(html);

        autoPlotRoutes();
        debouncedComputeDeltas();
    }

    function generateDiff(original, assigned) {
        if (original === assigned) {
            return '<span class="text-muted">' + PERTII18n.t('rad.amendment.noChange') + '</span>';
        }

        var origParts = original.split(/\s+/);
        var assignParts = assigned.split(/\s+/);

        var removed = origParts.filter(function(p) { return assignParts.indexOf(p) === -1; });
        var added = assignParts.filter(function(p) { return origParts.indexOf(p) === -1; });

        var html = '';
        if (removed.length > 0) {
            html += '<span style="color:red;text-decoration:line-through;">' + removed.join(' ') + '</span> ';
        }
        if (added.length > 0) {
            html += '<span style="color:green;">' + added.join(' ') + '</span>';
        }

        return html || '<span class="text-muted">' + PERTII18n.t('rad.amendment.reordered') + '</span>';
    }

    function generateSubstringDiff(original, assigned) {
        var find = $('#rad_find').val().trim().toUpperCase();
        var replace = $('#rad_replace').val().trim().toUpperCase();
        var origUpper = (original || '').toUpperCase();

        if (!find || origUpper.indexOf(find) === -1) {
            return generateDiff(original, assigned);
        }

        var idx = origUpper.indexOf(find);
        var prefix = original.substring(0, idx);
        var suffix = original.substring(idx + find.length);

        return prefix +
            '<span style="color:red;text-decoration:line-through;">' + find + '</span>' +
            '<span style="color:green;">' + replace + '</span>' +
            suffix;
    }

    function loadTMIPrograms() {
        $.get('api/tmi/gdp_preview.php')
            .done(function(response) {
                if (response.status === 'ok' && response.data) {
                    var select = $('#rad_tmi_assoc');
                    select.empty();
                    select.append('<option value="">' + PERTII18n.t('rad.amendment.noTMI') + '</option>');

                    (response.data || []).forEach(function(program) {
                        select.append('<option value="' + program.program_id + '">' + program.name + '</option>');
                    });
                }
            });
    }

    function saveDraft() {
        var payload = buildPayload('create');
        if (!payload) return;

        $.post('api/rad/amendment.php', payload)
            .done(function(response) {
                if (response.status === 'ok') {
                    PERTIDialog.success(PERTII18n.t('rad.amendment.draftSaved'));
                    RADEventBus.emit('amendment:created', { payload: payload, response: response.data });
                } else {
                    PERTIDialog.warning(response.message || PERTII18n.t('error.saveFailed'));
                }
            })
            .fail(function() {
                PERTIDialog.warning(PERTII18n.t('error.networkError'));
            });
    }

    function sendAmendment() {
        var payload = buildPayload('send');
        if (!payload) return;

        PERTIDialog.confirm(PERTII18n.t('rad.amendment.confirmSend', { count: currentFlights.length }))
            .then(function(result) {
                if (result.isConfirmed) {
                    $.post('api/rad/amendment.php', payload)
                        .done(function(response) {
                            if (response.status === 'ok') {
                                PERTIDialog.success(PERTII18n.t('rad.amendment.sent'));
                                RADEventBus.emit('amendment:sent', { payload: payload, response: response.data });
                                clearForm();
                            } else {
                                PERTIDialog.warning(response.message || PERTII18n.t('error.sendFailed'));
                            }
                        })
                        .fail(function() {
                            PERTIDialog.warning(PERTII18n.t('error.networkError'));
                        });
                }
            });
    }

    function buildPayload(action) {
        if (currentFlights.length === 0) {
            PERTIDialog.warning(PERTII18n.t('rad.amendment.selectFlightFirst'));
            return null;
        }

        var hasPerFlight = Object.keys(perFlightRoutes).length > 0;
        var route = $('#rad_manual_route').val().trim();

        if (!hasPerFlight && !route) {
            PERTIDialog.warning(PERTII18n.t('rad.amendment.enterRoute'));
            return null;
        }

        var channels = [];
        if ($('#rad_ch_cpdlc').prop('checked')) channels.push('CPDLC');
        if ($('#rad_ch_swim').prop('checked')) channels.push('SWIM');
        if ($('#rad_ch_discord').prop('checked')) channels.push('DISCORD');

        if (channels.length === 0) {
            PERTIDialog.warning(PERTII18n.t('rad.amendment.selectChannel'));
            return null;
        }

        var payload = {
            action: action,
            channels: channels,
            tmi_id: $('#rad_tmi_assoc').val() || null
        };

        if (hasPerFlight) {
            // Only send GUFIs that have matching routes (skip unmatched flights)
            payload.flights = Object.keys(perFlightRoutes);
            payload.routes = perFlightRoutes;  // { gufi: route }
        } else {
            payload.flights = currentFlights.map(function(f) { return f.gufi; });
            payload.route = route;  // single route (existing behavior)
        }

        return payload;
    }

    function clearForm() {
        currentRoute = '';
        perFlightRoutes = {};
        clearTimeout(distanceTimer);
        $('#rad_manual_route').val('');
        $('#rad_cdr_code').val('');
        $('#rad_find').val('');
        $('#rad_replace').val('');
        $('#rad_amendment_preview').html('<div class="text-muted">' + PERTII18n.t('rad.amendment.noPreview') + '</div>');
        // Re-plot: shows only original routes in gray (amendment routes cleared)
        autoPlotRoutes();
    }

    return {
        init: init,
        setRoute: setRoute
    };
})();
