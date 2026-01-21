# VATSWIM X-Plane Plugin

X-Plane 11/12 integration for VATSWIM (VATSIM System Wide Information Management).

## Features

- **Real-time Track Reporting**: Submits aircraft position to VATSWIM API
- **OOOI Detection**: Automatic Out, Off, On, In time detection
- **DataRef Integration**: Native X-Plane DataRef subscription
- **xPilot Compatible**: Can be controlled via xPilot integration
- **Cross-Platform**: Windows, macOS, and Linux support

## Installation

1. Download the latest release
2. Copy the `vatswim` folder to your X-Plane plugins folder:
   - `X-Plane 12/Resources/plugins/vatswim/`
3. Copy `vatswim_config.txt.example` to your X-Plane preferences folder as `vatswim_config.txt`
4. Configure your API key

### Plugin Structure

```
X-Plane 12/
└── Resources/
    └── plugins/
        └── vatswim/
            ├── win_x64/
            │   └── vatswim_xplane.xpl
            ├── mac_x64/
            │   └── vatswim_xplane.xpl
            ├── lin_x64/
            │   └── lin.xpl
            └── vatswim_config.txt
```

## Building from Source

### Prerequisites

- CMake 3.20+
- X-Plane SDK (from https://developer.x-plane.com/sdk/)
- Windows: Visual Studio 2022
- macOS: Xcode
- Linux: GCC/Clang, libcurl-dev

### Build Commands

```bash
# Set SDK path
export XPLANE_SDK="/path/to/X-Plane SDK"

# Build
mkdir build && cd build
cmake ..
cmake --build . --config Release
```

## Configuration

Edit `vatswim_config.txt` in your X-Plane preferences folder:

```
api_key=swim_dev_your_api_key_here
api_base_url=https://perti.vatcscc.org/api/swim/v1
track_interval=1.0
enable_oooi=1
enable_tracks=1
verbose_logging=0
```

## Menu Options

Access via **Plugins > VATSWIM**:

- **Enable/Disable Track Reporting**: Toggle position reporting
- **Enable/Disable Verbose Logging**: Toggle detailed logging
- **Show Statistics**: Display session stats in log

## xPilot Integration

When using xPilot with VATSWIM, the flight info is set automatically:

```c
// xPilot calls these when connecting to VATSIM
VATSWIM_SetFlightInfo("UAL123", "KJFK", "KLAX");
VATSWIM_SetApiKey("swim_dev_xxx...");
```

## DataRefs Used

### Position

| DataRef | Description |
|---------|-------------|
| sim/flightmodel/position/latitude | Latitude (degrees) |
| sim/flightmodel/position/longitude | Longitude (degrees) |
| sim/flightmodel/position/elevation | Altitude MSL (meters) |
| sim/flightmodel/position/groundspeed | Ground speed (m/s) |
| sim/flightmodel/position/mag_psi | Magnetic heading |
| sim/flightmodel/position/vh_ind | Vertical speed (m/s) |

### State

| DataRef | Description |
|---------|-------------|
| sim/flightmodel/failures/onground_any | On ground flag |
| sim/cockpit2/controls/parking_brake_ratio | Parking brake |
| sim/time/paused | Simulation paused |

## Logging

Logs are written to:
- X-Plane Log.txt (always)
- `vatswim_xplane.log` in X-Plane folder (if verbose logging enabled)

## Troubleshooting

### Plugin not loading

1. Verify plugin is in correct folder structure
2. Check X-Plane Log.txt for errors
3. Ensure correct platform version (win_x64, mac_x64, lin_x64)

### No data being submitted

1. Verify API key is configured
2. Check network connectivity
3. Enable verbose logging to see submission attempts

## License

MIT License - See LICENSE file

## Support

- Issues: https://github.com/vatcscc/vatswim-xplane/issues
- Documentation: https://perti.vatcscc.org/swim/docs
