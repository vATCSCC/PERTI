# ATFM Flight Engine - Azure Deployment Guide

## Overview

Deploy the Node.js flight engine to Azure App Service alongside the existing PHP PERTI site.

**Architecture:**
```
┌─────────────────────────────────────┐
│  perti.vatcscc.org (PHP App)        │
│  ├── simulator.php                  │
│  └── api/simulator/*.php            │
└──────────────┬──────────────────────┘
               │ HTTPS API calls
               ▼
┌─────────────────────────────────────┐
│  vatcscc-atfm-engine.azurewebsites.net  │
│  (Node.js App Service)              │
│  └── Flight simulation engine       │
└─────────────────────────────────────┘
```

---

## Quick Deploy (Azure CLI)

### Prerequisites
- Azure CLI installed (`az --version`)
- Logged in (`az login`)

### Step 1: Create the Node.js App Service

```bash
# Variables
RESOURCE_GROUP="VATSIM_RG"
APP_NAME="vatcscc-atfm-engine"
PLAN_NAME="ASP-VATSIMRG-9bb6"  # Use existing plan
LOCATION="centralus"

# Create the App Service (using existing plan)
az webapp create \
    --resource-group $RESOURCE_GROUP \
    --plan $PLAN_NAME \
    --name $APP_NAME \
    --runtime "NODE:20-lts"

# Configure settings
az webapp config appsettings set \
    --resource-group $RESOURCE_GROUP \
    --name $APP_NAME \
    --settings \
        PERTI_API_URL="https://perti.vatcscc.org/api" \
        WEBSITES_PORT=3001

# Enable HTTPS only
az webapp update \
    --resource-group $RESOURCE_GROUP \
    --name $APP_NAME \
    --https-only true
```

### Step 2: Deploy the Code

**Option A: Deploy from local folder (ZIP deploy)**

```bash
# Navigate to the engine directory
cd PERTI/simulator/engine

# Create deployment package
zip -r deploy.zip . -x "node_modules/*" -x ".git/*"

# Deploy
az webapp deployment source config-zip \
    --resource-group VATSIM_RG \
    --name vatcscc-atfm-engine \
    --src deploy.zip

# Clean up
rm deploy.zip
```

**Option B: Deploy via Git (if using GitHub)**

```bash
# Configure deployment from GitHub
az webapp deployment source config \
    --resource-group VATSIM_RG \
    --name vatcscc-atfm-engine \
    --repo-url https://github.com/YOUR_ORG/atfm-engine \
    --branch main \
    --manual-integration
```

### Step 3: Verify Deployment

```bash
# Check health endpoint
curl https://vatcscc-atfm-engine.azurewebsites.net/health

# Expected response:
# {"status":"ok","service":"atfm-flight-engine","version":"0.1.0","simulations":0,"aircraftTypes":21}
```

---

## Manual Deployment (Azure Portal)

### 1. Create App Service

1. Go to [Azure Portal](https://portal.azure.com)
2. Navigate to **VATSIM_RG** resource group
3. Click **+ Create** → **Web App**
4. Configure:
   - **Name:** `vatcscc-atfm-engine`
   - **Publish:** Code
   - **Runtime stack:** Node 20 LTS
   - **Operating System:** Linux
   - **Region:** Central US
   - **App Service Plan:** ASP-VATSIMRG-9bb6 (existing)
5. Click **Review + create** → **Create**

### 2. Configure App Settings

1. Open the new App Service
2. Go to **Settings** → **Configuration**
3. Add Application Settings:
   - `PERTI_API_URL` = `https://perti.vatcscc.org/api`
4. Under **General settings**:
   - Startup Command: `npm start`
5. Click **Save**

### 3. Deploy Code

**Via FTP:**
1. Go to **Deployment Center** → **FTPS credentials**
2. Use FileZilla or similar to upload `simulator/engine/*` to `/site/wwwroot/`

**Via VS Code:**
1. Install Azure App Service extension
2. Right-click the App Service → **Deploy to Web App**
3. Select the `simulator/engine` folder

**Via ZIP Deploy:**
1. Create a ZIP of the `simulator/engine` folder (excluding node_modules)
2. Go to **Deployment Center** → **ZIP Deploy** (or use Kudu)
3. Upload the ZIP file

---

## Files to Deploy

Only deploy the `simulator/engine` directory:

```
simulator/engine/
├── package.json          ← Required
├── config/
│   └── aircraftTypes.json
└── src/
    ├── index.js          ← Entry point
    ├── SimulationController.js
    ├── aircraft/
    │   └── AircraftModel.js
    ├── constants/
    │   └── flightConstants.js
    ├── math/
    │   └── flightMath.js
    └── navigation/
        └── NavDataClient.js
```

**Do NOT deploy:**
- `node_modules/` (Azure will run `npm install`)
- `.git/`
- Any test files

---

## Post-Deployment Checklist

- [ ] Health endpoint responds: `https://vatcscc-atfm-engine.azurewebsites.net/health`
- [ ] CORS allows `perti.vatcscc.org`
- [ ] Simulator page loads: `https://perti.vatcscc.org/simulator.php`
- [ ] Can create simulation
- [ ] Can spawn aircraft
- [ ] Aircraft positions update on map

---

## Troubleshooting

### "Application Error" on startup
- Check logs: `az webapp log tail --resource-group VATSIM_RG --name vatcscc-atfm-engine`
- Verify `package.json` has `"start": "node src/index.js"`
- Ensure Node 20 runtime is selected

### CORS errors in browser
- Verify origin is in allowed list in `src/index.js`
- Check browser console for actual origin being sent

### Engine not connecting from simulator
- Verify `ENGINE_URL` in `simulator.php` matches deployed URL
- Check HTTPS (not HTTP) is used
- Test health endpoint directly in browser

### Cold start delays
- First request after idle may take 10-30 seconds
- Consider "Always On" setting (already enabled on Premium V2)

---

## Cost Considerations

Using the existing `ASP-VATSIMRG-9bb6` (Premium V2) plan means:
- **No additional hosting cost** - shares the existing plan
- Same resources (CPU/memory) shared with PHP app
- If heavy load expected, consider separate plan

---

## Rollback

To remove the engine:

```bash
az webapp delete \
    --resource-group VATSIM_RG \
    --name vatcscc-atfm-engine
```

To revert simulator to localhost:
- Edit `simulator.php`
- Change `ENGINE_URL` back to `'http://localhost:3001'`
