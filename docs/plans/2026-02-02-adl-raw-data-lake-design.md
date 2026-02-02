# ADL Raw Data Lake Design

**Date:** 2026-02-02
**Status:** Draft (v2 - Revised Architecture)
**Author:** Claude (AI-assisted design)

## Executive Summary

This document describes the architecture for a new ADL Raw Data Lake that captures all flight data at full 15-second resolution before tiering, retains it for 7 days in hot storage (existing VATSIM_ADL), then archives to cold storage indefinitely. This enables historical analysis at full resolution for any point in time, from yesterday to 100 years in the future.

### Key Changes in v2

- **Simplified architecture**: Removed Azure Table Storage layer; use existing VATSIM_ADL as hot tier
- **Tiered cold storage**: Cool tier (0-1 year) for instant access, Archive tier (1+ years) for deep storage
- **Denormalized callsign**: Added callsign directly to trajectory records to eliminate expensive JOINs
- **Backfill strategy**: Phase 0 to migrate existing 452M trajectory_archive rows
- **Bloom filters**: Added for efficient flight_uid lookups

### Key Metrics

| Metric | Value |
|--------|-------|
| **7-day hot storage** | Existing VATSIM_ADL (no additional cost) |
| **100-year archive** | ~120 TB |
| **Year 1 monthly cost** | ~$11 |
| **Year 10 monthly cost** | ~$38 |
| **Year 100 monthly cost** | ~$331 |
| **Query time (4hr window, <1yr)** | ~2 seconds |
| **Query time (4hr window, >1yr)** | 1-15 hours rehydration* + ~2 seconds |
| **Query cost (typical)** | $0.0006 |

*Archive tier data requires rehydration. See Section 4.5 for access tier strategy.

---

## 1. Goals & Requirements

### 1.1 Primary Goals

1. **Preserve full resolution** - Store all ADL data at 15-second trajectory resolution before any downsampling
2. **7-day hot retention** - Immediate access to recent data for operational queries
3. **Indefinite cold archive** - Full resolution data queryable for 100+ years
4. **Cost efficiency** - Target <$300/month for complete solution
5. **Query performance** - Sub-10-second response for typical historical queries

### 1.2 Data Scope

All 11 ADL tables will be captured:

| Category | Tables | Growth Driver |
|----------|--------|---------------|
| **Position-based** | trajectory, changelog, tmi_trajectory | Per position update (15s) |
| **Flight-based** | core, plan, times, waypoints, aircraft, tmi | Per flight |
| **Event-based** | zone_events, boundary_log | Per zone/boundary crossing |

### 1.3 Scale Requirements

| Timeframe | Peak Concurrent | Daily Flights | Daily Data |
|-----------|-----------------|---------------|------------|
| Now | 1,500 | 19,000 | ~120 GB/year |
| 2 years | 5,000 | 63,000 | ~400 GB/year |
| 10 years | 10,000 | 127,000 | ~800 GB/year |
| 100 years | 25,000 | 310,000 | ~2 TB/year |

---

## 2. Architecture Overview

### 2.1 High-Level Architecture (Revised v2)

The simplified architecture eliminates the Azure Table Storage layer and leverages the existing VATSIM_ADL SQL database as the hot tier. This reduces complexity, eliminates duplicate writes, and reduces cost.

