#!/bin/bash

# Azure App Service web root
WWWROOT="/home/site/wwwroot"

# Ensure log directory exists
mkdir -p /home/LogFiles

echo "========================================"
echo "PERTI Daemon Startup - $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "WWWROOT: $WWWROOT"
echo "========================================"

# Start the VATSIM ADL ingestion daemon (fetches flight data every 15s)
echo "Starting vatsim_ingest_daemon.php..."
nohup php "${WWWROOT}/adl/php/vatsim_ingest_daemon.php" --loop --interval=15 >> /home/LogFiles/vatsim_ingest.log 2>&1 &
INGEST_PID=$!
echo "  vatsim_ingest_daemon.php started (PID: $INGEST_PID)"

# Start the parse queue daemon (processes routes every 5s)
echo "Starting parse_queue_daemon.php..."
nohup php "${WWWROOT}/adl/php/parse_queue_daemon.php" --loop --batch=50 --interval=5 >> /home/LogFiles/parse_queue.log 2>&1 &
PARSE_PID=$!
echo "  parse_queue_daemon.php started (PID: $PARSE_PID)"

# Start the ATIS daemon (parses runway assignments every 15s)
echo "Starting atis_daemon.py..."
cd "${WWWROOT}/scripts"
nohup python3 -m vatsim_atis.atis_daemon >> /home/LogFiles/atis_daemon.log 2>&1 &
ATIS_PID=$!
echo "  atis_daemon.py started (PID: $ATIS_PID)"
cd "${WWWROOT}"

echo "========================================"
echo "All daemons started. PIDs: ingest=$INGEST_PID, parse=$PARSE_PID, atis=$ATIS_PID"
echo "========================================"

# Start the default Apache server (required for App Service)
apache2-foreground
