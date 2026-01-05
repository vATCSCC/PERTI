// route-symbology.js - User-configurable route symbology for PERTI
// Provides color, width, opacity, and dash style configuration for route segments
// ═══════════════════════════════════════════════════════════════════════════════

(function() {
    'use strict';

    // ═══════════════════════════════════════════════════════════════════════════
    // DEFAULT CONFIGURATION
    // ═══════════════════════════════════════════════════════════════════════════

    const DEFAULT_SYMBOLOGY = {
        // Segment type defaults
        solid: {
            color: null,       // null = use route color
            width: 3,
            opacity: 1.0,
            dashArray: null    // null = solid line
        },
        dashed: {
            color: null,
            width: 3,
            opacity: 1.0,
            dashArray: [4, 4]
        },
        fan: {
            color: null,
            width: 1.5,
            opacity: 0.8,
            dashArray: [1, 3]
        },
        // Fix/waypoint symbology
        fixes: {
            visible: true,
            color: null,       // null = use route color
            radius: 4,         // circle radius at zoom 8
            opacity: 1.0,
            strokeWidth: 1,
            strokeColor: '#000000',
            labelsVisible: true,
            labelSize: 10,
            labelColor: null,  // null = use route color
            labelHaloWidth: 3,
            labelHaloColor: '#000000'
        },
        // Global overrides (applies to all segments if set)
        global: {
            color: null,
            width: null,
            opacity: null
        }
    };

    // Predefined dash patterns
    const DASH_PATTERNS = {
        'solid': null,
        'dashed': [4, 4],
        'dotted': [1, 3],
        'dash-dot': [6, 3, 1, 3],
        'long-dash': [8, 4],
        'short-dash': [2, 2],
        'dense-dot': [1, 1]
    };

    const DASH_PATTERN_LABELS = {
        'solid': 'Solid',
        'dashed': 'Dashed (- - -)',
        'dotted': 'Dotted (···)',
        'dash-dot': 'Dash-Dot (-·-)',
        'long-dash': 'Long Dash (— —)',
        'short-dash': 'Short Dash (--)',
        'dense-dot': 'Dense Dot'
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // STATE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════════════

    const STORAGE_KEY = 'PERTI_ROUTE_SYMBOLOGY';
    let currentSymbology = null;
    let segmentOverrides = {};  // Map of segmentKey -> symbology override
    let routeOverrides = {};    // Map of routeId -> symbology override
    let onChangeCallbacks = [];

    // ═══════════════════════════════════════════════════════════════════════════
    // PERSISTENCE
    // ═══════════════════════════════════════════════════════════════════════════

    function loadSymbology() {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored) {
                const parsed = JSON.parse(stored);
                currentSymbology = deepMerge(JSON.parse(JSON.stringify(DEFAULT_SYMBOLOGY)), parsed.defaults || {});
                segmentOverrides = parsed.segmentOverrides || {};
                routeOverrides = parsed.routeOverrides || {};
            } else {
                currentSymbology = JSON.parse(JSON.stringify(DEFAULT_SYMBOLOGY));
            }
        } catch (e) {
            console.warn('[SYMBOLOGY] Failed to load settings:', e);
            currentSymbology = JSON.parse(JSON.stringify(DEFAULT_SYMBOLOGY));
        }
        return currentSymbology;
    }

    function saveSymbology() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                defaults: currentSymbology,
                segmentOverrides: segmentOverrides,
                routeOverrides: routeOverrides
            }));
        } catch (e) {
            console.warn('[SYMBOLOGY] Failed to save settings:', e);
        }
    }

    function deepMerge(target, source) {
        for (const key in source) {
            if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
                if (!target[key]) target[key] = {};
                deepMerge(target[key], source[key]);
            } else {
                target[key] = source[key];
            }
        }
        return target;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SYMBOLOGY API
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Get effective symbology for a segment
     * Priority: segment override > route override > segment type default > global
     */
    function getSegmentSymbology(segmentKey, routeId, segmentType, routeColor) {
        const typeDefaults = currentSymbology[segmentType] || currentSymbology.dashed;
        const globalDefaults = currentSymbology.global || {};
        const routeOvr = routeOverrides[routeId] || {};
        const segOvr = segmentOverrides[segmentKey] || {};

        return {
            color: segOvr.color || routeOvr.color || typeDefaults.color || globalDefaults.color || routeColor,
            width: segOvr.width ?? routeOvr.width ?? globalDefaults.width ?? typeDefaults.width,
            opacity: segOvr.opacity ?? routeOvr.opacity ?? globalDefaults.opacity ?? typeDefaults.opacity,
            dashArray: segOvr.dashArray !== undefined ? segOvr.dashArray : 
                       (routeOvr.dashArray !== undefined ? routeOvr.dashArray : typeDefaults.dashArray)
        };
    }

    /**
     * Get default symbology for a segment type
     */
    function getTypeDefaults(segmentType) {
        return currentSymbology[segmentType] || currentSymbology.dashed;
    }

    /**
     * Update segment type defaults
     */
    function setTypeDefaults(segmentType, settings) {
        if (!currentSymbology[segmentType]) {
            currentSymbology[segmentType] = {};
        }
        Object.assign(currentSymbology[segmentType], settings);
        saveSymbology();
        notifyChange();
    }

    /**
     * Update global defaults
     */
    function setGlobalDefaults(settings) {
        Object.assign(currentSymbology.global, settings);
        saveSymbology();
        notifyChange();
    }

    /**
     * Set override for a specific segment
     */
    function setSegmentOverride(segmentKey, settings) {
        if (!settings || Object.keys(settings).length === 0) {
            delete segmentOverrides[segmentKey];
        } else {
            segmentOverrides[segmentKey] = settings;
        }
        saveSymbology();
        notifyChange();
    }

    /**
     * Set override for all segments of a route
     */
    function setRouteOverride(routeId, settings) {
        if (!settings || Object.keys(settings).length === 0) {
            delete routeOverrides[routeId];
        } else {
            routeOverrides[routeId] = settings;
        }
        saveSymbology();
        notifyChange();
    }

    /**
     * Clear segment override
     */
    function clearSegmentOverride(segmentKey) {
        delete segmentOverrides[segmentKey];
        saveSymbology();
        notifyChange();
    }

    /**
     * Clear route override
     */
    function clearRouteOverride(routeId) {
        delete routeOverrides[routeId];
        saveSymbology();
        notifyChange();
    }

    /**
     * Clear all overrides
     */
    function clearAllOverrides() {
        segmentOverrides = {};
        routeOverrides = {};
        saveSymbology();
        notifyChange();
    }

    /**
     * Reset to defaults
     */
    function resetToDefaults() {
        currentSymbology = JSON.parse(JSON.stringify(DEFAULT_SYMBOLOGY));
        segmentOverrides = {};
        routeOverrides = {};
        saveSymbology();
        notifyChange();
    }

    /**
     * Register change callback
     */
    function onChange(callback) {
        if (typeof callback === 'function') {
            onChangeCallbacks.push(callback);
        }
    }

    function notifyChange() {
        onChangeCallbacks.forEach(cb => {
            try { cb(); } catch (e) { console.warn('[SYMBOLOGY] Callback error:', e); }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MAPLIBRE INTEGRATION
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Apply symbology to MapLibre map layers
     */
    function applyToMapLibre(map) {
        if (!map) return;

        const solid = currentSymbology.solid;
        const dashed = currentSymbology.dashed;
        const fan = currentSymbology.fan;
        const global = currentSymbology.global;

        // Apply to routes-solid layer
        if (map.getLayer('routes-solid')) {
            map.setPaintProperty('routes-solid', 'line-width', global.width ?? solid.width);
            map.setPaintProperty('routes-solid', 'line-opacity', global.opacity ?? solid.opacity);
            // Color handled via feature properties unless global override
            if (global.color) {
                map.setPaintProperty('routes-solid', 'line-color', global.color);
            } else if (solid.color) {
                map.setPaintProperty('routes-solid', 'line-color', solid.color);
            } else {
                map.setPaintProperty('routes-solid', 'line-color', ['get', 'color']);
            }
        }

        // Apply to routes-dashed layer
        if (map.getLayer('routes-dashed')) {
            map.setPaintProperty('routes-dashed', 'line-width', global.width ?? dashed.width);
            map.setPaintProperty('routes-dashed', 'line-opacity', global.opacity ?? dashed.opacity);
            if (global.color) {
                map.setPaintProperty('routes-dashed', 'line-color', global.color);
            } else if (dashed.color) {
                map.setPaintProperty('routes-dashed', 'line-color', dashed.color);
            } else {
                map.setPaintProperty('routes-dashed', 'line-color', ['get', 'color']);
            }
            // Apply dash pattern
            const dashPattern = dashed.dashArray || DASH_PATTERNS.dashed;
            if (dashPattern) {
                map.setPaintProperty('routes-dashed', 'line-dasharray', dashPattern);
            }
        }

        // Apply to routes-fan layer
        if (map.getLayer('routes-fan')) {
            map.setPaintProperty('routes-fan', 'line-width', global.width ?? fan.width);
            map.setPaintProperty('routes-fan', 'line-opacity', global.opacity ?? fan.opacity);
            if (global.color) {
                map.setPaintProperty('routes-fan', 'line-color', global.color);
            } else if (fan.color) {
                map.setPaintProperty('routes-fan', 'line-color', fan.color);
            } else {
                map.setPaintProperty('routes-fan', 'line-color', ['get', 'color']);
            }
            // Apply dash pattern
            const dashPattern = fan.dashArray || DASH_PATTERNS.dotted;
            if (dashPattern) {
                map.setPaintProperty('routes-fan', 'line-dasharray', dashPattern);
            }
        }

        // Apply to fix/waypoint layers
        const fixes = currentSymbology.fixes || DEFAULT_SYMBOLOGY.fixes;
        
        // Fix circles (route-fix-points and fixes-circles)
        ['route-fix-points', 'fixes-circles', 'route-fixes-circles'].forEach(layerId => {
            if (!map.getLayer(layerId)) return;
            
            // Visibility
            map.setLayoutProperty(layerId, 'visibility', fixes.visible ? 'visible' : 'none');
            
            // Radius - use zoom interpolation
            const baseRadius = fixes.radius || 4;
            map.setPaintProperty(layerId, 'circle-radius', [
                'interpolate', ['linear'], ['zoom'],
                4, baseRadius * 0.5,
                8, baseRadius,
                12, baseRadius * 1.5
            ]);
            
            // Color
            if (global.color) {
                map.setPaintProperty(layerId, 'circle-color', global.color);
            } else if (fixes.color) {
                map.setPaintProperty(layerId, 'circle-color', fixes.color);
            } else {
                map.setPaintProperty(layerId, 'circle-color', ['get', 'color']);
            }
            
            // Opacity
            map.setPaintProperty(layerId, 'circle-opacity', fixes.opacity ?? 1.0);
            
            // Stroke
            map.setPaintProperty(layerId, 'circle-stroke-width', fixes.strokeWidth ?? 1);
            map.setPaintProperty(layerId, 'circle-stroke-color', fixes.strokeColor || '#000000');
        });
        
        // Fix labels (route-fixes-labels and route-fix-labels layer)
        ['route-fixes-labels', 'route-fix-labels'].forEach(layerId => {
            if (!map.getLayer(layerId)) return;
            
            // Visibility
            const labelsVisible = fixes.visible && (fixes.labelsVisible !== false);
            map.setLayoutProperty(layerId, 'visibility', labelsVisible ? 'visible' : 'none');
            
            // Text size
            const baseSize = fixes.labelSize || 10;
            map.setLayoutProperty(layerId, 'text-size', [
                'interpolate', ['linear'], ['zoom'],
                5, baseSize * 0.9,
                8, baseSize,
                12, baseSize * 1.1,
                16, baseSize * 1.2
            ]);
            
            // Text color
            if (global.color) {
                map.setPaintProperty(layerId, 'text-color', global.color);
            } else if (fixes.labelColor) {
                map.setPaintProperty(layerId, 'text-color', fixes.labelColor);
            } else {
                map.setPaintProperty(layerId, 'text-color', ['get', 'color']);
            }
            
            // Halo
            map.setPaintProperty(layerId, 'text-halo-width', fixes.labelHaloWidth ?? 3);
            map.setPaintProperty(layerId, 'text-halo-color', fixes.labelHaloColor || '#000000');
        });

        console.log('[SYMBOLOGY] Applied symbology to MapLibre layers');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // UI COMPONENTS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Generate HTML for dash pattern selector
     */
    function getDashPatternOptions(selectedKey) {
        return Object.entries(DASH_PATTERN_LABELS).map(([key, label]) => {
            const selected = key === selectedKey ? 'selected' : '';
            return `<option value="${key}" ${selected}>${label}</option>`;
        }).join('');
    }

    /**
     * Get dash pattern key from array
     */
    function getDashPatternKey(dashArray) {
        if (!dashArray) return 'solid';
        const str = JSON.stringify(dashArray);
        for (const [key, pattern] of Object.entries(DASH_PATTERNS)) {
            if (JSON.stringify(pattern) === str) return key;
        }
        return 'dashed'; // default fallback
    }

    /**
     * Parse dash pattern from key
     */
    function parseDashPattern(key) {
        return DASH_PATTERNS[key] || null;
    }

    /**
     * Initialize the symbology settings panel
     */
    function initSettingsPanel() {
        const $panel = $('#route-symbology-panel');
        if (!$panel.length) return;

        loadSymbology();
        updatePanelUI();

        // Bind change handlers for segment type controls
        ['solid', 'dashed', 'fan'].forEach(segType => {
            // Width slider
            $panel.on('input change', `#symb-${segType}-width`, function() {
                const val = parseFloat($(this).val());
                $(`#symb-${segType}-width-val`).text(val.toFixed(1));
                setTypeDefaults(segType, { width: val });
            });

            // Opacity slider
            $panel.on('input change', `#symb-${segType}-opacity`, function() {
                const val = parseFloat($(this).val());
                $(`#symb-${segType}-opacity-val`).text(Math.round(val * 100) + '%');
                setTypeDefaults(segType, { opacity: val });
            });

            // Color picker
            $panel.on('change', `#symb-${segType}-color`, function() {
                const val = $(this).val();
                const useCustom = $(`#symb-${segType}-color-enable`).prop('checked');
                setTypeDefaults(segType, { color: useCustom ? val : null });
            });

            // Color enable checkbox
            $panel.on('change', `#symb-${segType}-color-enable`, function() {
                const useCustom = $(this).prop('checked');
                const colorVal = $(`#symb-${segType}-color`).val();
                $(`#symb-${segType}-color`).prop('disabled', !useCustom);
                setTypeDefaults(segType, { color: useCustom ? colorVal : null });
            });

            // Dash pattern selector
            $panel.on('change', `#symb-${segType}-dash`, function() {
                const key = $(this).val();
                setTypeDefaults(segType, { dashArray: parseDashPattern(key) });
            });
        });

        // Global controls
        $panel.on('input change', '#symb-global-width', function() {
            const val = $(this).val();
            $('#symb-global-width-val').text(val ? parseFloat(val).toFixed(1) : 'Default');
            setGlobalDefaults({ width: val ? parseFloat(val) : null });
        });

        $panel.on('input change', '#symb-global-opacity', function() {
            const val = $(this).val();
            $('#symb-global-opacity-val').text(val ? Math.round(parseFloat(val) * 100) + '%' : 'Default');
            setGlobalDefaults({ opacity: val ? parseFloat(val) : null });
        });

        $panel.on('change', '#symb-global-color-enable', function() {
            const useCustom = $(this).prop('checked');
            const colorVal = $('#symb-global-color').val();
            $('#symb-global-color').prop('disabled', !useCustom);
            setGlobalDefaults({ color: useCustom ? colorVal : null });
        });

        $panel.on('change', '#symb-global-color', function() {
            const val = $(this).val();
            const useCustom = $('#symb-global-color-enable').prop('checked');
            setGlobalDefaults({ color: useCustom ? val : null });
        });

        // Reset button
        $panel.on('click', '#symb-reset-defaults', function() {
            if (confirm('Reset all route symbology settings to defaults?')) {
                resetToDefaults();
                updatePanelUI();
            }
        });

        // Clear overrides button
        $panel.on('click', '#symb-clear-overrides', function() {
            if (confirm('Clear all segment and route overrides?')) {
                clearAllOverrides();
            }
        });

        // Fix symbology controls
        $panel.on('change', '#symb-fixes-visible', function() {
            const visible = $(this).prop('checked');
            setTypeDefaults('fixes', { visible });
        });

        $panel.on('change', '#symb-fixes-labels-visible', function() {
            const labelsVisible = $(this).prop('checked');
            setTypeDefaults('fixes', { labelsVisible });
        });

        $panel.on('input change', '#symb-fixes-radius', function() {
            const val = parseFloat($(this).val());
            $('#symb-fixes-radius-val').text(val.toFixed(1));
            setTypeDefaults('fixes', { radius: val });
        });

        $panel.on('input change', '#symb-fixes-opacity', function() {
            const val = parseFloat($(this).val());
            $('#symb-fixes-opacity-val').text(Math.round(val * 100) + '%');
            setTypeDefaults('fixes', { opacity: val });
        });

        $panel.on('change', '#symb-fixes-color-enable', function() {
            const useCustom = $(this).prop('checked');
            const colorVal = $('#symb-fixes-color').val();
            $('#symb-fixes-color').prop('disabled', !useCustom);
            setTypeDefaults('fixes', { color: useCustom ? colorVal : null });
        });

        $panel.on('change', '#symb-fixes-color', function() {
            const val = $(this).val();
            const useCustom = $('#symb-fixes-color-enable').prop('checked');
            setTypeDefaults('fixes', { color: useCustom ? val : null });
        });

        $panel.on('input change', '#symb-fixes-stroke-width', function() {
            const val = parseFloat($(this).val());
            $('#symb-fixes-stroke-width-val').text(val.toFixed(1));
            setTypeDefaults('fixes', { strokeWidth: val });
        });

        $panel.on('change', '#symb-fixes-stroke-color', function() {
            setTypeDefaults('fixes', { strokeColor: $(this).val() });
        });

        $panel.on('input change', '#symb-fixes-label-size', function() {
            const val = parseFloat($(this).val());
            $('#symb-fixes-label-size-val').text(val.toFixed(0));
            setTypeDefaults('fixes', { labelSize: val });
        });

        $panel.on('change', '#symb-fixes-label-color-enable', function() {
            const useCustom = $(this).prop('checked');
            const colorVal = $('#symb-fixes-label-color').val();
            $('#symb-fixes-label-color').prop('disabled', !useCustom);
            setTypeDefaults('fixes', { labelColor: useCustom ? colorVal : null });
        });

        $panel.on('change', '#symb-fixes-label-color', function() {
            const val = $(this).val();
            const useCustom = $('#symb-fixes-label-color-enable').prop('checked');
            setTypeDefaults('fixes', { labelColor: useCustom ? val : null });
        });

        $panel.on('input change', '#symb-fixes-halo-width', function() {
            const val = parseFloat($(this).val());
            $('#symb-fixes-halo-width-val').text(val.toFixed(1));
            setTypeDefaults('fixes', { labelHaloWidth: val });
        });

        $panel.on('change', '#symb-fixes-halo-color', function() {
            setTypeDefaults('fixes', { labelHaloColor: $(this).val() });
        });

        // Show/Hide All Fixes button
        $panel.on('click', '#symb-toggle-all-fixes', function() {
            const fixes = currentSymbology.fixes || {};
            const newVisible = !fixes.visible;
            setTypeDefaults('fixes', { visible: newVisible });
            updatePanelUI();
        });

        console.log('[SYMBOLOGY] Settings panel initialized');
    }

    /**
     * Update panel UI from current settings
     */
    function updatePanelUI() {
        const $panel = $('#route-symbology-panel');
        if (!$panel.length) return;

        ['solid', 'dashed', 'fan'].forEach(segType => {
            const settings = currentSymbology[segType] || {};
            
            // Width
            $(`#symb-${segType}-width`).val(settings.width || 3);
            $(`#symb-${segType}-width-val`).text((settings.width || 3).toFixed(1));
            
            // Opacity
            $(`#symb-${segType}-opacity`).val(settings.opacity ?? 1.0);
            $(`#symb-${segType}-opacity-val`).text(Math.round((settings.opacity ?? 1.0) * 100) + '%');
            
            // Color
            const hasColor = settings.color != null;
            $(`#symb-${segType}-color-enable`).prop('checked', hasColor);
            $(`#symb-${segType}-color`).prop('disabled', !hasColor);
            if (settings.color) {
                $(`#symb-${segType}-color`).val(settings.color);
            }
            
            // Dash pattern
            const dashKey = getDashPatternKey(settings.dashArray);
            $(`#symb-${segType}-dash`).val(dashKey);
        });

        // Global
        const global = currentSymbology.global || {};
        $('#symb-global-width').val(global.width ?? '');
        $('#symb-global-width-val').text(global.width ? global.width.toFixed(1) : 'Default');
        $('#symb-global-opacity').val(global.opacity ?? '');
        $('#symb-global-opacity-val').text(global.opacity ? Math.round(global.opacity * 100) + '%' : 'Default');
        const hasGlobalColor = global.color != null;
        $('#symb-global-color-enable').prop('checked', hasGlobalColor);
        $('#symb-global-color').prop('disabled', !hasGlobalColor);
        if (global.color) {
            $('#symb-global-color').val(global.color);
        }

        // Override counts
        const segCount = Object.keys(segmentOverrides).length;
        const routeCount = Object.keys(routeOverrides).length;
        $('#symb-override-count').text(`${segCount} segment${segCount !== 1 ? 's' : ''}, ${routeCount} route${routeCount !== 1 ? 's' : ''}`);

        // Fix symbology
        const fixes = currentSymbology.fixes || DEFAULT_SYMBOLOGY.fixes;
        
        $('#symb-fixes-visible').prop('checked', fixes.visible !== false);
        $('#symb-fixes-labels-visible').prop('checked', fixes.labelsVisible !== false);
        
        $('#symb-fixes-radius').val(fixes.radius || 4);
        $('#symb-fixes-radius-val').text((fixes.radius || 4).toFixed(1));
        
        $('#symb-fixes-opacity').val(fixes.opacity ?? 1.0);
        $('#symb-fixes-opacity-val').text(Math.round((fixes.opacity ?? 1.0) * 100) + '%');
        
        const hasFixColor = fixes.color != null;
        $('#symb-fixes-color-enable').prop('checked', hasFixColor);
        $('#symb-fixes-color').prop('disabled', !hasFixColor);
        if (fixes.color) $('#symb-fixes-color').val(fixes.color);
        
        $('#symb-fixes-stroke-width').val(fixes.strokeWidth ?? 1);
        $('#symb-fixes-stroke-width-val').text((fixes.strokeWidth ?? 1).toFixed(1));
        $('#symb-fixes-stroke-color').val(fixes.strokeColor || '#000000');
        
        $('#symb-fixes-label-size').val(fixes.labelSize || 10);
        $('#symb-fixes-label-size-val').text((fixes.labelSize || 10).toFixed(0));
        
        const hasLabelColor = fixes.labelColor != null;
        $('#symb-fixes-label-color-enable').prop('checked', hasLabelColor);
        $('#symb-fixes-label-color').prop('disabled', !hasLabelColor);
        if (fixes.labelColor) $('#symb-fixes-label-color').val(fixes.labelColor);
        
        $('#symb-fixes-halo-width').val(fixes.labelHaloWidth ?? 3);
        $('#symb-fixes-halo-width-val').text((fixes.labelHaloWidth ?? 3).toFixed(1));
        $('#symb-fixes-halo-color').val(fixes.labelHaloColor || '#000000');
        
        // Update toggle button text
        $('#symb-toggle-all-fixes').html(
            fixes.visible !== false 
                ? '<i class="fas fa-eye-slash"></i> Hide All' 
                : '<i class="fas fa-eye"></i> Show All'
        );
    }

    /**
     * Show segment style editor popup
     */
    function showSegmentEditor(lngLat, segmentKey, routeId, currentStyle, onSave) {
        const popup = document.createElement('div');
        popup.className = 'symbology-segment-editor';
        popup.innerHTML = `
            <div class="symb-editor-header">
                <strong>Segment Style</strong>
                <button type="button" class="close symb-editor-close">&times;</button>
            </div>
            <div class="symb-editor-body">
                <div class="form-group mb-2">
                    <label class="small mb-1">COLOR</label>
                    <div class="d-flex align-items-center">
                        <input type="checkbox" id="seg-color-enable" class="mr-2" ${currentStyle.color ? 'checked' : ''}>
                        <input type="color" id="seg-color" value="${currentStyle.color || '#C70039'}" 
                               class="form-control form-control-sm" style="width: 60px; padding: 2px;" ${currentStyle.color ? '' : 'disabled'}>
                    </div>
                </div>
                <div class="form-group mb-2">
                    <label class="small mb-1">WIDTH</label>
                    <div class="d-flex align-items-center">
                        <input type="range" id="seg-width" min="0.5" max="8" step="0.5" value="${currentStyle.width || 3}" class="form-control-range flex-grow-1">
                        <span class="ml-2 small" id="seg-width-val">${(currentStyle.width || 3).toFixed(1)}</span>
                    </div>
                </div>
                <div class="form-group mb-2">
                    <label class="small mb-1">OPACITY</label>
                    <div class="d-flex align-items-center">
                        <input type="range" id="seg-opacity" min="0.1" max="1" step="0.1" value="${currentStyle.opacity || 1}" class="form-control-range flex-grow-1">
                        <span class="ml-2 small" id="seg-opacity-val">${Math.round((currentStyle.opacity || 1) * 100)}%</span>
                    </div>
                </div>
                <div class="form-group mb-2">
                    <label class="small mb-1">DASH STYLE</label>
                    <select id="seg-dash" class="form-control form-control-sm">
                        ${getDashPatternOptions(getDashPatternKey(currentStyle.dashArray))}
                    </select>
                </div>
                <div class="d-flex justify-content-between mt-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="seg-clear">Clear Override</button>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-info mr-1" id="seg-apply-route">Apply to Route</button>
                        <button type="button" class="btn btn-sm btn-primary" id="seg-apply">Apply</button>
                    </div>
                </div>
            </div>
        `;

        // Create MapLibre popup
        const mlPopup = new maplibregl.Popup({ closeButton: false, closeOnClick: true, maxWidth: '280px' })
            .setLngLat(lngLat)
            .setDOMContent(popup);

        // Bind events
        popup.querySelector('.symb-editor-close').addEventListener('click', () => mlPopup.remove());
        
        popup.querySelector('#seg-color-enable').addEventListener('change', function() {
            popup.querySelector('#seg-color').disabled = !this.checked;
        });

        popup.querySelector('#seg-width').addEventListener('input', function() {
            popup.querySelector('#seg-width-val').textContent = parseFloat(this.value).toFixed(1);
        });

        popup.querySelector('#seg-opacity').addEventListener('input', function() {
            popup.querySelector('#seg-opacity-val').textContent = Math.round(parseFloat(this.value) * 100) + '%';
        });

        popup.querySelector('#seg-clear').addEventListener('click', () => {
            clearSegmentOverride(segmentKey);
            mlPopup.remove();
            if (onSave) onSave();
        });

        popup.querySelector('#seg-apply').addEventListener('click', () => {
            const settings = {
                color: popup.querySelector('#seg-color-enable').checked ? popup.querySelector('#seg-color').value : null,
                width: parseFloat(popup.querySelector('#seg-width').value),
                opacity: parseFloat(popup.querySelector('#seg-opacity').value),
                dashArray: parseDashPattern(popup.querySelector('#seg-dash').value)
            };
            setSegmentOverride(segmentKey, settings);
            mlPopup.remove();
            if (onSave) onSave();
        });

        popup.querySelector('#seg-apply-route').addEventListener('click', () => {
            const settings = {
                color: popup.querySelector('#seg-color-enable').checked ? popup.querySelector('#seg-color').value : null,
                width: parseFloat(popup.querySelector('#seg-width').value),
                opacity: parseFloat(popup.querySelector('#seg-opacity').value),
                dashArray: parseDashPattern(popup.querySelector('#seg-dash').value)
            };
            setRouteOverride(routeId, settings);
            mlPopup.remove();
            if (onSave) onSave();
        });

        return mlPopup;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════════

    window.RouteSymbology = {
        // Initialization
        load: loadSymbology,
        initPanel: initSettingsPanel,

        // Getters
        getSegmentSymbology,
        getTypeDefaults,
        getDefaults: () => currentSymbology,
        getSegmentOverrides: () => segmentOverrides,
        getRouteOverrides: () => routeOverrides,

        // Setters
        setTypeDefaults,
        setGlobalDefaults,
        setSegmentOverride,
        setRouteOverride,

        // Clear/Reset
        clearSegmentOverride,
        clearRouteOverride,
        clearAllOverrides,
        resetToDefaults,

        // MapLibre integration
        applyToMapLibre,

        // UI
        showSegmentEditor,
        updatePanelUI,

        // Fix visibility
        toggleFixesVisible: function() {
            const fixes = currentSymbology.fixes || {};
            setTypeDefaults('fixes', { visible: !fixes.visible });
        },
        setFixesVisible: function(visible) {
            setTypeDefaults('fixes', { visible: !!visible });
        },
        areFixesVisible: function() {
            return (currentSymbology.fixes || {}).visible !== false;
        },
        getFixDefaults: function() {
            return currentSymbology.fixes || DEFAULT_SYMBOLOGY.fixes;
        },

        // Utilities
        getDashPatternOptions,
        getDashPatternKey,
        parseDashPattern,
        DASH_PATTERNS,
        DASH_PATTERN_LABELS,

        // Events
        onChange
    };

    // Auto-load on document ready
    $(document).ready(function() {
        loadSymbology();
    });

})();
