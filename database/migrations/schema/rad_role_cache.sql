-- Role cache for RAD page (perti_site MySQL)
-- Stores detected role per CID with 5-minute TTL
-- Used by api/rad/role.php to avoid repeated VNAS feed + DB lookups
CREATE TABLE IF NOT EXISTS rad_role_cache (
    cid             INT PRIMARY KEY,
    detected_role   VARCHAR(10) NOT NULL,
    artcc_id        VARCHAR(4) NULL,
    facility_id     VARCHAR(10) NULL,
    position_type   VARCHAR(10) NULL,
    callsign        VARCHAR(20) NULL,
    flight_gufi     VARCHAR(64) NULL,
    airline_icao    VARCHAR(4) NULL,
    detected_utc    DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    expires_utc     DATETIME NOT NULL,
    INDEX IX_rad_role_expires (expires_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
