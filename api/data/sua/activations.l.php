<?php
/**
 * SUA Activations List (HTML Table Rows)
 *
 * Returns HTML table rows for the activations table.
 * Used for AJAX loading in the SUA management page.
 */

include(__DIR__ . "/../../../load/config.php");
include(__DIR__ . "/../../../load/connect.php");

// Check ADL connection
if (!$conn_adl) {
    echo '<tr><td colspan="8" class="text-center text-danger">Database connection not available</td></tr>';
    exit;
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? strtoupper($_GET['status']) : null;

// Build query
$sql = "SELECT id, sua_id, sua_type, tfr_subtype, name, artcc,
               start_utc, end_utc, status, lower_alt, upper_alt,
               remarks, notam_number, created_by
        FROM sua_activations";

$where = [];
if ($status_filter && $status_filter !== 'ALL') {
    $where[] = "status = '" . addslashes($status_filter) . "'";
}

// Default: show non-expired
if (empty($where)) {
    $where[] = "status IN ('SCHEDULED', 'ACTIVE')";
}

$sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY
            CASE status
                WHEN 'ACTIVE' THEN 1
                WHEN 'SCHEDULED' THEN 2
                WHEN 'EXPIRED' THEN 3
                WHEN 'CANCELLED' THEN 4
            END,
            start_utc ASC";

$stmt = sqlsrv_query($conn_adl, $sql);

if ($stmt === false) {
    echo '<tr><td colspan="8" class="text-center text-danger">Database query failed</td></tr>';
    exit;
}

// Check if any rows
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if (!$row) {
    echo '<tr><td colspan="8" class="text-center text-muted">No activations found</td></tr>';
    exit;
}

$h = function($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };

// Type display names
$type_names = [
    'P' => 'Prohibited',
    'R' => 'Restricted',
    'W' => 'Warning',
    'A' => 'Alert',
    'MOA' => 'MOA',
    'NSA' => 'NSA',
    'ATCAA' => 'ATCAA',
    'IR' => 'IR',
    'VR' => 'VR',
    'SR' => 'SR',
    'AR' => 'AR',
    'TFR' => 'TFR',
    'OTHER' => 'Other'
];

// Status badge classes
$status_badges = [
    'SCHEDULED' => 'badge-info',
    'ACTIVE' => 'badge-success',
    'EXPIRED' => 'badge-secondary',
    'CANCELLED' => 'badge-danger'
];

// Process first row and continue
do {
    $id = intval($row['id']);
    $sua_type = $row['sua_type'];
    $tfr_subtype = $row['tfr_subtype'];
    $name = $row['name'];
    $artcc = $row['artcc'] ?? '-';
    $status = $row['status'];
    $lower_alt = $row['lower_alt'] ?? '-';
    $upper_alt = $row['upper_alt'] ?? '-';
    $remarks = $row['remarks'] ?? '';

    // Format dates
    $start_utc = $row['start_utc'];
    $end_utc = $row['end_utc'];
    if ($start_utc instanceof DateTime) {
        $start = $start_utc->format('M j H:i') . 'Z';
        $start_iso = $start_utc->format('Y-m-d\TH:i');
    } else {
        $start = date('M j H:i', strtotime($start_utc)) . 'Z';
        $start_iso = date('Y-m-d\TH:i', strtotime($start_utc));
    }
    if ($end_utc instanceof DateTime) {
        $end = $end_utc->format('M j H:i') . 'Z';
        $end_iso = $end_utc->format('Y-m-d\TH:i');
    } else {
        $end = date('M j H:i', strtotime($end_utc)) . 'Z';
        $end_iso = date('Y-m-d\TH:i', strtotime($end_utc));
    }

    $type_display = $type_names[$sua_type] ?? $sua_type;
    if ($sua_type === 'TFR' && $tfr_subtype) {
        $type_display = "TFR ($tfr_subtype)";
    }

    $status_badge = $status_badges[$status] ?? 'badge-secondary';

    echo '<tr>';
    echo '<td><span class="badge badge-primary">' . $h($type_display) . '</span></td>';
    echo '<td>' . $h($name) . '</td>';
    echo '<td>' . $h($artcc) . '</td>';
    echo '<td class="text-monospace small">' . $h($start) . '</td>';
    echo '<td class="text-monospace small">' . $h($end) . '</td>';
    echo '<td>' . $h($lower_alt) . ' - ' . $h($upper_alt) . '</td>';
    echo '<td><span class="badge ' . $status_badge . '">' . $h($status) . '</span></td>';
    echo '<td class="text-right">';

    // Action buttons
    if ($status === 'SCHEDULED' || $status === 'ACTIVE') {
        echo '<span class="badge badge-warning mr-1" style="cursor:pointer" data-toggle="modal" data-target="#editModal" ';
        echo 'data-id="' . $id . '" ';
        echo 'data-sua-id="' . $h($row['sua_id'] ?? '') . '" ';
        echo 'data-sua-type="' . $h($sua_type) . '" ';
        echo 'data-tfr-subtype="' . $h($tfr_subtype) . '" ';
        echo 'data-name="' . $h($name) . '" ';
        echo 'data-artcc="' . $h($artcc) . '" ';
        echo 'data-start="' . $h($start_iso) . '" ';
        echo 'data-end="' . $h($end_iso) . '" ';
        echo 'data-lower-alt="' . $h($lower_alt) . '" ';
        echo 'data-upper-alt="' . $h($upper_alt) . '" ';
        echo 'data-remarks="' . $h($remarks) . '">';
        echo '<i class="fas fa-edit"></i></span>';

        echo '<span class="badge badge-danger" style="cursor:pointer" onclick="cancelActivation(' . $id . ', ' . htmlspecialchars(json_encode($row['name']), ENT_QUOTES, 'UTF-8') . ')">';
        echo '<i class="fas fa-times"></i></span>';
    }

    echo '</td>';
    echo '</tr>';

} while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC));

sqlsrv_free_stmt($stmt);
