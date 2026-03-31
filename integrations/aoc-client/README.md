# VATSWIM AOC Client

C/C++ daemon that runs alongside flight simulators (MSFS, X-Plane, P3D) to provide bidirectional telemetry between the simulator and the VATSWIM API.

## Features

- **Position reporting** with configurable interval and altitude/distance thresholds
- **OOOI phase detection** (Out, Off, On, In) with automatic SWIM ingest
- **EDCT retrieval** from SWIM for TMI compliance
- **Cross-platform** -- WinHTTP on Windows, libcurl on Linux/macOS
- **i18n** -- INI-based locale files (en-US, fr-CA)

## Building

### Windows (Visual Studio / MSVC)

```bash
mkdir build && cd build
cmake .. -G "Visual Studio 17 2022"
cmake --build . --config Release
```

### Linux / macOS

```bash
mkdir build && cd build
cmake ..
make
```

Requires `libcurl-dev` on Linux (`sudo apt install libcurl4-openssl-dev`).

## Configuration

Copy `config.example.ini` to `config.ini` and set:

- `swim.api_key` -- Your SWIM API key from https://perti.vatcscc.org/swim-keys
- `client.simulator` -- `msfs`, `xplane`, or `p3d`
- `telemetry.position_interval` -- Seconds between position reports (default 15)

## Usage

```bash
./vatswim-aoc config.ini
```

The daemon connects to the configured simulator and begins polling for flight data. Position updates and OOOI events are pushed to the SWIM API automatically.

## Simulator Interfaces

- **MSFS** -- Uses SimConnect SDK (stub implementation; requires MSFS SDK headers for production)
- **X-Plane** -- Uses UDP datarefs on port 49000 (stub implementation; requires UDP socket for production)
- **P3D** -- Uses SimConnect (same interface as MSFS)

## API Endpoints Used

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/swim/v1/ingest/track` | POST | Position telemetry |
| `/api/swim/v1/ingest/adl` | POST | OOOI phase events |
| `/api/swim/v1/flight` | GET | EDCT/TMI data retrieval |
