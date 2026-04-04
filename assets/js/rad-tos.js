/**
 * RAD TOS Module — TOS entry form (ATC/Pilot/VA) + resolution panel (TMU).
 *
 * Public API:
 *   RADTOS.showEntryForm(amendment)
 *   RADTOS.showResolutionPanel(amendment)
 */
window.RADTOS = (function() {
    var TOS_COLORS = ['#32CD32','#FFD700','#4ECDC4','#FF6347','#9370DB','#00BFFF','#FFA500','#FF69B4'];

    function showEntryForm(amendment) {
        var filed = amendment.original_route || amendment.filed_route || '';
        var assigned = amendment.assigned_route || '';

        var html = '<div class="rad-tos-entry">';
        html += '<h6>' + PERTII18n.t('rad.tos.title') + '</h6>';

        // Auto options
        html += '<div class="rad-tos-option" data-type="as_filed">';
        html += '<span class="rad-tos-rank">#1</span>';
        html += '<span class="rad-tos-type">' + PERTII18n.t('rad.tos.asOriginallyFiled') + '</span>';
        html += '<div class="rad-tos-route text-monospace">' + filed + '</div>';
        html += '</div>';

        html += '<div class="rad-tos-option" data-type="as_amended">';
        html += '<span class="rad-tos-rank">#3</span>';
        html += '<span class="rad-tos-type">' + PERTII18n.t('rad.tos.asAmended') + '</span>';
        html += '<div class="rad-tos-route text-monospace">' + assigned + '</div>';
        html += '</div>';

        // Pilot options
        html += '<div id="tos_pilot_options"></div>';
        html += '<button class="btn btn-sm btn-outline-secondary mt-1 mb-2" id="tos_add_option">' + PERTII18n.t('rad.tos.addOption') + '</button>';

        // Actions
        html += '<div class="d-flex mt-2">';
        html += '<button class="btn btn-sm btn-warning mr-1" id="tos_submit">' + PERTII18n.t('rad.tos.submitTos') + '</button>';
        html += '<button class="btn btn-sm btn-outline-danger" id="tos_reject_no_tos">' + PERTII18n.t('rad.tos.rejectWithoutTos') + '</button>';
        html += '</div>';
        html += '</div>';

        Swal.fire({
            title: PERTII18n.t('rad.tos.title'),
            html: html,
            width: '70%',
            showConfirmButton: false,
            showCloseButton: true,
            background: '#1a1a2e',
            didOpen: function() {
                var optionCount = 0;

                $('#tos_add_option').on('click', function() {
                    optionCount++;
                    var rank = optionCount + 1;
                    var optHtml = '<div class="rad-tos-option rad-tos-pilot-option" data-type="pilot_option">';
                    optHtml += '<span class="rad-tos-rank">#' + rank + '</span>';
                    optHtml += '<input type="text" class="form-control form-control-sm tos-route-input" placeholder="Enter route string...">';
                    optHtml += '<button class="btn btn-sm btn-outline-danger tos-remove-opt ml-1">&times;</button>';
                    optHtml += '</div>';
                    $('#tos_pilot_options').append(optHtml);
                });

                $(document).on('click', '.tos-remove-opt', function() {
                    $(this).closest('.rad-tos-pilot-option').remove();
                });

                $('#tos_submit').on('click', function() {
                    var options = [
                        { rank: 1, route_string: filed, option_type: 'as_filed' },
                        { rank: 3, route_string: assigned, option_type: 'as_amended' }
                    ];
                    var pilotRank = 2;
                    $('.rad-tos-pilot-option').each(function() {
                        var route = $(this).find('.tos-route-input').val().trim();
                        if (route) {
                            options.push({ rank: pilotRank++, route_string: route, option_type: 'pilot_option' });
                        }
                    });

                    // Re-rank sequentially
                    options.sort(function(a, b) { return a.rank - b.rank; });
                    options.forEach(function(o, i) { o.rank = i + 1; });

                    $.ajax({
                        url: 'api/rad/tos.php',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            amendment_id: amendment.id,
                            options: options
                        })
                    })
                    .done(function(r) {
                        if (r.status === 'ok') {
                            PERTIDialog.success(PERTII18n.t('rad.tos.submitted'));
                            Swal.close();
                            RADEventBus.emit('tos:submitted', { tosId: r.data.tos_id, amendmentId: amendment.id });
                        } else {
                            PERTIDialog.warning(r.message);
                        }
                    })
                    .fail(function() { PERTIDialog.warning(PERTII18n.t('error.networkError')); });
                });

                $('#tos_reject_no_tos').on('click', function() {
                    $.post('api/rad/amendment.php', { id: amendment.id, action: 'reject' })
                        .done(function(r) {
                            if (r.status === 'ok') {
                                PERTIDialog.success(PERTII18n.t('rad.status.RJCT'));
                                Swal.close();
                                RADEventBus.emit('amendment:sent');
                            } else {
                                PERTIDialog.warning(r.message);
                            }
                        });
                });
            }
        });
    }

    function showResolutionPanel(amendment) {
        // Fetch TOS data + route metrics
        $.get('api/rad/tos.php', { amendment_id: amendment.id })
            .done(function(response) {
                if (response.status !== 'ok' || !response.data) {
                    PERTIDialog.warning(PERTII18n.t('rad.tos.noOptions'));
                    return;
                }
                var tos = response.data;
                var routes = tos.options.map(function(o) { return o.route_string; });

                // Fetch metrics for all routes
                $.ajax({
                    url: 'api/rad/route-metrics.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ routes: routes })
                }).done(function(metricsResp) {
                    var metricsData = (metricsResp.data && metricsResp.data.metrics) || [];
                    renderResolutionDialog(amendment, tos, metricsData);
                }).fail(function() {
                    renderResolutionDialog(amendment, tos, []);
                });
            })
            .fail(function() {
                PERTIDialog.warning(PERTII18n.t('error.networkError'));
            });
    }

    function renderResolutionDialog(amendment, tos, metrics) {
        var baselineDistance = metrics.length > 0 && metrics[0].distance_nm ? metrics[0].distance_nm : null;
        var baselineTime = metrics.length > 0 && metrics[0].time_minutes ? metrics[0].time_minutes : null;

        var html = '<div style="text-align:left;">';

        // Header
        html += '<div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">';
        html += '<span style="color:#00BFFF; font-weight:700; font-family:monospace; font-size:1rem;">' + (amendment.callsign || '') + '</span>';
        html += '<span style="color:#89a;">' + (amendment.origin || '') + ' &rarr; ' + (amendment.dest || amendment.destination || '') + '</span>';
        html += '</div>';

        // Options header
        html += '<div style="margin-bottom:8px;"><span style="color:#89a; font-size:0.72rem; text-transform:uppercase; font-weight:600;">' + PERTII18n.t('rad.tos.pilotPreferences') + '</span></div>';

        tos.options.forEach(function(opt, idx) {
            var color = TOS_COLORS[idx % TOS_COLORS.length];
            var m = metrics[idx] || {};
            var dist = m.distance_nm ? Math.round(m.distance_nm) + ' nm' : '--';
            var time = m.time_minutes ? Math.floor(m.time_minutes / 60) + 'h ' + (m.time_minutes % 60) + 'm' : '--';

            var delta = '';
            if (baselineDistance && m.distance_nm && idx > 0) {
                var dNm = m.distance_nm - baselineDistance;
                var dMin = m.time_minutes && baselineTime ? m.time_minutes - baselineTime : null;
                var sign = dNm >= 0 ? '+' : '';
                delta = '<span style="color:#fd7e14;">' + sign + Math.round(dNm) + ' nm';
                if (dMin !== null) delta += ' / ' + sign + Math.round(dMin) + ' min';
                delta += '</span>';
            } else if (idx === 0) {
                delta = '<span style="color:#28a745;">' + PERTII18n.t('rad.tos.baseline') + '</span>';
            }

            var typeLabel = opt.option_type === 'as_filed' ? PERTII18n.t('rad.tos.asOriginallyFiled')
                : opt.option_type === 'as_amended' ? PERTII18n.t('rad.tos.asAmended')
                : PERTII18n.t('rad.tos.pilotOption');

            html += '<div class="rad-tos-option">';
            html += '<span class="rad-tos-rank">#' + opt.rank + '</span>';
            html += '<div style="width:14px; height:14px; border-radius:2px; background:' + color + '; cursor:pointer; flex-shrink:0;" class="tos-color-swatch" data-route="' + (opt.route_string || '').replace(/"/g, '&quot;') + '" data-color="' + color + '"></div>';
            html += '<div style="flex:1;">';
            html += '<div class="rad-tos-route">' + (opt.route_string || '') + '</div>';
            html += '<div style="display:flex; gap:16px; font-size:0.75rem;">';
            html += '<span style="color:#89a;">' + typeLabel + '</span>';
            html += '<span style="color:#89a;">' + dist + '</span>';
            html += '<span style="color:#89a;">' + time + '</span>';
            html += delta;
            html += '</div></div>';
            html += '<button class="btn btn-sm btn-outline-success tos-accept-btn" data-tos-id="' + tos.id + '" data-rank="' + opt.rank + '">' + PERTII18n.t('rad.tos.accept') + '</button>';
            html += '</div>';
        });

        // Map legend
        html += '<div style="background:#16213e; border:1px solid #334; border-radius:4px; padding:8px 12px; margin:12px 0; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">';
        html += '<span style="color:#89a; font-size:0.75rem; text-transform:uppercase; font-weight:600;">' + PERTII18n.t('rad.tos.mapLegend') + '</span>';
        tos.options.forEach(function(opt, idx) {
            var c = TOS_COLORS[idx % TOS_COLORS.length];
            html += '<div style="display:flex; gap:4px; align-items:center;"><div style="width:10px; height:10px; border-radius:2px; background:' + c + ';"></div><span style="color:#ccc; font-size:0.75rem;">#' + opt.rank + '</span></div>';
        });
        html += '<button class="btn btn-sm btn-outline-secondary tos-plot-all ml-auto" style="font-size:0.72rem;">' + PERTII18n.t('rad.tos.plotAll') + '</button>';
        html += '<button class="btn btn-sm btn-outline-secondary tos-clear-plots" style="font-size:0.72rem;">' + PERTII18n.t('rad.tos.clear') + '</button>';
        html += '</div>';

        // Actions
        html += '<div style="border-top:1px solid #334; padding-top:12px; display:flex; gap:8px;">';
        html += '<button class="btn btn-sm btn-outline-info tos-counter-btn">' + PERTII18n.t('rad.tos.counterPropose') + '</button>';
        html += '<button class="btn btn-sm btn-outline-danger tos-force-btn" data-tos-id="' + tos.id + '">' + PERTII18n.t('rad.tos.forceOriginal') + '</button>';
        html += '</div>';

        html += '</div>';

        Swal.fire({
            title: PERTII18n.t('rad.tos.resolve'),
            html: html,
            width: '80%',
            showConfirmButton: false,
            showCloseButton: true,
            background: '#1a1a2e',
            didOpen: function() {
                // Accept button
                $('.tos-accept-btn').on('click', function() {
                    var tosId = $(this).data('tos-id');
                    var rank = $(this).data('rank');
                    $.ajax({
                        url: 'api/rad/tos.php',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ action: 'resolve', tos_id: tosId, resolve_action: 'ACCEPT', accepted_rank: rank })
                    }).done(function(r) {
                        if (r.status === 'ok') { PERTIDialog.success(PERTII18n.t('rad.tos.resolved')); Swal.close(); RADEventBus.emit('tos:resolved', r.data); }
                        else PERTIDialog.warning(r.message);
                    });
                });

                // Force button
                $('.tos-force-btn').on('click', function() {
                    var tosId = $(this).data('tos-id');
                    PERTIDialog.confirm(PERTII18n.t('rad.tos.forceOriginal') + '?').then(function(res) {
                        if (res.isConfirmed) {
                            $.ajax({
                                url: 'api/rad/tos.php',
                                method: 'POST',
                                contentType: 'application/json',
                                data: JSON.stringify({ action: 'resolve', tos_id: tosId, resolve_action: 'FORCE' })
                            }).done(function(r) {
                                if (r.status === 'ok') { PERTIDialog.success(PERTII18n.t('rad.tos.forced')); Swal.close(); RADEventBus.emit('tos:resolved', r.data); }
                                else PERTIDialog.warning(r.message);
                            });
                        }
                    });
                });

                // Color swatch: plot single route
                $('.tos-color-swatch').on('click', function() {
                    var route = $(this).data('route');
                    var color = $(this).data('color');
                    if (route && window.MapLibreRoute) {
                        var $ta = $('#routeSearch');
                        var existing = $ta.val().trim();
                        var line = route + ';' + color;
                        $ta.val(existing ? existing + '\n' + line : line);
                        MapLibreRoute.processRoutes();
                    }
                });

                // Plot all
                $('.tos-plot-all').on('click', function() {
                    var lines = [];
                    tos.options.forEach(function(opt, idx) {
                        if (opt.route_string) lines.push(opt.route_string + ';' + TOS_COLORS[idx % TOS_COLORS.length]);
                    });
                    $('#routeSearch').val(lines.join('\n'));
                    if (window.MapLibreRoute) MapLibreRoute.processRoutes();
                });

                // Clear
                $('.tos-clear-plots').on('click', function() {
                    $('#routeSearch').val('');
                    if (window.MapLibreRoute) MapLibreRoute.processRoutes();
                });

                // Counter-propose
                $('.tos-counter-btn').on('click', function() {
                    Swal.close();
                    $('#tab-edit').tab('show');
                });
            }
        });
    }

    return {
        showEntryForm: showEntryForm,
        showResolutionPanel: showResolutionPanel
    };
})();
