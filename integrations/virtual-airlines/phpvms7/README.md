# VATSWIM phpVMS 7 Module

VATSWIM integration module for [phpVMS 7](https://github.com/nabeelio/phpvms) virtual airline software.

## Features

- **Automatic PIREP Sync**: Submits flight data when PIREPs are filed/accepted
- **CDM Times**: Sends T1-T4 predictions and T11-T14 actuals
- **Schedule Integration**: Syncs STD/STA from flight schedules
- **Queue Support**: Async API calls for better performance
- **VATSIM CID Linking**: Associates flights with pilot VATSIM IDs

## Installation

### Via Composer (Recommended)

```bash
cd /path/to/phpvms
composer require vatcscc/phpvms-vatswim
php artisan module:enable Vatswim
php artisan config:cache
```

### Manual Installation

1. Download and extract to `modules/Vatswim/`
2. Run `composer dump-autoload`
3. Enable the module: `php artisan module:enable Vatswim`
4. Publish config: `php artisan vendor:publish --tag=config`

## Configuration

Add to your `.env` file:

```env
# Enable VATSWIM integration
VATSWIM_ENABLED=true

# Your VATSWIM API key
VATSWIM_API_KEY=swim_dev_your_key_here

# Your VA's ICAO code
VATSWIM_AIRLINE_ICAO=AAL

# Optional settings
VATSWIM_SYNC_FILED=true
VATSWIM_SYNC_ACCEPTED=true
VATSWIM_INCLUDE_CID=true
VATSWIM_USE_QUEUE=true
VATSWIM_VERBOSE=false
```

### Queue Configuration

The module uses Laravel's queue system. Ensure your queue worker is running:

```bash
php artisan queue:work --queue=vatswim
```

For Supervisor configuration:

```ini
[program:phpvms-vatswim]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/phpvms/artisan queue:work --queue=vatswim --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=1
```

## Data Flow

### PIREP Filed Event

When a pilot files a PIREP (or pre-files via ACARS):

1. `PirepFiledListener` captures the event
2. `PirepTransformer` converts to VATSWIM format
3. Submits to VATSWIM with:
   - Flight plan data (route, altitude)
   - CDM T1-T4 predictions (estimated times)
   - Schedule times (STD/STA if available)

### PIREP Accepted Event

When VA staff accepts a PIREP:

1. `PirepAcceptedListener` captures the event
2. `PirepTransformer` includes actual times
3. Submits to VATSWIM with:
   - Updated flight data
   - CDM T11-T14 actuals (OOOI times)
   - Flight statistics (fuel, distance)

## CDM Time Mapping

| phpVMS Field | VATSWIM Field | CDM Milestone |
|--------------|---------------|---------------|
| `created_at` | `lgtd_utc` | T3 - Airline Gate Departure |
| `created_at + 15min` | `lrtd_utc` | T1 - Airline Runway Departure |
| `created_at + flight_time` | `lrta_utc` | T2 - Airline Runway Arrival |
| `created_at + flight_time + 10min` | `lgta_utc` | T4 - Airline Gate Arrival |
| `block_off_time` | `out_utc` | T13 - Actual Off-Block |
| `takeoff_time` | `off_utc` | T11 - Actual Takeoff |
| `landing_time` | `on_utc` | T12 - Actual Landing |
| `block_on_time` | `in_utc` | T14 - Actual In-Block |

## Obtaining an API Key

1. **Via VATSIM OAuth**: Use the `/keys/provision` endpoint
2. **Via VATSWIM Portal**: Request at https://perti.vatcscc.org/swim/keys
3. **Via Contact**: Email dev@vatcscc.org for partner keys

## Troubleshooting

### PIREPs not syncing

1. Check `VATSWIM_ENABLED=true` in `.env`
2. Verify API key is valid
3. Check Laravel logs: `storage/logs/laravel.log`
4. Enable verbose logging: `VATSWIM_VERBOSE=true`

### Queue jobs failing

1. Ensure queue worker is running
2. Check failed jobs: `php artisan queue:failed`
3. Retry failed jobs: `php artisan queue:retry all`

### API errors

Enable verbose logging to see full request/response:

```env
VATSWIM_VERBOSE=true
```

## License

MIT License - See LICENSE file

## Support

- Issues: https://github.com/vatcscc/phpvms-vatswim/issues
- Documentation: https://perti.vatcscc.org/swim/docs
- phpVMS Discord: https://discord.gg/phpvms
