<?php

// api/demand/airports.php
// Returns list of airports for demand visualization filters
// Supports filtering by category (ASPM77/OEP35/Core30), ARTCC, and search term

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once("../../load/config.php");

// Check ADL database configuration
if (!defined("ADL_SQL_HOST") || !defined("ADL_SQL_DATABASE") ||
    !defined("ADL_SQL_USERNAME") || !defined("ADL_SQL_PASSWORD")) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "ADL_SQL_* constants are not defined."]);
    exit;
}

// Helper function for SQL Server error messages
function adl_sql_error_message() {
    $errs = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errs) return "";
    $msgs = [];
    foreach ($errs as $e) {
        $msgs[] = (isset($e['SQLSTATE']) ? $e['SQLSTATE'] : '') . " " .
                  (isset($e['code']) ? $e['code'] : '') . " " .
                  (isset($e['message']) ? trim($e['message']) : '');
    }
    return implode(" | ", $msgs);
}

// Check sqlsrv extension
if (!function_exists('sqlsrv_connect')) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "sqlsrv extension not available."]);
    exit;
}

// Connect to ADL database
$connectionInfo = [
    "Database" => ADL_SQL_DATABASE,
    "UID"      => ADL_SQL_USERNAME,
    "PWD"      => ADL_SQL_PASSWORD
];

$conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
if ($conn === false) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Unable to connect to ADL database.",
        "sql_error" => adl_sql_error_message()
    ]);
    exit;
}

