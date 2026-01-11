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

### Navigation Data

Update FAA NASR data on the 28-day cycle:

```bash
python scripts/nasr_navdata_updater.py
```

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
