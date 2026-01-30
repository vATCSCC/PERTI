<?php /* route.php - merged groups_v4 + updated_v3 (header comment added) */ ?>


<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        $page_title = "vATCSCC Route Plotter";
        include("load/header.php");
    ?>

    <!-- Map Library Selection (Feature Flag) -->
    <script>
        // Feature flag: Check localStorage or URL param for MapLibre
        window.PERTI_USE_MAPLIBRE = (localStorage.getItem('useMapLibre') === 'true') || 
                                    (new URLSearchParams(window.location.search).get('maplibre') === 'true');
        
        // Write appropriate CSS based on selection
        if (window.PERTI_USE_MAPLIBRE) {
            document.write('<link href="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.css" rel="stylesheet" />');
        } else {
            document.write('<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>');
        }
    </script>
    
    <!-- MapLibre GL JS (loaded conditionally) -->
    <script>
        if (window.PERTI_USE_MAPLIBRE) {
            document.write('<script src="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.js"><\/script>');
            document.write('<script src="https://unpkg.com/@turf/turf@6/turf.min.js"><\/script>');
        }
    </script>
    
    <!-- Leaflet (loaded conditionally) --> 
    <script>
        if (!window.PERTI_USE_MAPLIBRE) {
            document.write('<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""><\/script>');
            document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet-omnivore/0.3.4/leaflet-omnivore.min.js"><\/script>');
            document.write('<script src="assets/js/leaflet.textpath.js"><\/script>');
            document.write('<script src="https://cdn.jsdelivr.net/npm/leaflet.geodesic"><\/script>');
        }
    </script>

    <style>
        .Incon {
            background: none;
            color: #fbff00;
            border: none;
            font-size: 1rem;
            font-family: Inconsolata;
        }

        /* Make the Plot Routes textarea monospace */
        #routeSearch {
            font-family: Inconsolata, monospace;
            font-size: 0.9rem;
        }

        /* Advisory builder: Facilities Included dropdown */
        .adv-facilities-wrapper {
            position: relative;
        }

        .adv-facilities-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 1050;
            display: none;
            background-color: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.15);
            border-radius: 0.25rem;
            padding: 0.5rem;
            max-height: 260px;
            overflow-y: auto;
            min-width: 260px;
            box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,0.15);
        }

        .adv-facilities-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            grid-column-gap: 0.75rem;
            grid-row-gap: 0.25rem;
        }

        .adv-facilities-grid .form-check {
            margin-bottom: 0.1rem;
        }

        .adv-facilities-grid label {
            font-size: 0.75rem;
            margin-bottom: 0;
        }

        /* ═══════════════════════════════════════════════════════════════════
           ADL LIVE FLIGHTS - TSD SYMBOLOGY STYLES
           ═══════════════════════════════════════════════════════════════════ */
        
        /* Controls bar styling - light theme */
        .adl-controls {
            background: #fff;
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Fix custom-switch padding to ensure toggle track is visible
           The switch track is 2.5rem wide and positioned at left: -3rem,
           so we need at least 3rem padding to prevent clipping */
        .custom-switch {
            padding-left: 3.25rem !important;
        }
        .custom-switch .custom-control-label {
            cursor: pointer;
        }
        
        /* Status badge states */
        #adl_status_badge {
            font-size: 0.75rem;
            padding: 4px 10px;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
        }
        #adl_status_badge.live {
            background-color: #28a745 !important;
            animation: adl-pulse 2s infinite;
        }
        @keyframes adl-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }

        /* Filter button */
        #adl_filter_toggle {
            padding: 4px 8px;
        }
        #adl_filter_toggle:disabled {
            opacity: 0.5;
        }

        /* Refresh status */
        #adl_refresh_status {
            color: #28a745;
            font-size: 0.7rem;
            font-family: 'Inconsolata', monospace;
        }

        /* Filter panel - floating over map */
        #map_wrapper {
            position: relative;
        }

        #adl_filter_panel {
            position: absolute;
            top: 10px;
            left: 10px;
            width: 300px;
            background: rgba(255,255,255,0.97);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            max-height: calc(100% - 20px);
            overflow-y: auto;
        }

        /* ═══════════════════════════════════════════════════════════════════
           ROUTE SYMBOLOGY PANEL STYLES
           ═══════════════════════════════════════════════════════════════════ */
        
        #route-symbology-panel {
            position: absolute;
            bottom: 10px;
            left: 10px;
            width: 340px;
            background: rgba(255,255,255,0.97);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            max-height: calc(100% - 100px);
            overflow-y: auto;
            display: none;
        }

        #route-symbology-panel.show {
            display: block;
        }

        /* Draggable panel support */
        #route-symbology-panel.dragging {
            opacity: 0.9;
            cursor: grabbing;
        }

        .symb-panel-header {
            background: linear-gradient(135deg, #239BCD 0%, #1a7aa8 100%);
            color: white;
            padding: 8px 12px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: grab;
            user-select: none;
        }

        .symb-panel-header:active {
            cursor: grabbing;
        }

        .symb-panel-header h6 {
            margin: 0;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .symb-panel-body {
            padding: 12px;
        }

        .symb-section {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            margin-bottom: 12px;
            overflow: hidden;
        }

        .symb-section-header {
            background: #f8f9fa;
            padding: 6px 10px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e9ecef;
        }

        .symb-section-header:hover {
            background: #e9ecef;
        }

        .symb-section-header .symb-section-title {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
        }

        .symb-section-header .symb-section-preview {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .symb-section-body {
            padding: 10px;
            display: none;
        }

        .symb-section.expanded .symb-section-body {
            display: block;
        }

        .symb-section.expanded .symb-section-header {
            border-bottom: 1px solid #dee2e6;
        }

        .symb-row {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
        }

        .symb-row:last-child {
            margin-bottom: 0;
        }

        .symb-label {
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            color: #6c757d;
            width: 60px;
            flex-shrink: 0;
        }

        .symb-control {
            flex-grow: 1;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .symb-control input[type="range"] {
            flex-grow: 1;
            height: 6px;
        }

        .symb-control input[type="color"] {
            width: 32px;
            height: 24px;
            padding: 0;
            border: 1px solid #ced4da;
            border-radius: 3px;
            cursor: pointer;
        }

        .symb-control input[type="color"]:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .symb-control .symb-value {
            font-size: 0.7rem;
            color: #495057;
            min-width: 35px;
            text-align: right;
            font-family: 'Inconsolata', monospace;
        }

        .symb-control select {
            font-size: 0.75rem;
            padding: 2px 6px;
            height: auto;
        }

        .symb-line-preview {
            width: 40px;
            height: 4px;
            border-radius: 2px;
            flex-shrink: 0;
        }

        .symb-line-preview.solid { background: #C70039; }
        .symb-line-preview.dashed { 
            background: repeating-linear-gradient(90deg, #C70039, #C70039 4px, transparent 4px, transparent 8px);
        }
        .symb-line-preview.fan {
            background: repeating-linear-gradient(90deg, #C70039, #C70039 1px, transparent 1px, transparent 4px);
        }

        .symb-footer {
            border-top: 1px solid #e9ecef;
            padding: 10px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
        }

        .symb-footer .symb-override-info {
            font-size: 0.7rem;
            color: #6c757d;
        }

        /* Symbology toggle button */
        #route_symbology_toggle {
            padding: 4px 8px;
        }

        /* Segment editor popup styles */
        .symbology-segment-editor {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .symb-editor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 8px;
            margin-bottom: 8px;
            border-bottom: 1px solid #e9ecef;
        }

        .symb-editor-header strong {
            font-size: 0.85rem;
            color: #239BCD;
        }

        .symb-editor-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: #6c757d;
            padding: 0;
            line-height: 1;
        }

        .symb-editor-body .form-group label {
            font-weight: 600;
            color: #495057;
            letter-spacing: 0.5px;
        }

        /* Weight class mini icons for legend */
        .adl-icon-jumbo::before { content: '✈'; font-size: 1.1em; }
        .adl-icon-heavy::before { content: '✈'; font-size: 0.95em; }
        .adl-icon-jet::before { content: '✈'; font-size: 0.85em; }
        .adl-icon-prop::before { content: '●'; font-size: 0.6em; }

        /* Color legend badges */
        .adl-color-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.65rem;
            font-family: 'Inconsolata', monospace;
            background: rgba(0,0,0,0.05);
        }
        .adl-color-badge .swatch {
            width: 10px;
            height: 10px;
            border-radius: 2px;
        }

        /* Route popup styling */
        .route-popup {
            min-width: 140px;
        }

        /* Custom color rules panel */
        #adl_custom_rules_panel {
            position: absolute;
            top: 50px;
            right: 60px;
            width: 320px;
            background: rgba(255,255,255,0.97);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1001;
            max-height: calc(100% - 60px);
            overflow-y: auto;
            display: none;
        }

        .custom-rule-item {
            padding: 6px 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
        }
        .custom-rule-item:last-child {
            border-bottom: none;
        }
        .custom-rule-item .rule-color {
            width: 16px;
            height: 16px;
            border-radius: 3px;
            flex-shrink: 0;
        }
        .custom-rule-item .rule-filter {
            color: #6c757d;
            font-size: 0.65rem;
            text-transform: uppercase;
        }
        .custom-rule-item .rule-values {
            flex-grow: 1;
            font-family: 'Inconsolata', monospace;
        }
        .custom-rule-item .rule-delete {
            color: #dc3545;
            cursor: pointer;
            opacity: 0.6;
        }
        .custom-rule-item .rule-delete:hover {
            opacity: 1;
        }

        /* Flight info popup styling */
        .adl-flight-popup {
            font-family: 'Inconsolata', monospace;
            font-size: 0.8rem;
        }
        .adl-flight-popup .callsign {
            font-size: 1rem;
            font-weight: bold;
        }
        .adl-flight-popup .route {
            word-break: break-all;
            max-height: 80px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 4px;
            color: #333;
        }

        /* Waypoint markers and tooltips for flight routes */
        .adl-waypoint-marker {
            cursor: pointer;
        }
        .adl-waypoint-tooltip {
            background: rgba(0, 0, 0, 0.85);
            border: none;
            border-radius: 3px;
            color: #fff;
            font-family: 'Inconsolata', monospace;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 6px;
            white-space: nowrap;
            letter-spacing: 0.5px;
        }
        .adl-waypoint-tooltip::before {
            border-top-color: rgba(0, 0, 0, 0.85) !important;
        }
        .adl-flight-route-behind {
            pointer-events: none;
        }
        .adl-flight-route-ahead {
            pointer-events: none;
        }

        /* SUA (Special Use Airspace) Popup Styling */
        .sua-popup {
            font-family: 'Inconsolata', 'Consolas', monospace;
            font-size: 0.85rem;
            padding: 4px 0;
        }
        .sua-popup strong {
            color: #239BCD;
        }

        /* ═══════════════════════════════════════════════════════════════════
           RESPONSIVE LAYOUT STYLES
           ═══════════════════════════════════════════════════════════════════ */
        
        /* Main layout container */
        .route-main-container {
            display: flex;
            flex-direction: column;
        }
        
        @media (min-width: 992px) {
            .route-main-container {
                flex-direction: row;
            }
        }
        
        /* Left panel (route controls) */
        .route-controls-panel {
            flex-shrink: 0;
        }
        
        @media (min-width: 1200px) {
            .route-controls-panel {
                position: sticky;
                top: 80px;
                max-height: calc(100vh - 100px);
                overflow-y: auto;
            }
        }
        
        /* Route controls card styling */
        .route-controls-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 1rem;
        }
        
        .route-controls-card h4 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #343a40;
            margin-bottom: 0;
        }
        
        /* Map section */
        .route-map-section {
            flex-grow: 1;
            min-width: 0;
        }
        
        /* Map container - responsive height */
        #graphic {
            height: 60vh;
            min-height: 400px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        @media (min-width: 768px) {
            #graphic {
                height: 65vh;
                min-height: 500px;
            }
        }
        
        @media (min-width: 992px) {
            #graphic {
                height: 75vh;
                min-height: 600px;
            }
        }
        
        @media (min-width: 1400px) {
            #graphic {
                height: 80vh;
                min-height: 700px;
            }
        }
        
        /* Route textarea responsive */
        #routeSearch {
            font-family: Inconsolata, monospace;
            font-size: 0.85rem;
            border-radius: 6px;
            resize: vertical;
        }
        
        @media (max-width: 991px) {
            #routeSearch {
                font-size: 0.8rem;
            }
        }
        
        /* Button groups - keep on one line, allow shrinking */
        .route-btn-group {
            display: flex;
            flex-wrap: nowrap;
            gap: 0.25rem;
            width: 100%;
        }
        
        .route-btn-group .btn {
            flex: 1 1 auto;
            white-space: nowrap;
            font-size: 0.8rem;
            padding: 0.375rem 0.5rem;
        }
        
        @media (max-width: 500px) {
            .route-btn-group .btn {
                font-size: 0.75rem;
                padding: 0.3rem 0.4rem;
            }
        }
        
        @media (max-width: 380px) {
            .route-btn-group .btn i.mr-1 {
                margin-right: 0 !important;
            }
            .route-btn-group .btn-text {
                display: none;
            }
        }
        
        /* Export buttons */
        .route-export-group {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 0.375rem;
        }
        
        .route-export-group .btn-group {
            flex-shrink: 0;
        }
        
        /* Help panel styling */
        #routeHelpPanel {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 0.75rem;
            border: 1px solid #e9ecef;
            margin-bottom: 0.75rem;
        }
        
        #routeHelpPanel ul {
            margin-bottom: 0;
        }
        
        #routeHelpPanel code {
            background: #e9ecef;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 0.8rem;
        }
        
        /* Filter bar responsive */
        .filter-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
        }
        
        .filter-toolbar .filter-input-group {
            flex: 1 1 200px;
            max-width: 300px;
            min-width: 150px;
        }
        
        @media (max-width: 767px) {
            .filter-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-toolbar .filter-input-group {
                max-width: none;
            }
            
            .filter-toolbar .adl-controls {
                justify-content: space-between;
                width: 100%;
            }
        }
        
        /* ADL Controls styling */
        .adl-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Section headers */
        .section-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 600;
        }
        
        /* Divider line */
        .route-divider {
            border: 0;
            border-top: 1px solid #e9ecef;
            margin: 0.75rem 0;
        }
        
        /* Compact form styling */
        .form-control-sm {
            border-radius: 4px;
        }
        
        /* Map toolbar area - single line with wrapping */
        .map-toolbar {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.75rem;
            border: 1px solid #e9ecef;
        }
        
        @media (max-width: 575px) {
            .map-toolbar {
                padding: 0.5rem;
            }
            
            .map-toolbar .section-label {
                display: none;
            }
        }
        
        /* Map wrapper positioning context */
        #map_wrapper {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
        }
        
        /* ADL Filter Panel responsive */
        #adl_filter_panel {
            max-width: calc(100% - 20px);
        }
        
        @media (max-width: 575px) {
            #adl_filter_panel {
                top: 10px !important;
                right: 10px !important;
                left: 10px !important;
                width: auto !important;
            }
        }
        
        /* Symbology panel responsive */
        @media (max-width: 575px) {
            #route-symbology-panel {
                width: calc(100% - 20px) !important;
                max-width: 340px;
                left: 10px !important;
                right: 10px !important;
            }
        }
        
        /* Custom rules panel responsive */
        @media (max-width: 767px) {
            #adl_custom_rules_panel {
                right: 10px !important;
                left: 10px !important;
                width: auto !important;
                max-width: none !important;
            }
        }
        
        /* Clean button styling */
        .btn-icon-only {
            padding: 0.375rem 0.5rem;
        }
        
        /* Improved badge styling */
        .badge-live {
            animation: pulse-glow 2s infinite;
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(35, 155, 205, 0.4); }
            50% { box-shadow: 0 0 0 4px rgba(35, 155, 205, 0); }
        }
        
        /* Mobile-friendly touch targets */
        @media (max-width: 767px) {
            .btn-sm {
                min-height: 38px;
                min-width: 38px;
            }
            
            .custom-control-label {
                padding-top: 2px;
            }
        }

        /* ═══════════════════════════════════════════════════════════════════
           PUBLIC ROUTES PANEL STYLES
           ═══════════════════════════════════════════════════════════════════ */
        
        #public_routes_panel {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 320px;
            background: rgba(255,255,255,0.97);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            max-height: calc(100% - 20px);
            overflow-y: auto;
        }

        .public-route-item {
            padding: 10px;
            border-bottom: 1px solid #e9ecef;
            cursor: pointer;
            transition: background 0.15s;
        }

        .public-route-item:hover {
            background: #f8f9fa;
        }

        .public-route-item:last-child {
            border-bottom: none;
        }

        .route-color-indicator {
            width: 4px;
            min-height: 40px;
            border-radius: 2px;
            flex-shrink: 0;
        }

        .route-actions {
            opacity: 0;
            transition: opacity 0.15s;
        }

        .public-route-item:hover .route-actions {
            opacity: 1;
        }

        /* Time status styling for routes */
        .public-route-item.pr-future {
            background: linear-gradient(90deg, rgba(23, 162, 184, 0.08) 0%, transparent 100%);
            border-left: 3px solid #17a2b8;
        }
        
        .public-route-item.pr-past {
            background: linear-gradient(90deg, rgba(108, 117, 125, 0.08) 0%, transparent 100%);
            border-left: 3px solid #adb5bd;
            opacity: 0.75;
        }
        
        .public-route-item.pr-past:hover {
            opacity: 1;
        }
        
        .public-route-item.pr-active {
            border-left: 3px solid #28a745;
        }

        /* Filter controls */
        .pr-filter-controls .pr-filter-btn {
            font-size: 0.7rem;
            padding: 2px 6px;
        }
        
        .pr-filter-controls .pr-filter-btn i {
            font-size: 0.65rem;
        }
        
        /* Visibility toggle labels */
        .pr-toggle-label {
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            user-select: none;
        }
        
        .pr-toggle-label input[type="checkbox"] {
            display: none;
        }
        
        .pr-toggle-label .badge {
            opacity: 0.5;
            transition: opacity 0.15s, transform 0.15s;
            font-size: 0.7rem;
            padding: 4px 8px;
        }
        
        .pr-toggle-label input[type="checkbox"]:checked + .badge {
            opacity: 1;
        }
        
        .pr-toggle-label:hover .badge {
            transform: scale(1.05);
        }
        
        .pr-show-all, .pr-hide-all {
            padding: 1px 5px !important;
            font-size: 0.65rem !important;
        }
        
        .pr-empty-message {
            font-size: 0.85rem;
        }
        
        /* Individually hidden routes - collapsed view */
        .public-route-item.pr-individually-hidden.pr-collapsed {
            padding: 4px 8px !important;
            margin-bottom: 2px !important;
            opacity: 0.6;
            background: rgba(0,0,0,0.1);
            border-left: 2px solid #6c757d;
        }
        
        .public-route-item.pr-individually-hidden.pr-collapsed:hover {
            opacity: 0.85;
            background: rgba(0,0,0,0.15);
        }
        
        .public-route-item.pr-individually-hidden.pr-collapsed .route-name {
            text-decoration: line-through;
            text-decoration-color: rgba(255,255,255,0.4);
            font-weight: normal !important;
        }
        
        .public-route-item.pr-individually-hidden.pr-collapsed .route-color-indicator {
            width: 8px !important;
            height: 8px !important;
            min-width: 8px !important;
            margin-top: 0 !important;
        }
        
        .route-visibility-toggle {
            font-size: 0.65rem !important;
            line-height: 1;
        }

        .badge-sm {
            font-size: 0.6rem;
            padding: 1px 4px;
            vertical-align: middle;
        }

        .btn-xs {
            padding: 2px 6px;
            font-size: 0.7rem;
        }

        /* Public routes indicator in toolbar */
        #public_routes_indicator {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            animation: pr-pulse 2s infinite;
        }

        @keyframes pr-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        /* ═══════════════════════════════════════════════════════════════════
           PLAYBOOK/CDR SEARCH PANEL STYLES
           ═══════════════════════════════════════════════════════════════════ */
        
        #pbcdr_search_panel {
            position: absolute;
            top: 10px;
            right: 340px;
            width: 420px;
            background: rgba(255,255,255,0.98);
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
            z-index: 1001;
            max-height: calc(100% - 20px);
            overflow: hidden;
            display: none;
        }

        #pbcdr_search_panel.show {
            display: flex;
            flex-direction: column;
        }

        /* Draggable state */
        #pbcdr_search_panel.dragging {
            opacity: 0.92;
            cursor: grabbing;
            box-shadow: 0 8px 24px rgba(0,0,0,0.25);
        }

        /* Collapsed state */
        #pbcdr_search_panel.collapsed {
            width: auto;
            min-width: 200px;
            max-height: none;
        }

        #pbcdr_search_panel.collapsed .pbcdr-tabs,
        #pbcdr_search_panel.collapsed .pbcdr-search-body,
        #pbcdr_search_panel.collapsed .pbcdr-results-header,
        #pbcdr_search_panel.collapsed .pbcdr-results-list,
        #pbcdr_search_panel.collapsed .pbcdr-bulk-actions {
            display: none;
        }

        #pbcdr_search_panel.collapsed .pbcdr-panel-header {
            border-radius: 8px;
        }

        /* Collapse button in header */
        .pbcdr-collapse-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
            margin-right: 6px;
        }

        .pbcdr-collapse-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        .pbcdr-collapse-btn i {
            font-size: 0.75rem;
            transition: transform 0.2s;
        }

        #pbcdr_search_panel.collapsed .pbcdr-collapse-btn i {
            transform: rotate(180deg);
        }

        .pbcdr-panel-header {
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
            color: white;
            padding: 10px 14px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            cursor: grab;
            user-select: none;
        }

        .pbcdr-panel-header:active {
            cursor: grabbing;
        }

        .pbcdr-panel-header h6 {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .pbcdr-tabs {
            display: flex;
            border-bottom: 1px solid #dee2e6;
            flex-shrink: 0;
        }

        .pbcdr-tab {
            flex: 1;
            padding: 10px 16px;
            text-align: center;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            color: #6c757d;
            background: #f8f9fa;
            border: none;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }

        .pbcdr-tab:hover {
            background: #e9ecef;
            color: #495057;
        }

        .pbcdr-tab.active {
            background: white;
            color: #6f42c1;
            border-bottom-color: #6f42c1;
        }

        .pbcdr-search-body {
            padding: 14px;
            flex-shrink: 0;
        }

        .pbcdr-filter-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .pbcdr-filter-group {
            flex: 1;
        }

        .pbcdr-filter-group label {
            display: block;
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #6c757d;
            margin-bottom: 3px;
            letter-spacing: 0.5px;
        }

        .pbcdr-filter-group input,
        .pbcdr-filter-group select {
            width: 100%;
            font-family: 'Inconsolata', monospace;
        }

        .pbcdr-results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 14px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            border-bottom: 1px solid #dee2e6;
            flex-shrink: 0;
        }

        .pbcdr-results-count {
            font-size: 0.75rem;
            color: #6c757d;
        }

        .pbcdr-results-count strong {
            color: #6f42c1;
        }

        .pbcdr-results-list {
            overflow-y: auto;
            max-height: 320px;
            flex-grow: 1;
        }

        .pbcdr-result-item {
            padding: 10px 14px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.15s;
        }

        .pbcdr-result-item:hover {
            background: #f8f5ff;
        }

        .pbcdr-result-item:last-child {
            border-bottom: none;
        }

        .pbcdr-result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .pbcdr-result-name {
            font-family: 'Inconsolata', monospace;
            font-size: 0.85rem;
            font-weight: 700;
            color: #6f42c1;
        }

        .pbcdr-result-type {
            font-size: 0.65rem;
            text-transform: uppercase;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: 600;
        }

        .pbcdr-result-type.playbook {
            background: #e2d9f3;
            color: #6f42c1;
        }

        .pbcdr-result-type.cdr {
            background: #d4edda;
            color: #155724;
        }

        .pbcdr-result-route {
            font-family: 'Inconsolata', monospace;
            font-size: 0.75rem;
            color: #495057;
            word-break: break-word;
            line-height: 1.4;
            max-height: 3.2em;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .pbcdr-result-meta {
            display: flex;
            gap: 8px;
            margin-top: 4px;
            flex-wrap: wrap;
        }

        .pbcdr-result-meta span {
            font-size: 0.65rem;
            color: #6c757d;
            background: #e9ecef;
            padding: 1px 5px;
            border-radius: 2px;
        }

        .pbcdr-result-actions {
            display: flex;
            gap: 4px;
            opacity: 0;
            transition: opacity 0.15s;
        }

        .pbcdr-result-item:hover .pbcdr-result-actions {
            opacity: 1;
        }

        .pbcdr-action-btn {
            padding: 2px 6px;
            font-size: 0.65rem;
            border-radius: 3px;
        }

        .pbcdr-no-results {
            padding: 30px;
            text-align: center;
            color: #6c757d;
        }

        .pbcdr-no-results i {
            font-size: 2rem;
            margin-bottom: 10px;
            color: #dee2e6;
        }

        /* Bulk actions footer */
        .pbcdr-bulk-actions {
            padding: 10px 14px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .pbcdr-select-all {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
        }

        /* Responsive */
        @media (max-width: 991px) {
            #pbcdr_search_panel {
                right: 10px;
                width: calc(100% - 20px);
                max-width: 420px;
            }
        }

        @media (max-width: 575px) {
            #pbcdr_search_panel {
                top: 10px;
                right: 10px;
                left: 10px;
                width: auto;
                max-width: none;
            }
            
            .pbcdr-filter-row {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>

    <!-- Info Bar Shared Styles -->
    <link rel="stylesheet" href="assets/css/info-bar.css">

    <!-- Embed Mode Styles -->
    <style>
        body.embed-mode {
            background: #1a1a2e;
            padding: 0 !important;
            margin: 0 !important;
            overflow: hidden;
        }
        body.embed-mode nav,
        body.embed-mode footer,
        body.embed-mode .jarallax,
        body.embed-mode .perti-info-bar,
        body.embed-mode .route-controls-panel,
        body.embed-mode #map_library_toggle,
        body.embed-mode .copyright-section {
            display: none !important;
        }
        body.embed-mode .container-fluid {
            padding: 0 !important;
            margin: 0 !important;
            max-width: 100% !important;
        }
        body.embed-mode .route-main-container {
            margin: 0 !important;
        }
        body.embed-mode .route-map-section {
            flex: 0 0 100% !important;
            max-width: 100% !important;
            padding: 0 !important;
        }
        body.embed-mode #map_wrapper {
            height: 100vh !important;
            min-height: 100vh !important;
        }
    </style>

    <!-- Embed Mode Detection -->
    <script>
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            window.PERTI_EMBED_MODE = (urlParams.get('embed') === '1');
            if (window.PERTI_EMBED_MODE) {
                document.documentElement.classList.add('embed-mode');
                document.addEventListener('DOMContentLoaded', function() {
                    document.body.classList.add('embed-mode');
                });
            }
        })();
    </script>
