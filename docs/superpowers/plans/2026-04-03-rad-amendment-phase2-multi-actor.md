# RAD Amendment V2 Phase 2: Multi-Actor Roles — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add role detection (TMU/ATC/Pilot/VA/Observer) with VNAS controller feed integration, role-based tab visibility, and ATC/Pilot/VA amendment queue views.

**Architecture:** New `rad-role.js` handles client-side role state. New `VNASService.php` polls VNAS controller feed. `api/rad/role.php` returns detected role with capabilities. Existing modules conditionally show/hide UI based on role.

**Tech Stack:** PHP 8.2, Azure SQL (sqlsrv), MySQL (MySQLi), jQuery 2.2.4

**Spec:** `docs/superpowers/specs/2026-04-03-rad-amendment-workflow-design.md` (Sections 5, 7.4, 8.2-8.3, 9.2, 9.5-9.6, 10.2, 11)

**Depends on:** Phase 1 (ISSUED state must exist)

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `assets/js/rad-role.js` | Role detection, capability gating, UI adaptation |
| Create | `load/services/VNASService.php` | VNAS controller feed polling + caching |
| Create | `api/rad/role.php` | Role detection endpoint |
| Create | `database/migrations/schema/rad_role_cache.sql` | MySQL role cache table |
| Modify | `api/rad/common.php` | `rad_require_role()` helper |
| Modify | `api/rad/amendment.php` | Role-aware auth for issue/accept/reject |
| Modify | `assets/js/rad.js` | Role-based tab visibility, VA selector |
| Modify | `assets/js/rad-monitoring.js` | Role-based filtering, Accept/Reject buttons |
| Modify | `assets/js/rad-flight-search.js` | Role-based search scope |
| Modify | `rad.php` | Role indicator bar, VA selector dropdown |
| Modify | `assets/css/rad.css` | Role badge styles |
| Modify | `assets/locales/en-US.json` | Role i18n keys |

---

### Task 1: MySQL Role Cache Table

**Files:**
- Create: `database/migrations/schema/rad_role_cache.sql`

- [ ] **Step 1: Write migration**

```sql
-- Role cache for RAD page (perti_site MySQL)
-- Stores detected role per CID with 5-minute TTL
CREATE TABLE IF NOT EXISTS rad_role_cache (
    cid             INT PRIMARY KEY,
    detected_role   VARCHAR(10) NOT NULL,
    artcc_id        VARCHAR(4) NULL,
    facility_id     VARCHAR(10) NULL,
    position_type   VARCHAR(10) NULL,
    callsign        VARCHAR(20) NULL,
    flight_gufi     VARCHAR(64) NULL,
    airline_icao    VARCHAR(4) NULL,
    detected_utc    DATETIME NOT NULL DEFAULT UTC_TIMESTAMP,
    expires_utc     DATETIME NOT NULL,
    INDEX IX_rad_role_expires (expires_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Deploy to perti_site MySQL**

Run against `vatcscc-perti.mysql.database.azure.com` / `perti_site`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/schema/rad_role_cache.sql
git commit -m "feat(rad): MySQL rad_role_cache table for role detection caching"
```

---

### Task 2: VNASService — Controller Feed Polling

**Files:**
- Create: `load/services/VNASService.php`

- [ ] **Step 1: Create VNASService**

