<?php
/**
 * RAD Service — Route Amendment Dialogue Business Logic
 *
 * Shared service layer used by both internal (api/rad/) and SWIM (api/swim/v1/rad/)
 * endpoints. All database queries, amendment lifecycle, and compliance logic live here.
 *
 * @package PERTI
 * @subpackage RAD
 * @version 1.0.0
 */

class RADService
{
    private $conn_adl;
    private $conn_tmi;
    private $conn_gis;

    public function __construct($conn_adl, $conn_tmi, $conn_gis = null)
    {
        $this->conn_adl = $conn_adl;
        $this->conn_tmi = $conn_tmi;
        $this->conn_gis = $conn_gis;
    }

    // =========================================================================
    // FLIGHT SEARCH
    // =========================================================================

    /**
     * Search ADL flights with filters.
     *
     * @param array $filters Keys: cs, orig, dest, orig_tracon, orig_center,
     *   dest_tracon, dest_center, type, carrier, time_field, time_start,
     *   time_end, route, status, page, limit
     * @return array ['flights' => [...], 'total' => int]
     */
    public function searchFlights(array $filters): array
    {
        $where = ["1=1"];
        $params = [];

        // Callsign search (LIKE)
        if (!empty($filters['cs'])) {
            $where[] = "c.callsign LIKE ?";
            $params[] = '%' . str_replace(['%','_'], ['[%]','[_]'], $filters['cs']) . '%';
        }

        // Origin/destination airport
        if (!empty($filters['orig'])) {
            $where[] = "p.fp_dept_icao = ?";
            $params[] = strtoupper($filters['orig']);
        }
        if (!empty($filters['dest'])) {
            $where[] = "p.fp_dest_icao = ?";
            $params[] = strtoupper($filters['dest']);
        }

        // TRACON filters (origin/dest from adl_flight_plan)
        if (!empty($filters['orig_tracon'])) {
            $where[] = "p.fp_dept_tracon = ?";
            $params[] = strtoupper($filters['orig_tracon']);
        }
        if (!empty($filters['dest_tracon'])) {
            $where[] = "p.fp_dest_tracon = ?";
            $params[] = strtoupper($filters['dest_tracon']);
        }

        // Center filters (from adl_flight_plan)
        if (!empty($filters['orig_center'])) {
            $where[] = "p.fp_dept_artcc = ?";
            $params[] = strtoupper($filters['orig_center']);
        }
        if (!empty($filters['dest_center'])) {
            $where[] = "p.fp_dest_artcc = ?";
            $params[] = strtoupper($filters['dest_center']);
        }

        // Flight rules (IFR/VFR) — fp_rule is NCHAR(1): 'I' or 'V'
        if (!empty($filters['type'])) {
            $rule = strtoupper(substr($filters['type'], 0, 1));
            $where[] = "p.fp_rule = ?";
            $params[] = $rule;
        }

        // Carrier
        if (!empty($filters['carrier'])) {
            $where[] = "a.airline_icao = ?";
            $params[] = strtoupper($filters['carrier']);
        }

        // Time range filter
        $time_field = $filters['time_field'] ?? 'etd';
        $time_col_map = [
            'etd' => 't.etd_utc', 'ctd' => 't.ctd_utc', 'atd' => 't.atd_utc',
            'eta' => 't.eta_utc', 'cta' => 't.cta_utc', 'ata' => 't.ata_utc',
        ];
        $time_col = $time_col_map[$time_field] ?? 't.etd_utc';
        if (!empty($filters['time_start'])) {
            $where[] = "$time_col >= ?";
            $params[] = $filters['time_start'];
        }
        if (!empty($filters['time_end'])) {
            $where[] = "$time_col <= ?";
            $params[] = $filters['time_end'];
        }

        // Route string element search
        if (!empty($filters['route'])) {
            $where[] = "p.fp_route LIKE ?";
            $params[] = '%' . str_replace(['%','_'], ['[%]','[_]'], $filters['route']) . '%';
        }

        // Flight status — phase column uses lowercase: prefile, enroute, departed, arrived, etc.
        if (!empty($filters['status'])) {
            $st = strtolower($filters['status']);
            // Map legacy/JS values to actual DB phase values
            $phase_map = [
                'active' => 'enroute', 'airborne' => 'enroute',
                'prefiled' => 'prefile', 'prefile' => 'prefile',
                'departed' => 'departed', 'arrived' => 'arrived',
            ];
            $st = $phase_map[$st] ?? $st;
            $where[] = "c.phase = ?";
            $params[] = $st;
        }

        // Default: active + prefiled
        if (empty($filters['status'])) {
            $where[] = "c.is_active = 1";
        }

        $where_sql = implode(' AND ', $where);
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = min(200, max(1, (int)($filters['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        // Count query
        $count_sql = "SELECT COUNT(*) as total
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
            JOIN dbo.adl_flight_aircraft a ON c.flight_uid = a.flight_uid
            WHERE $where_sql";
        $count_stmt = sqlsrv_query($this->conn_adl, $count_sql, $params);
        $total = 0;
        if ($count_stmt) {
            $row = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
            $total = $row['total'] ?? 0;
            sqlsrv_free_stmt($count_stmt);
        }

        // Main query — aliases match JS field expectations
        $sql = "SELECT
                c.flight_uid, c.flight_key AS gufi, c.callsign,
                c.phase,
                p.fp_dept_artcc AS center, p.fp_dest_artcc AS dest_center,
                p.fp_dept_tracon AS tracon, p.fp_dest_tracon AS dest_tracon,
                p.fp_dept_icao AS origin, p.fp_dest_icao AS dest, p.fp_route AS route,
                a.aircraft_icao AS actype, a.airline_icao AS carrier,
                a.weight_class,
                t.etd_utc, t.eta_utc, t.ctd_utc, t.cta_utc,
                t.atd_utc, t.ata_utc, t.ete_minutes, t.cete_minutes
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
            JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
            JOIN dbo.adl_flight_aircraft a ON c.flight_uid = a.flight_uid
            WHERE $where_sql
            ORDER BY t.etd_utc ASC
            OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY";

        // Map DB phase values to JS-expected uppercase labels
        $phase_display = [
            'prefile' => 'PREFILED', 'taxiing' => 'DEPARTED',
            'departed' => 'DEPARTED', 'enroute' => 'AIRBORNE',
            'descending' => 'AIRBORNE', 'arrived' => 'ARRIVED',
            'disconnected' => 'ARRIVED',
        ];

        $stmt = sqlsrv_query($this->conn_adl, $sql, $params);
        $flights = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                foreach ($row as $k => $v) {
                    if ($v instanceof DateTimeInterface) {
                        $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
                    }
                }
                if (isset($row['phase'])) {
                    $row['phase'] = $phase_display[$row['phase']] ?? strtoupper($row['phase']);
                }
                $flights[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }

        return ['flights' => $flights, 'total' => $total];
    }

    // =========================================================================
    // AMENDMENT LIFECYCLE
    // =========================================================================

    /**
     * Create a route amendment.
     *
     * @param string $gufi Flight GUFI (UUID)
     * @param string $assigned_route New route string
     * @param array $options Keys: delivery_channels, tmi_reroute_id, tmi_id_label,
     *   route_color, notes, send (bool), created_by
     * @return array ['id' => int, 'status' => string] or ['error' => string]
     */
    public function createAmendment(string $gufi, string $assigned_route, array $options = []): array
    {
        if (!$this->radTableExists()) {
            return ['error' => 'RAD tables not yet deployed (migration 057 required)'];
        }

        // Look up flight by GUFI
        $flight = $this->getFlightByGufi($gufi);
        if (!$flight) {
            return ['error' => 'Flight not found for GUFI'];
        }

        $status = !empty($options['send']) ? 'SENT' : 'DRAFT';
        $channels = $options['delivery_channels'] ?? 'CPDLC,SWIM';

        $sql = "INSERT INTO dbo.rad_amendments
            (gufi, callsign, origin, destination, original_route, assigned_route,
             status, tmi_reroute_id, tmi_id_label, delivery_channels, route_color,
             created_by, notes, expires_utc)
            OUTPUT INSERTED.id
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    DATEADD(HOUR, 6, SYSUTCDATETIME()))";

        $params = [
            $gufi,
            $flight['callsign'],
            $flight['fp_dept_icao'],
            $flight['fp_dest_icao'],
            $flight['route'],
            $assigned_route,
            $status,
            $options['tmi_reroute_id'] ?? null,
            $options['tmi_id_label'] ?? null,
            $channels,
            $options['route_color'] ?? null,
            $options['created_by'] ?? null,
            $options['notes'] ?? null,
        ];

        $stmt = sqlsrv_query($this->conn_tmi, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            return ['error' => 'Insert failed: ' . ($errors[0]['message'] ?? 'Unknown')];
        }
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $id = $row['id'] ?? null;
        sqlsrv_free_stmt($stmt);

        if (!$id) {
            return ['error' => 'Failed to get inserted ID'];
        }

        // Log creation
        $this->logTransition($id, null, $status, 'Amendment created', $options['created_by'] ?? null);

        // If send=true, trigger delivery
        if ($status === 'SENT') {
            $this->triggerDelivery($id, $flight, $assigned_route, $channels, $options);
        }

        // Update adl_flight_tmi
        $this->updateAdlFlightTmi($flight['flight_uid'], $id, $assigned_route);

        return ['id' => $id, 'status' => $status];
    }

    /**
     * Send a DRAFT amendment.
     */
    public function sendAmendment(int $id, ?int $user_cid = null): array
    {
        $amendment = $this->getAmendment($id);
        if (!$amendment) return ['error' => 'Amendment not found'];
        if ($amendment['status'] !== 'DRAFT') return ['error' => 'Only DRAFT amendments can be sent'];

        $sql = "UPDATE dbo.rad_amendments SET status = 'SENT', sent_utc = SYSUTCDATETIME() WHERE id = ?";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$id]);
        if ($stmt === false) return ['error' => 'Update failed'];
        sqlsrv_free_stmt($stmt);

        $this->logTransition($id, 'DRAFT', 'SENT', 'Sent by operator', $user_cid);

        $flight = $this->getFlightByGufi($amendment['gufi']);
        if ($flight) {
            $this->triggerDelivery($id, $flight, $amendment['assigned_route'],
                $amendment['delivery_channels'], ['created_by' => $user_cid]);
        }

        return ['success' => true, 'status' => 'SENT'];
    }

    /**
     * Resend an already-sent amendment.
     */
    public function resendAmendment(int $id, ?int $user_cid = null): array
    {
        $amendment = $this->getAmendment($id);
        if (!$amendment) return ['error' => 'Amendment not found'];
        if (!in_array($amendment['status'], ['SENT', 'DLVD'])) {
            return ['error' => 'Only SENT/DLVD amendments can be resent'];
        }

        $this->logTransition($id, $amendment['status'], $amendment['status'], 'Resent by operator', $user_cid);

        $flight = $this->getFlightByGufi($amendment['gufi']);
        if ($flight) {
            $this->triggerDelivery($id, $flight, $amendment['assigned_route'],
                $amendment['delivery_channels'], ['created_by' => $user_cid]);
        }

        return ['success' => true];
    }

    /**
     * Cancel a DRAFT or SENT amendment (deletes it).
     */
    public function cancelAmendment(int $id, ?int $user_cid = null): array
    {
        $amendment = $this->getAmendment($id);
        if (!$amendment) return ['error' => 'Amendment not found'];
        if (!in_array($amendment['status'], ['DRAFT', 'SENT'])) {
            return ['error' => 'Only DRAFT/SENT amendments can be cancelled'];
        }

        // Clear adl_flight_tmi reference
        $this->clearAdlFlightTmi($amendment['gufi']);

        // Delete (CASCADE deletes log entries)
        $sql = "DELETE FROM dbo.rad_amendments WHERE id = ?";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$id]);
        if ($stmt === false) return ['error' => 'Delete failed'];
        sqlsrv_free_stmt($stmt);

