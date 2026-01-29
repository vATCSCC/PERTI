# AIRAC Update

This guide covers the complete AIRAC (Aeronautical Information Regulation And Control) update process for PERTI navigation data. AIRAC cycles occur every 28 days and require updating navigation fixes, airways, procedures, and routes.

---

## Quick Start

For most AIRAC updates, run the single master script from the project root:

```bash
python airac_full_update.py
```

This performs all three steps automatically. See [[#Full Workflow]] for details.

---

## Overview

### What Gets Updated

| Data Type | Source | CSV File | Database Table |
|-----------|--------|----------|----------------|
| Waypoints | FAA NASR | `points.csv` | `nav_fixes` |
| Navaids (VOR/NDB) | FAA NASR | `navaids.csv` | `nav_fixes` |
| Airways | FAA NASR | `awys.csv` | `airways` |
| CDRs | FAA NASR | `cdrs.csv` | `coded_departure_routes` |
| DPs | FAA NASR | `dp_full_routes.csv` | `nav_procedures` |
| STARs | FAA NASR | `star_full_routes.csv` | `nav_procedures` |
| Playbook Routes | FAA Playbook | `playbook_routes.csv` | `playbook_routes` |

### Data Flow

```
FAA NASR Subscription    FAA Playbook
     (nfdc.faa.gov)     (fly.faa.gov)
           |                  |
           v                  v
    nasr_navdata_updater.py   update_playbook_routes.py
           |                  |
           v                  v
    assets/data/*.csv    assets/data/playbook_routes.csv
           |                  |
           +--------+---------+
                    |
                    v
            airac_update.py
                    |
                    v
            VATSIM_REF (Azure SQL)
                    |
                    v
            VATSIM_ADL (Azure SQL)
                    |
                    v
            sp_ParseRoute (uses cached data)
```

### Database Architecture

PERTI uses a two-database architecture for navigation reference data:

| Database | Purpose | Tier |
|----------|---------|------|
| **VATSIM_REF** | Authoritative source for static navigation data | Azure SQL Basic |
| **VATSIM_ADL** | Runtime cache used by `sp_ParseRoute` and other procedures | Azure SQL Standard |

The separation allows:
- Cost-effective storage of large reference datasets
- Independent scaling of operational databases
- Clean separation between reference data and operational data

---

## Full Workflow

### Step 1: FAA NASR Data Download

Downloads current and next AIRAC cycle data from the FAA NASR 28-day subscription.

**Script:** `nasr_navdata_updater.py`

**Source:** `https://nfdc.faa.gov/webContent/28DaySub/`

**Updates:**
- `assets/data/points.csv` (~270K waypoints)
- `assets/data/navaids.csv` (~3K VOR/NDB/DME)
- `assets/data/awys.csv` (~1.5K airways)
- `assets/data/cdrs.csv` (~44K coded departure routes)
- `assets/data/dp_full_routes.csv` (~50K departure procedures)
- `assets/data/star_full_routes.csv` (~50K arrival procedures)
- `assets/js/awys.js` (JavaScript airway data for web)
- `assets/js/procs.js` (JavaScript procedure data for web)

**Manual execution:**
```bash
python nasr_navdata_updater.py
python nasr_navdata_updater.py --force   # Force re-download even if cached
```

### Step 2: FAA Playbook Routes Scrape

Scrapes current playbook routes from the FAA Traffic Flow Management website.

**Script:** `scripts/update_playbook_routes.py`

**Source:** `https://www.fly.faa.gov/playbook/`

**Updates:**
- `assets/data/playbook_routes.csv` (~56K route entries)

**Features:**
- Preserves old route versions with `_old_YYMM` suffix when routes change
- Generates full route expansions including origin/destination metadata
- Includes TRACON and ARTCC filters for each play

**Manual execution:**
```bash
python scripts/update_playbook_routes.py
python scripts/update_playbook_routes.py --dry-run   # Preview changes
python scripts/update_playbook_routes.py --verbose   # Detailed logging
```

### Step 3: Database Import and Sync

Imports CSV data to VATSIM_REF and syncs to VATSIM_ADL cache.

**Script:** `adl/scripts/airac_update.py`

**Tables imported to VATSIM_REF:**

| Table | Source CSV | Typical Rows |
|-------|------------|--------------|
| `nav_fixes` | points.csv + navaids.csv | ~270,000 |
| `airways` | awys.csv | ~1,500 |
| `coded_departure_routes` | cdrs.csv | ~44,000 |
| `nav_procedures` | dp_full_routes.csv + star_full_routes.csv | ~100,000 |
| `playbook_routes` | playbook_routes.csv | ~56,000 |

**Manual execution:**
```bash
python adl/scripts/airac_update.py
python adl/scripts/airac_update.py --table nav_fixes   # Single table
python adl/scripts/airac_update.py --sync-only         # Only REF -> ADL sync
python adl/scripts/airac_update.py --skip-sync         # Import only, no sync
python adl/scripts/airac_update.py --dry-run           # Preview
```

---

## Master Script

The `airac_full_update.py` script orchestrates all three steps.

**Location:** Project root

**Usage:**
```bash
# Full update (all steps)
python airac_full_update.py

# Preview what would happen (no changes)
python airac_full_update.py --dry-run

# Run individual steps
python airac_full_update.py --step 1   # NASR only
python airac_full_update.py --step 2   # Playbook only
python airac_full_update.py --step 3   # Database only

# Skip optional steps
python airac_full_update.py --skip-playbook   # Faster if playbook unchanged
python airac_full_update.py --skip-database   # Update CSVs only

# Force NASR re-download
python airac_full_update.py --force

# Import specific table only
python airac_full_update.py --step 3 --table nav_fixes
```

**Output example:**
```
======================================================================
            AIRAC FULL UPDATE - Master Script
======================================================================

  Started: 2026-01-29 14:30:00

======================================================================
  STEP 1: FAA NASR NavData Update
======================================================================
  Downloads current + next AIRAC cycle data from FAA NASR
  ...

======================================================================
  STEP 2: FAA Playbook Routes Update
======================================================================
  Scrapes current routes from fly.faa.gov/playbook
  ...

======================================================================
  STEP 3: Database Import & Sync
======================================================================
  Imports CSV data to VATSIM_REF (authoritative)
  Syncs to VATSIM_ADL (cache for sp_ParseRoute)
  ...

======================================================================
                         AIRAC UPDATE COMPLETE
======================================================================

  Results:
    [+] NASR Update: SUCCESS
    [+] Playbook Update: SUCCESS
    [+] Database Import: SUCCESS

  Duration: 8m 42s
  Finished: 2026-01-29 14:38:42
```

---

## Database Schema

### VATSIM_REF Tables

#### nav_fixes
Navigation waypoints including VORs, NDBs, and fixes.

| Column | Type | Description |
|--------|------|-------------|
| `fix_id` | INT | Primary key (identity) |
| `fix_name` | NVARCHAR(16) | Fix identifier (e.g., MERIT, JFK) |
| `fix_type` | NVARCHAR(16) | WAYPOINT, VOR, NDB, AIRPORT, DME, TACAN |
| `lat` | DECIMAL(10,7) | Latitude |
| `lon` | DECIMAL(11,7) | Longitude |
| `artcc_id` | NVARCHAR(4) | Owning ARTCC |
| `source` | NVARCHAR(32) | Data source (NASR) |
| `effective_date` | DATE | AIRAC effective date |

#### airways
Airway definitions with fix sequences.

| Column | Type | Description |
|--------|------|-------------|
| `airway_id` | INT | Primary key (identity) |
| `airway_name` | NVARCHAR(8) | Airway name (J60, V1, Q100) |
| `airway_type` | NVARCHAR(16) | JET, VICTOR, RNAV, LOW, HIGH |
| `fix_sequence` | NVARCHAR(MAX) | Space-delimited fix list |
| `fix_count` | INT | Number of fixes |
| `start_fix` | NVARCHAR(16) | First fix |
| `end_fix` | NVARCHAR(16) | Last fix |

#### coded_departure_routes
CDR expansions for common city pairs.

| Column | Type | Description |
|--------|------|-------------|
| `cdr_id` | INT | Primary key (identity) |
| `cdr_code` | NVARCHAR(16) | CDR code (JFKMIA1) |
| `full_route` | NVARCHAR(MAX) | Expanded route string |
| `origin_icao` | CHAR(4) | Origin airport |
| `dest_icao` | CHAR(4) | Destination airport |
| `is_active` | BIT | Active flag |

#### nav_procedures
DP and STAR procedure definitions.

| Column | Type | Description |
|--------|------|-------------|
| `procedure_id` | INT | Primary key (identity) |
| `procedure_type` | NVARCHAR(8) | DP, STAR, APPROACH |
| `airport_icao` | CHAR(4) | Airport ICAO code |
| `procedure_name` | NVARCHAR(32) | Procedure name |
| `computer_code` | NVARCHAR(16) | Computer code (MERIT3) |
| `transition_name` | NVARCHAR(16) | Transition identifier |
| `full_route` | NVARCHAR(MAX) | Fix sequence |
| `runways` | NVARCHAR(64) | Applicable runways |

#### playbook_routes
FAA Playbook route expansions.

| Column | Type | Description |
|--------|------|-------------|
| `playbook_id` | INT | Primary key (identity) |
| `play_name` | NVARCHAR(64) | Play name (e.g., BURNN1_NORTH) |
| `full_route` | NVARCHAR(MAX) | Route string |
| `origin_airports` | NVARCHAR(256) | Applicable origins |
| `origin_tracons` | NVARCHAR(128) | Origin TRACON filter |
| `origin_artccs` | NVARCHAR(64) | Origin ARTCC filter |
| `dest_airports` | NVARCHAR(256) | Applicable destinations |
| `dest_tracons` | NVARCHAR(128) | Destination TRACON filter |
| `dest_artccs` | NVARCHAR(64) | Destination ARTCC filter |

#### ref_sync_log
Sync audit trail.

| Column | Type | Description |
|--------|------|-------------|
| `sync_id` | INT | Primary key |
| `sync_timestamp` | DATETIME2 | When sync occurred |
| `table_name` | NVARCHAR(64) | Table synced |
| `rows_synced` | INT | Row count |
| `sync_direction` | NVARCHAR(16) | FROM_SOURCE, TO_ADL |
| `sync_status` | NVARCHAR(16) | SUCCESS, FAILED, PARTIAL |

---

## Credentials

Database credentials are stored in the script files and should NOT be committed to public repositories.

**Required credentials:**

| Purpose | User | Location |
|---------|------|----------|
| VATSIM_REF import | API user | `adl/scripts/airac_update.py` |
| VATSIM_ADL sync | API user | `adl/scripts/airac_update.py` |

**Connection string format:**
```
Server: vatsim.database.windows.net
Database: VATSIM_REF or VATSIM_ADL
Driver: ODBC Driver 18 for SQL Server
Encrypt: yes
```

> **Security Note:** Contact a PERTI administrator for database credentials. Never commit credentials to source control.

---

## Verification

### After Import

```sql
-- Check VATSIM_REF row counts
SELECT 'nav_fixes' AS [table], COUNT(*) AS rows FROM VATSIM_REF.dbo.nav_fixes
UNION ALL
SELECT 'airways', COUNT(*) FROM VATSIM_REF.dbo.airways
UNION ALL
SELECT 'coded_departure_routes', COUNT(*) FROM VATSIM_REF.dbo.coded_departure_routes
UNION ALL
SELECT 'nav_procedures', COUNT(*) FROM VATSIM_REF.dbo.nav_procedures
UNION ALL
SELECT 'playbook_routes', COUNT(*) FROM VATSIM_REF.dbo.playbook_routes;
```

Expected results (approximate):
- nav_fixes: 270,000+
- airways: 1,500+
- coded_departure_routes: 44,000+
- nav_procedures: 100,000+
- playbook_routes: 56,000+

### After Sync

```sql
-- Check sync log
SELECT TOP 10 *
FROM VATSIM_REF.dbo.ref_sync_log
ORDER BY sync_timestamp DESC;
```

### Verify ADL Cache

```sql
-- Verify ADL has current data
SELECT 'nav_fixes' AS [table], COUNT(*) AS rows FROM VATSIM_ADL.dbo.nav_fixes
UNION ALL
SELECT 'airways', COUNT(*) FROM VATSIM_ADL.dbo.airways;
```

---

## Troubleshooting

### Import Fails with Permission Error

The API user may lack permissions for certain operations (TRUNCATE, RESEED). Contact the database administrator or use admin credentials for the import.

### String Truncation Errors

If playbook play names exceed column limits, the `play_name` column was increased to `NVARCHAR(64)`. Verify schema:

```sql
SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'playbook_routes' AND COLUMN_NAME = 'play_name';
```

### Sync Fails Due to Foreign Keys

The `airway_segments` table has a foreign key to `airways`. The sync process clears segments before airways automatically.

### NASR Download Times Out

The FAA NASR server can be slow. Use `--force` to retry:

```bash
python airac_full_update.py --step 1 --force
```

### Azure SQL Connection Issues

Ensure:
1. ODBC Driver 18 for SQL Server is installed
2. Firewall allows Azure SQL connections
3. Credentials are correct

Test connection:
```python
import pyodbc
conn = pyodbc.connect(
    "DRIVER={ODBC Driver 18 for SQL Server};"
    "SERVER=vatsim.database.windows.net;"
    "DATABASE=VATSIM_REF;"
    "UID=<username>;PWD=<password>;"
    "Encrypt=yes;TrustServerCertificate=no;"
)
```

---

## AIRAC Cycle Reference

AIRAC cycles are 28 days. The cycle identifier format is YYMM (year + cycle number 01-13).

**2026 AIRAC Dates:**
| Cycle | Effective Date |
|-------|----------------|
| 2601 | Jan 2, 2026 |
| 2602 | Jan 30, 2026 |
| 2603 | Feb 27, 2026 |
| 2604 | Mar 26, 2026 |
| 2605 | Apr 23, 2026 |
| 2606 | May 21, 2026 |
| 2607 | Jun 18, 2026 |
| 2608 | Jul 16, 2026 |
| 2609 | Aug 13, 2026 |
| 2610 | Sep 10, 2026 |
| 2611 | Oct 8, 2026 |
| 2612 | Nov 5, 2026 |
| 2613 | Dec 3, 2026 |

---

## Post-Update Checklist

After running the AIRAC update:

1. **Verify import counts** - Run verification queries above
2. **Review CSV changes** - Check `git diff assets/data/`
3. **Commit changes** - `git add -A && git commit -m "Update AIRAC navdata YYMM"`
4. **Push to repository** - `git push`
5. **Deploy to production** - Follow standard deployment process
6. **Monitor route parsing** - Check `adl_parse_queue` for errors

---

## See Also

- [[Database-Schema]] - Full schema reference
- [[Algorithm-Route-Parsing]] - How routes use this data
- [[Maintenance]] - Other maintenance tasks
- [[Troubleshooting]] - General troubleshooting
