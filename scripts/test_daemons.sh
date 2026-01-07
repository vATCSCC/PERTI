#!/bin/bash
# test_daemons.sh
# Tests daemon connectivity and functionality on Linux/Azure

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WWWROOT="$(dirname "$SCRIPT_DIR")"

echo "======================================"
echo " PERTI Daemon Test Suite"
echo "======================================"
echo ""
echo "Script Dir: $SCRIPT_DIR"
echo "WWW Root:   $WWWROOT"
echo ""

# Test 1: Check PHP is available
echo "[1] Checking PHP availability..."
if command -v php &> /dev/null; then
    php_version=$(php -v | head -n 1)
    echo "    PHP: $php_version"
else
    echo "    ERROR: PHP not found in PATH"
    exit 1
fi

# Test 2: Check sqlsrv extension
echo "[2] Checking PHP sqlsrv extension..."
sqlsrv_check=$(php -r "echo extension_loaded('sqlsrv') ? 'loaded' : 'not loaded';")
if [ "$sqlsrv_check" = "loaded" ]; then
    echo "    sqlsrv extension: LOADED"
else
    echo "    sqlsrv extension: NOT LOADED"
    echo "    Install with: sudo pecl install sqlsrv"
fi

# Test 3: Check Python is available
echo "[3] Checking Python availability..."
if command -v python3 &> /dev/null; then
    python_version=$(python3 --version)
    echo "    Python: $python_version"
else
    echo "    ERROR: Python3 not found in PATH"
fi

# Test 4: Check Python dependencies
echo "[4] Checking Python dependencies..."
req_file="$SCRIPT_DIR/vatsim_atis/requirements.txt"
if [ -f "$req_file" ]; then
    missing=""
    while IFS= read -r line; do
        # Skip comments and empty lines
        [[ "$line" =~ ^#.*$ ]] && continue
        [[ -z "$line" ]] && continue
        # Extract package name
        pkg=$(echo "$line" | sed 's/[>=<].*//' | tr -d ' ')
        if ! python3 -c "import $pkg" 2>/dev/null; then
            missing="$missing $pkg"
        fi
    done < "$req_file"

    if [ -z "$missing" ]; then
        echo "    All Python dependencies installed"
    else
        echo "    Missing packages:$missing"
        echo "    Run: pip install -r $req_file"
    fi
fi

# Test 5: Check daemon files exist
echo "[5] Checking daemon files..."
files=(
    "adl/php/vatsim_ingest_daemon.php"
    "adl/php/parse_queue_daemon.php"
    "scripts/vatsim_atis/atis_daemon.py"
)
for file in "${files[@]}"; do
    full_path="$WWWROOT/$file"
    if [ -f "$full_path" ]; then
        echo "    OK: $file"
    else
        echo "    MISSING: $file"
    fi
done

# Test 6: Test config loading
echo "[6] Testing config loading..."
config_check=$(php -r "
require_once '$WWWROOT/load/config.php';
echo defined('ADL_SQL_HOST') ? 'ADL config: OK' : 'ADL config: MISSING';
" 2>&1)
echo "    $config_check"

# Test 7: Check for running daemon processes
echo "[7] Checking for running daemon processes..."
ingest_pid=$(pgrep -f "vatsim_ingest_daemon.php" || echo "")
parse_pid=$(pgrep -f "parse_queue_daemon.php" || echo "")
atis_pid=$(pgrep -f "atis_daemon" || echo "")

if [ -n "$ingest_pid" ]; then
    echo "    vatsim_ingest_daemon: RUNNING (PID: $ingest_pid)"
else
    echo "    vatsim_ingest_daemon: NOT RUNNING"
fi

if [ -n "$parse_pid" ]; then
    echo "    parse_queue_daemon: RUNNING (PID: $parse_pid)"
else
    echo "    parse_queue_daemon: NOT RUNNING"
fi

if [ -n "$atis_pid" ]; then
    echo "    atis_daemon: RUNNING (PID: $atis_pid)"
else
    echo "    atis_daemon: NOT RUNNING"
fi

echo ""
echo "======================================"

# Parse command line args for individual tests
run_ingest=false
run_parse=false
run_atis=false
run_all=false

for arg in "$@"; do
    case $arg in
        --ingest) run_ingest=true ;;
        --parse) run_parse=true ;;
        --atis) run_atis=true ;;
        --all) run_all=true ;;
    esac
done

if [ "$run_ingest" = true ] || [ "$run_all" = true ]; then
    echo ""
    echo "[TEST] Running vatsim_ingest_daemon.php (single cycle)..."
    php "$WWWROOT/adl/php/vatsim_ingest_daemon.php"
fi

if [ "$run_parse" = true ] || [ "$run_all" = true ]; then
    echo ""
    echo "[TEST] Running parse_queue_daemon.php (single cycle)..."
    php "$WWWROOT/adl/php/parse_queue_daemon.php"
fi

if [ "$run_atis" = true ] || [ "$run_all" = true ]; then
    echo ""
    echo "[TEST] Running atis_daemon.py (single cycle)..."
    cd "$SCRIPT_DIR"
    python3 -m vatsim_atis.atis_daemon --once
fi

echo ""
echo "Done!"
