"""
TMI Compliance Analyzer - Facility Hierarchy Lookups
=====================================================

Provides authoritative airport→facility hierarchy lookups.

Data Sources:
- VATSIM_REF.area_centers: List of ARTCCs, TRACONs, ATCTs
- VATSIM_GIS.airports: Airport → parent_artcc/parent_tracon mappings

Note: The geographic containment in airports table may not match the
actual controlling facility hierarchy. For example:
- PHL is within ZDC's lateral boundaries
- But PHL is controlled by ZNY (N90 TRACON → ZNY ARTCC)

This module handles:
1. Loading facility lists from database
2. Providing airport → controlling facility lookups
3. Facility type detection (ARTCC vs TRACON vs sector)
4. Hierarchy overrides for known exceptions
"""

import logging
from typing import Dict, List, Optional, Set, Tuple
from dataclasses import dataclass, field
from functools import lru_cache

logger = logging.getLogger(__name__)


@dataclass
class FacilityInfo:
    """Information about a facility (ARTCC, TRACON, etc.)"""
    code: str
    facility_type: str  # ARTCC, TRACON, ATCT, FSS
    name: str = ''
    parent_artcc: str = ''
    lat: float = 0.0
    lon: float = 0.0


@dataclass
class AirportFacilityInfo:
    """Airport's controlling facilities"""
    icao_code: str
    controlling_artcc: str      # Authoritative ARTCC (e.g., ZNY for PHL)
    controlling_tracon: str     # TRACON if applicable (e.g., N90 for JFK)
    geographic_artcc: str       # ARTCC from geographic containment
    geographic_tracon: str      # TRACON from geographic containment


# Known hierarchy overrides where geographic containment differs from control
# Format: airport_icao -> (controlling_artcc, controlling_tracon)
AIRPORT_FACILITY_OVERRIDES: Dict[str, Tuple[str, str]] = {
    # Philadelphia area - geographically in ZDC but controlled by ZNY
    'KPHL': ('ZNY', 'PHL'),
    'KABE': ('ZNY', ''),
    'KRDG': ('ZNY', ''),
    'KILG': ('ZNY', 'PHL'),
    'KMIV': ('ZNY', ''),
    'KACY': ('ZNY', ''),

    # Atlantic City/Wildwood - ZNY controls but near ZDC boundary
    'KWWD': ('ZNY', ''),

    # Add more overrides as identified...
}


