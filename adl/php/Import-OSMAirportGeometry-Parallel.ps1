# ============================================================================
# Import-OSMAirportGeometry-Parallel.ps1
# 
# Parallel import of airport geometry from OpenStreetMap via Overpass API
# Uses PowerShell runspaces for true parallel execution across 4 endpoints
#
# Usage: 
#   .\Import-OSMAirportGeometry-Parallel.ps1                # Full parallel import
#   .\Import-OSMAirportGeometry-Parallel.ps1 -BatchSize 2   # Conservative (2 parallel)
#   .\Import-OSMAirportGeometry-Parallel.ps1 -DryRun        # Test without DB writes
#   .\Import-OSMAirportGeometry-Parallel.ps1 -RetryFile failures.txt
#
# v1.0 - 2026-01-06
#   - True parallel execution using runspaces
#   - 4 Overpass API endpoints for distributed load
#   - Fetches in parallel, imports sequentially (DB safety)
#   - Automatic retry of failures
# ============================================================================

param(
    [string]$Airport = "",
    [string]$StartFrom = "",
    [int]$BatchSize = 4,          # Number of parallel requests (max 4 = one per endpoint)
    [int]$DelayBetweenBatches = 4, # Seconds between batches
    [int]$TimeoutSeconds = 90,
    [string]$RetryFile = "",
    [switch]$DryRun
)

# ============================================================================
# CONFIGURATION - Auto-read from config.php
# ============================================================================

$SqlServer = ""
$SqlDatabase = ""
$SqlUser = ""
$SqlPassword = ""

$ConfigPath = Join-Path $PSScriptRoot "..\..\load\config.php"
if (Test-Path $ConfigPath) {
    $configContent = Get-Content $ConfigPath -Raw
    if ($configContent -match "define\s*\(\s*['""]ADL_SQL_HOST['""]\s*,\s*['""]([^'""]+)['""]\s*\)") {
        $SqlServer = $Matches[1]
    }
    if ($configContent -match "define\s*\(\s*['""]ADL_SQL_DATABASE['""]\s*,\s*['""]([^'""]+)['""]\s*\)") {
        $SqlDatabase = $Matches[1]
    }
    if ($configContent -match "define\s*\(\s*['""]ADL_SQL_USERNAME['""]\s*,\s*['""]([^'""]+)['""]\s*\)") {
        $SqlUser = $Matches[1]
    }
    if ($configContent -match "define\s*\(\s*['""]ADL_SQL_PASSWORD['""]\s*,\s*['""]([^'""]+)['""]\s*\)") {
        $SqlPassword = $Matches[1]
    }
}

if (-not $SqlServer -or -not $SqlDatabase -or -not $SqlUser -or -not $SqlPassword) {
    Write-Host "ERROR: Could not read database credentials from config.php" -ForegroundColor Red
    Write-Host "Config path: $ConfigPath"
    exit 1
}

# ============================================================================
# OVERPASS API ENDPOINTS
# ============================================================================

$script:OverpassEndpoints = @(
    "https://overpass-api.de/api/interpreter",
    "https://overpass.kumi.systems/api/interpreter",
    "https://maps.mail.ru/osm/tools/overpass/api/interpreter",
    "https://overpass.private.coffee/api/interpreter"
)

# Limit batch size to number of endpoints
if ($BatchSize -gt $script:OverpassEndpoints.Count) {
    $BatchSize = $script:OverpassEndpoints.Count
}

# ============================================================================
# AIRPORT LIST
# ============================================================================

