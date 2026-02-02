<?php
/**
 * PERTI Master Indexer Runner
 *
 * Runs both codebase and database indexers to generate comprehensive documentation
 * for coding agents. Designed to run every 12 hours via cron.
 *
 * Usage:
 *   CLI:  php scripts/indexer/run_indexer.php
 *   HTTP: /scripts/indexer/run_indexer.php?cron_key=YOUR_KEY
 *
 * Output files saved to /data/indexes/:
 *   - codebase_index.json       Full codebase index
 *   - codebase_index.md         Codebase documentation
 *   - database_schema.json      Full database schema
 *   - database_schema.md        Database documentation
 *   - database_quick_reference.md  Quick reference
 *   - agent_context.md          Combined summary for agents
 *   - index_manifest.json       Index metadata and timestamps
 *
 * @package PERTI
 * @subpackage Indexer
 * @version 1.0.0
 * @date 2026-02-01
 */

// ============================================================================
// ACCESS CONTROL
// ============================================================================

// Allow CLI, scheduler include, or valid cron key
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key']) && !defined('SCHEDULER_CONTEXT')) {
    http_response_code(403);
    echo 'CLI or cron key required';
    exit;
}

// Validate cron key for HTTP access
$expectedKey = getenv('INDEXER_CRON_KEY') ?: 'perti_indexer_2026';
if (isset($_GET['cron_key']) && $_GET['cron_key'] !== $expectedKey) {
    http_response_code(403);
    echo 'Invalid cron key';
    exit;
}

// ============================================================================
// CONFIGURATION
// ============================================================================

$startTime = microtime(true);
$rootPath = dirname(__DIR__, 2);
$outputPath = $rootPath . '/data/indexes';
$configPath = $rootPath . '/load/config.php';

// Set execution time limit (10 minutes for thorough indexing)
set_time_limit(600);
ini_set('memory_limit', '512M');

// Ensure output directory exists
if (!is_dir($outputPath)) {
    mkdir($outputPath, 0755, true);
}

// ============================================================================
// LOGGING
// ============================================================================

$logFile = $outputPath . '/indexer.log';

function logMessage(string $message, bool $echo = true): void {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    if ($echo) {
        echo $line;
    }
}

// Rotate log if > 1MB
if (file_exists($logFile) && filesize($logFile) > 1048576) {
    rename($logFile, $logFile . '.old');
}

logMessage("========================================");
logMessage("PERTI Index Generator - Starting");
logMessage("========================================");

// ============================================================================
// RUN INDEXERS
// ============================================================================

require_once __DIR__ . '/codebase_indexer.php';
require_once __DIR__ . '/database_indexer.php';

$results = [
    'codebase' => null,
    'database' => null,
    'errors' => [],
    'timing' => []
];

// Run Codebase Indexer
logMessage("");
logMessage("PHASE 1: Codebase Indexing");
logMessage("--------------------------");
$codebaseStart = microtime(true);

