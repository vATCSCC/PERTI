<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../load/config.php");
include("../../load/connect.php");

// Check Perms
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {

        // Getting CID Value
        $cid = session_get('VATSIM_CID', '');

        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");

        if ($p_check) {
            $perm = true;
        }

    }
} else {
    $perm = true;
    $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
}

require_once(dirname(__DIR__, 2) . '/load/org_context.php');
$org = get_org_code();
$stmt_plans = $conn_sqli->prepare("SELECT * FROM p_plans WHERE org_code = ? OR org_code IS NULL ORDER BY event_date DESC");
$stmt_plans->bind_param("s", $org);
$stmt_plans->execute();
$query = $stmt_plans->get_result();

// Org display names for scope badges
$org_display = [];
$org_result = $conn_sqli->query("SELECT org_code, display_name FROM organizations");
if ($org_result) {
    while ($org_row = $org_result->fetch_assoc()) {
        $org_display[$org_row['org_code']] = $org_row['display_name'];
    }
}

// Hotline badge abbreviations
$hotline_badges = [
    'NY Metro' => 'NYC',
    'DC Metro' => 'DC',
    'Chicago' => 'CHI',
    'Atlanta' => 'ATL',
    'Florida' => 'FLA',
    'Texas' => 'TEX',
    'East Coast' => 'EC',
    'West Coast' => 'WC',
    'Canada East' => 'CANE',
    'Canada West' => 'CANW',
    'Mexico' => 'MEX',
    'Caribbean' => 'CAR'
];

// -----------------------------------------------------------------------
// Collect all plans into array for temporal classification
// -----------------------------------------------------------------------
$plans = [];
while ($data = mysqli_fetch_array($query)) {
    $plans[] = $data;
}

$now = time();

// Week boundaries: Monday 00:00Z through Sunday 23:59:59Z
$week_start = gmmktime(0, 0, 0, (int)gmdate('n'), (int)gmdate('j') - ((int)gmdate('N') - 1), (int)gmdate('Y'));
$week_end = $week_start + (7 * 86400) - 1;

// Build a UTC timestamp from "YYYY-MM-DD" + "HHMM" strings
function plan_build_utc_ts(string $date_str, string $time_str): ?int {
    if ($date_str === '') return null;
    $time_str = str_pad($time_str, 4, '0', STR_PAD_LEFT);
    $hh = substr($time_str, 0, 2);
    $mm = substr($time_str, 2, 2);
    return strtotime($date_str . ' ' . $hh . ':' . $mm . ':00 UTC');
}

// -----------------------------------------------------------------------
// Classify each plan: live | week | upcoming | past
// -----------------------------------------------------------------------
$sections = ['live' => [], 'week' => [], 'upcoming' => [], 'past' => []];
$background_plan_pattern = '/^advanced(\s+perti\s+plan)?$/i';

foreach ($plans as &$p) {
    $start_ts = plan_build_utc_ts($p['event_date'] ?? '', $p['event_start'] ?? '');

    $end_date = $p['event_end_date'] ?? '';
    $end_time = $p['event_end_time'] ?? '';
    if ($end_date !== '') {
        $end_ts = ($end_time !== '')
            ? plan_build_utc_ts($end_date, $end_time)
            : strtotime($end_date . ' 23:59:59 UTC');
    } else {
        $end_ts = $start_ts ? $start_ts + (6 * 3600) : null;
    }

    $p['_start_ts'] = $start_ts;
    $p['_end_ts']   = $end_ts;

    $is_background = preg_match($background_plan_pattern, trim($p['event_name']));

    if (!$is_background && $start_ts && $end_ts && $now >= $start_ts && $now <= $end_ts) {
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
        $p['_status'] = 'past';
        $sections['past'][] = &$p;
    }
}
unset($p);

// Sort within sections
usort($sections['live'],     fn($a, $b) => ($a['_start_ts'] ?? 0) - ($b['_start_ts'] ?? 0));
usort($sections['week'],     fn($a, $b) => ($a['_start_ts'] ?? 0) - ($b['_start_ts'] ?? 0));
usort($sections['upcoming'], fn($a, $b) => ($a['_start_ts'] ?? 0) - ($b['_start_ts'] ?? 0));
usort($sections['past'],     fn($a, $b) => ($b['_start_ts'] ?? 0) - ($a['_start_ts'] ?? 0));

