/**
 * Historical Routes - Page Controller
 * Search and analyze historically filed flight plan routes
 */
(function() {
    'use strict';

    // ========================================================================
    // STATE MANAGEMENT
    // ========================================================================

    var state = {
        filters: {
            origins: [],
            destinations: [],
            origMode: 'airport',
            destMode: 'airport',
            // Aircraft filters
            aircraft: [],        // ICAO type codes
            manufacturer: [],    // manufacturer names
            weight: [],          // weight classes (S/L/H/J)
            wake: [],            // wake categories (L/M/H/J)
            engine: [],          // engine types (J/T/P/E)
            // Operator filters
            airline: [],         // airline ICAO codes
            callsignPrefix: '',  // 3-char prefix string
            operatorGroup: [],   // group names
            // Time filters
            dateFrom: '',        // ISO date string
            dateTo: '',          // ISO date string
            month: [],           // 1-12
            dayOfWeek: [],       // 1-7
            hourMin: '',         // 0-23
            hourMax: '',         // 0-23
            season: [],          // winter/spring/summer/fall
            year: []             // year numbers
        },
        results: null,
        selectedRoute: null,
        multiSelected: [],   // Array of route_dim_ids (max 6)
        page: 1,
        sort: 'frequency',
        view: 'grouped'
    };

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    $(document).ready(function() {
        console.log('[Routes] Initializing...');
        initFilterSections();
        initFilters();
        initSplitter();
        parseUrlState();

        // Initialize map
        if (typeof RoutesMap !== 'undefined') {
            RoutesMap.init('routes_map');
        }

        // If URL had filters, auto-search
        if (hasActiveFilters()) {
            console.log('[Routes] Found filters in URL, auto-searching');
            doSearch();
        }
    });

    // ========================================================================
    // SPLITTER INITIALIZATION
    // ========================================================================

    function initSplitter() {
        var $splitter = $('#routes_splitter');
        var $left = $('.routes-left-panel');
        var $right = $('.routes-right-panel');
        var isDragging = false;

        // Restore saved ratio
        var savedRatio = localStorage.getItem('routes_panel_ratio');
        if (savedRatio) {
            var ratio = parseFloat(savedRatio);
            if (ratio >= 0.2 && ratio <= 0.6) {
                var containerWidth = $('.routes-container').width();
                var newLeftWidth = Math.round(ratio * containerWidth);
                $left.css('width', newLeftWidth + 'px');
                $right.css('width', (containerWidth - newLeftWidth) + 'px');
                $left.css('flex', 'none');
                $right.css('flex', 'none');
                $left.css('max-width', 'none');
                $left.css('min-width', '300px');
                $('#routes_bottom_panel').css('left', newLeftWidth + 'px');
            }
        }

        $splitter.on('mousedown', function(e) {
            e.preventDefault();
            isDragging = true;
            $('body').addClass('routes-resizing');
        });

        $(document).on('mousemove', function(e) {
            if (!isDragging) return;
            var containerWidth = $('.routes-container').width();
            var newLeftWidth = e.pageX;
            var minLeft = 300;
            var minRight = 400;

            if (newLeftWidth < minLeft) newLeftWidth = minLeft;
            if (containerWidth - newLeftWidth < minRight) newLeftWidth = containerWidth - minRight;

            var ratio = newLeftWidth / containerWidth;
            $left.css('width', newLeftWidth + 'px');
            $left.css('flex', 'none');
            $left.css('max-width', 'none');
            $right.css('width', (containerWidth - newLeftWidth) + 'px');
            $right.css('flex', 'none');

            // Update bottom panel offset
            $('#routes_bottom_panel').css('left', newLeftWidth + 'px');

            localStorage.setItem('routes_panel_ratio', ratio.toFixed(3));
        });

        $(document).on('mouseup', function() {
            if (isDragging) {
                isDragging = false;
                $('body').removeClass('routes-resizing');
                // Resize map
                if (typeof RoutesMap !== 'undefined' && RoutesMap.getMap()) {
                    RoutesMap.getMap().resize();
                }
            }
        });
    }

    // ========================================================================
    // FILTER SECTIONS INITIALIZATION
    // ========================================================================

    function initFilterSections() {
        buildAircraftSection();
        buildOperatorSection();
        buildTimeSection();
    }

    /**
     * Collapse all expanded filter sections after search to maximize route list space.
     */
    function collapseFilters() {
        $('.routes-filter-section .collapse.show').each(function() {
            $(this).removeClass('show');
            var $header = $(this).siblings('.routes-filter-header');
            $header.attr('aria-expanded', 'false');
        });
    }

    function buildAircraftSection() {
        var $body = $('#filter_aircraft .routes-filter-body');
        $body.empty();

        // Type select2
        var $typeGroup = $('<div class="routes-filter-group"></div>');
        $typeGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.aircraftType') + '</label>');
        var $typeSelect = $('<select id="aircraft_type_select" multiple></select>');
        $typeGroup.append($typeSelect);
        $body.append($typeGroup);

        // Weight class checkboxes
        var $weightGroup = $('<div class="routes-filter-group"></div>');
        $weightGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.weightClass') + '</label>');
        var $weightCheckboxes = $('<div class="routes-checkbox-group"></div>');
        $weightCheckboxes.append(buildCheckbox('weight', 'S', PERTII18n.t('routes.filters.weightSmall')));
        $weightCheckboxes.append(buildCheckbox('weight', 'L', PERTII18n.t('routes.filters.weightLarge')));
        $weightCheckboxes.append(buildCheckbox('weight', 'H', PERTII18n.t('routes.filters.weightHeavy')));
        $weightCheckboxes.append(buildCheckbox('weight', 'J', PERTII18n.t('routes.filters.weightSuper')));
        $weightGroup.append($weightCheckboxes);
        $body.append($weightGroup);

        // Wake category checkboxes
        var $wakeGroup = $('<div class="routes-filter-group"></div>');
        $wakeGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.wakeCategory') + '</label>');
        var $wakeCheckboxes = $('<div class="routes-checkbox-group"></div>');
        $wakeCheckboxes.append(buildCheckbox('wake', 'L', PERTII18n.t('routes.filters.wakeLight')));
        $wakeCheckboxes.append(buildCheckbox('wake', 'M', PERTII18n.t('routes.filters.wakeMedium')));
        $wakeCheckboxes.append(buildCheckbox('wake', 'H', PERTII18n.t('routes.filters.wakeHeavy')));
        $wakeCheckboxes.append(buildCheckbox('wake', 'J', PERTII18n.t('routes.filters.wakeSuper')));
        $wakeGroup.append($wakeCheckboxes);
        $body.append($wakeGroup);

        // Engine type checkboxes
        var $engineGroup = $('<div class="routes-filter-group"></div>');
        $engineGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.engineType') + '</label>');
        var $engineCheckboxes = $('<div class="routes-checkbox-group"></div>');
        $engineCheckboxes.append(buildCheckbox('engine', 'J', PERTII18n.t('routes.filters.engineJet')));
        $engineCheckboxes.append(buildCheckbox('engine', 'T', PERTII18n.t('routes.filters.engineTurboprop')));
        $engineCheckboxes.append(buildCheckbox('engine', 'P', PERTII18n.t('routes.filters.enginePiston')));
        $engineCheckboxes.append(buildCheckbox('engine', 'E', PERTII18n.t('routes.filters.engineElectric')));
        $engineGroup.append($engineCheckboxes);
        $body.append($engineGroup);

        // Initialize Select2
        initSelect2Ajax('aircraft_type_select', 'aircraft', 'aircraft');
    }

    function buildOperatorSection() {
        var $body = $('#filter_operator .routes-filter-body');
        $body.empty();

        // Airline select2
        var $airlineGroup = $('<div class="routes-filter-group"></div>');
        $airlineGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.airline') + '</label>');
        var $airlineSelect = $('<select id="airline_select" multiple></select>');
        $airlineGroup.append($airlineSelect);
        $body.append($airlineGroup);

        // Callsign prefix input
        var $callsignGroup = $('<div class="routes-filter-group"></div>');
        $callsignGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.callsignPrefix') + '</label>');
        var $callsignInput = $('<input type="text" id="callsign_prefix_input" class="routes-filter-input" maxlength="3" placeholder="' + PERTII18n.t('routes.filters.callsignPlaceholder') + '">');
        $callsignInput.on('input', function() {
            var pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
            state.filters.callsignPrefix = this.value;
            renderFilterChips();
            updateClearButton();
        });
        $callsignGroup.append($callsignInput);
        $body.append($callsignGroup);

        // Operator group checkboxes
        var $groupGroup = $('<div class="routes-filter-group"></div>');
        $groupGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.operatorGroup') + '</label>');
        var $groupCheckboxes = $('<div class="routes-checkbox-group"></div>');
        $groupCheckboxes.append(buildCheckbox('operatorGroup', 'legacy_carrier', PERTII18n.t('routes.filters.opLegacy')));
        $groupCheckboxes.append(buildCheckbox('operatorGroup', 'regional', PERTII18n.t('routes.filters.opRegional')));
        $groupCheckboxes.append(buildCheckbox('operatorGroup', 'lcc', PERTII18n.t('routes.filters.opLcc')));
        $groupCheckboxes.append(buildCheckbox('operatorGroup', 'cargo', PERTII18n.t('routes.filters.opCargo')));
        $groupCheckboxes.append(buildCheckbox('operatorGroup', 'bizjet', PERTII18n.t('routes.filters.opBizjet')));
        $groupCheckboxes.append(buildCheckbox('operatorGroup', 'charter', PERTII18n.t('routes.filters.opCharter')));
        $groupCheckboxes.append(buildCheckbox('operatorGroup', 'military', PERTII18n.t('routes.filters.opMilitary')));
        $groupCheckboxes.append(buildCheckbox('operatorGroup', 'ga', PERTII18n.t('routes.filters.opGa')));
        $groupGroup.append($groupCheckboxes);
        $body.append($groupGroup);

        // Initialize Select2
        initSelect2Ajax('airline_select', 'operator', 'airline');
    }

    function buildTimeSection() {
        var $body = $('#filter_time .routes-filter-body');
        $body.empty();

        // Date range
        var $dateGroup = $('<div class="routes-filter-group"></div>');
        $dateGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.dateRange') + '</label>');
        var $dateRange = $('<div class="routes-date-range"></div>');
        var $dateFrom = $('<input type="date" id="date_from_input" class="routes-filter-input" style="flex:1">');
        var $dateTo = $('<input type="date" id="date_to_input" class="routes-filter-input" style="flex:1">');
        $dateFrom.on('change', function() {
            state.filters.dateFrom = this.value;
            renderFilterChips();
            updateClearButton();
        });
        $dateTo.on('change', function() {
            state.filters.dateTo = this.value;
            renderFilterChips();
            updateClearButton();
        });
        $dateRange.append($dateFrom, $dateTo);
        $dateGroup.append($dateRange);
        $body.append($dateGroup);

        // Year checkboxes
        var $yearGroup = $('<div class="routes-filter-group"></div>');
        $yearGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.year') + '</label>');
        var $yearCheckboxes = $('<div class="routes-checkbox-group"></div>');
        $yearCheckboxes.append(buildCheckbox('year', '2025', '2025'));
        $yearCheckboxes.append(buildCheckbox('year', '2026', '2026'));
        $yearGroup.append($yearCheckboxes);
        $body.append($yearGroup);

        // Month checkboxes
        var $monthGroup = $('<div class="routes-filter-group"></div>');
        $monthGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.month') + '</label>');
        var $monthCheckboxes = $('<div class="routes-checkbox-group"></div>');
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        for (var i = 0; i < months.length; i++) {
            $monthCheckboxes.append(buildCheckbox('month', String(i + 1), months[i]));
        }
        $monthGroup.append($monthCheckboxes);
        $body.append($monthGroup);

        // Day of week checkboxes
        var $dowGroup = $('<div class="routes-filter-group"></div>');
        $dowGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.dayOfWeek') + '</label>');
        var $dowCheckboxes = $('<div class="routes-checkbox-group"></div>');
        var days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        for (var j = 0; j < days.length; j++) {
            $dowCheckboxes.append(buildCheckbox('dayOfWeek', String(j + 1), days[j]));
        }
        $dowGroup.append($dowCheckboxes);
        $body.append($dowGroup);

        // Hour range
        var $hourGroup = $('<div class="routes-filter-group"></div>');
        $hourGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.hourRange') + '</label>');
        var $hourRange = $('<div class="routes-hour-range"></div>');
        var $hourMin = $('<input type="number" id="hour_min_input" class="routes-filter-input" min="0" max="23" placeholder="0" style="flex:1">');
        var $hourMax = $('<input type="number" id="hour_max_input" class="routes-filter-input" min="0" max="23" placeholder="23" style="flex:1">');
        $hourMin.on('change', function() {
            state.filters.hourMin = this.value;
            renderFilterChips();
            updateClearButton();
        });
        $hourMax.on('change', function() {
            state.filters.hourMax = this.value;
            renderFilterChips();
            updateClearButton();
        });
        $hourRange.append($hourMin, $hourMax);
        $hourGroup.append($hourRange);
        $body.append($hourGroup);

        // Season pills
        var $seasonGroup = $('<div class="routes-filter-group"></div>');
        $seasonGroup.append('<label class="routes-filter-label">' + PERTII18n.t('routes.filters.season') + '</label>');
        var $seasonPills = $('<div class="routes-season-pills"></div>');
        var seasons = [
            { value: 'winter', label: PERTII18n.t('routes.filters.seasonWinter') },
            { value: 'spring', label: PERTII18n.t('routes.filters.seasonSpring') },
            { value: 'summer', label: PERTII18n.t('routes.filters.seasonSummer') },
            { value: 'fall', label: PERTII18n.t('routes.filters.seasonFall') }
        ];
        seasons.forEach(function(season) {
            var $pill = $('<div class="routes-season-pill" data-value="' + season.value + '"></div>')
                .text(season.label)
                .on('click', function() {
                    var val = $(this).data('value');
                    var idx = state.filters.season.indexOf(val);
                    if (idx === -1) {
                        state.filters.season.push(val);
                        $(this).addClass('active');
                    } else {
                        state.filters.season.splice(idx, 1);
                        $(this).removeClass('active');
                    }
                    renderFilterChips();
                    updateClearButton();
                });
            $seasonPills.append($pill);
        });
        $seasonGroup.append($seasonPills);
        $body.append($seasonGroup);
    }

    function buildCheckbox(filterKey, value, label) {
        var $item = $('<label class="routes-checkbox-item"></label>');
        var $checkbox = $('<input type="checkbox" value="' + value + '">');
        $checkbox.on('change', function() {
            var val = $(this).val();
            if (this.checked) {
                if (state.filters[filterKey].indexOf(val) === -1) {
                    state.filters[filterKey].push(val);
                }
            } else {
                state.filters[filterKey] = state.filters[filterKey].filter(function(v) {
                    return v !== val;
                });
            }
            renderFilterChips();
            updateClearButton();
        });
        $item.append($checkbox);
        $item.append(' ' + label);
        return $item;
    }

    function initSelect2Ajax(selectId, filterType, stateKey) {
        $('#' + selectId).select2({
            ajax: {
                url: 'api/data/route-history/filters.php',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return { type: filterType, q: params.term };
                },
                processResults: function(data) {
                    return {
                        results: (data.results || []).map(function(item) {
                            return {
                                id: item.code || item.icao_code,
                                text: (item.code || item.icao_code) + ' - ' + item.name
                            };
                        })
                    };
                }
            },
            minimumInputLength: 2,
            placeholder: PERTII18n.t('routes.filters.' + filterType) || filterType,
            allowClear: true,
            multiple: true
        }).on('change', function() {
            var vals = $(this).val() || [];
            state.filters[stateKey] = vals;
            renderFilterChips();
            updateClearButton();
        });
    }

    // ========================================================================
    // FILTER INITIALIZATION
    // ========================================================================

    function initFilters() {
        // Origin tag input
        initTagInput('origin');

        // Destination tag input
        initTagInput('dest');

        // Mode pills
        $('.routes-mode-pill').on('click', function() {
            var mode = $(this).data('mode');
            var target = $(this).data('target');

            // Update active state
            $('.routes-mode-pill[data-target="' + target + '"]').removeClass('active');
            $(this).addClass('active');

            // Update state
            if (target === 'origin') {
                state.filters.origMode = mode;
            } else {
                state.filters.destMode = mode;
            }

            console.log('[Routes] Mode changed:', target, mode);
        });

        // Search button
        $('#routes_search_btn').on('click', function() {
            state.page = 1; // Reset to page 1
            doSearch();
        });

        // Clear all button
        $('#routes_clear_btn').on('click', function() {
            clearAllFilters();
        });
    }

    function initTagInput(target) {
        var inputId = target + '_input';
        var containerId = target + '_tags_container';
        var filterKey = target === 'origin' ? 'origins' : 'destinations';

        var $input = $('#' + inputId);
        var $container = $('#' + containerId);

        // Handle Enter key and comma
        $input.on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                var value = $input.val().trim().toUpperCase();
                if (value && value !== ',') {
                    addTag(target, value);
                    $input.val('');
                }
            } else if (e.key === 'Backspace' && $input.val() === '') {
                // Remove last tag on backspace
                if (state.filters[filterKey].length > 0) {
                    removeTag(target, state.filters[filterKey][state.filters[filterKey].length - 1]);
                }
            }
        });

        // Handle blur (when clicking outside)
        $input.on('blur', function() {
            var value = $input.val().trim().toUpperCase();
            if (value && value !== ',') {
                addTag(target, value);
                $input.val('');
            }
        });

        // Uppercase as you type
        $input.on('input', function() {
            var pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    }

    function addTag(target, code) {
        var filterKey = target === 'origin' ? 'origins' : 'destinations';

        // Remove any non-alphanumeric chars except comma (for split)
        code = code.replace(/[^A-Z0-9,]/g, '');

        // Split by comma if multiple
        var codes = code.split(',').filter(function(c) { return c.length > 0; });

        codes.forEach(function(c) {
            // Prevent duplicates
            if (state.filters[filterKey].indexOf(c) === -1) {
                state.filters[filterKey].push(c);
            }
        });

        renderTags(target);
        renderFilterChips();
        updateClearButton();
    }

    function removeTag(target, code) {
        var filterKey = target === 'origin' ? 'origins' : 'destinations';
        state.filters[filterKey] = state.filters[filterKey].filter(function(c) {
            return c !== code;
        });
        renderTags(target);
        renderFilterChips();
        updateClearButton();
    }

    function renderTags(target) {
        var filterKey = target === 'origin' ? 'origins' : 'destinations';
        var containerId = target + '_tags_container';
        var inputId = target + '_input';

        var $container = $('#' + containerId);
        var $input = $('#' + inputId);

        // Remove existing tags
        $container.find('.routes-tag').remove();

        // Add tags before input
        state.filters[filterKey].forEach(function(code) {
            var $tag = $('<span class="routes-tag"></span>')
                .text(code)
                .append('<i class="fas fa-times routes-tag-remove"></i>');

            $tag.find('.routes-tag-remove').on('click', function() {
                removeTag(target, code);
            });

            $input.before($tag);
        });
    }

    // ========================================================================
    // FILTER CHIPS
    // ========================================================================

    function renderFilterChips() {
        var $bar = $('#routes_filter_chips');
        $bar.empty();

        // Origin chips
        state.filters.origins.forEach(function(code) {
            var label = PERTII18n.t('routes.filters.origin') + ': ' + code;
            if (state.filters.origMode !== 'airport') {
                label += ' (' + state.filters.origMode + ')';
            }
            $bar.append(buildChip(label, function() {
                removeTag('origin', code);
                doSearch();
            }));
        });

        // Destination chips
        state.filters.destinations.forEach(function(code) {
            var label = PERTII18n.t('routes.filters.destination') + ': ' + code;
            if (state.filters.destMode !== 'airport') {
                label += ' (' + state.filters.destMode + ')';
            }
            $bar.append(buildChip(label, function() {
                removeTag('dest', code);
                doSearch();
            }));
        });

        // Aircraft chips
        state.filters.aircraft.forEach(function(code) {
            $bar.append(buildChip(PERTII18n.t('routes.filters.aircraft') + ': ' + code, function() {
                removeArrayValue('aircraft', code);
            }));
        });

        state.filters.weight.forEach(function(code) {
            $bar.append(buildChip(PERTII18n.t('routes.filters.weight') + ': ' + code, function() {
                removeArrayValue('weight', code);
            }));
        });

        state.filters.wake.forEach(function(code) {
            $bar.append(buildChip(PERTII18n.t('routes.filters.wake') + ': ' + code, function() {
                removeArrayValue('wake', code);
            }));
        });

        state.filters.engine.forEach(function(code) {
            $bar.append(buildChip(PERTII18n.t('routes.filters.engine') + ': ' + code, function() {
                removeArrayValue('engine', code);
            }));
        });

        // Operator chips
        state.filters.airline.forEach(function(code) {
            $bar.append(buildChip(PERTII18n.t('routes.filters.airline') + ': ' + code, function() {
                removeArrayValue('airline', code);
            }));
        });

        if (state.filters.callsignPrefix) {
            $bar.append(buildChip(PERTII18n.t('routes.filters.callsignPrefix') + ': ' + state.filters.callsignPrefix, function() {
                state.filters.callsignPrefix = '';
                $('#callsign_prefix_input').val('');
                renderFilterChips();
                updateClearButton();
            }));
        }

        state.filters.operatorGroup.forEach(function(code) {
            $bar.append(buildChip(PERTII18n.t('routes.filters.operatorGroup') + ': ' + code, function() {
                removeArrayValue('operatorGroup', code);
            }));
        });

        // Time chips
        if (state.filters.dateFrom) {
            $bar.append(buildChip(PERTII18n.t('routes.filters.from') + ': ' + state.filters.dateFrom, function() {
                state.filters.dateFrom = '';
                $('#date_from_input').val('');
                renderFilterChips();
                updateClearButton();
            }));
        }

        if (state.filters.dateTo) {
            $bar.append(buildChip(PERTII18n.t('routes.filters.to') + ': ' + state.filters.dateTo, function() {
                state.filters.dateTo = '';
                $('#date_to_input').val('');
                renderFilterChips();
                updateClearButton();
            }));
        }

        state.filters.year.forEach(function(code) {
            $bar.append(buildChip(PERTII18n.t('routes.filters.year') + ': ' + code, function() {
                removeArrayValue('year', code);
            }));
        });

        state.filters.month.forEach(function(code) {
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $bar.append(buildChip(PERTII18n.t('routes.filters.month') + ': ' + months[parseInt(code) - 1], function() {
                removeArrayValue('month', code);
            }));
        });

        state.filters.dayOfWeek.forEach(function(code) {
            var days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            $bar.append(buildChip(PERTII18n.t('routes.filters.dayOfWeek') + ': ' + days[parseInt(code) - 1], function() {
                removeArrayValue('dayOfWeek', code);
            }));
        });

        if (state.filters.hourMin !== '') {
            $bar.append(buildChip(PERTII18n.t('routes.filters.hourMin') + ': ' + state.filters.hourMin, function() {
                state.filters.hourMin = '';
                $('#hour_min_input').val('');
                renderFilterChips();
                updateClearButton();
            }));
        }

        if (state.filters.hourMax !== '') {
            $bar.append(buildChip(PERTII18n.t('routes.filters.hourMax') + ': ' + state.filters.hourMax, function() {
                state.filters.hourMax = '';
                $('#hour_max_input').val('');
                renderFilterChips();
                updateClearButton();
            }));
        }

        state.filters.season.forEach(function(code) {
            $bar.append(buildChip(PERTII18n.t('routes.filters.season') + ': ' + code, function() {
                removeArrayValue('season', code);
            }));
        });

        // Toggle visibility class based on whether chips exist
        $bar.toggleClass('has-chips', $bar.children().length > 0);
    }

    function removeArrayValue(filterKey, value) {
        state.filters[filterKey] = state.filters[filterKey].filter(function(v) {
            return v !== value;
        });
        populateFiltersFromState();
        renderFilterChips();
        updateClearButton();
    }

    function buildChip(label, onRemove) {
        var $chip = $('<span class="routes-filter-chip"></span>')
            .text(label)
            .append('<i class="fas fa-times routes-chip-remove"></i>');

        $chip.find('.routes-chip-remove').on('click', onRemove);
        return $chip;
    }

    // ========================================================================
    // SEARCH
    // ========================================================================

    function doSearch() {
        var params = filtersToQueryString();

        if (!params) {
            showNoFiltersState();
            return;
        }

        console.log('[Routes] Searching with params:', params);
        showLoadingState();

        $.ajax({
            url: 'api/data/route-history/search.php?' + params,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                console.log('[Routes] Search response:', data);
                if (data.success) {
                    state.results = data;
                    renderRouteList(data);
                    renderFilterChips();
                    updateUrl();

                    // Collapse filter sections to give route list more space
                    collapseFilters();

                    // Plot routes on map
                    if (typeof RoutesMap !== 'undefined' && data.routes) {
                        RoutesMap.plotRoutes(data.routes, data.total_flights || 0);
                    }
                } else {
                    showError(data.error || PERTII18n.t('error.loadFailed', { resource: 'routes' }));
                }
            },
            error: function(xhr, status, error) {
                console.error('[Routes] Search failed:', status, error);
                showError(PERTII18n.t('error.loadFailed', { resource: 'routes' }));
            }
        });
    }

    function filtersToQueryString() {
        var params = [];

        // Location filters
        if (state.filters.origins.length > 0) {
            params.push('orig=' + state.filters.origins.join(','));
            if (state.filters.origMode !== 'airport') {
                params.push('orig_mode=' + state.filters.origMode);
            }
        }

        if (state.filters.destinations.length > 0) {
            params.push('dest=' + state.filters.destinations.join(','));
            if (state.filters.destMode !== 'airport') {
                params.push('dest_mode=' + state.filters.destMode);
            }
        }

        // Aircraft filters
        if (state.filters.aircraft.length > 0) {
            params.push('aircraft=' + state.filters.aircraft.join(','));
        }
        if (state.filters.manufacturer.length > 0) {
            params.push('manufacturer=' + state.filters.manufacturer.join(','));
        }
        if (state.filters.weight.length > 0) {
            params.push('weight=' + state.filters.weight.join(','));
        }
        if (state.filters.wake.length > 0) {
            params.push('wake=' + state.filters.wake.join(','));
        }
        if (state.filters.engine.length > 0) {
            params.push('engine=' + state.filters.engine.join(','));
        }

        // Operator filters
        if (state.filters.airline.length > 0) {
            params.push('airline=' + state.filters.airline.join(','));
        }
        if (state.filters.callsignPrefix) {
            params.push('callsign_prefix=' + state.filters.callsignPrefix);
        }
        if (state.filters.operatorGroup.length > 0) {
            params.push('operator_group=' + state.filters.operatorGroup.join(','));
        }

        // Time filters
        if (state.filters.dateFrom) {
            params.push('date_from=' + state.filters.dateFrom);
        }
        if (state.filters.dateTo) {
            params.push('date_to=' + state.filters.dateTo);
        }
        if (state.filters.month.length > 0) {
            params.push('month=' + state.filters.month.join(','));
        }
        if (state.filters.dayOfWeek.length > 0) {
            params.push('day_of_week=' + state.filters.dayOfWeek.join(','));
        }
        if (state.filters.hourMin !== '') {
            params.push('hour_min=' + state.filters.hourMin);
        }
        if (state.filters.hourMax !== '') {
            params.push('hour_max=' + state.filters.hourMax);
        }
        if (state.filters.season.length > 0) {
            params.push('season=' + state.filters.season.join(','));
        }
        if (state.filters.year.length > 0) {
            params.push('year=' + state.filters.year.join(','));
        }

        // Need at least one filter
        if (params.length === 0) {
            return null;
        }

        // Sort, view, and pagination
        params.push('sort=' + state.sort);
        params.push('view=' + state.view);
        params.push('page=' + state.page);
        params.push('per_page=50');

        return params.join('&');
    }

    function hasActiveFilters() {
        return state.filters.origins.length > 0 ||
               state.filters.destinations.length > 0 ||
               state.filters.aircraft.length > 0 ||
               state.filters.manufacturer.length > 0 ||
               state.filters.weight.length > 0 ||
               state.filters.wake.length > 0 ||
               state.filters.engine.length > 0 ||
               state.filters.airline.length > 0 ||
               state.filters.callsignPrefix !== '' ||
               state.filters.operatorGroup.length > 0 ||
               state.filters.dateFrom !== '' ||
               state.filters.dateTo !== '' ||
               state.filters.month.length > 0 ||
               state.filters.dayOfWeek.length > 0 ||
               state.filters.hourMin !== '' ||
               state.filters.hourMax !== '' ||
               state.filters.season.length > 0 ||
               state.filters.year.length > 0;
    }

    // ========================================================================
    // ROUTE LIST RENDERING
    // ========================================================================

    function renderRouteList(data) {
        var $list = $('#routes_list');
        $list.empty();

        if (!data.routes || data.routes.length === 0) {
            showNoResultsState();
            return;
        }

        // Summary bar
        var $summary = $('<div class="routes-summary"></div>');
        $summary.html(
            '<span><strong>' + data.total_routes.toLocaleString() + '</strong> ' +
            PERTII18n.t('routes.results.totalRoutes') + '</span>' +
            '<span><strong>' + data.total_flights.toLocaleString() + '</strong> ' +
            PERTII18n.t('routes.results.totalFlights') + '</span>'
        );
        $list.append($summary);

        // Controls (sort + view toggle)
        var $controls = $('<div class="routes-controls"></div>');

        // Sort dropdown
        var $sortGroup = $('<div></div>');
        $sortGroup.append('<label style="color: #aaa; font-size: 0.8rem; margin-right: 8px;">' +
            PERTII18n.t('routes.results.sortBy') + ':</label>');
        var $sortSelect = $('<select class="routes-sort-select"></select>');
        $sortSelect.append('<option value="frequency">' + PERTII18n.t('routes.results.sortFrequency') + '</option>');
        $sortSelect.append('<option value="distance">' + PERTII18n.t('routes.results.sortDistance') + '</option>');
        $sortSelect.append('<option value="ete">' + PERTII18n.t('routes.results.sortEte') + '</option>');
        $sortSelect.append('<option value="last_filed">' + PERTII18n.t('routes.results.sortLastFiled') + '</option>');
        $sortSelect.val(state.sort);
        $sortSelect.on('change', function() {
            state.sort = $(this).val();
            state.page = 1;
            doSearch();
        });
        $sortGroup.append($sortSelect);
        $controls.append($sortGroup);

        // View toggle
        var $viewToggle = $('<div class="routes-view-toggle"></div>');
        var $groupedBtn = $('<button class="routes-view-btn"></button>')
            .text(PERTII18n.t('routes.results.grouped'))
            .addClass(state.view === 'grouped' ? 'active' : '')
            .on('click', function() {
                state.view = 'grouped';
                state.page = 1;
                doSearch();
            });
        var $rawBtn = $('<button class="routes-view-btn"></button>')
            .text(PERTII18n.t('routes.results.raw'))
            .addClass(state.view === 'raw' ? 'active' : '')
            .on('click', function() {
                state.view = 'raw';
                state.page = 1;
                doSearch();
            });
        $viewToggle.append($groupedBtn, $rawBtn);
        $controls.append($viewToggle);

        $list.append($controls);

        // Route items
        data.routes.forEach(function(route) {
            var $item = buildRouteItem(route);
            $list.append($item);
        });

        // Load more button
        if (data.page < data.total_pages) {
            var $more = $('<button class="routes-load-more"></button>')
                .html('<i class="fas fa-chevron-down"></i> ' + PERTII18n.t('routes.results.loadMore') +
                      ' (' + (data.total_pages - data.page) + ' ' + PERTII18n.t('routes.results.totalRoutes') + ')')
                .on('click', function() {
                    state.page++;
                    doSearch();
                });
            $list.append($more);
        }
    }

    function buildRouteItem(route) {
        var $item = $('<div class="routes-item"></div>');

        // Frequency tier class for left border color
        var pct = parseFloat(route.frequency_pct) || 0;
        if (pct > 10) $item.addClass('freq-high');
        else if (pct >= 3) $item.addClass('freq-medium');
        else $item.addClass('freq-low');

        // Multi-select checkbox
        var $checkbox = $('<input type="checkbox" class="routes-multi-check">')
            .prop('checked', state.multiSelected.indexOf(route.route_dim_id) !== -1)
            .on('click', function(e) {
                e.stopPropagation();
                toggleMultiSelect(route.route_dim_id);
            });
        $item.append($checkbox);

        // Header: airports (fallback to filter values if API doesn't return them)
        var $header = $('<div class="routes-item-header"></div>');
        var originLabel = route.origin_icao || (state.filters.origins.length === 1 ? state.filters.origins[0] : '???');
        var destLabel = route.dest_icao || (state.filters.destinations.length === 1 ? state.filters.destinations[0] : '???');
        var $airports = $('<div class="routes-item-airports"></div>')
            .html(originLabel + ' <span class="arrow">&rarr;</span> ' + destLabel);
        var $count = $('<div class="routes-item-count"></div>')
            .text(route.flight_count.toLocaleString() + ' ' + PERTII18n.t('routes.results.flights'));
        $header.append($airports, $count);
        $item.append($header);

        // Route string (full, CSS handles wrapping)
        var $route = $('<div class="routes-item-route"></div>').text(route.normalized_route || route.raw_route || '');
        $item.append($route);

        // Metadata
        var $meta = $('<div class="routes-item-meta"></div>');

        if (route.variant_count > 1) {
            $meta.append('<span><i class="fas fa-code-branch"></i> ' +
                route.variant_count + ' ' + PERTII18n.t('routes.results.variants') + '</span>');
        }

        if (route.avg_distance_nm) {
            $meta.append('<span><i class="fas fa-ruler"></i> ' +
                Math.round(route.avg_distance_nm) + ' nm ' + PERTII18n.t('routes.results.avgDist') + '</span>');
        }

        if (route.avg_ete_minutes) {
            var hours = Math.floor(route.avg_ete_minutes / 60);
            var mins = Math.round(route.avg_ete_minutes % 60);
            $meta.append('<span><i class="fas fa-clock"></i> ' + hours + 'h' + mins + 'm ' +
                PERTII18n.t('routes.results.avgEte') + '</span>');
        }

        if (route.last_filed) {
            var lastFiled = formatRelativeDate(route.last_filed);
            $meta.append('<span><i class="fas fa-calendar"></i> ' + lastFiled + '</span>');
        }

        $item.append($meta);

        // Click handler
        $item.on('click', function() {
            selectRoute(route);
        });

        // Mark as selected if matches current selection
        if (state.selectedRoute && state.selectedRoute.route_dim_id === route.route_dim_id) {
            $item.addClass('selected');
        }

        // Mark as multi-selected and apply color
        var multiIdx = state.multiSelected.indexOf(route.route_dim_id);
        if (multiIdx !== -1) {
            var colors = ['#FF6B6B', '#4ECDC4', '#FFE66D', '#7B68EE', '#FF8C42', '#A8E6CF'];
            $item.addClass('multi-selected');
            $item.css('--multi-color', colors[multiIdx]);
        }

        return $item;
    }

    function selectRoute(route) {
        // Toggle: if already selected, deselect
        if (state.selectedRoute && state.selectedRoute.route_dim_id === route.route_dim_id) {
            console.log('[Routes] Route deselected:', route.route_dim_id);
            state.selectedRoute = null;
            $('.routes-item').removeClass('selected');
            if (typeof RoutesMap !== 'undefined') RoutesMap.clearHighlight();
            return;
        }

        console.log('[Routes] Route selected:', route);
        state.selectedRoute = route;

        // Update selected state in list
        $('.routes-item').removeClass('selected');
        $('.routes-item').filter(function() {
            return $(this).data('route_id') === route.route_dim_id;
        }).addClass('selected');

        // Highlight on map (only if no multi-select is active)
        if (typeof RoutesMap !== 'undefined') {
            if (state.multiSelected.length === 0) {
                RoutesMap.highlightRoute(route.route_dim_id);
            } else {
                RoutesMap.highlightMultiple(state.multiSelected);
            }
        }

        // Show info dialog immediately with available data
        showRouteInfoDialog(route);
    }

    function showRouteInfoDialog(route) {
        var originLabel = route.origin_icao || '???';
        var destLabel = route.dest_icao || '???';
        var pct = parseFloat(route.frequency_pct) || 0;
        var routeStr = route.normalized_route || route.raw_route || '';

        // Format ETE
        var eteStr = '';
        if (route.avg_ete_minutes) {
            var hours = Math.floor(route.avg_ete_minutes / 60);
            var mins = Math.round(route.avg_ete_minutes % 60);
            eteStr = hours + 'h ' + mins + 'm';
        }

        // Build dialog HTML
        var html = '<div class="route-info-dialog">';

        // Route string
        html += '<div class="rid-route-string">' + escapeHtml(routeStr) + '</div>';

        // Stats grid
        html += '<div class="rid-stats">';
        html += '<div class="rid-stat"><div class="rid-stat-value">' + parseInt(route.flight_count).toLocaleString() + '</div><div class="rid-stat-label">' + PERTII18n.t('routes.results.flights') + '</div></div>';
        html += '<div class="rid-stat"><div class="rid-stat-value">' + pct.toFixed(1) + '%</div><div class="rid-stat-label">' + PERTII18n.t('routes.map.frequency') + '</div></div>';
        if (route.avg_distance_nm) {
            html += '<div class="rid-stat"><div class="rid-stat-value">' + Math.round(route.avg_distance_nm) + ' nm</div><div class="rid-stat-label">' + PERTII18n.t('routes.results.avgDist') + '</div></div>';
        }
        if (eteStr) {
            html += '<div class="rid-stat"><div class="rid-stat-value">' + eteStr + '</div><div class="rid-stat-label">' + PERTII18n.t('routes.results.avgEte') + '</div></div>';
        }
        if (route.avg_altitude_ft) {
            html += '<div class="rid-stat"><div class="rid-stat-value">FL' + Math.round(route.avg_altitude_ft / 100) + '</div><div class="rid-stat-label">Avg Alt</div></div>';
        }
        if (route.variant_count > 1) {
            html += '<div class="rid-stat"><div class="rid-stat-value">' + route.variant_count + '</div><div class="rid-stat-label">' + PERTII18n.t('routes.results.variants') + '</div></div>';
        }
        html += '</div>';

        // Date range
        if (route.first_filed || route.last_filed) {
            html += '<div class="rid-dates">';
            if (route.first_filed) html += '<span><i class="fas fa-calendar-plus"></i> First: ' + formatDate(route.first_filed) + '</span>';
            if (route.last_filed) html += '<span><i class="fas fa-calendar-check"></i> Last: ' + formatDate(route.last_filed) + '</span>';
            html += '</div>';
        }

        // Detail sections (populated async)
        html += '<div id="rid_detail_sections"><div class="rid-loading"><i class="fas fa-spinner fa-spin"></i> Loading details...</div></div>';

        html += '</div>';

        Swal.fire({
            title: originLabel + '  \u2192  ' + destLabel,
            html: html,
            width: 900,
            showConfirmButton: false,
            showCloseButton: true,
            customClass: {
                popup: 'route-info-popup',
                title: 'route-info-title',
                htmlContainer: 'route-info-html'
            },
            didOpen: function() {
                // Add minimize button
                var popup = Swal.getPopup();
                if (popup) {
                    var minBtn = document.createElement('button');
                    minBtn.className = 'rid-minimize-btn';
                    minBtn.innerHTML = '<i class="fas fa-window-minimize"></i>';
                    minBtn.title = 'Minimize';
                    minBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var isMin = popup.classList.toggle('rid-minimized');
                        minBtn.innerHTML = isMin
                            ? '<i class="fas fa-window-maximize"></i>'
                            : '<i class="fas fa-window-minimize"></i>';
                        minBtn.title = isMin ? 'Restore' : 'Minimize';
                        // Click title to restore when minimized
                        if (isMin) {
                            var titleEl = popup.querySelector('.swal2-title');
                            if (titleEl) {
                                titleEl.style.cursor = 'pointer';
                                titleEl.onclick = function() {
                                    popup.classList.remove('rid-minimized');
                                    minBtn.innerHTML = '<i class="fas fa-window-minimize"></i>';
                                    minBtn.title = 'Minimize';
                                    titleEl.style.cursor = '';
                                    titleEl.onclick = null;
                                };
                            }
                        }
                    });
                    popup.appendChild(minBtn);
                }
                // Fetch detail data to enrich dialog
                fetchRouteDetail(route);
            }
        });
    }

    function fetchRouteDetail(route) {
        $.ajax({
            url: 'api/data/route-history/detail.php?route_dim_id=' + route.route_dim_id,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    renderDetailSections(data);
                } else {
                    $('#rid_detail_sections').html('<div class="rid-empty">Could not load details</div>');
                }
            },
            error: function() {
                $('#rid_detail_sections').html('<div class="rid-empty">Failed to load details</div>');
            }
        });
    }

    function renderDetailSections(data) {
        var $container = $('#rid_detail_sections');
        if (!$container.length) return;

        var html = '';

        // Variants section with sortable headers and selectable rows
        if (data.variants && data.variants.length > 0) {
            html += '<div class="rid-section">';
            html += '<div class="rid-section-title">' + PERTII18n.t('routes.detail.variants') + ' (' + data.variants.length + ')</div>';
            html += '<div class="rid-variant-actions" id="rid_variant_actions" style="display:none;">';
            html += '<button class="rid-filter-selected-btn" id="rid_filter_selected"><i class="fas fa-filter"></i> Filter to selected</button>';
            html += '<button class="rid-clear-selected-btn" id="rid_clear_selected"><i class="fas fa-times"></i> Clear</button>';
            html += '<span class="rid-selected-count" id="rid_selected_count"></span>';
            html += '</div>';
            html += '<table class="rid-table" id="rid_variants_table"><thead><tr>';
            html += '<th class="rid-sortable" data-sort="route">' + PERTII18n.t('routes.detail.rawRoute') + '</th>';
            html += '<th class="rid-col-num rid-sortable" data-sort="count">' + PERTII18n.t('routes.detail.count') + '</th>';
            html += '<th class="rid-col-date rid-sortable" data-sort="date">' + PERTII18n.t('routes.detail.lastFiled') + '</th>';
            html += '</tr></thead><tbody>';
            data.variants.forEach(function(v, idx) {
                html += '<tr data-idx="' + idx + '" data-route="' + escapeHtml(v.raw_route) + '" data-cnt="' + parseInt(v.cnt) + '" data-date="' + (v.last_filed || '') + '">';
                html += '<td class="rid-route-cell">' + escapeHtml(v.raw_route) + '</td>';
                html += '<td class="rid-col-num">' + parseInt(v.cnt).toLocaleString() + '</td>';
                html += '<td class="rid-col-date">' + formatDate(v.last_filed) + '</td></tr>';
            });
            html += '</tbody></table>';
            html += '</div>';
        }

        // Aircraft mix
        var aircraft = data.stats && data.stats.aircraft_mix;
        if (aircraft && aircraft.length > 0) {
            html += '<div class="rid-section">';
            html += '<div class="rid-section-title">' + PERTII18n.t('routes.detail.aircraftMix') + '</div>';
            html += '<div class="rid-bar-list">';
            var maxAc = Math.max.apply(null, aircraft.map(function(a) { return parseInt(a.cnt); }));
            aircraft.slice(0, 8).forEach(function(a) {
                var pct = maxAc > 0 ? (parseInt(a.cnt) / maxAc * 100) : 0;
                html += '<div class="rid-bar-row">';
                html += '<span class="rid-bar-label">' + escapeHtml(a.icao_code) + '</span>';
                html += '<div class="rid-bar-track"><div class="rid-bar-fill rid-bar-cyan" style="width:' + pct + '%"></div></div>';
                html += '<span class="rid-bar-value">' + parseInt(a.cnt).toLocaleString() + '</span>';
                html += '</div>';
            });
            html += '</div></div>';
        }

        // Airline mix
        var airlines = data.stats && data.stats.airline_mix;
        if (airlines && airlines.length > 0) {
            html += '<div class="rid-section">';
            html += '<div class="rid-section-title">' + PERTII18n.t('routes.detail.airlineMix') + '</div>';
            html += '<div class="rid-bar-list">';
            var maxAl = Math.max.apply(null, airlines.map(function(a) { return parseInt(a.cnt); }));
            airlines.slice(0, 8).forEach(function(a) {
                var pct = maxAl > 0 ? (parseInt(a.cnt) / maxAl * 100) : 0;
                html += '<div class="rid-bar-row">';
                html += '<span class="rid-bar-label">' + escapeHtml(a.airline_icao) + '</span>';
                html += '<div class="rid-bar-track"><div class="rid-bar-fill rid-bar-teal" style="width:' + pct + '%"></div></div>';
                html += '<span class="rid-bar-value">' + parseInt(a.cnt).toLocaleString() + '</span>';
                html += '</div>';
            });
            html += '</div></div>';
        }

        // Altitude distribution
        var altitudes = data.stats && data.stats.altitude_distribution;
        if (altitudes && altitudes.length > 0) {
            html += '<div class="rid-section">';
            html += '<div class="rid-section-title">' + PERTII18n.t('routes.detail.altitudeDistribution') + '</div>';
            html += '<div class="rid-bar-list">';
            var maxAlt = Math.max.apply(null, altitudes.map(function(a) { return parseInt(a.cnt); }));
            altitudes.slice(0, 8).forEach(function(a) {
                var pct = maxAlt > 0 ? (parseInt(a.cnt) / maxAlt * 100) : 0;
                html += '<div class="rid-bar-row">';
                html += '<span class="rid-bar-label">FL' + Math.round(parseInt(a.altitude_ft) / 100) + '</span>';
                html += '<div class="rid-bar-track"><div class="rid-bar-fill rid-bar-purple" style="width:' + pct + '%"></div></div>';
                html += '<span class="rid-bar-value">' + parseInt(a.cnt).toLocaleString() + '</span>';
                html += '</div>';
            });
            html += '</div></div>';
        }

        if (!html) {
            html = '<div class="rid-empty">No additional details available</div>';
        }

        $container.html(html);

        // Wire up sortable column headers
        var $table = $('#rid_variants_table');
        if ($table.length) {
            $table.find('th.rid-sortable').on('click', function() {
                var $th = $(this);
                var sortKey = $th.data('sort');
                var $tbody = $table.find('tbody');
                var $rows = $tbody.find('tr').get();

                // Cycle: none -> asc -> desc -> none
                var currentState = $th.hasClass('rid-sort-asc') ? 'asc' : ($th.hasClass('rid-sort-desc') ? 'desc' : 'none');
                $table.find('th').removeClass('rid-sort-asc rid-sort-desc');

                if (currentState === 'none') {
                    $th.addClass('rid-sort-asc');
                    sortVariantRows($rows, sortKey, 1);
                } else if (currentState === 'asc') {
                    $th.addClass('rid-sort-desc');
                    sortVariantRows($rows, sortKey, -1);
                } else {
                    // Reset to default (by count desc)
                    sortVariantRows($rows, 'count', -1);
                }

                $rows.forEach(function(row) { $tbody.append(row); });
            });

            // Wire up row selection
            $table.find('tbody').on('click', 'tr', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    $(this).toggleClass('rid-row-selected');
                } else if (e.shiftKey) {
                    // Range select
                    var $allRows = $table.find('tbody tr');
                    var lastIdx = $table.data('lastSelectedIdx') || 0;
                    var thisIdx = $allRows.index(this);
                    var start = Math.min(lastIdx, thisIdx);
                    var end = Math.max(lastIdx, thisIdx);
                    $allRows.slice(start, end + 1).addClass('rid-row-selected');
                } else {
                    var wasSelected = $(this).hasClass('rid-row-selected');
                    $table.find('tbody tr').removeClass('rid-row-selected');
                    if (!wasSelected) $(this).addClass('rid-row-selected');
                }
                $table.data('lastSelectedIdx', $table.find('tbody tr').index(this));
                updateVariantActions();
            });
        }

        // Wire up filter/clear buttons
        $(document).off('click.ridFilter').on('click.ridFilter', '#rid_filter_selected', function() {
            var selected = [];
            $('#rid_variants_table tbody tr.rid-row-selected').each(function() {
                selected.push($(this).data('route'));
            });
            if (selected.length > 0 && typeof window.filterRoutesByVariants === 'function') {
                window.filterRoutesByVariants(selected);
            }
        });
        $(document).off('click.ridClear').on('click.ridClear', '#rid_clear_selected', function() {
            $('#rid_variants_table tbody tr').removeClass('rid-row-selected');
            updateVariantActions();
        });
    }

    function sortVariantRows(rows, key, dir) {
        rows.sort(function(a, b) {
            var aVal, bVal;
            if (key === 'count') {
                aVal = parseInt($(a).data('cnt')) || 0;
                bVal = parseInt($(b).data('cnt')) || 0;
            } else if (key === 'date') {
                aVal = $(a).data('date') || '';
                bVal = $(b).data('date') || '';
            } else {
                aVal = ($(a).data('route') || '').toLowerCase();
                bVal = ($(b).data('route') || '').toLowerCase();
            }
            if (aVal < bVal) return -1 * dir;
            if (aVal > bVal) return 1 * dir;
            return 0;
        });
    }

    function updateVariantActions() {
        var count = $('#rid_variants_table tbody tr.rid-row-selected').length;
        if (count > 0) {
            $('#rid_variant_actions').show();
            $('#rid_selected_count').text(count + ' selected');
        } else {
            $('#rid_variant_actions').hide();
        }
    }

    function handleExport(format, scope) {
        var url = 'api/data/route-history/export.php?format=' + encodeURIComponent(format);

        if (scope === 'route') {
            // Export selected route group
            url += '&route_dim_id=' + state.selectedRoute.route_dim_id;
        } else {
            // Export full search results with all filters
            url += '&' + buildFilterQueryString();
        }

        if (format === 'clipboard') {
            // AJAX call to get clipboard data
            $.ajax({
                url: url,
                method: 'GET',
                success: function(response) {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(response).then(function() {
                            Swal.fire({
                                icon: 'success',
                                title: PERTII18n.t('routes.detail.exportTab.copied'),
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 2000
                            });
                        }).catch(function(err) {
                            console.error('[Routes] Clipboard write failed:', err);
                            Swal.fire({
                                icon: 'error',
                                title: 'Failed to copy to clipboard',
                                text: 'Please use CSV download instead.'
                            });
                        });
                    } else {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Clipboard not available',
                            text: 'Please use CSV download instead.'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[Routes] Export failed:', status, error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Export failed',
                        text: error
                    });
                }
            });
        } else {
            // CSV or GeoJSON - open in new tab to trigger download
            window.open(url, '_blank');
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ========================================================================
    // EMPTY STATES
    // ========================================================================

    function showNoFiltersState() {
        var $list = $('#routes_list');
        $list.empty();

        var $empty = $('<div class="routes-empty-state"></div>');
        $empty.append('<i class="fas fa-filter"></i>');
        $empty.append('<h3>' + PERTII18n.t('routes.title') + '</h3>');
        $empty.append('<p>' + PERTII18n.t('routes.results.noFilters') + '</p>');
        $list.append($empty);
    }

    function showNoResultsState() {
        var $list = $('#routes_list');
        $list.empty();

        var $empty = $('<div class="routes-empty-state"></div>');
        $empty.append('<i class="fas fa-search"></i>');
        $empty.append('<h3>' + PERTII18n.t('routes.results.noResultsTitle') + '</h3>');
        $empty.append('<p>' + PERTII18n.t('routes.results.noResults') + '</p>');
        $list.append($empty);
    }

    function showLoadingState() {
        var $list = $('#routes_list');
        $list.empty();

        var $loading = $('<div class="routes-empty-state"></div>');
        $loading.append('<i class="fas fa-spinner fa-spin"></i>');
        $loading.append('<h3>' + PERTII18n.t('routes.results.loading') + '</h3>');
        $list.append($loading);
    }

    function showError(message) {
        var $list = $('#routes_list');
        $list.empty();

        var $error = $('<div class="routes-empty-state"></div>');
        $error.append('<i class="fas fa-exclamation-triangle" style="color: #ff6b6b;"></i>');
        $error.append('<h3 style="color: #ff6b6b;">Error</h3>');
        $error.append('<p>' + message + '</p>');
        $list.append($error);
    }

    // ========================================================================
    // MULTI-SELECT MANAGEMENT
    // ========================================================================

    function toggleMultiSelect(dimId) {
        var idx = state.multiSelected.indexOf(dimId);
        if (idx >= 0) {
            state.multiSelected.splice(idx, 1);
        } else {
            if (state.multiSelected.length >= 6) {
                console.warn('[Routes] Maximum 6 routes can be selected for comparison');
                return;
            }
            state.multiSelected.push(dimId);
        }
        updateMultiSelectUI();
    }

    function updateMultiSelectUI() {
        // Update checkboxes and styles in list
        $('.routes-item').each(function() {
            var $item = $(this);
            var $checkbox = $item.find('.routes-multi-check');
            if (!$checkbox.length) return;

            // Find route data for this item
            var route = null;
            if (state.results && state.results.routes) {
                var itemIndex = $('.routes-item').index($item);
                route = state.results.routes[itemIndex];
            }
            if (!route) return;

            var multiIdx = state.multiSelected.indexOf(route.route_dim_id);
            var isMultiSelected = multiIdx !== -1;

            // Update checkbox
            $checkbox.prop('checked', isMultiSelected);

            // Update multi-selected class and color
            var colors = ['#FF6B6B', '#4ECDC4', '#FFE66D', '#7B68EE', '#FF8C42', '#A8E6CF'];
            if (isMultiSelected) {
                $item.addClass('multi-selected');
                $item.css('--multi-color', colors[multiIdx]);
            } else {
                $item.removeClass('multi-selected');
                $item.css('--multi-color', '');
            }
        });

        // Update map highlighting
        if (state.multiSelected.length > 0 && typeof RoutesMap !== 'undefined') {
            RoutesMap.highlightMultiple(state.multiSelected);
        } else if (typeof RoutesMap !== 'undefined') {
            RoutesMap.clearHighlight();
        }
    }

    // ========================================================================
    // MAP INTEGRATION
    // ========================================================================

    // Map click callback
    window.onRouteMapClick = function(dimId) {
        // Find the route in results
        if (!state.results || !state.results.routes) return;
        var route = null;
        for (var i = 0; i < state.results.routes.length; i++) {
            if (state.results.routes[i].route_dim_id === dimId) {
                route = state.results.routes[i];
                break;
            }
        }
        if (route) {
            selectRoute(route);
            // Scroll to item in list
            var $item = $('.routes-item').eq(state.results.routes.indexOf(route));
            if ($item.length) {
                $item[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    };

    // ========================================================================
    // URL STATE MANAGEMENT
    // ========================================================================

    function updateUrl() {
        var qs = filtersToQueryString();
        if (qs) {
            history.pushState(state, '', 'routes.php?' + qs);
        } else {
            history.pushState(state, '', 'routes.php');
        }
    }

    function parseUrlState() {
        var params = new URLSearchParams(window.location.search);

        // Origin
        if (params.get('orig')) {
            state.filters.origins = params.get('orig').split(',');
        }
        if (params.get('orig_mode')) {
            state.filters.origMode = params.get('orig_mode');
        }

        // Destination
        if (params.get('dest')) {
            state.filters.destinations = params.get('dest').split(',');
        }
        if (params.get('dest_mode')) {
            state.filters.destMode = params.get('dest_mode');
        }

        // Aircraft
        if (params.get('aircraft')) {
            state.filters.aircraft = params.get('aircraft').split(',');
        }
        if (params.get('manufacturer')) {
            state.filters.manufacturer = params.get('manufacturer').split(',');
        }
        if (params.get('weight')) {
            state.filters.weight = params.get('weight').split(',');
        }
        if (params.get('wake')) {
            state.filters.wake = params.get('wake').split(',');
        }
        if (params.get('engine')) {
            state.filters.engine = params.get('engine').split(',');
        }

        // Operator
        if (params.get('airline')) {
            state.filters.airline = params.get('airline').split(',');
        }
        if (params.get('callsign_prefix')) {
            state.filters.callsignPrefix = params.get('callsign_prefix');
        }
        if (params.get('operator_group')) {
            state.filters.operatorGroup = params.get('operator_group').split(',');
        }

        // Time
        if (params.get('date_from')) {
            state.filters.dateFrom = params.get('date_from');
        }
        if (params.get('date_to')) {
            state.filters.dateTo = params.get('date_to');
        }
        if (params.get('month')) {
            state.filters.month = params.get('month').split(',');
        }
        if (params.get('day_of_week')) {
            state.filters.dayOfWeek = params.get('day_of_week').split(',');
        }
        if (params.get('hour_min')) {
            state.filters.hourMin = params.get('hour_min');
        }
        if (params.get('hour_max')) {
            state.filters.hourMax = params.get('hour_max');
        }
        if (params.get('season')) {
            state.filters.season = params.get('season').split(',');
        }
        if (params.get('year')) {
            state.filters.year = params.get('year').split(',');
        }

        // Sort/view/page
        if (params.get('sort')) {
            state.sort = params.get('sort');
        }
        if (params.get('view')) {
            state.view = params.get('view');
        }
        if (params.get('page')) {
            state.page = parseInt(params.get('page')) || 1;
        }

        // Populate UI from state
        populateFiltersFromState();
    }

    function populateFiltersFromState() {
        // Render tags
        renderTags('origin');
        renderTags('dest');

        // Update mode pills
        $('.routes-mode-pill[data-target="origin"]').removeClass('active');
        $('.routes-mode-pill[data-target="origin"][data-mode="' + state.filters.origMode + '"]').addClass('active');

        $('.routes-mode-pill[data-target="dest"]').removeClass('active');
        $('.routes-mode-pill[data-target="dest"][data-mode="' + state.filters.destMode + '"]').addClass('active');

        // Aircraft - Select2
        if (state.filters.aircraft.length > 0) {
            var $select = $('#aircraft_type_select');
            state.filters.aircraft.forEach(function(val) {
                if (!$select.find('option[value="' + val + '"]').length) {
                    $select.append(new Option(val, val, true, true));
                }
            });
            $select.trigger('change.select2');
        }

        // Aircraft - Checkboxes
        $('input[type="checkbox"][value]').each(function() {
            var $cb = $(this);
            var val = $cb.val();
            var filterKey = null;
            if ($cb.closest('.routes-checkbox-group').prev('.routes-filter-label').text().indexOf(PERTII18n.t('routes.filters.weightClass')) !== -1) {
                filterKey = 'weight';
            } else if ($cb.closest('.routes-checkbox-group').prev('.routes-filter-label').text().indexOf(PERTII18n.t('routes.filters.wakeCategory')) !== -1) {
                filterKey = 'wake';
            } else if ($cb.closest('.routes-checkbox-group').prev('.routes-filter-label').text().indexOf(PERTII18n.t('routes.filters.engineType')) !== -1) {
                filterKey = 'engine';
            } else if ($cb.closest('.routes-checkbox-group').prev('.routes-filter-label').text().indexOf(PERTII18n.t('routes.filters.operatorGroup')) !== -1) {
                filterKey = 'operatorGroup';
            } else if ($cb.closest('.routes-checkbox-group').prev('.routes-filter-label').text().indexOf(PERTII18n.t('routes.filters.year')) !== -1) {
                filterKey = 'year';
            } else if ($cb.closest('.routes-checkbox-group').prev('.routes-filter-label').text().indexOf(PERTII18n.t('routes.filters.month')) !== -1) {
                filterKey = 'month';
            } else if ($cb.closest('.routes-checkbox-group').prev('.routes-filter-label').text().indexOf(PERTII18n.t('routes.filters.dayOfWeek')) !== -1) {
                filterKey = 'dayOfWeek';
            }

            if (filterKey && state.filters[filterKey].indexOf(val) !== -1) {
                $cb.prop('checked', true);
            } else {
                $cb.prop('checked', false);
            }
        });

        // Operator - Select2
        if (state.filters.airline.length > 0) {
            var $airlineSelect = $('#airline_select');
            state.filters.airline.forEach(function(val) {
                if (!$airlineSelect.find('option[value="' + val + '"]').length) {
                    $airlineSelect.append(new Option(val, val, true, true));
                }
            });
            $airlineSelect.trigger('change.select2');
        }

        // Operator - Callsign prefix
        $('#callsign_prefix_input').val(state.filters.callsignPrefix);

        // Time - Date inputs
        $('#date_from_input').val(state.filters.dateFrom);
        $('#date_to_input').val(state.filters.dateTo);

        // Time - Hour inputs
        $('#hour_min_input').val(state.filters.hourMin);
        $('#hour_max_input').val(state.filters.hourMax);

        // Time - Season pills
        $('.routes-season-pill').removeClass('active');
        state.filters.season.forEach(function(val) {
            $('.routes-season-pill[data-value="' + val + '"]').addClass('active');
        });

        // Update chips
        renderFilterChips();
        updateClearButton();
    }

    // ========================================================================
    // CLEAR FILTERS
    // ========================================================================

    function clearAllFilters() {
        state.filters.origins = [];
        state.filters.destinations = [];
        state.filters.origMode = 'airport';
        state.filters.destMode = 'airport';
        state.filters.aircraft = [];
        state.filters.manufacturer = [];
        state.filters.weight = [];
        state.filters.wake = [];
        state.filters.engine = [];
        state.filters.airline = [];
        state.filters.callsignPrefix = '';
        state.filters.operatorGroup = [];
        state.filters.dateFrom = '';
        state.filters.dateTo = '';
        state.filters.month = [];
        state.filters.dayOfWeek = [];
        state.filters.hourMin = '';
        state.filters.hourMax = '';
        state.filters.season = [];
        state.filters.year = [];
        state.page = 1;

        // Clear Select2
        $('#aircraft_type_select').val(null).trigger('change');
        $('#airline_select').val(null).trigger('change');

        // Clear checkboxes
        $('input[type="checkbox"]').prop('checked', false);

        // Clear text inputs
        $('#callsign_prefix_input').val('');
        $('#date_from_input').val('');
        $('#date_to_input').val('');
        $('#hour_min_input').val('');
        $('#hour_max_input').val('');

        // Clear season pills
        $('.routes-season-pill').removeClass('active');

        // Clear map
        if (typeof RoutesMap !== 'undefined') {
            RoutesMap.clearRoutes();
        }

        populateFiltersFromState();
        showNoFiltersState();
        updateUrl();
    }

    function updateClearButton() {
        if (hasActiveFilters()) {
            $('#routes_clear_btn').show();
        } else {
            $('#routes_clear_btn').hide();
        }
    }

    // ========================================================================
    // HISTORY API
    // ========================================================================

    window.addEventListener('popstate', function(e) {
        if (e.state) {
            console.log('[Routes] popstate:', e.state);
            state = e.state;
            populateFiltersFromState();
            if (hasActiveFilters()) {
                doSearch();
            } else {
                showNoFiltersState();
            }
        }
    });

    // ========================================================================
    // UTILITY FUNCTIONS
    // ========================================================================

    function formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        var d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function formatRelativeDate(dateStr) {
        if (!dateStr) return 'N/A';
        var d = new Date(dateStr);
        var now = new Date();
        var diffMs = now - d;
        var diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

        if (diffDays === 0) {
            return PERTII18n.t('common.today') || 'today';
        } else if (diffDays === 1) {
            return 'yesterday';
        } else if (diffDays < 7) {
            return diffDays + ' days ago';
        } else if (diffDays < 30) {
            var weeks = Math.floor(diffDays / 7);
            return weeks + ' week' + (weeks > 1 ? 's' : '') + ' ago';
        } else if (diffDays < 365) {
            var months = Math.floor(diffDays / 30);
            return months + ' month' + (months > 1 ? 's' : '') + ' ago';
        } else {
            var years = Math.floor(diffDays / 365);
            return years + ' year' + (years > 1 ? 's' : '') + ' ago';
        }
    }

})();
