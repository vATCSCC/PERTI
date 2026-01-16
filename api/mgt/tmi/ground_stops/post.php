<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include("../../../../load/config.php");
include("../../../../load/connect.php");

$domain = strip_tags(SITE_DOMAIN);

// Check Perms
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {

        // Getting CID Value
        $cid = session_get('VATSIM_CID', '');

        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");

        if ($p_check) {
            $perm = true;
        }

    }
} else {
    $perm = true;
    $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
}

// Check Perms (S)
if ($perm == true) {
    // Do Nothing
} else {
    http_response_code(403);
    exit();
}
// (E)

// Collect POST data
$id = isset($_POST['id']) ? post_int('id') : 0;
$status = isset($_POST['status']) ? post_int('status') : 0;

$name = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['name'] ?? "")));
$ctl_element = strip_tags($_POST['ctl_element'] ?? "");
$element_type = strip_tags($_POST['element_type'] ?? "APT");
$airports = strip_tags(strtoupper($_POST['airports'] ?? ""));
$start_utc = strip_tags($_POST['start_utc'] ?? "");
$end_utc = strip_tags($_POST['end_utc'] ?? "");
$prob_ext = isset($_POST['prob_ext']) ? post_int('prob_ext') : 0;
$origin_centers = strip_tags(strtoupper($_POST['origin_centers'] ?? ""));
$origin_airports = strip_tags(strtoupper($_POST['origin_airports'] ?? ""));
$flt_incl_carrier = strip_tags(strtoupper($_POST['flt_incl_carrier'] ?? ""));
$flt_incl_type = strip_tags($_POST['flt_incl_type'] ?? "ALL");
$dep_facilities = strip_tags(strtoupper($_POST['dep_facilities'] ?? ""));
$comments = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['comments'] ?? "")));
$adv_number = strip_tags($_POST['adv_number'] ?? "");
$advisory_text = strip_tags(html_entity_decode(str_replace("`", "&#039;", $_POST['advisory_text'] ?? "")));

// Basic validation
if ($name === "" || $ctl_element === "" || $airports === "") {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["error" => "Name, CTL ELEMENT, and Arrival Airports are required."]);
    exit();
}

// Insert or update
try {
    $conn_pdo->beginTransaction();

    if ($id > 0) {
        $sql = "UPDATE tmi_ground_stops SET 
            status='$status',
            name='$name',
            ctl_element='$ctl_element',
            element_type='$element_type',
            airports='$airports',
            start_utc='$start_utc',
            end_utc='$end_utc',
            prob_ext='$prob_ext',
            origin_centers='$origin_centers',
            origin_airports='$origin_airports',
            flt_incl_carrier='$flt_incl_carrier',
            flt_incl_type='$flt_incl_type',
            dep_facilities='$dep_facilities',
            comments='$comments',
            adv_number='$adv_number',
            advisory_text='$advisory_text'
        WHERE id='$id'";
        $conn_pdo->exec($sql);
    } else {
        $sql = "INSERT INTO tmi_ground_stops (
            status,
            name,
            ctl_element,
            element_type,
            airports,
            start_utc,
            end_utc,
            prob_ext,
            origin_centers,
            origin_airports,
            flt_incl_carrier,
            flt_incl_type,
            dep_facilities,
            comments,
            adv_number,
            advisory_text
        ) VALUES (
            '$status',
            '$name',
            '$ctl_element',
            '$element_type',
            '$airports',
            '$start_utc',
            '$end_utc',
            '$prob_ext',
            '$origin_centers',
            '$origin_airports',
            '$flt_incl_carrier',
            '$flt_incl_type',
            '$dep_facilities',
            '$comments',
            '$adv_number',
            '$advisory_text'
        )";
        $conn_pdo->exec($sql);
        $id = (int)$conn_pdo->lastInsertId();
    }

    $conn_pdo->commit();

    header('Content-Type: application/json');
    echo json_encode([
        "id" => (int)$id,
        "status" => (int)$status
    ]);
} catch (PDOException $e) {
    $conn_pdo->rollback();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        "error" => "Database error",
        "detail" => $e->getMessage()
    ]);
}

?>
