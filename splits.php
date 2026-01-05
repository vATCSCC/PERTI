<?php
/**
 * Airspace Splits Configuration Tool
 * 
 * Allows users to create, manage, and publish sector split configurations.
 * Configurations define how sectors are grouped into positions for a given time window.
 */
?>
<!DOCTYPE html>
<html>
<head>
<?php require_once 'load/header.php'; ?>
<link href="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.css" rel="stylesheet" />

<style>
/* ═══════════════════════════════════════════════════════════════════
   SPLITS PAGE - CONFIGURATION MANAGEMENT
   ═══════════════════════════════════════════════════════════════════ */

/* Sidebar panel */
.splits-sidebar {
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex: 1;
    min-height: 0;
}

/* Map container */
.splits-map-container {
    position: relative;
    height: 700px;
    border: 1px solid #333;
    border-radius: 8px;
    overflow: hidden;
}

/* Left column flex layout */
.splits-left-column {
    display: flex;
    flex-direction: column;
    height: 700px;
}

#splits-map {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
}

/* Sidebar Header */
.sidebar-header {
    padding: 12px 16px;
    background: rgba(0,0,0,0.2);
    border-bottom: 1px solid #333;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.sidebar-header h5 {
    margin: 0;
    font-size: 14px;
    color: #fff;
}

/* Mode Tabs */
.mode-tabs {
    display: flex;
    border-bottom: 1px solid #333;
    background: rgba(0,0,0,0.1);
}

.mode-tab {
    flex: 1;
    padding: 10px;
    text-align: center;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #888;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.15s;
}

.mode-tab:hover {
    color: #ccc;
    background: rgba(255,255,255,0.03);
}

.mode-tab.active {
    color: #4dabf7;
    border-bottom-color: #4dabf7;
}

/* Sidebar Content */
.sidebar-content {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
}

.mode-content {
    display: none;
    padding: 16px;
}

.mode-content.active {
    display: block;
}

/* Section Headers */
.section-header {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #bbb;
    margin-bottom: 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid #333;
}

/* Form Groups */
.form-group {
    margin-bottom: 12px;
}

.form-group label {
    display: block;
    font-size: 11px;
    color: #ccc;
    margin-bottom: 4px;
}

.form-group .form-control {
    background: #252540;
    border: 1px solid #333;
    color: #fff;
    font-size: 13px;
}

.form-group .form-control:focus {
    background: #2a2a48;
    border-color: #4dabf7;
    box-shadow: none;
}

/* Modal styling overrides for dark theme */
.modal-content.bg-dark label,
.modal-content.bg-dark .form-group label {
    color: #ccc !important;
}

.modal-content.bg-dark .btn-outline-secondary,
.modal-content.bg-dark .btn-outline-info,
.modal-content.bg-dark .btn-outline-primary {
    color: #ccc;
    border-color: #666;
}

.modal-content.bg-dark .btn-outline-secondary:hover,
.modal-content.bg-dark .btn-outline-info:hover,
.modal-content.bg-dark .btn-outline-primary:hover {
    color: #fff;
}

.modal-content.bg-dark .btn-xs {
    color: #ccc;
}

.modal-content.bg-dark .sector-chip {
    color: #ddd;
}

.modal-content.bg-dark .sector-chip.selected {
    color: #fff;
    background: #4dabf7;
}

.modal-content.bg-dark .sector-chip.assigned {
    color: #888;
}

/* Selected sectors display - dark text on light background */
.modal-content.bg-dark #selected-sectors-display,
.modal-content.bg-dark #area-selected-sectors {
    color: #333 !important;
    background: #6c757d !important;
}

/* Review card - make it darker with light text */
.modal-content.bg-dark .card.bg-secondary {
    background: #2a2a4a !important;
}

.modal-content.bg-dark .card.bg-secondary .card-title,
.modal-content.bg-dark .card.bg-secondary div {
    color: #ddd !important;
}

.modal-content.bg-dark .card.bg-secondary .text-muted {
    color: #888 !important;
}

/* Review step spans */
.modal-content.bg-dark .review-split-item span,
.modal-content.bg-dark .step-indicator span {
    color: #ccc;
}

.modal-content.bg-dark .step-indicator span.active {
    color: #4dabf7;
}

/* Responsive modal styling - prevent overflow beyond screen */
.modal-dialog {
    max-height: calc(100vh - 60px);
    margin: 30px auto;
}

.modal-dialog.modal-lg,
.modal-dialog.modal-xl {
    max-width: calc(100vw - 40px);
}

@media (min-width: 992px) {
    .modal-dialog.modal-lg {
        max-width: 800px;
    }
}

@media (min-width: 1200px) {
    .modal-dialog.modal-xl {
        max-width: 1140px;
    }
}

.modal-content {
    max-height: calc(100vh - 60px);
    display: flex;
    flex-direction: column;
}

.modal-body {
    overflow-y: auto;
    flex: 1 1 auto;
}

/* Ensure modal header and footer don't shrink */
.modal-header,
.modal-footer {
    flex-shrink: 0;
}

/* Mobile-specific adjustments */
@media (max-width: 576px) {
    .modal-dialog {
        margin: 10px;
        max-height: calc(100vh - 20px);
        max-width: calc(100vw - 20px);
    }
    
    .modal-content {
        max-height: calc(100vh - 20px);
    }
    
    .modal-body {
        padding: 10px;
    }
    
    .modal-header,
    .modal-footer {
        padding: 10px;
    }
}

/* Tablet adjustments */
@media (max-width: 991px) and (min-width: 577px) {
    .modal-dialog.modal-lg,
    .modal-dialog.modal-xl {
        max-width: calc(100vw - 40px);
        margin: 20px auto;
    }
    
    .modal-content {
        max-height: calc(100vh - 40px);
    }
}

/* Ensure modal body scrolls properly with lots of content */
.modal-dialog-scrollable .modal-body {
    overflow-y: auto;
    overflow-x: hidden;
}

/* Prevent form elements from causing horizontal overflow */
.modal-body input,
.modal-body select,
.modal-body textarea {
    max-width: 100%;
}

/* Custom checkbox label */
.modal-content.bg-dark .custom-control-label {
    color: #ccc;
}

.form-row {
    display: flex;
    gap: 10px;
}

.form-row .form-group {
    flex: 1;
}

/* Config Cards */
.config-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid #333;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 10px;
    cursor: pointer;
    transition: all 0.15s;
}

.config-card:hover {
    background: rgba(255,255,255,0.06);
    border-color: #444;
}

.config-card.active {
    border-color: #4dabf7;
    background: rgba(77, 171, 247, 0.1);
}

.config-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}

.config-card-title {
    font-size: 13px;
    font-weight: 600;
    color: #fff;
}

.config-card-badge {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 3px;
    background: #333;
    color: #888;
}

.config-card-badge.published {
    background: #22863a;
    color: #fff;
}

.config-card-badge.draft {
    background: #6f42c1;
    color: #fff;
}

.config-card-details {
    font-size: 11px;
    color: #888;
}

/* Split/Position Items */
.split-item {
    background: rgba(255,255,255,0.03);
    border: 1px solid #333;
    border-radius: 4px;
    padding: 10px;
    margin-bottom: 8px;
}

.split-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}

.split-item-name {
    font-size: 0.9375rem;
    font-weight: 600;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 8px;
}

.split-item-color {
    width: 16px;
    height: 16px;
    border-radius: 3px;
    border: 1px solid rgba(255,255,255,0.2);
}

.split-item-actions {
    display: flex;
    gap: 4px;
}

.split-item-actions .btn {
    padding: 2px 6px;
    font-size: 0.75rem;
}

.split-item-sectors {
    font-size: 0.8125rem;
    color: #888;
}

/* Sector Selection */
.sector-selection-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.sector-selection-actions {
    display: flex;
    gap: 6px;
}

.sector-selection-actions .btn {
    padding: 4px 8px;
    font-size: 10px;
}

.sector-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 4px;
    max-height: 200px;
    overflow-y: auto;
    margin-bottom: 10px;
}

.sector-chip {
    padding: 4px 6px;
    font-size: 10px;
    text-align: center;
    background: #333;
    border: 1px solid #444;
    border-radius: 3px;
    cursor: pointer;
    color: #aaa;
    transition: all 0.1s;
}

.sector-chip:hover {
    background: #3a3a55;
    color: #fff;
}

.sector-chip.selected {
    background: #4dabf7;
    border-color: #4dabf7;
    color: #fff;
}

.sector-type-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 4px;
    vertical-align: middle;
}

.sector-chip.selected .sector-type-dot {
    box-shadow: 0 0 0 1px rgba(255,255,255,0.5);
}

/* Area Groups */
.area-groups {
    margin-bottom: 10px;
}

.area-group-btn {
    padding: 4px 8px;
    font-size: 10px;
    margin-right: 4px;
    margin-bottom: 4px;
}

/* Active Configs Panel */
.active-configs-panel {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 1000;
    background: rgba(20, 20, 35, 0.95);
    border-radius: 6px;
    min-width: 200px;
    max-width: 280px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.4);
    border: 1px solid #333;
}

.active-configs-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    background: rgba(255,255,255,0.05);
    border-radius: 6px 6px 0 0;
    cursor: move;
}

