-- ============================================================================
-- fn_ProcedureCommonBody.sql
-- T-SQL scalar function: extracts the common waypoint body shared across
-- all runway transitions of a given DP or STAR procedure.
--
-- Port of PostGIS procedure_common_body() from migration 025.
--
-- For STARs: returns the longest common PREFIX
--   e.g., MEZC at MMGL: RW11B=[MEZCA LIVRI IKBAN ULOGA ...]
--         and RW29B=[MEZCA LIVRI IKBAN GL840 ...]
--         -> common body = "MEZCA LIVRI IKBAN"
--
-- For DPs/SIDs: returns the longest common SUFFIX
--   e.g., AVPO at SCCF: RW19L=[GUMAS AVPOP] and RW01R=[KIMOM CC337 AVPOP]
--         -> common body = "AVPOP"
--
-- When only 1 runway transition exists, returns it in full (no alternative).
-- When 0 runway transitions exist or 0 common fixes, returns NULL.
--
-- Parameters:
--   @proc_name   - Procedure name (e.g., 'CAMRN4', 'MEZC')
--   @proc_type   - 'STAR' or 'DP'/'SID'
--   @trunc_name  - Optional truncated name for fallback matching
--
-- Requires: Azure SQL (STRING_SPLIT with enable_ordinal, STRING_AGG)
-- ============================================================================

