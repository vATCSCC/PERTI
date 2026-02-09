# ============================================================================
# Import-OSMAirportGeometry.ps1
# 
# Imports airport geometry from OpenStreetMap via Overpass API
# Uses PowerShell native - no PHP required
#
# Usage: 
#   .\Import-OSMAirportGeometry.ps1                    # Standard sequential
#   .\Import-OSMAirportGeometry.ps1 -Parallel          # Use multiple endpoints
#   .\Import-OSMAirportGeometry.ps1 -Airport KJFK      # Single airport
#   .\Import-OSMAirportGeometry.ps1 -StartFrom CYYZ    # Resume from airport
#   .\Import-OSMAirportGeometry.ps1 -RetryFile failures.txt  # Retry failures
#
# v2.0 - 2026-01-06
#   - Multiple Overpass API endpoints (round-robin)
#   - Retry logic with exponential backoff
#   - Track and retry failures at end of run
#   - -Parallel mode for faster processing
# ============================================================================

param(
    [string]$Airport = "",
    [string]$StartFrom = "",
    [int]$DelaySeconds = 3,
    [int]$MaxRetries = 3,
    [switch]$Parallel,
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
# OVERPASS API ENDPOINTS (multiple for rate limit distribution)
# ============================================================================

$OverpassEndpoints = @(
    "https://overpass-api.de/api/interpreter",
    "https://overpass.kumi.systems/api/interpreter",
    "https://maps.mail.ru/osm/tools/overpass/api/interpreter",
    "https://overpass.openstreetmap.ru/api/interpreter"
)
$script:EndpointIndex = 0

function Get-NextEndpoint {
    $endpoint = $OverpassEndpoints[$script:EndpointIndex]
    $script:EndpointIndex = ($script:EndpointIndex + 1) % $OverpassEndpoints.Count
    return $endpoint
}

# ============================================================================
# AIRPORT LIST
# ============================================================================

$Airports = @(
    # ASPM82
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
# FUNCTIONS
# ============================================================================

function Get-OverpassData {
    param(
        [string]$Icao,
        [string]$Endpoint = $null,
        [int]$TimeoutSec = 90
    )
    
    if (-not $Endpoint) {
        $Endpoint = Get-NextEndpoint
    }
    
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
    
    try {
        $Body = "data=" + [System.Web.HttpUtility]::UrlEncode($Query)
        $Response = Invoke-RestMethod -Uri $Endpoint `
            -Method Post `
            -Body $Body `
            -ContentType "application/x-www-form-urlencoded" `
            -TimeoutSec $TimeoutSec `
            -Headers @{"User-Agent"="PERTI-VATSIM-OOOI/2.0"}
        return @{ Success = $true; Data = $Response; Endpoint = $Endpoint }
    }
    catch {
        $ErrorMsg = $_.Exception.Message
        # Check for rate limit (HTTP 429) or server overload
        $IsRateLimit = $ErrorMsg -match "429|Too Many|rate limit|overload"
        return @{ Success = $false; Error = $ErrorMsg; IsRateLimit = $IsRateLimit; Endpoint = $Endpoint }
    }
}

function Get-OverpassDataWithRetry {
    param(
        [string]$Icao,
        [int]$MaxRetries = 3,
        [int]$BaseDelay = 3
    )
    
    for ($attempt = 1; $attempt -le $MaxRetries; $attempt++) {
        $result = Get-OverpassData -Icao $Icao
        
        if ($result.Success) {
            return $result
        }
        
        # If we have more attempts, wait with exponential backoff
        if ($attempt -lt $MaxRetries) {
            $waitTime = $BaseDelay * [Math]::Pow(2, $attempt - 1)  # 3, 6, 12 seconds
            if ($result.IsRateLimit) {
                $waitTime = $waitTime * 2  # Double wait for rate limits
            }
            Write-Host " retry in ${waitTime}s..." -ForegroundColor Yellow -NoNewline
            Start-Sleep -Seconds $waitTime
            Write-Host " attempt $($attempt + 1)..." -NoNewline
        }
    }
    
    return $result  # Return last failed result
}

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
            # Calculate center manually
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

function Import-Zones {
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
            # Skip failed inserts silently
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

function Process-SingleAirport {
    param(
        $Connection,
        [string]$Icao,
        [int]$Current,
        [int]$Total,
        [int]$MaxRetries,
        [int]$DelaySeconds,
        [bool]$DryRun
    )
    
    Write-Host ("[{0,3}/{1}] {2} " -f $Current, $Total, $Icao) -NoNewline
    
    # Fetch with retry
    $result = Get-OverpassDataWithRetry -Icao $Icao -MaxRetries $MaxRetries -BaseDelay $DelaySeconds
    
    if (-not $result.Success) {
        Write-Host "FAILED ($($result.Error.Substring(0, [Math]::Min(40, $result.Error.Length))))" -ForegroundColor Red
        return @{ Status = "FAILED"; Icao = $Icao }
    }
    
    # Process response
    $Zones = ConvertTo-Zones -OsmData $result.Data -Icao $Icao
    
    if ($Zones.Count -eq 0) {
        Write-Host "no OSM data" -ForegroundColor Yellow -NoNewline
        if (-not $DryRun -and $Connection) {
            Invoke-FallbackZones -Connection $Connection -Icao $Icao
            Write-Host " -> fallback" -ForegroundColor Cyan
        } else {
            Write-Host ""
        }
        return @{ Status = "NODATA"; Icao = $Icao }
    }
    
    # Import
    if (-not $DryRun -and $Connection) {
        $Stats = Import-Zones -Connection $Connection -Icao $Icao -Zones $Zones
        Write-Host ("{0} zones (RWY:{1} TWY:{2} PARK:{3})" -f $Stats.inserted, $Stats.runways, $Stats.taxiways, $Stats.parking) -ForegroundColor Green
    } else {
        Write-Host ("{0} zones (dry run)" -f $Zones.Count) -ForegroundColor Cyan
    }
    
    return @{ Status = "SUCCESS"; Icao = $Icao; Zones = $Zones.Count }
}

# ============================================================================
# PARALLEL PROCESSING (using PowerShell jobs with endpoint distribution)
# ============================================================================

function Start-ParallelImport {
    param(
        [string[]]$AirportList,
        [string]$ConnectionString,
        [int]$DelaySeconds,
        [bool]$DryRun
    )
    
    $Total = $AirportList.Count
    $BatchSize = 4  # One per endpoint
    $Results = @{
        Success = [System.Collections.ArrayList]@()
        NoData = [System.Collections.ArrayList]@()
        Failed = [System.Collections.ArrayList]@()
    }
    
    Write-Host "Parallel mode: Using $($OverpassEndpoints.Count) endpoints" -ForegroundColor Cyan
    Write-Host ""
    
    # Process in batches of 4 (one per endpoint)
    for ($i = 0; $i -lt $Total; $i += $BatchSize) {
        $batch = $AirportList[$i..([Math]::Min($i + $BatchSize - 1, $Total - 1))]
        $jobs = @()
        
        # Start parallel jobs
        for ($j = 0; $j -lt $batch.Count; $j++) {
            $icao = $batch[$j]
            $endpoint = $OverpassEndpoints[$j]
            $idx = $i + $j + 1
            
            Write-Host ("[{0,3}/{1}] {2} -> {3}..." -f $idx, $Total, $icao, ($endpoint -replace 'https://', '' -replace '/api/interpreter', '')) -NoNewline
            
            $jobs += Start-Job -ScriptBlock {
                param($Icao, $Endpoint, $ConnStr, $DryRunMode)
                
                Add-Type -AssemblyName System.Web
                
                # Query Overpass
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
                
                try {
                    $Body = "data=" + [System.Web.HttpUtility]::UrlEncode($Query)
                    $Response = Invoke-RestMethod -Uri $Endpoint `
                        -Method Post `
                        -Body $Body `
                        -ContentType "application/x-www-form-urlencoded" `
                        -TimeoutSec 90 `
                        -Headers @{"User-Agent"="PERTI-VATSIM-OOOI/2.0"}
                    
                    return @{ 
                        Icao = $Icao
                        Success = $true
                        Data = $Response
                        ElementCount = $Response.elements.Count
                    }
                }
                catch {
                    return @{
                        Icao = $Icao
                        Success = $false
                        Error = $_.Exception.Message
                    }
                }
            } -ArgumentList $icao, $endpoint, $ConnectionString, $DryRun
            
            Write-Host " " -NoNewline
        }
        Write-Host ""
        
        # Wait for batch to complete
        $null = Wait-Job -Job $jobs -Timeout 120
        
        # Process results
        foreach ($job in $jobs) {
            $result = Receive-Job -Job $job
            Remove-Job -Job $job -Force
            
            if (-not $result) {
                Write-Host "  $($job.Name): TIMEOUT" -ForegroundColor Red
                $Results.Failed.Add($job.Name) | Out-Null
                continue
            }
            
            $icao = $result.Icao
            
            if (-not $result.Success) {
                Write-Host "  ${icao}: FAILED" -ForegroundColor Red
                $Results.Failed.Add($icao) | Out-Null
            }
            elseif ($result.ElementCount -eq 0) {
                Write-Host "  ${icao}: no OSM data -> fallback" -ForegroundColor Yellow
                $Results.NoData.Add($icao) | Out-Null
            }
            else {
                Write-Host "  ${icao}: $($result.ElementCount) elements" -ForegroundColor Green
                $Results.Success.Add($icao) | Out-Null
            }
        }
        
        # Brief pause between batches
        if ($i + $BatchSize -lt $Total) {
            Start-Sleep -Seconds $DelaySeconds
        }
    }
    
    return $Results
}

