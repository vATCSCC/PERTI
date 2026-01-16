#!/usr/bin/env python3
"""
TMI Monitor Example

Monitors Traffic Management Initiatives (Ground Stops, GDPs, etc.)

Usage:
    python tmi_monitor.py YOUR_API_KEY
"""

import sys
from datetime import datetime
from swim_client import SWIMClient, TMIEvent

# Track active TMIs
active_tmis = {}


def main():
    if len(sys.argv) < 2:
        print("Usage: python tmi_monitor.py YOUR_API_KEY")
        sys.exit(1)
    
    api_key = sys.argv[1]
    
    print(f"\n🚦 TMI MONITOR")
    print(f"   Monitoring: Ground Stops, GDPs, AFPs, MITs")
    print(f"   Started: {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')} UTC")
    print("-" * 60)
    
    client = SWIMClient(api_key)
    
    @client.on('connected')
    def on_connected(info, timestamp):
        print(f"✅ Connected\n")
        print("Waiting for TMI events...\n")
    
    @client.on('tmi.issued')
    def on_tmi_issued(event: TMIEvent, timestamp):
        # Store active TMI
        active_tmis[event.program_id] = event
        
        icon = get_tmi_icon(event.program_type)
        print(f"{icon} TMI ISSUED: {event.program_type}")
        print(f"   Program ID: {event.program_id}")
        print(f"   Airport: {event.airport}")
        print(f"   Start: {event.start_time}")
        print(f"   End: {event.end_time}")
        if event.reason:
            print(f"   Reason: {event.reason}")
        print(f"   Active TMIs: {len(active_tmis)}")
        print()
    
    @client.on('tmi.modified')
    def on_tmi_modified(event: TMIEvent, timestamp):
        icon = get_tmi_icon(event.program_type)
        print(f"📝 TMI MODIFIED: {event.program_type}")
        print(f"   Program ID: {event.program_id}")
        print(f"   Airport: {event.airport}")
        print(f"   New End: {event.end_time}")
        print()
        
        # Update stored TMI
        if event.program_id in active_tmis:
            active_tmis[event.program_id] = event
    
    @client.on('tmi.released')
    def on_tmi_released(event: TMIEvent, timestamp):
        # Remove from active
        if event.program_id in active_tmis:
            del active_tmis[event.program_id]
        
        icon = get_tmi_icon(event.program_type)
        print(f"✅ TMI RELEASED: {event.program_type}")
        print(f"   Program ID: {event.program_id}")
        print(f"   Airport: {event.airport}")
        print(f"   Status: {event.status}")
        print(f"   Active TMIs: {len(active_tmis)}")
        print()
    
    @client.on('system.heartbeat')
    def on_heartbeat(data, timestamp):
        if active_tmis:
            print(f"💓 Heartbeat | Active TMIs: {len(active_tmis)}")
            for pid, tmi in active_tmis.items():
                print(f"      • {tmi.program_type} @ {tmi.airport}")
            print()
    
    def get_tmi_icon(program_type: str) -> str:
        """Get emoji icon for TMI type."""
        icons = {
            'GROUND_STOP': '🛑',
            'GS': '🛑',
            'GDP': '⏱️',
            'GROUND_DELAY': '⏱️',
            'AFP': '🔄',
            'AIRSPACE_FLOW': '🔄',
            'MIT': '📏',
            'MILES_IN_TRAIL': '📏',
            'MINIT': '📏',
            'REROUTE': '↪️',
        }
        return icons.get(program_type.upper(), '🚦')
    
    # Subscribe to all TMI events
    client.subscribe(['tmi.*', 'system.heartbeat'])
    
    print("Monitoring TMIs... Press Ctrl+C to stop.\n")
    
    try:
        client.run()
    except KeyboardInterrupt:
        print("\n" + "=" * 60)
        print("ACTIVE TMIs AT EXIT")
        print("=" * 60)
        if active_tmis:
            for pid, tmi in active_tmis.items():
                print(f"  {tmi.program_type} @ {tmi.airport} (ID: {pid})")
        else:
            print("  No active TMIs")
        print()


if __name__ == '__main__':
    main()