        return ['success' => true];
    }

    /**
     * Get amendments with optional filters.
     */
    public function getAmendments(array $filters = []): array
    {
        if (!$this->radTableExists()) return [];

        $where = ["1=1"];
        $params = [];

        if (!empty($filters['gufi'])) {
            $where[] = "gufi = ?";
            $params[] = $filters['gufi'];
        }
        if (!empty($filters['status'])) {
            $statuses = array_map('trim', explode(',', strtoupper($filters['status'])));
            $ph = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "status IN ($ph)";
            $params = array_merge($params, $statuses);
        }
        if (!empty($filters['tmi_reroute_id'])) {
            $where[] = "tmi_reroute_id = ?";
            $params[] = (int)$filters['tmi_reroute_id'];
        }

        $where_sql = implode(' AND ', $where);
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = min(200, max(1, (int)($filters['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $sql = "SELECT * FROM dbo.rad_amendments WHERE $where_sql
                ORDER BY created_utc DESC OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY";

        $stmt = sqlsrv_query($this->conn_tmi, $sql, $params);
        $rows = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                foreach ($row as $k => $v) {
                    if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
                }
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        return $rows;
    }

    // =========================================================================
    // COMPLIANCE
    // =========================================================================

    /**
     * Get compliance status for amendments.
     *
     * @param array $filters Keys: amendment_ids (comma-sep), tmi_reroute_id
     * @return array Per-amendment compliance + aggregate
     */
    public function getCompliance(array $filters): array
    {
        $empty = [
            'amendments' => [],
            'aggregate' => ['C' => 0, 'NC' => 0, 'NC_OK' => 0, 'UNKN' => 0, 'OK' => 0, 'EXC' => 0],
            'compliance_rate' => 0,
            'total' => 0,
        ];

        // Gracefully handle missing rad_amendments table
        if (!$this->radTableExists()) return $empty;

        $where = ["status IN ('SENT','DLVD')"];
        $params = [];

        if (!empty($filters['amendment_ids'])) {
            $ids = array_map('intval', explode(',', $filters['amendment_ids']));
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "id IN ($ph)";
            $params = array_merge($params, $ids);
        }
        if (!empty($filters['tmi_reroute_id'])) {
            $where[] = "tmi_reroute_id = ?";
            $params[] = (int)$filters['tmi_reroute_id'];
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT id, gufi, callsign, origin, destination, status, rrstat,
                       assigned_route, tmi_reroute_id, tmi_id_label,
                       delivery_channels, sent_utc
                FROM dbo.rad_amendments WHERE $where_sql ORDER BY sent_utc DESC";

        $stmt = sqlsrv_query($this->conn_tmi, $sql, $params);
        $items = [];
        $agg = ['C' => 0, 'NC' => 0, 'NC_OK' => 0, 'UNKN' => 0, 'OK' => 0, 'EXC' => 0];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                foreach ($row as $k => $v) {
                    if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
                }

                // Look up current filed route from ADL
                $flight = $this->getFlightByGufi($row['gufi']);
                $row['filed_route'] = $flight ? ($flight['route'] ?? '') : '';

                // Map field names to match JS expectations
                $row['dest'] = $row['destination'] ?? '';
                $row['sent_at'] = $row['sent_utc'] ?? null;
                $row['tmi_id'] = $row['tmi_id_label'] ?? $row['tmi_reroute_id'] ?? null;
                $row['delivery_status'] = $row['delivery_channels'] ?? '--';

                $items[] = $row;
                $rs = $row['rrstat'] ?? 'UNKN';
                if (isset($agg[$rs])) $agg[$rs]++;
            }
            sqlsrv_free_stmt($stmt);
        }

        $total = array_sum($agg);
        $rate = $total > 0 ? round(($agg['C'] + $agg['OK'] + $agg['EXC']) / $total * 100, 1) : 0;

        return [
            'amendments' => $items,
            'aggregate' => $agg,
            'compliance_rate' => $rate,
            'total' => $total,
        ];
    }

    /**
     * Run compliance check for all active amendments (called by ADL daemon).
     * Compares filed route against assigned route, updates rrstat and status.
     */
    public function runComplianceCheck(): array
    {
        $sql = "SELECT id, gufi, assigned_route, status FROM dbo.rad_amendments
                WHERE status IN ('SENT', 'DLVD')";
        $stmt = sqlsrv_query($this->conn_tmi, $sql);
        if (!$stmt) return ['error' => 'Query failed'];

        $checked = 0;
        $transitioned = 0;

        while ($amend = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $flight = $this->getFlightByGufi($amend['gufi']);
            if (!$flight) continue;

            $checked++;
            $new_rrstat = $this->computeRrstat($flight['route'] ?? '', $amend['assigned_route']);

            // Check for status transitions
            $new_status = $amend['status'];
            if ($new_rrstat === 'C' && in_array($amend['status'], ['SENT', 'DLVD'])) {
                $new_status = 'ACPT';
                $transitioned++;
            } elseif (in_array($flight['phase'], ['enroute', 'departed', 'descending', 'taxiing']) && !empty($flight['atd_utc'])) {
                // Flight departed — check if route matches
                if ($new_rrstat !== 'C') {
                    $new_status = 'EXPR';
                    $transitioned++;
                }
            }

            // Update if changed
            if ($new_rrstat !== $amend['rrstat'] || $new_status !== $amend['status']) {
                $upd_sql = "UPDATE dbo.rad_amendments SET rrstat = ?, status = ?";
                $upd_params = [$new_rrstat, $new_status];
                if ($new_status !== $amend['status'] && in_array($new_status, ['ACPT', 'EXPR'])) {
                    $upd_sql .= ", resolved_utc = SYSUTCDATETIME()";
                }
                $upd_sql .= " WHERE id = ?";
                $upd_params[] = $amend['id'];
                $upd_stmt = sqlsrv_query($this->conn_tmi, $upd_sql, $upd_params);
                if ($upd_stmt) sqlsrv_free_stmt($upd_stmt);

                if ($new_status !== $amend['status']) {
                    $detail = $new_status === 'ACPT'
                        ? 'Pilot filed matching route'
                        : 'Flight departed without amendment';
                    $this->logTransition($amend['id'], $amend['status'], $new_status, $detail, null);

                    // Broadcast status change on SWIM WebSocket
                    $this->broadcastWebSocket('rad:amendment_update', [
                        'amendment_id' => $amend['id'],
                        'gufi' => $amend['gufi'],
                        'status' => $new_status,
                        'rrstat' => $new_rrstat,
                    ]);
                    $this->broadcastWebSocket('rad:compliance_update', [
                        'amendment_id' => $amend['id'],
                        'rrstat' => $new_rrstat,
                    ]);
                }
            }
        }
        sqlsrv_free_stmt($stmt);

        return ['checked' => $checked, 'transitioned' => $transitioned];
    }

    // =========================================================================
    // ROUTE OPTIONS & HISTORY
    // =========================================================================

    /**
     * Get route options for a flight (TMI reroutes, CDR matches).
     */
    public function getRouteOptions(string $gufi): array
    {
        $flight = $this->getFlightByGufi($gufi);
        if (!$flight) return ['error' => 'Flight not found'];

        $options = ['tmi_routes' => [], 'tos_options' => []];

        // TMI reroute routes matching this flight's city pair
        $sql = "SELECT r.reroute_id, r.reroute_name, r.advisory_number,
                       rr.route_string, rr.route_id
                FROM dbo.tmi_reroutes r
                JOIN dbo.tmi_reroute_routes rr ON r.reroute_id = rr.reroute_id
                WHERE r.status = 'ACTIVE'
                  AND (r.ctl_element = ? OR r.ctl_element = ?)";
        $stmt = sqlsrv_query($this->conn_tmi, $sql,
            [$flight['fp_dept_icao'], $flight['fp_dest_icao']]);
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $options['tmi_routes'][] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }

        return $options;
    }

    /**
     * Get recently sent routes for a city pair.
     */
    public function getRecentRoutes(string $origin, string $destination): array
    {
        if (!$this->radTableExists()) return [];

        $sql = "SELECT DISTINCT TOP 20 assigned_route AS route_string,
                       tmi_id_label, created_utc, 1 AS usage_count
                FROM dbo.rad_amendments
                WHERE origin = ? AND destination = ? AND status != 'DRAFT'
                ORDER BY created_utc DESC";
        $stmt = sqlsrv_query($this->conn_tmi, $sql,
            [strtoupper($origin), strtoupper($destination)]);
        $routes = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                foreach ($row as $k => $v) {
                    if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
                }
                $routes[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        return $routes;
    }

    /**
     * Get route change history for a flight from adl_flight_changelog.
     */
    public function getRouteHistory(string $gufi): array
    {
        // Get flight_uid from gufi
        $flight = $this->getFlightByGufi($gufi);
        if (!$flight) return [];

        $sql = "SELECT changed_utc, field_name, old_value, new_value
                FROM dbo.adl_flight_changelog
                WHERE flight_uid = ? AND field_name = 'route'
                ORDER BY changed_utc DESC";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [$flight['flight_uid']]);
        $history = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                foreach ($row as $k => $v) {
                    if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
                }
                // Map field names to match JS expectations
                $history[] = [
                    'timestamp' => $row['changed_utc'],
                    'route'     => $row['new_value'] ?? '',
                    'old_route' => $row['old_value'] ?? '',
                    'source'    => 'Filed route change',
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
        return $history;
    }

    /**
     * Look up a CDR (Coded Departure Route) by code.
     */
    public function getCDRRoute(string $code): array
    {
        $sql = "SELECT TOP 1 cdr_code, route_string, dept_icao, dest_icao, route_type
                FROM dbo.coded_departure_routes
                WHERE cdr_code = ?";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [strtoupper(trim($code))]);
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if ($row) return $row;
        }
        return ['error' => 'CDR code not found: ' . $code];
    }

    /**
     * Validate a route string via PostGIS expand_route().
     */
    public function validateRoute(string $routeString): array
    {
        if (!$this->conn_gis) {
            return ['valid' => true, 'warning' => 'PostGIS unavailable, skipping validation'];
        }

        try {
            $stmt = $this->conn_gis->prepare("SELECT * FROM expand_route(?)");
            $stmt->execute([$routeString]);
            $waypoints = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return ['valid' => count($waypoints) > 0, 'waypoints' => count($waypoints)];
        } catch (\Exception $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    /**
     * Check if rad_amendments table exists in VATSIM_TMI.
     * Caches result per-request.
     */
    private $_radTableExists = null;
    private function radTableExists(): bool
    {
        if ($this->_radTableExists !== null) return $this->_radTableExists;
        $sql = "SELECT OBJECT_ID('dbo.rad_amendments') AS oid";
        $stmt = sqlsrv_query($this->conn_tmi, $sql);
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $this->_radTableExists = !empty($row['oid']);
            sqlsrv_free_stmt($stmt);
        } else {
            $this->_radTableExists = false;
        }
        return $this->_radTableExists;
    }

    private function getAmendment(int $id): ?array
    {
        $stmt = sqlsrv_query($this->conn_tmi,
            "SELECT * FROM dbo.rad_amendments WHERE id = ?", [$id]);
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if (!$row) return null;
        foreach ($row as $k => $v) {
            if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
        }
        return $row;
    }

    private function getFlightByGufi(string $gufi): ?array
    {
        $sql = "SELECT c.flight_uid, c.flight_key AS gufi, c.callsign, c.phase,
                       p.fp_dept_artcc, p.fp_dest_artcc, p.fp_dept_tracon, p.fp_dest_tracon,
                       p.fp_dept_icao, p.fp_dest_icao, p.fp_route AS route,
                       t.etd_utc, t.eta_utc, t.ctd_utc, t.cta_utc, t.atd_utc, t.ata_utc
                FROM dbo.adl_flight_core c
                JOIN dbo.adl_flight_plan p ON c.flight_uid = p.flight_uid
                JOIN dbo.adl_flight_times t ON c.flight_uid = t.flight_uid
                WHERE c.flight_key = ?";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [$gufi]);
        if (!$stmt) return null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if (!$row) return null;
        foreach ($row as $k => $v) {
            if ($v instanceof DateTimeInterface) $row[$k] = $v->format('Y-m-d\TH:i:s') . 'Z';
        }
        return $row;
    }

    private function logTransition(int $amendment_id, ?string $from, string $to, string $detail, ?int $user_cid): void
    {
        $sql = "INSERT INTO dbo.rad_amendment_log (amendment_id, status_from, status_to, detail, changed_by)
                VALUES (?, ?, ?, ?, ?)";
        $stmt = sqlsrv_query($this->conn_tmi, $sql, [$amendment_id, $from, $to, $detail, $user_cid]);
        if ($stmt) sqlsrv_free_stmt($stmt);
    }

    /**
     * Compute RRSTAT by comparing filed route against assigned route.
     * Simple string match for V1 — future versions can use PostGIS geometry comparison.
     */
    private function computeRrstat(string $filedRoute, string $assignedRoute): string
    {
        if (empty($filedRoute) || empty($assignedRoute)) return 'UNKN';

        // Normalize: uppercase, collapse whitespace, trim
        $filed = strtoupper(preg_replace('/\s+/', ' ', trim($filedRoute)));
        $assigned = strtoupper(preg_replace('/\s+/', ' ', trim($assignedRoute)));

        if ($filed === $assigned) return 'C';

        // Check if assigned route is contained within filed route (partial match)
        if (strpos($filed, $assigned) !== false) return 'C';

        return 'NC';
    }

    private function triggerDelivery(int $amendment_id, array $flight, string $route, string $channels, array $options): void
    {
        // Build CPDLC message
        $tmi_label = $options['tmi_id_label'] ?? 'ATC';
        $message = "ROUTE AMENDMENT: {$flight['callsign']} CLEARED $route PER $tmi_label";

        // Use EDCTDelivery pattern for multi-channel delivery
        // Channel: CPDLC via Hoppie
        $cpdlc_ok = false;
        if (strpos($channels, 'CPDLC') !== false) {
            $cpdlc_ok = $this->deliverViaCPDLC($flight['callsign'], $message);
            if ($cpdlc_ok) {
                // Hoppie accepted — transition to DLVD, store message ID
                $upd = "UPDATE dbo.rad_amendments SET status = 'DLVD', delivered_utc = SYSUTCDATETIME()
                        WHERE id = ? AND status = 'SENT'";
                $upd_stmt = sqlsrv_query($this->conn_tmi, $upd, [$amendment_id]);
                if ($upd_stmt) sqlsrv_free_stmt($upd_stmt);
                $this->logTransition($amendment_id, 'SENT', 'DLVD', 'CPDLC delivery confirmed', null);
            }
        }

        // Channel: WebSocket broadcast
        if (strpos($channels, 'SWIM') !== false) {
            $this->broadcastWebSocket('rad:amendment_update', [
                'amendment_id' => $amendment_id,
                'gufi' => $flight['gufi'],
                'callsign' => $flight['callsign'],
                'status' => 'SENT',
                'assigned_route' => $route,
            ]);
        }
    }

    private function deliverViaCPDLC(string $callsign, string $message): bool
    {
        // Use Hoppie ACARS API (same pattern as EDCTDelivery)
        if (!defined('HOPPIE_LOGON_CODE') || !HOPPIE_LOGON_CODE) return false;

        $data = [
            'logon' => HOPPIE_LOGON_CODE,
            'from' => defined('HOPPIE_STATION') ? HOPPIE_STATION : 'VATCSCC',
            'to' => $callsign,
            'type' => 'cpdlc',
            'packet' => '/data2/' . strlen($message) . '//' . $message,
        ];

        $ch = curl_init('https://www.hoppie.nl/acars/system/connect.html');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $ok = $response !== false && strpos($response, 'ok') !== false;

        // If Hoppie accepted, extract message ID from response
        // Hoppie returns "ok {id}" on success
        if ($ok) {
            $parts = explode(' ', trim($response));
            $msg_id = isset($parts[1]) ? $parts[1] : null;
            // Note: caller should update rad_amendments.cpdlc_message_id
        }

        return $ok;
    }

    private function broadcastWebSocket(string $event, array $data): void
    {
        $payload = json_encode(['event' => $event, 'data' => $data]);
        $file = sys_get_temp_dir() . '/swim_ws_events.json';
        @file_put_contents($file, $payload . "\n", FILE_APPEND | LOCK_EX);
    }

    private function updateAdlFlightTmi(int $flight_uid, int $amendment_id, string $route): void
    {
        $sql = "UPDATE dbo.adl_flight_tmi SET rad_amendment_id = ?, rad_assigned_route = ?
                WHERE flight_uid = ?";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [$amendment_id, $route, $flight_uid]);
        if ($stmt) sqlsrv_free_stmt($stmt);
    }

    private function clearAdlFlightTmi(string $gufi): void
    {
        $flight = $this->getFlightByGufi($gufi);
        if (!$flight) return;
        $sql = "UPDATE dbo.adl_flight_tmi SET rad_amendment_id = NULL, rad_assigned_route = NULL
                WHERE flight_uid = ?";
        $stmt = sqlsrv_query($this->conn_adl, $sql, [$flight['flight_uid']]);
        if ($stmt) sqlsrv_free_stmt($stmt);
    }
}
