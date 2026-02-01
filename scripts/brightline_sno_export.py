#!/usr/bin/env python3
"""
Brightline SNO Event Flight Export
Exports flights to/from Florida airports (MIA, MCO, TPA) during the event window
with FIXM-compliant field naming (matching VATSWIM format).

Event: All Aboard the Brightline SNO
Time: 2026-01-31 22:00Z to 2026-02-01 06:00Z
Airports: KMIA, KMCO, KTPA (and their majors: FLL, PBI, SRQ, RSW, etc.)
"""

import pyodbc
import json
import os
import sys
from datetime import datetime, timezone
from decimal import Decimal
from collections import defaultdict

# Database connection - uses environment variables or defaults
DB_SERVER = os.environ.get("ADL_DB_SERVER", "vatsim.database.windows.net")
DB_NAME = os.environ.get("ADL_DB_NAME", "VATSIM_ADL")
DB_USER = os.environ.get("ADL_DB_USER", "adl_api_user")
DB_PASSWORD = os.environ.get("ADL_DB_PASSWORD", "")

# Event parameters
EVENT_START = "2026-01-31 22:00:00"
EVENT_END = "2026-02-01 06:00:00"

# Florida airports - main 3 + majors in the region
FLORIDA_AIRPORTS = [
    'KMIA', 'KMCO', 'KTPA',  # Primary
    'KFLL', 'KPBI', 'KSRQ',  # South/Central FL
    'KRSW', 'KJAX', 'KSFB',  # Extended region
]

def get_connection():
    """Create database connection."""
    conn_str = (
        f"DRIVER={{ODBC Driver 18 for SQL Server}};"
        f"SERVER={DB_SERVER};"
        f"DATABASE={DB_NAME};"
        f"UID={DB_USER};"
        f"PWD={DB_PASSWORD};"
        f"Encrypt=yes;"
        f"TrustServerCertificate=no;"
    )
    return pyodbc.connect(conn_str)


def decimal_default(obj):
    """JSON serializer for Decimal and datetime objects."""
    if isinstance(obj, Decimal):
        return float(obj)
    if isinstance(obj, datetime):
        return obj.strftime("%Y-%m-%dT%H:%M:%SZ")
    raise TypeError(f"Object of type {type(obj)} is not JSON serializable")


def fetch_all_data(conn):
    """Fetch all flight data in bulk queries for efficiency."""
    airports_list = ",".join([f"'{a}'" for a in FLORIDA_AIRPORTS])

    print("  Fetching flight cores...", flush=True)
    # 1. Core flight data
    cursor = conn.cursor()
    cursor.execute(f"""
        SELECT DISTINCT
            c.flight_uid, c.flight_key, c.cid, c.callsign, c.phase,
            c.first_seen_utc, c.last_seen_utc
        FROM adl_flight_core c
        INNER JOIN adl_flight_plan p ON c.flight_uid = p.flight_uid
        WHERE (p.fp_dept_icao IN ({airports_list}) OR p.fp_dest_icao IN ({airports_list}))
          AND c.first_seen_utc <= '{EVENT_END}'
          AND c.last_seen_utc >= '{EVENT_START}'
        ORDER BY c.first_seen_utc
    """)

    flights = {}
    flight_uids = []
    for row in cursor.fetchall():
        uid = row.flight_uid
        flight_uids.append(uid)
        flights[uid] = {
            'core': {
                'flight_uid': uid,
                'flight_key': row.flight_key,
                'cid': row.cid,
                'callsign': row.callsign,
                'phase': row.phase,
                'first_seen_utc': row.first_seen_utc,
                'last_seen_utc': row.last_seen_utc,
            },
            'plan': None,
            'aircraft': None,
            'position': None,
            'times': None,
            'trajectory': []
        }

    if not flight_uids:
        return flights

    uid_list = ",".join(str(uid) for uid in flight_uids)
    print(f"  Found {len(flight_uids)} flights", flush=True)

    # 2. Flight plans
    print("  Fetching flight plans...", flush=True)
    cursor.execute(f"""
        SELECT flight_uid, fp_rule, fp_dept_icao, fp_dest_icao, fp_alt_icao,
               fp_route, fp_route_expanded, dp_name, star_name, approach, runway,
               dfix, afix, fp_altitude_ft, fp_tas_kts, fp_enroute_minutes,
               fp_remarks, aircraft_type, aircraft_equip, gcd_nm,
               waypoints_json, artccs_traversed, tracons_traversed
        FROM adl_flight_plan WHERE flight_uid IN ({uid_list})
    """)
    for row in cursor.fetchall():
        flights[row.flight_uid]['plan'] = row

    # 3. Aircraft info
    print("  Fetching aircraft info...", flush=True)
    cursor.execute(f"""
        SELECT flight_uid, aircraft_icao, weight_class, engine_type,
               engine_count, wake_category, airline_icao, airline_name
        FROM adl_flight_aircraft WHERE flight_uid IN ({uid_list})
    """)
    for row in cursor.fetchall():
        flights[row.flight_uid]['aircraft'] = row

    # 4. Positions
    print("  Fetching positions...", flush=True)
    cursor.execute(f"""
        SELECT flight_uid, lat, lon, altitude_ft, groundspeed_kts,
               vertical_rate_fpm, heading_deg, track_deg,
               dist_to_dest_nm, pct_complete, position_updated_utc
        FROM adl_flight_position WHERE flight_uid IN ({uid_list})
    """)
    for row in cursor.fetchall():
        flights[row.flight_uid]['position'] = row

    # 5. Times
    print("  Fetching times...", flush=True)
    cursor.execute(f"""
        SELECT flight_uid, std_utc, etd_utc, atd_utc, sta_utc, eta_utc, ata_utc,
               ctd_utc, cta_utc, edct_utc, ete_minutes, ate_minutes, delay_minutes
        FROM adl_flight_times WHERE flight_uid IN ({uid_list})
    """)
    for row in cursor.fetchall():
        flights[row.flight_uid]['times'] = row

    # 6. Trajectories (bulk fetch, then group)
    print("  Fetching trajectories (this may take a moment)...", flush=True)
    cursor.execute(f"""
        SELECT flight_uid, recorded_utc, lat, lon, altitude_ft,
               groundspeed_kts, vertical_rate_fpm, heading_deg, track_deg
        FROM adl_flight_trajectory
        WHERE flight_uid IN ({uid_list})
          AND recorded_utc >= '{EVENT_START}'
          AND recorded_utc <= '{EVENT_END}'
        ORDER BY flight_uid, recorded_utc
    """)

    traj_count = 0
    for row in cursor.fetchall():
        if row.flight_uid in flights:
            flights[row.flight_uid]['trajectory'].append(row)
            traj_count += 1

    print(f"  Loaded {traj_count} trajectory points", flush=True)

    return flights


