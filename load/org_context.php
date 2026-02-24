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
 * @return string 'vatcscc', 'canoc', 'ecfmp', etc.
 */
function get_org_code(): string {
    return $_SESSION['ORG_CODE'] ?? 'vatcscc';
}

/**
 * Check if current user is privileged in active org (or is global)
 * @return bool
 */
function is_org_privileged(): bool {
    return !empty($_SESSION['ORG_PRIVILEGED']) || !empty($_SESSION['ORG_GLOBAL']);
}

/**
 * Check if current user has global access (can see/edit all plans)
 * @return bool
 */
function is_org_global(): bool {
    return !empty($_SESSION['ORG_GLOBAL']) || (($_SESSION['ORG_CODE'] ?? '') === 'global');
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
 * @return array e.g. ['vatcscc', 'canoc']
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
    // PROTECTED_CID always gets full access to all orgs
    $is_protected = defined('PROTECTED_CID') && (string)$cid === PROTECTED_CID;

    $stmt = mysqli_prepare($conn, "SELECT org_code, is_privileged, is_primary, is_global FROM user_orgs WHERE cid = ?");
    mysqli_stmt_bind_param($stmt, "i", $cid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $all_orgs = [];
    $primary_org = null;
    $org_privileges = [];
    $has_global = $is_protected;

    while ($row = mysqli_fetch_assoc($result)) {
        $all_orgs[] = $row['org_code'];
        $org_privileges[$row['org_code']] = $is_protected ? true : (bool)$row['is_privileged'];
        if ($row['is_primary']) {
            $primary_org = $row['org_code'];
        }
        if (!empty($row['is_global'])) {
            $has_global = true;
        }
    }

    // Protected user: ensure membership in all active orgs
    if ($is_protected) {
        $org_result = mysqli_query($conn, "SELECT org_code FROM organizations WHERE is_active = 1");
        while ($org_row = mysqli_fetch_assoc($org_result)) {
            $oc = $org_row['org_code'];
            if (!in_array($oc, $all_orgs)) {
                $all_orgs[] = $oc;
                $org_privileges[$oc] = true;
            }
        }
    }

    if (empty($all_orgs)) {
        $all_orgs = ['vatcscc'];
        $primary_org = 'vatcscc';
        $org_privileges['vatcscc'] = false;
    }

    if ($target_org && in_array($target_org, $all_orgs)) {
        $active_org = $target_org;
    } else {
        $active_org = $primary_org ?? $all_orgs[0];
    }

    $_SESSION['ORG_CODE'] = $active_org;
    $_SESSION['ORG_PRIVILEGED'] = $is_protected ? true : ($org_privileges[$active_org] ?? false);
    $_SESSION['ORG_GLOBAL'] = $has_global;
    $_SESSION['ORG_ALL'] = $all_orgs;

    // Global org membership implies global access and privilege
    if ($active_org === 'global') {
        $_SESSION['ORG_PRIVILEGED'] = true;
        $_SESSION['ORG_GLOBAL'] = true;
    }

    // Clear cached org info when switching
    foreach ($all_orgs as $org) {
        unset($_SESSION['ORG_INFO_' . $org]);
    }
}

/**
 * Load the current org's facility codes into session cache.
 * Called once per session, cached in $_SESSION['ORG_FACILITIES'].
 * @param mysqli $conn MySQLi connection
 * @return array Facility codes for the active org
 */
function load_org_facilities($conn): array {
    $org = get_org_code();
    $cache_key = 'ORG_FACILITIES_' . $org;

    if (!empty($_SESSION[$cache_key])) {
        return $_SESSION[$cache_key];
    }

    // Global org: return ALL facilities from ALL orgs
    if ($org === 'global') {
        $result = mysqli_query($conn, "SELECT DISTINCT facility_code FROM org_facilities");
    } else {
        $stmt = mysqli_prepare($conn, "SELECT facility_code FROM org_facilities WHERE org_code = ?");
        mysqli_stmt_bind_param($stmt, "s", $org);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    }

    $facilities = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $facilities[] = strtoupper($row['facility_code']);
    }

    $_SESSION[$cache_key] = $facilities;
    return $facilities;
}

/**
 * Validate that a facility code is within the current org's jurisdiction.
 * - ARTCC/FIR codes: checked directly against org_facilities
 * - Airport codes: resolved via apts.RESP_FIR_ID / RESP_ARTCC_ID then checked
 * - Global users bypass all checks
 *
 * @param string $facility_code Facility, ARTCC, FIR, or airport code
 * @param mysqli $conn_sqli MySQL connection (for org_facilities)
 * @param resource|null $conn_adl Azure SQL connection (for apts lookup, optional)
 * @return true|array True if allowed, or ['error' => msg, 'error_code' => key, 'params' => [...]]
 */
