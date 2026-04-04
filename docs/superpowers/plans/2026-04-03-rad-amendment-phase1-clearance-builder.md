# RAD Amendment V2 Phase 1: Clearance Builder — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add partial route amendment with auto-diff, clearance phraseology generation, structured builder form, and ISSUED state to the RAD page (TMU-only).

**Architecture:** Extends V1 RAD infrastructure (migration 057, RADService.php, rad-amendment.js). New `rad-clearance-builder.js` module handles client-side route diffing and phraseology. Migration 058 adds ISSUED state + clearance columns. Server-side `generateClearance()` provides fallback.

**Tech Stack:** PHP 8.2, Azure SQL (sqlsrv), PostgreSQL/PostGIS (PDO), jQuery 2.2.4, MapLibre GL, Bootstrap 4.5

**Spec:** `docs/superpowers/specs/2026-04-03-rad-amendment-workflow-design.md` (Sections 3-4, 7.1, 8.1, 9.1, 9.4, 10.1, 14)

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `assets/js/rad-clearance-builder.js` | LCS diff engine, phraseology patterns, structured form rendering |
| Create | `api/rad/clearance.php` | Server-side clearance generation endpoint |
| Create | `database/migrations/tmi/058_rad_v2_workflow.sql` | Schema: ISSUED state, clearance columns, TOS tables, role cache |
| Modify | `load/services/RADService.php` | `generateClearance()`, `issueAmendment()`, updated compliance |
| Modify | `api/rad/amendment.php` | Add `action=issue` handler |
| Modify | `api/rad/common.php` | Add `rad_require_role()` helper |
| Modify | `assets/js/rad-amendment.js` | Integrate clearance builder, add "Mark as Issued" |
| Modify | `assets/js/rad-monitoring.js` | Handle ISSUED status in badges/filters |
| Modify | `rad.php` | Clearance builder panel in Edit tab |
| Modify | `assets/css/rad.css` | Clearance builder styles |
| Modify | `assets/locales/en-US.json` | Clearance/status i18n keys |

---

### Task 1: Migration 058 — Schema Changes

**Files:**
- Create: `database/migrations/tmi/058_rad_v2_workflow.sql`

This migration extends the V1 `rad_amendments` table and creates new tables for Phase 2-3. We deploy the full schema now so Phase 2/3 don't need additional migrations.

- [ ] **Step 1: Write migration SQL**

```sql
-- Migration 058: RAD V2 Workflow — Multi-Actor Amendments + TOS
-- Target: VATSIM_TMI database
-- Depends on: 057_rad_tables.sql (deployed)

-- 1. Replace CHECK constraint: add V2 states, keep DLVD for backward compat
ALTER TABLE dbo.rad_amendments DROP CONSTRAINT CK_rad_status;
ALTER TABLE dbo.rad_amendments ADD CONSTRAINT CK_rad_status
    CHECK (status IN ('DRAFT','SENT','ISSUED','DLVD','ACPT','RJCT',
                      'TOS_PENDING','TOS_RESOLVED','FORCED','EXPR'));

-- 2. New columns for V2
ALTER TABLE dbo.rad_amendments ADD
    clearance_text      VARCHAR(MAX) NULL,
    clearance_segments  VARCHAR(MAX) NULL,
    closing_phrase      VARCHAR(20)  NULL,
    issued_by           INT          NULL,
    issued_utc          DATETIME2    NULL,
    rejected_by         INT          NULL,
    rejected_utc        DATETIME2    NULL,
    resolved_by         INT          NULL,
    tos_id              INT          NULL,
    forced_utc          DATETIME2    NULL,
    parent_amendment_id INT          NULL,
    actor_role          VARCHAR(10)  NULL;

-- 3. Update filtered index (include new active statuses)
DROP INDEX IX_rad_amendments_status ON dbo.rad_amendments;
CREATE INDEX IX_rad_amendments_status ON dbo.rad_amendments (status)
    INCLUDE (gufi, callsign, origin, destination, assigned_route, rrstat, sent_utc)
    WHERE status IN ('DRAFT','SENT','ISSUED','TOS_PENDING');

-- 4. Index for TOS resolution chain lookups
CREATE INDEX IX_rad_amendments_parent ON dbo.rad_amendments (parent_amendment_id)
    INCLUDE (gufi, status)
    WHERE parent_amendment_id IS NOT NULL;

-- 5. TOS table (Phase 3, deployed now for schema stability)
CREATE TABLE dbo.rad_tos (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    amendment_id    INT NOT NULL,
    gufi            NVARCHAR(64) NOT NULL,
    submitted_by    INT NOT NULL,
    submitted_role  VARCHAR(10) NOT NULL,
    submitted_utc   DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    resolved_utc    DATETIME2 NULL,
    resolved_action VARCHAR(20) NULL,
    resolved_option_rank INT NULL,
    resolved_by     INT NULL,
    notes           VARCHAR(500) NULL,
    CONSTRAINT FK_rad_tos_amendment FOREIGN KEY (amendment_id)
        REFERENCES dbo.rad_amendments(id)
);

CREATE INDEX IX_rad_tos_amendment ON dbo.rad_tos (amendment_id);
CREATE INDEX IX_rad_tos_gufi ON dbo.rad_tos (gufi);

-- 6. TOS options table (Phase 3)
CREATE TABLE dbo.rad_tos_options (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    tos_id          INT NOT NULL,
    rank            INT NOT NULL,
    route_string    VARCHAR(MAX) NOT NULL,
    option_type     VARCHAR(20) NOT NULL,
    distance_nm     DECIMAL(10,1) NULL,
    time_minutes    INT NULL,
    route_geojson   VARCHAR(MAX) NULL,
    CONSTRAINT FK_rad_tos_option_tos FOREIGN KEY (tos_id)
        REFERENCES dbo.rad_tos(id) ON DELETE CASCADE
);

CREATE INDEX IX_rad_tos_options_tos ON dbo.rad_tos_options (tos_id);
```

