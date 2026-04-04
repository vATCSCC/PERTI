# RAD Amendment V2 Phase 3: TOS Workflow — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Trajectory Option Set (TOS) workflow — pilot rejection with ranked route preferences, TMU resolution with distance/time diffs and color-coded map comparison.

**Architecture:** New `rad-tos.js` handles TOS entry form (for ATC/Pilot/VA) and resolution panel (for TMU). New `api/rad/tos.php` endpoint. New `api/rad/route-metrics.php` computes PostGIS distances. `RADService.php` gets TOS lifecycle methods.

**Tech Stack:** PHP 8.2, Azure SQL (sqlsrv), PostgreSQL/PostGIS (PDO), jQuery 2.2.4, MapLibre GL

**Spec:** `docs/superpowers/specs/2026-04-03-rad-amendment-workflow-design.md` (Sections 6, 7.2-7.3, 8.1, 8.4, 9.3, 10.1)

**Depends on:** Phase 1 (clearance builder, ISSUED state) + Phase 2 (role detection, accept/reject)

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `assets/js/rad-tos.js` | TOS entry form + resolution panel + map plotting |
| Create | `api/rad/tos.php` | TOS CRUD endpoint |
| Create | `api/rad/route-metrics.php` | Route distance/time computation via PostGIS |
| Modify | `load/services/RADService.php` | `submitTOS()`, `resolveTOS()`, `forceAmendment()`, `computeRouteMetrics()` |
| Modify | `assets/js/rad-monitoring.js` | TOS badges, "Enter TOS" button, "Resolve TOS" expandable panel |
| Modify | `rad.php` | TOS modal containers |
| Modify | `assets/css/rad.css` | TOS panel styles |
| Modify | `assets/locales/en-US.json` | TOS i18n keys |

---

### Task 1: Route Metrics Endpoint (PostGIS)

**Files:**
- Create: `api/rad/route-metrics.php`

- [ ] **Step 1: Create route-metrics.php**

```php
<?php
/** RAD API: Route Metrics — POST /api/rad/route-metrics.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

$cid = rad_require_auth();
$body = rad_read_payload();

$routes = $body['routes'] ?? [];
if (!is_array($routes) || count($routes) === 0) {
    rad_respond_json(400, ['status' => 'error', 'message' => 'routes array required']);
}
if (count($routes) > 10) {
    rad_respond_json(400, ['status' => 'error', 'message' => 'Maximum 10 routes per request']);
}

$cruise_speed = (int)($body['cruise_speed_kts'] ?? 450);
if ($cruise_speed < 100) $cruise_speed = 450;

$conn_gis = get_conn_gis();
if (!$conn_gis) {
    rad_respond_json(500, ['status' => 'error', 'message' => 'PostGIS unavailable']);
}

$metrics = [];
foreach ($routes as $route_string) {
    $route_string = trim($route_string);
    if (empty($route_string)) {
        $metrics[] = ['route' => $route_string, 'distance_nm' => null, 'time_minutes' => null, 'geojson' => null];
        continue;
    }

    try {
        // Compute distance via expand_route() + ST_Length()
        $sql = "SELECT
                    ST_Length(
                        ST_MakeLine(ARRAY(
                            SELECT geom FROM expand_route(:route) ORDER BY seq
                        ))::geography
                    ) / 1852.0 AS distance_nm,
                    ST_AsGeoJSON(
                        ST_MakeLine(ARRAY(
                            SELECT geom FROM expand_route(:route2) ORDER BY seq
                        ))
                    ) AS geojson";

        $stmt = $conn_gis->prepare($sql);
        $stmt->execute([':route' => $route_string, ':route2' => $route_string]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $distance = $row['distance_nm'] ? round((float)$row['distance_nm'], 1) : null;
        $time_min = $distance ? (int)round($distance / $cruise_speed * 60) : null;
        $geojson = $row['geojson'] ? json_decode($row['geojson'], true) : null;

        $metrics[] = [
            'route' => $route_string,
            'distance_nm' => $distance,
            'time_minutes' => $time_min,
            'geojson' => $geojson,
        ];
    } catch (\Exception $e) {
        $metrics[] = [
            'route' => $route_string,
            'distance_nm' => null,
            'time_minutes' => null,
            'geojson' => null,
            'error' => $e->getMessage(),
        ];
    }
}

rad_respond_json(200, [
    'status' => 'ok',
    'data' => ['metrics' => $metrics, 'baseline_index' => 0],
]);
```

- [ ] **Step 2: Verify**

```bash
curl -X POST https://perti.vatcscc.org/api/rad/route-metrics.php \
  -H "Content-Type: application/json" \
  -H "Cookie: <session>" \
  -d '{"routes":["KATL BRIGS J60 PHILA COLIN CAMRN KJFK","KATL BRIGS J80 SBY COLIN CAMRN KJFK"]}'
# Expected: metrics array with distance_nm, time_minutes, geojson per route
```

