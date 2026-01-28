<?php
/**
 * Coordination Log Helper
 *
 * Provides logging functions for TMI coordination activities.
 * Logs to both database and Discord #coordination-log channel.
 *
 * @package PERTI
 * @subpackage Load
 * @version 1.0.0
 * @date 2026-01-28
 */

// Discord coordination log channel ID
define('COORDINATION_LOG_CHANNEL', '1466038410962796672');

/**
 * Log an activity to the coordination log
 *
 * @param PDO|null $conn Database connection (optional - will create if null)
 * @param int|null $proposalId Proposal ID (null for non-proposal activities)
 * @param string $action Action type (e.g., SUBMITTED, FACILITY_APPROVE, DCC_DENY, TMI_CREATED, TMI_EDITED)
 * @param array $details Additional details (user_name, facility, etc.)
 */
function logToCoordinationChannel($conn, $proposalId, $action, $details = []) {
    $timestamp = gmdate('Y-m-d H:i:s') . 'Z';

    try {
        // 1. Save to database if connection provided
        if ($conn) {
            $sql = "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_coordination_log')
                    CREATE TABLE dbo.tmi_coordination_log (
                        log_id INT IDENTITY(1,1) PRIMARY KEY,
                        proposal_id INT,
                        action VARCHAR(50) NOT NULL,
                        details NVARCHAR(MAX),
                        created_at DATETIME2 DEFAULT SYSUTCDATETIME()
                    )";
            $conn->exec($sql);

            $insertSql = "INSERT INTO dbo.tmi_coordination_log (proposal_id, action, details)
                          VALUES (:prop_id, :action, :details)";
            $stmt = $conn->prepare($insertSql);
            $stmt->execute([
                ':prop_id' => $proposalId,
                ':action' => $action,
                ':details' => json_encode($details)
            ]);
        }

        // 2. Post to Discord
        $logMessage = formatCoordinationMessage($proposalId, $action, $details, $timestamp);
        postToCoordinationChannel($logMessage);

    } catch (Exception $e) {
        error_log("Failed to log coordination activity: " . $e->getMessage());
    }
}

/**
 * Format a coordination log message for Discord
 */
function formatCoordinationMessage($proposalId, $action, $details, $timestamp) {
    $userName = $details['user_name'] ?? $details['created_by_name'] ?? 'System';

    $parts = ["`[{$timestamp}]`"];

    switch ($action) {
        case 'TMI_CREATED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "📌 **TMI CREATED** {$entryType}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| by {$userName}";
            break;

        case 'TMI_EDITED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $entryId = $details['entry_id'] ?? '';
            $parts[] = "✏️ **TMI EDITED** {$entryType}";
            if ($entryId) $parts[] = "| #{$entryId}";
            $parts[] = "| by {$userName}";
            break;

        case 'TMI_CANCELLED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $entryId = $details['entry_id'] ?? '';
            $parts[] = "🗑️ **TMI CANCELLED** {$entryType}";
            if ($entryId) $parts[] = "| #{$entryId}";
            $parts[] = "| by {$userName}";
            break;

        case 'ADVISORY_CREATED':
            $advType = $details['advisory_type'] ?? 'Advisory';
            $advNum = $details['advisory_number'] ?? '';
            $parts[] = "📢 **ADVISORY CREATED** {$advType}";
            if ($advNum) $parts[] = "| #{$advNum}";
            $parts[] = "| by {$userName}";
            break;

        case 'ADVISORY_EDITED':
            $advType = $details['advisory_type'] ?? 'Advisory';
            $advNum = $details['advisory_number'] ?? '';
            $parts[] = "✏️ **ADVISORY EDITED** {$advType}";
            if ($advNum) $parts[] = "| #{$advNum}";
            $parts[] = "| by {$userName}";
            break;

        case 'ADVISORY_CANCELLED':
            $advType = $details['advisory_type'] ?? 'Advisory';
            $advNum = $details['advisory_number'] ?? '';
            $parts[] = "🗑️ **ADVISORY CANCELLED** {$advType}";
            if ($advNum) $parts[] = "| #{$advNum}";
            $parts[] = "| by {$userName}";
            break;

        case 'REROUTE_CREATED':
            $routeName = $details['route_name'] ?? '';
            $parts[] = "🛣️ **REROUTE CREATED**";
            if ($routeName) $parts[] = "| {$routeName}";
            $parts[] = "| by {$userName}";
            break;

        case 'REROUTE_CANCELLED':
            $routeName = $details['route_name'] ?? '';
            $parts[] = "🗑️ **REROUTE CANCELLED**";
            if ($routeName) $parts[] = "| {$routeName}";
            $parts[] = "| by {$userName}";
            break;

        default:
            $parts[] = "📋 **{$action}**";
            if ($proposalId) $parts[] = "| Proposal #{$proposalId}";
            if ($userName) $parts[] = "| by {$userName}";
    }

    return implode(' ', $parts);
}

/**
 * Post a message to the Discord coordination log channel
 */
function postToCoordinationChannel($message) {
    try {
        require_once __DIR__ . '/discord/DiscordAPI.php';

        $discord = new DiscordAPI();
        if (!$discord->isConfigured()) {
            return;
        }

        $discord->createMessage(COORDINATION_LOG_CHANNEL, [
            'content' => $message
        ]);
    } catch (Exception $e) {
        error_log("Failed to post to coordination log: " . $e->getMessage());
    }
}
