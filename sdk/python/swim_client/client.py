"""
SWIM WebSocket Client

Main client class for connecting to PERTI SWIM WebSocket API.
"""

import asyncio
import json
import logging
import ssl
import time
from datetime import datetime
from typing import Any, Callable, Dict, List, Optional, Set, Union
from urllib.parse import urlencode

try:
    import websockets
    from websockets.client import WebSocketClientProtocol
except ImportError:
    raise ImportError(
        "websockets library required. Install with: pip install websockets"
    )

from .events import (
    EventType,
    FlightEvent,
    TMIEvent,
    PositionBatch,
    HeartbeatEvent,
    ConnectionInfo,
    SubscriptionFilters,
)

logger = logging.getLogger('swim_client')


class SWIMClient:
    """
    VATSIM SWIM WebSocket Client
    
    Connects to the PERTI SWIM API for real-time flight data streaming.
    
    Example:
        client = SWIMClient('your-api-key')
        
        @client.on('flight.departed')
        def on_departure(data, timestamp):
            print(f"{data['callsign']} departed {data['dep']}")
        
        client.subscribe(['flight.departed', 'flight.arrived'])
        client.run()  # Blocking
        
        # Or async:
        await client.connect()
        await client.run_async()
    """
    
    DEFAULT_URL = 'wss://perti.vatcscc.org/api/swim/v1/ws'
    
    def __init__(
        self,
        api_key: str,
        url: Optional[str] = None,
        reconnect: bool = True,
        reconnect_interval: float = 5.0,
        max_reconnect_interval: float = 60.0,
        ping_interval: float = 30.0,
        debug: bool = False,
    ):
        """
        Initialize SWIM client.
        
        Args:
            api_key: API key for authentication
            url: WebSocket URL (default: wss://perti.vatcscc.org/api/swim/v1/ws)
            reconnect: Auto-reconnect on disconnect
            reconnect_interval: Initial reconnect delay (seconds)
            max_reconnect_interval: Maximum reconnect delay (seconds)
            ping_interval: Ping interval to keep connection alive (seconds)
            debug: Enable debug logging
        """
        self.api_key = api_key
        self.url = url or self.DEFAULT_URL
        self.reconnect = reconnect
        self.reconnect_interval = reconnect_interval
        self.max_reconnect_interval = max_reconnect_interval
        self.ping_interval = ping_interval
        
        # Connection state
        self._ws: Optional[WebSocketClientProtocol] = None
        self._connected = False
        self._client_id: Optional[str] = None
        self._reconnect_attempts = 0
        self._running = False
        
        # Subscriptions
        self._channels: List[str] = []
        self._filters: Dict[str, Any] = {}
        
        # Event handlers: event_type -> list of callbacks
        self._handlers: Dict[str, List[Callable]] = {}
        
        # Logging
        if debug:
            logging.basicConfig(level=logging.DEBUG)
            logger.setLevel(logging.DEBUG)
        else:
            logger.setLevel(logging.INFO)
    
    # =========================================================================
    # Public API
    # =========================================================================
    
    def on(self, event_type: Union[str, EventType]) -> Callable:
        """
        Decorator to register an event handler.
        
        Args:
            event_type: Event type to listen for (e.g., 'flight.departed')
        
        Returns:
            Decorator function
        
        Example:
            @client.on('flight.departed')
            def handle_departure(data, timestamp):
                print(data)
        """
        if isinstance(event_type, EventType):
            event_type = event_type.value
        
        def decorator(func: Callable) -> Callable:
            self.add_handler(event_type, func)
            return func
        
        return decorator
    
    def add_handler(self, event_type: str, handler: Callable) -> None:
        """
        Add an event handler.
        
        Args:
            event_type: Event type to listen for
            handler: Callback function(data, timestamp)
        """
        if event_type not in self._handlers:
            self._handlers[event_type] = []
        self._handlers[event_type].append(handler)
        logger.debug(f"Added handler for {event_type}")
    
    def remove_handler(self, event_type: str, handler: Optional[Callable] = None) -> None:
        """
        Remove event handler(s).
        
        Args:
            event_type: Event type
            handler: Specific handler to remove (or all if None)
        """
        if event_type not in self._handlers:
            return
        
        if handler is None:
            del self._handlers[event_type]
        else:
            self._handlers[event_type] = [
                h for h in self._handlers[event_type] if h != handler
            ]
    
    def subscribe(
        self,
        channels: List[str],
        airports: Optional[List[str]] = None,
        artccs: Optional[List[str]] = None,
        callsign_prefix: Optional[List[str]] = None,
        bbox: Optional[Dict[str, float]] = None,
    ) -> None:
        """
        Subscribe to event channels.
        
        Args:
            channels: List of channel names (e.g., ['flight.departed', 'tmi.*'])
            airports: Filter by airport ICAO codes
            artccs: Filter by ARTCC IDs
            callsign_prefix: Filter by callsign prefixes
            bbox: Filter by bounding box {north, south, east, west}
        
        Valid channels:
            - flight.created, flight.departed, flight.arrived, flight.deleted
            - flight.position, flight.positions
            - flight.* (all flight events)
            - tmi.issued, tmi.modified, tmi.released
            - tmi.* (all TMI events)
            - system.* (heartbeats, etc.)
        """
        self._channels = channels
        
        filters = SubscriptionFilters(
            airports=airports,
            artccs=artccs,
            callsign_prefix=callsign_prefix,
            bbox=bbox,
        )
        self._filters = filters.to_dict()
        
        if self._connected:
            asyncio.create_task(self._send_subscribe())
    
    def unsubscribe(self, channels: Optional[List[str]] = None) -> None:
        """
        Unsubscribe from channels.
        
        Args:
            channels: Channels to unsubscribe from (all if None)
        """
        if channels is None:
            self._channels = []
            self._filters = {}
        else:
            self._channels = [c for c in self._channels if c not in channels]
        
        if self._connected:
            asyncio.create_task(self._send_unsubscribe(channels or []))
    
    def run(self) -> None:
        """
        Run the client (blocking).
        
        This starts the event loop and runs until interrupted.
        """
        try:
            asyncio.run(self._run_loop())
        except KeyboardInterrupt:
            logger.info("Interrupted by user")
    
    async def connect(self) -> bool:
        """
        Connect to the WebSocket server.
        
        Returns:
            True if connected successfully
        """
        return await self._connect()
    
    async def disconnect(self) -> None:
        """Disconnect from the server."""
        self._running = False
        self.reconnect = False
        
        if self._ws:
            await self._ws.close(1000, 'Client disconnect')
        
        self._connected = False
        self._client_id = None
    
    async def run_async(self) -> None:
        """Run the client asynchronously."""
        await self._run_loop()
    
    def ping(self) -> None:
        """Send ping to server."""
        if self._connected:
            asyncio.create_task(self._send({'action': 'ping'}))
    
    def status(self) -> None:
        """Request connection status from server."""
        if self._connected:
            asyncio.create_task(self._send({'action': 'status'}))
    
    @property
    def connected(self) -> bool:
        """Check if connected."""
        return self._connected
    
    @property
    def client_id(self) -> Optional[str]:
        """Get client ID assigned by server."""
        return self._client_id
    
    # =========================================================================
    # Internal Methods
    # =========================================================================
    
    async def _run_loop(self) -> None:
        """Main event loop."""
        self._running = True
        
        while self._running:
            try:
                if not self._connected:
                    success = await self._connect()
                    if not success:
                        if self.reconnect:
                            await self._schedule_reconnect()
                            continue
                        else:
                            break
                
                # Listen for messages
                await self._listen()
                
            except Exception as e:
                logger.error(f"Connection error: {e}")
                self._connected = False
                
                if self.reconnect and self._running:
                    await self._schedule_reconnect()
                else:
                    break
        
        logger.info("Client stopped")
    
    async def _connect(self) -> bool:
        """Establish WebSocket connection."""
        # Build URL with API key
        params = urlencode({'api_key': self.api_key})
        full_url = f"{self.url}?{params}"
        
        logger.info(f"Connecting to {self.url}")
        
        try:
            # Create SSL context for wss://
            ssl_context = ssl.create_default_context()
            
            self._ws = await websockets.connect(
                full_url,
                ssl=ssl_context if self.url.startswith('wss://') else None,
                ping_interval=self.ping_interval,
                ping_timeout=10,
            )
            
            self._connected = True
            self._reconnect_attempts = 0
            
            logger.info("Connected")
            
            # Re-subscribe if we had subscriptions
            if self._channels:
                await self._send_subscribe()
            
            return True
            
        except Exception as e:
            logger.error(f"Connection failed: {e}")
            return False
    
    async def _listen(self) -> None:
        """Listen for incoming messages."""
        if not self._ws:
            return
        
        async for message in self._ws:
            try:
                data = json.loads(message)
                await self._handle_message(data)
            except json.JSONDecodeError:
                logger.warning(f"Invalid JSON received: {message[:100]}")
            except Exception as e:
                logger.error(f"Error handling message: {e}")
    
    async def _handle_message(self, msg: Dict[str, Any]) -> None:
        """Process incoming message."""
        msg_type = msg.get('type', '')
        data = msg.get('data', {})
        timestamp = msg.get('timestamp', '')
        
        logger.debug(f"Received: {msg_type}")
        
        # Handle special message types
        if msg_type == 'connected':
            self._client_id = data.get('client_id')
            info = ConnectionInfo.from_dict(data)
            self._emit('connected', info, timestamp)
            
        elif msg_type == 'subscribed':
            self._emit('subscribed', msg, timestamp)
            
        elif msg_type == 'unsubscribed':
            self._emit('unsubscribed', msg, timestamp)
            
        elif msg_type == 'pong':
            self._emit('pong', msg, timestamp)
            
        elif msg_type == 'error':
            logger.warning(f"Server error: {msg.get('code')} - {msg.get('message')}")
            self._emit('error', msg, timestamp)
            
        elif msg_type == 'system.heartbeat':
            hb = HeartbeatEvent.from_dict(data)
            self._emit('system.heartbeat', hb, timestamp)
            
        elif msg_type == 'status':
            self._emit('status', data, timestamp)
            
        elif msg_type.startswith('flight.'):
            # Parse flight events
            if msg_type == 'flight.positions':
                batch = PositionBatch.from_dict(data)
                self._emit(msg_type, batch, timestamp)
            else:
                event = FlightEvent.from_dict(data)
                self._emit(msg_type, event, timestamp)
            
        elif msg_type.startswith('tmi.'):
            event = TMIEvent.from_dict(data)
            self._emit(msg_type, event, timestamp)
            
        else:
            # Unknown event - pass raw data
            self._emit(msg_type, data, timestamp)
        
        # Also emit to wildcard handlers
        parts = msg_type.split('.')
        if len(parts) == 2:
            wildcard = f"{parts[0]}.*"
            self._emit(wildcard, data, timestamp, msg_type)
    
    def _emit(self, event_type: str, data: Any, timestamp: str, original_type: Optional[str] = None) -> None:
        """Emit event to registered handlers."""
        handlers = self._handlers.get(event_type, [])
        
        for handler in handlers:
            try:
                # Call handler with appropriate arguments
                if original_type:
                    handler(data, timestamp, original_type)
                else:
                    handler(data, timestamp)
            except Exception as e:
                logger.error(f"Handler error for {event_type}: {e}")
    
    async def _send(self, data: Dict[str, Any]) -> None:
        """Send message to server."""
        if self._ws and self._connected:
            await self._ws.send(json.dumps(data))
    
    async def _send_subscribe(self) -> None:
        """Send subscribe message."""
        await self._send({
            'action': 'subscribe',
            'channels': self._channels,
            'filters': self._filters,
        })
        logger.info(f"Subscribed to: {self._channels}")
    
    async def _send_unsubscribe(self, channels: List[str]) -> None:
        """Send unsubscribe message."""
        await self._send({
            'action': 'unsubscribe',
            'channels': channels,
        })
    
    async def _schedule_reconnect(self) -> None:
        """Schedule a reconnection attempt."""
        self._reconnect_attempts += 1
        
        # Exponential backoff
        delay = min(
            self.reconnect_interval * (1.5 ** (self._reconnect_attempts - 1)),
            self.max_reconnect_interval,
        )
        
        logger.info(f"Reconnecting in {delay:.1f}s (attempt {self._reconnect_attempts})")
        
        await asyncio.sleep(delay)
