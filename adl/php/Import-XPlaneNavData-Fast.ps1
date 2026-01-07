# ============================================================================
# Import-XPlaneNavData-Fast.ps1
# Optimized version using .NET I/O for 5-10x faster parsing
# ============================================================================

param(
    [string]$DataPath = ".\xplane_navdata",
    [string]$OutputPath = ".\nav_import",
    [switch]$SkipFixes,
    [switch]$SkipNavaids,
    [switch]$SkipAirways
)

$ErrorActionPreference = "Stop"

# Create output directory
New-Item -ItemType Directory -Force -Path $OutputPath | Out-Null

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "X-Plane NavData Importer (Fast)" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

$totalStart = Get-Date

# ============================================================================
# Parse earth_fix.dat - Waypoints/Fixes (FAST)
# ============================================================================
if (-not $SkipFixes) {
    $fixFile = Join-Path $DataPath "earth_fix.dat"
    if (Test-Path $fixFile) {
        Write-Host "Parsing earth_fix.dat..." -ForegroundColor Yellow
        $start = Get-Date

        $fixCsv = Join-Path $OutputPath "xplane_fixes.csv"
        $writer = [System.IO.StreamWriter]::new($fixCsv, $false, [System.Text.Encoding]::UTF8)
        $writer.WriteLine('"fix_name","fix_type","lat","lon","country_code","source"')

        $reader = [System.IO.StreamReader]::new($fixFile, [System.Text.Encoding]::UTF8)
        $lineNum = 0
        $fixCount = 0
        $dataStarted = $false

        while ($null -ne ($line = $reader.ReadLine())) {
            $lineNum++
            $line = $line.Trim()

            # Skip header until we hit coordinate data
            if (-not $dataStarted -and $line -match '^\s*[\d\-]+\.\d+\s+[\d\-]+\.\d+\s+\w+') {
                $dataStarted = $true
            }

            if ($dataStarted -and $line -match '^\s*([\d\-]+\.\d+)\s+([\d\-]+\.\d+)\s+(\w+)\s*(.*)$') {
                $lat = $Matches[1]
                $lon = $Matches[2]
                $name = $Matches[3].ToUpper()
                $region = if ($Matches[4]) { $Matches[4].Trim().Substring(0, [Math]::Min(4, $Matches[4].Trim().Length)) } else { "" }

                # Validate coordinates
                $latNum = [decimal]$lat
                $lonNum = [decimal]$lon
                if ([Math]::Abs($latNum) -le 90 -and [Math]::Abs($lonNum) -le 180) {
                    $writer.WriteLine("`"$name`",`"WAYPOINT`",$lat,$lon,`"$region`",`"XPLANE`"")
                    $fixCount++
                }
            }

            if ($lineNum % 100000 -eq 0) {
                Write-Host "  Processed $lineNum lines, found $fixCount fixes..." -ForegroundColor Gray
            }
        }

        $reader.Close()
        $writer.Close()

        $elapsed = ((Get-Date) - $start).TotalSeconds
        Write-Host "  Found $fixCount fixes in $([math]::Round($elapsed, 1))s" -ForegroundColor Green
        Write-Host ""
    } else {
        Write-Host "earth_fix.dat not found at $fixFile" -ForegroundColor Red
    }
}

# ============================================================================
# Parse earth_nav.dat - Navaids (FAST)
# ============================================================================
if (-not $SkipNavaids) {
    $navFile = Join-Path $DataPath "earth_nav.dat"
    if (Test-Path $navFile) {
        Write-Host "Parsing earth_nav.dat..." -ForegroundColor Yellow
        $start = Get-Date

        $navCsv = Join-Path $OutputPath "xplane_navaids.csv"
        $writer = [System.IO.StreamWriter]::new($navCsv, $false, [System.Text.Encoding]::UTF8)
        $writer.WriteLine('"fix_name","fix_type","lat","lon","elevation_ft","freq_mhz","mag_var","source"')

        $navTypes = @{
            "2" = "NDB"; "3" = "VOR"; "12" = "DME"; "13" = "DME"
        }

        $reader = [System.IO.StreamReader]::new($navFile, [System.Text.Encoding]::UTF8)
        $lineNum = 0
        $navCount = 0

        while ($null -ne ($line = $reader.ReadLine())) {
            $lineNum++
            $line = $line.Trim()

            if ($line -match '^(\d+)\s+([\d\-]+\.\d+)\s+([\d\-]+\.\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+([\d\.\-]+)\s+(\w+)\s+(.*)$') {
                $typeCode = $Matches[1]

                if ($navTypes.ContainsKey($typeCode)) {
                    $navType = $navTypes[$typeCode]
                    $lat = $Matches[2]
                    $lon = $Matches[3]
                    $elev = $Matches[4]
                    $freq = [int]$Matches[5]
                    $magVar = $Matches[7]
                    $id = $Matches[8].ToUpper()

                    # Convert frequency
                    $freqMhz = if ($navType -eq "NDB") { $freq / 10.0 } else { $freq / 100.0 }

                    $writer.WriteLine("`"$id`",`"$navType`",$lat,$lon,$elev,$freqMhz,$magVar,`"XPLANE`"")
                    $navCount++
                }
            }

            if ($lineNum % 50000 -eq 0) {
                Write-Host "  Processed $lineNum lines, found $navCount navaids..." -ForegroundColor Gray
            }
        }

        $reader.Close()
        $writer.Close()

        $elapsed = ((Get-Date) - $start).TotalSeconds
        Write-Host "  Found $navCount navaids in $([math]::Round($elapsed, 1))s" -ForegroundColor Green
        Write-Host ""
    } else {
        Write-Host "earth_nav.dat not found" -ForegroundColor Red
    }
}

# ============================================================================
# Parse earth_awy.dat - Airways (X-Plane 1100 format)
# Format: from_fix from_region from_type to_fix to_region to_type dir code min_fl max_fl airway
# ============================================================================
if (-not $SkipAirways) {
    $awyFile = Join-Path $DataPath "earth_awy.dat"
    if (Test-Path $awyFile) {
        Write-Host "Parsing earth_awy.dat (1100 format)..." -ForegroundColor Yellow
        $start = Get-Date

        # First pass: collect all segments
        $airwaySegments = @{}
        $reader = [System.IO.StreamReader]::new($awyFile, [System.Text.Encoding]::UTF8)
        $lineNum = 0

        while ($null -ne ($line = $reader.ReadLine())) {
            $lineNum++

            # Skip header lines (I, comments, version info)
            if ($line -match '^(I|99|1100)' -or $line -match '^$' -or $line.Length -lt 20) {
                continue
            }

            # X-Plane 1100 format: fixed-width columns
            # Cols 0-4: from_fix (5 chars, right-padded or left-padded with spaces)
            # Col 6-7: from_region (2 chars)
            # Col 9-10: from_type (2 chars)
            # Cols 12-16: to_fix (5 chars)
            # Col 18-19: to_region
            # Col 21-22: to_type
            # Col 24: direction (N/F)
            # Col 26: code (1/2)
            # Cols 28-30: min_fl (3 digits)
            # Cols 32-34: max_fl (3 digits)
            # Col 36+: airway name

            # Use regex to parse the space-separated format
            if ($line -match '^\s*(\S+)\s+(\w{2})\s+(\d+)\s+(\S+)\s+(\w{2})\s+(\d+)\s+([NF])\s+(\d)\s+(\d+)\s+(\d+)\s+(.+)$') {
                $fromFix = $Matches[1].Trim().ToUpper()
                $toFix = $Matches[4].Trim().ToUpper()
                $minAlt = [int]$Matches[9] * 100  # FL to feet
                $maxAlt = [int]$Matches[10] * 100
                $airwayName = $Matches[11].Trim().ToUpper()

                # Handle compound airway names (e.g., "G451-N895") - use first one
                if ($airwayName -match '^([A-Z]+\d+)') {
                    $airwayName = $Matches[1]
                }

                if (-not $airwaySegments.ContainsKey($airwayName)) {
                    $airwaySegments[$airwayName] = @{
                        Segments = [System.Collections.Generic.List[object]]::new()
                        Fixes = [System.Collections.Generic.HashSet[string]]::new()
                        MinAlt = $minAlt
                        MaxAlt = $maxAlt
                    }
                }

                # Check if this segment already exists (avoid duplicates from bidirectional entries)
                $segKey = "$fromFix-$toFix"
                $segKeyRev = "$toFix-$fromFix"
                $exists = $false
                foreach ($seg in $airwaySegments[$airwayName].Segments) {
                    if (($seg.from_fix -eq $fromFix -and $seg.to_fix -eq $toFix) -or
                        ($seg.from_fix -eq $toFix -and $seg.to_fix -eq $fromFix)) {
                        $exists = $true
                        break
                    }
                }

                if (-not $exists) {
                    $airwaySegments[$airwayName].Segments.Add(@{
                        from_fix = $fromFix
                        to_fix = $toFix
                        min_alt = $minAlt
                        max_alt = $maxAlt
                    })
                }

                [void]$airwaySegments[$airwayName].Fixes.Add($fromFix)
                [void]$airwaySegments[$airwayName].Fixes.Add($toFix)

                if ($minAlt -lt $airwaySegments[$airwayName].MinAlt) { $airwaySegments[$airwayName].MinAlt = $minAlt }
                if ($maxAlt -gt $airwaySegments[$airwayName].MaxAlt) { $airwaySegments[$airwayName].MaxAlt = $maxAlt }
            }

            if ($lineNum % 100000 -eq 0) {
                Write-Host "  Processed $lineNum lines, found $($airwaySegments.Count) airways..." -ForegroundColor Gray
            }
        }
        $reader.Close()

        Write-Host "  Found $($airwaySegments.Count) airways, building sequences..." -ForegroundColor Yellow

        # Write airways CSV
        $awyCsv = Join-Path $OutputPath "xplane_airways.csv"
        $awyWriter = [System.IO.StreamWriter]::new($awyCsv, $false, [System.Text.Encoding]::UTF8)
        $awyWriter.WriteLine('"airway_name","airway_type","fix_sequence","fix_count","start_fix","end_fix","min_alt_ft","max_alt_ft","source"')

        # Write segments CSV (no coordinates - will be joined from nav_fixes in SQL)
        $segCsv = Join-Path $OutputPath "xplane_airway_segments.csv"
        $segWriter = [System.IO.StreamWriter]::new($segCsv, $false, [System.Text.Encoding]::UTF8)
        $segWriter.WriteLine('"airway_name","from_fix","to_fix","min_alt_ft","max_alt_ft"')

        $awyCount = 0
        $segCount = 0

        foreach ($name in $airwaySegments.Keys) {
            $awy = $airwaySegments[$name]

            # Build adjacency for fix sequence
            $adjacency = @{}
            foreach ($seg in $awy.Segments) {
                if (-not $adjacency.ContainsKey($seg.from_fix)) { $adjacency[$seg.from_fix] = @() }
                $adjacency[$seg.from_fix] += $seg.to_fix
                if (-not $adjacency.ContainsKey($seg.to_fix)) { $adjacency[$seg.to_fix] = @() }
                $adjacency[$seg.to_fix] += $seg.from_fix

                # Write segment (no lat/lon - will join in SQL)
                $segWriter.WriteLine("`"$name`",`"$($seg.from_fix)`",`"$($seg.to_fix)`",$($seg.min_alt),$($seg.max_alt)")
                $segCount++
            }

            # Find endpoints and build sequence
            $endpoints = @($adjacency.Keys | Where-Object { $adjacency[$_].Count -eq 1 })
            $sequence = @()

            if ($endpoints.Count -ge 1) {
                $visited = [System.Collections.Generic.HashSet[string]]::new()
                $current = $endpoints[0]

                while ($current -and -not $visited.Contains($current)) {
                    [void]$visited.Add($current)
                    $sequence += $current
                    $nextFix = $null
                    foreach ($neighbor in $adjacency[$current]) {
                        if (-not $visited.Contains($neighbor)) { $nextFix = $neighbor; break }
                    }
                    $current = $nextFix
                }
            }

            $fixSequence = if ($sequence.Count -gt 0) { $sequence -join " " } else { ($awy.Fixes | Sort-Object) -join " " }
            $startFix = if ($sequence.Count -gt 0) { $sequence[0] } else { "" }
            $endFix = if ($sequence.Count -gt 0) { $sequence[-1] } else { "" }

            # Determine type
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

            $awyWriter.WriteLine("`"$name`",`"$airwayType`",`"$fixSequence`",$($awy.Fixes.Count),`"$startFix`",`"$endFix`",$($awy.MinAlt),$($awy.MaxAlt),`"XPLANE`"")
            $awyCount++
        }

        $awyWriter.Close()
        $segWriter.Close()

        $elapsed = ((Get-Date) - $start).TotalSeconds
        Write-Host "  Exported $awyCount airways, $segCount segments in $([math]::Round($elapsed, 1))s" -ForegroundColor Green
        Write-Host ""
    } else {
        Write-Host "earth_awy.dat not found" -ForegroundColor Red
    }
}

# ============================================================================
# Summary
# ============================================================================
$totalElapsed = ((Get-Date) - $totalStart).TotalSeconds
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Complete in $([math]::Round($totalElapsed, 1)) seconds" -ForegroundColor Green
Write-Host "Output: $OutputPath" -ForegroundColor White
Write-Host "============================================" -ForegroundColor Cyan
