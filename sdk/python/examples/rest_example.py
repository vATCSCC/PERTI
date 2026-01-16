#!/usr/bin/env python3
"""
SWIM REST API Example

Demonstrates using the SWIM REST client for querying flight data.

Requirements:
    pip install swim-client[rest]  # For sync client
    pip install swim-client[all]   # For sync + async
"""

import sys
from swim_client import SWIMRestClient, SWIMAPIError, SWIMAuthError

# Replace with your API key
API_KEY = 'swim_dev_your_key_here'


def basic_example():
    """Basic REST API usage."""
    print("=" * 60)
    print("SWIM REST API - Basic Example")
    print("=" * 60)
    
    client = SWIMRestClient(API_KEY)
    
    try:
        # Get API info
        print("\n1. API Info:")
        info = client.get_api_info()
        print(f"   API: {info.get('data', {}).get('name', 'Unknown')}")
        print(f"   Version: {info.get('data', {}).get('version', 'Unknown')}")
        
        # Get flights to JFK
        print("\n2. Flights to KJFK:")
        result = client.get_flights(dest_icao='KJFK', per_page=5)
        flights = result.get('data', [])
        print(f"   Found {len(flights)} flights (showing first 5)")
        
        for flight in flights:
            identity = flight.get('identity', {})
            plan = flight.get('flight_plan', {})
            progress = flight.get('progress', {})
            
            callsign = identity.get('callsign', 'N/A')
            dep = plan.get('departure', '????')
            arr = plan.get('destination', '????')
            phase = progress.get('phase', 'Unknown')
            
            print(f"   - {callsign}: {dep} -> {arr} ({phase})")
        
        # Get positions in ZNY
        print("\n3. Positions in ZNY:")
        positions = client.get_positions(artcc='ZNY')
        features = positions.get('features', [])
        count = positions.get('metadata', {}).get('count', len(features))
        print(f"   Found {count} aircraft")
        
        for feat in features[:5]:
            props = feat.get('properties', {})
            geom = feat.get('geometry', {})
            coords = geom.get('coordinates', [0, 0, 0])
            
            cs = props.get('callsign', 'N/A')
            alt = props.get('altitude', 0)
            lat, lon = coords[1] if len(coords) > 1 else 0, coords[0] if coords else 0
            
            print(f"   - {cs}: {alt}ft at ({lat:.2f}, {lon:.2f})")
        
        # Get TMI programs
        print("\n4. Active TMI Programs:")
        tmi = client.get_tmi_programs()
        data = tmi.get('data', {})
        
        gs_list = data.get('ground_stops', [])
        gdp_list = data.get('gdp_programs', [])
        
        print(f"   Ground Stops: {len(gs_list)}")
        for gs in gs_list:
            print(f"   - GS at {gs.get('airport')}: {gs.get('reason', 'N/A')}")
        
        print(f"   GDPs: {len(gdp_list)}")
        for gdp in gdp_list:
            avg = gdp.get('average_delay_minutes', 0)
            print(f"   - GDP at {gdp.get('airport')}: {avg}min avg delay")
        
    except SWIMAuthError as e:
        print(f"\nAuth Error: {e}")
        print("Check your API key and permissions.")
    except SWIMAPIError as e:
        print(f"\nAPI Error: {e}")
    finally:
        client.close()


def pagination_example():
    """Demonstrate pagination."""
    print("\n" + "=" * 60)
    print("SWIM REST API - Pagination Example")
    print("=" * 60)
    
    client = SWIMRestClient(API_KEY)
    
    try:
        # Get first page
        print("\n1. First page of results:")
        result = client.get_flights(status='active', per_page=10, page=1)
        
        pagination = result.get('pagination', {})
        total = pagination.get('total', 0)
        pages = pagination.get('total_pages', 1)
        has_more = pagination.get('has_more', False)
        
        print(f"   Total flights: {total}")
        print(f"   Total pages: {pages}")
        print(f"   Has more: {has_more}")
        
        # Iterate all flights
        print("\n2. Iterating all flights (using iter_all_flights):")
        count = 0
        for flight in client.iter_all_flights(status='active', per_page=100):
            count += 1
            if count <= 3:
                cs = flight.get('identity', {}).get('callsign', 'N/A')
                print(f"   - {cs}")
            elif count == 4:
                print("   ...")
        
        print(f"   Total iterated: {count}")
        
    except SWIMAPIError as e:
        print(f"\nError: {e}")
    finally:
        client.close()


