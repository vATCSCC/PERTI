-- ============================================================================
-- VATSIM_TMI Migration 040: CDM Core Schema
-- Collaborative Decision Making tables for EDCT delivery, pilot readiness,
-- real-time compliance tracking, airport CDM status, and trigger automation
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-05
-- Author: HP/Claude
--
-- Tables created:
--   1. cdm_messages          - EDCT/gate-hold message delivery tracking
--   2. cdm_pilot_readiness   - Pilot readiness state signals (VATSIM TOBT)
--   3. cdm_compliance_live   - Real-time TMI compliance evaluation
--   4. cdm_airport_status    - Airport CDM status snapshots
--   5. cdm_triggers          - IF/THEN trigger definitions
--   6. cdm_trigger_log       - Trigger evaluation history
--
-- Design notes:
--   - Adapted from FAA CDM (EDCT delivery + ACK), EUROCONTROL A-CDM
--     (milestone tracking), and AMNAC (cross-border coordination)
--   - Pilot readiness states map EUROCONTROL's 16 milestones to 5 states
--   - CTOT tolerance: -5/+15 min (relaxed from EUROCONTROL -5/+10)
--   - All tables are hibernation-safe: data accumulates during hibernation
--     and is post-processed on wake
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '==========================================================================';
PRINT '  Migration 040: CDM Core Schema';
PRINT '  ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '==========================================================================';
GO

-- ============================================================================
-- TABLE 1: cdm_messages
-- ============================================================================
-- Tracks EDCT delivery and pilot acknowledgment across all channels.
-- Multi-channel delivery: CPDLC (Hoppie), pilot client plugin, web dashboard,
-- Discord DM. Modeled after FAA AOCnet EDCT distribution to airlines,
-- adapted for direct pilot delivery.
-- ============================================================================

IF OBJECT_ID('dbo.cdm_messages', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.cdm_messages (
        message_id          INT IDENTITY(1,1) PRIMARY KEY,
        message_guid        UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),

        -- Flight linkage
        flight_uid          BIGINT NOT NULL,
        callsign            NVARCHAR(12) NOT NULL,
        cid                 INT NULL,

        -- Message content
        message_type        NVARCHAR(20) NOT NULL,          -- EDCT, GATE_HOLD, GATE_RELEASE, SLOT_UPDATE, CANCEL, INFO
        message_body        NVARCHAR(500) NOT NULL,
        message_data_json   NVARCHAR(MAX) NULL,             -- Structured payload (times, slot info, etc.)

        -- Delivery tracking
        delivery_channel    NVARCHAR(20) NOT NULL,           -- cpdlc, vpilot, web, discord
        delivery_status     NVARCHAR(20) NOT NULL DEFAULT 'PENDING',  -- PENDING, SENT, DELIVERED, FAILED, EXPIRED
        delivery_attempts   INT DEFAULT 0,
        last_attempt_utc    DATETIME2(0) NULL,
        max_retries         INT DEFAULT 3,

        -- Pilot acknowledgment (adapted from CPDLC WILCO/UNABLE protocol)
        ack_type            NVARCHAR(10) NULL,               -- WILCO, UNABLE, ROGER, STANDBY
        ack_reason          NVARCHAR(200) NULL,              -- Pilot reason for UNABLE
        ack_channel         NVARCHAR(20) NULL,               -- Channel ACK received on

        -- TMI linkage
        program_id          INT NULL,
        slot_id             INT NULL,

        -- Timestamps
        created_utc         DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        sent_utc            DATETIME2(0) NULL,
        ack_utc             DATETIME2(0) NULL,
        expires_utc         DATETIME2(0) NULL,

        -- Hibernation support
        is_hibernation_queued BIT DEFAULT 0,                 -- Queued during hibernation for post-processing
        processed_utc       DATETIME2(0) NULL                -- When post-processed after hibernation wake
    );

    PRINT '+ Created table cdm_messages';

    -- Index: Active messages by flight
    CREATE INDEX IX_cdm_messages_flight
        ON dbo.cdm_messages (flight_uid, delivery_status)
        WHERE delivery_status IN ('PENDING', 'SENT');

    -- Index: Pending delivery queue
    CREATE INDEX IX_cdm_messages_pending
        ON dbo.cdm_messages (delivery_status, delivery_channel, created_utc)
        WHERE delivery_status = 'PENDING';

    -- Index: Hibernation post-processing
    CREATE INDEX IX_cdm_messages_hibernation
        ON dbo.cdm_messages (is_hibernation_queued, processed_utc)
        WHERE is_hibernation_queued = 1 AND processed_utc IS NULL;

    PRINT '  + Created indexes on cdm_messages';
