# VATSIM ATIS Import Scripts

Fetches ATIS information from VATSIM and parses runway assignments.

Based on parsing logic from [vatsim_control_recs](https://github.com/leftos/vatsim_control_recs).

## Components

### Database Schema
- `adl/migrations/085_atis_runway_schema.sql` - Creates tables and stored procedures

### Python Modules
- `atis_parser.py` - Parses ATIS text to extract runway assignments
- `vatsim_fetcher.py` - Fetches ATIS data from VATSIM API
- `atis_daemon.py` - Continuous import daemon

## Installation

```bash
# Install dependencies
pip install -r requirements.txt

# Create .env file with database credentials
cat > ../.env << EOF
ADL_SQL_HOST=your-server.database.windows.net
ADL_SQL_DATABASE=VATSIM_ADL
ADL_SQL_USERNAME=your-username
ADL_SQL_PASSWORD=your-password
EOF
```

## Usage

### Run Database Migration
```sql
-- Execute in SQL Server Management Studio or Azure Data Studio
:r 085_atis_runway_schema.sql
```

### Test Parser
```bash
python -m vatsim_atis.atis_parser
```

### Test Fetcher
```bash
python -m vatsim_atis.vatsim_fetcher
```

### Run Daemon
```bash
# Run continuously
python -m vatsim_atis.atis_daemon

# Run once and exit
python -m vatsim_atis.atis_daemon --once

# Filter specific airports
python -m vatsim_atis.atis_daemon --airports KJFK,KLAX,KORD

# Dry run (no database writes)
python -m vatsim_atis.atis_daemon --dry-run

# Debug mode
python -m vatsim_atis.atis_daemon --debug
```

### Run as Background Service
```bash
# Linux/macOS
nohup python -m vatsim_atis.atis_daemon > /dev/null 2>&1 &

# Windows (PowerShell)
Start-Process python -ArgumentList "-m vatsim_atis.atis_daemon" -WindowStyle Hidden
```

## Database Tables

### vatsim_atis
Stores raw ATIS broadcasts:
- `atis_id` - Primary key
- `airport_icao` - Airport code (KJFK)
- `callsign` - Controller callsign (JFK_ATIS)
- `atis_type` - COMB, ARR, or DEP
- `atis_code` - Information letter (A-Z)
- `atis_text` - Full ATIS text
- `parse_status` - PENDING, PARSED, or FAILED

### runway_in_use
Parsed runway assignments:
- `airport_icao` - Airport code
- `runway_id` - Runway designator (27L)
- `runway_use` - ARR, DEP, or BOTH
- `approach_type` - ILS, RNAV, VISUAL, etc.
- `effective_utc` - When became active
- `superseded_utc` - When replaced (NULL = current)

### atis_config_history
Configuration change history:
- `airport_icao` - Airport code
- `arr_runways` - Slash-separated arrival runways
- `dep_runways` - Slash-separated departure runways
- `effective_utc` - Configuration start time
- `duration_mins` - Computed duration

## Views

### vw_current_runways_in_use
Active runway assignments with ATIS details.

### vw_current_airport_config
Summary by airport showing current arrival/departure runways.

## Supported ATIS Formats

The parser handles multiple international formats:

- **US Standard**: "LDG RWY 27L", "DEP RWY 28R"
- **Compound**: "LDG/DEPTG RWY 27", "LDG AND DEPTG 4/8"
- **Australian**: "RWY 03 FOR ARR", "[RWY] 11"
- **European**: "RUNWAY IN USE 22", "ACTIVE RUNWAY 27"
- **Vietnamese**: "LDG 35L AND DPTG 35R"
- **Approaches**: "ILS RWY 22R", "EXPECT RNAV APPROACH RWY 35L"
- **Simultaneous**: "SIMUL DEPARTURES RWYS 24 AND 25"

## Stored Procedures

### sp_ImportVatsimAtis
Import raw ATIS data from JSON.

```sql
EXEC sp_ImportVatsimAtis @json = '[{"airport_icao":"KJFK",...}]'
```

### sp_ImportRunwaysInUse
Import parsed runways for an ATIS record.

```sql
EXEC sp_ImportRunwaysInUse @atis_id = 123, @runways_json = '[{"runway_id":"27L",...}]'
```

### sp_GetPendingAtis
Get ATIS records needing parsing.

```sql
EXEC sp_GetPendingAtis @limit = 100
```

### sp_CleanupOldAtis
Remove old records (default 7 days).

```sql
EXEC sp_CleanupOldAtis @retention_days = 7
```
