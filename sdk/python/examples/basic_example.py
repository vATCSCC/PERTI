#!/usr/bin/env python3
"""
Basic SWIM Client Example

Demonstrates connecting and receiving flight events.

Usage:
    python basic_example.py YOUR_API_KEY
"""

import sys
from swim_client import SWIMClient

def main():
    if len(sys.argv) < 2:
        print("Usage: python basic_example.py YOUR_API_KEY")
        sys.exit(1)
    
    api_key = sys.argv[1]
    
    # Create client
    client = SWIMClient(api_key, debug=True)
    
    # Handle connection
    @client.on('connected')
    def on_connected(info, timestamp):
        print(f"\n✅ Connected to SWIM API")
        print(f"   Client ID: {info.client_id}")
        print(f"   Server time: {info.server_time}")
        print(f"   API version: {info.version}\n")
    
    # Handle departures
    @client.on('flight.departed')
    def on_departure(event, timestamp):
        print(f"🛫 DEPARTURE: {event.callsign}")
        print(f"   From: {event.dep} → To: {event.arr}")
        print(f"   Time: {event.off_utc}")
        print()
    
    # Handle arrivals
    @client.on('flight.arrived')
    def on_arrival(event, timestamp):
        print(f"🛬 ARRIVAL: {event.callsign}")
        print(f"   From: {event.dep} → At: {event.arr}")
        print(f"   Time: {event.in_utc}")
        print()
    
    # Handle new flights
    @client.on('flight.created')
    def on_created(event, timestamp):
        print(f"✈️  NEW FLIGHT: {event.callsign}")
        print(f"   Route: {event.dep} → {event.arr}")
        print(f"   Equipment: {event.equipment}")
        print()
    
    # Handle heartbeats
    @client.on('system.heartbeat')
    def on_heartbeat(data, timestamp):
        print(f"💓 Heartbeat: {data.connected_clients} clients connected")
    
    # Handle errors
    @client.on('error')
    def on_error(error, timestamp):
        print(f"❌ Error: {error.get('code')} - {error.get('message')}")
    
    # Subscribe to flight events
    client.subscribe([
        'flight.created',
        'flight.departed', 
        'flight.arrived',
        'system.heartbeat',
    ])
    
    print("Starting SWIM client... Press Ctrl+C to stop.\n")
    
    # Run (blocking)
    try:
        client.run()
    except KeyboardInterrupt:
        print("\nStopped by user")


if __name__ == '__main__':
    main()
