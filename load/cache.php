<?php
/**
 * APCu Cache Helpers
 *
 * Lightweight cache functions using APCu for server-side response caching.
 * Used by demand endpoints, SWIM API, and any endpoint needing short-TTL caching.
 *
 * Pattern:
 *   require_once(__DIR__ . "/../../load/cache.php");
 *   $cacheKey = demand_cache_key('endpoint', ['param1' => 'val']);
 *   $cached = apcu_cache_get($cacheKey);
 *   if ($cached !== null) {
 *       header('X-Cache: HIT');
 *       echo json_encode($cached);
 *       exit;
 *   }
 *   // ... run queries ...
 *   apcu_cache_set($cacheKey, $response, 30);
 *   header('X-Cache: MISS');
 */

/**
 * Check if APCu is available
 */
function apcu_cache_available() {
    return function_exists('apcu_fetch') && apcu_enabled();
}

/**
 * Get cached data
 *
 * @param string $cache_key
 * @return mixed|null Cached data or null if not found
 */
function apcu_cache_get($cache_key) {
    if (!apcu_cache_available()) {
        return null;
    }
    $data = apcu_fetch($cache_key, $success);
    return $success ? $data : null;
}

/**
 * Store data in cache
 *
 * @param string $cache_key
 * @param mixed $data Data to cache
 * @param int $ttl TTL in seconds
 * @return bool Success
 */
function apcu_cache_set($cache_key, $data, $ttl) {
    if (!apcu_cache_available()) {
        return false;
    }
    return apcu_store($cache_key, $data, $ttl);
}

/**
 * Build a cache key for demand endpoints
 *
 * @param string $endpoint Endpoint name (e.g., 'demand_summary')
 * @param array $params Key-value pairs to include in the cache key
 * @return string Cache key
 */
function demand_cache_key($endpoint, $params = []) {
    ksort($params); // Ensure consistent key ordering
    return 'demand:' . $endpoint . ':' . md5(json_encode($params));
}
