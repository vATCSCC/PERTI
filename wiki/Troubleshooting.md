# Troubleshooting

Common issues and solutions for PERTI.

---

## Database Issues

### Cannot connect to MySQL

**Symptoms:** Connection refused, access denied

**Solutions:**
- Verify MySQL is running
- Check credentials in `load/config.php`
- Confirm database exists
- Check firewall rules

---

### Cannot connect to Azure SQL

**Symptoms:** Connection timeout, login failed

**Solutions:**
- Verify `ADL_*` constants in config
- Check Azure SQL firewall allows your IP
- Confirm `pdo_sqlsrv` extension is loaded
- Test connection string separately

---

### PostgreSQL/PostGIS Connection Issues

**Symptoms:** PostGIS spatial queries fail, boundary detection daemon reports errors, `get_conn_gis()` returns null

**Solutions:**
- Confirm the `pdo_pgsql` PHP extension is loaded (`php -m | grep pgsql`)
- Verify `GIS_HOST`, `GIS_DB`, `GIS_USER`, and `GIS_PASS` constants are set in `load/config.php`
- Check that the PostgreSQL firewall on Azure allows your IP (or the App Service outbound IPs)
- Test connectivity directly:
  ```sql
  SELECT PostGIS_Version();
  ```
  If this query fails, PostGIS is not installed or the extension is not enabled on the database.
- On Azure, ensure the `postgis` extension is listed under **Server parameters > azure.extensions** for the PostgreSQL Flexible Server.

---

### PERTI_MYSQL_ONLY Issues

**Symptoms:** HTTP 500 on API endpoints that previously worked, null connection errors in logs (`Call to a member function on null`), endpoints returning empty or broken data silently

**Cause:** The file defines `PERTI_MYSQL_ONLY` before including `connect.php`, which skips all five Azure SQL connections (`$conn_adl`, `$conn_tmi`, `$conn_swim`, `$conn_ref`, `$conn_gis`). If the endpoint then uses any of those connections, they are null.

**Solutions:**
1. Identify the affected file and check whether it uses Azure SQL connections:
   ```bash
   grep -n 'conn_adl\|conn_tmi\|conn_swim\|conn_ref\|conn_gis' path/to/file.php
   ```
2. If any matches are found, **remove** the `define('PERTI_MYSQL_ONLY', true);` line from that file.
3. Always grep for Azure SQL connection usage before adding the `PERTI_MYSQL_ONLY` flag to any file.

**Known files that must NOT use this flag:**
- `api/mgt/config_data/` (bulk, post, update, delete) -- uses `$conn_adl`
- `api/mgt/tmi/reroutes/`, `api/mgt/tmi/airport_configs.php` -- uses `$conn_adl`, `$conn_tmi`
- `api/mgt/sua/` -- uses `$conn_adl`
- `api/data/configs.php`, `api/data/tmi/reroute.php`, `api/data/sua/`, `api/data/rate_history.php`, `api/data/weather_impacts.php` -- uses `$conn_adl`

---

## Authentication Issues

### OAuth redirect error

**Symptoms:** Redirect loop, invalid state

**Solutions:**
- Verify `VATSIM_REDIRECT_URI` matches exactly
- Check VATSIM Connect app configuration
- Clear cookies and try again

---

### Session expired unexpectedly

**Symptoms:** Logged out mid-session

**Solutions:**
- Check session storage permissions
- Verify `SESSION_PATH` is writable
- Check PHP session settings

---

## Daemon Issues

### Another instance is already running

**Symptoms:** Lock file error

**Solutions:**
```bash
# Check for running process
ps aux | grep vatsim_adl

# Remove stale lock if process not running
rm scripts/vatsim_adl.lock
```

---

### Daemon taking too long

**Symptoms:** SP execution > 10 seconds

**Solutions:**
- Check index health
- Review query execution plans
- Consider history table cleanup

---

### Parse Queue GIS daemon errors

**Symptoms:** Route parsing stops, `parse_queue_gis_daemon.php` logs PostGIS connection failures, flights stuck in `adl_parse_queue` with status `pending`

