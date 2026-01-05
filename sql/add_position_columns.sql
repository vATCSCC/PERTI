-- SQL Script to add new columns to splits_positions table
-- Run this on the ADL database to support the enhanced position fields

-- Add frequency column (format: 123.456)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('splits_positions') AND name = 'frequency')
BEGIN
    ALTER TABLE splits_positions ADD frequency VARCHAR(10) NULL;
    PRINT 'Added frequency column';
END

-- Add controller_oi column (format: XX - 2 character operator initials)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('splits_positions') AND name = 'controller_oi')
BEGIN
    ALTER TABLE splits_positions ADD controller_oi VARCHAR(2) NULL;
    PRINT 'Added controller_oi column';
END

-- Add filters column (JSON storage for route/altitude/aircraft filters)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('splits_positions') AND name = 'filters')
BEGIN
    ALTER TABLE splits_positions ADD filters NVARCHAR(MAX) NULL;
    PRINT 'Added filters column';
END

-- Verify columns were added
SELECT 
    COLUMN_NAME, 
    DATA_TYPE, 
    CHARACTER_MAXIMUM_LENGTH,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'splits_positions'
ORDER BY ORDINAL_POSITION;

/*
Expected structure of the filters JSON:
{
    "route": {
        "orig": "JFK, LGA, N90",
        "dest": "LAX, SFO, ZLA",
        "fix": "LENDY, PUCKY",
        "gate": "North gates",
        "other": "..."
    },
    "altitude": {
        "floor": "240",
        "ceiling": "350",
        "block": "240B350"
    },
    "aircraft": {
        "type": "JETS",
        "speed": ">250",
        "rvsm": "RVSM",
        "navEquip": "RNAV, RNP",
        "other": "..."
    },
    "other": "Additional notes"
}
*/
