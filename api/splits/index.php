<?php
/**
 * Sector Index API
 * 
 * Returns available sector maps from the CRC index for a facility.
 * Uses the SQLite index for fast lookup without loading full GeoJSON.
 * 
 * GET Parameters:
 *   - facility: ARTCC/FIR code
 *   - search: Optional search term
 * 
 * Response: JSON with available sector maps
 */

header('Content-Type: application/json');

// Configuration
define('CRC_INDEX_PATH', '/mnt/data/crc_index.sqlite');
define('CRC_BASE_PATH', '/mnt/data/CRC_extracted');

function main() {
    $facility = strtoupper($_GET['facility'] ?? '');
    $search = $_GET['search'] ?? '';
    
    if (!$facility) {
        jsonError('Missing facility parameter');
    }
    
    // Try SQLite index first
    $maps = loadFromSqliteIndex($facility, $search);
    
    // Fallback to scanning ARTCC JSON
    if (empty($maps)) {
        $maps = loadFromArtccJson($facility, $search);
    }
    
    echo json_encode([
        'success' => true,
        'facility' => $facility,
        'maps' => $maps,
        'count' => count($maps)
    ]);
}

/**
 * Load from SQLite index
 */
function loadFromSqliteIndex($facility, $search) {
    if (!file_exists(CRC_INDEX_PATH)) {
        return [];
    }
    
    try {
        $db = new PDO('sqlite:' . CRC_INDEX_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $sql = "SELECT id, name, tags, geojson_relpath, file_size_bytes, geojson_exists
                FROM videomap 
                WHERE artcc = :facility 
                AND geojson_exists = 1";
        
        $params = [':facility' => $facility];
        
        if ($search) {
            $sql .= " AND (name LIKE :search OR tags LIKE :search)";
            $params[':search'] = "%$search%";
        }
        
        $sql .= " ORDER BY name";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $maps = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tags = json_decode($row['tags'] ?? '[]', true) ?: [];
            
            // Filter for sector-related maps
            $name = strtoupper($row['name']);
            $tagsStr = strtoupper(implode(' ', $tags));
            
            $isSectorRelated = (
                strpos($name, 'SECTOR') !== false ||
                strpos($tagsStr, 'SECTOR') !== false ||
                preg_match('/^' . $facility . '\d{2}/', $name) ||
                strpos($name, 'HIGH') !== false ||
                strpos($name, 'LOW') !== false ||
                strpos($name, 'BOUNDARY') !== false
            );
            
            if (!$isSectorRelated && !$search) continue;
            
            $maps[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'tags' => $tags,
                'path' => $row['geojson_relpath'],
                'size' => (int)$row['file_size_bytes']
            ];
        }
        
        return $maps;
        
    } catch (Exception $e) {
        error_log("SQLite error: " . $e->getMessage());
        return [];
    }
}

/**
 * Load from ARTCC JSON file
 */
function loadFromArtccJson($facility, $search) {
    $artccPath = CRC_BASE_PATH . "/ARTCCs/{$facility}.json";
    
    if (!file_exists($artccPath)) {
        return [];
    }
    
    $data = json_decode(file_get_contents($artccPath), true);
    if (!$data || !isset($data['videoMaps'])) {
        return [];
    }
    
    $maps = [];
    foreach ($data['videoMaps'] as $map) {
        $name = $map['name'] ?? '';
        $tags = $map['tags'] ?? [];
        $tagsStr = strtoupper(implode(' ', $tags));
        $nameUpper = strtoupper($name);
        
        // Filter for sector-related
        $isSectorRelated = (
            strpos($nameUpper, 'SECTOR') !== false ||
            strpos($tagsStr, 'SECTOR') !== false ||
            preg_match('/^' . $facility . '\d{2}/', $nameUpper) ||
            strpos($nameUpper, 'HIGH') !== false ||
            strpos($nameUpper, 'LOW') !== false ||
            strpos($nameUpper, 'BOUNDARY') !== false
        );
        
        if (!$isSectorRelated && !$search) continue;
        
        // Search filter
        if ($search) {
            $searchUpper = strtoupper($search);
            if (strpos($nameUpper, $searchUpper) === false && 
                strpos($tagsStr, $searchUpper) === false) {
                continue;
            }
        }
        
        $geojsonPath = CRC_BASE_PATH . '/VideoMaps/' . $facility . '/' . $map['id'] . '.geojson';
        
        $maps[] = [
            'id' => $map['id'],
            'name' => $name,
            'tags' => $tags,
            'path' => 'VideoMaps/' . $facility . '/' . $map['id'] . '.geojson',
            'exists' => file_exists($geojsonPath)
        ];
    }
    
    return $maps;
}

function jsonError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

main();
