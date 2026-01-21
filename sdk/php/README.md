# VATSWIM PHP SDK

Official PHP client library for the VATSWIM API - Flight data exchange for virtual airlines.

## Requirements

- PHP 8.0 or higher
- Guzzle HTTP client

## Installation

```bash
composer require vatsim/swim-client
```

## Quick Start

```php
<?php

use VatSim\Swim\SwimClient;
use VatSim\Swim\Models\Flight;
use VatSim\Swim\Models\Track;

// Create client
$client = new SwimClient('swim_par_your_api_key', [
    'source_id' => 'my_virtual_airline',
    'base_url' => 'https://perti.vatcscc.org/api/swim/v1'
]);

// Get active flights to JFK
$response = $client->getFlights(['dest_icao' => 'KJFK']);
foreach ($response->getFlights() as $flight) {
    echo "{$flight->callsign}: {$flight->deptIcao} -> {$flight->destIcao}\n";
}

// Ingest flight data (e.g., from PIREP)
$result = $client->ingestFlights([
    new Flight([
        'callsign' => 'UAL123',
        'dept_icao' => 'KLAX',
        'dest_icao' => 'KJFK',
        'aircraft_type' => 'B738',
        'out_utc' => '2026-01-18T14:30:00Z',
        'off_utc' => '2026-01-18T14:45:00Z',
        'eta_utc' => '2026-01-18T22:30:00Z'
    ])
]);

echo $result->getSummary(); // "Processed: 1, Created: 0, Updated: 1, Errors: 0"
```

## Features

### Query Flights

```php
// Get all active flights
$flights = $client->getFlights();

// Filter by airport
$arrivals = $client->getFlights(['dest_icao' => 'KJFK']);
$departures = $client->getFlights(['dept_icao' => 'KLAX']);

// Filter by ARTCC
$flights = $client->getFlights(['artcc' => 'ZNY']);

// Get TMI-controlled flights
$controlled = $client->getFlights(['tmi_controlled' => true]);

// Pagination
$page1 = $client->getFlights(['page' => 1, 'per_page' => 100]);
if ($page1->hasMorePages()) {
    $page2 = $client->getFlights(['page' => 2, 'per_page' => 100]);
}
```

### Get Single Flight

```php
$flight = $client->getFlight('VAT-20260118-UAL123-KLAX-KJFK');

if ($flight) {
    echo "Callsign: {$flight->callsign}\n";
    echo "Phase: {$flight->phase}\n";
    echo "ETA: {$flight->etaUtc}\n";
}
```

### Get Positions (GeoJSON)

```php
$positions = $client->getPositions([
    'dest_icao' => 'KJFK',
    'artcc' => 'ZNY'
]);

// $positions is a GeoJSON FeatureCollection
```

### TMI Programs

```php
// Get all active TMI programs
$programs = $client->getTmiPrograms();

// Get only Ground Stops
$groundStops = $client->getTmiPrograms('GS');

// Get TMI-controlled flights
$controlled = $client->getTmiControlledFlights();
```

### Metering Data

```php
// Get metering data for airport
$metering = $client->getMetering('KJFK');

// Get arrival sequence
$sequence = $client->getArrivalSequence('KJFK');
```

### Ingest Flight Data

```php
use VatSim\Swim\Models\Flight;

// Create flight with OOOI times
$flight = new Flight([
    'callsign' => 'UAL123',
    'dept_icao' => 'KLAX',
    'dest_icao' => 'KJFK',
    'aircraft_type' => 'B738',
    'cid' => 1234567,

    // OOOI times (ISO 8601 format)
    'out_utc' => '2026-01-18T14:30:00Z',  // Gate departure
    'off_utc' => '2026-01-18T14:45:00Z',  // Wheels up
    'on_utc' => '2026-01-18T22:15:00Z',   // Wheels down
    'in_utc' => '2026-01-18T22:30:00Z',   // Gate arrival

    // CDM T1-T4 predictions (optional)
    'lrtd_utc' => '2026-01-18T14:45:00Z', // Last Runway Time of Departure
    'lrta_utc' => '2026-01-18T22:15:00Z', // Last Runway Time of Arrival
]);

$result = $client->ingestFlights([$flight]);

if ($result->isFullySuccessful()) {
    echo "Flight data ingested successfully\n";
} else {
    foreach ($result->errorDetails as $error) {
        echo "Error: {$error}\n";
    }
}
```

### Ingest Position Updates

