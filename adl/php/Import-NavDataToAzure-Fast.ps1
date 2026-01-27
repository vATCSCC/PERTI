# ============================================================================
# Import-NavDataToAzure-Fast.ps1
# Uses SqlBulkCopy for 10-50x faster imports to Azure SQL
# ============================================================================

param(
    [string]$CsvPath = ".\nav_import",
    [string]$Server = "vatsim.database.windows.net",
    [string]$Database = "VATSIM_ADL",
    [string]$Username,
    [string]$Password,
    [switch]$SkipFixes,
    [switch]$SkipNavaids,
    [switch]$SkipAirways
)

$ErrorActionPreference = "Stop"

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Azure SQL NavData Importer (Fast)" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan

# Prompt for credentials if not provided
if (-not $Username) { $Username = Read-Host "SQL Username" }
if (-not $Password) {
    $secPwd = Read-Host "SQL Password" -AsSecureString
    $Password = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secPwd))
}

$connStr = "Server=tcp:$Server,1433;Database=$Database;User ID=$Username;Password=$Password;Encrypt=True;TrustServerCertificate=False;Connection Timeout=30;"

try {
    $conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
    $conn.Open()
    Write-Host "Connected to Azure SQL" -ForegroundColor Green
} catch {
    Write-Host "Failed to connect: $_" -ForegroundColor Red
    exit 1
}

$totalStart = Get-Date

# ============================================================================
# Clear existing XPLANE data before import
# ============================================================================
Write-Host ""
Write-Host "Clearing existing XPLANE data..." -ForegroundColor Yellow

$cmd = $conn.CreateCommand()
$cmd.CommandTimeout = 300

# Delete ALL airway segments first (foreign key constraint)
$cmd.CommandText = "DELETE FROM dbo.airway_segments"
$deleted = $cmd.ExecuteNonQuery()
Write-Host "  Deleted $deleted airway segments" -ForegroundColor Gray

# Delete ALL airways (Navigraph data replaces everything)
$cmd.CommandText = "DELETE FROM dbo.airways"
$deleted = $cmd.ExecuteNonQuery()
Write-Host "  Deleted $deleted airways" -ForegroundColor Gray

# Verify airways are gone
$cmd.CommandText = "SELECT COUNT(*) FROM dbo.airways"
$remaining = $cmd.ExecuteScalar()
if ($remaining -gt 0) {
    Write-Host "  WARNING: Still have $remaining airways in database!" -ForegroundColor Red
    Write-Host "  Attempting TRUNCATE..." -ForegroundColor Yellow
    try {
        $cmd.CommandText = "TRUNCATE TABLE dbo.airways"
        $cmd.ExecuteNonQuery() | Out-Null
        Write-Host "  TRUNCATE successful" -ForegroundColor Green
    } catch {
        Write-Host "  TRUNCATE failed (FK constraint?): $_" -ForegroundColor Red
    }
}

# Delete XPLANE fixes/navaids (keep FAA/other sources for now)
$cmd.CommandText = "DELETE FROM dbo.nav_fixes WHERE source = 'XPLANE'"
$deleted = $cmd.ExecuteNonQuery()
Write-Host "  Deleted $deleted fixes/navaids" -ForegroundColor Gray

Write-Host "  Cleared existing data" -ForegroundColor Green

