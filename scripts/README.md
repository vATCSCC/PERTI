# VATSIM ADL Refresh Daemon

Replaces Power Automate with a direct PHP daemon. No SQL connector limits.

---

## Directory Structure

Place files in your `wwwroot` like this:

```
wwwroot/
├── load/
│   ├── config.php        # Must have ADL_* constants (existing)
│   └── connect.php       # (existing)
├── api/                  # (existing)
├── assets/               # (existing)
├── scripts/              # ← CREATE THIS FOLDER
│   ├── vatsim_adl_daemon.php   # ← PUT HERE
│   ├── vatsim_adl.log          # (auto-created)
│   └── vatsim_adl.lock         # (auto-created)
└── ...
```

---

## Required: config.php Constants

Your `load/config.php` must define these constants:

```php
// Azure SQL / ADL Connection
define('ADL_SERVER',   'your-server.database.windows.net');
define('ADL_DATABASE', 'VATSIM_ADL');
define('ADL_USERNAME', 'your_username');
define('ADL_PASSWORD', 'your_password');
```

The daemon auto-loads these from `../load/config.php` relative to its location.

---

## Performance Targets

| Flights | Target Time | Status |
|---------|-------------|--------|
| 3,000   | < 5 seconds | ✅ Normal |
| 6,000   | < 10 seconds | ⚠️ Acceptable |
| 6,000+  | > 10 seconds | 🔴 Needs SP optimization |

The daemon logs warnings:
- `[WARN]` if SP takes > 5 seconds
- `[ERROR] [CRITICAL]` if SP takes > 10 seconds

If you consistently see slow times, the stored procedure needs optimization (indexes, query tuning).

---

## Linux Setup

### 1. Create scripts directory

```bash
mkdir -p /var/www/perti/wwwroot/scripts
cp vatsim_adl_daemon.php /var/www/perti/wwwroot/scripts/
chown -R www-data:www-data /var/www/perti/wwwroot/scripts
chmod 755 /var/www/perti/wwwroot/scripts/vatsim_adl_daemon.php
```

### 2. Install PHP extensions (if not already)

```bash
# SQL Server ODBC driver
curl https://packages.microsoft.com/keys/microsoft.asc | sudo apt-key add -
curl https://packages.microsoft.com/config/ubuntu/$(lsb_release -rs)/prod.list | \
    sudo tee /etc/apt/sources.list.d/mssql-release.list
sudo apt update
sudo ACCEPT_EULA=Y apt install -y msodbcsql18 unixodbc-dev

# PHP extensions
sudo pecl install sqlsrv pdo_sqlsrv
echo "extension=sqlsrv.so" | sudo tee /etc/php/8.*/cli/conf.d/20-sqlsrv.ini
echo "extension=pdo_sqlsrv.so" | sudo tee /etc/php/8.*/cli/conf.d/30-pdo_sqlsrv.ini

# Also install curl for better performance
sudo apt install -y php-curl
```

### 3. Test manually

```bash
cd /var/www/perti/wwwroot
php scripts/vatsim_adl_daemon.php
```

You should see:
```
[2025-12-19 23:30:00Z] [INFO] === VATSIM ADL Daemon Starting === {...}
[2025-12-19 23:30:00Z] [INFO] Database connected
[2025-12-19 23:30:01Z] [INFO] Refresh #1 {"pilots":1523,"json_kb":2145,"fetch_ms":312,"sp_ms":2847}
```

Press `Ctrl+C` to stop.

### 4. Install systemd service

```bash
# Copy service file
sudo cp vatsim-adl.service /etc/systemd/system/

# Edit paths if needed
sudo nano /etc/systemd/system/vatsim-adl.service

# Reload and enable
sudo systemctl daemon-reload
sudo systemctl enable vatsim-adl
sudo systemctl start vatsim-adl

# Check status
sudo systemctl status vatsim-adl

# View live logs
sudo journalctl -u vatsim-adl -f
```

---

## Windows Setup (IIS Environment)

### Option A: Run as Windows Service with NSSM

1. Download NSSM: https://nssm.cc/download

2. Install service:
```cmd
nssm install VatsimADL "C:\php\php.exe" "C:\inetpub\wwwroot\scripts\vatsim_adl_daemon.php"
nssm set VatsimADL AppDirectory "C:\inetpub\wwwroot"
nssm set VatsimADL DisplayName "VATSIM ADL Refresh Daemon"
nssm set VatsimADL Start SERVICE_AUTO_START
nssm set VatsimADL AppPriority ABOVE_NORMAL_PRIORITY_CLASS
nssm start VatsimADL
```

### Option B: Run in PowerShell (for testing)

```powershell
cd C:\inetpub\wwwroot
php scripts\vatsim_adl_daemon.php
```

---

## Monitoring

### Check daemon logs

```bash
# Linux (systemd)
sudo journalctl -u vatsim-adl -f

# Linux (file)
tail -f /var/www/perti/wwwroot/scripts/vatsim_adl.log

# Windows
type C:\inetpub\wwwroot\scripts\vatsim_adl.log
```

### Check database

```sql
-- Recent runs (should show flights_count > 0)
SELECT TOP 20 
    id, started_utc, duration_ms, flights_count, 
    ms_insert, ms_update1, ms_update2, ms_history
FROM dbo.adl_run_log 
ORDER BY id DESC;

-- Performance analysis
SELECT 
    AVG(duration_ms) AS avg_ms,
    MAX(duration_ms) AS max_ms,
    AVG(flights_count) AS avg_flights,
    MAX(flights_count) AS max_flights,
    COUNT(*) AS runs_last_hour
FROM dbo.adl_run_log 
WHERE started_utc > DATEADD(HOUR, -1, SYSUTCDATETIME());

-- History snapshots
SELECT TOP 10 snapshot_utc, COUNT(*) AS flight_count
FROM dbo.adl_flights_history 
GROUP BY snapshot_utc 
ORDER BY snapshot_utc DESC;
```

---

## Troubleshooting

### "Cannot find config at ..."
- Make sure the script is in `wwwroot/scripts/`
- The daemon looks for `../load/config.php` relative to itself

### "ADL_* constants not defined"
- Add to your `load/config.php`:
```php
define('ADL_SERVER', 'your-server.database.windows.net');
define('ADL_DATABASE', 'VATSIM_ADL');
define('ADL_USERNAME', 'your_user');
define('ADL_PASSWORD', 'your_pass');
```

### "Another instance is already running"
```bash
# Check for existing process
ps aux | grep vatsim_adl

# Remove stale lock file if process isn't running
rm /var/www/perti/wwwroot/scripts/vatsim_adl.lock
```

### SP taking > 10 seconds consistently
The stored procedure needs optimization. Check:
1. Indexes exist (see transfer document)
2. `adl_flights_history` isn't too large (purge old data)
3. Consider adding `@step_history` fix mentioned in transfer doc

### High memory usage
Expected: ~200-400MB during operation. If higher:
- Check for memory leaks in SP
- Restart daemon periodically via systemd

---

## Disable Power Automate Flows

Once the daemon is working:

1. Go to Power Automate portal
2. Find flows: `VATSIM ADL+00`, `+15`, `+30`, `+45`
3. Turn each one **Off**
4. Keep them (don't delete) as backup

---

## Maintenance

### Restart daemon
```bash
sudo systemctl restart vatsim-adl
```

### View recent errors
```bash
sudo journalctl -u vatsim-adl -p err -n 50
```

### Check uptime and stats
```bash
sudo systemctl status vatsim-adl
# Or check the 100-run stats in logs
```
