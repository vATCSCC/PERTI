#!/usr/bin/env python3
"""
VATUSA Event AAR/ADR Entry Web Application

A Flask app for manually entering hourly AAR/ADR data for events
that couldn't be automatically determined.

Run with:
    python app.py

Then visit http://localhost:5000
"""

import pyodbc
from flask import Flask, render_template, request, jsonify, redirect, url_for
from datetime import datetime, timedelta
import math

app = Flask(__name__)

# Database connection
CONNECTION_STRING = (
    "Driver={ODBC Driver 18 for SQL Server};"
    "Server=vatsim.database.windows.net;"
    "Database=VATSIM_ADL;"
    "Uid=jpeterson;"
    "Pwd=***REMOVED***;"
    "TrustServerCertificate=yes;"
)


def get_db_connection():
    """Get a database connection."""
    return pyodbc.connect(CONNECTION_STRING)


def get_events_missing_aar():
    """Get all event airports missing AAR/ADR data."""
    conn = get_db_connection()
    cursor = conn.cursor()

    cursor.execute("""
        SELECT
            ea.event_idx,
            ea.airport_icao,
            e.event_name,
            e.event_type,
            e.start_utc,
            e.end_utc,
            e.duration_hours,
            ea.total_arrivals,
            ea.total_departures,
            ea.total_operations,
            CASE WHEN e.duration_hours > 0
                 THEN CAST(ea.total_arrivals / e.duration_hours AS DECIMAL(6,1))
                 ELSE NULL END as calc_avg_aar,
            CASE WHEN e.duration_hours > 0
                 THEN CAST(ea.total_departures / e.duration_hours AS DECIMAL(6,1))
                 ELSE NULL END as calc_avg_adr,
            e.source,
            (SELECT COUNT(*) FROM dbo.vatusa_event_hourly h
             WHERE h.event_idx = ea.event_idx AND h.airport_icao = ea.airport_icao) as hourly_count
        FROM dbo.vatusa_event_airport ea
        JOIN dbo.vatusa_event e ON ea.event_idx = e.event_idx
        WHERE ea.peak_vatsim_aar IS NULL
        ORDER BY e.start_utc DESC, ea.airport_icao
    """)

    columns = [column[0] for column in cursor.description]
    events = [dict(zip(columns, row)) for row in cursor.fetchall()]
    conn.close()

    return events


def get_airport_configs(icao):
    """Get available configurations for an airport."""
    conn = get_db_connection()
    cursor = conn.cursor()

    cursor.execute("""
        SELECT DISTINCT
            c.config_id,
            c.config_name,
            arr.rate_value as vatsim_aar,
            dep.rate_value as vatsim_adr,
            arr_rw.rate_value as rw_aar,
            dep_rw.rate_value as rw_adr
        FROM dbo.airport_config c
        LEFT JOIN dbo.airport_config_rate arr
            ON c.config_id = arr.config_id
            AND arr.source = 'VATSIM' AND arr.weather = 'VMC' AND arr.rate_type = 'ARR'
        LEFT JOIN dbo.airport_config_rate dep
            ON c.config_id = dep.config_id
            AND dep.source = 'VATSIM' AND dep.weather = 'VMC' AND dep.rate_type = 'DEP'
        LEFT JOIN dbo.airport_config_rate arr_rw
            ON c.config_id = arr_rw.config_id
            AND arr_rw.source = 'RW' AND arr_rw.weather = 'VMC' AND arr_rw.rate_type = 'ARR'
        LEFT JOIN dbo.airport_config_rate dep_rw
            ON c.config_id = dep_rw.config_id
            AND dep_rw.source = 'RW' AND dep_rw.weather = 'VMC' AND dep_rw.rate_type = 'DEP'
        WHERE c.airport_icao = ?
        ORDER BY c.config_name
    """, icao)

    columns = [column[0] for column in cursor.description]
    configs = [dict(zip(columns, row)) for row in cursor.fetchall()]
    conn.close()

    return configs


