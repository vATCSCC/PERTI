<?php
/**
 * Lightweight Public Navigation
 *
 * For pages that don't require authentication or database queries.
 * Always shows logged-out state (login button).
 * No database connections, no session handler cURL calls.
 */

// No database connection needed
// No session handler cURL needed

// Always show as not logged in for public pages
$perm = false;
$filepath = "";

// ============================================================================
// NAVIGATION CONFIGURATION
// ============================================================================
// Same structure as nav.php but without permission-gated items in rendering

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
            // NTML requires login - not shown in public nav
            ['label' => 'Status', 'path' => './status'],
        ]
    ],
    // Admin dropdown not shown (requires permission)
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

function render_nav_item($item, $filepath) {
    $target = isset($item['external']) && $item['external'] ? ' target="_blank"' : '';
    $path = $filepath . $item['path'];
    return '<a class="dropdown-item" href="' . $path . '"' . $target . '>' . $item['label'] . '</a>';
}

function render_dropdown($key, $group, $filepath) {
    $html = '<li class="nav-item dropdown">';
    $html .= '<a class="nav-link dropdown-toggle" href="#" id="nav-' . $key . '" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
    $html .= $group['label'];
    $html .= '</a>';
    $html .= '<div class="dropdown-menu" aria-labelledby="nav-' . $key . '">';
    foreach ($group['items'] as $item) {
        // Skip items that require permission
        if (isset($item['perm']) && $item['perm']) continue;
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
                // Skip permission-gated groups (Admin)
                if (isset($group['perm']) && $group['perm']) continue;

                // Render as dropdown
                if (isset($group['items'])) {
                    echo render_dropdown($key, $group, $filepath);
                }
                ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="d-flex align-items-center order-lg-3 ml-lg-auto">
        <!-- Always show login button for public pages -->
        <a class="btn btn-sm btn-danger" href="<?= $filepath; ?>login" rel="noopener">
            <i class="fas fa-user font-size-lg mr-2"></i>Login
        </a>
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
                                // Skip permission-gated items
                                if (isset($item['perm']) && $item['perm']) continue;
                                $target = isset($item['external']) && $item['external'] ? ' target="_blank"' : '';
                            ?>
                                <a class="mobile-nav-link" href="<?= $filepath . $item['path'] ?>"<?= $target ?>><?= $item['label'] ?></a>
                            <?php endforeach; ?>
                        </div>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <li class="mobile-nav-standalone" style="margin-top: auto; border-top: 1px solid rgba(255,255,255,0.1);">
                <a class="mobile-nav-link" href="<?= $filepath ?>login">
                    <i class="fas fa-sign-in-alt mr-2"></i>Login
                </a>
            </li>
        </ul>
    </div>
</div>
<div class="offcanvas-backdrop" id="offcanvasBackdrop"></div>
