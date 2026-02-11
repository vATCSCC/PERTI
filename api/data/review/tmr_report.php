<?php
/**
 * TMR Report API - CRUD for Traffic Management Review reports
 *
 * GET  ?p_id=N  — Load saved report (or empty defaults from plan data)
 * POST          — Save/update report (upsert on p_id)
 *
 * Database: perti_site (MySQL) via $conn_pdo
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

require_once __DIR__ . '/../../../load/config.php';
require_once __DIR__ . '/../../../load/connect.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $p_id = get_int('p_id');
    if (!$p_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing p_id']);
        exit;
    }

    // Try to load existing report
    $stmt = $conn_pdo->prepare("SELECT * FROM r_tmr_reports WHERE p_id = ?");
    $stmt->execute([$p_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($report) {
        // Decode JSON fields
        foreach (['tmr_triggers', 'tmi_list', 'staffing_assessment', 'goals_assessment', 'demand_snapshots'] as $jsonField) {
            if (!empty($report[$jsonField])) {
                $report[$jsonField] = json_decode($report[$jsonField], true);
            }
        }
        // Convert tinyint to bool for JS
        foreach (['airport_config_correct', 'tmi_complied', 'tmi_effective', 'tmi_timely', 'personnel_adequate'] as $boolField) {
            if ($report[$boolField] !== null) {
                $report[$boolField] = (bool)$report[$boolField];
            }
        }
        echo json_encode(['success' => true, 'report' => $report, 'is_new' => false]);
    } else {
        // Load plan defaults for a new report
        $defaults = getDefaults($conn_pdo, $p_id);
        echo json_encode(['success' => true, 'report' => $defaults, 'is_new' => true]);
    }

} elseif ($method === 'POST') {
    // Require authentication
    $cid = session_get('VATSIM_CID', '');
    if (!$cid) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    // Read POST data (supports both form and JSON)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $data = $_POST;
    }

    $p_id = intval($data['p_id'] ?? 0);
    if (!$p_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing p_id']);
        exit;
    }

    // Encode JSON fields
    $tmrTriggers = isset($data['tmr_triggers']) ? json_encode($data['tmr_triggers']) : null;
    $tmiList = isset($data['tmi_list']) ? json_encode($data['tmi_list']) : null;

    // Encode additional JSON fields
    $staffingAssessment = isset($data['staffing_assessment']) ? json_encode($data['staffing_assessment']) : null;
    $goalsAssessment = isset($data['goals_assessment']) ? json_encode($data['goals_assessment']) : null;
    $demandSnapshots = isset($data['demand_snapshots']) ? json_encode($data['demand_snapshots']) : null;

    // Build upsert (INSERT ... ON DUPLICATE KEY UPDATE for MySQL)
    $sql = "INSERT INTO r_tmr_reports (
                p_id, host_artcc, featured_facilities,
                tmr_triggers, tmr_trigger_other_text, overview,
                airport_conditions, airport_config_correct, demand_snapshots,
                weather_category, weather_summary, special_events,
                tmi_list, tmi_source, tmi_complied, tmi_complied_details,
                tmi_effective, tmi_effective_details, tmi_timely, tmi_timely_details,
                equipment, personnel_adequate, personnel_details, staffing_assessment,
                operational_plan_link, goals_assessment, findings, recommendations,
                status, created_by, updated_by
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?
            ) ON DUPLICATE KEY UPDATE
                host_artcc = VALUES(host_artcc),
                featured_facilities = VALUES(featured_facilities),
                tmr_triggers = VALUES(tmr_triggers),
                tmr_trigger_other_text = VALUES(tmr_trigger_other_text),
                overview = VALUES(overview),
                airport_conditions = VALUES(airport_conditions),
                airport_config_correct = VALUES(airport_config_correct),
                demand_snapshots = VALUES(demand_snapshots),
                weather_category = VALUES(weather_category),
                weather_summary = VALUES(weather_summary),
                special_events = VALUES(special_events),
                tmi_list = VALUES(tmi_list),
                tmi_source = VALUES(tmi_source),
                tmi_complied = VALUES(tmi_complied),
                tmi_complied_details = VALUES(tmi_complied_details),
                tmi_effective = VALUES(tmi_effective),
                tmi_effective_details = VALUES(tmi_effective_details),
                tmi_timely = VALUES(tmi_timely),
                tmi_timely_details = VALUES(tmi_timely_details),
                equipment = VALUES(equipment),
                personnel_adequate = VALUES(personnel_adequate),
                personnel_details = VALUES(personnel_details),
                staffing_assessment = VALUES(staffing_assessment),
                operational_plan_link = VALUES(operational_plan_link),
                goals_assessment = VALUES(goals_assessment),
                findings = VALUES(findings),
                recommendations = VALUES(recommendations),
                status = VALUES(status),
                updated_by = VALUES(updated_by)";

    try {
        $stmt = $conn_pdo->prepare($sql);
        $stmt->execute([
            $p_id,
            $data['host_artcc'] ?? null,
            $data['featured_facilities'] ?? null,
            $tmrTriggers,
            $data['tmr_trigger_other_text'] ?? null,
            $data['overview'] ?? null,
            $data['airport_conditions'] ?? null,
            nullableBool($data['airport_config_correct'] ?? null),
            $demandSnapshots,
            $data['weather_category'] ?? null,
            $data['weather_summary'] ?? null,
            $data['special_events'] ?? null,
            $tmiList,
            $data['tmi_source'] ?? 'manual',
            nullableBool($data['tmi_complied'] ?? null),
            $data['tmi_complied_details'] ?? null,
            nullableBool($data['tmi_effective'] ?? null),
            $data['tmi_effective_details'] ?? null,
            nullableBool($data['tmi_timely'] ?? null),
            $data['tmi_timely_details'] ?? null,
            $data['equipment'] ?? null,
            nullableBool($data['personnel_adequate'] ?? null),
            $data['personnel_details'] ?? null,
            $staffingAssessment,
            $data['operational_plan_link'] ?? null,
            $goalsAssessment,
            $data['findings'] ?? null,
            $data['recommendations'] ?? null,
            $data['status'] ?? 'draft',
            $cid,
            $cid,
        ]);

        echo json_encode(['success' => true, 'message' => 'Report saved']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

/**
 * Convert truthy/falsy/null to nullable tinyint for MySQL
 */
