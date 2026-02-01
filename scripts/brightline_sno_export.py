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
from datetime import datetime
from decimal import Decimal

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
        f"DRIVER={{ODBC Driver 17 for SQL Server}};"
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


def fetch_flights(conn):
    """Fetch all flights to/from Florida airports during the event window."""
    airports_list = ",".join([f"'{a}'" for a in FLORIDA_AIRPORTS])

    query = f"""
    SELECT DISTINCT
        c.flight_uid,
        c.flight_key,
        c.cid,
        c.callsign,
        c.phase,
        c.first_seen_utc,
        c.last_seen_utc
    FROM adl_flight_core c
    INNER JOIN adl_flight_plan p ON c.flight_uid = p.flight_uid
    WHERE (p.fp_dept_icao IN ({airports_list}) OR p.fp_dest_icao IN ({airports_list}))
      AND c.first_seen_utc <= '{EVENT_END}'
      AND c.last_seen_utc >= '{EVENT_START}'
    ORDER BY c.first_seen_utc
    """

    cursor = conn.cursor()
    cursor.execute(query)

    flights = []
    for row in cursor.fetchall():
        flights.append({
            'flight_uid': row.flight_uid,
            'flight_key': row.flight_key,
            'cid': row.cid,
            'callsign': row.callsign,
            'phase': row.phase,
            'first_seen_utc': row.first_seen_utc,
            'last_seen_utc': row.last_seen_utc,
        })

    return flights


def fetch_flight_plan(conn, flight_uid):
    """Fetch flight plan data with FIXM field mapping."""
    query = """
    SELECT
        fp_rule,
        fp_dept_icao,
        fp_dest_icao,
        fp_alt_icao,
        fp_route,
        fp_route_expanded,
        dp_name,
        star_name,
        approach,
        runway,
        dfix,
        afix,
        fp_altitude_ft,
        fp_tas_kts,
        fp_enroute_minutes,
        fp_remarks,
        aircraft_type,
        aircraft_equip,
        gcd_nm,
        waypoints_json,
        artccs_traversed,
        tracons_traversed
    FROM adl_flight_plan
    WHERE flight_uid = ?
    """

    cursor = conn.cursor()
    cursor.execute(query, flight_uid)
    row = cursor.fetchone()

    if not row:
        return None

    # Map to FIXM field names
    return {
        # FIXM: FlightRules
        "flightRulesCategory": row.fp_rule,

        # FIXM: Departure/Arrival
        "departureAerodrome": {
            "icaoId": row.fp_dept_icao
        },
        "arrivalAerodrome": {
            "icaoId": row.fp_dest_icao
        },
        "alternateAerodrome": {
            "icaoId": row.fp_alt_icao
        } if row.fp_alt_icao else None,

        # FIXM: Route
        "filedRoute": {
            "routeText": row.fp_route,
            "expandedRoute": row.fp_route_expanded,
        },

        # FIXM: Procedures
        "standardInstrumentDeparture": row.dp_name,
        "standardInstrumentArrival": row.star_name,
        "approachProcedure": row.approach,
        "arrivalRunway": row.runway,

        # FIXM: Route points
        "departurePoint": row.dfix,
        "arrivalPoint": row.afix,

        # FIXM: Performance
        "cruisingAltitude": {
            "altitude": row.fp_altitude_ft,
            "uom": "FT"
        } if row.fp_altitude_ft else None,
        "cruisingSpeed": {
            "speed": row.fp_tas_kts,
            "uom": "KT"
        } if row.fp_tas_kts else None,
        "estimatedElapsedTime": row.fp_enroute_minutes,

        # FIXM: Remarks
        "remarks": row.fp_remarks,

        # FIXM: Aircraft
        "aircraftType": row.aircraft_type,
        "equipmentQualifier": row.aircraft_equip,

        # FIXM Extension: Route analysis
        "routeExtent": {
            "greatCircleDistanceNm": float(row.gcd_nm) if row.gcd_nm else None,
            "routeWaypoints": json.loads(row.waypoints_json) if row.waypoints_json else None,
            "artccsTraversed": row.artccs_traversed.split() if row.artccs_traversed else [],
            "traconsTraversed": row.tracons_traversed.split() if row.tracons_traversed else [],
        }
    }


