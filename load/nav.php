<?php

// Prevent multiple inclusions
if (defined('NAV_PHP_LOADED')) {
    return;
}
define('NAV_PHP_LOADED', true);

// Session Start (S)
// Check headers_sent() to avoid warning when included after HTML output
if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
  session_start();
  ob_start();
}
// Session Start (E)

include_once("config.php");
include_once("connect.php");

//  Check Perms
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
            ['label' => 'NTML', 'path' => './ntml', 'perm' => true],
            ['label' => 'TMI Publisher', 'path' => './tmi-publish', 'perm' => true],
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
    // Dropdown: SWIM API
    'swim' => [
        'label' => 'SWIM',
        'items' => [
            ['label' => 'Overview', 'path' => './swim'],
            ['label' => 'API Keys', 'path' => './swim-keys'],
            ['label' => 'API Docs', 'path' => './docs/swim/', 'external' => true],
            ['label' => 'Technical Docs', 'path' => './swim-docs'],
        ]
    ],
    // Dropdown: About
    'about' => [
        'label' => 'About',
        'items' => [
            ['label' => 'Infrastructure', 'path' => './transparency'],
            ['label' => 'Privacy Policy', 'path' => './privacy'],
        ]
    ],
];

// ============================================================================
// NAVIGATION RENDERING FUNCTIONS
// ============================================================================

if (!function_exists('render_nav_item')) {
    function render_nav_item($item, $filepath) {
        $target = isset($item['external']) && $item['external'] ? ' target="_blank"' : '';
        $path = $filepath . $item['path'];
        return '<a class="dropdown-item" href="' . $path . '"' . $target . '>' . $item['label'] . '</a>';
    }
}

if (!function_exists('render_standalone_link')) {
    function render_standalone_link($item, $filepath) {
        $target = isset($item['external']) && $item['external'] ? ' target="_blank"' : '';
        $path = $filepath . $item['path'];
        return '<li class="nav-item"><a class="nav-link" href="' . $path . '"' . $target . '>' . $item['label'] . '</a></li>';
    }
}

if (!function_exists('render_dropdown')) {
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

<!-- Mobile Offcanvas Menu -->
<div class="offcanvas-mobile" id="primaryMenu">
    <div class="offcanvas-header">
        <span class="offcanvas-title">Menu</span>
        <button type="button" class="offcanvas-close" aria-label="Close menu">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="offcanvas-body">
        <ul class="mobile-nav-list">
            <?php foreach ($nav_config as $key => $group): ?>
                <?php
                // Check if this group/item requires permission
                $requires_perm = isset($group['perm']) && $group['perm'];
                if ($requires_perm && !$perm) continue;

                // Render as collapsible section or standalone link
                if (isset($group['items'])): ?>
                    <li class="mobile-nav-section">
                        <a class="mobile-nav-header" data-toggle="collapse" href="#mobile-<?= $key ?>" role="button" aria-expanded="false" aria-controls="mobile-<?= $key ?>">
                            <?= $group['label'] ?>
                            <i class="fas fa-chevron-down"></i>
                        </a>
                        <div class="collapse" id="mobile-<?= $key ?>">
                            <?php foreach ($group['items'] as $item):
                                // Check item-level permission
                                $item_requires_perm = isset($item['perm']) && $item['perm'];
                                if ($item_requires_perm && !$perm) continue;

                                $target = isset($item['external']) && $item['external'] ? ' target="_blank"' : '';
                            ?>
                                <a class="mobile-nav-link" href="<?= $filepath . $item['path'] ?>"<?= $target ?>><?= $item['label'] ?></a>
                            <?php endforeach; ?>
                        </div>
                    </li>
                <?php else:
                    $target = isset($group['external']) && $group['external'] ? ' target="_blank"' : '';
                ?>
                    <li class="mobile-nav-standalone">
                        <a class="mobile-nav-link" href="<?= $filepath . $group['path'] ?>"<?= $target ?>><?= $group['label'] ?></a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($perm): ?>
                <li class="mobile-nav-standalone" style="margin-top: auto; border-top: 1px solid rgba(255,255,255,0.1);">
                    <a class="mobile-nav-link" href="<?= $filepath ?>logout">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout (<?= $_SESSION['VATSIM_FIRST_NAME'] ?>)
                    </a>
                </li>
            <?php else: ?>
                <li class="mobile-nav-standalone" style="margin-top: auto; border-top: 1px solid rgba(255,255,255,0.1);">
                    <a class="mobile-nav-link" href="<?= $filepath ?>login">
                        <i class="fas fa-sign-in-alt mr-2"></i>Login
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>
<div class="offcanvas-backdrop" id="offcanvasBackdrop"></div>