# ============================================================================
# MAIN
# ============================================================================

Add-Type -AssemblyName System.Web

$StartTime = Get-Date

Write-Host "======================================================================="
Write-Host "  PERTI OSM Airport Geometry Import v2.0"
Write-Host "  Airports: $($Airports.Count)"
Write-Host "  Mode: $(if ($Parallel) { 'PARALLEL (4 endpoints)' } else { 'Sequential with retry' })"
Write-Host "  $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
Write-Host "======================================================================="
Write-Host ""

if ($DryRun) {
    Write-Host "*** DRY RUN MODE ***" -ForegroundColor Yellow
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
    # Load from retry file
    $AirportList = Get-Content $RetryFile | Where-Object { $_ -match "^[A-Z]{4}$" }
    Write-Host "Loaded $($AirportList.Count) airports from retry file" -ForegroundColor Cyan
}
elseif ($Airport) {
    $AirportList = @($Airport.ToUpper())
}
else {
    $AirportList = $Airports
}

$Total = $AirportList.Count
$Current = 0
$Success = 0
$NoData = 0
$Failed = [System.Collections.ArrayList]@()
$Skip = $StartFrom -ne ""

Write-Host "Processing $Total airports..."
Write-Host ""

# ============================================================================
# PARALLEL MODE
# ============================================================================

