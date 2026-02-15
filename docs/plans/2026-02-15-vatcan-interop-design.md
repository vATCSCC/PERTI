# VATCAN Interoperability Design

**Date:** 2026-02-15
**Branch:** `feature/perti-vatcan`
**Worktree:** `C:/Temp/perti-worktrees/perti-vatcan`

## Overview

Adapt PERTI to support VATCAN (VATSIM Canada) as a second organization alongside vATCSCC (VATUSA). Each org has isolated operational data (plans, TMIs, staffing) with cross-border TMI coordination for initiatives affecting both regions.

### Key Decisions

| Decision | Choice |
|----------|--------|
| Org model | Isolated workspaces with cross-border TMI coordination |
| Auth | Auto-detect VATSIM division + admin override |
| Feature scope | Full parity, scoped to Canadian airspace |
| UI | Neutral PERTI brand, org-specific terminology |
| Locales | `en-US`, `en-CA`, `fr-CA` (VATCAN is bilingual) |
| Roles | Binary privileged/unprivileged per org, no role hierarchy |
| Flight data | Global — all flights visible to all orgs |
| Approach | Org-column extension (add `org_code` to key tables) |
| Rollout | Infrastructure first, Discord channels later |
| Super user | CID 1234727 (Jeremy Peterson / HP) — full owner on both orgs |

### Org Terminology

| Key | vATCSCC (en-US) | VATCAN (en-CA / fr-CA) |
|-----|-----------------|------------------------|
| `org.name` | vATCSCC | CANTMU |
| `org.displayName` | DCC | NOC |
| `org.airspace` | National Airspace System | Canadian Airspace System |
| `org.commandCenter` | Division Command Center | Canadian TMU |
| `org.facilityType` | ARTCC | FIR |

### VATUSA → VATCAN Role Title Mapping

Same permission levels, different display titles:
- ATM → CF (Chief)
- DATM → DC (Deputy Chief)
- TA → CI (Chief Instructor)
- EC → EC (Event Coordinator)
- FE → FE (Facility Engineer)
- WM → WM (Webmaster)

(Role titles are display-only; the system uses binary `is_privileged` for access control.)

---

## Section 1: Organization & User Schema

### New Tables (perti_site MySQL)

#### `organizations`

```sql
CREATE TABLE organizations (
    org_code VARCHAR(16) PRIMARY KEY,
    org_name VARCHAR(64) NOT NULL,
    display_name VARCHAR(64) NOT NULL,
    region VARCHAR(8) NOT NULL,
    vatsim_division VARCHAR(8) NOT NULL,
    default_locale VARCHAR(8) NOT NULL DEFAULT 'en-US',
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO organizations VALUES
    ('vatcscc', 'vATCSCC', 'DCC', 'US', 'VATUSA', 'en-US', 1, NOW()),
    ('vatcan', 'VATCAN', 'NOC', 'CA', 'VATCAN', 'en-CA', 1, NOW());
```

#### `user_orgs`

```sql
CREATE TABLE user_orgs (
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

-- Seed super user on both orgs
INSERT INTO user_orgs (cid, org_code, is_privileged, is_primary) VALUES
    (1234727, 'vatcscc', 1, 1),
    (1234727, 'vatcan', 1, 0);
```

### Session Context

After login, session receives:

```php
$_SESSION['ORG_CODE']       // 'vatcscc' or 'vatcan' (active org)
$_SESSION['ORG_PRIVILEGED'] // true/false for active org
$_SESSION['ORG_ALL']        // ['vatcscc','vatcan'] for multi-org users
```

### Auto-Detection Flow

1. User logs in via VATSIM OAuth
2. Query VATSIM API `/api/v2/members/{cid}` for `division` field
3. `VATUSA` → assign `vatcscc`, `VATCAN` → assign `vatcan`
4. Insert `user_orgs` row if not exists (`is_privileged = 0` default)
5. Admins can override org assignment and toggle `is_privileged`

