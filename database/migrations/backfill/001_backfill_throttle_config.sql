-- Migration: Backfill resource metering configuration
-- Target: VATSIM_ADL (Azure SQL)
-- Idempotent: MERGE pattern (safe to re-run)
--
-- Adds BACKFILL_* runtime config keys to adl_archive_config.
-- These control adaptive throttling of the hibernation recovery
-- backfill script (scripts/backfill/hibernation_recovery.php)
-- to prevent resource exhaustion while live GIS daemons run.

SET NOCOUNT ON;

PRINT '=== Backfill Throttle Config Migration ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);

MERGE INTO dbo.adl_archive_config AS target
USING (VALUES
    ('BACKFILL_THROTTLE_MODE',               'adaptive', 'Throttle mode: adaptive, fixed, off'),
    ('BACKFILL_FIXED_DELAY_MS',              '2000',     'Fixed inter-batch delay in ms'),
    ('BACKFILL_ADAPTIVE_BASE_DELAY_MS',      '500',      'Minimum adaptive inter-batch delay in ms'),
    ('BACKFILL_ADAPTIVE_MAX_DELAY_MS',       '30000',    'Maximum adaptive backoff delay in ms (30s ceiling)'),
    ('BACKFILL_BACKOFF_MULTIPLIER',          '2.0',      'Exponential backoff multiplier when pressure detected'),
    ('BACKFILL_PRESSURE_CONN_THRESHOLD',     '40',       'Active DB connection count triggering backoff'),
    ('BACKFILL_PRESSURE_BLOCKING_THRESHOLD', '2',        'Blocking session count triggering backoff'),
    ('BACKFILL_PRESSURE_LATENCY_THRESHOLD_MS', '500',    'DB round-trip latency in ms triggering backoff'),
    ('BACKFILL_PRESSURE_MEMORY_PCT_THRESHOLD', '85',     'System memory usage percent triggering backoff'),
    ('BACKFILL_BATCH_SIZE_FLOOR',            '10',       'Minimum adaptive batch size under pressure'),
    ('BACKFILL_BATCH_SIZE_CEILING',          '200',      'Maximum adaptive batch size when ramping up'),
    ('BACKFILL_LOG_METRICS_INTERVAL',        '5',        'Log resource metrics every N batches')
) AS source (config_key, config_value, description)
ON target.config_key = source.config_key
WHEN NOT MATCHED THEN
    INSERT (config_key, config_value, description)
    VALUES (source.config_key, source.config_value, source.description);

PRINT 'Merged ' + CAST(@@ROWCOUNT AS VARCHAR) + ' backfill config rows';

-- Verify
SELECT config_key, config_value, description
FROM dbo.adl_archive_config
WHERE config_key LIKE 'BACKFILL_%'
ORDER BY config_key;

PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
