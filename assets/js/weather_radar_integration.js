/**
 * Weather Radar Integration Example
 * Shows how to add weather radar to existing TSD map
 *
 * Prerequisites:
 *   - MapLibre GL JS loaded
 *   - weather_radar.js loaded
 *   - weather_radar.css loaded
 *
 * Usage:
 *   1. Call initWeatherRadar(map) after map loads
 *   2. Add control panel HTML to your map container
 *   3. Radar will be available in layer controls
 */

// ============================================================================
// INITIALIZATION
// ============================================================================

/**
 * Initialize weather radar on map
 * @param {maplibregl.Map} map - MapLibre GL map instance
 * @param {Object} options - Configuration options
 */
function initWeatherRadar(map, options = {}) {
    // Default options
    const config = {
        position: 'top-right',      // Control panel position
        defaultEnabled: false,       // Start with radar enabled?
        defaultProduct: 'nexrad-n0q', // Default radar product
        defaultOpacity: 0.6,         // Default opacity
        defaultColorTable: 'NWS',    // Default color table
        showLegend: true,            // Show color legend
        showAnimation: true,         // Show animation controls
        autoRefresh: true,           // Auto-refresh radar data
        ...options,
    };

    // Wait for map to load
    if (!map.loaded()) {
        map.on('load', () => initWeatherRadar(map, options));
        return;
    }

    // Initialize the WeatherRadar module
    WeatherRadar.init(map, {
        product: config.defaultProduct,
        opacity: config.defaultOpacity,
        colorTable: config.defaultColorTable,
    });

    // Add control panel to map
    addRadarControlPanel(map, config);

    // Enable if configured
    if (config.defaultEnabled) {
        WeatherRadar.enable();
        document.getElementById('radar-enabled-toggle').checked = true;
        document.querySelector('.radar-settings').classList.add('active');
    }

    // Bind keyboard shortcuts
    bindKeyboardShortcuts();

    console.log('[TSD] Weather radar initialized');

    return WeatherRadar;
}

// ============================================================================
// UI COMPONENTS
// ============================================================================

/**
 * Add radar control panel to map
 */
function addRadarControlPanel(map, config) {
    // Create control container
    const controlDiv = document.createElement('div');
    controlDiv.className = 'maplibregl-ctrl maplibregl-ctrl-group';
    controlDiv.id = 'radar-control-container';

    // Generate control panel HTML
    controlDiv.innerHTML = `
        <div class="radar-controls" id="radar-panel">
            <div class="radar-header">
                <label class="radar-toggle">
                    <input type="checkbox" id="radar-enabled-toggle">
                    <span><span class="radar-live-indicator" style="display:none;"></span>NEXRAD</span>
                </label>
                <span id="radar-timestamp" class="radar-time">--:--Z</span>
            </div>
            
            <div class="radar-settings">
                <!-- Product Selection -->
                <div class="radar-row">
                    <label>${PERTII18n.t('weather.product')}</label>
                    <select id="radar-product-select">
                        <option value="nexrad-n0q">${PERTII18n.t('weather.productBaseReflectivity')}</option>
                        <option value="q2-hsr">${PERTII18n.t('weather.productMrmsHsr')}</option>
                        <option value="nexrad-eet">${PERTII18n.t('weather.productEchoTops')}</option>
                        <option value="q2-p1h">${PERTII18n.t('weather.product1HrPrecip')}</option>
                    </select>
                </div>

                <!-- Color Table Selection -->
                <div class="radar-row">
                    <label>${PERTII18n.t('weather.colors')}</label>
                    <select id="radar-color-select">
                        <option value="NWS">${PERTII18n.t('weather.colorNws')}</option>
                        <option value="FAA_ATC">${PERTII18n.t('weather.colorFaaAtc')}</option>
                        <option value="SCOPE">${PERTII18n.t('weather.colorScope')}</option>
                    </select>
                </div>

                <!-- Opacity Control -->
                <div class="radar-row">
                    <label>${PERTII18n.t('weather.opacity')}</label>
                    <input type="range" id="radar-opacity-slider" 
                           min="0" max="100" value="${config.defaultOpacity * 100}">
                    <span id="radar-opacity-value">${Math.round(config.defaultOpacity * 100)}%</span>
                </div>
                
                ${config.showAnimation ? `
                <!-- Animation Controls -->
                <div class="radar-animation-controls">
                    <button id="radar-prev-btn" title="${PERTII18n.t('weather.previousFrame')} (←)">◀◀</button>
                    <button id="radar-play-btn" title="${PERTII18n.t('weather.playPause')} (Space)">▶</button>
                    <button id="radar-next-btn" title="${PERTII18n.t('weather.nextFrame')} (→)">▶▶</button>
                    <button id="radar-refresh-btn" title="${PERTII18n.t('common.refresh')} (R)">↻</button>
                </div>
                
                <!-- Progress Bar -->
                <div class="radar-progress">
                    <div class="radar-progress-bar" id="radar-progress-bar" style="width: 100%;"></div>
                </div>
                ` : ''}
                
                ${config.showLegend ? `
                <!-- Legend -->
                <div class="radar-legend" id="radar-legend">
                    <div class="legend-title">${PERTII18n.t('weather.legendReflectivity')}</div>
                    <div class="legend-gradient"></div>
                    <div class="legend-labels">
                        <span>5</span>
                        <span>20</span>
                        <span>35</span>
                        <span>50</span>
                        <span>65+</span>
                    </div>
                </div>
                ` : ''}
            </div>
        </div>
    `;

    // Add to map control container
    const mapContainer = map.getContainer();
    const controlContainer = mapContainer.querySelector(`.maplibregl-ctrl-${config.position}`);

    if (controlContainer) {
        controlContainer.insertBefore(controlDiv, controlContainer.firstChild);
    } else {
        // Fallback: create control container
        const newContainer = document.createElement('div');
        newContainer.className = `maplibregl-ctrl-${config.position}`;
        newContainer.appendChild(controlDiv);
        mapContainer.appendChild(newContainer);
    }

    // Bind event handlers
    bindControlEvents(config);
}

