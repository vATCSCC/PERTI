-- ============================================================
-- NOD Facility Flow - FEA Linkage Index
-- Database: VATSIM_ADL (Azure SQL)
-- Created: 2026-02-09
--
-- The demand_monitor_id column already exists on facility_flow_elements
-- (created in 001). This migration adds a filtered index for fast
-- lookups of elements with active FEA monitors.
-- ============================================================

IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE name = 'IX_flow_elements_monitor' AND object_id = OBJECT_ID('dbo.facility_flow_elements')
)
BEGIN
    CREATE INDEX IX_flow_elements_monitor
    ON dbo.facility_flow_elements(demand_monitor_id)
    WHERE demand_monitor_id IS NOT NULL;
END
GO
