# VATCAN Interoperability Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add multi-organization support to PERTI so VATCAN (CANTMU/NOC) can operate alongside vATCSCC (DCC) with isolated plans/TMIs and cross-border coordination.

**Architecture:** Org-column extension — add `org_code` to key tables, filter queries by session org context. Binary privileged/unprivileged access per org. Three locales: `en-US`, `en-CA`, `fr-CA`.

**Tech Stack:** PHP 8.2 (vanilla), MySQL 8, Azure SQL (sqlsrv), JavaScript (vanilla + jQuery), PERTII18n module.

**Design doc:** `docs/plans/2026-02-15-vatcan-interop-design.md`

**Worktree:** `C:/Temp/perti-worktrees/perti-vatcan` on branch `feature/perti-vatcan`

**No automated test suite** — verification is manual via the local dev server (`php -S localhost:8000`) and API endpoint testing.

---

## Task 1: Create Organization Schema (MySQL)

**Files:**
- Create: `database/migrations/org/001_create_organizations.sql`

**Step 1: Write migration SQL**

```sql
-- Organization tables for multi-org support
-- Target: perti_site MySQL database

CREATE TABLE IF NOT EXISTS organizations (
    org_code VARCHAR(16) PRIMARY KEY,
    org_name VARCHAR(64) NOT NULL,
    display_name VARCHAR(64) NOT NULL,
    region VARCHAR(8) NOT NULL,
    vatsim_division VARCHAR(8) NOT NULL,
    default_locale VARCHAR(8) NOT NULL DEFAULT 'en-US',
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_orgs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cid INT NOT NULL,
    org_code VARCHAR(16) NOT NULL,
    is_privileged TINYINT NOT NULL DEFAULT 0,
    is_primary TINYINT NOT NULL DEFAULT 0,
    assigned_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cid_org (cid, org_code),
    FOREIGN KEY (org_code) REFERENCES organizations(org_code)
);

CREATE TABLE IF NOT EXISTS org_facilities (
    org_code VARCHAR(16) NOT NULL,
    facility_code VARCHAR(8) NOT NULL,
    facility_type VARCHAR(16) NOT NULL,
    PRIMARY KEY (org_code, facility_code),
    FOREIGN KEY (org_code) REFERENCES organizations(org_code)
);

-- Seed organizations
INSERT INTO organizations (org_code, org_name, display_name, region, vatsim_division, default_locale) VALUES
    ('vatcscc', 'vATCSCC', 'DCC', 'US', 'VATUSA', 'en-US'),
    ('vatcan', 'VATCAN', 'NOC', 'CA', 'VATCAN', 'en-CA');

-- Seed super user (CID 1234727 = Jeremy Peterson / HP)
INSERT INTO user_orgs (cid, org_code, is_privileged, is_primary) VALUES
    (1234727, 'vatcscc', 1, 1),
    (1234727, 'vatcan', 1, 0);

-- Backfill existing users to vatcscc
INSERT IGNORE INTO user_orgs (cid, org_code, is_privileged, is_primary, created_at)
SELECT cid, 'vatcscc', 1, 1, NOW() FROM users;

INSERT IGNORE INTO user_orgs (cid, org_code, is_privileged, is_primary, created_at)
SELECT cid, 'vatcscc', 1, 1, NOW() FROM admin_users
WHERE cid NOT IN (SELECT cid FROM user_orgs WHERE org_code = 'vatcscc');

-- Seed US ARTCC facilities -> vatcscc
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('vatcscc', 'ZAB', 'artcc'), ('vatcscc', 'ZAU', 'artcc'), ('vatcscc', 'ZBW', 'artcc'),
    ('vatcscc', 'ZDC', 'artcc'), ('vatcscc', 'ZDV', 'artcc'), ('vatcscc', 'ZFW', 'artcc'),
    ('vatcscc', 'ZHU', 'artcc'), ('vatcscc', 'ZID', 'artcc'), ('vatcscc', 'ZJX', 'artcc'),
    ('vatcscc', 'ZKC', 'artcc'), ('vatcscc', 'ZLA', 'artcc'), ('vatcscc', 'ZLC', 'artcc'),
    ('vatcscc', 'ZMA', 'artcc'), ('vatcscc', 'ZME', 'artcc'), ('vatcscc', 'ZMP', 'artcc'),
    ('vatcscc', 'ZNY', 'artcc'), ('vatcscc', 'ZOA', 'artcc'), ('vatcscc', 'ZOB', 'artcc'),
    ('vatcscc', 'ZSE', 'artcc'), ('vatcscc', 'ZTL', 'artcc'),
    -- Oceanic/Pacific/Alaska
    ('vatcscc', 'ZAK', 'artcc'), ('vatcscc', 'ZAN', 'artcc'), ('vatcscc', 'ZHN', 'artcc'),
    ('vatcscc', 'ZAP', 'artcc'), ('vatcscc', 'ZWY', 'artcc'), ('vatcscc', 'ZHO', 'artcc'),
    ('vatcscc', 'ZMO', 'artcc'), ('vatcscc', 'ZUA', 'artcc'),
    -- Caribbean
    ('vatcscc', 'ZSU', 'artcc');

-- Seed Canadian FIR facilities -> vatcan
INSERT INTO org_facilities (org_code, facility_code, facility_type) VALUES
    ('vatcan', 'CZYZ', 'fir'), ('vatcan', 'CZUL', 'fir'), ('vatcan', 'CZEG', 'fir'),
    ('vatcan', 'CZVR', 'fir'), ('vatcan', 'CZWG', 'fir'), ('vatcan', 'CZQM', 'fir'),
    ('vatcan', 'CZQX', 'fir'), ('vatcan', 'CZQO', 'fir');
```

