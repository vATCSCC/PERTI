# Maintenance

Routine maintenance tasks for PERTI administrators.

---

## Database Maintenance

### Automated Archival

The `archival_daemon.php` handles most cleanup automatically:

| Task | Interval | Description |
|------|----------|-------------|
| Trajectory tiering | 1-4 hours | Moves `adl_flight_trajectory` → `adl_trajectory_archive` (downsampled) → `adl_trajectory_compressed` |
| Changelog purge | Daily | Batches and compresses `adl_flight_changelog` entries |
| Flight archive | Daily 10:00Z | Moves completed flights to `adl_flight_archive` |

### Manual History Cleanup

For manual cleanup of old data:

```sql
-- Delete archived trajectories older than 90 days
DELETE FROM adl_trajectory_compressed
WHERE recorded_utc < DATEADD(DAY, -90, GETUTCDATE());

-- Purge old changelog batches
DELETE FROM adl_changelog_batch
WHERE batch_date < DATEADD(DAY, -60, GETUTCDATE());
```

### Index Maintenance

Rebuild indexes periodically for optimal performance. Key indexes to monitor:

- `adl_flight_core` - `ix_flight_core_phase`, `ix_flight_core_active`
- `adl_flight_position` - `ix_flight_position_geo` (spatial)
- `adl_flight_trajectory` - `ix_trajectory_flight_uid`
- `tmi_programs` - `ix_tmi_programs_active`

---

## Log Management

### Daemon Logs

Monitor and rotate daemon logs:

**Azure App Service:**

All 15 daemons log to `/home/LogFiles/<daemon>.log`. Stream live:

```bash
az webapp log tail --resource-group PERTI-RG --name vatcscc
```

**Local development:**

| Daemon | Log Location |
|--------|-------------|
| ADL Ingest | `scripts/vatsim_adl.log` |
| Parse Queue GIS | `adl/php/parse_queue_gis.log` |
| Boundary GIS | `adl/php/boundary_gis.log` |
| Crossing GIS | `adl/php/crossing_gis.log` |
| Waypoint ETA | `adl/php/waypoint_eta.log` |
| SWIM WebSocket | `scripts/swim_ws.log` |
| SWIM Sync | `scripts/swim_sync.log` |
| Discord Queue | `scripts/tmi/discord_queue.log` |
| Scheduler | `scripts/scheduler.log` |
| Archival | `scripts/archival.log` |
| Monitoring | `scripts/monitoring.log` |
| Event Sync | `scripts/event_sync.log` |
| SimTraffic Poll | `scripts/simtraffic.log` |
| ADL Archive | `scripts/adl_archive.log` |

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
// Test MySQL (PDO)
$conn = new PDO("mysql:host=$host;dbname=$db", $user, $pass);

// Test Azure SQL (sqlsrv extension, NOT PDO)
$adl = sqlsrv_connect($server, [
    'Database' => $db,
    'UID' => $user,
    'PWD' => $pass
]);

// Test PostgreSQL/PostGIS (PDO pgsql)
$gis = new PDO("pgsql:host=$host;dbname=$db;port=5432", $user, $pass);
$result = $gis->query("SELECT PostGIS_Version()");
```

### Daemon Status

On Azure, daemons are started via `scripts/startup.sh` at boot. Check status:

```bash
# Stream daemon logs
az webapp log tail --resource-group PERTI-RG --name vatcscc

# Check ADL ingest performance
SELECT TOP 10 * FROM adl_refresh_perf ORDER BY refresh_id DESC;

# Check scheduler state
SELECT * FROM scheduler_state;

# Check parse queue backlog
SELECT parse_tier, status, COUNT(*) FROM adl_parse_queue GROUP BY parse_tier, status;
```

---

## See Also

- [[Deployment]] - Initial setup
- [[Troubleshooting]] - Problem resolution
- [[Daemons and Scripts]] - Background processes
