# Getting Started

This guide covers setting up PERTI for local development or deployment.

---

## Prerequisites

### Required Software

| Software | Version | Purpose |
|----------|---------|---------|
| PHP | 8.2+ | Backend runtime |
| Composer | 2.x | PHP dependency management |
| MySQL | 8.0+ | Application database |
| Node.js | 18+ | Build tools (optional) |
| Python | 3.9+ | Daemon scripts |
| Git | 2.x | Version control |

### PHP Extensions

```
pdo_mysql
pdo_sqlsrv (for Azure SQL)
pdo_pgsql  (for PostgreSQL/PostGIS)
sqlsrv
curl
json
mbstring
openssl
```

### Azure Resources (Production)

- **Azure SQL Database** - VATSIM_ADL (Hyperscale Serverless), VATSIM_TMI, SWIM_API, VATSIM_REF (Basic)
- **Azure Database for MySQL** - perti_site (General Purpose D2ds_v4)
- **Azure Database for PostgreSQL** - vatcscc_gis with PostGIS extension (Burstable B2s)
- **Azure App Service** - P1v2 Linux with custom startup
- **VATSIM Connect OAuth** application

---

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/your-org/PERTI.git
cd PERTI
```

### 2. Install Dependencies

```bash
# PHP dependencies (if using Composer)
composer install

# Python dependencies for daemons
pip install -r scripts/requirements.txt
```

### 3. Configure Environment

Copy the example configuration file:

```bash
cp load/config.example.php load/config.php
```

Edit `load/config.php` with your settings. See [[Configuration]] for details.

### 4. Initialize Databases

**MySQL (PERTI Application):**

```bash
mysql -u root -p < database/migrations/schema/001_initial.sql
# Run additional migrations as needed
```

**Azure SQL (ADL):**

Run migrations in order from `adl/migrations/`:

```bash
# Core tables
sqlcmd -S your-server.database.windows.net -d VATSIM_ADL -i adl/migrations/core/001_base_tables.sql
# Continue with other migration folders...
```

### 5. Configure Web Server

**Apache (`.htaccess` included):**

Point document root to the PERTI directory.

**IIS:**

Import `web.config` or configure URL rewrite rules manually.

### 6. Start Daemons

```bash
# VATSIM ADL refresh daemon
php scripts/vatsim_adl_daemon.php &

# ATIS import daemon
python scripts/vatsim_atis/atis_daemon.py &

# Parse queue daemon
php adl/php/parse_queue_daemon.php --loop &
```

---

## Verification

### Check Web Application

Navigate to `http://localhost/` - you should see the PERTI home page.

### Check Public Pages

- `/jatoc.php` - JATOC should load (no login required)
- `/nod.php` - NOD should load (no login required)

### Check Authentication

Navigate to `/login/` to test VATSIM OAuth flow.

### Check Database Connectivity

```php
// Create a test script
<?php
require_once 'load/config.php';
require_once 'load/connect.php';

echo "MySQL: " . ($conn ? "Connected" : "Failed") . "\n";
echo "ADL: " . ($adl_conn ? "Connected" : "Failed") . "\n";
```

---

## Directory Structure Overview

```
PERTI/
├── api/            # API endpoints
├── assets/         # CSS, JS, images, data files
├── database/       # MySQL migrations
├── adl/            # Azure SQL ADL system
│   ├── migrations/ # ADL database migrations
│   ├── php/        # ADL PHP scripts
│   └── procedures/ # Stored procedures
├── docs/           # Documentation
├── load/           # Shared PHP includes
├── login/          # VATSIM OAuth
├── scripts/        # Background daemons
└── sessions/       # Session handling
```

---

## Next Steps

- [[Configuration]] - Detailed configuration options
- [[Architecture]] - Understand the system design
- [[Deployment]] - Deploy to Azure
- [[API Reference]] - Explore the APIs

---

## Common Issues

### "Cannot connect to database"

- Verify MySQL is running
- Check credentials in `load/config.php`
- Ensure database exists and user has permissions

### "ADL connection failed"

- Verify Azure SQL firewall allows your IP
- Check `ADL_*` constants in config
- Ensure `pdo_sqlsrv` extension is loaded

### "OAuth redirect error"

- Verify VATSIM OAuth callback URL matches your domain
- Check `VATSIM_*` constants in config

See [[Troubleshooting]] for more solutions.
