<?php

// Getting JSON (cURL Request)
$init_ch = curl_init();
curl_setopt_array($init_ch, [
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_URL => "https://www.aviationweather.gov/cgi-bin/json/SigmetJSON.php"
]);

$raw = curl_exec($init_ch);

echo($raw);

?>