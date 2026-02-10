# VATSIM_TMI Azure SQL Cost Analysis

**Version:** 1.0  
**Date:** January 17, 2026  
**Server:** vatsim.database.windows.net

---

## 1. Executive Summary

The VATSIM_TMI database is designed for **low-volume, bursty workloads** typical of Traffic Management operations. Unlike VATSIM_ADL (which processes 2,000-6,000 flights every 15 seconds), TMI data is created manually through:
- Discord commands (~100-500 entries/day)
- TypeForm submissions (~10-50/day)
- PERTI web interface (~50-200 operations/day)
- Automated expiration jobs (1/minute)

**Recommended Tier:** Azure SQL Basic ($4.99/month)

---

## 2. Pricing Tiers Comparison

### 2.1 Single Database Options

| Tier | DTUs | Storage | Monthly Cost | Best For |
|------|------|---------|--------------|----------|
| **Basic** | 5 | 2 GB | **$4.99** | Low-volume, sporadic access |
| Standard S0 | 10 | 250 GB | $15.03 | Light consistent workloads |
| Standard S1 | 20 | 250 GB | $30.05 | Moderate workloads |
| Standard S2 | 50 | 250 GB | $75.13 | Higher throughput |
| Serverless | Auto | 32 GB | $0.50/hr active + $0.000145/vCore-sec | Variable, unpredictable |

### 2.2 Elastic Pool Option (Multi-DB)

If combining VATSIM_ADL, SWIM_API, and VATSIM_TMI:

| Pool | eDTUs | Storage | Monthly Cost | Per-DB Cost |
|------|-------|---------|--------------|-------------|
| Basic 50 eDTU | 50 | 5 GB | $74.78 | ~$25/db |
| Standard 50 eDTU | 50 | 50 GB | $112.52 | ~$37/db |
| Standard 100 eDTU | 100 | 100 GB | $225.04 | ~$75/db |

**Note:** Elastic Pools make sense only if total DTU needs exceed individual Basic/S0 limits significantly.

---

## 3. Usage Scenarios & Cost Estimates

### 3.1 Scenario: Light Usage (Typical Day)

**Assumptions:**
- 100 NTML entries/day (10 concurrent users max)
- 20 advisories/day
- 5 GDT programs/day (each with ~200 slots)
- 10 reroutes with ~50 flights each
- 50 public route updates/day
- ~500 audit log entries/day

**Workload Profile:**
- Peak: 5-10 concurrent connections
- Queries/hour: ~500 reads, ~100 writes
- DTU consumption: **<5 DTUs** (within Basic tier)

**Monthly Cost:** **$4.99** (Basic tier)

---

### 3.2 Scenario: Moderate Usage (Busy Event Day)

**Assumptions:**
- 500 NTML entries/day
- 100 advisories/day
- 20 GDT programs (with GDP simulations)
- 50 reroutes with ~200 flights each
- 200 public route updates/day
- ~5,000 audit log entries/day

**Workload Profile:**
- Peak: 20-30 concurrent connections
- Queries/hour: ~2,000 reads, ~500 writes
- DTU consumption: **5-10 DTUs** (Standard S0 recommended)

**Monthly Cost:** **$15.03** (Standard S0)

---

### 3.3 Scenario: Heavy Usage (CTP / FNO Event)

**Assumptions:**
- 2,000 NTML entries/day
- 500 advisories/day
- 50 GDT programs with full simulations
- 200 reroutes with ~500 flights each
- 1,000 public route operations/day
- GDP slot allocations: 10,000+ slots/day
- ~50,000 audit log entries/day

**Workload Profile:**
- Peak: 50-100 concurrent connections
- Queries/hour: ~10,000 reads, ~2,000 writes
- DTU consumption: **20-50 DTUs** (Standard S1/S2)

**Monthly Cost:** **$30-75** (Standard S1/S2, scale up temporarily)

---

### 3.4 Scenario: Serverless (Pay-per-Use)