.active-configs-title {
    font-size: 11px;
    font-weight: 600;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Button Variants */
.btn-xs {
    padding: 2px 6px;
    font-size: 10px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 30px 20px;
    color: #666;
}

.empty-state-icon {
    font-size: 32px;
    margin-bottom: 10px;
}

.empty-state-text {
    font-size: 12px;
}

/* Time inputs */
input[type="time"],
input[type="datetime-local"] {
    background: #252540;
    border: 1px solid #333;
    color: #fff;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 13px;
}

/* Hide MapLibre controls */
.maplibregl-ctrl-logo,
.maplibregl-ctrl-attrib,
.maplibregl-ctrl-attrib-inner {
    display: none !important;
}

/* Responsive */
@media (max-width: 992px) {
    .splits-left-column {
        height: 600px;
    }
    .splits-map-container {
        height: 600px;
    }
}

@media (max-width: 768px) {
    .splits-left-column {
        height: 500px;
    }
    .splits-map-container {
        height: 400px;
    }
}

/* Sector chips for selection */
.sector-chip {
    display: inline-block;
    padding: 4px 10px;
    margin: 2px;
    background: #2a2a4a;
    border: 1px solid #444;
    border-radius: 4px;
    font-size: 11px;
    cursor: pointer;
    transition: all 0.15s;
}

.sector-chip:hover {
    background: #3a3a5a;
}

.sector-chip.selected {
    background: #2a9d8f;
    border-color: #2a9d8f;
    color: #fff;
}

.sector-chip.assigned {
    opacity: 0.4;
    cursor: not-allowed;
    background: #333;
}

/* Split items in config wizard */
.split-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: #2a2a4a;
    border: 1px solid #444;
    border-radius: 4px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.15s;
}

.split-item:hover {
    background: #3a3a5a;
}

.split-item.editing {
    border-color: #4361ee;
    background: #2a2a5a;
}

.split-item-color {
    width: 24px;
    height: 24px;
    border-radius: 4px;
    flex-shrink: 0;
}

.split-item-info {
    flex: 1;
    min-width: 0;
}

.split-item-name {
    font-weight: 600;
    font-size: 1rem;
}

.split-item-sectors {
    font-size: 0.875rem;
    color: #999;
    margin-top: 2px;
}

/* Color swatches */
.color-swatch {
    width: 24px;
    height: 24px;
    border-radius: 4px;
    cursor: pointer;
    margin: 2px;
    border: 2px solid transparent;
    transition: all 0.15s;
}

.color-swatch:hover {
    transform: scale(1.1);
}

.color-swatch.selected {
    border-color: #fff;
    box-shadow: 0 0 0 2px rgba(255,255,255,0.3);
}

/* Area list styles */
.area-group-header {
    font-size: 11px;
    font-weight: 600;
    color: #888;
    padding: 8px 0 4px;
    border-bottom: 1px solid #333;
    margin-bottom: 4px;
}

.area-list-item {
    padding: 8px 10px;
    background: #2a2a4a;
    border-radius: 4px;
    margin-bottom: 4px;
    cursor: pointer;
    font-size: 12px;
}

.area-list-item:hover {
    background: #3a3a5a;
}

/* Sidebar Area Toggle List */
.area-toggle-group {
    margin-bottom: 8px;
}

.area-toggle-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 10px;
    font-weight: 600;
    color: #888;
    padding: 6px 0 3px;
    border-bottom: 1px solid #333;
    margin-bottom: 3px;
}

.artcc-toggle-btns {
    display: flex;
    gap: 2px;
}

.artcc-toggle-btns .btn-link {
    padding: 0 4px;
    font-size: 9px;
    color: #666;
}

.artcc-toggle-btns .btn-link:hover {
    color: #4dabf7;
}

.preset-color-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.area-toggle-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 6px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 11px;
    transition: background 0.2s;
}

.area-toggle-item:hover {
    background: rgba(255,255,255,0.05);
}

.area-toggle-checkbox {
    width: 14px;
    height: 14px;
    cursor: pointer;
    flex-shrink: 0;
}

.area-color-picker {
    width: 22px;
    height: 22px;
    border: 1px solid #555;
    border-radius: 4px;
    cursor: pointer;
    flex-shrink: 0;
    padding: 0;
    background: none;
}

.area-color-picker::-webkit-color-swatch-wrapper {
    padding: 2px;
}

.area-color-picker::-webkit-color-swatch {
    border: none;
    border-radius: 2px;
}

.area-color-picker::-moz-color-swatch {
    border: none;
    border-radius: 2px;
}

.area-toggle-color {
    width: 10px;
    height: 10px;
    border-radius: 2px;
    flex-shrink: 0;
}

.area-toggle-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #fff;
}

.area-toggle-count {
    font-size: 9px;
    color: #ccc;
    background: #333;
    padding: 1px 5px;
    border-radius: 8px;
}

.areas-toggle-actions {
    display: flex;
    gap: 4px;
}

/* Review step styles */
.review-split-item {
    display: flex;
    align-items: center;
    padding: 8px 10px;
    background: #2a2a4a;
    border-radius: 4px;
    margin-bottom: 6px;
}

.review-split-color {
    width: 16px;
    height: 16px;
    border-radius: 3px;
    margin-right: 10px;
}

/* Position badges */
.position-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    margin-right: 4px;
    color: #fff;
}

/* Config list item styles are defined later */

.status-draft { background: #666; }
.status-active { background: #22c55e; }
.status-scheduled { background: #f59e0b; }
.status-expired { background: #888; }
.status-cancelled { background: #ef4444; }

/* Scheduled Config Items */
.scheduled-config-item {
    background: rgba(245, 158, 11, 0.05);
    border: 1px solid rgba(245, 158, 11, 0.2);
    border-radius: 6px;
    padding: 10px 12px;
    margin-bottom: 8px;
    transition: all 0.15s;
}

.scheduled-config-item:hover {
    background: rgba(245, 158, 11, 0.1);
    border-color: rgba(245, 158, 11, 0.3);
}

.scheduled-config-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 6px;
}

.scheduled-config-info {
    flex: 1;
    min-width: 0;
}

.scheduled-config-name {
    font-size: 12px;
    font-weight: 600;
    color: #fff;
    margin-bottom: 2px;
}

.scheduled-config-artcc {
    font-size: 11px;
    color: #f59e0b;
    font-weight: 600;
}

.scheduled-config-timing {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px solid rgba(255,255,255,0.1);
}

.scheduled-time {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 3px;
}

.scheduled-time.start-time {
    color: #51cf66;
    background: rgba(81, 207, 102, 0.15);
}

.scheduled-time.end-time {
    color: #ff6b6b;
    background: rgba(255, 107, 107, 0.15);
}

.scheduled-time.countdown {
    color: #ffd93d;
    background: rgba(255, 217, 61, 0.15);
}

.scheduled-config-actions {
    display: flex;
    gap: 4px;
    margin-left: 8px;
}

.scheduled-config-positions {
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px solid rgba(255,255,255,0.05);
}

.scheduled-position-count {
    font-size: 10px;
    color: #888;
}

.scheduled-position-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 4px;
}

.scheduled-position-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 6px;
    background: rgba(255,255,255,0.05);
    border-radius: 3px;
    font-size: 10px;
    color: #ccc;
}

.scheduled-position-chip .pos-color {
    width: 8px;
    height: 8px;
    border-radius: 2px;
}

/* Active configs panel on map */
.active-configs-panel {
    position: absolute;
    bottom: 20px;
    left: 20px;
    z-index: 1000;
    background: rgba(20, 20, 35, 0.95);
    border-radius: 8px;
    min-width: 200px;
    max-width: 300px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.4);
    border: 1px solid #333;
}

.active-configs-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    border-bottom: 1px solid #333;
}

.active-configs-title {
    font-size: 12px;
    font-weight: 600;
    color: #ddd;
}

.active-configs-body {
    padding: 0;
    max-height: 350px;
    overflow-y: auto;
}

/* Active Configs Panel - Draggable */
.active-configs-panel {
    position: absolute;
    bottom: 20px;
    left: 20px;
    background: rgba(30, 30, 30, 0.95);
    border-radius: 8px;
    min-width: 280px;
    max-width: 350px;
    z-index: 100;
    box-shadow: 0 4px 16px rgba(0,0,0,0.5);
    border: 1px solid #333;
    transition: box-shadow 0.2s;
}

.active-configs-panel.dragging {
    box-shadow: 0 8px 24px rgba(0,0,0,0.7);
    cursor: grabbing;
}

.active-configs-panel.minimized .active-configs-body,
.active-configs-panel.minimized .active-configs-summary {
    display: none;
}

.active-configs-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    border-bottom: 1px solid #333;
    cursor: grab;
    user-select: none;
}

.active-configs-header:active {
    cursor: grabbing;
}

.header-buttons {
    display: flex;
    gap: 4px;
}

.header-buttons .btn {
    padding: 0 4px;
    font-size: 16px;
    line-height: 1;
}

.minimize-btn {
    font-weight: bold;
}

.active-configs-summary {
    padding: 8px 12px;
    background: rgba(0,0,0,0.3);
    border-bottom: 1px solid #333;
    font-size: 11px;
    color: #aaa;
}

.summary-stats {
    display: flex;
    gap: 12px;
}

.summary-stat {
    display: flex;
    align-items: center;
    gap: 4px;
}

.summary-stat-value {
    font-weight: 600;
    color: #fff;
}

.active-configs-title {
    font-size: 12px;
    font-weight: 600;
    color: #ddd;
}

