<?php
/**
 * VATSWIM API v1 - vNAS Controller Data Ingest Endpoint
 *
 * Receives controller data from vNAS systems and enriches existing
 * swim_controllers rows with ERAM/STARS sector assignments.
 *
 * @version 1.0.0
 *
 * Expected payload (same structure as vNAS controller feed):
 * {
 *   "controllers": [
 *     {
 *       "artccId": "ZDC",
 *       "primaryFacilityId": "...",
 *       "primaryPositionId": "...",
 *       "role": "Controller",
 *       "positions": [...],
 *       "isObserver": false,
 *       "loginTime": "2026-03-06T10:00:00Z",
 *       "vatsimData": { "cid": 1234567, "callsign": "ZDC_CTR", ... }
 *     }
 *   ]
 * }
 */

require_once __DIR__ . '/../../auth.php';

global $conn_swim;

if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Require authentication with write access
$auth = swim_init_auth(true, true);

// Validate source can write controller data
if (!$auth->canWriteField('controller')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write controller data.',
        403,
        'NOT_AUTHORITATIVE'
    );
}

// Get request body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

$controllers = $body['controllers'] ?? [];
if (empty($controllers)) {
    SwimResponse::error('controllers array is required and must not be empty', 400, 'MISSING_CONTROLLERS');
}

if (count($controllers) > 500) {
    SwimResponse::error('Maximum 500 controllers per batch', 400, 'BATCH_TOO_LARGE');
}

// Transform to enrichment batch (same logic as poll daemon)
$enrichBatch = [];

foreach ($controllers as $ctrl) {
    $vatsimData = $ctrl['vatsimData'] ?? null;
    if (!$vatsimData || empty($vatsimData['cid'])) {
        continue;
    }

    $cid = (int)$vatsimData['cid'];

    // Find primary position
    $primaryPosition = null;
    $secondaryPositions = [];

    foreach (($ctrl['positions'] ?? []) as $pos) {
        if (!empty($pos['isPrimary'])) {
            $primaryPosition = $pos;
        } else {
            $secondaryPositions[] = [
                'facilityId'   => $pos['facilityId'] ?? null,
                'facilityName' => $pos['facilityName'] ?? null,
                'positionId'   => $pos['positionId'] ?? null,
                'positionName' => $pos['positionName'] ?? null,
                'positionType' => $pos['positionType'] ?? null,
                'radioName'    => $pos['radioName'] ?? null,
            ];
        }
    }

    // Extract ERAM/STARS data
    $eramSectorId = null;
    $starsSectorId = null;
    $starsAreaId = null;

    if ($primaryPosition) {
        $eramData = $primaryPosition['eramData'] ?? null;
        if ($eramData) {
            $eramSectorId = $eramData['sectorId'] ?? null;
        }
        $starsData = $primaryPosition['starsData'] ?? null;
        if ($starsData) {
            $starsSectorId = $starsData['sectorId'] ?? null;
            $starsAreaId = $starsData['areaId'] ?? null;
        }
    }

    $enrichBatch[] = [
        'cid'             => $cid,
        'artcc_id'        => $ctrl['artccId'] ?? null,
        'facility_id'     => $ctrl['primaryFacilityId'] ?? null,
        'position_id'     => $ctrl['primaryPositionId'] ?? null,
        'position_name'   => $primaryPosition['positionName'] ?? null,
        'position_type'   => $primaryPosition['positionType'] ?? null,
        'radio_name'      => $primaryPosition['radioName'] ?? null,
        'role'            => $ctrl['role'] ?? null,
        'eram_sector_id'  => $eramSectorId,
        'stars_sector_id' => $starsSectorId,
        'stars_area_id'   => $starsAreaId,
        'secondary_json'  => !empty($secondaryPositions) ? json_encode($secondaryPositions) : null,
        'is_observer'     => !empty($ctrl['isObserver']) ? 1 : 0,
    ];
}

if (empty($enrichBatch)) {
    SwimResponse::error('No valid controllers found in payload (each must have vatsimData.cid)', 400, 'NO_VALID_DATA');
}

// Call sp_Swim_EnrichControllersVnas
$json = json_encode($enrichBatch);
$sql = "DECLARE @enr INT;
        EXEC dbo.sp_Swim_EnrichControllersVnas @Json = ?, @Enriched = @enr OUTPUT;
        SELECT @enr AS enriched;";

$stmt = @sqlsrv_query($conn_swim, $sql, [$json]);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'unknown'), 500, 'DB_ERROR');
}

$enriched = 0;
if (sqlsrv_next_result($stmt)) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $enriched = (int)($row['enriched'] ?? 0);
    }
}
sqlsrv_free_stmt($stmt);

SwimResponse::json([
    'success'   => true,
    'received'  => count($enrichBatch),
    'enriched'  => $enriched,
    'timestamp' => gmdate('c'),
]);
