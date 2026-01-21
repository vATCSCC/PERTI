# VATSWIM vPilot Plugin

VATSWIM integration plugin for [vPilot](https://vpilot.rosscarlson.dev/) VATSIM pilot client.

## Features

- **Flight Plan Sync**: Syncs filed flight plans to VATSWIM
- **SimBrief Import**: Automatically imports and syncs SimBrief OFP data
- **Simulator Integration**: Works with VATSWIM MSFS/P3D plugins

## Installation

1. Download the latest release
2. Copy `VatswimPlugin.dll` to vPilot plugins folder:
   - `%APPDATA%\vPilot\Plugins\`
3. Edit `vatswim_config.json` with your API key

### Configuration

Config file location: `%APPDATA%\vPilot\Plugins\VATSWIM\vatswim_config.json`

```json
{
  "Enabled": true,
  "ApiKey": "swim_dev_your_api_key_here",
  "ApiBaseUrl": "https://perti.vatcscc.org/api/swim/v1",
  "ImportSimbrief": true,
  "SimbriefUsername": "your_simbrief_username",
  "VerboseLogging": false,
  "EnableTracking": true,
  "EnableOOOI": true,
  "TrackIntervalMs": 1000
}
```

## Building from Source

### Prerequisites

- .NET 6.0 SDK
- Visual Studio 2022 (optional)
- vPilot SDK (if available)

### Build Commands

```powershell
cd VatswimPlugin
dotnet build -c Release
```

## Data Flow

1. **vPilot connects** → Plugin fetches SimBrief OFP (if configured)
2. **Flight plan filed** → Plugin syncs to VATSWIM
3. **SimBrief data** → Plugin submits CDM predictions
4. **Sim plugin notified** → MSFS/P3D plugin starts tracking

## SimBrief Integration

When SimBrief import is enabled:

1. Plugin fetches latest OFP from SimBrief API
2. Extracts flight plan, fuel, and time data
3. Submits to VATSWIM with `source: simbrief`

### SimBrief Fields Synced

| SimBrief Field | VATSWIM Field |
|----------------|---------------|
| `origin.icao_code` | `dept_icao` |
| `destination.icao_code` | `dest_icao` |
| `aircraft.icaocode` | `aircraft_type` |
| `general.route` | `fp_route` |
| `general.initial_altitude` | `fp_altitude_ft` |
| `general.cruise_mach` | `cruise_mach` |
| `fuel.plan_ramp` | `block_fuel_lbs` |
| `times.sched_out` | `lgtd_utc` |
| `times.sched_in` | `lgta_utc` |

## License

MIT License - See LICENSE file

## Support

- Issues: https://github.com/vatcscc/vpilot-vatswim/issues
- vPilot Support: https://vpilot.rosscarlson.dev/
