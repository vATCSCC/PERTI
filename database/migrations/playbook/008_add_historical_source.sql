-- ============================================================================
-- Migration: 008_add_historical_source.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Add FAA_HISTORICAL source for historical PDF playbook imports.
--            Add historical_import/reimport changelog actions.
-- ============================================================================

-- 1. Extend source ENUM to include FAA_HISTORICAL
ALTER TABLE playbook_plays
MODIFY COLUMN source ENUM('FAA','DCC','ECFMP','CANOC','CADENA','FAA_HISTORICAL') NOT NULL DEFAULT 'DCC';

-- 2. Extend changelog action ENUM
ALTER TABLE playbook_changelog
MODIFY COLUMN action ENUM(
    'play_created','play_updated','play_archived','play_restored','play_deleted',
    'route_added','route_updated','route_deleted',
    'faa_import','faa_reimport',
    'historical_import','historical_reimport'
) NOT NULL;