```text
┌─────────────────────────────────────────────────────────────────────────────┐
│                         SIMPLIFIED DATA FLOW (v2)                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  VATSIM API ────► Staging ────► Process ────► VATSIM_ADL                    │
│    (15s)           Tables        Engine       (Existing SQL)                 │
│                                                    │                         │
│                                                    │ ◄── HOT TIER            │
│                                                    │     7-day retention     │
│                                                    │     (already exists)    │
│                                                    │     $0/mo additional    │
│                                                    │                         │
│                                         (Daily archive job @ 04:00 UTC)      │
│                                                    │                         │
│                                                    ▼                         │
│                              ┌─────────────────────────────────────┐        │
│                              │        Azure Blob Storage           │        │
│                              │           (Parquet)                 │        │
│                              ├─────────────────────────────────────┤        │
│                              │  COOL TIER (0-365 days)             │        │
│                              │  - $0.02/GB/mo                      │        │
│                              │  - Instant access                   │        │
│                              │  - "Query 175 days ago" = 2 sec     │        │
│                              ├─────────────────────────────────────┤        │
│                              │  ARCHIVE TIER (365+ days)           │        │
│                              │  - $0.002/GB/mo                     │        │
│                              │  - 1-15 hour rehydration            │        │
│                              │  - "Query 748 days ago" = rehydrate │        │
│                              └──────────────────┬──────────────────┘        │
│                                                 │                            │
│                                                 ▼                            │
│                              ┌─────────────────────────────────────┐        │
│                              │         Synapse Serverless          │        │
│                              │         (Query Layer)               │        │
│                              │         $5/TB scanned               │        │
│                              └─────────────────────────────────────┘        │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Key Architecture Decisions

| Decision | Rationale |
|----------|-----------|
| **No Table Storage** | VATSIM_ADL already retains 7 days; adding Table Storage duplicates data with no benefit |
| **Cool tier for <1 year** | Instant query access ($0.02/GB) meets "2 second query" requirement |
| **Archive tier for >1 year** | 10x cheaper ($0.002/GB) but requires rehydration - acceptable for rare historical queries |
| **Denormalized callsign** | Eliminates expensive JOINs; adds ~10 bytes/record but saves query time/cost |
| **Bloom filters** | Enables fast flight_uid lookups without scanning entire partitions |

### 2.3 Component Summary

| Component | Purpose | Technology | Cost Model |
|-----------|---------|------------|------------|
| **Hot Tier** | 7-day real-time access | Existing VATSIM_ADL SQL | $0/mo (already paid) |
| **Cool Tier** | 0-365 day archive | Azure Blob Cool + Parquet | $0.02/GB/mo |
| **Archive Tier** | 365+ day archive | Azure Blob Archive + Parquet | $0.002/GB/mo |
| **Query Layer** | SQL access to archive | Synapse Serverless | $5/TB scanned |
| **Archive Job** | Daily Parquet export | Azure Function | ~$1/mo |

---

## 3. Hot Tier Design (Existing VATSIM_ADL)

### 3.1 Why Use Existing SQL?

The existing VATSIM_ADL database already provides everything we need for the hot tier:

| Requirement | VATSIM_ADL Capability |
|-------------|----------------------|
| **7-day retention** | `adl_trajectory_archive` already retains recent data |
| **Full resolution** | 15-second position updates are captured |
| **Query access** | Standard SQL queries, existing dashboards work |
| **No additional cost** | Database already paid for |
| **No code changes** | Existing write path is unchanged |

### 3.2 Current Hot Tier Tables

The following tables serve as the hot tier (data source for daily archive job):

| Table | Purpose | Estimated 7-Day Size |
|-------|---------|---------------------|
| `adl_trajectory_archive` | Position history at 15s resolution | ~2.3 GB |
| `adl_flight_changelog` | All field changes with old/new values | ~1.5 GB |
| `adl_flight_core` | Flight identification and status | ~150 MB |
| `adl_flight_plan` | Filed route and cruise data | ~120 MB |
| `adl_flight_times` | Departure/arrival timestamps | ~180 MB |
| `adl_flight_waypoints` | Route waypoint sequence | ~100 MB |
| `adl_flight_boundary_log` | FIR/ARTCC boundary crossings | ~80 MB |
| `adl_zone_events` | Zone entry/exit events | ~50 MB |
| `adl_tmi_trajectory` | TMI-specific trajectory data | ~30 MB |

### 3.3 Hot Tier Query Examples

For recent data (last 7 days), query VATSIM_ADL directly:

```sql
-- Get trajectory for a specific flight (hot tier)
SELECT timestamp_utc, lat, lon, altitude_ft, groundspeed_kts, heading_deg
FROM adl_trajectory_archive
WHERE flight_uid = 12345678
  AND timestamp_utc >= DATEADD(day, -7, GETUTCDATE())
ORDER BY timestamp_utc;