// -----------------------------------------------------------------------
// Overlap detection (non-past plans only)
// -----------------------------------------------------------------------
$non_past = array_merge($sections['live'], $sections['week'], $sections['upcoming']);
$overlaps = [];
for ($i = 0, $n = count($non_past); $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
        $a = $non_past[$i];
        $b = $non_past[$j];
        if (preg_match($background_plan_pattern, trim($a['event_name'])) || preg_match($background_plan_pattern, trim($b['event_name']))) {
            continue;
        }
        if ($a['_start_ts'] && $a['_end_ts'] && $b['_start_ts'] && $b['_end_ts']
            && $a['_start_ts'] < $b['_end_ts'] && $b['_start_ts'] < $a['_end_ts']) {
            $b_label = htmlspecialchars($b['event_name']) . ' (' . gmdate('Hi', $b['_start_ts']) . 'Z-' . gmdate('Hi', $b['_end_ts']) . 'Z)';
            $a_label = htmlspecialchars($a['event_name']) . ' (' . gmdate('Hi', $a['_start_ts']) . 'Z-' . gmdate('Hi', $a['_end_ts']) . 'Z)';
            $overlaps[$a['id']][] = $b_label;
            $overlaps[$b['id']][] = $a_label;
        }
    }
}

// -----------------------------------------------------------------------
// Duplicate detection (same event_date + fuzzy name > 80%)
// -----------------------------------------------------------------------
$duplicates = [];
function plan_normalize_name(string $name): string {
    return preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', '', strtolower(trim($name))));
}
for ($i = 0, $n = count($non_past); $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
        if (($non_past[$i]['event_date'] ?? '') === ($non_past[$j]['event_date'] ?? '') && $non_past[$i]['event_date'] !== '') {
            similar_text(plan_normalize_name($non_past[$i]['event_name']), plan_normalize_name($non_past[$j]['event_name']), $pct);
            if ($pct > 80) {
                $duplicates[$non_past[$i]['id']][] = ['name' => $non_past[$j]['event_name'], 'id' => $non_past[$j]['id']];
                $duplicates[$non_past[$j]['id']][] = ['name' => $non_past[$i]['event_name'], 'id' => $non_past[$i]['id']];
            }
        }
    }
}

