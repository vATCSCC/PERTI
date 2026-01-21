# VATSWIM smartCARS Integration

Webhook receiver for [smartCARS 3](https://smartcars.tfdidesign.com/) that syncs flight data to VATSWIM.

## Features

- **PIREP Sync**: Syncs PIREPs when started/completed
- **Position Tracking**: Forwards ACARS position updates to VATSWIM
- **CDM Times**: Submits T1-T4 predictions and T11-T14 actuals
- **Booking Sync**: Pre-files booked flights

## Installation

1. Copy files to your web server
2. Configure environment variables
3. Set up webhook in smartCARS admin panel

### File Structure

```
smartcars/
├── src/
│   ├── WebhookReceiver.php
│   ├── PIREPTransformer.php
│   └── SWIMSync.php
├── webhook.php          # Main entry point
├── config.php           # Optional config file
└── README.md
```

## Configuration

### Environment Variables

```bash
# Required
export VATSWIM_API_KEY="swim_dev_your_key_here"

# Optional
export VATSWIM_BASE_URL="https://perti.vatcscc.org/api/swim/v1"
export SMARTCARS_WEBHOOK_SECRET="your_webhook_secret"
export VATSWIM_VERBOSE="false"
```

### smartCARS Webhook Setup

In smartCARS admin panel:

1. Go to Settings → Webhooks
2. Add new webhook
3. URL: `https://your-server.com/vatswim/smartcars/webhook.php`
4. Events: Select all PIREP events
5. Secret: Set a secure secret (optional but recommended)

## Webhook Events

| Event | Description | VATSWIM Action |
|-------|-------------|----------------|
| `pirep.started` | Flight tracking started | Submit flight + OUT time |
| `pirep.position` | Position update | Submit track position |
| `pirep.completed` | Flight finished | Submit OOOI actuals |
| `pirep.cancelled` | Flight cancelled | Log only |
| `flight.booked` | Pilot booked flight | Submit as preflight |

## Data Mapping

### PIREP Started → VATSWIM

| smartCARS Field | VATSWIM Field |
|-----------------|---------------|
| `departure_icao` | `dept_icao` |
| `arrival_icao` | `dest_icao` |
| `aircraft_icao` | `aircraft_type` |
| `estimated_departure_time` | `lgtd_utc`, `lrtd_utc` |
| `estimated_arrival_time` | `lrta_utc`, `lgta_utc` |
| Event time | `out_utc` |

### PIREP Completed → VATSWIM

| smartCARS Field | VATSWIM Field |
|-----------------|---------------|
| `block_off_time` | `out_utc` |
| `takeoff_time` | `off_utc` |
| `landing_time` | `on_utc` |
| `block_on_time` | `in_utc` |
| `flight_time` | `block_time_minutes` |
| `fuel_used` | `fuel_used_lbs` |

## Testing

Test the webhook locally:

```bash
# Start PHP dev server
php -S localhost:8080

# Send test event
curl -X POST http://localhost:8080/webhook.php \
  -H "Content-Type: application/json" \
  -H "X-SmartCARS-Signature: test" \
  -d '{
    "event": "pirep.started",
    "data": {
      "pirep": {
        "callsign": "AAL123",
        "departure_icao": "KJFK",
        "arrival_icao": "KLAX",
        "aircraft_icao": "B738"
      }
    }
  }'
```

## Troubleshooting

### Webhook not receiving events

1. Verify URL is publicly accessible
2. Check smartCARS webhook logs
3. Verify SSL certificate is valid

### VATSWIM sync failing

1. Check API key is valid
2. Enable verbose logging: `VATSWIM_VERBOSE=true`
3. Check server error logs

### Signature verification failing

1. Ensure webhook secret matches in smartCARS and config
2. Check for whitespace in secret

## License

MIT License - See LICENSE file

## Support

- Issues: https://github.com/vatcscc/smartcars-vatswim/issues
- Documentation: https://perti.vatcscc.org/swim/docs
