#!/usr/bin/env bash
# =============================================================================
# PERTI Hibernation Toggle Script
#
# Usage:
#   ./scripts/hibernate.sh on       # Enter hibernation
#   ./scripts/hibernate.sh off      # Exit hibernation
#   ./scripts/hibernate.sh status   # Show current state
#
# Performs:
#   1. Updates load/config.php HIBERNATION_MODE default
#   2. Sets/removes Azure App Setting HIBERNATION_MODE
#   3. Scales Azure databases up/down
#   4. Restarts App Service
#
# Prerequisites:
#   - az CLI logged in (az login)
#   - Bash (Git Bash on Windows, or Linux/macOS)
#
# See docs/operations/HIBERNATION_RUNBOOK.md for manual procedure and verification steps.
# =============================================================================

set -euo pipefail

# Azure resource identifiers
RG="VATSIM_RG"
APP="vatcscc"
SQL_SERVER="vatsim"
ADL_DB="VATSIM_ADL"
MYSQL_SERVER="vatcscc-perti"
POSTGRES_SERVER="vatcscc-gis"

# Tiers
ADL_HIBERNATE_MIN=1
ADL_HIBERNATE_MAX=4
ADL_OPERATIONAL_MIN=3
ADL_OPERATIONAL_MAX=16

MYSQL_HIBERNATE_SKU="Standard_B1ms"
MYSQL_HIBERNATE_TIER="Burstable"
MYSQL_OPERATIONAL_SKU="Standard_D2ds_v4"
MYSQL_OPERATIONAL_TIER="GeneralPurpose"

POSTGRES_HIBERNATE_SKU="Standard_B1ms"
POSTGRES_OPERATIONAL_SKU="Standard_B2s"
POSTGRES_TIER="Burstable"

# Find config.php relative to this script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="$(cd "$SCRIPT_DIR/.." && pwd)/load/config.php"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