def get_event_details(event_idx, airport_icao):
    """Get details for a specific event airport."""
    conn = get_db_connection()
    cursor = conn.cursor()

    cursor.execute("""
        SELECT
            ea.event_idx,
            ea.airport_icao,
            e.event_name,
            e.event_type,
            e.start_utc,
            e.end_utc,
            e.duration_hours,
            ea.total_arrivals,
            ea.total_departures,
            ea.total_operations,
            ea.peak_vatsim_aar,
            ea.avg_vatsim_aar,
            ea.avg_vatsim_adr,
            ea.aar_source,
            CASE WHEN e.duration_hours > 0
                 THEN CAST(ea.total_arrivals / e.duration_hours AS DECIMAL(6,1))
                 ELSE NULL END as calc_avg_aar,
            CASE WHEN e.duration_hours > 0
                 THEN CAST(ea.total_departures / e.duration_hours AS DECIMAL(6,1))
                 ELSE NULL END as calc_avg_adr
        FROM dbo.vatusa_event_airport ea
        JOIN dbo.vatusa_event e ON ea.event_idx = e.event_idx
        WHERE ea.event_idx = ? AND ea.airport_icao = ?
    """, event_idx, airport_icao)

    row = cursor.fetchone()
    if row:
        columns = [column[0] for column in cursor.description]
        event = dict(zip(columns, row))
    else:
        event = None
    conn.close()

    return event


def get_hourly_data(event_idx, airport_icao):
    """Get existing hourly data for an event airport."""
    conn = get_db_connection()
    cursor = conn.cursor()

    cursor.execute("""
        SELECT
            hour_utc,
            hour_offset,
            arrivals,
            departures,
            throughput,
            vatsim_aar,
            vatsim_adr
        FROM dbo.vatusa_event_hourly
        WHERE event_idx = ? AND airport_icao = ?
        ORDER BY hour_offset
    """, event_idx, airport_icao)

    columns = [column[0] for column in cursor.description]
    hourly = [dict(zip(columns, row)) for row in cursor.fetchall()]
    conn.close()

    return hourly


def generate_hour_slots(start_utc, end_utc):
    """Generate hourly time slots between start and end."""
    slots = []
    current = start_utc.replace(minute=0, second=0, microsecond=0)

    # If start time has minutes, round up to next hour
    if start_utc.minute > 0:
        current += timedelta(hours=1)

    offset = 0
    while current <= end_utc:
        hour_str = current.strftime('%H%MZ')
        slots.append({
            'hour_utc': hour_str,
            'hour_offset': offset,
            'datetime': current
        })
        current += timedelta(hours=1)
        offset += 1

    return slots


@app.route('/')
def index():
    """Main page - list events missing AAR/ADR."""
    events = get_events_missing_aar()

    # Group by source
    excel_events = [e for e in events if e['source'] == 'EXCEL']
    statsim_events = [e for e in events if e['source'] == 'STATSIM']

    return render_template('index.html',
                         excel_events=excel_events,
                         statsim_events=statsim_events,
                         total_missing=len(events))


@app.route('/edit/<path:event_idx>/<airport_icao>')
def edit_event(event_idx, airport_icao):
    """Edit form for a specific event airport with hourly breakdown."""
    event = get_event_details(event_idx, airport_icao)
    if not event:
        return "Event not found", 404

    configs = get_airport_configs(airport_icao)

    # Get existing hourly data
    existing_hourly = get_hourly_data(event_idx, airport_icao)
    existing_by_hour = {h['hour_utc']: h for h in existing_hourly}

    # Generate hour slots for this event
    if event['start_utc'] and event['end_utc']:
        slots = generate_hour_slots(event['start_utc'], event['end_utc'])

        # Merge existing data with slots
        hourly_data = []
        for slot in slots:
            existing = existing_by_hour.get(slot['hour_utc'], {})
            hourly_data.append({
                'hour_utc': slot['hour_utc'],
                'hour_offset': slot['hour_offset'],
                'arrivals': existing.get('arrivals'),
                'departures': existing.get('departures'),
                'throughput': existing.get('throughput'),
                'vatsim_aar': existing.get('vatsim_aar'),
                'vatsim_adr': existing.get('vatsim_adr'),
            })
    else:
        hourly_data = existing_hourly

    return render_template('edit.html', event=event, configs=configs, hourly_data=hourly_data)


