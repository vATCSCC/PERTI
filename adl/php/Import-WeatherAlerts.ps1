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
    Version: 1.1
    Date: 2026-01-06
    Compatible with PowerShell 5.1+
#>

param(
    [switch]$DryRun,
    [ValidateSet('sigmet', 'airmet', 'all')]
    [string]$Type = 'all',
    [switch]$ShowVerbose
)

# Configuration
$AWC_URL = "https://aviationweather.gov/api/data/airsigmet"
$DB_SERVER = "vatsim.database.windows.net"
$DB_NAME = "VATSIM_ADL"

# Helper function for null coalescing (PS 5.1 compatible)
function Coalesce {
    param([array]$values)
    foreach ($v in $values) {
        if ($null -ne $v -and $v -ne '') { return $v }
    }
    return $null
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
    
    # Parse response - handle different formats
    $items = if ($response.features) { $response.features } else { $response }
    
    if (-not $items -or $items.Count -eq 0) {
        Write-Host "No weather alerts returned from API" -ForegroundColor Yellow
        exit 0
    }
    
    Write-Host "Received $($items.Count) raw items" -ForegroundColor Green
    
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
    $summary = $alerts | Group-Object -Property alert_type, hazard
    Write-Host "Alert Summary:" -ForegroundColor Yellow
    foreach ($group in $summary) {
        Write-Host "  $($group.Name): $($group.Count)"
    }
    Write-Host ""
    
    if ($DryRun) {
        Write-Host "[DRY RUN] Skipping database import" -ForegroundColor Yellow
        
        if ($alerts.Count -gt 0) {
            Write-Host ""
            Write-Host "Sample alert:" -ForegroundColor Yellow
            $alerts[0] | Format-List
        }
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
catch {
    Write-Host "ERROR: $_" -ForegroundColor Red
    Write-Host $_.ScriptStackTrace -ForegroundColor Red
    exit 1
}

$elapsed = ((Get-Date) - $startTime).TotalSeconds
Write-Host ""
Write-Host "Completed in $([math]::Round($elapsed, 2)) seconds" -ForegroundColor Cyan

# ============================================================================
# Functions
# ============================================================================

function Convert-AlertItem {
    param($item)
    
    # Handle GeoJSON format
    $props = if ($item.properties) { $item.properties } else { $item }
    $geom = $item.geometry
    
    # Determine type and hazard
    $rawType = (Coalesce @($props.airSigmetType, $props.type, '')).ToString().ToUpper()
    $hazard = (Coalesce @($props.hazard, $props.wxType, 'UNKNOWN')).ToString().ToUpper()
    
    $alertType = 'SIGMET'
    if ($rawType -match 'AIRMET') { $alertType = 'AIRMET' }
    elseif ($rawType -match 'OUTLOOK|OTLK') { $alertType = 'OUTLOOK' }
    elseif ($hazard -eq 'CONVECTIVE' -or $rawType -match 'CONVECTIVE') { $alertType = 'CONVECTIVE' }
    
    # Parse coordinates to WKT
    $wkt = $null
    $centerLat = $null
    $centerLon = $null
    
    if ($geom -and $geom.coordinates) {
        $result = Convert-GeometryToWkt $geom
        $wkt = $result.wkt
        $centerLat = $result.centerLat
        $centerLon = $result.centerLon
    }
    elseif ($props.coords) {
        $result = Convert-CoordsToWkt $props.coords
        $wkt = $result.wkt
        $centerLat = $result.centerLat
        $centerLon = $result.centerLon
    }
    
    if (-not $wkt) {
        return $null
    }
    
    # Parse times
    $validFrom = Convert-TimeString (Coalesce @($props.validTimeFrom, $props.validFrom, $props.issueTime))
    $validTo = Convert-TimeString (Coalesce @($props.validTimeTo, $props.validTo, $props.expireTime))
    
    if (-not $validFrom -or -not $validTo) {
        return $null
    }
    
    # Parse altitudes
    $floorFl = Convert-AltitudeToFL (Coalesce @($props.altitudeLow1, $props.base, $props.loAlt))
    $ceilingFl = Convert-AltitudeToFL (Coalesce @($props.altitudeHi1, $props.top, $props.hiAlt))
    
    # Movement
    $direction = [int](Coalesce @($props.dir, $props.movementDir, 0))
    if ($direction -eq 0) { $direction = $null }
    
    $speed = [int](Coalesce @($props.spd, $props.movementSpd, 0))
    if ($speed -eq 0) { $speed = $null }
    
    # Source ID
    $sourceId = Coalesce @($props.airSigmetId, $props.id, $props.seriesId)
    if (-not $sourceId) {
        $sourceId = "$($alertType)_$($hazard)_$(Get-Date -Format 'yyyyMMddHHmm')"
    }
    
    # Severity
    $severity = if ($props.severity) { $props.severity.ToString().ToUpper() } else { $null }
    
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
        raw_text = Coalesce @($props.rawAirSigmet, $props.rawText)
    }
}

function Convert-GeometryToWkt {
    param($geom)
    
    $type = $geom.type
    $coords = $geom.coordinates
    
    if ($type -eq 'Polygon' -and $coords.Count -gt 0) {
        $ring = $coords[0]
        $points = @()
        $latSum = 0
        $lonSum = 0
        
        foreach ($coord in $ring) {
            $lon = $coord[0]
            $lat = $coord[1]
            $points += "$lon $lat"
            $lonSum += $lon
            $latSum += $lat
        }
        
        # Ensure closed
        if ($points[0] -ne $points[-1]) {
            $points += $points[0]
        }
        
        return @{
            wkt = "POLYGON(($($points -join ', ')))"
            centerLat = [math]::Round($latSum / $ring.Count, 7)
            centerLon = [math]::Round($lonSum / $ring.Count, 7)
        }
    }
    
    if ($type -eq 'MultiPolygon' -and $coords.Count -gt 0) {
        # Take first polygon
        $ring = $coords[0][0]
        $points = @()
        $latSum = 0
        $lonSum = 0
        
        foreach ($coord in $ring) {
            $lon = $coord[0]
            $lat = $coord[1]
            $points += "$lon $lat"
            $lonSum += $lon
            $latSum += $lat
        }
        
        if ($points[0] -ne $points[-1]) {
            $points += $points[0]
        }
        
        return @{
            wkt = "POLYGON(($($points -join ', ')))"
            centerLat = [math]::Round($latSum / $ring.Count, 7)
            centerLon = [math]::Round($lonSum / $ring.Count, 7)
        }
    }
    
    return @{ wkt = $null; centerLat = $null; centerLon = $null }
}

function Convert-CoordsToWkt {
    param($coords)
    
    if ($coords.Count -lt 3) {
        return @{ wkt = $null; centerLat = $null; centerLon = $null }
    }
    
    $points = @()
    $latSum = 0
    $lonSum = 0
    
    foreach ($coord in $coords) {
        $lat = Coalesce @($coord.lat, $coord.latitude, $coord[1])
        $lon = Coalesce @($coord.lon, $coord.longitude, $coord[0])
        
        # AWC sometimes uses positive west longitude
        if ($lon -gt 0 -and $lon -gt 30) {
            $lon = -$lon
        }
        
        if ($lat -and $lon) {
            $points += "$lon $lat"
            $latSum += $lat
            $lonSum += $lon
        }
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
        centerLat = [math]::Round($latSum / $coords.Count, 7)
        centerLon = [math]::Round($lonSum / $coords.Count, 7)
    }
}

function Convert-TimeString {
    param($timeStr)
    
    if (-not $timeStr) { return $null }
    
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
    
    # Already flight level
    if ($altStr -match '^\d+$' -and [int]$altStr -le 600) {
        return [int]$altStr
    }
    
    # FL prefix
    if ($altStr -match '^FL?(\d+)$') {
        return [int]$Matches[1]
    }
    
    # Feet - convert
    if ($altStr -match '^\d+$' -and [int]$altStr -gt 600) {
        return [int]([int]$altStr / 100)
    }
    
    # Surface
    if ($altStr -eq 'SFC' -or $altStr -eq 'SURFACE') {
        return 0
    }
    
    return $null
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
        
        # Try with environment credentials
        $user = $env:SQL_USER
        $pass = $env:SQL_PASS
        
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
    
    # Call import procedure
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = "EXEC dbo.sp_ImportWeatherAlerts @json = @json, @source_url = @url"
    $cmd.Parameters.AddWithValue("@json", $json) | Out-Null
    $cmd.Parameters.AddWithValue("@url", $AWC_URL) | Out-Null
    $cmd.CommandTimeout = 60
    
    $reader = $cmd.ExecuteReader()
    
    $result = @{
        inserted = 0
        updated = 0
        expired = 0
    }
    
    if ($reader.Read()) {
        $result.inserted = $reader["inserted"]
        $result.updated = $reader["updated"]
        $result.expired = $reader["expired"]
    }
    
    $reader.Close()
    $conn.Close()
    
    return $result
}
