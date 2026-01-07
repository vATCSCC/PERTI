# ============================================================================
# Import-XPlaneNavData.ps1
# Downloads and parses X-Plane/FlightGear navigation data for ADL
#
# Data sources:
#   - X-Plane 11/12: earth_fix.dat, earth_nav.dat, earth_awy.dat
#   - Download from: https://gateway.x-plane.com/navdata/earthnav/
#
# Output: CSV files ready for SQL Server bulk import
# ============================================================================

param(
    [string]$DataPath = ".\xplane_navdata",
    [string]$OutputPath = ".\nav_import",
    [switch]$DownloadData,
    [switch]$SkipFixes,
    [switch]$SkipNavaids,
    [switch]$SkipAirways
)

$ErrorActionPreference = "Stop"

# Create output directories
New-Item -ItemType Directory -Force -Path $DataPath | Out-Null
New-Item -ItemType Directory -Force -Path $OutputPath | Out-Null

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "X-Plane Navigation Data Importer for ADL" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# ============================================================================
# Download X-Plane data if requested
# ============================================================================
if ($DownloadData) {
    Write-Host "Downloading X-Plane navigation data..." -ForegroundColor Yellow

    $urls = @{
        "earth_fix.dat" = "https://gateway.x-plane.com/navdata/earthnav/earth_fix.dat"
        "earth_nav.dat" = "https://gateway.x-plane.com/navdata/earthnav/earth_nav.dat"
        "earth_awy.dat" = "https://gateway.x-plane.com/navdata/earthnav/earth_awy.dat"
    }

    foreach ($file in $urls.Keys) {
        $outFile = Join-Path $DataPath $file
        if (Test-Path $outFile) {
            Write-Host "  $file already exists, skipping download" -ForegroundColor Gray
        } else {
            Write-Host "  Downloading $file..." -ForegroundColor White
            try {
                Invoke-WebRequest -Uri $urls[$file] -OutFile $outFile -UseBasicParsing
                Write-Host "    Downloaded successfully" -ForegroundColor Green
            } catch {
                Write-Host "    Failed to download: $_" -ForegroundColor Red
                Write-Host "    You may need to download manually from X-Plane Gateway" -ForegroundColor Yellow
            }
        }
    }
    Write-Host ""
}

# ============================================================================
# Parse earth_fix.dat - Waypoints/Fixes
# ============================================================================
if (-not $SkipFixes) {
    $fixFile = Join-Path $DataPath "earth_fix.dat"
    if (Test-Path $fixFile) {
        Write-Host "Parsing earth_fix.dat..." -ForegroundColor Yellow

        $fixes = @()
        $lineNum = 0
        $dataStarted = $false

        Get-Content $fixFile -Encoding UTF8 | ForEach-Object {
            $lineNum++
            $line = $_.Trim()

            # Skip header lines until we hit data
            if ($line -match '^\s*[\d\-]+\.\d+\s+[\d\-]+\.\d+\s+\w+') {
                $dataStarted = $true
            }

            if ($dataStarted -and $line -match '^\s*([\d\-]+\.\d+)\s+([\d\-]+\.\d+)\s+(\w+)\s*(.*)$') {
                $lat = [decimal]$Matches[1]
                $lon = [decimal]$Matches[2]
                $name = $Matches[3].ToUpper()
                $region = if ($Matches[4]) { $Matches[4].Trim().Substring(0, [Math]::Min(4, $Matches[4].Trim().Length)) } else { "" }

                # Validate coordinates
                if ([Math]::Abs($lat) -le 90 -and [Math]::Abs($lon) -le 180) {
                    $fixes += [PSCustomObject]@{
                        fix_name = $name
                        fix_type = "WAYPOINT"
                        lat = $lat
                        lon = $lon
                        country_code = $region
                        source = "XPLANE"
                    }
                }
            }

            if ($lineNum % 50000 -eq 0) {
                Write-Host "  Processed $lineNum lines, found $($fixes.Count) fixes..." -ForegroundColor Gray
            }
        }

        Write-Host "  Found $($fixes.Count) waypoints/fixes" -ForegroundColor Green

        # Export to CSV
        $fixCsv = Join-Path $OutputPath "xplane_fixes.csv"
        $fixes | Export-Csv -Path $fixCsv -NoTypeInformation -Encoding UTF8
        Write-Host "  Exported to $fixCsv" -ForegroundColor Green
        Write-Host ""
    } else {
        Write-Host "earth_fix.dat not found at $fixFile" -ForegroundColor Red
        Write-Host "Use -DownloadData to download, or place the file manually" -ForegroundColor Yellow
        Write-Host ""
    }
}

