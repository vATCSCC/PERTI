-- =============================================================================
-- Migration 009: Boundary Adjacency Network
-- =============================================================================
-- Creates a network graph of adjacent boundaries (ARTCCs, TRACONs, sectors)
-- Adjacency types:
--   POINT  - boundaries share only corner points (0-dimensional)
--   LINE   - boundaries share edge segments (1-dimensional)
--   POLY   - boundaries overlap (2-dimensional, rare - usually altitude-stratified)
--
-- This enables:
--   - Understanding which boundaries a flight can traverse between
--   - Building adjacency graphs for routing/pathfinding
--   - Validating boundary data integrity
-- =============================================================================

-- Drop existing objects if re-running
DROP TABLE IF EXISTS boundary_adjacency CASCADE;
DROP TYPE IF EXISTS adjacency_type CASCADE;

-- =============================================================================
-- ENUM TYPE FOR ADJACENCY CLASSIFICATION
-- =============================================================================
CREATE TYPE adjacency_type AS ENUM ('POINT', 'LINE', 'POLY');

-- =============================================================================
-- ADJACENCY TABLE
-- =============================================================================
-- Stores precomputed adjacency relationships between all boundary types
-- =============================================================================
CREATE TABLE boundary_adjacency (
    adjacency_id SERIAL PRIMARY KEY,

    -- Source boundary
    source_type VARCHAR(20) NOT NULL,  -- 'ARTCC', 'TRACON', 'SECTOR_LOW', 'SECTOR_HIGH', 'SECTOR_SUPERHIGH'
    source_code VARCHAR(50) NOT NULL,
    source_name VARCHAR(100),

    -- Target boundary
    target_type VARCHAR(20) NOT NULL,
    target_code VARCHAR(50) NOT NULL,
    target_name VARCHAR(100),

    -- Adjacency classification
    adjacency_class adjacency_type NOT NULL,

    -- Shared boundary metrics
    shared_length_nm FLOAT,           -- Length of shared boundary (NULL for POINT)
    shared_points INT DEFAULT 1,      -- Number of shared points (for POINT type)
    intersection_geom GEOMETRY,       -- The actual shared geometry

    -- Metadata
    computed_at TIMESTAMPTZ DEFAULT NOW(),

    -- Prevent duplicates (we store both directions for easy querying)
    UNIQUE (source_type, source_code, target_type, target_code)
);

-- Indexes for fast lookups
CREATE INDEX idx_adj_source ON boundary_adjacency (source_type, source_code);
CREATE INDEX idx_adj_target ON boundary_adjacency (target_type, target_code);
CREATE INDEX idx_adj_class ON boundary_adjacency (adjacency_class);
CREATE INDEX idx_adj_geom ON boundary_adjacency USING GIST (intersection_geom);

-- =============================================================================
-- FUNCTION: Classify intersection geometry type
-- =============================================================================
CREATE OR REPLACE FUNCTION classify_adjacency(geom GEOMETRY)
RETURNS adjacency_type AS $$
BEGIN
    IF geom IS NULL OR ST_IsEmpty(geom) THEN
        RETURN NULL;
    END IF;

    -- Check dimension: 0=point, 1=line, 2=polygon
    CASE ST_Dimension(geom)
        WHEN 0 THEN RETURN 'POINT'::adjacency_type;
        WHEN 1 THEN RETURN 'LINE'::adjacency_type;
        WHEN 2 THEN RETURN 'POLY'::adjacency_type;
        ELSE RETURN NULL;
    END CASE;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- =============================================================================
-- FUNCTION: Compute ARTCC-to-ARTCC adjacencies
-- =============================================================================
CREATE OR REPLACE FUNCTION compute_artcc_adjacencies()
RETURNS TABLE (
    inserted INT,
    elapsed_ms FLOAT
) AS $$
DECLARE
    start_time TIMESTAMPTZ := clock_timestamp();
    cnt INT := 0;
