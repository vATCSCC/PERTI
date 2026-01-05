<?php
/**
 * Historical Advisory Import API
 * 
 * POST - Import advisories from CSV or raw text format
 * 
 * Supports:
 * - ATCSCC ADVZY format (official FAA advisory format)
 * - CSV format with columns: adv_number, date, type, airport, center, body
 * - Raw text parsing for NTML/ADVZY files
 */

header('Content-Type: application/json');

// Include database connection
$config_path = realpath(__DIR__ . '/../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../load/connect.php');

if ($config_path) include($config_path);
if ($connect_path) include($connect_path);

// Check for Azure SQL connection
if (!isset($conn_adl) || !$conn_adl) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection not available']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed - POST only']);
    exit;
}

try {
    handleImport($conn_adl);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Handle the import POST request
 */
function handleImport($conn) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    // Handle multipart form data (file upload)
    if (strpos($contentType, 'multipart/form-data') !== false) {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded or upload error']);
            return;
        }
        
        $content = file_get_contents($_FILES['file']['tmp_name']);
        $filename = $_FILES['file']['name'];
        $format = $_POST['format'] ?? 'auto';
        $source = $_POST['source'] ?? 'HISTORICAL_IMPORT';
        $importedBy = $_POST['imported_by'] ?? 'system';
        
    } else {
        // Handle JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || empty($input['content'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing content in request body']);
            return;
        }
        
        $content = $input['content'];
        $filename = $input['filename'] ?? 'import.txt';
        $format = $input['format'] ?? 'auto';
        $source = $input['source'] ?? 'HISTORICAL_IMPORT';
        $importedBy = $input['imported_by'] ?? 'system';
    }
    
    // Auto-detect format
    if ($format === 'auto') {
        $format = detectFormat($content, $filename);
    }
    
    // Parse content based on format
    $advisories = [];
    switch ($format) {
        case 'advzy':
            $advisories = parseAdvzyFormat($content);
            break;
        case 'csv':
            $advisories = parseCsvFormat($content);
            break;
        case 'ntml':
            $advisories = parseNtmlFormat($content);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => "Unknown format: $format"]);
            return;
    }
    
    if (empty($advisories)) {
        echo json_encode([
            'success' => false,
            'imported' => 0,
            'skipped' => 0,
            'errors' => ['No advisories found in input']
        ]);
        return;
    }
    
    // Import advisories to database
    $result = importAdvisories($conn, $advisories, $source, $importedBy);
    
    echo json_encode([
        'success' => true,
        'format' => $format,
        'imported' => $result['imported'],
        'skipped' => $result['skipped'],
        'duplicates' => $result['duplicates'],
        'errors' => $result['errors']
    ]);
}

/**
 * Detect the format based on content and filename
 */
function detectFormat($content, $filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    if ($ext === 'csv') {
        return 'csv';
    }
    
    // Check for ATCSCC ADVZY format
    if (preg_match('/ATCSCC\s+ADVZY\s+\d+/i', $content)) {
        return 'advzy';
    }
    
    // Check for NTML format markers
    if (preg_match('/NTML|CDM\s+PROPOSED|CDM\s+ACTUAL/i', $content)) {
        return 'ntml';
    }
    
    // Check for CSV-like structure
    $lines = explode("\n", $content);
    if (count($lines) > 1 && strpos($lines[0], ',') !== false) {
        return 'csv';
    }
    
    // Default to ADVZY format
    return 'advzy';
}

/**
 * Parse ATCSCC ADVZY format
 * Format: ATCSCC ADVZY ### APT/CTR MM/DD/YYYY TYPE...
 */
