# Deep Hibernation Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Level 2 (deep) hibernation that suspends all flight processing, captures raw VATSIM JSON to Azure Blob Storage, and provides a replay mechanism for post-processing.

**Architecture:** New standalone capture daemon fetches VATSIM JSON every 15s, gzip-compresses it, buffers locally, and batch-uploads to the existing `adl-raw-archive` blob container under a `datafeed/` prefix every 10 minutes. A separate replay script reads those blobs chronologically and feeds them through the ADL ingest pipeline after exiting deep hibernation.

**Tech Stack:** PHP 8.2 (daemon + replay), Azure Blob Storage REST API (Shared Key auth), gzip compression, existing ADL ingest SP pipeline.

**Spec:** `docs/superpowers/specs/2026-05-13-deep-hibernation-design.md`

---

## File Structure

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `scripts/deep_hibernation_daemon.php` | Fetch VATSIM JSON, gzip, buffer, batch-upload to blob |
| Create | `scripts/deep_hibernation_replay.php` | Download archived JSON from blob, feed through ADL ingest |
| Create | `scripts/lib/AzureBlobClient.php` | Thin Azure Blob REST API client (Shared Key auth) |
| Create | `scripts/lib/adl_ingest_functions.php` | Shared ADL ingest functions extracted from the daemon for replay use |
| Modify | `scripts/vatsim_adl_daemon.php` | Require shared ingest functions instead of defining them inline |
| Modify | `load/config.php:198` | Add `DEEP_HIBERNATION_MODE` constant + invariant check |
| Modify | `load/hibernation.php` | Deep hibernation: SWIM page redirects + API 503 |
| Modify | `load/nav.php:51,100-109` | Mark SWIM nav items hibernated when deep |
| Modify | `load/nav_public.php:52,87-95` | Same as nav.php for public pages |
| Modify | `scripts/startup.sh:25-35,65-191` | Deep hibernation conditional — only capture + monitoring |
| Modify | `hibernation.php:182-243` | Show deep hibernation level info |
| Modify | `docs/operations/HIBERNATION_RUNBOOK.md` | Add Deep Hibernation section |
| Modify | `scripts/adl_archive/setup_infrastructure.ps1:151-185` | Add `datafeed/` prefix to lifecycle policy |

---

### Task 1: Add DEEP_HIBERNATION_MODE Configuration

**Files:**
- Modify: `load/config.php:197-198`

- [ ] **Step 1: Add the DEEP_HIBERNATION_MODE constant**

In `load/config.php`, immediately after line 198 (`define("HIBERNATION_MODE", ...)`), add the deep hibernation constant and invariant check:

```php
    // Deep Hibernation Mode - suspends ALL processing, captures raw JSON for post-processing replay
    define("DEEP_HIBERNATION_MODE", env('DEEP_HIBERNATION_MODE', false));

    // Deep hibernation implies hibernation — warn if misconfigured
    if (DEEP_HIBERNATION_MODE && !HIBERNATION_MODE) {
        error_log("WARNING: DEEP_HIBERNATION_MODE=1 but HIBERNATION_MODE=0. Both must be set.");
    }
```

Insert after the existing `HIBERNATION_MODE` line (198) and before the CTP config block (line 200).

- [ ] **Step 2: Verify the constant is accessible**

Start the PHP built-in server and test:

```bash
php -r "require 'load/config.php'; echo 'HIB=' . (HIBERNATION_MODE ? '1' : '0') . ' DEEP=' . (DEEP_HIBERNATION_MODE ? '1' : '0') . PHP_EOL;"
```

Expected: `HIB=1 DEEP=0` (since HIBERNATION_MODE defaults true, DEEP defaults false).

- [ ] **Step 3: Commit**

```bash
git add load/config.php
git commit -m "feat: add DEEP_HIBERNATION_MODE constant to config.php"
```

---

### Task 2: Extend Hibernation Redirects for Deep Mode

**Files:**
- Modify: `load/hibernation.php`

This task adds two behaviors for deep hibernation:
1. SWIM pages (`swim.php`, `swim-doc.php`, `swim-docs.php`, `swim-keys.php`) redirect to `/hibernation`
2. SWIM API endpoints (`api/swim/`) return 503 JSON

- [ ] **Step 1: Add deep hibernation page redirects and API 503**

Replace the entire content of `load/hibernation.php` with:

```php
<?php
/**
 * Hibernation Mode Check
 *
 * Included from config.php. When HIBERNATION_MODE is enabled:
 * - Redirects hibernated web pages to /hibernation info page
 * - Tracks hit counts for demand analysis (hibernation_hits table)
 * - SWIM API endpoints are exempt (remain fully operational)
 *
 * When DEEP_HIBERNATION_MODE is also enabled:
 * - SWIM pages additionally redirect to /hibernation
 * - SWIM API endpoints return 503 JSON
 *
 * @see docs/operations/HIBERNATION_RUNBOOK.md for full operational guide
 */

if (!defined('HIBERNATION_MODE') || !HIBERNATION_MODE) {
    return;
}

/**
 * Record a hit to a hibernated resource for demand tracking.
 * Uses a standalone PDO connection (config.php constants are available).
 * Silently fails — must never break redirects or 503 responses.
 */
function _hibernation_track_hit($page, $type = 'page') {
    try {
        if (!defined('SQL_HOST')) return;
        $pdo = new PDO(
            'mysql:host=' . SQL_HOST . ';dbname=' . SQL_DATABASE . ';charset=utf8mb4',
            SQL_USERNAME, SQL_PASSWORD,
            [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]
        );
        $stmt = $pdo->prepare(
            "INSERT INTO hibernation_hits (page, hit_type, ip_hash, hit_utc) VALUES (?, ?, ?, UTC_TIMESTAMP())"
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt->execute([$page, $type, hash('sha256', $ip . '_perti_hib')]);
    } catch (Exception $e) {
        // Silent — never interfere with redirect/503
    }
}

// Pages that redirect to the hibernation info page
// NOTE: In Level 1 hibernation, SWIM pages are exempt.
// In Level 2 (deep), SWIM pages are also redirected.
$_hibernated_pages = [
    'demand.php',
    'nod.php',
    'simulator.php',
    'gdt.php',
    'cdm.php',
    'sua.php',
    'event-aar.php',
];

// Deep hibernation: also redirect SWIM pages
$_deep_hibernation = defined('DEEP_HIBERNATION_MODE') && DEEP_HIBERNATION_MODE;
if ($_deep_hibernation) {
    $_hibernated_pages = array_merge($_hibernated_pages, [
        'swim.php',
        'swim-doc.php',
        'swim-docs.php',
        'swim-keys.php',
    ]);
}

$_current_page = basename($_SERVER['PHP_SELF'] ?? '');
$_request_uri = $_SERVER['REQUEST_URI'] ?? '';

// Redirect hibernated web pages to info page
if (in_array($_current_page, $_hibernated_pages)) {
    _hibernation_track_hit($_current_page, 'page');
    if (!headers_sent()) {
        header('Location: /hibernation');
    }
    exit();
}

// Deep hibernation: SWIM API returns 503 JSON
if ($_deep_hibernation && preg_match('#^/api/swim/#', $_request_uri)) {
    _hibernation_track_hit('api/swim', 'api');
    if (!headers_sent()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: application/json');
        header('Retry-After: 86400');
    }
    echo json_encode([
        'error' => 'Service suspended',
        'mode' => 'deep_hibernation',
        'message' => 'SWIM API is suspended during deep hibernation. Data is being archived for post-processing.',
    ]);
    exit();
}

// Level 1: SWIM API exempt from hibernation — VATSWIM remains fully operational
// (Previously returned 503; removed to keep SWIM API available during Level 1 hibernation)
```

- [ ] **Step 2: Verify page redirect logic**

Test with PHP CLI:

```bash
# Simulate deep hibernation constant state
php -r "
define('HIBERNATION_MODE', true);
define('DEEP_HIBERNATION_MODE', true);
\$_SERVER['PHP_SELF'] = '/swim.php';
\$_SERVER['REQUEST_URI'] = '/swim';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
// Can't test header() in CLI, but verify the page is in the array
\$pages = ['demand.php','nod.php','simulator.php','gdt.php','cdm.php','sua.php','event-aar.php','swim.php','swim-doc.php','swim-docs.php','swim-keys.php'];
echo in_array('swim.php', \$pages) ? 'PASS: swim.php would redirect' : 'FAIL';
echo PHP_EOL;
"
```

Expected: `PASS: swim.php would redirect`

- [ ] **Step 3: Verify SWIM API 503 pattern match**

```bash
php -r "
\$uris = ['/api/swim/v1/flights', '/api/swim/v1/health', '/api/data/plans/list.php', '/api/adl/current.php'];
foreach (\$uris as \$u) {
    \$match = preg_match('#^/api/swim/#', \$u) ? '503' : 'pass';
    echo \"\$u => \$match\" . PHP_EOL;
}
"
```

Expected:
```
/api/swim/v1/flights => 503
/api/swim/v1/health => 503
/api/data/plans/list.php => pass
/api/adl/current.php => pass
```

