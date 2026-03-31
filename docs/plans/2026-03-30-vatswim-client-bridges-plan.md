# VATSWIM Client Bridges Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make VATSWIM bidirectional by building 5 bridges that deliver enriched SWIM data to pilots and controllers via Hoppie CPDLC, FSD protocol, EuroScope tags, a web portal, and simulator integration.

**Architecture:** Server-side PHP (Bridge 1) hooks into existing ADL/TMI daemons to trigger CPDLC delivery. A standalone Go binary (Bridge 2) subscribes to SWIM WebSocket and serves FSD packets to CRC/EuroScope. A C++ EuroScope plugin (Bridge 3) provides deep tag enrichment with embedded FSD fallback. A Vue 3 web app (Bridge 4) gives pilots TOS filing and route display. A C/C++ sim daemon (Bridge 5) enables bidirectional telemetry with FMS injection.

**Tech Stack:** PHP 8.2 (Bridge 1), Go 1.22+ (Bridge 2), C++17 / EuroScope Plugin SDK (Bridge 3), Vue 3 / MapLibre GL (Bridge 4), C/C++ / SimConnect / XPLM (Bridge 5)

**Design Doc:** `docs/plans/2026-03-30-vatswim-client-bridges-design.md`

**Constraint:** Bridge 2 builds all code/logic but does NOT provision the Azure B2s VM or configure Azure infrastructure. The server can be spun up later.

---

## Bridge 1: HoppieWriter (PHP, Server-Side)

**Summary:** Activate the existing `EDCTDelivery` multi-channel delivery system and extend it from EDCT-only to the full TMI message catalog (20+ message types). Wire into ADL daemon TMI sync and vIFF CDM poll daemon.

---

### Task 1.1: Add New Message Type Constants to CDMService

**Files:**
- Modify: `load/services/CDMService.php:38-43`

**Step 1: Read CDMService.php to confirm current constants**

Open `load/services/CDMService.php` and verify lines 38-43 contain the existing 6 message type constants.

**Step 2: Add new message type constants**

Add after the existing constants (line 43):

```php
    // Extended TMI message types (Bridge 1: HoppieWriter)
    const MSG_EDCT_AMENDED  = 'EDCT_AMENDED';
    const MSG_EDCT_CANCEL   = 'EDCT_CANCEL';
    const MSG_CTOT          = 'CTOT';
    const MSG_GS_HOLD       = 'GS_HOLD';
    const MSG_GS_RELEASE    = 'GS_RELEASE';
    const MSG_REROUTE       = 'REROUTE';
    const MSG_FLOW_MEASURE  = 'FLOW_MEASURE';
    const MSG_MIT           = 'MIT';
    const MSG_AFP           = 'AFP';
    const MSG_METERING      = 'METERING';
    const MSG_HOLD          = 'HOLD';
    const MSG_CTP_SLOT      = 'CTP_SLOT';
    const MSG_WEATHER_REROUTE = 'WEATHER_REROUTE';
    const MSG_TOS_QUERY     = 'TOS_QUERY';
    const MSG_TOS_ACK       = 'TOS_ACK';
    const MSG_TOS_ASSIGN    = 'TOS_ASSIGN';
    const MSG_TRAFFIC_ADV   = 'TRAFFIC_ADVISORY';
```

**Step 3: Commit**

```bash
git add load/services/CDMService.php
git commit -m "feat(cdm): add extended TMI message type constants for Bridge 1 HoppieWriter"
```

---

### Task 1.2: Add Controller Configuration Columns

**Files:**
- Create: `database/migrations/tmi/056_bridge1_delivery_config.sql`

**Step 1: Write the migration**

```sql
-- Migration 056: Bridge 1 delivery configuration columns
-- Adds controller-configurable delivery mode for reroutes and GS release follow-on

-- Reroute delivery mode: VOICE (standby for voice clearance) or DELIVERY (contact delivery freq)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.tmi_reroutes') AND name = 'delivery_mode')
BEGIN
    ALTER TABLE dbo.tmi_reroutes ADD delivery_mode VARCHAR(10) NOT NULL DEFAULT 'VOICE';
END
GO

-- Delivery frequency (only used when delivery_mode = 'DELIVERY')
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.tmi_reroutes') AND name = 'delivery_freq')
BEGIN
    ALTER TABLE dbo.tmi_reroutes ADD delivery_freq VARCHAR(10) NULL;
END
GO

-- GS release follow-on: GDP_ACTIVE (flights may get new EDCTs) or RELEASED (depart when ready)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.tmi_programs') AND name = 'gs_release_followon')
BEGIN
    ALTER TABLE dbo.tmi_programs ADD gs_release_followon VARCHAR(12) NOT NULL DEFAULT 'RELEASED';
END
GO

-- Message delivery tracking: which flights have been notified of which TMI changes
IF NOT EXISTS (SELECT 1 FROM sys.objects WHERE name = 'tmi_delivery_log' AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_delivery_log (
        log_id          BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid      BIGINT NOT NULL,
        callsign        VARCHAR(10) NOT NULL,
        message_type    VARCHAR(20) NOT NULL,
        message_hash    VARCHAR(64) NOT NULL,   -- SHA256 of message body (dedup)
        edct_utc        DATETIME2 NULL,         -- EDCT value at time of delivery
        program_id      INT NULL,
        delivered_utc   DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        channels_sent   VARCHAR(50) NULL,       -- e.g. 'cpdlc,web,discord'
        ack_type        VARCHAR(10) NULL,       -- WILCO, UNABLE, STANDBY, NULL
        ack_utc         DATETIME2 NULL,

        INDEX IX_delivery_log_flight (flight_uid, delivered_utc DESC),
        INDEX IX_delivery_log_hash (flight_uid, message_hash)
    );
END
GO
```

**Step 2: Commit**

```bash
git add database/migrations/tmi/056_bridge1_delivery_config.sql
git commit -m "feat(tmi): add delivery config columns and delivery log table (migration 056)"
```

---

### Task 1.3: Add TMI Message Formatting Methods to EDCTDelivery

**Files:**
- Modify: `load/services/EDCTDelivery.php:65-100`

**Step 1: Read EDCTDelivery.php lines 65-100 to see existing formatters**

Verify `formatEDCTMessage()` at line 65, `formatGateHoldMessage()` at 79, `formatGateReleaseMessage()` at 88, `formatCancelMessage()` at 97.

**Step 2: Add all TMI message formatters after line 100**

Insert after the `formatCancelMessage()` method (after line 100, before the `// MULTI-CHANNEL DELIVERY` comment at line 102):

```php
    // =========================================================================
    // EXTENDED TMI MESSAGE FORMATTING (Bridge 1: HoppieWriter)
    // =========================================================================

    /**
     * Format EDCT amended message.
     */
    public function formatEDCTAmendedMessage(string $new_edct_utc, string $prev_edct_utc): string
    {
        $newHhmm = date('Hi', strtotime($new_edct_utc));
        $prevHhmm = date('Hi', strtotime($prev_edct_utc));
        return "REVISED EDCT {$newHhmm}Z. PREVIOUS {$prevHhmm}Z";
    }

    /**
     * Format EDCT cancellation message.
     */
    public function formatEDCTCancelMessage(string $edct_utc): string
    {
        $hhmm = date('Hi', strtotime($edct_utc));
        return "DISREGARD EDCT {$hhmm}Z. DEPART WHEN READY";
    }

    /**
     * Format CTOT (vIFF/ECFMP regulation) message.
     */
    public function formatCTOTMessage(string $ctot_utc, ?string $regulation_id = null): string
    {
        $hhmm = date('Hi', strtotime($ctot_utc));
        $msg = "CALCULATED TAKEOFF TIME {$hhmm}Z";
        if ($regulation_id) {
            $msg .= ". CTOT REGULATION $regulation_id";
        }
        return $msg;
    }

    /**
     * Format ground stop hold message.
     */
    public function formatGSHoldMessage(string $dest, ?string $expect_update_utc = null): string
    {
        $msg = "GROUND STOP IN EFFECT FOR $dest. HOLD FOR RELEASE.";
        if ($expect_update_utc) {
            $hhmm = date('Hi', strtotime($expect_update_utc));
            $msg .= " EXPECT UPDATE BY {$hhmm}Z";
        }
        return $msg;
    }

    /**
     * Format ground stop release message.
     * @param string $followon 'GDP_ACTIVE' or 'RELEASED'
     */
    public function formatGSReleaseMessage(string $dest, string $followon = 'RELEASED'): string
    {
        if ($followon === 'GDP_ACTIVE') {
            return "GROUND STOP RLSD FOR $dest. FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE FLOW PROGRAM";
        }
        return "GROUND STOP RLSD FOR $dest. DISREGARD EDCT & DEPART WHEN READY";
    }

    /**
     * Format reroute advisory message.
     * @param string $delivery_mode 'VOICE' or 'DELIVERY'
     */
    public function formatRerouteMessage(
        string $advisory_num,
        string $route,
        string $delivery_mode = 'VOICE',
        ?string $delivery_freq = null
    ): string {
        if ($delivery_mode === 'DELIVERY' && $delivery_freq) {
            return "REROUTE ADVISORY $advisory_num. AMEND ROUTE TO $route OR CONTACT DELIVERY AT $delivery_freq FOR AMENDED CLEARANCE";
        }
        return "REROUTE ADVISORY $advisory_num. AMEND ROUTE TO $route OR STANDBY FOR VOICE CLEARANCE";
    }

    /**
     * Format ECFMP/vIFF flow measure message.
     */
    public function formatFlowMeasureMessage(string $measure_type, string $value, string $fir): string
    {
        return "FLOW RESTRICTION: $measure_type $value FOR $fir";
    }

    /**
     * Format MIT (Miles In Trail) message.
     */
    public function formatMITMessage(int $miles, string $fix): string
    {
        return "MILES IN TRAIL {$miles}NM IN EFFECT AT $fix. EXPECT DELAY.";
    }

    /**
     * Format AFP (Airspace Flow Program) message.
     */
    public function formatAFPMessage(string $airspace, int $rate, int $delay_min): string
    {
        return "AIRSPACE FLOW PROGRAM IN EFFECT FOR $airspace. $rate FLIGHTS PER HOUR. EXPECT DELAY $delay_min MIN.";
    }

    /**
     * Format metering fix time message (SimTraffic TBFM / AMAN).
     */
    public function formatMeteringMessage(string $fix, string $sta_utc): string
    {
        $hhmm = date('Hi', strtotime($sta_utc));
        return "CROSS $fix AT {$hhmm}Z. SCHEDULED TIME OF ARRIVAL {$hhmm}Z.";
    }

    /**
     * Format hold advisory message.
     */
    public function formatHoldMessage(string $fix, ?string $efc_utc = null): string
    {
        $msg = "EXPECT HOLDING AT $fix.";
        if ($efc_utc) {
            $hhmm = date('Hi', strtotime($efc_utc));
            $msg .= " EXPECT FURTHER CLEARANCE {$hhmm}Z.";
        }
        return $msg;
    }

    /**
     * Format CTP slot assignment message.
     */
    public function formatCTPSlotMessage(string $entry_fix, string $slot_utc, string $route): string
    {
        $hhmm = date('Hi', strtotime($slot_utc));
        return "CTP SLOT ASSIGNED: $entry_fix AT {$hhmm}Z. ROUTE: $route. CONFIRM ACCEPTANCE.";
    }

    /**
     * Format weather reroute suggestion.
     */
    public function formatWeatherRerouteMessage(string $area, string $route): string
    {
        return "CONVECTIVE ACTIVITY NEAR $area. SUGGESTED DEVIATION: $route. PILOT DISCRETION.";
    }

    /**
     * Format TOS query message.
     */
    public function formatTOSQueryMessage(string $dep, string $dest): string
    {
        return "TRAJECTORY OPTIONS REQUESTED FOR $dep-$dest. FILE VIA PILOT CLIENT OR VATSWIM.";
    }

    /**
     * Format TOS acknowledgment message.
     */
    public function formatTOSAckMessage(int $count): string
    {
        return "$count TRAJECTORY OPTIONS ON FILE. STANDBY FOR ASSIGNMENT.";
    }

    /**
     * Format TOS assignment message.
     * Uses short form if route fits in CPDLC, advisory reference if too long.
     */
    public function formatTOSAssignMessage(int $option_num, string $route, string $reason, ?string $advisory_num = null): string
    {
        // CPDLC max practical length ~200 chars. If route is short enough, include inline.
        $inline = "TRAJECTORY OPTION $option_num ASSIGNED: $route. REASON: $reason.";
        if (strlen($inline) <= 200) {
            return $inline;
        }
        // Too long — reference advisory
        if ($advisory_num) {
            return "TRAJECTORY OPTION $option_num ASSIGNED PER ADVISORY $advisory_num. CHECK PILOT CLIENT FOR ROUTE DETAIL.";
        }
        return "TRAJECTORY OPTION $option_num ASSIGNED. CHECK PILOT CLIENT FOR ROUTE DETAIL. REASON: $reason.";
    }

    /**
     * Format traffic advisory messages (controller-initiated).
     */
    public function formatTrafficAdvisory(string $type, string $facility, ?string $options = null): string
    {
        switch ($type) {
            case 'arrival_volume':
                return "HIGH ARRIVAL VOLUME FOR $facility. SUGGEST REDIRECTING TO $options TO AVOID EXCESSIVE DELAYS.";
            case 'departure_volume':
                return "HIGH DEPARTURE VOLUME OVER $facility. SUGGEST REROUTING OVER $options TO AVOID EXCESSIVE DELAYS.";
            case 'reroute_fuel':
                return "REROUTE/S IN EFFECT $facility. USERS SHOULD FUEL ACCORDINGLY.";
            case 'delay_fuel':
                return "DELAYS $facility. USERS SHOULD FUEL ACCORDINGLY.";
            default:
                return "TRAFFIC ADVISORY FOR $facility: $options";
        }
    }
```

**Step 3: Commit**

```bash
git add load/services/EDCTDelivery.php
git commit -m "feat(edct): add full TMI message catalog formatters (20+ message types)"
```

---

### Task 1.4: Add Generic Multi-Channel Delivery Method

**Files:**
- Modify: `load/services/EDCTDelivery.php` (after `deliverGateRelease()`, around line 217)

**Step 1: Add a generic deliverMessage() method**

Insert after the `deliverGateRelease()` method (after line 217):

