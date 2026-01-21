# VATSWIM P3D Plugin

Prepar3D v4/v5 integration for VATSWIM (VATSIM System Wide Information Management).

## Features

- **Real-time Track Reporting**: Submits aircraft position to VATSWIM API
- **OOOI Detection**: Automatic Out, Off, On, In time detection
- **SimConnect Integration**: Native P3D SimConnect data subscription
- **vPilot Compatible**: Can be controlled via vPilot plugin

## Installation

### Manual Installation

1. Download the latest release
2. Copy `vatswim_p3d.dll` and `add-on.xml` to a folder, e.g.:
   - `Documents/Prepar3D v5 Add-ons/VATSWIM/`
3. Add the folder to P3D via Options > Add-ons
4. Copy `vatswim_p3d.ini.example` to `vatswim_p3d.ini` and configure

### Add-on Structure

```
VATSWIM/
├── vatswim_p3d.dll
├── vatswim_p3d.ini
└── add-on.xml
```

## Building from Source

### Prerequisites

- Visual Studio 2022 with C++ workload
- P3D SDK (included with Prepar3D installation)
- CMake 3.20+

### Build Commands

```powershell
# Set SDK path
$env:P3D_SDK = "C:\Program Files\Lockheed Martin\Prepar3D v5 SDK\SimConnect SDK"

# Build
mkdir build
cd build
cmake .. -G "Visual Studio 17 2022" -A x64
cmake --build . --config Release
```

## Configuration

Edit `vatswim_p3d.ini`:

```ini
[VATSWIM]
ApiKey=swim_dev_your_api_key_here
ApiBaseUrl=https://perti.vatcscc.org/api/swim/v1
TrackIntervalMs=1000
EnableOOOI=1
EnableTracks=1
VerboseLogging=0
```

## vPilot Integration

When using vPilot with VATSWIM integration:

```cpp
// vPilot sets flight info when connecting
VATSWIM_SetFlightInfo("UAL123", "KJFK", "KLAX");
VATSWIM_SetApiKey("swim_dev_xxx...");
```

## Exported Functions

| Function | Description |
|----------|-------------|
| `DLLStart()` | Called by P3D on load |
| `DLLStop()` | Called by P3D on unload |
| `VATSWIM_SetFlightInfo(callsign, departure, destination)` | Set flight plan |
| `VATSWIM_SetApiKey(api_key)` | Set API key |
| `VATSWIM_EnableTracks(enable)` | Enable/disable tracks |
| `VATSWIM_EnableOOOI(enable)` | Enable/disable OOOI |
| `VATSWIM_GetVersion()` | Get version string |
| `VATSWIM_GetStats(tracks, oooi, errors)` | Get statistics |
| `VATSWIM_IsConnected()` | Check SimConnect status |

## Logging

Logs are written to: `%TEMP%\vatswim_p3d.log`

## License

MIT License - See LICENSE file

## Support

- Issues: https://github.com/vatcscc/vatswim-p3d/issues
- Documentation: https://perti.vatcscc.org/swim/docs
