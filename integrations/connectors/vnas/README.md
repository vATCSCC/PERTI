# vNAS VATSWIM Connector

C# client library for integrating vNAS ERAM/STARS systems with the VATSWIM API.

## Endpoints

| Endpoint | Batch Limit | Auth Field | Description |
|----------|-------------|------------|-------------|
| `/api/swim/v1/ingest/vnas/track.php` | 1000 | `track` | Track surveillance data |
| `/api/swim/v1/ingest/vnas/tags.php` | 500 | `track` | ATC automation tags |
| `/api/swim/v1/ingest/vnas/handoff.php` | 200 | `track` | Sector handoffs |

## Quick Start

```csharp
var connector = new VATSWIMConnector("swim_sys_your_key_here");

var tracks = new List<TrackUpdate>
{
    new TrackUpdate
    {
        Callsign = "UAL123",
        Position = new TrackPosition
        {
            Latitude = 40.6413,
            Longitude = -73.7781,
            AltitudeFt = 35000,
            GroundSpeedKts = 450
        }
    }
};

var result = await connector.SendTracksAsync("ZDC", "ERAM", tracks);
```

## Authentication

Requires a **System tier** API key (`swim_sys_` prefix) with `track` write authority.

Request an API key at: https://perti.vatcscc.org/swim-keys.php

## Data Authority

vNAS is the **Priority 1** source for track/position data. Track updates from vNAS will override data from lower-priority sources (CRC, EuroScope, simulator plugins).