- [ ] **Step 2: Deploy migration to VATSIM_TMI**

Run via SSMS or sqlcmd with `jpeterson` admin credentials against `vatsim.database.windows.net` / `VATSIM_TMI`.

Verify:
```sql
SELECT name FROM sys.columns WHERE object_id = OBJECT_ID('dbo.rad_amendments') AND name = 'clearance_text';
-- Expected: 1 row
SELECT OBJECT_ID('dbo.rad_tos');
-- Expected: non-NULL
```

- [ ] **Step 3: Commit migration file**

```bash
git add database/migrations/tmi/058_rad_v2_workflow.sql
git commit -m "feat(rad): migration 058 — V2 schema (ISSUED state, clearance cols, TOS tables)"
```

---

### Task 2: i18n Keys for Clearance Builder + New Statuses

**Files:**
- Modify: `assets/locales/en-US.json`

- [ ] **Step 1: Add clearance builder and status keys**

Add under the existing `rad` object in `en-US.json`. The file uses nested objects auto-flattened to dot notation.

Keys to add inside `"rad": { ... }`:

```json
"clearance": {
    "builder": "Clearance Builder",
    "preview": "Clearance Preview",
    "filedRoute": "Filed Route",
    "assignedRoute": "Assigned Route",
    "generatedClearance": "Generated Clearance",
    "closingPhrase": "Closing Phrase",
    "thenAsFiled": "then as filed",
    "restUnchanged": "rest of route unchanged",
    "addSegment": "+ Add Segment",
    "removeSegment": "- Remove Last",
    "autoDetected": "Auto-detected from route diff",
    "anchor": "Anchor Fix",
    "amendment": "Amendment",
    "noChanges": "Routes are identical — no amendment needed",
    "copyToClipboard": "Copy to Clipboard",
    "copied": "Clearance copied to clipboard"
}
```

Update existing `"status"` object to add new states:

```json
"status": {
    "DRAFT": "Draft",
    "SENT": "Sent",
    "DLVD": "Delivered",
    "ISSUED": "Issued",
    "ACPT": "Accepted",
    "RJCT": "Rejected",
    "TOS_PENDING": "TOS Pending",
    "TOS_RESOLVED": "TOS Resolved",
    "FORCED": "Forced",
    "EXPR": "Expired"
}
```

Add action keys:

```json
"actions": {
    "issue": "Issue to Pilot",
    "markIssued": "Mark as Issued",
    "acceptOnBehalf": "Accept",
    "rejectOnBehalf": "Reject"
}
```

- [ ] **Step 2: Verify keys load**

Open RAD page in browser, open console:
```javascript
console.log(PERTII18n.t('rad.clearance.builder'));
// Expected: "Clearance Builder"
console.log(PERTII18n.t('rad.status.ISSUED'));
// Expected: "Issued"
```

- [ ] **Step 3: Commit**

```bash
git add assets/locales/en-US.json
git commit -m "feat(rad): i18n keys for clearance builder and V2 status labels"
```

---

### Task 3: Clearance Builder JS Module — LCS Diff Engine

**Files:**
- Create: `assets/js/rad-clearance-builder.js`

This is the largest new module. It has three parts: (A) LCS diff engine, (B) phraseology generator, (C) form renderer. This task covers part A.

- [ ] **Step 1: Create module skeleton with LCS diff engine**

Create `assets/js/rad-clearance-builder.js`:

```javascript
/**
 * RAD Clearance Builder — Route diff engine + phraseology generator + structured form.
 *
 * Public API:
 *   RADClearanceBuilder.init(container)
 *   RADClearanceBuilder.setRoutes(filedRoute, assignedRoute)
 *   RADClearanceBuilder.getDiff()
 *   RADClearanceBuilder.getClearanceText()
 *   RADClearanceBuilder.getSegments()
 *   RADClearanceBuilder.setClosingPhrase(phrase)
 *   RADClearanceBuilder.reset()
 */
window.RADClearanceBuilder = (function() {
    var container = null;
    var filedRoute = '';
    var assignedRoute = '';
    var diffResult = null;
    var closingPhrase = 'then_as_filed';
    var callsign = '';
    var clearanceLimit = '';

    // =========================================================================
    // LCS Diff Engine
    // =========================================================================

    /**
     * Tokenize a route string into uppercase waypoint/airway tokens.
     * Strips empty tokens from extra whitespace.
     */
    function tokenize(route) {
        if (!route) return [];
        return route.trim().toUpperCase().split(/\s+/).filter(function(t) { return t.length > 0; });
    }

    /**
     * Longest Common Subsequence — returns array of common tokens in order.
     * Standard DP approach: O(m*n) where m,n are token counts.
     */
    function lcs(a, b) {
        var m = a.length, n = b.length;
        // Build DP table
        var dp = [];
        for (var i = 0; i <= m; i++) {
            dp[i] = [];
            for (var j = 0; j <= n; j++) {
                if (i === 0 || j === 0) {
                    dp[i][j] = 0;
                } else if (a[i - 1] === b[j - 1]) {
                    dp[i][j] = dp[i - 1][j - 1] + 1;
                } else {
                    dp[i][j] = Math.max(dp[i - 1][j], dp[i][j - 1]);
                }
            }
        }

        // Backtrack to find LCS sequence
        var result = [];
        var i = m, j = n;
        while (i > 0 && j > 0) {
            if (a[i - 1] === b[j - 1]) {
                result.unshift(a[i - 1]);
                i--; j--;
            } else if (dp[i - 1][j] > dp[i][j - 1]) {
                i--;
            } else {
                j--;
            }
        }
        return result;
    }

    /**
     * Compute structured diff between filed and assigned routes.
     * Returns: { anchors, segments, unchanged_prefix, unchanged_suffix, clearance_limit }
     */
    function computeDiff(filed, assigned) {
        var filedTokens = tokenize(filed);
        var assignedTokens = tokenize(assigned);

        if (filedTokens.length === 0 || assignedTokens.length === 0) {
            return null;
        }

        // LCS gives us anchor fixes (common tokens in order)
        var anchors = lcs(filedTokens, assignedTokens);

        if (anchors.length === 0) {
            // No common fixes — entire route is changed
            return {
                anchors: [],
                segments: [{
                    anchor_before: null,
                    anchor_after: null,
                    removed: filedTokens,
                    inserted: assignedTokens,
                    type: 'full'
                }],
                unchanged_prefix: [],
                unchanged_suffix: [],
                clearance_limit: assignedTokens[assignedTokens.length - 1] || ''
            };
        }

        // Walk both token arrays, building segments between anchors
        var segments = [];
        var filedIdx = 0;
        var assignedIdx = 0;

        // Find first anchor position in each array
        for (var a = 0; a < anchors.length; a++) {
            var anchor = anchors[a];
            var filedPos = filedTokens.indexOf(anchor, filedIdx);
            var assignedPos = assignedTokens.indexOf(anchor, assignedIdx);

            // Tokens between current position and this anchor are removed/inserted
            var removed = filedTokens.slice(filedIdx, filedPos);
            var inserted = assignedTokens.slice(assignedIdx, assignedPos);

            if (removed.length > 0 || inserted.length > 0) {
                var prevAnchor = a > 0 ? anchors[a - 1] : null;
                var segType = prevAnchor ? 'mid' : 'begin';
                segments.push({
                    anchor_before: prevAnchor,
                    anchor_after: anchor,
                    removed: removed,
                    inserted: inserted,
                    type: segType
                });
            }

            filedIdx = filedPos + 1;
            assignedIdx = assignedPos + 1;
        }

        // Tokens after the last anchor
        var trailingRemoved = filedTokens.slice(filedIdx);
        var trailingInserted = assignedTokens.slice(assignedIdx);
        if (trailingRemoved.length > 0 || trailingInserted.length > 0) {
            segments.push({
                anchor_before: anchors[anchors.length - 1],
                anchor_after: null,
                removed: trailingRemoved,
                inserted: trailingInserted,
                type: 'end'
            });
        }

        // Compute unchanged prefix/suffix
        var firstChangedAnchorIdx = 0;
        var lastChangedAnchorIdx = anchors.length - 1;
        if (segments.length > 0) {
            var firstSeg = segments[0];
            var lastSeg = segments[segments.length - 1];
            // Prefix: anchors before the first changed segment
            var prefixEnd = firstSeg.anchor_after ? anchors.indexOf(firstSeg.anchor_after) : 0;
            // Suffix: anchors after the last changed segment
            var suffixStart = lastSeg.anchor_before
                ? anchors.indexOf(lastSeg.anchor_before) + 1
                : anchors.length;
        }

        // Simple prefix/suffix: tokens from start of assigned until first change
        var unchangedPrefix = [];
        var unchangedSuffix = [];
        if (segments.length > 0 && segments[0].type !== 'begin') {
            // Everything before first segment's anchor_before is unchanged
            var firstAnchorBefore = segments[0].anchor_before;
            if (firstAnchorBefore) {
                var pos = assignedTokens.indexOf(firstAnchorBefore);
                unchangedPrefix = assignedTokens.slice(0, pos + 1);
            }
        }
        if (segments.length > 0 && segments[segments.length - 1].type !== 'end') {
            var lastAnchorAfter = segments[segments.length - 1].anchor_after;
            if (lastAnchorAfter) {
                var pos = assignedTokens.indexOf(lastAnchorAfter);
                unchangedSuffix = assignedTokens.slice(pos);
            }
        }

        return {
            anchors: anchors,
            segments: segments,
            unchanged_prefix: unchangedPrefix,
            unchanged_suffix: unchangedSuffix,
            clearance_limit: assignedTokens[assignedTokens.length - 1] || ''
        };
    }

    // =========================================================================
    // Phraseology Generator
    // =========================================================================

    /**
     * Generate ATC clearance phraseology from diff result.
     */
    function generatePhraseology(diff) {
        if (!diff || !diff.segments || diff.segments.length === 0) {
            return '';
        }

        var closing = closingPhrase === 'rest_unchanged'
            ? 'rest of route unchanged'
            : 'then as filed';

        var parts = [];
        parts.push(callsign || '{callsign}');
        parts.push('cleared to');
        parts.push(diff.clearance_limit || clearanceLimit || '{destination}');
        parts.push('via');

        diff.segments.forEach(function(seg, idx) {
            if (idx > 0) parts.push('then');

            if (seg.type === 'begin') {
                // Change at beginning: amendment, then anchor
                parts.push(seg.inserted.join(' '));
                if (seg.anchor_after) parts.push(seg.anchor_after);
            } else if (seg.type === 'mid') {
                // Mid-route: after anchor_before, amendment, anchor_after
                parts.push('after ' + seg.anchor_before);
                parts.push(seg.inserted.join(' '));
                if (seg.anchor_after) parts.push(seg.anchor_after);
            } else if (seg.type === 'end') {
                // End: after anchor_before, amendment
                parts.push('after ' + seg.anchor_before);
                parts.push(seg.inserted.join(' '));
            } else if (seg.type === 'full') {
                // Full route replacement
                parts.push(seg.inserted.join(' '));
            }
        });

        // Only add closing phrase if last segment isn't 'end' or 'full'
        var lastSeg = diff.segments[diff.segments.length - 1];
        if (lastSeg.type !== 'end' && lastSeg.type !== 'full') {
            parts.push(closing);
        }

        return parts.join(' ').replace(/\s+/g, ' ').trim().toUpperCase();
    }

    // =========================================================================
    // Form Renderer
    // =========================================================================

    function render() {
        if (!container) return;

        if (!diffResult || !diffResult.segments || diffResult.segments.length === 0) {
            var noChangeMsg = (!filedRoute || !assignedRoute)
                ? PERTII18n.t('rad.amendment.noPreview')
                : PERTII18n.t('rad.clearance.noChanges');
            container.innerHTML = '<div class="text-muted" style="padding:8px;">' + noChangeMsg + '</div>';
            return;
        }

        var html = '';

        // Auto-diff visualization
        html += '<div class="rad-clearance-diff">';
        html += '<div class="rad-clearance-diff-label">' + PERTII18n.t('rad.clearance.autoDetected') + '</div>';
        html += renderDiffVisualization(diffResult);
        html += '</div>';

        // Segment list
        html += '<div class="rad-clearance-segments">';
        diffResult.segments.forEach(function(seg, idx) {
            html += renderSegmentRow(seg, idx);
        });
        html += '</div>';

        // Closing phrase toggle
        html += '<div class="rad-clearance-closing">';
        html += '<label>' + PERTII18n.t('rad.clearance.closingPhrase') + ':</label>';
        html += '<select id="rad_closing_phrase" class="form-control form-control-sm" style="width:auto;display:inline-block;margin-left:6px;">';
        html += '<option value="then_as_filed"' + (closingPhrase === 'then_as_filed' ? ' selected' : '') + '>' + PERTII18n.t('rad.clearance.thenAsFiled') + '</option>';
        html += '<option value="rest_unchanged"' + (closingPhrase === 'rest_unchanged' ? ' selected' : '') + '>' + PERTII18n.t('rad.clearance.restUnchanged') + '</option>';
        html += '</select>';
        html += '</div>';

        // Generated clearance preview
        var phraseology = generatePhraseology(diffResult);
        html += '<div class="rad-clearance-preview">';
        html += '<div class="rad-clearance-preview-label">' + PERTII18n.t('rad.clearance.generatedClearance') + '</div>';
        html += '<div class="rad-clearance-text" id="rad_clearance_text">' + phraseology + '</div>';
        html += '<button class="btn btn-sm btn-outline-secondary mt-1" id="rad_btn_copy_clearance">';
        html += '<i class="fas fa-copy mr-1"></i>' + PERTII18n.t('rad.clearance.copyToClipboard');
        html += '</button>';
        html += '</div>';

        container.innerHTML = html;

        // Bind closing phrase change
        $('#rad_closing_phrase').on('change', function() {
            closingPhrase = $(this).val();
            render();
            RADEventBus.emit('clearance:updated', {
                text: generatePhraseology(diffResult),
                segments: getStructuredSegments(),
                closingPhrase: closingPhrase
            });
        });

        // Copy to clipboard
        $('#rad_btn_copy_clearance').on('click', function() {
            var text = $('#rad_clearance_text').text();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    PERTIDialog.success(PERTII18n.t('rad.clearance.copied'));
                });
            }
        });
    }

    function renderDiffVisualization(diff) {
        var filedTokens = tokenize(filedRoute);
        var assignedTokens = tokenize(assignedRoute);
        var html = '';

        // Filed route with removed tokens struck through
        html += '<div class="rad-diff-row">';
        html += '<span class="rad-diff-label">' + PERTII18n.t('rad.clearance.filedRoute') + ':</span>';
        filedTokens.forEach(function(token) {
            var isAnchor = diff.anchors.indexOf(token) !== -1;
            var isRemoved = !isAnchor && assignedTokens.indexOf(token) === -1;
            if (isAnchor) {
                html += '<span class="rad-diff-anchor">' + token + '</span> ';
            } else if (isRemoved) {
                html += '<span class="rad-diff-removed">' + token + '</span> ';
            } else {
                html += '<span class="rad-diff-unchanged">' + token + '</span> ';
            }
        });
        html += '</div>';

        // Assigned route with new tokens highlighted
        html += '<div class="rad-diff-row">';
        html += '<span class="rad-diff-label">' + PERTII18n.t('rad.clearance.assignedRoute') + ':</span>';
        assignedTokens.forEach(function(token) {
            var isAnchor = diff.anchors.indexOf(token) !== -1;
            var isNew = !isAnchor && filedTokens.indexOf(token) === -1;
            if (isAnchor) {
                html += '<span class="rad-diff-anchor">' + token + '</span> ';
            } else if (isNew) {
                html += '<span class="rad-diff-inserted">' + token + '</span> ';
            } else {
                html += '<span class="rad-diff-unchanged">' + token + '</span> ';
            }
        });
        html += '</div>';

        return html;
    }

    function renderSegmentRow(seg, idx) {
        var html = '<div class="rad-clearance-segment" data-idx="' + idx + '">';
        html += '<span class="rad-segment-badge">' + (seg.type === 'begin' ? 'BEGIN' : seg.type === 'end' ? 'END' : 'MID') + '</span>';

        if (seg.anchor_before) {
            html += ' after <span class="rad-diff-anchor">' + seg.anchor_before + '</span>';
        }
        html += ' <span class="rad-diff-inserted">' + seg.inserted.join(' ') + '</span>';
        if (seg.anchor_after) {
            html += ' <span class="rad-diff-anchor">' + seg.anchor_after + '</span>';
        }

        if (seg.removed.length > 0) {
            html += ' <span class="rad-segment-removed">(replaces: <span class="rad-diff-removed">' + seg.removed.join(' ') + '</span>)</span>';
        }

        html += '</div>';
        return html;
    }

    function getStructuredSegments() {
        if (!diffResult || !diffResult.segments) return [];
        return diffResult.segments.map(function(seg) {
            return {
                anchor_before: seg.anchor_before,
                anchor_after: seg.anchor_after,
                amendment: seg.inserted.join(' '),
                type: seg.type
            };
        });
    }

    // =========================================================================
    // Public API
    // =========================================================================

    function init(containerEl) {
        container = typeof containerEl === 'string'
            ? document.getElementById(containerEl)
            : containerEl;
    }

    function setRoutes(filed, assigned, cs, dest) {
        filedRoute = (filed || '').trim();
        assignedRoute = (assigned || '').trim();
        callsign = cs || callsign || '';
        clearanceLimit = dest || clearanceLimit || '';
        diffResult = computeDiff(filedRoute, assignedRoute);
        render();

        RADEventBus.emit('clearance:updated', {
            text: diffResult ? generatePhraseology(diffResult) : '',
            segments: getStructuredSegments(),
            closingPhrase: closingPhrase
        });
    }

    function reset() {
        filedRoute = '';
        assignedRoute = '';
        diffResult = null;
        callsign = '';
        clearanceLimit = '';
        closingPhrase = 'then_as_filed';
        if (container) container.innerHTML = '';
    }

    return {
        init: init,
        setRoutes: setRoutes,
        getDiff: function() { return diffResult; },
        getClearanceText: function() { return diffResult ? generatePhraseology(diffResult) : ''; },
        getSegments: getStructuredSegments,
        setClosingPhrase: function(phrase) { closingPhrase = phrase; render(); },
        reset: reset
    };
})();
```

