-- Initiative Timeline - ALTER Script
-- Run this to ensure your tables are up-to-date
-- Safe to run multiple times (checks for existence before altering)

-- =============================================
-- Terminal Initiative Timeline Table
-- =============================================

-- Check if table exists, create if not
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'p_terminal_init_timeline')
BEGIN
    CREATE TABLE p_terminal_init_timeline (
        id INT IDENTITY(1,1) PRIMARY KEY,
        p_id INT NOT NULL,
        facility NVARCHAR(50) NOT NULL,
        area NVARCHAR(100) NULL,
        tmi_type NVARCHAR(50) NOT NULL,
        tmi_type_other NVARCHAR(100) NULL,
        cause NVARCHAR(255) NULL,
        start_datetime DATETIME NOT NULL,
        end_datetime DATETIME NOT NULL,
        level NVARCHAR(50) NOT NULL DEFAULT 'Possible',
        notes NVARCHAR(MAX) NULL,
        created_by NVARCHAR(50) NULL,
        created_at DATETIME DEFAULT GETDATE(),
        updated_at DATETIME DEFAULT GETDATE()
    );
    
    CREATE INDEX IX_term_init_timeline_pid ON p_terminal_init_timeline(p_id);
    CREATE INDEX IX_term_init_timeline_facility ON p_terminal_init_timeline(facility);
    CREATE INDEX IX_term_init_timeline_times ON p_terminal_init_timeline(start_datetime, end_datetime);
    CREATE INDEX IX_term_init_timeline_level ON p_terminal_init_timeline(level);
    
    PRINT 'Created table p_terminal_init_timeline';
END
ELSE
BEGIN
    PRINT 'Table p_terminal_init_timeline already exists';
END
GO

-- Ensure all columns exist with correct types
-- facility column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_terminal_init_timeline' AND COLUMN_NAME = 'facility')
BEGIN
    ALTER TABLE p_terminal_init_timeline ADD facility NVARCHAR(50) NOT NULL DEFAULT '';
    PRINT 'Added column: facility';
END
GO

-- area column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_terminal_init_timeline' AND COLUMN_NAME = 'area')
BEGIN
    ALTER TABLE p_terminal_init_timeline ADD area NVARCHAR(100) NULL;
    PRINT 'Added column: area';
END
GO

-- tmi_type column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_terminal_init_timeline' AND COLUMN_NAME = 'tmi_type')
BEGIN
    ALTER TABLE p_terminal_init_timeline ADD tmi_type NVARCHAR(50) NOT NULL DEFAULT 'Other';
    PRINT 'Added column: tmi_type';
END
GO

-- tmi_type_other column (for VIP callsigns, Other TMI types, etc.)
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_terminal_init_timeline' AND COLUMN_NAME = 'tmi_type_other')
BEGIN
    ALTER TABLE p_terminal_init_timeline ADD tmi_type_other NVARCHAR(100) NULL;
    PRINT 'Added column: tmi_type_other';
END
GO

-- cause column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_terminal_init_timeline' AND COLUMN_NAME = 'cause')
BEGIN
    ALTER TABLE p_terminal_init_timeline ADD cause NVARCHAR(255) NULL;
    PRINT 'Added column: cause';
END
GO

-- level column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_terminal_init_timeline' AND COLUMN_NAME = 'level')
BEGIN
    ALTER TABLE p_terminal_init_timeline ADD level NVARCHAR(50) NOT NULL DEFAULT 'Possible';
    PRINT 'Added column: level';
END
GO

-- notes column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_terminal_init_timeline' AND COLUMN_NAME = 'notes')
BEGIN
    ALTER TABLE p_terminal_init_timeline ADD notes NVARCHAR(MAX) NULL;
    PRINT 'Added column: notes';
END
GO

-- created_by column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_terminal_init_timeline' AND COLUMN_NAME = 'created_by')
BEGIN
    ALTER TABLE p_terminal_init_timeline ADD created_by NVARCHAR(50) NULL;
    PRINT 'Added column: created_by';
END
GO


-- =============================================
-- En Route Initiative Timeline Table
-- =============================================

-- Check if table exists, create if not
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'p_enroute_init_timeline')
BEGIN
    CREATE TABLE p_enroute_init_timeline (
        id INT IDENTITY(1,1) PRIMARY KEY,
        p_id INT NOT NULL,
        facility NVARCHAR(50) NOT NULL,
        area NVARCHAR(100) NULL,
        tmi_type NVARCHAR(50) NOT NULL,
        tmi_type_other NVARCHAR(100) NULL,
        cause NVARCHAR(255) NULL,
        start_datetime DATETIME NOT NULL,
        end_datetime DATETIME NOT NULL,
        level NVARCHAR(50) NOT NULL DEFAULT 'Possible',
        notes NVARCHAR(MAX) NULL,
        created_by NVARCHAR(50) NULL,
        created_at DATETIME DEFAULT GETDATE(),
        updated_at DATETIME DEFAULT GETDATE()
    );
    
    CREATE INDEX IX_enr_init_timeline_pid ON p_enroute_init_timeline(p_id);
    CREATE INDEX IX_enr_init_timeline_facility ON p_enroute_init_timeline(facility);
    CREATE INDEX IX_enr_init_timeline_times ON p_enroute_init_timeline(start_datetime, end_datetime);
    CREATE INDEX IX_enr_init_timeline_level ON p_enroute_init_timeline(level);
    
    PRINT 'Created table p_enroute_init_timeline';