BEGIN
    -- Delete existing ARTCC-ARTCC adjacencies
    DELETE FROM boundary_adjacency
    WHERE source_type = 'ARTCC' AND target_type = 'ARTCC';

    -- Insert new adjacencies
    INSERT INTO boundary_adjacency (
        source_type, source_code, source_name,
        target_type, target_code, target_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    )
    SELECT
        'ARTCC', a.artcc_code, a.fir_name,
        'ARTCC', b.artcc_code, b.fir_name,
        classify_adjacency(ST_Intersection(a.geom, b.geom)),
        CASE
            WHEN ST_Dimension(ST_Intersection(a.geom, b.geom)) = 1
            THEN ST_Length(ST_Intersection(a.geom, b.geom)::geography) / 1852.0
            ELSE NULL
        END,
        CASE
            WHEN ST_Dimension(ST_Intersection(a.geom, b.geom)) = 0
            THEN ST_NumGeometries(ST_Intersection(a.geom, b.geom))
            ELSE NULL
        END,
        ST_Intersection(a.geom, b.geom)
    FROM artcc_boundaries a
    JOIN artcc_boundaries b ON a.artcc_code < b.artcc_code  -- Avoid self and duplicates
    WHERE ST_Intersects(a.geom, b.geom)
      AND NOT ST_IsEmpty(ST_Intersection(a.geom, b.geom))
      AND NOT COALESCE(a.is_oceanic, FALSE)
      AND NOT COALESCE(b.is_oceanic, FALSE)
    ON CONFLICT (source_type, source_code, target_type, target_code) DO NOTHING;

    GET DIAGNOSTICS cnt = ROW_COUNT;

    -- Also insert reverse direction for easier querying
    INSERT INTO boundary_adjacency (
        source_type, source_code, source_name,
        target_type, target_code, target_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    )
    SELECT
        target_type, target_code, target_name,
        source_type, source_code, source_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    FROM boundary_adjacency
    WHERE source_type = 'ARTCC' AND target_type = 'ARTCC'
    ON CONFLICT DO NOTHING;

    inserted := cnt;
    elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - start_time)) * 1000;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql;

-- =============================================================================
-- FUNCTION: Compute TRACON-to-TRACON adjacencies
-- =============================================================================
CREATE OR REPLACE FUNCTION compute_tracon_adjacencies()
RETURNS TABLE (
    inserted INT,
    elapsed_ms FLOAT
) AS $$
DECLARE
    start_time TIMESTAMPTZ := clock_timestamp();
    cnt INT := 0;
BEGIN
    DELETE FROM boundary_adjacency
    WHERE source_type = 'TRACON' AND target_type = 'TRACON';

    INSERT INTO boundary_adjacency (
        source_type, source_code, source_name,
        target_type, target_code, target_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    )
    SELECT
        'TRACON', a.tracon_code, a.tracon_name,
        'TRACON', b.tracon_code, b.tracon_name,
        classify_adjacency(ST_Intersection(a.geom, b.geom)),
        CASE
            WHEN ST_Dimension(ST_Intersection(a.geom, b.geom)) = 1
            THEN ST_Length(ST_Intersection(a.geom, b.geom)::geography) / 1852.0
            ELSE NULL
        END,
        CASE
            WHEN ST_Dimension(ST_Intersection(a.geom, b.geom)) = 0
            THEN ST_NumGeometries(ST_Intersection(a.geom, b.geom))
            ELSE NULL
        END,
        ST_Intersection(a.geom, b.geom)
    FROM tracon_boundaries a
    JOIN tracon_boundaries b ON a.tracon_code < b.tracon_code
    WHERE ST_Intersects(a.geom, b.geom)
      AND NOT ST_IsEmpty(ST_Intersection(a.geom, b.geom))
    ON CONFLICT (source_type, source_code, target_type, target_code) DO NOTHING;

    GET DIAGNOSTICS cnt = ROW_COUNT;

    INSERT INTO boundary_adjacency (
        source_type, source_code, source_name,
        target_type, target_code, target_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    )
    SELECT
        target_type, target_code, target_name,
        source_type, source_code, source_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    FROM boundary_adjacency
    WHERE source_type = 'TRACON' AND target_type = 'TRACON'
    ON CONFLICT DO NOTHING;

    inserted := cnt;
    elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - start_time)) * 1000;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql;

-- =============================================================================
-- FUNCTION: Compute TRACON-to-ARTCC adjacencies
-- =============================================================================
CREATE OR REPLACE FUNCTION compute_tracon_artcc_adjacencies()
RETURNS TABLE (
    inserted INT,
    elapsed_ms FLOAT
) AS $$
DECLARE
    start_time TIMESTAMPTZ := clock_timestamp();
    cnt INT := 0;
