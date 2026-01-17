# TMI Status Workflow

**Version:** 1.0  
**Date:** January 17, 2026

---

## 1. Entry States

### 1.1 State Diagram

```
                          ┌─────────────────────────────────────────────┐
                          │                                             │
                          ▼                                             │
 ┌────────┐         ┌──────────┐         ┌──────────┐         ┌────────┴───┐
 │ DRAFT  │────────▶│ PROPOSED │────────▶│ APPROVED │────────▶│ SCHEDULED  │
 └────────┘         └──────────┘         └──────────┘         └────────────┘
      │                   │                    │                     │
      │                   │                    │                     │
      │                   │                    │                     ▼
      │                   │                    │              ┌────────────┐
      │                   │                    └─────────────▶│   ACTIVE   │
      │                   │                                   └────────────┘
      │                   │                                         │
      │                   │         ┌───────────────────────────────┼───────────────────┐
      │                   │         │                               │                   │
      │                   ▼         ▼                               ▼                   ▼
      │              ┌──────────────────┐                    ┌─────────────┐    ┌─────────────┐
      └─────────────▶│    CANCELLED     │                    │   EXPIRED   │    │ SUPERSEDED  │
                     └──────────────────┘                    └─────────────┘    └─────────────┘
```

### 1.2 State Definitions

| State | Description | Entry Allowed | Exit Allowed |
|-------|-------------|---------------|--------------|
| **DRAFT** | Initial state, not yet submitted | Yes | PROPOSED, CANCELLED |
| **PROPOSED** | Submitted for coordinator review | No | APPROVED, CANCELLED |
| **APPROVED** | Coordinator approved, awaiting activation | No | SCHEDULED, ACTIVE, CANCELLED |
| **SCHEDULED** | Approved and scheduled for future valid_from | No | ACTIVE, CANCELLED |
| **ACTIVE** | Currently in effect | No | EXPIRED, CANCELLED, SUPERSEDED |
| **EXPIRED** | valid_until time has passed | No | None (terminal) |
| **CANCELLED** | Manually cancelled before completion | No | None (terminal) |
| **SUPERSEDED** | Replaced by a newer version | No | None (terminal) |

---

## 2. State Transitions

### 2.1 Allowed Transitions

| From | To | Trigger | Notes |
|------|-----|---------|-------|
| DRAFT | PROPOSED | User submits | Requires validation pass |
| DRAFT | CANCELLED | User cancels | Optional cancel_reason |
| PROPOSED | APPROVED | Coordinator approves | Sets approved_by, approved_at |
| PROPOSED | CANCELLED | Coordinator rejects | Requires cancel_reason |
| APPROVED | SCHEDULED | valid_from > NOW() | Auto-set by system |
| APPROVED | ACTIVE | valid_from ≤ NOW() | Auto-set by system |
| APPROVED | CANCELLED | User cancels | Before activation |
| SCHEDULED | ACTIVE | valid_from reached | Auto by scheduler |
| SCHEDULED | CANCELLED | User cancels | Before activation |
| ACTIVE | EXPIRED | valid_until reached | Auto by scheduler |
| ACTIVE | CANCELLED | User cancels | Sets cancelled_by, cancelled_at |
| ACTIVE | SUPERSEDED | New version created | Sets supersedes_entry_id |

### 2.2 Forbidden Transitions

- Any state → DRAFT (cannot un-submit)
- Terminal states → Any state (EXPIRED, CANCELLED, SUPERSEDED are final)
- SCHEDULED → EXPIRED (must pass through ACTIVE first, or cancel)

---

## 3. Auto-State Determination

### 3.1 For Discord Direct Entry

When an entry comes from Discord (raw text), the system auto-determines state:

```php
function determineInitialState(?DateTime $validFrom, ?DateTime $validUntil): string
{
    $now = new DateTime('now', new DateTimeZone('UTC'));
    
    // No time specified = immediate and indefinite = ACTIVE
    if ($validFrom === null && $validUntil === null) {
        return 'ACTIVE';
    }
    
    // Has end time but no start = assume started already
    if ($validFrom === null && $validUntil !== null) {
        return $validUntil > $now ? 'ACTIVE' : 'EXPIRED';
    }
    
    // Has start time
    if ($validFrom > $now) {
        return 'SCHEDULED';
    }
    
    // Start time has passed
    if ($validUntil === null || $validUntil > $now) {
        return 'ACTIVE';
    }
    
    // Both times passed
    return 'EXPIRED';
}
```

