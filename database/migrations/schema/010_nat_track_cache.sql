-- NAT track cache table (MySQL perti_site)
-- Caches VATSIM natTrak API responses to avoid hammering the external API
-- TTL: 30 minutes (enforced in PHP), stale data kept as fallback

CREATE TABLE IF NOT EXISTS nat_track_cache (
    cache_key   VARCHAR(50) NOT NULL PRIMARY KEY,
    cache_data  MEDIUMTEXT NOT NULL,
    fetched_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