BEGIN
    DELETE FROM boundary_adjacency
    WHERE (source_type = 'TRACON' AND target_type = 'ARTCC')
       OR (source_type = 'ARTCC' AND target_type = 'TRACON');

    INSERT INTO boundary_adjacency (
        source_type, source_code, source_name,
        target_type, target_code, target_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    )
    SELECT
        'TRACON', t.tracon_code, t.tracon_name,
        'ARTCC', a.artcc_code, a.fir_name,
        classify_adjacency(ST_Intersection(t.geom, a.geom)),
        CASE
            WHEN ST_Dimension(ST_Intersection(t.geom, a.geom)) = 1
            THEN ST_Length(ST_Intersection(t.geom, a.geom)::geography) / 1852.0
            ELSE NULL
        END,
        CASE
            WHEN ST_Dimension(ST_Intersection(t.geom, a.geom)) = 0
            THEN ST_NumGeometries(ST_Intersection(t.geom, a.geom))
            ELSE NULL
        END,
        ST_Intersection(t.geom, a.geom)
    FROM tracon_boundaries t
    JOIN artcc_boundaries a ON ST_Intersects(t.geom, a.geom)
    WHERE NOT ST_IsEmpty(ST_Intersection(t.geom, a.geom))
      AND NOT COALESCE(a.is_oceanic, FALSE)
    ON CONFLICT (source_type, source_code, target_type, target_code) DO NOTHING;

    GET DIAGNOSTICS cnt = ROW_COUNT;

    -- Reverse direction
    INSERT INTO boundary_adjacency (
        source_type, source_code, source_name,
        target_type, target_code, target_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    )
    SELECT
        target_type, target_code, target_name,
        source_type, source_code, source_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    FROM boundary_adjacency
    WHERE source_type = 'TRACON' AND target_type = 'ARTCC'
    ON CONFLICT DO NOTHING;

    inserted := cnt;
    elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - start_time)) * 1000;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql;

-- =============================================================================
-- FUNCTION: Compute Sector-to-Sector adjacencies (within same type)
-- =============================================================================
CREATE OR REPLACE FUNCTION compute_sector_adjacencies(p_sector_type VARCHAR DEFAULT 'HIGH')
RETURNS TABLE (
    inserted INT,
    elapsed_ms FLOAT
) AS $$
DECLARE
    start_time TIMESTAMPTZ := clock_timestamp();
    cnt INT := 0;
    boundary_type_name VARCHAR;
BEGIN
    boundary_type_name := 'SECTOR_' || UPPER(p_sector_type);

    DELETE FROM boundary_adjacency
    WHERE source_type = boundary_type_name AND target_type = boundary_type_name;

    INSERT INTO boundary_adjacency (
        source_type, source_code, source_name,
        target_type, target_code, target_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    )
    SELECT
        boundary_type_name, a.sector_code, a.sector_name,
        boundary_type_name, b.sector_code, b.sector_name,
        classify_adjacency(ST_Intersection(a.geom, b.geom)),
        CASE
            WHEN ST_Dimension(ST_Intersection(a.geom, b.geom)) = 1
            THEN ST_Length(ST_Intersection(a.geom, b.geom)::geography) / 1852.0
            ELSE NULL
        END,
        CASE
            WHEN ST_Dimension(ST_Intersection(a.geom, b.geom)) = 0
            THEN ST_NumGeometries(ST_Intersection(a.geom, b.geom))
            ELSE NULL
        END,
        ST_Intersection(a.geom, b.geom)
    FROM sector_boundaries a
    JOIN sector_boundaries b ON a.sector_code < b.sector_code
    WHERE a.sector_type = UPPER(p_sector_type)
      AND b.sector_type = UPPER(p_sector_type)
      AND ST_Intersects(a.geom, b.geom)
      AND NOT ST_IsEmpty(ST_Intersection(a.geom, b.geom))
    ON CONFLICT (source_type, source_code, target_type, target_code) DO NOTHING;

    GET DIAGNOSTICS cnt = ROW_COUNT;

    -- Reverse direction
    INSERT INTO boundary_adjacency (
        source_type, source_code, source_name,
        target_type, target_code, target_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    )
    SELECT
        target_type, target_code, target_name,
        source_type, source_code, source_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    FROM boundary_adjacency
    WHERE source_type = boundary_type_name AND target_type = boundary_type_name
    ON CONFLICT DO NOTHING;

    inserted := cnt;
    elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - start_time)) * 1000;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql;

