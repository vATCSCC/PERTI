# VATSIM SWIM Java SDK

Official Java SDK for the VATSIM SWIM (System Wide Information Management) API.

[![Maven Central](https://img.shields.io/maven-central/v/org.vatsim.swim/swim-client.svg)](https://search.maven.org/artifact/org.vatsim.swim/swim-client)
[![Java 11+](https://img.shields.io/badge/java-11+-blue.svg)](https://openjdk.java.net/)

## Features

- **REST API Client** - Query flights, positions, TMIs with filtering and pagination
- **WebSocket Client** - Real-time flight event streaming
- **Fully Typed** - Strong typing for all models and responses
- **Modern Java** - Uses Java 11+, OkHttp, Jackson

## Installation

### Maven

```xml
<dependency>
    <groupId>org.vatsim.swim</groupId>
    <artifactId>swim-client</artifactId>
    <version>1.0.0</version>
</dependency>
```

### Gradle

```groovy
implementation 'org.vatsim.swim:swim-client:1.0.0'
```

## Quick Start

### REST API

```java
import org.vatsim.swim.SwimRestClient;
import org.vatsim.swim.model.Flight;

try (SwimRestClient client = new SwimRestClient("your-api-key")) {
    // Get flights to JFK
    List<Flight> flights = client.getFlights("KJFK", null, "active");
    
    for (Flight flight : flights) {
        System.out.println(flight.getCallsign() + ": " + 
            flight.getDeparture() + " -> " + flight.getDestination());
    }
    
    // Get active TMI programs
    TmiPrograms tmi = client.getTmiPrograms();
    System.out.println("Ground Stops: " + tmi.getActiveGroundStops());
    System.out.println("GDPs: " + tmi.getActiveGdpPrograms());
}
```

### WebSocket Streaming

```java
import org.vatsim.swim.SwimWebSocketClient;

SwimWebSocketClient client = new SwimWebSocketClient("your-api-key");

// Register event handlers
client.on("connected", (data, timestamp) -> {
    System.out.println("Connected! Client ID: " + data.get("client_id"));
});

client.on("flight.departed", (data, timestamp) -> {
    System.out.println("[DEP] " + data.get("callsign") + ": " + data.get("dep"));
});

client.on("flight.arrived", (data, timestamp) -> {
    System.out.println("[ARR] " + data.get("callsign") + ": " + data.get("arr"));
});

client.on("system.heartbeat", (data, timestamp) -> {
    System.out.println("[HB] " + data.get("connected_clients") + " clients");
});

// Connect and subscribe
client.connect();
client.subscribe(Arrays.asList("flight.departed", "flight.arrived", "system.heartbeat"));

// Keep running
Thread.sleep(Long.MAX_VALUE);
```

## REST API Reference

### SwimRestClient

```java
// Default settings
SwimRestClient client = new SwimRestClient("your-api-key");

// Custom settings
SwimRestClient client = new SwimRestClient(
    "your-api-key",
    "https://perti.vatcscc.org/api/swim/v1",
    30  // timeout seconds
);
```

### Flight Methods

```java
// Get flights with filtering
List<Flight> flights = client.getFlights(
    "KJFK",      // destIcao
    null,        // deptIcao
    "active"     // status: "active", "completed", "all"
);

// Full filtering options
List<Flight> flights = client.getFlights(
    "KJFK",      // destIcao
    "KLAX",      // deptIcao  
    "active",    // status
    "ZNY",       // artcc
    "UAL*",      // callsign (wildcards)
    1,           // page
    100          // perPage (max 1000)
);

// Get all flights (auto-pagination)
List<Flight> allFlights = client.getAllFlights("KJFK", null, "active");

// Get single flight
Flight flight = client.getFlightByGufi("VAT-20260116-UAL123-KLAX-KJFK");
Flight flight = client.getFlightByKey("UAL123_KLAX_KJFK_20260116");
```

### TMI Methods

```java
// Get all TMI programs
TmiPrograms tmi = client.getTmiPrograms();

// With filtering
TmiPrograms tmi = client.getTmiPrograms(
    "all",       // type: "all", "gs", "gdp"
    "KJFK",      // airport (optional)
    "ZNY"        // artcc (optional)
);

// Access data
for (GroundStop gs : tmi.getGroundStops()) {
    System.out.println(gs.getAirport() + ": " + gs.getReason());
}

for (GdpProgram gdp : tmi.getGdpPrograms()) {
    System.out.println(gdp.getAirport() + ": " + 
        gdp.getAverageDelayMinutes() + "min avg delay");
}
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

```java
Map<String, Object> filters = new HashMap<>();
filters.put("airports", Arrays.asList("KJFK", "KLGA", "KEWR"));
filters.put("artccs", Arrays.asList("ZNY"));

client.subscribe(
    Arrays.asList("flight.departed", "flight.arrived"),
    filters
);
```

## Error Handling

```java
try {
    List<Flight> flights = client.getFlights("KJFK", null, "active");
} catch (SwimApiException e) {
    System.err.println("API Error [" + e.getStatusCode() + "]: " + e.getMessage());
}
```

## Models

### Flight

```java
Flight flight = ...;

flight.getGufi();                    // "VAT-20260116-UAL123-KLAX-KJFK"
flight.getCallsign();                // "UAL123"
flight.getDeparture();               // "KLAX"
flight.getDestination();             // "KJFK"
flight.getIdentity().getAircraftType();   // "B738"
flight.getFlightPlan().getRoute();        // "DCT JFK..."
flight.getPosition().getAltitudeFt();     // 35000
flight.getProgress().getPhase();          // "ENROUTE"
flight.getTimes().getEta();               // "2026-01-16T18:45:00Z"
flight.getTmi().isControlled();           // true/false
```

## Requirements

- Java 11 or higher
- Dependencies (included via Maven):
  - OkHttp 4.12+
  - Jackson 2.16+
  - Java-WebSocket 1.5+
  - SLF4J 2.0+

## License

MIT License - see LICENSE file.

## Support

- Email: dev@vatcscc.org
- Discord: vATCSCC Server
- Issues: https://github.com/vatcscc/swim-client-java/issues
