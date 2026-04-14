# PERTI Homepage Plan Listing Enhancement

**Date:** 2026-02-17
**Branch:** `feature/perti-plans`
**Worktree:** `C:/Temp/perti-worktrees/perti-plans`

## Problem

The PERTI homepage plan listing is a flat table sorted by `event_date DESC` with no temporal awareness. Past, present, and future plans look identical. There is no overlap or duplicate detection. Users must scan the entire list to find what's relevant this week.

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Layout | Single table with row styling + section header separators | Preserves familiar layout while adding clear grouping |
| Row styling | 4px left border color + status badge | Subtle, professional, doesn't clash with existing OpLevel coloring |
| Architecture | Server-side PHP (Approach A) | Matches existing `plans.l.php` HTML-rendering pattern, reliable UTC handling |
| Past events | Show 15 most recent, collapse rest | Keeps focus on current/upcoming while providing recent context |
| Overlap display | Inline text listing conflicts under event name | Visible and informative without requiring hover/click |
| Duplicate detection | Same `event_date` + fuzzy name similarity (>80%) | Catches copy-paste errors without over-flagging intentional same-day events |

## Temporal Classification

Every plan is classified into exactly one status based on current UTC time:

| Status | Condition | Left Border | Badge |
|--------|-----------|-------------|-------|
| **LIVE** | `now >= start_datetime AND now <= end_datetime` | `#28a745` (green) | `LIVE` green pulse badge |
| **This Week** | `start_datetime` within current Mon-Sun UTC week, not live | `#17a2b8` (info blue) | `THIS WEEK` info badge |
| **Upcoming** | `start_datetime > now`, not this week | `#6c757d` (gray-blue) | none |
| **Past** | `end_datetime < now` (or inferred end if no end date) | transparent | `PAST` muted badge |

### DateTime Construction

- Start: `event_date` + `event_start` (HHMM → HH:MM UTC)
- End: `event_end_date` + `event_end_time` (HHMM → HH:MM UTC)
- If no end date/time provided: treat end as `start + 6 hours` (reasonable VATSIM event default)

### Week Boundary

"This week" = Monday 0000Z through Sunday 2359Z of the current UTC week.

## Table Structure

```
┌─────────────────────────────────────────────────────┐
│ HAPPENING NOW (2)                                   │  section header
├─────────────────────────────────────────────────────┤
│ ▌ FNO KJFK   2026-02-17  2300Z  ...  [LIVE]       │  green left border
│ ▌ CTP West   2026-02-17  2200Z  ...  [LIVE]       │
├─────────────────────────────────────────────────────┤
│ THIS WEEK (3)                                       │  section header
├─────────────────────────────────────────────────────┤
│ ▌ FNO KATL   2026-02-20  2300Z  ...               │  info blue left border
│ ▌ Mil Ops    2026-02-21  1400Z  ...               │
├─────────────────────────────────────────────────────┤
│ UPCOMING (5)                                        │  section header
├─────────────────────────────────────────────────────┤
│   CTP East   2026-03-01  1900Z  ...               │
│   ...                                               │
├─────────────────────────────────────────────────────┤
│ PAST EVENTS (47) - showing 15                       │  section header
├─────────────────────────────────────────────────────┤
│   FNO KJFK   2026-02-10  2300Z  ...  [PAST]       │  dimmed (opacity 0.7)
│   ... (14 more visible)                             │
│   [Show all 47 past events]                         │  expand link
└─────────────────────────────────────────────────────┘
```

### Sort Order Within Sections

- **Live**: start time ASC (earliest first)
- **This Week**: start time ASC
- **Upcoming**: start time ASC
- **Past**: start time DESC (most recent first), show 15, collapse rest

### Empty Sections

Sections with zero plans are hidden entirely (no empty header).

## Overlap Detection

After classification, PHP scans all plans for time-period overlaps: two plans overlap if their `[start, end]` intervals intersect (`A.start < B.end AND B.start < A.end`).

