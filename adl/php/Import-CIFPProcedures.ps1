# ============================================================================
# Import-CIFPProcedures.ps1
# Parses X-Plane CIFP procedure files for SIDs and STARs
#
# Data source: X-Plane 12 Custom Data/CIFP/*.dat (Navigraph)
#
# Output: CSV files ready for SQL Server bulk import
#   - cifp_procedures.csv  - Unique procedures
#   - cifp_legs.csv        - Individual procedure legs
# ============================================================================

param(
    [string]$CIFPPath = "C:\X-Plane 12\Custom Data\CIFP",
    [string]$OutputPath = ".\cifp_import",
    [int]$MaxFiles = 0,          # 0 = all files
    [int]$Threads = 8,           # Parallel threads
    [switch]$Verbose
)

$ErrorActionPreference = "Stop"

# Create output directory
New-Item -ItemType Directory -Force -Path $OutputPath | Out-Null

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "CIFP Procedure Importer for ADL" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Source: $CIFPPath" -ForegroundColor White
Write-Host "Output: $OutputPath" -ForegroundColor White
Write-Host ""

# ============================================================================
# Helper: Parse altitude value (handles feet and flight levels)
# ============================================================================
function Parse-Altitude {
    param([string]$value)

    if ([string]::IsNullOrWhiteSpace($value)) { return $null }
    $value = $value.Trim()
    if ($value -eq "") { return $null }

    # Flight level format: FL220 or just numbers
    if ($value -match '^FL(\d+)$') {
        return [int]$Matches[1] * 100
    }

    # Pure numeric (feet)
    if ($value -match '^(\d+)$') {
        $feet = [int]$Matches[1]
        # Values >= 18000 might be FL, but stored as feet
        return $feet
    }

    return $null
}

# ============================================================================
# Helper: Parse course value (4-digit format = XXX.X degrees)
# ============================================================================
function Parse-Course {
    param([string]$value)

    if ([string]::IsNullOrWhiteSpace($value)) { return $null }
    $value = $value.Trim()
    if ($value -eq "" -or $value -eq "0000") { return $null }

    if ($value -match '^(\d{4})$') {
        return [decimal]$value / 10.0
    }

    return $null
}

# ============================================================================
# Helper: Parse distance value (4-digit format = XX.XX NM)
# ============================================================================
function Parse-Distance {
    param([string]$value)

    if ([string]::IsNullOrWhiteSpace($value)) { return $null }
    $value = $value.Trim()
    if ($value -eq "" -or $value -eq "0000") { return $null }

    if ($value -match '^(\d{4})$') {
        return [decimal]$value / 100.0
    }

    return $null
}

# ============================================================================
# Helper: Parse speed value
# ============================================================================
function Parse-Speed {
    param([string]$value)

    if ([string]::IsNullOrWhiteSpace($value)) { return $null }
    $value = $value.Trim()
    if ($value -eq "" -or $value -match '^\s+$') { return $null }

    if ($value -match '^-?(\d+)$') {
        $speed = [int]$Matches[1]
        if ($speed -gt 0 -and $speed -lt 1000) {
            return $speed
        }
    }

    return $null
}

# ============================================================================
# Parse CIFP files (with parallel processing)
# ============================================================================

$datFiles = Get-ChildItem -Path $CIFPPath -Filter "*.dat" -File
if ($MaxFiles -gt 0) {
    $datFiles = $datFiles | Select-Object -First $MaxFiles
}

Write-Host "Found $($datFiles.Count) CIFP files to process" -ForegroundColor Yellow
Write-Host "Using $Threads parallel threads" -ForegroundColor Yellow
Write-Host ""

