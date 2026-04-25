#!/bin/bash

# Azure App Service web root
WWWROOT="/home/site/wwwroot"

# Ensure log directory exists
mkdir -p /home/LogFiles

# Ensure persistent session directory exists (/home survives deployments)
mkdir -p /home/sessions
chmod 0700 /home/sessions

echo "========================================"
echo "PERTI Daemon Startup - $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "WWWROOT: $WWWROOT"
echo "========================================"

# =============================================================================
# Hibernation Mode
# When enabled, only core daemons start (ADL ingest, archival, monitoring).
# All downstream processors (GIS, SWIM, scheduler, Discord, event sync) are
# skipped. Set via Azure App Setting: HIBERNATION_MODE=true
# See docs/operations/HIBERNATION_RUNBOOK.md for full details.
# =============================================================================
HIBERNATION_MODE=${HIBERNATION_MODE:-0}
if [ "$HIBERNATION_MODE" = "1" ] || [ "$HIBERNATION_MODE" = "true" ]; then
    echo ""
    echo "  *** HIBERNATION MODE ACTIVE ***"
    echo "  Core + SWIM daemons will start"
    echo "  Paused: GIS daemons, scheduler, event sync, CDM"
    echo ""
    HIBERNATION=1
else
    HIBERNATION=0
fi

# Configure nginx URL rewriting (Azure PHP 8 uses nginx, not Apache)
# Per Azure docs: https://azureossd.github.io/2021/09/02/php-8-rewrite-rule/
echo "Configuring nginx for extensionless URLs..."

# Copy nginx config from deployed wwwroot to nginx config directory
if [ -f "${WWWROOT}/default" ]; then
    cp "${WWWROOT}/default" /etc/nginx/sites-enabled/default
    echo "Copied nginx config from wwwroot"
else
    echo "WARNING: ${WWWROOT}/default not found, using container default"
fi

# Ensure CSV files get correct MIME type for gzip compression
# Azure container's mime.types may not include text/csv, causing 28 MB of
# uncompressed CSV data to be served as application/octet-stream
if ! grep -q "text/csv" /etc/nginx/mime.types 2>/dev/null; then
    sed -i '/^}$/i\    text/csv                              csv;' /etc/nginx/mime.types 2>/dev/null && \
        echo "Added text/csv MIME type to nginx" || \
        echo "WARNING: Could not add text/csv MIME type"
fi

# Reload nginx to apply config
service nginx reload 2>/dev/null && echo "nginx reloaded via service" || \
nginx -s reload 2>/dev/null && echo "nginx reloaded via signal" || \
echo "nginx reload failed - container may handle this"

echo "nginx configured"

# =============================================================================
# CORE DAEMONS (always start, even in hibernation)
# =============================================================================

# Start the combined VATSIM ADL daemon (ingestion + ATIS + deferred ETA every 15s)
# SP V9.2.0: trajectory always captured, ETA deferred to time-budget system
echo "Starting vatsim_adl_daemon.php (combined ingestion + ATIS)..."
nohup php "${WWWROOT}/scripts/vatsim_adl_daemon.php" >> /home/LogFiles/vatsim_adl.log 2>&1 &
ADL_PID=$!
echo "  vatsim_adl_daemon.php started (PID: $ADL_PID)"

# Start the archival daemon (trajectory tiering, changelog purge)
# Runs every 60 min during off-peak (04:00-10:00 UTC), every 4h otherwise
echo "Starting archival_daemon.php (trajectory + changelog archival)..."
nohup php "${WWWROOT}/scripts/archival_daemon.php" >> /home/LogFiles/archival.log 2>&1 &
ARCH_PID=$!
echo "  archival_daemon.php started (PID: $ARCH_PID)"

# Start the monitoring daemon (system metrics collection every 60s)
# FREE: logs to /home/LogFiles/monitoring.log for trend analysis
echo "Starting monitoring_daemon.php (system metrics)..."
nohup php "${WWWROOT}/scripts/monitoring_daemon.php" --loop >> /home/LogFiles/monitoring_daemon.log 2>&1 &
MON_PID=$!
echo "  monitoring_daemon.php started (PID: $MON_PID)"