```php
    /**
     * Generic multi-channel delivery for any TMI message type.
     * Used by all extended message types (CTOT, GS, reroute, flow, etc.)
     *
     * @param int         $flight_uid
     * @param string      $callsign
     * @param string      $message_type  CDMService::MSG_* constant
     * @param string      $message_body  Pre-formatted CPDLC text
     * @param string|null $time_utc      Relevant time for the message (for WebSocket/Discord display)
     * @param int|null    $cid           VATSIM CID
     * @param int|null    $program_id
     * @param int|null    $slot_id
     * @return array Results per channel
     */
    public function deliverMessage(
        int $flight_uid,
        string $callsign,
        string $message_type,
        string $message_body,
        ?string $time_utc = null,
        ?int $cid = null,
        ?int $program_id = null,
        ?int $slot_id = null
    ): array {
        $results = [];

        // During hibernation, queue but don't deliver
        if ($this->is_hibernation) {
            $msg_id = $this->cdm->queueMessage(
                $flight_uid, $callsign, $message_type,
                $message_body, 'all', $cid, $program_id, $slot_id
            );
            $results['hibernation_queued'] = $msg_id !== false;
            $this->log("$message_type hibernation-queued for $callsign");
            return $results;
        }

        // Deduplicate: check if identical message was already sent recently
        if ($this->isDuplicateMessage($flight_uid, $message_body)) {
            $this->log("$message_type skipped (duplicate) for $callsign");
            return ['skipped' => 'duplicate'];
        }

        // Channel 1: CPDLC (if Hoppie is configured)
        $results['cpdlc'] = $this->deliverViaCPDLC($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id);

        // Channel 2: Pilot client plugin (queued for polling)
        $results['vpilot'] = $this->queueForPlugin($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id);

        // Channel 3: Web dashboard (via WebSocket)
        $results['web'] = $this->deliverViaWebSocket($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id, $time_utc);

        // Channel 4: Discord DM (if CID is linked)
        if ($cid) {
            $results['discord'] = $this->deliverViaDiscord($flight_uid, $callsign, $message_body, $cid, $program_id, $slot_id, $time_utc, $message_type);
        }

        // Log to delivery tracking table
        $this->logDelivery($flight_uid, $callsign, $message_type, $message_body, $results, $program_id);

        $delivered = array_filter($results, fn($r) => $r === true || (is_array($r) && ($r['sent'] ?? false)));
        $this->log("$message_type delivered for $callsign: " . count($delivered) . "/" . count($results) . " channels");

        return $results;
    }

    /**
     * Check if this exact message was already delivered to this flight recently.
     */
    private function isDuplicateMessage(int $flight_uid, string $message_body): bool
    {
        $hash = hash('sha256', $message_body);
        $sql = "SELECT TOP 1 1 FROM dbo.tmi_delivery_log
                WHERE flight_uid = ? AND message_hash = ?
                  AND delivered_utc > DATEADD(MINUTE, -5, SYSUTCDATETIME())";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$flight_uid, $hash]);
        if ($stmt === false) return false;
        $exists = sqlsrv_fetch_array($stmt) !== null;
        sqlsrv_free_stmt($stmt);
        return $exists;
    }

    /**
     * Log delivery to tmi_delivery_log for tracking and deduplication.
     */
    private function logDelivery(
        int $flight_uid,
        string $callsign,
        string $message_type,
        string $message_body,
        array $results,
        ?int $program_id
    ): void {
        $hash = hash('sha256', $message_body);
        $channels = [];
        foreach ($results as $ch => $r) {
            if ($r === true || (is_array($r) && ($r['sent'] ?? false))) {
                $channels[] = $ch;
            }
        }
        $channelStr = implode(',', $channels) ?: 'none';

        $sql = "INSERT INTO dbo.tmi_delivery_log (flight_uid, callsign, message_type, message_hash, program_id, channels_sent)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$flight_uid, $callsign, $message_type, $hash, $program_id, $channelStr]);
        if ($stmt !== false) sqlsrv_free_stmt($stmt);
    }
```

**Step 2: Commit**

```bash
git add load/services/EDCTDelivery.php
git commit -m "feat(edct): add generic deliverMessage() with dedup and delivery logging"
```

---

### Task 1.5: Wire EDCTDelivery into ADL Daemon TMI Sync

**Files:**
- Modify: `scripts/vatsim_adl_daemon.php:39-41` (add require)
- Modify: `scripts/vatsim_adl_daemon.php:1889` (add parameter)
- Modify: `scripts/vatsim_adl_daemon.php:2128-2131` (add delivery hook)
- Modify: `scripts/vatsim_adl_daemon.php:3028-3030` (pass delivery service)

This is the critical wiring task. The TMI sync runs every 60s. After it updates `adl_flight_tmi`, we detect which flights got new/changed EDCTs and trigger delivery.

**Step 1: Add require for EDCTDelivery at top of daemon**

After line 41 (`require_once __DIR__ . '/swim_ws_events.php';`), add:

```php
require_once dirname(__DIR__) . '/load/services/EDCTDelivery.php';
```

**Step 2: Add EDCT change detection inside executeDeferredTMISync()**

The key insight: the `#TmiSync` temp table contains the new TMI state. We need to compare it with the current `adl_flight_tmi` state BEFORE the UPDATE to detect changes. Add a change detection query between lines 2098 and 2100 (after the ENSURE step, before the UPDATE).

Insert after line 2098 (`if ($ensureStmt !== false) sqlsrv_free_stmt($ensureStmt);`):

```php
    // ── Step 4b: Detect EDCT changes (before UPDATE overwrites old values) ──
    $changesSql = "
        SELECT s.flight_uid, s.edct_utc AS new_edct,
               t.edct_utc AS prev_edct,
               s.ctl_type, s.ctl_prgm, s.ctl_element,
               s.program_id, s.slot_id,
               s.gs_held, s.gs_release_utc,
               fc.callsign, fc.cid
        FROM #TmiSync s
        INNER JOIN dbo.adl_flight_tmi t ON s.flight_uid = t.flight_uid
        LEFT JOIN dbo.adl_flight_core fc ON s.flight_uid = fc.flight_uid
        WHERE (
            -- New EDCT assigned (was null, now has value)
            (t.edct_utc IS NULL AND s.edct_utc IS NOT NULL)
            -- EDCT value changed
            OR (t.edct_utc IS NOT NULL AND s.edct_utc IS NOT NULL AND t.edct_utc != s.edct_utc)
            -- EDCT cancelled (had value, now null)
            OR (t.edct_utc IS NOT NULL AND s.edct_utc IS NULL)
            -- GS held status changed
            OR (ISNULL(t.gs_held, 0) != ISNULL(s.gs_held, 0))
            -- GS release time changed
            OR (t.gs_release_utc IS NULL AND s.gs_release_utc IS NOT NULL)
        )
    ";
    $changesStmt = sqlsrv_query($conn_adl, $changesSql, [], ['QueryTimeout' => 10]);
    $edctChanges = [];
    if ($changesStmt !== false) {
        while ($ch = sqlsrv_fetch_array($changesStmt, SQLSRV_FETCH_ASSOC)) {
            $edctChanges[] = $ch;
        }
        sqlsrv_free_stmt($changesStmt);
    }
```

**Step 3: Add delivery dispatch after the UPDATE (after line 2131)**

Insert after line 2131 (after `sqlsrv_free_stmt($updateStmt);` inside the if block):

```php
        // ── Step 4c: Deliver EDCT/TMI changes via HoppieWriter ──────────────
        if (!empty($edctChanges) && isset($edctDelivery)) {
            foreach ($edctChanges as $change) {
                $fuid = (int)$change['flight_uid'];
                $cs = $change['callsign'] ?? null;
                $cid = isset($change['cid']) ? (int)$change['cid'] : null;
                $pid = isset($change['program_id']) ? (int)$change['program_id'] : null;
                $sid = isset($change['slot_id']) ? (int)$change['slot_id'] : null;

                if (!$cs) continue; // No callsign, skip

                try {
                    $prevEdct = $change['prev_edct'];
                    $newEdct = $change['new_edct'];
                    $gsHeld = (int)($change['gs_held'] ?? 0);
                    $gsRelease = $change['gs_release_utc'];

                    if ($prevEdct instanceof DateTimeInterface) $prevEdct = $prevEdct->format('Y-m-d H:i:s');
                    if ($newEdct instanceof DateTimeInterface) $newEdct = $newEdct->format('Y-m-d H:i:s');
                    if ($gsRelease instanceof DateTimeInterface) $gsRelease = $gsRelease->format('Y-m-d H:i:s');

                    // GS release takes priority
                    if ($gsRelease !== null && ($change['prev_edct'] === null || $prevEdct !== null)) {
                        $dest = $change['ctl_element'] ?? 'UNKNOWN';
                        // Look up follow-on config from tmi_programs
                        $followon = 'RELEASED';
                        if ($pid && $conn_tmi) {
                            $foSql = "SELECT gs_release_followon FROM dbo.tmi_programs WHERE program_id = ?";
                            $foStmt = sqlsrv_query($conn_tmi, $foSql, [$pid]);
                            if ($foStmt !== false) {
                                $foRow = sqlsrv_fetch_array($foStmt, SQLSRV_FETCH_ASSOC);
                                if ($foRow) $followon = $foRow['gs_release_followon'] ?? 'RELEASED';
                                sqlsrv_free_stmt($foStmt);
                            }
                        }
                        $msg = $edctDelivery->formatGSReleaseMessage($dest, $followon);
                        $edctDelivery->deliverMessage($fuid, $cs, CDMService::MSG_GS_RELEASE, $msg, $gsRelease, $cid, $pid, $sid);
                    }
                    // GS hold
                    elseif ($gsHeld === 1 && $newEdct === null) {
                        $dest = $change['ctl_element'] ?? 'UNKNOWN';
                        $msg = $edctDelivery->formatGSHoldMessage($dest);
                        $edctDelivery->deliverMessage($fuid, $cs, CDMService::MSG_GS_HOLD, $msg, null, $cid, $pid, $sid);
                    }
                    // EDCT cancelled
                    elseif ($prevEdct !== null && $newEdct === null) {
                        $msg = $edctDelivery->formatEDCTCancelMessage($prevEdct);
                        $edctDelivery->deliverMessage($fuid, $cs, CDMService::MSG_EDCT_CANCEL, $msg, null, $cid, $pid, $sid);
                    }
                    // EDCT amended
                    elseif ($prevEdct !== null && $newEdct !== null && $prevEdct !== $newEdct) {
                        $msg = $edctDelivery->formatEDCTAmendedMessage($newEdct, $prevEdct);
                        $edctDelivery->deliverMessage($fuid, $cs, CDMService::MSG_EDCT_AMENDED, $msg, $newEdct, $cid, $pid, $sid);
                    }
                    // New EDCT assigned
                    elseif ($prevEdct === null && $newEdct !== null) {
                        $reason = trim(($change['ctl_type'] ?? '') . ' ' . ($change['ctl_prgm'] ?? '') . ' ' . ($change['ctl_element'] ?? ''));
                        $edctDelivery->deliverEDCT($fuid, $cs, $newEdct, $reason, $cid, $pid, $sid);
                    }
                } catch (Throwable $e) {
                    logWarn("EDCT delivery error for $cs: " . $e->getMessage());
                }
            }
        }
```

**Step 4: Initialize EDCTDelivery in the main loop and pass to sync function**

At the call site (around line 3028-3030), the daemon needs a SWIM connection for CDMService. Modify the TMI sync block.

Before the `executeDeferredTMISync()` call (around line 3028), add initialization:

```php
                // Initialize EDCTDelivery (lazy, once per TMI cycle)
                static $edctDelivery = null;
                if ($edctDelivery === null) {
                    // CDMService needs conn_swim for reads
                    $swimConn = null;
                    if (defined('SWIM_SQL_HOST') && SWIM_SQL_HOST) {
                        $swimConn = sqlsrv_connect(SWIM_SQL_HOST, [
                            'Database' => SWIM_SQL_DATABASE,
                            'UID'      => SWIM_SQL_USERNAME,
                            'PWD'      => SWIM_SQL_PASSWORD,
                            'Encrypt'  => true,
                            'TrustServerCertificate' => false,
                            'LoginTimeout' => 10,
                        ]);
                    }
                    $cdmService = new CDMService($swimConn, $conn_tmi, $conn);
                    $edctDelivery = new EDCTDelivery($cdmService, $conn_tmi, false);
                    logInfo("EDCTDelivery initialized for TMI message delivery");
                }
```

And modify `executeDeferredTMISync` signature to accept the delivery service. This requires changing the function signature at line 1889:

From: `function executeDeferredTMISync($conn_adl, $conn_tmi): ?array {`
To: `function executeDeferredTMISync($conn_adl, $conn_tmi, ?EDCTDelivery $edctDelivery = null): ?array {`

And the call at line 3030:
From: `$tmiSyncResult = executeDeferredTMISync($conn, $conn_tmi);`
To: `$tmiSyncResult = executeDeferredTMISync($conn, $conn_tmi, $edctDelivery);`

**Step 5: Commit**

```bash
git add scripts/vatsim_adl_daemon.php
git commit -m "feat(daemon): wire EDCTDelivery into TMI sync with EDCT change detection"
```

---

### Task 1.6: Wire CTOT Delivery into vIFF CDM Poll Daemon

**Files:**
- Modify: `scripts/viff_cdm_poll_daemon.php`

**Step 1: Read top of viff_cdm_poll_daemon.php for require patterns**

Check existing requires at the top of the file.

**Step 2: Add EDCTDelivery require at top of file**

Add after existing requires:

```php
require_once dirname(__DIR__) . '/load/services/EDCTDelivery.php';
```

**Step 3: Add CTOT delivery after line 862**

The CTOT is written at line 862. After the entire `viff_update_flight()` function's SET clauses are built and the UPDATE is executed, we need to trigger delivery. The best approach is to add the delivery call after the function completes its UPDATE, back at the call site.

Find where `viff_update_flight()` is called in the daemon loop, and add CTOT delivery after a successful update when CTOT changed:

```php
    // After viff_update_flight() returns true and CTOT was in the update:
    if ($updated && !empty($f['ctot'])) {
        $ctotIso = viff_time_to_iso($f['ctot']);
        if ($ctotIso && isset($edctDelivery)) {
            $regId = $f['regulation_id'] ?? $f['measure_id'] ?? null;
            $msg = $edctDelivery->formatCTOTMessage($ctotIso, $regId);
            $callsign = $match['callsign'] ?? null;
            $fuid = (int)($match['flight_uid'] ?? 0);
            $cid = isset($match['cid']) ? (int)$match['cid'] : null;
            if ($callsign && $fuid) {
                $edctDelivery->deliverMessage($fuid, $callsign, CDMService::MSG_CTOT, $msg, $ctotIso, $cid);
            }
        }
    }
```

**Step 4: Commit**

```bash
git add scripts/viff_cdm_poll_daemon.php
git commit -m "feat(viff): wire CTOT delivery into vIFF CDM poll daemon"
```

---

### Task 1.7: Add WebSocket Channels for CDM and AMAN

**Files:**
- Modify: `api/swim/v1/ws/WebSocketServer.php:304-312`

**Step 1: Add new valid channels**

Change the `$validChannels` array at lines 304-312 to include CDM and AMAN channels:

```php
        $validChannels = [
            'flight.position', 'flight.positions',
            'flight.departed', 'flight.arrived',
            'flight.created', 'flight.updated', 'flight.deleted',
            'tmi.issued', 'tmi.modified', 'tmi.released',
            'ctp.slots.optimized', 'ctp.session.updated', 'ctp.edct.assigned',
            'cdm.updated', 'cdm.edct', 'cdm.ctot', 'cdm.gs',
            'aman.sequence', 'aman.updated',
            'tmi.*', 'flight.*', 'system.*', 'ctp.*', 'cdm.*', 'aman.*',
            'system.heartbeat',
        ];
```

**Step 2: Update the wildcard regex validation (line 315)**

From: `!preg_match('/^(flight|tmi|system|ctp)\.\*$/', $channel)`
To: `!preg_match('/^(flight|tmi|system|ctp|cdm|aman)\.\*$/', $channel)`

**Step 3: Commit**

```bash
git add api/swim/v1/ws/WebSocketServer.php
git commit -m "feat(ws): add cdm.* and aman.* WebSocket channels"
```

---

### Task 1.8: Add SWIM REST Endpoints for TOS

**Files:**
- Create: `api/swim/v1/tos/file.php`
- Create: `api/swim/v1/tos/status.php`

**Step 1: Create TOS filing endpoint**