/* Map Layer Controls */
.map-layer-controls {
    position: absolute;
    top: 10px;
    right: 10px;
    background: rgba(30, 30, 30, 0.95);
    border-radius: 8px;
    padding: 0;
    z-index: 100;
    min-width: 180px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    border: 1px solid #333;
    transition: all 0.3s ease;
}

.map-layer-controls.collapsed .layer-controls-body {
    display: none;
}

.map-layer-controls.collapsed .toggle-layers-btn i {
    transform: rotate(180deg);
}

.layer-controls-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    border-bottom: 1px solid #333;
    font-size: 11px;
    font-weight: 600;
    color: #ccc;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.layer-controls-body {
    padding: 8px 10px;
}

.layer-toggle-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px;
    margin: 0;
    cursor: pointer;
    font-size: 11px;
    color: #ccc;
    border-radius: 4px;
    transition: background 0.2s;
}

.layer-toggle-item:hover {
    background: rgba(255,255,255,0.05);
}

.layer-toggle-item input[type="checkbox"] {
    width: 14px;
    height: 14px;
    cursor: pointer;
    flex-shrink: 0;
}

.layer-toggle-item label {
    flex: 1;
    margin: 0;
    cursor: pointer;
    white-space: nowrap;
    color: #ddd;
}

.layer-color {
    width: 10px;
    height: 10px;
    border-radius: 2px;
    flex-shrink: 0;
}

.layer-opacity {
    width: 50px;
    height: 4px;
    -webkit-appearance: none;
    appearance: none;
    background: #333;
    border-radius: 2px;
    cursor: pointer;
    flex-shrink: 0;
}

.layer-opacity::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 10px;
    height: 10px;
    background: #888;
    border-radius: 50%;
    cursor: pointer;
}

.layer-opacity::-moz-range-thumb {
    width: 10px;
    height: 10px;
    background: #888;
    border-radius: 50%;
    cursor: pointer;
    border: none;
}

/* ═══════════════════════════════════════════════════════════════════
   PRESETS STYLES
   ═══════════════════════════════════════════════════════════════════ */

/* Preset list items */
.preset-list-item {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    background: rgba(255,255,255,0.03);
    border: 1px solid #333;
    border-radius: 4px;
    margin-bottom: 6px;
    cursor: pointer;
    transition: all 0.15s;
}

.preset-list-item:hover {
    background: rgba(255,255,255,0.06);
    border-color: #444;
}

.preset-list-item.active {
    border-color: #f4a261;
    background: rgba(244, 162, 97, 0.1);
}

.preset-list-info {
    flex: 1;
    min-width: 0;
}

.preset-list-name {
    font-size: 12px;
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.preset-list-meta {
    font-size: 10px;
    color: #888;
}

.preset-list-actions {
    display: flex;
    gap: 4px;
    margin-left: 8px;
}

.preset-badge {
    background: #f4a261;
    color: #000;
    font-size: 9px;
    padding: 2px 6px;
    border-radius: 3px;
    margin-left: 6px;
}

/* Preset load button in config wizard */
.preset-load-section {
    background: rgba(244, 162, 97, 0.1);
    border: 1px solid rgba(244, 162, 97, 0.3);
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 16px;
}

.preset-load-section label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #f4a261;
    margin-bottom: 8px;
    display: block;
}

.preset-dropdown {
    background: #252540;
    border: 1px solid #333;
    color: #fff;
}

.preset-dropdown:focus {
    border-color: #f4a261;
    box-shadow: none;
}

/* Save as preset section in review step */
.save-preset-section {
    background: rgba(244, 162, 97, 0.1);
    border: 1px solid rgba(244, 162, 97, 0.3);
    border-radius: 6px;
    padding: 12px;
    margin-top: 16px;
}

.save-preset-section .section-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #f4a261;
    margin-bottom: 8px;
}

/* Preset modal */
.preset-position-item {
    display: flex;
    align-items: center;
    padding: 8px 10px;
    background: rgba(255,255,255,0.03);
    border-radius: 4px;
    margin-bottom: 4px;
}

.preset-position-color {
    width: 14px;
    height: 14px;
    border-radius: 3px;
    margin-right: 10px;
    flex-shrink: 0;
}

.preset-position-name {
    font-size: 12px;
    font-weight: 500;
    color: #ddd;
}

.preset-position-sectors {
    font-size: 10px;
    color: #888;
    margin-left: auto;
}

.layer-sub-controls {
    display: flex;
    align-items: center;
    gap: 3px;
    margin-left: auto;
}

.layer-fill-btn, .layer-line-btn {
    padding: 2px 4px;
    font-size: 9px;
    background: transparent;
    border: 1px solid #444;
    color: #666;
    border-radius: 3px;
    opacity: 0.5;
    transition: all 0.2s;
    cursor: pointer;
}

.layer-fill-btn.active, .layer-line-btn.active {
    opacity: 1;
    color: #fff;
    border-color: #777;
    background: rgba(255,255,255,0.1);
}

.layer-fill-btn:hover, .layer-line-btn:hover {
    border-color: #888;
}

.toggle-layers-btn {
    padding: 0;
    line-height: 1;
}

.toggle-layers-btn i {
    transition: transform 0.2s;
}

/* Config list item styles */
.config-list-item {
    display: flex;
    align-items: center;
    padding: 8px 10px;
    border-bottom: 1px solid #333;
    cursor: pointer;
    transition: background 0.2s;
}

.config-list-item:hover {
    background: rgba(255,255,255,0.05);
}

.config-list-info {
    flex: 1;
}

.config-list-name {
    font-size: 12px;
    font-weight: 500;
    color: #fff;
}

.config-list-meta {
    font-size: 10px;
    color: #888;
}

.config-list-status {
    font-size: 9px;
    padding: 2px 6px;
    border-radius: 3px;
    text-transform: uppercase;
    margin-right: 8px;
}

.config-list-status.status-active {
    background: #198754;
    color: #fff;
}

.config-list-status.status-draft {
    background: #6c757d;
    color: #fff;
}

.config-list-actions .btn {
    padding: 2px 6px;
    font-size: 12px;
}

/* Config item actions inline */
.config-actions {
    display: flex;
    gap: 4px;
    margin-left: auto;
}

.config-actions .btn {
    padding: 0 5px;
    font-size: 12px;
    line-height: 1.2;
}

/* Summary ARTCC list */
.summary-artccs {
    font-size: 10px;
    color: #666;
}

/* ARTCC Group Styles */
.artcc-group {
    border-bottom: 1px solid #444;
}

.artcc-group:last-child {
    border-bottom: none;
}

.artcc-group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 10px;
    background: rgba(0,0,0,0.3);
    cursor: pointer;
    font-size: 12px;
}

.artcc-group-header:hover {
    background: rgba(0,0,0,0.4);
}

.artcc-name {
    font-weight: 600;
    color: #4dabf7;
}

.artcc-stats {
    font-size: 10px;
    color: #888;
}

.artcc-configs {
    padding: 0;
}

/* Active config item within ARTCC group */
.active-config-item {
    padding: 6px 10px;
    border-top: 1px solid #333;
}

.active-config-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
}

.config-name {
    font-size: 11px;
    font-weight: 500;
    color: #ddd;
}

.active-config-splits {
    padding-left: 4px;
}

/* Split detail row */
.split-detail {
    margin-bottom: 4px;
    padding: 4px 6px;
    background: rgba(255,255,255,0.03);
    border-radius: 3px;
}

.split-header {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 2px;
}

.split-color {
    width: 10px;
    height: 10px;
    border-radius: 2px;
    flex-shrink: 0;
}

.split-name {
    font-size: 11px;
    font-weight: 500;
    color: #ddd;
}

.split-count {
    font-size: 10px;
    color: #888;
    margin-left: auto;
}

.split-count::after {
    content: ' sectors';
}

.split-sectors {
    font-size: 9px;
    color: #777;
    font-family: 'Consolas', 'Monaco', monospace;
    word-break: break-all;
    line-height: 1.4;
}

/* Frequency display */
.split-freq {
    font-size: 10px;
    color: #4dabf7;
    font-family: 'Consolas', 'Monaco', monospace;
    background: rgba(77, 171, 247, 0.15);
    padding: 1px 4px;
    border-radius: 3px;
    margin-left: 4px;
}

/* Sector hierarchy display */
.split-sectors-hierarchy {
    font-size: 9px;
    color: #888;
    font-family: 'Consolas', 'Monaco', monospace;
    line-height: 1.5;
    padding: 4px 0 0 16px;
}

.sector-group {
    display: flex;
    align-items: baseline;
    gap: 4px;
    margin-bottom: 2px;
}

.sector-prefix {
    color: #aaa;
    font-weight: 600;
    min-width: 30px;
}

.sector-nums {
    color: #777;
    word-break: break-all;
}

/* Active Splits Toggle Button */
.active-splits-toggle {
    position: absolute;
    bottom: 20px;
    left: 20px;
    z-index: 99;
    background: rgba(30, 30, 30, 0.95);
    border: 1px solid #333;
    border-radius: 8px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    font-size: 12px;
    color: #aaa;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    transition: all 0.2s ease;
}

.active-splits-toggle:hover {
    background: rgba(40, 40, 40, 0.98);
    color: #fff;
    border-color: #4dabf7;
}

.active-splits-toggle.active {
    background: rgba(77, 171, 247, 0.2);
    border-color: #4dabf7;
    color: #4dabf7;
}

