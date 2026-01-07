# ============================================================================
# Import-NavDataToAzure.ps1
# Imports parsed nav data CSVs directly into Azure SQL Database
# ============================================================================

param(
    [string]$CsvPath = ".\nav_import",
    [string]$Server = "perti.database.windows.net",
    [string]$Database = "perti",
    [string]$Username,
    [string]$Password,
    [int]$BatchSize = 1000,
    [switch]$SkipFixes,
    [switch]$SkipNavaids,
    [switch]$SkipAirways
)

$ErrorActionPreference = "Stop"

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Azure SQL NavData Importer" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan

# Prompt for credentials if not provided
if (-not $Username) { $Username = Read-Host "SQL Username" }
if (-not $Password) { $Password = Read-Host "SQL Password" -AsSecureString | ConvertFrom-SecureString -AsPlainText }

$connStr = "Server=tcp:$Server,1433;Database=$Database;User ID=$Username;Password=$Password;Encrypt=True;TrustServerCertificate=False;Connection Timeout=30;"

try {
    $conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
    $conn.Open()
    Write-Host "Connected to Azure SQL" -ForegroundColor Green
} catch {
    Write-Host "Failed to connect: $_" -ForegroundColor Red
    exit 1
}

function Invoke-SqlNonQuery {
    param([string]$sql)
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = $sql
    $cmd.CommandTimeout = 300
    $cmd.ExecuteNonQuery() | Out-Null
}

function Invoke-SqlBatch {
    param([string]$tableName, [array]$columns, [array]$rows)

    if ($rows.Count -eq 0) { return }

    $colList = $columns -join ", "
    $values = @()

    foreach ($row in $rows) {
        $vals = @()
        foreach ($col in $columns) {
            $v = $row.$col
            if ($null -eq $v -or $v -eq "") {
                $vals += "NULL"
            } elseif ($v -is [string]) {
                $vals += "N'" + ($v -replace "'", "''") + "'"
            } else {
                $vals += $v.ToString()
            }
        }
        $values += "(" + ($vals -join ", ") + ")"
    }

    $sql = "INSERT INTO $tableName ($colList) VALUES " + ($values -join ", ")
    Invoke-SqlNonQuery $sql
}

$totalStart = Get-Date

# ============================================================================
# Import Fixes
# ============================================================================
if (-not $SkipFixes) {
    $fixCsv = Join-Path $CsvPath "xplane_fixes.csv"
    if (Test-Path $fixCsv) {
        Write-Host ""
        Write-Host "Importing fixes..." -ForegroundColor Yellow
        $start = Get-Date

        $fixes = Import-Csv $fixCsv
        $total = $fixes.Count
        Write-Host "  Loaded $total fixes from CSV" -ForegroundColor Gray

        $inserted = 0
        $batch = @()

        foreach ($fix in $fixes) {
            $batch += [PSCustomObject]@{
                fix_name = $fix.fix_name
                fix_type = $fix.fix_type
                lat = $fix.lat
                lon = $fix.lon
                country_code = $fix.country_code
                source = "XPLANE"
            }

            if ($batch.Count -ge $BatchSize) {
                Invoke-SqlBatch "nav_fixes" @("fix_name", "fix_type", "lat", "lon", "country_code", "source") $batch
                $inserted += $batch.Count
                $batch = @()
                if ($inserted % 10000 -eq 0) {
                    Write-Host "    Inserted $inserted / $total..." -ForegroundColor Gray
                }
            }
        }

        if ($batch.Count -gt 0) {
            Invoke-SqlBatch "nav_fixes" @("fix_name", "fix_type", "lat", "lon", "country_code", "source") $batch
            $inserted += $batch.Count
        }

        $elapsed = ((Get-Date) - $start).TotalSeconds
        Write-Host "  Inserted $inserted fixes in $([math]::Round($elapsed, 1))s" -ForegroundColor Green
    }
}

# ============================================================================
# Import Navaids
# ============================================================================
if (-not $SkipNavaids) {
    $navCsv = Join-Path $CsvPath "xplane_navaids.csv"
    if (Test-Path $navCsv) {
        Write-Host ""
        Write-Host "Importing navaids..." -ForegroundColor Yellow
        $start = Get-Date

        $navaids = Import-Csv $navCsv
        $total = $navaids.Count
        Write-Host "  Loaded $total navaids from CSV" -ForegroundColor Gray

        $inserted = 0
        $batch = @()

        foreach ($nav in $navaids) {
            $batch += [PSCustomObject]@{
                fix_name = $nav.fix_name
                fix_type = $nav.fix_type
                lat = $nav.lat
                lon = $nav.lon
                elevation_ft = if ($nav.elevation_ft) { $nav.elevation_ft } else { $null }
                freq_mhz = if ($nav.freq_mhz) { $nav.freq_mhz } else { $null }
                mag_var = if ($nav.mag_var) { $nav.mag_var } else { $null }
                source = "XPLANE"
            }

            if ($batch.Count -ge $BatchSize) {
                Invoke-SqlBatch "nav_fixes" @("fix_name", "fix_type", "lat", "lon", "elevation_ft", "freq_mhz", "mag_var", "source") $batch
                $inserted += $batch.Count
                $batch = @()
            }
        }

        if ($batch.Count -gt 0) {
            Invoke-SqlBatch "nav_fixes" @("fix_name", "fix_type", "lat", "lon", "elevation_ft", "freq_mhz", "mag_var", "source") $batch
            $inserted += $batch.Count
        }

        $elapsed = ((Get-Date) - $start).TotalSeconds
        Write-Host "  Inserted $inserted navaids in $([math]::Round($elapsed, 1))s" -ForegroundColor Green
    }
}

# ============================================================================
# Import Airways
# ============================================================================
if (-not $SkipAirways) {
    $awyCsv = Join-Path $CsvPath "xplane_airways.csv"
    if (Test-Path $awyCsv) {
        Write-Host ""
        Write-Host "Importing airways..." -ForegroundColor Yellow
        $start = Get-Date

        $airways = Import-Csv $awyCsv
        $total = $airways.Count
        Write-Host "  Loaded $total airways from CSV" -ForegroundColor Gray

        $inserted = 0
        $batch = @()

        foreach ($awy in $airways) {
            $batch += [PSCustomObject]@{
                airway_name = $awy.airway_name
                airway_type = $awy.airway_type
                fix_sequence = $awy.fix_sequence
                fix_count = $awy.fix_count
                start_fix = $awy.start_fix
                end_fix = $awy.end_fix
                min_alt_ft = $awy.min_alt_ft
                max_alt_ft = $awy.max_alt_ft
                source = "XPLANE"
            }

            if ($batch.Count -ge $BatchSize) {
                Invoke-SqlBatch "airways" @("airway_name", "airway_type", "fix_sequence", "fix_count", "start_fix", "end_fix", "min_alt_ft", "max_alt_ft", "source") $batch
                $inserted += $batch.Count
                $batch = @()
            }
        }

        if ($batch.Count -gt 0) {
            Invoke-SqlBatch "airways" @("airway_name", "airway_type", "fix_sequence", "fix_count", "start_fix", "end_fix", "min_alt_ft", "max_alt_ft", "source") $batch
            $inserted += $batch.Count
        }

        $elapsed = ((Get-Date) - $start).TotalSeconds
        Write-Host "  Inserted $inserted airways in $([math]::Round($elapsed, 1))s" -ForegroundColor Green
    }
}

$conn.Close()

$totalElapsed = ((Get-Date) - $totalStart).TotalSeconds
Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Complete in $([math]::Round($totalElapsed, 1)) seconds" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Cyan
