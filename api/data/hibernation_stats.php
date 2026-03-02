<?php
/**
 * Hibernation Hit Statistics
 *
 * Returns total and unique hit counts for hibernated pages and SWIM API endpoints.
 * Used by the hibernation info page to show demand for paused features.
 *
 * GET /api/data/hibernation_stats.php
 *
 * Response: {
 *   "generated_utc": "2026-03-01 12:00:00",
 *   "totals": { "hits": 123, "unique_ips": 45 },
 *   "by_page": [ { "page": "demand.php", "hits": 50, "unique_ips": 20 }, ... ],
 *   "by_day": [ { "date": "2026-03-01", "hits": 30, "unique_ips": 15 }, ... ]
 * }
 */

include("../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../load/connect.php");

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

try {
    // Overall totals
    $total_stmt = $conn_pdo->query(
        "SELECT COUNT(*) AS hits, COUNT(DISTINCT ip_hash) AS unique_ips FROM hibernation_hits"
    );
    $totals = $total_stmt->fetch(PDO::FETCH_ASSOC);

    // Per-page breakdown
    $page_stmt = $conn_pdo->query(
        "SELECT page, hit_type, COUNT(*) AS hits, COUNT(DISTINCT ip_hash) AS unique_ips
         FROM hibernation_hits
         GROUP BY page, hit_type
         ORDER BY hits DESC"
    );
    $by_page = $page_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Daily trend (last 30 days)
    $day_stmt = $conn_pdo->query(
        "SELECT DATE(hit_utc) AS date, COUNT(*) AS hits, COUNT(DISTINCT ip_hash) AS unique_ips
         FROM hibernation_hits
         WHERE hit_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
         GROUP BY DATE(hit_utc)
         ORDER BY date DESC"
    );
    $by_day = $day_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'generated_utc' => gmdate('Y-m-d H:i:s'),
        'totals' => [
            'hits' => (int)$totals['hits'],
            'unique_ips' => (int)$totals['unique_ips']
        ],
        'by_page' => $by_page,
        'by_day' => $by_day
    ], JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load stats']);
}