</head>

<body>

<?php
include('load/nav.php');
?>

    <section class="d-flex align-items-center position-relative bg-position-center overflow-hidden pt-6 jarallax bg-dark text-light" style="min-height: 250px" data-jarallax data-speed="0.3" style="pointer-events: all;">
        <div class="container-fluid pt-2 pb-5 py-lg-6">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%; height: 100vh;">
        </div>       
    </section>


    <div class="container-fluid mt-4 px-3 px-lg-4">
        <!-- Info Bar: UTC Clock, Local Times, Flight Stats -->
        <div class="perti-info-bar">
            <div class="row d-flex flex-wrap align-items-stretch" style="gap: 8px; margin: 0 -4px;">
                <!-- Current Time (UTC) -->
                <div class="col-auto px-1">
                    <div class="card shadow-sm perti-info-card perti-card-utc h-100">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <div class="perti-info-label">Current UTC</div>
                                <div id="route_utc_clock" class="perti-clock-display perti-clock-display-lg"></div>
                            </div>
                            <div class="ml-3">
                                <i class="far fa-clock fa-lg text-primary"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Time (US Time Zones) -->
                <div class="col-auto px-1">
                    <div class="card shadow-sm perti-info-card perti-card-local h-100">
                        <div class="card-body">
                            <div class="perti-info-label mb-1">US Local Times</div>
                            <div class="perti-clock-grid">
                                <div class="perti-clock-item">
                                    <div class="perti-clock-tz">GM</div>
                                    <div id="route_clock_guam" class="perti-clock-display perti-clock-display-md"></div>
                                </div>
                                <div class="perti-clock-item">
                                    <div class="perti-clock-tz">HI</div>
                                    <div id="route_clock_hi" class="perti-clock-display perti-clock-display-md"></div>
                                </div>
                                <div class="perti-clock-item">
                                    <div class="perti-clock-tz">AK</div>
                                    <div id="route_clock_ak" class="perti-clock-display perti-clock-display-md"></div>
                                </div>
                                <div class="perti-clock-item">
                                    <div class="perti-clock-tz">PT</div>
                                    <div id="route_clock_pac" class="perti-clock-display perti-clock-display-md"></div>
                                </div>
                                <div class="perti-clock-item">
                                    <div class="perti-clock-tz">MT</div>
                                    <div id="route_clock_mtn" class="perti-clock-display perti-clock-display-md"></div>
                                </div>
                                <div class="perti-clock-item">
                                    <div class="perti-clock-tz">CT</div>
                                    <div id="route_clock_cent" class="perti-clock-display perti-clock-display-md"></div>
                                </div>
                                <div class="perti-clock-item">
                                    <div class="perti-clock-tz">ET</div>
                                    <div id="route_clock_east" class="perti-clock-display perti-clock-display-md"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Global Flight Counts -->
                <div class="col-auto px-1">
                    <div class="card shadow-sm perti-info-card perti-card-global h-100">
                        <div class="card-body">
                            <div class="perti-info-label mb-1">
                                Global Flights
                                <span id="route_stats_global_total" class="badge badge-info badge-total ml-1">-</span>
                            </div>
                            <div class="perti-stat-grid">
                                <div class="perti-stat-item">
                                    <div class="perti-stat-category">D→D</div>
                                    <div id="route_stats_dd" class="perti-stat-value">-</div>
                                </div>
                                <div class="perti-stat-item">
                                    <div class="perti-stat-category">D→I</div>
                                    <div id="route_stats_di" class="perti-stat-value">-</div>
                                </div>
                                <div class="perti-stat-item">
                                    <div class="perti-stat-category">I→D</div>
                                    <div id="route_stats_id" class="perti-stat-value">-</div>
                                </div>
                                <div class="perti-stat-item">
                                    <div class="perti-stat-category">I→I</div>
                                    <div id="route_stats_ii" class="perti-stat-value">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Domestic Flight Counts -->
                <div class="col-auto px-1">
                    <div class="card shadow-sm perti-info-card perti-card-domestic h-100">
                        <div class="card-body">
                            <div class="perti-info-label mb-1">
                                Domestic Arrivals
                                <span id="route_stats_domestic_total" class="badge badge-success badge-total ml-1">-</span>
                            </div>
                            <div class="d-flex">
                                <!-- By DCC Region -->
                                <div class="perti-stat-section">
                                    <div class="perti-info-sublabel">DCC Region</div>
                                    <div class="perti-badge-group">
                                        <span class="badge badge-light" title="Northeast"><strong>NE</strong> <span id="route_stats_dcc_ne">-</span></span>
                                        <span class="badge badge-light" title="Southeast"><strong>SE</strong> <span id="route_stats_dcc_se">-</span></span>
                                        <span class="badge badge-light" title="Midwest"><strong>MW</strong> <span id="route_stats_dcc_mw">-</span></span>
                                        <span class="badge badge-light" title="South Central"><strong>SC</strong> <span id="route_stats_dcc_sc">-</span></span>
                                        <span class="badge badge-light" title="West"><strong>W</strong> <span id="route_stats_dcc_w">-</span></span>
                                    </div>
                                </div>
                                <!-- By Airport Tier -->
                                <div class="perti-stat-section">
                                    <div class="perti-info-sublabel">Airport Tier</div>
                                    <div class="perti-badge-group">
                                        <span class="badge badge-warning text-dark" title="ASPM 77 Airports"><strong>ASPM77</strong> <span id="route_stats_aspm77">-</span></span>
                                        <span class="badge badge-primary" title="OEP 35 Airports"><strong>OEP35</strong> <span id="route_stats_oep35">-</span></span>
                                        <span class="badge badge-danger" title="Core 30 Airports"><strong>Core30</strong> <span id="route_stats_core30">-</span></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Spacer -->
                <div class="col"></div>

                <!-- Advisory Org Config -->
                <div class="col-auto px-1">
                    <div class="card shadow-sm perti-info-card h-100">
                        <div class="card-body d-flex justify-content-between align-items-center py-2 px-3">
                            <button class="btn btn-sm btn-outline-secondary" onclick="AdvisoryConfig.showConfigModal();" data-toggle="tooltip" title="Switch between US DCC and Canadian NOC advisory formats">
                                <i class="fas fa-globe mr-1"></i> <span id="advisoryOrgDisplay">DCC</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row route-main-container">
            <!-- Left Panel: Route Controls -->
            <div class="col-12 col-lg-4 col-xl-3 mb-4 mb-lg-0 route-controls-panel">
                <div class="route-controls-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h4>Plot Routes</h4>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="routeHelpToggle">
                            <i class="fas fa-question-circle mr-1"></i>Help
                        </button>
                    </div>

                    <!-- Collapsible Help Panel (default: collapsed) -->
                    <div id="routeHelpPanel" class="text-left small" style="display: none;">
                        
                        <!-- Route Syntax -->
                        <p class="mb-1" style="font-weight: 600; color: #239BCD;"><i class="fas fa-route mr-1"></i> ROUTE SYNTAX</p>
                        <ul class="pl-3 mb-2">
                            <li><strong>Multiple routes:</strong> One route per line</li>
                            <li><strong>Colors:</strong> Add <code>;color</code> or <code>;#hex</code> at end (e.g., <code>FIX1 J60 FIX2;red</code>)</li>
                            <li><strong>Mandatory segments:</strong> Wrap in <code>&gt;&lt;</code> (e.g., <code>&gt;FIX1 J60 FIX2&lt;</code>)</li>
                            <li><strong>CDRs:</strong> Just type the code (e.g., <code>ACKMKEN0</code>)</li>
                            <li><strong>DP/STARs:</strong> Use <code>FIX.PROC#</code> syntax (e.g., <code>KJFK SKORR5.YNKEE</code> or <code>ALB.PARCH# KJFK</code>)</li>
                            <li><strong>Playbook:</strong> <code>PB.play_name.origins.destinations</code> (e.g., <code>PB.SERMN SOUTH.KJFK KLGA.KDCA</code>)</li>
                            <li><strong>Multi-origin/dest:</strong> Space-separated airports (e.g., <code>KJFK KLGA route KDCA KIAD</code>)</li>
                            <li><strong>Route groups:</strong>
