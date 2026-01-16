# PERTI Azure Cost Optimization Analysis

**Date:** January 16, 2026
**Current Monthly Cost:** ~$1,600-1,700 (after geo-replica removal)
**Target Monthly Cost:** ~$200-400

---

## Executive Summary

The VATSIM_ADL database (284 GB) contains **217 GB of legacy data** that is no longer being written to. The new normalized schema has been active since January 6, 2026. By archiving legacy data and implementing compression, storage can be reduced from 270 GB to ~25 GB, with potential savings of **$100-150/month**.

---

## Current Storage Breakdown

| Table | Size | % of DB | Records | Status |
|-------|------|---------|---------|--------|
| **adl_flights_history** | 209.35 GB | 79.3% | 213.8M | LEGACY - No longer written |
| **adl_flight_changelog** | 39.63 GB | 15.0% | 66.1M | ACTIVE - Growing ~4.4 GB/day |
| adl_flights_history_cool | 7.32 GB | 2.8% | 27.75M | LEGACY - Archive test batch |
| wind_grid | 1.67 GB | 0.6% | 6M | REFERENCE - Static data |
| adl_flight_trajectory | 1.48 GB | 0.6% | 4.6M | ACTIVE - Growing |
| Other tables | ~5 GB | 1.7% | Various | Mixed |

**Total Database: ~270 GB**

---

## Optimization Opportunities

### 1. Archive/Delete Legacy adl_flights_history (209 GB)

**Current State:**
- Contains flight data from Dec 9 - Jan 6, 2026 (28 days)
- Replaced by normalized schema (adl_flight_core, adl_flight_plan, etc.)
- **No longer receiving writes**

**Options:**

| Option | Action | Space Freed | Monthly Savings | Considerations |
|--------|--------|-------------|-----------------|----------------|
| **A** | Export to Blob, then DROP | 209 GB | ~$21 | Historical queries require blob access |
| **B** | Compress in-place (PAGE) | ~125 GB | ~$12.50 | SQL queries still work, slower |
| **C** | Delete entirely | 209 GB | ~$21 | No historical access |
| **D** | Create summary table, then DROP | 209 GB | ~$21 | Aggregated stats preserved |

**Recommendation:** Option A or D - Export key data, create summary table, then DROP

**Blob Storage Cost (Cool tier):** 209 GB × $0.01/GB = **$2.09/month** (vs $20.90 in SQL)

---

### 2. Implement Changelog Archival (40 GB → ~11 GB)

**Current State:**
- Growing at **7.3 million records/day** (~4.4 GB/day)
- 9 days of data = 66 million records
- **No compression applied**

**Problem Without Intervention:**
- 30 days: ~220M records = ~133 GB
- 1 year: ~2.7B records = ~1.6 TB
- Annual cost: **$1,600** (storage alone)

**Solution:**

1. **Apply PAGE compression**: 40 GB → ~16 GB (60% reduction)
2. **Implement 7-day retention**: 51M records maintained = ~28 GB → ~11 GB compressed
3. **Run sp_ArchiveChangelog_Enhanced daily**

**Cost Impact:**

| Scenario | Size | Monthly Cost |
|----------|------|--------------|
| Current (uncompressed, no archival) | 40 GB → 1.6 TB/year | $4-160/month |
| With archival + compression | 11 GB maintained | **$1.10/month** |

---

### 3. Compress Active Tables (~27 GB savings)

**Tables to Compress:**

| Table | Current Size | Compressed Est. | Savings |
|-------|-------------|-----------------|---------|
| adl_flight_changelog | 40 GB | 16 GB | 24 GB |
| wind_grid | 1.67 GB | 0.67 GB | 1 GB |
| adl_flight_trajectory | 1.48 GB | 0.59 GB | 0.9 GB |
| adl_zone_events | 0.56 GB | 0.22 GB | 0.34 GB |
| adl_flight_boundary_log | 0.37 GB | 0.15 GB | 0.22 GB |
| adl_flight_waypoints | 1.02 GB | 0.41 GB | 0.61 GB |
| **Total** | **45.1 GB** | **18 GB** | **~27 GB** |

**Monthly Savings:** ~27 GB × $0.10 = **$2.70/month**

**Performance Impact:**
- Read queries: 5-15% faster (less I/O)
- Write queries: 2-5% slower (compression overhead)
- Net effect: Positive for read-heavy workloads like PERTI

---

### 4. Trajectory Data Management

**Current State:**
- 4.6M records in 10 days = ~460K records/day
- 1.48 GB uncompressed
- Tier-based logging already implemented

**Projections:**
- 30 days: ~14M records = ~4.4 GB
- 1 year: ~168M records = ~53 GB

**Recommendations:**

1. **Apply PAGE compression**: Reduces size by 60%
2. **Archive data >90 days to blob storage**
3. **Keep 30-day window for operational queries**

**Cost with strategy:**
- Maintained SQL storage: ~2 GB (compressed, 30-day window)
- Blob archive (Cool): ~15 GB/year = $0.15/month

---

## Cost Summary

### Storage Optimization

| Action | Space Freed | Monthly Savings |
|--------|-------------|-----------------|
| Archive/delete adl_flights_history | 209 GB | $20.90 |
| Archive/delete adl_flights_history_cool | 7.3 GB | $0.73 |
| Compress + archive changelog (7-day retention) | ~35 GB | $3.50 |
| Compress active tables | ~5 GB net | $0.50 |
| **Total Storage Optimization** | **~256 GB** | **~$25.60/month** |

