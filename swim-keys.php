<?php
/**
 * VATSWIM API Key Management Portal
 *
 * Self-service interface for users to create and manage their SWIM API keys.
 * Supports public and developer tiers. Partner/system tiers require admin approval.
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 */

include("sessions/handler.php");

// Session Start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
include("load/connect.php");

// Check if user is logged in via VATSIM OAuth
$logged_in = isset($_SESSION['VATSIM_CID']) && !empty($_SESSION['VATSIM_CID']);
$cid = $logged_in ? session_get('VATSIM_CID', '') : '';
$user_name = $logged_in ? trim(session_get('VATSIM_FIRST_NAME', '') . ' ' . session_get('VATSIM_LAST_NAME', '')) : '';
$user_email = $logged_in ? session_get('VATSIM_EMAIL', '') : '';

// Check if user has admin permissions
$perm = false;
if ($logged_in && !defined('DEV')) {
    $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
    if ($p_check && $p_check->num_rows > 0) {
        $perm = true;
    }
}

// API actions (AJAX handlers)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (!$logged_in) {
        echo json_encode(['success' => false, 'error' => 'You must be logged in to perform this action.']);
        exit;
    }

    $action = $_POST['action'];

    // Use SWIM database connection from load/connect.php
    // Connection uses SWIM_SQL_* constants defined in config.php
    global $conn_swim;

    try {
        // Use lazy-loaded connection if global not available
        if (!$conn_swim) {
            $conn_swim = function_exists('get_conn_swim') ? get_conn_swim() : null;
        }

        if (!$conn_swim) {
            throw new Exception('SWIM database connection not available. Check SWIM_SQL_* configuration in config.php');
        }

        if ($action === 'create_key') {
            $tier = $_POST['tier'] ?? 'public';
            $description = trim($_POST['description'] ?? '');

            // Validate tier - only allow public and developer for self-service
            if (!in_array($tier, ['public', 'developer'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid tier. Only public and developer keys can be self-created.']);
                exit;
            }

            // Check existing key count for this user
            $count_sql = "SELECT COUNT(*) as cnt FROM dbo.swim_api_keys WHERE owner_cid = ? AND tier = ? AND is_active = 1";
            $count_stmt = sqlsrv_query($conn_swim, $count_sql, [$cid, $tier]);
            $count_row = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($count_stmt);

            $max_keys = ($tier === 'developer') ? 3 : 5;
            if ($count_row && $count_row['cnt'] >= $max_keys) {
                echo json_encode(['success' => false, 'error' => "You have reached the maximum number of $tier keys ($max_keys). Please revoke unused keys first."]);
                exit;
            }

            // Generate API key
            $prefix = ($tier === 'developer') ? 'swim_dev_' : 'swim_pub_';
            $api_key = $prefix . bin2hex(random_bytes(16));

            // Insert new key
            $insert_sql = "INSERT INTO dbo.swim_api_keys (api_key, tier, owner_name, owner_email, owner_cid, description, can_write, created_at, is_active)
                          VALUES (?, ?, ?, ?, ?, ?, 0, GETUTCDATE(), 1)";
            $insert_stmt = sqlsrv_query($conn_swim, $insert_sql, [$api_key, $tier, $user_name, $user_email, $cid, $description]);

            if ($insert_stmt) {
                sqlsrv_free_stmt($insert_stmt);
                echo json_encode([
                    'success' => true,
                    'api_key' => $api_key,
                    'message' => 'API key created successfully. Please save this key securely - it will only be shown once.'
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create API key. Please try again.']);
            }
            exit;
        }

        if ($action === 'revoke_key') {
            $key_id = intval($_POST['key_id'] ?? 0);

            // Only allow revoking own keys (unless admin)
            $where_clause = $perm ? "id = ?" : "id = ? AND owner_cid = ?";
            $params = $perm ? [$key_id] : [$key_id, $cid];

            $update_sql = "UPDATE dbo.swim_api_keys SET is_active = 0 WHERE $where_clause";
            $update_stmt = sqlsrv_query($conn_swim, $update_sql, $params);

            if ($update_stmt && sqlsrv_rows_affected($update_stmt) > 0) {
                sqlsrv_free_stmt($update_stmt);
                echo json_encode(['success' => true, 'message' => 'API key revoked successfully.']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to revoke API key or key not found.']);
            }
            exit;
        }

        if ($action === 'list_keys') {
            // List user's keys (or all keys for admin)
            $where_clause = $perm ? "1=1" : "owner_cid = ?";
            $params = $perm ? [] : [$cid];

            $list_sql = "SELECT id,
                               LEFT(api_key, 12) + '...' + RIGHT(api_key, 4) as api_key_masked,
                               tier, owner_name, description, can_write,
                               created_at, last_used_at, is_active
                        FROM dbo.swim_api_keys
                        WHERE $where_clause
                        ORDER BY created_at DESC";
            $list_stmt = sqlsrv_query($conn_swim, $list_sql, $params);

            $keys = [];
            while ($row = sqlsrv_fetch_array($list_stmt, SQLSRV_FETCH_ASSOC)) {
                // Format dates
                if ($row['created_at'] instanceof DateTime) {
                    $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
                }
                if ($row['last_used_at'] instanceof DateTime) {
                    $row['last_used_at'] = $row['last_used_at']->format('Y-m-d H:i:s');
                }
                $keys[] = $row;
            }
            sqlsrv_free_stmt($list_stmt);

            echo json_encode(['success' => true, 'keys' => $keys]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
        $page_title = "SWIM API Keys";
        include("load/header.php");
    ?>
    <style>
        /* SWIM Keys Page - TBFM/FSM Consistent Styling */
        .api-key-display {
            font-family: 'Inconsolata', 'Courier New', monospace;
            background: #1a1a2e;
            border: 1px solid #4a4a6a;
            padding: 15px;
            border-radius: 8px;
            word-break: break-all;
            color: #00ff88;
            font-size: 14px;
        }
        .tier-badge {
            font-size: 0.7rem;
            padding: 4px 10px;
            border-radius: 4px;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.03em;
        }
        .tier-public { background: #28a745; color: white; }
        .tier-developer { background: #007bff; color: white; }
        .tier-partner { background: #fd7e14; color: white; }
        .tier-system { background: #dc3545; color: white; }
        .status-active { color: #28a745; }
        .status-revoked { color: #dc3545; text-decoration: line-through; }

        /* Dark themed key cards */
        .key-card {
            background: #252540;
            border: 1px solid #333;
            border-left: 3px solid #4a9eff;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 10px;
            color: #e0e0e0;
        }
        .key-card.revoked {
            opacity: 0.5;
            background: #1a1a2e;
            border-left-color: #6c757d;
        }
        .key-card code {
            color: #4a9eff;
            background: transparent;
        }
        .key-card .text-muted {
            color: #888 !important;
        }
        .rate-limit-info {
            font-size: 0.8rem;
            color: #888;
        }

        /* Info card - gradient sidebar */
        .info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
        }
        .info-card h5 { color: white; }

        /* TBFM-style card headers */
        .tbfm-card-header {
            background: linear-gradient(180deg, #3a4a5c 0%, #2c3e50 100%);
            border-bottom: 2px solid #1a252f;
            padding: 12px 15px;
        }
        .tbfm-card-header .card-title {
            color: #ffffff;
            margin: 0;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .tbfm-card-header .card-title i {
            color: #5dade2;
        }

        /* Tier info table styling */
        .tier-info-table {
            margin-bottom: 0;
        }
        .tier-info-table td, .tier-info-table th {
            padding: 10px 12px;
            border-color: #dee2e6;
            vertical-align: middle;
        }
        .tier-info-table th {
            background: linear-gradient(180deg, #3a4a5c 0%, #2c3e50 100%);
            color: white;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-weight: 600;
        }

        /* Dark card body for keys list */
        .keys-container {
            background: #1a1a2e;
            border-radius: 0 0 6px 6px;
            padding: 15px;
            min-height: 150px;
        }

        /* Form styling */
        .swim-form .form-control {
            background: #fff;
            border: 1px solid #ced4da;
        }
        .swim-form .form-control:focus {
            border-color: #5dade2;
            box-shadow: 0 0 0 0.15rem rgba(93, 173, 226, 0.25);
        }
        .swim-form label {
            font-size: 0.85rem;
            font-weight: 500;
            color: #333;
        }
        .swim-form .form-text {
            font-size: 0.75rem;
        }

        /* Quick start code blocks */
        .quick-start-code {
            background: #1a1a2e !important;
            color: #e0e0e0;
            padding: 10px 12px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-family: 'Inconsolata', 'Courier New', monospace;
        }
        .quick-start-code code {
            color: #4a9eff;
            background: transparent !important;
        }

        /* Override Bootstrap pre/code defaults for SWIM page */
        .card pre,
        .card-body pre {
            background: #1a1a2e !important;
            color: #e0e0e0;
            padding: 10px 12px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-family: 'Inconsolata', 'Courier New', monospace;
            margin: 0;
            border: none;
        }
        .card pre code,
        .card-body pre code {
            color: #4a9eff;
            background: transparent !important;
        }
    </style>
</head>

<body>

<?php include('load/nav.php'); ?>

<!-- Hero Section -->
<section class="perti-hero perti-hero--dark-tool" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1><i class="fas fa-key mr-2"></i><?= __('swim.keys.title') ?></h1>
            <h4 class="text-white hvr-bob pl-1">
                <a href="#keys_section" style="text-decoration: none; color: #fff;">
                    <i class="fas fa-chevron-down text-danger"></i>
                    <?= __('swim.keys.subtitle') ?>
                </a>
            </h4>
        </center>
    </div>
</section>

<div class="container-fluid mt-3 mb-5" id="keys_section">
    <div class="row mb-3">
        <div class="col-12">
            <p class="text-muted mb-0"><?= __('swim.keys.pageDesc') ?></p>
        </div>
    </div>

    <?php if (!$logged_in): ?>
    <!-- Not logged in -->
    <div class="row">
        <div class="col-12 col-lg-8 offset-lg-2">
            <div class="card shadow-sm">
                <div class="card-header tbfm-card-header">
                    <span class="card-title"><i class="fas fa-sign-in-alt mr-2"></i><?= __('swim.keys.loginRequired') ?></span>
                </div>
                <div class="card-body text-center py-5">
                    <i class="fas fa-user-lock fa-4x mb-4" style="color: #5dade2;"></i>
                    <p class="lead mb-4"><?= __('swim.keys.loginMessage') ?></p>
                    <a href="login" class="btn btn-primary btn-lg"><i class="fas fa-user mr-2"></i><?= __('swim.keys.loginWithVatsim') ?></a>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>

    <!-- Logged in user -->
    <div class="row">
        <!-- Left column: Key management -->
        <div class="col-12 col-lg-8">
            <!-- Create new key -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header tbfm-card-header">
                    <span class="card-title"><i class="fas fa-plus-circle mr-2"></i><?= __('swim.keys.createNewKey') ?></span>
                </div>
                <div class="card-body swim-form">
                    <form id="createKeyForm">
                        <div class="row align-items-end">
                            <div class="col-md-4 mb-3 mb-md-0">
                                <label for="keyTier"><?= __('swim.keys.accessTier') ?></label>
                                <select class="form-control" id="keyTier" name="tier">
                                    <option value="public">Public (30 req/min)</option>
                                    <option value="developer">Developer (100 req/min)</option>
                                </select>
                                <small class="form-text text-muted"><?= __('swim.keys.tierApproval') ?></small>
                            </div>
                            <div class="col-md-5 mb-3 mb-md-0">
                                <label for="keyDescription"><?= __('swim.keys.descriptionLabel') ?> <span class="text-muted">(<?= __('swim.keys.descriptionOptional') ?>)</span></label>
                                <input type="text" class="form-control" id="keyDescription" name="description"
                                       placeholder="e.g., My flight tracker app" maxlength="128">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-key mr-1"></i> Create
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- New key display (hidden by default) -->
            <div class="card mb-4 d-none shadow-sm" id="newKeyCard">
                <div class="card-header bg-success text-white">
                    <i class="fas fa-check-circle mr-2"></i> <?= __('swim.keys.keyCreatedTitle') ?>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-3">
                        <i class="fas fa-exclamation-triangle mr-1"></i> <strong><?= __('swim.keys.important') ?></strong> <?= __('swim.keys.keyCreatedWarning') ?>
                    </div>
                    <div class="api-key-display" id="newKeyDisplay"></div>
                    <button class="btn btn-outline-light btn-sm mt-3" onclick="copyToClipboard()">
                        <i class="fas fa-copy mr-1"></i> <?= __('swim.keys.copyToClipboard') ?>
                    </button>
                </div>
            </div>

            <!-- Existing keys -->
            <div class="card shadow-sm">
                <div class="card-header tbfm-card-header d-flex justify-content-between align-items-center">
                    <span class="card-title"><i class="fas fa-list mr-2"></i><?= __('swim.keys.yourApiKeys') ?></span>
                    <?php if ($perm): ?>
                        <span class="badge badge-warning"><?= __('swim.keys.adminViewAll') ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0 keys-container">
                    <div id="keysLoading" class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x text-info"></i>
                        <p class="mt-2 text-muted"><?= __('swim.keys.loadingKeys') ?></p>
                    </div>
                    <div id="keysContainer" class="d-none"></div>
                    <div id="noKeysMessage" class="text-center py-4 d-none">
                        <i class="fas fa-key fa-3x mb-3" style="color: #444;"></i>
                        <p class="text-muted"><?= __('swim.keys.noKeys') ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right column: Information -->
        <div class="col-12 col-lg-4">
            <div class="info-card">
                <h5><i class="fas fa-info-circle mr-2"></i><?= __('swim.keys.aboutSwimApi') ?></h5>
                <p class="mb-3"><?= __('swim.keys.aboutSwimApiDesc') ?></p>
                <a href="api-docs/" target="_blank" class="btn btn-light">
                    <i class="fas fa-book mr-1"></i> <?= __('swim.keys.viewApiDocs') ?>
                </a>
            </div>

            <div class="card mb-4 shadow-sm">
                <div class="card-header tbfm-card-header">
                    <span class="card-title"><i class="fas fa-layer-group mr-2"></i><?= __('swim.keys.accessTiers') ?></span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm tier-info-table">
                        <thead>
                            <tr>
                                <th><?= __('swim.keys.tier') ?></th>
                                <th><?= __('swim.keys.rateLimitCol') ?></th>
                                <th><?= __('swim.keys.webSocketCol') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="tier-badge tier-public">Public</span></td>
                                <td>30/min</td>
                                <td>5 conn</td>
                            </tr>
                            <tr>
                                <td><span class="tier-badge tier-developer">Developer</span></td>
                                <td>100/min</td>
                                <td>50 conn</td>
                            </tr>
                            <tr>
                                <td><span class="tier-badge tier-partner">Partner</span></td>
                                <td>1,000/min</td>
                                <td>500 conn</td>
                            </tr>
                            <tr>
                                <td><span class="tier-badge tier-system">System</span></td>
                                <td>10,000/min</td>
                                <td>10,000 conn</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-muted small">
                    <?= __('swim.keys.tierFooter') ?>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header tbfm-card-header">
                    <span class="card-title"><i class="fas fa-code mr-2"></i><?= __('swim.keys.quickStart') ?></span>
                </div>
                <div class="card-body">
                    <p class="small mb-2"><?= __('swim.keys.authHeader') ?></p>
                    <div class="quick-start-code mb-3">
                        <code>Authorization: Bearer YOUR_API_KEY</code>
                    </div>
                    <p class="small mb-2"><?= __('swim.keys.exampleRequest') ?></p>
                    <div class="quick-start-code" style="font-size: 11px;">
                        <code>curl -H "Authorization: Bearer swim_pub_xxx" \<br>
  https://perti.vatcscc.org/api/swim/v1/flights</code>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include('load/footer.php'); ?>

<script>
// Load user's keys on page load
$(document).ready(function() {
    <?php if ($logged_in): ?>
    loadKeys();
    <?php endif; ?>
});

function loadKeys() {
    $.post('swim-keys.php', { action: 'list_keys' }, function(response) {
        $('#keysLoading').addClass('d-none');

        if (response.success && response.keys.length > 0) {
            $('#keysContainer').removeClass('d-none');
            renderKeys(response.keys);
        } else {
            $('#noKeysMessage').removeClass('d-none');
        }
    }, 'json').fail(function() {
        $('#keysLoading').html('<p class="text-danger">Failed to load keys. Please refresh the page.</p>');
    });
}

function renderKeys(keys) {
    var html = '';
    keys.forEach(function(key) {
        var cardClass = key.is_active ? '' : 'revoked';
        var tierClass = 'tier-' + key.tier;

        html += '<div class="key-card ' + cardClass + '">';
        html += '<div class="d-flex justify-content-between align-items-start">';
        html += '<div>';
        html += '<span class="tier-badge ' + tierClass + '">' + key.tier + '</span> ';
        html += '<code class="ml-2" style="font-size: 0.9rem;">' + key.api_key_masked + '</code>';
        if (key.description) {
            html += '<br><small class="text-muted mt-1 d-block">' + escapeHtml(key.description) + '</small>';
        }
        html += '</div>';
        html += '<div class="text-right">';
        if (key.is_active) {
            html += '<button class="btn btn-outline-danger btn-sm" onclick="revokeKey(' + key.id + ')">';
            html += '<i class="fas fa-trash mr-1"></i>Revoke</button>';
        } else {
            html += '<span class="badge badge-secondary">Revoked</span>';
        }
        html += '</div>';
        html += '</div>';
        html += '<div class="rate-limit-info mt-2 pt-2" style="border-top: 1px solid #333;">';
        html += '<i class="fas fa-clock mr-1"></i>Created: ' + (key.created_at || 'Unknown');
        if (key.last_used_at) {
            html += ' <span class="mx-2">|</span> Last used: ' + key.last_used_at;
        }
        if (key.owner_name) {
            html += ' <span class="mx-2">|</span> Owner: ' + escapeHtml(key.owner_name);
        }
        html += '</div>';
        html += '</div>';
    });
    $('#keysContainer').html(html);
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Create key form handler
$('#createKeyForm').on('submit', function(e) {
    e.preventDefault();

    var btn = $(this).find('button[type="submit"]');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Creating...');

    $.post('swim-keys.php', {
        action: 'create_key',
        tier: $('#keyTier').val(),
        description: $('#keyDescription').val()
    }, function(response) {
        btn.prop('disabled', false).html('<i class="fas fa-key"></i> Create');

        if (response.success) {
            $('#newKeyDisplay').text(response.api_key);
            $('#newKeyCard').removeClass('d-none');
            $('#keyDescription').val('');

            // Refresh key list
            $('#keysContainer').addClass('d-none');
            $('#noKeysMessage').addClass('d-none');
            $('#keysLoading').removeClass('d-none');
            loadKeys();

            // Scroll to new key display
            $('html, body').animate({
                scrollTop: $('#newKeyCard').offset().top - 100
            }, 500);
        } else {
            Swal.fire('Error', response.error || 'Failed to create API key.', 'error');
        }
    }, 'json').fail(function() {
        btn.prop('disabled', false).html('<i class="fas fa-key"></i> Create');
        Swal.fire('Error', 'Request failed. Please try again.', 'error');
    });
});

function revokeKey(keyId) {
    Swal.fire({
        title: 'Revoke API Key?',
        text: 'This action cannot be undone. Any applications using this key will stop working.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, revoke it'
    }).then(function(result) {
        if (result.isConfirmed) {
            $.post('swim-keys.php', { action: 'revoke_key', key_id: keyId }, function(response) {
                if (response.success) {
                    Swal.fire('Revoked', 'The API key has been revoked.', 'success');
                    loadKeys();
                } else {
                    Swal.fire('Error', response.error || 'Failed to revoke key.', 'error');
                }
            }, 'json');
        }
    });
}

function copyToClipboard() {
    var keyText = $('#newKeyDisplay').text();
    navigator.clipboard.writeText(keyText).then(function() {
        Swal.fire({
            icon: 'success',
            title: 'Copied!',
            text: 'API key copied to clipboard.',
            timer: 1500,
            showConfirmButton: false
        });
    }).catch(function() {
        // Fallback for older browsers
        var temp = $('<textarea>');
        $('body').append(temp);
        temp.val(keyText).select();
        document.execCommand('copy');
        temp.remove();
        Swal.fire({
            icon: 'success',
            title: 'Copied!',
            text: 'API key copied to clipboard.',
            timer: 1500,
            showConfirmButton: false
        });
    });
}
</script>

</body>
</html>
