# ADL Raw Data Lake Design

**Date:** 2026-02-02
**Status:** Draft
**Author:** Claude (AI-assisted design)

## Executive Summary

This document describes the architecture for a new ADL Raw Data Lake that captures all flight data at full 15-second resolution before tiering, retains it for 7 days in hot storage, then archives to cold storage indefinitely. This enables historical analysis at full resolution for any point in time, from yesterday to 100 years in the future.

### Key Metrics

| Metric | Value |
|--------|-------|
| **7-day hot storage** | ~5 GB |
| **100-year archive** | ~120 TB |
| **Monthly cost (hybrid)** | ~$275 |
| **Query time (4hr window)** | ~2 seconds |
| **Query cost (typical)** | $0.0006 |

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

### 2.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              DATA FLOW                                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  VATSIM API ────► Staging ────► Process ────┬────► VATSIM_ADL (existing)    │
│    (15s)           Tables        Engine     │       - Operational queries    │
│                                             │       - Real-time dashboards   │
│                                             │                                │
│                                             ▼                                │
│                                    ┌────────────────┐                       │
│                                    │  Azure Table   │  ◄── HOT TIER         │
│                                    │    Storage     │      7-day retention   │
│                                    │   (Raw Lake)   │      ~5 GB             │
│                                    └───────┬────────┘      $0.23/mo          │
│                                            │                                 │
│                                    (Daily archive job)                       │
│                                            │                                 │
│                                            ▼                                 │
│                                    ┌────────────────┐                       │
│                                    │  Azure Blob    │  ◄── COLD TIER        │
│                                    │   (Parquet)    │      Indefinite        │
│                                    │  Archive Tier  │      120+ TB           │
│                                    └───────┬────────┘      $246/mo @ 100yr   │
│                                            │                                 │
│                                            ▼                                 │
│                                    ┌────────────────┐                       │
│                                    │    Synapse     │  ◄── QUERY LAYER      │
│                                    │   Serverless   │      On-demand SQL     │
│                                    │                │      $5/TB scanned     │
│                                    └────────────────┘                       │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Component Summary

| Component | Purpose | Technology | Cost Model |
|-----------|---------|------------|------------|
| **Hot Tier** | 7-day real-time access | Azure Table Storage | $0.045/GB/mo |
| **Cold Tier** | Long-term archive | Azure Blob Archive + Parquet | $0.002/GB/mo |
| **Query Layer** | SQL access to archive | Synapse Serverless | $5/TB scanned |
| **Archive Job** | Daily Parquet export | Azure Function | ~$1/mo |

---

## 3. Hot Tier Design (Azure Table Storage)

### 3.1 Why Table Storage?

- **Real-time writes**: Handles 20,000+ operations/second
- **Simple pricing**: $0.045/GB storage + $0.00036/10K transactions
- **No provisioning**: Auto-scales with load
- **Native Azure**: No additional infrastructure

### 3.2 Table Schema

#### Trajectory Table: `adlraw-trajectory`

| Property | Type | Description |
|----------|------|-------------|
| **PartitionKey** | string | `{YYYYMMDD}` - Date partition |
| **RowKey** | string | `{flight_uid}_{timestamp_iso}` |
| timestamp_utc | DateTime | Position timestamp |
| flight_uid | Int64 | Flight identifier |
| lat | Double | Latitude |
| lon | Double | Longitude |
| altitude_ft | Int32 | Altitude in feet |
| groundspeed_kts | Int32 | Ground speed in knots |
| heading_deg | Int32 | Heading in degrees |
| vertical_rate_fpm | Int32 | Vertical rate |

#### Changelog Table: `adlraw-changelog`

| Property | Type | Description |
|----------|------|-------------|
| **PartitionKey** | string | `{YYYYMMDD}` |
| **RowKey** | string | `{flight_uid}_{change_utc_iso}_{field}` |
| flight_uid | Int64 | Flight identifier |
| change_utc | DateTime | Change timestamp |
| source_table | string | Source table name |
| field_name | string | Changed field |
| old_value | string | Previous value |
| new_value | string | New value |

#### Flight Tables: `adlraw-flights`

| Property | Type | Description |
|----------|------|-------------|
| **PartitionKey** | string | `{YYYYMMDD}` |
| **RowKey** | string | `{flight_uid}` |
| (all flight_core fields) | ... | ... |
| (all flight_plan fields) | ... | ... |
| (all flight_times fields) | ... | ... |

### 3.3 Write Pattern

```php
// In vatsim_adl_daemon.php - after staged refresh
$tableClient = TableServiceClient::fromConnectionString($connStr);

// Write trajectory points
foreach ($positions as $pos) {
    $entity = new TableEntity();
    $entity->setPartitionKey(date('Ymd', strtotime($pos['timestamp_utc'])));
    $entity->setRowKey($pos['flight_uid'] . '_' . $pos['timestamp_utc']);
    $entity->addProperty('lat', EdmType::DOUBLE, $pos['lat']);
    $entity->addProperty('lon', EdmType::DOUBLE, $pos['lon']);
    // ... other fields

    $batch[] = ['upsert', $entity];
}

$tableClient->submitBatch('adlraw-trajectory', $batch);
```

### 3.4 Retention Policy