```php
<?php
/**
 * SWIM API — TOS (Trajectory Options Set) Filing
 *
 * POST /api/swim/v1/tos/file.php
 *
 * Accepts pilot-filed ranked route preferences.
 * Rich structured data — no character limit (unlike CPDLC).
 *
 * Body:
 * {
 *   "callsign": "DAL123",
 *   "departure": "KJFK",
 *   "destination": "KLAX",
 *   "options": [
 *     {"rank": 1, "route": "J80 SLT J64 ABQ", "remarks": "preferred"},
 *     {"rank": 2, "route": "J584 MCI J80 ABQ", "remarks": "alternate"}
 *   ]
 * }
 */

include("../../../../load/config.php");
define('PERTI_SWIM_ONLY', true);
include("../../../../load/connect.php");
require_once __DIR__ . '/../common.php';

swim_require_auth('write');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    swim_error(405, 'METHOD_NOT_ALLOWED', 'Use POST');
}

$body = swim_get_json_body();
if (!$body) swim_error(400, 'INVALID_BODY', 'JSON body required');

$callsign = trim($body['callsign'] ?? '');
$dep = strtoupper(trim($body['departure'] ?? ''));
$dest = strtoupper(trim($body['destination'] ?? ''));
$options = $body['options'] ?? [];

if (!$callsign || !$dep || !$dest) {
    swim_error(400, 'MISSING_FIELDS', 'callsign, departure, destination required');
}
if (empty($options) || !is_array($options)) {
    swim_error(400, 'MISSING_OPTIONS', 'At least one trajectory option required');
}
if (count($options) > 10) {
    swim_error(400, 'TOO_MANY_OPTIONS', 'Maximum 10 trajectory options');
}

$conn = get_conn_swim();
if (!$conn) swim_error(503, 'DB_UNAVAILABLE', 'Database unavailable');

// Look up flight
$flightSql = "SELECT flight_uid, cid FROM dbo.swim_flights WHERE callsign = ? AND departure_icao = ? AND arrival_icao = ? AND is_active = 1";
$flightStmt = sqlsrv_query($conn, $flightSql, [$callsign, $dep, $dest]);
$flight = $flightStmt ? sqlsrv_fetch_array($flightStmt, SQLSRV_FETCH_ASSOC) : null;
if ($flightStmt) sqlsrv_free_stmt($flightStmt);

if (!$flight) {
    swim_error(404, 'FLIGHT_NOT_FOUND', "No active flight $callsign $dep-$dest");
}

$flight_uid = (int)$flight['flight_uid'];

// Clear previous TOS for this flight
$clearSql = "DELETE FROM dbo.swim_tos_options WHERE flight_uid = ?";
$clearStmt = sqlsrv_query($conn, $clearSql, [$flight_uid]);
if ($clearStmt) sqlsrv_free_stmt($clearStmt);

// Insert new options
$inserted = 0;
foreach ($options as $opt) {
    $rank = (int)($opt['rank'] ?? ($inserted + 1));
    $route = trim($opt['route'] ?? '');
    $remarks = trim($opt['remarks'] ?? '');
    if (!$route) continue;

    $insSql = "INSERT INTO dbo.swim_tos_options (flight_uid, callsign, rank_order, route_string, remarks, filed_utc)
               VALUES (?, ?, ?, ?, ?, SYSUTCDATETIME())";
    $insStmt = sqlsrv_query($conn, $insSql, [$flight_uid, $callsign, $rank, $route, $remarks]);
    if ($insStmt !== false) {
        $inserted++;
        sqlsrv_free_stmt($insStmt);
    }
}

swim_json([
    'status' => 'ok',
    'flight_uid' => $flight_uid,
    'callsign' => $callsign,
    'options_filed' => $inserted,
]);
```

**Step 2: Create TOS status endpoint**

```php
<?php
/**
 * SWIM API — TOS Status
 *
 * GET /api/swim/v1/tos/status.php?callsign=DAL123
 *
 * Returns filed TOS options and any assignment.
 */

include("../../../../load/config.php");
define('PERTI_SWIM_ONLY', true);
include("../../../../load/connect.php");
require_once __DIR__ . '/../common.php';

swim_require_auth('read');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    swim_error(405, 'METHOD_NOT_ALLOWED', 'Use GET');
}

$callsign = trim($_GET['callsign'] ?? '');
if (!$callsign) swim_error(400, 'MISSING_CALLSIGN', 'callsign parameter required');

$conn = get_conn_swim();
if (!$conn) swim_error(503, 'DB_UNAVAILABLE', 'Database unavailable');

$sql = "SELECT tos.*, sf.departure_icao, sf.arrival_icao
        FROM dbo.swim_tos_options tos
        INNER JOIN dbo.swim_flights sf ON tos.flight_uid = sf.flight_uid AND sf.is_active = 1
        WHERE tos.callsign = ?
        ORDER BY tos.rank_order ASC";
$stmt = sqlsrv_query($conn, $sql, [$callsign]);

$options = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $options[] = [
            'rank' => (int)$row['rank_order'],
            'route' => $row['route_string'],
            'remarks' => $row['remarks'],
            'filed_utc' => $row['filed_utc'] instanceof DateTimeInterface ? $row['filed_utc']->format('Y-m-d\TH:i:s\Z') : $row['filed_utc'],
            'assigned' => (bool)($row['is_assigned'] ?? false),
            'assigned_utc' => $row['assigned_utc'] ?? null,
            'assignment_reason' => $row['assignment_reason'] ?? null,
        ];
    }
    sqlsrv_free_stmt($stmt);
}

swim_json([
    'callsign' => $callsign,
    'options_count' => count($options),
    'options' => $options,
]);
```

**Step 3: Create TOS table migration**

Create `database/migrations/swim/016_tos_options.sql`:

```sql
-- Migration 016: Trajectory Options Set (TOS) table
IF NOT EXISTS (SELECT 1 FROM sys.objects WHERE name = 'swim_tos_options' AND type = 'U')
BEGIN
    CREATE TABLE dbo.swim_tos_options (
        tos_id          BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_uid      BIGINT NOT NULL,
        callsign        VARCHAR(10) NOT NULL,
        rank_order      INT NOT NULL,
        route_string    VARCHAR(2000) NOT NULL,
        remarks         VARCHAR(500) NULL,
        filed_utc       DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        is_assigned     BIT NOT NULL DEFAULT 0,
        assigned_utc    DATETIME2 NULL,
        assignment_reason VARCHAR(200) NULL,

        INDEX IX_tos_flight (flight_uid),
        INDEX IX_tos_callsign (callsign)
    );
END
GO
```

**Step 4: Commit**

```bash
git add api/swim/v1/tos/file.php api/swim/v1/tos/status.php database/migrations/swim/016_tos_options.sql
git commit -m "feat(swim): add TOS filing and status API endpoints with migration"
```

---

### Task 1.9: Add SWIM AMAN Ingest Endpoint

**Files:**
- Create: `api/swim/v1/ingest/aman.php`

**Step 1: Create the AMAN ingest endpoint**

```php
<?php
/**
 * SWIM API — AMAN Sequence Ingest
 *
 * POST /api/swim/v1/ingest/aman.php
 *
 * Accepts arrival sequencing data from AMAN tools (Maestro, future).
 *
 * Body:
 * {
 *   "source": "maestro",
 *   "airport": "KJFK",
 *   "runway": "13L",
 *   "sequence": [
 *     {"callsign": "UAL456", "sequence_position": 1, "eta_utc": "2026-01-27T14:30:00Z", "ttl_seconds": -150},
 *     {"callsign": "DAL789", "sequence_position": 2, "eta_utc": "2026-01-27T14:32:30Z", "ttl_seconds": -270}
 *   ]
 * }
 */

include("../../../../load/config.php");
include("../../../../load/connect.php");
require_once __DIR__ . '/../common.php';

swim_require_auth('write');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    swim_error(405, 'METHOD_NOT_ALLOWED', 'Use POST');
}

$body = swim_get_json_body();
if (!$body) swim_error(400, 'INVALID_BODY', 'JSON body required');

$source = $body['source'] ?? '';
$airport = strtoupper(trim($body['airport'] ?? ''));
$runway = trim($body['runway'] ?? '');
$sequence = $body['sequence'] ?? [];

$validSources = ['maestro', 'aman', 'topsky', 'generic'];
if (!in_array($source, $validSources)) {
    swim_error(400, 'INVALID_SOURCE', "Valid sources: " . implode(', ', $validSources));
}
if (!$airport) swim_error(400, 'MISSING_AIRPORT', 'airport required');
if (empty($sequence) || !is_array($sequence)) {
    swim_error(400, 'MISSING_SEQUENCE', 'Non-empty sequence array required');
}
if (count($sequence) > 100) {
    swim_error(400, 'SEQUENCE_TOO_LARGE', 'Maximum 100 entries per sequence');
}

// Publish to WebSocket for real-time consumers (Bridge 2, 3)
$wsPayload = [
    'source' => $source,
    'airport' => $airport,
    'runway' => $runway,
    'sequence' => $sequence,
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
];

// Write to WebSocket event file for swim_ws_server to pick up
$eventFile = sys_get_temp_dir() . '/swim_ws_events.json';
$event = json_encode(['type' => 'aman.sequence', 'data' => $wsPayload]) . "\n";
file_put_contents($eventFile, $event, FILE_APPEND | LOCK_EX);

swim_json([
    'status' => 'ok',
    'airport' => $airport,
    'entries_received' => count($sequence),
]);
```

**Step 2: Commit**

```bash
git add api/swim/v1/ingest/aman.php
git commit -m "feat(swim): add AMAN sequence ingest endpoint"
```

---

## Bridge 2: FSD Protocol Bridge (Go, Standalone Binary)

**Summary:** Go binary that subscribes to SWIM WebSocket and serves enriched data as FSD packets on TCP :6809. Build all code/logic, do NOT provision Azure infrastructure.

---

### Task 2.1: Initialize Go Module

**Files:**
- Create: `integrations/fsd-bridge/go.mod`
- Create: `integrations/fsd-bridge/go.sum`
- Create: `integrations/fsd-bridge/main.go`
- Create: `integrations/fsd-bridge/README.md`

**Step 1: Create directory and initialize Go module**

```bash
mkdir -p integrations/fsd-bridge
cd integrations/fsd-bridge
go mod init github.com/vatcscc/swim-bridge
```

**Step 2: Create main.go entry point**

```go
package main

import (
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"syscall"

	"github.com/vatcscc/swim-bridge/internal/config"
	"github.com/vatcscc/swim-bridge/internal/fsd"
	"github.com/vatcscc/swim-bridge/internal/swim"
	"github.com/vatcscc/swim-bridge/internal/state"
)

var version = "0.1.0"

func main() {
	configPath := flag.String("config", "config.yaml", "Path to config file")
	showVersion := flag.Bool("version", false, "Show version")
	flag.Parse()

	if *showVersion {
		fmt.Printf("swim-bridge v%s\n", version)
		os.Exit(0)
	}

	cfg, err := config.Load(*configPath)
	if err != nil {
		log.Fatalf("Failed to load config: %v", err)
	}

	log.Printf("swim-bridge v%s starting", version)
	log.Printf("SWIM WebSocket: %s", cfg.SWIM.WebSocketURL)
	log.Printf("FSD listen: %s", cfg.FSD.Listen)

	// Flight state cache
	cache := state.NewFlightCache()

	// SWIM WebSocket client
	swimClient, err := swim.NewClient(cfg.SWIM, cache)
	if err != nil {
		log.Fatalf("Failed to create SWIM client: %v", err)
	}

	// FSD TCP server
	fsdServer, err := fsd.NewServer(cfg.FSD, cache)
	if err != nil {
		log.Fatalf("Failed to create FSD server: %v", err)
	}

	// Start services
	go swimClient.Run()
	go fsdServer.Run()

	// Graceful shutdown
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
	<-sigCh

	log.Println("Shutting down...")
	fsdServer.Stop()
	swimClient.Stop()
	log.Println("Bye")
}
```

**Step 3: Create default config.yaml**

Create `integrations/fsd-bridge/config.yaml`:

```yaml
# swim-bridge configuration
swim:
  api_key: "swim_dev_xxxxx"
  websocket_url: "wss://perti.vatcscc.org/api/swim/v1/ws"
  rest_url: "https://perti.vatcscc.org/api/swim/v1"
  reconnect_interval: 5s
  heartbeat_timeout: 90s

fsd:
  listen: ":6809"
  server_name: "VATSWIM"
  server_ident: "SWIM"
  max_clients: 100

filters:
  airports: []      # e.g. ["KJFK", "KLAX"]
  artccs: []         # e.g. ["ZNY", "ZLA"]
  callsign_prefix: []

messages:
  tmi_alerts: true
  edct_notifications: true
  aman_sequence: true
  flow_measures: true
  text_prefix: "[SWIM]"

logging:
  level: "info"      # debug, info, warn, error
  file: ""           # empty = stdout
```

**Step 4: Commit**

```bash
git add integrations/fsd-bridge/
git commit -m "feat(fsd-bridge): initialize Go module with main entry point and config"
```

---

### Task 2.2: Implement Configuration Loading

**Files:**
- Create: `integrations/fsd-bridge/internal/config/config.go`

**Step 1: Write config struct and loader**

```go
package config

import (
	"fmt"
	"os"
	"time"

	"gopkg.in/yaml.v3"
)

type Config struct {
	SWIM     SWIMConfig     `yaml:"swim"`
	FSD      FSDConfig      `yaml:"fsd"`
	Filters  FilterConfig   `yaml:"filters"`
	Messages MessageConfig  `yaml:"messages"`
	Logging  LoggingConfig  `yaml:"logging"`
}

type SWIMConfig struct {
	APIKey             string        `yaml:"api_key"`
	WebSocketURL       string        `yaml:"websocket_url"`
	RESTURL            string        `yaml:"rest_url"`
	ReconnectInterval  time.Duration `yaml:"reconnect_interval"`
	HeartbeatTimeout   time.Duration `yaml:"heartbeat_timeout"`
}

type FSDConfig struct {
	Listen     string `yaml:"listen"`
	ServerName string `yaml:"server_name"`
	ServerIdent string `yaml:"server_ident"`
	MaxClients int    `yaml:"max_clients"`
}

type FilterConfig struct {
	Airports       []string `yaml:"airports"`
	ARTCCs         []string `yaml:"artccs"`
	CallsignPrefix []string `yaml:"callsign_prefix"`
}

type MessageConfig struct {
	TMIAlerts         bool   `yaml:"tmi_alerts"`
	EDCTNotifications bool   `yaml:"edct_notifications"`
	AMANSequence      bool   `yaml:"aman_sequence"`
	FlowMeasures      bool   `yaml:"flow_measures"`
	TextPrefix        string `yaml:"text_prefix"`
}

type LoggingConfig struct {
	Level string `yaml:"level"`
	File  string `yaml:"file"`
}

func Load(path string) (*Config, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("reading config %s: %w", path, err)
	}

	cfg := &Config{
		// Defaults
		SWIM: SWIMConfig{
			ReconnectInterval: 5 * time.Second,
			HeartbeatTimeout:  90 * time.Second,
		},
		FSD: FSDConfig{
			Listen:     ":6809",
			ServerName: "VATSWIM",
			ServerIdent: "SWIM",
			MaxClients: 100,
		},
		Messages: MessageConfig{
			TMIAlerts:         true,
			EDCTNotifications: true,
			AMANSequence:      true,
			FlowMeasures:      true,
			TextPrefix:        "[SWIM]",
		},
		Logging: LoggingConfig{
			Level: "info",
		},
	}

	if err := yaml.Unmarshal(data, cfg); err != nil {
		return nil, fmt.Errorf("parsing config: %w", err)
	}

	if cfg.SWIM.APIKey == "" || cfg.SWIM.APIKey == "swim_dev_xxxxx" {
		return nil, fmt.Errorf("swim.api_key must be set in config")
	}

	return cfg, nil
}
```

**Step 2: Add yaml dependency**

```bash
cd integrations/fsd-bridge && go get gopkg.in/yaml.v3
```

**Step 3: Commit**

```bash
git add integrations/fsd-bridge/internal/config/ integrations/fsd-bridge/go.mod integrations/fsd-bridge/go.sum
git commit -m "feat(fsd-bridge): add YAML configuration loader"
```

---

### Task 2.3: Implement Flight State Cache

**Files:**
- Create: `integrations/fsd-bridge/internal/state/cache.go`

**Step 1: Write the flight state cache**