try {
    $codebaseIndexer = new CodebaseIndexer($rootPath);
    $codebaseResult = $codebaseIndexer->run();
    $results['codebase'] = $codebaseResult;

    // Save codebase outputs
    file_put_contents(
        $outputPath . '/codebase_index.json',
        json_encode($codebaseResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    file_put_contents(
        $outputPath . '/codebase_index.md',
        $codebaseIndexer->generateMarkdown()
    );

    logMessage("Codebase index saved successfully");
} catch (Exception $e) {
    $results['errors'][] = "Codebase indexer: " . $e->getMessage();
    logMessage("ERROR: Codebase indexer failed - " . $e->getMessage());
}

$results['timing']['codebase_seconds'] = round(microtime(true) - $codebaseStart, 2);

// Run Database Indexer
logMessage("");
logMessage("PHASE 2: Database Schema Indexing");
logMessage("----------------------------------");
$databaseStart = microtime(true);

try {
    if (file_exists($configPath)) {
        $databaseIndexer = new DatabaseIndexer($configPath);
        $databaseResult = $databaseIndexer->run();
        $results['database'] = $databaseResult;

        // Save database outputs
        file_put_contents(
            $outputPath . '/database_schema.json',
            json_encode($databaseResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            $outputPath . '/database_schema.md',
            $databaseIndexer->generateMarkdown()
        );
        file_put_contents(
            $outputPath . '/database_quick_reference.md',
            $databaseIndexer->generateQuickReference()
        );

        logMessage("Database schema saved successfully");
    } else {
        $results['errors'][] = "Config file not found: {$configPath}";
        logMessage("SKIP: Config file not found at {$configPath}");
    }
} catch (Exception $e) {
    $results['errors'][] = "Database indexer: " . $e->getMessage();
    logMessage("ERROR: Database indexer failed - " . $e->getMessage());
}

$results['timing']['database_seconds'] = round(microtime(true) - $databaseStart, 2);

// ============================================================================
// GENERATE COMBINED AGENT CONTEXT
// ============================================================================

logMessage("");
logMessage("PHASE 3: Generating Agent Context");
logMessage("----------------------------------");

$agentContext = generateAgentContext($results, $rootPath);
file_put_contents($outputPath . '/agent_context.md', $agentContext);
logMessage("Agent context saved");

// ============================================================================
// SAVE MANIFEST
// ============================================================================

$totalTime = round(microtime(true) - $startTime, 2);
$results['timing']['total_seconds'] = $totalTime;

$manifest = [
    'generated_at' => date('Y-m-d H:i:s T'),
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'next_run' => date('Y-m-d H:i:s T', strtotime('+12 hours')),
    'timing' => $results['timing'],
    'files' => [
        'codebase_index.json' => file_exists($outputPath . '/codebase_index.json') ? filesize($outputPath . '/codebase_index.json') : 0,
        'codebase_index.md' => file_exists($outputPath . '/codebase_index.md') ? filesize($outputPath . '/codebase_index.md') : 0,
        'database_schema.json' => file_exists($outputPath . '/database_schema.json') ? filesize($outputPath . '/database_schema.json') : 0,
        'database_schema.md' => file_exists($outputPath . '/database_schema.md') ? filesize($outputPath . '/database_schema.md') : 0,
        'database_quick_reference.md' => file_exists($outputPath . '/database_quick_reference.md') ? filesize($outputPath . '/database_quick_reference.md') : 0,
        'agent_context.md' => file_exists($outputPath . '/agent_context.md') ? filesize($outputPath . '/agent_context.md') : 0,
    ],
    'stats' => [
        'codebase' => $results['codebase']['stats'] ?? null,
        'database' => $results['database']['stats'] ?? null
    ],
    'errors' => $results['errors']
];

file_put_contents(
    $outputPath . '/index_manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT)
);

// ============================================================================
// SUMMARY
// ============================================================================

logMessage("");
logMessage("========================================");
logMessage("INDEXING COMPLETE");
logMessage("========================================");
logMessage("Total time: {$totalTime} seconds");
logMessage("  - Codebase: {$results['timing']['codebase_seconds']}s");
logMessage("  - Database: {$results['timing']['database_seconds']}s");

if (!empty($results['errors'])) {
    logMessage("");
    logMessage("ERRORS (" . count($results['errors']) . "):");
    foreach ($results['errors'] as $error) {
        logMessage("  - {$error}");
    }
}

logMessage("");
logMessage("Output files:");
foreach ($manifest['files'] as $file => $size) {
    $sizeKb = round($size / 1024, 1);
    logMessage("  - {$file} ({$sizeKb} KB)");
}

logMessage("");
logMessage("Next scheduled run: {$manifest['next_run']}");

// Return success for HTTP requests
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => empty($results['errors']),
        'timing' => $results['timing'],
        'manifest' => $manifest
    ], JSON_PRETTY_PRINT);
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Generate a combined context document optimized for coding agents
 */
function generateAgentContext(array $results, string $rootPath): string {
    $md = "# PERTI Agent Context\n\n";
    $md .= "> **Auto-generated index for coding agents. Updated every 12 hours.**\n>\n";
    $md .= "> Last updated: " . date('Y-m-d H:i:s T') . "\n\n";

    $md .= "## Quick Reference\n\n";
    $md .= "This document provides a high-level overview of the PERTI codebase and database schema.\n";
    $md .= "For detailed information, see the full index files in `/data/indexes/`.\n\n";

    // Project Overview
    $md .= "## Project Overview\n\n";
    $md .= "PERTI (PERTI Event Review & Traffic Initiative) is a web application for VATSIM traffic management.\n\n";
    $md .= "**Key Technologies:**\n";
    $md .= "- Backend: PHP 8.x on Azure App Service\n";
    $md .= "- Databases: MySQL, Azure SQL Server, PostgreSQL/PostGIS\n";
    $md .= "- Frontend: Vanilla JS with MapLibre for mapping\n";
    $md .= "- Discord: Node.js bot for coordination\n\n";

    // Directory Structure
    $md .= "## Directory Structure\n\n";
    $md .= "```\n";
    $md .= "/api/          - REST API endpoints (organized by domain)\n";
    $md .= "/load/         - Configuration and shared includes\n";
    $md .= "/assets/js/    - Frontend JavaScript modules\n";
    $md .= "/database/migrations/ - SQL migrations by database\n";
    $md .= "/scripts/      - Utility and daemon scripts\n";
    $md .= "/cron/         - Scheduled job handlers\n";
    $md .= "/adl/          - ADL data processing\n";
    $md .= "/discord-bot/  - Discord.js bot\n";
    $md .= "/data/         - Runtime data and indexes\n";
    $md .= "```\n\n";

    // Codebase Stats
    if (isset($results['codebase']['stats'])) {
        $stats = $results['codebase']['stats'];
        $md .= "## Codebase Statistics\n\n";
        $md .= "| Metric | Count |\n";
        $md .= "|--------|-------|\n";
        $md .= "| PHP Files | {$stats['php_files']} |\n";
        $md .= "| JS Modules | {$stats['js_files']} |\n";
        $md .= "| API Endpoints | {$stats['api_endpoints']} |\n";
        $md .= "| Classes | {$stats['classes']} |\n";
        $md .= "| Functions | {$stats['functions']} |\n";
        $md .= "\n";
    }

    // API Domains Summary
    if (isset($results['codebase']['index']['api_endpoints'])) {
        $md .= "## API Domains\n\n";
        $md .= "| Domain | Endpoints | Description |\n";
        $md .= "|--------|-----------|-------------|\n";

        $domainDescriptions = [
            'tmi' => 'Traffic Management Initiatives (GDPs, Ground Stops, Reroutes)',
            'adl' => 'Aggregate Demand List (flight data, tracks)',
            'data' => 'Data management (plans, configs, crossings)',
            'gis' => 'Geographic queries (boundaries, routes)',
            'splits' => 'Sector/position configuration',
            'events' => 'Event management',
            'user' => 'User authentication and management',
            'discord' => 'Discord bot integration',
            'stats' => 'Statistics and metrics',
            'demand' => 'Demand analysis',
            'weather' => 'Weather data',
            'admin' => 'Administration functions',
            'swim' => 'SWIM data integration',
            'routes' => 'Route management',
            'gdt' => 'Ground Delay Tool',
            'nod' => 'NAS Operations Dashboard',
        ];

        foreach ($results['codebase']['index']['api_endpoints'] as $domain => $endpoints) {
            $count = count($endpoints);
            $desc = $domainDescriptions[$domain] ?? '';
            $md .= "| `/api/{$domain}/` | {$count} | {$desc} |\n";
        }
        $md .= "\n";
    }

    // Database Summary
    $md .= "## Databases\n\n";
    $md .= "| Database | Type | Tables | Description |\n";
    $md .= "|----------|------|--------|-------------|\n";

    // Known database configurations (static reference)
    $knownDatabases = [
        'perti_site' => ['type' => 'mysql', 'desc' => 'Main web app - users, plans, configs'],
        'VATSIM_ADL' => ['type' => 'sqlsrv', 'desc' => 'Flight data - tracks, crossings, ETAs'],
        'VATSIM_TMI' => ['type' => 'sqlsrv', 'desc' => 'TMIs - GDPs, ground stops, reroutes'],
        'VATSIM_REF' => ['type' => 'sqlsrv', 'desc' => 'Reference - airports, airways, fixes'],
        'SWIM_API' => ['type' => 'sqlsrv', 'desc' => 'SWIM integration - API sync'],
        'VATSIM_GIS' => ['type' => 'pgsql', 'desc' => 'PostGIS - spatial queries, boundaries'],
        'VATSIM_STATS' => ['type' => 'sqlsrv', 'desc' => 'Statistics - metrics, patterns'],
    ];

    if (isset($results['database']['index']) && !empty($results['database']['index'])) {
        foreach ($results['database']['index'] as $dbName => $db) {
            $tableCount = count($db['tables'] ?? []);
            $md .= "| {$dbName} | {$db['type']} | {$tableCount} | {$db['description']} |\n";
        }
    } else {
        // Fallback: show known databases when indexer couldn't connect
        foreach ($knownDatabases as $dbName => $info) {
            $md .= "| {$dbName} | {$info['type']} | - | {$info['desc']} |\n";
        }
    }
    $md .= "\n";

    // Key tables per database
    $md .= "## Key Tables\n\n";

    if (isset($results['database']['index']) && !empty($results['database']['index'])) {
        foreach ($results['database']['index'] as $dbName => $db) {
            if (empty($db['tables'])) continue;

            $md .= "### {$dbName}\n\n";

            // Sort tables by row count (descending) and take top 10
            $tables = $db['tables'];
            usort($tables, fn($a, $b) => ($b['row_count'] ?? 0) - ($a['row_count'] ?? 0));
            $topTables = array_slice($tables, 0, 10);

            $md .= "| Table | Rows | Key Columns |\n";
            $md .= "|-------|------|-------------|\n";

            foreach ($topTables as $table) {
                $rows = $table['row_count'] >= 0 ? number_format($table['row_count']) : 'N/A';

                // Extract likely key columns
                $keyCols = [];
                foreach ($table['columns'] as $col) {
                    $name = strtolower($col['name']);
                    if (strpos($name, '_id') !== false ||
                        strpos($name, 'code') !== false ||
                        strpos($name, 'callsign') !== false ||
                        $name === 'id') {
                        $keyCols[] = $col['name'];
                    }
                }
                $keyColStr = implode(', ', array_slice($keyCols, 0, 4));

                $md .= "| `{$table['name']}` | {$rows} | {$keyColStr} |\n";
            }
            $md .= "\n";
        }
    } else {
        // Fallback: Known tables from migrations and codebase analysis
        $md .= "### perti_site (MySQL)\n\n";
        $md .= "| Table | Purpose |\n";
        $md .= "|-------|--------|\n";
        $md .= "| `users` | User accounts (CID, name, role) |\n";
        $md .= "| `config_data` | Airport runway configurations |\n";
        $md .= "| `tmi_ground_stops` | Ground stop entries |\n";
        $md .= "| `p_plans` | PERTI event plans |\n";
        $md .= "| `p_configs` | Plan configurations |\n";
        $md .= "| `p_dcc_staffing` | DCC staffing entries |\n";
        $md .= "| `p_terminal_init_timeline` | Terminal initiative timeline |\n";
        $md .= "| `p_enroute_init_timeline` | En route initiative timeline |\n";
        $md .= "| `p_forecast` | Event forecasts |\n";
        $md .= "\n";

        $md .= "### VATSIM_ADL (Azure SQL)\n\n";
        $md .= "| Table | Purpose |\n";
        $md .= "|-------|--------|\n";
        $md .= "| `adl_flight_core` | Core flight data (callsign, type, origin, dest) |\n";
        $md .= "| `adl_flight_position` | Current position data |\n";
        $md .= "| `adl_flight_plan` | Filed flight plan details |\n";
        $md .= "| `adl_flight_times` | ETA/departure times |\n";
        $md .= "| `adl_flight_waypoints` | Parsed route waypoints |\n";
        $md .= "| `adl_boundary` | ARTCC/FIR boundary definitions |\n";
        $md .= "| `adl_flight_archive` | Historical flight records |\n";
        $md .= "\n";

        $md .= "### VATSIM_TMI (Azure SQL)\n\n";
        $md .= "| Table | Purpose |\n";
        $md .= "|-------|--------|\n";
        $md .= "| `tmi_programs` | GDP/GDT programs |\n";
        $md .= "| `tmi_entries` | TMI entries (GS, GDP, MIT, etc.) |\n";
        $md .= "| `tmi_advisories` | Advisory postings |\n";
        $md .= "| `tmi_reroutes` | Reroute definitions |\n";
        $md .= "| `tmi_proposals` | Coordination proposals |\n";
        $md .= "| `tmi_public_routes` | Published public routes |\n";
        $md .= "| `tmi_slots` | GDP slot assignments |\n";
        $md .= "\n";

        $md .= "### VATSIM_GIS (PostgreSQL/PostGIS)\n\n";
        $md .= "| Table | Purpose |\n";
        $md .= "|-------|--------|\n";
        $md .= "| `artcc_boundaries` | ARTCC boundary polygons |\n";
        $md .= "| `sector_boundaries` | Sector boundary polygons |\n";
        $md .= "| `airports` | Airport locations |\n";
        $md .= "\n";
    }

    // Common Patterns
    $md .= "## Common Code Patterns\n\n";

    $md .= "### Database Connections\n\n";
    $md .= "```php\n";
    $md .= "// MySQL (primary site database)\n";
    $md .= "global \$conn_pdo;  // PDO connection\n";
    $md .= "global \$conn_sqli; // MySQLi connection\n";
    $md .= "\n";
    $md .= "// Azure SQL (lazy-loaded)\n";
    $md .= "\$conn_adl = get_conn_adl();  // VATSIM_ADL\n";
    $md .= "\$conn_tmi = get_conn_tmi();  // VATSIM_TMI\n";
    $md .= "\$conn_ref = get_conn_ref();  // VATSIM_REF\n";
    $md .= "\$conn_gis = get_conn_gis();  // VATSIM_GIS (PostgreSQL)\n";
    $md .= "```\n\n";

    $md .= "### API Endpoint Pattern\n\n";
    $md .= "```php\n";
    $md .= "<?php\n";
    $md .= "require_once __DIR__ . '/../../load/connect.php';\n";
    $md .= "\n";
    $md .= "header('Content-Type: application/json');\n";
    $md .= "\n";
    $md .= "\$action = get_input('action');\n";
    $md .= "\$id = get_input('id');\n";
    $md .= "\n";
    $md .= "// ... process request ...\n";
    $md .= "\n";
    $md .= "echo json_encode(['success' => true, 'data' => \$result]);\n";
    $md .= "```\n\n";

    $md .= "### Safe Input Handling (PHP 8.2+)\n\n";
    $md .= "```php\n";
    $md .= "// Use get_input() instead of direct \$_GET/\$_POST access\n";
    $md .= "\$value = get_input('param_name');           // Returns null if not set\n";
    $md .= "\$value = get_input('param_name', 'default'); // With default\n";
    $md .= "\n";
    $md .= "// For session values\n";
    $md .= "\$user = session_get('user_id');\n";
    $md .= "```\n\n";

    // File locations for common tasks
    $md .= "## Key File Locations\n\n";
    $md .= "| Task | Location |\n";
    $md .= "|------|----------|\n";
    $md .= "| Database config | `load/config.php` |\n";
    $md .= "| DB connections | `load/connect.php` |\n";
    $md .= "| Input handling | `load/input.php` |\n";
    $md .= "| Page header/nav | `load/header.php`, `load/nav.php` |\n";
    $md .= "| Discord API | `load/discord/DiscordAPI.php` |\n";
    $md .= "| TMI coordination | `load/discord/TMIDiscord.php` |\n";
    $md .= "| ADL daemon | `scripts/vatsim_adl_daemon.php` |\n";
    $md .= "| Cron scheduler | `scripts/scheduler_daemon.php` |\n";
    $md .= "\n";

    // Search hints
    $md .= "## Search Tips for Agents\n\n";
    $md .= "When exploring this codebase:\n\n";
    $md .= "1. **API endpoints**: Search in `/api/{domain}/` - each `.php` file is an endpoint\n";
    $md .= "2. **Database queries**: Look for `FROM dbo.table_name` or `` FROM `table_name` ``\n";
    $md .= "3. **Configuration**: Check `load/config.php` for constants\n";
    $md .= "4. **Frontend modules**: JavaScript in `assets/js/` uses ES6 imports\n";
    $md .= "5. **Background jobs**: Daemons in `scripts/` and `adl/php/`\n";
    $md .= "6. **Migrations**: SQL changes in `database/migrations/{db}/`\n\n";

    $md .= "---\n\n";
    $md .= "*This context was auto-generated by the PERTI indexer. See `/scripts/indexer/` for source.*\n";

    return $md;
}
