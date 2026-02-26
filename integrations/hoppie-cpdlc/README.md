# VATSWIM Hoppie CPDLC Bridge

Bridge service that polls [Hoppie's ACARS system](http://www.hoppie.nl/acars/) for CPDLC messages and extracts clearance data to sync to VATSWIM.

## Features

- **DCL Parsing**: Extracts departure clearances (SID, altitude, squawk)
- **PDC Parsing**: Pre-departure clearance extraction
- **CPDLC Uplinks**: Parses altitude, direct-to, approach clearances
- **Automatic Sync**: Runs via cron to continuously poll

## Installation

1. Obtain a Hoppie logon code from http://www.hoppie.nl/acars/system/register.html
2. Copy files to your server
3. Configure environment variables
4. Set up cron job

## Configuration

```bash
export HOPPIE_LOGON="your_hoppie_logon_code"
export HOPPIE_CALLSIGN="vATCSCC"  # Your station callsign
export VATSWIM_API_KEY="swim_dev_your_key_here"
export VATSWIM_VERBOSE="false"
```

## Cron Setup

Poll every 30 seconds (2x per minute):

```bash
* * * * * /usr/bin/php /path/to/bridge.php >> /var/log/hoppie-bridge.log 2>&1
* * * * * sleep 30 && /usr/bin/php /path/to/bridge.php >> /var/log/hoppie-bridge.log 2>&1
```

## CPDLC Message Types Parsed

### DCL (Departure Clearance)

| Field | Description |
|-------|-------------|
| Callsign | Aircraft callsign |
| Destination | Destination ICAO |
| Cleared FL | Initial cleared flight level |
| SID | Standard Instrument Departure |
| Runway | Departure runway |
| Squawk | Assigned squawk code |

### CPDLC Uplinks

| Clearance Type | Example |
|----------------|---------|
| Climb | "CLIMB TO FL350" |
| Descend | "DESCEND TO FL240" |
| Direct | "PROCEED DIRECT BOSCO" |
| Approach | "CLEARED ILS RWY 28L" |
| Squawk | "SQUAWK 4521" |

## License

MIT License
