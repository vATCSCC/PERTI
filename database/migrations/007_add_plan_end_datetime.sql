-- Migration: Add event end date/time columns to p_plans table
-- Run this against your Azure SQL database before deploying the code changes

-- Add event_end_date column (nullable, VARCHAR to match event_date format)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('p_plans') AND name = 'event_end_date')
BEGIN
    ALTER TABLE p_plans ADD event_end_date VARCHAR(10) NULL;
    PRINT 'Added event_end_date column';
END
ELSE
BEGIN
    PRINT 'event_end_date column already exists';
END
GO

-- Add event_end_time column (nullable, VARCHAR to match event_start format - hhmm)
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('p_plans') AND name = 'event_end_time')
BEGIN
    ALTER TABLE p_plans ADD event_end_time VARCHAR(4) NULL;
    PRINT 'Added event_end_time column';
END
ELSE
BEGIN
    PRINT 'event_end_time column already exists';
END
GO

-- Optional: Backfill existing records with event_date as end date if desired
-- UPDATE p_plans SET event_end_date = event_date WHERE event_end_date IS NULL;
