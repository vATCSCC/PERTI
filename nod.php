<?php
/**
 * NAS Operations Dashboard (NOD)
 * 
 * Consolidated view of:
 * - Live traffic map with layer controls
 * - Public reroutes overlay
 * - Active splits visualization
 * - JATOC incidents
 * - Today's advisories
 * - Active TMIs (GS/GDP/Reroutes)
 */

// Session handling
include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) { 
    session_start(); 
    ob_start(); 
}
include("load/config.php");
include("load/connect.php");

// Public page - no auth required (like jatoc.php)
$user_name = trim(($_SESSION['VATSIM_FIRST_NAME'] ?? '') . ' ' . ($_SESSION['VATSIM_LAST_NAME'] ?? '')) ?: 'Unknown';
$user_cid = $_SESSION['VATSIM_CID'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Import CSS from shared header -->
    <?php $page_title = "vATCSCC NOD"; include("load/header.php"); ?>
    
    <!-- MapLibre GL CSS (additional for NOD) -->
    <link href="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.css" rel="stylesheet">
    
    <style>
        /* =========================================
         * NOD Page Layout
         * ========================================= */
        
        /* Make navbar solid instead of floating/transparent for NOD */
        .cs-header.navbar-floating {
            background: rgba(26, 26, 46, 0.98) !important;
            border-bottom: 1px solid #333;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
        }
        
        .cs-header.navbar-floating .navbar-brand img {
            filter: brightness(1);
        }
        
        /* Ensure navbar links are visible */
        .cs-header.navbar-floating .nav-link {
            color: rgba(255,255,255,0.8) !important;
        }
        
        .cs-header.navbar-floating .nav-link:hover {
            color: #fff !important;
        }
        
        body {
            overflow: hidden;
        }
        
        /* Hide footer on NOD - full viewport layout */
        .cs-footer {
            display: none !important;
        }
        
        .nod-container {
            display: flex;
            height: calc(100vh - 60px); /* Account for navbar */
            width: 100%;
            position: relative;
            margin-top: 60px; /* Push below fixed navbar */
        }
        
        /* Map Container */
        .nod-map-container {
            flex: 1;
            position: relative;
            min-width: 0;
        }
        
        #nod-map {
            width: 100%;
            height: 100%;
        }
        
        /* Right Panel */
        .nod-panel {
            width: 420px;
            min-width: 420px;
            max-width: 420px;
            height: 100%;
            background: #1a1a2e;
            border-left: 1px solid #333;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: margin-right 0.3s ease;
        }
        
        .nod-panel.collapsed {
            margin-right: -420px;
        }
        
        .nod-panel-toggle {
            position: absolute;
            right: 420px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 1000;
            background: #1a1a2e;
            border: 1px solid #333;
            border-right: none;
            border-radius: 4px 0 0 4px;
            padding: 10px 6px;
            cursor: pointer;
            color: #ccc;
            transition: right 0.3s ease;
        }
        
        .nod-panel.collapsed + .nod-panel-toggle,
        .nod-panel-toggle.panel-collapsed {
            right: 0;
        }
        
        .nod-panel-toggle:hover {
            background: #252540;
            color: #fff;
        }
        
        /* Panel Header */
        .nod-panel-header {
            background: linear-gradient(135deg, #16213e 0%, #1a1a2e 100%);
            padding: 12px 15px;
            border-bottom: 1px solid #333;
            flex-shrink: 0;
        }
        
        .nod-panel-header h5 {
            margin: 0;
            color: #fff;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .nod-panel-header .nod-status {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 8px;
        }
        
        .nod-status-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #aaa;
        }
        
        .nod-status-badge {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .nod-status-badge.ops-1 { background: #28a745; color: #fff; }
        .nod-status-badge.ops-2 { background: #ffc107; color: #000; }
        .nod-status-badge.ops-3 { background: #dc3545; color: #fff; }
        
        /* TMU OpLevel colors (matching index.php PERTI Plan display) */
        .nod-status-badge.tmu-1 { background: #6c757d; color: #fff; } /* Steady State - neutral gray */
        .nod-status-badge.tmu-2 { background: #28a745; color: #fff; } /* Localized - green */
        .nod-status-badge.tmu-3 { background: #ffc107; color: #000; } /* Regional - yellow */
        .nod-status-badge.tmu-4 { background: #dc3545; color: #fff; } /* NAS-Wide - red */
        .nod-status-badge.tmu-none { background: #495057; color: #aaa; } /* No active event */
        
        /* Panel Tabs */
        .nod-panel-tabs {
            display: flex;
            background: #16213e;
            border-bottom: 1px solid #333;
            flex-shrink: 0;
        }
        
        .nod-panel-tab {
            flex: 1;
            padding: 10px 8px;
            text-align: center;
            font-size: 11px;
            text-transform: uppercase;
            color: #888;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        
        .nod-panel-tab:hover {
            color: #ccc;
            background: rgba(255,255,255,0.05);
        }
        
        .nod-panel-tab.active {
            color: #fff;
            border-bottom-color: #4a9eff;
            background: rgba(74, 158, 255, 0.1);
        }
        
        .nod-panel-tab i {
            display: block;
            font-size: 16px;
            margin-bottom: 4px;
        }
        
        /* Panel Content */
        .nod-panel-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        .nod-tab-pane {
            display: none;
            padding: 0;
        }
        
        .nod-tab-pane.active {
            display: block;
        }
        
        /* Section Headers in Panels */
        .nod-section {
            border-bottom: 1px solid #333;
        }
        
        .nod-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 15px;
            background: #16213e;
            cursor: pointer;
            user-select: none;
        }
        
        .nod-section-header:hover {
            background: #1c2541;
        }
        
        .nod-section-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #aaa;
            margin: 0;
        }
        
        .nod-section-badge {
            background: #333;
            color: #fff;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .nod-section-badge.active {
            background: #dc3545;
        }
        
        .nod-section-body {
            padding: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .nod-section.expanded .nod-section-body {
            max-height: 2000px;
        }
        
        .nod-section-body-inner {
            padding: 10px 15px;
        }
        
        /* TMI Cards */
        .nod-tmi-card {
            background: #252540;
            border-radius: 4px;
            padding: 10px 12px;
            margin-bottom: 8px;
            border-left: 3px solid #666;
        }
        
        .nod-tmi-card.gs { border-left-color: #dc3545; }
        .nod-tmi-card.gdp { border-left-color: #ffc107; }
        .nod-tmi-card.reroute { border-left-color: #17a2b8; }
        .nod-tmi-card.public-route { border-left-color: #28a745; }
        
        .nod-tmi-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 6px;
        }
        
        .nod-tmi-type {
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 2px;
            background: #333;
            color: #fff;
        }
        
        .nod-tmi-type.gs { background: #dc3545; }
        .nod-tmi-type.gdp { background: #ffc107; color: #000; }
        .nod-tmi-type.reroute { background: #17a2b8; }
        
        .nod-tmi-airport {
            font-size: 16px;
            font-weight: 700;
            color: #fff;
            font-family: 'Consolas', 'Monaco', monospace;
        }
        
        .nod-tmi-info {
            font-size: 12px;
            color: #aaa;
            line-height: 1.4;
        }
        
        .nod-tmi-time {
            font-size: 11px;
            color: #888;
            margin-top: 6px;
            font-family: 'Consolas', 'Monaco', monospace;
        }
        
        /* Advisory Cards */
        .nod-advisory-card {
            background: #252540;
            border-radius: 4px;
            padding: 10px 12px;
            margin-bottom: 8px;
            border-left: 3px solid #4a9eff;
        }
        
        .nod-advisory-card.high { border-left-color: #dc3545; }
        .nod-advisory-card.normal { border-left-color: #4a9eff; }
        .nod-advisory-card.low { border-left-color: #6c757d; }
        
        .nod-advisory-number {
            font-size: 11px;
            font-weight: 600;
            color: #4a9eff;
            font-family: 'Consolas', 'Monaco', monospace;
        }
        
        .nod-advisory-subject {
            font-size: 13px;
            color: #fff;
            margin: 4px 0;
            font-weight: 500;
        }
        
        .nod-advisory-meta {
            font-size: 11px;
            color: #888;
        }
        
        /* JATOC Incident Cards */
        .nod-incident-card {
            background: #252540;
            border-radius: 4px;
            padding: 10px 12px;
            margin-bottom: 8px;
            border-left: 3px solid #ffc107;
        }
        
        .nod-incident-card.atc-zero { border-left-color: #dc3545; }
        .nod-incident-card.atc-alert { border-left-color: #ffc107; }
        .nod-incident-card.atc-limited { border-left-color: #fd7e14; }
        .nod-incident-card.non-responsive { border-left-color: #6c757d; }
        .nod-incident-card.staffing { border-left-color: #17a2b8; }
        .nod-incident-card.equipment { border-left-color: #20c997; }
        .nod-incident-card.weather { border-left-color: #6f42c1; }
        .nod-incident-card.other { border-left-color: #495057; }
        
        .nod-incident-facility {
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            font-family: 'Consolas', 'Monaco', monospace;
        }
        
        .nod-incident-type {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 2px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .nod-incident-type.atc-zero { background: #dc3545; color: #fff; }
        .nod-incident-type.atc-alert { background: #ffc107; color: #000; }
        .nod-incident-type.atc-limited { background: #fd7e14; color: #fff; }
        .nod-incident-type.non-responsive { background: #6c757d; color: #fff; }
        .nod-incident-type.staffing { background: #17a2b8; color: #fff; }
        .nod-incident-type.equipment { background: #20c997; color: #fff; }
        .nod-incident-type.weather { background: #6f42c1; color: #fff; }
        .nod-incident-type.other { background: #495057; color: #fff; }
        
        /* Map Controls Overlay */
        .nod-map-controls {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 100;
            background: rgba(26, 26, 46, 0.95);
            border-radius: 4px;
            border: 1px solid #333;
            min-width: 200px;
            max-width: 280px;
        }
        
        .nod-map-controls-header {
            padding: 8px 12px;
            background: #16213e;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: move;
            border-radius: 4px 4px 0 0;
            user-select: none;
        }
        
        .nod-map-controls-header:active {
            cursor: grabbing;
        }
        
        .nod-map-controls-header .drag-handle {
            color: #555;
            margin-right: 6px;
            font-size: 10px;
        }
        
        .nod-map-controls-header h6 {
            margin: 0;
            font-size: 11px;
            text-transform: uppercase;
            color: #aaa;
        }
        
        .nod-map-controls-header i.fa-chevron-down,
        .nod-map-controls-header i.fa-chevron-up,
        .nod-map-controls-header i.fa-chevron-right {
            color: #fff;
            cursor: pointer;
        }
        
        .nod-map-controls-body {
            padding: 8px;
            max-height: 400px; /* Fixed max height to prevent overlap with clock */
            overflow-y: auto;
        }
        
        .nod-map-controls.collapsed .nod-map-controls-body {
            display: none;
        }
        
        .nod-layer-item {
            display: flex;
            align-items: center;
            padding: 6px 8px;
            border-radius: 3px;
            margin-bottom: 2px;
        }
        
        .nod-layer-item:hover {
            background: rgba(255,255,255,0.05);
        }
        
        .nod-layer-item input[type="checkbox"] {
            margin-right: 8px;
        }
        
        .nod-layer-item label {
            margin: 0;
            font-size: 12px;
            color: #d6d7f1;
            cursor: pointer;
            flex: 1;
        }
        
        .nod-layer-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
            margin-right: 8px;
        }
        
        /* Traffic Controls */
        .nod-traffic-controls {
            position: absolute;
            top: 10px;
            right: 440px;
            z-index: 100;
            background: rgba(26, 26, 46, 0.95);
            border-radius: 4px;
            border: 1px solid #333;
            width: 320px;
            transition: right 0.3s ease;
        }
        
        .nod-traffic-controls .nod-map-controls-body {
            max-height: none;
            overflow: visible;
            padding-bottom: 8px;
        }
        
        .nod-traffic-controls .custom-control-label {
            color: #fff;
            font-size: 12px;
        }
        
        .nod-traffic-controls .custom-control-label::before,
        .nod-traffic-controls .custom-control-label::after {
            top: 0.15rem;
        }
        
        .nod-traffic-controls .form-control-sm {
            font-size: 12px;
            padding: 0.2rem 0.4rem;
        }
        
        .nod-traffic-controls select.form-control-sm {
            height: calc(1.5em + 0.5rem + 2px);
        }
        
        .nod-traffic-controls.dragging {
            transition: none;
        }
        
        .nod-panel.collapsed ~ .nod-traffic-controls:not(.user-positioned) {
            right: 10px;
        }
        
        /* Clock Display */
        .nod-clock {
            position: absolute;
            bottom: 10px;
            left: 10px;
            z-index: 100;
            background: rgba(26, 26, 46, 0.95);
            border: 1px solid #333;
            border-radius: 4px;
            padding: 8px 15px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 18px;
            color: #4a9eff;
        }
        
        .nod-clock small {
            font-size: 11px;
            color: #888;
            margin-left: 8px;
        }
        
        /* Map Color Legend - Draggable */
        .nod-map-legend {
            position: absolute;
            bottom: 50px;
            left: 10px;
            z-index: 100;
            background: rgba(26, 26, 46, 0.95);
            border: 1px solid #333;
            border-radius: 4px;
            min-width: 180px;
            max-width: 320px;
            max-height: 400px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
        }
        
        .nod-map-legend.dragging {
            opacity: 0.9;
            cursor: grabbing;
        }
        
        .nod-map-legend-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 10px;
            border-bottom: 1px solid #333;
            background: rgba(0,0,0,0.3);
            cursor: grab;
            user-select: none;
            border-radius: 4px 4px 0 0;
        }
        
        .nod-map-legend-header:active {
            cursor: grabbing;
        }
        
        .nod-map-legend-title {
            font-size: 10px;
            font-weight: 600;
            color: #888;
            letter-spacing: 0.5px;
        }
        
        .nod-map-legend-mode {
            font-size: 9px;
            color: #4a9eff;
            margin-left: 8px;
            text-transform: capitalize;
        }
        
        .nod-map-legend-toggle {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 2px 5px;
            font-size: 10px;
        }
        
        .nod-map-legend-toggle:hover {
            color: #fff;
        }
        
        .nod-map-legend-content {
            padding: 8px 10px;
            overflow-y: auto;
            flex: 1;
        }
        
        .nod-map-legend-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));
            gap: 4px 8px;
        }
        
        .nod-map-legend-item {
            display: flex;
            align-items: center;
            padding: 2px 0;
        }
        
        .nod-map-legend-color {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 2px;
            border: 1px solid #444;
            margin-right: 5px;
            flex-shrink: 0;
        }
        
        .nod-map-legend-label {
            font-size: 10px;
            color: #ccc;
            white-space: normal;
            word-wrap: break-word;
            line-height: 1.2;
        }
        
        /* Draggable Route Labels */
        .nod-route-label {
            position: absolute;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            font-family: 'Consolas', 'Monaco', monospace;
            white-space: nowrap;
            cursor: grab;
            user-select: none;
            z-index: 10;
            box-shadow: 0 1px 3px rgba(0,0,0,0.4);
            transform: translate(-50%, -50%);
            transition: box-shadow 0.15s ease;
        }
        
        .nod-route-label:hover {
            box-shadow: 0 2px 6px rgba(0,0,0,0.5);
        }
        
        .nod-route-label.dragging {
            cursor: grabbing;
            box-shadow: 0 4px 12px rgba(0,0,0,0.6);
            z-index: 100;
        }
        
        .nod-map-legend-show {
            position: absolute;
            bottom: 10px;
            right: 430px; /* Account for panel width */
            z-index: 100;
            background: rgba(26, 26, 46, 0.95);
            border: 1px solid #333;
            border-radius: 4px;
            padding: 8px 12px;
            color: #4a9eff;
            cursor: pointer;
            transition: right 0.3s ease;
        }
        
        .nod-map-legend-show:hover {
            background: rgba(40, 40, 70, 0.95);
        }
        
        /* Move legend button when panel is collapsed */
        .nod-panel.collapsed ~ .nod-map-legend-show {
            right: 10px;
        }
        
        /* Feature Picker Popup */
        .nod-feature-picker {
            background: rgba(26, 26, 46, 0.98);
            border: 1px solid #444;
            border-radius: 4px;
            padding: 0;
            min-width: 200px;
            max-width: 280px;
            font-family: 'Consolas', monospace;
            box-shadow: 0 4px 16px rgba(0,0,0,0.5);
        }
        
        .nod-feature-picker-header {
            padding: 8px 10px;
            border-bottom: 1px solid #333;
            background: rgba(0,0,0,0.3);
            font-size: 10px;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .nod-feature-picker-item {
            padding: 8px 10px;
            cursor: pointer;
            border-bottom: 1px solid #333;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.15s;
        }
        
        .nod-feature-picker-item:last-child {
            border-bottom: none;
        }
        
        .nod-feature-picker-item:hover {
            background: rgba(74, 158, 255, 0.15);
        }
        
        .nod-feature-picker-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            border-radius: 3px;
        }
        
        .nod-feature-picker-icon.flight {
            background: #17a2b8;
            color: #fff;
        }
        
        .nod-feature-picker-icon.route {
            background: #28a745;
            color: #fff;
        }
        
        .nod-feature-picker-icon.incident {
            background: #ffc107;
            color: #000;
        }
        
        .nod-feature-picker-icon.split {
            background: #6f42c1;
            color: #fff;
        }
        
        .nod-feature-picker-label {
            flex: 1;
            font-size: 11px;
            color: #ddd;
        }
        
        .nod-feature-picker-sublabel {
            font-size: 9px;
            color: #888;
        }
        
        /* Opacity Sliders */
        .nod-opacity-slider {
            width: 50px;
            height: 4px;
            margin-left: auto;
            cursor: pointer;
            -webkit-appearance: none;
            appearance: none;
            background: #333;
            border-radius: 2px;
        }
        
        .nod-opacity-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #4a9eff;
            cursor: pointer;
        }
        
        .nod-opacity-slider::-moz-range-thumb {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #4a9eff;
            cursor: pointer;
            border: none;
        }
        
        .nod-layer-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        /* Stats Bar */
        /* Empty state */
        .nod-empty {
            text-align: center;
            padding: 30px 20px;
            color: #666;
        }
        
        .nod-empty i {
            font-size: 32px;
            margin-bottom: 10px;
            opacity: 0.5;
        }
        
        /* Loading state */
        .nod-loading {
            text-align: center;
            padding: 20px;
            color: #888;
        }
        
        .nod-loading i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            100% { transform: rotate(360deg); }
        }
        
        /* Scrollbar styling */
        .nod-panel-content::-webkit-scrollbar,
        .nod-map-controls-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .nod-panel-content::-webkit-scrollbar-track,
        .nod-map-controls-body::-webkit-scrollbar-track {
            background: #1a1a2e;
        }
        
        .nod-panel-content::-webkit-scrollbar-thumb,
        .nod-map-controls-body::-webkit-scrollbar-thumb {
            background: #444;
            border-radius: 3px;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .nod-panel {
                width: 360px;
                min-width: 360px;
                max-width: 360px;
            }
            
            .nod-panel.collapsed {
                margin-right: -360px;
            }
            
            .nod-panel-toggle {
                right: 360px;
            }
            
            .nod-traffic-controls {
                right: 380px;
            }
        }
    </style>
</head>
<body>

<?php include('load/nav.php'); ?>

<!-- Main Container -->
<div class="nod-container">
    
    <!-- Map Container -->
    <div class="nod-map-container">
        <div id="nod-map"></div>
        
        <!-- Map Layer Controls -->
        <div class="nod-map-controls" id="mapLayerControls">
            <div class="nod-map-controls-header" data-draggable="mapLayerControls">
                <h6><i class="fas fa-grip-vertical drag-handle mr-2"></i><i class="fas fa-layer-group mr-2"></i>MAP LAYERS</h6>
                <i class="fas fa-chevron-down" id="layerControlsChevron" onclick="event.stopPropagation(); NOD.toggleLayerControls()"></i>
            </div>
            <div class="nod-map-controls-body">
                <!-- Boundaries -->
                <div class="text-muted small mb-2 mt-1" style="font-size: 10px; text-transform: uppercase;">Boundaries</div>
                <div class="nod-layer-item">
                    <input type="checkbox" id="layer-artcc" checked onchange="NOD.toggleLayer('artcc', this.checked)">
                    <span class="nod-layer-color" style="background: #4a9eff;"></span>
                    <label for="layer-artcc">ARTCC</label>
                </div>
                <div class="nod-layer-item">
                    <input type="checkbox" id="layer-tracon" onchange="NOD.toggleLayer('tracon', this.checked)">
                    <span class="nod-layer-color" style="background: #28a745;"></span>
                    <label for="layer-tracon">TRACON</label>
                </div>
                <div class="nod-layer-item">
                    <input type="checkbox" id="layer-high" onchange="NOD.toggleLayer('high', this.checked)">
                    <span class="nod-layer-color" style="background: #6f42c1;"></span>
                    <label for="layer-high">High Sectors</label>
                </div>
                <div class="nod-layer-item">
                    <input type="checkbox" id="layer-low" onchange="NOD.toggleLayer('low', this.checked)">
                    <span class="nod-layer-color" style="background: #20c997;"></span>
                    <label for="layer-low">Low Sectors</label>
                </div>
                <div class="nod-layer-item">
                    <input type="checkbox" id="layer-superhigh" onchange="NOD.toggleLayer('superhigh', this.checked)">
                    <span class="nod-layer-color" style="background: #e83e8c;"></span>
                    <label for="layer-superhigh">Superhigh Sectors</label>
                </div>
                
                <!-- Overlays -->
                <div class="text-muted small mb-2 mt-3" style="font-size: 10px; text-transform: uppercase;">Overlays</div>
                <div class="nod-layer-item">
                    <input type="checkbox" id="layer-traffic" checked onchange="NOD.toggleLayer('traffic', this.checked)">
                    <span class="nod-layer-color" style="background: #fff;"></span>
                    <label for="layer-traffic">Live Traffic</label>
                </div>
                <div class="nod-layer-item">
                    <input type="checkbox" id="layer-public-routes" checked onchange="NOD.toggleLayer('public-routes', this.checked)">
                    <span class="nod-layer-color" style="background: #17a2b8;"></span>
                    <label for="layer-public-routes">Public Reroutes</label>
                    <input type="range" class="nod-opacity-slider" id="opacity-public-routes" min="0" max="100" value="90" 
                           title="Opacity" onchange="NOD.setLayerOpacity('public-routes', this.value/100)">
                </div>
                <div class="nod-layer-item ml-3">
                    <input type="checkbox" id="layer-route-labels" checked onchange="NOD.toggleRouteLabels(this.checked)">
                    <span class="nod-layer-color" style="background: transparent; border: 1px solid #666;"><i class="fas fa-tag" style="font-size: 8px; color: #888;"></i></span>
                    <label for="layer-route-labels">Route Labels</label>
                </div>
                <div class="nod-layer-item">
                    <input type="checkbox" id="layer-splits" onchange="NOD.toggleLayer('splits', this.checked)">
                    <span class="nod-layer-color" style="background: #fd7e14;"></span>
                    <label for="layer-splits">Active Splits</label>
                    <input type="range" class="nod-opacity-slider" id="opacity-splits" min="0" max="100" value="70" 
                           title="Opacity" onchange="NOD.setLayerOpacity('splits', this.value/100)">
                </div>
                <!-- Splits Strata Filters -->
                <div class="nod-layer-item ml-3" id="splits-strata-filters" style="display: none;">
                    <input type="checkbox" id="layer-splits-low" checked onchange="NOD.toggleSplitsStrata('low', this.checked)">
                    <span class="nod-layer-color" style="background: #20c997;"></span>
                    <label for="layer-splits-low">Low</label>
                    <input type="checkbox" id="layer-splits-high" checked onchange="NOD.toggleSplitsStrata('high', this.checked)" class="ml-2">
                    <span class="nod-layer-color" style="background: #6f42c1;"></span>
                    <label for="layer-splits-high">High</label>
                    <input type="checkbox" id="layer-splits-superhigh" checked onchange="NOD.toggleSplitsStrata('superhigh', this.checked)" class="ml-2">
                    <span class="nod-layer-color" style="background: #e83e8c;"></span>
                    <label for="layer-splits-superhigh">Super</label>
                </div>
                <div class="nod-layer-item">
                    <input type="checkbox" id="layer-incidents" checked onchange="NOD.toggleLayer('incidents', this.checked)">
                    <span class="nod-layer-color" style="background: #dc3545;"></span>
                    <label for="layer-incidents">JATOC Incidents</label>
                    <input type="range" class="nod-opacity-slider" id="opacity-incidents" min="0" max="100" value="80" 
                           title="Opacity" onchange="NOD.setLayerOpacity('incidents', this.value/100)">
                </div>
                
                <!-- Weather -->
                <div class="text-muted small mb-2 mt-3" style="font-size: 10px; text-transform: uppercase;">Weather</div>
                <div class="nod-layer-item">
                    <input type="checkbox" id="layer-radar" onchange="NOD.toggleLayer('radar', this.checked)">
                    <span class="nod-layer-color" style="background: linear-gradient(90deg, #00ff00, #ffff00, #ff0000);"></span>
                    <label for="layer-radar">NEXRAD Radar</label>
                </div>
            </div>
        </div>
        
        <!-- Traffic Filter Controls (matches route.php Flight Filters) -->
        <div class="nod-traffic-controls" id="trafficControls">
            <div class="nod-map-controls-header" data-draggable="trafficControls">
                <h6><i class="fas fa-grip-vertical drag-handle mr-2"></i><i class="fas fa-filter mr-2"></i>FLIGHT FILTERS</h6>
                <i class="fas fa-chevron-down" id="trafficControlsChevron" onclick="event.stopPropagation(); NOD.toggleTrafficControls()"></i>
            </div>
            <div class="nod-map-controls-body">
                <!-- Display Options -->
                <div class="d-flex mb-2 px-2">
                    <div class="custom-control custom-checkbox mr-3">
                        <input type="checkbox" class="custom-control-input" id="traffic-labels" checked onchange="NOD.toggleTrafficLabels(this.checked)">
                        <label class="custom-control-label small" for="traffic-labels">Labels</label>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="traffic-tracks" onchange="NOD.toggleTrafficTracks(this.checked)">
                        <label class="custom-control-label small" for="traffic-tracks">Tracks</label>
                    </div>
                </div>
                
                <!-- Color By -->
                <div class="form-group mb-2 px-2">
                    <label class="small text-muted mb-1" style="font-size: 10px; text-transform: uppercase;">COLOR BY</label>
                    <select id="nod_color_by" class="form-control form-control-sm bg-dark text-light border-secondary" onchange="NOD.setColorMode(this.value)">
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
                            <option value="eta_relative">ETA (Relative)</option>
                            <option value="eta_hour">ETA (Hour)</option>
                            <option value="status">Flight Status</option>
                        </optgroup>
                        <optgroup label="Route Matching">
                            <option value="reroute_match">Public Reroute Match</option>
                        </optgroup>
                    </select>
                </div>
                
                <!-- Color Legend -->
                <div class="mb-2 px-2">
                    <div id="nod_color_legend" class="d-flex flex-wrap small" style="gap: 4px;"></div>
                </div>
                
                <!-- Weight Class Filter -->
                <div class="form-group mb-2 px-2">
                    <label class="small text-muted mb-1" style="font-size: 10px; text-transform: uppercase;">AIRCRAFT TYPE</label>
                    <div class="d-flex flex-wrap">
                        <div class="custom-control custom-checkbox mr-2">
                            <input type="checkbox" class="custom-control-input nod-weight-filter" id="nod_wc_super" value="SUPER" checked onchange="NOD.applyFilters()">
                            <label class="custom-control-label small" for="nod_wc_super">✈ Super</label>
                        </div>
                        <div class="custom-control custom-checkbox mr-2">
                            <input type="checkbox" class="custom-control-input nod-weight-filter" id="nod_wc_heavy" value="HEAVY" checked onchange="NOD.applyFilters()">
                            <label class="custom-control-label small" for="nod_wc_heavy">✈ Heavy</label>
                        </div>
                        <div class="custom-control custom-checkbox mr-2">
                            <input type="checkbox" class="custom-control-input nod-weight-filter" id="nod_wc_large" value="LARGE" checked onchange="NOD.applyFilters()">
                            <label class="custom-control-label small" for="nod_wc_large">✈ Jet</label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input nod-weight-filter" id="nod_wc_small" value="SMALL" checked onchange="NOD.applyFilters()">
                            <label class="custom-control-label small" for="nod_wc_small">○ Prop</label>
                        </div>
                    </div>
                </div>
                
                <!-- Origin / Destination -->
                <div class="form-group mb-2 px-2">
                    <label class="small text-muted mb-1" style="font-size: 10px; text-transform: uppercase;">ORIGIN / DESTINATION</label>
                    <div class="d-flex">
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary mr-1" 
                               id="nod_filter_origin" placeholder="Origin" title="ARTCC (ZNY) or Airport (KJFK)" 
                               style="flex: 1;" onchange="NOD.applyFilters()">
                        <span class="text-muted align-self-center mx-1">→</span>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" 
                               id="nod_filter_dest" placeholder="Dest" title="ARTCC (ZMA) or Airport (KMIA)" 
                               style="flex: 1;" onchange="NOD.applyFilters()">
                    </div>
                </div>
                
                <!-- Carrier / Altitude -->
                <div class="form-group mb-2 px-2">
                    <label class="small text-muted mb-1" style="font-size: 10px; text-transform: uppercase;">CARRIER / ALTITUDE</label>
                    <div class="d-flex align-items-center">
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary mr-2" 
                               id="nod_filter_carrier" placeholder="Carrier" title="e.g., AAL, UAL" style="width: 70px;" 
                               onchange="NOD.applyFilters()">
                        <span class="text-muted small mr-1">FL</span>
                        <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary mr-1" 
                               id="nod_filter_alt_min" placeholder="Min" style="width: 50px;" 
                               onchange="NOD.applyFilters()">
                        <span class="text-muted">-</span>
                        <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary ml-1" 
                               id="nod_filter_alt_max" placeholder="Max" style="width: 50px;" 
                               onchange="NOD.applyFilters()">
                    </div>
                </div>
                
                <!-- Stats and Actions -->
                <div class="d-flex justify-content-between align-items-center pt-2 px-2 border-top border-secondary">
                    <small class="text-muted">
                        <span id="nod_stats_display"><strong>0</strong> shown</span> / 
                        <span id="nod_stats_total"><strong>0</strong> total</span>
                    </small>
                    <div>
                        <button class="btn btn-sm btn-outline-info mr-1" onclick="NOD.drawAllFilteredRoutes()" title="Draw routes for all filtered flights">
                            <i class="fas fa-route"></i><i class="fas fa-plus" style="font-size:8px;margin-left:2px;"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-warning mr-1" onclick="NOD.clearFlightRoutes()" title="Clear all drawn flight routes">
                            <i class="fas fa-route"></i><i class="fas fa-times" style="font-size:8px;margin-left:2px;"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-secondary mr-1" onclick="NOD.resetFilters()" title="Clear all filters">
                            <i class="fas fa-eraser"></i>
                        </button>
                        <button class="btn btn-sm btn-info" onclick="NOD.applyFilters()" title="Apply filters">
                            <i class="fas fa-check"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- UTC Clock -->
        <div class="nod-clock" id="nodClock">
            <span id="utcTime">--:--:--</span>
            <small>UTC</small>
        </div>
        
        <!-- Map Color Legend (hideable, draggable) -->
        <div class="nod-map-legend" id="mapColorLegend">
            <div class="nod-map-legend-header" id="mapLegendDragHandle">
                <div>
                    <span class="nod-map-legend-title">COLOR LEGEND</span>
                    <span class="nod-map-legend-mode" id="mapLegendModeLabel">weight class</span>
                </div>
                <button class="nod-map-legend-toggle" onclick="NOD.toggleMapLegend()" title="Hide legend">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="nod-map-legend-content" id="mapLegendContent">
                <div class="nod-map-legend-grid" id="mapLegendGrid">
                    <!-- Populated dynamically -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Right Panel -->
    <div class="nod-panel" id="nodPanel">
        <!-- Panel Header -->
        <div class="nod-panel-header">
            <h5><i class="fas fa-tachometer-alt mr-2"></i>NAS OPERATIONS DASHBOARD</h5>
            <div class="nod-status">
                <div class="nod-status-item">
                    <span>TMU OpLevel:</span>
                    <span class="nod-status-badge tmu-none" id="tmuOpsLevelBadge" title="No active event">—</span>
                </div>
                <div class="nod-status-item">
                    <span>JATOC OpLevel:</span>
                    <span class="nod-status-badge ops-1" id="opsLevelBadge">1</span>
                </div>
                <div class="nod-status-item">
                    <span>UPDATED:</span>
                    <span id="lastUpdateTime">--:--</span>
                </div>
            </div>
        </div>
        
        <!-- Panel Tabs -->
        <div class="nod-panel-tabs">
            <div class="nod-panel-tab active" data-tab="tmi" onclick="NOD.switchTab('tmi')">
                <i class="fas fa-exclamation-triangle"></i>
                TMIs
            </div>
            <div class="nod-panel-tab" data-tab="advisories" onclick="NOD.switchTab('advisories')">
                <i class="fas fa-bullhorn"></i>
                Advisories
            </div>
            <div class="nod-panel-tab" data-tab="incidents" onclick="NOD.switchTab('incidents')">
                <i class="fas fa-broadcast-tower"></i>
                JATOC
            </div>
        </div>
        
        <!-- Panel Content -->
        <div class="nod-panel-content">
            
            <!-- TMI Tab -->
            <div class="nod-tab-pane active" id="tab-tmi">
                <!-- Ground Stops Section -->
                <div class="nod-section" id="section-gs">
                    <div class="nod-section-header" onclick="NOD.toggleSection('gs')">
                        <span class="nod-section-title"><i class="fas fa-ban mr-2"></i>Ground Stops</span>
                        <span class="nod-section-badge" id="gs-count">0</span>
                    </div>
                    <div class="nod-section-body">
                        <div class="nod-section-body-inner" id="gs-list">
                            <div class="nod-loading"><i class="fas fa-spinner"></i> Loading...</div>
                        </div>
                    </div>
                </div>
                
                <!-- GDPs Section -->
                <div class="nod-section" id="section-gdp">
                    <div class="nod-section-header" onclick="NOD.toggleSection('gdp')">
                        <span class="nod-section-title"><i class="fas fa-clock mr-2"></i>Ground Delay Programs</span>
                        <span class="nod-section-badge" id="gdp-count">0</span>
                    </div>
                    <div class="nod-section-body">
                        <div class="nod-section-body-inner" id="gdp-list">
                            <div class="nod-loading"><i class="fas fa-spinner"></i> Loading...</div>
                        </div>
                    </div>
                </div>
                
                <!-- Reroutes Section -->
                <div class="nod-section" id="section-reroutes">
                    <div class="nod-section-header" onclick="NOD.toggleSection('reroutes')">
                        <span class="nod-section-title"><i class="fas fa-route mr-2"></i>Active Reroutes</span>
                        <span class="nod-section-badge" id="reroutes-count">0</span>
                    </div>
                    <div class="nod-section-body">
                        <div class="nod-section-body-inner" id="reroutes-list">
                            <div class="nod-loading"><i class="fas fa-spinner"></i> Loading...</div>
                        </div>
                    </div>
                </div>
                
                <!-- Public Routes Section -->
                <div class="nod-section" id="section-public-routes">
                    <div class="nod-section-header" onclick="NOD.toggleSection('public-routes')">
                        <span class="nod-section-title"><i class="fas fa-share-alt mr-2"></i>Public Routes</span>
                        <span class="nod-section-badge" id="public-routes-count">0</span>
                    </div>
                    <div class="nod-section-body">
                        <div class="nod-section-body-inner" id="public-routes-list">
                            <div class="nod-loading"><i class="fas fa-spinner"></i> Loading...</div>
                        </div>
                    </div>
                </div>
                
                <!-- Discord TMIs Section -->
                <div class="nod-section" id="section-discord">
                    <div class="nod-section-header" onclick="NOD.toggleSection('discord')">
                        <span class="nod-section-title"><i class="fab fa-discord mr-2"></i>Discord TMIs</span>
                        <span class="nod-section-badge" id="discord-count">0</span>
                    </div>
                    <div class="nod-section-body">
                        <div class="nod-section-body-inner" id="discord-list">
                            <div class="nod-empty">
                                <i class="fab fa-discord"></i>
                                <p>Discord integration not configured</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Advisories Tab -->
            <div class="nod-tab-pane" id="tab-advisories">
                <div class="nod-section expanded">
                    <div class="nod-section-header">
                        <span class="nod-section-title"><i class="fas fa-bullhorn mr-2"></i>Today's Advisories</span>
                        <button class="btn btn-sm btn-outline-secondary" onclick="NOD.showAdvisoryModal()">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    <div class="nod-section-body" style="max-height: none;">
                        <div class="nod-section-body-inner" id="advisories-list">
                            <div class="nod-loading"><i class="fas fa-spinner"></i> Loading...</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- JATOC Incidents Tab -->
            <div class="nod-tab-pane" id="tab-incidents">
                <div class="nod-section expanded">
                    <div class="nod-section-header">
                        <span class="nod-section-title"><i class="fas fa-broadcast-tower mr-2"></i>Active Incidents</span>
                        <a href="jatoc.php" class="btn btn-sm btn-outline-info" target="_blank">
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                    </div>
                    <div class="nod-section-body" style="max-height: none;">
                        <div class="nod-section-body-inner" id="incidents-list">
                            <div class="nod-loading"><i class="fas fa-spinner"></i> Loading...</div>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
    
    <!-- Panel Toggle Button -->
    <button class="nod-panel-toggle" id="panelToggle" onclick="NOD.togglePanel()">
        <i class="fas fa-chevron-right" id="panelToggleIcon"></i>
    </button>
    
    <!-- Legend Toggle Button (shown when legend is hidden) -->
    <button class="nod-map-legend-show" id="mapLegendShowBtn" onclick="NOD.toggleMapLegend()" title="Show color legend" style="display: none;">
        <i class="fas fa-palette"></i>
    </button>
    
</div>

<!-- Advisory Modal -->
<div class="modal fade" id="advisoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="fas fa-bullhorn mr-2"></i>
                    <span id="advisoryModalTitle">New Advisory</span>
                </h5>
                <button type="button" class="close text-light" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="advisoryForm">
                    <input type="hidden" id="advisoryId">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="text-muted small">TYPE</label>
                                <select id="advisoryType" class="form-control bg-dark text-light border-secondary" required>
                                    <option value="OPERATIONS_PLAN">Operations Plan</option>
                                    <option value="REROUTE">Reroute</option>
                                    <option value="GDP">GDP</option>
                                    <option value="GS">Ground Stop</option>
                                    <option value="AFP">AFP</option>
                                    <option value="FACILITY_OUTAGE">Facility Outage</option>
                                    <option value="WEATHER">Weather</option>
                                    <option value="GENERAL">General</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="text-muted small">PRIORITY</label>
                                <select id="advisoryPriority" class="form-control bg-dark text-light border-secondary">
                                    <option value="1">High</option>
                                    <option value="2" selected>Normal</option>
                                    <option value="3">Low</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="text-muted small">SUBJECT</label>
                        <input type="text" id="advisorySubject" class="form-control bg-dark text-light border-secondary" required placeholder="Brief subject line">
                    </div>
                    <div class="form-group">
                        <label class="text-muted small">BODY TEXT</label>
                        <textarea id="advisoryBody" class="form-control bg-dark text-light border-secondary" rows="6" required placeholder="Full advisory text..."></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="text-muted small">VALID START (UTC)</label>
                                <input type="datetime-local" id="advisoryStart" class="form-control bg-dark text-light border-secondary" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="text-muted small">VALID END (UTC) <span class="text-muted">(optional)</span></label>
                                <input type="datetime-local" id="advisoryEnd" class="form-control bg-dark text-light border-secondary">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="text-muted small">IMPACTED AREA</label>
                                <input type="text" id="advisoryArea" class="form-control bg-dark text-light border-secondary" placeholder="e.g., NY METRO, NE CORRIDOR">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="text-muted small">FACILITIES <span class="text-muted">(comma-separated)</span></label>
                                <input type="text" id="advisoryFacilities" class="form-control bg-dark text-light border-secondary" placeholder="e.g., ZNY, ZDC, ZBW">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="NOD.saveAdvisory()">
                    <i class="fas fa-save mr-1"></i> Save Advisory
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Advisory Detail Modal -->
<div class="modal fade" id="advisoryDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="advisoryDetailTitle">Advisory Details</h5>
                <button type="button" class="close text-light" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body" id="advisoryDetailBody">
                <!-- Populated dynamically -->
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include('load/footer.php'); ?>

<!-- MapLibre GL -->
<script src="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.js"></script>

<!-- NOD Configuration -->
<script>
window.NOD_CONFIG = {
    userName: '<?= addslashes($user_name) ?>',
    userCid: '<?= addslashes($user_cid) ?>',
    refreshInterval: 30000,  // 30 seconds
    trafficRefreshInterval: 15000,  // 15 seconds
    mapStyle: 'https://basemaps.cartocdn.com/gl/dark-matter-nolabels-gl-style/style.json',
    mapCenter: [-98.5, 39.5],
    mapZoom: 4,
    geojsonPaths: {
        artcc: 'assets/geojson/artcc.json',
        tracon: 'assets/geojson/tracon.json',
        high: 'assets/geojson/high.json',
        low: 'assets/geojson/low.json',
        superhigh: 'assets/geojson/superhigh.json'
    }
};
</script>

<!-- NOD Module -->
<script src="assets/js/nod.js"></script>

</body>
</html>