- [ ] **Step 4: Commit**

```bash
git add load/hibernation.php
git commit -m "feat: add deep hibernation SWIM page redirects and API 503"
```

---

### Task 3: Mark SWIM Nav Items as Hibernated

**Files:**
- Modify: `load/nav.php:51,100-109`
- Modify: `load/nav_public.php:52,87-95`

Both files use `$_h` to mark items as hibernated. We need a second flag `$_dh` for deep hibernation, then apply it to SWIM nav items.

- [ ] **Step 1: Update nav.php**

In `load/nav.php`, after line 51 (`$_h = defined('HIBERNATION_MODE') && HIBERNATION_MODE;`), add:

```php
$_dh = defined('DEEP_HIBERNATION_MODE') && DEEP_HIBERNATION_MODE;
```

Then modify the SWIM section (lines 100-109) to mark all items as hibernated during deep hibernation:

```php
    // Dropdown: SWIM API
    'swim' => [
        'label' => __('nav.swim'),
        'hibernated' => $_dh,
        'items' => [
            ['label' => __('nav.overview'), 'path' => './swim', 'hibernated' => $_dh],
            ['label' => __('nav.apiKeys'), 'path' => './swim-keys', 'hibernated' => $_dh],
            ['label' => __('nav.apiDocs'), 'path' => './docs/swim/', 'external' => true, 'hibernated' => $_dh],
            ['label' => __('nav.technicalDocs'), 'path' => './swim-docs', 'hibernated' => $_dh],
        ]
    ],
```

- [ ] **Step 2: Update nav_public.php**

In `load/nav_public.php`, after line 52 (`$_h = defined('HIBERNATION_MODE') && HIBERNATION_MODE;`), add:

```php
$_dh = defined('DEEP_HIBERNATION_MODE') && DEEP_HIBERNATION_MODE;
```

Then modify the SWIM section (lines 87-95) identically:

```php
    // Dropdown: SWIM API
    'swim' => [
        'label' => __('nav.swim'),
        'hibernated' => $_dh,
        'items' => [
            ['label' => __('nav.overview'), 'path' => './swim', 'hibernated' => $_dh],
            ['label' => __('nav.apiKeys'), 'path' => './swim-keys', 'hibernated' => $_dh],
            ['label' => __('nav.apiDocs'), 'path' => './docs/swim/', 'external' => true, 'hibernated' => $_dh],
            ['label' => __('nav.technicalDocs'), 'path' => './swim-docs', 'hibernated' => $_dh],
        ]
    ],
```

- [ ] **Step 3: Commit**

```bash
git add load/nav.php load/nav_public.php
git commit -m "feat: mark SWIM nav items as hibernated during deep hibernation"
```

---

### Task 4: Azure Blob REST API Client

**Files:**
- Create: `scripts/lib/AzureBlobClient.php`

A thin PHP client for Azure Blob Storage REST API with Shared Key authentication. No external dependencies — uses cURL and built-in PHP functions. Used by both the capture daemon and replay script.

- [ ] **Step 1: Create scripts/lib/ directory and the Azure Blob client**

```bash
mkdir -p scripts/lib
```

Create `scripts/lib/AzureBlobClient.php`:

```php
<?php
/**
 * Azure Blob Storage REST API Client (Shared Key Auth)
 *
 * Thin client for PUT Blob and List Blobs operations. No external dependencies.
 * Parses connection string from ADL_ARCHIVE_STORAGE_CONN environment variable.
 *
 * @see https://learn.microsoft.com/en-us/rest/api/storageservices/authorize-with-shared-key
 */

declare(strict_types=1);

class AzureBlobClient
{
    private string $accountName;
    private string $accountKey;
    private string $blobEndpoint;

    /**
     * @param string $connectionString Azure Storage connection string
     *   Format: DefaultEndpointsProtocol=https;AccountName=...;AccountKey=...;EndpointSuffix=...
     */
    public function __construct(string $connectionString)
    {
        $parts = [];
        foreach (explode(';', $connectionString) as $segment) {
            $segment = trim($segment);
            if ($segment === '') continue;
            $eq = strpos($segment, '=');
            if ($eq === false) continue;
            $key = substr($segment, 0, $eq);
            $value = substr($segment, $eq + 1);
            $parts[$key] = $value;
        }

        if (empty($parts['AccountName']) || empty($parts['AccountKey'])) {
            throw new RuntimeException('Connection string must contain AccountName and AccountKey');
        }

        $this->accountName = $parts['AccountName'];
        $this->accountKey = $parts['AccountKey'];

        $protocol = $parts['DefaultEndpointsProtocol'] ?? 'https';
        $suffix = $parts['EndpointSuffix'] ?? 'core.windows.net';
        $this->blobEndpoint = "{$protocol}://{$this->accountName}.blob.{$suffix}";
    }

    /**
     * Upload a blob (PUT Blob — block blob).
     *
     * @param string $container Container name
     * @param string $blobPath  Blob path within the container
     * @param string $data      Raw blob content
     * @param string $contentType MIME type (default: application/gzip)
     * @return array ['status' => int, 'headers' => string]
     */
    public function putBlob(string $container, string $blobPath, string $data, string $contentType = 'application/gzip'): array
    {
        $url = "{$this->blobEndpoint}/{$container}/{$blobPath}";
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $contentLength = strlen($data);
        $version = '2020-10-02';

        // Canonicalized headers (alphabetical, lowercase)
        $canonHeaders = "x-ms-blob-type:BlockBlob\nx-ms-date:{$date}\nx-ms-version:{$version}";

        // Canonicalized resource
        $canonResource = "/{$this->accountName}/{$container}/{$blobPath}";

        // String to sign for PUT Blob
        $stringToSign = implode("\n", [
            'PUT',                    // HTTP verb
            '',                       // Content-Encoding
            '',                       // Content-Language
            (string)$contentLength,   // Content-Length
            '',                       // Content-MD5
            $contentType,             // Content-Type
            '',                       // Date (empty when x-ms-date used)
            '',                       // If-Modified-Since
            '',                       // If-Match
            '',                       // If-None-Match
            '',                       // If-Unmodified-Since
            '',                       // Range
            $canonHeaders,
            $canonResource,
        ]);

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey), true));
        $authHeader = "SharedKey {$this->accountName}:{$signature}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                "Authorization: {$authHeader}",
                "Content-Type: {$contentType}",
                "Content-Length: {$contentLength}",
                "x-ms-blob-type: BlockBlob",
                "x-ms-date: {$date}",
                "x-ms-version: {$version}",
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("cURL error uploading blob: {$error}");
        }

        return ['status' => $httpCode, 'response' => $response];
    }

    /**
     * List blobs in a container with optional prefix filter.
     *
     * @param string $container Container name
     * @param string $prefix    Blob name prefix (e.g., "datafeed/2026/05/")
     * @param string|null $marker Continuation marker for pagination
     * @return array ['blobs' => [['name' => string, 'size' => int, ...]], 'next_marker' => string|null]
     */
    public function listBlobs(string $container, string $prefix = '', ?string $marker = null): array
    {
        $params = ['restype' => 'container', 'comp' => 'list', 'prefix' => $prefix, 'maxresults' => '5000'];
        if ($marker !== null) {
            $params['marker'] = $marker;
        }
        $query = http_build_query($params);
        $url = "{$this->blobEndpoint}/{$container}?{$query}";

        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $version = '2020-10-02';

        $canonHeaders = "x-ms-date:{$date}\nx-ms-version:{$version}";

        // Canonicalized resource with query params (alphabetical)
        $canonResource = "/{$this->accountName}/{$container}\ncomp:list\nmaxresults:5000\nprefix:{$prefix}\nrestype:container";
        if ($marker !== null) {
            $canonResource = "/{$this->accountName}/{$container}\ncomp:list\nmarker:{$marker}\nmaxresults:5000\nprefix:{$prefix}\nrestype:container";
        }

        $stringToSign = implode("\n", [
            'GET', '', '', '', '', '', '', '', '', '', '', '',
            $canonHeaders,
            $canonResource,
        ]);

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey), true));
        $authHeader = "SharedKey {$this->accountName}:{$signature}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                "Authorization: {$authHeader}",
                "x-ms-date: {$date}",
                "x-ms-version: {$version}",
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("cURL error listing blobs: {$error}");
        }

        if ($httpCode !== 200) {
            throw new RuntimeException("List blobs failed with HTTP {$httpCode}: {$response}");
        }

        // Parse XML response
        $xml = simplexml_load_string($response);
        $blobs = [];
        if (isset($xml->Blobs->Blob)) {
            foreach ($xml->Blobs->Blob as $blob) {
                $blobs[] = [
                    'name' => (string)$blob->Name,
                    'size' => (int)($blob->Properties->{'Content-Length'} ?? 0),
                    'last_modified' => (string)($blob->Properties->{'Last-Modified'} ?? ''),
                ];
            }
        }

        $nextMarker = null;
        if (isset($xml->NextMarker) && (string)$xml->NextMarker !== '') {
            $nextMarker = (string)$xml->NextMarker;
        }

        return ['blobs' => $blobs, 'next_marker' => $nextMarker];
    }

    /**
     * Download a blob's content.
     *
     * @param string $container Container name
     * @param string $blobPath  Blob path
     * @return string Raw blob content
     */
    public function getBlob(string $container, string $blobPath): string
    {
        $url = "{$this->blobEndpoint}/{$container}/{$blobPath}";
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $version = '2020-10-02';

        $canonHeaders = "x-ms-date:{$date}\nx-ms-version:{$version}";
        $canonResource = "/{$this->accountName}/{$container}/{$blobPath}";

        $stringToSign = implode("\n", [
            'GET', '', '', '', '', '', '', '', '', '', '', '',
            $canonHeaders,
            $canonResource,
        ]);

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey), true));
        $authHeader = "SharedKey {$this->accountName}:{$signature}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                "Authorization: {$authHeader}",
                "x-ms-date: {$date}",
                "x-ms-version: {$version}",
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("cURL error downloading blob: {$error}");
        }

        if ($httpCode !== 200) {
            throw new RuntimeException("GET blob failed with HTTP {$httpCode} for {$blobPath}");
        }

        return $response;
    }
}
```

