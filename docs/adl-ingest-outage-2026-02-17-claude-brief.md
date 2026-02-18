# ADL Ingest Outage Analysis Brief For Claude

## Scope
This brief documents the ADL ingest outage observed on **2026-02-17** and the intended fixes for independent review.

## Executive Summary
1. ADL ingest stopped writing new data after approximately **2026-02-17 07:54Z**.
2. VATSIM upstream feed remained live, so the stall was internal to the ADL daemon path.
3. Production logs show a transient Azure SQL unavailability event (`SQL Server error 40613`) followed by a PHP fatal crash in reconnect handling.
4. Root crash mechanism: `sqlsrv_close()` threw a `TypeError` on an invalid SQLSRV handle; code caught `Exception` only, not `Throwable`.
5. A hardening patch has been applied locally in `scripts/vatsim_adl_daemon.php` to prevent this class of crash.

## Timeline (UTC)
1. Normal ingest cycles were observed through the `07:54` minute.
2. Last ADL write activity landed around `07:54:23` to `07:54:27`.
3. App log later records:
   - `[2026-02-17 07:56:50Z] [WARN] Connection lost, reconnecting...`
   - `[2026-02-17 07:56:50Z] [ERROR] ... SQLSTATE 42000 / code 40613 ... Database 'VATSIM_ADL' ... not currently available`
   - `[17-Feb-2026 07:56:50 UTC] PHP Fatal error: Uncaught TypeError: sqlsrv_close(): supplied resource is not a valid ss_sqlsrv_conn resource ... scripts/vatsim_adl_daemon.php:2328`
4. No further ADL ingest writes after this point.

## Database Evidence
All checks run against `VATSIM_ADL` via `sqlcmd`.

1. Core recency:
```sql
SELECT SYSUTCDATETIME() AS now_utc, MAX(last_seen_utc) AS max_last_seen_utc
FROM dbo.adl_flight_core;
```
Observed: `now_utc` around `2026-02-17 18:19Z`, `max_last_seen_utc` `2026-02-17 07:54:23`.

2. Multi-table stop confirmation:
```sql
SELECT 'core.last_seen>=08:00Z' AS metric, COUNT(*) FROM dbo.adl_flight_core WHERE last_seen_utc >= '2026-02-17T08:00:00Z'
UNION ALL
SELECT 'core.snapshot>=08:00Z', COUNT(*) FROM dbo.adl_flight_core WHERE snapshot_utc >= '2026-02-17T08:00:00Z'
UNION ALL
SELECT 'position.updated>=08:00Z', COUNT(*) FROM dbo.adl_flight_position WHERE position_updated_utc >= '2026-02-17T08:00:00Z'
UNION ALL
SELECT 'plan.updated>=08:00Z', COUNT(*) FROM dbo.adl_flight_plan WHERE fp_updated_utc >= '2026-02-17T08:00:00Z'
UNION ALL
SELECT 'times.updated>=08:00Z', COUNT(*) FROM dbo.adl_flight_times WHERE times_updated_utc >= '2026-02-17T08:00:00Z'
UNION ALL
SELECT 'changelog.change>=08:00Z', COUNT(*) FROM dbo.adl_flight_changelog WHERE change_utc >= '2026-02-17T08:00:00Z'
UNION ALL
SELECT 'parse_queue.queued>=08:00Z', COUNT(*) FROM dbo.adl_parse_queue WHERE queued_utc >= '2026-02-17T08:00:00Z'
UNION ALL
SELECT 'parse_queue.completed>=08:00Z', COUNT(*) FROM dbo.adl_parse_queue WHERE completed_utc >= '2026-02-17T08:00:00Z';
```
Observed: all zero.

