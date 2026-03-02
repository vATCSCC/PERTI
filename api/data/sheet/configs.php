<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../load/config.php");
include("../../../load/connect.php");

$p_id = get_input('p_id');

require_once(dirname(__DIR__, 3) . '/load/org_context.php');
if (!validate_plan_org((int)$p_id, $conn_sqli)) {
    http_response_code(403);
    exit();
}

header('Content-Type: application/json');

// Weather code to string mapping
$weatherMap = [1 => 'VMC', 2 => 'LVMC', 3 => 'IMC', 4 => 'LIMC'];

// Pre-load ADL rates for autofill if ADL connection available
$adlRates = [];
$conn_adl = function_exists('get_conn_adl') ? get_conn_adl() : null;

if ($conn_adl) {
    $planAirports = [];
    $tempQuery = $conn_sqli->query("SELECT DISTINCT airport FROM p_configs WHERE p_id='$p_id'");
    while ($r = $tempQuery->fetch_assoc()) {
        $planAirports[] = $r['airport'];
    }

    if (!empty($planAirports)) {
        $placeholders = implode(',', array_fill(0, count($planAirports), '?'));
        $sql = "SELECT s.airport_icao, s.arr_runways, s.dep_runways,
                       r.vatsim_vmc_aar, r.vatsim_lvmc_aar, r.vatsim_imc_aar, r.vatsim_limc_aar,
                       r.vatsim_vmc_adr, r.vatsim_lvmc_adr, r.vatsim_imc_adr, r.vatsim_limc_adr
                FROM dbo.vw_airport_config_summary s
                JOIN dbo.vw_airport_config_rates r ON s.config_id = r.config_id
                WHERE s.airport_icao IN ($placeholders) AND s.is_active = 1";

        $stmt = sqlsrv_query($conn_adl, $sql, $planAirports);
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $icao = $row['airport_icao'];
                $arr = $row['arr_runways'] ?? '';
                $dep = $row['dep_runways'] ?? '';
                $key = strtoupper($icao) . '|' . strtoupper(trim($arr)) . '|' . strtoupper(trim($dep));
                $adlRates[$key] = $row;
                if (!isset($adlRates[$icao])) {
                    $adlRates[$icao] = $row;
                }
            }
            sqlsrv_free_stmt($stmt);
        }
    }
}

$rows = [];
$query = $conn_sqli->query("SELECT * FROM p_configs WHERE p_id='$p_id'");

if ($query) {
    while ($data = mysqli_fetch_assoc($query)) {
        $row = [
            'id' => (int)$data['id'],
            'airport' => $data['airport'],
            'weather' => (int)$data['weather'],
            'arrive' => $data['arrive'],
            'depart' => $data['depart'],
            'aar' => $data['aar'],
            'adr' => $data['adr'],
            'comments' => $data['comments'],
            'has_autofill' => false,
            'autofill_aar' => 0,
            'autofill_adr' => 0,
        ];

        if (!empty($adlRates)) {
            $airport = strtoupper($data['airport']);
            $arrive = strtoupper(trim($data['arrive'] ?? ''));
            $depart = strtoupper(trim($data['depart'] ?? ''));
            $key = $airport . '|' . $arrive . '|' . $depart;
            $weatherCode = (int)$data['weather'];
            $weatherStr = $weatherMap[$weatherCode] ?? 'VMC';

            $rates = $adlRates[$key] ?? $adlRates[$airport] ?? null;

            if ($rates) {
                $row['has_autofill'] = true;
                $aarCol = 'vatsim_' . strtolower($weatherStr) . '_aar';
                $adrCol = 'vatsim_' . strtolower($weatherStr) . '_adr';
                $row['autofill_aar'] = $rates[$aarCol] ?? 0;
                $row['autofill_adr'] = $rates[$adrCol] ?? 0;
            }
        }

        $rows[] = $row;
    }
}

echo json_encode(['perm' => true, 'rows' => $rows]);