// Get filter parameters
$category = isset($_GET['category']) ? strtolower(trim($_GET['category'])) : 'all';
$artcc = isset($_GET['artcc']) ? strtoupper(trim($_GET['artcc'])) : '';
$tier = isset($_GET['tier']) ? trim($_GET['tier']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Load tier data if tier filter is specified (from database)
$tierARTCCs = [];
if (!empty($tier) && $tier !== 'all' && !empty($artcc)) {
    // First get the config to check if it references a tier group
    $tierSql = "
        SELECT fc.config_id, fc.tier_group_id
        FROM dbo.facility_tier_configs fc
        INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
        WHERE fc.config_code = ? AND ff.facility_code = ? AND fc.is_active = 1
    ";
    $tierStmt = sqlsrv_query($conn, $tierSql, [$tier, $artcc]);
    if ($tierStmt !== false) {
        $configRow = sqlsrv_fetch_array($tierStmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($tierStmt);

        if ($configRow) {
            if (!empty($configRow['tier_group_id'])) {
                // Get ARTCCs from tier group
                $memberSql = "
                    SELECT f.facility_code
                    FROM dbo.artcc_tier_group_members tgm
                    INNER JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
                    WHERE tgm.tier_group_id = ? AND f.is_active = 1
                    ORDER BY tgm.display_order
                ";
                $memberStmt = sqlsrv_query($conn, $memberSql, [$configRow['tier_group_id']]);
            } else {
                // Get ARTCCs from config members
                $memberSql = "
                    SELECT f.facility_code
                    FROM dbo.facility_tier_config_members fcm
                    INNER JOIN dbo.artcc_facilities f ON fcm.facility_id = f.facility_id
                    WHERE fcm.config_id = ? AND f.is_active = 1
                    ORDER BY fcm.display_order
                ";
                $memberStmt = sqlsrv_query($conn, $memberSql, [$configRow['config_id']]);
            }

            if ($memberStmt !== false) {
                while ($memberRow = sqlsrv_fetch_array($memberStmt, SQLSRV_FETCH_ASSOC)) {
                    $tierARTCCs[] = $memberRow['facility_code'];
                }
                sqlsrv_free_stmt($memberStmt);
            }
        }
    }
}

// Major international airports to always include
// These will always appear in the dropdown even if not in the database
$majorInternationalAirports = [
    // Canada Major
    'CYYZ' => 'Toronto Pearson',
    'CYVR' => 'Vancouver',
    'CYUL' => 'Montreal Trudeau',
    'CYYC' => 'Calgary',
    'CYEG' => 'Edmonton',
    'CYOW' => 'Ottawa',
    'CYWG' => 'Winnipeg',
    'CYQB' => 'Quebec City',
    'CYHZ' => 'Halifax',
    'CYXE' => 'Saskatoon',
    // Mexico Major
    'MMMX' => 'Mexico City',
    'MMUN' => 'Cancun',
    'MMTJ' => 'Tijuana',
    'MMGL' => 'Guadalajara',
    'MMMY' => 'Monterrey',
    'MMPR' => 'Puerto Vallarta',
    'MMSD' => 'Los Cabos',
    'MMCU' => 'Chihuahua',
    // Caribbean Major
    'TIST' => 'St Thomas',
    'TJSJ' => 'San Juan',
    'MKJP' => 'Kingston',
    'MBPV' => 'Providenciales',
    'MYNN' => 'Nassau',
    'TNCM' => 'St Maarten',
    'TBPB' => 'Barbados',
    'TFFR' => 'Guadeloupe',
    // European Hubs
    'EGLL' => 'London Heathrow',
    'LFPG' => 'Paris CDG',
    'EHAM' => 'Amsterdam',
    'EDDF' => 'Frankfurt',
    'LEMD' => 'Madrid',
    'LIRF' => 'Rome Fiumicino',
    'LEBL' => 'Barcelona',
    'EIDW' => 'Dublin',
    'EGKK' => 'London Gatwick',
    'LFPO' => 'Paris Orly',
    'LSZH' => 'Zurich',
    'LOWW' => 'Vienna',
    'EBBR' => 'Brussels',
    'EKCH' => 'Copenhagen',
    'ENGM' => 'Oslo',
    'ESSA' => 'Stockholm Arlanda',
    'EFHK' => 'Helsinki',
    'LPPT' => 'Lisbon',
    'LGAV' => 'Athens',
    'LTFM' => 'Istanbul',
    'UUEE' => 'Moscow Sheremetyevo',
    'UUDD' => 'Moscow Domodedovo',
    // Middle East
    'OMDB' => 'Dubai',
    'OMAA' => 'Abu Dhabi',
    'OERK' => 'Riyadh',
    'OEJN' => 'Jeddah',
    'OTHH' => 'Doha',
    'OKBK' => 'Kuwait',
    'OBBI' => 'Bahrain',
    'LLBG' => 'Tel Aviv',
    // Asia Pacific
    'VHHH' => 'Hong Kong',
    'WSSS' => 'Singapore Changi',
    'RKSI' => 'Seoul Incheon',
    'RKSS' => 'Seoul Gimpo',
    'RKPK' => 'Busan Gimhae',
    'RPLL' => 'Manila',
    'VTBS' => 'Bangkok Suvarnabhumi',
    'VTBD' => 'Bangkok Don Mueang',
    'WIII' => 'Jakarta',
    'WMKK' => 'Kuala Lumpur',
    'VVTS' => 'Ho Chi Minh City',
    'VVNB' => 'Hanoi',
    'RCTP' => 'Taipei Taoyuan',
    'RCSS' => 'Taipei Songshan',
    'VRMM' => 'Male Maldives',
    // Japan
    'RJTT' => 'Tokyo Haneda',
    'RJAA' => 'Tokyo Narita',
    'RJBB' => 'Osaka Kansai',
    'RJOO' => 'Osaka Itami',
    'RJGG' => 'Nagoya Chubu',
    'RJFF' => 'Fukuoka',
    'RJCC' => 'Sapporo New Chitose',
    'ROAH' => 'Okinawa Naha',
    'RJSN' => 'Niigata',
    'RJFK' => 'Kagoshima',
    // China
    'ZBAA' => 'Beijing Capital',
    'ZBAD' => 'Beijing Daxing',
    'ZSPD' => 'Shanghai Pudong',
    'ZSSS' => 'Shanghai Hongqiao',
    'ZGGG' => 'Guangzhou',
    'ZGSZ' => 'Shenzhen',
    'ZUUU' => 'Chengdu Shuangliu',
    'ZUCK' => 'Chongqing',
    'ZPPP' => 'Kunming',
    'ZLXY' => 'Xian',
    'ZHCC' => 'Zhengzhou',
    'ZHHH' => 'Wuhan',
    'ZSNJ' => 'Nanjing',
    'ZSHC' => 'Hangzhou',
    'ZSAM' => 'Xiamen',
    'ZGHA' => 'Changsha',
    'ZYTL' => 'Dalian',
    'ZYTX' => 'Shenyang',
    'ZYCC' => 'Changchun',
    'ZYHB' => 'Harbin',
    'ZWWW' => 'Urumqi',
    'ZLLL' => 'Lanzhou',
    // India Major
    'VIDP' => 'Delhi',
    'VABB' => 'Mumbai',
    'VOBL' => 'Bangalore',
    'VOMM' => 'Chennai',
    'VECC' => 'Kolkata',
    'VOCI' => 'Kochi',
    'VOHS' => 'Hyderabad',
    'VAAH' => 'Ahmedabad',
    'VAGO' => 'Goa',
    'VGHS' => 'Dhaka',
    // Australia
    'YSSY' => 'Sydney',
    'YMML' => 'Melbourne',
    'YBBN' => 'Brisbane',
    'YPPH' => 'Perth',
    'YPAD' => 'Adelaide',
    'YSCB' => 'Canberra',
    'YBCG' => 'Gold Coast',
    'YBCS' => 'Cairns',
    'YBTL' => 'Townsville',
    'YPDN' => 'Darwin',
    'YHBA' => 'Hobart',
    // New Zealand & Pacific
    'NZAA' => 'Auckland',
    'NZWN' => 'Wellington',
    'NZCH' => 'Christchurch',
    'NZQN' => 'Queenstown',
    'NFFN' => 'Fiji Nadi',
    'NTAA' => 'Tahiti Faaa',
    'NWWW' => 'Noumea',
    'NVVV' => 'Port Vila',
    'AGGR' => 'Solomon Islands',
    // South America
    'SBGR' => 'Sao Paulo Guarulhos',
    'SBGL' => 'Rio de Janeiro Galeao',
    'SCEL' => 'Santiago',
    'SKBO' => 'Bogota',
    'SPJC' => 'Lima',
    'SAEZ' => 'Buenos Aires Ezeiza',
    'SEQM' => 'Quito',
    'SVMI' => 'Caracas',
    'SUMU' => 'Montevideo',
    'SLLP' => 'La Paz',
    // Central America
    'MROC' => 'San Jose Costa Rica',
    'MPTO' => 'Panama City',
    'MGGT' => 'Guatemala City',
    // Africa - North
    'HECA' => 'Cairo',
    'HEGN' => 'Hurghada',
    'HESH' => 'Sharm El Sheikh',
    'GMMN' => 'Casablanca',
    'GMTT' => 'Tangier',
    'DTTA' => 'Tunis',
    'DAAG' => 'Algiers',
    // Africa - West
    'DNMM' => 'Lagos',
    'DNAA' => 'Abuja',
    'DGAA' => 'Accra',
    'DIAP' => 'Abidjan',
    'GOOY' => 'Dakar',
    // Africa - East
    'HKJK' => 'Nairobi',
    'HKMO' => 'Mombasa',
    'HAAB' => 'Addis Ababa',
    'HTDA' => 'Dar es Salaam',
    'HTKJ' => 'Kilimanjaro',
    'HUEN' => 'Entebbe',
    'HRYR' => 'Kigali',
    'FMMI' => 'Antananarivo',
    'FMEE' => 'Mauritius',
    'FSIA' => 'Seychelles',
    // Africa - South
    'FAOR' => 'Johannesburg',
    'FACT' => 'Cape Town',
    'FALE' => 'Durban',
    'FAPE' => 'Port Elizabeth',
    'FBSK' => 'Gaborone',
    'FLKK' => 'Lusaka',
    'FVHA' => 'Harare',
    'FQMA' => 'Maputo',
    'FWKI' => 'Lilongwe',
    'FYWH' => 'Windhoek'
];
$intlAirportCodes = array_keys($majorInternationalAirports);

// Build WHERE clause
$whereClauses = [];
$params = [];

// Only include airports with ICAO codes (4 characters)
$whereClauses[] = "ICAO_ID IS NOT NULL AND LEN(ICAO_ID) = 4";

// Filter by category - default to ASPM77 + major international
if ($category === 'aspm77') {
    $whereClauses[] = "ASPM77 = 1";
} elseif ($category === 'oep35') {
    $whereClauses[] = "OEP35 = 1";
} elseif ($category === 'core30') {
    $whereClauses[] = "Core30 = 1";
} elseif ($category === 'all' || $category === '') {
    // Default: ASPM77 US airports + major international airports
    $intlPlaceholders = array_fill(0, count($intlAirportCodes), '?');
    $whereClauses[] = "(ASPM77 = 1 OR ICAO_ID IN (" . implode(", ", $intlPlaceholders) . "))";
    $params = array_merge($params, $intlAirportCodes);
}

// Filter by ARTCC or tier ARTCCs
if (!empty($tierARTCCs)) {
    // Filter by multiple ARTCCs from tier
    $placeholders = [];
    foreach ($tierARTCCs as $tierArtcc) {
        $placeholders[] = "?";
        $params[] = $tierArtcc;
    }
    $whereClauses[] = "RESP_ARTCC_ID IN (" . implode(", ", $placeholders) . ")";
} elseif (!empty($artcc)) {
    // Filter by single ARTCC
    $whereClauses[] = "RESP_ARTCC_ID = ?";
    $params[] = $artcc;
}

// Filter by search term (ICAO or name)
if (!empty($search)) {
    $whereClauses[] = "(ICAO_ID LIKE ? OR ARPT_NAME LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereSQL = implode(" AND ", $whereClauses);

// Query airports
$sql = "
    SELECT
        ICAO_ID,
        ARPT_NAME,
        RESP_ARTCC_ID,
        DCC_REGION,
        ASPM77,
        OEP35,
        Core30,
        LAT_DECIMAL,
        LONG_DECIMAL
    FROM dbo.apts
    WHERE {$whereSQL}
    ORDER BY
        CASE
            WHEN Core30 = 1 THEN 1
            WHEN OEP35 = 1 THEN 2
            WHEN ASPM77 = 1 THEN 3
            ELSE 4
        END,
        ICAO_ID
";

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database error when querying airports.",
        "sql_error" => adl_sql_error_message()
    ]);
    sqlsrv_close($conn);
    exit;
}