- [ ] **Step 2: Verify module loads without errors**

Add `<script>` tag to `rad.php` (after `rad-amendment.js`, before `rad-monitoring.js`). Open RAD page in browser, check console for errors.

```javascript
console.log(typeof RADClearanceBuilder.setRoutes); // Expected: "function"
```

- [ ] **Step 3: Test LCS diff in browser console**

```javascript
RADClearanceBuilder.init(document.createElement('div'));
RADClearanceBuilder.setRoutes(
    'KATL KAJIN2 BRIGS J60 PHILA COLIN V276 DIXIE CAMRN LENDY6 KJFK',
    'KATL KAJIN2 BRIGS J80 SBY COLIN V16 MERIT CAMRN LENDY6 KJFK',
    'AAL123', 'KJFK'
);
console.log(JSON.stringify(RADClearanceBuilder.getDiff(), null, 2));
// Expected: anchors include BRIGS, COLIN, CAMRN; segments show J60 PHILA → J80 SBY and V276 DIXIE → V16 MERIT
console.log(RADClearanceBuilder.getClearanceText());
// Expected: "AAL123 CLEARED TO KJFK VIA AFTER BRIGS J80 SBY COLIN THEN AFTER COLIN V16 MERIT CAMRN THEN AS FILED"
```

- [ ] **Step 4: Commit**