- [ ] **Step 3: Commit**

```bash
git add api/rad/route-metrics.php
git commit -m "feat(rad): route-metrics endpoint — PostGIS distance + time + GeoJSON"
```

---

### Task 2: TOS Service Methods

**Files:**
- Modify: `load/services/RADService.php`

- [ ] **Step 1: Add submitTOS() method**

```php
/**
 * Submit a Trajectory Option Set for an ISSUED amendment.
 */
public function submitTOS(int $amendment_id, int $cid, string $role, array $options): array
{
    if (!$this->radTableExists()) return ['error' => 'RAD tables not yet deployed'];

    $amendment = $this->getAmendment($amendment_id);
    if (!$amendment) return ['error' => 'Amendment not found'];
    if ($amendment['status'] !== 'ISSUED') {
        return ['error' => 'TOS can only be submitted for ISSUED amendments'];
    }
    if (empty($options) || !is_array($options)) {
        return ['error' => 'At least one route option is required'];
    }

    // Create TOS record
    $sql = "INSERT INTO dbo.rad_tos (amendment_id, gufi, submitted_by, submitted_role)
            OUTPUT INSERTED.id
            VALUES (?, ?, ?, ?)";
    $stmt = sqlsrv_query($this->conn_tmi, $sql, [
        $amendment_id, $amendment['gufi'], $cid, $role
    ]);
    if ($stmt === false) return ['error' => 'TOS insert failed'];
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $tos_id = $row['id'] ?? null;
    sqlsrv_free_stmt($stmt);
    if (!$tos_id) return ['error' => 'Failed to get TOS ID'];

    // Insert options
    foreach ($options as $opt) {
        $opt_sql = "INSERT INTO dbo.rad_tos_options (tos_id, rank, route_string, option_type, distance_nm, time_minutes)
                    VALUES (?, ?, ?, ?, ?, ?)";
        $opt_stmt = sqlsrv_query($this->conn_tmi, $opt_sql, [
            $tos_id,
            (int)($opt['rank'] ?? 0),
            $opt['route_string'] ?? '',
            $opt['option_type'] ?? 'pilot_option',
            isset($opt['distance_nm']) ? (float)$opt['distance_nm'] : null,
            isset($opt['time_minutes']) ? (int)$opt['time_minutes'] : null,
        ]);
        if ($opt_stmt) sqlsrv_free_stmt($opt_stmt);
    }

    // Transition amendment to TOS_PENDING
    $upd = "UPDATE dbo.rad_amendments
            SET status = 'TOS_PENDING', tos_id = ?, rejected_by = ?, rejected_utc = SYSUTCDATETIME(), actor_role = ?
            WHERE id = ?";
    $upd_stmt = sqlsrv_query($this->conn_tmi, $upd, [$tos_id, $cid, $role, $amendment_id]);
    if ($upd_stmt) sqlsrv_free_stmt($upd_stmt);

    $this->logTransition($amendment_id, 'ISSUED', 'TOS_PENDING',
        "TOS submitted by $role (CID: $cid, $tos_id)", $cid);

    $this->broadcastWebSocket('tos:submitted', [
        'tos_id' => $tos_id, 'amendment_id' => $amendment_id,
        'gufi' => $amendment['gufi'],
    ]);

    return ['success' => true, 'tos_id' => $tos_id, 'status' => 'TOS_PENDING'];
}
```

- [ ] **Step 2: Add getTOS() method**

```php
/**
 * Get TOS data with options for an amendment.
 */
public function getTOS(int $amendment_id): ?array
{
    $sql = "SELECT * FROM dbo.rad_tos WHERE amendment_id = ? ORDER BY id DESC";
    $stmt = sqlsrv_query($this->conn_tmi, $sql, [$amendment_id]);
    if (!$stmt) return null;
    $tos = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$tos) return null;

    foreach ($tos as $k => $v) {
        if ($v instanceof \DateTimeInterface) $tos[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
    }

    // Get options
    $opt_sql = "SELECT * FROM dbo.rad_tos_options WHERE tos_id = ? ORDER BY rank ASC";
    $opt_stmt = sqlsrv_query($this->conn_tmi, $opt_sql, [$tos['id']]);
    $options = [];
    if ($opt_stmt) {
        while ($opt = sqlsrv_fetch_array($opt_stmt, SQLSRV_FETCH_ASSOC)) {
            $options[] = $opt;
        }
        sqlsrv_free_stmt($opt_stmt);
    }
    $tos['options'] = $options;

    return $tos;
}
```

- [ ] **Step 3: Add resolveTOS() method**