# ============================================================================
# Import Fixes using SqlBulkCopy
# ============================================================================
if (-not $SkipFixes) {
    $fixCsv = Join-Path $CsvPath "xplane_fixes.csv"
    if (Test-Path $fixCsv) {
        Write-Host ""
        Write-Host "Importing fixes..." -ForegroundColor Yellow
        $start = Get-Date

        # Create DataTable
        $dt = New-Object System.Data.DataTable
        [void]$dt.Columns.Add("fix_name", [string])
        [void]$dt.Columns.Add("fix_type", [string])
        [void]$dt.Columns.Add("lat", [decimal])
        [void]$dt.Columns.Add("lon", [decimal])
        [void]$dt.Columns.Add("country_code", [string])
        [void]$dt.Columns.Add("source", [string])

        # Use Import-Csv to handle quoted fields properly
        $fixes = Import-Csv $fixCsv
        $count = 0
        foreach ($fix in $fixes) {
            $row = $dt.NewRow()
            $row["fix_name"] = $fix.fix_name
            $row["fix_type"] = $fix.fix_type
            $row["lat"] = [decimal]$fix.lat
            $row["lon"] = [decimal]$fix.lon
            $row["country_code"] = $fix.country_code
            $row["source"] = "XPLANE"
            $dt.Rows.Add($row)
            $count++

            if ($count % 50000 -eq 0) {
                Write-Host "  Loaded $count fixes..." -ForegroundColor Gray
            }
        }

        Write-Host "  Loaded $count fixes, bulk inserting..." -ForegroundColor Gray

        # Bulk copy
        $bulk = New-Object System.Data.SqlClient.SqlBulkCopy($conn)
        $bulk.DestinationTableName = "nav_fixes"
        $bulk.BatchSize = 10000
        $bulk.BulkCopyTimeout = 600

        $bulk.ColumnMappings.Add("fix_name", "fix_name") | Out-Null
        $bulk.ColumnMappings.Add("fix_type", "fix_type") | Out-Null
        $bulk.ColumnMappings.Add("lat", "lat") | Out-Null
        $bulk.ColumnMappings.Add("lon", "lon") | Out-Null
        $bulk.ColumnMappings.Add("country_code", "country_code") | Out-Null
        $bulk.ColumnMappings.Add("source", "source") | Out-Null

        $bulk.WriteToServer($dt)
        $bulk.Close()

        $elapsed = ((Get-Date) - $start).TotalSeconds
        Write-Host "  Inserted $count fixes in $([math]::Round($elapsed, 1))s" -ForegroundColor Green
        $dt.Dispose()
    }
}

# ============================================================================
# Import Navaids using SqlBulkCopy
# ============================================================================
if (-not $SkipNavaids) {
    $navCsv = Join-Path $CsvPath "xplane_navaids.csv"
    if (Test-Path $navCsv) {
        Write-Host ""
        Write-Host "Importing navaids..." -ForegroundColor Yellow
        $start = Get-Date

        $dt = New-Object System.Data.DataTable
        [void]$dt.Columns.Add("fix_name", [string])
        [void]$dt.Columns.Add("fix_type", [string])
        [void]$dt.Columns.Add("lat", [decimal])
        [void]$dt.Columns.Add("lon", [decimal])
        [void]$dt.Columns.Add("elevation_ft", [object])
        [void]$dt.Columns.Add("freq_mhz", [object])
        [void]$dt.Columns.Add("mag_var", [object])
        [void]$dt.Columns.Add("source", [string])

        # Use Import-Csv to handle quoted fields properly
        $navaids = Import-Csv $navCsv
        $count = 0
        foreach ($nav in $navaids) {
            $row = $dt.NewRow()
            $row["fix_name"] = $nav.fix_name
            $row["fix_type"] = $nav.fix_type
            $row["lat"] = [decimal]$nav.lat
            $row["lon"] = [decimal]$nav.lon
            $row["elevation_ft"] = if ($nav.elevation_ft) { [int]$nav.elevation_ft } else { [DBNull]::Value }
            $row["freq_mhz"] = if ($nav.freq_mhz) { [decimal]$nav.freq_mhz } else { [DBNull]::Value }
            $row["mag_var"] = if ($nav.mag_var) { [decimal]$nav.mag_var } else { [DBNull]::Value }
            $row["source"] = "XPLANE"
            $dt.Rows.Add($row)
            $count++
        }

        Write-Host "  Loaded $count navaids, bulk inserting..." -ForegroundColor Gray

        $bulk = New-Object System.Data.SqlClient.SqlBulkCopy($conn)
        $bulk.DestinationTableName = "nav_fixes"
        $bulk.BatchSize = 10000
        $bulk.BulkCopyTimeout = 600

        $bulk.ColumnMappings.Add("fix_name", "fix_name") | Out-Null
        $bulk.ColumnMappings.Add("fix_type", "fix_type") | Out-Null
        $bulk.ColumnMappings.Add("lat", "lat") | Out-Null
        $bulk.ColumnMappings.Add("lon", "lon") | Out-Null
        $bulk.ColumnMappings.Add("elevation_ft", "elevation_ft") | Out-Null
        $bulk.ColumnMappings.Add("freq_mhz", "freq_mhz") | Out-Null
        $bulk.ColumnMappings.Add("mag_var", "mag_var") | Out-Null
        $bulk.ColumnMappings.Add("source", "source") | Out-Null

        $bulk.WriteToServer($dt)
        $bulk.Close()

        $elapsed = ((Get-Date) - $start).TotalSeconds
        Write-Host "  Inserted $count navaids in $([math]::Round($elapsed, 1))s" -ForegroundColor Green
        $dt.Dispose()
    }
}