@app.route('/api/configs/<airport_icao>')
def api_configs(airport_icao):
    """API endpoint to get configs for an airport."""
    configs = get_airport_configs(airport_icao)
    return jsonify(configs)


@app.route('/api/update', methods=['POST'])
def api_update():
    """API endpoint to update AAR/ADR for an event airport."""
    data = request.json

    event_idx = data.get('event_idx')
    airport_icao = data.get('airport_icao')
    peak_vatsim_aar = data.get('peak_vatsim_aar')
    avg_vatsim_aar = data.get('avg_vatsim_aar')
    avg_vatsim_adr = data.get('avg_vatsim_adr')
    aar_source = data.get('aar_source', 'MANUAL')

    if not event_idx or not airport_icao:
        return jsonify({'success': False, 'error': 'Missing event_idx or airport_icao'}), 400

    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        cursor.execute("""
            UPDATE dbo.vatusa_event_airport
            SET peak_vatsim_aar = ?,
                avg_vatsim_aar = ?,
                avg_vatsim_adr = ?,
                aar_source = ?
            WHERE event_idx = ? AND airport_icao = ?
        """, peak_vatsim_aar, avg_vatsim_aar, avg_vatsim_adr, aar_source,
            event_idx, airport_icao)

        conn.commit()
        conn.close()

        return jsonify({'success': True, 'message': 'Updated successfully'})

    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500


@app.route('/submit', methods=['POST'])
def submit_form():
    """Handle form submission with hourly data."""
    event_idx = request.form.get('event_idx')
    airport_icao = request.form.get('airport_icao')
    aar_source = request.form.get('aar_source', 'MANUAL')

    # Get hourly data from form
    hourly_entries = []
    hour_keys = [k for k in request.form.keys() if k.startswith('vatsim_aar_')]

    for key in hour_keys:
        hour_utc = key.replace('vatsim_aar_', '')
        vatsim_aar = request.form.get(f'vatsim_aar_{hour_utc}')
        vatsim_adr = request.form.get(f'vatsim_adr_{hour_utc}')
        hour_offset = request.form.get(f'hour_offset_{hour_utc}')

        if vatsim_aar or vatsim_adr:
            hourly_entries.append({
                'hour_utc': hour_utc,
                'hour_offset': int(hour_offset) if hour_offset else 0,
                'vatsim_aar': int(vatsim_aar) if vatsim_aar else None,
                'vatsim_adr': int(vatsim_adr) if vatsim_adr else None
            })

    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        # Update or insert hourly data
        for entry in hourly_entries:
            # Check if exists
            cursor.execute("""
                SELECT id FROM dbo.vatusa_event_hourly
                WHERE event_idx = ? AND airport_icao = ? AND hour_utc = ?
            """, event_idx, airport_icao, entry['hour_utc'])

            existing = cursor.fetchone()

            if existing:
                # Update existing
                cursor.execute("""
                    UPDATE dbo.vatusa_event_hourly
                    SET vatsim_aar = ?, vatsim_adr = ?
                    WHERE event_idx = ? AND airport_icao = ? AND hour_utc = ?
                """, entry['vatsim_aar'], entry['vatsim_adr'],
                    event_idx, airport_icao, entry['hour_utc'])
            else:
                # Insert new
                cursor.execute("""
                    INSERT INTO dbo.vatusa_event_hourly
                    (event_idx, airport_icao, hour_utc, hour_offset, vatsim_aar, vatsim_adr, created_utc)
                    VALUES (?, ?, ?, ?, ?, ?, GETUTCDATE())
                """, event_idx, airport_icao, entry['hour_utc'], entry['hour_offset'],
                    entry['vatsim_aar'], entry['vatsim_adr'])

        # Calculate peak and average from hourly data
        if hourly_entries:
            aar_values = [e['vatsim_aar'] for e in hourly_entries if e['vatsim_aar']]
            adr_values = [e['vatsim_adr'] for e in hourly_entries if e['vatsim_adr']]

            peak_aar = max(aar_values) if aar_values else None
            avg_aar = sum(aar_values) / len(aar_values) if aar_values else None
            avg_adr = sum(adr_values) / len(adr_values) if adr_values else None

            # Update airport summary
            cursor.execute("""
                UPDATE dbo.vatusa_event_airport
                SET peak_vatsim_aar = ?,
                    avg_vatsim_aar = ?,
                    avg_vatsim_adr = ?,
                    aar_source = ?
                WHERE event_idx = ? AND airport_icao = ?
            """, peak_aar, avg_aar, avg_adr, aar_source, event_idx, airport_icao)

        conn.commit()
        conn.close()

        return redirect(url_for('index'))

    except Exception as e:
        return f"Error: {str(e)}", 500