```php
/**
 * Resolve TOS: accept an option, counter-propose, or force.
 */
public function resolveTOS(int $tos_id, string $action, ?int $accepted_rank, ?string $counter_route, int $tmu_cid): array
{
    $sql = "SELECT * FROM dbo.rad_tos WHERE id = ?";
    $stmt = sqlsrv_query($this->conn_tmi, $sql, [$tos_id]);
    if (!$stmt) return ['error' => 'Query failed'];
    $tos = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$tos) return ['error' => 'TOS not found'];

    $amendment_id = $tos['amendment_id'];
    $amendment = $this->getAmendment($amendment_id);
    if (!$amendment || $amendment['status'] !== 'TOS_PENDING') {
        return ['error' => 'Amendment not in TOS_PENDING state'];
    }

    // Resolve TOS record
    $upd = "UPDATE dbo.rad_tos SET resolved_utc = SYSUTCDATETIME(), resolved_action = ?,
            resolved_option_rank = ?, resolved_by = ? WHERE id = ?";
    $upd_stmt = sqlsrv_query($this->conn_tmi, $upd, [$action, $accepted_rank, $tmu_cid, $tos_id]);
    if ($upd_stmt) sqlsrv_free_stmt($upd_stmt);

    if ($action === 'FORCE') {
        // Force original amendment
        $force_sql = "UPDATE dbo.rad_amendments
                      SET status = 'FORCED', forced_utc = SYSUTCDATETIME(), resolved_by = ?, actor_role = 'TMU'
                      WHERE id = ?";
        $force_stmt = sqlsrv_query($this->conn_tmi, $force_sql, [$tmu_cid, $amendment_id]);
        if ($force_stmt) sqlsrv_free_stmt($force_stmt);
        $this->logTransition($amendment_id, 'TOS_PENDING', 'FORCED', "Forced by TMU (CID: $tmu_cid)", $tmu_cid);

        return ['success' => true, 'status' => 'FORCED'];
    }

    // ACCEPT or COUNTER — resolve original, create new DRAFT
    $resolve_sql = "UPDATE dbo.rad_amendments
                    SET status = 'TOS_RESOLVED', resolved_utc = SYSUTCDATETIME(), resolved_by = ?, actor_role = 'TMU'
                    WHERE id = ?";
    $resolve_stmt = sqlsrv_query($this->conn_tmi, $resolve_sql, [$tmu_cid, $amendment_id]);
    if ($resolve_stmt) sqlsrv_free_stmt($resolve_stmt);
    $this->logTransition($amendment_id, 'TOS_PENDING', 'TOS_RESOLVED', "Resolved ($action) by TMU (CID: $tmu_cid)", $tmu_cid);

    // Determine new route
    $new_route = null;
    if ($action === 'ACCEPT' && $accepted_rank !== null) {
        $opt_sql = "SELECT route_string FROM dbo.rad_tos_options WHERE tos_id = ? AND rank = ?";
        $opt_stmt = sqlsrv_query($this->conn_tmi, $opt_sql, [$tos_id, $accepted_rank]);
        if ($opt_stmt) {
            $opt_row = sqlsrv_fetch_array($opt_stmt, SQLSRV_FETCH_ASSOC);
            $new_route = $opt_row['route_string'] ?? null;
            sqlsrv_free_stmt($opt_stmt);
        }
    } elseif ($action === 'COUNTER' && $counter_route) {
        $new_route = $counter_route;
    }

    // Create new DRAFT amendment linked to resolved one
    $new_amendment_id = null;
    if ($new_route) {
        $result = $this->createAmendment($amendment['gufi'], $new_route, [
            'created_by' => $tmu_cid,
            'tmi_reroute_id' => $amendment['tmi_reroute_id'],
            'tmi_id_label' => $amendment['tmi_id_label'],
            'delivery_channels' => $amendment['delivery_channels'],
            'notes' => "TOS resolution from amendment #$amendment_id",
        ]);
        if (isset($result['id'])) {
            $new_amendment_id = $result['id'];
            // Link parent
            $link_sql = "UPDATE dbo.rad_amendments SET parent_amendment_id = ? WHERE id = ?";
            $link_stmt = sqlsrv_query($this->conn_tmi, $link_sql, [$amendment_id, $new_amendment_id]);
            if ($link_stmt) sqlsrv_free_stmt($link_stmt);
        }
    }

    $this->broadcastWebSocket('tos:resolved', [
        'tos_id' => $tos_id, 'amendment_id' => $amendment_id,
        'action' => $action, 'new_amendment_id' => $new_amendment_id,
    ]);

    return [
        'success' => true, 'status' => 'TOS_RESOLVED',
        'new_amendment_id' => $new_amendment_id,
    ];
}
```

- [ ] **Step 4: Commit**

```bash
git add load/services/RADService.php
git commit -m "feat(rad): TOS lifecycle methods — submit, get, resolve (accept/counter/force)"
```

---

### Task 3: TOS API Endpoint

**Files:**
- Create: `api/rad/tos.php`

- [ ] **Step 1: Create tos.php**

