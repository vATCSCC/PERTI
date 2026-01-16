# SWIM WebSocket Phase 2/3 Transition Summary

**Date:** 2026-01-16  
**Session:** WebSocket integration deployment  
**Status:** Phase 2 ~85% complete, ready for production hardening

---

## Updates (2026-01-16 Session 2)

### Completed This Session

1. **Poll interval optimization** - Reduced from 500ms to 100ms in `swim_ws_server.php`
   - Latency improvement: ~200ms saved (50ms avg vs 250ms avg)
   - Cost: $0 (no new infrastructure)

2. **Python SDK created** - `sdk/python/swim_client/`
   - Full async WebSocket client with auto-reconnect
   - Typed event data classes (FlightEvent, TMIEvent, PositionBatch)
   - Decorator-based event handlers
   - Subscription filters (airports, ARTCCs, callsigns, bbox)
   - 4 example scripts

### Redis Decision

Redis ($16/mo) deferred until caching layer is needed. Current file-based IPC with 100ms polling adds only ~50ms latency on a 15-second refresh cycle (0.3%).

---

## What Was Completed This Session

### Core WebSocket Infrastructure ✅

| Component | File | Status |
|-----------|------|--------|
| WebSocket Server | `scripts/swim_ws_server.php` | Deployed, port 8090 |
| Event Detection | `scripts/swim_ws_events.php` | Deployed, schema-aligned |
| ADL Integration | `scripts/vatsim_adl_daemon.php` | Deployed, `ws_events` logging |
| Startup Script | `scripts/startup.sh` | Updated with WS + Apache proxy |
| JS Client Library | `api/swim/v1/ws/swim-ws-client.js` | Deployed |
| Design Doc | `docs/swim/SWIM_Phase2_RealTime_Design.md` | Updated to COMPLETE |

### Key Fixes Applied

1. **Port change:** 8080 → 8090 (8080 used by Apache)
2. **Column mappings:**
   - `created_at` → `first_seen_utc`
   - `last_seen` → `last_seen_utc`
   - `p.dep` → `p.fp_dept_icao`
   - `p.arr` → `p.fp_dest_icao`
   - `p.equipment` → `p.aircraft_equip`
   - `p.route` → `p.fp_route`

### Verified Working

```
[2026-01-16 07:02:27Z] [INFO] Refresh #5 {"pilots":756,"sp_ms":4892,"ws_events":6}
```

- Internal WebSocket connection: ✅ 101 Switching Protocols
- Event detection: ✅ 8 events in 60-second window
- ADL daemon logging: ✅ Shows `ws_events` count

---

## Phase 2 Remaining Tasks

### 1. External WebSocket Access (~30 min)

Apache proxy configured in `startup.sh` but needs container restart to take effect.

**Test after restart:**
```bash
# From local machine
wscat -c "wss://perti.vatcscc.org/api/swim/v1/ws?api_key=test-key"
```

**If proxy not working, manually apply:**
```bash
# SSH into Azure
a2enmod proxy proxy_http proxy_wstunnel
cat > /etc/apache2/conf-available/swim-websocket.conf << 'EOF'
<IfModule mod_proxy.c>
    <IfModule mod_proxy_wstunnel.c>
        ProxyRequests Off
        ProxyPreserveHost On
        ProxyPass /api/swim/v1/ws ws://127.0.0.1:8090/
        ProxyPassReverse /api/swim/v1/ws ws://127.0.0.1:8090/
        ProxyTimeout 3600
    </IfModule>
</IfModule>
EOF
a2enconf swim-websocket
service apache2 reload
```

### 2. Database Authentication (~2 hours)

Currently using debug mode (`test-key` accepted). Need to validate against `swim_api_keys` table.

**File to modify:** `scripts/swim_ws_server.php`

**Current code (line ~180):**
```php
// Debug mode - accept any key
if ($this->config['debug']) {
    return 'pro';  // Give full access in debug
}
```