<pre class="bg-light p-2 mb-1 rounded" style="font-family: Inconsolata, monospace; font-size: 0.7rem; margin-top: 4px;">[GROUP NAME];color
ROUTE1
ROUTE2</pre>
                            </li>
                        </ul>
                        
                        <!-- Toolbar -->
                        <p class="mb-1" style="font-weight: 600; color: #239BCD;"><i class="fas fa-tools mr-1"></i> MAP TOOLBAR</p>
                        <ul class="pl-3 mb-2">
                            <li><strong>Filter:</strong> Type airway names to highlight (space-separated)</li>
                            <li><strong><i class="fas fa-paint-brush"></i> Symbology:</strong> Configure line styles, colors, opacity, dash patterns, and fix/waypoint display</li>
                            <li><strong><i class="fas fa-plane"></i> Live:</strong> Toggle real-time VATSIM flight display</li>
                            <li><strong><i class="fas fa-filter"></i> Filters:</strong> Color flights by weight class, carrier, ARTCC, altitude, etc. Filter by aircraft type</li>
                        </ul>
                        
                        <!-- Actions -->
                        <p class="mb-1" style="font-weight: 600; color: #239BCD;"><i class="fas fa-mouse-pointer mr-1"></i> ACTIONS</p>
                        <ul class="pl-3 mb-2">
                            <li><strong>Plot:</strong> Render routes on map</li>
                            <li><strong>Copy:</strong> Copy route text to clipboard</li>
                            <li><strong>Labels:</strong> Toggle fix name labels on/off</li>
                            <li><strong>Export:</strong> GeoJSON, KML (Google Earth), GPKG bundle - includes route metadata, groups, playbook/CDR info, DP/STAR, symbology</li>
                        </ul>
                        
                        <!-- Map Interaction -->
                        <p class="mb-1" style="font-weight: 600; color: #239BCD;"><i class="fas fa-hand-pointer mr-1"></i> MAP INTERACTION</p>
                        <ul class="pl-3 mb-2">
                            <li><strong>Click route:</strong> View segment info, style options, label toggle</li>
                            <li><strong>Double-click route:</strong> Quick-open style editor</li>
                            <li><strong>Click flight:</strong> View callsign, route, altitude, speed</li>
                            <li><strong>Layer panel (top-right):</strong> Toggle boundaries, sectors, weather radar, SUA</li>
                        </ul>
                        
                        <!-- Advisory Builder -->
                        <p class="mb-1" style="font-weight: 600; color: #239BCD;"><i class="fas fa-file-alt mr-1"></i> REROUTE ADVISORY</p>
                        <ul class="pl-3 mb-0">
                            <li>Expand panel below map to generate VATCSCC reroute advisories</li>
                            <li>Configure validity times, included facilities, and traffic filters</li>
                            <li>Auto-populates from plotted routes</li>
                        </ul>
                        
                    </div>

                    <textarea class="form-control mb-3" name="routeSearch" id="routeSearch" rows="16" placeholder="Enter routes here..."></textarea>

                    <!-- Primary action buttons - all on one line -->
                    <div class="route-btn-group mb-3">
                        <button class="btn btn-sm btn-success" id="plot_r" title="Plot routes on map">
                            <i class="fas fa-pencil-alt mr-1"></i><span class="btn-text">Plot</span>
                        </button>
                        <button class="btn btn-sm btn-info" id="plot_c" title="Copy routes to clipboard">
                            <i class="far fa-copy mr-1"></i><span class="btn-text">Copy</span>
                        </button>
                        <button class="btn btn-sm btn-secondary" id="toggle_labels" type="button" onclick="toggleAllLabels();" title="Toggle fix labels">
                            <i class="fas fa-tags mr-1"></i><span class="btn-text">Labels</span>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" id="clear_routes" type="button" title="Clear all routes">
                            <i class="fas fa-trash-alt mr-1"></i><span class="btn-text">Clear</span>
                        </button>
                    </div>

                    <hr class="route-divider">

                    <!-- Export buttons -->
                    <div class="route-export-group">
                        <span class="section-label mr-2 align-self-center">Export:</span>
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-outline-primary" id="export_geojson" title="Export to GeoJSON format">
                                <i class="fas fa-file-code"></i> <span class="d-none d-sm-inline">GeoJSON</span>
                            </button>
                            <button class="btn btn-outline-primary" id="export_kml" title="Export to KML (Google Earth)">
                                <i class="fas fa-globe"></i> <span class="d-none d-sm-inline">KML</span>
                            </button>
                            <button class="btn btn-outline-primary" id="export_gpkg" title="Export to GeoPackage bundle">
                                <i class="fas fa-database"></i> <span class="d-none d-sm-inline">GPKG</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Map Section -->
            <div class="col-12 col-lg-8 col-xl-9 route-map-section">
                <!-- Map Toolbar - All controls in one line -->
                <div class="map-toolbar d-flex align-items-center flex-wrap" style="gap: 0.75rem;">
                    <!-- Filter Airways -->
                    <div class="d-flex align-items-center" style="gap: 0.5rem;">
                        <span class="section-label">Filter:</span>
                        <div class="input-group" style="width: auto; min-width: 160px; max-width: 220px;">
                            <input type="text" name="filter" id="filter" class="form-control form-control-sm" placeholder="Airways..." style="min-width: 100px;">
                            <div class="input-group-append">
                                <button class="btn btn-outline-danger btn-sm" type="button" id="filter_c" title="Clear filter">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Symbology Button -->
                    <button class="btn btn-sm btn-outline-primary" id="route_symbology_toggle" title="Configure route line styles">
                        <i class="fas fa-paint-brush"></i>
                    </button>
                    
                    <!-- Playbook/CDR Search Button -->
                    <button class="btn btn-sm btn-outline-secondary" id="pbcdr_search_toggle" title="Search Playbooks &amp; CDRs">
                        <i class="fas fa-search"></i> <span class="d-none d-md-inline">Routes</span>
                    </button>
                    
                    <!-- Separator -->
                    <div class="toolbar-separator d-none d-md-block" style="width: 1px; height: 24px; background: #dee2e6;"></div>
                    
                    <!-- ADL Live Flights Toggle -->
                    <div class="d-flex align-items-center" style="gap: 0.5rem;">
                        <div class="custom-control custom-switch mb-0">
                            <input type="checkbox" class="custom-control-input" id="adl_toggle">
                            <label class="custom-control-label" for="adl_toggle">
                                <span class="badge badge-dark" id="adl_status_badge">
                                    <i class="fas fa-plane mr-1"></i> Live
                                </span>
                            </label>
                        </div>
                        <button class="btn btn-sm btn-outline-info" id="adl_filter_toggle" disabled title="Configure filters">
                            <i class="fas fa-filter"></i>
                        </button>
                        <span class="small text-muted" id="adl_refresh_status"></span>
                    </div>
                    
                    <!-- Separator -->
                    <div class="toolbar-separator d-none d-md-block" style="width: 1px; height: 24px; background: #dee2e6;"></div>
                    
                    <!-- Public Routes Toggle -->
                    <div class="d-flex align-items-center" style="gap: 0.5rem;">
                        <div class="custom-control custom-switch mb-0">
                            <input type="checkbox" class="custom-control-input" id="public_routes_toggle">
                            <label class="custom-control-label" for="public_routes_toggle">
                                <span class="badge badge-secondary" id="public_routes_badge">
                                    <i class="fas fa-globe mr-1"></i> Routes
                                </span>
                            </label>
                        </div>
                        <button class="btn btn-sm btn-outline-success" id="public_routes_panel_btn" title="View public routes">
                            <i class="fas fa-list"></i>
                        </button>
                        <span class="d-none align-items-center" id="public_routes_indicator">
                            <i class="fas fa-broadcast-tower mr-1"></i>
                            <span class="badge badge-light">0</span> active
                        </span>
                    </div>
                </div>

                <!-- Map Container Wrapper -->
                <div id="map_wrapper">
                    <div id="placeholder"></div>
                    <div id="graphic"></div>
                        
                        <!-- ADL Filter Panel (floats over map) -->
                        <div id="adl_filter_panel" style="display: none;">
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small text-uppercase font-weight-bold" style="letter-spacing: 1px; color: #239BCD;">
                                        <i class="fas fa-filter mr-1"></i> Flight Filters
                                    </span>
                                    <button type="button" class="close" id="adl_filter_close" style="font-size: 1rem;">
                                        <span>&times;</span>
                                    </button>
                                </div>
                                <div class="mb-2">
                                    <label class="small mb-1 text-uppercase">Color By</label>
                                    <select class="form-control form-control-sm" id="adl_color_by" style="width: 100%;">
                                        <optgroup label="Aircraft">
                                            <option value="weight_class" selected>Weight Class</option>
                                            <option value="aircraft_category">Aircraft Category</option>
                                            <option value="aircraft_type">Aircraft Type (Manufacturer)</option>
                                            <option value="aircraft_config">Aircraft Configuration</option>
                                            <option value="wake_category">Wake Turbulence Category</option>
                                        </optgroup>
                                        <optgroup label="Operator">
                                            <option value="carrier">Carrier</option>
                                            <option value="operator_group">Operator Group</option>
                                        </optgroup>
                                        <optgroup label="Airspace">
                                            <option value="dcc_region">DCC Region</option>
                                            <option value="dep_center">Departure ARTCC</option>
                                            <option value="arr_center">Arrival ARTCC</option>
                                            <option value="dep_tracon">Departure TRACON</option>
                                            <option value="arr_tracon">Arrival TRACON</option>
                                        </optgroup>
                                        <optgroup label="Airport">
                                            <option value="dep_airport">Departure Airport Tier</option>
                                            <option value="arr_airport">Arrival Airport Tier</option>
                                        </optgroup>
                                        <optgroup label="Flight Data">
                                            <option value="altitude">Altitude Block</option>
                                            <option value="speed">Speed (±250kts)</option>
                                            <option value="arr_dep">Arrival / Departure</option>
                                            <option value="status">Flight Phase</option>
                                            <option value="eta_relative">ETA (Relative)</option>
                                            <option value="eta_hour">ETA (Hour)</option>
                                        </optgroup>
                                        <optgroup label="Traffic Management">
                                            <option value="reroute_match">Public Reroute Match</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="mb-2" id="adl_legend_container">
                                    <div id="adl_color_legend" class="d-flex flex-wrap small" style="gap: 6px;"></div>
                                </div>
                                <div class="mb-2">
                                    <label class="small mb-1 text-uppercase">Aircraft Type</label>
                                    <div class="d-flex flex-wrap">
                                        <div class="custom-control custom-checkbox mr-3">
                                            <input type="checkbox" class="custom-control-input adl-weight-filter" id="adl_wc_super" value="SUPER" checked>
                                            <label class="custom-control-label small" for="adl_wc_super">
                                                <span class="adl-icon-jumbo"></span> Jumbo
                                            </label>
                                        </div>
                                        <div class="custom-control custom-checkbox mr-3">
                                            <input type="checkbox" class="custom-control-input adl-weight-filter" id="adl_wc_heavy" value="HEAVY" checked>
                                            <label class="custom-control-label small" for="adl_wc_heavy">
                                                <span class="adl-icon-heavy"></span> Heavy
                                            </label>
                                        </div>
                                        <div class="custom-control custom-checkbox mr-3">
                                            <input type="checkbox" class="custom-control-input adl-weight-filter" id="adl_wc_large" value="LARGE" checked>
                                            <label class="custom-control-label small" for="adl_wc_large">
                                                <span class="adl-icon-jet"></span> Jet
                                            </label>
                                        </div>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input adl-weight-filter" id="adl_wc_small" value="SMALL" checked>
                                            <label class="custom-control-label small" for="adl_wc_small">
                                                <span class="adl-icon-prop"></span> Prop
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="small mb-1 text-uppercase">Origin / Destination</label>
                                    <div class="d-flex">
                                        <input type="text" class="form-control form-control-sm adl-filter-input mr-2" id="adl_origin" placeholder="Origin" title="ARTCC (ZNY) or Airport (KJFK)" style="width: 100px;">
                                        <span class="text-muted align-self-center mx-1">→</span>
                                        <input type="text" class="form-control form-control-sm adl-filter-input" id="adl_dest" placeholder="Dest" title="ARTCC (ZMA) or Airport (KMIA)" style="width: 100px;">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="small mb-1 text-uppercase">Carrier / Altitude</label>
                                    <div class="d-flex align-items-center">
                                        <input type="text" class="form-control form-control-sm adl-filter-input mr-2" id="adl_carrier" placeholder="Carrier" title="e.g., AAL, UAL" style="width: 70px;">
                                        <span class="text-muted small mr-1">FL</span>
                                        <input type="number" class="form-control form-control-sm adl-filter-input mr-1" id="adl_alt_min" placeholder="Min" style="width: 55px;">
                                        <span class="text-muted">-</span>
                                        <input type="number" class="form-control form-control-sm adl-filter-input ml-1" id="adl_alt_max" placeholder="Max" style="width: 55px;">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="small mb-1 text-uppercase">Routes</label>
                                    <div class="d-flex align-items-center">
                                        <button class="btn btn-sm btn-outline-info mr-2" id="adl_routes_show_all" title="Show routes for all filtered flights">
                                            <i class="fas fa-route"></i> Show All
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary mr-2" id="adl_routes_clear" title="Clear all routes">
                                            <i class="fas fa-times"></i> Clear
                                        </button>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="adl_routes_filter_only">
                                            <label class="custom-control-label small" for="adl_routes_filter_only" title="Only show routes for filtered flights">Filter</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                    <small class="text-muted">
                                        <span id="adl_stats_display"><strong>0</strong> shown</span> / 
                                        <span id="adl_stats_total"><strong>0</strong> total</span>
                                    </small>
                                    <div>
                                        <button class="btn btn-sm btn-outline-secondary mr-1" id="adl_filter_clear" title="Clear all filters">
                                            <i class="fas fa-eraser"></i>
                                        </button>
                                        <button class="btn btn-sm btn-info" id="adl_filter_apply" title="Apply filters">
                                            <i class="fas fa-check"></i> Apply
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Public Routes Panel (floats over map, top-right) -->
                        <div id="public_routes_panel" style="display: none;">
                            <div class="card-body p-0">
                                <div class="d-flex justify-content-between align-items-center p-2" style="background: linear-gradient(135deg, #28a745 0%, #218838 100%); color: white; border-radius: 8px 8px 0 0;">
                                    <span class="small text-uppercase font-weight-bold" style="letter-spacing: 1px;">
                                        <i class="fas fa-globe mr-1"></i> Public Routes
                                    </span>
                                    <div>
                                        <button type="button" class="btn btn-sm btn-outline-light mr-1" id="public_routes_refresh" title="Refresh routes">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                        <button type="button" class="close text-white" id="public_routes_panel_close" style="font-size: 1rem;">
                                            <span>&times;</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="p-2 border-bottom">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="public_routes_layer_toggle" checked>
                                        <label class="custom-control-label small" for="public_routes_layer_toggle">Show routes on map</label>
                                    </div>
                                </div>
                                <div id="public_routes_list" style="max-height: 400px; overflow-y: auto;">
                                    <div class="text-muted text-center py-3">
                                        <i class="fas fa-route mr-2"></i>No active public routes
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Playbook/CDR Search Panel (floats over map) -->
                        <div id="pbcdr_search_panel">
                            <div class="pbcdr-panel-header">
                                <h6><i class="fas fa-book mr-2"></i>Playbook &amp; CDR Search</h6>
                                <div class="d-flex align-items-center">
                                    <button type="button" class="pbcdr-collapse-btn" id="pbcdr_collapse_btn" title="Collapse/Expand">
                                        <i class="fas fa-chevron-up"></i>
                                    </button>
                                    <button type="button" class="close text-white" id="pbcdr_panel_close" style="font-size: 1rem;">
                                        <span>&times;</span>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Tabs -->
                            <div class="pbcdr-tabs">
                                <button class="pbcdr-tab active" data-tab="playbook">
                                    <i class="fas fa-book mr-1"></i> Playbooks
                                </button>
                                <button class="pbcdr-tab" data-tab="cdr">
                                    <i class="fas fa-road mr-1"></i> CDRs
                                </button>
                                <button class="pbcdr-tab" data-tab="all">
                                    <i class="fas fa-search mr-1"></i> All
                                </button>
                            </div>
                            
                            <!-- Search Filters -->
                            <div class="pbcdr-search-body">
                                <!-- Play/CDR Name -->
                                <div class="pbcdr-filter-row">
                                    <div class="pbcdr-filter-group" style="flex: 2;">
                                        <label><span id="pbcdr_name_label">Play Name</span></label>
                                        <input type="text" class="form-control form-control-sm" id="pbcdr_name" placeholder="e.g., SERMN, ABI, NORTHEAST..." autocomplete="off">
                                    </div>
                                    <div class="pbcdr-filter-group" style="flex: 1;">
                                        <label>Route Contains</label>
                                        <input type="text" class="form-control form-control-sm" id="pbcdr_route_text" placeholder="e.g., J60, BETTE" autocomplete="off">
                                    </div>
                                </div>
                                
                                <!-- Origin filters -->
                                <div class="pbcdr-filter-row">
                                    <div class="pbcdr-filter-group">
                                        <label>Origin Airport</label>
                                        <input type="text" class="form-control form-control-sm" id="pbcdr_orig_apt" placeholder="KJFK, KLGA..." autocomplete="off">
                                    </div>
                                    <div class="pbcdr-filter-group">
                                        <label>Origin TRACON</label>
                                        <input type="text" class="form-control form-control-sm" id="pbcdr_orig_tracon" placeholder="N90, A80..." autocomplete="off">
                                    </div>
                                    <div class="pbcdr-filter-group">
                                        <label>Origin ARTCC</label>
                                        <input type="text" class="form-control form-control-sm" id="pbcdr_orig_artcc" placeholder="ZNY, ZDC..." autocomplete="off">
                                    </div>
                                </div>
                                
                                <!-- Destination filters -->
                                <div class="pbcdr-filter-row">
                                    <div class="pbcdr-filter-group">
                                        <label>Dest Airport</label>
                                        <input type="text" class="form-control form-control-sm" id="pbcdr_dest_apt" placeholder="KMIA, KFLL..." autocomplete="off">
                                    </div>
                                    <div class="pbcdr-filter-group">
                                        <label>Dest TRACON</label>
                                        <input type="text" class="form-control form-control-sm" id="pbcdr_dest_tracon" placeholder="M98, P50..." autocomplete="off">
                                    </div>
                                    <div class="pbcdr-filter-group">
                                        <label>Dest ARTCC</label>
                                        <input type="text" class="form-control form-control-sm" id="pbcdr_dest_artcc" placeholder="ZMA, ZTL..." autocomplete="off">
                                    </div>
                                </div>
                                
                                <!-- Search buttons -->
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <button class="btn btn-sm btn-outline-secondary" id="pbcdr_clear_filters">
                                        <i class="fas fa-eraser mr-1"></i> Clear
                                    </button>
                                    <button class="btn btn-sm btn-primary" id="pbcdr_search_btn">
                                        <i class="fas fa-search mr-1"></i> Search
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Results Header -->
                            <div class="pbcdr-results-header">
                                <span class="pbcdr-results-count">
                                    <strong id="pbcdr_results_shown">0</strong> results
                                    <span id="pbcdr_results_limited" style="display: none;" class="text-warning">
                                        (limited to <span id="pbcdr_results_limit">100</span>)
                                    </span>
                                </span>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary btn-sm" id="pbcdr_add_selected" title="Add selected to textarea" disabled>
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                    <button class="btn btn-outline-success btn-sm" id="pbcdr_plot_selected" title="Plot selected routes" disabled>
                                        <i class="fas fa-pencil-alt"></i> Plot
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Results List -->
                            <div class="pbcdr-results-list" id="pbcdr_results_list">
                                <div class="pbcdr-no-results">
                                    <i class="fas fa-search d-block"></i>
                                    <p class="mb-0">Enter search criteria above</p>
                                </div>
                            </div>
                            
                            <!-- Bulk Actions Footer -->
                            <div class="pbcdr-bulk-actions">
                                <div class="pbcdr-select-all">
                                    <input type="checkbox" id="pbcdr_select_all">
                                    <label for="pbcdr_select_all" class="mb-0" style="cursor: pointer;">Select All</label>
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-danger btn-sm" id="pbcdr_clear_routes" title="Clear routes textarea">
                                        <i class="fas fa-eraser"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm" id="pbcdr_copy_selected" title="Copy to clipboard" disabled>
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Route Symbology Panel (floats over map, bottom-left) -->
                        <div id="route-symbology-panel">
                            <div class="symb-panel-header">
                                <h6><i class="fas fa-paint-brush mr-2"></i>Route Symbology</h6>
                                <button type="button" class="close text-white" id="route-symbology-close" style="font-size: 1rem;">
                                    <span>&times;</span>
                                </button>
                            </div>
                            <div class="symb-panel-body">
                                <!-- Solid Segments Section -->
                                <div class="symb-section expanded" data-type="solid">
                                    <div class="symb-section-header">
                                        <span class="symb-section-title">Mandatory (Solid)</span>
                                        <div class="symb-section-preview">
                                            <div class="symb-line-preview solid"></div>
                                            <i class="fas fa-chevron-down" style="font-size: 0.6rem; color: #6c757d;"></i>
                                        </div>
                                    </div>
                                    <div class="symb-section-body">
                                        <div class="symb-row">
                                            <span class="symb-label">Width</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-solid-width" min="0.5" max="8" step="0.5" value="3">
                                                <span class="symb-value" id="symb-solid-width-val">3.0</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Opacity</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-solid-opacity" min="0.1" max="1" step="0.1" value="1">
                                                <span class="symb-value" id="symb-solid-opacity-val">100%</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Color</span>
                                            <div class="symb-control">
                                                <input type="checkbox" id="symb-solid-color-enable" title="Override route color">
                                                <input type="color" id="symb-solid-color" value="#C70039" disabled>
                                                <span class="small text-muted">Override</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Style</span>
                                            <div class="symb-control">
                                                <select id="symb-solid-dash" class="form-control form-control-sm">
                                                    <option value="solid" selected>Solid</option>
                                                    <option value="dashed">Dashed (- - -)</option>
                                                    <option value="dotted">Dotted (···)</option>
                                                    <option value="dash-dot">Dash-Dot (-·-)</option>
                                                    <option value="long-dash">Long Dash (— —)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Dashed Segments Section -->
                                <div class="symb-section" data-type="dashed">
                                    <div class="symb-section-header">
                                        <span class="symb-section-title">Non-Mandatory (Dashed)</span>
                                        <div class="symb-section-preview">
                                            <div class="symb-line-preview dashed"></div>
                                            <i class="fas fa-chevron-down" style="font-size: 0.6rem; color: #6c757d;"></i>
                                        </div>
                                    </div>
                                    <div class="symb-section-body">
                                        <div class="symb-row">
                                            <span class="symb-label">Width</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-dashed-width" min="0.5" max="8" step="0.5" value="3">
                                                <span class="symb-value" id="symb-dashed-width-val">3.0</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Opacity</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-dashed-opacity" min="0.1" max="1" step="0.1" value="1">
                                                <span class="symb-value" id="symb-dashed-opacity-val">100%</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Color</span>
                                            <div class="symb-control">
                                                <input type="checkbox" id="symb-dashed-color-enable" title="Override route color">
                                                <input type="color" id="symb-dashed-color" value="#C70039" disabled>
                                                <span class="small text-muted">Override</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Style</span>
                                            <div class="symb-control">
                                                <select id="symb-dashed-dash" class="form-control form-control-sm">
                                                    <option value="solid">Solid</option>
                                                    <option value="dashed" selected>Dashed (- - -)</option>
                                                    <option value="dotted">Dotted (···)</option>
                                                    <option value="dash-dot">Dash-Dot (-·-)</option>
                                                    <option value="long-dash">Long Dash (— —)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Fan Segments Section -->
                                <div class="symb-section" data-type="fan">
                                    <div class="symb-section-header">
                                        <span class="symb-section-title">Fan / Radial</span>
                                        <div class="symb-section-preview">
                                            <div class="symb-line-preview fan"></div>
                                            <i class="fas fa-chevron-down" style="font-size: 0.6rem; color: #6c757d;"></i>
                                        </div>
                                    </div>
                                    <div class="symb-section-body">
                                        <div class="symb-row">
                                            <span class="symb-label">Width</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-fan-width" min="0.5" max="8" step="0.5" value="1.5">
                                                <span class="symb-value" id="symb-fan-width-val">1.5</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Opacity</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-fan-opacity" min="0.1" max="1" step="0.1" value="0.8">
                                                <span class="symb-value" id="symb-fan-opacity-val">80%</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Color</span>
                                            <div class="symb-control">
                                                <input type="checkbox" id="symb-fan-color-enable" title="Override route color">
                                                <input type="color" id="symb-fan-color" value="#C70039" disabled>
                                                <span class="small text-muted">Override</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Style</span>
                                            <div class="symb-control">
                                                <select id="symb-fan-dash" class="form-control form-control-sm">
                                                    <option value="solid">Solid</option>
                                                    <option value="dashed">Dashed (- - -)</option>
                                                    <option value="dotted" selected>Dotted (···)</option>
                                                    <option value="dash-dot">Dash-Dot (-·-)</option>
                                                    <option value="dense-dot">Dense Dot</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Global Overrides Section -->
                                <div class="symb-section" data-type="global">
                                    <div class="symb-section-header">
                                        <span class="symb-section-title">Global Overrides</span>
                                        <div class="symb-section-preview">
                                            <i class="fas fa-globe" style="color: #6c757d;"></i>
                                            <i class="fas fa-chevron-down" style="font-size: 0.6rem; color: #6c757d;"></i>
                                        </div>
                                    </div>
                                    <div class="symb-section-body">
                                        <p class="small text-muted mb-2">Apply to all segment types</p>
                                        <div class="symb-row">
                                            <span class="symb-label">Width</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-global-width" min="0.5" max="8" step="0.5" value="">
                                                <span class="symb-value" id="symb-global-width-val">Default</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Opacity</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-global-opacity" min="0.1" max="1" step="0.1" value="">
                                                <span class="symb-value" id="symb-global-opacity-val">Default</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Color</span>
                                            <div class="symb-control">
                                                <input type="checkbox" id="symb-global-color-enable" title="Override all colors">
                                                <input type="color" id="symb-global-color" value="#C70039" disabled>
                                                <span class="small text-muted">Override All</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Fixes / Waypoints Section -->
                                <div class="symb-section" data-type="fixes">
                                    <div class="symb-section-header">
                                        <span class="symb-section-title">Fixes / Waypoints</span>
                                        <div class="symb-section-preview">
                                            <i class="fas fa-map-marker-alt" style="color: #6c757d;"></i>
                                            <i class="fas fa-chevron-down" style="font-size: 0.6rem; color: #6c757d;"></i>
                                        </div>
                                    </div>
                                    <div class="symb-section-body">
                                        <!-- Visibility toggles -->
                                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                            <div class="custom-control custom-switch">
                                                <input type="checkbox" class="custom-control-input" id="symb-fixes-visible" checked>
                                                <label class="custom-control-label small" for="symb-fixes-visible">Show Fixes</label>
                                            </div>
                                            <button class="btn btn-xs btn-outline-secondary" id="symb-toggle-all-fixes" style="font-size: 0.7rem; padding: 2px 6px;">
                                                <i class="fas fa-eye-slash"></i> Hide All
                                            </button>
                                        </div>
                                        <div class="custom-control custom-switch mb-2">
                                            <input type="checkbox" class="custom-control-input" id="symb-fixes-labels-visible" checked>
                                            <label class="custom-control-label small" for="symb-fixes-labels-visible">Show Labels</label>
                                        </div>
                                        
                                        <!-- Circle settings -->
                                        <p class="small text-muted mb-1 mt-2" style="font-weight: 600;">CIRCLES</p>
                                        <div class="symb-row">
                                            <span class="symb-label">Radius</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-fixes-radius" min="1" max="10" step="0.5" value="4">
                                                <span class="symb-value" id="symb-fixes-radius-val">4.0</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Opacity</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-fixes-opacity" min="0.1" max="1" step="0.1" value="1">
                                                <span class="symb-value" id="symb-fixes-opacity-val">100%</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Color</span>
                                            <div class="symb-control">
                                                <input type="checkbox" id="symb-fixes-color-enable" title="Override route color">
                                                <input type="color" id="symb-fixes-color" value="#C70039" disabled>
                                                <span class="small text-muted">Override</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Stroke</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-fixes-stroke-width" min="0" max="3" step="0.5" value="1">
                                                <span class="symb-value" id="symb-fixes-stroke-width-val">1.0</span>
                                                <input type="color" id="symb-fixes-stroke-color" value="#000000" style="width: 28px; height: 20px; padding: 0; border: 1px solid #ced4da; border-radius: 3px;">
                                            </div>
                                        </div>
                                        
                                        <!-- Label settings -->
                                        <p class="small text-muted mb-1 mt-2" style="font-weight: 600;">LABELS</p>
                                        <div class="symb-row">
                                            <span class="symb-label">Size</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-fixes-label-size" min="6" max="16" step="1" value="10">
                                                <span class="symb-value" id="symb-fixes-label-size-val">10</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Color</span>
                                            <div class="symb-control">
                                                <input type="checkbox" id="symb-fixes-label-color-enable" title="Override route color">
                                                <input type="color" id="symb-fixes-label-color" value="#C70039" disabled>
                                                <span class="small text-muted">Override</span>
                                            </div>
                                        </div>
                                        <div class="symb-row">
                                            <span class="symb-label">Halo</span>
                                            <div class="symb-control">
                                                <input type="range" id="symb-fixes-halo-width" min="0" max="5" step="0.5" value="3">
                                                <span class="symb-value" id="symb-fixes-halo-width-val">3.0</span>
                                                <input type="color" id="symb-fixes-halo-color" value="#000000" style="width: 28px; height: 20px; padding: 0; border: 1px solid #ced4da; border-radius: 3px;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="symb-footer">
                                <span class="symb-override-info">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    <span id="symb-override-count">0 segments, 0 routes</span>
                                </span>
                                <div>
                                    <button class="btn btn-sm btn-outline-secondary mr-1" id="symb-clear-overrides" title="Clear segment/route overrides">
                                        <i class="fas fa-eraser"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" id="symb-reset-defaults" title="Reset to defaults">
                                        <i class="fas fa-undo"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- Reroute Advisory Builder (collapsible, below routes + map) -->
    <div class="container-fluid mt-4 mb-4">
        <div class="card bg-light text-dark">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-file-alt mr-2"></i> Reroute Advisory Builder</span>
                <button type="button" class="btn btn-sm btn-outline-dark" id="adv_panel_toggle">
                    Show
                </button>
            </div>

            <div class="card-body" id="adv_panel_body" style="display: none;">
                <p class="small text-muted mb-2">
                    Builds a vATCSCC-style <code>ROUTE RQD</code> advisory from the routes in the
                    <code>Plot Routes</code> box above (including <code>PB.*</code> playbooks and CDR codes).
                </p>

                <!-- Basic advisory metadata -->
                <div class="form-row">
                    <div class="form-group col-md-2 col-sm-4">
                        <label class="small mb-0" for="advNumber">Adv #</label>
                        <input type="text" class="form-control form-control-sm" id="advNumber" placeholder="001">
                    </div>
                    <div class="form-group col-md-2 col-sm-4">
                        <label class="small mb-0" for="advFacility">Facility</label>
                        <input type="text" class="form-control form-control-sm" id="advFacility" value="DCC">
                    </div>
                    <div class="form-group col-md-3 col-sm-4">
                        <label class="small mb-0" for="advDate">Date</label>
                        <input type="text" class="form-control form-control-sm" id="advDate" placeholder="MM/DD/YYYY">
                    </div>
                    <div class="form-group col-md-5 col-sm-8">
                        <label class="small mb-0" for="advAction">Type / Action</label>
                        <input type="text" class="form-control form-control-sm" id="advAction" value="ROUTE RQD">
                    </div>
                    <div class="form-group col-md-3 col-sm-4 d-flex align-items-end">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="advSplitFormat">
                            <label class="form-check-label small" for="advSplitFormat">Split Format (FROM/TO)</label>
                        </div>
                    </div>
                </div>

                <!-- Name / constrained area / reason -->
                <div class="form-row">
                    <div class="form-group col-md-3 col-sm-6">
                        <label class="small mb-0" for="advName">Name</label>
                        <input type="text" class="form-control form-control-sm" id="advName" placeholder="GOLDDR">
                    </div>
                    <div class="form-group col-md-3 col-sm-6">
                        <label class="small mb-0" for="advConstrainedArea">Constrained Area</label>
                        <input type="text" class="form-control form-control-sm" id="advConstrainedArea" placeholder="ZNY">
                    </div>
                    <div class="form-group col-md-6 col-sm-12">
                        <label class="small mb-0" for="advReason">Reason</label>
                        <input type="text" class="form-control form-control-sm" id="advReason" placeholder="WEATHER/TRAFFIC MANAGEMENT">
                    </div>
                </div>

                <!-- Effective time / TMI ID -->
                <div class="form-row">
                    <div class="form-group col-md-2 col-sm-4">
                        <label class="small mb-0" for="advValidStart">Start (DDHHMM)</label>
                        <input type="text" class="form-control form-control-sm" id="advValidStart" placeholder="011500">
                    </div>
                    <div class="form-group col-md-2 col-sm-4">
                        <label class="small mb-0" for="advValidEnd">End (DDHHMM)</label>
                        <input type="text" class="form-control form-control-sm" id="advValidEnd" placeholder="012300">
                    </div>
                    <div class="form-group col-md-3 col-sm-4">
                        <label class="small mb-0" for="advEffectiveTime">Effective (Auto)</label>
                        <input type="text" class="form-control form-control-sm" id="advEffectiveTime" readonly style="background: #e9ecef;">
                    </div>
                    <div class="form-group col-md-2 col-sm-4">
                        <label class="small mb-0" for="advTmiId">TMI ID</label>
                        <input type="text" class="form-control form-control-sm" id="advTmiId" placeholder="">
                    </div>
                    <div class="form-group col-md-3 col-sm-8 adv-facilities-wrapper">
                        <label class="small mb-0" for="advFacilities">
                            Facilities Included
                            <span id="advFacilitiesAutoBadge" class="badge badge-info ml-1" style="display: none; font-size: 0.65rem;" title="Auto-calculated from routes">AUTO</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control form-control-sm" id="advFacilities" placeholder="ZBW/ZNY/ZDC">
                            <div class="input-group-append">
                                <button class="btn btn-outline-info" type="button" id="advFacilitiesAuto" title="Auto-calculate from routes using GIS">
                                    <i class="fas fa-magic"></i>
                                </button>
                                <button class="btn btn-outline-secondary" type="button" id="advFacilitiesToggle">
                                    <i class="fas fa-caret-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="adv-facilities-dropdown" id="advFacilitiesDropdown">
                            <div class="adv-facilities-grid" id="advFacilitiesGrid"></div>
                            <div class="d-flex justify-content-between mt-2 pt-2 border-top" style="gap: 0.5rem;">
                                <button class="btn btn-sm btn-outline-secondary flex-fill" id="advFacilitiesClear" type="button">Clear</button>
                                <button class="btn btn-sm btn-outline-secondary flex-fill" id="advFacilitiesSelectAll" type="button">All</button>
                                <button class="btn btn-sm btn-outline-info flex-fill" id="advFacilitiesSelectUs" type="button">US Only</button>
                                <button class="btn btn-sm btn-primary flex-fill" id="advFacilitiesApply" type="button">Apply</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Include Traffic / Prob Extension -->
                <div class="form-row">
                    <div class="form-group col-md-6 col-sm-12">
                        <label class="small mb-0" for="advIncludeTraffic">Include Traffic</label>
                        <input type="text" class="form-control form-control-sm" id="advIncludeTraffic" placeholder="e.g., KJFK/KLGA DEPARTURES TO KCLT/KATL">
                    </div>
                    <div class="form-group col-md-3 col-sm-6">
                        <label class="small mb-0" for="advProb">Prob Extension</label>
                        <select class="form-control form-control-sm" id="advProb">
                            <option value="">--</option>
                            <option value="LOW">LOW</option>
                            <option value="MODERATE">MODERATE</option>
                            <option value="HIGH">HIGH</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3 col-sm-6">
                        <label class="small mb-0" for="advMods">Modifications</label>
                        <input type="text" class="form-control form-control-sm" id="advMods" placeholder="e.g., AMDT, CNCL">
                    </div>
                </div>

                <!-- Restrictions / Remarks -->
                <div class="form-row">
                    <div class="form-group col-md-6 col-sm-12">
                        <label class="small mb-0" for="advRestrictions">Restrictions</label>
                        <input type="text" class="form-control form-control-sm" id="advRestrictions" placeholder="e.g., FL310 AND ABOVE">
                    </div>
                    <div class="form-group col-md-6 col-sm-12">
                        <label class="small mb-0" for="advRemarks">Remarks</label>
                        <input type="text" class="form-control form-control-sm" id="advRemarks" placeholder="Optional additional remarks">
                    </div>
                </div>

                <!-- Generate / copy buttons -->
                <div class="d-flex align-items-center flex-wrap mt-2">
                    <button class="btn btn-primary mr-2 mb-1" id="adv_generate">
                        <i class="fas fa-file-alt mr-1"></i> Generate
                    </button>
                    <button class="btn btn-secondary mr-2 mb-1" id="adv_copy">
                        <i class="far fa-copy mr-1"></i> Copy
                    </button>
                    <button class="btn btn-success mr-2 mb-1" id="adv_publish" title="Publish this route so all users can see it on the map">
                        <i class="fas fa-globe mr-1"></i> Publish
                    </button>
                    <button class="btn btn-warning mb-1" id="adv_draft_tmi"
                            title="Open TMI Publisher with plotted routes for coordination workflow">
                        <i class="fas fa-paper-plane mr-1"></i> Draft TMI Reroute
                    </button>
                </div>

                <hr>

                <!-- Output text area -->
                <label class="small mb-1">Advisory Output</label>
                <textarea class="form-control" id="advOutput" rows="12"
                          style="font-family: Inconsolata, monospace; font-size: 0.85rem; white-space: pre; overflow-x: auto;"></textarea>
            </div>
        </div>
    </div>

