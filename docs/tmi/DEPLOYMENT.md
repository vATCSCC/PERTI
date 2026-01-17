# VATSIM_TMI Deployment Guide

**Version:** 1.1  
**Date:** January 17, 2026  
**Status:** ✅ DEPLOYMENT COMPLETE

---

## Deployment Summary

The VATSIM_TMI database and API were successfully deployed on January 17, 2026.

### Database Credentials

| Setting | Value |
|---------|-------|
| **Server** | `vatsim.database.windows.net` |
| **Database** | `VATSIM_TMI` |
| **Username** | `TMI_admin` |
| **Password** | (see config.php or contact admin) |
| **Tier** | Basic (5 DTU, 2 GB) |
| **Cost** | ~$5/month |

### Deployed Objects

| Object Type | Count | Status |
|-------------|-------|--------|
| Tables | 10 | ✅ Verified |
| Views | 6 | ✅ Verified |
| Stored Procedures | 4 | ✅ Verified |
| Indexes | 30+ | ✅ Verified |

### API Endpoints (Live)

| Endpoint | Status |
|----------|--------|
| `GET /api/tmi/` | ✅ Live |
| `GET /api/tmi/active.php` | ✅ Live |
| `GET/POST/PUT/DELETE /api/tmi/entries.php` | ✅ Live |
| `GET/POST/PUT/DELETE /api/tmi/programs.php` | ✅ Live |
| `GET/POST/PUT/DELETE /api/tmi/advisories.php` | ✅ Live |
| `GET/POST/PUT/DELETE /api/tmi/public-routes.php` | ✅ Live |
| `GET/POST/PUT/DELETE /api/tmi/reroutes.php` | ⏳ Pending |

### Files Deployed

```
api/tmi/
├── .htaccess           ✅
├── web.config          ✅
├── helpers.php         ✅
├── index.php           ✅
├── active.php          ✅
├── entries.php         ✅
├── programs.php        ✅
├── advisories.php      ✅
├── public-routes.php   ✅
└── reroutes.php        ⏳ (not yet created)

scripts/tmi/
└── verify_deployment.php  ✅

database/migrations/tmi/
├── 001_tmi_core_schema_azure_sql.sql  ✅
└── 002_create_tmi_user.sql            ✅

load/
├── config.example.php  ✅ (updated with TMI_admin)
├── config.php          ✅ (credentials added)
└── connect.php         ✅ ($conn_tmi support)
```

---

## Post-Deployment Checklist ✅

- [x] Database created on Azure (`VATSIM_TMI`)
- [x] `TMI_admin` user created with secure password
- [x] User permissions granted (db_datareader, db_datawriter, EXECUTE)
- [x] Migration script executed (001_tmi_core_schema_azure_sql.sql)
- [x] All 10 tables created
- [x] All 6 views created
- [x] All 4 stored procedures created
- [x] `config.php` updated with TMI credentials
- [x] API endpoints deployed and tested
- [x] `/api/tmi/` returning valid JSON ✅
- [x] `/api/tmi/active.php` returning valid JSON ✅

---

## Configuration

### config.php Settings

```php
// TMI Database (Azure SQL - Traffic Management)
define("TMI_SQL_HOST", "vatsim.database.windows.net");
define("TMI_SQL_DATABASE", "VATSIM_TMI");
define("TMI_SQL_USERNAME", "TMI_admin");
define("TMI_SQL_PASSWORD", "***REMOVED***");
```

---

## Testing the Deployment

### Quick Tests

```bash
# API Info
curl https://perti.vatcscc.org/api/tmi/

# Active Data
curl https://perti.vatcscc.org/api/tmi/active.php

# List Entries
curl https://perti.vatcscc.org/api/tmi/entries.php

# List Programs
curl https://perti.vatcscc.org/api/tmi/programs.php
```

### Verification Script

```bash
curl "https://perti.vatcscc.org/scripts/tmi/verify_deployment.php?allow=1"
```

---

## Remaining Work

1. **Create `reroutes.php` endpoint** - CRUD for reroute definitions
2. **Update existing GDT files** - Migrate `gs/*.php` and `gdp_*.php` to use new `tmi_programs` table
3. **Discord bot integration** - Update bot to call PHP API
4. **Data migration** - Move existing public routes from MySQL if any
5. **GDT stored procedures** - Implement slot generation (`sp_TMI_GenerateSlots`, etc.)

---

## Original Deployment Steps (For Reference)

<details>
<summary>Click to expand original deployment instructions</summary>

### Step 1: Create Azure SQL Database

```bash
az sql db create \
  --resource-group VATSIM_RG \
  --server vatsim \
  --name VATSIM_TMI \
  --edition Basic \
  --capacity 5 \
  --max-size 2GB
```

### Step 2: Create User (on `master` database)

```sql
CREATE LOGIN TMI_admin WITH PASSWORD = '***REMOVED***';
```

### Step 3: Grant Permissions (on `VATSIM_TMI` database)

```sql
CREATE USER [TMI_admin] FROM LOGIN [TMI_admin];
ALTER ROLE db_datareader ADD MEMBER [TMI_admin];
ALTER ROLE db_datawriter ADD MEMBER [TMI_admin];
GRANT EXECUTE ON SCHEMA::dbo TO [TMI_admin];
```

### Step 4: Run Migration Script

```bash
sqlcmd -S vatsim.database.windows.net -d VATSIM_TMI -U TMI_admin -P "password" \
  -i "database/migrations/tmi/001_tmi_core_schema_azure_sql.sql"
```

</details>

---

## Cost Summary

| Component | Monthly | Annual |
|-----------|---------|--------|
| VATSIM_TMI (Basic) | $4.99 | $59.88 |
| Event scale-ups (~6/year) | ~$2 | ~$12 |
| **Total** | **~$7** | **~$72** |

---

*Last Updated: January 17, 2026 - Post-deployment verification complete*
