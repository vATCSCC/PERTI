<#
.SYNOPSIS
    PERTI Weather Alert Import - Fetches SIGMET/AIRMET from aviationweather.gov

.DESCRIPTION
    Downloads weather hazard data from Aviation Weather Center and imports
    into VATSIM_ADL database with polygon boundaries.

.PARAMETER DryRun
    Fetch and display data without importing to database

.PARAMETER Type
    Filter by alert type: sigmet, airmet, or all (default)

.PARAMETER ShowVerbose
    Show detailed output

.EXAMPLE
    .\Import-WeatherAlerts.ps1
    # Full import

.EXAMPLE
    .\Import-WeatherAlerts.ps1 -DryRun
    # Test without importing

.NOTES
    Version: 1.4
    Date: 2026-01-06
    Compatible with PowerShell 5.1+
#>

param(
    [switch]$DryRun,
    [ValidateSet('sigmet', 'airmet', 'all')]
    [string]$Type = 'all',
    [switch]$ShowVerbose,
    [string]$SqlUser,
    [string]$SqlPass
)

# Configuration
$AWC_URL = "https://aviationweather.gov/api/data/airsigmet"
$DB_SERVER = "vatsim.database.windows.net"
$DB_NAME = "VATSIM_ADL"

# ============================================================================
# Functions (must be defined before main code)
# ============================================================================

# Helper function for null coalescing (PS 5.1 compatible)
function Coalesce {
    param([array]$values)
    foreach ($v in $values) {
        if ($null -ne $v -and $v -ne '') { return $v }
    }
    return $null
}

# Safe string conversion
function SafeString {
    param($value, $default = '')
    if ($null -eq $value) { return $default }
    return $value.ToString()
}

# Convert Unix timestamp to ISO date string
function Convert-UnixToISO {
    param($unixTime)
    
    if ($null -eq $unixTime) { return $null }
    
    try {
        # Unix timestamp is seconds since 1970-01-01
        $epoch = [DateTime]::new(1970, 1, 1, 0, 0, 0, [DateTimeKind]::Utc)
        $dt = $epoch.AddSeconds([double]$unixTime)
        return $dt.ToString('yyyy-MM-ddTHH:mm:ssZ')
    }
    catch {
        return $null
    }
}

function Convert-TimeString {
    param($timeStr)
    
    if ($null -eq $timeStr) { return $null }
    
    # Check if it's a Unix timestamp (numeric)
    if ($timeStr -is [int] -or $timeStr -is [long] -or $timeStr -is [double]) {
        return Convert-UnixToISO $timeStr
    }
    
    # Check if it's a numeric string
    if ($timeStr -match '^\d{9,}$') {
        return Convert-UnixToISO ([long]$timeStr)
    }
    
    # Try parsing as date string
    try {
        $dt = [DateTime]::Parse($timeStr.ToString())
        return $dt.ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    }
    catch {
        return $null
    }
}

function Convert-AltitudeToFL {
    param($alt)
    
    if ($null -eq $alt -or $alt -eq '') { return $null }
    
    $altStr = $alt.ToString().ToUpper().Trim()
    
    # Check if numeric
    if ($altStr -match '^\d+$') {
        $altNum = [int]$altStr
        
        # If > 600, assume feet and convert to FL
        if ($altNum -gt 600) {
            return [int]($altNum / 100)
        }
        # Otherwise assume already FL
        return $altNum
    }
    
    # FL prefix
    if ($altStr -match '^FL?(\d+)$') {
        return [int]$Matches[1]
    }
    
    # Surface
    if ($altStr -eq 'SFC' -or $altStr -eq 'SURFACE') {
        return 0
    }
    
    return $null
}

function Convert-CoordsToWkt {
    param($coords)
    
    if (-not $coords -or $coords.Count -lt 3) {
        return @{ wkt = $null; centerLat = $null; centerLon = $null }
    }
    
    $points = @()
    $latSum = 0
    $lonSum = 0
    $validCount = 0
    
    foreach ($coord in $coords) {
        if (-not $coord) { continue }
        
        $lat = $coord.lat
        $lon = $coord.lon
        
        if ($null -eq $lat -or $null -eq $lon) { continue }
        
        $points += "$lon $lat"
        $latSum += $lat
        $lonSum += $lon
        $validCount++
    }
    
    if ($points.Count -lt 3) {
        return @{ wkt = $null; centerLat = $null; centerLon = $null }
    }
    
    # Ensure closed
    if ($points[0] -ne $points[-1]) {
        $points += $points[0]
    }
    
    return @{
        wkt = "POLYGON(($($points -join ', ')))"
        centerLat = [math]::Round($latSum / $validCount, 7)
        centerLon = [math]::Round($lonSum / $validCount, 7)
    }
}

