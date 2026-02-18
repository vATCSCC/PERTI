# PERTI Homepage Plan Listing Enhancement — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add temporal grouping (live/this week/upcoming/past), overlap detection, and duplicate detection to the PERTI homepage plan listing table.

**Architecture:** Server-side PHP in `api/data/plans.l.php` classifies plans, detects overlaps/duplicates, renders section-header rows and styled plan rows. Minimal JS added to `index.php` for past-section expand/collapse. CSS classes in `perti_theme.css` handle left-border colors, section headers, and LIVE badge pulse.

**Tech Stack:** PHP 8.2, MySQL (perti_site), jQuery 2.2.4, Bootstrap 4.5, CSS3 animations

**Worktree:** `C:/Temp/perti-worktrees/perti-plans` on branch `feature/perti-plans`

**No automated tests** — this project has manual testing only via the live site.

---

### Task 1: Add i18n keys to en-US.json

**Files:**
- Modify: `assets/locales/en-US.json` (lines 4938-4939, inside the `"home"` object before its closing `}`)

**Step 1: Add new keys**

Inside the `"home"` object in `en-US.json`, before the closing `}` of the `"error"` sibling (line 4938), add the following new sibling keys after the `"error"` block:

```json
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
```

These go right after the `"error": { ... }` block (line 4938) and before the closing `}` of `"home"` (line 4939). Add a comma after the `"error"` block's closing `}`.

**Step 2: Commit**

```bash
cd C:/Temp/perti-worktrees/perti-plans
git add assets/locales/en-US.json
git commit -m "feat: add i18n keys for plan listing temporal sections and detection"
```

---

### Task 2: Add CSS classes for plan status styling

**Files:**
- Modify: `assets/css/perti_theme.css` (append after line 483, the end of the file)

**Step 1: Append CSS**

Add the following CSS at the end of `perti_theme.css` (after line 483):

```css

/* ===========================================
   PERTI Plan Listing - Temporal Status Styles
   =========================================== */

/* Row left-border indicators by temporal status */
.plan-row-live {
  border-left: 4px solid #28a745;
}
.plan-row-week {
  border-left: 4px solid #17a2b8;
}
.plan-row-upcoming {
  border-left: 4px solid #6c757d;
}
.plan-row-past {
  opacity: 0.7;
}
.plan-row-past-hidden {
  opacity: 0.7;
  display: none;
}

/* Section header rows (full-width dividers within the table) */
.plan-section-header td {
  background: var(--gray-100, #f8f9fa);
  font-weight: 600;
  font-size: 0.9em;
  padding: 8px 12px;
  border-bottom: 2px solid var(--gray-300, #dee2e6);
  color: var(--gray-700, #495057);
}
.plan-section-header.section-live td {
  border-left: 4px solid #28a745;
}
.plan-section-header.section-week td {
  border-left: 4px solid #17a2b8;
}
.plan-section-header.section-upcoming td {
  border-left: 4px solid #6c757d;
}
.plan-section-header.section-past td {
  border-left: 4px solid var(--gray-500, #adb5bd);
}

/* LIVE badge with pulse animation */
@keyframes pulse-live {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
.badge-live {
  background-color: #28a745;
  color: #fff;
  animation: pulse-live 2s infinite;
}

/* Past toggle link styling */
.plan-past-toggle {
  cursor: pointer;
  font-size: 0.85em;
}
```

**Step 2: Commit**

```bash
cd C:/Temp/perti-worktrees/perti-plans
git add assets/css/perti_theme.css
git commit -m "feat: add CSS for plan listing temporal status indicators"
```

---

### Task 3: Rewrite plans.l.php with temporal classification, overlap, and duplicate detection

This is the main task. The file `api/data/plans.l.php` needs a complete rewrite of its rendering logic (lines 65-149) while preserving the preamble (lines 1-63: session, db connection, org/hotline setup).

**Files:**
- Modify: `api/data/plans.l.php` (replace lines 65-151 entirely)

**Step 1: Replace the rendering logic**

Replace everything from line 65 (`while ($data = mysqli_fetch_array($query)) {`) through line 151 (the closing `?>`) with the following code. This code:

