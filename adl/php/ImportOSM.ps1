# ============================================================================
# ImportOSM.ps1 - v2
# ============================================================================

param(
    [string]$Airport = "",
    [string]$StartFrom = "",
    [int]$DelaySeconds = 2,
    [switch]$DryRun
)

# Config
$SqlServer = ""
$SqlDatabase = ""
$SqlUser = ""
$SqlPassword = ""

$ConfigPath = Join-Path $PSScriptRoot "..\..\load\config.php"
if (Test-Path $ConfigPath) {
    $c = Get-Content $ConfigPath -Raw
    if ($c -match "define\s*\(\s*['""]ADL_SQL_HOST['""]\s*,\s*['""]([^'""]+)['""]\s*\)") { $SqlServer = $Matches[1] }
    if ($c -match "define\s*\(\s*['""]ADL_SQL_DATABASE['""]\s*,\s*['""]([^'""]+)['""]\s*\)") { $SqlDatabase = $Matches[1] }
    if ($c -match "define\s*\(\s*['""]ADL_SQL_USERNAME['""]\s*,\s*['""]([^'""]+)['""]\s*\)") { $SqlUser = $Matches[1] }
    if ($c -match "define\s*\(\s*['""]ADL_SQL_PASSWORD['""]\s*,\s*['""]([^'""]+)['""]\s*\)") { $SqlPassword = $Matches[1] }
}

if (-not $SqlServer -or -not $SqlDatabase -or -not $SqlUser -or -not $SqlPassword) {
    Write-Host "ERROR: Could not read credentials from config.php" -ForegroundColor Red
    exit 1
}

$Airports = @(
    "KATL","KBOS","KBWI","KCLE","KCLT","KCVG","KDCA","KDEN","KDFW","KDTW",
    "KEWR","KFLL","KHNL","KHOU","KHPN","KIAD","KIAH","KISP","KJFK","KLAS",
    "KLAX","KLGA","KMCI","KMCO","KMDW","KMEM","KMIA","KMKE","KMSP","KMSY",
    "KOAK","KONT","KORD","KPBI","KPDX","KPHL","KPHX","KPIT","KPVD","KRDU",
    "KRSW","KSAN","KSAT","KSDF","KSEA","KSFO","KSJC","KSLC","KSMF","KSNA",
    "KSTL","KSWF","KTEB","KTPA","KAUS","KABQ","KANC","KBDL","KBNA","KBUF",
    "KBUR","KCHS","KCMH","KDAL","KGSO","KIND","KJAX","KMHT","KOMA","KORF",
    "KPWM","KRNO","KRIC","KSAV","KSYR","KTUL",
    "CYYZ","CYVR","CYUL","CYYC","CYOW","CYEG","CYWG","CYHZ","CYQB","CYYJ",
    "CYXE","CYQR","CYYT","CYTZ","CYQM","CYZF","CYXY",
    "MMMX","MMUN","MMTJ","MMMY","MMGL","MMPR","MMSD","MMCZ","MMMD","MMHO",
    "MMCU","MMMZ","MMTO","MMZH","MMAA","MMVR","MMTC","MMCL","MMAS","MMBT",
    "MGGT","MSLP","MHTG","MNMG","MROC","MPTO","MRLB","MPHO","MZBZ",
    "TJSJ","TJBQ","TIST","TISX","MYNN","MYEF","MYGF","MUHA","MUVR","MUCU",
    "MKJP","MKJS","MDSD","MDPP","MDPC","MTPP","MWCR","MBPV","TNCM","TNCA",
    "TNCB","TNCC","TBPB","TLPL","TAPA","TKPK","TGPY","TTPP","TUPJ","TFFR",
    "TFFF","TFFJ","TFFG",
    "SBGR","SBSP","SBRJ","SBGL","SBKP","SBBR","SBCF","SBPA","SBSV","SBRF",
    "SBFZ","SBCT","SBFL","SAEZ","SABE","SACO","SAAR","SAWH","SANC","SAME",
    "SCEL","SCFA","SCIE","SCTE","SCDA","SKBO","SKRG","SKCL","SKBQ","SKCG",
    "SKSP","SPJC","SPZO","SPQU","SEQM","SEGU","SEGS","SVMI","SVMC","SVVA",
    "SLLP","SLVR","SGAS","SUMU","SYCJ","SMJP"
)