# Script block to process a single file
$processFile = {
    param($datFile)

    $results = @{
        procedures = @()
        legs = @()
        sidCount = 0
        starCount = 0
    }

    $airportIcao = [System.IO.Path]::GetFileNameWithoutExtension($datFile.Name).ToUpper()
    $cifpFile = $datFile.Name
    $seenProcs = @{}

    $content = [System.IO.File]::ReadAllLines($datFile.FullName)

    foreach ($line in $content) {
        $line = $line.Trim()
        if ([string]::IsNullOrEmpty($line)) { continue }
        if (-not ($line.StartsWith("SID:") -or $line.StartsWith("STAR:"))) { continue }

        $line = $line.TrimEnd(';')
        $fields = $line -split ','
        if ($fields.Count -lt 25) { continue }

        $typeSeq = $fields[0]
        if ($typeSeq -match '^(SID|STAR):(\d{3})$') {
            $procType = $Matches[1]
            $seqNum = [int]$Matches[2]
        } else { continue }

        $dbProcType = if ($procType -eq "SID") { "DP" } else { "STAR" }
        $routeType = [int]$fields[1]
        $procName = $fields[2].Trim()
        $rwTrans = $fields[3].Trim()
        $fixName = $fields[4].Trim()
        $fixRegion = $fields[5].Trim()
        $fixSection = $fields[6].Trim()
        $legType = $fields[11].Trim()
        $recNavaid = $fields[13].Trim()
        $recNavRegion = $fields[14].Trim()

        # Course/distance parsing
        $outCourse = $null; $outDist = $null; $inCourse = $null
        $f17 = $fields[17].Trim(); $f18 = $fields[18].Trim(); $f19 = $fields[19].Trim()
        if ($f17 -match '^\d{4}$' -and $f17 -ne "0000") { $outCourse = [decimal]$f17 / 10.0 }
        if ($f18 -match '^\d{4}$' -and $f18 -ne "0000") { $outDist = [decimal]$f18 / 100.0 }
        if ($f19 -match '^\d{4}$' -and $f19 -ne "0000") { $inCourse = [decimal]$f19 / 10.0 }

        # Altitude constraints (fields 22-24)
        $altRestrict = if ($fields.Count -gt 22) { $fields[22].Trim() } else { "" }
        $alt1 = $null; $alt2 = $null
        if ($fields.Count -gt 23) {
            $a1 = $fields[23].Trim()
            if ($a1 -match '^FL(\d+)$') { $alt1 = [int]$Matches[1] * 100 }
            elseif ($a1 -match '^(\d+)$' -and $a1 -ne "") { $alt1 = [int]$Matches[1] }
        }
        if ($fields.Count -gt 24) {
            $a2 = $fields[24].Trim()
            if ($a2 -match '^FL(\d+)$') { $alt2 = [int]$Matches[1] * 100 }
            elseif ($a2 -match '^(\d+)$' -and $a2 -ne "") { $alt2 = [int]$Matches[1] }
        }

        # Speed constraints (fields 26-27)
        $speedRestrict = if ($fields.Count -gt 26) { $fields[26].Trim() } else { "" }
        $speedLimit = $null
        if ($fields.Count -gt 27) {
            $sp = $fields[27].Trim()
            if ($sp -match '^-?(\d+)$') {
                $spd = [int]$Matches[1]
                if ($spd -gt 0 -and $spd -lt 1000) { $speedLimit = $spd }
            }
        }

        $restrictFlags = $fields[8].Trim()
        $isFlyover = $restrictFlags -match 'Y'
        $isHold = $restrictFlags -match 'H'

        # Track unique procedure
        $procKey = "$airportIcao|$dbProcType|$procName|$rwTrans"
        if (-not $seenProcs.ContainsKey($procKey)) {
            $seenProcs[$procKey] = $true
            $results.procedures += [PSCustomObject]@{
                airport_icao = $airportIcao
                procedure_type = $dbProcType
                procedure_name = $procName
                runway_transition = $rwTrans
                cifp_file = $cifpFile
            }
            if ($dbProcType -eq "DP") { $results.sidCount++ } else { $results.starCount++ }
        }

        # Add leg
        $results.legs += [PSCustomObject]@{
            airport_icao = $airportIcao
            procedure_type = $dbProcType
            procedure_name = $procName
            runway_transition = $rwTrans
            sequence_num = $seqNum
            route_type = $routeType
            fix_name = if ($fixName -ne "") { $fixName } else { $null }
            fix_region = if ($fixRegion -ne "") { $fixRegion } else { $null }
            fix_section = if ($fixSection -ne "") { $fixSection } else { $null }
            leg_type = $legType
            outbound_course = $outCourse
            inbound_course = $inCourse
            distance_nm = $outDist
            rec_navaid = if ($recNavaid -ne "") { $recNavaid } else { $null }
            rec_navaid_region = if ($recNavRegion -ne "") { $recNavRegion } else { $null }
            alt_restriction = if ($altRestrict -match '^[\+\-B@JH]$') { $altRestrict } else { $null }
            altitude_1_ft = $alt1
            altitude_2_ft = $alt2
            speed_limit_kts = $speedLimit
            speed_restriction = if ($speedRestrict -match '^[\+\-@]$') { $speedRestrict } else { $null }
            is_flyover = if ($isFlyover) { 1 } else { 0 }
            is_hold_waypoint = if ($isHold) { 1 } else { 0 }
            cifp_file = $cifpFile
        }
    }

    return $results
}

