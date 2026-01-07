# ============================================================================
# Import-CIFPToAzure.ps1
# Imports CIFP CSV files directly to Azure SQL using SqlBulkCopy
# Fast version - uses bulk operations for everything
# ============================================================================

param(
    [string]$CsvPath = ".\cifp_import",
    [string]$ConfigPath = "..\..\load\config.php",
    [switch]$ClearExisting
)

$ErrorActionPreference = "Stop"
$startTime = Get-Date

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "CIFP Azure SQL Importer (Fast)" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# ============================================================================
# Read credentials from config.php
# ============================================================================

$configFile = Join-Path $PSScriptRoot $ConfigPath
if (-not (Test-Path $configFile)) {
    Write-Host "ERROR: Config file not found: $configFile" -ForegroundColor Red
    exit 1
}

Write-Host "Reading credentials from config.php..." -ForegroundColor Yellow
$configContent = Get-Content $configFile -Raw

function Get-PhpConstant($content, $name) {
    if ($content -match "define\s*\(\s*[`"']$name[`"']\s*,\s*[`"']([^`"']+)[`"']\s*\)") {
        return $Matches[1]
    }
    return $null
}

$Server = Get-PhpConstant $configContent "ADL_SQL_HOST"
$Database = Get-PhpConstant $configContent "ADL_SQL_DATABASE"
# Use admin credentials for import (has CREATE TABLE permissions)
$Username = "jpeterson"
$Password = "***REMOVED***"

if (-not $Server -or -not $Database -or -not $Username -or -not $Password) {
    Write-Host "ERROR: Could not parse ADL_SQL_* credentials from config.php" -ForegroundColor Red
    exit 1
}

Write-Host "Server: $Server | Database: $Database" -ForegroundColor White
Write-Host ""

$connString = "Server=tcp:$Server,1433;Initial Catalog=$Database;Persist Security Info=False;User ID=$Username;Password=$Password;MultipleActiveResultSets=False;Encrypt=True;TrustServerCertificate=False;Connection Timeout=30;"

# Verify CSV files exist
$procCsv = Join-Path $CsvPath "cifp_procedures.csv"
$legsCsv = Join-Path $CsvPath "cifp_legs.csv"

if (-not (Test-Path $procCsv)) { Write-Host "ERROR: $procCsv not found" -ForegroundColor Red; exit 1 }
if (-not (Test-Path $legsCsv)) { Write-Host "ERROR: $legsCsv not found" -ForegroundColor Red; exit 1 }

# ============================================================================
# Connect
# ============================================================================

Write-Host "Connecting to Azure SQL..." -ForegroundColor Yellow
$conn = New-Object System.Data.SqlClient.SqlConnection($connString)
$conn.Open()
Write-Host "Connected!" -ForegroundColor Green
Write-Host ""

# ============================================================================
# Step 0: Ensure staging tables exist
# ============================================================================

Write-Host "Ensuring staging tables exist..." -ForegroundColor Yellow

$setupCmd = $conn.CreateCommand()
$setupCmd.CommandTimeout = 120
$setupCmd.CommandText = @"
IF OBJECT_ID('dbo.cifp_procedures_staging', 'U') IS NULL
CREATE TABLE dbo.cifp_procedures_staging (
    airport_icao        NVARCHAR(8) NOT NULL,
    procedure_type      NVARCHAR(8) NOT NULL,
    procedure_name      NVARCHAR(32) NOT NULL,
    runway_transition   NVARCHAR(16) NULL,
    cifp_file           NVARCHAR(32) NOT NULL
);

IF OBJECT_ID('dbo.cifp_legs_staging', 'U') IS NULL
CREATE TABLE dbo.cifp_legs_staging (
    airport_icao        NVARCHAR(8) NOT NULL,
    procedure_type      NVARCHAR(8) NOT NULL,
    procedure_name      NVARCHAR(32) NOT NULL,
    runway_transition   NVARCHAR(16) NULL,
    sequence_num        INT NOT NULL,
    route_type          TINYINT NOT NULL,
    fix_name            NVARCHAR(16) NULL,
    fix_region          NVARCHAR(4) NULL,
    fix_section         NVARCHAR(4) NULL,
    leg_type            CHAR(2) NOT NULL,
    outbound_course     DECIMAL(5,1) NULL,
    inbound_course      DECIMAL(5,1) NULL,
    distance_nm         DECIMAL(6,2) NULL,
    rec_navaid          NVARCHAR(16) NULL,
    rec_navaid_region   NVARCHAR(4) NULL,
    alt_restriction     CHAR(1) NULL,
    altitude_1_ft       INT NULL,
    altitude_2_ft       INT NULL,
    speed_limit_kts     SMALLINT NULL,
    speed_restriction   CHAR(1) NULL,
    is_flyover          BIT NOT NULL DEFAULT 0,
    is_hold_waypoint    BIT NOT NULL DEFAULT 0,
    cifp_file           NVARCHAR(32) NOT NULL
);

