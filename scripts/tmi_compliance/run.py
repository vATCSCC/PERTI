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
import re
import sys
from datetime import datetime, timedelta

# Add parent directory to path for imports
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import requests
from core.models import (
    EventConfig, TMI, TMIType,
    GSProgram, GSAdvisory, RerouteProgram, RerouteAdvisory, RouteEntry
)
from core.ntml_parser import parse_ntml_to_tmis, parse_ntml_full, extract_programs_from_ntml
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


def parse_time_window(time_str: str, base_date) -> datetime:
    """Parse HHMM time string and combine with base date.

    Handles common format variations: '0149', '01:49', '0149Z'.
    Returns None if the string cannot be parsed.
    """
    if not time_str:
        return None
    time_str = time_str.replace(':', '').replace('Z', '').strip()
    if len(time_str) >= 4:
        try:
            hour = int(time_str[:2])
            minute = int(time_str[2:4])
            return datetime(base_date.year, base_date.month, base_date.day, hour, minute)
        except (ValueError, TypeError):
            return None
    return None


def build_gs_program_from_api(pt: dict, base_date, event_start=None) -> GSProgram:
    """Convert a PHP GS_PROGRAM entry to a Python GSProgram object.

    Args:
        pt: Dict from PHP parsed_tmis with type='GS_PROGRAM'
        base_date: Date object for resolving HHMM times
        event_start: Full event start datetime for overnight shift detection

    Returns:
        GSProgram or None if essential data is missing
    """
    airport = pt.get('airport', '').upper()
    if not airport:
        logger.warning("GS_PROGRAM entry missing airport, skipping")
        return None

    # Parse effective times
    effective_start = parse_time_window(pt.get('effective_start', ''), base_date)
    effective_end = parse_time_window(pt.get('effective_end', ''), base_date)

    # Handle overnight events: if parsed times fall well before event start,
    # they belong to the next calendar day (e.g., event at 23:59Z, GS at 02:00Z)
    if event_start and effective_start and effective_start < event_start - timedelta(hours=2):
        effective_start = effective_start + timedelta(days=1)
        if effective_end:
            effective_end = effective_end + timedelta(days=1)

    # Handle overnight wrap: if end < start, add a day
    if effective_start and effective_end and effective_end < effective_start:
        effective_end = effective_end + timedelta(days=1)

    # Build advisory list
    advisories = []
    for adv in pt.get('advisories', []):
        gs_start = parse_time_window(adv.get('start_time', ''), base_date)
        gs_end = parse_time_window(adv.get('end_time', ''), base_date)
        issued = parse_time_window(adv.get('issued_time', ''), base_date)

        # Overnight shift for advisory times
        if event_start and gs_start and gs_start < event_start - timedelta(hours=2):
            gs_start = gs_start + timedelta(days=1)
            if gs_end:
                gs_end = gs_end + timedelta(days=1)
            if issued:
                issued = issued + timedelta(days=1)

        if gs_start and gs_end and gs_end < gs_start:
            gs_end = gs_end + timedelta(days=1)

        advisories.append(GSAdvisory(
            advzy_number=adv.get('advzy_number', ''),
            advisory_type=adv.get('advisory_type', ''),
            adl_time=issued,
            gs_period_start=gs_start,
            gs_period_end=gs_end,
            dep_facilities=adv.get('dep_facilities', []) if isinstance(adv.get('dep_facilities'), list) else [],
            dep_facility_tier=adv.get('dep_facility_tier', ''),
            impacting_condition=adv.get('impacting_condition', ''),
            comments=adv.get('comments', ''),
        ))

    # Build dep_facilities from top-level or union from advisories
    dep_facilities = pt.get('dep_facilities', [])
    if isinstance(dep_facilities, str):
        dep_facilities = [f.strip() for f in dep_facilities.split(',') if f.strip()]

    return GSProgram(
        airport=airport,
        advisories=advisories,
        dep_facilities=dep_facilities,
        effective_start=effective_start,
        effective_end=effective_end,
        ended_by=pt.get('ended_by', ''),
        impacting_condition=pt.get('impacting_condition', ''),
        cnx_comments=pt.get('cnx_comments', ''),
    )


