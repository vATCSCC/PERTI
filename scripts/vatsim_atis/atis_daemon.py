#!/usr/bin/env python3
"""
VATSIM ATIS Import Daemon

Fetches ATIS data from VATSIM every 15 seconds, parses runway assignments,
and imports to SQL Server database.

Usage:
    python atis_daemon.py                    # Run in foreground
    python atis_daemon.py --once             # Run once and exit
    python atis_daemon.py --airports KJFK,KLAX  # Filter by airports
    nohup python atis_daemon.py &            # Run detached

Environment variables:
    ADL_SQL_HOST        SQL Server hostname
    ADL_SQL_DATABASE    Database name
    ADL_SQL_USERNAME    Username
    ADL_SQL_PASSWORD    Password

Or create a .env file in the scripts directory.
"""

import argparse
import json
import logging
import os
import signal
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

# Add parent directory for imports
sys.path.insert(0, str(Path(__file__).parent.parent))

from vatsim_atis.vatsim_fetcher import (
    fetch_vatsim_data,
    extract_atis_controllers,
    AtisController,
)
from vatsim_atis.atis_parser import (
    parse_full_runway_info,
    format_runway_summary,
)

# Optional: Load environment from .env file
try:
    from dotenv import load_dotenv
    load_dotenv(Path(__file__).parent.parent / '.env')
except ImportError:
    pass

# Load config from PHP config file
try:
    from vatsim_atis.config_loader import load_php_config
    _php_config = load_php_config()
except ImportError:
    _php_config = {}

# Try to import pyodbc for SQL Server
try:
    import pyodbc
    HAS_PYODBC = True
except ImportError:
    HAS_PYODBC = False
    print("Warning: pyodbc not installed. Database operations will be skipped.")
    print("Install with: pip install pyodbc")


# ============================================================================
# Configuration
# ============================================================================

CONFIG = {
    # Database (from environment, falling back to PHP config)
    'db_host': os.environ.get('ADL_SQL_HOST', _php_config.get('db_host', '')),
    'db_name': os.environ.get('ADL_SQL_DATABASE', _php_config.get('db_name', '')),
    'db_user': os.environ.get('ADL_SQL_USERNAME', _php_config.get('db_user', '')),
    'db_pass': os.environ.get('ADL_SQL_PASSWORD', _php_config.get('db_pass', '')),

    # Timing
    'interval_seconds': 15,
    'sp_timeout': 120,

    # Logging
    'log_file': Path(__file__).parent.parent / 'vatsim_atis.log',
    'log_level': logging.INFO,

    # Performance thresholds (ms)
    'warn_fetch_ms': 5000,
    'warn_parse_ms': 1000,
    'warn_db_ms': 5000,
}


# ============================================================================
# Logging Setup
# ============================================================================

def setup_logging(log_file: Optional[Path] = None, level: int = logging.INFO):
    """Configure logging to file and stdout."""
    handlers = [logging.StreamHandler(sys.stdout)]

    if log_file:
        handlers.append(logging.FileHandler(log_file, encoding='utf-8'))

    logging.basicConfig(
        level=level,
        format='[%(asctime)s] [%(levelname)s] %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S',
        handlers=handlers
    )

    return logging.getLogger(__name__)


# ============================================================================
# Database Operations
# ============================================================================

