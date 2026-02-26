<?php
/**
 * Public Navigation (Lightweight)
 *
 * For pages that don't require database queries or permission checks.
 * Reads session data for login state and org context. No DB connections needed.
 * All org/locale data comes from session cache populated by login or switch_org.
 */

// Prevent multiple inclusions
if (defined('NAV_PUBLIC_PHP_LOADED')) {
    return;
}
define('NAV_PUBLIC_PHP_LOADED', true);

// Start session to read login state (no DB query needed)
if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// Include org_context for helper functions (session reads only, no DB)
require_once __DIR__ . '/org_context.php';

// Check if user is logged in (cheap - just reading session data)
$logged_in = isset($_SESSION['VATSIM_CID']) && !empty($_SESSION['VATSIM_CID']);
$user_first_name = $logged_in ? ($_SESSION['VATSIM_FIRST_NAME'] ?? '') : '';
$user_last_name = $logged_in ? ($_SESSION['VATSIM_LAST_NAME'] ?? '') : '';

// Public pages don't show admin menu
$perm = false;
$filepath = "";

// Org context from session (no DB queries)
$org_code = $_SESSION['ORG_CODE'] ?? 'vatcscc';
$org_all = $_SESSION['ORG_ALL'] ?? ['vatcscc'];
$org_display = $_SESSION['ORG_INFO_' . $org_code]['display_name'] ?? 'DCC';
$org_colors = ['vatcscc' => '#1a73e8', 'canoc' => '#d32f2f', 'ecfmp' => '#7b1fa2', 'global' => '#f9a825'];
$org_color = $org_colors[$org_code] ?? '#555';
$multi_org = count($org_all) > 1;
$org_display_map = [];
foreach ($org_all as $oc) {
    $org_display_map[$oc] = $_SESSION['ORG_INFO_' . $oc]['display_name'] ?? strtoupper($oc);
}

// ============================================================================
// NAVIGATION CONFIGURATION
// ============================================================================
// Same structure as nav.php but without the Admin section
// ============================================================================

$nav_config = [
    // Dropdown: Planning Tools
    'planning' => [
        'label' => __('nav.planning'),
        'items' => [
            ['label' => __('nav.plans'), 'path' => './'],
            ['label' => __('nav.configs'), 'path' => './airport_config'],
            ['label' => __('nav.routes'), 'path' => './route'],
            ['label' => __('nav.playbook'), 'path' => './playbook'],
            ['label' => __('nav.simulator'), 'path' => './simulator'],
        ]
    ],
    // Dropdown: Data & Analysis
    'data' => [
        'label' => __('nav.data'),
        'items' => [
            ['label' => __('nav.nod'), 'path' => './nod'],
            ['label' => __('nav.gdt'), 'path' => './gdt'],
            ['label' => __('nav.demand'), 'path' => './demand'],
            ['label' => __('nav.splits'), 'path' => './splits'],
        ]
    ],
    // Dropdown: Tools
    'tools' => [
        'label' => __('nav.tools'),
        'items' => [
            ['label' => __('nav.jatoc'), 'path' => './jatoc'],
            ['label' => __('nav.eventAar'), 'path' => './event-aar'],
            ['label' => __('nav.tmiPublisher'), 'path' => './tmi-publish', 'perm' => true],
            ['label' => __('nav.status'), 'path' => './status'],
        ]
    ],
    // Dropdown: SWIM API
    'swim' => [
        'label' => __('nav.swim'),
        'items' => [
            ['label' => __('nav.overview'), 'path' => './swim'],
            ['label' => __('nav.apiKeys'), 'path' => './swim-keys'],
            ['label' => __('nav.apiDocs'), 'path' => './docs/swim/', 'external' => true],
            ['label' => __('nav.technicalDocs'), 'path' => './swim-docs'],
        ]
    ],
    // Dropdown: About
    'about' => [
        'label' => __('nav.about'),
        'items' => [
            ['label' => __('nav.infrastructure'), 'path' => './transparency'],
            ['label' => __('nav.fmdsComparison'), 'path' => './fmds-comparison'],
            ['label' => __('nav.privacyPolicy'), 'path' => './privacy'],
        ]
    ],
];

// ============================================================================
// NAVIGATION RENDERING FUNCTIONS
// ============================================================================

if (!function_exists('render_nav_item_public')) {
    function render_nav_item_public($item, $filepath) {
        $target = isset($item['external']) && $item['external'] ? ' target="_blank"' : '';
        $path = $filepath . $item['path'];
        return '<a class="dropdown-item" href="' . $path . '"' . $target . '>' . $item['label'] . '</a>';
    }
}

if (!function_exists('render_dropdown_public')) {
    function render_dropdown_public($key, $group, $filepath, $logged_in = false) {
        $html = '<li class="nav-item dropdown">';
        $html .= '<a class="nav-link dropdown-toggle" href="#" id="nav-' . $key . '" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
        $html .= $group['label'];
        $html .= '</a>';
        $html .= '<div class="dropdown-menu" aria-labelledby="nav-' . $key . '">';
        foreach ($group['items'] as $item) {
            // Skip items that require permission unless user is logged in
            if (isset($item['perm']) && $item['perm'] && !$logged_in) continue;
            $html .= render_nav_item_public($item, $filepath);
        }
        $html .= '</div>';
        $html .= '</li>';
        return $html;
    }
}

?>

