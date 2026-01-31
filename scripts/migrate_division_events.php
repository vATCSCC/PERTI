<?php
/**
 * Division Events Migration Script
 *
 * Creates the division_events table in VATSIM_ADL database.
 * Must be run with database admin credentials.
 *
 * Usage:
 *   CLI: php migrate_division_events.php
 *   Web: Access with ?run=1 query param (requires admin)
 *
 * Environment vars (optional):
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS - Override default credentials
 *
 * @package PERTI\Scripts
 * @version 1.0.0
 */

$isCli = php_sapi_name() === 'cli';

// Database configuration - uses Azure SQL Server
$config = [
    'host' => getenv('DB_HOST') ?: 'vatsim.database.windows.net',
    'name' => getenv('DB_NAME') ?: 'VATSIM_ADL',
    'user' => getenv('DB_USER') ?: 'adl_api_user',
    'pass' => getenv('DB_PASS') ?: '***REMOVED***',
];

$migration = "
-- Division Events Table
-- Stores upcoming/scheduled events from VATUSA, VATCAN, and VATSIM APIs

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'division_events')
BEGIN
    CREATE TABLE dbo.division_events (
        event_id            INT IDENTITY(1,1) PRIMARY KEY,

        -- Source identification
        source              NVARCHAR(16) NOT NULL,          -- 'VATUSA', 'VATCAN', 'VATSIM'
        external_id         NVARCHAR(64) NOT NULL,          -- Original ID from source API

        -- Event details
        event_name          NVARCHAR(256) NOT NULL,
        event_type          NVARCHAR(64) NULL,              -- 'Event', 'FNO', 'Controller Examination', etc.
        event_link          NVARCHAR(512) NULL,             -- URL to event page
        banner_url          NVARCHAR(512) NULL,             -- Event banner image

        -- Timing
        start_utc           DATETIME2 NOT NULL,
        end_utc             DATETIME2 NULL,

        -- Location/scope
        division            NVARCHAR(16) NULL,              -- 'USA', 'CAN', or VATSIM division code
        region              NVARCHAR(16) NULL,              -- VATSIM region (AMAS, EMEA, APAC)
        airports_json       NVARCHAR(MAX) NULL,             -- JSON array of airport ICAOs
        routes_json         NVARCHAR(MAX) NULL,             -- JSON array of routes

        -- Description
        short_description   NVARCHAR(1024) NULL,
        description         NVARCHAR(MAX) NULL,

        -- Sync metadata
        synced_at           DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        created_at          DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at          DATETIME2 NULL,

        -- Unique constraint to prevent duplicates
        CONSTRAINT UQ_division_events_source_external UNIQUE (source, external_id)
    );

    -- Indexes
    CREATE INDEX IX_division_events_start ON dbo.division_events (start_utc);
    CREATE INDEX IX_division_events_source ON dbo.division_events (source);
    CREATE INDEX IX_division_events_division ON dbo.division_events (division);

    PRINT 'Created division_events table';
END
ELSE
BEGIN
    PRINT 'Table division_events already exists';
END
";

function output($msg, $isCli) {
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo "<pre>$msg</pre>";
    }
}

// Web access check
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    if (!isset($_GET['run']) || $_GET['run'] !== '1') {
        echo "<h2>Division Events Migration</h2>";
        echo "<p>This script creates the division_events table.</p>";
        echo "<p><strong>Warning:</strong> Requires database admin credentials.</p>";
        echo "<p><a href='?run=1'>Run Migration</a></p>";
        exit;
    }
}

output("Division Events Migration", $isCli);
output("========================", $isCli);
output("Host: {$config['host']}", $isCli);
output("Database: {$config['name']}", $isCli);
output("User: {$config['user']}", $isCli);
output("", $isCli);

try {
    $dsn = "sqlsrv:server=tcp:{$config['host']},1433;Database={$config['name']};Encrypt=yes;TrustServerCertificate=no";
    $conn = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    output("Connected to database.", $isCli);
    output("Running migration...", $isCli);

    $conn->exec($migration);

    output("Migration completed successfully!", $isCli);

    // Verify
    $check = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'division_events'");
    $exists = $check->fetchColumn() > 0;
    output("Table exists: " . ($exists ? "YES" : "NO"), $isCli);

} catch (PDOException $e) {
    output("ERROR: " . $e->getMessage(), $isCli);

    if (strpos($e->getMessage(), 'permission denied') !== false) {
        output("", $isCli);
        output("The current user doesn't have permission to create tables.", $isCli);
        output("Please run this migration using Azure Portal Query Editor or", $isCli);
        output("connect with an admin account (e.g., via SSMS).", $isCli);
        output("", $isCli);
        output("SQL to run manually:", $isCli);
        output("--------------------", $isCli);
        output($migration, $isCli);
    }

    exit(1);
}
