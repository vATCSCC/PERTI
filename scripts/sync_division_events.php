<?php
/**
 * Division Events Sync Script
 *
 * Fetches events from VATUSA, VATCAN, and VATSIM APIs and stores them
 * in the division_events table.
 *
 * Usage:
 *   CLI:  php sync_division_events.php [--source=VATUSA|VATCAN|VATSIM|ALL]
 *   Web:  Include and call sync_division_events()
 *
 * @package PERTI\Scripts
 * @version 1.0.0
 * @since 2026-01-31
 */

// Determine if running from CLI or web
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    // Web context - load config
    require_once __DIR__ . '/../load/config.php';
    require_once __DIR__ . '/../load/connect.php';
}

// API endpoints
define('VATUSA_API_URL', 'https://api.vatusa.net/v2/public/events/50');
define('VATCAN_API_URL', 'https://vatcan.ca/api/events');
define('VATSIM_API_URL', 'https://my.vatsim.net/api/v2/events/latest');

/**
 * Fetch JSON from URL with error handling
 */
function fetchJson(string $url, int $timeout = 15): ?array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'PERTI-EventSync/1.0 (VATCSCC)'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log("Failed to fetch $url: HTTP $httpCode - $error");
        return null;
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Invalid JSON from $url: " . json_last_error_msg());
        return null;
    }

    return $data;
}

/**
 * Get ADL database connection (where division_events table lives)
 */
