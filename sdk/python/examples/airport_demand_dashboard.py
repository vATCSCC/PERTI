#!/usr/bin/env python3
"""
Airport Demand Dashboard

Real-time arrival/departure demand monitoring for airports.
Displays traffic counts in 15-minute buckets, similar to FAA FSM demand tools.

Features:
- Hourly demand timeline with 15-minute granularity
- Arrival vs Departure split
- Peak demand identification
- TMI status overlay
- Exportable demand data

Usage:
    python airport_demand_dashboard.py YOUR_API_KEY KJFK
    python airport_demand_dashboard.py YOUR_API_KEY KJFK,KEWR,KLGA

Consumer: TMU Coordinators, Facility Traffic Managers
"""

import sys
import json
from datetime import datetime, timedelta
from collections import defaultdict
from typing import Dict, List, Tuple
from swim_client.rest import SWIMRestClient
from swim_client.models import Flight, TMIPrograms


class AirportDemandDashboard:
    """Real-time airport demand monitoring."""
    
    def __init__(self, api_key: str, airports: List[str]):
        self.api_key = api_key
        self.airports = [a.upper() for a in airports]
        self.client = SWIMRestClient(api_key)
        
        # Demand buckets: airport -> hour -> quarter -> {'arr': N, 'dep': N}
        self.demand: Dict[str, Dict[int, Dict[int, Dict[str, int]]]] = defaultdict(
            lambda: defaultdict(lambda: defaultdict(lambda: {'arr': 0, 'dep': 0}))
        )
        
        # Flight tracking
        self.flights_by_airport: Dict[str, List[dict]] = defaultdict(list)
        
        # TMI status
        self.tmi_status: Dict[str, dict] = {}
    
    def load_demand(self, hours_ahead: int = 6):
        """Load demand for all airports."""
        print(f"\n📡 Loading demand for: {', '.join(self.airports)}")
        print(f"   Time window: Now + {hours_ahead} hours")
        
        try:
            for airport in self.airports:
                self._load_airport_demand(airport, hours_ahead)
                print(f"   ✓ {airport} loaded")
            
            # Load TMI status
            self._load_tmi_status()
            
        except Exception as e:
            print(f"❌ Error loading demand: {e}")
    
    def _load_airport_demand(self, airport: str, hours_ahead: int):
        """Load demand for single airport."""
        now = datetime.utcnow()
        
        # Get arrivals
        arr_response = self.client.get_arrivals(airport, status='active', per_page=500)
        arrivals = arr_response.get('data', [])
        
        # Get departures
        dep_response = self.client.get_departures(airport, status='active', per_page=500)
        departures = dep_response.get('data', [])
        
        # Store flights
        self.flights_by_airport[airport] = arrivals + departures
        
        # Bucket arrivals by ETA
        for flight in arrivals:
            eta_str = flight.get('times', {}).get('eta')
            if eta_str:
                try:
                    eta = datetime.fromisoformat(eta_str.replace('Z', '+00:00'))
                    if now <= eta <= now + timedelta(hours=hours_ahead):
                        hour = eta.hour
                        quarter = eta.minute // 15
                        self.demand[airport][hour][quarter]['arr'] += 1
                except (ValueError, TypeError):
                    pass
        
        # Bucket departures by ETD/scheduled
        for flight in departures:
            etd_str = flight.get('times', {}).get('eta')  # ETD for preflight
            if etd_str:
                try:
                    etd = datetime.fromisoformat(etd_str.replace('Z', '+00:00'))
                    if now <= etd <= now + timedelta(hours=hours_ahead):
                        hour = etd.hour
                        quarter = etd.minute // 15
                        self.demand[airport][hour][quarter]['dep'] += 1
                except (ValueError, TypeError):
                    pass
    
    def _load_tmi_status(self):
        """Load TMI status for airports."""
        try:
            tmi_response = self.client.get_tmi_programs(type='all')
            
            # Ground stops
            for gs in tmi_response.get('ground_stops', []):
                if gs.get('airport') in self.airports:
                    self.tmi_status[gs['airport']] = {
                        'type': 'GS',
                        'reason': gs.get('reason'),
                        'end_time': gs.get('end_time'),
                    }
            
            # GDPs
            for gdp in tmi_response.get('gdp_programs', []):
                if gdp.get('airport') in self.airports:
                    if gdp['airport'] not in self.tmi_status:  # GS takes precedence
                        self.tmi_status[gdp['airport']] = {
                            'type': 'GDP',
                            'reason': gdp.get('reason'),
                            'rate': gdp.get('program_rate'),
                            'avg_delay': gdp.get('average_delay_minutes'),
                        }
        
        except Exception as e:
            print(f"   Warning: Could not load TMI status: {e}")
    
    def print_dashboard(self):
        """Display demand dashboard for all airports."""
        now = datetime.utcnow()
        
        for airport in self.airports:
            self._print_airport_dashboard(airport, now)
    
    def _print_airport_dashboard(self, airport: str, now: datetime):
        """Display demand dashboard for single airport."""
        print("\n" + "=" * 80)
        print(f"  {airport} DEMAND DASHBOARD - {now.strftime('%Y-%m-%d %H:%M')} UTC")
        
        # TMI Status banner
        if airport in self.tmi_status:
            tmi = self.tmi_status[airport]
            if tmi['type'] == 'GS':
                print(f"  🛑 GROUND STOP - {tmi.get('reason', 'Unknown')} - Until {tmi.get('end_time', 'TBD')}")
            else:
                print(f"  ⏱️  GDP ACTIVE - Rate: {tmi.get('rate')}/hr - Avg Delay: {tmi.get('avg_delay')}min")
        
        print("=" * 80)
        
        # Time header
        print(f"\n{'Hour':<6}", end='')
        for q in range(4):
            print(f" :{'%02d' % (q*15):<5}", end='')
        print(f"  {'TOTAL':>6}")
        
        print("-" * 80)
        
        # Build hour rows
        current_hour = now.hour
        peak_arr = 0
        peak_dep = 0
        peak_hour = current_hour
        
        for h_offset in range(6):  # Next 6 hours
            hour = (current_hour + h_offset) % 24
            hour_arr = 0
            hour_dep = 0
            
            print(f"{hour:02d}:00 ", end='')
            
            for quarter in range(4):
                arr = self.demand[airport][hour][quarter]['arr']
                dep = self.demand[airport][hour][quarter]['dep']
                hour_arr += arr
                hour_dep += dep
                
                # Format: ARR/DEP
                cell = f"{arr}/{dep}"
                
                # Highlight high demand
                if arr + dep >= 10:
                    print(f" {cell:>6}!", end='')
                else:
                    print(f" {cell:>6} ", end='')
            
            total = hour_arr + hour_dep
            print(f"  {hour_arr:>2}/{hour_dep:<2} = {total}")
            
            if total > peak_arr + peak_dep:
                peak_arr = hour_arr
                peak_dep = hour_dep
                peak_hour = hour
        
        print("-" * 80)
        
        # Summary
        total_arr = sum(
            self.demand[airport][h][q]['arr']
            for h in self.demand[airport]
            for q in self.demand[airport][h]
        )
        total_dep = sum(
            self.demand[airport][h][q]['dep']
            for h in self.demand[airport]
            for q in self.demand[airport][h]
        )
        
        print(f"\n  📊 SUMMARY:")
        print(f"     Total Arrivals:   {total_arr}")
        print(f"     Total Departures: {total_dep}")
        print(f"     Peak Hour:        {peak_hour:02d}:00 ({peak_arr} arr / {peak_dep} dep)")
        print()
    
    def print_demand_chart(self, airport: str):
        """Print ASCII bar chart of demand."""
        now = datetime.utcnow()
        current_hour = now.hour
        
        print(f"\n📈 {airport} Demand Chart (Arrivals)")
        print("-" * 50)
        
        for h_offset in range(6):
            hour = (current_hour + h_offset) % 24
            total_arr = sum(self.demand[airport][hour][q]['arr'] for q in range(4))
            
            bar = '█' * min(total_arr, 40)
            print(f"{hour:02d}:00 |{bar:<40}| {total_arr}")
        
        print()
    
    def get_demand_data(self) -> dict:
        """Export demand data as dictionary."""
        export = {
            'generated_at': datetime.utcnow().isoformat(),
            'airports': {}
        }
        
        for airport in self.airports:
            export['airports'][airport] = {
                'demand': {},
                'tmi': self.tmi_status.get(airport),
            }
            
            for hour in sorted(self.demand[airport].keys()):
                hour_key = f"{hour:02d}:00"
                export['airports'][airport]['demand'][hour_key] = {}
                
                for quarter in range(4):
                    q_key = f":{quarter * 15:02d}"
                    export['airports'][airport]['demand'][hour_key][q_key] = {
                        'arrivals': self.demand[airport][hour][quarter]['arr'],
                        'departures': self.demand[airport][hour][quarter]['dep'],
                    }
        
        return export
    
    def export_to_json(self, filename: str):
        """Export demand data to JSON file."""
        data = self.get_demand_data()
        with open(filename, 'w') as f:
            json.dump(data, f, indent=2)
        print(f"\n💾 Demand data exported to: {filename}")
    
    def run(self, export_file: str = None):
        """Run the dashboard."""
        print(f"\n{'='*60}")
        print(f"  AIRPORT DEMAND DASHBOARD")
        print(f"  Airports: {', '.join(self.airports)}")
        print(f"  Time: {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')} UTC")
        print(f"{'='*60}")
        
        # Load demand data
        self.load_demand(hours_ahead=6)
        
        # Display dashboard
        self.print_dashboard()
        
        # Show charts
        for airport in self.airports:
            self.print_demand_chart(airport)
        
        # Export if requested
        if export_file:
            self.export_to_json(export_file)


def main():
    if len(sys.argv) < 3:
        print("Usage: python airport_demand_dashboard.py YOUR_API_KEY AIRPORT1,AIRPORT2,...")
        print("       python airport_demand_dashboard.py YOUR_API_KEY KJFK --export demand.json")
        sys.exit(1)
    
    api_key = sys.argv[1]
    airports = [a.strip() for a in sys.argv[2].split(',')]
    
    export_file = None
    if '--export' in sys.argv:
        idx = sys.argv.index('--export')
        if idx + 1 < len(sys.argv):
            export_file = sys.argv[idx + 1]
    
    dashboard = AirportDemandDashboard(api_key, airports)
    dashboard.run(export_file=export_file)


if __name__ == '__main__':
    main()
