-- ============================================================================
-- Migration 050: Purge Ephemeral Modeling Programs
-- Database: VATSIM_TMI
-- Date: 2026-03-29
-- Author: jpeterson (admin creds required -- adl_api_user lacks DELETE on some tables)
--
-- Purpose: Hard-delete all programs that were never proposed or activated
-- (status MODELING or PURGED with activated_at IS NULL). These are ephemeral
-- workspace items that should not persist as historical records.
--
-- After cleanup, reseed IDENTITY to max current program_id so new programs
-- get sequential IDs without gaps.
--
-- Run via SSMS with jpeterson admin creds against VATSIM_TMI.
-- ============================================================================

BEGIN TRANSACTION;

-- Collect IDs of programs to delete: never activated, in MODELING or PURGED status
-- (CANCELLED programs might have been PROPOSED/ACTIVE first, so we keep those)
DECLARE @ids TABLE (program_id INT);
INSERT INTO @ids
SELECT program_id FROM dbo.tmi_programs
WHERE activated_at IS NULL
  AND status IN ('MODELING', 'PURGED');

DECLARE @delete_count INT = (SELECT COUNT(*) FROM @ids);
PRINT 'Programs to delete: ' + CAST(@delete_count AS VARCHAR(10));

IF @delete_count > 0
BEGIN
    -- 1. Clear non-cascading FK refs (order matters: tmi_flight_control before tmi_slots cascade)
    DELETE fc FROM dbo.tmi_flight_control fc
    INNER JOIN @ids i ON fc.program_id = i.program_id;
    PRINT 'Deleted tmi_flight_control rows: ' + CAST(@@ROWCOUNT AS VARCHAR(10));

    DELETE a FROM dbo.tmi_advisories a
    INNER JOIN @ids i ON a.program_id = i.program_id;
    PRINT 'Deleted tmi_advisories rows: ' + CAST(@@ROWCOUNT AS VARCHAR(10));

    DELETE cs FROM dbo.ctp_sessions cs
    INNER JOIN @ids i ON cs.program_id = i.program_id;
    PRINT 'Deleted ctp_sessions rows: ' + CAST(@@ROWCOUNT AS VARCHAR(10));

    -- 2. Clear self-referential FKs
    UPDATE p SET parent_program_id = NULL
    FROM dbo.tmi_programs p
    INNER JOIN @ids i ON p.parent_program_id = i.program_id;
    PRINT 'Cleared parent_program_id refs: ' + CAST(@@ROWCOUNT AS VARCHAR(10));

    UPDATE p SET superseded_by_id = NULL
    FROM dbo.tmi_programs p
    INNER JOIN @ids i ON p.superseded_by_id = i.program_id;
    PRINT 'Cleared superseded_by_id refs: ' + CAST(@@ROWCOUNT AS VARCHAR(10));

    -- 3. Clean orphaned events (no FK constraint, won't block delete)
    DELETE e FROM dbo.tmi_events e
    INNER JOIN @ids i ON e.program_id = i.program_id;
    PRINT 'Deleted tmi_events rows: ' + CAST(@@ROWCOUNT AS VARCHAR(10));

    -- 4. Delete programs (CASCADE handles tmi_slots, tmi_flight_list, tmi_popup_queue, tmi_program_coordination_log)
    DELETE p FROM dbo.tmi_programs p
    INNER JOIN @ids i ON p.program_id = i.program_id;
    PRINT 'Deleted tmi_programs rows: ' + CAST(@@ROWCOUNT AS VARCHAR(10));
END

COMMIT;

-- 5. Reseed IDENTITY to max current program_id
DECLARE @max_id INT;
SELECT @max_id = ISNULL(MAX(program_id), 0) FROM dbo.tmi_programs;
DBCC CHECKIDENT('dbo.tmi_programs', RESEED, @max_id);

-- Summary
SELECT @max_id AS new_identity_seed,
       (SELECT COUNT(*) FROM dbo.tmi_programs) AS remaining_programs,
       @delete_count AS programs_deleted;