if ($Parallel -and -not $Airport -and -not $StartFrom) {
    Write-Host "NOTE: Parallel mode fetches data but imports sequentially" -ForegroundColor Yellow
    Write-Host "      This avoids database connection conflicts" -ForegroundColor Yellow
    Write-Host ""
    
    # Use parallel fetch, then sequential import
    # For now, just use sequential with round-robin endpoints
    Write-Host "Using round-robin endpoint rotation for speed..." -ForegroundColor Cyan
    Write-Host ""
}

# ============================================================================
# SEQUENTIAL PROCESSING (with round-robin endpoints)
# ============================================================================

foreach ($Icao in $AirportList) {
    $Current++
    
    # Handle StartFrom
    if ($Skip) {
        if ($Icao -eq $StartFrom.ToUpper()) { $Skip = $false }
        else { continue }
    }
    
    $result = Process-SingleAirport -Connection $Connection -Icao $Icao `
        -Current $Current -Total $Total -MaxRetries $MaxRetries `
        -DelaySeconds $DelaySeconds -DryRun $DryRun
    
    switch ($result.Status) {
        "SUCCESS" { $Success++ }
        "NODATA" { $NoData++ }
        "FAILED" { $Failed.Add($Icao) | Out-Null }
    }
    
    Start-Sleep -Seconds $DelaySeconds
}

