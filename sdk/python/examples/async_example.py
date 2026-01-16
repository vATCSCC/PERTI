#!/usr/bin/env python3
"""
SWIM Async API Example

Demonstrates using async clients for both REST and WebSocket.
Requires: pip install aiohttp
"""

import asyncio
from swim_client import (
    AsyncSWIMRestClient,
    SWIMClient,
    SWIMRestError,
)

# Replace with your API key
API_KEY = 'swim_dev_your_key_here'


async def rest_example():
    """Async REST API example."""
    print("=" * 60)
    print("Async REST API Example")
    print("=" * 60)
    
    async with AsyncSWIMRestClient(API_KEY, debug=True) as client:
        # Parallel requests
        print("\n1. Parallel API Requests:")
        
        flights_task = client.get_flights(dest_icao='KJFK', per_page=10)
        positions_task = client.get_positions(artcc='ZNY')
        tmi_task = client.get_tmi_programs()
        
        # Wait for all
        flights, positions, tmi = await asyncio.gather(
            flights_task,
            positions_task,
            tmi_task,
        )
        
        print(f"   Flights to JFK: {len(flights)}")
        print(f"   Positions in ZNY: {positions.count}")
        print(f"   Active Ground Stops: {tmi.active_ground_stops}")
        print(f"   Active GDPs: {tmi.active_gdp_programs}")
        
        # Sequential with filtering
        print("\n2. Sequential Queries:")
        
        # Get flights departing from NYC area
        nyc_departures = await client.get_flights(
            dept_icao=['KJFK', 'KLGA', 'KEWR'],
            status='active',
        )
        print(f"   NYC departures: {len(nyc_departures)}")
        
        # Get arrivals to LA
        la_arrivals = await client.get_flights(
            dest_icao=['KLAX', 'KONT', 'KSNA', 'KBUR'],
            phase='DESCENDING',
        )
        print(f"   LA area descending: {len(la_arrivals)}")


async def websocket_example():
    """Async WebSocket example."""
    print("\n" + "=" * 60)
    print("Async WebSocket Example")
    print("=" * 60)
    
    client = SWIMClient(API_KEY, debug=True)
    
    event_count = {'departures': 0, 'arrivals': 0}
    
    @client.on('connected')
    def on_connected(info, timestamp):
        print(f"\nConnected! Client ID: {info.client_id}")
        print(f"Server time: {info.server_time}")
    
    @client.on('flight.departed')
    def on_departure(event, timestamp):
        event_count['departures'] += 1
        print(f"[DEP] {event.callsign}: {event.dep} -> {event.arr}")
    
    @client.on('flight.arrived')
    def on_arrival(event, timestamp):
        event_count['arrivals'] += 1
        print(f"[ARR] {event.callsign}: {event.dep} -> {event.arr}")
    
    @client.on('system.heartbeat')
    def on_heartbeat(data, timestamp):
        print(f"[HB] {data.connected_clients} clients, "
              f"uptime {data.uptime_seconds}s | "
              f"DEP: {event_count['departures']}, ARR: {event_count['arrivals']}")
    
    @client.on('error')
    def on_error(error, timestamp):
        print(f"[ERR] {error.get('code')}: {error.get('message')}")
    
    # Subscribe to events
    client.subscribe(
        channels=['flight.departed', 'flight.arrived', 'system.heartbeat'],
        airports=['KJFK', 'KLAX', 'KORD', 'KATL'],
    )
    
    print("\nListening for events (Ctrl+C to stop)...")
    
    # Run for 60 seconds or until interrupted
    try:
        await asyncio.wait_for(client.run_async(), timeout=60)
    except asyncio.TimeoutError:
        print("\n60 second timeout reached")
    except KeyboardInterrupt:
        print("\nInterrupted by user")
    finally:
        await client.disconnect()
        print(f"\nFinal counts: {event_count}")


async def combined_example():
    """Combined REST + WebSocket example."""
    print("\n" + "=" * 60)
    print("Combined REST + WebSocket Example")
    print("=" * 60)
    
    # Use REST to get current state
    async with AsyncSWIMRestClient(API_KEY) as rest:
        # Get current flights to monitor
        flights = await rest.get_flights(dest_icao='KJFK', per_page=20)
        callsigns = [f.callsign for f in flights]
        print(f"\nTracking {len(callsigns)} flights to KJFK")
    
    # Use WebSocket to track updates
    ws = SWIMClient(API_KEY)
    
    tracked = set(callsigns)
    
    @ws.on('flight.arrived')
    def on_arrival(event, timestamp):
        if event.callsign in tracked:
            print(f"✓ {event.callsign} arrived at KJFK!")
            tracked.remove(event.callsign)
            if not tracked:
                print("All tracked flights have arrived!")
    
    @ws.on('flight.positions')
    def on_positions(batch, timestamp):
        for pos in batch.positions:
            if pos.callsign in tracked and pos.arr == 'KJFK':
                print(f"  {pos.callsign}: {pos.altitude_ft}ft, "
                      f"{pos.groundspeed_kts}kts")
    
    ws.subscribe(
        channels=['flight.arrived', 'flight.positions'],
        airports=['KJFK'],
    )
    
    print("Monitoring arrivals (Ctrl+C to stop)...")
    
    try:
        await asyncio.wait_for(ws.run_async(), timeout=120)
    except (asyncio.TimeoutError, KeyboardInterrupt):
        pass
    finally:
        await ws.disconnect()


async def main():
    """Run all async examples."""
    try:
        await rest_example()
        # Uncomment to run WebSocket example:
        # await websocket_example()
        # Uncomment to run combined example:
        # await combined_example()
    except SWIMRestError as e:
        print(f"\nAPI Error: [{e.status_code}] {e.code}: {e.message}")
    except ImportError as e:
        print(f"\nMissing dependency: {e}")
        print("Install with: pip install aiohttp")
    except Exception as e:
        print(f"\nError: {e}")


if __name__ == '__main__':
    asyncio.run(main())
