-- ============================================================================
-- SWIM Metering & Sequencing Fields (TBFM/FIXM Aligned)
--
-- Adds TBFM-style metering columns to swim_flights for SimTraffic integration.
-- Field naming follows FIXM 4.3 specification with FAA TBFM extensions.
--
-- Reference: FAA TBFM Operational Data Specification, FIXM 4.3 Core
--
-- Version: 1.0.0
-- Date: 2026-01-16
-- ============================================================================

USE SWIM_API;
GO

-- ============================================================================
-- SECTION 1: Core Metering Fields (FIXM-aligned)
-- ============================================================================

-- Meter fix identification (e.g., CAMRN, HITAG)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_point')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_point NVARCHAR(8) NULL;
    PRINT 'Added metering_point (FIXM: meteringPoint, TFMS: MF)';
END
GO

-- Scheduled Time of Arrival at meter fix (TBFM STA)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_time')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_time DATETIME2 NULL;
    PRINT 'Added metering_time (FIXM: meteringTime, TFMS: MF_TIME)';
END
GO

-- Scheduled Time of Arrival at runway threshold
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'scheduled_time_of_arrival')
BEGIN
    ALTER TABLE dbo.swim_flights ADD scheduled_time_of_arrival DATETIME2 NULL;
    PRINT 'Added scheduled_time_of_arrival (FIXM: scheduledTimeOfArrival, TFMS: STA)';
END
GO

-- Arrival sequence number (1 = next to land)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'sequence_number')
BEGIN
    ALTER TABLE dbo.swim_flights ADD sequence_number INT NULL;
    PRINT 'Added sequence_number (FIXM: sequenceNumber, TFMS: SEQ)';
END
GO

-- ============================================================================
-- SECTION 2: TBFM Extended Metering Fields
-- ============================================================================

-- Metering status (UNMETERED, METERED, FROZEN, SUSPENDED, EXEMPT)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_status')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_status NVARCHAR(16) NULL;
    PRINT 'Added metering_status (TBFM: METER_STATUS)';
END
GO

-- Delay assigned by TBFM (minutes)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_delay')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_delay INT NULL;
    PRINT 'Added metering_delay (TBFM: DLA_ASGN - minutes of delay)';
END
GO

-- Frozen indicator (1 = sequence frozen, cannot change)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_frozen')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_frozen BIT NULL DEFAULT 0;
    PRINT 'Added metering_frozen (TBFM: FROZEN)';
END
GO

-- Arrival stream/gate (e.g., NORTH, SOUTH, EAST, WEST corner posts)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'arrival_stream')
BEGIN
    ALTER TABLE dbo.swim_flights ADD arrival_stream NVARCHAR(16) NULL;
    PRINT 'Added arrival_stream (TBFM: GATE/STREAM)';
END
GO

-- Undelayed ETA (what ETA would be without TBFM delay)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'undelayed_eta')
BEGIN
    ALTER TABLE dbo.swim_flights ADD undelayed_eta DATETIME2 NULL;
    PRINT 'Added undelayed_eta (TBFM: UETA - baseline for delay calculation)';
END
GO

-- Scheduled runway time of departure (for departure metering/EDCT)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'scheduled_time_of_departure')
BEGIN
    ALTER TABLE dbo.swim_flights ADD scheduled_time_of_departure DATETIME2 NULL;
    PRINT 'Added scheduled_time_of_departure (FIXM: scheduledTimeOfDeparture, TFMS: STD)';
END
GO

-- ============================================================================
-- SECTION 3: Source Tracking for Metering Data
-- ============================================================================

-- Source of metering data (simtraffic, vatcscc, vnas, topsky)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_source')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_source NVARCHAR(16) NULL;
    PRINT 'Added metering_source';
END
GO

-- Timestamp of last metering update
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'metering_updated_at')
BEGIN
    ALTER TABLE dbo.swim_flights ADD metering_updated_at DATETIME2 NULL;
    PRINT 'Added metering_updated_at';
END
GO

-- ============================================================================
-- SECTION 4: TBFM Vertex/Arc Crossing Times (for TMA integration)
-- ============================================================================

