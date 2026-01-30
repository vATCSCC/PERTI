"""
TMI Compliance Analyzer - Main Analysis Engine
===============================================

Core analysis logic for MIT, MINIT, and Ground Stop compliance.
"""

import math
import logging
from datetime import datetime
from collections import defaultdict
from typing import Dict, List, Any, Optional

from .models import (
    TMI, TMIType, EventConfig, CrossingResult, Compliance, SpacingCategory,
    categorize_spacing, calculate_shortfall_pct, normalize_datetime,
    CROSSING_RADIUS_NM
)
from .database import ADLConnection, GISConnection

logger = logging.getLogger(__name__)


def haversine_nm(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Calculate distance between two points in nautical miles"""
    R = 3440.065  # Earth radius in nm
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = math.sin(dlat/2)**2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon/2)**2
    return R * 2 * math.asin(math.sqrt(a))


class TMIComplianceAnalyzer:
    """Main analyzer class for TMI compliance"""

    def __init__(self, event: EventConfig):
        self.event = event
        self.adl_conn = None
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
            with ADLConnection() as adl, GISConnection() as gis:
                self.adl_conn = adl.conn
                self.gis_conn = gis.conn

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
                        key = f"{tmi.tmi_type.value}_{tmi.fix}_{tmi.fix}"
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
            dest_in = "'" + "','".join(tmi.destinations) + "'"
            dest_filter = f"AND p.fp_dest_icao IN ({dest_in})"

        if tmi.origins:
            orig_in = "'" + "','".join(tmi.origins) + "'"
            orig_filter = f"AND p.fp_dept_icao IN ({orig_in})"

        # Use WIDEST window
        tmi_start = tmi.start_utc
        tmi_end = tmi.get_effective_end()
        query_start = min(self.event.start_utc, tmi_start)
        query_end = max(self.event.end_utc, tmi_end)

        cursor.execute(f"""
            SELECT DISTINCT c.callsign, c.flight_uid, p.fp_dept_icao, p.fp_dest_icao,
                   c.first_seen_utc, c.last_seen_utc
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            WHERE c.first_seen_utc <= ?
              AND c.last_seen_utc >= ?
              {dest_filter}
              {orig_filter}
        """, (
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
        """Detect fix crossings using trajectory data"""
        crossings = []
        cursor = self.adl_conn.cursor()

        tmi_start = tmi.start_utc
        tmi_end = tmi.get_effective_end()

        # Bounding box filter
        lat_margin = 0.18  # ~11nm
        lon_margin = 0.24

        callsign_in = "'" + "','".join(callsigns) + "'"

        cursor.execute(f"""
            SELECT t.callsign, t.flight_uid, t.timestamp_utc,
                   t.lat, t.lon, t.groundspeed_kts, t.altitude_ft,
                   p.fp_dept_icao, p.fp_dest_icao
            FROM dbo.adl_trajectory_archive t
            INNER JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
            WHERE t.timestamp_utc >= ?
              AND t.timestamp_utc <= ?
              AND t.callsign IN ({callsign_in})
              AND t.lat BETWEEN ? AND ?
              AND t.lon BETWEEN ? AND ?
            ORDER BY t.callsign, t.timestamp_utc
        """, (
            tmi_start.strftime('%Y-%m-%d %H:%M:%S'),
            tmi_end.strftime('%Y-%m-%d %H:%M:%S'),
            fix_lat - lat_margin, fix_lat + lat_margin,
            fix_lon - lon_margin, fix_lon + lon_margin
        ))

        positions = cursor.fetchall()
        cursor.close()

        # Group by callsign and find closest approach
        flight_positions = defaultdict(list)
        for pos in positions:
            flight_positions[pos[0]].append(pos)

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

    def _analyze_mit_compliance(self, tmi: TMI) -> Optional[Dict]:
        """Analyze MIT/MINIT compliance for a TMI"""
        logger.info(f"Analyzing {tmi.tmi_type.value}: {tmi.fix}")

        fix = tmi.fix
        if fix not in self.fix_coords:
            logger.warning(f"Fix {fix} not found in coordinates")
            return None

        coords = self.fix_coords[fix]
        flights = self._get_flights_for_tmi(tmi)

        if not flights:
            logger.info(f"No flights found for TMI scope")
            return None

        # Detect crossings
        crossings = self._detect_crossings(
            fix, coords['lat'], coords['lon'],
            list(flights.keys()), tmi
        )

        logger.info(f"Crossings detected: {len(crossings)}")

        if len(crossings) < 2:
            return {
                'fix': fix,
                'total_crossings': len(crossings),
                'valid_crossings': len(crossings),
                'pairs': 0,
                'message': 'Insufficient crossings for analysis'
            }

        # Filter to TMI active window
        valid_crossings = [c for c in crossings if tmi.is_active_at(c.crossing_time)]

        logger.info(f"Valid crossings (in TMI window): {len(valid_crossings)}")

        if len(valid_crossings) < 2:
            return {
                'fix': fix,
                'total_crossings': len(crossings),
                'valid_crossings': len(valid_crossings),
                'pairs': 0,
                'message': 'Insufficient crossings in TMI window'
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

        return {
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
            }
        }

    def _analyze_gs_compliance(self, tmi: TMI) -> Optional[Dict]:
        """Analyze Ground Stop compliance"""
        logger.info(f"Analyzing GS: {','.join(tmi.destinations)}")

        cursor = self.adl_conn.cursor()

        # Get flights from affected origins to destinations
        dest_in = "'" + "','".join(tmi.destinations) + "'" if tmi.destinations else "''"

        # For GS, get ALL flights to destination during event window
        cursor.execute(f"""
            SELECT c.callsign, p.fp_dept_icao, c.first_seen_utc, c.last_seen_utc
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            WHERE p.fp_dest_icao IN ({dest_in})
              AND c.first_seen_utc <= ?
              AND c.last_seen_utc >= ?
            ORDER BY c.first_seen_utc
        """, (
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
        """Track APREQ/CFR flights (no compliance assessment)"""
        flights = self._get_flights_for_tmi(tmi)

        return {
            'fix': tmi.fix or 'ALL',
            'destinations': tmi.destinations,
            'tmi_start': tmi.start_utc.strftime('%H:%MZ') if tmi.start_utc else '',
            'tmi_end': tmi.end_utc.strftime('%H:%MZ') if tmi.end_utc else '',
            'total_flights': len(flights),
            'note': 'APREQ/CFR compliance requires coordination verification - not automated'
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
