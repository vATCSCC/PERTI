# Synapse Serverless SQL Scripts

These scripts set up the query layer in Azure Synapse Serverless for accessing archived trajectory data.

## Prerequisites

1. Azure Synapse workspace created
2. Storage account `pertiadlarchive` set up via `setup_infrastructure.ps1`
3. SAS token for blob access (generated from Azure Portal)

## Script Order

Run these scripts in order in Synapse Studio → SQL script:

| Script | Purpose |
|--------|---------|
| `01_create_external_data_source.sql` | Database, credentials, data source |
| `02_create_external_tables.sql` | External table for trajectory data |
| `03_create_views.sql` | Views and stored procedures for common queries |

## Quick Setup

1. Open Synapse Studio
2. Navigate to **Develop** → **SQL script**
3. Run each script in order against the **Built-in** serverless pool

## Cost Optimization

Synapse Serverless charges **$5 per TB scanned**. Always use partition filters:

```sql
-- GOOD: Uses partition pruning (scans only one day's data)
SELECT * FROM dbo.trajectory_archive
WHERE [year] = 2025 AND [month] = 1 AND [day] = 15

-- BAD: Scans all data (expensive!)
SELECT * FROM dbo.trajectory_archive
WHERE timestamp_utc = '2025-01-15'
```

## Example Queries

```sql
-- Get a specific flight's trajectory
EXEC dbo.sp_get_flight_trajectory 'DAL123', '2025-01-15';

-- Get flights between airports
EXEC dbo.sp_get_airport_pair_flights 'KJFK', 'KLAX', '2025-01-01', '2025-01-31';

-- Daily statistics
EXEC dbo.sp_get_daily_stats '2025-01-15';
```

## Data Schema

The external table exposes these columns:

| Column | Type | Description |
|--------|------|-------------|
| flight_uid | BIGINT | Unique flight identifier |
| callsign | VARCHAR(10) | Aircraft callsign |
| dept_icao | VARCHAR(4) | Departure airport ICAO |
| dest_icao | VARCHAR(4) | Destination airport ICAO |
| timestamp_utc | DATETIME2 | Position timestamp |
| lat | FLOAT | Latitude |
| lon | FLOAT | Longitude |
| altitude_ft | INT | Altitude in feet |
| groundspeed_kts | INT | Ground speed in knots |
| heading_deg | INT | Heading in degrees |
| vertical_rate_fpm | INT | Vertical rate (feet/min) |
| year | INT | Partition: year |
| month | INT | Partition: month |
| day | INT | Partition: day |

## Troubleshooting

### "File is in archive tier"

Blobs older than 365 days are in Archive tier and cannot be queried directly.
Use `rehydrate.py` to move them to Cool tier first:

```bash
python rehydrate.py --start 2024-01-01 --end 2024-01-07
```

### "External data source not found"

Ensure you've run `01_create_external_data_source.sql` and that the SAS token is valid.

### Slow queries

Always include partition filters: `WHERE [year] = X AND [month] = Y AND [day] = Z`
