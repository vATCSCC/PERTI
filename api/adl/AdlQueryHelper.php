<?php

/**
 * ADL Query Helper - Centralized query builder for normalized ADL tables
 *
 * Supports feature flag switching between:
 *   - 'view': Uses vw_adl_flights view (backward compatibility)
 *   - 'normalized': Direct queries to normalized tables with explicit JOINs
 *
 * Set ADL_QUERY_SOURCE in config.php to control behavior.
 */
class AdlQueryHelper {

    const SOURCE_VIEW = 'view';
    const SOURCE_NORMALIZED = 'normalized';

    private $source;

    /**
     * Constructor
     *
     * @param string|null $source Override source ('view' or 'normalized').
     *                            If null, reads from ADL_QUERY_SOURCE constant.
     */
    public function __construct($source = null) {
        $this->source = $source ?? $this->getConfiguredSource();
    }

    /**
     * Get configured query source from config constant
     */
    private function getConfiguredSource() {
        return defined('ADL_QUERY_SOURCE') ? ADL_QUERY_SOURCE : self::SOURCE_VIEW;
    }

    /**
     * Check if using normalized tables
     */
    public function isNormalized() {
        return $this->source === self::SOURCE_NORMALIZED;
    }

    /**
     * Get the current source setting
     */
    public function getSource() {
        return $this->source;
    }

    /**
     * Generate SQL phase aggregation columns for breakdown queries
     * Returns SUM(CASE WHEN...) expressions for each phase
     *
     * @param string $phaseCol The column/expression containing phase (e.g., 'phase' or 'c.phase')
     * @return string SQL fragment with phase aggregation columns
     */
    protected function getPhaseAggregationSQL($phaseCol = 'phase') {
        return "
            SUM(CASE WHEN {$phaseCol} = 'arrived' THEN 1 ELSE 0 END) AS phase_arrived,
            SUM(CASE WHEN {$phaseCol} = 'disconnected' THEN 1 ELSE 0 END) AS phase_disconnected,
            SUM(CASE WHEN {$phaseCol} = 'descending' THEN 1 ELSE 0 END) AS phase_descending,
            SUM(CASE WHEN {$phaseCol} = 'enroute' THEN 1 ELSE 0 END) AS phase_enroute,
            SUM(CASE WHEN {$phaseCol} = 'departed' THEN 1 ELSE 0 END) AS phase_departed,
            SUM(CASE WHEN {$phaseCol} = 'taxiing' THEN 1 ELSE 0 END) AS phase_taxiing,
            SUM(CASE WHEN {$phaseCol} = 'prefile' THEN 1 ELSE 0 END) AS phase_prefile,
            SUM(CASE WHEN {$phaseCol} NOT IN ('arrived', 'disconnected', 'descending', 'enroute', 'departed', 'taxiing', 'prefile') OR {$phaseCol} IS NULL THEN 1 ELSE 0 END) AS phase_unknown";
    }

    // =========================================================================
    // CURRENT FLIGHTS QUERY (api/adl/current.php)
    // Tables: core + position + plan + aircraft + times
    // =========================================================================

    /**
     * Build query for current flights endpoint
     *
     * @param array $options Query options:
     *   - 'activeOnly' => bool (default true)
     *   - 'callsign' => string (optional filter)
     *   - 'dep' => string (optional departure ICAO filter)
     *   - 'arr' => string (optional arrival ICAO filter)
     *   - 'limit' => int (default 10000, max 15000)
     * @return array ['sql' => string, 'params' => array]
     */
    public function buildCurrentFlightsQuery($options = []) {
        $activeOnly = $options['activeOnly'] ?? true;
        $callsign = $options['callsign'] ?? '';
        $dep = $options['dep'] ?? '';
        $arr = $options['arr'] ?? '';
        $limit = min(max((int)($options['limit'] ?? 10000), 1), 15000);

        if ($this->source === self::SOURCE_VIEW) {
            return $this->buildCurrentFlightsViewQuery($activeOnly, $callsign, $dep, $arr, $limit);
        }

        return $this->buildCurrentFlightsNormalizedQuery($activeOnly, $callsign, $dep, $arr, $limit);
    }

    private function buildCurrentFlightsViewQuery($activeOnly, $callsign, $dep, $arr, $limit) {
        $where = [];
        $params = [];

        if ($activeOnly) {
            $where[] = "is_active = 1";
        }
        if ($callsign !== '') {
            $where[] = "callsign = ?";
            $params[] = $callsign;
        }
        if ($dep !== '') {
            $where[] = "fp_dept_icao = ?";
            $params[] = $dep;
        }
        if ($arr !== '') {
            $where[] = "fp_dest_icao = ?";
            $params[] = $arr;
        }

        // Select all columns plus computed gs_flag for GS eligibility filtering
        // GS eligibility: only pre-departure flights (prefile, taxiing, scheduled) can receive EDCTs
        // Airborne/completed flights (departed, enroute, descending, arrived, disconnected) are NOT eligible
        $sql = "SELECT TOP {$limit} *,
                CASE WHEN phase IN ('prefile', 'taxiing', 'scheduled') THEN 1 ELSE 0 END AS gs_flag
                FROM dbo.vw_adl_flights";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY eta_epoch ASC, callsign ASC";

        return ['sql' => $sql, 'params' => $params];
    }

    private function buildCurrentFlightsNormalizedQuery($activeOnly, $callsign, $dep, $arr, $limit) {
        $where = [];
        $params = [];

        if ($activeOnly) {
            $where[] = "c.is_active = 1";
        }
        if ($callsign !== '') {
            $where[] = "c.callsign = ?";
            $params[] = $callsign;
        }
        if ($dep !== '') {
            $where[] = "fp.fp_dept_icao = ?";
            $params[] = $dep;
        }
        if ($arr !== '') {
            $where[] = "fp.fp_dest_icao = ?";
            $params[] = $arr;
        }

        $sql = "
            SELECT TOP {$limit}
                -- Core fields
                c.flight_uid,
                c.flight_key,
                c.callsign,
                c.cid,
                c.phase,
                c.is_active,
                c.first_seen_utc,
                c.last_seen_utc,
                c.logon_time_utc,
                c.snapshot_utc,

                -- GS Eligibility Flag (computed)
                -- Pre-departure flights (prefile, taxiing, scheduled) are eligible for TMI control
                -- Airborne and completed flights are NOT eligible
                CASE WHEN c.phase IN ('prefile', 'taxiing', 'scheduled') THEN 1 ELSE 0 END AS gs_flag,

                -- Position fields
                p.lat,
                p.lon,
                p.altitude_ft,
                p.altitude_assigned,
                p.groundspeed_kts,
                p.vertical_rate_fpm,
                p.heading_deg,
                p.track_deg,
                p.dist_to_dest_nm,
                p.pct_complete,

                -- Flight plan fields
                fp.fp_rule,
                fp.fp_dept_icao,
                fp.fp_dest_icao,
                fp.fp_alt_icao,
                fp.fp_dept_tracon,
                fp.fp_dept_artcc,
                fp.dfix,
                fp.dp_name,
                fp.fp_dest_tracon,
                fp.fp_dest_artcc,
                fp.afix,
                fp.star_name,
                fp.approach,
                fp.runway,
                fp.fp_route,
                fp.fp_route_expanded,
                fp.aircraft_type,
                fp.fp_dept_time_z,
                fp.fp_altitude_ft,
                fp.fp_tas_kts,
                fp.fp_enroute_minutes,
                fp.fp_remarks,
                fp.gcd_nm,
                fp.artccs_traversed,
                fp.tracons_traversed,

                -- Pre-parsed route data (use pre-computed columns for performance)
                -- Note: route_geometry_wkt removed - use /api/adl/flight.php for geometry
                fp.waypoints_json,

                -- Aircraft fields
                ac.aircraft_icao,
                ac.weight_class,
                ac.engine_type,
                ac.wake_category,
                ac.airline_icao,
                ac.airline_name,

                -- Time fields
                t.eta_runway_utc,
                t.etd_runway_utc,
                t.eta_epoch,
                t.etd_epoch,
                t.arrival_bucket_utc,
                t.departure_bucket_utc,
                t.ete_minutes

            FROM dbo.adl_flight_core c
            LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        ";

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY t.eta_epoch ASC, c.callsign ASC";

        return ['sql' => $sql, 'params' => $params];
    }

    // =========================================================================
    // STATS QUERY (api/adl/stats.php)
    // Tables: core + plan (only)
    // =========================================================================

    /**
     * Build query for flight statistics endpoint
     *
     * @return array ['sql' => string, 'params' => array]
     */
    public function buildStatsQuery() {
        if ($this->source === self::SOURCE_VIEW) {
            return $this->buildStatsViewQuery();
        }
        return $this->buildStatsNormalizedQuery();
    }