- **Automatic deletion**: Azure Table Storage doesn't have built-in TTL
- **Cleanup job**: Daily Azure Function deletes rows older than 7 days
- **Partition strategy**: Date-based partitions enable efficient cleanup

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

### 4.3 Parquet Schema (Trajectory)

```
message trajectory {
  required int64 flight_uid;
  required int64 timestamp_utc (TIMESTAMP(MILLIS, true));
  required double lat;
  required double lon;
  required int32 altitude_ft;
  required int32 groundspeed_kts;
  optional int32 heading_deg;
  optional int32 vertical_rate_fpm;
}
```

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

### 4.5 Access Tier Strategy

| Data Age | Access Tier | Cost/GB/mo | Retrieval |
|----------|-------------|------------|-----------|
| 0-30 days | Cool | $0.02 | Instant |
| 30-90 days | Cool | $0.02 | Instant |
| 90+ days | Archive | $0.002 | 1-15 hours* |

*Archive tier requires rehydration. For frequent queries, keep in Cool tier.

**Recommendation**: Start with Cool tier ($0.02/GB) for all data. Move to Archive only if query frequency is <1/month per partition.

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

## 7. Cost Projections

### 7.1 Monthly Costs by Component

| Component | Year 1 | Year 10 | Year 100 |
|-----------|--------|---------|----------|
| Table Storage (7-day hot) | $0.23 | $0.75 | $1.95 |
| Blob Storage (cumulative archive) | $2.40 | $11.59 | $246.22 |
| Archive Function | $1.00 | $1.00 | $1.00 |
| Synapse Queries (est. 1000/mo) | $5.00 | $10.00 | $25.00 |
| **Total** | **$8.63** | **$23.34** | **$274.17** |

### 7.2 Comparison to Alternatives

| Approach | Year 1/mo | Year 100/mo | Notes |
|----------|-----------|-------------|-------|
| **Hybrid (recommended)** | $9 | $275 | Best balance |
| Blob Cool only | $18 | $2,500 | Faster access |
| SQL Hyperscale | $150 | $14,200 | Most expensive |
| No archive (lose data) | $0 | $0 | Not acceptable |

### 7.3 Query Cost Budget

| Usage Level | Queries/mo | Est. Cost |
|-------------|------------|-----------|
| Light (occasional lookups) | 100 | $0.05 |
| Moderate (daily analysis) | 1,000 | $0.50 |
| Heavy (automated reports) | 10,000 | $5.00 |
| Extreme (ML pipelines) | 100,000 | $50.00 |

---

## 8. Implementation Plan

### Phase 1: Infrastructure Setup (Week 1)

1. [ ] Create Azure Storage Account for raw lake
2. [ ] Create Table Storage tables (trajectory, changelog, flights)
3. [ ] Create Blob container with lifecycle policy
4. [ ] Set up Synapse workspace with external data source
5. [ ] Configure networking and access policies

### Phase 2: Write Path (Week 2)

1. [ ] Modify `vatsim_adl_daemon.php` to write to Table Storage
2. [ ] Add batch writer for trajectory points
3. [ ] Add changelog capture to raw lake
4. [ ] Add flight metadata snapshots
5. [ ] Test write throughput under load

### Phase 3: Archive Job (Week 3)

1. [ ] Create Azure Function for daily archive
2. [ ] Implement Parquet writer with compression
3. [ ] Add verification and row count checks
4. [ ] Implement Table Storage cleanup (7-day retention)
5. [ ] Set up monitoring and alerting

### Phase 4: Query Layer (Week 4)

1. [ ] Create Synapse external tables
2. [ ] Build common query views
3. [ ] Test query performance
4. [ ] Document query patterns
5. [ ] Create sample dashboards/reports

### Phase 5: Validation & Cutover (Week 5)

1. [ ] Run parallel with existing system
2. [ ] Verify data completeness
3. [ ] Performance benchmarking
4. [ ] Documentation
5. [ ] Go-live

---

## 9. Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Archive job fails | Data loss | Retry logic, alerting, 7-day buffer |
| Table Storage throttling | Write delays | Batch writes, multiple tables |
| Parquet corruption | Query errors | Checksums, verification step |
| Cost overrun | Budget exceeded | Daily cost monitoring, alerts |
| Query performance | User frustration | Proper partitioning, documentation |

---

## 10. Success Criteria

1. **Data completeness**: 100% of ADL data captured at 15s resolution
2. **Hot access**: <100ms query time for 7-day data
3. **Cold access**: <10s query time for typical historical queries
4. **Cost**: <$300/month at year 100 scale
5. **Reliability**: 99.9% archive job success rate

---

## Appendix A: Azure Resource Naming

| Resource | Name | Purpose |
|----------|------|---------|
| Storage Account | `vatcsccadlraw` | Table + Blob storage |
| Table (trajectory) | `adlrawtrajectory` | Hot trajectory data |
| Table (changelog) | `adlrawchangelog` | Hot changelog data |
| Table (flights) | `adlrawflights` | Hot flight metadata |
| Blob Container | `adl-raw-archive` | Parquet archive |
| Function App | `vatcscc-adl-archive` | Daily archive job |
| Synapse Workspace | `vatcscc-synapse` | Query layer |

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

*Document generated: 2026-02-02*
*Next review: After Phase 1 completion*
