"""
Configuration constants for FAA Playbook parser.
"""

import os
from pathlib import Path

# Determine project root (two levels up from this file)
PROJECT_ROOT = Path(__file__).parent.parent.parent

# FAA Playbook URLs
FAA_PLAYBOOK_BASE = "https://www.fly.faa.gov/playbook"
MENU_URL = f"{FAA_PLAYBOOK_BASE}/playbookMenu?active=1"
PLAY_URL_TEMPLATE = f"{FAA_PLAYBOOK_BASE}/playbook?playkey={{playkey}}"

# Request throttling (FAA server can be slow)
REQUEST_DELAY = 0.5  # seconds between requests
MAX_RETRIES = 3
RETRY_DELAY = 2  # seconds between retries
REQUEST_TIMEOUT = 30  # seconds

# User agent for requests
USER_AGENT = "Mozilla/5.0 (compatible; vATCSCC-PlaybookScraper/2.0)"

# Data file paths (relative to project root)
DATA_DIR = PROJECT_ROOT / "assets" / "data"
APTS_CSV = DATA_DIR / "apts.csv"
POINTS_CSV = DATA_DIR / "points.csv"
AWYS_CSV = DATA_DIR / "awys.csv"
DP_CSV = DATA_DIR / "dp_full_routes.csv"
STAR_CSV = DATA_DIR / "star_full_routes.csv"
OUTPUT_CSV = DATA_DIR / "playbook_routes.csv"

# Output CSV columns
OUTPUT_COLUMNS = [
    "Play",
    "Route String",
    "Origins",
    "Origin_TRACONs",
    "Origin_ARTCCs",
    "Destinations",
    "Dest_TRACONs",
    "Dest_ARTCCs"
]

# Known column header variations (for flexible parsing)
ORIGIN_HEADERS = ['ORIGIN', 'ORIGINS', 'ORIG', 'FROM']
DEST_HEADERS = ['DEST', 'DESTINATION', 'DESTINATIONS', 'TO']
ROUTE_HEADERS = ['ROUTE', 'ROUTES', 'ROUTE STRING', 'ROUTING']
FILTER_HEADERS = ['FILTERS', 'FILTER', 'RESTRICTIONS']
REMARKS_HEADERS = ['REMARKS', 'REMARK', 'NOTES', 'NOTE']

# AIRAC cycle calculation
AIRAC_EPOCH_DATE = "2024-01-25"  # Known cycle start date (AIRAC 2401)
AIRAC_CYCLE_DAYS = 28

# Validation thresholds
MIN_ROUTE_LENGTH = 3  # Minimum characters for a valid route string
MAX_ROUTE_LENGTH = 500  # Maximum route length before warning

# Known facility prefixes
ARTCC_PREFIX = 'Z'  # ARTCCs start with Z (ZAU, ZBW, ZDC, etc.)
ARTCC_LENGTH = 3
