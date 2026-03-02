-- Hibernation hit tracking table (MySQL: perti_site)
-- Tracks access attempts to hibernated pages and SWIM API endpoints
-- for demand analysis during hibernation mode.
--
-- Already deployed to production on 2026-03-01.

CREATE TABLE IF NOT EXISTS hibernation_hits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page VARCHAR(50) NOT NULL,
    hit_type ENUM('page','api') NOT NULL DEFAULT 'page',
    ip_hash CHAR(64) NOT NULL,
    hit_utc DATETIME NOT NULL,
    INDEX idx_page_date (page, hit_utc),
    INDEX idx_type_date (hit_type, hit_utc),
    INDEX idx_ip_hash (ip_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