class DatabaseConnection:
    """Manages SQL Server connection with reconnection logic."""

    def __init__(self, config: dict):
        self.config = config
        self.conn: Optional[pyodbc.Connection] = None
        self.logger = logging.getLogger(__name__)

    def connect(self) -> bool:
        """Establish database connection."""
        if not HAS_PYODBC:
            return False

        if not all([self.config['db_host'], self.config['db_name'],
                    self.config['db_user'], self.config['db_pass']]):
            self.logger.error("Database configuration incomplete")
            return False

        try:
            # Try ODBC Driver 18 first, fall back to generic SQL Server driver
            drivers = pyodbc.drivers()
            if 'ODBC Driver 18 for SQL Server' in drivers:
                driver = 'ODBC Driver 18 for SQL Server'
                encrypt = 'Encrypt=yes;TrustServerCertificate=no;'
            elif 'ODBC Driver 17 for SQL Server' in drivers:
                driver = 'ODBC Driver 17 for SQL Server'
                encrypt = 'Encrypt=yes;TrustServerCertificate=no;'
            else:
                driver = 'SQL Server'
                encrypt = ''  # Older driver doesn't support these options

            conn_str = (
                f"DRIVER={{{driver}}};"
                f"SERVER={self.config['db_host']};"
                f"DATABASE={self.config['db_name']};"
                f"UID={self.config['db_user']};"
                f"PWD={self.config['db_pass']};"
                f"{encrypt}"
                f"Connection Timeout=30;"
            )

            self.conn = pyodbc.connect(conn_str)
            self.conn.timeout = self.config.get('sp_timeout', 120)
            self.logger.info(f"Connected to database: {self.config['db_name']}")
            return True

        except pyodbc.Error as e:
            self.logger.error(f"Database connection failed: {e}")
            return False

    def ensure_connected(self) -> bool:
        """Ensure connection is active, reconnect if needed."""
        if self.conn:
            try:
                # Test connection
                cursor = self.conn.cursor()
                cursor.execute("SELECT 1")
                cursor.close()
                return True
            except pyodbc.Error:
                self.conn = None

        return self.connect()

    def import_atis(self, atis_list: list[AtisController]) -> int:
        """
        Import ATIS records to database.

        Returns:
            Number of records inserted
        """
        if not self.ensure_connected():
            return 0

        # Convert to JSON
        records = [ctrl.to_dict() for ctrl in atis_list]
        json_data = json.dumps(records)

        try:
            cursor = self.conn.cursor()
            cursor.execute("EXEC dbo.sp_ImportVatsimAtis ?", json_data)

            # Get result
            row = cursor.fetchone()
            inserted = row[0] if row else 0

            self.conn.commit()
            cursor.close()

            return inserted

        except pyodbc.Error as e:
            self.logger.error(f"Failed to import ATIS: {e}")
            return 0

    def get_pending_atis(self, limit: int = 100) -> list[dict]:
        """Get ATIS records pending parsing."""
        if not self.ensure_connected():
            return []

        try:
            cursor = self.conn.cursor()
            cursor.execute("EXEC dbo.sp_GetPendingAtis ?", limit)

            results = []
            for row in cursor.fetchall():
                results.append({
                    'atis_id': row[0],
                    'airport_icao': row[1],
                    'callsign': row[2],
                    'atis_type': row[3],
                    'atis_code': row[4],
                    'atis_text': row[5],
                })

            cursor.close()
            return results

        except pyodbc.Error as e:
            self.logger.error(f"Failed to get pending ATIS: {e}")
            return []

    def import_runways(self, atis_id: int, runways_json: str) -> bool:
        """Import parsed runways for an ATIS record."""
        if not self.ensure_connected():
            return False

        try:
            cursor = self.conn.cursor()
            cursor.execute("EXEC dbo.sp_ImportRunwaysInUse ?, ?", atis_id, runways_json)
            self.conn.commit()
            cursor.close()
            return True

        except pyodbc.Error as e:
            self.logger.error(f"Failed to import runways for ATIS {atis_id}: {e}")
            return False

    def import_runways_batch(self, batch_data: list[dict]) -> dict:
        """
        Import parsed runways for multiple ATIS records in a single call.

        Args:
            batch_data: List of {"atis_id": int, "runways": [...]} dicts

        Returns:
            {"parsed": int, "skipped": int, "runways": int} or empty dict on error
        """
        if not self.ensure_connected() or not batch_data:
            return {}

        try:
            cursor = self.conn.cursor()
            json_data = json.dumps(batch_data)
            cursor.execute("EXEC dbo.sp_ImportRunwaysInUseBatch ?", json_data)

            row = cursor.fetchone()
            result = {
                'parsed': row[0] if row else 0,
                'skipped': row[1] if row else 0,
                'runways': row[2] if row else 0,
            }

            self.conn.commit()
            cursor.close()
            return result

        except pyodbc.Error as e:
            self.logger.error(f"Failed to batch import runways: {e}")
            return {}

    def close(self):
        """Close database connection."""
        if self.conn:
            try:
                self.conn.close()
            except:
                pass
            self.conn = None


# ============================================================================
# Main Processing Loop
# ============================================================================

