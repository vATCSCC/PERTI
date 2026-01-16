# SWIM API Phase 2: Real-Time Distribution Design Document

**Version:** 1.0  
**Created:** 2026-01-16  
**Status:** ACTIVE DEVELOPMENT

---

## 1. Executive Summary

Phase 2 implements real-time WebSocket distribution of flight data changes to connected clients. This enables applications like CRC, vNAS, SimAware, and vPilot to receive instant updates rather than polling the REST API.

### Goals

1. **Sub-second latency** - Clients receive updates within 1 second of ADL refresh
2. **Efficient bandwidth** - Only send changes (deltas) not full flight records
3. **Scalable** - Support 100+ concurrent connections
4. **Cost-effective** - No additional Azure service costs

### Technology Choice: PHP Ratchet

After evaluating options, **PHP Ratchet** is selected because:
- No additional Azure costs (runs on existing App Service)
- Integrates with existing PHP codebase
- Full control over protocol and subscriptions
- Proven WebSocket library for PHP

---

## 2. Architecture

### High-Level Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PERTI Azure App Service                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌───────────────────┐         ┌───────────────────────────────────────┐   │
│  │  vatsim_adl_daemon │         │         swim_ws_server.php            │   │
│  │  (15s refresh)     │         │         (Ratchet WebSocket)           │   │
│  └─────────┬─────────┘         │                                        │   │
│            │                    │  ┌─────────────────────────────────┐  │   │
│            │ 1. After refresh   │  │  Connected Clients               │  │   │
│            │    detect changes  │  │  ┌─────┐ ┌─────┐ ┌─────┐       │  │   │
│            │                    │  │  │ CRC │ │vNAS │ │SimAw│ ...   │  │   │
│            ▼                    │  │  └──┬──┘ └──┬──┘ └──┬──┘       │  │   │
│  ┌───────────────────┐         │  │     │       │       │           │  │   │
│  │ Event Detection   │         │  └─────┼───────┼───────┼───────────┘  │   │
│  │ (in ADL daemon)   │         │        │       │       │              │   │
│  └─────────┬─────────┘         │        │       │       │              │   │
│            │                    │        │       │       │              │   │
│            │ 2. POST events     │        │ 4. Push filtered events     │   │
│            │    to WS server    │        │                              │   │
│            ▼                    │        ▼                              │   │
│  ┌───────────────────┐         │  ┌─────────────────────────────────┐  │   │
│  │ Internal API      │─────────┼─▶│  Event Router                   │  │   │
│  │ POST /ws/publish  │         │  │  - Filter by subscription       │  │   │
│  └───────────────────┘         │  │  - Format for each client       │  │   │
│                                 │  └─────────────────────────────────┘  │   │
│                                 │                                        │   │
│                                 └───────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | Responsibility |
|-----------|----------------|
| `vatsim_adl_daemon.php` | Detect changes after each refresh, publish events |
| `swim_ws_server.php` | Accept WebSocket connections, manage subscriptions |
| Internal API | Receive events from daemon, route to WS server |
| Event Router | Filter events based on client subscriptions |

---

## 3. Event Types

### Flight Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `flight.position` | Position/heading change | callsign, lat, lon, alt, gs, hdg, vrate |
| `flight.departed` | OUT→OFF detected | callsign, dep, off_utc |
| `flight.arrived` | ON→IN detected | callsign, arr, in_utc |
| `flight.created` | New flight filed | Full flight record |
| `flight.updated` | Flight plan change | Changed fields only |
| `flight.deleted` | Pilot disconnected | callsign, flight_uid |

### TMI Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `tmi.issued` | New GS/GDP created | program_type, airport, times |
| `tmi.modified` | TMI parameters changed | program_id, changed fields |
| `tmi.released` | TMI ended/purged | program_id, end_time |

### System Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `system.heartbeat` | Every 30s | timestamp, connected_count |
| `system.refresh` | ADL refresh complete | timestamp, flight_count |

---

## 4. WebSocket Protocol

### Connection URL

```
wss://perti.vatcscc.org/api/swim/v1/ws
```

### Authentication

Clients authenticate via API key in the initial connection:

