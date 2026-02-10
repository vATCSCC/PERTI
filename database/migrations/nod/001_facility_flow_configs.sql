-- ============================================================
-- NOD Facility Flow Configuration Tables
-- Database: VATSIM_ADL (Azure SQL)
-- Created: 2026-02-09
-- ============================================================

-- facility_flow_configs: saved flow configurations per facility
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.facility_flow_configs') AND type = 'U')
BEGIN
    CREATE TABLE dbo.facility_flow_configs (
        config_id       INT IDENTITY(1,1) PRIMARY KEY,
        facility_code   VARCHAR(10)   NOT NULL,
        facility_type   VARCHAR(10)   NOT NULL,       -- ARTCC, TRACON, TOWER
        config_name     VARCHAR(100)  NOT NULL,
        created_by      INT           NULL,
        is_shared       BIT           NOT NULL DEFAULT 0,
        is_default      BIT           NOT NULL DEFAULT 0,
        map_center_lat  DECIMAL(9,6)  NULL,
        map_center_lon  DECIMAL(9,6)  NULL,
        map_zoom        DECIMAL(4,1)  NULL,
        boundary_layers NVARCHAR(MAX) NULL,           -- JSON array
        created_at      DATETIME2     NOT NULL DEFAULT GETUTCDATE(),
        updated_at      DATETIME2     NOT NULL DEFAULT GETUTCDATE()
    );

    CREATE INDEX IX_flow_configs_facility ON dbo.facility_flow_configs(facility_code);
END
GO

-- facility_flow_gates: named groupings of fixes (created before elements for FK)
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.facility_flow_gates') AND type = 'U')
BEGIN
    CREATE TABLE dbo.facility_flow_gates (
        gate_id            INT IDENTITY(1,1) PRIMARY KEY,
        config_id          INT          NOT NULL,
        gate_name          VARCHAR(50)  NOT NULL,
        direction          VARCHAR(10)  NOT NULL DEFAULT 'ARRIVAL',
        color              VARCHAR(7)   NULL DEFAULT '#17a2b8',
        label_format       VARCHAR(50)  NULL,
        sort_order         INT          NOT NULL DEFAULT 0,
        demand_monitor_ids VARCHAR(500) NULL,
        auto_fea           BIT          NOT NULL DEFAULT 0,
        created_at         DATETIME2    NOT NULL DEFAULT GETUTCDATE(),
        updated_at         DATETIME2    NOT NULL DEFAULT GETUTCDATE(),

        CONSTRAINT FK_flow_gates_config FOREIGN KEY (config_id)
            REFERENCES dbo.facility_flow_configs(config_id) ON DELETE CASCADE
    );

    CREATE INDEX IX_flow_gates_config ON dbo.facility_flow_gates(config_id);
END
GO

-- facility_flow_elements: individual flow elements
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.facility_flow_elements') AND type = 'U')
BEGIN
    CREATE TABLE dbo.facility_flow_elements (
        element_id        INT IDENTITY(1,1) PRIMARY KEY,
        config_id         INT           NOT NULL,
        element_type      VARCHAR(20)   NOT NULL,     -- FIX, PROCEDURE, ROUTE, AIRWAY_SEGMENT
        element_name      VARCHAR(100)  NOT NULL,
        fix_name          VARCHAR(16)   NULL,
        procedure_id      INT           NULL,
        route_string      VARCHAR(1000) NULL,
        route_geojson     NVARCHAR(MAX) NULL,
        direction         VARCHAR(10)   NOT NULL DEFAULT 'ARRIVAL',
        gate_id           INT           NULL,
        sort_order        INT           NOT NULL DEFAULT 0,
        color             VARCHAR(7)    NULL DEFAULT '#17a2b8',
        line_weight       INT           NULL DEFAULT 2,
        line_style        VARCHAR(10)   NULL DEFAULT 'solid',
        label_format      VARCHAR(50)   NULL,
        icon              VARCHAR(30)   NULL,
        is_visible        BIT           NOT NULL DEFAULT 1,
        demand_monitor_id INT           NULL,
        auto_fea          BIT           NOT NULL DEFAULT 0,
        created_at        DATETIME2     NOT NULL DEFAULT GETUTCDATE(),
        updated_at        DATETIME2     NOT NULL DEFAULT GETUTCDATE(),

        CONSTRAINT FK_flow_elements_config FOREIGN KEY (config_id)
            REFERENCES dbo.facility_flow_configs(config_id) ON DELETE CASCADE,
        CONSTRAINT FK_flow_elements_gate FOREIGN KEY (gate_id)
            REFERENCES dbo.facility_flow_gates(gate_id)
    );

    CREATE INDEX IX_flow_elements_config ON dbo.facility_flow_elements(config_id);
END
GO
