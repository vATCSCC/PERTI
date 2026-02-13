#!/usr/bin/env python3
"""
Escape the Desert FNO - Complete MIT Analysis
January 17-18, 2026

Analyzes flight data from SWIM_API and ADL databases to compute MIT compliance.
"""

import pyodbc
import json
from datetime import datetime, timezone
from math import radians, sin, cos, sqrt, atan2
from collections import defaultdict
import os

# Event parameters
EVENT_START = '2026-01-17 22:00:00'
EVENT_END = '2026-01-18 05:00:00'
ARRIVAL_AIRPORTS = ['KLAS', 'KVGT', 'KHND']
DEPARTURE_AIRPORTS = ['KSFO', 'KOAK', 'KSJC', 'KSMF']
ALL_EVENT_AIRPORTS = ARRIVAL_AIRPORTS + DEPARTURE_AIRPORTS

# MIT Reference from event briefing
MIT_REFERENCE = [
    {'fix': 'FLCHR', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZOA', 'time': '2359-0400'},
    {'fix': 'ELLDA', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZAB', 'time': '2359-0400'},
    {'fix': 'HAHAA', 'dest': 'KLAS', 'mit_nm': 30, 'provider': 'ZLA', 'requestor': 'ZAB', 'time': '2359-0400'},
    {'fix': 'GGAPP', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZLC', 'time': '2359-0400'},
    {'fix': 'STEWW', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZLC', 'time': '2359-0400'},
    {'fix': 'TYEGR', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZDV', 'time': '2359-0400'},
    {'fix': 'NTELL', 'dest': 'KLAS', 'mit_nm': 15, 'provider': 'ZOA', 'requestor': 'NCT', 'time': '2359-0400'},
    {'fix': 'RUSME', 'dest': 'KSFO', 'mit_nm': 20, 'provider': 'ZOA', 'requestor': 'ZLA/ZLC', 'time': '2359-0400'},
    {'fix': 'INYOE', 'dest': 'KSFO', 'mit_nm': 20, 'provider': 'ZOA', 'requestor': 'ZLA/ZLC', 'time': '2359-0400'},
    {'fix': 'LEGGS', 'dest': 'KSFO', 'mit_nm': 35, 'provider': 'ZOA', 'requestor': 'ZLC', 'time': '2300-0300'},
]
MIT_FIXES = [m['fix'] for m in MIT_REFERENCE]

# Arrival stream mapping
STREAM_PATTERNS = {
    'SOUTH_ZOA_ZLA': ['FLCHR', 'KEPEC', 'TYSSN', 'BASET', 'FUZZY'],
    'NE_ZDV': ['TYEGR', 'CLARR', 'KADDY'],
    'NNE_ZLC': ['GGAPP', 'STEWW', 'PRFUM', 'SUNST', 'JELIR'],
    'EAST_ZAB': ['ELLDA', 'HAHAA', 'GRNPA', 'RNCHH', 'PRINO'],
    'NCT_LOCAL': ['NTELL', 'INYOE', 'RUSME'],
    'SFO_ARRIVALS': ['LEGGS', 'INYOE', 'RUSME', 'DYAMD'],
}

def get_swim_conn():
    return pyodbc.connect(
        "DRIVER={ODBC Driver 18 for SQL Server};"
        "SERVER=vatsim.database.windows.net;"
        "DATABASE=SWIM_API;"
        "UID=adl_api_user;"
        f'PWD={os.environ["ADL_SQL_PASSWORD"]};'
        "Encrypt=yes;TrustServerCertificate=yes;"
    )

def get_adl_conn():
    return pyodbc.connect(
        "DRIVER={ODBC Driver 18 for SQL Server};"
        "SERVER=vatsim.database.windows.net;"
        "DATABASE=VATSIM_ADL;"
        "UID=adl_api_user;"
        f'PWD={os.environ["ADL_SQL_PASSWORD"]};'
        "Encrypt=yes;TrustServerCertificate=yes;"
    )

def haversine_nm(lat1, lon1, lat2, lon2):
    """Calculate distance in nautical miles"""
    R = 3440.065
    lat1, lon1, lat2, lon2 = map(radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = sin(dlat/2)**2 + cos(lat1) * cos(lat2) * sin(dlon/2)**2
    c = 2 * atan2(sqrt(a), sqrt(1-a))
    return R * c

def detect_stream(route):
    """Detect arrival stream from route string"""
    route = (route or '').upper()
    for stream, fixes in STREAM_PATTERNS.items():
        for fix in fixes:
            if fix in route:
                return stream
    return 'OTHER'

def detect_mit_fix(route):
    """Detect MIT control fix from route string"""
    route = (route or '').upper()
    for fix in MIT_FIXES:
        if fix in route:
            return fix
    return None

def get_fix_coordinates(conn):
    """Get MIT fix coordinates from nav_fixes table"""
    cursor = conn.cursor()
    fix_list = "','".join(MIT_FIXES)
    cursor.execute(f"""
        SELECT fix_name, lat, lon FROM dbo.nav_fixes
        WHERE fix_name IN ('{fix_list}')
    """)
    coords = {}
    for row in cursor.fetchall():
        coords[row[0]] = (float(row[1]), float(row[2]))
    return coords

def get_event_flights_swim():
    """Get event flights from SWIM_API database"""
    print("\n" + "=" * 70)
    print("QUERYING SWIM_API DATABASE")
    print("=" * 70)

    conn = get_swim_conn()
    cursor = conn.cursor()

    # Get arrivals to event airports
    dest_list = "','".join(ALL_EVENT_AIRPORTS)
    cursor.execute(f"""
        SELECT
            flight_uid, callsign, cid,
            fp_dept_icao, fp_dest_icao, fp_route,
            star_name, afix, arrival_stream,
            first_seen_utc, last_seen_utc, eta_utc,
            lat, lon, groundspeed_kts, altitude_ft,
            fp_dept_artcc, fp_dest_artcc,
            aircraft_type, weight_class, airline_icao
        FROM dbo.swim_flights
        WHERE fp_dest_icao IN ('{dest_list}')
          AND (
            (first_seen_utc <= '{EVENT_END}' AND last_seen_utc >= '{EVENT_START}')
            OR eta_utc BETWEEN '{EVENT_START}' AND '{EVENT_END}'
          )
        ORDER BY eta_utc
    """)

    columns = [d[0] for d in cursor.description]
    rows = cursor.fetchall()
    flights = [dict(zip(columns, row)) for row in rows]

    print(f"Found {len(flights)} flights to event airports")

    # Get departures from Bay Area
    dept_list = "','".join(DEPARTURE_AIRPORTS)
    cursor.execute(f"""
        SELECT
            flight_uid, callsign, cid,
            fp_dept_icao, fp_dest_icao, fp_route,
            star_name, afix, arrival_stream,
            first_seen_utc, last_seen_utc, eta_utc,
            lat, lon, groundspeed_kts, altitude_ft,
            fp_dept_artcc, fp_dest_artcc,
            aircraft_type, weight_class, airline_icao
        FROM dbo.swim_flights
        WHERE fp_dept_icao IN ('{dept_list}')
          AND (
            (first_seen_utc <= '{EVENT_END}' AND last_seen_utc >= '{EVENT_START}')
            OR eta_utc BETWEEN '{EVENT_START}' AND '{EVENT_END}'
          )
        ORDER BY eta_utc
    """)

    dept_flights = [dict(zip(columns, row)) for row in cursor.fetchall()]
    print(f"Found {len(dept_flights)} departures from Bay Area")

    conn.close()
    return flights, dept_flights

def get_event_flights_adl():
    """Get event flights from ADL database (flight_core + flight_plan)"""
    print("\n" + "=" * 70)
    print("QUERYING VATSIM_ADL DATABASE")
    print("=" * 70)

    conn = get_adl_conn()
    cursor = conn.cursor()

    # Get flights from adl_flight_core joined with flight_plan
    dest_list = "','".join(ALL_EVENT_AIRPORTS)
    cursor.execute(f"""
        SELECT
            c.flight_uid, c.callsign, c.cid,
            p.dept_icao, p.dest_icao, p.route,
            p.star_name, p.afix, p.arrival_stream,
            c.first_seen_utc, c.last_seen_utc,
            pos.lat, pos.lon, pos.groundspeed_kts, pos.altitude_ft,
            p.dept_artcc, p.dest_artcc,
            p.aircraft_icao, p.weight_class
        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        LEFT JOIN dbo.adl_flight_position pos ON c.flight_uid = pos.flight_uid
        WHERE p.dest_icao IN ('{dest_list}')
          AND (
            (c.first_seen_utc <= '{EVENT_END}' AND c.last_seen_utc >= '{EVENT_START}')
          )
        ORDER BY c.first_seen_utc
    """)

    columns = [d[0] for d in cursor.description]
    rows = cursor.fetchall()
    flights = [dict(zip(columns, row)) for row in rows]

    print(f"Found {len(flights)} flights in ADL database")

    # Get fix coordinates
    fix_coords = get_fix_coordinates(conn)
    print(f"Loaded {len(fix_coords)} fix coordinates")

    conn.close()
    return flights, fix_coords

def analyze_arrivals(flights, fix_coords):
    """Analyze arrivals for MIT compliance"""
    print("\n" + "=" * 70)
    print("ARRIVAL ANALYSIS")
    print("=" * 70)

    # Categorize by destination
    by_dest = defaultdict(list)
    for f in flights:
        dest = f.get('fp_dest_icao') or f.get('dest_icao') or 'UNK'
        by_dest[dest].append(f)

    print("\nFlights by destination:")
    for dest in sorted(by_dest.keys()):
        print(f"  {dest}: {len(by_dest[dest])}")

    # Categorize by arrival stream
    by_stream = defaultdict(list)
    for f in flights:
        route = f.get('fp_route') or f.get('route') or ''
        stream = detect_stream(route)
        f['_detected_stream'] = stream
        by_stream[stream].append(f)

    print("\nFlights by arrival stream:")
    for stream in sorted(by_stream.keys(), key=lambda s: -len(by_stream[s])):
        print(f"  {stream}: {len(by_stream[stream])}")

    # Categorize by MIT fix
    by_fix = defaultdict(list)
    for f in flights:
        route = f.get('fp_route') or f.get('route') or ''
        fix = detect_mit_fix(route)
        f['_detected_fix'] = fix
        if fix:
            by_fix[fix].append(f)

    print("\nFlights by MIT control fix:")
    for fix in MIT_FIXES:
        if fix in by_fix:
            print(f"  {fix}: {len(by_fix[fix])}")

    return by_dest, by_stream, by_fix

def compute_spacing(flights_by_fix, fix_coords):
    """Compute spacing between sequential aircraft at MIT fixes"""
    print("\n" + "=" * 70)
    print("MIT SPACING ANALYSIS")
    print("=" * 70)

    # Get required MIT values
    mit_lookup = {m['fix']: m['mit_nm'] for m in MIT_REFERENCE}

    results = {}

    for fix, flights in flights_by_fix.items():
        if len(flights) < 2:
            continue

        required = mit_lookup.get(fix, 20)

        # Sort by ETA
        sorted_flights = sorted(flights, key=lambda f: f.get('eta_utc') or f.get('last_seen_utc') or datetime.max)

        spacings = []
        violations = []

        for i in range(1, len(sorted_flights)):
            prev = sorted_flights[i-1]
            curr = sorted_flights[i]

            # Get timestamps
            prev_time = prev.get('eta_utc') or prev.get('last_seen_utc')
            curr_time = curr.get('eta_utc') or curr.get('last_seen_utc')

            if prev_time and curr_time:
                # Calculate time difference in minutes
                if isinstance(prev_time, datetime) and isinstance(curr_time, datetime):
                    time_diff_min = (curr_time - prev_time).total_seconds() / 60
                else:
                    continue

                # Estimate spacing using average groundspeed (assume 250 kts)
                avg_gs = 250  # kts
                estimated_spacing = (time_diff_min * avg_gs) / 60  # nm

                spacings.append({
                    'prev_callsign': prev.get('callsign'),
                    'curr_callsign': curr.get('callsign'),
                    'time_diff_min': round(time_diff_min, 1),
                    'estimated_spacing_nm': round(estimated_spacing, 1),
                    'compliant': estimated_spacing >= required
                })

                if estimated_spacing < required:
                    violations.append(spacings[-1])

        if spacings:
            spacing_values = [s['estimated_spacing_nm'] for s in spacings]
            results[fix] = {
                'required_mit_nm': required,
                'total_flights': len(flights),
                'spacing_pairs': len(spacings),
                'avg_spacing_nm': round(sum(spacing_values) / len(spacing_values), 1),
                'min_spacing_nm': round(min(spacing_values), 1),
                'max_spacing_nm': round(max(spacing_values), 1),
                'violations': len(violations),
                'compliance_pct': round(100 * (1 - len(violations) / len(spacings)), 1),
                'violation_details': violations[:5]  # Top 5 violations
            }

            print(f"\n{fix}:")
            print(f"  Required MIT: {required} nm")
            print(f"  Total flights: {len(flights)}")
            print(f"  Avg spacing: {results[fix]['avg_spacing_nm']} nm")
            print(f"  Min spacing: {results[fix]['min_spacing_nm']} nm")
            print(f"  Violations: {len(violations)} ({100 - results[fix]['compliance_pct']:.1f}%)")

            if violations:
                print(f"  Tightest pairs:")
                for v in sorted(violations, key=lambda x: x['estimated_spacing_nm'])[:3]:
                    print(f"    {v['prev_callsign']} -> {v['curr_callsign']}: {v['estimated_spacing_nm']} nm ({v['time_diff_min']} min)")

    return results

def print_flight_details(flights, limit=50):
    """Print detailed flight list"""
    print("\n" + "=" * 70)
    print(f"FLIGHT DETAILS (showing {min(len(flights), limit)} of {len(flights)})")
    print("=" * 70)

    print(f"\n{'Callsign':<10} {'Dept':<5} {'Dest':<5} {'Stream':<15} {'Fix':<8} {'ETA':<20} {'Route (truncated)'}")
    print("-" * 100)

    for f in flights[:limit]:
        callsign = f.get('callsign', '?')[:9]
        dept = (f.get('fp_dept_icao') or f.get('dept_icao') or '?')[:4]
        dest = (f.get('fp_dest_icao') or f.get('dest_icao') or '?')[:4]
        stream = (f.get('_detected_stream') or '?')[:14]
        fix = (f.get('_detected_fix') or '-')[:7]
        eta = f.get('eta_utc') or f.get('last_seen_utc')
        eta_str = eta.strftime('%Y-%m-%d %H:%M') if isinstance(eta, datetime) else str(eta)[:19]
        route = (f.get('fp_route') or f.get('route') or '')[:40]

        print(f"{callsign:<10} {dept:<5} {dest:<5} {stream:<15} {fix:<8} {eta_str:<20} {route}")

def generate_report(flights, by_dest, by_stream, by_fix, spacing_results):
    """Generate final report"""
    print("\n" + "=" * 70)
    print("ESCAPE THE DESERT FNO - MIT ANALYSIS REPORT")
    print("=" * 70)

    print(f"""
Event: Escape the Desert FNO
Date: January 17-18, 2026
Window: {EVENT_START} to {EVENT_END} UTC
Generated: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')} UTC

SUMMARY
=======
Total event flights analyzed: {len(flights)}

Arrivals by Airport:
""")

    for dest in ARRIVAL_AIRPORTS:
        count = len(by_dest.get(dest, []))
        print(f"  {dest}: {count}")

    print(f"""
Arrivals by Stream:
""")
    for stream in ['SOUTH_ZOA_ZLA', 'NE_ZDV', 'NNE_ZLC', 'EAST_ZAB', 'NCT_LOCAL', 'OTHER']:
        count = len(by_stream.get(stream, []))
        if count > 0:
            print(f"  {stream}: {count}")

    print(f"""
MIT COMPLIANCE SUMMARY
======================
""")

    if spacing_results:
        total_violations = sum(r['violations'] for r in spacing_results.values())
        total_pairs = sum(r['spacing_pairs'] for r in spacing_results.values())
        overall_compliance = 100 * (1 - total_violations / total_pairs) if total_pairs > 0 else 100

        print(f"Overall compliance: {overall_compliance:.1f}%")
        print(f"Total violations: {total_violations} of {total_pairs} pairs")
        print()

        for fix in MIT_FIXES:
            if fix in spacing_results:
                r = spacing_results[fix]
                print(f"{fix}:")
                print(f"  Required: {r['required_mit_nm']} nm | Avg: {r['avg_spacing_nm']} nm | Min: {r['min_spacing_nm']} nm")
                print(f"  Compliance: {r['compliance_pct']}% ({r['violations']} violations)")
    else:
        print("Insufficient data to compute spacing compliance.")

    print("""
NOTES
=====
- Spacing estimates based on ETA differences and assumed 250kt average
- More precise analysis requires trajectory position data
- Event TMI restrictions were active 2359Z-0400Z
""")

def main():
    print("=" * 70)
    print("ESCAPE THE DESERT FNO - MIT ANALYSIS")
    print(f"Event: January 17-18, 2026")
    print(f"Analysis Started: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')} UTC")
    print("=" * 70)

    try:
        # Get flights from SWIM database
        arr_flights, dept_flights = get_event_flights_swim()

        # Get additional data from ADL
        adl_flights, fix_coords = get_event_flights_adl()

        # Combine and deduplicate by callsign
        all_flights = arr_flights + dept_flights
        seen_callsigns = set()
        unique_flights = []
        for f in all_flights:
            cs = f.get('callsign')
            if cs and cs not in seen_callsigns:
                seen_callsigns.add(cs)
                unique_flights.append(f)

        print(f"\nTotal unique flights: {len(unique_flights)}")

        # Analyze arrivals
        by_dest, by_stream, by_fix = analyze_arrivals(unique_flights, fix_coords)

        # Compute spacing
        spacing_results = compute_spacing(by_fix, fix_coords)

        # Print flight details
        print_flight_details(unique_flights, limit=50)

        # Generate report
        generate_report(unique_flights, by_dest, by_stream, by_fix, spacing_results)

        print("\n" + "=" * 70)
        print("ANALYSIS COMPLETE")
        print("=" * 70)

    except Exception as e:
        print(f"\nERROR: {e}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    main()
