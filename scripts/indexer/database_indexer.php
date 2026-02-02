<?php
/**
 * PERTI Database Schema Indexer
 *
 * Connects to all project databases and generates comprehensive schema documentation.
 * Extracts: tables, columns, types, indexes, foreign keys, views, stored procedures.
 *
 * Supported databases:
 * - MySQL (perti_site)
 * - Azure SQL Server (VATSIM_ADL, VATSIM_TMI, VATSIM_REF, SWIM_API, VATSIM_STATS)
 * - PostgreSQL/PostGIS (VATSIM_GIS)
 *
 * @package PERTI
 * @subpackage Indexer
 * @version 1.0.0
 * @date 2026-02-01
 */

class DatabaseIndexer {

    private array $connections = [];
    private array $index = [];
    private array $stats = [];
    private array $errors = [];
    private bool $configLoaded = false;

    // Database configurations
    // Constants must match those defined in load/config.php
    private array $databases = [
        'perti_site' => [
            'type' => 'mysql',
            'description' => 'Main web application database - users, plans, configurations',
            'constants' => ['SQL_HOST', 'SQL_DATABASE', 'SQL_USERNAME', 'SQL_PASSWORD']
        ],
        'VATSIM_ADL' => [
            'type' => 'sqlsrv',
            'description' => 'Aggregate Demand List - live flight data, tracks, crossings',
            'constants' => ['ADL_SQL_HOST', 'ADL_SQL_DATABASE', 'ADL_SQL_USERNAME', 'ADL_SQL_PASSWORD']
        ],
        'VATSIM_TMI' => [
            'type' => 'sqlsrv',
            'description' => 'Traffic Management Initiatives - GDPs, ground stops, reroutes, advisories',
            'constants' => ['TMI_SQL_HOST', 'TMI_SQL_DATABASE', 'TMI_SQL_USERNAME', 'TMI_SQL_PASSWORD']
        ],
        'VATSIM_REF' => [
            'type' => 'sqlsrv',
            'description' => 'Reference data - airports, airways, fixes, procedures',
            'constants' => ['REF_SQL_HOST', 'REF_SQL_DATABASE', 'REF_SQL_USERNAME', 'REF_SQL_PASSWORD']
        ],
        'SWIM_API' => [
            'type' => 'sqlsrv',
            'description' => 'SWIM integration - external API data sync',
            'constants' => ['SWIM_SQL_HOST', 'SWIM_SQL_DATABASE', 'SWIM_SQL_USERNAME', 'SWIM_SQL_PASSWORD']
        ],
        'VATSIM_GIS' => [
            'type' => 'pgsql',
            'description' => 'Geographic Information System - PostGIS spatial queries, boundaries',
            'constants' => ['GIS_SQL_HOST', 'GIS_SQL_DATABASE', 'GIS_SQL_USERNAME', 'GIS_SQL_PASSWORD']
        ],
        'VATSIM_STATS' => [
            'type' => 'sqlsrv',
            'description' => 'Statistics database - historical metrics, pattern analysis, analog detection',
            'constants' => ['STATS_SQL_HOST', 'STATS_SQL_DATABASE', 'STATS_SQL_USERNAME', 'STATS_SQL_PASSWORD']
        ]
    ];

    public function __construct(string $configPath = null) {
        $this->initStats();

        // Load config if path provided
        if ($configPath && file_exists($configPath)) {
            require_once $configPath;
            $this->configLoaded = true;
        }
    }

    private function initStats(): void {
        $this->stats = [
            'started_at' => date('Y-m-d H:i:s T'),
            'databases_indexed' => 0,
            'databases_failed' => 0,
            'total_tables' => 0,
            'total_views' => 0,
            'total_procedures' => 0,
            'total_columns' => 0,
            'total_indexes' => 0,
        ];
    }

    /**
     * Run the full database indexing process
     */
    public function run(): array {
        echo "[Database Indexer] Starting database schema scan...\n";

        foreach ($this->databases as $dbName => $dbConfig) {
            $this->indexDatabase($dbName, $dbConfig);
        }

        $this->stats['completed_at'] = date('Y-m-d H:i:s T');
        $this->stats['errors'] = count($this->errors);

        echo "[Database Indexer] Complete. Indexed {$this->stats['databases_indexed']} databases.\n";

        return [
            'stats' => $this->stats,
            'index' => $this->index,
            'errors' => $this->errors
        ];
    }

