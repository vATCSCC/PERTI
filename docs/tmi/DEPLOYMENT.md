# VATSIM_TMI Deployment Guide

**Version:** 1.0  
**Date:** January 17, 2026

---

## Pre-Deployment Summary

### Files Updated in This Session

| File | Status | Description |
|------|--------|-------------|
| `docs/tmi/README.md` | ✅ Updated | Master documentation index |
| `docs/tmi/ARCHITECTURE.md` | ✅ Updated | System architecture v2.0 |
| `docs/tmi/DATABASE.md` | ✅ Complete | Full schema (269 fields, 10 tables) |
| `docs/tmi/COST_ANALYSIS.md` | ✅ Created | Azure SQL pricing analysis |
| `docs/tmi/STATUS_WORKFLOW.md` | ✅ Existing | State machine documentation |
| `load/config.example.php` | ✅ Updated | Added TMI database constants |
| `load/connect.php` | ✅ Updated | Added $conn_tmi connection |
| `database/migrations/tmi/001_tmi_core_schema_azure_sql.sql` | ✅ Complete | Full migration (35KB) |

### Database Objects to Create

**Tables (10):**
1. `tmi_entries` - NTML log
2. `tmi_programs` - GS/GDP programs
3. `tmi_slots` - GDP slot allocation
4. `tmi_advisories` - Formal advisories
5. `tmi_reroutes` - Reroute definitions
6. `tmi_reroute_flights` - Flight assignments
7. `tmi_reroute_compliance_log` - Compliance history
8. `tmi_public_routes` - Map display
9. `tmi_events` - Audit log
10. `tmi_advisory_sequences` - Number generation

**Views (6):**
- `vw_tmi_active_entries`
- `vw_tmi_active_advisories`
- `vw_tmi_active_programs`
- `vw_tmi_active_reroutes`
- `vw_tmi_active_public_routes`
- `vw_tmi_recent_entries`

**Stored Procedures (4):**
- `sp_GetNextAdvisoryNumber`
- `sp_LogTmiEvent`
- `sp_ExpireOldEntries`
- `sp_GetActivePublicRoutes`

---

## Step 1: Create Azure SQL Database

### Option A: Azure Portal