```php
<?php
/**
 * VNASService — VNAS Controller Feed Integration
 *
 * Polls the VNAS live data feed to detect active ATC controllers.
 * Feed URL: https://live.env.vnas.vatsim.net/data-feed/controllers.json
 * Cache: File-based with 60-second TTL.
 */
class VNASService
{
    private const FEED_URL = 'https://live.env.vnas.vatsim.net/data-feed/controllers.json';
    private const CACHE_TTL = 60;
    private const CACHE_FILE = '/tmp/vnas_controllers.json';

    /**
     * Get all active controllers from VNAS feed (cached).
     * @return array Array of controller objects
     */
    public static function getControllers(): array
    {
        // Check cache
        $cache = self::readCache();
        if ($cache !== null) {
            return $cache;
        }

        // Fetch from VNAS
        $ch = curl_init(self::FEED_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            // Return stale cache if available, otherwise empty
            return self::readCache(true) ?? [];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return self::readCache(true) ?? [];
        }

        // Write cache
        self::writeCache($data);

        return $data;
    }

    /**
     * Find a controller by VATSIM CID.
     * @param int $cid VATSIM CID
     * @return array|null Controller data or null
     */
    public static function findByCID(int $cid): ?array
    {
        $controllers = self::getControllers();
        $cidStr = (string)$cid;

        foreach ($controllers as $controller) {
            $controllerCid = $controller['vatsimData']['cid'] ?? null;
            if ($controllerCid !== null && (string)$controllerCid === $cidStr) {
                return $controller;
            }
        }

        return null;
    }

    /**
     * Quick check if CID is an active controller.
     */
    public static function isActiveController(int $cid): bool
    {
        return self::findByCID($cid) !== null;
    }

    /**
     * Extract role context from controller data.
     */
    public static function extractContext(array $controller): array
    {
        $positions = $controller['positions'] ?? [];
        $firstPos = $positions[0] ?? [];

        return [
            'artcc_id' => $controller['artccId'] ?? null,
            'facility_id' => $firstPos['facilityId'] ?? $controller['primaryFacilityId'] ?? null,
            'position_type' => $firstPos['positionType'] ?? null,
            'callsign' => $controller['vatsimData']['callsign'] ?? null,
            'facility_name' => $firstPos['facilityName'] ?? null,
        ];
    }

    private static function readCache(bool $ignoreExpiry = false): ?array
    {
        if (!file_exists(self::CACHE_FILE)) return null;

        $raw = @file_get_contents(self::CACHE_FILE);
        if ($raw === false) return null;

        $cached = json_decode($raw, true);
        if (!is_array($cached) || !isset($cached['data']) || !isset($cached['timestamp'])) return null;

        if (!$ignoreExpiry && (time() - $cached['timestamp']) > self::CACHE_TTL) {
            return null;
        }

        return $cached['data'];
    }

    private static function writeCache(array $data): void
    {
        $payload = json_encode([
            'timestamp' => time(),
            'data' => $data,
        ]);
        @file_put_contents(self::CACHE_FILE, $payload, LOCK_EX);
    }
}
```

- [ ] **Step 2: Verify VNAS feed returns data**

```php
require_once 'load/services/VNASService.php';
$controllers = VNASService::getControllers();
echo count($controllers) . " controllers online\n";
// Expected: 0+ (depends on VATSIM traffic)
```

- [ ] **Step 3: Commit**

```bash
git add load/services/VNASService.php
git commit -m "feat(rad): VNASService — VNAS controller feed polling with file cache"
```

---

### Task 3: Role Detection Endpoint

**Files:**
- Create: `api/rad/role.php`

- [ ] **Step 1: Create role.php endpoint**