**Serverless Pricing (Gen5, 1-2 vCores):**
- vCore/second: $0.000145
- Storage/GB/month: $0.115
- Minimum: 0.5 vCores when active
- Auto-pause after 1 hour idle

**Cost Calculation (Light Usage):**

| Activity | Hours/Day | vCores | Daily Cost |
|----------|-----------|--------|------------|
| Active queries | 4 hrs | 0.5 | $1.04 |
| Idle (auto-pause) | 20 hrs | 0 | $0.00 |
| Storage (5 GB) | - | - | $0.02/day |
| **Daily Total** | | | **$1.06** |
| **Monthly Total** | | | **~$32** |

**Verdict:** Serverless is **more expensive** than Basic for predictable low-volume workloads. Only use if you have extremely sporadic usage (hours of complete inactivity).

---

## 4. Storage Growth Projection

### 4.1 Estimated Data Volume Per Year

| Table | Records/Day | Avg Size/Record | Daily Growth | Annual Growth |
|-------|-------------|-----------------|--------------|---------------|
| tmi_entries | 200 | 2 KB | 400 KB | 146 MB |
| tmi_advisories | 50 | 4 KB | 200 KB | 73 MB |
| tmi_programs | 10 | 3 KB | 30 KB | 11 MB |
| tmi_slots | 1,000 | 0.5 KB | 500 KB | 183 MB |
| tmi_reroutes | 20 | 5 KB | 100 KB | 37 MB |
| tmi_reroute_flights | 500 | 1 KB | 500 KB | 183 MB |
| tmi_reroute_compliance_log | 2,000 | 0.3 KB | 600 KB | 219 MB |
| tmi_public_routes | 30 | 3 KB | 90 KB | 33 MB |
| tmi_events | 2,000 | 0.5 KB | 1 MB | 365 MB |
| **Total** | | | **~3.4 MB/day** | **~1.25 GB/year** |

**5-Year Projection:** ~6-7 GB total (well within 2 GB Basic or 250 GB Standard)

### 4.2 Storage Cost

- Basic tier includes 2 GB (sufficient for 1+ year)
- Standard tiers include 250 GB (sufficient indefinitely)
- Extra storage: $0.115/GB/month

---

## 5. Cost Optimization Strategies

### 5.1 Use Basic Tier (Recommended)

For typical VATSIM operations, **Basic ($4.99/mo)** is sufficient:
- TMI operations are bursty but low-volume
- Most queries are simple lookups (entry by ID, active entries)
- GDT simulations are infrequent and short-lived
- 2 GB storage is plenty for 1+ year

### 5.2 Temporary Scale-Up for Events

For major events (CTP, FNO):
1. Scale to Standard S1/S2 before event via Azure Portal
2. Run event (typically 4-8 hours)
3. Scale back to Basic after event

**Event Day Cost:** ~$2-5 (pro-rated Standard pricing)

### 5.3 Index Optimization

Pre-built indexes in the schema target common query patterns:
- Active entries lookup: `IX_entries_active`
- Status/time filtering: `IX_entries_status`
- Discord sync: `IX_entries_discord`
- Program lookup: `IX_programs_active`

### 5.4 Connection Pooling

The `connect.php` uses `ConnectionPooling => 1` which:
- Reuses connections across requests
- Reduces connection overhead
- Improves DTU efficiency

### 5.5 Batch Operations

For GDT slot generation and flight assignments:
- Use stored procedures (single round-trip)
- Batch inserts (50-100 rows per statement)
- Use transactions to reduce log writes

---

## 6. Elastic Pool Consideration

### 6.1 Current Database Architecture (Updated February 2026)

```
vatsim.database.windows.net
├── VATSIM_ADL    - Flight data (Hyperscale Serverless 3/16 vCores, ~$3,200/mo)
├── VATSIM_REF    - Reference data (Basic, ~$5/mo)
├── SWIM_API      - Public API (Basic, ~$5/mo)
└── VATSIM_TMI    - TMI data (Basic, ~$5/mo)
```

