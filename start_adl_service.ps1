# VATSIM ADL Service Starter
# Runs the ADL daemon (with integrated ATIS) as a background process

param(
    [switch]$Stop,
    [switch]$Status,
    [switch]$Restart,
    [switch]$Foreground
)

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$PidFile = Join-Path $ScriptDir "scripts\vatsim_adl.pid"
$LogFile = Join-Path $ScriptDir "scripts\vatsim_adl.log"
$DaemonScript = Join-Path $ScriptDir "scripts\vatsim_adl_daemon.php"

function Get-AdlPid {
    if (Test-Path $PidFile) {
        $pid = Get-Content $PidFile -ErrorAction SilentlyContinue
        if ($pid -and (Get-Process -Id $pid -ErrorAction SilentlyContinue)) {
            return [int]$pid
        }
    }
    return $null
}

function Stop-AdlService {
    $pid = Get-AdlPid
    if ($pid) {
        Write-Host "Stopping ADL daemon (PID: $pid)..."
        Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue
        Remove-Item $PidFile -ErrorAction SilentlyContinue
        Write-Host "Stopped."
    } else {
        Write-Host "ADL daemon is not running."
    }
}

function Get-AdlStatus {
    $pid = Get-AdlPid
    if ($pid) {
        $proc = Get-Process -Id $pid -ErrorAction SilentlyContinue
        if ($proc) {
            $runtime = (Get-Date) - $proc.StartTime
            Write-Host "ADL daemon is RUNNING"
            Write-Host "  PID: $pid"
            Write-Host "  Runtime: $($runtime.ToString('hh\:mm\:ss'))"
            Write-Host "  Memory: $([math]::Round($proc.WorkingSet64/1MB, 1)) MB"

            # Show recent log entries
            if (Test-Path $LogFile) {
                Write-Host "`nRecent log entries:"
                Get-Content $LogFile -Tail 5 | ForEach-Object { Write-Host "  $_" }
            }
            return
        }
    }
    Write-Host "ADL daemon is NOT RUNNING"
}

function Start-AdlService {
    param([switch]$Foreground)

    $existingPid = Get-AdlPid
    if ($existingPid) {
        Write-Host "ADL daemon already running (PID: $existingPid)"
        Write-Host "Use -Restart to restart, or -Stop to stop."
        return
    }

    if (-not (Test-Path $DaemonScript)) {
        Write-Host "ERROR: Daemon script not found: $DaemonScript"
        return
    }

    if ($Foreground) {
        Write-Host "Starting ADL daemon in foreground (Ctrl+C to stop)..."
        & php $DaemonScript
        return
    }

    Write-Host "Starting ADL daemon..."

    # Start as background process
    $proc = Start-Process -FilePath "php" `
        -ArgumentList $DaemonScript `
        -WorkingDirectory $ScriptDir `
        -WindowStyle Hidden `
        -PassThru

    # Save PID
    $proc.Id | Out-File $PidFile -Encoding ASCII

    Start-Sleep -Seconds 2

    if (Get-Process -Id $proc.Id -ErrorAction SilentlyContinue) {
        Write-Host "ADL daemon started (PID: $($proc.Id))"
        Write-Host "Log file: $LogFile"
        Write-Host "Features: Flight data + ATIS with tiered refresh"
    } else {
        Write-Host "Failed to start ADL daemon. Check log file."
        Remove-Item $PidFile -ErrorAction SilentlyContinue
    }
}

# Main
if ($Stop) {
    Stop-AdlService
} elseif ($Status) {
    Get-AdlStatus
} elseif ($Restart) {
    Stop-AdlService
    Start-Sleep -Seconds 1
    Start-AdlService
} elseif ($Foreground) {
    Start-AdlService -Foreground
} else {
    Start-AdlService
}
