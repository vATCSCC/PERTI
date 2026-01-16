#!/usr/bin/env python3
"""
Airport Monitor Example

Monitors departures and arrivals at specific airports.

Usage:
    python airport_monitor.py YOUR_API_KEY KJFK KLAX KATL
"""

import sys
from datetime import datetime
from swim_client import SWIMClient

# Statistics
stats = {
    'departures': {},
    'arrivals': {},
}


def main():
    if len(sys.argv) < 3:
        print("Usage: python airport_monitor.py YOUR_API_KEY AIRPORT1 [AIRPORT2 ...]")
        print("Example: python airport_monitor.py your-key KJFK KLAX KATL")
        sys.exit(1)
    
    api_key = sys.argv[1]
    airports = [a.upper() for a in sys.argv[2:]]
    
    # Initialize stats
    for apt in airports:
        stats['departures'][apt] = 0
        stats['arrivals'][apt] = 0
    
    print(f"\n📊 AIRPORT MONITOR")
    print(f"   Watching: {', '.join(airports)}")
    print(f"   Started: {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')} UTC")
    print("-" * 60)
    
    # Create client
    client = SWIMClient(api_key)
    
    @client.on('connected')
    def on_connected(info, timestamp):
        print(f"\n✅ Connected (ID: {info.client_id})\n")
    
    @client.on('flight.departed')
    def on_departure(event, timestamp):
        apt = event.dep
        if apt in airports:
            stats['departures'][apt] += 1
            print(f"🛫 {event.callsign:10} departed {apt} → {event.arr or '????'}")
            print_stats()
    
    @client.on('flight.arrived')
    def on_arrival(event, timestamp):
        apt = event.arr
        if apt in airports:
            stats['arrivals'][apt] += 1
            print(f"🛬 {event.callsign:10} arrived  {event.dep or '????'} → {apt}")
            print_stats()
    
    @client.on('error')
    def on_error(error, timestamp):
        print(f"❌ Error: {error.get('message')}")
    
    def print_stats():
        """Print current statistics."""
        lines = []
        for apt in airports:
            deps = stats['departures'][apt]
            arrs = stats['arrivals'][apt]
            lines.append(f"{apt}: {deps} dep / {arrs} arr")
        print(f"   📈 Stats: {' | '.join(lines)}")
        print()
    
    # Subscribe with airport filter
    client.subscribe(
        channels=['flight.departed', 'flight.arrived'],
        airports=airports
    )
    
    print("Listening for flights... Press Ctrl+C to stop.\n")
    
    try:
        client.run()
    except KeyboardInterrupt:
        print("\n" + "=" * 60)
        print("FINAL STATISTICS")
        print("=" * 60)
        for apt in airports:
            deps = stats['departures'][apt]
            arrs = stats['arrivals'][apt]
            print(f"  {apt}: {deps} departures, {arrs} arrivals")
        print()


if __name__ == '__main__':
    main()
