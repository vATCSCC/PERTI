# CTP Pull-Based Sync — Go-Live Checklist

## Current Status: BLOCKED (waiting on CTP team)

**Deployed**: 2026-03-26 (PR #244, squash-merged to main)

## What's Deployed & Ready

- **Code**: `CTPPlaybookSync` service, `CTPApiClient`, push endpoint, pull orchestrator
- **Push endpoint**: `POST /api/swim/v1/ingest/ctp-routes.php` — SWIM API key auth, returns 401 correctly
- **Pull endpoint**: `/scripts/ctp/ctp_pull_sync.php` — disabled, returns clean JSON
- **MySQL migration 014**: `ctp_external_fields` — extends `route_playbook` with external tracking columns
- **MySQL migration 015**: `ctp_pull_sync_state` — state tracking table for pull sync
- **Azure App Settings configured**:
  - `CTP_API_URL` = `https://planning.ctp.vatsim.net`
  - `CTP_EVENT_CODE` = `CTPE26`
  - `CTP_PULL_SECRET` = `ctp_pull_5b604a9e25722673ea1824b0800ee874`
  - `CTP_PULL_ENABLED` = `0` (disabled)

## Blocked On (from CTP team)

1. **API Key** — needed to authenticate against `GET /api/Routes`
2. **Session ID** — integer identifying the CTP event session
3. **Confirmation of endpoint URL & schema** — we built against:
   - `GET {CTP_API_URL}/api/Routes`
   - Auth: `X-API-Key` header
   - Response: array of `RouteSegment` objects with fields:
     - `identifier`, `routeString`, `routeSegmentGroup`, `routeSegmentTags[]`
     - `locations[]` (each with `identifier`), `maximumAircraftPerHour`

## Go-Live Steps (when info is available)

### 1. Set Azure App Settings

```
CTP_API_KEY=<key from CTP team>
CTP_SESSION_ID=<session ID from CTP team>
CTP_PULL_ENABLED=1
```

Via Azure CLI:
```bash
az webapp config appsettings set --name vatcscc --resource-group VATSIM_RG --settings \
  CTP_API_KEY="<key>" \
  CTP_SESSION_ID="<id>" \
  CTP_PULL_ENABLED="1"
```

### 2. Verify API connectivity

```bash
# Check status (no auth needed)
curl "https://perti.vatcscc.org/scripts/ctp/ctp_pull_sync.php?action=status"

# Should show CTP_PULL_ENABLED=true, API URL, event code, group mapping
```

### 3. Trigger first sync

```bash
# Force first sync (bypasses content hash check)
curl "https://perti.vatcscc.org/scripts/ctp/ctp_pull_sync.php?action=sync&secret=ctp_pull_5b604a9e25722673ea1824b0800ee874&force=1"
```

Expected response:
```json
{
  "action": "sync",
  "changed": true,
  "revision": 1,
  "hash": "<md5>",
  "route_count": <N>,
  "plays": {
    "CTPE26": {"inserted": N, "updated": 0, "deleted": 0},
    "CTPE26_NA": {"inserted": N, "updated": 0, "deleted": 0},
    "CTPE26_EU": {"inserted": N, "updated": 0, "deleted": 0},
    "CTPE26_OCA": {"inserted": N, "updated": 0, "deleted": 0}
  }
}
```

### 4. Verify in playbook UI

- Go to `https://perti.vatcscc.org/playbook.php`
- Check for plays: CTPE26, CTPE26_NA, CTPE26_EU, CTPE26_OCA
- Verify routes appear with correct origin/dest/routestring

### 5. Set up recurring sync (optional)

For automated polling, set up a cron curl loop or Azure Logic App:
```bash
# Every 5 minutes
curl -s "https://perti.vatcscc.org/scripts/ctp/ctp_pull_sync.php?action=sync&secret=ctp_pull_5b604a9e25722673ea1824b0800ee874"
```

The pull sync uses content hashing — if nothing changed on CTP side, it returns immediately without writing.

## Group Mapping

CTP groups map to PERTI playbook plays:

| CTP Group | PERTI Scope | Play Name | Description |
|-----------|-------------|-----------|-------------|
| `FULL`    | `full`      | CTPE26    | Combined all-scope routes |
| `AMAS`    | `na`        | CTPE26_NA | Americas / West Atlantic |
| `EMEA`    | `eu`        | CTPE26_EU | Europe / East Atlantic |
| `OCA`     | `oca`       | CTPE26_OCA| Oceanic tracks |

Configurable via `CTP_GROUP_MAPPING` App Setting (JSON):
```json
{"OCA":"oca","AMAS":"na","EMEA":"eu","FULL":"full"}
```

## Key Files

| File | Purpose |
|------|---------|
| `load/services/CTPPlaybookSync.php` | Core sync service (shared by push & pull) |
| `load/services/CTPApiClient.php` | HTTP client + transform + content hash |
| `api/swim/v1/ingest/ctp-routes.php` | Push endpoint (CTP sends to us) |
| `scripts/ctp/ctp_pull_sync.php` | Pull orchestrator (we poll CTP) |
| `database/migrations/playbook/014_ctp_external_fields.sql` | Schema: external tracking columns |
| `database/migrations/playbook/015_ctp_pull_sync_state.sql` | Schema: pull sync state table |
| `docs/superpowers/specs/2026-03-26-ctp-pull-sync-design.md` | Design spec |
| `docs/superpowers/plans/2026-03-26-ctp-pull-sync.md` | Implementation plan |