# Start the ADL Archive daemon (daily trajectory archival to blob storage)
# Default: 10:00 UTC (lowest VATSIM traffic - night in Americas, morning in Europe)
# Override via ADL_ARCHIVE_HOUR_UTC env var (0-23)
# Requires ADL_ARCHIVE_STORAGE_CONN environment variable
if [ -n "$ADL_ARCHIVE_STORAGE_CONN" ]; then
    ARCHIVE_HOUR=${ADL_ARCHIVE_HOUR_UTC:-10}
    echo "Starting adl_archive_daemon.php (daily at ${ARCHIVE_HOUR}:00 UTC)..."
    nohup php "${WWWROOT}/scripts/adl_archive_daemon.php" >> /home/LogFiles/adl_archive.log 2>&1 &
    ADL_ARCHIVE_PID=$!
    echo "  adl_archive_daemon.php started (PID: $ADL_ARCHIVE_PID)"
else
    echo "ADL Archive daemon SKIPPED (ADL_ARCHIVE_STORAGE_CONN not set)"
    ADL_ARCHIVE_PID="N/A"
fi

# Start the Discord queue processor (async TMI Discord posting)
# Processes pending Discord posts from tmi_discord_posts table
# Rate limited to 10 posts/sec to avoid Discord API limits
# NOTE: Runs even in hibernation — TMI publishing remains active
echo "Starting Discord queue processor (TMI async posting)..."
nohup php "${WWWROOT}/scripts/tmi/process_discord_queue.php" --batch=50 --delay=100 >> /home/LogFiles/discord_queue.log 2>&1 &
DISCORD_Q_PID=$!
echo "  process_discord_queue.php started (PID: $DISCORD_Q_PID)"

# Start the ECFMP flow measure polling daemon (external ATFM data)
# Polls ECFMP API every 5 minutes for flow measures affecting VATSIM airspace
# NOTE: Runs even in hibernation — external flow data should always be captured
echo "Starting ECFMP flow measure polling daemon..."
nohup php "${WWWROOT}/scripts/ecfmp_poll_daemon.php" --loop --interval=300 >> /home/LogFiles/ecfmp_poll.log 2>&1 &
ECFMP_PID=$!
echo "  ecfmp_poll_daemon.php started (PID: $ECFMP_PID)"

# vIFF CDM poll daemon — fetches EU CDM milestone data from vIFF ATFCM system
# Polls /etfms/relevant, /etfms/restricted, /ifps/allStatus every 30s
# Runs even in hibernation (SWIM exempt) — external CDM data always captured
if [ -n "$VIFF_CDM_ENABLED" ] && [ "$VIFF_CDM_ENABLED" = "1" ]; then
    echo "Starting vIFF CDM polling daemon..."
    nohup php "${WWWROOT}/scripts/viff_cdm_poll_daemon.php" --loop --interval=${VIFF_POLL_INTERVAL:-30} >> /home/LogFiles/viff_cdm_poll.log 2>&1 &
    VIFF_PID=$!
    echo "  viff_cdm_poll_daemon.php started (PID: $VIFF_PID)"
else
    echo "Skipping vIFF CDM daemon (VIFF_CDM_ENABLED not set)"
    VIFF_PID="DISABLED"
fi

# Start the playbook export daemon (daily backup of all playbook data)
# MySQL-only — runs even in hibernation. Exports JSON + text to backups/playbook/.
# 5-min initial delay, then every 24h. Change detection skips if no updates.
echo "Starting playbook export (daily backup, every 24h)..."
nohup bash -c "sleep 300; while true; do php '${WWWROOT}/scripts/playbook/export_playbook.php' --cli >> /home/LogFiles/playbook_export.log 2>&1; sleep 86400; done" &
PLAYBOOK_EXPORT_PID=$!
echo "  playbook export started (PID: $PLAYBOOK_EXPORT_PID, first run in 5min)"

# Start the reference data sync daemon (daily CDR + playbook reimport at 06:00Z)
# Reimports cdrs.csv -> VATSIM_REF and playbook_routes.csv -> MySQL daily
# Runs even in hibernation — reference data should stay current
echo "Starting refdata_sync_daemon.php (daily reimport at 06:00Z)..."
nohup php "${WWWROOT}/scripts/refdata_sync_daemon.php" >> /home/LogFiles/refdata_sync.log 2>&1 &
REFDATA_PID=$!
echo "  refdata_sync_daemon.php started (PID: $REFDATA_PID)"

