# VATSWIM vFDS Integration

Bidirectional integration between vFDS (virtual Flight Data System) and VATSWIM for departure sequencing, EDCT management, and TMI synchronization.

## Features

- **EDST Client**: Interface with vEDST for flight data exchange
- **TDLS Sync**: Tower Departure List synchronization
- **Departure Sequencing**: Calculate optimal departure sequences with wake separation
- **EDCT Management**: Sync Expected Departure Clearance Times
- **TMI Sync**: Traffic Management Initiative data exchange
- **Webhook Support**: Real-time updates from vFDS

## Data Flow

```
            ┌─────────────┐
            │    vFDS     │
            └──────┬──────┘
                   │
        ┌──────────┴──────────┐
        ▼                     ▼
┌───────────────┐      ┌──────────────┐
│  EDST Client  │      │   Webhook    │
└───────┬───────┘      └──────┬───────┘
        │                     │
        └──────────┬──────────┘
                   ▼
            ┌─────────────┐
            │ SWIM Bridge │
            └──────┬──────┘
                   │
        ┌──────────┴──────────┐
        ▼                     ▼
┌───────────────┐      ┌──────────────┐
│  TDLS Sync    │      │  Sequencer   │
└───────────────┘      └──────────────┘
                   │
                   ▼
            ┌─────────────┐
            │   VATSWIM   │
            └─────────────┘
```

## Installation

1. Copy files to your server
2. Configure environment variables
3. Set up cron job
4. Configure webhook endpoint in vFDS

## Configuration

### Environment Variables

```bash
# vFDS Configuration
export VFDS_BASE_URL="https://vfds.example.com/api/v1"
export VFDS_API_KEY="your_vfds_api_key"
export VFDS_FACILITY_ID="ZNY"
export VFDS_WEBHOOK_SECRET="your_webhook_secret"

# VATSWIM Configuration
export VATSWIM_API_KEY="swim_dev_your_key_here"
export VATSWIM_BASE_URL="https://perti.vatcscc.org/api/swim/v1"
export VATSWIM_VERBOSE="false"
```

### config.php

```php
return [
    'vfds' => [
        'base_url' => 'https://vfds.example.com/api/v1',
        'api_key' => 'your_vfds_api_key',
        'facility_id' => 'ZNY'
    ],
    'airports' => [
        'include' => ['KJFK', 'KEWR', 'KLGA'],  // Specific airports
        'exclude' => []
    ],
    'sequencing' => [
        'runway_config' => 'parallel_close',  // For JFK
        'max_lookahead' => 7200  // 2 hours
    ]
];
```

## Cron Setup

Run every minute:

```bash
* * * * * /usr/bin/php /path/to/cron_sync.php >> /var/log/vatswim-vfds.log 2>&1
```

## Webhook Setup

1. Deploy `webhook.php` to a publicly accessible URL
2. Configure the webhook URL in vFDS admin panel
3. Set the shared secret in both systems
4. Subscribe to events:
   - `departure.updated`
   - `arrival.updated`
   - `edct.assigned`
   - `edct.cancelled`
   - `tmi.activated`
   - `tmi.updated`
   - `tmi.cancelled`

## Data Synced

### vFDS → VATSWIM

| Data Type | Fields |
|-----------|--------|
| Departures | callsign, airport, runway, SID, route, altitude, P-time |
| EDCT | callsign, EDCT time, reason, facility |
| Ground Stops | airport, status, reason, start/end time |
| GDPs | airport, ADR, scope, start/end time |
| MITs | fix, value, direction |

### VATSWIM → vFDS

| Data Type | Fields |
|-----------|--------|
| TMI Status | ground_stop, gdp, mit details |
| Metering | STA, ETA, slot times |
| Sequences | departure sequence, calculated times |
| Delays | delay minutes, category |

## Departure Sequencing

The sequencer calculates optimal departure times considering:

### Wake Turbulence Separation

| Leading | Following | Separation |
|---------|-----------|------------|
| SUPER | Any | 120s |
| HEAVY | SUPER | 180s |
| HEAVY | HEAVY | 120s |
| HEAVY | LARGE/SMALL | 120s |
| LARGE | Any | 90s |
| SMALL | Any | 60s |

### Runway Configuration

| Config | Min Interval |
|--------|-------------|
| Single runway | 90s |
| Close parallels | 60s |
| Far parallels | 45s |
| Intersecting | 120s |

### Sequencing Modes

- **RBS (Ration By Schedule)**: Sort by original proposed departure time
- **FCFS (First Come First Served)**: Sort by ready time

## Usage Examples

### Get Departure Sequence

```php
use VatSwim\VFDS\EDSTClient;
use VatSwim\VFDS\DepartureSequencer;

$client = new EDSTClient($baseUrl, $apiKey, 'ZNY');
$sequencer = new DepartureSequencer();

$departures = $client->getDepartureList('KJFK');
$sequence = $sequencer->sequence($departures, [
    'runway_config' => 'parallel_close',
    'max_lookahead' => 7200
]);

foreach ($sequence as $flight) {
    echo "{$flight['sequence']}. {$flight['callsign']} - ";
    echo "Calc: {$flight['calculated_departure_time']} ";
    echo "Delay: {$flight['delay_minutes']}min\n";
}
```

### Submit EDCT

```php
use VatSwim\VFDS\EDSTClient;

$client = new EDSTClient($baseUrl, $apiKey, 'ZNY');

$success = $client->submitEDCT('AAL123', '2024-01-15T14:30:00Z', 'GDP');
```

### Calculate Wind-Adjusted Sequence

```php
use VatSwim\VFDS\DepartureSequencer;

$sequencer = new DepartureSequencer();

// Add flow constraint
$sequencer->addConstraint('MIT', [
    'fix' => 'MERIT',
    'miles' => 15
]);

// Set any EDCTs
$sequencer->setEDCTs([
    'DAL456' => '2024-01-15T15:00:00Z'
]);

$sequence = $sequencer->sequence($flights);
$sequence = $sequencer->applyEDCTs($sequence);
$sequence = $sequencer->calculateDelays($sequence);

$stats = $sequencer->getStatistics($sequence);
echo "Average delay: {$stats['average_delay']} minutes\n";
```

## TMI Types Supported

| Type | Description |
|------|-------------|
| Ground Stop (GS) | All departures held |
| GDP | Ground Delay Program with ADR |
| MIT | Miles-in-trail restriction |
| MINIT | Minutes-in-trail restriction |
| AFP | Airspace Flow Program |

## Webhook Payload Format

```json
{
  "event": "edct.assigned",
  "timestamp": "2024-01-15T14:00:00Z",
  "data": {
    "callsign": "AAL123",
    "edct": "2024-01-15T15:30:00Z",
    "reason": "GDP",
    "facility": "ZNY"
  }
}
```

## License

MIT License