class FacilityHierarchyCache:
    """
    Cache for facility hierarchy data loaded from databases.

    Usage:
        cache = FacilityHierarchyCache()
        cache.load_from_databases(adl_conn, gis_conn)

        # Check if code is a TRACON
        if cache.is_tracon('N90'):
            ...

        # Get airport's controlling ARTCC
        artcc = cache.get_airport_artcc('KPHL')  # Returns 'ZNY'
    """

    def __init__(self):
        self.facilities: Dict[str, FacilityInfo] = {}
        self.tracons: Set[str] = set()
        self.artccs: Set[str] = set()
        self.sectors: Set[str] = set()
        self.airports: Dict[str, AirportFacilityInfo] = {}
        self._loaded = False

    def load_from_databases(self, adl_conn=None, gis_conn=None):
        """
        Load facility hierarchy from databases.

        Args:
            adl_conn: Connection to VATSIM_ADL/VATSIM_REF
            gis_conn: Connection to VATSIM_GIS
        """
        if adl_conn:
            self._load_facilities_from_adl(adl_conn)

        if gis_conn:
            self._load_airports_from_gis(gis_conn)

        self._loaded = True
        logger.info(f"Loaded facility hierarchy: {len(self.artccs)} ARTCCs, "
                   f"{len(self.tracons)} TRACONs, {len(self.airports)} airports")

    def _load_facilities_from_adl(self, conn):
        """Load ARTCCs and TRACONs from VATSIM_REF.area_centers"""
        cursor = conn.cursor()
        try:
            # Query area_centers for facility list
            query = """
                SELECT center_code, center_type, center_name, parent_artcc, lat, lon
                FROM VATSIM_REF.dbo.area_centers
            """
            # Handle pymssql vs pyodbc parameter style
            if hasattr(conn, 'driver') and conn.driver == 'pymssql':
                cursor.execute(query)
            else:
                cursor.execute(query)

            for row in cursor.fetchall():
                code, ftype, name, parent, lat, lon = row
                code = code.upper() if code else ''
                ftype = ftype.upper() if ftype else ''

                facility = FacilityInfo(
                    code=code,
                    facility_type=ftype,
                    name=name or '',
                    parent_artcc=parent or '',
                    lat=float(lat) if lat else 0.0,
                    lon=float(lon) if lon else 0.0
                )
                self.facilities[code] = facility

                if ftype == 'ARTCC':
                    self.artccs.add(code)
                elif ftype == 'TRACON':
                    self.tracons.add(code)

            logger.debug(f"Loaded {len(self.facilities)} facilities from VATSIM_REF")

        except Exception as e:
            logger.warning(f"Could not load facilities from ADL: {e}")
        finally:
            cursor.close()

    def _load_airports_from_gis(self, conn):
        """Load airport→facility mappings from VATSIM_GIS.airports"""
        cursor = conn.cursor()
        try:
            query = """
                SELECT icao_id, parent_artcc, parent_tracon
                FROM airports
                WHERE icao_id IS NOT NULL
            """
            cursor.execute(query)

            for row in cursor.fetchall():
                icao, geo_artcc, geo_tracon = row
                icao = icao.upper() if icao else ''

                # Check for override
                if icao in AIRPORT_FACILITY_OVERRIDES:
                    ctrl_artcc, ctrl_tracon = AIRPORT_FACILITY_OVERRIDES[icao]
                else:
                    ctrl_artcc = geo_artcc or ''
                    ctrl_tracon = geo_tracon or ''

                self.airports[icao] = AirportFacilityInfo(
                    icao_code=icao,
                    controlling_artcc=ctrl_artcc,
                    controlling_tracon=ctrl_tracon,
                    geographic_artcc=geo_artcc or '',
                    geographic_tracon=geo_tracon or ''
                )

            logger.debug(f"Loaded {len(self.airports)} airports from VATSIM_GIS")

        except Exception as e:
            logger.warning(f"Could not load airports from GIS: {e}")
        finally:
            cursor.close()

    def is_tracon(self, code: str) -> bool:
        """Check if a code is a known TRACON"""
        return code.upper() in self.tracons

    def is_artcc(self, code: str) -> bool:
        """Check if a code is a known ARTCC"""
        return code.upper() in self.artccs

    def is_sector(self, code: str) -> bool:
        """Check if a code looks like a sector (ARTCC + number)"""
        import re
        return bool(re.match(r'^Z[A-Z]{2}\d+$', code.upper()))

    def get_facility_type(self, code: str) -> str:
        """Get the facility type for a code"""
        code_upper = code.upper()

        if code_upper in self.facilities:
            return self.facilities[code_upper].facility_type

        # Fallback to pattern matching
        import re
        if re.match(r'^Z[A-Z]{2}$', code_upper):
            return 'ARTCC'
        if re.match(r'^Z[A-Z]{2}\d+$', code_upper):
            return 'SECTOR'
        if code_upper in self.tracons:
            return 'TRACON'

        return 'UNKNOWN'

    def get_airport_artcc(self, icao: str) -> str:
        """
        Get the controlling ARTCC for an airport.

        Uses hierarchy overrides when geographic containment differs
        from actual control (e.g., PHL → ZNY not ZDC).
        """
        icao_upper = icao.upper()

        # Check cache
        if icao_upper in self.airports:
            return self.airports[icao_upper].controlling_artcc

        # Check overrides
        if icao_upper in AIRPORT_FACILITY_OVERRIDES:
            return AIRPORT_FACILITY_OVERRIDES[icao_upper][0]

        return ''

    def get_airport_tracon(self, icao: str) -> str:
        """Get the controlling TRACON for an airport (if applicable)"""
        icao_upper = icao.upper()

        if icao_upper in self.airports:
            return self.airports[icao_upper].controlling_tracon

        if icao_upper in AIRPORT_FACILITY_OVERRIDES:
            return AIRPORT_FACILITY_OVERRIDES[icao_upper][1]

        return ''

    def airport_in_facility(self, airport: str, facility: str) -> bool:
        """
        Check if an airport is within a facility's control.

        Args:
            airport: Airport ICAO code (e.g., 'KJFK')
            facility: Facility code (e.g., 'ZNY', 'N90')

        Returns:
            True if airport is controlled by the facility
        """
        airport_upper = airport.upper()
        facility_upper = facility.upper()

        # Get airport's controlling facilities
        artcc = self.get_airport_artcc(airport_upper)
        tracon = self.get_airport_tracon(airport_upper)

        # Check against facility
        if facility_upper == artcc:
            return True
        if facility_upper == tracon:
            return True

        # Check if facility is an ARTCC and airport's TRACON is under it
        if facility_upper in self.artccs and tracon:
            tracon_info = self.facilities.get(tracon)
            if tracon_info and tracon_info.parent_artcc == facility_upper:
                return True

        return False


# Global cache instance (populated lazily)
_facility_cache: Optional[FacilityHierarchyCache] = None


def get_facility_cache() -> FacilityHierarchyCache:
    """Get the global facility hierarchy cache"""
    global _facility_cache
    if _facility_cache is None:
        _facility_cache = FacilityHierarchyCache()
    return _facility_cache


def initialize_facility_cache(adl_conn=None, gis_conn=None):
    """Initialize the facility cache with database connections"""
    cache = get_facility_cache()
    cache.load_from_databases(adl_conn, gis_conn)
    return cache


# Fallback sets for when database is unavailable
# These are populated from area_centers data
FALLBACK_TRACONS = {
    'N90', 'A90', 'C90', 'D10', 'I90', 'L30', 'M98', 'NCT', 'PCT', 'P50',
    'R90', 'S46', 'S56', 'SCT', 'U90', 'Y90', 'A80', 'D01', 'F11', 'M03',
    'P31', 'P80', 'T75', 'CLT', 'CLE', 'MKE', 'IND', 'CVG', 'CMH', 'PIT',
    'PHL', 'MIA', 'TPA', 'JAX', 'BNA', 'MCI', 'STL', 'MSP', 'DTW', 'SDF',
}

FALLBACK_ARTCCS = {
    'ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
    'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL',
    'ZAN', 'ZHN',  # Alaska, Honolulu
    # Canadian FIRs
    'CZEG', 'CZQM', 'CZQX', 'CZUL', 'CZVR', 'CZWG', 'CZYZ',
}


def is_known_tracon(code: str) -> bool:
    """Check if a code is a known TRACON (uses cache or fallback)"""
    cache = get_facility_cache()
    if cache._loaded:
        return cache.is_tracon(code)
    return code.upper() in FALLBACK_TRACONS


def is_known_artcc(code: str) -> bool:
    """Check if a code is a known ARTCC (uses cache or fallback)"""
    cache = get_facility_cache()
    if cache._loaded:
        return cache.is_artcc(code)
    return code.upper() in FALLBACK_ARTCCS
