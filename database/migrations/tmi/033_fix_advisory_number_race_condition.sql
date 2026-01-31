-- =============================================================================
-- Migration: 033_fix_advisory_number_race_condition.sql
-- Database: VATSIM_TMI
-- Purpose: Fix race condition in sp_GetNextAdvisoryNumber
-- Date: 2026-01-31
--
-- Problem: The original SP had a gap between MERGE and SELECT where another
-- transaction could increment the counter, causing duplicate numbers.
--
-- Solution: Use OUTPUT clause to capture the value atomically during MERGE.
-- =============================================================================

USE VATSIM_TMI;
GO

-- Drop and recreate the procedure with atomic OUTPUT
CREATE OR ALTER PROCEDURE dbo.sp_GetNextAdvisoryNumber
    @next_number NVARCHAR(16) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @today DATE = CAST(SYSUTCDATETIME() AS DATE);
    DECLARE @result TABLE (seq_number INT);

    -- MERGE with OUTPUT captures the inserted/updated value atomically
    -- HOLDLOCK ensures serializable isolation to prevent phantom reads
    MERGE dbo.tmi_advisory_sequences WITH (HOLDLOCK) AS target
    USING (SELECT @today AS seq_date) AS source
    ON target.seq_date = source.seq_date
    WHEN MATCHED THEN
        UPDATE SET seq_number = seq_number + 1
    WHEN NOT MATCHED THEN
        INSERT (seq_date, seq_number) VALUES (@today, 1)
    OUTPUT inserted.seq_number INTO @result;

    -- Read from the captured result, not the table
    DECLARE @seq INT;
    SELECT @seq = seq_number FROM @result;

    SET @next_number = CONCAT('ADVZY ', RIGHT('000' + CAST(@seq AS VARCHAR), 3));
END;
GO

PRINT 'Updated sp_GetNextAdvisoryNumber with atomic OUTPUT clause';
GO
