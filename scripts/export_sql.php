<?php

/**
 * export_sql.php
 *
 * Web page that outputs SQL migration script.
 * Visit this page in browser, then save the output as .sql file.
 *
 * Usage: https://yoursite.com/scripts/export_sql.php
 */

include("../load/config.php");
include("../load/connect.php");

// Output as plain text for easy copying/saving
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="config_data_migration.sql"');

// Fetch data from MySQL
$query = mysqli_query($conn_sqli, "SELECT * FROM config_data ORDER BY airport ASC");

if (!$query) {
    die("-- ERROR: " . mysqli_error($conn_sqli));
}

$rows = [];
while ($row = mysqli_fetch_assoc($query)) {
    $rows[] = $row;
}

// Output SQL header
echo "-- =====================================================\n";
echo "-- Config Data Migration from MySQL\n";
echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
echo "-- Total records: " . count($rows) . "\n";
echo "-- Run this in SSMS or Azure Data Studio\n";
echo "-- =====================================================\n\n";

echo "SET NOCOUNT ON;\n";
echo "BEGIN TRANSACTION;\n\n";
echo "DECLARE @config_id INT;\n\n";

foreach ($rows as $row) {
    $airport = strtoupper(trim($row['airport']));
    $icao = (strlen($airport) == 3) ? 'K' . $airport : $airport;

    echo "-- =====================================================\n";
    echo "-- Airport: {$airport} ({$icao})\n";
    echo "-- =====================================================\n\n";

    echo "IF NOT EXISTS (SELECT 1 FROM dbo.airport_config WHERE airport_icao = '{$icao}' AND config_name = 'Default')\n";
    echo "BEGIN\n";

    // Insert config
    echo "    INSERT INTO dbo.airport_config (airport_faa, airport_icao, config_name, config_code)\n";
    echo "    VALUES ('{$airport}', '{$icao}', 'Default', NULL);\n\n";
    echo "    SET @config_id = SCOPE_IDENTITY();\n\n";

    // Arrival runways
    $arrRunways = trim($row['arr']);
    if (!empty($arrRunways)) {
        $runways = preg_split('/[,\/\s]+/', $arrRunways);
        $priority = 1;
        foreach ($runways as $rwy) {
            $rwy = strtoupper(trim($rwy));
            if (empty($rwy)) continue;
            echo "    INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority) VALUES (@config_id, '{$rwy}', 'ARR', {$priority});\n";
            $priority++;
        }
    }

    // Departure runways
    $depRunways = trim($row['dep']);
    if (!empty($depRunways)) {
        $runways = preg_split('/[,\/\s]+/', $depRunways);
        $priority = 1;
        foreach ($runways as $rwy) {
            $rwy = strtoupper(trim($rwy));
            if (empty($rwy)) continue;
            echo "    INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority) VALUES (@config_id, '{$rwy}', 'DEP', {$priority});\n";
            $priority++;
        }
    }

    echo "\n";

    // Rates
    $rates = [
        ['VMC', 'ARR', intval($row['vmc_aar'])],
        ['LVMC', 'ARR', intval($row['lvmc_aar'])],
        ['IMC', 'ARR', intval($row['imc_aar'])],
        ['LIMC', 'ARR', intval($row['limc_aar'])],
        ['VMC', 'DEP', intval($row['vmc_adr'])],
        ['IMC', 'DEP', intval($row['imc_adr'])],
    ];

    foreach ($rates as $rate) {
        list($weather, $type, $value) = $rate;
        if ($value > 0) {
            echo "    INSERT INTO dbo.airport_config_rate (config_id, source, weather, rate_type, rate_value) VALUES (@config_id, 'VATSIM', '{$weather}', '{$type}', {$value});\n";
        }
    }

    echo "\n    PRINT 'Migrated: {$airport} ({$icao})';\n";
    echo "END\n";
    echo "ELSE\n";
    echo "BEGIN\n";
    echo "    PRINT 'Skipped (exists): {$airport} ({$icao})';\n";
    echo "END\n\n";
}

echo "COMMIT TRANSACTION;\n\n";
echo "PRINT '';\n";
echo "PRINT 'Migration complete. " . count($rows) . " airports processed.';\n";
echo "GO\n";

?>