-- Get all US traffic for the last hour
SELECT t.*, c.callsign, c.dept_icao, c.dest_icao
FROM adl_trajectory_archive t
JOIN adl_flight_core c ON t.flight_uid = c.flight_uid
WHERE t.timestamp_utc >= DATEADD(hour, -1, GETUTCDATE())
  AND (c.dept_icao LIKE 'K%' OR c.dest_icao LIKE 'K%');
```

### 3.4 No Changes Required

The hot tier requires **zero modifications** to the existing system:
- Write path: Unchanged (existing `vatsim_adl_daemon.php`)
- Retention: Existing cleanup jobs maintain 7-day window
- Queries: Existing dashboards and APIs continue to work
- Cost: No additional storage or compute costs

---

## 4. Cold Tier Design (Azure Blob + Parquet)

### 4.1 Why Parquet on Blob Archive?

- **Cost**: $0.002/GB/month (50x cheaper than SQL)
- **Compression**: 60-80% smaller than raw data
- **Column pruning**: Only reads requested columns
- **Predicate pushdown**: Filters at storage layer
- **Ecosystem**: Works with Synapse, Spark, DuckDB, etc.

### 4.2 Blob Container Structure

```
adl-raw-archive/
├── trajectory/
│   ├── year=2026/
│   │   ├── month=01/
│   │   │   ├── day=01/
│   │   │   │   ├── part-00000.parquet
│   │   │   │   ├── part-00001.parquet
│   │   │   │   └── ...
│   │   │   ├── day=02/
│   │   │   └── ...
│   │   └── ...
│   └── ...
├── changelog/
│   └── year=YYYY/month=MM/day=DD/*.parquet
├── flights/
│   └── year=YYYY/month=MM/day=DD/*.parquet
├── waypoints/
│   └── year=YYYY/month=MM/day=DD/*.parquet
├── boundary_log/
│   └── year=YYYY/month=MM/day=DD/*.parquet
└── zone_events/
    └── year=YYYY/month=MM/day=DD/*.parquet
```

### 4.3 Parquet Schema (Trajectory) - With Denormalized Callsign

**Critical Change**: Include `callsign` directly in trajectory records to eliminate expensive JOINs.

```parquet
message trajectory {
  required int64 flight_uid;
  required binary callsign (STRING);        -- DENORMALIZED: eliminates JOIN
  required binary dept_icao (STRING);       -- DENORMALIZED: for region filtering
  required binary dest_icao (STRING);       -- DENORMALIZED: for region filtering
  required int64 timestamp_utc (TIMESTAMP(MILLIS, true));
  required double lat;
  required double lon;
  required int32 altitude_ft;
  required int32 groundspeed_kts;
  optional int32 heading_deg;
  optional int32 vertical_rate_fpm;
}
```

**Why Denormalize?**
- Without callsign: Every trajectory query requires JOIN to flights table
- With callsign: Single table scan, 10x faster for common queries
- Storage overhead: ~15 bytes/record (callsign + airports)
- At 452M records/year: ~6.3 GB additional = $0.13/mo in Archive tier
- **Trade-off is worth it**: Query savings far exceed storage cost

### 4.4 Compression Settings

```python
# Parquet write options for optimal compression
parquet_options = {
    'compression': 'zstd',           # Best ratio for numeric data
    'compression_level': 9,          # Higher = better ratio
    'use_dictionary': True,          # For repeated values
    'write_statistics': True,        # For predicate pushdown
    'row_group_size': 100000,        # ~100K rows per group
}
```

Expected compression ratios:
- Trajectory: 70-75% reduction (70 bytes → 25 bytes)
- Changelog: 50-55% reduction (52 bytes → 25 bytes)
- Flight metadata: 60-65% reduction

### 4.5 Access Tier Strategy (Revised)

**Critical Insight**: Archive tier requires 1-15 hours rehydration, which breaks any "instant query" promise. The tiered strategy balances cost vs. accessibility.

| Data Age | Access Tier | Cost/GB/mo | Retrieval | Use Case |
|----------|-------------|------------|-----------|----------|
| 0-7 days | Hot (VATSIM_ADL) | $0 | Instant | Real-time ops, dashboards |
| 8-365 days | Cool | $0.02 | Instant | Recent historical analysis |
| 365+ days | Archive | $0.002 | 1-15 hours | Deep historical research |

### 4.6 Lifecycle Management Policy

Azure Blob Storage lifecycle policy automates tier transitions:

```json
{
  "rules": [
    {
      "name": "cool-to-archive-after-1-year",
      "enabled": true,
      "type": "Lifecycle",
      "definition": {
        "filters": {
          "blobTypes": ["blockBlob"],
          "prefixMatch": ["trajectory/", "changelog/", "flights/"]
        },
        "actions": {
          "baseBlob": {
            "tierToCool": {"daysAfterModificationGreaterThan": 8},
            "tierToArchive": {"daysAfterModificationGreaterThan": 365}
          }
        }
      }
    }
  ]
}
```

### 4.7 Rehydration Strategy for Archive Data

When querying data older than 1 year:

1. **Standard rehydration** (1-15 hours): $0.02/GB - use for planned research
2. **High-priority rehydration** (under 1 hour): $0.10/GB - use for urgent requests

```python
# Example: Programmatic rehydration request
from azure.storage.blob import BlobServiceClient, RehydratePriority

def rehydrate_partition(year: int, month: int, day: int, priority: str = "Standard"):
    """Rehydrate archived data for querying."""
    blob_service = BlobServiceClient.from_connection_string(conn_str)
    container = blob_service.get_container_client("adl-raw-archive")

    prefix = f"trajectory/year={year}/month={month:02d}/day={day:02d}/"

    for blob in container.list_blobs(name_starts_with=prefix):
        blob_client = container.get_blob_client(blob.name)
        blob_client.set_standard_blob_tier(
            "Cool",
            rehydrate_priority=RehydratePriority.HIGH if priority == "High" else RehydratePriority.STANDARD
        )

    return f"Rehydration initiated for {prefix}"
```

**User Experience for Archive Queries**:
- Query for data >1 year old → System checks blob tier
- If Archive: Return message "Data requires rehydration. ETA: 1-15 hours. Click to initiate."
- User initiates rehydration → Background job moves to Cool tier
- Email/notification when ready → User re-runs query (now instant)

---

## 5. Archive Job Design

### 5.1 Job Overview

A daily Azure Function reads from Table Storage and writes Parquet files to Blob Storage.

### 5.2 Process Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                    DAILY ARCHIVE JOB                             │
│                    (Runs at 04:00 UTC)                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. Query Table Storage for yesterday's partition               │
│     └── PartitionKey = '20260201'                               │
│                                                                  │
│  2. Stream rows into PyArrow table (batched)                    │
│     └── Process 100K rows at a time                             │
│                                                                  │
│  3. Write Parquet file(s) to Blob Storage                       │
│     └── /trajectory/year=2026/month=02/day=01/part-0000.parquet │
│                                                                  │
│  4. Verify row counts match                                     │
│                                                                  │
│  5. Delete archived rows from Table Storage                     │
│     └── Keep only last 7 days                                   │
│                                                                  │
│  6. Log metrics and alert on errors                             │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 5.3 Azure Function Code (Python)

```python
# archive_to_parquet/__init__.py
import azure.functions as func
from azure.data.tables import TableServiceClient
from azure.storage.blob import BlobServiceClient
import pyarrow as pa
import pyarrow.parquet as pq
from datetime import datetime, timedelta

def main(timer: func.TimerRequest) -> None:
    # Archive yesterday's data
    archive_date = datetime.utcnow().date() - timedelta(days=1)
    partition_key = archive_date.strftime('%Y%m%d')

    # Connect to services
    table_client = TableServiceClient.from_connection_string(
        os.environ['TABLE_STORAGE_CONN']
    ).get_table_client('adlraw-trajectory')

    blob_client = BlobServiceClient.from_connection_string(
        os.environ['BLOB_STORAGE_CONN']
    )

    # Query all rows for partition
    rows = table_client.query_entities(
        query_filter=f"PartitionKey eq '{partition_key}'"
    )

    # Convert to PyArrow and write Parquet
    schema = pa.schema([
        ('flight_uid', pa.int64()),
        ('timestamp_utc', pa.timestamp('ms', tz='UTC')),
        ('lat', pa.float64()),
        ('lon', pa.float64()),
        ('altitude_ft', pa.int32()),
        ('groundspeed_kts', pa.int32()),
        ('heading_deg', pa.int32()),
        ('vertical_rate_fpm', pa.int32()),
    ])

    # Batch processing
    batch = []
    part_num = 0

    for row in rows:
        batch.append(row)

        if len(batch) >= 1_000_000:
            write_parquet_batch(blob_client, archive_date, batch, part_num, schema)
            part_num += 1
            batch = []

    if batch:
        write_parquet_batch(blob_client, archive_date, batch, part_num, schema)

    # Delete archived rows from Table Storage (older than 7 days)
    cleanup_date = datetime.utcnow().date() - timedelta(days=8)
    cleanup_partition = cleanup_date.strftime('%Y%m%d')
    delete_partition(table_client, cleanup_partition)

def write_parquet_batch(blob_client, date, rows, part_num, schema):
    table = pa.Table.from_pylist(rows, schema=schema)

    buffer = pa.BufferOutputStream()
    pq.write_table(table, buffer, compression='zstd')

    blob_path = f"trajectory/year={date.year}/month={date.month:02d}/day={date.day:02d}/part-{part_num:05d}.parquet"

    blob = blob_client.get_blob_client('adl-raw-archive', blob_path)
    blob.upload_blob(buffer.getvalue().to_pybytes(), overwrite=True)
```

### 5.4 Function Configuration

```json
// function.json
{
  "bindings": [
    {
      "name": "timer",
      "type": "timerTrigger",
      "direction": "in",
      "schedule": "0 0 4 * * *"
    }
  ]
}
```

---

## 6. Query Layer Design (Synapse Serverless)

### 6.1 External Data Source Setup

```sql
-- Create master key for credential
CREATE MASTER KEY ENCRYPTION BY PASSWORD = 'YourSecurePassword123!';

-- Create credential for blob access
CREATE DATABASE SCOPED CREDENTIAL adl_archive_cred
WITH IDENTITY = 'SHARED ACCESS SIGNATURE',
SECRET = 'your-sas-token-here';

-- Create external data source
CREATE EXTERNAL DATA SOURCE adl_raw_archive
WITH (
    LOCATION = 'https://yourstorage.blob.core.windows.net/adl-raw-archive',
    CREDENTIAL = adl_archive_cred
);

-- Create external file format
CREATE EXTERNAL FILE FORMAT parquet_format
WITH (
    FORMAT_TYPE = PARQUET
);
```

### 6.2 Example Queries

#### Query 1: 4-Hour US Traffic Window (748 days ago)

```sql
-- Cost: ~$0.0006, Time: ~2 seconds
SELECT
    t.flight_uid,
    t.timestamp_utc,
    t.lat,
    t.lon,
    t.altitude_ft,
    t.groundspeed_kts,
    f.callsign,
    f.dept_icao,
    f.dest_icao
FROM OPENROWSET(
    BULK 'trajectory/year=2024/month=02/day=08/*.parquet',
    DATA_SOURCE = 'adl_raw_archive',
    FORMAT = 'PARQUET'
) AS t
JOIN OPENROWSET(
    BULK 'flights/year=2024/month=02/day=08/*.parquet',
    DATA_SOURCE = 'adl_raw_archive',
    FORMAT = 'PARQUET'
) AS f ON t.flight_uid = f.flight_uid
WHERE t.timestamp_utc BETWEEN '2024-02-08 14:00:00' AND '2024-02-08 18:00:00'
  AND (f.dept_icao LIKE 'K%' OR f.dest_icao LIKE 'K%')
ORDER BY t.flight_uid, t.timestamp_utc;
```

#### Query 2: Single Flight Replay

```sql
-- Cost: ~$0.00005 (minimum), Time: ~1.6 seconds
SELECT timestamp_utc, lat, lon, altitude_ft, groundspeed_kts, heading_deg
FROM OPENROWSET(
    BULK 'trajectory/year=2025/month=08/day=15/*.parquet',
    DATA_SOURCE = 'adl_raw_archive',
    FORMAT = 'PARQUET'
) AS t
WHERE flight_uid = 12345678
ORDER BY timestamp_utc;
```

#### Query 3: Monthly Traffic Trends

```sql
-- Cost: ~$0.03, Time: ~45 seconds
SELECT
    CAST(timestamp_utc AS DATE) as flight_date,
    DATEPART(hour, timestamp_utc) as hour_utc,
    COUNT(DISTINCT flight_uid) as unique_flights
FROM OPENROWSET(
    BULK 'trajectory/year=2025/month=06/day=*/*.parquet',
    DATA_SOURCE = 'adl_raw_archive',
    FORMAT = 'PARQUET'
) AS t
GROUP BY CAST(timestamp_utc AS DATE), DATEPART(hour, timestamp_utc)
ORDER BY flight_date, hour_utc;
```

### 6.3 Views for Common Queries

```sql
-- Create view for easy access
CREATE VIEW vw_trajectory_archive AS
SELECT *
FROM OPENROWSET(
    BULK 'trajectory/year=*/month=*/day=*/*.parquet',
    DATA_SOURCE = 'adl_raw_archive',
    FORMAT = 'PARQUET'
) AS t;