```php
<?php
/** RAD API: TOS (Trajectory Option Set) — GET/POST /api/rad/tos.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

$cid = rad_require_auth();
$svc = rad_get_service();
$method = $_SERVER['REQUEST_METHOD'];
$body = rad_read_payload();
$action = $body['action'] ?? $_GET['action'] ?? null;

if ($method === 'GET') {
    $amendment_id = (int)($_GET['amendment_id'] ?? 0);
    if (!$amendment_id) rad_respond_json(400, ['status' => 'error', 'message' => 'amendment_id required']);

    $tos = $svc->getTOS($amendment_id);
    if (!$tos) rad_respond_json(404, ['status' => 'error', 'message' => 'No TOS found']);
    rad_respond_json(200, ['status' => 'ok', 'data' => $tos]);

} elseif ($method === 'POST') {

    if ($action === 'resolve') {
        // TMU resolves TOS
        rad_require_tmu($cid);
        $tos_id = (int)($body['tos_id'] ?? 0);
        if (!$tos_id) rad_respond_json(400, ['status' => 'error', 'message' => 'tos_id required']);

        $resolve_action = strtoupper($body['resolve_action'] ?? '');
        if (!in_array($resolve_action, ['ACCEPT', 'COUNTER', 'FORCE'])) {
            rad_respond_json(400, ['status' => 'error', 'message' => 'resolve_action must be ACCEPT, COUNTER, or FORCE']);
        }

        $result = $svc->resolveTOS(
            $tos_id,
            $resolve_action,
            isset($body['accepted_rank']) ? (int)$body['accepted_rank'] : null,
            $body['counter_route'] ?? null,
            (int)$cid
        );
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } else {
        // Submit TOS (ATC/Pilot/VA)
        require_once __DIR__ . '/../../load/services/VNASService.php';
        $role = rad_detect_role((int)$cid);
        if (!in_array($role, ['ATC', 'PILOT', 'VA', 'TMU'])) {
            rad_respond_json(403, ['status' => 'error', 'message' => 'Role not authorized for TOS submission']);
        }

        $amendment_id = (int)($body['amendment_id'] ?? 0);
        if (!$amendment_id) rad_respond_json(400, ['status' => 'error', 'message' => 'amendment_id required']);

        $options = $body['options'] ?? [];
        if (empty($options)) rad_respond_json(400, ['status' => 'error', 'message' => 'At least one option required']);

        $result = $svc->submitTOS($amendment_id, (int)$cid, $role, $options);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(201, ['status' => 'ok', 'data' => $result]);
    }

} else {
    rad_respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
```

- [ ] **Step 2: Commit**

```bash
git add api/rad/tos.php
git commit -m "feat(rad): TOS API endpoint — submit + resolve + GET"
```

---

### Task 4: TOS i18n Keys

**Files:**
- Modify: `assets/locales/en-US.json`

- [ ] **Step 1: Add TOS keys**

Add under `rad`:

```json
"tos": {
    "title": "Trajectory Option Set",
    "pilotPreferences": "Pilot Preferences (ranked)",
    "addOption": "+ Add Route Option",
    "submitTos": "Submit TOS",
    "rejectWithoutTos": "Reject Without TOS",
    "counterPropose": "Counter-Propose New Route",
    "forceOriginal": "Force Original Amendment",
    "asOriginallyFiled": "As Originally Filed",
    "asAmended": "As Amended",
    "pilotOption": "Pilot Option",
    "plotAll": "Plot All",
    "clear": "Clear",
    "baseline": "baseline",
    "resolve": "Resolve TOS",
    "mapLegend": "Map",
    "rank": "Rank",
    "route": "Route",
    "distance": "Distance",
    "time": "Time",
    "delta": "Delta",
    "accept": "Accept",
    "resolved": "TOS Resolved",
    "forced": "Amendment Forced",
    "submitted": "TOS submitted successfully",
    "noOptions": "No route options submitted"
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/locales/en-US.json
git commit -m "feat(rad): TOS i18n keys"
```

---

### Task 5: TOS Client Module

**Files:**
- Create: `assets/js/rad-tos.js`

- [ ] **Step 1: Create rad-tos.js with entry form + resolution panel**