<!-- Page Loading Indicator -->
<div id="perti-page-loader"><div class="bar"></div></div>
<div id="perti-loader-overlay"></div>

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
                // Skip permission-gated groups (Admin)
                if (isset($group['perm']) && $group['perm']) continue;

                // Render as dropdown
                if (isset($group['items'])) {
                    echo render_dropdown_public($key, $group, $filepath, $logged_in);
                }
                ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="d-flex align-items-center order-lg-3 ml-lg-auto">
        <?php if ($multi_org): ?>
            <div class="dropdown mr-2">
                <button class="btn btn-sm dropdown-toggle" style="background:<?= $org_color ?>;color:#fff;font-weight:600;" data-toggle="dropdown">
                    <?= htmlspecialchars($org_display) ?>
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <?php foreach ($org_all as $oc):
                        $oc_name = $org_display_map[$oc] ?? strtoupper($oc);
                    ?>
                        <a class="dropdown-item <?= $oc === $org_code ? 'active' : '' ?>" href="#" onclick="switchOrg('<?= $oc ?>');return false;">
                            <?= htmlspecialchars($oc_name) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <span class="badge mr-2" style="background:<?= $org_color ?>;color:#fff;font-size:0.75rem;padding:4px 8px;">
                <?= htmlspecialchars($org_display) ?>
            </span>
        <?php endif; ?>
        <?php if ($org_code === 'canoc'): ?>
            <div class="btn-group btn-group-sm mr-2" role="group">
                <button type="button" class="btn btn-outline-light btn-lang" onclick="setLocale('en-CA')" id="btn-lang-en">EN</button>
                <button type="button" class="btn btn-outline-light btn-lang" onclick="setLocale('fr-CA')" id="btn-lang-fr">FR</button>
            </div>
        <?php endif; ?>
        <?php if ($logged_in): ?>
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?= $filepath; ?>logout" id="profile">
                        <i class="fas fa-user-circle"></i> <?= htmlspecialchars($user_first_name . " " . $user_last_name); ?>
                    </a>
                </li>
            </ul>
        <?php else: ?>
            <a class="btn btn-sm btn-danger" href="<?= $filepath; ?>login" rel="noopener">
                <i class="fas fa-user font-size-lg mr-2"></i><?= __('nav.login') ?>
            </a>
        <?php endif; ?>
    </div>

  </div>
</nav>

<!-- Mobile Offcanvas Menu -->
<div class="offcanvas-mobile" id="primaryMenu">
    <div class="offcanvas-header">
        <span class="offcanvas-title"><?= __('nav.menu') ?></span>
        <button type="button" class="offcanvas-close" aria-label="Close menu">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="offcanvas-body">
        <ul class="mobile-nav-list">
            <?php foreach ($nav_config as $key => $group): ?>
                <?php
                // Skip permission-gated groups
                if (isset($group['perm']) && $group['perm']) continue;

                // Render as collapsible section
                if (isset($group['items'])): ?>
                    <li class="mobile-nav-section">
                        <a class="mobile-nav-header" data-toggle="collapse" href="#mobile-<?= $key ?>" role="button" aria-expanded="false" aria-controls="mobile-<?= $key ?>">
                            <?= $group['label'] ?>
                            <i class="fas fa-chevron-down"></i>
                        </a>
                        <div class="collapse" id="mobile-<?= $key ?>">
                            <?php foreach ($group['items'] as $item):
                                // Skip permission-gated items unless user is logged in
                                if (isset($item['perm']) && $item['perm'] && !$logged_in) continue;
                                $target = isset($item['external']) && $item['external'] ? ' target="_blank"' : '';
                            ?>
                                <a class="mobile-nav-link" href="<?= $filepath . $item['path'] ?>"<?= $target ?>><?= $item['label'] ?></a>
                            <?php endforeach; ?>
                        </div>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($logged_in): ?>
                <li class="mobile-nav-standalone" style="margin-top: auto; border-top: 1px solid rgba(255,255,255,0.1);">
                    <a class="mobile-nav-link" href="<?= $filepath ?>logout">
                        <i class="fas fa-sign-out-alt mr-2"></i><?= __('nav.logout') ?> (<?= htmlspecialchars($user_first_name); ?>)
                    </a>
                </li>
            <?php else: ?>
                <li class="mobile-nav-standalone" style="margin-top: auto; border-top: 1px solid rgba(255,255,255,0.1);">
                    <a class="mobile-nav-link" href="<?= $filepath ?>login">
                        <i class="fas fa-sign-in-alt mr-2"></i><?= __('nav.login') ?>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>
<div class="offcanvas-backdrop" id="offcanvasBackdrop"></div>

<script>
function switchOrg(orgCode) {
    fetch('/api/session/switch_org.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({org_code: orgCode})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            if (data.default_locale) {
                localStorage.setItem('PERTI_LOCALE', data.default_locale);
            }
            window.location.reload();
        }
    });
}

function setLocale(locale) {
    localStorage.setItem('PERTI_LOCALE', locale);
    fetch('/api/data/locale.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({locale: locale})
    }).then(function() {
        window.location.reload();
    });
}
(function() {
    var loc = localStorage.getItem('PERTI_LOCALE') || 'en-CA';
    var activeBtn = loc === 'fr-CA' ? 'btn-lang-fr' : 'btn-lang-en';
    var el = document.getElementById(activeBtn);
    if (el) el.classList.add('active');
})();
</script>
