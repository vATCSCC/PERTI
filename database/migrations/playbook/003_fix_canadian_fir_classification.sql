-- ============================================================================
-- Migration: 003_fix_canadian_fir_classification.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Move Canadian FIR codes (CZ*) from TRACON to ARTCC columns.
--            The FAA playbook parser incorrectly classified CZ-prefix codes
--            (CZU=Montreal, CZY=Toronto, CZE=Edmonton, etc.) as TRACONs
--            instead of ARTCCs because _is_artcc() only checked Z-prefix.
-- ============================================================================

-- Fix origin_tracons → origin_artccs for CZ* codes
UPDATE playbook_routes
SET origin_artccs = CASE
        WHEN origin_artccs = '' OR origin_artccs IS NULL THEN origin_tracons
        ELSE CONCAT(origin_artccs, ',', origin_tracons)
    END,
    origin_tracons = ''
WHERE origin_tracons REGEXP '^CZ[A-Z]$'
  AND (origin_artccs = '' OR origin_artccs IS NULL);

-- Fix dest_tracons → dest_artccs for CZ* codes
UPDATE playbook_routes
SET dest_artccs = CASE
        WHEN dest_artccs = '' OR dest_artccs IS NULL THEN dest_tracons
        ELSE CONCAT(dest_artccs, ',', dest_tracons)
    END,
    dest_tracons = ''
WHERE dest_tracons REGEXP '^CZ[A-Z]$'
  AND (dest_artccs = '' OR dest_artccs IS NULL);