**Solutions:**
- Verify PostGIS is reachable (see [PostgreSQL/PostGIS Connection Issues](#postgresqlpostgis-connection-issues) above)
- Check that `nav_fixes`, `airways`, and `airway_segments` tables are populated in `VATSIM_GIS`
- Review the parse queue for stuck entries:
  ```sql
  SELECT status, COUNT(*) FROM adl_parse_queue GROUP BY status;
  ```
- If entries are stuck with high `attempts` counts, check the daemon log for specific SQL errors

---

### Boundary GIS daemon not detecting boundaries

**Symptoms:** `boundary_gis_daemon.php` runs but flights show no `current_artcc` or `current_tracon`, `adl_flight_core.boundary_updated_at` never updates

**Solutions:**
- Confirm `artcc_boundaries` and `tracon_boundaries` tables in `VATSIM_GIS` are populated:
  ```sql
  SELECT COUNT(*) FROM artcc_boundaries;
  SELECT COUNT(*) FROM tracon_boundaries;
  ```
- If empty, run the boundary import migration or `scripts/build_sector_boundaries.py`
- Check that the `geom` columns contain valid geometries (`ST_IsValid(geom)`)
- Verify `adl_boundary_grid` is populated for fast lookups

---

### SWIM sync failures

**Symptoms:** `swim_sync_daemon.php` reports errors, `swim_flights` table stale, SWIM API returning outdated data

**Solutions:**
- Verify both `get_conn_adl()` and `get_conn_swim()` return valid connections
- Check `SWIM_API` database is accessible and `swim_flights` table exists
- Review the sync daemon log for specific SQL errors or timeout messages
- Compare record counts between source and destination:
  ```sql
  -- ADL side
  SELECT COUNT(*) FROM adl_flight_core WHERE is_active = 1;
  -- SWIM side (run against SWIM_API)
  SELECT COUNT(*) FROM swim_flights WHERE is_active = 1;
  ```

---

### Discord queue stuck

**Symptoms:** TMI advisories not posting to Discord, `tmi_discord_posts` table has rows with `status = 'pending'` that never clear

**Solutions:**
- Verify `scripts/tmi/process_discord_queue.php` is running (`ps aux | grep process_discord_queue`)
- Check Discord bot token and webhook URLs are valid in config
- Look for rate-limit entries in `discord_rate_limits` table
- Check the daemon log for HTTP 429 (rate limited) or 401 (unauthorized) responses
- If the queue is very backlogged, check `retry_count` -- entries with high retry counts may have permanent errors

---

### Archival daemon timing

**Symptoms:** Trajectory tables growing unbounded, `adl_flight_trajectory` very large, archival not running

**Solutions:**
- The archival daemon (`scripts/archival_daemon.php`) runs on a 1-4 hour cycle with daily archive at 10:00Z
- Verify the daemon is running and check its log for errors
- Check `adl_archive_config` for current retention settings
- Review `adl_archive_log` for the last successful run:
  ```sql
  SELECT TOP 5 * FROM adl_archive_log ORDER BY run_utc DESC;
  ```
- If trajectory tables are very large, a manual archive run may be needed

---

### Daemon log locations

All daemons write to log files that can be checked for errors and status:

| Environment | Log Location |
|-------------|-------------|
| Azure App Service | `/home/LogFiles/<daemon>.log` |
| Local development | `scripts/<daemon>.log` (same directory as the daemon script) |

To tail a daemon log on Azure:
```bash
# Via Kudu SSH
tail -f /home/LogFiles/vatsim_adl_daemon.log
```

Full daemon list and their intervals are documented in [[Daemons and Scripts]].

---

## Display Issues

### Map not loading

**Symptoms:** Blank map area

**Solutions:**
- Verify JavaScript enabled
- Check browser console for errors
- Confirm WebGL support
- Try different browser

---

### Flight data not updating

**Symptoms:** Stale positions

**Solutions:**
- Verify ADL daemon is running
- Check VATSIM API status
- Review daemon logs for errors

---

### i18n / Translation Issues

**Symptoms:** Untranslated keys showing as `[missing: key.name]` in the UI

**Solutions:**
- Verify the i18n scripts are loaded in the correct order on the page:
  1. `assets/js/lib/i18n.js` (core translation module)
  2. `assets/locales/index.js` (locale loader and initializer)
  3. `assets/locales/en-US.json` (translation dictionary, loaded by the locale loader)
- Check that the missing key exists in `assets/locales/en-US.json` -- keys use dot notation (e.g., `dialog.confirmDelete.title`)
- Open the browser console and look for i18n initialization errors
- Verify the locale JSON file loaded successfully (check the Network tab for a failed request to `en-US.json`)

**Symptoms:** Wrong locale detected, UI showing unexpected language behavior

**Solutions:**
- Force a specific locale via URL parameter: `?locale=en-US`
- Check `localStorage` for a stored override: `localStorage.getItem('PERTI_LOCALE')`
- Check `navigator.language` in the browser console to see what the browser reports
- Locale detection priority: URL param > `localStorage.PERTI_LOCALE` > `navigator.language` > `en-US` fallback

---

### TMI Compliance Display Issues

**Symptoms:** Flow cone not rendering on the map, measurement points missing or misplaced, compliance analysis panel empty

**Solutions:**
- Verify `assets/js/tmi_compliance.js` is loaded on the page (check the Network tab or page source)
- Check that the PostGIS (`VATSIM_GIS`) database is accessible -- flow cone geometry relies on fix positions from `nav_fixes`
- Confirm `nav_fixes` table in GIS database is populated:
  ```sql
  SELECT COUNT(*) FROM nav_fixes;
  ```
- Check the browser console for JavaScript errors from `tmi_compliance.js`
- Verify the TMI program has an active `ctl_element` that maps to a valid airport or fix
- If measurement points display but flow cones do not, the issue is likely in the JS enhancement layer (approach bearings, centerline calculation) -- check console for geometry errors

---

### NOD Display Issues

**Symptoms:** Facility flow layers not showing on the NOD map, TMI cards not appearing in the sidebar, NAT tracks missing

**Solutions:**
- Check that `facility_flow_configs` table has entries for the facility being viewed
- Verify FEA (Flow Evaluation Area) API connectivity -- check browser Network tab for failed API requests
- Open browser console and look for MapLibre GL errors (layer/source failures)
- For missing NAT tracks, check the `api/nod/tracks.php` endpoint directly to confirm data is being returned
- Verify `nod.js` and `nod-demand-layer.js` are loaded on the page
- Check that MapLibre GL JS is loaded from CDN without errors

---

## Performance Issues

### Slow page loads

**Solutions:**
- Check database query times
- Review network latency
- Consider caching
- Check server resources

---

## See Also

- [[Maintenance]] - Routine tasks
- [[Configuration]] - Setup options
- [[Daemons and Scripts]] - Background processes