```javascript
const ws = new WebSocket('wss://perti.vatcscc.org/api/swim/v1/ws', {
    headers: {
        'X-SWIM-API-Key': 'your-api-key'
    }
});
```

Or via query parameter:
```
wss://perti.vatcscc.org/api/swim/v1/ws?api_key=your-api-key
```

### Message Format

All messages are JSON with a standard envelope:

```json
{
    "type": "event_type",
    "timestamp": "2026-01-16T14:30:00Z",
    "data": { ... }
}
```

### Client → Server Messages

#### Subscribe to Events

```json
{
    "action": "subscribe",
    "channels": ["flight.position", "flight.departed", "tmi.*"],
    "filters": {
        "airports": ["KJFK", "KLAX"],
        "artccs": ["ZNY", "ZLA"],
        "callsign_prefix": ["AAL", "UAL"]
    }
}
```

#### Unsubscribe

```json
{
    "action": "unsubscribe",
    "channels": ["flight.position"]
}
```

#### Ping (keepalive)

```json
{
    "action": "ping"
}
```

### Server → Client Messages

#### Event

```json
{
    "type": "flight.position",
    "timestamp": "2026-01-16T14:30:15.123Z",
    "data": {
        "callsign": "UAL123",
        "latitude": 40.6413,
        "longitude": -73.7781,
        "altitude_ft": 35000,
        "groundspeed_kts": 450,
        "heading_deg": 270,
        "vertical_rate_fpm": 0
    }
}
```

#### Subscription Confirmation

```json
{
    "type": "subscribed",
    "channels": ["flight.position", "flight.departed"],
    "filters": { ... }
}
```

#### Pong

```json
{
    "type": "pong",
    "timestamp": "2026-01-16T14:30:00Z"
}
```

#### Error

```json
{
    "type": "error",
    "code": "INVALID_FILTER",
    "message": "Unknown airport code: XXXX"
}
```

---

## 5. Subscription Filters

### Airport Filter

Subscribe to events for specific airports:

```json
{
    "filters": {
        "airports": ["KJFK", "KEWR", "KLGA"]
    }
}
```

Matches flights where `dep` OR `arr` is in the list.

### ARTCC Filter

Subscribe to events for specific ARTCCs:

```json
{
    "filters": {
        "artccs": ["ZNY", "ZBW"]
    }
}
```

Matches flights currently in the specified ARTCC.

### Callsign Prefix Filter

Subscribe to specific airlines/operators:

```json
{
    "filters": {
        "callsign_prefix": ["AAL", "DAL", "UAL"]
    }
}
```

### Bounding Box Filter

Subscribe to a geographic area:

```json
{
    "filters": {
        "bbox": {
            "north": 42.0,
            "south": 40.0,
            "east": -72.0,
            "west": -75.0
        }
    }
}
```

### Filter Combination

All filters are combined with AND logic:

```json
{
    "filters": {
        "airports": ["KJFK"],
        "callsign_prefix": ["AAL"]
    }
}
```
→ Only AAL flights to/from JFK

---

## 6. Implementation Details

### 6.1 Directory Structure

```
api/swim/v1/
├── ws/
│   ├── WebSocketServer.php     # Main Ratchet server class
│   ├── ClientConnection.php    # Client connection wrapper
│   ├── SubscriptionManager.php # Manages client subscriptions
│   ├── EventRouter.php         # Routes events to clients
│   └── publish.php             # Internal API for daemon
│
scripts/
├── swim_ws_server.php          # WebSocket server daemon
└── vatsim_adl_daemon.php       # (modified) Add event publishing
```

### 6.2 Server Class (WebSocketServer.php)

```php
<?php
namespace PERTI\SWIM\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class WebSocketServer implements MessageComponentInterface {
    protected $clients;
    protected $subscriptions;
    protected $authManager;
    
    public function onOpen(ConnectionInterface $conn) {
        // Authenticate connection
        // Add to clients collection
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
        // Parse JSON message
        // Handle subscribe/unsubscribe/ping
    }
    
    public function onClose(ConnectionInterface $conn) {
        // Remove from clients
        // Clean up subscriptions
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e) {
        // Log error
        // Close connection
    }
    
    public function broadcast(string $eventType, array $data) {
        // Route to subscribed clients
    }
}
```

