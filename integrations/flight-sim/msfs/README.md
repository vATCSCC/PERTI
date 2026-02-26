# VATSWIM MSFS Plugin

Microsoft Flight Simulator 2020/2024 integration for VATSWIM (VATSIM System Wide Information Management).

## Features

- **Real-time Track Reporting**: Submits aircraft position to VATSWIM API
- **OOOI Detection**: Automatic Out, Off, On, In time detection
- **SimConnect Integration**: Native SimConnect data subscription
- **vPilot Compatible**: Can be controlled via vPilot plugin

## Installation

### Community Package (Recommended)

1. Download the latest release from the [Releases](https://github.com/vatcscc/vatswim-msfs/releases) page
2. Extract to your MSFS Community folder:
   - Steam: `%APPDATA%\Microsoft Flight Simulator\Packages\Community`
   - MS Store: `%LOCALAPPDATA%\Packages\Microsoft.FlightSimulator_8wekyb3d8bbwe\LocalCache\Packages\Community`
3. Copy `vatswim_config.ini.example` to `vatswim_config.ini` and configure your API key

### Building from Source

#### Prerequisites

- Visual Studio 2022 with C++ workload
- MSFS SDK (for WASM build)
- CMake 3.20+

#### WASM Build (for MSFS)

```powershell
# Set SDK path
$env:MSFS_SDK = "C:\MSFS SDK"

# Build
mkdir build
cd build
cmake .. -G "Visual Studio 17 2022" -A x64 -DMSFS_WASM=ON
cmake --build . --config Release
```

#### Standalone DLL (for testing)

```powershell
# Set SimConnect SDK path
$env:SIMCONNECT_SDK = "C:\MSFS SDK\SimConnect SDK"

# Build
mkdir build
cd build
cmake .. -G "Visual Studio 17 2022" -A x64
cmake --build . --config Release
```

## Configuration

Edit `vatswim_config.ini` in the package folder:

```ini
[VATSWIM]
ApiKey=swim_dev_your_api_key_here
ApiBaseUrl=https://perti.vatcscc.org/api/swim/v1
TrackIntervalMs=1000
EnableOOOI=1
EnableTracks=1
VerboseLogging=0
```

### Getting an API Key

1. **Via VATSIM OAuth** (recommended): Use the `/keys/provision` endpoint with your VATSIM access token
2. **Via vPilot**: If using the VATSWIM vPilot plugin, the API key is provisioned automatically
3. **Manual Request**: Contact vATCSCC for a developer key

## Integration with vPilot

When used with the VATSWIM vPilot plugin, the MSFS plugin can be controlled remotely:

```cpp
// vPilot sets flight info when connecting
VATSWIM_SetFlightInfo("UAL123", "KJFK", "KLAX");
VATSWIM_SetApiKey("swim_dev_xxx...");
VATSWIM_EnableTracks(true);
VATSWIM_EnableOOOI(true);
```

## OOOI Detection

The plugin automatically detects flight phases:

| Phase | Detection Criteria |
|-------|-------------------|
| **OUT** | Parking brake released, ground speed > 5 kts |
| **OFF** | Airborne transition (on ground → not on ground) |
| **ON** | Landing transition (not on ground → on ground) |
| **IN** | Ground speed < 5 kts after landing, at gate area |

## Data Submitted

### Track Position (every 1 second)

```json
{
  "callsign": "UAL123",
  "latitude": 40.6413,
  "longitude": -73.7781,
  "altitude_ft": 35000,
  "groundspeed_kts": 450,
  "heading_deg": 270,
  "vertical_rate_fpm": 0,
  "on_ground": false,
  "source": "msfs_plugin"
}
```

### OOOI Events

```json
{
  "callsign": "UAL123",
  "dept_icao": "KJFK",
  "dest_icao": "KLAX",
  "off_utc": "2026-01-18T15:30:00Z",
  "source": "msfs_plugin"
}
```

## Logging

Logs are written to: `%TEMP%\vatswim_msfs.log`

Enable verbose logging in config for debugging:

```ini
VerboseLogging=1
```

## Troubleshooting

### Plugin not loading

1. Verify the package is in the correct Community folder
2. Check MSFS content manager shows the package
3. Review `vatswim_msfs.log` for errors

### No data being submitted

1. Verify API key is configured
2. Check API base URL is correct
3. Verify network connectivity to VATSWIM API
4. Enable verbose logging to see submission attempts

### SimConnect connection failed

1. Ensure MSFS is running before starting a flight
2. Check SimConnect SDK is properly installed
3. Review Windows Event Viewer for SimConnect errors

## API Reference

### Exported Functions

| Function | Description |
|----------|-------------|
| `VATSWIM_SetFlightInfo(callsign, departure, destination)` | Set flight plan info |
| `VATSWIM_SetApiKey(api_key)` | Set API key at runtime |
| `VATSWIM_EnableTracks(enable)` | Enable/disable track reporting |
| `VATSWIM_EnableOOOI(enable)` | Enable/disable OOOI detection |
| `VATSWIM_GetVersion()` | Get plugin version string |
| `VATSWIM_GetStats(tracks, oooi, errors)` | Get session statistics |
| `VATSWIM_IsConnected()` | Check SimConnect connection status |

## License

MIT License - See LICENSE file

## Support

- Issues: https://github.com/vatcscc/vatswim-msfs/issues
- Documentation: https://perti.vatcscc.org/swim/docs
- Discord: https://discord.gg/vatcscc