**Step 2: Run migration on perti_site database**

Use Kudu VFS API to upload and execute, or run via local MySQL client if available.

**Step 3: Verify**

Query: `SELECT * FROM organizations;` — expect 2 rows (vatcscc, vatcan).
Query: `SELECT * FROM user_orgs WHERE cid = 1234727;` — expect 2 rows (both orgs, privileged).
Query: `SELECT COUNT(*) FROM org_facilities;` — expect ~37 rows.

**Step 4: Commit**

```bash
git add database/migrations/org/001_create_organizations.sql
git commit -m "feat(org): add organizations, user_orgs, org_facilities schema"
```

---

## Task 2: Add org_code Column to MySQL Plan Tables

**Files:**
- Create: `database/migrations/org/002_add_org_code_to_plans.sql`

**Step 1: Write migration SQL**

```sql
-- Add org_code to all plan-related tables in perti_site
-- DEFAULT 'vatcscc' backfills existing rows automatically

ALTER TABLE p_plans ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_configs ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_terminal_staffing ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_enroute_staffing ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_dcc_staffing ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_terminal_constraints ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_enroute_constraints ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_terminal_init ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_enroute_init ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_terminal_init_timeline ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_enroute_init_timeline ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_terminal_init_times ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_enroute_init_times ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_terminal_planning ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_enroute_planning ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_op_goals ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_forecast ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_historical ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE p_group_flights ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE r_scores ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE r_comments ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE r_data ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE r_ops_data ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE assigned ADD COLUMN org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- Index on p_plans.org_code (primary query target)
ALTER TABLE p_plans ADD INDEX idx_org_code (org_code);
```

**Step 2: Run migration**

**Step 3: Verify**

Query: `SELECT org_code, COUNT(*) FROM p_plans GROUP BY org_code;` — all rows should be `vatcscc`.

**Step 4: Commit**

```bash
git add database/migrations/org/002_add_org_code_to_plans.sql
git commit -m "feat(org): add org_code column to plan tables"
```

---

## Task 3: Add org_code Column to Azure SQL TMI Tables

**Files:**
- Create: `database/migrations/org/003_add_org_code_to_tmi.sql`

**Step 1: Write migration SQL**

```sql
USE VATSIM_TMI;

-- Add org_code to TMI tables
-- DEFAULT 'vatcscc' backfills existing rows

ALTER TABLE tmi_programs ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE tmi_advisories ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE tmi_entries ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE tmi_reroutes ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE tmi_public_routes ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE tmi_airport_configs ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE tmi_delay_entries ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';
ALTER TABLE tmi_proposals ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- Indexes
CREATE NONCLUSTERED INDEX IX_tmi_programs_org ON tmi_programs(org_code);
CREATE NONCLUSTERED INDEX IX_tmi_advisories_org ON tmi_advisories(org_code);
CREATE NONCLUSTERED INDEX IX_tmi_entries_org ON tmi_entries(org_code);

PRINT 'org_code columns added to TMI tables';
```

**Step 2: Run via Azure SQL admin connection (jpeterson credentials)**

**Step 3: Verify**

Query: `SELECT org_code, COUNT(*) FROM tmi_programs GROUP BY org_code;`

**Step 4: Commit**

```bash
git add database/migrations/org/003_add_org_code_to_tmi.sql
git commit -m "feat(org): add org_code column to Azure SQL TMI tables"
```

---

## Task 4: Create Org Context PHP Helper

**Files:**
- Create: `load/org_context.php`

**Step 1: Write the org context module**

This file provides `get_org_code()`, `is_org_privileged()`, `require_org_privileged()`, and `load_org_context()` for use by all endpoints.

