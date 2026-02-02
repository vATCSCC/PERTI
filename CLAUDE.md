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
- `/adl/` - ADL (Aggregate Demand List) components

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

## Background Jobs & Scheduled Tasks

**IMPORTANT**: Use PHP daemons with `scripts/startup.sh`, NOT Azure Functions.

All scheduled and background tasks run as long-lived PHP processes managed by `scripts/startup.sh`:

- Daemons are started at App Service boot and run continuously
- Use `nohup php ... &` pattern for background execution
- Logs go to `/home/LogFiles/<daemon>.log`
- Environment variables control feature flags and connections

When implementing new scheduled tasks:

1. Create a PHP daemon in `/scripts/` (e.g., `my_daemon.php`)
2. Add startup command to `scripts/startup.sh`
3. Use sleep loops or time-based scheduling within the daemon
4. Check for required env vars before starting

See `scripts/adl_archive_daemon.php` for a daily-scheduled job example.

## Git Worktrees

**IMPORTANT**: Always use `C:/Temp/perti-worktrees/` for git worktrees.

The main repository path (OneDrive) is too long for Windows' 260-character limit, causing worktree creation to fail when using project-local directories like `.worktrees/`.

```bash
# Correct - use short temp path
git worktree add C:/Temp/perti-worktrees/<branch-name> -b feature/<branch-name>

# Incorrect - will fail due to path length
git worktree add .worktrees/<branch-name> -b feature/<branch-name>
```

Current worktrees can be listed with `git worktree list`.