-- Estimated time at vertex (corner post)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'eta_vertex')
BEGIN
    ALTER TABLE dbo.swim_flights ADD eta_vertex DATETIME2 NULL;
    PRINT 'Added eta_vertex (TBFM: ETA_VT)';
END
GO

-- Scheduled time at vertex (assigned by TBFM)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'sta_vertex')
BEGIN
    ALTER TABLE dbo.swim_flights ADD sta_vertex DATETIME2 NULL;
    PRINT 'Added sta_vertex (TBFM: STA_VT)';
END
GO

-- Vertex fix name (e.g., CAMRN, LEEON)
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'vertex_point')
BEGIN
    ALTER TABLE dbo.swim_flights ADD vertex_point NVARCHAR(8) NULL;
    PRINT 'Added vertex_point (TBFM: VT_FIX)';
END
GO

-- ============================================================================
-- SECTION 5: Indexes for Metering Queries
-- ============================================================================

-- Index for airport-based metering queries (vNAS consumption)
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_metering_dest')
BEGIN
    CREATE INDEX IX_swim_flights_metering_dest
    ON dbo.swim_flights (fp_dest_icao, sequence_number)
    WHERE is_active = 1 AND sequence_number IS NOT NULL;
    PRINT 'Created index IX_swim_flights_metering_dest';
END
GO

-- Index for metering status filtering
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.swim_flights') AND name = 'IX_swim_flights_metering_status')
BEGIN
    CREATE INDEX IX_swim_flights_metering_status
    ON dbo.swim_flights (metering_status, fp_dest_icao)
    WHERE is_active = 1 AND metering_status IS NOT NULL;
    PRINT 'Created index IX_swim_flights_metering_status';
END
GO

-- ============================================================================
-- SECTION 6: Verify Schema
-- ============================================================================

SELECT
    c.name AS column_name,
    t.name AS data_type,
    c.max_length,
    c.is_nullable
FROM sys.columns c
JOIN sys.types t ON c.user_type_id = t.user_type_id
WHERE c.object_id = OBJECT_ID('dbo.swim_flights')
  AND c.name IN (
      'metering_point', 'metering_time', 'scheduled_time_of_arrival',
      'sequence_number', 'metering_status', 'metering_delay', 'metering_frozen',
      'arrival_stream', 'undelayed_eta', 'scheduled_time_of_departure',
      'metering_source', 'metering_updated_at', 'eta_vertex', 'sta_vertex', 'vertex_point'
  )
ORDER BY c.name;
GO

-- ============================================================================
-- NOTES
-- ============================================================================
/*
FIXM/TFMS Field Reference for TBFM Metering:

| FIXM Field                    | TFMS     | Our Field                    | Description                           |
|-------------------------------|----------|------------------------------|---------------------------------------|
| meteringPoint                 | MF       | metering_point               | Meter fix identifier                  |
| meteringTime                  | MF_TIME  | metering_time                | STA at meter fix                      |
| scheduledTimeOfArrival        | STA      | scheduled_time_of_arrival    | STA at runway threshold               |
| sequenceNumber                | SEQ      | sequence_number              | Arrival sequence (1=next)             |
| delayValue                    | DLA_ASGN | metering_delay               | Assigned delay in minutes             |
| frozenIndicator               | FROZEN   | metering_frozen              | Sequence frozen flag                  |
| arrivalStream                 | GATE     | arrival_stream               | Corner post/stream assignment         |
| scheduledTimeOfDeparture      | STD      | scheduled_time_of_departure  | EDCT or scheduled departure           |

TBFM Extension Fields (vATCSCC):
- metering_status: Metering state (UNMETERED/METERED/FROZEN/SUSPENDED/EXEMPT)
- undelayed_eta: Baseline ETA without TBFM delay
- metering_source: Data source (simtraffic/vatcscc/vnas/topsky)
- metering_updated_at: Last metering data update timestamp
- eta_vertex/sta_vertex/vertex_point: Vertex (corner post) times and fix

SimTraffic Integration:
- SimTraffic provides: ETA, meter fix times, delay, sequence
- Push to SWIM via POST /ingest/metering
- vNAS queries GET /metering/{airport} for datablock display
*/