```php
<?php
/** RAD API: Role Detection — GET /api/rad/role.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../load/services/VNASService.php';

$cid = rad_require_auth();
$cid_int = (int)$cid;

// Priority 1: TMU (admin_users check)
$is_tmu = false;
global $conn_sqli;
$stmt = $conn_sqli->prepare("SELECT 1 FROM admin_users WHERE cid=? LIMIT 1");
$stmt->bind_param('i', $cid_int);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $is_tmu = true;
}
$stmt->close();

if ($is_tmu) {
    rad_respond_json(200, [
        'status' => 'ok',
        'data' => [
            'role' => 'TMU',
            'cid' => $cid_int,
            'context' => [],
            'capabilities' => [
                'can_create_amendment' => true,
                'can_issue' => true,
                'can_accept_reject' => false,
                'can_submit_tos' => false,
                'can_resolve_tos' => true,
                'can_force' => true,
                'tabs' => ['search', 'detail', 'edit', 'monitoring'],
            ],
        ],
    ]);
}

// Priority 2: ATC (VNAS controller feed)
$controller = VNASService::findByCID($cid_int);
if ($controller) {
    $ctx = VNASService::extractContext($controller);
    rad_respond_json(200, [
        'status' => 'ok',
        'data' => [
            'role' => 'ATC',
            'cid' => $cid_int,
            'context' => $ctx,
            'capabilities' => [
                'can_create_amendment' => false,
                'can_issue' => true,
                'can_accept_reject' => true,
                'can_submit_tos' => true,
                'can_resolve_tos' => false,
                'can_force' => false,
                'tabs' => ['detail', 'monitoring'],
            ],
        ],
    ]);
}

// Priority 3: Check for VA role override (from request param)
$va_airline = $_GET['va_airline'] ?? null;
if ($va_airline) {
    rad_respond_json(200, [
        'status' => 'ok',
        'data' => [
            'role' => 'VA',
            'cid' => $cid_int,
            'context' => ['airline_icao' => strtoupper($va_airline)],
            'capabilities' => [
                'can_create_amendment' => false,
                'can_issue' => true,
                'can_accept_reject' => true,
                'can_submit_tos' => true,
                'can_resolve_tos' => false,
                'can_force' => false,
                'tabs' => ['detail', 'monitoring'],
            ],
        ],
    ]);
}

// Priority 4: Pilot (CID in adl_flight_core)
global $conn_adl;
if ($conn_adl) {
    $sql = "SELECT TOP 1 flight_key AS gufi, callsign
            FROM dbo.adl_flight_core
            WHERE cid = ? AND is_active = 1
            ORDER BY inserted_utc DESC";
    $pilot_stmt = sqlsrv_query($conn_adl, $sql, [$cid_int]);
    if ($pilot_stmt) {
        $pilot_row = sqlsrv_fetch_array($pilot_stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($pilot_stmt);
        if ($pilot_row) {
            rad_respond_json(200, [
                'status' => 'ok',
                'data' => [
                    'role' => 'PILOT',
                    'cid' => $cid_int,
                    'context' => [
                        'flight_gufi' => $pilot_row['gufi'],
                        'callsign' => $pilot_row['callsign'],
                    ],
                    'capabilities' => [
                        'can_create_amendment' => false,
                        'can_issue' => false,
                        'can_accept_reject' => true,
                        'can_submit_tos' => true,
                        'can_resolve_tos' => false,
                        'can_force' => false,
                        'tabs' => ['monitoring'],
                    ],
                ],
            ]);
        }
    }
}

// Priority 5: Observer (authenticated but no role)
rad_respond_json(200, [
    'status' => 'ok',
    'data' => [
        'role' => 'OBSERVER',
        'cid' => $cid_int,
        'context' => [],
        'capabilities' => [
            'can_create_amendment' => false,
            'can_issue' => false,
            'can_accept_reject' => false,
            'can_submit_tos' => false,
            'can_resolve_tos' => false,
            'can_force' => false,
            'tabs' => [],
        ],
    ],
]);
```

- [ ] **Step 2: Verify endpoint**

```bash
curl -s https://perti.vatcscc.org/api/rad/role.php -H "Cookie: <session>" | python -m json.tool
# Expected: {"status":"ok","data":{"role":"TMU",...}} (for admin user)
```

- [ ] **Step 3: Commit**

```bash
git add api/rad/role.php
git commit -m "feat(rad): role detection endpoint with VNAS + CID + TMU priority chain"
```

---

### Task 4: Client-Side Role Module

**Files:**
- Create: `assets/js/rad-role.js`

- [ ] **Step 1: Create rad-role.js**