```php
<?php
/**
 * Organization Context
 *
 * Provides org-scoped session helpers. Include after connect.php.
 * Reads org from session, falls back to 'vatcscc'.
 */

if (defined('ORG_CONTEXT_LOADED')) {
    return;
}
define('ORG_CONTEXT_LOADED', true);

/**
 * Get active org code from session
 * @return string 'vatcscc' or 'vatcan'
 */
function get_org_code(): string {
    return $_SESSION['ORG_CODE'] ?? 'vatcscc';
}

/**
 * Check if current user is privileged in active org
 * @return bool
 */
function is_org_privileged(): bool {
    return !empty($_SESSION['ORG_PRIVILEGED']);
}

/**
 * Require org privilege or exit with 403
 */
function require_org_privileged(): void {
    if (!is_org_privileged()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Insufficient privileges for this organization']);
        exit;
    }
}

/**
 * Get all orgs for current user
 * @return array e.g. ['vatcscc', 'vatcan']
 */
function get_user_orgs(): array {
    return $_SESSION['ORG_ALL'] ?? ['vatcscc'];
}

/**
 * Get org display info from organizations table
 * Cached in session to avoid repeated DB queries.
 * @param mysqli $conn MySQLi connection
 * @return array ['org_name', 'display_name', 'region', 'default_locale']
 */
function get_org_info($conn): array {
    $org_code = get_org_code();
    $cache_key = 'ORG_INFO_' . $org_code;

    if (!empty($_SESSION[$cache_key])) {
        return $_SESSION[$cache_key];
    }

    $stmt = mysqli_prepare($conn, "SELECT org_name, display_name, region, default_locale FROM organizations WHERE org_code = ?");
    mysqli_stmt_bind_param($stmt, "s", $org_code);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    if ($row) {
        $_SESSION[$cache_key] = $row;
        return $row;
    }

    return ['org_name' => 'vATCSCC', 'display_name' => 'DCC', 'region' => 'US', 'default_locale' => 'en-US'];
}

/**
 * Load org context into session from user_orgs table.
 * Called after login and on org switch.
 * @param int $cid VATSIM CID
 * @param mysqli $conn MySQLi connection
 * @param string|null $target_org Force a specific org (for switching)
 */
function load_org_context(int $cid, $conn, ?string $target_org = null): void {
    // Get all orgs for this user
    $stmt = mysqli_prepare($conn, "SELECT org_code, is_privileged, is_primary FROM user_orgs WHERE cid = ?");
    mysqli_stmt_bind_param($stmt, "i", $cid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $all_orgs = [];
    $primary_org = null;
    $org_privileges = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $all_orgs[] = $row['org_code'];
        $org_privileges[$row['org_code']] = (bool)$row['is_privileged'];
        if ($row['is_primary']) {
            $primary_org = $row['org_code'];
        }
    }

    // If user has no org rows yet, default to vatcscc
    if (empty($all_orgs)) {
        $all_orgs = ['vatcscc'];
        $primary_org = 'vatcscc';
        $org_privileges['vatcscc'] = false;
    }

    // Determine active org
    if ($target_org && in_array($target_org, $all_orgs)) {
        $active_org = $target_org;
    } else {
        $active_org = $primary_org ?? $all_orgs[0];
    }

    $_SESSION['ORG_CODE'] = $active_org;
    $_SESSION['ORG_PRIVILEGED'] = $org_privileges[$active_org] ?? false;
    $_SESSION['ORG_ALL'] = $all_orgs;

    // Clear cached org info when switching
    foreach ($all_orgs as $org) {
        unset($_SESSION['ORG_INFO_' . $org]);
    }
}
```

**Step 2: Verify syntax**

Run: `php -l load/org_context.php` — expect "No syntax errors detected".

**Step 3: Commit**

```bash
git add load/org_context.php
git commit -m "feat(org): add org_context.php session helper"
```

---

## Task 5: Integrate Org Context into Login Flow

**Files:**
- Modify: `login/callback.php` (lines 100-123)
- Modify: `sessions/handler.php` (lines 36-40 for DEV mode)

**Step 1: Update callback.php**

After the existing `sessionstart()` call at line 110, add org context loading. Also add auto-detection of VATSIM division for new users.

In `login/callback.php`, after line 110 (`sessionstart($cid, $first_name, $last_name);`), add:

```php
// Load org context
require_once dirname(__DIR__) . '/load/org_context.php';
load_org_context((int)$cid, $conn_sqli);
```

**Step 2: Update handler.php DEV mode**

In `sessions/handler.php`, after line 39 (`$_SESSION['VATSIM_LAST_NAME'] = 'User';`), add:

```php
$_SESSION['ORG_CODE'] = 'vatcscc';
$_SESSION['ORG_PRIVILEGED'] = true;
$_SESSION['ORG_ALL'] = ['vatcscc', 'vatcan'];
```

**Step 3: Verify**

Login via VATSIM OAuth → session should now contain `ORG_CODE`, `ORG_PRIVILEGED`, `ORG_ALL`.

**Step 4: Commit**

```bash
git add login/callback.php sessions/handler.php
git commit -m "feat(org): integrate org context into login flow"
```

---

