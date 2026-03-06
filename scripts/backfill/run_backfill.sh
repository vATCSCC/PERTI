#!/bin/bash
#
# Hibernation Recovery Orchestrator
#
# Run on Azure App Service via Kudu SSH to execute the full backfill pipeline.
# Ensures the parse daemon is running, then calls hibernation_recovery.php
# in auto mode which handles the Phase 1→parse queue→Phase 3 dependency.
#
# Usage:
#   bash scripts/backfill/run_backfill.sh                    # Full backfill
#   bash scripts/backfill/run_backfill.sh --dry-run           # Preview only
#   bash scripts/backfill/run_backfill.sh --batch=200         # Custom batch size
#   bash scripts/backfill/run_backfill.sh --no-parse-daemon   # Skip parse daemon check
#
# The script will:
#   1. Check/start the parse_queue_gis_daemon (needed for route parsing)
#   2. Extend archive delay to 48h (prevents archival during backfill)
#   3. Run hibernation_recovery.php --phase=auto --include-inactive
#   4. Log everything to /home/LogFiles/backfill_<timestamp>.log
#
# Prerequisites:
#   - HIBERNATION_MODE should be disabled (or about to be) in config
#   - PostGIS database (VATSIM_GIS) must be accessible and un-paused
#   - Azure SQL (VATSIM_ADL) must be accessible
#

set -euo pipefail

# ============================================================
# CONFIGURATION
# ============================================================

WWWROOT="${WWWROOT:-/home/site/wwwroot}"
LOGDIR="${LOGDIR:-/home/LogFiles}"
PARSE_PID_FILE="/tmp/adl_parse_queue_gis_daemon.pid"
BACKFILL_SCRIPT="${WWWROOT}/scripts/backfill/hibernation_recovery.php"
PARSE_DAEMON="${WWWROOT}/adl/php/parse_queue_gis_daemon.php"

TIMESTAMP=$(date -u +%Y%m%d_%H%M%S)
LOGFILE="${LOGDIR}/backfill_${TIMESTAMP}.log"

# Parse arguments
DRY_RUN=""
NO_PARSE_DAEMON=0
EXTRA_ARGS=""

for arg in "$@"; do
    case "$arg" in
        --dry-run)
            DRY_RUN="--dry-run"
            ;;
        --no-parse-daemon)
            NO_PARSE_DAEMON=1
            ;;
        *)
            EXTRA_ARGS="${EXTRA_ARGS} ${arg}"
            ;;
    esac
done

# ============================================================
# LOGGING
# ============================================================

log() {
    local msg="[$(date -u '+%Y-%m-%d %H:%M:%S')Z] $1"
    echo "$msg"
    echo "$msg" >> "$LOGFILE"
}

# ============================================================
# PREFLIGHT CHECKS
# ============================================================

log "=========================================="
log "HIBERNATION BACKFILL ORCHESTRATOR"
log "=========================================="
log "Timestamp: ${TIMESTAMP}"
log "Log file:  ${LOGFILE}"
log "Dry run:   ${DRY_RUN:-no}"
log ""

# Check backfill script exists
if [ ! -f "$BACKFILL_SCRIPT" ]; then
    log "ERROR: Backfill script not found at ${BACKFILL_SCRIPT}"
    exit 1
fi

# Check PHP is available
if ! command -v php &> /dev/null; then
    log "ERROR: PHP not found in PATH"
    exit 1
fi

# Check ADL database connectivity (quick test)
log "Testing database connectivity..."
php -r "
    require_once '${WWWROOT}/load/config.php';
    require_once '${WWWROOT}/load/connect.php';
    \$c = get_conn_adl();
    if (!\$c) { echo 'FAIL'; exit(1); }
    echo 'OK';
" 2>/dev/null
if [ $? -ne 0 ]; then
    log "ERROR: Cannot connect to VATSIM_ADL database"
    exit 1
fi
log "  ADL database: OK"

# Check GIS connectivity
php -r "
    require_once '${WWWROOT}/load/config.php';
    require_once '${WWWROOT}/load/connect.php';
    require_once '${WWWROOT}/load/services/GISService.php';
    \$g = GISService::getInstance();
    echo \$g && \$g->isConnected() ? 'OK' : 'FAIL';
" 2>/dev/null
GIS_STATUS=$?
if [ $GIS_STATUS -ne 0 ]; then
    log "WARNING: PostGIS not available — Phases 2-3 will be skipped"
else
    log "  PostGIS: OK"
fi

# ============================================================
# PARSE DAEMON MANAGEMENT
# ============================================================

parse_daemon_running() {
    if [ -f "$PARSE_PID_FILE" ]; then
        local pid
        pid=$(cat "$PARSE_PID_FILE" 2>/dev/null)
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            return 0
        fi
    fi
    return 1
}

if [ "$NO_PARSE_DAEMON" -eq 0 ]; then
    log ""
    log "--- Parse Daemon Check ---"

    if parse_daemon_running; then
        log "  Parse daemon already running (PID: $(cat "$PARSE_PID_FILE"))"
    else
        if [ -f "$PARSE_DAEMON" ]; then
            log "  Starting parse_queue_gis_daemon (batch=200, interval=5s)..."

            if [ -z "$DRY_RUN" ]; then
                nohup php "$PARSE_DAEMON" \
                    --loop --batch=200 --interval=5 \
                    >> "${LOGDIR}/parse_queue_gis_backfill.log" 2>&1 &
                PARSE_PID=$!
                log "  Started with PID ${PARSE_PID}"

                # Wait for daemon to initialize
                sleep 3

                if ! kill -0 "$PARSE_PID" 2>/dev/null; then
                    log "ERROR: Parse daemon failed to start. Check ${LOGDIR}/parse_queue_gis_backfill.log"
                    exit 1
                fi
            else
                log "  [DRY RUN] Would start parse daemon"
            fi
        else
            log "WARNING: Parse daemon script not found at ${PARSE_DAEMON}"
            log "Phase 1 will queue routes but they won't be processed until the daemon starts."
        fi
    fi
fi

# ============================================================
# RUN BACKFILL
# ============================================================

log ""
log "=========================================="
log "STARTING BACKFILL"
log "=========================================="
log ""

php "$BACKFILL_SCRIPT" \
    --phase=auto \
    --include-inactive \
    --delay-hours=48 \
    ${DRY_RUN} \
    ${EXTRA_ARGS} \
    2>&1 | tee -a "$LOGFILE"

EXIT_CODE=${PIPESTATUS[0]}

# ============================================================
# POST-BACKFILL
# ============================================================

log ""
log "=========================================="
log "BACKFILL COMPLETE"
log "=========================================="
log "Exit code: ${EXIT_CODE}"
log "Log file:  ${LOGFILE}"

if [ $EXIT_CODE -eq 0 ]; then
    log ""
    log "Next steps:"
    log "  1. Verify enrichment via: curl -s https://perti.vatcscc.org/api/adl/diagnostic.php | python3 -m json.tool"
    log "  2. If COMPLETED_FLIGHT_DELAY_HOURS was extended, reset it:"
    log "     php ${BACKFILL_SCRIPT} --delay-hours=2 --phase=0"
    log "  3. If parse queue still has items, re-run Phase 3:"
    log "     php ${BACKFILL_SCRIPT} --phase=3 --include-inactive --batch=100"
else
    log ""
    log "Backfill failed. Check ${LOGFILE} for details."
fi

exit $EXIT_CODE