TRUNCATE TABLE dbo.cifp_procedures_staging;
TRUNCATE TABLE dbo.cifp_legs_staging;
"@
$setupCmd.ExecuteNonQuery() | Out-Null
Write-Host "  Staging tables ready" -ForegroundColor Green

# ============================================================================
# Step 1: Bulk load procedures to staging table
# ============================================================================

Write-Host "Step 1: Loading procedures to staging..." -ForegroundColor Yellow

# Create DataTable for procedures
$procDt = New-Object System.Data.DataTable
$procDt.Columns.Add("airport_icao", [string]) | Out-Null
$procDt.Columns.Add("procedure_type", [string]) | Out-Null
$procDt.Columns.Add("procedure_name", [string]) | Out-Null
$procDt.Columns.Add("runway_transition", [string]) | Out-Null
$procDt.Columns.Add("cifp_file", [string]) | Out-Null

$procedures = Import-Csv $procCsv
foreach ($proc in $procedures) {
    $row = $procDt.NewRow()
    $row["airport_icao"] = $proc.airport_icao
    $row["procedure_type"] = $proc.procedure_type
    $row["procedure_name"] = $proc.procedure_name
    $row["runway_transition"] = if ($proc.runway_transition) { $proc.runway_transition } else { [DBNull]::Value }
    $row["cifp_file"] = $proc.cifp_file
    $procDt.Rows.Add($row) | Out-Null
}

$bulkCopy = New-Object System.Data.SqlClient.SqlBulkCopy($conn)
$bulkCopy.DestinationTableName = "dbo.cifp_procedures_staging"
$bulkCopy.BulkCopyTimeout = 300
$bulkCopy.WriteToServer($procDt)
$bulkCopy.Close()

Write-Host "  Loaded $($procDt.Rows.Count) procedures to staging" -ForegroundColor Green

# ============================================================================
# Step 2: Bulk load legs to staging table
# ============================================================================

Write-Host "Step 2: Loading legs to staging..." -ForegroundColor Yellow

# Create DataTable for legs
$legsDt = New-Object System.Data.DataTable
$legsDt.Columns.Add("airport_icao", [string]) | Out-Null
$legsDt.Columns.Add("procedure_type", [string]) | Out-Null
$legsDt.Columns.Add("procedure_name", [string]) | Out-Null
$legsDt.Columns.Add("runway_transition", [string]) | Out-Null
$legsDt.Columns.Add("sequence_num", [int]) | Out-Null
$legsDt.Columns.Add("route_type", [byte]) | Out-Null
$legsDt.Columns.Add("fix_name", [string]) | Out-Null
$legsDt.Columns.Add("fix_region", [string]) | Out-Null
$legsDt.Columns.Add("fix_section", [string]) | Out-Null
$legsDt.Columns.Add("leg_type", [string]) | Out-Null
$legsDt.Columns.Add("outbound_course", [decimal]) | Out-Null
$legsDt.Columns.Add("inbound_course", [decimal]) | Out-Null
$legsDt.Columns.Add("distance_nm", [decimal]) | Out-Null
$legsDt.Columns.Add("rec_navaid", [string]) | Out-Null
$legsDt.Columns.Add("rec_navaid_region", [string]) | Out-Null
$legsDt.Columns.Add("alt_restriction", [string]) | Out-Null
$legsDt.Columns.Add("altitude_1_ft", [int]) | Out-Null
$legsDt.Columns.Add("altitude_2_ft", [int]) | Out-Null
$legsDt.Columns.Add("speed_limit_kts", [int16]) | Out-Null
$legsDt.Columns.Add("speed_restriction", [string]) | Out-Null
$legsDt.Columns.Add("is_flyover", [bool]) | Out-Null
$legsDt.Columns.Add("is_hold_waypoint", [bool]) | Out-Null
$legsDt.Columns.Add("cifp_file", [string]) | Out-Null

$legsCount = 0
$batchSize = 100000

