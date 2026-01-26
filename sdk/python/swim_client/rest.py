"""
SWIM REST API Client

Provides synchronous and asynchronous access to SWIM REST endpoints.
"""

import json
import logging
from datetime import datetime
from typing import Any, Dict, List, Optional, Union
from urllib.parse import urlencode, urljoin

try:
    import requests
except ImportError:
    requests = None

try:
    import aiohttp
except ImportError:
    aiohttp = None

from .events import FlightEvent, Position, TMIEvent

logger = logging.getLogger('swim_client.rest')


class SWIMRestClient:
    """
    VATSWIM REST API Client

    Provides access to SWIM REST endpoints for querying flight data,
    positions, TMI programs, and ingesting data.
    
    Example (sync):
        client = SWIMRestClient('your-api-key')
        
        # Get flights to JFK
        flights = client.get_flights(dest_icao='KJFK')
        for f in flights:
            print(f"{f['identity']['callsign']} -> {f['flight_plan']['destination']}")
        
        # Get positions as GeoJSON
        positions = client.get_positions(artcc='ZNY')
        print(f"Got {len(positions['features'])} aircraft")
    
    Example (async):
        async with SWIMRestClient('your-api-key') as client:
            flights = await client.get_flights_async(dest_icao='KJFK')
    """
    
    DEFAULT_BASE_URL = 'https://perti.vatcscc.org/api/swim/v1'
    
    def __init__(
        self,
        api_key: str,
        base_url: Optional[str] = None,
        timeout: float = 30.0,
        debug: bool = False,
    ):
        """
        Initialize REST client.
        
        Args:
            api_key: API key for authentication
            base_url: API base URL (default: https://perti.vatcscc.org/api/swim/v1)
            timeout: Request timeout in seconds
            debug: Enable debug logging
        """
        self.api_key = api_key
        self.base_url = (base_url or self.DEFAULT_BASE_URL).rstrip('/')
        self.timeout = timeout
        
        # Session for connection pooling
        self._session: Optional[requests.Session] = None
        self._async_session: Optional[aiohttp.ClientSession] = None
        
        if debug:
            logging.basicConfig(level=logging.DEBUG)
            logger.setLevel(logging.DEBUG)
    
    # =========================================================================
    # Context Managers
    # =========================================================================
    
    def __enter__(self) -> 'SWIMRestClient':
        """Sync context manager entry."""
        self._ensure_sync_session()
        return self
    
    def __exit__(self, exc_type, exc_val, exc_tb) -> None:
        """Sync context manager exit."""
        self.close()
    
    async def __aenter__(self) -> 'SWIMRestClient':
        """Async context manager entry."""
        await self._ensure_async_session()
        return self
    
    async def __aexit__(self, exc_type, exc_val, exc_tb) -> None:
        """Async context manager exit."""
        await self.close_async()
    
    def close(self) -> None:
        """Close sync session."""
        if self._session:
            self._session.close()
            self._session = None
    
    async def close_async(self) -> None:
        """Close async session."""
        if self._async_session:
            await self._async_session.close()
            self._async_session = None
    
    # =========================================================================
    # Flight Endpoints
    # =========================================================================
    
    def get_flights(
        self,
        status: str = 'active',
        dept_icao: Optional[Union[str, List[str]]] = None,
        dest_icao: Optional[Union[str, List[str]]] = None,
        artcc: Optional[Union[str, List[str]]] = None,
        callsign: Optional[str] = None,
        tmi_controlled: Optional[bool] = None,
        phase: Optional[Union[str, List[str]]] = None,
        format: str = 'fixm',
        page: int = 1,
        per_page: int = 100,
    ) -> Dict[str, Any]:
        """
        Get list of flights.
        
        Args:
            status: 'active', 'completed', or 'all'
            dept_icao: Departure airport(s) filter
            dest_icao: Destination airport(s) filter
            artcc: ARTCC(s) filter
            callsign: Callsign pattern (supports * wildcard)
            tmi_controlled: Filter TMI-controlled flights
            phase: Flight phase(s) filter
            format: 'fixm' (default) or 'legacy' field naming
            page: Page number
            per_page: Results per page (max 1000)
        
        Returns:
            Dict with 'data' (list of flights) and 'pagination' info
        """
        params = self._build_flight_params(
            status, dept_icao, dest_icao, artcc, callsign,
            tmi_controlled, phase, format, page, per_page
        )
        return self._get('/flights', params)
    
    async def get_flights_async(
        self,
        status: str = 'active',
        dept_icao: Optional[Union[str, List[str]]] = None,
        dest_icao: Optional[Union[str, List[str]]] = None,
        artcc: Optional[Union[str, List[str]]] = None,
        callsign: Optional[str] = None,
        tmi_controlled: Optional[bool] = None,
        phase: Optional[Union[str, List[str]]] = None,
        format: str = 'fixm',
        page: int = 1,
        per_page: int = 100,
    ) -> Dict[str, Any]:
        """Async version of get_flights."""
        params = self._build_flight_params(
            status, dept_icao, dest_icao, artcc, callsign,
            tmi_controlled, phase, format, page, per_page
        )
        return await self._get_async('/flights', params)
    
    def get_flight(
        self,
        gufi: Optional[str] = None,
        flight_key: Optional[str] = None,
        format: str = 'fixm',
    ) -> Dict[str, Any]:
        """
        Get single flight by GUFI or flight_key.
        
        Args:
            gufi: Globally Unique Flight Identifier
            flight_key: ADL flight key
            format: 'fixm' (default) or 'legacy' field naming
        
        Returns:
            Flight data dict
        """
        if not gufi and not flight_key:
            raise ValueError("Must provide either gufi or flight_key")
        
        params = {'format': format}
        if gufi:
            params['gufi'] = gufi
        if flight_key:
            params['flight_key'] = flight_key
        
        return self._get('/flight', params)
    
    async def get_flight_async(
        self,
        gufi: Optional[str] = None,
        flight_key: Optional[str] = None,
        format: str = 'fixm',
    ) -> Dict[str, Any]:
        """Async version of get_flight."""
        if not gufi and not flight_key:
            raise ValueError("Must provide either gufi or flight_key")
        
        params = {'format': format}
        if gufi:
            params['gufi'] = gufi
        if flight_key:
            params['flight_key'] = flight_key
        
        return await self._get_async('/flight', params)
    
    # =========================================================================
    # Position Endpoints
    # =========================================================================
    
    def get_positions(
        self,
        dept_icao: Optional[Union[str, List[str]]] = None,
        dest_icao: Optional[Union[str, List[str]]] = None,
        artcc: Optional[Union[str, List[str]]] = None,
        bounds: Optional[str] = None,
        tmi_controlled: Optional[bool] = None,
        phase: Optional[Union[str, List[str]]] = None,
        include_route: bool = False,
    ) -> Dict[str, Any]:
        """
        Get flight positions as GeoJSON FeatureCollection.
        
        Args:
            dept_icao: Departure airport(s) filter
            dest_icao: Destination airport(s) filter
            artcc: ARTCC(s) filter
            bounds: Bounding box 'minLon,minLat,maxLon,maxLat'
            tmi_controlled: Filter TMI-controlled flights
            phase: Flight phase(s) filter
            include_route: Include route string in properties
        
        Returns:
            GeoJSON FeatureCollection
        """
        params = {}
        if dept_icao:
            params['dept_icao'] = self._list_param(dept_icao)
        if dest_icao:
            params['dest_icao'] = self._list_param(dest_icao)
        if artcc:
            params['artcc'] = self._list_param(artcc)
        if bounds:
            params['bounds'] = bounds
        if tmi_controlled is not None:
            params['tmi_controlled'] = str(tmi_controlled).lower()
        if phase:
            params['phase'] = self._list_param(phase)
        if include_route:
            params['include_route'] = 'true'
        
        return self._get('/positions', params)
    
    async def get_positions_async(
        self,
        dept_icao: Optional[Union[str, List[str]]] = None,
        dest_icao: Optional[Union[str, List[str]]] = None,
        artcc: Optional[Union[str, List[str]]] = None,
        bounds: Optional[str] = None,
        tmi_controlled: Optional[bool] = None,
        phase: Optional[Union[str, List[str]]] = None,
        include_route: bool = False,
    ) -> Dict[str, Any]:
        """Async version of get_positions."""
        params = {}
        if dept_icao:
            params['dept_icao'] = self._list_param(dept_icao)
        if dest_icao:
            params['dest_icao'] = self._list_param(dest_icao)
        if artcc:
            params['artcc'] = self._list_param(artcc)
        if bounds:
            params['bounds'] = bounds
        if tmi_controlled is not None:
            params['tmi_controlled'] = str(tmi_controlled).lower()
        if phase:
            params['phase'] = self._list_param(phase)
        if include_route:
            params['include_route'] = 'true'
        
        return await self._get_async('/positions', params)
    
    # =========================================================================
    # TMI Endpoints
    # =========================================================================
    
    def get_tmi_programs(
        self,
        type: str = 'all',
        airport: Optional[str] = None,
        artcc: Optional[str] = None,
        include_history: bool = False,
    ) -> Dict[str, Any]:
        """
        Get active Traffic Management Initiatives.
        
        Args:
            type: 'all', 'gs' (ground stops), or 'gdp' (ground delay programs)
            airport: Airport ICAO filter
            artcc: ARTCC filter
            include_history: Include recently ended programs
        
        Returns:
            Dict with 'ground_stops', 'gdp_programs', and 'summary'
        """
        params = {'type': type}
        if airport:
            params['airport'] = airport.upper()
        if artcc:
            params['artcc'] = artcc.upper()
        if include_history:
            params['include_history'] = 'true'
        
        return self._get('/tmi/programs', params)
    
    async def get_tmi_programs_async(
        self,
        type: str = 'all',
        airport: Optional[str] = None,
        artcc: Optional[str] = None,
        include_history: bool = False,
    ) -> Dict[str, Any]:
        """Async version of get_tmi_programs."""
        params = {'type': type}
        if airport:
            params['airport'] = airport.upper()
        if artcc:
            params['artcc'] = artcc.upper()
        if include_history:
            params['include_history'] = 'true'
        
        return await self._get_async('/tmi/programs', params)
    
    def get_tmi_controlled(
        self,
        airport: Optional[str] = None,
        program_type: Optional[str] = None,
    ) -> Dict[str, Any]:
        """
        Get flights under TMI control.
        
        Args:
            airport: Airport filter
            program_type: 'GS', 'GDP', or None for all
        
        Returns:
            Dict with 'flights' list
        """
        params = {}
        if airport:
            params['airport'] = airport.upper()
        if program_type:
            params['program_type'] = program_type.upper()
        
        return self._get('/tmi/controlled', params)
    
    async def get_tmi_controlled_async(
        self,
        airport: Optional[str] = None,
        program_type: Optional[str] = None,
    ) -> Dict[str, Any]:
        """Async version of get_tmi_controlled."""
        params = {}
        if airport:
            params['airport'] = airport.upper()
        if program_type:
            params['program_type'] = program_type.upper()
        
        return await self._get_async('/tmi/controlled', params)
    
    # =========================================================================
    # Ingest Endpoints (Write Access Required)
    # =========================================================================
    
    def ingest_flights(self, flights: List[Dict[str, Any]]) -> Dict[str, Any]:
        """
        Ingest flight data (requires write access).
        
        Args:
            flights: List of flight records with fields:
                - callsign (required)
                - dept_icao (required)
                - dest_icao (required)
                - cid, aircraft_type, route, phase, is_active
                - latitude, longitude, altitude_ft, heading_deg, groundspeed_kts
                - vertical_rate_fpm, out_utc, off_utc, on_utc, in_utc, eta_utc
                - tmi (object with ctl_type, slot_time_utc, delay_minutes)
        
        Returns:
            Dict with processed/created/updated/errors counts
        
        Raises:
            SWIMAuthError: If API key lacks write permission
        """
        if len(flights) > 500:
            raise ValueError("Maximum batch size is 500 flights")
        
        return self._post('/ingest/adl', {'flights': flights})
    
    async def ingest_flights_async(self, flights: List[Dict[str, Any]]) -> Dict[str, Any]:
        """Async version of ingest_flights."""
        if len(flights) > 500:
            raise ValueError("Maximum batch size is 500 flights")
        
        return await self._post_async('/ingest/adl', {'flights': flights})
    
    def ingest_tracks(self, tracks: List[Dict[str, Any]]) -> Dict[str, Any]:
        """
        Ingest track/position data (requires write access).
        
        Args:
            tracks: List of track records with fields:
                - callsign (required)
                - latitude (required)
                - longitude (required)
                - altitude_ft, ground_speed_kts, heading_deg
                - vertical_rate_fpm, squawk, track_source, timestamp
        
        Returns:
            Dict with processed/updated/not_found/errors counts
        
        Raises:
            SWIMAuthError: If API key lacks write permission
        """
        if len(tracks) > 1000:
            raise ValueError("Maximum batch size is 1000 tracks")
        
        return self._post('/ingest/track', {'tracks': tracks})
    
    async def ingest_tracks_async(self, tracks: List[Dict[str, Any]]) -> Dict[str, Any]:
        """Async version of ingest_tracks."""
        if len(tracks) > 1000:
            raise ValueError("Maximum batch size is 1000 tracks")
        
        return await self._post_async('/ingest/track', {'tracks': tracks})
    
    # =========================================================================
    # API Info
    # =========================================================================
    
    def get_api_info(self) -> Dict[str, Any]:
        """Get API information and available endpoints."""
        return self._get('', {})
    
    async def get_api_info_async(self) -> Dict[str, Any]:
        """Async version of get_api_info."""
        return await self._get_async('', {})
    
    # =========================================================================
    # Convenience Methods
    # =========================================================================
    
    def get_arrivals(
        self,
        airport: str,
        status: str = 'active',
        page: int = 1,
        per_page: int = 100,
    ) -> Dict[str, Any]:
        """Get arrivals to an airport."""
        return self.get_flights(
            dest_icao=airport,
            status=status,
            page=page,
            per_page=per_page,
        )
    
    def get_departures(
        self,
        airport: str,
        status: str = 'active',
        page: int = 1,
        per_page: int = 100,
    ) -> Dict[str, Any]:
        """Get departures from an airport."""
        return self.get_flights(
            dept_icao=airport,
            status=status,
            page=page,
            per_page=per_page,
        )
    
    def get_artcc_traffic(
        self,
        artcc: str,
        status: str = 'active',
        page: int = 1,
        per_page: int = 100,
    ) -> Dict[str, Any]:
        """Get all traffic in an ARTCC."""
        return self.get_flights(
            artcc=artcc,
            status=status,
            page=page,
            per_page=per_page,
        )
    
    def iter_all_flights(
        self,
        status: str = 'active',
        per_page: int = 100,
        **kwargs,
    ):
        """
        Iterator that yields all flights across all pages.
        
        Args:
            status: Flight status filter
            per_page: Page size
            **kwargs: Additional filters passed to get_flights
        
        Yields:
            Individual flight dicts
        """
        page = 1
        while True:
            result = self.get_flights(
                status=status,
                page=page,
                per_page=per_page,
                **kwargs,
            )
            
            flights = result.get('data', [])
            for flight in flights:
                yield flight
            
            pagination = result.get('pagination', {})
            if not pagination.get('has_more', False):
                break
            
            page += 1
    
    # =========================================================================
    # Internal Methods
    # =========================================================================
    
    def _build_flight_params(
        self, status, dept_icao, dest_icao, artcc, callsign,
        tmi_controlled, phase, format, page, per_page
    ) -> Dict[str, str]:
        """Build query params for flight endpoints."""
        params = {
            'status': status,
            'format': format,
            'page': str(page),
            'per_page': str(per_page),
        }
        
        if dept_icao:
            params['dept_icao'] = self._list_param(dept_icao)
        if dest_icao:
            params['dest_icao'] = self._list_param(dest_icao)
        if artcc:
            params['artcc'] = self._list_param(artcc)
        if callsign:
            params['callsign'] = callsign
        if tmi_controlled is not None:
            params['tmi_controlled'] = str(tmi_controlled).lower()
        if phase:
            params['phase'] = self._list_param(phase)
        
        return params
    
    def _list_param(self, value: Union[str, List[str]]) -> str:
        """Convert list to comma-separated string."""
        if isinstance(value, list):
            return ','.join(v.upper() for v in value)
        return value.upper()
    
    def _headers(self) -> Dict[str, str]:
        """Build request headers."""
        return {
            'Authorization': f'Bearer {self.api_key}',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        }
    
    def _ensure_sync_session(self) -> None:
        """Ensure sync session exists."""
        if requests is None:
            raise ImportError("requests library required for sync client. Install with: pip install requests")
        
        if self._session is None:
            self._session = requests.Session()
            self._session.headers.update(self._headers())
    
    async def _ensure_async_session(self) -> None:
        """Ensure async session exists."""
        if aiohttp is None:
            raise ImportError("aiohttp library required for async client. Install with: pip install aiohttp")
        
        if self._async_session is None:
            self._async_session = aiohttp.ClientSession(
                headers=self._headers(),
                timeout=aiohttp.ClientTimeout(total=self.timeout),
            )
    
    def _get(self, endpoint: str, params: Dict[str, str]) -> Dict[str, Any]:
        """Sync GET request."""
        self._ensure_sync_session()
        
        url = f"{self.base_url}{endpoint}"
        logger.debug(f"GET {url} params={params}")
        
        response = self._session.get(url, params=params, timeout=self.timeout)
        return self._handle_response(response)
    
    async def _get_async(self, endpoint: str, params: Dict[str, str]) -> Dict[str, Any]:
        """Async GET request."""
        await self._ensure_async_session()
        
        url = f"{self.base_url}{endpoint}"
        logger.debug(f"GET {url} params={params}")
        
        async with self._async_session.get(url, params=params) as response:
            return await self._handle_response_async(response)
    
    def _post(self, endpoint: str, data: Dict[str, Any]) -> Dict[str, Any]:
        """Sync POST request."""
        self._ensure_sync_session()
        
        url = f"{self.base_url}{endpoint}"
        logger.debug(f"POST {url}")
        
        response = self._session.post(url, json=data, timeout=self.timeout)
        return self._handle_response(response)
    
    async def _post_async(self, endpoint: str, data: Dict[str, Any]) -> Dict[str, Any]:
        """Async POST request."""
        await self._ensure_async_session()
        
        url = f"{self.base_url}{endpoint}"
        logger.debug(f"POST {url}")
        
        async with self._async_session.post(url, json=data) as response:
            return await self._handle_response_async(response)
    
    def _handle_response(self, response: 'requests.Response') -> Dict[str, Any]:
        """Handle sync response."""
        if response.status_code == 401:
            raise SWIMAuthError("Invalid or expired API key")
        elif response.status_code == 403:
            raise SWIMAuthError("Insufficient permissions for this operation")
        elif response.status_code == 429:
            raise SWIMRateLimitError("Rate limit exceeded")
        elif response.status_code >= 400:
            raise SWIMAPIError(f"API error {response.status_code}: {response.text}")
        
        return response.json()
    
    async def _handle_response_async(self, response: 'aiohttp.ClientResponse') -> Dict[str, Any]:
        """Handle async response."""
        if response.status == 401:
            raise SWIMAuthError("Invalid or expired API key")
        elif response.status == 403:
            raise SWIMAuthError("Insufficient permissions for this operation")
        elif response.status == 429:
            raise SWIMRateLimitError("Rate limit exceeded")
        elif response.status >= 400:
            text = await response.text()
            raise SWIMAPIError(f"API error {response.status}: {text}")
        
        return await response.json()


# =============================================================================
# Exceptions
# =============================================================================

class SWIMAPIError(Exception):
    """Base exception for SWIM API errors."""
    pass


class SWIMAuthError(SWIMAPIError):
    """Authentication or authorization error."""
    pass


class SWIMRateLimitError(SWIMAPIError):
    """Rate limit exceeded error."""
    pass
