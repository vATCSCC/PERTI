# ============================================================================
# setup_task_scheduler.ps1 - Create Wind Fetch Scheduled Task
#
# Creates a Windows Task Scheduler task to run the wind fetcher every 6 hours.
# Run this script as Administrator.
#
# Usage:
#   1. Edit run_wind_fetch.bat to set WIND_DB_PASSWORD
#   2. Run PowerShell as Administrator
#   3. Execute: .\setup_task_scheduler.ps1
# ============================================================================

$ErrorActionPreference = "Stop"

# Configuration
$TaskName = "VATSIM_PERTI_WindFetch"
$TaskDescription = "Fetches NOAA GFS wind data every 6 hours for VATSIM PERTI ETA calculations"
$ScriptPath = Join-Path $PSScriptRoot "run_wind_fetch.bat"

Write-Host "=============================================="
Write-Host "  VATSIM PERTI - Wind Fetch Task Setup"
Write-Host "=============================================="
Write-Host ""

# Verify script exists
if (-not (Test-Path $ScriptPath)) {
    Write-Error "run_wind_fetch.bat not found at: $ScriptPath"
    exit 1
}

Write-Host "Script path: $ScriptPath"
Write-Host ""

# Check for existing task
$existingTask = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existingTask) {
    Write-Host "Existing task found. Removing..."
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

# Create the action (run the batch file)
$Action = New-ScheduledTaskAction -Execute $ScriptPath -WorkingDirectory $PSScriptRoot

# Create trigger: Run every 6 hours starting at 02:00 UTC
# Convert to local time for the trigger (Windows handles this)
$Trigger = New-ScheduledTaskTrigger -Daily -At "02:00"
$Trigger.Repetition = (New-ScheduledTaskTrigger -Once -At "02:00" -RepetitionInterval (New-TimeSpan -Hours 6)).Repetition

# Create settings
$Settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 5) `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1)

# Create principal (run as SYSTEM for reliability, or current user)
Write-Host ""
Write-Host "Select run mode:"
Write-Host "  1. Run as SYSTEM (recommended - runs even when logged off)"
Write-Host "  2. Run as current user"
$choice = Read-Host "Enter choice (1 or 2)"

if ($choice -eq "1") {
    $Principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
    Write-Host "Task will run as SYSTEM account"
} else {
    $Principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType S4U -RunLevel Highest
    Write-Host "Task will run as $env:USERNAME"
}

# Register the task
Write-Host ""
Write-Host "Creating scheduled task..."

$Task = Register-ScheduledTask `
    -TaskName $TaskName `
    -Description $TaskDescription `
    -Action $Action `
    -Trigger $Trigger `
    -Settings $Settings `
    -Principal $Principal `
    -Force

Write-Host ""
Write-Host "=============================================="
Write-Host "  Task created successfully!"
Write-Host "=============================================="
Write-Host ""
Write-Host "Task Name: $TaskName"
Write-Host "Schedule:  Every 6 hours (02:00, 08:00, 14:00, 20:00 local)"
Write-Host ""
Write-Host "IMPORTANT: Before the task runs, edit run_wind_fetch.bat and set:"
Write-Host "  set WIND_DB_PASSWORD=your_actual_password"
Write-Host ""
Write-Host "To run the task manually:"
Write-Host "  Start-ScheduledTask -TaskName '$TaskName'"
Write-Host ""
Write-Host "To view task status:"
Write-Host "  Get-ScheduledTask -TaskName '$TaskName' | Get-ScheduledTaskInfo"
Write-Host ""
Write-Host "To remove the task:"
Write-Host "  Unregister-ScheduledTask -TaskName '$TaskName' -Confirm:`$false"
Write-Host ""