$legs = Import-Csv $legsCsv
Write-Host "  Processing $($legs.Count) legs..." -ForegroundColor Gray

foreach ($leg in $legs) {
    $row = $legsDt.NewRow()
    $row["airport_icao"] = $leg.airport_icao
    $row["procedure_type"] = $leg.procedure_type
    $row["procedure_name"] = $leg.procedure_name
    $row["runway_transition"] = if ($leg.runway_transition) { $leg.runway_transition } else { [DBNull]::Value }
    $row["sequence_num"] = [int]$leg.sequence_num
    $row["route_type"] = [byte]$leg.route_type
    $row["fix_name"] = if ($leg.fix_name) { $leg.fix_name } else { [DBNull]::Value }
    $row["fix_region"] = if ($leg.fix_region) { $leg.fix_region } else { [DBNull]::Value }
    $row["fix_section"] = if ($leg.fix_section) { $leg.fix_section } else { [DBNull]::Value }
    $row["leg_type"] = $leg.leg_type
    $row["outbound_course"] = if ($leg.outbound_course) { [decimal]$leg.outbound_course } else { [DBNull]::Value }
    $row["inbound_course"] = if ($leg.inbound_course) { [decimal]$leg.inbound_course } else { [DBNull]::Value }
    $row["distance_nm"] = if ($leg.distance_nm) { [decimal]$leg.distance_nm } else { [DBNull]::Value }
    $row["rec_navaid"] = if ($leg.rec_navaid) { $leg.rec_navaid } else { [DBNull]::Value }
    $row["rec_navaid_region"] = if ($leg.rec_navaid_region) { $leg.rec_navaid_region } else { [DBNull]::Value }
    $row["alt_restriction"] = if ($leg.alt_restriction) { $leg.alt_restriction } else { [DBNull]::Value }
    $row["altitude_1_ft"] = if ($leg.altitude_1_ft) { [int]$leg.altitude_1_ft } else { [DBNull]::Value }
    $row["altitude_2_ft"] = if ($leg.altitude_2_ft) { [int]$leg.altitude_2_ft } else { [DBNull]::Value }
    $row["speed_limit_kts"] = if ($leg.speed_limit_kts) { [int16]$leg.speed_limit_kts } else { [DBNull]::Value }
    $row["speed_restriction"] = if ($leg.speed_restriction) { $leg.speed_restriction } else { [DBNull]::Value }
    $row["is_flyover"] = [bool]([int]$leg.is_flyover -eq 1)
    $row["is_hold_waypoint"] = [bool]([int]$leg.is_hold_waypoint -eq 1)
    $row["cifp_file"] = $leg.cifp_file
    $legsDt.Rows.Add($row) | Out-Null
    $legsCount++

    if ($legsDt.Rows.Count -ge $batchSize) {
        Write-Host "    Uploading batch ($legsCount legs)..." -ForegroundColor Gray
        $bulkCopy = New-Object System.Data.SqlClient.SqlBulkCopy($conn)
        $bulkCopy.DestinationTableName = "dbo.cifp_legs_staging"
        $bulkCopy.BulkCopyTimeout = 300
        $bulkCopy.WriteToServer($legsDt)
        $bulkCopy.Close()
        $legsDt.Clear()
    }
}

if ($legsDt.Rows.Count -gt 0) {
    $bulkCopy = New-Object System.Data.SqlClient.SqlBulkCopy($conn)
    $bulkCopy.DestinationTableName = "dbo.cifp_legs_staging"
    $bulkCopy.BulkCopyTimeout = 300
    $bulkCopy.WriteToServer($legsDt)
    $bulkCopy.Close()
}

Write-Host "  Loaded $legsCount legs to staging" -ForegroundColor Green

# ============================================================================
# Step 3: Execute merge via SQL (much faster than row-by-row)
# ============================================================================

Write-Host "Step 3: Merging data into production tables..." -ForegroundColor Yellow

$mergeCmd = $conn.CreateCommand()
$mergeCmd.CommandTimeout = 600
$mergeCmd.CommandText = @"
-- Update existing procedures
UPDATE np
SET np.has_leg_detail = 1,
    np.cifp_file = s.cifp_file,
    np.cifp_import_utc = GETUTCDATE()
FROM dbo.nav_procedures np
INNER JOIN (
    SELECT DISTINCT airport_icao, procedure_type, procedure_name, cifp_file
    FROM dbo.cifp_procedures_staging
) s ON np.airport_icao = s.airport_icao
   AND np.procedure_name = s.procedure_name
   AND np.procedure_type = s.procedure_type;

