# Simulator API

PHP proxy to the Node.js flight engine for ATFM training simulations.

---

## Overview

The Simulator API provides a unified interface to control flight simulations for ATFM training. It proxies requests to a Node.js engine that manages aircraft state, movement, and ATC commands.

**Base URL**: `/api/simulator/engine.php`

**Method**: GET or POST (with JSON body)

**Engine URL**: Configured via `ATFM_ENGINE_URL` environment variable (default: `http://localhost:3001`)

---

## Quick Reference

| Action | Description |
|--------|-------------|
| `health` | Check engine health status |
| `create` | Create new simulation |
| `list` | List all simulations |
| `status` | Get simulation status |
| `spawn` | Spawn new aircraft |
| `aircraft` | Get aircraft (single or all) |
| `tick` | Advance simulation by time delta |
| `run` | Run simulation for duration |
| `command` | Issue single ATC command |
| `commands` | Issue batch ATC commands |
| `pause` | Pause simulation |
| `resume` | Resume simulation |
| `delete` | Delete simulation |
| `remove_aircraft` | Remove aircraft from simulation |

---

## Simulation Management

### Health Check

Check if the flight engine is running.

```
GET /api/simulator/engine.php?action=health
```

**Response**

```json
{
  "status": "ok",
  "version": "1.0.0",
  "uptime": 3600
}
```

---

### Create Simulation

Create a new simulation instance.

```
POST /api/simulator/engine.php
Content-Type: application/json

{
  "action": "create",
  "name": "Training Session",
  "startTime": "2026-02-01T14:00:00Z"
}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `name` | string | No | "Training Session" | Simulation name |
| `startTime` | ISO 8601 | No | Current time | Simulation start time |

**Response**

```json
{
  "success": true,
  "simId": "sim_abc123",
  "name": "Training Session",
  "startTime": "2026-02-01T14:00:00Z",
  "status": "created"
}
```

---

### List Simulations

List all active simulations.

```
GET /api/simulator/engine.php?action=list
```

**Response**

```json
{
  "success": true,
  "simulations": [
    {
      "simId": "sim_abc123",
      "name": "Training Session",
      "status": "running",
      "aircraftCount": 12,
      "currentTime": "2026-02-01T14:30:00Z"
    }
  ]
}
```

---

### Get Simulation Status

Get detailed status of a specific simulation.

```
GET /api/simulator/engine.php?action=status&simId=sim_abc123
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `simId` | string | Yes | Simulation ID |

**Response**

```json
{
  "success": true,
  "simId": "sim_abc123",
  "name": "Training Session",
  "status": "running",
  "isPaused": false,
  "currentTime": "2026-02-01T14:30:00Z",
  "startTime": "2026-02-01T14:00:00Z",
  "elapsedSeconds": 1800,
  "aircraftCount": 12
}
```

---

### Pause Simulation

Pause a running simulation.

```
POST /api/simulator/engine.php
Content-Type: application/json

{
  "action": "pause",
  "simId": "sim_abc123"
}
```

---

### Resume Simulation

Resume a paused simulation.

```
POST /api/simulator/engine.php
Content-Type: application/json

{
  "action": "resume",
  "simId": "sim_abc123"
}
```

---

### Delete Simulation

Delete a simulation and all its aircraft.

```
POST /api/simulator/engine.php
Content-Type: application/json

{
  "action": "delete",
  "simId": "sim_abc123"
}
```

---

## Aircraft Management

### Spawn Aircraft

Add a new aircraft to the simulation.

