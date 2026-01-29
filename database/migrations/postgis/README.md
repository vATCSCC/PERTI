# VATSIM_GIS - PostGIS Spatial Database

PostgreSQL/PostGIS database for spatial boundary queries (ARTCC traversal, sector intersection, etc.).

## Files

| File | Purpose |
|------|---------|
| `000_create_database.sql` | Creates VATSIM_GIS database and GIS_admin user |
| `001_boundaries_schema.sql` | Tables, indexes, and helper functions |
| `002_extended_functions.sql` | Extended functions for TMI route analysis |
| `003_airports_table.sql` | Airports reference table for ARTCC lookups |

## Setup

```bash
# 1. Create database (as postgres superuser)
psql -U postgres -d postgres -f 000_create_database.sql

# 2. Connect to new database and run schema
psql -U postgres -d VATSIM_GIS -f 001_boundaries_schema.sql

# 3. Import GeoJSON boundaries
cd ../../scripts/postgis
pip install psycopg2-binary
python import_boundaries.py --host localhost --password YOUR_PASSWORD
```

## Environment Variables

```bash
GIS_SQL_HOST=localhost
GIS_SQL_PORT=5432
GIS_SQL_DATABASE=VATSIM_GIS
GIS_SQL_USERNAME=GIS_admin
GIS_SQL_PASSWORD=GIS_Admin_2026!
```

## Performance & Cost Estimates

### Query Performance

PostGIS with GiST spatial indexes is extremely efficient for route-polygon intersection:

| Query Type | Complexity | Expected Time |
|------------|------------|---------------|
| Route → ARTCC list | LineString vs ~300 polygons | **1-5ms** |
| Route → Sectors at altitude | LineString vs ~3000 polygons + altitude filter | **5-15ms** |
| Point-in-polygon (single) | Point vs ~300 polygons | **<1ms** |

### Load Estimate: 30 Users × 90 Routes

**Peak scenario**: 30 concurrent users, each running 90 route calculations over 3 hours

| Metric | Value |
|--------|-------|
| Total queries | 2,700 |
| Queries per hour | 900 |
| Queries per second (avg) | **0.25 QPS** |
| Burst (all 30 at once) | 30 queries × 5ms = **150ms CPU** |

**Verdict**: This is trivial load. A basic PostgreSQL instance would be ~99.9% idle.

### Hosting Cost Options

| Platform | Tier | Specs | Cost/Month |
|----------|------|-------|------------|
| Azure Database for PostgreSQL | Basic | 1 vCPU, 2GB RAM | ~$25 |
| AWS RDS PostgreSQL | db.t3.micro | 1 vCPU, 1GB RAM | ~$15 |
| DigitalOcean | Basic | 1 vCPU, 1GB RAM | ~$15 |
| Self-hosted (existing server) | - | - | **$0** |

**Recommendation**: For this workload, the smallest available tier is more than sufficient. If you already have a PostgreSQL server, just add the database there.

### Scaling Notes

If load increases significantly:
- **100 QPS**: Still fine on basic tier
- **1000 QPS**: Consider 2-4 vCPU instance (~$50-100/month)
- **10000 QPS**: Add read replicas, ~$200/month

The GiST spatial index is the key - it reduces polygon checks from O(n) to O(log n).

## Usage Example

```sql
-- Get ARTCCs traversed by a route
SELECT artcc_code, fir_name
FROM get_route_artccs_from_waypoints('[
    {"lon": -73.78, "lat": 40.64},
    {"lon": -118.41, "lat": 33.94}
]'::jsonb);

-- Result: ZNY, ZDC, ZTL, ZME, ZFW, ZAB, ZLA
```

## Data Sources

- `artcc.json` - VATSIM FIR boundaries (8.9 MB, ~300 features)
- `high.json` - High altitude sectors (~2000 features)
- `low.json` - Low altitude sectors (~2500 features)
- `superhigh.json` - Super-high sectors (~1500 features)
- `tracon.json` - TRACON boundaries (~200 features)
