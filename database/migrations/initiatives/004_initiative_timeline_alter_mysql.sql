-- Initiative Timeline - ALTER Script (MySQL)
-- Run this to ensure your tables are up-to-date
-- Safe to run multiple times

-- =============================================
-- Terminal Initiative Timeline Table
-- =============================================

CREATE TABLE IF NOT EXISTS p_terminal_init_timeline (
    id INT AUTO_INCREMENT PRIMARY KEY,
    p_id INT NOT NULL,
    facility VARCHAR(50) NOT NULL,
    area VARCHAR(100) NULL,
    tmi_type VARCHAR(50) NOT NULL DEFAULT 'Other',
    tmi_type_other VARCHAR(100) NULL,
    cause VARCHAR(255) NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    level VARCHAR(50) NOT NULL DEFAULT 'Possible',
    notes TEXT NULL,
    is_global TINYINT(1) NOT NULL DEFAULT 0,
    advzy_number VARCHAR(20) NULL,
    created_by VARCHAR(50) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_term_init_pid (p_id),
    INDEX idx_term_init_facility (facility),
    INDEX idx_term_init_times (start_datetime, end_datetime),
    INDEX idx_term_init_level (level),
    INDEX idx_term_init_global (is_global)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add is_global column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_terminal_init_timeline' 
               AND COLUMN_NAME = 'is_global');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_terminal_init_timeline ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER notes',
    'SELECT "Column is_global already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add advzy_number column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_terminal_init_timeline' 
               AND COLUMN_NAME = 'advzy_number');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_terminal_init_timeline ADD COLUMN advzy_number VARCHAR(20) NULL AFTER is_global',
    'SELECT "Column advzy_number already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add tmi_type_other column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_terminal_init_timeline' 
               AND COLUMN_NAME = 'tmi_type_other');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_terminal_init_timeline ADD COLUMN tmi_type_other VARCHAR(100) NULL AFTER tmi_type',
    'SELECT "Column tmi_type_other already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add cause column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_terminal_init_timeline' 
               AND COLUMN_NAME = 'cause');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_terminal_init_timeline ADD COLUMN cause VARCHAR(255) NULL AFTER tmi_type_other',
    'SELECT "Column cause already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add level column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_terminal_init_timeline' 
               AND COLUMN_NAME = 'level');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_terminal_init_timeline ADD COLUMN level VARCHAR(50) NOT NULL DEFAULT "Possible" AFTER end_datetime',
    'SELECT "Column level already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add notes column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_terminal_init_timeline' 
               AND COLUMN_NAME = 'notes');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_terminal_init_timeline ADD COLUMN notes TEXT NULL AFTER level',
    'SELECT "Column notes already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add created_by column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_terminal_init_timeline' 
               AND COLUMN_NAME = 'created_by');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_terminal_init_timeline ADD COLUMN created_by VARCHAR(50) NULL AFTER advzy_number',
    'SELECT "Column created_by already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for is_global if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_terminal_init_timeline' 
               AND INDEX_NAME = 'idx_term_init_global');
SET @sql := IF(@exist = 0, 
    'CREATE INDEX idx_term_init_global ON p_terminal_init_timeline(is_global)',
    'SELECT "Index idx_term_init_global already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- =============================================
-- En Route Initiative Timeline Table
-- =============================================

CREATE TABLE IF NOT EXISTS p_enroute_init_timeline (
    id INT AUTO_INCREMENT PRIMARY KEY,
    p_id INT NOT NULL,
    facility VARCHAR(50) NOT NULL,
    area VARCHAR(100) NULL,
    tmi_type VARCHAR(50) NOT NULL DEFAULT 'Other',
    tmi_type_other VARCHAR(100) NULL,
    cause VARCHAR(255) NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    level VARCHAR(50) NOT NULL DEFAULT 'Possible',
    notes TEXT NULL,
    is_global TINYINT(1) NOT NULL DEFAULT 0,
    advzy_number VARCHAR(20) NULL,
    created_by VARCHAR(50) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enr_init_pid (p_id),
    INDEX idx_enr_init_facility (facility),
    INDEX idx_enr_init_times (start_datetime, end_datetime),
    INDEX idx_enr_init_level (level),
    INDEX idx_enr_init_global (is_global)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add is_global column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_enroute_init_timeline' 
               AND COLUMN_NAME = 'is_global');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_enroute_init_timeline ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER notes',
    'SELECT "Column is_global already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add advzy_number column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_enroute_init_timeline' 
               AND COLUMN_NAME = 'advzy_number');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_enroute_init_timeline ADD COLUMN advzy_number VARCHAR(20) NULL AFTER is_global',
    'SELECT "Column advzy_number already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add tmi_type_other column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_enroute_init_timeline' 
               AND COLUMN_NAME = 'tmi_type_other');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_enroute_init_timeline ADD COLUMN tmi_type_other VARCHAR(100) NULL AFTER tmi_type',
    'SELECT "Column tmi_type_other already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add cause column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_enroute_init_timeline' 
               AND COLUMN_NAME = 'cause');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_enroute_init_timeline ADD COLUMN cause VARCHAR(255) NULL AFTER tmi_type_other',
    'SELECT "Column cause already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add level column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_enroute_init_timeline' 
               AND COLUMN_NAME = 'level');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_enroute_init_timeline ADD COLUMN level VARCHAR(50) NOT NULL DEFAULT "Possible" AFTER end_datetime',
    'SELECT "Column level already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add notes column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_enroute_init_timeline' 
               AND COLUMN_NAME = 'notes');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_enroute_init_timeline ADD COLUMN notes TEXT NULL AFTER level',
    'SELECT "Column notes already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add created_by column if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_enroute_init_timeline' 
               AND COLUMN_NAME = 'created_by');
SET @sql := IF(@exist = 0, 
    'ALTER TABLE p_enroute_init_timeline ADD COLUMN created_by VARCHAR(50) NULL AFTER advzy_number',
    'SELECT "Column created_by already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for is_global if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'p_enroute_init_timeline' 
               AND INDEX_NAME = 'idx_enr_init_global');
SET @sql := IF(@exist = 0, 
    'CREATE INDEX idx_enr_init_global ON p_enroute_init_timeline(is_global)',
    'SELECT "Index idx_enr_init_global already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- =============================================
-- Summary
-- =============================================
SELECT 'Initiative Timeline tables updated successfully' AS Status;

SELECT 
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME IN ('p_terminal_init_timeline', 'p_enroute_init_timeline')
ORDER BY TABLE_NAME, ORDINAL_POSITION;
