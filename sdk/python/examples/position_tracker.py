#!/usr/bin/env python3
"""
Position Tracker Example

Tracks real-time position updates for flights.

Usage:
    python position_tracker.py YOUR_API_KEY [CALLSIGN_PREFIX]
"""

import sys
from datetime import datetime
from typing import Dict
from swim_client import SWIMClient, PositionBatch

# Track latest positions
positions: Dict[str, dict] = {}


def main():
    if len(sys.argv) < 2:
        print("Usage: python position_tracker.py YOUR_API_KEY [CALLSIGN_PREFIX]")
        print("Example: python position_tracker.py your-key AAL")
        sys.exit(1)
    
    api_key = sys.argv[1]
    callsign_filter = sys.argv[2].upper() if len(sys.argv) > 2 else None
    
    print(f"\n📍 POSITION TRACKER")
    if callsign_filter:
        print(f"   Filtering: {callsign_filter}*")
    else:
        print(f"   Tracking: All flights")
    print(f"   Started: {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')} UTC")
    print("-" * 70)
    
    client = SWIMClient(api_key)
    
    @client.on('connected')
    def on_connected(info, timestamp):
        print(f"✅ Connected\n")
    
    @client.on('flight.positions')
    def on_positions(batch: PositionBatch, timestamp):
        updated = 0
        
        for pos in batch.positions:
            # Apply callsign filter if set
            if callsign_filter and not pos.callsign.startswith(callsign_filter):
                continue
            
            # Store position
            prev = positions.get(pos.callsign)
            positions[pos.callsign] = {
                'lat': pos.latitude,
                'lon': pos.longitude,
                'alt': pos.altitude_ft,
                'gs': pos.groundspeed_kts,
                'hdg': pos.heading_deg,
                'vs': pos.vertical_rate_fpm,
                'dep': pos.dep,
                'arr': pos.arr,
                'artcc': pos.current_artcc,
            }
            
            # Show update if position changed significantly
            if prev is None or abs(prev['alt'] - pos.altitude_ft) > 100:
                phase = get_flight_phase(pos.vertical_rate_fpm, pos.altitude_ft)
                print(
                    f"{pos.callsign:10} "
                    f"{pos.latitude:8.4f}, {pos.longitude:9.4f} "
                    f"FL{pos.altitude_ft // 100:03d} "
                    f"{pos.groundspeed_kts:3d}kt "
                    f"hdg {pos.heading_deg:03d}° "
                    f"{phase:8} "
                    f"{pos.dep or '????'} → {pos.arr or '????'}"
                )
                updated += 1
        
        if updated > 0:
            print(f"   [{timestamp}] Updated {updated} / {batch.count} positions | Tracking {len(positions)} flights\n")
    
    @client.on('flight.deleted')
    def on_deleted(event, timestamp):
        if event.callsign in positions:
            del positions[event.callsign]
            print(f"❌ {event.callsign} disconnected | Tracking {len(positions)} flights\n")
    
    def get_flight_phase(vs: int, alt: int) -> str:
        """Determine flight phase from vertical speed and altitude."""
        if vs > 500:
            return "CLIMBING"
        elif vs < -500:
            return "DESCENT"
        elif alt < 10000:
            return "LOW"
        else:
            return "CRUISE"
    
    # Subscribe to position updates
    sub_args = {
        'channels': ['flight.positions', 'flight.deleted'],
    }
    if callsign_filter:
        sub_args['callsign_prefix'] = [callsign_filter]
    
    client.subscribe(**sub_args)
    
    print("Tracking positions... Press Ctrl+C to stop.\n")
    
    try:
        client.run()
    except KeyboardInterrupt:
        print(f"\n\nTracked {len(positions)} unique flights")


if __name__ == '__main__':
    main()
