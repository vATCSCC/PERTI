# ATFM Flight Engine

Node.js simulation engine for the VATSIM PERTI ATFM Training Simulator.

## Quick Start

### Local Development

```bash
cd PERTI/simulator/engine
npm install
npm start
```

Engine runs at `http://localhost:3001`

### Azure Deployment

```powershell
cd PERTI\simulator\engine
.\deploy.ps1
```

Or see [AZURE_DEPLOY.md](AZURE_DEPLOY.md) for manual steps.

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/health` | Health check |
| POST | `/simulation/create` | Create simulation |
| GET | `/simulation` | List simulations |
| GET | `/simulation/:id` | Get simulation status |
| POST | `/simulation/:id/aircraft` | Spawn aircraft |
| GET | `/simulation/:id/aircraft` | Get all aircraft |
| POST | `/simulation/:id/tick` | Advance time |
| POST | `/simulation/:id/command` | Issue ATC command |
| POST | `/simulation/:id/pause` | Pause simulation |
| POST | `/simulation/:id/resume` | Resume simulation |
| DELETE | `/simulation/:id` | Delete simulation |

## Aircraft Commands

| Command | Params | Description |
|---------|--------|-------------|
| `FH` / `FLY_HEADING` | `{heading}` | Fly heading |
| `TL` / `TURN_LEFT` | `{heading}` | Turn left to heading |
| `TR` / `TURN_RIGHT` | `{heading}` | Turn right to heading |
| `CM` / `CLIMB` | `{altitude}` | Climb and maintain |
| `DM` / `DESCEND` | `{altitude}` | Descend and maintain |
| `SP` / `SPEED` | `{speed}` | Maintain speed |
| `D` / `DIRECT` | `{fix}` | Proceed direct to fix |
| `RESUME` | - | Resume FMS navigation |

## Architecture

```
src/
├── index.js                 # Express HTTP server
├── SimulationController.js  # Multi-simulation management
├── aircraft/
│   └── AircraftModel.js     # Flight physics
├── constants/
│   └── flightConstants.js   # Aviation constants
├── math/
│   └── flightMath.js        # Navigation math
└── navigation/
    └── NavDataClient.js     # PERTI nav data API client
```

## Configuration

| Env Variable | Default | Description |
|--------------|---------|-------------|
| `PORT` | `3001` | HTTP server port |
| `PERTI_API_URL` | `https://perti.vatcscc.org/api` | PERTI API for nav data |

## Aircraft Types

21 aircraft types loaded from `config/aircraftTypes.json`:

B738, A320, A321, B739, B752, B763, B772, B77W, B788, B789,
A319, A20N, A21N, E170, E175, E190, CRJ2, CRJ7, CRJ9, MD80, MD11
