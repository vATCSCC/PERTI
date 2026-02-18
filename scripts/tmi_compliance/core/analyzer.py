"""
TMI Compliance Analyzer - Main Analysis Engine
===============================================

Core analysis logic for MIT, MINIT, and Ground Stop compliance.
"""

import math
import re
import logging
from datetime import datetime, timedelta
from collections import defaultdict, Counter
from typing import Dict, List, Any, Optional

from .models import (
    TMI, TMIType, EventConfig, CrossingResult, BoundaryCrossing, Compliance,
    SpacingCategory, MeasurementType, categorize_spacing, calculate_shortfall_pct,
    normalize_datetime, normalize_icao_list, CROSSING_RADIUS_NM, MITModifier,
    TrafficDirection, TrafficFilter,
    GSProgram, GSAdvisory, RerouteProgram, RerouteAdvisory, RouteEntry,
    REROUTE_COMPLIANT_THRESHOLD, REROUTE_PARTIAL_THRESHOLD,
    HOLD_MIN_HEADING_CHANGE_DEG, HOLD_MIN_DURATION_SEC, HOLD_MAX_RADIUS_NM,
    HOLD_CIRCLING_ALT_AGL_FT, HOLD_CIRCLING_DIST_NM, HOLD_MIN_GROUNDSPEED_KTS,
    HOLD_GAP_RESET_SEC, HOLD_LOW_CONFIDENCE_INTERVAL_SEC, HOLD_FIX_MATCH_RADIUS_NM
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


def closest_approach_on_segment(lat1: float, lon1: float, lat2: float, lon2: float,
                                fix_lat: float, fix_lon: float):
    """
    Find the closest approach of the great-circle segment (lat1,lon1)->(lat2,lon2)
    to the point (fix_lat, fix_lon) using projected interpolation.

    Returns (min_dist_nm, fraction, interp_lat, interp_lon) where:
    - min_dist_nm: minimum distance from segment to fix
    - fraction: 0.0-1.0 position along segment of closest approach
    - interp_lat, interp_lon: interpolated coordinates at closest approach

    For typical trajectory segments (<50nm), uses cos(lat)-corrected linear
    interpolation which is accurate to within ~0.1nm at mid-latitudes.
    """
    d1 = haversine_nm(lat1, lon1, fix_lat, fix_lon)
    seg_len = haversine_nm(lat1, lon1, lat2, lon2)
    if seg_len < 0.1:
        return (d1, 0.0, lat1, lon1)

    cos_lat = math.cos(math.radians((lat1 + lat2) / 2))
    dlat = lat2 - lat1
    dlon = (lon2 - lon1) * cos_lat
    flat = fix_lat - lat1
    flon = (fix_lon - lon1) * cos_lat

    denom = dlat * dlat + dlon * dlon
    if denom < 1e-14:
        return (d1, 0.0, lat1, lon1)

    t = max(0.0, min(1.0, (flat * dlat + flon * dlon) / denom))
    interp_lat = lat1 + t * (lat2 - lat1)
    interp_lon = lon1 + t * (lon2 - lon1)
    min_dist = haversine_nm(interp_lat, interp_lon, fix_lat, fix_lon)
    return (min_dist, t, interp_lat, interp_lon)


def calculate_bearing(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Calculate initial bearing from point 1 to point 2 in degrees (0-360)"""
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlon = lon2 - lon1
    x = math.sin(dlon) * math.cos(lat2)
    y = math.cos(lat1) * math.sin(lat2) - math.sin(lat1) * math.cos(lat2) * math.cos(dlon)
    bearing = math.atan2(x, y)
    return (math.degrees(bearing) + 360) % 360


def compute_approach_bearing(trajectory: List[dict], crossing_lat: float, crossing_lon: float,
                              crossing_seg_idx: int, approach_dist_nm: float = 150.0) -> Optional[float]:
    """
    Compute approach bearing from upstream trajectory to the crossing point.

    Unlike instantaneous heading at the crossing (which is convergent for all
    flights merging toward the same destination), approach bearing reveals the
    corridor a flight came from by looking ~150nm back along the trajectory.

    At arrival fixes, all flights converge with similar headings regardless of
    corridor. But 150nm back, flights are still on their enroute segments with
    distinct directions: flights from Arkansas might approach at ~200 degrees
    while flights from Mississippi approach at ~270 degrees — a separation that
    gap-based clustering can detect.

    Uses 150nm (not 75nm) because STAR convergence begins ~50-100nm from the
    destination, so 75nm still captures partially-converged traffic.

    Args:
        trajectory: Full flight trajectory (time-ordered list with lat, lon keys)
        crossing_lat: Latitude of the crossing point
        crossing_lon: Longitude of the crossing point
        crossing_seg_idx: Index into trajectory near the crossing (scan starts here)
        approach_dist_nm: Target lookback distance (default 150nm)

    Returns:
        Bearing in degrees (0-360) or None if insufficient upstream trajectory
    """
    # Scan backward from crossing segment to find a point ~approach_dist_nm upstream
    for i in range(min(crossing_seg_idx, len(trajectory) - 1), -1, -1):
        pt = trajectory[i]
        dist = haversine_nm(pt['lat'], pt['lon'], crossing_lat, crossing_lon)
        if dist >= approach_dist_nm:
            return calculate_bearing(pt['lat'], pt['lon'], crossing_lat, crossing_lon)

    # Didn't reach target distance. Use earliest trajectory point if at least
    # 30nm away (otherwise too close for meaningful corridor identification).
    if trajectory:
        first = trajectory[0]
        dist = haversine_nm(first['lat'], first['lon'], crossing_lat, crossing_lon)
        if dist >= 30.0:
            return calculate_bearing(first['lat'], first['lon'], crossing_lat, crossing_lon)

    return None


def compute_traffic_sector(crossings: List, trajectory_cache: dict,
                           measurement_lat: float, measurement_lon: float) -> Optional[dict]:
    """
    Compute angular sectors capturing 75% and 90% of traffic flow at measurement point.

    For each flight, determines the track heading as it passes the measurement point,
    then finds the smallest angular sector containing the specified percentages of traffic.

    Args:
        crossings: List of CrossingResult objects
        trajectory_cache: Dict of callsign -> trajectory points
        measurement_lat: Latitude of measurement point
        measurement_lon: Longitude of measurement point

    Returns:
        Dict with sector data for map rendering, or None if insufficient data
    """
    if len(crossings) < 3:
        return None

    # Collect track headings at measurement point
    headings = []

    for crossing in crossings:
        callsign = crossing.callsign
        if callsign not in trajectory_cache:
            continue

        trajectory = trajectory_cache[callsign]
        if len(trajectory) < 2:
            continue

        # Find the segment closest to measurement point
        min_dist = float('inf')
        best_idx = 0

        for i, pt in enumerate(trajectory):
            dist = haversine_nm(pt['lat'], pt['lon'], measurement_lat, measurement_lon)
            if dist < min_dist:
                min_dist = dist
                best_idx = i

        # Calculate heading from trajectory segment around closest point
        if best_idx == 0:
            # Use first two points
            p1, p2 = trajectory[0], trajectory[1]
        elif best_idx >= len(trajectory) - 1:
            # Use last two points
            p1, p2 = trajectory[-2], trajectory[-1]
        else:
            # Use point before and after
            p1, p2 = trajectory[best_idx - 1], trajectory[best_idx + 1]

        heading = calculate_bearing(p1['lat'], p1['lon'], p2['lat'], p2['lon'])
        headings.append(heading)

    if len(headings) < 3:
        return None

    # Find the median heading (central direction of traffic flow)
    # Use circular statistics to handle wrap-around at 360°
    sin_sum = sum(math.sin(math.radians(h)) for h in headings)
    cos_sum = sum(math.cos(math.radians(h)) for h in headings)
    median_heading = (math.degrees(math.atan2(sin_sum, cos_sum)) + 360) % 360

    # Convert headings to angular offsets from median (-180 to +180)
    offsets = []
    for h in headings:
        offset = h - median_heading
        if offset > 180:
            offset -= 360
        elif offset < -180:
            offset += 360
        offsets.append(offset)

    # Sort offsets to find percentile bounds
    offsets.sort()
    n = len(offsets)

    # Find smallest sector containing X% of tracks
    def find_sector_bounds(target_pct):
        target_count = int(math.ceil(n * target_pct))
        if target_count >= n:
            return offsets[0], offsets[-1]

        # Sliding window to find smallest angular range
        min_range = 360
        best_start = 0
        best_end = 0

        for i in range(n - target_count + 1):
            range_size = offsets[i + target_count - 1] - offsets[i]
            if range_size < min_range:
                min_range = range_size
                best_start = offsets[i]
                best_end = offsets[i + target_count - 1]

        return best_start, best_end

    # Compute 75% and 90% sectors
    start_75, end_75 = find_sector_bounds(0.75)
    start_90, end_90 = find_sector_bounds(0.90)

    # Convert back to absolute bearings
    bearing_start_75 = (median_heading + start_75 + 360) % 360
    bearing_end_75 = (median_heading + end_75 + 360) % 360
    bearing_start_90 = (median_heading + start_90 + 360) % 360
    bearing_end_90 = (median_heading + end_90 + 360) % 360

    return {
        'measurement_point': [round(measurement_lon, 4), round(measurement_lat, 4)],
        'median_heading': round(median_heading, 1),
        'track_count': len(headings),
        'sector_75': {
            'start_bearing': round(bearing_start_75, 1),
            'end_bearing': round(bearing_end_75, 1),
            'width_deg': round(end_75 - start_75, 1)
        },
        'sector_90': {
            'start_bearing': round(bearing_start_90, 1),
            'end_bearing': round(bearing_end_90, 1),
            'width_deg': round(end_90 - start_90, 1)
        }
    }


def cluster_crossings_by_bearing(crossings: List, gap_threshold_deg: float = 30.0) -> Dict[int, List]:
    """
    Cluster crossings into traffic streams based on bearing gaps.

    Uses circular gap detection: bearings are sorted around the circle (0-360),
    and gaps larger than gap_threshold_deg are used to split into streams.
    This naturally handles the 0/360 wrap-around.

    Args:
        crossings: List of CrossingResult with bearing field
        gap_threshold_deg: Minimum angular gap to split streams (default 30 degrees)

    Returns:
        Dict mapping stream_id (0-based) to list of CrossingResult.
        Stream -1 contains crossings without bearing data.
    """
    with_bearing = [(c, c.bearing) for c in crossings if c.bearing is not None]
    without_bearing = [c for c in crossings if c.bearing is None]

    if not with_bearing:
        # No bearing data — all unassigned
        return {-1: crossings} if crossings else {}

    # Sort by bearing (circular)
    with_bearing.sort(key=lambda x: x[1])

    # Find gaps > threshold in the circular arrangement
    bearings = [b for _, b in with_bearing]
    n = len(bearings)
    gaps = []

    for i in range(n):
        next_i = (i + 1) % n
        if next_i == 0:
            # Wrap-around gap: from last bearing to first + 360
            gap = (bearings[0] + 360) - bearings[-1]
        else:
            gap = bearings[next_i] - bearings[i]
        if gap >= gap_threshold_deg:
            gaps.append(i)  # Gap AFTER index i

    if not gaps:
        # All bearings within one cluster
        result = {0: [c for c, _ in with_bearing]}
        if without_bearing:
            result[-1] = without_bearing
        return result

    # Split into streams at the gaps
    # Start from the first element AFTER the largest gap (most natural break)
    # This ensures streams don't get split at arbitrary points
    result = {}
    stream_id = 0

    # Rotate so we start right after the first gap
    start_after = gaps[0] + 1
    ordered_indices = [(start_after + j) % n for j in range(n)]

    current_stream = []
    gap_set = set(gaps)

    for j, idx in enumerate(ordered_indices):
        current_stream.append(with_bearing[idx][0])
        # Check if there's a gap AFTER this index
        if idx in gap_set and j < n - 1:
            result[stream_id] = current_stream
            current_stream = []
            stream_id += 1

    if current_stream:
        result[stream_id] = current_stream

    if without_bearing:
        result[-1] = without_bearing

    return result


def cluster_crossings_by_trajectory(crossings: List, trajectory_cache: dict,
                                     fix_lat: float, fix_lon: float,
                                     gis_conn=None,
                                     min_dist_nm: float = 5.0,
                                     max_dist_nm: float = 250.0,
                                     eps_nm: float = 3.0,
                                     min_points: int = 5) -> Dict[int, List]:
    """
    Cluster crossings into traffic streams using PostGIS ST_ClusterDBSCAN.

    Uses the same spatial clustering algorithm as the JS frontend's branch analysis
    (track_density.php). For each flight, trajectory segment midpoints between
    min_dist_nm and max_dist_nm from the fix are inserted into PostGIS, then
    ST_ClusterDBSCAN groups spatially adjacent segments into corridors. Each
    flight is assigned to its majority corridor via segment vote.

    This approach is proven to correctly separate converging corridors because
    PostGIS operates on true geographic coordinates with proper distance metrics.
    """
    if not gis_conn:
        logger.warning("  No GIS connection — falling back to bearing-based clustering")
        return cluster_crossings_by_bearing(crossings, gap_threshold_deg=30.0)

    try:
        postgis_result = _cluster_via_postgis(crossings, trajectory_cache, fix_lat, fix_lon,
                                               gis_conn, min_dist_nm, max_dist_nm, eps_nm, min_points)
    except Exception as e:
        logger.warning(f"  PostGIS clustering failed ({e}) — falling back to bearing-based")
        return cluster_crossings_by_bearing(crossings, gap_threshold_deg=30.0)

    # Validate bearing coherence of PostGIS clusters
    # If streams have high bearing variance, spatial clustering created
    # non-directional groups (common at convergence fixes like DADES)
    return _validate_stream_coherence(crossings, postgis_result)


def _validate_stream_coherence(crossings, postgis_result, max_spread_deg=55.0):
    """
    Validate that PostGIS-produced streams have directionally coherent bearings.

    At junction fixes (SEEVR), PostGIS correctly separates corridors because
    flights from different directions occupy distinct geographic space upstream.
    At convergence fixes (DADES), trajectories from many origins overlap
    spatially, producing streams with mixed bearings — these are not useful
    for directional pairing.

    For each stream, compute circular bearing spread (angular std dev).
    If the majority of flights are in incoherent streams (spread > max_spread_deg),
    fall back to bearing-based clustering which directly separates by direction.

    Args:
        crossings: Original crossing list
        postgis_result: Dict from PostGIS clustering {stream_id -> [crossings]}
        max_spread_deg: Maximum acceptable bearing spread per stream (default 55°)

    Returns:
        PostGIS result if coherent, bearing-based result if not
    """
    if len(postgis_result) <= 1:
        return postgis_result

    total_flights = sum(len(cx) for cx in postgis_result.values())
    incoherent_flights = 0

    for sid, cx_list in postgis_result.items():
        if sid == -1:
            continue
        meta = compute_stream_metadata(cx_list)
        spread = meta.get('bearing_spread', 0)
        if spread > max_spread_deg:
            incoherent_flights += len(cx_list)
            logger.info(f"    Stream {sid}: bearing spread {spread}° > {max_spread_deg}° threshold "
                       f"({len(cx_list)} flights)")

    incoherent_pct = (incoherent_flights / total_flights * 100) if total_flights > 0 else 0

    if incoherent_pct > 40:
        logger.info(f"    PostGIS clustering incoherent: {incoherent_pct:.0f}% of flights in "
                   f"high-spread streams — falling back to bearing-based clustering")
        return cluster_crossings_by_bearing(crossings, gap_threshold_deg=30.0)

    logger.info(f"    PostGIS clustering validated: {incoherent_pct:.0f}% incoherent (threshold 40%)")
    return postgis_result


def _cluster_via_postgis(crossings, trajectory_cache, fix_lat, fix_lon,
                          gis_conn, min_dist_nm, max_dist_nm, eps_nm, min_points):
    """Internal: PostGIS-based spatial clustering of trajectory segments."""
    cursor = gis_conn.cursor()
    eps_deg = eps_nm / 60.0
    min_dist_m = min_dist_nm * 1852
    max_dist_m = max_dist_nm * 1852

    # Phase 1: Build trajectory segment midpoints for all crossing flights
    # Segments = midpoints of consecutive trajectory point pairs
    segments = []  # (callsign, seg_idx, mid_lat, mid_lon)
    callsign_set = set()

    for crossing in crossings:
        cs = crossing.callsign
        trajectory = trajectory_cache.get(cs, [])
        if len(trajectory) < 2:
            continue
        callsign_set.add(cs)
        for i in range(len(trajectory) - 1):
            p1, p2 = trajectory[i], trajectory[i + 1]
            mid_lat = (p1['lat'] + p2['lat']) / 2.0
            mid_lon = (p1['lon'] + p2['lon']) / 2.0
            segments.append((cs, i, mid_lat, mid_lon))

    if not segments:
        return {0: list(crossings)} if crossings else {}

    logger.info(f"    PostGIS clustering: {len(segments)} segments from "
                f"{len(callsign_set)} flights, eps={eps_nm}nm, min_pts={min_points}")

    # Phase 2: Create temp table and insert segments
    cursor.execute("DROP TABLE IF EXISTS _tmp_stream_segs")
    cursor.execute("""
        CREATE TEMP TABLE _tmp_stream_segs (
            callsign VARCHAR(20),
            seg_idx INT,
            geom GEOMETRY(Point, 4326)
        )
    """)

    # Batch insert using execute with values
    batch_size = 500
    for i in range(0, len(segments), batch_size):
        batch = segments[i:i + batch_size]
        values = []
        params = []
        for j, (cs, idx, lat, lon) in enumerate(batch):
            offset = j * 4
            values.append(f"(%s, %s, ST_SetSRID(ST_MakePoint(%s, %s), 4326))")
            params.extend([cs, idx, lon, lat])
        sql = "INSERT INTO _tmp_stream_segs (callsign, seg_idx, geom) VALUES " + ",".join(values)
        cursor.execute(sql, params)

    # Phase 3: ST_ClusterDBSCAN on segments within distance range of fix
    cursor.execute("""
        WITH fix AS (
            SELECT ST_SetSRID(ST_MakePoint(%s, %s), 4326)::geography AS geom
        ),
        filtered AS (
            SELECT s.callsign, s.seg_idx, s.geom,
                   ST_Distance(s.geom::geography, f.geom) / 1852.0 AS dist_nm
            FROM _tmp_stream_segs s
            CROSS JOIN fix f
            WHERE ST_DWithin(s.geom::geography, f.geom, %s)
              AND ST_Distance(s.geom::geography, f.geom) > %s
        ),
        clustered AS (
            SELECT callsign, seg_idx, dist_nm,
                   ST_ClusterDBSCAN(geom, eps := %s, minpoints := %s) OVER () AS cluster_id
            FROM filtered
        )
        SELECT callsign, cluster_id, COUNT(*) as seg_count
        FROM clustered
        WHERE cluster_id IS NOT NULL
        GROUP BY callsign, cluster_id
        ORDER BY callsign, seg_count DESC
    """, (fix_lon, fix_lat, max_dist_m, min_dist_m, eps_deg, min_points))

    # Phase 4: Assign each flight to its majority cluster
    flight_clusters = {}  # callsign -> {cluster_id: count}
    for row in cursor.fetchall():
        cs, cid, cnt = row
        if cs not in flight_clusters:
            flight_clusters[cs] = {}
        flight_clusters[cs][cid] = cnt

    # Majority vote per flight
    flight_assignment = {}
    for cs, clusters in flight_clusters.items():
        best_cluster = max(clusters, key=clusters.get)
        flight_assignment[cs] = best_cluster

    # Renumber cluster IDs to 0-based sequential
    unique_clusters = sorted(set(flight_assignment.values()))
    cluster_remap = {old: new for new, old in enumerate(unique_clusters)}

    # Phase 5: Build result dict, assign unassigned flights to nearest cluster
    result = {}
    unassigned = []
    for crossing in crossings:
        cs = crossing.callsign
        if cs in flight_assignment:
            sid = cluster_remap[flight_assignment[cs]]
            if sid not in result:
                result[sid] = []
            result[sid].append(crossing)
        else:
            unassigned.append(crossing)

    # Phase 6: Assign unassigned flights to nearest cluster by crossing position
    # Flights without segments in the distance band are matched to the nearest
    # cluster based on where they crossed (geographic proximity of crossing point)
    if unassigned and result:
        # Compute centroid crossing position per cluster
        cluster_centroids = {}
        for sid, cx_list in result.items():
            lats = [c.lat for c in cx_list if c.lat]
            lons = [c.lon for c in cx_list if c.lon]
            if lats and lons:
                cluster_centroids[sid] = (sum(lats) / len(lats), sum(lons) / len(lons))

        for crossing in unassigned:
            if crossing.lat and crossing.lon and cluster_centroids:
                best_sid = min(cluster_centroids,
                              key=lambda s: haversine_nm(
                                  crossing.lat, crossing.lon,
                                  cluster_centroids[s][0], cluster_centroids[s][1]))
            else:
                best_sid = max(result, key=lambda s: len(result[s]))
            result[best_sid].append(crossing)
            logger.info(f"    Assigned {crossing.callsign} to stream {best_sid} "
                       f"(crossing position match)")
    elif unassigned:
        result[0] = unassigned

    # Cleanup temp table
    cursor.execute("DROP TABLE IF EXISTS _tmp_stream_segs")
    gis_conn.commit()

    # Log
    for sid in sorted(result.keys()):
        logger.info(f"    Stream {sid}: {len(result[sid])} flights")

    return result


def compute_stream_metadata(stream_crossings: List) -> dict:
    """Compute metadata for a stream of crossings (circular mean bearing, spread, count)."""
    bearings = [c.bearing for c in stream_crossings if c.bearing is not None]
    if not bearings:
        return {'mean_bearing': None, 'bearing_spread': 0, 'count': len(stream_crossings)}

    # Circular mean
    sin_sum = sum(math.sin(math.radians(b)) for b in bearings)
    cos_sum = sum(math.cos(math.radians(b)) for b in bearings)
    mean_bearing = math.degrees(math.atan2(sin_sum, cos_sum)) % 360

    # Angular spread (circular std dev approximation)
    R = math.sqrt(sin_sum**2 + cos_sum**2) / len(bearings)
    spread = math.degrees(math.acos(min(1.0, R))) if R < 1.0 else 0

    return {
        'mean_bearing': round(mean_bearing, 1),
        'bearing_spread': round(spread, 1),
        'count': len(stream_crossings)
    }


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


# ---------------------------------------------------------------------------
# Holding pattern detection helpers (module-level pure functions)
# ---------------------------------------------------------------------------

def _heading_delta(h1: float, h2: float) -> float:
    """Signed heading change from h1 to h2, handling 360-degree wrap.
    Positive = clockwise (right turn), Negative = counter-clockwise (left turn).
    Returns value in range (-180, 180]."""
    d = (h2 - h1) % 360
    if d > 180:
        d -= 360
    return d


def detect_flight_holding(trajectory: List[Dict[str, Any]],
                          dest_lat: float, dest_lon: float) -> List[Dict[str, Any]]:
    """
    Detect holding patterns in a single flight's trajectory.

    Scans the trajectory for sustained turning (cumulative heading change
    exceeding one orbit) and groups consecutive orbits into hold events.

    Args:
        trajectory: List of dicts with keys:
            timestamp (datetime), lat (float), lon (float),
            gs (float), gs_valid (bool), alt (float)
        dest_lat: Destination airport latitude (for circling approach filter)
        dest_lon: Destination airport longitude (for circling approach filter)

    Returns:
        List of hold event dicts (see _finalize_hold for structure).
    """
    if len(trajectory) < 4:
        return []

    # Step 1: Build bearing series from consecutive trajectory points
    # Each entry is the bearing from point[i] to point[i+1]
    bearings = []
    for i in range(len(trajectory) - 1):
        p1 = trajectory[i]
        p2 = trajectory[i + 1]
        brg = calculate_bearing(p1['lat'], p1['lon'], p2['lat'], p2['lon'])
        bearings.append(brg)

    if len(bearings) < 2:
        return []

    # Step 2: Scan bearing deltas to find orbits
    holding_events = []
    cumulative_heading = 0.0
    orbit_count = 0
    turn_sign_sum = 0.0
    hold_start_idx = 0      # Index into bearings where current candidate began
    in_candidate = False

    for i in range(1, len(bearings)):
        # Check for time gaps in the trajectory segments contributing to
        # bearings[i-1] and bearings[i].  A large gap means data dropout;
        # reset the accumulator to avoid false detections across the gap.
        gap_sec = (trajectory[i]['timestamp'] - trajectory[i - 1]['timestamp']).total_seconds()
        if (i + 1) < len(trajectory):
            gap_sec = max(gap_sec,
                          (trajectory[i + 1]['timestamp'] - trajectory[i]['timestamp']).total_seconds())

        if gap_sec > HOLD_GAP_RESET_SEC:
            # Finalize any pending hold before resetting
            if in_candidate and orbit_count >= 1:
                _finalize_hold(trajectory, bearings, hold_start_idx, i - 1,
                               orbit_count, turn_sign_sum, holding_events,
                               dest_lat, dest_lon)
            # Reset accumulator
            cumulative_heading = 0.0
            orbit_count = 0
            turn_sign_sum = 0.0
            in_candidate = False
            hold_start_idx = i
            continue

        # Compute heading delta between consecutive bearings
        delta = _heading_delta(bearings[i - 1], bearings[i])
        cumulative_heading += delta
        turn_sign_sum += delta

        if not in_candidate:
            # Start tracking when we see meaningful turning
            if abs(cumulative_heading) >= 45:
                in_candidate = True
                hold_start_idx = max(0, i - 1)
            else:
                # Keep resetting start point until candidate begins
                hold_start_idx = i
                continue

        # Check if we've completed an orbit
        if abs(cumulative_heading) >= HOLD_MIN_HEADING_CHANGE_DEG:
            orbit_count += 1
            # Subtract one orbit's worth of heading, preserving sign
            if cumulative_heading > 0:
                cumulative_heading -= 360.0
            else:
                cumulative_heading += 360.0

    # Finalize any remaining candidate at trajectory end
    if in_candidate and orbit_count >= 1:
        _finalize_hold(trajectory, bearings, hold_start_idx, len(bearings) - 1,
                       orbit_count, turn_sign_sum, holding_events,
                       dest_lat, dest_lon)

    return holding_events


def _finalize_hold(trajectory: List[Dict[str, Any]],
                   bearings: List[float],
                   start_idx: int, end_idx: int,
                   orbit_count: int, turn_sign_sum: float,
                   holding_events: List[Dict[str, Any]],
                   dest_lat: float, dest_lon: float) -> None:
    """
    Validate and finalize a candidate holding event.

    Maps bearing indices back to trajectory indices, applies duration,
    spatial containment, and circling approach filters, then appends
    a validated event dict to holding_events.

    Args:
        trajectory: Full trajectory point list
        bearings: Bearing series (one per consecutive trajectory pair)
        start_idx: Start index in bearings array
        end_idx: End index in bearings array
        orbit_count: Number of detected orbits
        turn_sign_sum: Sum of all heading deltas (sign indicates direction)
        holding_events: Output list to append validated events to
        dest_lat: Destination latitude (for circling filter)
        dest_lon: Destination longitude (for circling filter)
    """
    # Map bearing indices to trajectory indices.
    # bearings[i] is derived from trajectory[i] -> trajectory[i+1],
    # so the trajectory span is [start_idx, end_idx + 1].
    traj_start = start_idx
    traj_end = min(end_idx + 1, len(trajectory) - 1)

    if traj_start >= traj_end:
        return

    # 1. Duration check
    t_start = trajectory[traj_start]['timestamp']
    t_end = trajectory[traj_end]['timestamp']
    duration_sec = int((t_end - t_start).total_seconds())

    if duration_sec < HOLD_MIN_DURATION_SEC:
        return

    # 2. Spatial containment: compute centroid and check radius
    hold_points = trajectory[traj_start:traj_end + 1]
    n_pts = len(hold_points)

    center_lat = sum(p['lat'] for p in hold_points) / n_pts
    center_lon = sum(p['lon'] for p in hold_points) / n_pts

    max_dist = 0.0
    dist_sum = 0.0
    for p in hold_points:
        d = haversine_nm(center_lat, center_lon, p['lat'], p['lon'])
        dist_sum += d
        if d > max_dist:
            max_dist = d

    if max_dist > HOLD_MAX_RADIUS_NM:
        return

    avg_radius = dist_sum / n_pts if n_pts > 0 else 0.0
    avg_alt = sum(p['alt'] for p in hold_points) / n_pts if n_pts > 0 else 0.0

    # 3. Circling approach filter: skip if close to destination and low altitude
    if dest_lat is not None and dest_lon is not None:
        dist_to_dest = haversine_nm(center_lat, center_lon, dest_lat, dest_lon)

        if dist_to_dest < HOLD_CIRCLING_DIST_NM and avg_alt < HOLD_CIRCLING_ALT_AGL_FT:
            return

    # 4. Compute metrics
    gs_values = [p['gs'] for p in hold_points if p.get('gs_valid', True) and p['gs'] > 0]
    avg_gs = sum(gs_values) / len(gs_values) if gs_values else 0.0

    # Groundspeed filter: exclude ground operations (taxi, pushback, parking)
    if avg_gs < HOLD_MIN_GROUNDSPEED_KTS:
        return

    turn_direction = 'R' if turn_sign_sum >= 0 else 'L'

    # 5. Data resolution / low-confidence check
    # Average interval between points; flag if sparser than threshold
    low_confidence = False
    if n_pts >= 2:
        avg_interval = duration_sec / (n_pts - 1)
        if avg_interval > HOLD_LOW_CONFIDENCE_INTERVAL_SEC:
            low_confidence = True
    else:
        low_confidence = True

    # 6. Append validated event
    holding_events.append({
        'hold_start_utc': t_start,
        'hold_end_utc': t_end,
        'duration_sec': duration_sec,
        'orbit_count': orbit_count,
        'center_lat': round(center_lat, 6),
        'center_lon': round(center_lon, 6),
        'avg_radius_nm': round(avg_radius, 2),
        'avg_altitude_ft': round(avg_alt, 0),
        'avg_groundspeed_kts': round(avg_gs, 1),
        'turn_direction': turn_direction,
        'low_confidence': low_confidence,
        'point_indices': (traj_start, traj_end),
    })


class TMIComplianceAnalyzer:
    """Main analyzer class for TMI compliance"""

    def __init__(self, event: EventConfig):
        self.event = event
        self.adl = None          # ADLConnection wrapper (for format_query)
        self.adl_conn = None     # Raw database connection
        self.gis_conn = None
        self.fix_coords = {}
        self.flight_data = {}
        # Trajectory caching for performance - computed once, reused across all TMIs
        self._trajectory_cache = {}      # callsign -> list of trajectory points
        self._trajectory_metadata = {}   # callsign -> {flight_uid, dept, dest}
        self._crossing_cache = {}        # callsign -> list of PostGIS boundary crossings
        self._trajectory_cache_loaded = False
        self._low_quality_flights = set()  # Flights with insufficient trajectory data
        self._mit_trajectories = {}  # key -> {callsign -> trajectory} for split output
        self._taxi_references = {}  # airport_icao -> unimpeded_taxi_sec
        self._holding_events = []           # List of holding event dicts
        self._flight_waypoints_cache = {}   # {flight_uid: [waypoints]}
        self._star_fixes_cache = {}         # {dest_icao: [fixes]}

    # Trajectory quality thresholds
    MIN_ENROUTE_POINTS = 5       # Minimum points with gs > 50 (enroute, not ground)
    MIN_UNIQUE_POSITIONS = 3    # Minimum unique lat/lon positions (~1nm precision)
    MIN_IMPLIED_SPEED_KTS = 100 # Minimum implied speed when checking gaps (catches SFO->LAS jumps)

    def _get_widest_time_window(self) -> tuple:
        """
        Calculate the widest time window from all TMIs, GS programs,
        reroute programs, and event times.

        Returns:
            Tuple of (earliest_start, latest_end) as datetime objects
        """
        earliest = self.event.start_utc
        latest = self.event.end_utc

        for tmi in self.event.tmis:
            if tmi.start_utc and tmi.start_utc < earliest:
                earliest = tmi.start_utc
            tmi_end = tmi.get_effective_end()
            if tmi_end and tmi_end > latest:
                latest = tmi_end

        # Include GS program windows
        for prog in getattr(self.event, 'gs_programs', []):
            if prog.effective_start and prog.effective_start < earliest:
                earliest = prog.effective_start
            if prog.effective_end and prog.effective_end > latest:
                latest = prog.effective_end

        # Include reroute program windows
        for prog in getattr(self.event, 'reroute_programs', []):
            if prog.effective_start and prog.effective_start < earliest:
                earliest = prog.effective_start
            if prog.effective_end and prog.effective_end > latest:
                latest = prog.effective_end

        return earliest, latest

    def _get_all_featured_flights(self) -> Dict[str, Any]:
        """
        Get ALL flights departing from or arriving at featured facilities.

        This is the primary flight gathering method - captures all flights
        that could potentially be affected by TMIs, without pre-filtering
        by route. Let the trajectory analysis determine actual crossings.

        Uses the widest time window from all TMIs + event times.

        Returns:
            Dict mapping callsign -> flight metadata
        """
        cursor = self.adl_conn.cursor()
        flights = {}

        # Get featured facilities from event config + GS/reroute program airports
        featured = list(self.event.destinations) if self.event.destinations else []

        # Auto-include airports from GS programs so their flights are loaded
        for prog in getattr(self.event, 'gs_programs', []):
            if prog.airport and prog.airport not in featured:
                featured.append(prog.airport)
                logger.info(f"Auto-included GS airport {prog.airport} in featured facilities")

        # Auto-include airports from reroute programs
        for prog in getattr(self.event, 'reroute_programs', []):
            for apt in (prog.origins or []):
                if apt and apt not in featured:
                    featured.append(apt)
            for apt in (prog.destinations or []):
                if apt and apt not in featured:
                    featured.append(apt)

        if not featured:
            logger.warning("No featured facilities defined - cannot gather flights")
            return flights

        # Normalize airport codes (ATL -> both ATL and KATL)
        normalized = normalize_icao_list(featured)
        facility_in = "'" + "','".join(normalized) + "'"

        # Calculate widest time window
        earliest, latest = self._get_widest_time_window()

        logger.info(f"Gathering flights for featured facilities: {featured}")
        logger.info(f"  Time window: {earliest.strftime('%Y-%m-%d %H:%MZ')} to {latest.strftime('%Y-%m-%d %H:%MZ')}")

        # Get ALL flights departing from OR arriving at featured facilities
        # LEFT JOIN adl_flight_times for OOOI departure times (atd_utc, off_utc)
        # LEFT JOIN adl_flight_aircraft for carrier/airline data
        # Also pull dept_artcc/dept_tracon from flight_plan for facility filtering
        query = self.adl.format_query(f"""
            SELECT DISTINCT c.callsign, c.flight_uid, p.fp_dept_icao, p.fp_dest_icao,
                   c.first_seen_utc, c.last_seen_utc, p.fp_route, p.fp_route_expanded, p.afix,
                   t.atd_utc, t.off_utc, t.out_utc, p.fp_dept_artcc, p.fp_dept_tracon,
                   a.airline_icao, a.airline_name
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            LEFT JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
            LEFT JOIN dbo.adl_flight_aircraft a ON c.flight_uid = a.flight_uid
            WHERE c.first_seen_utc <= %s
              AND c.last_seen_utc >= %s
              AND (p.fp_dept_icao IN ({facility_in}) OR p.fp_dest_icao IN ({facility_in}))
        """)
        cursor.execute(query, (
            latest.strftime('%Y-%m-%d %H:%M:%S'),
            earliest.strftime('%Y-%m-%d %H:%M:%S')
        ))

        for row in cursor.fetchall():
            callsign = row[0]
            flight_uid = row[1]
            flights[flight_uid] = {
                'callsign': callsign,
                'flight_uid': flight_uid,
                'dept': row[2],
                'dest': row[3],
                'first_seen': normalize_datetime(row[4]),
                'last_seen': normalize_datetime(row[5]),
                'fp_route': row[6] if len(row) > 6 else None,
                'route_expanded': row[7] if len(row) > 7 else None,
                'afix': row[8] if len(row) > 8 else None,
                'atd_utc': normalize_datetime(row[9]) if row[9] else None,
                'off_utc': normalize_datetime(row[10]) if row[10] else None,
                'out_utc': normalize_datetime(row[11]) if row[11] else None,
                'dept_artcc': row[12] if row[12] else None,
                'dept_tracon': row[13] if row[13] else None,
                'airline_icao': row[14] if len(row) > 14 and row[14] else None,
                'airline_name': row[15] if len(row) > 15 and row[15] else None,
            }

        cursor.close()
        logger.info(f"  Found {len(flights)} flights to/from featured facilities")
        return flights

    def _load_airport_taxi_references(self):
        """
        Load unimpeded taxi-out times from airport_taxi_reference table.

        These are per-airport p5-p15 averages (FAA ASPM methodology) computed
        from OOOI data over a 90-day rolling window. Used to estimate wheels-off
        time from gate push time: estimated_off = out_utc + unimpeded_taxi_sec.

        Default is 600 seconds (10 minutes) for airports with insufficient data.
        """
        self._taxi_references = {}
        cursor = self.adl_conn.cursor()

        query = self.adl.format_query(
            "SELECT airport_icao, unimpeded_taxi_sec, confidence FROM dbo.airport_taxi_reference"
        )
        try:
            cursor.execute(query)
            for row in cursor.fetchall():
                self._taxi_references[row[0]] = row[1]
            logger.info(f"Loaded {len(self._taxi_references)} airport taxi references")
        except Exception as e:
            logger.warning(f"Could not load airport taxi references: {e}")
            logger.warning("GS analysis will use 600s default taxi time for all airports")
        finally:
            cursor.close()

    def _get_taxi_reference(self, airport_icao: str) -> int:
        """Get unimpeded taxi-out time in seconds for an airport. Default: 600s (10 min)."""
        return self._taxi_references.get(airport_icao, 600)

    def _load_airport_connect_references(self):
        """
        Load unimpeded connect-to-push times from airport_connect_reference table.

        These are per-airport p5-p15 averages computed from first_seen-to-out_utc
        times over a 90-day rolling window. Used to estimate when a pilot was
        actually ready to depart after connecting to the network:
            ready_time = first_seen + unimpeded_connect_sec

        Default is 900 seconds (15 minutes) for airports with insufficient data.
        """
        self._connect_references = {}
        cursor = self.adl_conn.cursor()

        query = self.adl.format_query(
            "SELECT airport_icao, unimpeded_connect_sec, confidence FROM dbo.airport_connect_reference"
        )
        try:
            cursor.execute(query)
            for row in cursor.fetchall():
                self._connect_references[row[0]] = row[1]
            logger.info(f"Loaded {len(self._connect_references)} airport connect references")
        except Exception as e:
            logger.warning(f"Could not load airport connect references: {e}")
            logger.warning("GS analysis will use 900s default connect time for all airports")
        finally:
            cursor.close()

    def _get_connect_reference(self, airport_icao: str) -> int:
        """Get unimpeded connect-to-push time in seconds for an airport. Default: 900s (15 min)."""
        return self._connect_references.get(airport_icao, 900)

    def _load_airport_facility_map(self):
        """
        Load airport → ARTCC mapping from the apts reference table.

        Used as fallback when fp_dept_artcc is NULL in flight data
        (common for first_seen-only flights without parsed routes).
        Maps ICAO code → RESP_ARTCC_ID (e.g., KJFK → ZNY).
        """
        self._airport_artcc = {}
        cursor = self.adl_conn.cursor()

        query = self.adl.format_query(
            "SELECT ICAO_ID, RESP_ARTCC_ID FROM dbo.apts WHERE ICAO_ID IS NOT NULL AND RESP_ARTCC_ID IS NOT NULL"
        )
        try:
            cursor.execute(query)
            for row in cursor.fetchall():
                self._airport_artcc[row[0]] = row[1].upper()
            logger.info(f"Loaded {len(self._airport_artcc)} airport→ARTCC mappings")
        except Exception as e:
            logger.warning(f"Could not load airport facility map: {e}")
        finally:
            cursor.close()

    def _get_airport_artcc(self, airport_icao: str) -> str:
        """Get responsible ARTCC for an airport (e.g., KJFK → ZNY). Returns '' if unknown."""
        return self._airport_artcc.get(airport_icao, '')

    def _validate_trajectory_quality(self, callsign: str, trajectory: List[dict]) -> tuple:
        """
        Validate trajectory data quality for reliable boundary crossing detection.

        Flights with sparse or incomplete trajectory data (e.g., only departure/arrival
        positions with no enroute data) produce unreliable interpolated boundary crossings.

        Key checks:
        1. Enroute points: Must have actual position updates while flying (gs > 50)
        2. Unique positions: Must have geographic spread (not just sitting at airports)
        3. Implied speed: Large position jumps must have reasonable implied speed

        Returns:
            Tuple of (is_valid, reason_if_invalid)
        """
        if not trajectory or len(trajectory) < 2:
            return False, "insufficient_points"

        # Count points with meaningful groundspeed (enroute, not on ground)
        # This is the PRIMARY check - flights with only ground positions are invalid
        # Use gs_valid flag if available (new format), otherwise check raw gs value
        enroute_points = [p for p in trajectory if p.get('gs_valid', p.get('gs', 0) > 50)]
        if len(enroute_points) < self.MIN_ENROUTE_POINTS:
            return False, f"only_{len(enroute_points)}_enroute_points"

        # Check for unique positions (not just sitting at airport)
        unique_positions = set()
        for p in trajectory:
            # Round to ~1nm precision to identify truly different positions
            pos_key = (round(p['lat'], 2), round(p['lon'], 2))
            unique_positions.add(pos_key)

        if len(unique_positions) < self.MIN_UNIQUE_POSITIONS:
            return False, f"only_{len(unique_positions)}_unique_positions"

        # Check for suspicious position jumps (e.g., SFO->LAS direct with no intermediate data)
        # This catches flights that have some gs>0 points at arrival but no enroute tracking
        sorted_traj = sorted(trajectory, key=lambda p: p['timestamp'])
        for i in range(1, len(sorted_traj)):
            prev = sorted_traj[i - 1]
            curr = sorted_traj[i]

            time_diff_hr = (curr['timestamp'] - prev['timestamp']).total_seconds() / 3600
            if time_diff_hr > 0.05:  # Only check gaps > 3 minutes
                dist_nm = haversine_nm(prev['lat'], prev['lon'], curr['lat'], curr['lon'])
                if dist_nm > 50:  # Only check significant position changes
                    implied_speed = dist_nm / time_diff_hr
                    # SFO->LAS is ~350nm in ~150min = ~140 kts - but this would mean NO intermediate data
                    # A real flight would have ~400 kts average with intermediate points
                    # Only flag if implied speed is suspiciously low (missing data) or very high (corrupt data)
                    if implied_speed < self.MIN_IMPLIED_SPEED_KTS:
                        return False, f"suspicious_jump_{dist_nm:.0f}nm_in_{time_diff_hr*60:.0f}min"

        return True, None

    def analyze(self) -> Dict:
        """Run full compliance analysis"""
        logger.info(f"Starting analysis for: {self.event.name}")

        # Merge user-defined TMIs with auto-parsed TMIs
        if self.event.user_defined_tmis:
            for user_def in self.event.user_defined_tmis:
                user_tmi = user_def.to_tmi(self.event.start_utc, self.event.end_utc)
                self.event.tmis.append(user_tmi)
            logger.info(f"Merged {len(self.event.user_defined_tmis)} user-defined TMIs")

        results = {
            'event': self.event.name,
            'event_start': self.event.start_utc.isoformat(),
            'event_end': self.event.end_utc.isoformat(),
            'generated_utc': datetime.utcnow().isoformat(),
            'summary': {},
            'mit_results': {},
            'gs_results': {},
            'reroute_results': {},
            'apreq_results': {},
            'holding': {},
            'delay_results': [],
            'skipped_lines': [],  # Lines parser could not handle (for user override)
            'user_defined_tmis': []  # User overrides that were applied
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

                # Pre-load ALL flights to/from featured facilities
                # This is the comprehensive approach - gather all flights that could
                # potentially be affected by TMIs, then let trajectory analysis
                # determine actual crossings
                self.flight_data = self._get_all_featured_flights()

                if self.flight_data:
                    self._preload_trajectories([f['callsign'] for f in self.flight_data.values()])

                # Load airport taxi references for GS delay calculation
                self._load_airport_taxi_references()

                # Load airport connect-to-push references for GS hold time adjustment
                self._load_airport_connect_references()

                # Load airport→ARTCC mapping for GS facility scope filtering
                self._load_airport_facility_map()

                # Holding pattern detection (event-wide)
                self._holding_events = self._detect_all_holding_patterns()

                # Analyze by TMI type
                mit_tmis = [t for t in self.event.tmis if t.tmi_type in (TMIType.MIT, TMIType.MINIT)]
                gs_tmis = [t for t in self.event.tmis if t.tmi_type == TMIType.GS]
                reroute_tmis = [t for t in self.event.tmis if t.tmi_type == TMIType.REROUTE]
                apreq_tmis = [t for t in self.event.tmis if t.tmi_type in (TMIType.APREQ, TMIType.CFR)]

                # MIT/MINIT Analysis
                for tmi in mit_tmis:
                    result = self._analyze_mit_compliance(tmi)
                    if result:
                        # Use unique key: type_fix_starttime_value to differentiate multiple TMIs per fix
                        # Include provider/requestor for multi-facility splits so each boundary gets its own result
                        time_key = tmi.start_utc.strftime('%H%M') if tmi.start_utc else 'notime'
                        fac_key = f"_{tmi.requestor}_{tmi.provider}" if (tmi.group_id and tmi.provider) else ''
                        key = f"{tmi.tmi_type.value}_{tmi.fix}_{time_key}_{tmi.value}{fac_key}"
                        # Extract trajectories for separate file output
                        self._mit_trajectories[key] = result.pop('_trajectories', {})
                        results['mit_results'][key] = result

                # Ground Stop Analysis - prefer programs, fall back to individual TMIs
                gs_programs = getattr(self.event, 'gs_programs', [])
                if gs_programs:
                    for program in gs_programs:
                        result = self._analyze_gs_program(program)
                        if result:
                            key = f"GS_{program.airport}"
                            results['gs_results'][key] = result
                        else:
                            logger.warning(f"GS program {program.airport} returned no results (no matching flights?)")

                # Fall back to individual GS TMIs if programs produced nothing
                if not results['gs_results'] and gs_tmis:
                    logger.info("GS programs produced no results, falling back to individual TMI approach")
                    for tmi in gs_tmis:
                        result = self._analyze_gs_compliance(tmi)
                        if result:
                            key = f"GS_{tmi.provider}_{','.join(tmi.destinations)}_ALL"
                            results['gs_results'][key] = result

                # Reroute Analysis - prefer programs, fall back to individual TMIs
                reroute_programs = getattr(self.event, 'reroute_programs', [])
                if reroute_programs:
                    for program in reroute_programs:
                        result = self._analyze_reroute_program(program)
                        if result:
                            key = program.name or f"REROUTE_{program.route_type}_{program.action}"
                            results['reroute_results'][key] = result

                # Fall back to individual reroute TMIs if programs produced nothing
                if not results['reroute_results'] and reroute_tmis:
                    logger.info("Reroute programs produced no results, falling back to individual TMI approach")
                    for tmi in reroute_tmis:
                        result = self._analyze_reroute_compliance(tmi)
                        if result:
                            key = tmi.reroute_name or f"REROUTE_{','.join(tmi.origins[:2])}_{','.join(tmi.destinations[:2])}"
                            results['reroute_results'][key] = result

                # APREQ Tracking (just count flights, no compliance assessment)
                for tmi in apreq_tmis:
                    result = self._track_apreq_flights(tmi)
                    if result:
                        key = f"{tmi.tmi_type.value}_{tmi.fix or 'ALL'}"
                        results['apreq_results'][key] = result

                # Delay Tracking - include parsed delay entries from NTML
                if self.event.delays:
                    results['delay_results'] = self._format_delay_entries()
                    logger.info(f"Included {len(results['delay_results'])} delay entries")

        except Exception as e:
            logger.exception("Analysis failed")
            raise

        # NTML correlation for holding events
        if self.event.delays:
            self._correlate_ntml_holding(self._holding_events, self.event.delays)

        # TMI delay attribution
        self._attribute_holding_to_tmi(self._holding_events, self.event)

        # Build holding results
        results['holding'] = self._build_holding_summary(self._holding_events)

        # Calculate summary
        results['summary'] = self._calculate_summary(results)

        # Include skipped lines for user to potentially define
        if self.event.skipped_lines:
            results['skipped_lines'] = [
                {
                    'line': sl.line,
                    'line_number': sl.line_number,
                    'reason': sl.reason
                }
                for sl in self.event.skipped_lines
            ]
            logger.info(f"Included {len(self.event.skipped_lines)} skipped lines for user review")

        # Include user-defined TMIs that were applied
        if self.event.user_defined_tmis:
            results['user_defined_tmis'] = [
                {
                    'original_line': ud.original_line,
                    'definition_id': ud.definition_id,
                    'tmi_type': ud.tmi_type.value if ud.tmi_type else None,
                    'fix': ud.fix,
                    'destinations': ud.destinations,
                    'origins': ud.origins,
                    'value': ud.value,
                    'notes': ud.notes
                }
                for ud in self.event.user_defined_tmis
            ]

        # Attach trajectory data for split file output (popped by run.py)
        results['_trajectories'] = self._mit_trajectories

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

    def _preload_trajectories(self, callsigns: List[str]):
        """
        Pre-load all trajectory data and PostGIS boundary crossings for given callsigns.

        This is called once at the start of analysis to cache data that would otherwise
        be re-computed for each TMI. Reduces analysis time from ~15 min to ~3-4 min.
        """
        if self._trajectory_cache_loaded:
            return

        if not callsigns:
            logger.info("No callsigns to preload trajectories for")
            return

        cursor = self.adl_conn.cursor()

        # Use widest time window from all TMIs + event times, plus buffer
        earliest, latest = self._get_widest_time_window()
        query_start = earliest - timedelta(hours=1)  # Buffer before
        query_end = latest + timedelta(hours=1)      # Buffer after

        callsign_in = "'" + "','".join(callsigns) + "'"

        logger.info(f"Pre-loading trajectories for {len(callsigns)} flights...")
        logger.info(f"  Trajectory window: {query_start.strftime('%Y-%m-%d %H:%MZ')} to {query_end.strftime('%Y-%m-%d %H:%MZ')}")

        # Load from ALL trajectory sources with priority-based deduplication.
        # Priority: TMI (full resolution) > Live (not yet archived) > Archive (downsampled)
        # This ensures the analyzer always uses the highest resolution data available.
        query = self.adl.format_query(f"""
            WITH all_trajectory AS (
                SELECT c.callsign, t.flight_uid, t.timestamp_utc,
                       t.lat, t.lon, t.groundspeed_kts, t.altitude_ft,
                       p.fp_dept_icao, p.fp_dest_icao,
                       t.tmi_tier, 'TMI' AS source_table,
                       1 AS source_priority
                FROM dbo.adl_tmi_trajectory t
                JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid
                JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
                WHERE t.timestamp_utc >= %s AND t.timestamp_utc <= %s
                  AND c.callsign IN ({callsign_in})
                UNION ALL
                SELECT c.callsign, t.flight_uid, t.recorded_utc,
                       t.lat, t.lon, t.groundspeed_kts, t.altitude_ft,
                       p.fp_dept_icao, p.fp_dest_icao,
                       NULL AS tmi_tier, 'LIVE' AS source_table,
                       2 AS source_priority
                FROM dbo.adl_flight_trajectory t
                JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid
                JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
                WHERE t.recorded_utc >= %s AND t.recorded_utc <= %s
                  AND c.callsign IN ({callsign_in})
                UNION ALL
                SELECT a.callsign, a.flight_uid, a.timestamp_utc,
                       a.lat, a.lon, a.groundspeed_kts, a.altitude_ft,
                       p.fp_dept_icao, p.fp_dest_icao,
                       NULL AS tmi_tier, 'ARCHIVE' AS source_table,
                       3 AS source_priority
                FROM dbo.adl_trajectory_archive a
                JOIN dbo.adl_flight_plan p ON a.flight_uid = p.flight_uid
                WHERE a.timestamp_utc >= %s AND a.timestamp_utc <= %s
                  AND a.callsign IN ({callsign_in})
            ),
            ranked AS (
                SELECT *, ROW_NUMBER() OVER (
                    PARTITION BY callsign, timestamp_utc
                    ORDER BY source_priority
                ) AS rn
                FROM all_trajectory
            )
            SELECT callsign, flight_uid, timestamp_utc,
                   lat, lon, groundspeed_kts, altitude_ft,
                   fp_dept_icao, fp_dest_icao,
                   tmi_tier, source_table
            FROM ranked
            WHERE rn = 1
            ORDER BY callsign, timestamp_utc
        """)
        cursor.execute(query, (
            query_start.strftime('%Y-%m-%d %H:%M:%S'),
            query_end.strftime('%Y-%m-%d %H:%M:%S'),
            query_start.strftime('%Y-%m-%d %H:%M:%S'),
            query_end.strftime('%Y-%m-%d %H:%M:%S'),
            query_start.strftime('%Y-%m-%d %H:%M:%S'),
            query_end.strftime('%Y-%m-%d %H:%M:%S')
        ))

        # Group by callsign and track source/tier distribution
        tier_counts = {0: 0, 1: 0, 2: 0, None: 0}
        source_counts = {'TMI': 0, 'LIVE': 0, 'ARCHIVE': 0}
        for row in cursor.fetchall():
            cs, fuid, ts, lat, lon, gs, alt, dept, dest, tmi_tier, source_table = row

            if cs not in self._trajectory_cache:
                self._trajectory_cache[cs] = []
                self._trajectory_metadata[cs] = {
                    'flight_uid': fuid,
                    'dept': dept or 'UNK',
                    'dest': dest or 'UNK',
                    'tmi_tier': tmi_tier,
                    'source': source_table
                }
                tier_counts[tmi_tier] = tier_counts.get(tmi_tier, 0) + 1
                source_counts[source_table] = source_counts.get(source_table, 0) + 1

            self._trajectory_cache[cs].append({
                'timestamp': normalize_datetime(ts),
                'lat': float(lat),
                'lon': float(lon),
                'gs': float(gs) if gs else 0,
                'gs_valid': bool(gs and 100 < gs < 600),
                'alt': float(alt) if alt else 0
            })

        cursor.close()
        total = len(self._trajectory_cache)
        tmi_count = tier_counts.get(0, 0) + tier_counts.get(1, 0) + tier_counts.get(2, 0)
        archive_count = tier_counts.get(None, 0)
        logger.info(f"  Cached trajectories for {total} flights")
        logger.info(f"  Sources: TMI={source_counts.get('TMI', 0)}, Live={source_counts.get('LIVE', 0)}, Archive={source_counts.get('ARCHIVE', 0)}")
        logger.info(f"  TMI tiers: T-0={tier_counts.get(0, 0)}, T-1={tier_counts.get(1, 0)}, T-2={tier_counts.get(2, 0)}, non-TMI={archive_count}")
        if total > 0:
            logger.info(f"  Highest-res coverage: {tmi_count + source_counts.get('LIVE', 0)}/{total} flights ({(tmi_count + source_counts.get('LIVE', 0))/total*100:.0f}%)")

        # Pre-compute PostGIS boundary crossings for all flights with GIS
        if self.gis_conn and self._trajectory_cache:
            self._precompute_boundary_crossings()

        self._trajectory_cache_loaded = True

    def _precompute_boundary_crossings(self):
        """
        Pre-compute PostGIS boundary crossings for all cached trajectories.

        This is the expensive operation - calling PostGIS for each flight.
        By doing it once upfront, we avoid re-computing for each TMI.

        Flights with low-quality trajectory data (sparse, missing enroute positions)
        are flagged and excluded from boundary crossing analysis to prevent
        unreliable interpolated results.
        """
        if not self.gis_conn:
            return

        gis_cursor = self.gis_conn.cursor()
        processed = 0
        skipped_quality = 0
        total = len(self._trajectory_cache)

        logger.info(f"Pre-computing boundary crossings for {total} flights...")

        for callsign, trajectory in self._trajectory_cache.items():
            if len(trajectory) < 2:
                continue

            # Validate trajectory quality before computing crossings
            is_valid, reason = self._validate_trajectory_quality(callsign, trajectory)
            if not is_valid:
                self._low_quality_flights.add(callsign)
                skipped_quality += 1
                logger.debug(f"  Skipping {callsign}: low quality trajectory ({reason})")
                continue

            # Build waypoints JSON for PostGIS
            waypoints = [
                {'lat': pt['lat'], 'lon': pt['lon'], 'sequence_num': i}
                for i, pt in enumerate(trajectory)
            ]
            waypoints_json = json.dumps(waypoints)

            try:
                # Try get_trajectory_all_crossings (handles ARTCC + TRACON)
                try:
                    gis_cursor.execute('''
                        SELECT boundary_code, crossing_lat, crossing_lon, crossing_fraction,
                               boundary_type, crossing_type
                        FROM get_trajectory_all_crossings(%s::jsonb)
                        ORDER BY crossing_fraction,
                                 CASE crossing_type WHEN 'EXIT' THEN 0 ELSE 1 END
                    ''', (waypoints_json,))
                    rows = gis_cursor.fetchall()
                    self._crossing_cache[callsign] = [
                        {
                            'facility_code': r[0],
                            'lat': float(r[1]),
                            'lon': float(r[2]),
                            'fraction': float(r[3]),
                            'facility_type': r[4] if len(r) > 4 else 'ARTCC',
                            'crossing_type': r[5] if len(r) > 5 else 'UNKNOWN'
                        }
                        for r in rows
                    ]
                except Exception:
                    # Fall back to ARTCC-only function
                    gis_cursor.execute('''
                        SELECT artcc_code, crossing_lat, crossing_lon, crossing_fraction
                        FROM get_trajectory_artcc_crossings(%s::jsonb)
                        ORDER BY crossing_fraction
                    ''', (waypoints_json,))
                    rows = gis_cursor.fetchall()
                    self._crossing_cache[callsign] = [
                        {
                            'facility_code': r[0],
                            'lat': float(r[1]),
                            'lon': float(r[2]),
                            'fraction': float(r[3]),
                            'facility_type': 'ARTCC',
                            'crossing_type': 'UNKNOWN'
                        }
                        for r in rows
                    ]

                processed += 1
                if processed % 50 == 0:
                    logger.info(f"  Processed {processed}/{total} flights...")

            except Exception as e:
                logger.debug(f"Error computing crossings for {callsign}: {e}")
                self._crossing_cache[callsign] = []

        gis_cursor.close()
        logger.info(f"  Cached boundary crossings for {len(self._crossing_cache)} flights")
        if skipped_quality > 0:
            logger.warning(f"  Skipped {skipped_quality} flights with low-quality trajectory data")

    def _filter_flights_by_scope(self, tmi: TMI) -> Dict[str, Any]:
        """
        Filter the comprehensive flight set by TMI scope (destination/origin only).

        NO route filtering - let trajectory analysis determine actual crossings.
        This ensures we don't miss flights due to missing fp_route_expanded data.

        Args:
            tmi: TMI object with destinations/origins to filter by

        Returns:
            Dict mapping callsign -> flight metadata
        """
        if not self.flight_data:
            logger.warning("No flight data loaded - call _get_all_featured_flights() first")
            return {}

        # Normalize destination/origin codes for matching
        normalized_dests = set(normalize_icao_list(tmi.destinations)) if tmi.destinations else set()
        normalized_origs = set(normalize_icao_list(tmi.origins)) if tmi.origins else set()

        filtered = {}
        for fuid, flight in self.flight_data.items():
            dest = flight.get('dest', '')
            dept = flight.get('dept', '')

            # Check destination filter (if specified)
            if normalized_dests and dest not in normalized_dests:
                continue

            # Check origin filter (if specified)
            if normalized_origs and dept not in normalized_origs:
                continue

            filtered[fuid] = flight

        logger.debug(f"  Filtered to {len(filtered)} flights (dest={tmi.destinations}, orig={tmi.origins})")
        return filtered

    def _get_flights_for_tmi(self, tmi: TMI) -> Dict[str, Any]:
        """
        Get flights affected by a TMI based on its scope.

        Filters by:
        - Destination airports (from TMI destinations)
        - Origin airports (from TMI origins, if specified)
        - Route fix (from TMI fix, if specified) - ensures flight is routed via the fix
        - Time window (flights active during TMI period)
        """
        cursor = self.adl_conn.cursor()
        flights = {}

        dest_filter = ""
        orig_filter = ""
        route_filter = ""

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

        # CRITICAL: Filter by route fix to ensure flights are actually routed via the TMI fix
        # This prevents including flights on parallel routes that happen to pass near the fix
        if tmi.fix:
            # Check: 1) fix in expanded route (space-bounded), 2) arrival fix, 3) STAR named after fix
            # STARs are named {FIX}{version} e.g. DADES2, MAATY5 — flights on these STARs cross the fix
            # 4) fix in parsed waypoints — catches flights where expanded route is NULL but
            #    waypoint parser resolved the fix (e.g. via airway/procedure expansion)
            route_filter = f"""AND (
                p.fp_route_expanded LIKE '% {tmi.fix} %'
                OR p.fp_route_expanded LIKE '{tmi.fix} %'
                OR p.fp_route_expanded LIKE '% {tmi.fix}'
                OR p.afix = '{tmi.fix}'
                OR p.star_name LIKE '{tmi.fix}%'
                OR EXISTS (
                    SELECT 1 FROM dbo.adl_flight_waypoints w
                    WHERE w.flight_uid = c.flight_uid AND w.fix_name = '{tmi.fix}'
                )
            )"""
            logger.info(f"Route filter: flights via {tmi.fix} (expanded route/afix/STAR/waypoints)")

        # Use WIDEST window
        tmi_start = tmi.start_utc
        tmi_end = tmi.get_effective_end()
        query_start = min(self.event.start_utc, tmi_start)
        query_end = max(self.event.end_utc, tmi_end)

        # Format query for current driver (pymssql uses %s, pyodbc uses ?)
        # Include route_expanded and afix for debugging
        query = self.adl.format_query(f"""
            SELECT DISTINCT c.callsign, c.flight_uid, p.fp_dept_icao, p.fp_dest_icao,
                   c.first_seen_utc, c.last_seen_utc, p.fp_route_expanded, p.afix
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            WHERE c.first_seen_utc <= %s
              AND c.last_seen_utc >= %s
              {dest_filter}
              {orig_filter}
              {route_filter}
        """)
        cursor.execute(query, (
            query_end.strftime('%Y-%m-%d %H:%M:%S'),
            query_start.strftime('%Y-%m-%d %H:%M:%S')
        ))

        for row in cursor.fetchall():
            flight_uid = row[1]
            flights[flight_uid] = {
                'callsign': row[0],
                'flight_uid': flight_uid,
                'dept': row[2],
                'dest': row[3],
                'first_seen': normalize_datetime(row[4]),
                'last_seen': normalize_datetime(row[5]),
                'route_expanded': row[6] if len(row) > 6 else None,
                'afix': row[7] if len(row) > 7 else None
            }

        cursor.close()
        logger.info(f"  Found {len(flights)} flights matching route filter")
        return flights

    def _detect_crossings(self, fix_name: str, fix_lat: float, fix_lon: float,
                          callsigns: List[str], tmi: TMI) -> List[CrossingResult]:
        """
        Detect fix crossings using trajectory data with segment interpolation.

        When the trajectory cache is loaded (normal path), uses full trajectory
        data without bbox limitations and interpolates between consecutive points
        to find crossings even when no actual trajectory point falls near the fix.

        This handles the common case of Tier 4 cruise (5-min intervals, ~33nm gaps)
        where flights cross a fix but have no trajectory point within the bbox.

        Falls back to bbox-filtered SQL queries when cache is not loaded.
        """
        callsigns = [cs for cs in callsigns if cs not in self._low_quality_flights]

        tmi_start = tmi.start_utc
        tmi_end = tmi.get_effective_end()

        if self._trajectory_cache_loaded:
            return self._detect_crossings_interpolated(
                fix_name, fix_lat, fix_lon, callsigns, tmi_start, tmi_end
            )

        return self._detect_crossings_bbox(
            fix_name, fix_lat, fix_lon, callsigns, tmi_start, tmi_end
        )

    def _detect_crossings_interpolated(self, fix_name: str, fix_lat: float, fix_lon: float,
                                        callsigns: List[str], tmi_start, tmi_end) -> List[CrossingResult]:
        """
        Detect crossings using cached trajectories with segment interpolation.

        For each flight, scans all consecutive trajectory point pairs and computes
        the closest approach of each segment to the fix. This catches crossings
        even when trajectory resolution is sparse (Tier 4 = 5 min intervals).
        """
        crossings = []

        for callsign in callsigns:
            trajectory = self._trajectory_cache.get(callsign, [])
            if len(trajectory) < 2:
                continue

            metadata = self._trajectory_metadata.get(callsign, {})
            best_dist = float('inf')
            best_result = None
            best_seg_idx = -1

            for i in range(len(trajectory) - 1):
                p1 = trajectory[i]
                p2 = trajectory[i + 1]

                # Geometry-aware pre-filter: skip segments that can't possibly
                # pass within CROSSING_RADIUS of the fix. For a segment of length L,
                # the closest-approach point could be at most L/2 closer than the
                # nearest endpoint, so skip if min(d1,d2) > L/2 + radius.
                d1 = haversine_nm(p1['lat'], p1['lon'], fix_lat, fix_lon)
                d2 = haversine_nm(p2['lat'], p2['lon'], fix_lat, fix_lon)
                seg_len = haversine_nm(p1['lat'], p1['lon'], p2['lat'], p2['lon'])
                if min(d1, d2) > seg_len / 2.0 + CROSSING_RADIUS_NM:
                    continue

                min_dist, frac, interp_lat, interp_lon = closest_approach_on_segment(
                    p1['lat'], p1['lon'], p2['lat'], p2['lon'], fix_lat, fix_lon
                )

                if min_dist >= best_dist:
                    continue

                # Interpolate crossing time
                t1 = p1['timestamp']
                t2 = p2['timestamp']
                dt = (t2 - t1).total_seconds()
                interp_time = t1 + timedelta(seconds=dt * frac)

                if interp_time < tmi_start or interp_time > tmi_end:
                    continue

                best_dist = min_dist
                best_seg_idx = i
                gs1 = p1['gs'] if p1['gs_valid'] else 250
                gs2 = p2['gs'] if p2['gs_valid'] else 250

                best_result = CrossingResult(
                    callsign=callsign,
                    flight_uid=metadata.get('flight_uid', 0),
                    crossing_time=interp_time,
                    distance_nm=min_dist,
                    lat=interp_lat,
                    lon=interp_lon,
                    groundspeed=gs1 + frac * (gs2 - gs1),
                    altitude=p1['alt'] + frac * (p2['alt'] - p1['alt']),
                    dept=metadata.get('dept', 'UNK'),
                    dest=metadata.get('dest', 'UNK')
                )

            if best_result and best_dist <= CROSSING_RADIUS_NM:
                best_result.bearing = compute_approach_bearing(
                    trajectory, best_result.lat, best_result.lon, best_seg_idx
                )
                crossings.append(best_result)

        logger.info(f"  Fix crossings ({fix_name}): {len(crossings)} detected "
                     f"(segment interpolation, {len(callsigns)} candidates)")
        return crossings

    def _detect_crossings_bbox(self, fix_name: str, fix_lat: float, fix_lon: float,
                                callsigns: List[str], tmi_start, tmi_end) -> List[CrossingResult]:
        """Fallback: detect crossings using bbox-filtered SQL queries (no cache)."""
        crossings = []
        cursor = self.adl_conn.cursor()
        lat_margin = 0.18
        lon_margin = 0.24
        callsign_in = "'" + "','".join(callsigns) + "'"

        query = self.adl.format_query(f"""
            WITH trajectory_points AS (
                SELECT c.callsign, t.flight_uid, t.timestamp_utc,
                       t.lat, t.lon, t.groundspeed_kts, t.altitude_ft,
                       p.fp_dept_icao, p.fp_dest_icao, 1 AS source_priority
                FROM dbo.adl_tmi_trajectory t
                JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid
                JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
                WHERE t.timestamp_utc >= %s AND t.timestamp_utc <= %s
                  AND c.callsign IN ({callsign_in})
                  AND t.lat BETWEEN %s AND %s AND t.lon BETWEEN %s AND %s
                UNION ALL
                SELECT c.callsign, t.flight_uid, t.recorded_utc,
                       t.lat, t.lon, t.groundspeed_kts, t.altitude_ft,
                       p.fp_dept_icao, p.fp_dest_icao, 2 AS source_priority
                FROM dbo.adl_flight_trajectory t
                JOIN dbo.adl_flight_core c ON t.flight_uid = c.flight_uid
                JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
                WHERE t.recorded_utc >= %s AND t.recorded_utc <= %s
                  AND c.callsign IN ({callsign_in})
                  AND t.lat BETWEEN %s AND %s AND t.lon BETWEEN %s AND %s
                UNION ALL
                SELECT t.callsign, t.flight_uid, t.timestamp_utc,
                       t.lat, t.lon, t.groundspeed_kts, t.altitude_ft,
                       p.fp_dept_icao, p.fp_dest_icao, 3 AS source_priority
                FROM dbo.adl_trajectory_archive t
                JOIN dbo.adl_flight_plan p ON t.flight_uid = p.flight_uid
                WHERE t.timestamp_utc >= %s AND t.timestamp_utc <= %s
                  AND t.callsign IN ({callsign_in})
                  AND t.lat BETWEEN %s AND %s AND t.lon BETWEEN %s AND %s
            ),
            ranked AS (
                SELECT *, ROW_NUMBER() OVER (
                    PARTITION BY callsign, timestamp_utc ORDER BY source_priority
                ) AS rn FROM trajectory_points
            )
            SELECT callsign, flight_uid, timestamp_utc, lat, lon,
                   groundspeed_kts, altitude_ft, fp_dept_icao, fp_dest_icao
            FROM ranked WHERE rn = 1
            ORDER BY callsign, timestamp_utc
        """)
        cursor.execute(query, (
            tmi_start.strftime('%Y-%m-%d %H:%M:%S'), tmi_end.strftime('%Y-%m-%d %H:%M:%S'),
            fix_lat - lat_margin, fix_lat + lat_margin, fix_lon - lon_margin, fix_lon + lon_margin,
            tmi_start.strftime('%Y-%m-%d %H:%M:%S'), tmi_end.strftime('%Y-%m-%d %H:%M:%S'),
            fix_lat - lat_margin, fix_lat + lat_margin, fix_lon - lon_margin, fix_lon + lon_margin,
            tmi_start.strftime('%Y-%m-%d %H:%M:%S'), tmi_end.strftime('%Y-%m-%d %H:%M:%S'),
            fix_lat - lat_margin, fix_lat + lat_margin, fix_lon - lon_margin, fix_lon + lon_margin
        ))
        positions = cursor.fetchall()
        cursor.close()
        logger.info(f"  Found {len(positions)} trajectory points near {fix_name} (bbox fallback)")

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
                        callsign=callsign, flight_uid=fuid,
                        crossing_time=normalize_datetime(ts), distance_nm=dist,
                        lat=lat, lon=lon,
                        groundspeed=float(gs) if gs and 100 < gs < 600 else 250,
                        altitude=float(alt) if alt else 0,
                        dept=dept or 'UNK', dest=dest or 'UNK'
                    )
            if closest_pos and closest_dist <= CROSSING_RADIUS_NM:
                # bbox fallback has no upstream trajectory data — leave bearing None
                # (approach bearing requires full trajectory, only available in cache path)
                crossings.append(closest_pos)

        return crossings

    def _detect_boundary_crossings(self, provider: str, requestor: str,
                                    callsigns: List[str], tmi: TMI) -> List[BoundaryCrossing]:
        """
        Detect facility boundary crossings using CACHED PostGIS results.

        Uses pre-computed boundary crossings from _crossing_cache for performance.
        Falls back to on-demand PostGIS queries if cache is not available.

        Args:
            provider: Facility code of the TMI provider (ARTCC or TRACON)
            requestor: Facility code of the TMI requestor (ARTCC or TRACON)
            callsigns: List of flight callsigns to analyze
            tmi: TMI definition with time window

        Returns:
            List of BoundaryCrossing objects for flights crossing the provider->requestor boundary
        """
        if not self.gis_conn and not self._crossing_cache:
            logger.warning("GIS connection not available for boundary crossing detection")
            return []

        if not provider or not requestor:
            logger.warning("Provider and requestor required for boundary crossing detection")
            return []

        crossings = []

        # Filter out flights with low-quality trajectory data
        original_count = len(callsigns)
        low_quality_in_list = [cs for cs in callsigns if cs in self._low_quality_flights]
        callsigns = [cs for cs in callsigns if cs not in self._low_quality_flights]
        skipped_count = original_count - len(callsigns)
        if skipped_count > 0:
            logger.info(f"  Skipped {skipped_count} flights with low-quality trajectory data")
            if low_quality_in_list and len(low_quality_in_list) <= 10:
                logger.debug(f"    Low quality flights: {low_quality_in_list}")

        # Classify facilities and normalize codes
        provider_type = classify_facility(provider)
        requestor_type = classify_facility(requestor)
        provider_codes = self._normalize_facility_code(provider)
        requestor_codes = self._normalize_facility_code(requestor)

        logger.info(f"Detecting boundary crossings: {provider} ({provider_type}) -> {requestor} ({requestor_type})")
        logger.info(f"  Provider codes: {provider_codes}, Requestor codes: {requestor_codes}")

        # Use cached trajectories if available
        if self._trajectory_cache_loaded:
            flight_trajectories = {cs: self._trajectory_cache.get(cs, []) for cs in callsigns if cs in self._trajectory_cache}
            flight_metadata = {cs: self._trajectory_metadata.get(cs, {}) for cs in callsigns if cs in self._trajectory_metadata}
            logger.info(f"  Using cached trajectories for {len(flight_trajectories)} flights")
        else:
            # Fallback: load on-demand (slow path)
            cursor = self.adl_conn.cursor()
            tmi_start = tmi.start_utc
            tmi_end = tmi.get_effective_end()
            callsign_in = "'" + "','".join(callsigns) + "'"

            # Use unified view: TMI high-res data preferred, archive as fallback
            query = self.adl.format_query(f"""
                SELECT v.callsign, v.flight_uid, v.timestamp_utc,
                       v.lat, v.lon, v.groundspeed_kts, v.altitude_ft,
                       p.fp_dept_icao, p.fp_dest_icao,
                       v.tmi_tier, v.source_table
                FROM dbo.vw_trajectory_tmi_complete v
                INNER JOIN dbo.adl_flight_plan p ON v.flight_uid = p.flight_uid
                WHERE v.timestamp_utc >= %s
                  AND v.timestamp_utc <= %s
                  AND v.callsign IN ({callsign_in})
                ORDER BY v.callsign, v.timestamp_utc
            """)
            cursor.execute(query, (
                tmi_start.strftime('%Y-%m-%d %H:%M:%S'),
                tmi_end.strftime('%Y-%m-%d %H:%M:%S')
            ))

            flight_trajectories = defaultdict(list)
            flight_metadata = {}
            tmi_count = 0
            archive_count = 0

            for row in cursor.fetchall():
                cs, fuid, ts, lat, lon, gs, alt, dept, dest, tmi_tier, source_table = row
                flight_trajectories[cs].append({
                    'timestamp': normalize_datetime(ts),
                    'lat': float(lat),
                    'lon': float(lon),
                    # Store RAW groundspeed for validation consistency
                    'gs': float(gs) if gs else 0,
                    'gs_valid': bool(gs and 100 < gs < 600),
                    'alt': float(alt) if alt else 0
                })
                if cs not in flight_metadata:
                    flight_metadata[cs] = {
                        'flight_uid': fuid,
                        'dept': dept or 'UNK',
                        'dest': dest or 'UNK',
                        'tmi_tier': tmi_tier,
                        'source': source_table
                    }
                    if source_table == 'TMI':
                        tmi_count += 1
                    else:
                        archive_count += 1

            cursor.close()
            total = len(flight_trajectories)
            logger.info(f"  Loaded trajectories for {total} flights (on-demand via unified view)")
            if total > 0:
                logger.info(f"  TMI coverage: {tmi_count}/{total} flights ({tmi_count/total*100:.0f}%), Archive fallback: {archive_count}")

        # When requestor is a TRACON or airport (not ARTCC), PostGIS may not
        # reliably detect the TRACON entry due to sparse trajectory data.
        # Interpret the facility pair: e.g., ZJX:TPA means ZJX ARTCC -> TPA TRACON.
        # The handoff occurs where the flight exits the provider ARTCC, which shares
        # a boundary edge with the requestor TRACON. So "exit provider ARTCC" is a
        # valid proxy for "enter requestor TRACON".
        use_provider_exit_fallback = requestor_type in ('TRACON', 'AIRPORT')

        # Process each flight using CACHED boundary crossings
        for callsign in callsigns:
            trajectory = flight_trajectories.get(callsign, [])
            if len(trajectory) < 2:
                continue

            # Validate trajectory quality for flights not already validated during caching
            if callsign not in self._trajectory_cache:
                is_valid, reason = self._validate_trajectory_quality(callsign, trajectory)
                if not is_valid:
                    logger.debug(f"  Skipping {callsign}: low quality trajectory ({reason})")
                    continue

            # Get cached boundary crossings or compute on-demand
            if callsign in self._crossing_cache:
                boundary_crossings_data = self._crossing_cache[callsign]
            elif self.gis_conn:
                boundary_crossings_data = self._compute_boundary_crossings_for_flight(callsign, trajectory)
            else:
                continue

            if not boundary_crossings_data:
                continue

            # Find the provider->requestor boundary crossing
            found = False
            prev_facility = None
            last_provider_crossing = None  # Track last point in provider ARTCC

            for crossing_data in boundary_crossings_data:
                facility_code = crossing_data['facility_code']
                clat = crossing_data['lat']
                clon = crossing_data['lon']
                cfrac = crossing_data['fraction']

                # Check if this is the boundary we're looking for
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
                    # Found exact provider->requestor handoff
                    crossing_time, crossing_gs, crossing_alt, crossing_bearing = self._interpolate_crossing_time(
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
                            crossing_type='ENTRY',
                            bearing=crossing_bearing
                        )
                        crossings.append(crossing)
                        found = True
                        break

                # Track when the flight leaves the provider ARTCC (for fallback)
                if is_provider_match and use_provider_exit_fallback:
                    last_provider_crossing = {
                        'lat': clat, 'lon': clon, 'fraction': cfrac,
                        'prev_facility': prev_facility, 'next_facility': facility_code
                    }

                prev_facility = facility_code

            # Fallback: if requestor is TRACON/airport but PostGIS didn't detect
            # the TRACON entry, use the provider ARTCC exit point instead.
            # The provider ARTCC exit IS the TRACON entry (shared boundary edge).
            if not found and last_provider_crossing and use_provider_exit_fallback:
                lpc = last_provider_crossing
                crossing_time, crossing_gs, crossing_alt, crossing_bearing = self._interpolate_crossing_time(
                    trajectory, lpc['fraction']
                )
                if crossing_time:
                    meta = flight_metadata.get(callsign, {})
                    crossing = BoundaryCrossing(
                        callsign=callsign,
                        flight_uid=meta.get('flight_uid', ''),
                        crossing_time=crossing_time,
                        crossing_lat=float(lpc['lat']),
                        crossing_lon=float(lpc['lon']),
                        from_artcc=lpc['prev_facility'] or provider,
                        to_artcc=requestor,
                        groundspeed=crossing_gs,
                        altitude=crossing_alt,
                        dept=meta.get('dept', 'UNK'),
                        dest=meta.get('dest', 'UNK'),
                        distance_from_origin_nm=lpc['fraction'] * self._estimate_route_length(trajectory),
                        crossing_type='ENTRY',
                        bearing=crossing_bearing
                    )
                    crossings.append(crossing)

        logger.info(f"  Boundary crossings ({provider}->{requestor}): {len(crossings)}")
        return crossings

    def _compute_boundary_crossings_for_flight(self, callsign: str, trajectory: List[dict]) -> List[dict]:
        """Compute boundary crossings on-demand for a single flight (fallback when cache miss)"""
        if not self.gis_conn or len(trajectory) < 2:
            return []

        gis_cursor = self.gis_conn.cursor()
        waypoints = [
            {'lat': pt['lat'], 'lon': pt['lon'], 'sequence_num': i}
            for i, pt in enumerate(trajectory)
        ]
        waypoints_json = json.dumps(waypoints)

        try:
            try:
                gis_cursor.execute('''
                    SELECT boundary_code, crossing_lat, crossing_lon, crossing_fraction,
                           boundary_type, crossing_type
                    FROM get_trajectory_all_crossings(%s::jsonb)
                    ORDER BY crossing_fraction,
                             CASE crossing_type WHEN 'EXIT' THEN 0 ELSE 1 END
                ''', (waypoints_json,))
                rows = gis_cursor.fetchall()
                result = [
                    {
                        'facility_code': r[0],
                        'lat': float(r[1]),
                        'lon': float(r[2]),
                        'fraction': float(r[3]),
                        'facility_type': r[4] if len(r) > 4 else 'ARTCC',
                        'crossing_type': r[5] if len(r) > 5 else 'UNKNOWN'
                    }
                    for r in rows
                ]
            except Exception:
                gis_cursor.execute('''
                    SELECT artcc_code, crossing_lat, crossing_lon, crossing_fraction
                    FROM get_trajectory_artcc_crossings(%s::jsonb)
                    ORDER BY crossing_fraction
                ''', (waypoints_json,))
                rows = gis_cursor.fetchall()
                result = [
                    {
                        'facility_code': r[0],
                        'lat': float(r[1]),
                        'lon': float(r[2]),
                        'fraction': float(r[3]),
                        'facility_type': 'ARTCC',
                        'crossing_type': 'UNKNOWN'
                    }
                    for r in rows
                ]

            # Cache for future use
            self._crossing_cache[callsign] = result
            return result

        except Exception as e:
            logger.debug(f"Error computing crossings for {callsign}: {e}")
            return []
        finally:
            gis_cursor.close()

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
            Tuple of (crossing_time, groundspeed, altitude, bearing) or (None, 0, 0, None)
        """
        if not trajectory or len(trajectory) < 2:
            return None, 0, 0, None

        # Calculate cumulative distances
        cumulative_dist = [0.0]
        for i in range(1, len(trajectory)):
            prev = trajectory[i - 1]
            curr = trajectory[i]
            dist = haversine_nm(prev['lat'], prev['lon'], curr['lat'], curr['lon'])
            cumulative_dist.append(cumulative_dist[-1] + dist)

        total_dist = cumulative_dist[-1]
        if total_dist <= 0:
            return None, 0, 0, None

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
                prev_gs = prev['gs'] if prev['gs_valid'] else 250
                curr_gs = curr['gs'] if curr['gs_valid'] else 250
                crossing_gs = prev_gs + (curr_gs - prev_gs) * seg_frac
                crossing_alt = prev['alt'] + (curr['alt'] - prev['alt']) * seg_frac

                # Compute approach bearing (from ~75nm upstream, not segment heading)
                crossing_lat = prev['lat'] + seg_frac * (curr['lat'] - prev['lat'])
                crossing_lon = prev['lon'] + seg_frac * (curr['lon'] - prev['lon'])
                bearing = compute_approach_bearing(
                    trajectory, crossing_lat, crossing_lon, i - 1
                )

                return crossing_time, crossing_gs, crossing_alt, bearing

        # Default to last point
        last = trajectory[-1]
        last_gs = last['gs'] if last['gs_valid'] else 250
        return last['timestamp'], last_gs, last['alt'], None

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

    # ------------------------------------------------------------------
    # Holding pattern: fix matching
    # ------------------------------------------------------------------

    def _load_flight_waypoints(self, flight_uids: list) -> dict:
        """Load route waypoints for a batch of flights.
        Returns dict: {flight_uid: [{fix_name, lat, lon, sequence_num}, ...]}
        """
        if not flight_uids:
            return {}

        placeholders = ','.join(['%s'] * len(flight_uids))
        query = self.adl.format_query(
            f"""SELECT flight_uid, fix_name, lat, lon, sequence_num
                FROM dbo.adl_flight_waypoints
                WHERE flight_uid IN ({placeholders})
                AND fix_name IS NOT NULL AND lat IS NOT NULL
                ORDER BY flight_uid, sequence_num"""
        )
        cursor = self.adl_conn.cursor()
        cursor.execute(query, tuple(flight_uids))

        waypoints = {}
        for row in cursor.fetchall():
            uid = row[0]
            if uid not in waypoints:
                waypoints[uid] = []
            waypoints[uid].append({
                'fix_name': row[1],
                'lat': float(row[2]),
                'lon': float(row[3]),
                'sequence_num': row[4]
            })
        return waypoints

    def _load_star_fixes(self, dest_icao: str) -> list:
        """Load all fixes on published STARs for a destination airport.
        Returns list of {fix_name, lat, lon}.
        """
        query = self.adl.format_query(
            """SELECT DISTINCT nf.fix_name, nf.lat, nf.lon
               FROM dbo.nav_procedure_legs npl
               JOIN dbo.nav_procedures np ON npl.procedure_id = np.procedure_id
               JOIN dbo.nav_fixes nf ON npl.fix_name = nf.fix_name
               WHERE np.airport_icao = %s AND np.procedure_type = 'STAR'
               AND nf.lat IS NOT NULL"""
        )
        cursor = self.adl_conn.cursor()
        cursor.execute(query, (dest_icao,))
        return [{'fix_name': r[0], 'lat': float(r[1]), 'lon': float(r[2])}
                for r in cursor.fetchall()]

    def _match_holding_fix(self, event: dict, flight_uid: int, dest_icao: str,
                           flight_waypoints: dict, star_cache: dict) -> dict:
        """Match a holding event's center to the best fix.
        Priority: route waypoints > STAR fixes > any nav_fix.
        Modifies event dict in place.
        """
        center_lat = event['center_lat']
        center_lon = event['center_lon']
        best_fix = None
        best_dist = HOLD_FIX_MATCH_RADIUS_NM
        best_source = None

        # Priority 1: Flight's own route waypoints
        wps = flight_waypoints.get(flight_uid, [])
        for wp in wps:
            d = haversine_nm(center_lat, center_lon, wp['lat'], wp['lon'])
            if d < best_dist:
                best_fix = wp['fix_name']
                best_dist = d
                best_source = 'route'

        # Priority 2: STAR fixes for destination (only if no route match within 3nm)
        if best_source != 'route' or best_dist > 3.0:
            if dest_icao and dest_icao not in star_cache:
                star_cache[dest_icao] = self._load_star_fixes(dest_icao)
            for sf in star_cache.get(dest_icao, []):
                d = haversine_nm(center_lat, center_lon, sf['lat'], sf['lon'])
                if d < best_dist:
                    best_fix = sf['fix_name']
                    best_dist = d
                    best_source = 'star'

        # Priority 3: Any nav_fix (from preloaded fix_coords)
        if best_source is None:
            for fix_name, coords in self.fix_coords.items():
                d = haversine_nm(center_lat, center_lon, coords['lat'], coords['lon'])
                if d < best_dist:
                    best_fix = fix_name
                    best_dist = d
                    best_source = 'navfix'

            # If fix_coords doesn't cover the area, do a targeted query
            if best_source is None:
                nearby = self._query_nearby_fixes(center_lat, center_lon, HOLD_FIX_MATCH_RADIUS_NM)
                for nf in nearby:
                    d = haversine_nm(center_lat, center_lon, nf['lat'], nf['lon'])
                    if d < best_dist:
                        best_fix = nf['fix_name']
                        best_dist = d
                        best_source = 'navfix'

        event['matched_fix'] = best_fix
        event['fix_match_source'] = best_source
        event['fix_distance_nm'] = round(best_dist, 2) if best_fix else 0
        event['fix_on_route'] = (best_source == 'route')
        return event

    def _query_nearby_fixes(self, lat: float, lon: float, radius_nm: float) -> list:
        """Query nav_fixes within a bounding box around a point."""
        deg_offset = radius_nm / 60.0
        query = self.adl.format_query(
            """SELECT fix_name, lat, lon FROM dbo.nav_fixes
               WHERE lat BETWEEN %s AND %s AND lon BETWEEN %s AND %s"""
        )
        cursor = self.adl_conn.cursor()
        cursor.execute(query, (lat - deg_offset, lat + deg_offset,
                               lon - deg_offset, lon + deg_offset))
        return [{'fix_name': r[0], 'lat': float(r[1]), 'lon': float(r[2])}
                for r in cursor.fetchall()]

    # ------------------------------------------------------------------
    # Holding pattern: NTML correlation & TMI delay attribution
    # ------------------------------------------------------------------

    def _correlate_ntml_holding(self, holding_events: list, delay_entries: list) -> None:
        """Cross-reference detected holds with NTML +Holding entries.
        Modifies holding_events in place, setting ntml_corroborated=True when matched.
        """
        from .models import HoldingStatus

        ntml_holds = [d for d in delay_entries
                      if d.holding_status == HoldingStatus.HOLDING and d.holding_fix]

        if not ntml_holds:
            return

        for event in holding_events:
            if not event.get('matched_fix'):
                continue
            for ntml in ntml_holds:
                if ntml.holding_fix.upper() == event['matched_fix'].upper():
                    event['ntml_corroborated'] = True
                    break

    def _attribute_holding_to_tmi(self, holding_events: list, event_config) -> None:
        """Attribute each holding event to the most likely TMI program.
        Priority: GS > MIT (based on likelihood of causing holds).
        """
        for hold in holding_events:
            hold_start = hold['hold_start_utc']
            hold_end = hold['hold_end_utc']
            dest = hold.get('dest', '')

            # Check Ground Stops first (strongest signal for causing holding)
            for gs in getattr(event_config, 'gs_programs', []):
                if not gs.advisories:
                    continue
                gs_dest = gs.airport
                if dest and dest.upper().endswith(gs_dest.upper()):
                    gs_start = gs.effective_start
                    gs_end = gs.effective_end
                    if gs_start and gs_end and hold_start <= gs_end and hold_end >= gs_start:
                        hold['tmi_attribution'] = 'gs'
                        hold['tmi_program_id'] = f"GS_{gs_dest}"
                        break

            if hold.get('tmi_attribution'):
                continue

            # Check MIT programs (hold near measurement fix)
            for tmi in getattr(event_config, 'tmis', []):
                if not hasattr(tmi, 'tmi_type') or tmi.tmi_type.name not in ('MIT', 'MINIT'):
                    continue
                fix_name = getattr(tmi, 'fix', None) or getattr(tmi, 'measurement_point', None)
                if hold.get('matched_fix') and fix_name:
                    if hold['matched_fix'].upper() == fix_name.upper():
                        if (tmi.start_utc and tmi.end_utc and
                                hold_start <= tmi.end_utc and hold_end >= tmi.start_utc):
                            hold['tmi_attribution'] = 'mit'
                            hold['tmi_program_id'] = f"MIT_{fix_name}"
                            break

    def _detect_all_holding_patterns(self) -> list:
        """Scan all flights in trajectory cache for holding patterns.
        Called once after _preload_trajectories(), before TMI-specific analysis.
        """
        all_events = []
        flights_with_holds = 0

        # Load waypoints for all flights that have trajectories
        flight_uids = [meta['flight_uid'] for meta in self._trajectory_metadata.values()
                       if meta.get('flight_uid')]
        self._flight_waypoints_cache = self._load_flight_waypoints(flight_uids)

        for callsign, trajectory in self._trajectory_cache.items():
            if callsign in self._low_quality_flights:
                continue
            if len(trajectory) < 4:
                continue

            meta = self._trajectory_metadata.get(callsign, {})
            dest = meta.get('dest', '')

            # Get destination coords for circling approach filter
            dest_lat = dest_lon = None
            if dest and dest in self.fix_coords:
                dest_lat = self.fix_coords[dest]['lat']
                dest_lon = self.fix_coords[dest]['lon']

            raw_events = detect_flight_holding(trajectory, dest_lat, dest_lon)

            if raw_events:
                flights_with_holds += 1
                flight_uid = meta.get('flight_uid', 0)
                dept = meta.get('dept', '')

                for evt in raw_events:
                    evt['callsign'] = callsign
                    evt['flight_uid'] = flight_uid
                    evt['dept'] = dept
                    evt['dest'] = dest

                    # Fix matching
                    self._match_holding_fix(evt, flight_uid, dest,
                                            self._flight_waypoints_cache,
                                            self._star_fixes_cache)

                    all_events.append(evt)

        logger.info(f"Holding detection: {len(all_events)} events across "
                    f"{flights_with_holds} flights (scanned {len(self._trajectory_cache)})")

        return all_events

    def _build_holding_summary(self, events: list) -> dict:
        """Build aggregate holding summary grouped by fix."""
        from collections import defaultdict

        if not events:
            return {
                'summary': {
                    'total_flights_holding': 0,
                    'total_hold_events': 0,
                    'total_hold_duration_sec': 0,
                    'avg_hold_duration_sec': 0,
                    'hold_fixes': [],
                    'delay_attribution': {
                        'total_hold_delay_sec': 0,
                        'attributed': {
                            'gs': {'flights': 0, 'total_sec': 0},
                            'gdp': {'flights': 0, 'total_sec': 0},
                            'mit': {'flights': 0, 'total_sec': 0},
                        },
                        'unattributed': {'flights': 0, 'total_sec': 0},
                    },
                },
                'events': [],
            }

        # Group by matched fix
        fix_groups = defaultdict(list)
        for evt in events:
            key = evt.get('matched_fix') or f"{evt['center_lat']:.3f},{evt['center_lon']:.3f}"
            fix_groups[key].append(evt)

        hold_fixes = []
        for fix_key, group in fix_groups.items():
            # Peak concurrent: sweep line algorithm
            time_events = []
            for evt in group:
                time_events.append((evt['hold_start_utc'], 1))
                time_events.append((evt['hold_end_utc'], -1))
            time_events.sort(key=lambda x: x[0])
            concurrent = 0
            peak = 0
            for _, delta in time_events:
                concurrent += delta
                peak = max(peak, concurrent)

            hold_fixes.append({
                'fix_name': group[0].get('matched_fix'),
                'center': [group[0]['center_lon'], group[0]['center_lat']],
                'flight_count': len(set(e['callsign'] for e in group)),
                'total_orbits': sum(e['orbit_count'] for e in group),
                'avg_duration_sec': sum(e['duration_sec'] for e in group) / len(group),
                'peak_concurrent': peak,
                'ntml_corroborated': any(e.get('ntml_corroborated') for e in group),
                'time_range': [
                    min(e['hold_start_utc'] for e in group).isoformat() + 'Z',
                    max(e['hold_end_utc'] for e in group).isoformat() + 'Z',
                ],
            })

        # Delay attribution totals
        attr = {'gs': {'flights': set(), 'total_sec': 0},
                'gdp': {'flights': set(), 'total_sec': 0},
                'mit': {'flights': set(), 'total_sec': 0}}
        unattr_flights = set()
        unattr_sec = 0
        for evt in events:
            a = evt.get('tmi_attribution')
            if a and a in attr:
                attr[a]['flights'].add(evt['callsign'])
                attr[a]['total_sec'] += evt['duration_sec']
            else:
                unattr_flights.add(evt['callsign'])
                unattr_sec += evt['duration_sec']

        unique_flights = set(e['callsign'] for e in events)
        total_dur = sum(e['duration_sec'] for e in events)

        # Serialize events for JSON
        serialized_events = []
        for evt in events:
            se = dict(evt)
            se['hold_start_utc'] = evt['hold_start_utc'].isoformat() + 'Z'
            se['hold_end_utc'] = evt['hold_end_utc'].isoformat() + 'Z'
            se.pop('point_indices', None)
            serialized_events.append(se)

        return {
            'summary': {
                'total_flights_holding': len(unique_flights),
                'total_hold_events': len(events),
                'total_hold_duration_sec': total_dur,
                'avg_hold_duration_sec': round(total_dur / len(events), 1),
                'hold_fixes': sorted(hold_fixes, key=lambda x: x['flight_count'], reverse=True),
                'delay_attribution': {
                    'total_hold_delay_sec': total_dur,
                    'attributed': {
                        k: {'flights': len(v['flights']), 'total_sec': v['total_sec']}
                        for k, v in attr.items()
                    },
                    'unattributed': {'flights': len(unattr_flights), 'total_sec': unattr_sec},
                },
            },
            'events': serialized_events,
        }

    def _analyze_mit_compliance(self, tmi: TMI) -> Optional[Dict]:
        """
        Analyze MIT/MINIT compliance for a TMI.

        Stream identification uses TMI details:
        - destinations/origins: Filter to relevant traffic flows
        - provider/requestor: Boundary crossing identifies handoff point
        - fix: Crossing detection confirms route

        TMI scope (destinations/origins) defines the stream; trajectory analysis
        determines actual crossings within that stream.
        """
        time_str = f"{tmi.start_utc.strftime('%H:%MZ') if tmi.start_utc else '??'}-{tmi.end_utc.strftime('%H:%MZ') if tmi.end_utc else '??'}"
        logger.info(f"Analyzing {tmi.tmi_type.value}: {tmi.fix} {tmi.value}nm {time_str}")

        fix = tmi.fix
        # Filter by TMI scope (destinations/origins) - this is the stream definition
        # Trajectory analysis then determines which of these crossed the fix/boundary
        flights = self._filter_flights_by_scope(tmi)
        logger.info(f"  Filtered to {len(flights)} flights in stream scope")

        if not flights:
            logger.info(f"No flights found for TMI scope")
            return None

        # Detect BOTH fix and boundary crossings, then use the earlier one per flight
        # This ensures we measure at the actual handoff point, not just at the fix
        fix_crossings_map = {}      # callsign -> CrossingResult
        boundary_crossings_map = {} # callsign -> CrossingResult
        measurement_stats = {'fix': 0, 'boundary': 0}

        # Extract callsigns for trajectory queries (keyed by flight_uid now)
        callsign_list = [f.get('callsign', '') for f in flights.values() if f.get('callsign')]

        # 1. Detect fix crossings (if fix is specified and known)
        if fix and fix in self.fix_coords:
            coords = self.fix_coords[fix]
            fix_results = self._detect_crossings(
                fix, coords['lat'], coords['lon'],
                callsign_list, tmi
            )
            for crossing in fix_results:
                fix_crossings_map[crossing.callsign] = crossing
            logger.info(f"  Fix crossings ({fix}): {len(fix_crossings_map)}")

        # 2. Detect boundary crossings (if provider/requestor specified and GIS available)
        if tmi.provider and tmi.requestor and self.gis_conn:
            logger.info(f"  Attempting boundary detection: {tmi.provider} -> {tmi.requestor}")
            boundary_results = self._detect_boundary_crossings(
                tmi.provider, tmi.requestor,
                callsign_list, tmi
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
                    dest=bc.dest,
                    bearing=bc.bearing
                )
            logger.info(f"  Boundary crossings ({tmi.provider}->{tmi.requestor}): {len(boundary_crossings_map)}")

        # 3. For each flight, select the appropriate crossing point
        # For facility-pair MITs (e.g., DEPDY 25MIT N90:ZDC):
        #   - The BOUNDARY (handoff point) is the measurement point
        #   - The FIX is a FILTER (only flights that pass through this fix)
        # Strategy: fix crossing gates the flight in, boundary is measurement
        #   a) Fix crossing + boundary: use BOUNDARY (handoff = measurement)
        #   b) Fix crossing only, split MIT: SKIP (can't determine provider)
        #   b') Fix crossing only, non-split: use fix (boundary detection missed handoff)
        #   c) Boundary only, near fix: use boundary (sparse trajectory missed fix)
        #   d) Boundary only, far from fix: skip (flight doesn't traverse this fix)
        has_facility_pair = bool(tmi.provider and tmi.requestor)
        is_split_mit = bool(tmi.group_id)  # Multiple providers for same fix
        fix_lat_coord = self.fix_coords[fix]['lat'] if fix and fix in self.fix_coords else None
        fix_lon_coord = self.fix_coords[fix]['lon'] if fix and fix in self.fix_coords else None

        # Tight proximity for boundary-only fallback (no fix crossing confirmation)
        proximity_nm = 40.0

        crossings = []
        all_callsigns = set(fix_crossings_map.keys()) | set(boundary_crossings_map.keys())
        skipped_far = 0
        skipped_no_boundary = 0

        for callsign in all_callsigns:
            fix_cx = fix_crossings_map.get(callsign)
            bnd_cx = boundary_crossings_map.get(callsign)

            if has_facility_pair and fix and fix_lat_coord is not None:
                # Facility-pair MIT: fix crossing gates entry, boundary is measurement
                has_fix_cx = fix_cx is not None
                bnd_near_fix = False
                if bnd_cx and bnd_cx.lat and bnd_cx.lon:
                    bnd_near_fix = haversine_nm(bnd_cx.lat, bnd_cx.lon,
                                                fix_lat_coord, fix_lon_coord) <= proximity_nm

                if has_fix_cx and bnd_cx:
                    # Flight confirmed through fix AND has boundary crossing
                    # Use boundary (handoff point) as measurement
                    crossings.append(bnd_cx)
                    measurement_stats['boundary'] += 1
                elif has_fix_cx and not bnd_cx:
                    if is_split_mit:
                        # Split MIT: can't determine which provider without boundary.
                        # Skip to prevent cross-provider contamination.
                        # (e.g., SEEVR flight from ZKC skipped for ZFW:ZME sub-MIT)
                        skipped_no_boundary += 1
                    else:
                        # Single-provider MIT: fix crossing is sufficient proof
                        # that flight passes through this fix's traffic flow.
                        # Boundary detection may have missed the handoff.
                        crossings.append(fix_cx)
                        measurement_stats['fix'] += 1
                elif bnd_near_fix:
                    # No fix crossing but boundary is very close to fix —
                    # trajectory too sparse for fix detection, accept boundary
                    crossings.append(bnd_cx)
                    measurement_stats['boundary'] += 1
                elif bnd_cx:
                    # Boundary far from fix, no fix crossing — flight doesn't
                    # traverse this fix (e.g., KATL traffic at TPA TRACON
                    # boundary counted against MAATY MIT)
                    skipped_far += 1
                # else: no crossing at all for this callsign
            elif bnd_cx:
                # No facility pair or no fix — use boundary crossing as-is
                crossings.append(bnd_cx)
                measurement_stats['boundary'] += 1
            elif fix_cx:
                crossings.append(fix_cx)
                measurement_stats['fix'] += 1

        if skipped_far > 0:
            logger.info(f"  Filtered {skipped_far} boundary crossings: no fix crossing "
                       f"and >{proximity_nm}nm from {fix}")
        if skipped_no_boundary > 0:
            logger.info(f"  Skipped {skipped_no_boundary} fix-only crossings: split MIT, "
                       f"no {tmi.provider}->{tmi.requestor} boundary detected")

        # Determine overall measurement type based on what was actually used
        if has_facility_pair:
            if measurement_stats['boundary'] > 0:
                measurement_type = MeasurementType.BOUNDARY
                measurement_point = f"{tmi.provider}->{tmi.requestor} boundary"
            else:
                # No boundary crossings found at all - nothing to analyze
                measurement_type = MeasurementType.BOUNDARY
                measurement_point = f"{tmi.provider}->{tmi.requestor} boundary (no crossings)"
        elif measurement_stats['fix'] > 0:
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
                # Fix coordinates from navdata
                'fix_info': {'lat': self.fix_coords[fix]['lat'], 'lon': self.fix_coords[fix]['lon']}
                    if fix and fix in self.fix_coords else None,
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
                # Fix coordinates from navdata
                'fix_info': {'lat': self.fix_coords[fix]['lat'], 'lon': self.fix_coords[fix]['lon']}
                    if fix and fix in self.fix_coords else None,
                'was_superseded': tmi.superseded_by_tmi_id is not None,
                # Facility metadata
                'is_multiple': tmi.is_multiple,
            }

        # Sort by crossing time
        sorted_crossings = sorted(valid_crossings, key=lambda c: c.crossing_time)

        # Crossing separation thresholds for stream validation
        # FIX-based: Flights should cross at the same point (~15nm tolerance)
        # BOUNDARY-based: Flights can cross anywhere along the boundary (no limit)
        MAX_CROSSING_SEPARATION_NM_FIX = 15.0

        # Determine if we're using boundary-based or fix-based measurement
        is_boundary_based = measurement_type in (MeasurementType.BOUNDARY, MeasurementType.BOUNDARY_FALLBACK_FIX)

        # Stream-aware grouping: cluster crossings geographically to avoid
        # pairing flights from different traffic corridors
        modifier = tmi.modifier
        if modifier in (MITModifier.AS_ONE, MITModifier.SINGLE_STREAM):
            # Explicit single-stream: treat all crossings as one group
            stream_groups = {0: sorted_crossings}
            logger.info(f"  Stream grouping: AS_ONE (modifier={modifier.value})")
        elif modifier == MITModifier.PER_AIRPORT:
            # Group by departure airport instead of bearing
            stream_groups = {}
            for c in sorted_crossings:
                dept = c.dept or 'UNK'
                if dept not in stream_groups:
                    stream_groups[dept] = []
                stream_groups[dept].append(c)
            logger.info(f"  Stream grouping: PER_AIRPORT ({len(stream_groups)} groups)")
        else:
            # Default: cluster by trajectory position (spatial DBSCAN)
            # Geography-based: samples trajectory positions upstream from fix and
            # clusters spatially, correctly separating converging corridors that
            # approach the fix at similar bearings but from different geographic paths
            fix_lat = self.fix_coords[fix]['lat'] if fix and fix in self.fix_coords else None
            fix_lon = self.fix_coords[fix]['lon'] if fix and fix in self.fix_coords else None

            if fix_lat is not None and self._trajectory_cache_loaded:
                stream_groups = cluster_crossings_by_trajectory(
                    sorted_crossings, self._trajectory_cache,
                    fix_lat, fix_lon,
                    gis_conn=self.gis_conn,
                    min_dist_nm=60.0,
                    max_dist_nm=120.0,
                    eps_nm=8.0
                )
                logger.info(f"  Stream grouping: PostGIS DBSCAN ({len(stream_groups)} streams)")
            else:
                # Fallback to bearing-based when no trajectory cache or fix coords
                stream_groups = cluster_crossings_by_bearing(sorted_crossings, gap_threshold_deg=30.0)
                logger.info(f"  Stream grouping: bearing-based fallback ({len(stream_groups)} streams)")
            for sid, sc in stream_groups.items():
                meta = compute_stream_metadata(sc)
                logger.info(f"    Stream {sid}: {len(sc)} crossings, "
                           f"mean bearing={meta['mean_bearing']}, spread={meta['bearing_spread']}")

        # Pair within each stream
        pairs = []
        skipped_pairs = []
        per_stream_results = {}
        required = tmi.value

        for stream_id, stream_crossings in stream_groups.items():
            if stream_id == -1:
                # Unassigned crossings (no bearing) — skip pairing
                logger.info(f"  Stream -1: {len(stream_crossings)} unassigned crossings (no bearing data)")
                continue

            # Sort this stream by time
            stream_sorted = sorted(stream_crossings, key=lambda c: c.crossing_time)
            stream_pairs = []

            for i in range(1, len(stream_sorted)):
                prev = stream_sorted[i-1]
                curr = stream_sorted[i]

                time_diff_sec = (curr.crossing_time - prev.crossing_time).total_seconds()
                time_diff_min = time_diff_sec / 60

                if time_diff_sec <= 0:
                    continue

                # Crossing separation check (additional safeguard)
                crossing_separation = haversine_nm(prev.lat, prev.lon, curr.lat, curr.lon)
                if not is_boundary_based and crossing_separation > MAX_CROSSING_SEPARATION_NM_FIX:
                    skipped_pairs.append({
                        'prev': prev.callsign,
                        'curr': curr.callsign,
                        'reason': f'crossing separation {crossing_separation:.1f}nm > {MAX_CROSSING_SEPARATION_NM_FIX}nm'
                    })
                    continue

                # Calculate spacing based on TMI type
                if tmi.tmi_type == TMIType.MINIT:
                    actual = time_diff_min
                else:
                    avg_gs = (prev.groundspeed + curr.groundspeed) / 2 if prev.groundspeed > 0 else curr.groundspeed
                    actual = (time_diff_min * avg_gs) / 60

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
                    'gs': curr.groundspeed,
                    'prev_crossing_lat': round(prev.lat, 4),
                    'prev_crossing_lon': round(prev.lon, 4),
                    'curr_crossing_lat': round(curr.lat, 4),
                    'curr_crossing_lon': round(curr.lon, 4),
                    'crossing_separation_nm': round(crossing_separation, 1),
                    'stream_id': stream_id,
                    'prev_bearing': round(prev.bearing, 1) if prev.bearing is not None else None,
                    'curr_bearing': round(curr.bearing, 1) if curr.bearing is not None else None,
                }
                stream_pairs.append(pair)

            # Per-stream stats
            stream_meta = compute_stream_metadata(stream_crossings)
            if stream_pairs:
                stream_under = sum(1 for p in stream_pairs if p['spacing_category'] == SpacingCategory.UNDER.value)
                stream_compliant = len(stream_pairs) - stream_under
                per_stream_results[stream_id] = {
                    'crossings': len(stream_crossings),
                    'pairs': len(stream_pairs),
                    'compliant': stream_compliant,
                    'violations': stream_under,
                    'compliance_pct': round(100 * stream_compliant / len(stream_pairs), 1),
                    'mean_bearing': stream_meta['mean_bearing'],
                    'bearing_spread': stream_meta['bearing_spread'],
                }
            else:
                per_stream_results[stream_id] = {
                    'crossings': len(stream_crossings),
                    'pairs': 0,
                    'compliant': 0,
                    'violations': 0,
                    'compliance_pct': 100.0,
                    'mean_bearing': stream_meta['mean_bearing'],
                    'bearing_spread': stream_meta['bearing_spread'],
                }

            pairs.extend(stream_pairs)

        if skipped_pairs:
            logger.info(f"  Skipped {len(skipped_pairs)} pairs due to stream/separation validation")

        if not pairs:
            return None

        # Calculate aggregate statistics across all streams
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
            # Multi-facility grouping (links sub-TMIs split from same original TMI)
            'group_id': tmi.group_id or '',
            'original_facilities': tmi.original_facilities or '',
            # Modifier and filter info
            'modifier': tmi.modifier.value if tmi.modifier else None,
            'traffic_direction': tmi.traffic_direction.value if tmi.traffic_direction else None,
            # Fix coordinates from navdata (for map rendering anchor point)
            'fix_info': {'lat': self.fix_coords[fix]['lat'], 'lon': self.fix_coords[fix]['lon']}
                if fix and fix in self.fix_coords else None,
            # Stream-aware pairing metadata
            'stream_aware': True,
            'stream_count': len([s for s in stream_groups if s != -1]),
            'streams': per_stream_results,
        }

        # Add trajectory data for flights that crossed (for map rendering)
        trajectories = {}
        for crossing in sorted_crossings:
            callsign = crossing.callsign
            if callsign not in trajectories and callsign in self._trajectory_cache:
                traj_points = self._trajectory_cache[callsign]
                if traj_points:
                    # Convert to GeoJSON-compatible coordinates [lon, lat, timestamp]
                    # Timestamp included for gap detection in frontend rendering
                    coords = []
                    for p in traj_points:
                        ts = p['timestamp']
                        # Convert timestamp to epoch seconds for JS
                        if hasattr(ts, 'timestamp'):
                            epoch = int(ts.timestamp())
                        else:
                            epoch = int(ts)
                        coords.append([round(p['lon'], 4), round(p['lat'], 4), epoch])
                    trajectories[callsign] = {
                        'type': 'LineString',
                        'coordinates': coords,
                        'properties': {
                            'callsign': callsign,
                            'dept': self._trajectory_metadata.get(callsign, {}).get('dept', ''),
                            'dest': self._trajectory_metadata.get(callsign, {}).get('dest', '')
                        }
                    }
        # Store trajectory metadata in result (actual data stored separately for split output)
        result['has_trajectories'] = bool(trajectories)
        result['trajectory_count'] = len(trajectories)
        result['_trajectories'] = trajectories  # Popped by analyze() for split file

        # Compute traffic flow sectors (angular distribution at measurement point)
        if len(sorted_crossings) >= 3:
            # Use centroid of crossing locations as measurement point
            avg_lat = sum(c.lat for c in sorted_crossings) / len(sorted_crossings)
            avg_lon = sum(c.lon for c in sorted_crossings) / len(sorted_crossings)

            traffic_sector = compute_traffic_sector(
                sorted_crossings,
                self._trajectory_cache,
                avg_lat,
                avg_lon
            )
            if traffic_sector:
                result['traffic_sector'] = traffic_sector

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
        """
        Analyze Ground Stop compliance (legacy single-advisory path).

        Time source priority: off_utc > out_utc + taxi_ref > first_seen
        """
        logger.info(f"Analyzing GS: {','.join(tmi.destinations)}")

        # Use comprehensive flight set filtered by scope
        flights = self._filter_flights_by_scope(tmi)
        logger.info(f"  Found {len(flights)} flights to/from featured facilities")

        if not flights:
            return None

        gs_start = tmi.start_utc
        gs_end = tmi.get_effective_end()
        gs_issued = tmi.issued_utc or gs_start

        exempt = []
        compliant = []
        non_compliant = []
        gs_delays = []
        time_source_counts = {'off_utc': 0, 'out_utc+taxi': 0, 'first_seen+connect': 0}

        for fuid, flight in flights.items():
            callsign = flight.get('callsign', str(fuid))
            dept = flight.get('dept', 'UNK')

            # Skip if no origin filter or origin doesn't match
            normalized_origs = set(normalize_icao_list(tmi.origins)) if tmi.origins else set()
            if normalized_origs and dept not in normalized_origs:
                continue

            # Determine best wheels-off estimate
            # Priority: off_utc > out_utc + taxi_ref > first_seen
            off_utc = flight.get('off_utc')
            out_utc = flight.get('out_utc')
            first_seen = flight.get('first_seen')

            if off_utc:
                dep_time = normalize_datetime(off_utc)
                time_source = 'off_utc'
            elif out_utc:
                out_dt = normalize_datetime(out_utc)
                taxi_sec = self._get_taxi_reference(dept)
                dep_time = out_dt + timedelta(seconds=taxi_sec)
                time_source = 'out_utc+taxi'
            elif first_seen:
                connect_sec = self._get_connect_reference(dept)
                dep_time = normalize_datetime(first_seen) + timedelta(seconds=connect_sec)
                time_source = 'first_seen+connect'
            else:
                continue

            time_source_counts[time_source] += 1

            flight_info = {
                'callsign': callsign,
                'dept': dept,
                'dept_time': dep_time.strftime('%H:%M:%SZ'),
                'time_source': time_source
            }

            # Calculate GS delay: total additional ground time caused by the GS
            # = (dep_time - ready_time) - unimpeded_taxi
            ready_time_for_delay = None
            if first_seen:
                connect_sec_d = self._get_connect_reference(dept)
                ready_time_for_delay = normalize_datetime(first_seen) + timedelta(seconds=connect_sec_d)
            elif out_utc:
                ready_time_for_delay = normalize_datetime(out_utc)

            if ready_time_for_delay:
                unimpeded_sec = self._get_taxi_reference(dept)
                total_ground_sec = (dep_time - ready_time_for_delay).total_seconds()
                gs_delay_sec = max(0, total_ground_sec - unimpeded_sec)
                flight_info['gs_delay_min'] = round(gs_delay_sec / 60, 1)
                if gs_delay_sec > 0:
                    gs_delays.append(gs_delay_sec / 60)

            # Determine compliance status
            if dep_time < gs_issued:
                flight_info['status'] = 'EXEMPT'
                flight_info['reason'] = 'Airborne before GS issued'
                exempt.append(flight_info)
            elif dep_time > gs_end:
                # Only count if departed within reasonable window after GS ended
                gs_duration_min = (gs_end - gs_start).total_seconds() / 60
                max_hold_window_min = max(gs_duration_min * 3, 120)
                time_after_gs_min = (dep_time - gs_end).total_seconds() / 60
                if time_after_gs_min > max_hold_window_min:
                    continue  # Not GS-related, just normal traffic
                flight_info['status'] = 'COMPLIANT'
                flight_info['reason'] = 'Departed after GS ended'
                compliant.append(flight_info)
                # Calculate hold time: delay from the GS (overlap of ready time with GS window)
                ready_time = normalize_datetime(out_utc) if out_utc else (normalize_datetime(first_seen) if first_seen else None)
                if ready_time and ready_time < gs_end:
                    hold_min = (gs_end - max(ready_time, gs_start)).total_seconds() / 60
                    if hold_min > 0:
                        flight_info['hold_time_min'] = round(hold_min, 1)
            else:
                flight_info['status'] = 'NON-COMPLIANT'
                flight_info['reason'] = 'Departed during GS window'
                gs_duration = (gs_end - gs_start).total_seconds()
                into_gs = (dep_time - gs_start).total_seconds()
                flight_info['pct_into_gs'] = round(100 * into_gs / gs_duration, 1) if gs_duration > 0 else 0
                non_compliant.append(flight_info)

        total_applicable = len(compliant) + len(non_compliant)
        compliance_pct = round(100 * len(compliant) / total_applicable, 1) if total_applicable > 0 else 100

        return {
            'gs_start': gs_start.strftime('%H:%MZ'),
            'gs_end': gs_end.strftime('%H:%MZ'),
            'gs_issued': gs_issued.strftime('%H:%MZ'),
            'cancelled': tmi.cancelled_utc is not None,
            'total_flights': len(exempt) + len(compliant) + len(non_compliant),
            'exempt': exempt,
            'compliant': compliant,
            'non_compliant': non_compliant,
            'compliance_pct': compliance_pct,
            'violations': {
                'total': len(non_compliant),
                'avg_pct_into_gs': round(sum(f.get('pct_into_gs', 0) for f in non_compliant) / len(non_compliant), 1) if non_compliant else 0
            },
            'destinations': tmi.destinations,
            'origins': tmi.origins,
            'time_source_breakdown': time_source_counts,
            'gs_delay_stats': {
                'flights_with_delay_data': len(gs_delays),
                'avg_delay_min': round(sum(gs_delays) / len(gs_delays), 1) if gs_delays else 0,
                'max_delay_min': round(max(gs_delays), 1) if gs_delays else 0,
                'total_delay_min': round(sum(gs_delays), 1) if gs_delays else 0,
            }
        }

    def _analyze_gs_program(self, program: GSProgram) -> Optional[Dict]:
        """
        Analyze Ground Stop compliance for a program (chain of advisories).

        Time source priority for wheels-off determination:
        1. off_utc - actual wheels-off from OOOI zone detection
        2. out_utc + airport taxi ref - gate push + per-airport unimpeded taxi time
        3. first_seen - VATSIM connection time (least accurate, ~35 min early median)

        GS delay calculation (when out_utc + off_utc both available):
        gs_delay = max(0, (OFF - OUT) - unimpeded_taxi(airport))

        Features:
        - Uses effective window from program chain
        - Phase tracking - tags each flight with which advisory phase it was in
        - Per-origin breakdown by DEP FACILITY
        - Delay impact calculation (hold time for compliant flights)
        - Program timeline for frontend rendering
        - Not-in-scope flights (to GS airport but from unlisted facility)
        - Time source breakdown statistics
        """
        logger.info(f"Analyzing GS Program: {program.airport} ({len(program.advisories)} advisories)")

        # Get all flights to the GS airport
        # Create a synthetic TMI for flight filtering
        synthetic_tmi = TMI(
            tmi_id=f'GS_PROGRAM_{program.airport}',
            tmi_type=TMIType.GS,
            destinations=[program.airport],
            origins=[],
            start_utc=program.effective_start,
            end_utc=program.effective_end
        )
        flights = self._filter_flights_by_scope(synthetic_tmi)

        if not flights:
            return None

        gs_start = program.effective_start
        gs_end = program.effective_end
        first_issued = program.advisories[0].adl_time if program.advisories else gs_start

        # Map dep_facilities to filter origins
        dep_facilities = set(f.upper() for f in program.dep_facilities)

        # Build program timeline for frontend
        program_timeline = []
        for adv in program.advisories:
            program_timeline.append({
                'advzy': adv.advzy_number,
                'type': adv.advisory_type,
                'start': adv.gs_period_start.strftime('%H:%MZ') if adv.gs_period_start else None,
                'end': adv.gs_period_end.strftime('%H:%MZ') if adv.gs_period_end else None,
                'issued': adv.adl_time.strftime('%H:%MZ') if adv.adl_time else None,
                'impacting_condition': adv.impacting_condition,
                'dep_facilities': adv.dep_facilities,
                'dep_facility_tier': adv.dep_facility_tier,
                'prob_extension': adv.prob_extension,
                'comments': adv.comments,
            })

        exempt = []
        compliant = []
        non_compliant = []
        not_in_scope = []
        per_origin = defaultdict(lambda: {'compliant': 0, 'non_compliant': 0, 'exempt': 0, 'total': 0, 'hold_times': [], 'gs_delays': []})
        per_carrier = defaultdict(lambda: {'compliant': 0, 'non_compliant': 0, 'exempt': 0, 'total': 0, 'hold_times': [], 'gs_delays': [], 'airline_name': ''})
        hold_times = []
        gs_delays = []
        time_source_counts = {'off_utc': 0, 'out_utc+taxi': 0, 'first_seen+connect': 0}

        for fuid, flight in flights.items():
            callsign = flight.get('callsign', str(fuid))
            dept = flight.get('dept', 'UNK')

            # Determine best wheels-off estimate
            # Priority: off_utc (actual wheels-off) > out_utc + taxi_ref (estimated) > first_seen
            off_utc = flight.get('off_utc')
            out_utc = flight.get('out_utc')
            first_seen = flight.get('first_seen')

            if off_utc:
                dep_time = normalize_datetime(off_utc)
                time_source = 'off_utc'
            elif out_utc:
                out_dt = normalize_datetime(out_utc)
                taxi_sec = self._get_taxi_reference(dept)
                dep_time = out_dt + timedelta(seconds=taxi_sec)
                time_source = 'out_utc+taxi'
            elif first_seen:
                connect_sec = self._get_connect_reference(dept)
                dep_time = normalize_datetime(first_seen) + timedelta(seconds=connect_sec)
                time_source = 'first_seen+connect'
            else:
                continue

            # Extract carrier from airline_icao or callsign prefix
            airline_icao = flight.get('airline_icao')
            airline_name = flight.get('airline_name', '')
            if airline_icao:
                carrier = airline_icao
            else:
                # Fallback: extract letter prefix from callsign (e.g., "AAL" from "AAL123")
                m = re.match(r'^([A-Za-z]{2,4})', callsign.upper() if callsign else '')
                carrier = m.group(1).upper() if m else (callsign or 'UNK')

            # first_seen → gate wait calculation
            first_seen_dt = normalize_datetime(first_seen) if first_seen else None
            gate_wait_min = None
            if first_seen_dt and out_utc:
                out_dt_raw = normalize_datetime(out_utc)
                wait_sec = (out_dt_raw - first_seen_dt).total_seconds()
                if wait_sec > 0:
                    gate_wait_min = round(wait_sec / 60, 1)

            flight_info = {
                'callsign': callsign,
                'dept': dept,
                'carrier': carrier,
                'airline_name': airline_name or '',
                'dept_time': dep_time.strftime('%H:%M:%SZ'),
                'out_time': normalize_datetime(out_utc).strftime('%H:%M:%SZ') if out_utc else None,
                'off_time': normalize_datetime(off_utc).strftime('%H:%M:%SZ') if off_utc else None,
                'first_seen_time': first_seen_dt.strftime('%H:%M:%SZ') if first_seen_dt else None,
                'gate_wait_min': gate_wait_min,
                'time_source': time_source,
                'connect_ref_sec': self._get_connect_reference(dept) if first_seen else None
            }

            # Calculate GS delay: total additional ground time caused by the GS
            # = (dep_time - ready_time) - unimpeded_taxi
            # This captures both gate hold AND excess taxi delay
            # ready_time: when pilot was ready (first_seen+connect or out_utc)
            # dep_time: actual departure (off_utc, out_utc+taxi, or first_seen+connect)
            ready_time_for_delay = None
            if first_seen:
                connect_sec = self._get_connect_reference(dept)
                ready_time_for_delay = (normalize_datetime(first_seen) + timedelta(seconds=connect_sec))
            elif out_utc:
                ready_time_for_delay = normalize_datetime(out_utc)

            if ready_time_for_delay:
                unimpeded_sec = self._get_taxi_reference(dept)
                total_ground_sec = (dep_time - ready_time_for_delay).total_seconds()
                gs_delay_sec = max(0, total_ground_sec - unimpeded_sec)
                flight_info['gs_delay_min'] = round(gs_delay_sec / 60, 1)
                flight_info['unimpeded_taxi_min'] = round(unimpeded_sec / 60, 1)

            # Also track actual taxi time if OOOI data available
            if out_utc and off_utc:
                out_dt = normalize_datetime(out_utc)
                off_dt = normalize_datetime(off_utc)
                actual_taxi_sec = (off_dt - out_dt).total_seconds()
                flight_info['actual_taxi_min'] = round(actual_taxi_sec / 60, 1)

            # Check if flight is from a listed DEP FACILITY
            # If dep_facilities is specified but dept origin's facility isn't listed -> NOT_IN_SCOPE
            if dep_facilities:
                # Check flight plan data first, fall back to apts reference table
                origin_artcc = (flight.get('dept_artcc') or '').upper()
                origin_tracon = (flight.get('dept_tracon') or '').upper()
                if not origin_artcc:
                    origin_artcc = self._get_airport_artcc(dept)
                in_scope = (origin_artcc in dep_facilities or
                           origin_tracon in dep_facilities or
                           dept.upper() in dep_facilities or
                           not dep_facilities)  # If no facilities listed, all in scope

                if not in_scope:
                    flight_info['status'] = 'NOT_IN_SCOPE'
                    flight_info['reason'] = f'Origin facility not in DEP FACILITIES'
                    not_in_scope.append(flight_info)
                    continue

            # Track stats for in-scope flights only
            time_source_counts[time_source] += 1
            if flight_info.get('gs_delay_min', 0) > 0:
                gs_delays.append(flight_info['gs_delay_min'])

            # Determine which advisory phase this flight's departure falls in
            phase = None
            phase_type = None
            for adv in program.advisories:
                if adv.advisory_type == 'CNX':
                    continue
                if adv.gs_period_start and adv.gs_period_end:
                    if adv.gs_period_start <= dep_time <= adv.gs_period_end:
                        phase = adv.advzy_number
                        phase_type = adv.advisory_type
                        break

            flight_info['phase'] = phase
            flight_info['phase_type'] = phase_type

            # Determine compliance
            if first_issued and dep_time < first_issued:
                flight_info['status'] = 'EXEMPT'
                flight_info['reason'] = 'Airborne before GS issued'
                exempt.append(flight_info)
                per_origin[dept]['exempt'] += 1
                per_carrier[carrier]['exempt'] += 1
            elif gs_end and dep_time > gs_end:
                # Only count as GS-affected if departed within reasonable window after GS ended
                # Flights departing hours later are normal traffic, not held by the GS
                gs_duration_min = (gs_end - gs_start).total_seconds() / 60 if gs_start else 60
                max_hold_window_min = max(gs_duration_min * 3, 120)  # 3x GS duration or 2 hours, whichever is larger
                time_after_gs_min = (dep_time - gs_end).total_seconds() / 60
                if time_after_gs_min > max_hold_window_min:
                    continue  # Skip - departed too long after GS to be related

                flight_info['status'] = 'COMPLIANT'
                flight_info['reason'] = 'Departed after GS ended'
                compliant.append(flight_info)
                per_origin[dept]['compliant'] += 1
                per_carrier[carrier]['compliant'] += 1
                # Calculate hold time: delay incurred as a result of the GS
                # = overlap between when the flight was ready and the GS window
                # Ready time: first_seen + connect_ref (estimated setup completion)
                # This adjusts raw connect time to approximate when pilot was ready
                # For GS-held flights, out_utc is AFTER the GS ended so we prefer first_seen
                ready_time = None
                if first_seen:
                    connect_sec = self._get_connect_reference(dept)
                    ready_time = first_seen_dt + timedelta(seconds=connect_sec)
                elif out_utc:
                    ready_time = normalize_datetime(out_utc)

                if ready_time and gs_start and gs_end and ready_time < gs_end:
                    hold_min = (gs_end - max(ready_time, gs_start)).total_seconds() / 60
                    if hold_min > 0:
                        hold_times.append(hold_min)
                        per_origin[dept]['hold_times'].append(hold_min)
                        per_carrier[carrier]['hold_times'].append(hold_min)
                        flight_info['hold_time_min'] = round(hold_min, 1)
            elif gs_start and dep_time < gs_start:
                flight_info['status'] = 'EXEMPT'
                flight_info['reason'] = 'Departed before GS started'
                exempt.append(flight_info)
                per_origin[dept]['exempt'] += 1
                per_carrier[carrier]['exempt'] += 1
            else:
                flight_info['status'] = 'NON-COMPLIANT'
                flight_info['reason'] = 'Departed during GS window'
                if gs_start and gs_end:
                    gs_duration = (gs_end - gs_start).total_seconds()
                    into_gs = (dep_time - gs_start).total_seconds()
                    flight_info['pct_into_gs'] = round(100 * into_gs / gs_duration, 1) if gs_duration > 0 else 0
                    flight_info['into_gs_min'] = round(into_gs / 60, 1)
                non_compliant.append(flight_info)
                per_origin[dept]['non_compliant'] += 1
                per_carrier[carrier]['non_compliant'] += 1

            per_origin[dept]['total'] += 1
            per_carrier[carrier]['total'] += 1
            if not per_carrier[carrier]['airline_name'] and airline_name:
                per_carrier[carrier]['airline_name'] = airline_name

            # Track GS delay per origin and carrier
            if flight_info.get('gs_delay_min') is not None and flight_info['gs_delay_min'] > 0:
                per_origin[dept]['gs_delays'].append(flight_info['gs_delay_min'])
                per_carrier[carrier]['gs_delays'].append(flight_info['gs_delay_min'])

        total_applicable = len(compliant) + len(non_compliant)
        compliance_pct = round(100 * len(compliant) / total_applicable, 1) if total_applicable > 0 else 100
        avg_hold_time = round(sum(hold_times) / len(hold_times), 1) if hold_times else 0

        # Format per_origin for output
        per_origin_list = []
        for origin, counts in sorted(per_origin.items()):
            origin_applicable = counts['compliant'] + counts['non_compliant']
            origin_hold = counts['hold_times']
            origin_delays = counts['gs_delays']
            per_origin_list.append({
                'origin': origin,
                'total': counts['total'],
                'compliant': counts['compliant'],
                'non_compliant': counts['non_compliant'],
                'exempt': counts['exempt'],
                'compliance_pct': round(100 * counts['compliant'] / origin_applicable, 1) if origin_applicable > 0 else 100,
                'avg_hold_time_min': round(sum(origin_hold) / len(origin_hold), 1) if origin_hold else 0,
                'avg_gs_delay_min': round(sum(origin_delays) / len(origin_delays), 1) if origin_delays else 0,
            })

        # Format per_carrier for output
        per_carrier_list = []
        for carrier_code, counts in sorted(per_carrier.items()):
            carrier_applicable = counts['compliant'] + counts['non_compliant']
            carrier_hold = counts['hold_times']
            carrier_delays = counts['gs_delays']
            per_carrier_list.append({
                'carrier': carrier_code,
                'airline_name': counts['airline_name'],
                'total': counts['total'],
                'compliant': counts['compliant'],
                'non_compliant': counts['non_compliant'],
                'exempt': counts['exempt'],
                'compliance_pct': round(100 * counts['compliant'] / carrier_applicable, 1) if carrier_applicable > 0 else 100,
                'avg_hold_time_min': round(sum(carrier_hold) / len(carrier_hold), 1) if carrier_hold else 0,
                'avg_gs_delay_min': round(sum(carrier_delays) / len(carrier_delays), 1) if carrier_delays else 0,
            })

        return {
            'gs_start': gs_start.strftime('%H:%MZ') if gs_start else None,
            'gs_end': gs_end.strftime('%H:%MZ') if gs_end else None,
            'gs_issued': first_issued.strftime('%H:%MZ') if first_issued else None,
            'cancelled': program.is_cancelled(),
            'ended_by': program.ended_by,
            'total_flights': len(exempt) + len(compliant) + len(non_compliant) + len(not_in_scope),
            'exempt': exempt,
            'compliant': compliant,
            'non_compliant': non_compliant,
            'not_in_scope': not_in_scope,
            'compliance_pct': compliance_pct,
            'violations': {
                'total': len(non_compliant),
                'avg_pct_into_gs': round(sum(f.get('pct_into_gs', 0) for f in non_compliant) / len(non_compliant), 1) if non_compliant else 0
            },
            'destinations': [program.airport],
            'origins': list(program.dep_facilities),
            # Enhanced program data
            'program_timeline': program_timeline,
            'per_origin_breakdown': per_origin_list,
            'per_carrier_breakdown': per_carrier_list,
            'avg_hold_time_min': avg_hold_time,
            'hold_time_stats': {
                'min': round(min(hold_times), 1) if hold_times else 0,
                'max': round(max(hold_times), 1) if hold_times else 0,
                'median': round(sorted(hold_times)[len(hold_times) // 2], 1) if hold_times else 0,
            },
            'impacting_condition': program.impacting_condition,
            'prob_extension': program.prob_extension,
            'cnx_comments': program.cnx_comments,
            'dep_facility_tier': program.dep_facility_tier,
            # Time source & GS delay analysis
            'time_source_breakdown': time_source_counts,
            'gs_delay_stats': {
                'flights_with_delay_data': len(gs_delays),
                'avg_delay_min': round(sum(gs_delays) / len(gs_delays), 1) if gs_delays else 0,
                'max_delay_min': round(max(gs_delays), 1) if gs_delays else 0,
                'median_delay_min': round(sorted(gs_delays)[len(gs_delays) // 2], 1) if gs_delays else 0,
                'total_delay_min': round(sum(gs_delays), 1) if gs_delays else 0,
            }
        }

    def _analyze_reroute_compliance(self, tmi: TMI) -> Optional[Dict]:
        """
        Analyze Reroute/Playbook compliance - both filed AND flown routes.

        Checks if flights from affected origins to destinations are using
        the specified route segments during the reroute validity window.

        Two compliance checks:
        1. Filed compliance: Does the flight plan contain required fixes?
        2. Flown compliance: Did the actual trajectory pass through required fixes?

        For ROUTE RQD (mandatory): Flights must use the specified route.
        For FEA FYI (informational): Track usage but no compliance assessment.
        """
        import re

        name = tmi.reroute_name or 'Unknown Reroute'
        logger.info(f"Analyzing REROUTE: {name}")
        logger.info(f"  Origins: {tmi.origins}, Destinations: {tmi.destinations}")
        logger.info(f"  Mandatory: {tmi.reroute_mandatory}, Routes: {len(tmi.reroute_routes)}")

        # Use comprehensive flight set filtered by scope
        flights_dict = self._filter_flights_by_scope(tmi)
        logger.info(f"  Found {len(flights_dict)} flights to/from featured facilities")

        # Normalize airport codes for additional filtering
        normalized_origs = set(normalize_icao_list(tmi.origins)) if tmi.origins else set()
        normalized_dests = set(normalize_icao_list(tmi.destinations)) if tmi.destinations else set()

        if not normalized_origs or not normalized_dests:
            logger.warning(f"  Skipping reroute - missing origins or destinations")
            return None

        # Filter to flights within the reroute time window
        flights = []
        for fuid, flight in flights_dict.items():
            callsign = flight.get('callsign', str(fuid))
            first_seen = flight.get('first_seen')
            if not first_seen:
                continue

            # Check if departure is within reroute window
            if first_seen >= tmi.start_utc and first_seen <= tmi.end_utc:
                dept = flight.get('dept', '')
                dest = flight.get('dest', '')
                # Additional check: must be from origin to destination
                if dept in normalized_origs and dest in normalized_dests:
                    flights.append((
                        callsign,
                        dept,
                        dest,
                        flight.get('fp_route', ''),
                        first_seen,
                        flight.get('last_seen')
                    ))

        logger.info(f"  Filtered to {len(flights)} flights in reroute time window")

        if not flights:
            return {
                'name': name,
                'mandatory': tmi.reroute_mandatory,
                'time_type': tmi.time_type,
                'start': tmi.start_utc.strftime('%H:%MZ'),
                'end': tmi.end_utc.strftime('%H:%MZ'),
                'origins': tmi.origins,
                'destinations': tmi.destinations,
                'required_routes': tmi.reroute_routes,
                'total_flights': 0,
                'flights': [],
                'filed_compliant': [],
                'filed_non_compliant': [],
                'flown_compliant': [],
                'flown_non_compliant': [],
                'filed_compliance_pct': 100,
                'flown_compliance_pct': 100,
                'note': 'No flights found for reroute scope'
            }

        # Build route check patterns from reroute_routes
        # Extract key fixes from required routes (marked with > <)
        required_fixes = []
        for route_spec in tmi.reroute_routes:
            route_str = route_spec.get('route', '')
            # Extract fixes between > and < markers (mandatory segment)
            marked = re.findall(r'>([^<]+)<', route_str)
            if marked:
                for segment in marked:
                    fixes = re.findall(r'[A-Z]{3,5}', segment)
                    required_fixes.extend(fixes)
            else:
                # No markers - use all fixes
                fixes = re.findall(r'[A-Z]{3,5}', route_str)
                required_fixes.extend(fixes)

        required_fixes = list(set(required_fixes))  # Deduplicate
        logger.info(f"  Required route fixes: {required_fixes}")

        # Load coordinates for required fixes (for flown route analysis)
        fixes_to_load = [f for f in required_fixes if f not in self.fix_coords]
        if fixes_to_load:
            self._load_fix_coordinates(fixes_to_load)

        # Pre-load trajectories for all flights if not already cached
        all_callsigns = [row[0] for row in flights]
        if not self._trajectory_cache_loaded:
            self._preload_trajectories(all_callsigns)

        # Analyze each flight for both filed and flown compliance
        flight_results = []
        filed_compliant = []
        filed_non_compliant = []
        flown_compliant = []
        flown_non_compliant = []

        for row in flights:
            callsign, dept, dest, fp_route, first_seen, last_seen = row
            first_seen = normalize_datetime(first_seen)
            fp_route = fp_route or ''

            # === FILED ROUTE ANALYSIS ===
            route_upper = fp_route.upper()
            filed_matched_fixes = [f for f in required_fixes if f in route_upper]
            filed_match_pct = len(filed_matched_fixes) / len(required_fixes) * 100 if required_fixes else 0
            filed_status = 'FILED_COMPLIANT' if filed_match_pct >= 50 else 'FILED_NON_COMPLIANT'

            # === FLOWN ROUTE ANALYSIS ===
            # Check if trajectory passed within crossing radius of required fixes
            trajectory = self._trajectory_cache.get(callsign, [])
            flown_matched_fixes = []
            flown_fix_details = []  # Details about each fix crossing

            if trajectory and len(trajectory) >= 2 and callsign not in self._low_quality_flights:
                for fix_name in required_fixes:
                    if fix_name in self.fix_coords:
                        fix_lat = self.fix_coords[fix_name]['lat']
                        fix_lon = self.fix_coords[fix_name]['lon']

                        # Find closest approach to this fix
                        min_dist = float('inf')
                        crossing_time = None
                        crossing_alt = None

                        for pt in trajectory:
                            dist = haversine_nm(pt['lat'], pt['lon'], fix_lat, fix_lon)
                            if dist < min_dist:
                                min_dist = dist
                                crossing_time = pt['timestamp']
                                crossing_alt = pt.get('alt', 0)

                        # Consider "crossed" if within crossing radius (default 10nm)
                        if min_dist <= CROSSING_RADIUS_NM:
                            flown_matched_fixes.append(fix_name)
                            flown_fix_details.append({
                                'fix': fix_name,
                                'distance_nm': round(min_dist, 1),
                                'crossing_time': crossing_time.strftime('%H:%M:%SZ') if crossing_time else None,
                                'altitude': int(crossing_alt) if crossing_alt else None
                            })

            flown_match_pct = len(flown_matched_fixes) / len(required_fixes) * 100 if required_fixes else 0
            flown_status = 'FLOWN_COMPLIANT' if flown_match_pct >= 50 else 'FLOWN_NON_COMPLIANT'

            # Handle case where no trajectory data is available
            has_trajectory = bool(trajectory and len(trajectory) >= 2 and callsign not in self._low_quality_flights)
            if not has_trajectory:
                flown_status = 'NO_TRAJECTORY'

            flight_info = {
                'callsign': callsign,
                'dept': dept,
                'dest': dest,
                'dept_time': first_seen.strftime('%H:%M:%SZ') if first_seen else None,
                'filed_route': fp_route,
                # Filed compliance
                'filed_matched_fixes': filed_matched_fixes,
                'filed_match_pct': round(filed_match_pct, 1),
                'filed_status': filed_status,
                # Flown compliance
                'has_trajectory': has_trajectory,
                'flown_matched_fixes': flown_matched_fixes,
                'flown_match_pct': round(flown_match_pct, 1),
                'flown_status': flown_status,
                'flown_fix_details': flown_fix_details,
                # Overall status (filed takes precedence for reporting, flown for verification)
                'filed_but_not_flown': filed_status == 'FILED_COMPLIANT' and flown_status == 'FLOWN_NON_COMPLIANT',
                'flown_but_not_filed': filed_status == 'FILED_NON_COMPLIANT' and flown_status == 'FLOWN_COMPLIANT'
            }

            flight_results.append(flight_info)

            # Categorize for summary
            if filed_status == 'FILED_COMPLIANT':
                filed_compliant.append(flight_info)
            else:
                filed_non_compliant.append(flight_info)

            if flown_status == 'FLOWN_COMPLIANT':
                flown_compliant.append(flight_info)
            elif flown_status == 'FLOWN_NON_COMPLIANT':
                flown_non_compliant.append(flight_info)
            # Note: NO_TRAJECTORY flights are not counted in flown compliance

        # Calculate compliance percentages
        filed_applicable = len(filed_compliant) + len(filed_non_compliant)
        filed_compliance_pct = round(100 * len(filed_compliant) / filed_applicable, 1) if filed_applicable > 0 else 100

        flown_applicable = len(flown_compliant) + len(flown_non_compliant)
        flown_compliance_pct = round(100 * len(flown_compliant) / flown_applicable, 1) if flown_applicable > 0 else 100

        # Count discrepancies
        filed_but_not_flown = sum(1 for f in flight_results if f.get('filed_but_not_flown', False))
        flown_but_not_filed = sum(1 for f in flight_results if f.get('flown_but_not_filed', False))
        no_trajectory_count = sum(1 for f in flight_results if f.get('flown_status') == 'NO_TRAJECTORY')

        logger.info(f"  Filed compliance: {len(filed_compliant)}/{filed_applicable} ({filed_compliance_pct}%)")
        logger.info(f"  Flown compliance: {len(flown_compliant)}/{flown_applicable} ({flown_compliance_pct}%)")
        logger.info(f"  Filed but not flown: {filed_but_not_flown}, Flown but not filed: {flown_but_not_filed}")
        if no_trajectory_count > 0:
            logger.info(f"  No trajectory data: {no_trajectory_count} flights")

        return {
            'name': name,
            'mandatory': tmi.reroute_mandatory,
            'time_type': tmi.time_type,
            'start': tmi.start_utc.strftime('%H:%MZ'),
            'end': tmi.end_utc.strftime('%H:%MZ'),
            'origins': tmi.origins,
            'destinations': tmi.destinations,
            'required_routes': tmi.reroute_routes,
            'required_fixes': required_fixes,
            'total_flights': len(flights),
            'flights': flight_results,
            # Filed compliance (what pilots filed)
            'filed_compliant': filed_compliant,
            'filed_non_compliant': filed_non_compliant,
            'filed_compliance_pct': filed_compliance_pct,
            # Flown compliance (what actually happened)
            'flown_compliant': flown_compliant,
            'flown_non_compliant': flown_non_compliant,
            'flown_compliance_pct': flown_compliance_pct,
            'no_trajectory_count': no_trajectory_count,
            # Discrepancy analysis
            'filed_but_not_flown': filed_but_not_flown,
            'flown_but_not_filed': flown_but_not_filed,
            # Legacy fields for backward compatibility
            'using_route': filed_compliant,  # Alias for backward compat
            'not_using_route': filed_non_compliant,  # Alias for backward compat
            'compliance_pct': filed_compliance_pct,  # Alias for backward compat
            # Metadata
            'reason': tmi.reason,
            'facilities': tmi.artccs
        }

    def _analyze_reroute_program(self, program: RerouteProgram) -> Optional[Dict]:
        """
        Analyze Reroute compliance for a program (chain of advisories).

        Assessment modes based on action:
        - RQD (full_compliance): Full scoring with COMPLIANT/PARTIAL/NON_COMPLIANT
        - RMD (usage_tracking): Softer scoring, NON_COMPLIANT shown as MONITORING
        - PLN (future_planning): All flights marked PENDING
        - FYI (tracking_only): All flights marked MONITORING

        Uses REROUTE_COMPLIANT_THRESHOLD (95%) and REROUTE_PARTIAL_THRESHOLD (50%).
        """
        import re

        name = program.name or 'Unknown Reroute'
        mode = program.get_assessment_mode()
        logger.info(f"Analyzing REROUTE Program: {name} (mode={mode}, action={program.action})")

        # Create synthetic TMI for flight filtering
        synthetic_tmi = TMI(
            tmi_id=f'REROUTE_PROGRAM_{name}',
            tmi_type=TMIType.REROUTE,
            destinations=program.destinations,
            origins=program.origins,
            start_utc=program.effective_start,
            end_utc=program.effective_end
        )
        flights_dict = self._filter_flights_by_scope(synthetic_tmi)

        normalized_origs = set(normalize_icao_list(program.origins)) if program.origins else set()
        normalized_dests = set(normalize_icao_list(program.destinations)) if program.destinations else set()

        # Build program history for frontend (must be before early-returns that reference it)
        program_history = []
        for adv in program.advisories:
            program_history.append({
                'advzy': adv.advzy_number,
                'type': adv.advisory_type,
                'route_type': adv.route_type,
                'action': adv.action,
                'start': adv.valid_start.strftime('%H:%MZ') if adv.valid_start else None,
                'end': adv.valid_end.strftime('%H:%MZ') if adv.valid_end else None,
                'issued': adv.adl_time.strftime('%H:%MZ') if adv.adl_time else None,
                'replaces': adv.replaces_advzy,
                'modifications': adv.modifications,
                'routes': [{'orig': ','.join(r.origins), 'dest': r.destination, 'route': r.route_string} for r in adv.routes],
                'exemptions': adv.exemptions,
                'associated_restrictions': adv.associated_restrictions,
                'prob_extension': adv.prob_extension,
                'comments': adv.comments,
            })

        if not normalized_origs or not normalized_dests:
            logger.warning(f"  Skipping reroute program - missing origins or destinations")
            return {
                'name': name,
                'mandatory': program.is_mandatory(),
                'route_type': program.route_type,
                'action': program.action,
                'assessment_mode': mode,
                'start': program.effective_start.strftime('%H:%MZ') if program.effective_start else None,
                'end': program.effective_end.strftime('%H:%MZ') if program.effective_end else None,
                'ended_by': program.ended_by,
                'origins': program.origins,
                'destinations': program.destinations,
                'required_routes': [],
                'required_fixes': [],
                'total_flights': 0,
                'flights': [],
                'filed_compliant': [], 'filed_non_compliant': [],
                'flown_compliant': [], 'flown_non_compliant': [],
                'filed_compliance_pct': 100, 'flown_compliance_pct': 100,
                'program_history': program_history,
                'constrained_area': program.constrained_area,
                'reason': program.reason,
                'note': f'Missing origins ({program.origins}) or destinations ({program.destinations}) - cannot scope flights'
            }

        # Filter flights to those within the program window and matching OD
        flights = []
        for fuid, flight in flights_dict.items():
            callsign = flight.get('callsign', str(fuid))
            dep_time = (flight.get('atd_utc') or flight.get('off_utc') or flight.get('first_seen'))
            if not dep_time:
                continue
            dep_time = normalize_datetime(dep_time)

            if program.effective_start and program.effective_end:
                if dep_time >= program.effective_start and dep_time <= program.effective_end:
                    dept = flight.get('dept', '')
                    dest = flight.get('dest', '')
                    if dept in normalized_origs and dest in normalized_dests:
                        flights.append((callsign, dept, dest, flight.get('fp_route', ''), dep_time, flight.get('last_seen')))

        logger.info(f"  Filtered to {len(flights)} flights in program window")

        if not flights:
            return {
                'name': name,
                'mandatory': program.is_mandatory(),
                'route_type': program.route_type,
                'action': program.action,
                'assessment_mode': mode,
                'time_type': '',
                'start': program.effective_start.strftime('%H:%MZ') if program.effective_start else None,
                'end': program.effective_end.strftime('%H:%MZ') if program.effective_end else None,
                'ended_by': program.ended_by,
                'origins': program.origins,
                'destinations': program.destinations,
                'required_routes': [{'orig': ','.join(r.origins), 'dest': r.destination, 'route': r.route_string} for r in program.current_routes],
                'required_fixes': [],
                'total_flights': 0,
                'flights': [],
                'filed_compliant': [], 'filed_non_compliant': [],
                'flown_compliant': [], 'flown_non_compliant': [],
                'filed_compliance_pct': 100, 'flown_compliance_pct': 100,
                'program_history': program_history,
                'constrained_area': program.constrained_area,
                'reason': program.reason,
                'exemptions': program.exemptions,
                'associated_restrictions': program.associated_restrictions,
                'note': 'No flights found for reroute program scope'
            }

        # Build required fixes from current routes
        required_fixes_by_od = {}  # (orig, dest) -> [fix_list]
        all_required_fixes = []

        for route in program.current_routes:
            for orig in route.origins:
                key = (orig.upper(), route.destination.upper())
                fixes = route.required_fixes if route.required_fixes else re.findall(r'[A-Z]{3,5}', route.route_string)
                required_fixes_by_od[key] = fixes
                all_required_fixes.extend(fixes)

        all_required_fixes = list(set(all_required_fixes))

        # Load fix coordinates
        fixes_to_load = [f for f in all_required_fixes if f not in self.fix_coords]
        if fixes_to_load:
            self._load_fix_coordinates(fixes_to_load)

        # Pre-load trajectories
        all_callsigns = [row[0] for row in flights]
        if not self._trajectory_cache_loaded:
            self._preload_trajectories(all_callsigns)

        # Analyze each flight
        flight_results = []
        filed_compliant = []
        filed_non_compliant = []
        flown_compliant = []
        flown_non_compliant = []
        exempt_flights = []

        for row in flights:
            callsign, dept, dest, fp_route, dep_time, last_seen = row
            dep_time = normalize_datetime(dep_time)
            fp_route = fp_route or ''

            # For PLN/FYI modes, skip compliance scoring
            if mode == 'future_planning':
                flight_results.append({
                    'callsign': callsign, 'dept': dept, 'dest': dest,
                    'dept_time': dep_time.strftime('%H:%M:%SZ'),
                    'status': 'PENDING', 'filed_status': 'PENDING', 'flown_status': 'PENDING'
                })
                continue

            if mode == 'tracking_only':
                flight_results.append({
                    'callsign': callsign, 'dept': dept, 'dest': dest,
                    'dept_time': dep_time.strftime('%H:%M:%SZ'),
                    'status': 'MONITORING', 'filed_status': 'MONITORING', 'flown_status': 'MONITORING'
                })
                continue

            # Find matching route for this OD pair
            od_key = (dept.upper(), dest.upper())
            # Try exact match first, then try with ICAO normalization
            required_fixes = required_fixes_by_od.get(od_key, [])
            if not required_fixes:
                # Try normalized keys
                for (o, d), fixes in required_fixes_by_od.items():
                    o_codes = set(normalize_icao_list([o]))
                    d_codes = set(normalize_icao_list([d]))
                    if dept.upper() in o_codes and dest.upper() in d_codes:
                        required_fixes = fixes
                        break

            if not required_fixes:
                # No specific route for this OD pair - use all required fixes
                required_fixes = all_required_fixes

            # === FILED ROUTE ANALYSIS ===
            route_upper = fp_route.upper()

            # Check fixes in ORDER (sequence validation)
            filed_matched_fixes = []
            last_pos = -1
            for fix in required_fixes:
                pos = route_upper.find(fix)
                if pos >= 0 and pos > last_pos:
                    filed_matched_fixes.append(fix)
                    last_pos = pos
                elif pos >= 0:
                    # Fix present but out of order
                    filed_matched_fixes.append(fix)

            filed_match_pct = len(filed_matched_fixes) / len(required_fixes) * 100 if required_fixes else 0

            # Apply thresholds
            if filed_match_pct >= REROUTE_COMPLIANT_THRESHOLD * 100:
                filed_status = 'FILED_COMPLIANT'
            elif filed_match_pct >= REROUTE_PARTIAL_THRESHOLD * 100:
                filed_status = 'FILED_PARTIAL'
            else:
                filed_status = 'FILED_NON_COMPLIANT'

            # For RMD mode, soften NON_COMPLIANT to MONITORING
            if mode == 'usage_tracking' and filed_status == 'FILED_NON_COMPLIANT':
                filed_status = 'FILED_MONITORING'

            # === FLOWN ROUTE ANALYSIS ===
            trajectory = self._trajectory_cache.get(callsign, [])
            flown_matched_fixes = []
            flown_fix_details = []

            has_trajectory = bool(trajectory and len(trajectory) >= 2 and callsign not in self._low_quality_flights)

            if has_trajectory:
                for fix_name in required_fixes:
                    if fix_name in self.fix_coords:
                        fix_lat = self.fix_coords[fix_name]['lat']
                        fix_lon = self.fix_coords[fix_name]['lon']

                        min_dist = float('inf')
                        crossing_time = None
                        crossing_alt = None

                        for pt in trajectory:
                            dist = haversine_nm(pt['lat'], pt['lon'], fix_lat, fix_lon)
                            if dist < min_dist:
                                min_dist = dist
                                crossing_time = pt['timestamp']
                                crossing_alt = pt.get('alt', 0)

                        if min_dist <= CROSSING_RADIUS_NM:
                            flown_matched_fixes.append(fix_name)
                            flown_fix_details.append({
                                'fix': fix_name,
                                'distance_nm': round(min_dist, 1),
                                'crossing_time': crossing_time.strftime('%H:%M:%SZ') if crossing_time else None,
                                'altitude': int(crossing_alt) if crossing_alt else None
                            })

            flown_match_pct = len(flown_matched_fixes) / len(required_fixes) * 100 if required_fixes else 0

            if not has_trajectory:
                flown_status = 'NO_TRAJECTORY'
            elif flown_match_pct >= REROUTE_COMPLIANT_THRESHOLD * 100:
                flown_status = 'FLOWN_COMPLIANT'
            elif flown_match_pct >= REROUTE_PARTIAL_THRESHOLD * 100:
                flown_status = 'FLOWN_PARTIAL'
            else:
                flown_status = 'FLOWN_NON_COMPLIANT'

            if mode == 'usage_tracking' and flown_status == 'FLOWN_NON_COMPLIANT':
                flown_status = 'FLOWN_MONITORING'

            # Overall status = worst of filed and flown
            status_priority = {'COMPLIANT': 0, 'PARTIAL': 1, 'MONITORING': 2, 'NON_COMPLIANT': 3}
            filed_level = filed_status.replace('FILED_', '')
            flown_level = flown_status.replace('FLOWN_', '') if flown_status != 'NO_TRAJECTORY' else filed_level
            final_status = max([filed_level, flown_level], key=lambda s: status_priority.get(s, 4))

            flight_info = {
                'callsign': callsign,
                'dept': dept,
                'dest': dest,
                'dept_time': dep_time.strftime('%H:%M:%SZ'),
                'filed_route': fp_route,
                'filed_matched_fixes': filed_matched_fixes,
                'filed_match_pct': round(filed_match_pct, 1),
                'filed_status': filed_status,
                'has_trajectory': has_trajectory,
                'flown_matched_fixes': flown_matched_fixes,
                'flown_match_pct': round(flown_match_pct, 1),
                'flown_status': flown_status,
                'flown_fix_details': flown_fix_details,
                'final_status': final_status,
                'required_fixes': required_fixes
            }

            flight_results.append(flight_info)

            # Categorize
            if 'COMPLIANT' in filed_status:
                filed_compliant.append(flight_info)
            elif 'PARTIAL' in filed_status:
                filed_non_compliant.append(flight_info)  # Partial counts as non-compliant for summary
            else:
                filed_non_compliant.append(flight_info)

            if 'COMPLIANT' in flown_status:
                flown_compliant.append(flight_info)
            elif flown_status not in ('NO_TRAJECTORY',):
                flown_non_compliant.append(flight_info)

        # Calculate percentages
        filed_applicable = len(filed_compliant) + len(filed_non_compliant)
        filed_compliance_pct = round(100 * len(filed_compliant) / filed_applicable, 1) if filed_applicable > 0 else 100

        flown_applicable = len(flown_compliant) + len(flown_non_compliant)
        flown_compliance_pct = round(100 * len(flown_compliant) / flown_applicable, 1) if flown_applicable > 0 else 100

        no_trajectory_count = sum(1 for f in flight_results if f.get('flown_status') == 'NO_TRAJECTORY')

        return {
            'name': name,
            'mandatory': program.is_mandatory(),
            'route_type': program.route_type,
            'action': program.action,
            'assessment_mode': mode,
            'time_type': '',
            'start': program.effective_start.strftime('%H:%MZ') if program.effective_start else None,
            'end': program.effective_end.strftime('%H:%MZ') if program.effective_end else None,
            'ended_by': program.ended_by,
            'origins': program.origins,
            'destinations': program.destinations,
            'required_routes': [{'orig': ','.join(r.origins), 'dest': r.destination, 'route': r.route_string} for r in program.current_routes],
            'required_fixes': all_required_fixes,
            'total_flights': len(flights),
            'flights': flight_results,
            'filed_compliant': filed_compliant,
            'filed_non_compliant': filed_non_compliant,
            'filed_compliance_pct': filed_compliance_pct,
            'flown_compliant': flown_compliant,
            'flown_non_compliant': flown_non_compliant,
            'flown_compliance_pct': flown_compliance_pct,
            'no_trajectory_count': no_trajectory_count,
            # Legacy compat
            'using_route': filed_compliant,
            'not_using_route': filed_non_compliant,
            'compliance_pct': filed_compliance_pct,
            # Program metadata
            'program_history': program_history,
            'constrained_area': program.constrained_area,
            'reason': program.reason,
            'exemptions': program.exemptions,
            'associated_restrictions': program.associated_restrictions,
            'facilities': program.facilities
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

        for fuid, flight_data in flights.items():
            callsign = flight_data.get('callsign', str(fuid))
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
            'group_id': tmi.group_id or '',
            'original_facilities': tmi.original_facilities or '',
            'note': 'APREQ/CFR requires coordination verification - these flights would need release'
        }

    def _format_delay_entries(self) -> List[Dict]:
        """
        Format delay entries from NTML for output.

        Groups delays by airport and delay type, tracking how delays changed over time.
        """
        from .models import DelayType, DelayTrend, HoldingStatus

        delay_list = []

        for d in self.event.delays:
            entry = {
                'delay_type': d.delay_type.value if d.delay_type else 'UNKNOWN',
                'airport': d.airport,
                'facility': d.facility,
                'timestamp': d.timestamp_utc.strftime('%H:%MZ') if d.timestamp_utc else None,
                'delay_minutes': d.delay_minutes,
                'delay_trend': d.delay_trend.value if d.delay_trend else 'UNKNOWN',
                'delay_start': d.delay_start_utc.strftime('%H:%MZ') if d.delay_start_utc else None,
                'holding_status': d.holding_status.value if d.holding_status else 'NONE',
                'holding_fix': d.holding_fix,
                'aircraft_holding': d.aircraft_holding,
                'reason': d.reason,
                'raw_line': d.raw_line
            }
            delay_list.append(entry)

        # Sort by timestamp
        delay_list.sort(key=lambda x: x.get('timestamp') or '')

        return delay_list

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
            'reroute': {
                'total_reroutes': 0,
                'mandatory_count': 0,
                'total_flights': 0,
                'not_using_route': 0,
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

        # Reroute summary - both filed and flown compliance
        total_reroute_flights = 0
        total_filed_non_compliant = 0
        total_flown_non_compliant = 0
        total_flown_applicable = 0
        mandatory_count = 0
        filed_but_not_flown_total = 0
        flown_but_not_filed_total = 0

        for key, rr in results.get('reroute_results', {}).items():
            flights = rr.get('total_flights', 0)
            total_reroute_flights += flights

            # Filed compliance
            filed_non_compliant = len(rr.get('filed_non_compliant', rr.get('not_using_route', [])))

            # Flown compliance (only for flights with trajectory data)
            flown_non_compliant = len(rr.get('flown_non_compliant', []))
            no_trajectory = rr.get('no_trajectory_count', 0)
            flown_applicable = flights - no_trajectory

            if rr.get('mandatory', False):
                mandatory_count += 1
                total_filed_non_compliant += filed_non_compliant
                total_flown_non_compliant += flown_non_compliant
                total_flown_applicable += flown_applicable

            # Discrepancy tracking
            filed_but_not_flown_total += rr.get('filed_but_not_flown', 0)
            flown_but_not_filed_total += rr.get('flown_but_not_filed', 0)

        summary['reroute'] = {
            'total_reroutes': len(results.get('reroute_results', {})),
            'mandatory_count': mandatory_count,
            'total_flights': total_reroute_flights,
            # Filed compliance (what was filed)
            'filed_non_compliant': total_filed_non_compliant,
            'filed_compliance_pct': round(100 * (total_reroute_flights - total_filed_non_compliant) / total_reroute_flights, 1) if total_reroute_flights > 0 else 100,
            # Flown compliance (what actually happened)
            'flown_applicable': total_flown_applicable,
            'flown_non_compliant': total_flown_non_compliant,
            'flown_compliance_pct': round(100 * (total_flown_applicable - total_flown_non_compliant) / total_flown_applicable, 1) if total_flown_applicable > 0 else 100,
            # Discrepancy analysis
            'filed_but_not_flown': filed_but_not_flown_total,
            'flown_but_not_filed': flown_but_not_filed_total,
            # Legacy fields for backward compat
            'not_using_route': total_filed_non_compliant,
            'compliance_pct': round(100 * (total_reroute_flights - total_filed_non_compliant) / total_reroute_flights, 1) if total_reroute_flights > 0 else 100
        }

        # Add action breakdown
        action_counts = {'RQD': 0, 'RMD': 0, 'PLN': 0, 'FYI': 0}
        for key, rr in results.get('reroute_results', {}).items():
            action = rr.get('action', 'FYI')
            if action in action_counts:
                action_counts[action] += 1
        summary['reroute']['action_breakdown'] = action_counts

        # Holding summary
        holding = results.get('holding', {}).get('summary', {})
        summary['holding'] = {
            'total_flights_holding': holding.get('total_flights_holding', 0),
            'total_hold_events': holding.get('total_hold_events', 0),
            'total_hold_duration_min': round(holding.get('total_hold_duration_sec', 0) / 60, 1),
            'avg_hold_duration_min': round(holding.get('avg_hold_duration_sec', 0) / 60, 1),
        }

        # Overall (including mandatory reroutes - use flown compliance for reroutes)
        # For reroutes, flown compliance is the more accurate measure of what actually happened
        total_items = total_pairs + total_applicable + total_flown_applicable
        total_issues = total_violations + total_gs_violations + total_flown_non_compliant
        summary['overall_compliance_pct'] = round(100 * (total_items - total_issues) / total_items, 1) if total_items > 0 else 100

        return summary