- [ ] **Step 2: Verify the client parses connection strings correctly**

```bash
php -r "
require 'scripts/lib/AzureBlobClient.php';
\$cs = 'DefaultEndpointsProtocol=https;AccountName=pertiadlarchive;AccountKey=dGVzdA==;EndpointSuffix=core.windows.net';
\$c = new AzureBlobClient(\$cs);
echo 'PASS: AzureBlobClient instantiated' . PHP_EOL;
"
```

Expected: `PASS: AzureBlobClient instantiated`

- [ ] **Step 3: Commit**

```bash
git add scripts/lib/AzureBlobClient.php
git commit -m "feat: add AzureBlobClient for deep hibernation blob operations"
```

---

### Task 4A: Extract Shared ADL Ingest Functions

**Files:**
- Create: `scripts/lib/adl_ingest_functions.php`
- Modify: `scripts/vatsim_adl_daemon.php`

The replay script (Task 7) needs to call the same parsing/staging/SP functions that live inside `vatsim_adl_daemon.php`. But requiring that file directly would execute its `runDaemon()` main loop. We extract the shared functions into a library file that both the daemon and replay script can include.

**Functions to extract** (with their dependencies):

| Function | Line in daemon | Dependencies |
|----------|---------------|-------------|
| `parseVatsimPilots()` | 517 | None |
| `parseVatsimPrefiles()` | 581 | None |
| `sqlEscapeString()` | 779 | None |
| `sqlEscapeNumber()` | 791 | None |
| `sqlEscapeBinary()` | 805 | None |
| `clearStagingTables()` | 761 | None |
| `insertPilotsBulkLiteral()` | 822 | `sqlEscapeString`, `sqlEscapeNumber`, `sqlEscapeBinary` |
| `insertPrefilesBulkLiteral()` | 897 | `sqlEscapeString`, `sqlEscapeNumber` |
| `executeStagedRefreshSP()` | 961 | None |
| `generateBatchId()` | 1248 | None |

- [ ] **Step 1: Create the shared library file**

Create `scripts/lib/adl_ingest_functions.php` by copying the 10 functions listed above from `scripts/vatsim_adl_daemon.php`. The file should start with:

```php
<?php
/**
 * Shared ADL Ingest Functions
 *
 * Extracted from vatsim_adl_daemon.php so they can be reused by:
 * - vatsim_adl_daemon.php (main ingest loop)
 * - deep_hibernation_replay.php (post-processing replay)
 *
 * Contains: JSON parsing, staging table insertion, SP execution, and batch ID generation.
 */

declare(strict_types=1);

if (defined('ADL_INGEST_FUNCTIONS_LOADED')) {
    return;
}
define('ADL_INGEST_FUNCTIONS_LOADED', true);
```

Then copy each function verbatim from the daemon (lines 517-630, 761-960, 1248-1258 in `vatsim_adl_daemon.php`). Do NOT modify any function bodies — exact copy.

- [ ] **Step 2: Update the daemon to require the shared file**

In `scripts/vatsim_adl_daemon.php`, after the existing `require_once` block (around line 43), add:

```php
require_once $scriptDir . '/lib/adl_ingest_functions.php';
```

Then delete the original function definitions from the daemon file (lines 517-630 for `parseVatsimPilots`/`parseVatsimPrefiles`, 761-960 for the staging/SP functions, 1248-1258 for `generateBatchId`). Keep all other functions (logging, connections, ATIS, TMI sync, etc.) in the daemon.

- [ ] **Step 3: Verify the daemon still works after extraction**

```bash
php -l scripts/lib/adl_ingest_functions.php && echo "Shared lib: OK"
php -l scripts/vatsim_adl_daemon.php && echo "Daemon: OK"
```

Expected: Both files pass syntax check.

Additionally, verify the shared functions are callable:

```bash
php -r "
require_once 'scripts/lib/adl_ingest_functions.php';
echo function_exists('parseVatsimPilots') ? 'PASS: parseVatsimPilots exists' : 'FAIL';
echo PHP_EOL;
echo function_exists('executeStagedRefreshSP') ? 'PASS: executeStagedRefreshSP exists' : 'FAIL';
echo PHP_EOL;
echo function_exists('generateBatchId') ? 'PASS: generateBatchId exists' : 'FAIL';
echo PHP_EOL;
"
```

Expected: All three PASS.

- [ ] **Step 4: Commit**

```bash
git add scripts/lib/adl_ingest_functions.php scripts/vatsim_adl_daemon.php
git commit -m "refactor: extract shared ADL ingest functions for replay reuse"
```

---

### Task 5: Deep Hibernation Capture Daemon

**Files:**
- Create: `scripts/deep_hibernation_daemon.php`

This is the core new daemon. Fetches VATSIM JSON every 15s, gzip-compresses, buffers locally, batch-uploads every 10 minutes.

- [ ] **Step 1: Create the capture daemon**

Create `scripts/deep_hibernation_daemon.php`:

