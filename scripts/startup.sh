#!/bin/bash

# Start the VATSIM ADL daemon in background (ingests flight data)
nohup php /scripts/vatsim_adl_daemon.php >> /scripts/vatsim_adl.log 2>&1 &

# Start the parse queue daemon in background (expands routes to waypoints)
nohup php /adl/php/parse_queue_daemon.php --loop >> /scripts/parse_queue.log 2>&1 &

# Start the default Apache server (required for App Service)
apache2-foreground
