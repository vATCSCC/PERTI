# VATSWIM C# SDK

Official .NET SDK for the VATSWIM (System Wide Information Management) API.

[![NuGet](https://img.shields.io/nuget/v/VatSim.Swim.Client.svg)](https://www.nuget.org/packages/VatSim.Swim.Client/)
[![.NET](https://img.shields.io/badge/.NET-6.0%20%7C%207.0%20%7C%208.0-blue.svg)](https://dotnet.microsoft.com/)

## Features

- **REST API Client** - Query flights, positions, TMIs with filtering and pagination
- **WebSocket Client** - Real-time flight event streaming
- **Fully Typed** - Strong typing for all models and responses
- **Async/Await** - Full async support throughout
- **Cross-platform** - Supports .NET 6+, .NET Standard 2.0

## Installation

```bash
dotnet add package VatSim.Swim.Client
```

Or via Package Manager:
```powershell
Install-Package VatSim.Swim.Client
```

## Quick Start

### REST API

```csharp
using VatSim.Swim;
using VatSim.Swim.Models;

// Create client
using var client = new SwimRestClient("your-api-key");

// Get flights to JFK
var flights = await client.GetFlightsAsync(destIcao: "KJFK");
foreach (var flight in flights)
{
    Console.WriteLine($"{flight.Callsign}: {flight.Departure} -> {flight.Destination}");
}

// Get positions as GeoJSON
var positions = await client.GetPositionsAsync(artcc: "ZNY");
Console.WriteLine($"Found {positions.Count} aircraft");

// Get active TMI programs
var tmi = await client.GetTmiProgramsAsync();
foreach (var gs in tmi.GroundStops)
{
    Console.WriteLine($"Ground Stop at {gs.Airport}: {gs.Reason}");
}
```

### WebSocket Streaming

```csharp
using VatSim.Swim;

await using var client = new SwimWebSocketClient("your-api-key");

// Register event handlers
client.OnConnected += (s, e) => 
    Console.WriteLine($"Connected! Client ID: {e.Data.ClientId}");

client.OnFlightDeparted += (s, e) => 
    Console.WriteLine($"[DEP] {e.Data.Callsign}: {e.Data.Dep}");

client.OnFlightArrived += (s, e) => 
    Console.WriteLine($"[ARR] {e.Data.Callsign}: {e.Data.Arr}");

client.OnHeartbeat += (s, e) => 
    Console.WriteLine($"[HB] {e.Data.ConnectedClients} clients connected");

// Connect and subscribe
await client.ConnectAsync();
await client.SubscribeAsync(
    new[] { "flight.departed", "flight.arrived", "system.heartbeat" },
    new SubscriptionFilters 
    { 
        Airports = new List<string> { "KJFK", "KLAX" } 
    });

// Run message loop
await client.RunAsync(cancellationToken);
```

## REST API Reference

### SwimRestClient

```csharp
using var client = new SwimRestClient(
    apiKey: "your-key",
    baseUrl: "https://perti.vatcscc.org/api/swim/v1",  // optional
    timeout: TimeSpan.FromSeconds(30)  // optional
);
```

### Flight Methods

```csharp
// Get flights with filtering
var flights = await client.GetFlightsAsync(
    status: "active",           // "active", "completed", "all"
    deptIcao: "KJFK",           // Departure airport
    destIcao: "KLAX",           // Destination airport
    artcc: "ZNY",               // ARTCC filter
    callsign: "UAL*",           // Wildcard pattern
    tmiControlled: true,        // TMI-controlled only
    phase: "ENROUTE",           // Flight phase filter
    page: 1,
    perPage: 100                // Max 1000
);

// Get with pagination info
var response = await client.GetFlightsPaginatedAsync(destIcao: "KJFK");
Console.WriteLine($"Page {response.Pagination.Page} of {response.Pagination.TotalPages}");

// Get all flights (auto-pagination)
var allFlights = await client.GetAllFlightsAsync(destIcao: "KJFK");

// Get single flight
var flight = await client.GetFlightAsync(gufi: "VAT-20260116-UAL123-KLAX-KJFK");
var flight = await client.GetFlightAsync(flightKey: "UAL123_KLAX_KJFK_20260116");
```

### Position Methods

```csharp
// Get positions as GeoJSON
var positions = await client.GetPositionsAsync(
    artcc: "ZNY",
    tmiControlled: true,
    includeRoute: true
);

foreach (var feature in positions.Features)
{
    Console.WriteLine($"{feature.Callsign} at {feature.Latitude}, {feature.Longitude}");
}

// Get positions in bounding box
var positions = await client.GetPositionsBboxAsync(
    north: 42.0, south: 39.0,
    east: -72.0, west: -76.0
);
```

### TMI Methods

```csharp
// Get active TMI programs
var tmi = await client.GetTmiProgramsAsync(
    type: "all",          // "all", "gs", "gdp"
    airport: "KJFK"
);

foreach (var gs in tmi.GroundStops)
{
    Console.WriteLine($"{gs.Airport}: {gs.Reason}");
}

foreach (var gdp in tmi.GdpPrograms)
{
    Console.WriteLine($"{gdp.Airport}: {gdp.AverageDelayMinutes}min avg delay");
}

// Get TMI-controlled flights
var controlled = await client.GetTmiControlledFlightsAsync(airport: "KJFK");
```

### Ingest Methods (Write Access Required)

```csharp
// Ingest flight data
var result = await client.IngestFlightsAsync(new[]
{
    new FlightIngest
    {
        Callsign = "TEST123",
        DeptIcao = "KJFK",
        DestIcao = "KLAX",
        Cid = 1234567,
        Latitude = 40.6413,
        Longitude = -73.7781,
        AltitudeFt = 35000,
        VerticalRateFpm = -500
    }
});
Console.WriteLine($"Processed: {result.Processed}, Errors: {result.Errors}");

// Ingest track data
var result = await client.IngestTracksAsync(new[]
{
    new TrackIngest
    {
        Callsign = "TEST123",
        Latitude = 41.2345,
        Longitude = -74.5678,
        AltitudeFt = 35000,
        GroundSpeedKts = 450
    }
});
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

```csharp
await client.SubscribeAsync(
    new[] { "flight.departed", "flight.arrived" },
    new SubscriptionFilters
    {
        Airports = new List<string> { "KJFK", "KLGA", "KEWR" },
        Artccs = new List<string> { "ZNY" },
        CallsignPrefix = new List<string> { "UAL", "DAL" },
        Bbox = new BoundingBox
        {
            North = 42.0, South = 39.0,
            East = -72.0, West = -76.0
        }
    }
);
```

## Error Handling

```csharp
try
{
    var flights = await client.GetFlightsAsync(destIcao: "KJFK");
}
catch (SwimApiException ex)
{
    Console.WriteLine($"API Error [{ex.StatusCode}]: {ex.ErrorCode} - {ex.Message}");
}
```

## Models

### Flight

```csharp
flight.Gufi               // "VAT-20260116-UAL123-KLAX-KJFK"
flight.Callsign           // "UAL123"
flight.Departure          // "KLAX"
flight.Destination        // "KJFK"
flight.Identity.AircraftType   // "B738"
flight.FlightPlan.Route        // "DCT JFK..."
flight.Position.AltitudeFt     // 35000
flight.Progress.Phase          // "ENROUTE"
flight.Times.Eta              // "2026-01-16T18:45:00Z"
flight.Tmi.IsControlled       // true/false
```

## License

MIT License - see LICENSE file.

## Support

- Email: dev@vatcscc.org
- Discord: vATCSCC Server
- Issues: https://github.com/vatcscc/swim-client-csharp/issues
