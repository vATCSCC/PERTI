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

.PARAMETER Verbose
    Show detailed output

.EXAMPLE
    .\Import-WeatherAlerts.ps1
    # Full import

.EXAMPLE
    .\Import-WeatherAlerts.ps1 -DryRun
    # Test without importing

.EXAMPLE
    .\Import-WeatherAlerts.ps1 -Type sigmet -Verbose
    # Import only SIGMETs with detailed output

.NOTES
    Version: 1.0
    Date: 2026-01-06
#>

param(
    [switch]$DryRun,
    [ValidateSet('sigmet', 'airmet', 'all')]
    [string]$Type = 'all',
    [switch]$Verbose
)

# Configuration
$AWC_URL = "https://aviationweather.gov/api/data/airsigmet"
$DB_SERVER = "vatsim.database.windows.net"
$DB_NAME = "VATSIM_ADL"

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
    
    if ($Verbose) {
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
        $alert = Convert-Alert $item
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
        $result = Import-AlertsToDatabase $alerts
        
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

function Convert-Alert {
    param($item)
    
    # Handle GeoJSON format
    $props = if ($item.properties) { $item.properties } else { $item }
    $geom = $item.geometry
    
    # Determine type and hazard
    $rawType = ($props.airSigmetType ?? $props.type ?? '').ToUpper()
    $hazard = ($props.hazard ?? $props.wxType ?? 'UNKNOWN').ToUpper()
    
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
    $validFrom = Convert-Time ($props.validTimeFrom ?? $props.validFrom ?? $props.issueTime)
    $validTo = Convert-Time ($props.validTimeTo ?? $props.validTo ?? $props.expireTime)
    
    if (-not $validFrom -or -not $validTo) {
        return $null
    }
    
    # Parse altitudes
    $floorFl = Convert-Altitude ($props.altitudeLow1 ?? $props.base ?? $props.loAlt)
    $ceilingFl = Convert-Altitude ($props.altitudeHi1 ?? $props.top ?? $props.hiAlt)
    
    # Source ID
    $sourceId = $props.airSigmetId ?? $props.id ?? $props.seriesId ?? "$($alertType)_$($hazard)_$(Get-Date -Format 'yyyyMMddHHmm')"
    
    return @{
        alert_type = $alertType
        hazard = $hazard
        severity = ($props.severity ?? '').ToUpper()
        source_id = $sourceId
        valid_from = $validFrom
        valid_to = $validTo
        floor_fl = $floorFl
        ceiling_fl = $ceilingFl
        direction = [int]($props.dir ?? $props.movementDir ?? 0) | Where-Object { $_ -gt 0 }
        speed = [int]($props.spd ?? $props.movementSpd ?? 0) | Where-Object { $_ -gt 0 }
        wkt = $wkt
        center_lat = $centerLat
        center_lon = $centerLon
        raw_text = $props.rawAirSigmet ?? $props.rawText
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
        $lat = $coord.lat ?? $coord.latitude ?? $coord[1]
        $lon = $coord.lon ?? $coord.longitude ?? $coord[0]
        
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

function Convert-Time {
    param($timeStr)
    
    if (-not $timeStr) { return $null }
    
    try {
        $dt = [DateTime]::Parse($timeStr)
        return $dt.ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    }
    catch {
        return $null
    }
}

function Convert-Altitude {
    param($alt)
    
    if (-not $alt) { return $null }
    
    $alt = $alt.ToString().ToUpper().Trim()
    
    # Already flight level
    if ($alt -match '^\d+$' -and [int]$alt -le 600) {
        return [int]$alt
    }
    
    # FL prefix
    if ($alt -match '^FL?(\d+)$') {
        return [int]$Matches[1]
    }
    
    # Feet - convert
    if ($alt -match '^\d+$' -and [int]$alt -gt 600) {
        return [int]([int]$alt / 100)
    }
    
    # Surface
    if ($alt -eq 'SFC' -or $alt -eq 'SURFACE') {
        return 0
    }
    
    return $null
}

function Import-AlertsToDatabase {
    param($alerts)
    
    Write-Host "Connecting to database..."
    
    # Build connection string
    $connString = "Server=$DB_SERVER;Database=$DB_NAME;Authentication=Active Directory Default;TrustServerCertificate=True;Connect Timeout=30"
    
    try {
        $conn = New-Object System.Data.SqlClient.SqlConnection($connString)
        $conn.Open()
    }
    catch {
        # Try with environment credentials
        $user = $env:SQL_USER
        $pass = $env:SQL_PASS
        
        if ($user -and $pass) {
            $connString = "Server=$DB_SERVER;Database=$DB_NAME;User Id=$user;Password=$pass;TrustServerCertificate=True;Connect Timeout=30"
            $conn = New-Object System.Data.SqlClient.SqlConnection($connString)
            $conn.Open()
        }
        else {
            throw "Database connection failed: $_"
        }
    }
    
    Write-Host "Connected to $DB_NAME" -ForegroundColor Green
    
    # Convert alerts to JSON
    $json = $alerts | ConvertTo-Json -Depth 10 -Compress
    
    if ($Verbose) {
        Write-Host "JSON payload: $($json.Length) bytes" -ForegroundColor Gray
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
