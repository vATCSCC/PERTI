<?php
/**
 * PERTI Unified Scheduler (Event-Driven with Tiers)
 *
 * Handles automatic status transitions for multiple resource types:
 * - Splits: Activate scheduled configs, deactivate expired ones
 * - Public Routes: Activate when valid_start_utc arrives, expire when valid_end_utc passes
 * - Initiatives (FEAs, etc.): Transition levels based on start/end times
 *
 * Event-Driven Design:
 * - Only runs when next_run_at time has arrived OR triggered by ?force=1
 * - Stores next planned run time in scheduler_state table
 * - Resource APIs trigger this with ?force=1 when items are created/updated
 * - Cron calls cron.php which only invokes scheduler if it's time
 *
 * Tiers (determines next_run_at):
 *   Tier 0: Run in 1 min  - Transition imminent (within 5 minutes)
 *   Tier 1: Run in 5 min  - Transition soon (within 30 minutes)
 *   Tier 2: Run in 15 min - Transition upcoming (within 6 hours)
 *   Tier 3: Run in 60 min - No imminent transitions (idle)
 *
 * Usage:
 *   GET /api/scheduler.php              - Run if it's time
 *   GET /api/scheduler.php?force=1      - Force run now
 *   GET /api/scheduler.php?status=1     - Just return current state
 *   GET /api/scheduler.php?type=splits  - Only process splits (optional filter)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/splits/connect_adl.php';

if (!$conn_adl) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed', 'details' => $conn_adl_error]);
    exit;
}

// Tier definitions (in minutes)
$tiers = [
    0 => ['threshold_minutes' => 5,   'interval_minutes' => 1,  'label' => 'imminent'],
    1 => ['threshold_minutes' => 30,  'interval_minutes' => 5,  'label' => 'soon'],
    2 => ['threshold_minutes' => 360, 'interval_minutes' => 15, 'label' => 'upcoming'],
    3 => ['threshold_minutes' => null,'interval_minutes' => 60, 'label' => 'idle']
];

$forceRun = isset($_GET['force']) && $_GET['force'] == '1';
$statusOnly = isset($_GET['status']) && $_GET['status'] == '1';
$typeFilter = $_GET['type'] ?? null; // Optional: 'splits', 'routes', 'initiatives'

// ============================================================================
// Get current scheduler state
// ============================================================================
$state = null;
$sql = "SELECT next_run_at, last_run_at, last_tier, last_activated, last_deactivated FROM scheduler_state WHERE id = 1";
$stmt = sqlsrv_query($conn_adl, $sql);

if ($stmt !== false) {
    $state = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

// Initialize state if it doesn't exist
if (!$state) {
    $sql = "INSERT INTO scheduler_state (id, next_run_at, last_tier) VALUES (1, GETUTCDATE(), 3)";
    sqlsrv_query($conn_adl, $sql);
    $state = [
        'next_run_at' => new DateTime('now', new DateTimeZone('UTC')),
        'last_run_at' => null,
        'last_tier' => 3,
        'last_activated' => 0,
        'last_deactivated' => 0
    ];
}

// Convert DateTime objects
$nextRunAt = $state['next_run_at'];
if ($nextRunAt instanceof DateTime) {
    $nextRunAtStr = $nextRunAt->format('Y-m-d H:i:s');
    $nextRunAtTs = $nextRunAt->getTimestamp();
} else {
    $nextRunAtStr = $nextRunAt;
    $nextRunAtTs = strtotime($nextRunAt);
}

$lastRunAt = $state['last_run_at'];
$lastRunAtStr = ($lastRunAt instanceof DateTime) ? $lastRunAt->format('Y-m-d H:i:s') : $lastRunAt;

$nowTs = time();
$shouldRun = $forceRun || ($nowTs >= $nextRunAtTs);

// Status-only mode
if ($statusOnly) {
    echo json_encode([
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'state' => [
            'next_run_at' => $nextRunAtStr,
            'last_run_at' => $lastRunAtStr,
            'last_tier' => (int)$state['last_tier'],
            'seconds_until_next' => max(0, $nextRunAtTs - $nowTs),
            'should_run' => $shouldRun
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// Check if it's time to run
if (!$shouldRun) {
    echo json_encode([
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'skipped' => true,
        'reason' => 'Not yet time to run',
        'next_run_at' => $nextRunAtStr,
        'seconds_until_next' => max(0, $nextRunAtTs - $nowTs)
    ], JSON_PRETTY_PRINT);
    exit;
}

// ============================================================================
// EXECUTE SCHEDULER
// ============================================================================

$results = [
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'triggered_by' => $forceRun ? 'force' : 'schedule',
    'splits' => ['activated' => 0, 'deactivated' => 0, 'items' => []],
    'routes' => ['activated' => 0, 'expired' => 0, 'items' => []],
    'totals' => ['activated' => 0, 'deactivated' => 0]
];

$upcomingTransitions = []; // Collect all upcoming transitions for tier calculation

// ============================================================================
// 1. SPLITS - Activate scheduled, deactivate expired
// ============================================================================
if (!$typeFilter || $typeFilter === 'splits') {
    // Activate scheduled splits
    $sql = "UPDATE splits_configs
            SET status = 'active', updated_at = GETUTCDATE()
            OUTPUT INSERTED.id, INSERTED.artcc, INSERTED.config_name, 'activation' AS action
            WHERE status = 'scheduled'
              AND start_time_utc IS NOT NULL
              AND start_time_utc <= GETUTCDATE()";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results['splits']['activated']++;
            $results['splits']['items'][] = ['id' => $row['id'], 'name' => $row['config_name'], 'artcc' => $row['artcc'], 'action' => 'activated'];
        }
        sqlsrv_free_stmt($stmt);
    }

    // Deactivate expired splits
    $sql = "UPDATE splits_configs
            SET status = 'inactive', updated_at = GETUTCDATE()
            OUTPUT INSERTED.id, INSERTED.artcc, INSERTED.config_name, 'deactivation' AS action
            WHERE status = 'active'
              AND end_time_utc IS NOT NULL
              AND end_time_utc <= GETUTCDATE()";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results['splits']['deactivated']++;
            $results['splits']['items'][] = ['id' => $row['id'], 'name' => $row['config_name'], 'artcc' => $row['artcc'], 'action' => 'deactivated'];
        }
        sqlsrv_free_stmt($stmt);
    }

    // Get upcoming splits transitions
    $sql = "SELECT 'splits' AS resource_type, 'activation' AS transition_type,
                   DATEDIFF(MINUTE, GETUTCDATE(), start_time_utc) AS minutes_until
            FROM splits_configs
            WHERE status = 'scheduled' AND start_time_utc > GETUTCDATE()
            UNION ALL
            SELECT 'splits', 'deactivation',
                   DATEDIFF(MINUTE, GETUTCDATE(), end_time_utc)
            FROM splits_configs
            WHERE status = 'active' AND end_time_utc IS NOT NULL AND end_time_utc > GETUTCDATE()";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $upcomingTransitions[] = (int)$row['minutes_until'];
        }
        sqlsrv_free_stmt($stmt);
    }
}

// ============================================================================
// 2. PUBLIC ROUTES - Activate when valid, expire when ended
// ============================================================================
if (!$typeFilter || $typeFilter === 'routes') {
    // Activate routes whose valid_start_utc has arrived (status 0 -> 1)
    // Note: Routes may be created with status=0 (inactive) if scheduled for future
    $sql = "UPDATE public_routes
            SET status = 1, updated_utc = GETUTCDATE()
            OUTPUT INSERTED.id, INSERTED.name, 'activation' AS action
            WHERE status = 0
              AND valid_start_utc IS NOT NULL
              AND valid_start_utc <= GETUTCDATE()
              AND valid_end_utc > GETUTCDATE()";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results['routes']['activated']++;
            $results['routes']['items'][] = ['id' => $row['id'], 'name' => $row['name'], 'action' => 'activated'];
        }
        sqlsrv_free_stmt($stmt);
    }

    // Expire routes whose valid_end_utc has passed (status 1 -> 2)
    $sql = "UPDATE public_routes
            SET status = 2, updated_utc = GETUTCDATE()
            OUTPUT INSERTED.id, INSERTED.name, 'expiration' AS action
            WHERE status = 1
              AND valid_end_utc IS NOT NULL
              AND valid_end_utc <= GETUTCDATE()";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results['routes']['expired']++;
            $results['routes']['items'][] = ['id' => $row['id'], 'name' => $row['name'], 'action' => 'expired'];
        }
        sqlsrv_free_stmt($stmt);
    }

    // Get upcoming route transitions
    $sql = "SELECT 'routes' AS resource_type, 'activation' AS transition_type,
                   DATEDIFF(MINUTE, GETUTCDATE(), valid_start_utc) AS minutes_until
            FROM public_routes
            WHERE status = 0 AND valid_start_utc > GETUTCDATE()
            UNION ALL
            SELECT 'routes', 'expiration',
                   DATEDIFF(MINUTE, GETUTCDATE(), valid_end_utc)
            FROM public_routes
            WHERE status = 1 AND valid_end_utc IS NOT NULL AND valid_end_utc > GETUTCDATE()";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $upcomingTransitions[] = (int)$row['minutes_until'];
        }
        sqlsrv_free_stmt($stmt);
    }
}

// Calculate totals
$results['totals']['activated'] = $results['splits']['activated'] + $results['routes']['activated'];
$results['totals']['deactivated'] = $results['splits']['deactivated'] + $results['routes']['expired'];

// ============================================================================
// 3. Determine next tier based on all upcoming transitions
// ============================================================================
$nextTransitionMinutes = null;
if (!empty($upcomingTransitions)) {
    $nextTransitionMinutes = min(array_filter($upcomingTransitions, fn($m) => $m >= 0));
}

$recommendedTier = 3;
if ($nextTransitionMinutes !== null) {
    if ($nextTransitionMinutes <= $tiers[0]['threshold_minutes']) {
        $recommendedTier = 0;
    } elseif ($nextTransitionMinutes <= $tiers[1]['threshold_minutes']) {
        $recommendedTier = 1;
    } elseif ($nextTransitionMinutes <= $tiers[2]['threshold_minutes']) {
        $recommendedTier = 2;
    }
}

$intervalMinutes = $tiers[$recommendedTier]['interval_minutes'];

// ============================================================================
// 4. Update scheduler state
// ============================================================================
$sql = "UPDATE scheduler_state
        SET next_run_at = DATEADD(MINUTE, ?, GETUTCDATE()),
            last_run_at = GETUTCDATE(),
            last_tier = ?,
            last_activated = ?,
            last_deactivated = ?
        WHERE id = 1";

$stmt = sqlsrv_query($conn_adl, $sql, [
    $intervalMinutes,
    $recommendedTier,
    $results['totals']['activated'],
    $results['totals']['deactivated']
]);
if ($stmt !== false) sqlsrv_free_stmt($stmt);

// Build response
$results['tier'] = [
    'current' => $recommendedTier,
    'label' => $tiers[$recommendedTier]['label'],
    'next_run_minutes' => $intervalMinutes,
    'next_run_seconds' => $intervalMinutes * 60
];

$results['next_transition_minutes'] = $nextTransitionMinutes;

echo json_encode($results, JSON_PRETTY_PRINT);