```javascript
/**
 * RAD TOS Module — TOS entry form (ATC/Pilot/VA) + resolution panel (TMU).
 *
 * Public API:
 *   RADTOS.init(container)
 *   RADTOS.showEntryForm(amendment)
 *   RADTOS.showResolutionPanel(amendment)
 */
window.RADTOS = (function() {
    var TOS_COLORS = ['#32CD32','#FFD700','#4ECDC4','#FF6347','#9370DB','#00BFFF','#FFA500','#FF69B4'];

    function showEntryForm(amendment) {
        var filed = amendment.original_route || amendment.filed_route || '';
        var assigned = amendment.assigned_route || '';

        var html = '<div class="rad-tos-entry">';
        html += '<h6>' + PERTII18n.t('rad.tos.title') + '</h6>';

        // Auto options
        html += '<div class="rad-tos-option" data-type="as_filed">';
        html += '<span class="rad-tos-rank">#1</span>';
        html += '<span class="rad-tos-type">' + PERTII18n.t('rad.tos.asOriginallyFiled') + '</span>';
        html += '<div class="rad-tos-route text-monospace">' + filed + '</div>';
        html += '</div>';

        html += '<div class="rad-tos-option" data-type="as_amended">';
        html += '<span class="rad-tos-rank">#3</span>';
        html += '<span class="rad-tos-type">' + PERTII18n.t('rad.tos.asAmended') + '</span>';
        html += '<div class="rad-tos-route text-monospace">' + assigned + '</div>';
        html += '</div>';

        // Pilot options
        html += '<div id="tos_pilot_options"></div>';
        html += '<button class="btn btn-sm btn-outline-secondary mt-1 mb-2" id="tos_add_option">' + PERTII18n.t('rad.tos.addOption') + '</button>';

        // Actions
        html += '<div class="d-flex gap-2 mt-2">';
        html += '<button class="btn btn-sm btn-warning mr-1" id="tos_submit">' + PERTII18n.t('rad.tos.submitTos') + '</button>';
        html += '<button class="btn btn-sm btn-outline-danger" id="tos_reject_no_tos">' + PERTII18n.t('rad.tos.rejectWithoutTos') + '</button>';
        html += '</div>';
        html += '</div>';

        Swal.fire({
            title: PERTII18n.t('rad.tos.title'),
            html: html,
            width: '70%',
            showConfirmButton: false,
            showCloseButton: true,
            didOpen: function() {
                var optionCount = 0;

                $('#tos_add_option').on('click', function() {
                    optionCount++;
                    var rank = optionCount + 1; // rank 2, 4, 5, etc.
                    var optHtml = '<div class="rad-tos-option rad-tos-pilot-option" data-type="pilot_option">';
                    optHtml += '<span class="rad-tos-rank">#' + rank + '</span>';
                    optHtml += '<input type="text" class="form-control form-control-sm tos-route-input" placeholder="Enter route string...">';
                    optHtml += '<button class="btn btn-sm btn-outline-danger tos-remove-opt ml-1">&times;</button>';
                    optHtml += '</div>';
                    $('#tos_pilot_options').append(optHtml);
                });

                $(document).on('click', '.tos-remove-opt', function() {
                    $(this).closest('.rad-tos-pilot-option').remove();
                });

                $('#tos_submit').on('click', function() {
                    var options = [
                        { rank: 1, route_string: filed, option_type: 'as_filed' },
                        { rank: 3, route_string: assigned, option_type: 'as_amended' }
                    ];
                    var pilotRank = 2;
                    $('.rad-tos-pilot-option').each(function() {
                        var route = $(this).find('.tos-route-input').val().trim();
                        if (route) {
                            options.push({ rank: pilotRank++, route_string: route, option_type: 'pilot_option' });
                        }
                    });

                    // Re-rank sequentially
                    options.sort(function(a, b) { return a.rank - b.rank; });
                    options.forEach(function(o, i) { o.rank = i + 1; });

                    $.post('api/rad/tos.php', JSON.stringify({
                        amendment_id: amendment.id,
                        options: options
                    }))
                    .done(function(r) {
                        if (r.status === 'ok') {
                            PERTIDialog.success(PERTII18n.t('rad.tos.submitted'));
                            Swal.close();
                            RADEventBus.emit('tos:submitted', { tosId: r.data.tos_id, amendmentId: amendment.id });
                        } else {
                            PERTIDialog.warning(r.message);
                        }
                    })
                    .fail(function() { PERTIDialog.warning(PERTII18n.t('error.networkError')); });
                });

                $('#tos_reject_no_tos').on('click', function() {
                    $.post('api/rad/amendment.php', { id: amendment.id, action: 'reject' })
                        .done(function(r) {
                            if (r.status === 'ok') {
                                PERTIDialog.success(PERTII18n.t('rad.status.RJCT'));
                                Swal.close();
                            } else {
                                PERTIDialog.warning(r.message);
                            }
                        });
                });
            }
        });
    }

    function showResolutionPanel(amendment) {
        // Fetch TOS data + route metrics
        $.get('api/rad/tos.php', { amendment_id: amendment.id })
            .done(function(response) {
                if (response.status !== 'ok' || !response.data) {
                    PERTIDialog.warning(PERTII18n.t('rad.tos.noOptions'));
                    return;
                }
                var tos = response.data;
                var routes = tos.options.map(function(o) { return o.route_string; });

                // Fetch metrics for all routes
                $.ajax({
                    url: 'api/rad/route-metrics.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ routes: routes })
                }).done(function(metricsResp) {
                    var metricsData = (metricsResp.data && metricsResp.data.metrics) || [];
                    renderResolutionDialog(amendment, tos, metricsData);
                }).fail(function() {
                    renderResolutionDialog(amendment, tos, []);
                });
            })
            .fail(function() {
                PERTIDialog.warning(PERTII18n.t('error.networkError'));
            });
    }

    function renderResolutionDialog(amendment, tos, metrics) {
        var baselineDistance = metrics.length > 0 && metrics[0].distance_nm ? metrics[0].distance_nm : null;
        var baselineTime = metrics.length > 0 && metrics[0].time_minutes ? metrics[0].time_minutes : null;

        var html = '<div style="background:#0d1117; padding:16px; font-size:0.82rem;">';

        // Header
        html += '<div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">';
        html += '<span style="color:#00BFFF; font-weight:700; font-family:monospace; font-size:1rem;">' + (amendment.callsign || '') + '</span>';
        html += '<span style="color:#89a;">' + (amendment.origin || '') + ' &rarr; ' + (amendment.dest || amendment.destination || '') + '</span>';
        html += '</div>';

        // Options
        html += '<div style="margin-bottom:8px;"><span style="color:#89a; font-size:0.72rem; text-transform:uppercase; font-weight:600;">' + PERTII18n.t('rad.tos.pilotPreferences') + '</span></div>';

        tos.options.forEach(function(opt, idx) {
            var color = TOS_COLORS[idx % TOS_COLORS.length];
            var m = metrics[idx] || {};
            var dist = m.distance_nm ? Math.round(m.distance_nm) + ' nm' : '--';
            var time = m.time_minutes ? Math.floor(m.time_minutes / 60) + 'h ' + (m.time_minutes % 60) + 'm' : '--';

            var delta = '';
            if (baselineDistance && m.distance_nm && idx > 0) {
                var dNm = m.distance_nm - baselineDistance;
                var dMin = m.time_minutes && baselineTime ? m.time_minutes - baselineTime : null;
                var sign = dNm >= 0 ? '+' : '';
                delta = '<span style="color:#fd7e14;">' + sign + Math.round(dNm) + ' nm';
                if (dMin !== null) delta += ' / ' + sign + Math.round(dMin) + ' min';
                delta += '</span>';
            } else if (idx === 0) {
                delta = '<span style="color:#28a745;">' + PERTII18n.t('rad.tos.baseline') + '</span>';
            }

            var typeLabel = opt.option_type === 'as_filed' ? PERTII18n.t('rad.tos.asOriginallyFiled')
                : opt.option_type === 'as_amended' ? PERTII18n.t('rad.tos.asAmended')
                : PERTII18n.t('rad.tos.pilotOption');

            html += '<div style="background:#111; border:1px solid #334; border-radius:4px; padding:10px 12px; margin-bottom:4px;">';
            html += '<div style="display:flex; align-items:center; gap:8px;">';
            html += '<span style="background:#352; color:#fd8; font-weight:700; width:24px; height:24px; display:flex; align-items:center; justify-content:center; border-radius:3px; font-size:0.82rem;">#' + opt.rank + '</span>';
            html += '<div style="width:14px; height:14px; border-radius:2px; background:' + color + '; cursor:pointer; border:2px solid transparent; flex-shrink:0;" class="tos-color-swatch" data-idx="' + idx + '" data-route="' + (opt.route_string || '').replace(/"/g, '&quot;') + '" data-color="' + color + '"></div>';
            html += '<div style="flex:1;">';
            html += '<div style="color:#6f8; font-family:monospace; font-size:0.8rem; margin-bottom:2px;">' + (opt.route_string || '') + '</div>';
            html += '<div style="display:flex; gap:16px; font-size:0.75rem;">';
            html += '<span style="color:#89a;">' + typeLabel + '</span>';
            html += '<span style="color:#89a;">' + dist + '</span>';
            html += '<span style="color:#89a;">' + time + '</span>';
            html += delta;
            html += '</div></div>';
            html += '<div style="background:#253; border:1px solid #5a5; color:#8c8; padding:3px 12px; border-radius:3px; font-size:0.78rem; cursor:pointer; white-space:nowrap;" class="tos-accept-btn" data-tos-id="' + tos.id + '" data-rank="' + opt.rank + '">' + PERTII18n.t('rad.tos.accept') + '</div>';
            html += '</div></div>';
        });

        // Map legend
        html += '<div style="background:#16213e; border:1px solid #334; border-radius:4px; padding:8px 12px; margin:12px 0; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">';
        html += '<span style="color:#89a; font-size:0.75rem; text-transform:uppercase; font-weight:600;">' + PERTII18n.t('rad.tos.mapLegend') + '</span>';
        tos.options.forEach(function(opt, idx) {
            var c = TOS_COLORS[idx % TOS_COLORS.length];
            html += '<div style="display:flex; gap:4px; align-items:center;"><div style="width:10px; height:10px; border-radius:2px; background:' + c + ';"></div><span style="color:#ccc; font-size:0.75rem;">#' + opt.rank + '</span></div>';
        });
        html += '<span class="tos-plot-all" style="color:#456; margin-left:auto; font-size:0.75rem; cursor:pointer; border:1px solid #445; padding:2px 8px; border-radius:3px;">' + PERTII18n.t('rad.tos.plotAll') + '</span>';
        html += '<span class="tos-clear-plots" style="color:#456; font-size:0.75rem; cursor:pointer; border:1px solid #445; padding:2px 8px; border-radius:3px;">' + PERTII18n.t('rad.tos.clear') + '</span>';
        html += '</div>';

        // Actions
        html += '<div style="border-top:1px solid #334; padding-top:12px; display:flex; gap:8px;">';
        html += '<div class="tos-counter-btn" style="background:#234; border:1px solid #456; color:#89a; padding:5px 14px; border-radius:4px; font-size:0.82rem; cursor:pointer;">' + PERTII18n.t('rad.tos.counterPropose') + '</div>';
        html += '<div class="tos-force-btn" style="background:#523; border:1px solid #856; color:#f88; padding:5px 14px; border-radius:4px; font-size:0.82rem; cursor:pointer;" data-tos-id="' + tos.id + '">' + PERTII18n.t('rad.tos.forceOriginal') + '</div>';
        html += '</div>';

        html += '</div>';

        Swal.fire({
            title: PERTII18n.t('rad.tos.resolve'),
            html: html,
            width: '80%',
            showConfirmButton: false,
            showCloseButton: true,
            didOpen: function() {
                // Accept button
                $(document).off('click.tosAccept').on('click.tosAccept', '.tos-accept-btn', function() {
                    var tosId = $(this).data('tos-id');
                    var rank = $(this).data('rank');
                    $.ajax({
                        url: 'api/rad/tos.php',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ action: 'resolve', tos_id: tosId, resolve_action: 'ACCEPT', accepted_rank: rank })
                    }).done(function(r) {
                        if (r.status === 'ok') { PERTIDialog.success(PERTII18n.t('rad.tos.resolved')); Swal.close(); RADEventBus.emit('tos:resolved', r.data); }
                        else PERTIDialog.warning(r.message);
                    });
                });

                // Force button
                $(document).off('click.tosForce').on('click.tosForce', '.tos-force-btn', function() {
                    var tosId = $(this).data('tos-id');
                    PERTIDialog.confirm(PERTII18n.t('rad.tos.forceOriginal') + '?').then(function(res) {
                        if (res.isConfirmed) {
                            $.ajax({
                                url: 'api/rad/tos.php',
                                method: 'POST',
                                contentType: 'application/json',
                                data: JSON.stringify({ action: 'resolve', tos_id: tosId, resolve_action: 'FORCE' })
                            }).done(function(r) {
                                if (r.status === 'ok') { PERTIDialog.success(PERTII18n.t('rad.tos.forced')); Swal.close(); RADEventBus.emit('tos:resolved', r.data); }
                                else PERTIDialog.warning(r.message);
                            });
                        }
                    });
                });

                // Color swatch: plot single route
                $(document).off('click.tosSwatch').on('click.tosSwatch', '.tos-color-swatch', function() {
                    var route = $(this).data('route');
                    var color = $(this).data('color');
                    if (route && window.MapLibreRoute) {
                        var $ta = $('#routeSearch');
                        $ta.val($ta.val().trim() + '\n' + route + ';' + color);
                        MapLibreRoute.processRoutes();
                    }
                });

                // Plot all
                $(document).off('click.tosPlotAll').on('click.tosPlotAll', '.tos-plot-all', function() {
                    var lines = [];
                    tos.options.forEach(function(opt, idx) {
                        if (opt.route_string) lines.push(opt.route_string + ';' + TOS_COLORS[idx % TOS_COLORS.length]);
                    });
                    $('#routeSearch').val(lines.join('\n'));
                    if (window.MapLibreRoute) MapLibreRoute.processRoutes();
                });

                // Clear
                $(document).off('click.tosClear').on('click.tosClear', '.tos-clear-plots', function() {
                    $('#routeSearch').val('');
                    if (window.MapLibreRoute) MapLibreRoute.processRoutes();
                });

                // Counter-propose: switch to Edit tab
                $(document).off('click.tosCounter').on('click.tosCounter', '.tos-counter-btn', function() {
                    // Resolve as COUNTER first, then user creates new amendment
                    Swal.close();
                    $('#tab-edit').tab('show');
                    PERTIDialog.info('Create the counter-proposed route in the Edit tab, then send it.');
                });
            }
        });
    }

    return {
        showEntryForm: showEntryForm,
        showResolutionPanel: showResolutionPanel
    };
})();
```