DECLARE @updated INT = @@ROWCOUNT;

-- Insert new procedures
INSERT INTO dbo.nav_procedures (
    procedure_type, airport_icao, procedure_name, computer_code,
    transition_name, runways, is_active, source, has_leg_detail, cifp_file, cifp_import_utc
)
SELECT DISTINCT
    s.procedure_type, s.airport_icao, s.procedure_name,
    CASE WHEN s.runway_transition IS NOT NULL AND s.runway_transition != '' AND s.runway_transition != 'ALL'
         THEN s.procedure_name + '.' + s.runway_transition ELSE s.procedure_name END,
    s.runway_transition,
    CASE WHEN s.runway_transition LIKE 'RW%' THEN SUBSTRING(s.runway_transition, 3, 10) ELSE NULL END,
    1, 'CIFP', 1, s.cifp_file, GETUTCDATE()
FROM dbo.cifp_procedures_staging s
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.nav_procedures np
    WHERE np.airport_icao = s.airport_icao
      AND np.procedure_name = s.procedure_name
      AND np.procedure_type = s.procedure_type
);

DECLARE @inserted INT = @@ROWCOUNT;

-- Delete existing legs for updated procedures
DELETE pl
FROM dbo.nav_procedure_legs pl
INNER JOIN dbo.nav_procedures np ON pl.procedure_id = np.procedure_id
WHERE np.cifp_file IS NOT NULL;

-- Insert all legs
INSERT INTO dbo.nav_procedure_legs (
    procedure_id, sequence_num, route_type, fix_name, fix_region, fix_section,
    leg_type, outbound_course, inbound_course, distance_nm, rec_navaid, rec_navaid_region,
    alt_restriction, altitude_1_ft, altitude_2_ft, speed_limit_kts, speed_restriction,
    is_flyover, is_hold_waypoint
)
SELECT
    np.procedure_id, s.sequence_num, s.route_type,
    NULLIF(s.fix_name, ''), NULLIF(s.fix_region, ''), NULLIF(s.fix_section, ''),
    s.leg_type, s.outbound_course, s.inbound_course, s.distance_nm,
    NULLIF(s.rec_navaid, ''), NULLIF(s.rec_navaid_region, ''),
    NULLIF(s.alt_restriction, ''), s.altitude_1_ft, s.altitude_2_ft,
    s.speed_limit_kts, NULLIF(s.speed_restriction, ''),
    s.is_flyover, s.is_hold_waypoint
FROM dbo.cifp_legs_staging s
INNER JOIN dbo.nav_procedures np
    ON np.airport_icao = s.airport_icao
   AND np.procedure_name = s.procedure_name
   AND np.procedure_type = s.procedure_type
   AND COALESCE(np.transition_name, '') = COALESCE(s.runway_transition, '')
WHERE np.has_leg_detail = 1;

DECLARE @legs INT = @@ROWCOUNT;

-- Build full_route strings
;WITH route_fixes AS (
    SELECT procedure_id,
           STRING_AGG(fix_name, ' ') WITHIN GROUP (ORDER BY sequence_num) AS fix_sequence
    FROM dbo.nav_procedure_legs
    WHERE fix_name IS NOT NULL
    GROUP BY procedure_id
)
UPDATE np
SET np.full_route = rf.fix_sequence
FROM dbo.nav_procedures np
INNER JOIN route_fixes rf ON np.procedure_id = rf.procedure_id
WHERE np.source = 'CIFP' AND (np.full_route IS NULL OR np.full_route = '');

SELECT @updated AS updated, @inserted AS inserted, @legs AS legs;
"@

$reader = $mergeCmd.ExecuteReader()
if ($reader.Read()) {
    $updated = $reader["updated"]
    $inserted = $reader["inserted"]
    $legsInserted = $reader["legs"]
    Write-Host "  Procedures updated: $updated" -ForegroundColor Green
    Write-Host "  Procedures inserted: $inserted" -ForegroundColor Green
    Write-Host "  Legs inserted: $legsInserted" -ForegroundColor Green
}
$reader.Close()

# ============================================================================
# Done
# ============================================================================

$conn.Close()
$elapsed = (Get-Date) - $startTime

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Import Complete!" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Elapsed time: $([math]::Round($elapsed.TotalSeconds, 1)) seconds" -ForegroundColor White