**Replace with:**
```php
// Validate API key against database
$sql = "SELECT tier, is_active FROM dbo.swim_api_keys WHERE api_key = ?";
$stmt = sqlsrv_query($this->dbConn, $sql, [$apiKey]);
if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    if ($row['is_active']) {
        return $row['tier'];
    }
}
return null;  // Invalid key
```

**Also need:** Database connection in WebSocket server (currently only ADL daemon has it).

### 3. Rate Limiting by Tier (~1 hour)

**Tier limits (from design doc):**

| Tier | Max Connections | Rate Limit |
|------|-----------------|------------|
| Free | 5 | 10 msg/sec |
| Basic | 50 | 100 msg/sec |
| Pro | 500 | 1000 msg/sec |
| Enterprise | Unlimited | Unlimited |

**Implementation location:** `scripts/swim_ws_server.php` in `onOpen()` method

---

## Phase 3 Tasks

### 3.1 Redis IPC (4-6 hours)

Replace file-based event queue with Redis pub/sub for lower latency.

**Current flow:**
```
ADL Daemon → /tmp/swim_ws_events.json → WS Server polls file
```

**Target flow:**
```
ADL Daemon → Redis PUBLISH → WS Server SUBSCRIBE (instant)
```

**Files to modify:**
- `scripts/swim_ws_events.php` - Publish to Redis instead of file
- `scripts/swim_ws_server.php` - Subscribe to Redis channel

**Redis commands:**
```php
// Publisher (in swim_ws_events.php)
$redis->publish('swim:events', json_encode($events));

// Subscriber (in swim_ws_server.php)
$redis->subscribe(['swim:events'], function($redis, $channel, $message) {
    $this->broadcastEvents(json_decode($message, true));
});
```

### 3.2 Message Compression (2 hours)

Add gzip for position batches when `flight.positions` enabled.

```php
// In WebSocket server broadcast
if ($event['type'] === 'flight.positions' && count($event['data']) > 100) {
    $compressed = gzencode(json_encode($event));
    // Send with compression header
}
```

### 3.3 Historical Replay (8 hours)

Allow clients to request missed events after reconnect.

**New message type:**
```json
{
    "action": "replay",
    "since": "2026-01-16T14:00:00Z",
    "channels": ["flight.departed", "flight.arrived"]
}
```

**Requires:** Event storage table (currently events are ephemeral)

### 3.4 Client SDKs (12 hours each)

Create libraries for:
- Python (`swim-client-python`) ✅ COMPLETE
- C# (`SWIM.Client`)
- Java (`swim-client-java`)

**Python SDK Location:** `sdk/python/`

**Installation:**
```bash
cd sdk/python
pip install -e .
```

**Quick Start:**
```python
from swim_client import SWIMClient

client = SWIMClient('your-api-key')

@client.on('flight.departed')
def on_departure(data, timestamp):
    print(f"{data.callsign} departed {data.dep}")

client.subscribe(['flight.departed', 'flight.arrived'])
client.run()
```

**Examples:**
- `examples/basic_example.py` - Simple event handling
- `examples/airport_monitor.py` - Monitor specific airports
- `examples/position_tracker.py` - Track flight positions
- `examples/tmi_monitor.py` - Monitor Ground Stops, GDPs

### 3.5 Metrics Dashboard (4 hours)

Track and display:
- Active connections by tier
- Events per minute by type
- Average latency (ADL refresh → client delivery)
- Error rates

---

## File Locations

| File | Purpose |
|------|---------|
| `scripts/swim_ws_server.php` | WebSocket server daemon |
| `scripts/swim_ws_events.php` | Event detection queries |
| `scripts/vatsim_adl_daemon.php` | Main daemon (calls event detection) |
| `scripts/startup.sh` | Azure startup (starts WS server) |
| `api/swim/v1/ws/swim-ws-client.js` | JavaScript client library |
| `docs/swim/SWIM_Phase2_RealTime_Design.md` | Design documentation |

