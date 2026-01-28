# TMI Load Test Script (PowerShell with curl)
# Simulates 30 users submitting 25 restrictions + 4 advisories each over 5 minutes

param(
    [int]$Users = 30,
    [int]$RestrictionsPerUser = 25,
    [int]$AdvisoriesPerUser = 4,
    [int]$DurationSeconds = 300,
    [int]$ConcurrentJobs = 10,
    [string]$BaseUrl = "https://perti.vatcscc.org",
    [switch]$DryRun
)

$ErrorActionPreference = "Continue"

# Configuration
$TotalTmisPerUser = $RestrictionsPerUser + $AdvisoriesPerUser
$TotalTmis = $Users * $TotalTmisPerUser
$DelayBetweenRequests = [math]::Max(10, [int](($DurationSeconds * 1000) / $TotalTmis))

Write-Host "=" * 70 -ForegroundColor Cyan
Write-Host "              TMI LOAD TESTING SCRIPT (PowerShell)" -ForegroundColor Cyan
Write-Host "=" * 70 -ForegroundColor Cyan
Write-Host ""
Write-Host "  Configuration:" -ForegroundColor Yellow
Write-Host "  - Users:              $Users"
Write-Host "  - Restrictions/user:  $RestrictionsPerUser"
Write-Host "  - Advisories/user:    $AdvisoriesPerUser"
Write-Host "  - Duration:           $DurationSeconds seconds ($([math]::Round($DurationSeconds/60, 1)) min)"
Write-Host "  - Total TMIs:         $TotalTmis"
Write-Host "  - Target rate:        $([math]::Round($TotalTmis / $DurationSeconds, 2)) requests/sec"
Write-Host "  - Concurrent jobs:    $ConcurrentJobs"
Write-Host "  - Base URL:           $BaseUrl"
Write-Host "  - Mode:               $(if ($DryRun) { 'DRY RUN' } else { 'LIVE TEST' })"
Write-Host ""
Write-Host "=" * 70 -ForegroundColor Cyan

# Sample data arrays
$Airports = @('KJFK', 'KEWR', 'KLGA', 'KBOS', 'KPHL', 'KDCA', 'KIAD', 'KBWI', 'KATL', 'KORD', 'KDFW', 'KLAX', 'KSFO', 'KDEN', 'KMIA')
$Facilities = @('N90', 'A90', 'C90', 'D10', 'I90', 'P50', 'S46', 'PCT', 'Y90')
$Artccs = @('ZNY', 'ZBW', 'ZDC', 'ZTL', 'ZOB', 'ZAU', 'ZLA', 'ZOA', 'ZSE', 'ZDV', 'ZMA', 'ZJX')
$Fixes = @('LENDY', 'PARCH', 'BIGGY', 'BRIGS', 'CAMRN', 'DIXIE', 'EMJAY', 'FINNN', 'GREKI', 'HOLEY')
$Reasons = @('VOLUME', 'WEATHER', 'EQUIPMENT', 'RUNWAY', 'STAFFING')
$EntryTypes = @('MIT', 'MINIT', 'STOP', 'APREQ', 'CFR', 'DELAY')
$AdvisoryTypes = @('OPSPLAN', 'FREEFORM', 'HOTLINE')

function Get-RandomElement($Array) {
    return $Array[(Get-Random -Maximum $Array.Length)]
}

function New-RestrictionPayload($UserCid, $Index) {
    $airport = Get-RandomElement $Airports
    $facility = Get-RandomElement $Facilities
    $artcc = Get-RandomElement $Artccs
    $fix = Get-RandomElement $Fixes
    $reason = Get-RandomElement $Reasons
    $entryType = Get-RandomElement $EntryTypes
    $value = Get-Random -Minimum 5 -Maximum 31

    $validFrom = (Get-Date).AddMinutes((Get-Random -Minimum 0 -Maximum 30)).ToString("yyyy-MM-ddTHH:mm")
    $validUntil = (Get-Date).AddHours((Get-Random -Minimum 2 -Maximum 6)).ToString("yyyy-MM-ddTHH:mm")

    $preview = "$(Get-Date -Format 'dd/HHmm') $airport ARR VIA $fix ##$entryType ${value}NM $reason [LOADTEST-$UserCid-$Index]"

    return @{
        entries = @(
            @{
                type = "ntml"
                entryType = $entryType
                preview = $preview
                orgs = @("vatcscc")
                data = @{
                    ctl_element = $airport
                    req_facility = $facility
                    prov_facility = $artcc
                    value = $value
                    valid_from = $validFrom
                    valid_until = $validUntil
                    reason_category = $reason
                    reason_cause = "Load test generated"
                    via_fix = $fix
                }
            }
        )
        production = $false
        userCid = $UserCid
        userName = "LoadTest User"
    }
}