def fetch_aircraft_info(conn, flight_uid):
    """Fetch aircraft info with FIXM field mapping."""
    query = """
    SELECT
        aircraft_icao,
        weight_class,
        engine_type,
        engine_count,
        wake_category,
        airline_icao,
        airline_name
    FROM adl_flight_aircraft
    WHERE flight_uid = ?
    """

    cursor = conn.cursor()
    cursor.execute(query, flight_uid)
    row = cursor.fetchone()

    if not row:
        return None

    return {
        # FIXM: Aircraft description
        "aircraftType": row.aircraft_icao,
        "wakeTurbulenceCategory": row.weight_class,
        "engineType": row.engine_type,
        "engineCount": row.engine_count,
        "wakeCategory": row.wake_category,

        # FIXM: Operator
        "operator": {
            "icaoDesignator": row.airline_icao,
            "operatorName": row.airline_name
        } if row.airline_icao else None
    }


def fetch_current_position(conn, flight_uid):
    """Fetch current position data with FIXM field mapping."""
    query = """
    SELECT
        lat,
        lon,
        altitude_ft,
        groundspeed_kts,
        vertical_rate_fpm,
        heading_deg,
        track_deg,
        dist_to_dest_nm,
        pct_complete,
        position_updated_utc
    FROM adl_flight_position
    WHERE flight_uid = ?
    """

    cursor = conn.cursor()
    cursor.execute(query, flight_uid)
    row = cursor.fetchone()

    if not row:
        return None

    return {
        # FIXM: Position
        "position": {
            "latitude": float(row.lat) if row.lat else None,
            "longitude": float(row.lon) if row.lon else None,
        } if row.lat and row.lon else None,

        # FIXM: Altitude
        "altitude": {
            "altitude": row.altitude_ft,
            "uom": "FT"
        } if row.altitude_ft else None,

        # FIXM: Speed
        "groundSpeed": {
            "speed": row.groundspeed_kts,
            "uom": "KT"
        } if row.groundspeed_kts else None,

        # FIXM: Vertical rate
        "verticalRate": {
            "rate": row.vertical_rate_fpm,
            "uom": "FT_MIN"
        } if row.vertical_rate_fpm else None,

        # FIXM: Heading/Track
        "heading": row.heading_deg,
        "track": row.track_deg,

        # Extension
        "distanceToDestinationNm": float(row.dist_to_dest_nm) if row.dist_to_dest_nm else None,
        "percentComplete": float(row.pct_complete) if row.pct_complete else None,

        # Timestamp
        "positionTime": row.position_updated_utc
    }


def fetch_flight_times(conn, flight_uid):
    """Fetch flight times with FIXM field mapping."""
    query = """
    SELECT
        std_utc,
        etd_utc,
        atd_utc,
        sta_utc,
        eta_utc,
        ata_utc,
        ctd_utc,
        cta_utc,
        edct_utc,
        ete_minutes,
        ate_minutes,
        delay_minutes
    FROM adl_flight_times
    WHERE flight_uid = ?
    """

    cursor = conn.cursor()
    cursor.execute(query, flight_uid)
    row = cursor.fetchone()

    if not row:
        return None

    return {
        # FIXM: Departure times
        "departure": {
            "scheduledOffBlockTime": row.std_utc,
            "estimatedOffBlockTime": row.etd_utc,
            "actualOffBlockTime": row.atd_utc,
            "controlledOffBlockTime": row.ctd_utc,
            "expectedDepartureClearanceTime": row.edct_utc,
        },

        # FIXM: Arrival times
        "arrival": {
            "scheduledInBlockTime": row.sta_utc,
            "estimatedInBlockTime": row.eta_utc,
            "actualInBlockTime": row.ata_utc,
            "controlledInBlockTime": row.cta_utc,
        },

        # FIXM: Elapsed times
        "estimatedElapsedTime": row.ete_minutes,
        "actualElapsedTime": row.ate_minutes,
        "totalDelay": row.delay_minutes,
    }