---

## Commands Reference

### Start/Restart Services (Azure SSH)

```bash
# WebSocket server
rm -f /home/site/wwwroot/scripts/swim_ws.lock
nohup php /home/site/wwwroot/scripts/swim_ws_server.php --debug > /home/LogFiles/swim_ws.log 2>&1 &

# ADL daemon
pkill -f vatsim_adl_daemon
rm -f /home/site/wwwroot/scripts/vatsim_adl.lock
nohup php /home/site/wwwroot/scripts/vatsim_adl_daemon.php > /home/LogFiles/vatsim_adl.log 2>&1 &
```

### Check Status

```bash
# Running processes
ps aux | grep -E "swim_ws|vatsim_adl" | grep -v grep

# WebSocket log
tail -f /home/LogFiles/swim_ws.log

# ADL daemon log (look for ws_events)
tail -f /home/LogFiles/vatsim_adl.log
```

### Test Event Detection

```bash
cat > /tmp/test_events.php << 'PHPEOF'
<?php
require '/home/site/wwwroot/load/config.php';
require '/home/site/wwwroot/scripts/swim_ws_events.php';
$conn = sqlsrv_connect(ADL_SQL_HOST, [
    'Database' => ADL_SQL_DATABASE,
    'Uid' => ADL_SQL_USERNAME,
    'PWD' => ADL_SQL_PASSWORD,
    'Encrypt' => true,
    'TrustServerCertificate' => false
]);
$lastRefresh = gmdate('Y-m-d H:i:s', strtotime('-60 seconds'));
$result = swim_processWebSocketEvents($conn, $lastRefresh, false);
print_r($result);
PHPEOF
php /tmp/test_events.php
```

### Test Internal WebSocket

```bash
curl -i -N \
  -H "Connection: Upgrade" \
  -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  -H "Sec-WebSocket-Version: 13" \
  "http://localhost:8090/?api_key=test-key"
```

---

## Database Schema Reference

### Event Detection Queries Use:

| Table | Columns Used |
|-------|--------------|
| `adl_flight_core` | `flight_uid`, `callsign`, `is_active`, `first_seen_utc`, `last_seen_utc` |
| `adl_flight_plan` | `flight_uid`, `fp_dept_icao`, `fp_dest_icao`, `aircraft_equip`, `fp_route` |
| `adl_flight_times` | `flight_uid`, `off_utc`, `in_utc` |
| `adl_flight_position` | `flight_uid`, `lat`, `lon`, `altitude_ft`, `groundspeed_kts`, `heading_deg` |
| `tmi_programs` | `program_id`, `program_type`, `airport_icao`, `start_time`, `end_time`, `status`, `created_at` |

### API Keys Table:

```sql
-- swim_api_keys (in SWIM_API database)
CREATE TABLE swim_api_keys (
    api_key VARCHAR(64) PRIMARY KEY,
    owner_name VARCHAR(100),
    owner_email VARCHAR(100),
    tier VARCHAR(20) DEFAULT 'free',  -- free, basic, pro, enterprise
    is_active BIT DEFAULT 1,
    created_at DATETIME2 DEFAULT GETUTCDATE(),
    last_used_at DATETIME2
);
```

---

## Priority Order for Completion

1. **External WSS test** - Verify after next container restart
2. **Database auth** - Replace debug mode
3. **Tier rate limits** - Enforce connection limits
4. **Redis IPC** - Phase 3 priority (lower latency)
5. **Client SDKs** - As consumers request them

---

## Success Metrics

| Metric | Target | Current |
|--------|--------|---------|
| Event latency | < 15 seconds | ~15 sec (ADL cycle) |
| Events detected | 10-50 per cycle | 2-10 observed |
| Internal WS test | ✅ Working | ✅ Done |
| External WS test | Pending | After restart |
| Auth validation | DB-backed | Debug mode |
