<?php
/**
 * VATSIM SWIM API Key Management Portal
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

    // Connect to SWIM database
    $conn_swim_str = "sqlsrv:Server=" . getenv('SWIM_DB_SERVER') . ";Database=" . getenv('SWIM_DB_NAME');
    try {
        // Use existing connection if available
        if (!isset($conn_swim) || !$conn_swim) {
            $serverName = getenv('SWIM_DB_SERVER') ?: getenv('DB_SERVER');
            $database = getenv('SWIM_DB_NAME') ?: 'SWIM_API';
            $uid = getenv('DB_USER');
            $pwd = getenv('DB_PASS');

            $connectionInfo = [
                "Database" => $database,
                "UID" => $uid,
                "PWD" => $pwd,
                "Encrypt" => true,
                "TrustServerCertificate" => false,
                "ConnectionPooling" => true
            ];
            $conn_swim = sqlsrv_connect($serverName, $connectionInfo);
        }

        if (!$conn_swim) {
            throw new Exception('Database connection failed');
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
<html>
<head>
    <?php
        $page_title = "SWIM API Keys - PERTI";
        include("load/header.php");
    ?>
    <style>
        .api-key-display {
            font-family: 'Courier New', monospace;
            background: #1a1a2e;
            border: 1px solid #4a4a6a;
            padding: 15px;
            border-radius: 8px;
            word-break: break-all;
            color: #00ff88;
            font-size: 14px;
        }
        .tier-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
            text-transform: uppercase;
            font-weight: 600;
        }
        .tier-public { background: #28a745; color: white; }
        .tier-developer { background: #007bff; color: white; }
        .tier-partner { background: #fd7e14; color: white; }
        .tier-system { background: #dc3545; color: white; }
        .status-active { color: #28a745; }
        .status-revoked { color: #dc3545; text-decoration: line-through; }
        .key-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .key-card.revoked {
            opacity: 0.6;
            background: #f5f5f5;
        }
        .rate-limit-info {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
        }
        .info-card h5 { color: white; }
        .tier-info-table td, .tier-info-table th {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
        }
        .tier-info-table th {
            background: #343a40;
            color: white;
        }
    </style>
</head>

<body>

<?php include('load/nav.php'); ?>

<div class="container-fluid mt-4 mb-5">
    <div class="row">
        <div class="col-12">
            <h2><i class="fas fa-key text-danger"></i> VATSIM SWIM API Keys</h2>
            <p class="text-muted">Manage your API credentials for accessing the VATSIM System Wide Information Management (SWIM) data feed.</p>
            <hr>
        </div>
    </div>

    <?php if (!$logged_in): ?>
    <!-- Not logged in -->
    <div class="row">
        <div class="col-12 col-lg-8 offset-lg-2">
            <div class="alert alert-warning text-center">
                <h4><i class="fas fa-sign-in-alt"></i> Login Required</h4>
                <p>You must be logged in with your VATSIM account to create and manage SWIM API keys.</p>
                <a href="login" class="btn btn-primary"><i class="fas fa-user"></i> Login with VATSIM</a>
            </div>
        </div>
    </div>
    <?php else: ?>

    <!-- Logged in user -->
    <div class="row">
        <!-- Left column: Key management -->
        <div class="col-12 col-lg-8">
            <!-- Create new key -->
            <div class="card mb-4">
                <div class="card-header bg-dark text-white">
                    <i class="fas fa-plus-circle"></i> Create New API Key
                </div>
                <div class="card-body">
                    <form id="createKeyForm">
                        <div class="form-row">
                            <div class="col-md-4">
                                <label for="keyTier">Access Tier</label>
                                <select class="form-control" id="keyTier" name="tier">
                                    <option value="public">Public (30 req/min)</option>
                                    <option value="developer">Developer (100 req/min)</option>
                                </select>
                                <small class="form-text text-muted">Partner/System tiers require approval.</small>
                            </div>
                            <div class="col-md-6">
                                <label for="keyDescription">Description (optional)</label>
                                <input type="text" class="form-control" id="keyDescription" name="description"
                                       placeholder="e.g., My flight tracker app" maxlength="128">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fas fa-key"></i> Create
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- New key display (hidden by default) -->
            <div class="card mb-4 d-none" id="newKeyCard">
                <div class="card-header bg-success text-white">
                    <i class="fas fa-check-circle"></i> API Key Created Successfully
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Important:</strong> Copy this key now. For security reasons, it will not be displayed again.
                    </div>
                    <div class="api-key-display" id="newKeyDisplay"></div>
                    <button class="btn btn-outline-primary btn-sm mt-3" onclick="copyToClipboard()">
                        <i class="fas fa-copy"></i> Copy to Clipboard
                    </button>
                </div>
            </div>

            <!-- Existing keys -->
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <i class="fas fa-list"></i> Your API Keys
                    <?php if ($perm): ?>
                        <span class="badge badge-warning ml-2">Admin View - All Keys</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div id="keysLoading" class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x"></i>
                        <p class="mt-2">Loading your keys...</p>
                    </div>
                    <div id="keysContainer" class="d-none"></div>
                    <div id="noKeysMessage" class="text-center py-4 d-none">
                        <i class="fas fa-key fa-3x text-muted mb-3"></i>
                        <p class="text-muted">You don't have any API keys yet. Create one above to get started.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right column: Information -->
        <div class="col-12 col-lg-4">
            <div class="info-card">
                <h5><i class="fas fa-info-circle"></i> About SWIM API</h5>
                <p>VATSIM SWIM provides real-time flight data, positions, and traffic management information across the VATSIM network.</p>
                <a href="api-docs/" target="_blank" class="btn btn-light btn-sm">
                    <i class="fas fa-book"></i> View API Documentation
                </a>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-dark text-white">
                    <i class="fas fa-layer-group"></i> Access Tiers
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm tier-info-table mb-0">
                        <thead>
                            <tr>
                                <th>Tier</th>
                                <th>Rate Limit</th>
                                <th>WebSocket</th>
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
                    Partner and System tiers require approval. Contact us for access.
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-dark text-white">
                    <i class="fas fa-code"></i> Quick Start
                </div>
                <div class="card-body">
                    <p class="small">Include your API key in the Authorization header:</p>
                    <pre class="bg-dark text-light p-2 small rounded"><code>Authorization: Bearer YOUR_API_KEY</code></pre>
                    <p class="small mt-3">Example request:</p>
                    <pre class="bg-dark text-light p-2 small rounded" style="font-size: 11px;"><code>curl -H "Authorization: Bearer swim_pub_xxx" \
  https://perti.vatcscc.org/api/swim/v1/flights</code></pre>
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
        var statusClass = key.is_active ? 'status-active' : 'status-revoked';
        var cardClass = key.is_active ? '' : 'revoked';
        var tierClass = 'tier-' + key.tier;

        html += '<div class="key-card ' + cardClass + '">';
        html += '<div class="d-flex justify-content-between align-items-start">';
        html += '<div>';
        html += '<span class="tier-badge ' + tierClass + '">' + key.tier + '</span> ';
        html += '<code class="ml-2">' + key.api_key_masked + '</code>';
        if (key.description) {
            html += '<br><small class="text-muted">' + escapeHtml(key.description) + '</small>';
        }
        html += '</div>';
        html += '<div class="text-right">';
        if (key.is_active) {
            html += '<button class="btn btn-outline-danger btn-sm" onclick="revokeKey(' + key.id + ')">';
            html += '<i class="fas fa-trash"></i> Revoke</button>';
        } else {
            html += '<span class="badge badge-secondary">Revoked</span>';
        }
        html += '</div>';
        html += '</div>';
        html += '<div class="rate-limit-info mt-2">';
        html += '<i class="fas fa-clock"></i> Created: ' + (key.created_at || 'Unknown');
        if (key.last_used_at) {
            html += ' | Last used: ' + key.last_used_at;
        }
        if (key.owner_name) {
            html += ' | Owner: ' + escapeHtml(key.owner_name);
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
