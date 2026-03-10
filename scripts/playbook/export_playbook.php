<?php
/**
 * Playbook Periodic Backup Export
 *
 * Exports ALL playbook plays, routes, and route groups
 * from MySQL into consolidated backup files for database rebuild.
 *
 * Output files (in backups/playbook/):
 *   playbook_all.json     — Complete DB dump (all columns, nested routes + groups)
 *   playbook_all.txt      — Human-readable, organized by Source > Category > Play
 *   export_meta.json      — Last export timestamp + per-source stats
 *
 * Modes:
 *   CLI:  php export_playbook.php --cli [--force]
 *   Web:  ?run=1 [&force=1]
 *
 * Change detection: skips export if no plays/routes changed since last run.
 * Use --force or &force=1 to bypass change detection.
 */

// Determine execution mode
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if (!isset($_GET['run'])) {
        http_response_code(400);
        echo "Pass ?run=1 to execute.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$force = $isCli
    ? in_array('--force', $argv ?? [])
    : isset($_GET['force']);

// Connection setup — MySQL only, no Azure SQL needed
define('PERTI_MYSQL_ONLY', true);
$loadBase = $isCli
    ? __DIR__ . '/../../load/'
    : dirname(__DIR__, 2) . '/load/';

include_once($loadBase . 'config.php');
include_once($loadBase . 'input.php');
include_once($loadBase . 'connect.php');

// Output directory
$outputDir = $isCli
    ? __DIR__ . '/../../backups/playbook'
    : dirname(__DIR__, 2) . '/backups/playbook';

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$metaPath = $outputDir . '/export_meta.json';

// ============================================================================
// Progress output
// ============================================================================

function out($msg) {
    echo $msg . "\n";
    if (php_sapi_name() !== 'cli') flush();
}

// ============================================================================
// Change detection
// ============================================================================

function loadExportMeta($metaPath) {
    if (!file_exists($metaPath)) return null;
    $data = json_decode(file_get_contents($metaPath), true);
    return $data['exported_utc'] ?? null;
}

function hasChanges($pdo, $lastExportUtc) {
    if ($lastExportUtc === null) return true;

    $stmt = $pdo->query("SELECT GREATEST(
        (SELECT COALESCE(MAX(updated_at), '2000-01-01') FROM playbook_plays),
        (SELECT COALESCE(MAX(created_at), '2000-01-01') FROM playbook_routes)
    ) AS latest_change");
    $latest = $stmt->fetchColumn();

    return strtotime($latest) > strtotime($lastExportUtc);
}

// ============================================================================
// Data fetching (SELECT * for future-proofing)
// ============================================================================

function fetchAllPlays($pdo) {
    $stmt = $pdo->query("SELECT * FROM playbook_plays ORDER BY source, category, play_name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchRoutes($pdo, $playId) {
    $stmt = $pdo->prepare("SELECT * FROM playbook_routes WHERE play_id = ? ORDER BY sort_order, route_id");
    $stmt->execute([$playId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchRouteGroups($pdo, $playId) {
    $stmt = $pdo->prepare("SELECT * FROM playbook_route_groups WHERE play_id = ? ORDER BY sort_order, group_id");
    $stmt->execute([$playId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================================
// JSON export
// ============================================================================

function exportJson($allData, $summary, $filePath) {
    $output = [
        'export_version' => 1,
        'exported_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'encoding' => 'UTF-8',
        'schema_note' => 'All columns via SELECT * — see database/migrations/playbook/ for definitions',
        'summary' => $summary,
        'plays' => $allData,
    ];

    $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents($filePath, $json);

    return strlen($json);
}

// ============================================================================
// Text export
// ============================================================================

function exportText($allData, $summary, $filePath) {
    $lines = [];
    $now = gmdate('Y-m-d H:i:s');

    $totalPlays = $summary['total_plays'];
    $totalRoutes = $summary['total_routes'];

    $lines[] = "## ======================================================================";
    $lines[] = "## PERTI Playbook Routes (Complete Database Export)";
    $lines[] = "## Exported: {$now} UTC";
    $lines[] = "## Total: " . number_format($totalPlays) . " plays | " . number_format($totalRoutes) . " routes";
    $lines[] = "## ======================================================================";
    $lines[] = "";

    // Group plays by source, then by category
    $bySource = [];
    foreach ($allData as $play) {
        $src = $play['source'] ?? 'UNKNOWN';
        $bySource[$src][] = $play;
    }

    // Preferred source ordering
    $sourceOrder = ['FAA', 'FAA_HISTORICAL', 'DCC', 'CADENA', 'ECFMP', 'CANOC'];
    $orderedSources = [];
    foreach ($sourceOrder as $s) {
        if (isset($bySource[$s])) $orderedSources[$s] = $bySource[$s];
    }
    // Append any unexpected sources
    foreach ($bySource as $s => $plays) {
        if (!isset($orderedSources[$s])) $orderedSources[$s] = $plays;
    }

    $globalRouteNum = 1;

    foreach ($orderedSources as $source => $plays) {
        $srcStats = $summary['by_source'][$source] ?? ['plays' => 0, 'routes' => 0];
        $srcPlayCount = $srcStats['plays'];
        $srcRouteCount = $srcStats['routes'];

        // Source header
        $orgLabel = '';
        $firstOrg = $plays[0]['org_code'] ?? null;
        if ($firstOrg) $orgLabel = " -- Org: {$firstOrg}";

        $lines[] = "## ======================================================================";
        $lines[] = "## SOURCE: {$source} (" . number_format($srcPlayCount) . " plays, " . number_format($srcRouteCount) . " routes){$orgLabel}";
        $lines[] = "## ======================================================================";
        $lines[] = "";

        // Group by category within source
        $byCategory = [];
        foreach ($plays as $play) {
            $cat = $play['category'] ?? 'Uncategorized';
            $byCategory[$cat][] = $play;
        }

        foreach ($byCategory as $category => $catPlays) {
            $lines[] = "## --- Category: {$category} ---";
            $lines[] = "";

            foreach ($catPlays as $play) {
                $playName = $play['play_name'] ?? '';
                $displayName = $play['display_name'] ?? '';
                $routeCount = (int)($play['route_count'] ?? 0);
                $impacted = $play['impacted_area'] ?? '';
                $facilities = $play['facilities_involved'] ?? '';
                $playRemarks = $play['remarks'] ?? '';

                // Play header line
                $header = "## {$playName}";
                if ($displayName && $displayName !== $playName) {
                    $header .= " -- {$displayName}";
                }
                $header .= " ({$routeCount} routes)";
                $lines[] = $header;

                if ($impacted) {
                    $lines[] = "## Impacted: {$impacted}";
                }
                if ($facilities) {
                    $lines[] = "## Facilities: {$facilities}";
                }
                if ($playRemarks) {
                    $lines[] = "## Remarks: {$playRemarks}";
                }

                // Routes
                $routes = $play['routes'] ?? [];
                foreach ($routes as $route) {
                    $rs = $route['route_string'] ?? '';
                    $routeRemarks = $route['remarks'] ?? '';

                    $line = sprintf("%6d", $globalRouteNum) . " " . $rs;
                    if ($routeRemarks) {
                        $line .= "  ## {$routeRemarks}";
                    }
                    $lines[] = $line;
                    $globalRouteNum++;
                }

                $lines[] = "";
            }
        }
    }

    $lines[] = "## ======================================================================";
    $lines[] = "## END -- " . number_format($totalRoutes) . " routes across " . number_format($totalPlays) . " plays (" . count($orderedSources) . " sources)";
    $lines[] = "## ======================================================================";

    $content = implode("\n", $lines) . "\n";
    file_put_contents($filePath, $content);

    return strlen($content);
}

// ============================================================================
// Meta export
// ============================================================================

function saveExportMeta($metaPath, $summary, $jsonSize, $txtSize) {
    $meta = [
        'exported_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'summary' => $summary,
        'file_sizes' => [
            'playbook_all_json' => $jsonSize,
            'playbook_all_txt' => $txtSize,
        ],
    ];

    file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ============================================================================
// Main export function
// ============================================================================

function exportPlaybook($pdo, $outputDir, $metaPath, $force) {
    $startTime = microtime(true);
    out("Playbook export starting...");

    // Change detection
    if (!$force) {
        $lastExport = loadExportMeta($metaPath);
        if ($lastExport !== null && !hasChanges($pdo, $lastExport)) {
            out("No changes since last export ({$lastExport}). Skipping.");
            return;
        }
    } else {
        out("Force mode: skipping change detection.");
    }

    // Fetch all plays
    out("Fetching plays...");
    $plays = fetchAllPlays($pdo);
    $totalPlays = count($plays);
    out("  Found {$totalPlays} plays.");

    // Build per-source stats and attach routes + groups to each play
    $bySourceStats = [];
    $totalRoutes = 0;

    for ($i = 0; $i < count($plays); $i++) {
        $playId = (int)$plays[$i]['play_id'];
        $source = $plays[$i]['source'] ?? 'UNKNOWN';

        $routes = fetchRoutes($pdo, $playId);
        $groups = fetchRouteGroups($pdo, $playId);

        $plays[$i]['routes'] = $routes;
        $plays[$i]['route_groups'] = $groups;

        $routeCount = count($routes);
        $totalRoutes += $routeCount;

        if (!isset($bySourceStats[$source])) {
            $bySourceStats[$source] = ['plays' => 0, 'routes' => 0];
        }
        $bySourceStats[$source]['plays']++;
        $bySourceStats[$source]['routes'] += $routeCount;

        if (($i + 1) % 1000 === 0 || ($i + 1) === $totalPlays) {
            out("  Loaded " . ($i + 1) . " / {$totalPlays} plays ({$totalRoutes} routes so far)...");
        }
    }

    $summary = [
        'total_plays' => $totalPlays,
        'total_routes' => $totalRoutes,
        'by_source' => $bySourceStats,
    ];

    out("Total: {$totalPlays} plays, {$totalRoutes} routes.");

    // Export JSON
    out("Writing playbook_all.json...");
    $jsonSize = exportJson($plays, $summary, $outputDir . '/playbook_all.json');
    out("  JSON: " . number_format($jsonSize) . " bytes");

    // Export text
    out("Writing playbook_all.txt...");
    $txtSize = exportText($plays, $summary, $outputDir . '/playbook_all.txt');
    out("  Text: " . number_format($txtSize) . " bytes");

    // Save meta
    saveExportMeta($metaPath, $summary, $jsonSize, $txtSize);

    $elapsed = round(microtime(true) - $startTime, 1);
    out("Export complete in {$elapsed}s.");

    // Summary
    out("");
    out("Per-source breakdown:");
    foreach ($bySourceStats as $src => $stats) {
        out("  {$src}: {$stats['plays']} plays, {$stats['routes']} routes");
    }
}

// ============================================================================
// Run
// ============================================================================

// $conn_pdo is the MySQL PDO connection from load/connect.php
if (!isset($conn_pdo) || !$conn_pdo) {
    out("ERROR: MySQL PDO connection not available.");
    exit(1);
}

exportPlaybook($conn_pdo, $outputDir, $metaPath, $force);