    private function buildStatsViewQuery() {
        $sql = "
            WITH flight_classifications AS (
                SELECT
                    f.flight_key,
                    f.fp_dept_icao,
                    f.fp_dest_icao,
                    CASE WHEN LEFT(f.fp_dept_icao, 1) IN ('K', 'P') THEN 1 ELSE 0 END AS dep_domestic,
                    CASE WHEN LEFT(f.fp_dest_icao, 1) IN ('K', 'P') THEN 1 ELSE 0 END AS arr_domestic,
                    dept_apt.DCC_REGION AS dep_dcc_region,
                    dest_apt.DCC_REGION AS arr_dcc_region,
                    dest_apt.ASPM82 AS arr_aspm82,
                    dest_apt.OEP35 AS arr_oep35,
                    dest_apt.Core30 AS arr_core30
                FROM dbo.vw_adl_flights f
                LEFT JOIN dbo.apts dept_apt ON dept_apt.ICAO_ID = f.fp_dept_icao
                LEFT JOIN dbo.apts dest_apt ON dest_apt.ICAO_ID = f.fp_dest_icao
                WHERE f.is_active = 1
            )
            SELECT
                COUNT(*) AS total_flights,
                SUM(CASE WHEN dep_domestic = 1 AND arr_domestic = 1 THEN 1 ELSE 0 END) AS domestic_to_domestic,
                SUM(CASE WHEN dep_domestic = 1 AND arr_domestic = 0 THEN 1 ELSE 0 END) AS domestic_to_intl,
                SUM(CASE WHEN dep_domestic = 0 AND arr_domestic = 1 THEN 1 ELSE 0 END) AS intl_to_domestic,
                SUM(CASE WHEN dep_domestic = 0 AND arr_domestic = 0 THEN 1 ELSE 0 END) AS intl_to_intl,
                SUM(CASE WHEN dep_domestic = 1 OR arr_domestic = 1 THEN 1 ELSE 0 END) AS domestic_total,
                SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'Northeast' THEN 1 ELSE 0 END) AS arr_dcc_ne,
                SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'Southeast' THEN 1 ELSE 0 END) AS arr_dcc_se,
                SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'Midwest' THEN 1 ELSE 0 END) AS arr_dcc_mw,
                SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'South Central' THEN 1 ELSE 0 END) AS arr_dcc_sc,
                SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'West' THEN 1 ELSE 0 END) AS arr_dcc_w,
                SUM(CASE WHEN arr_domestic = 1 AND (arr_dcc_region IS NULL OR arr_dcc_region NOT IN ('Northeast','Southeast','Midwest','South Central','West')) THEN 1 ELSE 0 END) AS arr_dcc_other,
                SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'Northeast' THEN 1 ELSE 0 END) AS dep_dcc_ne,
                SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'Southeast' THEN 1 ELSE 0 END) AS dep_dcc_se,
                SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'Midwest' THEN 1 ELSE 0 END) AS dep_dcc_mw,
                SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'South Central' THEN 1 ELSE 0 END) AS dep_dcc_sc,
                SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'West' THEN 1 ELSE 0 END) AS dep_dcc_w,
                SUM(CASE WHEN dep_domestic = 1 AND (dep_dcc_region IS NULL OR dep_dcc_region NOT IN ('Northeast','Southeast','Midwest','South Central','West')) THEN 1 ELSE 0 END) AS dep_dcc_other,
                SUM(CASE WHEN arr_domestic = 1 AND arr_aspm82 = 1 THEN 1 ELSE 0 END) AS arr_aspm82,
                SUM(CASE WHEN arr_domestic = 1 AND arr_oep35 = 1 THEN 1 ELSE 0 END) AS arr_oep35,
                SUM(CASE WHEN arr_domestic = 1 AND arr_core30 = 1 THEN 1 ELSE 0 END) AS arr_core30,
                SUM(CASE WHEN arr_domestic = 1 AND (arr_aspm82 = 0 OR arr_aspm82 IS NULL) THEN 1 ELSE 0 END) AS arr_non_aspm82,
                SUM(CASE WHEN arr_domestic = 1 AND (arr_oep35 = 0 OR arr_oep35 IS NULL) THEN 1 ELSE 0 END) AS arr_non_oep35,
                SUM(CASE WHEN arr_domestic = 1 AND (arr_core30 = 0 OR arr_core30 IS NULL) THEN 1 ELSE 0 END) AS arr_non_core30
            FROM flight_classifications
        ";

        return ['sql' => $sql, 'params' => []];
    }

    private function buildStatsNormalizedQuery() {
        // Uses only core + plan tables (no position, aircraft, times, or tmi needed)
        $sql = "
            WITH flight_classifications AS (
                SELECT
                    c.flight_key,
                    fp.fp_dept_icao,
                    fp.fp_dest_icao,
                    CASE WHEN LEFT(fp.fp_dept_icao, 1) IN ('K', 'P') THEN 1 ELSE 0 END AS dep_domestic,
                    CASE WHEN LEFT(fp.fp_dest_icao, 1) IN ('K', 'P') THEN 1 ELSE 0 END AS arr_domestic,
                    dept_apt.DCC_REGION AS dep_dcc_region,
                    dest_apt.DCC_REGION AS arr_dcc_region,
                    dest_apt.ASPM82 AS arr_aspm82,
                    dest_apt.OEP35 AS arr_oep35,
                    dest_apt.Core30 AS arr_core30
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                LEFT JOIN dbo.apts dept_apt ON dept_apt.ICAO_ID = fp.fp_dept_icao
                LEFT JOIN dbo.apts dest_apt ON dest_apt.ICAO_ID = fp.fp_dest_icao
                WHERE c.is_active = 1
            )
            SELECT
                COUNT(*) AS total_flights,
                SUM(CASE WHEN dep_domestic = 1 AND arr_domestic = 1 THEN 1 ELSE 0 END) AS domestic_to_domestic,
                SUM(CASE WHEN dep_domestic = 1 AND arr_domestic = 0 THEN 1 ELSE 0 END) AS domestic_to_intl,
                SUM(CASE WHEN dep_domestic = 0 AND arr_domestic = 1 THEN 1 ELSE 0 END) AS intl_to_domestic,
                SUM(CASE WHEN dep_domestic = 0 AND arr_domestic = 0 THEN 1 ELSE 0 END) AS intl_to_intl,
                SUM(CASE WHEN dep_domestic = 1 OR arr_domestic = 1 THEN 1 ELSE 0 END) AS domestic_total,
                SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'Northeast' THEN 1 ELSE 0 END) AS arr_dcc_ne,
                SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'Southeast' THEN 1 ELSE 0 END) AS arr_dcc_se,
                SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'Midwest' THEN 1 ELSE 0 END) AS arr_dcc_mw,
                SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'South Central' THEN 1 ELSE 0 END) AS arr_dcc_sc,
                SUM(CASE WHEN arr_domestic = 1 AND arr_dcc_region = 'West' THEN 1 ELSE 0 END) AS arr_dcc_w,
                SUM(CASE WHEN arr_domestic = 1 AND (arr_dcc_region IS NULL OR arr_dcc_region NOT IN ('Northeast','Southeast','Midwest','South Central','West')) THEN 1 ELSE 0 END) AS arr_dcc_other,
                SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'Northeast' THEN 1 ELSE 0 END) AS dep_dcc_ne,
                SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'Southeast' THEN 1 ELSE 0 END) AS dep_dcc_se,
                SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'Midwest' THEN 1 ELSE 0 END) AS dep_dcc_mw,
                SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'South Central' THEN 1 ELSE 0 END) AS dep_dcc_sc,
                SUM(CASE WHEN dep_domestic = 1 AND dep_dcc_region = 'West' THEN 1 ELSE 0 END) AS dep_dcc_w,
                SUM(CASE WHEN dep_domestic = 1 AND (dep_dcc_region IS NULL OR dep_dcc_region NOT IN ('Northeast','Southeast','Midwest','South Central','West')) THEN 1 ELSE 0 END) AS dep_dcc_other,
                SUM(CASE WHEN arr_domestic = 1 AND arr_aspm82 = 1 THEN 1 ELSE 0 END) AS arr_aspm82,
                SUM(CASE WHEN arr_domestic = 1 AND arr_oep35 = 1 THEN 1 ELSE 0 END) AS arr_oep35,
                SUM(CASE WHEN arr_domestic = 1 AND arr_core30 = 1 THEN 1 ELSE 0 END) AS arr_core30,
                SUM(CASE WHEN arr_domestic = 1 AND (arr_aspm82 = 0 OR arr_aspm82 IS NULL) THEN 1 ELSE 0 END) AS arr_non_aspm82,
                SUM(CASE WHEN arr_domestic = 1 AND (arr_oep35 = 0 OR arr_oep35 IS NULL) THEN 1 ELSE 0 END) AS arr_non_oep35,
                SUM(CASE WHEN arr_domestic = 1 AND (arr_core30 = 0 OR arr_core30 IS NULL) THEN 1 ELSE 0 END) AS arr_non_core30
            FROM flight_classifications
        ";

        return ['sql' => $sql, 'params' => []];
    }

    // =========================================================================
    // SINGLE FLIGHT QUERY (api/adl/flight.php)
    // Tables: All (core + position + plan + aircraft + times + tmi)
    // =========================================================================

    /**
     * Build query for single flight lookup
     *
     * @param array $options:
     *   - 'id' => int (flight_uid)
     *   - 'callsign' => string
     *   - 'activeOnly' => bool (default false)
     * @return array ['sql' => string, 'params' => array]
     */
    public function buildFlightLookupQuery($options = []) {
        $id = (int)($options['id'] ?? 0);
        $callsign = $options['callsign'] ?? '';
        $activeOnly = $options['activeOnly'] ?? false;

        if ($this->source === self::SOURCE_VIEW) {
            return $this->buildFlightLookupViewQuery($id, $callsign, $activeOnly);
        }
        return $this->buildFlightLookupNormalizedQuery($id, $callsign, $activeOnly);
    }

    private function buildFlightLookupViewQuery($id, $callsign, $activeOnly) {
        $where = [];
        $params = [];

        if ($id > 0) {
            $where[] = "id = ?";
            $params[] = $id;
        } elseif ($callsign !== '') {
            $where[] = "callsign = ?";
            $params[] = $callsign;
        }

        if ($activeOnly) {
            $where[] = "is_active = 1";
        }

        $sql = "SELECT TOP 1 * FROM dbo.vw_adl_flights";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY is_active DESC, last_seen_utc DESC, snapshot_utc DESC";

        return ['sql' => $sql, 'params' => $params];
    }

    private function buildFlightLookupNormalizedQuery($id, $callsign, $activeOnly) {
        $where = [];
        $params = [];

        if ($id > 0) {
            $where[] = "c.flight_uid = ?";
            $params[] = $id;
        } elseif ($callsign !== '') {
            $where[] = "c.callsign = ?";
            $params[] = $callsign;
        }

        if ($activeOnly) {
            $where[] = "c.is_active = 1";
        }

        // Full join of all tables for complete flight details
        $sql = "
            SELECT TOP 1
                -- Core
                c.flight_uid,
                c.flight_key,
                c.callsign,
                c.cid,
                c.flight_id,
                c.phase,
                c.last_source,
                c.is_active,
                c.first_seen_utc,
                c.last_seen_utc,
                c.logon_time_utc,
                c.adl_date,
                c.adl_time,
                c.snapshot_utc,

                -- Position
                p.lat,
                p.lon,
                p.altitude_ft,
                p.altitude_assigned,
                p.altitude_cleared,
                p.groundspeed_kts,
                p.true_airspeed_kts,
                p.mach,
                p.vertical_rate_fpm,
                p.heading_deg,
                p.track_deg,
                p.qnh_in_hg,
                p.qnh_mb,
                p.dist_to_dest_nm,
                p.dist_flown_nm,
                p.pct_complete,

                -- Flight plan
                fp.fp_rule,
                fp.fp_dept_icao,
                fp.fp_dest_icao,
                fp.fp_alt_icao,
                fp.fp_dept_tracon,
                fp.fp_dept_artcc,
                fp.dfix,
                fp.dp_name,
                fp.dtrsn,
                fp.fp_dest_tracon,
                fp.fp_dest_artcc,
                fp.afix,
                fp.star_name,
                fp.strsn,
                fp.approach,
                fp.runway,
                fp.eaft_utc,
                fp.fp_route,
                fp.fp_route_expanded,
                fp.waypoints_json,
                fp.waypoint_count,
                fp.parse_status,
                fp.dep_runway,
                fp.arr_runway,
                fp.initial_alt_ft,
                fp.final_alt_ft,
                fp.stepclimb_count,
                fp.is_simbrief,
                fp.simbrief_id,
                fp.cost_index,
                fp.fp_dept_time_z,
                fp.fp_altitude_ft,
                fp.fp_tas_kts,
                fp.fp_enroute_minutes,
                fp.fp_fuel_minutes,
                fp.fp_remarks,
                fp.gcd_nm,
                fp.aircraft_type,
                fp.aircraft_equip,
                fp.artccs_traversed,
                fp.tracons_traversed,

                -- Aircraft
                ac.aircraft_icao,
                ac.aircraft_faa,
                ac.weight_class,
                ac.engine_type,
                ac.engine_count,
                ac.wake_category,
                ac.cruise_tas_kts,
                ac.ceiling_ft,
                ac.airline_icao,
                ac.airline_name,

                -- Times
                t.std_utc,
                t.etd_utc,
                t.etd_runway_utc,
                t.atd_utc,
                t.atd_runway_utc,
                t.ctd_utc AS times_ctd_utc,
                t.edct_utc AS times_edct_utc,
                t.sta_utc,
                t.eta_utc,
                t.eta_runway_utc,
                t.eta_tfms_utc,
                t.ata_utc,
                t.ata_runway_utc,
                t.cta_utc AS times_cta_utc,
                t.eta_epoch,
                t.etd_epoch,
                t.arrival_bucket_utc,
                t.departure_bucket_utc,
                t.ete_minutes,
                t.ate_minutes,
                t.delay_minutes AS times_delay_minutes,

                -- TMI
                tmi.ctl_type,
                tmi.ctl_element,
                tmi.delay_status,
                tmi.delay_minutes AS tmi_delay_minutes,
                tmi.delay_source,
                tmi.ctd_utc AS tmi_ctd_utc,
                tmi.cta_utc AS tmi_cta_utc,
                tmi.edct_utc AS tmi_edct_utc,
                tmi.slot_time_utc,
                tmi.slot_status,
                tmi.is_exempt,
                tmi.exempt_reason,
                tmi.reroute_status,
                tmi.reroute_id

            FROM dbo.adl_flight_core c
            LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
        ";

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY c.is_active DESC, c.last_seen_utc DESC, c.snapshot_utc DESC";

        return ['sql' => $sql, 'params' => $params];
    }

    // =========================================================================
    // DEMAND AIRPORT QUERY (api/demand/airport.php)
    // Tables: core + plan + times
    // =========================================================================

    /**
     * Build query for demand aggregation by time bins
     *
     * @param array $options:
     *   - 'airport' => string (ICAO code)
     *   - 'direction' => 'arr'|'dep'
     *   - 'granularity' => '15min'|'hourly'
     *   - 'startSQL' => string (formatted datetime)
     *   - 'endSQL' => string (formatted datetime)
     * @return array ['sql' => string, 'params' => array]
     */
    public function buildDemandAggregationQuery($options = []) {
        $airport = $options['airport'] ?? '';
        $direction = $options['direction'] ?? 'arr';
        $granularity = $options['granularity'] ?? 'hourly';
        $startSQL = $options['startSQL'] ?? '';
        $endSQL = $options['endSQL'] ?? '';

        // Time column with COALESCE fallback: prefer runway time, fall back to general ETA/ETD
        $arrTimeExpr = "COALESCE(t.eta_runway_utc, t.eta_utc)";
        $depTimeExpr = "COALESCE(t.etd_runway_utc, t.etd_utc)";
        $timeExpr = $direction === 'arr' ? $arrTimeExpr : $depTimeExpr;

        // Time bin SQL based on granularity (15min, 30min, hourly)
        if ($granularity === '15min') {
            $timeBinSQL = "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', {$timeExpr}) / 15) * 15, '2000-01-01')";
        } elseif ($granularity === '30min') {
            $timeBinSQL = "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', {$timeExpr}) / 30) * 30, '2000-01-01')";
        } else {
            $timeBinSQL = "DATEADD(HOUR, DATEDIFF(HOUR, 0, {$timeExpr}), 0)";
        }

        if ($this->source === self::SOURCE_VIEW) {
            return $this->buildDemandAggregationViewQuery($airport, $direction, $timeBinSQL, $startSQL, $endSQL);
        }
        return $this->buildDemandAggregationNormalizedQuery($airport, $direction, $timeBinSQL, $startSQL, $endSQL, $timeExpr);
    }

    private function buildDemandAggregationViewQuery($airport, $direction, $timeBinSQL, $startSQL, $endSQL) {
        // For view mode, we reference columns without alias
        $timeBinSQL = str_replace('t.', '', $timeBinSQL);

        $airportCol = $direction === 'arr' ? 'fp_dest_icao' : 'fp_dept_icao';
        // Use COALESCE to fall back to general ETA/ETD if runway time is NULL
        $timeCol = $direction === 'arr'
            ? 'COALESCE(eta_runway_utc, eta_utc)'
            : 'COALESCE(etd_runway_utc, etd_utc)';

        // Individual phase breakdown for detailed demand visualization:
        //   - arrived: landed at destination (ONLY for past time bins - can't arrive in the future)
        //   - disconnected: disconnected mid-flight (never arrived)
        //   - descending: on approach to destination
        //   - enroute: cruising
        //   - departed: just took off from origin
        //   - taxiing: taxiing at origin airport
        //   - prefile: filed flight plan, not yet taxiing
        //   - unknown: catch-all for other/null phases
        //
        // IMPORTANT: Flights with phase='arrived' but time_bin in the future are excluded entirely.
        // These are flights that landed early and won't be arriving at their original ETA.
        $sql = "
            SELECT
                {$timeBinSQL} AS time_bin,
                COUNT(*) AS total,
                SUM(CASE WHEN phase = 'arrived' THEN 1 ELSE 0 END) AS arrived,
                SUM(CASE WHEN phase = 'disconnected' THEN 1 ELSE 0 END) AS disconnected,
                SUM(CASE WHEN phase = 'descending' THEN 1 ELSE 0 END) AS descending,
                SUM(CASE WHEN phase = 'enroute' THEN 1 ELSE 0 END) AS enroute,
                SUM(CASE WHEN phase = 'departed' THEN 1 ELSE 0 END) AS departed,
                SUM(CASE WHEN phase = 'taxiing' THEN 1 ELSE 0 END) AS taxiing,
                SUM(CASE WHEN phase = 'prefile' THEN 1 ELSE 0 END) AS prefile,
                SUM(CASE WHEN phase NOT IN ('arrived', 'disconnected', 'descending', 'enroute', 'departed', 'taxiing', 'prefile') OR phase IS NULL THEN 1 ELSE 0 END) AS unknown
            FROM dbo.vw_adl_flights
            WHERE {$airportCol} = ?
              AND {$timeCol} IS NOT NULL
              AND {$timeCol} >= ?
              AND {$timeCol} < ?
              AND (phase != 'arrived' OR {$timeBinSQL} < GETUTCDATE())
            GROUP BY {$timeBinSQL}
            ORDER BY time_bin
        ";

        return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL]];
    }

    private function buildDemandAggregationNormalizedQuery($airport, $direction, $timeBinSQL, $startSQL, $endSQL, $timeExpr = null) {
        $airportCol = $direction === 'arr' ? 'fp.fp_dest_icao' : 'fp.fp_dept_icao';
        // Use provided time expression with COALESCE fallback, or default to runway time
        $timeCol = $timeExpr ?? ($direction === 'arr' ? 't.eta_runway_utc' : 't.etd_runway_utc');

        // Individual phase breakdown for detailed demand visualization:
        //   - arrived: landed at destination (ONLY for past time bins - can't arrive in the future)
        //   - disconnected: disconnected mid-flight (never arrived)
        //   - descending: on approach to destination
        //   - enroute: cruising
        //   - departed: just took off from origin
        //   - taxiing: taxiing at origin airport
        //   - prefile: filed flight plan, not yet taxiing
        //   - unknown: catch-all for other/null phases
        //
        // IMPORTANT: Flights with phase='arrived' but time_bin in the future are excluded entirely.
        // These are flights that landed early and won't be arriving at their original ETA.
        $sql = "
            SELECT
                {$timeBinSQL} AS time_bin,
                COUNT(*) AS total,
                SUM(CASE WHEN c.phase = 'arrived' THEN 1 ELSE 0 END) AS arrived,
                SUM(CASE WHEN c.phase = 'disconnected' THEN 1 ELSE 0 END) AS disconnected,
                SUM(CASE WHEN c.phase = 'descending' THEN 1 ELSE 0 END) AS descending,
                SUM(CASE WHEN c.phase = 'enroute' THEN 1 ELSE 0 END) AS enroute,
                SUM(CASE WHEN c.phase = 'departed' THEN 1 ELSE 0 END) AS departed,
                SUM(CASE WHEN c.phase = 'taxiing' THEN 1 ELSE 0 END) AS taxiing,
                SUM(CASE WHEN c.phase = 'prefile' THEN 1 ELSE 0 END) AS prefile,
                SUM(CASE WHEN c.phase NOT IN ('arrived', 'disconnected', 'descending', 'enroute', 'departed', 'taxiing', 'prefile') OR c.phase IS NULL THEN 1 ELSE 0 END) AS unknown
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
            WHERE {$airportCol} = ?
              AND {$timeCol} IS NOT NULL
              AND {$timeCol} >= ?
              AND {$timeCol} < ?
              AND (c.phase != 'arrived' OR {$timeBinSQL} < GETUTCDATE())
            GROUP BY {$timeBinSQL}
            ORDER BY time_bin
        ";

        return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL]];
    }

    // =========================================================================
    // DEMAND SUMMARY QUERIES (api/demand/summary.php)
    // Tables: core + plan + times
    // =========================================================================

    /**
     * Build query for top origin ARTCCs
     */
    public function buildTopOriginsQuery($airport, $startSQL, $endSQL) {
        if ($this->source === self::SOURCE_VIEW) {
            $sql = "
                SELECT TOP 10
                    fp_dept_artcc AS artcc,
                    COUNT(*) AS count
                FROM dbo.vw_adl_flights
                WHERE fp_dest_icao = ?
                  AND COALESCE(eta_runway_utc, eta_utc) IS NOT NULL
                  AND COALESCE(eta_runway_utc, eta_utc) >= ?
                  AND COALESCE(eta_runway_utc, eta_utc) < ?
                  AND fp_dept_artcc IS NOT NULL
                  AND fp_dept_artcc != ''
                GROUP BY fp_dept_artcc
                ORDER BY count DESC
            ";
        } else {
            $sql = "
                SELECT TOP 10
                    fp.fp_dept_artcc AS artcc,
                    COUNT(*) AS count
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE fp.fp_dest_icao = ?
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) IS NOT NULL
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) >= ?
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) < ?
                  AND fp.fp_dept_artcc IS NOT NULL
                  AND fp.fp_dept_artcc != ''
                GROUP BY fp.fp_dept_artcc
                ORDER BY count DESC
            ";
        }

        return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL]];
    }

    /**
     * Build query for top carriers (both directions)
     */
    public function buildTopCarriersBothQuery($airport, $startSQL, $endSQL) {
        if ($this->source === self::SOURCE_VIEW) {
            $sql = "
                SELECT TOP 10 carrier, SUM(cnt) AS count FROM (
                    SELECT LEFT(callsign, 3) AS carrier, COUNT(*) AS cnt
                    FROM dbo.vw_adl_flights
                    WHERE fp_dest_icao = ?
                      AND COALESCE(eta_runway_utc, eta_utc) >= ?
                      AND COALESCE(eta_runway_utc, eta_utc) < ?
                      AND callsign IS NOT NULL
                      AND LEN(callsign) >= 3
                    GROUP BY LEFT(callsign, 3)
                    UNION ALL
                    SELECT LEFT(callsign, 3) AS carrier, COUNT(*) AS cnt
                    FROM dbo.vw_adl_flights
                    WHERE fp_dept_icao = ?
                      AND COALESCE(etd_runway_utc, etd_utc) >= ?
                      AND COALESCE(etd_runway_utc, etd_utc) < ?
                      AND callsign IS NOT NULL
                      AND LEN(callsign) >= 3
                    GROUP BY LEFT(callsign, 3)
                ) combined
                GROUP BY carrier
                ORDER BY count DESC
            ";
        } else {
            $sql = "
                SELECT TOP 10 carrier, SUM(cnt) AS count FROM (
                    SELECT LEFT(c.callsign, 3) AS carrier, COUNT(*) AS cnt
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    WHERE fp.fp_dest_icao = ?
                      AND COALESCE(t.eta_runway_utc, t.eta_utc) >= ?
                      AND COALESCE(t.eta_runway_utc, t.eta_utc) < ?
                      AND c.callsign IS NOT NULL
                      AND LEN(c.callsign) >= 3
                    GROUP BY LEFT(c.callsign, 3)
                    UNION ALL
                    SELECT LEFT(c.callsign, 3) AS carrier, COUNT(*) AS cnt
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    WHERE fp.fp_dept_icao = ?
                      AND COALESCE(t.etd_runway_utc, t.etd_utc) >= ?
                      AND COALESCE(t.etd_runway_utc, t.etd_utc) < ?
                      AND c.callsign IS NOT NULL
                      AND LEN(c.callsign) >= 3
                    GROUP BY LEFT(c.callsign, 3)
                ) combined
                GROUP BY carrier
                ORDER BY count DESC
            ";
        }

        return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
    }

    /**
     * Build query for top carriers (single direction)
     */
    public function buildTopCarriersSingleQuery($airport, $startSQL, $endSQL, $direction) {
        $airportCol = $direction === 'arr' ? 'fp_dest_icao' : 'fp_dept_icao';
        $timeCol = $direction === 'arr'
            ? 'COALESCE(eta_runway_utc, eta_utc)'
            : 'COALESCE(etd_runway_utc, etd_utc)';

        if ($this->source === self::SOURCE_VIEW) {
            $sql = "
                SELECT TOP 10
                    LEFT(callsign, 3) AS carrier,
                    COUNT(*) AS count
                FROM dbo.vw_adl_flights
                WHERE {$airportCol} = ?
                  AND {$timeCol} >= ?
                  AND {$timeCol} < ?
                  AND callsign IS NOT NULL
                  AND LEN(callsign) >= 3
                GROUP BY LEFT(callsign, 3)
                ORDER BY count DESC
            ";
        } else {
            $airportColN = $direction === 'arr' ? 'fp.fp_dest_icao' : 'fp.fp_dept_icao';
            $timeColN = $direction === 'arr'
                ? 'COALESCE(t.eta_runway_utc, t.eta_utc)'
                : 'COALESCE(t.etd_runway_utc, t.etd_utc)';

            $sql = "
                SELECT TOP 10
                    LEFT(c.callsign, 3) AS carrier,
                    COUNT(*) AS count
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE {$airportColN} = ?
                  AND {$timeColN} >= ?
                  AND {$timeColN} < ?
                  AND c.callsign IS NOT NULL
                  AND LEN(c.callsign) >= 3
                GROUP BY LEFT(c.callsign, 3)
                ORDER BY count DESC
            ";
        }

        return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL]];
    }

    /**
     * Build query for origin ARTCC breakdown by time bin
     */
    public function buildOriginARTCCBreakdownQuery($airport, $startSQL, $endSQL) {
        if ($this->source === self::SOURCE_VIEW) {
            $sql = "
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(eta_runway_utc, eta_utc)), 0) AS time_bin,
                    fp_dept_artcc AS artcc,
                    COUNT(*) AS count
                FROM dbo.vw_adl_flights
                WHERE fp_dest_icao = ?
                  AND COALESCE(eta_runway_utc, eta_utc) IS NOT NULL
                  AND COALESCE(eta_runway_utc, eta_utc) >= ?
                  AND COALESCE(eta_runway_utc, eta_utc) < ?
                  AND fp_dept_artcc IS NOT NULL
                  AND fp_dept_artcc != ''
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(eta_runway_utc, eta_utc)), 0), fp_dept_artcc
                ORDER BY time_bin, count DESC
            ";
        } else {
            $sql = "
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.eta_runway_utc, t.eta_utc)), 0) AS time_bin,
                    fp.fp_dept_artcc AS artcc,
                    COUNT(*) AS count
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE fp.fp_dest_icao = ?
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) IS NOT NULL
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) >= ?
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) < ?
                  AND fp.fp_dept_artcc IS NOT NULL
                  AND fp.fp_dept_artcc != ''
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.eta_runway_utc, t.eta_utc)), 0), fp.fp_dept_artcc
                ORDER BY time_bin, count DESC
            ";
        }

        return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL]];
    }

    /**
     * Build query for destination ARTCC breakdown by time bin (departures)
     */
    public function buildDestARTCCBreakdownQuery($airport, $startSQL, $endSQL) {
        if ($this->source === self::SOURCE_VIEW) {
            $sql = "
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(etd_runway_utc, etd_utc)), 0) AS time_bin,
                    fp_dest_artcc AS artcc,
                    COUNT(*) AS count
                FROM dbo.vw_adl_flights
                WHERE fp_dept_icao = ?
                  AND COALESCE(etd_runway_utc, etd_utc) IS NOT NULL
                  AND COALESCE(etd_runway_utc, etd_utc) >= ?
                  AND COALESCE(etd_runway_utc, etd_utc) < ?
                  AND fp_dest_artcc IS NOT NULL
                  AND fp_dest_artcc != ''
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(etd_runway_utc, etd_utc)), 0), fp_dest_artcc
                ORDER BY time_bin, count DESC
            ";
        } else {
            $sql = "
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.etd_runway_utc, t.etd_utc)), 0) AS time_bin,
                    fp.fp_dest_artcc AS artcc,
                    COUNT(*) AS count
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE fp.fp_dept_icao = ?
                  AND COALESCE(t.etd_runway_utc, t.etd_utc) IS NOT NULL
                  AND COALESCE(t.etd_runway_utc, t.etd_utc) >= ?
                  AND COALESCE(t.etd_runway_utc, t.etd_utc) < ?
                  AND fp.fp_dest_artcc IS NOT NULL
                  AND fp.fp_dest_artcc != ''
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.etd_runway_utc, t.etd_utc)), 0), fp.fp_dest_artcc
                ORDER BY time_bin, count DESC
            ";
        }

        return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL]];
    }

    /**
     * Build query for weight class breakdown by time bin
     */
    public function buildWeightClassBreakdownQuery($airport, $direction, $startSQL, $endSQL) {
        if ($this->source === self::SOURCE_VIEW) {
            if ($direction === 'both') {
                // Combined arrivals and departures
                $sql = "
                    WITH Combined AS (
                        SELECT COALESCE(eta_runway_utc, eta_utc) AS op_time, weight_class
                        FROM dbo.vw_adl_flights WHERE fp_dest_icao = ?
                        UNION ALL
                        SELECT COALESCE(etd_runway_utc, etd_utc) AS op_time, weight_class
                        FROM dbo.vw_adl_flights WHERE fp_dept_icao = ?
                    )
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0) AS time_bin,
                        COALESCE(weight_class, 'UNKNOWN') AS weight_class,
                        COUNT(*) AS count
                    FROM Combined
                    WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0), COALESCE(weight_class, 'UNKNOWN')
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $airport, $startSQL, $endSQL]];
            } else {
                $timeCol = $direction === 'arr' ? 'COALESCE(eta_runway_utc, eta_utc)' : 'COALESCE(etd_runway_utc, etd_utc)';
                $airportCol = $direction === 'arr' ? 'fp_dest_icao' : 'fp_dept_icao';
                $sql = "
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, $timeCol), 0) AS time_bin,
                        COALESCE(weight_class, 'UNKNOWN') AS weight_class,
                        COUNT(*) AS count
                    FROM dbo.vw_adl_flights
                    WHERE $airportCol = ?
                      AND $timeCol IS NOT NULL
                      AND $timeCol >= ?
                      AND $timeCol < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, $timeCol), 0), COALESCE(weight_class, 'UNKNOWN')
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL]];
            }
        } else {
            if ($direction === 'both') {
                $sql = "
                    WITH Combined AS (
                        SELECT COALESCE(t.eta_runway_utc, t.eta_utc) AS op_time, a.weight_class
                        FROM dbo.adl_flight_core c
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
                        WHERE fp.fp_dest_icao = ?
                        UNION ALL
                        SELECT COALESCE(t.etd_runway_utc, t.etd_utc) AS op_time, a.weight_class
                        FROM dbo.adl_flight_core c
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
                        WHERE fp.fp_dept_icao = ?
                    )
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0) AS time_bin,
                        COALESCE(weight_class, 'UNKNOWN') AS weight_class,
                        COUNT(*) AS count
                    FROM Combined
                    WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0), COALESCE(weight_class, 'UNKNOWN')
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $airport, $startSQL, $endSQL]];
            } else {
                $tTimeCol = $direction === 'arr' ? 'COALESCE(t.eta_runway_utc, t.eta_utc)' : 'COALESCE(t.etd_runway_utc, t.etd_utc)';
                $fpAirportCol = $direction === 'arr' ? 'fp.fp_dest_icao' : 'fp.fp_dept_icao';
                $sql = "
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, $tTimeCol), 0) AS time_bin,
                        COALESCE(a.weight_class, 'UNKNOWN') AS weight_class,
                        COUNT(*) AS count
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
                    WHERE $fpAirportCol = ?
                      AND $tTimeCol IS NOT NULL
                      AND $tTimeCol >= ?
                      AND $tTimeCol < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, $tTimeCol), 0), COALESCE(a.weight_class, 'UNKNOWN')
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL]];
            }
        }
    }

    /**
     * Build query for carrier/airline breakdown by time bin (top N + other)
     */
    public function buildCarrierBreakdownQuery($airport, $direction, $startSQL, $endSQL, $topN = 10) {
        $phaseAgg = $this->getPhaseAggregationSQL('phase');

        if ($this->source === self::SOURCE_VIEW) {
            if ($direction === 'both') {
                $sql = "
                    WITH Combined AS (
                        SELECT COALESCE(eta_runway_utc, eta_utc) AS op_time, airline_icao, phase
                        FROM dbo.vw_adl_flights WHERE fp_dest_icao = ?
                        UNION ALL
                        SELECT COALESCE(etd_runway_utc, etd_utc) AS op_time, airline_icao, phase
                        FROM dbo.vw_adl_flights WHERE fp_dept_icao = ?
                    ),
                    TopCarriers AS (
                        SELECT TOP $topN airline_icao
                        FROM Combined
                        WHERE op_time >= ? AND op_time < ?
                          AND airline_icao IS NOT NULL AND airline_icao != ''
                        GROUP BY airline_icao
                        ORDER BY COUNT(*) DESC
                    )
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0) AS time_bin,
                        CASE WHEN airline_icao IN (SELECT airline_icao FROM TopCarriers) THEN airline_icao ELSE 'OTHER' END AS carrier,
                        COUNT(*) AS count,
                        {$phaseAgg}
                    FROM Combined
                    WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0),
                             CASE WHEN airline_icao IN (SELECT airline_icao FROM TopCarriers) THEN airline_icao ELSE 'OTHER' END
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $airport, $startSQL, $endSQL, $startSQL, $endSQL]];
            } else {
                $timeCol = $direction === 'arr' ? 'COALESCE(eta_runway_utc, eta_utc)' : 'COALESCE(etd_runway_utc, etd_utc)';
                $airportCol = $direction === 'arr' ? 'fp_dest_icao' : 'fp_dept_icao';
                $sql = "
                    WITH TopCarriers AS (
                        SELECT TOP $topN airline_icao
                        FROM dbo.vw_adl_flights
                        WHERE $airportCol = ?
                          AND $timeCol >= ?
                          AND $timeCol < ?
                          AND airline_icao IS NOT NULL AND airline_icao != ''
                        GROUP BY airline_icao
                        ORDER BY COUNT(*) DESC
                    )
                        COUNT(*) AS count,
                        {$phaseAgg}
                    FROM dbo.vw_adl_flights
                    WHERE $airportCol = ?
                      AND $timeCol IS NOT NULL
                      AND $timeCol >= ?
                      AND $timeCol < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, $timeCol), 0),
                             CASE WHEN airline_icao IN (SELECT airline_icao FROM TopCarriers) THEN airline_icao ELSE 'OTHER' END
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
            }
        } else {
            $phaseAggNorm = $this->getPhaseAggregationSQL('c.phase');
            if ($direction === 'both') {
                $sql = "
                    WITH Combined AS (
                        SELECT COALESCE(t.eta_runway_utc, t.eta_utc) AS op_time, a.airline_icao, c.phase
                        FROM dbo.adl_flight_core c
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
                        WHERE fp.fp_dest_icao = ?
                        UNION ALL
                        SELECT COALESCE(t.etd_runway_utc, t.etd_utc) AS op_time, a.airline_icao, c.phase
                        FROM dbo.adl_flight_core c
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
                        WHERE fp.fp_dept_icao = ?
                    ),
                    TopCarriers AS (
                        SELECT TOP $topN airline_icao
                        FROM Combined
                        WHERE op_time >= ? AND op_time < ?
                          AND airline_icao IS NOT NULL AND airline_icao != ''
                        GROUP BY airline_icao
                        ORDER BY COUNT(*) DESC
                    )
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0) AS time_bin,
                        CASE WHEN airline_icao IN (SELECT airline_icao FROM TopCarriers) THEN airline_icao ELSE 'OTHER' END AS carrier,
                        COUNT(*) AS count,
                        {$this->getPhaseAggregationSQL('phase')}
                    FROM Combined
                    WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0),
                             CASE WHEN airline_icao IN (SELECT airline_icao FROM TopCarriers) THEN airline_icao ELSE 'OTHER' END
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $airport, $startSQL, $endSQL, $startSQL, $endSQL]];
            } else {
                $tTimeCol = $direction === 'arr' ? 'COALESCE(t.eta_runway_utc, t.eta_utc)' : 'COALESCE(t.etd_runway_utc, t.etd_utc)';
                $fpAirportCol = $direction === 'arr' ? 'fp.fp_dest_icao' : 'fp.fp_dept_icao';
                $sql = "
                    WITH TopCarriers AS (
                        SELECT TOP $topN a.airline_icao
                        FROM dbo.adl_flight_core c
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
                        WHERE $fpAirportCol = ?
                          AND $tTimeCol >= ?
                          AND $tTimeCol < ?
                          AND a.airline_icao IS NOT NULL AND a.airline_icao != ''
                        GROUP BY a.airline_icao
                        ORDER BY COUNT(*) DESC
                    )
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, $tTimeCol), 0) AS time_bin,
                        CASE WHEN a.airline_icao IN (SELECT airline_icao FROM TopCarriers) THEN a.airline_icao ELSE 'OTHER' END AS carrier,
                        COUNT(*) AS count,
                        {$phaseAggNorm}
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_aircraft a ON a.flight_uid = c.flight_uid
                    WHERE $fpAirportCol = ?
                      AND $tTimeCol IS NOT NULL
                      AND $tTimeCol >= ?
                      AND $tTimeCol < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, $tTimeCol), 0),
                             CASE WHEN a.airline_icao IN (SELECT airline_icao FROM TopCarriers) THEN a.airline_icao ELSE 'OTHER' END
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
            }
        }
    }

    /**
     * Build query for equipment/aircraft type breakdown by time bin (top N + other)
     */
    public function buildEquipmentBreakdownQuery($airport, $direction, $startSQL, $endSQL, $topN = 10) {
        if ($this->source === self::SOURCE_VIEW) {
            if ($direction === 'both') {
                $sql = "
                    WITH Combined AS (
                        SELECT COALESCE(eta_runway_utc, eta_utc) AS op_time, aircraft_type
                        FROM dbo.vw_adl_flights WHERE fp_dest_icao = ?
                        UNION ALL
                        SELECT COALESCE(etd_runway_utc, etd_utc) AS op_time, aircraft_type
                        FROM dbo.vw_adl_flights WHERE fp_dept_icao = ?
                    ),
                    TopEquipment AS (
                        SELECT TOP $topN aircraft_type
                        FROM Combined
                        WHERE op_time >= ? AND op_time < ?
                          AND aircraft_type IS NOT NULL AND aircraft_type != ''
                        GROUP BY aircraft_type
                        ORDER BY COUNT(*) DESC
                    )
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0) AS time_bin,
                        CASE WHEN aircraft_type IN (SELECT aircraft_type FROM TopEquipment) THEN aircraft_type ELSE 'OTHER' END AS equipment,
                        COUNT(*) AS count
                    FROM Combined
                    WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0),
                             CASE WHEN aircraft_type IN (SELECT aircraft_type FROM TopEquipment) THEN aircraft_type ELSE 'OTHER' END
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $airport, $startSQL, $endSQL, $startSQL, $endSQL]];
            } else {
                $timeCol = $direction === 'arr' ? 'COALESCE(eta_runway_utc, eta_utc)' : 'COALESCE(etd_runway_utc, etd_utc)';
                $airportCol = $direction === 'arr' ? 'fp_dest_icao' : 'fp_dept_icao';
                $sql = "
                    WITH TopEquipment AS (
                        SELECT TOP $topN aircraft_type
                        FROM dbo.vw_adl_flights
                        WHERE $airportCol = ?
                          AND $timeCol >= ?
                          AND $timeCol < ?
                          AND aircraft_type IS NOT NULL AND aircraft_type != ''
                        GROUP BY aircraft_type
                        ORDER BY COUNT(*) DESC
                    )
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, $timeCol), 0) AS time_bin,
                        CASE WHEN aircraft_type IN (SELECT aircraft_type FROM TopEquipment) THEN aircraft_type ELSE 'OTHER' END AS equipment,
                        COUNT(*) AS count
                    FROM dbo.vw_adl_flights
                    WHERE $airportCol = ?
                      AND $timeCol IS NOT NULL
                      AND $timeCol >= ?
                      AND $timeCol < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, $timeCol), 0),
                             CASE WHEN aircraft_type IN (SELECT aircraft_type FROM TopEquipment) THEN aircraft_type ELSE 'OTHER' END
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
            }
        } else {
            if ($direction === 'both') {
                $sql = "
                    WITH Combined AS (
                        SELECT COALESCE(t.eta_runway_utc, t.eta_utc) AS op_time, fp.aircraft_type
                        FROM dbo.adl_flight_core c
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                        WHERE fp.fp_dest_icao = ?
                        UNION ALL
                        SELECT COALESCE(t.etd_runway_utc, t.etd_utc) AS op_time, fp.aircraft_type
                        FROM dbo.adl_flight_core c
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                        WHERE fp.fp_dept_icao = ?
                    ),
                    TopEquipment AS (
                        SELECT TOP $topN aircraft_type
                        FROM Combined
                        WHERE op_time >= ? AND op_time < ?
                          AND aircraft_type IS NOT NULL AND aircraft_type != ''
                        GROUP BY aircraft_type
                        ORDER BY COUNT(*) DESC
                    )
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0) AS time_bin,
                        CASE WHEN aircraft_type IN (SELECT aircraft_type FROM TopEquipment) THEN aircraft_type ELSE 'OTHER' END AS equipment,
                        COUNT(*) AS count
                    FROM Combined
                    WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0),
                             CASE WHEN aircraft_type IN (SELECT aircraft_type FROM TopEquipment) THEN aircraft_type ELSE 'OTHER' END
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $airport, $startSQL, $endSQL, $startSQL, $endSQL]];
            } else {
                $tTimeCol = $direction === 'arr' ? 'COALESCE(t.eta_runway_utc, t.eta_utc)' : 'COALESCE(t.etd_runway_utc, t.etd_utc)';
                $fpAirportCol = $direction === 'arr' ? 'fp.fp_dest_icao' : 'fp.fp_dept_icao';
                $sql = "
                    WITH TopEquipment AS (
                        SELECT TOP $topN fp.aircraft_type
                        FROM dbo.adl_flight_core c
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                        WHERE $fpAirportCol = ?
                          AND $tTimeCol >= ?
                          AND $tTimeCol < ?
                          AND fp.aircraft_type IS NOT NULL AND fp.aircraft_type != ''
                        GROUP BY fp.aircraft_type
                        ORDER BY COUNT(*) DESC
                    )
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, $tTimeCol), 0) AS time_bin,
                        CASE WHEN fp.aircraft_type IN (SELECT aircraft_type FROM TopEquipment) THEN fp.aircraft_type ELSE 'OTHER' END AS equipment,
                        COUNT(*) AS count
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    WHERE $fpAirportCol = ?
                      AND $tTimeCol IS NOT NULL
                      AND $tTimeCol >= ?
                      AND $tTimeCol < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, $tTimeCol), 0),
                             CASE WHEN fp.aircraft_type IN (SELECT aircraft_type FROM TopEquipment) THEN fp.aircraft_type ELSE 'OTHER' END
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
            }
        }
    }

    /**
     * Build query for flight rule (IFR/VFR) breakdown by time bin
     */
    public function buildFlightRuleBreakdownQuery($airport, $direction, $startSQL, $endSQL) {
        if ($this->source === self::SOURCE_VIEW) {
            if ($direction === 'both') {
                $sql = "
                    WITH Combined AS (
                        SELECT COALESCE(eta_runway_utc, eta_utc) AS op_time, fp_rule
                        FROM dbo.vw_adl_flights WHERE fp_dest_icao = ?
                        UNION ALL
                        SELECT COALESCE(etd_runway_utc, etd_utc) AS op_time, fp_rule
                        FROM dbo.vw_adl_flights WHERE fp_dept_icao = ?
                    )
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0) AS time_bin,
                        COALESCE(fp_rule, 'I') AS rule,
                        COUNT(*) AS count
                    FROM Combined
                    WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0), COALESCE(fp_rule, 'I')
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $airport, $startSQL, $endSQL]];
            } else {
                $timeCol = $direction === 'arr' ? 'COALESCE(eta_runway_utc, eta_utc)' : 'COALESCE(etd_runway_utc, etd_utc)';
                $airportCol = $direction === 'arr' ? 'fp_dest_icao' : 'fp_dept_icao';
                $sql = "
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, $timeCol), 0) AS time_bin,
                        COALESCE(fp_rule, 'I') AS rule,
                        COUNT(*) AS count
                    FROM dbo.vw_adl_flights
                    WHERE $airportCol = ?
                      AND $timeCol IS NOT NULL
                      AND $timeCol >= ?
                      AND $timeCol < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, $timeCol), 0), COALESCE(fp_rule, 'I')
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL]];
            }
        } else {
            if ($direction === 'both') {
                $sql = "
                    WITH Combined AS (
                        SELECT COALESCE(t.eta_runway_utc, t.eta_utc) AS op_time, fp.fp_rule
                        FROM dbo.adl_flight_core c
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                        WHERE fp.fp_dest_icao = ?
                        UNION ALL
                        SELECT COALESCE(t.etd_runway_utc, t.etd_utc) AS op_time, fp.fp_rule
                        FROM dbo.adl_flight_core c
                        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                        WHERE fp.fp_dept_icao = ?
                    )
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0) AS time_bin,
                        COALESCE(fp_rule, 'I') AS rule,
                        COUNT(*) AS count
                    FROM Combined
                    WHERE op_time IS NOT NULL AND op_time >= ? AND op_time < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, op_time), 0), COALESCE(fp_rule, 'I')
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $airport, $startSQL, $endSQL]];
            } else {
                $tTimeCol = $direction === 'arr' ? 'COALESCE(t.eta_runway_utc, t.eta_utc)' : 'COALESCE(t.etd_runway_utc, t.etd_utc)';
                $fpAirportCol = $direction === 'arr' ? 'fp.fp_dest_icao' : 'fp.fp_dept_icao';
                $sql = "
                    SELECT
                        DATEADD(HOUR, DATEDIFF(HOUR, 0, $tTimeCol), 0) AS time_bin,
                        COALESCE(fp.fp_rule, 'I') AS rule,
                        COUNT(*) AS count
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    WHERE $fpAirportCol = ?
                      AND $tTimeCol IS NOT NULL
                      AND $tTimeCol >= ?
                      AND $tTimeCol < ?
                    GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, $tTimeCol), 0), COALESCE(fp.fp_rule, 'I')
                    ORDER BY time_bin, count DESC
                ";
                return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL]];
            }
        }
    }

    /**
     * Build query for departure fix breakdown by time bin (departures only)
     */
    public function buildDepFixBreakdownQuery($airport, $startSQL, $endSQL, $topN = 10) {
        if ($this->source === self::SOURCE_VIEW) {
            $sql = "
                WITH TopFixes AS (
                    SELECT TOP $topN dfix
                    FROM dbo.vw_adl_flights
                    WHERE fp_dept_icao = ?
                      AND COALESCE(etd_runway_utc, etd_utc) >= ?
                      AND COALESCE(etd_runway_utc, etd_utc) < ?
                      AND dfix IS NOT NULL AND dfix != ''
                    GROUP BY dfix
                    ORDER BY COUNT(*) DESC
                )
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(etd_runway_utc, etd_utc)), 0) AS time_bin,
                    CASE WHEN dfix IN (SELECT dfix FROM TopFixes) THEN dfix ELSE 'OTHER' END AS fix,
                    COUNT(*) AS count
                FROM dbo.vw_adl_flights
                WHERE fp_dept_icao = ?
                  AND COALESCE(etd_runway_utc, etd_utc) IS NOT NULL
                  AND COALESCE(etd_runway_utc, etd_utc) >= ?
                  AND COALESCE(etd_runway_utc, etd_utc) < ?
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(etd_runway_utc, etd_utc)), 0),
                         CASE WHEN dfix IN (SELECT dfix FROM TopFixes) THEN dfix ELSE 'OTHER' END
                ORDER BY time_bin, count DESC
            ";
            return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
        } else {
            $sql = "
                WITH TopFixes AS (
                    SELECT TOP $topN fp.dfix
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    WHERE fp.fp_dept_icao = ?
                      AND COALESCE(t.etd_runway_utc, t.etd_utc) >= ?
                      AND COALESCE(t.etd_runway_utc, t.etd_utc) < ?
                      AND fp.dfix IS NOT NULL AND fp.dfix != ''
                    GROUP BY fp.dfix
                    ORDER BY COUNT(*) DESC
                )
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.etd_runway_utc, t.etd_utc)), 0) AS time_bin,
                    CASE WHEN fp.dfix IN (SELECT dfix FROM TopFixes) THEN fp.dfix ELSE 'OTHER' END AS fix,
                    COUNT(*) AS count
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE fp.fp_dept_icao = ?
                  AND COALESCE(t.etd_runway_utc, t.etd_utc) IS NOT NULL
                  AND COALESCE(t.etd_runway_utc, t.etd_utc) >= ?
                  AND COALESCE(t.etd_runway_utc, t.etd_utc) < ?
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.etd_runway_utc, t.etd_utc)), 0),
                         CASE WHEN fp.dfix IN (SELECT dfix FROM TopFixes) THEN fp.dfix ELSE 'OTHER' END
                ORDER BY time_bin, count DESC
            ";
            return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
        }
    }

    /**
     * Build query for arrival fix breakdown by time bin (arrivals only)
     */
    public function buildArrFixBreakdownQuery($airport, $startSQL, $endSQL, $topN = 10) {
        if ($this->source === self::SOURCE_VIEW) {
            $sql = "
                WITH TopFixes AS (
                    SELECT TOP $topN afix
                    FROM dbo.vw_adl_flights
                    WHERE fp_dest_icao = ?
                      AND COALESCE(eta_runway_utc, eta_utc) >= ?
                      AND COALESCE(eta_runway_utc, eta_utc) < ?
                      AND afix IS NOT NULL AND afix != ''
                    GROUP BY afix
                    ORDER BY COUNT(*) DESC
                )
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(eta_runway_utc, eta_utc)), 0) AS time_bin,
                    CASE WHEN afix IN (SELECT afix FROM TopFixes) THEN afix ELSE 'OTHER' END AS fix,
                    COUNT(*) AS count
                FROM dbo.vw_adl_flights
                WHERE fp_dest_icao = ?
                  AND COALESCE(eta_runway_utc, eta_utc) IS NOT NULL
                  AND COALESCE(eta_runway_utc, eta_utc) >= ?
                  AND COALESCE(eta_runway_utc, eta_utc) < ?
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(eta_runway_utc, eta_utc)), 0),
                         CASE WHEN afix IN (SELECT afix FROM TopFixes) THEN afix ELSE 'OTHER' END
                ORDER BY time_bin, count DESC
            ";
            return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
        } else {
            $sql = "
                WITH TopFixes AS (
                    SELECT TOP $topN fp.afix
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    WHERE fp.fp_dest_icao = ?
                      AND COALESCE(t.eta_runway_utc, t.eta_utc) >= ?
                      AND COALESCE(t.eta_runway_utc, t.eta_utc) < ?
                      AND fp.afix IS NOT NULL AND fp.afix != ''
                    GROUP BY fp.afix
                    ORDER BY COUNT(*) DESC
                )
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.eta_runway_utc, t.eta_utc)), 0) AS time_bin,
                    CASE WHEN fp.afix IN (SELECT afix FROM TopFixes) THEN fp.afix ELSE 'OTHER' END AS fix,
                    COUNT(*) AS count
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE fp.fp_dest_icao = ?
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) IS NOT NULL
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) >= ?
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) < ?
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.eta_runway_utc, t.eta_utc)), 0),
                         CASE WHEN fp.afix IN (SELECT afix FROM TopFixes) THEN fp.afix ELSE 'OTHER' END
                ORDER BY time_bin, count DESC
            ";
            return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
        }
    }

    /**
     * Build query for DP/SID breakdown by time bin (departures only)
     */
    public function buildDPBreakdownQuery($airport, $startSQL, $endSQL, $topN = 10) {
        if ($this->source === self::SOURCE_VIEW) {
            $sql = "
                WITH TopDPs AS (
                    SELECT TOP $topN dp_name
                    FROM dbo.vw_adl_flights
                    WHERE fp_dept_icao = ?
                      AND COALESCE(etd_runway_utc, etd_utc) >= ?
                      AND COALESCE(etd_runway_utc, etd_utc) < ?
                      AND dp_name IS NOT NULL AND dp_name != ''
                    GROUP BY dp_name
                    ORDER BY COUNT(*) DESC
                )
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(etd_runway_utc, etd_utc)), 0) AS time_bin,
                    CASE WHEN dp_name IN (SELECT dp_name FROM TopDPs) THEN dp_name ELSE 'OTHER' END AS dp,
                    COUNT(*) AS count
                FROM dbo.vw_adl_flights
                WHERE fp_dept_icao = ?
                  AND COALESCE(etd_runway_utc, etd_utc) IS NOT NULL
                  AND COALESCE(etd_runway_utc, etd_utc) >= ?
                  AND COALESCE(etd_runway_utc, etd_utc) < ?
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(etd_runway_utc, etd_utc)), 0),
                         CASE WHEN dp_name IN (SELECT dp_name FROM TopDPs) THEN dp_name ELSE 'OTHER' END
                ORDER BY time_bin, count DESC
            ";
            return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
        } else {
            $sql = "
                WITH TopDPs AS (
                    SELECT TOP $topN fp.dp_name
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    WHERE fp.fp_dept_icao = ?
                      AND COALESCE(t.etd_runway_utc, t.etd_utc) >= ?
                      AND COALESCE(t.etd_runway_utc, t.etd_utc) < ?
                      AND fp.dp_name IS NOT NULL AND fp.dp_name != ''
                    GROUP BY fp.dp_name
                    ORDER BY COUNT(*) DESC
                )
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.etd_runway_utc, t.etd_utc)), 0) AS time_bin,
                    CASE WHEN fp.dp_name IN (SELECT dp_name FROM TopDPs) THEN fp.dp_name ELSE 'OTHER' END AS dp,
                    COUNT(*) AS count
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE fp.fp_dept_icao = ?
                  AND COALESCE(t.etd_runway_utc, t.etd_utc) IS NOT NULL
                  AND COALESCE(t.etd_runway_utc, t.etd_utc) >= ?
                  AND COALESCE(t.etd_runway_utc, t.etd_utc) < ?
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.etd_runway_utc, t.etd_utc)), 0),
                         CASE WHEN fp.dp_name IN (SELECT dp_name FROM TopDPs) THEN fp.dp_name ELSE 'OTHER' END
                ORDER BY time_bin, count DESC
            ";
            return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
        }
    }

    /**
     * Build query for STAR breakdown by time bin (arrivals only)
     */
    public function buildSTARBreakdownQuery($airport, $startSQL, $endSQL, $topN = 10) {
        if ($this->source === self::SOURCE_VIEW) {
            $sql = "
                WITH TopSTARs AS (
                    SELECT TOP $topN star_name
                    FROM dbo.vw_adl_flights
                    WHERE fp_dest_icao = ?
                      AND COALESCE(eta_runway_utc, eta_utc) >= ?
                      AND COALESCE(eta_runway_utc, eta_utc) < ?
                      AND star_name IS NOT NULL AND star_name != ''
                    GROUP BY star_name
                    ORDER BY COUNT(*) DESC
                )
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(eta_runway_utc, eta_utc)), 0) AS time_bin,
                    CASE WHEN star_name IN (SELECT star_name FROM TopSTARs) THEN star_name ELSE 'OTHER' END AS star,
                    COUNT(*) AS count
                FROM dbo.vw_adl_flights
                WHERE fp_dest_icao = ?
                  AND COALESCE(eta_runway_utc, eta_utc) IS NOT NULL
                  AND COALESCE(eta_runway_utc, eta_utc) >= ?
                  AND COALESCE(eta_runway_utc, eta_utc) < ?
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(eta_runway_utc, eta_utc)), 0),
                         CASE WHEN star_name IN (SELECT star_name FROM TopSTARs) THEN star_name ELSE 'OTHER' END
                ORDER BY time_bin, count DESC
            ";
            return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
        } else {
            $sql = "
                WITH TopSTARs AS (
                    SELECT TOP $topN fp.star_name
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    WHERE fp.fp_dest_icao = ?
                      AND COALESCE(t.eta_runway_utc, t.eta_utc) >= ?
                      AND COALESCE(t.eta_runway_utc, t.eta_utc) < ?
                      AND fp.star_name IS NOT NULL AND fp.star_name != ''
                    GROUP BY fp.star_name
                    ORDER BY COUNT(*) DESC
                )
                SELECT
                    DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.eta_runway_utc, t.eta_utc)), 0) AS time_bin,
                    CASE WHEN fp.star_name IN (SELECT star_name FROM TopSTARs) THEN fp.star_name ELSE 'OTHER' END AS star,
                    COUNT(*) AS count
                FROM dbo.adl_flight_core c
                INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                WHERE fp.fp_dest_icao = ?
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) IS NOT NULL
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) >= ?
                  AND COALESCE(t.eta_runway_utc, t.eta_utc) < ?
                GROUP BY DATEADD(HOUR, DATEDIFF(HOUR, 0, COALESCE(t.eta_runway_utc, t.eta_utc)), 0),
                         CASE WHEN fp.star_name IN (SELECT star_name FROM TopSTARs) THEN fp.star_name ELSE 'OTHER' END
                ORDER BY time_bin, count DESC
            ";
            return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL, $airport, $startSQL, $endSQL]];
        }
    }

    /**
     * Build query for flights in a specific time bin (drill-down)
     */
    public function buildFlightsForTimeBinQuery($airport, $binStartSQL, $binEndSQL, $direction) {
        if ($this->source === self::SOURCE_VIEW) {
            if ($direction === 'arr') {
                $sql = "
                    SELECT
                        callsign,
                        fp_dept_icao AS origin,
                        fp_dest_icao AS destination,
                        fp_dept_artcc AS origin_artcc,
                        fp_dest_artcc AS dest_artcc,
                        COALESCE(eta_runway_utc, eta_utc) AS eta,
                        phase,
                        is_active,
                        aircraft_type,
                        airline_icao AS carrier,
                        weight_class,
                        fp_rule AS flight_rules,
                        dfix,
                        afix,
                        dp_name,
                        star_name
                    FROM dbo.vw_adl_flights
                    WHERE fp_dest_icao = ?
                      AND COALESCE(eta_runway_utc, eta_utc) >= ?
                      AND COALESCE(eta_runway_utc, eta_utc) < ?
                    ORDER BY COALESCE(eta_runway_utc, eta_utc)
                ";
            } else {
                $sql = "
                    SELECT
                        callsign,
                        fp_dept_icao AS origin,
                        fp_dest_icao AS destination,
                        fp_dept_artcc AS origin_artcc,
                        fp_dest_artcc AS dest_artcc,
                        COALESCE(etd_runway_utc, etd_utc) AS etd,
                        phase,
                        is_active,
                        aircraft_type,
                        airline_icao AS carrier,
                        weight_class,
                        fp_rule AS flight_rules,
                        dfix,
                        afix,
                        dp_name,
                        star_name
                    FROM dbo.vw_adl_flights
                    WHERE fp_dept_icao = ?
                      AND COALESCE(etd_runway_utc, etd_utc) >= ?
                      AND COALESCE(etd_runway_utc, etd_utc) < ?
                    ORDER BY COALESCE(etd_runway_utc, etd_utc)
                ";
            }
        } else {
            // Normalized tables - derive carrier from callsign prefix
            // Note: weight_class not available in normalized tables (cross-db join not supported)
            if ($direction === 'arr') {
                $sql = "
                    SELECT
                        c.callsign,
                        fp.fp_dept_icao AS origin,
                        fp.fp_dest_icao AS destination,
                        fp.fp_dept_artcc AS origin_artcc,
                        fp.fp_dest_artcc AS dest_artcc,
                        COALESCE(t.eta_runway_utc, t.eta_utc) AS eta,
                        c.phase,
                        c.is_active,
                        fp.aircraft_type,
                        LEFT(c.callsign, 3) AS carrier,
                        NULL AS weight_class,
                        fp.fp_rule AS flight_rules,
                        fp.dfix,
                        fp.afix,
                        fp.dp_name,
                        fp.star_name
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    WHERE fp.fp_dest_icao = ?
                      AND COALESCE(t.eta_runway_utc, t.eta_utc) >= ?
                      AND COALESCE(t.eta_runway_utc, t.eta_utc) < ?
                    ORDER BY COALESCE(t.eta_runway_utc, t.eta_utc)
                ";
            } else {
                $sql = "
                    SELECT
                        c.callsign,
                        fp.fp_dept_icao AS origin,
                        fp.fp_dest_icao AS destination,
                        fp.fp_dept_artcc AS origin_artcc,
                        fp.fp_dest_artcc AS dest_artcc,
                        COALESCE(t.etd_runway_utc, t.etd_utc) AS etd,
                        c.phase,
                        c.is_active,
                        fp.aircraft_type,
                        LEFT(c.callsign, 3) AS carrier,
                        NULL AS weight_class,
                        fp.fp_rule AS flight_rules,
                        fp.dfix,
                        fp.afix,
                        fp.dp_name,
                        fp.star_name
                    FROM dbo.adl_flight_core c
                    INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
                    LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
                    WHERE fp.fp_dept_icao = ?
                      AND COALESCE(t.etd_runway_utc, t.etd_utc) >= ?
                      AND COALESCE(t.etd_runway_utc, t.etd_utc) < ?
                    ORDER BY COALESCE(t.etd_runway_utc, t.etd_utc)
                ";
            }
        }

        return ['sql' => $sql, 'params' => [$airport, $binStartSQL, $binEndSQL]];
    }

    // =========================================================================
    // UTILITY: Get last ADL update timestamp
    // =========================================================================

    /**
     * Build query to get last ADL snapshot timestamp
     */
    public function buildLastUpdateQuery() {
        if ($this->source === self::SOURCE_VIEW) {
            return ['sql' => "SELECT MAX(snapshot_utc) AS last_update FROM dbo.vw_adl_flights", 'params' => []];
        }
        return ['sql' => "SELECT MAX(snapshot_utc) AS last_update FROM dbo.adl_flight_core", 'params' => []];
    }

    // =========================================================================
    // TMI DEMAND AGGREGATION (for CTD time basis in GDT Power Run)
    // Tables: core + plan + times + tmi + ntml
    // =========================================================================

    /**
     * Build query for TMI-aware demand aggregation by time bins
     * Uses CTA (controlled time) and groups by TMI status instead of phase
     *
     * @param array $options:
     *   - 'airport' => string (ICAO code)
     *   - 'granularity' => '15min'|'30min'|'hourly'
     *   - 'startSQL' => string (formatted datetime)
     *   - 'endSQL' => string (formatted datetime)
     *   - 'program_id' => int (optional - filter to specific program)
     * @return array ['sql' => string, 'params' => array]
     */
    public function buildTmiDemandAggregationQuery($options = []) {
        $airport = $options['airport'] ?? '';
        $granularity = $options['granularity'] ?? 'hourly';
        $startSQL = $options['startSQL'] ?? '';
        $endSQL = $options['endSQL'] ?? '';
        $programId = isset($options['program_id']) ? (int)$options['program_id'] : null;

        // Time column: Use CTA if available (controlled), otherwise fall back to ETA
        $timeExpr = "COALESCE(tmi.cta_utc, t.eta_runway_utc, t.eta_utc)";

        // Time bin SQL based on granularity
        if ($granularity === '15min') {
            $timeBinSQL = "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', {$timeExpr}) / 15) * 15, '2000-01-01')";
        } elseif ($granularity === '30min') {
            $timeBinSQL = "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', {$timeExpr}) / 30) * 30, '2000-01-01')";
        } else {
            $timeBinSQL = "DATEADD(HOUR, DATEDIFF(HOUR, 0, {$timeExpr}), 0)";
        }

        // TMI status determination:
        // - proposed_gs: ctl_type='GS' AND program status='PROPOSED'
        // - simulated_gs: ctl_type='GS' AND program status='SIMULATED'
        // - actual_gs: ctl_type='GS' AND program status='ACTUAL'
        // - exempt: ctl_exempt=1
        // - uncontrolled: no TMI assignment or phase-based
        $sql = "
            SELECT
                {$timeBinSQL} AS time_bin,
                COUNT(*) AS total,
                SUM(CASE WHEN tmi.ctl_type = 'GS' AND p.status = 'PROPOSED' AND ISNULL(tmi.ctl_exempt, 0) = 0 THEN 1 ELSE 0 END) AS proposed_gs,
                SUM(CASE WHEN tmi.ctl_type = 'GS' AND p.status = 'SIMULATED' AND ISNULL(tmi.ctl_exempt, 0) = 0 THEN 1 ELSE 0 END) AS simulated_gs,
                SUM(CASE WHEN tmi.ctl_type = 'GS' AND p.status = 'ACTUAL' AND ISNULL(tmi.ctl_exempt, 0) = 0 THEN 1 ELSE 0 END) AS actual_gs,
                SUM(CASE WHEN tmi.ctl_type = 'GDP' AND p.status = 'PROPOSED' AND ISNULL(tmi.ctl_exempt, 0) = 0 THEN 1 ELSE 0 END) AS proposed_gdp,
                SUM(CASE WHEN tmi.ctl_type = 'GDP' AND p.status = 'SIMULATED' AND ISNULL(tmi.ctl_exempt, 0) = 0 THEN 1 ELSE 0 END) AS simulated_gdp,
                SUM(CASE WHEN tmi.ctl_type = 'GDP' AND p.status = 'ACTUAL' AND ISNULL(tmi.ctl_exempt, 0) = 0 THEN 1 ELSE 0 END) AS actual_gdp,
                SUM(CASE WHEN tmi.ctl_exempt = 1 THEN 1 ELSE 0 END) AS exempt,
                SUM(CASE WHEN tmi.program_id IS NULL OR tmi.ctl_type IS NULL THEN 1 ELSE 0 END) AS uncontrolled,
                -- Also include phase breakdown for flights without TMI
                SUM(CASE WHEN c.phase = 'arrived' THEN 1 ELSE 0 END) AS arrived,
                SUM(CASE WHEN c.phase = 'disconnected' THEN 1 ELSE 0 END) AS disconnected,
                SUM(CASE WHEN c.phase = 'descending' THEN 1 ELSE 0 END) AS descending,
                SUM(CASE WHEN c.phase = 'enroute' THEN 1 ELSE 0 END) AS enroute,
                SUM(CASE WHEN c.phase = 'departed' THEN 1 ELSE 0 END) AS departed,
                SUM(CASE WHEN c.phase = 'taxiing' THEN 1 ELSE 0 END) AS taxiing,
                SUM(CASE WHEN c.phase = 'prefile' THEN 1 ELSE 0 END) AS prefile
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
            LEFT JOIN dbo.ntml p ON p.program_id = tmi.program_id
            WHERE fp.fp_dest_icao = ?
              AND {$timeExpr} IS NOT NULL
              AND {$timeExpr} >= ?
              AND {$timeExpr} < ?
        ";

        $params = [$airport, $startSQL, $endSQL];

        // Optional program filter
        if ($programId !== null) {
            $sql .= " AND (tmi.program_id = ? OR tmi.program_id IS NULL)";
            $params[] = $programId;
        }

        $sql .= "
            GROUP BY {$timeBinSQL}
            ORDER BY time_bin
        ";

        return ['sql' => $sql, 'params' => $params];
    }
}
