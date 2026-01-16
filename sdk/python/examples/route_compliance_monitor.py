#!/usr/bin/env python3
"""
Route Compliance Monitor

Monitors flights for compliance with assigned routes and reroutes.
Useful for TMU coordinators tracking reroute adherence.

Features:
- Tracks flights on specified routes
- Detects deviations from assigned routing
- Calculates compliance percentage
- Alerts on non-compliance

Usage:
    python route_compliance_monitor.py YOUR_API_KEY --route "KJFK..MERIT..KLAX"
    python route_compliance_monitor.py YOUR_API_KEY --fix MERIT --dest KLAX

Consumer: TMU Coordinators, Traffic Managers
"""

import sys
import re
import argparse
from datetime import datetime
from typing import Dict, List, Set, Optional, Tuple
from swim_client import SWIMClient
from swim_client.rest import SWIMRestClient


class RouteComplianceMonitor:
    """Monitors flight route compliance."""
    
    def __init__(self, api_key: str):
        self.api_key = api_key
        self.rest_client = SWIMRestClient(api_key)
        self.ws_client = SWIMClient(api_key)
        
        # Tracking state
        self.monitored_flights: Dict[str, dict] = {}  # callsign -> flight info
        self.compliance_status: Dict[str, dict] = {}  # callsign -> compliance data
        
        # Route requirements
        self.required_fixes: List[str] = []
        self.origin_filter: Optional[str] = None
        self.dest_filter: Optional[str] = None
    
    def set_route_requirement(
        self,
        fixes: List[str] = None,
        origin: str = None,
        dest: str = None,
    ):
        """Set the route requirements to monitor."""
        if fixes:
            self.required_fixes = [f.upper() for f in fixes]
        if origin:
            self.origin_filter = origin.upper()
        if dest:
            self.dest_filter = dest.upper()
        
        print(f"\n📋 Route Requirement Set:")
        if self.required_fixes:
            print(f"   Required fixes: {' -> '.join(self.required_fixes)}")
        if self.origin_filter:
            print(f"   Origin: {self.origin_filter}")
        if self.dest_filter:
            print(f"   Destination: {self.dest_filter}")
        print()
    
    def parse_route_string(self, route_str: str) -> List[str]:
        """Extract fixes from route string."""
        if not route_str:
            return []
        
        # Remove common connectors and clean up
        route = route_str.upper()
        route = re.sub(r'\s+', ' ', route)
        route = re.sub(r'\.+', ' ', route)
        route = re.sub(r'DCT', '', route)
        
        # Extract fixes (alphanumeric 2-5 chars)
        fixes = re.findall(r'\b([A-Z]{2,5}[0-9]{0,2})\b', route)
        
        return fixes
    
    def check_compliance(self, callsign: str, route: str) -> dict:
        """Check if route contains required fixes in order."""
        route_fixes = self.parse_route_string(route)
        
        result = {
            'is_compliant': False,
            'required_fixes': self.required_fixes.copy(),
            'found_fixes': [],
            'missing_fixes': [],
            'compliance_pct': 0.0,
        }
        
        if not self.required_fixes:
            result['is_compliant'] = True
            result['compliance_pct'] = 100.0
            return result
        
        # Check each required fix is present in order
        last_idx = -1
        for fix in self.required_fixes:
            try:
                idx = route_fixes.index(fix)
                if idx > last_idx:
                    result['found_fixes'].append(fix)
                    last_idx = idx
                else:
                    result['missing_fixes'].append(fix)
            except ValueError:
                result['missing_fixes'].append(fix)
        
        # Calculate compliance
        if self.required_fixes:
            result['compliance_pct'] = (len(result['found_fixes']) / len(self.required_fixes)) * 100
        
        result['is_compliant'] = len(result['missing_fixes']) == 0
        
        return result
    
    def load_affected_flights(self):
        """Load flights that match the route criteria."""
        print(f"📡 Loading flights matching criteria...")
        
        try:
            params = {'status': 'active', 'per_page': 500}
            
            if self.origin_filter:
                params['dept_icao'] = self.origin_filter
            if self.dest_filter:
                params['dest_icao'] = self.dest_filter
            
            response = self.rest_client.get_flights(**params)
            flights = response.get('data', [])
            
            for flight in flights:
                callsign = flight.get('identity', {}).get('callsign', '')
                route = flight.get('flight_plan', {}).get('route', '')
                
                if not callsign:
                    continue
                
                # Check if flight should be monitored
                if self.required_fixes and not any(f in route.upper() for f in self.required_fixes):
                    continue
                
                self.monitored_flights[callsign] = flight
                self.compliance_status[callsign] = self.check_compliance(callsign, route)
            
            print(f"   Found {len(self.monitored_flights)} flights to monitor")
            
        except Exception as e:
            print(f"❌ Error loading flights: {e}")
    
    def print_compliance_board(self):
        """Display compliance status board."""
        now = datetime.utcnow()
        
        print("\n" + "=" * 80)
        print(f"  ROUTE COMPLIANCE MONITOR - {now.strftime('%H:%M:%S')} UTC")
        if self.required_fixes:
            print(f"  Required: {' -> '.join(self.required_fixes)}")
        print("=" * 80)
        print(f"{'FLIGHT':<10} {'ROUTE':<30} {'COMPLIANCE':>12} {'STATUS':<10}")
        print("-" * 80)
        
        compliant_count = 0
        total_count = len(self.monitored_flights)
        
        for callsign in sorted(self.monitored_flights.keys()):
            flight = self.monitored_flights[callsign]
            compliance = self.compliance_status.get(callsign, {})
            
            plan = flight.get('flight_plan', {})
            dep = plan.get('departure', '????')
            dest = plan.get('destination', '????')
            route_display = f"{dep}-{dest}"
            
            pct = compliance.get('compliance_pct', 0)
            is_compliant = compliance.get('is_compliant', False)
            
            if is_compliant:
                status = "✅ OK"
                compliant_count += 1
            elif pct >= 50:
                status = "⚠️ PARTIAL"
            else:
                status = "❌ NON-COMP"
            
            # Show missing fixes
            missing = compliance.get('missing_fixes', [])
            if missing:
                route_display = f"{route_display} (missing: {','.join(missing)})"
            
            print(f"{callsign:<10} {route_display:<30} {pct:>10.0f}% {status:<10}")
        
        print("-" * 80)
        
        if total_count > 0:
            overall_pct = (compliant_count / total_count) * 100
            print(f"  Overall Compliance: {compliant_count}/{total_count} ({overall_pct:.1f}%)")
        else:
            print("  No flights being monitored")
        print()
    
    def setup_websocket_handlers(self):
        """Configure WebSocket event handlers."""
        
        @self.ws_client.on('connected')
        def on_connected(info, timestamp):
            print(f"✅ Connected to SWIM WebSocket")
        
        @self.ws_client.on('flight.created')
        def on_created(event, timestamp):
            # Check if new flight matches criteria
            if self.dest_filter and hasattr(event, 'arr') and event.arr != self.dest_filter:
                return
            if self.origin_filter and hasattr(event, 'dep') and event.dep != self.origin_filter:
                return
            
            route = event.route if hasattr(event, 'route') else ''
            compliance = self.check_compliance(event.callsign, route)
            
            if not compliance['is_compliant'] and self.required_fixes:
                print(f"\n⚠️  NEW NON-COMPLIANT FLIGHT: {event.callsign}")
                print(f"   Route: {route}")
                print(f"   Missing: {', '.join(compliance['missing_fixes'])}")
        
        @self.ws_client.on('flight.route_change')
        def on_route_change(event, timestamp):
            if event.callsign not in self.monitored_flights:
                return
            
            old_compliance = self.compliance_status.get(event.callsign, {})
            new_compliance = self.check_compliance(event.callsign, event.new_route)
            
            self.compliance_status[event.callsign] = new_compliance
            
            # Alert on compliance change
            was_compliant = old_compliance.get('is_compliant', False)
            now_compliant = new_compliance.get('is_compliant', False)
            
            if was_compliant and not now_compliant:
                print(f"\n❌ COMPLIANCE LOST: {event.callsign}")
                print(f"   New route: {event.new_route}")
                print(f"   Missing: {', '.join(new_compliance['missing_fixes'])}")
            elif not was_compliant and now_compliant:
                print(f"\n✅ NOW COMPLIANT: {event.callsign}")
        
        @self.ws_client.on('system.heartbeat')
        def on_heartbeat(data, timestamp):
            self.print_compliance_board()
    
    def run(self):
        """Start the compliance monitor."""
        print(f"\n{'='*60}")
        print(f"  ROUTE COMPLIANCE MONITOR")
        print(f"  Started: {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')} UTC")
        print(f"{'='*60}")
        
        # Load initial flights
        self.load_affected_flights()
        self.print_compliance_board()
        
        # Setup WebSocket handlers
        self.setup_websocket_handlers()
        
        # Subscribe to events
        self.ws_client.subscribe([
            'flight.created',
            'flight.route_change',
            'system.heartbeat',
        ])
        
        print("Monitoring compliance... Press Ctrl+C to stop.\n")
        
        try:
            self.ws_client.run()
        except KeyboardInterrupt:
            print("\n\nFinal Compliance Status:")
            self.print_compliance_board()