```go
package state

import (
	"sync"
	"time"
)

// Flight represents the enriched state of a tracked flight.
type Flight struct {
	UID            int64     `json:"flight_uid"`
	Callsign       string    `json:"callsign"`
	Departure      string    `json:"departure_icao"`
	Arrival        string    `json:"arrival_icao"`
	AircraftType   string    `json:"aircraft_type"`
	Route          string    `json:"route"`
	CruiseAlt      int       `json:"cruise_altitude"`
	Latitude       float64   `json:"latitude"`
	Longitude      float64   `json:"longitude"`
	Altitude       int       `json:"altitude"`
	Groundspeed    int       `json:"groundspeed"`
	Heading        int       `json:"heading"`
	Phase          string    `json:"phase"`
	EDCT           string    `json:"edct_utc"`
	CTOT           string    `json:"ctot_utc"`
	TMIProgram     string    `json:"tmi_program"`
	TMIType        string    `json:"tmi_type"`
	DelayMin       int       `json:"delay_min"`
	GSHeld         bool      `json:"gs_held"`
	CDMState       string    `json:"cdm_state"`
	AMANSeqPos     int       `json:"aman_seq_pos"`
	RerouteStatus  string    `json:"reroute_status"`
	CurrentARTCC   string    `json:"current_artcc"`
	LastUpdated    time.Time `json:"last_updated"`
}

// TMIProgram represents an active traffic management initiative.
type TMIProgram struct {
	ProgramID   int    `json:"program_id"`
	Type        string `json:"program_type"`
	Element     string `json:"ctl_element"`
	AAR         int    `json:"aar"`
	ADR         int    `json:"adr"`
	DelayMin    int    `json:"delay_min"`
	Status      string `json:"status"`
	IssuedUTC   string `json:"issued_utc"`
}

// AMANEntry is one position in an arrival sequence.
type AMANEntry struct {
	Callsign string `json:"callsign"`
	Position int    `json:"sequence_position"`
	ETAUTC   string `json:"eta_utc"`
	TTLSec   int    `json:"ttl_seconds"`
}

// FlightCache is a thread-safe in-memory store of flight/TMI/AMAN state.
type FlightCache struct {
	mu       sync.RWMutex
	flights  map[string]*Flight      // keyed by callsign
	programs map[int]*TMIProgram     // keyed by program_id
	aman     map[string][]AMANEntry  // keyed by airport ICAO
}

func NewFlightCache() *FlightCache {
	return &FlightCache{
		flights:  make(map[string]*Flight),
		programs: make(map[int]*TMIProgram),
		aman:     make(map[string][]AMANEntry),
	}
}

func (c *FlightCache) UpdateFlight(f *Flight) {
	c.mu.Lock()
	defer c.mu.Unlock()
	f.LastUpdated = time.Now().UTC()
	c.flights[f.Callsign] = f
}

func (c *FlightCache) GetFlight(callsign string) *Flight {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.flights[callsign]
}

func (c *FlightCache) RemoveFlight(callsign string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	delete(c.flights, callsign)
}

func (c *FlightCache) GetAllFlights() []*Flight {
	c.mu.RLock()
	defer c.mu.RUnlock()
	result := make([]*Flight, 0, len(c.flights))
	for _, f := range c.flights {
		result = append(result, f)
	}
	return result
}

func (c *FlightCache) UpdateProgram(p *TMIProgram) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.programs[p.ProgramID] = p
}

func (c *FlightCache) RemoveProgram(id int) {
	c.mu.Lock()
	defer c.mu.Unlock()
	delete(c.programs, id)
}

func (c *FlightCache) GetPrograms() []*TMIProgram {
	c.mu.RLock()
	defer c.mu.RUnlock()
	result := make([]*TMIProgram, 0, len(c.programs))
	for _, p := range c.programs {
		result = append(result, p)
	}
	return result
}

func (c *FlightCache) UpdateAMANSequence(airport string, seq []AMANEntry) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.aman[airport] = seq
}

func (c *FlightCache) GetAMANSequence(airport string) []AMANEntry {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.aman[airport]
}

func (c *FlightCache) FlightCount() int {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return len(c.flights)
}

// PurgeStale removes flights not updated within the given duration.
func (c *FlightCache) PurgeStale(maxAge time.Duration) int {
	c.mu.Lock()
	defer c.mu.Unlock()
	cutoff := time.Now().UTC().Add(-maxAge)
	purged := 0
	for cs, f := range c.flights {
		if f.LastUpdated.Before(cutoff) {
			delete(c.flights, cs)
			purged++
		}
	}
	return purged
}
```

**Step 2: Commit**

```bash
git add integrations/fsd-bridge/internal/state/
git commit -m "feat(fsd-bridge): add thread-safe flight state cache"
```

---

### Task 2.4: Implement SWIM WebSocket Client

**Files:**
- Create: `integrations/fsd-bridge/internal/swim/client.go`

**Step 1: Write the SWIM WebSocket client**

```go
package swim

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"time"

	"github.com/gorilla/websocket"
	"github.com/vatcscc/swim-bridge/internal/config"
	"github.com/vatcscc/swim-bridge/internal/state"
)

type Client struct {
	cfg   config.SWIMConfig
	cache *state.FlightCache
	conn  *websocket.Conn
	done  chan struct{}
}

type wsMessage struct {
	Type      string          `json:"type"`
	Timestamp string          `json:"timestamp"`
	Data      json.RawMessage `json:"data"`
}

func NewClient(cfg config.SWIMConfig, cache *state.FlightCache) (*Client, error) {
	return &Client{
		cfg:   cfg,
		cache: cache,
		done:  make(chan struct{}),
	}, nil
}

func (c *Client) Run() {
	for {
		select {
		case <-c.done:
			return
		default:
			if err := c.connect(); err != nil {
				log.Printf("SWIM connection error: %v", err)
			}
			select {
			case <-c.done:
				return
			case <-time.After(c.cfg.ReconnectInterval):
				log.Println("Reconnecting to SWIM WebSocket...")
			}
		}
	}
}

func (c *Client) Stop() {
	close(c.done)
	if c.conn != nil {
		c.conn.Close()
	}
}

func (c *Client) connect() error {
	header := http.Header{}
	header.Set("X-API-Key", c.cfg.APIKey)

	dialer := websocket.Dialer{
		HandshakeTimeout: 10 * time.Second,
	}

	conn, _, err := dialer.Dial(c.cfg.WebSocketURL, header)
	if err != nil {
		return fmt.Errorf("dial: %w", err)
	}
	c.conn = conn
	log.Println("Connected to SWIM WebSocket")

	// Subscribe to channels
	sub := map[string]interface{}{
		"action": "subscribe",
		"channels": []string{
			"flight.positions",
			"flight.created", "flight.departed", "flight.arrived", "flight.deleted",
			"tmi.issued", "tmi.modified", "tmi.released",
			"cdm.updated", "cdm.edct", "cdm.ctot", "cdm.gs",
			"aman.sequence",
			"system.heartbeat",
		},
	}
	if err := conn.WriteJSON(sub); err != nil {
		return fmt.Errorf("subscribe: %w", err)
	}
	log.Println("Subscribed to SWIM channels")

	// Read loop
	for {
		select {
		case <-c.done:
			return nil
		default:
		}

		conn.SetReadDeadline(time.Now().Add(c.cfg.HeartbeatTimeout))
		_, raw, err := conn.ReadMessage()
		if err != nil {
			return fmt.Errorf("read: %w", err)
		}

		var msg wsMessage
		if err := json.Unmarshal(raw, &msg); err != nil {
			log.Printf("SWIM parse error: %v", err)
			continue
		}

		c.handleMessage(msg)
	}
}

func (c *Client) handleMessage(msg wsMessage) {
	switch msg.Type {
	case "system.heartbeat":
		// No-op, just resets read deadline
	case "flight.positions":
		c.handlePositions(msg.Data)
	case "flight.created", "flight.departed":
		c.handleFlightUpdate(msg.Data)
	case "flight.arrived", "flight.deleted":
		c.handleFlightRemoved(msg.Data)
	case "tmi.issued", "tmi.modified":
		c.handleTMIUpdate(msg.Data)
	case "tmi.released":
		c.handleTMIReleased(msg.Data)
	case "cdm.updated", "cdm.edct", "cdm.ctot", "cdm.gs":
		c.handleCDMUpdate(msg.Data)
	case "aman.sequence":
		c.handleAMANSequence(msg.Data)
	default:
		log.Printf("Unknown SWIM event: %s", msg.Type)
	}
}

func (c *Client) handlePositions(data json.RawMessage) {
	var positions []struct {
		Callsign  string  `json:"callsign"`
		Lat       float64 `json:"latitude"`
		Lon       float64 `json:"longitude"`
		Alt       int     `json:"altitude"`
		GS        int     `json:"groundspeed"`
		Heading   int     `json:"heading"`
		Phase     string  `json:"phase"`
		ARTCC     string  `json:"current_artcc"`
	}
	if err := json.Unmarshal(data, &positions); err != nil {
		log.Printf("positions parse: %v", err)
		return
	}
	for _, p := range positions {
		f := c.cache.GetFlight(p.Callsign)
		if f == nil {
			f = &state.Flight{Callsign: p.Callsign}
		}
		f.Latitude = p.Lat
		f.Longitude = p.Lon
		f.Altitude = p.Alt
		f.Groundspeed = p.GS
		f.Heading = p.Heading
		f.Phase = p.Phase
		f.CurrentARTCC = p.ARTCC
		c.cache.UpdateFlight(f)
	}
}

func (c *Client) handleFlightUpdate(data json.RawMessage) {
	var f state.Flight
	if err := json.Unmarshal(data, &f); err != nil {
		log.Printf("flight update parse: %v", err)
		return
	}
	c.cache.UpdateFlight(&f)
}

func (c *Client) handleFlightRemoved(data json.RawMessage) {
	var payload struct {
		Callsign string `json:"callsign"`
	}
	if err := json.Unmarshal(data, &payload); err != nil {
		return
	}
	c.cache.RemoveFlight(payload.Callsign)
}

func (c *Client) handleTMIUpdate(data json.RawMessage) {
	var p state.TMIProgram
	if err := json.Unmarshal(data, &p); err != nil {
		log.Printf("TMI update parse: %v", err)
		return
	}
	c.cache.UpdateProgram(&p)
}

func (c *Client) handleTMIReleased(data json.RawMessage) {
	var payload struct {
		ProgramID int `json:"program_id"`
	}
	if err := json.Unmarshal(data, &payload); err != nil {
		return
	}
	c.cache.RemoveProgram(payload.ProgramID)
}

func (c *Client) handleCDMUpdate(data json.RawMessage) {
	var payload struct {
		Callsign string `json:"callsign"`
		EDCT     string `json:"edct_utc"`
		CTOT     string `json:"ctot_utc"`
		GSHeld   bool   `json:"gs_held"`
		CDMState string `json:"cdm_state"`
		TMI      string `json:"tmi_program"`
		Delay    int    `json:"delay_min"`
	}
	if err := json.Unmarshal(data, &payload); err != nil {
		return
	}
	f := c.cache.GetFlight(payload.Callsign)
	if f == nil {
		f = &state.Flight{Callsign: payload.Callsign}
	}
	if payload.EDCT != "" {
		f.EDCT = payload.EDCT
	}
	if payload.CTOT != "" {
		f.CTOT = payload.CTOT
	}
	f.GSHeld = payload.GSHeld
	f.CDMState = payload.CDMState
	if payload.TMI != "" {
		f.TMIProgram = payload.TMI
	}
	if payload.Delay > 0 {
		f.DelayMin = payload.Delay
	}
	c.cache.UpdateFlight(f)
}

func (c *Client) handleAMANSequence(data json.RawMessage) {
	var payload struct {
		Airport  string             `json:"airport"`
		Sequence []state.AMANEntry  `json:"sequence"`
	}
	if err := json.Unmarshal(data, &payload); err != nil {
		return
	}
	c.cache.UpdateAMANSequence(payload.Airport, payload.Sequence)
}
```

**Step 2: Add gorilla/websocket dependency**

```bash
cd integrations/fsd-bridge && go get github.com/gorilla/websocket
```

**Step 3: Commit**

```bash
git add integrations/fsd-bridge/internal/swim/ integrations/fsd-bridge/go.mod integrations/fsd-bridge/go.sum
git commit -m "feat(fsd-bridge): implement SWIM WebSocket client with event handlers"
```

---

### Task 2.5: Implement FSD Protocol Packet Encoder

**Files:**
- Create: `integrations/fsd-bridge/internal/fsd/packet.go`

**Step 1: Write FSD packet encoder**

```go
package fsd

import (
	"fmt"
	"strings"
)

// FSD protocol packet types
const (
	PacketTextMessage   = "#TM"
	PacketFlightPlan    = "$FP"
	PacketInfoRequest   = "$CQ"
	PacketInfoResponse  = "$CR"
	PacketServerIdent   = "$DI"
	PacketClientIdent   = "$ID"
	PacketPing          = "$PI"
	PacketPong          = "$PO"
	PacketKill          = "$!!"
)

// EncodeTextMessage creates an FSD #TM packet.
// Format: #TMfrom:to:message
func EncodeTextMessage(from, to, message string) string {
	return fmt.Sprintf("%s%s:%s:%s\r\n", PacketTextMessage, from, to, message)
}

// EncodeFlightPlan creates an FSD $FP packet.
// Format: $FPcallsign:*A:rules:actype:speed:dep:deptime:cruisealt:dest:route
func EncodeFlightPlan(callsign, rules, acType string, speed int, dep, depTime string, cruiseAlt int, dest, route string) string {
	return fmt.Sprintf("%s%s:*A:%s:%s:%d:%s:%s:%d:%s:%s\r\n",
		PacketFlightPlan, callsign, rules, acType, speed, dep, depTime, cruiseAlt, dest, route)
}

// EncodeInfoResponse creates an FSD $CR packet.
// Format: $CRfrom:to:type:data
func EncodeInfoResponse(from, to, infoType, data string) string {
	return fmt.Sprintf("%s%s:%s:%s:%s\r\n", PacketInfoResponse, from, to, infoType, data)
}

// EncodeServerIdent creates the initial server identification packet.
func EncodeServerIdent(serverName, version string) string {
	return fmt.Sprintf("%s%s:*:%s:VATSWIM FSD Bridge\r\n", PacketServerIdent, serverName, version)
}

// EncodePong creates a pong response to a ping.
func EncodePong(from, to string) string {
	return fmt.Sprintf("%s%s:%s\r\n", PacketPong, from, to)
}

// ParsePacket splits a raw FSD line into prefix, fields.
func ParsePacket(line string) (prefix string, fields []string) {
	line = strings.TrimRight(line, "\r\n")
	if len(line) < 3 {
		return "", nil
	}

	// Prefix is first 3 characters (e.g., "#TM", "$CQ", "$ID")
	prefix = line[:3]
	rest := line[3:]

	fields = strings.Split(rest, ":")
	return prefix, fields
}

// EncodeKill sends a disconnect message.
func EncodeKill(from, reason string) string {
	return fmt.Sprintf("%s%s:%s\r\n", PacketKill, from, reason)
}
```

**Step 2: Commit**

```bash
git add integrations/fsd-bridge/internal/fsd/packet.go
git commit -m "feat(fsd-bridge): implement FSD protocol packet encoder/parser"
```

---

### Task 2.6: Implement FSD TCP Server

**Files:**
- Create: `integrations/fsd-bridge/internal/fsd/server.go`

**Step 1: Write the FSD TCP server**

