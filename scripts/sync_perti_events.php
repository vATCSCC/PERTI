<?php
/**
 * PERTI Events Sync Script
 *
 * Fetches events from VATUSA, VATCAN, and VATSIM APIs and stores them
 * in the perti_events table (unified event scheduling).
 *
 * Usage:
 *   CLI:  php sync_perti_events.php [--source=VATUSA|VATCAN|VATSIM|ALL]
 *   Web:  Include and call sync_perti_events()
 *
 * @package PERTI\Scripts
 * @version 2.0.0
 * @since 2026-02-02
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

// Event type mapping patterns (name-based, applies to all sources)
// NOTE: FNO, SAT, SUN, MWK day-of-week classification is VATUSA-specific
//       and handled separately in classifyVatusaByTime()
define('EVENT_TYPE_PATTERNS', [
    // Cross-division special events
    'CTP' => ['CTP', 'Cross The Pond', 'Cross-The-Pond', 'CrossThePond'],
    'CTL' => ['CTL', 'Cross The Land', 'Cross-The-Land'],
    'WF'  => ['WorldFlight', 'World Flight'],
    '24HRSOV' => ['24HRSOV', '24HR'],

    // Name-based VATUSA patterns
    'FNO' => ['FNO', 'Friday Night Ops'],

    // Any division
    'LIVE' => ['Live'],
    'REALOPS' => ['Real Ops', 'RealOps', 'Real-Ops', 'Real Operations'],
    'TRAIN' => ['Training', 'Exam', 'First Wings'],
    'SPEC' => ['CROSS VATRUS', 'Overload', 'Screamin'],
]);

// Source priority for deduplication (higher = preferred)
define('SOURCE_PRIORITY', [
    'VATSIM' => 3,
    'VATCAN' => 2,
    'VATUSA' => 1,
    'MANUAL' => 0,
]);

/**
 * Check if a higher-priority source already has this event
 * Returns true if we should skip this event (duplicate exists)
 */
function isDuplicateEvent(PDO $conn, array $event): bool
{
    $source = $event['source'];
    $priority = SOURCE_PRIORITY[$source] ?? 0;

    // Only check for duplicates from lower-priority sources
    if ($priority >= 3) {
        return false; // VATSIM is highest priority, never skip
    }

    // Build list of higher-priority sources
    $higherSources = [];
    foreach (SOURCE_PRIORITY as $src => $pri) {
        if ($pri > $priority) {
            $higherSources[] = $src;
        }
    }

    if (empty($higherSources)) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($higherSources), '?'));

    // Check for existing event on same day with similar characteristics
    $sql = "
        SELECT event_id FROM dbo.perti_events
        WHERE source IN ($placeholders)
          AND CAST(start_utc AS DATE) = CAST(? AS DATE)
          AND (
              -- Same featured airports
              (featured_airports IS NOT NULL AND featured_airports = ?)
              -- Or similar name
              OR event_name LIKE ?
          )
    ";

    $params = array_merge(
        $higherSources,
        [
            $event['start_utc'],
            $event['featured_airports'],
            '%' . substr($event['event_name'], 0, 20) . '%'
        ]
    );

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        error_log("Duplicate check failed: " . $e->getMessage());
        return false;
    }
}

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
        CURLOPT_USERAGENT => 'PERTI-EventSync/2.0 (VATCSCC)'
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
 * Get ADL database connection (where perti_events table lives)
 * Note: connect.php now provides $conn_adl as PDO (not sqlsrv resource)
 */
