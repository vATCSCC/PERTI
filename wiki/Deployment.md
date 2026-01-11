# Deployment

This guide covers deploying PERTI to Azure App Service and managing the production environment.

---

## Deployment Architecture

PERTI runs on Microsoft Azure with the following components:

| Component | Azure Service | Purpose |
|-----------|---------------|---------|
| Web Application | Azure App Service | PHP hosting |
| Application Database | Azure Database for MySQL | Plans, configs, user data |
| ADL Database | Azure SQL Database | Flight data, TMI workflows |
| CI/CD | GitHub Actions + Azure Pipelines | Automated deployment |

---

## Azure App Service Configuration

### Runtime Settings

| Setting | Value |
|---------|-------|
| Runtime | PHP 8.2 |
| Platform | Windows (or Linux) |
| App Service Plan | B2 or higher recommended |

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

Background daemons run separately from the web application.

### Option 1: Azure WebJobs

Create WebJobs for PHP daemons:

1. Navigate to App Service > WebJobs
2. Add new WebJob (Continuous type)
3. Upload daemon script as ZIP
4. Configure to run continuously

### Option 2: Azure Container Instances

For Python daemons, consider ACI:

```bash
az container create \
  --resource-group PERTI-RG \
  --name atis-daemon \
  --image your-registry/atis-daemon:latest \
  --restart-policy Always
```

### Option 3: External VM

Run daemons on a dedicated VM:

```bash
# Setup systemd service
sudo cp vatsim-adl.service /etc/systemd/system/
sudo systemctl enable vatsim-adl
sudo systemctl start vatsim-adl
```

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