def build_reroute_program_from_api(pt: dict, base_date, event_start=None) -> RerouteProgram:
    """Convert a PHP REROUTE_PROGRAM entry to a Python RerouteProgram object.

    Args:
        pt: Dict from PHP parsed_tmis with type='REROUTE_PROGRAM'
        base_date: Date object for resolving HHMM times
        event_start: Full event start datetime for overnight shift detection

    Returns:
        RerouteProgram or None if essential data is missing
    """
    name = pt.get('name', '')

    # Parse effective times
    effective_start = parse_time_window(pt.get('effective_start', ''), base_date)
    effective_end = parse_time_window(pt.get('effective_end', ''), base_date)

    # Handle overnight events: if parsed times fall well before event start,
    # they belong to the next calendar day
    if event_start and effective_start and effective_start < event_start - timedelta(hours=2):
        effective_start = effective_start + timedelta(days=1)
        if effective_end:
            effective_end = effective_end + timedelta(days=1)

    if effective_start and effective_end and effective_end < effective_start:
        effective_end = effective_end + timedelta(days=1)

    # Build advisory list
    advisories = []
    for adv in pt.get('advisories', []):
        valid_start = parse_time_window(adv.get('start_time', ''), base_date)
        valid_end = parse_time_window(adv.get('end_time', ''), base_date)
        issued = parse_time_window(adv.get('issued_time', ''), base_date)

        # Overnight shift for advisory times
        if event_start and valid_start and valid_start < event_start - timedelta(hours=2):
            valid_start = valid_start + timedelta(days=1)
            if valid_end:
                valid_end = valid_end + timedelta(days=1)
            if issued:
                issued = issued + timedelta(days=1)

        if valid_start and valid_end and valid_end < valid_start:
            valid_end = valid_end + timedelta(days=1)

        # Parse routes from advisory
        adv_routes = []
        for r in adv.get('routes', []):
            route_str = r.get('route', '')
            # Extract required fixes from >fix< markers in route string
            required_fixes = re.findall(r'>([A-Z0-9]+)<', route_str)

            # Parse origins: can be string or list
            orig = r.get('orig', '')
            if isinstance(orig, list):
                origins = orig
            elif orig:
                origins = [o.strip() for o in orig.split(',') if o.strip()]
            else:
                origins = []

            adv_routes.append(RouteEntry(
                origins=origins,
                destination=r.get('dest', ''),
                route_string=route_str,
                required_fixes=required_fixes,
            ))

        advisories.append(RerouteAdvisory(
            advzy_number=adv.get('advzy_number', ''),
            advisory_type=adv.get('advisory_type', ''),
            route_type=adv.get('route_type', ''),
            action=adv.get('action', ''),
            adl_time=issued,
            valid_start=valid_start,
            valid_end=valid_end,
            routes=adv_routes,
            origins=adv.get('origins', []) if isinstance(adv.get('origins'), list) else [],
            destinations=adv.get('destinations', []) if isinstance(adv.get('destinations'), list) else [],
            facilities=adv.get('facilities', []) if isinstance(adv.get('facilities'), list) else [],
            exemptions=adv.get('exemptions', ''),
            comments=adv.get('comments', ''),
        ))

    # Build current_routes from top-level routes array
    current_routes = []
    for r in pt.get('routes', []):
        route_str = r.get('route', '')
        required_fixes = re.findall(r'>([A-Z0-9]+)<', route_str)

        orig = r.get('orig', '')
        if isinstance(orig, list):
            origins = orig
        elif orig:
            origins = [o.strip() for o in orig.split(',') if o.strip()]
        else:
            origins = []

        current_routes.append(RouteEntry(
            origins=origins,
            destination=r.get('dest', ''),
            route_string=route_str,
            required_fixes=required_fixes,
        ))

    return RerouteProgram(
        name=name,
        tmi_id=pt.get('tmi_id', ''),
        route_type=pt.get('route_type', ''),
        action=pt.get('action', ''),
        advisories=advisories,
        constrained_area=pt.get('constrained_area', ''),
        reason=pt.get('reason', ''),
        effective_start=effective_start,
        effective_end=effective_end,
        ended_by=pt.get('ended_by', ''),
        current_routes=current_routes,
    )


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

    # Use pre-parsed TMIs from API if available
    parsed_tmis = config.get('parsed_tmis', [])
    if parsed_tmis:
        tmis = []
        gs_programs = []
        reroute_programs = []

        for pt in parsed_tmis:
            tmi_type_str = pt.get('type', 'MIT').upper()

            # Handle program-level entries (GS_PROGRAM, REROUTE_PROGRAM) separately
            if tmi_type_str == 'GS_PROGRAM':
                prog = build_gs_program_from_api(pt, event_start.date(), event_start)
                if prog:
                    gs_programs.append(prog)
                continue
            elif tmi_type_str == 'REROUTE_PROGRAM':
                prog = build_reroute_program_from_api(pt, event_start.date(), event_start)
                if prog:
                    reroute_programs.append(prog)
                continue

            # Map type string to TMIType enum for individual TMI entries
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

            # Split multi-provider TMIs into separate per-boundary TMI objects
            # e.g., provider="ZME,ZKC" with requestor="ZFW" becomes two TMIs:
            #   ZFW:ZME and ZFW:ZKC — each analyzed at its own boundary
            raw_provider = pt.get('provider') or ''
            raw_requestor = pt.get('requestor') or ''

            if ',' in raw_provider and tmi_type not in (TMIType.GS, TMIType.REROUTE):
                provider_list = [p.strip() for p in raw_provider.split(',') if p.strip()]
            else:
                provider_list = [raw_provider]

            if ',' in raw_requestor and tmi_type not in (TMIType.GS, TMIType.REROUTE):
                requestor_list = [r.strip() for r in raw_requestor.split(',') if r.strip()]
            else:
                requestor_list = [raw_requestor]

            is_multi_split = len(provider_list) > 1 or len(requestor_list) > 1
            # Group ID links sub-TMIs from the same original multi-facility TMI
            # Include time to distinguish groups from different NTML lines for same fix
            base_tmi_id = f'{tmi_type.value}_{fix}_{dest}'
            time_tag = f"_{start_utc.strftime('%H%M')}" if (is_multi_split and start_utc) else ''
            group_id = f'{base_tmi_id}{time_tag}' if is_multi_split else ''
            original_facilities = f'{raw_requestor}:{raw_provider}' if is_multi_split else ''

            for single_provider in provider_list:
                for single_requestor in requestor_list:
                    # Build unique tmi_id that includes facility pair when present
                    # Multi-facility splits use _mf_ prefix to avoid colliding with
                    # standalone TMIs that happen to share the same fix/dest/provider
                    if single_provider or single_requestor:
                        fac_suffix = f"_{single_requestor}_{single_provider}" if (single_requestor and single_provider) else ""
                        mf_tag = f'_mf{time_tag}' if is_multi_split else ''
                        tmi_id = f'{base_tmi_id}{mf_tag}{fac_suffix}'
                    else:
                        tmi_id = base_tmi_id

                    tmi = TMI(
                        tmi_id=tmi_id,
                        tmi_type=tmi_type,
                        fix=fix if fix else None,
                        destinations=dest_list,
                        origins=origins,
                        value=pt.get('value', 0),
                        unit='nm' if tmi_type == TMIType.MIT else 'min',
                        provider=single_provider,
                        requestor=single_requestor,
                        start_utc=start_utc,
                        end_utc=end_utc,
                        issued_utc=issued_utc,
                        group_id=group_id,
                        original_facilities=original_facilities,
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

            if is_multi_split:
                logger.info(f"  Split multi-facility TMI into {len(provider_list) * len(requestor_list)} "
                           f"sub-TMIs: {original_facilities} (group={group_id})")

        event.tmis = tmis
        event.gs_programs = gs_programs
        event.reroute_programs = reroute_programs
        logger.info(f"Loaded {len(tmis)} pre-parsed TMIs, "
                    f"{len(gs_programs)} GS programs, "
                    f"{len(reroute_programs)} reroute programs from API")

        # Supplement: if parsed_tmis had no GS/reroute programs (e.g. saved before
        # program parsing was deployed), try extracting them from raw NTML text
        if not gs_programs and not reroute_programs:
            ntml_text = config.get('ntml_text', '')
            if ntml_text and ('GROUND STOP' in ntml_text or 'ROUTE RQD' in ntml_text
                              or 'ROUTE RMD' in ntml_text or 'FEA' in ntml_text):
                logger.info("No programs in parsed_tmis, extracting from ADVZY blocks")
                try:
                    gs_progs, reroute_progs = extract_programs_from_ntml(
                        ntml_text, event_start, event_end, destinations
                    )
                    if gs_progs:
                        event.gs_programs = gs_progs
                        logger.info(f"Supplemented {len(gs_progs)} GS programs from NTML")
                    if reroute_progs:
                        event.reroute_programs = reroute_progs
                        logger.info(f"Supplemented {len(reroute_progs)} reroute programs from NTML")
                except Exception as e:
                    logger.warning(f"Failed to supplement programs from NTML: {e}")
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
            event.gs_programs = parse_result.gs_programs
            event.reroute_programs = parse_result.reroute_programs
            logger.info(f"Parsed {len(parse_result.tmis)} TMIs, {len(parse_result.delays)} delays, "
                       f"{len(parse_result.gs_programs)} GS programs, "
                       f"{len(parse_result.reroute_programs)} reroute programs, "
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

    if not event.tmis and not event.gs_programs and not event.reroute_programs:
        return {"error": "No TMIs defined in configuration. Add NTML entries and save."}

    logger.info(f"Event: {event.name}, TMIs: {len(event.tmis)}, "
                f"GS programs: {len(event.gs_programs)}, "
                f"Reroute programs: {len(event.reroute_programs)}")

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