```
POST /api/simulator/engine.php
Content-Type: application/json

{
  "action": "spawn",
  "simId": "sim_abc123",
  "callsign": "AAL123",
  "aircraftType": "B738",
  "origin": "KDFW",
  "destination": "KJFK",
  "route": "FORNY MEMPH J24 BRISS",
  "altitude": 0,
  "speed": 0,
  "cruiseAltitude": 35000,
  "lat": 32.897,
  "lon": -97.038
}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `simId` | string | Yes | - | Simulation ID |
| `callsign` | string | Yes | - | Aircraft callsign |
| `aircraftType` | string | Yes | - | ICAO aircraft type (e.g., B738) |
| `origin` | string | Yes | - | Departure airport ICAO |
| `destination` | string | Yes | - | Arrival airport ICAO |
| `route` | string | No | null | Route string |
| `altitude` | int | No | 0 | Initial altitude (feet) |
| `speed` | int | No | 0 | Initial speed (knots) |
| `heading` | int | No | null | Initial heading (degrees) |
| `cruiseAltitude` | int | No | 35000 | Cruise altitude (feet) |
| `lat` | float | No | null | Initial latitude |
| `lon` | float | No | null | Initial longitude |

**Response**

```json
{
  "success": true,
  "aircraft": {
    "callsign": "AAL123",
    "aircraftType": "B738",
    "origin": "KDFW",
    "destination": "KJFK",
    "position": {
      "lat": 32.897,
      "lon": -97.038
    },
    "altitude": 0,
    "speed": 0,
    "heading": 90,
    "status": "ground"
  }
}
```

---

### Get Aircraft

Get all aircraft or a specific aircraft in a simulation.

**All Aircraft**

```
GET /api/simulator/engine.php?action=aircraft&simId=sim_abc123
```

**Single Aircraft**

```
GET /api/simulator/engine.php?action=aircraft&simId=sim_abc123&callsign=AAL123
```

**Response (All)**

```json
{
  "success": true,
  "aircraft": [
    {
      "callsign": "AAL123",
      "aircraftType": "B738",
      "position": {"lat": 34.512, "lon": -91.234},
      "altitude": 35000,
      "speed": 450,
      "heading": 78,
      "status": "enroute"
    }
  ],
  "count": 1
}
```

---

### Remove Aircraft

Remove an aircraft from the simulation.

```
POST /api/simulator/engine.php
Content-Type: application/json

{
  "action": "remove_aircraft",
  "simId": "sim_abc123",
  "callsign": "AAL123"
}
```

---

## Simulation Time Control

### Tick (Advance Time)

Advance simulation by a time delta.

```
POST /api/simulator/engine.php
Content-Type: application/json

{
  "action": "tick",
  "simId": "sim_abc123",
  "deltaSeconds": 60
}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `deltaSeconds` | int | No | 1 | Seconds to advance |

---

### Run (Continuous Simulation)

Run simulation continuously for a specified duration.

```
POST /api/simulator/engine.php
Content-Type: application/json

{
  "action": "run",
  "simId": "sim_abc123",
  "durationSeconds": 60,
  "tickInterval": 1
}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `durationSeconds` | int | No | 60 | Total duration to run |
| `tickInterval` | int | No | 1 | Seconds between ticks |

---

## ATC Commands

### Issue Single Command

Issue an ATC command to an aircraft.

```
POST /api/simulator/engine.php
Content-Type: application/json

{
  "action": "command",
  "simId": "sim_abc123",
  "callsign": "AAL123",
  "command": "climb",
  "params": {
    "altitude": 37000
  }
}
```

**Command Types**

| Command | Params | Description |
|---------|--------|-------------|
| `climb` | `altitude` | Climb to altitude |
| `descend` | `altitude` | Descend to altitude |
| `turn` | `heading` | Turn to heading |
| `speed` | `speed` | Adjust speed (knots) |
| `direct` | `fix` | Proceed direct to fix |
| `hold` | `fix`, `direction` | Enter holding pattern |
| `approach` | `runway` | Cleared for approach |
| `takeoff` | `runway` | Cleared for takeoff |

---

### Issue Batch Commands

Issue multiple ATC commands at once.

```
POST /api/simulator/engine.php
Content-Type: application/json

{
  "action": "commands",
  "simId": "sim_abc123",
  "commands": [
    {"callsign": "AAL123", "command": "climb", "params": {"altitude": 37000}},
    {"callsign": "UAL456", "command": "turn", "params": {"heading": 270}}
  ]
}
```

---

## Error Handling

When the flight engine is not running or unreachable:

```json
{
  "success": false,
  "error": "Engine connection failed: Connection refused",
  "hint": "Is the flight engine running? Start with: cd simulator/engine && npm start",
  "engine_url": "http://localhost:3001"
}
```

---

## See Also

- [[ATFM Training Simulator]] - User guide for the training simulator
- [[API Reference]] - Complete API reference