-- =============================================================================
-- FUNCTION: Compute Sector-to-ARTCC adjacencies
-- =============================================================================
CREATE OR REPLACE FUNCTION compute_sector_artcc_adjacencies(p_sector_type VARCHAR DEFAULT 'HIGH')
RETURNS TABLE (
    inserted INT,
    elapsed_ms FLOAT
) AS $$
DECLARE
    start_time TIMESTAMPTZ := clock_timestamp();
    cnt INT := 0;
    boundary_type_name VARCHAR;
BEGIN
    boundary_type_name := 'SECTOR_' || UPPER(p_sector_type);

    DELETE FROM boundary_adjacency
    WHERE (source_type = boundary_type_name AND target_type = 'ARTCC')
       OR (source_type = 'ARTCC' AND target_type = boundary_type_name);

    INSERT INTO boundary_adjacency (
        source_type, source_code, source_name,
        target_type, target_code, target_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    )
    SELECT
        boundary_type_name, s.sector_code, s.sector_name,
        'ARTCC', a.artcc_code, a.fir_name,
        classify_adjacency(ST_Intersection(s.geom, a.geom)),
        CASE
            WHEN ST_Dimension(ST_Intersection(s.geom, a.geom)) = 1
            THEN ST_Length(ST_Intersection(s.geom, a.geom)::geography) / 1852.0
            ELSE NULL
        END,
        CASE
            WHEN ST_Dimension(ST_Intersection(s.geom, a.geom)) = 0
            THEN ST_NumGeometries(ST_Intersection(s.geom, a.geom))
            ELSE NULL
        END,
        ST_Intersection(s.geom, a.geom)
    FROM sector_boundaries s
    JOIN artcc_boundaries a ON ST_Intersects(s.geom, a.geom)
    WHERE s.sector_type = UPPER(p_sector_type)
      AND NOT ST_IsEmpty(ST_Intersection(s.geom, a.geom))
      AND NOT COALESCE(a.is_oceanic, FALSE)
      -- Only include if sector is NOT fully contained in ARTCC (would be POLY)
      -- or if we want to track containment relationships too
    ON CONFLICT (source_type, source_code, target_type, target_code) DO NOTHING;

    GET DIAGNOSTICS cnt = ROW_COUNT;

    -- Reverse direction
    INSERT INTO boundary_adjacency (
        source_type, source_code, source_name,
        target_type, target_code, target_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    )
    SELECT
        target_type, target_code, target_name,
        source_type, source_code, source_name,
        adjacency_class, shared_length_nm, shared_points, intersection_geom
    FROM boundary_adjacency
    WHERE source_type = boundary_type_name AND target_type = 'ARTCC'
    ON CONFLICT DO NOTHING;

    inserted := cnt;
    elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - start_time)) * 1000;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql;

-- =============================================================================
-- FUNCTION: Compute ALL adjacencies (master function)
-- =============================================================================
CREATE OR REPLACE FUNCTION compute_all_adjacencies()
RETURNS TABLE (
    category VARCHAR,
    inserted INT,
    elapsed_ms FLOAT
) AS $$
DECLARE
    r RECORD;
