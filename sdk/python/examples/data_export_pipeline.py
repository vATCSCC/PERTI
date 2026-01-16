#!/usr/bin/env python3
"""
SWIM Data Export Pipeline

Batch exports flight data to various formats for external analysis.
Supports JSON, CSV, and GeoJSON output formats.

Features:
- Export active flights with full details
- Export positions as GeoJSON for mapping
- Export OOOI times for performance analysis
- Scheduled/continuous export mode
- Filtering by airport, ARTCC, airline

Usage:
    python data_export_pipeline.py YOUR_API_KEY --format json --output flights.json
    python data_export_pipeline.py YOUR_API_KEY --format csv --dest KJFK --output jfk_arrivals.csv
    python data_export_pipeline.py YOUR_API_KEY --format geojson --artcc ZNY --output zny_traffic.geojson

Consumer: Data Analysts, Reporting Systems
"""

import sys
import csv
import json
import argparse
from datetime import datetime
from typing import List, Dict, Any, Optional
from swim_client.rest import SWIMRestClient


class SWIMDataExporter:
    """Export SWIM data to various formats."""
    
    def __init__(self, api_key: str):
        self.client = SWIMRestClient(api_key)
    
    def export_flights_json(
        self,
        output_file: str,
        dept_icao: Optional[str] = None,
        dest_icao: Optional[str] = None,
        artcc: Optional[str] = None,
        callsign: Optional[str] = None,
        status: str = 'active',
    ) -> int:
        """Export flights to JSON format."""
        flights = self._fetch_all_flights(dept_icao, dest_icao, artcc, callsign, status)
        
        export_data = {
            'exported_at': datetime.utcnow().isoformat(),
            'count': len(flights),
            'filters': {
                'dept_icao': dept_icao,
                'dest_icao': dest_icao,
                'artcc': artcc,
                'callsign': callsign,
                'status': status,
            },
            'flights': flights,
        }
        
        with open(output_file, 'w') as f:
            json.dump(export_data, f, indent=2)
        
        return len(flights)
    
    def export_flights_csv(
        self,
        output_file: str,
        dept_icao: Optional[str] = None,
        dest_icao: Optional[str] = None,
        artcc: Optional[str] = None,
        callsign: Optional[str] = None,
        status: str = 'active',
    ) -> int:
        """Export flights to CSV format."""
        flights = self._fetch_all_flights(dept_icao, dest_icao, artcc, callsign, status)
        
        # Define CSV columns
        columns = [
            'callsign', 'cid', 'aircraft_type', 'departure', 'destination',
            'route', 'cruise_altitude', 'current_artcc', 'phase',
            'latitude', 'longitude', 'altitude_ft', 'groundspeed_kts', 'heading',
            'eta', 'out_time', 'off_time', 'on_time', 'in_time',
            'is_tmi_controlled', 'tmi_type', 'edct', 'delay_minutes',
            'gufi', 'flight_key',
        ]
        
        with open(output_file, 'w', newline='') as f:
            writer = csv.DictWriter(f, fieldnames=columns, extrasaction='ignore')
            writer.writeheader()
            
            for flight in flights:
                row = self._flatten_flight(flight)
                writer.writerow(row)
        
        return len(flights)
    
    def export_positions_geojson(
        self,
        output_file: str,
        dept_icao: Optional[str] = None,
        dest_icao: Optional[str] = None,
        artcc: Optional[str] = None,
        bounds: Optional[str] = None,
        include_route: bool = False,
    ) -> int:
        """Export positions to GeoJSON FeatureCollection."""
        params = {}
        if dept_icao:
            params['dept_icao'] = dept_icao
        if dest_icao:
            params['dest_icao'] = dest_icao
        if artcc:
            params['artcc'] = artcc
        if bounds:
            params['bounds'] = bounds
        
        response = self.client.get_positions(**params, include_route=include_route)
        
        # Response is already GeoJSON
        with open(output_file, 'w') as f:
            json.dump(response, f, indent=2)
        
        return len(response.get('features', []))
    
    def export_oooi_report(
        self,
        output_file: str,
        dept_icao: Optional[str] = None,
        dest_icao: Optional[str] = None,
    ) -> int:
        """Export OOOI times report for completed flights."""
        flights = self._fetch_all_flights(
            dept_icao=dept_icao,
            dest_icao=dest_icao,
            status='completed',
        )
        
        columns = [
            'callsign', 'departure', 'destination', 'aircraft_type',
            'out_time', 'off_time', 'on_time', 'in_time',
            'taxi_out_min', 'block_time_min', 'air_time_min', 'taxi_in_min',
        ]
        
        with open(output_file, 'w', newline='') as f:
            writer = csv.DictWriter(f, fieldnames=columns)
            writer.writeheader()
            
            count = 0
            for flight in flights:
                times = flight.get('times', {})
                
                # Only include flights with OOOI times
                if not all([times.get('out'), times.get('off'), times.get('on'), times.get('in')]):
                    continue
                
                # Parse times
                try:
                    out_time = datetime.fromisoformat(times['out'].replace('Z', '+00:00'))
                    off_time = datetime.fromisoformat(times['off'].replace('Z', '+00:00'))
                    on_time = datetime.fromisoformat(times['on'].replace('Z', '+00:00'))
                    in_time = datetime.fromisoformat(times['in'].replace('Z', '+00:00'))
                    
                    row = {
                        'callsign': flight.get('identity', {}).get('callsign'),
                        'departure': flight.get('flight_plan', {}).get('departure'),
                        'destination': flight.get('flight_plan', {}).get('destination'),
                        'aircraft_type': flight.get('identity', {}).get('aircraft_type'),
                        'out_time': times['out'],
                        'off_time': times['off'],
                        'on_time': times['on'],
                        'in_time': times['in'],
                        'taxi_out_min': round((off_time - out_time).total_seconds() / 60, 1),
                        'block_time_min': round((in_time - out_time).total_seconds() / 60, 1),
                        'air_time_min': round((on_time - off_time).total_seconds() / 60, 1),
                        'taxi_in_min': round((in_time - on_time).total_seconds() / 60, 1),
                    }
                    writer.writerow(row)
                    count += 1
                except (ValueError, TypeError):
                    continue
        
        return count
    
    def export_tmi_summary(self, output_file: str) -> int:
        """Export current TMI programs summary."""
        response = self.client.get_tmi_programs(type='all', include_history=True)
        
        export_data = {
            'exported_at': datetime.utcnow().isoformat(),
            'summary': response.get('summary', {}),
            'ground_stops': response.get('ground_stops', []),
            'gdp_programs': response.get('gdp_programs', []),
        }
        
        with open(output_file, 'w') as f:
            json.dump(export_data, f, indent=2)
        
        gs_count = len(response.get('ground_stops', []))
        gdp_count = len(response.get('gdp_programs', []))
        return gs_count + gdp_count
    
    def _fetch_all_flights(
        self,
        dept_icao: Optional[str] = None,
        dest_icao: Optional[str] = None,
        artcc: Optional[str] = None,
        callsign: Optional[str] = None,
        status: str = 'active',
    ) -> List[Dict[str, Any]]:
        """Fetch all flights using pagination."""
        all_flights = []
        page = 1
        
        while True:
            response = self.client.get_flights(
                dept_icao=dept_icao,
                dest_icao=dest_icao,
                artcc=artcc,
                callsign=callsign,
                status=status,
                page=page,
                per_page=500,
            )
            
            flights = response.get('data', [])
            all_flights.extend(flights)
            
            pagination = response.get('pagination', {})
            if not pagination.get('has_more', False):
                break
            
            page += 1
            
            # Safety limit
            if page > 20:
                print(f"Warning: Stopped at page 20 ({len(all_flights)} flights)")
                break
        
        return all_flights
    
    def _flatten_flight(self, flight: Dict[str, Any]) -> Dict[str, Any]:
        """Flatten nested flight structure for CSV."""
        identity = flight.get('identity', {})
        plan = flight.get('flight_plan', {})
        pos = flight.get('position', {})
        progress = flight.get('progress', {})
        times = flight.get('times', {})
        tmi = flight.get('tmi', {})
        
        return {
            'callsign': identity.get('callsign'),
            'cid': identity.get('cid'),
            'aircraft_type': identity.get('aircraft_type'),
            'departure': plan.get('departure'),
            'destination': plan.get('destination'),
            'route': plan.get('route'),
            'cruise_altitude': plan.get('cruise_altitude'),
            'current_artcc': pos.get('current_artcc'),
            'phase': progress.get('phase'),
            'latitude': pos.get('latitude'),
            'longitude': pos.get('longitude'),
            'altitude_ft': pos.get('altitude_ft'),
            'groundspeed_kts': pos.get('ground_speed_kts'),
            'heading': pos.get('heading'),
            'eta': times.get('eta'),
            'out_time': times.get('out'),
            'off_time': times.get('off'),
            'on_time': times.get('on'),
            'in_time': times.get('in'),
            'is_tmi_controlled': tmi.get('is_controlled', False),
            'tmi_type': tmi.get('control_type'),
            'edct': tmi.get('edct'),
            'delay_minutes': tmi.get('delay_minutes'),
            'gufi': flight.get('gufi'),
            'flight_key': flight.get('flight_key'),
        }