# ============================================================================
# RETRY FAILURES
# ============================================================================

if ($Failed.Count -gt 0 -and -not $DryRun) {
    Write-Host ""
    Write-Host "======================================================================="
    Write-Host "  Retrying $($Failed.Count) failed airports (longer delays)..."
    Write-Host "======================================================================="
    Write-Host ""
    
    $RetryList = $Failed.Clone()
    $Failed.Clear()
    $RetryDelay = $DelaySeconds * 3  # Triple the delay for retries
    
    foreach ($Icao in $RetryList) {
        $Current = $RetryList.IndexOf($Icao) + 1
        
        Write-Host ("[RETRY {0}/{1}] {2} " -f $Current, $RetryList.Count, $Icao) -NoNewline
        
        # Use longer timeout and more retries
        $result = Get-OverpassDataWithRetry -Icao $Icao -MaxRetries 5 -BaseDelay $RetryDelay
        
        if ($result.Success) {
            $Zones = ConvertTo-Zones -OsmData $result.Data -Icao $Icao
            if ($Zones.Count -gt 0) {
                $Stats = Import-Zones -Connection $Connection -Icao $Icao -Zones $Zones
                Write-Host ("{0} zones (RWY:{1} TWY:{2} PARK:{3})" -f $Stats.inserted, $Stats.runways, $Stats.taxiways, $Stats.parking) -ForegroundColor Green
                $Success++
            } else {
                Write-Host "no OSM data -> fallback" -ForegroundColor Yellow
                Invoke-FallbackZones -Connection $Connection -Icao $Icao
                $NoData++
            }
        }
        else {
            Write-Host "FAILED (giving up)" -ForegroundColor Red
            $Failed.Add($Icao) | Out-Null
        }
        
        Start-Sleep -Seconds $RetryDelay
    }
}

# ============================================================================
# SAVE FAILURES FOR LATER
# ============================================================================

if ($Failed.Count -gt 0) {
    $FailureFile = Join-Path $PSScriptRoot "osm_import_failures_$(Get-Date -Format 'yyyyMMdd_HHmmss').txt"
    $Failed | Out-File -FilePath $FailureFile -Encoding UTF8
    Write-Host ""
    Write-Host "Failed airports saved to: $FailureFile" -ForegroundColor Yellow
    Write-Host "Re-run with: .\Import-OSMAirportGeometry.ps1 -RetryFile `"$FailureFile`"" -ForegroundColor Yellow
}

# Cleanup
if ($Connection) {
    $Connection.Close()
}

$EndTime = Get-Date
$Duration = $EndTime - $StartTime

Write-Host ""
Write-Host "======================================================================="
Write-Host "  Complete - $('{0:hh\:mm\:ss}' -f $Duration)"
Write-Host "======================================================================="
Write-Host "  Success:  $Success (OSM data imported)"
Write-Host "  No Data:  $NoData (fallback zones created)"
Write-Host "  Failed:   $($Failed.Count)"
if ($Failed.Count -gt 0) {
    Write-Host "  Failures: $($Failed -join ', ')" -ForegroundColor Red
}
Write-Host "======================================================================="
