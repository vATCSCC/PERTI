<?php
/**
 * JATOC Centralized Configuration
 *
 * Single source of truth for all JATOC constants and mappings.
 * This file should be included by all JATOC API endpoints.
 */

// Trigger codes mapping (A-W)
const JATOC_TRIGGERS = [
    'A' => 'AFV (Audio for VATSIM)',
    'B' => 'Other Audio Issue',
    'C' => 'Multiple Audio Issues',
    'D' => 'Datafeed (VATSIM)',
    'E' => 'Datafeed (Other)',
    'F' => 'Frequency Issue',
    'H' => 'Radar Client Issue',
    'J' => 'Staffing (Below Minimum)',
    'K' => 'Staffing (At Minimum)',
    'M' => 'Staffing (None)',
    'Q' => 'Other',
    'R' => 'Pilot Issue',
    'S' => 'Security (Real World)',
    'T' => 'Security (VATSIM)',
    'U' => 'Unknown',
    'V' => 'Volume',
    'W' => 'Weather'
];

// Incident types (stored in 'incident_type' column, formerly 'status')
// Describes WHAT type of incident occurred
const JATOC_INCIDENT_TYPES = [
    'ATC_ZERO' => 'ATC Zero',
    'ATC_ALERT' => 'ATC Alert',
    'ATC_LIMITED' => 'ATC Limited',
    'NON_RESPONSIVE' => 'Non-Responsive',
    'OTHER' => 'Other'
];

// Lifecycle statuses (stored in 'lifecycle_status' column, formerly 'incident_status')
// Describes WHERE the incident is in its lifecycle
const JATOC_LIFECYCLE_STATUSES = [
    'PENDING' => 'Pending',
    'ACTIVE' => 'Active',
    'MONITORING' => 'Monitoring',
    'ESCALATED' => 'Escalated',
    'CLOSED' => 'Closed'
];

// Facility types
const JATOC_FACILITY_TYPES = [
    'ARTCC' => 'Air Route Traffic Control Center',
    'TRACON' => 'Terminal Radar Approach Control',
    'ATCT' => 'Airport Traffic Control Tower',
    'COMBINED' => 'Combined Facility',
    'FIR' => 'Flight Information Region'
];

// Operations levels (1 = normal, 2 = elevated, 3 = major event)
const JATOC_OPS_LEVELS = [
    1 => 'Steady State',
    2 => 'Escalated Activity',
    3 => 'Major Event'
];

// Update types for incident updates
const JATOC_UPDATE_TYPES = [
    'REMARK' => 'Remark',
    'STATUS_CHANGE' => 'Status Change',
    'PAGED' => 'Personnel Paged',
    'ESCALATION' => 'Escalation',
    'OPS_LEVEL' => 'Ops Level Change',
    'REPORT_CREATED' => 'Report Created',
    'CLOSEOUT' => 'Closeout'
];

// User roles with permissions
// Permissions: create, update, delete, close, report, ops_level, personnel, view
const JATOC_ROLES = [
    'DCC' => ['create', 'update', 'delete', 'close', 'report', 'ops_level', 'personnel', 'daily_ops', 'special_emphasis', 'view'],
    'FACILITY' => ['create', 'update', 'close', 'view'],
    'ECFMP' => ['create', 'update', 'view'],
    'CTP' => ['create', 'update', 'view'],
    'READONLY' => ['view']
];

// Daily ops item types
const JATOC_DAILY_OPS_TYPES = [
    'POTUS' => 'POTUS Movement',
    'SPACE' => 'Space Activity'
];

// Personnel elements
const JATOC_PERSONNEL_ELEMENTS = [
    'JATOC1', 'JATOC2', 'JATOC3', 'JATOC4', 'JATOC5',
    'JATOC6', 'JATOC7', 'JATOC8', 'JATOC9', 'JATOC10',
    'SUP'
];
