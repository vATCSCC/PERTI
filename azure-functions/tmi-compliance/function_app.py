"""
TMI Compliance Analysis Azure Function
======================================

HTTP trigger that runs TMI compliance analysis for PERTI events.

Usage:
    GET/POST /api/tmi_compliance?plan_id={id}

Parameters:
    plan_id (required): PERTI plan ID to analyze

The function:
1. Fetches TMI configuration from PERTI API
2. Parses NTML text into TMI objects
3. Queries VATSIM_ADL for flight/trajectory data
4. Calculates MIT, MINIT, and Ground Stop compliance
5. Returns JSON results

Author: VATSIM PERTI
"""

import azure.functions as func
import json
import logging
import os
import requests
from datetime import datetime, timedelta

from core.models import EventConfig
from core.ntml_parser import parse_ntml_to_tmis
from core.analyzer import TMIComplianceAnalyzer

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = func.FunctionApp(http_auth_level=func.AuthLevel.FUNCTION)


@app.route(route="tmi_compliance")
def tmi_compliance_http(req: func.HttpRequest) -> func.HttpResponse:
    """
    HTTP Trigger for TMI Compliance Analysis

    Parameters:
        plan_id (required): PERTI plan ID

    Returns:
        JSON with compliance results
    """
    logger.info('TMI Compliance Analysis triggered')

    try:
        # Get plan_id from query params or body
        plan_id = req.params.get('plan_id')
        if not plan_id:
            try:
                req_body = req.get_json()
                plan_id = req_body.get('plan_id')
            except ValueError:
                pass

        if not plan_id:
            return func.HttpResponse(
                json.dumps({"error": "Missing plan_id parameter"}),
                status_code=400,
                mimetype="application/json"
            )

        plan_id = int(plan_id)
        logger.info(f"Processing plan_id: {plan_id}")

        # Load configuration from PERTI API
        config = load_config_from_api(plan_id)
        if not config:
            return func.HttpResponse(
                json.dumps({"error": f"No TMI config found for plan {plan_id}. Save configuration in PERTI first."}),
                status_code=404,
                mimetype="application/json"
            )

        # Build event config
        event = build_event_config(config, plan_id)

        if not event.tmis:
            return func.HttpResponse(
                json.dumps({"error": "No TMIs defined in configuration. Add NTML entries and save."}),
                status_code=400,
                mimetype="application/json"
            )

        logger.info(f"Event: {event.name}, TMIs: {len(event.tmis)}")

        # Run analysis
        analyzer = TMIComplianceAnalyzer(event)
        results = analyzer.analyze()

        # Add plan_id and debug info to results
        results['plan_id'] = plan_id
        results['debug'] = {
            'tmis_parsed': len(event.tmis),
            'destinations': event.destinations,
            'tmi_types': [tmi.tmi_type.value for tmi in event.tmis[:10]],
            'tmi_fixes': [tmi.fix for tmi in event.tmis if tmi.fix],
            'fix_coords_loaded': list(analyzer.fix_coords.keys()) if hasattr(analyzer, 'fix_coords') else []
        }

        logger.info("Analysis complete")

        return func.HttpResponse(
            json.dumps(results, default=str),
            mimetype="application/json"
        )

    except Exception as e:
        logger.exception("Analysis failed")
        return func.HttpResponse(
            json.dumps({"error": str(e)}),
            status_code=500,
            mimetype="application/json"
        )


def load_config_from_api(plan_id: int) -> dict:
    """
    Load TMI configuration from PERTI API

    The config is saved by the frontend when user clicks "Save Configuration"
    in the TMI Compliance tab.
    """
    api_url = os.environ.get('PERTI_API_URL', 'https://perti.vatcscc.org/api')
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
    logger.info(f"NTML text length: {len(ntml_text)}, first 200 chars: {ntml_text[:200] if ntml_text else 'empty'}")
    logger.info(f"Destinations: {destinations}, Event: {event_start} to {event_end}")

    if ntml_text:
        tmis = parse_ntml_to_tmis(ntml_text, event_start, event_end, destinations)
        event.tmis = tmis
        logger.info(f"Parsed {len(tmis)} TMIs from NTML")
        for tmi in tmis[:5]:  # Log first 5 TMIs for debug
            logger.info(f"  TMI: {tmi.tmi_type.value} {tmi.fix} {tmi.value} {tmi.start_utc}-{tmi.end_utc}")
    else:
        logger.warning("NTML text is empty!")

    return event
