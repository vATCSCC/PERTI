-- SWIM Users table (perti_site MySQL)
-- Stores VATSIM members who authenticate for SWIM API key self-service
-- but are not in the main `users` table (non-PERTI staff).
--
-- Run on: perti_site MySQL
-- Date: 2026-02-25

CREATE TABLE IF NOT EXISTS swim_users (
    cid BIGINT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    vatsim_rating VARCHAR(10) DEFAULT NULL,
    vatsim_division VARCHAR(20) DEFAULT NULL,
    first_login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
