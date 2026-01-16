# VATSIM SWIM JavaScript/TypeScript SDK

Official JavaScript/TypeScript SDK for the VATSIM SWIM (System Wide Information Management) API.

[![npm version](https://img.shields.io/npm/v/@vatsim/swim-client.svg)](https://www.npmjs.com/package/@vatsim/swim-client)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.0+-blue.svg)](https://www.typescriptlang.org/)

## Features

- **REST API Client** - Query flights, positions, TMIs with filtering and pagination
- **WebSocket Client** - Real-time flight event streaming
- **Full TypeScript Support** - Complete type definitions for all models
- **Universal** - Works in Node.js and browsers
- **Zero Dependencies** - Only `ws` for Node.js WebSocket support

## Installation

```bash
npm install @vatsim/swim-client
# or
yarn add @vatsim/swim-client
# or
pnpm add @vatsim/swim-client
```

## Quick Start

### REST API

```typescript
import { SwimRestClient } from '@vatsim/swim-client';

const client = new SwimRestClient('your-api-key');

// Get flights to JFK
const flights = await client.getFlights({ dest_icao: 'KJFK' });
for (const flight of flights) {
  console.log(`${flight.identity.callsign}: ${flight.flight_plan.departure} -> ${flight.flight_plan.destination}`);
}

// Get positions as GeoJSON
const positions = await client.getPositions({ artcc: 'ZNY' });
console.log(`Found ${positions.metadata.count} aircraft`);

// Get active TMI programs
const tmi = await client.getTmiPrograms();
console.log(`Ground Stops: ${tmi.active_ground_stops}, GDPs: ${tmi.active_gdp_programs}`);
```

### WebSocket Streaming

```typescript
import { SwimWebSocketClient } from '@vatsim/swim-client';

const client = new SwimWebSocketClient('your-api-key');

client.on('connected', (info, timestamp) => {
  console.log(`Connected! Client ID: ${info.client_id}`);
});

client.on('flight.departed', (data, timestamp) => {
  console.log(`[DEP] ${data.callsign}: ${data.dep}`);
});

client.on('flight.arrived', (data, timestamp) => {
  console.log(`[ARR] ${data.callsign}: ${data.arr}`);
});

client.on('system.heartbeat', (data, timestamp) => {
  console.log(`[HB] ${data.connected_clients} clients connected`);
});

await client.connect();
client.subscribe(['flight.departed', 'flight.arrived', 'system.heartbeat'], {
  airports: ['KJFK', 'KLAX'],
});
```

## REST API Reference

### SwimRestClient

```typescript
const client = new SwimRestClient('your-api-key', {
  baseUrl: 'https://perti.vatcscc.org/api/swim/v1',  // optional
  timeout: 30000,  // optional, milliseconds
});
```

### Flight Methods

```typescript
// Get flights with filtering
const flights = await client.getFlights({
  status: 'active',           // 'active', 'completed', 'all'
  dept_icao: 'KJFK',          // Single or array: ['KJFK', 'KLGA']
  dest_icao: 'KLAX',
  artcc: 'ZNY',
  callsign: 'UAL*',           // Wildcard pattern
  tmi_controlled: true,
  phase: 'ENROUTE',
  page: 1,
  per_page: 100,              // Max 1000
});

// Get with pagination info
const response = await client.getFlightsPaginated({ dest_icao: 'KJFK' });
console.log(`Page ${response.pagination?.page} of ${response.pagination?.total_pages}`);

// Get all flights (auto-pagination)
const allFlights = await client.getAllFlights({ dest_icao: 'KJFK' });

// Get single flight
const flight = await client.getFlightByGufi('VAT-20260116-UAL123-KLAX-KJFK');
const flight = await client.getFlightByKey('UAL123_KLAX_KJFK_20260116');
```

### Position Methods

```typescript
// Get positions as GeoJSON
const positions = await client.getPositions({
  artcc: 'ZNY',
  tmi_controlled: true,
  include_route: true,
});

for (const feature of positions.features) {
  const { callsign, altitude } = feature.properties;
  const [lon, lat] = feature.geometry.coordinates;
  console.log(`${callsign} at ${lat.toFixed(2)}, ${lon.toFixed(2)}, ${altitude}ft`);
}

// Get positions in bounding box
const positions = await client.getPositionsBbox(
  42.0,   // north
  39.0,   // south
  -72.0,  // east
  -76.0   // west
);
```

### TMI Methods

```typescript
// Get active TMI programs
const tmi = await client.getTmiPrograms({
  type: 'all',       // 'all', 'gs', 'gdp'
  airport: 'KJFK',
  artcc: 'ZNY',
});

for (const gs of tmi.ground_stops) {
  console.log(`GS at ${gs.airport}: ${gs.reason}`);
}

for (const gdp of tmi.gdp_programs) {
  console.log(`GDP at ${gdp.airport}: ${gdp.average_delay_minutes}min avg`);
}

// Get TMI-controlled flights
const controlled = await client.getTmiControlledFlights('KJFK');
```

### Ingest Methods (Write Access Required)

```typescript
// Ingest flight data
const result = await client.ingestFlights([
  {
    callsign: 'TEST123',
    dept_icao: 'KJFK',
    dest_icao: 'KLAX',
    cid: 1234567,
    latitude: 40.6413,
    longitude: -73.7781,
    altitude_ft: 35000,
    vertical_rate_fpm: -500,
  },
]);
console.log(`Processed: ${result.processed}, Errors: ${result.errors}`);

// Ingest track data
const result = await client.ingestTracks([
  {
    callsign: 'TEST123',
    latitude: 41.2345,
    longitude: -74.5678,
    altitude_ft: 35000,
    ground_speed_kts: 450,
  },
]);
```

## WebSocket API Reference

### Event Channels

| Channel | Description |
|---------|-------------|
| `flight.created` | New pilot connected |
| `flight.departed` | Aircraft wheels-up |
| `flight.arrived` | Aircraft landed |
| `flight.deleted` | Pilot disconnected |
| `flight.positions` | Batched position updates |
| `flight.*` | All flight events |
| `tmi.issued` | New GS/GDP created |
| `tmi.released` | TMI ended |
| `tmi.*` | All TMI events |
| `system.heartbeat` | Server keepalive |

### Subscription Filters

```typescript
client.subscribe(
  ['flight.departed', 'flight.arrived'],
  {
    airports: ['KJFK', 'KLGA', 'KEWR'],
    artccs: ['ZNY'],
    callsign_prefix: ['UAL', 'DAL'],
    bbox: {
      north: 42.0,
      south: 39.0,
      east: -72.0,
      west: -76.0,
    },
  }
);
```

### Event Types

```typescript
import type {
  ConnectionInfo,
  FlightEventData,
  PositionsBatch,
  TmiEventData,
  HeartbeatData,
} from '@vatsim/swim-client';

client.on('connected', (data: ConnectionInfo, timestamp) => {
  console.log(data.client_id);
});

client.on('flight.departed', (data: FlightEventData, timestamp) => {
  console.log(`${data.callsign} from ${data.dep}`);
});

client.on('flight.positions', (data: PositionsBatch, timestamp) => {
  console.log(`${data.count} positions`);
  for (const pos of data.positions) {
    console.log(`${pos.callsign}: ${pos.altitude_ft}ft`);
  }
});
```

## Error Handling

```typescript
import { SwimApiError } from '@vatsim/swim-client';

try {
  const flights = await client.getFlights({ dest_icao: 'KJFK' });
} catch (error) {
  if (error instanceof SwimApiError) {
    console.error(`API Error [${error.statusCode}]: ${error.errorCode} - ${error.message}`);
  }
}
```

## TypeScript Support

All types are exported and can be imported:

```typescript
import type {
  Flight,
  FlightIdentity,
  FlightPlan,
  FlightPosition,
  PositionsResponse,
  TmiPrograms,
  GroundStop,
  GdpProgram,
  FlightIngest,
  TrackIngest,
  SwimRestClientOptions,
  SwimWebSocketClientOptions,
} from '@vatsim/swim-client';
```

## Browser Usage

The SDK works in browsers using the native `fetch` and `WebSocket` APIs:

```html
<script type="module">
  import { SwimRestClient, SwimWebSocketClient } from '@vatsim/swim-client';
  
  const rest = new SwimRestClient('your-api-key');
  const ws = new SwimWebSocketClient('your-api-key');
</script>
```

## License

MIT License - see LICENSE file.

## Support

- Email: dev@vatcscc.org
- Discord: vATCSCC Server
- Issues: https://github.com/vatcscc/swim-client-js/issues
