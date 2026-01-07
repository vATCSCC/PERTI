<?php

// api/util/create_history_table.php
// Creates the rate history table if it doesn't exist

include("../../load/config.php");
include("../../load/connect.php");

header('Content-Type: application/json');

if (!$conn_adl) {
    echo json_encode(['error' => 'ADL database not available']);
    exit();
}

// Check if table exists
$checkSql = "SELECT 1 FROM sys.tables WHERE name = 'airport_config_rate_history'";
$checkResult = sqlsrv_query($conn_adl, $checkSql);

if ($checkResult && sqlsrv_fetch($checkResult)) {
    echo json_encode(['status' => 'exists', 'message' => 'Table already exists']);
    exit();
}

// Create the table
$createSql = "
    CREATE TABLE dbo.airport_config_rate_history (
        history_id BIGINT IDENTITY(1,1) PRIMARY KEY,
        config_id INT NOT NULL,
        source VARCHAR(8) NOT NULL,
        weather VARCHAR(8) NOT NULL,
        rate_type VARCHAR(4) NOT NULL,
        old_value SMALLINT NULL,
        new_value SMALLINT NULL,
        change_type VARCHAR(8) NOT NULL,
        changed_by_cid INT NULL,
        changed_utc DATETIME2 DEFAULT GETUTCDATE(),
        notes VARCHAR(256) NULL,
        CONSTRAINT FK_rate_history_config FOREIGN KEY (config_id)
            REFERENCES dbo.airport_config(config_id) ON DELETE CASCADE,
        INDEX IX_rate_history_config (config_id),
        INDEX IX_rate_history_changed (changed_utc DESC)
    )
";

$result = sqlsrv_query($conn_adl, $createSql);

if ($result === false) {
    $err = sqlsrv_errors();
    echo json_encode(['error' => 'Failed to create table', 'details' => $err]);
} else {
    echo json_encode(['status' => 'created', 'message' => 'Table created successfully']);
}

?>
