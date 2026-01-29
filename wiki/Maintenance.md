# Maintenance

Routine maintenance tasks for PERTI administrators.

---

## Database Maintenance

### History Cleanup

Periodically purge old flight history to manage database size:

```sql
-- Delete snapshots older than 30 days
DELETE FROM adl_flights_history
WHERE snapshot_utc < DATEADD(DAY, -30, GETUTCDATE());
```

### Index Maintenance

Rebuild indexes periodically for optimal performance.

---

## Log Management

### Daemon Logs

Monitor and rotate daemon logs:

| Log | Location |
|-----|----------|
| ADL Daemon | `scripts/vatsim_adl.log` |
| Parse Daemon | Application logs |
| ATIS Daemon | Console/journald |

---

## Data Updates

### AIRAC Navigation Data (28-Day Cycle)

AIRAC updates are required every 28 days when the FAA releases new navigation data. Use the master script:

```bash
python airac_full_update.py
```

This downloads FAA NASR data, scrapes playbook routes, imports to VATSIM_REF, and syncs to VATSIM_ADL.

**Quick options:**

```bash
python airac_full_update.py --dry-run      # Preview without changes
python airac_full_update.py --skip-playbook # Faster if playbook unchanged
python airac_full_update.py --step 1       # NASR download only
python airac_full_update.py --step 3       # Database import only
```

See **[[AIRAC-Update]]** for complete documentation including:

- Database schema and table details
- Verification queries
- Troubleshooting guide
- AIRAC cycle calendar

### Boundary Data

Refresh ARTCC/TRACON boundaries as needed:

```bash
php scripts/refresh_vatsim_boundaries.php
```

---

## Health Checks

### Database Connectivity

```php
// Test MySQL
$conn = new PDO("mysql:host=$host;dbname=$db", $user, $pass);

// Test Azure SQL
$adl = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
```

### Daemon Status

```bash
# Linux
systemctl status vatsim-adl

# Check recent runs
SELECT TOP 10 * FROM adl_run_log ORDER BY id DESC;
```

---

## See Also

- [[Deployment]] - Initial setup
- [[Troubleshooting]] - Problem resolution
- [[Daemons and Scripts]] - Background processes
