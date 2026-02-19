<?php
/**
 * PERTI Unified Scheduler Cron Entry Point
 *
 * Lightweight endpoint for cron to call frequently (e.g., every minute).
 * Only executes the full scheduler if it's actually time to run.
 *
 * Handles all scheduled resources: Splits, Routes, Initiatives
 *
 * Usage:
 *   Cron: * * * * * curl -s https://perti.vatcscc.org/api/cron.php > /dev/null
 *   Or Windows Task Scheduler running every minute
 */

header('Content-Type: application/json; charset=utf-8');

// Allow targeted triggering of TMI proposal reaction processing.
// Used by the coordination UI so reaction sync is not blocked by scheduler timing.
$requestedType = strtolower(trim((string)($_GET['type'] ?? '')));
if ($requestedType === 'tmi') {
    $tmiProposalResult = runTmiProposalProcessor();
    echo json_encode([
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'executed' => true,
        'type' => 'tmi',
        'tmi_proposals' => $tmiProposalResult
    ]);
    exit;
}

require_once __DIR__ . '/splits/connect_adl.php';

if (!$conn_adl) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// Quick check: is it time to run?
$sql = "SELECT
            CASE WHEN next_run_at <= GETUTCDATE() THEN 1 ELSE 0 END AS should_run,
            DATEDIFF(SECOND, GETUTCDATE(), next_run_at) AS seconds_until
        FROM scheduler_state
        WHERE id = 1";

$stmt = sqlsrv_query($conn_adl, $sql);

if ($stmt === false) {
    // Table might not exist yet - run scheduler to initialize
    include __DIR__ . '/scheduler.php';
    exit;
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if (!$row) {
    // No state row - run scheduler to initialize
    include __DIR__ . '/scheduler.php';
    exit;
}

$shouldRun = (int)$row['should_run'] === 1;
$secondsUntil = (int)$row['seconds_until'];

if ($shouldRun) {
    // Time to run - execute the unified scheduler
    include __DIR__ . '/scheduler.php';
} else {
    // Scheduler is idle, but coordination reactions should still be processed each minute.
    $tmiProposalResult = runTmiProposalProcessor();

    // Not time yet - return minimal response
    echo json_encode([
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'executed' => false,
        'seconds_until_next' => max(0, $secondsUntil),
        'tmi_proposals' => $tmiProposalResult
    ]);
}

/**
 * Run TMI proposal reaction processing out-of-band from scheduler tier timing.
 *
 * @return array
 */
function runTmiProposalProcessor() {
    $tmiCronPath = __DIR__ . '/../cron/process_tmi_proposals.php';
    if (!file_exists($tmiCronPath)) {
        return ['executed' => false, 'error' => 'processor_missing'];
    }

    $originalCronKey = $_GET['cron_key'] ?? null;
    $_GET['cron_key'] = getenv('CRON_KEY') ?: 'tmi_proposal_cron_2026';

    try {
        ob_start();
        include $tmiCronPath;
        $output = trim((string)ob_get_clean());
    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        if ($originalCronKey === null) {
            unset($_GET['cron_key']);
        } else {
            $_GET['cron_key'] = $originalCronKey;
        }

        return ['executed' => false, 'error' => $e->getMessage()];
    }

    if ($originalCronKey === null) {
        unset($_GET['cron_key']);
    } else {
        $_GET['cron_key'] = $originalCronKey;
    }

    $result = [
        'executed' => true,
        'processed' => 0,
        'approved' => 0,
        'denied' => 0,
        'expired' => 0,
    ];

    if (preg_match('/Found (\d+) pending/i', $output, $m)) {
        $result['processed'] = (int)$m[1];
    }
    if (preg_match('/Marked (\d+) proposal\(s\) as EXPIRED/i', $output, $m)) {
        $result['expired'] = (int)$m[1];
    }

    $result['approved'] = substr_count($output, 'set to APPROVED');
    $result['denied'] = substr_count($output, 'set to DENIED');

    return $result;
}