$TypeMap = @{ "runway"="RUNWAY"; "taxiway"="TAXIWAY"; "taxilane"="TAXILANE"; "apron"="APRON"; "parking_position"="PARKING"; "gate"="GATE"; "holding_position"="HOLD" }
$BufferMap = @{ "RUNWAY"=45; "TAXIWAY"=20; "TAXILANE"=15; "APRON"=50; "PARKING"=25; "GATE"=20; "HOLD"=15 }

Add-Type -AssemblyName System.Web

Write-Host "======================================================================="
Write-Host "  PERTI OSM Airport Geometry Import v2"
Write-Host "  $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
Write-Host "======================================================================="

$Conn = $null
if (-not $DryRun) {
    try {
        $Conn = New-Object System.Data.SqlClient.SqlConnection("Server=$SqlServer;Database=$SqlDatabase;User Id=$SqlUser;Password=$SqlPassword;Encrypt=True;TrustServerCertificate=False;")
        $Conn.Open()
        Write-Host "Connected to database" -ForegroundColor Green
    } catch {
        Write-Host "DB Error: $($_.Exception.Message)" -ForegroundColor Red
        exit 1
    }
}

$AirportList = if ($Airport) { @($Airport.ToUpper()) } else { $Airports }
$Total = $AirportList.Count
$Cur = 0; $Ok = 0; $NoOsm = 0; $Fail = 0
$Skip = $StartFrom -ne ""

Write-Host "Processing $Total airports...`n"

