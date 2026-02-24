<?php
/**
 * TMI Cancel Entry API
 * 
 * Cancels an active TMI entry or advisory.
 * 
 * POST /api/mgt/tmi/cancel.php
 * Body: { "entityType": "ENTRY"|"ADVISORY", "entityId": 123, "reason": "optional reason" }
 * 
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.0.0
 * @date 2026-01-27
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load dependencies
try {
    require_once __DIR__ . '/../../../load/config.php';
    require_once __DIR__ . '/../../../load/perti_constants.php';
    require_once __DIR__ . '/../../tmi/AdvisoryNumber.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config load error']);
    exit;
}

// Parse request body
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$entityType = strtoupper($payload['entityType'] ?? '');
$entityId = intval($payload['entityId'] ?? 0);
$reason = $payload['reason'] ?? 'Cancelled via TMI Publisher';
$userCid = $payload['userCid'] ?? null;
$userName = $payload['userName'] ?? 'Unknown';
$postAdvisory = isset($payload['postAdvisory']) ? (bool)$payload['postAdvisory'] : false;
$advisoryChannel = $payload['advisoryChannel'] ?? 'advzy_staging';

// Get org code from session context
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$org_code = $_SESSION['ORG_CODE'] ?? 'vatcscc';
$is_global_scope = function_exists('is_org_global') && is_org_global();
if ($org_code === 'global') {
    $user_orgs = $_SESSION['ORG_ALL'] ?? ['vatcscc'];
    $org_code = 'vatcscc';
    foreach ($user_orgs as $uo) {
        if ($uo !== 'global') { $org_code = $uo; break; }
    }
}
$org_scope = $is_global_scope ? '1=1' : 'org_code = :org_code';

if (empty($entityType) || !in_array($entityType, ['ENTRY', 'ADVISORY', 'PROGRAM', 'REROUTE', 'PUBLICROUTE'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid entityType. Must be ENTRY, ADVISORY, PROGRAM, REROUTE, or PUBLICROUTE']);
    exit;
}

// Get the subtype for REROUTE to distinguish between tmi_reroutes and tmi_public_routes
$entitySubtype = $payload['type'] ?? $payload['subtype'] ?? null;

if ($entityId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid entityId']);
    exit;
}

// Connect to TMI database (for entries, advisories, programs)
$tmiConn = null;
try {
    if (defined('TMI_SQL_HOST') && TMI_SQL_HOST) {
        $tmiConn = new PDO(
            "sqlsrv:Server=" . TMI_SQL_HOST . ";Database=" . TMI_SQL_DATABASE,
            TMI_SQL_USERNAME,
            TMI_SQL_PASSWORD
        );
        $tmiConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

if (!$tmiConn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database not configured']);
    exit;
}

// Connect to ADL database (for reroutes)
$adlConn = null;
try {
    if (defined('ADL_SQL_HOST') && ADL_SQL_HOST) {
        $adlConn = new PDO(
            "sqlsrv:Server=" . ADL_SQL_HOST . ";Database=" . ADL_SQL_DATABASE,
            ADL_SQL_USERNAME,
            ADL_SQL_PASSWORD
        );
        $adlConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (Exception $e) {
    // ADL connection failure is non-fatal for non-reroute operations
    $adlConn = null;
}

try {
    if ($entityType === 'ENTRY') {
        // Fetch entry details BEFORE cancelling (for cancellation posting)
        $fetchSql = "SELECT e.*, p.proposal_id
                     FROM dbo.tmi_entries e
                     LEFT JOIN dbo.tmi_proposals p ON e.entry_id = p.activated_entry_id
                     WHERE e.entry_id = :entry_id" . ($is_global_scope ? '' : ' AND e.org_code = :org_code');
        $fetchStmt = $tmiConn->prepare($fetchSql);
        $fetchStmt->execute([':entry_id' => $entityId, ':org_code' => $org_code]);
        $entryData = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        // Cancel NTML entry
        $sql = "UPDATE dbo.tmi_entries
                SET status = 'CANCELLED',
                    cancelled_at = SYSUTCDATETIME(),
                    cancelled_by = :cancelled_by,
                    cancel_reason = :cancel_reason,
                    updated_at = SYSUTCDATETIME()
                WHERE entry_id = :entry_id
                  AND {$org_scope}
                  AND status NOT IN ('CANCELLED', 'EXPIRED')";

        $stmt = $tmiConn->prepare($sql);
        $stmt->execute([
            ':cancelled_by' => $userCid,
            ':cancel_reason' => $reason,
            ':entry_id' => $entityId,
            ':org_code' => $org_code
        ]);

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Entry not found or already cancelled/expired'
            ]);
            exit;
        }

        // Log event
        logCancelEvent($tmiConn, 'ENTRY', $entityId, $reason, $userCid, $userName);

        // Post CANCEL entry to Discord for coordinated TMIs cancelled before valid_until
        $cancelPostResult = null;
        if ($entryData) {
            $cancelPostResult = postCancellationNtmlEntry($tmiConn, $entryData, $userName);
        }

    } elseif ($entityType === 'ADVISORY') {
        // Cancel advisory
        $sql = "UPDATE dbo.tmi_advisories
                SET status = 'CANCELLED',
                    cancelled_at = SYSUTCDATETIME(),
                    cancelled_by = :cancelled_by,
                    cancel_reason = :cancel_reason,
                    updated_at = SYSUTCDATETIME()
                WHERE advisory_id = :advisory_id
                  AND {$org_scope}
                  AND status NOT IN ('CANCELLED', 'EXPIRED')";

        $stmt = $tmiConn->prepare($sql);
        $stmt->execute([
            ':cancelled_by' => $userCid,
            ':cancel_reason' => $reason,
            ':advisory_id' => $entityId,
            ':org_code' => $org_code
        ]);

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Advisory not found or already cancelled/expired'
            ]);
            exit;
        }

        // Log event
        logCancelEvent($tmiConn, 'ADVISORY', $entityId, $reason, $userCid, $userName);

    } elseif ($entityType === 'PROGRAM') {
        // Cancel GDT program (Ground Stop or GDP)
        $sql = "UPDATE dbo.tmi_programs
                SET status = 'PURGED',
                    purged_utc = SYSUTCDATETIME(),
                    purged_by = :purged_by,
                    modified_utc = SYSUTCDATETIME(),
                    modified_by = :modified_by
                WHERE program_id = :program_id
                  AND {$org_scope}
                  AND status NOT IN ('PURGED', 'COMPLETED', 'SUPERSEDED')";

        $stmt = $tmiConn->prepare($sql);
        $stmt->execute([
            ':purged_by' => $userName,
            ':modified_by' => $userName,
            ':program_id' => $entityId,
            ':org_code' => $org_code
        ]);

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Program not found or already cancelled/completed'
            ]);
            exit;
        }

        // Log event
        logCancelEvent($tmiConn, 'PROGRAM', $entityId, $reason, $userCid, $userName);

    } elseif ($entityType === 'REROUTE' || $entityType === 'PUBLICROUTE') {
        // Check if this is a public route (tmi_public_routes in TMI database)
        // vs a reroute (tmi_reroutes)
        $isPublicRoute = ($entityType === 'PUBLICROUTE') ||
                         ($entitySubtype === 'publicroute') ||
                         ($entitySubtype === 'public_route');

        if ($isPublicRoute) {
            // Cancel public route (tmi_public_routes in TMI database)
            // status: 1=active, 2=expired, 3=cancelled
            $sql = "UPDATE dbo.tmi_public_routes
                    SET status = 3,
                        updated_at = SYSUTCDATETIME()
                    WHERE route_id = :id
                      AND {$org_scope}
                      AND status NOT IN (2, 3)";

            $stmt = $tmiConn->prepare($sql);
            $stmt->execute([
                ':id' => $entityId,
                ':org_code' => $org_code
            ]);

            $rowsAffected = $stmt->rowCount();

            if ($rowsAffected === 0) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Reroute not found or already cancelled/expired'
                ]);
                exit;
            }

            // Log event
            logCancelEvent($tmiConn, 'PUBLICROUTE', $entityId, $reason, $userCid, $userName);

        } else {
            // Cancel reroute (tmi_reroutes - try TMI first, then ADL)
            // status: 4=expired, 5=cancelled
            $rerouteConn = $tmiConn;

            // Check if tmi_reroutes exists in TMI database
            $tableExists = false;
            try {
                $checkStmt = $tmiConn->query("SELECT TOP 1 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'tmi_reroutes'");
                $tableExists = $checkStmt->fetch() !== false;
            } catch (Exception $e) {
                $tableExists = false;
            }

            if (!$tableExists && $adlConn) {
                $rerouteConn = $adlConn;
            }

            if (!$rerouteConn) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Database not configured for reroute operations'
                ]);
                exit;
            }

            $sql = "UPDATE dbo.tmi_reroutes
                    SET status = 5,
                        updated_at = SYSUTCDATETIME()
                    WHERE reroute_id = :id
                      AND {$org_scope}
                      AND status NOT IN (4, 5)";

            $stmt = $rerouteConn->prepare($sql);
            $stmt->execute([
                ':id' => $entityId,
                ':org_code' => $org_code
            ]);

            $rowsAffected = $stmt->rowCount();

            if ($rowsAffected === 0) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Reroute not found or already cancelled/expired'
                ]);
                exit;
            }

            // Log event (to TMI events table)
            logCancelEvent($tmiConn, 'REROUTE', $entityId, $reason, $userCid, $userName);
        }
    }

    // Post cancellation advisory if requested
    $advisoryResult = null;
    if ($postAdvisory) {
        $advisoryResult = postCancellationAdvisory($entityType, $entityId, $tmiConn, $adlConn, $advisoryChannel, $reason);
    }

    $response = [
        'success' => true,
        'message' => "{$entityType} #{$entityId} cancelled successfully",
        'entityType' => $entityType,
        'entityId' => $entityId
    ];

    if ($advisoryResult !== null) {
        $response['advisory'] = $advisoryResult;
    }

    // Include cancellation NTML post result (for ENTRY types)
    if (isset($cancelPostResult) && $cancelPostResult !== null) {
        $response['cancelPost'] = $cancelPostResult;
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Cancel failed: ' . $e->getMessage()]);
}

/**
 * Get next advisory number from database sequence
 * Uses centralized AdvisoryNumber class
 */
