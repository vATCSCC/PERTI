#!/usr/bin/env python3
"""
VATUSA Event Statistics - Historical Excel Import
==================================================

Imports historical event data from Excel files into SQL format
for the vatusa_event_* tables.

Data Sources:
- VATUSA Events Data.xlsx: Hourly traffic data per airport per event
- VATUSA Event Statistics.xlsb: Event metadata, TMR reviews

Usage:
    python import_historical_events.py -o 074_vatusa_event_import.sql

    # Specify custom file paths:
    python import_historical_events.py --events-xlsx path/to/events.xlsx \
                                       --stats-xlsb path/to/stats.xlsb \
                                       -o output.sql

Requirements:
    pip install openpyxl pyxlsb

Author: Claude Code
"""

import argparse
import re
import sys
from datetime import datetime, timezone, timedelta
from pathlib import Path
from typing import Dict, List, Optional, Tuple, Any
from collections import defaultdict

try:
    import openpyxl
except ImportError:
    print("ERROR: openpyxl required. Install with: pip install openpyxl")
    sys.exit(1)

try:
    from pyxlsb import open_workbook as open_xlsb
except ImportError:
    print("ERROR: pyxlsb required. Install with: pip install pyxlsb")
    sys.exit(1)


# Default file paths
DEFAULT_EVENTS_XLSX = r"C:\Users\jerem.DESKTOP-T926IG8\OneDrive - Virtual Air Traffic Control System Command Center\Documents - Virtual Air Traffic Control System Command Center\DCC\VATUSA Events Data.xlsx"
DEFAULT_STATS_XLSB = r"C:\Users\jerem.DESKTOP-T926IG8\OneDrive - Virtual Air Traffic Control System Command Center\Documents - Virtual Air Traffic Control System Command Center\DCC\VATUSA Event Statistics.xlsb"


def excel_date_to_datetime(excel_date: float, excel_time: float = 0) -> Optional[datetime]:
    """Convert Excel serial date/time to Python datetime."""
    if excel_date is None:
        return None
    try:
        # Excel epoch is 1899-12-30 (accounting for the 1900 leap year bug)
        base = datetime(1899, 12, 30)
        days = int(excel_date)
        # Time is fraction of day
        if excel_time:
            total_seconds = excel_time * 24 * 60 * 60
        else:
            total_seconds = (excel_date - days) * 24 * 60 * 60
        return base + timedelta(days=days, seconds=total_seconds)
    except (ValueError, TypeError):
        return None


def parse_event_idx(event_idx: str) -> Dict[str, Any]:
    """
    Parse event index like: 202003062359T202003070400/FNO/FNO1

    Returns dict with start_utc, end_utc, event_type, event_code
    """
    result = {
        'start_utc': None,
        'end_utc': None,
        'event_type': None,
        'event_code': None
    }

    if not event_idx or '/' not in event_idx:
        return result

    parts = event_idx.split('/')
    if len(parts) < 3:
        return result

    date_part = parts[0]
    result['event_type'] = parts[1]
    result['event_code'] = parts[2]

    # Parse datetime: 202003062359T202003070400
    if 'T' in date_part:
        start_str, end_str = date_part.split('T')
        try:
            result['start_utc'] = datetime.strptime(start_str, '%Y%m%d%H%M')
            result['end_utc'] = datetime.strptime(end_str, '%Y%m%d%H%M')
        except ValueError:
            pass

    return result


def sql_escape(value: Any) -> str:
    """Escape value for SQL."""
    if value is None:
        return 'NULL'
    if isinstance(value, bool):
        return '1' if value else '0'
    if isinstance(value, (int, float)):
        if isinstance(value, float) and (value != value):  # NaN check
            return 'NULL'
        return str(value)
    if isinstance(value, datetime):
        return f"'{value.strftime('%Y-%m-%d %H:%M:%S')}'"
    # String
    s = str(value).replace("'", "''")
    return f"N'{s}'"