```bash
git add assets/js/rad-clearance-builder.js
git commit -m "feat(rad): clearance builder module — LCS diff engine + phraseology generator"
```

---

### Task 4: Clearance Builder CSS

**Files:**
- Modify: `assets/css/rad.css`

- [ ] **Step 1: Add clearance builder styles**

Append to end of `rad.css`:

```css
/* =========================================================================
   Clearance Builder
   ========================================================================= */

.rad-clearance-diff {
    background: #0d1117;
    border: 1px solid #334;
    border-radius: 4px;
    padding: 10px 12px;
    margin-bottom: 10px;
    font-family: Inconsolata, 'Courier New', monospace;
    font-size: 0.82rem;
}
.rad-clearance-diff-label {
    color: #667;
    font-size: 0.72rem;
    text-transform: uppercase;
    margin-bottom: 6px;
    font-family: -apple-system, BlinkMacSystemFont, sans-serif;
}
.rad-diff-row {
    margin-bottom: 4px;
    line-height: 1.8;
}
.rad-diff-label {
    color: #89a;
    font-size: 0.75rem;
    display: inline-block;
    width: 100px;
    font-family: -apple-system, BlinkMacSystemFont, sans-serif;
}
.rad-diff-anchor {
    color: #5a5;
    font-weight: 600;
}
.rad-diff-removed {
    color: #f66;
    text-decoration: line-through;
}
.rad-diff-inserted {
    color: #4ECDC4;
    font-weight: 700;
}
.rad-diff-unchanged {
    color: #6f8;
}

.rad-clearance-segments {
    margin-bottom: 10px;
}
.rad-clearance-segment {
    background: #111;
    border: 1px solid #334;
    border-radius: 3px;
    padding: 6px 10px;
    margin-bottom: 4px;
    font-family: Inconsolata, 'Courier New', monospace;
    font-size: 0.82rem;
    color: #ccc;
}
.rad-segment-badge {
    background: #335;
    color: #aaf;
    font-size: 0.68rem;
    padding: 1px 6px;
    border-radius: 3px;
    font-family: -apple-system, BlinkMacSystemFont, sans-serif;
    text-transform: uppercase;
    font-weight: 600;
}
.rad-segment-removed {
    color: #667;
    font-size: 0.78rem;
}

.rad-clearance-closing {
    margin-bottom: 10px;
    color: #89a;
    font-size: 0.82rem;
}
.rad-clearance-closing select {
    background: #16213e;
    color: #ccc;
    border-color: #445;
    font-size: 0.82rem;
}

.rad-clearance-preview {
    background: #16213e;
    border: 1px solid #445;
    border-radius: 4px;
    padding: 10px 12px;
}
.rad-clearance-preview-label {
    color: #89a;
    font-size: 0.72rem;
    text-transform: uppercase;
    margin-bottom: 6px;
}
.rad-clearance-text {
    color: #4ECDC4;
    font-family: Inconsolata, 'Courier New', monospace;
    font-size: 0.88rem;
    font-weight: 600;
    line-height: 1.6;
    word-break: break-word;
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/rad.css
git commit -m "feat(rad): clearance builder CSS styles"
```

---

### Task 5: Integrate Clearance Builder into Edit Tab

**Files:**
- Modify: `rad.php` — add clearance builder container
- Modify: `assets/js/rad-amendment.js` — wire clearance builder to route changes
- Modify: `rad.php` — add script tag