// -----------------------------------------------------------------------
// Render helpers
// -----------------------------------------------------------------------
function render_plan_row(array $data, bool $perm, array $hotline_badges, array $org_display, array $overlaps, array $duplicates, string $status, string $extra_class = ''): void {
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
        $scope_badge = '<span class="badge badge-dark" data-toggle="tooltip" title="' . $scope_label . ' Only" style="font-size: 0.65em; margin-right: 4px;">' . $scope_label . '</span>';
    }

    // Status badge
    $status_badge = '';
    if ($status === 'live') {
        $status_badge = ' <span class="badge badge-live">' . __('home.status.live') . '</span>';
    } elseif ($status === 'week') {
        $status_badge = ' <span class="badge badge-info" style="font-size: 0.65em;">' . __('home.status.thisWeek') . '</span>';
    } elseif ($status === 'past') {
        $status_badge = ' <span class="badge badge-secondary" style="font-size: 0.65em;">' . __('home.status.past') . '</span>';
    }

    $row_class = 'plan-row-' . $status . ($extra_class !== '' ? ' ' . $extra_class : '');
    echo '<tr class="' . $row_class . '">';

    // Event name + badges + annotations
    echo '<td>' . $scope_badge . $icon_prefix . htmlspecialchars($data['event_name']) . ' <span class="badge badge-secondary" data-toggle="tooltip" title="' . htmlspecialchars($hotline) . ' Hotline">' . $hotline_badge . '</span>' . $status_badge;

    if (!empty($overlaps[$data['id']])) {
        echo '<br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> ' . __('home.overlap.overlapsWith', ['plans' => implode(', ', $overlaps[$data['id']])]) . '</small>';
    }
    if (!empty($duplicates[$data['id']])) {
        foreach ($duplicates[$data['id']] as $dup) {
            echo '<br><small class="text-info"><i class="fas fa-clone"></i> ' . __('home.duplicate.possibleDuplicate', ['name' => htmlspecialchars($dup['name']), 'id' => $dup['id']]) . '</small>';
        }
    }
    echo '</td>';

    echo '<td class="text-center">' . $data['event_date'] . '</td>';
    echo '<td class="text-center">' . $data['event_start'] . 'Z</td>';
    echo !empty($event_end_date) ? '<td class="text-center">' . $event_end_date . '</td>' : '<td class="text-center text-muted">&mdash;</td>';
    echo !empty($event_end_time) ? '<td class="text-center">' . $event_end_time . 'Z</td>' : '<td class="text-center text-muted">&mdash;</td>';

    $ol = (int)$data['oplevel'];
    $ol_classes = [1 => 'text-dark', 2 => 'text-success', 3 => 'text-warning', 4 => 'text-danger'];
    $ol_labels  = [1 => 'Steady State', 2 => 'Localized Impact', 3 => 'Regional Impact', 4 => 'NAS-Wide Impact'];
    echo '<td class="' . ($ol_classes[$ol] ?? 'text-dark') . ' text-center">' . $ol . ' - ' . ($ol_labels[$ol] ?? '') . '</td>';

    echo '<td class="text-center">' . $data['updated_at'] . '</td>';

    echo '<td><center>';
    echo '<a href="plan?' . $data['id'] . '" data-toggle="tooltip" title="View PERTI Plan"><span class="badge badge-primary"><i class="fas fa-eye"></i> View</span></a> ';
    echo '<a href="data?' . $data['id'] . '" data-toggle="tooltip" title="View PERTI Staffing Data"><span class="badge badge-success"><i class="fas fa-table"></i> Data</span></a> ';
    echo '<a href="review?' . $data['id'] . '" data-toggle="tooltip" title="View Traffic Management Review"><span class="badge badge-info"><i class="fas fa-magnifying-glass"></i> TMR</span></a>';

    if ($perm) {
        echo ' <a href="javascript:void(0)" data-toggle="tooltip" title="Edit PERTI Plan"><span class="badge badge-warning" data-toggle="modal" data-target="#editplanModal"'
            . ' data-id="' . $data['id'] . '"'
            . ' data-event_name="' . htmlspecialchars($data['event_name']) . '"'
            . ' data-event_date="' . $data['event_date'] . '"'
            . ' data-event_start="' . $data['event_start'] . '"'
            . ' data-event_end_date="' . $event_end_date . '"'
            . ' data-event_end_time="' . $event_end_time . '"'
            . ' data-oplevel="' . $data['oplevel'] . '"'
            . ' data-hotline="' . htmlspecialchars($data['hotline'] ?? '') . '"'
            . ' data-event_banner="' . htmlspecialchars($data['event_banner'] ?? '') . '"'
            . ' data-org_code="' . ($data['org_code'] ?? '') . '"'
            . '><i class="fas fa-pencil-alt"></i> Edit</span></a> ';
        echo '<a href="javascript:void(0)" onclick="deletePlan(' . $data['id'] . ')" data-toggle="tooltip" title="Delete PERTI Plan"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
    }
    echo '</center></td>';
    echo '</tr>';
}

// -----------------------------------------------------------------------
// Render all sections
// -----------------------------------------------------------------------
$section_config = [
    'live'     => ['key' => 'home.section.happeningNow', 'css' => 'section-live'],
    'week'     => ['key' => 'home.section.thisWeek',     'css' => 'section-week'],
    'upcoming' => ['key' => 'home.section.upcoming',     'css' => 'section-upcoming'],
    'past'     => ['key' => 'home.section.pastEvents',   'css' => 'section-past'],
];
$past_visible_limit = 15;
$col_count = 8;

foreach ($section_config as $sec_key => $cfg) {
    $items = $sections[$sec_key];
    if (empty($items)) continue;

    $count = count($items);

    // Section header row
    $header_text = __($cfg['key']) . ' (' . $count . ')';
    if ($sec_key === 'past' && $count > $past_visible_limit) {
        $header_text .= ' &mdash; ' . __('home.section.showing', ['count' => $past_visible_limit]);
    }
    echo '<tr class="plan-section-header ' . $cfg['css'] . '"><td colspan="' . $col_count . '">' . $header_text . '</td></tr>';

    // Plan rows
    foreach ($items as $idx => $plan) {
        $extra = '';
        if ($sec_key === 'past' && $idx >= $past_visible_limit) {
            $extra = 'plan-row-past-hidden';
        }
        render_plan_row($plan, $perm, $hotline_badges, $org_display, $overlaps, $duplicates, $plan['_status'], $extra);
    }

    // "Show all" toggle link for past section
    if ($sec_key === 'past' && $count > $past_visible_limit) {
        echo '<tr class="plan-past-toggle-row"><td colspan="' . $col_count . '" class="text-center">';
        echo '<a href="javascript:void(0)" class="plan-past-toggle" data-total="' . $count . '">';
        echo __('home.section.showAllPast', ['count' => $count]);
        echo '</a></td></tr>';
    }
}
