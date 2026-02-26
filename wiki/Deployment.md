# Deployment

This guide covers deploying PERTI to Azure App Service and managing the production environment.

---

## Deployment Architecture

PERTI runs on Microsoft Azure with the following components:

| Component | Azure Service | Tier | Monthly Cost |
|-----------|---------------|------|-------------|
| Web Application | Azure App Service | P1v2 (3.5GB, 1 vCPU) | ~$81 |
| Application Database | Azure Database for MySQL | General Purpose D2ds_v4 | ~$134 |
| ADL Database | Azure SQL Database | Hyperscale Serverless (3/16 vCores) | ~$3,200 |
| TMI Database | Azure SQL Database | Basic (5 DTU) | ~$5 |
| SWIM API Database | Azure SQL Database | Basic (5 DTU) | ~$5 |
| Reference Database | Azure SQL Database | Basic (5 DTU) | ~$5 |
| GIS Database | Azure Database for PostgreSQL | Burstable B2s, PostGIS | ~$58 |
| CI/CD | GitHub Actions | N/A | Free |

**Total: ~$3,500/month** (as of February 2026)

---

## Azure App Service Configuration

### Runtime Settings

| Setting | Value |
|---------|-------|
| Runtime | PHP 8.2 |
| Platform | Linux (custom startup via `scripts/startup.sh`) |
| App Service Plan | P1v2 (3.5GB RAM, 1 vCPU) |
| PHP-FPM Workers | 40 |
| ODBC Driver | 18 (installed via startup.sh) |

### Application Settings

Configure these in Azure Portal > App Service > Configuration > Application Settings:

| Setting | Description |
|---------|-------------|
| `DB_HOST` | MySQL server hostname |
| `DB_NAME` | MySQL database name |
| `DB_USER` | MySQL username |
| `DB_PASS` | MySQL password |
| `ADL_SERVER` | Azure SQL server (*.database.windows.net) |
| `ADL_DATABASE` | Azure SQL database name |
| `ADL_USERNAME` | Azure SQL username |
| `ADL_PASSWORD` | Azure SQL password |
| `VATSIM_CLIENT_ID` | VATSIM OAuth client ID |
| `VATSIM_CLIENT_SECRET` | VATSIM OAuth secret |
| `VATSIM_REDIRECT_URI` | OAuth callback URL |

### Connection Strings

Add under Configuration > Connection Strings:

```
MySQL: mysql://user:pass@host/database
AzureSQL: sqlsrv:Server=server;Database=db
```

---

## CI/CD Pipeline

### GitHub Actions Workflow

The primary deployment workflow is located at `.github/workflows/main_vatcscc.yml`:

```yaml
name: Deploy to Azure Web App

on:
  push:
    branches:
      - main

jobs:
  build-and-deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install Dependencies
        run: composer install --no-dev --optimize-autoloader

      - name: Deploy to Azure
        uses: azure/webapps-deploy@v2
        with:
          app-name: 'vatcscc'
          publish-profile: ${{ secrets.AZURE_WEBAPP_PUBLISH_PROFILE }}
```

### Azure Pipelines (Alternative)

Configuration at `azure-pipelines.yml`:

| Stage | Actions |
|-------|---------|
| Build | PHP setup, Composer install, artifact creation |
| Deploy | Azure Web App deployment |

---

## Database Migrations

### MySQL Migrations

Apply migrations in order from `database/migrations/`:

```bash
# Connect to Azure MySQL
mysql -h your-server.mysql.database.azure.com \
      -u admin@your-server -p perti \
      < database/migrations/schema/001_initial.sql
```

### Azure SQL (ADL) Migrations

Apply migrations from `adl/migrations/`:

```bash
# Using sqlcmd
sqlcmd -S your-server.database.windows.net \
       -d VATSIM_ADL -U admin -P 'password' \
       -i adl/migrations/core/001_base_tables.sql
```

**Migration Order:**
1. `core/` - Foundation tables
2. `navdata/` - Navigation data
3. `eta/` - ETA calculation
4. `oooi/` - Zone detection
5. `boundaries/` - Sector boundaries
6. `weather/` - Weather integration
7. `tmi/` - TMI workflows
8. Numbered migrations (079-091)

---

## Daemon Deployment

All 15 background daemons run inside the same Azure App Service container, started at boot via `scripts/startup.sh`. There is no separate daemon host — daemons are `nohup` PHP processes launched before PHP-FPM starts in the foreground.

### Startup Sequence

`scripts/startup.sh` executes the following in order:

1. **Configure nginx** — copies custom `default` site config for extensionless URLs
2. **Start daemons** — each launched via `nohup php ... >> /home/LogFiles/<name>.log 2>&1 &`
3. **Configure OPcache** — 128MB, revalidate every 60s
4. **Configure PHP-FPM** — 40 workers (P1v2 tier), status page at `/fpm-status`
5. **Start PHP-FPM** — runs in foreground (`php-fpm -F`) to keep the container alive

### Daemons Started

