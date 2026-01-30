# PERTI Project Instructions for Claude

## Database & Infrastructure Access

**IMPORTANT**: You have full access to all project databases and Azure resources.

Credentials and connection details are documented in: `.claude/credentials.md`

Read this file when you need to:
- Query any database directly
- Access Azure resources via Kudu SSH
- Connect to MySQL, Azure SQL, or PostgreSQL databases
- Use API keys or OAuth credentials

### Quick Reference (Common Databases)

| Database | Type | Host | Purpose |
|----------|------|------|---------|
| perti_site | MySQL | vatcscc-perti.mysql.database.azure.com | Main web app |
| VATSIM_ADL | Azure SQL | vatsim.database.windows.net | Flight data |
| VATSIM_TMI | Azure SQL | vatsim.database.windows.net | Traffic management |
| VATSIM_REF | Azure SQL | vatsim.database.windows.net | Reference data |
| VATSIM_GIS | PostgreSQL | vatcscc-gis.postgres.database.azure.com | Spatial queries |
| VATSIM_STATS | Azure SQL | vatsim.database.windows.net | Statistics |

Azure resource config is in: `load/azure_perti_config.json`

## Project Structure

- `/api/` - PHP REST API endpoints
- `/load/` - Configuration files (config.php is gitignored)
- `/database/migrations/` - SQL migration scripts organized by database (tmi/, ref/, adl/, etc.)
- `/discord-bot/` - Node.js Discord bot
- `/scripts/` - Utility and maintenance scripts
- `/adl/` - ADL (Aeronautical Data Library) components

## Key Technologies

- **Backend**: PHP 8.x on Azure App Service (Linux)
- **Databases**: Azure MySQL, Azure SQL Server, Azure PostgreSQL (PostGIS)
- **Frontend**: Vanilla JS with custom component system
- **Discord**: Discord.js bot with slash commands
- **Hosting**: Azure App Service with GitHub Actions deployment

## Code Conventions

- Database connections use PDO with prepared statements
- API endpoints follow REST conventions in `/api/{resource}/{action}.php`
- Config values are loaded via `env()` helper (supports Azure App Settings)
