"""
VATSWIM xPilot Plugin

xPilot integration for VATSWIM (VATSIM System Wide Information Management).
Syncs flight data to VATSWIM when connected to VATSIM via xPilot.

@package VATSWIM
@subpackage xPilot Integration
@version 1.0.0
"""

import os
import json
import logging
import requests
from datetime import datetime
from typing import Optional, Dict, Any
from dataclasses import dataclass

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='[VATSWIM] %(levelname)s: %(message)s'
)
logger = logging.getLogger('vatswim')


@dataclass
class VatswimConfig:
    """VATSWIM plugin configuration."""
    enabled: bool = True
    api_key: str = ""
    api_base_url: str = "https://perti.vatcscc.org/api/swim/v1"
    import_simbrief: bool = True
    simbrief_username: str = ""
    enable_tracking: bool = True
    enable_oooi: bool = True
    track_interval_ms: int = 1000
    verbose: bool = False

    @classmethod
    def load(cls, config_path: Optional[str] = None) -> 'VatswimConfig':
        """Load configuration from file."""
        if config_path is None:
            config_dir = os.path.expanduser("~/.xpilot/plugins/vatswim")
            config_path = os.path.join(config_dir, "config.json")

        if os.path.exists(config_path):
            try:
                with open(config_path, 'r') as f:
                    data = json.load(f)
                return cls(**data)
            except Exception as e:
                logger.warning(f"Failed to load config: {e}")

        # Return defaults
        config = cls()
        config.save(config_path)
        return config

    def save(self, config_path: Optional[str] = None):
        """Save configuration to file."""
        if config_path is None:
            config_dir = os.path.expanduser("~/.xpilot/plugins/vatswim")
            config_path = os.path.join(config_dir, "config.json")

        os.makedirs(os.path.dirname(config_path), exist_ok=True)
        with open(config_path, 'w') as f:
            json.dump(self.__dict__, f, indent=2)


class SimbriefImporter:
    """Imports OFP data from SimBrief."""

    SIMBRIEF_API_URL = "https://www.simbrief.com/api/xml.fetcher.php"

    def __init__(self):
        self.session = requests.Session()
        self.session.headers['User-Agent'] = 'xPilot-VATSWIM/1.0.0'

    def fetch_ofp(self, username: str) -> Optional[Dict[str, Any]]:
        """Fetch the latest OFP for a SimBrief user."""
        try:
            url = f"{self.SIMBRIEF_API_URL}?username={username}&json=1"
            response = self.session.get(url, timeout=30)
            response.raise_for_status()

            data = response.json()
            return self._parse_ofp(data)
        except Exception as e:
            logger.error(f"SimBrief fetch failed: {e}")
            return None

    def _parse_ofp(self, data: Dict) -> Optional[Dict[str, Any]]:
        """Parse SimBrief JSON response."""
        try:
            origin = data.get('origin', {})
            destination = data.get('destination', {})
            general = data.get('general', {})
            times = data.get('times', {})
            fuel = data.get('fuel', {})
            aircraft = data.get('aircraft', {})

            return {
                'origin': origin.get('icao_code', ''),
                'destination': destination.get('icao_code', ''),
                'aircraft_icao': aircraft.get('icaocode', ''),
                'route': general.get('route', ''),
                'cruise_altitude': int(general.get('initial_altitude', 0)),
                'cruise_mach': float(general.get('cruise_mach', 0)),
                'cost_index': int(general.get('costindex', 0)),
                'fuel_plan_ramp': float(fuel.get('plan_ramp', 0)),
                'est_time_enroute': int(times.get('est_time_enroute', 0)),
                'scheduled_departure': self._parse_timestamp(times.get('sched_out')),
                'scheduled_arrival': self._parse_timestamp(times.get('sched_in'))
            }
        except Exception as e:
            logger.error(f"OFP parse failed: {e}")
            return None

    def _parse_timestamp(self, value) -> Optional[str]:
        """Parse Unix timestamp to ISO8601."""
        if not value:
            return None
        try:
            dt = datetime.utcfromtimestamp(int(value))
            return dt.isoformat() + 'Z'
        except:
            return None


class VatswimClient:
    """VATSWIM API client."""

    def __init__(self, config: VatswimConfig):
        self.config = config
        self.session = requests.Session()
        self.session.headers.update({
            'Authorization': f'Bearer {config.api_key}',
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-SWIM-Source': 'xpilot',
            'User-Agent': 'xPilot-VATSWIM/1.0.0'
        })

    def submit_flight(self, data: Dict[str, Any]) -> bool:
        """Submit flight data to VATSWIM."""
        return self._post('/ingest/adl', data)

    def submit_track(self, data: Dict[str, Any]) -> bool:
        """Submit track position to VATSWIM."""
        return self._post('/ingest/track', data)

    def _post(self, endpoint: str, data: Dict[str, Any]) -> bool:
        """Make POST request to VATSWIM API."""
        if not self.config.enabled or not self.config.api_key:
            return False

        try:
            url = self.config.api_base_url.rstrip('/') + endpoint
            response = self.session.post(url, json=data, timeout=30)

            if self.config.verbose:
                logger.debug(f"POST {endpoint}: {response.status_code}")

            return response.status_code >= 200 and response.status_code < 300
        except Exception as e:
            logger.error(f"API request failed: {e}")
            return False


