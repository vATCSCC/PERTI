#!/usr/bin/env python3
"""
SWIM Webhook Receiver

FastAPI server that receives webhook events from SWIM API.
Useful for integrating SWIM data into external systems.

Features:
- Receives real-time flight events via webhooks
- Signature verification for security
- Event logging and filtering
- Forwarding to downstream systems

Usage:
    pip install fastapi uvicorn
    python webhook_receiver.py --port 8080 --secret YOUR_WEBHOOK_SECRET

    Then configure your SWIM webhook to point to:
    http://your-server:8080/webhook/swim

Consumer: Integration Developers, External Systems
"""

import sys
import hmac
import hashlib
import json
import logging
import argparse
from datetime import datetime
from typing import Optional, Dict, Any, List
from collections import deque

try:
    from fastapi import FastAPI, Request, HTTPException, Header
    from fastapi.responses import JSONResponse
    import uvicorn
except ImportError:
    print("Error: FastAPI and uvicorn required. Install with:")
    print("  pip install fastapi uvicorn")
    sys.exit(1)

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger('webhook_receiver')


# Event storage (in-memory, for demo)
recent_events: deque = deque(maxlen=1000)
event_counts: Dict[str, int] = {}


def create_app(webhook_secret: Optional[str] = None) -> FastAPI:
    """Create FastAPI application."""
    
    app = FastAPI(
        title="SWIM Webhook Receiver",
        description="Receives flight events from VATSWIM API",
        version="1.0.0",
    )
    
    @app.get("/")
    async def root():
        """Health check endpoint."""
        return {
            "status": "ok",
            "service": "SWIM Webhook Receiver",
            "events_received": sum(event_counts.values()),
            "event_types": dict(event_counts),
        }
    
    @app.get("/events")
    async def get_events(
        event_type: Optional[str] = None,
        limit: int = 50,
    ):
        """Get recent events."""
        events = list(recent_events)
        
        if event_type:
            events = [e for e in events if e.get('event_type') == event_type]
        
        return {
            "count": len(events[-limit:]),
            "events": events[-limit:],
        }
    
    @app.post("/webhook/swim")
    async def receive_webhook(
        request: Request,
        x_swim_signature: Optional[str] = Header(None),
        x_swim_timestamp: Optional[str] = Header(None),
    ):
        """
        Receive SWIM webhook events.
        
        Expected headers:
        - X-SWIM-Signature: HMAC-SHA256 signature
        - X-SWIM-Timestamp: Event timestamp
        
        Body format:
        {
            "event_type": "flight.departed",
            "timestamp": "2024-01-15T12:00:00Z",
            "data": { ... }
        }
        """
        # Get raw body for signature verification
        body = await request.body()
        
        # Verify signature if secret configured
        if webhook_secret:
            if not x_swim_signature:
                logger.warning("Missing signature header")
                raise HTTPException(status_code=401, detail="Missing signature")
            
            if not verify_signature(body, x_swim_signature, webhook_secret):
                logger.warning("Invalid signature")
                raise HTTPException(status_code=401, detail="Invalid signature")
        
        # Parse body
        try:
            payload = json.loads(body)
        except json.JSONDecodeError:
            raise HTTPException(status_code=400, detail="Invalid JSON")
        
        # Process event
        event_type = payload.get('event_type', 'unknown')
        event_data = payload.get('data', {})
        event_timestamp = payload.get('timestamp', datetime.utcnow().isoformat())
        
        # Store event
        event_record = {
            'event_type': event_type,
            'timestamp': event_timestamp,
            'received_at': datetime.utcnow().isoformat(),
            'data': event_data,
        }
        recent_events.append(event_record)
        
        # Update counts
        event_counts[event_type] = event_counts.get(event_type, 0) + 1
        
        # Log event
        log_event(event_type, event_data)
        
        # Process specific event types
        await process_event(event_type, event_data, event_timestamp)
        
        return {"status": "received", "event_type": event_type}
    
    @app.post("/webhook/test")
    async def test_webhook(request: Request):
        """Test endpoint that accepts any payload (no signature verification)."""
        body = await request.body()
        
        try:
            payload = json.loads(body)
        except json.JSONDecodeError:
            payload = {"raw": body.decode('utf-8', errors='replace')}
        
        logger.info(f"Test webhook received: {json.dumps(payload, indent=2)}")
        
        return {"status": "received", "payload": payload}
    
    return app


