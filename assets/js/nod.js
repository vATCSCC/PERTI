/**
 * NAS Operations Dashboard (NOD) Module
 * 
 * Consolidated dashboard for:
 * - Live traffic visualization
 * - Public reroutes
 * - Active splits
 * - JATOC incidents
 * - Advisories
 * - Active TMIs (GS/GDP/Reroutes)
 */

(function() {
    'use strict';
    
    // =========================================
    // Helper Functions
    // =========================================
    
    /**
     * Calculate contrasting text color (black or white) based on background color luminance
     */
    function getContrastColor(hexColor) {
        if (!hexColor || typeof hexColor !== 'string') return '#ffffff';
        
        // Remove # if present
        const hex = hexColor.replace('#', '');
        
        // Parse RGB
        let r, g, b;
        if (hex.length === 3) {
            r = parseInt(hex[0] + hex[0], 16);
            g = parseInt(hex[1] + hex[1], 16);
            b = parseInt(hex[2] + hex[2], 16);
        } else if (hex.length === 6) {
            r = parseInt(hex.substr(0, 2), 16);
            g = parseInt(hex.substr(2, 2), 16);
            b = parseInt(hex.substr(4, 2), 16);
        } else {
            return '#ffffff';
        }
        
        // Calculate relative luminance (WCAG formula)
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        
        // Return black for light backgrounds, white for dark
        return luminance > 0.5 ? '#000000' : '#ffffff';
    }

    /**
     * Normalize coordinates for International Date Line (IDL) crossing
     * When a route crosses ±180° longitude, adjust coordinates to prevent
     * the line from wrapping around the entire globe.
     * @param {Array} coords - Array of [lon, lat] coordinates
     * @returns {Array} - Normalized coordinates
     */
    function normalizeForIDL(coords) {
        if (!coords || coords.length < 2) return coords;

        const normalized = [[coords[0][0], coords[0][1]]];

        for (let i = 1; i < coords.length; i++) {
            let prevLon = normalized[i - 1][0];
            let currLon = coords[i][0];
            const currLat = coords[i][1];

            // Check for IDL crossing (longitude jump > 180°)
            const lonDiff = currLon - prevLon;
            if (Math.abs(lonDiff) > 180) {
                // Adjust current longitude to be continuous with previous
                if (lonDiff > 0) {
                    currLon -= 360;
                } else {
                    currLon += 360;
                }
            }

            normalized.push([currLon, currLat]);
        }

        return normalized;
    }

    // =========================================
    // State Management
    // =========================================
    
    const state = {
        map: null,
        initialized: false,
        
        // Route label DOM markers (for draggable labels)
        routeLabelMarkers: [],
        
        // Layer visibility
        layers: {
            artcc: true,
            tracon: false,
            high: false,
            low: false,
            superhigh: false,
            traffic: true,
            tracks: false,
            'public-routes': false,
            splits: false,
            incidents: true,
            radar: false
        },
        
        // Splits strata visibility
        splitsStrata: {
            low: true,
            high: true,
            superhigh: true
        },
        
        // Layer opacity (0-1)
        layerOpacity: {
            'public-routes': 0.9,
            splits: 0.7,
            incidents: 0.8
        },
        
        // Map legend visibility
        mapLegendVisible: true,
        
        // Route labels visibility
        routeLabelsVisible: true,
        
        // Traffic state
        traffic: {
            data: [],
            filteredData: [],
            colorMode: 'weight_class',
            showLabels: true,
            showTracks: false,
            filters: {
                weightClasses: ['SUPER', 'HEAVY', 'LARGE', 'SMALL', 'J', 'H', 'L', 'S', ''],
                origin: '',
                dest: '',
                carrier: '',
                altitudeMin: null,
                altitudeMax: null
            }
        },
        
        // TMI data
        tmi: {
            groundStops: [],
            gdps: [],
            reroutes: [],
            publicRoutes: [],
            discord: []
        },
        
        // Advisories
        advisories: [],
        
        // JATOC
        jatoc: {
            incidents: [],
            opsLevel: 1
        },
        
        // TMU OpLevel (from active PERTI Plan)
        tmu: {
            opsLevel: null,
            eventName: null,
            eventId: null
        },
        
        // UI state
        ui: {
            panelCollapsed: false,
            activeTab: 'tmi',
            layerControlsCollapsed: false,
            trafficControlsCollapsed: true
        },
        
        // Refresh timers
        timers: {
            clock: null,
            traffic: null,
            tmi: null,
            advisories: null,
            jatoc: null,
            tmuOplevel: null
        }
    };
    
    // =========================================
    // Initialization
    // =========================================
    
    function init() {
        console.log('[NOD] Initializing NAS Operations Dashboard...');
        
        // Start UTC clock
        startClock();
        
        // Initialize map (data loading happens after map load)
        initMap();
        
        // Start refresh timers
        startRefreshTimers();
        
        // Restore UI state from localStorage
        restoreUIState();
        
        // Initialize draggable panels
        initDraggablePanels();
        
        // Initialize draggable legend
        initDraggableLegend();
        
        state.initialized = true;
        console.log('[NOD] Initialization complete');
    }
    
    // =========================================
    // Clock
    // =========================================
    
    function startClock() {
        updateClock();
        state.timers.clock = setInterval(updateClock, 1000);
    }
    
    function updateClock() {
        const now = new Date();
        const utc = now.toISOString().substr(11, 8);
        document.getElementById('utcTime').textContent = utc;
    }
    
    // =========================================
    // Map Initialization
    // =========================================
    
    function initMap() {
        const config = window.NOD_CONFIG || {};
        
        // Use dark-matter-nolabels for cleaner display
        state.map = new maplibregl.Map({
            container: 'nod-map',
            style: config.mapStyle || 'https://basemaps.cartocdn.com/gl/dark-matter-nolabels-gl-style/style.json',
            center: config.mapCenter || [-98.5, 39.5],
            zoom: config.mapZoom || 4,
            attributionControl: false
        });
        
        state.map.addControl(new maplibregl.NavigationControl(), 'top-right');
        
        state.map.on('load', () => {
            console.log('[NOD] Map loaded');
            
            // Hide any remaining label layers from base style
            const style = state.map.getStyle();
            if (style && style.layers) {
                style.layers.forEach(layer => {
                    if (layer.type === 'symbol' && layer.id.includes('label')) {
                        state.map.setLayoutProperty(layer.id, 'visibility', 'none');
                    }
                });
            }
            
            initMapLayers();
            
            // Load data now that map sources are ready
            loadAllData();
            
            // Apply any saved layer visibility state
            applyLayerState();
        });
    }
    
    function initMapLayers() {
        const config = window.NOD_CONFIG || {};
        const paths = config.geojsonPaths || {};
        
        // =========================================
        // Layer Order (bottom to top on map):
        // 1. Weather radar (bottom)
        // 2. TRACON boundaries
        // 3. Splits
        // 4. JATOC incidents
        // 5. Sector boundaries (high, low, superhigh)
        // 6. ARTCC boundaries
        // 7. Routes
        // 8. Flights (top)
        // =========================================
        
        // =========================================
        // 1. Weather Radar Layer (BOTTOM)
        // =========================================
        
        state.map.addSource('weather-radar-source', {
            type: 'raster',
            tiles: ['https://mesonet.agron.iastate.edu/cache/tile.py/1.0.0/nexrad-n0q-900913/{z}/{x}/{y}.png'],
            tileSize: 256,
            attribution: '© Iowa Environmental Mesonet'
        });
        
        state.map.addLayer({
            id: 'weather-radar',
            type: 'raster',
            source: 'weather-radar-source',
            paint: {
                'raster-opacity': 0.3
            },
            layout: { visibility: 'none' }
        });
        
        // =========================================
        // 2. TRACON Boundaries
        // =========================================
        
        if (paths.tracon) {
            state.map.addSource('tracon-source', {
                type: 'geojson',
                data: paths.tracon
            });
            
            state.map.addLayer({
                id: 'tracon-fill',
                type: 'fill',
                source: 'tracon-source',
                paint: {
                    'fill-color': '#28a745',
                    'fill-opacity': 0.08
                },
                layout: { visibility: 'none' }
            });
            
            state.map.addLayer({
                id: 'tracon-lines',
                type: 'line',
                source: 'tracon-source',
                paint: {
                    'line-color': '#28a745',
                    'line-width': 1,
                    'line-opacity': 0.6
                },
                layout: { visibility: 'none' }
            });
        }
        
        // =========================================
        // 3. Active Splits Layer
        // =========================================
        
        state.map.addSource('splits-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        // Separate source for consolidated position labels (point features)
        state.map.addSource('splits-labels-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        state.map.addLayer({
            id: 'splits-fill',
            type: 'fill',
            source: 'splits-source',
            paint: {
                'fill-color': ['get', 'color'],
                'fill-opacity': 0.2
            },
            layout: { visibility: 'none' }
        });
        
        state.map.addLayer({
            id: 'splits-lines',
            type: 'line',
            source: 'splits-source',
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 2
            },
            layout: { visibility: 'none' }
        });
        
        state.map.addLayer({
            id: 'splits-labels',
            type: 'symbol',
            source: 'splits-labels-source',  // Use separate label source
            layout: {
                'text-field': ['get', 'label_text'],
                'text-size': 12,
                'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
                'text-anchor': 'center',
                'text-allow-overlap': false,
                'text-ignore-placement': false,
                'visibility': 'none'
            },
            paint: {
                'text-color': ['get', 'color'],
                'text-halo-color': '#000',
                'text-halo-width': 2
            },
            minzoom: 4
        });
        
        // =========================================
        // 4. JATOC Incidents Layer
        // =========================================
        
        state.map.addSource('incidents-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        // Fill layer for incident boundaries
        state.map.addLayer({
            id: 'incidents-fill',
            type: 'fill',
            source: 'incidents-source',
            paint: {
                'fill-color': ['get', 'color'],
                'fill-opacity': 0.35
            }
        });
        
        // Outline layer for incident boundaries
        state.map.addLayer({
            id: 'incidents-lines',
            type: 'line',
            source: 'incidents-source',
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 3,
                'line-opacity': 0.9
            }
        });
        
        // Labels for incident facilities
        state.map.addLayer({
            id: 'incidents-labels',
            type: 'symbol',
            source: 'incidents-source',
            layout: {
                'text-field': ['concat', ['get', 'facility'], '\n', ['get', 'incident_type']],
                'text-size': 12,
                'text-anchor': 'center',
                'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold']
            },
            paint: {
                'text-color': '#fff',
                'text-halo-color': ['get', 'color'],
                'text-halo-width': 2
            }
        });
        
        // =========================================
        // 5. Sector Boundaries (High, Low, Superhigh)
        // =========================================
        
        if (paths.high) {
            state.map.addSource('high-source', {
                type: 'geojson',
                data: paths.high
            });
            
            state.map.addLayer({
                id: 'high-lines',
                type: 'line',
                source: 'high-source',
                paint: {
                    'line-color': '#6f42c1',
                    'line-width': 1,
                    'line-opacity': 0.5
                },
                layout: { visibility: 'none' }
            });
        }
        
        if (paths.low) {
            state.map.addSource('low-source', {
                type: 'geojson',
                data: paths.low
            });
            
            state.map.addLayer({
                id: 'low-lines',
                type: 'line',
                source: 'low-source',
                paint: {
                    'line-color': '#20c997',
                    'line-width': 1,
                    'line-opacity': 0.5
                },
                layout: { visibility: 'none' }
            });
        }
        
        if (paths.superhigh) {
            state.map.addSource('superhigh-source', {
                type: 'geojson',
                data: paths.superhigh
            });
            
            state.map.addLayer({
                id: 'superhigh-lines',
                type: 'line',
                source: 'superhigh-source',
                paint: {
                    'line-color': '#e83e8c',
                    'line-width': 1,
                    'line-opacity': 0.5
                },
                layout: { visibility: 'none' }
            });
        }
        
        // =========================================
        // 6. ARTCC Boundaries
        // =========================================
        
        if (paths.artcc) {
            state.map.addSource('artcc-source', {
                type: 'geojson',
                data: paths.artcc
            });
            
            state.map.addLayer({
                id: 'artcc-fill',
                type: 'fill',
                source: 'artcc-source',
                paint: {
                    'fill-color': '#4a9eff',
                    'fill-opacity': 0.05
                }
            });
            
            state.map.addLayer({
                id: 'artcc-lines',
                type: 'line',
                source: 'artcc-source',
                paint: {
                    'line-color': '#4a9eff',
                    'line-width': 1.5,
                    'line-opacity': 0.7
                }
            });
            
            state.map.addLayer({
                id: 'artcc-labels',
                type: 'symbol',
                source: 'artcc-source',
                layout: {
                    'text-field': ['get', 'id'],
                    'text-size': 12,
                    'text-anchor': 'center'
                },
                paint: {
                    'text-color': '#4a9eff',
                    'text-halo-color': '#000',
                    'text-halo-width': 1
                }
            });
        }
        
        // =========================================
        // 7. Public Routes Layer - Matching route-maplibre.js symbology
        // =========================================
        
        state.map.addSource('public-routes-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        state.map.addSource('public-routes-labels-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        // Solid segments (mandatory)
        state.map.addLayer({
            id: 'public-routes-solid',
            type: 'line',
            source: 'public-routes-source',
            filter: ['all', ['==', ['get', 'solid'], true], ['!=', ['get', 'isFan'], true]],
            layout: {
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': ['get', 'color'],
                'line-width': ['coalesce', ['get', 'weight'], 3],
                'line-opacity': 0.9
            }
        });
        
        // Dashed segments (non-mandatory)
        state.map.addLayer({
            id: 'public-routes-dashed',
            type: 'line',
            source: 'public-routes-source',
            filter: ['all', ['==', ['get', 'solid'], false], ['!=', ['get', 'isFan'], true]],
            layout: {
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': ['get', 'color'],
                'line-width': ['coalesce', ['get', 'weight'], 3],
                'line-opacity': 0.9,
                'line-dasharray': [4, 4]
            }
        });
        
        // Fan segments (airport fans)
        state.map.addLayer({
            id: 'public-routes-fan',
            type: 'line',
            source: 'public-routes-source',
            filter: ['==', ['get', 'isFan'], true],
            layout: {
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 1.5,
                'line-opacity': 0.9,
                'line-dasharray': [1, 3]
            }
        });
        
        // Legacy fallback layer for routes without solid/isFan properties
        state.map.addLayer({
            id: 'public-routes-lines',
            type: 'line',
            source: 'public-routes-source',
            filter: ['all', 
                ['!', ['has', 'solid']], 
                ['!', ['has', 'isFan']]
            ],
            layout: {
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': ['get', 'color'],
                'line-width': ['coalesce', ['get', 'weight'], 3],
                'line-opacity': 0.9
            }
        });
        
        // Route labels (symbol layer - hidden, we use DOM markers instead)
        state.map.addLayer({
            id: 'public-routes-labels',
            type: 'symbol',
            source: 'public-routes-labels-source',
            layout: {
                'text-field': ['get', 'name'],
                'text-size': 12,
                'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
                'text-anchor': 'center',
                'text-allow-overlap': true,
                'visibility': 'none'  // Hidden - using DOM markers for draggable labels
            },
            paint: {
                'text-color': '#ffffff',
                'text-halo-color': ['get', 'color'],
                'text-halo-width': 3
            }
        });
        
        // =========================================
        // 8. Traffic Layer (TOP) - TSD Symbology (FSM Table 3-6)
        // =========================================
        
        state.map.addSource('traffic-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        // TSD-STYLE AIRCRAFT SYMBOLOGY - Single jet icon for all aircraft
        // Simplified from FSM Table 3-6 weight class variants
        
        // Create SDF-compatible jet icon (white fill for proper color tinting)
        const TSD_ICONS = {
            // Jet - Standard aircraft silhouette (used for all aircraft)
            jet: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">
                <path fill="white" d="M21,16V14L13,9V3.5A1.5,1.5 0 0,0 11.5,2A1.5,1.5 0 0,0 10,3.5V9L2,14V16L10,13.5V19L8,20.5V22L11.5,21L15,22V20.5L13,19V13.5L21,16Z"/>
            </svg>`
        };
        
        // Load jet icon as SDF image
        const jetSvg = TSD_ICONS.jet;
        const img = new Image();
        img.onload = () => {
            if (!state.map.hasImage('tsd-jet')) {
                state.map.addImage('tsd-jet', img, { sdf: true });
                console.log('[NOD] TSD jet icon loaded');
                // Hide fallback layer once icon is ready
                if (state.map.getLayer('traffic-circles-fallback')) {
                    state.map.setLayoutProperty('traffic-circles-fallback', 'visibility', 'none');
                }
            }
        };
        img.onerror = (e) => console.error('[NOD] Failed to load jet icon:', e);
        img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(jetSvg);
        
        // Fallback circle layer (shown until icon loads)
        state.map.addLayer({
            id: 'traffic-circles-fallback',
            type: 'circle',
            source: 'traffic-source',
            paint: {
                'circle-radius': 5,
                'circle-color': ['get', 'color'],
                'circle-stroke-width': 1,
                'circle-stroke-color': '#000'
            }
        });
        
        // Aircraft symbol layer - uniform jet icon for all aircraft
        state.map.addLayer({
            id: 'traffic-icons',
            type: 'symbol',
            source: 'traffic-source',
            layout: {
                'icon-image': 'tsd-jet',
                'icon-size': [
                    'interpolate', ['linear'], ['zoom'],
                    3, 0.5,
                    6, 0.7,
                    10, 1.0,
                    14, 1.2
                ],
                'icon-rotate': ['get', 'heading'],
                'icon-rotation-alignment': 'map',
                'icon-allow-overlap': true,
                'icon-ignore-placement': true
            },
            paint: {
                'icon-color': ['get', 'color'],
                'icon-halo-color': '#000000',
                'icon-halo-width': 1
            }
        });
        
        // Traffic labels with leader line offset
        state.map.addLayer({
            id: 'traffic-labels',
            type: 'symbol',
            source: 'traffic-source',
            layout: {
                'text-field': ['get', 'callsign'],
                'text-size': 10,
                'text-offset': [1.5, 0],  // Offset to side for leader line effect
                'text-anchor': 'left',
                'text-optional': true,
                'text-allow-overlap': false
            },
            paint: {
                'text-color': '#fff',
                'text-halo-color': '#000',
                'text-halo-width': 1
            }
        });
        
        // =========================================
        // 9. Flight Track History Layer
        // =========================================
        
        // Track lines source (GeoJSON LineStrings)
        state.map.addSource('tracks-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        
        // Track trail lines - rendered BELOW traffic icons
        // Using gradient effect: older positions are more transparent
        state.map.addLayer({
            id: 'tracks-lines',
            type: 'line',
            source: 'tracks-source',
            layout: {
                'line-join': 'round',
                'line-cap': 'round',
                'visibility': 'none'  // Start hidden
            },
            paint: {
                'line-color': '#00ff88',  // Bright green trail
                'line-width': [
                    'interpolate', ['linear'], ['zoom'],
                    3, 1,
                    6, 1.5,
                    10, 2,
                    14, 3
                ],
                'line-opacity': 0.7
            }
        }, 'traffic-circles-fallback');  // Insert below traffic
        
        // Track dots at each recorded position (optional detail layer)
        state.map.addLayer({
            id: 'tracks-points',
            type: 'circle',
            source: 'tracks-source',
            layout: {
                'visibility': 'none'  // Start hidden
            },
            paint: {
                'circle-radius': [
                    'interpolate', ['linear'], ['zoom'],
                    3, 1,
                    6, 2,
                    10, 3
                ],
                'circle-color': '#00ff88',
                'circle-opacity': 0.5
            }
        }, 'traffic-circles-fallback');  // Insert below traffic

        // =========================================
        // Flight Plan Routes (like route.php)
        // =========================================

        // Flight routes source - stores route lines for selected flights
        state.map.addSource('flight-routes-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        // Flight waypoints source - stores waypoint markers
        state.map.addSource('flight-waypoints-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        // Route behind aircraft (solid line - already flown) - matches route.php styling
        state.map.addLayer({
            id: 'flight-routes-behind',
            type: 'line',
            source: 'flight-routes-source',
            filter: ['==', ['get', 'segment'], 'behind'],
            layout: { 'line-join': 'round', 'line-cap': 'round' },
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 2.5
            }
        }, 'traffic-circles-fallback');

        // Route ahead of aircraft (dashed line - remaining) - matches route.php styling
        state.map.addLayer({
            id: 'flight-routes-ahead',
            type: 'line',
            source: 'flight-routes-source',
            filter: ['==', ['get', 'segment'], 'ahead'],
            layout: { 'line-join': 'round', 'line-cap': 'round' },
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 2.5,
                'line-dasharray': [4, 4]
            }
        }, 'traffic-circles-fallback');

        // Waypoint circles - colored dots with dark outline (matches route.php)
        state.map.addLayer({
            id: 'flight-waypoints-circles',
            type: 'circle',
            source: 'flight-waypoints-source',
            paint: {
                'circle-radius': [
                    'interpolate', ['linear'], ['zoom'],
                    4, 1.5,
                    8, 2.5,
                    12, 3.5
                ],
                'circle-color': ['get', 'color'],
                'circle-stroke-width': 0.5,
                'circle-stroke-color': '#222222',
                'circle-opacity': 0.8
            },
            minzoom: 5
        }, 'traffic-circles-fallback');

        // Waypoint labels - only show at higher zoom
        // Shows fix name with airway/DP/STAR info (e.g., "FIXNAME (J60)")
        state.map.addLayer({
            id: 'flight-waypoints-labels',
            type: 'symbol',
            source: 'flight-waypoints-source',
            minzoom: 7,
            layout: {
                'text-field': ['get', 'label'],
                'text-size': 9,
                'text-offset': [0, 1],
                'text-anchor': 'top',
                'text-allow-overlap': false
            },
            paint: {
                'text-color': '#ffffff',
                'text-halo-color': '#000000',
                'text-halo-width': 1
            }
        }, 'traffic-circles-fallback');

        // State for drawn flight routes
        state.drawnFlightRoutes = new Map();  // flightKey -> routeData

        // =========================================
        // Map Click Handler
        // =========================================
        
        // =========================================
        // Unified Click Handler for Overlapping Features
        // =========================================
        
        // Define all clickable layers
        const clickableLayers = [
            'traffic-icons',
            'traffic-circles-fallback',
            'public-routes-solid',
            'public-routes-dashed',
            'public-routes-fan',
            'public-routes-lines',
            'incidents-fill',
            'splits-fill'
        ];
        
        // Single click handler for the map
        state.map.on('click', (e) => {
            // Query all features at click point across all clickable layers
            const features = state.map.queryRenderedFeatures(e.point, {
                layers: clickableLayers.filter(l => state.map.getLayer(l))
            });

            if (!features || features.length === 0) return;

            // Deduplicate features (same feature can appear in multiple layers)
            const uniqueFeatures = deduplicateFeatures(features);

            if (uniqueFeatures.length === 1) {
                // Single feature - show popup directly
                showFeaturePopup(uniqueFeatures[0], e.lngLat);
            } else if (uniqueFeatures.length > 1) {
                // Multiple features - show picker
                showFeaturePicker(uniqueFeatures, e.lngLat);
            }
        });

        // Right-click handler for detailed flight info
        state.map.on('contextmenu', (e) => {
            // Query for flight features at click point
            const flightLayers = ['traffic-icons', 'traffic-circles-fallback'];
            const features = state.map.queryRenderedFeatures(e.point, {
                layers: flightLayers.filter(l => state.map.getLayer(l))
            });

            if (!features || features.length === 0) return;

            e.preventDefault();

            // Get the first flight feature
            const feature = features[0];
            if (feature && feature.properties) {
                showDetailedFlightPopup(feature.properties, e.lngLat);
            }
        });

        // Cursor change on hover for all clickable layers
        clickableLayers.forEach(layerId => {
            if (state.map.getLayer(layerId)) {
                state.map.on('mouseenter', layerId, () => {
                    state.map.getCanvas().style.cursor = 'pointer';
                });
                state.map.on('mouseleave', layerId, () => {
                    state.map.getCanvas().style.cursor = '';
                });
            }
        });
    }
    
    // =========================================
    // Data Loading
    // =========================================
    
    function loadAllData() {
        loadTMIData();
        loadAdvisories();
        loadJATOCData();
        loadTMUOpsLevel();  // Load TMU OpLevel from active PERTI Plan
        loadTraffic();
        
        // Splits (loaded once, updated when changed)
        loadActiveSplits();
        
        // Initialize color legends
        renderColorLegend();
        renderMapLegend();
    }
    
    async function loadTMIData() {
        try {
            const response = await fetch('api/nod/tmi_active.php');
            const data = await response.json();
            
            console.log('[NOD] TMI API response:', data);
            if (data.debug) {
                console.log('[NOD] TMI debug info:', data.debug);
            }
            
            // Only update if we got data (buffered pattern - preserve on empty)
            const newGS = data.ground_stops || [];
            const newGDPs = data.gdps || [];
            const newReroutes = data.reroutes || [];
            const newPublicRoutes = data.public_routes || [];
            
            if (newGS.length > 0 || state.tmi.groundStops.length === 0) {
                state.tmi.groundStops = newGS;
            }
            if (newGDPs.length > 0 || state.tmi.gdps.length === 0) {
                state.tmi.gdps = newGDPs;
            }
            if (newReroutes.length > 0 || state.tmi.reroutes.length === 0) {
                state.tmi.reroutes = newReroutes;
            }
            if (newPublicRoutes.length > 0 || state.tmi.publicRoutes.length === 0) {
                state.tmi.publicRoutes = newPublicRoutes;
            }
            
            // If no public routes from TMI API, try the dedicated public routes API
            if (state.tmi.publicRoutes.length === 0) {
                await loadPublicRoutesFromAPI();
            }
            
            // Update UI
            renderTMILists();
            updateStats();
            
            // Update public routes on map
            updatePublicRoutesLayer();
            
        } catch (error) {
            console.error('[NOD] Error loading TMI data:', error);
            // Don't clear state on error - keep showing old data
        }
    }
    
    /**
     * Load public routes directly from api/routes/public.php
     * This is the same endpoint used by route.php / public-routes.js
     */
    async function loadPublicRoutesFromAPI() {
        try {
            const response = await fetch('api/routes/public.php?filter=active');
            if (!response.ok) {
                console.log('[NOD] Public routes API not available');
                return;
            }
            
            const data = await response.json();
            console.log('[NOD] Public routes API response:', data);
            
            // api/routes/public.php returns { routes: [...] }
            const routes = data.routes || [];
            if (routes.length > 0) {
                state.tmi.publicRoutes = routes;
                console.log('[NOD] Loaded', routes.length, 'public routes from dedicated API');
            }
        } catch (error) {
            console.log('[NOD] Error loading public routes:', error.message);
        }
    }
    
    async function loadAdvisories() {
        try {
            const response = await fetch('api/nod/advisories.php?status=ACTIVE');
            const data = await response.json();
            
            // Only update if we got data (buffered pattern)
            const newAdvisories = data.advisories || [];
            if (newAdvisories.length > 0 || state.advisories.length === 0) {
                state.advisories = newAdvisories;
            }
            
            renderAdvisoriesList();
            
        } catch (error) {
            console.error('[NOD] Error loading advisories:', error);
            // Don't clear state on error - keep showing old data
        }
    }
    
    async function loadJATOCData() {
        try {
            const config = window.NOD_CONFIG || {};
            
            // Use demo mode if configured or if explicitly requested via URL param
            const urlParams = new URLSearchParams(window.location.search);
            const demoMode = config.jatocDemo || urlParams.get('jatoc_demo') === '1';
            
            // Try our NOD JATOC endpoint first
            const endpoint = demoMode ? 'api/nod/jatoc.php?demo=1' : 'api/nod/jatoc.php';
            let response = await fetch(endpoint);
            let data = null;
            
            if (response.ok) {
                data = await response.json();
                console.log('[NOD] JATOC API response:', data);
                
                // Show debug info
                if (data.debug) {
                    console.log('[NOD] JATOC debug:', data.debug);
                    if (data.debug.recent_incidents) {
                        console.log('[NOD] JATOC recent incidents in DB:', data.debug.recent_incidents);
                    }
                    if (!data.debug.connection) {
                        console.warn('[NOD] JATOC: Database not connected');
                    } else if (!data.debug.table_exists) {
                        console.warn('[NOD] JATOC: Table does not exist - run JATOC migrations');
                    } else if (data.debug.query_error) {
                        console.warn('[NOD] JATOC query error:', data.debug.query_error);
                    }
                }
            }
            
            // If no incidents from NOD API and not in demo mode, try the main JATOC API
            if (!demoMode && (!data || !data.incidents || data.incidents.length === 0)) {
                try {
                    const mainResponse = await fetch('api/jatoc/incidents.php?status=active');
                    if (mainResponse.ok) {
                        const mainData = await mainResponse.json();
                        console.log('[NOD] JATOC main API response:', mainData);
                        
                        if (mainData.incidents && mainData.incidents.length > 0) {
                            data = data || {};
                            data.incidents = mainData.incidents;
                        }
                    }
                } catch (e) {
                    console.log('[NOD] Main JATOC API not available:', e.message);
                }
            }
            
            // Buffered update - only update if we got data or had no prior data
            const newIncidents = data?.incidents || [];
            const newOpsLevel = data?.ops_level?.level || 1;
            
            if (newIncidents.length > 0 || state.jatoc.incidents.length === 0) {
                state.jatoc.incidents = newIncidents;
            }
            // Always update ops level since 1 is a valid default
            state.jatoc.opsLevel = newOpsLevel;
            
            console.log('[NOD] JATOC incidents loaded:', state.jatoc.incidents.length);
            console.log('[NOD] JATOC ops level:', state.jatoc.opsLevel);
            
            if (state.jatoc.incidents.length > 0) {
                console.log('[NOD] First incident:', state.jatoc.incidents[0]);
            } else {
                console.log('[NOD] No JATOC incidents. Add ?jatoc_demo=1 to URL to test with demo data');
            }
            
            // Update UI
            renderIncidentsList();
            updateOpsLevelBadge();
            updateStats();
            
            // Update incidents on map
            updateIncidentsLayer();
            
        } catch (error) {
            console.error('[NOD] Error loading JATOC data:', error);
            // Don't clear state on error - keep showing old data
        }
    }
    
    /**
     * Load TMU Operations Level from active PERTI Plan
     */
    async function loadTMUOpsLevel() {
        try {
            const response = await fetch('api/nod/tmu_oplevel.php');
            if (!response.ok) {
                console.warn('[NOD] TMU OpLevel API returned error:', response.status);
                return;
            }
            
            const data = await response.json();
            console.log('[NOD] TMU OpLevel API response:', data);
            
            // Update state
            state.tmu.opsLevel = data.tmu_oplevel;
            state.tmu.eventName = data.event_name;
            state.tmu.eventId = data.event_id;
            
            // Update badge
            updateTMUOpsLevelBadge();
            
        } catch (error) {
            console.error('[NOD] Error loading TMU OpLevel:', error);
        }
    }
    
    async function loadTraffic() {
        // Use centralized ADLService for buffered refresh
        // This prevents data flashing during periodic refreshes
        if (typeof ADLService !== 'undefined') {
            return ADLService.refresh().then(flights => {
                // Only update if we got data (preserves old data on empty/error)
                if (flights && flights.length > 0) {
                    state.traffic.data = flights;
                } else if (state.traffic.data.length === 0) {
                    // Only set empty if we had no prior data
                    state.traffic.data = flights || [];
                }
                // If flights is empty but we have prior data, keep it
                
                applyFilters();  // Apply client-side filters
                updateTrafficLayer();
                updateStats();
                return state.traffic.data;
            });
        }
        
        // Fallback to direct fetch if ADLService not available
        try {
            const url = 'api/adl/current.php?limit=10000&active=1';
            const response = await fetch(url);
            const data = await response.json();
            
            const newFlights = data.flights || data || [];
            // Only update if we got data (buffered pattern)
            if (newFlights.length > 0 || state.traffic.data.length === 0) {
                state.traffic.data = newFlights;
            }
            
            applyFilters();  // Apply client-side filters
            updateTrafficLayer();
            updateStats();
            
        } catch (error) {
            console.error('[NOD] Error loading traffic:', error);
            // Don't clear state.traffic.data on error - keep showing old data
        }
    }
    
    async function loadDiscordTMI() {
        try {
            const response = await fetch('api/nod/discord.php?action=list');
            const data = await response.json();
            
            state.tmi.discord = data.tmis || [];
            renderDiscordList();
            
        } catch (error) {
            console.error('[NOD] Error loading Discord TMI:', error);
        }
    }
    
    // =========================================
    // Splits Data Loading
    // =========================================
    
    /**
     * Load active splits configuration
     * Source: api/splits/active.php + sector boundary GeoJSON files
     */
    async function loadActiveSplits() {
        try {
            // Get active configurations (may have multiple ARTCCs)
            const response = await fetch('api/splits/active.php');
            if (!response.ok) {
                console.log('[NOD] Splits API not available');
                return;
            }
            
            const data = await response.json();
            console.log('[NOD] Splits API response:', data);
            
            const activeConfigs = data.configs || data || [];
            
            if (!activeConfigs.length) {
                console.log('[NOD] No active splits configurations');
                return;
            }
            
            // Build a map of sector label -> position info
            const sectorPositionMap = {};
            const artccsNeeded = new Set();
            
            for (const config of activeConfigs) {
                const positions = config.positions || [];
                const artcc = (config.artcc || '').toUpperCase();
                artccsNeeded.add(artcc);
                
                console.log(`[NOD] Config: ${config.config_name}, ARTCC: ${artcc}, Positions: ${positions.length}`);
                
                for (const pos of positions) {
                    // sectors can be JSON array or comma-separated string
                    let sectors = pos.sectors;
                    if (typeof sectors === 'string') {
                        try {
                            sectors = JSON.parse(sectors);
                        } catch (e) {
                            sectors = sectors.split(',').map(s => s.trim());
                        }
                    }
                    
                    console.log(`[NOD] Position: ${pos.position_name}, Color: ${pos.color}, Sectors:`, sectors);
                    
                    if (Array.isArray(sectors)) {
                        for (const sector of sectors) {
                            // Sector labels are usually like "ZAB15" or just "15"
                            const sectorLabel = String(sector).toUpperCase().trim();
                            sectorPositionMap[sectorLabel] = {
                                position_name: pos.position_name,
                                color: pos.color || getPositionColor(pos.position_name),
                                artcc: artcc,
                                config_name: config.config_name,
                                frequency: pos.frequency || null
                            };
                            // Also map without ARTCC prefix (e.g., "15" for "ZAB15")
                            if (sectorLabel.startsWith(artcc)) {
                                const shortLabel = sectorLabel.substring(artcc.length);
                                sectorPositionMap[shortLabel] = sectorPositionMap[sectorLabel];
                            }
                            // Also map with ARTCC prefix if not already there
                            if (!sectorLabel.startsWith(artcc) && sectorLabel.match(/^\d+$/)) {
                                sectorPositionMap[artcc + sectorLabel] = sectorPositionMap[sectorLabel];
                            }
                        }
                    }
                }
            }
            
            console.log('[NOD] Sector position map keys:', Object.keys(sectorPositionMap));
            
            // Load sector boundaries from static GeoJSON files
            const features = [];
            const boundaryFiles = ['high', 'low', 'superhigh'];
            
            // Track position centroids for consolidated labels
            // Key: position_name, Value: { centroids: [[lng,lat]...], color, config_name, artcc, frequency, strata: Set }
            const positionGroups = new Map();
            
            for (const boundaryType of boundaryFiles) {
                try {
                    const boundaryResponse = await fetch(`assets/geojson/${boundaryType}.json`);
                    if (!boundaryResponse.ok) {
                        console.log(`[NOD] ${boundaryType}.json not found`);
                        continue;
                    }
                    
                    const boundaryData = await boundaryResponse.json();
                    const boundaryFeatures = boundaryData.features || [];
                    
                    let matchCount = 0;
                    let sampleFeatures = [];
                    
                    for (const feature of boundaryFeatures) {
                        const props = feature.properties || {};
                        const featureArtcc = (props.artcc || '').toUpperCase();
                        
                        // Only include features for active ARTCCs
                        if (!artccsNeeded.has(featureArtcc)) continue;
                        
                        // Collect sample feature props for debugging
                        if (sampleFeatures.length < 3) {
                            sampleFeatures.push(props);
                        }
                        
                        // Try to match by label, sector, or id
                        const sectorLabel = (props.label || '').toUpperCase().trim();
                        const sectorNum = (props.sector || props.id || '').toString().toUpperCase().trim();
                        
                        // Check for position match - try multiple formats
                        const posInfo = sectorPositionMap[sectorLabel] || 
                                       sectorPositionMap[sectorNum] ||
                                       sectorPositionMap[featureArtcc + sectorNum];
                        
                        if (posInfo) {
                            matchCount++;
                            features.push({
                                type: 'Feature',
                                geometry: feature.geometry,
                                properties: {
                                    ...props,
                                    position_name: posInfo.position_name,
                                    color: posInfo.color,
                                    artcc: posInfo.artcc,
                                    config_name: posInfo.config_name,
                                    frequency: posInfo.frequency,
                                    boundary_type: boundaryType
                                }
                            });
                            
                            // Track centroid and strata for consolidated label
                            const posName = posInfo.position_name;
                            if (!positionGroups.has(posName)) {
                                positionGroups.set(posName, {
                                    centroids: [],
                                    color: posInfo.color,
                                    config_name: posInfo.config_name,
                                    artcc: posInfo.artcc,
                                    frequency: posInfo.frequency,
                                    strata: new Set()  // Track which strata this position has
                                });
                            }
                            
                            // Add this strata type to the position
                            positionGroups.get(posName).strata.add(boundaryType);
                            
                            // Calculate centroid from geometry
                            const centroid = calculatePolygonCentroid(feature.geometry);
                            if (centroid) {
                                positionGroups.get(posName).centroids.push(centroid);
                            }
                        }
                    }
                    
                    console.log(`[NOD] ${boundaryType}.json: ${boundaryFeatures.length} features, ${matchCount} matched for ${[...artccsNeeded].join(',')}`);
                    if (sampleFeatures.length > 0) {
                        console.log(`[NOD] Sample ${boundaryType} feature properties:`, sampleFeatures);
                    }
                    
                } catch (e) {
                    console.log(`[NOD] Could not load ${boundaryType}.json:`, e.message);
                }
            }
            
            // Create consolidated label features - one point per position at average centroid
            const labelFeatures = [];
            positionGroups.forEach((group, posName) => {
                if (group.centroids.length === 0) return;
                
                // Calculate average centroid of all sectors in this position
                const avgLng = group.centroids.reduce((sum, c) => sum + c[0], 0) / group.centroids.length;
                const avgLat = group.centroids.reduce((sum, c) => sum + c[1], 0) / group.centroids.length;
                
                labelFeatures.push({
                    type: 'Feature',
                    geometry: { type: 'Point', coordinates: [avgLng, avgLat] },
                    properties: {
                        label_text: posName,
                        color: group.color,
                        config_name: group.config_name,
                        artcc: group.artcc,
                        frequency: group.frequency,
                        sector_count: group.centroids.length,
                        // Store which strata this position has (for filtering)
                        has_low: group.strata.has('low'),
                        has_high: group.strata.has('high'),
                        has_superhigh: group.strata.has('superhigh')
                    }
                });
            });
            
            console.log(`[NOD] Created ${labelFeatures.length} consolidated labels for ${positionGroups.size} positions`);
            
            // Update map sources
            if (state.map && state.map.getSource('splits-source')) {
                state.map.getSource('splits-source').setData({
                    type: 'FeatureCollection',
                    features: features
                });
            }
            
            // Update labels source (separate from polygons)
            if (state.map && state.map.getSource('splits-labels-source')) {
                state.map.getSource('splits-labels-source').setData({
                    type: 'FeatureCollection',
                    features: labelFeatures
                });
            }
            
            // Apply current strata filter to the newly loaded data
            applySplitsStrataFilter();
            
            // Store for reference
            state.splits = {
                configs: activeConfigs,
                sectorMap: sectorPositionMap,
                featureCount: features.length
            };
            
            // Update stats to show splits count
            updateStats();
            
            console.log(`[NOD] Loaded ${features.length} split sectors from ${artccsNeeded.size} ARTCC(s)`);
            
        } catch (error) {
            console.log('[NOD] Splits data not available:', error.message);
            console.error(error);
        }
    }
    
    /**
     * Calculate centroid of a polygon geometry
     */
    function calculatePolygonCentroid(geometry) {
        if (!geometry || !geometry.coordinates) return null;
        
        try {
            // Handle Polygon and MultiPolygon
            let ring;
            if (geometry.type === 'Polygon') {
                ring = geometry.coordinates[0]; // Outer ring
            } else if (geometry.type === 'MultiPolygon') {
                ring = geometry.coordinates[0][0]; // First polygon's outer ring
            } else {
                return null;
            }
            
            if (!ring || ring.length < 3) return null;
            
            // Calculate bounding box centroid (simpler and faster than true centroid)
            let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
            for (const coord of ring) {
                if (Array.isArray(coord) && coord.length >= 2) {
                    minX = Math.min(minX, coord[0]);
                    maxX = Math.max(maxX, coord[0]);
                    minY = Math.min(minY, coord[1]);
                    maxY = Math.max(maxY, coord[1]);
                }
            }
            
            if (!isFinite(minX)) return null;
            return [(minX + maxX) / 2, (minY + maxY) / 2];
        } catch (e) {
            return null;
        }
    }
    
    /**
     * Generate position color based on name if not specified
     */
    function getPositionColor(positionName) {
        if (!positionName) return '#6c757d';
        
        const name = positionName.toUpperCase();
        
        // Common position color assignments
        const colorMap = {
            'NORTH': '#28a745', 'N': '#28a745',
            'SOUTH': '#dc3545', 'S': '#dc3545',
            'EAST': '#17a2b8', 'E': '#17a2b8',
            'WEST': '#ffc107', 'W': '#ffc107',
            'CENTRAL': '#6f42c1', 'C': '#6f42c1',
            'HIGH': '#fd7e14', 'LOW': '#20c997', 'SUPERHIGH': '#e83e8c'
        };
        
        for (const [key, color] of Object.entries(colorMap)) {
            if (name.includes(key)) return color;
        }
        
        // Hash-based color for unknown positions
        let hash = 0;
        for (let i = 0; i < name.length; i++) {
            hash = name.charCodeAt(i) + ((hash << 5) - hash);
        }
        const hue = Math.abs(hash) % 360;
        return `hsl(${hue}, 70%, 50%)`;
    }
    
    // =========================================
    // Map Layer Updates
    // =========================================
    
    function updateTrafficLayer() {
        if (!state.map || !state.map.getSource('traffic-source')) return;

        const features = state.traffic.filteredData
            .filter(f => f.lat && f.lon)
            .map(flight => {
                // Determine normalized weight class
                const weightClass = getWeightClass(flight);

                // Clean aircraft type - strip equipment suffixes (e.g., B738/L -> B738)
                const rawAcType = flight.aircraft_icao || flight.aircraft_type || '';
                const cleanAcType = stripAircraftSuffixes(rawAcType);

                return {
                    type: 'Feature',
                    geometry: {
                        type: 'Point',
                        coordinates: [parseFloat(flight.lon), parseFloat(flight.lat)]
                    },
                    properties: {
                        callsign: flight.callsign,
                        color: getFlightColor(flight),
                        altitude: flight.altitude_ft || flight.altitude,
                        speed: flight.groundspeed_kts || flight.groundspeed,
                        origin: flight.fp_dept_icao || flight.dep,
                        dest: flight.fp_dest_icao || flight.arr,
                        ac_type: cleanAcType,
                        raw_weight_class: flight.weight_class || '',
                        weight_class: weightClass,
                        heading: parseInt(flight.heading_deg) || 0,
                        status: flight.phase || flight.status,
                        dep_artcc: flight.fp_dept_artcc || flight.dep_artcc,
                        arr_artcc: flight.fp_dest_artcc || flight.arr_artcc,
                        current_artcc: flight.current_artcc,
                        route: flight.fp_route || flight.route,
                        gs_affected: flight.gs_affected || flight.gs_flag,
                        gdp_affected: flight.gdp_affected || flight.gdp_flag,
                        edct_issued: flight.edct_issued
                    }
                };
            });
        
        state.map.getSource('traffic-source').setData({
            type: 'FeatureCollection',
            features: features
        });
        
        // Refresh tracks if enabled
        if (state.traffic.showTracks) {
            loadTracks();
        }
    }
    
    // =========================================
    // Flight Filtering (matches route-maplibre.js)
    // =========================================
    
    function applyFilters() {
        const f = state.traffic.filters;
        state.traffic.filteredData = state.traffic.data.filter(flight => {
            // Weight class filter - getWeightClass() returns normalized SUPER/HEAVY/LARGE/SMALL
            const wc = getWeightClass(flight);
            const wcMatch = f.weightClasses.some(w => {
                if (w === wc) return true;
                // Map filter aliases to normalized form (wc is always normalized)
                if ((w === 'SUPER' || w === 'J') && wc === 'SUPER') return true;
                if ((w === 'HEAVY' || w === 'H') && wc === 'HEAVY') return true;
                if ((w === 'LARGE' || w === 'L' || w === '') && wc === 'LARGE') return true;
                if ((w === 'SMALL' || w === 'S') && wc === 'SMALL') return true;
                return false;
            });
            if (!wcMatch) return false;
            
            // Origin filter (airport or ARTCC)
            if (f.origin && f.origin.length > 0) {
                const deptIcao = (flight.fp_dept_icao || '').toUpperCase().trim();
                const deptArtcc = (flight.fp_dept_artcc || '').toUpperCase().trim();
                if (!(deptIcao.includes(f.origin) || deptArtcc.includes(f.origin))) return false;
            }
            
            // Destination filter (airport or ARTCC)
            if (f.dest && f.dest.length > 0) {
                const destIcao = (flight.fp_dest_icao || '').toUpperCase().trim();
                const destArtcc = (flight.fp_dest_artcc || '').toUpperCase().trim();
                if (!(destIcao.includes(f.dest) || destArtcc.includes(f.dest))) return false;
            }
            
            // Carrier filter
            if (f.carrier && f.carrier.length > 0) {
                const airlineIcao = (flight.airline_icao || '').toUpperCase().trim();
                const callsign = (flight.callsign || '').toUpperCase().trim();
                // Match carrier prefix in airline_icao or callsign
                if (!(airlineIcao.startsWith(f.carrier) || callsign.startsWith(f.carrier))) return false;
            }
            
            // Altitude filter (in feet)
            if (f.altitudeMin !== null) {
                const alt = parseInt(flight.altitude_ft || flight.altitude) || 0;
                if (alt < f.altitudeMin) return false;
            }
            if (f.altitudeMax !== null) {
                const alt = parseInt(flight.altitude_ft || flight.altitude) || 0;
                if (alt > f.altitudeMax) return false;
            }
            
            return true;
        });
        
        console.log(`[NOD] Filtered ${state.traffic.filteredData.length} of ${state.traffic.data.length} flights`);
    }
    
    function collectFiltersFromUI() {
        // Weight class checkboxes
        const wcs = [];
        if ($('#nod_wc_super').is(':checked')) wcs.push('SUPER', 'J');
        if ($('#nod_wc_heavy').is(':checked')) wcs.push('HEAVY', 'H');
        if ($('#nod_wc_large').is(':checked')) wcs.push('LARGE', 'L', '');
        if ($('#nod_wc_small').is(':checked')) wcs.push('SMALL', 'S');
        state.traffic.filters.weightClasses = wcs.length > 0 ? wcs : ['SUPER', 'HEAVY', 'LARGE', 'SMALL', 'J', 'H', 'L', 'S', ''];
        
        // Text filters
        state.traffic.filters.origin = ($('#nod_filter_origin').val() || '').toUpperCase().trim();
        state.traffic.filters.dest = ($('#nod_filter_dest').val() || '').toUpperCase().trim();
        state.traffic.filters.carrier = ($('#nod_filter_carrier').val() || '').toUpperCase().trim();
        
        // Altitude filters
        const altMin = parseInt($('#nod_filter_alt_min').val());
        const altMax = parseInt($('#nod_filter_alt_max').val());
        state.traffic.filters.altitudeMin = !isNaN(altMin) ? altMin * 100 : null;  // FL to feet
        state.traffic.filters.altitudeMax = !isNaN(altMax) ? altMax * 100 : null;
        
        applyFilters();
        updateTrafficLayer();
        updateStats();
    }
    
    function resetFilters() {
        state.traffic.filters = {
            weightClasses: ['SUPER', 'HEAVY', 'LARGE', 'SMALL', 'J', 'H', 'L', 'S', ''],
            origin: '',
            dest: '',
            carrier: '',
            altitudeMin: null,
            altitudeMax: null
        };
        
        // Reset UI inputs
        $('#nod_wc_super, #nod_wc_heavy, #nod_wc_large, #nod_wc_small').prop('checked', true);
        $('#nod_filter_origin, #nod_filter_dest, #nod_filter_carrier, #nod_filter_alt_min, #nod_filter_alt_max').val('');
        
        applyFilters();
        updateTrafficLayer();
        updateStats();
    }
    
    /**
     * Strip ICAO equipment/navigation suffixes from aircraft type
     * e.g., B738/L -> B738, A320/G -> A320, C172/U -> C172
     */
    function stripAircraftSuffixes(acType) {
        if (!acType) return '';
        // Remove everything after first slash (equipment suffix)
        // Also handles formats like B738-L or B738_L
        return acType.split(/[\/\-_]/)[0].toUpperCase();
    }
    
    /**
     * Determine weight class from flight data
     * Normalizes weight_class codes: J->SUPER, H->HEAVY, L->LARGE, S->SMALL
     * FSM Table 3-6: SUPER/J, HEAVY/H, LARGE/L, SMALL/S
     */
    function getWeightClass(flight) {
        if (flight.weight_class) {
            const wc = flight.weight_class.toUpperCase();
            if (['SUPER', 'J', 'JUMBO'].includes(wc)) return 'SUPER';
            if (['HEAVY', 'H'].includes(wc)) return 'HEAVY';
            if (['LARGE', 'L'].includes(wc)) return 'LARGE';
            if (['SMALL', 'S'].includes(wc)) return 'SMALL';
        }

        // Default to LARGE for unknown/jets
        return 'LARGE';
    }
    
    function getFlightColor(flight) {
        const mode = state.traffic.colorMode;
        
        switch (mode) {
            case 'status':
                // Check TMI status flags first (highest priority)
                if (flight.gs_affected || flight.ground_stop_affected) return '#dc3545';  // Red - Ground stopped
                if (flight.gdp_affected || flight.edct_issued) return '#ffc107';  // Yellow - EDCT

                // Get flight phase - ADL uses: prefile, taxiing, departed, enroute, descending, arrived
                const phase = (flight.phase || flight.status || '').toLowerCase();

                // Airborne/Enroute
                if (phase === 'enroute') {
                    return '#28a745';  // Green - Enroute
                }
                // Departing/Taxiing - on ground at origin
                if (phase === 'departed' || phase === 'taxiing') {
                    return '#17a2b8';  // Cyan - Departing/Taxiing
                }
                // Descending - approaching destination
                if (phase === 'descending') {
                    return '#f28e2b';  // Orange - Descending
                }
                // Arrived - on ground at destination or disconnected
                if (phase === 'arrived') {
                    return '#6c757d';  // Gray - Arrived
                }
                // Proposed/Prefile - no position yet
                if (phase === 'prefile' || phase === 'unknown' || phase === '' || !phase) {
                    return '#b07aa1';  // Purple - Prefile
                }
                return '#ffffff';
                
            case 'altitude':
                return getAltitudeBlockColor(flight.altitude_ft || flight.altitude);
                
            case 'weight_class':
                const wc = getWeightClass(flight);
                return WEIGHT_CLASS_COLORS[wc] || WEIGHT_CLASS_COLORS[''];
                
            case 'aircraft_category':
            case 'ac_cat':
                // Use raw weight_class field (J/H/L/S or SUPER/HEAVY/LARGE/SMALL)
                const cat = (flight.weight_class || '').toUpperCase();
                if (cat === 'J' || cat === 'SUPER') return WEIGHT_CLASS_COLORS['SUPER'];
                if (cat === 'H' || cat === 'HEAVY') return WEIGHT_CLASS_COLORS['HEAVY'];
                if (cat === 'L' || cat === 'LARGE') return WEIGHT_CLASS_COLORS['LARGE'];
                if (cat === 'S' || cat === 'SMALL' || cat === 'P' || cat === 'PROP') return WEIGHT_CLASS_COLORS['SMALL'];
                return '#6c757d';
                
            case 'carrier':
                return getCarrierColor(flight.callsign);
                
            case 'dep_center':
                return getCenterColor(flight.fp_dept_artcc || flight.dep_artcc);
                
            case 'arr_center':
                return getCenterColor(flight.fp_dest_artcc || flight.arr_artcc);
                
            case 'center':
                return getCenterColor(flight.current_artcc || flight.dep_artcc);
                
            case 'dcc_region':
                const center = (flight.fp_dest_artcc || flight.arr_artcc || '').toUpperCase();
                for (const [region, centers] of Object.entries(DCC_REGIONS)) {
                    if (centers.includes(center)) {
                        return DCC_REGION_COLORS[region];
                    }
                }
                return DCC_REGION_COLORS[''];
                
            case 'arr_dep':
                const altVal = parseInt(flight.altitude_ft || flight.altitude) || 0;
                if (flight.groundspeed && parseInt(flight.groundspeed) < 50) {
                    return '#666666'; // Parked
                }
                return altVal > 10000 ? ARR_DEP_COLORS['ARR'] : ARR_DEP_COLORS['DEP'];
                
            case 'eta_relative':
                return getEtaRelativeColor(flight.eta_runway_utc || flight.eta_utc);
                
            case 'eta_hour':
                return getEtaHourColor(flight.eta_runway_utc || flight.eta_utc);
                
            case 'speed':
                const spd = parseInt(flight.groundspeed_kts || flight.groundspeed) || 0;
                if (spd < 50) return SPEED_COLORS['GROUND'];
                if (spd < 150) return SPEED_COLORS['SLOW'];
                if (spd < 250) return SPEED_COLORS['MEDIUM'];
                if (spd < 350) return SPEED_COLORS['FAST'];
                if (spd < 450) return SPEED_COLORS['VFAST'];
                if (spd < 550) return SPEED_COLORS['JET'];
                return SPEED_COLORS['SUPERSONIC'];
                
            case 'dep_airport':
                return getAirportTierColor(flight.fp_dept_icao);
                
            case 'arr_airport':
                return getAirportTierColor(flight.fp_dest_icao);
                
            case 'dep_tracon':
            case 'arr_tracon':
                // Use DCC region color based on ARTCC
                const artcc = mode === 'dep_tracon' 
                    ? (flight.fp_dept_artcc || flight.dep_artcc) 
                    : (flight.fp_dest_artcc || flight.arr_artcc);
                const artccUpper = (artcc || '').toUpperCase();
                for (const [region, centers] of Object.entries(DCC_REGIONS)) {
                    if (centers.includes(artccUpper)) {
                        return DCC_REGION_COLORS[region];
                    }
                }
                return DCC_REGION_COLORS[''];
                
            case 'aircraft_type':
                // Prefer aircraft_icao (clean code from DB), fallback to flight plan fields
                const acType = stripAircraftSuffixes(flight.aircraft_icao || flight.aircraft_type || '');
                const mfr = getAircraftManufacturer(acType);
                return AIRCRAFT_MANUFACTURER_COLORS[mfr] || AIRCRAFT_MANUFACTURER_COLORS['OTHER'];

            case 'aircraft_config':
                // Prefer aircraft_icao (clean code from DB), fallback to flight plan fields
                const acType2 = stripAircraftSuffixes(flight.aircraft_icao || flight.aircraft_type || '');
                const cfg = getAircraftConfig(acType2);
                return AIRCRAFT_CONFIG_COLORS[cfg] || AIRCRAFT_CONFIG_COLORS['OTHER'];
                
            case 'wake_category':
                // FAA RECAT wake turbulence categories (A-F)
                const recat = getRecatCategory(flight);
                return RECAT_COLORS[recat] || RECAT_COLORS[''];

            case 'operator_group':
                const opGroup = getOperatorGroup(flight.callsign);
                return OPERATOR_GROUP_COLORS[opGroup] || OPERATOR_GROUP_COLORS['OTHER'];
                
            case 'reroute_match':
                return getRerouteMatchColor(flight);
                
            default:
                return '#ffffff';
        }
    }
    
    // Helper: Altitude block color
    function getAltitudeBlockColor(altitude) {
        const alt = parseInt(altitude) || 0;
        const fl = alt / 100;
        if (fl < 5) return ALTITUDE_BLOCK_COLORS['GROUND'];
        if (fl < 10) return ALTITUDE_BLOCK_COLORS['SURFACE'];
        if (fl < 100) return ALTITUDE_BLOCK_COLORS['LOW'];
        if (fl < 180) return ALTITUDE_BLOCK_COLORS['LOWMED'];
        if (fl < 240) return ALTITUDE_BLOCK_COLORS['MED'];
        if (fl < 290) return ALTITUDE_BLOCK_COLORS['MEDHIGH'];
        if (fl < 350) return ALTITUDE_BLOCK_COLORS['HIGH'];
        if (fl < 410) return ALTITUDE_BLOCK_COLORS['VHIGH'];
        return ALTITUDE_BLOCK_COLORS['SUPERHIGH'];
    }
    
    // Helper: ETA relative color - discrete buckets matching ETA_RELATIVE_COLORS
    function getEtaRelativeColor(etaUtc) {
        if (!etaUtc) return '#6c757d';
        const now = new Date();
        const eta = new Date(etaUtc);
        const diffMin = (eta - now) / 60000;

        if (diffMin <= 0) return '#6c757d';       // Past ETA - gray
        if (diffMin <= 15) return ETA_RELATIVE_COLORS['ETA_15'];   // ≤15 min - red
        if (diffMin <= 30) return ETA_RELATIVE_COLORS['ETA_30'];   // 15-30 min - orange
        if (diffMin <= 60) return ETA_RELATIVE_COLORS['ETA_60'];   // 30-60 min - yellow
        if (diffMin <= 120) return ETA_RELATIVE_COLORS['ETA_120']; // 1-2 hr - green
        if (diffMin <= 180) return ETA_RELATIVE_COLORS['ETA_180']; // 2-3 hr - cyan
        if (diffMin <= 300) return ETA_RELATIVE_COLORS['ETA_300']; // 3-5 hr - blue
        if (diffMin <= 480) return ETA_RELATIVE_COLORS['ETA_480']; // 5-8 hr - purple
        return ETA_RELATIVE_COLORS['ETA_OVER'];                    // >8 hr - gray
    }
    
    // Helper: ETA hour color - cyclical spectral colormap
    function getEtaHourColor(etaUtc) {
        if (!etaUtc) return '#6c757d';
        const eta = new Date(etaUtc);
        const hour = eta.getUTCHours();
        const minute = eta.getUTCMinutes();
        
        // Convert to fraction of day (0-1), cyclical
        const dayFraction = (hour + minute / 60) / 24;
        
        // Use HSL with full hue rotation for cyclical coloring
        // Shift so midnight is at red (hue 0)
        const hue = dayFraction * 360;
        return `hsl(${hue}, 85%, 50%)`;
    }
    
    /**
     * Spectral color interpolation (red → orange → yellow → green → cyan → blue)
     * @param {number} t - Value from 0 (red/close) to 1 (blue/far)
     * @returns {string} Hex color
     */
    function getSpectralColor(t) {
        // Clamp t to 0-1
        t = Math.max(0, Math.min(1, t));
        
        // Spectral color stops
        const stops = [
            { t: 0.00, r: 255, g: 0,   b: 0   },   // Red
            { t: 0.20, r: 255, g: 128, b: 0   },   // Orange
            { t: 0.40, r: 255, g: 255, b: 0   },   // Yellow
            { t: 0.60, r: 0,   g: 200, b: 0   },   // Green
            { t: 0.80, r: 0,   g: 200, b: 255 },   // Cyan
            { t: 1.00, r: 0,   g: 80,  b: 255 }    // Blue
        ];
        
        // Find the two stops to interpolate between
        let i = 0;
        while (i < stops.length - 1 && stops[i + 1].t < t) i++;
        
        const s1 = stops[i];
        const s2 = stops[Math.min(i + 1, stops.length - 1)];
        
        // Interpolate
        const range = s2.t - s1.t;
        const localT = range > 0 ? (t - s1.t) / range : 0;
        
        const r = Math.round(s1.r + (s2.r - s1.r) * localT);
        const g = Math.round(s1.g + (s2.g - s1.g) * localT);
        const b = Math.round(s1.b + (s2.b - s1.b) * localT);
        
        return `rgb(${r}, ${g}, ${b})`;
    }
    
    // Helper: Airport tier color
    function getAirportTierColor(icao) {
        if (!icao) return AIRPORT_TIER_COLORS['OTHER'];
        const apt = icao.toUpperCase();
        if (CORE30_AIRPORTS.includes(apt)) return AIRPORT_TIER_COLORS['CORE30'];
        if (OEP35_AIRPORTS.includes(apt)) return AIRPORT_TIER_COLORS['OEP35'];
        if (ASPM77_AIRPORTS.includes(apt)) return AIRPORT_TIER_COLORS['ASPM77'];
        return AIRPORT_TIER_COLORS['OTHER'];
    }
    
    // Helper: Aircraft manufacturer detection
    function getAircraftManufacturer(acType) {
        if (!acType) return 'OTHER';
        const type = acType.toUpperCase();
        for (const [mfr, pattern] of Object.entries(AIRCRAFT_MANUFACTURER_PATTERNS)) {
            if (pattern.test(type)) return mfr;
        }
        return 'OTHER';
    }
    
    // Helper: Aircraft configuration detection
    function getAircraftConfig(acType) {
        if (!acType) return 'OTHER';
        const type = acType.toUpperCase();
        for (const [cfg, pattern] of Object.entries(AIRCRAFT_CONFIG_PATTERNS)) {
            if (pattern.test(type)) return cfg;
        }
        return 'OTHER';
    }
    
    /**
     * Match flight against public reroutes and return matching route color
     * Matches based on origin_filter and dest_filter arrays
     * Supports airports (KJFK), ARTCCs (ZMA, ZNY), and TRACONs (A80, N90)
     */
    function getRerouteMatchColor(flight) {
        const origin = (flight.fp_dept_icao || flight.origin || '').toUpperCase();
        const dest = (flight.fp_dest_icao || flight.dest || '').toUpperCase();
        const depArtcc = (flight.fp_dept_artcc || flight.dep_artcc || '').toUpperCase();
        const arrArtcc = (flight.fp_dest_artcc || flight.arr_artcc || '').toUpperCase();
        // TRACONs if available (may come from enhanced flight data)
        const depTracon = (flight.dep_tracon || '').toUpperCase();
        const arrTracon = (flight.arr_tracon || '').toUpperCase();
        
        if (!origin && !dest && !depArtcc && !arrArtcc) return '#666666'; // Gray - no data
        
        // Known ARTCC codes pattern (3 letters starting with Z)
        const artccPattern = /^Z[A-Z]{2}$/;
        // TRACON codes pattern (letter + 2 digits OR 3 letters like NCT, PCT, SCT)
        const traconPattern = /^[A-Z][0-9]{2}$|^(NCT|PCT|SCT|A80|N90|C90|D10|I90|L30)$/;
        
        /**
         * Check if a flight matches a single filter value
         * Filter can be: airport (KJFK), ARTCC (ZMA), or TRACON (A80)
         */
        function matchesFilter(filterValue, flightOrigin, flightDest, flightDepArtcc, flightArrArtcc, flightDepTracon, flightArrTracon, isOrigin) {
            if (!filterValue) return false;
            
            const f = filterValue.toUpperCase().trim();
            const airport = isOrigin ? flightOrigin : flightDest;
            const artcc = isOrigin ? flightDepArtcc : flightArrArtcc;
            const tracon = isOrigin ? flightDepTracon : flightArrTracon;
            
            // Direct airport match
            if (airport === f) return true;
            
            // ARTCC match - filter is an ARTCC code
            if (artccPattern.test(f) && artcc === f) return true;
            
            // TRACON match - filter is a TRACON code
            if (traconPattern.test(f) && tracon === f) return true;
            
            // Support prefix matching (e.g., "K" matches all K-prefixed airports)
            if (f.length <= 2 && airport.startsWith(f)) return true;
            
            // For backwards compatibility: also check if flight's ARTCC matches filter directly
            // This handles cases where filter might be "ZMA" and we check against depArtcc
            if (artcc === f) return true;
            
            return false;
        }
        
        // Filter to only active and future routes (not expired)
        const now = new Date();
        const activeRoutes = state.tmi.publicRoutes.filter(route => {
            // Check if route has ended
            if (route.valid_end_utc) {
                const endTime = new Date(route.valid_end_utc);
                if (endTime < now) return false; // Expired
            }
            // Check computed_status if available
            if (route.computed_status === 'past' || route.computed_status === 'expired') {
                return false;
            }
            return true;
        });
        
        // Check each active public route for a match
        for (const route of activeRoutes) {
            const originFilter = route.origin_filter || [];
            const destFilter = route.dest_filter || [];
            
            // Parse filters if they're strings
            const origins = Array.isArray(originFilter) ? originFilter : 
                            (typeof originFilter === 'string' ? [originFilter] : []);
            const dests = Array.isArray(destFilter) ? destFilter : 
                          (typeof destFilter === 'string' ? [destFilter] : []);
            
            // Normalize filter values to uppercase
            const normalizedOrigins = origins.map(o => (o || '').toUpperCase().trim()).filter(o => o);
            const normalizedDests = dests.map(d => (d || '').toUpperCase().trim()).filter(d => d);
            
            // Check origin match - requires non-empty origin filter to match
            let originMatch = false;
            if (normalizedOrigins.length > 0) {
                for (const o of normalizedOrigins) {
                    if (matchesFilter(o, origin, dest, depArtcc, arrArtcc, depTracon, arrTracon, true)) {
                        originMatch = true;
                        break;
                    }
                }
            }
            
            // Check dest match - requires non-empty dest filter to match
            let destMatch = false;
            if (normalizedDests.length > 0) {
                for (const d of normalizedDests) {
                    if (matchesFilter(d, origin, dest, depArtcc, arrArtcc, depTracon, arrTracon, false)) {
                        destMatch = true;
                        break;
                    }
                }
            }
            
            // If both origin and dest match (requires both filters to be non-empty)
            if (originMatch && destMatch) {
                return route.color || '#17a2b8';
            }
        }
        
        // No match - return lighter gray for better visibility
        return '#666666';
    }
    
    /**
     * Get active (non-expired) public routes for legend display
     */
    function getActivePublicRoutes() {
        const now = new Date();
        return state.tmi.publicRoutes.filter(route => {
            if (route.valid_end_utc) {
                const endTime = new Date(route.valid_end_utc);
                if (endTime < now) return false;
            }
            if (route.computed_status === 'past' || route.computed_status === 'expired') {
                return false;
            }
            return true;
        });
    }
    
    /**
     * Get list of active routes that a flight matches
     * Used for popup display
     * Supports airports (KJFK), ARTCCs (ZMA, ZNY), and TRACONs (A80, N90)
     */
    function getMatchingRoutes(flight) {
        const matches = [];
        const origin = (flight.fp_dept_icao || flight.origin || '').toUpperCase();
        const dest = (flight.fp_dest_icao || flight.dest || '').toUpperCase();
        const depArtcc = (flight.fp_dept_artcc || flight.dep_artcc || '').toUpperCase();
        const arrArtcc = (flight.fp_dest_artcc || flight.arr_artcc || '').toUpperCase();
        const depTracon = (flight.dep_tracon || '').toUpperCase();
        const arrTracon = (flight.arr_tracon || '').toUpperCase();
        
        // Known ARTCC codes pattern (3 letters starting with Z)
        const artccPattern = /^Z[A-Z]{2}$/;
        // TRACON codes pattern
        const traconPattern = /^[A-Z][0-9]{2}$|^(NCT|PCT|SCT|A80|N90|C90|D10|I90|L30)$/;
        
        /**
         * Check if a flight matches a filter value (used in both origin and dest checks)
         */
        function matchesFilter(filterValue, isOrigin) {
            if (!filterValue) return false;
            
            const f = filterValue.toUpperCase().trim();
            const airport = isOrigin ? origin : dest;
            const artcc = isOrigin ? depArtcc : arrArtcc;
            const tracon = isOrigin ? depTracon : arrTracon;
            
            // Direct airport match
            if (airport === f) return true;
            
            // ARTCC match
            if (artccPattern.test(f) && artcc === f) return true;
            if (artcc === f) return true;  // Also handle non-pattern ARTCC matches
            
            // TRACON match
            if (traconPattern.test(f) && tracon === f) return true;
            
            // Prefix matching (e.g., "K" matches all K-prefixed airports)
            if (f.length <= 2 && airport.startsWith(f)) return true;
            
            return false;
        }
        
        for (const route of state.tmi.publicRoutes) {
            const originFilter = route.origin_filter || [];
            const destFilter = route.dest_filter || [];
            
            const origins = Array.isArray(originFilter) ? originFilter : 
                            (typeof originFilter === 'string' ? [originFilter] : []);
            const dests = Array.isArray(destFilter) ? destFilter : 
                          (typeof destFilter === 'string' ? [destFilter] : []);
            
            const normalizedOrigins = origins.map(o => (o || '').toUpperCase().trim()).filter(o => o);
            const normalizedDests = dests.map(d => (d || '').toUpperCase().trim()).filter(d => d);
            
            // Check origin match - requires non-empty origin filter
            let originMatch = false;
            if (normalizedOrigins.length > 0) {
                for (const o of normalizedOrigins) {
                    if (matchesFilter(o, true)) {
                        originMatch = true;
                        break;
                    }
                }
            }
            
            // Check dest match - requires non-empty dest filter
            let destMatch = false;
            if (normalizedDests.length > 0) {
                for (const d of normalizedDests) {
                    if (matchesFilter(d, false)) {
                        destMatch = true;
                        break;
                    }
                }
            }
            
            if (originMatch && destMatch) {
                matches.push({
                    name: route.name,
                    color: route.color || '#17a2b8'
                });
            }
        }
        
        return matches;
    }
    
    // Helper: Extract carrier code from callsign
    function extractCarrier(callsign) {
        if (!callsign) return '';
        const match = callsign.match(/^([A-Z]{3})/);
        return match ? match[1] : '';
    }
    
    // Helper: Operator group detection
    function getOperatorGroup(callsign) {
        if (!callsign) return 'OTHER';
        const cs = callsign.toUpperCase();
        const carrier = extractCarrier(callsign);

        // Check carriers first (most common)
        if (MAJOR_CARRIERS.includes(carrier)) return 'MAJOR';
        if (REGIONAL_CARRIERS.includes(carrier)) return 'REGIONAL';
        if (FREIGHT_CARRIERS.includes(carrier)) return 'FREIGHT';

        // Military detection - multiple methods:

        // 1. US Military tail number patterns (letter prefix + digits)
        //    A + 5-6 digits = Air Force (e.g., A12345)
        //    VV + 3-4 digits = Navy (e.g., VV123)
        //    R + digits = Army
        //    AF + 1-4 digits = Air Force (but NOT AFR/AFL which are airlines)
        //    CG + digits = Coast Guard (but NOT airline codes starting with CG)
        if (/^A[0-9]{5,6}$/.test(cs)) return 'MILITARY';      // Air Force tail
        if (/^VV[0-9]{3,4}$/.test(cs)) return 'MILITARY';     // Navy tail
        if (/^R[0-9]{5,6}$/.test(cs)) return 'MILITARY';      // Army tail
        if (/^AF[0-9]{1,4}$/.test(cs)) return 'MILITARY';     // Air Force (AF + digits only)
        if (/^CG[0-9]{2,5}$/.test(cs)) return 'MILITARY';     // Coast Guard (CG + digits)
        if (/^VR[0-9]{2,4}$/.test(cs)) return 'MILITARY';     // Navy Fleet Logistics (VR + digits)

        // 2. Check explicit military prefixes (startsWith for flexibility)
        //    Only check prefixes 3+ chars to avoid airline conflicts (AF->AFR, CG->CGA, etc.)
        for (const prefix of MILITARY_PREFIXES) {
            if (prefix.length >= 3 && cs.startsWith(prefix)) return 'MILITARY';
        }

        // 3. Military-style callsigns: word + numbers (e.g., REACH01, KING21, NAVY42)
        if (/^[A-Z]{3,7}[0-9]{1,3}$/.test(cs) && !carrier) {
            // Check if the word part matches any military prefix
            const wordPart = cs.replace(/[0-9]+$/, '');
            if (MILITARY_PREFIXES.includes(wordPart)) return 'MILITARY';
        }

        // 4. Check for common military tail number patterns
        //    US military often uses 5-6 digit tail numbers without letters
        if (/^[0-9]{5,6}$/.test(cs)) return 'MILITARY';

        // GA detection: N-numbers (US civil), short callsigns, or tail number patterns
        if (/^N[0-9]/.test(cs) || callsign.length <= 5) return 'GA';

        return 'OTHER';
    }
    
    // Carrier color using constant
    function getCarrierColor(callsign) {
        if (!callsign) return CARRIER_COLORS[''];
        const prefix = callsign.substring(0, 3).toUpperCase();
        return CARRIER_COLORS[prefix] || CARRIER_COLORS[''];
    }
    
    // ARTCC color using constant
    function getCenterColor(artcc) {
        if (!artcc) return CENTER_COLORS[''];
        return CENTER_COLORS[artcc.toUpperCase()] || CENTER_COLORS[''];
    }
    
    function updatePublicRoutesLayer() {
        if (!state.map || !state.map.getSource('public-routes-source')) return;
        
        console.log('[NOD] Updating public routes layer');
        console.log('[NOD] Public routes:', state.tmi.publicRoutes.length);
        console.log('[NOD] Reroutes:', state.tmi.reroutes.length);
        
        const features = [];
        const labelFeatures = [];
        
        // Add public routes with their stored GeoJSON
        state.tmi.publicRoutes.forEach(route => {
            console.log('[NOD] Route:', route.name, 'has route_geojson:', !!route.route_geojson, 
                        typeof route.route_geojson);
            
            let geojson = route.route_geojson;
            
            // Handle both string and object formats
            if (typeof geojson === 'string' && geojson.length > 0) {
                try {
                    geojson = JSON.parse(geojson);
                } catch (e) {
                    console.warn('[NOD] Could not parse route GeoJSON string:', e);
                    geojson = null;
                }
            }
            
            const routeColor = route.color || '#17a2b8';
            const routeWeight = route.line_weight || 3;
            let labelAdded = false;
            
            if (geojson && geojson.features) {
                geojson.features.forEach(f => {
                    // Preserve solid/isFan properties from source GeoJSON
                    f.properties = {
                        ...f.properties,
                        color: routeColor,
                        weight: routeWeight,
                        name: route.name,
                        routeType: 'public',
                        solid: f.properties?.solid !== undefined ? f.properties.solid : true,
                        isFan: f.properties?.isFan || false
                    };
                    features.push(f);
                    
                    // Create label point from first feature midpoint
                    if (!labelAdded && f.geometry?.coordinates?.length > 1) {
                        const coords = f.geometry.coordinates;
                        const midIdx = Math.floor(coords.length / 2);
                        const midCoord = coords[midIdx];
                        if (midCoord) {
                            labelFeatures.push({
                                type: 'Feature',
                                geometry: { type: 'Point', coordinates: midCoord },
                                properties: { name: route.name, color: routeColor }
                            });
                            labelAdded = true;
                        }
                    }
                });
            } else if (geojson && geojson.type === 'Feature') {
                // Single feature
                geojson.properties = {
                    ...geojson.properties,
                    color: routeColor,
                    weight: routeWeight,
                    name: route.name,
                    routeType: 'public',
                    solid: geojson.properties?.solid !== undefined ? geojson.properties.solid : true,
                    isFan: geojson.properties?.isFan || false
                };
                features.push(geojson);
                
                // Create label point from feature midpoint
                if (geojson.geometry?.coordinates?.length > 1) {
                    const coords = geojson.geometry.coordinates;
                    const midIdx = Math.floor(coords.length / 2);
                    const midCoord = coords[midIdx];
                    if (midCoord) {
                        labelFeatures.push({
                            type: 'Feature',
                            geometry: { type: 'Point', coordinates: midCoord },
                            properties: { name: route.name, color: routeColor }
                        });
                    }
                }
            } else {
                console.log('[NOD] Route has no displayable GeoJSON:', route.name);
            }
        });
        
        // Also add active TMI reroutes
        state.tmi.reroutes.forEach(route => {
            console.log('[NOD] Reroute:', route.name, 'has route_geojson:', !!route.route_geojson);
            
            let geojson = route.route_geojson;
            
            if (typeof geojson === 'string' && geojson.length > 0) {
                try {
                    geojson = JSON.parse(geojson);
                } catch (e) {
                    console.warn('[NOD] Could not parse reroute GeoJSON string:', e);
                    geojson = null;
                }
            }
            
            const rerouteColor = route.color || '#fd7e14';
            let labelAdded = false;
            
            if (geojson && geojson.features) {
                geojson.features.forEach(f => {
                    f.properties = {
                        ...f.properties,
                        color: rerouteColor,
                        weight: 2,
                        name: route.name,
                        routeType: 'reroute',
                        solid: f.properties?.solid !== undefined ? f.properties.solid : true,
                        isFan: f.properties?.isFan || false
                    };
                    features.push(f);
                    
                    // Create label point
                    if (!labelAdded && f.geometry?.coordinates?.length > 1) {
                        const coords = f.geometry.coordinates;
                        const midIdx = Math.floor(coords.length / 2);
                        const midCoord = coords[midIdx];
                        if (midCoord) {
                            labelFeatures.push({
                                type: 'Feature',
                                geometry: { type: 'Point', coordinates: midCoord },
                                properties: { name: route.name, color: rerouteColor }
                            });
                            labelAdded = true;
                        }
                    }
                });
            } else if (geojson && geojson.type === 'Feature') {
                geojson.properties = {
                    ...geojson.properties,
                    color: rerouteColor,
                    weight: 2,
                    name: route.name,
                    routeType: 'reroute',
                    solid: geojson.properties?.solid !== undefined ? geojson.properties.solid : true,
                    isFan: geojson.properties?.isFan || false
                };
                features.push(geojson);
                
                // Create label point
                if (geojson.geometry?.coordinates?.length > 1) {
                    const coords = geojson.geometry.coordinates;
                    const midIdx = Math.floor(coords.length / 2);
                    const midCoord = coords[midIdx];
                    if (midCoord) {
                        labelFeatures.push({
                            type: 'Feature',
                            geometry: { type: 'Point', coordinates: midCoord },
                            properties: { name: route.name, color: rerouteColor }
                        });
                    }
                }
            }
        });
        
        console.log('[NOD] Total route features:', features.length, 'labels:', labelFeatures.length);
        
        state.map.getSource('public-routes-source').setData({
            type: 'FeatureCollection',
            features: features
        });
        
        // Update labels source (keep for fallback/backup)
        if (state.map.getSource('public-routes-labels-source')) {
            state.map.getSource('public-routes-labels-source').setData({
                type: 'FeatureCollection',
                features: labelFeatures
            });
        }
        
        // Create/update DOM-based draggable labels
        updateRouteLabelMarkers(labelFeatures);
    }
    
    // Cache for route label state (position + hidden) - preserved across refreshes
    let routeLabelState = {};
    
    // Load saved label state from localStorage on init
    function loadRouteLabelState() {
        try {
            const saved = localStorage.getItem('nod_route_label_state');
            if (saved) {
                routeLabelState = JSON.parse(saved);
                console.log('[NOD] Loaded route label state:', Object.keys(routeLabelState).length, 'labels');
            }
        } catch (e) { 
            console.warn('[NOD] Could not load route label state:', e);
        }
    }
    
    // Save label state to localStorage
    function saveRouteLabelState() {
        try {
            localStorage.setItem('nod_route_label_state', JSON.stringify(routeLabelState));
        } catch (e) { 
            console.warn('[NOD] Could not save route label state:', e);
        }
    }
    
    // Initialize state on load
    loadRouteLabelState();
    
    /**
     * Create/update DOM-based draggable route labels
     * Preserves user-moved positions and hidden state across refreshes
     */
    function updateRouteLabelMarkers(labelFeatures) {
        // Remove existing markers
        state.routeLabelMarkers.forEach(marker => marker.remove());
        state.routeLabelMarkers = [];
        
        // Ensure symbol layer stays hidden (we only use DOM markers)
        if (state.map.getLayer('public-routes-labels')) {
            state.map.setLayoutProperty('public-routes-labels', 'visibility', 'none');
        }
        
        // Check if route labels should be visible at all
        const labelsVisible = state.routeLabelsVisible !== false;
        
        labelFeatures.forEach(feature => {
            const coords = feature.geometry.coordinates;
            const name = feature.properties.name;
            const color = feature.properties.color || '#17a2b8';
            const textColor = getContrastColor(color);
            
            // Get saved state for this label
            const savedState = routeLabelState[name] || {};
            
            // Skip if label was hidden by user
            if (savedState.hidden) {
                return;
            }
            
            // Use saved position or default
            const labelCoords = savedState.lng !== undefined 
                ? [savedState.lng, savedState.lat] 
                : coords;
            
            // Create label element
            const el = document.createElement('div');
            el.className = 'nod-route-label';
            el.textContent = name;
            el.style.backgroundColor = color;
            el.style.color = textColor;
            el.style.display = labelsVisible ? 'block' : 'none';
            
            // Store original coordinates for reset
            el.dataset.origLng = coords[0];
            el.dataset.origLat = coords[1];
            el.dataset.routeName = name;
            
            // Create MapLibre marker
            const marker = new maplibregl.Marker({ 
                element: el, 
                draggable: true,
                anchor: 'center'
            })
            .setLngLat(labelCoords)
            .addTo(state.map);
            
            // Save position on drag end
            marker.on('dragend', () => {
                const lngLat = marker.getLngLat();
                if (!routeLabelState[name]) routeLabelState[name] = {};
                routeLabelState[name].lng = lngLat.lng;
                routeLabelState[name].lat = lngLat.lat;
                saveRouteLabelState();
            });
            
            // Double-click to reset position to original
            el.addEventListener('dblclick', (e) => {
                e.stopPropagation();
                e.preventDefault();
                const origLng = parseFloat(el.dataset.origLng);
                const origLat = parseFloat(el.dataset.origLat);
                marker.setLngLat([origLng, origLat]);
                // Clear saved position but keep other state
                if (routeLabelState[name]) {
                    delete routeLabelState[name].lng;
                    delete routeLabelState[name].lat;
                    if (Object.keys(routeLabelState[name]).length === 0) {
                        delete routeLabelState[name];
                    }
                }
                saveRouteLabelState();
            });
            
            // Right-click to hide this label
            el.addEventListener('contextmenu', (e) => {
                e.stopPropagation();
                e.preventDefault();
                // Hide this label
                if (!routeLabelState[name]) routeLabelState[name] = {};
                routeLabelState[name].hidden = true;
                saveRouteLabelState();
                marker.remove();
                // Remove from markers array
                const idx = state.routeLabelMarkers.indexOf(marker);
                if (idx > -1) state.routeLabelMarkers.splice(idx, 1);
            });
            
            // Add drag visual feedback
            el.addEventListener('mousedown', () => el.classList.add('dragging'));
            document.addEventListener('mouseup', () => el.classList.remove('dragging'));
            
            state.routeLabelMarkers.push(marker);
        });
        
        console.log('[NOD] Created', state.routeLabelMarkers.length, 'route label markers');
    }
    
    /**
     * Reset all hidden route labels (unhide all)
     */
    function resetHiddenRouteLabels() {
        Object.keys(routeLabelState).forEach(name => {
            if (routeLabelState[name]) {
                delete routeLabelState[name].hidden;
                if (Object.keys(routeLabelState[name]).length === 0) {
                    delete routeLabelState[name];
                }
            }
        });
        saveRouteLabelState();
        // Trigger refresh of route labels
        loadTMIData();
    }
    
    async function updateIncidentsLayer() {
        if (!state.map || !state.map.getSource('incidents-source')) return;
        
        console.log('[NOD] Updating incidents layer, incidents:', state.jatoc.incidents.length);
        
        if (state.jatoc.incidents.length === 0) {
            state.map.getSource('incidents-source').setData({
                type: 'FeatureCollection',
                features: []
            });
            return;
        }
        
        const config = window.NOD_CONFIG || {};
        const paths = config.geojsonPaths || {};
        
        // Cache for boundaries
        if (!state.boundaryCache) {
            state.boundaryCache = { artcc: null, tracon: null };
        }
        
        // Load ARTCC boundaries (assets/geojson/artcc.json)
        if (!state.boundaryCache.artcc && paths.artcc) {
            try {
                const resp = await fetch(paths.artcc);
                state.boundaryCache.artcc = await resp.json();
                console.log('[NOD] Loaded ARTCC boundaries:', state.boundaryCache.artcc.features?.length);
                
                // Debug: find US ARTCCs (starting with Z or K)
                const usArtccs = state.boundaryCache.artcc.features?.filter(f => {
                    const icao = (f.properties?.ICAOCODE || '').toUpperCase();
                    return icao.startsWith('Z') || icao.startsWith('K');
                });
                console.log('[NOD] US ARTCCs found:', usArtccs?.length, usArtccs?.slice(0, 5).map(f => f.properties?.ICAOCODE));
                
                // Try to find ZBW specifically
                const zbw = state.boundaryCache.artcc.features?.find(f => {
                    const props = f.properties || {};
                    return Object.values(props).some(v => 
                        v && v.toString().toUpperCase().includes('ZBW')
                    );
                });
                if (zbw) {
                    console.log('[NOD] Found ZBW entry:', zbw.properties);
                } else {
                    console.log('[NOD] ZBW not found in artcc.json - checking all property names');
                    // Show a few samples
                    const samples = state.boundaryCache.artcc.features?.slice(0, 3).map(f => f.properties);
                    console.log('[NOD] Sample ARTCC properties:', samples);
                }
            } catch (e) {
                console.warn('[NOD] Could not load ARTCC boundaries:', e);
            }
        }
        
        // Load TRACON boundaries (assets/geojson/tracon.json)
        if (!state.boundaryCache.tracon && paths.tracon) {
            try {
                const resp = await fetch(paths.tracon);
                state.boundaryCache.tracon = await resp.json();
                console.log('[NOD] Loaded TRACON boundaries:', state.boundaryCache.tracon.features?.length);
            } catch (e) {
                console.warn('[NOD] Could not load TRACON boundaries:', e);
            }
        }
        
        const features = [];
        
        for (const inc of state.jatoc.incidents) {
            const fac = (inc.facility || '').toUpperCase().trim();
            const facType = (inc.facility_type || '').toUpperCase().trim();
            
            // Determine color based on incident type
            let color = '#ffc107'; // Default yellow
            const type = (inc.incident_type || '').toUpperCase();
            if (type.includes('ZERO')) color = '#dc3545';      // Red
            else if (type.includes('ALERT')) color = '#ffc107'; // Yellow
            else if (type.includes('LIMITED')) color = '#fd7e14'; // Orange
            else if (type.includes('NON-RESPONSIVE') || type.includes('NONRESPONSIVE')) color = '#6f42c1'; // Purple
            
            let boundaryFeature = null;
            
            // For ARTCCs - match on ICAOCODE in artcc.json
            // Note: artcc.json uses K prefix (KZBW) while JATOC uses without (ZBW)
            if (facType === 'ARTCC' || fac.startsWith('Z')) {
                // Try with K prefix first (KZBW), then without (ZBW)
                const kFac = fac.startsWith('K') ? fac : 'K' + fac;
                
                boundaryFeature = state.boundaryCache.artcc?.features?.find(f => {
                    const props = f.properties || {};
                    const icao = (props.ICAOCODE || props.icaocode || props.id || '').toUpperCase().trim();
                    return icao === kFac || icao === fac;
                });
                
                if (boundaryFeature) {
                    console.log('[NOD] Found ARTCC boundary for', fac, '(matched as', kFac, ')');
                }
            }
            
            // For TRACONs - match on sector/artcc/label in tracon.json
            if (!boundaryFeature && (facType === 'TRACON' || facType === 'RAPCON' || facType === 'APPROACH' || !fac.startsWith('Z'))) {
                boundaryFeature = state.boundaryCache.tracon?.features?.find(f => {
                    const props = f.properties || {};
                    const sector = (props.sector || '').toUpperCase().trim();
                    const artcc = (props.artcc || '').toUpperCase().trim();
                    const label = (props.label || '').toUpperCase().trim();
                    
                    return sector === fac || artcc === fac || label.includes(fac);
                });
                
                if (boundaryFeature) {
                    console.log('[NOD] Found TRACON boundary for', fac);
                }
            }
            
            // Fallback: try both if not found
            if (!boundaryFeature) {
                const kFac = fac.startsWith('K') ? fac : 'K' + fac;
                boundaryFeature = state.boundaryCache.artcc?.features?.find(f => {
                    const props = f.properties || {};
                    const icao = (props.ICAOCODE || props.icaocode || props.id || '').toUpperCase().trim();
                    return icao === kFac || icao === fac;
                });
            }
            if (!boundaryFeature) {
                boundaryFeature = state.boundaryCache.tracon?.features?.find(f => {
                    const props = f.properties || {};
                    const sector = (props.sector || '').toUpperCase().trim();
                    return sector === fac;
                });
            }
            
            if (boundaryFeature) {
                features.push({
                    type: 'Feature',
                    geometry: boundaryFeature.geometry,
                    properties: {
                        id: inc.id,
                        facility: fac,
                        facility_type: facType,
                        color: color,
                        incident_type: inc.incident_type,
                        status: inc.status || inc.incident_status,
                        trigger_desc: inc.trigger_desc,
                        incident_number: inc.incident_number
                    }
                });
            } else {
                console.log('[NOD] No boundary found for facility:', fac, '(type:', facType, ')');
            }
        }
        
        console.log('[NOD] Incidents features created:', features.length);
        
        state.map.getSource('incidents-source').setData({
            type: 'FeatureCollection',
            features: features
        });
    }
    
    // =========================================
    // UI Rendering
    // =========================================
    
    function renderTMILists() {
        renderGSList();
        renderGDPList();
        renderReroutesList();
        renderPublicRoutesList();
    }
    
    function renderGSList() {
        const container = document.getElementById('gs-list');
        const countBadge = document.getElementById('gs-count');
        const section = document.getElementById('section-gs');
        const items = state.tmi.groundStops;
        
        countBadge.textContent = items.length;
        countBadge.classList.toggle('active', items.length > 0);
        
        // Auto-expand if has items, collapse if empty
        if (items.length > 0) {
            section?.classList.add('expanded');
        } else {
            section?.classList.remove('expanded');
        }
        
        if (items.length === 0) {
            container.innerHTML = '<div class="nod-empty"><i class="fas fa-check-circle"></i><p>No active ground stops</p></div>';
            return;
        }
        
        container.innerHTML = items.map(gs => `
            <div class="nod-tmi-card gs">
                <div class="nod-tmi-header">
                    <span class="nod-tmi-type gs">GS</span>
                    <span class="nod-tmi-airport">${escapeHtml(gs.ctl_element || gs.airports || 'N/A')}</span>
                </div>
                <div class="nod-tmi-info">
                    ${gs.comments ? escapeHtml(gs.comments) : 'Ground Stop in effect'}
                </div>
                <div class="nod-tmi-time">
                    ${formatTimeRange(gs.start_utc, gs.end_utc)}
                </div>
            </div>
        `).join('');
    }
    
    function renderGDPList() {
        const container = document.getElementById('gdp-list');
        const countBadge = document.getElementById('gdp-count');
        const section = document.getElementById('section-gdp');
        const items = state.tmi.gdps;
        
        countBadge.textContent = items.length;
        countBadge.classList.toggle('active', items.length > 0);
        
        // Auto-expand if has items, collapse if empty
        if (items.length > 0) {
            section?.classList.add('expanded');
        } else {
            section?.classList.remove('expanded');
        }
        
        if (items.length === 0) {
            container.innerHTML = '<div class="nod-empty"><i class="fas fa-check-circle"></i><p>No active GDPs</p></div>';
            return;
        }
        
        container.innerHTML = items.map(gdp => `
            <div class="nod-tmi-card gdp">
                <div class="nod-tmi-header">
                    <span class="nod-tmi-type gdp">GDP</span>
                    <span class="nod-tmi-airport">${escapeHtml(gdp.airport || 'N/A')}</span>
                </div>
                <div class="nod-tmi-info">
                    ${gdp.impacting_condition ? escapeHtml(gdp.impacting_condition) : 'Ground Delay Program'}
                    ${gdp.avg_delay ? `<br>Avg Delay: ${gdp.avg_delay} min` : ''}
                </div>
                <div class="nod-tmi-time">
                    ${formatTimeRange(gdp.start_time, gdp.end_time)}
                </div>
            </div>
        `).join('');
    }
    
    function renderReroutesList() {
        const container = document.getElementById('reroutes-list');
        const countBadge = document.getElementById('reroutes-count');
        const section = document.getElementById('section-reroutes');
        const items = state.tmi.reroutes;
        
        countBadge.textContent = items.length;
        countBadge.classList.toggle('active', items.length > 0);
        
        // Auto-expand if has items, collapse if empty
        if (items.length > 0) {
            section?.classList.add('expanded');
        } else {
            section?.classList.remove('expanded');
        }
        
        if (items.length === 0) {
            container.innerHTML = '<div class="nod-empty"><i class="fas fa-check-circle"></i><p>No active reroutes</p></div>';
            return;
        }
        
        container.innerHTML = items.map(rr => `
            <div class="nod-tmi-card reroute" style="border-left-color: ${rr.color || '#17a2b8'}">
                <div class="nod-tmi-header">
                    <span class="nod-tmi-type reroute">${escapeHtml(rr.adv_number || 'RR')}</span>
                    <span class="nod-tmi-airport">${escapeHtml(rr.name || 'Reroute')}</span>
                </div>
                <div class="nod-tmi-info">
                    ${rr.constrained_area ? `Area: ${escapeHtml(rr.constrained_area)}` : ''}
                    ${rr.reason ? `<br>Reason: ${escapeHtml(rr.reason)}` : ''}
                    ${rr.flight_count ? `<br>Flights: ${rr.flight_count}` : ''}
                </div>
                <div class="nod-tmi-time">
                    ${formatTimeRange(rr.valid_start_utc, rr.valid_end_utc)}
                </div>
            </div>
        `).join('');
    }
    
    function renderPublicRoutesList() {
        const container = document.getElementById('public-routes-list');
        const countBadge = document.getElementById('public-routes-count');
        const section = document.getElementById('section-public-routes');
        const items = state.tmi.publicRoutes;
        
        countBadge.textContent = items.length;
        countBadge.classList.toggle('active', items.length > 0);
        
        // Auto-expand if has items, collapse if empty
        if (items.length > 0) {
            section?.classList.add('expanded');
        } else {
            section?.classList.remove('expanded');
        }
        
        if (items.length === 0) {
            container.innerHTML = '<div class="nod-empty"><i class="fas fa-route"></i><p>No public routes active</p></div>';
            return;
        }
        
        container.innerHTML = items.map(pr => `
            <div class="nod-tmi-card public-route" style="border-left-color: ${pr.color || '#28a745'}">
                <div class="nod-tmi-header">
                    <span class="nod-tmi-type" style="background: ${pr.color || '#28a745'}">${escapeHtml(pr.adv_number || 'PR')}</span>
                    <span class="nod-tmi-airport">${escapeHtml(pr.name || 'Public Route')}</span>
                </div>
                <div class="nod-tmi-info">
                    ${pr.constrained_area ? `Area: ${escapeHtml(pr.constrained_area)}` : ''}
                    ${pr.reason ? `<br>Reason: ${escapeHtml(pr.reason)}` : ''}
                </div>
                <div class="nod-tmi-time">
                    ${formatTimeRange(pr.valid_start_utc, pr.valid_end_utc)}
                </div>
            </div>
        `).join('');
    }
    
    function renderDiscordList() {
        const container = document.getElementById('discord-list');
        const countBadge = document.getElementById('discord-count');
        const items = state.tmi.discord.filter(t => t.status === 'ACTIVE');
        
        countBadge.textContent = items.length;
        
        if (items.length === 0) {
            container.innerHTML = `
                <div class="nod-empty">
                    <i class="fab fa-discord"></i>
                    <p>No active Discord TMIs</p>
                    <small class="text-muted">Configure Discord integration to see TMIs</small>
                </div>
            `;
            return;
        }
        
        container.innerHTML = items.map(tmi => `
            <div class="nod-tmi-card ${tmi.tmi_type?.toLowerCase() || ''}">
                <div class="nod-tmi-header">
                    <span class="nod-tmi-type">${escapeHtml(tmi.tmi_type || 'TMI')}</span>
                    <span class="nod-tmi-airport">${escapeHtml(tmi.airport || 'N/A')}</span>
                </div>
                <div class="nod-tmi-info">${escapeHtml(tmi.reason || tmi.raw_content?.substring(0, 100) || '')}</div>
                <div class="nod-tmi-time">
                    Received: ${formatDateTime(tmi.received_at)}
                </div>
            </div>
        `).join('');
    }
    
    function renderAdvisoriesList() {
        const container = document.getElementById('advisories-list');
        const items = state.advisories;
        
        if (items.length === 0) {
            container.innerHTML = `
                <div class="nod-empty">
                    <i class="fas fa-bullhorn"></i>
                    <p>No advisories today</p>
                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="NOD.showAdvisoryModal()">
                        <i class="fas fa-plus mr-1"></i> Create Advisory
                    </button>
                </div>
            `;
            return;
        }
        
        container.innerHTML = items.map(adv => {
            const priorityClass = adv.priority == 1 ? 'high' : (adv.priority == 3 ? 'low' : 'normal');
            return `
                <div class="nod-advisory-card ${priorityClass}" onclick="NOD.showAdvisoryDetail(${adv.id})">
                    <div class="d-flex justify-content-between align-items-start">
                        <span class="nod-advisory-number">${escapeHtml(adv.adv_number || '')}</span>
                        <span class="badge badge-secondary">${escapeHtml(adv.adv_type || '')}</span>
                    </div>
                    <div class="nod-advisory-subject">${escapeHtml(adv.subject || '')}</div>
                    <div class="nod-advisory-meta">
                        ${adv.impacted_area ? `<i class="fas fa-map-marker-alt mr-1"></i>${escapeHtml(adv.impacted_area)}` : ''}
                        <span class="ml-2"><i class="far fa-clock mr-1"></i>${formatDateTime(adv.created_at)}</span>
                    </div>
                </div>
            `;
        }).join('');
    }
    
    function renderIncidentsList() {
        const container = document.getElementById('incidents-list');
        const items = state.jatoc.incidents;
        
        if (items.length === 0) {
            container.innerHTML = `
                <div class="nod-empty">
                    <i class="fas fa-check-circle text-success"></i>
                    <p>No active incidents</p>
                </div>
            `;
            return;
        }
        
        // Incident type colors (matching map colors)
        const incidentTypeColors = {
            'atc-zero': '#dc3545',
            'atc_zero': '#dc3545',
            'atczero': '#dc3545',
            'atc-alert': '#ffc107',
            'atc_alert': '#ffc107',
            'atcalert': '#ffc107',
            'atc-limited': '#fd7e14',
            'atc_limited': '#fd7e14',
            'atclimited': '#fd7e14',
            'non-responsive': '#6c757d',
            'non_responsive': '#6c757d',
            'nonresponsive': '#6c757d',
            'staffing': '#17a2b8',
            'equipment': '#20c997',
            'weather': '#6f42c1',
            'other': '#495057'
        };
        
        container.innerHTML = items.map(inc => {
            const typeClass = (inc.incident_type || '').toLowerCase().replace(/[^a-z]/g, '-');
            const typeKey = (inc.incident_type || '').toLowerCase().replace(/[^a-z]/g, '');
            const typeColor = incidentTypeColors[typeKey] || incidentTypeColors[typeClass] || '#6c757d';
            
            // Use trigger_desc or remarks for description
            const description = inc.trigger_desc || inc.remarks || 'No details';
            // Use start_utc for opened time
            const openedTime = inc.start_utc || inc.opened_at;
            // Use update_utc, updated_at, or created_utc for last update
            const lastUpdated = inc.update_utc || inc.updated_at || inc.created_utc;
            
            // Build the time info line
            let timeInfo = `#${inc.incident_number || inc.id || '?'}`;
            if (openedTime) {
                timeInfo += ` · Opened: ${formatDateTime(openedTime)}`;
            }
            if (lastUpdated && lastUpdated !== openedTime) {
                timeInfo += ` · Updated: ${formatDateTime(lastUpdated)}`;
            }
            if (inc.update_count && inc.update_count > 0) {
                timeInfo += ` (${inc.update_count} updates)`;
            }
            
            return `
                <div class="nod-incident-card ${typeClass}" style="border-left-color: ${typeColor}; background: linear-gradient(90deg, ${typeColor}22 0%, transparent 50%);">
                    <div class="d-flex justify-content-between align-items-start">
                        <span class="nod-incident-facility">${escapeHtml(inc.facility || '')}</span>
                        <span class="nod-incident-type ${typeClass}" style="background: ${typeColor};">${escapeHtml(inc.incident_type || '')}</span>
                    </div>
                    <div class="nod-tmi-info mt-2">
                        ${escapeHtml(description)}
                    </div>
                    <div class="nod-tmi-time">
                        ${timeInfo}
                    </div>
                </div>
            `;
        }).join('');
    }
    
    // =========================================
    // Color Constants (matching route-maplibre.js)
    // =========================================
    
    // DCC Region definitions (5 regions per spec)
    const DCC_REGIONS = {
        'WEST':         ['ZAK', 'ZAN', 'ZHN', 'ZLA', 'ZLC', 'ZOA', 'ZSE'],
        'SOUTH_CENTRAL': ['ZAB', 'ZFW', 'ZHO', 'ZHU', 'ZME'],
        'MIDWEST':      ['ZAU', 'ZDV', 'ZKC', 'ZMP'],
        'SOUTHEAST':    ['ZID', 'ZJX', 'ZMA', 'ZMO', 'ZTL'],
        'NORTHEAST':    ['ZBW', 'ZDC', 'ZNY', 'ZOB', 'ZWY']
    };
    
    const DCC_REGION_COLORS = {
        'WEST': '#dc3545',           // Red (bright, distinct)
        'SOUTH_CENTRAL': '#fd7e14',  // Orange (saturated, distinct from yellow)
        'MIDWEST': '#28a745',        // Green
        'SOUTHEAST': '#ffc107',      // Yellow (bright amber, distinct from orange)
        'NORTHEAST': '#007bff',      // Blue
        '': '#6c757d'
    };
    
    // Weight class colors (FSM Table 3-6)
    const WEIGHT_CLASS_COLORS = {
        'SUPER': '#ffc107', 'J': '#ffc107',  // Amber/Gold for Jumbo
        'HEAVY': '#dc3545', 'H': '#dc3545',  // Red for Heavy
        'LARGE': '#28a745', 'L': '#28a745',  // Green for Large/Jet
        'SMALL': '#17a2b8', 'S': '#17a2b8',  // Cyan for Small/Prop
        '': '#6c757d'
    };

    // FAA RECAT Wake Turbulence Categories (6-category system)
    // Based on FAA Order 7110.659 and JO 7110.65BB
    // Categories A-F based on wingspan and MTOW
    const RECAT_COLORS = {
        'A': '#9c27b0',  // Purple - Super (A380-800)
        'B': '#dc3545',  // Red - Upper Heavy (747, 777, A340, A350, C-5, AN-124)
        'C': '#f28e2b',  // Orange - Lower Heavy (767, 787, A300, A330, MD-11, DC-10)
        'D': '#edc948',  // Yellow - Upper Large (757, MD-80/90, A320, 737, CRJ-900)
        'E': '#28a745',  // Green - Lower Large (ERJ, CRJ, ATR, DHC-8, Beech 1900)
        'F': '#17a2b8',  // Cyan - Small (Light aircraft < 15,500 lbs)
        '': '#6c757d'    // Gray - Unknown
    };

    // FAA RECAT aircraft type to category mapping
    // Based on FAA RECAT 1.5 Phase I/II/III implementation
    const RECAT_PATTERNS = {
        // Cat A: Super - A380 only (wingspan > 245ft, MTOW > 560,000 lbs)
        'A': /^A38[0-9]/i,

        // Cat B: Upper Heavy (wingspan 175-245ft, MTOW 300,000-560,000 lbs)
        // 747 variants, 777 variants, A340, A350-1000, MD-11, DC-10-30/40, C-5, AN-124, AN-225
        'B': /^B74[0-9]|^B77[0-9]|^B77[A-Z]|^A34[0-9]|^A35K|^MD11|^DC10|^C5|^C5M|^AN12|^A124|^AN225|^IL96|^A300B4|^KC10|^KC135|^E3|^E4|^E6|^VC25/i,

        // Cat C: Lower Heavy (wingspan 125-175ft, MTOW 200,000-300,000 lbs)
        // 787, 767, A350-900, A330, A300, L-1011, DC-8, IL-62, IL-86, TU-154M
        'C': /^B78[0-9]|^B78X|^B76[0-9]|^A35[0-9]|^A33[0-9]|^A30[0-9]|^A310|^L101|^DC8|^IL62|^IL86|^TU15|^C17|^KC46|^P8/i,

        // Cat D: Upper Large (wingspan 90-125ft, MTOW 41,000-200,000 lbs)
        // 757, 737-700/800/900/MAX, A321, A320, A319, A318, MD-80/90, 717, C-130, P-3, Gulfstream
        'D': /^B75[0-9]|^B73[789]|^B38M|^B39M|^B3XM|^A32[0-1]|^A31[89]|^MD[89][0-9]|^B712|^B717|^C130|^C160|^P3|^G[56][0-9]{2}|^GLF[456]|^F900|^FA[78]X|^CL60|^GL[57]T|^GLEX|^BCS[13]/i,

        // Cat E: Lower Large (wingspan 65-90ft, MTOW 15,500-41,000 lbs)
        // CRJ-200/700, ERJ-145/170/175, E-Jets, ATR 42/72, DHC-8, Saab 340/2000, Beech 1900
        'E': /^CRJ[12789]|^ERJ|^E[0-9]{3}|^E1[0-9]{2}|^E75|^E90|^E95|^AT[47][0-9]|^ATR|^DH8|^DHC8|^Q[0-9]{3}|^SF34|^SB20|^B190|^JS[0-9]{2}|^PC12|^PC24|^BE20|^BE30|^BE35|^C208|^DHC[67]|^F[27]0|^F100|^BA46|^B146|^RJ[0-9]{2}/i,

        // Cat F: Small (wingspan < 65ft, MTOW < 15,500 lbs)
        // Light GA, Cessna singles/twins, Piper, Cirrus, Diamond, Beech Bonanza, etc.
        'F': /^C1[0-9]{2}|^C2[0-9]{2}|^C3[0-9]{2}|^C4[0-9]{2}|^P28|^PA[0-9]{2}|^SR2[0-9]|^DA[0-9]{2}|^M20|^BE[0-9]{2}[^0-9]|^BE3[56]|^A36|^G36|^TB[0-9]{2}|^TBM|^PC6|^ULAC/i
    };

    /**
     * Get FAA RECAT wake turbulence category (A-F) from aircraft type
     * Falls back to weight class if no pattern match
     */
    function getRecatCategory(flight) {
        const acType = stripAircraftSuffixes(flight.aircraft_icao || flight.aircraft_type || '');
        if (!acType) {
            // Fallback to weight class mapping
            const wc = getWeightClass(flight);
            if (wc === 'SUPER') return 'A';
            if (wc === 'HEAVY') return 'B';  // Conservative - assume upper heavy
            if (wc === 'LARGE') return 'D';  // Most large are Cat D
            if (wc === 'SMALL') return 'F';
            return '';
        }

        // Check patterns in order (A is most restrictive)
        for (const [cat, pattern] of Object.entries(RECAT_PATTERNS)) {
            if (pattern.test(acType)) return cat;
        }

        // Fallback to weight class if no pattern match
        const wc = getWeightClass(flight);
        if (wc === 'SUPER') return 'A';
        if (wc === 'HEAVY') return 'C';  // Default heavy to Cat C (lower heavy)
        if (wc === 'LARGE') return 'D';
        if (wc === 'SMALL') return 'F';
        return 'D';  // Default to Cat D (most common)
    }

    // Altitude block colors - spectral gradient (cool=low, warm=high)
    const ALTITUDE_BLOCK_COLORS = {
        'GROUND': '#6c757d',     // Gray - Ground/Taxi
        'SURFACE': '#6f42c1',    // Purple - Surface ops (<1000)
        'LOW': '#007bff',        // Blue - <FL100
        'LOWMED': '#17a2b8',     // Cyan - FL100-180
        'MED': '#28a745',        // Green - FL180-240
        'MEDHIGH': '#ffc107',    // Yellow - FL240-290
        'HIGH': '#fd7e14',       // Orange - FL290-350
        'VHIGH': '#dc3545',      // Red - FL350-410
        'SUPERHIGH': '#e83e8c',  // Pink/Magenta - >FL410
        '': '#6c757d'
    };
    
    // ETA relative colors - spectral (red=imminent, blue/purple=far)
    const ETA_RELATIVE_COLORS = {
        'ETA_15': '#dc3545',     // Red - imminent (≤15 min)
        'ETA_30': '#fd7e14',     // Orange (15-30 min)
        'ETA_60': '#ffc107',     // Yellow (30-60 min)
        'ETA_120': '#28a745',    // Green (1-2 hr)
        'ETA_180': '#17a2b8',    // Cyan (2-3 hr)
        'ETA_300': '#007bff',    // Blue (3-5 hr)
        'ETA_480': '#6f42c1',    // Purple (5-8 hr)
        'ETA_OVER': '#6c757d',   // Gray - >8 hours
        '': '#6c757d'
    };

    // Speed colors - more granularity
    const SPEED_COLORS = {
        'GROUND': '#6c757d',     // Gray - <50 kts (taxi/parked)
        'SLOW': '#6f42c1',       // Purple - 50-150 kts (climb/descent)
        'MEDIUM': '#007bff',     // Blue - 150-250 kts
        'FAST': '#28a745',       // Green - 250-350 kts
        'VFAST': '#ffc107',      // Yellow - 350-450 kts
        'JET': '#fd7e14',        // Orange - 450-550 kts
        'SUPERSONIC': '#dc3545'  // Red - >550 kts
    };
    
    // Arrival/Departure colors
    const ARR_DEP_COLORS = { 'ARR': '#59a14f', 'DEP': '#4e79a7' };
    
    // Airport tier colors
    const AIRPORT_TIER_COLORS = {
        'CORE30': '#dc3545',    // Red - highest priority
        'OEP35': '#007bff',     // Blue
        'ASPM77': '#ffc107',    // Yellow
        'OTHER': '#6c757d',     // Gray - unclassified
        '': '#6c757d'
    };

    // ARTCC colors - inherit from DCC region with variations
    const CENTER_COLORS = {
        // West (Red family) - matches DCC_REGION_COLORS['WEST']
        'ZAK': '#dc3545', 'ZAN': '#e74c3c', 'ZHN': '#c0392b', 'ZLA': '#dc3545',
        'ZLC': '#e57373', 'ZOA': '#d63031', 'ZSE': '#ff6b6b',
        // South Central (Orange family) - matches DCC_REGION_COLORS['SOUTH_CENTRAL']
        'ZAB': '#fd7e14', 'ZFW': '#ff9800', 'ZHO': '#e67e22', 'ZHU': '#f39c12', 'ZME': '#d35400',
        // Midwest (Green family) - matches DCC_REGION_COLORS['MIDWEST']
        'ZAU': '#28a745', 'ZDV': '#27ae60', 'ZKC': '#2ecc71', 'ZMP': '#00b894',
        // Southeast (Yellow family) - matches DCC_REGION_COLORS['SOUTHEAST']
        'ZID': '#ffc107', 'ZJX': '#f1c40f', 'ZMA': '#f4d03f', 'ZMO': '#e9b824', 'ZTL': '#ffca2c',
        // Northeast (Blue family) - matches DCC_REGION_COLORS['NORTHEAST']
        'ZBW': '#007bff', 'ZDC': '#0069d9', 'ZNY': '#0056b3', 'ZOB': '#5dade2', 'ZWY': '#004085',
        '': '#6c757d'
    };
    
    // Extended Carrier Colors (matching route-maplibre.js)
    const CARRIER_COLORS = {
        // US Legacy
        'AAL': '#0078d2', 'UAL': '#0033a0', 'DAL': '#e01933',
        // US Low-Cost
        'SWA': '#f9b612', 'JBU': '#003876', 'NKS': '#ffd200', 'FFT': '#2b8542',
        'VXP': '#e51937', 'SYX': '#ff5a00',
        // US ULCCs
        'AAY': '#f9b612', 'G4': '#6ec8e4',
        // US Regional - Major
        'SKW': '#6cace4', 'RPA': '#00b5ad', 'ENY': '#4e79a7', 'ASH': '#003876',
        // US Regional - AA
        'PDT': '#76b7b2', 'PSA': '#ff7f0e',
        // US Regional - UA
        'ASQ': '#0033a0', 'GJS': '#0033a0',
        // US Regional - DL
        'CPZ': '#e01933', 'EDV': '#e01933',
        // Alaska Group
        'ASA': '#00a8e0', 'HAL': '#5b2e91', 'QXE': '#00a8e0',
        // Cargo - Major
        'FDX': '#ff6600', 'UPS': '#351c15', 'ABX': '#00529b', 'GTI': '#002d72',
        'ATN': '#e15759', 'CLX': '#003087', 'PAC': '#b07aa1', 'KAL': '#0064d2',
        // Cargo - Regional
        'MTN': '#ffc107', 'SRR': '#28a745', 'WCW': '#17a2b8',
        // International - European
        'BAW': '#075aaa', 'DLH': '#00195c', 'AFR': '#002157', 'KLM': '#00a1e4',
        'EZY': '#ff6600', 'RYR': '#073590', 'VIR': '#e01933', 'SAS': '#00195c',
        'AZA': '#006341', 'IBE': '#e01933', 'TAP': '#00a651', 'FIN': '#0057a8',
        'SWR': '#e01933', 'AUA': '#e01933', 'BEL': '#003366', 'LOT': '#00538b',
        'CSA': '#d7141a', 'AEE': '#00529b', 'THY': '#cc0000',
        // International - Americas
        'ACA': '#f01428', 'WJA': '#003082', 'TAM': '#1a1760', 'GOL': '#ff6600',
        'AVA': '#e01933', 'CMP': '#003087', 'AMX': '#000000', 'VOI': '#ffc907',
        'ARG': '#75aadb', 'LAN': '#1a1760',
        // International - Asia/Pacific
        'UAE': '#c8a96b', 'QTR': '#5c0632', 'ETD': '#b8a36e', 'SIA': '#f9ba00',
        'CPA': '#006a4e', 'JAL': '#e01933', 'ANA': '#003370',
        'CES': '#004b87', 'CSN': '#e01933', 'CCA': '#e01933', 'QFA': '#e01933',
        'ANZ': '#000000', 'THT': '#672d91', 'MAS': '#e01933', 'SLK': '#0b3c7d',
        'EVA': '#00674b', 'CAL': '#003d7c', 'HVN': '#f7e500',
        // International - Middle East/Africa
        'SAA': '#009639', 'ETH': '#00844e', 'RAM': '#c9262c', 'RJA': '#000000',
        'GIA': '#003057', 'ELY': '#0033a1', 'MEA': '#006341',
        // Military/Government
        'AIO': '#556b2f', 'RCH': '#556b2f', 'RRR': '#556b2f',
        // Default
        '': '#6c757d'
    };
    
    // Core 30 airports (highest priority)
    const CORE30_AIRPORTS = [
        'KATL', 'KBOS', 'KBWI', 'KCLT', 'KDCA', 'KDEN', 'KDFW', 'KDTW',
        'KEWR', 'KFLL', 'KHOU', 'KIAD', 'KIAH', 'KJFK', 'KLAS', 'KLAX',
        'KLGA', 'KMCO', 'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KORD', 'KPHL',
        'KPHX', 'KSAN', 'KSEA', 'KSFO', 'KSLC', 'KTPA'
    ];

    // OEP 35 airports (5 additional beyond Core 30)
    const OEP35_AIRPORTS = [
        'KCLE', 'KCVG', 'KPBI', 'KPIT', 'KSTL'
    ];

    // ASPM 77 airports (additional beyond OEP 35)
    const ASPM77_AIRPORTS = [
        'KABQ', 'PANC', 'KAUS', 'KBDL', 'KBHM', 'KBNA', 'KBUF', 'KBUR',
        'KCHS', 'KCMH', 'KDAL', 'KDAY', 'KDSM', 'KELP', 'KGEG', 'KGRR',
        'KGSO', 'PHNL', 'KHPN', 'KIND', 'KISP', 'KJAX', 'KLGB', 'KLIT',
        'KMCI', 'KMKE', 'KMSY', 'KOAK', 'PHOG', 'KOKC', 'KOMA', 'KONT',
        'KPDX', 'KPVD', 'KRDU', 'KRIC', 'KRNO', 'KRSW', 'KSAT', 'KSDF',
        'KSJC', 'TJSJ', 'KSMF', 'KSNA', 'KSWF', 'KSYR', 'KTUS'
    ];

    // Aircraft Manufacturer Patterns (for aircraft_type color mode)
    const AIRCRAFT_MANUFACTURER_PATTERNS = {
        'AIRBUS':     /^A[0-9]{3}|^A[0-9]{2}[NK]/i,
        'BOEING':     /^B7[0-9]{2}|^B3[0-9]M|^B3XM|^B77[A-Z]|^B74[A-Z]|^B74[0-9][A-Z]|^B78X/i,
        'EMBRAER':    /^E[0-9]{3}|^ERJ|^EMB|^E[0-9][0-9][A-Z]/i,
        'BOMBARDIER': /^CRJ|^CL[0-9]{2}|^BD[0-9]{3}|^GL[0-9]{2}|^DHC|^BCS[0-9]|^Q[0-9]{3}/i,
        'MD_DC':      /^MD[0-9]{2}|^DC[0-9]{1,2}|^L10|^L101|^C130|^C17/i,
        'SAAB_OTHER': /^SF34|^SB20|^F[0-9]{2,3}|^D[0-9]{3}|^BAE|^B?146|^RJ[0-9]{2}|^AT[0-9]{2}|^PC[0-9]{2}/i,
        'RUSSIAN':    /^AN[0-9]{2,3}|^IL[0-9]{2,3}|^TU[0-9]{3}|^SU[0-9]{2}|^YAK|^BE20[0-9]/i,
        'CHINESE':    /^ARJ|^C9[0-9]{2}|^MA[0-9]{2}|^Y[0-9]{1,2}/i
    };
    
    const AIRCRAFT_MANUFACTURER_COLORS = {
        'AIRBUS': '#e15759',       // Red
        'BOEING': '#4e79a7',       // Blue
        'EMBRAER': '#59a14f',      // Green
        'BOMBARDIER': '#f28e2b',   // Orange
        'MD_DC': '#b07aa1',        // Purple
        'SAAB_OTHER': '#76b7b2',   // Teal
        'RUSSIAN': '#9c755f',      // Brown
        'CHINESE': '#edc948',      // Yellow
        'OTHER': '#6c757d'         // Gray
    };
    
    // Aircraft Configuration Patterns (order matters - first match wins)
    const AIRCRAFT_CONFIG_PATTERNS = {
        // Supersonic
        'CONC':        /^CONC|^T144|^TU144/i,
        // Super Heavy (A380, AN-225, AN-124)
        'A380':        /^A38[0-9]|^A225|^AN225|^A124|^AN124/i,
        // Quad Jets (747, A340, IL-96, DC-8, VC10)
        'QUAD_JET':    /^B74[0-9]|^B74[A-Z]|^B74[0-9][A-Z]|^A34[0-6]|^A340|^IL96|^DC8|^VC10/i,
        // Heavy Twins (777, 787, A330, A350, 767, A300, A310, IL-86, IL-62)
        'HEAVY_TWIN':  /^B77[0-9]|^B77[A-Z]|^B78[0-9]|^B78X|^A33[0-9]|^A35[0-9]|^A35K|^B76[0-9]|^A30[0-9]|^A310|^IL86|^IL62/i,
        // Tri-Jets (MD-11, DC-10, L-1011, TU-154, 727, Yak-42, TU-134, Falcon 900/7X)
        'TRI_JET':     /^MD11|^DC10|^L101|^L10|^TU15|^B72[0-9]|^R72[0-9]|^YK42|^YAK42|^TU13|^F900|^FA7X|^FA8X/i,
        // Twin Jets - Narrowbody (A320 fam, 737 fam, 757, MD-80/90, 717, Fokker, BAe, TU-204, C919, SSJ, ARJ)
        'TWIN_JET':    /^A32[0-9]|^A31[0-9]|^A2[0-9][NK]|^A22[0-9]|^B73[0-9]|^B3[0-9]M|^B3XM|^B75[0-9]|^MD[89][0-9]|^BCS[0-9]|^B712|^B717|^F100|^F70|^F28|^B146|^RJ[0-9]{2}|^BA46|^AVRO|^TU20|^TU21|^C919|^SSJ|^SU95|^ARJ|^CRJX/i,
        // Regional Jets (CRJ, ERJ, E-Jets)
        'REGIONAL_JET': /^CRJ[0-9]|^ERJ|^E[0-9]{3}|^E[0-9][0-9][A-Z]|^E1[0-9]{2}|^E75|^E90|^E95/i,
        // Turboprops (ATR, DHC-8/Q, Saab, Beech 1900, Jetstream, PC-12/24, Caravan, L-410, MA60, Y-12)
        'TURBOPROP':   /^AT[0-9]{2}|^ATR|^DH8|^DHC[0-9]|^Q[0-9]{3}|^SF34|^SB20|^SAAB|^B190|^BE19|^JS[0-9]{2}|^J31|^J32|^J41|^PC12|^PC24|^C208|^C212|^L410|^MA60|^Y12|^AN[23][0-9]|^DO[0-9]{2}|^D328/i,
        // Props/GA (Cessna 1xx/2xx, Piper, Cirrus, Diamond, Mooney, Beech Bonanza, Robin, Socata)
        'PROP':        /^C1[0-9]{2}|^C2[0-9]{2}|^C3[0-9]{2}|^C4[0-9]{2}|^P28|^PA[0-9]{2}|^PA[0-9][0-9]T|^SR2[0-9]|^SR22|^DA[0-9]{2}|^DA4[0-9]|^M20|^M20[A-Z]|^BE[0-9]{2}[^0-9]|^BE3[0-9]|^BE36|^A36|^G36|^DR[0-9]{2}|^TB[0-9]{2}|^TBM|^RV[0-9]|^AAA|^AA5|^GLST|^ULAC|^TRIN|^COL[0-9]|^EVOT/i
    };

    const AIRCRAFT_CONFIG_COLORS = {
        'CONC': '#ff1493',          // Deep Pink - Supersonic
        'A380': '#9c27b0',          // Deep Purple - Super Heavy
        'QUAD_JET': '#e15759',      // Red
        'HEAVY_TWIN': '#f28e2b',    // Orange
        'TRI_JET': '#edc948',       // Yellow
        'TWIN_JET': '#59a14f',      // Green
        'REGIONAL_JET': '#4e79a7',  // Blue
        'TURBOPROP': '#76b7b2',     // Teal
        'PROP': '#17a2b8',          // Cyan
        'OTHER': '#6c757d'          // Gray
    };
    
    // Operator Group definitions
    const MAJOR_CARRIERS = ['AAL', 'UAL', 'DAL', 'SWA', 'JBU', 'ASA', 'HAL', 'NKS', 'FFT', 'AAY', 'VXP', 'SYX'];
    const REGIONAL_CARRIERS = ['SKW', 'RPA', 'ENY', 'PDT', 'PSA', 'ASQ', 'GJS', 'CPZ', 'EDV', 'QXE', 'ASH', 'OO', 'AIP', 'MES', 'JIA', 'SCX'];
    const FREIGHT_CARRIERS = ['FDX', 'UPS', 'ABX', 'GTI', 'ATN', 'CLX', 'PAC', 'KAL', 'MTN', 'SRR', 'WCW', 'CAO'];
    // Military callsign prefixes - comprehensive list
    // US Military: Air Force, Army, Navy, Marines, Coast Guard, Air National Guard
    // NATO and allied nations military aviation
    const MILITARY_PREFIXES = [
        // US Air Force
        'RCH',    // Reach (AMC tanker/transport)
        'REACH',  // Reach alternate
        'AIO',    // Air Mobility Command
        'KING',   // HC-130 rescue
        'JOLLY',  // HH-60 rescue
        'PEDRO',  // Pararescue
        'SPAR',   // Special Operations
        'EVAC',   // Aeromedical evacuation
        'BREW',   // KC-135 callsign
        'SHELL',  // Tanker
        'DARK',   // Special ops
        'HAWK',   // F-15/F-16
        'VIPER',  // F-16
        'EAGLE',  // F-15
        'RAPTOR', // F-22
        'BOLT',   // F-35
        'SLAM',   // Strike aircraft
        'BONE',   // B-1 Lancer
        'DEATH',  // B-2 Spirit
        'DOOM',   // B-52
        'COBRA',  // Military
        'TIGER',  // Military
        'WOLF',   // Military
        'REAPER', // MQ-9
        'SHADOW', // RQ-7
        'GLOBAL', // RQ-4 Global Hawk
        // US Air Force standard prefixes (longer codes only - 'AF' handled separately to avoid AFR/AFL conflicts)
        'USAF', 'ANG', 'AFRC',
        // US Army
        'ARMY', 'PAT',  // Patriot Express
        // US Navy (squadron prefixes - 2-letter ones handled by regex to avoid airline conflicts)
        'NAVY', 'CNV', 'VAQ', 'VFA', 'VAW', 'VRC', 'VRM',
        // US Marines (various squadron designators)
        'MARINE', 'MAR', 'USMC', 'VMM', 'VMA', 'VMFA', 'VMC', 'VMGR', 'HMH', 'HMM', 'HML', 'HMLA',
        // US Coast Guard (CG + digits handled by regex to avoid airline conflicts)
        'USCG', 'COAST',
        // US Special Operations
        'RRR',    // Rescue
        'EXEC',   // Executive transport
        'SAM',    // Special Air Mission (VIP)
        'VENUS',  // VIP transport
        // NATO common callsigns
        'NATO',
        // UK Royal Air Force
        'RFR', 'ASCOT', 'TARTAN', 'TALLY',
        // Canadian Forces
        'CFC', 'CANFORCE', 'CANAF',
        // German Luftwaffe
        'GAF', 'GAM',
        // French Air Force
        'FAF', 'CTM', 'COTAM',
        // Italian Air Force
        'IAM',
        // Australian Defence Force
        'AUSSIE', 'RAAF',
        // Other NATO/Allied
        'NATO', 'ALLIED', 'SENTRY', 'AWACS'
    ];
    
    const OPERATOR_GROUP_COLORS = {
        'MAJOR': '#dc3545',      // Red - major carriers
        'REGIONAL': '#28a745',   // Green - regional carriers
        'FREIGHT': '#007bff',    // Blue - freight/cargo
        'GA': '#ffc107',         // Yellow - general aviation
        'MILITARY': '#6f42c1',   // Purple - military
        'OTHER': '#6c757d'       // Gray - unclassified
    };
    
    // =========================================
    // UI Updates
    // =========================================
    
    // US domestic prefix check
    function isDomestic(icao) {
        if (!icao) return false;
        const prefix = icao.substring(0, 1).toUpperCase();
        return prefix === 'K' || prefix === 'P'; // K = CONUS, P = Pacific
    }
    
    function updateStats() {
        // Flight counts - use filtered data for displayed stats
        const allFlights = state.traffic.data;
        const flights = state.traffic.filteredData || allFlights;
        const totalFlights = allFlights.length;
        const filteredCount = flights.length;
        
        // Update panel stats display (only if we have data, to prevent 0 flash)
        const displayEl = document.getElementById('nod_stats_display');
        const totalEl = document.getElementById('nod_stats_total');
        
        if (displayEl) {
            // Don't update to 0 if we previously had data (buffered pattern)
            const currentShown = parseInt(displayEl.textContent.replace(/[^0-9]/g, ''), 10) || 0;
            if (filteredCount > 0 || currentShown === 0) {
                displayEl.innerHTML = `<strong>${filteredCount}</strong> shown`;
            }
        }
        
        if (totalEl) {
            const currentTotal = parseInt(totalEl.textContent.replace(/[^0-9]/g, ''), 10) || 0;
            if (totalFlights > 0 || currentTotal === 0) {
                totalEl.innerHTML = `<strong>${totalFlights}</strong> total`;
            }
        }
        
        // Update last update time
        const now = new Date();
        const lastUpdateEl = document.getElementById('lastUpdateTime');
        if (lastUpdateEl) {
            lastUpdateEl.textContent = now.toISOString().substr(11, 5);
        }
    }
    
    function updateOpsLevelBadge() {
        const badge = document.getElementById('opsLevelBadge');
        const level = state.jatoc.opsLevel || 1;
        
        badge.textContent = level;
        badge.className = 'nod-status-badge ops-' + level;
    }
    
    /**
     * Update TMU Operations Level badge from active PERTI Plan
     */
    function updateTMUOpsLevelBadge() {
        const badge = document.getElementById('tmuOpsLevelBadge');
        if (!badge) return;
        
        const level = state.tmu.opsLevel;
        const eventName = state.tmu.eventName;
        
        if (level === null || level === undefined) {
            // No active event
            badge.textContent = '—';
            badge.className = 'nod-status-badge tmu-none';
            badge.title = 'No active PERTI Plan';
        } else {
            // Active event with OpLevel
            badge.textContent = level;
            badge.className = 'nod-status-badge tmu-' + level;
            
            // Set tooltip with event name and level description
            const levelDescriptions = {
                1: 'Steady State',
                2: 'Localized Impact',
                3: 'Regional Impact',
                4: 'NAS-Wide Impact'
            };
            const desc = levelDescriptions[level] || '';
            badge.title = eventName ? `${eventName} - ${desc}` : desc;
        }
    }
    
    // =========================================
    // User Interactions
    // =========================================
    
    function togglePanel() {
        const panel = document.getElementById('nodPanel');
        const toggle = document.getElementById('panelToggle');
        const icon = document.getElementById('panelToggleIcon');
        
        state.ui.panelCollapsed = !state.ui.panelCollapsed;
        panel.classList.toggle('collapsed', state.ui.panelCollapsed);
        toggle.classList.toggle('panel-collapsed', state.ui.panelCollapsed);
        
        icon.className = state.ui.panelCollapsed ? 'fas fa-chevron-left' : 'fas fa-chevron-right';
        
        saveUIState();
        
        // Resize map after panel toggle
        setTimeout(() => state.map?.resize(), 350);
    }
    
    function switchTab(tabId) {
        // Update tab buttons
        document.querySelectorAll('.nod-panel-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.tab === tabId);
        });
        
        // Update tab panes
        document.querySelectorAll('.nod-tab-pane').forEach(pane => {
            pane.classList.toggle('active', pane.id === 'tab-' + tabId);
        });
        
        state.ui.activeTab = tabId;
        saveUIState();
    }
    
    function toggleSection(sectionId) {
        const section = document.getElementById('section-' + sectionId);
        if (section) {
            section.classList.toggle('expanded');
        }
    }
    
    function toggleLayerControls() {
        const controls = document.getElementById('mapLayerControls');
        const chevron = document.getElementById('layerControlsChevron');
        
        controls.classList.toggle('collapsed');
        chevron.className = controls.classList.contains('collapsed') 
            ? 'fas fa-chevron-right' 
            : 'fas fa-chevron-down';
        
        state.ui.layerControlsCollapsed = controls.classList.contains('collapsed');
        saveUIState();
    }
    
    function toggleTrafficControls() {
        const controls = document.getElementById('trafficControls');
        const chevron = document.getElementById('trafficControlsChevron');
        const body = controls.querySelector('.nod-map-controls-body');
        
        const isCollapsed = body.style.display === 'none';
        body.style.display = isCollapsed ? 'block' : 'none';
        chevron.className = isCollapsed ? 'fas fa-chevron-down' : 'fas fa-chevron-right';
        
        state.ui.trafficControlsCollapsed = !isCollapsed;
        saveUIState();
    }
    
    function toggleLayer(layerId, visible) {
        state.layers[layerId] = visible;
        
        const layerMappings = {
            // Boundaries
            'artcc': ['artcc-fill', 'artcc-lines', 'artcc-labels'],
            'tracon': ['tracon-fill', 'tracon-lines'],
            'high': ['high-lines'],
            'low': ['low-lines'],
            'superhigh': ['superhigh-lines'],
            
            // Traffic and tracks
            'traffic': ['traffic-icons', 'traffic-circles-fallback', 'traffic-labels'],
            'tracks': ['tracks-lines', 'tracks-points'],
            
            // Routes and TMI (labels handled by DOM markers, not symbol layer)
            'public-routes': ['public-routes-solid', 'public-routes-dashed', 'public-routes-fan', 'public-routes-lines'],
            'splits': ['splits-fill', 'splits-lines', 'splits-labels'],
            'incidents': ['incidents-fill', 'incidents-lines', 'incidents-labels'],
            
            // Weather
            'radar': ['weather-radar']
        };
        
        const layers = layerMappings[layerId];
        if (layers && state.map) {
            layers.forEach(layer => {
                if (state.map.getLayer(layer)) {
                    state.map.setLayoutProperty(layer, 'visibility', visible ? 'visible' : 'none');
                }
            });
        }
        
        // Handle DOM route label markers visibility
        if (layerId === 'public-routes') {
            state.routeLabelMarkers.forEach(marker => {
                const el = marker.getElement();
                if (el) {
                    el.style.display = visible ? 'block' : 'none';
                }
            });
        }
        
        // Show/hide splits strata filters when splits layer is toggled
        if (layerId === 'splits') {
            const strataFilters = document.getElementById('splits-strata-filters');
            if (strataFilters) {
                strataFilters.style.display = visible ? 'flex' : 'none';
            }
            // Apply current strata filter when enabling
            if (visible) {
                applySplitsStrataFilter();
            }
        }
        
        saveUIState();
    }
    
    /**
     * Toggle splits strata visibility (low, high, superhigh)
     */
    function toggleSplitsStrata(strata, visible) {
        state.splitsStrata[strata] = visible;
        applySplitsStrataFilter();
        saveUIState();
    }
    
    /**
     * Apply strata filter to splits layers
     */
    function applySplitsStrataFilter() {
        if (!state.map) return;
        
        // Build filter based on visible strata
        const visibleStrata = Object.entries(state.splitsStrata)
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
        const polygonLayers = ['splits-fill', 'splits-lines'];
        polygonLayers.forEach(layerId => {
            if (state.map.getLayer(layerId)) {
                state.map.setFilter(layerId, polygonFilter);
            }
        });
        
        // Build label filter - show label if position has ANY visible strata
        let labelFilter;
        if (visibleStrata.length === 3) {
            labelFilter = null; // Show all
        } else if (visibleStrata.length === 0) {
            labelFilter = ['==', ['get', 'label_text'], '__never_match__']; // Show none
        } else {
            // Show label if any of its strata flags match visible strata
            const conditions = [];
            if (state.splitsStrata.low) conditions.push(['get', 'has_low']);
            if (state.splitsStrata.high) conditions.push(['get', 'has_high']);
            if (state.splitsStrata.superhigh) conditions.push(['get', 'has_superhigh']);
            
            // Use 'any' to check if any of the strata flags are truthy
            labelFilter = ['any', ...conditions];
        }
        
        // Apply filter to labels layer
        if (state.map.getLayer('splits-labels')) {
            state.map.setFilter('splits-labels', labelFilter);
        }
        
        console.log('[NOD] Splits strata filter applied:', visibleStrata);
    }
    
    function toggleTrafficLabels(show) {
        state.traffic.showLabels = show;
        if (state.map && state.map.getLayer('traffic-labels')) {
            state.map.setLayoutProperty('traffic-labels', 'visibility', show ? 'visible' : 'none');
        }
    }
    
    /**
     * Toggle flight track history visibility
     * Loads track data from API when enabled
     */
    function toggleTrafficTracks(show) {
        state.traffic.showTracks = show;
        
        if (!state.map) return;
        
        const visibility = show ? 'visible' : 'none';
        
        // Toggle track layer visibility
        if (state.map.getLayer('tracks-lines')) {
            state.map.setLayoutProperty('tracks-lines', 'visibility', visibility);
        }
        if (state.map.getLayer('tracks-points')) {
            state.map.setLayoutProperty('tracks-points', 'visibility', visibility);
        }
        
        if (show) {
            // Load track data for visible flights
            loadTracks();
        } else {
            // Clear track data when disabled
            if (state.map.getSource('tracks-source')) {
                state.map.getSource('tracks-source').setData({
                    type: 'FeatureCollection',
                    features: []
                });
            }
        }
        
        saveUIState();
    }
    
    // Track loading debounce timer
    let trackLoadTimeout = null;
    let trackLoadInProgress = false;
    
    /**
     * Load track history for currently visible/filtered flights
     * Fetches historical positions and renders as LineStrings
     * Debounced to prevent excessive API calls
     */
    async function loadTracks() {
        if (!state.traffic.showTracks || !state.map) return;
        
        // Debounce: cancel pending request and wait 500ms
        if (trackLoadTimeout) {
            clearTimeout(trackLoadTimeout);
        }
        
        trackLoadTimeout = setTimeout(async () => {
            // Skip if another load is in progress
            if (trackLoadInProgress) return;
            
            trackLoadInProgress = true;
            
            try {
                // Get flight keys from filtered data
                const flightKeys = state.traffic.filteredData
                    .filter(f => f.flight_key)
                    .map(f => f.flight_key)
                    .slice(0, 50);  // Limit to 50 flights for performance
                
                if (flightKeys.length === 0) {
                    console.log('[NOD] No flights to load tracks for');
                    trackLoadInProgress = false;
                    return;
                }
                
                console.log(`[NOD] Loading tracks for ${flightKeys.length} flights...`);
                
                const response = await fetch(`api/nod/tracks.php?flight_keys=${flightKeys.join(',')}&since_hours=2&limit=100`);
                const data = await response.json();
                
                if (data.debug && data.debug.error) {
                    console.warn('[NOD] Track API error:', data.debug.error);
                    trackLoadInProgress = false;
                    return;
                }
                
                console.log(`[NOD] Loaded ${data.features?.length || 0} tracks`);
                
                // Update track source with GeoJSON data
                if (state.map.getSource('tracks-source')) {
                    state.map.getSource('tracks-source').setData(data);
                }
                
                // Apply color based on current color mode if using flight-specific colors
                updateTrackColors();
                
            } catch (err) {
                console.error('[NOD] Failed to load tracks:', err);
            } finally {
                trackLoadInProgress = false;
            }
        }, 500);  // 500ms debounce
    }
    
    /**
     * Update track line colors to match flight colors
     */
    function updateTrackColors() {
        if (!state.map || !state.map.getLayer('tracks-lines')) return;
        
        // For now, use a consistent track color
        // Could enhance to match flight colors by joining on flight_key
        const trackColor = '#00ff88';  // Bright green
        
        state.map.setPaintProperty('tracks-lines', 'line-color', trackColor);
        state.map.setPaintProperty('tracks-points', 'circle-color', trackColor);
    }
    
    /**
     * Toggle route labels visibility
     */
    function toggleRouteLabels(show) {
        state.routeLabelsVisible = show;
        
        // Toggle DOM-based route label markers
        state.routeLabelMarkers.forEach(marker => {
            const el = marker.getElement();
            if (el) {
                el.style.display = show ? 'block' : 'none';
            }
        });
        
        // Keep symbol layer always hidden (we only use DOM markers)
        if (state.map && state.map.getLayer('public-routes-labels')) {
            state.map.setLayoutProperty('public-routes-labels', 'visibility', 'none');
        }
        
        saveUIState();
    }

    // =========================================
    // Flight Plan Route Display Functions
    // =========================================

    /**
     * Toggle flight route display for a specific flight
     * Fetches waypoints from ADL API if not available locally
     * @param {Object} flight - Flight data
     * @param {string} color - Route color (optional, defaults based on color mode)
     * @returns {Promise<boolean>} true if route was added, false if removed
     */
    async function toggleFlightRoute(flight, color = null) {
        const flightKey = flight.flight_key || flight.callsign;
        if (!flightKey) return false;

        // If already drawn, remove it
        if (state.drawnFlightRoutes.has(flightKey)) {
            state.drawnFlightRoutes.delete(flightKey);
            updateFlightRoutesDisplay();
            console.log(`[NOD] Removed route for ${flightKey}`);
            return false;
        }

        // Try to get waypoints from flight object first
        let waypoints = null;
        if (flight.waypoints_json) {
            try {
                waypoints = typeof flight.waypoints_json === 'string'
                    ? JSON.parse(flight.waypoints_json)
                    : flight.waypoints_json;
            } catch (e) {
                console.warn(`[NOD] Failed to parse waypoints for ${flightKey}:`, e);
            }
        }

        // If no local waypoints, fetch from API
        let apiFlightInfo = null;
        if (!waypoints || !Array.isArray(waypoints) || waypoints.length < 2) {
            console.log(`[NOD] Fetching waypoints from API for ${flightKey}...`);
            try {
                const lookupParam = flight.flight_key ? `key=${encodeURIComponent(flight.flight_key)}` : `cs=${encodeURIComponent(flight.callsign)}`;
                const response = await fetch(`api/adl/waypoints.php?${lookupParam}`);
                if (response.ok) {
                    const data = await response.json();
                    apiFlightInfo = data.flight;
                    if (data.waypoints && data.waypoints.length > 0) {
                        waypoints = data.waypoints;
                        console.log(`[NOD] Fetched ${waypoints.length} waypoints for ${flightKey}`);
                    }
                }
            } catch (e) {
                console.warn(`[NOD] Failed to fetch waypoints for ${flightKey}:`, e);
            }
        }

        // If still no waypoints, create simple direct route from origin to destination
        // Use airport coordinates from API response
        if (!waypoints || !Array.isArray(waypoints) || waypoints.length < 2) {
            const coords = [];

            // Get airport coords from API response
            const deptLat = apiFlightInfo?.dept_lat;
            const deptLon = apiFlightInfo?.dept_lon;
            const destLat = apiFlightInfo?.dest_lat;
            const destLon = apiFlightInfo?.dest_lon;
            const deptIcao = apiFlightInfo?.fp_dept_icao || flight.fp_dept_icao;
            const destIcao = apiFlightInfo?.fp_dest_icao || flight.fp_dest_icao;

            // Add departure airport if we have coordinates
            if (deptLat != null && deptLon != null) {
                coords.push({ fix: deptIcao || 'DEP', lat: deptLat, lon: deptLon });
            }

            // Add current aircraft position
            const acLat = apiFlightInfo?.ac_lat ?? parseFloat(flight.lat);
            const acLon = apiFlightInfo?.ac_lon ?? parseFloat(flight.lon);
            if (!isNaN(acLat) && !isNaN(acLon)) {
                coords.push({ fix: 'AIRCRAFT', lat: acLat, lon: acLon });
            }

            // Add destination airport if we have coordinates
            if (destLat != null && destLon != null) {
                coords.push({ fix: destIcao || 'ARR', lat: destLat, lon: destLon });
            }

            if (coords.length >= 2) {
                waypoints = coords;
                console.log(`[NOD] Using simple route (${coords.length} points) for ${flightKey}: ${coords.map(c => c.fix).join(' -> ')}`);
            } else {
                console.warn(`[NOD] No route data available for ${flightKey} (coords: ${coords.length})`);
                return false;
            }
        }

        // Determine route color
        if (!color) {
            color = getFlightColor(flight) || '#00ffff';
        }

        // Find aircraft position to split route
        const acLat = parseFloat(flight.lat);
        const acLon = parseFloat(flight.lon);

        // Split waypoints into "behind" (flown) and "ahead" (remaining)
        const behindCoords = [];
        const aheadCoords = [];
        let foundAircraft = false;
        let minDist = Infinity;
        let splitIdx = 0;

        // Find closest point on route to aircraft
        waypoints.forEach((wp, idx) => {
            const lat = parseFloat(wp.lat || wp.latitude);
            const lon = parseFloat(wp.lon || wp.longitude || wp.lng);
            if (isNaN(lat) || isNaN(lon)) return;

            const dist = Math.sqrt(Math.pow(lat - acLat, 2) + Math.pow(lon - acLon, 2));
            if (dist < minDist) {
                minDist = dist;
                splitIdx = idx;
            }
        });

        // Build behind and ahead coordinate arrays
        waypoints.forEach((wp, idx) => {
            const lat = parseFloat(wp.lat || wp.latitude);
            const lon = parseFloat(wp.lon || wp.longitude || wp.lng);
            if (isNaN(lat) || isNaN(lon)) return;

            if (idx <= splitIdx) {
                behindCoords.push([lon, lat]);
            }
            if (idx >= splitIdx) {
                aheadCoords.push([lon, lat]);
            }
        });

        // Insert aircraft position at split point
        if (!isNaN(acLat) && !isNaN(acLon)) {
            if (behindCoords.length > 0) behindCoords.push([acLon, acLat]);
            if (aheadCoords.length > 0) aheadCoords.unshift([acLon, acLat]);
        }

        // Normalize coordinates for IDL crossing (prevents routes wrapping around globe)
        const normalizedBehind = normalizeForIDL(behindCoords);
        const normalizedAhead = normalizeForIDL(aheadCoords);

        // Store route data
        state.drawnFlightRoutes.set(flightKey, {
            flight,
            waypoints,
            behindCoords: normalizedBehind,
            aheadCoords: normalizedAhead,
            color
        });

        updateFlightRoutesDisplay();
        console.log(`[NOD] Added route for ${flightKey} with ${waypoints.length} waypoints`);
        return true;
    }

    /**
     * Update the flight routes display on the map
     */
    function updateFlightRoutesDisplay() {
        if (!state.map) return;

        const lineFeatures = [];
        const waypointFeatures = [];

        state.drawnFlightRoutes.forEach((routeData, flightKey) => {
            const { behindCoords, aheadCoords, color, waypoints } = routeData;

            // Add behind line (solid)
            if (behindCoords && behindCoords.length >= 2) {
                lineFeatures.push({
                    type: 'Feature',
                    properties: { flightKey, segment: 'behind', color },
                    geometry: { type: 'LineString', coordinates: behindCoords }
                });
            }

            // Add ahead line (dashed)
            if (aheadCoords && aheadCoords.length >= 2) {
                lineFeatures.push({
                    type: 'Feature',
                    properties: { flightKey, segment: 'ahead', color },
                    geometry: { type: 'LineString', coordinates: aheadCoords }
                });
            }

            // Add waypoint markers
            if (waypoints) {
                waypoints.forEach((wp, idx) => {
                    const lat = parseFloat(wp.lat || wp.latitude);
                    const lon = parseFloat(wp.lon || wp.longitude || wp.lng);
                    const name = wp.name || wp.ident || wp.fix || `WP${idx}`;
                    if (isNaN(lat) || isNaN(lon)) return;

                    // Build label with airway/DP/STAR info
                    const airway = wp.airway || null;
                    const dp = wp.dp || null;
                    const star = wp.star || null;

                    // Format: "FIXNAME (J60)" or "FIXNAME (SKORR5)" or "FIXNAME (ANJLL4)"
                    let label = name;
                    if (airway) {
                        label = `${name} (${airway})`;
                    } else if (dp) {
                        label = `${name} (${dp})`;
                    } else if (star) {
                        label = `${name} (${star})`;
                    }

                    waypointFeatures.push({
                        type: 'Feature',
                        properties: {
                            flightKey,
                            name,
                            label,
                            color,
                            airway,
                            dp,
                            star,
                            source: wp.source || null
                        },
                        geometry: { type: 'Point', coordinates: [lon, lat] }
                    });
                });
            }
        });

        // Update map sources
        const routeSource = state.map.getSource('flight-routes-source');
        if (routeSource) {
            routeSource.setData({ type: 'FeatureCollection', features: lineFeatures });
        }

        const wpSource = state.map.getSource('flight-waypoints-source');
        if (wpSource) {
            wpSource.setData({ type: 'FeatureCollection', features: waypointFeatures });
        }
    }

    /**
     * Clear all drawn flight routes
     */
    function clearFlightRoutes() {
        state.drawnFlightRoutes.clear();
        updateFlightRoutesDisplay();
        console.log('[NOD] All flight routes cleared');
    }

    /**
     * Draw routes for all currently filtered flights
     * Fetches waypoint data from API for each flight that doesn't already have a route displayed
     */
    async function drawAllFilteredRoutes() {
        const flights = state.traffic.filteredData || state.traffic.data || [];

        if (flights.length === 0) {
            console.log('[NOD] No flights to draw routes for');
            return;
        }

        // Limit to avoid overwhelming the API/map
        const MAX_ROUTES = 50;
        const flightsToProcess = flights.filter(f => !isFlightRouteDisplayed(f)).slice(0, MAX_ROUTES);

        if (flightsToProcess.length === 0) {
            console.log('[NOD] All filtered flights already have routes displayed');
            return;
        }

        console.log(`[NOD] Drawing routes for ${flightsToProcess.length} flights...`);

        // Process in batches to avoid overwhelming the browser/API
        const BATCH_SIZE = 10;
        let processed = 0;

        for (let i = 0; i < flightsToProcess.length; i += BATCH_SIZE) {
            const batch = flightsToProcess.slice(i, i + BATCH_SIZE);

            // Process batch in parallel
            await Promise.all(batch.map(async (flight) => {
                try {
                    await toggleFlightRoute(flight);
                    processed++;
                } catch (err) {
                    console.warn('[NOD] Failed to draw route for', flight.callsign, err);
                }
            }));

            // Small delay between batches to be nice to the API
            if (i + BATCH_SIZE < flightsToProcess.length) {
                await new Promise(resolve => setTimeout(resolve, 100));
            }
        }

        console.log(`[NOD] Drew routes for ${processed} flights`);

        if (flights.length > MAX_ROUTES) {
            console.log(`[NOD] Note: Limited to ${MAX_ROUTES} routes. ${flights.length - MAX_ROUTES} flights not drawn.`);
        }
    }

    /**
     * Check if a flight's route is currently displayed
     */
    function isFlightRouteDisplayed(flight) {
        const flightKey = flight.flight_key || flight.callsign;
        return state.drawnFlightRoutes.has(flightKey);
    }

    function setTrafficColorMode(mode) {
        state.traffic.colorMode = mode;
        updateTrafficLayer();
        renderColorLegend();
        renderMapLegend();
    }
    
    // Alias for HTML onclick
    function setColorMode(mode) {
        setTrafficColorMode(mode);
    }
    
    /**
     * Toggle map legend visibility
     */
    function toggleMapLegend() {
        state.mapLegendVisible = !state.mapLegendVisible;
        const legend = document.getElementById('mapColorLegend');
        const showBtn = document.getElementById('mapLegendShowBtn');
        
        if (state.mapLegendVisible) {
            legend.style.display = 'block';
            showBtn.style.display = 'none';
        } else {
            legend.style.display = 'none';
            showBtn.style.display = 'block';
        }
        
        saveUIState();
    }
    
    /**
     * Set layer opacity
     */
    function setLayerOpacity(layerName, opacity) {
        state.layerOpacity[layerName] = opacity;
        
        // Update map layers based on layer name
        if (layerName === 'public-routes') {
            const layers = ['public-routes-solid', 'public-routes-dashed', 'public-routes-fan', 'public-routes-lines'];
            layers.forEach(layer => {
                if (state.map.getLayer(layer)) {
                    const type = state.map.getLayer(layer).type;
                    if (type === 'line') {
                        state.map.setPaintProperty(layer, 'line-opacity', opacity);
                    }
                }
            });
            
            // Also update DOM label markers opacity
            state.routeLabelMarkers.forEach(marker => {
                const el = marker.getElement();
                if (el) {
                    el.style.opacity = opacity;
                }
            });
        } else if (layerName === 'splits') {
            const layers = ['splits-fill', 'splits-lines', 'splits-labels'];
            layers.forEach(layer => {
                if (state.map.getLayer(layer)) {
                    const type = state.map.getLayer(layer).type;
                    if (type === 'fill') {
                        state.map.setPaintProperty(layer, 'fill-opacity', opacity * 0.3);  // Fill is typically less opaque
                    } else if (type === 'line') {
                        state.map.setPaintProperty(layer, 'line-opacity', opacity);
                    } else if (type === 'symbol') {
                        state.map.setPaintProperty(layer, 'text-opacity', opacity);
                    }
                }
            });
        } else if (layerName === 'incidents') {
            const layers = ['incidents-fill', 'incidents-lines', 'incidents-labels'];
            layers.forEach(layer => {
                if (state.map.getLayer(layer)) {
                    const type = state.map.getLayer(layer).type;
                    if (type === 'fill') {
                        state.map.setPaintProperty(layer, 'fill-opacity', opacity * 0.35);  // Keep fill semi-transparent
                    } else if (type === 'line') {
                        state.map.setPaintProperty(layer, 'line-opacity', opacity);
                    } else if (type === 'symbol') {
                        state.map.setPaintProperty(layer, 'text-opacity', opacity);
                    }
                }
            });
        }
        
        saveUIState();
    }
    
    /**
     * Render the floating map legend
     */
    function renderMapLegend() {
        const $grid = $('#mapLegendGrid');
        const $modeLabel = $('#mapLegendModeLabel');
        if (!$grid.length) return;
        
        const mode = state.traffic.colorMode;
        let items = getLegendItems();
        
        // Update mode label
        const modeLabels = {
            'weight_class': 'Weight Class',
            'aircraft_category': 'AC Category',
            'aircraft_type': 'Manufacturer',
            'aircraft_config': 'Configuration',
            'operator_group': 'Operator',
            'altitude': 'Altitude',
            'speed': 'Speed',
            'status': 'Status',
            'arr_dep': 'Arr/Dep',
            'dcc_region': 'DCC Region',
            'eta_relative': 'ETA',
            'eta_hour': 'ETA Hour',
            'carrier': 'Carrier',
            'dep_center': 'Dep Center',
            'arr_center': 'Arr Center',
            'dep_tracon': 'Dep TRACON',
            'arr_tracon': 'Arr TRACON',
            'dep_airport': 'Dep Airport',
            'arr_airport': 'Arr Airport',
            'reroute_match': 'Reroute Match'
        };
        $modeLabel.text(modeLabels[mode] || mode);
        
        // Render all items in grid
        $grid.html(items.map(item =>
            `<div class="nod-map-legend-item">
                <span class="nod-map-legend-color" style="background: ${item.color};"></span>
                <span class="nod-map-legend-label">${item.label}</span>
            </div>`
        ).join(''));
    }
    
    /**
     * Get legend items based on current color mode - returns ALL values
     */
    function getLegendItems() {
        const mode = state.traffic.colorMode;
        let items = [];
        
        switch (mode) {
            case 'weight_class':
                items = [
                    { color: WEIGHT_CLASS_COLORS['SUPER'], label: 'Super' },
                    { color: WEIGHT_CLASS_COLORS['HEAVY'], label: 'Heavy' },
                    { color: WEIGHT_CLASS_COLORS['LARGE'], label: 'Large' },
                    { color: WEIGHT_CLASS_COLORS['SMALL'], label: 'Small' }
                ];
                break;
            case 'aircraft_category':
                items = [
                    { color: WEIGHT_CLASS_COLORS['SUPER'], label: 'J (Jet)' },
                    { color: WEIGHT_CLASS_COLORS['HEAVY'], label: 'H (Heavy)' },
                    { color: WEIGHT_CLASS_COLORS['LARGE'], label: 'L (Large)' },
                    { color: WEIGHT_CLASS_COLORS['SMALL'], label: 'S (Small)' }
                ];
                break;
            case 'aircraft_type':
                items = [
                    { color: AIRCRAFT_MANUFACTURER_COLORS['AIRBUS'], label: 'Airbus' },
                    { color: AIRCRAFT_MANUFACTURER_COLORS['BOEING'], label: 'Boeing' },
                    { color: AIRCRAFT_MANUFACTURER_COLORS['EMBRAER'], label: 'Embraer' },
                    { color: AIRCRAFT_MANUFACTURER_COLORS['BOMBARDIER'], label: 'Bombardier' },
                    { color: AIRCRAFT_MANUFACTURER_COLORS['MD_DC'], label: 'MD/DC' },
                    { color: AIRCRAFT_MANUFACTURER_COLORS['CESSNA'], label: 'Cessna' },
                    { color: AIRCRAFT_MANUFACTURER_COLORS['OTHER'], label: 'Other' }
                ];
                break;
            case 'aircraft_config':
                items = [
                    { color: AIRCRAFT_CONFIG_COLORS['CONC'], label: 'Concorde' },
                    { color: AIRCRAFT_CONFIG_COLORS['A380'], label: 'A380' },
                    { color: AIRCRAFT_CONFIG_COLORS['QUAD_JET'], label: 'Quad Jet' },
                    { color: AIRCRAFT_CONFIG_COLORS['HEAVY_TWIN'], label: 'Heavy Twin' },
                    { color: AIRCRAFT_CONFIG_COLORS['TRI_JET'], label: 'Tri Jet' },
                    { color: AIRCRAFT_CONFIG_COLORS['TWIN_JET'], label: 'Twin Jet' },
                    { color: AIRCRAFT_CONFIG_COLORS['REGIONAL_JET'], label: 'Regional' },
                    { color: AIRCRAFT_CONFIG_COLORS['TURBOPROP'], label: 'Turboprop' },
                    { color: AIRCRAFT_CONFIG_COLORS['PROP'], label: 'Prop' }
                ];
                break;
            case 'operator_group':
                items = [
                    { color: OPERATOR_GROUP_COLORS['MAJOR'], label: 'Major' },
                    { color: OPERATOR_GROUP_COLORS['REGIONAL'], label: 'Regional' },
                    { color: OPERATOR_GROUP_COLORS['FREIGHT'], label: 'Freight' },
                    { color: OPERATOR_GROUP_COLORS['GA'], label: 'Gen Aviation' },
                    { color: OPERATOR_GROUP_COLORS['MILITARY'], label: 'Military' },
                    { color: OPERATOR_GROUP_COLORS['OTHER'], label: 'Other' }
                ];
                break;
            case 'wake_category':
                // FAA RECAT categories (A-F)
                items = [
                    { color: RECAT_COLORS['A'], label: 'A (Super)' },
                    { color: RECAT_COLORS['B'], label: 'B (Upper Heavy)' },
                    { color: RECAT_COLORS['C'], label: 'C (Lower Heavy)' },
                    { color: RECAT_COLORS['D'], label: 'D (Upper Large)' },
                    { color: RECAT_COLORS['E'], label: 'E (Lower Large)' },
                    { color: RECAT_COLORS['F'], label: 'F (Small)' }
                ];
                break;
            case 'altitude':
                items = [
                    { color: ALTITUDE_BLOCK_COLORS['GROUND'], label: 'Ground' },
                    { color: ALTITUDE_BLOCK_COLORS['SURFACE'], label: 'Surface' },
                    { color: ALTITUDE_BLOCK_COLORS['LOW'], label: '<FL100' },
                    { color: ALTITUDE_BLOCK_COLORS['LOWMED'], label: 'FL100-180' },
                    { color: ALTITUDE_BLOCK_COLORS['MED'], label: 'FL180-240' },
                    { color: ALTITUDE_BLOCK_COLORS['MEDHIGH'], label: 'FL240-290' },
                    { color: ALTITUDE_BLOCK_COLORS['HIGH'], label: 'FL290-350' },
                    { color: ALTITUDE_BLOCK_COLORS['VHIGH'], label: 'FL350-410' },
                    { color: ALTITUDE_BLOCK_COLORS['SUPERHIGH'], label: 'FL410+' }
                ];
                break;
            case 'speed':
                items = [
                    { color: SPEED_COLORS['GROUND'], label: '<50' },
                    { color: SPEED_COLORS['SLOW'], label: '50-150' },
                    { color: SPEED_COLORS['MEDIUM'], label: '150-250' },
                    { color: SPEED_COLORS['FAST'], label: '250-350' },
                    { color: SPEED_COLORS['VFAST'], label: '350-450' },
                    { color: SPEED_COLORS['JET'], label: '450-550' },
                    { color: SPEED_COLORS['SUPERSONIC'], label: '550+' }
                ];
                break;
            case 'status':
                // ADL flight statuses: PROPOSED, DEPARTING, ACTIVE, ARRIVING, COMPLETED
                items = [
                    { color: '#b07aa1', label: 'Proposed' },
                    { color: '#17a2b8', label: 'Departing' },
                    { color: '#28a745', label: 'Airborne' },
                    { color: '#f28e2b', label: 'Arriving' },
                    { color: '#6c757d', label: 'Arrived' },
                    { color: '#dc3545', label: 'GS Affected' },
                    { color: '#ffc107', label: 'EDCT' }
                ];
                break;
            case 'arr_dep':
                items = [
                    { color: ARR_DEP_COLORS['ARR'], label: 'Enroute' },
                    { color: ARR_DEP_COLORS['DEP'], label: 'Climbing' },
                    { color: '#666666', label: 'Ground' }
                ];
                break;
            case 'dcc_region':
                // Ordered by color: red, orange, yellow, green, blue
                items = [
                    { color: DCC_REGION_COLORS['WEST'], label: 'West' },
                    { color: DCC_REGION_COLORS['SOUTH_CENTRAL'], label: 'South Central' },
                    { color: DCC_REGION_COLORS['SOUTHEAST'], label: 'Southeast' },
                    { color: DCC_REGION_COLORS['MIDWEST'], label: 'Midwest' },
                    { color: DCC_REGION_COLORS['NORTHEAST'], label: 'Northeast' }
                ];
                break;
            case 'eta_relative':
                // Discrete bucket legend matching getEtaRelativeColor
                items = [
                    { color: ETA_RELATIVE_COLORS['ETA_15'], label: '≤15m' },
                    { color: ETA_RELATIVE_COLORS['ETA_30'], label: '15-30m' },
                    { color: ETA_RELATIVE_COLORS['ETA_60'], label: '30m-1h' },
                    { color: ETA_RELATIVE_COLORS['ETA_120'], label: '1-2h' },
                    { color: ETA_RELATIVE_COLORS['ETA_180'], label: '2-3h' },
                    { color: ETA_RELATIVE_COLORS['ETA_300'], label: '3-5h' },
                    { color: ETA_RELATIVE_COLORS['ETA_480'], label: '5-8h' },
                    { color: ETA_RELATIVE_COLORS['ETA_OVER'], label: '>8h' }
                ];
                break;
            case 'eta_hour':
                // Cyclical hour legend showing 24-hour cycle
                items = [];
                for (let h = 0; h < 24; h += 3) {
                    const hue = (h / 24) * 360;
                    items.push({ color: `hsl(${hue}, 85%, 50%)`, label: `${String(h).padStart(2,'0')}Z` });
                }
                break;
            case 'carrier':
                // Show major carriers
                items = [
                    { color: CARRIER_COLORS['AAL'], label: 'American' },
                    { color: CARRIER_COLORS['UAL'], label: 'United' },
                    { color: CARRIER_COLORS['DAL'], label: 'Delta' },
                    { color: CARRIER_COLORS['SWA'], label: 'Southwest' },
                    { color: CARRIER_COLORS['JBU'], label: 'JetBlue' },
                    { color: CARRIER_COLORS['ASA'], label: 'Alaska' },
                    { color: CARRIER_COLORS['NKS'], label: 'Spirit' },
                    { color: CARRIER_COLORS['FFT'], label: 'Frontier' },
                    { color: CARRIER_COLORS['FDX'], label: 'FedEx' },
                    { color: CARRIER_COLORS['UPS'], label: 'UPS' },
                    { color: CARRIER_COLORS[''], label: 'Other' }
                ];
                break;
            case 'dep_center':
            case 'arr_center':
                // Show all CONUS centers grouped by DCC
                items = [
                    // West
                    { color: CENTER_COLORS['ZSE'], label: 'ZSE' },
                    { color: CENTER_COLORS['ZOA'], label: 'ZOA' },
                    { color: CENTER_COLORS['ZLA'], label: 'ZLA' },
                    { color: CENTER_COLORS['ZLC'], label: 'ZLC' },
                    { color: CENTER_COLORS['ZDV'], label: 'ZDV' },
                    { color: CENTER_COLORS['ZAB'], label: 'ZAB' },
                    // South Central
                    { color: CENTER_COLORS['ZFW'], label: 'ZFW' },
                    { color: CENTER_COLORS['ZHU'], label: 'ZHU' },
                    { color: CENTER_COLORS['ZME'], label: 'ZME' },
                    { color: CENTER_COLORS['ZKC'], label: 'ZKC' },
                    // Midwest
                    { color: CENTER_COLORS['ZMP'], label: 'ZMP' },
                    { color: CENTER_COLORS['ZAU'], label: 'ZAU' },
                    { color: CENTER_COLORS['ZID'], label: 'ZID' },
                    // Southeast
                    { color: CENTER_COLORS['ZTL'], label: 'ZTL' },
                    { color: CENTER_COLORS['ZJX'], label: 'ZJX' },
                    { color: CENTER_COLORS['ZMA'], label: 'ZMA' },
                    // Northeast
                    { color: CENTER_COLORS['ZDC'], label: 'ZDC' },
                    { color: CENTER_COLORS['ZNY'], label: 'ZNY' },
                    { color: CENTER_COLORS['ZBW'], label: 'ZBW' },
                    { color: CENTER_COLORS['ZOB'], label: 'ZOB' }
                ];
                break;
            case 'dep_tracon':
            case 'arr_tracon':
                items = [
                    { color: DCC_REGION_COLORS['WEST'], label: 'West' },
                    { color: DCC_REGION_COLORS['SOUTH_CENTRAL'], label: 'South Central' },
                    { color: DCC_REGION_COLORS['MIDWEST'], label: 'Midwest' },
                    { color: DCC_REGION_COLORS['SOUTHEAST'], label: 'Southeast' },
                    { color: DCC_REGION_COLORS['NORTHEAST'], label: 'Northeast' }
                ];
                break;
            case 'dep_airport':
            case 'arr_airport':
                items = [
                    { color: AIRPORT_TIER_COLORS['CORE30'], label: 'Core 30' },
                    { color: AIRPORT_TIER_COLORS['OEP35'], label: 'OEP 35' },
                    { color: AIRPORT_TIER_COLORS['ASPM77'], label: 'ASPM 77' },
                    { color: AIRPORT_TIER_COLORS['OTHER'], label: 'Other' }
                ];
                break;
            case 'reroute_match':
                // Show only active (non-expired) public routes with their colors
                const activeRoutes = getActivePublicRoutes();
                items = activeRoutes.map(route => ({
                    color: route.color || '#17a2b8',
                    label: route.name || 'Route'
                }));
                // Add "No Match" at the end
                items.push({ color: '#666666', label: 'No Match' });
                break;
            default:
                items = [{ color: '#6c757d', label: mode }];
        }
        
        return items;
    }
    
    /**
     * Render color legend based on current color mode
     */
    function renderColorLegend() {
        const $legend = $('#nod_color_legend');
        if (!$legend.length) return;
        
        let items = [];
        const mode = state.traffic.colorMode;
        
        switch (mode) {
            case 'weight_class':
                items = [
                    { color: WEIGHT_CLASS_COLORS['SUPER'], label: 'Super (▬▬)' },
                    { color: WEIGHT_CLASS_COLORS['HEAVY'], label: 'Heavy (═)' },
                    { color: WEIGHT_CLASS_COLORS['LARGE'], label: 'Large (✈)' },
                    { color: WEIGHT_CLASS_COLORS['SMALL'], label: 'Small (○)' }
                ];
                break;
            case 'aircraft_category':
                items = [
                    { color: WEIGHT_CLASS_COLORS['SUPER'], label: 'J (Jet)' },
                    { color: WEIGHT_CLASS_COLORS['HEAVY'], label: 'H (Heavy)' },
                    { color: WEIGHT_CLASS_COLORS['LARGE'], label: 'L (Large)' },
                    { color: WEIGHT_CLASS_COLORS['SMALL'], label: 'S (Small)' }
                ];
                break;
            case 'aircraft_type':
                items = [
                    { color: AIRCRAFT_MANUFACTURER_COLORS['AIRBUS'], label: 'Airbus' },
                    { color: AIRCRAFT_MANUFACTURER_COLORS['BOEING'], label: 'Boeing' },
                    { color: AIRCRAFT_MANUFACTURER_COLORS['EMBRAER'], label: 'Embraer' },
                    { color: AIRCRAFT_MANUFACTURER_COLORS['BOMBARDIER'], label: 'Bombardier' },
                    { color: AIRCRAFT_MANUFACTURER_COLORS['MD_DC'], label: 'MD/DC' },
                    { color: AIRCRAFT_MANUFACTURER_COLORS['OTHER'], label: 'Other' }
                ];
                break;
            case 'aircraft_config':
                items = [
                    { color: AIRCRAFT_CONFIG_COLORS['CONC'], label: 'Concorde' },
                    { color: AIRCRAFT_CONFIG_COLORS['A380'], label: 'A380' },
                    { color: AIRCRAFT_CONFIG_COLORS['QUAD_JET'], label: 'Quad Jet' },
                    { color: AIRCRAFT_CONFIG_COLORS['HEAVY_TWIN'], label: 'Heavy Twin' },
                    { color: AIRCRAFT_CONFIG_COLORS['TRI_JET'], label: 'Tri Jet' },
                    { color: AIRCRAFT_CONFIG_COLORS['TWIN_JET'], label: 'Twin Jet' },
                    { color: AIRCRAFT_CONFIG_COLORS['REGIONAL_JET'], label: 'Regional' },
                    { color: AIRCRAFT_CONFIG_COLORS['TURBOPROP'], label: 'Turboprop' },
                    { color: AIRCRAFT_CONFIG_COLORS['PROP'], label: 'Prop' }
                ];
                break;
            case 'wake_category':
                // FAA RECAT categories (A-F)
                items = [
                    { color: RECAT_COLORS['A'], label: 'A (Super)' },
                    { color: RECAT_COLORS['B'], label: 'B (Upper Heavy)' },
                    { color: RECAT_COLORS['C'], label: 'C (Lower Heavy)' },
                    { color: RECAT_COLORS['D'], label: 'D (Upper Large)' },
                    { color: RECAT_COLORS['E'], label: 'E (Lower Large)' },
                    { color: RECAT_COLORS['F'], label: 'F (Small)' }
                ];
                break;
            case 'altitude':
                items = [
                    { color: ALTITUDE_BLOCK_COLORS['GROUND'], label: 'Ground' },
                    { color: ALTITUDE_BLOCK_COLORS['LOW'], label: '<FL100' },
                    { color: ALTITUDE_BLOCK_COLORS['LOWMED'], label: 'FL100-180' },
                    { color: ALTITUDE_BLOCK_COLORS['MED'], label: 'FL180-240' },
                    { color: ALTITUDE_BLOCK_COLORS['MEDHIGH'], label: 'FL240-290' },
                    { color: ALTITUDE_BLOCK_COLORS['HIGH'], label: 'FL290-350' },
                    { color: ALTITUDE_BLOCK_COLORS['VHIGH'], label: 'FL350-410' },
                    { color: ALTITUDE_BLOCK_COLORS['SUPERHIGH'], label: 'FL410+' }
                ];
                break;
            case 'speed':
                items = [
                    { color: SPEED_COLORS['GROUND'], label: '<50' },
                    { color: SPEED_COLORS['SLOW'], label: '50-150' },
                    { color: SPEED_COLORS['MEDIUM'], label: '150-250' },
                    { color: SPEED_COLORS['FAST'], label: '250-350' },
                    { color: SPEED_COLORS['VFAST'], label: '350-450' },
                    { color: SPEED_COLORS['JET'], label: '450-550' },
                    { color: SPEED_COLORS['SUPERSONIC'], label: '550+' }
                ];
                break;
            case 'status':
                // ADL flight statuses: PROPOSED, DEPARTING, ACTIVE, ARRIVING, COMPLETED
                items = [
                    { color: '#b07aa1', label: 'Proposed' },
                    { color: '#17a2b8', label: 'Departing' },
                    { color: '#28a745', label: 'Airborne' },
                    { color: '#f28e2b', label: 'Arriving' },
                    { color: '#6c757d', label: 'Arrived' },
                    { color: '#dc3545', label: 'GS Affected' },
                    { color: '#ffc107', label: 'EDCT' }
                ];
                break;
            case 'arr_dep':
                items = [
                    { color: ARR_DEP_COLORS['ARR'], label: 'Enroute' },
                    { color: ARR_DEP_COLORS['DEP'], label: 'Climbing' },
                    { color: '#666666', label: 'Parked' }
                ];
                break;
            case 'dcc_region':
                items = [
                    { color: DCC_REGION_COLORS['WEST'], label: 'West' },
                    { color: DCC_REGION_COLORS['SOUTH_CENTRAL'], label: 'South Central' },
                    { color: DCC_REGION_COLORS['MIDWEST'], label: 'Midwest' },
                    { color: DCC_REGION_COLORS['SOUTHEAST'], label: 'Southeast' },
                    { color: DCC_REGION_COLORS['NORTHEAST'], label: 'Northeast' }
                ];
                break;
            case 'eta_relative':
                // Matches getEtaRelativeColor discrete buckets
                items = [
                    { color: ETA_RELATIVE_COLORS['ETA_15'], label: '≤15m' },
                    { color: ETA_RELATIVE_COLORS['ETA_30'], label: '15-30m' },
                    { color: ETA_RELATIVE_COLORS['ETA_60'], label: '30m-1h' },
                    { color: ETA_RELATIVE_COLORS['ETA_120'], label: '1-2h' },
                    { color: ETA_RELATIVE_COLORS['ETA_180'], label: '2-3h' },
                    { color: ETA_RELATIVE_COLORS['ETA_300'], label: '3-5h' },
                    { color: ETA_RELATIVE_COLORS['ETA_480'], label: '5-8h' },
                    { color: ETA_RELATIVE_COLORS['ETA_OVER'], label: '>8h' }
                ];
                break;
            case 'eta_hour':
                // Cyclical hours - show representative samples
                items = [
                    { color: 'hsl(0, 85%, 50%)', label: '00Z' },
                    { color: 'hsl(60, 85%, 50%)', label: '04Z' },
                    { color: 'hsl(120, 85%, 50%)', label: '08Z' },
                    { color: 'hsl(180, 85%, 50%)', label: '12Z' },
                    { color: 'hsl(240, 85%, 50%)', label: '16Z' },
                    { color: 'hsl(300, 85%, 50%)', label: '20Z' }
                ];
                break;
            case 'carrier':
                items = [
                    { color: CARRIER_COLORS['AAL'], label: 'AAL' },
                    { color: CARRIER_COLORS['UAL'], label: 'UAL' },
                    { color: CARRIER_COLORS['DAL'], label: 'DAL' },
                    { color: CARRIER_COLORS['SWA'], label: 'SWA' },
                    { color: CARRIER_COLORS['FDX'], label: 'FDX' },
                    { color: CARRIER_COLORS[''], label: '...' }
                ];
                break;
            case 'operator_group':
                items = [
                    { color: OPERATOR_GROUP_COLORS['MAJOR'], label: 'Major' },
                    { color: OPERATOR_GROUP_COLORS['REGIONAL'], label: 'Regional' },
                    { color: OPERATOR_GROUP_COLORS['FREIGHT'], label: 'Freight' },
                    { color: OPERATOR_GROUP_COLORS['GA'], label: 'GA' },
                    { color: OPERATOR_GROUP_COLORS['MILITARY'], label: 'Military' },
                    { color: OPERATOR_GROUP_COLORS['OTHER'], label: 'Other' }
                ];
                break;
            case 'dep_center':
            case 'arr_center':
                items = Object.entries(CENTER_COLORS)
                    .filter(([k]) => k && k !== '')
                    .slice(0, 8)
                    .map(([k, v]) => ({ color: v, label: k }));
                items.push({ color: CENTER_COLORS[''], label: '...' });
                break;
            case 'dep_tracon':
            case 'arr_tracon':
                items = [
                    { color: DCC_REGION_COLORS['WEST'], label: 'West' },
                    { color: DCC_REGION_COLORS['SOUTH_CENTRAL'], label: 'S.Central' },
                    { color: DCC_REGION_COLORS['MIDWEST'], label: 'Midwest' },
                    { color: DCC_REGION_COLORS['SOUTHEAST'], label: 'Southeast' },
                    { color: DCC_REGION_COLORS['NORTHEAST'], label: 'Northeast' }
                ];
                break;
            case 'dep_airport':
            case 'arr_airport':
                items = [
                    { color: AIRPORT_TIER_COLORS['CORE30'], label: 'Core 30' },
                    { color: AIRPORT_TIER_COLORS['OEP35'], label: 'OEP 35' },
                    { color: AIRPORT_TIER_COLORS['ASPM77'], label: 'ASPM 77' },
                    { color: AIRPORT_TIER_COLORS['OTHER'], label: 'Other' }
                ];
                break;
            case 'reroute_match':
                // Show only active (non-expired) public routes with their colors
                const activeRoutesLegend = getActivePublicRoutes();
                items = activeRoutesLegend.map(route => ({
                    color: route.color || '#17a2b8',
                    label: route.name || 'Route'
                }));
                items.push({ color: '#666666', label: 'No Match' });
                break;
            default:
                items = [{ color: '#6c757d', label: 'Default' }];
        }
        
        $legend.html(items.map(item =>
            `<span style="display: inline-flex; align-items: center; margin-right: 6px;">
                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: ${item.color}; margin-right: 3px; border: 1px solid #333;"></span>
                <span style="font-size: 10px; color: #aaa;">${item.label}</span>
            </span>`
        ).join(''));
    }
    
    // =========================================
    // Advisory Functions
    // =========================================
    
    function showAdvisoryModal(id = null) {
        const modal = document.getElementById('advisoryModal');
        const title = document.getElementById('advisoryModalTitle');
        const form = document.getElementById('advisoryForm');
        
        // Reset form
        form.reset();
        document.getElementById('advisoryId').value = '';
        
        // Set default start time to now
        const now = new Date();
        document.getElementById('advisoryStart').value = now.toISOString().slice(0, 16);
        
        if (id) {
            title.textContent = 'Edit Advisory';
            loadAdvisoryForEdit(id);
        } else {
            title.textContent = 'New Advisory';
        }
        
        $('#advisoryModal').modal('show');
    }
    
    async function loadAdvisoryForEdit(id) {
        try {
            const response = await fetch(`api/nod/advisories.php?id=${id}`);
            const data = await response.json();
            
            if (data.advisory) {
                const adv = data.advisory;
                document.getElementById('advisoryId').value = adv.id;
                document.getElementById('advisoryType').value = adv.adv_type || '';
                document.getElementById('advisoryPriority').value = adv.priority || 2;
                document.getElementById('advisorySubject').value = adv.subject || '';
                document.getElementById('advisoryBody').value = adv.body_text || '';
                document.getElementById('advisoryStart').value = (adv.valid_start_utc || '').slice(0, 16);
                document.getElementById('advisoryEnd').value = (adv.valid_end_utc || '').slice(0, 16);
                document.getElementById('advisoryArea').value = adv.impacted_area || '';
                document.getElementById('advisoryFacilities').value = Array.isArray(adv.impacted_facilities) 
                    ? adv.impacted_facilities.join(', ') 
                    : (adv.impacted_facilities || '');
            }
        } catch (error) {
            console.error('[NOD] Error loading advisory:', error);
            alert('Error loading advisory');
        }
    }
    
    async function saveAdvisory() {
        const config = window.NOD_CONFIG || {};
        
        const id = document.getElementById('advisoryId').value;
        const facilities = document.getElementById('advisoryFacilities').value
            .split(',')
            .map(f => f.trim().toUpperCase())
            .filter(f => f);
        
        const payload = {
            adv_type: document.getElementById('advisoryType').value,
            priority: parseInt(document.getElementById('advisoryPriority').value) || 2,
            subject: document.getElementById('advisorySubject').value,
            body_text: document.getElementById('advisoryBody').value,
            valid_start_utc: document.getElementById('advisoryStart').value,
            valid_end_utc: document.getElementById('advisoryEnd').value || null,
            impacted_area: document.getElementById('advisoryArea').value || null,
            impacted_facilities: facilities.length > 0 ? facilities : null,
            created_by: config.userName || 'Unknown',
            updated_by: config.userName || 'Unknown'
        };
        
        if (id) {
            payload.id = parseInt(id);
        }
        
        try {
            const response = await fetch('api/nod/advisories.php', {
                method: id ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            
            const result = await response.json();
            
            if (result.success || result.id) {
                $('#advisoryModal').modal('hide');
                loadAdvisories();
            } else {
                alert('Error: ' + (result.error || 'Failed to save advisory'));
            }
        } catch (error) {
            console.error('[NOD] Error saving advisory:', error);
            alert('Error saving advisory');
        }
    }
    
    async function showAdvisoryDetail(id) {
        try {
            const response = await fetch(`api/nod/advisories.php?id=${id}`);
            const data = await response.json();
            
            if (data.advisory) {
                const adv = data.advisory;
                
                document.getElementById('advisoryDetailTitle').textContent = adv.adv_number || 'Advisory Details';
                document.getElementById('advisoryDetailBody').innerHTML = `
                    <div class="mb-3">
                        <span class="badge badge-secondary">${escapeHtml(adv.adv_type || '')}</span>
                        <span class="badge badge-${adv.priority == 1 ? 'danger' : (adv.priority == 3 ? 'secondary' : 'primary')}">
                            ${adv.priority == 1 ? 'HIGH' : (adv.priority == 3 ? 'LOW' : 'NORMAL')}
                        </span>
                    </div>
                    <h5>${escapeHtml(adv.subject || '')}</h5>
                    <pre class="bg-dark p-3 rounded text-light" style="white-space: pre-wrap; font-family: monospace; font-size: 12px;">${escapeHtml(adv.body_text || '')}</pre>
                    <hr class="border-secondary">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Valid:</strong> ${formatTimeRange(adv.valid_start_utc, adv.valid_end_utc)}</p>
                            <p class="mb-1"><strong>Area:</strong> ${escapeHtml(adv.impacted_area || 'N/A')}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Facilities:</strong> ${Array.isArray(adv.impacted_facilities) ? adv.impacted_facilities.join(', ') : (adv.impacted_facilities || 'N/A')}</p>
                            <p class="mb-1"><strong>Created:</strong> ${formatDateTime(adv.created_at)} by ${escapeHtml(adv.created_by || 'Unknown')}</p>
                        </div>
                    </div>
                `;
                
                $('#advisoryDetailModal').modal('show');
            }
        } catch (error) {
            console.error('[NOD] Error loading advisory detail:', error);
        }
    }
    
    // =========================================
    // Popups
    // =========================================
    
    function showFlightPopup(props, lngLat) {
        // Weight class symbols for display
        const wcSymbols = {
            'SUPER': '▬▬', 'J': '▬▬',
            'HEAVY': '═', 'H': '═',
            'LARGE': '✈', 'L': '✈',
            'SMALL': '○', 'S': '○'
        };
        const wcSymbol = wcSymbols[props.weight_class] || '?';
        
        // Format heading
        const hdg = props.heading ? `${String(props.heading).padStart(3, '0')}°` : '---';
        
        // Format altitude
        const alt = props.altitude ? `FL${Math.round(props.altitude / 100)}` : '---';
        
        // Status indicator
        let statusIcon = '';
        if (props.gs_affected) statusIcon = '<span style="color:#dc3545">⛔ GS</span>';
        else if (props.gdp_affected || props.edct_issued) statusIcon = '<span style="color:#ffc107">⏱ EDCT</span>';
        
        // Check for matching public routes
        const matchingRoutes = getMatchingRoutes(props);
        let routeMatchHtml = '';
        if (matchingRoutes.length > 0) {
            routeMatchHtml = `
                <div style="margin-top:6px; padding-top:6px; border-top:1px solid #444;">
                    <div style="font-size:9px; color:#888; margin-bottom:2px;">MATCHING ROUTES:</div>
                    ${matchingRoutes.map(r => `<span style="display:inline-block; margin-right:4px; padding:1px 4px; background:${r.color}; color:#fff; border-radius:2px; font-size:9px;">${escapeHtml(r.name)}</span>`).join('')}
                </div>
            `;
        }
        
        const html = `
            <div style="font-family: 'Consolas', monospace; font-size: 12px; min-width: 180px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                    <strong style="font-size:14px;">${escapeHtml(props.callsign || 'Unknown')}</strong>
                    <span title="Weight Class: ${props.weight_class || '?'}">${wcSymbol}</span>
                </div>
                <div style="color:#777; font-size:10px; margin-bottom:4px;">
                    ${escapeHtml(props.ac_type || '---')} (${props.weight_class || '?'})
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:11px;">
                    <tr>
                        <td style="color:#888;">Route:</td>
                        <td style="text-align:right;">${props.origin || '???'} → ${props.dest || '???'}</td>
                    </tr>
                    <tr>
                        <td style="color:#888;">Alt:</td>
                        <td style="text-align:right;">${alt}</td>
                    </tr>
                    <tr>
                        <td style="color:#888;">GS:</td>
                        <td style="text-align:right;">${props.speed || '---'} kts</td>
                    </tr>
                    <tr>
                        <td style="color:#888;">Hdg:</td>
                        <td style="text-align:right;">${hdg}</td>
                    </tr>
                    ${props.current_artcc ? `<tr>
                        <td style="color:#888;">ARTCC:</td>
                        <td style="text-align:right;">${props.current_artcc}</td>
                    </tr>` : ''}
                </table>
                ${props.route ? `
                <div style="margin-top:6px; padding-top:6px; border-top:1px solid #444;">
                    <div style="font-size:9px; color:#888; margin-bottom:2px;">FLIGHT PLAN:</div>
                    <div style="font-size:9px; color:#999; word-break:break-all; max-height:60px; overflow-y:auto;">${escapeHtml(props.route)}</div>
                </div>` : ''}
                ${statusIcon ? `<div style="margin-top:6px; text-align:center;">${statusIcon}</div>` : ''}
                ${routeMatchHtml}
                <div style="margin-top:8px; padding-top:6px; border-top:1px solid #444; text-align:center;">
                    <button class="btn btn-sm btn-outline-info show-route-btn"
                            data-flight-key="${escapeHtml(props.flight_key || props.callsign)}"
                            style="font-size:10px; padding:2px 8px;">
                        ${isFlightRouteDisplayed(props) ? '✓ Hide Route' : '➤ Show Route'}
                    </button>
                </div>
            </div>
        `;

        // Store flight data for route toggle
        state.lastPopupFlight = props;

        new maplibregl.Popup({ closeButton: false, offset: 15 })
            .setLngLat(lngLat)
            .setHTML(html)
            .addTo(state.map);
    }

    /**
     * Show detailed flight popup on right-click
     * Displays comprehensive flight information including all available data
     */
    function showDetailedFlightPopup(props, lngLat) {
        // Weight class symbols
        const wcSymbols = {
            'SUPER': '▬▬', 'J': '▬▬',
            'HEAVY': '═', 'H': '═',
            'LARGE': '✈', 'L': '✈',
            'SMALL': '○', 'S': '○'
        };
        const wcSymbol = wcSymbols[props.weight_class] || '?';

        // Format values
        const hdg = props.heading ? `${String(props.heading).padStart(3, '0')}°` : '---';
        const alt = props.altitude ? `FL${Math.round(props.altitude / 100)}` : '---';
        const altFt = props.altitude ? `${props.altitude.toLocaleString()} ft` : '---';

        // Status badges
        let statusBadges = '';
        if (props.gs_affected) statusBadges += '<span style="display:inline-block;margin:2px;padding:2px 6px;background:#dc3545;color:#fff;border-radius:3px;font-size:9px;">⛔ GS AFFECTED</span>';
        if (props.gdp_affected) statusBadges += '<span style="display:inline-block;margin:2px;padding:2px 6px;background:#ffc107;color:#000;border-radius:3px;font-size:9px;">⏱ GDP AFFECTED</span>';
        if (props.edct_issued) statusBadges += '<span style="display:inline-block;margin:2px;padding:2px 6px;background:#17a2b8;color:#fff;border-radius:3px;font-size:9px;">📋 EDCT ISSUED</span>';

        // Matching routes
        const matchingRoutes = getMatchingRoutes(props);
        let routeMatchHtml = '';
        if (matchingRoutes.length > 0) {
            routeMatchHtml = `
                <tr><td colspan="2" style="padding-top:8px;border-top:1px solid #444;">
                    <div style="font-size:9px;color:#888;margin-bottom:4px;">MATCHING PUBLIC ROUTES:</div>
                    ${matchingRoutes.map(r => `<span style="display:inline-block;margin:2px;padding:2px 6px;background:${r.color};color:#fff;border-radius:3px;font-size:9px;">${escapeHtml(r.name)}</span>`).join('')}
                </td></tr>
            `;
        }

        const html = `
            <div style="font-family:'Consolas',monospace;font-size:12px;min-width:280px;max-width:350px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;padding-bottom:6px;border-bottom:1px solid #444;">
                    <div>
                        <strong style="font-size:16px;color:#4a9eff;">${escapeHtml(props.callsign || 'Unknown')}</strong>
                        <span style="margin-left:8px;font-size:12px;" title="Weight Class: ${props.weight_class || '?'}">${wcSymbol}</span>
                    </div>
                    <span style="font-size:10px;color:#888;background:#333;padding:2px 6px;border-radius:3px;">DETAILED VIEW</span>
                </div>

                <table style="width:100%;border-collapse:collapse;font-size:11px;">
                    <tr style="border-bottom:1px solid #333;">
                        <td colspan="2" style="padding:4px 0;"><strong style="color:#aaa;">Aircraft Information</strong></td>
                    </tr>
                    <tr>
                        <td style="color:#666;padding:2px 0;">Type:</td>
                        <td style="text-align:right;">${escapeHtml(props.ac_type || '---')}</td>
                    </tr>
                    <tr>
                        <td style="color:#666;padding:2px 0;">Weight Class:</td>
                        <td style="text-align:right;">${props.weight_class || '---'}</td>
                    </tr>
                    ${props.aircraft_icao ? `<tr>
                        <td style="color:#666;padding:2px 0;">ICAO Code:</td>
                        <td style="text-align:right;">${escapeHtml(props.aircraft_icao)}</td>
                    </tr>` : ''}

                    <tr style="border-bottom:1px solid #333;">
                        <td colspan="2" style="padding:8px 0 4px 0;"><strong style="color:#aaa;">Flight Data</strong></td>
                    </tr>
                    <tr>
                        <td style="color:#666;padding:2px 0;">Route:</td>
                        <td style="text-align:right;font-weight:bold;">${props.origin || '???'} → ${props.dest || '???'}</td>
                    </tr>
                    <tr>
                        <td style="color:#666;padding:2px 0;">Altitude:</td>
                        <td style="text-align:right;">${alt} <span style="color:#666;font-size:10px;">(${altFt})</span></td>
                    </tr>
                    <tr>
                        <td style="color:#666;padding:2px 0;">Ground Speed:</td>
                        <td style="text-align:right;">${props.speed || '---'} kts</td>
                    </tr>
                    <tr>
                        <td style="color:#666;padding:2px 0;">Heading:</td>
                        <td style="text-align:right;">${hdg}</td>
                    </tr>
                    ${props.squawk ? `<tr>
                        <td style="color:#666;padding:2px 0;">Squawk:</td>
                        <td style="text-align:right;font-family:monospace;">${escapeHtml(props.squawk)}</td>
                    </tr>` : ''}

                    <tr style="border-bottom:1px solid #333;">
                        <td colspan="2" style="padding:8px 0 4px 0;"><strong style="color:#aaa;">Position & Airspace</strong></td>
                    </tr>
                    ${props.current_artcc ? `<tr>
                        <td style="color:#666;padding:2px 0;">Current ARTCC:</td>
                        <td style="text-align:right;">${escapeHtml(props.current_artcc)}</td>
                    </tr>` : ''}
                    ${props.dep_artcc ? `<tr>
                        <td style="color:#666;padding:2px 0;">Departure ARTCC:</td>
                        <td style="text-align:right;">${escapeHtml(props.dep_artcc)}</td>
                    </tr>` : ''}
                    ${props.arr_artcc ? `<tr>
                        <td style="color:#666;padding:2px 0;">Arrival ARTCC:</td>
                        <td style="text-align:right;">${escapeHtml(props.arr_artcc)}</td>
                    </tr>` : ''}
                    <tr>
                        <td style="color:#666;padding:2px 0;">Coordinates:</td>
                        <td style="text-align:right;font-size:10px;">${props.lat?.toFixed(4) || '---'}, ${props.lng?.toFixed(4) || '---'}</td>
                    </tr>

                    ${props.route ? `
                    <tr style="border-bottom:1px solid #333;">
                        <td colspan="2" style="padding:8px 0 4px 0;"><strong style="color:#aaa;">Flight Plan Route</strong></td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding:4px 0;">
                            <div style="font-size:10px;color:#888;background:#1a1a2e;padding:6px;border-radius:3px;word-break:break-all;max-height:80px;overflow-y:auto;">
                                ${escapeHtml(props.route)}
                            </div>
                        </td>
                    </tr>` : ''}

                    ${statusBadges ? `
                    <tr>
                        <td colspan="2" style="padding:8px 0 4px 0;text-align:center;">
                            ${statusBadges}
                        </td>
                    </tr>` : ''}

                    ${routeMatchHtml}
                </table>

                <div style="margin-top:10px;padding-top:8px;border-top:1px solid #444;text-align:center;">
                    <button class="btn btn-sm btn-outline-info show-route-btn"
                            data-flight-key="${escapeHtml(props.flight_key || props.callsign)}"
                            style="font-size:10px;padding:2px 10px;margin-right:4px;">
                        ${isFlightRouteDisplayed(props) ? '✓ Hide Route' : '➤ Show Route'}
                    </button>
                </div>
            </div>
        `;

        // Store flight data for route toggle
        state.lastPopupFlight = props;

        new maplibregl.Popup({ closeButton: true, closeOnClick: true, offset: 15, maxWidth: '400px' })
            .setLngLat(lngLat)
            .setHTML(html)
            .addTo(state.map);
    }

    // Event delegation for popup buttons (show route toggle)
    document.addEventListener('click', async function(e) {
        if (e.target.classList.contains('show-route-btn')) {
            const flightKey = e.target.dataset.flightKey;
            if (flightKey && state.lastPopupFlight) {
                // Find flight in current data (use traffic.data, fallback to lastPopupFlight)
                const flights = state.traffic && state.traffic.data ? state.traffic.data : [];
                const flight = flights.find(f =>
                    (f.flight_key || f.callsign) === flightKey
                ) || state.lastPopupFlight;

                if (flight) {
                    // Check if already displayed (will be removed)
                    const wasAlreadyDisplayed = isFlightRouteDisplayed(flight);

                    // Show loading state if we're adding a route
                    if (!wasAlreadyDisplayed) {
                        e.target.textContent = '⏳ Loading...';
                        e.target.disabled = true;
                    }

                    // Toggle route (async - fetches waypoints from API)
                    const isNowDisplayed = await toggleFlightRoute(flight);

                    // Update button text
                    e.target.disabled = false;
                    e.target.textContent = isNowDisplayed ? '✓ Hide Route' : '➤ Show Route';
                }
            }
        }
    });

    function showIncidentPopup(props, lngLat) {
        const html = `
            <div style="font-family: 'Consolas', monospace; font-size: 12px;">
                <strong style="color: ${props.color || '#ffc107'}">${escapeHtml(props.facility || 'Unknown')}</strong><br>
                Type: ${escapeHtml(props.incident_type || props.type || 'N/A')}<br>
                Status: ${escapeHtml(props.status || 'N/A')}<br>
                ${props.trigger_desc ? `Trigger: ${escapeHtml(props.trigger_desc)}<br>` : ''}
                ${props.incident_number ? `#${escapeHtml(props.incident_number)}<br>` : ''}
                <a href="jatoc.php" target="_blank">View in JATOC →</a>
            </div>
        `;
        
        new maplibregl.Popup({ closeButton: false, offset: 15 })
            .setLngLat(lngLat)
            .setHTML(html)
            .addTo(state.map);
    }
    
    function showSplitsPopup(props, lngLat) {
        const sectorId = props.id || props.sector || props.label || 'Unknown';
        const boundaryType = props.boundary_type || '';
        const boundaryLabel = boundaryType ? ` <span style="color:#888;font-size:10px;">(${boundaryType.toUpperCase()})</span>` : '';
        
        // Try to find frequency from various possible property names
        const frequency = props.frequency || props.freq || props.radio_freq || '';
        const positionName = props.position_name || 'Unassigned';
        const posColor = props.color || '#6c757d';
        
        const html = `
            <div style="font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; min-width: 140px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; padding-bottom:4px; border-bottom:1px solid #444;">
                    <strong style="color: ${posColor}; font-size: 13px;">${escapeHtml(positionName)}</strong>
                    ${frequency ? `<span style="color:#4fc3f7; font-weight:bold;">${escapeHtml(frequency)}</span>` : ''}
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:11px;">
                    <tr>
                        <td style="color:#888; padding:1px 0;">Sector:</td>
                        <td style="text-align:right; padding:1px 0;">${escapeHtml(sectorId)}${boundaryLabel}</td>
                    </tr>
                    <tr>
                        <td style="color:#888; padding:1px 0;">ARTCC:</td>
                        <td style="text-align:right; padding:1px 0;">${escapeHtml(props.artcc || 'N/A')}</td>
                    </tr>
                    <tr>
                        <td style="color:#888; padding:1px 0;">Config:</td>
                        <td style="text-align:right; padding:1px 0;">${escapeHtml(props.config_name || 'N/A')}</td>
                    </tr>
                </table>
            </div>
        `;
        
        new maplibregl.Popup({ closeButton: false, offset: 15 })
            .setLngLat(lngLat)
            .setHTML(html)
            .addTo(state.map);
    }
    
    /**
     * Show popup for a public route
     */
    function showRoutePopup(props, lngLat) {
        const routeName = props.name || props.route_name || 'Unknown Route';
        const origin = props.origin || props.dep || '???';
        const dest = props.destination || props.dest || props.arr || '???';
        const status = props.status || 'Active';
        
        const html = `
            <div style="font-family: 'Consolas', monospace; font-size: 12px; min-width: 160px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                    <strong style="font-size:13px; color:#28a745;">${escapeHtml(routeName)}</strong>
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:11px;">
                    <tr>
                        <td style="color:#888;">Route:</td>
                        <td style="text-align:right;">${escapeHtml(origin)} → ${escapeHtml(dest)}</td>
                    </tr>
                    <tr>
                        <td style="color:#888;">Status:</td>
                        <td style="text-align:right;">${escapeHtml(status)}</td>
                    </tr>
                    ${props.artcc ? `<tr>
                        <td style="color:#888;">ARTCC:</td>
                        <td style="text-align:right;">${escapeHtml(props.artcc)}</td>
                    </tr>` : ''}
                    ${props.remarks ? `<tr>
                        <td colspan="2" style="color:#aaa; font-size:10px; padding-top:4px;">${escapeHtml(props.remarks)}</td>
                    </tr>` : ''}
                </table>
            </div>
        `;
        
        new maplibregl.Popup({ closeButton: false, offset: 15 })
            .setLngLat(lngLat)
            .setHTML(html)
            .addTo(state.map);
    }
    
    /**
     * Deduplicate features based on type and ID
     */
    function deduplicateFeatures(features) {
        const seen = new Set();
        const unique = [];
        
        for (const f of features) {
            const layer = f.layer?.id || '';
            const type = getFeatureType(layer);
            
            // Create a unique key based on feature type and identifying properties
            let key;
            if (type === 'flight') {
                key = `flight:${f.properties.callsign || f.properties.id}`;
            } else if (type === 'route') {
                key = `route:${f.properties.name || f.properties.route_name || f.properties.id}`;
            } else if (type === 'incident') {
                key = `incident:${f.properties.incident_number || f.properties.facility || f.properties.id}`;
            } else if (type === 'split') {
                key = `split:${f.properties.id || f.properties.sector || f.properties.label}`;
            } else {
                key = `unknown:${JSON.stringify(f.properties).substring(0, 50)}`;
            }
            
            if (!seen.has(key)) {
                seen.add(key);
                unique.push({ ...f, _featureType: type, _featureKey: key });
            }
        }
        
        return unique;
    }
    
    /**
     * Determine feature type from layer ID
     */
    function getFeatureType(layerId) {
        if (layerId.includes('traffic')) return 'flight';
        if (layerId.includes('public-routes') || layerId.includes('route')) return 'route';
        if (layerId.includes('incident')) return 'incident';
        if (layerId.includes('split')) return 'split';
        return 'unknown';
    }
    
    /**
     * Show popup for a single feature
     */
    function showFeaturePopup(feature, lngLat) {
        const type = feature._featureType || getFeatureType(feature.layer?.id || '');
        
        switch (type) {
            case 'flight':
                showFlightPopup(feature.properties, lngLat);
                break;
            case 'route':
                showRoutePopup(feature.properties, lngLat);
                break;
            case 'incident':
                showIncidentPopup(feature.properties, lngLat);
                break;
            case 'split':
                showSplitsPopup(feature.properties, lngLat);
                break;
            default:
                console.warn('[NOD] Unknown feature type:', type, feature);
        }
    }
    
    /**
     * Show feature picker popup when multiple features overlap
     */
    function showFeaturePicker(features, lngLat) {
        // Build picker items
        const items = features.map((f, idx) => {
            const type = f._featureType;
            const props = f.properties;
            
            let icon, iconClass, label, sublabel;
            
            switch (type) {
                case 'flight':
                    icon = '✈';
                    iconClass = 'flight';
                    label = props.callsign || 'Unknown';
                    sublabel = `${props.origin || '???'} → ${props.dest || '???'}`;
                    break;
                case 'route':
                    icon = '↗';
                    iconClass = 'route';
                    label = props.name || props.route_name || 'Route';
                    sublabel = `${props.origin || props.dep || '???'} → ${props.destination || props.dest || '???'}`;
                    break;
                case 'incident':
                    icon = '⚠';
                    iconClass = 'incident';
                    label = props.facility || 'Incident';
                    sublabel = props.incident_type || props.type || props.status || '';
                    break;
                case 'split':
                    icon = '▣';
                    iconClass = 'split';
                    label = props.position_name || props.id || 'Sector';
                    // Show sector ID with ARTCC prefix (e.g., ZME67)
                    const sectorId = props.sector || props.id || props.label || '';
                    const sectorArtcc = props.artcc || '';
                    sublabel = sectorArtcc && sectorId ? `${sectorArtcc}${sectorId}` : (sectorArtcc || sectorId);
                    break;
                default:
                    icon = '?';
                    iconClass = '';
                    label = 'Unknown';
                    sublabel = '';
            }
            
            return `
                <div class="nod-feature-picker-item" data-feature-idx="${idx}">
                    <div class="nod-feature-picker-icon ${iconClass}">${icon}</div>
                    <div class="nod-feature-picker-label">
                        ${escapeHtml(label)}
                        ${sublabel ? `<div class="nod-feature-picker-sublabel">${escapeHtml(sublabel)}</div>` : ''}
                    </div>
                </div>
            `;
        }).join('');
        
        const html = `
            <div class="nod-feature-picker">
                <div class="nod-feature-picker-header">
                    ${features.length} features at this location
                </div>
                ${items}
            </div>
        `;
        
        // Create and show popup
        const popup = new maplibregl.Popup({ closeButton: true, offset: 15, className: 'nod-feature-picker-popup' })
            .setLngLat(lngLat)
            .setHTML(html)
            .addTo(state.map);
        
        // Add click handlers to picker items
        setTimeout(() => {
            const pickerItems = document.querySelectorAll('.nod-feature-picker-item');
            pickerItems.forEach(item => {
                item.addEventListener('click', () => {
                    const idx = parseInt(item.getAttribute('data-feature-idx'));
                    const feature = features[idx];
                    popup.remove();
                    showFeaturePopup(feature, lngLat);
                });
            });
        }, 50);
    }
    
    // =========================================
    // Utilities
    // =========================================
    
    function formatTimeRange(start, end) {
        const startStr = start ? formatTime(start) : '???';
        const endStr = end ? formatTime(end) : 'UFN';
        return `${startStr} - ${endStr}`;
    }
    
    function formatTime(dateStr) {
        if (!dateStr) return '???';
        try {
            const d = new Date(dateStr);
            return d.toISOString().substr(11, 5) + 'Z';
        } catch (e) {
            return dateStr.toString().substr(0, 5);
        }
    }
    
    function formatDateTime(dateStr) {
        if (!dateStr) return 'N/A';
        try {
            const d = new Date(dateStr);
            return d.toISOString().substr(0, 16).replace('T', ' ') + 'Z';
        } catch (e) {
            return dateStr;
        }
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    // =========================================
    // State Persistence
    // =========================================
    
    function saveUIState() {
        try {
            localStorage.setItem('nod_ui_state', JSON.stringify({
                panelCollapsed: state.ui.panelCollapsed,
                activeTab: state.ui.activeTab,
                layerControlsCollapsed: state.ui.layerControlsCollapsed,
                trafficControlsCollapsed: state.ui.trafficControlsCollapsed,
                layers: state.layers,
                trafficColorMode: state.traffic.colorMode,
                showTracks: state.traffic.showTracks
            }));
        } catch (e) {
            console.warn('[NOD] Could not save UI state:', e);
        }
    }
    
    function restoreUIState() {
        try {
            const saved = localStorage.getItem('nod_ui_state');
            if (saved) {
                const data = JSON.parse(saved);
                
                // Restore panel state
                if (data.panelCollapsed) {
                    togglePanel();
                }
                
                // Restore active tab
                if (data.activeTab) {
                    switchTab(data.activeTab);
                }
                
                // Restore layer visibility to state (but don't apply to map yet)
                if (data.layers) {
                    Object.entries(data.layers).forEach(([layer, visible]) => {
                        state.layers[layer] = visible;
                        const checkbox = document.getElementById('layer-' + layer);
                        if (checkbox) {
                            checkbox.checked = visible;
                        }
                    });
                }
                
                // Restore traffic color mode
                if (data.trafficColorMode) {
                    document.getElementById('traffic-color-mode').value = data.trafficColorMode;
                    state.traffic.colorMode = data.trafficColorMode;
                }
                
                // Restore track visibility
                if (data.showTracks !== undefined) {
                    state.traffic.showTracks = data.showTracks;
                    const tracksCheckbox = document.getElementById('traffic-tracks');
                    if (tracksCheckbox) {
                        tracksCheckbox.checked = data.showTracks;
                    }
                    // Tracks will be loaded after map init when toggle is applied
                }
            }
        } catch (e) {
            console.warn('[NOD] Could not restore UI state:', e);
        }
    }
    
    // =========================================
    // Draggable Panels
    // =========================================
    
    function initDraggablePanels() {
        const headers = document.querySelectorAll('[data-draggable]');
        
        headers.forEach(header => {
            const panelId = header.getAttribute('data-draggable');
            const panel = document.getElementById(panelId);
            if (!panel) return;
            
            let isDragging = false;
            let startX, startY, startLeft, startTop;
            
            // Restore saved position
            restorePanelPosition(panelId, panel);
            
            header.addEventListener('mousedown', (e) => {
                // Don't start drag if clicking on chevron
                if (e.target.classList.contains('fa-chevron-down') || e.target.classList.contains('fa-chevron-up')) {
                    return;
                }
                
                isDragging = true;
                panel.classList.add('dragging');
                panel.classList.add('user-positioned');
                
                // Get current position
                const rect = panel.getBoundingClientRect();
                const mapRect = document.getElementById('nod-map').getBoundingClientRect();
                
                startX = e.clientX;
                startY = e.clientY;
                startLeft = rect.left - mapRect.left;
                startTop = rect.top - mapRect.top;
                
                e.preventDefault();
            });
            
            document.addEventListener('mousemove', (e) => {
                if (!isDragging) return;
                
                const mapRect = document.getElementById('nod-map').getBoundingClientRect();
                const panelRect = panel.getBoundingClientRect();
                
                let newLeft = startLeft + (e.clientX - startX);
                let newTop = startTop + (e.clientY - startY);
                
                // Constrain to map bounds
                newLeft = Math.max(0, Math.min(newLeft, mapRect.width - panelRect.width));
                newTop = Math.max(0, Math.min(newTop, mapRect.height - panelRect.height));
                
                panel.style.left = newLeft + 'px';
                panel.style.top = newTop + 'px';
                panel.style.right = 'auto';
            });
            
            document.addEventListener('mouseup', () => {
                if (isDragging) {
                    isDragging = false;
                    panel.classList.remove('dragging');
                    savePanelPosition(panelId, panel);
                }
            });
        });
    }
    
    function savePanelPosition(panelId, panel) {
        try {
            const positions = JSON.parse(localStorage.getItem('nod_panel_positions') || '{}');
            positions[panelId] = {
                left: panel.style.left,
                top: panel.style.top
            };
            localStorage.setItem('nod_panel_positions', JSON.stringify(positions));
        } catch (e) {
            console.warn('[NOD] Could not save panel position:', e);
        }
    }
    
    function restorePanelPosition(panelId, panel) {
        try {
            const positions = JSON.parse(localStorage.getItem('nod_panel_positions') || '{}');
            if (positions[panelId]) {
                panel.style.left = positions[panelId].left;
                panel.style.top = positions[panelId].top;
                panel.style.right = 'auto';
                panel.classList.add('user-positioned');
            }
        } catch (e) {
            console.warn('[NOD] Could not restore panel position:', e);
        }
    }
    
    function resetPanelPositions() {
        localStorage.removeItem('nod_panel_positions');
        localStorage.removeItem('nod_legend_position');
        
        // Reset map layer controls
        const mapControls = document.getElementById('mapLayerControls');
        if (mapControls) {
            mapControls.style.left = '10px';
            mapControls.style.top = '10px';
            mapControls.style.right = 'auto';
            mapControls.classList.remove('user-positioned');
        }
        
        // Reset traffic controls
        const trafficControls = document.getElementById('trafficControls');
        if (trafficControls) {
            trafficControls.style.left = '';
            trafficControls.style.top = '10px';
            trafficControls.style.right = '';
            trafficControls.classList.remove('user-positioned');
        }
        
        // Reset legend position
        const legend = document.getElementById('mapColorLegend');
        if (legend) {
            legend.style.left = '10px';
            legend.style.bottom = '50px';
            legend.style.top = 'auto';
            legend.style.right = 'auto';
        }
    }
    
    /**
     * Initialize draggable legend
     */
    function initDraggableLegend() {
        const legend = document.getElementById('mapColorLegend');
        const handle = document.getElementById('mapLegendDragHandle');
        if (!legend || !handle) return;
        
        let isDragging = false;
        let startX, startY, startLeft, startTop;
        
        // Restore saved position
        try {
            const saved = localStorage.getItem('nod_legend_position');
            if (saved) {
                const pos = JSON.parse(saved);
                legend.style.left = pos.left;
                legend.style.top = pos.top;
                legend.style.bottom = 'auto';
                legend.style.right = 'auto';
            }
        } catch (e) {
            console.warn('[NOD] Could not restore legend position:', e);
        }
        
        handle.addEventListener('mousedown', (e) => {
            // Don't start drag if clicking on close button
            if (e.target.closest('.nod-map-legend-toggle')) return;
            
            isDragging = true;
            legend.classList.add('dragging');
            
            const rect = legend.getBoundingClientRect();
            const mapRect = document.getElementById('nod-map').getBoundingClientRect();
            
            startX = e.clientX;
            startY = e.clientY;
            startLeft = rect.left - mapRect.left;
            startTop = rect.top - mapRect.top;
            
            // Convert from bottom-left positioning to top-left
            legend.style.left = startLeft + 'px';
            legend.style.top = startTop + 'px';
            legend.style.bottom = 'auto';
            legend.style.right = 'auto';
            
            e.preventDefault();
        });
        
        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            
            const mapRect = document.getElementById('nod-map').getBoundingClientRect();
            const legendRect = legend.getBoundingClientRect();
            
            let newLeft = startLeft + (e.clientX - startX);
            let newTop = startTop + (e.clientY - startY);
            
            // Constrain to map bounds
            newLeft = Math.max(0, Math.min(newLeft, mapRect.width - legendRect.width));
            newTop = Math.max(0, Math.min(newTop, mapRect.height - legendRect.height));
            
            legend.style.left = newLeft + 'px';
            legend.style.top = newTop + 'px';
        });
        
        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                legend.classList.remove('dragging');
                
                // Save position
                try {
                    localStorage.setItem('nod_legend_position', JSON.stringify({
                        left: legend.style.left,
                        top: legend.style.top
                    }));
                } catch (e) {
                    console.warn('[NOD] Could not save legend position:', e);
                }
            }
        });
    }
    
    /**
     * Apply current layer visibility state to map
     * Called after map layers are initialized
     */
    function applyLayerState() {
        if (!state.map) return;
        
        const layerMappings = {
            'artcc': ['artcc-fill', 'artcc-lines', 'artcc-labels'],
            'tracon': ['tracon-fill', 'tracon-lines'],
            'high': ['high-lines'],
            'low': ['low-lines'],
            'superhigh': ['superhigh-lines'],
            'traffic': ['traffic-icons', 'traffic-circles-fallback', 'traffic-labels'],
            'tracks': ['tracks-lines', 'tracks-points'],
            'public-routes': ['public-routes-solid', 'public-routes-dashed', 'public-routes-fan', 'public-routes-lines'],
            'splits': ['splits-fill', 'splits-lines', 'splits-labels'],
            'incidents': ['incidents-fill', 'incidents-lines', 'incidents-labels'],
            'radar': ['weather-radar']
        };
        
        Object.entries(state.layers).forEach(([layerId, visible]) => {
            const mapLayers = layerMappings[layerId];
            if (mapLayers) {
                mapLayers.forEach(layer => {
                    if (state.map.getLayer(layer)) {
                        state.map.setLayoutProperty(layer, 'visibility', visible ? 'visible' : 'none');
                    }
                });
            }
        });
        
        // Apply track visibility state (separate from layers toggle)
        if (state.traffic.showTracks) {
            const trackLayers = ['tracks-lines', 'tracks-points'];
            trackLayers.forEach(layer => {
                if (state.map.getLayer(layer)) {
                    state.map.setLayoutProperty(layer, 'visibility', 'visible');
                }
            });
            // Load track data after traffic has loaded
            setTimeout(() => {
                if (state.traffic.filteredData.length > 0) {
                    loadTracks();
                }
            }, 1000);
        }
        
        console.log('[NOD] Applied layer visibility state');
    }
    
    // =========================================
    // Refresh Timers
    // =========================================
    
    function startRefreshTimers() {
        const config = window.NOD_CONFIG || {};
        
        // Traffic refresh (more frequent)
        state.timers.traffic = setInterval(loadTraffic, config.trafficRefreshInterval || 15000);
        
        // TMI refresh
        state.timers.tmi = setInterval(loadTMIData, config.refreshInterval || 30000);
        
        // Advisories refresh
        state.timers.advisories = setInterval(loadAdvisories, config.refreshInterval || 30000);
        
        // JATOC refresh
        state.timers.jatoc = setInterval(loadJATOCData, config.refreshInterval || 30000);
        
        // TMU OpLevel refresh (every 60 seconds - PERTI plans don't change frequently)
        state.timers.tmuOplevel = setInterval(loadTMUOpsLevel, 60000);
        
        // Splits refresh (every 5 minutes - changes less frequently)
        state.timers.splits = setInterval(loadActiveSplits, 300000);
    }
    
    // =========================================
    // Public API
    // =========================================
    
    window.NOD = {
        init,
        togglePanel,
        switchTab,
        toggleSection,
        toggleLayerControls,
        toggleTrafficControls,
        toggleLayer,
        toggleSplitsStrata,
        toggleTrafficLabels,
        toggleTrafficTracks,
        toggleRouteLabels,
        resetHiddenRouteLabels,
        setTrafficColorMode,
        setColorMode,
        toggleMapLegend,
        setLayerOpacity,
        showAdvisoryModal,
        saveAdvisory,
        showAdvisoryDetail,
        resetPanelPositions,
        applyFilters: collectFiltersFromUI,
        resetFilters,
        refresh: loadAllData,
        // Flight route functions
        toggleFlightRoute,
        clearFlightRoutes,
        drawAllFilteredRoutes,
        isFlightRouteDisplayed
    };
    
    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})();