# ============================================================================
# Parse earth_nav.dat - Navaids (VOR, NDB, DME, etc.)
# ============================================================================
if (-not $SkipNavaids) {
    $navFile = Join-Path $DataPath "earth_nav.dat"
    if (Test-Path $navFile) {
        Write-Host "Parsing earth_nav.dat..." -ForegroundColor Yellow

        $navaids = @()
        $lineNum = 0

        # Navaid type codes
        $navTypes = @{
            "2" = "NDB"
            "3" = "VOR"
            "4" = "ILS_LOC"
            "5" = "ILS_LOC"
            "6" = "GS"
            "7" = "OM"
            "8" = "MM"
            "9" = "IM"
            "12" = "DME"
            "13" = "DME"
        }

        Get-Content $navFile -Encoding UTF8 | ForEach-Object {
            $lineNum++
            $line = $_.Trim()

            # Format: type lat lon elev freq range magvar id name
            # Example: 3  40.616389  -75.440833    660    11570    130     0.0   BLR  ALLENTOWN VORTAC
            if ($line -match '^(\d+)\s+([\d\-]+\.\d+)\s+([\d\-]+\.\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+([\d\.\-]+)\s+(\w+)\s+(.*)$') {
                $typeCode = $Matches[1]
                $lat = [decimal]$Matches[2]
                $lon = [decimal]$Matches[3]
                $elev = [int]$Matches[4]
                $freq = [int]$Matches[5]
                $range = [int]$Matches[6]
                $magVar = [decimal]$Matches[7]
                $id = $Matches[8].ToUpper()
                $name = $Matches[9].Trim()

                $navType = if ($navTypes.ContainsKey($typeCode)) { $navTypes[$typeCode] } else { "OTHER" }

                # Only include VOR, NDB, DME (skip ILS components)
                if ($navType -in @("VOR", "NDB", "DME")) {
                    # Convert frequency (stored as integer, needs decimal)
                    $freqMhz = if ($navType -eq "NDB") { $freq / 10.0 } else { $freq / 100.0 }

                    $navaids += [PSCustomObject]@{
                        fix_name = $id
                        fix_type = $navType
                        lat = $lat
                        lon = $lon
                        elevation_ft = $elev
                        freq_mhz = $freqMhz
                        mag_var = $magVar
                        source = "XPLANE"
                    }
                }
            }

            if ($lineNum % 10000 -eq 0) {
                Write-Host "  Processed $lineNum lines, found $($navaids.Count) navaids..." -ForegroundColor Gray
            }
        }

        Write-Host "  Found $($navaids.Count) navaids (VOR/NDB/DME)" -ForegroundColor Green

        # Export to CSV
        $navCsv = Join-Path $OutputPath "xplane_navaids.csv"
        $navaids | Export-Csv -Path $navCsv -NoTypeInformation -Encoding UTF8
        Write-Host "  Exported to $navCsv" -ForegroundColor Green
        Write-Host ""
    } else {
        Write-Host "earth_nav.dat not found at $fixFile" -ForegroundColor Red
        Write-Host ""
    }
}