BEGIN
    RAISE NOTICE 'Computing ARTCC-ARTCC adjacencies...';
    FOR r IN SELECT * FROM compute_artcc_adjacencies() LOOP
        category := 'ARTCC-ARTCC';
        inserted := r.inserted;
        elapsed_ms := r.elapsed_ms;
        RETURN NEXT;
    END LOOP;

    RAISE NOTICE 'Computing TRACON-TRACON adjacencies...';
    FOR r IN SELECT * FROM compute_tracon_adjacencies() LOOP
        category := 'TRACON-TRACON';
        inserted := r.inserted;
        elapsed_ms := r.elapsed_ms;
        RETURN NEXT;
    END LOOP;

    RAISE NOTICE 'Computing TRACON-ARTCC adjacencies...';
    FOR r IN SELECT * FROM compute_tracon_artcc_adjacencies() LOOP
        category := 'TRACON-ARTCC';
        inserted := r.inserted;
        elapsed_ms := r.elapsed_ms;
        RETURN NEXT;
    END LOOP;

    RAISE NOTICE 'Computing SECTOR_LOW adjacencies...';
    FOR r IN SELECT * FROM compute_sector_adjacencies('LOW') LOOP
        category := 'SECTOR_LOW-SECTOR_LOW';
        inserted := r.inserted;
        elapsed_ms := r.elapsed_ms;
        RETURN NEXT;
    END LOOP;

    RAISE NOTICE 'Computing SECTOR_HIGH adjacencies...';
    FOR r IN SELECT * FROM compute_sector_adjacencies('HIGH') LOOP
        category := 'SECTOR_HIGH-SECTOR_HIGH';
        inserted := r.inserted;
        elapsed_ms := r.elapsed_ms;
        RETURN NEXT;
    END LOOP;

    RAISE NOTICE 'Computing SECTOR_SUPERHIGH adjacencies...';
    FOR r IN SELECT * FROM compute_sector_adjacencies('SUPERHIGH') LOOP
        category := 'SECTOR_SUPERHIGH-SECTOR_SUPERHIGH';
        inserted := r.inserted;
        elapsed_ms := r.elapsed_ms;
        RETURN NEXT;
    END LOOP;

    RAISE NOTICE 'Computing SECTOR_HIGH-ARTCC adjacencies...';
    FOR r IN SELECT * FROM compute_sector_artcc_adjacencies('HIGH') LOOP
        category := 'SECTOR_HIGH-ARTCC';
        inserted := r.inserted;
        elapsed_ms := r.elapsed_ms;
        RETURN NEXT;
    END LOOP;

    RAISE NOTICE 'All adjacencies computed.';
END;
$$ LANGUAGE plpgsql;