def filtering_example():
    """Demonstrate filtering options."""
    print("\n" + "=" * 60)
    print("SWIM REST API - Filtering Example")
    print("=" * 60)
    
    client = SWIMRestClient(API_KEY)
    
    try:
        # Multiple airports
        print("\n1. Flights from NYC area to LA area:")
        result = client.get_flights(
            dept_icao=['KJFK', 'KLGA', 'KEWR'],
            dest_icao=['KLAX', 'KONT', 'KSNA', 'KBUR'],
        )
        flights = result.get('data', [])
        print(f"   Found {len(flights)} flights")
        
        # TMI-controlled flights
        print("\n2. TMI-controlled flights:")
        result = client.get_flights(tmi_controlled=True)
        flights = result.get('data', [])
        print(f"   Found {len(flights)} controlled flights")
        
        # By phase
        print("\n3. Flights in descent:")
        result = client.get_flights(phase='DESCENDING')
        flights = result.get('data', [])
        print(f"   Found {len(flights)} descending")
        
        # By callsign pattern
        print("\n4. United flights (UAL*):")
        result = client.get_flights(callsign='UAL*', per_page=5)
        flights = result.get('data', [])
        print(f"   Found {len(flights)} United flights")
        for f in flights[:5]:
            cs = f.get('identity', {}).get('callsign', 'N/A')
            print(f"   - {cs}")
        
    except SWIMAPIError as e:
        print(f"\nError: {e}")
    finally:
        client.close()


def context_manager_example():
    """Using context manager for automatic cleanup."""
    print("\n" + "=" * 60)
    print("SWIM REST API - Context Manager Example")
    print("=" * 60)
    
    try:
        with SWIMRestClient(API_KEY) as client:
            # Get arrivals (convenience method)
            print("\n1. Arrivals to KJFK:")
            result = client.get_arrivals('KJFK')
            flights = result.get('data', [])
            print(f"   Found {len(flights)} arrivals")
            
            # Get departures (convenience method)
            print("\n2. Departures from KJFK:")
            result = client.get_departures('KJFK')
            flights = result.get('data', [])
            print(f"   Found {len(flights)} departures")
            
            # Get ARTCC traffic (convenience method)
            print("\n3. ZNY traffic:")
            result = client.get_artcc_traffic('ZNY')
            flights = result.get('data', [])
            print(f"   Found {len(flights)} flights in ZNY")
        
        # Session automatically closed here
        print("\n   Session closed automatically")
        
    except SWIMAPIError as e:
        print(f"\nError: {e}")


async def async_example():
    """Async REST API usage."""
    print("\n" + "=" * 60)
    print("SWIM REST API - Async Example")
    print("=" * 60)
    
    try:
        async with SWIMRestClient(API_KEY) as client:
            # Parallel requests
            import asyncio
            
            print("\n1. Parallel API requests:")
            
            flights_task = client.get_flights_async(dest_icao='KJFK', per_page=10)
            positions_task = client.get_positions_async(artcc='ZNY')
            tmi_task = client.get_tmi_programs_async()
            
            flights_result, positions, tmi = await asyncio.gather(
                flights_task,
                positions_task,
                tmi_task,
            )
            
            flights = flights_result.get('data', [])
            features = positions.get('features', [])
            data = tmi.get('data', {})
            
            print(f"   Flights to JFK: {len(flights)}")
            print(f"   Positions in ZNY: {len(features)}")
            print(f"   Ground Stops: {len(data.get('ground_stops', []))}")
            
    except SWIMAPIError as e:
        print(f"\nError: {e}")


def main():
    """Run all examples."""
    basic_example()
    pagination_example()
    filtering_example()
    context_manager_example()
    
    # Run async example if possible
    try:
        import asyncio
        asyncio.run(async_example())
    except ImportError:
        print("\n(Async example skipped - aiohttp not installed)")


if __name__ == '__main__':
    main()
