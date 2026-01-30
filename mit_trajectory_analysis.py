"""
MIT Compliance Analysis using Actual Trajectory Data
Escape the Desert FNO - January 17-18, 2026

This script analyzes actual flown positions from adl_trajectory_archive
to determine when aircraft crossed MIT control fixes.
"""

import pyodbc
import math
from datetime import datetime, timedelta
from collections import defaultdict

# Database connection (from config.php)
DB_HOST = 'vatsim.database.windows.net'
DB_NAME = 'VATSIM_ADL'
DB_USER = 'adl_api_user'
DB_PASS = '***REMOVED***'

# Event parameters
EVENT_START = '2026-01-17 22:00:00'
EVENT_END = '2026-01-18 05:00:00'

# MIT Reference with valid time windows
MIT_REFERENCE = [
    {'fix': 'FLCHR', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZOA',
     'start_utc': datetime(2026, 1, 17, 23, 59), 'end_utc': datetime(2026, 1, 18, 4, 0)},
    {'fix': 'ELLDA', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZAB',
     'start_utc': datetime(2026, 1, 17, 23, 59), 'end_utc': datetime(2026, 1, 18, 4, 0)},
    {'fix': 'HAHAA', 'dest': 'KLAS', 'mit_nm': 30, 'provider': 'ZLA', 'requestor': 'ZAB',
     'start_utc': datetime(2026, 1, 17, 23, 59), 'end_utc': datetime(2026, 1, 18, 4, 0)},
    {'fix': 'GGAPP', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZLC',
     'start_utc': datetime(2026, 1, 17, 23, 59), 'end_utc': datetime(2026, 1, 18, 4, 0)},
    {'fix': 'STEWW', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZLC',
     'start_utc': datetime(2026, 1, 17, 23, 59), 'end_utc': datetime(2026, 1, 18, 4, 0)},
    {'fix': 'TYEGR', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZDV',
     'start_utc': datetime(2026, 1, 17, 23, 59), 'end_utc': datetime(2026, 1, 18, 4, 0)},
    {'fix': 'NTELL', 'dest': 'KLAS', 'mit_nm': 15, 'provider': 'ZOA', 'requestor': 'NCT',
     'start_utc': datetime(2026, 1, 17, 23, 59), 'end_utc': datetime(2026, 1, 18, 4, 0)},
]
MIT_FIXES = [m['fix'] for m in MIT_REFERENCE]

# Crossing detection radius (nm) - aircraft within this distance are considered to have crossed
CROSSING_RADIUS_NM = 8


def haversine_nm(lat1, lon1, lat2, lon2):
    """Calculate distance between two points in nautical miles"""
    R = 3440.065  # Earth radius in nm
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = math.sin(dlat/2)**2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon/2)**2
    c = 2 * math.asin(math.sqrt(a))
    return R * c


def get_tmi_for_fix(fix_name):
    """Get the TMI reference for a given fix"""
    for m in MIT_REFERENCE:
        if m['fix'] == fix_name:
            return m
    return None


def is_within_tmi_window(crossing_time, tmi):
    """Check if a crossing time falls within the TMI valid window"""
    if not isinstance(crossing_time, datetime):
        return False
    if crossing_time.tzinfo is not None:
        crossing_time = crossing_time.replace(tzinfo=None)
    return tmi['start_utc'] <= crossing_time <= tmi['end_utc']