## Task 6: Create Org Switch API Endpoint

**Files:**
- Create: `api/session/switch_org.php`

**Step 1: Write endpoint**

```php
<?php
/**
 * Switch active organization context
 * POST { "org_code": "vatcan" }
 */
include_once(dirname(__DIR__, 2) . '/sessions/handler.php');
include_once(dirname(__DIR__, 2) . '/load/config.php');
define('PERTI_MYSQL_ONLY', true);
include_once(dirname(__DIR__, 2) . '/load/connect.php');
require_once(dirname(__DIR__, 2) . '/load/org_context.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$cid = $_SESSION['VATSIM_CID'] ?? null;
if (!$cid) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$target_org = $input['org_code'] ?? null;

if (!$target_org || !in_array($target_org, ['vatcscc', 'vatcan'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid org_code']);
    exit;
}

// Reload org context with target org
load_org_context((int)$cid, $conn_sqli, $target_org);

$org_info = get_org_info($conn_sqli);

echo json_encode([
    'success' => true,
    'org_code' => get_org_code(),
    'privileged' => is_org_privileged(),
    'display_name' => $org_info['display_name'],
    'default_locale' => $org_info['default_locale']
]);
```

**Step 2: Verify syntax**

Run: `php -l api/session/switch_org.php`

**Step 3: Test manually**

```bash
curl -X POST http://localhost:8000/api/session/switch_org.php \
  -H "Content-Type: application/json" \
  -d '{"org_code":"vatcan"}' \
  --cookie "PHPSESSID=<session_id>"
```

Expected: `{"success":true,"org_code":"vatcan","privileged":true,...}`

**Step 4: Commit**

```bash
git add api/session/switch_org.php
git commit -m "feat(org): add org switch API endpoint"
```

---

## Task 7: Inject Org Context into Frontend

**Files:**
- Modify: `load/header.php` (after line 89)
- Modify: `load/nav.php` (lines 188-201)

**Step 1: Add window.PERTI_ORG to header.php**

After line 89 (`<script src="<?= $filepath; ?>assets/locales/index.js"></script>`), add:

```php
<!-- Organization Context -->
<script>
window.PERTI_ORG = <?= json_encode([
    'code' => $_SESSION['ORG_CODE'] ?? 'vatcscc',
    'privileged' => !empty($_SESSION['ORG_PRIVILEGED']),
    'allOrgs' => $_SESSION['ORG_ALL'] ?? ['vatcscc'],
    'defaultLocale' => (function() {
        // Look up org default locale if org_context is loaded
        if (function_exists('get_org_info') && isset($GLOBALS['conn_sqli'])) {
            $info = get_org_info($GLOBALS['conn_sqli']);
            return $info['default_locale'];
        }
        return 'en-US';
    })()
]) ?>;
</script>
```

**Step 2: Add org badge and switcher to nav.php**

In `load/nav.php`, replace lines 188-201 (the user menu div) with a version that includes the org badge before the user name. The org badge shows the `display_name` (DCC or NOC) and, for multi-org users, a dropdown to switch.

Insert before the existing user profile `<ul>` (between lines 189 and 190):

```php
<!-- Org Badge -->
<?php
    $org_code = $_SESSION['ORG_CODE'] ?? 'vatcscc';
    $org_all = $_SESSION['ORG_ALL'] ?? ['vatcscc'];
    $org_display = 'DCC'; // default
    if (function_exists('get_org_info') && isset($conn_sqli)) {
        $oi = get_org_info($conn_sqli);
        $org_display = $oi['display_name'] ?? 'DCC';
    }
    $org_color = ($org_code === 'vatcan') ? '#d32f2f' : '#1a73e8';
    $multi_org = count($org_all) > 1;
?>
<?php if ($multi_org): ?>
    <div class="dropdown mr-2">
        <button class="btn btn-sm dropdown-toggle" style="background:<?= $org_color ?>;color:#fff;font-weight:600;" data-toggle="dropdown">
            <?= htmlspecialchars($org_display) ?>
        </button>
        <div class="dropdown-menu dropdown-menu-right">
            <?php foreach ($org_all as $oc): ?>
                <a class="dropdown-item <?= $oc === $org_code ? 'active' : '' ?>" href="#" onclick="switchOrg('<?= $oc ?>');return false;">
                    <?= $oc === 'vatcscc' ? 'DCC' : 'NOC' ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <span class="badge mr-2" style="background:<?= $org_color ?>;color:#fff;font-size:0.75rem;padding:4px 8px;">
        <?= htmlspecialchars($org_display) ?>
    </span>
<?php endif; ?>
```

Add the `switchOrg` JS function at the bottom of nav.php (before `</div>` closing offcanvas-backdrop):

```html
<script>
function switchOrg(orgCode) {
    fetch('/api/session/switch_org.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({org_code: orgCode})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        }
    });
}
</script>
```

