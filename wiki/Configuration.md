# Configuration

PERTI configuration is managed through `load/config.php`. This file is not committed to version control for security.

---

## Configuration File Setup

Copy the example file to create your configuration:

```bash
cp load/config.example.php load/config.php
```

---

## Required Configuration

### MySQL Database

```php
// MySQL (PERTI Application Database)
define('DB_HOST', 'localhost');
define('DB_NAME', 'perti');
define('DB_USER', 'perti_user');
define('DB_PASS', 'your_secure_password');
```

### Azure SQL (ADL Database)

```php
// Azure SQL Server connection
define('ADL_SERVER', 'your-server.database.windows.net');
define('ADL_DATABASE', 'VATSIM_ADL');
define('ADL_USERNAME', 'your_username');
define('ADL_PASSWORD', 'your_password');
```

### VATSIM OAuth

Register an application at [VATSIM Connect](https://auth.vatsim.net/) to obtain credentials:

```php
// VATSIM Connect OAuth
define('VATSIM_OAUTH_URL', 'https://auth.vatsim.net');
define('VATSIM_CLIENT_ID', 'your_client_id');
define('VATSIM_CLIENT_SECRET', 'your_client_secret');
define('VATSIM_REDIRECT_URI', 'https://your-domain.com/login/callback.php');
```

---

## Optional Configuration

### Site Settings

```php
// Site configuration
define('SITE_NAME', 'vATCSCC PERTI');
define('SITE_URL', 'https://vatcscc.azurewebsites.net');
define('TIMEZONE', 'UTC');
```

### Discord Integration

```php
// Discord webhook for TMI notifications
define('DISCORD_WEBHOOK_URL', 'https://discord.com/api/webhooks/...');
define('DISCORD_TMI_CHANNEL', 'webhook_url_for_tmi_channel');
```

### Feature Flags

```php
// Feature toggles
define('FEATURE_WEATHER_RADAR', true);
define('FEATURE_SUA_DISPLAY', true);
define('FEATURE_DEMAND_ANALYSIS', true);
```

### Debug Settings

```php
// Development/debugging (set false in production)
define('DEBUG_MODE', false);
define('SHOW_ERRORS', false);
define('LOG_QUERIES', false);
```

---

## Environment-Specific Configuration

### Development

```php
define('DEBUG_MODE', true);
define('SHOW_ERRORS', true);
define('DB_HOST', 'localhost');
```

### Production (Azure)

```php
define('DEBUG_MODE', false);
define('SHOW_ERRORS', false);
define('DB_HOST', getenv('MYSQL_HOST') ?: 'production-server');
```

### Using Environment Variables

For Azure App Service, use application settings:

```php
// Read from environment variables with fallbacks
define('ADL_SERVER', getenv('ADL_SERVER') ?: 'default-server');
define('ADL_DATABASE', getenv('ADL_DATABASE') ?: 'VATSIM_ADL');
define('ADL_USERNAME', getenv('ADL_USERNAME') ?: 'default_user');
define('ADL_PASSWORD', getenv('ADL_PASSWORD') ?: '');
```

---

## Daemon Configuration

### VATSIM ADL Daemon

The daemon reads from `load/config.php` automatically. Additional settings:

| Setting | Default | Description |
|---------|---------|-------------|
| Interval | 15s | Time between VATSIM API calls |
| Timeout | 30s | HTTP request timeout |
| Lock file | `scripts/vatsim_adl.lock` | Prevents duplicate instances |
| Log file | `scripts/vatsim_adl.log` | Daemon output log |

### Parse Queue Daemon

Command-line options:

```bash
php parse_queue_daemon.php --loop              # Continuous mode
php parse_queue_daemon.php --batch=100         # Custom batch size
php parse_queue_daemon.php --interval=10       # Custom interval (seconds)
```

### ATIS Daemon

```bash
python atis_daemon.py --once                   # Single run
python atis_daemon.py --airports KJFK,KLAX     # Filter airports
python atis_daemon.py                          # Continuous (default)
```

---

## Navigation Data Configuration

### NASR Data Path

```php
// Path to navigation data CSVs
define('NAVDATA_PATH', __DIR__ . '/../assets/data/');
```

### Playbook Routes

```php
// FAA playbook route data
define('PLAYBOOK_CSV', __DIR__ . '/../assets/data/playbook_routes.csv');
```

---

## Weather Configuration

### IEM Radar Tiles

```php
// Iowa Environmental Mesonet tile server
define('IEM_TILE_URL', 'https://mesonet.agron.iastate.edu/cache/tile.py');
define('IEM_RADAR_PRODUCT', 'nexrad-n0q');  // Base reflectivity
```

### Weather Alert Sources

```php
// Aviation weather sources
define('AWC_SIGMET_URL', 'https://aviationweather.gov/api/data/sigmet');
define('AWC_AIRMET_URL', 'https://aviationweather.gov/api/data/airmet');
```

---

## Session Configuration

```php
// Session settings
define('SESSION_LIFETIME', 86400);  // 24 hours
define('SESSION_PATH', __DIR__ . '/../sessions/');
```

---

## Security Configuration

### HTTPS Enforcement

```php
// Force HTTPS in production
define('FORCE_HTTPS', true);
```

### CORS Settings

```php
// Allowed origins for API requests
define('CORS_ORIGINS', [
    'https://vatcscc.azurewebsites.net',
    'https://your-custom-domain.com'
]);
```

---

## Configuration Validation

Create a test script to validate your configuration:

```php
<?php
// test_config.php
require_once 'load/config.php';

$checks = [
    'DB_HOST' => defined('DB_HOST'),
    'ADL_SERVER' => defined('ADL_SERVER'),
    'VATSIM_CLIENT_ID' => defined('VATSIM_CLIENT_ID'),
];

foreach ($checks as $const => $defined) {
    echo "$const: " . ($defined ? "OK" : "MISSING") . "\n";
}
```

---

## See Also

- [[Getting Started]] - Initial setup guide
- [[Deployment]] - Azure deployment configuration
- [[Troubleshooting]] - Configuration issues
