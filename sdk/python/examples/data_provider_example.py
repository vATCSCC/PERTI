#!/usr/bin/env python3
"""
Data Provider / CRC Integration Example

Demonstrates how to ingest flight and track data into SWIM API.
Useful for CRC, external radar feeds, or virtual airline dispatch systems.

Features:
- Batch flight data ingest
- Real-time track/position updates
- TMI assignment updates
- Error handling and retry logic
- Rate limiting compliance

Usage:
    python data_provider_example.py YOUR_API_KEY --mode demo
    python data_provider_example.py YOUR_API_KEY --mode file --input flights.json

Consumer: CRC Developers, Data Providers, Virtual Airline Systems
"""

import sys
import json
import time
import argparse
from datetime import datetime, timedelta
from typing import List, Dict, Any, Optional
from swim_client.rest import SWIMRestClient, SWIMAuthError, SWIMRateLimitError, SWIMAPIError


class SWIMDataProvider:
    """
    Data provider for ingesting flight and track data into SWIM.
    
    Requires an API key with write permissions (can_write=true).
    """
    
    def __init__(self, api_key: str, debug: bool = False):
        self.client = SWIMRestClient(api_key, debug=debug)
        self.stats = {
            'flights_submitted': 0,
            'flights_created': 0,
            'flights_updated': 0,
            'tracks_submitted': 0,
            'tracks_updated': 0,
            'errors': 0,
        }
    
    def ingest_flights(self, flights: List[Dict[str, Any]], batch_size: int = 100) -> Dict[str, Any]:
        """
        Ingest flight data in batches.
        
        Args:
            flights: List of flight records
            batch_size: Records per batch (max 500)
        
        Returns:
            Summary of ingest results
        """
        results = {
            'batches': 0,
            'processed': 0,
            'created': 0,
            'updated': 0,
            'errors': 0,
            'error_details': [],
        }
        
        # Process in batches
        for i in range(0, len(flights), batch_size):
            batch = flights[i:i + batch_size]
            
            try:
                response = self.client.ingest_flights(batch)
                
                results['batches'] += 1
                results['processed'] += response.get('processed', 0)
                results['created'] += response.get('created', 0)
                results['updated'] += response.get('updated', 0)
                results['errors'] += response.get('errors', 0)
                
                if response.get('error_details'):
                    results['error_details'].extend(response['error_details'])
                
                self.stats['flights_submitted'] += len(batch)
                self.stats['flights_created'] += response.get('created', 0)
                self.stats['flights_updated'] += response.get('updated', 0)
                
                print(f"   Batch {results['batches']}: {response.get('processed', 0)} processed, "
                      f"{response.get('created', 0)} created, {response.get('updated', 0)} updated")
                
            except SWIMRateLimitError:
                print("   ⚠️ Rate limited, waiting 60 seconds...")
                time.sleep(60)
                # Retry batch
                i -= batch_size
                continue
            
            except SWIMAuthError as e:
                print(f"   ❌ Authentication error: {e}")
                print("      Make sure your API key has write permissions")
                results['errors'] += len(batch)
                self.stats['errors'] += len(batch)
                break
            
            except SWIMAPIError as e:
                print(f"   ❌ API error: {e}")
                results['errors'] += len(batch)
                self.stats['errors'] += len(batch)
        
        return results
    
    def ingest_tracks(self, tracks: List[Dict[str, Any]], batch_size: int = 200) -> Dict[str, Any]:
        """
        Ingest track/position data in batches.
        
        Args:
            tracks: List of track records
            batch_size: Records per batch (max 1000)
        
        Returns:
            Summary of ingest results
        """
        results = {
            'batches': 0,
            'processed': 0,
            'updated': 0,
            'not_found': 0,
            'errors': 0,
        }
        
        for i in range(0, len(tracks), batch_size):
            batch = tracks[i:i + batch_size]
            
            try:
                response = self.client.ingest_tracks(batch)
                
                results['batches'] += 1
                results['processed'] += response.get('processed', 0)
                results['updated'] += response.get('updated', 0)
                results['not_found'] += response.get('not_found', 0)
                results['errors'] += response.get('errors', 0)
                
                self.stats['tracks_submitted'] += len(batch)
                self.stats['tracks_updated'] += response.get('updated', 0)
                
            except SWIMRateLimitError:
                print("   ⚠️ Rate limited, waiting 60 seconds...")
                time.sleep(60)
                i -= batch_size
                continue
            
            except SWIMAPIError as e:
                print(f"   ❌ API error: {e}")
                results['errors'] += len(batch)
                self.stats['errors'] += len(batch)
        
        return results
    
    def print_stats(self):
        """Print cumulative statistics."""
        print("\n📊 INGEST STATISTICS")
        print("-" * 40)
        print(f"   Flights submitted:  {self.stats['flights_submitted']}")
        print(f"   Flights created:    {self.stats['flights_created']}")
        print(f"   Flights updated:    {self.stats['flights_updated']}")
        print(f"   Tracks submitted:   {self.stats['tracks_submitted']}")
        print(f"   Tracks updated:     {self.stats['tracks_updated']}")
        print(f"   Errors:             {self.stats['errors']}")
        print()


