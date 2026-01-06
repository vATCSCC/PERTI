#!/bin/bash

# Start the VATSIM ADL daemon in background (ingests flight data)
nohup php /home/site/wwwroot/scripts/vatsim_adl_daemon.php >> /home/site/wwwroot/scripts/vatsim_adl.log 2>&1 &

# Start the parse queue daemon in background (expands routes to waypoints)
nohup php /home/site/wwwroot/adl/php/parse_queue_daemon.php --loop >> /home/site/wwwroot/scripts/parse_queue.log 2>&1 &

# Start the default Apache server (required for App Service)
apache2-foreground