/**
 * Bind control panel event handlers
 */
function bindControlEvents(config) {
    // Enable toggle
    const enableToggle = document.getElementById('radar-enabled-toggle');
    enableToggle?.addEventListener('change', (e) => {
        if (e.target.checked) {
            WeatherRadar.enable();
            document.querySelector('.radar-settings')?.classList.add('active');
            document.querySelector('.radar-live-indicator').style.display = 'inline-block';
        } else {
            WeatherRadar.disable();
            document.querySelector('.radar-settings')?.classList.remove('active');
            document.querySelector('.radar-live-indicator').style.display = 'none';
        }
    });

    // Product select
    document.getElementById('radar-product-select')?.addEventListener('change', (e) => {
        WeatherRadar.setProduct(e.target.value);
        updateLegendForProduct(e.target.value);
    });

    // Color table select
    document.getElementById('radar-color-select')?.addEventListener('change', (e) => {
        WeatherRadar.setColorTable(e.target.value);
        updateLegendForColorTable(e.target.value);
    });

    // Opacity slider
    const opacitySlider = document.getElementById('radar-opacity-slider');
    const opacityValue = document.getElementById('radar-opacity-value');
    opacitySlider?.addEventListener('input', (e) => {
        const opacity = parseInt(e.target.value) / 100;
        WeatherRadar.setOpacity(opacity);
        if (opacityValue) {opacityValue.textContent = `${e.target.value}%`;}
    });

    // Animation controls
    document.getElementById('radar-prev-btn')?.addEventListener('click', () => {
        WeatherRadar.prevFrame();
    });

    document.getElementById('radar-next-btn')?.addEventListener('click', () => {
        WeatherRadar.nextFrame();
    });

    document.getElementById('radar-play-btn')?.addEventListener('click', () => {
        const playing = WeatherRadar.toggleAnimation();
        const btn = document.getElementById('radar-play-btn');
        if (btn) {
            btn.textContent = playing ? '⏸' : '▶';
            btn.classList.toggle('playing', playing);
        }
    });

    document.getElementById('radar-refresh-btn')?.addEventListener('click', () => {
        WeatherRadar.refresh();
    });

    // Listen for frame changes to update progress bar
    document.addEventListener('radar-frame-change', (e) => {
        const { frameIndex, totalFrames } = e.detail;
        const progress = ((frameIndex + 1) / totalFrames) * 100;
        const progressBar = document.getElementById('radar-progress-bar');
        if (progressBar) {
            progressBar.style.width = `${progress}%`;
        }

        // Update timestamp display
        if (e.detail.timestamp) {
            const timestamp = document.getElementById('radar-timestamp');
            if (timestamp) {
                timestamp.textContent = e.detail.timestamp.label || '--:--Z';
            }
        }
    });
}

/**
 * Bind keyboard shortcuts for radar control
 */
function bindKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
        // Ignore if typing in input
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {return;}

        const state = WeatherRadar.getState();
        if (!state.enabled) {return;}

        switch (e.key) {
            case ' ':
            case 'Space': {
                e.preventDefault();
                WeatherRadar.toggleAnimation();
                const btn = document.getElementById('radar-play-btn');
                if (btn) {
                    btn.textContent = state.animating ? '▶' : '⏸';
                    btn.classList.toggle('playing', !state.animating);
                }
                break;
            }

            case 'ArrowLeft':
                e.preventDefault();
                WeatherRadar.prevFrame();
                break;

            case 'ArrowRight':
                e.preventDefault();
                WeatherRadar.nextFrame();
                break;

            case 'r':
            case 'R':
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    WeatherRadar.refresh();
                }
                break;
        }
    });
}

/**
 * Update legend for selected product
 */
function updateLegendForProduct(productId) {
    const legendTitle = document.querySelector('.radar-legend .legend-title');
    if (!legendTitle) {return;}

    const products = {
        'nexrad-n0q': PERTII18n.t('weather.legendReflectivity'),
        'q2-hsr': PERTII18n.t('weather.legendMrmsReflectivity'),
        'nexrad-eet': PERTII18n.t('weather.legendEchoTops'),
        'q2-p1h': PERTII18n.t('weather.legend1HrPrecip'),
    };

    legendTitle.textContent = products[productId] || PERTII18n.t('weather.legendReflectivity');
}

/**
 * Update legend for selected color table
 */
function updateLegendForColorTable(tableId) {
    const legendContainer = document.getElementById('radar-legend');
    if (!legendContainer) {return;}

    if (tableId === 'FAA_ATC') {
        legendContainer.innerHTML = `
            <div class="legend-title">${PERTII18n.t('weather.legendPrecipIntensity')}</div>
            <div class="legend-faa">
                <div class="legend-item">
                    <span class="legend-color wx-green"></span>
                    <span class="legend-label">${PERTII18n.t('weather.legendLight')}</span>
                </div>
                <div class="legend-item">
                    <span class="legend-color wx-yellow"></span>
                    <span class="legend-label">${PERTII18n.t('weather.legendModerate')}</span>
                </div>
                <div class="legend-item">
                    <span class="legend-color wx-red"></span>
                    <span class="legend-label">${PERTII18n.t('weather.legendHeavyExtreme')}</span>
                </div>
            </div>
        `;
    } else {
        legendContainer.innerHTML = `
            <div class="legend-title">${PERTII18n.t('weather.legendReflectivity')}</div>
            <div class="legend-gradient"></div>
            <div class="legend-labels">
                <span>5</span>
                <span>20</span>
                <span>35</span>
                <span>50</span>
                <span>65+</span>
            </div>
        `;
    }
}

// ============================================================================
// LAYER PANEL INTEGRATION
// ============================================================================

/**
 * Add radar to existing layer panel
 * Call this if you have a layer management panel
 */
function addRadarToLayerPanel(layerPanelId) {
    const layerPanel = document.getElementById(layerPanelId);
    if (!layerPanel) {return;}

    const radarItem = document.createElement('div');
    radarItem.className = 'layer-item layer-item-radar';
    radarItem.innerHTML = `
        <input type="checkbox" id="layer-radar-toggle">
        <span class="layer-icon"></span>
        <span class="layer-label">${PERTII18n.t('weather.radar')}</span>
        <span class="layer-badge">${PERTII18n.t('weather.layerLive')}</span>
    `;

    // Find weather group or create it
    let weatherGroup = layerPanel.querySelector('.layer-group-weather');
    if (!weatherGroup) {
        weatherGroup = document.createElement('div');
        weatherGroup.className = 'layer-group layer-group-weather';
        weatherGroup.innerHTML = '<div class="layer-group-title">' + PERTII18n.t('weather.layerGroupWeather') + '</div>';
        layerPanel.appendChild(weatherGroup);
    }

    weatherGroup.appendChild(radarItem);

    // Sync with main toggle
    const layerToggle = radarItem.querySelector('#layer-radar-toggle');
    const mainToggle = document.getElementById('radar-enabled-toggle');

    layerToggle?.addEventListener('change', (e) => {
        if (mainToggle) {mainToggle.checked = e.target.checked;}
        mainToggle?.dispatchEvent(new Event('change'));
    });

    // Keep in sync
    document.addEventListener('radar-enabled', () => {
        if (layerToggle) {layerToggle.checked = true;}
    });

    document.addEventListener('radar-disabled', () => {
        if (layerToggle) {layerToggle.checked = false;}
    });
}

// ============================================================================
// EXPORT
// ============================================================================

// Make functions globally available
window.initWeatherRadar = initWeatherRadar;
window.addRadarToLayerPanel = addRadarToLayerPanel;

// Auto-init if map already exists
if (typeof map !== 'undefined' && map && map.loaded && map.loaded()) {
    console.log('[TSD] Auto-initializing weather radar...');
    initWeatherRadar(map);
}