function parseAdvzyFormat($content) {
    $advisories = [];
    
    // Split by advisory header pattern
    $pattern = '/ATCSCC\s+ADVZY\s+(\d+)\s+([A-Z]{3})\/([A-Z]{3})\s+(\d{1,2}\/\d{1,2}\/\d{4})\s+(.+?)(?=ATCSCC\s+ADVZY|\z)/si';
    
    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $advNumber = 'DCC ' . str_pad($match[1], 3, '0', STR_PAD_LEFT);
            $airport = $match[2];
            $center = $match[3];
            $dateStr = $match[4];
            $bodyAndType = trim($match[5]);
            
            // Parse date
            $date = DateTime::createFromFormat('n/j/Y', $dateStr);
            if (!$date) {
                $date = DateTime::createFromFormat('m/d/Y', $dateStr);
            }
            $validStart = $date ? $date->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
            
            // Extract advisory type from first line
            $lines = preg_split('/\r?\n/', $bodyAndType);
            $firstLine = trim($lines[0] ?? '');
            
            // Determine advisory type
            $advType = 'INFO';
            if (preg_match('/\bGS\b|GROUND\s*STOP/i', $firstLine)) {
                $advType = 'GS';
            } elseif (preg_match('/\bGDP\b|GROUND\s*DELAY/i', $firstLine)) {
                $advType = 'GDP';
            } elseif (preg_match('/\bAFP\b|AIRSPACE\s*FLOW/i', $firstLine)) {
                $advType = 'AFP';
            } elseif (preg_match('/\bCTOP\b/i', $firstLine)) {
                $advType = 'CTOP';
            } elseif (preg_match('/\bMIT\b|MILES?\s*IN\s*TRAIL/i', $firstLine)) {
                $advType = 'MIT';
            } elseif (preg_match('/\bRR\b|REROUTE/i', $firstLine)) {
                $advType = 'REROUTE';
            }
            
            // Parse control element if present
            $ctlElement = null;
            if (preg_match('/CTL\s+ELEMENT:\s*([A-Z0-9]+)/i', $bodyAndType, $ctlMatch)) {
                $ctlElement = $ctlMatch[1];
            }
            
            // Extract subject (first meaningful line)
            $subject = strlen($firstLine) > 100 ? substr($firstLine, 0, 100) . '...' : $firstLine;
            
            $advisories[] = [
                'adv_number' => $advNumber,
                'adv_type' => $advType,
                'adv_category' => 'TMI',
                'subject' => $subject,
                'body_text' => trim($bodyAndType),
                'valid_start_utc' => $validStart,
                'impacted_airports' => $airport ?: $ctlElement,
                'impacted_facilities' => $center ? "Z$center" : null,
                'impacted_area' => $center,
                'priority' => $advType === 'GS' ? 1 : ($advType === 'GDP' ? 2 : 3)
            ];
        }
    }
    
    // Also try line-by-line parsing for simpler formats
    if (empty($advisories)) {
        $lines = preg_split('/\r?\n/', $content);
        $currentAdvisory = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Check for new advisory header
            if (preg_match('/ATCSCC\s+ADVZY\s+(\d+)/i', $line, $match)) {
                if ($currentAdvisory) {
                    $advisories[] = $currentAdvisory;
                }
                $currentAdvisory = [
                    'adv_number' => 'DCC ' . str_pad($match[1], 3, '0', STR_PAD_LEFT),
                    'adv_type' => 'INFO',
                    'adv_category' => 'TMI',
                    'subject' => '',
                    'body_text' => $line,
                    'valid_start_utc' => date('Y-m-d H:i:s'),
                    'priority' => 3
                ];
            } elseif ($currentAdvisory) {
                $currentAdvisory['body_text'] .= "\n" . $line;
            }
        }
        
        if ($currentAdvisory) {
            $advisories[] = $currentAdvisory;
        }
    }
    
    return $advisories;
}

/**
 * Parse CSV format
 * Expected columns: adv_number, date, type, airport, center, subject, body
 */
