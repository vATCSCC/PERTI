<?php
/**
 * Test Script: MultiDiscordAPI
 * 
 * Tests the multi-organization Discord integration.
 * Run from CLI: php scripts/test_multi_discord.php
 * 
 * @package PERTI
 * @subpackage Tests
 * @date 2026-01-27
 */

// Bootstrap
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== MultiDiscordAPI Test Suite ===\n\n";

// Load config
$configPath = __DIR__ . '/../load/config.php';
if (!file_exists($configPath)) {
    echo "ERROR: config.php not found. Copy config.example.php to config.php\n";
    exit(1);
}
require_once $configPath;

// Load MultiDiscordAPI
require_once __DIR__ . '/../load/discord/MultiDiscordAPI.php';

// Initialize
echo "Initializing MultiDiscordAPI...\n";
$multiDiscord = new MultiDiscordAPI();

// Test 1: Configuration Check
echo "\n--- Test 1: Configuration Check ---\n";
if ($multiDiscord->isConfigured()) {
    echo "✓ MultiDiscordAPI is configured\n";
} else {
    echo "✗ MultiDiscordAPI is NOT configured\n";
    echo "  Make sure DISCORD_BOT_TOKEN and DISCORD_ORGANIZATIONS are set in config.php\n";
}

// Test 2: List Organizations
echo "\n--- Test 2: Organization Listing ---\n";
$allOrgs = $multiDiscord->getOrganizations(false);
$enabledOrgs = $multiDiscord->getOrganizations(true);

echo "Total organizations configured: " . count($allOrgs) . "\n";
echo "Enabled organizations: " . count($enabledOrgs) . "\n\n";

foreach ($allOrgs as $code => $config) {
    $status = isset($enabledOrgs[$code]) ? '✓ ENABLED' : '○ disabled';
    $default = !empty($config['default']) ? ' [DEFAULT]' : '';
    $testing = !empty($config['testing_only']) ? ' [TESTING]' : '';
    
    echo "  [{$code}] {$config['name']} - {$status}{$default}{$testing}\n";
    echo "    Region: " . ($config['region'] ?? 'N/A') . "\n";
    echo "    Guild: " . ($config['guild_id'] ?? 'NOT SET') . "\n";
    
    if (!empty($config['channels'])) {
        $channelCount = count(array_filter($config['channels']));
        echo "    Channels configured: {$channelCount}\n";
    }
    echo "\n";
}

// Test 3: Default Organization
echo "--- Test 3: Default Organization ---\n";
$defaultOrg = $multiDiscord->getDefaultOrg();
if ($defaultOrg) {
    echo "✓ Default organization: {$defaultOrg}\n";
} else {
    echo "✗ No default organization set\n";
}

// Test 4: Channel Resolution
echo "\n--- Test 4: Channel Resolution ---\n";
$testChannels = ['ntml', 'advisories', 'ntml_staging', 'advzy_staging'];

foreach ($enabledOrgs as $code => $config) {
    echo "Organization: {$code}\n";
    foreach ($testChannels as $purpose) {
        $channelId = $multiDiscord->getChannelId($code, $purpose);
        if ($channelId) {
            echo "  ✓ {$purpose}: {$channelId}\n";
        } else {
            echo "  ○ {$purpose}: not configured\n";
        }
    }
    echo "\n";
}

// Test 5: Cross-Border Detection
echo "--- Test 5: Cross-Border TMI Detection ---\n";

$testEntries = [
    [
        'name' => 'US Domestic (ZNY → JFK)',
        'entry' => [
            'requesting_facility' => 'ZNY',
            'providing_facility' => 'ZBW',
            'airport' => 'KJFK',
        ],
        'expected_regions' => ['US'],
    ],
    [
        'name' => 'US-Canada Cross-Border (ZBW → CYYZ)',
        'entry' => [
            'requesting_facility' => 'ZBW',
            'providing_facility' => 'CZYZ',
            'airport' => 'CYYZ',
        ],
        'expected_regions' => ['US', 'CA'],
    ],
    [
        'name' => 'Canada to US (CZWG → ZMP)',
        'entry' => [
            'from_facility' => 'CZWG',
            'to_facility' => 'ZMP',
            'airport' => 'KMSP',
        ],
        'expected_regions' => ['US', 'CA'],
    ],
    [
        'name' => 'West Coast Border (ZSE scope)',
        'entry' => [
            'requesting_facility' => 'ZSE',
            'scope_facilities' => 'CZVR,ZSE',
        ],
        'expected_regions' => ['US', 'CA'],
    ],
];