def fetch_trajectory(conn, flight_uid):
    """Fetch trajectory (position history) with FIXM field mapping."""
    query = """
    SELECT
        timestamp_utc,
        lat,
        lon,
        altitude_ft,
        groundspeed_kts,
        vertical_rate_fpm,
        heading_deg,
        track_deg
    FROM adl_flight_trajectory
    WHERE flight_uid = ?
      AND timestamp_utc >= ?
      AND timestamp_utc <= ?
    ORDER BY timestamp_utc
    """

    cursor = conn.cursor()
    cursor.execute(query, flight_uid, EVENT_START, EVENT_END)

    points = []
    for row in cursor.fetchall():
        points.append({
            # FIXM: Point4D
            "pointTime": row.timestamp_utc,
            "position": {
                "latitude": float(row.lat),
                "longitude": float(row.lon),
            },
            "altitude": {
                "altitude": row.altitude_ft,
                "uom": "FT"
            } if row.altitude_ft else None,
            "groundSpeed": {
                "speed": row.groundspeed_kts,
                "uom": "KT"
            } if row.groundspeed_kts else None,
            "verticalRate": {
                "rate": row.vertical_rate_fpm,
                "uom": "FT_MIN"
            } if row.vertical_rate_fpm else None,
            "heading": row.heading_deg,
            "track": row.track_deg,
        })

    return {
        "trajectoryPointCount": len(points),
        "trajectoryPoints": points
    }


def build_fixm_flight(conn, flight_info):
    """Build complete FIXM-formatted flight object."""
    flight_uid = flight_info['flight_uid']

    return {
        # FIXM: Flight identification
        "flightIdentification": {
            "gufi": flight_info['flight_key'],  # Global Unique Flight Identifier
            "aircraftIdentification": flight_info['callsign'],
            "vatsimCid": flight_info['cid'],
        },

        # FIXM: Flight status
        "flightStatus": {
            "airborneState": flight_info['phase'],
            "firstContact": flight_info['first_seen_utc'],
            "lastContact": flight_info['last_seen_utc'],
        },

        # FIXM: Flight plan filed
        "flightPlanFiled": fetch_flight_plan(conn, flight_uid),

        # FIXM: Aircraft description
        "aircraft": fetch_aircraft_info(conn, flight_uid),

        # FIXM: Current position
        "enRoute": {
            "currentPosition": fetch_current_position(conn, flight_uid),
        },

        # FIXM: Times
        "flightTimes": fetch_flight_times(conn, flight_uid),

        # FIXM: Trajectory (4D path)
        "trajectory": fetch_trajectory(conn, flight_uid),
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

    # Fetch flights
    print("Fetching flights...")
    flights = fetch_flights(conn)
    print(f"Found {len(flights)} flights.")

    if not flights:
        print("No flights found for the specified criteria.")
        conn.close()
        return

    # Build FIXM output
    print("Building FIXM output...")
    fixm_output = {
        "messageMetadata": {
            "messageType": "FlightDataCollection",
            "source": "VATSWIM",
            "timestamp": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ"),
            "event": {
                "name": "All Aboard the Brightline SNO",
                "startTime": EVENT_START.replace(" ", "T") + "Z",
                "endTime": EVENT_END.replace(" ", "T") + "Z",
            },
            "airports": FLORIDA_AIRPORTS,
        },
        "flightCollection": {
            "flightCount": len(flights),
            "flights": []
        }
    }

    for i, flight_info in enumerate(flights, 1):
        if i % 10 == 0:
            print(f"  Processing flight {i}/{len(flights)}...")
        fixm_flight = build_fixm_flight(conn, flight_info)
        fixm_output["flightCollection"]["flights"].append(fixm_flight)

    conn.close()

    # Write output
    output_file = "brightline_sno_flights.json"
    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump(fixm_output, f, indent=2, default=decimal_default)

    print()
    print(f"Export complete: {output_file}")
    print(f"Total flights: {len(flights)}")

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