CREATE OR ALTER FUNCTION dbo.fn_ProcedureCommonBody(
    @proc_name NVARCHAR(32),
    @proc_type VARCHAR(10),
    @trunc_name NVARCHAR(32) = NULL
)
RETURNS NVARCHAR(MAX)
AS
BEGIN
    DECLARE @result NVARCHAR(MAX);
    DECLARE @route_count INT;

    -- Collect all runway transition full_routes for this procedure.
    -- Use procedure_name column (canonical name) instead of parsing computer_code,
    -- because 93.7% of runway transitions have version suffixes in computer_code
    -- (e.g., RW02.ULPO1A) that don't match the base procedure_name (ULPO).
    DECLARE @routes TABLE (route_id INT IDENTITY(1,1), full_route NVARCHAR(MAX));

    IF @proc_type = 'STAR'
    BEGIN
        INSERT INTO @routes (full_route)
        SELECT np.full_route
        FROM dbo.nav_procedures np
        WHERE (np.procedure_name = UPPER(@proc_name)
               OR (@trunc_name IS NOT NULL AND np.procedure_name = UPPER(@trunc_name)))
          AND np.procedure_type = 'STAR'
          AND np.transition_type = 'runway'
          AND np.source IN ('NASR', 'cifp_base', 'synthetic_base', 'CIFP')
          AND np.is_active = 1
          AND (np.is_superseded IS NULL OR np.is_superseded = 0)
          AND np.full_route IS NOT NULL
          AND LEN(np.full_route) > 0
        ORDER BY np.computer_code;
    END
    ELSE  -- DP or SID
    BEGIN
        INSERT INTO @routes (full_route)
        SELECT np.full_route
        FROM dbo.nav_procedures np
        WHERE (np.procedure_name = UPPER(@proc_name)
               OR (@trunc_name IS NOT NULL AND np.procedure_name = UPPER(@trunc_name)))
          AND np.procedure_type IN ('DP', 'SID')
          AND np.transition_type = 'runway'
          AND np.source IN ('NASR', 'cifp_base', 'synthetic_base', 'CIFP')
          AND np.is_active = 1
          AND (np.is_superseded IS NULL OR np.is_superseded = 0)
          AND np.full_route IS NOT NULL
          AND LEN(np.full_route) > 0
        ORDER BY np.computer_code;
    END

    SET @route_count = (SELECT COUNT(*) FROM @routes);

    -- Single transition: return as-is (no alternative exists)
    IF @route_count = 1
    BEGIN
        SELECT @result = full_route FROM @routes WHERE route_id = 1;
        RETURN @result;
    END

    -- Zero transitions: nothing to compute
    IF @route_count < 2
        RETURN NULL;

    -- Split all routes into words with sequential position.
    -- STRING_SPLIT ordinal may have gaps when consecutive spaces produce empty values,
    -- so re-number with ROW_NUMBER to get contiguous 1-based positions.
    DECLARE @words TABLE (route_id INT, seq INT, word NVARCHAR(100));

    INSERT INTO @words (route_id, seq, word)
    SELECT r.route_id,
           ROW_NUMBER() OVER (PARTITION BY r.route_id ORDER BY s.ordinal),
           LTRIM(RTRIM(s.value))
    FROM @routes r
    CROSS APPLY STRING_SPLIT(LTRIM(RTRIM(r.full_route)), ' ', 1) s
    WHERE LEN(LTRIM(RTRIM(s.value))) > 0;

    -- Get word counts per route and find minimum length
    DECLARE @route_lengths TABLE (route_id INT, word_count INT);
    INSERT INTO @route_lengths (route_id, word_count)
    SELECT route_id, MAX(seq) FROM @words GROUP BY route_id;

    DECLARE @min_len INT;
    SELECT @min_len = MIN(word_count) FROM @route_lengths;

    IF @min_len IS NULL OR @min_len = 0
        RETURN NULL;

    DECLARE @common_len INT = 0;
    DECLARE @i INT = 1;
    DECLARE @ref_word NVARCHAR(100);

    IF @proc_type = 'STAR'
    BEGIN
        -- ================================================================
        -- STAR: Longest common PREFIX
        -- Shared enroute approach body before runway-specific segments
        -- ================================================================
        WHILE @i <= @min_len
        BEGIN
            -- Get word at position @i from the first route (reference)
            SELECT @ref_word = word FROM @words WHERE route_id = 1 AND seq = @i;

            -- If any other route has a different word at this position, stop
            IF EXISTS (
                SELECT 1 FROM @words
                WHERE seq = @i AND route_id > 1 AND word != @ref_word
            )
                BREAK;

            SET @common_len = @i;
            SET @i = @i + 1;
        END

        IF @common_len = 0
            RETURN NULL;

        -- Build result: first route's words from position 1 to @common_len
        SELECT @result = STRING_AGG(word, ' ') WITHIN GROUP (ORDER BY seq)
        FROM @words
        WHERE route_id = 1 AND seq <= @common_len;
    END
    ELSE
    BEGIN
        -- ================================================================
        -- DP/SID: Longest common SUFFIX
        -- Shared departure body after runway-specific initial climb
        -- ================================================================
        DECLARE @first_len INT;
        SELECT @first_len = word_count FROM @route_lengths WHERE route_id = 1;

        WHILE @i <= @min_len
        BEGIN
            -- Get the i-th word from the END of the first route
            SELECT @ref_word = word
            FROM @words
            WHERE route_id = 1 AND seq = @first_len - @i + 1;

            -- Check all other routes at their respective i-th-from-end position
            IF EXISTS (
                SELECT 1
                FROM @words w
                JOIN @route_lengths rl ON rl.route_id = w.route_id
                WHERE w.route_id > 1
                  AND w.seq = rl.word_count - @i + 1
                  AND w.word != @ref_word
            )
                BREAK;

            SET @common_len = @i;
            SET @i = @i + 1;
        END

        IF @common_len = 0
            RETURN NULL;

        -- Build result: first route's last @common_len words
        SELECT @result = STRING_AGG(word, ' ') WITHIN GROUP (ORDER BY seq)
        FROM @words
        WHERE route_id = 1 AND seq >= (@first_len - @common_len + 1);
    END

    RETURN @result;
END;
GO

PRINT 'fn_ProcedureCommonBody created';
PRINT 'Extracts shared waypoint body from runway transitions.';
PRINT 'STAR: longest common prefix | DP: longest common suffix';
GO
