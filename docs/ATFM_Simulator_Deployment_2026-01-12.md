# ATFM Simulator Deployment Transition Summary
**Date:** 2026-01-12
**Session Focus:** Azure Deployment of Node.js Flight Engine

---

## Session Accomplishments

### 1. Azure Deployment - COMPLETE ✅

Successfully deployed the Node.js flight engine to Azure App Service:

| Component | URL |
|-----------|-----|
| **Flight Engine API** | https://vatcscc-atfm-engine.azurewebsites.net |
| **Health Check** | https://vatcscc-atfm-engine.azurewebsites.net/health |
| **Simulator UI** | https://perti.vatcscc.org/simulator.php |

**Azure Resources Created:**
- App Service: `vatcscc-atfm-engine` (Linux, Node 20 LTS)
- Resource Group: `VATSIM_RG`
- App Service Plan: `ASP-VATSIMRG-9bb6` (shared with PHP site, no extra cost)

### 2. Code Changes Made

**simulator.php:**
- Added `glyphs` URL to MapLibre style config (fixed text label error)
- Changed `ENGINE_URL` from `localhost:3001` to `https://vatcscc-atfm-engine.azurewebsites.net`
- Converted `apiCall()` to make direct calls to Node.js API (bypassing PHP proxy)
- Added engine health check on page load with user-friendly messages

**simulator/engine/src/index.js:**
- Updated CORS to allow `perti.vatcscc.org` and `vatcscc.azurewebsites.net`

**New Files Created:**
- `simulator/engine/AZURE_DEPLOY.md` - Deployment documentation
- `simulator/engine/deploy.ps1` - PowerShell deployment script (for future use)
- `simulator/engine/README.md` - Engine documentation

---

## Current System Architecture

```
┌─────────────────────────────────────────────┐
│  Browser                                     │
│  └── simulator.php (MapLibre + JS)          │
└──────────────┬──────────────────────────────┘
               │ Direct HTTPS API calls
               ▼
┌─────────────────────────────────────────────┐
│  vatcscc-atfm-engine.azurewebsites.net      │
│  (Azure App Service - Node.js 20 LTS)       │
│  ├── Express HTTP server (port 80)          │
│  ├── SimulationController (multi-sim)       │
│  └── AircraftModel (flight physics)         │
└──────────────┬──────────────────────────────┘
               │ Nav data lookups
               ▼
┌─────────────────────────────────────────────┐
│  perti.vatcscc.org/api/simulator/navdata.php│
│  └── Azure SQL nav_fixes table              │
└─────────────────────────────────────────────┘
```

---

## Verified Working Features

- [x] Engine health endpoint responds
- [x] Create simulation
- [x] Spawn aircraft with origin/destination
- [x] Aircraft appears on map at origin
- [x] Start simulation - aircraft moves
- [x] Speed multiplier changes tick rate
- [x] Pause/resume functionality
- [x] CORS working between perti.vatcscc.org and engine

---

## File Locations

**PERTI Directory:**
```
PERTI/
├── simulator.php                    # Main UI page
├── api/simulator/
│   ├── engine.php                   # PHP proxy (not currently used)
│   └── navdata.php                  # Nav data API for engine
└── simulator/engine/
    ├── package.json
    ├── README.md
    ├── AZURE_DEPLOY.md
    ├── deploy.ps1
    ├── config/
    │   └── aircraftTypes.json       # 21 aircraft performance profiles
    └── src/
        ├── index.js                 # Express server
        ├── SimulationController.js  # Multi-simulation management
        ├── aircraft/
        │   └── AircraftModel.js     # Flight physics
        ├── constants/
        │   └── flightConstants.js
        ├── math/
        │   └── flightMath.js        # Great circle, wind, TAS/IAS
        └── navigation/
            └── NavDataClient.js     # PERTI nav data integration
```

**Azure SQL (VATSIM_ADL):**
- `sim_ref_carrier_lookup` (17 rows)
- `sim_ref_route_patterns` (3,989 rows)
- `sim_ref_airport_demand` (107 rows)
- `nav_fixes` (~200K waypoints)

---

## Redeployment Instructions

If engine code changes, redeploy with:

```powershell
cd PERTI\simulator\engine
az webapp up --resource-group VATSIM_RG --name vatcscc-atfm-engine --runtime "NODE:20-lts"

# Then SSH in to run npm install if dependencies changed
az webapp ssh --resource-group VATSIM_RG --name vatcscc-atfm-engine
# In SSH: cd /home/site/wwwroot && npm install && exit

az webapp restart --resource-group VATSIM_RG --name vatcscc-atfm-engine
```

---

## Next Development Options

| Priority | Task | Description |
|----------|------|-------------|
| **A** | ATC Commands UI | Add panel to issue heading/altitude/speed commands |
| **B** | Route Visualization | Draw flight path on map, show waypoints |
| **C** | Scenario System | Pre-built training scenarios |
| **D** | Phase 1: GS/GDP | Ground Stop and Ground Delay Program implementation |
| **E** | Multi-aircraft spawning | Spawn traffic patterns from BTS data |

---

## API Quick Reference

**Engine Endpoints (vatcscc-atfm-engine.azurewebsites.net):**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/health` | Health check |
| POST | `/simulation/create` | Create simulation |
| GET | `/simulation` | List simulations |
| GET | `/simulation/:id` | Get simulation status |
| POST | `/simulation/:id/aircraft` | Spawn aircraft |
| GET | `/simulation/:id/aircraft` | Get all aircraft |
| POST | `/simulation/:id/tick` | Advance simulation time |
| POST | `/simulation/:id/command` | Issue ATC command |
| POST | `/simulation/:id/pause` | Pause simulation |
| POST | `/simulation/:id/resume` | Resume simulation |
| DELETE | `/simulation/:id` | Delete simulation |

**Aircraft Commands:**
- `FH {heading}` - Fly heading
- `CM {altitude}` - Climb and maintain
- `DM {altitude}` - Descend and maintain  
- `SP {speed}` - Speed
- `D {fix}` - Direct to fix
- `RESUME` - Resume FMS navigation

---

## Known Issues / Future Improvements

1. **Cold start delay** - First request after idle may take 10-30 seconds
2. **No persistence** - Simulations lost on engine restart
3. **No authentication** - Engine API is publicly accessible
4. **NavData fallback** - Uses hardcoded airport coordinates if API fails

---

## Session Context for Next Chat

**What was accomplished:**
- Migrated flight engine from local development to Azure production
- Fixed MapLibre glyphs configuration error
- Updated simulator to call Azure-hosted engine directly
- Verified end-to-end functionality

**Where we left off:**
- Simulator is fully operational at https://perti.vatcscc.org/simulator.php
- Ready to add features (ATC commands UI, route visualization, or GS/GDP)

**Recommended next step:**
- Add ATC Commands UI panel to make simulator more interactive