```go
package fsd

import (
	"bufio"
	"fmt"
	"log"
	"net"
	"strings"
	"sync"
	"time"

	"github.com/vatcscc/swim-bridge/internal/config"
	"github.com/vatcscc/swim-bridge/internal/state"
)

// ClientConn represents a connected ATC client.
type ClientConn struct {
	conn     net.Conn
	callsign string
	reader   *bufio.Reader
	mu       sync.Mutex
}

func (c *ClientConn) Send(data string) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.conn.SetWriteDeadline(time.Now().Add(5 * time.Second))
	_, err := c.conn.Write([]byte(data))
	return err
}

// Server is the FSD protocol TCP server.
type Server struct {
	cfg      config.FSDConfig
	cache    *state.FlightCache
	listener net.Listener
	clients  map[string]*ClientConn
	mu       sync.RWMutex
	done     chan struct{}
}

func NewServer(cfg config.FSDConfig, cache *state.FlightCache) (*Server, error) {
	return &Server{
		cfg:     cfg,
		cache:   cache,
		clients: make(map[string]*ClientConn),
		done:    make(chan struct{}),
	}, nil
}

func (s *Server) Run() {
	var err error
	s.listener, err = net.Listen("tcp", s.cfg.Listen)
	if err != nil {
		log.Fatalf("FSD listen error: %v", err)
	}
	log.Printf("FSD server listening on %s", s.cfg.Listen)

	for {
		conn, err := s.listener.Accept()
		if err != nil {
			select {
			case <-s.done:
				return
			default:
				log.Printf("FSD accept error: %v", err)
				continue
			}
		}

		s.mu.RLock()
		clientCount := len(s.clients)
		s.mu.RUnlock()

		if clientCount >= s.cfg.MaxClients {
			conn.Write([]byte(EncodeKill(s.cfg.ServerIdent, "Server full")))
			conn.Close()
			continue
		}

		go s.handleClient(conn)
	}
}

func (s *Server) Stop() {
	close(s.done)
	if s.listener != nil {
		s.listener.Close()
	}
	s.mu.Lock()
	for _, c := range s.clients {
		c.Send(EncodeKill(s.cfg.ServerIdent, "Server shutting down"))
		c.conn.Close()
	}
	s.mu.Unlock()
}

func (s *Server) handleClient(conn net.Conn) {
	client := &ClientConn{
		conn:   conn,
		reader: bufio.NewReader(conn),
	}

	defer func() {
		conn.Close()
		if client.callsign != "" {
			s.mu.Lock()
			delete(s.clients, client.callsign)
			s.mu.Unlock()
			log.Printf("FSD client disconnected: %s", client.callsign)
		}
	}()

	// Send server ident
	client.Send(EncodeServerIdent(s.cfg.ServerName, "1.0"))

	for {
		select {
		case <-s.done:
			return
		default:
		}

		conn.SetReadDeadline(time.Now().Add(120 * time.Second))
		line, err := client.reader.ReadString('\n')
		if err != nil {
			return
		}

		line = strings.TrimRight(line, "\r\n")
		if line == "" {
			continue
		}

		prefix, fields := ParsePacket(line + "\r\n")
		s.handlePacket(client, prefix, fields)
	}
}

func (s *Server) handlePacket(client *ClientConn, prefix string, fields []string) {
	if len(fields) == 0 {
		return
	}

	switch prefix {
	case PacketClientIdent:
		// $IDcallsign:...:...
		if len(fields) >= 1 {
			client.callsign = strings.Split(fields[0], ":")[0]
			s.mu.Lock()
			s.clients[client.callsign] = client
			s.mu.Unlock()
			log.Printf("FSD client identified: %s", client.callsign)
		}

	case PacketPing:
		// $PIcaller:target
		if len(fields) >= 2 {
			client.Send(EncodePong(fields[1], fields[0]))
		}

	case PacketInfoRequest:
		// $CQfrom:to:type[:data]
		if len(fields) >= 3 {
			s.handleInfoRequest(client, fields)
		}
	}
}

func (s *Server) handleInfoRequest(client *ClientConn, fields []string) {
	from := fields[0]
	infoType := fields[2]

	switch strings.ToUpper(infoType) {
	case "FP":
		// Flight plan request for a callsign
		if len(fields) >= 4 {
			callsign := fields[3]
			f := s.cache.GetFlight(callsign)
			if f != nil {
				fp := EncodeFlightPlan(f.Callsign, "I", f.AircraftType, f.Groundspeed,
					f.Departure, "", f.CruiseAlt, f.Arrival, f.Route)
				client.Send(fp)
			}
		}

	case "SWIM":
		// Custom: query SWIM TMI status for a callsign
		if len(fields) >= 4 {
			callsign := fields[3]
			f := s.cache.GetFlight(callsign)
			if f != nil {
				info := fmt.Sprintf("TMI:%s EDCT:%s DELAY:%dmin PHASE:%s",
					f.TMIProgram, f.EDCT, f.DelayMin, f.Phase)
				client.Send(EncodeInfoResponse(s.cfg.ServerIdent, from, "SWIM", info))
			} else {
				client.Send(EncodeInfoResponse(s.cfg.ServerIdent, from, "SWIM", "NO_DATA"))
			}
		}
	}
}

// BroadcastText sends a text message to all connected clients.
func (s *Server) BroadcastText(message string) {
	s.mu.RLock()
	defer s.mu.RUnlock()

	packet := EncodeTextMessage(s.cfg.ServerIdent, "*", message)
	for _, client := range s.clients {
		if err := client.Send(packet); err != nil {
			log.Printf("FSD send error to %s: %v", client.callsign, err)
		}
	}
}

// SendToClient sends a text message to a specific client.
func (s *Server) SendToClient(callsign, message string) {
	s.mu.RLock()
	client, ok := s.clients[callsign]
	s.mu.RUnlock()

	if ok {
		packet := EncodeTextMessage(s.cfg.ServerIdent, callsign, message)
		client.Send(packet)
	}
}

// ClientCount returns the number of connected clients.
func (s *Server) ClientCount() int {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return len(s.clients)
}
```

**Step 2: Commit**

```bash
git add integrations/fsd-bridge/internal/fsd/server.go
git commit -m "feat(fsd-bridge): implement FSD TCP server with client management"
```

---

### Task 2.7: Implement SWIM-to-FSD Event Translator

**Files:**
- Modify: `integrations/fsd-bridge/internal/swim/client.go`
- Create: `integrations/fsd-bridge/internal/swim/translator.go`

**Step 1: Write the event translator**

This component translates SWIM events into FSD text messages and sends them to the FSD server.

```go
package swim

import (
	"fmt"
	"strings"

	"github.com/vatcscc/swim-bridge/internal/config"
	"github.com/vatcscc/swim-bridge/internal/fsd"
	"github.com/vatcscc/swim-bridge/internal/state"
)

// Translator converts SWIM events to FSD messages.
type Translator struct {
	cfg       config.MessageConfig
	fsdServer *fsd.Server
	cache     *state.FlightCache
}

func NewTranslator(cfg config.MessageConfig, fsdServer *fsd.Server, cache *state.FlightCache) *Translator {
	return &Translator{cfg: cfg, fsdServer: fsdServer, cache: cache}
}

// OnTMIIssued translates a TMI program creation to FSD text messages.
func (t *Translator) OnTMIIssued(p *state.TMIProgram) {
	if !t.cfg.TMIAlerts {
		return
	}

	var msg string
	switch strings.ToUpper(p.Type) {
	case "GDP":
		msg = fmt.Sprintf("%s GDP %s VOL ISSUED. AAR %d. DELAY %d MIN.",
			t.cfg.TextPrefix, p.Element, p.AAR, p.DelayMin)
	case "GS":
		msg = fmt.Sprintf("%s GROUND STOP %s. ALL DEPARTURES HELD.",
			t.cfg.TextPrefix, p.Element)
	case "AFP":
		msg = fmt.Sprintf("%s AFP IN EFFECT FOR %s. %d FLIGHTS/HR.",
			t.cfg.TextPrefix, p.Element, p.AAR)
	default:
		msg = fmt.Sprintf("%s TMI %s: %s %s",
			t.cfg.TextPrefix, p.Type, p.Element, p.Status)
	}

	t.fsdServer.BroadcastText(msg)
}

// OnTMIModified translates TMI program modification.
func (t *Translator) OnTMIModified(p *state.TMIProgram) {
	if !t.cfg.TMIAlerts {
		return
	}
	msg := fmt.Sprintf("%s TMI %s %s MODIFIED. AAR %d. DELAY %d MIN.",
		t.cfg.TextPrefix, p.Type, p.Element, p.AAR, p.DelayMin)
	t.fsdServer.BroadcastText(msg)
}

// OnTMIReleased translates TMI program release.
func (t *Translator) OnTMIReleased(programType, element string) {
	if !t.cfg.TMIAlerts {
		return
	}
	msg := fmt.Sprintf("%s %s %s RELEASED.",
		t.cfg.TextPrefix, programType, element)
	t.fsdServer.BroadcastText(msg)
}

// OnEDCTAssigned translates EDCT/CTOT assignment to per-flight FSD message.
func (t *Translator) OnEDCTAssigned(callsign, edctUTC string, delayMin int) {
	if !t.cfg.EDCTNotifications {
		return
	}
	msg := fmt.Sprintf("%s %s EDCT %s (+%d)",
		t.cfg.TextPrefix, callsign, edctUTC, delayMin)
	t.fsdServer.BroadcastText(msg)
}

// OnGSHold translates ground stop hold for a flight.
func (t *Translator) OnGSHold(callsign, dest string) {
	if !t.cfg.TMIAlerts {
		return
	}
	msg := fmt.Sprintf("%s %s HELD FOR GS %s",
		t.cfg.TextPrefix, callsign, dest)
	t.fsdServer.BroadcastText(msg)
}

// OnAMANSequence translates AMAN sequence to FSD text.
func (t *Translator) OnAMANSequence(airport string, seq []state.AMANEntry) {
	if !t.cfg.AMANSequence || len(seq) == 0 {
		return
	}
	parts := make([]string, 0, len(seq))
	for _, e := range seq {
		if len(parts) >= 10 {
			break // Limit to top 10 for readability
		}
		parts = append(parts, fmt.Sprintf("%d.%s", e.Position, e.Callsign))
	}
	msg := fmt.Sprintf("%s %s SEQ: %s",
		t.cfg.TextPrefix, airport, strings.Join(parts, " "))
	t.fsdServer.BroadcastText(msg)
}
```

**Step 2: Update main.go to wire translator into the SWIM client and FSD server**

The SWIM client needs a reference to the translator, which needs a reference to the FSD server. Update `main.go` to create the translator after both are initialized, then set it on the SWIM client.

Add to `Client` struct a `translator *Translator` field and call translator methods from `handleMessage()`.

**Step 3: Commit**

```bash
git add integrations/fsd-bridge/internal/swim/translator.go integrations/fsd-bridge/main.go
git commit -m "feat(fsd-bridge): implement SWIM-to-FSD event translator"
```

---

### Task 2.8: Add Build Scripts

**Files:**
- Create: `integrations/fsd-bridge/Makefile`
- Create: `integrations/fsd-bridge/.gitignore`

**Step 1: Create Makefile**

```makefile
.PHONY: build build-all clean test

VERSION := 0.1.0
LDFLAGS := -ldflags "-X main.version=$(VERSION)"
BINARY := swim-bridge

build:
	go build $(LDFLAGS) -o $(BINARY) .

build-all:
	GOOS=windows GOARCH=amd64 go build $(LDFLAGS) -o $(BINARY)-windows-amd64.exe .
	GOOS=linux GOARCH=amd64 go build $(LDFLAGS) -o $(BINARY)-linux-amd64 .
	GOOS=darwin GOARCH=amd64 go build $(LDFLAGS) -o $(BINARY)-darwin-amd64 .
	GOOS=darwin GOARCH=arm64 go build $(LDFLAGS) -o $(BINARY)-darwin-arm64 .

test:
	go test ./...

clean:
	rm -f $(BINARY) $(BINARY)-*

run: build
	./$(BINARY) -config config.yaml
```

**Step 2: Create .gitignore**

```
swim-bridge
swim-bridge-*
*.exe
config.yaml
!config.yaml.example
```

**Step 3: Rename config.yaml to config.yaml.example**

```bash
mv integrations/fsd-bridge/config.yaml integrations/fsd-bridge/config.yaml.example
```

**Step 4: Commit**

```bash
git add integrations/fsd-bridge/Makefile integrations/fsd-bridge/.gitignore integrations/fsd-bridge/config.yaml.example
git commit -m "feat(fsd-bridge): add build system and config example"
```

---

## Bridge 3: EuroScope Plugin (C++, DLL)

**Summary:** C++ EuroScope plugin using the SWIM C++ SDK. Provides tag enrichment (8 categories), custom lists (4), and embedded FSD injection for redundancy.

---

### Task 3.1: Create Plugin Skeleton

**Files:**
- Create: `integrations/euroscope-plugin/CMakeLists.txt`
- Create: `integrations/euroscope-plugin/src/VatswimPlugin.h`
- Create: `integrations/euroscope-plugin/src/VatswimPlugin.cpp`
- Create: `integrations/euroscope-plugin/src/dllmain.cpp`

**Step 1: Create CMakeLists.txt**

```cmake
cmake_minimum_required(VERSION 3.20)
project(VatswimPlugin VERSION 0.1.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# EuroScope Plugin SDK path (set via -DEUROSCOPE_SDK_PATH=...)
if(NOT DEFINED EUROSCOPE_SDK_PATH)
    message(FATAL_ERROR "Set -DEUROSCOPE_SDK_PATH to EuroScope Plugin SDK directory")
endif()

# SWIM C++ SDK
set(SWIM_SDK_PATH "${CMAKE_SOURCE_DIR}/../../sdk/cpp")

add_library(VatswimPlugin SHARED
    src/dllmain.cpp
    src/VatswimPlugin.cpp
    src/SWIMDataProvider.cpp
    src/TagItems.cpp
    src/CustomLists.cpp
)

target_include_directories(VatswimPlugin PRIVATE
    ${EUROSCOPE_SDK_PATH}/include
    ${SWIM_SDK_PATH}/include
    src/
)

target_link_directories(VatswimPlugin PRIVATE
    ${EUROSCOPE_SDK_PATH}/lib
)

target_link_libraries(VatswimPlugin PRIVATE
    EuroScopePlugInDll
    winhttp
    ws2_32
)

# Output as .dll
set_target_properties(VatswimPlugin PROPERTIES
    OUTPUT_NAME "VatswimPlugin"
    SUFFIX ".dll"
)
```

**Step 2: Create VatswimPlugin.h**

```cpp
#pragma once
#include <EuroScopePlugIn.h>
#include <string>
#include <unordered_map>
#include <mutex>

// Tag item function IDs
constexpr int TAG_SWIM_EDCT  = 1;
constexpr int TAG_SWIM_TMI   = 2;
constexpr int TAG_SWIM_DELAY = 3;
constexpr int TAG_SWIM_CDM   = 4;
constexpr int TAG_SWIM_AMAN  = 5;
constexpr int TAG_SWIM_FLOW  = 6;
constexpr int TAG_SWIM_RR    = 7;
constexpr int TAG_SWIM_PHASE = 8;

// Custom list IDs
constexpr int LIST_TMI_FLIGHTS   = 100;
constexpr int LIST_AMAN_SEQUENCE = 101;
constexpr int LIST_CDM_STATUS    = 102;
constexpr int LIST_FLOW_IMPACT   = 103;

class VatswimPlugin : public EuroScopePlugIn::CPlugIn {
public:
    VatswimPlugin();
    virtual ~VatswimPlugin();

    // EuroScope callbacks
    virtual void OnGetTagItem(EuroScopePlugIn::CFlightPlan FlightPlan,
                              EuroScopePlugIn::CRadarTarget RadarTarget,
                              int ItemCode, int TagData, char sItemString[16],
                              int* pColorCode, COLORREF* pRGB,
                              double* pFontSize) override;

    virtual void OnFunctionCall(int FunctionId, const char* sItemString,
                                POINT Pt, RECT Area) override;

    virtual void OnTimer(int Counter) override;

    // SWIM data refresh
    void RefreshSWIMData();

private:
    struct FlightSWIMData {
        std::string edct_utc;
        std::string ctot_utc;
        std::string tmi_program;
        std::string tmi_type;
        int delay_min = 0;
        std::string cdm_state;
        int aman_seq_pos = 0;
        std::string flow_measure;
        std::string reroute_status;
        std::string phase;
    };

    std::unordered_map<std::string, FlightSWIMData> m_swimData;
    std::mutex m_dataMutex;
    std::string m_apiKey;
    std::string m_apiUrl;
    int m_refreshInterval = 10; // seconds
};
```

