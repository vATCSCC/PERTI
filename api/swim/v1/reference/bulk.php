<?php
/**
 * VATSWIM API v1 - Bulk Download Reference Data
 *
 * @version 1.0.0
 * @since 2026-04-05
 *
 * Endpoints:
 *   GET /reference/bulk/catalog              - List available bulk files
 *   GET /reference/bulk/{dataset}?format=json - Download a complete dataset
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, false);

$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/bulk/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$dataset = $path_parts[0] ?? null;
$format = swim_get_param('format', 'json');

$bulk_dir = __DIR__ . '/../../../../data/bulk';
$meta_file = $bulk_dir . '/catalog.json';

if ($dataset === 'catalog' || $dataset === null) {
    // Return catalog
    if (!file_exists($meta_file)) {
        SwimResponse::error('Bulk catalog not yet generated. Run scripts/reference/generate_bulk.php first.', 503, 'NOT_GENERATED');
    }

    $catalog = json_decode(file_get_contents($meta_file), true);
    SwimResponse::success(['catalog' => $catalog['datasets'] ?? [], 'generated_utc' => $catalog['generated_utc'] ?? null, 'airac_cycle' => $catalog['airac_cycle'] ?? null]);
}

// Validate dataset name
$valid_datasets = ['airports', 'fixes', 'airways', 'procedures', 'boundaries_artcc', 'boundaries_tracon', 'boundaries_sector', 'cdrs', 'aircraft', 'airlines', 'hierarchy'];
if (!in_array($dataset, $valid_datasets)) {
    SwimResponse::error("Unknown dataset: $dataset. Valid: " . implode(', ', $valid_datasets), 404, 'NOT_FOUND');
}

// Map format to file extension
$ext_map = ['json' => 'json', 'geojson' => 'geojson', 'csv' => 'csv'];
$ext = $ext_map[$format] ?? 'json';
$file_path = "$bulk_dir/{$dataset}.{$ext}";

if (!file_exists($file_path)) {
    // Try json as fallback
    $file_path = "$bulk_dir/{$dataset}.json";
    if (!file_exists($file_path)) {
        SwimResponse::error("Bulk file not available: {$dataset}.{$ext}. Run generate_bulk.php first.", 503, 'NOT_GENERATED');
    }
}

// Serve file directly with appropriate headers
$content_types = [
    'json' => 'application/json',
    'geojson' => 'application/geo+json',
    'csv' => 'text/csv',
];

$stat = stat($file_path);
$etag = '"' . md5($file_path . $stat['mtime']) . '"';
$last_modified = gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT';

// Check If-None-Match / If-Modified-Since
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . ($content_types[$ext] ?? 'application/json'));
header('Content-Length: ' . filesize($file_path));
header('ETag: ' . $etag);
header('Last-Modified: ' . $last_modified);
header('Cache-Control: public, max-age=86400');
header('Content-Disposition: inline; filename="' . basename($file_path) . '"');

readfile($file_path);
exit;