function getEventsDbConnection(): ?PDO
{
    global $conn_adl;

    // If already have PDO connection from connect.php (web context)
    if (isset($conn_adl) && $conn_adl instanceof PDO) {
        return $conn_adl;
    }

    // CLI context: try to load config if not already loaded
    if (!defined('ADL_SQL_HOST')) {
        $configPath = __DIR__ . '/../load/config.php';
        if (file_exists($configPath)) {
            require_once $configPath;
        }
    }

    // Get credentials from config constants or environment variables
    $host = defined('ADL_SQL_HOST') ? ADL_SQL_HOST : (getenv('ADL_SQL_HOST') ?: 'vatsim.database.windows.net');
    $database = defined('ADL_SQL_DATABASE') ? ADL_SQL_DATABASE : (getenv('ADL_SQL_DATABASE') ?: 'VATSIM_ADL');
    $username = defined('ADL_SQL_USERNAME') ? ADL_SQL_USERNAME : (getenv('ADL_SQL_USERNAME') ?: getenv('ADL_DB_USER'));
    $password = defined('ADL_SQL_PASSWORD') ? ADL_SQL_PASSWORD : (getenv('ADL_SQL_PASSWORD') ?: getenv('ADL_DB_PASS'));

    if (!$username || !$password) {
        error_log("ADL DB connection failed: Missing credentials (set ADL_SQL_USERNAME/ADL_SQL_PASSWORD or environment variables)");
        return null;
    }

    // Create new PDO connection to VATSIM_ADL
    try {
        $dsn = "sqlsrv:server=tcp:{$host},1433;Database={$database};Encrypt=yes;TrustServerCertificate=no";
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        error_log("ADL DB connection failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Determine event type from name/type string
 *
 * @param string|null $name Event name
 * @param string|null $rawType Raw type from API
 * @param string|null $startUtc Start time (for VATUSA day-of-week classification)
 * @param bool $isVatusa Whether this is a VATUSA event
 */
function classifyEventType(?string $name, ?string $rawType, ?string $startUtc = null, bool $isVatusa = false): string
{
    $searchText = strtolower(($name ?? '') . ' ' . ($rawType ?? ''));

    // Priority 1: Explicit exclusions
    if (stripos($searchText, 'not an fno') !== false || stripos($searchText, 'not a fno') !== false) {
        return 'MWK';
    }

    // Priority 2: Name-based patterns (applies to all sources)
    foreach (EVENT_TYPE_PATTERNS as $type => $patterns) {
        foreach ($patterns as $pattern) {
            if (stripos($searchText, strtolower($pattern)) !== false) {
                return $type;
            }
        }
    }

    // Priority 3: OMN (Open Mic Night) - VATUSA convention
    // Must match "Open Mic" OR standalone "OMN" (not KOMN airport code)
    if (stripos($searchText, 'open mic') !== false) {
        return 'OMN';
    }
    // Check for OMN not preceded by K (to exclude KOMN airport)
    if (preg_match('/(?<![a-z])omn(?![a-z])/i', $searchText)) {
        // Additional check: exclude if it looks like an airport reference
        if (stripos($searchText, 'komn') === false && stripos($searchText, 'ormond') === false) {
            return 'OMN';
        }
    }

    // Priority 4: SNO detection (not KSNO airport - but KSNO isn't a real airport)
    if (preg_match('/(?<![a-z])sno(?![a-z])/i', $searchText)) {
        return 'SAT';
    }

    // Priority 5: Time-based classification (VATUSA only)
    if ($isVatusa && $startUtc) {
        return classifyVatusaByTime($startUtc);
    }

    // Default: UNKN (unknown) for unclassified events
    return $isVatusa ? 'MWK' : 'UNKN';
}

/**
 * Classify VATUSA event by day-of-week (matches Excel formula logic)
 */
function classifyVatusaByTime(string $startUtc): string
{
    try {
        $dt = new DateTime($startUtc, new DateTimeZone('UTC'));
        $dayOfWeek = $dt->format('l'); // Monday, Tuesday, etc.
        $hour = (int)$dt->format('G');  // 0-23

        // FNO: Friday 21:00+ or Saturday before 06:00
        if ($dayOfWeek === 'Friday' && $hour >= 21) {
            return 'FNO';
        }
        if ($dayOfWeek === 'Saturday' && $hour < 6) {
            return 'FNO';
        }

        // SAT: Saturday (after 06:00)
        if ($dayOfWeek === 'Saturday') {
            return 'SAT';
        }

        // SUN: Sunday
        if ($dayOfWeek === 'Sunday') {
            return 'SUN';
        }

        // MWK: Mon-Thu, or Friday before 21:00
        return 'MWK';
    } catch (Exception $e) {
        return 'MWK';
    }
}

/**
 * Determine event status based on timing
 */
function determineStatus(?string $startUtc, ?string $endUtc): string
{
    if (!$startUtc) {
        return 'SCHEDULED';
    }

    $now = gmdate('Y-m-d\TH:i:s');
    $start = str_replace(['T', 'Z'], [' ', ''], $startUtc);
    $end = $endUtc ? str_replace(['T', 'Z'], [' ', ''], $endUtc) : null;

    if ($end && $end < $now) {
        return 'COMPLETED';
    }

    if ($start <= $now && (!$end || $end >= $now)) {
        return 'ACTIVE';
    }

    return 'SCHEDULED';
}

/**
 * Upsert event into perti_events table
 */
function upsertEvent(PDO $conn, array $event): bool
{
    $sql = "
        MERGE INTO dbo.perti_events AS target
        USING (SELECT ? AS source, ? AS external_id) AS source_row
        ON target.source = source_row.source AND target.external_id = source_row.external_id
        WHEN MATCHED THEN
            UPDATE SET
                event_name = ?,
                event_type = ?,
                start_utc = ?,
                end_utc = ?,
                divisions = ?,
                featured_airports = ?,
                external_url = ?,
                banner_url = ?,
                description = ?,
                status = ?,
                synced_utc = SYSUTCDATETIME(),
                updated_utc = SYSUTCDATETIME()
        WHEN NOT MATCHED THEN
            INSERT (source, external_id, event_name, event_type, start_utc, end_utc,
                    divisions, featured_airports, external_url, banner_url,
                    description, status, synced_utc)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME());
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
            $event['start_utc'],
            $event['end_utc'],
            $event['divisions'],
            $event['featured_airports'],
            $event['external_url'],
            $event['banner_url'],
            $event['description'],
            $event['status'],
            // INSERT values
            $event['source'],
            $event['external_id'],
            $event['event_name'],
            $event['event_type'],
            $event['start_utc'],
            $event['end_utc'],
            $event['divisions'],
            $event['featured_airports'],
            $event['external_url'],
            $event['banner_url'],
            $event['description'],
            $event['status'],
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

        $eventName = $event['title'] ?? 'Untitled Event';
        // VATUSA events use day-of-week classification
        $eventType = classifyEventType($eventName, null, $startUtc, true);

        $normalized = [
            'source' => 'VATUSA',
            'external_id' => (string)$event['id_event'],
            'event_name' => $eventName,
            'event_type' => $eventType,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'divisions' => 'VATUSA',
            'featured_airports' => null,
            'external_url' => null, // VATUSA doesn't provide direct link in this endpoint
            'banner_url' => null,
            'description' => null,
            'status' => determineStatus($startUtc, $endUtc),
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
        $airports = array_values(array_unique($airports));

        $startUtc = str_replace(' ', 'T', $event['start']);
        $endUtc = isset($event['end']) ? str_replace(' ', 'T', $event['end']) : null;
        $eventName = $event['name'] ?? 'Untitled Event';
        // VATCAN: not VATUSA, so no day-of-week classification
        $eventType = classifyEventType($eventName, null, $startUtc, false);

        $normalized = [
            'source' => 'VATCAN',
            'external_id' => (string)$event['id'],
            'event_name' => $eventName,
            'event_type' => $eventType,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'divisions' => 'VATCAN',
            'featured_airports' => !empty($airports) ? json_encode($airports) : null,
            'external_url' => $event['forum_url'] ?? null,
            'banner_url' => $event['image_url'] ?? null,
            'description' => $event['description'] ?? null,
            'status' => determineStatus($startUtc, $endUtc),
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

        // Extract divisions from organisers (could be multiple)
        $divisions = [];
        if (!empty($event['organisers'])) {
            foreach ($event['organisers'] as $org) {
                if (!empty($org['division'])) {
                    $divisions[] = strtoupper($org['division']);
                }
            }
        }
        $divisions = array_unique($divisions);

        // Format start/end times (already ISO format with timezone)
        $startUtc = isset($event['start_time'])
            ? preg_replace('/\.\d+Z$/', '', $event['start_time'])
            : null;
        $endUtc = isset($event['end_time'])
            ? preg_replace('/\.\d+Z$/', '', $event['end_time'])
            : null;

        $eventName = $event['name'] ?? 'Untitled Event';
        // Check if this is a VATUSA event (division = USA)
        $isVatusa = in_array('USA', $divisions) || in_array('VATUSA', $divisions);
        $eventType = classifyEventType($eventName, $event['type'] ?? null, $startUtc, $isVatusa);

        $normalized = [
            'source' => 'VATSIM',
            'external_id' => (string)$event['id'],
            'event_name' => $eventName,
            'event_type' => $eventType,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'divisions' => !empty($divisions) ? implode(',', $divisions) : null,
            'featured_airports' => !empty($airports) ? json_encode($airports) : null,
            'external_url' => $event['link'] ?? null,
            'banner_url' => $event['banner'] ?? null,
            'description' => $event['description'] ?? $event['short_description'] ?? null,
            'status' => determineStatus($startUtc, $endUtc),
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
function sync_perti_events(string $source = 'ALL'): array
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

// Backwards compatibility alias
function sync_division_events(string $source = 'ALL'): array
{
    return sync_perti_events($source);
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

    echo "PERTI Events Sync (v2.0)\n";
    echo "========================\n";
    echo "Target: perti_events table\n";
    echo "Source: $source\n";
    echo "Started: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

    $results = sync_perti_events($source);

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