END
ELSE PRINT '= cdm_messages already exists';
GO

-- ============================================================================
-- TABLE 2: cdm_pilot_readiness
-- ============================================================================
-- Tracks pilot readiness signals — VATSIM's equivalent of TOBT.
-- Maps EUROCONTROL A-CDM milestones to 5 observable VATSIM states:
--   PLANNING  = flight plan filed, pilot not connected
--   BOARDING  = pilot connected, at gate position
--   READY     = pilot signals ready for pushback (TOBT moment)
--   TAXIING   = detected moving on taxiway
--   CANCELLED = disconnected or cancelled
--
-- Each state change creates a new row; superseded_utc marks old entries.
-- ============================================================================

IF OBJECT_ID('dbo.cdm_pilot_readiness', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.cdm_pilot_readiness (
        readiness_id        INT IDENTITY(1,1) PRIMARY KEY,
        readiness_guid      UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),

        -- Flight linkage
        flight_uid          BIGINT NOT NULL,
        callsign            NVARCHAR(12) NOT NULL,
        cid                 INT NULL,

        -- Readiness state
        readiness_state     NVARCHAR(20) NOT NULL,          -- PLANNING, BOARDING, READY, TAXIING, CANCELLED
        previous_state      NVARCHAR(20) NULL,

        -- TOBT data
        reported_tobt       DATETIME2(0) NULL,              -- Pilot-reported target off-block time
        computed_tobt       DATETIME2(0) NULL,               -- System-computed TOBT (first_seen + connect_baseline)

        -- Source tracking
        source              NVARCHAR(20) NOT NULL,           -- cpdlc, vpilot, web, simbrief, auto, controller
        source_detail       NVARCHAR(100) NULL,              -- Additional source info (e.g., CPDLC message ref)

        -- Airport context
        dep_airport         NVARCHAR(4) NULL,
        arr_airport         NVARCHAR(4) NULL,

        -- Timestamps
        reported_utc        DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        superseded_utc      DATETIME2(0) NULL,

        -- Hibernation support
        is_hibernation_queued BIT DEFAULT 0,
        processed_utc       DATETIME2(0) NULL
    );

    PRINT '+ Created table cdm_pilot_readiness';

    -- Index: Current readiness per flight (latest non-superseded)
    CREATE INDEX IX_cdm_pilot_readiness_active
        ON dbo.cdm_pilot_readiness (flight_uid, reported_utc DESC)
        WHERE superseded_utc IS NULL;

    -- Index: Airport departures readiness
    CREATE INDEX IX_cdm_pilot_readiness_airport
        ON dbo.cdm_pilot_readiness (dep_airport, readiness_state, reported_utc)
        WHERE superseded_utc IS NULL;

    -- Index: Hibernation post-processing
    CREATE INDEX IX_cdm_pilot_readiness_hibernation
        ON dbo.cdm_pilot_readiness (is_hibernation_queued, processed_utc)
        WHERE is_hibernation_queued = 1 AND processed_utc IS NULL;

    PRINT '  + Created indexes on cdm_pilot_readiness';
END
ELSE PRINT '= cdm_pilot_readiness already exists';
GO

-- ============================================================================
-- TABLE 3: cdm_compliance_live
-- ============================================================================
-- Real-time TMI compliance evaluation results.
-- Evaluated on each ADL 15-second cycle for active controlled flights.
-- Covers: EDCT (-5/+15 tolerance), MIT spacing, reroute adherence, GS hold.
-- Final assessment written when flight departs or enters terminal airspace.
-- ============================================================================

