# PERTI/SWIM Cost Optimization Guide

> **Last Updated:** February 10, 2026

## Current Infrastructure

| Component | Tier | Cost/Month |
|-----------|------|------------|
| **VATSIM_ADL** | **Hyperscale Serverless (3/16 vCores)** | **~$3,200** |
| SWIM_API | Basic (5 DTU, 2GB) | ~$5 |
| VATSIM_REF | Basic (5 DTU, 2GB) | ~$5 |
| VATSIM_TMI | Basic (5 DTU, 2GB) | ~$5 |
| VATSIM_STATS | GP Serverless (paused) | ~$0 (paused) |
| MySQL (perti_site) | General Purpose D2ds_v4 | ~$134 |
| PostgreSQL (GIS) | Burstable B2s, PostGIS | ~$58 |
| App Service | P1v2 (3.5GB, 1 vCPU) | ~$81 |
| **Total** | | **~$3,500/month** |

**Cost Trend (4-month actual from Azure Cost Management):**

| Month | Total | SQL | App Service | MySQL | PostgreSQL |
|-------|-------|-----|-------------|-------|------------|
| Oct 2025 | $684 | $536 | $73 | $15 | - |
| Nov 2025 | $670 | $524 | $73 | $15 | - |
| Dec 2025 | $2,172 | $2,020 | $73 | $21 | $6 |
| Jan 2026 | $3,640 | $3,479 | $73 | $50 | $19 |

*Dec 2025 increase: VATSIM_ADL migrated from GP Serverless to Hyperscale Serverless. MySQL upgraded from Burstable B1ms to General Purpose D2ds_v4. PostgreSQL GIS deployed.*

### VATSIM_ADL Configuration (Updated January 21, 2026)

| Setting | Value | Notes |
|---------|-------|-------|
| SKU | HS_S_Gen5_16 | Hyperscale Serverless |
| Min vCores | 3 | Auto-pause disabled for production |
| Max vCores | 16 | Reduced from 24 (Jan 21, 2026) |
| Max Workers | 1,200 | Sufficient for peak events (558 observed) |
| HA Replicas | 1 | High availability replica |
| Storage | ~270 GB | Legacy data pending archival |

**Recent Optimization:** Reduced from 4/24 vCores to 3/16 vCores based on actual usage analysis. Peak worker utilization during major VATSIM events (Jan 16, 2026) was 558 workers (46.5% of 1,200 capacity), providing 54% headroom. **Savings: ~$1,140/month (~$13,700/year)**

### Decommissioned Resources
- **vatsim-georeplica** server: Deleted (was ~$411/mo)
- **VATSIM_Data** database: Deleted (was Hyperscale HS_S_Gen5_4)

---

## Scaling Options (Cheapest First)

### Option 1: Reserved Instances (30-50% savings)

**No code changes required. Immediate savings.**

| Commitment | P1v2 Savings | New Monthly Cost |
|------------|--------------|------------------|
| 1-year | ~30% | ~$57/month |
| 3-year | ~50% | ~$41/month |

How to enable:
1. Azure Portal → Reservations → Add
2. Select "App Service" → P1v2 → Central US
3. Choose 1-year or 3-year term

**Savings: $24-40/month ($288-480/year)**

---

### Option 2: Azure CDN for SWIM API (~$5-10/month at scale)

Offload SWIM API responses to CDN. Your data refreshes every 15 seconds, so 15-30s CDN cache is safe.

**Benefits:**
- Reduces PHP-FPM worker load by 80-95%
- Reduces database load
- Improves global latency
- Handles traffic spikes without scaling App Service

**Cost:** ~$0.01-0.03/GB egress (first 10TB/month)

#### Implementation

1. **Create Azure CDN Profile:**
   ```bash
   az cdn profile create \
     --name perti-cdn \
     --resource-group VATSIM_RG \
     --sku Standard_Microsoft
   ```

2. **Create CDN Endpoint:**
   ```bash
   az cdn endpoint create \
     --name swim-api \
     --profile-name perti-cdn \
     --resource-group VATSIM_RG \
     --origin perti.vatcscc.org \
     --origin-host-header perti.vatcscc.org \
     --query-string-caching-behavior UseQueryString
   ```