---

## Section 2: Org-Scoping Existing Data

### Tables Getting `org_code VARCHAR(16) NOT NULL DEFAULT 'vatcscc'`

#### perti_site MySQL

- `p_plans` — PERTI event plans (primary query target)
- `p_configs` — Airport configs per plan
- `p_terminal_staffing` / `p_enroute_staffing` — Staffing
- `p_dcc_staffing` — DCC staffing
- `p_terminal_constraints` / `p_enroute_constraints` — Constraints
- `p_terminal_init` / `p_enroute_init` — Initiatives
- `p_terminal_init_timeline` / `p_enroute_init_timeline` — Initiative timelines
- `p_terminal_init_times` / `p_enroute_init_times` — Initiative times
- `p_terminal_planning` / `p_enroute_planning` — Planning comments
- `p_op_goals` — Planning goals
- `p_forecast` — Demand forecasts
- `p_historical` — Historical references
- `p_group_flights` — Group flights
- `r_scores` / `r_comments` / `r_data` / `r_ops_data` — Review data
- `assigned` — Role assignments

Child tables inherit org scoping from parent `p_plans` JOIN, but carry `org_code` for defense-in-depth.

#### VATSIM_TMI Azure SQL

- `tmi_programs` — GDP/GS/AFP programs
- `tmi_advisories` — Advisory messages
- `tmi_entries` — MIT/AFP/restriction entries
- `tmi_reroutes` — Reroute definitions
- `tmi_public_routes` — Published routes
- `tmi_airport_configs` — TMI airport config snapshots
- `tmi_delay_entries` — Delay reports
- `tmi_proposals` — Coordination proposals

(`tmi_discord_posts` already has `org_code`.)

### Tables That Stay Global

- All ADL tables (flight data)
- All REF/GIS tables (reference/navdata)
- SWIM_API tables (public API)
- `config_data` (default airport rates)
- `route_cdr` / `route_playbook` (route reference)
- `division_events` / `perti_events` (already have `source` field)

### Query Pattern

```php
// Read endpoints: filter by session org
$sql = "SELECT * FROM p_plans WHERE id = ? AND org_code = ?";

// Write endpoints: inject org on INSERT
$sql = "INSERT INTO p_plans (event_name, org_code, ...) VALUES (?, ?, ...)";

// Write endpoints: validate org on UPDATE/DELETE
$sql = "UPDATE p_plans SET ... WHERE id = ? AND org_code = ?";
```

All existing rows backfilled to `'vatcscc'` via `DEFAULT`.

---

## Section 3: i18n Integration

### Current State

- `PERTII18n` module (`assets/js/lib/i18n.js`): `t()`, `tp()`, fallback chain
- Locale loader (`assets/locales/index.js`): hardcoded `SUPPORTED_LOCALES = ['en-US']`
- `en-US.json`: ~450 keys, 2,050 lines — comprehensive
- Fallback: `strings[key] → fallbackStrings[key] → key`

### Changes to Locale Loader

1. Update `SUPPORTED_LOCALES` to `['en-US', 'en-CA', 'fr-CA']`
2. Add org default locale detection (step 3 in priority):
   ```
   URL param → localStorage → org default → browser language → en-US
   ```
3. For non-en-US locales: load `en-US.json` as fallback, then locale-specific JSON as primary

### Locale Files

| File | Strategy | Est. Keys |
|------|----------|-----------|
| `en-US.json` | Existing authoritative baseline | ~450 (exists) |
| `en-CA.json` | Delta: Canadian spellings + org terms | ~80-100 |
| `fr-CA.json` | Full French translation | ~450 |

`en-CA.json` and `fr-CA.json` only need keys that differ from `en-US.json` — missing keys fall back automatically.

### Keys Needing Org-Aware Overrides