**Step 3: Create VatswimPlugin.cpp with tag item implementation**

```cpp
#include "VatswimPlugin.h"
#include <ctime>
#include <cstring>

VatswimPlugin::VatswimPlugin()
    : CPlugIn(EuroScopePlugIn::COMPATIBILITY_CODE,
              "VATSWIM Plugin", "0.1.0", "VATCSCC", "VATSWIM SWIM Data Integration")
{
    // Register tag items
    RegisterTagItemType("SWIM EDCT",    TAG_SWIM_EDCT);
    RegisterTagItemType("SWIM TMI",     TAG_SWIM_TMI);
    RegisterTagItemType("SWIM Delay",   TAG_SWIM_DELAY);
    RegisterTagItemType("SWIM CDM",     TAG_SWIM_CDM);
    RegisterTagItemType("SWIM AMAN",    TAG_SWIM_AMAN);
    RegisterTagItemType("SWIM Flow",    TAG_SWIM_FLOW);
    RegisterTagItemType("SWIM Reroute", TAG_SWIM_RR);
    RegisterTagItemType("SWIM Phase",   TAG_SWIM_PHASE);

    // Register tag item functions
    RegisterTagItemFunction("SWIM Details", TAG_SWIM_EDCT);

    // Load settings
    const char* key = GetDataFromSettings("VatswimAPIKey");
    if (key) m_apiKey = key;
    const char* url = GetDataFromSettings("VatswimAPIURL");
    if (url) m_apiUrl = url;
    else m_apiUrl = "https://perti.vatcscc.org/api/swim/v1";

    DisplayUserMessage("VATSWIM", "Plugin", "VATSWIM Plugin loaded. SWIM data integration active.", true, true, false, false, false);
}

VatswimPlugin::~VatswimPlugin() {}

void VatswimPlugin::OnGetTagItem(
    EuroScopePlugIn::CFlightPlan FlightPlan,
    EuroScopePlugIn::CRadarTarget RadarTarget,
    int ItemCode, int TagData,
    char sItemString[16], int* pColorCode, COLORREF* pRGB, double* pFontSize)
{
    if (!FlightPlan.IsValid()) return;

    std::string callsign = FlightPlan.GetCallsign();
    std::lock_guard<std::mutex> lock(m_dataMutex);

    auto it = m_swimData.find(callsign);
    if (it == m_swimData.end()) {
        strcpy_s(sItemString, 16, "");
        return;
    }

    const auto& data = it->second;

    switch (ItemCode) {
    case TAG_SWIM_EDCT:
        if (!data.edct_utc.empty()) {
            // Show "EDCT HHMMz" or countdown
            std::string display = "EDCT " + data.edct_utc.substr(11, 5);
            strncpy_s(sItemString, 16, display.c_str(), 15);
            *pColorCode = EuroScopePlugIn::TAG_COLOR_RGB;
            *pRGB = RGB(255, 200, 0); // Amber
        }
        break;

    case TAG_SWIM_TMI:
        if (!data.tmi_program.empty()) {
            std::string display = data.tmi_type + ":" + data.tmi_program;
            strncpy_s(sItemString, 16, display.c_str(), 15);
        }
        break;

    case TAG_SWIM_DELAY:
        if (data.delay_min > 0) {
            char buf[16];
            snprintf(buf, sizeof(buf), "D+%d", data.delay_min);
            strcpy_s(sItemString, 16, buf);
            *pColorCode = EuroScopePlugIn::TAG_COLOR_RGB;
            if (data.delay_min > 60) *pRGB = RGB(255, 50, 50);      // Red
            else if (data.delay_min > 30) *pRGB = RGB(255, 165, 0); // Orange
            else *pRGB = RGB(255, 255, 0);                          // Yellow
        }
        break;

    case TAG_SWIM_CDM:
        if (!data.cdm_state.empty()) {
            strncpy_s(sItemString, 16, data.cdm_state.c_str(), 15);
        }
        break;

    case TAG_SWIM_AMAN:
        if (data.aman_seq_pos > 0) {
            char buf[16];
            snprintf(buf, sizeof(buf), "SEQ#%d", data.aman_seq_pos);
            strcpy_s(sItemString, 16, buf);
        }
        break;

    case TAG_SWIM_FLOW:
        if (!data.flow_measure.empty()) {
            strncpy_s(sItemString, 16, data.flow_measure.c_str(), 15);
        }
        break;

    case TAG_SWIM_RR:
        if (!data.reroute_status.empty()) {
            strncpy_s(sItemString, 16, data.reroute_status.c_str(), 15);
            *pColorCode = EuroScopePlugIn::TAG_COLOR_RGB;
            if (data.reroute_status == "RR:COMPLY") *pRGB = RGB(0, 200, 0);
            else *pRGB = RGB(255, 50, 50);
        }
        break;

    case TAG_SWIM_PHASE:
        if (!data.phase.empty()) {
            strncpy_s(sItemString, 16, data.phase.c_str(), 15);
        }
        break;
    }
}

void VatswimPlugin::OnFunctionCall(int FunctionId, const char* sItemString,
                                    POINT Pt, RECT Area)
{
    // Handle tag item clicks - show SWIM detail popup
}

void VatswimPlugin::OnTimer(int Counter) {
    // Refresh SWIM data periodically
    if (Counter % (m_refreshInterval * 4) == 0) { // Timer fires ~4x/sec
        RefreshSWIMData();
    }
}

void VatswimPlugin::RefreshSWIMData() {
    // TODO: Implement REST API call using SWIM C++ SDK
    // Fetch /flights?fields=callsign,edct_utc,tmi_program,...
    // Update m_swimData map
}
```

**Step 4: Create dllmain.cpp**

```cpp
#include "VatswimPlugin.h"

VatswimPlugin* pPlugin = nullptr;

void __declspec(dllexport) EuroScopePlugInInit(EuroScopePlugIn::CPlugIn** ppPlugInInstance) {
    *ppPlugInInstance = pPlugin = new VatswimPlugin();
}

void __declspec(dllexport) EuroScopePlugInExit(void) {
    delete pPlugin;
    pPlugin = nullptr;
}
```

**Step 5: Create stub files for SWIMDataProvider, TagItems, CustomLists**

Create `src/SWIMDataProvider.cpp`:

```cpp
#include "VatswimPlugin.h"
#include <swim/swim.h>

// SWIM REST API data fetching implementation
// Uses swim_client_init() and custom HTTP calls to fetch enriched flight data

// Placeholder — full implementation in Task 3.2
```

Create `src/TagItems.cpp`:

```cpp
// Tag item rendering helpers — shared formatting utilities
// Placeholder — expanded in Task 3.3
```

Create `src/CustomLists.cpp`:

```cpp
// Custom EuroScope list providers (TMI flights, AMAN, CDM, Flow impact)
// Placeholder — expanded in Task 3.4
```

**Step 6: Commit**

```bash
git add integrations/euroscope-plugin/
git commit -m "feat(euroscope): create plugin skeleton with tag items and DLL entry points"
```

---

### Task 3.2: Implement SWIM REST Data Provider

**Files:**
- Modify: `integrations/euroscope-plugin/src/SWIMDataProvider.cpp`
- Modify: `integrations/euroscope-plugin/src/VatswimPlugin.h` (add SWIMClient field)

**Step 1: Implement the RefreshSWIMData() method using the SWIM C++ SDK**

```cpp
#include "VatswimPlugin.h"
#include <swim/swim.h>
#include <swim/http.h>
#include <swim/json.h>
#include <string>
#include <vector>

// Initialize SWIM client on first call
static SwimClient s_swimClient;
static bool s_swimInitialized = false;

static bool ensureSWIMClient(const std::string& apiKey, const std::string& apiUrl) {
    if (s_swimInitialized) return true;

    SwimClientConfig config = {};
    strncpy_s(config.api_key, sizeof(config.api_key), apiKey.c_str(), _TRUNCATE);
    strncpy_s(config.base_url, sizeof(config.base_url), apiUrl.c_str(), _TRUNCATE);
    config.position_min_interval_ms = 5000;
    config.position_min_distance_nm = 0.5;

    if (swim_client_init(&s_swimClient, &config)) {
        s_swimInitialized = true;
        return true;
    }
    return false;
}

void VatswimPlugin::RefreshSWIMData() {
    if (!ensureSWIMClient(m_apiKey, m_apiUrl)) return;

    // Build URL for TMI-controlled flights with enrichment fields
    std::string url = m_apiUrl + "/flights?tmi_controlled=true"
        "&fields=callsign,edct_utc,ctot_utc,tmi_program,tmi_type,"
        "delay_min,cdm_state,aman_seq_pos,flow_measure,reroute_status,phase";

    // HTTP GET using WinHTTP (same pattern as swim.h REST calls)
    SwimHttpResponse resp = {};
    if (!swim_http_get(&s_swimClient.http, url.c_str(), &resp)) {
        return;
    }

    if (resp.status_code != 200 || !resp.body) {
        swim_http_response_free(&resp);
        return;
    }

    // Parse JSON array of flight objects
    // Using minimal JSON parsing (swim/json.h or manual)
    std::lock_guard<std::mutex> lock(m_dataMutex);
    m_swimData.clear();

    // Simple JSON array parsing
    SwimJsonParser parser;
    if (swim_json_parse(&parser, resp.body, resp.body_len)) {
        int count = swim_json_array_size(&parser);
        for (int i = 0; i < count; i++) {
            SwimJsonValue* item = swim_json_array_get(&parser, i);
            if (!item) continue;

            FlightSWIMData data;
            const char* cs = swim_json_get_string(item, "callsign");
            if (!cs) continue;

            std::string callsign(cs);
            const char* val;

            if ((val = swim_json_get_string(item, "edct_utc"))) data.edct_utc = val;
            if ((val = swim_json_get_string(item, "ctot_utc"))) data.ctot_utc = val;
            if ((val = swim_json_get_string(item, "tmi_program"))) data.tmi_program = val;
            if ((val = swim_json_get_string(item, "tmi_type"))) data.tmi_type = val;
            data.delay_min = swim_json_get_int(item, "delay_min", 0);
            if ((val = swim_json_get_string(item, "cdm_state"))) data.cdm_state = val;
            data.aman_seq_pos = swim_json_get_int(item, "aman_seq_pos", 0);
            if ((val = swim_json_get_string(item, "flow_measure"))) data.flow_measure = val;
            if ((val = swim_json_get_string(item, "reroute_status"))) data.reroute_status = val;
            if ((val = swim_json_get_string(item, "phase"))) data.phase = val;

            m_swimData[callsign] = data;
        }
        swim_json_free(&parser);
    }

    swim_http_response_free(&resp);
}
```

**Step 2: Commit**

```bash
git add integrations/euroscope-plugin/src/SWIMDataProvider.cpp
git commit -m "feat(euroscope): implement SWIM REST data provider using C++ SDK"
```

---

### Task 3.3: Add WebSocket Support to C++ SDK

**Files:**
- Create: `sdk/cpp/include/swim/websocket.h`

This is shared infrastructure needed by Bridge 3 and Bridge 5.

**Step 1: Write WebSocket client header**

```cpp
#pragma once
#ifndef SWIM_WEBSOCKET_H
#define SWIM_WEBSOCKET_H

#include "swim.h"
#include <stdbool.h>

#ifdef __cplusplus
extern "C" {
#endif

// WebSocket connection state
typedef enum {
    SWIM_WS_DISCONNECTED = 0,
    SWIM_WS_CONNECTING,
    SWIM_WS_CONNECTED,
    SWIM_WS_ERROR
} SwimWsState;

// WebSocket event types
typedef enum {
    SWIM_WS_EVENT_CONNECTED = 0,
    SWIM_WS_EVENT_MESSAGE,
    SWIM_WS_EVENT_DISCONNECTED,
    SWIM_WS_EVENT_ERROR
} SwimWsEventType;

// Callback for WebSocket events
typedef void (*SwimWsCallback)(SwimWsEventType event, const char* data, int data_len, void* user_data);

// WebSocket client configuration
typedef struct {
    char url[512];
    char api_key[256];
    char channels[2048];        // Comma-separated channel list
    int reconnect_interval_ms;  // Default 5000
    int heartbeat_timeout_ms;   // Default 90000
    SwimWsCallback callback;
    void* user_data;
} SwimWsConfig;

// WebSocket client handle (opaque)
typedef struct SwimWsClient SwimWsClient;

/**
 * Create a WebSocket client.
 * Returns NULL on failure.
 */
SwimWsClient* swim_ws_create(const SwimWsConfig* config);

/**
 * Connect and start the read loop.
 * Blocks the calling thread. Call from a dedicated thread.
 * Auto-reconnects on disconnect.
 */
void swim_ws_run(SwimWsClient* client);

/**
 * Send a JSON message to the server.
 */
bool swim_ws_send(SwimWsClient* client, const char* json_data, int len);

/**
 * Subscribe to additional channels.
 */
bool swim_ws_subscribe(SwimWsClient* client, const char** channels, int count);

/**
 * Get current connection state.
 */
SwimWsState swim_ws_get_state(SwimWsClient* client);

/**
 * Signal the client to stop and disconnect.
 * Thread-safe. swim_ws_run() will return after this.
 */
void swim_ws_stop(SwimWsClient* client);

/**
 * Destroy the client and free resources.
 * Must be called after swim_ws_run() returns.
 */
void swim_ws_destroy(SwimWsClient* client);

#ifdef __cplusplus
}
#endif

#endif // SWIM_WEBSOCKET_H
```

**Step 2: Commit**

```bash
git add sdk/cpp/include/swim/websocket.h
git commit -m "feat(sdk-cpp): add WebSocket client API header for SWIM real-time events"
```

---

### Task 3.4: Add FMS Write API to C++ SDK

**Files:**
- Create: `sdk/cpp/include/swim/fms.h`

Shared by Bridge 4 (local helper) and Bridge 5 (AOC client).

**Step 1: Write FMS injection header**

```cpp
#pragma once
#ifndef SWIM_FMS_H
#define SWIM_FMS_H

#include <stdbool.h>

#ifdef __cplusplus
extern "C" {
#endif

// Simulator platform
typedef enum {
    SWIM_SIM_NONE = 0,
    SWIM_SIM_MSFS,      // Microsoft Flight Simulator 2020/2024
    SWIM_SIM_P3D,       // Prepar3D v4/v5
    SWIM_SIM_XPLANE     // X-Plane 11/12
} SwimSimPlatform;

// FMS waypoint for injection
typedef struct {
    char ident[16];     // Fix/waypoint identifier
    double latitude;
    double longitude;
    int altitude_ft;    // 0 = no constraint
    bool is_departure;  // Part of DP
    bool is_arrival;    // Part of STAR
} SwimFmsWaypoint;

// FMS write result
typedef struct {
    bool success;
    int waypoints_loaded;
    char error[256];
} SwimFmsResult;

/**
 * Detect which simulator is running.
 * Returns SWIM_SIM_NONE if no simulator detected.
 */
SwimSimPlatform swim_fms_detect_sim(void);

/**
 * Initialize FMS connection for the detected simulator.
 * Must be called before swim_fms_load_route().
 */
bool swim_fms_init(SwimSimPlatform platform);

/**
 * Load a route into the simulator FMS.
 *
 * For MSFS/P3D: Uses SimConnect_SetFlightPlan() or individual waypoint injection.
 * For X-Plane: Uses XPLMSetFMSEntryInfo() to set each waypoint.
 *
 * @param departure    ICAO departure airport
 * @param destination  ICAO destination airport
 * @param waypoints    Array of waypoints in route order
 * @param count        Number of waypoints
 * @param result       Output result
 */
bool swim_fms_load_route(
    const char* departure,
    const char* destination,
    const SwimFmsWaypoint* waypoints,
    int count,
    SwimFmsResult* result
);

/**
 * Parse a route string (e.g., "KJFK J80 SLT J64 ABQ KLAX") into waypoints.
 * Coordinates are resolved via SWIM REST API.
 *
 * @param route_string  Space-separated route
 * @param waypoints     Output array (caller allocates)
 * @param max_count     Size of waypoints array
 * @return              Number of waypoints parsed, -1 on error
 */
int swim_fms_parse_route(
    const char* route_string,
    SwimFmsWaypoint* waypoints,
    int max_count
);

/**
 * Clean up FMS connection.
 */
void swim_fms_cleanup(void);

#ifdef __cplusplus
}
#endif

#endif // SWIM_FMS_H
```

