-- Migration: 017_normalize_artcc_function.sql
-- Purpose: Canonical ARTCC code normalization function for PostGIS
-- Handles: K-prefix stripping (KZNY→ZNY), Canadian 3→4 letter (CZE→CZEG), PAZA→ZAN

CREATE OR REPLACE FUNCTION normalize_artcc_code(code TEXT)
RETURNS TEXT LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
    v_upper TEXT := upper(trim(code));
    v_map   JSONB := '{
        "CZE":"CZEG", "CZU":"CZUL", "CZV":"CZVR",
        "CZW":"CZWG", "CZY":"CZYZ", "CZM":"CZQM",
        "CZQ":"CZQX", "CZO":"CZQO", "CZX":"CZQX",
        "PAZA":"ZAN", "KZAK":"ZAK", "KZWY":"ZWY",
        "PGZU":"ZUA", "PAZN":"ZAP", "PHZH":"ZHN"
    }';
BEGIN
    -- Check alias map first
    IF v_map ? v_upper THEN
        RETURN v_map ->> v_upper;
    END IF;
    -- K-prefix stripping (KZ** pattern only)
    IF v_upper ~ '^KZ[A-Z]{2}$' THEN
        RETURN substring(v_upper FROM 2);
    END IF;
    RETURN v_upper;
END;
$$;

COMMENT ON FUNCTION normalize_artcc_code(TEXT) IS
    'Normalizes ARTCC codes: strips K-prefix (KZNY→ZNY), expands Canadian 3-letter (CZE→CZEG), maps PAZA→ZAN and other ICAO→FAA conversions';