.active-splits-toggle .toggle-icon {
    font-size: 14px;
}

.active-splits-toggle .toggle-count {
    background: #4dabf7;
    color: #000;
    font-size: 10px;
    font-weight: 600;
    padding: 1px 5px;
    border-radius: 10px;
    min-width: 18px;
    text-align: center;
}

/* Floating Panel Text Summary Styles */
.active-splits-summary-text {
    padding: 10px;
}

.summary-artcc-group {
    margin-bottom: 12px;
}

.summary-artcc-group:last-child {
    margin-bottom: 0;
}

.summary-artcc-header {
    font-size: 13px;
    font-weight: 600;
    color: #4dabf7;
    padding: 5px 8px;
    background: rgba(77, 171, 247, 0.1);
    border-radius: 4px;
    margin-bottom: 8px;
    cursor: pointer;
}

.summary-artcc-header:hover {
    background: rgba(77, 171, 247, 0.2);
}

.summary-config-info {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 8px;
    margin-bottom: 6px;
    font-size: 11px;
    background: rgba(255,255,255,0.03);
    border-radius: 4px;
}

.summary-config-name {
    color: #bbb;
    font-weight: 500;
}

.summary-config-timing {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 11px;
    color: #cc8800;
    background: rgba(204, 136, 0, 0.15);
    padding: 2px 6px;
    border-radius: 3px;
}

.summary-position {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding: 6px 8px;
    font-size: 12px;
    border-left: 3px solid #444;
    margin-left: 6px;
    margin-bottom: 6px;
    background: rgba(0,0,0,0.2);
    border-radius: 0 4px 4px 0;
}

.summary-pos-main {
    display: flex;
    align-items: center;
    gap: 8px;
}

.summary-pos-color {
    width: 10px;
    height: 10px;
    border-radius: 2px;
    flex-shrink: 0;
}

.summary-pos-name {
    font-weight: 600;
    font-size: 12px;
    color: #eee;
}

.summary-pos-freq {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 11px;
    color: #4dabf7;
    background: rgba(77, 171, 247, 0.15);
    padding: 2px 6px;
    border-radius: 3px;
}

.summary-pos-controller {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 11px;
    color: #51cf66;
    background: rgba(81, 207, 102, 0.15);
    padding: 2px 6px;
    border-radius: 3px;
}

.summary-pos-timing {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 10px;
    color: #cc8800;
    background: rgba(204, 136, 0, 0.15);
    padding: 2px 6px;
    border-radius: 3px;
    margin-left: auto;
}

.summary-pos-sectors {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 10px;
    color: #999;
    word-break: break-all;
    padding-left: 18px;
}

.summary-pos-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    padding-left: 18px;
    margin-top: 4px;
}

.filter-tag {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 11px;
    color: #f0a500;
    background: rgba(240, 165, 0, 0.18);
    padding: 2px 7px;
    border-radius: 3px;
    white-space: nowrap;
}

.filter-label {
    color: #d4a517;
    font-weight: 600;
    margin-right: 2px;
}

.filter-note {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 11px;
    color: #6dd5ed;
    background: rgba(109, 213, 237, 0.18);
    padding: 2px 7px;
    border-radius: 3px;
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: inline-block;
    vertical-align: middle;
}

.filter-note:hover {
    max-width: none;
    white-space: normal;
    word-break: break-word;
}

/* Filter Sections */
.filter-section {
    margin-bottom: 8px;
    border: 1px solid #444;
    border-radius: 4px;
    background: rgba(0,0,0,0.2);
}

.filter-section-header {
    padding: 8px 10px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    color: #aaa;
    transition: background 0.2s;
}

.filter-section-header:hover {
    background: rgba(255,255,255,0.05);
}

.filter-section-header .fa-chevron-down {
    transition: transform 0.2s;
}

.filter-section-header[aria-expanded="true"] .fa-chevron-down {
    transform: rotate(180deg);
}

.filter-section-body {
    padding: 10px;
    border-top: 1px solid #444;
    background: rgba(0,0,0,0.1);
}

.filter-section .form-group label {
    font-size: 11px;
    color: #999;
    margin-bottom: 2px;
}

.filter-section .form-control-sm {
    font-size: 12px;
}

.filter-section small {
    font-size: 9px;
}

/* Layer Selection Popup */
.layer-select-popup {
    position: absolute;
    z-index: 1000;
    background: rgba(30, 30, 30, 0.95);
    border: 1px solid #555;
    border-radius: 6px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.5);
    min-width: 220px;
    max-width: 320px;
}

.layer-select-header {
    padding: 8px 12px;
    font-size: 11px;
    font-weight: 600;
    color: #ddd;
    border-bottom: 1px solid #444;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.layer-select-header .popup-close-btn {
    background: none;
    border: none;
    color: #888;
    font-size: 18px;
    cursor: pointer;
    padding: 0 4px;
    line-height: 1;
}

.layer-select-header .popup-close-btn:hover {
    color: #fff;
}

.layer-select-list {
    max-height: 300px;
    overflow-y: auto;
}

.layer-select-item {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 12px;
    border-bottom: 1px solid #333;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background 0.2s;
    color: #eee;
}

.layer-select-item:last-child {
    border-bottom: none;
}

.layer-select-item:hover {
    background: rgba(255,255,255,0.1);
}

.layer-select-item .layer-type {
    font-size: 9px;
    padding: 2px 5px;
    border-radius: 3px;
    color: #fff;
    text-transform: uppercase;
    flex-shrink: 0;
}

