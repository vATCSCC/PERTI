#!/usr/bin/env python3
"""
Sector Traffic Monitor

Real-time traffic monitoring for ARTCC sectors.
Displays traffic counts, flow rates, and alerts for high-traffic conditions.

Features:
- Monitors traffic in specified ARTCC(s)
- Tracks sector entry/exit events
- Calculates hourly flow rates
- Alerts on traffic thresholds

Usage:
    python sector_traffic_monitor.py YOUR_API_KEY ZNY
    python sector_traffic_monitor.py YOUR_API_KEY ZNY,ZDC,ZBW

Consumer: vNAS, ARTCC Traffic Managers
"""

import sys
from datetime import datetime, timedelta
from collections import defaultdict
from typing import Dict, List, Set
from swim_client import SWIMClient
from swim_client.rest import SWIMRestClient


class SectorTrafficMonitor:
    """Monitors traffic for ARTCC sectors."""
    
    # Alert thresholds
    HIGH_TRAFFIC_THRESHOLD = 50  # Flights in sector
    SURGE_RATE_THRESHOLD = 15    # Entries per 15 minutes
    
    def __init__(self, api_key: str, artccs: List[str]):
        self.api_key = api_key
        self.artccs = [a.upper() for a in artccs]
        
        # Traffic state
        self.current_traffic: Dict[str, Set[str]] = defaultdict(set)  # ARTCC -> callsigns
        self.entry_times: Dict[str, List[datetime]] = defaultdict(list)  # ARTCC -> entry timestamps
        self.exit_times: Dict[str, List[datetime]] = defaultdict(list)   # ARTCC -> exit timestamps
        
        # Statistics
        self.hourly_entries: Dict[str, int] = defaultdict(int)
        self.hourly_exits: Dict[str, int] = defaultdict(int)
        self.peak_traffic: Dict[str, int] = defaultdict(int)
        
        # Clients
        self.rest_client = SWIMRestClient(api_key)
        self.ws_client = SWIMClient(api_key)
    
    def load_initial_traffic(self):
        """Load current traffic counts via REST API."""
        print(f"\n📡 Loading current traffic for: {', '.join(self.artccs)}")
        
        try:
            for artcc in self.artccs:
                response = self.rest_client.get_flights(
                    artcc=artcc,
                    status='active',
                    per_page=1000,
                )
                
                flights = response.get('data', [])
                for flight in flights:
                    callsign = flight.get('identity', {}).get('callsign', '')
                    if callsign:
                        self.current_traffic[artcc].add(callsign)
                
                count = len(self.current_traffic[artcc])
                self.peak_traffic[artcc] = count
                print(f"   {artcc}: {count} flights")
        
        except Exception as e:
            print(f"❌ Error loading traffic: {e}")
    
    def get_flow_rate(self, artcc: str, window_minutes: int = 15) -> tuple:
        """Calculate entry/exit flow rate for time window."""
        cutoff = datetime.utcnow() - timedelta(minutes=window_minutes)
        
        entries = sum(1 for t in self.entry_times[artcc] if t > cutoff)
        exits = sum(1 for t in self.exit_times[artcc] if t > cutoff)
        
        return entries, exits
    
    def check_alerts(self, artcc: str):
        """Check for alert conditions."""
        traffic_count = len(self.current_traffic[artcc])
        entry_rate, _ = self.get_flow_rate(artcc, 15)
        
        alerts = []
        
        if traffic_count >= self.HIGH_TRAFFIC_THRESHOLD:
            alerts.append(f"⚠️  HIGH TRAFFIC: {artcc} has {traffic_count} flights")
        
        if entry_rate >= self.SURGE_RATE_THRESHOLD:
            alerts.append(f"⚠️  TRAFFIC SURGE: {artcc} - {entry_rate} entries in last 15 min")
        
        for alert in alerts:
            print(f"\n{alert}")
    
    def print_traffic_board(self):
        """Display current traffic status."""
        now = datetime.utcnow()
        
        print("\n" + "=" * 70)
        print(f"  SECTOR TRAFFIC MONITOR - {now.strftime('%H:%M:%S')} UTC")
        print("=" * 70)
        print(f"{'ARTCC':<8} {'TRAFFIC':>10} {'PEAK':>8} {'ENTRIES':>10} {'EXITS':>10} {'NET':>8}")
        print(f"{'':8} {'(current)':>10} {'(today)':>8} {'(/15min)':>10} {'(/15min)':>10}")
        print("-" * 70)
        
        for artcc in sorted(self.artccs):
            count = len(self.current_traffic[artcc])
            peak = self.peak_traffic[artcc]
            entries, exits = self.get_flow_rate(artcc, 15)
            net = entries - exits
            
            # Color coding (using emoji as indicators)
            if count >= self.HIGH_TRAFFIC_THRESHOLD:
                status = "🔴"
            elif count >= self.HIGH_TRAFFIC_THRESHOLD * 0.8:
                status = "🟡"
            else:
                status = "🟢"
            
            net_str = f"+{net}" if net >= 0 else str(net)
            
            print(f"{status} {artcc:<6} {count:>10} {peak:>8} {entries:>10} {exits:>10} {net_str:>8}")
        
        print("-" * 70)
        
        # Total
        total_traffic = sum(len(self.current_traffic[a]) for a in self.artccs)
        total_entries = sum(self.get_flow_rate(a, 15)[0] for a in self.artccs)
        total_exits = sum(self.get_flow_rate(a, 15)[1] for a in self.artccs)
        
        print(f"  {'TOTAL':<6} {total_traffic:>10} {'-':>8} {total_entries:>10} {total_exits:>10}")
        print()
    
    def print_top_routes(self, artcc: str, limit: int = 5):
        """Display top routes through sector."""
        if artcc not in self.current_traffic:
            return
        
        # This would require storing route info - simplified version
        print(f"\n📊 Top {artcc} Traffic Flows:")
        print("   (Requires flight detail tracking for full implementation)")
    
    def setup_websocket_handlers(self):
        """Configure WebSocket event handlers."""
        
        @self.ws_client.on('connected')
        def on_connected(info, timestamp):
            print(f"\n✅ Connected to SWIM WebSocket")
            print(f"   Monitoring: {', '.join(self.artccs)}")
        
        @self.ws_client.on('flight.sector_entry')
        def on_sector_entry(event, timestamp):
            artcc = event.artcc.upper() if hasattr(event, 'artcc') else None
            
            if artcc in self.artccs:
                callsign = event.callsign
                self.current_traffic[artcc].add(callsign)
                self.entry_times[artcc].append(datetime.utcnow())
                self.hourly_entries[artcc] += 1
                
                # Update peak
                count = len(self.current_traffic[artcc])
                if count > self.peak_traffic[artcc]:
                    self.peak_traffic[artcc] = count
                
                print(f"   → {callsign} entered {artcc} (total: {count})")
                self.check_alerts(artcc)
        
        @self.ws_client.on('flight.sector_exit')
        def on_sector_exit(event, timestamp):
            artcc = event.artcc.upper() if hasattr(event, 'artcc') else None
            
            if artcc in self.artccs:
                callsign = event.callsign
                self.current_traffic[artcc].discard(callsign)
                self.exit_times[artcc].append(datetime.utcnow())
                self.hourly_exits[artcc] += 1
                
                count = len(self.current_traffic[artcc])
                print(f"   ← {callsign} exited {artcc} (total: {count})")
        
        @self.ws_client.on('flight.arrived')
        def on_arrived(event, timestamp):
            # Remove from all ARTCCs on arrival
            for artcc in self.artccs:
                if event.callsign in self.current_traffic[artcc]:
                    self.current_traffic[artcc].discard(event.callsign)
                    self.exit_times[artcc].append(datetime.utcnow())
        
        @self.ws_client.on('system.heartbeat')
        def on_heartbeat(data, timestamp):
            # Clean up old timestamps (older than 1 hour)
            cutoff = datetime.utcnow() - timedelta(hours=1)
            for artcc in self.artccs:
                self.entry_times[artcc] = [t for t in self.entry_times[artcc] if t > cutoff]
                self.exit_times[artcc] = [t for t in self.exit_times[artcc] if t > cutoff]
            
            # Refresh display
            self.print_traffic_board()
    
    def run(self):
        """Start the traffic monitor."""
        print(f"\n{'='*60}")
        print(f"  SECTOR TRAFFIC MONITOR")
        print(f"  ARTCCs: {', '.join(self.artccs)}")
        print(f"  Started: {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')} UTC")
        print(f"{'='*60}")
        
        # Load initial traffic
        self.load_initial_traffic()
        self.print_traffic_board()
        
        # Setup WebSocket handlers
        self.setup_websocket_handlers()
        
        # Subscribe to events
        self.ws_client.subscribe([
            'flight.sector_entry',
            'flight.sector_exit',
            'flight.arrived',
            'system.heartbeat',
        ])
        
        print("\nMonitoring traffic... Press Ctrl+C to stop.\n")
        
        try:
            self.ws_client.run()
        except KeyboardInterrupt:
            print("\n\nFinal Statistics:")
            self.print_traffic_board()


def main():
    if len(sys.argv) < 3:
        print("Usage: python sector_traffic_monitor.py YOUR_API_KEY ARTCC1,ARTCC2,...")
        print("Example: python sector_traffic_monitor.py abc123 ZNY,ZDC")
        sys.exit(1)
    
    api_key = sys.argv[1]
    artccs = [a.strip() for a in sys.argv[2].split(',')]
    
    monitor = SectorTrafficMonitor(api_key, artccs)
    monitor.run()


if __name__ == '__main__':
    main()
