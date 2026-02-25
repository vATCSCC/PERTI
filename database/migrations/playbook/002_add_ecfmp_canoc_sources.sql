-- ============================================================================
-- Migration: 002_add_ecfmp_canoc_sources.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Extend playbook_plays source ENUM to include ECFMP and CANOC
-- ============================================================================

ALTER TABLE playbook_plays
    MODIFY COLUMN source ENUM('FAA','DCC','ECFMP','CANOC') NOT NULL DEFAULT 'DCC';
