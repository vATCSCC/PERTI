# ATFM Flight Engine

Headless flight simulation engine for ATFM training on the PERTI platform.

## Overview

This Node.js service provides physics-based flight simulation for the ATFM training simulator. It calculates aircraft positions, altitudes, and speeds as they follow flight plans and respond to ATC commands.

## Location

This engine is part of the PERTI project at:
```
PERTI/simulator/engine/
```

## Quick Start

```bash
cd PERTI/simulator/engine
npm install
npm start
```

Server runs on port 3001 by default.

## API Endpoints

See full documentation in the main ATFM Simulator Design Document.

### Key Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/simulation/create` | Create new simulation |
| POST | `/simulation/:id/aircraft` | Spawn aircraft |
| POST | `/simulation/:id/tick` | Advance time |
| POST | `/simulation/:id/command` | Issue ATC command |
| GET | `/simulation/:id/aircraft` | Get all aircraft |

## Integration

The engine integrates with PERTI via:
1. **NavDataClient** - Uses PERTI's `/api/data/fixes.php` for navigation data
2. **PHP Wrapper** - Called from `api/simulator/*.php` endpoints
3. **Database** - Reference data from `sim_ref_*` tables

## License

MIT (adapted from openScope)