# Process files in parallel using runspaces
$runspacePool = [runspacefactory]::CreateRunspacePool(1, $Threads)
$runspacePool.Open()

$jobs = @()
$completed = 0
$total = $datFiles.Count

foreach ($datFile in $datFiles) {
    $powershell = [powershell]::Create().AddScript($processFile).AddArgument($datFile)
    $powershell.RunspacePool = $runspacePool

    $jobs += @{
        PowerShell = $powershell
        Handle = $powershell.BeginInvoke()
        File = $datFile.Name
    }
}

# Collect results with progress
$procedures = @{}
$legs = [System.Collections.ArrayList]::new()
$totalSID = 0
$totalSTAR = 0

Write-Host "Processing..." -ForegroundColor Gray
$sw = [System.Diagnostics.Stopwatch]::StartNew()

foreach ($job in $jobs) {
    $result = $job.PowerShell.EndInvoke($job.Handle)
    $job.PowerShell.Dispose()

    if ($null -ne $result -and $null -ne $result.procedures) {
        foreach ($proc in $result.procedures) {
            $key = "$($proc.airport_icao)|$($proc.procedure_type)|$($proc.procedure_name)|$($proc.runway_transition)"
            if (-not $procedures.ContainsKey($key)) {
                $procedures[$key] = $proc
            }
        }
    }
    if ($null -ne $result -and $null -ne $result.legs -and $result.legs.Count -gt 0) {
        [void]$legs.AddRange($result.legs)
    }
    if ($null -ne $result) {
        $totalSID += $result.sidCount
        $totalSTAR += $result.starCount
    }

    $completed++
    if ($completed % 500 -eq 0 -or $completed -eq $total) {
        $pct = [math]::Round(($completed / $total) * 100)
        $elapsed = $sw.Elapsed.TotalSeconds
        $rate = [math]::Round($completed / $elapsed, 1)
        Write-Host "  $completed / $total ($pct%) - $rate files/sec" -ForegroundColor Gray
    }
}

$runspacePool.Close()
$runspacePool.Dispose()
$sw.Stop()

$totalLegs = $legs.Count
$fileCount = $total

Write-Host ""
Write-Host "Parsing complete!" -ForegroundColor Green
Write-Host "  Airports processed: $fileCount" -ForegroundColor White
Write-Host "  SID procedures: $totalSID" -ForegroundColor White
Write-Host "  STAR procedures: $totalSTAR" -ForegroundColor White
Write-Host "  Total legs: $totalLegs" -ForegroundColor White
Write-Host ""

# ============================================================================
# Export to CSV
# ============================================================================

Write-Host "Exporting CSV files..." -ForegroundColor Yellow

# Procedures CSV
$procCsvPath = Join-Path $OutputPath "cifp_procedures.csv"
$procedures.Values | Export-Csv -Path $procCsvPath -NoTypeInformation -Encoding UTF8
Write-Host "  Created: $procCsvPath ($($procedures.Count) records)" -ForegroundColor Green

# Legs CSV
$legsCsvPath = Join-Path $OutputPath "cifp_legs.csv"
$legs | Export-Csv -Path $legsCsvPath -NoTypeInformation -Encoding UTF8
Write-Host "  Created: $legsCsvPath ($totalLegs records)" -ForegroundColor Green

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Import complete!" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "  1. Run migration 060_cifp_procedure_legs.sql" -ForegroundColor White
Write-Host "  2. Run migration 061_cifp_import_procedure.sql" -ForegroundColor White
Write-Host "  3. Execute: EXEC sp_ImportCIFPProcedures" -ForegroundColor White
Write-Host "       @procedures_csv = '$procCsvPath'," -ForegroundColor White
Write-Host "       @legs_csv = '$legsCsvPath'" -ForegroundColor White