    /**
     * Index a single database
     */
    private function indexDatabase(string $dbName, array $config): void {
        echo "  Indexing {$dbName} ({$config['type']})...\n";

        // Check if constants are defined
        foreach ($config['constants'] as $const) {
            if (!defined($const)) {
                $this->errors[] = "Database {$dbName}: Missing constant {$const}";
                echo "    SKIP: Missing configuration constants\n";
                $this->stats['databases_failed']++;
                return;
            }
        }

        // Connect to database
        $conn = $this->connect($dbName, $config);
        if (!$conn) {
            $this->stats['databases_failed']++;
            return;
        }

        $this->connections[$dbName] = $conn;

        try {
            $dbIndex = [
                'name' => $dbName,
                'type' => $config['type'],
                'description' => $config['description'],
                'indexed_at' => date('Y-m-d H:i:s T'),
                'tables' => [],
                'views' => [],
                'procedures' => [],
                'functions' => []
            ];

            // Index based on database type
            switch ($config['type']) {
                case 'mysql':
                    $dbIndex = $this->indexMySqlDatabase($conn, $dbIndex);
                    break;
                case 'sqlsrv':
                    $dbIndex = $this->indexSqlServerDatabase($conn, $dbIndex);
                    break;
                case 'pgsql':
                    $dbIndex = $this->indexPostgresDatabase($conn, $dbIndex);
                    break;
            }

            $this->index[$dbName] = $dbIndex;
            $this->stats['databases_indexed']++;

            $tableCount = count($dbIndex['tables']);
            $viewCount = count($dbIndex['views']);
            $procCount = count($dbIndex['procedures']);
            echo "    Found {$tableCount} tables, {$viewCount} views, {$procCount} procedures\n";

        } catch (Exception $e) {
            $this->errors[] = "Database {$dbName}: " . $e->getMessage();
            echo "    ERROR: " . $e->getMessage() . "\n";
            $this->stats['databases_failed']++;
        }
    }