**Step 3: Verify**

Load any page → org badge (DCC) should appear in nav bar. For CID 1234727, dropdown should show DCC/NOC options.

**Step 4: Commit**

```bash
git add load/header.php load/nav.php
git commit -m "feat(org): add org badge and switcher to nav bar"
```

---

## Task 8: Add org_code Filtering to Plan API Endpoints

**Files:**
- Modify: `api/data/plans.l.php` (line 33 — main query)
- Modify: `api/mgt/perti/post.php` (lines 57-58 — INSERT)
- Modify: `api/mgt/perti/update.php` (line 53 — UPDATE)
- Modify: All other `api/mgt/perti/*.php` and `api/data/plans/*.php` endpoints

**Step 1: Update plans.l.php (plan listing)**

At the top of the file (after includes), add:
```php
require_once(dirname(__DIR__, 2) . '/load/org_context.php');
```

Replace line 33:
```php
// Before:
$query = mysqli_query($conn_sqli, ("SELECT * FROM p_plans ORDER BY event_date DESC"));

// After:
$org = get_org_code();
$stmt = mysqli_prepare($conn_sqli, "SELECT * FROM p_plans WHERE org_code = ? ORDER BY event_date DESC");
mysqli_stmt_bind_param($stmt, "s", $org);
mysqli_stmt_execute($stmt);
$query = mysqli_stmt_get_result($stmt);
```

**Step 2: Update post.php (plan creation)**

Add `org_context.php` require. Update INSERT to include `org_code`:

```php
$org = get_org_code();
$sql = "INSERT INTO p_plans (event_name, event_date, event_start, event_end_date, event_end_time, event_banner, oplevel, hotline, org_code)
VALUES ('$event_name', '$event_date', '$event_start', '$event_end_date', '$event_end_time', '$event_banner', '$oplevel', '$hotline', '$org')";
```

**Step 3: Update update.php (plan update)**

Add org_code validation to UPDATE WHERE clause:

```php
$org = get_org_code();
$query = $conn_sqli->query("UPDATE p_plans SET ... WHERE id=$id AND org_code='$org'");
```

**Step 4: Update remaining plan API endpoints**

Apply the same pattern to all files in `api/data/plans/` and `api/mgt/perti/`:
- Add `require_once` for `org_context.php`
- Add `WHERE org_code = ?` (or `AND org_code = ?`) to SELECT queries
- Add `org_code` to INSERT statements
- Add `AND org_code = ?` to UPDATE/DELETE statements
- Child table queries that JOIN through `p_plans.id` get the filter via the parent join

The full list of files to update in `api/data/plans/`: `configs.php`, `forecast.php`, `goals.php`, `historical.php`, `outlook.php`, `term_inits.php`, `enroute_inits.php`, `term_inits_timeline.php`, `enroute_inits_timeline.php`, `term_planning.php`, `enroute_planning.php`, `term_staffing.php`, `enroute_staffing.php`, `dcc_staffing.php`, `term_constraints.php`, `enroute_constraints.php`, `group_flights.php`.

The full list of files to update in `api/mgt/perti/`: `post.php`, `update.php`, `delete.php`, and all staffing/constraint/initiative CRUD endpoints.

**Step 5: Verify**

Hit `GET /api/data/plans.l.php` — should return same plans as before (all `vatcscc`). Switch to `vatcan` org → should return empty.

**Step 6: Commit**

```bash
git add api/data/plans.l.php api/mgt/perti/ api/data/plans/
git commit -m "feat(org): scope plan API endpoints by org_code"
```

---

## Task 9: Add org_code Filtering to TMI API Endpoints

**Files:**
- Modify: `api/tmi/active.php` (lines 43-69)
- Modify: `api/tmi/programs.php`
- Modify: `api/tmi/advisories.php`
- Modify: `api/tmi/entries.php`
- Modify: `api/mgt/tmi/*.php` write endpoints

**Step 1: Update active.php**

The TMI queries use Azure SQL via `sqlsrv`. Add `org_code` filter to the active entries and programs queries.

For the entries query (lines 43-54), add `WHERE org_code = ?` to the view query or add it as a parameter. Since the view `vw_tmi_active_entries` doesn't have org_code yet, query the base table directly:

```php
$org = get_org_code();

// Active entries for this org + cross-border
$entries = tmi_query(
    "SELECT * FROM dbo.tmi_entries
     WHERE status = 'ACTIVE' AND (
         org_code = ?
         OR ctl_element IN (SELECT facility_code FROM dbo.org_facilities WHERE org_code = ?)
     )
     ORDER BY created_at DESC",
    [$org, $org]
);
```

Note: `org_facilities` is in MySQL, not Azure SQL. For cross-border TMI detection in Azure SQL, either:
- (a) Replicate the org_facilities data to Azure SQL, or
- (b) Use the regex-based detection from MultiDiscordAPI (existing pattern), or
- (c) Query cross-border in PHP after fetching results