```javascript
/**
 * RAD Role Module — Role detection and UI capability gating.
 *
 * Public API:
 *   RADRole.init()
 *   RADRole.getRole() → 'TMU'|'ATC'|'PILOT'|'VA'|'OBSERVER'
 *   RADRole.getContext()
 *   RADRole.can(capability)
 *   RADRole.setVAContext(airlineIcao)
 *   RADRole.onRoleChanged(callback)
 */
window.RADRole = (function() {
    var currentRole = null;
    var currentContext = {};
    var capabilities = {};
    var allowedTabs = [];
    var changeCallbacks = [];
    var refreshTimer = null;

    function init() {
        fetchRole();
        // Re-check every 5 minutes
        refreshTimer = setInterval(fetchRole, 5 * 60 * 1000);
    }

    function fetchRole(vaAirline) {
        var params = {};
        // Check sessionStorage for VA context
        var savedVA = sessionStorage.getItem('RAD_VA_CONTEXT');
        if (vaAirline) {
            params.va_airline = vaAirline;
        } else if (savedVA) {
            params.va_airline = savedVA;
        }

        $.get('api/rad/role.php', params)
            .done(function(response) {
                if (response.status === 'ok' && response.data) {
                    var d = response.data;
                    var oldRole = currentRole;
                    currentRole = d.role;
                    currentContext = d.context || {};
                    capabilities = d.capabilities || {};
                    allowedTabs = capabilities.tabs || [];

                    applyRoleUI();

                    if (oldRole !== currentRole) {
                        RADEventBus.emit('role:detected', {
                            role: currentRole,
                            context: currentContext,
                            capabilities: capabilities
                        });
                        changeCallbacks.forEach(function(cb) {
                            try { cb(currentRole, currentContext); } catch(e) {}
                        });
                    }
                }
            })
            .fail(function() {
                // Default to observer on failure
                currentRole = 'OBSERVER';
                capabilities = {};
                allowedTabs = [];
                applyRoleUI();
            });
    }

    function applyRoleUI() {
        // Update role indicator
        var $indicator = $('#rad_role_indicator');
        if ($indicator.length) {
            var roleLabel = PERTII18n.t('rad.role.' + (currentRole || 'observer').toLowerCase());
            var badgeClass = {
                'TMU': 'badge-danger',
                'ATC': 'badge-primary',
                'PILOT': 'badge-success',
                'VA': 'badge-info',
                'OBSERVER': 'badge-secondary'
            }[currentRole] || 'badge-secondary';

            $indicator.html(
                '<span class="badge ' + badgeClass + '">' + roleLabel + '</span>' +
                (currentContext.callsign ? ' <span class="text-muted">' + currentContext.callsign + '</span>' : '') +
                (currentContext.artcc_id ? ' <span class="text-muted">(' + currentContext.artcc_id + ')</span>' : '')
            );
        }

        // Show/hide tabs based on role
        var allTabs = ['search', 'detail', 'edit', 'monitoring'];
        allTabs.forEach(function(tab) {
            var $tabLink = $('#tab-' + tab).closest('.nav-item');
            if (allowedTabs.indexOf(tab) !== -1) {
                $tabLink.show();
            } else {
                $tabLink.hide();
            }
        });

        // If current active tab is hidden, switch to first allowed tab
        var activeTab = $('#radTabs .nav-link.active').attr('id');
        var activeTabName = activeTab ? activeTab.replace('tab-', '') : '';
        if (allowedTabs.length > 0 && allowedTabs.indexOf(activeTabName) === -1) {
            $('#tab-' + allowedTabs[0]).tab('show');
        }

        // Show VA selector only when no role detected (or already VA)
        var $vaSelector = $('#rad_va_selector');
        if ($vaSelector.length) {
            if (currentRole === 'OBSERVER' || currentRole === 'VA') {
                $vaSelector.show();
            } else {
                $vaSelector.hide();
            }
        }
    }

    function setVAContext(airlineIcao) {
        if (airlineIcao) {
            sessionStorage.setItem('RAD_VA_CONTEXT', airlineIcao);
        } else {
            sessionStorage.removeItem('RAD_VA_CONTEXT');
        }
        fetchRole(airlineIcao || undefined);
    }

    return {
        init: init,
        getRole: function() { return currentRole; },
        getContext: function() { return currentContext; },
        can: function(cap) { return capabilities[cap] === true; },
        setVAContext: setVAContext,
        onRoleChanged: function(cb) { changeCallbacks.push(cb); },
        refresh: function() { fetchRole(); }
    };
})();
```