-- Usage
SELECT * FROM vw_trajectory_archive
WHERE timestamp_utc BETWEEN '2024-01-01' AND '2024-01-02'
  AND flight_uid = 12345;
```

---

## 7. Cost Projections (Revised v2)

### 7.1 Monthly Costs by Component

The simplified architecture eliminates Table Storage costs entirely.

| Component | Year 1 | Year 10 | Year 100 |
|-----------|--------|---------|----------|
| Hot Tier (existing VATSIM_ADL) | $0 | $0 | $0 |
| Cool Tier (0-365 days) | $2.40 | $16.00 | $40.00 |
| Archive Tier (365+ days) | $0 | $16.00 | $280.00 |
| Archive Function | $1.00 | $1.00 | $1.00 |
| Synapse Queries (est. 1000/mo) | $5.00 | $5.00 | $10.00 |
| **Total** | **$8.40** | **$38.00** | **$331.00** |

### 7.2 Cost Breakdown by Year

| Year | Cool Storage | Archive Storage | Cumulative Data | Monthly Cost |
|------|--------------|-----------------|-----------------|--------------|
| 1 | 120 GB | 0 GB | 120 GB | $11 |
| 2 | 140 GB | 120 GB | 260 GB | $15 |
| 5 | 200 GB | 800 GB | 1.5 TB | $22 |
| 10 | 300 GB | 5.4 TB | 5.7 TB | $38 |
| 50 | 400 GB | 43 TB | 44 TB | $180 |
| 100 | 500 GB | 119 TB | 120 TB | $331 |

### 7.3 Comparison to Alternatives

| Approach | Year 1/mo | Year 100/mo | Notes |
|----------|-----------|-------------|-------|
| **Cool + Archive (recommended)** | $11 | $331 | Best balance, instant <1yr queries |
| Cool tier only | $11 | $2,400 | Faster but 7x more expensive |
| Archive tier only | $3 | $246 | Cheapest but requires rehydration for ALL queries |
| SQL Hyperscale | $150 | $14,200 | Most expensive |
| No archive (lose data) | $0 | $0 | Not acceptable |

### 7.4 Query Cost Budget

| Usage Level | Queries/mo | Est. Cost | Notes |
|-------------|------------|-----------|-------|
| Light (occasional lookups) | 100 | $0.05 | Single flight replays |
| Moderate (daily analysis) | 1,000 | $0.50 | Traffic pattern analysis |
| Heavy (automated reports) | 10,000 | $5.00 | Scheduled dashboards |
| Extreme (ML pipelines) | 100,000 | $50.00 | Research workloads |

### 7.5 Rehydration Cost Budget

For Archive tier data (>1 year old):

| Priority | Cost/GB | Time | Use Case |
|----------|---------|------|----------|
| Standard | $0.02 | 1-15 hours | Planned research, batch jobs |
| High | $0.10 | <1 hour | Urgent investigations |

**Example**: Rehydrating 1 month of trajectory data (~10 GB compressed) costs $0.20 standard or $1.00 high-priority.

---

## 8. Backfill Strategy (Phase 0)

### 8.1 Existing Data to Migrate

The existing `adl_trajectory_archive` table contains historical trajectory data that should be backfilled into the new Parquet archive:

| Source | Rows | Size | Date Range |
|--------|------|------|------------|
| `adl_trajectory_archive` | ~452M | ~30 GB | Historical |
| `adl_flight_changelog` | ~1.46B | ~73 GB | Historical |

### 8.2 Backfill Process

**Option A: Batch Export (Recommended)**

```python
# backfill_trajectory.py - One-time migration script
import pyodbc
import pyarrow as pa
import pyarrow.parquet as pq
from datetime import datetime, timedelta

