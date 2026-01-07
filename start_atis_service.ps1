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
        $procId = Get-Content $PidFile -ErrorAction SilentlyContinue
        if ($procId -and (Get-Process -Id $procId -ErrorAction SilentlyContinue)) {
            return [int]$procId
        }
    }
    return $null
}

function Stop-AtisService {
    $procId = Get-AtisPid
    if ($procId) {
        Write-Host "Stopping ATIS daemon (PID: $procId)..."
        Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue
        Remove-Item $PidFile -ErrorAction SilentlyContinue
        Write-Host "Stopped."
    } else {
        Write-Host "ATIS daemon is not running."
    }
}

function Get-AtisStatus {
    $procId = Get-AtisPid
    if ($procId) {
        $proc = Get-Process -Id $procId -ErrorAction SilentlyContinue
        if ($proc) {
            $runtime = (Get-Date) - $proc.StartTime
            Write-Host "ATIS daemon is RUNNING"
            Write-Host "  PID: $procId"
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
        -RedirectStandardOutput $LogFile

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
