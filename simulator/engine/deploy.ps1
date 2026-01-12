# ATFM Flight Engine - Azure Deployment Script
# Run this from the PERTI\simulator\engine directory

param(
    [string]$ResourceGroup = "VATSIM_RG",
    [string]$AppName = "vatcscc-atfm-engine",
    [string]$Plan = "ASP-VATSIMRG-9bb6",
    [switch]$CreateOnly,
    [switch]$DeployOnly
)

$ErrorActionPreference = "Stop"

Write-Host "======================================" -ForegroundColor Cyan
Write-Host "ATFM Flight Engine - Azure Deployment" -ForegroundColor Cyan
Write-Host "======================================" -ForegroundColor Cyan
Write-Host ""

# Check Azure CLI
try {
    $azVersion = az version | ConvertFrom-Json
    Write-Host "Azure CLI version: $($azVersion.'azure-cli')" -ForegroundColor Green
} catch {
    Write-Host "ERROR: Azure CLI not found. Install from https://aka.ms/installazurecliwindows" -ForegroundColor Red
    exit 1
}

# Check login
$account = az account show 2>$null | ConvertFrom-Json
if (-not $account) {
    Write-Host "Not logged in. Running 'az login'..." -ForegroundColor Yellow
    az login
}
Write-Host "Logged in as: $($account.user.name)" -ForegroundColor Green
Write-Host ""

# Step 1: Create App Service (if needed)
if (-not $DeployOnly) {
    Write-Host "Step 1: Creating App Service '$AppName'..." -ForegroundColor Cyan
    
    # Check if app exists
    $existingApp = az webapp show --resource-group $ResourceGroup --name $AppName 2>$null
    
    if ($existingApp) {
        Write-Host "  App Service '$AppName' already exists" -ForegroundColor Yellow
    } else {
        Write-Host "  Creating new App Service..." -ForegroundColor White
        az webapp create `
            --resource-group $ResourceGroup `
            --plan $Plan `
            --name $AppName `
            --runtime "NODE:20-lts"
        
        Write-Host "  App Service created!" -ForegroundColor Green
    }
    
    # Configure settings
    Write-Host "  Configuring app settings..." -ForegroundColor White
    az webapp config appsettings set `
        --resource-group $ResourceGroup `
        --name $AppName `
        --settings PERTI_API_URL="https://perti.vatcscc.org/api" | Out-Null
    
    # Enable HTTPS only
    az webapp update `
        --resource-group $ResourceGroup `
        --name $AppName `
        --https-only true | Out-Null
    
    Write-Host "  Configuration complete!" -ForegroundColor Green
    Write-Host ""
}

if ($CreateOnly) {
    Write-Host "CreateOnly flag set - skipping deployment" -ForegroundColor Yellow
    exit 0
}

# Step 2: Create deployment package
Write-Host "Step 2: Creating deployment package..." -ForegroundColor Cyan

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $scriptDir) { $scriptDir = Get-Location }

$zipPath = Join-Path $scriptDir "deploy.zip"

# Remove old zip if exists
if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

# Create zip (excluding node_modules and .git)
Write-Host "  Zipping files (excluding node_modules)..." -ForegroundColor White

$filesToZip = Get-ChildItem -Path $scriptDir -Recurse | 
    Where-Object { 
        $_.FullName -notlike "*\node_modules\*" -and 
        $_.FullName -notlike "*\.git\*" -and
        $_.Name -ne "deploy.zip" -and
        $_.Name -ne "deploy.ps1"
    }

Compress-Archive -Path (Get-ChildItem -Path $scriptDir -Exclude "node_modules",".git","deploy.zip","deploy.ps1") -DestinationPath $zipPath -Force

Write-Host "  Created: $zipPath" -ForegroundColor Green
Write-Host ""

# Step 3: Deploy
Write-Host "Step 3: Deploying to Azure..." -ForegroundColor Cyan
Write-Host "  This may take 1-2 minutes..." -ForegroundColor White

az webapp deployment source config-zip `
    --resource-group $ResourceGroup `
    --name $AppName `
    --src $zipPath

Write-Host "  Deployment complete!" -ForegroundColor Green
Write-Host ""

# Cleanup
Remove-Item $zipPath -Force

# Step 4: Verify
Write-Host "Step 4: Verifying deployment..." -ForegroundColor Cyan
$healthUrl = "https://$AppName.azurewebsites.net/health"
Write-Host "  Checking: $healthUrl" -ForegroundColor White
Write-Host "  (First request may take 30+ seconds for cold start)" -ForegroundColor Yellow

Start-Sleep -Seconds 5

try {
    $response = Invoke-RestMethod -Uri $healthUrl -TimeoutSec 60
    Write-Host ""
    Write-Host "  Status: $($response.status)" -ForegroundColor Green
    Write-Host "  Service: $($response.service)" -ForegroundColor Green
    Write-Host "  Aircraft Types: $($response.aircraftTypes)" -ForegroundColor Green
} catch {
    Write-Host "  Could not verify (may still be starting). Check manually:" -ForegroundColor Yellow
    Write-Host "  $healthUrl" -ForegroundColor White
}

Write-Host ""
Write-Host "======================================" -ForegroundColor Cyan
Write-Host "Deployment Complete!" -ForegroundColor Green
Write-Host "======================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Engine URL: https://$AppName.azurewebsites.net" -ForegroundColor Cyan
Write-Host "Simulator:  https://perti.vatcscc.org/simulator.php" -ForegroundColor Cyan
Write-Host ""
