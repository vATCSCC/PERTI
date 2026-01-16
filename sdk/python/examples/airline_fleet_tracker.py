#!/usr/bin/env python3
"""
Airline Fleet Tracker

Monitors all flights for a specific airline/callsign prefix in real-time.
Useful for virtual airline operations centers tracking their fleet.

Features:
- Tracks all flights matching airline prefix (e.g., 'DAL', 'UAL', 'VAL')
- Displays fleet status board with live updates
- Calculates on-time performance metrics
- Logs OOOI events for pilot records

Usage:
    python airline_fleet_tracker.py YOUR_API_KEY DAL
    python airline_fleet_tracker.py YOUR_API_KEY "VAL*"

Consumer: Virtual Airlines
"""

import sys
import json
from datetime import datetime, timedelta
from collections import defaultdict
from typing import Dict, List, Optional
from swim_client import SWIMClient
from swim_client.rest import SWIMRestClient
from swim_client.models import Flight, FlightsResponse


class AirlineFleetTracker:
    """Tracks all flights for a specific airline."""
    
    def __init__(self, api_key: str, airline_prefix: str):
        self.api_key = api_key
        self.airline_prefix = airline_prefix.upper().rstrip('*')
        self.callsign_pattern = f"{self.airline_prefix}*"
        
        # Fleet state
        self.fleet: Dict[str, dict] = {}  # callsign -> flight data
        self.oooi_log: List[dict] = []     # OOOI event log
        
        # Metrics
        self.stats = {
            'departures': 0,
            'arrivals': 0,
            'on_time_departures': 0,
            'on_time_arrivals': 0,
            'total_delay_minutes': 0,
        }
        
        # Clients
        self.rest_client = SWIMRestClient(api_key)
        self.ws_client = SWIMClient(api_key)
    
    def load_initial_fleet(self):
        """Load current fleet status via REST API."""
        print(f"\n📡 Loading {self.airline_prefix} fleet status...")
        
        try:
            # Get all active flights for airline
            response = self.rest_client.get_flights(
                callsign=self.callsign_pattern,
                status='active',
                per_page=500,
            )
            
            flights = response.get('data', [])
            
            for flight_data in flights:
                callsign = flight_data.get('identity', {}).get('callsign', '')
                if callsign.startswith(self.airline_prefix):
                    self.fleet[callsign] = flight_data
            
            print(f"   Found {len(self.fleet)} active flights")
            self.print_fleet_board()
            
        except Exception as e:
            print(f"❌ Error loading fleet: {e}")
    
    def print_fleet_board(self):
        """Display current fleet status board."""
        print("\n" + "=" * 80)
        print(f"  {self.airline_prefix} FLEET STATUS - {datetime.utcnow().strftime('%H:%M:%S')} UTC")
        print("=" * 80)
        print(f"{'FLIGHT':<10} {'ROUTE':<15} {'PHASE':<12} {'ALT':>7} {'GS':>5} {'ETA':>8}")
        print("-" * 80)
        
        # Sort by phase priority
        phase_order = ['PREFLIGHT', 'DEPARTING', 'CLIMBING', 'ENROUTE', 'DESCENDING', 'APPROACH', 'LANDED']
        
        sorted_flights = sorted(
            self.fleet.items(),
            key=lambda x: (
                phase_order.index(x[1].get('progress', {}).get('phase', 'ENROUTE'))
                if x[1].get('progress', {}).get('phase') in phase_order else 99,
                x[0]
            )
        )
        
        for callsign, flight in sorted_flights:
            identity = flight.get('identity', {})
            plan = flight.get('flight_plan', {})
            pos = flight.get('position', {})
            progress = flight.get('progress', {})
            times = flight.get('times', {})
            
            dep = plan.get('departure', '????')
            dest = plan.get('destination', '????')
            route = f"{dep}-{dest}"
            phase = progress.get('phase', 'UNK')[:11]
            alt = pos.get('altitude_ft', 0) // 100  # Flight level
            gs = pos.get('ground_speed_kts', 0)
            eta = times.get('eta', '')[-8:-3] if times.get('eta') else '--:--'
            
            # Phase icon
            phase_icon = {
                'PREFLIGHT': '🅿️',
                'DEPARTING': '🛫',
                'CLIMBING': '📈',
                'ENROUTE': '✈️',
                'DESCENDING': '📉',
                'APPROACH': '🛬',
                'LANDED': '🏁',
            }.get(phase, '❓')
            
            print(f"{phase_icon} {callsign:<8} {route:<15} {phase:<12} FL{alt:03d}   {gs:>4} {eta:>8}")
        
        print("-" * 80)
        print(f"  Total: {len(self.fleet)} aircraft active")
        print()
    
    def print_metrics(self):
        """Display on-time performance metrics."""
        print("\n📊 PERFORMANCE METRICS")
        print("-" * 40)
        
        if self.stats['departures'] > 0:
            dep_otp = (self.stats['on_time_departures'] / self.stats['departures']) * 100
            print(f"   Departure OTP: {dep_otp:.1f}% ({self.stats['on_time_departures']}/{self.stats['departures']})")
        
        if self.stats['arrivals'] > 0:
            arr_otp = (self.stats['on_time_arrivals'] / self.stats['arrivals']) * 100
            print(f"   Arrival OTP:   {arr_otp:.1f}% ({self.stats['on_time_arrivals']}/{self.stats['arrivals']})")
        
        if self.stats['departures'] + self.stats['arrivals'] > 0:
            avg_delay = self.stats['total_delay_minutes'] / (self.stats['departures'] + self.stats['arrivals'])
            print(f"   Avg Delay:     {avg_delay:.1f} minutes")
        
        print()
    
    def log_oooi_event(self, callsign: str, event_type: str, timestamp: str, airport: str):
        """Log OOOI event for pilot records."""
        event = {
            'callsign': callsign,
            'event': event_type,
            'timestamp': timestamp,
            'airport': airport,
            'logged_at': datetime.utcnow().isoformat(),
        }
        self.oooi_log.append(event)
        
        icon = {'OUT': '🚪', 'OFF': '🛫', 'ON': '🛬', 'IN': '🚪'}.get(event_type, '📝')
        print(f"   {icon} OOOI: {callsign} {event_type} at {airport} - {timestamp}")
    
    def setup_websocket_handlers(self):
        """Configure WebSocket event handlers."""
        
        @self.ws_client.on('connected')
        def on_connected(info, timestamp):
            print(f"\n✅ Connected to SWIM WebSocket")
            print(f"   Tracking: {self.airline_prefix}* flights")
        
        @self.ws_client.on('flight.created')
        def on_created(event, timestamp):
            if event.callsign.startswith(self.airline_prefix):
                print(f"\n✈️  NEW FLIGHT: {event.callsign}")
                print(f"   Route: {event.dep} → {event.arr}")
                self.fleet[event.callsign] = {
                    'identity': {'callsign': event.callsign},
                    'flight_plan': {'departure': event.dep, 'destination': event.arr},
                    'progress': {'phase': 'PREFLIGHT'},
                }
        
        @self.ws_client.on('flight.departed')
        def on_departed(event, timestamp):
            if event.callsign.startswith(self.airline_prefix):
                print(f"\n🛫 DEPARTURE: {event.callsign} from {event.dep}")
                self.stats['departures'] += 1
                
                # Log OFF event
                if hasattr(event, 'off_utc') and event.off_utc:
                    self.log_oooi_event(event.callsign, 'OFF', event.off_utc, event.dep)
                
                # Update fleet
                if event.callsign in self.fleet:
                    self.fleet[event.callsign]['progress'] = {'phase': 'CLIMBING'}
        
        @self.ws_client.on('flight.arrived')
        def on_arrived(event, timestamp):
            if event.callsign.startswith(self.airline_prefix):
                print(f"\n🛬 ARRIVAL: {event.callsign} at {event.arr}")
                self.stats['arrivals'] += 1
                
                # Log ON/IN events
                if hasattr(event, 'on_utc') and event.on_utc:
                    self.log_oooi_event(event.callsign, 'ON', event.on_utc, event.arr)
                if hasattr(event, 'in_utc') and event.in_utc:
                    self.log_oooi_event(event.callsign, 'IN', event.in_utc, event.arr)
                
                # Remove from active fleet
                if event.callsign in self.fleet:
                    del self.fleet[event.callsign]
        
        @self.ws_client.on('flight.position')
        def on_position(event, timestamp):
            if event.callsign.startswith(self.airline_prefix):
                # Update position in fleet
                if event.callsign in self.fleet:
                    self.fleet[event.callsign]['position'] = {
                        'latitude': event.latitude,
                        'longitude': event.longitude,
                        'altitude_ft': event.altitude,
                        'ground_speed_kts': event.groundspeed,
                        'heading': event.heading,
                    }
        
        @self.ws_client.on('system.heartbeat')
        def on_heartbeat(data, timestamp):
            # Refresh display every heartbeat
            self.print_fleet_board()
            self.print_metrics()
    
    def run(self):
        """Start the fleet tracker."""
        print(f"\n{'='*60}")
        print(f"  {self.airline_prefix} AIRLINE FLEET TRACKER")
        print(f"  Started: {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')} UTC")
        print(f"{'='*60}")
        
        # Load initial fleet
        self.load_initial_fleet()
        
        # Setup WebSocket handlers
        self.setup_websocket_handlers()
        
        # Subscribe to events
        self.ws_client.subscribe([
            'flight.created',
            'flight.departed',
            'flight.arrived',
            'flight.position',
            'system.heartbeat',
        ])
        
        print("\nMonitoring fleet... Press Ctrl+C to stop.\n")
        
        try:
            self.ws_client.run()
        except KeyboardInterrupt:
            print("\n\nShutting down...")
            self.save_oooi_log()
    
    def save_oooi_log(self):
        """Save OOOI log to file."""
        if not self.oooi_log:
            return
        
        filename = f"{self.airline_prefix}_oooi_{datetime.utcnow().strftime('%Y%m%d_%H%M%S')}.json"
        with open(filename, 'w') as f:
            json.dump(self.oooi_log, f, indent=2)
        print(f"\n💾 OOOI log saved to: {filename}")


def main():
    if len(sys.argv) < 3:
        print("Usage: python airline_fleet_tracker.py YOUR_API_KEY AIRLINE_PREFIX")
        print("Example: python airline_fleet_tracker.py abc123 DAL")
        sys.exit(1)
    
    api_key = sys.argv[1]
    airline_prefix = sys.argv[2]
    
    tracker = AirlineFleetTracker(api_key, airline_prefix)
    tracker.run()


if __name__ == '__main__':
    main()