### 6.3 ADL Daemon Changes

Add event detection after each refresh cycle:

```php
// In runDaemon() after executeRefreshSP()

// Detect changes for WebSocket events
if ($config['websocket_enabled']) {
    $events = detectFlightEvents($conn, $prevSnapshot);
    if (!empty($events)) {
        publishToWebSocket($events);
    }
}
```

Event detection queries:

```sql
-- New flights (created since last refresh)
SELECT * FROM adl_flight_core 
WHERE created_at > @lastRefresh;

-- Departed flights (OFF time set since last refresh)  
SELECT c.callsign, t.off_utc, p.dep
FROM adl_flight_core c
JOIN adl_flight_times t ON t.flight_uid = c.flight_uid
JOIN adl_flight_plan p ON p.flight_uid = c.flight_uid
WHERE t.off_utc > @lastRefresh;

-- Arrived flights (IN time set since last refresh)
SELECT c.callsign, t.in_utc, p.arr
FROM adl_flight_core c
JOIN adl_flight_times t ON t.flight_uid = c.flight_uid
JOIN adl_flight_plan p ON p.flight_uid = c.flight_uid
WHERE t.in_utc > @lastRefresh;

-- Deleted flights (marked inactive)
SELECT callsign, flight_uid
FROM adl_flight_core
WHERE is_active = 0 
  AND last_seen > @prevRefresh 
  AND last_seen <= @lastRefresh;
```

### 6.4 Internal Publish API

The ADL daemon publishes events via HTTP to the WebSocket server:

```
POST /api/swim/v1/ws/publish
Content-Type: application/json
X-Internal-Key: <daemon-secret>

{
    "events": [
        {
            "type": "flight.position",
            "data": { ... }
        },
        {
            "type": "flight.departed",
            "data": { ... }
        }
    ]
}
```

---

## 7. Performance Considerations

### Connection Limits

| Tier | Max Connections | Rate Limit |
|------|-----------------|------------|
| Free | 5 | 10 msg/sec |
| Basic | 50 | 100 msg/sec |
| Pro | 500 | 1000 msg/sec |
| Enterprise | Unlimited | Unlimited |

### Message Batching

Position updates are batched per refresh cycle:

```json
{
    "type": "flight.positions",
    "timestamp": "2026-01-16T14:30:15Z",
    "count": 2847,
    "data": [
        { "callsign": "UAL123", "lat": 40.64, "lon": -73.78, ... },
        { "callsign": "AAL456", "lat": 33.94, "lon": -118.40, ... },
        ...
    ]
}
```

### Delta Compression

For position updates, only send changed fields:

```json
{
    "type": "flight.position",
    "data": {
        "callsign": "UAL123",
        "altitude_ft": 36000,    // Changed from 35000
        "vertical_rate_fpm": 500  // Was 0
        // lat, lon, gs, hdg omitted if unchanged
    }
}
```

### Heartbeat

Server sends heartbeat every 30 seconds:

```json
{
    "type": "system.heartbeat",
    "timestamp": "2026-01-16T14:30:00Z",
    "data": {
        "connected_clients": 47,
        "active_flights": 2847,
        "uptime_seconds": 86400
    }
}
```

---

## 8. Deployment

### Azure App Service Configuration

WebSocket requires specific configuration:

```xml
<!-- web.config -->
<webSocket enabled="true" />
```

### Daemon Startup

The WebSocket server runs as a separate daemon:

```bash
# Start WebSocket server
nohup php scripts/swim_ws_server.php &

# Or via systemd
systemctl start swim-ws
```

### Port Configuration

