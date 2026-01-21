# VATSWIM xPilot Plugin

VATSWIM integration plugin for [xPilot](https://xpilot-project.org/) VATSIM pilot client.

## Features

- **Flight Plan Sync**: Syncs filed flight plans to VATSWIM
- **SimBrief Import**: Automatically imports and syncs SimBrief OFP data
- **Position Tracking**: Forwards ACARS-style position updates

## Installation

1. Install dependencies: `pip install -r requirements.txt`
2. Copy `src/plugin.py` to xPilot plugins folder:
   - Linux: `~/.xpilot/plugins/vatswim/`
   - Windows: `%APPDATA%\xPilot\plugins\vatswim\`
   - macOS: `~/Library/Application Support/xPilot/plugins/vatswim/`
3. Create config file with your API key

### Configuration

Config file location: `~/.xpilot/plugins/vatswim/config.json`

```json
{
  "enabled": true,
  "api_key": "swim_dev_your_api_key_here",
  "api_base_url": "https://perti.vatcscc.org/api/swim/v1",
  "import_simbrief": true,
  "simbrief_username": "your_simbrief_username",
  "enable_tracking": true,
  "enable_oooi": true,
  "track_interval_ms": 1000,
  "verbose": false
}
```

## Usage

The plugin automatically:

1. Imports SimBrief OFP when you connect (if configured)
2. Syncs flight plans when filed
3. Reports positions during flight (if tracking enabled)

## Data Flow

```
xPilot Connect
    │
    ├── SimBrief Import (if enabled)
    │   └── Submit to VATSWIM with source=simbrief
    │
    ├── Flight Plan Filed
    │   └── Submit to VATSWIM with source=xpilot
    │
    └── Position Updates
        └── Submit to VATSWIM track endpoint
```

## API

### Plugin Callbacks

| Function | Description |
|----------|-------------|
| `init()` | Initialize plugin |
| `shutdown()` | Cleanup plugin |
| `on_connected(callsign, cid, real_name)` | VATSIM connected |
| `on_disconnected()` | VATSIM disconnected |
| `on_flight_plan_filed(...)` | Flight plan filed |
| `on_position_update(...)` | Position update |

## Development

### Running Tests

```bash
pip install pytest
pytest tests/
```

### Debug Mode

Set `verbose: true` in config for detailed logging.

## License

MIT License - See LICENSE file

## Support

- Issues: https://github.com/vatcscc/xpilot-vatswim/issues
- xPilot: https://xpilot-project.org/
