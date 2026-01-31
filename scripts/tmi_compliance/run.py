#!/usr/bin/env python3
"""
TMI Compliance Analysis CLI
===========================

Command-line interface for running TMI compliance analysis.
Called from PHP via shell_exec() or directly from command line.

Usage:
    python run.py --plan_id 123
    python run.py --plan_id 123 --api_url https://perti.vatcscc.org/api

Output:
    JSON results to stdout (errors to stderr)
"""

import argparse
import json
import logging
import os
import sys
from datetime import datetime, timedelta

# Add parent directory to path for imports
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import requests
from core.models import EventConfig
from core.ntml_parser import parse_ntml_to_tmis
from core.analyzer import TMIComplianceAnalyzer

# Configure logging to stderr (so stdout is clean JSON)
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    stream=sys.stderr
)
logger = logging.getLogger(__name__)


def load_config_from_api(plan_id: int, api_url: str) -> dict:
    """Load TMI configuration from PERTI API"""
    config_url = f"{api_url}/analysis/tmi_config.php?p_id={plan_id}"
    logger.info(f"Fetching config from: {config_url}")

    try:
        response = requests.get(config_url, timeout=30)
        response.raise_for_status()

        data = response.json()
        if data.get('success') and data.get('data'):
            return data['data']
        else:
            logger.warning(f"No config in response: {data.get('message', 'Unknown error')}")
            return None

    except requests.RequestException as e:
        logger.error(f"Failed to fetch config: {e}")
        return None


def build_event_config(config: dict, plan_id: int) -> EventConfig:
    """Build EventConfig from saved web configuration"""

    # Parse destinations
    dest_str = config.get('destinations', '')
    destinations = [d.strip().upper() for d in dest_str.split(',') if d.strip()]

    # Parse event times
    event_start_str = config.get('event_start', '')
    event_end_str = config.get('event_end', '')

    def parse_datetime(dt_str: str) -> datetime:
        """Parse datetime from various formats"""
        for fmt in ['%Y-%m-%d %H:%M', '%Y-%m-%d %H:%M:%S', '%Y-%m-%dT%H:%M:%S', '%Y-%m-%dT%H:%M']:
            try:
                return datetime.strptime(dt_str.strip(), fmt)
            except ValueError:
                continue
        raise ValueError(f"Could not parse datetime: {dt_str}")

    event_start = parse_datetime(event_start_str)
    event_end = parse_datetime(event_end_str)

    # Create event
    event = EventConfig(
        name=config.get('event_name', f"Plan {plan_id} - TMI Analysis"),
        start_utc=event_start,
        end_utc=event_end,
        destinations=destinations
    )

    # Parse NTML text into TMIs
    ntml_text = config.get('ntml_text', '')
    if ntml_text:
        tmis = parse_ntml_to_tmis(ntml_text, event_start, event_end, destinations)
        event.tmis = tmis
        logger.info(f"Parsed {len(tmis)} TMIs from NTML")

    return event


def run_analysis(plan_id: int, api_url: str) -> dict:
    """Run TMI compliance analysis for a plan"""
    logger.info(f"Starting TMI compliance analysis for plan_id: {plan_id}")

    # Load configuration from PERTI API
    config = load_config_from_api(plan_id, api_url)
    if not config:
        return {"error": f"No TMI config found for plan {plan_id}. Save configuration in PERTI first."}

    # Build event config
    event = build_event_config(config, plan_id)

    if not event.tmis:
        return {"error": "No TMIs defined in configuration. Add NTML entries and save."}

    logger.info(f"Event: {event.name}, TMIs: {len(event.tmis)}")

    # Run analysis
    analyzer = TMIComplianceAnalyzer(event)
    results = analyzer.analyze()

    # Add plan_id to results
    results['plan_id'] = plan_id

    logger.info("Analysis complete")
    return results


def main():
    parser = argparse.ArgumentParser(description='Run TMI Compliance Analysis')
    parser.add_argument('--plan_id', type=int, required=True, help='PERTI plan ID to analyze')
    parser.add_argument('--api_url', type=str,
                        default=os.environ.get('PERTI_API_URL', 'https://perti.vatcscc.org/api'),
                        help='PERTI API base URL')

    args = parser.parse_args()

    try:
        results = run_analysis(args.plan_id, args.api_url)
        # Output JSON to stdout
        print(json.dumps(results, default=str))
        sys.exit(0 if 'error' not in results else 1)
    except Exception as e:
        logger.exception("Analysis failed")
        print(json.dumps({"error": str(e)}))
        sys.exit(1)


if __name__ == '__main__':
    main()
