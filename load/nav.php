<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
  session_start();
  ob_start();
}
// Session Start (E)

include("config.php");
include("connect.php");

//  Check Perms
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        // Getting CID Value
        $cid = strip_tags($_SESSION['VATSIM_CID']);
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
        }
    }
} else {
    $perm = true;
    $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
}

$filepath = "";

// ============================================================================
// NAVIGATION CONFIGURATION
// ============================================================================
// To add a new page: Add an entry to the appropriate group below
// Options per item:
//   'label'    => Display text (required)
//   'path'     => URL path (required)
//   'perm'     => true = requires login (optional, default: false)
//   'external' => true = opens in new tab (optional, default: false)
// ============================================================================

$nav_config = [
    // Dropdown: Planning Tools
    'planning' => [
        'label' => 'Planning',
        'items' => [
            ['label' => 'Plans', 'path' => './'],
            ['label' => 'Configs', 'path' => './configs'],
            ['label' => 'Routes', 'path' => './route'],
            ['label' => 'Simulator', 'path' => './simulator'],
        ]
    ],
    // Dropdown: Data & Analysis
    'data' => [
        'label' => 'Data',
        'items' => [
            ['label' => 'NOD', 'path' => './nod'],
            ['label' => 'GDT', 'path' => './gdt'],
            ['label' => 'Demand', 'path' => './demand'],
            ['label' => 'Splits', 'path' => './splits'],
        ]
    ],
    // Dropdown: Tools
    'tools' => [
        'label' => 'Tools',
        'items' => [
            ['label' => 'JATOC', 'path' => './jatoc'],
            ['label' => 'Event AAR', 'path' => './event-aar'],
            ['label' => 'Status', 'path' => './status'],
        ]
    ],
    // Dropdown: Admin (permission-gated)
    'admin' => [
        'label' => 'Admin',
        'perm' => true,
        'items' => [
            ['label' => 'Schedule', 'path' => './schedule'],
            ['label' => 'SUA', 'path' => './sua'],
            ['label' => 'Crossings', 'path' => './airspace-elements'],
        ]
    ],
    // Standalone link (no dropdown)
    'api' => [
        'label' => 'API Docs',
        'path' => './api-docs/',
        'external' => true,
    ],
];

// ============================================================================
// NAVIGATION RENDERING FUNCTIONS
// ============================================================================

function render_nav_item($item, $filepath) {
    $target = isset($item['external']) && $item['external'] ? ' target="_blank"' : '';
    $path = $filepath . $item['path'];
    return '<a class="dropdown-item" href="' . $path . '"' . $target . '>' . $item['label'] . '</a>';
}

function render_standalone_link($item, $filepath) {
    $target = isset($item['external']) && $item['external'] ? ' target="_blank"' : '';
    $path = $filepath . $item['path'];
    return '<li class="nav-item"><a class="nav-link" href="' . $path . '"' . $target . '>' . $item['label'] . '</a></li>';
}

function render_dropdown($key, $group, $filepath) {
    $html = '<li class="nav-item dropdown">';
    $html .= '<a class="nav-link dropdown-toggle" href="#" id="nav-' . $key . '" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
    $html .= $group['label'];
    $html .= '</a>';
    $html .= '<div class="dropdown-menu" aria-labelledby="nav-' . $key . '">';
    foreach ($group['items'] as $item) {
        $html .= render_nav_item($item, $filepath);
    }
    $html .= '</div>';
    $html .= '</li>';
    return $html;
}

?>

<!-- Enable Tooltips -->
<script>
    $(document).ready(function() {
        $('[data-toggle="tooltip"]').tooltip({'placement': 'top'});
    });
</script>

<nav class="cs-header navbar navbar-expand-lg navbar-dark navbar-floating">
  <div class="container px-0 px-xl-3">
    <button class="navbar-toggler ml-n2 mr-2" type="button" data-toggle="offcanvas" data-offcanvas-id="primaryMenu">
        <span class="navbar-toggler-icon"></span>
    </button>

    <a class="navbar-brand order-lg-1 mx-auto ml-lg-0 pr-lg-2 mr-lg-4" href="<?= $filepath; ?>./">
        <img class="navbar-floating-logo d-none d-lg-block" width="200" src="assets/img/logo.png">
        <img class="navbar-stuck-logo" width="200" src="assets/img/logo.png" alt="vATCSCC Logo"/>
    </a>

    <div class="d-flex align-items-left order-lg-3">
        <ul class="navbar-nav">
            <?php foreach ($nav_config as $key => $group): ?>
                <?php
                // Check if this group/item requires permission
                $requires_perm = isset($group['perm']) && $group['perm'];
                if ($requires_perm && !$perm) continue;

                // Render as dropdown or standalone link
                if (isset($group['items'])) {
                    echo render_dropdown($key, $group, $filepath);
                } else {
                    echo render_standalone_link($group, $filepath);
                }
                ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="d-flex align-items-center order-lg-3 ml-lg-auto">
        <?php if ($perm): ?>
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?= $filepath; ?>logout" id="profile">
                        <i class="fas fa-user-circle"></i> <?= $_SESSION['VATSIM_FIRST_NAME'] . " " . $_SESSION['VATSIM_LAST_NAME']; ?>
                    </a>
                </li>
            </ul>
        <?php else: ?>
            <a class="btn btn-sm btn-danger" href="<?= $filepath; ?>login" rel="noopener">
                <i class="fas fa-user font-size-lg mr-2"></i>Login
            </a>
        <?php endif; ?>
    </div>

  </div>
</nav>