### 3.2 For PERTI Website Entry

Users can explicitly choose:
- **Save as Draft** → DRAFT
- **Submit for Review** → PROPOSED
- **Submit (Auto-activate)** → APPROVED → ACTIVE/SCHEDULED

---

## 4. Scheduler Logic

The `sp_ExpireOldEntries()` stored procedure runs every minute:

```sql
-- Expire active entries past their valid_until
UPDATE tmi_entries
SET status = 'EXPIRED'
WHERE status = 'ACTIVE'
  AND valid_until IS NOT NULL
  AND valid_until < NOW();

-- Activate scheduled entries when valid_from is reached
UPDATE tmi_entries
SET status = 'ACTIVE'
WHERE status = 'SCHEDULED'
  AND valid_from IS NOT NULL
  AND valid_from <= NOW()
  AND (valid_until IS NULL OR valid_until > NOW());
```

---

## 5. Cancellation

### 5.1 Who Can Cancel

| State | Who Can Cancel |
|-------|----------------|
| DRAFT | Creator only |
| PROPOSED | Creator or Coordinator |
| APPROVED | Creator or Coordinator |
| SCHEDULED | Creator, Coordinator, or DCC |
| ACTIVE | DCC (Coordinator authority required) |

### 5.2 Cancellation Fields

When cancelled, these fields are set:

| Field | Value |
|-------|-------|
| status | 'CANCELLED' |
| cancelled_by | CID or Discord user ID |
| cancelled_at | Current UTC timestamp |
| cancel_reason | Required text explanation |

---

## 6. Supersession

### 6.1 When to Supersede

An entry is superseded (not cancelled) when:
- A revision/update is issued (same TMI, new parameters)
- A conflicting entry takes precedence
- The entry is replaced by a different type (e.g., MIT → GDP)

### 6.2 Supersession Process

```sql
-- Mark old entry as superseded
UPDATE tmi_entries
SET status = 'SUPERSEDED'
WHERE entry_id = @old_entry_id;

-- Create new entry with reference
INSERT INTO tmi_entries (
    ...,
    supersedes_entry_id = @old_entry_id
) VALUES (...);
```

---

## 7. Advisory States

Advisories follow the same workflow with additional considerations:

### 7.1 Proposed vs Actual

| is_proposed | Meaning |
|-------------|---------|
| TRUE | Proposed/Anticipated advisory |
| FALSE | Actual/Confirmed advisory |

### 7.2 Revision Tracking

Each advisory has a `revision_number`:
- Initial: 1
- First revision: 2
- etc.

When revised:
1. Original marked SUPERSEDED
2. New advisory created with `revision_number + 1`
3. `supersedes_advisory_id` points to original

---

## 8. API Status Changes

### 8.1 Endpoints

```
POST /api/tmi/entries/{id}/cancel
{
    "cancel_reason": "Weather improved, restriction no longer needed"
}

POST /api/tmi/entries/{id}/approve
{
    "approved_by": "1234567"
}

POST /api/tmi/entries/{id}/activate
{
    // Manually activate a scheduled entry
}
```

### 8.2 Response

```json
{
    "success": true,
    "entry_id": 123,
    "previous_status": "ACTIVE",
    "new_status": "CANCELLED",
    "audit_log_id": 456
}
```

---

## 9. Audit Trail

Every state change is logged to `tmi_audit_log`:

```sql
INSERT INTO tmi_audit_log (
    entity_type,    -- 'ENTRY' or 'ADVISORY'
    entity_id,      -- entry_id or advisory_id
    entity_guid,    -- GUID for reference
    action,         -- 'STATUS_CHANGE'
    action_detail,  -- 'ACTIVE→CANCELLED'
    field_name,     -- 'status'
    old_value,      -- 'ACTIVE'
    new_value,      -- 'CANCELLED'
    source_type,    -- 'PERTI', 'DISCORD', 'API', 'SCHEDULER'
    actor_id,       -- Who made the change
    actor_name,     -- Display name
    logged_at       -- Timestamp with milliseconds
);
```

---

*Last Updated: January 17, 2026*