class VatswimPlugin:
    """
    VATSWIM integration plugin for xPilot.
    """

    VERSION = "1.0.0"

    def __init__(self):
        self.config = VatswimConfig.load()
        self.client = VatswimClient(self.config)
        self.simbrief = SimbriefImporter()

        self.callsign: Optional[str] = None
        self.departure: Optional[str] = None
        self.destination: Optional[str] = None
        self.cid: Optional[int] = None
        self.is_connected: bool = False

        logger.info(f"VATSWIM xPilot Plugin v{self.VERSION} initialized")

    def on_connected(self, callsign: str, cid: int, real_name: str):
        """Called when xPilot connects to VATSIM."""
        self.callsign = callsign
        self.cid = cid
        self.is_connected = True

        logger.info(f"Connected to VATSIM as {callsign} (CID: {cid})")

        # Import SimBrief data if configured
        if self.config.import_simbrief and self.config.simbrief_username:
            self._import_simbrief()

    def on_disconnected(self):
        """Called when xPilot disconnects from VATSIM."""
        self.is_connected = False
        logger.info("Disconnected from VATSIM")

    def on_flight_plan_filed(self, departure: str, destination: str,
                             aircraft: str, route: str, altitude: int,
                             remarks: str):
        """Called when a flight plan is filed."""
        self.departure = departure
        self.destination = destination

        data = {
            'callsign': self.callsign,
            'cid': self.cid,
            'dept_icao': departure,
            'dest_icao': destination,
            'aircraft_type': aircraft,
            'fp_route': route,
            'fp_altitude_ft': altitude,
            'fp_remarks': remarks,
            'source': 'xpilot'
        }

        if self.client.submit_flight(data):
            logger.info(f"Flight plan synced: {departure} -> {destination}")
        else:
            logger.warning("Flight plan sync failed")

    def on_position_update(self, lat: float, lon: float, altitude: float,
                           groundspeed: float, heading: float,
                           vertical_rate: float, on_ground: bool):
        """Called on position update (ACARS-style)."""
        if not self.config.enable_tracking or not self.is_connected:
            return

        data = {
            'callsign': self.callsign,
            'latitude': lat,
            'longitude': lon,
            'altitude_ft': int(altitude),
            'groundspeed_kts': int(groundspeed),
            'heading_deg': int(heading),
            'vertical_rate_fpm': int(vertical_rate),
            'on_ground': on_ground,
            'source': 'xpilot'
        }

        self.client.submit_track(data)

    def _import_simbrief(self):
        """Import and sync SimBrief OFP."""
        ofp = self.simbrief.fetch_ofp(self.config.simbrief_username)

        if not ofp:
            return

        self.departure = ofp['origin']
        self.destination = ofp['destination']

        data = {
            'callsign': self.callsign,
            'cid': self.cid,
            'dept_icao': ofp['origin'],
            'dest_icao': ofp['destination'],
            'aircraft_type': ofp['aircraft_icao'],
            'fp_route': ofp['route'],
            'fp_altitude_ft': ofp['cruise_altitude'],
            'cruise_mach': ofp['cruise_mach'],
            'block_fuel_lbs': ofp['fuel_plan_ramp'],
            'cost_index': ofp['cost_index'],
            'lgtd_utc': ofp['scheduled_departure'],
            'lgta_utc': ofp['scheduled_arrival'],
            'source': 'simbrief'
        }

        if self.client.submit_flight(data):
            logger.info(f"SimBrief OFP synced: {ofp['origin']} -> {ofp['destination']}")


# Plugin instance (for xPilot plugin loader)
_plugin: Optional[VatswimPlugin] = None


def init():
    """Initialize the plugin (called by xPilot)."""
    global _plugin
    _plugin = VatswimPlugin()
    return True


def shutdown():
    """Shutdown the plugin (called by xPilot)."""
    global _plugin
    _plugin = None


def on_connected(callsign: str, cid: int, real_name: str):
    """xPilot callback: Connected to VATSIM."""
    if _plugin:
        _plugin.on_connected(callsign, cid, real_name)


def on_disconnected():
    """xPilot callback: Disconnected from VATSIM."""
    if _plugin:
        _plugin.on_disconnected()


def on_flight_plan_filed(departure: str, destination: str, aircraft: str,
                         route: str, altitude: int, remarks: str):
    """xPilot callback: Flight plan filed."""
    if _plugin:
        _plugin.on_flight_plan_filed(departure, destination, aircraft,
                                     route, altitude, remarks)


def on_position_update(lat: float, lon: float, altitude: float,
                       groundspeed: float, heading: float,
                       vertical_rate: float, on_ground: bool):
    """xPilot callback: Position update."""
    if _plugin:
        _plugin.on_position_update(lat, lon, altitude, groundspeed,
                                   heading, vertical_rate, on_ground)