<?php
include('load/footer.php');
?>

<!-- Advisory Organization Config Modal -->
<div class="modal fade" id="advisoryOrgModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-globe mr-2"></i>Advisory Organization</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="advisoryOrg" id="orgDCC" value="DCC">
                    <label class="form-check-label" for="orgDCC">
                        <strong>US DCC</strong><br><small class="text-muted">vATCSCC ADVZY ... DCC</small>
                    </label>
                </div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="radio" name="advisoryOrg" id="orgNOC" value="NOC">
                    <label class="form-check-label" for="orgNOC">
                        <strong>Canadian NOC</strong><br><small class="text-muted">vNAVCAN ADVZY ... NOC</small>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="advisoryOrgSaveBtn" onclick="AdvisoryConfig.saveOrg()">Save</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/advisory-config.js"></script>

<!-- Phase Colors Configuration -->
<script src="assets/js/config/phase-colors.js"></script>
<!-- Filter Colors Configuration -->
<script src="assets/js/config/filter-colors.js"></script>

<!-- Graphical Map Generation (Leaflet or MapLibre) -->
<script src="assets/js/awys.js"></script>
<script src="assets/js/procs_enhanced.js"></script>
<script src="assets/js/route-symbology.js"></script>
<?php if (defined('SWIM_PUBLIC_ROUTES_KEY') && SWIM_PUBLIC_ROUTES_KEY): ?>
<script>window.SWIM_PUBLIC_ROUTES_KEY = '<?php echo SWIM_PUBLIC_ROUTES_KEY; ?>';</script>
<?php endif; ?>
<script src="assets/js/public-routes.js"></script>
<script src="assets/js/playbook-cdr-search.js"></script>
<script>
    // Load appropriate map library based on feature flag
    if (window.PERTI_USE_MAPLIBRE) {
        document.write('<script src="assets/js/route-maplibre.js?v=20260130b"><\/script>');
    } else {
        document.write('<script src="assets/js/route.js?v=20260130a"><\/script>');
    }