# ============================================================================
# Import Airways using SqlBulkCopy
# ============================================================================
if (-not $SkipAirways) {
    $awyCsv = Join-Path $CsvPath "xplane_airways.csv"
    if (Test-Path $awyCsv) {
        Write-Host ""
        Write-Host "Importing airways..." -ForegroundColor Yellow
        $start = Get-Date

        $dt = New-Object System.Data.DataTable
        [void]$dt.Columns.Add("airway_name", [string])
        [void]$dt.Columns.Add("airway_type", [string])
        [void]$dt.Columns.Add("fix_sequence", [string])
        [void]$dt.Columns.Add("fix_count", [int])
        [void]$dt.Columns.Add("start_fix", [string])
        [void]$dt.Columns.Add("end_fix", [string])
        [void]$dt.Columns.Add("min_alt_ft", [int])
        [void]$dt.Columns.Add("max_alt_ft", [int])
        [void]$dt.Columns.Add("source", [string])

        # Use Import-Csv for airways since fix_sequence can have commas
        $airways = Import-Csv $awyCsv
        $skipped = 0
        foreach ($awy in $airways) {
            # Skip rows with empty required fields
            if ([string]::IsNullOrWhiteSpace($awy.airway_name) -or [string]::IsNullOrWhiteSpace($awy.fix_sequence)) {
                $skipped++
                continue
            }

            $row = $dt.NewRow()
            # Truncate strings to fit column sizes
            $row["airway_name"] = $awy.airway_name.Substring(0, [Math]::Min(16, $awy.airway_name.Length))
            $row["airway_type"] = if ($awy.airway_type) { $awy.airway_type.Substring(0, [Math]::Min(16, $awy.airway_type.Length)) } else { "OTHER" }
            $row["fix_sequence"] = $awy.fix_sequence
            $row["fix_count"] = if ($awy.fix_count) { [int]$awy.fix_count } else { 0 }
            $row["start_fix"] = if ($awy.start_fix) { $awy.start_fix.Substring(0, [Math]::Min(16, $awy.start_fix.Length)) } else { "" }
            $row["end_fix"] = if ($awy.end_fix) { $awy.end_fix.Substring(0, [Math]::Min(16, $awy.end_fix.Length)) } else { "" }
            $row["min_alt_ft"] = if ($awy.min_alt_ft) { [int]$awy.min_alt_ft } else { 0 }
            $row["max_alt_ft"] = if ($awy.max_alt_ft) { [int]$awy.max_alt_ft } else { 99999 }
            $row["source"] = "XPLANE"
            $dt.Rows.Add($row)
        }

        $count = $dt.Rows.Count
        if ($skipped -gt 0) {
            Write-Host "  Skipped $skipped invalid rows" -ForegroundColor Yellow
        }
        Write-Host "  Loaded $count airways, bulk inserting..." -ForegroundColor Gray

        $bulk = New-Object System.Data.SqlClient.SqlBulkCopy($conn)
        $bulk.DestinationTableName = "airways"
        $bulk.BatchSize = 5000
        $bulk.BulkCopyTimeout = 600

        $bulk.ColumnMappings.Add("airway_name", "airway_name") | Out-Null
        $bulk.ColumnMappings.Add("airway_type", "airway_type") | Out-Null
        $bulk.ColumnMappings.Add("fix_sequence", "fix_sequence") | Out-Null
        $bulk.ColumnMappings.Add("fix_count", "fix_count") | Out-Null
        $bulk.ColumnMappings.Add("start_fix", "start_fix") | Out-Null
        $bulk.ColumnMappings.Add("end_fix", "end_fix") | Out-Null
        $bulk.ColumnMappings.Add("min_alt_ft", "min_alt_ft") | Out-Null
        $bulk.ColumnMappings.Add("max_alt_ft", "max_alt_ft") | Out-Null
        $bulk.ColumnMappings.Add("source", "source") | Out-Null

        $bulk.WriteToServer($dt)
        $bulk.Close()

        $elapsed = ((Get-Date) - $start).TotalSeconds
        Write-Host "  Inserted $count airways in $([math]::Round($elapsed, 1))s" -ForegroundColor Green
        $dt.Dispose()
    }
}

$conn.Close()

$totalElapsed = ((Get-Date) - $totalStart).TotalSeconds
Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Complete in $([math]::Round($totalElapsed, 1)) seconds" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Cyan
