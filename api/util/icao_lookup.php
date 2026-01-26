<?php

// api/util/icao_lookup.php
// Looks up ICAO code from FAA/local airport code using the apts table

include("../../load/config.php");
include("../../load/connect.php");

header('Content-Type: application/json');

$faa = isset($_GET['faa']) ? strtoupper(trim($_GET['faa'])) : '';

if (empty($faa) || strlen($faa) < 2 || strlen($faa) > 4) {
    echo json_encode(['success' => false, 'error' => 'Invalid FAA code']);
    exit();
}

// Check if ADL connection is available
if (!$conn_adl) {
    // Fallback to simple prefix logic
    if (strlen($faa) == 4) {
        $icao = $faa;
    } elseif (strlen($faa) == 3 && $faa[0] === 'Y') {
        $icao = 'C' . $faa;
    } elseif (strlen($faa) == 3) {
        $icao = 'K' . $faa;
    } else {
        $icao = $faa;
    }
    
    echo json_encode([
        'success' => true,
        'faa' => $faa,
        'icao' => $icao,
        'source' => 'fallback'
    ]);
    exit();
}

// Query the apts table for the ICAO code
$sql = "SELECT ARPT_ID, ICAO_ID, ARPT_NAME FROM dbo.apts WHERE ARPT_ID = ? OR ICAO_ID = ?";
$stmt = sqlsrv_query($conn_adl, $sql, [$faa, $faa]);

if ($stmt === false) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit();
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if ($row) {
    echo json_encode([
        'success' => true,
        'faa' => $row['ARPT_ID'],
        'icao' => $row['ICAO_ID'],
        'name' => $row['ARPT_NAME'],
        'source' => 'apts'
    ]);
} else {
    // Not found in apts table - use fallback logic
    if (strlen($faa) == 4) {
        $icao = $faa;
    } elseif (strlen($faa) == 3 && $faa[0] === 'Y') {
        $icao = 'C' . $faa;
    } elseif (strlen($faa) == 3) {
        $icao = 'K' . $faa;
    } else {
        $icao = $faa;
    }
    
    echo json_encode([
        'success' => true,
        'faa' => $faa,
        'icao' => $icao,
        'source' => 'fallback',
        'note' => 'Airport not found in apts table'
    ]);
}

?>
