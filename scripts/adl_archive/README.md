# ADL Raw Data Lake Archive Scripts

This directory contains scripts for implementing the ADL Raw Data Lake architecture as documented in `docs/plans/2026-02-02-adl-raw-data-lake-design.md`.

## Architecture Overview

```
VATSIM_ADL (Hot Tier, 7 days)
    → Daily Archive Job
        → Azure Blob Storage (Parquet)
            → Cool Tier (0-365 days)
            → Archive Tier (365+ days)
                → Synapse Serverless (Query Layer)
```

## Scripts

| Script | Purpose |
|--------|---------|
| `setup_infrastructure.ps1` | Create Azure Storage account, container, lifecycle policy |
| `backfill_trajectory.py` | One-time migration of existing trajectory data to Parquet |
| `daily_archive.py` | Daily job to archive previous day's data (for Azure Function) |
| `query_archive.py` | Utility to query archived Parquet data via Synapse |
| `rehydrate.py` | Utility to rehydrate Archive tier data for querying |

## Setup

### Prerequisites

- Python 3.10+
- Azure CLI installed and authenticated (`az login`)
- Azure subscription with appropriate permissions
- Access to VATSIM_ADL database

### Installation

```bash
cd scripts/adl_archive
pip install -r requirements.txt
```

### Azure Infrastructure Setup

```powershell
# Run from project root
.\scripts\adl_archive\setup_infrastructure.ps1
```

This creates:
- Storage account: `pertiadlarchive`
- Container: `adl-raw-archive`
- Lifecycle policy: Cool at 8 days, Archive at 365 days

### Backfill Historical Data

```bash
# Dry run - show what would be migrated
python backfill_trajectory.py --dry-run

# Backfill last 30 days (validation run)
python backfill_trajectory.py --days 30

# Backfill all historical data
python backfill_trajectory.py --all
```

### Deploy Azure Function (Daily Archive)

The `function_app/` directory contains an Azure Function with timer triggers:

- **Daily**: Runs at 04:00 UTC to archive the previous day's data
- **Weekly catch-up**: Runs Sundays at 05:00 UTC to fill any gaps

```powershell
# Install Azure Functions Core Tools
npm install -g azure-functions-core-tools@4

# Test locally
cd scripts/adl_archive/function_app
cp local.settings.json.template local.settings.json
# Edit local.settings.json with your storage connection string
func start

# Deploy to Azure
func azure functionapp publish <your-function-app-name> --python
```

Required App Settings:

- `ADL_ARCHIVE_STORAGE_CONN`: Storage connection string from setup_infrastructure.ps1

## Cost Estimates

| Component | Year 1/mo | Year 10/mo | Year 100/mo |
|-----------|-----------|------------|-------------|
| Cool Storage | $2.40 | $16.00 | $40.00 |
| Archive Storage | $0 | $16.00 | $280.00 |
| Synapse Queries | $5.00 | $5.00 | $10.00 |
| **Total** | **~$11** | **~$38** | **~$331** |

## File Format

All archive data is stored as Parquet with ZSTD compression:

```
adl-raw-archive/
├── trajectory/
│   └── year=YYYY/month=MM/day=DD/*.parquet
├── changelog/
│   └── year=YYYY/month=MM/day=DD/*.parquet
├── flights/
│   └── year=YYYY/month=MM/day=DD/*.parquet
└── ...
```

## Trajectory Schema (Denormalized)

```
flight_uid: int64
callsign: string          # Denormalized from adl_flight_core
dept_icao: string         # Denormalized from adl_flight_core
dest_icao: string         # Denormalized from adl_flight_core
timestamp_utc: timestamp
lat: double
lon: double
altitude_ft: int32
groundspeed_kts: int32
heading_deg: int32 (optional)
vertical_rate_fpm: int32 (optional)
```

## Related Documentation

- [ADL Raw Data Lake Design](../../docs/plans/2026-02-02-adl-raw-data-lake-design.md)
- [Credentials](.claude/credentials.md)
