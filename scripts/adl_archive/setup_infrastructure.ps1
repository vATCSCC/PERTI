<#
.SYNOPSIS
    Sets up Azure infrastructure for ADL Raw Data Lake archive.

.DESCRIPTION
    Creates:
    - Azure Storage Account (pertiadlarchive)
    - Blob container (adl-raw-archive)
    - Lifecycle management policy (Cool at 8 days, Archive at 365 days)

.EXAMPLE
    .\setup_infrastructure.ps1

.NOTES
    Requires Azure CLI (az) installed and authenticated.
    Run: az login
#>

param(
    [string]$ResourceGroup = "VATSIM_RG",
    [string]$Location = "eastus",
    [string]$StorageAccountName = "pertiadlarchive",
    [string]$ContainerName = "adl-raw-archive",
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "ADL Raw Data Lake Infrastructure Setup" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Configuration:" -ForegroundColor Yellow
Write-Host "  Resource Group:    $ResourceGroup"
Write-Host "  Location:          $Location"
Write-Host "  Storage Account:   $StorageAccountName"
Write-Host "  Container:         $ContainerName"
Write-Host ""

if ($DryRun) {
    Write-Host "[DRY RUN] Would execute the following commands:" -ForegroundColor Yellow
    Write-Host ""
}

# Step 1: Create Storage Account
Write-Host "Step 1: Creating Storage Account..." -ForegroundColor Green

$storageCmd = @"
az storage account create `
    --name $StorageAccountName `
    --resource-group $ResourceGroup `
    --location $Location `
    --sku Standard_LRS `
    --kind StorageV2 `
    --access-tier Cool `
    --min-tls-version TLS1_2 `
    --allow-blob-public-access false `
    --https-only true
"@

if ($DryRun) {
    Write-Host $storageCmd
} else {
    # Check if storage account already exists (suppress stderr for "not found")
    $ErrorActionPreference = "SilentlyContinue"
    $existingAccount = az storage account show --name $StorageAccountName --resource-group $ResourceGroup 2>&1
    $ErrorActionPreference = "Stop"

    if ($LASTEXITCODE -eq 0) {
        Write-Host "  Storage account '$StorageAccountName' already exists. Skipping creation." -ForegroundColor Yellow
    } else {
        az storage account create `
            --name $StorageAccountName `
            --resource-group $ResourceGroup `
            --location $Location `
            --sku Standard_LRS `
            --kind StorageV2 `
            --access-tier Cool `
            --min-tls-version TLS1_2 `
            --allow-blob-public-access false `
            --https-only true

        if ($LASTEXITCODE -ne 0) {
            Write-Host "Failed to create storage account" -ForegroundColor Red
            exit 1
        }
        Write-Host "  Storage account created successfully." -ForegroundColor Green
    }
}

Write-Host ""

# Step 2: Get Storage Account Key
Write-Host "Step 2: Retrieving Storage Account Key..." -ForegroundColor Green

if (-not $DryRun) {
    $storageKey = az storage account keys list `
        --account-name $StorageAccountName `
        --resource-group $ResourceGroup `
        --query "[0].value" -o tsv

    if (-not $storageKey) {
        Write-Host "Failed to retrieve storage account key" -ForegroundColor Red
        exit 1
    }
    Write-Host "  Retrieved storage account key." -ForegroundColor Green
}

Write-Host ""

# Step 3: Create Blob Container
Write-Host "Step 3: Creating Blob Container..." -ForegroundColor Green

$containerCmd = @"
az storage container create `
    --name $ContainerName `
    --account-name $StorageAccountName `
    --account-key <key>
"@

if ($DryRun) {
    Write-Host $containerCmd
} else {
    $existingContainer = az storage container exists `
        --name $ContainerName `
        --account-name $StorageAccountName `
        --account-key $storageKey `
        --query "exists" -o tsv

    if ($existingContainer -eq "true") {
        Write-Host "  Container '$ContainerName' already exists. Skipping creation." -ForegroundColor Yellow
    } else {
        az storage container create `
            --name $ContainerName `
            --account-name $StorageAccountName `
            --account-key $storageKey

        if ($LASTEXITCODE -ne 0) {
            Write-Host "Failed to create container" -ForegroundColor Red
            exit 1
        }
        Write-Host "  Container created successfully." -ForegroundColor Green
    }
}

Write-Host ""

# Step 4: Create Lifecycle Management Policy
Write-Host "Step 4: Creating Lifecycle Management Policy..." -ForegroundColor Green

$lifecyclePolicy = @"
{
  "rules": [
    {
      "name": "adl-archive-tiering",
      "enabled": true,
      "type": "Lifecycle",
      "definition": {
        "filters": {
          "blobTypes": ["blockBlob"],
          "prefixMatch": [
            "trajectory/",
            "changelog/",
            "flights/",
            "waypoints/",
            "boundary_log/",
            "zone_events/",
            "tmi_trajectory/"
          ]
        },
        "actions": {
          "baseBlob": {
            "tierToCool": {
              "daysAfterModificationGreaterThan": 8
            },
            "tierToArchive": {
              "daysAfterModificationGreaterThan": 365
            }
          }
        }
      }
    }
  ]
}
"@

$policyPath = Join-Path $PSScriptRoot "lifecycle_policy.json"

if ($DryRun) {
    Write-Host "Would create lifecycle policy:" -ForegroundColor Yellow
    Write-Host $lifecyclePolicy
} else {
    # Write policy to temp file
    $lifecyclePolicy | Out-File -FilePath $policyPath -Encoding utf8

    az storage account management-policy create `
        --account-name $StorageAccountName `
        --resource-group $ResourceGroup `
        --policy $policyPath

    if ($LASTEXITCODE -ne 0) {
        Write-Host "Failed to create lifecycle policy" -ForegroundColor Red
        Remove-Item $policyPath -ErrorAction SilentlyContinue
        exit 1
    }

    # Cleanup temp file
    Remove-Item $policyPath -ErrorAction SilentlyContinue
    Write-Host "  Lifecycle policy created successfully." -ForegroundColor Green
}

Write-Host ""

# Step 5: Get Connection String
Write-Host "Step 5: Retrieving Connection String..." -ForegroundColor Green

if (-not $DryRun) {
    $connectionString = az storage account show-connection-string `
        --name $StorageAccountName `
        --resource-group $ResourceGroup `
        --query "connectionString" -o tsv

    Write-Host ""
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host "Setup Complete!" -ForegroundColor Cyan
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Storage Account: $StorageAccountName" -ForegroundColor Yellow
    Write-Host "Container:       $ContainerName" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Connection String:" -ForegroundColor Yellow
    Write-Host $connectionString
    Write-Host ""
    Write-Host "Add to .env or Azure Function settings as:" -ForegroundColor Yellow
    Write-Host "  ADL_ARCHIVE_STORAGE_CONN=$connectionString"
    Write-Host ""
    Write-Host "Lifecycle Policy:" -ForegroundColor Yellow
    Write-Host "  - Blobs move to COOL tier after 8 days"
    Write-Host "  - Blobs move to ARCHIVE tier after 365 days"
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Green
    Write-Host "  1. Run backfill script: python backfill_trajectory.py --days 30"
    Write-Host "  2. Verify data: python query_archive.py --test"
    Write-Host "  3. Deploy Azure Function for daily archival"
} else {
    Write-Host ""
    Write-Host "[DRY RUN] Complete. No resources were created." -ForegroundColor Yellow
}