$Airports = @(
    # ASPM77
    "KATL","KBOS","KBWI","KCLE","KCLT","KCVG","KDCA","KDEN","KDFW","KDTW",
    "KEWR","KFLL","PHNL","KHOU","KHPN","KIAD","KIAH","KISP","KJFK","KLAS",
    "KLAX","KLGA","KMCI","KMCO","KMDW","KMEM","KMIA","KMKE","KMSP","KMSY",
    "KOAK","KONT","KORD","KPBI","KPDX","KPHL","KPHX","KPIT","KPVD","KRDU",
    "KRSW","KSAN","KSAT","KSDF","KSEA","KSFO","KSJC","KSLC","KSMF","KSNA",
    "KSTL","KSWF","KTEB","KTPA","KAUS","KABQ","KBDL","KBNA","KBUF",
    "KBUR","KCHS","KCMH","KDAL","KGSO","KIND","KJAX","KMHT","KOMA","KORF",
    "KPWM","KRNO","KRIC","KSAV","KSYR","KTUL","PANC",
    # Canada
    "CYYZ","CYVR","CYUL","CYYC","CYOW","CYEG","CYWG","CYHZ","CYQB","CYYJ",
    "CYXE","CYQR","CYYT","CYTZ","CYQM","CYZF","CYXY",
    # Mexico
    "MMMX","MMUN","MMTJ","MMMY","MMGL","MMPR","MMSD","MMCZ","MMMD","MMHO",
    "MMCU","MMMZ","MMTO","MMZH","MMAA","MMVR","MMTC","MMCL","MMAS","MMBT",
    # Central America
    "MGGT","MSLP","MHTG","MNMG","MROC","MPTO","MRLB","MPHO","MZBZ",
    # Caribbean
    "TJSJ","TJBQ","TIST","TISX","MYNN","MYEF","MYGF","MUHA","MUVR","MUCU",
    "MKJP","MKJS","MDSD","MDPP","MDPC","MTPP","MWCR","MBPV","TNCM","TNCA",
    "TNCB","TNCC","TBPB","TLPL","TAPA","TKPK","TGPY","TTPP","TUPJ","TFFR",
    "TFFF","TFFJ","TFFG",
    # South America
    "SBGR","SBSP","SBRJ","SBGL","SBKP","SBBR","SBCF","SBPA","SBSV","SBRF",
    "SBFZ","SBCT","SBFL","SAEZ","SABE","SACO","SAAR","SAWH","SANC","SAME",
    "SCEL","SCFA","SCIE","SCTE","SCDA","SKBO","SKRG","SKCL","SKBQ","SKCG",
    "SKSP","SPJC","SPZO","SPQU","SEQM","SEGU","SEGS","SVMI","SVMC","SVVA",
    "SLLP","SLVR","SGAS","SUMU","SYCJ","SMJP"
)

# ============================================================================
# RUNSPACE SCRIPTBLOCK - Fetches OSM data for one airport
# ============================================================================