    /**
     * Connect to a database
     */
    private function connect(string $dbName, array $config) {
        try {
            switch ($config['type']) {
                case 'mysql':
                    $host = constant($config['constants'][0]);
                    $database = constant($config['constants'][1]);
                    $username = constant($config['constants'][2]);
                    $password = constant($config['constants'][3]);

                    $conn = new PDO(
                        "mysql:host={$host};dbname={$database};charset=utf8mb4",
                        $username,
                        $password,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    return $conn;

                case 'sqlsrv':
                    if (!function_exists('sqlsrv_connect')) {
                        $this->errors[] = "Database {$dbName}: sqlsrv extension not loaded";
                        echo "    SKIP: sqlsrv extension not available\n";
                        return null;
                    }

                    $host = constant($config['constants'][0]);
                    $database = constant($config['constants'][1]);
                    $username = constant($config['constants'][2]);
                    $password = constant($config['constants'][3]);

                    $connectionInfo = [
                        "Database" => $database,
                        "UID" => $username,
                        "PWD" => $password,
                        "ConnectionPooling" => 0
                    ];

                    $conn = sqlsrv_connect($host, $connectionInfo);
                    if ($conn === false) {
                        $errors = sqlsrv_errors();
                        $msg = $errors ? $errors[0]['message'] : 'Unknown error';
                        $this->errors[] = "Database {$dbName}: Connection failed - {$msg}";
                        echo "    SKIP: Connection failed\n";
                        return null;
                    }
                    return $conn;

                case 'pgsql':
                    if (!extension_loaded('pdo_pgsql')) {
                        $this->errors[] = "Database {$dbName}: pdo_pgsql extension not loaded";
                        echo "    SKIP: pdo_pgsql extension not available\n";
                        return null;
                    }

                    $host = constant($config['constants'][0]);
                    $database = constant($config['constants'][1]);
                    $username = constant($config['constants'][2]);
                    $password = constant($config['constants'][3]);
                    $port = defined('GIS_SQL_PORT') ? GIS_SQL_PORT : '5432';

                    $conn = new PDO(
                        "pgsql:host={$host};port={$port};dbname={$database}",
                        $username,
                        $password,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    return $conn;
            }
        } catch (Exception $e) {
            $this->errors[] = "Database {$dbName}: " . $e->getMessage();
            echo "    SKIP: " . $e->getMessage() . "\n";
            return null;
        }

        return null;
    }

    // =========================================================================
    // MySQL Indexing
    // =========================================================================

    private function indexMySqlDatabase(PDO $conn, array $dbIndex): array {
        // Get all tables
        $stmt = $conn->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $tableName) {
            $table = $this->indexMySqlTable($conn, $tableName);
            $dbIndex['tables'][] = $table;
            $this->stats['total_tables']++;
            $this->stats['total_columns'] += count($table['columns']);
            $this->stats['total_indexes'] += count($table['indexes']);
        }

        // Get views
        $stmt = $conn->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
        $views = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($views as $viewName) {
            $dbIndex['views'][] = $this->indexMySqlView($conn, $viewName);
            $this->stats['total_views']++;
        }

        // Get procedures
        $dbName = $conn->query("SELECT DATABASE()")->fetchColumn();
        $stmt = $conn->prepare("SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?");
        $stmt->execute([$dbName]);
        $routines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($routines as $routine) {
            if ($routine['ROUTINE_TYPE'] === 'PROCEDURE') {
                $dbIndex['procedures'][] = ['name' => $routine['ROUTINE_NAME']];
                $this->stats['total_procedures']++;
            } else {
                $dbIndex['functions'][] = ['name' => $routine['ROUTINE_NAME']];
            }
        }

        return $dbIndex;
    }

    private function indexMySqlTable(PDO $conn, string $tableName): array {
        $table = [
            'name' => $tableName,
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
            'row_count' => 0
        ];

        // Get columns
        $stmt = $conn->query("DESCRIBE `{$tableName}`");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columns as $col) {
            $table['columns'][] = [
                'name' => $col['Field'],
                'type' => $col['Type'],
                'nullable' => $col['Null'] === 'YES',
                'key' => $col['Key'],
                'default' => $col['Default'],
                'extra' => $col['Extra']
            ];
        }

        // Get indexes
        $stmt = $conn->query("SHOW INDEX FROM `{$tableName}`");
        $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexMap = [];
        foreach ($indexes as $idx) {
            $name = $idx['Key_name'];
            if (!isset($indexMap[$name])) {
                $indexMap[$name] = [
                    'name' => $name,
                    'unique' => $idx['Non_unique'] == 0,
                    'columns' => []
                ];
            }
            $indexMap[$name]['columns'][] = $idx['Column_name'];
        }
        $table['indexes'] = array_values($indexMap);

        // Get foreign keys
        $dbName = $conn->query("SELECT DATABASE()")->fetchColumn();
        $stmt = $conn->prepare("
            SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute([$dbName, $tableName]);
        $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fks as $fk) {
            $table['foreign_keys'][] = [
                'name' => $fk['CONSTRAINT_NAME'],
                'column' => $fk['COLUMN_NAME'],
                'references_table' => $fk['REFERENCED_TABLE_NAME'],
                'references_column' => $fk['REFERENCED_COLUMN_NAME']
            ];
        }

        // Get approximate row count
        try {
            $stmt = $conn->query("SELECT COUNT(*) FROM `{$tableName}`");
            $table['row_count'] = (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            $table['row_count'] = -1;
        }

        return $table;
    }

    private function indexMySqlView(PDO $conn, string $viewName): array {
        return [
            'name' => $viewName,
            'type' => 'view'
        ];
    }

    // =========================================================================
    // SQL Server Indexing
    // =========================================================================

    private function indexSqlServerDatabase($conn, array $dbIndex): array {
        // Get all tables
        $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = 'dbo' ORDER BY TABLE_NAME";
        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt === false) {
            throw new Exception("Failed to query tables: " . $this->getSqlSrvError());
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $table = $this->indexSqlServerTable($conn, $row['TABLE_NAME']);
            $dbIndex['tables'][] = $table;
            $this->stats['total_tables']++;
            $this->stats['total_columns'] += count($table['columns']);
            $this->stats['total_indexes'] += count($table['indexes']);
        }
        sqlsrv_free_stmt($stmt);

        // Get views
        $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = 'dbo' ORDER BY TABLE_NAME";
        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $dbIndex['views'][] = ['name' => $row['TABLE_NAME']];
                $this->stats['total_views']++;
            }
            sqlsrv_free_stmt($stmt);
        }

        // Get stored procedures
        $sql = "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA = 'dbo' ORDER BY ROUTINE_NAME";
        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $proc = $this->indexSqlServerProcedure($conn, $row['ROUTINE_NAME']);
                if ($row['ROUTINE_TYPE'] === 'PROCEDURE') {
                    $dbIndex['procedures'][] = $proc;
                    $this->stats['total_procedures']++;
                } else {
                    $dbIndex['functions'][] = $proc;
                }
            }
            sqlsrv_free_stmt($stmt);
        }

        return $dbIndex;
    }

    private function indexSqlServerTable($conn, string $tableName): array {
        $table = [
            'name' => $tableName,
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
            'row_count' => 0
        ];

        // Get columns
        $sql = "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = ? AND TABLE_SCHEMA = 'dbo'
                ORDER BY ORDINAL_POSITION";
        $stmt = sqlsrv_query($conn, $sql, [$tableName]);

        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $type = $row['DATA_TYPE'];
                if ($row['CHARACTER_MAXIMUM_LENGTH']) {
                    $len = $row['CHARACTER_MAXIMUM_LENGTH'] == -1 ? 'max' : $row['CHARACTER_MAXIMUM_LENGTH'];
                    $type .= "({$len})";
                } elseif ($row['NUMERIC_PRECISION']) {
                    $type .= "({$row['NUMERIC_PRECISION']})";
                }

                $table['columns'][] = [
                    'name' => $row['COLUMN_NAME'],
                    'type' => $type,
                    'nullable' => $row['IS_NULLABLE'] === 'YES',
                    'default' => $row['COLUMN_DEFAULT']
                ];
            }
            sqlsrv_free_stmt($stmt);
        }

        // Get indexes
        $sql = "SELECT i.name AS index_name, i.is_unique, c.name AS column_name
                FROM sys.indexes i
                INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
                WHERE i.object_id = OBJECT_ID(?)
                ORDER BY i.name, ic.key_ordinal";
        $stmt = sqlsrv_query($conn, $sql, ["dbo.{$tableName}"]);

        if ($stmt) {
            $indexMap = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $name = $row['index_name'];
                if (!isset($indexMap[$name])) {
                    $indexMap[$name] = [
                        'name' => $name,
                        'unique' => (bool)$row['is_unique'],
                        'columns' => []
                    ];
                }
                $indexMap[$name]['columns'][] = $row['column_name'];
            }
            $table['indexes'] = array_values($indexMap);
            sqlsrv_free_stmt($stmt);
        }

        // Get foreign keys
        $sql = "SELECT fk.name AS fk_name, cp.name AS column_name, tp.name AS ref_table, cr.name AS ref_column
                FROM sys.foreign_keys fk
                INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
                INNER JOIN sys.columns cp ON fkc.parent_object_id = cp.object_id AND fkc.parent_column_id = cp.column_id
                INNER JOIN sys.tables tp ON fkc.referenced_object_id = tp.object_id
                INNER JOIN sys.columns cr ON fkc.referenced_object_id = cr.object_id AND fkc.referenced_column_id = cr.column_id
                WHERE fk.parent_object_id = OBJECT_ID(?)";
        $stmt = sqlsrv_query($conn, $sql, ["dbo.{$tableName}"]);

        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $table['foreign_keys'][] = [
                    'name' => $row['fk_name'],
                    'column' => $row['column_name'],
                    'references_table' => $row['ref_table'],
                    'references_column' => $row['ref_column']
                ];
            }
            sqlsrv_free_stmt($stmt);
        }

        // Get row count (approximate for large tables)
        $sql = "SELECT SUM(p.rows) AS row_count
                FROM sys.partitions p
                WHERE p.object_id = OBJECT_ID(?) AND p.index_id IN (0, 1)";
        $stmt = sqlsrv_query($conn, $sql, ["dbo.{$tableName}"]);

        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $table['row_count'] = (int)($row['row_count'] ?? 0);
            sqlsrv_free_stmt($stmt);
        }

        return $table;
    }

    private function indexSqlServerProcedure($conn, string $procName): array {
        $proc = [
            'name' => $procName,
            'parameters' => []
        ];

        // Get parameters
        $sql = "SELECT PARAMETER_NAME, DATA_TYPE, PARAMETER_MODE, CHARACTER_MAXIMUM_LENGTH
                FROM INFORMATION_SCHEMA.PARAMETERS
                WHERE SPECIFIC_NAME = ? AND SPECIFIC_SCHEMA = 'dbo'
                ORDER BY ORDINAL_POSITION";
        $stmt = sqlsrv_query($conn, $sql, [$procName]);

        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                if ($row['PARAMETER_NAME']) {
                    $type = $row['DATA_TYPE'];
                    if ($row['CHARACTER_MAXIMUM_LENGTH']) {
                        $len = $row['CHARACTER_MAXIMUM_LENGTH'] == -1 ? 'max' : $row['CHARACTER_MAXIMUM_LENGTH'];
                        $type .= "({$len})";
                    }

                    $proc['parameters'][] = [
                        'name' => $row['PARAMETER_NAME'],
                        'type' => $type,
                        'mode' => $row['PARAMETER_MODE']
                    ];
                }
            }
            sqlsrv_free_stmt($stmt);
        }

        return $proc;
    }

    // =========================================================================
    // PostgreSQL Indexing
    // =========================================================================

    private function indexPostgresDatabase(PDO $conn, array $dbIndex): array {
        // Get all tables (excluding PostGIS system tables)
        $stmt = $conn->query("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_type = 'BASE TABLE'
              AND table_name NOT LIKE 'spatial_ref_sys'
            ORDER BY table_name
        ");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $tableName) {
            $table = $this->indexPostgresTable($conn, $tableName);
            $dbIndex['tables'][] = $table;
            $this->stats['total_tables']++;
            $this->stats['total_columns'] += count($table['columns']);
            $this->stats['total_indexes'] += count($table['indexes']);
        }

        // Get views
        $stmt = $conn->query("
            SELECT table_name
            FROM information_schema.views
            WHERE table_schema = 'public'
            ORDER BY table_name
        ");
        $views = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($views as $viewName) {
            $dbIndex['views'][] = ['name' => $viewName];
            $this->stats['total_views']++;
        }

        // Get functions
        $stmt = $conn->query("
            SELECT routine_name, routine_type
            FROM information_schema.routines
            WHERE routine_schema = 'public'
            ORDER BY routine_name
        ");
        $routines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($routines as $routine) {
            if ($routine['routine_type'] === 'PROCEDURE') {
                $dbIndex['procedures'][] = ['name' => $routine['routine_name']];
                $this->stats['total_procedures']++;
            } else {
                $dbIndex['functions'][] = ['name' => $routine['routine_name']];
            }
        }

        // Check for PostGIS
        $stmt = $conn->query("SELECT PostGIS_Version()");
        if ($postgisVersion = $stmt->fetchColumn()) {
            $dbIndex['postgis_version'] = $postgisVersion;
        }

        return $dbIndex;
    }

    private function indexPostgresTable(PDO $conn, string $tableName): array {
        $table = [
            'name' => $tableName,
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
            'row_count' => 0,
            'has_geometry' => false
        ];

        // Get columns
        $stmt = $conn->prepare("
            SELECT column_name, data_type, is_nullable, column_default, udt_name,
                   character_maximum_length, numeric_precision
            FROM information_schema.columns
            WHERE table_name = ? AND table_schema = 'public'
            ORDER BY ordinal_position
        ");
        $stmt->execute([$tableName]);
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columns as $col) {
            $type = $col['data_type'];
            if ($col['udt_name'] === 'geometry') {
                $type = 'geometry';
                $table['has_geometry'] = true;
            } elseif ($col['character_maximum_length']) {
                $type .= "({$col['character_maximum_length']})";
            } elseif ($col['numeric_precision']) {
                $type .= "({$col['numeric_precision']})";
            }

            $table['columns'][] = [
                'name' => $col['column_name'],
                'type' => $type,
                'nullable' => $col['is_nullable'] === 'YES',
                'default' => $col['column_default']
            ];
        }

        // Get indexes
        $stmt = $conn->prepare("
            SELECT i.relname AS index_name, ix.indisunique, a.attname AS column_name
            FROM pg_class t
            JOIN pg_index ix ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
            WHERE t.relname = ? AND t.relkind = 'r'
            ORDER BY i.relname, a.attnum
        ");
        $stmt->execute([$tableName]);
        $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexMap = [];
        foreach ($indexes as $idx) {
            $name = $idx['index_name'];
            if (!isset($indexMap[$name])) {
                $indexMap[$name] = [
                    'name' => $name,
                    'unique' => (bool)$idx['indisunique'],
                    'columns' => []
                ];
            }
            $indexMap[$name]['columns'][] = $idx['column_name'];
        }
        $table['indexes'] = array_values($indexMap);

        // Get foreign keys
        $stmt = $conn->prepare("
            SELECT tc.constraint_name, kcu.column_name, ccu.table_name AS ref_table, ccu.column_name AS ref_column
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ?
        ");
        $stmt->execute([$tableName]);
        $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fks as $fk) {
            $table['foreign_keys'][] = [
                'name' => $fk['constraint_name'],
                'column' => $fk['column_name'],
                'references_table' => $fk['ref_table'],
                'references_column' => $fk['ref_column']
            ];
        }

        // Get row count
        try {
            $stmt = $conn->query("SELECT COUNT(*) FROM \"{$tableName}\"");
            $table['row_count'] = (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            $table['row_count'] = -1;
        }

        return $table;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function getSqlSrvError(): string {
        $errors = sqlsrv_errors();
        if (!$errors) return 'Unknown error';
        return $errors[0]['message'] ?? 'Unknown error';
    }

    /**
     * Generate markdown documentation from the index
     */
    public function generateMarkdown(): string {
        $md = "# PERTI Database Schema Index\n\n";
        $md .= "**Generated:** {$this->stats['completed_at']}\n\n";

        $md .= "## Statistics\n\n";
        $md .= "| Metric | Count |\n";
        $md .= "|--------|-------|\n";
        $md .= "| Databases Indexed | {$this->stats['databases_indexed']} |\n";
        $md .= "| Total Tables | {$this->stats['total_tables']} |\n";
        $md .= "| Total Views | {$this->stats['total_views']} |\n";
        $md .= "| Total Procedures | {$this->stats['total_procedures']} |\n";
        $md .= "| Total Columns | {$this->stats['total_columns']} |\n";
        $md .= "| Total Indexes | {$this->stats['total_indexes']} |\n";
        $md .= "\n---\n\n";

        foreach ($this->index as $dbName => $db) {
            $md .= "## {$dbName}\n\n";
            $md .= "**Type:** {$db['type']} | **Description:** {$db['description']}\n\n";

            if (isset($db['postgis_version'])) {
                $md .= "**PostGIS Version:** {$db['postgis_version']}\n\n";
            }

            // Tables
            if (!empty($db['tables'])) {
                $md .= "### Tables\n\n";

                foreach ($db['tables'] as $table) {
                    $rowCount = $table['row_count'] >= 0 ? number_format($table['row_count']) : 'N/A';
                    $geomFlag = !empty($table['has_geometry']) ? ' 🌍' : '';
                    $md .= "#### {$table['name']}{$geomFlag}\n\n";
                    $md .= "**Rows:** {$rowCount}\n\n";

                    // Columns table
                    $md .= "| Column | Type | Nullable | Key/Default |\n";
                    $md .= "|--------|------|----------|-------------|\n";
                    foreach ($table['columns'] as $col) {
                        $nullable = $col['nullable'] ? 'YES' : 'NO';
                        $extra = [];
                        if (!empty($col['key'])) $extra[] = $col['key'];
                        if (!empty($col['extra'])) $extra[] = $col['extra'];
                        if ($col['default'] !== null) $extra[] = "default: " . substr($col['default'], 0, 20);
                        $extraStr = implode(', ', $extra);
                        $md .= "| `{$col['name']}` | {$col['type']} | {$nullable} | {$extraStr} |\n";
                    }
                    $md .= "\n";

                    // Indexes
                    if (!empty($table['indexes'])) {
                        $md .= "**Indexes:** ";
                        $idxList = [];
                        foreach ($table['indexes'] as $idx) {
                            $unique = $idx['unique'] ? 'UNIQUE ' : '';
                            $cols = implode(', ', $idx['columns']);
                            $idxList[] = "`{$idx['name']}` ({$unique}{$cols})";
                        }
                        $md .= implode(', ', $idxList) . "\n\n";
                    }

                    // Foreign keys
                    if (!empty($table['foreign_keys'])) {
                        $md .= "**Foreign Keys:** ";
                        $fkList = [];
                        foreach ($table['foreign_keys'] as $fk) {
                            $fkList[] = "`{$fk['column']}` → `{$fk['references_table']}.{$fk['references_column']}`";
                        }
                        $md .= implode(', ', $fkList) . "\n\n";
                    }
                }
            }

            // Views
            if (!empty($db['views'])) {
                $md .= "### Views\n\n";
                foreach ($db['views'] as $view) {
                    $md .= "- `{$view['name']}`\n";
                }
                $md .= "\n";
            }

            // Procedures
            if (!empty($db['procedures'])) {
                $md .= "### Stored Procedures\n\n";
                foreach ($db['procedures'] as $proc) {
                    $params = '';
                    if (!empty($proc['parameters'])) {
                        $paramList = array_map(fn($p) => "{$p['name']} {$p['type']}", $proc['parameters']);
                        $params = ' (' . implode(', ', $paramList) . ')';
                    }
                    $md .= "- `{$proc['name']}`{$params}\n";
                }
                $md .= "\n";
            }

            // Functions
            if (!empty($db['functions'])) {
                $md .= "### Functions\n\n";
                foreach ($db['functions'] as $func) {
                    $md .= "- `{$func['name']}`\n";
                }
                $md .= "\n";
            }

            $md .= "---\n\n";
        }

        // Errors section
        if (!empty($this->errors)) {
            $md .= "## Errors\n\n";
            foreach ($this->errors as $error) {
                $md .= "- {$error}\n";
            }
        }

        return $md;
    }

    /**
     * Generate a quick reference summary
     */
    public function generateQuickReference(): string {
        $ref = "# PERTI Database Quick Reference\n\n";
        $ref .= "**Generated:** {$this->stats['completed_at']}\n\n";

        foreach ($this->index as $dbName => $db) {
            $ref .= "## {$dbName}\n\n";
            $ref .= "{$db['description']}\n\n";

            if (!empty($db['tables'])) {
                $ref .= "| Table | Columns | Rows | Key Columns |\n";
                $ref .= "|-------|---------|------|-------------|\n";

                foreach ($db['tables'] as $table) {
                    $colCount = count($table['columns']);
                    $rowCount = $table['row_count'] >= 0 ? number_format($table['row_count']) : 'N/A';

                    // Find primary/important columns
                    $keyCols = [];
                    foreach ($table['columns'] as $col) {
                        if (strpos($col['name'], '_id') !== false || strpos($col['name'], 'code') !== false) {
                            $keyCols[] = $col['name'];
                        }
                    }
                    $keyColStr = implode(', ', array_slice($keyCols, 0, 3));

                    $ref .= "| `{$table['name']}` | {$colCount} | {$rowCount} | {$keyColStr} |\n";
                }
                $ref .= "\n";
            }
        }

        return $ref;
    }
}

// CLI execution
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    $rootPath = dirname(__DIR__, 2);
    $configPath = $rootPath . '/load/config.php';

    if (!file_exists($configPath)) {
        echo "ERROR: Config file not found at {$configPath}\n";
        exit(1);
    }

    $indexer = new DatabaseIndexer($configPath);
    $result = $indexer->run();

    // Output
    $outputPath = $rootPath . '/data/indexes';
    if (!is_dir($outputPath)) {
        mkdir($outputPath, 0755, true);
    }

    file_put_contents($outputPath . '/database_schema.json', json_encode($result, JSON_PRETTY_PRINT));
    file_put_contents($outputPath . '/database_schema.md', $indexer->generateMarkdown());
    file_put_contents($outputPath . '/database_quick_reference.md', $indexer->generateQuickReference());

    echo "\nIndex saved to:\n";
    echo "  - {$outputPath}/database_schema.json\n";
    echo "  - {$outputPath}/database_schema.md\n";
    echo "  - {$outputPath}/database_quick_reference.md\n";
}