# Start the SWIM WebSocket server (real-time flight events on port 8090)
# NOTE: Runs even in hibernation — VATSWIM remains operational
echo "Starting swim_ws_server.php (WebSocket on port 8090)..."
nohup php "${WWWROOT}/scripts/swim_ws_server.php" --debug >> /home/LogFiles/swim_ws.log 2>&1 &
WS_PID=$!
echo "  swim_ws_server.php started (PID: $WS_PID)"

# Start the SWIM sync daemon (syncs VATSIM_ADL to SWIM_API every 2 min)
# Also handles data retention cleanup every 6 hours
# NOTE: Runs even in hibernation — VATSWIM remains operational
echo "Starting swim_sync_daemon.php (sync every 2min, cleanup every 6h)..."
nohup php "${WWWROOT}/scripts/swim_sync_daemon.php" --loop --sync-interval=120 --cleanup-interval=21600 >> /home/LogFiles/swim_sync.log 2>&1 &
SWIM_SYNC_PID=$!
echo "  swim_sync_daemon.php started (PID: $SWIM_SYNC_PID)"

# Start the SimTraffic -> SWIM polling daemon (reconciliation fallback every 10 min)
# Rate limited to 5 req/sec per SimTraffic API docs
# Demoted from 2min to 10min — webhooks are now the primary ingest path
# NOTE: Runs even in hibernation — VATSWIM remains operational
echo "Starting simtraffic_swim_poll.php (reconciliation every 10min)..."
nohup php "${WWWROOT}/scripts/simtraffic_swim_poll.php" --loop --interval=600 >> /home/LogFiles/simtraffic_poll.log 2>&1 &
ST_POLL_PID=$!
echo "  simtraffic_swim_poll.php started (PID: $ST_POLL_PID)"

# Start the SWIM -> ADL reverse sync daemon (propagates ST times to ADL every 2 min)
# Syncs SimTraffic data from swim_flights back to ADL normalized tables
# NOTE: Runs even in hibernation — VATSWIM remains operational
echo "Starting swim_adl_reverse_sync.php (reverse sync every 2min)..."
nohup php "${WWWROOT}/scripts/swim_adl_reverse_sync_daemon.php" --loop --interval=120 >> /home/LogFiles/swim_reverse_sync.log 2>&1 &
REVERSE_SYNC_PID=$!
echo "  swim_adl_reverse_sync_daemon.php started (PID: $REVERSE_SYNC_PID)"

# Start the SWIM TMI sync daemon (syncs TMI/CDM/reference data to SWIM mirrors every 5 min)
# Offset by 60s from flight sync to avoid DTU contention on Azure SQL Basic
# Daily reference sync (airports, taxi times) runs 0601-0801Z
# NOTE: Runs even in hibernation — VATSWIM remains operational
echo "Starting swim_tmi_sync_daemon.php (sync every 5min, offset 60s)..."
nohup php "${WWWROOT}/scripts/swim_tmi_sync_daemon.php" --loop --interval=300 >> /home/LogFiles/swim_tmi_sync.log 2>&1 &
TMI_SYNC_PID=$!
echo "  swim_tmi_sync_daemon.php started (PID: $TMI_SYNC_PID)"

# =============================================================================
# DOWNSTREAM DAEMONS (skipped in hibernation mode)
# =============================================================================

# Initialize PID variables for skipped daemons
PARSE_PID="HIBERNATED"
BOUNDARY_PID="HIBERNATED"
CROSSING_PID="HIBERNATED"
WAYPOINT_PID="HIBERNATED"
SCHED_PID="HIBERNATED"
EVENT_SYNC_PID="HIBERNATED"
CDM_PID="HIBERNATED"
VACDM_PID="HIBERNATED"
DELAY_ATTR_PID="HIBERNATED"
FACILITY_STATS_PID="HIBERNATED"
WEBHOOK_DELIVERY_PID="HIBERNATED"

