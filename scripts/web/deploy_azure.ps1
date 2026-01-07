# Deploy VATUSA Event AAR/ADR Entry to Azure App Service
#
# Prerequisites:
#   1. Azure CLI installed (az)
#   2. Logged in to Azure (az login)
#
# Usage:
#   .\deploy_azure.ps1
#   .\deploy_azure.ps1 -ResourceGroup "mygroup" -AppName "vatsim-aar-entry"

param(
    [string]$ResourceGroup = "VATSIM-ADL",
    [string]$AppName = "vatsim-event-aar",
    [string]$Location = "eastus",
    [string]$PythonVersion = "3.11"
)

Write-Host "=== VATUSA Event AAR/ADR Entry - Azure Deployment ===" -ForegroundColor Cyan
Write-Host ""

# Check Azure CLI
try {
    $null = az --version
} catch {
    Write-Host "ERROR: Azure CLI not found. Install from https://docs.microsoft.com/cli/azure/install-azure-cli" -ForegroundColor Red
    exit 1
}

# Check login status
$account = az account show 2>$null | ConvertFrom-Json
if (-not $account) {
    Write-Host "Not logged in to Azure. Running 'az login'..." -ForegroundColor Yellow
    az login
    $account = az account show | ConvertFrom-Json
}

Write-Host "Logged in as: $($account.user.name)" -ForegroundColor Green
Write-Host "Subscription: $($account.name)" -ForegroundColor Green
Write-Host ""

# Create resource group if needed
Write-Host "Checking resource group '$ResourceGroup'..." -ForegroundColor Cyan
$rgExists = az group exists --name $ResourceGroup
if ($rgExists -eq "false") {
    Write-Host "Creating resource group..." -ForegroundColor Yellow
    az group create --name $ResourceGroup --location $Location
}

# Create App Service Plan if needed
$PlanName = "$AppName-plan"
Write-Host "Checking App Service Plan '$PlanName'..." -ForegroundColor Cyan
$planCheck = az appservice plan list --resource-group $ResourceGroup --query "[?name=='$PlanName']" 2>$null | ConvertFrom-Json
if (-not $planCheck -or $planCheck.Count -eq 0) {
    Write-Host "Creating App Service Plan (B1 - Basic)..." -ForegroundColor Yellow
    az appservice plan create --name $PlanName --resource-group $ResourceGroup --sku B1 --is-linux
} else {
    Write-Host "App Service Plan exists." -ForegroundColor Green
}

# Create Web App if needed
Write-Host "Checking Web App '$AppName'..." -ForegroundColor Cyan
$appCheck = az webapp list --resource-group $ResourceGroup --query "[?name=='$AppName']" 2>$null | ConvertFrom-Json
if (-not $appCheck -or $appCheck.Count -eq 0) {
    Write-Host "Creating Web App..." -ForegroundColor Yellow
    az webapp create --name $AppName --resource-group $ResourceGroup --plan $PlanName --runtime "PYTHON:$PythonVersion"
} else {
    Write-Host "Web App exists." -ForegroundColor Green
}

# Configure startup command
Write-Host "Configuring startup command..." -ForegroundColor Cyan
az webapp config set `
    --name $AppName `
    --resource-group $ResourceGroup `
    --startup-file "gunicorn --bind=0.0.0.0 --timeout 600 app:app"

# Deploy the app
Write-Host "Deploying application..." -ForegroundColor Cyan
$zipPath = "$env:TEMP\vatsim-aar-app.zip"

# Create deployment package
Push-Location $PSScriptRoot
Compress-Archive -Path "app.py", "requirements.txt", "templates", "static" -DestinationPath $zipPath -Force
Pop-Location

az webapp deployment source config-zip `
    --name $AppName `
    --resource-group $ResourceGroup `
    --src $zipPath

# Get the URL
$url = "https://$AppName.azurewebsites.net"
Write-Host ""
Write-Host "=== Deployment Complete ===" -ForegroundColor Green
Write-Host "App URL: $url" -ForegroundColor Cyan
Write-Host ""
Write-Host "To view logs: az webapp log tail --name $AppName --resource-group $ResourceGroup" -ForegroundColor Gray

# Clean up
Remove-Item $zipPath -ErrorAction SilentlyContinue

# Open in browser
Start-Process $url