**Step 2: Commit**

```bash
git add sdk/cpp/include/swim/fms.h
git commit -m "feat(sdk-cpp): add FMS write API header for SimConnect/XPLM route injection"
```

---

## Bridge 4: Pilot Portal (Vue 3, Web Application)

**Summary:** Web app for pilots to view TMI status, file TOS preferences, see routes on a map, and initiate FMS injection via a local helper.

---

### Task 4.1: Scaffold Vue 3 Project

**Files:**
- Create: `integrations/pilot-portal/` (Vue project)

**Step 1: Initialize Vue project with Vite**

```bash
cd integrations
npm create vue@latest pilot-portal -- --typescript
cd pilot-portal
npm install
npm install maplibre-gl @maplibre/maplibre-gl-style-spec
npm install pinia
npm install vue-router@4
```

**Step 2: Create project structure**

```
integrations/pilot-portal/
  src/
    App.vue
    main.ts
    router/index.ts
    stores/
      flight.ts        # Flight state (Pinia)
      swim.ts          # SWIM API client
    components/
      FlightStatus.vue
      TOSFiling.vue
      RouteMap.vue
      FMSLoader.vue
      MessageFeed.vue
    views/
      Dashboard.vue
      Login.vue
    services/
      swim-api.ts      # REST API client
      swim-ws.ts       # WebSocket client
      fms-bridge.ts    # Local helper communication
    types/
      flight.ts
      tos.ts
      tmi.ts
```

**Step 3: Create SWIM API service**

Create `src/services/swim-api.ts`:

```typescript
const BASE_URL = import.meta.env.VITE_SWIM_API_URL || 'https://perti.vatcscc.org/api/swim/v1';

interface SwimApiOptions {
  apiKey?: string;
  token?: string;
}

class SwimApi {
  private baseUrl: string;
  private headers: Record<string, string> = {};

  constructor(options: SwimApiOptions = {}) {
    this.baseUrl = BASE_URL;
    if (options.apiKey) this.headers['X-API-Key'] = options.apiKey;
    if (options.token) this.headers['Authorization'] = `Bearer ${options.token}`;
  }

  private async request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const res = await fetch(`${this.baseUrl}${path}`, {
      ...options,
      headers: { ...this.headers, 'Content-Type': 'application/json', ...options.headers },
    });
    if (!res.ok) throw new Error(`SWIM API ${res.status}: ${await res.text()}`);
    return res.json();
  }

  async getMyFlight(callsign: string) {
    return this.request(`/flights?callsign=${encodeURIComponent(callsign)}`);
  }

  async getTMIPrograms() {
    return this.request('/tmi/programs?active=true');
  }

  async fileTOS(callsign: string, departure: string, destination: string, options: any[]) {
    return this.request('/tos/file.php', {
      method: 'POST',
      body: JSON.stringify({ callsign, departure, destination, options }),
    });
  }

  async getTOSStatus(callsign: string) {
    return this.request(`/tos/status.php?callsign=${encodeURIComponent(callsign)}`);
  }

  async getRouteGeometry(departure: string, destination: string, route: string) {
    return this.request(`/routes/expand?dep=${departure}&dest=${destination}&route=${encodeURIComponent(route)}`);
  }
}

export const swimApi = new SwimApi();
export default SwimApi;
```

**Step 4: Create WebSocket service**

Create `src/services/swim-ws.ts`:

```typescript
type EventHandler = (data: any) => void;

class SwimWebSocket {
  private ws: WebSocket | null = null;
  private url: string;
  private apiKey: string;
  private handlers: Map<string, Set<EventHandler>> = new Map();
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;

  constructor(url?: string, apiKey?: string) {
    this.url = url || import.meta.env.VITE_SWIM_WS_URL || 'wss://perti.vatcscc.org/api/swim/v1/ws';
    this.apiKey = apiKey || '';
  }

  connect(channels: string[]) {
    this.ws = new WebSocket(this.url);

    this.ws.onopen = () => {
      this.ws?.send(JSON.stringify({
        action: 'subscribe',
        channels,
        filters: {},
      }));
    };

    this.ws.onmessage = (event) => {
      const msg = JSON.parse(event.data);
      const handlers = this.handlers.get(msg.type) || new Set();
      handlers.forEach(h => h(msg.data));
      // Wildcard handlers
      const prefix = msg.type.split('.')[0] + '.*';
      const wildcardHandlers = this.handlers.get(prefix) || new Set();
      wildcardHandlers.forEach(h => h(msg.data));
    };

    this.ws.onclose = () => {
      this.reconnectTimer = setTimeout(() => this.connect(channels), 5000);
    };
  }

  on(event: string, handler: EventHandler) {
    if (!this.handlers.has(event)) this.handlers.set(event, new Set());
    this.handlers.get(event)!.add(handler);
  }

  off(event: string, handler: EventHandler) {
    this.handlers.get(event)?.delete(handler);
  }

  disconnect() {
    if (this.reconnectTimer) clearTimeout(this.reconnectTimer);
    this.ws?.close();
  }
}

export const swimWs = new SwimWebSocket();
export default SwimWebSocket;
```

**Step 5: Commit**

```bash
git add integrations/pilot-portal/
git commit -m "feat(pilot-portal): scaffold Vue 3 project with SWIM API and WebSocket services"
```

---

### Task 4.2: Create Dashboard and TOS Filing Components

**Files:**
- Create: `integrations/pilot-portal/src/views/Dashboard.vue`
- Create: `integrations/pilot-portal/src/components/FlightStatus.vue`
- Create: `integrations/pilot-portal/src/components/TOSFiling.vue`

**Step 1: Create Dashboard view**

```vue
<template>
  <div class="dashboard">
    <h1>VATSWIM Pilot Portal</h1>
    <div v-if="!callsign" class="callsign-entry">
      <label>Enter your callsign:</label>
      <input v-model="inputCallsign" placeholder="DAL123" @keyup.enter="loadFlight" />
      <button @click="loadFlight">Connect</button>
    </div>
    <div v-else class="flight-dashboard">
      <FlightStatus :callsign="callsign" />
      <TOSFiling :callsign="callsign" :departure="departure" :destination="destination" />
      <RouteMap v-if="route" :route="route" :departure="departure" :destination="destination" />
      <MessageFeed :callsign="callsign" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import FlightStatus from '../components/FlightStatus.vue';
import TOSFiling from '../components/TOSFiling.vue';
import RouteMap from '../components/RouteMap.vue';
import MessageFeed from '../components/MessageFeed.vue';
import { swimApi } from '../services/swim-api';

const callsign = ref('');
const inputCallsign = ref('');
const departure = ref('');
const destination = ref('');
const route = ref('');

async function loadFlight() {
  const cs = inputCallsign.value.trim().toUpperCase();
  if (!cs) return;
  try {
    const data: any = await swimApi.getMyFlight(cs);
    if (data.flights?.length > 0) {
      const flight = data.flights[0];
      callsign.value = cs;
      departure.value = flight.departure_icao;
      destination.value = flight.arrival_icao;
      route.value = flight.route || '';
    }
  } catch (e) {
    console.error('Flight not found', e);
  }
}
</script>
```

**Step 2: Create FlightStatus component**

```vue
<template>
  <div class="flight-status card">
    <h2>{{ callsign }} Flight Status</h2>
    <div class="status-grid">
      <div v-if="edct" class="status-item edct">
        <label>EDCT</label>
        <span class="value">{{ edct }}</span>
        <span class="countdown">{{ edctCountdown }}</span>
      </div>
      <div v-if="ctot" class="status-item ctot">
        <label>CTOT</label>
        <span class="value">{{ ctot }}</span>
      </div>
      <div v-if="tmiProgram" class="status-item tmi">
        <label>TMI</label>
        <span class="value">{{ tmiProgram }}</span>
      </div>
      <div v-if="delay > 0" class="status-item delay">
        <label>Delay</label>
        <span class="value">+{{ delay }} min</span>
      </div>
      <div class="status-item phase">
        <label>Phase</label>
        <span class="value">{{ phase || 'PREFILED' }}</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { swimWs } from '../services/swim-ws';

const props = defineProps<{ callsign: string }>();

const edct = ref('');
const ctot = ref('');
const tmiProgram = ref('');
const delay = ref(0);
const phase = ref('');
const now = ref(Date.now());

let timer: ReturnType<typeof setInterval>;

onMounted(() => {
  swimWs.connect(['cdm.*', 'flight.*']);
  swimWs.on('cdm.updated', handleCDMUpdate);
  timer = setInterval(() => { now.value = Date.now(); }, 1000);
});

onUnmounted(() => {
  swimWs.off('cdm.updated', handleCDMUpdate);
  clearInterval(timer);
});

function handleCDMUpdate(data: any) {
  if (data.callsign !== props.callsign) return;
  if (data.edct_utc) edct.value = data.edct_utc;
  if (data.ctot_utc) ctot.value = data.ctot_utc;
  if (data.tmi_program) tmiProgram.value = data.tmi_program;
  if (data.delay_min !== undefined) delay.value = data.delay_min;
  if (data.phase) phase.value = data.phase;
}

const edctCountdown = computed(() => {
  if (!edct.value) return '';
  const target = new Date(edct.value).getTime();
  const diff = Math.round((target - now.value) / 60000);
  if (diff > 0) return `in ${diff} min`;
  if (diff === 0) return 'NOW';
  return `${Math.abs(diff)} min ago`;
});
</script>
```

**Step 3: Create TOS filing component**

```vue
<template>
  <div class="tos-filing card">
    <h2>Trajectory Options Set</h2>
    <div v-for="(opt, i) in options" :key="i" class="tos-option">
      <span class="rank">#{{ i + 1 }}</span>
      <input v-model="opt.route" placeholder="Route string (e.g., J80 SLT J64 ABQ)" class="route-input" />
      <input v-model="opt.remarks" placeholder="Remarks" class="remarks-input" />
      <button @click="removeOption(i)" class="remove-btn">X</button>
    </div>
    <div class="tos-actions">
      <button @click="addOption" :disabled="options.length >= 10">Add Option</button>
      <button @click="submitTOS" :disabled="options.length === 0 || submitting" class="submit-btn">
        {{ submitting ? 'Filing...' : 'File TOS' }}
      </button>
    </div>
    <div v-if="result" class="tos-result" :class="result.status">
      {{ result.message }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { swimApi } from '../services/swim-api';

const props = defineProps<{ callsign: string; departure: string; destination: string }>();

interface TOSOption { route: string; remarks: string; }
const options = ref<TOSOption[]>([{ route: '', remarks: '' }]);
const submitting = ref(false);
const result = ref<{ status: string; message: string } | null>(null);

function addOption() {
  if (options.value.length < 10) {
    options.value.push({ route: '', remarks: '' });
  }
}

function removeOption(i: number) {
  options.value.splice(i, 1);
}

async function submitTOS() {
  submitting.value = true;
  result.value = null;
  try {
    const tosOptions = options.value
      .filter(o => o.route.trim())
      .map((o, i) => ({ rank: i + 1, route: o.route.trim(), remarks: o.remarks.trim() }));

    const res: any = await swimApi.fileTOS(props.callsign, props.departure, props.destination, tosOptions);
    result.value = { status: 'ok', message: `${res.options_filed} options filed successfully` };
  } catch (e: any) {
    result.value = { status: 'error', message: e.message };
  } finally {
    submitting.value = false;
  }
}
</script>
```

**Step 4: Commit**

```bash
git add integrations/pilot-portal/src/
git commit -m "feat(pilot-portal): add dashboard, flight status, and TOS filing components"
```

---

### Task 4.3: Create Route Map Component

**Files:**
- Create: `integrations/pilot-portal/src/components/RouteMap.vue`

**Step 1: Write MapLibre route display component**

```vue
<template>
  <div class="route-map card">
    <h2>Route Display</h2>
    <div ref="mapContainer" class="map-container"></div>
    <div class="map-actions">
      <button @click="loadToSim" :disabled="!route" class="load-sim-btn">
        Load to Simulator
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, watch } from 'vue';
import maplibregl from 'maplibre-gl';
import { swimApi } from '../services/swim-api';

const props = defineProps<{ route: string; departure: string; destination: string }>();

const mapContainer = ref<HTMLDivElement>();
let map: maplibregl.Map;

onMounted(() => {
  if (!mapContainer.value) return;

  map = new maplibregl.Map({
    container: mapContainer.value,
    style: 'https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json',
    center: [-98.5, 39.8], // US center
    zoom: 3,
  });

  map.on('load', () => {
    // Add empty route source
    map.addSource('route', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addLayer({
      id: 'route-line',
      type: 'line',
      source: 'route',
      paint: { 'line-color': '#00ff88', 'line-width': 3 },
    });

    if (props.route) loadRouteGeometry();
  });
});

watch(() => props.route, () => {
  if (map && props.route) loadRouteGeometry();
});

async function loadRouteGeometry() {
  try {
    const geo: any = await swimApi.getRouteGeometry(props.departure, props.destination, props.route);
    if (geo.geometry) {
      const source = map.getSource('route') as maplibregl.GeoJSONSource;
      source.setData({
        type: 'Feature',
        geometry: geo.geometry,
        properties: {},
      });
      // Fit bounds
      if (geo.bounds) {
        map.fitBounds(geo.bounds, { padding: 50 });
      }
    }
  } catch (e) {
    console.error('Route geometry load failed', e);
  }
}

async function loadToSim() {
  try {
    // Communicate with local helper via localhost WebSocket
    const ws = new WebSocket('ws://localhost:19285');
    ws.onopen = () => {
      ws.send(JSON.stringify({
        action: 'load_route',
        departure: props.departure,
        destination: props.destination,
        route: props.route,
      }));
    };
    ws.onmessage = (e) => {
      const res = JSON.parse(e.data);
      if (res.success) {
        alert(`Route loaded: ${res.waypoints_loaded} waypoints`);
      } else {
        alert(`Failed: ${res.error}`);
      }
      ws.close();
    };
    ws.onerror = () => {
      alert('Cannot connect to local simulator helper. Is it running?');
    };
  } catch (e) {
    console.error('FMS load error', e);
  }
}
</script>

<style scoped>
.map-container {
  height: 400px;
  border-radius: 8px;
  overflow: hidden;
}
</style>
```

**Step 2: Commit**

```bash
git add integrations/pilot-portal/src/components/RouteMap.vue
git commit -m "feat(pilot-portal): add MapLibre route display with FMS injection"
```

---

## Bridge 5: AOC Client (C/C++, Simulator Daemon)

**Summary:** Local daemon providing bidirectional telemetry between flight simulator, VA systems, and VATSWIM. Extends the C++ SDK with WebSocket support and FMS write.

---

### Task 5.1: Create AOC Client Main Skeleton

**Files:**
- Create: `integrations/aoc-client/src/main.cpp`
- Create: `integrations/aoc-client/src/aoc_client.h`
- Create: `integrations/aoc-client/CMakeLists.txt`

**Step 1: Create CMakeLists.txt**

