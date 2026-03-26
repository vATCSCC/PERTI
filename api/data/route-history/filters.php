<?php
/**
 * Historical Route Filter Options API
 *
 * GET /api/data/route-history/filters.php?type=aircraft&q=B73
 * Returns autocomplete suggestions for filter dropdowns.
 */

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");

header('Content-Type: application/json; charset=utf-8');

$type = get_input('type') ?? '';
$q    = trim(get_input('q') ?? '');
$limit = min(50, max(1, (int)(get_input('limit') ?? 20)));

$results = [];

switch ($type) {
    case 'aircraft':
        $stmt = $conn_pdo->prepare("
            SELECT dat.icao_code as id,
                   CONCAT(dat.icao_code, ' - ', COALESCE(dat.manufacturer, ''), ' ', COALESCE(dat.model, '')) as label
            FROM dim_aircraft_type dat
            WHERE dat.icao_code LIKE ? OR dat.manufacturer LIKE ? OR dat.model LIKE ?
            ORDER BY dat.icao_code
            LIMIT ?
        ");
        $like = "%$q%";
        $stmt->execute([$like, $like, $like, $limit]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'operator':
        $stmt = $conn_pdo->prepare("
            SELECT dop.airline_icao as id,
                   CONCAT(dop.airline_icao, ' - ', COALESCE(dop.airline_name, '')) as label
            FROM dim_operator dop
            WHERE dop.airline_icao LIKE ? OR dop.airline_name LIKE ?
            ORDER BY dop.airline_icao
            LIMIT ?
        ");
        $like = "%$q%";
        $stmt->execute([$like, $like, $limit]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'airport':
        $stmt = $conn_pdo->prepare("
            SELECT origin_icao as id, origin_icao as label, COUNT(*) as cnt
            FROM route_history_facts
            WHERE origin_icao LIKE ?
            GROUP BY origin_icao
            UNION
            SELECT dest_icao as id, dest_icao as label, COUNT(*) as cnt
            FROM route_history_facts
            WHERE dest_icao LIKE ?
            GROUP BY dest_icao
            ORDER BY cnt DESC
            LIMIT ?
        ");
        $like = strtoupper($q) . '%';
        $stmt->execute([$like, $like, $limit]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'tracon':
        $stmt = $conn_pdo->prepare("
            SELECT DISTINCT val as id, val as label FROM (
                SELECT origin_tracon as val FROM route_history_facts WHERE origin_tracon LIKE ?
                UNION
                SELECT dest_tracon as val FROM route_history_facts WHERE dest_tracon LIKE ?
            ) t WHERE val IS NOT NULL
            ORDER BY val
            LIMIT ?
        ");
        $like = strtoupper($q) . '%';
        $stmt->execute([$like, $like, $limit]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'artcc':
        $stmt = $conn_pdo->prepare("
            SELECT DISTINCT val as id, val as label FROM (
                SELECT origin_artcc as val FROM route_history_facts WHERE origin_artcc LIKE ?
                UNION
                SELECT dest_artcc as val FROM route_history_facts WHERE dest_artcc LIKE ?
            ) t WHERE val IS NOT NULL
            ORDER BY val
            LIMIT ?
        ");
        $like = strtoupper($q) . '%';
        $stmt->execute([$like, $like, $limit]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid type']);
        exit;
}

echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
