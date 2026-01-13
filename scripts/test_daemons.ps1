# test_daemons.ps1
# Tests daemon connectivity and functionality on Windows

param(
    [switch]$TestAdl,
    [switch]$TestParse,
    [switch]$TestBoundary,
    [switch]$TestAtis,
    [switch]$All
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$WwwRoot = Split-Path -Parent $ScriptDir

Write-Host "======================================" -ForegroundColor Cyan
Write-Host " PERTI Daemon Test Suite" -ForegroundColor Cyan
Write-Host "======================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Script Dir: $ScriptDir"
Write-Host "WWW Root:   $WwwRoot"
Write-Host ""

# Test 1: Check PHP is available
Write-Host "[1] Checking PHP availability..." -ForegroundColor Yellow
try {
    $phpVersion = php -v 2>&1 | Select-Object -First 1
    Write-Host "    PHP: $phpVersion" -ForegroundColor Green
} catch {
    Write-Host "    ERROR: PHP not found in PATH" -ForegroundColor Red
    exit 1
}

# Test 2: Check sqlsrv extension
Write-Host "[2] Checking PHP sqlsrv extension..." -ForegroundColor Yellow
$sqlsrvCheck = php -r "echo extension_loaded('sqlsrv') ? 'loaded' : 'not loaded';" 2>&1
if ($sqlsrvCheck -eq "loaded") {
    Write-Host "    sqlsrv extension: LOADED" -ForegroundColor Green
} else {
    Write-Host "    sqlsrv extension: NOT LOADED" -ForegroundColor Red
    Write-Host "    Download from: https://docs.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server" -ForegroundColor Yellow
}

# Test 3: Check Python is available
Write-Host "[3] Checking Python availability..." -ForegroundColor Yellow
try {
    $pythonVersion = python --version 2>&1
    Write-Host "    Python: $pythonVersion" -ForegroundColor Green
} catch {
    Write-Host "    ERROR: Python not found in PATH" -ForegroundColor Red
}

# Test 4: Check Python dependencies
Write-Host "[4] Checking Python dependencies..." -ForegroundColor Yellow
$reqFile = Join-Path $ScriptDir "vatsim_atis\requirements.txt"
if (Test-Path $reqFile) {
    $missing = @()
    foreach ($line in Get-Content $reqFile) {
        if ($line -match "^([a-zA-Z0-9_-]+)") {
            $pkg = $Matches[1]
            $check = python -c "import $pkg" 2>&1
            if ($LASTEXITCODE -ne 0) {
                $missing += $pkg
            }
        }
    }
    if ($missing.Count -eq 0) {
        Write-Host "    All Python dependencies installed" -ForegroundColor Green
    } else {
        Write-Host "    Missing packages: $($missing -join ', ')" -ForegroundColor Red
        Write-Host "    Run: pip install -r $reqFile" -ForegroundColor Yellow
    }
}

# Test 5: Check daemon files exist
Write-Host "[5] Checking daemon files..." -ForegroundColor Yellow
$files = @(
    "scripts\vatsim_adl_daemon.php",
    "adl\php\parse_queue_daemon.php",
    "adl\php\boundary_daemon.php",
    "scripts\vatsim_atis\atis_daemon.py"
)
foreach ($file in $files) {
    $fullPath = Join-Path $WwwRoot $file
    if (Test-Path $fullPath) {
        Write-Host "    OK: $file" -ForegroundColor Green
    } else {
        Write-Host "    MISSING: $file" -ForegroundColor Red
    }
}

# Test 6: Test config loading
Write-Host "[6] Testing config loading..." -ForegroundColor Yellow
$configCheck = php -r "
require_once '$WwwRoot/load/config.php';
echo defined('ADL_SQL_HOST') ? 'ADL config: OK' : 'ADL config: MISSING';
" 2>&1
Write-Host "    $configCheck" -ForegroundColor Green

Write-Host ""
Write-Host "======================================" -ForegroundColor Cyan

# Run individual daemon tests if requested
if ($TestAdl -or $All) {
    Write-Host ""
    Write-Host "[TEST] Running vatsim_adl_daemon.php (single cycle)..." -ForegroundColor Magenta
    $adlPath = Join-Path $WwwRoot "scripts\vatsim_adl_daemon.php"
    php $adlPath 2>&1
}

if ($TestParse -or $All) {
    Write-Host ""
    Write-Host "[TEST] Running parse_queue_daemon.php (single cycle)..." -ForegroundColor Magenta
    $parsePath = Join-Path $WwwRoot "adl\php\parse_queue_daemon.php"
    php $parsePath 2>&1
}

if ($TestBoundary -or $All) {
    Write-Host ""
    Write-Host "[TEST] Running boundary_daemon.php (single cycle)..." -ForegroundColor Magenta
    $boundaryPath = Join-Path $WwwRoot "adl\php\boundary_daemon.php"
    php $boundaryPath 2>&1
}

if ($TestAtis -or $All) {
    Write-Host ""
    Write-Host "[TEST] Running atis_daemon.py (single cycle)..." -ForegroundColor Magenta
    Push-Location $ScriptDir
    python -m vatsim_atis.atis_daemon --once 2>&1
    Pop-Location
}

Write-Host ""
Write-Host "Done!" -ForegroundColor Green
