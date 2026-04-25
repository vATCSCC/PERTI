-- Migration 062: Covering index for CTPSlotEngine::getSlotAtOrAfter()
-- Predicate: program_id = ? AND slot_status = 'OPEN' AND slot_time_utc >= ?
-- Filtered index on slot_status = 'OPEN' keeps index small (only open slots)

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_slots')
      AND name = 'IX_tmi_slots_ctp_lookup'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_slots_ctp_lookup
    ON dbo.tmi_slots (program_id, slot_time_utc)
    INCLUDE (slot_id, slot_name)
    WHERE slot_status = 'OPEN';
END
