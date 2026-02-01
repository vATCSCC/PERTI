# PERTI Codebase & Database Indexer

Automated indexing system that generates comprehensive documentation for coding agents (Claude, GitHub Copilot, etc.) to quickly understand the codebase and database structure.

## Overview

The indexer runs every 12 hours and creates:

| File | Description |
|------|-------------|
| `codebase_index.json` | Full codebase structure (API endpoints, classes, functions) |
| `codebase_index.md` | Human-readable codebase documentation |
| `database_schema.json` | Complete database schemas for all 6 databases |
| `database_schema.md` | Human-readable schema documentation |
| `database_quick_reference.md` | Quick lookup table for tables/columns |
| `agent_context.md` | Combined summary optimized for AI agents |
| `index_manifest.json` | Index metadata, timestamps, stats |
| `indexer.log` | Execution log |

## Output Location

All index files are saved to: `/data/indexes/`

## Running the Indexer

### Manual Execution (CLI)

```bash
# From project root
php scripts/indexer/run_indexer.php

# Or run individual indexers
php scripts/indexer/codebase_indexer.php
php scripts/indexer/database_indexer.php
```

### HTTP Trigger

```
GET /cron/run_indexer.php?cron_key=perti_indexer_2026
```

Set custom key via environment variable: `INDEXER_CRON_KEY`

### Azure Cron Setup

Add to Azure App Service WebJobs or use an external scheduler:

```bash
# Every 12 hours at midnight and noon UTC
0 0,12 * * * /usr/local/bin/php /home/site/wwwroot/cron/run_indexer.php
```

Or via HTTP with Azure Logic Apps / external cron service:

```bash
0 0,12 * * * curl -s "https://perti.vatcscc.net/cron/run_indexer.php?cron_key=YOUR_KEY"
```

## What Gets Indexed

### Codebase Indexer

- **API Endpoints**: All `/api/**/*.php` files with methods, parameters, tables used
- **PHP Classes**: Class definitions, inheritance, methods
- **Functions**: Standalone function definitions
- **JavaScript Modules**: ES6 exports, imports, purpose detection
- **SQL Migrations**: DDL operations per database
- **Configuration**: Constants, settings files
- **Page Routes**: Root-level PHP pages
- **Daemons/Cron**: Background processes and scheduled jobs

### Database Indexer

Connects to all configured databases:

| Database | Type | Purpose |
|----------|------|---------|
| perti_site | MySQL | Main web app (users, plans, config) |
| VATSIM_ADL | Azure SQL | Flight data, tracks |
| VATSIM_TMI | Azure SQL | TMIs, GDPs, reroutes |
| VATSIM_REF | Azure SQL | Reference data (airports, fixes) |
| VATSIM_GIS | PostgreSQL | PostGIS spatial queries |
| SWIM_API | Azure SQL | SWIM integration |
| VATSIM_STATS | Azure SQL | Statistics |

For each database, extracts:
- Tables with columns, types, nullability
- Indexes (clustered, unique, composite)
- Foreign key relationships
- Views
- Stored procedures with parameters
- Row counts

## Using the Index

### For Coding Agents (Claude, etc.)

Point the agent to read `/data/indexes/agent_context.md` for a quick overview, or the full JSON files for detailed searches.

Example prompt:
> "Read /data/indexes/agent_context.md to understand the codebase structure, then help me add a new TMI endpoint."

### For IDE Integration

The JSON indexes can be used with IDE plugins that support codebase context.

### For Documentation

The markdown files can be published to a wiki or documentation site.

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `INDEXER_CRON_KEY` | `perti_indexer_2026` | HTTP access key |

### Skipped Directories

The codebase indexer skips:
- `vendor/`
- `node_modules/`
- `.git/`
- `.claude/`
- `assets/vendor/`
- `assets/lib/`
- `assets/fonts/`

## Troubleshooting

### Database Connection Errors

Check that all database credentials are defined in `load/config.php`. Missing credentials will be logged but won't stop other databases from being indexed.

### Memory Issues

The indexer sets `memory_limit=512M`. For very large codebases, increase this in `run_indexer.php`.

### Timeout Issues

Execution time limit is 10 minutes. If indexing takes longer, increase `set_time_limit()` in `run_indexer.php`.

## File Structure

```
scripts/indexer/
├── README.md              # This file
├── codebase_indexer.php   # Codebase analysis
├── database_indexer.php   # Database schema extraction
└── run_indexer.php        # Main orchestrator

cron/
└── run_indexer.php        # Cron entry point

data/indexes/              # Output directory (auto-created)
├── codebase_index.json
├── codebase_index.md
├── database_schema.json
├── database_schema.md
├── database_quick_reference.md
├── agent_context.md
├── index_manifest.json
└── indexer.log
```

## Version History

- **1.0.0** (2026-02-01): Initial release