</script>

<!-- Simple JS to toggle Plot Routes help panel -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('routeHelpToggle');
    var panel = document.getElementById('routeHelpPanel');

    if (btn && panel) {
        btn.addEventListener('click', function () {
            var isHidden = (panel.style.display === 'none' || panel.style.display === '');
            panel.style.display = isHidden ? 'block' : 'none';
            btn.textContent = isHidden ? 'Hide Help' : 'Show Help';
        });
    }

    // Clear Routes Button
    var clearRoutesBtn = document.getElementById('clear_routes');
    var routeTextarea = document.getElementById('routeSearch');
    if (clearRoutesBtn && routeTextarea) {
        clearRoutesBtn.addEventListener('click', function() {
            if (routeTextarea.value.trim() === '') {
                return; // Nothing to clear
            }
            if (confirm('Clear all routes from the textarea?')) {
                routeTextarea.value = '';
                // Focus the textarea after clearing
                routeTextarea.focus();
            }
        });
    }

    // Route Symbology Panel Toggle
    var symbToggle = document.getElementById('route_symbology_toggle');
    var symbPanel = document.getElementById('route-symbology-panel');
    var symbClose = document.getElementById('route-symbology-close');

    if (symbToggle && symbPanel) {
        symbToggle.addEventListener('click', function() {
            symbPanel.classList.toggle('show');
            if (symbPanel.classList.contains('show') && typeof RouteSymbology !== 'undefined') {
                RouteSymbology.initPanel();
            }
        });
    }

    if (symbClose && symbPanel) {
        symbClose.addEventListener('click', function() {
            symbPanel.classList.remove('show');
        });
    }

    // Symbology Section Collapse Toggle
    document.querySelectorAll('.symb-section-header').forEach(function(header) {
        header.addEventListener('click', function(e) {
            // Don't toggle if clicking on the panel header (drag area)
            if (header.closest('.symb-panel-header')) return;
            var section = header.closest('.symb-section');
            section.classList.toggle('expanded');
        });
    });

    // Make symbology panel draggable
    (function() {
        var panel = document.getElementById('route-symbology-panel');
        var header = panel ? panel.querySelector('.symb-panel-header') : null;
        if (!panel || !header) return;

        var isDragging = false;
        var startX, startY, startLeft, startTop;

        header.addEventListener('mousedown', function(e) {
            // Don't start drag if clicking close button
            if (e.target.closest('.close')) return;
            
            isDragging = true;
            panel.classList.add('dragging');
            
            var rect = panel.getBoundingClientRect();
            var parentRect = panel.offsetParent.getBoundingClientRect();
            
            startX = e.clientX;
            startY = e.clientY;
            startLeft = rect.left - parentRect.left;
            startTop = rect.top - parentRect.top;
            
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            
            var dx = e.clientX - startX;
            var dy = e.clientY - startY;
            
            var newLeft = startLeft + dx;
            var newTop = startTop + dy;
            
            // Constrain to parent bounds
            var parentRect = panel.offsetParent.getBoundingClientRect();
            var panelRect = panel.getBoundingClientRect();
            
            newLeft = Math.max(0, Math.min(newLeft, parentRect.width - panelRect.width));
            newTop = Math.max(0, Math.min(newTop, parentRect.height - panelRect.height));
            
            panel.style.left = newLeft + 'px';
            panel.style.top = newTop + 'px';
            panel.style.bottom = 'auto';
            panel.style.right = 'auto';
        });

        document.addEventListener('mouseup', function() {
            if (isDragging) {
                isDragging = false;
                panel.classList.remove('dragging');
            }
        });

        // Touch support for mobile
        header.addEventListener('touchstart', function(e) {
            if (e.target.closest('.close')) return;
            
            isDragging = true;
            panel.classList.add('dragging');
            
            var touch = e.touches[0];
            var rect = panel.getBoundingClientRect();
            var parentRect = panel.offsetParent.getBoundingClientRect();
            
            startX = touch.clientX;
            startY = touch.clientY;
            startLeft = rect.left - parentRect.left;
            startTop = rect.top - parentRect.top;
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            if (!isDragging) return;
            
            var touch = e.touches[0];
            var dx = touch.clientX - startX;
            var dy = touch.clientY - startY;
            
            var newLeft = startLeft + dx;
            var newTop = startTop + dy;
            
            var parentRect = panel.offsetParent.getBoundingClientRect();
            var panelRect = panel.getBoundingClientRect();
            
            newLeft = Math.max(0, Math.min(newLeft, parentRect.width - panelRect.width));
            newTop = Math.max(0, Math.min(newTop, parentRect.height - panelRect.height));
            
            panel.style.left = newLeft + 'px';
            panel.style.top = newTop + 'px';
            panel.style.bottom = 'auto';
            panel.style.right = 'auto';
        }, { passive: true });

        document.addEventListener('touchend', function() {
            if (isDragging) {
                isDragging = false;
                panel.classList.remove('dragging');
            }
        });
    })();

    // Public Routes Panel Toggle
    var prPanelBtn = document.getElementById('public_routes_panel_btn');
    var prPanel = document.getElementById('public_routes_panel');
    var prPanelClose = document.getElementById('public_routes_panel_close');

    if (prPanelBtn && prPanel) {
        prPanelBtn.addEventListener('click', function() {
            var isVisible = prPanel.style.display !== 'none';
            prPanel.style.display = isVisible ? 'none' : 'block';
        });
    }

    if (prPanelClose && prPanel) {
        prPanelClose.addEventListener('click', function() {
            prPanel.style.display = 'none';
        });
    }

    // Update public routes badge when toggle changes
    var prToggle = document.getElementById('public_routes_toggle');
    var prBadge = document.getElementById('public_routes_badge');
    if (prToggle && prBadge) {
        prToggle.addEventListener('change', function() {
            if (this.checked) {
                prBadge.classList.remove('badge-secondary');
                prBadge.classList.add('badge-success');
            } else {
                prBadge.classList.remove('badge-success');
                prBadge.classList.add('badge-secondary');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PLAYBOOK/CDR SEARCH PANEL
    // ═══════════════════════════════════════════════════════════════════════
    
    var pbcdrToggle = document.getElementById('pbcdr_search_toggle');
    var pbcdrPanel = document.getElementById('pbcdr_search_panel');
    var pbcdrClose = document.getElementById('pbcdr_panel_close');
    var pbcdrCollapseBtn = document.getElementById('pbcdr_collapse_btn');
    
    if (pbcdrToggle && pbcdrPanel) {
        pbcdrToggle.addEventListener('click', function() {
            pbcdrPanel.classList.toggle('show');
            // Initialize search if PlaybookCDRSearch module is available
            if (pbcdrPanel.classList.contains('show') && typeof PlaybookCDRSearch !== 'undefined') {
                PlaybookCDRSearch.init();
            }
        });
    }
    
    if (pbcdrClose && pbcdrPanel) {
        pbcdrClose.addEventListener('click', function() {
            pbcdrPanel.classList.remove('show');
        });
    }
    
    // Collapse/Expand toggle
    if (pbcdrCollapseBtn && pbcdrPanel) {
        pbcdrCollapseBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            pbcdrPanel.classList.toggle('collapsed');
        });
    }
    
    // Double-click header to expand when collapsed
    if (pbcdrPanel) {
        var pbcdrHeader = pbcdrPanel.querySelector('.pbcdr-panel-header');
        if (pbcdrHeader) {
            pbcdrHeader.addEventListener('dblclick', function(e) {
                if (pbcdrPanel.classList.contains('collapsed')) {
                    pbcdrPanel.classList.remove('collapsed');
                }
            });
        }
    }
    
    // Make PBCDR panel draggable
    (function() {
        var panel = document.getElementById('pbcdr_search_panel');
        var header = panel ? panel.querySelector('.pbcdr-panel-header') : null;
        if (!panel || !header) return;

        var isDragging = false;
        var startX, startY, startLeft, startTop;

        header.addEventListener('mousedown', function(e) {
            // Don't start drag if clicking close button or collapse button
            if (e.target.closest('.close') || e.target.closest('.pbcdr-collapse-btn')) return;
            
            isDragging = true;
            panel.classList.add('dragging');
            
            var rect = panel.getBoundingClientRect();
            var parentRect = panel.offsetParent.getBoundingClientRect();
            
            startX = e.clientX;
            startY = e.clientY;
            startLeft = rect.left - parentRect.left;
            startTop = rect.top - parentRect.top;
            
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            
            var dx = e.clientX - startX;
            var dy = e.clientY - startY;
            
            var newLeft = startLeft + dx;
            var newTop = startTop + dy;
            
            // Constrain to parent bounds
            var parentRect = panel.offsetParent.getBoundingClientRect();
            var panelRect = panel.getBoundingClientRect();
            
            newLeft = Math.max(0, Math.min(newLeft, parentRect.width - panelRect.width));
            newTop = Math.max(0, Math.min(newTop, parentRect.height - panelRect.height));
            
            panel.style.left = newLeft + 'px';
            panel.style.top = newTop + 'px';
            panel.style.right = 'auto';
        });

        document.addEventListener('mouseup', function() {
            if (isDragging) {
                isDragging = false;
                panel.classList.remove('dragging');
            }
        });

        // Touch support for mobile
        header.addEventListener('touchstart', function(e) {
            if (e.target.closest('.close') || e.target.closest('.pbcdr-collapse-btn')) return;
            
            isDragging = true;
            panel.classList.add('dragging');
            
            var touch = e.touches[0];
            var rect = panel.getBoundingClientRect();
            var parentRect = panel.offsetParent.getBoundingClientRect();
            
            startX = touch.clientX;
            startY = touch.clientY;
            startLeft = rect.left - parentRect.left;
            startTop = rect.top - parentRect.top;
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            if (!isDragging) return;
            
            var touch = e.touches[0];
            var dx = touch.clientX - startX;
            var dy = touch.clientY - startY;
            
            var newLeft = startLeft + dx;
            var newTop = startTop + dy;
            
            var parentRect = panel.offsetParent.getBoundingClientRect();
            var panelRect = panel.getBoundingClientRect();
            
            newLeft = Math.max(0, Math.min(newLeft, parentRect.width - panelRect.width));
            newTop = Math.max(0, Math.min(newTop, parentRect.height - panelRect.height));
            
            panel.style.left = newLeft + 'px';
            panel.style.top = newTop + 'px';
            panel.style.right = 'auto';
        }, { passive: true });

        document.addEventListener('touchend', function() {
            if (isDragging) {
                isDragging = false;
                panel.classList.remove('dragging');
            }
        });
    })();
    
    // Tab switching
    document.querySelectorAll('.pbcdr-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.pbcdr-tab').forEach(function(t) {
                t.classList.remove('active');
            });
            this.classList.add('active');
            
            var tabType = this.dataset.tab;
            var nameLabel = document.getElementById('pbcdr_name_label');
            var nameInput = document.getElementById('pbcdr_name');
            
            if (tabType === 'playbook') {
                nameLabel.textContent = 'Play Name';
                nameInput.placeholder = 'e.g., SERMN, ABI, NORTHEAST...';
            } else if (tabType === 'cdr') {
                nameLabel.textContent = 'CDR Code';
                nameInput.placeholder = 'e.g., JFKMIA, LGAATL...';
            } else {
                nameLabel.textContent = 'Name / Code';
                nameInput.placeholder = 'e.g., SERMN, JFKMIA...';
            }
            
            // Trigger search if module available
            if (typeof PlaybookCDRSearch !== 'undefined') {
                PlaybookCDRSearch.setSearchType(tabType);
            }
        });
    });
});
</script>