```php
use VatSim\Swim\Models\Track;

$track = new Track([
    'callsign' => 'UAL123',
    'latitude' => 40.6413,
    'longitude' => -73.7781,
    'altitude_ft' => 35000,
    'ground_speed_kts' => 450,
    'heading_deg' => 270,
    'vertical_rate_fpm' => -500,
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
]);

$result = $client->ingestTracks([$track]);
```

### Convenience Methods

```php
// Quick update flight with OOOI times
$result = $client->updateFlight('UAL123', 'KLAX', 'KJFK', [
    'out_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'eta_utc' => '2026-01-18T22:30:00Z'
]);

// Quick position update
$result = $client->updatePosition(
    'UAL123',
    40.6413,    // latitude
    -73.7781,   // longitude
    35000,      // altitude
    450,        // groundspeed
    270,        // heading
    -500        // vertical rate
);
```

## Error Handling

```php
use VatSim\Swim\Exceptions\SwimApiException;
use VatSim\Swim\Exceptions\SwimAuthException;
use VatSim\Swim\Exceptions\SwimRateLimitException;

try {
    $flights = $client->getFlights();
} catch (SwimAuthException $e) {
    echo "Authentication failed: {$e->getMessage()}\n";
} catch (SwimRateLimitException $e) {
    echo "Rate limited. Retry after: {$e->getRetryAfter()} seconds\n";
} catch (SwimApiException $e) {
    echo "API error ({$e->getHttpStatusCode()}): {$e->getMessage()}\n";
}
```

## phpVMS Integration Example

```php
// In your phpVMS module's PIREP listener

use App\Events\PirepAccepted;
use VatSim\Swim\SwimClient;
use VatSim\Swim\Models\Flight;

class PIREPAcceptedListener
{
    private SwimClient $swim;

    public function __construct()
    {
        $this->swim = new SwimClient(
            config('swim.api_key'),
            ['source_id' => 'phpvms']
        );
    }

    public function handle(PirepAccepted $event)
    {
        $pirep = $event->pirep;

        $flight = new Flight([
            'callsign' => $pirep->ident,
            'dept_icao' => $pirep->dpt_airport_id,
            'dest_icao' => $pirep->arr_airport_id,
            'aircraft_type' => $pirep->aircraft->icao,
            'cid' => $pirep->user->pilot_id,

            // OOOI from PIREP
            'out_utc' => $pirep->block_off_time?->toIso8601String(),
            'off_utc' => $pirep->submitted_at?->toIso8601String(),
            'on_utc' => $pirep->landing_time?->toIso8601String(),
            'in_utc' => $pirep->block_on_time?->toIso8601String(),

            // Flight complete
            'is_active' => false
        ]);

        $this->swim->ingestFlights([$flight]);
    }
}
```

## API Reference

### SwimClient Methods

| Method | Description |
|--------|-------------|
| `getFlights(array $filters)` | Get active flights with optional filters |
| `getFlight(string $identifier)` | Get single flight by GUFI or key |
| `getPositions(array $filters)` | Get positions as GeoJSON |
| `getTmiPrograms(?string $type)` | Get active TMI programs |
| `getTmiControlledFlights(array $filters)` | Get TMI-controlled flights |
| `getMetering(string $airport)` | Get metering data for airport |
| `getArrivalSequence(string $airport)` | Get arrival sequence |
| `ingestFlights(Flight[] $flights)` | Ingest flight data |
| `ingestTracks(Track[] $tracks)` | Ingest position updates |
| `ingestMetering(string $airport, array $data)` | Ingest metering data |
| `updateFlight(...)` | Quick single flight update |
| `updatePosition(...)` | Quick single position update |

### Models

| Model | Description |
|-------|-------------|
| `Flight` | Flight data with CDM/FIXM fields |
| `Track` | Position update |
| `IngestResult` | Result of ingest operation |
| `FlightsResponse` | Paginated flights response |

### Exceptions

| Exception | Description |
|-----------|-------------|
| `SwimApiException` | Base API exception |
| `SwimAuthException` | Authentication/authorization error |
| `SwimRateLimitException` | Rate limit exceeded |

## Configuration

```php
$client = new SwimClient('your_api_key', [
    'base_url' => 'https://perti.vatcscc.org/api/swim/v1',  // API base URL
    'source_id' => 'my_app',                                 // Your source identifier
    'timeout' => 30,                                         // Request timeout in seconds
    'verify_ssl' => true                                     // Verify SSL certificates
]);
```

## License

MIT License - see LICENSE file for details.

## Support

- GitHub Issues: https://github.com/vatcscc/swim-sdk-php/issues
- Documentation: https://perti.vatcscc.org/swim/docs
