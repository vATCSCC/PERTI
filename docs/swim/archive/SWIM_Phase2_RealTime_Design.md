# SWIM API Phase 2: Real-Time Distribution Design Document

**Version:** 1.1  
**Created:** 2026-01-16  
**Updated:** 2026-01-16  
**Status:** ✅ COMPLETE (Deployed to Production)

---

## 1. Executive Summary

Phase 2 implements real-time WebSocket distribution of flight data changes to connected clients. This enables applications like CRC, vNAS, SimAware, and vPilot to receive instant updates rather than polling the REST API.

### Goals

1. **Sub-second latency** - Clients receive updates within 15 seconds of flight change ✅
2. **Efficient bandwidth** - Only send changes (deltas) not full flight records ✅
3. **Scalable** - Support 100+ concurrent connections ✅
4. **Cost-effective** - No additional Azure service costs ✅

### Technology Choice: PHP Ratchet

**PHP Ratchet** was selected because:
- No additional Azure costs (runs on existing App Service)
- Integrates with existing PHP codebase
- Full control over protocol and subscriptions
- Proven WebSocket library for PHP

---

## 2. Implementation Status

### Completed Components

| Component | File | Status |
|-----------|------|--------|
| WebSocket Server | `scripts/swim_ws_server.php` | ✅ Deployed |
| Event Detection | `scripts/swim_ws_events.php` | ✅ Deployed |
| ADL Daemon Integration | `scripts/vatsim_adl_daemon.php` | ✅ Deployed |
| JavaScript Client | `api/swim/v1/ws/swim-ws-client.js` | ✅ Deployed |
| Apache Proxy Config | `scripts/startup.sh` | ✅ Deployed |

### Deployment Details

- **WebSocket Port:** 8090 (internal)
- **External URL:** `wss://perti.vatcscc.org/api/swim/v1/ws` (via Apache proxy)
- **Event Detection:** Runs every 15 seconds with ADL refresh cycle
- **Typical Events:** 2-10 per cycle (new flights, departures, arrivals, disconnects)

---

## 3. Architecture

### High-Level Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PERTI Azure App Service                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌───────────────────┐         ┌───────────────────────────────────────┐   │
│  │  vatsim_adl_daemon │         │         swim_ws_server.php            │   │
│  │  (15s refresh)     │         │         (Ratchet on port 8090)        │   │
│  └─────────┬─────────┘         │                                        │   │
│            │                    │  ┌─────────────────────────────────┐  │   │
│            │ 1. After refresh   │  │  Connected Clients               │  │   │
│            │    detect changes  │  │  ┌─────┐ ┌─────┐ ┌─────┐       │  │   │
│            │                    │  │  │ CRC │ │vNAS │ │SimAw│ ...   │  │   │
│            ▼                    │  │  └──┬──┘ └──┬──┘ └──┬──┘       │  │   │
│  ┌───────────────────┐         │  │     │       │       │           │  │   │
│  │ swim_ws_events.php│         │  └─────┼───────┼───────┼───────────┘  │   │
│  │ (Event Detection) │         │        │       │       │              │   │
│  └─────────┬─────────┘         │        │ 3. Push filtered events     │   │
│            │                    │        ▼                              │   │
│            │ 2. Write to        │  ┌─────────────────────────────────┐  │   │
│            │    event file      │  │  Subscription Filter             │  │   │
│            ▼                    │  │  - By airport                    │  │   │
│  ┌───────────────────┐         │  │  - By ARTCC                      │  │   │
│  │/tmp/swim_ws_      │────────▶│  │  - By callsign prefix            │  │   │
│  │events.json        │         │  │  - By bounding box               │  │   │
│  └───────────────────┘         │  └─────────────────────────────────┘  │   │
│                                 │                                        │   │
│                                 └───────────────────────────────────────┘   │
│                                                                              │
│  ┌───────────────────┐                                                      │
│  │  Apache (port 80) │ ─── ProxyPass /api/swim/v1/ws ──▶ ws://127.0.0.1:8090│
│  └───────────────────┘                                                      │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Event Types

### Flight Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `flight.created` | New flight filed | callsign, dep, arr, equipment, route, position |
| `flight.departed` | OFF time detected | callsign, dep, arr, off_utc |
| `flight.arrived` | IN time detected | callsign, dep, arr, in_utc |
| `flight.deleted` | Pilot disconnected | callsign, flight_uid |
| `flight.positions` | Position batch (optional) | Array of all position updates |

### TMI Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `tmi.issued` | New GS/GDP created | program_type, airport, times, reason |
| `tmi.released` | TMI ended/purged | program_id, status, end_time |

---

## 5. WebSocket Protocol

### Connection URL

```
wss://perti.vatcscc.org/api/swim/v1/ws?api_key=YOUR_KEY
```

### Subscribe Message

```json
{
    "action": "subscribe",
    "channels": ["flight.created", "flight.departed", "tmi.*"],
    "filters": {
        "airports": ["KJFK", "KEWR"],
        "artccs": ["ZNY"],
        "callsign_prefix": ["AAL"],
        "bbox": {"north": 42, "south": 40, "east": -72, "west": -75}
    }
}
```

### Event Message

```json
{
    "type": "flight.departed",
    "timestamp": "2026-01-16T14:30:15.123Z",
    "data": {
        "callsign": "UAL123",
        "flight_uid": "abc123",
        "dep": "KEWR",
        "arr": "KSFO",
        "off_utc": "2026-01-16T14:30:00Z"
    }
}
```

---

## 6. Configuration

### ADL Daemon Config

```php
// WebSocket real-time events
'websocket_enabled'   => true,
'websocket_positions' => false,  // High volume - disabled by default
```

### WebSocket Server Config

```php
$config = [
    'host' => '0.0.0.0',
    'port' => 8090,
    'auth_enabled' => true,
    'event_file' => '/tmp/swim_ws_events.json',
];
```

---

## 7. Monitoring

### Log Files

| Log | Location |
|-----|----------|
| WebSocket Server | `/home/LogFiles/swim_ws.log` |
| ADL Daemon | `/home/LogFiles/vatsim_adl.log` |

### ADL Log shows `ws_events` count:

```
[2026-01-16 07:02:27Z] [INFO] Refresh #5 {"pilots":756,"sp_ms":4892,"ws_events":6}
```

---

## 8. Future Enhancements (Phase 3)

1. **Redis IPC** - Replace file-based event queue
2. **Message Compression** - gzip for position updates
3. **Client SDKs** - Python, C#, Java libraries
4. **Database Authentication** - Replace debug mode with swim_api_keys

---

## 9. Cost Impact

**No additional costs** - WebSocket server runs on existing Azure App Service ($5/month SWIM_API database unchanged).