- [ ] **Step 2: Add script tag to rad.php**

After `rad-clearance-builder.js` and before `rad-monitoring.js`:

```html
<script src="assets/js/rad-tos.js<?= _v('assets/js/rad-tos.js') ?>"></script>
```

- [ ] **Step 3: Commit**

```bash
git add assets/js/rad-tos.js rad.php
git commit -m "feat(rad): TOS client module — entry form + resolution panel with map plotting"
```

---

### Task 6: Wire TOS into Monitoring Tab

**Files:**
- Modify: `assets/js/rad-monitoring.js`

- [ ] **Step 1: Add TOS action buttons**

In `getActionButtons()`, add for ISSUED status (ATC/Pilot/VA can reject with TOS):

```javascript
if (a.status === 'ISSUED' && window.RADRole && RADRole.can('can_submit_tos')) {
    html += '<button class="btn btn-sm btn-outline-warning rad-btn-enter-tos mr-1" data-id="' + a.id + '">TOS</button>';
}

if (a.status === 'TOS_PENDING' && window.RADRole && RADRole.can('can_resolve_tos')) {
    html += '<button class="btn btn-sm btn-outline-info rad-btn-resolve-tos mr-1" data-id="' + a.id + '">' + PERTII18n.t('rad.tos.resolve') + '</button>';
}
```