END
ELSE
BEGIN
    PRINT 'Table p_enroute_init_timeline already exists';
END
GO

-- Ensure all columns exist with correct types
-- facility column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_enroute_init_timeline' AND COLUMN_NAME = 'facility')
BEGIN
    ALTER TABLE p_enroute_init_timeline ADD facility NVARCHAR(50) NOT NULL DEFAULT '';
    PRINT 'Added column: facility';
END
GO

-- area column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_enroute_init_timeline' AND COLUMN_NAME = 'area')
BEGIN
    ALTER TABLE p_enroute_init_timeline ADD area NVARCHAR(100) NULL;
    PRINT 'Added column: area';
END
GO

-- tmi_type column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_enroute_init_timeline' AND COLUMN_NAME = 'tmi_type')
BEGIN
    ALTER TABLE p_enroute_init_timeline ADD tmi_type NVARCHAR(50) NOT NULL DEFAULT 'Other';
    PRINT 'Added column: tmi_type';
END
GO

-- tmi_type_other column (for VIP callsigns, Other TMI types, etc.)
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_enroute_init_timeline' AND COLUMN_NAME = 'tmi_type_other')
BEGIN
    ALTER TABLE p_enroute_init_timeline ADD tmi_type_other NVARCHAR(100) NULL;
    PRINT 'Added column: tmi_type_other';
END
GO

-- cause column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_enroute_init_timeline' AND COLUMN_NAME = 'cause')
BEGIN
    ALTER TABLE p_enroute_init_timeline ADD cause NVARCHAR(255) NULL;
    PRINT 'Added column: cause';
END
GO

-- level column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_enroute_init_timeline' AND COLUMN_NAME = 'level')
BEGIN
    ALTER TABLE p_enroute_init_timeline ADD level NVARCHAR(50) NOT NULL DEFAULT 'Possible';
    PRINT 'Added column: level';
END
GO

-- notes column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_enroute_init_timeline' AND COLUMN_NAME = 'notes')
BEGIN
    ALTER TABLE p_enroute_init_timeline ADD notes NVARCHAR(MAX) NULL;
    PRINT 'Added column: notes';
END
GO

-- created_by column
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'p_enroute_init_timeline' AND COLUMN_NAME = 'created_by')
BEGIN
    ALTER TABLE p_enroute_init_timeline ADD created_by NVARCHAR(50) NULL;
    PRINT 'Added column: created_by';
END
GO

-- =============================================
-- Ensure indexes exist
-- =============================================

-- Terminal indexes
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_term_init_timeline_pid' AND object_id = OBJECT_ID('p_terminal_init_timeline'))
BEGIN
    CREATE INDEX IX_term_init_timeline_pid ON p_terminal_init_timeline(p_id);
    PRINT 'Created index: IX_term_init_timeline_pid';
END
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_term_init_timeline_level' AND object_id = OBJECT_ID('p_terminal_init_timeline'))
BEGIN
    CREATE INDEX IX_term_init_timeline_level ON p_terminal_init_timeline(level);
    PRINT 'Created index: IX_term_init_timeline_level';
END
GO

-- En Route indexes
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_enr_init_timeline_pid' AND object_id = OBJECT_ID('p_enroute_init_timeline'))
BEGIN
    CREATE INDEX IX_enr_init_timeline_pid ON p_enroute_init_timeline(p_id);
    PRINT 'Created index: IX_enr_init_timeline_pid';
END
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_enr_init_timeline_level' AND object_id = OBJECT_ID('p_enroute_init_timeline'))
BEGIN
    CREATE INDEX IX_enr_init_timeline_level ON p_enroute_init_timeline(level);
    PRINT 'Created index: IX_enr_init_timeline_level';
END
GO

PRINT '';
PRINT '=============================================';
PRINT 'Initiative Timeline ALTER Script Complete';
PRINT '=============================================';
PRINT '';
PRINT 'Valid level values:';
PRINT '  - CDW (Critical Decision Window)';
PRINT '  - Possible, Probable, Expected, Active (TMI stages)';
PRINT '  - Advisory_Terminal, Advisory_EnRoute';
PRINT '  - VIP (VIP Movement)';
PRINT '  - Space_Op (Space Operation)';
PRINT '  - Staffing (Staffing Trigger)';
PRINT '  - Special_Event';
PRINT '  - Misc';
PRINT '';
PRINT 'tmi_type can now contain:';
PRINT '  - Traditional: GS, GDP, MIT, MINIT, CFR, APREQ, Reroute, AFP, etc.';
PRINT '  - VIP types: VIP Arrival, VIP Departure, VIP Overflight, TFR';
PRINT '  - Space types: Rocket Launch, Reentry, Launch Window, Hazard Area';
PRINT '  - Staffing shifts: Day, Mid, Swing, All';
PRINT '  - Other: CDW, Special Event, Misc';
GO