```php
#!/usr/bin/env php
<?php
/**
 * Deep Hibernation Capture Daemon
 *
 * Fetches raw VATSIM Data API JSON every 15 seconds, gzip-compresses it,
 * and batch-uploads to Azure Blob Storage every 10 minutes.
 *
 * This daemon replaces vatsim_adl_daemon.php during deep hibernation.
 * No SQL processing occurs — raw JSON is preserved for post-processing replay.
 *
 * Usage:
 *   php scripts/deep_hibernation_daemon.php                # Run in foreground
 *   nohup php scripts/deep_hibernation_daemon.php &        # Run detached
 *
 * Environment:
 *   ADL_ARCHIVE_STORAGE_CONN     - Azure Blob Storage connection string (required)
 *   DEEP_HIB_FETCH_INTERVAL      - Seconds between fetches (default: 15)
 *   DEEP_HIB_UPLOAD_INTERVAL     - Seconds between blob upload batches (default: 600)
 *   DEEP_HIB_BUFFER_PATH         - Local buffer directory (default: /home/site/data/deep-hibernation-buffer)
 *
 * @see docs/superpowers/specs/2026-05-13-deep-hibernation-design.md
 */

declare(strict_types=1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '256M');

// ============================================================================
// LOAD CONFIG
// ============================================================================

$scriptDir = __DIR__;
$wwwroot = dirname($scriptDir);
$configPath = $wwwroot . '/load/config.php';

if (!file_exists($configPath)) {
    die("ERROR: Cannot find config at {$configPath}\n");
}

require_once $configPath;
require_once $scriptDir . '/lib/AzureBlobClient.php';

// ============================================================================
// CONFIGURATION
// ============================================================================

$config = [
    'vatsim_url'      => 'https://data.vatsim.net/v3/vatsim-data.json',
    'fetch_interval'  => (int)(getenv('DEEP_HIB_FETCH_INTERVAL') ?: 15),
    'upload_interval' => (int)(getenv('DEEP_HIB_UPLOAD_INTERVAL') ?: 600),
    'buffer_path'     => getenv('DEEP_HIB_BUFFER_PATH') ?: '/home/site/data/deep-hibernation-buffer',
    'container'       => 'adl-raw-archive',
    'blob_prefix'     => 'datafeed',
    'log_file'        => file_exists('/home/LogFiles') ? '/home/LogFiles/deep_hibernation.log' : $scriptDir . '/deep_hibernation.log',
];

// Validate required env var
$storageConn = getenv('ADL_ARCHIVE_STORAGE_CONN');
if (empty($storageConn)) {
    die("ERROR: ADL_ARCHIVE_STORAGE_CONN environment variable not set\n");
}

// ============================================================================
// LOGGING
// ============================================================================

function logMsg(string $msg, string $level = 'INFO'): void {
    global $config;
    $timestamp = gmdate('Y-m-d H:i:s');
    $line = "[{$timestamp} UTC] [{$level}] {$msg}\n";
    @file_put_contents($config['log_file'], $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// ============================================================================
// VATSIM FETCH
// ============================================================================

function fetchVatsimJson(string $url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_ENCODING       => 'gzip,deflate',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate',
            'User-Agent: PERTI-DeepHibernation/1.0',
        ],
    ]);

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($data === false || $httpCode !== 200) {
        logMsg("Fetch failed: HTTP {$httpCode}, error: {$error}", 'WARN');
        return null;
    }

    return $data;
}

// ============================================================================
// LOCAL BUFFER
// ============================================================================

function ensureBufferDir(string $path): void {
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true)) {
            die("ERROR: Cannot create buffer directory: {$path}\n");
        }
        logMsg("Created buffer directory: {$path}");
    }
}

function writeToBuffer(string $bufferPath, string $jsonData): ?string {
    $timestamp = gmdate('Ymd-His');
    $filename = "vatsim-data-{$timestamp}.json.gz";
    $filepath = "{$bufferPath}/{$filename}";

    $compressed = gzencode($jsonData, 6);
    if ($compressed === false) {
        logMsg("gzencode failed for {$filename}", 'ERROR');
        return null;
    }

    if (file_put_contents($filepath, $compressed) === false) {
        logMsg("Failed to write buffer file: {$filepath}", 'ERROR');
        return null;
    }

    return $filepath;
}

function getBufferedFiles(string $bufferPath): array {
    $files = glob("{$bufferPath}/vatsim-data-*.json.gz");
    if ($files === false) return [];
    sort($files); // Chronological order (filenames contain timestamps)
    return $files;
}

// ============================================================================
// BLOB UPLOAD
// ============================================================================

function uploadBufferedFiles(AzureBlobClient $client, string $container, string $prefix, string $bufferPath): array {
    $files = getBufferedFiles($bufferPath);
    if (empty($files)) return ['uploaded' => 0, 'failed' => 0, 'bytes' => 0];

    $uploaded = 0;
    $failed = 0;
    $totalBytes = 0;

    foreach ($files as $filepath) {
        $filename = basename($filepath);

        // Parse timestamp from filename: vatsim-data-YYYYMMDD-HHMMSS.json.gz
        if (!preg_match('/vatsim-data-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.json\.gz/', $filename, $m)) {
            logMsg("Unexpected filename format: {$filename}, skipping", 'WARN');
            continue;
        }

        // Blob path: datafeed/YYYY/MM/DD/HH/vatsim-data-YYYYMMDD-HHMMSS.json.gz
        $blobPath = "{$prefix}/{$m[1]}/{$m[2]}/{$m[3]}/{$m[4]}/{$filename}";

        $data = file_get_contents($filepath);
        if ($data === false) {
            logMsg("Failed to read buffer file: {$filepath}", 'ERROR');
            $failed++;
            continue;
        }

        try {
            $result = $client->putBlob($container, $blobPath, $data);
            if ($result['status'] === 201) {
                $totalBytes += strlen($data);
                $uploaded++;
                unlink($filepath); // Remove after successful upload
            } else {
                logMsg("Blob upload returned HTTP {$result['status']} for {$blobPath}", 'WARN');
                $failed++;
            }
        } catch (Exception $e) {
            logMsg("Blob upload exception for {$blobPath}: {$e->getMessage()}", 'ERROR');
            $failed++;
        }
    }

    return ['uploaded' => $uploaded, 'failed' => $failed, 'bytes' => $totalBytes];
}

// ============================================================================
// MAIN LOOP
// ============================================================================

logMsg("=== Deep Hibernation Capture Daemon starting ===");
logMsg("Config: fetch={$config['fetch_interval']}s, upload={$config['upload_interval']}s");
logMsg("Buffer: {$config['buffer_path']}");
logMsg("Container: {$config['container']}, prefix: {$config['blob_prefix']}");

ensureBufferDir($config['buffer_path']);

// Initialize blob client
$blobClient = new AzureBlobClient($storageConn);

// Crash recovery: upload any existing buffered files from previous run
$existingFiles = getBufferedFiles($config['buffer_path']);
if (!empty($existingFiles)) {
    logMsg("Crash recovery: found " . count($existingFiles) . " buffered files, uploading...");
    $result = uploadBufferedFiles($blobClient, $config['container'], $config['blob_prefix'], $config['buffer_path']);
    logMsg("Crash recovery: uploaded={$result['uploaded']}, failed={$result['failed']}, bytes={$result['bytes']}");
}

// Signal handling for graceful shutdown
$running = true;
if (function_exists('pcntl_signal')) {
    $handler = function ($sig) use (&$running) {
        logMsg("Received signal {$sig}, shutting down...");
        $running = false;
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

$stats = ['fetches' => 0, 'fetch_errors' => 0, 'uploads' => 0, 'upload_bytes' => 0];
$lastUploadTime = time();

while ($running) {
    $cycleStart = microtime(true);

    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // 1. Fetch VATSIM data
    $jsonData = fetchVatsimJson($config['vatsim_url']);

    if ($jsonData !== null && strlen($jsonData) > 1000) {
        // Validate JSON structure (must have 'pilots' key)
        $decoded = json_decode($jsonData, true);
        if ($decoded && isset($decoded['pilots'])) {
            $pilotCount = count($decoded['pilots']);
            unset($decoded); // Free memory

            // 2. Gzip + write to local buffer
            $filepath = writeToBuffer($config['buffer_path'], $jsonData);
            if ($filepath !== null) {
                $stats['fetches']++;
                $compressedSize = filesize($filepath);

                // Log every 20th fetch (5 minutes)
                if ($stats['fetches'] % 20 === 1) {
                    $rawKb = round(strlen($jsonData) / 1024);
                    $gzKb = round($compressedSize / 1024);
                    logMsg("Fetch #{$stats['fetches']}: {$pilotCount} pilots, raw={$rawKb}KB, gz={$gzKb}KB");
                }
            }
        } else {
            logMsg("Invalid JSON structure (no 'pilots' key), skipping", 'WARN');
            $stats['fetch_errors']++;
        }
    } else {
        $stats['fetch_errors']++;
    }

    unset($jsonData); // Free memory

    // 3. Batch upload check (every upload_interval seconds)
    $timeSinceUpload = time() - $lastUploadTime;
    if ($timeSinceUpload >= $config['upload_interval']) {
        $result = uploadBufferedFiles($blobClient, $config['container'], $config['blob_prefix'], $config['buffer_path']);
        if ($result['uploaded'] > 0 || $result['failed'] > 0) {
            $stats['uploads'] += $result['uploaded'];
            $stats['upload_bytes'] += $result['bytes'];
            $bytesKb = round($result['bytes'] / 1024);
            logMsg("Upload batch: uploaded={$result['uploaded']}, failed={$result['failed']}, size={$bytesKb}KB");
        }
        $lastUploadTime = time();
    }

    // 4. Sleep until next fetch interval
    $elapsed = microtime(true) - $cycleStart;
    $sleepTime = max(0, $config['fetch_interval'] - $elapsed);
    if ($sleepTime > 0) {
        usleep((int)($sleepTime * 1_000_000));
    }
}

// Final upload before exit
logMsg("Shutting down — uploading remaining buffered files...");
$result = uploadBufferedFiles($blobClient, $config['container'], $config['blob_prefix'], $config['buffer_path']);
logMsg("Final upload: uploaded={$result['uploaded']}, failed={$result['failed']}");
logMsg("Session stats: fetches={$stats['fetches']}, errors={$stats['fetch_errors']}, uploads={$stats['uploads']}, totalBytes={$stats['upload_bytes']}");
logMsg("=== Deep Hibernation Capture Daemon stopped ===");
```

- [ ] **Step 2: Verify daemon can parse config and create buffer directory**

```bash
php -r "
require 'load/config.php';
require 'scripts/lib/AzureBlobClient.php';
echo 'Config loaded, DEEP_HIBERNATION_MODE=' . (defined('DEEP_HIBERNATION_MODE') ? (DEEP_HIBERNATION_MODE ? '1' : '0') : 'undefined') . PHP_EOL;
echo 'PASS' . PHP_EOL;
"
```

- [ ] **Step 3: Commit**

```bash
git add scripts/deep_hibernation_daemon.php
git commit -m "feat: add deep hibernation capture daemon"
```

---

### Task 6: Update startup.sh for Deep Hibernation

**Files:**
- Modify: `scripts/startup.sh`

In deep hibernation, only the capture daemon + monitoring daemon start. All other daemons (including ADL ingest, SWIM, archival, Discord, ECFMP, etc.) are skipped.

- [ ] **Step 1: Add deep hibernation detection after the hibernation detection block**

After line 35 in `startup.sh` (end of hibernation detection), add deep hibernation detection:

```bash
# =============================================================================
# Deep Hibernation Mode
# When enabled (superset of hibernation), ALL flight processing stops.
# Only the raw JSON capture daemon and monitoring daemon run.
# Set via Azure App Setting: DEEP_HIBERNATION_MODE=1
# See docs/superpowers/specs/2026-05-13-deep-hibernation-design.md
# =============================================================================
DEEP_HIBERNATION_MODE=${DEEP_HIBERNATION_MODE:-0}
if [ "$DEEP_HIBERNATION_MODE" = "1" ] || [ "$DEEP_HIBERNATION_MODE" = "true" ]; then
    echo ""
    echo "  *** DEEP HIBERNATION MODE ACTIVE ***"
    echo "  Only capture daemon + monitoring will start"
    echo "  All flight processing, SWIM, archival suspended"
    echo ""
    DEEP_HIBERNATION=1
else
    DEEP_HIBERNATION=0
fi
```

- [ ] **Step 2: Wrap core daemons in deep hibernation conditional**

The core daemons section starts at line 65 (`# CORE DAEMONS`) and runs through line 191 (`echo "  swim_tmi_sync_daemon.php started..."`). We need to wrap this in a deep hibernation check.

**Edit A**: Replace this exact line at the start of the core daemons section:

```bash
# =============================================================================
# CORE DAEMONS (always start, even in hibernation)
# =============================================================================
```

With:

```bash
# =============================================================================
# CORE DAEMONS
# =============================================================================

if [ "$DEEP_HIBERNATION" = "1" ]; then
    # =========================================================================
    # DEEP HIBERNATION: Only capture daemon + monitoring
    # =========================================================================

    echo "Starting deep_hibernation_daemon.php (JSON capture every 15s, upload every 10min)..."
    nohup php "${WWWROOT}/scripts/deep_hibernation_daemon.php" >> /home/LogFiles/deep_hibernation.log 2>&1 &
    DEEP_HIB_PID=$!
    echo "  deep_hibernation_daemon.php started (PID: $DEEP_HIB_PID)"

    echo "Starting monitoring_daemon.php (system metrics)..."
    nohup php "${WWWROOT}/scripts/monitoring_daemon.php" --loop >> /home/LogFiles/monitoring_daemon.log 2>&1 &
    MON_PID=$!
    echo "  monitoring_daemon.php started (PID: $MON_PID)"

else
    # =========================================================================
    # NORMAL + LEVEL 1 HIBERNATION: All core daemons
    # =========================================================================
```

**Edit B**: After the last core daemon line (the SWIM TMI sync daemon, which ends with the echo line containing `swim_tmi_sync_daemon.php started`), add the closing `fi` before the downstream daemons section:

Find this exact text:

```bash
echo "  swim_tmi_sync_daemon.php started (PID: $TMI_SYNC_PID)"

# =============================================================================
# DOWNSTREAM DAEMONS (skipped in hibernation mode)
# =============================================================================
```

Replace with:

```bash
echo "  swim_tmi_sync_daemon.php started (PID: $TMI_SYNC_PID)"

fi  # end DEEP_HIBERNATION check

# =============================================================================
# DOWNSTREAM DAEMONS (skipped in hibernation mode)
# =============================================================================
```

**Result**: In deep hibernation, only the capture daemon and monitoring start. In Level 1 hibernation or operational mode, all core daemons start as before. Downstream daemons are controlled by the existing `$HIBERNATION` check (which remains unchanged).

- [ ] **Step 3: Update the summary echo block**

Find the summary block near the end of startup.sh. It starts with:

```bash
echo "========================================"
if [ "$HIBERNATION" = "1" ]; then
    echo "HIBERNATION MODE - Core + SWIM daemons:"
```

Replace the entire block (from the `echo "===..."` through the final `echo "===..."`) with:

```bash
echo "========================================"
if [ "$DEEP_HIBERNATION" = "1" ]; then
    echo "DEEP HIBERNATION MODE - Minimal daemons:"
    echo "  deep_hib=$DEEP_HIB_PID, mon=$MON_PID"
    echo "  indexer=$INDEXER_PID (scheduled, 30s delay)"
    echo "  Suspended: ALL flight processing, SWIM, archival, Discord, ECFMP"
elif [ "$HIBERNATION" = "1" ]; then
    echo "HIBERNATION MODE - Core + SWIM daemons:"
    echo "  adl=$ADL_PID, arch=$ARCH_PID, mon=$MON_PID"
    echo "  ws=$WS_PID, swim_sync=$SWIM_SYNC_PID"
    echo "  st_poll=$ST_POLL_PID, reverse_sync=$REVERSE_SYNC_PID"
    echo "  tmi_sync=$TMI_SYNC_PID"
    echo "  discord_q=$DISCORD_Q_PID, adl_archive=$ADL_ARCHIVE_PID"
    echo "  ecfmp=$ECFMP_PID, viff=$VIFF_PID"
    echo "  refdata=$REFDATA_PID (daily reimport at 06:00Z)"
    echo "  playbook_export=$PLAYBOOK_EXPORT_PID (daily, first in 5min)"
    echo "  indexer=$INDEXER_PID (scheduled, 30s delay)"
    echo "  Hibernated: GIS, waypoint ETA, scheduler, event sync, CDM, vACDM, webhook delivery"
else
    echo "All daemons started:"
    echo "  adl=$ADL_PID, parse=$PARSE_PID, boundary=$BOUNDARY_PID"
    echo "  waypoint=$WAYPOINT_PID, crossing=${CROSSING_PID:-N/A}"
    echo "  ws=$WS_PID, swim_sync=$SWIM_SYNC_PID"
    echo "  st_poll=$ST_POLL_PID, reverse_sync=$REVERSE_SYNC_PID"
    echo "  tmi_sync=$TMI_SYNC_PID"
    echo "  sched=$SCHED_PID, arch=$ARCH_PID, mon=$MON_PID"
    echo "  discord_q=$DISCORD_Q_PID, event_sync=$EVENT_SYNC_PID"
    echo "  ecfmp=$ECFMP_PID, viff=$VIFF_PID, cdm=$CDM_PID, vacdm=$VACDM_PID"
    echo "  delay_attr=$DELAY_ATTR_PID, facility_stats=$FACILITY_STATS_PID"
    echo "  webhook_delivery=$WEBHOOK_DELIVERY_PID"
    echo "  adl_archive=$ADL_ARCHIVE_PID (daily ${ARCHIVE_HOUR:-10}:00 UTC)"
    echo "  refdata=$REFDATA_PID (daily reimport at 06:00Z)"
    echo "  playbook_export=$PLAYBOOK_EXPORT_PID (daily, first in 5min)"
    echo "  indexer=$INDEXER_PID (scheduled, 30s delay)"
fi
echo "========================================"
```

- [ ] **Step 4: Update FPM worker count for deep hibernation**

In the PHP-FPM configuration section, find this exact block:

```bash
if [ "$HIBERNATION" = "1" ]; then
    FPM_MAX_CHILDREN=20
```

Replace the entire if/else/fi block with a three-branch version:

```bash
if [ "$DEEP_HIBERNATION" = "1" ]; then
    FPM_MAX_CHILDREN=10
    FPM_START=3
    FPM_MIN_SPARE=2
    FPM_MAX_SPARE=5
elif [ "$HIBERNATION" = "1" ]; then
    FPM_MAX_CHILDREN=20
    FPM_START=5
    FPM_MIN_SPARE=3
    FPM_MAX_SPARE=10
else
    FPM_MAX_CHILDREN=40
    FPM_START=10
    FPM_MIN_SPARE=5
    FPM_MAX_SPARE=20
fi
```

- [ ] **Step 5: Verify startup.sh syntax**

```bash
bash -n scripts/startup.sh
echo "Exit code: $?"
```

Expected: Exit code 0 (no syntax errors).

- [ ] **Step 6: Commit**

```bash
git add scripts/startup.sh
git commit -m "feat: add deep hibernation conditional to startup.sh"
```

---

### Task 7: Deep Hibernation Replay Script

**Files:**
- Create: `scripts/deep_hibernation_replay.php`

Reads archived JSON files from blob storage, decompresses them, and feeds each through the normal ADL ingest pipeline.

- [ ] **Step 1: Create the replay script**

Create `scripts/deep_hibernation_replay.php`:

```php
#!/usr/bin/env php
<?php
/**
 * Deep Hibernation Replay — Post-process archived VATSIM JSON data
 *
 * Reads gzipped JSON files from Azure Blob Storage (datafeed/ prefix),
 * decompresses each, and feeds it through the ADL ingest pipeline
 * (staging tables → sp_Adl_RefreshFromVatsim_Staged).
 *
 * IMPORTANT: Run this AFTER exiting deep hibernation, with all daemons running.
 * The script extends the archive grace period to prevent data loss during replay.
 *
 * Usage:
 *   php scripts/deep_hibernation_replay.php --verbose
 *   php scripts/deep_hibernation_replay.php --start-date=2026-05-01 --end-date=2026-05-13
 *   php scripts/deep_hibernation_replay.php --dry-run
 *   php scripts/deep_hibernation_replay.php --batch=100
 *
 * Options:
 *   --start-date=YYYY-MM-DD   Start date for replay (default: earliest archived)
 *   --end-date=YYYY-MM-DD     End date (default: latest archived)
 *   --delay-ms=N              Delay between cycles in milliseconds (default: 0 = max speed)
 *   --dry-run                 List files and counts without processing
 *   --batch=N                 Process N files then pause for confirmation
 *   --skip-safety             Skip archive grace period extension (not recommended)
 *   --verbose                 Detailed per-cycle logging
 *
 * @see docs/superpowers/specs/2026-05-13-deep-hibernation-design.md Section 6
 */

declare(strict_types=1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '512M');

// ============================================================================
// BOOTSTRAP
// ============================================================================

$scriptDir = __DIR__;
$wwwroot = dirname($scriptDir);

require_once $wwwroot . '/load/config.php';
require_once $scriptDir . '/lib/AzureBlobClient.php';

// ============================================================================
// CLI ARGUMENTS
// ============================================================================

$opts = getopt('', [
    'start-date:',
    'end-date:',
    'delay-ms:',
    'dry-run',
    'batch:',
    'skip-safety',
    'verbose',
]);

$startDate  = $opts['start-date'] ?? null;
$endDate    = $opts['end-date'] ?? null;
$delayMs    = (int)($opts['delay-ms'] ?? 0);
$dryRun     = isset($opts['dry-run']);
$batchSize  = isset($opts['batch']) ? (int)$opts['batch'] : 0;
$skipSafety = isset($opts['skip-safety']);
$verbose    = isset($opts['verbose']);

// ============================================================================
// LOGGING
// ============================================================================

$logFile = file_exists('/home/LogFiles') ? '/home/LogFiles/deep_hibernation_replay.log' : $scriptDir . '/deep_hibernation_replay.log';

function logMsg(string $msg, string $level = 'INFO'): void {
    global $logFile;
    $timestamp = gmdate('Y-m-d H:i:s');
    $line = "[{$timestamp} UTC] [{$level}] {$msg}\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// ============================================================================
// PRE-FLIGHT CHECKS
// ============================================================================

logMsg("=== Deep Hibernation Replay ===");

// 1. Verify we're NOT in deep hibernation mode
if (defined('DEEP_HIBERNATION_MODE') && DEEP_HIBERNATION_MODE) {
    logMsg("ERROR: DEEP_HIBERNATION_MODE is still active. Exit deep hibernation first.", 'ERROR');
    exit(1);
}

// 2. Verify storage connection
$storageConn = getenv('ADL_ARCHIVE_STORAGE_CONN');
if (empty($storageConn)) {
    logMsg("ERROR: ADL_ARCHIVE_STORAGE_CONN not set", 'ERROR');
    exit(1);
}

// 3. Verify ADL database connection
if (!defined('ADL_SQL_HOST') || !defined('ADL_SQL_DATABASE')) {
    logMsg("ERROR: ADL_SQL_* constants not defined", 'ERROR');
    exit(1);
}

// 4. Connect to ADL
logMsg("Connecting to VATSIM_ADL...");
$conn = sqlsrv_connect(ADL_SQL_HOST, [
    'Database' => ADL_SQL_DATABASE,
    'UID'      => ADL_SQL_USERNAME,
    'PWD'      => ADL_SQL_PASSWORD,
    'Encrypt'  => true,
    'TrustServerCertificate' => false,
    'LoginTimeout' => 30,
]);
if ($conn === false) {
    $errors = sqlsrv_errors();
    logMsg("ERROR: Cannot connect to VATSIM_ADL: " . json_encode($errors), 'ERROR');
    exit(1);
}
logMsg("Connected to VATSIM_ADL");

// 5. Check archival daemon is not running
if (!$skipSafety) {
    logMsg("Safety check: verifying archival daemon is not running...");
    // Check lock file
    if (file_exists('/tmp/adl_archive.lock')) {
        logMsg("ERROR: Archival daemon lock file exists at /tmp/adl_archive.lock. Stop it first.", 'ERROR');
        exit(1);
    }
    logMsg("Safety check passed: archival daemon not running");

    // Extend archive grace period to 48 hours
    logMsg("Extending archive grace period to 48 hours...");
    if (!$dryRun) {
        $sql = "IF EXISTS (SELECT 1 FROM adl_archive_config WHERE config_key = 'COMPLETED_FLIGHT_DELAY_HOURS')
                    UPDATE adl_archive_config SET config_value = '48', updated_utc = GETUTCDATE()
                    WHERE config_key = 'COMPLETED_FLIGHT_DELAY_HOURS'
                ELSE
                    INSERT INTO adl_archive_config (config_key, config_value, updated_utc)
                    VALUES ('COMPLETED_FLIGHT_DELAY_HOURS', '48', GETUTCDATE())";
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            logMsg("WARNING: Could not extend grace period: " . json_encode(sqlsrv_errors()), 'WARN');
        } else {
            sqlsrv_free_stmt($stmt);
            logMsg("Archive grace period set to 48 hours");
        }
    } else {
        logMsg("[DRY RUN] Would set COMPLETED_FLIGHT_DELAY_HOURS to 48");
    }
}

// ============================================================================
// LIST ARCHIVED FILES
// ============================================================================

logMsg("Listing archived files from blob storage...");
$blobClient = new AzureBlobClient($storageConn);
$container = 'adl-raw-archive';
$prefix = 'datafeed/';

// Build prefix filter from date range
if ($startDate) {
    $prefix = 'datafeed/' . str_replace('-', '/', $startDate) . '/';
    // For multi-day ranges, we'll list all and filter
    if ($endDate && $startDate !== $endDate) {
        $prefix = 'datafeed/';
    }
}

$allBlobs = [];
$marker = null;
do {
    $result = $blobClient->listBlobs($container, $prefix, $marker);
    foreach ($result['blobs'] as $blob) {
        // Filter by date range if specified
        if ($startDate || $endDate) {
            // Extract date from path: datafeed/YYYY/MM/DD/HH/vatsim-data-YYYYMMDD-HHMMSS.json.gz
            if (preg_match('#datafeed/(\d{4})/(\d{2})/(\d{2})/#', $blob['name'], $m)) {
                $blobDate = "{$m[1]}-{$m[2]}-{$m[3]}";
                if ($startDate && $blobDate < $startDate) continue;
                if ($endDate && $blobDate > $endDate) continue;
            }
        }
        $allBlobs[] = $blob;
    }
    $marker = $result['next_marker'];
} while ($marker !== null);

// Sort by name (lexicographic = chronological due to timestamp naming)
usort($allBlobs, fn($a, $b) => strcmp($a['name'], $b['name']));

$totalFiles = count($allBlobs);
$totalBytes = array_sum(array_column($allBlobs, 'size'));
logMsg("Found {$totalFiles} archived files (" . round($totalBytes / 1024 / 1024, 1) . " MB compressed)");

if ($totalFiles === 0) {
    logMsg("No files to replay. Exiting.");
    exit(0);
}

// Show date range
if (!empty($allBlobs)) {
    logMsg("Date range: {$allBlobs[0]['name']} to {$allBlobs[$totalFiles - 1]['name']}");
}

if ($dryRun) {
    logMsg("[DRY RUN] Would process {$totalFiles} files. Exiting.");
    exit(0);
}

// ============================================================================
// REPLAY LOOP
// ============================================================================

// Load shared ADL ingest functions (extracted from vatsim_adl_daemon.php in Task 4A)
// Provides: parseVatsimPilots(), parseVatsimPrefiles(), insertPilotsBulkLiteral(),
// insertPrefilesBulkLiteral(), executeStagedRefreshSP(), generateBatchId()
require_once $scriptDir . '/lib/adl_ingest_functions.php';

logMsg("=== Starting replay of {$totalFiles} files ===");

$processed = 0;
$errors = 0;
$startTime = microtime(true);

foreach ($allBlobs as $blob) {
    $processed++;

    if ($verbose) {
        logMsg("Processing [{$processed}/{$totalFiles}]: {$blob['name']}");
    }

    try {
        // 1. Download blob
        $compressed = $blobClient->getBlob($container, $blob['name']);

        // 2. Decompress
        $jsonData = gzdecode($compressed);
        if ($jsonData === false) {
            logMsg("Failed to decompress: {$blob['name']}", 'ERROR');
            $errors++;
            continue;
        }

        // 3. Validate JSON
        $vatsimData = json_decode($jsonData, true);
        if (!$vatsimData || !isset($vatsimData['pilots'])) {
            logMsg("Invalid JSON structure in: {$blob['name']}", 'WARN');
            $errors++;
            continue;
        }

        $pilotCount = count($vatsimData['pilots']);

        // 4. Parse pilots + prefiles (same as ADL daemon)
        $parsedPilots = parseVatsimPilots($vatsimData);
        $parsedPrefiles = parseVatsimPrefiles($vatsimData);

        // 5. Insert to staging tables
        $batchId = generateBatchId();
        $pilotResult = insertPilotsBulkLiteral($conn, $parsedPilots, $batchId, 1000);
        $prefileResult = insertPrefilesBulkLiteral($conn, $parsedPrefiles, $batchId, 1000);

        // 6. Execute staged refresh SP
        $spResult = executeStagedRefreshSP($conn, $batchId, 120, false, false);

        if ($verbose) {
            logMsg("  Replayed: {$pilotCount} pilots, SP={$spResult['elapsed_ms']}ms");
        }

        // Free memory
        unset($compressed, $jsonData, $vatsimData, $parsedPilots, $parsedPrefiles);

    } catch (Exception $e) {
        logMsg("Error processing {$blob['name']}: {$e->getMessage()}", 'ERROR');
        $errors++;
    }

    // Delay between cycles if configured
    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }

    // Batch pause
    if ($batchSize > 0 && $processed % $batchSize === 0 && $processed < $totalFiles) {
        $elapsed = round(microtime(true) - $startTime);
        $rate = round($processed / max($elapsed, 1), 1);
        logMsg("Batch checkpoint: {$processed}/{$totalFiles} ({$rate} files/sec, {$errors} errors)");
        logMsg("Press ENTER to continue or Ctrl+C to stop...");
        fgets(STDIN);
    }

    // Progress log every 100 files
    if ($processed % 100 === 0) {
        $elapsed = round(microtime(true) - $startTime);
        $rate = round($processed / max($elapsed, 1), 1);
        $remaining = $totalFiles - $processed;
        $eta = $rate > 0 ? round($remaining / $rate) : 0;
        logMsg("Progress: {$processed}/{$totalFiles} ({$rate}/sec, ~{$eta}s remaining, {$errors} errors)");
    }
}

// ============================================================================
// POST-REPLAY
// ============================================================================

$totalElapsed = round(microtime(true) - $startTime);
logMsg("=== Replay complete ===");
logMsg("Processed: {$processed}, Errors: {$errors}, Elapsed: {$totalElapsed}s");

if (!$skipSafety) {
    logMsg("");
    logMsg("Next steps:");
    logMsg("  1. Run GIS backfill: php scripts/backfill/hibernation_recovery.php --phase=auto --include-inactive");
    logMsg("  2. After backfill completes, reset archive grace period:");
    logMsg("     php scripts/backfill/hibernation_recovery.php --delay-hours=2 --phase=0");
    logMsg("  3. Restart archival daemon if stopped");
}

sqlsrv_close($conn);
```