- [ ] **Step 2: Add script tag to rad.php**

Add before `rad-event-bus.js` (it should load early so other modules can check role):

```html
<script src="assets/js/rad-role.js<?= _v('assets/js/rad-role.js') ?>"></script>
```

Actually, it depends on RADEventBus and PERTII18n, so add after `rad-event-bus.js` and before `rad-flight-search.js`.

- [ ] **Step 3: Add role indicator bar to rad.php**

In `rad.php`, after the opening `<div class="rad-app">` tag (line 54), add:

```html
<div class="rad-role-bar d-flex align-items-center px-3 py-1" style="background:#16213e; border-bottom:1px solid #334; font-size:0.82rem;">
    <span class="text-muted mr-2" data-i18n="rad.role.detecting">Detecting role...</span>
    <span id="rad_role_indicator"></span>
    <div id="rad_va_selector" class="ml-auto" style="display:none;">
        <select id="rad_va_airline" class="form-control form-control-sm" style="width:auto;display:inline-block;background:#111;color:#ccc;border-color:#445;font-size:0.82rem;">
            <option value="">-- Select VA --</option>
        </select>
    </div>
</div>
```

- [ ] **Step 4: Initialize role module in rad.js**

In `rad.js` (`RADController.init()`), add at the beginning (before other module inits):

```javascript
// Initialize role detection (must be before other modules)
if (window.RADRole) {
    RADRole.init();
}
```

And bind VA selector:

```javascript
// VA selector
$('#rad_va_airline').on('change', function() {
    var airline = $(this).val();
    if (window.RADRole) RADRole.setVAContext(airline);
});
```

- [ ] **Step 5: Add role i18n keys**

Add to `en-US.json` under `rad.role`:

```json
"role": {
    "tmu": "TMU",
    "atc": "ATC",
    "pilot": "Pilot",
    "va": "Virtual Airline",
    "observer": "Observer",
    "detecting": "Detecting role..."
}
```

- [ ] **Step 6: Verify role detection**

Open RAD page as admin → role bar shows "TMU" badge in red.
Open in incognito (non-admin) → shows "Observer" in gray.

- [ ] **Step 7: Commit**

```bash
git add assets/js/rad-role.js rad.php assets/js/rad.js assets/locales/en-US.json
git commit -m "feat(rad): role detection module with VNAS integration and UI gating"
```

---

### Task 5: Role-Aware Amendment Actions

**Files:**
- Modify: `api/rad/common.php` — add `rad_require_role()`
- Modify: `api/rad/amendment.php` — role-based auth for issue/accept/reject
- Modify: `load/services/RADService.php` — `acceptAmendment()`, `rejectAmendment()`

- [ ] **Step 1: Add rad_require_role() to common.php**

In `api/rad/common.php`, add after `rad_require_tmu()`:

```php
/**
 * Detect role for CID and return it. Does NOT block — returns role string.
 * Roles: TMU, ATC, PILOT, VA, OBSERVER
 */
function rad_detect_role($cid) {
    global $conn_sqli, $conn_adl;

    // TMU check
    $stmt = $conn_sqli->prepare("SELECT 1 FROM admin_users WHERE cid=? LIMIT 1");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $result = $stmt->get_result();
    $is_tmu = $result && $result->num_rows > 0;
    $stmt->close();
    if ($is_tmu) return 'TMU';

    // ATC check (VNAS)
    require_once(__DIR__ . '/../../load/services/VNASService.php');
    if (VNASService::isActiveController((int)$cid)) return 'ATC';

    return 'OBSERVER';
}

/**
 * Require specific role(s) for an action.
 * @param int $cid
 * @param array $allowed_roles e.g. ['TMU', 'ATC']
 * @return string The detected role
 */
function rad_require_role($cid, array $allowed_roles) {
    $role = rad_detect_role($cid);
    if (!in_array($role, $allowed_roles)) {
        rad_respond_json(403, [
            'status' => 'error',
            'message' => 'This action requires role: ' . implode(' or ', $allowed_roles) . '. Your role: ' . $role
        ]);
    }
    return $role;
}
```

- [ ] **Step 2: Add accept/reject methods to RADService**

In `RADService.php`, add after `issueAmendment()`:

```php
/**
 * Accept amendment on behalf of pilot.
 */
public function acceptAmendment(int $id, int $cid, string $role): array
{
    if (!$this->radTableExists()) return ['error' => 'RAD tables not yet deployed'];

    $amendment = $this->getAmendment($id);
    if (!$amendment) return ['error' => 'Amendment not found'];
    if ($amendment['status'] !== 'ISSUED') {
        return ['error' => 'Only ISSUED amendments can be accepted'];
    }

    $sql = "UPDATE dbo.rad_amendments
            SET status = 'ACPT', resolved_utc = SYSUTCDATETIME(), actor_role = ?
            WHERE id = ?";
    $stmt = sqlsrv_query($this->conn_tmi, $sql, [$role, $id]);
    if ($stmt === false) return ['error' => 'Update failed'];
    sqlsrv_free_stmt($stmt);

    $this->logTransition($id, 'ISSUED', 'ACPT', "Accepted by $role (CID: $cid)", $cid);

    $this->broadcastWebSocket('rad:amendment_update', [
        'amendment_id' => $id, 'gufi' => $amendment['gufi'], 'status' => 'ACPT',
    ]);

    return ['success' => true, 'status' => 'ACPT'];
}

/**
 * Reject amendment (with or without TOS).
 */
public function rejectAmendment(int $id, int $cid, string $role, bool $with_tos = false): array
{
    if (!$this->radTableExists()) return ['error' => 'RAD tables not yet deployed'];

    $amendment = $this->getAmendment($id);
    if (!$amendment) return ['error' => 'Amendment not found'];
    if ($amendment['status'] !== 'ISSUED') {
        return ['error' => 'Only ISSUED amendments can be rejected'];
    }

    $new_status = $with_tos ? 'TOS_PENDING' : 'RJCT';

    $sql = "UPDATE dbo.rad_amendments
            SET status = ?, rejected_by = ?, rejected_utc = SYSUTCDATETIME(), actor_role = ?
            WHERE id = ?";
    $stmt = sqlsrv_query($this->conn_tmi, $sql, [$new_status, $cid, $role, $id]);
    if ($stmt === false) return ['error' => 'Update failed'];
    sqlsrv_free_stmt($stmt);

    $detail = $with_tos ? "Rejected with TOS by $role" : "Rejected by $role";
    $this->logTransition($id, 'ISSUED', $new_status, "$detail (CID: $cid)", $cid);

    $this->broadcastWebSocket('rad:amendment_update', [
        'amendment_id' => $id, 'gufi' => $amendment['gufi'], 'status' => $new_status,
    ]);

    return ['success' => true, 'status' => $new_status];
}
```

- [ ] **Step 3: Add accept/reject/issue handlers in amendment.php**

In `api/rad/amendment.php`, update the POST block. Replace the existing TMU-only auth with role-aware auth for specific actions:

```php
// POST operations: role-aware auth
if ($method === 'POST' || $method === 'DELETE') {
    // Default: TMU required. Override for specific actions below.
    $role_actions = ['issue', 'accept', 'reject'];
    if (!in_array($action, $role_actions)) {
        rad_require_tmu($cid);
    }
}
```