Option (b) is simplest — add cross-border detection in PHP post-query. For the primary query, just filter by `org_code`:

```php
$entries = tmi_query("SELECT ... FROM dbo.tmi_entries WHERE status = 'ACTIVE' AND org_code = ?", [$org]);

// Cross-border: fetch foreign TMIs referencing our facilities
$cross_border = tmi_query("SELECT ... FROM dbo.tmi_entries WHERE status = 'ACTIVE' AND org_code != ?", [$org]);
// Filter in PHP using org_facilities lookup
```

**Step 2: Update TMI write endpoints**

In `api/tmi/programs.php` createProgram(), add `org_code` to INSERT:

```php
$org = get_org_code();
// Add to INSERT column list and values
```

Same pattern for `api/tmi/advisories.php`, `api/tmi/entries.php`, `api/mgt/tmi/` endpoints.

**Step 3: Update views**

Update `vw_tmi_active_entries` and `vw_tmi_active_programs` views in Azure SQL to include `org_code` column.

**Step 4: Verify**

`GET /api/tmi/active.php` — should return same TMIs as before. Switch to vatcan → empty (no VATCAN TMIs yet).

**Step 5: Commit**

```bash
git add api/tmi/ api/mgt/tmi/
git commit -m "feat(org): scope TMI API endpoints by org_code"
```

---

## Task 10: Update Locale Loader for Multi-Locale Support

**Files:**
- Modify: `assets/locales/index.js` (lines 24, 30-67, 73-204)

**Step 1: Update SUPPORTED_LOCALES**

Change line 24:
```javascript
// Before:
const SUPPORTED_LOCALES = ['en-US'];

// After:
const SUPPORTED_LOCALES = ['en-US', 'en-CA', 'fr-CA'];
```

**Step 2: Add org default locale detection**

In the `detectLocale()` function (lines 30-67), add a new step between localStorage (line 43) and browser language (line 53):

```javascript
// Check org default locale (from window.PERTI_ORG injected by header.php)
if (typeof window !== 'undefined' && window.PERTI_ORG && window.PERTI_ORG.defaultLocale) {
    if (SUPPORTED_LOCALES.includes(window.PERTI_ORG.defaultLocale)) {
        return window.PERTI_ORG.defaultLocale;
    }
}
```

**Step 3: Update loadLocaleSync to handle new locales**

The `loadLocaleSync()` function (line 73) returns inline strings for `en-US` and empty for others. Update to return the same inline strings for `en-CA` (they share most common strings). `fr-CA` can start empty (will load from JSON):

```javascript
function loadLocaleSync(locale) {
    if (locale === 'en-US' || locale === 'en-CA') {
        return { /* existing inline strings */ };
    }
    return {};
}
```

**Step 4: Update init() to load fallback + primary**

In the `init()` function (lines 209-248), for non-en-US locales, load `en-US.json` as fallback first:

```javascript
function init() {
    // ... existing detection ...
    const locale = detectLocale();
    const inlineStrings = loadLocaleSync(locale);

    PERTII18n.setLocale(locale);
    PERTII18n.loadStrings(inlineStrings, true); // fallback
    PERTII18n.loadStrings(inlineStrings);

    // Load en-US.json as fallback for non-en-US locales
    if (locale !== 'en-US') {
        try {
            var xhrFallback = new XMLHttpRequest();
            xhrFallback.open('GET', '/assets/locales/en-US.json', false);
            xhrFallback.send();
            if (xhrFallback.status === 200) {
                PERTII18n.loadStrings(JSON.parse(xhrFallback.responseText), true);
            }
        } catch (e) { /* fallback to inline */ }
    }

    // Load locale-specific JSON (existing logic)
    try {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/assets/locales/' + locale + '.json', false);
        xhr.send();
        if (xhr.status === 200) {
            PERTII18n.loadStrings(JSON.parse(xhr.responseText));
        }
    } catch (e) { /* use inline */ }

    // ... rest of init ...
}
```

**Step 5: Commit**

```bash
git add assets/locales/index.js
git commit -m "feat(i18n): update locale loader for en-CA and fr-CA support"
```

---

## Task 11: Create en-CA and fr-CA Locale Files

**Files:**
- Create: `assets/locales/en-CA.json`
- Create: `assets/locales/fr-CA.json`

**Step 1: Create en-CA.json**

Delta from en-US: Canadian English spellings, org-specific terms, DCC→NOC references. Only keys that differ from `en-US.json`. Missing keys fall back to `en-US` automatically.

Key overrides:
- `org.*` — CANTMU/NOC/Canadian terminology
- `dccRegion.*` — Region names
- `jatoc.auth.dccOnly` — NOC reference
- `tmr.trigger.dccInitiated` — NOC reference
- `tmr.staffing.dcc` — NOC reference
- Spelling: centre, colour, programme (where contextually appropriate)