info()  { echo -e "${CYAN}[INFO]${NC}  $*"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
err()   { echo -e "${RED}[ERROR]${NC} $*"; }
step()  { echo -e "\n${CYAN}==> $*${NC}"; }

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------

check_az_login() {
    if ! az account show &>/dev/null; then
        err "Not logged into Azure CLI. Run: az login"
        exit 1
    fi
    ok "Azure CLI authenticated ($(az account show --query name -o tsv))"
}

get_current_config() {
    if [ ! -f "$CONFIG_FILE" ]; then
        err "Config file not found: $CONFIG_FILE"
        exit 1
    fi
    # Extract the default value from: define("HIBERNATION_MODE", env('HIBERNATION_MODE', true/false));
    grep 'HIBERNATION_MODE' "$CONFIG_FILE" | grep -oP "env\('HIBERNATION_MODE',\s*\K(true|false)" || echo "unknown"
}

get_azure_app_setting() {
    az webapp config appsettings list --name "$APP" --resource-group "$RG" \
        --query "[?name=='HIBERNATION_MODE'].value" -o tsv 2>/dev/null || echo "not set"
}

# -----------------------------------------------------------------------------
# Status
# -----------------------------------------------------------------------------

do_status() {
    echo ""
    echo "  PERTI Hibernation Status"
    echo "  ========================"
    echo ""

    local config_val
    config_val=$(get_current_config)
    if [ "$config_val" = "true" ]; then
        echo -e "  config.php default:    ${RED}HIBERNATING${NC} (true)"
    elif [ "$config_val" = "false" ]; then
        echo -e "  config.php default:    ${GREEN}OPERATIONAL${NC} (false)"
    else
        echo -e "  config.php default:    ${YELLOW}UNKNOWN${NC}"
    fi

    check_az_login 2>/dev/null || true

    local az_val
    az_val=$(get_azure_app_setting)
    if [ "$az_val" = "true" ]; then
        echo -e "  Azure App Setting:     ${RED}HIBERNATING${NC} (true)"
    elif [ "$az_val" = "false" ] || [ "$az_val" = "not set" ] || [ -z "$az_val" ]; then
        echo -e "  Azure App Setting:     ${GREEN}OPERATIONAL${NC} ($az_val)"
    else
        echo -e "  Azure App Setting:     ${YELLOW}$az_val${NC}"
    fi

    # DB tiers
    echo ""
    info "Checking Azure DB tiers..."

    local adl_info
    adl_info=$(az sql db show --name "$ADL_DB" --server "$SQL_SERVER" --resource-group "$RG" \
        --query "{minCapacity:minCapacity,maxCapacity:currentSku.capacity}" -o tsv 2>/dev/null || echo "? ?")
    echo "  VATSIM_ADL vCores:     min=$(echo "$adl_info" | cut -f1) / max=$(echo "$adl_info" | cut -f2)"

    local mysql_sku
    mysql_sku=$(az mysql flexible-server show --name "$MYSQL_SERVER" --resource-group "$RG" \
        --query "sku.name" -o tsv 2>/dev/null || echo "?")
    echo "  MySQL SKU:             $mysql_sku"

    local pg_sku
    pg_sku=$(az postgres flexible-server show --name "$POSTGRES_SERVER" --resource-group "$RG" \
        --query "sku.name" -o tsv 2>/dev/null || echo "?")
    echo "  PostGIS SKU:           $pg_sku"

    echo ""
}

# -----------------------------------------------------------------------------
# Hibernate (ON)
# -----------------------------------------------------------------------------

do_hibernate() {
    echo ""
    echo -e "  ${RED}*** ENTERING HIBERNATION MODE ***${NC}"
    echo ""
    echo "  This will:"
    echo "    - Set HIBERNATION_MODE=true in config.php and Azure"
    echo "    - Downscale VATSIM_ADL to min $ADL_HIBERNATE_MIN / max $ADL_HIBERNATE_MAX vCores"
    echo "    - Downscale MySQL to $MYSQL_HIBERNATE_SKU ($MYSQL_HIBERNATE_TIER)"
    echo "    - Downscale PostGIS to $POSTGRES_HIBERNATE_SKU ($POSTGRES_TIER)"
    echo "    - Restart App Service"
    echo ""
    echo "  Trajectory logging will continue at full resolution (no tiering/purging)."
    echo ""
    read -rp "  Continue? [y/N] " confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        echo "  Aborted."
        exit 0
    fi

    check_az_login

    # Step 1: Update config.php
    step "Updating config.php (HIBERNATION_MODE default -> true)"
    if grep -q "env('HIBERNATION_MODE', false)" "$CONFIG_FILE"; then
        sed -i "s/env('HIBERNATION_MODE', false)/env('HIBERNATION_MODE', true)/" "$CONFIG_FILE"
        ok "config.php updated"
    elif grep -q "env('HIBERNATION_MODE', true)" "$CONFIG_FILE"; then
        warn "config.php already set to true"
    else
        err "Could not find HIBERNATION_MODE line in config.php"
        exit 1
    fi

    # Step 2: Set Azure App Setting
    step "Setting Azure App Setting HIBERNATION_MODE=true"
    az webapp config appsettings set --name "$APP" --resource-group "$RG" \
        --settings HIBERNATION_MODE=true --output none
    ok "Azure App Setting set"

    # Step 3: Downscale databases (parallel)
    step "Downscaling Azure databases..."

    info "VATSIM_ADL: min $ADL_HIBERNATE_MIN / max $ADL_HIBERNATE_MAX vCores"
    az sql db update --name "$ADL_DB" --server "$SQL_SERVER" --resource-group "$RG" \
        --min-capacity "$ADL_HIBERNATE_MIN" --capacity "$ADL_HIBERNATE_MAX" \
        --edition Hyperscale --family Gen5 --compute-model Serverless --output none &
    local adl_pid=$!

    info "MySQL: $MYSQL_HIBERNATE_SKU ($MYSQL_HIBERNATE_TIER)"
    az mysql flexible-server update --name "$MYSQL_SERVER" --resource-group "$RG" \
        --sku-name "$MYSQL_HIBERNATE_SKU" --tier "$MYSQL_HIBERNATE_TIER" --output none &
    local mysql_pid=$!

    info "PostGIS: $POSTGRES_HIBERNATE_SKU ($POSTGRES_TIER)"
    az postgres flexible-server update --name "$POSTGRES_SERVER" --resource-group "$RG" \
        --sku-name "$POSTGRES_HIBERNATE_SKU" --tier "$POSTGRES_TIER" --yes --output none &
    local pg_pid=$!

    # Wait for all
    local failed=0
    wait $adl_pid   && ok "VATSIM_ADL downscaled"   || { err "VATSIM_ADL downscale failed"; failed=1; }
    wait $mysql_pid && ok "MySQL downscaled"         || { err "MySQL downscale failed"; failed=1; }
    wait $pg_pid    && ok "PostGIS downscaled"       || { err "PostGIS downscale failed"; failed=1; }

    if [ $failed -ne 0 ]; then
        warn "Some database operations failed. Check Azure Portal."
    fi

    # Step 4: Restart App Service
    step "Restarting App Service..."
    az webapp restart --name "$APP" --resource-group "$RG" --output none
    ok "App Service restarted"

    echo ""
    echo -e "  ${GREEN}Hibernation mode activated.${NC}"
    echo ""
    echo "  Still running: ADL ingest (15s), archival (flight archive only), monitoring, Discord queue"
    echo "  Trajectory:    Full-resolution logging active, tiering/purging suspended"
    echo "  Paused:        GIS daemons, SWIM, scheduler, event sync, ATIS parsing"
    echo ""
    echo "  Verify: visit https://perti.vatcscc.org/status"
    echo ""
}

# -----------------------------------------------------------------------------
# Un-hibernate (OFF)
# -----------------------------------------------------------------------------

do_unhibernate() {
    echo ""
    echo -e "  ${GREEN}*** EXITING HIBERNATION MODE ***${NC}"
    echo ""
    echo "  This will:"
    echo "    - Set HIBERNATION_MODE=false in config.php and Azure"
    echo "    - Upscale VATSIM_ADL to min $ADL_OPERATIONAL_MIN / max $ADL_OPERATIONAL_MAX vCores"
    echo "    - Upscale MySQL to $MYSQL_OPERATIONAL_SKU ($MYSQL_OPERATIONAL_TIER)"
    echo "    - Upscale PostGIS to $POSTGRES_OPERATIONAL_SKU ($POSTGRES_TIER)"
    echo "    - Restart App Service (starts all daemons)"
    echo ""
    read -rp "  Continue? [y/N] " confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        echo "  Aborted."
        exit 0
    fi

    check_az_login

    # Step 1: Upscale databases first (they take longest)
    step "Upscaling Azure databases..."

    info "VATSIM_ADL: min $ADL_OPERATIONAL_MIN / max $ADL_OPERATIONAL_MAX vCores"
    az sql db update --name "$ADL_DB" --server "$SQL_SERVER" --resource-group "$RG" \
        --min-capacity "$ADL_OPERATIONAL_MIN" --capacity "$ADL_OPERATIONAL_MAX" \
        --edition Hyperscale --family Gen5 --compute-model Serverless --output none &
    local adl_pid=$!

    info "MySQL: $MYSQL_OPERATIONAL_SKU ($MYSQL_OPERATIONAL_TIER)"
    az mysql flexible-server update --name "$MYSQL_SERVER" --resource-group "$RG" \
        --sku-name "$MYSQL_OPERATIONAL_SKU" --tier "$MYSQL_OPERATIONAL_TIER" --output none &
    local mysql_pid=$!

    info "PostGIS: $POSTGRES_OPERATIONAL_SKU ($POSTGRES_TIER)"
    az postgres flexible-server update --name "$POSTGRES_SERVER" --resource-group "$RG" \
        --sku-name "$POSTGRES_OPERATIONAL_SKU" --tier "$POSTGRES_TIER" --yes --output none &
    local pg_pid=$!

    # Wait for all
    local failed=0
    wait $adl_pid   && ok "VATSIM_ADL upscaled"   || { err "VATSIM_ADL upscale failed"; failed=1; }
    wait $mysql_pid && ok "MySQL upscaled"         || { err "MySQL upscale failed"; failed=1; }
    wait $pg_pid    && ok "PostGIS upscaled"       || { err "PostGIS upscale failed"; failed=1; }

    if [ $failed -ne 0 ]; then
        warn "Some database operations failed. Check Azure Portal."
    fi

    # Step 2: Update config.php
    step "Updating config.php (HIBERNATION_MODE default -> false)"
    if grep -q "env('HIBERNATION_MODE', true)" "$CONFIG_FILE"; then
        sed -i "s/env('HIBERNATION_MODE', true)/env('HIBERNATION_MODE', false)/" "$CONFIG_FILE"
        ok "config.php updated"
    elif grep -q "env('HIBERNATION_MODE', false)" "$CONFIG_FILE"; then
        warn "config.php already set to false"
    else
        err "Could not find HIBERNATION_MODE line in config.php"
        exit 1
    fi

    # Step 3: Set Azure App Setting to false
    step "Setting Azure App Setting HIBERNATION_MODE=false"
    az webapp config appsettings set --name "$APP" --resource-group "$RG" \
        --settings HIBERNATION_MODE=false --output none
    ok "Azure App Setting set to false"

    # Step 4: Restart App Service
    step "Restarting App Service (will start all daemons)..."
    az webapp restart --name "$APP" --resource-group "$RG" --output none
    ok "App Service restarted"

    echo ""
    echo -e "  ${GREEN}Hibernation mode deactivated. All systems operational.${NC}"
    echo ""
    echo "  Verification checklist:"
    echo "    - [ ] All daemons running (Kudu SSH: ps aux | grep php)"
    echo "    - [ ] ADL ingest OK:    /home/LogFiles/vatsim_adl.log"
    echo "    - [ ] GIS daemons OK:   /home/LogFiles/parse_queue.log, boundary.log, crossing.log"
    echo "    - [ ] SWIM sync OK:     /home/LogFiles/swim_sync.log"
    echo "    - [ ] SWIM API:         curl https://perti.vatcscc.org/api/swim/v1/health"
    echo "    - [ ] Pages accessible: /demand, /gdt, /nod, /sua"
    echo "    - [ ] ATIS parsing re-enabled in ADL logs"
    echo "    - [ ] Trajectory archival resumes normal tiering schedule"
    echo ""
}

# -----------------------------------------------------------------------------
# Main
# -----------------------------------------------------------------------------

case "${1:-}" in
    on|hibernate|enter)
        do_hibernate
        ;;
    off|unhibernate|exit|wake)
        do_unhibernate
        ;;
    status|state|check)
        do_status
        ;;
    *)
        echo ""
        echo "  PERTI Hibernation Toggle"
        echo ""
        echo "  Usage: $0 <command>"
        echo ""
        echo "  Commands:"
        echo "    on      Enter hibernation (downscale + suspend)"
        echo "    off     Exit hibernation (upscale + restore)"
        echo "    status  Show current hibernation state"
        echo ""
        exit 1
        ;;
esac
