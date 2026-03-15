-- ============================================================================
-- Migration 027: vIFF CDM Integration
--
-- Adds EU ATFCM status column to swim_flights and registers vIFF as a
-- flow provider in tmi_flow_providers.
--
-- vIFF (viff-system.network) is the European ATFCM backend for VATSIM,
-- powering the EuroScope CDM plugin used by 32+ vACCs.
--
-- Run with DDL admin (jpeterson):
--   sqlcmd -S vatsim.database.windows.net -U jpeterson -P Jhp21012 -d SWIM_API -i 027_viff_cdm_integration.sql
-- ============================================================================

USE SWIM_API;
GO

-- EU ATFCM status column
-- Values: REA (Ready), FLS-CDM, FLS-GS, FLS-MR (Flight Suspended variants),
--         COMPLY (airborne within CTOT window), AIRB (airborne),
--         SIR (Slot Improvement Request), EXCLUDED
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'swim_flights' AND COLUMN_NAME = 'eu_atfcm_status'
)
ALTER TABLE dbo.swim_flights ADD eu_atfcm_status NVARCHAR(16) NULL;
GO

-- Filtered index for EU CDM queries (only rows with CDM data)
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_swim_flights_eu_cdm' AND object_id = OBJECT_ID('dbo.swim_flights')
)
CREATE NONCLUSTERED INDEX IX_swim_flights_eu_cdm
ON dbo.swim_flights (cdm_source, cdm_updated_at)
WHERE cdm_source IS NOT NULL;
GO

-- ============================================================================
-- Register vIFF as a flow provider in VATSIM_TMI
-- ============================================================================
USE VATSIM_TMI;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.tmi_flow_providers WHERE provider_code = 'VIFF')
INSERT INTO dbo.tmi_flow_providers (
    provider_code, provider_name, api_base_url, api_version,
    auth_type, region_codes_json, fir_codes_json,
    sync_interval_sec, sync_enabled, is_active, priority
) VALUES (
    'VIFF',
    'vIFF ATFCM System',
    'https://viff-system.network',
    'v1',
    'API_KEY',
    '["EUR","NAT"]',
    '["LECM","EGTT","EGPX","LFFF","LFEE","EDWW","EDMM","EDGG","LIPP","LIBB","LIMM","LPPC","EBBR","EHAA","LKAA","LOVV","LHCC","LRBB","LZBB","LSAS","EKDK","ENOR","ESAA","EFIN","EYVC","EETT","EPWW","LBSR","LCCC","LFMM","LECP","GCCC"]',
    30,
    1,
    1,
    15
);
GO

-- ============================================================================
-- Register in SWIM mirror table if it exists
-- ============================================================================
USE SWIM_API;
GO

IF OBJECT_ID('dbo.swim_tmi_flow_providers', 'U') IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM dbo.swim_tmi_flow_providers WHERE provider_code = 'VIFF')
INSERT INTO dbo.swim_tmi_flow_providers (
    provider_code, provider_name, api_base_url, api_version,
    auth_type, region_codes_json, fir_codes_json,
    sync_interval_sec, sync_enabled, is_active, priority
) VALUES (
    'VIFF',
    'vIFF ATFCM System',
    'https://viff-system.network',
    'v1',
    'API_KEY',
    '["EUR","NAT"]',
    '["LECM","EGTT","EGPX","LFFF","LFEE","EDWW","EDMM","EDGG","LIPP","LIBB","LIMM","LPPC","EBBR","EHAA","LKAA","LOVV","LHCC","LRBB","LZBB","LSAS","EKDK","ENOR","ESAA","EFIN","EYVC","EETT","EPWW","LBSR","LCCC","LFMM","LECP","GCCC"]',
    30,
    1,
    1,
    15
);
GO

PRINT 'Migration 027: vIFF CDM Integration complete';
GO
