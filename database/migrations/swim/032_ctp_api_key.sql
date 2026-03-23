-- =====================================================
-- SWIM Migration 032: CTP API Integration Key
-- =====================================================
-- Creates dedicated API key for the CTP API (vatsimnetwork/ctp-api)
-- to read flight data and push slot assignment results via VATSWIM.
--
-- Usage:
--   - REST: Authorization: Bearer swim_sys_ctp_<key>
--   - WebSocket: wss://perti.vatcscc.org/api/swim/v1/ws/?api_key=swim_sys_ctp_<key>
--   - Ingest: POST /api/swim/v1/ingest/ctp
--
-- Tier: system (30,000 req/min, 10K WebSocket connections)
-- Source: ctp_api (matches $SWIM_DATA_SOURCES in swim_config.php)
-- Write: enabled (for slot assignment ingest)
--
-- @since 2026-03-22
-- @see docs/superpowers/specs/2026-03-22-ctp-api-vatswim-integration.md
-- =====================================================

-- Generate unique API key with random UUID suffix
DECLARE @key NVARCHAR(64) = 'swim_sys_ctp_' + LOWER(CONVERT(VARCHAR(36), NEWID()));

-- Only insert if no CTP API key exists yet
IF NOT EXISTS (SELECT 1 FROM dbo.swim_api_keys WHERE source_id = 'ctp_api' AND is_active = 1)
BEGIN
    INSERT INTO dbo.swim_api_keys (
        api_key,
        tier,
        owner_name,
        owner_email,
        source_id,
        can_write,
        allowed_sources,
        ip_whitelist,
        description,
        expires_at,
        is_active,
        created_at
    )
    VALUES (
        @key,                                              -- API key (swim_sys_ctp_<uuid>)
        'system',                                          -- Tier (highest rate limits)
        'CTP API - vatsimnetwork/ctp-api',                 -- Owner name
        'tech@vatsim.net',                                 -- Owner email
        'ctp_api',                                         -- Source ID (matches $SWIM_DATA_SOURCES)
        1,                                                 -- Can write (slot assignment ingest)
        '["ctp_api"]',                                     -- Allowed sources
        NULL,                                              -- No IP whitelist (any IP)
        'CTP API integration key for reading flight data via REST/WebSocket and pushing slot assignment results via ingest endpoint. See spec: 2026-03-22-ctp-api-vatswim-integration.md',
        NULL,                                              -- No expiration
        1,                                                 -- Active
        GETUTCDATE()                                       -- Created timestamp
    );

    PRINT 'CTP API key created successfully';
    PRINT 'Key: ' + @key;
    PRINT 'IMPORTANT: Record this key - it cannot be retrieved after this migration.';
END
ELSE
BEGIN
    PRINT 'CTP API key already exists (source_id=ctp_api) - skipping';
END
GO