function getEventsDbConnection(): ?PDO
{
    global $conn_adl;

    // If already have connection from connect.php
    if (isset($conn_adl) && $conn_adl) {
        return $conn_adl;
    }

    // Create new connection to VATSIM_ADL
    try {
        $dsn = "sqlsrv:server=tcp:vatsim.database.windows.net,1433;Database=VATSIM_ADL;Encrypt=yes;TrustServerCertificate=no";
        return new PDO($dsn, 'adl_api_user', '***REMOVED***', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $e) {
        error_log("ADL DB connection failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Upsert event into division_events table
 */
function upsertEvent(PDO $conn, array $event): bool
{
    $sql = "
        MERGE INTO dbo.division_events AS target
        USING (SELECT ? AS source, ? AS external_id) AS source_row
        ON target.source = source_row.source AND target.external_id = source_row.external_id
        WHEN MATCHED THEN
            UPDATE SET
                event_name = ?,
                event_type = ?,
                event_link = ?,
                banner_url = ?,
                start_utc = ?,
                end_utc = ?,
                division = ?,
                region = ?,
                airports_json = ?,
                routes_json = ?,
                short_description = ?,
                description = ?,
                synced_at = SYSUTCDATETIME(),
                updated_at = SYSUTCDATETIME()
        WHEN NOT MATCHED THEN
            INSERT (source, external_id, event_name, event_type, event_link, banner_url,
                    start_utc, end_utc, division, region, airports_json, routes_json,
                    short_description, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            // MERGE ON clause
            $event['source'],
            $event['external_id'],
            // UPDATE values
            $event['event_name'],
            $event['event_type'],
            $event['event_link'],
            $event['banner_url'],
            $event['start_utc'],
            $event['end_utc'],
            $event['division'],
            $event['region'],
            $event['airports_json'],
            $event['routes_json'],
            $event['short_description'],
            $event['description'],
            // INSERT values
            $event['source'],
            $event['external_id'],
            $event['event_name'],
            $event['event_type'],
            $event['event_link'],
            $event['banner_url'],
            $event['start_utc'],
            $event['end_utc'],
            $event['division'],
            $event['region'],
            $event['airports_json'],
            $event['routes_json'],
            $event['short_description'],
            $event['description'],
        ]);
        return true;
    } catch (PDOException $e) {
        error_log("Failed to upsert event {$event['source']}/{$event['external_id']}: " . $e->getMessage());
        return false;
    }
}

/**
 * Sync VATUSA events
 */
function syncVatusaEvents(PDO $conn): array
{
    $result = ['source' => 'VATUSA', 'fetched' => 0, 'synced' => 0, 'errors' => 0];

    $data = fetchJson(VATUSA_API_URL);
    if (!$data || !isset($data['data'])) {
        $result['errors'] = 1;
        return $result;
    }

    foreach ($data['data'] as $event) {
        $result['fetched']++;

        // Parse VATUSA date format (YYYY-MM-DD)
        $startUtc = $event['start_date'] . 'T00:00:00';
        $endUtc = isset($event['end_date']) ? $event['end_date'] . 'T23:59:59' : null;

        $normalized = [
            'source' => 'VATUSA',
            'external_id' => (string)$event['id_event'],
            'event_name' => $event['title'] ?? 'Untitled Event',
            'event_type' => 'Event',
            'event_link' => null, // VATUSA doesn't provide direct link in this endpoint
            'banner_url' => null,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'division' => 'USA',
            'region' => 'AMAS',
            'airports_json' => null,
            'routes_json' => null,
            'short_description' => null,
            'description' => null,
        ];

        if (upsertEvent($conn, $normalized)) {
            $result['synced']++;
        } else {
            $result['errors']++;
        }
    }

    return $result;
}

/**
 * Sync VATCAN events
 */
function syncVatcanEvents(PDO $conn): array
{
    $result = ['source' => 'VATCAN', 'fetched' => 0, 'synced' => 0, 'errors' => 0];

    $data = fetchJson(VATCAN_API_URL);
    if (!$data || !is_array($data)) {
        $result['errors'] = 1;
        return $result;
    }

    foreach ($data as $event) {
        $result['fetched']++;

        // Build airports array from arrival/departure
        $airports = [];
        if (!empty($event['airports'])) {
            if (!empty($event['airports']['departure'])) {
                $airports = array_merge($airports, $event['airports']['departure']);
            }
            if (!empty($event['airports']['arrival'])) {
                $airports = array_merge($airports, $event['airports']['arrival']);
            }
        }
        $airports = array_unique($airports);

        $normalized = [
            'source' => 'VATCAN',
            'external_id' => (string)$event['id'],
            'event_name' => $event['name'] ?? 'Untitled Event',
            'event_type' => 'Event',
            'event_link' => $event['forum_url'] ?? null,
            'banner_url' => $event['image_url'] ?? null,
            'start_utc' => str_replace(' ', 'T', $event['start']),
            'end_utc' => isset($event['end']) ? str_replace(' ', 'T', $event['end']) : null,
            'division' => 'CAN',
            'region' => 'AMAS',
            'airports_json' => !empty($airports) ? json_encode($airports) : null,
            'routes_json' => null,
            'short_description' => null,
            'description' => $event['description'] ?? null,
        ];

        if (upsertEvent($conn, $normalized)) {
            $result['synced']++;
        } else {
            $result['errors']++;
        }
    }

    return $result;
}

/**
 * Sync VATSIM global events
 */
function syncVatsimEvents(PDO $conn): array
{
    $result = ['source' => 'VATSIM', 'fetched' => 0, 'synced' => 0, 'errors' => 0];

    $data = fetchJson(VATSIM_API_URL);
    if (!$data || !isset($data['data'])) {
        $result['errors'] = 1;
        return $result;
    }

    foreach ($data['data'] as $event) {
        $result['fetched']++;

        // Extract airports
        $airports = [];
        if (!empty($event['airports'])) {
            foreach ($event['airports'] as $apt) {
                if (isset($apt['icao'])) {
                    $airports[] = $apt['icao'];
                }
            }
        }

        // Extract division from organisers
        $division = null;
        $region = null;
        if (!empty($event['organisers'])) {
            foreach ($event['organisers'] as $org) {
                if (!empty($org['division'])) {
                    $division = $org['division'];
                }
                if (!empty($org['region'])) {
                    $region = $org['region'];
                }
                break; // Take first organiser
            }
        }

        // Format start/end times (already ISO format with timezone)
        $startUtc = isset($event['start_time'])
            ? preg_replace('/\.\d+Z$/', '', $event['start_time'])
            : null;
        $endUtc = isset($event['end_time'])
            ? preg_replace('/\.\d+Z$/', '', $event['end_time'])
            : null;

        $normalized = [
            'source' => 'VATSIM',
            'external_id' => (string)$event['id'],
            'event_name' => $event['name'] ?? 'Untitled Event',
            'event_type' => $event['type'] ?? 'Event',
            'event_link' => $event['link'] ?? null,
            'banner_url' => $event['banner'] ?? null,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'division' => $division,
            'region' => $region,
            'airports_json' => !empty($airports) ? json_encode($airports) : null,
            'routes_json' => !empty($event['routes']) ? json_encode($event['routes']) : null,
            'short_description' => $event['short_description'] ?? null,
            'description' => $event['description'] ?? null,
        ];

        if (upsertEvent($conn, $normalized)) {
            $result['synced']++;
        } else {
            $result['errors']++;
        }
    }

    return $result;
}

/**
 * Main sync function
 *
 * @param string $source 'VATUSA', 'VATCAN', 'VATSIM', or 'ALL'
 * @return array Results array
 */
function sync_division_events(string $source = 'ALL'): array
{
    $conn = getEventsDbConnection();
    if (!$conn) {
        return ['success' => false, 'error' => 'Database connection failed'];
    }

    $results = [];
    $source = strtoupper($source);

    if ($source === 'ALL' || $source === 'VATUSA') {
        $results[] = syncVatusaEvents($conn);
    }

    if ($source === 'ALL' || $source === 'VATCAN') {
        $results[] = syncVatcanEvents($conn);
    }

    if ($source === 'ALL' || $source === 'VATSIM') {
        $results[] = syncVatsimEvents($conn);
    }

    $totalFetched = array_sum(array_column($results, 'fetched'));
    $totalSynced = array_sum(array_column($results, 'synced'));
    $totalErrors = array_sum(array_column($results, 'errors'));

    return [
        'success' => true,
        'synced_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'totals' => [
            'fetched' => $totalFetched,
            'synced' => $totalSynced,
            'errors' => $totalErrors,
        ],
        'by_source' => $results,
    ];
}

// CLI execution
if ($isCli) {
    // Parse arguments
    $source = 'ALL';
    foreach ($argv as $arg) {
        if (strpos($arg, '--source=') === 0) {
            $source = substr($arg, 9);
        }
    }

    echo "PERTI Division Events Sync\n";
    echo "==========================\n";
    echo "Source: $source\n";
    echo "Started: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

    $results = sync_division_events($source);

    if ($results['success']) {
        echo "Results:\n";
        foreach ($results['by_source'] as $r) {
            printf("  %s: %d fetched, %d synced, %d errors\n",
                $r['source'], $r['fetched'], $r['synced'], $r['errors']);
        }
        echo "\nTotals: {$results['totals']['fetched']} fetched, {$results['totals']['synced']} synced, {$results['totals']['errors']} errors\n";
    } else {
        echo "Error: {$results['error']}\n";
        exit(1);
    }
}