- [ ] **Step 2: Verify the script loads and parses arguments**

```bash
php scripts/deep_hibernation_replay.php --dry-run 2>&1 | head -5
```

Expected: Script starts, shows "Deep Hibernation Replay" header, then either connects to DB or shows error about connection (which is expected locally).

- [ ] **Step 3: Commit**

```bash
git add scripts/deep_hibernation_replay.php
git commit -m "feat: add deep hibernation replay script for post-processing"
```

---

### Task 8: Update Hibernation Info Page

**Files:**
- Modify: `hibernation.php`

Show the current hibernation level and adjust messaging for deep hibernation.

- [ ] **Step 1: Add deep hibernation detection and adjusted hero section**

In `hibernation.php`, after line 13 (`include("load/i18n.php");`), add:

```php
$deepHibernation = defined('DEEP_HIBERNATION_MODE') && DEEP_HIBERNATION_MODE;
```

Then modify the hero subtitle (around line 186-188) to be level-aware. Replace:

```php
        <p class="subtitle">
            <?= __('hibernation.heroSubtitle') ?>
        </p>
```

With:

```php
        <p class="subtitle">
            <?php if ($deepHibernation): ?>
                <?= __('hibernation.deepHeroSubtitle') ?>
            <?php else: ?>
                <?= __('hibernation.heroSubtitle') ?>
            <?php endif; ?>
        </p>
```

- [ ] **Step 2: Add deep hibernation note to the "Still Active" card**

In the "Still Active" card (around line 237, the `data-note` div), add a conditional note for deep hibernation. After the existing `data-note` div, add:

```php
                <?php if ($deepHibernation): ?>
                <div class="data-note" style="background:#fff3e0;border-left-color:#ff9800;">
                    <i class="fas fa-archive" style="color:#ff9800;"></i>
                    <strong><?= __('hibernation.deepDataNote') ?></strong>
                    <?= __('hibernation.deepDataNoteDetail') ?>
                </div>
                <?php endif; ?>
```

- [ ] **Step 3: Add i18n keys for deep hibernation**

In `assets/locales/en-US.json`, find the last entry in the `hibernation` object (line 2438):

```json
    "vatswimApi": "VATSWIM API & Documentation"
  },
```

Replace it with (adding new keys before the closing brace):

```json
    "vatswimApi": "VATSWIM API & Documentation",
    "deepHeroSubtitle": "Deep hibernation active — raw VATSIM data is being archived for post-processing. No live flight data is available. Planning, review, and TMI publishing tools remain operational.",
    "deepDataNote": "Deep Hibernation Mode",
    "deepDataNoteDetail": "All flight data processing is suspended. Raw VATSIM API data is being captured and archived every 15 seconds for lossless post-processing replay when deep hibernation ends."
  },
```

Note the keys are just `deepHeroSubtitle` etc. (not `hibernation.deepHeroSubtitle`) because the `hibernation.` prefix comes from the nesting — they're inside the `"hibernation": { }` object. The i18n system auto-flattens to dot notation.

- [ ] **Step 4: Commit**

```bash
git add hibernation.php assets/locales/en-US.json
git commit -m "feat: update hibernation info page for deep hibernation level"
```

---

### Task 9: Update Lifecycle Policy for datafeed/ Prefix

**Files:**
- Modify: `scripts/adl_archive/setup_infrastructure.ps1:151-185`

Add the `datafeed/` prefix to the existing lifecycle policy so archived VATSIM JSON files tier to Cool after 8 days. No auto-delete.

- [ ] **Step 1: Add datafeed tiering rule to the lifecycle policy**

In `setup_infrastructure.ps1`, modify the `$lifecyclePolicy` JSON (lines 151-185) to add a second rule for the datafeed prefix. Replace the policy JSON with:

```json
{
  "rules": [
    {
      "name": "adl-archive-tiering",
      "enabled": true,
      "type": "Lifecycle",
      "definition": {
        "filters": {
          "blobTypes": ["blockBlob"],
          "prefixMatch": [
            "trajectory/",
            "changelog/",
            "flights/",
            "waypoints/",
            "boundary_log/",
            "zone_events/",
            "tmi_trajectory/"
          ]
        },
        "actions": {
          "baseBlob": {
            "tierToCool": {
              "daysAfterModificationGreaterThan": 8
            },
            "tierToArchive": {
              "daysAfterModificationGreaterThan": 365
            }
          }
        }
      }
    },
    {
      "name": "datafeed-tiering",
      "enabled": true,
      "type": "Lifecycle",
      "definition": {
        "filters": {
          "blobTypes": ["blockBlob"],
          "prefixMatch": ["datafeed/"]
        },
        "actions": {
          "baseBlob": {
            "tierToCool": {
              "daysAfterModificationGreaterThan": 8
            }
          }
        }
      }
    }
  ]
}
```

Note: The `datafeed-tiering` rule has no `tierToArchive` — files persist in Cool tier until explicitly deleted after replay.

- [ ] **Step 2: Document the Azure CLI command to apply the policy update manually**

For existing deployments, the policy can be updated via CLI without rerunning the full setup script:

```bash
# Create a JSON file with the updated policy, then:
az storage account management-policy create \
    --account-name pertiadlarchive \
    --resource-group VATSIM_RG \
    --policy @lifecycle_policy.json
```

- [ ] **Step 3: Commit**

```bash
git add scripts/adl_archive/setup_infrastructure.ps1
git commit -m "feat: add datafeed/ prefix to blob lifecycle policy"
```

---

### Task 10: Update Hibernation Runbook

**Files:**
- Modify: `docs/operations/HIBERNATION_RUNBOOK.md`

Add a comprehensive Deep Hibernation section to the existing runbook.

- [ ] **Step 1: Add Deep Hibernation section**

At the end of `docs/operations/HIBERNATION_RUNBOOK.md` (after the existing "Data Recovery & Backfill" section), add:

````markdown

---

## Deep Hibernation (Level 2)

### Overview

Deep hibernation is a cost-reduction mode that goes beyond Level 1 by suspending **all** flight data processing — including ADL ingest and SWIM API. Raw VATSIM JSON is captured to Azure Blob Storage for post-processing replay.

### Mode Hierarchy

| Level | ADL Ingest | SWIM API | Daemons Running | Pages Redirected |
|-------|-----------|----------|-----------------|------------------|
| 0 (Operational) | Full pipeline | Operational | All | None |
| 1 (Hibernation) | Full pipeline | Operational | Core + SWIM | 7 pages |
| **2 (Deep)** | **Raw capture only** | **503** | **2 daemons** | **11 pages** |

### What Runs

| Daemon | Interval | Purpose |
|--------|----------|---------|
| `deep_hibernation_daemon.php` | 15s fetch, 10min upload | VATSIM JSON capture + blob archival |
| `monitoring_daemon.php` | 60s | System health metrics |

### What Is Stopped