| Daemon | Script | Mode |
|--------|--------|------|
| ADL Ingest | `scripts/vatsim_adl_daemon.php` | 15s loop |
| Parse Queue (GIS) | `adl/php/parse_queue_gis_daemon.php` | 10s batch (GIS mode) |
| Boundary Detection (GIS) | `adl/php/boundary_gis_daemon.php` | 15s loop (GIS mode) |
| Crossing Calculation | `adl/php/crossing_gis_daemon.php` | Tiered loop (GIS mode) |
| Waypoint ETA | `adl/php/waypoint_eta_daemon.php` | Tiered loop |
| SWIM WebSocket | `scripts/swim_ws_server.php` | Persistent (port 8090) |
| SWIM Sync | `scripts/swim_sync_daemon.php` | 2min sync, 6h cleanup |
| SimTraffic Poll | `scripts/simtraffic_swim_poll.php` | 2min loop |
| Reverse Sync | `scripts/swim_adl_reverse_sync_daemon.php` | 2min loop |
| Scheduler | `scripts/scheduler_daemon.php` | 60s loop |
| Archival | `scripts/archival_daemon.php` | 1-4h adaptive |
| Monitoring | `scripts/monitoring_daemon.php` | 60s loop |
| Discord Queue | `scripts/tmi/process_discord_queue.php` | Continuous |
| Event Sync | `scripts/event_sync_daemon.php` | 6h loop |
| ADL Archive | `scripts/adl_archive_daemon.php` | Daily at 10:00Z (conditional) |

The ADL Archive daemon only starts if `ADL_ARCHIVE_STORAGE_CONN` is set. An indexer script also runs once at startup (30s delayed) to generate `agent_context.md`.

### GIS Mode Switch

The startup script has a `USE_GIS_DAEMONS` toggle (default: `1`). When enabled, PostGIS-based daemons handle spatial operations (parse queue, boundary detection, crossing calculation). When disabled, legacy ADL-only daemons are used instead. This switch is scheduled for removal after the evaluation period.

### PHP-FPM Worker Sizing

Memory calculation for `pm.max_children`:

```
max_children = (TOTAL_RAM - 500MB) / 50MB
```

| App Service Tier | RAM | Recommended Workers |
|-----------------|-----|---------------------|
| B1/S1 | 1.75GB | 25 |
| P1v2 (current) | 3.5GB | 40-60 |
| P2v2 | 7GB | 80-130 |

### Daemon Logs

All daemon logs write to `/home/LogFiles/`:

```bash
# Stream logs via Azure CLI
az webapp log tail --resource-group PERTI-RG --name vatcscc

# Or via Kudu SSH
tail -f /home/LogFiles/vatsim_adl.log
tail -f /home/LogFiles/parse_queue_gis.log
```

See [[Daemons and Scripts]] for detailed daemon documentation.

---

## Pre-Deployment Checklist

Before deploying to production:

- [ ] All tests pass locally
- [ ] Configuration validated (`load/config.php`)
- [ ] Database migrations tested on staging
- [ ] No secrets in committed code
- [ ] Dependencies up to date (`composer update`)
- [ ] Static assets optimized
- [ ] Error pages configured
- [ ] SSL certificate valid
- [ ] Backup current database

---

## Deployment Steps

### Standard Deployment

1. **Merge to Main Branch**
   ```bash
   git checkout main
   git merge feature-branch
   git push origin main
   ```

2. **Monitor GitHub Actions**
   - Check workflow at Actions tab
   - Verify build succeeds
   - Confirm deployment completes

3. **Verify Deployment**
   - Check production URL
   - Test authentication flow
   - Verify public pages load
   - Check daemon status

### Manual Deployment

If automated deployment fails:

```bash
# Create deployment package
zip -r deploy.zip . -x "*.git*" -x "load/config.php"

# Deploy via Azure CLI
az webapp deployment source config-zip \
  --resource-group PERTI-RG \
  --name vatcscc \
  --src deploy.zip
```

---

## Rollback Procedure

If issues occur after deployment:

### Via Azure Portal

1. Navigate to App Service > Deployment Center
2. Select previous successful deployment
3. Click "Redeploy"

### Via Azure CLI

```bash
# List deployments
az webapp deployment list --resource-group PERTI-RG --name vatcscc

# Rollback to specific deployment
az webapp deployment source sync --resource-group PERTI-RG --name vatcscc
```

### Database Rollback

If migration caused issues:

1. Restore from backup
2. Or apply rollback migration if provided

---

## Monitoring

### Azure Application Insights

Enable for production monitoring:

- Request tracking
- Error logging
- Performance metrics
- Custom events

### Health Checks

Configure health check endpoint:

| Endpoint | Expected | Action if Failed |
|----------|----------|------------------|
| `/api/health.php` | HTTP 200 | Restart instance |

### Log Access

```bash
# Stream live logs
az webapp log tail --resource-group PERTI-RG --name vatcscc

# Download logs
az webapp log download --resource-group PERTI-RG --name vatcscc
```

---

## Scaling

### Vertical Scaling

Upgrade App Service Plan:

| Plan | Use Case |
|------|----------|
| B1 | Development/Testing |
| B2 | Light production |
| S1 | Standard production |
| P1V2 | High traffic events |

### Horizontal Scaling

Enable auto-scaling based on CPU/memory:

1. App Service > Scale out
2. Configure rules (e.g., scale up at 70% CPU)
3. Set instance limits

---

## Security Considerations

### Production Security Checklist

- [ ] HTTPS enforced (HSTS enabled)
- [ ] Azure SQL firewall configured
- [ ] App Service IP restrictions (if needed)
- [ ] Secrets in Azure Key Vault
- [ ] Managed Identity for database access
- [ ] Regular security updates applied

### Firewall Rules

Azure SQL Database firewall must allow:
- Azure services (for App Service)
- Daemon host IPs
- Developer IPs (for migrations)

---

## See Also

- [[Configuration]] - Environment configuration
- [[Daemons and Scripts]] - Background process setup
- [[Troubleshooting]] - Common deployment issues
