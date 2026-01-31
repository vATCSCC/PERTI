"""
TMI Compliance Analyzer - Main Analysis Engine
===============================================

Core analysis logic for MIT, MINIT, and Ground Stop compliance.
"""

import math
import logging
from datetime import datetime, timedelta
from collections import defaultdict
from typing import Dict, List, Any, Optional

from .models import (
    TMI, TMIType, EventConfig, CrossingResult, BoundaryCrossing, Compliance,
    SpacingCategory, MeasurementType, categorize_spacing, calculate_shortfall_pct,
    normalize_datetime, normalize_icao_list, CROSSING_RADIUS_NM, MITModifier,
    TrafficDirection, TrafficFilter
)
from .database import ADLConnection, GISConnection
import json

logger = logging.getLogger(__name__)


def haversine_nm(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Calculate distance between two points in nautical miles"""
    R = 3440.065  # Earth radius in nm
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = math.sin(dlat/2)**2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon/2)**2
    return R * 2 * math.asin(math.sqrt(a))


def classify_facility(code: str) -> str:
    """
    Classify a facility code as ARTCC, TRACON, or AIRPORT.

    ARTCC codes: 3 letters starting with Z (ZNY, ZDC, ZBW, ZSE, ZLA, ZOA, ZFW, etc.)
    TRACON codes: Various patterns:
        - Letter + 2 digits: N90, A90, C90, A80, D10, I90, L30, P80, S56, etc.
        - 3 letters not starting with Z or K: PCT, SCT, NCT, etc.
    Airport codes: 3-4 letters starting with K (KJFK, KBOS) or 3-letter (JFK, BOS)

    Args:
        code: Facility identifier

    Returns:
        'ARTCC', 'TRACON', or 'AIRPORT'
    """
    if not code:
        return 'UNKNOWN'

    code = code.upper().strip()

    # ARTCC: 3 letters starting with Z
    if len(code) == 3 and code.startswith('Z'):
        return 'ARTCC'

    # ARTCC with K prefix: KZNY, KZDC (used in some GIS data)
    if len(code) == 4 and code.startswith('KZ'):
        return 'ARTCC'

    # TRACON patterns
    # Letter + 2 digits: N90, A90, C90, A80, D10, I90, L30, P80, S56
    if len(code) == 3 and code[0].isalpha() and code[1:].isdigit():
        return 'TRACON'

    # 3-letter TRACON codes (not starting with Z or K): PCT, SCT, NCT, SDF
    if len(code) == 3 and code.isalpha() and not code.startswith('Z') and not code.startswith('K'):
        # Common TRACON identifiers
        known_tracons = {'PCT', 'SCT', 'NCT', 'SDF', 'MIA', 'DFW', 'ATL', 'ORD', 'DEN', 'PHX', 'SEA', 'MSP', 'DTW', 'CLT', 'BOS', 'LAS', 'SLC', 'IAH', 'DCA'}
        if code in known_tracons:
            return 'TRACON'
        # Could be an airport - will need context
        return 'AIRPORT'  # Default to airport for 3-letter non-ARTCC

    # Airport: K + 3 letters (KJFK, KBOS)
    if len(code) == 4 and code.startswith('K') and code[1:].isalpha():
        return 'AIRPORT'

    # Default: assume airport for short codes
    return 'AIRPORT'


class TMIComplianceAnalyzer:
    """Main analyzer class for TMI compliance"""

    def __init__(self, event: EventConfig):
        self.event = event
        self.adl = None          # ADLConnection wrapper (for format_query)
        self.adl_conn = None     # Raw database connection
        self.gis_conn = None
        self.fix_coords = {}
        self.flight_data = {}

    def analyze(self) -> Dict:
        """Run full compliance analysis"""
        logger.info(f"Starting analysis for: {self.event.name}")

        results = {
            'event': self.event.name,
            'event_start': self.event.start_utc.isoformat(),
            'event_end': self.event.end_utc.isoformat(),
            'generated_utc': datetime.utcnow().isoformat(),
            'summary': {},
            'mit_results': {},
            'gs_results': {},
            'apreq_results': {}
        }

        # Connect to databases
        try:
            # GIS connection is optional - only ADL is required for trajectory analysis
            with ADLConnection() as adl:
                self.adl = adl                # Keep wrapper for format_query
                self.adl_conn = adl.conn      # Raw connection for cursor
                logger.info(f"ADL driver: {adl.driver}, param style: {adl.param_style}")

                # Try to connect to GIS (optional, for future spatial queries)
                try:
                    gis = GISConnection()
                    gis.connect()
                    self.gis_conn = gis.conn
                    logger.info("GIS connection established (optional)")
                except Exception as gis_err:
                    logger.warning(f"GIS connection unavailable (not required): {gis_err}")
                    self.gis_conn = None

                # Load fix coordinates for all TMIs
                all_fixes = set()
                for tmi in self.event.tmis:
                    if tmi.fix:
                        all_fixes.add(tmi.fix)
                    all_fixes.update(tmi.fixes)

                if all_fixes:
                    self._load_fix_coordinates(list(all_fixes))

                # Analyze by TMI type
                mit_tmis = [t for t in self.event.tmis if t.tmi_type in (TMIType.MIT, TMIType.MINIT)]
                gs_tmis = [t for t in self.event.tmis if t.tmi_type == TMIType.GS]
                apreq_tmis = [t for t in self.event.tmis if t.tmi_type in (TMIType.APREQ, TMIType.CFR)]

                # MIT/MINIT Analysis
                for tmi in mit_tmis:
                    result = self._analyze_mit_compliance(tmi)
                    if result:
                        # Use unique key: type_fix_starttime_value to differentiate multiple TMIs per fix
                        time_key = tmi.start_utc.strftime('%H%M') if tmi.start_utc else 'notime'
                        key = f"{tmi.tmi_type.value}_{tmi.fix}_{time_key}_{tmi.value}"
                        results['mit_results'][key] = result

                # Ground Stop Analysis
                for tmi in gs_tmis:
                    result = self._analyze_gs_compliance(tmi)
                    if result:
                        key = f"GS_{tmi.provider}_{','.join(tmi.destinations)}_ALL"
                        results['gs_results'][key] = result

                # APREQ Tracking (just count flights, no compliance assessment)
                for tmi in apreq_tmis:
                    result = self._track_apreq_flights(tmi)
                    if result:
                        key = f"{tmi.tmi_type.value}_{tmi.fix or 'ALL'}"
                        results['apreq_results'][key] = result

        except Exception as e:
            logger.exception("Analysis failed")
            raise

        # Calculate summary
        results['summary'] = self._calculate_summary(results)

        return results

    def _load_fix_coordinates(self, fixes: List[str]):
        """Load fix coordinates from database"""
        cursor = self.adl_conn.cursor()
        fix_in = "'" + "','".join(fixes) + "'"

        cursor.execute(f"""
            SELECT fix_name, lat, lon FROM dbo.nav_fixes
            WHERE fix_name IN ({fix_in})
            GROUP BY fix_name, lat, lon
        """)

        for row in cursor.fetchall():
            self.fix_coords[row[0]] = {
                'lat': float(row[1]),
                'lon': float(row[2])
            }
            logger.info(f"  Fix {row[0]}: {row[1]:.4f}, {row[2]:.4f}")

        cursor.close()

    def _get_flights_for_tmi(self, tmi: TMI) -> Dict[str, Any]:
        """Get flights affected by a TMI based on its scope"""
        cursor = self.adl_conn.cursor()
        flights = {}

        dest_filter = ""
        orig_filter = ""

        if tmi.destinations:
            # Normalize airport codes (ATL -> both ATL and KATL)
            normalized_dests = normalize_icao_list(tmi.destinations)
            dest_in = "'" + "','".join(normalized_dests) + "'"
            dest_filter = f"AND p.fp_dest_icao IN ({dest_in})"
            logger.debug(f"Destination filter: {dest_in}")

        if tmi.origins:
            normalized_origs = normalize_icao_list(tmi.origins)
            orig_in = "'" + "','".join(normalized_origs) + "'"
            orig_filter = f"AND p.fp_dept_icao IN ({orig_in})"

        # Use WIDEST window
        tmi_start = tmi.start_utc
        tmi_end = tmi.get_effective_end()
        query_start = min(self.event.start_utc, tmi_start)
        query_end = max(self.event.end_utc, tmi_end)

        # Format query for current driver (pymssql uses %s, pyodbc uses ?)
        query = self.adl.format_query(f"""
            SELECT DISTINCT c.callsign, c.flight_uid, p.fp_dept_icao, p.fp_dest_icao,
                   c.first_seen_utc, c.last_seen_utc
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            WHERE c.first_seen_utc <= %s
              AND c.last_seen_utc >= %s
              {dest_filter}
              {orig_filter}
        """)
        cursor.execute(query, (
            query_end.strftime('%Y-%m-%d %H:%M:%S'),
            query_start.strftime('%Y-%m-%d %H:%M:%S')
        ))

        for row in cursor.fetchall():
            flights[row[0]] = {
                'flight_uid': row[1],
                'dept': row[2],
                'dest': row[3],
                'first_seen': normalize_datetime(row[4]),
                'last_seen': normalize_datetime(row[5])
            }

        cursor.close()
        return flights

    def _detect_crossings(self, fix_name: str, fix_lat: float, fix_lon: float,
                          callsigns: List[str], tmi: TMI) -> List[CrossingResult]:
        """Detect fix crossings using trajectory data from both live and archive tables"""
        crossings = []
        cursor = self.adl_conn.cursor()

        tmi_start = tmi.start_utc
        tmi_end = tmi.get_effective_end()

        # Bounding box filter
        lat_margin = 0.18  # ~11nm
        lon_margin = 0.24

        callsign_in = "'" + "','".join(callsigns) + "'"

        # Query both live (adl_flight_trajectory) and archive (adl_trajectory_archive) tables
        # Live table uses recorded_utc and needs join to get callsign
        # Archive table has callsign and uses timestamp_utc
        query = self.adl.format_query(f"""
            SELECT callsign, flight_uid, timestamp_utc, lat, lon, groundspeed_kts, altitude_ft,
                   fp_dept_icao, fp_dest_icao
            FROM (
                -- Archive table (older data)
                SELECT t.callsign, t.flight_uid, t.timestamp_utc,
                       t.lat, t.lon, t.groundspeed_kts, t.altitude_ft,
                       p.fp_dept_icao, p.fp_dest_icao
                FROM dbo.adl_trajectory_archive t
                INNER JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
                WHERE t.timestamp_utc >= %s
                  AND t.timestamp_utc <= %s
                  AND t.callsign IN ({callsign_in})
                  AND t.lat BETWEEN %s AND %s
                  AND t.lon BETWEEN %s AND %s

                UNION ALL

                -- Live table (recent data - needs join for callsign)
                SELECT c.callsign, t.flight_uid, t.recorded_utc AS timestamp_utc,
                       t.lat, t.lon, t.groundspeed_kts, t.altitude_ft,
                       p.fp_dept_icao, p.fp_dest_icao
                FROM dbo.adl_flight_trajectory t
                INNER JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid
                INNER JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
                WHERE t.recorded_utc >= %s
                  AND t.recorded_utc <= %s
                  AND c.callsign IN ({callsign_in})
                  AND t.lat BETWEEN %s AND %s
                  AND t.lon BETWEEN %s AND %s
            ) combined
            ORDER BY callsign, timestamp_utc
        """)
        cursor.execute(query, (
            # Archive table params
            tmi_start.strftime('%Y-%m-%d %H:%M:%S'),
            tmi_end.strftime('%Y-%m-%d %H:%M:%S'),
            fix_lat - lat_margin, fix_lat + lat_margin,
            fix_lon - lon_margin, fix_lon + lon_margin,
            # Live table params (same values)
            tmi_start.strftime('%Y-%m-%d %H:%M:%S'),
            tmi_end.strftime('%Y-%m-%d %H:%M:%S'),
            fix_lat - lat_margin, fix_lat + lat_margin,
            fix_lon - lon_margin, fix_lon + lon_margin
        ))

        positions = cursor.fetchall()
        cursor.close()
        logger.info(f"  Found {len(positions)} trajectory points near {fix_name} (live+archive)")

        # Group by callsign and find closest approach
        flight_positions = defaultdict(list)
        for pos in positions:
            flight_positions[pos[0]].append(pos)
        logger.info(f"  Trajectory data for {len(flight_positions)} unique flights")

        for callsign, pos_list in flight_positions.items():
            closest_dist = float('inf')
            closest_pos = None

            for pos in pos_list:
                cs, fuid, ts, lat, lon, gs, alt, dept, dest = pos
                lat, lon = float(lat), float(lon)

                dist = haversine_nm(lat, lon, fix_lat, fix_lon)

                if dist < closest_dist:
                    closest_dist = dist
                    closest_pos = CrossingResult(
                        callsign=callsign,
                        flight_uid=fuid,
                        crossing_time=normalize_datetime(ts),
                        distance_nm=dist,
                        lat=lat,
                        lon=lon,
                        groundspeed=float(gs) if gs and 100 < gs < 600 else 250,
                        altitude=float(alt) if alt else 0,
                        dept=dept or 'UNK',
                        dest=dest or 'UNK'
                    )

            if closest_pos and closest_dist <= CROSSING_RADIUS_NM:
                crossings.append(closest_pos)

        return crossings

    def _detect_boundary_crossings(self, provider: str, requestor: str,
                                    callsigns: List[str], tmi: TMI) -> List[BoundaryCrossing]:
        """
        Detect facility boundary crossings using PostGIS for accurate handoff point measurement.

        Handles both ARTCC and TRACON boundaries:
        - ARTCC -> ARTCC: Uses get_trajectory_artcc_crossings()
        - TRACON -> ARTCC: Uses get_trajectory_all_crossings() to detect TRACON exit

        For a TMI like "P80:ZSE via KRATR 30MIT":
        - P80 is the provider (TRACON executing the MIT)
        - ZSE is the requestor (ARTCC needing the spacing)
        - We measure at the P80 boundary (when flight exits P80 into ZSE)

        For a TMI like "ZNY:ZDC via RBV 25MIT":
        - ZDC is the provider (ARTCC executing the MIT)
        - ZNY is the requestor (ARTCC needing the spacing)
        - We measure at the ZDC->ZNY boundary crossing

        Args:
            provider: Facility code of the TMI provider (ARTCC or TRACON)
            requestor: Facility code of the TMI requestor (ARTCC or TRACON)
            callsigns: List of flight callsigns to analyze
            tmi: TMI definition with time window

        Returns:
            List of BoundaryCrossing objects for flights crossing the provider->requestor boundary
        """
        if not self.gis_conn:
            logger.warning("GIS connection not available for boundary crossing detection")
            return []

        if not provider or not requestor:
            logger.warning("Provider and requestor required for boundary crossing detection")
            return []

        crossings = []
        cursor = self.adl_conn.cursor()
        gis_cursor = self.gis_conn.cursor()

        tmi_start = tmi.start_utc
        tmi_end = tmi.get_effective_end()

        # Classify facilities and normalize codes
        provider_type = classify_facility(provider)
        requestor_type = classify_facility(requestor)
        provider_codes = self._normalize_facility_code(provider)
        requestor_codes = self._normalize_facility_code(requestor)

        logger.info(f"Detecting boundary crossings: {provider} ({provider_type}) -> {requestor} ({requestor_type})")
        logger.info(f"  Provider codes: {provider_codes}, Requestor codes: {requestor_codes}")

        callsign_in = "'" + "','".join(callsigns) + "'"

        # Get trajectory data for each flight
        query = self.adl.format_query(f"""
            SELECT c.callsign, t.flight_uid, t.timestamp_utc,
                   t.lat, t.lon, t.groundspeed_kts, t.altitude_ft,
                   p.fp_dept_icao, p.fp_dest_icao
            FROM dbo.adl_trajectory_archive t
            INNER JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid
            INNER JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
            WHERE t.timestamp_utc >= %s
              AND t.timestamp_utc <= %s
              AND c.callsign IN ({callsign_in})
            ORDER BY c.callsign, t.timestamp_utc
        """)
        cursor.execute(query, (
            tmi_start.strftime('%Y-%m-%d %H:%M:%S'),
            tmi_end.strftime('%Y-%m-%d %H:%M:%S')
        ))

        # Group trajectory points by callsign
        flight_trajectories = defaultdict(list)
        flight_metadata = {}

        for row in cursor.fetchall():
            cs, fuid, ts, lat, lon, gs, alt, dept, dest = row
            flight_trajectories[cs].append({
                'timestamp': normalize_datetime(ts),
                'lat': float(lat),
                'lon': float(lon),
                'gs': float(gs) if gs and 100 < gs < 600 else 250,
                'alt': float(alt) if alt else 0
            })
            if cs not in flight_metadata:
                flight_metadata[cs] = {
                    'flight_uid': fuid,
                    'dept': dept or 'UNK',
                    'dest': dest or 'UNK'
                }

        cursor.close()
        logger.info(f"  Loaded trajectories for {len(flight_trajectories)} flights")

        # Process each flight through PostGIS
        for callsign, trajectory in flight_trajectories.items():
            if len(trajectory) < 2:
                continue

            # Build waypoints JSON for PostGIS
            waypoints = [
                {'lat': pt['lat'], 'lon': pt['lon'], 'sequence_num': i}
                for i, pt in enumerate(trajectory)
            ]
            waypoints_json = json.dumps(waypoints)

            # Try to find boundary crossings using PostGIS
            # First try get_trajectory_all_crossings (handles ARTCC + TRACON)
            # Fall back to get_trajectory_artcc_crossings (ARTCC only)
            try:
                boundary_crossings_data = None

                # Try get_trajectory_all_crossings first (includes TRACONs)
                try:
                    gis_cursor.execute('''
                        SELECT facility_code, crossing_lat, crossing_lon, crossing_fraction, facility_type
                        FROM get_trajectory_all_crossings(%s::jsonb)
                        ORDER BY crossing_fraction
                    ''', (waypoints_json,))
                    boundary_crossings_data = gis_cursor.fetchall()
                    has_facility_type = True
                except Exception:
                    # Function doesn't exist - fall back to ARTCC-only function
                    gis_cursor.execute('''
                        SELECT artcc_code, crossing_lat, crossing_lon, crossing_fraction
                        FROM get_trajectory_artcc_crossings(%s::jsonb)
                        ORDER BY crossing_fraction
                    ''', (waypoints_json,))
                    boundary_crossings_data = [(r[0], r[1], r[2], r[3], 'ARTCC') for r in gis_cursor.fetchall()]
                    has_facility_type = False

                if not boundary_crossings_data:
                    continue

                # Find the provider->requestor boundary crossing
                # For TRACON->ARTCC (P80->ZSE), we detect when flight exits TRACON
                # For ARTCC->ARTCC (ZDC->ZNY), we detect when flight crosses boundary
                prev_facility = None
                for row in boundary_crossings_data:
                    facility_code = row[0]
                    clat, clon, cfrac = row[1], row[2], row[3]
                    facility_type_col = row[4] if len(row) > 4 else 'ARTCC'

                    # Check if this is the boundary we're looking for
                    # Provider -> Requestor means exiting provider and entering requestor
                    # Handle case where provider is TRACON (P80) and requestor is ARTCC (ZSE)
                    is_provider_match = (
                        prev_facility in provider_codes or
                        (provider_type == 'TRACON' and prev_facility and
                         any(prev_facility.startswith(p.rstrip('0123456789')) for p in provider_codes))
                    )
                    is_requestor_match = (
                        facility_code in requestor_codes or
                        (requestor_type == 'ARTCC' and
                         any(facility_code.startswith(r.rstrip('0123456789')) for r in requestor_codes))
                    )

                    if is_provider_match and is_requestor_match:
                        # Found the handoff point!
                        crossing_time, crossing_gs, crossing_alt = self._interpolate_crossing_time(
                            trajectory, cfrac
                        )

                        if crossing_time:
                            meta = flight_metadata.get(callsign, {})
                            crossing = BoundaryCrossing(
                                callsign=callsign,
                                flight_uid=meta.get('flight_uid', ''),
                                crossing_time=crossing_time,
                                crossing_lat=float(clat),
                                crossing_lon=float(clon),
                                from_artcc=prev_facility or provider,
                                to_artcc=facility_code,
                                groundspeed=crossing_gs,
                                altitude=crossing_alt,
                                dept=meta.get('dept', 'UNK'),
                                dest=meta.get('dest', 'UNK'),
                                distance_from_origin_nm=cfrac * self._estimate_route_length(trajectory),
                                crossing_type='ENTRY'
                            )
                            crossings.append(crossing)
                            break  # Only count first crossing

                    prev_facility = facility_code

            except Exception as e:
                logger.warning(f"Error processing trajectory for {callsign}: {e}")
                continue

        gis_cursor.close()
        logger.info(f"  Found {len(crossings)} boundary crossings")
        return crossings

    def _normalize_facility_code(self, code: str) -> List[str]:
        """
        Normalize facility code to match PostGIS database formats.

        Handles both ARTCCs and TRACONs with various naming conventions.

        Returns list of possible codes for matching.
        """
        if not code:
            return []

        code = code.upper().strip()
        codes = [code]

        facility_type = classify_facility(code)

        if facility_type == 'ARTCC':
            # US ARTCCs in PostGIS often have K prefix: ZDC -> KZDC
            if len(code) == 3 and code.startswith('Z'):
                codes.append('K' + code)
            elif len(code) == 4 and code.startswith('KZ'):
                codes.append(code[1:])  # KZDC -> ZDC

        elif facility_type == 'TRACON':
            # TRACONs may have K prefix in some datasets
            if not code.startswith('K'):
                codes.append('K' + code)
            # Some TRACONs use airport code (P80 vs KPDX approach)
            # This is data-dependent - add variations as needed

        return codes

    # Backwards compatibility alias
    def _normalize_artcc_code(self, code: str) -> List[str]:
        """Legacy alias for _normalize_facility_code"""
        return self._normalize_facility_code(code)

    def _interpolate_crossing_time(self, trajectory: List[dict], fraction: float) -> tuple:
        """
        Interpolate the crossing time from trajectory based on fraction along route.

        Args:
            trajectory: List of trajectory points with timestamp, lat, lon, gs, alt
            fraction: Position along route (0.0 to 1.0)

        Returns:
            Tuple of (crossing_time, groundspeed, altitude) or (None, 0, 0) if interpolation fails
        """
        if not trajectory or len(trajectory) < 2:
            return None, 0, 0

        # Calculate cumulative distances
        cumulative_dist = [0.0]
        for i in range(1, len(trajectory)):
            prev = trajectory[i - 1]
            curr = trajectory[i]
            dist = haversine_nm(prev['lat'], prev['lon'], curr['lat'], curr['lon'])
            cumulative_dist.append(cumulative_dist[-1] + dist)

        total_dist = cumulative_dist[-1]
        if total_dist <= 0:
            return None, 0, 0

        # Find target distance
        target_dist = fraction * total_dist

        # Find the segment containing this distance
        for i in range(1, len(cumulative_dist)):
            if cumulative_dist[i] >= target_dist:
                # Interpolate between points i-1 and i
                prev = trajectory[i - 1]
                curr = trajectory[i]
                seg_start = cumulative_dist[i - 1]
                seg_len = cumulative_dist[i] - seg_start

                if seg_len > 0:
                    seg_frac = (target_dist - seg_start) / seg_len
                else:
                    seg_frac = 0

                # Interpolate time
                prev_time = prev['timestamp']
                curr_time = curr['timestamp']
                time_diff = (curr_time - prev_time).total_seconds()
                crossing_time = prev_time + timedelta(seconds=time_diff * seg_frac)

                # Interpolate GS and altitude
                crossing_gs = prev['gs'] + (curr['gs'] - prev['gs']) * seg_frac
                crossing_alt = prev['alt'] + (curr['alt'] - prev['alt']) * seg_frac

                return crossing_time, crossing_gs, crossing_alt

        # Default to last point
        last = trajectory[-1]
        return last['timestamp'], last['gs'], last['alt']

    def _estimate_route_length(self, trajectory: List[dict]) -> float:
        """Estimate total route length in nm from trajectory points"""
        if len(trajectory) < 2:
            return 0.0

        total = 0.0
        for i in range(1, len(trajectory)):
            prev = trajectory[i - 1]
            curr = trajectory[i]
            total += haversine_nm(prev['lat'], prev['lon'], curr['lat'], curr['lon'])

        return total

    def _analyze_mit_compliance(self, tmi: TMI) -> Optional[Dict]:
        """Analyze MIT/MINIT compliance for a TMI"""
        time_str = f"{tmi.start_utc.strftime('%H:%MZ') if tmi.start_utc else '??'}-{tmi.end_utc.strftime('%H:%MZ') if tmi.end_utc else '??'}"
        logger.info(f"Analyzing {tmi.tmi_type.value}: {tmi.fix} {tmi.value}nm {time_str}")

        fix = tmi.fix
        flights = self._get_flights_for_tmi(tmi)
        logger.info(f"  Found {len(flights)} flights matching destination filter")

        if not flights:
            logger.info(f"No flights found for TMI scope")
            return None

        # Detect BOTH fix and boundary crossings, then use the earlier one per flight
        # This ensures we measure at the actual handoff point, not just at the fix
        fix_crossings_map = {}      # callsign -> CrossingResult
        boundary_crossings_map = {} # callsign -> CrossingResult
        measurement_stats = {'fix': 0, 'boundary': 0}

        # 1. Detect fix crossings (if fix is specified and known)
        if fix and fix in self.fix_coords:
            coords = self.fix_coords[fix]
            fix_results = self._detect_crossings(
                fix, coords['lat'], coords['lon'],
                list(flights.keys()), tmi
            )
            for crossing in fix_results:
                fix_crossings_map[crossing.callsign] = crossing
            logger.info(f"  Fix crossings ({fix}): {len(fix_crossings_map)}")

        # 2. Detect boundary crossings (if provider/requestor specified and GIS available)
        if tmi.provider and tmi.requestor and self.gis_conn:
            logger.info(f"  Attempting boundary detection: {tmi.provider} -> {tmi.requestor}")
            boundary_results = self._detect_boundary_crossings(
                tmi.provider, tmi.requestor,
                list(flights.keys()), tmi
            )
            for bc in boundary_results:
                # Convert to CrossingResult for uniform handling
                boundary_crossings_map[bc.callsign] = CrossingResult(
                    callsign=bc.callsign,
                    flight_uid=bc.flight_uid,
                    crossing_time=bc.crossing_time,
                    distance_nm=bc.distance_from_origin_nm,
                    lat=bc.crossing_lat,
                    lon=bc.crossing_lon,
                    groundspeed=bc.groundspeed,
                    altitude=bc.altitude,
                    dept=bc.dept,
                    dest=bc.dest
                )
            logger.info(f"  Boundary crossings ({tmi.provider}->{tmi.requestor}): {len(boundary_crossings_map)}")

        # 3. For each flight, use whichever crossing comes FIRST (earlier in the flight path)
        # This is the actual handoff/measurement point
        crossings = []
        all_callsigns = set(fix_crossings_map.keys()) | set(boundary_crossings_map.keys())

        for callsign in all_callsigns:
            fix_cx = fix_crossings_map.get(callsign)
            bnd_cx = boundary_crossings_map.get(callsign)

            if fix_cx and bnd_cx:
                # Both found - use the earlier one (first in flight path)
                if fix_cx.crossing_time <= bnd_cx.crossing_time:
                    crossings.append(fix_cx)
                    measurement_stats['fix'] += 1
                else:
                    crossings.append(bnd_cx)
                    measurement_stats['boundary'] += 1
            elif fix_cx:
                crossings.append(fix_cx)
                measurement_stats['fix'] += 1
            elif bnd_cx:
                crossings.append(bnd_cx)
                measurement_stats['boundary'] += 1

        # Determine overall measurement type based on what was actually used
        if measurement_stats['boundary'] > 0 and measurement_stats['fix'] > 0:
            measurement_type = MeasurementType.BOUNDARY  # Mixed, but boundary-aware
            measurement_point = f"{tmi.provider}->{tmi.requestor} boundary (or {fix} if earlier)"
        elif measurement_stats['boundary'] > 0:
            measurement_type = MeasurementType.BOUNDARY
            measurement_point = f"{tmi.provider}->{tmi.requestor} boundary"
        elif measurement_stats['fix'] > 0:
            if tmi.provider and tmi.requestor:
                measurement_type = MeasurementType.BOUNDARY_FALLBACK_FIX
                measurement_point = f"{fix} (boundary unavailable)"
            else:
                measurement_type = MeasurementType.FIX
                measurement_point = fix
        else:
            measurement_type = MeasurementType.FIX
            measurement_point = fix or 'unknown'

        logger.info(f"  Final crossings: {len(crossings)} (fix: {measurement_stats['fix']}, boundary: {measurement_stats['boundary']})")

        if len(crossings) < 2:
            return {
                'fix': fix,
                'required': tmi.value,
                'unit': tmi.unit,
                'tmi_start': tmi.start_utc.strftime('%H:%MZ') if tmi.start_utc else '',
                'tmi_end': tmi.get_effective_end().strftime('%H:%MZ') if tmi.end_utc else '',
                'cancelled': tmi.cancelled_utc is not None,
                'total_crossings': len(crossings),
                'valid_crossings': len(crossings),
                'pairs': 0,
                'message': 'Insufficient crossings for analysis',
                # Measurement metadata
                'measurement_type': measurement_type.value,
                'measurement_point': measurement_point,
                'measurement_stats': measurement_stats,
                # Amendment tracking metadata
                'destinations': tmi.destinations,
                'issued_utc': tmi.issued_utc.strftime('%H:%MZ') if tmi.issued_utc else None,
                'is_amendment': tmi.supersedes_tmi_id is not None,
                'was_superseded': tmi.superseded_by_tmi_id is not None,
                # Facility metadata
                'is_multiple': tmi.is_multiple,
            }

        # Filter to TMI active window
        valid_crossings = [c for c in crossings if tmi.is_active_at(c.crossing_time)]

        logger.info(f"Valid crossings (in TMI window): {len(valid_crossings)}")

        if len(valid_crossings) < 2:
            return {
                'fix': fix,
                'required': tmi.value,
                'unit': tmi.unit,
                'tmi_start': tmi.start_utc.strftime('%H:%MZ') if tmi.start_utc else '',
                'tmi_end': tmi.get_effective_end().strftime('%H:%MZ') if tmi.end_utc else '',
                'cancelled': tmi.cancelled_utc is not None,
                'total_crossings': len(crossings),
                'valid_crossings': len(valid_crossings),
                'pairs': 0,
                'message': 'Insufficient crossings in TMI window',
                # Measurement metadata
                'measurement_type': measurement_type.value,
                'measurement_point': measurement_point,
                'measurement_stats': measurement_stats,
                # Amendment tracking metadata
                'destinations': tmi.destinations,
                'issued_utc': tmi.issued_utc.strftime('%H:%MZ') if tmi.issued_utc else None,
                'is_amendment': tmi.supersedes_tmi_id is not None,
                'was_superseded': tmi.superseded_by_tmi_id is not None,
                # Facility metadata
                'is_multiple': tmi.is_multiple,
            }

        # Sort by crossing time
        sorted_crossings = sorted(valid_crossings, key=lambda c: c.crossing_time)

        # Analyze consecutive pairs
        pairs = []
        required = tmi.value

        for i in range(1, len(sorted_crossings)):
            prev = sorted_crossings[i-1]
            curr = sorted_crossings[i]

            time_diff_sec = (curr.crossing_time - prev.crossing_time).total_seconds()
            time_diff_min = time_diff_sec / 60

            if time_diff_sec <= 0:
                continue

            # Calculate spacing based on TMI type
            if tmi.tmi_type == TMIType.MINIT:
                actual = time_diff_min
            else:
                actual = (time_diff_min * curr.groundspeed) / 60

            spacing_cat = categorize_spacing(actual, required)

            if spacing_cat == SpacingCategory.UNDER:
                compliance = Compliance.NON_COMPLIANT
                shortfall_pct = calculate_shortfall_pct(actual, required)
            else:
                compliance = Compliance.COMPLIANT
                shortfall_pct = 0

            margin_pct = ((actual - required) / required * 100) if required > 0 else 0

            pair = {
                'prev_callsign': prev.callsign,
                'curr_callsign': curr.callsign,
                'prev_time': prev.crossing_time.strftime('%H:%M:%SZ'),
                'curr_time': curr.crossing_time.strftime('%H:%M:%SZ'),
                'time_min': round(time_diff_min, 1),
                'spacing': round(actual, 1),
                'required': required,
                'margin_pct': round(margin_pct, 1),
                'spacing_category': spacing_cat.value,
                'compliance': compliance.value,
                'shortfall_pct': shortfall_pct,
                'gs': curr.groundspeed
            }
            pairs.append(pair)

        if not pairs:
            return None

        # Calculate statistics
        spacings = [p['spacing'] for p in pairs]

        under_count = sum(1 for p in pairs if p['spacing_category'] == SpacingCategory.UNDER.value)
        within_count = sum(1 for p in pairs if p['spacing_category'] == SpacingCategory.WITHIN.value)
        over_count = sum(1 for p in pairs if p['spacing_category'] == SpacingCategory.OVER.value)
        gap_count = sum(1 for p in pairs if p['spacing_category'] == SpacingCategory.GAP.value)

        violations_list = [p for p in pairs if p['shortfall_pct'] > 0]
        avg_shortfall = round(sum(p['shortfall_pct'] for p in violations_list) / len(violations_list), 1) if violations_list else 0
        max_shortfall = round(max((p['shortfall_pct'] for p in violations_list), default=0), 1)

        compliant_count = len(pairs) - under_count
        compliance_pct = 100 * compliant_count / len(pairs) if pairs else 0

        # Build result with amendment tracking info
        result = {
            'fix': fix,
            'required': required,
            'unit': tmi.unit,
            'tmi_start': tmi.start_utc.strftime('%H:%MZ'),
            'tmi_end': tmi.get_effective_end().strftime('%H:%MZ'),
            'cancelled': tmi.cancelled_utc is not None,
            'total_crossings': len(crossings),
            'valid_crossings': len(valid_crossings),
            'pairs': len(pairs),
            'avg_spacing': round(sum(spacings) / len(spacings), 1),
            'min_spacing': round(min(spacings), 1),
            'max_spacing': round(max(spacings), 1),
            'compliance_pct': round(compliance_pct, 1),
            'distribution': {
                'under': under_count,
                'within': within_count,
                'over': over_count,
                'gap': gap_count
            },
            'violations': {
                'total': under_count,
                'avg_shortfall_pct': avg_shortfall,
                'max_shortfall_pct': max_shortfall
            },
            'all_pairs': pairs,
            'spacing_stats': {
                'min': round(min(spacings), 1),
                'avg': round(sum(spacings) / len(spacings), 1),
                'max': round(max(spacings), 1)
            },
            # Measurement metadata
            'measurement_type': measurement_type.value,
            'measurement_point': measurement_point,
            'measurement_stats': measurement_stats,
            # Amendment tracking metadata
            'destinations': tmi.destinations,
            'origins': tmi.origins,
            'provider': tmi.provider,
            'requestor': tmi.requestor,
            'issued_utc': tmi.issued_utc.strftime('%H:%MZ') if tmi.issued_utc else None,
            'supersedes_tmi_id': tmi.supersedes_tmi_id,
            'superseded_by_tmi_id': tmi.superseded_by_tmi_id,
            'is_amendment': tmi.supersedes_tmi_id is not None,
            'was_superseded': tmi.superseded_by_tmi_id is not None,
            # Facility metadata
            'is_multiple': tmi.is_multiple,
            # Modifier and filter info
            'modifier': tmi.modifier.value if tmi.modifier else None,
            'traffic_direction': tmi.traffic_direction.value if tmi.traffic_direction else None,
        }

        # Add traffic filter details if present
        if tmi.traffic_filter:
            result['traffic_filter'] = {
                'aircraft_type': tmi.traffic_filter.aircraft_type.value if tmi.traffic_filter.aircraft_type else None,
                'speed_op': tmi.traffic_filter.speed_op.value if tmi.traffic_filter.speed_op else None,
                'speed_value': tmi.traffic_filter.speed_value,
                'altitude_filter': tmi.traffic_filter.altitude_filter.value if tmi.traffic_filter.altitude_filter else None,
                'altitude_value': tmi.traffic_filter.altitude_value,
                'exclusions': tmi.traffic_filter.exclusions
            }

        return result

    def _analyze_gs_compliance(self, tmi: TMI) -> Optional[Dict]:
        """Analyze Ground Stop compliance"""
        logger.info(f"Analyzing GS: {','.join(tmi.destinations)}")

        cursor = self.adl_conn.cursor()

        # Get flights from affected origins to destinations
        # Normalize airport codes (ATL -> both ATL and KATL)
        normalized_dests = normalize_icao_list(tmi.destinations) if tmi.destinations else []
        dest_in = "'" + "','".join(normalized_dests) + "'" if normalized_dests else "''"

        # For GS, get ALL flights to destination during event window
        # Format query for current driver (pymssql uses %s, pyodbc uses ?)
        query = self.adl.format_query(f"""
            SELECT c.callsign, p.fp_dept_icao, c.first_seen_utc, c.last_seen_utc
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            WHERE p.fp_dest_icao IN ({dest_in})
              AND c.first_seen_utc <= %s
              AND c.last_seen_utc >= %s
            ORDER BY c.first_seen_utc
        """)
        cursor.execute(query, (
            self.event.end_utc.strftime('%Y-%m-%d %H:%M:%S'),
            self.event.start_utc.strftime('%Y-%m-%d %H:%M:%S')
        ))

        flights = cursor.fetchall()
        cursor.close()

        if not flights:
            return None

        gs_start = tmi.start_utc
        gs_end = tmi.get_effective_end()
        gs_issued = tmi.issued_utc or gs_start

        exempt = []
        compliant = []
        non_compliant = []

        for row in flights:
            callsign, dept, first_seen, last_seen = row
            first_seen = normalize_datetime(first_seen)

            # Skip if no origin filter or origin doesn't match
            if tmi.origins and dept not in tmi.origins:
                continue

            flight_info = {
                'callsign': callsign,
                'dept': dept,
                'dept_time': first_seen.strftime('%H:%M:%SZ') if first_seen else None
            }

            # Determine compliance status
            if first_seen < gs_issued:
                flight_info['status'] = 'EXEMPT'
                flight_info['reason'] = 'Airborne before GS issued'
                exempt.append(flight_info)
            elif first_seen > gs_end:
                flight_info['status'] = 'COMPLIANT'
                flight_info['reason'] = 'Departed after GS ended'
                compliant.append(flight_info)
            else:
                flight_info['status'] = 'NON-COMPLIANT'
                flight_info['reason'] = 'Departed during GS window'
                # Calculate how far into GS they departed
                gs_duration = (gs_end - gs_start).total_seconds()
                into_gs = (first_seen - gs_start).total_seconds()
                flight_info['pct_into_gs'] = round(100 * into_gs / gs_duration, 1) if gs_duration > 0 else 0
                non_compliant.append(flight_info)

        total_applicable = len(compliant) + len(non_compliant)
        compliance_pct = round(100 * len(compliant) / total_applicable, 1) if total_applicable > 0 else 100

        return {
            'gs_start': gs_start.strftime('%H:%MZ'),
            'gs_end': gs_end.strftime('%H:%MZ'),
            'gs_issued': gs_issued.strftime('%H:%MZ'),
            'cancelled': tmi.cancelled_utc is not None,
            'total_flights': len(flights),
            'exempt': exempt,
            'compliant': compliant,
            'non_compliant': non_compliant,
            'compliance_pct': compliance_pct,
            'violations': {
                'total': len(non_compliant),
                'avg_pct_into_gs': round(sum(f.get('pct_into_gs', 0) for f in non_compliant) / len(non_compliant), 1) if non_compliant else 0
            },
            'destinations': tmi.destinations,
            'origins': tmi.origins
        }

    def _track_apreq_flights(self, tmi: TMI) -> Optional[Dict]:
        """
        Track APREQ/CFR flights - returns list of affected flights for manual review.

        APREQ/CFR compliance cannot be fully automated since it requires verification
        that coordination actually occurred. This method identifies:
        1. Flights that match the TMI scope (destinations, origins, fix)
        2. Flights that were active during the TMI window
        3. Flights that would need to request release

        Returns flight list with details for manual compliance review.
        """
        logger.info(f"Tracking {tmi.tmi_type.value}: {tmi.fix or 'ALL'} -> {','.join(tmi.destinations)}")

        flights = self._get_flights_for_tmi(tmi)
        logger.info(f"  Found {len(flights)} flights matching scope")

        if not flights:
            return {
                'fix': tmi.fix or 'ALL',
                'destinations': tmi.destinations,
                'origins': tmi.origins,
                'tmi_start': tmi.start_utc.strftime('%H:%MZ') if tmi.start_utc else '',
                'tmi_end': tmi.end_utc.strftime('%H:%MZ') if tmi.end_utc else '',
                'total_flights': 0,
                'affected_flights': [],
                'note': 'No flights found for APREQ/CFR scope'
            }

        tmi_start = tmi.start_utc
        tmi_end = tmi.get_effective_end()

        # Categorize flights based on timing relative to APREQ/CFR window
        exempt_flights = []       # Airborne before TMI issued
        affected_flights = []     # Need coordination during window
        post_tmi_flights = []     # Departed after TMI ended

        for callsign, flight_data in flights.items():
            first_seen = flight_data.get('first_seen')
            if not first_seen:
                continue

            flight_info = {
                'callsign': callsign,
                'dept': flight_data.get('dept', 'UNK'),
                'dest': flight_data.get('dest', 'UNK'),
                'first_seen': first_seen.strftime('%H:%M:%SZ') if first_seen else None,
            }

            # Check timing
            tmi_issued = tmi.issued_utc or tmi_start

            if first_seen < tmi_issued:
                # Airborne before APREQ/CFR was issued - exempt
                flight_info['status'] = 'EXEMPT'
                flight_info['reason'] = 'Airborne before issued'
                exempt_flights.append(flight_info)
            elif first_seen > tmi_end:
                # Departed after TMI ended
                flight_info['status'] = 'POST_TMI'
                flight_info['reason'] = 'Departed after TMI ended'
                post_tmi_flights.append(flight_info)
            else:
                # Departed during active TMI window - would need coordination
                flight_info['status'] = 'AFFECTED'
                flight_info['reason'] = 'Departed during APREQ/CFR window'
                affected_flights.append(flight_info)

        # If fix is specified, try to detect which affected flights crossed it
        if tmi.fix and tmi.fix.upper() not in ['ALL', 'ANY'] and affected_flights:
            if tmi.fix in self.fix_coords:
                coords = self.fix_coords[tmi.fix]
                fix_crossings = self._detect_crossings(
                    tmi.fix, coords['lat'], coords['lon'],
                    [f['callsign'] for f in affected_flights], tmi
                )
                crossing_callsigns = {c.callsign for c in fix_crossings}

                # Mark which affected flights actually crossed the fix
                for flight in affected_flights:
                    if flight['callsign'] in crossing_callsigns:
                        flight['crossed_fix'] = True
                        flight['reason'] = f'Crossed {tmi.fix} during window'
                    else:
                        flight['crossed_fix'] = False
                        flight['reason'] = f'Did not cross {tmi.fix} (different routing?)'

        return {
            'fix': tmi.fix or 'ALL',
            'destinations': tmi.destinations,
            'origins': tmi.origins,
            'tmi_start': tmi.start_utc.strftime('%H:%MZ') if tmi.start_utc else '',
            'tmi_end': tmi.get_effective_end().strftime('%H:%MZ') if tmi.end_utc else '',
            'issued_utc': tmi.issued_utc.strftime('%H:%MZ') if tmi.issued_utc else None,
            'cancelled': tmi.cancelled_utc is not None,
            'total_flights': len(flights),
            'exempt_count': len(exempt_flights),
            'affected_count': len(affected_flights),
            'post_tmi_count': len(post_tmi_flights),
            'exempt_flights': exempt_flights,
            'affected_flights': affected_flights,
            'post_tmi_flights': post_tmi_flights,
            'provider': tmi.provider,
            'requestor': tmi.requestor,
            'note': 'APREQ/CFR requires coordination verification - these flights would need release'
        }

    def _calculate_summary(self, results: Dict) -> Dict:
        """Calculate overall summary statistics"""
        summary = {
            'mit': {
                'total_pairs': 0,
                'total_violations': 0,
                'compliance_pct': 100,
                'avg_shortfall_pct': 0,
                'max_shortfall_pct': 0
            },
            'gs': {
                'applicable_flights': 0,
                'violations': 0,
                'compliance_pct': 100
            },
            'overall_compliance_pct': 100.0
        }

        # MIT summary
        total_pairs = 0
        total_violations = 0
        shortfalls = []

        for key, mit in results.get('mit_results', {}).items():
            pairs = mit.get('pairs', 0)
            violations = mit.get('violations', {}).get('total', 0)
            total_pairs += pairs
            total_violations += violations
            if mit.get('violations', {}).get('max_shortfall_pct', 0) > 0:
                shortfalls.append(mit['violations']['max_shortfall_pct'])

        summary['mit']['total_pairs'] = total_pairs
        summary['mit']['total_violations'] = total_violations
        summary['mit']['compliance_pct'] = round(100 * (total_pairs - total_violations) / total_pairs, 1) if total_pairs > 0 else 100
        summary['mit']['max_shortfall_pct'] = max(shortfalls) if shortfalls else 0

        # GS summary
        total_applicable = 0
        total_gs_violations = 0

        for key, gs in results.get('gs_results', {}).items():
            applicable = len(gs.get('compliant', [])) + len(gs.get('non_compliant', []))
            violations = len(gs.get('non_compliant', []))
            total_applicable += applicable
            total_gs_violations += violations

        summary['gs']['applicable_flights'] = total_applicable
        summary['gs']['violations'] = total_gs_violations
        summary['gs']['compliance_pct'] = round(100 * (total_applicable - total_gs_violations) / total_applicable, 1) if total_applicable > 0 else 100

        # Overall
        total_items = total_pairs + total_applicable
        total_issues = total_violations + total_gs_violations
        summary['overall_compliance_pct'] = round(100 * (total_items - total_issues) / total_items, 1) if total_items > 0 else 100

        return summary