- [ ] **Step 2: Bind TOS button handlers**

In `bindEvents()`:

```javascript
$(document).on('click', '.rad-btn-enter-tos', function() {
    var id = $(this).data('id');
    var amendment = amendments.find(function(a) { return a.id === id; });
    if (amendment && window.RADTOS) {
        RADTOS.showEntryForm(amendment);
    }
});

$(document).on('click', '.rad-btn-resolve-tos', function() {
    var id = $(this).data('id');
    var amendment = amendments.find(function(a) { return a.id === id; });
    if (amendment && window.RADTOS) {
        RADTOS.showResolutionPanel(amendment);
    }
});
```

- [ ] **Step 3: Refresh on TOS events**

In `init()`:

```javascript
RADEventBus.on('tos:submitted', function() { refresh(); });
RADEventBus.on('tos:resolved', function() { refresh(); });
```

- [ ] **Step 4: Add TOS badge to TOS_PENDING rows**

In `renderRow()`, after the status badge, add:

```javascript
if (a.status === 'TOS_PENDING') {
    // Add TOS indicator
    var statusCell = row.find('td').eq(2);
    statusCell.append(' <span class="rad-badge rad-badge-warning" style="font-size:0.65rem;">TOS</span>');
}
```

- [ ] **Step 5: Commit**