1. Go to [Azure Portal](https://portal.azure.com)
2. Navigate to **SQL databases**
3. Click **+ Create**
4. Fill in:
   - **Resource group:** `vatcscc-rg` (or your existing RG)
   - **Database name:** `VATSIM_TMI`
   - **Server:** Select `vatsim.database.windows.net`
   - **Compute + storage:** Click "Configure database"
     - Select **Basic** tier (5 DTUs, 2 GB) = **$4.99/month**
5. Click **Review + create** → **Create**
6. Wait for deployment (~2-5 minutes)

### Option B: Azure CLI

```bash
# If not logged in
az login

# Create the database
az sql db create \
  --resource-group vatcscc-rg \
  --server vatsim \
  --name VATSIM_TMI \
  --edition Basic \
  --capacity 5 \
  --max-size 2GB
```

### Option C: T-SQL (from SSMS connected to `master`)

```sql
-- Connect to vatsim.database.windows.net, database: master
CREATE DATABASE VATSIM_TMI
(
    EDITION = 'Basic',
    SERVICE_OBJECTIVE = 'Basic',
    MAXSIZE = 2 GB
);
```

---

## Step 2: Grant User Access

Connect to `VATSIM_TMI` and run:

```sql
-- Use the same login as VATSIM_ADL
CREATE USER [adl_api_user] FROM LOGIN [adl_api_user];

-- Grant permissions
ALTER ROLE db_datareader ADD MEMBER [adl_api_user];
ALTER ROLE db_datawriter ADD MEMBER [adl_api_user];
GRANT EXECUTE ON SCHEMA::dbo TO [adl_api_user];

-- Verify
SELECT name, type_desc FROM sys.database_principals WHERE name = 'adl_api_user';
```

---

## Step 3: Run Migration Script

### Option A: SQL Server Management Studio (SSMS)

1. Open SSMS
2. Connect to `vatsim.database.windows.net`
   - Authentication: SQL Server Authentication
   - Login: `adl_api_user`
   - Password: (your password)
3. Select database: `VATSIM_TMI`
4. File → Open → `database/migrations/tmi/001_tmi_core_schema_azure_sql.sql`
5. Press **F5** to execute
6. Verify output shows all tables, views, procedures created

### Option B: sqlcmd (Command Line)

```bash
sqlcmd -S vatsim.database.windows.net -d VATSIM_TMI \
  -U adl_api_user -P "YourPassword" \
  -i "database/migrations/tmi/001_tmi_core_schema_azure_sql.sql"
```

### Option C: Azure Data Studio

1. Connect to `vatsim.database.windows.net`
2. Select `VATSIM_TMI` database
3. Open the migration script
4. Run all

---

## Step 4: Verify Deployment

Run these queries to confirm everything is created:

```sql
-- Check tables
SELECT name FROM sys.tables WHERE is_ms_shipped = 0 ORDER BY name;
-- Expected: 10 tables

-- Check views
SELECT name FROM sys.views WHERE is_ms_shipped = 0 ORDER BY name;
-- Expected: 6 views

-- Check procedures
SELECT name FROM sys.procedures WHERE is_ms_shipped = 0 ORDER BY name;
-- Expected: 4 procedures

-- Check indexes
SELECT 
    t.name AS table_name,
    i.name AS index_name,
    i.type_desc
FROM sys.indexes i
JOIN sys.tables t ON i.object_id = t.object_id
WHERE i.name IS NOT NULL AND t.is_ms_shipped = 0
ORDER BY t.name, i.name;
```

---

## Step 5: Update Local Config

Add to your `load/config.php` (not the example file):

```php
// =============================================
// TMI Database (Azure SQL - Traffic Management)
// Server: vatsim.database.windows.net
// =============================================
define("TMI_SQL_HOST", "vatsim.database.windows.net");
define("TMI_SQL_DATABASE", "VATSIM_TMI");
define("TMI_SQL_USERNAME", "adl_api_user");  // Same as ADL
define("TMI_SQL_PASSWORD", "your_password"); // Same as ADL
```

---

## Step 6: Test PHP Connection

Create a quick test file (then delete it):

```php
<?php
// test_tmi_connection.php
require_once 'load/connect.php';

if ($conn_tmi) {
    echo "✅ TMI connection successful!\n\n";
    
    // Test a query
    $result = sqlsrv_query($conn_tmi, "SELECT name FROM sys.tables ORDER BY name");
    echo "Tables in VATSIM_TMI:\n";
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        echo "  - " . $row['name'] . "\n";
    }
} else {
    echo "❌ TMI connection failed!\n";
    print_r(sqlsrv_errors());
}
```

Run:
```bash
php test_tmi_connection.php
```

Expected output:
```
✅ TMI connection successful!

Tables in VATSIM_TMI:
  - tmi_advisories
  - tmi_advisory_sequences
  - tmi_entries
  - tmi_events
  - tmi_programs
  - tmi_public_routes
  - tmi_reroute_compliance_log
  - tmi_reroute_flights
  - tmi_reroutes
  - tmi_slots
```

---

## Post-Deployment Checklist

- [ ] Database created on Azure
- [ ] User access granted
- [ ] Migration script executed
- [ ] All 10 tables created
- [ ] All 6 views created
- [ ] All 4 procedures created
- [ ] `config.php` updated with TMI credentials
- [ ] PHP connection tested successfully
- [ ] Test file deleted

---

## Next Steps After Deployment

1. **GDT Stored Procedures** - Implement slot generation and assignment
2. **API Endpoints** - Create `/api/tmi/` and `/api/gdt/` routes
3. **Discord Bot Update** - Update bot to call PHP API
4. **UI Integration** - Update `gdt.js` for new API structure

---

## Cost Summary

| Component | Monthly | Annual |
|-----------|---------|--------|
| VATSIM_TMI (Basic) | $4.99 | $59.88 |
| Event scale-ups | ~$2 | ~$12 |
| **Total** | **~$7** | **~$72** |

---

## Troubleshooting

### Connection Failed
- Verify firewall allows your IP: Azure Portal → SQL server → Firewall settings
- Check credentials match VATSIM_ADL user
- Ensure database name is exactly `VATSIM_TMI`

### Permission Denied
- Re-run the user grant statements from Step 2
- Verify user exists: `SELECT name FROM sys.database_principals`

### Migration Script Errors
- Run in smaller batches (each `GO` section separately)
- Check for pre-existing objects
- Verify you're connected to correct database

---

*Last Updated: January 17, 2026*
