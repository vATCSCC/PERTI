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

# MIT Reference with valid time windows
# Event date: Jan 17-18, 2026
# Times are in UTC
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
    {'fix': 'RUSME', 'dest': 'KSFO', 'mit_nm': 20, 'provider': 'ZOA', 'requestor': 'ZLA/ZLC',
     'start_utc': datetime(2026, 1, 17, 23, 59), 'end_utc': datetime(2026, 1, 18, 4, 0)},
    {'fix': 'INYOE', 'dest': 'KSFO', 'mit_nm': 20, 'provider': 'ZOA', 'requestor': 'ZLA/ZLC',
     'start_utc': datetime(2026, 1, 17, 23, 59), 'end_utc': datetime(2026, 1, 18, 4, 0)},
    {'fix': 'LEGGS', 'dest': 'KSFO', 'mit_nm': 35, 'provider': 'ZOA', 'requestor': 'ZLC',
     'start_utc': datetime(2026, 1, 17, 23, 0), 'end_utc': datetime(2026, 1, 18, 3, 0)},  # 2300-0300
]
MIT_FIXES = [m['fix'] for m in MIT_REFERENCE]

def get_tmi_for_fix(fix_name):
    """Get the TMI reference for a given fix"""
    for m in MIT_REFERENCE:
        if m['fix'] == fix_name:
            return m
    return None