-- =============================================================================
-- QUERY FUNCTIONS
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Get all neighbors of a boundary
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION get_boundary_neighbors(
    p_boundary_type VARCHAR,
    p_boundary_code VARCHAR,
    p_adjacency_class adjacency_type DEFAULT NULL  -- NULL = all types
)
RETURNS TABLE (
    neighbor_type VARCHAR,
    neighbor_code VARCHAR,
    neighbor_name VARCHAR,
    adjacency_class adjacency_type,
    shared_length_nm FLOAT,
    shared_points INT
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        ba.target_type::VARCHAR,
        ba.target_code::VARCHAR,
        ba.target_name::VARCHAR,
        ba.adjacency_class,
        ba.shared_length_nm,
        ba.shared_points
    FROM boundary_adjacency ba
    WHERE ba.source_type = p_boundary_type
      AND ba.source_code = p_boundary_code
      AND (p_adjacency_class IS NULL OR ba.adjacency_class = p_adjacency_class)
    ORDER BY ba.adjacency_class DESC, ba.shared_length_nm DESC NULLS LAST;
END;
$$ LANGUAGE plpgsql STABLE;

-- -----------------------------------------------------------------------------
-- Get adjacency network summary statistics
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION get_adjacency_stats()
RETURNS TABLE (
    relationship VARCHAR,
    total_pairs BIGINT,
    point_adjacencies BIGINT,
    line_adjacencies BIGINT,
    poly_adjacencies BIGINT,
    avg_shared_length_nm FLOAT,
    max_shared_length_nm FLOAT
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        (ba.source_type || '-' || ba.target_type)::VARCHAR AS relationship,
        COUNT(*)::BIGINT AS total_pairs,
        COUNT(*) FILTER (WHERE ba.adjacency_class = 'POINT')::BIGINT,
        COUNT(*) FILTER (WHERE ba.adjacency_class = 'LINE')::BIGINT,
        COUNT(*) FILTER (WHERE ba.adjacency_class = 'POLY')::BIGINT,
        AVG(ba.shared_length_nm)::FLOAT,
        MAX(ba.shared_length_nm)::FLOAT
    FROM boundary_adjacency ba
    WHERE ba.source_type <= ba.target_type  -- Avoid double-counting
    GROUP BY ba.source_type, ba.target_type
    ORDER BY ba.source_type, ba.target_type;
END;
$$ LANGUAGE plpgsql STABLE;

-- -----------------------------------------------------------------------------
-- Export adjacency network as edge list (for graph tools)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION export_adjacency_edges(
    p_types VARCHAR[] DEFAULT NULL,  -- NULL = all types
    p_min_adjacency adjacency_type DEFAULT 'POINT'  -- Minimum adjacency level
)
RETURNS TABLE (
    source_id VARCHAR,
    target_id VARCHAR,
    weight FLOAT,
    edge_type VARCHAR
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        (ba.source_type || ':' || ba.source_code)::VARCHAR AS source_id,
        (ba.target_type || ':' || ba.target_code)::VARCHAR AS target_id,
        COALESCE(ba.shared_length_nm, 1.0)::FLOAT AS weight,
        ba.adjacency_class::VARCHAR AS edge_type
    FROM boundary_adjacency ba
    WHERE ba.source_type < ba.target_type
       OR (ba.source_type = ba.target_type AND ba.source_code < ba.target_code)
      AND (p_types IS NULL OR ba.source_type = ANY(p_types))
      AND ba.adjacency_class >= p_min_adjacency
    ORDER BY ba.source_type, ba.source_code, ba.target_type, ba.target_code;
END;
$$ LANGUAGE plpgsql STABLE;

-- -----------------------------------------------------------------------------
-- Find path between two boundaries (BFS through adjacency network)
-- Returns array of boundary codes from source to target
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION find_boundary_path(
    p_source_type VARCHAR,
    p_source_code VARCHAR,
    p_target_type VARCHAR,
    p_target_code VARCHAR,
    p_max_hops INT DEFAULT 10,
    p_same_type_only BOOLEAN DEFAULT FALSE
)
RETURNS TABLE (
    hop INT,
    boundary_type VARCHAR,
    boundary_code VARCHAR,
    boundary_name VARCHAR
) AS $$
DECLARE
    path_found BOOLEAN := FALSE;
BEGIN
    -- Use recursive CTE for BFS
    RETURN QUERY
    WITH RECURSIVE path_search AS (
        -- Base case: start at source
        SELECT
            0 AS hop,
            p_source_type AS btype,
            p_source_code AS bcode,
            ''::VARCHAR AS bname,
            ARRAY[p_source_type || ':' || p_source_code] AS visited

        UNION ALL

        -- Recursive case: follow adjacencies
        SELECT
            ps.hop + 1,
            ba.target_type,
            ba.target_code,
            ba.target_name,
            ps.visited || (ba.target_type || ':' || ba.target_code)
        FROM path_search ps
        JOIN boundary_adjacency ba
            ON ba.source_type = ps.btype
            AND ba.source_code = ps.bcode
        WHERE ps.hop < p_max_hops
          AND NOT ((ba.target_type || ':' || ba.target_code) = ANY(ps.visited))
          AND ba.adjacency_class IN ('LINE', 'POLY')  -- Only traversable adjacencies
          AND (NOT p_same_type_only OR ba.target_type = ba.source_type)
    ),
    -- Find shortest path to target
    target_paths AS (
        SELECT *
        FROM path_search
        WHERE btype = p_target_type AND bcode = p_target_code
        ORDER BY hop
        LIMIT 1
    )
    SELECT
        tp.hop,
        tp.btype::VARCHAR,
        tp.bcode::VARCHAR,
        tp.bname::VARCHAR
    FROM target_paths tp;
END;
$$ LANGUAGE plpgsql STABLE;

-- =============================================================================
-- COMMENTS
-- =============================================================================
COMMENT ON TABLE boundary_adjacency IS 'Precomputed adjacency relationships between airspace boundaries';
COMMENT ON TYPE adjacency_type IS 'Classification of boundary adjacency: POINT (corner), LINE (edge), POLY (overlap)';
COMMENT ON FUNCTION compute_all_adjacencies IS 'Recompute all boundary adjacencies - run after importing new boundaries';
COMMENT ON FUNCTION get_boundary_neighbors IS 'Get all boundaries adjacent to a given boundary';
COMMENT ON FUNCTION get_adjacency_stats IS 'Summary statistics of the adjacency network';
COMMENT ON FUNCTION export_adjacency_edges IS 'Export adjacency network as edge list for graph analysis tools';
COMMENT ON FUNCTION find_boundary_path IS 'Find traversal path between two boundaries using BFS';

-- =============================================================================
-- INITIAL COMPUTATION (run after creating)
-- =============================================================================
-- Uncomment to compute on migration:
-- SELECT * FROM compute_all_adjacencies();

-- =============================================================================
-- END MIGRATION
-- =============================================================================
