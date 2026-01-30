# VATSIM_STATS Database Documentation

**Created:** 2026-01-30
**Author:** Claude Code
**Status:** Design Complete, Ready for Deployment

---

## Overview

VATSIM_STATS is a dedicated statistics and analytics database for VATSIM network data. It consolidates historical network statistics from VATSIM_Data and provides a comprehensive framework for:

- Real-time network activity tracking
- Multi-granularity time series analysis (5-min to yearly)
- Pattern detection and analog matching
- Facility/carrier/aircraft dimensional analytics
- Traffic level tagging and baseline comparison

---

## Database Connections

### Target Database
| Property | Value |
|----------|-------|
| **Server** | vatsim.database.windows.net |
| **Database** | VATSIM_STATS |
| **Tier** | Azure SQL Free Tier (Serverless GP_S_Gen5_1) |
| **Cost** | $0/month (within free limits) |

### Related Databases
| Database | Server | Purpose |
|----------|--------|---------|
| VATSIM_ADL | vatsim.database.windows.net | Flight data, dimensional stats |
| VATSIM_Data | vatsim.database.windows.net | **TO BE DEPRECATED** - Source for migration |
| VATSIM_GIS | vatcscc-gis.postgres.database.azure.com | PostGIS spatial queries |

---

## Migration Plan

### Source: VATSIM_Data
- **Table:** `Running_VATSIM_Data_2`
- **Rows:** ~444,000
- **Date Range:** Sep 2021 → Present
- **Size:** ~55 MB

### Migration Steps
1. Create VATSIM_STATS database (Azure SQL Free Tier)
2. Run `001_complete_schema.sql` to create tables
3. Run `002_migrate_historical_data.sql` to migrate data
4. Update Power BI dataflow to write to VATSIM_STATS
5. Verify data integrity (7-day parallel run)
6. Delete VATSIM_Data database

### Expected Savings
- VATSIM_Data primary: $365/month
- VATSIM_Data geo-replica: $194/month
- **Total savings: $559/month**

---

## Schema Overview

### Core Tables

#### Dimension Tables
| Table | Purpose | Rows |
|-------|---------|------|
| `dim_time` | Pre-populated time dimension (2020-2035) | ~1.5M |
| `traffic_baselines` | Historical percentiles for tagging | ~168 |

#### Fact Tables (5-minute snapshots)
| Table | Purpose | Growth |
|-------|---------|--------|
| `fact_network_5min` | Network-level pilot/controller counts | ~105K/year |
| `fact_facility_5min` | Facility-level traffic | ~5M/year |

#### Aggregated Stats
| Table | Granularity | Retention |
|-------|-------------|-----------|
| `stats_network_hourly` | Hourly | 90 days |
| `stats_network_daily` | Daily | 2 years |
| `stats_network_weekly` | Weekly | 2 years |
| `stats_network_monthly` | Monthly | 2 years |
| `stats_network_yearly` | Yearly | Forever |

#### Dimensional Stats
| Table | Purpose |
|-------|---------|
| `stats_carrier_daily` | Airline-level metrics |
| `stats_aircraft_daily` | Aircraft type metrics |
| `stats_facility_daily` | ARTCC/TRACON metrics |
| `stats_airport_daily` | Airport traffic metrics |

#### Pattern Detection
| Table | Purpose |
|-------|---------|
| `pattern_archetypes` | Canonical pattern templates |
| `daily_feature_vectors` | Statistical fingerprints per day |
| `pattern_clusters` | ML-driven groupings |
| `daily_pattern_assignments` | Cluster/archetype assignments |
| `analog_similarity_matrix` | Pre-computed similar day pairs |

### Historical Migration
| Table | Purpose |
|-------|---------|
| `historical_network_stats` | Migrated VATSIM_Data records |

---

## Time Binning

### Time-of-Day Bins (UTC)
| Bin | Hours | Description |
|-----|-------|-------------|
| night | 00-06Z | Lowest activity |
| morning | 06-12Z | Ramp-up period |
| afternoon | 12-18Z | Peak activity |
| evening | 18-00Z | Wind-down period |

### Minute Bins
| Bin | Values |
|-----|--------|
| 15-minute | 0, 15, 30, 45 |
| 30-minute | 0, 30 |
| 60-minute | 0 |

### Seasons (Meteorological)
| Season | Months | Code |
|--------|--------|------|
| Winter | Dec, Jan, Feb | DJF |
| Spring | Mar, Apr, May | MAM |
| Summer | Jun, Jul, Aug | JJA |
| Fall | Sep, Oct, Nov | SON |

---

## Traffic Level Tagging

Each 5-minute snapshot is tagged in real-time using pre-computed baselines:

### Traffic Levels
| Level | Definition |
|-------|------------|
| quiet | Below 50% of median |
| low | 50-100% of median |
| normal | Within IQR (p25-p75) |
| busy | Above p75 |
| peak | Above p95 |

