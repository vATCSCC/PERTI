<?php
/**
 * AdvisoryNumber - Centralized advisory number management
 *
 * Provides unified interface for all advisory number operations:
 * - peek()     - Get next number WITHOUT incrementing
 * - reserve()  - Get next number AND increment
 * - current()  - Get today's current/last used number (0 if none)
 * - previous() - Get the previous number (current - 1, or 0)
 * - format()   - Format a number as "ADVZY ###"
 *
 * Usage:
 *   $advNum = new AdvisoryNumber($conn);  // PDO connection
 *   $advNum = new AdvisoryNumber($conn_tmi, 'sqlsrv');  // sqlsrv connection
 *
 *   $next = $advNum->peek();           // "ADVZY 005" (doesn't increment)
 *   $reserved = $advNum->reserve();    // "ADVZY 005" (increments to 5)
 *   $current = $advNum->current();     // 5 (raw number)
 *   $formatted = $advNum->format(5);   // "ADVZY 005"
 */

class AdvisoryNumber {
    private $conn;
    private $driver;

    /**
     * Constructor
     * @param mixed $conn Database connection (PDO or sqlsrv resource)
     * @param string $driver 'pdo' or 'sqlsrv' (auto-detected if not specified)
     */
    public function __construct($conn, $driver = null) {
        $this->conn = $conn;

        if ($driver) {
            $this->driver = strtolower($driver);
        } else {
            // Auto-detect driver
            $this->driver = ($conn instanceof PDO) ? 'pdo' : 'sqlsrv';
        }
    }

    /**
     * Get the current (last used) sequence number for today
     * @return int Current sequence number (0 if none used today)
     */
    public function current(): int {
        $sql = "SELECT ISNULL(seq_number, 0) AS seq_number
                FROM dbo.tmi_advisory_sequences
                WHERE seq_date = CAST(SYSUTCDATETIME() AS DATE)";

        $row = $this->fetchOne($sql);
        return $row ? intval($row['seq_number']) : 0;
    }

    /**
     * Get the next advisory number WITHOUT incrementing
     * @return string Formatted advisory number (e.g., "ADVZY 001")
     */
    public function peek(): string {
        $current = $this->current();
        $next = $current + 1;
        return $this->format($next);
    }

    /**
     * Get and reserve the next advisory number (increments the counter)
     * @return string Formatted advisory number (e.g., "ADVZY 001")
     */
    public function reserve(): string {
        $sql = "DECLARE @num NVARCHAR(16);
                EXEC dbo.sp_GetNextAdvisoryNumber @next_number = @num OUTPUT;
                SELECT @num AS adv_num;";

        $row = $this->fetchOne($sql);

        if ($row && !empty($row['adv_num'])) {
            return $row['adv_num'];
        }

        // Fallback if SP fails
        return 'ADVZY 001';
    }

    /**
     * Get the previous advisory number (current - 1)
     * @return int Previous sequence number (0 if current is 0 or 1)
     */
    public function previous(): int {
        $current = $this->current();
        return max(0, $current - 1);
    }

    /**
     * Get previous, current, and next advisory numbers in one call
     * @param bool $reserveNext If true, reserves the next number (increments)
     * @return array ['previous' => int, 'current' => int, 'next' => string, 'next_raw' => int]
     */
    public function getAll(bool $reserveNext = false): array {
        $current = $this->current();
        $previous = max(0, $current - 1);
        $nextRaw = $current + 1;

        if ($reserveNext) {
            $next = $this->reserve();
            // Update current after reservation
            $current = $this->current();
            $nextRaw = $current;
        } else {
            $next = $this->format($nextRaw);
        }

        return [
            'previous' => $previous,
            'previous_formatted' => $previous > 0 ? $this->format($previous) : null,
            'current' => $reserveNext ? $current : $current,
            'current_formatted' => $current > 0 ? $this->format($current) : null,
            'next' => $next,
            'next_raw' => $nextRaw,
            'date' => gmdate('Y-m-d'),
            'reserved' => $reserveNext
        ];
    }

    /**
     * Format a sequence number as advisory string
     * @param int $number Raw sequence number
     * @return string Formatted as "ADVZY ###"
     */
    public function format(int $number): string {
        return 'ADVZY ' . str_pad($number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Extract raw number from formatted advisory string
     * @param string $formatted Advisory string like "ADVZY 005" or "005"
     * @return int|null Raw number or null if invalid
     */
    public function parse(string $formatted): ?int {
        if (preg_match('/(\d{1,3})/', $formatted, $matches)) {
            return intval($matches[1]);
        }
        return null;
    }

    /**
     * Execute a query and fetch one row
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return array|null Associative array or null
     */
    private function fetchOne(string $sql, array $params = []): ?array {
        if ($this->driver === 'pdo') {
            return $this->fetchOnePdo($sql, $params);
        } else {
            return $this->fetchOneSqlsrv($sql, $params);
        }
    }

    /**
     * Fetch one row using PDO
     */
    private function fetchOnePdo(string $sql, array $params = []): ?array {
        try {
            if (empty($params)) {
                $stmt = $this->conn->query($sql);
            } else {
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            error_log("AdvisoryNumber PDO error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch one row using sqlsrv
     */
    private function fetchOneSqlsrv(string $sql, array $params = []): ?array {
        try {
            $stmt = empty($params)
                ? sqlsrv_query($this->conn, $sql)
                : sqlsrv_query($this->conn, $sql, $params);

            if ($stmt === false) {
                error_log("AdvisoryNumber sqlsrv error: " . print_r(sqlsrv_errors(), true));
                return null;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            return $row ?: null;
        } catch (Exception $e) {
            error_log("AdvisoryNumber sqlsrv error: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Helper function for quick access without instantiation
 * Uses global $conn_tmi (sqlsrv) or $conn (PDO) if available
 *
 * @param string $method 'peek', 'reserve', 'current', 'previous', 'all'
 * @param mixed $conn Optional connection override
 * @return mixed Result depends on method
 */
function advisory_number(string $method = 'peek', $conn = null) {
    // Find a connection if not provided
    if (!$conn) {
        global $conn_tmi, $conn;
        $conn = $conn_tmi ?? $conn ?? null;
    }

    if (!$conn) {
        return $method === 'all' ? [] : ($method === 'current' || $method === 'previous' ? 0 : 'ADVZY 001');
    }

    $advNum = new AdvisoryNumber($conn);

    switch ($method) {
        case 'peek':
            return $advNum->peek();
        case 'reserve':
            return $advNum->reserve();
        case 'current':
            return $advNum->current();
        case 'previous':
            return $advNum->previous();
        case 'all':
            return $advNum->getAll(false);
        case 'all_reserve':
            return $advNum->getAll(true);
        default:
            return $advNum->peek();
    }
}