def format_flight_plan(row):
    """Format flight plan row as FIXM."""
    if not row:
        return None

    return {
        "flightRulesCategory": row.fp_rule,
        "departureAerodrome": {"icaoId": row.fp_dept_icao},
        "arrivalAerodrome": {"icaoId": row.fp_dest_icao},
        "alternateAerodrome": {"icaoId": row.fp_alt_icao} if row.fp_alt_icao else None,
        "filedRoute": {
            "routeText": row.fp_route,
            "expandedRoute": row.fp_route_expanded,
        },
        "standardInstrumentDeparture": row.dp_name,
        "standardInstrumentArrival": row.star_name,
        "approachProcedure": row.approach,
        "arrivalRunway": row.runway,
        "departurePoint": row.dfix,
        "arrivalPoint": row.afix,
        "cruisingAltitude": {"altitude": row.fp_altitude_ft, "uom": "FT"} if row.fp_altitude_ft else None,
        "cruisingSpeed": {"speed": row.fp_tas_kts, "uom": "KT"} if row.fp_tas_kts else None,
        "estimatedElapsedTime": row.fp_enroute_minutes,
        "remarks": row.fp_remarks,
        "aircraftType": row.aircraft_type,
        "equipmentQualifier": row.aircraft_equip,
        "routeExtent": {
            "greatCircleDistanceNm": float(row.gcd_nm) if row.gcd_nm else None,
            "routeWaypoints": json.loads(row.waypoints_json) if row.waypoints_json else None,
            "artccsTraversed": row.artccs_traversed.split() if row.artccs_traversed else [],
            "traconsTraversed": row.tracons_traversed.split() if row.tracons_traversed else [],
        }
    }


def format_aircraft(row):
    """Format aircraft row as FIXM."""
    if not row:
        return None

    return {
        "aircraftType": row.aircraft_icao,
        "wakeTurbulenceCategory": row.weight_class,
        "engineType": row.engine_type,
        "engineCount": row.engine_count,
        "wakeCategory": row.wake_category,
        "operator": {
            "icaoDesignator": row.airline_icao,
            "operatorName": row.airline_name
        } if row.airline_icao else None
    }


def format_position(row):
    """Format position row as FIXM."""
    if not row:
        return None

    return {
        "position": {
            "latitude": float(row.lat) if row.lat else None,
            "longitude": float(row.lon) if row.lon else None,
        } if row.lat and row.lon else None,
        "altitude": {"altitude": row.altitude_ft, "uom": "FT"} if row.altitude_ft else None,
        "groundSpeed": {"speed": row.groundspeed_kts, "uom": "KT"} if row.groundspeed_kts else None,
        "verticalRate": {"rate": row.vertical_rate_fpm, "uom": "FT_MIN"} if row.vertical_rate_fpm else None,
        "heading": row.heading_deg,
        "track": row.track_deg,
        "distanceToDestinationNm": float(row.dist_to_dest_nm) if row.dist_to_dest_nm else None,
        "percentComplete": float(row.pct_complete) if row.pct_complete else None,
        "positionTime": row.position_updated_utc
    }


