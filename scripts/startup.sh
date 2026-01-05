#!/bin/bash

# Start the VATSIM ADL daemon in background
nohup php /home/site/wwwroot/scripts/vatsim_adl_daemon.php >> /home/site/wwwroot/scripts/vatsim_adl.log 2>&1 &

# Start the default Apache server (required for App Service)
apache2-foreground