function New-AdvisoryPayload($UserCid, $Index, $AdvNum) {
    $airport = Get-RandomElement $Airports
    $reason = Get-RandomElement $Reasons
    $advisoryType = Get-RandomElement $AdvisoryTypes

    $validFrom = (Get-Date).AddMinutes((Get-Random -Minimum 0 -Maximum 30)).ToString("yyyy-MM-ddTHH:mm")
    $validUntil = (Get-Date).AddHours((Get-Random -Minimum 2 -Maximum 6)).ToString("yyyy-MM-ddTHH:mm")

    $preview = "vATCSCC ADVZY LT-$AdvNum $airport $(Get-Date -Format 'MM/dd/yyyy')`n$advisoryType ADVISORY [LOADTEST-$UserCid-$Index]"

    return @{
        entries = @(
            @{
                type = "advisory"
                entryType = $advisoryType
                preview = $preview
                orgs = @("vatcscc")
                data = @{
                    number = "ADVZY LT-$AdvNum"
                    impacted_area = $airport
                    effective_time = $validFrom
                    end_time = $validUntil
                    subject = "$airport $advisoryType - Load Test"
                    reason = $reason
                }
            }
        )
        production = $false
        userCid = $UserCid
        userName = "LoadTest User"
    }
}

# Build all requests
Write-Host "`nBuilding request queue..." -ForegroundColor Yellow
$Requests = [System.Collections.ArrayList]::new()
$advCounter = 1

for ($u = 0; $u -lt $Users; $u++) {
    $userCid = 1000000 + $u

    # Restrictions
    for ($r = 0; $r -lt $RestrictionsPerUser; $r++) {
        [void]$Requests.Add(@{
            Type = "restriction"
            Payload = (New-RestrictionPayload $userCid $r)
        })
    }

    # Advisories
    for ($a = 0; $a -lt $AdvisoriesPerUser; $a++) {
        [void]$Requests.Add(@{
            Type = "advisory"
            Payload = (New-AdvisoryPayload $userCid $a $advCounter)
        })
        $advCounter++
    }
}

# Shuffle requests
$Requests = $Requests | Sort-Object { Get-Random }

Write-Host "Generated $($Requests.Count) requests`n" -ForegroundColor Green

if (-not $DryRun) {
    Write-Host "WARNING: This will send $($Requests.Count) real requests to the server." -ForegroundColor Red
    Write-Host "The requests will be sent to STAGING (production=false)." -ForegroundColor Yellow
    Write-Host "Press Enter to continue or Ctrl+C to abort..." -ForegroundColor Yellow
    Read-Host
}

# Metrics
$Metrics = @{
    StartTime = Get-Date
    Completed = 0
    Successful = 0
    Failed = 0
    ResponseTimes = [System.Collections.ArrayList]::new()
    HttpCodes = @{}
    Errors = [System.Collections.ArrayList]::new()
}

$Url = "$BaseUrl/api/mgt/tmi/publish.php"
Write-Host "Starting load test..." -ForegroundColor Green
Write-Host "Target URL: $Url"
Write-Host "Delay between requests: ${DelayBetweenRequests}ms`n"

# Progress tracking
$Total = $Requests.Count
$Lock = [System.Object]::new()

# Process requests
$JobResults = @()

