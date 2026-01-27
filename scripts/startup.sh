#!/bin/bash

# Azure App Service web root
WWWROOT="/home/site/wwwroot"

# Ensure log directory exists
mkdir -p /home/LogFiles

echo "========================================"
echo "PERTI Daemon Startup - $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "WWWROOT: $WWWROOT"
echo "========================================"

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

# Reload nginx to apply config
service nginx reload 2>/dev/null && echo "nginx reloaded via service" || \
nginx -s reload 2>/dev/null && echo "nginx reloaded via signal" || \
echo "nginx reload failed - container may handle this"

echo "nginx configured"

# Start the combined VATSIM ADL daemon (ingestion + ATIS processing every 15s)
# This PHP daemon handles both flight data AND ATIS runway parsing
echo "Starting vatsim_adl_daemon.php (combined ingestion + ATIS)..."
nohup php "${WWWROOT}/scripts/vatsim_adl_daemon.php" >> /home/LogFiles/vatsim_adl.log 2>&1 &
ADL_PID=$!
echo "  vatsim_adl_daemon.php started (PID: $ADL_PID)"

# Start the parse queue daemon (processes routes every 5s)
echo "Starting parse_queue_daemon.php..."
nohup php "${WWWROOT}/adl/php/parse_queue_daemon.php" --loop --batch=50 --interval=5 >> /home/LogFiles/parse_queue.log 2>&1 &
PARSE_PID=$!
echo "  parse_queue_daemon.php started (PID: $PARSE_PID)"

# Start the boundary detection daemon (ARTCC/TRACON detection every 30s)
echo "Starting boundary_daemon.php..."
nohup php "${WWWROOT}/adl/php/boundary_daemon.php" --loop --interval=30 >> /home/LogFiles/boundary.log 2>&1 &
BOUNDARY_PID=$!
echo "  boundary_daemon.php started (PID: $BOUNDARY_PID)"

# Start the waypoint ETA daemon (tiered waypoint ETA calculation)
# Tier 0 every 15s, Tier 1 every 30s, Tier 2 every 60s, etc.
echo "Starting waypoint_eta_daemon.php..."
nohup php "${WWWROOT}/adl/php/waypoint_eta_daemon.php" --loop >> /home/LogFiles/waypoint_eta.log 2>&1 &
WAYPOINT_PID=$!
echo "  waypoint_eta_daemon.php started (PID: $WAYPOINT_PID)"

# Start the SWIM WebSocket server (real-time flight events on port 8090)
echo "Starting swim_ws_server.php (WebSocket on port 8090)..."
nohup php "${WWWROOT}/scripts/swim_ws_server.php" --debug >> /home/LogFiles/swim_ws.log 2>&1 &
WS_PID=$!
echo "  swim_ws_server.php started (PID: $WS_PID)"

# Start the SWIM sync daemon (syncs VATSIM_ADL to SWIM_API every 2 min)
# Also handles data retention cleanup every 6 hours
echo "Starting swim_sync_daemon.php (sync every 2min, cleanup every 6h)..."
nohup php "${WWWROOT}/scripts/swim_sync_daemon.php" --loop --sync-interval=120 --cleanup-interval=21600 >> /home/LogFiles/swim_sync.log 2>&1 &
SWIM_SYNC_PID=$!
echo "  swim_sync_daemon.php started (PID: $SWIM_SYNC_PID)"

# Start the SimTraffic -> SWIM polling daemon (fetches ST times every 2 min)
# Rate limited to 5 req/sec per SimTraffic API docs
echo "Starting simtraffic_swim_poll.php (polling every 2min)..."
nohup php "${WWWROOT}/scripts/simtraffic_swim_poll.php" --loop --interval=120 >> /home/LogFiles/simtraffic_poll.log 2>&1 &
ST_POLL_PID=$!
echo "  simtraffic_swim_poll.php started (PID: $ST_POLL_PID)"

# Start the SWIM -> ADL reverse sync daemon (propagates ST times to ADL every 2 min)
# Syncs SimTraffic data from swim_flights back to ADL normalized tables
echo "Starting swim_adl_reverse_sync.php (reverse sync every 2min)..."
nohup php "${WWWROOT}/scripts/swim_adl_reverse_sync_daemon.php" --loop --interval=120 >> /home/LogFiles/swim_reverse_sync.log 2>&1 &
REVERSE_SYNC_PID=$!
echo "  swim_adl_reverse_sync_daemon.php started (PID: $REVERSE_SYNC_PID)"

# Start the unified scheduler daemon (splits, routes auto-activation)
echo "Starting scheduler_daemon.php (checks every 60s)..."
nohup php "${WWWROOT}/scripts/scheduler_daemon.php" --interval=60 >> /home/LogFiles/scheduler.log 2>&1 &
SCHED_PID=$!
echo "  scheduler_daemon.php started (PID: $SCHED_PID)"

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

echo "========================================"
echo "All daemons started:"
echo "  adl=$ADL_PID, parse=$PARSE_PID, boundary=$BOUNDARY_PID"
echo "  waypoint=$WAYPOINT_PID, ws=$WS_PID, swim_sync=$SWIM_SYNC_PID"
echo "  st_poll=$ST_POLL_PID, reverse_sync=$REVERSE_SYNC_PID"
echo "  sched=$SCHED_PID, arch=$ARCH_PID, mon=$MON_PID"
echo "========================================"

# Configure PHP-FPM for higher concurrency
# Default is only 5 workers which causes request queueing under load
#
# Memory calculation (for choosing max_children):
#   - 9 background daemons: ~225MB
#   - nginx + OS overhead: ~250MB
#   - PHP-FPM workers: ~50MB each
#   - Safe formula: (TOTAL_RAM - 500MB) / 50MB = max_children
#
# Tier recommendations:
#   B1/S1 (1.75GB): 25 workers  → (1750-500)/50 = 25
#   B2/S2/P1v2 (3.5GB): 60 workers → (3500-500)/50 = 60 (use 50 for safety)
#   B3/S3/P2v2+ (7GB+): 100+ workers
#
# Current: 40 workers (optimal for PremiumV2 P1v2 tier with 3.5GB RAM)
# If on P2v2 (7GB) or P3v2 (14GB), can increase to 80-150
echo "Configuring PHP-FPM workers..."
FPM_CONF="/usr/local/etc/php-fpm.d/www.conf"
if [ -f "$FPM_CONF" ]; then
    sed -i 's/^pm.max_children = .*/pm.max_children = 40/' "$FPM_CONF"
    sed -i 's/^pm.start_servers = .*/pm.start_servers = 10/' "$FPM_CONF"
    sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 5/' "$FPM_CONF"
    sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 20/' "$FPM_CONF"
    # Enable status page for monitoring (access via /fpm-status)
    sed -i 's/^;pm.status_path = .*/pm.status_path = \/fpm-status/' "$FPM_CONF"
    grep -q '^pm.status_path' "$FPM_CONF" || echo 'pm.status_path = /fpm-status' >> "$FPM_CONF"
    echo "  PHP-FPM configured: max_children=40, start=10, min_spare=5, max_spare=20"
    echo "  Status page enabled at /fpm-status"
else
    echo "  WARNING: FPM config not found at $FPM_CONF"
fi

# Start PHP-FPM in foreground (nginx handles HTTP, PHP-FPM handles PHP)
# Azure PHP container already has nginx running, we just need PHP-FPM
echo "Starting PHP-FPM..."
php-fpm -F