def format_times(row):
    """Format times row as FIXM."""
    if not row:
        return None

    return {
        "departure": {
            "scheduledOffBlockTime": row.std_utc,
            "estimatedOffBlockTime": row.etd_utc,
            "actualOffBlockTime": row.atd_utc,
            "controlledOffBlockTime": row.ctd_utc,
            "expectedDepartureClearanceTime": row.edct_utc,
        },
        "arrival": {
            "scheduledInBlockTime": row.sta_utc,
            "estimatedInBlockTime": row.eta_utc,
            "actualInBlockTime": row.ata_utc,
            "controlledInBlockTime": row.cta_utc,
        },
        "estimatedElapsedTime": row.ete_minutes,
        "actualElapsedTime": row.ate_minutes,
        "totalDelay": row.delay_minutes,
    }


def format_trajectory(rows):
    """Format trajectory rows as FIXM."""
    points = []
    for row in rows:
        points.append({
            "pointTime": row.recorded_utc,
            "position": {
                "latitude": float(row.lat),
                "longitude": float(row.lon),
            },
            "altitude": {"altitude": row.altitude_ft, "uom": "FT"} if row.altitude_ft else None,
            "groundSpeed": {"speed": row.groundspeed_kts, "uom": "KT"} if row.groundspeed_kts else None,
            "verticalRate": {"rate": row.vertical_rate_fpm, "uom": "FT_MIN"} if row.vertical_rate_fpm else None,
            "heading": row.heading_deg,
            "track": row.track_deg,
        })

    return {
        "trajectoryPointCount": len(points),
        "trajectoryPoints": points
    }


def build_fixm_flight(flight_data):
    """Build complete FIXM-formatted flight object."""
    core = flight_data['core']

    return {
        "flightIdentification": {
            "gufi": core['flight_key'],
            "aircraftIdentification": core['callsign'],
            "vatsimCid": core['cid'],
        },
        "flightStatus": {
            "airborneState": core['phase'],
            "firstContact": core['first_seen_utc'],
            "lastContact": core['last_seen_utc'],
        },
        "flightPlanFiled": format_flight_plan(flight_data['plan']),
        "aircraft": format_aircraft(flight_data['aircraft']),
        "enRoute": {
            "currentPosition": format_position(flight_data['position']),
        },
        "flightTimes": format_times(flight_data['times']),
        "trajectory": format_trajectory(flight_data['trajectory']),
    }


def main():
    print("=" * 60)
    print("Brightline SNO Event Flight Export")
    print("=" * 60)
    print(f"Event window: {EVENT_START} to {EVENT_END}")
    print(f"Target airports: {', '.join(FLORIDA_AIRPORTS)}")
    print()

    if not DB_PASSWORD:
        print("Error: ADL_DB_PASSWORD environment variable not set.")
        print("Set it with: set ADL_DB_PASSWORD=your_password")
        return

    conn = get_connection()
    print("Connected to database.")
    print()

    # Fetch all data in bulk
    print("Fetching data...")
    flights_data = fetch_all_data(conn)
    conn.close()

    print()
    print(f"Processing {len(flights_data)} flights...")

    # Build FIXM output
    fixm_output = {
        "messageMetadata": {
            "messageType": "FlightDataCollection",
            "source": "VATSWIM",
            "timestamp": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "event": {
                "name": "All Aboard the Brightline SNO",
                "startTime": EVENT_START.replace(" ", "T") + "Z",
                "endTime": EVENT_END.replace(" ", "T") + "Z",
            },
            "airports": FLORIDA_AIRPORTS,
        },
        "flightCollection": {
            "flightCount": len(flights_data),
            "flights": []
        }
    }

    for i, (uid, flight_data) in enumerate(flights_data.items(), 1):
        if i % 100 == 0:
            print(f"  Formatted {i}/{len(flights_data)} flights...", flush=True)
        fixm_flight = build_fixm_flight(flight_data)
        fixm_output["flightCollection"]["flights"].append(fixm_flight)

    # Write output
    output_file = "brightline_sno_flights.json"
    print(f"\nWriting {output_file}...")
    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump(fixm_output, f, indent=2, default=decimal_default)

    print()
    print(f"Export complete: {output_file}")
    print(f"Total flights: {len(flights_data)}")

    # Summary stats
    dept_counts = {}
    dest_counts = {}
    for flight in fixm_output["flightCollection"]["flights"]:
        if flight.get("flightPlanFiled"):
            dept = flight["flightPlanFiled"].get("departureAerodrome", {}).get("icaoId")
            dest = flight["flightPlanFiled"].get("arrivalAerodrome", {}).get("icaoId")
            if dept in FLORIDA_AIRPORTS:
                dept_counts[dept] = dept_counts.get(dept, 0) + 1
            if dest in FLORIDA_AIRPORTS:
                dest_counts[dest] = dest_counts.get(dest, 0) + 1

    print()
    print("Departures from Florida airports:")
    for apt, count in sorted(dept_counts.items(), key=lambda x: -x[1]):
        print(f"  {apt}: {count}")

    print()
    print("Arrivals to Florida airports:")
    for apt, count in sorted(dest_counts.items(), key=lambda x: -x[1]):
        print(f"  {apt}: {count}")


if __name__ == "__main__":
    main()
