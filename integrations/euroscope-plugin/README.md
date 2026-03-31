# VATSWIM EuroScope Plugin

C++ DLL plugin for EuroScope that enriches radar tag items with SWIM data from the PERTI platform.

## Features

- **EDCT/CTOT display** in tag items (HHmm format)
- **TMI status** indicator (GDP, GS, AFP, MIT, Reroute)
- **AMAN sequence/delay** for arrival management
- **CDM status** for collaborative decision making
- **Flow measure** indicator for ECFMP integration
- **i18n support** via INI locale files (en-US, fr-CA)
- **15-second polling** against SWIM REST API

## Requirements

- Visual Studio 2022 (v143 toolset)
- Windows SDK 10.0+
- EuroScope Plugin SDK (32-bit)

## Build

1. Open `VATSWIMPlugin.vcxproj` in Visual Studio 2022
2. Set configuration to **Release | Win32**
3. Place EuroScope SDK headers in a directory and add to Include Directories
4. Build the solution

The output `VATSWIMPlugin.dll` will be in `Release/`.

## Installation

1. Copy `VATSWIMPlugin.dll` to your EuroScope plugins directory
2. Copy `config.example.ini` to `VATSWIMPlugin.ini` in the same directory
3. Edit `VATSWIMPlugin.ini` with your SWIM API key and airport
4. Copy the `locales/` folder alongside the DLL
5. In EuroScope: Other SET -> Plug-ins -> Load -> select the DLL

## Configuration

See `config.example.ini` for all options:

| Key | Description |
|-----|-------------|
| `base_url` | SWIM API base URL (default: `https://perti.vatcscc.org`) |
| `api_key` | Your SWIM API key (required) |
| `airport` | ICAO code for the airport to monitor |
| `locale` | Display locale (`en-US` or `fr-CA`) |

## Tag Items

After loading, these tag items become available in EuroScope tag editor:

| Tag Item | Description | Example |
|----------|-------------|---------|
| VATSWIM / EDCT | Expect Departure Clearance Time | `1430` |
| VATSWIM / CTOT | Calculated Takeoff Time | `1435` |
| VATSWIM / TMI Status | Active TMI program type | `GDP` |
| VATSWIM / AMAN Seq | Arrival sequence number | `12` |
| VATSWIM / AMAN Delay | Arrival delay in minutes | `5m` |
| VATSWIM / TMI Delay | TMI-assigned delay | |
| VATSWIM / CDM Status | CDM readiness state | `Ready` |
| VATSWIM / Flow Status | ECFMP flow measure ID | `EGTT01A` |

## Architecture

```
VATSWIMPlugin.cpp  -- EuroScope callback integration
SWIMClient.cpp     -- WinHTTP REST client (HTTPS, API key auth)
TagItems.cpp       -- Tag item value rendering
LocaleResource.cpp -- INI-based i18n string management
```

The plugin polls the SWIM REST API every 15 seconds, caching flight data
in memory. Tag item callbacks read from the cache for zero-latency rendering.