foreach ($Icao in $AirportList) {
    $Cur++
    if ($Skip) { if ($Icao -eq $StartFrom.ToUpper()) { $Skip = $false } else { continue } }
    
    Write-Host ("[{0,3}/{1}] {2} " -f $Cur, $Total, $Icao) -NoNewline
    
    # Fetch OSM
    $IcaoLower = $Icao.ToLower()
    $Query = "[out:json][timeout:60];(area[`"icao`"=`"$Icao`"]->.a;area[`"icao`"=`"$IcaoLower`"]->.b;);(way[`"aeroway`"=`"runway`"](area.a);way[`"aeroway`"=`"runway`"](area.b);way[`"aeroway`"=`"taxiway`"](area.a);way[`"aeroway`"=`"taxiway`"](area.b);way[`"aeroway`"=`"apron`"](area.a);way[`"aeroway`"=`"apron`"](area.b);node[`"aeroway`"=`"parking_position`"](area.a);node[`"aeroway`"=`"parking_position`"](area.b);node[`"aeroway`"=`"gate`"](area.a);node[`"aeroway`"=`"gate`"](area.b);node[`"aeroway`"=`"holding_position`"](area.a);node[`"aeroway`"=`"holding_position`"](area.b););out body;>;out skel qt;"
    
    try {
        $Resp = Invoke-RestMethod -Uri "https://overpass-api.de/api/interpreter" -Method Post -Body ("data=" + [System.Web.HttpUtility]::UrlEncode($Query)) -ContentType "application/x-www-form-urlencoded" -TimeoutSec 60
    } catch {
        Write-Host "API FAIL" -ForegroundColor Red
        $Fail++
        Start-Sleep $DelaySeconds
        continue
    }
    
    if (-not $Resp.elements) {
        Write-Host "no data -> fallback" -ForegroundColor Yellow
        if ($Conn) { 
            $cmd = $Conn.CreateCommand(); $cmd.CommandText = "EXEC dbo.sp_GenerateFallbackZones @airport_icao = @i"
            $cmd.Parameters.AddWithValue("@i", $Icao) | Out-Null
            try { $cmd.ExecuteNonQuery() | Out-Null } catch {}
        }
        $NoOsm++
        Start-Sleep $DelaySeconds
        continue
    }
    
    # Build nodes dict
    $Nodes = @{}
    foreach ($e in $Resp.elements) {
        if ($e.type -eq "node" -and $null -ne $e.lat) {
            $Nodes["$($e.id)"] = @{ lat=[double]$e.lat; lon=[double]$e.lon }
        }
    }
    
    # Build zones
    $Zones = @()
    foreach ($e in $Resp.elements) {
        if (-not $e.tags) { continue }
        $aero = $e.tags.aeroway
        if (-not $aero -or -not $TypeMap.ContainsKey($aero)) { continue }
        
        $zt = $TypeMap[$aero]
        $zn = if ($e.tags.ref) { $e.tags.ref } elseif ($e.tags.name) { $e.tags.name } else { $null }
        $lat = $null; $lon = $null
        
        if ($e.type -eq "node" -and $null -ne $e.lat) {
            $lat = [double]$e.lat; $lon = [double]$e.lon
        } elseif ($e.type -eq "way" -and $e.nodes) {
            $sLat = 0.0; $sLon = 0.0; $cnt = 0
            foreach ($nid in $e.nodes) {
                $k = "$nid"
                if ($Nodes.ContainsKey($k)) { $sLat += $Nodes[$k].lat; $sLon += $Nodes[$k].lon; $cnt++ }
            }
            if ($cnt -ge 2) { $lat = $sLat / $cnt; $lon = $sLon / $cnt }
        }
        
        if ($null -ne $lat) {
            $Zones += @{ osm_id=$e.id; zone_type=$zt; zone_name=$zn; buffer=$BufferMap[$zt]; lat=$lat; lon=$lon }
        }
    }
    
    if ($Zones.Count -eq 0) {
        Write-Host "parsed 0 -> fallback" -ForegroundColor Yellow
        if ($Conn) {
            $cmd = $Conn.CreateCommand(); $cmd.CommandText = "EXEC dbo.sp_GenerateFallbackZones @airport_icao = @i"
            $cmd.Parameters.AddWithValue("@i", $Icao) | Out-Null
            try { $cmd.ExecuteNonQuery() | Out-Null } catch {}
        }
        $NoOsm++
        Start-Sleep $DelaySeconds
        continue
    }
    
    # Import
    $ins = 0; $rwy = 0; $twy = 0; $prk = 0
    if ($Conn) {
        $del = $Conn.CreateCommand(); $del.CommandText = "DELETE FROM dbo.airport_geometry WHERE airport_icao = @i AND source = 'OSM'"
        $del.Parameters.AddWithValue("@i", $Icao) | Out-Null
        $del.ExecuteNonQuery() | Out-Null
        
        foreach ($z in $Zones) {
            $sql = "INSERT INTO dbo.airport_geometry (airport_icao, zone_type, zone_name, osm_id, geometry, center_lat, center_lon, source) VALUES (@i, @zt, @zn, @oid, geography::Point(@lat, @lon, 4326).STBuffer(@buf), @lat, @lon, 'OSM')"
            $cmd = $Conn.CreateCommand(); $cmd.CommandText = $sql
            $cmd.Parameters.AddWithValue("@i", $Icao) | Out-Null
            $cmd.Parameters.AddWithValue("@zt", $z.zone_type) | Out-Null
            $cmd.Parameters.AddWithValue("@zn", $(if ($z.zone_name) { $z.zone_name } else { [DBNull]::Value })) | Out-Null
            $cmd.Parameters.AddWithValue("@oid", $z.osm_id) | Out-Null
            $cmd.Parameters.AddWithValue("@lat", $z.lat) | Out-Null
            $cmd.Parameters.AddWithValue("@lon", $z.lon) | Out-Null
            $cmd.Parameters.AddWithValue("@buf", $z.buffer) | Out-Null
            try { $cmd.ExecuteNonQuery() | Out-Null; $ins++
                if ($z.zone_type -eq "RUNWAY") { $rwy++ }
                if ($z.zone_type -in @("TAXIWAY","TAXILANE")) { $twy++ }
                if ($z.zone_type -in @("PARKING","GATE")) { $prk++ }
            } catch {}
        }
        
        $log = $Conn.CreateCommand()
        $log.CommandText = "INSERT INTO dbo.airport_geometry_import_log (airport_icao, source, zones_imported, runways_count, taxiways_count, parking_count, success) VALUES (@i, 'OSM', @z, @r, @t, @p, 1)"
        $log.Parameters.AddWithValue("@i", $Icao) | Out-Null
        $log.Parameters.AddWithValue("@z", $ins) | Out-Null
        $log.Parameters.AddWithValue("@r", $rwy) | Out-Null
        $log.Parameters.AddWithValue("@t", $twy) | Out-Null
        $log.Parameters.AddWithValue("@p", $prk) | Out-Null
        try { $log.ExecuteNonQuery() | Out-Null } catch {}
    } else {
        $ins = $Zones.Count
    }
    
    Write-Host "$ins zones" -ForegroundColor Green
    $Ok++
    Start-Sleep $DelaySeconds
}

if ($Conn) { $Conn.Close() }

Write-Host "`n======================================================================="
Write-Host "Done: $Ok success, $NoOsm fallback, $Fail failed"
Write-Host "======================================================================="