// Build response
$airports = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $airports[] = [
        "icao" => $row['ICAO_ID'],
        "name" => $row['ARPT_NAME'],
        "artcc" => $row['RESP_ARTCC_ID'],
        "dcc_region" => $row['DCC_REGION'],
        "is_aspm77" => $row['ASPM77'] == 1,
        "is_oep35" => $row['OEP35'] == 1,
        "is_core30" => $row['Core30'] == 1,
        "lat" => $row['LAT_DECIMAL'] !== null ? (float)$row['LAT_DECIMAL'] : null,
        "lon" => $row['LONG_DECIMAL'] !== null ? (float)$row['LONG_DECIMAL'] : null
    ];
}

sqlsrv_free_stmt($stmt);

// For 'all' category, add any international airports not in the database
if ($category === 'all' || $category === '') {
    $existingIcaos = array_column($airports, 'icao');
    foreach ($majorInternationalAirports as $icao => $name) {
        if (!in_array($icao, $existingIcaos)) {
            $airports[] = [
                "icao" => $icao,
                "name" => $name,
                "artcc" => null,
                "dcc_region" => null,
                "is_aspm77" => false,
                "is_oep35" => false,
                "is_core30" => false,
                "is_international" => true,
                "lat" => null,
                "lon" => null
            ];
        }
    }
    // Sort: Core30 first, then OEP35, then ASPM77, then international, then alphabetically
    usort($airports, function($a, $b) {
        if ($a['is_core30'] !== $b['is_core30']) return $b['is_core30'] ? 1 : -1;
        if ($a['is_oep35'] !== $b['is_oep35']) return $b['is_oep35'] ? 1 : -1;
        if ($a['is_aspm77'] !== $b['is_aspm77']) return $b['is_aspm77'] ? 1 : -1;
        return strcmp($a['icao'], $b['icao']);
    });
}

// Also get list of unique ARTCCs for filter dropdown
$artccSql = "
    SELECT DISTINCT RESP_ARTCC_ID
    FROM dbo.apts
    WHERE RESP_ARTCC_ID IS NOT NULL AND RESP_ARTCC_ID != ''
    ORDER BY RESP_ARTCC_ID
";
$artccStmt = sqlsrv_query($conn, $artccSql);
$artccList = [];
if ($artccStmt !== false) {
    while ($row = sqlsrv_fetch_array($artccStmt, SQLSRV_FETCH_ASSOC)) {
        $artccList[] = $row['RESP_ARTCC_ID'];
    }
    sqlsrv_free_stmt($artccStmt);
}

sqlsrv_close($conn);

// Return response
echo json_encode([
    "success" => true,
    "timestamp" => gmdate("Y-m-d\\TH:i:s\\Z"),
    "filters" => [
        "category" => $category,
        "artcc" => $artcc,
        "tier" => $tier,
        "search" => $search
    ],
    "count" => count($airports),
    "airports" => $airports,
    "artcc_list" => $artccList
]);