<!-- TMI Info Bar Clock & Stats -->
<script>
(function() {
    // Initialize UTC clock display
    var utcClockEl = document.getElementById("route_utc_clock");
    if (utcClockEl) {
        var updateUtcClock = function() {
            var now = new Date();
            var dd = String(now.getUTCDate()).padStart(2, "0");
            var hh = String(now.getUTCHours()).padStart(2, "0");
            var mi = String(now.getUTCMinutes()).padStart(2, "0");
            var ss = String(now.getUTCSeconds()).padStart(2, "0");
            utcClockEl.textContent = dd + " / " + hh + ":" + mi + ":" + ss + "Z";
        };
        updateUtcClock();
        setInterval(updateUtcClock, 1000);
    }

    // Initialize US timezone clocks
    var clockGuam = document.getElementById("route_clock_guam");
    var clockHi = document.getElementById("route_clock_hi");
    var clockAk = document.getElementById("route_clock_ak");
    var clockPac = document.getElementById("route_clock_pac");
    var clockMtn = document.getElementById("route_clock_mtn");
    var clockCent = document.getElementById("route_clock_cent");
    var clockEast = document.getElementById("route_clock_east");

    if (clockPac && clockMtn && clockCent && clockEast) {
        var updateLocalClocks = function() {
            var now = new Date();
            
            function formatLocalTime(date, tzName) {
                try {
                    var opts = { 
                        timeZone: tzName, 
                        hour: '2-digit', 
                        minute: '2-digit',
                        hour12: false 
                    };
                    return date.toLocaleTimeString('en-US', opts);
                } catch (e) {
                    return "--:--";
                }
            }
            
            if (clockGuam) clockGuam.textContent = formatLocalTime(now, 'Pacific/Guam');
            if (clockHi) clockHi.textContent = formatLocalTime(now, 'Pacific/Honolulu');
            if (clockAk) clockAk.textContent = formatLocalTime(now, 'America/Anchorage');
            clockPac.textContent = formatLocalTime(now, 'America/Los_Angeles');
            clockMtn.textContent = formatLocalTime(now, 'America/Denver');
            clockCent.textContent = formatLocalTime(now, 'America/Chicago');
            clockEast.textContent = formatLocalTime(now, 'America/New_York');
        };
        updateLocalClocks();
        setInterval(updateLocalClocks, 1000);
    }

    // Initialize flight statistics display
    var statsElements = {
        globalTotal: document.getElementById("route_stats_global_total"),
        dd: document.getElementById("route_stats_dd"),
        di: document.getElementById("route_stats_di"),
        id: document.getElementById("route_stats_id"),
        ii: document.getElementById("route_stats_ii"),
        domesticTotal: document.getElementById("route_stats_domestic_total"),
        dccNe: document.getElementById("route_stats_dcc_ne"),
        dccSe: document.getElementById("route_stats_dcc_se"),
        dccMw: document.getElementById("route_stats_dcc_mw"),
        dccSc: document.getElementById("route_stats_dcc_sc"),
        dccW: document.getElementById("route_stats_dcc_w"),
        aspm77: document.getElementById("route_stats_aspm77"),
        oep35: document.getElementById("route_stats_oep35"),
        core30: document.getElementById("route_stats_core30")
    };

    var hasStatsElements = Object.values(statsElements).some(function(el) { return el !== null; });

    if (hasStatsElements) {
        var updateFlightStats = function() {
            fetch("api/adl/stats.php", { cache: "no-cache" })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (!data) return;

                    // Update global counts
                    if (data.global) {
                        if (statsElements.globalTotal) {
                            statsElements.globalTotal.textContent = data.global.total || 0;
                        }
                        if (statsElements.dd) {
                            statsElements.dd.textContent = data.global.domestic_to_domestic || 0;
                        }
                        if (statsElements.di) {
                            statsElements.di.textContent = data.global.domestic_to_intl || 0;
                        }
                        if (statsElements.id) {
                            statsElements.id.textContent = data.global.intl_to_domestic || 0;
                        }
                        if (statsElements.ii) {
                            statsElements.ii.textContent = data.global.intl_to_intl || 0;
                        }
                    }

                    // Update domestic counts
                    if (data.domestic) {
                        var domesticArrTotal = 0;
                        if (data.domestic.arr_dcc) {
                            var dcc = data.domestic.arr_dcc;
                            domesticArrTotal = (dcc.NE || 0) + (dcc.SE || 0) + (dcc.MW || 0) + 
                                               (dcc.SC || 0) + (dcc.W || 0) + (dcc.Other || 0);
                            
                            if (statsElements.dccNe) statsElements.dccNe.textContent = dcc.NE || 0;
                            if (statsElements.dccSe) statsElements.dccSe.textContent = dcc.SE || 0;
                            if (statsElements.dccMw) statsElements.dccMw.textContent = dcc.MW || 0;
                            if (statsElements.dccSc) statsElements.dccSc.textContent = dcc.SC || 0;
                            if (statsElements.dccW) statsElements.dccW.textContent = dcc.W || 0;
                        }

                        if (statsElements.domesticTotal) {
                            statsElements.domesticTotal.textContent = domesticArrTotal;
                        }

                        if (data.domestic.arr_aspm77 && statsElements.aspm77) {
                            statsElements.aspm77.textContent = data.domestic.arr_aspm77.yes || 0;
                        }
                        if (data.domestic.arr_oep35 && statsElements.oep35) {
                            statsElements.oep35.textContent = data.domestic.arr_oep35.yes || 0;
                        }
                        if (data.domestic.arr_core30 && statsElements.core30) {
                            statsElements.core30.textContent = data.domestic.arr_core30.yes || 0;
                        }
                    }
                })
                .catch(function(err) {
                    console.error("Error fetching flight stats:", err);
                });
        };

        // Initial load and refresh every 15 seconds
        updateFlightStats();
        setInterval(updateFlightStats, 15000);
    }
})();
</script>