function parseCsvFormat($content) {
    $advisories = [];
    $lines = preg_split('/\r?\n/', $content);
    
    if (count($lines) < 2) {
        return $advisories;
    }
    
    // Parse header
    $header = str_getcsv($lines[0]);
    $header = array_map('strtolower', array_map('trim', $header));
    
    // Map column names to our fields
    $columnMap = [
        'adv_number' => ['adv_number', 'advisory_number', 'number', 'advzy', 'id'],
        'date' => ['date', 'datetime', 'timestamp', 'valid_start', 'start_date', 'issued'],
        'type' => ['type', 'adv_type', 'advisory_type', 'tmi_type'],
        'airport' => ['airport', 'apt', 'airports', 'impacted_airport', 'facility'],
        'center' => ['center', 'artcc', 'ctr', 'impacted_center'],
        'subject' => ['subject', 'title', 'summary', 'headline'],
        'body' => ['body', 'body_text', 'text', 'message', 'content', 'remarks']
    ];
    
    $colIndex = [];
    foreach ($columnMap as $field => $aliases) {
        foreach ($aliases as $alias) {
            $idx = array_search($alias, $header);
            if ($idx !== false) {
                $colIndex[$field] = $idx;
                break;
            }
        }
    }
    
    // Parse data rows
    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        
        $row = str_getcsv($line);
        
        $advisory = [
            'adv_number' => isset($colIndex['adv_number']) ? trim($row[$colIndex['adv_number']] ?? '') : ('IMP ' . str_pad($i, 3, '0', STR_PAD_LEFT)),
            'adv_type' => isset($colIndex['type']) ? strtoupper(trim($row[$colIndex['type']] ?? 'INFO')) : 'INFO',
            'adv_category' => 'TMI',
            'subject' => isset($colIndex['subject']) ? trim($row[$colIndex['subject']] ?? '') : '',
            'body_text' => isset($colIndex['body']) ? trim($row[$colIndex['body']] ?? '') : '',
            'valid_start_utc' => null,
            'impacted_airports' => isset($colIndex['airport']) ? trim($row[$colIndex['airport']] ?? '') : null,
            'impacted_facilities' => isset($colIndex['center']) ? trim($row[$colIndex['center']] ?? '') : null,
            'priority' => 3
        ];
        
        // Parse date
        if (isset($colIndex['date'])) {
            $dateStr = trim($row[$colIndex['date']] ?? '');
            if ($dateStr) {
                $date = strtotime($dateStr);
                if ($date) {
                    $advisory['valid_start_utc'] = date('Y-m-d H:i:s', $date);
                }
            }
        }
        
        if (!$advisory['valid_start_utc']) {
            $advisory['valid_start_utc'] = date('Y-m-d H:i:s');
        }
        
        // Set subject from body if empty
        if (empty($advisory['subject']) && !empty($advisory['body_text'])) {
            $advisory['subject'] = substr($advisory['body_text'], 0, 100);
            if (strlen($advisory['body_text']) > 100) {
                $advisory['subject'] .= '...';
            }
        }
        
        // Set priority based on type
        if (in_array($advisory['adv_type'], ['GS', 'GROUND_STOP'])) {
            $advisory['adv_type'] = 'GS';
            $advisory['priority'] = 1;
        } elseif (in_array($advisory['adv_type'], ['GDP', 'GROUND_DELAY'])) {
            $advisory['adv_type'] = 'GDP';
            $advisory['priority'] = 2;
        }
        
        $advisories[] = $advisory;
    }
    
    return $advisories;
}

/**
 * Parse NTML format (similar to ADVZY but may have different structure)
 */