Display as inline text under the event name:

```html
<small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Overlaps with: CTP West (2200Z-0400Z)</small>
```

Multiple overlaps shown comma-separated. Only non-past plans are checked for overlaps (no value in flagging historical overlaps).

## Duplicate Detection

PHP checks for potential duplicates among non-past plans: same `event_date` AND fuzzy name similarity using PHP's `similar_text()` with >80% threshold. Names are normalized before comparison (lowercase, strip punctuation/extra whitespace).

Display as inline info text:

```html
<small class="text-info"><i class="fas fa-clone"></i> Possible duplicate of: FNO KJFK (#142)</small>
```

## CSS

New classes in `perti_theme.css`:

```css
/* Plan temporal status - left border indicators */
.plan-row-live     { border-left: 4px solid #28a745; }
.plan-row-week     { border-left: 4px solid #17a2b8; }
.plan-row-upcoming { border-left: 4px solid #6c757d; }
.plan-row-past     { opacity: 0.7; }

/* Section header rows */
.plan-section-header td {
  background: #f8f9fa;
  font-weight: 600;
  font-size: 0.9em;
  padding: 8px 12px;
  border-bottom: 2px solid #dee2e6;
  color: #495057;
}
.plan-section-header.section-live td    { border-left: 4px solid #28a745; }
.plan-section-header.section-week td    { border-left: 4px solid #17a2b8; }
.plan-section-header.section-upcoming td { border-left: 4px solid #6c757d; }
.plan-section-header.section-past td    { border-left: 4px solid #adb5bd; }

/* LIVE badge pulse animation */
@keyframes pulse-live {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
.badge-live {
  background-color: #28a745;
  color: #fff;
  animation: pulse-live 2s infinite;
}
```

## JavaScript Changes (index.php)

Minimal JS addition for past section expand/collapse:

```javascript
// Past events expand/collapse
$(document).on('click', '.plan-past-toggle', function(e) {
    e.preventDefault();
    $('.plan-row-past-hidden').toggle();
    var total = $(this).data('total');
    $(this).text(
        $('.plan-row-past-hidden').is(':visible')
            ? PERTII18n.t('home.showLess')
            : PERTII18n.t('home.showAllPast', { count: total })
    );
});
```

## i18n Keys

New keys in `assets/locales/en-US.json` under `home`:

```json
{
  "section": {
    "happeningNow": "Happening Now",
    "thisWeek": "This Week",
    "upcoming": "Upcoming",
    "pastEvents": "Past Events",
    "showing": "showing {count}",
    "showAllPast": "Show all {count} past events",
    "showLess": "Show less"
  },
  "status": {
    "live": "LIVE",
    "thisWeek": "THIS WEEK",
    "past": "PAST"
  },
  "overlap": {
    "overlapsWith": "Overlaps with: {plans}"
  },
  "duplicate": {
    "possibleDuplicate": "Possible duplicate of: {name} (#{id})"
  }
}
```

Note: Since the section headers and status labels are rendered server-side by PHP, these keys will be used via the PHP `__()` i18n function, not JS. The JS keys (`showAllPast`, `showLess`) are for the client-side expand/collapse only.

## Files Modified

| File | Change | Scope |
|------|--------|-------|
| `api/data/plans.l.php` | Major rewrite: classification, sorting, section headers, overlap/duplicate detection | ~150 lines |
| `assets/css/perti_theme.css` | Add plan status CSS classes | ~30 lines |
| `index.php` | Add past-section expand/collapse JS | ~10 lines |
| `assets/locales/en-US.json` | Add i18n keys for sections, statuses, overlap/duplicate text | ~15 keys |

## Non-Goals

- No changes to the create/edit plan modals
- No changes to the plan data API (`api/mgt/perti/`)
- No filtering/search functionality (could be a future enhancement)
- No changes to plan detail pages (`plan.php`, `sheet.php`, `review.php`)
