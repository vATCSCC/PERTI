<?php
/**
 * Playbook Visibility Helper
 *
 * Centralizes visibility/permission logic for playbook plays.
 * Include after connect.php. Requires org_context.php for org checks.
 *
 * Visibility levels:
 *   public       — Anyone can view. Authenticated users can edit.
 *   local        — Only the creator (created_by CID) can view/edit.
 *   private_users — Creator + ACL entries with can_view=1 can view.
 *   private_org  — Creator + (same org or global) AND ACL can_view=1 can view.
 *
 * Owner (created_by) always has full implicit access without an ACL row.
 * Admin users (admin_users table) bypass all visibility checks.
 */

if (defined('PLAYBOOK_VISIBILITY_LOADED')) {
    return;
}
define('PLAYBOOK_VISIBILITY_LOADED', true);

require_once __DIR__ . '/org_context.php';

/**
 * Check if current session user is a playbook admin (in admin_users table).
 * Admin users see and edit ALL plays regardless of visibility.
 *
 * @param mysqli $conn MySQL connection
 * @return bool
 */
function is_playbook_admin($conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;

    $cid = $_SESSION['VATSIM_CID'] ?? null;
    if (!$cid) {
        $cached = false;
        return false;
    }

    $stmt = $conn->prepare("SELECT cid FROM admin_users WHERE cid = ?");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $cached = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $cached;
}

/**
 * Get the current session user's CID, or null if not logged in.
 * @return int|null
 */
function _pb_session_cid(): ?int {
    $cid = $_SESSION['VATSIM_CID'] ?? null;
    return $cid !== null ? (int)$cid : null;
}

/**
 * Check if a CID is the owner of a play.
 * @param array $play Row from playbook_plays (must include created_by)
 * @param int $cid VATSIM CID
 * @return bool
 */
function _pb_is_owner(array $play, int $cid): bool {
    return (string)($play['created_by'] ?? '') === (string)$cid;
}

/**
 * Get a CID's ACL entry for a play, or null if not on the list.
 * @param int $play_id
 * @param int $cid
 * @param mysqli $conn
 * @return array|null ACL row or null
 */