function nullableBool($val) {
    if ($val === null || $val === '' || $val === 'null') return null;
    if ($val === 'true' || $val === true || $val === '1' || $val === 1) return 1;
    if ($val === 'false' || $val === false || $val === '0' || $val === 0) return 0;
    return null;
}

/**
 * Build default report values from plan metadata
 */
function getDefaults($pdo, $p_id) {
    $defaults = [
        'p_id' => $p_id,
        'host_artcc' => null,
        'featured_facilities' => null,
        'tmr_triggers' => [],
        'tmr_trigger_other_text' => null,
        'overview' => null,
        'airport_conditions' => null,
        'airport_config_correct' => null,
        'demand_snapshots' => null,
        'weather_category' => null,
        'weather_summary' => null,
        'special_events' => null,
        'tmi_list' => [],
        'tmi_source' => 'manual',
        'tmi_complied' => null,
        'tmi_complied_details' => null,
        'tmi_effective' => null,
        'tmi_effective_details' => null,
        'tmi_timely' => null,
        'tmi_timely_details' => null,
        'equipment' => null,
        'personnel_adequate' => null,
        'personnel_details' => null,
        'staffing_assessment' => null,
        'operational_plan_link' => null,
        'goals_assessment' => null,
        'findings' => null,
        'recommendations' => null,
        'status' => 'draft',
    ];

    // Pull plan metadata
    $stmt = $pdo->prepare("SELECT * FROM p_plans WHERE id = ?");
    $stmt->execute([$p_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($plan) {
        // Auto-set operational plan link
        $defaults['operational_plan_link'] = 'https://perti.vatcscc.org/plan?' . $p_id;
    }

    // Pull airport configs for this plan
    $stmt = $pdo->prepare("SELECT airport, weather, aar, adr, arrive, depart FROM p_configs WHERE p_id = ?");
    $stmt->execute([$p_id]);
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($configs) {
        // Build airport conditions default text
        $conditions = [];
        foreach ($configs as $cfg) {
            $weather_map = [1 => 'VMC', 2 => 'LVMC', 3 => 'IMC', 4 => 'LIMC'];
            $wx = $weather_map[$cfg['weather']] ?? 'UNK';
            $conditions[] = sprintf(
                '%s | %s | %s | %s/%s',
                $cfg['airport'],
                $cfg['arrive'] ?? 'N/A',
                $cfg['depart'] ?? 'N/A',
                $cfg['aar'] ?? '?',
                $cfg['adr'] ?? '?'
            );
        }
        $defaults['airport_conditions'] = implode("\n", $conditions);
    }

    return $defaults;
}
