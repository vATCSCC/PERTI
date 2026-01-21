# VATSWIM vATIS Correlation Service

Service that correlates active ATIS data with VATSWIM flights for runway assignment accuracy and weather enrichment.

## Features

- **ATIS Monitoring**: Polls VATSIM data feed for active ATIS broadcasts
- **Runway Correlation**: Matches ATIS runway assignments to departing/arriving flights
- **Weather Extraction**: Normalizes wind, visibility, ceiling, altimeter data
- **Flight Category**: Calculates VFR/MVFR/IFR/LIFR conditions
- **Wind Components**: Calculates headwind/crosswind for runways
- **Automatic Sync**: Runs via cron to continuously update flight data

## Installation

1. Copy files to your server
2. Configure environment variables
3. Set up cron job

## Configuration

```bash
export VATSWIM_API_KEY="swim_dev_your_key_here"
export VATSWIM_BASE_URL="https://perti.vatcscc.org/api/swim/v1"
export VATSWIM_VERBOSE="false"
```

Or edit `config.php` directly:

```php
return [
    'vatswim' => [
        'api_key' => 'your_vatswim_api_key',
        'base_url' => 'https://perti.vatcscc.org/api/swim/v1'
    ],
    'airports' => [
        'include' => ['KJFK', 'KLAX', 'KATL'],  // Only these airports
        'us_only' => true                        // Or all US airports
    ]
];
```

## Cron Setup

Run every minute:

```bash
* * * * * /usr/bin/php /path/to/cron_sync.php >> /var/log/vatswim-vatis.log 2>&1
```

## Data Flow

```
VATSIM Data Feed
       │
       ▼
┌─────────────────┐
│  ATISMonitor    │◄── Polls ATIS data
└────────┬────────┘
         │
    ┌────┴────┐
    ▼         ▼
┌────────┐ ┌────────────────┐
│Weather │ │RunwayCorrelator│
│Extract │ │                │
└───┬────┘ └───────┬────────┘
    │              │
    └──────┬───────┘
           ▼
    ┌─────────────┐
    │  SWIMSync   │──► VATSWIM API
    └─────────────┘
```

## ATIS Data Parsed

| Field | Description |
|-------|-------------|
| letter | ATIS code (A-Z) |
| time_utc | ATIS observation time |
| wind | Direction, speed, gust |
| visibility | Statute miles |
| ceiling | Feet AGL |
| temperature | Celsius |
| dewpoint | Celsius |
| altimeter | inHg / hPa |
| runways_departure | Active departure runways |
| runways_arrival | Active arrival runways |
| approaches_in_use | ILS, RNAV, Visual, etc. |

## Fields Synced to VATSWIM

### Per Flight

| Field | Description |
|-------|-------------|
| departure_runway | Expected departure runway |
| departure_atis_code | Current ATIS letter |
| departure_altimeter | Departure altimeter |
| departure_wind_dir | Wind direction |
| departure_wind_speed | Wind speed (kts) |
| arrival_runway | Expected arrival runway |
| expected_approach | ILS, RNAV, etc. |
| arrival_atis_code | Arrival ATIS letter |
| arrival_visibility | Visibility (SM) |
| arrival_ceiling | Ceiling (ft) |

### Per Airport (Weather Endpoint)

| Field | Description |
|-------|-------------|
| flight_category | VFR/MVFR/IFR/LIFR |
| wind_direction | Degrees |
| wind_speed | Knots |
| wind_gust | Gust (kts) |
| visibility_sm | Statute miles |
| ceiling_ft | Feet AGL |
| altimeter_inhg | Inches of mercury |
| altimeter_hpa | Hectopascals |
| temperature_c | Temperature |
| dewpoint_c | Dewpoint |

## Usage Examples

### Get Weather for Airport

```php
use VatSwim\VATIS\ATISMonitor;
use VatSwim\VATIS\WeatherExtractor;

$monitor = new ATISMonitor();
$weather = new WeatherExtractor($monitor);

$wx = $weather->getWeather('KJFK');
echo "KJFK: {$wx['flight_category']}, Wind {$wx['wind']['direction']}@{$wx['wind']['speed']}\n";
```

### Check Runway Crosswind

```php
use VatSwim\VATIS\ATISMonitor;
use VatSwim\VATIS\WeatherExtractor;

$monitor = new ATISMonitor();
$weather = new WeatherExtractor($monitor);

$component = $weather->getRunwayWindComponent('KJFK', '04L');
echo "Headwind: {$component['headwind']} kts\n";
echo "Crosswind: {$component['crosswind']} kts from the {$component['crosswind_direction']}\n";
```

### Correlate Flight

```php
use VatSwim\VATIS\ATISMonitor;
use VatSwim\VATIS\RunwayCorrelator;

$monitor = new ATISMonitor();
$correlator = new RunwayCorrelator($monitor);

$flight = [
    'callsign' => 'AAL123',
    'dept_icao' => 'KJFK',
    'dest_icao' => 'KLAX',
    'sid' => 'DEEZZ5'
];

$result = $correlator->correlate($flight);
echo "Expect departure: {$result['departure']['expected_runway']}\n";
echo "Expect arrival: {$result['arrival']['expected_runway']}\n";
```

## Flight Category Criteria

| Category | Ceiling | Visibility |
|----------|---------|------------|
| VFR | > 3000 ft | > 5 SM |
| MVFR | 1000-3000 ft | 3-5 SM |
| IFR | 500-999 ft | 1-3 SM |
| LIFR | < 500 ft | < 1 SM |

## License

MIT License
