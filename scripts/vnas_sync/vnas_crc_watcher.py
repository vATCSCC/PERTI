"""
vNAS CRC file watcher and PERTI API client.

Monitors %LOCALAPPDATA%/CRC/ARTCCs/*.json for changes,
parses them via crc_parser, and POSTs to PERTI ingest endpoints.

Usage:
    python vnas_crc_watcher.py [--once] [--artcc ZDC] [--debug] [--dry-run]
"""

import argparse
import json
import logging
import os
import sys
import time
from pathlib import Path

import requests
from watchdog.observers import Observer
from watchdog.events import FileSystemEventHandler

# Import sibling parser module
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from crc_parser import parse_artcc_json

logger = logging.getLogger('vnas_crc_watcher')

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

CRC_DIR = os.path.join(os.environ.get('LOCALAPPDATA', ''), 'CRC', 'ARTCCs')
STATE_DIR = os.path.join(os.path.expanduser('~'), '.perti')
STATE_FILE = os.path.join(STATE_DIR, 'vnas_sync_state.json')

DEBOUNCE_SECONDS = 5


# ---------------------------------------------------------------------------
# State management
# ---------------------------------------------------------------------------

def load_state():
    """Load the lastUpdatedAt state file, returning a dict keyed by ARTCC code."""
    if os.path.isfile(STATE_FILE):
        try:
            with open(STATE_FILE, 'r', encoding='utf-8') as f:
                return json.load(f)
        except (json.JSONDecodeError, OSError) as exc:
            logger.warning('Failed to read state file %s: %s', STATE_FILE, exc)
    return {}


def save_state(state):
    """Persist the state dict to disk."""
    os.makedirs(STATE_DIR, exist_ok=True)
    with open(STATE_FILE, 'w', encoding='utf-8') as f:
        json.dump(state, f, indent=2)


# ---------------------------------------------------------------------------
# Core sync logic
# ---------------------------------------------------------------------------

def read_last_updated_at(filepath):
    """Read and return the lastUpdatedAt value from a CRC JSON file."""
    with open(filepath, 'r', encoding='utf-8') as f:
        data = json.load(f)
    return data.get('lastUpdatedAt')


def sync_artcc(filepath, state, base_url, api_key, dry_run=False):
    """Parse an ARTCC JSON file and POST to PERTI if changed.

    Returns True if a sync was performed (or would have been in dry-run).
    """
    artcc_code = Path(filepath).stem  # e.g. "ZDC" from "ZDC.json"

    try:
        current_updated = read_last_updated_at(filepath)
    except (json.JSONDecodeError, OSError) as exc:
        logger.error('Failed to read %s: %s', filepath, exc)
        return False

    saved_updated = state.get(artcc_code)

    if saved_updated == current_updated:
        logger.debug('%s unchanged (lastUpdatedAt=%s), skipping', artcc_code, current_updated)
        return False

    logger.info('Processing %s (lastUpdatedAt %s -> %s)', artcc_code,
                saved_updated or '(none)', current_updated)

    # Parse
    try:
        facilities_payload, restrictions_payload = parse_artcc_json(filepath)
    except Exception as exc:
        logger.error('Parse failed for %s: %s', filepath, exc)
        return False

    # Log payload sizes
    logger.info(
        '%s payload: %d facilities, %d positions, %d stars_tcps, %d stars_areas, '
        '%d beacon_banks, %d transceivers, %d video_maps, %d airport_groups, '
        '%d common_urls, %d restrictions, %d auto_atc_rules',
        artcc_code,
        len(facilities_payload.get('facilities', [])),
        len(facilities_payload.get('positions', [])),
        len(facilities_payload.get('stars_tcps', [])),
        len(facilities_payload.get('stars_areas', [])),
        len(facilities_payload.get('beacon_banks', [])),
        len(facilities_payload.get('transceivers', [])),
        len(facilities_payload.get('video_maps', [])),
        len(facilities_payload.get('airport_groups', [])),
        len(facilities_payload.get('common_urls', [])),
        len(restrictions_payload.get('restrictions', [])),
        len(restrictions_payload.get('auto_atc_rules', [])),
    )

    if dry_run:
        logger.info('[DRY RUN] Would POST facilities to %s/api/swim/v1/ingest/vnas/facilities',
                     base_url)
        logger.info('[DRY RUN] Would POST restrictions to %s/api/swim/v1/ingest/vnas/restrictions',
                     base_url)
    else:
        headers = {
            'Content-Type': 'application/json',
            'X-API-Key': api_key,
        }

        # POST facilities
        fac_url = f'{base_url}/api/swim/v1/ingest/vnas/facilities'
        try:
            resp = requests.post(fac_url, json=facilities_payload, headers=headers, timeout=60)
            if resp.status_code == 200:
                logger.info('%s facilities POST OK (200)', artcc_code)
            else:
                logger.error('%s facilities POST failed (%d): %s',
                             artcc_code, resp.status_code, resp.text[:500])
        except requests.RequestException as exc:
            logger.error('%s facilities POST error: %s', artcc_code, exc)

        # POST restrictions
        rst_url = f'{base_url}/api/swim/v1/ingest/vnas/restrictions'
        try:
            resp = requests.post(rst_url, json=restrictions_payload, headers=headers, timeout=60)
            if resp.status_code == 200:
                logger.info('%s restrictions POST OK (200)', artcc_code)
            else:
                logger.error('%s restrictions POST failed (%d): %s',
                             artcc_code, resp.status_code, resp.text[:500])
        except requests.RequestException as exc:
            logger.error('%s restrictions POST error: %s', artcc_code, exc)

    # Update state
    if current_updated:
        state[artcc_code] = current_updated
        save_state(state)

    return True