```cmake
cmake_minimum_required(VERSION 3.20)
project(VatswimAOC VERSION 0.1.0 LANGUAGES C CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# SWIM C++ SDK
set(SWIM_SDK_PATH "${CMAKE_SOURCE_DIR}/../../sdk/cpp")

# SimConnect SDK path (for MSFS/P3D)
# set(SIMCONNECT_SDK_PATH "..." CACHE PATH "Path to SimConnect SDK")

add_executable(vatswim-aoc
    src/main.cpp
    src/aoc_client.cpp
    src/telemetry.cpp
    src/dispatch.cpp
    src/fms_writer.cpp
)

target_include_directories(vatswim-aoc PRIVATE
    ${SWIM_SDK_PATH}/include
    src/
)

target_link_libraries(vatswim-aoc PRIVATE
    winhttp
    ws2_32
)

if(SIMCONNECT_SDK_PATH)
    target_include_directories(vatswim-aoc PRIVATE ${SIMCONNECT_SDK_PATH}/include)
    target_link_directories(vatswim-aoc PRIVATE ${SIMCONNECT_SDK_PATH}/lib)
    target_link_libraries(vatswim-aoc PRIVATE SimConnect)
    target_compile_definitions(vatswim-aoc PRIVATE HAS_SIMCONNECT=1)
endif()
```

**Step 2: Create aoc_client.h**

```cpp
#pragma once
#include <swim/swim.h>
#include <swim/websocket.h>
#include <swim/fms.h>
#include <string>
#include <atomic>
#include <thread>

class AOCClient {
public:
    AOCClient();
    ~AOCClient();

    bool init(const std::string& configPath);
    void run();  // Blocks until stop() called
    void stop();

private:
    // Configuration
    struct Config {
        std::string api_key;
        std::string api_url;
        std::string ws_url;
        std::string callsign;
        int telemetry_interval_ms = 15000;
        bool va_passthrough = false;
        std::string va_callback_url;
    } m_config;

    // SWIM clients
    SwimClient m_swimClient;
    SwimWsClient* m_wsClient = nullptr;

    // State
    std::atomic<bool> m_running{false};
    SwimSimPlatform m_simPlatform = SWIM_SIM_NONE;

    // Threads
    std::thread m_telemetryThread;
    std::thread m_wsThread;

    // Telemetry collection
    void telemetryLoop();
    void collectSimConnectTelemetry();
    void collectXPlaneTelemetry();
    void sendPosition(double lat, double lon, int alt, int gs, int hdg);
    void sendOOOI(const char* event_type);
    void sendProgress(const char* next_wpt, const char* eta_dest, double fuel_remaining);

    // Dispatch receiving
    static void onWebSocketEvent(SwimWsEventType event, const char* data, int len, void* user_data);
    void handleDispatch(const char* json_data, int len);
    void handleRouteAmendment(const char* route);
    void handleEDCTNotification(const char* edct_utc, int delay_min);
};
```

**Step 3: Create main.cpp**

```cpp
#include "aoc_client.h"
#include <cstdio>
#include <csignal>

static AOCClient* g_client = nullptr;

void signalHandler(int sig) {
    if (g_client) g_client->stop();
}

int main(int argc, char* argv[]) {
    const char* configPath = "vatswim-aoc.yaml";
    if (argc > 1) configPath = argv[1];

    printf("VATSWIM AOC Client v0.1.0\n");

    AOCClient client;
    g_client = &client;

    signal(SIGINT, signalHandler);
    signal(SIGTERM, signalHandler);

    if (!client.init(configPath)) {
        fprintf(stderr, "Failed to initialize. Check config at %s\n", configPath);
        return 1;
    }

    client.run();
    return 0;
}
```

**Step 4: Create stub implementation files**

Create `src/aoc_client.cpp`:

```cpp
#include "aoc_client.h"
#include <fstream>
#include <cstring>

AOCClient::AOCClient() {}
AOCClient::~AOCClient() { stop(); }

bool AOCClient::init(const std::string& configPath) {
    // TODO: Load YAML config
    // For now, use environment variables or defaults
    m_config.api_url = "https://perti.vatcscc.org/api/swim/v1";
    m_config.ws_url = "wss://perti.vatcscc.org/api/swim/v1/ws";

    // Initialize SWIM REST client
    SwimClientConfig swimCfg = {};
    strncpy_s(swimCfg.api_key, sizeof(swimCfg.api_key), m_config.api_key.c_str(), _TRUNCATE);
    strncpy_s(swimCfg.base_url, sizeof(swimCfg.base_url), m_config.api_url.c_str(), _TRUNCATE);
    if (!swim_client_init(&m_swimClient, &swimCfg)) return false;

    // Detect simulator
    m_simPlatform = swim_fms_detect_sim();
    if (m_simPlatform != SWIM_SIM_NONE) {
        printf("Detected simulator: %d\n", m_simPlatform);
        swim_fms_init(m_simPlatform);
    }

    // Initialize WebSocket
    SwimWsConfig wsCfg = {};
    strncpy_s(wsCfg.url, sizeof(wsCfg.url), m_config.ws_url.c_str(), _TRUNCATE);
    strncpy_s(wsCfg.api_key, sizeof(wsCfg.api_key), m_config.api_key.c_str(), _TRUNCATE);
    strncpy_s(wsCfg.channels, sizeof(wsCfg.channels),
              "cdm.*,tmi.*,flight.updated", _TRUNCATE);
    wsCfg.reconnect_interval_ms = 5000;
    wsCfg.heartbeat_timeout_ms = 90000;
    wsCfg.callback = &AOCClient::onWebSocketEvent;
    wsCfg.user_data = this;
    m_wsClient = swim_ws_create(&wsCfg);

    return true;
}

void AOCClient::run() {
    m_running = true;

    // Start WebSocket listener thread
    if (m_wsClient) {
        m_wsThread = std::thread([this]() { swim_ws_run(m_wsClient); });
    }

    // Start telemetry collection thread
    m_telemetryThread = std::thread([this]() { telemetryLoop(); });

    // Wait for stop signal
    m_telemetryThread.join();
    if (m_wsThread.joinable()) m_wsThread.join();
}

void AOCClient::stop() {
    m_running = false;
    if (m_wsClient) swim_ws_stop(m_wsClient);
    swim_fms_cleanup();
    swim_client_cleanup(&m_swimClient);
}

void AOCClient::onWebSocketEvent(SwimWsEventType event, const char* data, int len, void* user_data) {
    auto* self = static_cast<AOCClient*>(user_data);
    if (event == SWIM_WS_EVENT_MESSAGE) {
        self->handleDispatch(data, len);
    }
}
```

Create `src/telemetry.cpp`:

```cpp
#include "aoc_client.h"
#include <thread>
#include <chrono>

void AOCClient::telemetryLoop() {
    while (m_running) {
        switch (m_simPlatform) {
#ifdef HAS_SIMCONNECT
        case SWIM_SIM_MSFS:
        case SWIM_SIM_P3D:
            collectSimConnectTelemetry();
            break;
#endif
        case SWIM_SIM_XPLANE:
            collectXPlaneTelemetry();
            break;
        default:
            break;
        }

        // OOOI state machine update
        // swim_client_update_oooi() handles state transitions
        // When state changes, sendOOOI() is called

        std::this_thread::sleep_for(
            std::chrono::milliseconds(m_config.telemetry_interval_ms));
    }
}

void AOCClient::collectSimConnectTelemetry() {
    // TODO: SimConnect_RequestDataOnSimObjectType()
    // Read lat/lon/alt/gs/heading from sim
    // Call sendPosition()
}

void AOCClient::collectXPlaneTelemetry() {
    // TODO: XPLMGetDataf() for sim data refs
    // Read lat/lon/alt/gs/heading from sim
    // Call sendPosition()
}

void AOCClient::sendPosition(double lat, double lon, int alt, int gs, int hdg) {
    SwimTrackUpdate track = {};
    track.latitude = lat;
    track.longitude = lon;
    track.altitude = alt;
    track.groundspeed = gs;
    track.heading = hdg;
    // TODO: set callsign, timestamp

    SwimIngestResult result = {};
    swim_client_send_position_throttled(&m_swimClient, &track, &result);
}

void AOCClient::sendOOOI(const char* event_type) {
    // POST to /ingest/acars with type: oooi
    // TODO: Build JSON payload and send
}

void AOCClient::sendProgress(const char* next_wpt, const char* eta_dest, double fuel_remaining) {
    // POST to /ingest/acars with type: progress
    // TODO: Build JSON payload and send
}
```

Create `src/dispatch.cpp`:

```cpp
#include "aoc_client.h"
#include <swim/json.h>
#include <cstdio>

void AOCClient::handleDispatch(const char* json_data, int len) {
    SwimJsonParser parser;
    if (!swim_json_parse(&parser, json_data, len)) return;

    const char* type = swim_json_get_string(&parser, "type");
    if (!type) { swim_json_free(&parser); return; }

    SwimJsonValue* data = swim_json_get_object(&parser, "data");
    if (!data) { swim_json_free(&parser); return; }

    if (strcmp(type, "cdm.edct") == 0 || strcmp(type, "cdm.ctot") == 0) {
        const char* edct = swim_json_get_string(data, "edct_utc");
        int delay = swim_json_get_int(data, "delay_min", 0);
        if (edct) handleEDCTNotification(edct, delay);
    }
    else if (strcmp(type, "cdm.updated") == 0) {
        const char* route = swim_json_get_string(data, "assigned_route");
        if (route) handleRouteAmendment(route);
    }

    swim_json_free(&parser);
}

void AOCClient::handleRouteAmendment(const char* route) {
    printf("[DISPATCH] Route amendment: %s\n", route);

    if (m_simPlatform != SWIM_SIM_NONE) {
        SwimFmsWaypoint waypoints[256];
        int count = swim_fms_parse_route(route, waypoints, 256);
        if (count > 0) {
            SwimFmsResult result = {};
            // TODO: extract dep/dest from current flight
            swim_fms_load_route("", "", waypoints, count, &result);
            if (result.success) {
                printf("[FMS] Route loaded: %d waypoints\n", result.waypoints_loaded);
            } else {
                printf("[FMS] Route load failed: %s\n", result.error);
            }
        }
    }
}

void AOCClient::handleEDCTNotification(const char* edct_utc, int delay_min) {
    printf("[DISPATCH] EDCT assigned: %s (+%d min)\n", edct_utc, delay_min);
    // Display to pilot via console or notification
}
```

Create `src/fms_writer.cpp`:

```cpp
#include "aoc_client.h"
#include <swim/fms.h>

// FMS injection implementations
// SimConnect and XPLM implementations are platform-specific
// This file provides the common interface

// Placeholder — full SimConnect/XPLM implementations require their respective SDKs
```

**Step 5: Create default config**

Create `integrations/aoc-client/vatswim-aoc.yaml.example`:

```yaml
swim:
  api_key: "swim_dev_xxxxx"
  api_url: "https://perti.vatcscc.org/api/swim/v1"
  ws_url: "wss://perti.vatcscc.org/api/swim/v1/ws"

flight:
  callsign: ""  # Auto-detected from sim if empty

telemetry:
  interval_ms: 15000
  position_min_distance_nm: 0.5

va:
  enabled: false
  callback_url: ""  # phpVMS/smartCARS webhook URL
```

**Step 6: Commit**

```bash
git add integrations/aoc-client/
git commit -m "feat(aoc-client): create C++ AOC client skeleton with telemetry and dispatch"
```

---

## Cross-Cutting: Integration Tests

### Task 6.1: Create SWIM API Test Script for Bridge 1

**Files:**
- Create: `scripts/test/test_bridge1_delivery.php`

**Step 1: Write a test script that validates the delivery pipeline end-to-end**

```php
<?php
/**
 * Bridge 1 Integration Test — EDCT Delivery Pipeline
 *
 * Tests: formatters -> dedup -> queueMessage -> delivery channels
 * Run: php scripts/test/test_bridge1_delivery.php
 */

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/services/EDCTDelivery.php';

echo "=== Bridge 1: HoppieWriter Integration Test ===\n\n";

// Test all message formatters
echo "--- Message Formatters ---\n";

$cdm = new CDMService(null, null, null);
$delivery = new EDCTDelivery($cdm, null, true);

$tests = [
    ['formatEDCTMessage', ['2026-03-30 14:30:00', 'GDP KJFK VOLUME', 'GDP-KJFK-001'],
     'EXPECT DEPARTURE CLEARANCE TIME 1430Z DUE GDP KJFK VOLUME. REPORT READY.'],

    ['formatEDCTAmendedMessage', ['2026-03-30 15:00:00', '2026-03-30 14:30:00'],
     'REVISED EDCT 1500Z. PREVIOUS 1430Z'],

    ['formatEDCTCancelMessage', ['2026-03-30 14:30:00'],
     'DISREGARD EDCT 1430Z. DEPART WHEN READY'],

    ['formatCTOTMessage', ['2026-03-30 14:30:00', 'LFFF-REG-001'],
     'CALCULATED TAKEOFF TIME 1430Z. CTOT REGULATION LFFF-REG-001'],

    ['formatGSHoldMessage', ['KATL', '2026-03-30 16:00:00'],
     'GROUND STOP IN EFFECT FOR KATL. HOLD FOR RELEASE. EXPECT UPDATE BY 1600Z'],

    ['formatGSReleaseMessage', ['KATL', 'GDP_ACTIVE'],
     'GROUND STOP RLSD FOR KATL. FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE FLOW PROGRAM'],

    ['formatGSReleaseMessage', ['KATL', 'RELEASED'],
     'GROUND STOP RLSD FOR KATL. DISREGARD EDCT & DEPART WHEN READY'],

    ['formatRerouteMessage', ['RR-2024-001', 'J80 SLT J64 ABQ', 'VOICE', null],
     'REROUTE ADVISORY RR-2024-001. AMEND ROUTE TO J80 SLT J64 ABQ OR STANDBY FOR VOICE CLEARANCE'],

    ['formatRerouteMessage', ['RR-2024-001', 'J80 SLT J64 ABQ', 'DELIVERY', '121.9'],
     'REROUTE ADVISORY RR-2024-001. AMEND ROUTE TO J80 SLT J64 ABQ OR CONTACT DELIVERY AT 121.9 FOR AMENDED CLEARANCE'],

    ['formatMITMessage', [10, 'MERIT'],
     'MILES IN TRAIL 10NM IN EFFECT AT MERIT. EXPECT DELAY.'],

    ['formatTOSQueryMessage', ['KJFK', 'KLAX'],
     'TRAJECTORY OPTIONS REQUESTED FOR KJFK-KLAX. FILE VIA PILOT CLIENT OR VATSWIM.'],
];

$passed = 0;
$failed = 0;
foreach ($tests as [$method, $args, $expected]) {
    $result = call_user_func_array([$delivery, $method], $args);
    if ($result === $expected) {
        echo "  PASS: $method\n";
        $passed++;
    } else {
        echo "  FAIL: $method\n";
        echo "    Expected: $expected\n";
        echo "    Got:      $result\n";
        $failed++;
    }
}

echo "\n--- Results: $passed passed, $failed failed ---\n";
exit($failed > 0 ? 1 : 0);
```

**Step 2: Commit**

```bash
git add scripts/test/test_bridge1_delivery.php
git commit -m "test(bridge1): add message formatter integration test"
```

---

## Summary

| Bridge | Tasks | Key Files | Status |
|--------|-------|-----------|--------|
| **1: HoppieWriter** | 1.1-1.9 | EDCTDelivery.php, vatsim_adl_daemon.php, viff_cdm_poll_daemon.php, WebSocketServer.php | Highest priority |
| **2: FSD Bridge** | 2.1-2.8 | integrations/fsd-bridge/ (Go) | Build code only, no Azure VM |
| **3: EuroScope Plugin** | 3.1-3.4 | integrations/euroscope-plugin/ (C++) + sdk/cpp/ enhancements | |
| **4: Pilot Portal** | 4.1-4.3 | integrations/pilot-portal/ (Vue 3) | |
| **5: AOC Client** | 5.1 | integrations/aoc-client/ (C++) | |
| **Cross-cutting** | 6.1 | scripts/test/ | |

**Implementation order:** Bridge 1 (Tasks 1.1-1.9) -> Bridge 2 (Tasks 2.1-2.8) -> Bridge 3 (Tasks 3.1-3.4) -> Bridge 4 (Tasks 4.1-4.3) -> Bridge 5 (Task 5.1) -> Tests (Task 6.1)
