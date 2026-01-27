-- =====================================================
-- SWIM Migration 018: SimTraffic Push Integration API Key
-- =====================================================
-- Creates dedicated API key for SimTraffic to push flight
-- timing data directly to VATSWIM ingest endpoint.
--
-- Key: swim_sys_simtraffic_d1b8e35e297f4d30b2b5b4d2
-- Tier: system (30,000 req/min)
-- Source: simtraffic
-- Write: enabled
--
-- @since 2026-01-27
-- =====================================================

-- Insert SimTraffic push API key
INSERT INTO dbo.swim_api_keys (
    api_key,
    tier,
    owner_name,
    owner_email,
    source_id,
    can_write,
    allowed_sources,
    ip_whitelist,
    expires_at,
    is_active,
    created_at
)
VALUES (
    'swim_sys_simtraffic_d1b8e35e297f4d30b2b5b4d2',  -- API key
    'system',                                          -- Tier (highest rate limits)
    'SimTraffic Integration',                          -- Owner name
    'support@simtraffic.net',                          -- Owner email (update as needed)
    'simtraffic',                                      -- Source ID (matches SWIM_DATA_SOURCES)
    1,                                                 -- Can write
    'simtraffic',                                      -- Allowed sources
    NULL,                                              -- No IP whitelist (any IP)
    NULL,                                              -- No expiration
    1,                                                 -- Active
    GETUTCDATE()                                       -- Created timestamp
);

PRINT 'SimTraffic push API key created successfully';
PRINT 'Key: swim_sys_simtraffic_d1b8e35e297f4d30b2b5b4d2';
GO
