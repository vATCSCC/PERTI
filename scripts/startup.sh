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

echo "========================================"
echo "All daemons started. PIDs: adl=$ADL_PID, parse=$PARSE_PID"
echo "========================================"

# Start the default Apache server (required for App Service)
apache2-foreground
