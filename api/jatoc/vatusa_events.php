<?php
/**
 * JATOC - VATUSA Events Proxy (Public access)
 */
header('Content-Type: application/json');

try {
    $limit = intval($_GET['limit'] ?? 50);
    if ($limit < 1 || $limit > 100) $limit = 50;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.vatusa.net/public/events/{$limit}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'PERTI-JATOC/1.0 (VATCSCC)'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        throw new Exception("VATUSA API error: HTTP $httpCode" . ($error ? " - $error" : ""));
    }
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response from VATUSA');
    }
    
    // VATUSA API returns different structures - normalize it
    $events = [];
    if (is_array($data)) {
        if (isset($data['data'])) {
            $events = $data['data'];
        } elseif (isset($data['events'])) {
            $events = $data['events'];
        } elseif (isset($data[0]) && is_array($data[0])) {
            $events = $data;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $events,
        'count' => count($events),
        'fetched_at' => gmdate('Y-m-d H:i:s') . 'Z'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'data' => []
    ]);
}