**Step 2: Create fr-CA.json**

Full French translation of all ~450 keys. Use NAV CANADA operational terminology as baseline. This can be delivered incrementally — start with the `org.*`, `common.*`, `tmi.*`, `dialog.*`, and `status.*` sections (~100 keys), then expand.

**Step 3: Verify**

Switch to VATCAN org → set locale to `en-CA` → org badge shows "NOC", strings use Canadian spelling.
Set locale to `fr-CA` → common UI elements show French.

**Step 4: Commit**

```bash
git add assets/locales/en-CA.json assets/locales/fr-CA.json
git commit -m "feat(i18n): add en-CA and fr-CA locale files"
```

---

## Task 12: Add Language Toggle for VATCAN Users

**Files:**
- Modify: `load/nav.php` (near org badge, line ~188)

**Step 1: Add EN/FR toggle**

After the org badge (added in Task 7), conditionally render a language toggle for VATCAN users:

```php
<?php if ($org_code === 'vatcan'): ?>
    <div class="btn-group btn-group-sm mr-2" role="group">
        <button type="button" class="btn btn-outline-light btn-lang" onclick="setLocale('en-CA')" id="btn-lang-en">EN</button>
        <button type="button" class="btn btn-outline-light btn-lang" onclick="setLocale('fr-CA')" id="btn-lang-fr">FR</button>
    </div>
    <script>
    function setLocale(locale) {
        localStorage.setItem('PERTI_LOCALE', locale);
        window.location.reload();
    }
    (function() {
        var loc = localStorage.getItem('PERTI_LOCALE') || 'en-CA';
        var activeBtn = loc === 'fr-CA' ? 'btn-lang-fr' : 'btn-lang-en';
        var el = document.getElementById(activeBtn);
        if (el) el.classList.add('active');
    })();
    </script>
<?php endif; ?>
```

**Step 2: Verify**

Switch to VATCAN org → EN/FR toggle appears. Click FR → page reloads with French strings. Click EN → back to Canadian English.

**Step 3: Commit**

```bash
git add load/nav.php
git commit -m "feat(i18n): add EN/FR language toggle for VATCAN users"
```

---

## Task 13: Update Privilege Checks to Use Org Context

**Files:**
- Modify: `load/nav.php` (lines 20-34 — permission check)
- Modify: All `api/mgt/perti/*.php` files (permission pattern)

**Step 1: Update nav.php permission check**

The current pattern (lines 20-34) checks if the user exists in the `users` table. Update to also load org context and set `$perm` based on `is_org_privileged()`:

After the existing `$perm = true;` (line 28), add:

```php
// Load org context
require_once(__DIR__ . '/org_context.php');
if (!isset($_SESSION['ORG_CODE'])) {
    load_org_context((int)$cid, $conn_sqli);
}
```

The `$perm` variable stays based on the `users` table (basic authentication), but org-specific privilege is checked via `is_org_privileged()` for write operations.

**Step 2: Update api/mgt/perti/ permission pattern**

In each `api/mgt/perti/*.php` file, the existing pattern checks `$perm` from the `users` table. Add org privilege check after:

```php
// After existing perm check
require_once(dirname(__DIR__, 3) . '/load/org_context.php');
if (!is_org_privileged()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not privileged for this organization']);
    exit;
}
```

**Step 3: Commit**

```bash
git add load/nav.php api/mgt/perti/
git commit -m "feat(org): integrate org privilege checks into write endpoints"
```

---

## Task 14: Add org_code to New Locale Keys (en-US.json)

**Files:**
- Modify: `assets/locales/en-US.json`

**Step 1: Add org.* section**

Add to the top level of `en-US.json`:

```json
{
    "org": {
        "name": "vATCSCC",
        "displayName": "DCC",
        "airspace": "National Airspace System",
        "commandCenter": "Division Command Center",
        "facilityType": "ARTCC",
        "facilityTypePlural": "ARTCCs",
        "switchOrg": "Switch Organization",
        "currentOrg": "Current: {org}"
    }
}
```

**Step 2: Commit**

```bash
git add assets/locales/en-US.json
git commit -m "feat(i18n): add org.* translation keys to en-US"
```

---

## Task 15: Auto-Detect VATSIM Division on First Login

**Files:**
- Modify: `login/callback.php` (after line 110)

**Step 1: Add division detection**

After the OAuth user fetch (line 86, `$obj_at = json_decode($json_at, true);`), extract division info:

```php
$division = $obj_at['data']['vatsim']['division']['id'] ?? null;
```

After `sessionstart()` and `load_org_context()`, if the user has no `user_orgs` rows, auto-create based on division:

```php
// Auto-detect org from VATSIM division
$division = $obj_at['data']['vatsim']['division']['id'] ?? null;
$auto_org = 'vatcscc'; // default
if ($division === 'VATCAN' || $division === 'CAN') {
    $auto_org = 'vatcan';
}

// Check if user already has org assignment
$org_check = mysqli_prepare($conn_sqli, "SELECT COUNT(*) as cnt FROM user_orgs WHERE cid = ?");
mysqli_stmt_bind_param($org_check, "i", $cid);
mysqli_stmt_execute($org_check);
$org_result = mysqli_stmt_get_result($org_check);
$org_row = mysqli_fetch_assoc($org_result);

if ($org_row['cnt'] == 0) {
    // First login: auto-assign based on division
    $insert_org = mysqli_prepare($conn_sqli, "INSERT INTO user_orgs (cid, org_code, is_privileged, is_primary) VALUES (?, ?, 0, 1)");
    mysqli_stmt_bind_param($insert_org, "is", $cid, $auto_org);
    mysqli_stmt_execute($insert_org);
}

// Load org context (uses the auto-assigned org)
require_once dirname(__DIR__) . '/load/org_context.php';
load_org_context((int)$cid, $conn_sqli);
```

**Step 2: Verify**

A new VATCAN user logging in for the first time → auto-assigned to `vatcan` org with `is_privileged = 0`.

**Step 3: Commit**

```bash
git add login/callback.php
git commit -m "feat(org): auto-detect VATSIM division on first login"
```

---

## Task 16: Include org_context.php in Shared Includes

**Files:**
- Modify: `load/connect.php` (add require at end)

**Step 1: Auto-load org_context.php**

At the end of `load/connect.php` (before the final line), add:

```php
// Load organization context helpers
require_once __DIR__ . '/org_context.php';
```

This ensures `get_org_code()` and friends are available everywhere `connect.php` is loaded, without needing explicit requires in every endpoint.

**Step 2: Commit**

```bash
git add load/connect.php
git commit -m "feat(org): auto-load org_context.php from connect.php"
```

---

## Task 17: End-to-End Verification

**No files to commit — manual testing only.**

**Step 1: Verify schema**

- [ ] `organizations` table has 2 rows
- [ ] `user_orgs` has CID 1234727 on both orgs
- [ ] `org_facilities` has ~37 facility mappings
- [ ] `p_plans` has `org_code` column, all existing rows = `vatcscc`
- [ ] TMI tables in Azure SQL have `org_code` column

**Step 2: Verify login flow**

- [ ] Login as CID 1234727 → session has `ORG_CODE=vatcscc`, `ORG_PRIVILEGED=true`, `ORG_ALL=['vatcscc','vatcan']`
- [ ] Org badge shows "DCC" in nav bar
- [ ] Org switcher dropdown shows DCC/NOC options

**Step 3: Verify org switching**

- [ ] Click "NOC" in org switcher → page reloads
- [ ] Org badge now shows "NOC" (red)
- [ ] EN/FR toggle appears
- [ ] Plan list is empty (no VATCAN plans yet)
- [ ] Switch back to "DCC" → plans reappear

**Step 4: Verify plan CRUD scoping**

- [ ] In DCC context: create a plan → `org_code = vatcscc` in DB
- [ ] Switch to NOC: plan not visible
- [ ] In NOC context: create a plan → `org_code = vatcan` in DB
- [ ] Switch to DCC: VATCAN plan not visible

**Step 5: Verify i18n**

- [ ] In NOC context: click "FR" → French strings appear
- [ ] Click "EN" → Canadian English strings appear
- [ ] In DCC context: no language toggle visible
- [ ] `org.displayName` shows "DCC" or "NOC" appropriately

---

## Summary: Task Dependencies

```
Task 1  (org schema)
Task 2  (plan columns)     ← depends on Task 1
Task 3  (TMI columns)      ← independent of Task 2
Task 4  (org_context.php)  ← depends on Task 1
Task 5  (login flow)       ← depends on Task 4
Task 6  (switch endpoint)  ← depends on Task 4
Task 7  (frontend inject)  ← depends on Task 4
Task 8  (plan API)         ← depends on Tasks 2, 4
Task 9  (TMI API)          ← depends on Tasks 3, 4
Task 10 (locale loader)    ← independent
Task 11 (locale files)     ← depends on Task 10
Task 12 (lang toggle)      ← depends on Tasks 7, 11
Task 13 (privilege checks) ← depends on Task 4
Task 14 (en-US keys)       ← independent
Task 15 (auto-detect)      ← depends on Tasks 1, 5
Task 16 (auto-load)        ← depends on Task 4
Task 17 (verification)     ← depends on all above
```

**Parallelizable groups:**
- Group A (schema): Tasks 1, 2, 3
- Group B (backend): Tasks 4, 5, 6, 13, 15, 16
- Group C (frontend): Tasks 7, 10, 11, 12, 14
- Group D (API scoping): Tasks 8, 9
- Group E (verification): Task 17