- [ ] **Step 1: Add clearance builder container to rad.php Edit tab**

In `rad.php`, inside `pane-edit` after the amendment preview div (`#rad_amendment_preview`), add:

```html
<label data-i18n="rad.clearance.builder">Clearance Builder</label>
<div id="rad_clearance_builder" class="mb-2"></div>
```

Place this between `#rad_amendment_preview` and the TMI Association section (around line 191).

- [ ] **Step 2: Add script tag for clearance builder module**

In `rad.php`, add the script tag after `rad-amendment.js` and before `rad-monitoring.js` (between lines 265-266):

```html
<script src="assets/js/rad-clearance-builder.js<?= _v('assets/js/rad-clearance-builder.js') ?>"></script>
```

- [ ] **Step 3: Initialize clearance builder in rad-amendment.js**

In `rad-amendment.js`, in the `init()` function (around line 13), add after `bindEvents()`:

```javascript
// Initialize clearance builder
if (window.RADClearanceBuilder) {
    RADClearanceBuilder.init('rad_clearance_builder');
}
```

- [ ] **Step 4: Wire clearance builder to route changes in rad-amendment.js**

In `rad-amendment.js`, at the end of `updatePreview()` (around line 519, after `debouncedComputeDeltas()`), add:

```javascript
// Update clearance builder
if (window.RADClearanceBuilder && currentFlights.length > 0) {
    var flight = currentFlights[0];
    var original = flight.route || '';
    var hasPerFlight = Object.keys(perFlightRoutes).length > 0;
    var assigned = hasPerFlight ? (perFlightRoutes[flight.gufi] || '') : currentRoute;
    if (assigned) {
        RADClearanceBuilder.setRoutes(original, assigned, flight.callsign, flight.dest);
    } else {
        RADClearanceBuilder.reset();
    }
}
```

- [ ] **Step 5: Include clearance data in amendment payload**

In `rad-amendment.js`, in `buildPayload()` (around line 644), before `return payload`, add:

```javascript
// Attach clearance data if available
if (window.RADClearanceBuilder) {
    var clearanceText = RADClearanceBuilder.getClearanceText();
    var segments = RADClearanceBuilder.getSegments();
    if (clearanceText) {
        payload.clearance_text = clearanceText;
        payload.clearance_segments = JSON.stringify(segments);
        payload.closing_phrase = closingPhrase || 'then_as_filed';
    }
}
```

Note: `closingPhrase` isn't accessible from `buildPayload`'s scope. Instead, read it from the clearance builder module. Replace the above with:

```javascript
if (window.RADClearanceBuilder) {
    payload.clearance_text = RADClearanceBuilder.getClearanceText();
    payload.clearance_segments = JSON.stringify(RADClearanceBuilder.getSegments());
}
```

- [ ] **Step 6: Reset clearance builder in clearForm()**

In `rad-amendment.js`, in `clearForm()` (around line 662), add:

```javascript
if (window.RADClearanceBuilder) RADClearanceBuilder.reset();
```

- [ ] **Step 7: Verify end-to-end**

1. Open RAD page → Search tab → find flights → add to Detail → switch to Edit tab
2. Enter a route in the manual route textarea (or use substring replace)
3. Clearance builder panel should show auto-diff with colored tokens
4. Generated clearance preview should display ATC phraseology
5. Toggle closing phrase dropdown → phraseology updates live
6. Copy button should copy text to clipboard

- [ ] **Step 8: Commit**

```bash
git add rad.php assets/js/rad-amendment.js
git commit -m "feat(rad): integrate clearance builder into Edit tab with auto-diff"
```

---

### Task 6: Server-Side Clearance Storage in RADService

**Files:**
- Modify: `load/services/RADService.php`
- Modify: `api/rad/amendment.php`

- [ ] **Step 1: Accept clearance columns in createAmendment()**

In `RADService.php`, in `createAmendment()` (around line 231), update the INSERT SQL to include new columns:

Replace the existing INSERT SQL with:

```php
$sql = "INSERT INTO dbo.rad_amendments
    (gufi, callsign, origin, destination, original_route, assigned_route,
     status, tmi_reroute_id, tmi_id_label, delivery_channels, route_color,
     created_by, notes, clearance_text, clearance_segments, closing_phrase,
     expires_utc)
    OUTPUT INSERTED.id
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            DATEADD(HOUR, 6, SYSUTCDATETIME()))";

$params = [
    $gufi,
    $flight['callsign'],
    $flight['fp_dept_icao'],
    $flight['fp_dest_icao'],
    $flight['route'],
    $assigned_route,
    $status,
    $options['tmi_reroute_id'] ?? null,
    $options['tmi_id_label'] ?? null,
    $channels,
    $options['route_color'] ?? null,
    $options['created_by'] ?? null,
    $options['notes'] ?? null,
    $options['clearance_text'] ?? null,
    $options['clearance_segments'] ?? null,
    $options['closing_phrase'] ?? null,
];
```

- [ ] **Step 2: Pass clearance data through amendment.php**

In `api/rad/amendment.php`, in the create section (around line 72), add clearance fields to `$options`:

```php
$options = [
    'delivery_channels' => $channels,
    'tmi_reroute_id'    => $body['tmi_reroute_id'] ?? null,
    'tmi_id_label'      => $body['tmi_id'] ?? $body['tmi_id_label'] ?? null,
    'route_color'       => $body['route_color'] ?? null,
    'notes'             => $body['notes'] ?? null,
    'send'              => ($action === 'send') || !empty($body['send']),
    'created_by'        => (int)$cid,
    'clearance_text'    => $body['clearance_text'] ?? null,
    'clearance_segments' => $body['clearance_segments'] ?? null,
    'closing_phrase'    => $body['closing_phrase'] ?? null,
];
```

- [ ] **Step 3: Verify clearance data persists**

Create a test amendment via API, then query `rad_amendments` to confirm `clearance_text` and `clearance_segments` are populated.