def backfill_date_range(start_date: str, end_date: str, batch_days: int = 7):
    """Backfill historical data one week at a time."""
    conn = pyodbc.connect(ADL_CONNECTION_STRING)

    current = datetime.strptime(start_date, '%Y-%m-%d')
    end = datetime.strptime(end_date, '%Y-%m-%d')

    while current < end:
        batch_end = min(current + timedelta(days=batch_days), end)

        # Query with denormalized callsign
        query = f"""
        SELECT
            t.flight_uid,
            c.callsign,
            c.dept_icao,
            c.dest_icao,
            t.timestamp_utc,
            t.lat,
            t.lon,
            t.altitude_ft,
            t.groundspeed_kts,
            t.heading_deg,
            t.vertical_rate_fpm
        FROM adl_trajectory_archive t
        JOIN adl_flight_core c ON t.flight_uid = c.flight_uid
        WHERE t.timestamp_utc >= '{current.strftime('%Y-%m-%d')}'
          AND t.timestamp_utc < '{batch_end.strftime('%Y-%m-%d')}'
        ORDER BY t.timestamp_utc
        """

        # Stream to Parquet by day
        for row_batch in execute_streaming(conn, query, batch_size=100000):
            write_parquet_partition(row_batch)

        print(f"Backfilled {current} to {batch_end}")
        current = batch_end

