/**
 * Airspace Splits Configuration System
 * 
 * Manages sector boundary visualization, area definitions, and split configurations.
 * Integrates with backend API for global configuration sharing.
 */

const SplitsController = {
    // ═══════════════════════════════════════════════════════════════════
    // STATE
    // ═══════════════════════════════════════════════════════════════════
    
    map: null,
    sectors: {},           // sector_id -> { geometry, name, artcc, sectorNum, color, ... }
    areas: [],             // Array of area definitions from server
    areaColors: {},        // area_id -> color (user-configurable)
    traconsData: {},       // tracon_id -> { tracon_name, airports_served, responsible_artcc, ... }
    traconsDataByName: {}, // tracon_name (uppercase) -> same data (for GeoJSON label matching)
    myConfigs: [],         // User's draft/saved configs
    activeConfigs: [],     // Currently active configs (from server)
    scheduledConfigs: [],  // Scheduled/upcoming configs
    presets: [],           // Saved preset templates
    visiblePresets: null,  // Set of visible preset IDs
    
    // GeoJSON cache
    geoJsonCache: { high: null, low: null, superhigh: null, artcc: null, tracon: null },
    
    // Layer visibility state
    layerVisibility: {
        artcc: true,
        high: false,
        low: false,
        superhigh: false,
        tracon: false,
        areas: false,
        presets: false,
        activeConfigs: true
    },
    
    // Active splits strata visibility
    activeSplitsStrata: {
        low: true,
        high: true,
        superhigh: true
    },
    
    // Datablock state - tracks visible split info datablocks on map
    // Key: positionKey (e.g., "configId-positionName"), Value: { element, leaderLine, labelCoords, position }
    activeDatablocks: new Map(),
    
    // Current wizard state
    currentConfig: {
        id: null,
        artcc: null,
        name: '',
        startTime: null,
        endTime: null,
        sectorType: 'all',
        splits: []
    },
    editingSplitIndex: -1,
    editingAreaId: null,
    currentStep: 1,
    
    // Track which modal is being hidden for map selection ('config', 'area', 'preset', or null)
    _mapSelectionSourceModal: null,
    
    // Color palette
    colorPalette: [
        '#e63946', '#f4a261', '#e9c46a', '#2a9d8f', '#264653', '#a8dadc',
        '#457b9d', '#1d3557', '#f72585', '#7209b7', '#3a0ca3', '#4361ee',
        '#4cc9f0', '#80ed99', '#57cc99', '#38a3a5', '#22577a', '#c9184a',
        '#ff6b6b', '#ffd93d', '#6bcb77', '#4d96ff', '#845ef7', '#f06595',
        '#20c997', '#fd7e14', '#6610f2', '#d63384', '#0dcaf0', '#198754'
    ],
    
    // ARTCC list (populated from data)
    artccList: [],
    
    // ARTCC centers for map zoom
    artccCenters: {
        'ZAB': [-109.5, 33.5], 'ZAK': [-155, 58], 'ZAN': [-150, 64], 'ZAU': [-88, 42],
        'ZBW': [-71, 42.5], 'ZDC': [-77, 39], 'ZDV': [-105, 40], 'ZFW': [-97, 33],
        'ZHN': [-157, 21], 'ZHU': [-95, 30], 'ZID': [-86, 40], 'ZJX': [-82, 30],
        'ZKC': [-95, 39], 'ZLA': [-118, 34], 'ZLC': [-112, 42], 'ZMA': [-80, 26],
        'ZME': [-90, 35], 'ZMP': [-94, 45], 'ZNY': [-74, 41], 'ZOA': [-122, 38],
        'ZOB': [-82, 41], 'ZSE': [-122, 47], 'ZSU': [-66, 18], 'ZTL': [-84, 34]
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // INITIALIZATION
    // ═══════════════════════════════════════════════════════════════════
    
    init() {
        console.log('[SPLITS] Initializing...');
        this.initMap();
        this.bindEvents();
        this.preloadGeoJson();
        this.loadAreas();
        this.loadActiveConfigs();
        this.loadScheduledConfigs();
        this.loadPresets();
        this.loadTracons();
        this.initActiveSplitsToggle();
    },
    
    /**
     * Initialize the Active Splits toggle button
     */
    initActiveSplitsToggle() {
        const toggleBtn = document.getElementById('active-splits-toggle-btn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                this.toggleActiveSplitsPanel();
            });
        }
        
        // Update panel close button to use toggle (hide instead of remove)
        const panel = document.getElementById('active-configs-panel');
        if (panel) {
            const closeBtn = panel.querySelector('.close-panel-btn');
            if (closeBtn) {
                closeBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.toggleActiveSplitsPanel(false);
                });
            }
        }
    },
    
    async preloadGeoJson() {
        const files = { 
            high: 'assets/geojson/high.json', 
            low: 'assets/geojson/low.json',
            superhigh: 'assets/geojson/superhigh.json',
            artcc: 'assets/geojson/artcc.json',
            tracon: 'assets/geojson/tracon.json'
        };
        const foundArtccs = new Set();
        
        for (const [key, url] of Object.entries(files)) {
            try {
                const response = await fetch(url);
                if (response.ok) {
                    this.geoJsonCache[key] = await response.json();
                    console.log(`[SPLITS] Loaded ${key}.json: ${this.geoJsonCache[key].features?.length || 0} features`);
                    
                    // Extract ARTCCs from high/low/superhigh sectors
                    if (key === 'high' || key === 'low' || key === 'superhigh') {
                        this.geoJsonCache[key].features?.forEach(f => {
                            const artcc = f.properties?.artcc;
                            if (artcc) foundArtccs.add(artcc.toUpperCase());
                        });
                    }
                }
            } catch (err) {
                console.warn(`[SPLITS] Failed to load ${url}:`, err);
            }
        }
        
        if (foundArtccs.size > 0) {
            this.artccList = Array.from(foundArtccs).sort();
            this.populateArtccDropdowns();
            console.log(`[SPLITS] Found ${this.artccList.length} ARTCCs`);
        }
        
        // Add base layers to map after loading
        this.addBaseLayers();
    },
    
    initMap() {
        this.map = new maplibregl.Map({
            container: 'splits-map',
            style: {
                version: 8,
                glyphs: 'https://cdn.protomaps.com/fonts/pbf/{fontstack}/{range}.pbf',
                sources: {
                    'carto-dark': {
                        type: 'raster',
                        tiles: [
                            'https://a.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
                            'https://b.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
                            'https://c.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png'
                        ],
                        tileSize: 256,
                        attribution: '© CARTO'
                    }
                },
                layers: [{ id: 'carto-dark-layer', type: 'raster', source: 'carto-dark' }]
            },
            center: [-98, 39],
            zoom: 4
        });
        
        this.map.addControl(new maplibregl.NavigationControl(), 'top-left');
        
        this.map.on('load', () => {
            this.addMapLayers();
        });
    },
    
    addMapLayers() {
        // Sector polygons
        this.map.addSource('sectors', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        this.map.addLayer({
            id: 'sectors-fill',
            type: 'fill',
            source: 'sectors',
            paint: {
                'fill-color': ['get', 'color'],
                'fill-opacity': ['case', ['get', 'selected'], 0.85, 0.75]
            }
        });
        
        this.map.addLayer({
            id: 'sectors-lines',
            type: 'line',
            source: 'sectors',
            paint: {
                'line-color': ['get', 'color'],
                'line-width': ['case', ['get', 'selected'], 2.5, 1.5],
                'line-opacity': 0
            }
        });
        
        // Labels
        this.map.addSource('sector-labels', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        this.map.addLayer({
            id: 'sectors-labels',
            type: 'symbol',
            source: 'sector-labels',
            layout: {
                'text-field': ['get', 'label'],
                'text-size': 12,
                'text-font': ['Noto Sans Bold'],
                'text-anchor': 'center',
                'text-allow-overlap': true
            },
            paint: {
                'text-color': '#ffffff',
                'text-halo-color': ['get', 'color'],
                'text-halo-width': 3
            }
        });
        
        console.log('[SPLITS] Map layers initialized');
        
        // Initialize map click handlers
        this.initMapClickHandler();
    },
    
    addBaseLayers() {
        if (!this.map) {
            console.log('[SPLITS] Map not initialized, deferring addBaseLayers');
            setTimeout(() => this.addBaseLayers(), 200);
            return;
        }
        
        // Use isStyleLoaded() instead of loaded() - more reliable
        if (!this.map.isStyleLoaded()) {
            console.log('[SPLITS] Map style not loaded, deferring addBaseLayers');
            setTimeout(() => this.addBaseLayers(), 200);
            return;
        }
        
        // Wait for sectors-fill layer to exist (created in addMapLayers)
        if (!this.map.getLayer('sectors-fill')) {
            console.log('[SPLITS] sectors-fill layer not ready, deferring addBaseLayers');
            setTimeout(() => this.addBaseLayers(), 200);
            return;
        }
        
        console.log('[SPLITS] Adding base layers now...');
        
        // Add ARTCC boundaries
        if (this.geoJsonCache.artcc && !this.map.getSource('artcc-source')) {
            console.log('[SPLITS] Adding ARTCC layer with', this.geoJsonCache.artcc.features?.length, 'features');
            
            try {
                this.map.addSource('artcc-source', {
                    type: 'geojson',
                    data: this.geoJsonCache.artcc
                });
                
                this.map.addLayer({
                    id: 'artcc-fill',
                    type: 'fill',
                    source: 'artcc-source',
                    paint: {
                        'fill-color': '#FF00FF',
                        'fill-opacity': 0
                    }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'artcc-lines',
                    type: 'line',
                    source: 'artcc-source',
                    paint: {
                        'line-color': '#FF00FF',
                        'line-width': 2,
                        'line-opacity': 0.5
                    }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'artcc-labels',
                    type: 'symbol',
                    source: 'artcc-source',
                    layout: {
                        'text-field': ['coalesce', ['get', 'id'], ['get', 'name'], ['get', 'ID']],
                        'text-size': 14,
                        'text-font': ['Noto Sans Bold'],
                        'text-anchor': 'center',
                        'text-allow-overlap': false
                    },
                    paint: {
                        'text-color': '#FF00FF',
                        'text-halo-color': '#000',
                        'text-halo-width': 2
                    }
                });
                
                console.log('[SPLITS] ARTCC layers added successfully');
            } catch (err) {
                console.error('[SPLITS] Failed to add ARTCC layers:', err);
            }
        }
        
        // Add High sectors (hidden by default)
        if (this.geoJsonCache.high && !this.map.getSource('high-source')) {
            console.log('[SPLITS] Adding High sector layer with', this.geoJsonCache.high.features?.length, 'features');
            
            try {
                this.map.addSource('high-source', {
                    type: 'geojson',
                    data: this.geoJsonCache.high
                });
                
                // Create label source with centroids
                const highLabelFeatures = this.createLabelFeatures(this.geoJsonCache.high);
                this.map.addSource('high-labels-source', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: highLabelFeatures }
                });
                
                this.map.addLayer({
                    id: 'high-fill',
                    type: 'fill',
                    source: 'high-source',
                    paint: {
                        'fill-color': '#FF6347',
                        'fill-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'high-lines',
                    type: 'line',
                    source: 'high-source',
                    paint: {
                        'line-color': '#FF6347',
                        'line-width': 1,
                        'line-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'high-labels',
                    type: 'symbol',
                    source: 'high-labels-source',
                    layout: {
                        'text-field': ['get', 'label'],
                        'text-size': 10,
                        'text-font': ['Noto Sans Regular'],
                        'text-anchor': 'center',
                        'text-allow-overlap': false,
                        'visibility': 'none'
                    },
                    paint: {
                        'text-color': '#FF6347',
                        'text-halo-color': '#000',
                        'text-halo-width': 1.5
                    }
                });
                
                console.log('[SPLITS] High sector layers added successfully');
            } catch (err) {
                console.error('[SPLITS] Failed to add High sector layers:', err);
            }
        }
        
        // Add Low sectors (hidden by default)
        if (this.geoJsonCache.low && !this.map.getSource('low-source')) {
            console.log('[SPLITS] Adding Low sector layer with', this.geoJsonCache.low.features?.length, 'features');
            
            try {
                this.map.addSource('low-source', {
                    type: 'geojson',
                    data: this.geoJsonCache.low
                });
                
                // Create label source with centroids
                const lowLabelFeatures = this.createLabelFeatures(this.geoJsonCache.low);
                this.map.addSource('low-labels-source', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: lowLabelFeatures }
                });
                
                this.map.addLayer({
                    id: 'low-fill',
                    type: 'fill',
                    source: 'low-source',
                    paint: {
                        'fill-color': '#228B22',
                        'fill-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'low-lines',
                    type: 'line',
                    source: 'low-source',
                    paint: {
                        'line-color': '#228B22',
                        'line-width': 1,
                        'line-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'low-labels',
                    type: 'symbol',
                    source: 'low-labels-source',
                    layout: {
                        'text-field': ['get', 'label'],
                        'text-size': 10,
                        'text-font': ['Noto Sans Regular'],
                        'text-anchor': 'center',
                        'text-allow-overlap': false,
                        'visibility': 'none'
                    },
                    paint: {
                        'text-color': '#228B22',
                        'text-halo-color': '#000',
                        'text-halo-width': 1.5
                    }
                });
                
                console.log('[SPLITS] Low sector layers added successfully');
            } catch (err) {
                console.error('[SPLITS] Failed to add Low sector layers:', err);
            }
        }
        
        // Add Super High sectors (hidden by default)
        if (this.geoJsonCache.superhigh && !this.map.getSource('superhigh-source')) {
            console.log('[SPLITS] Adding Super High sector layer with', this.geoJsonCache.superhigh.features?.length, 'features');
            
            try {
                this.map.addSource('superhigh-source', {
                    type: 'geojson',
                    data: this.geoJsonCache.superhigh
                });
                
                // Create label source with centroids
                const superhighLabelFeatures = this.createLabelFeatures(this.geoJsonCache.superhigh);
                this.map.addSource('superhigh-labels-source', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: superhighLabelFeatures }
                });
                
                this.map.addLayer({
                    id: 'superhigh-fill',
                    type: 'fill',
                    source: 'superhigh-source',
                    paint: {
                        'fill-color': '#9932CC',
                        'fill-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'superhigh-lines',
                    type: 'line',
                    source: 'superhigh-source',
                    paint: {
                        'line-color': '#9932CC',
                        'line-width': 1,
                        'line-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'superhigh-labels',
                    type: 'symbol',
                    source: 'superhigh-labels-source',
                    layout: {
                        'text-field': ['get', 'label'],
                        'text-size': 10,
                        'text-font': ['Noto Sans Regular'],
                        'text-anchor': 'center',
                        'text-allow-overlap': false,
                        'visibility': 'none'
                    },
                    paint: {
                        'text-color': '#9932CC',
                        'text-halo-color': '#000',
                        'text-halo-width': 1.5
                    }
                });
                
                console.log('[SPLITS] Super High sector layers added successfully');
            } catch (err) {
                console.error('[SPLITS] Failed to add Super High sector layers:', err);
            }
        }
        
        // Add TRACON boundaries (hidden by default)
        if (this.geoJsonCache.tracon && !this.map.getSource('tracon-source')) {
            console.log('[SPLITS] Adding TRACON layer with', this.geoJsonCache.tracon.features?.length, 'features');
            
            try {
                this.map.addSource('tracon-source', {
                    type: 'geojson',
                    data: this.geoJsonCache.tracon
                });
                
                // Create label source with centroids
                const traconLabelFeatures = this.createLabelFeatures(this.geoJsonCache.tracon, 'id');
                this.map.addSource('tracon-labels-source', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: traconLabelFeatures }
                });
                
                this.map.addLayer({
                    id: 'tracon-fill',
                    type: 'fill',
                    source: 'tracon-source',
                    paint: {
                        'fill-color': '#4682B4',
                        'fill-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'tracon-lines',
                    type: 'line',
                    source: 'tracon-source',
                    paint: {
                        'line-color': '#4682B4',
                        'line-width': 1.5,
                        'line-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'tracon-labels',
                    type: 'symbol',
                    source: 'tracon-labels-source',
                    layout: {
                        'text-field': ['get', 'label'],
                        'text-size': 11,
                        'text-font': ['Noto Sans Regular'],
                        'text-anchor': 'center',
                        'text-allow-overlap': false,
                        'visibility': 'none'
                    },
                    paint: {
                        'text-color': '#4682B4',
                        'text-halo-color': '#000',
                        'text-halo-width': 1.5
                    }
                });
                
                console.log('[SPLITS] TRACON layers added successfully');
            } catch (err) {
                console.error('[SPLITS] Failed to add TRACON layers:', err);
            }
        }
        
        // Add Areas layer (hidden by default) - built from database areas
        if (!this.map.getSource('areas-source')) {
            try {
                this.map.addSource('areas-source', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: [] }
                });
                
                this.map.addSource('areas-labels-source', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: [] }
                });
                
                this.map.addLayer({
                    id: 'areas-fill',
                    type: 'fill',
                    source: 'areas-source',
                    paint: {
                        'fill-color': ['get', 'color'],
                        'fill-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'areas-lines',
                    type: 'line',
                    source: 'areas-source',
                    paint: {
                        'line-color': ['get', 'color'],
                        'line-width': 2,
                        'line-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                }, 'sectors-fill');
                
                this.map.addLayer({
                    id: 'areas-labels',
                    type: 'symbol',
                    source: 'areas-labels-source',
                    layout: {
                        'text-field': ['get', 'label'],
                        'text-size': 12,
                        'text-font': ['Noto Sans Bold'],
                        'text-anchor': 'center',
                        'text-allow-overlap': true,
                        'visibility': 'none'
                    },
                    paint: {
                        'text-color': '#fff',
                        'text-halo-color': ['get', 'color'],
                        'text-halo-width': 2
                    }
                });
                
                console.log('[SPLITS] Areas layers added successfully');
                
                // Load areas data into layer
                this.updateAreasLayer();
            } catch (err) {
                console.error('[SPLITS] Failed to add Areas layers:', err);
            }
        }
        
        // Add Presets layer (hidden by default) - built from saved presets
        if (!this.map.getSource('presets-source')) {
            try {
                this.map.addSource('presets-source', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: [] }
                });
                
                this.map.addSource('presets-labels-source', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: [] }
                });
                
                // Add presets layers (matching superhigh defaults)
                this.map.addLayer({
                    id: 'presets-fill',
                    type: 'fill',
                    source: 'presets-source',
                    paint: {
                        'fill-color': ['get', 'color'],
                        'fill-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                });
                
                this.map.addLayer({
                    id: 'presets-lines',
                    type: 'line',
                    source: 'presets-source',
                    paint: {
                        'line-color': ['get', 'color'],
                        'line-width': 1,
                        'line-opacity': 0.5
                    },
                    layout: { visibility: 'none' }
                });
                
                this.map.addLayer({
                    id: 'presets-labels',
                    type: 'symbol',
                    source: 'presets-labels-source',
                    layout: {
                        'text-field': ['get', 'label'],
                        'text-size': 11,
                        'text-font': ['Noto Sans Bold'],
                        'text-anchor': 'center',
                        'text-allow-overlap': true,
                        'visibility': 'none'
                    },
                    paint: {
                        'text-color': '#fff',
                        'text-halo-color': ['get', 'color'],
                        'text-halo-width': 2
                    }
                });
                
                console.log('[SPLITS] Presets layers added successfully');
            } catch (err) {
                console.error('[SPLITS] Failed to add Presets layers:', err);
            }
        }
        
        console.log('[SPLITS] All base layers added successfully');
    },
    
    updateAreasLayer() {
        if (!this.map || !this.map.getSource('areas-source')) return;
        if (!this.areas || this.areas.length === 0) return;
        
        const features = [];
        const labelFeatures = [];
        const defaultColors = ['#e63946', '#f4a261', '#2a9d8f', '#e9c46a', '#264653', '#a855f7', '#06b6d4'];
        
        // Only show visible areas
        const visibleAreas = this.areas.filter(a => this.visibleAreas && this.visibleAreas.has(a.id));
        
        visibleAreas.forEach((area, index) => {
            const sectors = area.sectors || [];
            // Find the original index for consistent default coloring
            const originalIndex = this.areas.findIndex(a => a.id === area.id);
            // Use stored color or default
            const color = this.areaColors[area.id] || defaultColors[originalIndex % defaultColors.length];
            
            // Build polygon from sector geometries
            sectors.forEach(sectorId => {
                const sector = this.findSectorGeometry(sectorId);
                if (sector) {
                    features.push({
                        type: 'Feature',
                        properties: {
                            area_id: area.id,
                            area_name: area.area_name,
                            artcc: area.artcc,
                            color: color
                        },
                        geometry: sector.geometry
                    });
                }
            });
            
            // Create label at area centroid
            const areaCentroids = sectors.map(s => this.findSectorGeometry(s)?.centroid).filter(c => c);
            if (areaCentroids.length > 0) {
                const avgLng = areaCentroids.reduce((sum, c) => sum + c[0], 0) / areaCentroids.length;
                const avgLat = areaCentroids.reduce((sum, c) => sum + c[1], 0) / areaCentroids.length;
                
                labelFeatures.push({
                    type: 'Feature',
                    properties: {
                        label: `${area.artcc} ${area.area_name}`,
                        color: color
                    },
                    geometry: { type: 'Point', coordinates: [avgLng, avgLat] }
                });
            }
        });
        
        this.map.getSource('areas-source').setData({ type: 'FeatureCollection', features });
        this.map.getSource('areas-labels-source').setData({ type: 'FeatureCollection', features: labelFeatures });
        
        // Auto-enable areas layer visibility when areas are selected
        if (visibleAreas.length > 0 && !this.layerVisibility.areas) {
            this.layerVisibility.areas = true;
            this.toggleLayerVisibility('areas', true);
            // Update checkbox in layer controls
            const checkbox = document.getElementById('layer-areas');
            if (checkbox) checkbox.checked = true;
        }
        
        console.log(`[SPLITS] Updated areas layer with ${features.length} sector features from ${visibleAreas.length} visible areas`);
    },
    
    updatePresetsLayer() {
        if (!this.map || !this.map.getSource('presets-source')) {
            console.warn('[SPLITS] updatePresetsLayer: map or source not ready');
            return;
        }
        if (!this.presets || this.presets.length === 0) {
            console.warn('[SPLITS] updatePresetsLayer: no presets loaded');
            return;
        }
        
        const features = [];
        const labelFeatures = [];
        
        // Only show visible presets
        const visiblePresets = this.presets.filter(p => this.visiblePresets && this.visiblePresets.has(p.id));
        
        console.log(`[SPLITS] updatePresetsLayer: ${visiblePresets.length} visible presets out of ${this.presets.length} total`);
        
        visiblePresets.forEach(preset => {
            const positions = preset.positions || [];
            console.log(`[SPLITS] Preset "${preset.preset_name}" has ${positions.length} positions`);
            
            positions.forEach(pos => {
                const color = pos.color || '#4dabf7';
                const sectors = pos.sectors || [];
                console.log(`[SPLITS] Position "${pos.position_name}" has ${sectors.length} sectors:`, sectors);
                
                // Build polygon from sector geometries
                sectors.forEach(sectorId => {
                    const sector = this.findSectorGeometry(sectorId);
                    if (sector) {
                        features.push({
                            type: 'Feature',
                            properties: {
                                preset_id: preset.id,
                                preset_name: preset.preset_name,
                                position_name: pos.position_name,
                                artcc: preset.artcc,
                                color: color
                            },
                            geometry: sector.geometry
                        });
                    } else {
                        console.warn(`[SPLITS] Could not find geometry for sector: ${sectorId}`);
                    }
                });
                
                // Create label at position centroid
                const posCentroids = sectors.map(s => this.findSectorGeometry(s)?.centroid).filter(c => c);
                if (posCentroids.length > 0) {
                    const avgLng = posCentroids.reduce((sum, c) => sum + c[0], 0) / posCentroids.length;
                    const avgLat = posCentroids.reduce((sum, c) => sum + c[1], 0) / posCentroids.length;
                    
                    labelFeatures.push({
                        type: 'Feature',
                        properties: {
                            label: pos.position_name,
                            color: color
                        },
                        geometry: { type: 'Point', coordinates: [avgLng, avgLat] }
                    });
                }
            });
        });
        
        console.log(`[SPLITS] Setting presets-source with ${features.length} features`);
        this.map.getSource('presets-source').setData({ type: 'FeatureCollection', features });
        this.map.getSource('presets-labels-source').setData({ type: 'FeatureCollection', features: labelFeatures });
        
        // Auto-enable presets layer visibility when presets are selected
        if (visiblePresets.length > 0 && !this.layerVisibility.presets) {
            console.log('[SPLITS] Auto-enabling presets layer visibility');
            this.layerVisibility.presets = true;
            this.toggleLayerVisibility('presets', true);
            // Update checkbox in layer controls
            const checkbox = document.getElementById('layer-presets');
            if (checkbox) checkbox.checked = true;
        }
        
        console.log(`[SPLITS] Updated presets layer with ${features.length} sector features from ${visiblePresets.length} visible presets`);
    },
    
    renderPresetsLayerList() {
        const container = document.getElementById('presets-toggle-list');
        if (!container) return;
        
        if (!this.presets || this.presets.length === 0) {
            container.innerHTML = '<div class="text-muted text-center py-2" style="font-size: 11px;">No presets saved.</div>';
            return;
        }
        
        // Initialize visiblePresets if needed
        if (!this.visiblePresets) {
            this.visiblePresets = new Set();
        }
        
        // Cache for fully loaded preset data
        if (!this.presetDataCache) {
            this.presetDataCache = {};
        }
        
        // Group by ARTCC
        const grouped = {};
        this.presets.forEach(preset => {
            if (!grouped[preset.artcc]) grouped[preset.artcc] = [];
            grouped[preset.artcc].push(preset);
        });
        
        // Color palette for presets
        const defaultColors = ['#f59e0b', '#8b5cf6', '#06b6d4', '#10b981', '#ef4444', '#ec4899', '#6366f1'];
        
        let html = '';
        Object.keys(grouped).sort().forEach(artcc => {
            html += `<div class="area-toggle-group" data-artcc="${artcc}">
                <div class="area-toggle-header">
                    <span>${artcc}</span>
                    <div class="artcc-toggle-btns">
                        <button class="btn btn-xs btn-link preset-artcc-show-all-btn" data-artcc="${artcc}" title="Show all ${artcc}">All</button>
                        <button class="btn btn-xs btn-link preset-artcc-hide-all-btn" data-artcc="${artcc}" title="Hide all ${artcc}">None</button>
                    </div>
                </div>`;
            
            grouped[artcc].forEach((preset, i) => {
                const isVisible = this.visiblePresets.has(preset.id);
                const posCount = preset.positions?.length || preset.position_count || 0;
                const color = defaultColors[i % defaultColors.length];
                
                html += `
                    <div class="area-toggle-item" data-preset-id="${preset.id}">
                        <input type="checkbox" class="preset-toggle-checkbox" ${isVisible ? 'checked' : ''}>
                        <span class="preset-color-dot" style="background: ${color};"></span>
                        <span class="area-toggle-name" title="${preset.preset_name}">${preset.preset_name}</span>
                        <span class="area-toggle-count">${posCount}</span>
                    </div>`;
            });
            
            html += '</div>';
        });
        
        container.innerHTML = html;
        
        // Bind toggle events
        container.querySelectorAll('.area-toggle-item').forEach(item => {
            const checkbox = item.querySelector('.preset-toggle-checkbox');
            const presetId = parseInt(item.dataset.presetId);
            
            checkbox.addEventListener('change', async () => {
                if (checkbox.checked) {
                    this.visiblePresets.add(presetId);
                    // Fetch full preset data if not cached
                    await this.ensurePresetDataLoaded(presetId);
                } else {
                    this.visiblePresets.delete(presetId);
                }
                this.updatePresetsLayer();
            });
            
            // Click anywhere on item toggles checkbox
            item.addEventListener('click', (e) => {
                if (e.target !== checkbox) {
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event('change'));
                }
            });
        });
        
        // Bind per-ARTCC All/None buttons
        container.querySelectorAll('.preset-artcc-show-all-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const artcc = btn.dataset.artcc;
                this.showPresetsForArtcc(artcc);
            });
        });
        
        container.querySelectorAll('.preset-artcc-hide-all-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const artcc = btn.dataset.artcc;
                this.hidePresetsForArtcc(artcc);
            });
        });
    },
    
    async ensurePresetDataLoaded(presetId) {
        // Check if this preset already has positions loaded
        const preset = this.presets.find(p => p.id === presetId);
        if (preset && preset.positions && preset.positions.length > 0 && preset.positions[0].sectors) {
            console.log(`[SPLITS] Preset ${presetId} already has full data`);
            return;
        }
        
        // Check cache
        if (this.presetDataCache[presetId]) {
            console.log(`[SPLITS] Using cached data for preset ${presetId}`);
            const idx = this.presets.findIndex(p => p.id === presetId);
            if (idx >= 0) {
                this.presets[idx] = this.presetDataCache[presetId];
            }
            return;
        }
        
        // Fetch full preset data
        try {
            console.log(`[SPLITS] Fetching full data for preset ${presetId}`);
            const response = await fetch(`api/splits/presets.php?id=${presetId}`);
            if (response.ok) {
                const data = await response.json();
                if (data.preset) {
                    this.presetDataCache[presetId] = data.preset;
                    const idx = this.presets.findIndex(p => p.id === presetId);
                    if (idx >= 0) {
                        this.presets[idx] = data.preset;
                    }
                    console.log(`[SPLITS] Loaded full data for preset ${presetId}:`, data.preset.positions?.length, 'positions');
                }
            }
        } catch (err) {
            console.error(`[SPLITS] Failed to load preset ${presetId}:`, err);
        }
    },
    
    async showPresetsForArtcc(artcc) {
        const artccPresets = this.presets.filter(p => p.artcc === artcc);
        // Load full data for all presets in this ARTCC
        await Promise.all(artccPresets.map(p => this.ensurePresetDataLoaded(p.id)));
        artccPresets.forEach(p => this.visiblePresets.add(p.id));
        this.renderPresetsLayerList();
        this.updatePresetsLayer();
    },
    
    hidePresetsForArtcc(artcc) {
        this.presets.filter(p => p.artcc === artcc).forEach(p => this.visiblePresets.delete(p.id));
        this.renderPresetsLayerList();
        this.updatePresetsLayer();
    },
    
    async showAllPresets() {
        if (!this.visiblePresets) this.visiblePresets = new Set();
        // Load full data for all presets
        await Promise.all(this.presets.map(p => this.ensurePresetDataLoaded(p.id)));
        this.presets.forEach(p => this.visiblePresets.add(p.id));
        this.renderPresetsLayerList();
        this.updatePresetsLayer();
    },
    
    hideAllPresets() {
        if (!this.visiblePresets) this.visiblePresets = new Set();
        this.visiblePresets.clear();
        this.renderPresetsLayerList();
        this.updatePresetsLayer();
    },
    
    createLabelFeatures(geojson, labelProp = 'label') {
        const features = [];
        if (!geojson?.features) return features;
        
        geojson.features.forEach(f => {
            const label = f.properties?.[labelProp] || f.properties?.name || f.properties?.id || f.properties?.ID;
            if (!label) return;
            
            const centroid = this.calculateCentroid(f.geometry?.coordinates);
            if (!centroid) return;
            
            features.push({
                type: 'Feature',
                properties: { label },
                geometry: { type: 'Point', coordinates: centroid }
            });
        });
        
        return features;
    },
    
    toggleLayer(layerName) {
        this.layerVisibility[layerName] = !this.layerVisibility[layerName];
        const visibility = this.layerVisibility[layerName] ? 'visible' : 'none';
        
        const layerGroups = {
            artcc: ['artcc-fill', 'artcc-lines', 'artcc-labels'],
            high: ['high-fill', 'high-lines', 'high-labels'],
            low: ['low-fill', 'low-lines', 'low-labels'],
            superhigh: ['superhigh-fill', 'superhigh-lines', 'superhigh-labels'],
            tracon: ['tracon-fill', 'tracon-lines', 'tracon-labels'],
            areas: ['areas-fill', 'areas-lines', 'areas-labels'],
            presets: ['presets-fill', 'presets-lines', 'presets-labels'],
            activeConfigs: ['sectors-fill', 'sectors-lines', 'sectors-labels']
        };
        
        const layers = layerGroups[layerName] || [];
        layers.forEach(layerId => {
            if (this.map.getLayer(layerId)) {
                this.map.setLayoutProperty(layerId, 'visibility', visibility);
            }
        });
        
        // Update checkbox state
        const checkbox = document.querySelector(`.layer-toggle[data-layer="${layerName}"]`);
        if (checkbox) checkbox.checked = this.layerVisibility[layerName];
        
        console.log(`[SPLITS] Layer ${layerName} visibility: ${visibility}`);
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // EVENT BINDING
    // ═══════════════════════════════════════════════════════════════════
    
    bindEvents() {
        // Mode tabs
        document.querySelectorAll('.mode-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.mode-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.mode-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById(`mode-${tab.dataset.mode}`).classList.add('active');
            });
        });
        
        // New config button
        document.getElementById('new-config-btn')?.addEventListener('click', () => this.openConfigWizard());
        
        // Help toggle
        document.getElementById('splits-help-toggle')?.addEventListener('click', function() {
            const panel = document.getElementById('splits-help-panel');
            const btn = document.getElementById('splits-help-toggle');
            if (panel && btn) {
                const isHidden = panel.style.display === 'none' || panel.style.display === '';
                panel.style.display = isHidden ? 'block' : 'none';
                btn.textContent = isHidden ? 'Hide Help' : 'Show Help';
            }
        });
        
        // Config wizard navigation
        document.getElementById('config-next-btn')?.addEventListener('click', () => this.nextStep());
        document.getElementById('config-prev-btn')?.addEventListener('click', () => this.prevStep());
        document.getElementById('config-save-btn')?.addEventListener('click', () => this.saveConfig());
        
        // Config wizard - ARTCC change
        document.getElementById('config-artcc')?.addEventListener('change', (e) => {
            this.currentConfig.artcc = e.target.value;
            // Show sectors on map
            if (e.target.value) {
                this.loadArtccOnMap(e.target.value);
            }
        });
        
        // Add split button
        document.getElementById('add-split-btn')?.addEventListener('click', () => this.openSplitModal());
        document.getElementById('save-split-btn')?.addEventListener('click', () => this.saveSplit());
        
        // Sector selection
        document.getElementById('select-all-sectors')?.addEventListener('click', () => this.selectAllSectors());
        document.getElementById('clear-all-sectors')?.addEventListener('click', () => this.clearAllSectors());
        document.getElementById('done-selecting-btn')?.addEventListener('click', () => this.doneSelectingSectors());
        document.getElementById('select-on-map-btn')?.addEventListener('click', () => this.enableSplitMapSelection());
        
        // Sector input field (main config wizard)
        document.getElementById('sector-input-apply-btn')?.addEventListener('click', () => this.applySectorInput());
        document.getElementById('sector-input')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.applySectorInput();
            }
        });
        
        // Areas management
        document.getElementById('manage-areas-btn')?.addEventListener('click', () => this.openAreasModal());
        document.getElementById('new-area-btn')?.addEventListener('click', () => this.newArea());
        document.getElementById('save-area-btn')?.addEventListener('click', () => this.saveArea());
        document.getElementById('delete-area-btn')?.addEventListener('click', () => this.deleteArea());
        document.getElementById('cancel-area-btn')?.addEventListener('click', () => this.cancelAreaEdit());
        document.getElementById('area-artcc')?.addEventListener('change', (e) => this.loadAreaSectors(e.target.value));
        document.getElementById('area-load-sectors-btn')?.addEventListener('click', () => {
            const artcc = document.getElementById('area-artcc').value;
            if (artcc) this.loadAreaSectors(artcc);
        });
        document.getElementById('area-select-all-btn')?.addEventListener('click', () => this.areaSelectAllSectors());
        document.getElementById('area-clear-all-btn')?.addEventListener('click', () => this.areaClearAllSectors());
        document.getElementById('areas-artcc-filter')?.addEventListener('change', () => this.renderAreasList());
        document.getElementById('area-select-on-map-btn')?.addEventListener('click', () => this.enableAreaMapSelection());
        
        // Scheduled configs refresh button
        document.getElementById('refresh-scheduled-btn')?.addEventListener('click', () => this.loadScheduledConfigs());
        
        // Area sector input field
        document.getElementById('area-sector-input-apply-btn')?.addEventListener('click', () => this.applyAreaSectorInput());
        document.getElementById('area-sector-input')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.applyAreaSectorInput();
            }
        });
        
        // Close areas modal: disable map selection (unless we're entering map selection mode)
        $('#areas-modal').on('hidden.bs.modal', () => {
            if (this._mapSelectionSourceModal === 'area') {
                // We're intentionally hiding to enable map selection - don't disable
                return;
            }
            this.disableAreaMapSelection();
        });
        
        // Close config modal: restore map (unless we're entering map selection mode)
        $('#config-modal').on('hidden.bs.modal', () => {
            if (this._mapSelectionSourceModal === 'config') {
                // We're intentionally hiding to enable map selection - don't disable
                return;
            }
            this.isEditingConfig = false;
            this.disableMapSectorSelection();
            this.disableSplitMapSelection();
            this.updateMapWithActiveConfigs();
        });
        
        // Config modal shown: enable editing mode
        $('#config-modal').on('shown.bs.modal', () => {
            this.isEditingConfig = true;
        });
        
        // ═══════════════════════════════════════════════════════════════════
        // PRESET EVENT BINDINGS
        // ═══════════════════════════════════════════════════════════════════
        
        // Presets ARTCC filter
        document.getElementById('presets-artcc-filter')?.addEventListener('change', () => this.renderPresetsList());
        
        // New preset button
        document.getElementById('new-preset-btn')?.addEventListener('click', () => this.openPresetModal());
        
        // Load preset dropdown in config wizard
        document.getElementById('load-preset-dropdown')?.addEventListener('change', (e) => {
            const loadBtn = document.getElementById('load-preset-btn');
            if (loadBtn) loadBtn.disabled = !e.target.value;
        });
        
        // Load preset button
        document.getElementById('load-preset-btn')?.addEventListener('click', () => this.loadPresetIntoConfig());
        
        // Save as preset button (in review step)
        document.getElementById('save-as-preset-btn')?.addEventListener('click', () => this.saveCurrentConfigAsPreset());
        
        // Preset wizard navigation
        document.getElementById('preset-next-btn')?.addEventListener('click', () => this.presetNextStep());
        document.getElementById('preset-prev-btn')?.addEventListener('click', () => this.presetPrevStep());
        
        // Preset modal save button
        document.getElementById('save-preset-modal-btn')?.addEventListener('click', () => this.savePresetFromModal());
        
        // Preset modal delete button
        document.getElementById('delete-preset-btn')?.addEventListener('click', () => this.deletePreset());
        
        // Preset ARTCC change - load sectors
        document.getElementById('preset-artcc')?.addEventListener('change', (e) => {
            this._presetArtcc = e.target.value;
            if (e.target.value) {
                this.loadPresetArtccOnMap(e.target.value);
            }
        });
        
        // Preset position management
        document.getElementById('preset-add-position-btn')?.addEventListener('click', () => this.openPresetPositionModal());
        document.getElementById('save-preset-position-btn')?.addEventListener('click', () => this.savePresetPosition());
        
        // Preset sector selection
        document.getElementById('preset-select-all-sectors')?.addEventListener('click', () => this.presetSelectAllSectors());
        document.getElementById('preset-clear-all-sectors')?.addEventListener('click', () => this.presetClearAllSectors());
        document.getElementById('preset-done-selecting-btn')?.addEventListener('click', () => this.presetDoneSelectingSectors());
        document.getElementById('preset-select-on-map-btn')?.addEventListener('click', () => this.enablePresetMapSelection());

        // Preset strata filter checkboxes
        ['preset-strata-low', 'preset-strata-high', 'preset-strata-superhigh'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', () => this.loadPresetSectorGrid());
        });
        
        // Preset sector input
        document.getElementById('preset-sector-input-apply-btn')?.addEventListener('click', () => this.applyPresetSectorInput());
        document.getElementById('preset-sector-input')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.applyPresetSectorInput();
            }
        });
        
        // Update preset dropdown when ARTCC changes in config wizard
        const configArtccSelect = document.getElementById('config-artcc');
        if (configArtccSelect) {
            const originalHandler = configArtccSelect.onchange;
            configArtccSelect.addEventListener('change', (e) => {
                this.populatePresetDropdown(e.target.value);
            });
        }
        
        // Close preset modal - reset state (unless we're entering map selection mode)
        $('#preset-modal').on('hidden.bs.modal', () => {
            if (this._mapSelectionSourceModal === 'preset') {
                // We're intentionally hiding to enable map selection - don't disable
                return;
            }
            this._presetStep = 1;
            this._presetArtcc = null;
            this._editingPresetId = null;
            this._presetPositions = [];
            this._editingPresetPositionIndex = -1;
            this.disablePresetMapSelection();
            this.updateMapWithActiveConfigs();
        });
        
        // ═══════════════════════════════════════════════════════════════════
        
        // Sidebar areas toggle controls
        document.getElementById('areas-artcc-select')?.addEventListener('change', () => this.renderAreasToggleList());
        document.getElementById('areas-show-all-btn')?.addEventListener('click', () => this.showAllAreas());
        document.getElementById('areas-hide-all-btn')?.addEventListener('click', () => this.hideAllAreas());
        
        // Layer toggle controls
        document.querySelectorAll('.layer-toggle').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const layerName = e.target.dataset.layer;
                this.layerVisibility[layerName] = e.target.checked;
                this.toggleLayerVisibility(layerName, e.target.checked);
            });
        });
        
        // Layer opacity controls
        document.querySelectorAll('.layer-opacity').forEach(slider => {
            slider.addEventListener('input', (e) => {
                const layerName = e.target.dataset.layer;
                const opacity = parseInt(e.target.value) / 100;
                this.setLayerOpacity(layerName, opacity);
            });
        });
        
        // Layer fill/line toggle buttons
        document.querySelectorAll('.layer-fill-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const layerName = btn.dataset.layer;
                btn.classList.toggle('active');
                this.toggleLayerFill(layerName, btn.classList.contains('active'));
            });
        });
        
        document.querySelectorAll('.layer-line-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const layerName = btn.dataset.layer;
                btn.classList.toggle('active');
                this.toggleLayerLine(layerName, btn.classList.contains('active'));
            });
        });
        
        // Note: We removed the document click handler for layer-select-popup here
        // because the map click event bubbles up and would immediately hide any popup we just created
        // The popup is closed by: 1) clicking elsewhere on map, 2) close button, 3) selecting an item
        
        // Presets layer show/hide all buttons
        document.getElementById('presets-show-all-btn')?.addEventListener('click', () => this.showAllPresets());
        document.getElementById('presets-hide-all-btn')?.addEventListener('click', () => this.hideAllPresets());
        
        // Populate split color picker
        this.populateSplitColorPicker();
        
        // Make active configs panel draggable
        this.initDraggablePanel();
    },
    
    toggleLayerFill(layerName, visible) {
        const layerMap = {
            artcc: { layer: 'artcc-fill', baseOpacity: 0.1 },
            high: { layer: 'high-fill', baseOpacity: 0.25 },
            low: { layer: 'low-fill', baseOpacity: 0.25 },
            superhigh: { layer: 'superhigh-fill', baseOpacity: 0.25 },
            tracon: { layer: 'tracon-fill', baseOpacity: 0.2 },
            areas: { layer: 'areas-fill', baseOpacity: 0.3 },
            presets: { layer: 'presets-fill', baseOpacity: 0.25 },
            activeConfigs: { layer: 'sectors-fill', baseOpacity: 0.6 }
        };
        const config = layerMap[layerName];
        if (config && this.map && this.map.getLayer(config.layer)) {
            this.map.setLayoutProperty(config.layer, 'visibility', visible ? 'visible' : 'none');
            
            // Also set opacity based on slider when turning on
            if (visible) {
                const slider = document.querySelector(`.layer-opacity[data-layer="${layerName}"]`);
                const opacity = slider ? parseInt(slider.value) / 100 : 0.5;
                this.map.setPaintProperty(config.layer, 'fill-opacity', config.baseOpacity * opacity);
            }
        }
    },
    
    toggleLayerLine(layerName, visible) {
        const layerMap = {
            artcc: { layer: 'artcc-lines', baseOpacity: 1 },
            high: { layer: 'high-lines', baseOpacity: 1 },
            low: { layer: 'low-lines', baseOpacity: 1 },
            superhigh: { layer: 'superhigh-lines', baseOpacity: 1 },
            tracon: { layer: 'tracon-lines', baseOpacity: 1 },
            areas: { layer: 'areas-lines', baseOpacity: 1 },
            presets: { layer: 'presets-lines', baseOpacity: 1 },
            activeConfigs: { layer: 'sectors-lines', baseOpacity: 1 }
        };
        const config = layerMap[layerName];
        if (config && this.map && this.map.getLayer(config.layer)) {
            this.map.setLayoutProperty(config.layer, 'visibility', visible ? 'visible' : 'none');
            
            // Also set opacity based on slider when turning on
            if (visible) {
                const slider = document.querySelector(`.layer-opacity[data-layer="${layerName}"]`);
                const opacity = slider ? parseInt(slider.value) / 100 : 0.5;
                this.map.setPaintProperty(config.layer, 'line-opacity', config.baseOpacity * opacity);
            }
        }
    },
    
    initDraggablePanel() {
        const panel = document.getElementById('active-configs-panel');
        const header = document.getElementById('active-panel-header');
        if (!panel || !header) return;
        
        let isDragging = false;
        let startX, startY, startLeft, startBottom;
        
        header.addEventListener('mousedown', (e) => {
            if (e.target.tagName === 'BUTTON') return;
            isDragging = true;
            panel.classList.add('dragging');
            
            const rect = panel.getBoundingClientRect();
            const parentRect = panel.parentElement.getBoundingClientRect();
            
            startX = e.clientX;
            startY = e.clientY;
            startLeft = rect.left - parentRect.left;
            startBottom = parentRect.bottom - rect.bottom;
            
            e.preventDefault();
        });
        
        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            
            const parentRect = panel.parentElement.getBoundingClientRect();
            const deltaX = e.clientX - startX;
            const deltaY = e.clientY - startY;
            
            let newLeft = startLeft + deltaX;
            let newBottom = startBottom - deltaY;
            
            // Constrain to parent bounds
            const panelRect = panel.getBoundingClientRect();
            const maxLeft = parentRect.width - panelRect.width - 10;
            const maxBottom = parentRect.height - panelRect.height - 10;
            
            newLeft = Math.max(10, Math.min(newLeft, maxLeft));
            newBottom = Math.max(10, Math.min(newBottom, maxBottom));
            
            panel.style.left = newLeft + 'px';
            panel.style.bottom = newBottom + 'px';
            panel.style.right = 'auto';
            panel.style.top = 'auto';
        });
        
        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                panel.classList.remove('dragging');
            }
        });
    },
    
    initMapClickHandler() {
        if (!this.map) return;
        
        // Create popup
        this.sectorPopup = new maplibregl.Popup({
            closeButton: true,
            closeOnClick: false,  // We'll manage closing ourselves
            maxWidth: '320px'
        });
        
        // Clickable layers - include both fill and line layers for better hit detection
        const clickableFillLayers = ['sectors-fill', 'high-fill', 'low-fill', 'artcc-fill', 'tracon-fill', 'areas-fill'];
        const clickableLineLayers = ['sectors-lines', 'high-lines', 'low-lines', 'artcc-lines', 'tracon-lines', 'areas-lines'];
        const allClickableLayers = [...clickableFillLayers, ...clickableLineLayers];
        
        // Single unified click handler
        this.map.on('click', (e) => {
            // Close any existing popups
            this.sectorPopup.remove();
            const selectPopup = document.getElementById('layer-select-popup');
            if (selectPopup) selectPopup.remove();
            
            // Also close sector selection popup if clicking elsewhere on map
            const sectorSelectPopup = document.getElementById('sector-select-popup');
            if (sectorSelectPopup) sectorSelectPopup.remove();
            
            // Check if we're in selection mode
            if (this.mapSelectionMode) {
                this.handleSelectionModeClick(e);
                return;
            }
            
            // Normal mode - query all layers at click point
            const allFeatures = this.queryFeaturesAtPoint(e.point);
            
            if (allFeatures.length === 0) {
                return;
            }
            
            if (allFeatures.length === 1) {
                // Single feature - show popup directly
                this.showFeaturePopup(allFeatures[0], e.lngLat);
            } else {
                // Multiple features - show selection popup
                this.showLayerSelectPopup(allFeatures, e);
            }
        });
        
        // Close selection popup when clicking elsewhere on the page
        // Note: We don't need a document click handler here since:
        // 1. The map click handler already closes the popup at the start
        // 2. The popup's close button handles explicit closing
        // 3. A document handler would catch the map click event bubbling up and immediately close the popup
        
        // Change cursor on hover over any clickable layer
        allClickableLayers.forEach(layerId => {
            this.map.on('mouseenter', layerId, () => {
                if (this.map.getLayer(layerId)) {
                    this.map.getCanvas().style.cursor = this.mapSelectionMode ? 'crosshair' : 'pointer';
                }
            });
            this.map.on('mouseleave', layerId, () => {
                this.map.getCanvas().style.cursor = '';
            });
        });
        
        // Add click handler for split labels to toggle datablocks
        this.map.on('click', 'sectors-labels', (e) => {
            e.preventDefault();
            e.originalEvent.stopPropagation();
            
            if (e.features && e.features.length > 0) {
                const feature = e.features[0];
                const props = feature.properties;
                const coords = feature.geometry.coordinates;
                
                // Find the position data for this label using config_id for precise match
                const positionData = this.findPositionByLabel(props.label, props.color, props.config_id);
                if (positionData) {
                    this.toggleSplitDatablock(positionData, coords);
                }
            }
        });
        
        // Change cursor on hover over labels
        this.map.on('mouseenter', 'sectors-labels', () => {
            this.map.getCanvas().style.cursor = 'pointer';
        });
        this.map.on('mouseleave', 'sectors-labels', () => {
            this.map.getCanvas().style.cursor = '';
        });
        
        // Update datablock positions when map moves (so they stay fixed to map coordinates)
        this.map.on('move', () => {
            this.updateAllDatablockPositions();
        });
    },
    
    /**
     * Query all features at a point, handling visibility and deduplication
     */
    queryFeaturesAtPoint(point) {
        const layerConfigs = [
            { fill: 'sectors-fill', line: 'sectors-lines', type: 'active' },
            { fill: 'high-fill', line: 'high-lines', type: 'high' },
            { fill: 'low-fill', line: 'low-lines', type: 'low' },
            { fill: 'superhigh-fill', line: 'superhigh-lines', type: 'superhigh' },
            { fill: 'artcc-fill', line: 'artcc-lines', type: 'artcc' },
            { fill: 'tracon-fill', line: 'tracon-lines', type: 'tracon' },
            { fill: 'areas-fill', line: 'areas-lines', type: 'areas' },
            { fill: 'presets-fill', line: 'presets-lines', type: 'presets' }
        ];
        
        const allFeatures = [];
        const seenFeatures = new Set(); // For deduplication
        
        layerConfigs.forEach(config => {
            // Check if layer group is visible (check either fill or line)
            const fillVisible = this.isLayerVisible(config.fill);
            const lineVisible = this.isLayerVisible(config.line);
            
            if (!fillVisible && !lineVisible) {
                return;
            }
            
            // Query both fill and line layers
            const layersToQuery = [];
            if (fillVisible && this.map.getLayer(config.fill)) layersToQuery.push(config.fill);
            if (lineVisible && this.map.getLayer(config.line)) layersToQuery.push(config.line);
            
            if (layersToQuery.length === 0) return;
            
            const features = this.map.queryRenderedFeatures(point, { layers: layersToQuery });
            
            features.forEach(f => {
                // Create a unique key for deduplication
                const key = this.getFeatureKey(f, config.type);
                if (seenFeatures.has(key)) return;
                seenFeatures.add(key);
                
                allFeatures.push({
                    feature: f,
                    layerId: config.fill,
                    layerType: config.type
                });
            });
        });
        
        return allFeatures;
    },
    
    /**
     * Check if a layer is visible
     */
    isLayerVisible(layerId) {
        if (!this.map.getLayer(layerId)) return false;
        const visibility = this.map.getLayoutProperty(layerId, 'visibility');
        // Default is visible if not explicitly set to 'none'
        return visibility !== 'none';
    },
    
    /**
     * Generate a unique key for a feature to deduplicate
     */
    getFeatureKey(feature, layerType) {
        const props = feature.properties;
        switch (layerType) {
            case 'active':
                return `active-${props.sector_id || props.position}`;
            case 'high':
            case 'low':
            case 'superhigh':
                return `${layerType}-${props.label || props.name || props.id}`;
            case 'artcc':
                return `artcc-${props.id || props.name || props.ID}`;
            case 'tracon':
                return `tracon-${props.sector || props.id || props.name}`;
            case 'areas':
                return `areas-${props.area_id || props.area_name}`;
            case 'presets':
                return `presets-${props.preset_id}-${props.position_name}`;
            default:
                return `unknown-${JSON.stringify(props).substring(0, 50)}`;
        }
    },
    
    /**
     * Handle click in selection mode (for sector assignment)
     * Shows a popup if multiple overlapping sectors are found
     */
    handleSelectionModeClick(e) {
        const selectableLayers = ['high-fill', 'low-fill', 'superhigh-fill', 'high-lines', 'low-lines', 'superhigh-lines'];
        
        // Gather all features from all visible layers
        const allFeatures = [];
        const seenSectors = new Set();
        
        for (const layerId of selectableLayers) {
            if (!this.isLayerVisible(layerId)) continue;
            if (!this.map.getLayer(layerId)) continue;
            
            const features = this.map.queryRenderedFeatures(e.point, { layers: [layerId] });
            for (const f of features) {
                const props = f.properties;
                const sectorId = props.label || props.name || props.id;
                if (!sectorId || seenSectors.has(sectorId)) continue;
                
                seenSectors.add(sectorId);
                const layerType = layerId.includes('superhigh') ? 'superhigh' : (layerId.includes('high') ? 'high' : 'low');
                allFeatures.push({
                    sectorId: sectorId,
                    layerType: layerType,
                    properties: props
                });
            }
        }
        
        if (allFeatures.length === 0) {
            return;
        }
        
        if (allFeatures.length === 1) {
            // Single feature - toggle directly
            this.handleMapSectorClick(allFeatures[0].sectorId, allFeatures[0].layerType);
        } else {
            // Multiple features - show selection popup
            this.showSectorSelectionPopup(allFeatures, e);
        }
    },
    
    /**
     * Show popup for selecting from overlapping sectors in selection mode
     */
    showSectorSelectionPopup(features, e) {
        // Clean up any existing popup and its handlers
        const existingPopup = document.getElementById('sector-select-popup');
        if (existingPopup) existingPopup.remove();
        if (this._sectorSelectCloseHandler) {
            document.removeEventListener('click', this._sectorSelectCloseHandler);
            this._sectorSelectCloseHandler = null;
        }
        
        // Determine which sectors are currently selected based on mode
        const currentlySelected = this.getCurrentlySelectedSectors();
        
        // Create popup
        const popup = document.createElement('div');
        popup.id = 'sector-select-popup';
        popup.className = 'layer-select-popup sector-select-popup';
        
        const headerText = features.length > 1 ? `${features.length} Overlapping Sectors` : 'Select Sector';
        
        popup.innerHTML = `
            <div class="layer-select-header">
                <span>${headerText}</span>
                <button class="popup-close-btn" title="Close">&times;</button>
            </div>
            <div class="layer-select-list" id="sector-select-list">
                ${features.map((item, index) => {
                    const isSelected = currentlySelected.has(item.sectorId);
                    const typeName = { 'high': 'High', 'low': 'Low', 'superhigh': 'Super High' }[item.layerType] || item.layerType;
                    const typeColor = { 'high': '#FF6347', 'low': '#228B22', 'superhigh': '#9932CC' }[item.layerType] || '#888';
                    return `
                        <div class="layer-select-item sector-select-item ${isSelected ? 'is-selected' : ''}" 
                             data-index="${index}" data-sector="${item.sectorId}" data-type="${item.layerType}">
                            <input type="checkbox" class="sector-checkbox" ${isSelected ? 'checked' : ''}>
                            <span class="layer-type" style="background: ${typeColor}">${typeName}</span>
                            <span class="layer-name">${item.sectorId}</span>
                            <span class="selected-indicator">${isSelected ? '✓' : ''}</span>
                        </div>
                    `;
                }).join('')}
            </div>
            <div class="sector-select-actions">
                <button class="btn btn-xs btn-outline-secondary" id="sector-select-all-btn">Select All</button>
                <button class="btn btn-xs btn-outline-secondary" id="sector-deselect-all-btn">Deselect All</button>
                <button class="btn btn-xs btn-primary" id="sector-apply-btn">Apply</button>
            </div>
        `;
        
        // Add to map container
        this.map.getContainer().appendChild(popup);
        
        // Position popup near click
        const mapRect = this.map.getContainer().getBoundingClientRect();
        let left = e.point.x + 10;
        let top = e.point.y + 10;
        
        popup.style.display = 'block';
        popup.style.left = left + 'px';
        popup.style.top = top + 'px';
        
        // Adjust if popup goes off-screen
        const popupRect = popup.getBoundingClientRect();
        if (left + popupRect.width > mapRect.width - 10) {
            left = e.point.x - popupRect.width - 10;
        }
        if (top + popupRect.height > mapRect.height - 10) {
            top = e.point.y - popupRect.height - 10;
        }
        popup.style.left = Math.max(10, left) + 'px';
        popup.style.top = Math.max(10, top) + 'px';
        
        // Store features for later
        this._sectorSelectFeatures = features;
        
        // Bind events
        this.bindSectorSelectionPopupEvents(popup);
    },
    
    /**
     * Get the set of currently selected sector IDs based on current selection mode
     */
    getCurrentlySelectedSectors() {
        const selected = new Set();
        
        if (this.mapSelectionMode === 'split' && this.editingSplitIndex >= 0) {
            const split = this.currentConfig.splits[this.editingSplitIndex];
            (split.sectors || []).forEach(s => selected.add(s));
        } else if (this.mapSelectionMode === 'area') {
            (this._areaSelectedSectors || []).forEach(s => selected.add(s));
        } else if (this.mapSelectionMode === 'preset' && this._editingPresetPositionIndex >= 0) {
            const position = this._presetPositions[this._editingPresetPositionIndex];
            (position?.sectors || []).forEach(s => selected.add(s));
        }
        
        return selected;
    },
    
    /**
     * Bind events for the sector selection popup
     */
    bindSectorSelectionPopupEvents(popup) {
        // Stop propagation on all popup clicks to prevent map click handler from firing
        popup.addEventListener('click', (ev) => {
            ev.stopPropagation();
        });
        
        // Close button
        popup.querySelector('.popup-close-btn')?.addEventListener('click', () => {
            popup.remove();
        });
        
        // Click on item row (not checkbox) - toggle single sector immediately
        popup.querySelectorAll('.sector-select-item').forEach(item => {
            item.addEventListener('click', (ev) => {
                // If clicking directly on checkbox, let it handle itself
                if (ev.target.classList.contains('sector-checkbox')) {
                    return;
                }
                
                const sectorId = item.dataset.sector;
                const layerType = item.dataset.type;
                
                // Toggle this single sector
                this.handleMapSectorClick(sectorId, layerType);
                
                // Update the popup to reflect new state
                this.updateSectorSelectionPopup(popup);
            });
        });
        
        // Select All button
        popup.querySelector('#sector-select-all-btn')?.addEventListener('click', () => {
            popup.querySelectorAll('.sector-checkbox').forEach(cb => cb.checked = true);
        });
        
        // Deselect All button  
        popup.querySelector('#sector-deselect-all-btn')?.addEventListener('click', () => {
            popup.querySelectorAll('.sector-checkbox').forEach(cb => cb.checked = false);
        });
        
        // Apply button - apply checkbox state as the new selection
        popup.querySelector('#sector-apply-btn')?.addEventListener('click', () => {
            this.applySectorSelectionFromPopup(popup);
            popup.remove();
        });
        
        // Close on click outside (on document, but not on map - map click handler handles that)
        // Store reference so we can remove it later
        this._sectorSelectCloseHandler = (ev) => {
            // Check if popup still exists
            if (!document.getElementById('sector-select-popup')) {
                document.removeEventListener('click', this._sectorSelectCloseHandler);
                return;
            }
            // Don't close if clicking inside popup
            if (popup.contains(ev.target)) {
                return;
            }
            // Don't close if clicking on the map canvas (map click handler will handle it)
            if (ev.target.classList.contains('maplibregl-canvas') || 
                ev.target.closest('.maplibregl-canvas-container')) {
                return;
            }
            // Close for clicks elsewhere (sidebar, etc.)
            popup.remove();
            document.removeEventListener('click', this._sectorSelectCloseHandler);
        };
        
        // Delay adding the handler to avoid immediate closure from the triggering click
        setTimeout(() => {
            document.addEventListener('click', this._sectorSelectCloseHandler);
        }, 100);
    },
    
    /**
     * Update the sector selection popup to reflect current state
     */
    updateSectorSelectionPopup(popup) {
        const currentlySelected = this.getCurrentlySelectedSectors();
        
        popup.querySelectorAll('.sector-select-item').forEach(item => {
            const sectorId = item.dataset.sector;
            const isSelected = currentlySelected.has(sectorId);
            const checkbox = item.querySelector('.sector-checkbox');
            const indicator = item.querySelector('.selected-indicator');
            
            if (checkbox) checkbox.checked = isSelected;
            if (indicator) indicator.textContent = isSelected ? '✓' : '';
            item.classList.toggle('is-selected', isSelected);
        });
    },
    
    /**
     * Apply the checkbox selections from the popup
     * This sets the selected sectors to match the checkbox state
     */
    applySectorSelectionFromPopup(popup) {
        const toAdd = [];
        const toRemove = [];
        const currentlySelected = this.getCurrentlySelectedSectors();
        
        popup.querySelectorAll('.sector-select-item').forEach(item => {
            const sectorId = item.dataset.sector;
            const layerType = item.dataset.type;
            const checkbox = item.querySelector('.sector-checkbox');
            const isChecked = checkbox?.checked || false;
            const wasSelected = currentlySelected.has(sectorId);
            
            if (isChecked && !wasSelected) {
                toAdd.push({ sectorId, layerType });
            } else if (!isChecked && wasSelected) {
                toRemove.push({ sectorId, layerType });
            }
        });
        
        // Apply changes
        toAdd.forEach(({ sectorId, layerType }) => {
            this.addSectorToCurrentSelection(sectorId, layerType);
        });
        toRemove.forEach(({ sectorId, layerType }) => {
            this.removeSectorFromCurrentSelection(sectorId, layerType);
        });
        
        // Update UI
        this.updateMapSelectionOverlay();
        this.updateSelectionIndicator();
    },
    
    /**
     * Add a sector to the current selection (without toggling)
     */
    addSectorToCurrentSelection(sectorId, layerType) {
        if (this.mapSelectionMode === 'split' && this.editingSplitIndex >= 0) {
            const split = this.currentConfig.splits[this.editingSplitIndex];
            if (!split.sectors.includes(sectorId)) {
                // Check if assigned elsewhere
                let assignedElsewhere = false;
                this.currentConfig.splits.forEach((s, i) => {
                    if (i !== this.editingSplitIndex && s.sectors.includes(sectorId)) {
                        assignedElsewhere = true;
                    }
                });
                if (!assignedElsewhere) {
                    split.sectors.push(sectorId);
                }
            }
        } else if (this.mapSelectionMode === 'area') {
            if (!this._areaSelectedSectors.includes(sectorId)) {
                this._areaSelectedSectors.push(sectorId);
            }
        } else if (this.mapSelectionMode === 'preset' && this._editingPresetPositionIndex >= 0) {
            const position = this._presetPositions[this._editingPresetPositionIndex];
            if (!position.sectors) position.sectors = [];
            if (!position.sectors.includes(sectorId)) {
                // Check if assigned elsewhere
                let assignedElsewhere = false;
                this._presetPositions.forEach((p, i) => {
                    if (i !== this._editingPresetPositionIndex && (p.sectors || []).includes(sectorId)) {
                        assignedElsewhere = true;
                    }
                });
                if (!assignedElsewhere) {
                    position.sectors.push(sectorId);
                }
            }
        }
    },
    
    /**
     * Remove a sector from the current selection (without toggling)
     */
    removeSectorFromCurrentSelection(sectorId, layerType) {
        if (this.mapSelectionMode === 'split' && this.editingSplitIndex >= 0) {
            const split = this.currentConfig.splits[this.editingSplitIndex];
            const idx = split.sectors.indexOf(sectorId);
            if (idx >= 0) split.sectors.splice(idx, 1);
        } else if (this.mapSelectionMode === 'area') {
            const idx = this._areaSelectedSectors.indexOf(sectorId);
            if (idx >= 0) this._areaSelectedSectors.splice(idx, 1);
        } else if (this.mapSelectionMode === 'preset' && this._editingPresetPositionIndex >= 0) {
            const position = this._presetPositions[this._editingPresetPositionIndex];
            if (position.sectors) {
                const idx = position.sectors.indexOf(sectorId);
                if (idx >= 0) position.sectors.splice(idx, 1);
            }
        }
    },
    
    /**
     * Update the appropriate selection indicator based on current mode
     */
    updateSelectionIndicator() {
        if (this.mapSelectionMode === 'split') {
            this.updateSplitMapSelectionIndicator();
        } else if (this.mapSelectionMode === 'area') {
            this.updateAreaMapSelectionIndicator();
        } else if (this.mapSelectionMode === 'preset') {
            this.updatePresetMapSelectionIndicator();
        }
    },
    
    /**
     * Close sector selection popup and clean up its event handlers
     */
    closeSectorSelectionPopup() {
        const popup = document.getElementById('sector-select-popup');
        if (popup) popup.remove();
        
        if (this._sectorSelectCloseHandler) {
            document.removeEventListener('click', this._sectorSelectCloseHandler);
            this._sectorSelectCloseHandler = null;
        }
    },
    
    getFeatureName(item) {
        const props = item.feature.properties;
        switch (item.layerType) {
            case 'active':
                return props.sector_id || props.position || 'Active Sector';
            case 'high':
            case 'low':
            case 'superhigh':
                return props.label || props.name || props.id || 'Sector';
            case 'artcc':
                return props.id || props.name || props.ID || 'ARTCC';
            case 'tracon':
                return props.sector || props.label || props.id || props.name || 'TRACON';
            case 'areas':
                return props.area_name || 'Area';
            case 'presets':
                return props.position_name || props.preset_name || 'Preset';
            default:
                return 'Feature';
        }
    },
    
    showLayerSelectPopup(features, e) {
        // Remove existing popup if any
        let popup = document.getElementById('layer-select-popup');
        if (popup) popup.remove();
        
        // Color map for layer types
        const typeColors = {
            'high': '#FF6347',
            'low': '#228B22',
            'superhigh': '#9932CC',
            'artcc': '#FF00FF',
            'tracon': '#4682B4',
            'areas': '#a855f7',
            'presets': '#f59e0b',
            'active': '#e63946'
        };
        
        // Build list HTML with more info for sectors
        const listHtml = features.map((item, index) => {
            const props = item.feature.properties;
            const typeName = {
                'active': 'Split',
                'high': 'High',
                'low': 'Low',
                'superhigh': 'Superhigh',
                'artcc': 'ARTCC',
                'tracon': 'TRACON',
                'areas': 'Area',
                'presets': 'Preset'
            }[item.layerType] || item.layerType;
            
            const typeColor = typeColors[item.layerType] || '#888';
            const featureName = this.getFeatureName(item);
            
            // For sector types, show ARTCC info
            let extraInfo = '';
            if (['high', 'low', 'superhigh'].includes(item.layerType)) {
                const artcc = (props.artcc || '').toUpperCase();
                if (artcc) {
                    extraInfo = `<span class="layer-artcc">${artcc}</span>`;
                }
            }
            
            return `
                <div class="layer-select-item" data-index="${index}">
                    <span class="layer-type" style="background: ${typeColor}">${typeName}</span>
                    <span class="layer-name">${featureName}</span>
                    ${extraInfo}
                </div>
            `;
        }).join('');
        
        const headerText = features.length > 1 ? `${features.length} Overlapping Features` : 'Feature Info';
        
        // Create popup dynamically inside map container
        popup = document.createElement('div');
        popup.id = 'layer-select-popup';
        popup.className = 'layer-select-popup';
        
        popup.innerHTML = `
            <div class="layer-select-header">
                <span>${headerText}</span>
                <button class="popup-close-btn" title="Close">&times;</button>
            </div>
            <div class="layer-select-list" id="layer-select-list">${listHtml}</div>
        `;
        
        // Append to splits-map-container for correct positioning (has position: relative)
        const mapContainer = document.querySelector('.splits-map-container');
        if (!mapContainer) {
            console.error('[SPLITS] Could not find .splits-map-container!');
            return;
        }
        mapContainer.appendChild(popup);
        
        // Position popup near click, ensuring it stays within map bounds
        const mapRect = mapContainer.getBoundingClientRect();
        let left = e.point.x + 10;
        let top = e.point.y + 10;
        
        // Set position
        popup.style.left = left + 'px';
        popup.style.top = top + 'px';
        
        // Adjust if popup goes off-screen
        const popupRect = popup.getBoundingClientRect();
        const popupWidth = popupRect.width;
        const popupHeight = popupRect.height;
        
        if (left + popupWidth > mapRect.width - 10) {
            left = e.point.x - popupWidth - 10;
        }
        if (top + popupHeight > mapRect.height - 10) {
            top = e.point.y - popupHeight - 10;
        }
        
        left = Math.max(10, left);
        top = Math.max(10, top);
        
        // Update final position
        popup.style.left = left + 'px';
        popup.style.top = top + 'px';
        
        // Store features and lngLat for click handling
        this._selectableFeatures = features;
        this._selectLngLat = e.lngLat;
        
        // Bind close button
        popup.querySelector('.popup-close-btn')?.addEventListener('click', (ev) => {
            ev.stopPropagation();
            popup.remove();
        });
        
        // Bind click handler for list items
        const list = popup.querySelector('.layer-select-list');
        list?.addEventListener('click', (ev) => {
            const item = ev.target.closest('.layer-select-item');
            if (!item) return;
            
            ev.stopPropagation();
            const index = parseInt(item.dataset.index);
            const selected = this._selectableFeatures[index];
            popup.remove();
            this.showFeaturePopup(selected, this._selectLngLat);
        });
    },
    
    showFeaturePopup(item, lngLat) {
        const props = item.feature.properties;
        let html = '';
        
        switch (item.layerType) {
            case 'active':
                html = this.buildActiveSectorPopup(props);
                break;
            case 'high':
            case 'low':
            case 'superhigh':
                html = this.buildSectorPopup(props, item.layerType);
                break;
            case 'artcc':
                html = this.buildArtccPopup(props);
                break;
            case 'tracon':
                html = this.buildTraconPopup(props);
                break;
            case 'areas':
                html = this.buildAreaPopup(props);
                break;
            case 'presets':
                html = this.buildPresetPopup(props);
                break;
            default:
                html = `<div class="sector-popup"><div class="popup-body">Unknown feature type</div></div>`;
        }
        
        this.sectorPopup
            .setLngLat(lngLat)
            .setHTML(html)
            .addTo(this.map);
    },
    
    buildActiveSectorPopup(props) {
        // Find which config this sector belongs to
        let configInfo = null;
        for (const config of this.activeConfigs) {
            for (const pos of (config.positions || [])) {
                if ((pos.sectors || []).includes(props.sector_id)) {
                    configInfo = { config, position: pos };
                    break;
                }
            }
            if (configInfo) break;
        }
        
        let html = `
            <div class="sector-popup">
                <div class="popup-header" style="background: ${props.color}; display: flex; justify-content: space-between; align-items: center; padding-right: 24px;">
                    <strong>${props.sector_id}</strong>
                    ${props.position ? `<span style="opacity: 0.9;">${props.position}</span>` : ''}
                </div>
                <div class="popup-body">
        `;
        
        if (configInfo) {
            const pos = configInfo.position;
            const sectors = pos.sectors || [];
            
            html += `
                    <div class="popup-row"><span>Position:</span> <strong style="color: ${pos.color}">${pos.position_name}</strong></div>
            `;
            if (pos.frequency) html += `<div class="popup-row"><span>Frequency:</span> <strong>${pos.frequency}</strong></div>`;
            html += `
                    <div class="popup-row"><span>Config:</span> ${configInfo.config.config_name}</div>
                    <div class="popup-row"><span>ARTCC:</span> ${configInfo.config.artcc}</div>
            `;
            if (pos.controller_oi) html += `<div class="popup-row"><span>Controller:</span> ${pos.controller_oi}</div>`;
            
            // Show other sectors in this position
            if (sectors.length > 1) {
                const otherSectors = sectors.filter(s => s !== props.sector_id);
                html += `
                    <hr style="margin: 6px 0; border-color: #444;">
                    <div class="popup-row" style="flex-direction: column; align-items: flex-start;">
                        <span style="margin-bottom: 2px;">Also in this position (${sectors.length} total):</span>
                        <div style="font-family: monospace; font-size: 10px; color: #aaa;">${otherSectors.join(', ')}</div>
                    </div>
                `;
            }
        } else {
            html += `<div class="popup-row"><span>Position:</span> <strong>${props.position || 'Unknown'}</strong></div>`;
        }
        
        html += `</div></div>`;
        return html;
    },
    
    buildSectorPopup(props, type) {
        const isHigh = type === 'high';
        const isSuperhigh = type === 'superhigh';
        const color = isSuperhigh ? '#9932CC' : (isHigh ? '#FF6347' : '#228B22');
        const levelName = isSuperhigh ? 'Superhigh' : (isHigh ? 'High' : 'Low');
        
        const artcc = (props.artcc || '').toUpperCase();
        const label = props.label || props.name || 'Unknown';
        const sector = props.sector || props.id || 'N/A';
        
        // Check if assigned to any active config
        let assignedTo = null;
        for (const config of this.activeConfigs) {
            for (const pos of (config.positions || [])) {
                if ((pos.sectors || []).includes(label)) {
                    assignedTo = { config, position: pos };
                    break;
                }
            }
            if (assignedTo) break;
        }
        
        let html = `
            <div class="sector-popup">
                <div class="popup-header" style="background: ${color};">
                    <strong>${label}</strong>
                </div>
                <div class="popup-body">
                    <div class="popup-row"><span>ARTCC:</span> <strong>${artcc}</strong></div>
                    <div class="popup-row"><span>Sector:</span> ${sector}</div>
                    <div class="popup-row"><span>Label:</span> ${label}</div>
                    <div class="popup-row"><span>Level:</span> ${levelName}</div>
        `;
        
        if (assignedTo) {
            html += `
                    <hr style="margin: 6px 0; border-color: #444;">
                    <div class="popup-row"><span>Assigned to:</span> <strong style="color: ${assignedTo.position.color}">${assignedTo.position.position_name}</strong></div>
                    <div class="popup-row"><span>Config:</span> ${assignedTo.config.config_name}</div>
            `;
        }
        
        html += `</div></div>`;
        return html;
    },
    
    buildArtccPopup(props) {
        return `
            <div class="sector-popup">
                <div class="popup-header" style="background: #FF00FF">
                    <strong>${props.id || props.name || props.ID || 'ARTCC'}</strong>
                </div>
                <div class="popup-body">
                    <div class="popup-row"><span>Type:</span> ARTCC Boundary</div>
                </div>
            </div>
        `;
    },
    
    buildTraconPopup(props) {
        // GeoJSON has: sector (code like PCT), label (name like "Potomac Approach"), artcc
        const sectorCode = (props.sector || '').toUpperCase();
        const labelName = props.label || '';
        const labelUpper = labelName.toUpperCase();
        
        // Create simplified versions for matching
        const labelSimple = labelUpper
            .replace(/ APPROACH$/i, '')
            .replace(/ TRACON$/i, '')
            .replace(/ TOWER$/i, '')
            .replace(/ RAPCON$/i, '')
            .replace(/ RATCF$/i, '')
            .trim();
        
        // Normalize abbreviations for matching (St. -> Saint, Ft. -> Fort, etc.)
        const normalizeForMatch = (str) => {
            return str
                .replace(/\bST\.\s*/gi, 'SAINT ')
                .replace(/\bST\s+/gi, 'SAINT ')
                .replace(/\bMT\.\s*/gi, 'MOUNT ')
                .replace(/\bMT\s+/gi, 'MOUNT ')
                .replace(/\bFT\.\s*/gi, 'FORT ')
                .replace(/\bFT\s+/gi, 'FORT ')
                .replace(/\bMC\s+/gi, 'MC ')
                .replace(/\./g, '')
                .replace(/\s+/g, ' ')
                .trim();
        };
        
        const labelNormalized = normalizeForMatch(labelSimple);
        
        // Try to find matching TRACON data from database
        let traconData = null;
        
        // Try by normalized label name first (handles St. -> Saint, etc.)
        if (labelNormalized && this.traconsDataByName && this.traconsDataByName[labelNormalized]) {
            traconData = this.traconsDataByName[labelNormalized];
        }
        // Try by simplified label name (without normalization)
        else if (labelSimple && this.traconsDataByName && this.traconsDataByName[labelSimple]) {
            traconData = this.traconsDataByName[labelSimple];
        }
        // Try full label
        else if (labelUpper && this.traconsDataByName && this.traconsDataByName[labelUpper]) {
            traconData = this.traconsDataByName[labelUpper];
        }
        // Try sector code as fallback (in case DB has codes)
        else if (sectorCode && this.traconsData[sectorCode]) {
            traconData = this.traconsData[sectorCode];
        }
        // Try sector code in names lookup too
        else if (sectorCode && this.traconsDataByName && this.traconsDataByName[sectorCode]) {
            traconData = this.traconsDataByName[sectorCode];
        }
        
        // Use GeoJSON data as fallback
        const displayCode = sectorCode || 'TRACON';
        const displayName = labelName || traconData?.tracon_name || '';
        const artccFromGeo = (props.artcc || '').toUpperCase();
        
        // Database values (if found)
        const airports = traconData?.airports_served || '';
        const artcc = traconData?.responsible_artcc || artccFromGeo || '';
        const region = traconData?.dcc_region || '';
        
        // Build flags display
        const flags = [];
        if (traconData?.contains_core30) flags.push('Core 30');
        if (traconData?.contains_oep35) flags.push('OEP 35');
        if (traconData?.contains_aspm77) flags.push('ASPM 77');
        const flagsHtml = flags.length > 0 
            ? `<div class="popup-row"><span>Designations:</span> ${flags.join(', ')}</div>`
            : '';
        
        // Format airports for display with expandable toggle
        let airportsHtml = '';
        if (airports) {
            const airportList = airports.split(',').map(a => a.trim()).filter(a => a);
            if (airportList.length <= 6) {
                airportsHtml = `<div class="popup-row"><span>Airports:</span> ${airportList.join(', ')}</div>`;
            } else {
                const shown = airportList.slice(0, 6).join(', ');
                const allAirports = airportList.join(', ');
                const more = airportList.length - 6;
                const uniqueId = 'airports-' + Date.now();
                airportsHtml = `<div class="popup-row" style="flex-direction: column; align-items: flex-start;">
                    <span>Airports (${airportList.length}):</span>
                    <div id="${uniqueId}-short" style="font-family: monospace; font-size: 10px; color: #aaa; margin-top: 2px;">${shown}</div>
                    <div id="${uniqueId}-full" style="font-family: monospace; font-size: 10px; color: #aaa; margin-top: 2px; display: none;">${allAirports}</div>
                    <div id="${uniqueId}-toggle" 
                         style="font-size: 10px; color: #4dabf7; cursor: pointer; margin-top: 2px;" 
                         onclick="
                            var short = document.getElementById('${uniqueId}-short');
                            var full = document.getElementById('${uniqueId}-full');
                            var toggle = document.getElementById('${uniqueId}-toggle');
                            if (short.style.display !== 'none') {
                                short.style.display = 'none';
                                full.style.display = 'block';
                                toggle.textContent = '▲ Show less';
                            } else {
                                short.style.display = 'block';
                                full.style.display = 'none';
                                toggle.textContent = '▼ Show all ${airportList.length} airports';
                            }
                         ">▼ Show all ${airportList.length} airports</div>
                </div>`;
            }
        }
        
        return `
            <div class="sector-popup">
                <div class="popup-header" style="background: #4682B4">
                    <strong>${displayCode}</strong>
                    ${displayName ? `<span style="font-weight: normal; font-size: 10px; opacity: 0.8; display: block;">${displayName}</span>` : ''}
                </div>
                <div class="popup-body">
                    ${artcc ? `<div class="popup-row"><span>ARTCC:</span> ${artcc}</div>` : ''}
                    ${region ? `<div class="popup-row"><span>DCC Region:</span> ${region}</div>` : ''}
                    ${flagsHtml}
                    ${airportsHtml}
                    ${!traconData && !artcc ? '<div class="popup-row"><span>Type:</span> TRACON Boundary</div>' : ''}
                </div>
            </div>
        `;
    },
    
    buildAreaPopup(props) {
        // Find the full area data to get sector list
        const areaData = this.areas.find(a => a.id === props.area_id);
        const sectors = areaData?.sectors || [];
        
        let sectorList = '';
        if (sectors.length > 0) {
            // Group sectors by prefix for compact display
            const byPrefix = {};
            sectors.forEach(s => {
                const match = s.match(/^([A-Z]{3})(\d+)$/);
                if (match) {
                    if (!byPrefix[match[1]]) byPrefix[match[1]] = [];
                    byPrefix[match[1]].push(match[2]);
                } else {
                    if (!byPrefix['_']) byPrefix['_'] = [];
                    byPrefix['_'].push(s);
                }
            });
            
            const parts = [];
            Object.keys(byPrefix).sort().forEach(prefix => {
                const nums = byPrefix[prefix].sort((a, b) => parseInt(a) - parseInt(b));
                if (prefix === '_') {
                    parts.push(nums.join(', '));
                } else {
                    parts.push(`${prefix}: ${nums.join(', ')}`);
                }
            });
            sectorList = parts.join('<br>');
        }
        
        return `
            <div class="sector-popup">
                <div class="popup-header" style="background: ${props.color || '#a855f7'}">
                    <strong>${props.artcc || ''} ${props.area_name || 'Area'}</strong>
                </div>
                <div class="popup-body">
                    <div class="popup-row"><span>ARTCC:</span> ${props.artcc || 'N/A'}</div>
                    <div class="popup-row"><span>Sectors:</span> ${sectors.length}</div>
                    ${sectorList ? `<div class="popup-row" style="flex-direction: column; align-items: flex-start;"><span style="margin-bottom: 2px;">Includes:</span><div style="font-family: monospace; font-size: 10px; color: #aaa;">${sectorList}</div></div>` : ''}
                </div>
            </div>
        `;
    },
    
    buildPresetPopup(props) {
        // Find the preset data
        const presetData = this.presets.find(p => p.id === props.preset_id);
        const positions = presetData?.positions || [];
        
        // Find this position's sectors
        const posData = positions.find(p => p.position_name === props.position_name);
        const sectors = posData?.sectors || [];
        
        return `
            <div class="sector-popup">
                <div class="popup-header" style="background: ${props.color || '#f59e0b'}; display: flex; justify-content: space-between; align-items: center; padding-right: 24px;">
                    <strong>${props.position_name || 'Position'}</strong>
                    <span style="opacity: 0.8; font-size: 11px;">${props.preset_name || ''}</span>
                </div>
                <div class="popup-body">
                    <div class="popup-row"><span>Preset:</span> ${props.preset_name || 'N/A'}</div>
                    <div class="popup-row"><span>ARTCC:</span> ${props.artcc || 'N/A'}</div>
                    <div class="popup-row"><span>Sectors:</span> ${sectors.length}</div>
                    ${sectors.length > 0 ? `<div class="popup-row" style="flex-direction: column; align-items: flex-start;"><span style="margin-bottom: 2px;">Includes:</span><div style="font-family: monospace; font-size: 10px; color: #aaa;">${sectors.join(', ')}</div></div>` : ''}
                </div>
            </div>
        `;
    },
    
    setLayerOpacity(layerName, opacity) {
        const layerConfigs = {
            artcc: {
                fill: { layer: 'artcc-fill', prop: 'fill-opacity', base: 0.1 },
                line: { layer: 'artcc-lines', prop: 'line-opacity', base: 1 }
            },
            high: {
                fill: { layer: 'high-fill', prop: 'fill-opacity', base: 0.25 },
                line: { layer: 'high-lines', prop: 'line-opacity', base: 1 }
            },
            low: {
                fill: { layer: 'low-fill', prop: 'fill-opacity', base: 0.25 },
                line: { layer: 'low-lines', prop: 'line-opacity', base: 1 }
            },
            superhigh: {
                fill: { layer: 'superhigh-fill', prop: 'fill-opacity', base: 0.25 },
                line: { layer: 'superhigh-lines', prop: 'line-opacity', base: 1 }
            },
            tracon: {
                fill: { layer: 'tracon-fill', prop: 'fill-opacity', base: 0.2 },
                line: { layer: 'tracon-lines', prop: 'line-opacity', base: 1 }
            },
            areas: {
                fill: { layer: 'areas-fill', prop: 'fill-opacity', base: 0.3 },
                line: { layer: 'areas-lines', prop: 'line-opacity', base: 1 }
            },
            presets: {
                fill: { layer: 'presets-fill', prop: 'fill-opacity', base: 0.25 },
                line: { layer: 'presets-lines', prop: 'line-opacity', base: 1 }
            },
            activeConfigs: {
                fill: { layer: 'sectors-fill', prop: 'fill-opacity', base: 0.6 },
                line: { layer: 'sectors-lines', prop: 'line-opacity', base: 1 }
            }
        };
        
        const config = layerConfigs[layerName];
        if (!config || !this.map) return;
        
        // Check button states to only adjust opacity for active (visible) sub-layers
        const fillBtn = document.querySelector(`.layer-fill-btn[data-layer="${layerName}"]`);
        const lineBtn = document.querySelector(`.layer-line-btn[data-layer="${layerName}"]`);
        const fillActive = fillBtn ? fillBtn.classList.contains('active') : true;
        const lineActive = lineBtn ? lineBtn.classList.contains('active') : true;
        
        // Only set opacity for layers whose button is active
        if (fillActive && config.fill && this.map.getLayer(config.fill.layer)) {
            this.map.setPaintProperty(config.fill.layer, config.fill.prop, config.fill.base * opacity);
        }
        if (lineActive && config.line && this.map.getLayer(config.line.layer)) {
            this.map.setPaintProperty(config.line.layer, config.line.prop, config.line.base * opacity);
        }
    },
    
    toggleLayerVisibility(layerName, visible) {
        const visibility = visible ? 'visible' : 'none';
        
        const layerGroups = {
            artcc: ['artcc-fill', 'artcc-lines', 'artcc-labels'],
            high: ['high-fill', 'high-lines', 'high-labels'],
            low: ['low-fill', 'low-lines', 'low-labels'],
            superhigh: ['superhigh-fill', 'superhigh-lines', 'superhigh-labels'],
            tracon: ['tracon-fill', 'tracon-lines', 'tracon-labels'],
            areas: ['areas-fill', 'areas-lines', 'areas-labels'],
            presets: ['presets-fill', 'presets-lines', 'presets-labels'],
            activeConfigs: ['sectors-fill', 'sectors-lines', 'sectors-labels']
        };
        
        const layers = layerGroups[layerName] || [];
        layers.forEach(layerId => {
            if (this.map && this.map.getLayer(layerId)) {
                this.map.setLayoutProperty(layerId, 'visibility', visibility);
            }
        });
        
        console.log(`[SPLITS] Layer ${layerName} visibility: ${visibility}`);
    },
    
    /**
     * Toggle active splits strata visibility (low, high, superhigh)
     */
    toggleActiveSplitsStrata(strata, visible) {
        this.activeSplitsStrata[strata] = visible;
        this.applyActiveSplitsStrataFilter();
        console.log(`[SPLITS] Active splits strata ${strata}: ${visible}`);
    },
    
    /**
     * Apply strata filter to active splits layers
     */
    applyActiveSplitsStrataFilter() {
        if (!this.map) return;
        
        // Build filter based on visible strata
        const visibleStrata = Object.entries(this.activeSplitsStrata)
            .filter(([_, visible]) => visible)
            .map(([strata, _]) => strata);
        
        // If all strata are visible, remove filter; otherwise apply filter
        let polygonFilter;
        if (visibleStrata.length === 3) {
            polygonFilter = null; // Show all
        } else if (visibleStrata.length === 0) {
            polygonFilter = ['==', ['get', 'boundary_type'], '__none__']; // Show none
        } else {
            polygonFilter = ['in', ['get', 'boundary_type'], ['literal', visibleStrata]];
        }
        
        // Apply filter to fill and line layers
        const polygonLayers = ['sectors-fill', 'sectors-lines'];
        polygonLayers.forEach(layerId => {
            if (this.map.getLayer(layerId)) {
                this.map.setFilter(layerId, polygonFilter);
            }
        });
        
        // Build label filter - show label if position has ANY visible strata
        let labelFilter;
        if (visibleStrata.length === 3) {
            labelFilter = null; // Show all
        } else if (visibleStrata.length === 0) {
            labelFilter = ['==', ['get', 'label'], '__never_match__']; // Show none
        } else {
            // Show label if any of its strata flags match visible strata
            const conditions = [];
            if (this.activeSplitsStrata.low) conditions.push(['get', 'has_low']);
            if (this.activeSplitsStrata.high) conditions.push(['get', 'has_high']);
            if (this.activeSplitsStrata.superhigh) conditions.push(['get', 'has_superhigh']);
            
            // Use 'any' to check if any of the strata flags are truthy
            labelFilter = ['any', ...conditions];
        }
        
        // Apply filter to labels layer
        if (this.map.getLayer('sectors-labels')) {
            this.map.setFilter('sectors-labels', labelFilter);
        }
        
        console.log('[SPLITS] Active splits strata filter applied:', visibleStrata);
    },
    
    populateArtccDropdowns() {
        const selectors = ['#config-artcc', '#area-artcc', '#areas-artcc-filter', '#browse-artcc-filter', '#preset-artcc', '#presets-artcc-filter'];
        selectors.forEach(sel => {
            const el = document.querySelector(sel);
            if (!el) return;
            const firstOption = el.options[0]?.outerHTML || '<option value="">Select ARTCC</option>';
            el.innerHTML = firstOption;
            this.artccList.forEach(artcc => {
                el.innerHTML += `<option value="${artcc}">${artcc}</option>`;
            });
        });
    },
    
    populateSplitColorPicker() {
        const container = document.getElementById('split-color-picker');
        if (!container) return;
        container.innerHTML = this.colorPalette.map((color, i) => 
            `<div class="color-swatch ${i === 0 ? 'selected' : ''}" data-color="${color}" style="background:${color}"></div>`
        ).join('');
        container.querySelectorAll('.color-swatch').forEach(swatch => {
            swatch.addEventListener('click', () => {
                container.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('selected'));
                swatch.classList.add('selected');
            });
        });
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // AREAS MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════
    
    async loadAreas() {
        try {
            const response = await fetch('api/splits/areas.php');
            if (response.ok) {
                const data = await response.json();
                this.areas = data.areas || [];
                console.log(`[SPLITS] Loaded ${this.areas.length} areas`);
                
                // Load saved colors from database into areaColors cache
                this.areas.forEach(area => {
                    if (area.color) {
                        this.areaColors[area.id] = area.color;
                    }
                });
                
                // Initialize visible areas set (all visible by default)
                if (!this.visibleAreas) {
                    this.visibleAreas = new Set(this.areas.map(a => a.id));
                }
                
                // Populate ARTCC select in sidebar
                this.populateAreasArtccSelect();
                
                // Render toggle list in sidebar
                this.renderAreasToggleList();
                
                // Update areas layer if map is ready
                this.updateAreasLayer();
            }
        } catch (err) {
            console.warn('[SPLITS] Failed to load areas:', err);
            this.areas = [];
        }
    },
    
    async loadTracons() {
        try {
            const response = await fetch('api/splits/tracons.php');
            
            if (response.ok) {
                const data = await response.json();
                
                // Normalize abbreviations for matching
                const normalizeForMatch = (str) => {
                    return str
                        .replace(/\bST\.\s*/gi, 'SAINT ')
                        .replace(/\bST\s+/gi, 'SAINT ')
                        .replace(/\bMT\.\s*/gi, 'MOUNT ')
                        .replace(/\bMT\s+/gi, 'MOUNT ')
                        .replace(/\bFT\.\s*/gi, 'FORT ')
                        .replace(/\bFT\s+/gi, 'FORT ')
                        .replace(/\bMC\s+/gi, 'MC ')
                        .replace(/\./g, '')
                        .replace(/\s+/g, ' ')
                        .trim();
                };
                
                // Build multiple lookup maps for flexible matching
                this.traconsData = {};
                this.traconsDataByName = {};
                
                if (Array.isArray(data)) {
                    data.forEach(t => {
                        // Primary lookup by tracon_id
                        if (t.tracon_id) {
                            this.traconsData[t.tracon_id.toUpperCase()] = t;
                        }
                        // Secondary lookup by tracon_name (for matching GeoJSON labels)
                        if (t.tracon_name) {
                            const nameUpper = t.tracon_name.toUpperCase();
                            // Store by full name
                            this.traconsDataByName[nameUpper] = t;
                            // Store by normalized name (SAINT LOUIS matches ST. LOUIS)
                            const nameNormalized = normalizeForMatch(nameUpper);
                            if (nameNormalized && nameNormalized !== nameUpper) {
                                this.traconsDataByName[nameNormalized] = t;
                            }
                            // Also store by name without common suffixes
                            const simpleName = nameUpper
                                .replace(/ APPROACH$/i, '')
                                .replace(/ TRACON$/i, '')
                                .replace(/ ARTCC$/i, '')
                                .trim();
                            if (simpleName && simpleName !== nameUpper) {
                                this.traconsDataByName[simpleName] = t;
                            }
                        }
                    });
                }
                console.log(`[SPLITS] Loaded ${Object.keys(this.traconsData).length} TRACONs from database`);
            }
        } catch (err) {
            console.warn('[SPLITS] Failed to load TRACONs:', err);
            this.traconsData = {};
            this.traconsDataByName = {};
        }
    },
    
    populateAreasArtccSelect() {
        const select = document.getElementById('areas-artcc-select');
        if (!select) return;
        
        const artccs = [...new Set(this.areas.map(a => a.artcc))].sort();
        select.innerHTML = '<option value="">All ARTCCs</option>' + 
            artccs.map(a => `<option value="${a}">${a}</option>`).join('');
    },
    
    renderAreasToggleList() {
        const container = document.getElementById('areas-toggle-list');
        if (!container) return;
        
        const filter = document.getElementById('areas-artcc-select')?.value || '';
        const filtered = filter ? this.areas.filter(a => a.artcc === filter) : this.areas;
        
        if (filtered.length === 0) {
            container.innerHTML = '<div class="text-muted text-center py-3" style="font-size: 11px;">No areas defined.</div>';
            return;
        }
        
        // Group by ARTCC
        const grouped = {};
        filtered.forEach(area => {
            if (!grouped[area.artcc]) grouped[area.artcc] = [];
            grouped[area.artcc].push(area);
        });
        
        // Default color palette for areas
        const defaultColors = ['#e63946', '#f4a261', '#2a9d8f', '#e9c46a', '#264653', '#a855f7', '#06b6d4'];
        
        let html = '';
        Object.entries(grouped).sort().forEach(([artcc, areas]) => {
            html += `<div class="area-toggle-group" data-artcc="${artcc}">
                <div class="area-toggle-header">
                    <span>${artcc}</span>
                    <div class="artcc-toggle-btns">
                        <button class="btn btn-xs btn-link artcc-show-all-btn" data-artcc="${artcc}" title="Show all ${artcc}">All</button>
                        <button class="btn btn-xs btn-link artcc-hide-all-btn" data-artcc="${artcc}" title="Hide all ${artcc}">None</button>
                    </div>
                </div>`;
            
            areas.forEach((area, i) => {
                const isVisible = this.visibleAreas.has(area.id);
                // Use stored color or default based on index
                const originalIndex = this.areas.findIndex(a => a.id === area.id);
                const color = this.areaColors[area.id] || defaultColors[originalIndex % defaultColors.length];
                const sectorCount = Array.isArray(area.sectors) ? area.sectors.length : 0;
                
                html += `
                    <div class="area-toggle-item" data-area-id="${area.id}">
                        <input type="checkbox" class="area-toggle-checkbox" ${isVisible ? 'checked' : ''}>
                        <input type="color" class="area-color-picker" value="${color}" title="Change color">
                        <span class="area-toggle-name">${area.area_name}</span>
                        <span class="area-toggle-count">${sectorCount}</span>
                    </div>
                `;
            });
            
            html += '</div>';
        });
        
        container.innerHTML = html;
        
        // Bind toggle events
        container.querySelectorAll('.area-toggle-item').forEach(item => {
            const checkbox = item.querySelector('.area-toggle-checkbox');
            const colorPicker = item.querySelector('.area-color-picker');
            const areaId = parseInt(item.dataset.areaId);
            
            // Checkbox toggle
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) {
                    this.visibleAreas.add(areaId);
                } else {
                    this.visibleAreas.delete(areaId);
                }
                this.updateAreasLayer();
            });
            
            // Color picker - save color to backend on change
            colorPicker.addEventListener('input', (e) => {
                e.stopPropagation();
                this.areaColors[areaId] = e.target.value;
                this.updateAreasLayer();
            });
            
            // Save color when picker is closed (on change event, after input)
            colorPicker.addEventListener('change', (e) => {
                e.stopPropagation();
                this.saveAreaColor(areaId, e.target.value);
            });
            
            colorPicker.addEventListener('click', (e) => {
                e.stopPropagation(); // Don't toggle checkbox when clicking color picker
            });
            
            // Click anywhere on item (except color picker) toggles checkbox
            item.addEventListener('click', (e) => {
                if (e.target !== checkbox && e.target !== colorPicker) {
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event('change'));
                }
            });
        });
        
        // Bind per-ARTCC All/None buttons
        container.querySelectorAll('.artcc-show-all-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const artcc = btn.dataset.artcc;
                this.showAreasForArtcc(artcc);
            });
        });
        
        container.querySelectorAll('.artcc-hide-all-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const artcc = btn.dataset.artcc;
                this.hideAreasForArtcc(artcc);
            });
        });
    },
    
    showAreasForArtcc(artcc) {
        this.areas.filter(a => a.artcc === artcc).forEach(a => this.visibleAreas.add(a.id));
        this.renderAreasToggleList();
        this.updateAreasLayer();
    },
    
    hideAreasForArtcc(artcc) {
        this.areas.filter(a => a.artcc === artcc).forEach(a => this.visibleAreas.delete(a.id));
        this.renderAreasToggleList();
        this.updateAreasLayer();
    },
    
    showAllAreas() {
        this.areas.forEach(a => this.visibleAreas.add(a.id));
        this.renderAreasToggleList();
        this.updateAreasLayer();
    },
    
    hideAllAreas() {
        this.visibleAreas.clear();
        this.renderAreasToggleList();
        this.updateAreasLayer();
    },
    
    openAreasModal() {
        this.renderAreasList();
        this.showNoAreaSelected();
        $('#areas-modal').modal('show');
    },
    
    renderAreasList() {
        const container = document.getElementById('areas-list');
        const filter = document.getElementById('areas-artcc-filter')?.value || '';
        
        const filtered = filter ? this.areas.filter(a => a.artcc === filter) : this.areas;
        
        if (filtered.length === 0) {
            container.innerHTML = '<div class="text-muted text-center py-3">No areas defined yet.</div>';
            return;
        }
        
        // Group by ARTCC
        const grouped = {};
        filtered.forEach(area => {
            if (!grouped[area.artcc]) grouped[area.artcc] = [];
            grouped[area.artcc].push(area);
        });
        
        let html = '';
        Object.entries(grouped).sort().forEach(([artcc, areas]) => {
            html += `<div class="area-group-header">${artcc}</div>`;
            areas.forEach(area => {
                const sectorCount = Array.isArray(area.sectors) ? area.sectors.length : 0;
                html += `
                    <div class="area-list-item" data-area-id="${area.id}">
                        <strong>${area.area_name}</strong>
                        <span class="text-muted ml-2">(${sectorCount} sectors)</span>
                    </div>
                `;
            });
        });
        
        container.innerHTML = html;
        
        container.querySelectorAll('.area-list-item').forEach(item => {
            item.addEventListener('click', () => this.editArea(parseInt(item.dataset.areaId)));
        });
    },
    
    showNoAreaSelected() {
        document.getElementById('area-editor').style.display = 'none';
        document.getElementById('no-area-selected').style.display = 'block';
        this.editingAreaId = null;
    },
    
    newArea() {
        this.editingAreaId = null;
        document.getElementById('area-artcc').value = '';
        document.getElementById('area-name').value = '';
        document.getElementById('area-description').value = '';
        document.getElementById('area-sector-grid').innerHTML = '<div class="text-muted">Select an ARTCC to load sectors</div>';
        document.getElementById('area-selected-sectors').innerHTML = '<span class="text-muted">None selected</span>';
        
        document.getElementById('no-area-selected').style.display = 'none';
        document.getElementById('area-editor').style.display = 'block';
    },
    
    editArea(areaId) {
        const area = this.areas.find(a => a.id === areaId);
        if (!area) return;
        
        this.editingAreaId = areaId;
        document.getElementById('area-artcc').value = area.artcc;
        document.getElementById('area-name').value = area.area_name;
        document.getElementById('area-description').value = area.description || '';
        
        // Load sectors for this ARTCC and select the area's sectors
        this.loadAreaSectors(area.artcc, area.sectors);
        
        document.getElementById('no-area-selected').style.display = 'none';
        document.getElementById('area-editor').style.display = 'block';
    },
    
    loadAreaSectors(artcc, selectedSectors = []) {
        const container = document.getElementById('area-sector-grid');
        if (!artcc) {
            container.innerHTML = '<div class="text-muted">Select an ARTCC first</div>';
            return;
        }
        
        const sectors = this.getSectorsForArtcc(artcc);
        if (sectors.length === 0) {
            container.innerHTML = '<div class="text-muted">No sectors found for this ARTCC</div>';
            return;
        }
        
        container.innerHTML = sectors.map(s => {
            const isSelected = selectedSectors.includes(s.id);
            const typeColor = s.type === 'superhigh' ? '#9932CC' : (s.type === 'high' ? '#FF6347' : '#228B22');
            return `<div class="sector-chip ${isSelected ? 'selected' : ''}" data-sector="${s.id}" data-type="${s.type}">
                <span class="sector-type-dot" style="background:${typeColor}"></span>${s.name}
            </div>`;
        }).join('');
        
        container.querySelectorAll('.sector-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                chip.classList.toggle('selected');
                this.updateAreaSelectedDisplay();
            });
        });
        
        this.updateAreaSelectedDisplay();
    },
    
    getSectorsForArtcc(artcc) {
        const sectors = [];
        const artccLower = artcc.toLowerCase();
        
        ['high', 'low', 'superhigh'].forEach(type => {
            const data = this.geoJsonCache[type];
            if (!data?.features) return;
            
            data.features.forEach(f => {
                if ((f.properties?.artcc || '').toLowerCase() === artccLower) {
                    const label = f.properties.label || `${artcc}${f.properties.sector}`;
                    if (!sectors.find(s => s.id === label)) {
                        sectors.push({
                            id: label,
                            name: label,
                            sectorNum: f.properties.sector,
                            type: type
                        });
                    }
                }
            });
        });
        
        return sectors.sort((a, b) => a.name.localeCompare(b.name));
    },
    
    updateAreaSelectedDisplay() {
        const container = document.getElementById('area-selected-sectors');
        if (!container) return;
        
        const selected = this._areaSelectedSectors || 
            Array.from(document.querySelectorAll('#area-sector-grid .sector-chip.selected'))
                .map(c => c.dataset.sector);
        
        if (selected.length === 0) {
            container.innerHTML = '<span class="text-muted">None selected</span>';
        } else {
            container.innerHTML = selected.join(', ');
        }
    },
    
    areaSelectAllSectors() {
        document.querySelectorAll('#area-sector-grid .sector-chip').forEach(c => c.classList.add('selected'));
        this.updateAreaSelectedDisplay();
    },
    
    areaClearAllSectors() {
        document.querySelectorAll('#area-sector-grid .sector-chip').forEach(c => c.classList.remove('selected'));
        this.updateAreaSelectedDisplay();
    },
    
    /**
     * Apply area sector input from the text field
     * Supports formats:
     *   - Full IDs: ZDC50,ZDC51,ZDC53,ZDC54
     *   - Numbers only: 50,51,52,53,54 (auto-prefixes with selected ARTCC)
     */
    applyAreaSectorInput() {
        const input = document.getElementById('area-sector-input');
        if (!input) return;
        
        const value = input.value.trim();
        if (!value) return;
        
        const artcc = document.getElementById('area-artcc')?.value;
        const sectorIds = this.parseSectorInput(value, artcc);
        
        if (sectorIds.length === 0) {
            console.log('[SPLITS] No valid sectors parsed from area input');
            return;
        }
        
        // Select matching sectors in the area grid
        let selectedCount = 0;
        sectorIds.forEach(id => {
            const chip = document.querySelector(`#area-sector-grid .sector-chip[data-sector="${id}"]`);
            if (chip) {
                chip.classList.add('selected');
                selectedCount++;
            }
        });
        
        console.log(`[SPLITS] Area sector input applied: ${selectedCount}/${sectorIds.length} sectors selected`);
        
        // Update the selected sectors display
        this.updateAreaSelectedDisplay();
        
        // Clear the input after applying
        input.value = '';
    },
    
    async saveArea() {
        const artcc = document.getElementById('area-artcc').value;
        const areaName = document.getElementById('area-name').value.trim();
        const description = document.getElementById('area-description').value.trim();
        const sectors = Array.from(document.querySelectorAll('#area-sector-grid .sector-chip.selected'))
            .map(c => c.dataset.sector);
        
        if (!artcc || !areaName) {
            alert('Please fill in ARTCC and Area Name');
            return;
        }
        if (sectors.length === 0) {
            alert('Please select at least one sector');
            return;
        }
        
        // Get color: use cached color for existing areas, or default for new areas
        const defaultColors = ['#e63946', '#f4a261', '#2a9d8f', '#e9c46a', '#264653', '#a855f7', '#06b6d4'];
        const color = this.editingAreaId 
            ? (this.areaColors[this.editingAreaId] || defaultColors[this.areas.length % defaultColors.length])
            : defaultColors[this.areas.length % defaultColors.length];
        
        const payload = { artcc, area_name: areaName, description, sectors, color };
        
        try {
            let response;
            if (this.editingAreaId) {
                response = await fetch(`api/splits/areas.php?id=${this.editingAreaId}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            } else {
                response = await fetch('api/splits/areas.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            }
            
            const result = await response.json();
            
            if (response.ok) {
                await this.loadAreas();
                this.renderAreasList();
                this.showNoAreaSelected();
                alert(result.message || 'Area saved successfully');
            } else {
                alert(result.error || 'Failed to save area');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to save area:', err);
            alert('Failed to save area: ' + err.message);
        }
    },
    
    async deleteArea() {
        if (!this.editingAreaId) return;
        if (!confirm('Are you sure you want to delete this area?')) return;
        
        try {
            const response = await fetch(`api/splits/areas.php?id=${this.editingAreaId}`, {
                method: 'DELETE'
            });
            
            if (response.ok) {
                await this.loadAreas();
                this.renderAreasList();
                this.showNoAreaSelected();
            } else {
                const result = await response.json();
                alert(result.error || 'Failed to delete area');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to delete area:', err);
            alert('Failed to delete area: ' + err.message);
        }
    },
    
    /**
     * Save just the color for an area (quick update from color picker)
     */
    async saveAreaColor(areaId, color) {
        try {
            const response = await fetch(`api/splits/areas.php?id=${areaId}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ color: color })
            });
            
            if (response.ok) {
                console.log(`[SPLITS] Saved color ${color} for area ${areaId}`);
                // Update local cache
                const area = this.areas.find(a => a.id === areaId);
                if (area) area.color = color;
            } else {
                console.warn('[SPLITS] Failed to save area color');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to save area color:', err);
        }
    },
    
    cancelAreaEdit() {
        this.showNoAreaSelected();
        this.disableAreaMapSelection();
    },
    
    enableAreaMapSelection() {
        const artcc = document.getElementById('area-artcc').value;
        if (!artcc) {
            alert('Please select an ARTCC first');
            return;
        }
        
        // Track that we're hiding modal for map selection
        this._mapSelectionSourceModal = 'area';
        
        // Hide modal to see map
        $('#areas-modal').modal('hide');
        
        // Get currently selected sectors from the grid
        this._areaSelectedSectors = Array.from(document.querySelectorAll('#area-sector-grid .sector-chip.selected'))
            .map(c => c.dataset.sector);
        this._areaEditingArtcc = artcc;
        
        // Show High/Low/Superhigh sectors with outlines only (no fill) for selection
        const sectorLayers = ['high', 'low', 'superhigh'];
        sectorLayers.forEach(layer => {
            const checkbox = document.getElementById(`layer-${layer}`);
            if (checkbox && !checkbox.checked) {
                checkbox.checked = true;
                this.toggleLayerVisibility(layer, true);
            }
            
            // Set to outline-only mode for selection
            const fillBtn = document.querySelector(`.layer-fill-btn[data-layer="${layer}"]`);
            const lineBtn = document.querySelector(`.layer-line-btn[data-layer="${layer}"]`);
            
            // Turn off fill
            if (fillBtn && fillBtn.classList.contains('active')) {
                fillBtn.classList.remove('active');
                this.toggleLayerFill(layer, false);
            }
            
            // Turn on lines
            if (lineBtn && !lineBtn.classList.contains('active')) {
                lineBtn.classList.add('active');
                this.toggleLayerLine(layer, true);
            }
        });
        
        // Zoom to ARTCC
        this.zoomToArtcc(artcc);
        
        // Enable selection mode
        this.mapSelectionMode = 'area';
        this.mapSelectionColor = '#a855f7'; // Purple for areas
        
        // Update overlay
        this.updateMapSelectionOverlay();
        
        // Show indicator with done button
        this.showAreaMapSelectionIndicator();
    },
    
    showAreaMapSelectionIndicator() {
        let indicator = document.getElementById('area-map-selection-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'area-map-selection-indicator';
            indicator.className = 'area-map-selection-indicator';
            document.body.appendChild(indicator);
        }
        
        const count = this._areaSelectedSectors?.length || 0;
        indicator.innerHTML = `
            <div class="selection-indicator-content">
                <span class="indicator-dot" style="background: #a855f7"></span>
                <span class="indicator-text">Area Map Selection Mode - Click sectors to add/remove</span>
                <span class="badge badge-info ml-2">${count} selected</span>
                <button class="btn btn-sm btn-success ml-3" id="finish-area-selection-btn">
                    <i class="fas fa-check"></i> Done
                </button>
                <button class="btn btn-sm btn-secondary ml-1" id="cancel-area-selection-btn">
                    Cancel
                </button>
            </div>
        `;
        indicator.style.display = 'block';
        
        document.getElementById('finish-area-selection-btn')?.addEventListener('click', () => this.finishAreaMapSelection());
        document.getElementById('cancel-area-selection-btn')?.addEventListener('click', () => this.cancelAreaMapSelection());
    },
    
    updateAreaMapSelectionIndicator() {
        const badge = document.querySelector('#area-map-selection-indicator .badge');
        if (badge) {
            badge.textContent = `${this._areaSelectedSectors?.length || 0} selected`;
        }
    },
    
    finishAreaMapSelection() {
        // Update the sector grid with selected sectors
        const artcc = this._areaEditingArtcc;
        const selectedSectors = this._areaSelectedSectors || [];
        
        // Disable map mode
        this.disableAreaMapSelection();
        
        // Re-open modal and update grid
        $('#areas-modal').modal('show');
        
        // Reload sectors with new selection
        setTimeout(() => {
            this.loadAreaSectors(artcc, selectedSectors);
        }, 300);
    },
    
    cancelAreaMapSelection() {
        this.disableAreaMapSelection();
        $('#areas-modal').modal('show');
    },
    
    disableAreaMapSelection() {
        if (this.mapSelectionMode === 'area') {
            this.mapSelectionMode = null;
            this.mapSelectionColor = null;
        }
        this._mapSelectionSourceModal = null;
        this._areaSelectedSectors = null;
        this._areaEditingArtcc = null;
        
        // Clean up sector selection popup if open
        this.closeSectorSelectionPopup();
        
        // Remove selection overlay
        if (this.map && this.map.getSource('selection-source')) {
            this.map.getSource('selection-source').setData({ type: 'FeatureCollection', features: [] });
        }
        
        // Hide indicator
        const indicator = document.getElementById('area-map-selection-indicator');
        if (indicator) indicator.style.display = 'none';
    },
    
    updateAreaSectorGrid() {
        // Sync the sector grid with _areaSelectedSectors
        if (!this._areaSelectedSectors) return;
        
        document.querySelectorAll('#area-sector-grid .sector-chip').forEach(chip => {
            const sectorId = chip.dataset.sector;
            chip.classList.toggle('selected', this._areaSelectedSectors.includes(sectorId));
        });
        
        // Update selected sectors display
        this.updateAreaSelectedDisplay();
        
        // Update indicator count
        this.updateAreaMapSelectionIndicator();
    },
    
    zoomToArtcc(artcc) {
        if (!this.map || !this.geoJsonCache.artcc) return;
        
        const artccFeature = this.geoJsonCache.artcc.features.find(f => 
            (f.properties.id || f.properties.ID || f.properties.name || '').toUpperCase() === artcc.toUpperCase()
        );
        
        if (artccFeature && artccFeature.geometry) {
            const bounds = this.getGeometryBounds(artccFeature.geometry);
            if (bounds) {
                this.map.fitBounds(bounds, { padding: 50, duration: 500 });
            }
        }
    },
    
    getGeometryBounds(geometry) {
        let minLng = Infinity, minLat = Infinity, maxLng = -Infinity, maxLat = -Infinity;
        
        const processCoords = (coords) => {
            if (typeof coords[0] === 'number') {
                minLng = Math.min(minLng, coords[0]);
                maxLng = Math.max(maxLng, coords[0]);
                minLat = Math.min(minLat, coords[1]);
                maxLat = Math.max(maxLat, coords[1]);
            } else {
                coords.forEach(processCoords);
            }
        };
        
        processCoords(geometry.coordinates);
        
        if (minLng === Infinity) return null;
        return [[minLng, minLat], [maxLng, maxLat]];
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // PRESETS MANAGEMENT (Full Wizard Editor)
    // ═══════════════════════════════════════════════════════════════════
    
    // Preset editor state
    _presetStep: 1,
    _presetArtcc: null,
    _editingPresetId: null,
    _presetPositions: [],
    _editingPresetPositionIndex: -1,
    
    /**
     * Load all presets from server
     */
    async loadPresets() {
        try {
            const response = await fetch('api/splits/presets.php');
            if (response.ok) {
                const data = await response.json();
                this.presets = data.presets || [];
                console.log(`[SPLITS] Loaded ${this.presets.length} presets`);
                console.log('[SPLITS] Presets data:', JSON.stringify(this.presets.slice(0, 2), null, 2));
                this.populatePresetsArtccFilter();
                this.renderPresetsList();
                this.populatePresetDropdown();
                this.renderPresetsLayerList();
            }
        } catch (err) {
            console.warn('[SPLITS] Failed to load presets:', err);
            this.presets = [];
        }
    },
    
    populatePresetsArtccFilter() {
        const select = document.getElementById('presets-artcc-filter');
        if (!select) return;
        const artccs = [...new Set(this.presets.map(p => p.artcc))].sort();
        select.innerHTML = '<option value="">All ARTCCs</option>' + 
            artccs.map(a => `<option value="${a}">${a}</option>`).join('');
    },
    
    renderPresetsList() {
        const container = document.getElementById('presets-list');
        if (!container) return;
        
        const filter = document.getElementById('presets-artcc-filter')?.value || '';
        const filtered = filter ? this.presets.filter(p => p.artcc === filter) : this.presets;
        
        if (filtered.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">⭐</div>
                    <div class="empty-state-text">No saved presets yet.<br>Click "+ New Preset" to create one.</div>
                </div>`;
            return;
        }
        
        const grouped = {};
        filtered.forEach(preset => {
            if (!grouped[preset.artcc]) grouped[preset.artcc] = [];
            grouped[preset.artcc].push(preset);
        });
        
        let html = '';
        Object.entries(grouped).sort().forEach(([artcc, presets]) => {
            html += `<div class="area-toggle-group"><div class="area-toggle-header">${artcc}</div>`;
            presets.forEach(preset => {
                const posCount = preset.position_count || 0;
                html += `
                    <div class="preset-list-item" data-preset-id="${preset.id}" data-artcc="${preset.artcc}">
                        <div class="preset-list-info">
                            <div class="preset-list-name">${preset.preset_name}</div>
                            <div class="preset-list-meta">${posCount} position${posCount !== 1 ? 's' : ''}</div>
                        </div>
                        <div class="preset-list-actions">
                            <button class="btn btn-xs btn-outline-warning view-preset-btn" title="Edit"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-xs btn-outline-success use-preset-btn" title="Use in New Config"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>`;
            });
            html += '</div>';
        });
        
        container.innerHTML = html;
        
        // Bind events
        container.querySelectorAll('.view-preset-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const presetId = parseInt(btn.closest('.preset-list-item').dataset.presetId);
                this.editPreset(presetId);
            });
        });
        
        container.querySelectorAll('.use-preset-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const presetId = parseInt(btn.closest('.preset-list-item').dataset.presetId);
                this.usePresetForNewConfig(presetId);
            });
        });
        
        container.querySelectorAll('.preset-list-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (e.target.closest('.preset-list-actions')) return;
                this.editPreset(parseInt(item.dataset.presetId));
            });
        });
    },
    
    populatePresetDropdown(artcc = null) {
        const select = document.getElementById('load-preset-dropdown');
        if (!select) return;
        
        artcc = artcc || this.currentConfig?.artcc;
        const filtered = artcc ? this.presets.filter(p => p.artcc === artcc) : this.presets;
        
        if (filtered.length === 0) {
            select.innerHTML = `<option value="">No presets available${artcc ? ` for ${artcc}` : ''}</option>`;
            select.disabled = true;
            document.getElementById('load-preset-btn')?.setAttribute('disabled', 'disabled');
            return;
        }
        
        select.disabled = false;
        select.innerHTML = '<option value="">Select a saved preset...</option>';
        
        if (!artcc) {
            const grouped = {};
            filtered.forEach(p => {
                if (!grouped[p.artcc]) grouped[p.artcc] = [];
                grouped[p.artcc].push(p);
            });
            Object.entries(grouped).sort().forEach(([a, presets]) => {
                const optgroup = document.createElement('optgroup');
                optgroup.label = a;
                presets.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = `${p.preset_name} (${p.position_count || 0} pos)`;
                    optgroup.appendChild(opt);
                });
                select.appendChild(optgroup);
            });
        } else {
            filtered.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = `${p.preset_name} (${p.position_count || 0} positions)`;
                select.appendChild(opt);
            });
        }
    },
    
    /**
     * Load a preset into the current config (from config wizard)
     */
    async loadPresetIntoConfig() {
        const select = document.getElementById('load-preset-dropdown');
        const presetId = select?.value;
        if (!presetId) return alert('Please select a preset');
        
        try {
            const response = await fetch(`api/splits/presets.php?id=${presetId}`);
            if (!response.ok) throw new Error('Failed to load preset');
            
            const data = await response.json();
            const preset = data.preset;
            
            // Set ARTCC if not already set
            if (preset.artcc) {
                this.currentConfig.artcc = preset.artcc;
                document.getElementById('config-artcc').value = preset.artcc;
                this.loadArtccOnMap(preset.artcc);
            }
            
            // Set name if empty
            if (!document.getElementById('config-name').value) {
                document.getElementById('config-name').value = preset.preset_name;
            }
            
            // Load positions
            this.currentConfig.splits = preset.positions.map(pos => ({
                name: pos.position_name,
                sectors: pos.sectors || [],
                color: pos.color || '#4dabf7',
                frequency: pos.frequency || null,
                filters: pos.filters || null,
                startTime: null,
                endTime: null
            }));
            
            this.renderConfigSplitsList();
            this.updateMapPreview();
            
            select.value = '';
            document.getElementById('load-preset-btn')?.setAttribute('disabled', 'disabled');
            
            console.log(`[SPLITS] Loaded preset "${preset.preset_name}" with ${preset.positions.length} positions`);
        } catch (err) {
            console.error('[SPLITS] Failed to load preset:', err);
            alert('Failed to load preset: ' + err.message);
        }
    },
    
    /**
     * Use preset to start new config (from sidebar)
     */
    async usePresetForNewConfig(presetId) {
        try {
            const response = await fetch(`api/splits/presets.php?id=${presetId}`);
            if (!response.ok) throw new Error('Failed to load preset');
            
            const data = await response.json();
            const preset = data.preset;
            
            this.openConfigWizard({
                id: null,
                artcc: preset.artcc,
                name: preset.preset_name,
                startTime: null,
                endTime: null,
                sectorType: 'all',
                splits: preset.positions.map(pos => ({
                    name: pos.position_name,
                    sectors: pos.sectors || [],
                    color: pos.color || '#4dabf7',
                    frequency: pos.frequency || null,
                    filters: pos.filters || null
                }))
            });
            
            document.getElementById('config-artcc').value = preset.artcc;
            this.currentConfig.artcc = preset.artcc;
            this.loadArtccOnMap(preset.artcc);
            this.updateMapPreview();
        } catch (err) {
            console.error('[SPLITS] Failed to use preset:', err);
            alert('Failed to load preset');
        }
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // PRESET WIZARD
    // ═══════════════════════════════════════════════════════════════════
    
    openPresetModal() {
        this._presetStep = 1;
        this._presetArtcc = null;
        this._editingPresetId = null;
        this._presetPositions = [];
        this._editingPresetPositionIndex = -1;
        
        document.getElementById('preset-name').value = '';
        document.getElementById('preset-artcc').value = '';
        document.getElementById('preset-description').value = '';
        document.getElementById('preset-modal-title').textContent = 'New Preset';
        document.getElementById('delete-preset-btn').style.display = 'none';
        
        this.updatePresetWizardStep();
        this.renderPresetPositionsList();
        
        $('#preset-modal').modal('show');
    },
    
    async editPreset(presetId) {
        try {
            const response = await fetch(`api/splits/presets.php?id=${presetId}`);
            if (!response.ok) throw new Error('Failed to load preset');
            
            const data = await response.json();
            const preset = data.preset;
            
            this._presetStep = 1;
            this._editingPresetId = presetId;
            this._presetArtcc = preset.artcc;
            this._presetPositions = (preset.positions || []).map(p => ({
                id: p.id,  // Preserve position ID for PATCH updates
                name: p.position_name,
                sectors: p.sectors || [],
                color: p.color || '#4dabf7',
                frequency: p.frequency || null,
                filters: p.filters || null,
                strataFilter: p.strata_filter || null
            }));
            this._editingPresetPositionIndex = -1;
            
            document.getElementById('preset-name').value = preset.preset_name;
            document.getElementById('preset-artcc').value = preset.artcc;
            document.getElementById('preset-description').value = preset.description || '';
            document.getElementById('preset-modal-title').textContent = 'Edit Preset';
            document.getElementById('delete-preset-btn').style.display = 'block';
            
            this.updatePresetWizardStep();
            this.renderPresetPositionsList();
            
            $('#preset-modal').modal('show');
        } catch (err) {
            console.error('[SPLITS] Failed to edit preset:', err);
            alert('Failed to load preset');
        }
    },
    
    updatePresetWizardStep() {
        // Update step indicators
        document.querySelectorAll('#preset-step-indicator .step').forEach(el => {
            const step = parseInt(el.dataset.step);
            el.classList.toggle('active', step === this._presetStep);
            el.classList.toggle('completed', step < this._presetStep);
        });
        
        // Show/hide steps
        document.querySelectorAll('.preset-step').forEach((el, i) => {
            el.style.display = (i + 1 === this._presetStep) ? 'block' : 'none';
        });
        
        // Update buttons
        const prevBtn = document.getElementById('preset-prev-btn');
        const nextBtn = document.getElementById('preset-next-btn');
        const saveBtn = document.getElementById('save-preset-modal-btn');
        
        if (prevBtn) prevBtn.style.display = this._presetStep > 1 ? 'inline-block' : 'none';
        if (nextBtn) nextBtn.style.display = this._presetStep < 2 ? 'inline-block' : 'none';
        if (saveBtn) saveBtn.style.display = this._presetStep === 2 ? 'inline-block' : 'none';
    },
    
    presetNextStep() {
        if (this._presetStep === 1) {
            const artcc = document.getElementById('preset-artcc').value;
            const name = document.getElementById('preset-name').value.trim();
            
            if (!artcc || !name) {
                alert('Please fill in preset name and ARTCC');
                return;
            }
            
            this._presetArtcc = artcc;
            this._presetStep = 2;
            this.updatePresetWizardStep();
            this.renderPresetPositionsList();
            this.loadPresetArtccOnMap(artcc);
        }
    },
    
    presetPrevStep() {
        if (this._presetStep > 1) {
            this._presetStep--;
            this.updatePresetWizardStep();
        }
    },
    
    loadPresetArtccOnMap(artcc) {
        if (!this.map || !artcc) return;

        const sectors = this.getSectorsForArtcc(artcc);
        const features = [];
        const labelFeatures = [];

        // Track assigned sectors with their position info and strata filter
        const assignedSectors = new Map();
        this._presetPositions.forEach(pos => {
            const sf = pos.strataFilter || { low: true, high: true, superhigh: true };
            (pos.sectors || []).forEach(sectorId => {
                assignedSectors.set(sectorId, { name: pos.name, color: pos.color, strataFilter: sf });
            });
        });

        sectors.forEach(sector => {
            const sectorGeom = this.findSectorGeometry(sector.id);
            if (!sectorGeom) return;

            const assigned = assignedSectors.get(sector.id);

            // Check if sector's strata is enabled for this position
            if (assigned) {
                const sf = assigned.strataFilter;
                const sectorStrata = sectorGeom.boundary_type; // 'low', 'high', or 'superhigh'
                if (sectorStrata && sf[sectorStrata] === false) {
                    // Strata is disabled for this position - show as unassigned
                    features.push({
                        type: 'Feature',
                        properties: { sector_id: sector.id, color: '#444444', selected: false },
                        geometry: sectorGeom.geometry
                    });
                    return;
                }
            }

            const color = assigned ? assigned.color : '#444444';

            features.push({
                type: 'Feature',
                properties: { sector_id: sector.id, color: color, selected: !!assigned },
                geometry: sectorGeom.geometry
            });

            if (sectorGeom.centroid) {
                labelFeatures.push({
                    type: 'Feature',
                    properties: { label: assigned ? assigned.name : sector.name, color: assigned ? assigned.color : '#666666' },
                    geometry: { type: 'Point', coordinates: sectorGeom.centroid }
                });
            }
        });

        if (this.map.getSource('sectors')) {
            this.map.getSource('sectors').setData({ type: 'FeatureCollection', features });
        }
        if (this.map.getSource('sector-labels')) {
            this.map.getSource('sector-labels').setData({ type: 'FeatureCollection', features: labelFeatures });
        }

        const center = this.artccCenters[artcc];
        if (center) this.map.flyTo({ center, zoom: 6 });
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // PRESET POSITION MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════
    
    renderPresetPositionsList() {
        const container = document.getElementById('preset-positions-list');
        if (!container) return;
        
        const positions = this._presetPositions || [];
        
        if (positions.length === 0) {
            container.innerHTML = `
                <div class="empty-state py-4">
                    <div class="empty-state-text">No positions added yet.<br>Click "+ Add Position" to start.</div>
                </div>`;
            return;
        }
        
        container.innerHTML = positions.map((pos, i) => {
            const sectors = pos.sectors || [];
            const isEditing = i === this._editingPresetPositionIndex;
            const sf = pos.strataFilter || { low: true, high: true, superhigh: true };
            return `
                <div class="split-item ${isEditing ? 'active' : ''}" data-index="${i}">
                    <div class="split-item-header">
                        <div class="split-item-name">
                            <input type="color" class="preset-pos-color-picker" value="${pos.color}" data-index="${i}" title="Change color">
                            ${pos.name}
                            ${pos.frequency ? `<small class="text-muted ml-1">${pos.frequency}</small>` : ''}
                        </div>
                        <div class="split-item-actions">
                            <button class="btn btn-outline-info btn-xs edit-preset-pos-btn" title="Edit sectors">✎</button>
                            <button class="btn btn-outline-secondary btn-xs edit-preset-pos-details-btn" title="Edit details">⚙</button>
                            <button class="btn btn-outline-danger btn-xs delete-preset-pos-btn" title="Delete">×</button>
                        </div>
                    </div>
                    <div class="split-item-sectors">${sectors.length} sector${sectors.length !== 1 ? 's' : ''}: ${sectors.slice(0, 6).join(', ')}${sectors.length > 6 ? '...' : ''}</div>
                    <div class="position-strata-toggles mt-1" style="font-size: 11px;">
                        <span class="text-muted mr-1">Strata:</span>
                        <label class="mr-2 mb-0" style="cursor: pointer;">
                            <input type="checkbox" class="pos-strata-toggle" data-index="${i}" data-strata="low" ${sf.low !== false ? 'checked' : ''}>
                            <span style="background:#228B22; width:8px; height:8px; border-radius:50%; display:inline-block;"></span>
                        </label>
                        <label class="mr-2 mb-0" style="cursor: pointer;">
                            <input type="checkbox" class="pos-strata-toggle" data-index="${i}" data-strata="high" ${sf.high !== false ? 'checked' : ''}>
                            <span style="background:#FF6347; width:8px; height:8px; border-radius:50%; display:inline-block;"></span>
                        </label>
                        <label class="mb-0" style="cursor: pointer;">
                            <input type="checkbox" class="pos-strata-toggle" data-index="${i}" data-strata="superhigh" ${sf.superhigh !== false ? 'checked' : ''}>
                            <span style="background:#9932CC; width:8px; height:8px; border-radius:50%; display:inline-block;"></span>
                        </label>
                    </div>
                </div>`;
        }).join('');
        
        // Bind events
        container.querySelectorAll('.edit-preset-pos-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const idx = parseInt(btn.closest('.split-item').dataset.index);
                this.selectPresetPositionForEditing(idx);
            });
        });
        
        container.querySelectorAll('.edit-preset-pos-details-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const idx = parseInt(btn.closest('.split-item').dataset.index);
                this.openPresetPositionModal(idx);
            });
        });
        
        container.querySelectorAll('.delete-preset-pos-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const idx = parseInt(btn.closest('.split-item').dataset.index);
                this.deletePresetPosition(idx);
            });
        });
        
        container.querySelectorAll('.split-item').forEach(item => {
            item.addEventListener('click', () => {
                const idx = parseInt(item.dataset.index);
                this.selectPresetPositionForEditing(idx);
            });
        });

        // Color picker events for preset positions
        container.querySelectorAll('.preset-pos-color-picker').forEach(picker => {
            picker.addEventListener('input', (e) => {
                e.stopPropagation();
                const idx = parseInt(e.target.dataset.index);
                this._presetPositions[idx].color = e.target.value;
                this.loadPresetArtccOnMap(this._presetArtcc); // Update map preview
            });

            picker.addEventListener('change', (e) => {
                e.stopPropagation();
                const idx = parseInt(e.target.dataset.index);
                this.savePresetPositionColor(idx, e.target.value);
            });

            picker.addEventListener('click', (e) => {
                e.stopPropagation(); // Don't select row when clicking color picker
            });
        });

        // Per-position strata toggle events
        container.querySelectorAll('.pos-strata-toggle').forEach(toggle => {
            toggle.addEventListener('change', (e) => {
                e.stopPropagation();
                const idx = parseInt(e.target.dataset.index);
                const strata = e.target.dataset.strata;
                const pos = this._presetPositions[idx];
                if (!pos.strataFilter) {
                    pos.strataFilter = { low: true, high: true, superhigh: true };
                }
                pos.strataFilter[strata] = e.target.checked;
                this.loadPresetArtccOnMap(this._presetArtcc); // Update map preview
            });

            toggle.addEventListener('click', (e) => {
                e.stopPropagation(); // Don't select row when clicking toggle
            });
        });
    },
    
    openPresetPositionModal(editIndex = -1) {
        document.getElementById('preset-position-name').value = '';
        document.getElementById('preset-position-frequency').value = '';
        
        // Populate color picker
        const colorPicker = document.getElementById('preset-position-color-picker');
        if (colorPicker) {
            colorPicker.innerHTML = this.colorPalette.map((color, i) => 
                `<div class="color-swatch ${i === 0 ? 'selected' : ''}" data-color="${color}" style="background:${color}"></div>`
            ).join('');
            colorPicker.querySelectorAll('.color-swatch').forEach(swatch => {
                swatch.addEventListener('click', () => {
                    colorPicker.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('selected'));
                    swatch.classList.add('selected');
                });
            });
        }
        
        this._editingPresetPositionModalIndex = editIndex;
        
        if (editIndex >= 0 && this._presetPositions[editIndex]) {
            const pos = this._presetPositions[editIndex];
            document.getElementById('preset-position-name').value = pos.name || '';
            document.getElementById('preset-position-frequency').value = pos.frequency || '';
            document.getElementById('preset-position-modal-title').textContent = 'Edit Position';
            document.getElementById('save-preset-position-btn').textContent = 'Save Position';
            
            // Select color
            colorPicker?.querySelectorAll('.color-swatch').forEach(s => {
                s.classList.toggle('selected', s.dataset.color === pos.color);
            });
        } else {
            document.getElementById('preset-position-modal-title').textContent = 'Add Position';
            document.getElementById('save-preset-position-btn').textContent = 'Add Position';
        }
        
        $('#preset-position-modal').modal('show');
    },
    
    savePresetPosition() {
        const name = document.getElementById('preset-position-name').value.trim();
        if (!name) return alert('Please enter a position name');
        
        const colorEl = document.querySelector('#preset-position-color-picker .color-swatch.selected');
        const color = colorEl ? colorEl.dataset.color : this.colorPalette[0];
        const frequency = document.getElementById('preset-position-frequency').value.trim() || null;
        
        const posData = { name, color, frequency, sectors: [], filters: null };
        
        if (this._editingPresetPositionModalIndex >= 0) {
            // Preserve existing sectors
            posData.sectors = this._presetPositions[this._editingPresetPositionModalIndex].sectors || [];
            this._presetPositions[this._editingPresetPositionModalIndex] = posData;
        } else {
            this._presetPositions.push(posData);
        }
        
        this.renderPresetPositionsList();
        this.loadPresetArtccOnMap(this._presetArtcc);
        $('#preset-position-modal').modal('hide');
        
        // Auto-select for sector assignment if new
        if (this._editingPresetPositionModalIndex < 0) {
            this.selectPresetPositionForEditing(this._presetPositions.length - 1);
        }
        
        this._editingPresetPositionModalIndex = -1;
    },
    
    deletePresetPosition(index) {
        if (!confirm('Delete this position?')) return;
        this._presetPositions.splice(index, 1);
        if (this._editingPresetPositionIndex === index) {
            this._editingPresetPositionIndex = -1;
            document.getElementById('preset-sector-selection-area').style.display = 'none';
            document.getElementById('preset-no-position-selected').style.display = 'block';
        } else if (this._editingPresetPositionIndex > index) {
            this._editingPresetPositionIndex--;
        }
        this.renderPresetPositionsList();
        this.loadPresetArtccOnMap(this._presetArtcc);
    },

    /**
     * Save just the color for a preset position (quick update from color picker)
     */
    async savePresetPositionColor(index, color) {
        const pos = this._presetPositions[index];
        if (!pos || !pos.id) {
            // Position not yet saved to DB - color will be saved when preset is saved
            console.log('[SPLITS] Position not yet persisted, color will save with preset');
            return;
        }

        try {
            const response = await fetch(`api/splits/presets.php?position_id=${pos.id}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ color: color })
            });

            if (response.ok) {
                console.log(`[SPLITS] Saved color ${color} for preset position ${pos.id}`);
            } else {
                console.warn('[SPLITS] Failed to save preset position color');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to save preset position color:', err);
        }
    },

    // ═══════════════════════════════════════════════════════════════════
    // PRESET SECTOR SELECTION
    // ═══════════════════════════════════════════════════════════════════
    
    selectPresetPositionForEditing(index) {
        this._editingPresetPositionIndex = index;
        const pos = this._presetPositions[index];
        
        document.getElementById('preset-editing-position-name').textContent = pos.name;
        document.getElementById('preset-sector-selection-area').style.display = 'block';
        document.getElementById('preset-no-position-selected').style.display = 'none';
        
        this.loadPresetSectorGrid();
        this.renderPresetPositionsList();
        this.populatePresetAreaQuickSelect();
    },
    
    loadPresetSectorGrid() {
        const container = document.getElementById('preset-sector-grid');
        if (!container || !this._presetArtcc) return;

        // Get strata filter state
        const showLow = document.getElementById('preset-strata-low')?.checked ?? true;
        const showHigh = document.getElementById('preset-strata-high')?.checked ?? true;
        const showSuper = document.getElementById('preset-strata-superhigh')?.checked ?? true;

        let sectors = this.getSectorsForArtcc(this._presetArtcc);

        // Filter by strata
        sectors = sectors.filter(s => {
            if (s.type === 'low' && !showLow) return false;
            if (s.type === 'high' && !showHigh) return false;
            if (s.type === 'superhigh' && !showSuper) return false;
            return true;
        });

        if (sectors.length === 0) {
            container.innerHTML = '<div class="text-muted">No sectors found (check strata filters)</div>';
            return;
        }

        // Get assigned sectors from all positions
        const assignedByOthers = new Set();
        this._presetPositions.forEach((pos, i) => {
            if (i !== this._editingPresetPositionIndex) {
                (pos.sectors || []).forEach(s => assignedByOthers.add(s));
            }
        });
        
        const currentPos = this._presetPositions[this._editingPresetPositionIndex];
        const currentSectors = new Set(currentPos?.sectors || []);
        
        container.innerHTML = sectors.map(s => {
            const isAssigned = assignedByOthers.has(s.id);
            const isSelected = currentSectors.has(s.id);
            const typeColor = s.type === 'superhigh' ? '#9932CC' : (s.type === 'high' ? '#FF6347' : '#228B22');
            let classes = 'sector-chip';
            if (isSelected) classes += ' selected';
            if (isAssigned) classes += ' assigned';
            return `<div class="${classes}" data-sector="${s.id}" data-type="${s.type}" ${isAssigned ? 'title="Assigned to another position"' : ''}>
                <span class="sector-type-dot" style="background:${typeColor}"></span>${s.name}
            </div>`;
        }).join('');
        
        container.querySelectorAll('.sector-chip:not(.assigned)').forEach(chip => {
            chip.addEventListener('click', () => {
                chip.classList.toggle('selected');
            });
        });
    },
    
    populatePresetAreaQuickSelect() {
        const container = document.getElementById('preset-area-groups-container');
        if (!container) return;
        
        const artccAreas = this.areas.filter(a => a.artcc === this._presetArtcc);
        
        if (artccAreas.length === 0) {
            container.innerHTML = '<span class="text-muted" style="font-size: 10px;">No areas defined for this ARTCC</span>';
            return;
        }
        
        container.innerHTML = '<label class="d-block mb-1" style="font-size: 10px; color: #888;">Quick Select Area:</label>';
        artccAreas.forEach(area => {
            const btn = document.createElement('button');
            btn.className = 'btn btn-xs btn-outline-info mr-1 mb-1';
            btn.textContent = area.area_name;
            btn.title = `Select ${area.sectors.length} sectors`;
            btn.addEventListener('click', () => this.selectPresetAreaSectors(area.sectors));
            container.appendChild(btn);
        });
    },
    
    selectPresetAreaSectors(sectorIds) {
        sectorIds.forEach(id => {
            const chip = document.querySelector(`#preset-sector-grid .sector-chip[data-sector="${id}"]:not(.assigned)`);
            if (chip) chip.classList.add('selected');
        });
    },
    
    presetSelectAllSectors() {
        document.querySelectorAll('#preset-sector-grid .sector-chip:not(.assigned)').forEach(c => c.classList.add('selected'));
    },
    
    presetClearAllSectors() {
        document.querySelectorAll('#preset-sector-grid .sector-chip').forEach(c => c.classList.remove('selected'));
    },
    
    applyPresetSectorInput() {
        const input = document.getElementById('preset-sector-input');
        if (!input?.value.trim()) return;
        
        const sectorIds = this.parseSectorInput(input.value.trim(), this._presetArtcc);
        sectorIds.forEach(id => {
            const chip = document.querySelector(`#preset-sector-grid .sector-chip[data-sector="${id}"]:not(.assigned)`);
            if (chip) chip.classList.add('selected');
        });
        input.value = '';
    },
    
    presetDoneSelectingSectors() {
        if (this._editingPresetPositionIndex < 0) return;
        
        const selected = Array.from(document.querySelectorAll('#preset-sector-grid .sector-chip.selected'))
            .map(c => c.dataset.sector);
        
        this._presetPositions[this._editingPresetPositionIndex].sectors = selected;
        this.renderPresetPositionsList();
        this.loadPresetArtccOnMap(this._presetArtcc);
        
        document.getElementById('preset-sector-selection-area').style.display = 'none';
        document.getElementById('preset-no-position-selected').style.display = 'block';
        this._editingPresetPositionIndex = -1;
    },
    
    enablePresetMapSelection() {
        if (this._editingPresetPositionIndex < 0) {
            alert('Please select a position first');
            return;
        }
        
        const position = this._presetPositions[this._editingPresetPositionIndex];
        const artcc = this._presetArtcc;
        
        if (!artcc) {
            alert('Please select an ARTCC first');
            return;
        }
        
        // Track that we're hiding modal for map selection
        this._mapSelectionSourceModal = 'preset';
        
        // Hide modal to see map
        $('#preset-modal').modal('hide');
        
        // Enable map selection mode
        this.mapSelectionMode = 'preset';
        this.mapSelectionColor = position.color || '#4dabf7';
        
        // Show High/Low/Superhigh sectors with outlines only (no fill) for selection
        const sectorLayers = ['high', 'low', 'superhigh'];
        sectorLayers.forEach(layer => {
            const checkbox = document.getElementById(`layer-${layer}`);
            if (checkbox && !checkbox.checked) {
                checkbox.checked = true;
                this.toggleLayerVisibility(layer, true);
            }
            
            // Set to outline-only mode for selection
            const fillBtn = document.querySelector(`.layer-fill-btn[data-layer="${layer}"]`);
            const lineBtn = document.querySelector(`.layer-line-btn[data-layer="${layer}"]`);
            
            // Turn off fill
            if (fillBtn && fillBtn.classList.contains('active')) {
                fillBtn.classList.remove('active');
                this.toggleLayerFill(layer, false);
            }
            
            // Turn on lines
            if (lineBtn && !lineBtn.classList.contains('active')) {
                lineBtn.classList.add('active');
                this.toggleLayerLine(layer, true);
            }
        });
        
        // Zoom to ARTCC
        this.zoomToArtcc(artcc);
        
        // Update selection overlay
        this.updateMapSelectionOverlay();
        
        // Show floating indicator
        this.showPresetMapSelectionIndicator(position);
    },
    
    showPresetMapSelectionIndicator(position) {
        let indicator = document.getElementById('preset-map-selection-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'preset-map-selection-indicator';
            indicator.className = 'area-map-selection-indicator'; // Reuse same styling
            document.body.appendChild(indicator);
        }
        
        // Set border color to match position color
        const color = position.color || '#4dabf7';
        indicator.style.borderColor = color;
        
        const count = position.sectors?.length || 0;
        indicator.innerHTML = `
            <div class="selection-indicator-content">
                <span class="indicator-dot" style="background: ${color}"></span>
                <span class="indicator-text">Selecting sectors for: <strong>${position.name}</strong></span>
                <span class="badge badge-info ml-2">${count} selected</span>
                <button class="btn btn-sm btn-success ml-3" id="finish-preset-selection-btn">
                    <i class="fas fa-check"></i> Done
                </button>
                <button class="btn btn-sm btn-secondary ml-1" id="cancel-preset-selection-btn">
                    Cancel
                </button>
            </div>
        `;
        indicator.style.display = 'block';
        
        document.getElementById('finish-preset-selection-btn')?.addEventListener('click', () => this.finishPresetMapSelection());
        document.getElementById('cancel-preset-selection-btn')?.addEventListener('click', () => this.cancelPresetMapSelection());
    },
    
    updatePresetMapSelectionIndicator() {
        if (this._editingPresetPositionIndex < 0) return;
        const position = this._presetPositions[this._editingPresetPositionIndex];
        const badge = document.querySelector('#preset-map-selection-indicator .badge');
        if (badge) {
            badge.textContent = `${position.sectors?.length || 0} selected`;
        }
    },
    
    finishPresetMapSelection() {
        // Disable map mode
        this.disablePresetMapSelection();
        
        // Re-open modal
        $('#preset-modal').modal('show');
        
        // Refresh the sector grid
        setTimeout(() => {
            this.loadPresetSectorGrid();
            this.renderPresetPositionsList();
        }, 300);
    },
    
    cancelPresetMapSelection() {
        this.disablePresetMapSelection();
        $('#preset-modal').modal('show');
    },
    
    disablePresetMapSelection() {
        if (this.mapSelectionMode === 'preset') {
            this.mapSelectionMode = null;
            this.mapSelectionColor = null;
        }
        this._mapSelectionSourceModal = null;
        
        // Clean up sector selection popup if open
        this.closeSectorSelectionPopup();
        
        // Remove selection overlay
        if (this.map && this.map.getSource('selection-source')) {
            this.map.getSource('selection-source').setData({ type: 'FeatureCollection', features: [] });
        }
        
        // Hide indicator
        const indicator = document.getElementById('preset-map-selection-indicator');
        if (indicator) indicator.style.display = 'none';
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // PRESET SAVE/DELETE
    // ═══════════════════════════════════════════════════════════════════
    
    async savePresetFromModal() {
        const presetName = document.getElementById('preset-name')?.value.trim();
        const artcc = document.getElementById('preset-artcc')?.value;
        const description = document.getElementById('preset-description')?.value.trim();
        
        if (!presetName || !artcc) return alert('Please fill in preset name and ARTCC');
        if (!this._presetPositions || this._presetPositions.length === 0) return alert('Please add at least one position');
        
        const payload = {
            preset_name: presetName,
            artcc: artcc,
            description: description,
            positions: this._presetPositions.map((pos, i) => ({
                position_name: pos.name,
                sectors: pos.sectors || [],
                color: pos.color,
                frequency: pos.frequency || null,
                sort_order: i,
                filters: pos.filters || null
            }))
        };
        
        try {
            const url = this._editingPresetId ? 
                `api/splits/presets.php?id=${this._editingPresetId}` : 
                'api/splits/presets.php';
            const method = this._editingPresetId ? 'PUT' : 'POST';
            
            const response = await fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            
            const result = await response.json();
            
            if (response.ok) {
                $('#preset-modal').modal('hide');
                await this.loadPresets();
                alert(result.message || 'Preset saved successfully!');
            } else {
                alert(result.error || 'Failed to save preset');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to save preset:', err);
            alert('Failed to save preset: ' + err.message);
        }
    },
    
    async deletePreset() {
        if (!this._editingPresetId) return;
        if (!confirm('Delete this preset? This cannot be undone.')) return;
        
        try {
            const response = await fetch(`api/splits/presets.php?id=${this._editingPresetId}`, { method: 'DELETE' });
            const result = await response.json();
            
            if (response.ok) {
                $('#preset-modal').modal('hide');
                await this.loadPresets();
                alert('Preset deleted successfully');
            } else {
                alert(result.error || 'Failed to delete preset');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to delete preset:', err);
            alert('Failed to delete preset: ' + err.message);
        }
    },
    
    /**
     * Save current config as a preset (from config wizard review step)
     */
    async saveCurrentConfigAsPreset() {
        const presetName = document.getElementById('save-preset-name')?.value.trim() ||
            `${this.currentConfig.artcc} Preset - ${new Date().toLocaleDateString()}`;
        
        if (!this.currentConfig.artcc) return alert('Please select an ARTCC first');
        if (this.currentConfig.splits.length === 0) return alert('Please add at least one position');
        
        const payload = {
            preset_name: presetName,
            artcc: this.currentConfig.artcc,
            description: `Created from configuration: ${this.currentConfig.name || 'Untitled'}`,
            positions: this.currentConfig.splits.map((split, i) => ({
                position_name: split.name,
                sectors: split.sectors || [],
                color: split.color,
                frequency: split.frequency || null,
                sort_order: i,
                filters: split.filters || null
            }))
        };
        
        try {
            const response = await fetch('api/splits/presets.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            
            const result = await response.json();
            
            if (response.ok) {
                await this.loadPresets();
                document.getElementById('save-preset-name').value = '';
                alert(result.message || 'Preset saved successfully!');
            } else {
                alert(result.error || 'Failed to save preset');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to save preset:', err);
            alert('Failed to save preset: ' + err.message);
        }
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // CONFIGURATION WIZARD
    // ═══════════════════════════════════════════════════════════════════
    
    openConfigWizard(existingConfig = null) {
        // Reset state
        this.currentConfig = existingConfig || {
            id: null,
            artcc: null,
            name: '',
            startTime: null,
            endTime: null,
            sectorType: 'all',
            splits: []
        };
        this.currentStep = 1;
        this.editingSplitIndex = -1;
        this.isEditingConfig = true;
        
        // Set modal title based on whether we're editing
        const isEditing = existingConfig && existingConfig.id;
        document.getElementById('config-modal-title').textContent = isEditing 
            ? `Edit Configuration: ${existingConfig.name || 'Untitled'}`
            : 'New Configuration';
        
        // Populate form
        document.getElementById('config-artcc').value = this.currentConfig.artcc || '';
        document.getElementById('config-name').value = this.currentConfig.name || '';
        document.getElementById('config-start').value = this.currentConfig.startTime || '';
        document.getElementById('config-end').value = this.currentConfig.endTime || '';
        const sectorTypeEl = document.getElementById('config-sector-type');
        if (sectorTypeEl) sectorTypeEl.value = this.currentConfig.sectorType || 'all';
        
        // Set default times (now + 4 hours)
        if (!this.currentConfig.startTime) {
            const now = new Date();
            const start = new Date(now.getTime());
            const end = new Date(now.getTime() + 4 * 60 * 60 * 1000);
            document.getElementById('config-start').value = this.formatDateTimeLocal(start);
            document.getElementById('config-end').value = this.formatDateTimeLocal(end);
        }
        
        // Populate preset dropdown
        this.populatePresetDropdown(this.currentConfig.artcc);
        
        // Clear save preset name field
        const savePresetNameEl = document.getElementById('save-preset-name');
        if (savePresetNameEl) savePresetNameEl.value = '';
        
        this.updateWizardStep();
        this.renderConfigSplitsList();
        
        // Clear map - show empty sectors layer (will be populated when ARTCC is selected)
        this.clearEditingMapDisplay();
        
        // If editing with an ARTCC already set, load its sectors on the map
        if (this.currentConfig.artcc) {
            this.loadArtccOnMap(this.currentConfig.artcc);
        }
        
        $('#config-modal').modal('show');
    },
    
    clearEditingMapDisplay() {
        // Clear the sectors layer when starting new config
        if (this.map && this.map.getSource('sectors')) {
            this.map.getSource('sectors').setData({ type: 'FeatureCollection', features: [] });
        }
        if (this.map && this.map.getSource('sector-labels')) {
            this.map.getSource('sector-labels').setData({ type: 'FeatureCollection', features: [] });
        }
    },
    
    formatDateTimeLocal(date) {
        return date.toISOString().slice(0, 16);
    },
    
    updateWizardStep() {
        // Update step indicators
        document.querySelectorAll('.step-indicator .step').forEach(el => {
            const step = parseInt(el.dataset.step);
            el.classList.toggle('active', step === this.currentStep);
            el.classList.toggle('completed', step < this.currentStep);
        });
        
        // Show current step content
        document.querySelectorAll('.config-step').forEach((el, i) => {
            el.style.display = (i + 1 === this.currentStep) ? 'block' : 'none';
        });
        
        // Update buttons
        document.getElementById('config-prev-btn').style.display = this.currentStep > 1 ? 'inline-block' : 'none';
        document.getElementById('config-next-btn').style.display = this.currentStep < 3 ? 'inline-block' : 'none';
        document.getElementById('config-save-btn').style.display = this.currentStep === 3 ? 'inline-block' : 'none';
        
        // Update step-specific content
        if (this.currentStep === 2) {
            this.loadSectorGridForConfig();
        } else if (this.currentStep === 3) {
            this.populateReviewStep();
        }
    },
    
    nextStep() {
        // Validate current step
        if (this.currentStep === 1) {
            const artcc = document.getElementById('config-artcc').value;
            const name = document.getElementById('config-name').value.trim();
            const start = document.getElementById('config-start').value;
            const end = document.getElementById('config-end').value;
            
            if (!artcc || !name || !start || !end) {
                alert('Please fill in all required fields');
                return;
            }
            
            this.currentConfig.artcc = artcc;
            this.currentConfig.name = name;
            this.currentConfig.startTime = start;
            this.currentConfig.endTime = end;
            this.currentConfig.sectorType = document.getElementById('config-sector-type').value;
        }
        
        if (this.currentStep === 2 && this.currentConfig.splits.length === 0) {
            alert('Please add at least one split');
            return;
        }
        
        this.currentStep++;
        this.updateWizardStep();
    },
    
    prevStep() {
        if (this.currentStep > 1) {
            this.currentStep--;
            this.updateWizardStep();
        }
    },
    
    loadSectorGridForConfig() {
        const artcc = this.currentConfig.artcc;
        const sectors = this.getSectorsForArtcc(artcc);
        const container = document.getElementById('sector-grid');
        
        if (sectors.length === 0) {
            container.innerHTML = '<div class="text-muted">No sectors found</div>';
            return;
        }
        
        // Group all assigned sectors
        const assignedSectors = new Set();
        this.currentConfig.splits.forEach(split => {
            split.sectors.forEach(s => assignedSectors.add(s));
        });
        
        // Get current split's sectors
        const currentSplitSectors = new Set(
            this.editingSplitIndex >= 0 ? this.currentConfig.splits[this.editingSplitIndex].sectors : []
        );
        
        container.innerHTML = sectors.map(s => {
            const isAssigned = assignedSectors.has(s.id) && !currentSplitSectors.has(s.id);
            const isSelected = currentSplitSectors.has(s.id);
            const typeColor = s.type === 'superhigh' ? '#9932CC' : (s.type === 'high' ? '#FF6347' : '#228B22');
            return `
                <div class="sector-chip ${isSelected ? 'selected' : ''} ${isAssigned ? 'assigned' : ''}" 
                     data-sector="${s.id}" data-type="${s.type}" ${isAssigned ? 'title="Assigned to another split"' : ''}>
                    <span class="sector-type-dot" style="background:${typeColor}"></span>${s.name}
                </div>
            `;
        }).join('');
        
        container.querySelectorAll('.sector-chip:not(.assigned)').forEach(chip => {
            chip.addEventListener('click', () => chip.classList.toggle('selected'));
        });
        
        // Populate area quick-select buttons
        this.populateAreaQuickSelect(artcc);
    },
    
    populateAreaQuickSelect(artcc) {
        const container = document.getElementById('area-groups-container');
        const artccAreas = this.areas.filter(a => a.artcc === artcc);
        
        if (artccAreas.length === 0) {
            container.innerHTML = '<span class="text-muted" style="font-size: 10px;">No areas defined for this ARTCC</span>';
            return;
        }
        
        container.innerHTML = '<label class="d-block mb-1" style="font-size: 10px; color: #888;">Quick Select Area:</label>';
        artccAreas.forEach(area => {
            const btn = document.createElement('button');
            btn.className = 'btn btn-xs btn-outline-info mr-1 mb-1';
            btn.textContent = area.area_name;
            btn.title = `Select ${area.sectors.length} sectors`;
            btn.addEventListener('click', () => this.selectAreaSectors(area.sectors));
            container.appendChild(btn);
        });
    },
    
    selectAreaSectors(sectorIds) {
        sectorIds.forEach(id => {
            const chip = document.querySelector(`#sector-grid .sector-chip[data-sector="${id}"]:not(.assigned)`);
            if (chip) chip.classList.add('selected');
        });
    },
    
    selectAllSectors() {
        document.querySelectorAll('#sector-grid .sector-chip:not(.assigned)').forEach(c => c.classList.add('selected'));
    },
    
    clearAllSectors() {
        document.querySelectorAll('#sector-grid .sector-chip').forEach(c => c.classList.remove('selected'));
    },
    
    /**
     * Apply sector input from the text field
     * Supports formats:
     *   - Full IDs: ZDC50,ZDC51,ZDC53,ZDC54
     *   - Numbers only: 50,51,52,53,54 (auto-prefixes with current ARTCC)
     *   - Mixed: ZDC50,51,ZDC52 (numbers without prefix use current ARTCC)
     */
    applySectorInput() {
        const input = document.getElementById('sector-input');
        if (!input) return;
        
        const value = input.value.trim();
        if (!value) return;
        
        const artcc = this.currentConfig.artcc;
        const sectorIds = this.parseSectorInput(value, artcc);
        
        if (sectorIds.length === 0) {
            console.log('[SPLITS] No valid sectors parsed from input');
            return;
        }
        
        // Select matching sectors in the grid
        let selectedCount = 0;
        sectorIds.forEach(id => {
            const chip = document.querySelector(`#sector-grid .sector-chip[data-sector="${id}"]:not(.assigned)`);
            if (chip) {
                chip.classList.add('selected');
                selectedCount++;
            }
        });
        
        console.log(`[SPLITS] Sector input applied: ${selectedCount}/${sectorIds.length} sectors selected`);
        
        // Clear the input after applying
        input.value = '';
    },
    
    /**
     * Parse sector input string into array of sector IDs
     * @param {string} input - Comma-separated sector identifiers
     * @param {string} defaultArtcc - Default ARTCC prefix for numbers-only entries
     * @returns {string[]} - Array of full sector IDs (e.g., ['ZDC50', 'ZDC51'])
     */
    parseSectorInput(input, defaultArtcc) {
        if (!input) return [];
        
        const parts = input.split(/[,\s]+/).filter(p => p.trim());
        const sectorIds = [];
        
        parts.forEach(part => {
            part = part.trim().toUpperCase();
            if (!part) return;
            
            // Check if it's a full sector ID (starts with letters, e.g., ZDC50)
            if (/^[A-Z]{3}\d+$/.test(part)) {
                // Full sector ID
                sectorIds.push(part);
            } else if (/^\d+$/.test(part)) {
                // Numbers only - prefix with default ARTCC
                if (defaultArtcc) {
                    sectorIds.push(defaultArtcc.toUpperCase() + part);
                }
            } else if (/^[A-Z]{3}$/.test(part)) {
                // Just an ARTCC - skip (can't select without sector number)
                console.log(`[SPLITS] Skipping incomplete sector ID: ${part}`);
            } else {
                // Try to handle other formats (e.g., ZDC-50 or ZDC_50)
                const match = part.match(/^([A-Z]{3})[\-_]?(\d+)$/);
                if (match) {
                    sectorIds.push(match[1] + match[2]);
                }
            }
        });
        
        return sectorIds;
    },
    
    doneSelectingSectors() {
        if (this.editingSplitIndex < 0) return;
        
        const selected = Array.from(document.querySelectorAll('#sector-grid .sector-chip.selected'))
            .map(c => c.dataset.sector);
        
        this.currentConfig.splits[this.editingSplitIndex].sectors = selected;
        this.renderConfigSplitsList();
        
        document.getElementById('sector-selection-area').style.display = 'none';
        document.getElementById('no-split-selected').style.display = 'block';
        this.editingSplitIndex = -1;
        
        // Disable map selection mode
        this.disableMapSectorSelection();
        
        // Update map preview
        this.updateMapPreview();
        
        // Update map preview to show colored sectors
        this.updateMapPreview();
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // SPLIT MANAGEMENT (within config wizard)
    // ═══════════════════════════════════════════════════════════════════
    
    saveSplit() {
        const name = document.getElementById('split-name').value.trim();
        if (!name) {
            alert('Please enter a position name');
            return;
        }
        
        const colorEl = document.querySelector('#split-color-picker .color-swatch.selected');
        const color = colorEl ? colorEl.dataset.color : this.colorPalette[0];
        
        // Gather all position details
        const positionData = {
            name: name,
            color: color,
            sectors: [],
            startTime: document.getElementById('split-start').value || null,
            endTime: document.getElementById('split-end').value || null,
            frequency: document.getElementById('split-frequency').value.trim() || null,
            controllerOI: document.getElementById('split-oi').value.trim().toUpperCase() || null,
            filters: {
                route: {
                    orig: document.getElementById('filter-orig').value.trim() || null,
                    dest: document.getElementById('filter-dest').value.trim() || null,
                    fix: document.getElementById('filter-fix').value.trim() || null,
                    gate: document.getElementById('filter-gate').value.trim() || null,
                    other: document.getElementById('filter-route-other').value.trim() || null
                },
                altitude: {
                    floor: document.getElementById('filter-floor').value.trim() || null,
                    ceiling: document.getElementById('filter-ceiling').value.trim() || null,
                    block: document.getElementById('filter-block').value.trim() || null
                },
                aircraft: {
                    type: document.getElementById('filter-acft-type').value || null,
                    speed: document.getElementById('filter-speed').value.trim() || null,
                    rvsm: document.getElementById('filter-rvsm').value || null,
                    navEquip: document.getElementById('filter-nav-equip').value.trim() || null,
                    other: document.getElementById('filter-acft-other').value.trim() || null
                },
                other: document.getElementById('filter-other').value.trim() || null
            }
        };
        
        // If editing existing split, update it; otherwise add new
        if (this._editingSplitModalIndex !== undefined && this._editingSplitModalIndex >= 0) {
            // Preserve existing sectors
            positionData.sectors = this.currentConfig.splits[this._editingSplitModalIndex].sectors || [];
            this.currentConfig.splits[this._editingSplitModalIndex] = positionData;
        } else {
            this.currentConfig.splits.push(positionData);
        }
        
        this.renderConfigSplitsList();
        $('#split-modal').modal('hide');
        
        // Auto-select for sector assignment if new split
        if (this._editingSplitModalIndex === undefined || this._editingSplitModalIndex < 0) {
            this.selectSplitForEditing(this.currentConfig.splits.length - 1);
        } else {
            this.selectSplitForEditing(this._editingSplitModalIndex);
        }
        
        // Reset editing index
        this._editingSplitModalIndex = -1;
    },
    
    openSplitModal(editIndex = -1) {
        // Clear form
        document.getElementById('split-name').value = '';
        document.getElementById('split-frequency').value = '';
        document.getElementById('split-oi').value = '';
        document.getElementById('split-start').value = '';
        document.getElementById('split-end').value = '';
        document.getElementById('filter-orig').value = '';
        document.getElementById('filter-dest').value = '';
        document.getElementById('filter-fix').value = '';
        document.getElementById('filter-gate').value = '';
        document.getElementById('filter-route-other').value = '';
        document.getElementById('filter-floor').value = '';
        document.getElementById('filter-ceiling').value = '';
        document.getElementById('filter-block').value = '';
        document.getElementById('filter-acft-type').value = '';
        document.getElementById('filter-speed').value = '';
        document.getElementById('filter-rvsm').value = '';
        document.getElementById('filter-nav-equip').value = '';
        document.getElementById('filter-acft-other').value = '';
        document.getElementById('filter-other').value = '';
        
        // Collapse all filter sections
        ['route-filters', 'altitude-filters', 'aircraft-filters', 'other-filters'].forEach(id => {
            const el = document.getElementById(id);
            if (el) $(el).collapse('hide');
        });
        
        // Reset color picker
        this.populateSplitColorPicker();
        
        this._editingSplitModalIndex = editIndex;
        
        if (editIndex >= 0 && this.currentConfig.splits[editIndex]) {
            // Editing existing split
            const split = this.currentConfig.splits[editIndex];
            
            document.getElementById('split-modal-title').textContent = 'Edit Position';
            document.getElementById('save-split-btn').textContent = 'Save Position';
            
            document.getElementById('split-name').value = split.name || '';
            document.getElementById('split-frequency').value = split.frequency || '';
            document.getElementById('split-oi').value = split.controllerOI || '';
            document.getElementById('split-start').value = split.startTime || '';
            document.getElementById('split-end').value = split.endTime || '';
            
            // Populate filters if they exist
            if (split.filters) {
                const f = split.filters;
                if (f.route) {
                    document.getElementById('filter-orig').value = f.route.orig || '';
                    document.getElementById('filter-dest').value = f.route.dest || '';
                    document.getElementById('filter-fix').value = f.route.fix || '';
                    document.getElementById('filter-gate').value = f.route.gate || '';
                    document.getElementById('filter-route-other').value = f.route.other || '';
                }
                if (f.altitude) {
                    document.getElementById('filter-floor').value = f.altitude.floor || '';
                    document.getElementById('filter-ceiling').value = f.altitude.ceiling || '';
                    document.getElementById('filter-block').value = f.altitude.block || '';
                }
                if (f.aircraft) {
                    document.getElementById('filter-acft-type').value = f.aircraft.type || '';
                    document.getElementById('filter-speed').value = f.aircraft.speed || '';
                    document.getElementById('filter-rvsm').value = f.aircraft.rvsm || '';
                    document.getElementById('filter-nav-equip').value = f.aircraft.navEquip || '';
                    document.getElementById('filter-acft-other').value = f.aircraft.other || '';
                }
                document.getElementById('filter-other').value = f.other || '';
            }
            
            // Select the color
            document.querySelectorAll('#split-color-picker .color-swatch').forEach(s => {
                s.classList.toggle('selected', s.dataset.color === split.color);
            });
        } else {
            // New split
            document.getElementById('split-modal-title').textContent = 'Add New Position';
            document.getElementById('save-split-btn').textContent = 'Add Position';
        }
        
        $('#split-modal').modal('show');
    },
    
    renderConfigSplitsList() {
        const container = document.getElementById('config-splits-list');
        
        if (this.currentConfig.splits.length === 0) {
            container.innerHTML = '<div class="empty-state py-4"><div class="empty-state-text">No positions added yet.</div></div>';
            return;
        }
        
        container.innerHTML = this.currentConfig.splits.map((split, i) => {
            let extraInfo = [];
            if (split.frequency) extraInfo.push(split.frequency);
            if (split.controllerOI) extraInfo.push(split.controllerOI);
            const extraStr = extraInfo.length > 0 ? ` • ${extraInfo.join(' • ')}` : '';
            
            return `
            <div class="split-item ${this.editingSplitIndex === i ? 'editing' : ''}" data-index="${i}">
                <div class="split-item-color" style="background: ${split.color}"></div>
                <div class="split-item-info">
                    <div class="split-item-name">${split.name}${extraStr}</div>
                    <div class="split-item-sectors">${split.sectors.length} sectors</div>
                </div>
                <div class="split-item-actions">
                    <button class="btn btn-xs btn-outline-info edit-split-details-btn" title="Edit Details">✎</button>
                    <button class="btn btn-xs btn-outline-primary select-sectors-btn" title="Select Sectors">◉</button>
                    <button class="btn btn-xs btn-outline-danger remove-split-btn" title="Remove">×</button>
                </div>
            </div>
        `}).join('');
        
        // Bind events
        container.querySelectorAll('.split-item').forEach(item => {
            const index = parseInt(item.dataset.index);
            
            // Edit details opens the modal
            item.querySelector('.edit-split-details-btn').addEventListener('click', (e) => {
                e.stopPropagation();
                this.openSplitModal(index);
            });
            
            // Select sectors button
            item.querySelector('.select-sectors-btn').addEventListener('click', (e) => {
                e.stopPropagation();
                this.selectSplitForEditing(index);
            });
            
            // Remove button
            item.querySelector('.remove-split-btn').addEventListener('click', (e) => {
                e.stopPropagation();
                this.removeSplit(index);
            });
            
            // Click on item selects for sector editing
            item.addEventListener('click', () => this.selectSplitForEditing(index));
        });
    },
    
    selectSplitForEditing(index) {
        this.editingSplitIndex = index;
        const split = this.currentConfig.splits[index];
        
        document.getElementById('editing-split-name').textContent = split.name;
        document.getElementById('sector-selection-area').style.display = 'block';
        document.getElementById('no-split-selected').style.display = 'none';
        
        this.loadSectorGridForConfig();
        this.renderConfigSplitsList();
        
        // Enable map selection mode
        this.enableMapSectorSelection(split.color);
    },
    
    enableMapSectorSelection(highlightColor) {
        // Show High/Low/Superhigh sectors if not already visible
        const showHigh = document.getElementById('layer-high');
        const showLow = document.getElementById('layer-low');
        const showSuperhigh = document.getElementById('layer-superhigh');
        
        // Turn on all sector layers for selection
        if (showHigh && !showHigh.checked) {
            showHigh.checked = true;
            this.toggleLayerVisibility('high', true);
        }
        if (showLow && !showLow.checked) {
            showLow.checked = true;
            this.toggleLayerVisibility('low', true);
        }
        if (showSuperhigh && !showSuperhigh.checked) {
            showSuperhigh.checked = true;
            this.toggleLayerVisibility('superhigh', true);
        }
        
        // Store state for map click handling
        this.mapSelectionMode = 'split';
        this.mapSelectionColor = highlightColor;
        
        // Update map with selection overlay
        this.updateMapSelectionOverlay();
        
        // Show selection mode indicator
        this.showMapSelectionIndicator('Click sectors on the map to select/deselect', highlightColor);
    },
    
    enableSplitMapSelection() {
        if (this.editingSplitIndex < 0) {
            alert('Please select a position first');
            return;
        }
        
        const split = this.currentConfig.splits[this.editingSplitIndex];
        const artcc = this.currentConfig.artcc;
        
        // Track that we're hiding modal for map selection
        this._mapSelectionSourceModal = 'config';
        
        // Hide modal to see map
        $('#config-modal').modal('hide');
        
        // Enable map selection
        this.mapSelectionMode = 'split';
        this.mapSelectionColor = split.color;
        
        // Show High/Low/Superhigh sectors with outlines only (no fill) for selection
        const sectorLayers = ['high', 'low', 'superhigh'];
        sectorLayers.forEach(layer => {
            const checkbox = document.getElementById(`layer-${layer}`);
            if (checkbox && !checkbox.checked) {
                checkbox.checked = true;
                this.toggleLayerVisibility(layer, true);
            }
            
            // Set to outline-only mode for selection
            const fillBtn = document.querySelector(`.layer-fill-btn[data-layer="${layer}"]`);
            const lineBtn = document.querySelector(`.layer-line-btn[data-layer="${layer}"]`);
            
            // Turn off fill
            if (fillBtn && fillBtn.classList.contains('active')) {
                fillBtn.classList.remove('active');
                this.toggleLayerFill(layer, false);
            }
            
            // Turn on lines
            if (lineBtn && !lineBtn.classList.contains('active')) {
                lineBtn.classList.add('active');
                this.toggleLayerLine(layer, true);
            }
        });
        
        // Zoom to ARTCC
        if (artcc) {
            this.zoomToArtcc(artcc);
        }
        
        // Update overlay
        this.updateMapSelectionOverlay();
        
        // Show floating indicator with done/cancel buttons
        this.showSplitMapSelectionIndicator(split);
    },
    
    showSplitMapSelectionIndicator(split) {
        let indicator = document.getElementById('split-map-selection-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'split-map-selection-indicator';
            indicator.className = 'area-map-selection-indicator'; // Reuse same styling
            document.body.appendChild(indicator);
        }
        
        // Set border color to match split color
        indicator.style.borderColor = split.color;
        
        const count = split.sectors?.length || 0;
        indicator.innerHTML = `
            <div class="selection-indicator-content">
                <span class="indicator-dot" style="background: ${split.color}"></span>
                <span class="indicator-text">Selecting sectors for: <strong>${split.name}</strong></span>
                <span class="badge badge-info ml-2">${count} selected</span>
                <button class="btn btn-sm btn-success ml-3" id="finish-split-selection-btn">
                    <i class="fas fa-check"></i> Done
                </button>
                <button class="btn btn-sm btn-secondary ml-1" id="cancel-split-selection-btn">
                    Cancel
                </button>
            </div>
        `;
        indicator.style.display = 'block';
        
        document.getElementById('finish-split-selection-btn')?.addEventListener('click', () => this.finishSplitMapSelection());
        document.getElementById('cancel-split-selection-btn')?.addEventListener('click', () => this.cancelSplitMapSelection());
    },
    
    updateSplitMapSelectionIndicator() {
        if (this.editingSplitIndex < 0) return;
        const split = this.currentConfig.splits[this.editingSplitIndex];
        const badge = document.querySelector('#split-map-selection-indicator .badge');
        if (badge) {
            badge.textContent = `${split.sectors?.length || 0} selected`;
        }
    },
    
    finishSplitMapSelection() {
        // Disable map mode
        this.disableSplitMapSelection();
        
        // Re-open modal
        $('#config-modal').modal('show');
        
        // Refresh the sector grid
        setTimeout(() => {
            this.loadSectorGridForConfig();
            this.renderConfigSplitsList();
        }, 300);
    },
    
    cancelSplitMapSelection() {
        this.disableSplitMapSelection();
        $('#config-modal').modal('show');
    },
    
    disableSplitMapSelection() {
        if (this.mapSelectionMode === 'split') {
            this.mapSelectionMode = null;
            this.mapSelectionColor = null;
        }
        this._mapSelectionSourceModal = null;
        
        // Clean up sector selection popup if open
        this.closeSectorSelectionPopup();
        
        // Remove selection overlay
        if (this.map && this.map.getSource('selection-source')) {
            this.map.getSource('selection-source').setData({ type: 'FeatureCollection', features: [] });
        }
        
        // Hide indicator
        const indicator = document.getElementById('split-map-selection-indicator');
        if (indicator) indicator.style.display = 'none';
    },
    
    disableMapSectorSelection() {
        this.mapSelectionMode = null;
        this.mapSelectionColor = null;
        this._mapSelectionSourceModal = null;
        
        // Clean up sector selection popup if open
        this.closeSectorSelectionPopup();
        
        // Remove selection overlay
        if (this.map && this.map.getSource('selection-source')) {
            this.map.getSource('selection-source').setData({ type: 'FeatureCollection', features: [] });
        }
        
        // Hide indicator
        this.hideMapSelectionIndicator();
    },
    
    updateMapSelectionOverlay() {
        if (!this.map || !this.mapSelectionMode) return;
        
        // Create or update selection highlight source
        if (!this.map.getSource('selection-source')) {
            this.map.addSource('selection-source', {
                type: 'geojson',
                data: { type: 'FeatureCollection', features: [] }
            });
            
            this.map.addLayer({
                id: 'selection-highlight',
                type: 'fill',
                source: 'selection-source',
                paint: {
                    'fill-color': ['get', 'color'],
                    'fill-opacity': 0.5
                }
            });
            
            this.map.addLayer({
                id: 'selection-outline',
                type: 'line',
                source: 'selection-source',
                paint: {
                    'line-color': ['get', 'color'],
                    'line-width': 3,
                    'line-opacity': 1
                }
            });
        }
        
        // Get selected sectors
        let selectedSectors = [];
        if (this.mapSelectionMode === 'split' && this.editingSplitIndex >= 0) {
            selectedSectors = this.currentConfig.splits[this.editingSplitIndex].sectors || [];
        } else if (this.mapSelectionMode === 'area') {
            selectedSectors = this._areaSelectedSectors || [];
        } else if (this.mapSelectionMode === 'preset' && this._editingPresetPositionIndex >= 0) {
            selectedSectors = this._presetPositions[this._editingPresetPositionIndex]?.sectors || [];
        }
        
        // Build features for selected sectors
        const features = [];
        const color = this.mapSelectionColor || '#00ff00';
        
        selectedSectors.forEach(sectorId => {
            const geom = this.findSectorGeometry(sectorId);
            if (geom) {
                features.push({
                    type: 'Feature',
                    properties: { sector_id: sectorId, color: color },
                    geometry: geom.geometry
                });
            }
        });
        
        this.map.getSource('selection-source').setData({
            type: 'FeatureCollection',
            features: features
        });
    },
    
    showMapSelectionIndicator(message, color) {
        let indicator = document.getElementById('map-selection-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'map-selection-indicator';
            indicator.className = 'map-selection-indicator';
            document.getElementById('config-map')?.appendChild(indicator);
        }
        indicator.innerHTML = `
            <span class="indicator-dot" style="background: ${color}"></span>
            <span class="indicator-text">${message}</span>
        `;
        indicator.style.display = 'flex';
    },
    
    hideMapSelectionIndicator() {
        const indicator = document.getElementById('map-selection-indicator');
        if (indicator) indicator.style.display = 'none';
    },
    
    handleMapSectorClick(sectorId, layerType) {
        if (!this.mapSelectionMode) return false;
        
        if (this.mapSelectionMode === 'split' && this.editingSplitIndex >= 0) {
            // Toggle sector in current split
            const split = this.currentConfig.splits[this.editingSplitIndex];
            const idx = split.sectors.indexOf(sectorId);
            
            // Check if assigned to another split
            let assignedElsewhere = false;
            this.currentConfig.splits.forEach((s, i) => {
                if (i !== this.editingSplitIndex && s.sectors.includes(sectorId)) {
                    assignedElsewhere = true;
                }
            });
            
            if (assignedElsewhere) {
                // Flash warning on the floating indicator
                const indicator = document.getElementById('split-map-selection-indicator');
                if (indicator) {
                    const textEl = indicator.querySelector('.indicator-text');
                    if (textEl) {
                        const original = textEl.innerHTML;
                        textEl.innerHTML = '⚠️ <span style="color:#ff6b6b">Sector assigned to another position!</span>';
                        setTimeout(() => { textEl.innerHTML = original; }, 1500);
                    }
                }
                return true;
            }
            
            if (idx >= 0) {
                split.sectors.splice(idx, 1);
            } else {
                split.sectors.push(sectorId);
            }
            
            // Update UI
            this.updateMapSelectionOverlay();
            this.updateSplitMapSelectionIndicator();
            
            // Only update grid if modal is visible
            if ($('#config-modal').hasClass('show')) {
                this.loadSectorGridForConfig();
                this.renderConfigSplitsList();
            }
            return true;
        }
        
        if (this.mapSelectionMode === 'area') {
            // Toggle sector in area selection
            const idx = this._areaSelectedSectors.indexOf(sectorId);
            if (idx >= 0) {
                this._areaSelectedSectors.splice(idx, 1);
            } else {
                this._areaSelectedSectors.push(sectorId);
            }
            
            // Update UI
            this.updateAreaSectorGrid();
            this.updateMapSelectionOverlay();
            return true;
        }
        
        if (this.mapSelectionMode === 'preset' && this._editingPresetPositionIndex >= 0) {
            // Toggle sector in current preset position
            const position = this._presetPositions[this._editingPresetPositionIndex];
            if (!position.sectors) position.sectors = [];
            
            const idx = position.sectors.indexOf(sectorId);
            
            // Check if assigned to another position
            let assignedElsewhere = false;
            this._presetPositions.forEach((p, i) => {
                if (i !== this._editingPresetPositionIndex && (p.sectors || []).includes(sectorId)) {
                    assignedElsewhere = true;
                }
            });
            
            if (assignedElsewhere) {
                // Flash warning on the floating indicator
                const indicator = document.getElementById('preset-map-selection-indicator');
                if (indicator) {
                    const textEl = indicator.querySelector('.indicator-text');
                    if (textEl) {
                        const original = textEl.innerHTML;
                        textEl.innerHTML = '⚠️ <span style="color:#ff6b6b">Sector assigned to another position!</span>';
                        setTimeout(() => { textEl.innerHTML = original; }, 1500);
                    }
                }
                return true;
            }
            
            if (idx >= 0) {
                position.sectors.splice(idx, 1);
            } else {
                position.sectors.push(sectorId);
            }
            
            // Update UI
            this.updateMapSelectionOverlay();
            this.updatePresetMapSelectionIndicator();
            return true;
        }
        
        return false;
    },
    
    removeSplit(index) {
        this.currentConfig.splits.splice(index, 1);
        if (this.editingSplitIndex === index) {
            this.editingSplitIndex = -1;
            document.getElementById('sector-selection-area').style.display = 'none';
            document.getElementById('no-split-selected').style.display = 'block';
        } else if (this.editingSplitIndex > index) {
            this.editingSplitIndex--;
        }
        this.renderConfigSplitsList();
        // Update map preview
        this.updateMapPreview();
    },
    
    populateReviewStep() {
        document.getElementById('review-config-name').textContent = this.currentConfig.name;
        document.getElementById('review-artcc').textContent = this.currentConfig.artcc;
        document.getElementById('review-start').textContent = this.formatDisplayTime(this.currentConfig.startTime);
        document.getElementById('review-end').textContent = this.formatDisplayTime(this.currentConfig.endTime);
        
        const container = document.getElementById('review-splits-list');
        container.innerHTML = this.currentConfig.splits.map(split => `
            <div class="review-split-item">
                <span class="review-split-color" style="background: ${split.color}"></span>
                <strong>${split.name}</strong>
                <span class="text-muted ml-2">${split.sectors.length} sectors: ${split.sectors.slice(0, 5).join(', ')}${split.sectors.length > 5 ? '...' : ''}</span>
            </div>
        `).join('');
    },
    
    formatDisplayTime(isoString) {
        if (!isoString) return '-';
        const d = new Date(isoString);
        return d.toUTCString().replace('GMT', 'Z');
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // SAVE & LOAD CONFIGURATIONS
    // ═══════════════════════════════════════════════════════════════════
    
    async saveConfig() {
        const publishImmediately = document.getElementById('publish-immediately')?.checked;
        
        // Determine status based on publish checkbox and start time
        let status;
        const hasFutureStartTime = this.currentConfig.startTime && 
            new Date(this.currentConfig.startTime) > new Date();
        
        if (publishImmediately) {
            status = 'active';
        } else if (hasFutureStartTime) {
            status = 'scheduled';
        } else if (this.currentConfig.id) {
            status = this.currentConfig.status || 'draft';
        } else {
            status = 'draft';
        }
        
        const payload = {
            artcc: this.currentConfig.artcc,
            config_name: this.currentConfig.name,
            start_time_utc: this.currentConfig.startTime,
            end_time_utc: this.currentConfig.endTime,
            status: status,
            positions: this.currentConfig.splits.map((split, i) => ({
                position_name: split.name,
                sectors: split.sectors,
                color: split.color,
                start_time_utc: split.startTime,
                end_time_utc: split.endTime,
                sort_order: i,
                frequency: split.frequency || null,
                controller_oi: split.controllerOI || null,
                filters: split.filters || null
            }))
        };
        
        try {
            let response;
            const isEdit = !!this.currentConfig.id;
            
            if (isEdit) {
                response = await fetch(`api/splits/configs.php?id=${this.currentConfig.id}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            } else {
                response = await fetch('api/splits/configs.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            }
            
            const result = await response.json();
            
            if (response.ok) {
                $('#config-modal').modal('hide');
                
                // Reset modal title
                document.getElementById('config-modal-title').textContent = 'New Configuration';
                
                // Refresh all lists
                await this.loadMyConfigs();
                await this.loadActiveConfigs();
                await this.loadScheduledConfigs();
                
                alert(result.message || 'Configuration saved successfully');
            } else {
                alert(result.error || 'Failed to save configuration');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to save config:', err);
            alert('Failed to save configuration: ' + err.message);
        }
    },
    
    async loadMyConfigs() {
        // TODO: Add user ID filtering when auth is available
        try {
            const response = await fetch('api/splits/configs.php');
            if (response.ok) {
                const data = await response.json();
                this.myConfigs = data.configs || [];
                this.renderMyConfigsList();
            }
        } catch (err) {
            console.warn('[SPLITS] Failed to load configs:', err);
        }
    },
    
    renderMyConfigsList() {
        const container = document.getElementById('my-configs-list');
        if (!container) return;
        
        if (this.myConfigs.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">📋</div>
                    <div class="empty-state-text">No configurations yet.<br>Click "+ New Config" to create one.</div>
                </div>
            `;
            return;
        }
        
        container.innerHTML = this.myConfigs.map(config => {
            const posCount = config.position_count || 0;
            return `
            <div class="config-list-item" data-config-id="${config.id}" data-artcc="${config.artcc}">
                <div class="config-list-info">
                    <div class="config-list-name">${config.config_name}</div>
                    <div class="config-list-meta">${config.artcc} • ${posCount} position${posCount !== 1 ? 's' : ''}</div>
                </div>
                <div class="config-list-status status-${config.status}">${config.status}</div>
                <div class="config-list-actions">
                    <button class="btn btn-xs btn-outline-info edit-config-btn" title="Edit">✎</button>
                    <button class="btn btn-xs btn-outline-danger delete-config-btn" title="Delete">×</button>
                </div>
            </div>
        `}).join('');
        
        // Bind edit events
        container.querySelectorAll('.edit-config-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const item = btn.closest('.config-list-item');
                const configId = parseInt(item.dataset.configId);
                await this.editConfig(configId);
            });
        });
        
        // Bind delete events
        container.querySelectorAll('.delete-config-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const item = btn.closest('.config-list-item');
                const configId = parseInt(item.dataset.configId);
                await this.deleteConfig(configId);
            });
        });
        
        // Click item to view on map
        container.querySelectorAll('.config-list-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (e.target.closest('.config-list-actions')) return;
                const artcc = item.dataset.artcc;
                if (artcc && this.artccCenters[artcc]) {
                    this.map.flyTo({ center: this.artccCenters[artcc], zoom: 6 });
                }
            });
        });
    },
    
    async deleteConfig(configId) {
        if (!confirm('Delete this configuration? This cannot be undone.')) return;
        
        try {
            const response = await fetch(`api/splits/configs.php?id=${configId}`, {
                method: 'DELETE'
            });
            
            const result = await response.json();
            
            if (response.ok) {
                // Refresh all lists
                await this.loadMyConfigs();
                await this.loadActiveConfigs();
                await this.loadScheduledConfigs();
            } else {
                alert(result.error || 'Failed to delete config');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to delete config:', err);
            alert('Failed to delete config: ' + err.message);
        }
    },
    
    async loadActiveConfigs() {
        try {
            // Clear existing datablocks when reloading
            this.clearAllDatablocks();
            
            const response = await fetch('api/splits/active.php');
            if (response.ok) {
                const data = await response.json();
                this.activeConfigs = data.configs || [];
                console.log('[SPLITS] Active configs loaded:', this.activeConfigs);
                
                // Debug: show all sectors in active configs
                this.activeConfigs.forEach(config => {
                    console.log(`[SPLITS] Config "${config.config_name}" (${config.artcc}):`, config.positions);
                });
                
                this.renderActiveConfigsList();  // Sidebar editable list
                this.renderActiveConfigsPanel(); // Floating panel summary
                this.updateMapWithActiveConfigs();
            } else {
                console.warn('[SPLITS] Active configs response not OK:', response.status);
            }
        } catch (err) {
            console.warn('[SPLITS] Failed to load active configs:', err);
        }
    },
    
    /**
     * Load scheduled (upcoming) configurations
     */
    async loadScheduledConfigs() {
        try {
            const response = await fetch('api/splits/scheduled.php');
            if (response.ok) {
                const data = await response.json();
                this.scheduledConfigs = data.configs || [];
                console.log('[SPLITS] Scheduled configs loaded:', this.scheduledConfigs);
                this.renderScheduledConfigsList();
            } else {
                console.warn('[SPLITS] Scheduled configs response not OK:', response.status);
            }
        } catch (err) {
            console.warn('[SPLITS] Failed to load scheduled configs:', err);
        }
    },
    
    /**
     * Render the scheduled configs list in the sidebar
     */
    renderScheduledConfigsList() {
        const container = document.getElementById('scheduled-configs-list');
        
        if (!container) return;
        
        const totalConfigs = this.scheduledConfigs.length;
        
        if (totalConfigs === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">📅</div>
                    <div class="empty-state-text">No scheduled configurations.<br>Create a config with a future start time.</div>
                </div>
            `;
            return;
        }
        
        let html = '';
        this.scheduledConfigs.forEach(config => {
            const positions = config.positions || [];
            const startTime = config.start_time_utc ? new Date(config.start_time_utc + 'Z') : null;
            const endTime = config.end_time_utc ? new Date(config.end_time_utc + 'Z') : null;
            
            // Calculate countdown
            let countdownText = '';
            if (startTime) {
                const now = new Date();
                const diffMs = startTime - now;
                if (diffMs > 0) {
                    const diffHrs = Math.floor(diffMs / (1000 * 60 * 60));
                    const diffMins = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
                    if (diffHrs > 24) {
                        const days = Math.floor(diffHrs / 24);
                        countdownText = `in ${days}d ${diffHrs % 24}h`;
                    } else if (diffHrs > 0) {
                        countdownText = `in ${diffHrs}h ${diffMins}m`;
                    } else {
                        countdownText = `in ${diffMins}m`;
                    }
                } else {
                    countdownText = 'Starting soon';
                }
            }
            
            // Format times for display
            const startDisplay = startTime ? this.formatUTCTime(startTime) : 'Not set';
            const endDisplay = endTime ? this.formatUTCTime(endTime) : 'Not set';
            
            html += `
                <div class="scheduled-config-item" data-config-id="${config.id}" data-artcc="${config.artcc}">
                    <div class="scheduled-config-header">
                        <div class="scheduled-config-info">
                            <div class="scheduled-config-name">${this.escapeHtml(config.config_name)}</div>
                            <div class="scheduled-config-artcc">${config.artcc}</div>
                        </div>
                        <div class="scheduled-config-actions">
                            <button class="btn btn-xs btn-outline-info edit-scheduled-btn" title="Edit"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-xs btn-outline-success activate-scheduled-btn" title="Activate Now"><i class="fas fa-play"></i></button>
                            <button class="btn btn-xs btn-outline-danger delete-scheduled-btn" title="Delete"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                    <div class="scheduled-config-timing">
                        <span class="scheduled-time start-time" title="Start Time UTC">▶ ${startDisplay}</span>
                        ${endTime ? `<span class="scheduled-time end-time" title="End Time UTC">■ ${endDisplay}</span>` : ''}
                        ${countdownText ? `<span class="scheduled-time countdown" title="Time until start">⏱ ${countdownText}</span>` : ''}
                    </div>
                    <div class="scheduled-config-positions">
                        <span class="scheduled-position-count">${positions.length} position${positions.length !== 1 ? 's' : ''}</span>
                        <div class="scheduled-position-preview">
                            ${positions.slice(0, 4).map(pos => `
                                <span class="scheduled-position-chip">
                                    <span class="pos-color" style="background: ${pos.color}"></span>
                                    ${this.escapeHtml(pos.position_name)}
                                </span>
                            `).join('')}
                            ${positions.length > 4 ? `<span class="scheduled-position-chip">+${positions.length - 4} more</span>` : ''}
                        </div>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
        
        // Bind action buttons
        container.querySelectorAll('.edit-scheduled-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const configId = btn.closest('.scheduled-config-item').dataset.configId;
                this.editScheduledConfig(configId);
            });
        });
        
        container.querySelectorAll('.activate-scheduled-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const configId = btn.closest('.scheduled-config-item').dataset.configId;
                this.activateScheduledConfig(configId);
            });
        });
        
        container.querySelectorAll('.delete-scheduled-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const configId = btn.closest('.scheduled-config-item').dataset.configId;
                this.deleteScheduledConfig(configId);
            });
        });
    },
    
    /**
     * Format a Date object as UTC time string
     */
    formatUTCTime(date) {
        const month = String(date.getUTCMonth() + 1).padStart(2, '0');
        const day = String(date.getUTCDate()).padStart(2, '0');
        const hours = String(date.getUTCHours()).padStart(2, '0');
        const mins = String(date.getUTCMinutes()).padStart(2, '0');
        return `${month}/${day} ${hours}${mins}Z`;
    },
    
    /**
     * Edit a scheduled configuration (opens the config wizard with existing data)
     */
    async editScheduledConfig(configId) {
        try {
            const response = await fetch(`api/splits/configs.php?id=${configId}`);
            if (!response.ok) {
                throw new Error('Failed to load config');
            }
            const data = await response.json();
            const config = data.config;
            
            console.log('[SPLITS] Loaded config for editing:', config);
            
            if (!config) {
                alert('Configuration not found');
                return;
            }
            
            // Transform API response to the format expected by openConfigWizard
            const positions = config.positions || [];
            const transformedConfig = {
                id: config.id,
                artcc: config.artcc,
                name: config.config_name,
                status: config.status,
                startTime: config.start_time_utc ? config.start_time_utc.replace(' ', 'T').slice(0, 16) : null,
                endTime: config.end_time_utc ? config.end_time_utc.replace(' ', 'T').slice(0, 16) : null,
                sectorType: 'all',
                splits: positions.map(pos => ({
                    name: pos.position_name || '',
                    sectors: Array.isArray(pos.sectors) ? pos.sectors : [],
                    color: pos.color || '#4dabf7',
                    frequency: pos.frequency || null,
                    controllerOI: pos.controller_oi || null,
                    filters: pos.filters || null,
                    startTime: pos.start_time_utc || null,
                    endTime: pos.end_time_utc || null
                }))
            };
            
            console.log('[SPLITS] Transformed config for wizard:', transformedConfig);
            
            // Open config wizard with transformed data
            this.openConfigWizard(transformedConfig);
        } catch (err) {
            console.error('[SPLITS] Failed to edit scheduled config:', err);
            alert('Failed to load configuration: ' + err.message);
        }
    },
    
    /**
     * Activate a scheduled configuration immediately
     */
    async activateScheduledConfig(configId) {
        if (!confirm('Activate this configuration now? It will become immediately active.')) {
            return;
        }
        
        try {
            const response = await fetch(`api/splits/configs.php?id=${configId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    status: 'active',
                    start_time_utc: null  // Clear scheduled time
                })
            });
            
            const result = await response.json();
            
            if (response.ok && result.success) {
                await this.loadScheduledConfigs();
                await this.loadActiveConfigs();
            } else {
                alert(result.error || 'Failed to activate config');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to activate scheduled config:', err);
            alert('Failed to activate config: ' + err.message);
        }
    },
    
    /**
     * Delete a scheduled configuration
     */
    async deleteScheduledConfig(configId) {
        const config = this.scheduledConfigs.find(c => c.id == configId);
        const configName = config ? config.config_name : 'this configuration';
        
        if (!confirm(`Delete "${configName}"? This cannot be undone.`)) {
            return;
        }
        
        try {
            const response = await fetch(`api/splits/scheduled.php?id=${configId}`, {
                method: 'DELETE'
            });
            
            const result = await response.json();
            
            if (response.ok && result.success) {
                await this.loadScheduledConfigs();
            } else {
                alert(result.error || 'Failed to delete config');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to delete scheduled config:', err);
            alert('Failed to delete config: ' + err.message);
        }
    },
    
    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
    
    /**
     * Toggle visibility of the Active Splits panel
     */
    toggleActiveSplitsPanel(show) {
        const panel = document.getElementById('active-configs-panel');
        const toggleBtn = document.getElementById('active-splits-toggle-btn');
        
        if (!panel) return;
        
        if (typeof show === 'undefined') {
            // Toggle current state
            show = panel.style.display === 'none';
        }
        
        panel.style.display = show ? 'block' : 'none';
        
        // Show toggle button only when panel is hidden AND there are active configs
        if (toggleBtn) {
            const hasActiveConfigs = this.activeConfigs && this.activeConfigs.length > 0;
            toggleBtn.style.display = (!show && hasActiveConfigs) ? 'flex' : 'none';
            toggleBtn.classList.toggle('active', show);
            toggleBtn.title = show ? 'Hide Active Splits' : 'Show Active Splits';
        }
    },
    
    renderActiveConfigsList() {
        const container = document.getElementById('active-configs-list');
        
        if (!container) return;
        
        const totalConfigs = this.activeConfigs.length;
        
        if (totalConfigs === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">🔴</div>
                    <div class="empty-state-text">No active configurations right now.</div>
                </div>
            `;
            return;
        }
        
        // Group configs by ARTCC
        const configsByArtcc = {};
        this.activeConfigs.forEach(config => {
            if (!configsByArtcc[config.artcc]) {
                configsByArtcc[config.artcc] = [];
            }
            configsByArtcc[config.artcc].push(config);
        });
        
        // Render grouped by ARTCC
        let html = '';
        Object.keys(configsByArtcc).sort().forEach(artcc => {
            const configs = configsByArtcc[artcc];
            const artccSectorCount = configs.reduce((sum, c) => 
                sum + (c.positions || []).reduce((psum, p) => psum + (p.sectors?.length || 0), 0), 0);
            
            html += `
                <div class="artcc-group" data-artcc="${artcc}">
                    <div class="artcc-group-header">
                        <span class="artcc-name">${artcc}</span>
                        <span class="artcc-stats">${artccSectorCount} sectors</span>
                    </div>
                    <div class="artcc-configs">
            `;
            
            configs.forEach(config => {
                const positions = config.positions || [];
                
                html += `
                    <div class="active-config-item" data-config-id="${config.id}" data-artcc="${config.artcc}">
                        <div class="active-config-header">
                            <span class="config-name">${config.config_name}</span>
                            <div class="config-actions">
                                <button class="btn btn-xs btn-outline-info edit-active-btn" title="Edit">✎</button>
                                <button class="btn btn-xs btn-outline-danger delete-active-btn" title="Delete">×</button>
                            </div>
                        </div>
                        <div class="active-config-splits">
                `;
                
                if (positions.length > 0) {
                    positions.forEach(pos => {
                        const sectors = pos.sectors || [];
                        const frequency = pos.frequency || null;
                        
                        // Build frequency display
                        const freqDisplay = frequency ? 
                            `<span class="split-freq" title="Frequency">${frequency}</span>` : '';
                        
                        html += `
                            <div class="split-detail">
                                <div class="split-header">
                                    <span class="split-color" style="background: ${pos.color}"></span>
                                    <span class="split-name">${pos.position_name}</span>
                                    ${freqDisplay}
                                    <span class="split-count">${sectors.length}</span>
                                </div>
                                <div class="split-sectors-hierarchy">
                        `;
                        
                        // Show detailed sector listing with hierarchy
                        if (sectors.length > 0) {
                            // Group sectors if possible (by prefix)
                            const sectorsByPrefix = {};
                            sectors.forEach(sector => {
                                // Extract prefix (e.g., ZNY from ZNY07)
                                const match = sector.match(/^([A-Z]{3})(\d+)$/);
                                if (match) {
                                    const prefix = match[1];
                                    if (!sectorsByPrefix[prefix]) {
                                        sectorsByPrefix[prefix] = [];
                                    }
                                    sectorsByPrefix[prefix].push(match[2]);
                                } else {
                                    // Non-standard format
                                    if (!sectorsByPrefix['_other']) {
                                        sectorsByPrefix['_other'] = [];
                                    }
                                    sectorsByPrefix['_other'].push(sector);
                                }
                            });
                            
                            // Render grouped sectors
                            const prefixes = Object.keys(sectorsByPrefix).sort();
                            if (prefixes.length === 1 && prefixes[0] !== '_other') {
                                // All same ARTCC, show compact view
                                const prefix = prefixes[0];
                                const sectorNums = sectorsByPrefix[prefix].sort((a, b) => parseInt(a) - parseInt(b));
                                html += `<div class="sector-group">`;
                                html += `<span class="sector-prefix">${prefix}</span>`;
                                html += `<span class="sector-nums">${sectorNums.join(', ')}</span>`;
                                html += `</div>`;
                            } else {
                                // Multiple ARTCCs or mixed format
                                prefixes.forEach(prefix => {
                                    if (prefix === '_other') {
                                        html += `<div class="sector-group">`;
                                        html += `<span class="sector-nums">${sectorsByPrefix[prefix].join(', ')}</span>`;
                                        html += `</div>`;
                                    } else {
                                        const sectorNums = sectorsByPrefix[prefix].sort((a, b) => parseInt(a) - parseInt(b));
                                        html += `<div class="sector-group">`;
                                        html += `<span class="sector-prefix">${prefix}</span>`;
                                        html += `<span class="sector-nums">${sectorNums.join(', ')}</span>`;
                                        html += `</div>`;
                                    }
                                });
                            }
                        } else {
                            html += `<span class="text-muted">No sectors</span>`;
                        }
                        
                        html += `
                                </div>
                            </div>
                        `;
                    });
                } else {
                    html += '<div class="text-muted small px-2 py-1">No positions defined</div>';
                }
                
                html += `
                        </div>
                    </div>
                `;
            });
            
            html += `
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
        
        // Click ARTCC header to zoom
        container.querySelectorAll('.artcc-group-header').forEach(header => {
            header.addEventListener('click', () => {
                const artcc = header.closest('.artcc-group').dataset.artcc;
                if (artcc && this.artccCenters[artcc]) {
                    this.map.flyTo({ center: this.artccCenters[artcc], zoom: 6 });
                }
            });
        });
        
        // Edit buttons
        container.querySelectorAll('.edit-active-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const item = btn.closest('.active-config-item');
                const configId = parseInt(item.dataset.configId);
                await this.editConfig(configId);
            });
        });
        
        // Delete buttons
        container.querySelectorAll('.delete-active-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const item = btn.closest('.active-config-item');
                const configId = parseInt(item.dataset.configId);
                await this.deleteConfig(configId);
            });
        });
    },
    
    /**
     * Render the floating Active Splits panel with text summary
     */
    renderActiveConfigsPanel() {
        const panel = document.getElementById('active-configs-panel');
        const summaryContainer = document.getElementById('active-panel-summary');
        const contentContainer = document.getElementById('active-panel-content');
        const toggleBtn = document.getElementById('active-splits-toggle-btn');
        
        if (!panel || !contentContainer) return;
        
        const totalConfigs = this.activeConfigs.length;
        const totalPositions = this.activeConfigs.reduce((sum, c) => sum + (c.positions?.length || 0), 0);
        const totalSectors = this.activeConfigs.reduce((sum, c) => 
            sum + (c.positions || []).reduce((psum, p) => psum + (p.sectors?.length || 0), 0), 0);
        const artccs = [...new Set(this.activeConfigs.map(c => c.artcc))].sort();
        
        // Update summary stats
        if (summaryContainer) {
            if (totalConfigs === 0) {
                summaryContainer.innerHTML = '';
            } else {
                summaryContainer.innerHTML = `
                    <div class="summary-stats">
                        <div class="summary-stat"><span class="summary-stat-value">${artccs.length}</span> ARTCC${artccs.length !== 1 ? 's' : ''}</div>
                        <div class="summary-stat"><span class="summary-stat-value">${totalPositions}</span> position${totalPositions !== 1 ? 's' : ''}</div>
                        <div class="summary-stat"><span class="summary-stat-value">${totalSectors}</span> sector${totalSectors !== 1 ? 's' : ''}</div>
                    </div>
                `;
            }
        }
        
        // Show/hide panel and toggle button based on active configs
        if (totalConfigs === 0) {
            panel.style.display = 'none';
            if (toggleBtn) toggleBtn.style.display = 'none';
            return;
        } else {
            // Has active configs - show panel by default
            panel.style.display = 'block';
            if (toggleBtn) toggleBtn.style.display = 'none';
        }
        
        // Build text summary grouped by ARTCC
        let html = '<div class="active-splits-summary-text">';
        
        // Group by ARTCC
        const configsByArtcc = {};
        this.activeConfigs.forEach(config => {
            if (!configsByArtcc[config.artcc]) {
                configsByArtcc[config.artcc] = [];
            }
            configsByArtcc[config.artcc].push(config);
        });
        
        Object.keys(configsByArtcc).sort().forEach(artcc => {
            const configs = configsByArtcc[artcc];
            
            html += `<div class="summary-artcc-group" data-artcc="${artcc}">`;
            html += `<div class="summary-artcc-header">${artcc}</div>`;
            
            configs.forEach(config => {
                const positions = config.positions || [];
                
                // Config-level timing
                const configTiming = this.formatConfigTiming(config.start_time_utc, config.end_time_utc);
                
                if (configTiming || config.config_name) {
                    html += `<div class="summary-config-info">`;
                    if (config.config_name) {
                        html += `<span class="summary-config-name">${config.config_name}</span>`;
                    }
                    if (configTiming) {
                        html += `<span class="summary-config-timing">${configTiming}</span>`;
                    }
                    html += `</div>`;
                }
                
                positions.forEach(pos => {
                    const sectors = pos.sectors || [];
                    const frequency = pos.frequency || '';
                    const controllerOI = pos.controller_oi || '';
                    
                    // Position-level timing (if different from config)
                    const posTiming = this.formatPositionTiming(pos.start_time_utc, pos.end_time_utc, config.start_time_utc, config.end_time_utc);
                    
                    // Parse filters if stored as JSON string
                    let filters = pos.filters;
                    if (typeof filters === 'string') {
                        try { filters = JSON.parse(filters); } catch(e) { filters = null; }
                    }
                    
                    // Build sector summary (compact)
                    let sectorSummary = '';
                    if (sectors.length > 0) {
                        // Group by prefix for compact display
                        const byPrefix = {};
                        sectors.forEach(s => {
                            const match = s.match(/^([A-Z]{3})(\d+)$/);
                            if (match) {
                                if (!byPrefix[match[1]]) byPrefix[match[1]] = [];
                                byPrefix[match[1]].push(match[2]);
                            } else {
                                if (!byPrefix['_']) byPrefix['_'] = [];
                                byPrefix['_'].push(s);
                            }
                        });
                        
                        const parts = [];
                        Object.keys(byPrefix).sort().forEach(prefix => {
                            const nums = byPrefix[prefix].sort((a, b) => parseInt(a) - parseInt(b));
                            if (prefix === '_') {
                                parts.push(nums.join(','));
                            } else {
                                parts.push(`${prefix}:${nums.join(',')}`);
                            }
                        });
                        sectorSummary = parts.join(' ');
                    }
                    
                    // Build filter summary
                    const filterTags = this.buildFilterTags(filters);
                    
                    html += `
                        <div class="summary-position">
                            <div class="summary-pos-main">
                                <span class="summary-pos-color" style="background: ${pos.color}"></span>
                                <span class="summary-pos-name">${pos.position_name}</span>
                                ${frequency ? `<span class="summary-pos-freq">${frequency}</span>` : ''}
                                ${controllerOI ? `<span class="summary-pos-controller" title="Controller">${controllerOI}</span>` : ''}
                                ${posTiming ? `<span class="summary-pos-timing">${posTiming}</span>` : ''}
                            </div>
                            <div class="summary-pos-sectors">${sectorSummary}</div>
                            ${filterTags ? `<div class="summary-pos-filters">${filterTags}</div>` : ''}
                        </div>
                    `;
                });
            });
            
            html += `</div>`;
        });
        
        html += '</div>';
        
        contentContainer.innerHTML = html;
        
        // Click ARTCC header to zoom
        contentContainer.querySelectorAll('.summary-artcc-header').forEach(header => {
            header.addEventListener('click', () => {
                const artcc = header.closest('.summary-artcc-group').dataset.artcc;
                if (artcc && this.artccCenters[artcc]) {
                    this.map.flyTo({ center: this.artccCenters[artcc], zoom: 6 });
                }
            });
        });
    },
    
    /**
     * Build filter tags HTML for display in Active Splits panel
     * Handles nested filter structure: filters.route.*, filters.altitude.*, filters.aircraft.*, filters.other
     */
    buildFilterTags(filters) {
        if (!filters || typeof filters !== 'object') return '';
        
        const tags = [];
        
        // Route filters (nested under filters.route)
        const route = filters.route || {};
        if (route.orig) tags.push({ label: 'ORIG', value: route.orig });
        if (route.dest) tags.push({ label: 'DEST', value: route.dest });
        if (route.fix) tags.push({ label: 'FIX', value: route.fix });
        if (route.gate) tags.push({ label: 'GATE', value: route.gate });
        if (route.other) tags.push({ label: 'RTE', value: route.other });
        
        // Also check for flattened format (legacy/API compatibility)
        if (filters.departure) tags.push({ label: 'ORIG', value: filters.departure });
        if (filters.arrival) tags.push({ label: 'DEST', value: filters.arrival });
        if (filters.route_string) tags.push({ label: 'RTE', value: filters.route_string });
        
        // Altitude filters (nested under filters.altitude)
        const alt = filters.altitude || {};
        if (alt.floor || alt.ceiling) {
            const floor = alt.floor || '---';
            const ceiling = alt.ceiling || '---';
            tags.push({ label: 'ALT', value: `${floor}-${ceiling}` });
        }
        if (alt.block) tags.push({ label: 'BLK', value: alt.block });
        
        // Legacy flattened altitude format
        if (filters.altitude_min || filters.altitude_max) {
            const min = filters.altitude_min || '---';
            const max = filters.altitude_max || '---';
            tags.push({ label: 'ALT', value: `${min}-${max}` });
        }
        if (filters.altitude_filed) tags.push({ label: 'FILED', value: `F${filters.altitude_filed}` });
        
        // Direction filter
        if (filters.direction) {
            const dirMap = { 'N': '↑N', 'S': '↓S', 'E': '→E', 'W': '←W', 'NE': '↗NE', 'NW': '↖NW', 'SE': '↘SE', 'SW': '↙SW' };
            tags.push({ label: 'DIR', value: dirMap[filters.direction] || filters.direction });
        }
        
        // Beacon code filter
        if (filters.beacon_from || filters.beacon_to) {
            const from = filters.beacon_from || '----';
            const to = filters.beacon_to || '----';
            tags.push({ label: 'BCN', value: `${from}-${to}` });
        }
        
        // Aircraft filters (nested under filters.aircraft)
        const acft = filters.aircraft || {};
        if (acft.type) tags.push({ label: 'ACFT', value: acft.type });
        if (acft.speed) tags.push({ label: 'SPD', value: acft.speed });
        if (acft.rvsm) tags.push({ label: 'RVSM', value: acft.rvsm });
        if (acft.navEquip) tags.push({ label: 'NAV', value: acft.navEquip });
        if (acft.other) tags.push({ label: 'ACFT', value: acft.other });
        
        // Legacy flattened aircraft format
        if (filters.acft_type) tags.push({ label: 'ACFT', value: filters.acft_type });
        if (filters.speed) tags.push({ label: 'SPD', value: filters.speed });
        if (filters.rvsm) tags.push({ label: 'RVSM', value: filters.rvsm });
        if (filters.nav_equip) tags.push({ label: 'NAV', value: filters.nav_equip });
        
        // Check for notes in both formats
        const otherNote = filters.other || null;
        
        if (tags.length === 0 && !otherNote) return '';
        
        // Build HTML for filter tags with label:value format
        let html = tags.map(tag => 
            `<span class="filter-tag"><span class="filter-label">${tag.label}:</span>${tag.value}</span>`
        ).join('');
        
        // Show notes/other content clearly (full text, not just an icon)
        if (otherNote && String(otherNote).trim()) {
            const noteText = String(otherNote).trim();
            // Escape HTML to prevent XSS
            const escaped = noteText.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            html += `<span class="filter-note" title="${escaped}">📝 ${escaped}</span>`;
        }
        
        return html;
    },
    
    /**
     * Format config-level timing for display
     * Returns string like "22/1400Z-2200Z" or "22/1400Z-23/0200Z"
     */
    formatConfigTiming(startTime, endTime) {
        if (!startTime && !endTime) return '';
        
        const formatTime = (timeStr) => {
            if (!timeStr) return '----';
            try {
                const d = new Date(timeStr.replace(' ', 'T') + (timeStr.includes('Z') ? '' : 'Z'));
                if (isNaN(d.getTime())) return '----';
                const day = d.getUTCDate().toString().padStart(2, '0');
                const hours = d.getUTCHours().toString().padStart(2, '0');
                const mins = d.getUTCMinutes().toString().padStart(2, '0');
                return { day, time: `${hours}${mins}` };
            } catch (e) {
                return '----';
            }
        };
        
        const start = formatTime(startTime);
        const end = formatTime(endTime);
        
        if (start === '----' && end === '----') return '';
        
        if (typeof start === 'object' && typeof end === 'object') {
            if (start.day === end.day) {
                return `${start.day}/${start.time}Z-${end.time}Z`;
            } else {
                return `${start.day}/${start.time}Z-${end.day}/${end.time}Z`;
            }
        } else if (typeof start === 'object') {
            return `${start.day}/${start.time}Z-`;
        } else if (typeof end === 'object') {
            return `-${end.day}/${end.time}Z`;
        }
        
        return '';
    },
    
    /**
     * Format position-level timing (only if different from config timing)
     * Returns abbreviated string or empty if same as config
     */
    formatPositionTiming(posStart, posEnd, configStart, configEnd) {
        // If position timing matches config timing, don't show anything
        if (posStart === configStart && posEnd === configEnd) return '';
        if (!posStart && !posEnd) return '';
        
        const formatTime = (timeStr) => {
            if (!timeStr) return null;
            try {
                const d = new Date(timeStr.replace(' ', 'T') + (timeStr.includes('Z') ? '' : 'Z'));
                if (isNaN(d.getTime())) return null;
                const hours = d.getUTCHours().toString().padStart(2, '0');
                const mins = d.getUTCMinutes().toString().padStart(2, '0');
                return `${hours}${mins}Z`;
            } catch (e) {
                return null;
            }
        };
        
        const start = formatTime(posStart);
        const end = formatTime(posEnd);
        
        if (!start && !end) return '';
        
        if (start && end) {
            return `⏱${start}-${end}`;
        } else if (start) {
            return `⏱${start}-`;
        } else if (end) {
            return `⏱-${end}`;
        }
        
        return '';
    },
    
    async editConfig(configId) {
        // Always fetch from server to get full config with positions
        let config = null;
        
        try {
            const response = await fetch(`api/splits/configs.php?id=${configId}`);
            if (response.ok) {
                const data = await response.json();
                config = data.config;
            } else {
                const err = await response.json();
                throw new Error(err.error || 'Failed to load config');
            }
        } catch (err) {
            console.error('[SPLITS] Failed to fetch config:', err);
            alert('Could not load configuration: ' + err.message);
            return;
        }
        
        if (!config) {
            alert('Could not load configuration');
            return;
        }
        
        console.log('[SPLITS] Editing config:', config);
        
        // Set editing mode
        this.isEditingConfig = true;
        
        // Convert to wizard format
        this.currentConfig = {
            id: config.id,
            artcc: config.artcc,
            name: config.config_name,
            startTime: config.start_time_utc ? config.start_time_utc.replace(' ', 'T').slice(0, 16) : null,
            endTime: config.end_time_utc ? config.end_time_utc.replace(' ', 'T').slice(0, 16) : null,
            status: config.status,
            splits: (config.positions || []).map(p => {
                // Parse filters if stored as JSON string
                let filters = p.filters;
                if (typeof filters === 'string') {
                    try { filters = JSON.parse(filters); } catch(e) { filters = null; }
                }
                
                return {
                    name: p.position_name,
                    color: p.color,
                    sectors: p.sectors || [],
                    startTime: p.start_time_utc,
                    endTime: p.end_time_utc,
                    frequency: p.frequency || null,
                    controllerOI: p.controller_oi || null,
                    filters: filters || null
                };
            })
        };
        
        this.currentStep = 1;
        this.editingSplitIndex = -1;
        
        // Populate form
        document.getElementById('config-artcc').value = this.currentConfig.artcc || '';
        document.getElementById('config-name').value = this.currentConfig.name || '';
        document.getElementById('config-start').value = this.currentConfig.startTime || '';
        document.getElementById('config-end').value = this.currentConfig.endTime || '';
        
        // Update modal title
        document.getElementById('config-modal-title').textContent = 'Edit Configuration';
        
        this.updateWizardStep();
        this.renderConfigSplitsList();
        
        // Load ARTCC on map and show current splits
        if (this.currentConfig.artcc) {
            this.updateMapPreview();
        }
        
        $('#config-modal').modal('show');
    },
    
    updateMapWithActiveConfigs() {
        // Make sure map and GeoJSON are loaded
        if (!this.map || !this.map.loaded()) {
            console.log('[SPLITS] Map not ready, deferring updateMapWithActiveConfigs');
            setTimeout(() => this.updateMapWithActiveConfigs(), 500);
            return;
        }
        
        if (!this.geoJsonCache.high && !this.geoJsonCache.low) {
            console.log('[SPLITS] GeoJSON not loaded, deferring updateMapWithActiveConfigs');
            setTimeout(() => this.updateMapWithActiveConfigs(), 500);
            return;
        }
        
        // Collect all sectors from active configs
        const features = [];
        let foundCount = 0;
        let notFoundCount = 0;
        
        // Track position centroids for consolidated labels
        // Key: "configId-positionName", Value: { centroids: [[lng,lat]...], color, name, strata: Set }
        const positionGroups = new Map();
        
        this.activeConfigs.forEach(config => {
            const positions = config.positions || [];
            positions.forEach(pos => {
                const sectors = pos.sectors || [];
                const groupKey = `${config.id}-${pos.position_name}`;
                
                if (!positionGroups.has(groupKey)) {
                    positionGroups.set(groupKey, {
                        centroids: [],
                        color: pos.color,
                        name: pos.position_name,
                        artcc: config.artcc,
                        config_id: config.id,
                        key: groupKey,
                        strata: new Set()  // Track which strata this position has
                    });
                }
                
                sectors.forEach(sectorId => {
                    const sector = this.findSectorGeometry(sectorId);
                    if (sector) {
                        foundCount++;
                        features.push({
                            type: 'Feature',
                            properties: {
                                sector_id: sectorId,
                                color: pos.color,
                                position: pos.position_name,
                                config_id: config.id,
                                selected: true,
                                boundary_type: sector.boundary_type  // Include strata for filtering
                            },
                            geometry: sector.geometry
                        });
                        
                        // Track strata and centroid for consolidated label
                        if (sector.boundary_type) {
                            positionGroups.get(groupKey).strata.add(sector.boundary_type);
                        }
                        if (sector.centroid) {
                            positionGroups.get(groupKey).centroids.push(sector.centroid);
                        }
                    } else {
                        notFoundCount++;
                    }
                });
            });
        });
        
        // Create consolidated labels - one per position group
        const labelFeatures = [];
        positionGroups.forEach((group, key) => {
            if (group.centroids.length === 0) return;
            
            // Calculate average centroid of all sectors in this position
            const avgLng = group.centroids.reduce((sum, c) => sum + c[0], 0) / group.centroids.length;
            const avgLat = group.centroids.reduce((sum, c) => sum + c[1], 0) / group.centroids.length;
            
            labelFeatures.push({
                type: 'Feature',
                properties: {
                    label: group.name,
                    color: group.color,
                    sectorCount: group.centroids.length,
                    config_id: group.config_id,
                    artcc: group.artcc,
                    positionKey: group.key,
                    // Store which strata this position has (for filtering)
                    has_low: group.strata.has('low'),
                    has_high: group.strata.has('high'),
                    has_superhigh: group.strata.has('superhigh')
                },
                geometry: { type: 'Point', coordinates: [avgLng, avgLat] }
            });
        });
        
        console.log(`[SPLITS] Sector lookup: ${foundCount} found, ${notFoundCount} not found`);
        console.log(`[SPLITS] Created ${labelFeatures.length} consolidated labels for ${positionGroups.size} position groups`);
        
        if (this.map.getSource('sectors')) {
            this.map.getSource('sectors').setData({ type: 'FeatureCollection', features });
        } else {
            console.warn('[SPLITS] sectors source not found!');
        }
        
        if (this.map.getSource('sector-labels')) {
            this.map.getSource('sector-labels').setData({ type: 'FeatureCollection', features: labelFeatures });
        }
        
        // Apply current strata filter to the newly loaded data
        this.applyActiveSplitsStrataFilter();
        
        // Show panel if we have active configs
        const panel = document.getElementById('active-configs-panel');
        if (panel) {
            panel.style.display = this.activeConfigs.length > 0 ? 'block' : 'none';
        }
        
        console.log(`[SPLITS] Updated map with ${features.length} sector features from ${this.activeConfigs.length} active configs`);
    },
    
    findSectorGeometry(sectorId) {
        if (!sectorId) return null;
        
        const sectorIdUpper = sectorId.toUpperCase();
        
        for (const type of ['high', 'low', 'superhigh']) {
            const data = this.geoJsonCache[type];
            if (!data?.features) continue;
            
            for (const f of data.features) {
                // Try multiple property names for matching
                const label = (f.properties?.label || '').toUpperCase();
                const name = (f.properties?.name || '').toUpperCase();
                const id = (f.properties?.id || '').toUpperCase();
                const sectorNum = f.properties?.sector;
                const artcc = (f.properties?.artcc || '').toUpperCase();
                
                // Match by label, name, id, or artcc+sector combo
                if (label === sectorIdUpper || 
                    name === sectorIdUpper || 
                    id === sectorIdUpper ||
                    (artcc && sectorNum && `${artcc}${sectorNum}`.toUpperCase() === sectorIdUpper)) {
                    return {
                        geometry: f.geometry,
                        centroid: this.calculateCentroid(f.geometry?.coordinates),
                        boundary_type: type  // Include strata type (high, low, superhigh)
                    };
                }
            }
        }
        
        console.warn(`[SPLITS] Sector geometry not found for: ${sectorId}`);
        return null;
    },
    
    calculateCentroid(coordinates) {
        if (!coordinates || !coordinates.length) return null;
        const ring = Array.isArray(coordinates[0][0]) ? coordinates[0] : coordinates;
        
        let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
        ring.forEach(coord => {
            if (Array.isArray(coord) && coord.length >= 2) {
                minX = Math.min(minX, coord[0]);
                maxX = Math.max(maxX, coord[0]);
                minY = Math.min(minY, coord[1]);
                maxY = Math.max(maxY, coord[1]);
            }
        });
        
        if (!isFinite(minX)) return null;
        return [(minX + maxX) / 2, (minY + maxY) / 2];
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // UTILITIES
    // ═══════════════════════════════════════════════════════════════════
    
    showLoading(show) {
        const overlay = document.getElementById('loading-overlay');
        if (overlay) overlay.style.display = show ? 'flex' : 'none';
    },
    
    /**
     * Update map preview with current config's sectors
     * Shows sectors colored by their assigned splits
     */
    updateMapPreview() {
        if (!this.map || !this.currentConfig.artcc) return;
        
        const features = [];
        const labelFeatures = [];
        const artcc = this.currentConfig.artcc;
        
        // Get all sectors for this ARTCC
        const allSectors = this.getSectorsForArtcc(artcc);
        const sectorMap = {};
        allSectors.forEach(s => { sectorMap[s.id] = s; });
        
        // Track which sectors are assigned to splits
        const assignedSectors = new Map(); // sectorId -> { splitName, color }
        this.currentConfig.splits.forEach(split => {
            split.sectors.forEach(sectorId => {
                assignedSectors.set(sectorId, { name: split.name, color: split.color });
            });
        });
        
        // Build features for all sectors
        allSectors.forEach(sector => {
            const sectorGeom = this.findSectorGeometry(sector.id);
            if (!sectorGeom) return;
            
            const assigned = assignedSectors.get(sector.id);
            const color = assigned ? assigned.color : '#444444';
            
            features.push({
                type: 'Feature',
                properties: {
                    sector_id: sector.id,
                    color: color,
                    position: assigned ? assigned.name : null,
                    selected: !!assigned
                },
                geometry: sectorGeom.geometry
            });
            
            // Add label
            if (sectorGeom.centroid) {
                labelFeatures.push({
                    type: 'Feature',
                    properties: {
                        label: assigned ? assigned.name : sector.name,
                        color: assigned ? assigned.color : '#666666'
                    },
                    geometry: { type: 'Point', coordinates: sectorGeom.centroid }
                });
            }
        });
        
        // Update map sources
        if (this.map.getSource('sectors')) {
            this.map.getSource('sectors').setData({ type: 'FeatureCollection', features });
        }
        if (this.map.getSource('sector-labels')) {
            this.map.getSource('sector-labels').setData({ type: 'FeatureCollection', features: labelFeatures });
        }
        
        // Zoom to ARTCC if we have features
        if (features.length > 0) {
            const center = this.artccCenters[artcc];
            if (center) {
                this.map.flyTo({ center, zoom: 6 });
            }
        }
    },
    
    /**
     * Load an ARTCC's sectors onto the map
     */
    loadArtccOnMap(artcc) {
        if (!this.map || !artcc) return;
        
        const sectors = this.getSectorsForArtcc(artcc);
        const features = [];
        const labelFeatures = [];
        
        sectors.forEach(sector => {
            const sectorGeom = this.findSectorGeometry(sector.id);
            if (!sectorGeom) return;
            
            features.push({
                type: 'Feature',
                properties: {
                    sector_id: sector.id,
                    color: '#4dabf7',
                    selected: false
                },
                geometry: sectorGeom.geometry
            });
            
            if (sectorGeom.centroid) {
                labelFeatures.push({
                    type: 'Feature',
                    properties: {
                        label: sector.name,
                        color: '#4dabf7'
                    },
                    geometry: { type: 'Point', coordinates: sectorGeom.centroid }
                });
            }
        });
        
        if (this.map.getSource('sectors')) {
            this.map.getSource('sectors').setData({ type: 'FeatureCollection', features });
        }
        if (this.map.getSource('sector-labels')) {
            this.map.getSource('sector-labels').setData({ type: 'FeatureCollection', features: labelFeatures });
        }
        
        // Zoom to ARTCC
        const center = this.artccCenters[artcc];
        if (center) {
            this.map.flyTo({ center, zoom: 6 });
        }
        
        console.log(`[SPLITS] Loaded ${features.length} sectors for ${artcc}`);
    },
    
    // ═══════════════════════════════════════════════════════════════════
    // SPLIT DATABLOCKS - Toggleable info views on map
    // ═══════════════════════════════════════════════════════════════════
    
    /**
     * Find position data by label name, color, and optionally config_id
     */
    findPositionByLabel(label, color, configId = null) {
        for (const config of this.activeConfigs) {
            // If configId provided, match exactly
            if (configId !== null && config.id !== configId) continue;
            
            for (const pos of (config.positions || [])) {
                if (pos.position_name === label && pos.color === color) {
                    return { config, position: pos, key: `${config.id}-${pos.position_name}` };
                }
            }
        }
        return null;
    },
    
    /**
     * Toggle datablock visibility for a position
     */
    toggleSplitDatablock(positionData, labelCoords) {
        const key = positionData.key;
        
        if (this.activeDatablocks.has(key)) {
            // Hide existing datablock
            this.removeSplitDatablock(key);
        } else {
            // Show new datablock
            this.createSplitDatablock(positionData, labelCoords);
        }
    },
    
    /**
     * Create and show a datablock for a position
     */
    createSplitDatablock(positionData, labelCoords) {
        const { config, position, key } = positionData;
        const color = position.color || '#4dabf7';
        
        // Determine contrasting background
        const bgColor = this.getContrastingBackground(color);
        const textColor = bgColor === '#000000' ? color : color;
        
        // Create datablock container
        const datablock = document.createElement('div');
        datablock.className = 'split-datablock';
        datablock.id = `datablock-${key}`;
        datablock.style.cssText = `
            position: absolute;
            background: ${bgColor};
            border: 2px solid ${color};
            border-radius: 3px;
            padding: 5px 7px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 10px;
            color: ${textColor};
            z-index: 100;
            cursor: move;
            min-width: 120px;
            max-width: 220px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.4);
            line-height: 1.3;
        `;
        
        // Build content
        datablock.innerHTML = this.buildDatablockContent(config, position, color, bgColor);
        
        // Add close button handler
        datablock.querySelector('.datablock-close')?.addEventListener('click', (e) => {
            e.stopPropagation();
            this.removeSplitDatablock(key);
        });
        
        // Create SVG for leader lines (can have multiple)
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.id = `leader-${key}`;
        svg.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 99;
        `;
        
        // Add to map container
        const mapContainer = this.map.getContainer();
        mapContainer.appendChild(svg);
        mapContainer.appendChild(datablock);
        
        // Calculate initial datablock geo position (offset from label in geo coords)
        const labelPoint = this.map.project(labelCoords);
        const offsetPixelX = 80;
        const offsetPixelY = -50;
        const datablockScreenPos = { x: labelPoint.x + offsetPixelX, y: labelPoint.y + offsetPixelY };
        const datablockGeoPos = this.map.unproject([datablockScreenPos.x, datablockScreenPos.y]);
        
        // Position datablock initially
        datablock.style.left = datablockScreenPos.x + 'px';
        datablock.style.top = datablockScreenPos.y + 'px';
        
        // Get sector geometries for this position
        const sectorGeometries = [];
        for (const sectorId of (position.sectors || [])) {
            const geom = this.findSectorGeometry(sectorId);
            if (geom && geom.geometry) {
                sectorGeometries.push({
                    id: sectorId,
                    geometry: geom.geometry
                });
            }
        }
        
        // Store reference with geo coordinates (not pixel offsets)
        this.activeDatablocks.set(key, {
            element: datablock,
            leaderSvg: svg,
            labelCoords: labelCoords,
            // Store datablock position as geo coordinates so it stays fixed to map
            datablockLngLat: [datablockGeoPos.lng, datablockGeoPos.lat],
            position: position,
            config: config,
            color: color,
            sectorGeometries: sectorGeometries
        });
        
        // Update leader lines
        this.updateDatablockLeaderLines(key);
        
        // Make draggable
        this.initDatablockDrag(datablock, key);
        
        console.log(`[SPLITS] Created datablock for ${position.position_name} with ${sectorGeometries.length} sector geometries`);
    },
    
    /**
     * Build HTML content for datablock
     */
    buildDatablockContent(config, position, color, bgColor) {
        const textColor = bgColor === '#000000' ? color : color;
        const dimColor = bgColor === '#000000' ? 'rgba(255,255,255,0.5)' : 'rgba(0,0,0,0.5)';
        const sectors = position.sectors || [];
        
        let html = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px;">
                <strong style="font-size: 12px;">${position.position_name}</strong>
                <span class="datablock-close" style="cursor: pointer; font-size: 14px; line-height: 1; opacity: 0.6; margin-left: 8px;">&times;</span>
            </div>
            <div style="color: ${dimColor}; font-size: 9px; margin-bottom: 3px;">${config.artcc} • ${config.config_name || 'Config'}</div>
        `;
        
        // Frequency & Controller on same line if both exist
        const freqCtrl = [];
        if (position.frequency) freqCtrl.push(`<span style="color: ${dimColor};">F:</span>${position.frequency}`);
        if (position.controller_oi) freqCtrl.push(`<span style="color: ${dimColor};">C:</span>${position.controller_oi}`);
        if (freqCtrl.length > 0) {
            html += `<div style="margin-bottom: 2px;">${freqCtrl.join(' ')}</div>`;
        }
        
        // Sectors - show all, wrap as needed
        if (sectors.length > 0) {
            const sectorSpans = sectors.map(s => `<span style="white-space: nowrap;">${s}</span>`).join(', ');
            html += `<div style="font-size: 9px; color: ${dimColor};">${sectorSpans}</div>`;
        }
        
        // Parse and display filters
        let filters = position.filters;
        if (typeof filters === 'string') {
            try { filters = JSON.parse(filters); } catch(e) { filters = null; }
        }
        
        if (filters && typeof filters === 'object') {
            const filterLines = this.buildDatablockFilters(filters, dimColor);
            if (filterLines) {
                html += `<div style="margin-top: 3px; padding-top: 3px; border-top: 1px solid ${color}25; font-size: 10px;">${filterLines}</div>`;
            }
        }
        
        return html;
    },
    
    /**
     * Build filter display for datablock (compact format)
     */
    buildDatablockFilters(filters, dimColor) {
        const items = [];
        
        // Route filters - combine on lines
        const route = filters.route || {};
        if (route.orig || route.dest) {
            const parts = [];
            if (route.orig) parts.push(`O:${route.orig}`);
            if (route.dest) parts.push(`D:${route.dest}`);
            items.push(parts.join(' '));
        }
        if (route.fix) items.push(`FIX:${route.fix}`);
        if (route.gate) items.push(`GATE:${route.gate}`);
        if (route.other) items.push(`RTE:${route.other}`);
        
        // Legacy flattened format
        if (filters.departure || filters.arrival) {
            const parts = [];
            if (filters.departure) parts.push(`O:${filters.departure}`);
            if (filters.arrival) parts.push(`D:${filters.arrival}`);
            items.push(parts.join(' '));
        }
        if (filters.route_string) items.push(`RTE:${filters.route_string}`);
        
        // Altitude filters
        const alt = filters.altitude || {};
        if (alt.floor || alt.ceiling) {
            items.push(`ALT:${alt.floor || '---'}-${alt.ceiling || '---'}`);
        }
        if (alt.block) items.push(`BLK:${alt.block}`);
        
        // Legacy altitude
        if (filters.altitude_min || filters.altitude_max) {
            items.push(`ALT:${filters.altitude_min || '---'}-${filters.altitude_max || '---'}`);
        }
        
        // Direction
        if (filters.direction) {
            const dirMap = { 'N': '↑N', 'S': '↓S', 'E': '→E', 'W': '←W', 'NE': '↗NE', 'NW': '↖NW', 'SE': '↘SE', 'SW': '↙SW' };
            items.push(`DIR:${dirMap[filters.direction] || filters.direction}`);
        }
        
        // Aircraft filters - combine related
        const acft = filters.aircraft || {};
        const acftParts = [];
        if (acft.type) acftParts.push(acft.type);
        if (acft.speed) acftParts.push(`SPD${acft.speed}`);
        if (acft.rvsm) acftParts.push(acft.rvsm);
        if (acft.navEquip) acftParts.push(`NAV:${acft.navEquip}`);
        if (acftParts.length > 0) items.push(acftParts.join(' '));
        if (acft.other) items.push(`ACFT:${acft.other}`);
        
        // Legacy aircraft
        if (filters.acft_type) items.push(filters.acft_type);
        
        // Notes
        if (filters.other) {
            items.push(`📝${filters.other}`);
        }
        
        if (items.length === 0) return '';
        
        return items.map(item => `<div style="color: inherit;">${item}</div>`).join('');
    },
    
    /**
     * Get contrasting background color (black for light colors, white for dark colors)
     */
    getContrastingBackground(hexColor) {
        // Parse hex color
        const hex = hexColor.replace('#', '');
        const r = parseInt(hex.substr(0, 2), 16);
        const g = parseInt(hex.substr(2, 2), 16);
        const b = parseInt(hex.substr(4, 2), 16);
        
        // Calculate luminance
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        
        // Return black for light colors, white for dark colors
        return luminance > 0.5 ? '#000000' : '#ffffff';
    },
    
    /**
     * Remove a datablock
     */
    removeSplitDatablock(key) {
        const data = this.activeDatablocks.get(key);
        if (data) {
            // Clean up drag handlers
            if (data.element._dragCleanup) {
                data.element._dragCleanup();
            }
            data.element.remove();
            data.leaderSvg.remove();
            this.activeDatablocks.delete(key);
            console.log(`[SPLITS] Removed datablock for ${key}`);
        }
    },
    
    /**
     * Clear all datablocks
     */
    clearAllDatablocks() {
        if (!this.activeDatablocks) {
            this.activeDatablocks = new Map();
            return;
        }
        for (const key of this.activeDatablocks.keys()) {
            this.removeSplitDatablock(key);
        }
    },
    
    /**
     * Update datablock position and leader lines (called on map move)
     */
    updateDatablockPosition(key) {
        const data = this.activeDatablocks.get(key);
        if (!data || !this.map) return;
        
        // Project geo coordinates to screen position
        const screenPos = this.map.project(data.datablockLngLat);
        
        // Update datablock screen position
        data.element.style.left = screenPos.x + 'px';
        data.element.style.top = screenPos.y + 'px';
        
        // Update leader lines to polygon edges
        this.updateDatablockLeaderLines(key);
    },
    
    /**
     * Update leader lines to connect datablock to visible polygon edges
     * Treats contiguous sectors as one polygon, draws one line per disjoint visible group
     */
    updateDatablockLeaderLines(key) {
        const data = this.activeDatablocks.get(key);
        if (!data || !this.map) return;
        
        const svg = data.leaderSvg;
        const color = data.color || '#4dabf7';
        
        // Clear existing lines
        while (svg.firstChild) {
            svg.removeChild(svg.firstChild);
        }
        
        // Get datablock screen position and bounds
        const datablock = data.element;
        const rect = datablock.getBoundingClientRect();
        const mapRect = this.map.getContainer().getBoundingClientRect();
        const mapWidth = mapRect.width;
        const mapHeight = mapRect.height;
        
        const dbLeft = rect.left - mapRect.left;
        const dbTop = rect.top - mapRect.top;
        const dbRight = dbLeft + rect.width;
        const dbBottom = dbTop + rect.height;
        const dbCenterX = dbLeft + rect.width / 2;
        const dbCenterY = dbTop + rect.height / 2;
        
        // Get current map view bounds
        const bounds = this.map.getBounds();
        const viewMinLng = bounds.getWest();
        const viewMaxLng = bounds.getEast();
        const viewMinLat = bounds.getSouth();
        const viewMaxLat = bounds.getNorth();
        
        // Collect ALL visible edge segments from all sectors in this position
        const visibleSegments = [];
        
        for (const sector of (data.sectorGeometries || [])) {
            const geom = sector.geometry;
            if (!geom) continue;
            
            // Get polygon rings
            let rings = [];
            if (geom.type === 'Polygon') {
                rings = [geom.coordinates[0]];
            } else if (geom.type === 'MultiPolygon') {
                rings = geom.coordinates.map(poly => poly[0]);
            }
            
            for (const ring of rings) {
                if (!ring || ring.length < 3) continue;
                
                // Check each edge segment
                for (let i = 0; i < ring.length - 1; i++) {
                    const p1 = ring[i];
                    const p2 = ring[i + 1];
                    
                    // Check if this edge is at least partially in view
                    const p1InView = p1[0] >= viewMinLng && p1[0] <= viewMaxLng && p1[1] >= viewMinLat && p1[1] <= viewMaxLat;
                    const p2InView = p2[0] >= viewMinLng && p2[0] <= viewMaxLng && p2[1] >= viewMinLat && p2[1] <= viewMaxLat;
                    
                    if (!p1InView && !p2InView) {
                        // Check if edge crosses view
                        const edgeMinLng = Math.min(p1[0], p2[0]);
                        const edgeMaxLng = Math.max(p1[0], p2[0]);
                        const edgeMinLat = Math.min(p1[1], p2[1]);
                        const edgeMaxLat = Math.max(p1[1], p2[1]);
                        
                        if (edgeMaxLng < viewMinLng || edgeMinLng > viewMaxLng ||
                            edgeMaxLat < viewMinLat || edgeMinLat > viewMaxLat) {
                            continue;
                        }
                    }
                    
                    // Project to screen coordinates
                    const sp1 = this.map.project([p1[0], p1[1]]);
                    const sp2 = this.map.project([p2[0], p2[1]]);
                    
                    // Check if projected segment is within screen bounds
                    const segMinX = Math.min(sp1.x, sp2.x);
                    const segMaxX = Math.max(sp1.x, sp2.x);
                    const segMinY = Math.min(sp1.y, sp2.y);
                    const segMaxY = Math.max(sp1.y, sp2.y);
                    
                    if (segMaxX < 0 || segMinX > mapWidth || segMaxY < 0 || segMinY > mapHeight) {
                        continue;
                    }
                    
                    visibleSegments.push({ sp1, sp2 });
                }
            }
        }
        
        if (visibleSegments.length === 0) {
            return;
        }
        
        // Group segments by contiguity (connected segments = same visual polygon)
        const groups = this.groupContiguousSegments(visibleSegments);
        
        // For each contiguous group, find the single closest point and draw one leader line
        for (const group of groups) {
            let closestPoint = null;
            let closestDist = Infinity;
            
            for (const seg of group) {
                const closest = this.closestPointOnSegment(seg.sp1.x, seg.sp1.y, seg.sp2.x, seg.sp2.y, dbCenterX, dbCenterY);
                
                // Verify point is on screen
                if (closest.x < 0 || closest.x > mapWidth || closest.y < 0 || closest.y > mapHeight) {
                    continue;
                }
                
                const dist = Math.sqrt((closest.x - dbCenterX) ** 2 + (closest.y - dbCenterY) ** 2);
                if (dist < closestDist) {
                    closestDist = dist;
                    closestPoint = closest;
                }
            }
            
            if (!closestPoint) continue;
            
            // Find nearest point on datablock border to the polygon point
            let dbEdgeX = Math.max(dbLeft, Math.min(dbRight, closestPoint.x));
            let dbEdgeY = Math.max(dbTop, Math.min(dbBottom, closestPoint.y));
            
            // If polygon point is inside datablock bounds, find nearest edge
            if (closestPoint.x >= dbLeft && closestPoint.x <= dbRight && 
                closestPoint.y >= dbTop && closestPoint.y <= dbBottom) {
                const distLeft = closestPoint.x - dbLeft;
                const distRight = dbRight - closestPoint.x;
                const distTop = closestPoint.y - dbTop;
                const distBottom = dbBottom - closestPoint.y;
                const minDist = Math.min(distLeft, distRight, distTop, distBottom);
                
                if (minDist === distLeft) { dbEdgeX = dbLeft; dbEdgeY = dbCenterY; }
                else if (minDist === distRight) { dbEdgeX = dbRight; dbEdgeY = dbCenterY; }
                else if (minDist === distTop) { dbEdgeX = dbCenterX; dbEdgeY = dbTop; }
                else { dbEdgeX = dbCenterX; dbEdgeY = dbBottom; }
            }
            
            // Create line element
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', closestPoint.x);
            line.setAttribute('y1', closestPoint.y);
            line.setAttribute('x2', dbEdgeX);
            line.setAttribute('y2', dbEdgeY);
            line.setAttribute('stroke', color);
            line.setAttribute('stroke-width', '2');
            line.setAttribute('stroke-dasharray', '4,2');
            svg.appendChild(line);
        }
    },
    
    /**
     * Group segments by contiguity - segments sharing endpoints are in the same group
     */
    groupContiguousSegments(segments) {
        if (segments.length === 0) return [];
        if (segments.length === 1) return [segments];
        
        const threshold = 3; // Pixels - endpoints within this distance are considered connected
        const n = segments.length;
        
        // Union-find structure
        const parent = segments.map((_, i) => i);
        
        const find = (i) => {
            if (parent[i] !== i) {
                parent[i] = find(parent[i]);
            }
            return parent[i];
        };
        
        const union = (i, j) => {
            const pi = find(i);
            const pj = find(j);
            if (pi !== pj) {
                parent[pi] = pj;
            }
        };
        
        // Check each pair of segments for connectivity
        for (let i = 0; i < n; i++) {
            for (let j = i + 1; j < n; j++) {
                if (this.segmentsAreConnected(segments[i], segments[j], threshold)) {
                    union(i, j);
                }
            }
        }
        
        // Group segments by their root parent
        const groups = {};
        for (let i = 0; i < n; i++) {
            const root = find(i);
            if (!groups[root]) groups[root] = [];
            groups[root].push(segments[i]);
        }
        
        return Object.values(groups);
    },
    
    /**
     * Check if two segments share an endpoint (within threshold)
     */
    segmentsAreConnected(seg1, seg2, threshold) {
        const points1 = [seg1.sp1, seg1.sp2];
        const points2 = [seg2.sp1, seg2.sp2];
        
        for (const p1 of points1) {
            for (const p2 of points2) {
                const dist = Math.sqrt((p1.x - p2.x) ** 2 + (p1.y - p2.y) ** 2);
                if (dist <= threshold) return true;
            }
        }
        return false;
    },
    
    /**
     * Find closest point on a line segment to a target point
     */
    closestPointOnSegment(x1, y1, x2, y2, px, py) {
        const dx = x2 - x1;
        const dy = y2 - y1;
        const lengthSq = dx * dx + dy * dy;
        
        if (lengthSq === 0) {
            return { x: x1, y: y1 };
        }
        
        // Parameter t for the closest point on the infinite line
        let t = ((px - x1) * dx + (py - y1) * dy) / lengthSq;
        
        // Clamp t to [0, 1] to stay on the segment
        t = Math.max(0, Math.min(1, t));
        
        return {
            x: x1 + t * dx,
            y: y1 + t * dy
        };
    },
    
    /**
     * Update all datablock positions (called on map move)
     */
    updateAllDatablockPositions() {
        for (const key of this.activeDatablocks.keys()) {
            this.updateDatablockPosition(key);
        }
    },
    
    /**
     * Initialize drag functionality for a datablock
     */
    initDatablockDrag(element, key) {
        let isDragging = false;
        let startX, startY, startLeft, startTop;
        
        const onMouseDown = (e) => {
            // Don't drag if clicking close button
            if (e.target.classList.contains('datablock-close')) return;
            
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            startLeft = parseInt(element.style.left) || 0;
            startTop = parseInt(element.style.top) || 0;
            element.style.cursor = 'grabbing';
            e.preventDefault();
        };
        
        const onMouseMove = (e) => {
            if (!isDragging) return;
            
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            const newLeft = startLeft + dx;
            const newTop = startTop + dy;
            element.style.left = newLeft + 'px';
            element.style.top = newTop + 'px';
            
            // Update stored geo coordinates so datablock stays in place when map moves
            const data = this.activeDatablocks.get(key);
            if (data && this.map) {
                const newGeoPos = this.map.unproject([newLeft, newTop]);
                data.datablockLngLat = [newGeoPos.lng, newGeoPos.lat];
            }
            
            // Update leader lines while dragging
            this.updateDatablockLeaderLines(key);
        };
        
        const onMouseUp = () => {
            if (isDragging) {
                isDragging = false;
                element.style.cursor = 'move';
            }
        };
        
        element.addEventListener('mousedown', onMouseDown);
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
        
        // Store cleanup function
        element._dragCleanup = () => {
            element.removeEventListener('mousedown', onMouseDown);
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
        };
    },
    
    // Debug function - call from console: SplitsController.debug()
    debug() {
        console.log('=== SPLITS DEBUG ===');
        console.log('Active configs:', this.activeConfigs);
        console.log('GeoJSON cache keys:', Object.keys(this.geoJsonCache));
        
        ['high', 'low', 'superhigh'].forEach(type => {
            const data = this.geoJsonCache[type];
            if (data?.features?.length > 0) {
                console.log(`${type}.json: ${data.features.length} features`);
                console.log(`${type} sample properties:`, data.features[0].properties);
                
                // Show first 5 sector labels
                const labels = data.features.slice(0, 10).map(f => 
                    f.properties?.label || f.properties?.name || f.properties?.id || 'no-label'
                );
                console.log(`${type} first 10 labels:`, labels);
            } else {
                console.log(`${type}.json: not loaded or empty`);
            }
        });
        
        console.log('Map sources:', this.map ? Object.keys(this.map.style._sourceCaches || {}) : 'no map');
        console.log('===================');
    }
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    SplitsController.init();
});
