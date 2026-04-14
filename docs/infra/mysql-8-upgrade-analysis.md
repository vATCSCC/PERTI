# MySQL 5.7 → 8.0 Upgrade Analysis for perti_site

**Analysis Date:** January 30, 2026
**Current Version:** MySQL 5.7.44-azure-log
**Target Version:** MySQL 8.0 or 8.4
**Deadline:** March 2026 (end of MySQL 5.7 support)

---

## Executive Summary

The upgrade is **feasible with moderate effort**. There are no blocking issues, but several configuration changes are required to avoid runtime errors.

### Critical Actions Required

| Priority | Issue | Impact | Effort |
|----------|-------|--------|--------|
| **HIGH** | SQL Mode contains `ALLOW_INVALID_DATES` (removed in 8.0.22) | Upgrade will fail | Config change |
| **HIGH** | SQL Mode contains `NO_AUTO_CREATE_USER` (removed in 8.0) | Upgrade will fail | Config change |
| **MEDIUM** | Authentication plugin mismatch | Connection errors possible | Config + code change |
| **MEDIUM** | 143 columns using `utf8` instead of `utf8mb4` | Future compatibility | Pre-upgrade script |
| **LOW** | Integer display widths deprecated (e.g., `INT(11)`) | Warnings in logs | No action needed |
| **LOW** | 2 foreign key constraints exist | Verify naming unique | Verify before upgrade |

---

## Detailed Findings

### 1. SQL Mode Compatibility (HIGH PRIORITY)

**Current SQL Mode:**
```
NO_AUTO_VALUE_ON_ZERO, STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE,
ALLOW_INVALID_DATES, ERROR_FOR_DIVISION_BY_ZERO, NO_AUTO_CREATE_USER
```

**Problems:**
- `ALLOW_INVALID_DATES` - **Removed in MySQL 8.0.22**
- `NO_AUTO_CREATE_USER` - **Removed in MySQL 8.0**

**Action Required:**
Before upgrading, set the SQL mode to MySQL 8.0 compatible values:

```sql
-- Run this BEFORE upgrading
SET GLOBAL sql_mode = 'NO_AUTO_VALUE_ON_ZERO,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO';
```

Or via Azure Portal:
1. Go to Azure Database for MySQL → Server parameters
2. Find `sql_mode`
3. Remove `ALLOW_INVALID_DATES` and `NO_AUTO_CREATE_USER`

---

### 2. Authentication Plugin (MEDIUM PRIORITY)

**Current State:**
- Server default: `mysql_native_password`
- All users use: `mysql_native_password`
- PHP connection code: No explicit auth plugin specified

**MySQL 8.0 Change:**
Default authentication plugin changed to `caching_sha2_password`.

**Risk:**
If Azure upgrades the default plugin, existing connections may fail with:
```
Authentication plugin 'caching_sha2_password' cannot be loaded
```

**Options:**

**Option A (Recommended):** Keep using `mysql_native_password` after upgrade
```sql
-- Set server default to maintain compatibility
SET GLOBAL default_authentication_plugin = 'mysql_native_password';

-- Or ensure user uses compatible plugin
ALTER USER 'jpeterson'@'%' IDENTIFIED WITH mysql_native_password BY 'password';
```

**Option B:** Update PHP connection to support new auth
```php
// In load/connect.php, modify the PDO connection:
$conn_pdo = new PDO(
    "mysql:host={$sql_host};dbname={$sql_dbname};charset=utf8mb4",
    $sql_user,
    $sql_passwd,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_CA => true  // Required for Azure
    ]
);
```

---

### 3. Character Set Migration (MEDIUM PRIORITY)

**Current State:**
| Level | Character Set | Collation |
|-------|--------------|-----------|
| Server | latin1 | latin1_swedish_ci |
| Database | utf8 | utf8_general_ci |
| Most Tables | utf8 | utf8_general_ci |
| 2 Tables | utf8mb4 | utf8mb4_general_ci |

**MySQL 8.0 Default:** `utf8mb4` with `utf8mb4_0900_ai_ci`

**Risks:**
1. `utf8` in MySQL is only 3-byte (no emojis/some unicode). MySQL 8 prefers `utf8mb4` (4-byte true UTF-8)
2. Connection without explicit charset may get different defaults

**Recommended Action:**

**Pre-upgrade:** Convert database and tables to `utf8mb4`:
```sql
-- Convert database
ALTER DATABASE perti_site CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Convert each table (run for all 30 tables)
ALTER TABLE admin_users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE assigned CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- ... (repeat for all tables)
```

**Update PHP connection:**
```php
// Add charset to DSN
$conn_pdo = new PDO(
    "mysql:host={$sql_host};dbname={$sql_dbname};charset=utf8mb4",
    $sql_user,
    $sql_passwd
);

// For MySQLi
$conn_sqli = mysqli_connect($sql_host, $sql_user, $sql_passwd, $sql_dbname);
mysqli_set_charset($conn_sqli, 'utf8mb4');
```

---

### 4. Schema Compatibility (VERIFIED - MOSTLY GOOD)