if [ "$HIBERNATION" != "1" ]; then

    # =============================================================================
    # GIS Mode Switch
    # Set USE_GIS_DAEMONS=1 to use PostGIS for spatial operations (cost savings)
    # Set USE_GIS_DAEMONS=0 to use ADL-only mode (original behavior)
    #
    # REVIEW DATE: 2026-03-01
    # After 30 days of GIS mode, evaluate whether to:
    #   - Delete old ADL daemons (boundary_daemon.php, parse_queue_daemon.php)
    #   - Remove this else branch and USE_GIS_DAEMONS switch entirely
    #   - Remove ADL fallback code from GIS daemons
    # Check logs for GIS success rate: grep "GIS rate" /home/LogFiles/*.log
    # =============================================================================
    USE_GIS_DAEMONS=${USE_GIS_DAEMONS:-1}  # Default: use GIS daemons

    if [ "$USE_GIS_DAEMONS" = "1" ]; then
        echo "GIS Mode: ENABLED (using PostGIS for spatial operations)"

        # Start the GIS-based parse queue daemon (2-3x faster than ADL)
        echo "Starting parse_queue_gis_daemon.php..."
        nohup php "${WWWROOT}/adl/php/parse_queue_gis_daemon.php" --loop --batch=50 --interval=10 >> /home/LogFiles/parse_queue_gis.log 2>&1 &
        PARSE_PID=$!
        echo "  parse_queue_gis_daemon.php started (PID: $PARSE_PID)"

        # Start the GIS-based boundary detection daemon (offloads spatial from ADL)
        echo "Starting boundary_gis_daemon.php..."
        nohup php "${WWWROOT}/adl/php/boundary_gis_daemon.php" --loop --interval=15 >> /home/LogFiles/boundary_gis.log 2>&1 &
        BOUNDARY_PID=$!
        echo "  boundary_gis_daemon.php started (PID: $BOUNDARY_PID)"

        # Start the GIS-based crossing calculation daemon (trajectory-based crossing ETAs)
        # Uses PostGIS line-polygon intersection for precise boundary crossing points/times
        # Tier 0 every 15s, Tier 1 every 30s, Tier 2 every 60s, Tier 3 every 2min, Tier 4 every 5min
        echo "Starting crossing_gis_daemon.php..."
        nohup php "${WWWROOT}/adl/php/crossing_gis_daemon.php" --loop >> /home/LogFiles/crossing_gis.log 2>&1 &
        CROSSING_PID=$!
        echo "  crossing_gis_daemon.php started (PID: $CROSSING_PID)"
    else
        echo "GIS Mode: DISABLED (using ADL-only for spatial operations)"

        # Start the original ADL parse queue daemon
        echo "Starting parse_queue_daemon.php..."
        nohup php "${WWWROOT}/adl/php/parse_queue_daemon.php" --loop --batch=50 --interval=5 >> /home/LogFiles/parse_queue.log 2>&1 &
        PARSE_PID=$!
        echo "  parse_queue_daemon.php started (PID: $PARSE_PID)"

        # Start the original ADL boundary detection daemon
        echo "Starting boundary_daemon.php..."
        nohup php "${WWWROOT}/adl/php/boundary_daemon.php" --loop --interval=30 >> /home/LogFiles/boundary.log 2>&1 &
        BOUNDARY_PID=$!
        echo "  boundary_daemon.php started (PID: $BOUNDARY_PID)"
    fi

    # Start the waypoint ETA daemon (tiered waypoint ETA calculation)
    # Tier 0 every 15s, Tier 1 every 30s, Tier 2 every 60s, etc.
    echo "Starting waypoint_eta_daemon.php..."
    nohup php "${WWWROOT}/adl/php/waypoint_eta_daemon.php" --loop >> /home/LogFiles/waypoint_eta.log 2>&1 &
    WAYPOINT_PID=$!
    echo "  waypoint_eta_daemon.php started (PID: $WAYPOINT_PID)"

    # Start the unified scheduler daemon (splits, routes auto-activation)
    echo "Starting scheduler_daemon.php (checks every 60s)..."
    nohup php "${WWWROOT}/scripts/scheduler_daemon.php" --interval=60 >> /home/LogFiles/scheduler.log 2>&1 &
    SCHED_PID=$!
    echo "  scheduler_daemon.php started (PID: $SCHED_PID)"

    # Start the PERTI events sync daemon (VATUSA, VATCAN, VATSIM events)
    # Syncs every 6 hours to populate perti_events table for TMI compliance & position logging
    echo "Starting event_sync_daemon.php (sync every 6h)..."
    nohup php "${WWWROOT}/scripts/event_sync_daemon.php" --loop --interval=21600 >> /home/LogFiles/event_sync.log 2>&1 &
    EVENT_SYNC_PID=$!
    echo "  event_sync_daemon.php started (PID: $EVENT_SYNC_PID)"

    # Start the CDM daemon (A-CDM milestone computation + compliance + delivery)
    # 60-second cycle: readiness detection, TSAT/TTOT computation, EDCT compliance,
    # airport status snapshots, message delivery, trigger evaluation, data purge
    echo "Starting cdm_daemon.php (CDM cycle every 60s)..."
    nohup php "${WWWROOT}/scripts/cdm_daemon.php" --loop >> /home/LogFiles/cdm_daemon.log 2>&1 &
    CDM_PID=$!
    echo "  cdm_daemon.php started (PID: $CDM_PID)"

    # Start the vACDM polling daemon (polls vACDM instances for CDM milestones)
    # Discovers providers from tmi_flow_providers where provider_code='VACDM'
    # Per-provider circuit breaker, 2-min poll interval
    echo "Starting vacdm_poll_daemon.php (polling every 2min)..."
    nohup php "${WWWROOT}/scripts/vacdm_poll_daemon.php" --loop --interval=120 >> /home/LogFiles/vacdm_poll.log 2>&1 &
    VACDM_PID=$!
    echo "  vacdm_poll_daemon.php started (PID: $VACDM_PID)"

    # Start the vNAS controller feed polling daemon
    # Polls https://live.env.vnas.vatsim.net/data-feed/controllers.json every 60s
    # Upserts into swim_controllers + enriches with ERAM/STARS sector data
    # TODO: Uncomment when migration 024 is deployed and controller data is ready
    # echo "Starting vnas_controller_poll.php (polling every 60s)..."
    # nohup php "${WWWROOT}/scripts/vnas_controller_poll.php" --loop --interval=60 >> /home/LogFiles/vnas_ctrl_poll.log 2>&1 &
    # VNAS_CTRL_PID=$!
    # echo "  vnas_controller_poll.php started (PID: $VNAS_CTRL_PID)"

    # Start the TMI delay attribution daemon
    # Computes per-flight delay from EDCT/OOOI baselines, writes to VATSIM_TMI
    # 60-second cycle for active controlled flights
    echo "Starting delay_attribution_daemon.php (cycle every 60s)..."
    nohup php "${WWWROOT}/scripts/tmi/delay_attribution_daemon.php" --loop --interval=60 >> /home/LogFiles/delay_attribution.log 2>&1 &
    DELAY_ATTR_PID=$!
    echo "  delay_attribution_daemon.php started (PID: $DELAY_ATTR_PID)"

    # Start the TMI facility statistics daemon
    # Computes hourly/daily facility stats from flight data + delay attributions
    # Hourly cycle with 2-hour lookback
    echo "Starting facility_stats_daemon.php (cycle every 3600s)..."
    nohup php "${WWWROOT}/scripts/tmi/facility_stats_daemon.php" --loop --interval=3600 --hours=2 >> /home/LogFiles/facility_stats.log 2>&1 &
    FACILITY_STATS_PID=$!
    echo "  facility_stats_daemon.php started (PID: $FACILITY_STATS_PID)"

    # Webhook delivery daemon (outbound event queue)
    echo "Starting webhook_delivery_daemon.php (outbound webhook delivery)..."
    nohup php "${WWWROOT}/scripts/webhook_delivery_daemon.php" --loop >> /home/LogFiles/webhook_delivery.log 2>&1 &
    WEBHOOK_DELIVERY_PID=$!
    echo "  webhook_delivery_daemon.php started (PID: $WEBHOOK_DELIVERY_PID)"