def read_event_list_xlsb(filepath: str) -> Dict[str, Dict]:
    """
    Read Event List sheet from .xlsb file.
    Returns dict keyed by event_idx.
    """
    events = {}
    print(f"Reading Event List from: {filepath}")

    with open_xlsb(filepath) as wb:
        with wb.get_sheet('Event List') as sheet:
            rows = list(sheet.rows())

            # Find header row (row 2 based on inspection output)
            headers = None
            for i, row in enumerate(rows[:5]):
                values = [item.v for item in row]
                if 'IDX' in values or 'Name' in values:
                    headers = values
                    header_idx = i
                    break

            if not headers:
                print("  WARNING: Could not find headers in Event List")
                return events

            # Build column index
            col_map = {h: i for i, h in enumerate(headers) if h}

            # Read data rows
            for row in rows[header_idx + 1:]:
                values = [item.v for item in row]
                if not values or not values[col_map.get('IDX', 0)]:
                    continue

                event_idx = str(values[col_map.get('IDX', 0)])
                parsed = parse_event_idx(event_idx)

                event = {
                    'event_idx': event_idx,
                    'event_name': values[col_map.get('Name', 1)] if col_map.get('Name') else None,
                    'event_type': parsed['event_type'],
                    'event_code': parsed['event_code'],
                    'start_utc': parsed['start_utc'],
                    'end_utc': parsed['end_utc'],
                    'day_of_week': values[col_map.get('Day of Week (UTC)', 7)] if col_map.get('Day of Week (UTC)') else None,
                }

                # Try to get dates from Excel serial if parsing failed
                if not event['start_utc'] and col_map.get('Start Date (UTC)'):
                    start_date = values[col_map['Start Date (UTC)']]
                    start_time = values[col_map.get('Start Time (UTC)', col_map['Start Date (UTC)'])]
                    if start_date:
                        event['start_utc'] = excel_date_to_datetime(start_date, start_time if isinstance(start_time, (int, float)) else 0)

                if not event['end_utc'] and col_map.get('End Date (UTC)'):
                    end_date = values[col_map['End Date (UTC)']]
                    end_time = values[col_map.get('End Time (UTC)', col_map['End Date (UTC)'])]
                    if end_date:
                        event['end_utc'] = excel_date_to_datetime(end_date, end_time if isinstance(end_time, (int, float)) else 0)

                events[event_idx] = event

    print(f"  Found {len(events)} events in Event List")
    return events


def read_tmr_xlsb(filepath: str) -> Dict[str, Dict]:
    """
    Read TMR sheet from .xlsb file for review scores.
    Returns dict keyed by event_idx.
    """
    tmr_data = {}
    print(f"Reading TMR data from: {filepath}")

    with open_xlsb(filepath) as wb:
        if 'TMR' not in wb.sheets:
            print("  TMR sheet not found")
            return tmr_data

        with wb.get_sheet('TMR') as sheet:
            rows = list(sheet.rows())

            # Headers should be in row 1
            if len(rows) < 2:
                return tmr_data

            headers = [item.v for item in rows[0]]
            col_map = {h: i for i, h in enumerate(headers) if h}

            for row in rows[1:]:
                values = [item.v for item in row]
                if not values:
                    continue

                # Index column
                idx_col = col_map.get('Index', 0)
                event_idx = values[idx_col] if idx_col < len(values) else None

                if not event_idx or event_idx.startswith('`='):
                    continue

                tmr = {
                    'tmr_link': values[col_map.get('TMR Link', 2)] if col_map.get('TMR Link') and col_map['TMR Link'] < len(values) else None,
                    'timelapse_link': values[col_map.get('Timelapse Link', 3)] if col_map.get('Timelapse Link') and col_map['Timelapse Link'] < len(values) else None,
                    'simaware_link': values[col_map.get('SimAware Link', 4)] if col_map.get('SimAware Link') and col_map['SimAware Link'] < len(values) else None,
                    'perti_plan_link': values[col_map.get('PERTI Plan Link', 5)] if col_map.get('PERTI Plan Link') and col_map['PERTI Plan Link'] < len(values) else None,
                    'staffing_score': values[col_map.get('Staffing Score', 12)] if col_map.get('Staffing Score') and col_map['Staffing Score'] < len(values) else None,
                    'tactical_score': values[col_map.get('Tactical Score', 14)] if col_map.get('Tactical Score') and col_map['Tactical Score'] < len(values) else None,
                    'overall_score': values[col_map.get('Overall Score', 26)] if col_map.get('Overall Score') and col_map['Overall Score'] < len(values) else None,
                }

                tmr_data[str(event_idx)] = tmr

    print(f"  Found {len(tmr_data)} TMR records")
    return tmr_data


