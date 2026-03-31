/**
 * RAD Amendment Module
 * Route editing, validation, and amendment creation
 */
window.RADAmendment = (function() {
    var currentFlights = [];
    var currentRoute = '';

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

        $('#rad_manual_route').on('input', function() {
            currentRoute = $(this).val().trim();
            updatePreview();
        });

        $('#rad_route_color').on('change', function() {
            if (currentRoute) {
                RADEventBus.emit('route:clear', { id: 'rad-current-route' });
                plotRoute();
            }
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
                if (response.success) {
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

                    $(document).on('click', '.rad-route-row', function() {
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
        // Try to use PlaybookCDRSearch if available
        if (window.PlaybookCDRSearch && typeof window.PlaybookCDRSearch.open === 'function') {
            window.PlaybookCDRSearch.open(function(route) {
                setRoute(route);
            });
        } else {
            // Fallback to API
            var gufi = currentFlights.length > 0 ? currentFlights[0].gufi : null;
            if (!gufi) {
                PERTIDialog.warning(PERTII18n.t('rad.amendment.selectFlightFirst'));
                return;
            }

            $.get('api/rad/routes.php', { source: 'options', gufi: gufi })
                .done(function(response) {
                    if (response.success) {
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
                if (response.success && response.data) {
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
                if (response.success) {
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
                if (response.success) {
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

        $(document).on('click', '.rad-route-row', function() {
            setRoute($(this).data('route'));
            Swal.close();
        });
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
            html += '<table class="table table-sm table-striped">';
            html += '<thead><tr><th>' + PERTII18n.t('common.callsign') + '</th><th>' + PERTII18n.t('rad.amendment.currentRoute') + '</th></tr></thead><tbody>';
            currentFlights.forEach(function(flight) {
                html += '<tr><td>' + flight.callsign + '</td><td class="text-monospace">' + (flight.route || '') + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        $('#rad_current_routes').html(html);
        updatePreview();
    }

    function updatePreview() {
        if (currentFlights.length === 0 || !currentRoute) {
            $('#rad_amendment_preview').html('<div class="text-muted">' + PERTII18n.t('rad.amendment.noPreview') + '</div>');
            return;
        }

        var html = '<table class="table table-sm table-striped">';
        html += '<thead><tr><th>' + PERTII18n.t('common.callsign') + '</th><th>' + PERTII18n.t('rad.amendment.original') + '</th><th>' + PERTII18n.t('rad.amendment.assigned') + '</th><th>' + PERTII18n.t('rad.amendment.diff') + '</th></tr></thead><tbody>';

        currentFlights.forEach(function(flight) {
            var original = flight.route || '';
            var diff = generateDiff(original, currentRoute);

            html += '<tr>' +
                '<td>' + flight.callsign + '</td>' +
                '<td class="text-monospace">' + original + '</td>' +
                '<td class="text-monospace">' + currentRoute + '</td>' +
                '<td class="text-monospace">' + diff + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        $('#rad_amendment_preview').html(html);
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

    function loadTMIPrograms() {
        $.get('api/tmi/gdp_preview.php')
            .done(function(response) {
                if (response.success && response.data) {
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
                if (response.success) {
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
                            if (response.success) {
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

        var route = $('#rad_manual_route').val().trim();
        if (!route) {
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

        return {
            action: action,
            flights: currentFlights.map(function(f) { return f.gufi; }),
            route: route,
            channels: channels,
            tmi_id: $('#rad_tmi_assoc').val() || null
        };
    }

    function clearForm() {
        currentRoute = '';
        $('#rad_manual_route').val('');
        $('#rad_cdr_code').val('');
        $('#rad_amendment_preview').html('<div class="text-muted">' + PERTII18n.t('rad.amendment.noPreview') + '</div>');
        RADEventBus.emit('route:clear', { id: 'rad-current-route' });
    }

    return {
        init: init,
        setRoute: setRoute
    };
})();