else
    echo ""
    echo "  Downstream daemons SKIPPED (hibernation mode)"
    echo "  Skipped: GIS parse/boundary/crossing, waypoint ETA, scheduler, event sync, CDM, vACDM, delay attribution, facility stats, webhook delivery"
    echo "  Running: SWIM (ws/sync/SimTraffic/reverse sync) — VATSWIM exempt from hibernation"
    echo ""
fi

# Run codebase/database indexer once at startup (generates agent_context.md for AI tools)
# Uses lock file to prevent concurrent runs during rapid deployments
# Runs in background with 30s delay to let daemons stabilize first
echo "Scheduling indexer run (30s delay, background)..."
(
    sleep 30
    LOCK_FILE="/tmp/perti_indexer.lock"
    if [ -f "$LOCK_FILE" ]; then
        # Check if lock is stale (older than 15 minutes)
        LOCK_AGE=$(($(date +%s) - $(stat -c %Y "$LOCK_FILE" 2>/dev/null || echo 0)))
        if [ "$LOCK_AGE" -lt 900 ]; then
            echo "[$(date -u '+%Y-%m-%d %H:%M:%S UTC')] Indexer skipped - already running" >> /home/LogFiles/indexer.log
            exit 0
        fi
        rm -f "$LOCK_FILE"
    fi
    touch "$LOCK_FILE"
    echo "[$(date -u '+%Y-%m-%d %H:%M:%S UTC')] Starting indexer (startup trigger)" >> /home/LogFiles/indexer.log
    php "${WWWROOT}/scripts/indexer/run_indexer.php" >> /home/LogFiles/indexer.log 2>&1
    rm -f "$LOCK_FILE"
    echo "[$(date -u '+%Y-%m-%d %H:%M:%S UTC')] Indexer complete" >> /home/LogFiles/indexer.log
) &
INDEXER_PID=$!
echo "  Indexer scheduled (PID: $INDEXER_PID, will run after 30s)"