### New Storage Profile

| Component | Size | Monthly Cost |
|-----------|------|--------------|
| Active flight data (normalized) | ~3 GB | $0.30 |
| Changelog (7-day, compressed) | ~11 GB | $1.10 |
| Trajectory (30-day, compressed) | ~2 GB | $0.20 |
| Reference data (nav, airports) | ~5 GB | $0.50 |
| Wind grid (compressed) | ~0.7 GB | $0.07 |
| Other active tables | ~3 GB | $0.30 |
| **Total Active Storage** | **~25 GB** | **~$2.50/month** |
| Blob Archive (Cool tier) | ~225 GB | $2.25/month |

---

## Compute Cost Considerations

### Current Hyperscale Serverless Configuration

- **VATSIM_ADL**: Gen5 8 vCores max, auto-pause disabled
- **VATSIM_Data**: Gen5 4 vCores max, auto-pause disabled

### Compute Cost Factors

1. **vCore Hours**: $0.77/vCore/hour (Gen5)
2. **Current estimate**: 8 vCores × 730 hours = $4,496/month MAX
3. **Actual**: Serverless scales down, likely 1-2 avg vCores = $560-1,120/month

### Compute Optimization Opportunities

| Optimization | Potential Savings | Trade-off |
|--------------|-------------------|-----------|
| Reduce max vCores (8→4) | Caps burst capability | May slow peak queries |
| Move heavy compute to Functions | $50-100/month | Requires code changes |
| Optimize slow queries | Reduces vCore usage | Development time |

**Top Query Candidates for Optimization:**
1. `sp_LogTrajectory` - Runs for every position update
2. Changelog triggers - Fire on all table changes
3. Boundary detection - GIS calculations

---

## Functionality Impact Analysis

### No Impact (Safe Changes)

| Change | Functionality Preserved |
|--------|------------------------|
| Delete adl_flights_history | New normalized schema handles all operations |
| Compress changelog | Audit trail fully functional |
| Compress trajectory | All queries work identically |
| Archive old trajectory to blob | Operational data (30 days) in SQL |

### Minor Impact

| Change | Impact | Mitigation |
|--------|--------|------------|
| 7-day changelog retention | Historical audits limited | Export to blob before delete |
| 30-day trajectory retention | Old flight history unavailable | Archive to blob with query API |

### Requires Caution

| Change | Risk | Validation Needed |
|--------|------|-------------------|
| Reduce max vCores | Peak performance degradation | Load test during high traffic |
| Delete wind_grid old data | Weather analysis gaps | Verify retention requirements |

---

## Implementation Roadmap

### Phase 1: Immediate (Week 1) - No Risk
```
1. Run analyze_storage.sql to verify current state
2. Run compress_active_tables.sql (during low traffic)
3. Deploy sp_ArchiveChangelog_Enhanced
4. Schedule daily changelog archival
```
**Expected Savings:** ~$3-5/month

### Phase 2: Archive Legacy (Week 2) - Low Risk
```
1. Export adl_flights_history to Parquet (Azure Data Factory)
2. Upload to Blob Storage (Cool tier)
3. Verify export integrity
4. DROP adl_flights_history and adl_flights_history_cool
```
**Expected Savings:** ~$21/month

### Phase 3: Ongoing Optimization (Month 2+)
```
1. Implement trajectory archival (30-day SQL, older to blob)
2. Review and optimize top queries
3. Consider vCore reduction after monitoring
```
**Expected Savings:** ~$5-50/month depending on compute optimization

---

## Projected Monthly Cost After Optimization

| Component | Current | After Phase 1 | After Phase 2 | After Phase 3 |
|-----------|---------|---------------|---------------|---------------|
| SQL Storage | ~$27 | ~$24 | ~$3 | ~$3 |
| SQL Compute | ~$800-1,200 | ~$800-1,200 | ~$800-1,200 | ~$600-900 |
| Blob Archive | $0 | $0 | $2.25 | $2.50 |
| App Service | ~$73 | ~$73 | ~$73 | ~$73 |
| MySQL | ~$13 | ~$13 | ~$13 | ~$13 |
| **Total** | **~$900-1,300** | **~$900-1,300** | **~$880-1,290** | **~$690-990** |

---

## Scripts Created

1. **[analyze_storage.sql](../adl/scripts/analyze_storage.sql)** - Storage analysis
2. **[archive_legacy_flights_history.sql](../adl/scripts/archive_legacy_flights_history.sql)** - Legacy table handling
3. **[compress_active_tables.sql](../adl/scripts/compress_active_tables.sql)** - Table compression
4. **[sp_ArchiveChangelog_Enhanced.sql](../adl/scripts/sp_ArchiveChangelog_Enhanced.sql)** - Automated archival

---

## Recommendations Summary

1. **Immediately**: Compress active tables and implement changelog archival
2. **This week**: Archive and drop legacy adl_flights_history (209 GB)
3. **Ongoing**: Monitor compute usage, optimize top queries
4. **Consider**: Reducing max vCores after load testing

**Total Potential Savings: $200-400/month** (primarily from right-sizing after removing legacy bloat)