function Convert-AlertItem {
    param($item)
    
    if (-not $item) { return $null }
    
    # AWC format: item IS the properties (no nested properties object)
    $props = $item
    
    # Determine type and hazard
    $rawType = if ($props.airSigmetType) { $props.airSigmetType.ToString().ToUpper() } else { '' }
    $hazard = if ($props.hazard) { $props.hazard.ToString().ToUpper() } else { 'UNKNOWN' }
    
    $alertType = 'SIGMET'
    if ($rawType -match 'AIRMET') { $alertType = 'AIRMET' }
    elseif ($rawType -match 'OUTLOOK|OTLK') { $alertType = 'OUTLOOK' }
    elseif ($hazard -eq 'CONVECTIVE' -or $rawType -match 'CONVECTIVE') { $alertType = 'CONVECTIVE' }
    
    # Parse coordinates to WKT
    if (-not $props.coords -or $props.coords.Count -lt 3) {
        return $null
    }
    
    $geoResult = Convert-CoordsToWkt $props.coords
    $wkt = $geoResult.wkt
    $centerLat = $geoResult.centerLat
    $centerLon = $geoResult.centerLon
    
    if (-not $wkt) {
        return $null
    }
    
    # Parse times (Unix timestamps)
    $validFrom = Convert-TimeString $props.validTimeFrom
    $validTo = Convert-TimeString $props.validTimeTo
    
    if (-not $validFrom -or -not $validTo) {
        return $null
    }
    
    # Parse altitudes (in feet, convert to FL)
    # Use altitudeLow1/altitudeHi2 as primary (altitudeLow2/altitudeHi1 are secondary ranges)
    $floorFl = Convert-AltitudeToFL (Coalesce @($props.altitudeLow1, $props.altitudeLow2))
    $ceilingFl = Convert-AltitudeToFL (Coalesce @($props.altitudeHi2, $props.altitudeHi1))
    
    # Movement
    $direction = if ($props.movementDir) { [int]$props.movementDir } else { $null }
    $speed = if ($props.movementSpd) { [int]$props.movementSpd } else { $null }
    
    # Source ID - use seriesId or construct one
    $sourceId = if ($props.seriesId) { 
        $props.seriesId.ToString() -replace '\s+', '_' 
    } else { 
        "$($props.icaoId)_$($alertType)_$(Get-Date -Format 'yyyyMMddHHmm')" 
    }
    
    # Severity (AWC uses numeric 1-5)
    $severityMap = @{ 1 = 'LGT'; 2 = 'LGT'; 3 = 'MOD'; 4 = 'MOD'; 5 = 'SEV' }
    $severity = if ($props.severity -and $severityMap.ContainsKey([int]$props.severity)) {
        $severityMap[[int]$props.severity]
    } else { $null }
    
    return @{
        alert_type = $alertType
        hazard = $hazard
        severity = $severity
        source_id = $sourceId
        valid_from = $validFrom
        valid_to = $validTo
        floor_fl = $floorFl
        ceiling_fl = $ceilingFl
        direction = $direction
        speed = $speed
        wkt = $wkt
        center_lat = $centerLat
        center_lon = $centerLon
        raw_text = $props.rawAirSigmet
    }
}

function Import-AlertsToDatabase {
    param($alerts, $showVerbose)
    
    Write-Host "Connecting to database..."
    
    # Convert alerts to JSON
    $json = $alerts | ConvertTo-Json -Depth 10 -Compress
    
    if ($showVerbose) {
        Write-Host "JSON payload: $($json.Length) bytes" -ForegroundColor Gray
    }
    
    # Try Azure AD auth first
    $connString = "Server=$DB_SERVER;Database=$DB_NAME;Authentication=Active Directory Default;TrustServerCertificate=True;Connect Timeout=30"
    
    $conn = $null
    try {
        $conn = New-Object System.Data.SqlClient.SqlConnection($connString)
        $conn.Open()
        Write-Host "Connected via Azure AD" -ForegroundColor Green
    }
    catch {
        Write-Host "Azure AD auth failed, trying environment credentials..." -ForegroundColor Yellow
        
        # Try with parameter or environment credentials
        $user = if ($script:SqlUser) { $script:SqlUser } else { $env:SQL_USER }
        $pass = if ($script:SqlPass) { $script:SqlPass } else { $env:SQL_PASS }
        
        if ($user -and $pass) {
            $connString = "Server=$DB_SERVER;Database=$DB_NAME;User Id=$user;Password=$pass;TrustServerCertificate=True;Connect Timeout=30"
            $conn = New-Object System.Data.SqlClient.SqlConnection($connString)
            $conn.Open()
            Write-Host "Connected via SQL auth" -ForegroundColor Green
        }
        else {
            throw "Database connection failed. Set SQL_USER and SQL_PASS environment variables."
        }
    }
    
    # Call import procedure with output parameters
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = "EXEC dbo.sp_ImportWeatherAlerts @json = @json, @source_url = @url, @alerts_inserted = @inserted OUTPUT, @alerts_updated = @updated OUTPUT, @alerts_expired = @expired OUTPUT"
    $cmd.Parameters.AddWithValue("@json", $json) | Out-Null
    $cmd.Parameters.AddWithValue("@url", $AWC_URL) | Out-Null
    
    $insertedParam = $cmd.Parameters.Add("@inserted", [System.Data.SqlDbType]::Int)
    $insertedParam.Direction = [System.Data.ParameterDirection]::Output
    
    $updatedParam = $cmd.Parameters.Add("@updated", [System.Data.SqlDbType]::Int)
    $updatedParam.Direction = [System.Data.ParameterDirection]::Output
    
    $expiredParam = $cmd.Parameters.Add("@expired", [System.Data.SqlDbType]::Int)
    $expiredParam.Direction = [System.Data.ParameterDirection]::Output
    
    $cmd.CommandTimeout = 60
    
    $cmd.ExecuteNonQuery() | Out-Null
    
    $result = @{
        inserted = $insertedParam.Value
        updated = $updatedParam.Value
        expired = $expiredParam.Value
    }
    $conn.Close()
    
    return $result
}

