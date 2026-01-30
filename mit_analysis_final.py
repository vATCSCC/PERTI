#!/usr/bin/env python3
"""
Escape the Desert FNO - Final MIT Analysis
January 17-18, 2026

Uses correct column names from verified schema.
"""

import pyodbc
from datetime import datetime, timezone
from collections import defaultdict

# Event parameters
EVENT_START = '2026-01-17 22:00:00'
EVENT_END = '2026-01-18 05:00:00'
ARRIVAL_AIRPORTS = ['KLAS', 'KVGT', 'KHND']
DEPARTURE_AIRPORTS = ['KSFO', 'KOAK', 'KSJC', 'KSMF']

# MIT Reference
MIT_REFERENCE = [
    {'fix': 'FLCHR', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZOA'},
    {'fix': 'ELLDA', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZAB'},
    {'fix': 'HAHAA', 'dest': 'KLAS', 'mit_nm': 30, 'provider': 'ZLA', 'requestor': 'ZAB'},
    {'fix': 'GGAPP', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZLC'},
    {'fix': 'STEWW', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZLC'},
    {'fix': 'TYEGR', 'dest': 'KLAS', 'mit_nm': 20, 'provider': 'ZLA', 'requestor': 'ZDV'},
    {'fix': 'NTELL', 'dest': 'KLAS', 'mit_nm': 15, 'provider': 'ZOA', 'requestor': 'NCT'},
    {'fix': 'RUSME', 'dest': 'KSFO', 'mit_nm': 20, 'provider': 'ZOA', 'requestor': 'ZLA/ZLC'},
    {'fix': 'INYOE', 'dest': 'KSFO', 'mit_nm': 20, 'provider': 'ZOA', 'requestor': 'ZLA/ZLC'},
    {'fix': 'LEGGS', 'dest': 'KSFO', 'mit_nm': 35, 'provider': 'ZOA', 'requestor': 'ZLC'},
]
MIT_FIXES = [m['fix'] for m in MIT_REFERENCE]

# Stream patterns
STREAM_PATTERNS = {
    'SOUTH_ZOA': ['FLCHR', 'KEPEC', 'TYSSN', 'BASET', 'FUZZY'],
    'NE_ZDV': ['TYEGR', 'CLARR', 'KADDY'],
    'NNE_ZLC': ['GGAPP', 'STEWW', 'PRFUM', 'SUNST', 'JELIR'],
    'EAST_ZAB': ['ELLDA', 'HAHAA', 'GRNPA', 'RNCHH', 'PRINO'],
    'NCT_LOCAL': ['NTELL'],
    'NORCAL_SFO': ['LEGGS', 'INYOE', 'RUSME', 'DYAMD'],
}

def get_adl_conn():
    return pyodbc.connect(
        "DRIVER={ODBC Driver 18 for SQL Server};"
        "SERVER=vatsim.database.windows.net;"
        "DATABASE=VATSIM_ADL;"
        "UID=adl_api_user;"
        "PWD=***REMOVED***;"
        "Encrypt=yes;TrustServerCertificate=yes;"
    )

def detect_stream(route):
    route = (route or '').upper()
    for stream, fixes in STREAM_PATTERNS.items():
        for fix in fixes:
            if fix in route:
                return stream
    return 'OTHER'

def detect_mit_fix(route):
    route = (route or '').upper()
    for fix in MIT_FIXES:
        if fix in route:
            return fix
    return None

def main():
    print("=" * 70)
    print("ESCAPE THE DESERT FNO - MIT ANALYSIS")
    print(f"Event: January 17-18, 2026 ({EVENT_START} to {EVENT_END})")
    print(f"Generated: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')} UTC")
    print("=" * 70)

    conn = get_adl_conn()
    cursor = conn.cursor()

    # =====================================================================
    # Query flights from ADL using JOINs
    # =====================================================================
    print("\n>>> Querying ADL database...")

    arr_list = "','".join(ARRIVAL_AIRPORTS)
    dept_list = "','".join(DEPARTURE_AIRPORTS)

    # Get arrivals to LAS area
    cursor.execute(f"""
        SELECT
            c.flight_uid, c.callsign, c.cid,
            p.fp_dept_icao, p.fp_dest_icao, p.fp_route, p.fp_altitude_ft,
            p.star_name, p.afix, p.dfix, p.dp_name,
            p.fp_dept_artcc, p.fp_dest_artcc,
            c.first_seen_utc, c.last_seen_utc,
            pos.lat, pos.lon, pos.groundspeed_kts, pos.altitude_ft as current_alt,
            t.eta_utc
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        LEFT JOIN dbo.adl_flight_position pos ON c.flight_uid = pos.flight_uid
        LEFT JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        WHERE p.fp_dest_icao IN ('{arr_list}')
          AND c.first_seen_utc <= '{EVENT_END}'
          AND c.last_seen_utc >= '{EVENT_START}'
        ORDER BY c.first_seen_utc
    """)

    columns = [d[0] for d in cursor.description]
    arrivals = [dict(zip(columns, row)) for row in cursor.fetchall()]
    print(f"   Found {len(arrivals)} arrivals to LAS area")

    # Get departures from Bay Area
    cursor.execute(f"""
        SELECT
            c.flight_uid, c.callsign, c.cid,
            p.fp_dept_icao, p.fp_dest_icao, p.fp_route, p.fp_altitude_ft,
            p.star_name, p.afix, p.dfix, p.dp_name,
            p.fp_dept_artcc, p.fp_dest_artcc,
            c.first_seen_utc, c.last_seen_utc,
            pos.lat, pos.lon, pos.groundspeed_kts, pos.altitude_ft as current_alt,
            t.eta_utc
        FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
        LEFT JOIN dbo.adl_flight_position pos ON c.flight_uid = pos.flight_uid
        LEFT JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
        WHERE p.fp_dept_icao IN ('{dept_list}')
          AND c.first_seen_utc <= '{EVENT_END}'
          AND c.last_seen_utc >= '{EVENT_START}'
        ORDER BY c.first_seen_utc
    """)

    departures = [dict(zip(columns, row)) for row in cursor.fetchall()]
    print(f"   Found {len(departures)} departures from Bay Area")

    # Combine unique flights
    all_flights = arrivals + departures
    seen = set()
    unique = []
    for f in all_flights:
        cs = f.get('callsign')
        if cs and cs not in seen:
            seen.add(cs)
            unique.append(f)

    print(f"   Total unique flights: {len(unique)}")

    # =====================================================================
    # Categorize flights
    # =====================================================================
    print("\n" + "=" * 70)
    print("FLIGHT CATEGORIZATION")
    print("=" * 70)

    # By destination
    by_dest = defaultdict(list)
    for f in unique:
        dest = f.get('fp_dest_icao', '').strip()
        by_dest[dest].append(f)

    print("\nBy Destination:")
    for dest in sorted(by_dest.keys(), key=lambda d: -len(by_dest[d])):
        if dest in ARRIVAL_AIRPORTS or len(by_dest[dest]) > 5:
            print(f"  {dest}: {len(by_dest[dest])}")

    # By arrival stream
    by_stream = defaultdict(list)
    for f in unique:
        route = f.get('fp_route', '')
        stream = detect_stream(route)
        f['_stream'] = stream
        by_stream[stream].append(f)

    print("\nBy Arrival Stream:")
    for stream in sorted(by_stream.keys(), key=lambda s: -len(by_stream[s])):
        print(f"  {stream}: {len(by_stream[stream])}")

    # By MIT fix (only LAS arrivals)
    by_fix = defaultdict(list)
    las_arrivals = [f for f in unique if f.get('fp_dest_icao', '').strip() in ARRIVAL_AIRPORTS]

    for f in las_arrivals:
        route = f.get('fp_route', '')
        fix = detect_mit_fix(route)
        f['_mit_fix'] = fix
        if fix:
            by_fix[fix].append(f)

    print("\nBy MIT Control Fix (LAS arrivals only):")
    for fix in MIT_FIXES:
        if fix in by_fix:
            print(f"  {fix}: {len(by_fix[fix])}")

    # =====================================================================
    # MIT Spacing Analysis
    # =====================================================================
    print("\n" + "=" * 70)
    print("MIT SPACING ANALYSIS")
    print("=" * 70)

    mit_lookup = {m['fix']: m['mit_nm'] for m in MIT_REFERENCE}
    compliance_results = {}

    for fix in MIT_FIXES:
        flights = by_fix.get(fix, [])
        if len(flights) < 2:
            continue

        required = mit_lookup.get(fix, 20)

        # Sort by ETA or last_seen
        def get_sort_time(f):
            t = f.get('eta_utc') or f.get('last_seen_utc')
            if isinstance(t, datetime):
                return t
            return datetime.max

        sorted_flights = sorted(flights, key=get_sort_time)

        spacings = []
        violations = []

        for i in range(1, len(sorted_flights)):
            prev = sorted_flights[i-1]
            curr = sorted_flights[i]

            prev_time = prev.get('eta_utc') or prev.get('last_seen_utc')
            curr_time = curr.get('eta_utc') or curr.get('last_seen_utc')

            if isinstance(prev_time, datetime) and isinstance(curr_time, datetime):
                time_diff_sec = (curr_time - prev_time).total_seconds()
                time_diff_min = time_diff_sec / 60

                # Use actual groundspeed if available, else default 250kts
                gs = curr.get('groundspeed_kts') or 250
                estimated_spacing = (time_diff_min * gs) / 60

                pair = {
                    'prev': prev.get('callsign'),
                    'curr': curr.get('callsign'),
                    'time_min': round(time_diff_min, 1),
                    'spacing_nm': round(estimated_spacing, 1),
                    'gs': gs
                }
                spacings.append(pair)

                if estimated_spacing < required:
                    violations.append(pair)

        if spacings:
            spacing_vals = [s['spacing_nm'] for s in spacings]
            compliance_results[fix] = {
                'required': required,
                'flights': len(flights),
                'pairs': len(spacings),
                'avg': round(sum(spacing_vals) / len(spacing_vals), 1),
                'min': round(min(spacing_vals), 1),
                'max': round(max(spacing_vals), 1),
                'violations': len(violations),
                'compliance': round(100 * (1 - len(violations) / len(spacings)), 1),
                'worst': sorted(violations, key=lambda x: x['spacing_nm'])[:5]
            }

            print(f"\n{fix}:")
            print(f"  Required MIT: {required} nm")
            print(f"  Flights: {len(flights)}, Pairs analyzed: {len(spacings)}")
            print(f"  Spacing: avg={compliance_results[fix]['avg']} nm, min={compliance_results[fix]['min']} nm, max={compliance_results[fix]['max']} nm")
            print(f"  Violations: {len(violations)} ({100 - compliance_results[fix]['compliance']:.1f}% non-compliant)")

            if violations:
                print(f"  Tightest pairs:")
                for v in compliance_results[fix]['worst'][:3]:
                    print(f"    {v['prev']} -> {v['curr']}: {v['spacing_nm']} nm ({v['time_min']} min @ {v['gs']} kts)")

    # =====================================================================
    # Sample Flight List
    # =====================================================================
    print("\n" + "=" * 70)
    print("SAMPLE FLIGHTS (LAS arrivals)")
    print("=" * 70)

    print(f"\n{'Callsign':<10} {'From':<5} {'To':<5} {'Stream':<12} {'Fix':<7} {'STAR':<10} {'Route (sample)'}")
    print("-" * 90)

    for f in las_arrivals[:40]:
        cs = f.get('callsign', '?')[:9]
        dept = (f.get('fp_dept_icao') or '?').strip()[:4]
        dest = (f.get('fp_dest_icao') or '?').strip()[:4]
        stream = (f.get('_stream') or '?')[:11]
        fix = (f.get('_mit_fix') or '-')[:6]
        star = (f.get('star_name') or '-')[:9]
        route = (f.get('fp_route') or '')[:35]
        print(f"{cs:<10} {dept:<5} {dest:<5} {stream:<12} {fix:<7} {star:<10} {route}")

    # =====================================================================
    # Summary Report
    # =====================================================================
    print("\n" + "=" * 70)
    print("SUMMARY REPORT")
    print("=" * 70)

    total_violations = sum(r['violations'] for r in compliance_results.values())
    total_pairs = sum(r['pairs'] for r in compliance_results.values())
    overall = 100 * (1 - total_violations / total_pairs) if total_pairs > 0 else 100

    print(f"""
EVENT: Escape the Desert FNO
DATE: January 17-18, 2026
WINDOW: {EVENT_START} to {EVENT_END} UTC

TRAFFIC SUMMARY:
  Total arrivals to LAS/VGT/HND: {len(las_arrivals)}
  Total departures from SFO/OAK/SJC/SMF: {len(departures)}
  Total unique flights analyzed: {len(unique)}

MIT COMPLIANCE SUMMARY:
  Overall compliance: {overall:.1f}%
  Total violations: {total_violations} of {total_pairs} aircraft pairs
""")

    print("BY FIX:")
    for fix in MIT_FIXES:
        if fix in compliance_results:
            r = compliance_results[fix]
            status = "OK" if r['compliance'] >= 90 else "WARN" if r['compliance'] >= 75 else "FAIL"
            print(f"  {fix}: {r['compliance']:.1f}% compliant ({r['violations']}/{r['pairs']} violations) [{status}]")

    print(f"""
NOTES:
- Spacing calculated from ETA differences and groundspeed
- MIT restrictions were effective 2359Z-0400Z per event briefing
- Precise fix crossing times would require trajectory analysis
""")

    conn.close()

    print("\n" + "=" * 70)
    print("ANALYSIS COMPLETE")
    print("=" * 70)

if __name__ == "__main__":
    main()