3. Last-known writes:
1. `adl_flight_core.last_seen_utc` -> `2026-02-17 07:54:23`
2. `adl_flight_core.snapshot_utc` -> `2026-02-17 07:54:23`
3. `adl_flight_position.position_updated_utc` -> `2026-02-17 07:54:23.000`
4. `adl_flight_plan.fp_updated_utc` -> `2026-02-17 07:54:23.000`
5. `adl_flight_times.times_updated_utc` -> `2026-02-17 07:54:27.000`
6. `adl_flight_changelog.change_utc` -> `2026-02-17 07:54:27.903`
7. `adl_parse_queue.queued_utc` -> `2026-02-17 07:54:24.887` (latest status `COMPLETE`)
8. `adl_staging_pilots.inserted_utc` -> `2026-02-17 07:54:22.981`
9. `adl_staging_prefiles.inserted_utc` -> `2026-02-17 07:54:23.137`

4. Changelog minute histogram (last 16h) showed active writes through `07:54`, then none after `08:00`.

## Upstream Feed Cross-Check
VATSIM feed remained healthy:
```powershell
Invoke-RestMethod https://data.vatsim.net/v3/vatsim-data.json
```
Observed sample: `update=2026-02-17T18:24:11Z`, `pilots=1907`, `prefiles=217`.

## Production Runtime Evidence
Kudu `LogFiles` metadata:
1. `vatsim_adl.log` last modified at `2026-02-17T07:56:50Z`.
2. No subsequent writes in that log after the fatal event.

Kudu process probe:
1. `ps` grep for `vatsim_adl_daemon.php` returned no running process at check time.

## Root Cause
1. Azure SQL transient outage/failover (error `40613`) invalidated the active connection.
2. Reconnect path called `@sqlsrv_close($conn)` on an invalid SQLSRV handle.
3. In PHP 8, this can raise `TypeError`; `@` does not suppress fatal type exceptions.
4. Code was catching `Exception`, not `Throwable`, so the `TypeError` escaped and terminated the daemon.
5. Daemon is launched via `nohup` in startup script (`scripts/startup.sh:35-38`) without a supervisor loop, so it stayed down after crash.

## Intended Fixes (For Review)
Local patch already applied in `scripts/vatsim_adl_daemon.php`.

1. Harden health-check query against invalid handles:
1. Wrap `sqlsrv_query` in `try/catch (Throwable)` and return `false` on failure.
2. New helper: `safeCloseConnection(&$conn)`:
1. Best-effort close in `try/catch (Throwable)`.
2. Always nulls the handle.
3. Replace direct `@sqlsrv_close($conn)` calls with `safeCloseConnection($conn)` in:
1. Connection health-reconnect block.
2. Main error-recovery reconnect block.
3. Shutdown block.
4. Expand catches from `Exception` to `Throwable` in recovery paths to include `TypeError`.

## Patched Locations
1. `scripts/vatsim_adl_daemon.php:1800` (`isConnectionAlive` hardened)
2. `scripts/vatsim_adl_daemon.php:1823` (`safeCloseConnection` added)
3. `scripts/vatsim_adl_daemon.php:1960` (health reconnect close path)
4. `scripts/vatsim_adl_daemon.php:2348` (main catch `Throwable`)
5. `scripts/vatsim_adl_daemon.php:2353` (reconnect close path)
6. `scripts/vatsim_adl_daemon.php:2438` (restore catch `Throwable`)
7. `scripts/vatsim_adl_daemon.php:2456` (shutdown safe close)

## Validation Performed On Patch
```bash
php -l scripts/vatsim_adl_daemon.php
```
Observed: no syntax errors.

## Suggested Post-Deploy Validation
1. Confirm daemon process is running.
2. Confirm `MAX(last_seen_utc)` advances every minute.
3. Confirm ADL writes after current UTC time window in core/position/plan/times/changelog.
4. Watch log for successful recovery after forced transient DB reconnect scenarios.

## Optional Follow-Up Hardening
1. Add process supervision/auto-restart for `vatsim_adl_daemon.php` so a future fatal does not create prolonged ingest outage.
2. Add explicit reconnect backoff telemetry and alerting when no ingest writes are observed for >2 minutes.

