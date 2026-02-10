<?php
/**
 * TMR Ops Plan API - Fetch plan goals and initiative timelines
 *
 * GET ?p_id=N — Returns operational goals and initiative timeline data
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

// Operational goals
$stmt = $conn_pdo->prepare("SELECT id, comments FROM p_op_goals WHERE p_id = ? ORDER BY id");
$stmt->execute([$p_id]);
$goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Terminal initiative timelines
$stmt = $conn_pdo->prepare("SELECT id, facility, area, tmi_type, cause, start_datetime, end_datetime, level, notes, is_global, advzy_number FROM p_terminal_init_timeline WHERE p_id = ? ORDER BY start_datetime");
$stmt->execute([$p_id]);
$termInitTimeline = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Enroute initiative timelines
$stmt = $conn_pdo->prepare("SELECT id, facility, area, tmi_type, cause, start_datetime, end_datetime, level, notes, is_global, advzy_number FROM p_enroute_init_timeline WHERE p_id = ? ORDER BY start_datetime");
$stmt->execute([$p_id]);
$enrouteInitTimeline = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'goals' => $goals,
    'initiatives' => [
        'terminal' => $termInitTimeline,
        'enroute' => $enrouteInitTimeline,
    ],
    'goal_count' => count($goals),
    'initiative_count' => count($termInitTimeline) + count($enrouteInitTimeline),
]);