# Estimated runtime: ~4-6 hours for 452M rows
# Estimated cost: ~$5 in Synapse compute
```

**Option B: Synapse CETAS (Create External Table As Select)**

```sql
-- Faster but requires Synapse dedicated pool
CREATE EXTERNAL TABLE trajectory_backfill
WITH (
    LOCATION = 'trajectory/year=2024/',
    DATA_SOURCE = adl_raw_archive,
    FILE_FORMAT = parquet_format
)
AS
SELECT
    t.flight_uid,
    c.callsign,
    c.dept_icao,
    c.dest_icao,
    t.timestamp_utc,
    t.lat, t.lon,
    t.altitude_ft, t.groundspeed_kts
FROM VATSIM_ADL.dbo.adl_trajectory_archive t
JOIN VATSIM_ADL.dbo.adl_flight_core c ON t.flight_uid = c.flight_uid
WHERE YEAR(t.timestamp_utc) = 2024;
```

### 8.3 Backfill Schedule

| Phase | Data | Estimated Time | Priority |
|-------|------|----------------|----------|
| 0a | Last 30 days | 2 hours | Immediate |
| 0b | Last 365 days | 8 hours | Week 1 |
| 0c | All historical | 24+ hours | Week 2 |

### 8.4 Validation Queries

```sql
-- Verify row counts match
SELECT
    'SQL' as source,
    COUNT(*) as row_count,
    MIN(timestamp_utc) as min_ts,
    MAX(timestamp_utc) as max_ts