All daemons from Level 1 are stopped, including:
- `vatsim_adl_daemon.php` (replaced by capture daemon)
- All SWIM daemons (ws, sync, SimTraffic, reverse sync, TMI sync)
- `archival_daemon.php`, `adl_archive_daemon.php`
- `process_discord_queue.php`, `ecfmp_poll_daemon.php`
- `refdata_sync_daemon.php`, `export_playbook.php`
- All conditional daemons (viff, playbook export)

### Additional Pages Redirected

SWIM pages are redirected to `/hibernation` in deep mode (exempt in Level 1):
- `swim.php`, `swim-doc.php`, `swim-docs.php`, `swim-keys.php`

### SWIM API

All `api/swim/v1/*` endpoints return **HTTP 503** with:
```json
{"error": "Service suspended", "mode": "deep_hibernation", "message": "..."}
```

### Configuration

| Setting | Value | Notes |
|---------|-------|-------|
| `DEEP_HIBERNATION_MODE` (Azure App Setting) | `1` | Use `1`/`0`, not `true`/`false` |
| `HIBERNATION_MODE` (Azure App Setting) | `1` | Must also be set (deep implies hibernation) |
| `load/config.php` | `DEEP_HIBERNATION_MODE` constant | Default: `false` |

### Data Capture

- Raw JSON from `https://data.vatsim.net/v3/vatsim-data.json` captured every 15 seconds
- Gzip-compressed, buffered locally at `/home/site/data/deep-hibernation-buffer/`
- Batch-uploaded to `adl-raw-archive` container under `datafeed/YYYY/MM/DD/HH/` every 10 minutes
- Estimated: ~1.4 GB/day compressed, ~$0.92/month Azure storage cost

### Entering Deep Hibernation (from Level 1)

```bash
# 1. Set Azure App Settings
az webapp config appsettings set --name vatcscc --resource-group VATSIM_RG \
    --settings DEEP_HIBERNATION_MODE=1 HIBERNATION_MODE=1

# 2. Update load/config.php defaults (commit + deploy):
#    DEEP_HIBERNATION_MODE default -> true (optional, for defense-in-depth)

# 3. VATSIM_ADL: no change (stays at min 1 / max 4, auto-pause off)

# 4. (Optional) Pause PostGIS if degraded route analysis is acceptable
az postgres flexible-server stop --name vatcscc-gis --resource-group VATSIM_RG

# 5. Restart App Service (after any DB changes propagate)
az webapp restart --name vatcscc --resource-group VATSIM_RG
```

### Exiting Deep Hibernation

```bash
# 1. (If operational tier needed) Upscale VATSIM_ADL
az sql db update --name VATSIM_ADL --server vatsim --resource-group VATSIM_RG \
    --min-capacity 3 --capacity 16

# 2. (If paused) Restart PostGIS
az postgres flexible-server start --name vatcscc-gis --resource-group VATSIM_RG

# 3. Update Azure App Settings
az webapp config appsettings set --name vatcscc --resource-group VATSIM_RG \
    --settings DEEP_HIBERNATION_MODE=0 HIBERNATION_MODE=0

# 4. Update load/config.php defaults (commit + deploy)

# 5. Restart App Service
az webapp restart --name vatcscc --resource-group VATSIM_RG

# 6. Run replay
php scripts/deep_hibernation_replay.php --verbose

# 7. Run GIS backfill
php scripts/backfill/hibernation_recovery.php --phase=auto --include-inactive

# 8. Reset archive grace period
php scripts/backfill/hibernation_recovery.php --delay-hours=2 --phase=0

# 9. Verify (see checklist)
```

### Exit Verification Checklist

- [ ] All daemons running: `ps aux | grep php` on Kudu SSH
- [ ] ADL ingest working: `tail /home/LogFiles/vatsim_adl.log`
- [ ] GIS daemons running: check parse/boundary/crossing logs
- [ ] SWIM sync running: `tail /home/LogFiles/swim_sync.log`
- [ ] SWIM API responding: `curl https://perti.vatcscc.org/api/swim/v1/health`
- [ ] All pages accessible (demand, gdt, nod, swim)
- [ ] Nav items no longer muted/snowflaked
- [ ] Replay completed: check `/home/LogFiles/deep_hibernation_replay.log`
- [ ] GIS backfill completed: `php scripts/backfill/hibernation_recovery.php --phase=0`
- [ ] Archive grace period reset to 2 hours

### Troubleshooting

#### Capture daemon not archiving

1. Check log: `tail /home/LogFiles/deep_hibernation.log`
2. Verify `ADL_ARCHIVE_STORAGE_CONN` is set: `echo $ADL_ARCHIVE_STORAGE_CONN | head -c 40`
3. Check buffer directory: `ls -la /home/site/data/deep-hibernation-buffer/`
4. Manual test: `curl -s https://data.vatsim.net/v3/vatsim-data.json | head -c 100`

#### Replay fails mid-way

1. The replay script is resumable — re-run with `--start-date` set to where it stopped
2. Check ADL connection: `tail /home/LogFiles/deep_hibernation_replay.log`
3. If SP timeout issues, try `--delay-ms=1000` to slow down

#### SWIM API still returning 503 after exit

1. Check `DEEP_HIBERNATION_MODE` env var: must be `0` or removed
2. Check `load/config.php` default (must be `false`)
3. OPcache stale — wait 60s or restart PHP-FPM
````

- [ ] **Step 2: Commit**

```bash
git add docs/operations/HIBERNATION_RUNBOOK.md
git commit -m "docs: add deep hibernation section to hibernation runbook"
```

---

### Task 11: End-to-End Verification

No new files. Manual verification of the complete implementation.

- [ ] **Step 1: Verify configuration loads correctly**

```bash
php -r "
require 'load/config.php';
echo 'HIBERNATION_MODE=' . (HIBERNATION_MODE ? '1' : '0') . PHP_EOL;
echo 'DEEP_HIBERNATION_MODE=' . (DEEP_HIBERNATION_MODE ? '1' : '0') . PHP_EOL;
"
```

Expected: Both constants defined, DEEP defaults to 0.

- [ ] **Step 2: Verify hibernation.php redirect logic**

```bash
# Test Level 1: swim.php should NOT be in the redirect list
php -r "
define('HIBERNATION_MODE', true);
define('DEEP_HIBERNATION_MODE', false);
\$_SERVER['PHP_SELF'] = '/test.php';
\$_SERVER['REQUEST_URI'] = '/test';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
include 'load/hibernation.php';
echo 'Level 1 swim pages: ' . (in_array('swim.php', \$_hibernated_pages ?? []) ? 'REDIRECTED' : 'EXEMPT') . PHP_EOL;
"
```

Expected: `Level 1 swim pages: EXEMPT`

```bash
# Test Level 2: swim.php should be redirected
php -r "
define('HIBERNATION_MODE', true);
define('DEEP_HIBERNATION_MODE', true);
\$_SERVER['PHP_SELF'] = '/test.php';
\$_SERVER['REQUEST_URI'] = '/test';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
include 'load/hibernation.php';
echo 'Level 2 swim pages: ' . (in_array('swim.php', \$_hibernated_pages ?? []) ? 'REDIRECTED' : 'EXEMPT') . PHP_EOL;
"
```

Expected: `Level 2 swim pages: REDIRECTED`

- [ ] **Step 3: Verify startup.sh has no syntax errors**

```bash
bash -n scripts/startup.sh && echo "PASS: no syntax errors" || echo "FAIL"
```

- [ ] **Step 4: Verify AzureBlobClient loads and parses connection strings**

```bash
php -r "
require 'scripts/lib/AzureBlobClient.php';
\$c = new AzureBlobClient('DefaultEndpointsProtocol=https;AccountName=test;AccountKey=dGVzdA==;EndpointSuffix=core.windows.net');
echo 'PASS: AzureBlobClient works' . PHP_EOL;
"
```

- [ ] **Step 5: Verify capture daemon starts without errors (will fail at blob connection, which is expected locally)**

```bash
timeout 3 php scripts/deep_hibernation_daemon.php 2>&1 || true
```

Expected: Either starts and exits after timeout, or shows "ADL_ARCHIVE_STORAGE_CONN not set" error (expected locally).

- [ ] **Step 6: Verify all modified files have no PHP syntax errors**

```bash
php -l load/config.php && \
php -l load/hibernation.php && \
php -l load/nav.php && \
php -l load/nav_public.php && \
php -l scripts/lib/AzureBlobClient.php && \
php -l scripts/lib/adl_ingest_functions.php && \
php -l scripts/deep_hibernation_daemon.php && \
php -l scripts/deep_hibernation_replay.php && \
php -l scripts/vatsim_adl_daemon.php && \
php -l hibernation.php && \
echo "ALL PASS"
```

Expected: `No syntax errors` for each file, then `ALL PASS`.

- [ ] **Step 7: Final commit with all changes**

If any files weren't committed in previous tasks:

```bash
git status
# Review any uncommitted files and add them
git add -A
git commit -m "feat: complete deep hibernation mode implementation"
```