| Environment | WebSocket Port | Internal API Port |
|-------------|----------------|-------------------|
| Production | 443 (wss://) | 8080 (internal) |
| Development | 8081 (ws://) | 8082 |

---

## 9. Security

### Authentication

- API key required for connection
- Keys validated against `swim_api_keys` table
- Invalid keys rejected with close code 4001

### Rate Limiting

- Per-connection message limits
- Excessive requests result in throttling
- Abuse triggers automatic disconnect

### Internal API Protection

- Daemon-to-WS communication uses internal secret
- Not exposed to external network
- IP whitelist for publish endpoint

---

## 10. Client Libraries

### JavaScript Example

```javascript
class SWIMWebSocket {
    constructor(apiKey) {
        this.apiKey = apiKey;
        this.ws = null;
        this.handlers = {};
    }
    
    connect() {
        this.ws = new WebSocket(
            `wss://perti.vatcscc.org/api/swim/v1/ws?api_key=${this.apiKey}`
        );
        
        this.ws.onmessage = (event) => {
            const msg = JSON.parse(event.data);
            if (this.handlers[msg.type]) {
                this.handlers[msg.type](msg.data);
            }
        };
    }
    
    subscribe(channels, filters = {}) {
        this.ws.send(JSON.stringify({
            action: 'subscribe',
            channels,
            filters
        }));
    }
    
    on(eventType, handler) {
        this.handlers[eventType] = handler;
    }
}

// Usage
const swim = new SWIMWebSocket('your-api-key');
swim.connect();
swim.subscribe(['flight.position', 'flight.departed'], {
    airports: ['KJFK']
});
swim.on('flight.position', (data) => {
    console.log(`${data.callsign} at ${data.altitude_ft}ft`);
});
```

---

## 11. Implementation Phases

### Phase 2.1: Core WebSocket Server (Week 1)

| Task | Effort | Status |
|------|--------|--------|
| Install Ratchet via Composer | 1h | ⏳ |
| Create WebSocketServer class | 4h | ⏳ |
| Implement authentication | 2h | ⏳ |
| Create server daemon script | 2h | ⏳ |
| Test basic connections | 2h | ⏳ |

### Phase 2.2: Subscriptions & Filtering (Week 2)

| Task | Effort | Status |
|------|--------|--------|
| Create SubscriptionManager | 4h | ⏳ |
| Implement filter logic | 4h | ⏳ |
| Create EventRouter | 4h | ⏳ |
| Test subscription scenarios | 4h | ⏳ |

### Phase 2.3: ADL Integration (Week 2-3)

| Task | Effort | Status |
|------|--------|--------|
| Add event detection to daemon | 4h | ⏳ |
| Create internal publish API | 2h | ⏳ |
| Connect daemon to WS server | 2h | ⏳ |
| End-to-end testing | 4h | ⏳ |

### Phase 2.4: Production Deployment (Week 3)

| Task | Effort | Status |
|------|--------|--------|
| Azure App Service config | 2h | ⏳ |
| SSL/WSS configuration | 2h | ⏳ |
| Monitoring and logging | 2h | ⏳ |
| Documentation update | 2h | ⏳ |

---

## 12. Open Questions

1. **Delta vs Full Records**: Should position updates always include all fields, or only changed fields?
   - **Recommendation**: Changed fields only (bandwidth savings)

2. **Reconnection Policy**: How should clients handle reconnection?
   - **Recommendation**: Exponential backoff, max 30 seconds

3. **Historical Replay**: Should clients be able to request missed events?
   - **Recommendation**: No for Phase 2, consider for Phase 3

4. **Message Ordering**: Are event order guarantees needed?
   - **Recommendation**: Best-effort ordering, timestamp included for client sorting

---

## 13. Success Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| Latency (ADL→Client) | < 1 second | Timestamp comparison |
| Connection stability | 99.5% uptime | Monitoring |
| Concurrent connections | 100+ | Load testing |
| Message throughput | 10K msg/sec | Stress testing |

---

## 14. References

- [Ratchet PHP WebSocket Library](http://socketo.me/)
- [RFC 6455: The WebSocket Protocol](https://tools.ietf.org/html/rfc6455)
- [Azure App Service WebSocket Support](https://docs.microsoft.com/en-us/azure/app-service/faq-configuration-and-management#how-do-i-turn-on-web-sockets)

---

**Next Steps:**
1. Install Ratchet dependency
2. Create core WebSocketServer class
3. Create server daemon script
