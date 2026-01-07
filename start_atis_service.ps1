# VATSIM ATIS Service Starter
# Runs the ATIS daemon as a background process

param(
    [switch]$Stop,
    [switch]$Status,
    [switch]$Restart
)

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$PidFile = Join-Path $ScriptDir "scripts\vatsim_atis.pid"
$LogFile = Join-Path $ScriptDir "scripts\vatsim_atis.log"

function Get-AtisPid {
    if (Test-Path $PidFile) {
        $pid = Get-Content $PidFile -ErrorAction SilentlyContinue
        if ($pid -and (Get-Process -Id $pid -ErrorAction SilentlyContinue)) {
            return [int]$pid
        }
    }
    return $null
}

function Stop-AtisService {
    $pid = Get-AtisPid
    if ($pid) {
        Write-Host "Stopping ATIS daemon (PID: $pid)..."
        Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue
        Remove-Item $PidFile -ErrorAction SilentlyContinue
        Write-Host "Stopped."
    } else {
        Write-Host "ATIS daemon is not running."
    }
}

function Get-AtisStatus {
    $pid = Get-AtisPid
    if ($pid) {
        $proc = Get-Process -Id $pid -ErrorAction SilentlyContinue
        if ($proc) {
            $runtime = (Get-Date) - $proc.StartTime
            Write-Host "ATIS daemon is RUNNING"
            Write-Host "  PID: $pid"
            Write-Host "  Runtime: $($runtime.ToString('hh\:mm\:ss'))"
            Write-Host "  Memory: $([math]::Round($proc.WorkingSet64/1MB, 1)) MB"
            return
        }
    }
    Write-Host "ATIS daemon is NOT RUNNING"
}

function Start-AtisService {
    $existingPid = Get-AtisPid
    if ($existingPid) {
        Write-Host "ATIS daemon already running (PID: $existingPid)"
        Write-Host "Use -Restart to restart, or -Stop to stop."
        return
    }

    Write-Host "Starting ATIS daemon..."

    $python = "python"
    $scriptPath = Join-Path $ScriptDir "scripts"

    # Start as background job
    $proc = Start-Process -FilePath $python `
        -ArgumentList "-m", "vatsim_atis.atis_daemon" `
        -WorkingDirectory $scriptPath `
        -WindowStyle Hidden `
        -PassThru `
        -RedirectStandardOutput $LogFile `
        -RedirectStandardError $LogFile

    # Save PID
    $proc.Id | Out-File $PidFile -Encoding ASCII

    Start-Sleep -Seconds 2

    if (Get-Process -Id $proc.Id -ErrorAction SilentlyContinue) {
        Write-Host "ATIS daemon started (PID: $($proc.Id))"
        Write-Host "Log file: $LogFile"
    } else {
        Write-Host "Failed to start ATIS daemon. Check log file."
        Remove-Item $PidFile -ErrorAction SilentlyContinue
    }
}

# Main
if ($Stop) {
    Stop-AtisService
} elseif ($Status) {
    Get-AtisStatus
} elseif ($Restart) {
    Stop-AtisService
    Start-Sleep -Seconds 1
    Start-AtisService
} else {
    Start-AtisService
}