# ============================================================================
# Parse earth_awy.dat - Airways
# ============================================================================
if (-not $SkipAirways) {
    $awyFile = Join-Path $DataPath "earth_awy.dat"
    if (Test-Path $awyFile) {
        Write-Host "Parsing earth_awy.dat..." -ForegroundColor Yellow

        # Build airways from segments
        $airwaySegments = @{}
        $lineNum = 0

        Get-Content $awyFile -Encoding UTF8 | ForEach-Object {
            $lineNum++
            $line = $_.Trim()

            # Format: from_fix from_lat from_lon to_fix to_lat to_lon dir_code min_alt max_alt airway_name
            # Example: KSFO 37.619 -122.375 KSFO 37.619 -122.375 2 180 18000 J1
            if ($line -match '^(\w+)\s+([\d\-]+\.\d+)\s+([\d\-]+\.\d+)\s+(\w+)\s+([\d\-]+\.\d+)\s+([\d\-]+\.\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\w+)') {
                $fromFix = $Matches[1].ToUpper()
                $fromLat = [decimal]$Matches[2]
                $fromLon = [decimal]$Matches[3]
                $toFix = $Matches[4].ToUpper()
                $toLat = [decimal]$Matches[5]
                $toLon = [decimal]$Matches[6]
                $dirCode = [int]$Matches[7]  # 1=forward, 2=both
                $minAlt = [int]$Matches[8]
                $maxAlt = [int]$Matches[9]
                $airwayName = $Matches[10].ToUpper()

                # Add segment to airway
                if (-not $airwaySegments.ContainsKey($airwayName)) {
                    $airwaySegments[$airwayName] = @{
                        Segments = @()
                        Fixes = [System.Collections.Generic.HashSet[string]]::new()
                        MinAlt = $minAlt
                        MaxAlt = $maxAlt
                    }
                }

                $airwaySegments[$airwayName].Segments += [PSCustomObject]@{
                    from_fix = $fromFix
                    from_lat = $fromLat
                    from_lon = $fromLon
                    to_fix = $toFix
                    to_lat = $toLat
                    to_lon = $toLon
                    min_alt_ft = $minAlt
                    max_alt_ft = $maxAlt
                }

                [void]$airwaySegments[$airwayName].Fixes.Add($fromFix)
                [void]$airwaySegments[$airwayName].Fixes.Add($toFix)

                # Track overall altitude limits
                if ($minAlt -lt $airwaySegments[$airwayName].MinAlt) {
                    $airwaySegments[$airwayName].MinAlt = $minAlt
                }
                if ($maxAlt -gt $airwaySegments[$airwayName].MaxAlt) {
                    $airwaySegments[$airwayName].MaxAlt = $maxAlt
                }
            }

            if ($lineNum % 50000 -eq 0) {
                Write-Host "  Processed $lineNum lines, found $($airwaySegments.Count) airways..." -ForegroundColor Gray
            }
        }

        Write-Host "  Found $($airwaySegments.Count) airways" -ForegroundColor Green

        # Build ordered fix sequences for each airway
        Write-Host "  Building fix sequences..." -ForegroundColor Yellow
        $airways = @()

        foreach ($name in $airwaySegments.Keys) {
            $awy = $airwaySegments[$name]
            $segments = $awy.Segments

            # Build adjacency map
            $adjacency = @{}
            foreach ($seg in $segments) {
                if (-not $adjacency.ContainsKey($seg.from_fix)) {
                    $adjacency[$seg.from_fix] = @()
                }
                $adjacency[$seg.from_fix] += $seg.to_fix

                # Add reverse for bidirectional
                if (-not $adjacency.ContainsKey($seg.to_fix)) {
                    $adjacency[$seg.to_fix] = @()
                }
                $adjacency[$seg.to_fix] += $seg.from_fix
            }

            # Find fix sequence (simple approach: find endpoints and traverse)
            $endpoints = $adjacency.Keys | Where-Object { $adjacency[$_].Count -eq 1 }

            if ($endpoints.Count -ge 1) {
                $startFix = $endpoints[0]
                $visited = [System.Collections.Generic.HashSet[string]]::new()
                $sequence = @()
                $current = $startFix

                while ($current -and -not $visited.Contains($current)) {
                    [void]$visited.Add($current)
                    $sequence += $current

                    $nextFix = $null
                    foreach ($neighbor in $adjacency[$current]) {
                        if (-not $visited.Contains($neighbor)) {
                            $nextFix = $neighbor
                            break
                        }
                    }
                    $current = $nextFix
                }

                $fixSequence = $sequence -join " "
            } else {
                # Circular or complex - just list all fixes
                $fixSequence = ($awy.Fixes | Sort-Object) -join " "
            }

            # Determine airway type
            $airwayType = switch -Regex ($name) {
                '^J\d+' { "JET" }
                '^V\d+' { "VICTOR" }
                '^Q\d+' { "RNAV_HIGH" }
                '^T\d+' { "RNAV_LOW" }
                '^A\d+' { "OCEANIC" }
                '^[LMNBGRWYZ]\d+' { "EURO" }
                '^U[LMNPRTWYZ]\d+' { "EURO_HIGH" }
                default { "OTHER" }
            }

            $airways += [PSCustomObject]@{
                airway_name = $name
                airway_type = $airwayType
                fix_sequence = $fixSequence
                fix_count = $awy.Fixes.Count
                start_fix = if ($sequence) { $sequence[0] } else { "" }
                end_fix = if ($sequence) { $sequence[-1] } else { "" }
                min_alt_ft = $awy.MinAlt
                max_alt_ft = $awy.MaxAlt
                source = "XPLANE"
            }
        }

        # Export airways to CSV
        $awyCsv = Join-Path $OutputPath "xplane_airways.csv"
        $airways | Export-Csv -Path $awyCsv -NoTypeInformation -Encoding UTF8
        Write-Host "  Exported $($airways.Count) airways to $awyCsv" -ForegroundColor Green

        # Export segments to CSV
        $allSegments = @()
        foreach ($name in $airwaySegments.Keys) {
            foreach ($seg in $airwaySegments[$name].Segments) {
                $allSegments += [PSCustomObject]@{
                    airway_name = $name
                    from_fix = $seg.from_fix
                    from_lat = $seg.from_lat
                    from_lon = $seg.from_lon
                    to_fix = $seg.to_fix
                    to_lat = $seg.to_lat
                    to_lon = $seg.to_lon
                    min_alt_ft = $seg.min_alt_ft
                    max_alt_ft = $seg.max_alt_ft
                }
            }
        }

        $segCsv = Join-Path $OutputPath "xplane_airway_segments.csv"
        $allSegments | Export-Csv -Path $segCsv -NoTypeInformation -Encoding UTF8
        Write-Host "  Exported $($allSegments.Count) segments to $segCsv" -ForegroundColor Green
        Write-Host ""
    } else {
        Write-Host "earth_awy.dat not found at $awyFile" -ForegroundColor Red
        Write-Host ""
    }
}

# ============================================================================
# Summary
# ============================================================================
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Import complete!" -ForegroundColor Green
Write-Host ""
Write-Host "Output files in: $OutputPath" -ForegroundColor White
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "  1. Review the CSV files" -ForegroundColor White
Write-Host "  2. Run the SQL import script:" -ForegroundColor White
Write-Host "     EXEC sp_ImportXPlaneNavData @CsvPath = '$OutputPath'" -ForegroundColor Gray
Write-Host "============================================" -ForegroundColor Cyan