```bash
git add assets/js/rad-monitoring.js
git commit -m "feat(rad): wire TOS entry/resolution into monitoring tab"
```

---

### Task 7: TOS CSS Styles

**Files:**
- Modify: `assets/css/rad.css`

- [ ] **Step 1: Add TOS styles**

```css
/* =========================================================================
   TOS (Trajectory Option Set)
   ========================================================================= */
.rad-tos-entry {
    text-align: left;
}
.rad-tos-option {
    background: #111;
    border: 1px solid #334;
    border-radius: 4px;
    padding: 8px 12px;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.rad-tos-rank {
    background: #352;
    color: #fd8;
    font-weight: 700;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 3px;
    font-size: 0.82rem;
    flex-shrink: 0;
}
.rad-tos-type {
    color: #89a;
    font-size: 0.78rem;
    min-width: 120px;
}
.rad-tos-route {
    color: #6f8;
    font-size: 0.8rem;
    flex: 1;
    word-break: break-word;
}
.tos-route-input {
    background: #0d1117 !important;
    color: #6f8 !important;
    border-color: #445 !important;
    font-family: Inconsolata, monospace;
    flex: 1;
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/rad.css
git commit -m "feat(rad): TOS panel CSS styles"
```

---

### Task 8: End-to-End Phase 3 Verification

- [ ] **Step 1: Full TOS workflow test**

1. Create and send an amendment (TMU)
2. Mark as Issued
3. From monitoring tab, click TOS button on ISSUED row
4. TOS entry form opens with "As Originally Filed" and "As Amended" auto-options
5. Add a pilot option, submit TOS
6. Amendment transitions to TOS_PENDING
7. As TMU, click "Resolve TOS" on TOS_PENDING row
8. Resolution panel shows ranked options with distances, times, deltas
9. Click "Plot All" → all routes appear on map with different colors
10. Accept option #1 → amendment resolves, new DRAFT created
11. Verify "Force" flow: force → amendment goes to FORCED state

- [ ] **Step 2: Verify route metrics**

1. Submit routes to `api/rad/route-metrics.php`
2. Confirm distance_nm values are reasonable (e.g., KATL-KJFK ≈ 700-800nm)
3. Confirm GeoJSON is returned for map plotting

- [ ] **Step 3: Final commit**

```bash
git add -A
git commit -m "feat(rad): Phase 3 complete — TOS entry, resolution, route metrics, map comparison"
```