if ($DryRun) {
    Write-Host "DRY RUN MODE - Simulating requests...`n" -ForegroundColor Magenta

    foreach ($req in $Requests) {
        $simulatedTime = (Get-Random -Minimum 50 -Maximum 500)
        Start-Sleep -Milliseconds ([math]::Min(50, $DelayBetweenRequests))

        $Metrics.Completed++
        $Metrics.Successful++
        [void]$Metrics.ResponseTimes.Add($simulatedTime)
        if (-not $Metrics.HttpCodes.ContainsKey(200)) { $Metrics.HttpCodes[200] = 0 }
        $Metrics.HttpCodes[200]++

        if ($Metrics.Completed % 20 -eq 0 -or $Metrics.Completed -eq $Total) {
            $elapsed = ((Get-Date) - $Metrics.StartTime).TotalSeconds
            $rate = [math]::Round($Metrics.Completed / [math]::Max(0.001, $elapsed), 1)
            $pct = [math]::Round(($Metrics.Completed / $Total) * 100)
            Write-Progress -Activity "Load Test" -Status "$($Metrics.Completed)/$Total ($pct%) - $rate req/s" -PercentComplete $pct
        }
    }
} else {
    # Use PowerShell jobs with Invoke-RestMethod for concurrent requests
    $BatchSize = [math]::Min($ConcurrentJobs, $Total)
    $RequestIndex = 0

    while ($RequestIndex -lt $Total -or (Get-Job -State Running).Count -gt 0) {
        # Start new jobs up to concurrent limit
        while ((Get-Job -State Running).Count -lt $BatchSize -and $RequestIndex -lt $Total) {
            $req = $Requests[$RequestIndex]
            $jsonPayload = $req.Payload | ConvertTo-Json -Depth 10 -Compress
            $idx = $RequestIndex

            $job = Start-Job -ScriptBlock {
                param($Url, $Json, $Index)
                $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
                try {
                    $response = Invoke-RestMethod -Uri $Url -Method POST `
                        -ContentType "application/json" `
                        -Headers @{ "X-Load-Test" = "true" } `
                        -Body $Json `
                        -TimeoutSec 60

                    $stopwatch.Stop()
                    $success = $response.success -eq $true

                    return @{
                        Index = $Index
                        Success = $success
                        HttpCode = 200
                        Duration = $stopwatch.ElapsedMilliseconds
                        Response = ($response | ConvertTo-Json -Compress)
                        Error = $null
                    }
                } catch {
                    $stopwatch.Stop()
                    $httpCode = 0
                    if ($_.Exception.Response) {
                        $httpCode = [int]$_.Exception.Response.StatusCode
                    }
                    return @{
                        Index = $Index
                        Success = $false
                        HttpCode = $httpCode
                        Duration = $stopwatch.ElapsedMilliseconds
                        Response = $null
                        Error = $_.Exception.Message
                    }
                }
            } -ArgumentList $Url, $jsonPayload, $idx

            $RequestIndex++
            Start-Sleep -Milliseconds $DelayBetweenRequests
        }

        # Check completed jobs
        $completedJobs = Get-Job -State Completed
        foreach ($job in $completedJobs) {
            $result = Receive-Job $job
            Remove-Job $job

            $Metrics.Completed++
            [void]$Metrics.ResponseTimes.Add($result.Duration)

            $code = $result.HttpCode
            if (-not $Metrics.HttpCodes.ContainsKey($code)) { $Metrics.HttpCodes[$code] = 0 }
            $Metrics.HttpCodes[$code]++

            if ($result.Success) {
                $Metrics.Successful++
            } else {
                $Metrics.Failed++
                [void]$Metrics.Errors.Add(@{
                    Index = $result.Index
                    HttpCode = $result.HttpCode
                    Error = if ($result.Error) { $result.Error } else { "HTTP Error" }
                    Response = $result.Response
                })
            }
        }

        # Update progress
        $elapsed = ((Get-Date) - $Metrics.StartTime).TotalSeconds
        $rate = [math]::Round($Metrics.Completed / [math]::Max(0.001, $elapsed), 1)
        $pct = [math]::Round(($Metrics.Completed / $Total) * 100)
        Write-Progress -Activity "Load Test" -Status "$($Metrics.Completed)/$Total ($pct%) - $rate req/s - Success: $($Metrics.Successful) Failed: $($Metrics.Failed)" -PercentComplete $pct

        Start-Sleep -Milliseconds 100
    }

    # Clean up any remaining jobs
    Get-Job | Remove-Job -Force
}

Write-Progress -Activity "Load Test" -Completed
$Metrics.EndTime = Get-Date
$Metrics.TotalDuration = ($Metrics.EndTime - $Metrics.StartTime).TotalSeconds

# Calculate statistics
$times = $Metrics.ResponseTimes | Sort-Object
if ($times.Count -gt 0) {
    $avg = [math]::Round(($times | Measure-Object -Average).Average, 1)
    $min = $times[0]
    $max = $times[-1]
    $p50 = $times[[math]::Floor($times.Count * 0.5)]
    $p95 = $times[[math]::Floor($times.Count * 0.95)]
    $p99 = $times[[math]::Floor($times.Count * 0.99)]
} else {
    $avg = $min = $max = $p50 = $p95 = $p99 = 0
}