class AtisDaemon:
    """Main daemon for ATIS processing."""

    def __init__(self, config: dict, airports: Optional[list[str]] = None):
        self.config = config
        self.airports = airports
        self.running = True
        self.logger = logging.getLogger(__name__)
        self.db = DatabaseConnection(config) if HAS_PYODBC else None

        # Statistics
        self.stats = {
            'cycles': 0,
            'atis_fetched': 0,
            'atis_imported': 0,
            'runways_parsed': 0,
            'errors': 0,
        }

    def stop(self):
        """Signal daemon to stop."""
        self.running = False
        self.logger.info("Shutdown signal received")

    def process_cycle(self) -> dict:
        """
        Run one processing cycle with optimized batch processing.

        Returns:
            Dictionary with cycle statistics
        """
        cycle_stats = {
            'atis_count': 0,
            'imported': 0,
            'parsed': 0,
            'fetch_ms': 0,
            'parse_ms': 0,
            'db_ms': 0,
        }

        # Fetch VATSIM data
        fetch_start = time.time()
        vatsim_data = fetch_vatsim_data(use_cache=False)
        cycle_stats['fetch_ms'] = int((time.time() - fetch_start) * 1000)

        if not vatsim_data:
            self.logger.warning("Failed to fetch VATSIM data")
            self.stats['errors'] += 1
            return cycle_stats

        # Extract ATIS controllers
        atis_list = extract_atis_controllers(vatsim_data)

        # Filter by airports if specified
        if self.airports:
            normalized = set()
            for apt in self.airports:
                apt = apt.upper()
                normalized.add(apt)
                if apt.startswith('K') and len(apt) == 4:
                    normalized.add(apt[1:])
                elif len(apt) == 3:
                    normalized.add('K' + apt)

            atis_list = [a for a in atis_list if a.airport_icao in normalized]

        cycle_stats['atis_count'] = len(atis_list)
        self.stats['atis_fetched'] += len(atis_list)

        # Import to database
        if self.db and atis_list:
            db_start = time.time()
            imported = self.db.import_atis(atis_list)
            cycle_stats['imported'] = imported
            self.stats['atis_imported'] += imported

            # Parse pending ATIS records - process larger batches
            parse_start = time.time()
            pending = self.db.get_pending_atis(limit=500)

            # Pre-parse all ATIS in memory first (fast)
            batch_data = []
            for record in pending:
                # Pass atis_type to parser for correct runway assignment inference
                # (e.g., ARR ATIS "runways in use" = arrival runways only)
                runways = parse_full_runway_info(record['atis_text'], record.get('atis_type'))
                batch_data.append({
                    'atis_id': record['atis_id'],
                    'runways': [r.to_dict() for r in runways] if runways else []
                })

            # Single batch import to database (eliminates N+1)
            if batch_data:
                result = self.db.import_runways_batch(batch_data)
                if result:
                    cycle_stats['parsed'] = result.get('parsed', 0) + result.get('skipped', 0)
                    self.stats['runways_parsed'] += result.get('parsed', 0)

            cycle_stats['parse_ms'] = int((time.time() - parse_start) * 1000)
            cycle_stats['db_ms'] = int((time.time() - db_start) * 1000)

        return cycle_stats

    def run(self, once: bool = False, quiet: bool = False):
        """
        Run the daemon loop.

        Args:
            once: If True, run one cycle and exit
            quiet: If True, reduce logging (log every 10 cycles)
        """
        self.logger.info("ATIS daemon starting")

        if self.airports:
            self.logger.info(f"Filtering airports: {', '.join(self.airports)}")

        if self.db:
            self.db.connect()
        else:
            self.logger.warning("No database connection - running in dry-run mode")

        log_every = 10 if quiet else 1

        try:
            while self.running:
                cycle_start = time.time()
                self.stats['cycles'] += 1

                try:
                    stats = self.process_cycle()

                    # Log less frequently in quiet mode
                    if self.stats['cycles'] % log_every == 0 or stats['imported'] > 0:
                        self.logger.info(
                            f"Cycle {self.stats['cycles']}: "
                            f"ATIS={stats['atis_count']}, "
                            f"Imported={stats['imported']}, "
                            f"Parsed={stats['parsed']}, "
                            f"Fetch={stats['fetch_ms']}ms, "
                            f"DB={stats['db_ms']}ms"
                        )

                except Exception as e:
                    self.logger.exception(f"Cycle error: {e}")
                    self.stats['errors'] += 1
                    # Reset connection on error
                    if self.db:
                        self.db.conn = None

                if once:
                    break

                # Sleep until next cycle
                elapsed = time.time() - cycle_start
                sleep_time = max(0, self.config['interval_seconds'] - elapsed)
                if sleep_time > 0:
                    time.sleep(sleep_time)

        finally:
            if self.db:
                self.db.close()

            self.logger.info(
                f"Daemon stopped. Stats: cycles={self.stats['cycles']}, "
                f"fetched={self.stats['atis_fetched']}, "
                f"imported={self.stats['atis_imported']}, "
                f"parsed={self.stats['runways_parsed']}, "
                f"errors={self.stats['errors']}"
            )


# ============================================================================
# Entry Point
# ============================================================================

def main():
    parser = argparse.ArgumentParser(
        description="VATSIM ATIS Import Daemon",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__
    )
    parser.add_argument(
        '--once', action='store_true',
        help='Run one cycle and exit'
    )
    parser.add_argument(
        '--airports', type=str,
        help='Comma-separated list of airport ICAO codes to filter'
    )
    parser.add_argument(
        '--interval', type=int, default=15,
        help='Seconds between cycles (default: 15)'
    )
    parser.add_argument(
        '--debug', action='store_true',
        help='Enable debug logging'
    )
    parser.add_argument(
        '--dry-run', action='store_true',
        help='Fetch and parse but do not write to database'
    )

    args = parser.parse_args()

    # Setup logging
    log_level = logging.DEBUG if args.debug else logging.INFO
    logger = setup_logging(CONFIG['log_file'], log_level)

    # Parse airports filter
    airports = None
    if args.airports:
        airports = [a.strip().upper() for a in args.airports.split(',')]

    # Update config
    CONFIG['interval_seconds'] = args.interval

    # Create daemon
    daemon = AtisDaemon(
        config=CONFIG if not args.dry_run else {**CONFIG, 'db_host': ''},
        airports=airports
    )

    # Setup signal handlers
    def signal_handler(signum, frame):
        daemon.stop()

    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)

    # Run
    daemon.run(once=args.once)


if __name__ == '__main__':
    main()