<!-- Map Library Toggle (Leaflet/MapLibre) -->
<div id="map_library_toggle" style="
    position: fixed;
    bottom: 10px;
    left: 10px;
    z-index: 9999;
    background: rgba(0,0,0,0.7);
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 11px;
    color: #fff;
    font-family: monospace;
">
    <span id="map_library_indicator" style="margin-right: 8px;"></span>
    <button id="map_library_switch_btn" style="
        background: #239BCD;
        border: none;
        color: white;
        padding: 2px 8px;
        border-radius: 3px;
        cursor: pointer;
        font-size: 10px;
    ">Switch</button>
</div>
<script>
(function() {
    var isMapLibre = window.PERTI_USE_MAPLIBRE;
    var indicator = document.getElementById('map_library_indicator');
    var switchBtn = document.getElementById('map_library_switch_btn');
    
    if (indicator) {
        indicator.innerHTML = isMapLibre 
            ? '<span style="color:#2ecc71;">●</span> MapLibre GL (WebGL)' 
            : '<span style="color:#f39c12;">●</span> Leaflet (Canvas)';
    }
    
    if (switchBtn) {
        switchBtn.addEventListener('click', function() {
            var current = localStorage.getItem('useMapLibre') === 'true';
            localStorage.setItem('useMapLibre', current ? 'false' : 'true');
            
            // Show confirmation and reload
            if (confirm('Switch to ' + (current ? 'Leaflet' : 'MapLibre GL JS') + '?\n\nThe page will reload.')) {
                window.location.reload();
            } else {
                // Revert if cancelled
                localStorage.setItem('useMapLibre', current ? 'true' : 'false');
            }
        });
    }
})();
</script>

</body>
</html>
