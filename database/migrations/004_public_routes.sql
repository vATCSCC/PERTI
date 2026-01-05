-- ============================================================================
-- Public Routes Table Migration
-- vATCSCC PERTI System - Global route sharing for all users
-- ============================================================================

-- Create table for publicly shared routes (visible to all users on the map)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'public_routes')
BEGIN
    CREATE TABLE dbo.public_routes (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        status              TINYINT NOT NULL DEFAULT 1,  -- 0=inactive, 1=active, 2=expired
        
        -- Route identification
        name                NVARCHAR(64) NOT NULL,
        adv_number          NVARCHAR(16) NULL,
        
        -- Route content
        route_string        NVARCHAR(MAX) NOT NULL,      -- The actual route (e.g., "KORD..PLANO..JFK")
        advisory_text       NVARCHAR(MAX) NULL,          -- Full advisory text from builder
        
        -- Display settings
        color               CHAR(7) NOT NULL DEFAULT '#e74c3c',  -- Hex color for map display
        line_weight         TINYINT NOT NULL DEFAULT 3,
        line_style          NVARCHAR(16) NOT NULL DEFAULT 'solid',  -- solid, dashed, dotted
        
        -- Validity period
        valid_start_utc     DATETIME2 NOT NULL,
        valid_end_utc       DATETIME2 NOT NULL,
        
        -- Filters / scope
        constrained_area    NVARCHAR(64) NULL,           -- e.g., "ZNY"
        reason              NVARCHAR(256) NULL,          -- e.g., "WEATHER/TRAFFIC MANAGEMENT"
        origin_filter       NVARCHAR(MAX) NULL,          -- JSON array of origin airports/centers
        dest_filter         NVARCHAR(MAX) NULL,          -- JSON array of dest airports/centers
        facilities          NVARCHAR(MAX) NULL,          -- Facilities included (e.g., "ZBW/ZNY/ZDC")
        
        -- Metadata
        created_by          NVARCHAR(64) NULL,           -- Username/CID who created
        created_utc         DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_utc         DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        
        -- Route geometry (cached for performance)
        route_geojson       NVARCHAR(MAX) NULL           -- Pre-computed GeoJSON LineString
    );
    
    -- Index for active routes lookup
    CREATE INDEX IX_public_routes_status_validity 
    ON dbo.public_routes (status, valid_start_utc, valid_end_utc);
    
    -- Index for name searches
    CREATE INDEX IX_public_routes_name 
    ON dbo.public_routes (name);
    
    PRINT 'Created table: dbo.public_routes';
END
ELSE
BEGIN
    PRINT 'Table dbo.public_routes already exists';
END
GO

-- ============================================================================
-- Stored Procedure: Get Active Public Routes
-- ============================================================================
IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_GetActivePublicRoutes')
    DROP PROCEDURE dbo.sp_GetActivePublicRoutes;
GO

CREATE PROCEDURE dbo.sp_GetActivePublicRoutes
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2 = SYSUTCDATETIME();
    
    -- Auto-expire routes past their end time
    UPDATE dbo.public_routes
    SET status = 2, updated_utc = @now
    WHERE status = 1 AND valid_end_utc < @now;
    
    -- Return active routes
    SELECT 
        id, name, adv_number, route_string, advisory_text,
        color, line_weight, line_style,
        valid_start_utc, valid_end_utc,
        constrained_area, reason, origin_filter, dest_filter, facilities,
        created_by, created_utc, updated_utc, route_geojson
    FROM dbo.public_routes
    WHERE status = 1
      AND valid_start_utc <= @now
      AND valid_end_utc >= @now
    ORDER BY created_utc DESC;
END
GO

PRINT 'Created stored procedure: sp_GetActivePublicRoutes';
GO

-- ============================================================================
-- Stored Procedure: Upsert Public Route
-- ============================================================================
IF EXISTS (SELECT * FROM sys.procedures WHERE name = 'sp_UpsertPublicRoute')
    DROP PROCEDURE dbo.sp_UpsertPublicRoute;
GO

CREATE PROCEDURE dbo.sp_UpsertPublicRoute
    @id                 INT = NULL,
    @name               NVARCHAR(64),
    @adv_number         NVARCHAR(16) = NULL,
    @route_string       NVARCHAR(MAX),
    @advisory_text      NVARCHAR(MAX) = NULL,
    @color              CHAR(7) = '#e74c3c',
    @line_weight        TINYINT = 3,
    @line_style         NVARCHAR(16) = 'solid',
    @valid_start_utc    DATETIME2,
    @valid_end_utc      DATETIME2,
    @constrained_area   NVARCHAR(64) = NULL,
    @reason             NVARCHAR(256) = NULL,
    @origin_filter      NVARCHAR(MAX) = NULL,
    @dest_filter        NVARCHAR(MAX) = NULL,
    @facilities         NVARCHAR(MAX) = NULL,
    @created_by         NVARCHAR(64) = NULL,
    @route_geojson      NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2 = SYSUTCDATETIME();
    DECLARE @result_id INT;
    
    IF @id IS NULL
    BEGIN
        -- Insert new route
        INSERT INTO dbo.public_routes (
            name, adv_number, route_string, advisory_text,
            color, line_weight, line_style,
            valid_start_utc, valid_end_utc,
            constrained_area, reason, origin_filter, dest_filter, facilities,
            created_by, created_utc, updated_utc, route_geojson, status
        ) VALUES (
            @name, @adv_number, @route_string, @advisory_text,
            @color, @line_weight, @line_style,
            @valid_start_utc, @valid_end_utc,
            @constrained_area, @reason, @origin_filter, @dest_filter, @facilities,
            @created_by, @now, @now, @route_geojson, 1
        );
        
        SET @result_id = SCOPE_IDENTITY();
    END
    ELSE
    BEGIN
        -- Update existing route
        UPDATE dbo.public_routes SET
            name = @name,
            adv_number = @adv_number,
            route_string = @route_string,
            advisory_text = @advisory_text,
            color = @color,
            line_weight = @line_weight,
            line_style = @line_style,
            valid_start_utc = @valid_start_utc,
            valid_end_utc = @valid_end_utc,
            constrained_area = @constrained_area,
            reason = @reason,
            origin_filter = @origin_filter,
            dest_filter = @dest_filter,
            facilities = @facilities,
            route_geojson = @route_geojson,
            updated_utc = @now
        WHERE id = @id;
        
        SET @result_id = @id;
    END
    
    -- Return the upserted route
    SELECT * FROM dbo.public_routes WHERE id = @result_id;
END
GO

PRINT 'Created stored procedure: sp_UpsertPublicRoute';
GO