def generate_demo_flights(count: int = 10) -> List[Dict[str, Any]]:
    """Generate demo flight data for testing."""
    import random
    
    airlines = ['AAL', 'UAL', 'DAL', 'SWA', 'JBU', 'ASA']
    airports = ['KJFK', 'KLAX', 'KORD', 'KATL', 'KDFW', 'KDEN', 'KSFO', 'KLAS', 'KMIA', 'KBOS']
    aircraft = ['B738', 'A320', 'B77W', 'A321', 'E175', 'B739', 'A319']
    
    flights = []
    now = datetime.utcnow()
    
    for i in range(count):
        airline = random.choice(airlines)
        flight_num = random.randint(100, 9999)
        callsign = f"{airline}{flight_num}"
        
        dep = random.choice(airports)
        dest = random.choice([a for a in airports if a != dep])
        
        # Random position along route
        lat = random.uniform(25.0, 48.0)
        lon = random.uniform(-125.0, -70.0)
        alt = random.randint(30000, 41000)
        hdg = random.randint(0, 359)
        gs = random.randint(420, 520)
        
        flights.append({
            'callsign': callsign,
            'cid': 1000000 + i,
            'dept_icao': dep,
            'dest_icao': dest,
            'aircraft_type': random.choice(aircraft),
            'route': f"{dep} DCT {dest}",
            'phase': 'ENROUTE',
            'is_active': True,
            'latitude': round(lat, 6),
            'longitude': round(lon, 6),
            'altitude_ft': alt,
            'heading_deg': hdg,
            'groundspeed_kts': gs,
            'vertical_rate_fpm': random.randint(-500, 500),
            'eta_utc': (now + timedelta(hours=random.uniform(1, 4))).strftime('%Y-%m-%dT%H:%M:%SZ'),
        })
    
    return flights