3. **Configure caching rules:**
   - `/api/swim/v1/flights*` → Cache 15 seconds
   - `/api/swim/v1/positions*` → Cache 5 seconds
   - `/api/swim/v1/metering*` → Cache 10 seconds

4. **Update DNS:**
   - CNAME `swim.vatcscc.org` → `swim-api.azureedge.net`

---

### Option 3: Add Cache-Control Headers (FREE)

Add explicit cache headers so Azure CDN and client browsers can cache responses:

```php
// In api/swim/v1/auth.php, add to SwimResponse::json()
$cache_ttl = swim_get_cache_ttl($endpoint ?? 'default', self::$currentTier);
header("Cache-Control: public, max-age={$cache_ttl}, s-maxage={$cache_ttl}");
header("CDN-Cache-Control: public, max-age={$cache_ttl}");
```

---

### Option 4: Autoscaling (Pay only for peak)

Scale out during peak hours, scale in during off-peak.

**VATSIM peak hours:** ~1800-0200 UTC (US evening events)

```bash
# Create autoscale rule
az monitor autoscale create \
  --resource-group VATSIM_RG \
  --resource "/subscriptions/.../serverfarms/ASP-VATSIMRG-9bb6" \
  --resource-type Microsoft.Web/serverfarms \
  --name perti-autoscale \
  --min-count 1 \
  --max-count 3 \
  --count 1

# Scale up rule (CPU > 70%)
az monitor autoscale rule create \
  --resource-group VATSIM_RG \
  --autoscale-name perti-autoscale \
  --condition "CpuPercentage > 70 avg 5m" \
  --scale out 1

# Scale down rule (CPU < 30%)
az monitor autoscale rule create \
  --resource-group VATSIM_RG \
  --autoscale-name perti-autoscale \
  --condition "CpuPercentage < 30 avg 10m" \
  --scale in 1
```

**Cost:** Only pay for extra instances when needed (~$0.11/hr per P1v2)

---

### Option 5: Database Query Optimization (FREE)

Ensure these indexes exist on high-traffic tables:

```sql
-- SWIM_API.swim_flights (most queried)
CREATE INDEX IX_swim_flights_active_callsign
ON dbo.swim_flights (is_active, callsign)
INCLUDE (lat, lon, altitude_ft, groundspeed_kts);

CREATE INDEX IX_swim_flights_dept_dest
ON dbo.swim_flights (fp_dept_icao, fp_dest_icao)
WHERE is_active = 1;

CREATE INDEX IX_swim_flights_artcc
ON dbo.swim_flights (fp_dest_artcc)
WHERE is_active = 1;
```

---

## Scaling Scenarios

### Scenario A: 10x Current Traffic (Basic SWIM adoption)
- **Solution:** CDN only
- **Cost increase:** ~$5-10/month
- **Total:** ~$105-150/month

### Scenario B: 100x Current Traffic (Full SWIM adoption)
- **Solution:** CDN + Autoscale (1-3 instances) + Reserved Instance
- **Cost increase:** ~$20-60/month
- **Total:** ~$80-120/month (with 1-year RI)

### Scenario C: 1000x Current Traffic (Heavy external integrations)
- **Solution:** CDN + P2v2 + Autoscale (1-5 instances) + Reserved Instance
- **Cost increase:** ~$100-300/month
- **Total:** ~$200-400/month

---

## Traffic Estimation

With current architecture (40 PHP-FPM workers, 15s data refresh):

| Metric | Estimate |
|--------|----------|
| Max requests/sec (origin) | ~40-80 |
| With CDN (85% hit rate) | ~300-500 |
| With CDN (95% hit rate) | ~800-1600 |

**APCu cache hit rate:** ~80-90% (requests to same endpoint within TTL)
**CDN cache hit rate:** ~85-95% (global edge caching)

Combined effective throughput: **500-2000 req/sec** with current P1v2.

---

## Monitoring Cost

Use built-in Azure metrics (FREE):
- App Service metrics (CPU, Memory, Requests)
- CDN metrics (Hits, Misses, Bandwidth)

The `/api/system/health` endpoint provides additional insight at no cost.

---

## Recommended Order of Implementation

1. **Reserved Instance** (immediate 30% savings)
2. **Cache-Control headers** (free, enables CDN)
3. **Azure CDN** (when traffic grows)
4. **Autoscaling** (for traffic spikes)
5. **Scale up tier** (only if sustained high load)
