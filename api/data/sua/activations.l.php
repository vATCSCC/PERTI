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
    $id = $row['id'];
    $sua_type = $row['sua_type'];
    $tfr_subtype = $row['tfr_subtype'];
    $name = htmlspecialchars($row['name']);
    $artcc = $row['artcc'] ?? '-';
    $status = $row['status'];
    $lower_alt = $row['lower_alt'] ?? '-';
    $upper_alt = $row['upper_alt'] ?? '-';
    $remarks = htmlspecialchars($row['remarks'] ?? '');

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
    echo '<td><span class="badge badge-primary">' . $type_display . '</span></td>';
    echo '<td>' . $name . '</td>';
    echo '<td>' . $artcc . '</td>';
    echo '<td class="text-monospace small">' . $start . '</td>';
    echo '<td class="text-monospace small">' . $end . '</td>';
    echo '<td>' . $lower_alt . ' - ' . $upper_alt . '</td>';
    echo '<td><span class="badge ' . $status_badge . '">' . $status . '</span></td>';
    echo '<td class="text-right">';

    // Action buttons
    if ($status === 'SCHEDULED' || $status === 'ACTIVE') {
        echo '<span class="badge badge-warning mr-1" style="cursor:pointer" data-toggle="modal" data-target="#editModal" ';
        echo 'data-id="' . $id . '" ';
        echo 'data-sua-id="' . htmlspecialchars($row['sua_id'] ?? '') . '" ';
        echo 'data-sua-type="' . $sua_type . '" ';
        echo 'data-tfr-subtype="' . $tfr_subtype . '" ';
        echo 'data-name="' . $name . '" ';
        echo 'data-artcc="' . $artcc . '" ';
        echo 'data-start="' . $start_iso . '" ';
        echo 'data-end="' . $end_iso . '" ';
        echo 'data-lower-alt="' . $lower_alt . '" ';
        echo 'data-upper-alt="' . $upper_alt . '" ';
        echo 'data-remarks="' . $remarks . '">';
        echo '<i class="fas fa-edit"></i></span>';

        echo '<span class="badge badge-danger" style="cursor:pointer" onclick="cancelActivation(' . $id . ', \'' . addslashes($name) . '\')">';
        echo '<i class="fas fa-times"></i></span>';
    }

    echo '</td>';
    echo '</tr>';

} while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC));

sqlsrv_free_stmt($stmt);
