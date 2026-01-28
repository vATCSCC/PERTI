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
 * Includes UTC timestamp, Discord long format (:f), and relative format (:R)
 */
function formatCoordinationMessage($proposalId, $action, $details, $timestamp) {
    $userName = $details['user_name'] ?? $details['created_by_name'] ?? 'System';

    // Get Unix timestamp for Discord formatting
    $unixTime = time();

    // Build timestamp section: UTC + Discord :f (long) + Discord :R (relative)
    $discordLong = "<t:{$unixTime}:f>";      // e.g., "January 28, 2026 1:36 PM"
    $discordRelative = "<t:{$unixTime}:R>";  // e.g., "2 minutes ago"

    $parts = ["`[{$timestamp}]` {$discordLong} ({$discordRelative})"];

    switch ($action) {
        case 'TMI_CREATED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $entryId = $details['entry_id'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $facility = $details['facility'] ?? '';
            $parts[] = "📌 **TMI CREATED** {$entryType}";
            if ($entryId) $parts[] = "ID #{$entryId}";
            if ($element) $parts[] = "| {$element}";
            if ($facility) $parts[] = "| Fac: {$facility}";
            $parts[] = "| by {$userName}";
            break;

        case 'TMI_EDITED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $entryId = $details['entry_id'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $facility = $details['facility'] ?? '';
            $parts[] = "✏️ **TMI EDITED** {$entryType}";
            if ($entryId) $parts[] = "ID #{$entryId}";
            if ($element) $parts[] = "| {$element}";
            if ($facility) $parts[] = "| Fac: {$facility}";
            $parts[] = "| by {$userName}";
            break;

        case 'TMI_CANCELLED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $entryId = $details['entry_id'] ?? '';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "🗑️ **TMI CANCELLED** {$entryType}";
            if ($entryId) $parts[] = "ID #{$entryId}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| by {$userName}";
            break;

        case 'ADVISORY_CREATED':
            $advType = $details['advisory_type'] ?? 'Advisory';
            $advNum = $details['advisory_number'] ?? '';
            $facility = $details['facility'] ?? '';
            $parts[] = "📢 **ADVISORY CREATED** {$advType}";
            if ($advNum) $parts[] = "#{$advNum}";
            if ($facility) $parts[] = "| Fac: {$facility}";
            $parts[] = "| by {$userName}";
            break;

        case 'ADVISORY_EDITED':
            $advType = $details['advisory_type'] ?? 'Advisory';
            $advNum = $details['advisory_number'] ?? '';
            $facility = $details['facility'] ?? '';
            $parts[] = "✏️ **ADVISORY EDITED** {$advType}";
            if ($advNum) $parts[] = "#{$advNum}";
            if ($facility) $parts[] = "| Fac: {$facility}";
            $parts[] = "| by {$userName}";
            break;

        case 'ADVISORY_CANCELLED':
            $advType = $details['advisory_type'] ?? 'Advisory';
            $advNum = $details['advisory_number'] ?? '';
            $parts[] = "🗑️ **ADVISORY CANCELLED** {$advType}";
            if ($advNum) $parts[] = "#{$advNum}";
            $parts[] = "| by {$userName}";
            break;

        case 'REROUTE_CREATED':
            $rerouteId = $details['reroute_id'] ?? '';
            $routeName = $details['route_name'] ?? '';
            $origin = $details['origin'] ?? '';
            $dest = $details['destination'] ?? '';
            $parts[] = "🛣️ **REROUTE CREATED**";
            if ($rerouteId) $parts[] = "ID #{$rerouteId}";
            if ($routeName) $parts[] = "| {$routeName}";
            if ($origin && $dest) $parts[] = "| {$origin}→{$dest}";
            $parts[] = "| by {$userName}";
            break;

        case 'REROUTE_CANCELLED':
            $rerouteId = $details['reroute_id'] ?? '';
            $routeName = $details['route_name'] ?? '';
            $parts[] = "🗑️ **REROUTE CANCELLED**";
            if ($rerouteId) $parts[] = "ID #{$rerouteId}";
            if ($routeName) $parts[] = "| {$routeName}";
            $parts[] = "| by {$userName}";
            break;

        case 'REROUTE_EDITED':
            $rerouteId = $details['reroute_id'] ?? '';
            $routeName = $details['route_name'] ?? '';
            $parts[] = "✏️ **REROUTE EDITED**";
            if ($rerouteId) $parts[] = "ID #{$rerouteId}";
            if ($routeName) $parts[] = "| {$routeName}";
            $parts[] = "| by {$userName}";
            break;

        case 'PROGRAM_CREATED':
            $programId = $details['program_id'] ?? '';
            $programName = $details['program_name'] ?? 'Program';
            $element = $details['ctl_element'] ?? '';
            $airports = $details['airports'] ?? '';
            $parts[] = "🛑 **PROGRAM CREATED** {$programName}";
            if ($programId) $parts[] = "ID #{$programId}";
            if ($element) $parts[] = "| {$element}";
            if ($airports) $parts[] = "| Arpts: {$airports}";
            $parts[] = "| by {$userName}";
            break;

        case 'PROGRAM_EDITED':
            $programId = $details['program_id'] ?? '';
            $programName = $details['program_name'] ?? 'Program';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "✏️ **PROGRAM EDITED** {$programName}";
            if ($programId) $parts[] = "ID #{$programId}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| by {$userName}";
            break;

        case 'PROGRAM_CANCELLED':
            $programId = $details['program_id'] ?? '';
            $programName = $details['program_name'] ?? 'Program';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "🗑️ **PROGRAM CANCELLED** {$programName}";
            if ($programId) $parts[] = "ID #{$programId}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| by {$userName}";
            break;

        case 'PUBLICROUTE_CREATED':
            $routeId = $details['route_id'] ?? '';
            $routeName = $details['route_name'] ?? '';
            $parts[] = "🛣️ **PUBLIC ROUTE CREATED**";
            if ($routeId) $parts[] = "ID #{$routeId}";
            if ($routeName) $parts[] = "| {$routeName}";
            $parts[] = "| by {$userName}";
            break;

        case 'PUBLICROUTE_EDITED':
            $routeId = $details['route_id'] ?? '';
            $routeName = $details['route_name'] ?? '';
            $parts[] = "✏️ **PUBLIC ROUTE EDITED**";
            if ($routeId) $parts[] = "ID #{$routeId}";
            if ($routeName) $parts[] = "| {$routeName}";
            $parts[] = "| by {$userName}";
            break;

        case 'PUBLICROUTE_CANCELLED':
            $routeId = $details['route_id'] ?? '';
            $routeName = $details['route_name'] ?? '';
            $parts[] = "🗑️ **PUBLIC ROUTE CANCELLED**";
            if ($routeId) $parts[] = "ID #{$routeId}";
            if ($routeName) $parts[] = "| {$routeName}";
            $parts[] = "| by {$userName}";
            break;

        // Proposal coordination actions
        case 'PROPOSAL_SUBMITTED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $element = $details['ctl_element'] ?? '';
            $facilities = $details['facilities'] ?? '';
            $parts[] = "📝 **PROPOSAL SUBMITTED** {$entryType}";
            if ($proposalId) $parts[] = "Prop #{$proposalId}";
            if ($element) $parts[] = "| {$element}";
            if ($facilities) $parts[] = "| To: {$facilities}";
            $parts[] = "| by {$userName}";
            break;

        case 'FACILITY_APPROVE':
            $facility = $details['facility'] ?? '';
            $entryType = $details['entry_type'] ?? 'TMI';
            $parts[] = "✅ **FACILITY APPROVED**";
            if ($proposalId) $parts[] = "Prop #{$proposalId}";
            if ($facility) $parts[] = "| {$facility}";
            $parts[] = "| by {$userName}";
            break;

        case 'FACILITY_DENY':
            $facility = $details['facility'] ?? '';
            $reason = $details['reason'] ?? '';
            $parts[] = "❌ **FACILITY DENIED**";
            if ($proposalId) $parts[] = "Prop #{$proposalId}";
            if ($facility) $parts[] = "| {$facility}";
            if ($reason) $parts[] = "| Reason: {$reason}";
            $parts[] = "| by {$userName}";
            break;

        case 'DCC_OVERRIDE':
            $facility = $details['facility'] ?? '';
            $overrideType = $details['override_type'] ?? 'approve';
            $parts[] = "⚡ **DCC OVERRIDE** ({$overrideType})";
            if ($proposalId) $parts[] = "Prop #{$proposalId}";
            if ($facility) $parts[] = "| {$facility}";
            $parts[] = "| by {$userName}";
            break;

        case 'PROPOSAL_APPROVED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $entryId = $details['entry_id'] ?? '';
            $parts[] = "🎉 **PROPOSAL FULLY APPROVED** {$entryType}";
            if ($proposalId) $parts[] = "Prop #{$proposalId}";
            if ($entryId) $parts[] = "→ TMI #{$entryId}";
            break;

        case 'PROPOSAL_DENIED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $facility = $details['denied_by_facility'] ?? '';
            $parts[] = "🚫 **PROPOSAL DENIED** {$entryType}";
            if ($proposalId) $parts[] = "Prop #{$proposalId}";
            if ($facility) $parts[] = "| Denied by: {$facility}";
            break;

        case 'PROPOSAL_EXPIRED':
            $parts[] = "⏰ **PROPOSAL EXPIRED**";
            if ($proposalId) $parts[] = "Prop #{$proposalId}";
            break;

        case 'PROPOSAL_EDITED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $element = $details['ctl_element'] ?? '';
            $parts[] = "✏️ **PROPOSAL EDITED** {$entryType}";
            if ($proposalId) $parts[] = "Prop #{$proposalId}";
            if ($element) $parts[] = "| {$element}";
            $parts[] = "| by {$userName}";
            break;

        case 'PROPOSAL_REOPENED':
            $entryType = $details['entry_type'] ?? 'TMI';
            $parts[] = "🔄 **PROPOSAL REOPENED** {$entryType}";
            if ($proposalId) $parts[] = "Prop #{$proposalId}";
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
