<?php
/**
 * TMR Export API - Generate Discord-formatted TMR message
 *
 * GET ?p_id=N — Returns formatted TMR message following NTMO Guide template
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

require_once __DIR__ . '/../../../load/config.php';
require_once __DIR__ . '/../../../load/connect.php';

$p_id = get_int('p_id');
if (!$p_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing p_id']);
    exit;
}

// Load saved report
$stmt = $conn_pdo->prepare("SELECT * FROM r_tmr_reports WHERE p_id = ?");
$stmt->execute([$p_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'No TMR report found for this plan']);
    exit;
}

// Load plan metadata
$stmt = $conn_pdo->prepare("SELECT * FROM p_plans WHERE id = ?");
$stmt->execute([$p_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Plan not found']);
    exit;
}

// Build the Discord message following NTMO Guide template
$lines = [];

// Header
$eventName = strtoupper($plan['event_name'] ?? 'UNNAMED EVENT');
$lines[] = "**BEGIN {$eventName} TMR**";

$hostArtcc = $report['host_artcc'] ?: 'N/A';
$eventDate = $plan['event_date'] ? date('m/d Y', strtotime($plan['event_date'])) : 'N/A';
$startTime = $plan['event_start'] ? substr($plan['event_start'], 0, 5) : '????';
$endTime = $plan['event_end_time'] ? substr($plan['event_end_time'], 0, 5) : '????';
$lines[] = "{$hostArtcc} | {$eventDate} | {$startTime}-{$endTime}z";
$lines[] = '';

// TMR Triggers
$triggers = $report['tmr_triggers'] ? json_decode($report['tmr_triggers'], true) : [];
$triggerLabels = [
    'holding_15' => 'Airborne holding in excess of 15 minutes',
    'delays_30' => 'Departure delays in excess of 30 minutes',
    'no_notice_holding' => 'No notice airborne holding',
    'reroutes' => 'Reroutes',
    'ground_stop' => 'Ground stop',
    'gdp' => 'Ground Delay Program',
    'equipment' => 'Equipment',
];
$triggerList = [];
foreach ($triggers as $key) {
    $triggerList[] = $triggerLabels[$key] ?? $key;
}
$lines[] = '**TMR Triggers:** ' . (count($triggerList) > 0 ? implode(', ', $triggerList) : 'None');
$lines[] = '';

// Overview
$lines[] = '**Overview:** ' . ($report['overview'] ?: 'N/A');
$lines[] = '';

// Airport Conditions
$lines[] = '**Airport Conditions:**';
$lines[] = $report['airport_conditions'] ?: 'N/A';
$lines[] = '';

// Weather
$lines[] = '**Prevailing Weather Conditions: ' . ($report['weather_category'] ?: 'N/A') . '**';
if ($report['weather_summary']) {
    $lines[] = $report['weather_summary'];
}
$lines[] = '';

// Special Events
if ($report['special_events']) {
    $lines[] = '**Special Events:** ' . $report['special_events'];
    $lines[] = '';
}

// TMIs
$tmiList = $report['tmi_list'] ? json_decode($report['tmi_list'], true) : [];
$lines[] = '**Traffic Management Initiatives:**';
if (is_array($tmiList) && count($tmiList) > 0) {
    foreach ($tmiList as $tmi) {
        if (is_array($tmi)) {
            $tmiLine = '- ' . ($tmi['type'] ?? 'TMI') . ' | ' . ($tmi['element'] ?? '');
            if (!empty($tmi['start_utc']) || !empty($tmi['end_utc'])) {
                $tmiLine .= ' | ' . ($tmi['start_utc'] ?? '?') . '-' . ($tmi['end_utc'] ?? '?');
            }
            $lines[] = $tmiLine;
        } else {
            $lines[] = '- ' . $tmi;
        }
    }
} else {
    $lines[] = 'None';
}
$lines[] = '';
$lines[] = 'Were TMIs complied with? ' . formatYN($report['tmi_complied']);
if ($report['tmi_complied_details']) $lines[] = $report['tmi_complied_details'];
$lines[] = 'Were TMIs effective? ' . formatYN($report['tmi_effective']);
if ($report['tmi_effective_details']) $lines[] = $report['tmi_effective_details'];
$lines[] = 'Were TMIs initiated in a timely manner? ' . formatYN($report['tmi_timely']);
if ($report['tmi_timely_details']) $lines[] = $report['tmi_timely_details'];
$lines[] = '';

// Equipment
$lines[] = '**Equipment:** ' . ($report['equipment'] ?: 'N/A');
$lines[] = '';

// Personnel
$lines[] = '**Personnel:** Was the operational area properly staffed? ' . formatYN($report['personnel_adequate']);
if ($report['personnel_details']) {
    $lines[] = $report['personnel_details'];
}
$lines[] = '';

// Operational Plan
$lines[] = '**Operational Plan:** ' . ($report['operational_plan_link'] ?: 'N/A');
$lines[] = '';

// Findings
$lines[] = '**Findings:**';
$lines[] = $report['findings'] ?: 'N/A';
$lines[] = '';

// Recommendations
$lines[] = '**Recommendations & Conclusions:**';
$lines[] = $report['recommendations'] ?: 'N/A';
$lines[] = '';

$lines[] = "**END {$eventName} TMR**";

$message = implode("\n", $lines);

echo json_encode([
    'success' => true,
    'message' => $message,
    'char_count' => mb_strlen($message),
    'plan_name' => $plan['event_name'],
]);

function formatYN($val) {
    if ($val === null || $val === '') return 'N/A';
    return $val ? 'Y' : 'N';
}