def read_event_data_xlsx(filepath: str) -> Tuple[Dict[str, List[Dict]], Dict[str, Dict]]:
    """
    Read Event Data sheet from .xlsx file.
    Returns:
        - hourly_data: dict of event_idx -> list of hourly records
        - airport_totals: dict of (event_idx, airport) -> aggregated stats
    """
    hourly_data = defaultdict(list)
    airport_totals = defaultdict(lambda: {
        'total_arrivals': 0,
        'total_departures': 0,
        'total_operations': 0,
        'hours_above_50pct': 0,
        'hours_above_75pct': 0,
        'hours_above_90pct': 0,
        'peak_vatsim_aar': 0,
        'peak_hour_utc': None,
    })

    print(f"Reading Event Data from: {filepath}")

    wb = openpyxl.load_workbook(filepath, read_only=True, data_only=True)
    ws = wb['Event Data']

    rows = list(ws.iter_rows(values_only=True))
    if len(rows) < 2:
        print("  No data rows found")
        return hourly_data, airport_totals

    headers = rows[0]
    col_map = {h: i for i, h in enumerate(headers) if h}

    # Key columns
    idx_col = col_map.get('Index', 0)
    airport_col = col_map.get('Airport', 1)
    hour_col = col_map.get('Hour (UTC)', 7)

    for row in rows[1:]:
        if not row or not row[idx_col]:
            continue

        event_idx = str(row[idx_col])
        airport = str(row[airport_col]) if row[airport_col] else None
        hour_utc = str(row[hour_col]) if row[hour_col] else None

        if not airport or not hour_utc:
            continue

        # Extract hourly data
        hourly = {
            'event_idx': event_idx,
            'airport_icao': airport,
            'hour_utc': hour_utc,
            'hour_offset': row[col_map.get('Hour Offset', 71)] if col_map.get('Hour Offset') else None,
            'arrivals': row[col_map.get('Arrivals', 13)] if col_map.get('Arrivals') else None,
            'departures': row[col_map.get('Departures', 14)] if col_map.get('Departures') else None,
            'throughput': row[col_map.get('Throughput', 15)] if col_map.get('Throughput') else None,
            'vatsim_aar': row[col_map.get('VATSIM AAR', 16)] if col_map.get('VATSIM AAR') else None,
            'vatsim_adr': row[col_map.get('VATSIM ADR', 17)] if col_map.get('VATSIM ADR') else None,
            'vatsim_total': row[col_map.get('VATSIM Total', 18)] if col_map.get('VATSIM Total') else None,
            'rw_aar': row[col_map.get('RW AAR', 19)] if col_map.get('RW AAR') else None,
            'rw_adr': row[col_map.get('RW ADR', 20)] if col_map.get('RW ADR') else None,
            'rw_total': row[col_map.get('RW Total', 21)] if col_map.get('RW Total') else None,
            'pct_vatsim_aar': row[col_map.get('% VATSIM AAR', 24)] if col_map.get('% VATSIM AAR') else None,
            'pct_vatsim_adr': row[col_map.get('% VATSIM ADR', 25)] if col_map.get('% VATSIM ADR') else None,
            'pct_vatsim_total': row[col_map.get('% VATSIM Total', 26)] if col_map.get('% VATSIM Total') else None,
            'pct_rw_aar': row[col_map.get('% RW AAR', 27)] if col_map.get('% RW AAR') else None,
            'pct_rw_adr': row[col_map.get('% RW ADR', 28)] if col_map.get('% RW ADR') else None,
            'pct_rw_total': row[col_map.get('% RW Total', 29)] if col_map.get('% RW Total') else None,
            'rolling_arr': row[col_map.get('Rolling Airport Arrivals', 42)] if col_map.get('Rolling Airport Arrivals') else None,
            'rolling_dep': row[col_map.get('Rolling Airport Departures', 43)] if col_map.get('Rolling Airport Departures') else None,
            'rolling_throughput': row[col_map.get('Rolling Airport Throughput', 44)] if col_map.get('Rolling Airport Throughput') else None,
            'event_airport_arr': row[col_map.get('Event Airport Arrivals', 48)] if col_map.get('Event Airport Arrivals') else None,
            'event_airport_dep': row[col_map.get('Event Airport Departures', 49)] if col_map.get('Event Airport Departures') else None,
            'event_airport_total': row[col_map.get('Event Airport Total', 50)] if col_map.get('Event Airport Total') else None,
            'hourly_avg': row[col_map.get('Hourly Average', 54)] if col_map.get('Hourly Average') else None,
            'hourly_avg_airport': row[col_map.get('Hourly Average (per Airport)', 56)] if col_map.get('Hourly Average (per Airport)') else None,
            'hourly_avg_event_type': row[col_map.get('Hourly Average (per Event Type)', 58)] if col_map.get('Hourly Average (per Event Type)') else None,
        }

        hourly_data[event_idx].append(hourly)

        # Update airport totals
        key = (event_idx, airport)
        arr = hourly['arrivals'] or 0
        dep = hourly['departures'] or 0
        airport_totals[key]['total_arrivals'] += arr if isinstance(arr, (int, float)) else 0
        airport_totals[key]['total_departures'] += dep if isinstance(dep, (int, float)) else 0
        airport_totals[key]['total_operations'] += (arr + dep) if isinstance(arr, (int, float)) and isinstance(dep, (int, float)) else 0

        # Track peak hour
        vatsim_aar = hourly['vatsim_aar']
        if vatsim_aar and isinstance(vatsim_aar, (int, float)) and vatsim_aar > airport_totals[key]['peak_vatsim_aar']:
            airport_totals[key]['peak_vatsim_aar'] = vatsim_aar
            airport_totals[key]['peak_hour_utc'] = hour_utc

        # Count hours above thresholds
        pct = hourly['pct_vatsim_total']
        if pct and isinstance(pct, (int, float)):
            pct_val = pct * 100 if pct <= 1 else pct  # Handle decimal vs percentage
            if pct_val >= 50:
                airport_totals[key]['hours_above_50pct'] += 1
            if pct_val >= 75:
                airport_totals[key]['hours_above_75pct'] += 1
            if pct_val >= 90:
                airport_totals[key]['hours_above_90pct'] += 1

    wb.close()

    print(f"  Found {len(hourly_data)} events with {sum(len(v) for v in hourly_data.values())} hourly records")
    print(f"  Found {len(airport_totals)} airport-event combinations")

    return hourly_data, airport_totals