1. Reads all plans into an array
2. Computes `start_dt` and `end_dt` as Unix timestamps for each plan
3. Classifies each plan as `live`, `week`, `upcoming`, or `past`
4. Detects overlaps among non-past plans
5. Detects fuzzy-name duplicates among plans on the same date
6. Renders section headers and styled rows

```php
// Collect all plans into array for classification
$plans = [];
while ($data = mysqli_fetch_array($query)) {
    $plans[] = $data;
}

$now = time();
$now_utc = gmdate('Y-m-d H:i:s');

// Compute week boundaries (Mon 00:00Z - Sun 23:59Z)
$week_start = gmmktime(0, 0, 0, (int)gmdate('n'), (int)gmdate('j') - ((int)gmdate('N') - 1), (int)gmdate('Y'));
$week_end = $week_start + (7 * 86400) - 1;

// Helper: build UTC timestamp from date string + HHMM time string
function build_utc_ts($date_str, $time_str) {
    if (empty($date_str)) return null;
    $hh = '00'; $mm = '00';
    if (!empty($time_str) && strlen($time_str) >= 3) {
        $time_str = str_pad($time_str, 4, '0', STR_PAD_LEFT);
        $hh = substr($time_str, 0, 2);
        $mm = substr($time_str, 2, 2);
    }
    $dt = $date_str . ' ' . $hh . ':' . $mm . ':00';
    return strtotime($dt . ' UTC');
}

// Classify each plan
$sections = ['live' => [], 'week' => [], 'upcoming' => [], 'past' => []];

foreach ($plans as &$p) {
    $start_ts = build_utc_ts($p['event_date'], $p['event_start']);
    $end_date = $p['event_end_date'] ?? '';
    $end_time = $p['event_end_time'] ?? '';
    if (!empty($end_date)) {
        $end_ts = build_utc_ts($end_date, $end_time);
        if ($end_ts && !empty($end_time)) {
            // end_time is set, use as-is
        } elseif ($end_ts) {
            // end_date but no end_time: assume end of that day
            $end_ts = strtotime($end_date . ' 23:59:59 UTC');
        }
    } else {
        // No end date: default to start + 6 hours
        $end_ts = $start_ts ? $start_ts + (6 * 3600) : null;
    }
    $p['_start_ts'] = $start_ts;
    $p['_end_ts'] = $end_ts;

    if ($start_ts && $end_ts && $now >= $start_ts && $now <= $end_ts) {
        $p['_status'] = 'live';
        $sections['live'][] = &$p;
    } elseif ($end_ts && $now > $end_ts) {
        $p['_status'] = 'past';
        $sections['past'][] = &$p;
    } elseif ($start_ts && $now < $start_ts && $start_ts >= $week_start && $start_ts <= $week_end) {
        $p['_status'] = 'week';
        $sections['week'][] = &$p;
    } elseif ($start_ts && $now < $start_ts) {
        $p['_status'] = 'upcoming';
        $sections['upcoming'][] = &$p;
    } else {
        // Fallback: no valid dates, treat as past
        $p['_status'] = 'past';
        $sections['past'][] = &$p;
    }
}
unset($p);

// Sort within sections
usort($sections['live'], fn($a, $b) => ($a['_start_ts'] ?? 0) - ($b['_start_ts'] ?? 0));
usort($sections['week'], fn($a, $b) => ($a['_start_ts'] ?? 0) - ($b['_start_ts'] ?? 0));
usort($sections['upcoming'], fn($a, $b) => ($a['_start_ts'] ?? 0) - ($b['_start_ts'] ?? 0));
usort($sections['past'], fn($a, $b) => ($b['_start_ts'] ?? 0) - ($a['_start_ts'] ?? 0)); // DESC

// Overlap detection (non-past plans only)
$non_past = array_merge($sections['live'], $sections['week'], $sections['upcoming']);
$overlaps = []; // plan id => [list of overlapping plan names with times]
for ($i = 0; $i < count($non_past); $i++) {
    for ($j = $i + 1; $j < count($non_past); $j++) {
        $a = $non_past[$i];
        $b = $non_past[$j];
        if ($a['_start_ts'] && $a['_end_ts'] && $b['_start_ts'] && $b['_end_ts']) {
            if ($a['_start_ts'] < $b['_end_ts'] && $b['_start_ts'] < $a['_end_ts']) {
                $a_time_label = gmdate('Hi', $a['_start_ts']) . 'Z-' . gmdate('Hi', $a['_end_ts']) . 'Z';
                $b_time_label = gmdate('Hi', $b['_start_ts']) . 'Z-' . gmdate('Hi', $b['_end_ts']) . 'Z';
                $overlaps[$a['id']][] = htmlspecialchars($b['event_name']) . ' (' . $b_time_label . ')';
                $overlaps[$b['id']][] = htmlspecialchars($a['event_name']) . ' (' . $a_time_label . ')';
            }
        }
    }
}

// Duplicate detection (same event_date + fuzzy name > 80%)
$duplicates = []; // plan id => [list of duplicate plan name + id]
function normalize_name($name) {
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9\s]/', '', $name);
    return preg_replace('/\s+/', ' ', $name);
}
for ($i = 0; $i < count($plans); $i++) {
    for ($j = $i + 1; $j < count($plans); $j++) {
        if ($plans[$i]['event_date'] === $plans[$j]['event_date']) {
            $name_a = normalize_name($plans[$i]['event_name']);
            $name_b = normalize_name($plans[$j]['event_name']);
            similar_text($name_a, $name_b, $pct);
            if ($pct > 80) {
                $duplicates[$plans[$i]['id']][] = ['name' => $plans[$j]['event_name'], 'id' => $plans[$j]['id']];
                $duplicates[$plans[$j]['id']][] = ['name' => $plans[$i]['event_name'], 'id' => $plans[$i]['id']];
            }
        }
    }
}

// Render helper: emit a single plan row
function render_plan_row($data, $perm, $hotline_badges, $org_display, $overlaps, $duplicates, $status) {
    $event_end_date = $data['event_end_date'] ?? '';
    $event_end_time = $data['event_end_time'] ?? '';
    $hotline = $data['hotline'] ?? '';
    $hotline_badge = $hotline_badges[$hotline] ?? ($hotline !== '' ? substr($hotline, 0, 1) : 'UNK');

    // Region icons
    $icon_prefix = '';
    if ($hotline !== '' && strpos($hotline, 'Canada') === 0) {
        $icon_prefix = '<img src="https://flagcdn.com/20x15/ca.png" width="20" height="15" alt="" style="vertical-align: middle; margin-right: 4px;">';
    } elseif ($hotline === 'Mexico') {
        $icon_prefix = '<img src="https://flagcdn.com/20x15/mx.png" width="20" height="15" alt="" style="vertical-align: middle; margin-right: 4px;">';
    } elseif ($hotline === 'Caribbean') {
        $icon_prefix = '<i class="fas fa-tree fa-sm text-success" style="margin-right: 4px;"></i>';
    }

    // Scope badge
    $plan_org = $data['org_code'] ?? null;
    if ($plan_org === null) {
        $scope_badge = '<i class="fas fa-globe-americas text-muted fa-sm" data-toggle="tooltip" title="Global" style="margin-right: 4px;"></i>';
    } else {
        $scope_label = $org_display[$plan_org] ?? strtoupper($plan_org);
        $scope_badge = '<span class="badge badge-dark" data-toggle="tooltip" title="'.$scope_label.' Only" style="font-size: 0.65em; margin-right: 4px;">'.$scope_label.'</span>';
    }

    // Status badge
    $status_badge = '';
    if ($status === 'live') {
        $status_badge = ' <span class="badge badge-live">' . __('home.status.live') . '</span>';
    } elseif ($status === 'week') {
        $status_badge = ' <span class="badge badge-info">' . __('home.status.thisWeek') . '</span>';
    } elseif ($status === 'past') {
        $status_badge = ' <span class="badge badge-secondary" style="font-size: 0.65em;">' . __('home.status.past') . '</span>';
    }

    // Row CSS class
    $row_class = 'plan-row-' . $status;

    echo '<tr class="'.$row_class.'">';

    // Event name cell with overlap/duplicate annotations
    echo '<td>'.$scope_badge.$icon_prefix.htmlspecialchars($data['event_name']).' <span class="badge badge-secondary" data-toggle="tooltip" title="'.$hotline.' Hotline">'.$hotline_badge.'</span>'.$status_badge;

    // Overlap warning
    if (!empty($overlaps[$data['id']])) {
        echo '<br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> ' . __('home.overlap.overlapsWith', ['plans' => implode(', ', $overlaps[$data['id']])]) . '</small>';
    }
    // Duplicate info
    if (!empty($duplicates[$data['id']])) {
        foreach ($duplicates[$data['id']] as $dup) {
            echo '<br><small class="text-info"><i class="fas fa-clone"></i> ' . __('home.duplicate.possibleDuplicate', ['name' => htmlspecialchars($dup['name']), 'id' => $dup['id']]) . '</small>';
        }
    }
    echo '</td>';

    // Start Date
    echo '<td class="text-center">'.$data['event_date'].'</td>';

    // Start Time
    echo '<td class="text-center">'.$data['event_start'].'Z</td>';

    // End Date
    if (!empty($event_end_date)) {
        echo '<td class="text-center">'.$event_end_date.'</td>';
    } else {
        echo '<td class="text-center text-muted">&mdash;</td>';
    }

    // End Time
    if (!empty($event_end_time)) {
        echo '<td class="text-center">'.$event_end_time.'Z</td>';
    } else {
        echo '<td class="text-center text-muted">&mdash;</td>';
    }

    // OpLevel
    $ol = (int)$data['oplevel'];
    $ol_classes = [1 => 'text-dark', 2 => 'text-success', 3 => 'text-warning', 4 => 'text-danger'];
    $ol_labels = [1 => 'Steady State', 2 => 'Localized Impact', 3 => 'Regional Impact', 4 => 'NAS-Wide Impact'];
    $ol_class = $ol_classes[$ol] ?? 'text-dark';
    $ol_label = $ol_labels[$ol] ?? '';
    echo '<td class="'.$ol_class.' text-center">'.$ol.' - '.$ol_label.'</td>';

    // Last Updated
    echo '<td class="text-center">'.$data['updated_at'].'</td>';

    // Actions
    echo '<td><center>';
    echo '<a href="plan?'.$data['id'].'" data-toggle="tooltip" title="View PERTI Plan"><span class="badge badge-primary"><i class="fas fa-eye"></i> View</span></a>';
    echo ' ';
    echo '<a href="data?'.$data['id'].'" data-toggle="tooltip" title="View PERTI Staffing Data"><span class="badge badge-success"><i class="fas fa-table"></i> Data</span></a>';
    echo ' ';
    echo '<a href="review?'.$data['id'].'" data-toggle="tooltip" title="View Traffic Management Review"><span class="badge badge-info"><i class="fas fa-magnifying-glass"></i> TMR</span></a>';

    if ($perm == true) {
        echo ' ';
        echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit PERTI Plan"><span class="badge badge-warning" data-toggle="modal" data-target="#editplanModal" data-id="'.$data['id'].'" data-event_name="'.htmlspecialchars($data['event_name']).'" data-event_date="'.$data['event_date'].'" data-event_start="'.$data['event_start'].'" data-event_end_date="'.$event_end_date.'" data-event_end_time="'.$event_end_time.'" data-oplevel="'.$data['oplevel'].'" data-hotline="'.$data['hotline'].'" data-event_banner="'.$data['event_banner'].'" data-org_code="'.($data['org_code'] ?? '').'">
            <i class="fas fa-pencil-alt"></i> Edit</span></a>';
        echo ' ';
        echo '<a href="javascript:void(0)" onclick="deletePlan('.$data['id'].')" data-toggle="tooltip" title="Delete PERTI Plan"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
    }
    echo '</center></td>';

    echo '</tr>';
}

// Render helper: emit a section header row
function render_section_header($label, $count, $section_class, $extra = '') {
    $colspan = 8; // matches table column count
    echo '<tr class="plan-section-header '.$section_class.'">';
    echo '<td colspan="'.$colspan.'">'.$label.' ('.$count.')'.$extra.'</td>';
    echo '</tr>';
}

// Render sections
$section_config = [
    'live'     => ['label_key' => 'home.section.happeningNow', 'css' => 'section-live'],
    'week'     => ['label_key' => 'home.section.thisWeek',     'css' => 'section-week'],
    'upcoming' => ['label_key' => 'home.section.upcoming',     'css' => 'section-upcoming'],
    'past'     => ['label_key' => 'home.section.pastEvents',   'css' => 'section-past'],
];

$past_visible_limit = 15;

foreach ($section_config as $key => $cfg) {
    $items = $sections[$key];
    if (empty($items)) continue;

    $count = count($items);

    if ($key === 'past' && $count > $past_visible_limit) {
        $extra = ' &mdash; ' . __('home.section.showing', ['count' => $past_visible_limit]);
        render_section_header(__($cfg['label_key']), $count, $cfg['css'], $extra);
    } else {
        render_section_header(__($cfg['label_key']), $count, $cfg['css']);
    }

    foreach ($items as $idx => $plan) {
        if ($key === 'past' && $idx >= $past_visible_limit) {
            // Hidden past rows — will be toggled by JS
            echo '<tr class="plan-row-past plan-row-past-hidden">';
            // Re-render inside the hidden wrapper by calling the same logic
            // but we need to close/reopen tr, so we use a flag approach instead
            echo '</tr>';
            // Actually, we need to render these as full rows with the hidden class.
            // The CSS class plan-row-past-hidden has display:none by default.
            break;
        }
        render_plan_row($plan, $perm, $hotline_badges, $org_display, $overlaps, $duplicates, $key === 'week' ? 'week' : $plan['_status']);
    }

    // Render hidden past rows
    if ($key === 'past' && $count > $past_visible_limit) {
        for ($i = $past_visible_limit; $i < $count; $i++) {
            echo '<tr class="plan-row-past plan-row-past-hidden">';
            // Inline the row content directly since we're inside a hidden row
            $hplan = $items[$i];
            $event_end_date = $hplan['event_end_date'] ?? '';
            $event_end_time = $hplan['event_end_time'] ?? '';
            $hotline = $hplan['hotline'] ?? '';
            $hotline_badge_val = $hotline_badges[$hotline] ?? ($hotline !== '' ? substr($hotline, 0, 1) : 'UNK');
            $icon_prefix = '';
            if ($hotline !== '' && strpos($hotline, 'Canada') === 0) {
                $icon_prefix = '<img src="https://flagcdn.com/20x15/ca.png" width="20" height="15" alt="" style="vertical-align: middle; margin-right: 4px;">';
            } elseif ($hotline === 'Mexico') {
                $icon_prefix = '<img src="https://flagcdn.com/20x15/mx.png" width="20" height="15" alt="" style="vertical-align: middle; margin-right: 4px;">';
            } elseif ($hotline === 'Caribbean') {
                $icon_prefix = '<i class="fas fa-tree fa-sm text-success" style="margin-right: 4px;"></i>';
            }
            $plan_org = $hplan['org_code'] ?? null;
            if ($plan_org === null) {
                $scope_badge = '<i class="fas fa-globe-americas text-muted fa-sm" data-toggle="tooltip" title="Global" style="margin-right: 4px;"></i>';
            } else {
                $scope_label = $org_display[$plan_org] ?? strtoupper($plan_org);
                $scope_badge = '<span class="badge badge-dark" data-toggle="tooltip" title="'.$scope_label.' Only" style="font-size: 0.65em; margin-right: 4px;">'.$scope_label.'</span>';
            }
            echo '<td>'.$scope_badge.$icon_prefix.htmlspecialchars($hplan['event_name']).' <span class="badge badge-secondary" data-toggle="tooltip" title="'.$hotline.' Hotline">'.$hotline_badge_val.'</span> <span class="badge badge-secondary" style="font-size: 0.65em;">' . __('home.status.past') . '</span></td>';
            echo '<td class="text-center">'.$hplan['event_date'].'</td>';
            echo '<td class="text-center">'.$hplan['event_start'].'Z</td>';
            echo !empty($event_end_date) ? '<td class="text-center">'.$event_end_date.'</td>' : '<td class="text-center text-muted">&mdash;</td>';
            echo !empty($event_end_time) ? '<td class="text-center">'.$event_end_time.'Z</td>' : '<td class="text-center text-muted">&mdash;</td>';
            $ol = (int)$hplan['oplevel'];
            $ol_classes = [1 => 'text-dark', 2 => 'text-success', 3 => 'text-warning', 4 => 'text-danger'];
            $ol_labels = [1 => 'Steady State', 2 => 'Localized Impact', 3 => 'Regional Impact', 4 => 'NAS-Wide Impact'];
            echo '<td class="'.($ol_classes[$ol] ?? 'text-dark').' text-center">'.$ol.' - '.($ol_labels[$ol] ?? '').'</td>';
            echo '<td class="text-center">'.$hplan['updated_at'].'</td>';
            echo '<td><center>';
            echo '<a href="plan?'.$hplan['id'].'" data-toggle="tooltip" title="View PERTI Plan"><span class="badge badge-primary"><i class="fas fa-eye"></i> View</span></a>';
            echo ' <a href="data?'.$hplan['id'].'" data-toggle="tooltip" title="View PERTI Staffing Data"><span class="badge badge-success"><i class="fas fa-table"></i> Data</span></a>';
            echo ' <a href="review?'.$hplan['id'].'" data-toggle="tooltip" title="View Traffic Management Review"><span class="badge badge-info"><i class="fas fa-magnifying-glass"></i> TMR</span></a>';
            if ($perm == true) {
                echo ' <a href="javascript:void(0)" data-toggle="tooltip" title="Edit PERTI Plan"><span class="badge badge-warning" data-toggle="modal" data-target="#editplanModal" data-id="'.$hplan['id'].'" data-event_name="'.htmlspecialchars($hplan['event_name']).'" data-event_date="'.$hplan['event_date'].'" data-event_start="'.$hplan['event_start'].'" data-event_end_date="'.$event_end_date.'" data-event_end_time="'.$event_end_time.'" data-oplevel="'.$hplan['oplevel'].'" data-hotline="'.$hplan['hotline'].'" data-event_banner="'.$hplan['event_banner'].'" data-org_code="'.($hplan['org_code'] ?? '').'"><i class="fas fa-pencil-alt"></i> Edit</span></a>';
                echo ' <a href="javascript:void(0)" onclick="deletePlan('.$hplan['id'].')" data-toggle="tooltip" title="Delete PERTI Plan"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
            }
            echo '</center></td>';
            echo '</tr>';
        }

        // "Show all" toggle row
        echo '<tr class="plan-past-toggle-row"><td colspan="8" class="text-center">';
        echo '<a href="javascript:void(0)" class="plan-past-toggle" data-total="'.$count.'">';
        echo __('home.section.showAllPast', ['count' => $count]);
        echo '</a></td></tr>';
    }
}
```

