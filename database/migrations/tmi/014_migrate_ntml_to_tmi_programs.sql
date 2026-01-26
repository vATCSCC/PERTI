-- ============================================================================
-- Migration 014: Migrate data from VATSIM_ADL.ntml to VATSIM_TMI.tmi_programs
-- 
-- Purpose: Copy all Ground Stop programs from the old ntml table to the new
--          tmi_programs table, enabling unified GDT management in VATSIM_TMI.
--
-- Prerequisites:
--   - Migration 013 must be run first (adds scope/exemption columns)
--   - Both databases must be accessible from this session
--
-- Run on: VATSIM_TMI database
-- Server: vatsim.database.windows.net
-- 
-- Date: 2026-01-26
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Starting Migration 014: Migrate NTML to TMI_PROGRAMS ===';
PRINT '';

-- ============================================================================
-- PART 0: Verify source data exists
-- ============================================================================
PRINT 'Part 0: Checking source data...';

DECLARE @source_count INT;
SELECT @source_count = COUNT(*) FROM VATSIM_ADL.dbo.ntml;
PRINT '  Source records in VATSIM_ADL.dbo.ntml: ' + CAST(@source_count AS NVARCHAR(10));

DECLARE @target_count INT;
SELECT @target_count = COUNT(*) FROM dbo.tmi_programs;
PRINT '  Existing records in VATSIM_TMI.dbo.tmi_programs: ' + CAST(@target_count AS NVARCHAR(10));
PRINT '';

-- ============================================================================
-- PART 1: Clear test data from tmi_programs (optional - only programs 1-10)
-- ============================================================================
PRINT 'Part 1: Clearing test data (program_id <= 10)...';

DELETE FROM dbo.tmi_programs WHERE program_id <= 10;
PRINT '  Deleted ' + CAST(@@ROWCOUNT AS NVARCHAR(10)) + ' test records';
PRINT '';

-- ============================================================================
-- PART 2: Enable IDENTITY_INSERT for bulk copy
-- ============================================================================
PRINT 'Part 2: Migrating data...';

SET IDENTITY_INSERT dbo.tmi_programs ON;

-- ============================================================================
-- PART 3: Insert data from ntml with column mapping
-- ============================================================================

INSERT INTO dbo.tmi_programs (
    program_id,
    program_guid,
    ctl_element,
    element_type,
    program_type,
    program_name,
    adv_number,
    start_utc,
    end_utc,
    cumulative_start,
    cumulative_end,
    status,
    is_proposed,
    is_active,
    program_rate,
    reserve_rate,
    delay_limit_min,
    target_delay_mult,
    rates_hourly_json,
    reserve_hourly_json,
    scope_type,
    scope_tier,
    scope_distance_nm,
    scope_json,
    exemptions_json,
    exempt_airborne,
    exempt_within_min,
    flt_incl_carrier,
    flt_incl_type,
    flt_incl_fix,
    impacting_condition,
    cause_text,
    comments,
    prob_extension,
    revision_number,
    parent_program_id,
    total_flights,
    controlled_flights,
    exempt_flights,
    airborne_flights,
    avg_delay_min,
    max_delay_min,
    total_delay_min,
    created_by,
    created_at,
    updated_at,
    activated_by,
    activated_at,
    purged_by,
    purged_at,
    model_time_utc,
    modified_by,
    modified_utc,
    activated_utc,
    purged_utc
)
SELECT 
    n.program_id,
    n.program_guid,
    n.ctl_element,
    n.element_type,
    n.program_type,
    n.program_name,
    n.adv_number,
    n.start_utc,
    n.end_utc,
    n.cumulative_start,
    n.cumulative_end,
    n.status,
    n.is_proposed,
    n.is_active,
    n.program_rate,
    n.reserve_rate,
    n.delay_limit_min,
    n.target_delay_mult,
    n.rates_hourly_json,
    n.reserve_hourly_json,
    -- Scope columns
    n.scope_type,
    n.scope_tier,
    n.scope_distance_nm,
    n.scope_json,
    n.exemptions_json,
    n.exempt_airborne,
    n.exempt_within_min,
    -- Flight filters
    n.flt_incl_carrier,
    n.flt_incl_type,
    n.flt_incl_fix,
    -- Impact/cause
    n.impacting_condition,
    n.cause_text,
    n.comments,
    n.prob_extension,
    -- Revision
    n.revision_number,
    n.parent_program_id,
    -- Metrics
    n.total_flights,
    n.controlled_flights,
    n.exempt_flights,
    n.airborne_flights,
    n.avg_delay_min,
    n.max_delay_min,
    n.total_delay_min,
    -- Audit columns (map old names to new names)
    n.created_by,
    n.created_utc,           -- created_at
    n.modified_utc,          -- updated_at
    n.activated_by,
    n.activated_utc,         -- activated_at
    n.purged_by,
    n.purged_utc,            -- purged_at
    -- Additional columns
    n.model_time_utc,
    n.modified_by,
    n.modified_utc,
    n.activated_utc,
    n.purged_utc
FROM VATSIM_ADL.dbo.ntml n
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.tmi_programs t 
    WHERE t.program_guid = n.program_guid
);

DECLARE @inserted INT = @@ROWCOUNT;
PRINT '  Inserted ' + CAST(@inserted AS NVARCHAR(10)) + ' records';

SET IDENTITY_INSERT dbo.tmi_programs OFF;
PRINT '';

-- ============================================================================
-- PART 4: Reseed identity to continue after highest program_id
-- ============================================================================
PRINT 'Part 4: Reseeding identity...';

DECLARE @max_id INT;
SELECT @max_id = ISNULL(MAX(program_id), 0) FROM dbo.tmi_programs;
DBCC CHECKIDENT ('dbo.tmi_programs', RESEED, @max_id);
PRINT '  Identity reseeded to ' + CAST(@max_id AS NVARCHAR(10));
PRINT '';

-- ============================================================================
-- PART 5: Verify migration
-- ============================================================================
PRINT 'Part 5: Verification...';

SELECT @target_count = COUNT(*) FROM dbo.tmi_programs;
PRINT '  Final records in tmi_programs: ' + CAST(@target_count AS NVARCHAR(10));

SELECT @source_count = COUNT(*) FROM VATSIM_ADL.dbo.ntml;
PRINT '  Source records in ntml: ' + CAST(@source_count AS NVARCHAR(10));

IF @target_count >= @source_count
    PRINT '  STATUS: Migration SUCCESSFUL';
ELSE
    PRINT '  STATUS: WARNING - Record count mismatch';

PRINT '';
PRINT '=== Migration 014 Complete ===';
PRINT '';
PRINT 'Next steps:';
PRINT '  1. Update gdt.js to use /api/gdt/ endpoints';
PRINT '  2. Test GDT functionality end-to-end';
PRINT '  3. Consider deprecating old /api/tmi/gs/ endpoints';
GO