### Comparison Tags
| Tag | Compares To |
|-----|-------------|
| `vs_hour_avg` | Same hour average |
| `vs_dow_avg` | Same day-of-week average |
| `vs_season_avg` | Same season average |

---

## Pattern Detection System

### Archetypes (Pre-defined)
| Archetype | Category | Description |
|-----------|----------|-------------|
| typical_weekday | TYPICAL | Standard Mon-Fri pattern |
| typical_weekend | TYPICAL | Sat/Sun relaxed pattern |
| friday_night_ops | EVENT | FNO extended evening |
| cross_the_pond | EVENT | CTP massive volume |
| holiday_lull | HOLIDAY | Major holiday reduction |
| anomaly_high | ANOMALY | Unexplained spike |

### Feature Vector Components
- **Shape:** peak_hour, trough_hour, slopes by time-of-day
- **Volume:** total, mean, std_dev, coefficient of variation
- **Distribution:** skewness, kurtosis, percentiles, IQR
- **Time-of-day %:** pct_night, pct_morning, pct_afternoon, pct_evening
- **Autocorrelation:** lag1, lag2

### Analog Matching
- Top 50 similar days stored per day
- Similarity components:
  - Shape similarity (hourly pattern correlation)
  - Volume similarity (total activity match)
  - Timing similarity (peak/trough alignment)
  - Context similarity (DOW, season match)

---

## Job Schedule

All times in UTC. Batch window: 02:00-04:00 UTC (lowest network activity).

| Job | Schedule | Duration |
|-----|----------|----------|
| 5-min snapshot | Every 5 min | <10ms |
| Hourly rollup | :05 past hour | ~2 sec |
| Baseline refresh | 02:00 daily | ~30 sec |
| Daily stats | 02:30 daily | ~3 min |
| Feature vectors | 02:45 daily | ~2 min |
| Archetype match | 03:00 daily | ~1 min |
| Cluster assign | 03:15 daily | ~2 min |
| Similarity matrix | 03:30 daily | ~5 min |
| Tier migration | 03:45 daily | ~2 min |
| Weekly rollup | 04:00 Monday | ~3 min |
| Monthly rollup | 04:30 1st | ~5 min |

---

## Data Tiering

### Retention Tiers
| Tier | Name | Retention | Compression |
|------|------|-----------|-------------|
| 0 | HOT | 2 days | None |
| 1 | WARM | 90 days | None |
| 2 | COOL | 2 years | PAGE |
| 3 | COLD | Forever | Blob archive |

### Automatic Migration
- HOT → WARM: After 2 days
- WARM → COOL: After 90 days
- COOL → COLD: After 2 years (archive to blob)

---

## Storage Projections

| Timeframe | Storage | Notes |
|-----------|---------|-------|
| Year 1 | 700 MB | Includes 55 MB migration |
| Year 2 | 1.5 GB | |
| Year 3 | 2.5 GB | 5K daily peak target |
| Year 5 | 5 GB | 10K daily peak target |
| Year 10 | 12 GB | Mature platform |

Free tier limit: 32 GB (sufficient through Year 4+)

---

## Query Examples

### Hot Data (Real-time Dashboard)
```sql
SELECT * FROM vw_network_hot
WHERE snapshot_time > DATEADD(HOUR, -6, GETUTCDATE());
```

### Pattern Lookup
```sql
SELECT * FROM vw_daily_patterns
WHERE stat_date = '2026-01-29';
```

### Find Analog Days
```sql
SELECT * FROM vw_find_analogs
WHERE source_date = '2026-01-29'
  AND same_dow = 1
ORDER BY overall_score DESC;
```

### Traffic by Day-of-Week and Hour
```sql
SELECT day_of_week_name, hour_of_day, AVG(total_pilots) AS avg_pilots
FROM fact_network_5min
WHERE snapshot_time > DATEADD(DAY, -90, GETUTCDATE())
GROUP BY day_of_week_name, day_of_week, hour_of_day
ORDER BY day_of_week, hour_of_day;
```

### Seasonal Comparison
```sql
SELECT season_code, year_num, AVG(pilots_avg) AS season_avg
FROM stats_network_daily
GROUP BY season_code, year_num
ORDER BY year_num,
    CASE season_code WHEN 'DJF' THEN 1 WHEN 'MAM' THEN 2 WHEN 'JJA' THEN 3 ELSE 4 END;
```

---

## Files Created

| File | Purpose |
|------|---------|
| `database/migrations/vatsim_stats/001_complete_schema.sql` | Full schema DDL |
| `database/migrations/vatsim_stats/002_migrate_historical_data.sql` | Migration script |
| `docs/VATSIM_STATS_DATABASE.md` | This documentation |

---

## Related Documentation

- [AZURE_COST_OPTIMIZATION_ANALYSIS.md](./AZURE_COST_OPTIMIZATION_ANALYSIS.md) - Cost analysis
- [load/config.php](../load/config.php) - Database connection configuration

---

## Change Log

| Date | Change |
|------|--------|
| 2026-01-30 | Initial schema design and documentation |
