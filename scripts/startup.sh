#!/bin/bash

# Start the VATSIM ADL daemon in background (ingests flight data every 15s)
nohup php /home/site/wwwroot/adl/php/vatsim_ingest_daemon.php --loop --interval=15 >> /home/LogFiles/vatsim_ingest.log 2>&1 &

# Start the parse queue daemon in background (processes routes every 5s)
nohup php /home/site/wwwroot/adl/php/parse_queue_daemon.php --loop --batch=50 --interval=5 >> /home/LogFiles/parse_queue.log 2>&1 &

# Start the default Apache server (required for App Service)
apache2-foreground