FROM adl_trajectory_archive
WHERE timestamp_utc >= '2024-01-01'

UNION ALL

SELECT
    'Parquet' as source,
    COUNT(*),
    MIN(timestamp_utc),
    MAX(timestamp_utc)
FROM OPENROWSET(
    BULK 'trajectory/year=2024/**/*.parquet',
    DATA_SOURCE = 'adl_raw_archive',
    FORMAT = 'PARQUET'
) AS t;
```

---

## 9. Implementation Plan (Revised)

### Phase 0: Backfill Historical Data (Week 0-1)

1. [ ] Create Azure Storage Account and Blob container
2. [ ] Configure lifecycle policy (Cool → Archive at 365 days)
3. [ ] Run backfill script for last 30 days (validation)
4. [ ] Verify row counts and data integrity
5. [ ] Complete full historical backfill

### Phase 1: Infrastructure Setup (Week 1-2)

1. [ ] Set up Synapse Serverless workspace
2. [ ] Create external data source and credentials
3. [ ] Create external file format for Parquet
4. [ ] Configure networking and access policies
5. [ ] Set up monitoring dashboard

### Phase 2: Archive Job (Week 2-3)

1. [ ] Create Azure Function for daily archive
2. [ ] Implement Parquet writer with ZSTD compression
3. [ ] Add callsign denormalization to archive records
4. [ ] Add verification and row count checks
5. [ ] Implement idempotency (skip if already archived)
6. [ ] Set up alerting for failures

### Phase 3: Query Layer (Week 3-4)

1. [ ] Create Synapse external tables and views
2. [ ] Build common query templates
3. [ ] Implement rehydration request workflow
4. [ ] Test query performance across tiers
5. [ ] Document query patterns and costs

### Phase 4: Validation & Cutover (Week 4-5)

1. [ ] Run archive job in parallel for 7 days
2. [ ] Verify data completeness daily
3. [ ] Performance benchmarking
4. [ ] Update documentation
5. [ ] Go-live with monitoring

---

## 10. Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Archive job fails | Data loss for 1 day | Retry logic, alerting, 7-day buffer in VATSIM_ADL |
| Parquet corruption | Query errors | Checksums, verification step, daily validation |
| Cost overrun | Budget exceeded | Daily cost monitoring, alerts at 80% threshold |
| Archive tier rehydration | Slow queries for old data | Clear UI messaging, pre-fetch for known research |
| Backfill data mismatch | Incomplete historical data | Row count validation, spot-check queries |
| Callsign JOIN failure | Missing denormalized fields | Fallback to JOIN, log for investigation |

---

## 11. Success Criteria

1. **Data completeness**: 100% of ADL data captured at 15s resolution
2. **Hot access**: <100ms query time for 7-day data (existing VATSIM_ADL)
3. **Cool access**: <5s query time for <1 year historical queries
4. **Archive access**: Clear rehydration workflow for >1 year queries
5. **Cost**: <$350/month at year 100 scale
6. **Reliability**: 99.9% archive job success rate
7. **Backfill**: 100% of historical trajectory_archive data migrated

---

## Appendix A: Azure Resource Naming

| Resource | Name | Purpose |
|----------|------|---------|
| Storage Account | `vatcsccadlraw` | Blob storage for Parquet archive |
| Blob Container | `adl-raw-archive` | Parquet archive (Cool + Archive tiers) |
| Function App | `vatcscc-adl-archive` | Daily archive job |
| Synapse Workspace | `vatcscc-synapse` | Query layer |
| Existing DB | `VATSIM_ADL` | Hot tier (7-day retention, no changes) |

## Appendix B: Estimated Data Volumes

| Year | Hot (7-day) | Archive (cumulative) | Monthly Growth |
|------|-------------|---------------------|----------------|
| 0 | 2.3 GB | 0 GB | 10 GB |
| 1 | 4.2 GB | 120 GB | 18 GB |
| 2 | 5.5 GB | 380 GB | 28 GB |
| 5 | 7.5 GB | 1.5 TB | 45 GB |
| 10 | 11 GB | 5.7 TB | 67 GB |
| 50 | 16 GB | 44 TB | 100 GB |
| 100 | 27 GB | 120 TB | 165 GB |

---

## Revision History

| Version | Date | Changes |
|---------|------|---------|
| v1 | 2026-02-02 | Initial draft with Table Storage hot tier |
| v2 | 2026-02-02 | Simplified architecture: removed Table Storage, use existing VATSIM_ADL as hot tier, added Cool/Archive tiering, callsign denormalization, backfill strategy, lifecycle policy |

---

*Document generated: 2026-02-02*
*Last updated: 2026-02-02 (v2)*
*Next review: After Phase 0 completion (backfill validation)*
