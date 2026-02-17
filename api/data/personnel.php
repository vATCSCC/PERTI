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

if ($perm !== false) {
    // Protected CID from config - always allowed, cannot be deleted
    $protected_cid = defined('PROTECTED_CID') ? PROTECTED_CID : '';

    // Load all organizations for the dropdown
    $orgs_result = $conn_sqli->query("SELECT org_code, display_name FROM organizations WHERE is_active = 1 ORDER BY org_code");
    $all_orgs = [];
    while ($org_row = mysqli_fetch_assoc($orgs_result)) {
        $all_orgs[] = $org_row;
    }

    // Load all user_orgs memberships keyed by CID
    $uo_result = $conn_sqli->query("SELECT cid, org_code, is_privileged FROM user_orgs");
    $user_org_map = [];
    while ($uo = mysqli_fetch_assoc($uo_result)) {
        $user_org_map[$uo['cid']][] = ['org_code' => $uo['org_code'], 'is_privileged' => $uo['is_privileged']];
    }

    $query = mysqli_query($conn_sqli, ("SELECT * FROM users ORDER BY last_name DESC"));

    while ($data = mysqli_fetch_array($query)) {
        $cid = $data['cid'];
        $memberships = $user_org_map[$cid] ?? [];
        $member_codes = array_column($memberships, 'org_code');

        echo '<tr>';
        echo '<td class="text-center text-primary">'.$cid.'</td>';
        echo '<td class="text-center">'.$data['first_name'].'</td>';
        echo '<td class="text-center">'.$data['last_name'].'</td>';

        // Org column with checkboxes
        echo '<td class="text-center">';
        foreach ($all_orgs as $org) {
            $checked = in_array($org['org_code'], $member_codes) ? 'checked' : '';
            $priv = false;
            foreach ($memberships as $m) {
                if ($m['org_code'] === $org['org_code'] && $m['is_privileged']) {
                    $priv = true;
                }
            }
            $priv_class = $priv ? 'text-warning' : '';
            echo '<label class="mr-2 mb-0" style="cursor:pointer;">';
            echo '<input type="checkbox" class="org-toggle" data-cid="'.$cid.'" data-org="'.$org['org_code'].'" '.$checked.'> ';
            echo '<span class="'.$priv_class.'">'.$org['display_name'].'</span>';
            echo '</label>';
        }
        echo '</td>';

        echo '<td class="text-center">'.$data['updated_at'].'</td>';

            echo '<td><center>';
            if ($cid == $protected_cid) {
                echo '<span class="badge badge-secondary" data-toggle="tooltip" title="System personnel cannot be deleted"><i class="fas fa-lock"></i> Protected</span>';
            } else {
                echo '<a href="javascript:void(0)" onclick="deletePersonnel('.$data['id'].')" data-toggle="tooltip" title="Delete Personnel"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
            }
            echo '</center></td>';

        echo '</tr>';
    }
}

?>