**Important notes for implementor:**
- The preamble code (lines 1-63) stays exactly as-is. Only replace lines 65-151.
- `render_plan_row()` is a function defined within the file scope — PHP 8.2 supports this.
- The hidden past rows are duplicated inline rather than calling `render_plan_row()` because the function uses `echo '<tr class="...">'` which conflicts with the hidden class needed. The implementor may refactor this to pass a CSS class override to `render_plan_row()` instead — that would be cleaner. See Step 2 below.

**Step 2: Refactor to avoid row duplication**

After the initial implementation above works, refactor `render_plan_row()` to accept an optional `$extra_class` parameter:

Change the function signature from:
```php
function render_plan_row($data, $perm, $hotline_badges, $org_display, $overlaps, $duplicates, $status)
```
to:
```php
function render_plan_row($data, $perm, $hotline_badges, $org_display, $overlaps, $duplicates, $status, $extra_class = '')
```

And change the `<tr>` line from:
```php
$row_class = 'plan-row-' . $status;
echo '<tr class="'.$row_class.'">';
```
to:
```php
$row_class = 'plan-row-' . $status . ($extra_class ? ' ' . $extra_class : '');
echo '<tr class="'.$row_class.'">';
```

Then replace the entire inline hidden-row rendering block (the `for ($i = $past_visible_limit; ...)` loop) with:

```php
for ($i = $past_visible_limit; $i < $count; $i++) {
    render_plan_row($items[$i], $perm, $hotline_badges, $org_display, $overlaps, $duplicates, 'past', 'plan-row-past-hidden');
}
```

**Step 3: Commit**

```bash
cd C:/Temp/perti-worktrees/perti-plans
git add api/data/plans.l.php
git commit -m "feat: rewrite plan listing with temporal sections, overlap and duplicate detection"
```

---

### Task 4: Add past-section expand/collapse JS to index.php

**Files:**
- Modify: `index.php` (insert JS after the `loadData()` function, around line 432)

**Step 1: Add the toggle handler**

In the `<script>` block in `index.php`, add the following code inside `$(document).ready(function() { ... })`, right after the `loadData();` call (line 464):

```javascript
            // Past events expand/collapse
            $(document).on('click', '.plan-past-toggle', function(e) {
                e.preventDefault();
                $('.plan-row-past-hidden').toggle();
                var total = $(this).data('total');
                if ($('.plan-row-past-hidden').first().is(':visible')) {
                    $(this).text(PERTII18n.t('home.section.showLess'));
                } else {
                    $(this).text(PERTII18n.t('home.section.showAllPast', { count: total }));
                }
                tooltips();
            });
```

Insert this right after line 464 (`loadData();`) and before line 466 (`// Auto-default org...`).

