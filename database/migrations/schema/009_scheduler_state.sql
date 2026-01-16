-- ============================================================================
-- Migration: Create scheduler_state table for event-driven scheduling
--
-- Purpose: Track when the splits scheduler should next run, avoiding
--          unnecessary polling. The scheduler only runs when:
--          1. It's time (next_run_at <= NOW)
--          2. A split is created/updated (triggered by configs.php)
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'scheduler_state' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE dbo.scheduler_state (
        id INT NOT NULL DEFAULT 1 PRIMARY KEY,  -- Single row table
        next_run_at DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        last_run_at DATETIME2 NULL,
        last_tier INT NOT NULL DEFAULT 3,
        last_activated INT NOT NULL DEFAULT 0,
        last_deactivated INT NOT NULL DEFAULT 0,
        CONSTRAINT CK_scheduler_state_single_row CHECK (id = 1)
    );

    -- Insert the initial state row
    INSERT INTO dbo.scheduler_state (id, next_run_at, last_tier)
    VALUES (1, GETUTCDATE(), 3);

    PRINT 'Created table: dbo.scheduler_state';
END
ELSE
BEGIN
    PRINT 'Table dbo.scheduler_state already exists';
END
GO
