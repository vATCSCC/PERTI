# Daemons and Scripts

Background processes that keep PERTI data current.

---

## Active Daemons

### vatsim_adl_daemon.php

Refreshes flight data from VATSIM API.

| Setting | Value |
|---------|-------|
| Location | `scripts/vatsim_adl_daemon.php` |
| Interval | ~15 seconds |
| Language | PHP |

**Usage:**
```bash
php scripts/vatsim_adl_daemon.php
```

---

### parse_queue_daemon.php

Processes route parsing queue.

| Setting | Value |
|---------|-------|
| Location | `adl/php/parse_queue_daemon.php` |
| Interval | 5 seconds |
| Language | PHP |

**Usage:**
```bash
php adl/php/parse_queue_daemon.php --loop
php adl/php/parse_queue_daemon.php --batch=100
```

---

### atis_daemon.py

Imports ATIS data from VATSIM with weather parsing.

| Setting | Value |
|---------|-------|
| Location | `scripts/vatsim_atis/atis_daemon.py` |
| Interval | 15 seconds |
| Language | Python |

**Usage:**
```bash
python scripts/vatsim_atis/atis_daemon.py
python scripts/vatsim_atis/atis_daemon.py --once
python scripts/vatsim_atis/atis_daemon.py --airports KJFK,KLAX
```

---

## Import Scripts

| Script | Purpose | Schedule |
|--------|---------|----------|
| `import_weather_alerts.php` | SIGMET/AIRMET updates | Every 5 min |
| `nasr_navdata_updater.py` | FAA NASR data | On demand |
| `update_playbook_routes.py` | FAA playbook routes | On demand |

---

## Running as Services

### Linux (systemd)

```bash
sudo cp vatsim-adl.service /etc/systemd/system/
sudo systemctl enable vatsim-adl
sudo systemctl start vatsim-adl
```

### Windows (NSSM)

```cmd
nssm install VatsimADL "C:\php\php.exe" "path\to\vatsim_adl_daemon.php"
nssm start VatsimADL
```

---

## See Also

- [[Deployment]] - Service setup
- [[Data Flow]] - Data pipelines
- [[Troubleshooting]] - Common issues
