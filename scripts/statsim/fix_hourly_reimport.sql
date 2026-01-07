-- Fix hour_offset column type and re-import hourly data
-- The TINYINT (0-255) doesn't support negative hour offsets like -1, -2

SET NOCOUNT ON;
GO

PRINT 'Fixing hour_offset column type...';
GO

-- Change TINYINT to SMALLINT to allow negative values
ALTER TABLE dbo.vatusa_event_hourly
ALTER COLUMN hour_offset SMALLINT NULL;
GO

PRINT 'Column type changed to SMALLINT. Re-run the hourly import files now.';
GO