def main():
    parser = argparse.ArgumentParser(
        description='Monitor flight route compliance',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  Monitor flights via specific fix:
    python route_compliance_monitor.py YOUR_KEY --fix MERIT

  Monitor flights on route with multiple fixes:
    python route_compliance_monitor.py YOUR_KEY --route "MERIT..ROBER..ALCOA"

  Monitor with origin/destination filter:
    python route_compliance_monitor.py YOUR_KEY --fix WAVEY --origin KJFK --dest KLAX
        """
    )
    
    parser.add_argument('api_key', help='SWIM API key')
    parser.add_argument('--fix', help='Single required fix')
    parser.add_argument('--route', help='Required route (fixes separated by ..)')
    parser.add_argument('--origin', help='Filter by departure airport')
    parser.add_argument('--dest', help='Filter by destination airport')
    
    args = parser.parse_args()
    
    monitor = RouteComplianceMonitor(args.api_key)
    
    # Parse route requirement
    fixes = []
    if args.fix:
        fixes = [args.fix]
    elif args.route:
        fixes = [f.strip() for f in args.route.split('..') if f.strip()]
    
    if not fixes and not args.origin and not args.dest:
        print("Error: Must specify at least --fix, --route, --origin, or --dest")
        sys.exit(1)
    
    monitor.set_route_requirement(
        fixes=fixes,
        origin=args.origin,
        dest=args.dest,
    )
    
    monitor.run()


if __name__ == '__main__':
    main()