foreach ($testEntries as $test) {
    echo "Test: {$test['name']}\n";
    
    $crossBorderOrgs = $multiDiscord->detectCrossBorderOrgs($test['entry']);
    
    if (!empty($crossBorderOrgs)) {
        echo "  Cross-border orgs detected: " . implode(', ', $crossBorderOrgs) . "\n";
    } else {
        echo "  No cross-border orgs detected (single region TMI)\n";
    }
    
    // Test target determination
    $targets = $multiDiscord->determineTargetOrgs($test['entry'], 'vatcscc', false);
    echo "  Target orgs (non-privileged vatcscc user): " . implode(', ', $targets) . "\n";
    
    $targetsPriv = $multiDiscord->determineTargetOrgs($test['entry'], 'vatcscc', true);
    echo "  Target orgs (privileged user): " . implode(', ', $targetsPriv) . "\n";
    
    echo "\n";
}

// Test 6: Organization Lookup by Guild/Channel
echo "--- Test 6: Reverse Lookup ---\n";

// Test guild lookup
$testGuildId = '358294607974539265';
$orgByGuild = $multiDiscord->findOrgByGuildId($testGuildId);
if ($orgByGuild) {
    echo "✓ Guild {$testGuildId} belongs to: {$orgByGuild}\n";
} else {
    echo "○ Guild {$testGuildId} not found in any org\n";
}

// Test channel lookup
$testChannelId = '358295136398082048';
$orgByChannel = $multiDiscord->findOrgByChannelId($testChannelId);
if ($orgByChannel) {
    echo "✓ Channel {$testChannelId} is {$orgByChannel['org_code']}:{$orgByChannel['channel_purpose']}\n";
} else {
    echo "○ Channel {$testChannelId} not found in any org\n";
}

// Test 7: Organization Summary (for UI)
echo "\n--- Test 7: UI Summary ---\n";
$summary = $multiDiscord->getOrgSummary();
echo "Organizations for UI dropdown:\n";
foreach ($summary as $org) {
    $flags = [];
    if ($org['default']) $flags[] = 'default';
    if ($org['testing_only']) $flags[] = 'testing';
    $flagStr = !empty($flags) ? ' (' . implode(', ', $flags) . ')' : '';
    
    echo "  - {$org['name']} [{$org['code']}] - {$org['region']}{$flagStr}\n";
}

// Test 8: Post to Staging (Dry Run - Skip if no token)
echo "\n--- Test 8: Message Posting (Dry Run) ---\n";
$discordAPI = $multiDiscord->getDiscordAPI();
if (!$discordAPI->isConfigured()) {
    echo "○ Skipping - Discord bot token not configured\n";
    echo "  To test posting, configure DISCORD_BOT_TOKEN in config.php\n";
} else {
    echo "Discord API is configured.\n";
    echo "To test actual posting, uncomment the test code below.\n";
    
    /*
    // UNCOMMENT TO TEST ACTUAL POSTING
    $testMessage = [
        'content' => "```\n" .
            "=== MultiDiscordAPI Test Message ===\n" .
            "Time: " . gmdate('Y-m-d H:i:s') . " UTC\n" .
            "This is a test from the PERTI MultiDiscordAPI test suite.\n" .
            "```"
    ];
    
    // Post to vatcscc_backup staging (safe for testing)
    $results = $multiDiscord->postToStaging(['vatcscc_backup'], 'ntml', $testMessage);
    
    foreach ($results as $orgCode => $result) {
        if ($result['success']) {
            echo "✓ Posted to {$orgCode}: {$result['message_url']}\n";
        } else {
            echo "✗ Failed to post to {$orgCode}: {$result['error']}\n";
        }
    }
    */
}

// Summary
echo "\n=== Test Summary ===\n";
echo "MultiDiscordAPI is " . ($multiDiscord->isConfigured() ? "ready" : "NOT ready") . " for use.\n";
echo "Enabled organizations: " . count($enabledOrgs) . "\n";
echo "Default organization: " . ($defaultOrg ?? 'none') . "\n";

if (count($enabledOrgs) > 0) {
    echo "\nNext steps:\n";
    echo "1. Run migration 016_tmi_discord_posts.sql on VATSIM_TMI database\n";
    echo "2. Proceed to Phase 2: Create unified publisher page\n";
}

echo "\n=== Tests Complete ===\n";
