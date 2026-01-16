<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include_once(dirname(__DIR__, 1) . '/load/config.php');

// Generate Current IP Address
if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    $rawip = strip_tags($_SERVER['HTTP_CLIENT_IP']);
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $rawip = strip_tags($_SERVER['HTTP_X_FORWARDED_FOR']);
} else {
    $rawip = strip_tags($_SERVER['REMOTE_ADDR']);
}

if (strpos($rawip, ':') !== false) {
    $ip = explode(':', $rawip)[0];
} else {
    $ip = $rawip;
}

if (!defined('DEV')) {
    if (isset($_COOKIE["SELF"])) {
        $selfcookie = strip_tags($_COOKIE["SELF"]);        
    
        // START: cUrl to Session Check Script
        $url = "https://" . SITE_DOMAIN . "/sessions/query.php";
    
    
        $fields = [
            'ip' => $ip,
            'selfcookie' => $selfcookie
        ];
    
        $fields_string = http_build_query($fields);
    
        $ch = curl_init();
    
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    
        $json = curl_exec($ch);
        $obj = json_decode($json, true);
    
        echo curl_error($ch);
    
        curl_close($ch);
        // END: cUrl to Session Check Script
    
        if ($json && $obj['status'] == "success") {
    
            $_SESSION['VATSIM_CID'] = $obj['cid'];
            $_SESSION['VATSIM_FIRST_NAME'] = $obj['first_name'];
            $_SESSION['VATSIM_LAST_NAME'] = $obj['last_name'];
            setCookie("SELF", $selfcookie, time() + (10 * 365 * 24 * 60 * 60), "/");
    
        } else {
    
            if (isset($_SESSION['VATSIM_CID'])) {
                // START: cUrl to Session Check Script
                $url = "https://" . SITE_DOMAIN . "/sessions/cid.php";


                $fields = [
                    'ip' => $ip,
                    'cid' => strip_tags($_SESSION['VATSIM_CID'])
                ];
            
                $fields_string = http_build_query($fields);
            
                $ch = curl_init();
            
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            
                $json = curl_exec($ch);
                $obj = json_decode($json, true);
            
                echo curl_error($ch);
            
                curl_close($ch);
                // END: cUrl to Session Check Script
            
                if ($json && $obj['status'] == "success") {
            
                    $_SESSION['VATSIM_CID'] = $obj['cid'];
                    $_SESSION['VATSIM_FIRST_NAME'] = $obj['first_name'];
                    $_SESSION['VATSIM_LAST_NAME'] = $obj['last_name'];
                    setCookie("SELF", $selfcookie, time() + (10 * 365 * 24 * 60 * 60), "/");
            
                } else {
            
                    unset($_SESSION['VATSIM_CID']);
                    unset($_SESSION['VATSIM_FIRST_NAME']);
                    unset($_SESSION['VATSIM_LAST_NAME']);

                    session_destroy();
            
                    setCookie("PHPSESSID", "", time() - 3600, "/");
                    setCookie("SELF", "", time() - 3600, "/");
            
                }
            } else {
    
                unset($_SESSION['VATSIM_CID']);
                unset($_SESSION['VATSIM_FIRST_NAME']);
                unset($_SESSION['VATSIM_LAST_NAME']);
                
                session_destroy();
        
                setCookie("PHPSESSID", "", time() - 3600, "/");
                setCookie("SELF", "", time() - 3600, "/");
    
            }
    
        }
    
    } else {
    
        if (isset($_SESSION['VATSIM_CID'])) {
            // START: cUrl to Session Check Script
            $url = "https://" . SITE_DOMAIN . "/sessions/cid.php";


            $fields = [
                'ip' => $ip,
                'cid' => strip_tags($_SESSION['VATSIM_CID'])
            ];
        
            $fields_string = http_build_query($fields);
        
            $ch = curl_init();
        
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        
            $json = curl_exec($ch);
            $obj = json_decode($json, true);
        
            echo curl_error($ch);
        
            curl_close($ch);
            // END: cUrl to Session Check Script
        
            if ($json && $obj['status'] == "success") {
        
                $_SESSION['VATSIM_CID'] = $obj['cid'];
                $_SESSION['VATSIM_FIRST_NAME'] = $obj['first_name'];
                $_SESSION['VATSIM_LAST_NAME'] = $obj['last_name'];
                setCookie("SELF", $obj['selfcookie'], time() + (10 * 365 * 24 * 60 * 60), "/");

            } else {

                unset($_SESSION['VATSIM_CID']);
                unset($_SESSION['VATSIM_FIRST_NAME']);
                unset($_SESSION['VATSIM_LAST_NAME']);

                session_destroy();

                setCookie("PHPSESSID", "", time() - 3600, "/");
                setCookie("SELF", "", time() - 3600, "/");

            }
        } else {

            unset($_SESSION['VATSIM_CID']);
            unset($_SESSION['VATSIM_FIRST_NAME']);
            unset($_SESSION['VATSIM_LAST_NAME']);

            session_destroy();

            setCookie("PHPSESSID", "", time() - 3600, "/");
            setCookie("SELF", "", time() - 3600, "/");

        }

    }
} else {
    $_SESSION['VATSIM_CID'] = 0;
    $_SESSION['VATSIM_FIRST_NAME'] = '0';
    $_SESSION['VATSIM_LAST_NAME'] = '0';
}

?>