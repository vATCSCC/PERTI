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

        $sql = "SELECT TOP {$limit} * FROM dbo.vw_adl_flights";
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
                c.flight_status,
                c.is_active,
                c.first_seen_utc,
                c.last_seen_utc,
                c.logon_time_utc,
                c.snapshot_utc,

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

                -- Pre-parsed route data (for client-side rendering without re-parsing)
                fp.route_geometry.STAsText() AS route_geometry_wkt,
                (SELECT w.fix_name, w.lat, w.lon, w.sequence_num
                 FROM dbo.adl_flight_waypoints w
                 WHERE w.flight_uid = c.flight_uid
                 ORDER BY w.sequence_num
                 FOR JSON PATH) AS waypoints_json,

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
                    dest_apt.ASPM77 AS arr_aspm77,
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
                SUM(CASE WHEN arr_domestic = 1 AND arr_aspm77 = 1 THEN 1 ELSE 0 END) AS arr_aspm77,
                SUM(CASE WHEN arr_domestic = 1 AND arr_oep35 = 1 THEN 1 ELSE 0 END) AS arr_oep35,
                SUM(CASE WHEN arr_domestic = 1 AND arr_core30 = 1 THEN 1 ELSE 0 END) AS arr_core30,
                SUM(CASE WHEN arr_domestic = 1 AND (arr_aspm77 = 0 OR arr_aspm77 IS NULL) THEN 1 ELSE 0 END) AS arr_non_aspm77,
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
                    dest_apt.ASPM77 AS arr_aspm77,
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
                SUM(CASE WHEN arr_domestic = 1 AND arr_aspm77 = 1 THEN 1 ELSE 0 END) AS arr_aspm77,
                SUM(CASE WHEN arr_domestic = 1 AND arr_oep35 = 1 THEN 1 ELSE 0 END) AS arr_oep35,
                SUM(CASE WHEN arr_domestic = 1 AND arr_core30 = 1 THEN 1 ELSE 0 END) AS arr_core30,
                SUM(CASE WHEN arr_domestic = 1 AND (arr_aspm77 = 0 OR arr_aspm77 IS NULL) THEN 1 ELSE 0 END) AS arr_non_aspm77,
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
                c.flight_status,
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

        // Time bin SQL based on granularity
        if ($granularity === '15min') {
            $timeBinSQL = "DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', {$timeExpr}) / 15) * 15, '2000-01-01')";
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

        $sql = "
            SELECT
                {$timeBinSQL} AS time_bin,
                COUNT(*) AS total,
                SUM(CASE WHEN flight_status = 'L' THEN 1 ELSE 0 END) AS arrived,
                SUM(CASE WHEN flight_status = 'A' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN flight_status = 'D' THEN 1 ELSE 0 END) AS departed,
                SUM(CASE WHEN (flight_status IS NULL OR flight_status = '' OR flight_status NOT IN ('A','D','L'))
                              AND is_active = 1 THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN (flight_status IS NULL OR flight_status = '' OR flight_status NOT IN ('A','D','L'))
                              AND (is_active = 0 OR is_active IS NULL) THEN 1 ELSE 0 END) AS proposed
            FROM dbo.vw_adl_flights
            WHERE {$airportCol} = ?
              AND {$timeCol} IS NOT NULL
              AND {$timeCol} >= ?
              AND {$timeCol} < ?
            GROUP BY {$timeBinSQL}
            ORDER BY time_bin
        ";

        return ['sql' => $sql, 'params' => [$airport, $startSQL, $endSQL]];
    }

    private function buildDemandAggregationNormalizedQuery($airport, $direction, $timeBinSQL, $startSQL, $endSQL, $timeExpr = null) {
        $airportCol = $direction === 'arr' ? 'fp.fp_dest_icao' : 'fp.fp_dept_icao';
        // Use provided time expression with COALESCE fallback, or default to runway time
        $timeCol = $timeExpr ?? ($direction === 'arr' ? 't.eta_runway_utc' : 't.etd_runway_utc');

        $sql = "
            SELECT
                {$timeBinSQL} AS time_bin,
                COUNT(*) AS total,
                SUM(CASE WHEN c.flight_status = 'L' THEN 1 ELSE 0 END) AS arrived,
                SUM(CASE WHEN c.flight_status = 'A' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN c.flight_status = 'D' THEN 1 ELSE 0 END) AS departed,
                SUM(CASE WHEN (c.flight_status IS NULL OR c.flight_status = '' OR c.flight_status NOT IN ('A','D','L'))
                              AND c.is_active = 1 THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN (c.flight_status IS NULL OR c.flight_status = '' OR c.flight_status NOT IN ('A','D','L'))
                              AND (c.is_active = 0 OR c.is_active IS NULL) THEN 1 ELSE 0 END) AS proposed
            FROM dbo.adl_flight_core c
            INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
            LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
            WHERE {$airportCol} = ?
              AND {$timeCol} IS NOT NULL
              AND {$timeCol} >= ?
              AND {$timeCol} < ?
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
                        COALESCE(eta_runway_utc, eta_utc) AS eta,
                        flight_status,
                        is_active,
                        aircraft_type
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
                        fp_dest_artcc AS dest_artcc,
                        COALESCE(etd_runway_utc, etd_utc) AS etd,
                        flight_status,
                        is_active,
                        aircraft_type
                    FROM dbo.vw_adl_flights
                    WHERE fp_dept_icao = ?
                      AND COALESCE(etd_runway_utc, etd_utc) >= ?
                      AND COALESCE(etd_runway_utc, etd_utc) < ?
                    ORDER BY COALESCE(etd_runway_utc, etd_utc)
                ";
            }
        } else {
            if ($direction === 'arr') {
                $sql = "
                    SELECT
                        c.callsign,
                        fp.fp_dept_icao AS origin,
                        fp.fp_dest_icao AS destination,
                        fp.fp_dept_artcc AS origin_artcc,
                        COALESCE(t.eta_runway_utc, t.eta_utc) AS eta,
                        c.flight_status,
                        c.is_active,
                        fp.aircraft_type
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
                        fp.fp_dest_artcc AS dest_artcc,
                        COALESCE(t.etd_runway_utc, t.etd_utc) AS etd,
                        c.flight_status,
                        c.is_active,
                        fp.aircraft_type
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
}