- `jatoc.auth.dccOnly` — "Only DCC users may {action}."
- `tmr.trigger.dccInitiated` — "DCC-initiated TMR"
- `tmr.staffing.dcc` — "DCC"
- `nod.colorMode.dccRegion` — "DCC Region"
- `splits.popup.dccRegion` — "DCC Region"
- `gdt.exemption.origArtccs` / `destArtccs` — ARTCC → FIR for VATCAN

### New `org.*` Keys

```json
{
    "org": {
        "name": "vATCSCC | CANTMU",
        "displayName": "DCC | NOC",
        "airspace": "National Airspace System | Canadian Airspace System",
        "commandCenter": "Division Command Center | Canadian TMU",
        "facilityType": "ARTCC | FIR",
        "facilityTypePlural": "ARTCCs | FIRs"
    }
}
```

### Language Toggle

- VATCAN users (`org_code = 'vatcan'`): EN/FR toggle in nav bar
- vATCSCC users: no toggle visible
- Sets `localStorage.PERTI_LOCALE`, triggers page reload

### French Translation Source

NAV CANADA confirmed operational terminology as baseline:
- TMI → Initiative de gestion de la circulation (IGC)
- GDP → Programme d'attente au sol (PAS)
- Ground Stop → Arrêt au sol
- Flow control → Gestion de la circulation aérienne
- Metering → Espacement
- Advisory → Avis
- Reroute → Déroutement
- Acceptance Rate → Taux d'acceptation
- Departure Rate → Taux de départ

---

## Section 4: UI & Session Context

### Nav Bar Changes

```
[PERTI logo] [Plans] [Schedule] [Demand] ...    [DCC ▾] [User ▾]
                                                 [NOC ▾] [EN|FR] [User ▾]
```

- **Org badge**: shows `displayName` of active org (DCC or NOC)
- **Single-org users**: static badge, no dropdown
- **Multi-org users**: dropdown to switch active org context
- **Language toggle**: only visible for VATCAN users

### Org Context Switching

1. AJAX POST to `api/session/switch_org.php` with `{ org_code: 'vatcan' }`
2. Server validates `user_orgs` row exists for user + requested org
3. Updates `$_SESSION['ORG_CODE']` and `$_SESSION['ORG_PRIVILEGED']`
4. JS reloads page — all data queries now filter by new org

### Page-Level Org Injection

`load/header.php` injects:

```html
<script>
window.PERTI_ORG = {
    code: '<?= $_SESSION["ORG_CODE"] ?>',
    name: '<?= $org_display_name ?>',
    privileged: <?= $_SESSION["ORG_PRIVILEGED"] ? "true" : "false" ?>,
    allOrgs: <?= json_encode($_SESSION["ORG_ALL"]) ?>,
    defaultLocale: '<?= $org_default_locale ?>'
};
</script>
```

### Data Scoping in UI

- Plan list (`index.php`): `WHERE org_code = ?`
- TMI pages: filter by org + cross-border UNION
- "Create" buttons: visible only when `ORG_PRIVILEGED = true`
- No org badge on individual items (redundant within org context)

### Colour Theming (Minimal)

Org badge only:
- vATCSCC: blue (`#1a73e8`)
- VATCAN: red (`#d32f2f`)

---

## Section 5: Cross-Border TMI Coordination

### Existing Infrastructure

`MultiDiscordAPI::detectCrossBorderOrgs()` already identifies cross-border TMIs via facility/airport pattern matching:
- `Z[A-Z]{2}` → US (vatcscc)
- `CZ[A-Z]{2}` → Canada (vatcan)
- `K[A-Z]{3}` → US airport
- `C[A-Z]{3}` → Canadian airport
- `PA[A-Z]{2}`, `PH[A-Z]{2}`, `PG[A-Z]{2}`, `TJ[A-Z]{2}` → US territories

### TMI Ownership

TMI gets `org_code` of the **creating org**. Cross-border detection posts to both Discord channels but does not change ownership.

### `org_facilities` Table

