# SWIM API Session Transition - Phase 2 WebSocket Implementation

**Date:** 2026-01-16  
**Session:** Phase 2 Start  
**Status:** IN PROGRESS (60%)

---

## Summary

Started Phase 2 implementation of real-time WebSocket distribution for the SWIM API. All core server components have been created using PHP Ratchet. Ready for testing and ADL daemon integration.

---

## Completed This Session

### 1. Phase 2 Design Document

Created comprehensive design document covering:
- Architecture with PHP Ratchet WebSocket server
- Event types (flight.position, flight.departed, tmi.issued, etc.)
- WebSocket protocol specification
- Subscription filtering (airports, ARTCCs, callsign prefix, bbox)
- Client libraries and examples

**File:** `docs/swim/SWIM_Phase2_RealTime_Design.md`

### 2. Core WebSocket Server Components

| File | Purpose |
|------|---------|
| `api/swim/v1/ws/WebSocketServer.php` | Main Ratchet server class |
| `api/swim/v1/ws/ClientConnection.php` | Client connection wrapper |
| `api/swim/v1/ws/SubscriptionManager.php` | Subscription & filter management |

### 3. Server Daemon

Created `scripts/swim_ws_server.php` - standalone daemon that:
- Accepts WebSocket connections on port 8080
- Handles authentication, subscriptions, ping/pong
- Polls event file for incoming events from ADL daemon
- Sends heartbeats every 30 seconds
- Logs statistics every 5 minutes

### 4. Event Detection Module

Created `scripts/swim_ws_events.php` with functions:
- `swim_detectFlightEvents()` - New, departed, arrived, deleted flights
- `swim_detectPositionUpdates()` - Batched position changes
- `swim_detectTmiEvents()` - TMI issued/released
- `swim_publishToWebSocket()` - Write to event file
- `swim_processWebSocketEvents()` - Main processing function

### 5. JavaScript Client Library

Created `api/swim/v1/ws/swim-ws-client.js`:
```javascript
const swim = new SWIMWebSocket('your-api-key');
swim.connect();
swim.subscribe(['flight.position', 'tmi.*'], { airports: ['KJFK'] });
swim.on('flight.position', (data) => console.log(data));
```

### 6. Package Dependencies

Created `composer.json` with Ratchet dependency:
```json
{
    "require": {
        "cboden/ratchet": "^0.4.4",
        "react/event-loop": "^1.4"
    }
}
```

---

## Next Steps (To Complete Phase 2)

### Immediate Tasks

1. **Install Dependencies**
   ```bash
   cd /path/to/PERTI
   composer install
   ```

2. **Test WebSocket Server Locally**
   ```bash
   php scripts/swim_ws_server.php --debug
   ```

3. **Integrate with ADL Daemon**
   
   Add to `vatsim_adl_daemon.php` after main refresh:
   ```php
   require_once __DIR__ . '/swim_ws_events.php';
   
   // In main loop after executeRefreshSP()
   if ($config['websocket_enabled']) {
       $wsResult = swim_processWebSocketEvents($conn, $lastRefreshTime);
       if ($wsResult['total_events'] > 0) {
           logInfo("WS events", $wsResult);
       }
   }
   ```

4. **Test End-to-End**
   - Start WebSocket server
   - Start ADL daemon
   - Connect with JavaScript client
   - Verify events are received

### Azure Deployment Tasks

1. Configure Azure App Service for WebSocket support
2. Deploy swim_ws_server.php as background process
3. Monitor performance and stability

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PERTI Azure App Service                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌───────────────────┐                     ┌───────────────────────────┐    │
│  │ vatsim_adl_daemon │                     │    swim_ws_server.php     │    │
│  │   (15s refresh)   │                     │    (Ratchet WebSocket)    │    │
│  │                   │                     │                           │    │
│  │ ┌───────────────┐ │    Event File       │ ┌───────────────────────┐ │    │
│  │ │swim_ws_events │─┼──────────────────▶──┼─│    Event Poller       │ │    │
│  │ │    .php       │ │  /tmp/swim_ws_      │ │    (0.5s interval)    │ │    │
│  │ └───────────────┘ │  events.json        │ └───────────┬───────────┘ │    │
│  └───────────────────┘                     │             │             │    │
│                                            │             ▼             │    │
│                                            │ ┌───────────────────────┐ │    │
│                                            │ │  SubscriptionManager  │ │    │
│                                            │ │  - Filter by channel  │ │    │
│                                            │ │  - Filter by criteria │ │    │
│                                            │ └───────────┬───────────┘ │    │
│                                            │             │             │    │
│                                            │             ▼             │    │
│                                            │ ┌───────────────────────┐ │    │
│                                            │ │   Client Connections  │ │    │
│                                            │ │   ┌────┬────┬────┐    │ │    │
│                                            │ │   │CRC │vNAS│ .. │    │ │    │
│                                            │ │   └────┴────┴────┘    │ │    │
│                                            │ └───────────────────────┘ │    │
│                                            └───────────────────────────┘    │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Event Flow

1. **ADL Daemon** fetches VATSIM data every 15 seconds
2. **Event Detection** (`swim_ws_events.php`) queries for changes
3. **Events Written** to `/tmp/swim_ws_events.json`
4. **WebSocket Server** polls event file every 0.5 seconds
5. **Events Routed** to subscribed clients based on filters
6. **Clients Receive** real-time updates

---

## Files Created/Modified

### New Files

| File | Size | Purpose |
|------|------|---------|
| `composer.json` | ~300B | Package dependencies |
| `api/swim/v1/ws/WebSocketServer.php` | ~14KB | Core server class |
| `api/swim/v1/ws/ClientConnection.php` | ~4KB | Connection wrapper |
| `api/swim/v1/ws/SubscriptionManager.php` | ~6KB | Subscription logic |
| `api/swim/v1/ws/publish.php` | ~1KB | Internal publish API |
| `api/swim/v1/ws/swim-ws-client.js` | ~5KB | JavaScript client |
| `scripts/swim_ws_server.php` | ~7KB | Server daemon |
| `scripts/swim_ws_events.php` | ~6KB | Event detection |
| `docs/swim/SWIM_Phase2_RealTime_Design.md` | ~12KB | Design document |

### Modified Files

| File | Changes |
|------|---------|
| `docs/swim/SWIM_TODO.md` | Updated status to Phase 2 IN PROGRESS |

---

## Testing Commands

```bash
# Install dependencies
composer install

# Start WebSocket server in debug mode
php scripts/swim_ws_server.php --debug

# Test with wscat (npm install -g wscat)
wscat -c "ws://localhost:8080?api_key=test-key"

# Send subscribe message
{"action":"subscribe","channels":["flight.position","tmi.*"]}
```

---

## Cost Impact

| Component | Before | After |
|-----------|--------|-------|
| SWIM_API (Azure SQL Basic) | $5/mo | $5/mo |
| WebSocket Server | $0 | $0 (self-hosted on existing App Service) |
| **Total** | **$5/mo** | **$5/mo** |

---

## Open Items for Next Session

1. Complete ADL daemon integration
2. Implement database authentication (vs. dev mode)
3. Test with multiple concurrent clients
4. Configure Azure App Service WebSocket support
5. Deploy to production

---

**Session Duration:** ~2 hours  
**Next Estimated:** ~4 hours to complete Phase 2
