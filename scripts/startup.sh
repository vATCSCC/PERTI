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

# Start the SWIM WebSocket server (real-time flight events on port 8090)
echo "Starting swim_ws_server.php (WebSocket on port 8090)..."
nohup php "${WWWROOT}/scripts/swim_ws_server.php" --debug >> /home/LogFiles/swim_ws.log 2>&1 &
WS_PID=$!
echo "  swim_ws_server.php started (PID: $WS_PID)"

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

echo "========================================"
echo "All daemons started. PIDs: adl=$ADL_PID, parse=$PARSE_PID, boundary=$BOUNDARY_PID, ws=$WS_PID, sched=$SCHED_PID, arch=$ARCH_PID"
echo "========================================"

# Start PHP-FPM in foreground (nginx handles HTTP, PHP-FPM handles PHP)
# Azure PHP container already has nginx running, we just need PHP-FPM
echo "Starting PHP-FPM..."
php-fpm -F
