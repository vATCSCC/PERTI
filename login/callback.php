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
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $json = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("VATSIM OAuth token error: " . curl_error($ch));
        curl_close($ch);
        echo 'Unable to connect to VATSIM authentication service. Please try again later.';
        exit;
    }

    curl_close($ch);
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
        curl_setopt($ch_at, CURLOPT_HTTPHEADER, $array);
        curl_setopt($ch_at, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_at, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch_at, CURLOPT_TIMEOUT, 10);

        $json_at = curl_exec($ch_at);

        if (curl_errno($ch_at)) {
            error_log("VATSIM OAuth user fetch error: " . curl_error($ch_at));
            curl_close($ch_at);
            echo 'Unable to retrieve user information from VATSIM. Please try again later.';
            exit;
        }

        curl_close($ch_at);
        $obj_at = json_decode($json_at, true);
        // END: cUrl to VATSIM Connect 

        $cid = $obj_at['data']['cid'] ?? null;

        if ($cid) {
            // Use prepared statement to prevent SQL injection
            $check_stmt = mysqli_prepare($conn_sqli, "SELECT COUNT(*) as 'total', first_name, last_name FROM users WHERE cid=?");
            mysqli_stmt_bind_param($check_stmt, "i", $cid);
            mysqli_stmt_execute($check_stmt);
            $check_run = mysqli_stmt_get_result($check_stmt);

            $check_array = mysqli_fetch_array($check_run);

            if ($check_array['total'] > 0) {
                // User is authorized - create session
                $first_name = $check_array['first_name'];
                $last_name = $check_array['last_name'];

                // Get return URL before session regeneration clears it
                $return_url = $_SESSION['LOGIN_RETURN_URL'] ?? null;
                unset($_SESSION['LOGIN_RETURN_URL']);

                // Start the session and redirect
                sessionstart($cid, $first_name, $last_name);

                // Auto-detect org from VATSIM division for new users
                $division = $obj_at['data']['vatsim']['division']['id'] ?? null;
                $auto_org = 'vatcscc';
                if ($division === 'VATCAN' || $division === 'CAN') {
                    $auto_org = 'vatcan';
                }

                // Check if user already has org assignment
                $org_check = mysqli_prepare($conn_sqli, "SELECT COUNT(*) as cnt FROM user_orgs WHERE cid = ?");
                mysqli_stmt_bind_param($org_check, "i", $cid);
                mysqli_stmt_execute($org_check);
                $org_result = mysqli_stmt_get_result($org_check);
                $org_row = mysqli_fetch_assoc($org_result);

                if ($org_row['cnt'] == 0) {
                    $insert_org = mysqli_prepare($conn_sqli, "INSERT INTO user_orgs (cid, org_code, is_privileged, is_primary) VALUES (?, ?, 0, 1)");
                    mysqli_stmt_bind_param($insert_org, "is", $cid, $auto_org);
                    mysqli_stmt_execute($insert_org);
                }

                // Load org context
                require_once dirname(__DIR__) . '/load/org_context.php';
                load_org_context((int)$cid, $conn_sqli);

                // Redirect to return URL or default to index
                $redirect_to = '../index';
                if ($return_url) {
                    // Validate again to prevent open redirect
                    $parsed = parse_url($return_url);
                    $current_host = $_SERVER['HTTP_HOST'] ?? '';
                    if (!isset($parsed['host']) || $parsed['host'] === $current_host) {
                        $redirect_to = $return_url;
                    }
                }
                header('Location: ' . $redirect_to);
                exit;

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