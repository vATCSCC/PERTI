# Planned Crossings System - Performance Analysis

## Overview
This document details the performance characteristics of the planned boundary crossings calculation system.

## Architecture Summary

### Tiered Processing Model
| Tier | Interval | Criteria | Est. Flights/Cycle |
|------|----------|----------|-------------------|
| 1 | 15s | New flights + event-triggered recalc | 50-100 |
| 2 | 1 min | Regional + in TRACON | 100-200 |
| 3 | 2 min | Regional + in ARTCC (not TRACON) | 200-400 |
| 4 | 5 min | Regional + level flight | 300-500 |
| 5 | 10 min | International (non-regional) | 100-200 |
| 6 | 30 min | Transit-only (overflights) | 50-100 |
| 7 | 60 min | Wholly outside region | 200-500 |

### Key Optimizations

1. **Grid-based Spatial Pre-filtering**
   - 0.5-degree grid cells indexed in `adl_boundary_grid`
   - Reduces STContains checks from ~3,500 boundaries to ~5-10 per waypoint
   - ~95% reduction in spatial computation overhead

2. **Set-based Operations**
   - No row-by-row cursors in single-flight calculations
   - Batch processing uses fast-forward cursor with minimal overhead
   - Bulk inserts with TABLOCK hints

3. **Tiered Scheduling**
   - Processing spread across 15-second cycles
   - Higher-priority flights processed more frequently
   - Event triggers (OUT, OFF, route change, level flight) force immediate recalc

4. **Level Flight Smoothing**
   - 3 consecutive samples (~45 seconds) required to confirm level flight
   - Prevents false triggers from momentary altitude fluctuations
   - Triggers recalc after BOTH climb and descent phases

## Per-Flight Performance

### Operation Breakdown
| Operation | Est. Time | Notes |
|-----------|-----------|-------|
| Get waypoints | ~1ms | Indexed query on flight_uid |
| Delete old crossings | ~1ms | Indexed delete |
| Grid lookups (per waypoint) | ~0.1ms/wp | 25-40 waypoints typical |
| STContains checks | ~0.5ms/check | 3-5 checks per waypoint after grid filter |
| Insert crossings | ~0.2ms/crossing | 8-15 crossings typical |
| **Total per flight** | **20-30ms** | |

### Scaling Estimates
| Scenario | Flights | Processing Time |
|----------|---------|-----------------|
| Normal (5k global) | 100/cycle | 2-3s |
| Busy (7k global) | 150/cycle | 3-5s |
| Peak (10k global) | 200/cycle | 4-6s |
| Event surge | 300/cycle | 6-10s |

## Batch Performance (sp_CalculatePlannedCrossingsBatch)

### Time Budget Per Tier
| Tier | Budget | Max Flights | Expected Time |
|------|--------|-------------|---------------|
| 1 | 15s | 500 | 3-5s |
| 2 | 60s | 500 | 10-15s |
| 3 | 120s | 500 | 10-15s |
| 4 | 300s | 500 | 10-15s |
| 5 | 600s | 500 | 10-15s |
| 6 | 1800s | 500 | 10-15s |
| 7 | 3600s | 500 | 10-15s |

All tiers complete well within their time budgets.

## Memory Usage

### Temp Tables Per Batch
| Table | Rows | Size |
|-------|------|------|
| #batch_flights | ~200 | 20 KB |
| #waypoints | ~6,000 | 480 KB |
| #waypoint_artcc | ~6,000 | 400 KB |
| #waypoint_sectors | ~18,000 | 720 KB |
| #crossings | ~2,400 | 240 KB |
| **Total** | | **~2 MB** |

Negligible memory footprint per batch.

## Database Load

### Writes Per Cycle (Worst Case)
- 200 flights x 12 crossings avg = 2,400 crossing records
- Batch insert with minimal logging
- Index maintenance on crossing table (lightweight)

### Read Load
- Waypoint queries: ~200/cycle (indexed)
- Boundary grid lookups: ~6,000/cycle (indexed)
- STContains checks: ~12,000/cycle (optimized by grid filter)

## Query Performance (Views)

### vw_boundary_workload_forecast
- Expected rows: 1,000-5,000
- 15-minute bucketing with GROUP BY
- Response time: <100ms

### vw_hot_boundaries
- TOP 50 with aggregation
- Response time: <50ms

### vw_flights_crossing_boundary (with filter)
- Filtered by boundary + time window
- Response time: <100ms

## API Performance

### Forecast Endpoints
| Endpoint | Expected Response |
|----------|-------------------|
| ?type=workload&boundary=ZDC | <100ms |
| ?type=hot | <50ms |
| ?type=artcc_summary | <100ms |
| ?type=boundary_flights | <150ms |
| ?type=flight&flight_uid=X | <50ms |

## Recommendations for 10k+ Flights

1. **Increase Tier 4 interval** to 10 min for stable cruise flights
2. **Shard by region** for parallel batch execution if needed
3. **Add "skip if no ETA change"** logic for stable flights
4. **Partition crossings table** by calculated_at for efficient cleanup
5. **Retention policy**: Delete crossings older than 24 hours

## Monitoring Queries

```sql
-- Active crossings count
SELECT COUNT(*) FROM adl_flight_planned_crossings
WHERE planned_entry_utc >= GETUTCDATE();

-- Flights pending recalc
SELECT COUNT(*) FROM adl_flight_core
WHERE is_active = 1 AND crossing_needs_recalc = 1;

-- Tier distribution
SELECT crossing_tier, COUNT(*) AS flight_count
FROM adl_flight_core
WHERE is_active = 1
GROUP BY crossing_tier;

-- Recent calculation performance
SELECT
    DATEADD(MINUTE, DATEDIFF(MINUTE, 0, calculated_at), 0) AS minute_bucket,
    COUNT(*) AS crossings_calculated,
    COUNT(DISTINCT flight_uid) AS flights_processed
FROM adl_flight_planned_crossings
WHERE calculated_at >= DATEADD(HOUR, -1, GETUTCDATE())
GROUP BY DATEADD(MINUTE, DATEDIFF(MINUTE, 0, calculated_at), 0)
ORDER BY minute_bucket DESC;
```

## Conclusion

The planned crossings system is designed for efficient operation at scale:
- **Worst-case cycle time**: 6-10 seconds (10k flights, peak conditions)
- **Memory footprint**: ~2 MB per batch
- **Database writes**: ~2,400 rows per cycle maximum
- **API response times**: <150ms for all endpoints

The tiered processing model ensures responsive updates for high-priority flights while maintaining efficient resource utilization for lower-priority traffic.
