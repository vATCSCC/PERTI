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
from core.models import EventConfig, TMI, TMIType
from core.ntml_parser import parse_ntml_to_tmis, parse_ntml_full
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

    def parse_time_window(time_str: str, base_date) -> datetime:
        """Parse HHMM time and combine with base date"""
        time_str = time_str.replace(':', '').replace('Z', '').strip()
        if len(time_str) >= 4:
            hour = int(time_str[:2])
            minute = int(time_str[2:4])
            result = datetime(base_date.year, base_date.month, base_date.day, hour, minute)
            return result
        return None

    event_start = parse_datetime(event_start_str)
    event_end = parse_datetime(event_end_str)

    # Create event
    event = EventConfig(
        name=config.get('event_name', f"Plan {plan_id} - TMI Analysis"),
        start_utc=event_start,
        end_utc=event_end,
        destinations=destinations
    )

    # Use pre-parsed TMIs from API if available
    parsed_tmis = config.get('parsed_tmis', [])
    if parsed_tmis:
        tmis = []
        for pt in parsed_tmis:
            tmi_type_str = pt.get('type', 'MIT').upper()
            tmi_type = TMIType.MIT  # default
            if tmi_type_str == 'MINIT':
                tmi_type = TMIType.MINIT
            elif tmi_type_str == 'GS':
                tmi_type = TMIType.GS
            elif tmi_type_str == 'APREQ':
                tmi_type = TMIType.APREQ
            elif tmi_type_str == 'CFR':
                tmi_type = TMIType.CFR
            elif tmi_type_str == 'REROUTE':
                tmi_type = TMIType.REROUTE

            fix = pt.get('fix', '')
            dest = pt.get('dest', '')

            # Parse time window - prefer explicit fields, fall back to parsing raw text
            raw = pt.get('raw', '')
            start_utc = event_start
            end_utc = event_end
            issued_utc = None

            # Try explicit start_time/end_time fields first (from PHP parser)
            start_time_str = pt.get('start_time', '')
            end_time_str = pt.get('end_time', '')
            issued_time_str = pt.get('issued_time', '')

            import re
            if start_time_str and end_time_str:
                start_utc = parse_time_window(start_time_str, event_start.date())
                end_utc = parse_time_window(end_time_str, event_start.date())
            else:
                # Fall back to extracting from raw text (e.g., "0045-0320" or "2359Z-0400Z")
                time_match = re.search(r'(\d{4})Z?-(\d{4})Z?', raw)
                if time_match:
                    start_utc = parse_time_window(time_match.group(1), event_start.date())
                    end_utc = parse_time_window(time_match.group(2), event_start.date())

            # Parse issued time for GS
            if issued_time_str:
                issued_utc = parse_time_window(issued_time_str, event_start.date())

            # Handle overnight events: if parsed times are before event start,
            # shift them to the next day. This handles events like 23:59-04:00
            # where TMI times like "0045" should be on the next calendar day.
            if start_utc and start_utc < event_start - timedelta(hours=2):
                start_utc = start_utc + timedelta(days=1)
                end_utc = end_utc + timedelta(days=1) if end_utc else None
                issued_utc = issued_utc + timedelta(days=1) if issued_utc else None

            # Also handle case where end < start (e.g., 2300-0100)
            if end_utc and start_utc and end_utc < start_utc:
                end_utc = end_utc + timedelta(days=1)

            # Handle destinations (may be array or comma-separated string)
            if 'destinations' in pt and isinstance(pt['destinations'], list):
                dest_list = pt['destinations']
            elif dest:
                if ',' in dest:
                    dest_list = [d.strip() for d in dest.split(',') if d.strip()]
                elif dest.upper() not in ['ALL', 'ANY', 'DEPARTURES']:
                    dest_list = [dest]
                else:
                    dest_list = destinations
            else:
                dest_list = destinations

            # Handle origins (for CFR "X Departures" format, or GS DEP FACILITIES, or REROUTE)
            origin = pt.get('origin', '')
            provider = pt.get('provider', '')
            # For GS, the 'provider' (DEP FACILITIES INCLUDED) is actually the origin constraint
            if tmi_type == TMIType.GS and provider and not origin:
                origins = [provider]
            elif tmi_type == TMIType.REROUTE and 'origins' in pt:
                # REROUTE has explicit origins array from PHP parser
                origins = pt['origins'] if isinstance(pt['origins'], list) else []
            elif origin:
                origins = [origin]
            else:
                origins = []

            # Build base TMI
            tmi = TMI(
                tmi_id=f'{tmi_type.value}_{fix}_{dest}',
                tmi_type=tmi_type,
                fix=fix if fix else None,
                destinations=dest_list,
                origins=origins,
                value=pt.get('value', 0),
                unit='nm' if tmi_type == TMIType.MIT else 'min',
                provider=pt.get('provider', ''),
                requestor=pt.get('requestor', ''),
                start_utc=start_utc,
                end_utc=end_utc,
                issued_utc=issued_utc
            )

            # Add reroute-specific fields
            if tmi_type == TMIType.REROUTE:
                tmi.reroute_name = pt.get('name', '')
                tmi.reroute_mandatory = pt.get('mandatory', False)
                tmi.reroute_routes = pt.get('routes', [])
                tmi.time_type = pt.get('time_type', 'ETD')  # ETA or ETD
                tmi.reason = pt.get('reason', '')
                tmi.artccs = pt.get('facilities', [])
                # Update tmi_id to use name if available
                if tmi.reroute_name:
                    tmi.tmi_id = f"REROUTE_{tmi.reroute_name}"

            tmis.append(tmi)

        event.tmis = tmis
        logger.info(f"Loaded {len(tmis)} pre-parsed TMIs from API")
    else:
        # Fallback: try to parse NTML text directly using full parser for delays too
        ntml_text = config.get('ntml_text', '')
        if ntml_text:
            # Use parse_ntml_full to get TMIs, delays, and other entries
            parse_result = parse_ntml_full(ntml_text, event_start, event_end, destinations)
            event.tmis = parse_result.tmis
            event.delays = parse_result.delays
            event.airport_configs = parse_result.airport_configs
            event.cancellations = parse_result.cancellations
            event.skipped_lines = parse_result.skipped_lines
            logger.info(f"Parsed {len(parse_result.tmis)} TMIs, {len(parse_result.delays)} delays, "
                       f"{len(parse_result.skipped_lines)} skipped from NTML text")

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
    parser.add_argument('--output', type=str, default=None,
                        help='Write JSON results to file instead of stdout')

    args = parser.parse_args()

    try:
        results = run_analysis(args.plan_id, args.api_url)

        # Split trajectory data into separate file for memory efficiency
        # PHP serves trajectories via readfile() (zero json_decode overhead)
        trajectories = results.pop('_trajectories', {})

        json_str = json.dumps(results, default=str)

        if args.output:
            # Write to file (avoids PHP memory issues with large stdout capture)
            os.makedirs(os.path.dirname(args.output), exist_ok=True)

            # Atomic write: temp file + rename to prevent partial reads
            tmp_path = args.output + '.tmp'
            with open(tmp_path, 'w') as f:
                f.write(json_str)
            os.replace(tmp_path, args.output)
            logger.info(f"Results written to {args.output} ({len(json_str)} bytes)")

            # Write trajectory file alongside results
            if trajectories:
                traj_path = args.output.replace('_results_', '_trajectories_')
                traj_str = json.dumps(trajectories, default=str)
                tmp_traj = traj_path + '.tmp'
                with open(tmp_traj, 'w') as f:
                    f.write(traj_str)
                os.replace(tmp_traj, traj_path)
                logger.info(f"Trajectories written to {traj_path} ({len(traj_str)} bytes)")

            print(json.dumps({"output_file": args.output, "size": len(json_str)}))
        else:
            # Output JSON to stdout (trajectories included inline for backwards compat)
            # Re-embed trajectories for stdout mode
            for key, traj in trajectories.items():
                if key in results.get('mit_results', {}):
                    results['mit_results'][key]['trajectories'] = traj
            print(json.dumps(results, default=str))

        sys.exit(0 if 'error' not in results else 1)
    except Exception as e:
        logger.exception("Analysis failed")
        print(json.dumps({"error": str(e)}))
        sys.exit(1)


if __name__ == '__main__':
    main()
