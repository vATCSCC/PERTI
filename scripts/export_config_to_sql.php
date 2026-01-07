<?php

/**
 * export_config_to_sql.php
 *
 * Exports MySQL config_data to a .sql file that can be run in SSMS/Azure Data Studio.
 * This script only needs MySQL connection (no sqlsrv required).
 *
 * Output: scripts/config_data_migration.sql
 */

// Include MySQL connection only
$scriptDir = __DIR__;
$baseDir = dirname($scriptDir);

require_once $baseDir . '/load/config.php';

// MySQL connection only
$conn = mysqli_connect(SQL_HOST, SQL_USERNAME, SQL_PASSWORD, SQL_DATABASE);
if (!$conn) {
    die("MySQL connection failed: " . mysqli_connect_error() . "\n");
}

echo "Connected to MySQL.\n";

// Fetch data
$query = mysqli_query($conn, "SELECT * FROM config_data ORDER BY airport ASC");
if (!$query) {
    die("Query failed: " . mysqli_error($conn) . "\n");
}

$rows = [];
while ($row = mysqli_fetch_assoc($query)) {
    $rows[] = $row;
}

echo "Found " . count($rows) . " configurations.\n";

// Generate SQL file
$outputFile = $scriptDir . '/config_data_migration.sql';
$fp = fopen($outputFile, 'w');

fwrite($fp, "-- =====================================================\n");
fwrite($fp, "-- Config Data Migration from MySQL\n");
fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
fwrite($fp, "-- Run this in SSMS or Azure Data Studio\n");
fwrite($fp, "-- =====================================================\n\n");

fwrite($fp, "SET NOCOUNT ON;\n");
fwrite($fp, "BEGIN TRANSACTION;\n\n");

fwrite($fp, "DECLARE @config_id INT;\n\n");

$count = 0;
foreach ($rows as $row) {
    $airport = strtoupper(trim($row['airport']));
    $icao = (strlen($airport) == 3) ? 'K' . $airport : $airport;

    fwrite($fp, "-- =====================================================\n");
    fwrite($fp, "-- Airport: {$airport} ({$icao})\n");
    fwrite($fp, "-- =====================================================\n\n");

    // Check if exists and skip
    fwrite($fp, "IF NOT EXISTS (SELECT 1 FROM dbo.airport_config WHERE airport_icao = '{$icao}' AND config_name = 'Default')\n");
    fwrite($fp, "BEGIN\n");

    // Insert config
    fwrite($fp, "    INSERT INTO dbo.airport_config (airport_faa, airport_icao, config_name, config_code)\n");
    fwrite($fp, "    VALUES ('{$airport}', '{$icao}', 'Default', NULL);\n\n");
    fwrite($fp, "    SET @config_id = SCOPE_IDENTITY();\n\n");

    // Insert arrival runways
    $arrRunways = trim($row['arr']);
    if (!empty($arrRunways)) {
        $runways = preg_split('/[,\/\s]+/', $arrRunways);
        $priority = 1;
        foreach ($runways as $rwy) {
            $rwy = strtoupper(trim($rwy));
            if (empty($rwy)) continue;
            fwrite($fp, "    INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority) VALUES (@config_id, '{$rwy}', 'ARR', {$priority});\n");
            $priority++;
        }
    }

    // Insert departure runways
    $depRunways = trim($row['dep']);
    if (!empty($depRunways)) {
        $runways = preg_split('/[,\/\s]+/', $depRunways);
        $priority = 1;
        foreach ($runways as $rwy) {
            $rwy = strtoupper(trim($rwy));
            if (empty($rwy)) continue;
            fwrite($fp, "    INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority) VALUES (@config_id, '{$rwy}', 'DEP', {$priority});\n");
            $priority++;
        }
    }

    fwrite($fp, "\n");

    // Insert rates
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
            fwrite($fp, "    INSERT INTO dbo.airport_config_rate (config_id, source, weather, rate_type, rate_value) VALUES (@config_id, 'VATSIM', '{$weather}', '{$type}', {$value});\n");
        }
    }

    fwrite($fp, "\n    PRINT 'Migrated: {$airport} ({$icao})';\n");
    fwrite($fp, "END\n");
    fwrite($fp, "ELSE\n");
    fwrite($fp, "BEGIN\n");
    fwrite($fp, "    PRINT 'Skipped (exists): {$airport} ({$icao})';\n");
    fwrite($fp, "END\n\n");

    $count++;
}

fwrite($fp, "COMMIT TRANSACTION;\n\n");
fwrite($fp, "PRINT '';\n");
fwrite($fp, "PRINT 'Migration complete. {$count} airports processed.';\n");
fwrite($fp, "GO\n");

fclose($fp);

echo "Generated: {$outputFile}\n";
echo "Run this file in SSMS or Azure Data Studio against your ADL database.\n";

?>