def main():
    parser = argparse.ArgumentParser(
        description='Export SWIM data to various formats',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  Export all active flights to JSON:
    python data_export_pipeline.py YOUR_KEY --format json -o flights.json

  Export JFK arrivals to CSV:
    python data_export_pipeline.py YOUR_KEY --format csv --dest KJFK -o jfk.csv

  Export ZNY positions to GeoJSON:
    python data_export_pipeline.py YOUR_KEY --format geojson --artcc ZNY -o zny.geojson

  Export OOOI times report:
    python data_export_pipeline.py YOUR_KEY --format oooi -o oooi_report.csv

  Export TMI summary:
    python data_export_pipeline.py YOUR_KEY --format tmi -o tmi_status.json
        """
    )
    
    parser.add_argument('api_key', help='SWIM API key')
    parser.add_argument('-f', '--format', required=True,
                       choices=['json', 'csv', 'geojson', 'oooi', 'tmi'],
                       help='Export format')
    parser.add_argument('-o', '--output', required=True, help='Output filename')
    parser.add_argument('--dept', help='Filter by departure airport')
    parser.add_argument('--dest', help='Filter by destination airport')
    parser.add_argument('--artcc', help='Filter by ARTCC')
    parser.add_argument('--callsign', help='Filter by callsign pattern')
    parser.add_argument('--status', default='active',
                       choices=['active', 'completed', 'all'],
                       help='Flight status filter')
    parser.add_argument('--bounds', help='Bounding box for geojson: minLon,minLat,maxLon,maxLat')
    
    args = parser.parse_args()
    
    exporter = SWIMDataExporter(args.api_key)
    
    print(f"\n📦 SWIM Data Export")
    print(f"   Format: {args.format}")
    print(f"   Output: {args.output}")
    print(f"   Time: {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')} UTC")
    print()
    
    try:
        if args.format == 'json':
            count = exporter.export_flights_json(
                args.output,
                dept_icao=args.dept,
                dest_icao=args.dest,
                artcc=args.artcc,
                callsign=args.callsign,
                status=args.status,
            )
        
        elif args.format == 'csv':
            count = exporter.export_flights_csv(
                args.output,
                dept_icao=args.dept,
                dest_icao=args.dest,
                artcc=args.artcc,
                callsign=args.callsign,
                status=args.status,
            )
        
        elif args.format == 'geojson':
            count = exporter.export_positions_geojson(
                args.output,
                dept_icao=args.dept,
                dest_icao=args.dest,
                artcc=args.artcc,
                bounds=args.bounds,
                include_route=True,
            )
        
        elif args.format == 'oooi':
            count = exporter.export_oooi_report(
                args.output,
                dept_icao=args.dept,
                dest_icao=args.dest,
            )
        
        elif args.format == 'tmi':
            count = exporter.export_tmi_summary(args.output)
        
        print(f"✅ Exported {count} records to {args.output}")
    
    except Exception as e:
        print(f"❌ Export failed: {e}")
        sys.exit(1)


if __name__ == '__main__':
    main()