def main():
    print("=" * 70)
    print("MIT COMPLIANCE ANALYSIS - TRAJECTORY-BASED")
    print("Escape the Desert FNO - January 17-18, 2026")
    print("Using adl_trajectory_archive for actual flown positions")
    print("=" * 70)

    # Connect to database
    conn_str = (
        f'DRIVER={{ODBC Driver 18 for SQL Server}};'
        f'SERVER={DB_HOST};'
        f'DATABASE={DB_NAME};'
        f'UID={DB_USER};'
        f'PWD={DB_PASS};'
        f'TrustServerCertificate=yes;'
        f'Connection Timeout=60'
    )

    print("\nConnecting to database...")
    conn = pyodbc.connect(conn_str)
    cursor = conn.cursor()
    print("Connected!")

    # =========================================================================
    # Step 1: Get fix coordinates
    # =========================================================================
    print("\n" + "=" * 70)
    print("STEP 1: Loading Fix Coordinates")
    print("=" * 70)

    fix_coords = {}
    fix_in = "'" + "','".join(MIT_FIXES) + "'"
    cursor.execute(f"""
        SELECT fix_name, lat, lon FROM dbo.nav_fixes
        WHERE fix_name IN ({fix_in})
        GROUP BY fix_name, lat, lon
    """)
    for row in cursor.fetchall():
        fix_coords[row[0]] = {'lat': float(row[1]), 'lon': float(row[2])}
        print(f"  {row[0]}: {row[1]:.4f}, {row[2]:.4f}")

    # =========================================================================
    # Step 2: Get KLAS-bound callsigns
    # =========================================================================
    print("\n" + "=" * 70)
    print("STEP 2: Identifying KLAS-bound Flights")
    print("=" * 70)

    cursor.execute("""
        SELECT DISTINCT c.callsign, p.fp_dept_icao
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE p.fp_dest_icao = 'KLAS'
          AND c.first_seen_utc <= ?
          AND c.last_seen_utc >= ?
    """, (EVENT_END, EVENT_START))

    klas_callsigns = {}
    for row in cursor.fetchall():
        klas_callsigns[row[0]] = row[1]  # callsign -> dept airport

    print(f"  Found {len(klas_callsigns)} KLAS-bound callsigns in event window")

    # =========================================================================
    # Step 3: Query trajectory archive for these flights near MIT fixes
    # =========================================================================
    print("\n" + "=" * 70)
    print("STEP 3: Finding Fix Crossings from Position History")
    print("=" * 70)

    # For each fix, query positions within a bounding box, then filter by precise distance
    crossings_by_fix = defaultdict(list)

    for fix_name, coords in fix_coords.items():
        fix_lat = coords['lat']
        fix_lon = coords['lon']

        # Bounding box: ~0.15 degrees ≈ 9nm at these latitudes
        lat_margin = 0.15
        lon_margin = 0.20  # Slightly larger for longitude at this latitude

        print(f"\n  Querying positions near {fix_name} ({fix_lat:.3f}, {fix_lon:.3f})...")

        # Query positions in bounding box for KLAS arrivals
        callsign_list = "'" + "','".join(klas_callsigns.keys()) + "'"

        cursor.execute(f"""
            SELECT callsign, timestamp_utc, lat, lon, groundspeed_kts, altitude_ft
            FROM dbo.adl_trajectory_archive
            WHERE timestamp_utc >= ?
              AND timestamp_utc <= ?
              AND callsign IN ({callsign_list})
              AND lat BETWEEN ? AND ?
              AND lon BETWEEN ? AND ?
            ORDER BY callsign, timestamp_utc
        """, (
            EVENT_START, EVENT_END,
            fix_lat - lat_margin, fix_lat + lat_margin,
            fix_lon - lon_margin, fix_lon + lon_margin
        ))

        positions = cursor.fetchall()
        print(f"    Found {len(positions)} position records in bounding box")

        # Group by callsign and find closest approach for each flight
        flight_positions = defaultdict(list)
        for pos in positions:
            flight_positions[pos[0]].append(pos)

        for callsign, pos_list in flight_positions.items():
            # Find the position closest to the fix
            closest_dist = float('inf')
            closest_pos = None

            for pos in pos_list:
                ts, lat, lon, gs, alt = pos[1], pos[2], pos[3], pos[4], pos[5]
                dist = haversine_nm(lat, lon, fix_lat, fix_lon)

                if dist < closest_dist:
                    closest_dist = dist
                    closest_pos = {
                        'callsign': callsign,
                        'crossing_time': ts,
                        'distance_nm': dist,
                        'groundspeed': gs if gs and gs > 100 else 250,
                        'altitude': alt,
                        'dept': klas_callsigns.get(callsign, 'UNK')
                    }

            # Only count as crossing if within radius
            if closest_pos and closest_dist <= CROSSING_RADIUS_NM:
                crossings_by_fix[fix_name].append(closest_pos)

        print(f"    Crossings detected: {len(crossings_by_fix[fix_name])}")
        if crossings_by_fix[fix_name]:
            # Show a few examples
            for c in crossings_by_fix[fix_name][:3]:
                ts = c['crossing_time']
                ts_str = ts.strftime('%H:%M:%SZ') if isinstance(ts, datetime) else str(ts)
                print(f"      {c['callsign']} @ {ts_str} - {c['distance_nm']:.1f}nm from fix")

    # =========================================================================
    # Step 4: Calculate MIT Compliance for each fix
    # =========================================================================
    print("\n" + "=" * 70)
    print("STEP 4: MIT COMPLIANCE ANALYSIS")
    print("=" * 70)

    compliance_results = {}

    for fix in MIT_FIXES:
        all_crossings = crossings_by_fix.get(fix, [])
        if len(all_crossings) < 2:
            print(f"\n{fix}: Insufficient crossings ({len(all_crossings)})")
            continue

        tmi = get_tmi_for_fix(fix)
        if not tmi:
            continue

        required = tmi['mit_nm']
        tmi_start = tmi['start_utc']
        tmi_end = tmi['end_utc']

        # Filter to crossings within TMI window
        valid_crossings = []
        for c in all_crossings:
            ct = c['crossing_time']
            if isinstance(ct, datetime):
                if ct.tzinfo is not None:
                    ct = ct.replace(tzinfo=None)
                if tmi_start <= ct <= tmi_end:
                    valid_crossings.append(c)

        excluded_count = len(all_crossings) - len(valid_crossings)

        if len(valid_crossings) < 2:
            print(f"\n{fix}:")
            print(f"  TMI Valid: {tmi_start.strftime('%H:%MZ')} - {tmi_end.strftime('%H:%MZ')}")
            print(f"  Total crossings: {len(all_crossings)}, Within TMI window: {len(valid_crossings)}")
            print(f"  Insufficient crossings within TMI window")
            continue

        # Sort by crossing time
        def get_time(c):
            t = c['crossing_time']
            if isinstance(t, datetime):
                return t.replace(tzinfo=None) if t.tzinfo else t
            return datetime.max

        sorted_crossings = sorted(valid_crossings, key=get_time)

        spacings = []
        violations = []

        for i in range(1, len(sorted_crossings)):
            prev = sorted_crossings[i-1]
            curr = sorted_crossings[i]

            prev_time = get_time(prev)
            curr_time = get_time(curr)

            time_diff = (curr_time - prev_time).total_seconds()
            time_diff_min = time_diff / 60

            if time_diff <= 0:
                continue

            # Calculate spacing using groundspeed
            gs = curr['groundspeed']
            spacing_nm = (time_diff_min * gs) / 60

            pair = {
                'prev_callsign': prev['callsign'],
                'curr_callsign': curr['callsign'],
                'prev_time': prev_time.strftime('%H:%M:%S'),
                'curr_time': curr_time.strftime('%H:%M:%S'),
                'time_min': round(time_diff_min, 1),
                'spacing_nm': round(spacing_nm, 1),
                'gs': gs
            }
            spacings.append(pair)

            if spacing_nm < required:
                violations.append(pair)

        if spacings:
            spacing_vals = [s['spacing_nm'] for s in spacings]
            compliance_results[fix] = {
                'required': required,
                'tmi_start': tmi_start.strftime('%H:%MZ'),
                'tmi_end': tmi_end.strftime('%H:%MZ'),
                'total_crossings': len(all_crossings),
                'crossings_in_window': len(valid_crossings),
                'excluded': excluded_count,
                'pairs': len(spacings),
                'avg': round(sum(spacing_vals) / len(spacing_vals), 1),
                'min': round(min(spacing_vals), 1),
                'max': round(max(spacing_vals), 1),
                'violations': len(violations),
                'compliance': round(100 * (1 - len(violations) / len(spacings)), 1),
                'violation_details': sorted(violations, key=lambda x: x['spacing_nm'])[:10],
                'all_pairs': spacings
            }

            print(f"\n{fix}:")
            print(f"  TMI Valid: {tmi_start.strftime('%H:%MZ')} - {tmi_end.strftime('%H:%MZ')}")
            print(f"  Required MIT: {required} nm")
            print(f"  Crossings: {len(all_crossings)} total, {len(valid_crossings)} in TMI window ({excluded_count} excluded)")
            print(f"  Pairs analyzed: {len(spacings)}")
            print(f"  Spacing: avg={compliance_results[fix]['avg']} nm, min={compliance_results[fix]['min']} nm, max={compliance_results[fix]['max']} nm")
            print(f"  Violations: {len(violations)} ({100 - compliance_results[fix]['compliance']:.1f}% non-compliant)")

            if violations:
                print(f"\n  Worst violations:")
                for v in sorted(violations, key=lambda x: x['spacing_nm'])[:5]:
                    print(f"    {v['prev_callsign']} ({v['prev_time']}) -> {v['curr_callsign']} ({v['curr_time']}): {v['spacing_nm']} nm ({v['time_min']} min @ {v['gs']} kts)")

            print(f"\n  All crossings (in order):")
            for c in sorted_crossings:
                ts = get_time(c).strftime('%H:%M:%SZ')
                print(f"    {c['callsign']} @ {ts} ({c['distance_nm']:.1f}nm from fix, gs={c['groundspeed']})")

    # =========================================================================
    # Summary
    # =========================================================================
    print("\n" + "=" * 70)
    print("SUMMARY")
    print("=" * 70)

    if compliance_results:
        total_violations = sum(r['violations'] for r in compliance_results.values())
        total_pairs = sum(r['pairs'] for r in compliance_results.values())
        overall = 100 * (1 - total_violations / total_pairs) if total_pairs > 0 else 0

        print(f"\nOverall MIT Compliance: {overall:.1f}%")
        print(f"Total violations: {total_violations} of {total_pairs} pairs")
        print(f"\nBy Fix:")
        for fix in MIT_FIXES:
            if fix in compliance_results:
                r = compliance_results[fix]
                status = "OK" if r['compliance'] >= 90 else "WARN" if r['compliance'] >= 75 else "FAIL"
                print(f"  {fix}: {r['compliance']:.1f}% compliant ({r['violations']}/{r['pairs']} violations) [{status}]")
    else:
        print("\nNo fix crossings detected - unable to calculate compliance")

    conn.close()
    print("\n" + "=" * 70)
    print("ANALYSIS COMPLETE")
    print("=" * 70)


if __name__ == "__main__":
    main()