$FetchScriptBlock = {
    param(
        [string]$Icao,
        [string]$Endpoint,
        [int]$TimeoutSec
    )
    
    Add-Type -AssemblyName System.Web
    
    $IcaoLower = $Icao.ToLower()
    $Query = @"
[out:json][timeout:60];
(
  area["icao"="$Icao"]->.airport;
  area["icao"="$IcaoLower"]->.airport2;
);
(
  way["aeroway"="runway"](area.airport);
  way["aeroway"="runway"](area.airport2);
  way["aeroway"="taxiway"](area.airport);
  way["aeroway"="taxiway"](area.airport2);
  way["aeroway"="apron"](area.airport);
  way["aeroway"="apron"](area.airport2);
  node["aeroway"="parking_position"](area.airport);
  node["aeroway"="parking_position"](area.airport2);
  node["aeroway"="holding_position"](area.airport);
  node["aeroway"="holding_position"](area.airport2);
  node["aeroway"="gate"](area.airport);
  node["aeroway"="gate"](area.airport2);
);
out body;
>;
out skel qt;
"@
    
    $result = @{
        Icao = $Icao
        Endpoint = $Endpoint
        Success = $false
        Data = $null
        Error = $null
        ElementCount = 0
    }
    
    try {
        $Body = "data=" + [System.Web.HttpUtility]::UrlEncode($Query)
        $Response = Invoke-RestMethod -Uri $Endpoint `
            -Method Post `
            -Body $Body `
            -ContentType "application/x-www-form-urlencoded" `
            -TimeoutSec $TimeoutSec `
            -Headers @{"User-Agent"="PERTI-VATSIM-OOOI/2.0-Parallel"}
        
        $result.Success = $true
        $result.Data = $Response
        $result.ElementCount = if ($Response.elements) { $Response.elements.Count } else { 0 }
    }
    catch {
        $result.Error = $_.Exception.Message
    }
    
    return $result
}

# ============================================================================
# FUNCTIONS
# ============================================================================

function ConvertTo-Zones {
    param($OsmData, [string]$Icao)
    
    $Zones = [System.Collections.ArrayList]@()
    $Nodes = @{}
    
    if (-not $OsmData.elements) { return $Zones }
    
    # First pass: collect nodes
    foreach ($elem in $OsmData.elements) {
        if ($elem.type -eq "node" -and $null -ne $elem.lat) {
            $Nodes[[string]$elem.id] = @{ lat = [double]$elem.lat; lon = [double]$elem.lon }
        }
    }
    
    # Zone type mapping
    $TypeMap = @{
        "runway" = "RUNWAY"
        "taxiway" = "TAXIWAY"
        "taxilane" = "TAXILANE"
        "apron" = "APRON"
        "parking_position" = "PARKING"
        "gate" = "GATE"
        "holding_position" = "HOLD"
    }
    
    $BufferMap = @{
        "RUNWAY" = 45
        "TAXIWAY" = 20
        "TAXILANE" = 15
        "APRON" = 50
        "PARKING" = 25
        "GATE" = 20
        "HOLD" = 15
    }
    
    # Second pass: process elements with aeroway tags
    foreach ($elem in $OsmData.elements) {
        if (-not $elem.tags) { continue }
        $aeroway = $elem.tags.aeroway
        if (-not $aeroway -or -not $TypeMap.ContainsKey($aeroway)) { continue }
        
        $zoneType = $TypeMap[$aeroway]
        $zoneName = if ($elem.tags.ref) { $elem.tags.ref } elseif ($elem.tags.name) { $elem.tags.name } else { $null }
        
        $lat = $null
        $lon = $null
        
        if ($elem.type -eq "node" -and $null -ne $elem.lat) {
            $lat = [double]$elem.lat
            $lon = [double]$elem.lon
        }
        elseif ($elem.type -eq "way" -and $elem.nodes) {
            $sumLat = 0.0
            $sumLon = 0.0
            $count = 0
            
            foreach ($nodeId in $elem.nodes) {
                $key = [string]$nodeId
                if ($Nodes.ContainsKey($key)) {
                    $sumLat += $Nodes[$key].lat
                    $sumLon += $Nodes[$key].lon
                    $count++
                }
            }
            
            if ($count -ge 2) {
                $lat = $sumLat / $count
                $lon = $sumLon / $count
            }
        }
        
        if ($null -ne $lat -and $null -ne $lon) {
            $zone = @{
                osm_id = $elem.id
                zone_type = $zoneType
                zone_name = $zoneName
                buffer = $BufferMap[$zoneType]
                lat = $lat
                lon = $lon
            }
            $Zones.Add($zone) | Out-Null
        }
    }
    
    return $Zones
}

function Import-ZonesToDB {
    param($Connection, [string]$Icao, $Zones)
    
    $Stats = @{ inserted = 0; runways = 0; taxiways = 0; parking = 0 }
    
    # Delete existing OSM zones
    $DeleteCmd = $Connection.CreateCommand()
    $DeleteCmd.CommandText = "DELETE FROM dbo.airport_geometry WHERE airport_icao = @icao AND source = 'OSM'"
    $DeleteCmd.Parameters.AddWithValue("@icao", $Icao) | Out-Null
    $DeleteCmd.ExecuteNonQuery() | Out-Null
    
    # Insert zones
    foreach ($zone in $Zones) {
        $InsertCmd = $Connection.CreateCommand()
        $InsertCmd.CommandText = @"
INSERT INTO dbo.airport_geometry (airport_icao, zone_type, zone_name, osm_id, geometry, center_lat, center_lon, source)
VALUES (@icao, @zone_type, @zone_name, @osm_id, geography::Point(@lat, @lon, 4326).STBuffer(@buffer), @lat, @lon, 'OSM')
"@
        $InsertCmd.Parameters.AddWithValue("@icao", $Icao) | Out-Null
        $InsertCmd.Parameters.AddWithValue("@zone_type", $zone.zone_type) | Out-Null
        if ($zone.zone_name) {
            $InsertCmd.Parameters.AddWithValue("@zone_name", $zone.zone_name) | Out-Null
        } else {
            $InsertCmd.Parameters.AddWithValue("@zone_name", [DBNull]::Value) | Out-Null
        }
        $InsertCmd.Parameters.AddWithValue("@osm_id", $zone.osm_id) | Out-Null
        $InsertCmd.Parameters.AddWithValue("@lat", $zone.lat) | Out-Null
        $InsertCmd.Parameters.AddWithValue("@lon", $zone.lon) | Out-Null
        $InsertCmd.Parameters.AddWithValue("@buffer", $zone.buffer) | Out-Null
        
        try {
            $InsertCmd.ExecuteNonQuery() | Out-Null
            $Stats.inserted++
            switch ($zone.zone_type) {
                "RUNWAY" { $Stats.runways++ }
                "TAXIWAY" { $Stats.taxiways++ }
                "TAXILANE" { $Stats.taxiways++ }
                "PARKING" { $Stats.parking++ }
                "GATE" { $Stats.parking++ }
            }
        }
        catch {
            # Skip failed inserts
        }
    }
    
    # Log import
    $LogCmd = $Connection.CreateCommand()
    $LogCmd.CommandText = "INSERT INTO dbo.airport_geometry_import_log (airport_icao, source, zones_imported, runways_count, taxiways_count, parking_count, success) VALUES (@icao, 'OSM', @zones, @rwy, @twy, @park, 1)"
    $LogCmd.Parameters.AddWithValue("@icao", $Icao) | Out-Null
    $LogCmd.Parameters.AddWithValue("@zones", $Stats.inserted) | Out-Null
    $LogCmd.Parameters.AddWithValue("@rwy", $Stats.runways) | Out-Null
    $LogCmd.Parameters.AddWithValue("@twy", $Stats.taxiways) | Out-Null
    $LogCmd.Parameters.AddWithValue("@park", $Stats.parking) | Out-Null
    try { $LogCmd.ExecuteNonQuery() | Out-Null } catch {}
    
    return $Stats
}

function Invoke-FallbackZones {
    param($Connection, [string]$Icao)
    
    $Cmd = $Connection.CreateCommand()
    $Cmd.CommandText = "EXEC dbo.sp_GenerateFallbackZones @airport_icao = @icao"
    $Cmd.Parameters.AddWithValue("@icao", $Icao) | Out-Null
    try { $Cmd.ExecuteNonQuery() | Out-Null } catch {}
}

function Get-ShortEndpointName {
    param([string]$Endpoint)
    
    if ($Endpoint -match "overpass-api\.de") { return "de" }
    if ($Endpoint -match "kumi") { return "kumi" }
    if ($Endpoint -match "mail\.ru") { return "ru" }
    if ($Endpoint -match "private\.coffee") { return "coffee" }
    return "?"
}

# ============================================================================
# MAIN
# ============================================================================

Add-Type -AssemblyName System.Web

$StartTime = Get-Date

Write-Host "=======================================================================" -ForegroundColor Cyan
Write-Host "  PERTI OSM Airport Geometry Import - PARALLEL MODE" -ForegroundColor Cyan
Write-Host "  Airports: $($Airports.Count)" -ForegroundColor Cyan
Write-Host "  Parallel Requests: $BatchSize" -ForegroundColor Cyan
Write-Host "  Delay Between Batches: ${DelayBetweenBatches}s" -ForegroundColor Cyan
Write-Host "  $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Cyan
Write-Host "=======================================================================" -ForegroundColor Cyan
Write-Host ""

if ($DryRun) {
    Write-Host "*** DRY RUN MODE - No database writes ***" -ForegroundColor Yellow
    Write-Host ""
}

# Connect to database
$ConnectionString = "Server=$SqlServer;Database=$SqlDatabase;User Id=$SqlUser;Password=$SqlPassword;Encrypt=True;TrustServerCertificate=False;"

$Connection = $null
if (-not $DryRun) {
    try {
        $Connection = New-Object System.Data.SqlClient.SqlConnection($ConnectionString)
        $Connection.Open()
        Write-Host "Connected to $SqlServer/$SqlDatabase" -ForegroundColor Green
        Write-Host ""
    }
    catch {
        Write-Host "Database connection failed: $($_.Exception.Message)" -ForegroundColor Red
        exit 1
    }
}

# Build airport list
$AirportList = @()

if ($RetryFile -and (Test-Path $RetryFile)) {
    $AirportList = @(Get-Content $RetryFile | Where-Object { $_ -match "^[A-Z]{4}$" })
    Write-Host "Loaded $($AirportList.Count) airports from retry file" -ForegroundColor Cyan
    Write-Host ""
}
elseif ($Airport) {
    $AirportList = @($Airport.ToUpper())
}
else {
    $AirportList = $Airports
}

# Handle StartFrom
if ($StartFrom) {
    $startIdx = [Array]::IndexOf($AirportList, $StartFrom.ToUpper())
    if ($startIdx -ge 0) {
        $AirportList = $AirportList[$startIdx..($AirportList.Count - 1)]
        Write-Host "Starting from $StartFrom ($($AirportList.Count) airports remaining)" -ForegroundColor Cyan
        Write-Host ""
    }
}

$Total = $AirportList.Count
$Success = 0
$NoData = 0
$Failed = [System.Collections.ArrayList]@()

# Create runspace pool
$RunspacePool = [runspacefactory]::CreateRunspacePool(1, $BatchSize)
$RunspacePool.Open()

Write-Host "Processing $Total airports in batches of $BatchSize..." -ForegroundColor White
Write-Host ""

# ============================================================================
# PROCESS IN BATCHES
# ============================================================================

$BatchNum = 0
$ProcessedCount = 0

for ($i = 0; $i -lt $Total; $i += $BatchSize) {
    $BatchNum++
    $batchAirports = $AirportList[$i..([Math]::Min($i + $BatchSize - 1, $Total - 1))]
    $batchResults = @()
    $runspaces = @()
    
    # Display batch header
    $batchEnd = [Math]::Min($i + $BatchSize, $Total)
    Write-Host ("--- Batch {0}: Airports {1}-{2} of {3} ---" -f $BatchNum, ($i + 1), $batchEnd, $Total) -ForegroundColor DarkGray
    
    # Start parallel fetch for this batch
    Write-Host "  Fetching: " -NoNewline
    
    for ($j = 0; $j -lt $batchAirports.Count; $j++) {
        $icao = $batchAirports[$j]
        $endpoint = $script:OverpassEndpoints[$j % $script:OverpassEndpoints.Count]
        $shortEndpoint = Get-ShortEndpointName -Endpoint $endpoint
        
        Write-Host "$icao[$shortEndpoint] " -NoNewline -ForegroundColor White
        
        # Create and start runspace
        $powershell = [powershell]::Create().AddScript($FetchScriptBlock)
        $powershell.AddParameter("Icao", $icao) | Out-Null
        $powershell.AddParameter("Endpoint", $endpoint) | Out-Null
        $powershell.AddParameter("TimeoutSec", $TimeoutSeconds) | Out-Null
        $powershell.RunspacePool = $RunspacePool
        
        $runspaces += @{
            PowerShell = $powershell
            Handle = $powershell.BeginInvoke()
            Icao = $icao
        }
    }
    Write-Host ""
    
    # Wait for all runspaces in batch to complete
    Write-Host "  Waiting..." -NoNewline -ForegroundColor DarkGray
    
    $maxWait = $TimeoutSeconds + 30  # Extra buffer
    $waitStart = Get-Date
    
    foreach ($rs in $runspaces) {
        $remainingTime = $maxWait - ((Get-Date) - $waitStart).TotalSeconds
        if ($remainingTime -lt 1) { $remainingTime = 1 }
        
        $completed = $rs.Handle.AsyncWaitHandle.WaitOne([int]($remainingTime * 1000))
        
        if ($completed) {
            try {
                $result = $rs.PowerShell.EndInvoke($rs.Handle)
                $batchResults += $result
            }
            catch {
                $batchResults += @{
                    Icao = $rs.Icao
                    Success = $false
                    Error = "EndInvoke failed: $($_.Exception.Message)"
                }
            }
        }
        else {
            $batchResults += @{
                Icao = $rs.Icao
                Success = $false
                Error = "Timeout"
            }
        }
        
        $rs.PowerShell.Dispose()
    }
    
    Write-Host " done" -ForegroundColor DarkGray
    
    # Process results (sequential DB writes)
    Write-Host "  Results:" -ForegroundColor DarkGray
    
    foreach ($result in $batchResults) {
        $icao = $result.Icao
        $ProcessedCount++
        
        Write-Host "    $icao : " -NoNewline
        
        if (-not $result.Success) {
            $errMsg = if ($result.Error.Length -gt 50) { $result.Error.Substring(0, 50) + "..." } else { $result.Error }
            Write-Host "FAILED ($errMsg)" -ForegroundColor Red
            $Failed.Add($icao) | Out-Null
            continue
        }
        
        if ($result.ElementCount -eq 0) {
            Write-Host "no OSM data" -ForegroundColor Yellow -NoNewline
            if (-not $DryRun -and $Connection) {
                Invoke-FallbackZones -Connection $Connection -Icao $icao
                Write-Host " -> fallback" -ForegroundColor Cyan
            }
            else {
                Write-Host ""
            }
            $NoData++
            continue
        }
        
        # Convert and import
        $zones = ConvertTo-Zones -OsmData $result.Data -Icao $icao
        
        if ($zones.Count -eq 0) {
            Write-Host "no zones parsed" -ForegroundColor Yellow -NoNewline
            if (-not $DryRun -and $Connection) {
                Invoke-FallbackZones -Connection $Connection -Icao $icao
                Write-Host " -> fallback" -ForegroundColor Cyan
            }
            else {
                Write-Host ""
            }
            $NoData++
            continue
        }
        
        if (-not $DryRun -and $Connection) {
            $stats = Import-ZonesToDB -Connection $Connection -Icao $icao -Zones $zones
            Write-Host ("{0} zones (RWY:{1} TWY:{2} PARK:{3})" -f $stats.inserted, $stats.runways, $stats.taxiways, $stats.parking) -ForegroundColor Green
        }
        else {
            Write-Host "$($zones.Count) zones (dry run)" -ForegroundColor Cyan
        }
        $Success++
    }
    
    # Progress update
    $pct = [Math]::Round(($ProcessedCount / $Total) * 100, 1)
    $elapsed = (Get-Date) - $StartTime
    $rate = if ($elapsed.TotalMinutes -gt 0) { [Math]::Round($ProcessedCount / $elapsed.TotalMinutes, 1) } else { 0 }
    $remaining = if ($rate -gt 0) { [Math]::Round(($Total - $ProcessedCount) / $rate, 1) } else { 0 }
    
    Write-Host ("  Progress: {0}% ({1}/{2}) | Rate: {3}/min | ETA: {4} min" -f $pct, $ProcessedCount, $Total, $rate, $remaining) -ForegroundColor DarkCyan
    Write-Host ""
    
    # Delay between batches (except last)
    if ($i + $BatchSize -lt $Total) {
        Start-Sleep -Seconds $DelayBetweenBatches
    }
}

# Cleanup runspace pool
$RunspacePool.Close()
$RunspacePool.Dispose()

# ============================================================================
# RETRY FAILURES
# ============================================================================

if ($Failed.Count -gt 0 -and -not $DryRun) {
    Write-Host ""
    Write-Host "=======================================================================" -ForegroundColor Yellow
    Write-Host "  Retrying $($Failed.Count) failed airports (sequential, longer delays)" -ForegroundColor Yellow
    Write-Host "=======================================================================" -ForegroundColor Yellow
    Write-Host ""
    
    $RetryList = $Failed.Clone()
    $Failed.Clear()
    $retryDelay = 8  # Longer delay for retries
    
    $retryNum = 0
    foreach ($icao in $RetryList) {
        $retryNum++
        Write-Host ("[RETRY {0}/{1}] {2} " -f $retryNum, $RetryList.Count, $icao) -NoNewline
        
        # Try each endpoint sequentially
        $success = $false
        foreach ($endpoint in $script:OverpassEndpoints) {
            $shortName = Get-ShortEndpointName -Endpoint $endpoint
            Write-Host "[$shortName] " -NoNewline -ForegroundColor DarkGray
            
            # Direct invoke (not parallel)
            $result = & $FetchScriptBlock -Icao $icao -Endpoint $endpoint -TimeoutSec 120
            
            if ($result.Success -and $result.ElementCount -gt 0) {
                $zones = ConvertTo-Zones -OsmData $result.Data -Icao $icao
                if ($zones.Count -gt 0) {
                    $stats = Import-ZonesToDB -Connection $Connection -Icao $icao -Zones $zones
                    Write-Host ("{0} zones" -f $stats.inserted) -ForegroundColor Green
                    $Success++
                    $success = $true
                    break
                }
            }
            elseif ($result.Success) {
                # API succeeded but no data
                Write-Host "no data -> fallback" -ForegroundColor Yellow
                Invoke-FallbackZones -Connection $Connection -Icao $icao
                $NoData++
                $success = $true
                break
            }
            
            Start-Sleep -Seconds 2  # Brief delay between endpoint attempts
        }
        
        if (-not $success) {
            Write-Host "FAILED (all endpoints)" -ForegroundColor Red
            $Failed.Add($icao) | Out-Null
        }
        
        Start-Sleep -Seconds $retryDelay
    }
}

# ============================================================================
# SAVE FAILURES
# ============================================================================

if ($Failed.Count -gt 0) {
    $FailureFile = Join-Path $PSScriptRoot "osm_parallel_failures_$(Get-Date -Format 'yyyyMMdd_HHmmss').txt"
    $Failed | Out-File -FilePath $FailureFile -Encoding UTF8
    Write-Host ""
    Write-Host "Failed airports saved to: $FailureFile" -ForegroundColor Yellow
    Write-Host "Re-run with: .\Import-OSMAirportGeometry-Parallel.ps1 -RetryFile `"$FailureFile`"" -ForegroundColor Yellow
}

# Cleanup
if ($Connection) {
    $Connection.Close()
}

$EndTime = Get-Date
$Duration = $EndTime - $StartTime
$avgRate = if ($Duration.TotalMinutes -gt 0) { [Math]::Round($Total / $Duration.TotalMinutes, 1) } else { 0 }

Write-Host ""
Write-Host "=======================================================================" -ForegroundColor Cyan
Write-Host "  COMPLETE" -ForegroundColor Cyan
Write-Host "=======================================================================" -ForegroundColor Cyan
Write-Host "  Duration:    $('{0:hh\:mm\:ss}' -f $Duration)"
Write-Host "  Avg Rate:    $avgRate airports/min"
Write-Host "  -------------------------------------"
Write-Host "  Success:     $Success (OSM data imported)" -ForegroundColor Green
Write-Host "  No Data:     $NoData (fallback zones created)" -ForegroundColor Yellow
Write-Host "  Failed:      $($Failed.Count)" -ForegroundColor $(if ($Failed.Count -gt 0) { 'Red' } else { 'Green' })
if ($Failed.Count -gt 0) {
    Write-Host "  Failures:    $($Failed -join ', ')" -ForegroundColor Red
}
Write-Host "=======================================================================" -ForegroundColor Cyan
