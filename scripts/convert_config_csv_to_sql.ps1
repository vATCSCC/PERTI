# convert_config_csv_to_sql.ps1
#
# Converts config_data CSV export to SQL Server INSERT statements.
#
# Usage:
#   1. Export config_data from phpMyAdmin as CSV (with headers)
#   2. Save as scripts/config_data.csv
#   3. Run: powershell -ExecutionPolicy Bypass -File scripts/convert_config_csv_to_sql.ps1
#
# Output: scripts/config_data_migration.sql

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$csvFile = Join-Path $scriptDir "config_data.csv"
$sqlFile = Join-Path $scriptDir "config_data_migration.sql"

if (-not (Test-Path $csvFile)) {
    Write-Host "ERROR: $csvFile not found" -ForegroundColor Red
    Write-Host ""
    Write-Host "Please export config_data from phpMyAdmin:" -ForegroundColor Yellow
    Write-Host "  1. Open phpMyAdmin"
    Write-Host "  2. Select your database"
    Write-Host "  3. Click on config_data table"
    Write-Host "  4. Click 'Export' tab"
    Write-Host "  5. Format: CSV"
    Write-Host "  6. Check 'Include column names in first row'"
    Write-Host "  7. Save as: scripts/config_data.csv"
    exit 1
}

Write-Host "Reading $csvFile..."
$data = Import-Csv $csvFile

Write-Host "Found $($data.Count) configurations."

# Start building SQL
$sql = @"
-- =====================================================
-- Config Data Migration from MySQL
-- Generated: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")
-- Run this in SSMS or Azure Data Studio
-- =====================================================

SET NOCOUNT ON;
BEGIN TRANSACTION;

DECLARE @config_id INT;

"@

foreach ($row in $data) {
    $airport = $row.airport.ToUpper().Trim()
    $icao = if ($airport.Length -eq 3) { "K$airport" } else { $airport }

    $sql += @"

-- =====================================================
-- Airport: $airport ($icao)
-- =====================================================

IF NOT EXISTS (SELECT 1 FROM dbo.airport_config WHERE airport_icao = '$icao' AND config_name = 'Default')
BEGIN
    INSERT INTO dbo.airport_config (airport_faa, airport_icao, config_name, config_code)
    VALUES ('$airport', '$icao', 'Default', NULL);

    SET @config_id = SCOPE_IDENTITY();

"@

    # Arrival runways
    $arrRunways = $row.arr.Trim()
    if ($arrRunways) {
        $runways = $arrRunways -split '[,/\s]+'
        $priority = 1
        foreach ($rwy in $runways) {
            $rwy = $rwy.ToUpper().Trim()
            if ($rwy) {
                $sql += "    INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority) VALUES (@config_id, '$rwy', 'ARR', $priority);`n"
                $priority++
            }
        }
    }

    # Departure runways
    $depRunways = $row.dep.Trim()
    if ($depRunways) {
        $runways = $depRunways -split '[,/\s]+'
        $priority = 1
        foreach ($rwy in $runways) {
            $rwy = $rwy.ToUpper().Trim()
            if ($rwy) {
                $sql += "    INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority) VALUES (@config_id, '$rwy', 'DEP', $priority);`n"
                $priority++
            }
        }
    }

    $sql += "`n"

    # Rates
    $rates = @(
        @{ weather = 'VMC'; type = 'ARR'; value = [int]$row.vmc_aar },
        @{ weather = 'LVMC'; type = 'ARR'; value = [int]$row.lvmc_aar },
        @{ weather = 'IMC'; type = 'ARR'; value = [int]$row.imc_aar },
        @{ weather = 'LIMC'; type = 'ARR'; value = [int]$row.limc_aar },
        @{ weather = 'VMC'; type = 'DEP'; value = [int]$row.vmc_adr },
        @{ weather = 'IMC'; type = 'DEP'; value = [int]$row.imc_adr }
    )

    foreach ($rate in $rates) {
        if ($rate.value -gt 0) {
            $sql += "    INSERT INTO dbo.airport_config_rate (config_id, source, weather, rate_type, rate_value) VALUES (@config_id, 'VATSIM', '$($rate.weather)', '$($rate.type)', $($rate.value));`n"
        }
    }

    $sql += @"

    PRINT 'Migrated: $airport ($icao)';
END
ELSE
BEGIN
    PRINT 'Skipped (exists): $airport ($icao)';
END

"@
}

$sql += @"
COMMIT TRANSACTION;

PRINT '';
PRINT 'Migration complete. $($data.Count) airports processed.';
GO
"@

# Write output
$sql | Out-File -FilePath $sqlFile -Encoding UTF8
Write-Host ""
Write-Host "Generated: $sqlFile" -ForegroundColor Green
Write-Host "Run this file in SSMS or Azure Data Studio against your ADL database."
