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
 * @return string 'vatcscc' or 'vatcan'
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
    return !empty($_SESSION['ORG_GLOBAL']);
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
 * @return array e.g. ['vatcscc', 'vatcan']
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

    // Clear cached org info when switching
    foreach ($all_orgs as $org) {
        unset($_SESSION['ORG_INFO_' . $org]);
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