def is_within_tmi_window(flight_time, tmi):
    """Check if a flight time falls within the TMI valid window"""
    if not isinstance(flight_time, datetime):
        return False
    # Make naive datetime for comparison if needed
    if flight_time.tzinfo is not None:
        flight_time = flight_time.replace(tzinfo=None)
    return tmi['start_utc'] <= flight_time <= tmi['end_utc']

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
        "PWD=CAMRN@11000;"
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
    # MIT Spacing Analysis (TIME-AWARE - only during TMI valid windows)
    # =====================================================================
    print("\n" + "=" * 70)
    print("MIT SPACING ANALYSIS (Time-Filtered by TMI Validity)")
    print("=" * 70)

    compliance_results = {}

    for fix in MIT_FIXES:
        all_flights = by_fix.get(fix, [])
        if len(all_flights) < 2:
            continue

        # Get the TMI for this fix
        tmi = get_tmi_for_fix(fix)
        if not tmi:
            continue

        required = tmi['mit_nm']
        tmi_start = tmi['start_utc']
        tmi_end = tmi['end_utc']

        # Filter flights to only those within the TMI valid window
        # Use ETA or last_seen_utc as the reference time
        def get_flight_time(f):
            t = f.get('eta_utc') or f.get('last_seen_utc')
            if isinstance(t, datetime):
                if t.tzinfo is not None:
                    t = t.replace(tzinfo=None)
                return t
            return None

        valid_flights = []
        for f in all_flights:
            ft = get_flight_time(f)
            if ft and is_within_tmi_window(ft, tmi):
                valid_flights.append(f)

        excluded_count = len(all_flights) - len(valid_flights)

        if len(valid_flights) < 2:
            print(f"\n{fix}:")
            print(f"  TMI Valid: {tmi_start.strftime('%H:%MZ')} - {tmi_end.strftime('%H:%MZ')}")
            print(f"  Total flights with fix: {len(all_flights)}, Within TMI window: {len(valid_flights)}")
            print(f"  Insufficient flights within TMI window for analysis")
            continue

        # Sort by time
        sorted_flights = sorted(valid_flights, key=lambda f: get_flight_time(f) or datetime.max)

        spacings = []
        violations = []

        for i in range(1, len(sorted_flights)):
            prev = sorted_flights[i-1]
            curr = sorted_flights[i]

            prev_time = get_flight_time(prev)
            curr_time = get_flight_time(curr)

            if prev_time and curr_time:
                time_diff_sec = (curr_time - prev_time).total_seconds()
                time_diff_min = time_diff_sec / 60

                # Skip if time difference is 0 or negative (data artifact)
                if time_diff_sec <= 0:
                    continue

                # Use actual groundspeed if available and reasonable, else default 250kts
                gs = curr.get('groundspeed_kts')
                if not gs or gs < 100 or gs > 600:
                    gs = 250  # Default for jets at cruise/descent
                estimated_spacing = (time_diff_min * gs) / 60

                pair = {
                    'prev': prev.get('callsign'),
                    'curr': curr.get('callsign'),
                    'prev_time': prev_time.strftime('%H:%M:%S'),
                    'curr_time': curr_time.strftime('%H:%M:%S'),
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
                'tmi_start': tmi_start.strftime('%H:%MZ'),
                'tmi_end': tmi_end.strftime('%H:%MZ'),
                'total_flights': len(all_flights),
                'flights_in_window': len(valid_flights),
                'excluded': excluded_count,
                'pairs': len(spacings),
                'avg': round(sum(spacing_vals) / len(spacing_vals), 1),
                'min': round(min(spacing_vals), 1),
                'max': round(max(spacing_vals), 1),
                'violations': len(violations),
                'compliance': round(100 * (1 - len(violations) / len(spacings)), 1),
                'worst': sorted(violations, key=lambda x: x['spacing_nm'])[:5]
            }

            print(f"\n{fix}:")
            print(f"  TMI Valid: {tmi_start.strftime('%H:%MZ')} - {tmi_end.strftime('%H:%MZ')}")
            print(f"  Required MIT: {required} nm")
            print(f"  Flights: {len(all_flights)} total, {len(valid_flights)} in TMI window ({excluded_count} excluded)")
            print(f"  Pairs analyzed: {len(spacings)}")
            print(f"  Spacing: avg={compliance_results[fix]['avg']} nm, min={compliance_results[fix]['min']} nm, max={compliance_results[fix]['max']} nm")
            print(f"  Violations: {len(violations)} ({100 - compliance_results[fix]['compliance']:.1f}% non-compliant)")

            if violations:
                print(f"  Tightest pairs:")
                for v in compliance_results[fix]['worst'][:3]:
                    print(f"    {v['prev']} ({v['prev_time']}) -> {v['curr']} ({v['curr_time']}): {v['spacing_nm']} nm ({v['time_min']} min @ {v['gs']} kts)")

    # =====================================================================
    # GROUND STOP COMPLIANCE ANALYSIS
    # KLAS GS from NCT: 18/0230Z - 18/0315Z (issued 0244Z)
    # =====================================================================
    print("\n" + "=" * 70)
    print("GROUND STOP COMPLIANCE ANALYSIS")
    print("=" * 70)

    # GS Parameters
    GS_START = datetime(2026, 1, 18, 2, 30)  # 0230Z
    GS_END = datetime(2026, 1, 18, 3, 15)    # 0315Z
    GS_ISSUED = datetime(2026, 1, 18, 2, 44) # 0244Z
    GS_DEST = 'KLAS'
    # NCT area airports (NorCal TRACON)
    NCT_AIRPORTS = ['KSFO', 'KOAK', 'KSJC', 'KSMF', 'KCCR', 'KHWD', 'KLVK', 'KPAO', 'KSQL', 'KNUQ', 'KRHV', 'KMOD', 'KSTS']

    print(f"\nGround Stop Details:")
    print(f"  Destination: {GS_DEST}")
    print(f"  Scope: NCT (NorCal TRACON departures)")
    print(f"  Valid: {GS_START.strftime('%H:%MZ')} - {GS_END.strftime('%H:%MZ')} (Jan 18)")
    print(f"  Issued: {GS_ISSUED.strftime('%H:%MZ')}")

    # Find all flights from NCT airports to KLAS
    nct_to_las = []
    for f in unique:
        dept = (f.get('fp_dept_icao') or '').strip()
        dest = (f.get('fp_dest_icao') or '').strip()
        if dest == GS_DEST and dept in NCT_AIRPORTS:
            nct_to_las.append(f)

    print(f"\nTotal NCT -> KLAS flights in event window: {len(nct_to_las)}")

    # Check for departures during the GS window
    # A GS violation = aircraft that departed DURING the GS window
    # We'll use first_seen_utc as a proxy for departure time (or etd if available)
    gs_violations = []
    gs_compliant = []
    gs_exempt = []  # Airborne before GS issued

    for f in nct_to_las:
        # Get departure time (first_seen is often shortly after departure)
        dept_time = f.get('first_seen_utc')
        if isinstance(dept_time, datetime):
            if dept_time.tzinfo is not None:
                dept_time = dept_time.replace(tzinfo=None)

            # Check if flight was airborne BEFORE the GS was issued (exempt)
            if dept_time < GS_ISSUED:
                gs_exempt.append({
                    'callsign': f.get('callsign'),
                    'dept': f.get('fp_dept_icao', '').strip(),
                    'dept_time': dept_time.strftime('%H:%M:%S'),
                    'status': 'EXEMPT (airborne before GS issued)'
                })
            # Check if departed during GS window
            elif GS_START <= dept_time <= GS_END:
                gs_violations.append({
                    'callsign': f.get('callsign'),
                    'dept': f.get('fp_dept_icao', '').strip(),
                    'dept_time': dept_time.strftime('%H:%M:%S'),
                    'status': 'VIOLATION (departed during GS)'
                })
            # Departed after GS ended
            elif dept_time > GS_END:
                gs_compliant.append({
                    'callsign': f.get('callsign'),
                    'dept': f.get('fp_dept_icao', '').strip(),
                    'dept_time': dept_time.strftime('%H:%M:%S'),
                    'status': 'COMPLIANT (departed after GS)'
                })
            # Departed before GS started but after issue
            elif GS_ISSUED <= dept_time < GS_START:
                gs_compliant.append({
                    'callsign': f.get('callsign'),
                    'dept': f.get('fp_dept_icao', '').strip(),
                    'dept_time': dept_time.strftime('%H:%M:%S'),
                    'status': 'COMPLIANT (departed before GS window)'
                })

    print(f"\nGround Stop Analysis Results:")
    print(f"  Exempt (airborne before GS issued): {len(gs_exempt)}")
    print(f"  Compliant: {len(gs_compliant)}")
    print(f"  Violations: {len(gs_violations)}")

    if gs_exempt:
        print(f"\n  Exempt Flights (airborne before {GS_ISSUED.strftime('%H:%MZ')}):")
        for v in gs_exempt[:10]:
            print(f"    {v['callsign']} from {v['dept']} @ {v['dept_time']}")

    if gs_violations:
        gs_compliance_pct = 100 * len(gs_compliant) / (len(gs_compliant) + len(gs_violations))
        print(f"\n  VIOLATIONS (departed during GS {GS_START.strftime('%H:%MZ')}-{GS_END.strftime('%H:%MZ')}):")
        for v in gs_violations:
            print(f"    {v['callsign']} from {v['dept']} @ {v['dept_time']} - {v['status']}")
        print(f"\n  GS Compliance Rate: {gs_compliance_pct:.1f}%")
    else:
        print(f"\n  No GS violations detected - 100% compliance!")

    if gs_compliant:
        print(f"\n  Compliant Departures:")
        for v in gs_compliant[:10]:
            print(f"    {v['callsign']} from {v['dept']} @ {v['dept_time']}")

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

    print("BY FIX (during valid TMI windows only):")
    for fix in MIT_FIXES:
        if fix in compliance_results:
            r = compliance_results[fix]
            status = "OK" if r['compliance'] >= 90 else "WARN" if r['compliance'] >= 75 else "FAIL"
            print(f"  {fix} ({r['tmi_start']}-{r['tmi_end']}): {r['compliance']:.1f}% compliant ({r['violations']}/{r['pairs']} violations) [{status}]")
            print(f"       {r['flights_in_window']}/{r['total_flights']} flights within TMI window")

    print(f"""
NOTES:
- Spacing calculated from ETA/last_seen differences and groundspeed
- Analysis ONLY includes flights during each TMI's valid window
- TMI Windows: Most fixes 2359Z-0400Z, LEGGS 2300Z-0300Z
- Groundspeeds < 100 or > 600 kts replaced with 250 kts default
- Precise fix crossing times would require trajectory analysis
""")

    conn.close()

    print("\n" + "=" * 70)
    print("ANALYSIS COMPLETE")
    print("=" * 70)

if __name__ == "__main__":
    main()
