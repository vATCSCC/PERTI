<?php
/**
 * PERTI Codebase & Database Indexer Cron Job
 *
 * Generates comprehensive indexes of the codebase and database schemas
 * for use by coding agents (AI assistants, documentation tools, etc.)
 *
 * Schedule: Every 12 hours
 *   0 */12 * * * php /home/site/wwwroot/cron/run_indexer.php
 *   OR via HTTP:
 *   0 */12 * * * curl -s "https://perti.vatcscc.net/cron/run_indexer.php?cron_key=YOUR_KEY"
 *
 * Output: /data/indexes/
 *   - codebase_index.json      Full codebase structure
 *   - codebase_index.md        Human-readable codebase docs
 *   - database_schema.json     Full database schemas
 *   - database_schema.md       Human-readable schema docs
 *   - agent_context.md         Combined summary for AI agents
 *   - index_manifest.json      Metadata and timestamps
 *
 * @package PERTI
 * @subpackage Cron
 * @version 1.0.0
 * @date 2026-02-01
 */

// Redirect to the main indexer runner script
define('SCHEDULER_CONTEXT', true);
require_once __DIR__ . '/../scripts/indexer/run_indexer.php';