def generate_sql(events: Dict[str, Dict],
                 tmr_data: Dict[str, Dict],
                 hourly_data: Dict[str, List[Dict]],
                 airport_totals: Dict[Tuple[str, str], Dict],
                 output_path: str):
    """Generate SQL import files - split into manageable chunks."""

    timestamp = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    base_path = Path(output_path).stem
    output_dir = Path(output_path).parent

    # File 1: Events only
    events_file = output_dir / f"{base_path}_1_events.sql"
    # File 2: Airport summaries
    airports_file = output_dir / f"{base_path}_2_airports.sql"
    # File 3+: Hourly data (split into chunks)
    hourly_prefix = output_dir / f"{base_path}_3_hourly"

    print(f"\nGenerating split SQL files...")

    # =========================================================================
    # FILE 1: EVENTS
    # =========================================================================
    sql_lines = [
        "-- ============================================================================",
        "-- VATUSA Event Statistics - Events Import (Part 1 of 3+)",
        f"-- Generated: {timestamp}",
        "-- ============================================================================",
        "",
        "SET NOCOUNT ON;",
        "GO",
        "",
        f"PRINT 'Importing {len(events)} events...';",
        "GO",
        "",
    ]

    event_count = 0
    for event_idx, event in events.items():
        # Merge TMR data
        tmr = tmr_data.get(event_idx, {})

        # Calculate aggregates from hourly data
        event_hours = hourly_data.get(event_idx, [])
        total_arr = sum(h.get('arrivals', 0) or 0 for h in event_hours if isinstance(h.get('arrivals'), (int, float)))
        total_dep = sum(h.get('departures', 0) or 0 for h in event_hours if isinstance(h.get('departures'), (int, float)))
        total_ops = total_arr + total_dep

        # Count unique airports
        airports = set(h.get('airport_icao') for h in event_hours if h.get('airport_icao'))

        # RW totals
        rw_arr = sum(h.get('rw_aar', 0) or 0 for h in event_hours if isinstance(h.get('rw_aar'), (int, float)))
        rw_dep = sum(h.get('rw_adr', 0) or 0 for h in event_hours if isinstance(h.get('rw_adr'), (int, float)))
        rw_ops = rw_arr + rw_dep

        pct_rw = (total_ops / rw_ops * 100) if rw_ops > 0 else None

        # Season from event type or date
        season = None
        if event.get('start_utc'):
            month = event['start_utc'].month
            if month in [12, 1, 2]:
                season = 'Winter'
            elif month in [3, 4, 5]:
                season = 'Spring'
            elif month in [6, 7, 8]:
                season = 'Summer'
            else:
                season = 'Fall'

        sql_lines.append(f"""INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    {sql_escape(event_idx)}, {sql_escape(event.get('event_name'))}, {sql_escape(event.get('event_type'))}, {sql_escape(event.get('event_code'))},
    {sql_escape(event.get('start_utc'))}, {sql_escape(event.get('end_utc'))}, {sql_escape(event.get('day_of_week'))},
    {total_arr if total_arr else 'NULL'}, {total_dep if total_dep else 'NULL'}, {total_ops if total_ops else 'NULL'}, {len(airports) if airports else 'NULL'},
    {rw_arr if rw_arr else 'NULL'}, {rw_dep if rw_dep else 'NULL'}, {rw_ops if rw_ops else 'NULL'}, {f'{pct_rw:.2f}' if pct_rw else 'NULL'},
    {sql_escape(season)}, {event['start_utc'].month if event.get('start_utc') else 'NULL'}, {event['start_utc'].year if event.get('start_utc') else 'NULL'},
    {sql_escape(tmr.get('tmr_link'))}, {sql_escape(tmr.get('timelapse_link'))}, {sql_escape(tmr.get('simaware_link'))}, {sql_escape(tmr.get('perti_plan_link'))},
    {tmr.get('staffing_score') if tmr.get('staffing_score') else 'NULL'}, {tmr.get('tactical_score') if tmr.get('tactical_score') else 'NULL'}, {tmr.get('overall_score') if tmr.get('overall_score') else 'NULL'}, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = {sql_escape(event_idx)});""")

        event_count += 1
        # Add GO every 50 events
        if event_count % 50 == 0:
            sql_lines.append("GO")

    sql_lines.append("""
GO
PRINT 'Events inserted.';
GO
""")

    # Write events file
    with open(events_file, 'w', encoding='utf-8') as f:
        f.write('\n'.join(sql_lines))
    print(f"  Created: {events_file} ({len(events)} events)")

    # =========================================================================
    # FILE 2: AIRPORT SUMMARIES
    # =========================================================================
    sql_lines = [
        "-- ============================================================================",
        f"-- VATUSA Event Statistics - Airport Summaries (Part 2)",
        f"-- Generated: {timestamp}",
        "-- ============================================================================",
        "",
        "SET NOCOUNT ON;",
        "GO",
        "",
        f"PRINT 'Importing {len(airport_totals)} airport summaries...';",
        "GO",
        "",
    ]

    apt_count = 0
    for (event_idx, airport), totals in airport_totals.items():
        sql_lines.append(f"""INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT {sql_escape(event_idx)}, {sql_escape(airport)}, 1,
    {totals['total_arrivals'] or 'NULL'}, {totals['total_departures'] or 'NULL'}, {totals['total_operations'] or 'NULL'},
    {totals['peak_vatsim_aar'] or 'NULL'}, {sql_escape(totals['peak_hour_utc'])},
    {totals['hours_above_50pct'] or 'NULL'}, {totals['hours_above_75pct'] or 'NULL'}, {totals['hours_above_90pct'] or 'NULL'}
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = {sql_escape(event_idx)} AND airport_icao = {sql_escape(airport)});""")

        apt_count += 1
        if apt_count % 100 == 0:
            sql_lines.append("GO")

    sql_lines.append("""
GO
PRINT 'Airport summaries inserted.';
GO
""")

    with open(airports_file, 'w', encoding='utf-8') as f:
        f.write('\n'.join(sql_lines))
    print(f"  Created: {airports_file} ({len(airport_totals)} airports)")

    # =========================================================================
    # FILE 3+: HOURLY DATA (split into multiple files)
    # =========================================================================
    all_hourly = []
    for event_idx, hours in hourly_data.items():
        for h in hours:
            all_hourly.append(h)

    # Split into files of ~20K records each
    records_per_file = 20000
    file_num = 0

    for file_start in range(0, len(all_hourly), records_per_file):
        file_num += 1
        file_chunk = all_hourly[file_start:file_start + records_per_file]
        hourly_file = output_dir / f"{base_path}_3_hourly_{file_num:02d}.sql"

        sql_lines = [
            "-- ============================================================================",
            f"-- VATUSA Event Statistics - Hourly Data (Part 3, File {file_num})",
            f"-- Records {file_start + 1} to {file_start + len(file_chunk)} of {len(all_hourly)}",
            f"-- Generated: {timestamp}",
            "-- ============================================================================",
            "",
            "SET NOCOUNT ON;",
            "GO",
            "",
        ]

        # Batch inserts within each file
        batch_size = 500
        for i in range(0, len(file_chunk), batch_size):
            batch = file_chunk[i:i + batch_size]
            batch_num = (file_start + i) // batch_size + 1

            sql_lines.append(f"""
-- Batch {batch_num}
INSERT INTO dbo.vatusa_event_hourly (
    event_idx, airport_icao, hour_utc, hour_offset,
    arrivals, departures, throughput,
    vatsim_aar, vatsim_adr, vatsim_total,
    rw_aar, rw_adr, rw_total,
    pct_vatsim_aar, pct_vatsim_adr, pct_vatsim_total,
    pct_rw_aar, pct_rw_adr, pct_rw_total,
    rolling_arr, rolling_dep, rolling_throughput,
    event_airport_arr, event_airport_dep, event_airport_total,
    hourly_avg, hourly_avg_airport, hourly_avg_event_type
) VALUES""")

            values = []
            for h in batch:
                v = f"""({sql_escape(h['event_idx'])}, {sql_escape(h['airport_icao'])}, {sql_escape(h['hour_utc'])}, {h['hour_offset'] if h.get('hour_offset') is not None else 'NULL'},
    {h['arrivals'] if h.get('arrivals') is not None else 'NULL'}, {h['departures'] if h.get('departures') is not None else 'NULL'}, {h['throughput'] if h.get('throughput') is not None else 'NULL'},
    {h['vatsim_aar'] if h.get('vatsim_aar') is not None else 'NULL'}, {h['vatsim_adr'] if h.get('vatsim_adr') is not None else 'NULL'}, {h['vatsim_total'] if h.get('vatsim_total') is not None else 'NULL'},
    {h['rw_aar'] if h.get('rw_aar') is not None else 'NULL'}, {h['rw_adr'] if h.get('rw_adr') is not None else 'NULL'}, {h['rw_total'] if h.get('rw_total') is not None else 'NULL'},
    {h['pct_vatsim_aar'] if h.get('pct_vatsim_aar') is not None else 'NULL'}, {h['pct_vatsim_adr'] if h.get('pct_vatsim_adr') is not None else 'NULL'}, {h['pct_vatsim_total'] if h.get('pct_vatsim_total') is not None else 'NULL'},
    {h['pct_rw_aar'] if h.get('pct_rw_aar') is not None else 'NULL'}, {h['pct_rw_adr'] if h.get('pct_rw_adr') is not None else 'NULL'}, {h['pct_rw_total'] if h.get('pct_rw_total') is not None else 'NULL'},
    {h['rolling_arr'] if h.get('rolling_arr') is not None else 'NULL'}, {h['rolling_dep'] if h.get('rolling_dep') is not None else 'NULL'}, {h['rolling_throughput'] if h.get('rolling_throughput') is not None else 'NULL'},
    {h['event_airport_arr'] if h.get('event_airport_arr') is not None else 'NULL'}, {h['event_airport_dep'] if h.get('event_airport_dep') is not None else 'NULL'}, {h['event_airport_total'] if h.get('event_airport_total') is not None else 'NULL'},
    {h['hourly_avg'] if h.get('hourly_avg') is not None else 'NULL'}, {h['hourly_avg_airport'] if h.get('hourly_avg_airport') is not None else 'NULL'}, {h['hourly_avg_event_type'] if h.get('hourly_avg_event_type') is not None else 'NULL'})"""
                values.append(v)

            sql_lines.append(",\n".join(values) + ";")
            sql_lines.append("GO")

        sql_lines.append(f"""
PRINT 'Hourly file {file_num} complete ({len(file_chunk)} records).';
GO
""")

        with open(hourly_file, 'w', encoding='utf-8') as f:
            f.write('\n'.join(sql_lines))
        print(f"  Created: {hourly_file} ({len(file_chunk)} records)")

    # =========================================================================
    # MASTER RUN SCRIPT
    # =========================================================================
    master_file = output_dir / f"{base_path}_RUN_ALL.sql"
    sql_lines = [
        "-- ============================================================================",
        "-- VATUSA Event Statistics - Master Import Script",
        f"-- Generated: {timestamp}",
        "-- ",
        "-- Run this file with sqlcmd to execute all import files in order:",
        f"--   sqlcmd -S your_server -d VATSIM_ADL -i {master_file.name}",
        "-- ============================================================================",
        "",
        "SET NOCOUNT ON;",
        "GO",
        "",
        "PRINT '=== Starting VATUSA Event Import ===';",
        "PRINT '';",
        "GO",
        "",
        f":r {events_file.name}",
        f":r {airports_file.name}",
    ]

    for i in range(1, file_num + 1):
        sql_lines.append(f":r {base_path}_3_hourly_{i:02d}.sql")

    sql_lines.append("""
PRINT '';
PRINT '=== Import Complete ===';
GO

-- Final verification
SELECT 'vatusa_event' AS [Table], COUNT(*) AS [Count] FROM dbo.vatusa_event
UNION ALL
SELECT 'vatusa_event_airport', COUNT(*) FROM dbo.vatusa_event_airport
UNION ALL
SELECT 'vatusa_event_hourly', COUNT(*) FROM dbo.vatusa_event_hourly;
GO
""")

    with open(master_file, 'w', encoding='utf-8') as f:
        f.write('\n'.join(sql_lines))

    print(f"\n  Master script: {master_file}")
    print(f"\nTo import all data, run:")
    print(f"  sqlcmd -S your_server -d VATSIM_ADL -i \"{master_file}\"")
    print(f"\nOr run files individually in order:")
    print(f"  1. {events_file.name}")
    print(f"  2. {airports_file.name}")
    for i in range(1, file_num + 1):
        print(f"  {2+i}. {base_path}_3_hourly_{i:02d}.sql")


