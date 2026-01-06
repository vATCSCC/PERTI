# ADL Daemon Setup for Normalized Schema

## Option 1: Modify Existing Daemon (Recommended)

Edit `scripts/vatsim_adl_daemon.php` and change line ~178:

**Before:**
```php
$sql = "EXEC [dbo].[sp_Adl_RefreshFromVatsim] @Json = ?";
```

**After:**
```php
$sql = "EXEC [dbo].[sp_Adl_RefreshFromVatsim_Normalized] @Json = ?";
```

That's it! The daemon will now populate the normalized tables.

---

## Option 2: Run Both (During Transition)

If you want to keep the old system running while testing the new one, create a copy:

```bash
cp scripts/vatsim_adl_daemon.php scripts/vatsim_adl_daemon_normalized.php
```

Then edit the copy to use the new stored procedure and a different lock file:

```php
// Line ~178
$sql = "EXEC [dbo].[sp_Adl_RefreshFromVatsim_Normalized] @Json = ?";

// Line ~424 - change lock file name
$lockFile = __DIR__ . '/vatsim_adl_normalized.lock';
```

Run both daemons in parallel:
```bash
nohup php scripts/vatsim_adl_daemon.php &
nohup php scripts/vatsim_adl_daemon_normalized.php &
```

---

## Option 3: Use Azure WebJobs / App Service

If your daemons run on Azure App Service, create a WebJob:

1. Create `webjobs/vatsim-adl/run.php`:
```php
<?php
// Continuous WebJob for ADL refresh
require_once __DIR__ . '/../../scripts/vatsim_adl_daemon.php';
```

2. Create `webjobs/vatsim-adl/settings.job`:
```json
{
    "is_continuous": true
}
```

3. Deploy and Azure will keep it running automatically.

---

## Deployment Steps

### 1. Deploy the Stored Procedure

Run on VATSIM_ADL database:
```sql
-- First, ensure fn_GetParseTier exists
:r adl/procedures/fn_GetParseTier.sql

-- Then create the new refresh procedure
:r adl/procedures/sp_Adl_RefreshFromVatsim_Normalized.sql
```

### 2. Test the Procedure

```sql
-- Test with a small sample
DECLARE @testJson NVARCHAR(MAX) = '{"pilots":[{"cid":1234567,"callsign":"TEST1","latitude":40.6,"longitude":-73.7,"altitude":35000,"groundspeed":450,"heading":270,"qnh_i_hg":29.92,"qnh_mb":1013,"server":"USA-EAST","logon_time":"2025-01-06T12:00:00Z","flight_plan":{"flight_rules":"I","departure":"KJFK","arrival":"KLAX","route":"SKORR5 RNGRR RBV","altitude":"FL350","cruise_tas":"N0450","aircraft_faa":"H/B738/L"}}]}';

EXEC dbo.sp_Adl_RefreshFromVatsim_Normalized @Json = @testJson;

-- Verify data
SELECT * FROM dbo.adl_flight_core WHERE callsign = 'TEST1';
SELECT * FROM dbo.adl_flight_position WHERE flight_uid = (SELECT flight_uid FROM dbo.adl_flight_core WHERE callsign = 'TEST1');
SELECT * FROM dbo.adl_flight_plan WHERE flight_uid = (SELECT flight_uid FROM dbo.adl_flight_core WHERE callsign = 'TEST1');
SELECT * FROM dbo.adl_parse_queue WHERE flight_uid = (SELECT flight_uid FROM dbo.adl_flight_core WHERE callsign = 'TEST1');
```

### 3. Update the Daemon

Make the one-line change in `scripts/vatsim_adl_daemon.php`.

### 4. Restart the Daemon

```bash
# Find and stop existing daemon
pkill -f vatsim_adl_daemon.php

# Start with new procedure
nohup php scripts/vatsim_adl_daemon.php > /dev/null 2>&1 &

# Or via systemctl if configured
sudo systemctl restart vatsim-adl
```

### 5. Monitor

```bash
tail -f scripts/vatsim_adl.log
```

---

## Parse Queue Processing

The daemon only ingests data. Routes are queued for parsing. Run the parse queue processor:

```bash
# One-time processing
php adl/php/parse_queue_daemon.php

# Continuous processing
nohup php adl/php/parse_queue_daemon.php --loop &
```

Or add to your systemd services:

```ini
# /etc/systemd/system/adl-parse-queue.service
[Unit]
Description=ADL Route Parse Queue Processor
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/perti
ExecStart=/usr/bin/php adl/php/parse_queue_daemon.php --loop
Restart=always

[Install]
WantedBy=multi-user.target
```

---

## Verification

After running for a few minutes, check stats:

```sql
EXEC dbo.sp_GetActiveFlightStats;
```

Expected output:
```
active_flights | vatsim_flights | pending_parse | routes_parsed
-------------- | -------------- | ------------- | -------------
2847           | 2847           | 150           | 2697
```
