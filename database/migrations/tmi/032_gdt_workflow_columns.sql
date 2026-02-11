-- =====================================================
-- GDT Workflow Enhancement - Phase 1 Schema
-- Migration: 032_gdt_workflow_columns.sql
-- Database: VATSIM_TMI (Azure SQL)
-- Purpose: Add advisory_chain_id and TRANSITIONED status
-- =====================================================

SET NOCOUNT ON;
GO

PRINT 'Starting GDT workflow schema migration...';
GO

-- =====================================================
-- 1. Add advisory_chain_id column
-- =====================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_programs')
    AND name = 'advisory_chain_id'
)
BEGIN
    ALTER TABLE dbo.tmi_programs ADD advisory_chain_id INT NULL;
    PRINT '  Added advisory_chain_id column';
END
ELSE
BEGIN
    PRINT '  advisory_chain_id already exists';
END;
GO

-- =====================================================
-- 2. Update status constraint to include TRANSITIONED
-- =====================================================

-- Drop existing constraint if it exists
DECLARE @constraint_name NVARCHAR(128);
SELECT @constraint_name = dc.name
FROM sys.check_constraints dc
JOIN sys.columns c ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id
WHERE dc.parent_object_id = OBJECT_ID('dbo.tmi_programs')
AND c.name = 'status';

IF @constraint_name IS NOT NULL
BEGIN
    EXEC('ALTER TABLE dbo.tmi_programs DROP CONSTRAINT [' + @constraint_name + ']');
    PRINT '  Dropped existing status constraint: ' + @constraint_name;
END;

ALTER TABLE dbo.tmi_programs ADD CONSTRAINT CK_tmi_programs_status
    CHECK (status IN ('PROPOSED', 'MODELING', 'ACTIVE', 'PAUSED', 'COMPLETED',
                      'PURGED', 'SUPERSEDED', 'CANCELLED', 'PENDING_COORD', 'TRANSITIONED'));

PRINT '  Added updated status constraint with TRANSITIONED';
GO

-- =====================================================
-- 3. Backfill advisory_chain_id for existing programs
-- =====================================================

-- Standalone programs: chain to self
UPDATE dbo.tmi_programs
SET advisory_chain_id = program_id
WHERE advisory_chain_id IS NULL
AND parent_program_id IS NULL;

PRINT '  Backfilled advisory_chain_id for ' + CAST(@@ROWCOUNT AS VARCHAR) + ' standalone programs';

-- Child programs: inherit parent's chain
UPDATE child
SET advisory_chain_id = COALESCE(parent.advisory_chain_id, parent.program_id)
FROM dbo.tmi_programs child
JOIN dbo.tmi_programs parent ON child.parent_program_id = parent.program_id
WHERE child.advisory_chain_id IS NULL;

PRINT '  Backfilled advisory_chain_id for ' + CAST(@@ROWCOUNT AS VARCHAR) + ' child programs';
GO

PRINT 'GDT workflow schema migration complete.';
GO