def generate_demo_tracks(flights: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """Generate track updates for existing flights."""
    import random
    
    tracks = []
    
    for flight in flights:
        # Slightly modify position
        tracks.append({
            'callsign': flight['callsign'],
            'latitude': flight['latitude'] + random.uniform(-0.1, 0.1),
            'longitude': flight['longitude'] + random.uniform(-0.1, 0.1),
            'altitude_ft': flight.get('altitude_ft', 35000) + random.randint(-100, 100),
            'ground_speed_kts': flight.get('groundspeed_kts', 450) + random.randint(-10, 10),
            'heading_deg': flight.get('heading_deg', 0),
            'vertical_rate_fpm': random.randint(-1000, 1000),
            'track_source': 'CRC_DEMO',
            'timestamp': datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ'),
        })
    
    return tracks


def load_flights_from_file(filepath: str) -> List[Dict[str, Any]]:
    """Load flight data from JSON file."""
    with open(filepath, 'r') as f:
        data = json.load(f)
    
    # Handle both array and object with 'flights' key
    if isinstance(data, list):
        return data
    elif isinstance(data, dict) and 'flights' in data:
        return data['flights']
    else:
        raise ValueError("Invalid file format: expected array or object with 'flights' key")


def main():
    parser = argparse.ArgumentParser(
        description='SWIM Data Provider / Ingest Example',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  Demo mode (generates test data):
    python data_provider_example.py YOUR_API_KEY --mode demo

  File mode (load from JSON):
    python data_provider_example.py YOUR_API_KEY --mode file --input flights.json

  Continuous mode (periodic updates):
    python data_provider_example.py YOUR_API_KEY --mode continuous --interval 15

Flight JSON format:
{
  "flights": [
    {
      "callsign": "DAL123",
      "dept_icao": "KJFK",
      "dest_icao": "KLAX",
      "cid": 1234567,
      "aircraft_type": "B738",
      "route": "KJFK DCT KLAX",
      "phase": "ENROUTE",
      "is_active": true,
      "latitude": 40.6413,
      "longitude": -73.7781,
      "altitude_ft": 35000,
      "groundspeed_kts": 450,
      "heading_deg": 270
    }
  ]
}
        """
    )
    
    parser.add_argument('api_key', help='SWIM API key with write permissions')
    parser.add_argument('--mode', choices=['demo', 'file', 'continuous'], default='demo',
                       help='Operation mode')
    parser.add_argument('--input', help='Input JSON file (for file mode)')
    parser.add_argument('--count', type=int, default=10, help='Number of demo flights')
    parser.add_argument('--interval', type=int, default=15,
                       help='Update interval in seconds (for continuous mode)')
    parser.add_argument('--debug', action='store_true', help='Enable debug output')
    
    args = parser.parse_args()
    
    provider = SWIMDataProvider(args.api_key, debug=args.debug)
    
    print(f"\n{'='*60}")
    print(f"  SWIM Data Provider")
    print(f"  Mode: {args.mode}")
    print(f"  Time: {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')} UTC")
    print(f"{'='*60}\n")
    
    try:
        if args.mode == 'demo':
            # Generate and ingest demo data
            print(f"📝 Generating {args.count} demo flights...")
            flights = generate_demo_flights(args.count)
            
            print(f"\n📤 Ingesting flights...")
            result = provider.ingest_flights(flights)
            
            print(f"\n   ✅ Completed: {result['processed']} processed, "
                  f"{result['created']} created, {result['updated']} updated")
            
            # Generate track updates
            print(f"\n📤 Ingesting track updates...")
            tracks = generate_demo_tracks(flights)
            track_result = provider.ingest_tracks(tracks)
            
            print(f"\n   ✅ Completed: {track_result['processed']} processed, "
                  f"{track_result['updated']} updated")
        
        elif args.mode == 'file':
            if not args.input:
                print("❌ Error: --input required for file mode")
                sys.exit(1)
            
            print(f"📂 Loading flights from: {args.input}")
            flights = load_flights_from_file(args.input)
            print(f"   Found {len(flights)} flights")
            
            print(f"\n📤 Ingesting flights...")
            result = provider.ingest_flights(flights)
            
            print(f"\n   ✅ Completed: {result['processed']} processed, "
                  f"{result['created']} created, {result['updated']} updated")
            
            if result['error_details']:
                print(f"\n   ⚠️ Errors:")
                for err in result['error_details'][:5]:
                    print(f"      - {err}")
        
        elif args.mode == 'continuous':
            print(f"🔄 Running continuous updates every {args.interval} seconds")
            print("   Press Ctrl+C to stop\n")
            
            flights = generate_demo_flights(args.count)
            
            while True:
                # Update positions
                tracks = generate_demo_tracks(flights)
                provider.ingest_tracks(tracks)
                
                print(f"   📍 Updated {len(tracks)} positions at "
                      f"{datetime.utcnow().strftime('%H:%M:%S')} UTC")
                
                time.sleep(args.interval)
        
        provider.print_stats()
    
    except KeyboardInterrupt:
        print("\n\n⏹️ Stopped by user")
        provider.print_stats()
    
    except SWIMAuthError as e:
        print(f"\n❌ Authentication Error: {e}")
        print("   Make sure your API key has write permissions (can_write=true)")
        sys.exit(1)
    
    except Exception as e:
        print(f"\n❌ Error: {e}")
        sys.exit(1)


if __name__ == '__main__':
    main()