```sql
CREATE TABLE org_facilities (
    org_code VARCHAR(16) NOT NULL,
    facility_code VARCHAR(8) NOT NULL,
    facility_type VARCHAR(16) NOT NULL,
    PRIMARY KEY (org_code, facility_code),
    FOREIGN KEY (org_code) REFERENCES organizations(org_code)
);
```

Seeded from facility hierarchy:
- 20 US ARTCCs + oceanic (ZAK, ZAN, ZHN, etc.) + TRACONs → `vatcscc`
- 8 Canadian FIRs + TRACONs → `vatcan`

### Cross-Border Visibility

Each org's TMI list includes a secondary query for foreign TMIs referencing their facilities:

```sql
-- My org's active TMIs
SELECT * FROM tmi_programs WHERE org_code = ? AND status = 'ACTIVE'
UNION ALL
-- Other org's TMIs touching my facilities
SELECT p.* FROM tmi_programs p
WHERE p.org_code != ? AND p.status = 'ACTIVE'
  AND (p.ctl_element IN (SELECT facility_code FROM org_facilities WHERE org_code = ?))
```

Cross-border TMIs display with origin org badge. **Read-only** in the foreign org's view.

### Coordination Proposals

Existing `tmi_proposals` system works across orgs naturally — tracks `requesting_facility` and `providing_facility`. Discord bot processes reactions from both guild servers.

---

## Section 6: API Layer Changes

### PHP Request Context

New shared include (`load/org_context.php`):

```php
function get_org_code() {
    return $_SESSION['ORG_CODE'] ?? 'vatcscc';
}

function is_org_privileged() {
    return !empty($_SESSION['ORG_PRIVILEGED']);
}

function require_org_privileged() {
    if (!is_org_privileged()) {
        Response::error(403, 'Insufficient privileges');
        exit;
    }
}
```

### Endpoint Changes

**Write endpoints** (create/update/delete):
- Inject `org_code = get_org_code()` on INSERT
- Validate `AND org_code = ?` on UPDATE/DELETE
- Applies to: `api/mgt/perti/*`, `api/mgt/tmi/*`

**Read endpoints** (list/get):
- Add `WHERE org_code = ?` filter
- Applies to: `api/data/plans/*`, `api/tmi/active.php`, `programs.php`, `advisories.php`
- TMI endpoints add cross-border UNION

**Global endpoints** (no filter):
- `api/adl/*` — flight data
- `api/swim/v1/*` — public API
- `api/data/fixes.php`, `routes.php` — reference data
- `api/stats/*` — statistics

### New Endpoints

- `api/session/switch_org.php` — switch active org context
- SWIM TMI endpoints: add `org_code` to response payload for consumers

---

## Section 7: Migration & Rollout

### Phase 1: Schema Additions (non-breaking)

- Create `organizations`, `user_orgs`, `org_facilities` tables
- Seed org data, CID 1234727, facility mappings
- Backfill existing users to `vatcscc`

### Phase 2: Add `org_code` Columns

- ALTER TABLE on all target tables with `DEFAULT 'vatcscc'`
- Existing rows auto-backfilled via DEFAULT
- No data migration script needed

### Phase 3: Backend Changes

- Deploy `org_context.php`, update API endpoints
- `get_org_code()` returns `'vatcscc'` for existing sessions — zero behavior change

### Phase 4: Frontend Changes

- Org badge, locale files, nav bar, language toggle
- Update locale loader with new supported locales

### Phase 5: Enable Auto-Detection

- VATSIM division lookup on login
- VATCAN users auto-assigned on first login

### Phase 6: VATCAN Discord (when ready)

- Update config with VATCAN guild/channel IDs
- Enable `vatcan` org in Discord config

### Backwards Compatibility

- Sessions without `ORG_CODE` default to `'vatcscc'`
- All existing queries return same results (all data is `vatcscc`)
- No feature flags needed — org system is inert until VATCAN users log in
