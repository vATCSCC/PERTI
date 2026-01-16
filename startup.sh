#!/bin/bash
# ==============================================================================
# PERTI Startup Script for Azure App Service
# Location: /home/site/wwwroot/startup.sh
#
# Configure: Azure Portal > App Service > Configuration > General settings
#            Set "Startup Command" to: /home/site/wwwroot/startup.sh
# ==============================================================================

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] PERTI startup script beginning..."

# ==============================================================================
# APACHE WEBSOCKET PROXY CONFIGURATION
# ==============================================================================

# Enable required Apache modules for WebSocket proxying
echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Enabling Apache WebSocket proxy modules..."

# Create Apache config for WebSocket proxy
APACHE_CONF_DIR="/etc/apache2/sites-available"
WS_CONF="/etc/apache2/conf-available/websocket-proxy.conf"

cat > $WS_CONF << 'WSEOF'
# SWIM WebSocket Proxy Configuration
# Proxies wss://perti.vatcscc.org/api/swim/v1/ws to internal ws://localhost:8090

<IfModule mod_proxy.c>
    <IfModule mod_proxy_wstunnel.c>
        ProxyRequests Off
        ProxyPreserveHost On
        
        # WebSocket proxy for SWIM API real-time events
        ProxyPass /api/swim/v1/ws ws://127.0.0.1:8090/
        ProxyPassReverse /api/swim/v1/ws ws://127.0.0.1:8090/
        
        # Extended timeout for long-lived WebSocket connections
        ProxyTimeout 3600
    </IfModule>
</IfModule>
WSEOF

# Enable the modules and config
a2enmod proxy proxy_http proxy_wstunnel rewrite 2>/dev/null || true
a2enconf websocket-proxy 2>/dev/null || true

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Apache WebSocket proxy configured"

# ==============================================================================
# START WEBSOCKET SERVER
# ==============================================================================

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Starting SWIM WebSocket server on port 8090..."

# Kill any existing WebSocket server
pkill -f swim_ws_server.php 2>/dev/null || true

# Start WebSocket server in background
cd /home/site/wwwroot
nohup php scripts/swim_ws_server.php --debug > /home/LogFiles/swim_ws.log 2>&1 &
WS_PID=$!

# Verify it started
sleep 2
if ps -p $WS_PID > /dev/null 2>&1; then
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] WebSocket server started (PID: $WS_PID)"
else
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] WARNING: WebSocket server failed to start"
fi

# ==============================================================================
# START ADL DAEMON
# ==============================================================================

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Starting VATSIM ADL daemon..."

# Kill any existing ADL daemon
pkill -f vatsim_adl_daemon.php 2>/dev/null || true
rm -f /home/site/wwwroot/scripts/vatsim_adl.lock 2>/dev/null || true

# Start ADL daemon in background
nohup php scripts/vatsim_adl_daemon.php > /home/LogFiles/vatsim_adl.log 2>&1 &
ADL_PID=$!

# Verify it started
sleep 2
if ps -p $ADL_PID > /dev/null 2>&1; then
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] ADL daemon started (PID: $ADL_PID)"
else
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] WARNING: ADL daemon failed to start"
fi

# ==============================================================================
# DONE - Let Apache start
# ==============================================================================

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] PERTI startup complete. Starting Apache..."

# Start Apache (required - this is the main process)
# Azure App Service expects this script to either:
# 1. Start Apache directly, OR
# 2. Exit and let the default behavior start Apache
# We'll use the default behavior by exiting cleanly