Add accept and reject handlers:

```php
} elseif ($action === 'accept') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
    $role = rad_require_role((int)$cid, ['TMU', 'ATC', 'VA', 'PILOT']);
    $result = $svc->acceptAmendment($id, (int)$cid, $role);
    if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
    rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

} elseif ($action === 'reject') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
    $role = rad_require_role((int)$cid, ['ATC', 'VA', 'PILOT']);
    $with_tos = !empty($body['with_tos']);
    $result = $svc->rejectAmendment($id, (int)$cid, $role, $with_tos);
    if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
    rad_respond_json(200, ['status' => 'ok', 'data' => $result]);
```

Update the issue handler to use role auth:

```php
} elseif ($action === 'issue') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
    $role = rad_require_role((int)$cid, ['TMU', 'ATC', 'VA']);
    $result = $svc->issueAmendment($id, (int)$cid, $role);
    if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
    rad_respond_json(200, ['status' => 'ok', 'data' => $result]);
```

- [ ] **Step 4: Add Accept/Reject buttons in monitoring module**

In `rad-monitoring.js`, in `getActionButtons()`, add for ISSUED status:

```javascript
if (a.status === 'ISSUED' && window.RADRole) {
    if (RADRole.can('can_accept_reject')) {
        html += '<button class="btn btn-sm btn-outline-success rad-btn-accept mr-1" data-id="' + a.id + '">' + PERTII18n.t('rad.actions.acceptOnBehalf') + '</button>';
        html += '<button class="btn btn-sm btn-outline-danger rad-btn-reject mr-1" data-id="' + a.id + '">' + PERTII18n.t('rad.actions.rejectOnBehalf') + '</button>';
    }
}
```

Bind click handlers:

```javascript
$(document).on('click', '.rad-btn-accept', function() {
    var id = $(this).data('id');
    $.post('api/rad/amendment.php', { id: id, action: 'accept' })
        .done(function(r) { if (r.status === 'ok') { PERTIDialog.success(PERTII18n.t('rad.status.ACPT')); refresh(); } else { PERTIDialog.warning(r.message); } })
        .fail(function() { PERTIDialog.warning(PERTII18n.t('error.networkError')); });
});

$(document).on('click', '.rad-btn-reject', function() {
    var id = $(this).data('id');
    $.post('api/rad/amendment.php', { id: id, action: 'reject' })
        .done(function(r) { if (r.status === 'ok') { PERTIDialog.success(PERTII18n.t('rad.status.RJCT')); refresh(); } else { PERTIDialog.warning(r.message); } })
        .fail(function() { PERTIDialog.warning(PERTII18n.t('error.networkError')); });
});
```

- [ ] **Step 5: Verify**

1. As TMU: can create, send, mark issued, resolve TOS, force. Sees all tabs.
2. As non-admin/non-ATC: sees Observer role, no tabs.
3. Issue/Accept/Reject buttons appear based on role capabilities.

- [ ] **Step 6: Commit**

```bash
git add api/rad/common.php api/rad/amendment.php load/services/RADService.php assets/js/rad-monitoring.js
git commit -m "feat(rad): role-aware accept/reject/issue with VNAS-based role detection"
```

---

### Task 6: End-to-End Phase 2 Verification

- [ ] **Step 1: Full workflow test**

1. Open RAD as admin → TMU badge, all 4 tabs visible
2. Create + send amendment → shows in Monitoring
3. Mark as Issued → ISSUED badge
4. Accept → ACPT
5. Role bar shows TMU with red badge
6. VA selector appears only for Observer role
7. Tab visibility changes based on role

- [ ] **Step 2: Commit all remaining changes and push**

```bash
git add -A
git commit -m "feat(rad): Phase 2 complete — multi-actor role detection and capability gating"
```