- [ ] **Step 4: Commit**

```bash
git add load/services/RADService.php api/rad/amendment.php
git commit -m "feat(rad): persist clearance text and segments on amendment creation"
```

---

### Task 7: ISSUED State Transition

**Files:**
- Modify: `load/services/RADService.php` — add `issueAmendment()` method
- Modify: `api/rad/amendment.php` — add `action=issue` handler
- Modify: `api/rad/common.php` — add role-aware auth helper

- [ ] **Step 1: Add issueAmendment() to RADService**

In `RADService.php`, add after `cancelAmendment()` method (around line 358):

```php
/**
 * Mark amendment as ISSUED (ATC/VA has issued clearance to pilot).
 * TMU can also mark ISSUED to bypass ATC handoff.
 */
public function issueAmendment(int $id, int $issuer_cid, string $issuer_role = 'TMU'): array
{
    if (!$this->radTableExists()) return ['error' => 'RAD tables not yet deployed'];

    $amendment = $this->getAmendment($id);
    if (!$amendment) return ['error' => 'Amendment not found'];

    // Valid transitions: SENT → ISSUED, DLVD → ISSUED
    if (!in_array($amendment['status'], ['SENT', 'DLVD'])) {
        return ['error' => 'Only SENT/DLVD amendments can be marked as ISSUED'];
    }

    $sql = "UPDATE dbo.rad_amendments
            SET status = 'ISSUED', issued_by = ?, issued_utc = SYSUTCDATETIME(), actor_role = ?
            WHERE id = ?";
    $stmt = sqlsrv_query($this->conn_tmi, $sql, [$issuer_cid, $issuer_role, $id]);
    if ($stmt === false) return ['error' => 'Update failed'];
    sqlsrv_free_stmt($stmt);

    $this->logTransition($id, $amendment['status'], 'ISSUED',
        "Issued by $issuer_role (CID: $issuer_cid)", $issuer_cid);

    $this->broadcastWebSocket('rad:amendment_update', [
        'amendment_id' => $id,
        'gufi' => $amendment['gufi'],
        'status' => 'ISSUED',
        'issued_by' => $issuer_cid,
    ]);

    return ['success' => true, 'status' => 'ISSUED'];
}
```

- [ ] **Step 2: Add issue handler in amendment.php**

In `api/rad/amendment.php`, add after the `cancel` action block (around line 49) and before the `else` block:

```php
} elseif ($action === 'issue') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
    $role = $body['role'] ?? 'TMU';
    $result = $svc->issueAmendment($id, (int)$cid, $role);
    if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
    rad_respond_json(200, ['status' => 'ok', 'data' => $result]);
```

- [ ] **Step 3: Update compliance check to include ISSUED**

In `RADService.php`, in `runComplianceCheck()` (around line 493), update the query to include ISSUED:

```php
$sql = "SELECT id, gufi, assigned_route, status FROM dbo.rad_amendments
        WHERE status IN ('SENT', 'DLVD', 'ISSUED')";
```

And in the transition logic (around line 510), update to also transition ISSUED → ACPT/EXPR:

```php
if ($new_rrstat === 'C' && in_array($amend['status'], ['SENT', 'DLVD', 'ISSUED'])) {
    $new_status = 'ACPT';
    $transitioned++;
}
```

- [ ] **Step 4: Update getCompliance() to include ISSUED**

In `RADService.php`, in `getCompliance()` (around line 430), update the WHERE clause:

```php
$where = ["status IN ('SENT','DLVD','ISSUED')"];
```

- [ ] **Step 5: Verify ISSUED transition**

```bash
# Create a test amendment, then issue it
curl -X POST https://perti.vatcscc.org/api/rad/amendment.php \
  -d '{"action":"issue","id":1,"role":"TMU"}' \
  -H "Cookie: <session>"
```

- [ ] **Step 6: Commit**

```bash
git add load/services/RADService.php api/rad/amendment.php
git commit -m "feat(rad): ISSUED state transition + compliance check for ISSUED amendments"
```

---

### Task 8: Monitoring Tab — ISSUED Status Support

**Files:**
- Modify: `assets/js/rad-monitoring.js`

- [ ] **Step 1: Add ISSUED to status counts and badges**

In `rad-monitoring.js`, in `renderSummary()` (around line 192), add ISSUED counter:

```javascript
var counts = {
    total: amendments.length,
    draft: 0, sent: 0, dlvd: 0, issued: 0,
    acpt: 0, rjct: 0, expr: 0,
    tos_pending: 0, tos_resolved: 0, forced: 0
};
```

And in the counting loop (around line 203), add:

```javascript
else if (status === 'ISSUED') counts.issued++;
else if (status === 'TOS_PENDING') counts.tos_pending++;
else if (status === 'TOS_RESOLVED') counts.tos_resolved++;
else if (status === 'FORCED') counts.forced++;
```

Add the ISSUED card in the summary HTML (after DLVD):

```javascript
html += buildCard(PERTII18n.t('rad.status.ISSUED'), counts.issued, 'info');
```

- [ ] **Step 2: Add ISSUED badge class in getStatusBadge()**

In `rad-monitoring.js`, in `getStatusBadge()` (around line 314), add:

```javascript
else if (status === 'ISSUED') badgeClass = 'rad-badge-info';
else if (status === 'TOS_PENDING') badgeClass = 'rad-badge-warning';
else if (status === 'TOS_RESOLVED') badgeClass = 'rad-badge-primary';
else if (status === 'FORCED') badgeClass = 'rad-badge-danger';
```

- [ ] **Step 3: Add "Mark as Issued" action button**

In `rad-monitoring.js`, in `getActionButtons()` (around line 347), add before the DRAFT block:

```javascript
if (a.status === 'SENT' || a.status === 'DLVD') {
    html += '<button class="btn btn-sm btn-outline-info rad-btn-issue mr-1" data-id="' + a.id + '">' + PERTII18n.t('rad.actions.markIssued') + '</button>';
    html += '<button class="btn btn-sm btn-outline-primary rad-btn-resend mr-1" data-id="' + a.id + '">' + PERTII18n.t('rad.monitoring.resend') + '</button>';
}
```

(Remove the existing SENT/DLVD block that only had resend.)

- [ ] **Step 4: Bind issue button click handler**

In `rad-monitoring.js`, in `bindEvents()` (around line 41), add:

```javascript
$(document).on('click', '.rad-btn-issue', function() {
    var id = $(this).data('id');
    issueAmendment(id);
});
```

Add the `issueAmendment` function:

```javascript
function issueAmendment(id) {
    PERTIDialog.confirm(PERTII18n.t('rad.actions.issue') + '?')
        .then(function(result) {
            if (result.isConfirmed) {
                $.post('api/rad/amendment.php', { id: id, action: 'issue', role: 'TMU' })
                    .done(function(response) {
                        if (response.status === 'ok') {
                            PERTIDialog.success(PERTII18n.t('rad.status.ISSUED'));
                            refresh();
                        } else {
                            PERTIDialog.warning(response.message || PERTII18n.t('error.updateFailed'));
                        }
                    })
                    .fail(function() {
                        PERTIDialog.warning(PERTII18n.t('error.networkError'));
                    });
            }
        });
}
```

- [ ] **Step 5: Update active badge count to include ISSUED**

In `rad-monitoring.js`, in `updateBadge()` (around line 445), update the filter:

```javascript
var active = amendments.filter(function(a) {
    return a.status !== 'ACPT' && a.status !== 'RJCT' && a.status !== 'EXPR'
        && a.status !== 'TOS_RESOLVED' && a.status !== 'FORCED';
}).length;
```

- [ ] **Step 6: Update pending filter to include ISSUED**

In `rad-monitoring.js`, in `getFilteredAmendments()` (around line 391), update pending:

```javascript
if (currentFilter === 'pending') {
    filtered = filtered.filter(function(a) {
        return a.status === 'SENT' || a.status === 'DLVD' || a.status === 'ISSUED';
    });
}
```

And alerts to include TOS_PENDING:

```javascript
} else if (currentFilter === 'alerts') {
    filtered = filtered.filter(function(a) {
        return a.status === 'EXPR' || a.status === 'RJCT' || a.status === 'TOS_PENDING';
    });
}
```

- [ ] **Step 7: Verify**

Open RAD page → Monitoring tab → verify ISSUED status shows as light blue badge, "Mark as Issued" button appears on SENT/DLVD rows.

- [ ] **Step 8: Commit**

```bash
git add assets/js/rad-monitoring.js
git commit -m "feat(rad): monitoring tab support for ISSUED + V2 status badges"
```

---

### Task 9: Include Clearance Text in Delivery Messages

**Files:**
- Modify: `load/services/RADService.php`

- [ ] **Step 1: Use clearance_text in triggerDelivery()**

In `RADService.php`, in `triggerDelivery()` (around line 776), update message construction:

```php
private function triggerDelivery(int $amendment_id, array $flight, string $route, string $channels, array $options): void
{
    // Use clearance text if available, otherwise build simple message
    $tmi_label = $options['tmi_id_label'] ?? 'ATC';
    $clearance_text = $options['clearance_text'] ?? null;

    if ($clearance_text) {
        $message = $clearance_text;
    } else {
        $message = "ROUTE AMENDMENT: {$flight['callsign']} CLEARED $route PER $tmi_label";
    }

    // ... rest of delivery logic unchanged
```

- [ ] **Step 2: Include clearance in WebSocket broadcast**

In the same method, update the WebSocket broadcast data:

```php
if (strpos($channels, 'SWIM') !== false) {
    $this->broadcastWebSocket('rad:amendment_update', [
        'amendment_id' => $amendment_id,
        'gufi' => $flight['gufi'],
        'callsign' => $flight['callsign'],
        'status' => 'SENT',
        'assigned_route' => $route,
        'clearance_text' => $clearance_text,
        'clearance_segments' => $options['clearance_segments'] ?? null,
    ]);
}
```

- [ ] **Step 3: Commit**

```bash
git add load/services/RADService.php
git commit -m "feat(rad): use clearance phraseology in CPDLC/WS delivery messages"
```

---

### Task 10: End-to-End Verification

- [ ] **Step 1: Verify full Phase 1 workflow**

1. Open RAD page (`/rad.php`)
2. **Search** → find flights for KATL-KJFK → add 2-3 to Detail
3. **Edit** tab → type a modified route in textarea (change one airway)
4. **Clearance Builder** panel shows:
   - Filed vs assigned diff with colored tokens (green anchors, red removed, teal inserted)
   - Segment breakdown (MID/BEGIN/END)
   - Generated phraseology: "AAL123 CLEARED TO KJFK VIA AFTER BRIGS..."
   - Closing phrase toggle works
   - Copy button copies to clipboard
5. **Send Amendment** → confirm dialog → amendment created with clearance data
6. **Monitoring** tab → amendment appears with SENT status
7. Click **Mark as Issued** → status changes to ISSUED (blue badge)
8. Verify compliance polling works with ISSUED status

- [ ] **Step 2: Create PR branch and commit**

```bash
git checkout -b feat/rad-v2-phase1
# (all commits already on this branch if started fresh)
git push -u origin feat/rad-v2-phase1
```

---

## Phase 2 & Phase 3

Phase 2 (Multi-Actor Roles) and Phase 3 (TOS Workflow) are documented in separate plan files:
- `docs/superpowers/plans/2026-04-03-rad-amendment-phase2-multi-actor.md`
- `docs/superpowers/plans/2026-04-03-rad-amendment-phase3-tos-workflow.md`

Phase 1 must be deployed before Phase 2. Phase 2 must be deployed before Phase 3.