echo "========================================"
if [ "$HIBERNATION" = "1" ]; then
    echo "HIBERNATION MODE - Core + SWIM daemons:"
    echo "  adl=$ADL_PID, arch=$ARCH_PID, mon=$MON_PID"
    echo "  ws=$WS_PID, swim_sync=$SWIM_SYNC_PID"
    echo "  st_poll=$ST_POLL_PID, reverse_sync=$REVERSE_SYNC_PID"
    echo "  tmi_sync=$TMI_SYNC_PID"
    echo "  discord_q=$DISCORD_Q_PID, adl_archive=$ADL_ARCHIVE_PID"
    echo "  ecfmp=$ECFMP_PID, viff=$VIFF_PID"
    echo "  refdata=$REFDATA_PID (daily reimport at 06:00Z)"
    echo "  playbook_export=$PLAYBOOK_EXPORT_PID (daily, first in 5min)"
    echo "  indexer=$INDEXER_PID (scheduled, 30s delay)"
    echo "  Hibernated: GIS, waypoint ETA, scheduler, event sync, CDM, vACDM, webhook delivery"
else
    echo "All daemons started:"
    echo "  adl=$ADL_PID, parse=$PARSE_PID, boundary=$BOUNDARY_PID"
    echo "  waypoint=$WAYPOINT_PID, crossing=${CROSSING_PID:-N/A}"
    echo "  ws=$WS_PID, swim_sync=$SWIM_SYNC_PID"
    echo "  st_poll=$ST_POLL_PID, reverse_sync=$REVERSE_SYNC_PID"
    echo "  tmi_sync=$TMI_SYNC_PID"
    echo "  sched=$SCHED_PID, arch=$ARCH_PID, mon=$MON_PID"
    echo "  discord_q=$DISCORD_Q_PID, event_sync=$EVENT_SYNC_PID"
    echo "  ecfmp=$ECFMP_PID, viff=$VIFF_PID, cdm=$CDM_PID, vacdm=$VACDM_PID"
    echo "  delay_attr=$DELAY_ATTR_PID, facility_stats=$FACILITY_STATS_PID"
    echo "  webhook_delivery=$WEBHOOK_DELIVERY_PID"
    echo "  adl_archive=$ADL_ARCHIVE_PID (daily ${ARCHIVE_HOUR:-10}:00 UTC)"
    echo "  refdata=$REFDATA_PID (daily reimport at 06:00Z)"
    echo "  playbook_export=$PLAYBOOK_EXPORT_PID (daily, first in 5min)"
    echo "  indexer=$INDEXER_PID (scheduled, 30s delay)"
fi
echo "========================================"

