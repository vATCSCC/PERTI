-- Migration: Initiative Timeline Tables (MySQL)
-- Creates new tables for timeline-based Terminal and En Route initiatives
-- Run this migration to add timeline functionality to PERTI plans

-- Terminal Initiative Timeline Table
CREATE TABLE p_terminal_init_timeline (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    p_id INT NOT NULL,                          -- Plan ID (FK to p_plans)
    facility VARCHAR(255) NOT NULL,             -- Comma-separated facility codes (e.g., "JFK,LGA,EWR")
    area VARCHAR(255) NULL,                     -- Area/sector (optional)
    tmi_type VARCHAR(50) NOT NULL,              -- GS, GDP, MIT, MINIT, CFR, APREQ, Reroute, AFP, FEA, FCA, CTOP, ICR, TBO, Metering, TBM, TBFM, Other
    tmi_type_other VARCHAR(100) NULL,           -- Custom type when tmi_type = 'Other'
    cause VARCHAR(255) NULL,                    -- Cause/context (Volume, Weather, Equipment, etc.)
    start_datetime DATETIME NOT NULL,           -- Start date/time in UTC
    end_datetime DATETIME NOT NULL,             -- End date/time in UTC
    level VARCHAR(50) NOT NULL,                 -- CDW, Possible, Probable, Expected, Active, Advisory_Terminal, Advisory_EnRoute, Special_Event, Space_Op, Staffing, VIP, Misc
    notes TEXT NULL,                            -- Additional notes
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by VARCHAR(50) NULL,                -- CID of creator
    CONSTRAINT FK_terminal_init_timeline_plan FOREIGN KEY (p_id) REFERENCES p_plans(id) ON DELETE CASCADE,
    INDEX IX_terminal_init_timeline_p_id (p_id),
    INDEX IX_terminal_init_timeline_datetime (start_datetime, end_datetime),
    INDEX IX_terminal_init_timeline_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- En Route Initiative Timeline Table
CREATE TABLE p_enroute_init_timeline (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    p_id INT NOT NULL,                          -- Plan ID (FK to p_plans)
    facility VARCHAR(255) NOT NULL,             -- Comma-separated facility codes (e.g., "ZNY,ZDC,ZOB")
    area VARCHAR(255) NULL,                     -- Area/sector (optional)
    tmi_type VARCHAR(50) NOT NULL,              -- GS, GDP, MIT, MINIT, CFR, APREQ, Reroute, AFP, FEA, FCA, CTOP, ICR, TBO, Metering, TBM, TBFM, Other
    tmi_type_other VARCHAR(100) NULL,           -- Custom type when tmi_type = 'Other'
    cause VARCHAR(255) NULL,                    -- Cause/context (Volume, Weather, Structure, etc.)
    start_datetime DATETIME NOT NULL,           -- Start date/time in UTC
    end_datetime DATETIME NOT NULL,             -- End date/time in UTC
    level VARCHAR(50) NOT NULL,                 -- CDW, Possible, Probable, Expected, Active, Advisory_Terminal, Advisory_EnRoute, Special_Event, Space_Op, Staffing, VIP, Misc
    notes TEXT NULL,                            -- Additional notes
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by VARCHAR(50) NULL,                -- CID of creator
    CONSTRAINT FK_enroute_init_timeline_plan FOREIGN KEY (p_id) REFERENCES p_plans(id) ON DELETE CASCADE,
    INDEX IX_enroute_init_timeline_p_id (p_id),
    INDEX IX_enroute_init_timeline_datetime (start_datetime, end_datetime),
    INDEX IX_enroute_init_timeline_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Note: Level values reference:
-- CDW = Critical Decision Window
-- Possible = Possible TMI
-- Probable = Probable TMI
-- Expected = Expected TMI
-- Active = Active TMI
-- Advisory_Terminal = TMI Advisory (Terminal)
-- Advisory_EnRoute = TMI Advisory (En Route)
-- Special_Event = Special Event
-- Space_Op = Space Operation
-- Staffing = Staffing Trigger
-- VIP = VIP Movement
-- Misc = Miscellaneous