# ---------------------------------------------------------------------------
# --once mode
# ---------------------------------------------------------------------------

def run_once(args, base_url, api_key):
    """Process all (or specified) ARTCCs once and exit."""
    state = load_state()

    if args.artcc:
        files = [os.path.join(CRC_DIR, f'{args.artcc}.json')]
        if not os.path.isfile(files[0]):
            logger.error('ARTCC file not found: %s', files[0])
            return 1
        # If no saved state for this ARTCC, force-process it
        if args.artcc not in state:
            logger.info('No saved state for %s, will process unconditionally', args.artcc)
    else:
        files = sorted(
            os.path.join(CRC_DIR, f)
            for f in os.listdir(CRC_DIR)
            if f.endswith('.json')
        )

    if not files:
        logger.warning('No ARTCC JSON files found in %s', CRC_DIR)
        return 1

    processed = 0
    skipped = 0

    for filepath in files:
        artcc_code = Path(filepath).stem
        if args.artcc and args.artcc not in state:
            # Force processing by temporarily removing saved state
            pass  # state already lacks the key, sync_artcc will detect mismatch
        synced = sync_artcc(filepath, state, base_url, api_key, dry_run=args.dry_run)
        if synced:
            processed += 1
        else:
            skipped += 1

    logger.info('Done: %d processed, %d skipped (unchanged)', processed, skipped)
    return 0


# ---------------------------------------------------------------------------
# Watch mode (watchdog)
# ---------------------------------------------------------------------------

class ArtccFileHandler(FileSystemEventHandler):
    """Handles .json file modification events in the CRC ARTCCs directory."""

    def __init__(self, base_url, api_key, dry_run=False, artcc_filter=None):
        super().__init__()
        self.base_url = base_url
        self.api_key = api_key
        self.dry_run = dry_run
        self.artcc_filter = artcc_filter
        self.state = load_state()
        self._last_sync = {}  # artcc_code -> timestamp for debounce

    def on_modified(self, event):
        if event.is_directory:
            return
        if not event.src_path.endswith('.json'):
            return

        artcc_code = Path(event.src_path).stem

        if self.artcc_filter and artcc_code != self.artcc_filter:
            return

        # Debounce: skip if synced within DEBOUNCE_SECONDS
        now = time.time()
        last = self._last_sync.get(artcc_code, 0)
        if now - last < DEBOUNCE_SECONDS:
            logger.debug('Debounce: ignoring %s change (%.1fs since last sync)',
                         artcc_code, now - last)
            return

        logger.debug('File change detected: %s', event.src_path)

        try:
            synced = sync_artcc(event.src_path, self.state, self.base_url,
                                self.api_key, dry_run=self.dry_run)
            if synced:
                self._last_sync[artcc_code] = time.time()
        except Exception:
            logger.exception('Error processing %s', event.src_path)


def run_watch(args, base_url, api_key):
    """Watch CRC ARTCCs directory for file changes."""
    if not os.path.isdir(CRC_DIR):
        logger.error('CRC ARTCCs directory not found: %s', CRC_DIR)
        return 1

    handler = ArtccFileHandler(
        base_url=base_url,
        api_key=api_key,
        dry_run=args.dry_run,
        artcc_filter=args.artcc,
    )
    observer = Observer()
    observer.schedule(handler, CRC_DIR, recursive=False)
    observer.start()

    logger.info('Watching %s for changes (Ctrl+C to stop)', CRC_DIR)
    if args.artcc:
        logger.info('Filtering to ARTCC: %s', args.artcc)
    if args.dry_run:
        logger.info('Dry-run mode: no HTTP POSTs will be made')

    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        logger.info('Stopping watcher...')
        observer.stop()
    observer.join()
    return 0


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(
        description='vNAS CRC file watcher - syncs ARTCC data to PERTI',
    )
    parser.add_argument('--once', action='store_true',
                        help='Process all (or specified) ARTCCs once and exit')
    parser.add_argument('--artcc', type=str, default=None,
                        help='Only process this one ARTCC (e.g. ZDC)')
    parser.add_argument('--debug', action='store_true',
                        help='Enable verbose debug logging')
    parser.add_argument('--dry-run', action='store_true',
                        help='Parse and log but skip HTTP POSTs')
    args = parser.parse_args()

    # Logging setup
    log_level = logging.DEBUG if args.debug else logging.INFO
    logging.basicConfig(
        level=log_level,
        format='%(asctime)s [%(levelname)s] %(message)s',
    )

    # Configuration
    base_url = os.environ.get('PERTI_API_URL', 'https://perti.vatcscc.org').rstrip('/')
    api_key = os.environ.get('PERTI_API_KEY', '')

    if not api_key and not args.dry_run:
        logger.error('PERTI_API_KEY environment variable is required (or use --dry-run)')
        return 1

    if not os.path.isdir(CRC_DIR):
        logger.error('CRC ARTCCs directory not found: %s', CRC_DIR)
        return 1

    logger.info('PERTI API URL: %s', base_url)
    logger.info('CRC ARTCCs dir: %s', CRC_DIR)

    if args.once:
        return run_once(args, base_url, api_key)
    else:
        return run_watch(args, base_url, api_key)


if __name__ == '__main__':
    sys.exit(main())