**Good News:**
- ✅ No columns use MySQL 8.0 reserved words as names
- ✅ No zero-date defaults in DATETIME/TIMESTAMP columns
- ✅ **No actual zero-date DATA found** in any tables
- ✅ All tables use InnoDB (required for some 8.0 features)
- ✅ All tables use DYNAMIC row format (optimal for 8.0)
- ✅ No deprecated functions found (PASSWORD(), ENCODE(), etc.)
- ✅ No SQL_CALC_FOUND_ROWS usage (deprecated in 8.0.17)
- ✅ No query cache references (query cache removed in 8.0)
- ✅ No deprecated system variables referenced (@@tx_isolation, etc.)
- ✅ All 49 TIMESTAMP columns have proper defaults (CURRENT_TIMESTAMP)

**Minor Issues (warnings only, no action required):**
- ⚠️ Integer display widths (e.g., `INT(11)`, `TINYINT(1)`) deprecated in 8.0.17
  - These still work but generate deprecation warnings in logs
  - Affects most tables but causes no functional issues
  - Can clean up post-upgrade if desired

**Foreign Key Constraints (2 total):**
- `FK_enroute_init_timeline_plan`: p_enroute_init_timeline.p_id → p_plans.id
- `FK_terminal_init_timeline_plan`: p_terminal_init_timeline.p_id → p_plans.id
- ✅ Names are unique - no conflicts expected

**Database Size:**
- Total rows: ~45,000 across 30 tables
- Largest table: `route_cdr` (35,820 rows)
- Estimated upgrade time: < 5 minutes

---

### 5. GROUP BY Behavior (LOW RISK)

MySQL 8.0 enforces stricter GROUP BY rules by default (`ONLY_FULL_GROUP_BY`).

**Current State:** Your SQL mode doesn't include `ONLY_FULL_GROUP_BY`, and MySQL 8.0 adds it by default.

**Potential Impact:** Queries that SELECT columns not in GROUP BY or aggregate functions may fail.

**Assessment:** Reviewed all GROUP BY queries in codebase - all appear to follow proper aggregation rules with COUNT(*), SUM(), etc.

**Recommendation:** After upgrade, test all reports/dashboards that use aggregations.

---

## Upgrade Procedure

### Pre-Upgrade Checklist

- [ ] Backup database (Azure handles this, but verify backup exists)
- [ ] Update SQL mode to remove deprecated options
- [ ] Convert character set to utf8mb4 (optional but recommended)
- [ ] Update `load/connect.php` to specify charset
- [ ] Test in staging environment first (if available)

### Azure CLI Upgrade Command

```bash
# First, update SQL mode via Azure Portal or:
az mysql flexible-server parameter set \
  -g VATSIM_RG \
  -s vatcscc-perti \
  -n sql_mode \
  -v "NO_AUTO_VALUE_ON_ZERO,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO"

# Then upgrade
az mysql flexible-server upgrade \
  -g VATSIM_RG \
  -n vatcscc-perti \
  -v 8
```

### Post-Upgrade Verification

```sql
-- Verify version
SELECT VERSION();

-- Verify SQL mode
SELECT @@sql_mode;

-- Verify connections work
SELECT COUNT(*) FROM users;
```

---

## Code Changes Required

### File: load/connect.php

```php
// Line 54: Add charset to PDO connection
$conn_pdo = new PDO(
    "mysql:host={$sql_host};dbname={$sql_dbname};charset=utf8mb4",
    $sql_user,
    $sql_passwd,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]
);

// Line 62: Add charset to MySQLi connection
$conn_sqli = mysqli_connect($sql_host, $sql_user, $sql_passwd, $sql_dbname);
if ($conn_sqli) {
    mysqli_set_charset($conn_sqli, 'utf8mb4');
}
```

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Upgrade fails due to SQL mode | High if not fixed | Blocking | Remove deprecated modes first |
| Connection auth errors | Medium | Blocking | Verify auth plugin compatibility |
| Character encoding issues | Low | Data corruption | Convert to utf8mb4 first |
| GROUP BY query failures | Low | Runtime errors | Test after upgrade |
| Performance regression | Low | Degraded UX | Monitor query times |

---

## Timeline Recommendation

1. **Week 1:** Make code changes (charset in connect.php)
2. **Week 2:** Update SQL mode via Azure Portal
3. **Week 3:** Convert tables to utf8mb4 (off-peak hours)
4. **Week 4:** Execute upgrade (off-peak, ~15-30 min downtime expected)
5. **Week 5:** Monitor and address any issues

---

## References

- [MySQL 8.0 Upgrade Checker](https://dev.mysql.com/doc/mysql-shell/8.0/en/mysql-shell-utilities-upgrade.html)
- [Azure MySQL Flexible Server Upgrade](https://learn.microsoft.com/en-us/azure/mysql/flexible-server/how-to-upgrade)
- [MySQL 8.0 SQL Mode Changes](https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html)
- [MySQL 8.0 Authentication Changes](https://dev.mysql.com/doc/refman/8.0/en/upgrading-from-previous-series.html#upgrade-caching-sha2-password)
