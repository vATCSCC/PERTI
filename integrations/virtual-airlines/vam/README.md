# VATSWIM VAM REST Sync Adapter

REST API sync adapter for [VAM (Virtual Airlines Manager)](https://www.virtualairlinesmanager.net/) that polls VAM and syncs flights to VATSWIM.

## Features

- **Active Flight Sync**: Polls VAM for currently flying aircraft
- **PIREP Sync**: Syncs completed PIREPs with OOOI times
- **Schedule Import**: Imports flight schedules
- **Cron-Based**: Runs on a schedule via cron

## Installation

1. Copy files to your server
2. Configure environment variables
3. Set up cron job

### File Structure

```
vam/
├── src/
│   ├── VAMClient.php      # VAM API client
│   ├── SWIMClient.php     # VATSWIM API client
│   └── FlightSync.php     # Sync logic
├── cron_sync.php          # Cron entry point
├── config.php             # Optional config file
└── README.md
```

## Configuration

### Environment Variables

```bash
# VAM settings
export VAM_BASE_URL="https://your-vam.com"
export VAM_API_KEY="your_vam_api_key"

# VATSWIM settings
export VATSWIM_API_KEY="swim_dev_your_key_here"
export VATSWIM_BASE_URL="https://perti.vatcscc.org/api/swim/v1"

# Optional
export VATSWIM_VERBOSE="false"
```

### Cron Setup

Add to crontab (`crontab -e`):

```bash
# Sync every minute
* * * * * /usr/bin/php /path/to/vam/cron_sync.php >> /var/log/vatswim-vam.log 2>&1
```

Or every 5 minutes for lower API load:

```bash
*/5 * * * * /usr/bin/php /path/to/vam/cron_sync.php >> /var/log/vatswim-vam.log 2>&1
```

## Data Flow

### Active Flight Sync

Every cron run:

1. Poll VAM `/api/v1/flights/active` for currently flying aircraft
2. Transform each flight to VATSWIM format
3. Submit to VATSWIM `/ingest/adl`

### PIREP Sync

On each run:

1. Poll VAM `/api/v1/pireps/recent` for last 24 hours
2. Transform PIREPs with OOOI times
3. Submit to VATSWIM with T11-T14 actuals

## Data Mapping

| VAM Field | VATSWIM Field |
|-----------|---------------|
| `departure_icao` | `dept_icao` |
| `arrival_icao` | `dest_icao` |
| `aircraft_icao` | `aircraft_type` |
| `pilot_vatsim_id` | `cid` |
| `scheduled_departure` | `std_utc` |
| `scheduled_arrival` | `sta_utc` |
| `estimated_departure` | `lgtd_utc` |
| `estimated_arrival` | `lrta_utc` |
| `block_off_time` | `out_utc` |
| `takeoff_time` | `off_utc` |
| `landing_time` | `on_utc` |
| `block_on_time` | `in_utc` |

## Troubleshooting

### No flights syncing

1. Verify VAM API credentials
2. Check VAM API endpoint is accessible
3. Enable verbose logging

### VATSWIM sync failing

1. Verify VATSWIM API key
2. Check network connectivity
3. Review error logs

### Cron not running

1. Check cron daemon is running: `systemctl status cron`
2. Verify PHP path: `which php`
3. Check cron logs: `grep CRON /var/log/syslog`

## License

MIT License - See LICENSE file

## Support

- Issues: https://github.com/vatcscc/vam-vatswim/issues
- Documentation: https://perti.vatcscc.org/swim/docs