# ============================================================================
# Main
# ============================================================================

Write-Host "=======================================================================" -ForegroundColor Cyan
Write-Host "  PERTI Weather Alert Import" -ForegroundColor Cyan
Write-Host "  $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Cyan
Write-Host "=======================================================================" -ForegroundColor Cyan
Write-Host ""

$startTime = Get-Date

try {
    # Build URL
    $params = @{
        format = 'json'
    }
    if ($Type -ne 'all') {
        $params.type = $Type
    }
    
    $queryString = ($params.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }) -join '&'
    $url = "$AWC_URL`?$queryString"
    
    if ($ShowVerbose) {
        Write-Host "Fetching: $url" -ForegroundColor Gray
        Write-Host ""
    }
    
    # Fetch data
    Write-Host "Fetching weather data from aviationweather.gov..."
    
    $response = Invoke-RestMethod -Uri $url -Method Get -TimeoutSec 30 -Headers @{
        'Accept' = 'application/json'
        'User-Agent' = 'PERTI-WeatherImport/1.0'
    }
    
    # Response is an array directly
    $items = $response
    
    if (-not $items -or $items.Count -eq 0) {
        Write-Host "No weather alerts returned from API" -ForegroundColor Yellow
        exit 0
    }
    
    Write-Host "Received $($items.Count) raw items" -ForegroundColor Green
    
    if ($ShowVerbose) {
        Write-Host ""
        Write-Host "Sample raw item:" -ForegroundColor Gray
        Write-Host "  ICAO: $($items[0].icaoId)" -ForegroundColor DarkGray
        Write-Host "  Type: $($items[0].airSigmetType)" -ForegroundColor DarkGray
        Write-Host "  Hazard: $($items[0].hazard)" -ForegroundColor DarkGray
        Write-Host "  ValidFrom: $($items[0].validTimeFrom) -> $(Convert-TimeString $items[0].validTimeFrom)" -ForegroundColor DarkGray
        Write-Host "  ValidTo: $($items[0].validTimeTo) -> $(Convert-TimeString $items[0].validTimeTo)" -ForegroundColor DarkGray
        Write-Host "  Coords: $($items[0].coords.Count) points" -ForegroundColor DarkGray
        Write-Host ""
    }
    
    # Process alerts
    $alerts = @()
    foreach ($item in $items) {
        $alert = Convert-AlertItem $item
        if ($alert) {
            $alerts += $alert
        }
    }
    
    Write-Host "Parsed $($alerts.Count) valid alerts with geometry" -ForegroundColor Green
    Write-Host ""
    
    # Summary by type
    if ($alerts.Count -gt 0) {
        $summary = $alerts | Group-Object -Property { $_.alert_type + "/" + $_.hazard }
        Write-Host "Alert Summary:" -ForegroundColor Yellow
        foreach ($group in $summary) {
            Write-Host "  $($group.Name): $($group.Count)"
        }
        Write-Host ""
    }
    
    if ($DryRun) {
        Write-Host "[DRY RUN] Skipping database import" -ForegroundColor Yellow
        
        if ($alerts.Count -gt 0) {
            Write-Host ""
            Write-Host "Sample parsed alert:" -ForegroundColor Yellow
            $alerts[0].GetEnumerator() | Sort-Object Name | ForEach-Object {
                $val = if ($_.Value -and $_.Value.ToString().Length -gt 80) { 
                    $_.Value.ToString().Substring(0, 80) + "..." 
                } else { 
                    $_.Value 
                }
                Write-Host "  $($_.Name): $val"
            }
        }
    }
    else {
        if ($alerts.Count -eq 0) {
            Write-Host "No valid alerts to import" -ForegroundColor Yellow
        }
        else {
            # Import to database
            $result = Import-AlertsToDatabase $alerts $ShowVerbose
            
            Write-Host "Import Results:" -ForegroundColor Green
            Write-Host "  Inserted: $($result.inserted)"
            Write-Host "  Updated:  $($result.updated)"
            Write-Host "  Expired:  $($result.expired)"
        }
    }
    
}
catch {
    Write-Host "ERROR: $_" -ForegroundColor Red
    Write-Host $_.ScriptStackTrace -ForegroundColor Red
    exit 1
}

$elapsed = ((Get-Date) - $startTime).TotalSeconds
Write-Host ""
Write-Host "Completed in $([math]::Round($elapsed, 2)) seconds" -ForegroundColor Cyan