def verify_signature(body: bytes, signature: str, secret: str) -> bool:
    """Verify HMAC-SHA256 signature."""
    expected = hmac.new(
        secret.encode('utf-8'),
        body,
        hashlib.sha256
    ).hexdigest()
    
    # Constant-time comparison
    return hmac.compare_digest(f"sha256={expected}", signature)


def log_event(event_type: str, data: Dict[str, Any]):
    """Log event with appropriate formatting."""
    callsign = data.get('callsign', 'N/A')
    
    if event_type == 'flight.created':
        dep = data.get('dept_icao', data.get('dep', '????'))
        dest = data.get('dest_icao', data.get('arr', '????'))
        logger.info(f"✈️  NEW: {callsign} {dep} → {dest}")
    
    elif event_type == 'flight.departed':
        dep = data.get('dept_icao', data.get('dep', '????'))
        logger.info(f"🛫 DEP: {callsign} from {dep}")
    
    elif event_type == 'flight.arrived':
        arr = data.get('dest_icao', data.get('arr', '????'))
        logger.info(f"🛬 ARR: {callsign} at {arr}")
    
    elif event_type == 'flight.position':
        alt = data.get('altitude', data.get('altitude_ft', 0))
        logger.debug(f"📍 POS: {callsign} FL{alt // 100:03d}")
    
    elif event_type.startswith('tmi.'):
        airport = data.get('airport', '????')
        tmi_type = data.get('program_type', data.get('type', 'TMI'))
        logger.info(f"🚦 TMI: {tmi_type} at {airport} ({event_type})")
    
    elif event_type == 'system.heartbeat':
        clients = data.get('connected_clients', 0)
        logger.debug(f"💓 Heartbeat: {clients} clients")
    
    else:
        logger.info(f"📥 {event_type}: {callsign}")


async def process_event(event_type: str, data: Dict[str, Any], timestamp: str):
    """
    Process event and trigger downstream actions.
    
    Override this function to add custom processing logic:
    - Forward to message queue
    - Update database
    - Send notifications
    - Trigger alerts
    """
    # Example: Alert on ground stops
    if event_type == 'tmi.issued':
        tmi_type = data.get('program_type', '')
        if tmi_type in ('GS', 'GROUND_STOP'):
            airport = data.get('airport', '????')
            reason = data.get('reason', 'Unknown')
            logger.warning(f"🛑 GROUND STOP ALERT: {airport} - {reason}")
            # TODO: Send notification to Slack/Discord/email
    
    # Example: Log significant delays
    if event_type in ('flight.departed', 'flight.arrived'):
        delay = data.get('delay_minutes', 0)
        if delay and delay > 30:
            callsign = data.get('callsign', 'N/A')
            logger.warning(f"⏰ DELAY ALERT: {callsign} - {delay} minutes")


def main():
    parser = argparse.ArgumentParser(description='SWIM Webhook Receiver')
    parser.add_argument('--host', default='0.0.0.0', help='Host to bind to')
    parser.add_argument('--port', type=int, default=8080, help='Port to listen on')
    parser.add_argument('--secret', help='Webhook signature secret')
    parser.add_argument('--debug', action='store_true', help='Enable debug logging')
    
    args = parser.parse_args()
    
    if args.debug:
        logging.getLogger().setLevel(logging.DEBUG)
        logger.setLevel(logging.DEBUG)
    
    print(f"\n{'='*60}")
    print(f"  SWIM Webhook Receiver")
    print(f"  Listening on: http://{args.host}:{args.port}")
    print(f"  Webhook URL:  http://<your-host>:{args.port}/webhook/swim")
    print(f"  Signature:    {'Enabled' if args.secret else 'Disabled'}")
    print(f"{'='*60}\n")
    
    app = create_app(webhook_secret=args.secret)
    
    uvicorn.run(app, host=args.host, port=args.port)


if __name__ == '__main__':
    main()