# Configure OPcache for production performance
# Eliminates PHP script re-parsing (~10-50ms saved per request)
# validate_timestamps=1 with revalidate_freq=60 means changes are
# picked up within 60s of deployment — safe for CI/CD
echo "Configuring PHP OPcache..."
PHP_INI="/usr/local/etc/php/conf.d/opcache-perti.ini"
cat > "$PHP_INI" << 'OPCACHE_EOF'
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=60
opcache.interned_strings_buffer=16
opcache.fast_shutdown=1
OPCACHE_EOF
echo "  OPcache configured (128MB, revalidate every 60s)"

# Install APCu for server-side response caching
# Used by demand endpoints, ADL current flights, and SWIM API
if php -m 2>/dev/null | grep -q apcu; then
    echo "  APCu already installed"
else
    echo "Installing APCu..."
    pecl install apcu >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        echo "  APCu installed successfully"
    else
        echo "  WARNING: APCu installation failed — caching will be disabled"
    fi
fi
# Configure APCu (idempotent — writes config even if install was from a previous boot)
APCU_INI="/usr/local/etc/php/conf.d/apcu.ini"
cat > "$APCU_INI" << 'APCU_EOF'
extension=apcu
apc.enabled=1
apc.shm_size=64M
apc.enable_cli=0
APCU_EOF
echo "  APCu configured (64MB SHM)"

# Configure PHP-FPM for higher concurrency
# Default is only 5 workers which causes request queueing under load
#
# Memory calculation (for choosing max_children):
#   - Background daemons: ~100MB (hibernation) to ~225MB (full)
#   - nginx + OS overhead: ~250MB
#   - PHP-FPM workers: ~50MB each
#   - Safe formula: (TOTAL_RAM - 500MB) / 50MB = max_children
#
# Tier recommendations:
#   B1/S1 (1.75GB): 20 workers (hibernation) / 25 workers (full)
#   B2/S2/P1v2 (3.5GB): 40-60 workers
#   B3/S3/P2v2+ (7GB+): 100+ workers
if [ "$HIBERNATION" = "1" ]; then
    FPM_MAX_CHILDREN=20
    FPM_START=5
    FPM_MIN_SPARE=3
    FPM_MAX_SPARE=10
else
    FPM_MAX_CHILDREN=40
    FPM_START=10
    FPM_MIN_SPARE=5
    FPM_MAX_SPARE=20
fi

echo "Configuring PHP-FPM workers..."
FPM_CONF="/usr/local/etc/php-fpm.d/www.conf"
if [ -f "$FPM_CONF" ]; then
    sed -i "s/^pm.max_children = .*/pm.max_children = $FPM_MAX_CHILDREN/" "$FPM_CONF"
    sed -i "s/^pm.start_servers = .*/pm.start_servers = $FPM_START/" "$FPM_CONF"
    sed -i "s/^pm.min_spare_servers = .*/pm.min_spare_servers = $FPM_MIN_SPARE/" "$FPM_CONF"
    sed -i "s/^pm.max_spare_servers = .*/pm.max_spare_servers = $FPM_MAX_SPARE/" "$FPM_CONF"
    # Enable status page for monitoring (access via /fpm-status)
    sed -i 's/^;pm.status_path = .*/pm.status_path = \/fpm-status/' "$FPM_CONF"
    grep -q '^pm.status_path' "$FPM_CONF" || echo 'pm.status_path = /fpm-status' >> "$FPM_CONF"
    # Kill workers that run longer than 90 seconds — prevents runaway DB queries from
    # consuming all FPM workers and causing site-wide 504s (incident 2026-04-25)
    sed -i 's/^;*request_terminate_timeout = .*/request_terminate_timeout = 90/' "$FPM_CONF"
    grep -q '^request_terminate_timeout' "$FPM_CONF" || echo 'request_terminate_timeout = 90' >> "$FPM_CONF"
    echo "  PHP-FPM configured: max_children=$FPM_MAX_CHILDREN, start=$FPM_START, min_spare=$FPM_MIN_SPARE, max_spare=$FPM_MAX_SPARE"
    echo "  request_terminate_timeout=90s, status page enabled at /fpm-status"
else
    echo "  WARNING: FPM config not found at $FPM_CONF"
fi

# Start PHP-FPM in foreground (nginx handles HTTP, PHP-FPM handles PHP)
# Azure PHP container already has nginx running, we just need PHP-FPM
echo "Starting PHP-FPM..."
php-fpm -F