.layer-select-item .layer-type.artcc { background: #FF00FF; }
.layer-select-item .layer-type.high { background: #FF6347; }
.layer-select-item .layer-type.low { background: #228B22; }
.layer-select-item .layer-type.superhigh { background: #9932CC; }
.layer-select-item .layer-type.tracon { background: #4682B4; }
.layer-select-item .layer-type.areas { background: #a855f7; }
.layer-select-item .layer-type.presets { background: #f59e0b; }
.layer-select-item .layer-type.active { background: #e63946; }

.layer-select-item .layer-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #eee;
}

.layer-select-item .layer-artcc {
    font-size: 10px;
    color: #aaa;
    font-family: monospace;
    flex-shrink: 0;
}

/* Sector Selection Popup (for map selection mode with overlapping features) */
.sector-select-popup {
    min-width: 250px;
    max-width: 350px;
}

.sector-select-popup .layer-select-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sector-select-popup .popup-close-btn {
    background: none;
    border: none;
    color: #888;
    font-size: 18px;
    cursor: pointer;
    padding: 0 4px;
    line-height: 1;
}

.sector-select-popup .popup-close-btn:hover {
    color: #fff;
}

.sector-select-item {
    cursor: pointer;
}

.sector-select-item .sector-checkbox {
    margin-right: 4px;
    cursor: pointer;
}

.sector-select-item .selected-indicator {
    margin-left: auto;
    color: #4dabf7;
    font-weight: bold;
    min-width: 16px;
}

.sector-select-item.is-selected {
    background: rgba(77, 171, 247, 0.15);
}

.sector-select-item.is-selected:hover {
    background: rgba(77, 171, 247, 0.25);
}

.sector-select-actions {
    display: flex;
    gap: 6px;
    padding: 8px 12px;
    border-top: 1px solid #444;
    justify-content: flex-end;
}

.sector-select-actions .btn {
    font-size: 10px;
    padding: 4px 8px;
}

/* Map Selection Mode Indicator */
.map-selection-indicator {
    position: absolute;
    top: 10px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(30, 30, 30, 0.95);
    border: 2px solid;
    border-radius: 20px;
    padding: 8px 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    z-index: 200;
    box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    animation: pulse-border 2s infinite;
}

@keyframes pulse-border {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.indicator-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    animation: pulse-dot 1.5s infinite;
}

@keyframes pulse-dot {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.3); opacity: 0.7; }
}

.indicator-text {
    font-size: 12px;
    color: #fff;
    white-space: nowrap;
}

/* Selection highlight on map */
.selection-mode-active {
    cursor: crosshair !important;
}

/* Area Map Selection Indicator */
.area-map-selection-indicator {
    position: fixed;
    top: 60px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(30, 30, 30, 0.98);
    border: 2px solid #a855f7;
    border-radius: 8px;
    padding: 12px 20px;
    z-index: 1100;
    box-shadow: 0 4px 20px rgba(0,0,0,0.5);
}

.selection-indicator-content {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Sector Popup Styles */
.sector-popup {
    font-size: 12px;
    min-width: 180px;
}

.sector-popup .popup-header {
    padding: 6px 10px;
    margin: -10px -10px 8px -10px;
    border-radius: 4px 4px 0 0;
    color: #fff;
    font-size: 13px;
}

.sector-popup .popup-body {
    padding: 0;
}

.sector-popup .popup-row {
    display: flex;
    justify-content: space-between;
    padding: 3px 0;
    border-bottom: 1px solid #eee;
}

.sector-popup .popup-row:last-child {
    border-bottom: none;
}

.sector-popup .popup-row span:first-child {
    color: #666;
}

/* MapLibre popup overrides for dark theme */
.maplibregl-popup-content {
    background: #2a2a2a;
    color: #ddd;
    border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    padding: 10px;
}

.maplibregl-popup-anchor-bottom .maplibregl-popup-tip {
    border-top-color: #2a2a2a;
}

.maplibregl-popup-anchor-top .maplibregl-popup-tip {
    border-bottom-color: #2a2a2a;
}

.maplibregl-popup-anchor-left .maplibregl-popup-tip {
    border-right-color: #2a2a2a;
}

.maplibregl-popup-anchor-right .maplibregl-popup-tip {
    border-left-color: #2a2a2a;
}

.maplibregl-popup-close-button {
    color: #aaa;
    font-size: 18px;
    padding: 4px 8px;
}

.maplibregl-popup-close-button:hover {
    color: #fff;
    background: transparent;
}

/* Split Datablocks - toggleable info views on map labels */
.split-datablock {
    user-select: none;
    transition: box-shadow 0.2s;
}

.split-datablock:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.7);
}

.split-datablock .datablock-close:hover {
    opacity: 1 !important;
}
</style>
</head>

<body>

<?php require_once 'load/nav.php'; ?>

<section class="d-flex align-items-center position-relative min-vh-25 py-4" data-jarallax data-speed="0.3" style="pointer-events: all;">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1>Sector Splits</h1>
            <h4 class="text-white">Configure airspace sector groupings</h4>
        </center>
    </div>
</section>

<div class="container-fluid mt-3 mb-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-lg-4 col-xl-3 mb-4">
            <div class="splits-left-column">
            <!-- Title & Help Toggle (above sidebar) -->
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Configurations</h5>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="splits-help-toggle">
                    Show Help
                </button>
            </div>
            
            <!-- Collapsible Help Panel (above sidebar) -->
            <div id="splits-help-panel" class="text-left small mb-3" style="display: none;">
                <div class="card">
                    <div class="card-body py-2 px-3">
                        <ul class="pl-3 mb-2">
                            <li><strong>Presets</strong> are reusable split templates you can create and save for quick access.</li>
                            <li><strong>Areas</strong> are named sector groupings used to organize split configurations.</li>
                            <li>Click <strong>+ New Config</strong> to create an active split configuration with positions and sectors.</li>
                            <li>Use the <strong>Map Layers</strong> panel (top-right of map) to toggle visibility of different layer types.</li>
                            <li>Click on any sector on the map to see details and assign it to positions.</li>
                            <li>Use the <strong>"Show on Map"</strong> section to preview presets/areas without activating them.</li>
                            <li>Active splits appear in the <strong>"Active Now"</strong> tab and on the floating panel.</li>
                        </ul>
                        <div class="d-flex flex-wrap" style="gap: 12px;">
                            <span><span class="d-inline-block rounded-circle mr-1" style="width:10px;height:10px;background:#228B22;"></span> Low Sectors</span>
                            <span><span class="d-inline-block rounded-circle mr-1" style="width:10px;height:10px;background:#FF6347;"></span> High Sectors</span>
                            <span><span class="d-inline-block rounded-circle mr-1" style="width:10px;height:10px;background:#9932CC;"></span> Super High</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="splits-sidebar">
                <!-- Header with action buttons -->
                <div class="sidebar-header" style="justify-content: flex-end;">
                    <div>
                        <button class="btn btn-sm btn-outline-secondary mr-1" id="manage-areas-btn" title="Manage Areas">Areas</button>
                        <button class="btn btn-sm btn-outline-primary" id="new-config-btn">+ New Config</button>
                    </div>
                </div>
        
        <!-- Mode Tabs -->
        <div class="mode-tabs">
            <div class="mode-tab active" data-mode="presets">Presets</div>
            <div class="mode-tab" data-mode="scheduled">Scheduled</div>
            <div class="mode-tab" data-mode="active">Active Now</div>
            <div class="mode-tab" data-mode="browse">Areas</div>
        </div>
        
        <!-- Content Area -->
        <div class="sidebar-content">
            <!-- Saved Presets -->
            <div class="mode-content active" id="mode-presets">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="mb-0 text-light" style="font-size: 11px;">Saved Split Templates</label>
                    <button class="btn btn-xs btn-outline-warning" id="new-preset-btn">+ New Preset</button>
                </div>
                <div class="form-group mb-2">
                    <select id="presets-artcc-filter" class="form-control form-control-sm">
                        <option value="">All ARTCCs</option>
                    </select>
                </div>
                <div id="presets-list" style="max-height: 200px; overflow-y: auto;">
                    <div class="empty-state">
                        <div class="empty-state-icon">⭐</div>
                        <div class="empty-state-text">No saved presets yet.<br>Create one from a configuration or click "+ New Preset".</div>
                    </div>
                </div>
                
                <!-- Show on Map Section -->
                <hr class="my-2" style="border-color: #444;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="mb-0 text-light" style="font-size: 11px;">Show on Map</label>
                    <div>
                        <button class="btn btn-xs btn-outline-secondary" id="presets-show-all-btn">All</button>
                        <button class="btn btn-xs btn-outline-secondary" id="presets-hide-all-btn">None</button>
                    </div>
                </div>
                <div id="presets-toggle-list" style="max-height: 180px; overflow-y: auto;">
                    <div class="text-muted text-center py-2" style="font-size: 11px;">Loading presets...</div>
                </div>
            </div>
            
            <!-- Scheduled (Upcoming) -->
            <div class="mode-content" id="mode-scheduled">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="mb-0 text-light" style="font-size: 11px;">Upcoming Split Configurations</label>
                    <button class="btn btn-xs btn-outline-secondary" id="refresh-scheduled-btn" title="Refresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
                <div id="scheduled-configs-list">
                    <div class="empty-state">
                        <div class="empty-state-icon">📅</div>
                        <div class="empty-state-text">No scheduled configurations.</div>
                    </div>
                </div>
            </div>
            
            <!-- Active Now -->
            <div class="mode-content" id="mode-active">
                <div id="active-configs-list">
                    <div class="empty-state">
                        <div class="empty-state-icon">🔴</div>
                        <div class="empty-state-text">No active configurations.</div>
                    </div>
                </div>
            </div>
            
            <!-- Areas -->
            <div class="mode-content" id="mode-browse">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="mb-0 text-light">Show Areas on Map</label>
                    <button class="btn btn-xs btn-outline-primary" id="manage-areas-btn">
                        <i class="fas fa-cog"></i> Manage
                    </button>
                </div>
                <div class="form-group mb-2">
                    <select id="areas-artcc-select" class="form-control form-control-sm">
                        <option value="">All ARTCCs</option>
                    </select>
                </div>
                <div class="areas-toggle-actions mb-2">
                    <button class="btn btn-xs btn-outline-secondary" id="areas-show-all-btn">Show All</button>
                    <button class="btn btn-xs btn-outline-secondary" id="areas-hide-all-btn">Hide All</button>
                </div>
                <div id="areas-toggle-list" style="max-height: 300px; overflow-y: auto;">
                    <div class="text-muted text-center py-3" style="font-size: 11px;">Loading areas...</div>
                </div>
            </div>
        </div>
</div><!-- end splits-sidebar -->
</div><!-- end splits-left-column -->
</div><!-- end col sidebar -->

<!-- Map Column -->
<div class="col-lg-8 col-xl-9 mb-4">
    <div class="splits-map-container">
        <div id="splits-map"></div>
        
        <!-- Map Layer Controls -->
        <div class="map-layer-controls" id="map-layer-controls">
            <div class="layer-controls-header">
                <span>Map Layers</span>
                <button class="btn btn-xs btn-link text-muted toggle-layers-btn" onclick="document.getElementById('map-layer-controls').classList.toggle('collapsed')">
                    <i class="fas fa-chevron-up"></i>
                </button>
            </div>
            <div class="layer-controls-body">
                <div class="layer-toggle-item">
                    <input type="checkbox" class="layer-toggle" data-layer="artcc" id="layer-artcc" checked>
                    <span class="layer-color" style="background: #FF00FF;"></span>
                    <label for="layer-artcc">ARTCC</label>
                    <div class="layer-sub-controls">
                        <button class="btn btn-xs layer-fill-btn" data-layer="artcc" title="Fill"><i class="fas fa-square"></i></button>
                        <button class="btn btn-xs layer-line-btn active" data-layer="artcc" title="Outline"><i class="fas fa-border-style"></i></button>
                        <input type="range" class="layer-opacity" data-layer="artcc" min="0" max="100" value="50" title="Opacity">
                    </div>
                </div>
                <div class="layer-toggle-item">
                    <input type="checkbox" class="layer-toggle" data-layer="superhigh" id="layer-superhigh">
                    <span class="layer-color" style="background: #9932CC;"></span>
                    <label for="layer-superhigh">Super High</label>
                    <div class="layer-sub-controls">
                        <button class="btn btn-xs layer-fill-btn active" data-layer="superhigh" title="Fill"><i class="fas fa-square"></i></button>
                        <button class="btn btn-xs layer-line-btn active" data-layer="superhigh" title="Outline"><i class="fas fa-border-style"></i></button>
                        <input type="range" class="layer-opacity" data-layer="superhigh" min="0" max="100" value="50" title="Opacity">
                    </div>
                </div>
                <div class="layer-toggle-item">
                    <input type="checkbox" class="layer-toggle" data-layer="high" id="layer-high">
                    <span class="layer-color" style="background: #FF6347;"></span>
                    <label for="layer-high">High Sectors</label>
                    <div class="layer-sub-controls">
                        <button class="btn btn-xs layer-fill-btn active" data-layer="high" title="Fill"><i class="fas fa-square"></i></button>
                        <button class="btn btn-xs layer-line-btn active" data-layer="high" title="Outline"><i class="fas fa-border-style"></i></button>
                        <input type="range" class="layer-opacity" data-layer="high" min="0" max="100" value="50" title="Opacity">
                    </div>
                </div>
                <div class="layer-toggle-item">
                    <input type="checkbox" class="layer-toggle" data-layer="low" id="layer-low">
                    <span class="layer-color" style="background: #228B22;"></span>
                    <label for="layer-low">Low Sectors</label>
                    <div class="layer-sub-controls">
                        <button class="btn btn-xs layer-fill-btn active" data-layer="low" title="Fill"><i class="fas fa-square"></i></button>
                        <button class="btn btn-xs layer-line-btn active" data-layer="low" title="Outline"><i class="fas fa-border-style"></i></button>
                        <input type="range" class="layer-opacity" data-layer="low" min="0" max="100" value="50" title="Opacity">
                    </div>
                </div>
                <div class="layer-toggle-item">
                    <input type="checkbox" class="layer-toggle" data-layer="tracon" id="layer-tracon">
                    <span class="layer-color" style="background: #4682B4;"></span>
                    <label for="layer-tracon">TRACON</label>
                    <div class="layer-sub-controls">
                        <button class="btn btn-xs layer-fill-btn active" data-layer="tracon" title="Fill"><i class="fas fa-square"></i></button>
                        <button class="btn btn-xs layer-line-btn active" data-layer="tracon" title="Outline"><i class="fas fa-border-style"></i></button>
                        <input type="range" class="layer-opacity" data-layer="tracon" min="0" max="100" value="50" title="Opacity">
                    </div>
                </div>
                <div class="layer-toggle-item">
                    <input type="checkbox" class="layer-toggle" data-layer="areas" id="layer-areas">
                    <span class="layer-color" style="background: linear-gradient(90deg, #e63946, #f4a261, #2a9d8f);"></span>
                    <label for="layer-areas">Areas</label>
                    <div class="layer-sub-controls">
                        <button class="btn btn-xs layer-fill-btn active" data-layer="areas" title="Fill"><i class="fas fa-square"></i></button>
                        <button class="btn btn-xs layer-line-btn" data-layer="areas" title="Outline"><i class="fas fa-border-style"></i></button>
                        <input type="range" class="layer-opacity" data-layer="areas" min="0" max="100" value="50" title="Opacity">
                    </div>
                </div>
                <div class="layer-toggle-item">
                    <input type="checkbox" class="layer-toggle" data-layer="presets" id="layer-presets">
                    <span class="layer-color" style="background: linear-gradient(90deg, #f59e0b, #8b5cf6);"></span>
                    <label for="layer-presets">Presets</label>
                    <div class="layer-sub-controls">
                        <button class="btn btn-xs layer-fill-btn active" data-layer="presets" title="Fill"><i class="fas fa-square"></i></button>
                        <button class="btn btn-xs layer-line-btn active" data-layer="presets" title="Outline"><i class="fas fa-border-style"></i></button>
                        <input type="range" class="layer-opacity" data-layer="presets" min="0" max="100" value="50" title="Opacity">
                    </div>
                </div>
                <hr class="my-2" style="border-color: #444;">
                <div class="layer-toggle-item">
                    <input type="checkbox" class="layer-toggle" data-layer="activeConfigs" id="layer-active" checked>
                    <span class="layer-color" style="background: linear-gradient(90deg, #e63946, #2a9d8f);"></span>
                    <label for="layer-active">Active Splits</label>
                    <div class="layer-sub-controls">
                        <button class="btn btn-xs layer-fill-btn active" data-layer="activeConfigs" title="Fill"><i class="fas fa-square"></i></button>
                        <button class="btn btn-xs layer-line-btn" data-layer="activeConfigs" title="Outline"><i class="fas fa-border-style"></i></button>
                        <input type="range" class="layer-opacity" data-layer="activeConfigs" min="0" max="100" value="75" title="Opacity">
                    </div>
                </div>
                <!-- Active Splits Strata Filter -->
                <div class="layer-toggle-item ml-3" id="active-splits-strata-filters">
                    <span class="text-muted small mr-2">Strata:</span>
                    <input type="checkbox" id="strata-low" checked onchange="SplitsController.toggleActiveSplitsStrata('low', this.checked)">
                    <span class="layer-color" style="background: #228B22; width: 10px; height: 10px;"></span>
                    <label for="strata-low" class="small mr-2">Low</label>
                    <input type="checkbox" id="strata-high" checked onchange="SplitsController.toggleActiveSplitsStrata('high', this.checked)">
                    <span class="layer-color" style="background: #FF6347; width: 10px; height: 10px;"></span>
                    <label for="strata-high" class="small mr-2">High</label>
                    <input type="checkbox" id="strata-superhigh" checked onchange="SplitsController.toggleActiveSplitsStrata('superhigh', this.checked)">
                    <span class="layer-color" style="background: #e83e8c; width: 10px; height: 10px;"></span>
                    <label for="strata-superhigh" class="small">Super</label>
                </div>
            </div>
        </div>
        
        <!-- Active Splits Toggle Button -->
        <button class="active-splits-toggle active" id="active-splits-toggle-btn" title="Show Active Splits" style="display: none;">
            <span class="toggle-icon">📡</span>
            <span>Active Splits</span>
        </button>
        
        <!-- Active Configs Panel (on map) - Draggable -->
        <div class="active-configs-panel" id="active-configs-panel">
            <div class="active-configs-header draggable-handle" id="active-panel-header">
                <span class="active-configs-title">Active Splits</span>
                <div class="header-buttons">
                    <button class="btn btn-xs btn-link text-muted minimize-btn" onclick="document.getElementById('active-configs-panel').classList.toggle('minimized')" title="Minimize">−</button>
                    <button class="btn btn-xs btn-link text-muted close-panel-btn" title="Hide Panel">×</button>
                </div>
            </div>
            <div class="active-configs-summary" id="active-panel-summary"></div>
            <div class="active-configs-body" id="active-panel-content"></div>
        </div>
        
        <!-- Loading Overlay -->
        <div class="loading-overlay" id="loading-overlay" style="display: none;">
            <div class="text-center">
                <div class="spinner-border text-primary" role="status"></div>
                <div class="mt-2 text-light">Loading...</div>
            </div>
        </div>
    </div>
</div><!-- end col map -->
</div><!-- end row -->
</div><!-- end container-fluid -->

<!-- New/Edit Configuration Modal -->
<div class="modal fade" id="config-modal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="config-modal-title">New Configuration</h5>
                <button type="button" class="close text-light" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Step Indicator -->
                <div class="d-flex justify-content-center mb-4">
                    <div class="step-indicator">
                        <span class="step active" data-step="1">1. Config Details</span>
                        <span class="step-arrow">→</span>
                        <span class="step" data-step="2">2. Add Splits</span>
                        <span class="step-arrow">→</span>
                        <span class="step" data-step="3">3. Review & Save</span>
                    </div>
                </div>
                
                <!-- Step 1: Config Details -->
                <div class="config-step" id="config-step-1">
                    <!-- Load from Preset Section -->
                    <div class="preset-load-section" id="preset-load-section">
                        <label><i class="fas fa-star mr-1"></i> Quick Start from Preset</label>
                        <div class="d-flex align-items-center">
                            <select id="load-preset-dropdown" class="form-control form-control-sm preset-dropdown flex-grow-1">
                                <option value="">Select a saved preset...</option>
                            </select>
                            <button class="btn btn-sm btn-warning ml-2" id="load-preset-btn" disabled>Load</button>
                        </div>
                        <small class="text-muted d-block mt-1">Load positions from a saved preset template, then customize as needed.</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Configuration Name *</label>
                                <input type="text" id="config-name" class="form-control" placeholder="e.g., ZNY Event Splits">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>ARTCC *</label>
                                <select id="config-artcc" class="form-control">
                                    <option value="">Select ARTCC...</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Start Time (UTC) *</label>
                                <input type="datetime-local" id="config-start" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>End Time (UTC) *</label>
                                <input type="datetime-local" id="config-end" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Sector Type</label>
                        <select id="config-sector-type" class="form-control">
                            <option value="all">All Sectors</option>
                            <option value="high">High Sectors Only</option>
                            <option value="low">Low Sectors Only</option>
                            <option value="superhigh">Super High Sectors Only</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Description (optional)</label>
                        <textarea id="config-description" class="form-control" rows="2" placeholder="Brief description of this configuration..."></textarea>
                    </div>
                </div>
                
                <!-- Step 2: Add Splits -->
                <div class="config-step" id="config-step-2" style="display: none;">
                    <div class="row">
                        <div class="col-md-5">
                            <!-- Split List -->
                            <div class="section-header d-flex justify-content-between align-items-center">
                                <span>Positions/Splits</span>
                                <button class="btn btn-xs btn-outline-primary" id="add-split-btn">+ Add Split</button>
                            </div>
                            <div id="config-splits-list" style="max-height: 350px; overflow-y: auto;">
                                <div class="empty-state py-4">
                                    <div class="empty-state-text">No splits added yet.</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <!-- Sector Selection -->
                            <div id="sector-selection-area" style="display: none;">
                                <div class="section-header">Select Sectors for: <span id="editing-split-name" class="text-primary"></span></div>
                                
                                <!-- Quick Actions -->
                                <div class="sector-selection-header">
                                    <div class="sector-selection-actions">
                                        <button class="btn btn-outline-info btn-xs" id="select-on-map-btn" title="Click sectors directly on the main map">
                                            <i class="fas fa-map-marker-alt"></i> Select on Map
                                        </button>
                                        <button class="btn btn-outline-secondary btn-xs" id="select-all-sectors">Select All</button>
                                        <button class="btn btn-outline-secondary btn-xs" id="clear-all-sectors">Clear All</button>
                                    </div>
                                </div>
                                
                                <!-- Sector Input -->
                                <div class="sector-input-row mb-2">
                                    <div class="input-group input-group-sm">
                                        <input type="text" id="sector-input" class="form-control form-control-sm" 
                                               placeholder="e.g., ZDC50,ZDC51 or 50,51,52">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-primary btn-sm" id="sector-input-apply-btn" type="button">Apply</button>
                                        </div>
                                    </div>
                                    <small class="text-muted d-block mt-1" style="font-size: 9px;">
                                        Enter sector numbers separated by commas. Use just numbers (50,51) to auto-prefix with ARTCC.
                                    </small>
                                </div>
                                
                                <!-- Area Groups -->
                                <div class="area-groups" id="area-groups-container">
                                    <label class="d-block mb-1 text-light" style="font-size: 10px;">Quick Select Area:</label>
                                </div>
                                
                                <!-- Sector Grid -->
                                <div class="sector-grid" id="sector-grid"></div>
                                
                                <div class="text-right mt-2">
                                    <button class="btn btn-sm btn-primary" id="done-selecting-btn">Done Selecting</button>
                                </div>
                            </div>
                            
                            <div id="no-split-selected" class="empty-state py-5">
                                <div class="empty-state-text">Select a split from the left to assign sectors.</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Step 3: Review & Save -->
                <div class="config-step" id="config-step-3" style="display: none;">
                    <div class="card bg-secondary mb-3">
                        <div class="card-body">
                            <h6 class="card-title" id="review-config-name">Configuration Name</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <small class="text-muted">ARTCC</small>
                                    <div id="review-artcc">-</div>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Start Time</small>
                                    <div id="review-start">-</div>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">End Time</small>
                                    <div id="review-end">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="section-header">Positions Summary</div>
                    <div id="review-splits-list"></div>
                    
                    <div class="form-group mt-3">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="publish-immediately">
                            <label class="custom-control-label" for="publish-immediately">Publish to global map immediately</label>
                        </div>
                        <small class="text-muted">When published, this configuration will be visible to all users and shown on the active splits map.</small>
                    </div>
                    
                    <!-- Save as Preset Section -->
                    <div class="save-preset-section">
                        <div class="section-label"><i class="fas fa-star mr-1"></i> Save as Reusable Preset</div>
                        <div class="d-flex align-items-center">
                            <input type="text" id="save-preset-name" class="form-control form-control-sm flex-grow-1" 
                                   placeholder="Enter preset name (optional)">
                            <button class="btn btn-sm btn-warning ml-2" id="save-as-preset-btn">
                                <i class="fas fa-save mr-1"></i> Save Preset
                            </button>
                        </div>
                        <small class="text-muted d-block mt-1">Save the current positions as a reusable template for future configurations.</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-secondary" id="config-prev-btn" style="display: none;">← Previous</button>
                <button type="button" class="btn btn-primary" id="config-next-btn">Next →</button>
                <button type="button" class="btn btn-success" id="config-save-btn" style="display: none;">Save Configuration</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Split Modal -->
<div class="modal fade" id="split-modal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="split-modal-title">Add New Position</h5>
                <button type="button" class="close text-light" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Basic Position Info -->
                    <div class="col-md-4">
                        <div class="section-header mb-2">Basic Info</div>
                        <div class="form-group">
                            <label>Position Name *</label>
                            <input type="text" id="split-name" class="form-control" placeholder="e.g., HIGH EAST">
                        </div>
                        <div class="form-group">
                            <label>Frequency</label>
                            <input type="text" id="split-frequency" class="form-control" placeholder="e.g., 132.450" pattern="[0-9]{3}\.[0-9]{2,3}">
                            <small class="text-muted">Format: 123.456</small>
                        </div>
                        <div class="form-group">
                            <label>Controller OI</label>
                            <input type="text" id="split-oi" class="form-control" placeholder="e.g., A1" maxlength="2" style="text-transform: uppercase;">
                            <small class="text-muted">Format: XX (2 chars)</small>
                        </div>
                        <div class="form-group">
                            <label>Color</label>
                            <div class="d-flex flex-wrap" id="split-color-picker">
                                <!-- Colors populated by JS -->
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6">
                                <div class="form-group">
                                    <label>Start Time</label>
                                    <input type="datetime-local" id="split-start" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-group">
                                    <label>End Time</label>
                                    <input type="datetime-local" id="split-end" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="col-md-8">
                        <div class="section-header mb-2">Filters (Optional)</div>
                        
                        <!-- Route Filters -->
                        <div class="filter-section">
                            <div class="filter-section-header" data-toggle="collapse" data-target="#route-filters">
                                <i class="fas fa-route mr-1"></i> Route Filters
                                <i class="fas fa-chevron-down float-right"></i>
                            </div>
                            <div class="collapse" id="route-filters">
                                <div class="filter-section-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-2">
                                                <label class="small">Origin (ORIG)</label>
                                                <input type="text" id="filter-orig" class="form-control form-control-sm" placeholder="e.g., JFK, LGA, N90, ZNY">
                                                <small class="text-muted">Airports, TRACONs, or ARTCCs</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-2">
                                                <label class="small">Destination (DEST)</label>
                                                <input type="text" id="filter-dest" class="form-control form-control-sm" placeholder="e.g., LAX, SFO, ZLA">
                                                <small class="text-muted">Airports, TRACONs, or ARTCCs</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-2">
                                                <label class="small">Fix</label>
                                                <input type="text" id="filter-fix" class="form-control form-control-sm" placeholder="e.g., LENDY, PUCKY">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-2">
                                                <label class="small">Gate</label>
                                                <input type="text" id="filter-gate" class="form-control form-control-sm" placeholder="e.g., North gates">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label class="small">Other Route Notes</label>
                                        <input type="text" id="filter-route-other" class="form-control form-control-sm" placeholder="Any other route criteria">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Altitude Filters -->
                        <div class="filter-section">
                            <div class="filter-section-header" data-toggle="collapse" data-target="#altitude-filters">
                                <i class="fas fa-arrows-alt-v mr-1"></i> Altitude Filters
                                <i class="fas fa-chevron-down float-right"></i>
                            </div>
                            <div class="collapse" id="altitude-filters">
                                <div class="filter-section-body">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group mb-2">
                                                <label class="small">Floor (FL)</label>
                                                <input type="text" id="filter-floor" class="form-control form-control-sm" placeholder="e.g., 240" maxlength="3" pattern="[0-9]{3}">
                                                <small class="text-muted">Format: 000</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group mb-2">
                                                <label class="small">Ceiling (FL)</label>
                                                <input type="text" id="filter-ceiling" class="form-control form-control-sm" placeholder="e.g., 350" maxlength="3" pattern="[0-9]{3}">
                                                <small class="text-muted">Format: 000</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group mb-2">
                                                <label class="small">Block (FL)</label>
                                                <input type="text" id="filter-block" class="form-control form-control-sm" placeholder="e.g., 240B350" maxlength="7" pattern="[0-9]{3}B[0-9]{3}">
                                                <small class="text-muted">Format: 000B000</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Aircraft Filters -->
                        <div class="filter-section">
                            <div class="filter-section-header" data-toggle="collapse" data-target="#aircraft-filters">
                                <i class="fas fa-plane mr-1"></i> Aircraft Filters
                                <i class="fas fa-chevron-down float-right"></i>
                            </div>
                            <div class="collapse" id="aircraft-filters">
                                <div class="filter-section-body">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group mb-2">
                                                <label class="small">Type</label>
                                                <select id="filter-acft-type" class="form-control form-control-sm">
                                                    <option value="">Any</option>
                                                    <option value="JETS">JETS</option>
                                                    <option value="TURBOPROPS">TURBOPROPS</option>
                                                    <option value="PROPS">PROPS</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group mb-2">
                                                <label class="small">Speed (KTS)</label>
                                                <input type="text" id="filter-speed" class="form-control form-control-sm" placeholder="e.g., >250">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group mb-2">
                                                <label class="small">RVSM</label>
                                                <select id="filter-rvsm" class="form-control form-control-sm">
                                                    <option value="">Any</option>
                                                    <option value="RVSM">RVSM only</option>
                                                    <option value="NON-RVSM">Non-RVSM only</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-2">
                                                <label class="small">Nav Equipment</label>
                                                <input type="text" id="filter-nav-equip" class="form-control form-control-sm" placeholder="e.g., RNAV, RNP">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-0">
                                                <label class="small">Other Aircraft Notes</label>
                                                <input type="text" id="filter-acft-other" class="form-control form-control-sm" placeholder="Any other criteria">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Other Filters -->
                        <div class="filter-section">
                            <div class="filter-section-header" data-toggle="collapse" data-target="#other-filters">
                                <i class="fas fa-ellipsis-h mr-1"></i> Other Notes
                                <i class="fas fa-chevron-down float-right"></i>
                            </div>
                            <div class="collapse" id="other-filters">
                                <div class="filter-section-body">
                                    <div class="form-group mb-0">
                                        <textarea id="filter-other" class="form-control form-control-sm" rows="2" placeholder="Any additional notes or criteria"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-split-btn">Add Position</button>
            </div>
        </div>
    </div>
</div>

<!-- Areas Management Modal -->
<div class="modal fade" id="areas-modal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">Manage Areas</h5>
                <button type="button" class="close text-light" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Areas are pre-defined groups of sectors (e.g., "ZNY A" = ZNY07+ZNY08+ZNY09...). They can be used for quick sector selection when creating splits.</p>
                
                <div class="row">
                    <div class="col-md-4 border-right border-secondary">
                        <!-- Area List -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong>Defined Areas</strong>
                            <button class="btn btn-xs btn-outline-primary" id="new-area-btn">+ New Area</button>
                        </div>
                        <div class="form-group">
                            <select id="areas-artcc-filter" class="form-control form-control-sm">
                                <option value="">All ARTCCs</option>
                            </select>
                        </div>
                        <div id="areas-list" style="max-height: 350px; overflow-y: auto;">
                            <div class="text-muted text-center py-3">Loading...</div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <!-- Area Editor -->
                        <div id="area-editor" style="display: none;">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <strong>Edit Area</strong>
                                <button class="btn btn-xs btn-outline-danger" id="delete-area-btn">Delete</button>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>ARTCC *</label>
                                        <select id="area-artcc" class="form-control form-control-sm">
                                            <option value="">Select ARTCC</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Area Name *</label>
                                        <input type="text" id="area-name" class="form-control form-control-sm" placeholder="e.g., A, B, WEST">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Description</label>
                                        <input type="text" id="area-description" class="form-control form-control-sm" placeholder="Optional description">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Sectors *</label>
                                <div class="d-flex mb-2 flex-wrap">
                                    <button class="btn btn-xs btn-outline-secondary mr-1 mb-1" id="area-load-sectors-btn">Load ARTCC Sectors</button>
                                    <button class="btn btn-xs btn-outline-info mr-1 mb-1" id="area-select-on-map-btn">
                                        <i class="fas fa-map-marker-alt"></i> Select on Map
                                    </button>
                                    <button class="btn btn-xs btn-outline-secondary mr-1 mb-1" id="area-select-all-btn">Select All</button>
                                    <button class="btn btn-xs btn-outline-secondary mb-1" id="area-clear-all-btn">Clear All</button>
                                </div>
                                <div class="sector-input-row mb-2">
                                    <div class="input-group input-group-sm">
                                        <input type="text" id="area-sector-input" class="form-control form-control-sm" 
                                               placeholder="e.g., ZDC50,ZDC51 or 50,51,52">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-primary btn-sm" id="area-sector-input-apply-btn" type="button">Apply</button>
                                        </div>
                                    </div>
                                    <small class="text-muted d-block mt-1" style="font-size: 9px;">
                                        Enter sector numbers separated by commas. Use just numbers (50,51) to auto-prefix with ARTCC.
                                    </small>
                                </div>
                                <div id="area-sector-grid" class="sector-grid" style="max-height: 200px; overflow-y: auto;"></div>
                            </div>
                            
                            <div class="form-group">
                                <label>Selected Sectors</label>
                                <div id="area-selected-sectors" class="p-2 bg-secondary rounded" style="min-height: 40px; font-size: 12px;"></div>
                            </div>
                            
                            <div class="text-right">
                                <button class="btn btn-secondary" id="cancel-area-btn">Cancel</button>
                                <button class="btn btn-primary" id="save-area-btn">Save Area</button>
                            </div>
                        </div>
                        
                        <div id="no-area-selected" class="text-center text-muted py-5">
                            <div style="font-size: 32px;">📍</div>
                            <div class="mt-2">Select an area to edit or click "+ New Area" to create one.</div>
                            <div class="mt-3">
                                <small class="text-muted">Tip: Use "Select on Map" when editing to click sectors directly on the map!</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Preset View/Edit Modal (Full Editor - like Config wizard without time fields) -->
<div class="modal fade" id="preset-modal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="preset-modal-title">New Preset</h5>
                <button type="button" class="close text-light" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Step Indicator -->
                <div class="d-flex justify-content-center mb-4">
                    <div class="step-indicator" id="preset-step-indicator">
                        <span class="step active" data-step="1">1. Preset Details</span>
                        <span class="step-arrow">→</span>
                        <span class="step" data-step="2">2. Define Positions</span>
                    </div>
                </div>
                
                <!-- Step 1: Preset Details -->
                <div class="preset-step" id="preset-step-1">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Preset Name *</label>
                                <input type="text" id="preset-name" class="form-control" placeholder="e.g., Standard Day Split">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>ARTCC *</label>
                                <select id="preset-artcc" class="form-control">
                                    <option value="">Select ARTCC...</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description (optional)</label>
                        <textarea id="preset-description" class="form-control" rows="2" placeholder="Brief description of when to use this preset..."></textarea>
                    </div>
                    
                    <div class="alert alert-warning mt-3 mb-0" style="font-size: 12px;">
                        <i class="fas fa-star mr-1"></i>
                        <strong>Presets</strong> are reusable position templates without time constraints. 
                        Define your positions once, then quickly load them into new configurations whenever needed.
                    </div>
                </div>
                
                <!-- Step 2: Define Positions -->
                <div class="preset-step" id="preset-step-2" style="display: none;">
                    <div class="row">
                        <div class="col-md-5">
                            <!-- Position List -->
                            <div class="section-header d-flex justify-content-between align-items-center">
                                <span>Positions/Splits</span>
                                <button class="btn btn-xs btn-outline-primary" id="preset-add-position-btn">+ Add Position</button>
                            </div>
                            <div id="preset-positions-list" style="max-height: 400px; overflow-y: auto;">
                                <div class="empty-state py-4">
                                    <div class="empty-state-text">No positions added yet.<br>Click "+ Add Position" to start.</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <!-- Sector Selection -->
                            <div id="preset-sector-selection-area" style="display: none;">
                                <div class="section-header">Select Sectors for: <span id="preset-editing-position-name" class="text-primary"></span></div>
                                
                                <!-- Quick Actions -->
                                <div class="sector-selection-header">
                                    <div class="sector-selection-actions">
                                        <button class="btn btn-outline-info btn-xs" id="preset-select-on-map-btn" title="Click sectors directly on the main map">
                                            <i class="fas fa-map-marker-alt"></i> Select on Map
                                        </button>
                                        <button class="btn btn-outline-secondary btn-xs" id="preset-select-all-sectors">Select All</button>
                                        <button class="btn btn-outline-secondary btn-xs" id="preset-clear-all-sectors">Clear All</button>
                                    </div>
                                </div>
                                
                                <!-- Sector Input -->
                                <div class="sector-input-row mb-2">
                                    <div class="input-group input-group-sm">
                                        <input type="text" id="preset-sector-input" class="form-control form-control-sm" 
                                               placeholder="e.g., ZDC50,ZDC51 or 50,51,52">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-primary btn-sm" id="preset-sector-input-apply-btn" type="button">Apply</button>
                                        </div>
                                    </div>
                                    <small class="text-muted d-block mt-1" style="font-size: 9px;">
                                        Enter sector numbers separated by commas. Use just numbers (50,51) to auto-prefix with ARTCC.
                                    </small>
                                </div>
                                
                                <!-- Area Groups -->
                                <div class="area-groups" id="preset-area-groups-container">
                                    <label class="d-block mb-1 text-light" style="font-size: 10px;">Quick Select Area:</label>
                                </div>
                                
                                <!-- Sector Grid -->
                                <div class="sector-grid" id="preset-sector-grid" style="max-height: 250px;"></div>
                                
                                <div class="text-right mt-2">
                                    <button class="btn btn-sm btn-primary" id="preset-done-selecting-btn">Done Selecting</button>
                                </div>
                            </div>
                            
                            <div id="preset-no-position-selected" class="empty-state py-5">
                                <div class="empty-state-icon">📍</div>
                                <div class="empty-state-text">Select a position from the left to assign sectors,<br>or click "+ Add Position" to create one.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-danger mr-auto" id="delete-preset-btn" style="display: none;">Delete Preset</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-secondary" id="preset-prev-btn" style="display: none;">← Previous</button>
                <button type="button" class="btn btn-primary" id="preset-next-btn">Next →</button>
                <button type="button" class="btn btn-warning" id="save-preset-modal-btn" style="display: none;">
                    <i class="fas fa-save mr-1"></i> Save Preset
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Position to Preset Modal -->
<div class="modal fade" id="preset-position-modal" tabindex="-1" data-backdrop="static">
    <div class="modal-dialog modal-md modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="preset-position-modal-title">Add Position</h5>
                <button type="button" class="close text-light" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Position Name *</label>
                    <input type="text" id="preset-position-name" class="form-control" placeholder="e.g., HIGH EAST">
                </div>
                <div class="form-group">
                    <label>Frequency</label>
                    <input type="text" id="preset-position-frequency" class="form-control" placeholder="e.g., 132.450">
                    <small class="text-muted">Format: 123.456</small>
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <div class="d-flex flex-wrap" id="preset-position-color-picker">
                        <!-- Colors populated by JS -->
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-preset-position-btn">Add Position</button>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.js"></script>
<script src="assets/js/splits.js"></script>

<?php require_once 'load/footer.php'; ?>
</body>
</html>