### 6.2 Elastic Pool vs. Individual DBs

| Option | Configuration | Monthly Cost | Notes |
|--------|---------------|--------------|-------|
| **Individual DBs (current)** | ADL (Hyperscale) + REF/SWIM/TMI (Basic) | ~$3,215 | ADL on Hyperscale, others Basic |
| Elastic Pool | Not applicable | N/A | ADL uses Hyperscale (not DTU-based) |

**Note:** VATSIM_ADL was migrated from General Purpose Serverless to Hyperscale Serverless in December 2025. Elastic Pools are not compatible with Hyperscale databases. The Basic-tier databases (REF, SWIM, TMI) at $5/mo each are already cost-optimal for their workloads.

**Recommendation:** Keep individual databases. VATSIM_ADL requires Hyperscale for its workload and cannot participate in Elastic Pools.

---

## 7. Monitoring & Alerts

### 7.1 Key Metrics to Monitor

| Metric | Basic Threshold | Alert Level |
|--------|-----------------|-------------|
| DTU % | > 80% sustained | Scale up |
| Storage % | > 80% | Add storage or purge |
| Connection failures | > 5/hour | Investigate |
| Query latency | > 500ms avg | Optimize queries |

### 7.2 Azure Monitor Setup

```sql
-- Enable Query Store (for performance insights)
ALTER DATABASE VATSIM_TMI SET QUERY_STORE = ON;

-- View DTU consumption
SELECT 
    end_time,
    avg_cpu_percent,
    avg_data_io_percent,
    avg_log_write_percent,
    max_worker_percent,
    max_session_percent
FROM sys.dm_db_resource_stats
ORDER BY end_time DESC;
```

---

## 8. Cost Summary & Recommendation

### 8.1 Typical Monthly Costs

| Component | Configuration | Monthly Cost |
|-----------|---------------|--------------|
| VATSIM_TMI Database | Basic (5 DTU) | $4.99 |
| Backup Storage | LRS (included) | $0.00 |
| Monitoring | Basic (free tier) | $0.00 |
| **Total** | | **$4.99** |

### 8.2 Event Month Costs (1 CTP + 2 FNOs)

| Component | Configuration | Monthly Cost |
|-----------|---------------|--------------|
| VATSIM_TMI Database | Basic (baseline) | $4.99 |
| Scale-up events (3×8 hrs) | S1 pro-rated | $2.40 |
| **Total** | | **$7.39** |

### 8.3 Annual Cost Projection

| Scenario | Monthly | Annual |
|----------|---------|--------|
| Light usage | $4.99 | $59.88 |
| With events | $6-8 | $72-96 |
| Growth buffer | +$1 | +$12 |
| **Total (conservative)** | | **$85-110/year** |

---

## 9. Quick Start Commands

### 9.1 Create Database (Azure CLI)

```bash
az sql db create \
  --resource-group vatcscc-rg \
  --server vatsim \
  --name VATSIM_TMI \
  --service-objective Basic
```

### 9.2 Scale Up for Event

```bash
az sql db update \
  --resource-group vatcscc-rg \
  --server vatsim \
  --name VATSIM_TMI \
  --service-objective S1
```

### 9.3 Scale Down After Event

```bash
az sql db update \
  --resource-group vatcscc-rg \
  --server vatsim \
  --name VATSIM_TMI \
  --service-objective Basic
```

---

## 10. Conclusion

**Recommended Configuration:**
- **Tier:** Azure SQL Basic ($4.99/mo)
- **Strategy:** Scale up temporarily for major events
- **Expected Annual Cost:** $60-110

The TMI workload is well-suited for the Basic tier due to:
1. Low concurrent connections (typically <10)
2. Simple queries (mostly lookups and inserts)
3. Bursty but predictable traffic patterns
4. Small data footprint (~1 GB/year)

Serverless is **not recommended** as it costs 6x more than Basic for this workload pattern.

---

*Last Updated: February 10, 2026*
