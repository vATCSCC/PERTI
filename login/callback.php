<?php

include_once(dirname(__DIR__, 1) . '/sessions/handler.php');
// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include_once(dirname(__DIR__, 1) . '/load/config.php');
include_once(dirname(__DIR__, 1) . '/load/connect.php');

if (isset($_GET['code'])) {
    // Setting Values
    $url_base = CONNECT_URL_BASE;

    $client_id = CONNECT_CLIENT_ID;
    $client_secret = CONNECT_SECRET;
    $redirect_uri = CONNECT_REDIRECT_URI;

    $code = $_GET['code'];

    // START: cUrl to VATSIM Connect (/oauth/token)
    $fields = [
        'grant_type' => 'authorization_code',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'code' => $code
    ];

    $fields_string = http_build_query($fields);

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url_base . '/oauth/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  
    
    $json = curl_exec($ch);
    $obj = json_decode($json, true);
    // END: cUrl to VATSIM Connect

    // ---> Check if we got a valid response
    $token_type = $obj['token_type'] ?? null;
    $access_token = $obj['access_token'] ?? null;

    if ($access_token) {
        // START: cUrl to VATSIM Connect (/api/user)
        $array = [
            "Authorization: $token_type $access_token",
            "Accept: application/json"
        ];

        $ch_at = curl_init();

        curl_setopt($ch_at, CURLOPT_URL, $url_base . '/api/user');
        // curl_setopt($ch_at, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch_at, CURLOPT_HTTPHEADER, $array);
        curl_setopt($ch_at, CURLOPT_RETURNTRANSFER, true);  
        
        $json_at = curl_exec($ch_at);
        $obj_at = json_decode($json_at, true);

        curl_close($ch_at);
        // END: cUrl to VATSIM Connect 

        $cid = $obj_at['data']['cid'] ?? null;

        if ($cid) {
            $check_query = "SELECT COUNT(*) as 'total', first_name, last_name FROM users WHERE cid=$cid";
            $check_run = mysqli_query($conn_sqli, $check_query);

            $check_array = mysqli_fetch_array($check_run);

            if ($check_array['total'] > 0) {
                // Setting Values
                $first_name = $check_array['first_name'];
                $last_name = $check_array['last_name'];

                    // Generate Current IP Address (use server_get for safe access)
                    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                        $rawip = server_get('HTTP_CLIENT_IP');
                    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                        $rawip = server_get('HTTP_X_FORWARDED_FOR');
                    } else {
                        $rawip = server_get('REMOTE_ADDR', '127.0.0.1');
                    }

                    if (strpos($rawip, ':') !== false) {
                        $ip = explode(':', $rawip)[0];
                    } else {
                        $ip = $rawip;
                    }

                    // Create Self-Identifying Cookie
                    $selfcookie = uniqid() . $cid . random_int(1000, 5000);

                    setcookie("SELF", $selfcookie, time() + (86400 * 30), "/");

                $update_query = "UPDATE users SET last_session_ip='$ip', last_selfcookie='$selfcookie' WHERE cid=$cid";

                if (mysqli_query($conn_sqli, $update_query)) {
                    sessionstart($cid, $first_name, $last_name);
                    header('Location: ../index');
                }

            } else {
                // Error: Not on Rosters
                echo 'You do not appear on the list of priveleged users for this system.';
            }

        } else {
            // Error: VATSIM Error
            echo 'An error occured when attempting to authorize with VATSIM Connect. Please try again later';
        }
    } else {
        // Error: VATSIM Error
        echo 'An error occured when attempting to authorize with VATSIM Connect. Please try again later';
    }

} else {
    header('Location: index.php');
}


// Function : Session Start
function sessionstart($cid, $first_name, $last_name) {

    // Generate Initial Session
    session_regenerate_id();

    // Safely sanitize values (handle potential null)
    $_SESSION["VATSIM_CID"] = strip_tags($cid ?? '');
    $_SESSION["VATSIM_FIRST_NAME"] = strip_tags($first_name ?? '');
    $_SESSION["VATSIM_LAST_NAME"] = strip_tags($last_name ?? '');

}

?>