$successRate = if ($Total -gt 0) { [math]::Round(($Metrics.Successful / $Total) * 100, 1) } else { 0 }
$actualRate = if ($Metrics.TotalDuration -gt 0) { [math]::Round($Metrics.Completed / $Metrics.TotalDuration, 2) } else { 0 }

# Display results
Write-Host "`n"
Write-Host "=" * 70 -ForegroundColor Cyan
Write-Host "                      LOAD TEST RESULTS" -ForegroundColor Cyan
Write-Host "=" * 70 -ForegroundColor Cyan
Write-Host "  Duration:           $([math]::Round($Metrics.TotalDuration, 2)) seconds"
Write-Host "  Total Requests:     $Total"
Write-Host "  Successful:         $($Metrics.Successful) ($successRate%)" -ForegroundColor $(if ($successRate -ge 99) { "Green" } elseif ($successRate -ge 95) { "Yellow" } else { "Red" })
Write-Host "  Failed:             $($Metrics.Failed)" -ForegroundColor $(if ($Metrics.Failed -eq 0) { "Green" } else { "Red" })
Write-Host "  Actual Rate:        $actualRate requests/sec"
Write-Host "-" * 70
Write-Host "  Response Times:"
Write-Host "  - Min:              $min ms"
Write-Host "  - Max:              $max ms"
Write-Host "  - Average:          $avg ms"
Write-Host "  - P50 (Median):     $p50 ms"
Write-Host "  - P95:              $p95 ms"
Write-Host "  - P99:              $p99 ms"
Write-Host "-" * 70
Write-Host "  HTTP Response Codes:"
foreach ($code in ($Metrics.HttpCodes.Keys | Sort-Object)) {
    $count = $Metrics.HttpCodes[$code]
    $pct = [math]::Round(($count / $Total) * 100, 1)
    $color = if ($code -ge 200 -and $code -lt 300) { "Green" } elseif ($code -ge 400) { "Red" } else { "Yellow" }
    Write-Host "  - HTTP ${code}:         $count ($pct%)" -ForegroundColor $color
}
Write-Host "=" * 70 -ForegroundColor Cyan

# Performance grade
$grade = "A"
$notes = @()

if ($successRate -lt 99) {
    $grade = if ($successRate -lt 95) { "F" } elseif ($successRate -lt 98) { "C" } else { "B" }
    $notes += "Error rate is concerning ($([math]::Round(100 - $successRate, 1))%)"
}

if ($p95 -gt 2000) {
    $grade = if ($p95 -gt 5000) { "D" } else { "C" }
    $notes += "P95 response time is high (${p95}ms)"
} elseif ($p95 -gt 1000) {
    $notes += "P95 response time could be improved"
}

if ($notes.Count -eq 0) {
    $notes += "Server handled the load well!"
}

Write-Host "`n  PERFORMANCE GRADE: $grade" -ForegroundColor $(if ($grade -eq "A") { "Green" } elseif ($grade -eq "B" -or $grade -eq "C") { "Yellow" } else { "Red" })
foreach ($note in $notes) {
    Write-Host "  - $note"
}
Write-Host "=" * 70 -ForegroundColor Cyan

# Show errors if any
if ($Metrics.Errors.Count -gt 0) {
    Write-Host "`n  ERRORS (first 5):" -ForegroundColor Red
    $Metrics.Errors | Select-Object -First 5 | ForEach-Object {
        Write-Host "  - Request $($_.Index): HTTP $($_.HttpCode) - $($_.Error)"
    }
}

# Save results
$ResultsFile = Join-Path $PSScriptRoot "load_test_results_$(Get-Date -Format 'yyyy-MM-dd_HHmmss').json"
@{
    config = @{
        users = $Users
        restrictions_per_user = $RestrictionsPerUser
        advisories_per_user = $AdvisoriesPerUser
        duration_seconds = $DurationSeconds
        total_requests = $Total
    }
    metrics = @{
        successful = $Metrics.Successful
        failed = $Metrics.Failed
        total_duration = $Metrics.TotalDuration
        avg_response_time = $avg
        p50 = $p50
        p95 = $p95
        p99 = $p99
        http_codes = @($Metrics.HttpCodes.GetEnumerator() | ForEach-Object { @{ code = $_.Key.ToString(); count = $_.Value } })
    }
    timestamp = (Get-Date).ToString("o")
} | ConvertTo-Json -Depth 5 | Set-Content $ResultsFile

Write-Host "`nResults saved to: $ResultsFile" -ForegroundColor Gray