function _pb_get_acl(int $play_id, int $cid, $conn): ?array {
    $stmt = $conn->prepare("SELECT can_view, can_manage, can_manage_acl FROM playbook_play_acl WHERE play_id = ? AND cid = ?");
    $stmt->bind_param('ii', $play_id, $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Check if a specific CID can VIEW a play.
 * Used by SWIM API where session CID != the CID being checked.
 *
 * @param array $play Row from playbook_plays (visibility, created_by, org_code, play_id)
 * @param int $cid VATSIM CID to check
 * @param mysqli $conn MySQL connection
 * @param bool $is_admin Whether this CID is an admin
 * @return bool
 */
function can_cid_view_play(array $play, int $cid, $conn, bool $is_admin = false): bool {
    if ($is_admin) return true;

    $visibility = $play['visibility'] ?? 'public';

    if ($visibility === 'public') return true;

    if (_pb_is_owner($play, $cid)) return true;

    if ($visibility === 'local') return false;

    // private_users or private_org — check ACL
    $acl = _pb_get_acl((int)$play['play_id'], $cid, $conn);
    if (!$acl || !$acl['can_view']) return false;

    if ($visibility === 'private_users') return true;

    // private_org — user must be in same org or global
    if ($visibility === 'private_org') {
        $play_org = $play['org_code'] ?? null;
        if (!$play_org) return true; // global play (NULL org_code)

        // Check user's org memberships
        $stmt = $conn->prepare("SELECT org_code FROM user_orgs WHERE cid = ?");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_orgs = [];
        while ($row = $result->fetch_assoc()) {
            $user_orgs[] = $row['org_code'];
        }
        $stmt->close();

        return in_array($play_org, $user_orgs) || in_array('global', $user_orgs);
    }

    return false;
}

/**
 * Check if the current session user can VIEW a play.
 *
 * @param array $play Row from playbook_plays
 * @param mysqli $conn MySQL connection
 * @return bool
 */
function can_view_play(array $play, $conn): bool {
    $cid = _pb_session_cid();

    if (is_playbook_admin($conn)) return true;

    $visibility = $play['visibility'] ?? 'public';
    if ($visibility === 'public') return true;

    // All non-public require login
    if ($cid === null) return false;

    if (_pb_is_owner($play, $cid)) return true;

    if ($visibility === 'local') return false;

    // private_users or private_org — check ACL
    $acl = _pb_get_acl((int)$play['play_id'], $cid, $conn);
    if (!$acl || !$acl['can_view']) return false;

    if ($visibility === 'private_users') return true;

    // private_org — user must be in same org or global
    if ($visibility === 'private_org') {
        $play_org = $play['org_code'] ?? null;
        if (!$play_org) return true; // NULL org_code = global play
        $user_orgs = get_user_orgs();
        return in_array($play_org, $user_orgs) || in_array('global', $user_orgs);
    }

    return false;
}

/**
 * Check if the current session user can EDIT a play (metadata + routes).
 *
 * @param array $play Row from playbook_plays
 * @param mysqli $conn MySQL connection
 * @return bool
 */
function can_edit_play(array $play, $conn): bool {
    $cid = _pb_session_cid();
    if ($cid === null) return false;

    if (is_playbook_admin($conn)) return true;

    $visibility = $play['visibility'] ?? 'public';

    // Public plays: any authenticated user can edit (preserves current behavior)
    if ($visibility === 'public') return true;

    if (_pb_is_owner($play, $cid)) return true;

    if ($visibility === 'local') return false;

    // private_users or private_org — check ACL can_manage
    $acl = _pb_get_acl((int)$play['play_id'], $cid, $conn);
    if (!$acl || !$acl['can_manage']) return false;

    if ($visibility === 'private_users') return true;

    if ($visibility === 'private_org') {
        $play_org = $play['org_code'] ?? null;
        if (!$play_org) return true;
        $user_orgs = get_user_orgs();
        return in_array($play_org, $user_orgs) || in_array('global', $user_orgs);
    }

    return false;
}

/**
 * Check if the current session user can manage ACL for a play.
 *
 * @param array $play Row from playbook_plays
 * @param mysqli $conn MySQL connection
 * @return bool
 */
function can_manage_acl_play(array $play, $conn): bool {
    $cid = _pb_session_cid();
    if ($cid === null) return false;

    if (is_playbook_admin($conn)) return true;

    if (_pb_is_owner($play, $cid)) return true;

    $acl = _pb_get_acl((int)$play['play_id'], $cid, $conn);
    if (!$acl || !$acl['can_manage_acl']) return false;

    $visibility = $play['visibility'] ?? 'public';
    if ($visibility === 'private_org') {
        $play_org = $play['org_code'] ?? null;
        if (!$play_org) return true;
        $user_orgs = get_user_orgs();
        return in_array($play_org, $user_orgs) || in_array('global', $user_orgs);
    }

    return true;
}

/**
 * Build a SQL WHERE clause fragment for visibility filtering on list queries.
 *
 * Returns an array with:
 *   'sql'    => string SQL fragment (includes leading AND)
 *   'params' => array of parameter values to bind
 *   'types'  => string of bind_param type characters
 *
 * @param int|null $cid Current user CID (null = anonymous)
 * @param bool $is_admin Whether user is admin
 * @return array{sql: string, params: array, types: string}
 */
function build_visibility_where(?int $cid, bool $is_admin): array {
    // Admin: no filter
    if ($is_admin) {
        return ['sql' => '', 'params' => [], 'types' => ''];
    }

    // Anonymous: only public
    if ($cid === null) {
        return [
            'sql' => "AND p.visibility = 'public'",
            'params' => [],
            'types' => ''
        ];
    }

    // Authenticated non-admin: public + own local + ACL'd private
    // Note: private_org org-membership check is done at the row level in PHP
    // after fetching, not in SQL (avoids org table joins in the list query).
    // This means a small number of private_org rows might be fetched and then
    // filtered out in PHP, which is acceptable for the current dataset size.
    $sql = "AND (
        p.visibility = 'public'
        OR (p.visibility = 'local' AND p.created_by = ?)
        OR (p.visibility IN ('private_users','private_org') AND (
            p.created_by = ? OR EXISTS (
                SELECT 1 FROM playbook_play_acl a
                WHERE a.play_id = p.play_id AND a.cid = ? AND a.can_view = 1
            )
        ))
    )";

    return [
        'sql' => $sql,
        'params' => [$cid, $cid, $cid],
        'types' => 'iii'
    ];
}
