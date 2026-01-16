#!/bin/bash

# Azure App Service web root
WWWROOT="/home/site/wwwroot"

# Ensure log directory exists
mkdir -p /home/LogFiles

echo "========================================"
echo "PERTI Daemon Startup - $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "WWWROOT: $WWWROOT"
echo "========================================"

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

echo "========================================"
echo "All daemons started. PIDs: adl=$ADL_PID, parse=$PARSE_PID, boundary=$BOUNDARY_PID, ws=$WS_PID, sched=$SCHED_PID"
echo "========================================"

# Configure Apache WebSocket proxy
echo "Configuring Apache WebSocket proxy..."

# Enable required modules (may already be enabled)
a2enmod proxy proxy_http proxy_wstunnel 2>/dev/null || true

# Create WebSocket proxy config
cat > /etc/apache2/conf-available/swim-websocket.conf << 'WSCONF'
# SWIM WebSocket Proxy - Route /api/swim/v1/ws to internal Ratchet server
<IfModule mod_proxy.c>
    <IfModule mod_proxy_wstunnel.c>
        ProxyRequests Off
        ProxyPreserveHost On
        ProxyPass /api/swim/v1/ws ws://127.0.0.1:8090/
        ProxyPassReverse /api/swim/v1/ws ws://127.0.0.1:8090/
        ProxyTimeout 3600
    </IfModule>
</IfModule>
WSCONF

# Enable the config
a2enconf swim-websocket 2>/dev/null || true

echo "Apache WebSocket proxy configured"
echo "========================================"

# Start the default Apache server (required for App Service)
apache2-foreground