def main():
    parser = argparse.ArgumentParser(
        description='Import historical VATUSA event data from Excel to SQL'
    )
    parser.add_argument('--events-xlsx', default=DEFAULT_EVENTS_XLSX,
                        help='Path to VATUSA Events Data.xlsx')
    parser.add_argument('--stats-xlsb', default=DEFAULT_STATS_XLSB,
                        help='Path to VATUSA Event Statistics.xlsb')
    parser.add_argument('-o', '--output', default='074_vatusa_event_import.sql',
                        help='Output SQL file path')

    args = parser.parse_args()

    print("=" * 60)
    print("VATUSA Event Statistics - Historical Import")
    print("=" * 60)

    # Verify files exist
    if not Path(args.events_xlsx).exists():
        print(f"ERROR: Events file not found: {args.events_xlsx}")
        sys.exit(1)
    if not Path(args.stats_xlsb).exists():
        print(f"ERROR: Stats file not found: {args.stats_xlsb}")
        sys.exit(1)

    # Read data
    events = read_event_list_xlsb(args.stats_xlsb)
    tmr_data = read_tmr_xlsb(args.stats_xlsb)
    hourly_data, airport_totals = read_event_data_xlsx(args.events_xlsx)

    # Merge events from hourly data that might not be in Event List
    for event_idx in hourly_data.keys():
        if event_idx not in events:
            parsed = parse_event_idx(event_idx)
            events[event_idx] = {
                'event_idx': event_idx,
                'event_name': None,
                'event_type': parsed['event_type'],
                'event_code': parsed['event_code'],
                'start_utc': parsed['start_utc'],
                'end_utc': parsed['end_utc'],
                'day_of_week': None,
            }

    # Generate SQL
    generate_sql(events, tmr_data, hourly_data, airport_totals, args.output)

    print("\nDone! Run the generated SQL file in SSMS to import data.")
    print(f"  sqlcmd -S your_server -d VATSIM_ADL -i {args.output}")


if __name__ == '__main__':
    main()