**Step 2: Commit**

```bash
cd C:/Temp/perti-worktrees/perti-plans
git add index.php
git commit -m "feat: add expand/collapse toggle for past events section"
```

---

### Task 5: Manual testing and verification

**No automated tests** — the project uses manual testing via the live site and local PHP server.

**Step 1: Visual verification checklist**

Deploy to the worktree's local server or verify by reading the code carefully:

1. **Section headers render** — each non-empty section has a header row with count
2. **Empty sections hidden** — sections with 0 plans don't show headers
3. **Live plans** — green left border + pulsing LIVE badge
4. **This Week plans** — blue left border + THIS WEEK badge
5. **Upcoming plans** — gray left border, no badge
6. **Past plans** — dimmed (opacity 0.7) + PAST badge, first 15 visible
7. **Past collapse** — plans 16+ hidden, "Show all N past events" link visible
8. **Past expand** — clicking link shows hidden rows, text changes to "Show less"
9. **Overlap detection** — overlapping non-past plans show warning text
10. **Duplicate detection** — same-date similar-name plans show info text
11. **Edit modal** — clicking Edit on any row (including past-hidden) populates correctly
12. **Delete** — deleting a plan reloads the table with correct sections
13. **Create** — creating a new plan reloads and classifies correctly
14. **Tooltips** — all tooltips still work after expand/collapse toggle

