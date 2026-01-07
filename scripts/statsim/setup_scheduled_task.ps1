# VATUSA Event Statistics - Scheduled Task Setup
# Run this script as Administrator to create a daily scheduled task
#
# Usage:
#   .\setup_scheduled_task.ps1           # Create task running at 02:30 UTC
#   .\setup_scheduled_task.ps1 -Remove   # Remove the task
#   .\setup_scheduled_task.ps1 -RunNow   # Run the task immediately

param(
    [switch]$Remove,
    [switch]$RunNow,
    [string]$Time = "02:30"  # UTC time
)

$TaskName = "VATUSA Event Stats Update"
$ScriptPath = Join-Path $PSScriptRoot "daily_event_update.py"
$PythonPath = (Get-Command python -ErrorAction SilentlyContinue).Source

if (-not $PythonPath) {
    $PythonPath = "python"
}

if ($Remove) {
    Write-Host "Removing scheduled task: $TaskName" -ForegroundColor Yellow
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "Task removed." -ForegroundColor Green
    exit 0
}

if ($RunNow) {
    Write-Host "Running update now..." -ForegroundColor Cyan
    Push-Location $PSScriptRoot
    & $PythonPath $ScriptPath --days-back 3
    Pop-Location
    exit $LASTEXITCODE
}

# Create the scheduled task
Write-Host "Creating scheduled task: $TaskName" -ForegroundColor Cyan
Write-Host "  Script: $ScriptPath"
Write-Host "  Time: $Time UTC daily"
Write-Host ""

# Calculate local time from UTC
$utcTime = [DateTime]::ParseExact($Time, "HH:mm", $null)
$localTime = $utcTime.AddHours([TimeZoneInfo]::Local.BaseUtcOffset.TotalHours)
Write-Host "  Local time: $($localTime.ToString('HH:mm'))"

# Create action
$Action = New-ScheduledTaskAction -Execute $PythonPath -Argument "`"$ScriptPath`" --days-back 3" -WorkingDirectory $PSScriptRoot

# Create trigger (daily at specified time)
$Trigger = New-ScheduledTaskTrigger -Daily -At $localTime

# Create settings
$Settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1)

# Register the task
try {
    $existingTask = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if ($existingTask) {
        Write-Host "Updating existing task..." -ForegroundColor Yellow
        Set-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings | Out-Null
    } else {
        Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings -Description "Daily import of VATUSA event statistics from Statsim.net" | Out-Null
    }
    Write-Host ""
    Write-Host "Task created successfully!" -ForegroundColor Green
    Write-Host ""
    Write-Host "To manage the task:"
    Write-Host "  View:   Get-ScheduledTask -TaskName '$TaskName'"
    Write-Host "  Run:    Start-ScheduledTask -TaskName '$TaskName'"
    Write-Host "  Remove: Unregister-ScheduledTask -TaskName '$TaskName'"
    Write-Host ""
    Write-Host "Or use this script:"
    Write-Host "  .\setup_scheduled_task.ps1 -RunNow   # Run immediately"
    Write-Host "  .\setup_scheduled_task.ps1 -Remove   # Remove task"
} catch {
    Write-Host "Failed to create task: $_" -ForegroundColor Red
    Write-Host ""
    Write-Host "Try running PowerShell as Administrator." -ForegroundColor Yellow
    exit 1
}