function parseNtmlFormat($content) {
    // NTML format is similar to ADVZY, reuse with some modifications
    $advisories = parseAdvzyFormat($content);
    
    // Additional NTML-specific patterns
    if (empty($advisories)) {
        $blocks = preg_split('/(?=\d{6,8}Z?\s+NTML)/i', $content);
        
        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) continue;
            
            // Extract timestamp
            $timestamp = null;
            if (preg_match('/^(\d{6,8})Z?\s+/i', $block, $tsMatch)) {
                $timestamp = $tsMatch[1];
            }
            
            // Parse the NTML message
            $lines = preg_split('/\r?\n/', $block);
            $firstLine = $lines[0] ?? '';
            
            $advisory = [
                'adv_number' => 'NTML ' . ($timestamp ?: date('His')),
                'adv_type' => 'INFO',
                'adv_category' => 'TMI',
                'subject' => strlen($firstLine) > 100 ? substr($firstLine, 0, 100) . '...' : $firstLine,
                'body_text' => $block,
                'valid_start_utc' => date('Y-m-d H:i:s'),
                'priority' => 3
            ];
            
            // Detect type from content
            if (preg_match('/\bGROUND\s*STOP|\bGS\b/i', $block)) {
                $advisory['adv_type'] = 'GS';
                $advisory['priority'] = 1;
            } elseif (preg_match('/\bGROUND\s*DELAY|\bGDP\b/i', $block)) {
                $advisory['adv_type'] = 'GDP';
                $advisory['priority'] = 2;
            } elseif (preg_match('/\bREROUTE|\bRR\b/i', $block)) {
                $advisory['adv_type'] = 'REROUTE';
            }
            
            // Extract airport/center
            if (preg_match('/([A-Z]{3,4})\/([A-Z]{3})/i', $firstLine, $facMatch)) {
                $advisory['impacted_airports'] = $facMatch[1];
                $advisory['impacted_facilities'] = 'Z' . $facMatch[2];
            }
            
            $advisories[] = $advisory;
        }
    }
    
    return $advisories;
}

/**
 * Import advisories to the database
 */
function importAdvisories($conn, $advisories, $source, $importedBy) {
    $imported = 0;
    $skipped = 0;
    $duplicates = 0;
    $errors = [];
    
    foreach ($advisories as $adv) {
        try {
            // Check for duplicate by advisory number
            $checkSql = "SELECT id FROM dbo.dcc_advisories WHERE adv_number = ?";
            $checkStmt = sqlsrv_query($conn, $checkSql, [$adv['adv_number']]);
            
            if ($checkStmt && sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC)) {
                $duplicates++;
                continue;
            }
            
            // Insert advisory
            $sql = "INSERT INTO dbo.dcc_advisories (
                        adv_number, adv_type, adv_category, subject, body_text,
                        valid_start_utc, valid_end_utc,
                        impacted_facilities, impacted_airports, impacted_area,
                        source, source_ref, status, priority, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 'HISTORICAL', ?, ?)";
            
            $params = [
                $adv['adv_number'],
                $adv['adv_type'],
                $adv['adv_category'] ?? 'TMI',
                $adv['subject'],
                $adv['body_text'],
                $adv['valid_start_utc'],
                $adv['impacted_facilities'] ? json_encode([$adv['impacted_facilities']]) : null,
                $adv['impacted_airports'] ? json_encode([$adv['impacted_airports']]) : null,
                $adv['impacted_area'] ?? null,
                $source,
                $adv['adv_number'],
                $adv['priority'] ?? 3,
                $importedBy
            ];
            
            $stmt = sqlsrv_query($conn, $sql, $params);
            
            if ($stmt === false) {
                $err = sqlsrv_errors();
                $errors[] = "Failed to import {$adv['adv_number']}: " . formatSqlError($err);
                $skipped++;
            } else {
                $imported++;
            }
            
        } catch (Exception $e) {
            $errors[] = "Error importing {$adv['adv_number']}: " . $e->getMessage();
            $skipped++;
        }
    }
    
    return [
        'imported' => $imported,
        'skipped' => $skipped,
        'duplicates' => $duplicates,
        'errors' => array_slice($errors, 0, 10) // Limit error messages
    ];
}

/**
 * Format SQL Server errors
 */
function formatSqlError($errors) {
    if (!$errors) return 'Unknown database error';
    
    $messages = [];
    foreach ($errors as $error) {
        $messages[] = $error['message'] ?? $error['SQLSTATE'] ?? 'Unknown';
    }
    return implode('; ', $messages);
}