function getNextAdvisoryNumber($conn) {
    $advNum = new AdvisoryNumber($conn, 'pdo');
    return $advNum->reserve();
}

/**
 * Post cancellation advisory to Discord
 * Returns array with advisory info or null on failure
 */
function postCancellationAdvisory($entityType, $entityId, $tmiConn, $adlConn, $channel, $reason) {
    try {
        // Load TMIDiscord class
        require_once __DIR__ . '/../../../load/discord/TMIDiscord.php';

        if (!class_exists('TMIDiscord')) {
            error_log("TMIDiscord class not found");
            return ['success' => false, 'error' => 'TMIDiscord not available'];
        }

        $tmiDiscord = new TMIDiscord();
        $advisoryData = null;
        $result = null;

        // Get next advisory number for cancellation advisories
        $advisoryNumber = getNextAdvisoryNumber($tmiConn);

        if ($entityType === 'PROGRAM') {
            // Fetch program details
            $stmt = $tmiConn->prepare("
                SELECT p.*, a.RESP_ARTCC_ID as artcc
                FROM dbo.tmi_programs p
                LEFT JOIN dbo.apts a ON p.ctl_element = a.ICAO_ID
                WHERE p.program_id = :id
            ");
            $stmt->execute([':id' => $entityId]);
            $program = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$program) {
                return ['success' => false, 'error' => 'Program not found'];
            }

            $programType = $program['program_type'] ?? 'GS';
            $airport = $program['ctl_element'] ?? 'XXX';
            $artcc = $program['artcc'] ?? 'ZXX';

            $advisoryData = [
                'advisory_number' => $advisoryNumber,
                'ctl_element' => $airport,
                'airport' => $airport,
                'artcc' => $artcc,
                'adl_time' => gmdate('H:i'),
                'issue_date' => gmdate('Y-m-d H:i:s'),
                'start_utc' => $program['start_utc'] instanceof DateTime
                    ? $program['start_utc']->format('c')
                    : $program['start_utc'],
                'end_utc' => $program['end_utc'] instanceof DateTime
                    ? $program['end_utc']->format('c')
                    : $program['end_utc'],
                'comments' => $reason,
                'active_afp' => ''
            ];

            if ($programType === 'GS') {
                $result = $tmiDiscord->postGSCancellation($advisoryData, $channel);
            } else {
                // GDP cancellation
                $result = $tmiDiscord->postGDPCancellation($advisoryData, $channel);
            }

        } elseif ($entityType === 'REROUTE' || $entityType === 'PUBLICROUTE') {
            // Determine which table to query based on entity type
            $isPublicRoute = ($entityType === 'PUBLICROUTE');

            if ($isPublicRoute) {
                // Fetch from tmi_public_routes
                $stmt = $tmiConn->prepare("
                    SELECT route_id, name, route_string, advisory_text, reason,
                           valid_start_utc, valid_end_utc, facilities
                    FROM dbo.tmi_public_routes WHERE route_id = :id
                ");
                $stmt->execute([':id' => $entityId]);
                $route = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$route) {
                    return ['success' => false, 'error' => 'Public route not found'];
                }

                $routeName = $route['name'] ?? 'ROUTE';
                $advisoryData = [
                    'advisory_number' => $advisoryNumber,
                    'name' => $routeName,
                    'route_name' => $routeName,
                    'facility' => 'DCC',
                    'issue_date' => gmdate('Y-m-d H:i:s'),
                    'start_utc' => $route['valid_start_utc'] instanceof DateTime
                        ? $route['valid_start_utc']->format('c')
                        : $route['valid_start_utc'],
                    'end_utc' => $route['valid_end_utc'] instanceof DateTime
                        ? $route['valid_end_utc']->format('c')
                        : $route['valid_end_utc'],
                    'cancel_text' => strtoupper($routeName) . ' HAS BEEN CANCELLED.',
                    'reason' => $reason
                ];
            } else {
                // Fetch from tmi_reroutes (TMI database uses 'reroute_id')
                $stmt = $tmiConn->prepare("
                    SELECT * FROM dbo.tmi_reroutes WHERE reroute_id = :id
                ");
                $stmt->execute([':id' => $entityId]);
                $reroute = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$reroute) {
                    return ['success' => false, 'error' => 'Reroute not found'];
                }

                $routeName = $reroute['name'] ?? 'REROUTE';
                $advisoryData = [
                    'advisory_number' => $advisoryNumber,
                    'name' => $routeName,
                    'route_name' => $routeName,
                    'facility' => 'DCC',
                    'issue_date' => gmdate('Y-m-d H:i:s'),
                    'start_utc' => $reroute['start_utc'] instanceof DateTime
                        ? $reroute['start_utc']->format('c')
                        : $reroute['start_utc'],
                    'end_utc' => $reroute['end_utc'] instanceof DateTime
                        ? $reroute['end_utc']->format('c')
                        : $reroute['end_utc'],
                    'cancel_text' => strtoupper($routeName) . ' HAS BEEN CANCELLED.',
                    'reason' => $reason
                ];
            }

            $result = $tmiDiscord->postRerouteCancellation($advisoryData, $channel);

        } elseif ($entityType === 'ADVISORY') {
            // Fetch advisory details
            $stmt = $tmiConn->prepare("
                SELECT * FROM dbo.tmi_advisories WHERE advisory_id = :id
            ");
            $stmt->execute([':id' => $entityId]);
            $advisory = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$advisory) {
                return ['success' => false, 'error' => 'Advisory not found'];
            }

            $advType = strtoupper($advisory['advisory_type'] ?? '');

            // Check if it's a hotline advisory
            if (strpos($advType, 'HOTLINE') !== false) {
                $advisoryData = [
                    'advisory_number' => $advisoryNumber,
                    'hotline_name' => $advisory['name'] ?? 'HOTLINE',
                    'name' => $advisory['name'] ?? 'HOTLINE',
                    'facility' => $advisory['facility'] ?? 'DCC',
                    'issue_date' => gmdate('Y-m-d H:i:s'),
                    'start_utc' => $advisory['start_utc'] instanceof DateTime
                        ? $advisory['start_utc']->format('c')
                        : $advisory['start_utc'],
                    'end_utc' => gmdate('c'), // Terminated now
                    'constrained_facilities' => $advisory['constrained_facilities'] ?? '',
                    'terminated' => true,
                    'deactivated' => true
                ];

                $result = $tmiDiscord->postHotlineAdvisory($advisoryData, $channel);
            }
            // Other advisory types can be added here
        }

        if ($result !== null) {
            return [
                'success' => true,
                'channel' => $channel,
                'message_id' => $result['id'] ?? null
            ];
        }

        return ['success' => false, 'error' => 'No advisory posted'];

    } catch (Exception $e) {
        error_log("Failed to post cancellation advisory: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Log cancel event to tmi_events table
 */
function logCancelEvent($conn, $entityType, $entityId, $reason, $actorId, $actorName) {
    try {
        $sql = "INSERT INTO dbo.tmi_events (
                    entity_type, entity_id, event_type, event_detail,
                    source_type, actor_id, actor_name, actor_ip
                ) VALUES (
                    :entity_type, :entity_id, 'CANCELLED', :reason,
                    'WEB', :actor_id, :actor_name, :actor_ip
                )";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':reason' => substr($reason, 0, 64),
            ':actor_id' => $actorId,
            ':actor_name' => $actorName,
            ':actor_ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        // Also post to Discord coordination log
        require_once __DIR__ . '/../../../load/coordination_log.php';
        $action = strtoupper($entityType) . '_CANCELLED';
        logToCoordinationChannel($conn, null, $action, [
            'entry_type' => $entityType,
            'entry_id' => $entityId,
            'reason' => $reason,
            'user_cid' => $actorId,
            'user_name' => $actorName
        ]);
    } catch (Exception $e) {
        // Log failure but don't fail the cancel
        error_log("Failed to log cancel event: " . $e->getMessage());
    }
}

/**
 * Post CANCEL NTML entry to Discord for coordinated TMIs
 * Format: {post time (UTC)}    CANCEL {TMI} {original valid start}-{time now (UTC)} {req}:{prov}
 *
 * Only posts if:
 * - Entry was coordinated (has proposal_id)
 * - Entry is ACTIVE status
 * - Entry type requires coordination (MIT, MINIT, APREQ, CFR, TBM, TBFM, STOP)
 * - Being cancelled before valid_until
 */
function postCancellationNtmlEntry($conn, $entryData, $userName) {
    try {
        $entryType = strtoupper($entryData['entry_type'] ?? '');

        // Check if this entry type needs coordination
        if (!in_array($entryType, PERTI_COORDINATED_ENTRY_TYPES)) {
            return null; // Not a coordinated type, no need to post CANCEL
        }

        // Check if it was coordinated (has a linked proposal)
        if (empty($entryData['proposal_id'])) {
            return null; // Not coordinated, no need to post CANCEL
        }

        // Check if it was ACTIVE (only post CANCEL for entries that went active)
        $status = strtoupper($entryData['status'] ?? '');
        if ($status !== 'ACTIVE' && $status !== 'SCHEDULED') {
            return null; // Wasn't active, no need to post CANCEL
        }

        // Check if cancelled before valid_until
        $validUntil = $entryData['valid_until'] ?? null;
        $now = new DateTime('now', new DateTimeZone('UTC'));

        if ($validUntil) {
            $validUntilDt = $validUntil instanceof DateTime
                ? $validUntil
                : new DateTime($validUntil, new DateTimeZone('UTC'));

            if ($now >= $validUntilDt) {
                return null; // Already past valid_until, no need for CANCEL
            }
        }

        // Build CANCEL NTML entry text
        // Format: {dd/hhmm}    CANCEL {ctl_element} {flow} via {fix} {value}{type} {start}-{cancel} {req}:{prov}
        $postTime = $now->format('d/Hi'); // dd/HHmm format for publish time
        $cancelTime = $now->format('Hi'); // HHmm format for end time

        // Get fields for building the cancel line
        $ctlElement = strtoupper($entryData['ctl_element'] ?? '');
        $flowType = strtolower($entryData['flow_type'] ?? '');
        $via = strtoupper($entryData['via'] ?? $entryData['condition_text'] ?? '');
        $restrictionValue = $entryData['restriction_value'] ?? '';
        $reqFac = strtoupper($entryData['req_fac'] ?? $entryData['requesting_facility'] ?? 'DCC');
        $provFac = strtoupper($entryData['prov_fac'] ?? $entryData['providing_facility'] ?? '');

        // Get original start time
        $validFrom = $entryData['valid_from'] ?? null;
        $originalStart = '0000';
        if ($validFrom) {
            $validFromDt = $validFrom instanceof DateTime
                ? $validFrom
                : new DateTime($validFrom, new DateTimeZone('UTC'));
            $originalStart = $validFromDt->format('Hi');
        }

        // Build restriction: {ctl_element} {flow} via {fix} {value}{type}
        $restriction = $ctlElement;
        if ($flowType) {
            $restriction .= " {$flowType}";
        }
        if ($via) {
            $restriction .= " via {$via}";
        }
        if ($restrictionValue) {
            $restriction .= " {$restrictionValue}{$entryType}";
        } else {
            $restriction .= " {$entryType}";
        }

        // Build facilities
        $facilities = $reqFac;
        if ($provFac) {
            $facilities = "{$reqFac}:{$provFac}";
        }

        // Build the CANCEL line
        $cancelLine = "{$postTime}    CANCEL {$restriction} {$originalStart}-{$cancelTime} {$facilities}";

        // Post to Discord NTML channel
        require_once __DIR__ . '/../../../load/discord/DiscordAPI.php';

        if (!class_exists('DiscordAPI')) {
            error_log("DiscordAPI class not found for CANCEL posting");
            return ['success' => false, 'error' => 'DiscordAPI not available'];
        }

        $discord = new DiscordAPI();

        // Format for Discord code block
        $discordContent = "```\n{$cancelLine}\n```";

        // Post to ntml channel (production channel for cancellations)
        $result = $discord->createMessage('ntml', ['content' => $discordContent]);

        if ($result && isset($result['id'])) {
            return [
                'success' => true,
                'message_id' => $result['id'],
                'content' => $cancelLine
            ];
        }

        return ['success' => false, 'error' => 'Discord post failed'];

    } catch (Exception $e) {
        error_log("Failed to post CANCEL NTML entry: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