function validate_facility_scope(string $facility_code, $conn_sqli, $conn_adl = null) {
    if (is_org_global()) {
        return true;
    }

    $code = strtoupper(trim($facility_code));
    if ($code === '') {
        return true;
    }

    $org = get_org_code();
    $org_info = get_org_info($conn_sqli);
    $org_display = $org_info['display_name'] ?? strtoupper($org);
    $facilities = load_org_facilities($conn_sqli);

    // Direct match (ARTCC/FIR code in org_facilities)
    if (in_array($code, $facilities)) {
        return true;
    }

    // Airport resolution: look up responsible ARTCC/FIR
    if ($conn_adl !== null) {
        $sql = "SELECT RESP_FIR_ID, RESP_ARTCC_ID FROM dbo.apts WHERE ICAO_ID = ? OR ARPT_ID = ?";
        $stmt = sqlsrv_query($conn_adl, $sql, [$code, $code]);
        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            if ($row) {
                $responsible = $row['RESP_FIR_ID'] ?? $row['RESP_ARTCC_ID'] ?? null;
                if ($responsible && in_array(strtoupper($responsible), $facilities)) {
                    return true;
                }
                // Airport found but responsible facility not in org
                return [
                    'error' => "$code is outside $org_display's jurisdiction",
                    'error_code' => 'error.facilityOutOfScope',
                    'params' => ['facility' => $code, 'org' => $org_display]
                ];
            }
            // Airport not in apts table
            // Fall through — might be a direct facility code not in org
        }
    }

    // Code not recognized as a facility in this org
    if (strlen($code) <= 4 && preg_match('/^[A-Z]{2,4}$/', $code)) {
        // Looks like a facility code but not in org
        return [
            'error' => "$code is outside $org_display's jurisdiction",
            'error_code' => 'error.facilityOutOfScope',
            'params' => ['facility' => $code, 'org' => $org_display]
        ];
    }

    return [
        'error' => "Facility $code not recognized",
        'error_code' => 'error.facilityNotRecognized',
        'params' => ['facility' => $code]
    ];
}

/**
 * Validate multiple facility codes against org scope.
 * @return true|array True if all allowed, or first error result
 */
function validate_facilities_scope(array $facility_codes, $conn_sqli, $conn_adl = null) {
    foreach ($facility_codes as $code) {
        $code = is_string($code) ? trim($code) : '';
        if ($code === '') continue;
        $result = validate_facility_scope($code, $conn_sqli, $conn_adl);
        if ($result !== true) {
            return $result;
        }
    }
    return true;
}

/**
 * Require facility to be within org scope, or exit with 403 JSON.
 * @param string $facility_code Facility code to validate
 * @param mysqli $conn_sqli MySQL connection
 * @param resource|null $conn_adl Azure SQL connection (optional)
 */
function require_facility_scope(string $facility_code, $conn_sqli, $conn_adl = null): void {
    $result = validate_facility_scope($facility_code, $conn_sqli, $conn_adl);
    if ($result !== true) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}

/**
 * Require all facilities to be within org scope, or exit with 403 JSON.
 * @param array $facility_codes Array of facility codes
 * @param mysqli $conn_sqli MySQL connection
 * @param resource|null $conn_adl Azure SQL connection (optional)
 */
function require_facilities_scope(array $facility_codes, $conn_sqli, $conn_adl = null): void {
    $result = validate_facilities_scope($facility_codes, $conn_sqli, $conn_adl);
    if ($result !== true) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}

/**
 * Validate that a plan belongs to the current org or is global.
 * Global plans (org_code IS NULL) are accessible from any org.
 * @param int $p_id Plan ID
 * @param mysqli $conn MySQLi connection
 * @return bool
 */
function validate_plan_org(int $p_id, $conn): bool {
    // Global users can access any plan
    if (is_org_global()) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM p_plans WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $p_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        return mysqli_num_rows($result) > 0;
    }

    $org = get_org_code();
    $stmt = mysqli_prepare($conn, "SELECT id FROM p_plans WHERE id = ? AND (org_code = ? OR org_code IS NULL)");
    mysqli_stmt_bind_param($stmt, "is", $p_id, $org);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_num_rows($result) > 0;
}