IF OBJECT_ID('dbo.cdm_compliance_live', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.cdm_compliance_live (
        compliance_id       INT IDENTITY(1,1) PRIMARY KEY,

        -- Flight linkage
        flight_uid          BIGINT NOT NULL,
        callsign            NVARCHAR(12) NOT NULL,

        -- TMI linkage
        program_id          INT NOT NULL,
        slot_id             INT NULL,

        -- Compliance evaluation
        compliance_type     NVARCHAR(20) NOT NULL,          -- EDCT, MIT, MINIT, REROUTE, GS, CTOT
        compliance_status   NVARCHAR(20) NOT NULL,          -- PENDING, COMPLIANT, NON_COMPLIANT, EXEMPT, AT_RISK
        risk_level          NVARCHAR(10) NULL,               -- LOW, MEDIUM, HIGH (for AT_RISK status)

        -- Evaluation detail
        expected_value      NVARCHAR(50) NULL,               -- Expected time/spacing/route
        actual_value        NVARCHAR(50) NULL,               -- Actual observed value
        delta_minutes       FLOAT NULL,                      -- Difference (positive = late)
        tolerance_min       FLOAT NULL,                      -- -5 for early tolerance
        tolerance_max       FLOAT NULL,                      -- +15 for late tolerance (VATSIM relaxed)

        -- Timestamps
        evaluated_utc       DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        is_final            BIT DEFAULT 0,                   -- Final assessment (post-departure)
        finalized_utc       DATETIME2(0) NULL,

        -- Hibernation support
        is_hibernation_queued BIT DEFAULT 0,
        processed_utc       DATETIME2(0) NULL
    );

    PRINT '+ Created table cdm_compliance_live';

    -- Index: Active compliance per flight
    CREATE INDEX IX_cdm_compliance_flight
        ON dbo.cdm_compliance_live (flight_uid, compliance_type, is_final);

    -- Index: Program compliance overview
    CREATE INDEX IX_cdm_compliance_program
        ON dbo.cdm_compliance_live (program_id, compliance_status, is_final);

    -- Index: At-risk flights for dashboard
    CREATE INDEX IX_cdm_compliance_risk
        ON dbo.cdm_compliance_live (compliance_status, risk_level, evaluated_utc)
        WHERE compliance_status = 'AT_RISK' AND is_final = 0;

    PRINT '  + Created indexes on cdm_compliance_live';
END
ELSE PRINT '= cdm_compliance_live already exists';
GO

-- ============================================================================
-- TABLE 4: cdm_airport_status
-- ============================================================================
-- Periodic airport CDM status snapshots (every 60s during active operations).
-- Provides A-CDM style airport operational picture:
-- - Departure queue composition (ready, held, taxiing counts)
-- - Weather category and current rates
-- - Gate-hold effectiveness metrics
-- ============================================================================

IF OBJECT_ID('dbo.cdm_airport_status', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.cdm_airport_status (
        status_id           INT IDENTITY(1,1) PRIMARY KEY,

        -- Airport identification
        airport_icao        NVARCHAR(4) NOT NULL,

        -- Snapshot time
        snapshot_utc        DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),

        -- Departure queue counts
        total_departures_next_hour  INT DEFAULT 0,
        ready_count         INT DEFAULT 0,                   -- READY state
        gate_held_count     INT DEFAULT 0,                   -- Held at gate (TSAT > NOW)
        taxiing_count       INT DEFAULT 0,                   -- TAXIING state
        boarding_count      INT DEFAULT 0,                   -- BOARDING state
        planning_count      INT DEFAULT 0,                   -- PLANNING state (prefile only)

        -- Airport performance
        avg_taxi_time_sec   INT NULL,                        -- Current avg taxi time
        baseline_taxi_sec   INT NULL,                        -- Reference baseline from airport_taxi_reference
        avg_gate_hold_min   FLOAT NULL,                      -- Avg gate hold duration
        departures_last_hour INT DEFAULT 0,
        arrivals_last_hour  INT DEFAULT 0,

        -- Weather and rates
        weather_category    NVARCHAR(10) NULL,               -- VMC, MVMC, IMC, LIMC
        aar                 INT NULL,
        adr                 INT NULL,

        -- Control status
        is_controlled       BIT DEFAULT 0,                   -- Under TMI control?
        controlling_program_id INT NULL,                     -- FK → tmi_programs

        -- Hibernation support (snapshots accumulate during hibernation for analysis)
        is_hibernation_snapshot BIT DEFAULT 0
    );

    PRINT '+ Created table cdm_airport_status';

    -- Index: Latest snapshot per airport
    CREATE INDEX IX_cdm_airport_status_latest
        ON dbo.cdm_airport_status (airport_icao, snapshot_utc DESC);

    -- Index: Controlled airports
    CREATE INDEX IX_cdm_airport_status_controlled
        ON dbo.cdm_airport_status (is_controlled, snapshot_utc DESC)
        WHERE is_controlled = 1;

    PRINT '  + Created indexes on cdm_airport_status';
END
ELSE PRINT '= cdm_airport_status already exists';
GO

-- ============================================================================
-- TABLE 5: cdm_triggers
-- ============================================================================
-- IF/THEN trigger definitions for automated TMI proposal creation.
-- Replaces FAA Planning Telcon human coordination with automated monitoring.
-- Example: IF demand > AAR * 1.2 for 2 consecutive checks THEN propose GDP
-- ============================================================================

IF OBJECT_ID('dbo.cdm_triggers', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.cdm_triggers (
        trigger_id          INT IDENTITY(1,1) PRIMARY KEY,
        trigger_guid        UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),

        -- Definition
        trigger_name        NVARCHAR(100) NOT NULL,
        trigger_description NVARCHAR(500) NULL,

        -- Condition (JSON structure)
        -- { "type": "demand_exceeds_rate", "airport": "KJFK",
        --   "threshold_pct": 120, "consecutive_checks": 2 }
        condition_type      NVARCHAR(50) NOT NULL,           -- demand_exceeds_rate, weather_change, fix_demand, gs_duration
        condition_json      NVARCHAR(MAX) NOT NULL,

        -- Action (JSON structure)
        -- { "action": "propose_gdp", "rate": 30, "duration_hours": 2 }
        action_type         NVARCHAR(50) NOT NULL,           -- propose_gdp, propose_gs, propose_mit, alert, adjust_rate
        action_json         NVARCHAR(MAX) NOT NULL,

        -- Scope
        airport_icao        NVARCHAR(4) NULL,                -- NULL = network-wide
        facility_code       NVARCHAR(8) NULL,

        -- State
        is_active           BIT DEFAULT 1,
        is_armed            BIT DEFAULT 1,                   -- Can be disarmed without deactivating
        cooldown_minutes    INT DEFAULT 30,                  -- Min time between consecutive firings
        last_fired_utc      DATETIME2(0) NULL,
        consecutive_met     INT DEFAULT 0,                   -- Current consecutive condition-met count
        required_consecutive INT DEFAULT 2,                  -- Required consecutive checks before firing

        -- Plan linkage
        plan_id             INT NULL,                        -- FK → perti_site.p_plans (event-specific triggers)
        ops_plan_id         INT NULL,                        -- FK → perti_site.p_ops_plan

        -- Metadata
        created_by          INT NULL,                        -- CID
        created_utc         DATETIME2(0) NOT NULL DEFAULT GETUTCDATE(),
        updated_utc         DATETIME2(0) NOT NULL DEFAULT GETUTCDATE()
    );

    PRINT '+ Created table cdm_triggers';

    -- Index: Active triggers by airport
    CREATE INDEX IX_cdm_triggers_active
        ON dbo.cdm_triggers (is_active, airport_icao)
        WHERE is_active = 1;

    PRINT '  + Created indexes on cdm_triggers';
END
ELSE PRINT '= cdm_triggers already exists';
GO

-- ============================================================================
-- TABLE 6: cdm_trigger_log
-- ============================================================================
-- Audit log for trigger evaluations and firings.
-- ============================================================================

IF OBJECT_ID('dbo.cdm_trigger_log', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.cdm_trigger_log (
        log_id              INT IDENTITY(1,1) PRIMARY KEY,
        trigger_id          INT NOT NULL,

        -- Evaluation result
        event_type          NVARCHAR(20) NOT NULL,           -- EVALUATED, CONDITION_MET, FIRED, COOLDOWN_SKIP, ERROR
        condition_result    BIT NULL,                        -- TRUE/FALSE for this evaluation
        evaluation_data_json NVARCHAR(MAX) NULL,             -- Snapshot of metrics at evaluation time

        -- Action result (when FIRED)
        action_result       NVARCHAR(20) NULL,               -- SUCCESS, FAILED, PROPOSAL_CREATED
        action_entity_id    INT NULL,                        -- proposal_id or program_id created
        action_detail       NVARCHAR(500) NULL,

        -- Timestamp
        evaluated_utc       DATETIME2(0) NOT NULL DEFAULT GETUTCDATE()
    );

    PRINT '+ Created table cdm_trigger_log';

    -- Index: Trigger history
    CREATE INDEX IX_cdm_trigger_log_trigger
        ON dbo.cdm_trigger_log (trigger_id, evaluated_utc DESC);

    PRINT '  + Created indexes on cdm_trigger_log';
END
ELSE PRINT '= cdm_trigger_log already exists';
GO

-- ============================================================================
-- VIEWS
-- ============================================================================

-- Active (current) pilot readiness per flight
IF OBJECT_ID('dbo.vw_cdm_current_readiness', 'V') IS NOT NULL
    DROP VIEW dbo.vw_cdm_current_readiness;
GO

CREATE VIEW dbo.vw_cdm_current_readiness AS
SELECT
    r.readiness_id,
    r.flight_uid,
    r.callsign,
    r.cid,
    r.readiness_state,
    r.reported_tobt,
    r.computed_tobt,
    COALESCE(r.reported_tobt, r.computed_tobt) AS effective_tobt,
    r.source,
    r.dep_airport,
    r.arr_airport,
    r.reported_utc
FROM dbo.cdm_pilot_readiness r
WHERE r.superseded_utc IS NULL;
GO

PRINT '+ Created view vw_cdm_current_readiness';
GO

-- Pending EDCT messages needing delivery
IF OBJECT_ID('dbo.vw_cdm_pending_messages', 'V') IS NOT NULL
    DROP VIEW dbo.vw_cdm_pending_messages;
GO

CREATE VIEW dbo.vw_cdm_pending_messages AS
SELECT
    m.message_id,
    m.message_guid,
    m.flight_uid,
    m.callsign,
    m.cid,
    m.message_type,
    m.message_body,
    m.delivery_channel,
    m.delivery_status,
    m.delivery_attempts,
    m.max_retries,
    m.program_id,
    m.slot_id,
    m.created_utc,
    m.expires_utc,
    m.is_hibernation_queued
FROM dbo.cdm_messages m
WHERE m.delivery_status = 'PENDING'
  AND (m.expires_utc IS NULL OR m.expires_utc > GETUTCDATE())
  AND m.delivery_attempts < m.max_retries;
GO

PRINT '+ Created view vw_cdm_pending_messages';
GO

-- At-risk flights (compliance issues detected)
IF OBJECT_ID('dbo.vw_cdm_at_risk_flights', 'V') IS NOT NULL
    DROP VIEW dbo.vw_cdm_at_risk_flights;
GO

CREATE VIEW dbo.vw_cdm_at_risk_flights AS
SELECT
    c.compliance_id,
    c.flight_uid,
    c.callsign,
    c.program_id,
    c.compliance_type,
    c.compliance_status,
    c.risk_level,
    c.expected_value,
    c.actual_value,
    c.delta_minutes,
    c.evaluated_utc
FROM dbo.cdm_compliance_live c
WHERE c.compliance_status = 'AT_RISK'
  AND c.is_final = 0;
GO

PRINT '+ Created view vw_cdm_at_risk_flights';
GO

-- ============================================================================
-- STORED PROCEDURES
-- ============================================================================

-- SP: Record a CDM message for delivery
IF OBJECT_ID('dbo.sp_CDM_QueueMessage', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CDM_QueueMessage;
GO

CREATE PROCEDURE dbo.sp_CDM_QueueMessage
    @flight_uid     BIGINT,
    @callsign       NVARCHAR(12),
    @cid            INT = NULL,
    @message_type   NVARCHAR(20),
    @message_body   NVARCHAR(500),
    @channel        NVARCHAR(20),
    @program_id     INT = NULL,
    @slot_id        INT = NULL,
    @expires_minutes INT = 120,
    @is_hibernation BIT = 0,
    @message_id     INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.cdm_messages (
        flight_uid, callsign, cid,
        message_type, message_body,
        delivery_channel, delivery_status,
        program_id, slot_id,
        expires_utc,
        is_hibernation_queued
    ) VALUES (
        @flight_uid, @callsign, @cid,
        @message_type, @message_body,
        @channel, CASE WHEN @is_hibernation = 1 THEN 'HIBERNATION_QUEUED' ELSE 'PENDING' END,
        @program_id, @slot_id,
        DATEADD(MINUTE, @expires_minutes, GETUTCDATE()),
        @is_hibernation
    );

    SET @message_id = SCOPE_IDENTITY();
END;
GO

PRINT '+ Created procedure sp_CDM_QueueMessage';
GO

-- SP: Record pilot readiness state change
IF OBJECT_ID('dbo.sp_CDM_UpdateReadiness', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CDM_UpdateReadiness;
GO

CREATE PROCEDURE dbo.sp_CDM_UpdateReadiness
    @flight_uid         BIGINT,
    @callsign           NVARCHAR(12),
    @cid                INT = NULL,
    @new_state          NVARCHAR(20),
    @source             NVARCHAR(20),
    @reported_tobt      DATETIME2(0) = NULL,
    @computed_tobt      DATETIME2(0) = NULL,
    @dep_airport        NVARCHAR(4) = NULL,
    @arr_airport        NVARCHAR(4) = NULL,
    @is_hibernation     BIT = 0,
    @readiness_id       INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @current_state NVARCHAR(20);

    -- Get current state
    SELECT TOP 1 @current_state = readiness_state
    FROM dbo.cdm_pilot_readiness
    WHERE flight_uid = @flight_uid AND superseded_utc IS NULL
    ORDER BY reported_utc DESC;

    -- Skip if same state
    IF @current_state = @new_state
    BEGIN
        SET @readiness_id = 0;
        RETURN;
    END

    -- Supersede previous entry
    UPDATE dbo.cdm_pilot_readiness
    SET superseded_utc = GETUTCDATE()
    WHERE flight_uid = @flight_uid AND superseded_utc IS NULL;

    -- Insert new state
    INSERT INTO dbo.cdm_pilot_readiness (
        flight_uid, callsign, cid,
        readiness_state, previous_state,
        reported_tobt, computed_tobt,
        source, dep_airport, arr_airport,
        is_hibernation_queued
    ) VALUES (
        @flight_uid, @callsign, @cid,
        @new_state, @current_state,
        @reported_tobt, @computed_tobt,
        @source, @dep_airport, @arr_airport,
        @is_hibernation
    );

    SET @readiness_id = SCOPE_IDENTITY();
END;
GO

PRINT '+ Created procedure sp_CDM_UpdateReadiness';
GO

-- SP: Evaluate EDCT compliance for a flight
IF OBJECT_ID('dbo.sp_CDM_EvaluateCompliance', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CDM_EvaluateCompliance;
GO

CREATE PROCEDURE dbo.sp_CDM_EvaluateCompliance
    @flight_uid         BIGINT,
    @callsign           NVARCHAR(12),
    @program_id         INT,
    @slot_id            INT = NULL,
    @compliance_type    NVARCHAR(20),
    @expected_value     NVARCHAR(50),
    @actual_value       NVARCHAR(50) = NULL,
    @delta_minutes      FLOAT = NULL,
    @is_final           BIT = 0,
    @is_hibernation     BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    -- VATSIM CDM tolerance: -5/+15 minutes (relaxed from EUROCONTROL -5/+10)
    DECLARE @tol_early FLOAT = -5.0;
    DECLARE @tol_late FLOAT = 15.0;

    DECLARE @status NVARCHAR(20);
    DECLARE @risk NVARCHAR(10) = NULL;

    IF @actual_value IS NULL AND @is_final = 0
    BEGIN
        -- Pre-departure: assess risk based on current state
        SET @status = 'PENDING';
        IF @delta_minutes IS NOT NULL
        BEGIN
            IF @delta_minutes > @tol_late
                BEGIN SET @status = 'AT_RISK'; SET @risk = 'HIGH'; END
            ELSE IF @delta_minutes > (@tol_late * 0.6)
                BEGIN SET @status = 'AT_RISK'; SET @risk = 'MEDIUM'; END
            ELSE IF @delta_minutes < @tol_early
                BEGIN SET @status = 'AT_RISK'; SET @risk = 'LOW'; END
        END
    END
    ELSE IF @is_final = 1
    BEGIN
        -- Post-departure: final assessment
        IF @delta_minutes IS NULL
            SET @status = 'EXEMPT';
        ELSE IF @delta_minutes >= @tol_early AND @delta_minutes <= @tol_late
            SET @status = 'COMPLIANT';
        ELSE
            SET @status = 'NON_COMPLIANT';
    END
    ELSE
        SET @status = 'PENDING';

    -- Upsert: update existing non-final record or insert new
    IF EXISTS (
        SELECT 1 FROM dbo.cdm_compliance_live
        WHERE flight_uid = @flight_uid
          AND program_id = @program_id
          AND compliance_type = @compliance_type
          AND is_final = 0
    )
    BEGIN
        UPDATE dbo.cdm_compliance_live
        SET compliance_status = @status,
            risk_level = @risk,
            expected_value = @expected_value,
            actual_value = @actual_value,
            delta_minutes = @delta_minutes,
            tolerance_min = @tol_early,
            tolerance_max = @tol_late,
            evaluated_utc = GETUTCDATE(),
            is_final = @is_final,
            finalized_utc = CASE WHEN @is_final = 1 THEN GETUTCDATE() ELSE NULL END,
            is_hibernation_queued = @is_hibernation
        WHERE flight_uid = @flight_uid
          AND program_id = @program_id
          AND compliance_type = @compliance_type
          AND is_final = 0;
    END
    ELSE
    BEGIN
        INSERT INTO dbo.cdm_compliance_live (
            flight_uid, callsign, program_id, slot_id,
            compliance_type, compliance_status, risk_level,
            expected_value, actual_value, delta_minutes,
            tolerance_min, tolerance_max,
            is_final, finalized_utc,
            is_hibernation_queued
        ) VALUES (
            @flight_uid, @callsign, @program_id, @slot_id,
            @compliance_type, @status, @risk,
            @expected_value, @actual_value, @delta_minutes,
            @tol_early, @tol_late,
            @is_final, CASE WHEN @is_final = 1 THEN GETUTCDATE() ELSE NULL END,
            @is_hibernation
        );
    END
END;
GO

PRINT '+ Created procedure sp_CDM_EvaluateCompliance';
GO

-- SP: Take airport CDM status snapshot
IF OBJECT_ID('dbo.sp_CDM_SnapshotAirportStatus', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CDM_SnapshotAirportStatus;
GO

CREATE PROCEDURE dbo.sp_CDM_SnapshotAirportStatus
    @airport_icao       NVARCHAR(4),
    @weather_category   NVARCHAR(10) = NULL,
    @aar                INT = NULL,
    @adr                INT = NULL,
    @is_hibernation     BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    -- Count readiness states for this airport
    DECLARE @ready INT = 0, @held INT = 0, @taxiing INT = 0;
    DECLARE @boarding INT = 0, @planning INT = 0;

    SELECT
        @ready    = SUM(CASE WHEN readiness_state = 'READY' THEN 1 ELSE 0 END),
        @boarding = SUM(CASE WHEN readiness_state = 'BOARDING' THEN 1 ELSE 0 END),
        @taxiing  = SUM(CASE WHEN readiness_state = 'TAXIING' THEN 1 ELSE 0 END),
        @planning = SUM(CASE WHEN readiness_state = 'PLANNING' THEN 1 ELSE 0 END)
    FROM dbo.vw_cdm_current_readiness
    WHERE dep_airport = @airport_icao;

    -- Check controlling program
    DECLARE @is_controlled BIT = 0;
    DECLARE @controlling_program INT = NULL;

    SELECT TOP 1
        @is_controlled = 1,
        @controlling_program = program_id
    FROM dbo.tmi_programs
    WHERE ctl_element = @airport_icao
      AND status = 'ACTIVE'
      AND is_active = 1
    ORDER BY activated_at DESC;

    -- Insert snapshot
    INSERT INTO dbo.cdm_airport_status (
        airport_icao, snapshot_utc,
        total_departures_next_hour,
        ready_count, gate_held_count, taxiing_count,
        boarding_count, planning_count,
        weather_category, aar, adr,
        is_controlled, controlling_program_id,
        is_hibernation_snapshot
    ) VALUES (
        @airport_icao, GETUTCDATE(),
        ISNULL(@ready, 0) + ISNULL(@boarding, 0) + ISNULL(@taxiing, 0),
        ISNULL(@ready, 0), @held, ISNULL(@taxiing, 0),
        ISNULL(@boarding, 0), ISNULL(@planning, 0),
        @weather_category, @aar, @adr,
        @is_controlled, @controlling_program,
        @is_hibernation
    );
END;
GO

PRINT '+ Created procedure sp_CDM_SnapshotAirportStatus';
GO

-- ============================================================================
-- RETENTION: Purge old CDM data
-- ============================================================================

IF OBJECT_ID('dbo.sp_CDM_PurgeOldData', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CDM_PurgeOldData;
GO

CREATE PROCEDURE dbo.sp_CDM_PurgeOldData
    @messages_days      INT = 30,
    @readiness_days     INT = 14,
    @compliance_days    INT = 90,
    @airport_status_days INT = 7,
    @trigger_log_days   INT = 30
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cutoff DATETIME2(0);
    DECLARE @deleted INT;

    -- Purge old messages
    SET @cutoff = DATEADD(DAY, -@messages_days, GETUTCDATE());
    DELETE FROM dbo.cdm_messages WHERE created_utc < @cutoff;
    SET @deleted = @@ROWCOUNT;
    IF @deleted > 0 PRINT 'Purged ' + CAST(@deleted AS VARCHAR) + ' cdm_messages older than ' + CAST(@messages_days AS VARCHAR) + ' days';

    -- Purge old readiness signals
    SET @cutoff = DATEADD(DAY, -@readiness_days, GETUTCDATE());
    DELETE FROM dbo.cdm_pilot_readiness WHERE reported_utc < @cutoff AND superseded_utc IS NOT NULL;
    SET @deleted = @@ROWCOUNT;
    IF @deleted > 0 PRINT 'Purged ' + CAST(@deleted AS VARCHAR) + ' superseded cdm_pilot_readiness older than ' + CAST(@readiness_days AS VARCHAR) + ' days';

    -- Purge old compliance (keep final assessments longer)
    SET @cutoff = DATEADD(DAY, -@compliance_days, GETUTCDATE());
    DELETE FROM dbo.cdm_compliance_live WHERE evaluated_utc < @cutoff;
    SET @deleted = @@ROWCOUNT;
    IF @deleted > 0 PRINT 'Purged ' + CAST(@deleted AS VARCHAR) + ' cdm_compliance_live older than ' + CAST(@compliance_days AS VARCHAR) + ' days';

    -- Purge old airport status snapshots
    SET @cutoff = DATEADD(DAY, -@airport_status_days, GETUTCDATE());
    DELETE FROM dbo.cdm_airport_status WHERE snapshot_utc < @cutoff;
    SET @deleted = @@ROWCOUNT;
    IF @deleted > 0 PRINT 'Purged ' + CAST(@deleted AS VARCHAR) + ' cdm_airport_status older than ' + CAST(@airport_status_days AS VARCHAR) + ' days';

    -- Purge old trigger logs
    SET @cutoff = DATEADD(DAY, -@trigger_log_days, GETUTCDATE());
    DELETE FROM dbo.cdm_trigger_log WHERE evaluated_utc < @cutoff;
    SET @deleted = @@ROWCOUNT;
    IF @deleted > 0 PRINT 'Purged ' + CAST(@deleted AS VARCHAR) + ' cdm_trigger_log older than ' + CAST(@trigger_log_days AS VARCHAR) + ' days';
END;
GO

PRINT '+ Created procedure sp_CDM_PurgeOldData';
GO

-- ============================================================================
-- SP: Post-process hibernation data
-- ============================================================================
-- Called after hibernation ends to process queued CDM data.
-- Marks items as processed and generates summary metrics.
-- ============================================================================

IF OBJECT_ID('dbo.sp_CDM_ProcessHibernationQueue', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CDM_ProcessHibernationQueue;
GO

CREATE PROCEDURE dbo.sp_CDM_ProcessHibernationQueue
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = GETUTCDATE();
    DECLARE @msg_count INT, @readiness_count INT, @compliance_count INT;

    -- Mark hibernation-queued messages as processed (they were never sent)
    UPDATE dbo.cdm_messages
    SET processed_utc = @now,
        delivery_status = 'EXPIRED'
    WHERE is_hibernation_queued = 1 AND processed_utc IS NULL;
    SET @msg_count = @@ROWCOUNT;

    -- Mark readiness signals as processed
    UPDATE dbo.cdm_pilot_readiness
    SET processed_utc = @now
    WHERE is_hibernation_queued = 1 AND processed_utc IS NULL;
    SET @readiness_count = @@ROWCOUNT;

    -- Mark compliance records as processed
    UPDATE dbo.cdm_compliance_live
    SET processed_utc = @now
    WHERE is_hibernation_queued = 1 AND processed_utc IS NULL;
    SET @compliance_count = @@ROWCOUNT;

    PRINT 'Hibernation post-processing complete:';
    PRINT '  Messages expired: ' + CAST(@msg_count AS VARCHAR);
    PRINT '  Readiness processed: ' + CAST(@readiness_count AS VARCHAR);
    PRINT '  Compliance processed: ' + CAST(@compliance_count AS VARCHAR);
END;
GO

PRINT '+ Created procedure sp_CDM_ProcessHibernationQueue';
GO

PRINT '';
PRINT '==========================================================================';
PRINT '  Migration 040 Complete: CDM Core Schema';
PRINT '';
PRINT '  Tables: cdm_messages, cdm_pilot_readiness, cdm_compliance_live,';
PRINT '          cdm_airport_status, cdm_triggers, cdm_trigger_log';
PRINT '  Views:  vw_cdm_current_readiness, vw_cdm_pending_messages,';
PRINT '          vw_cdm_at_risk_flights';
PRINT '  SPs:    sp_CDM_QueueMessage, sp_CDM_UpdateReadiness,';
PRINT '          sp_CDM_EvaluateCompliance, sp_CDM_SnapshotAirportStatus,';
PRINT '          sp_CDM_PurgeOldData, sp_CDM_ProcessHibernationQueue';
PRINT '==========================================================================';
GO
