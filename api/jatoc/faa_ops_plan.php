<?php
/**
 * JATOC FAA Ops Plan API - Public access (GET only)
 */
header('Content-Type: application/json');

try {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://nasstatus.faa.gov/',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $spaceLaunches = [];
    $opsPlanUrl = null;
    
    if ($httpCode === 200 && $html) {
        // Try to find ops plan PDF link
        if (preg_match('/href=["\']([^"\']*operations[^"\']*\.pdf)["\']|operations-plan[^>]*href=["\']([^"\']+)["\']/i', $html, $matches)) {
            $opsPlanUrl = $matches[1] ?: $matches[2];
            if (!str_starts_with($opsPlanUrl, 'http')) $opsPlanUrl = 'https://nasstatus.faa.gov/' . ltrim($opsPlanUrl, '/');
        }
        
        // Try to parse space launch info from the page
        // Look for common patterns in FAA status pages
        if (preg_match_all('/(?:space|launch|rocket|spacex|ula|nasa)[^<]*(?:window|scheduled|planned)[^<]*(\d{1,2}:\d{2})[^<]*/i', $html, $launchMatches, PREG_SET_ORDER)) {
            foreach ($launchMatches as $m) {
                $spaceLaunches[] = [
                    'time' => $m[1] ?? 'TBD',
                    'name' => trim(strip_tags($m[0])),
                    'status' => 'future'
                ];
            }
        }
        
        // Also check for any table rows with space/launch info
        if (preg_match_all('/<tr[^>]*>.*?(?:space|launch|rocket)[^<]*<\/tr>/is', $html, $trMatches)) {
            foreach ($trMatches[0] as $tr) {
                if (preg_match('/(\d{1,2}:\d{2})/', $tr, $timeMatch)) {
                    $spaceLaunches[] = [
                        'time' => $timeMatch[1],
                        'name' => trim(strip_tags(preg_replace('/<[^>]+>/', ' ', $tr))),
                        'status' => 'future'
                    ];
                }
            }
        }
    }
    
    // Deduplicate
    $seen = [];
    $spaceLaunches = array_filter($spaceLaunches, function($l) use (&$seen) {
        $key = $l['time'] . $l['name'];
        if (isset($seen[$key])) return false;
        $seen[$key] = true;
        return true;
    });
    
    echo json_encode([
        'success' => true, 
        'space_launches' => array_values($spaceLaunches),
        'ops_plan_url' => $opsPlanUrl, 
        'source' => 'FAA NAS Status', 
        'fetched_at' => gmdate('Y-m-d H:i:s') . 'Z'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(), 
        'space_launches' => [],
        'space_ops' => "Unable to fetch - check FAA NAS Status directly\nhttps://nasstatus.faa.gov/"
    ]);
}