@app.route('/api/apply-config', methods=['POST'])
def apply_config_to_all():
    """Apply a config's rates to all hourly slots."""
    data = request.json

    event_idx = data.get('event_idx')
    airport_icao = data.get('airport_icao')
    vatsim_aar = data.get('vatsim_aar')
    vatsim_adr = data.get('vatsim_adr')

    if not event_idx or not airport_icao:
        return jsonify({'success': False, 'error': 'Missing required fields'}), 400

    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        # Get event details for hour generation
        cursor.execute("""
            SELECT e.start_utc, e.end_utc
            FROM dbo.vatusa_event e
            JOIN dbo.vatusa_event_airport ea ON e.event_idx = ea.event_idx
            WHERE ea.event_idx = ? AND ea.airport_icao = ?
        """, event_idx, airport_icao)

        row = cursor.fetchone()
        if not row:
            return jsonify({'success': False, 'error': 'Event not found'}), 404

        start_utc, end_utc = row
        slots = generate_hour_slots(start_utc, end_utc)

        # Insert/update all hourly slots with the config rates
        for slot in slots:
            cursor.execute("""
                MERGE dbo.vatusa_event_hourly AS target
                USING (SELECT ? as event_idx, ? as airport_icao, ? as hour_utc) AS source
                ON target.event_idx = source.event_idx
                   AND target.airport_icao = source.airport_icao
                   AND target.hour_utc = source.hour_utc
                WHEN MATCHED THEN
                    UPDATE SET vatsim_aar = ?, vatsim_adr = ?
                WHEN NOT MATCHED THEN
                    INSERT (event_idx, airport_icao, hour_utc, hour_offset, vatsim_aar, vatsim_adr, created_utc)
                    VALUES (?, ?, ?, ?, ?, ?, GETUTCDATE());
            """, event_idx, airport_icao, slot['hour_utc'],
                vatsim_aar, vatsim_adr,
                event_idx, airport_icao, slot['hour_utc'], slot['hour_offset'], vatsim_aar, vatsim_adr)

        # Update airport summary
        cursor.execute("""
            UPDATE dbo.vatusa_event_airport
            SET peak_vatsim_aar = ?,
                avg_vatsim_aar = ?,
                avg_vatsim_adr = ?,
                aar_source = 'CONFIG'
            WHERE event_idx = ? AND airport_icao = ?
        """, vatsim_aar, vatsim_aar, vatsim_adr, event_idx, airport_icao)

        conn.commit()
        conn.close()

        return jsonify({'success': True, 'message': f'Applied to {len(slots)} hours'})

    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500


@app.template_filter('datetime_format')
def datetime_format(value, format='%Y-%m-%d %H:%M'):
    """Format datetime for display."""
    if value is None:
        return ''
    if isinstance(value, str):
        return value
    return value.strftime(format)


if __name__ == '__main__':
    print("Starting VATUSA Event AAR/ADR Entry Server...")
    print("Visit http://localhost:5000")
    app.run(debug=True, host='0.0.0.0', port=5000)