**Step 2: Edge cases to verify**

- Plan with no end date/time → should use start + 6h default
- Plan with end date but no end time → should use end-of-day (23:59)
- Exactly 15 past plans → no "Show all" link needed
- Exactly 16 past plans → "Show all 16" link appears, 1 hidden
- Two plans with 81% similar names on same date → duplicate flagged
- Two plans with 79% similar names on same date → no duplicate flag
- Plan that spans midnight (start 2300, end next day 0300) → correctly classified

**Step 3: Final commit (if any fixes needed)**

```bash
cd C:/Temp/perti-worktrees/perti-plans
git add -A
git commit -m "fix: address issues found during manual testing"
```

---

### Task 6: Clean up and prepare for PR

**Step 1: Review all changes**

```bash
cd C:/Temp/perti-worktrees/perti-plans
git log --oneline main..HEAD
git diff main..HEAD --stat
```

**Step 2: Create PR**

```bash
cd C:/Temp/perti-worktrees/perti-plans
git push -u origin feature/perti-plans
```

Then create PR with title: `feat: add temporal sections, overlap & duplicate detection to homepage plan listing`

Summary should mention:
- Temporal classification: LIVE / This Week / Upcoming / Past
- Section header separators with plan counts
- Left-border color + status badges per row
- Overlap detection for non-past plans
- Fuzzy duplicate detection (same date + >80% name similarity)
- Past events: show 15, collapse rest with expand toggle
