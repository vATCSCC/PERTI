<?php
/**
 * PERTI Codebase Indexer
 *
 * Scans the entire codebase and generates a comprehensive index for coding agents.
 * Extracts: API endpoints, functions, classes, database queries, dependencies.
 *
 * @package PERTI
 * @subpackage Indexer
 * @version 1.0.0
 * @date 2026-02-01
 */

class CodebaseIndexer {

    private string $rootPath;
    private array $index = [];
    private array $stats = [];
    private array $errors = [];

    // File patterns to scan
    private array $phpPatterns = ['*.php'];
    private array $jsPatterns = ['*.js', '*.mjs'];
    private array $sqlPatterns = ['*.sql'];

    // Directories to skip
    private array $skipDirs = [
        'vendor', 'node_modules', '.git', '.claude',
        'assets/vendor', 'assets/lib', 'assets/fonts'
    ];

    public function __construct(string $rootPath) {
        $this->rootPath = rtrim($rootPath, '/\\');
        $this->initStats();
    }

    private function initStats(): void {
        $this->stats = [
            'started_at' => date('Y-m-d H:i:s T'),
            'total_files' => 0,
            'php_files' => 0,
            'js_files' => 0,
            'sql_files' => 0,
            'api_endpoints' => 0,
            'functions' => 0,
            'classes' => 0,
            'database_queries' => 0,
        ];
    }

    /**
     * Run the full indexing process
     */
    public function run(): array {
        echo "[Codebase Indexer] Starting scan of {$this->rootPath}\n";

        // Index each category
        $this->indexApiEndpoints();
        $this->indexPhpFiles();
        $this->indexJsModules();
        $this->indexSqlMigrations();
        $this->indexConfiguration();
        $this->indexPageRoutes();
        $this->indexDaemons();

        $this->stats['completed_at'] = date('Y-m-d H:i:s T');
        $this->stats['errors'] = count($this->errors);

        echo "[Codebase Indexer] Complete. Indexed {$this->stats['total_files']} files.\n";

        return [
            'stats' => $this->stats,
            'index' => $this->index,
            'errors' => $this->errors
        ];
    }

    /**
     * Index all API endpoints in /api/
     */
    private function indexApiEndpoints(): void {
        echo "  Indexing API endpoints...\n";

        $apiPath = $this->rootPath . '/api';
        if (!is_dir($apiPath)) {
            $this->errors[] = "API directory not found: $apiPath";
            return;
        }

        $endpoints = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($apiPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') continue;

            $relativePath = str_replace($this->rootPath . '/', '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            $endpoint = $this->parseApiEndpoint($file->getPathname(), $relativePath);
            if ($endpoint) {
                $endpoints[] = $endpoint;
                $this->stats['api_endpoints']++;
            }
        }

        // Group by domain
        $grouped = [];
        foreach ($endpoints as $ep) {
            $domain = $ep['domain'] ?? 'other';
            if (!isset($grouped[$domain])) {
                $grouped[$domain] = [];
            }
            $grouped[$domain][] = $ep;
        }

        $this->index['api_endpoints'] = $grouped;
        echo "    Found {$this->stats['api_endpoints']} API endpoints\n";
    }

    /**
     * Parse a single API endpoint file
     */
    private function parseApiEndpoint(string $filepath, string $relativePath): ?array {
        $content = @file_get_contents($filepath);
        if (!$content) return null;

        $this->stats['php_files']++;
        $this->stats['total_files']++;

        // Extract domain from path (e.g., api/tmi/list.php -> tmi)
        $parts = explode('/', $relativePath);
        $domain = isset($parts[1]) && $parts[1] !== end($parts) ? $parts[1] : 'root';

        // Extract HTTP methods used
        $methods = [];
        if (preg_match('/\$_GET\b/', $content)) $methods[] = 'GET';
        if (preg_match('/\$_POST\b/', $content)) $methods[] = 'POST';
        if (preg_match('/get_input\s*\(\s*[\'"]/', $content)) $methods[] = 'GET/POST';
        if (preg_match('/file_get_contents\s*\(\s*[\'"]php:\/\/input/', $content)) $methods[] = 'JSON';

        if (empty($methods)) $methods[] = 'GET';

        // Extract description from docblock or first comment
        $description = '';
        if (preg_match('/\/\*\*\s*\n\s*\*\s*([^\n]+)/', $content, $m)) {
            $description = trim($m[1]);
        } elseif (preg_match('/\/\/\s*([^\n]+)/', $content, $m)) {
            $description = trim($m[1]);
        }

        // Extract database tables used
        $tables = $this->extractTablesFromContent($content);

        // Extract functions defined
        $functions = $this->extractFunctionsFromContent($content);

        // Check for authentication
        $requiresAuth = (bool)preg_match('/session_get|SESSION|admin_check|requireLogin/', $content);

        // Extract key parameters
        $params = $this->extractParametersFromContent($content);

        return [
            'path' => $relativePath,
            'url' => '/' . $relativePath,
            'domain' => $domain,
            'methods' => $methods,
            'description' => $description,
            'requires_auth' => $requiresAuth,
            'tables' => $tables,
            'functions' => $functions,
            'params' => array_slice($params, 0, 10), // Limit to 10 params
            'lines' => substr_count($content, "\n") + 1
        ];
    }

    /**
     * Index all PHP files for classes, functions, and dependencies
     */
    private function indexPhpFiles(): void {
        echo "  Indexing PHP files...\n";

        $classes = [];
        $functions = [];
        $includes = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->rootPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') continue;

            $relativePath = str_replace($this->rootPath . '/', '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            // Skip directories
            foreach ($this->skipDirs as $skip) {
                if (strpos($relativePath, $skip . '/') === 0) continue 2;
            }

            // Skip already-indexed API files
            if (strpos($relativePath, 'api/') === 0) continue;

            $content = @file_get_contents($file->getPathname());
            if (!$content) continue;

            $this->stats['total_files']++;
            $this->stats['php_files']++;

            // Extract classes
            if (preg_match_all('/(?:abstract\s+)?class\s+(\w+)(?:\s+extends\s+(\w+))?(?:\s+implements\s+([\w,\s]+))?/', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $className = $match[1];
                    $extends = $match[2] ?? null;
                    $implements = isset($match[3]) ? array_map('trim', explode(',', $match[3])) : [];

                    $methods = [];
                    if (preg_match_all('/(?:public|private|protected)\s+(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)/', $content, $methodMatches, PREG_SET_ORDER)) {
                        foreach ($methodMatches as $mm) {
                            $methods[] = [
                                'name' => $mm[1],
                                'params' => $mm[2] ? array_map('trim', explode(',', $mm[2])) : []
                            ];
                        }
                    }

                    $classes[] = [
                        'name' => $className,
                        'file' => $relativePath,
                        'extends' => $extends,
                        'implements' => $implements,
                        'methods' => $methods,
                        'line' => $this->findLineNumber($content, $match[0])
                    ];
                    $this->stats['classes']++;
                }
            }

            // Extract standalone functions (not in classes)
            if (preg_match_all('/^function\s+(\w+)\s*\(([^)]*)\)/m', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $functions[] = [
                        'name' => $match[1],
                        'file' => $relativePath,
                        'params' => $match[2] ? array_map('trim', explode(',', $match[2])) : [],
                        'line' => $this->findLineNumber($content, $match[0])
                    ];
                    $this->stats['functions']++;
                }
            }

            // Extract includes/requires
            if (preg_match_all('/(require|include)(?:_once)?\s*\(?[\'"]([^\'"]+)[\'"]\)?/', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $includes[] = [
                        'from' => $relativePath,
                        'type' => $match[1],
                        'target' => $match[2]
                    ];
                }
            }
        }

        $this->index['classes'] = $classes;
        $this->index['functions'] = $functions;
        $this->index['includes'] = $includes;

        echo "    Found {$this->stats['classes']} classes, {$this->stats['functions']} functions\n";
    }

    /**
     * Index JavaScript modules
     */
    private function indexJsModules(): void {
        echo "  Indexing JavaScript modules...\n";

        $jsPath = $this->rootPath . '/assets/js';
        if (!is_dir($jsPath)) {
            $this->errors[] = "JS directory not found: $jsPath";
            return;
        }

        $modules = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($jsPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $ext = $file->getExtension();
            if ($ext !== 'js' && $ext !== 'mjs') continue;

            $relativePath = str_replace($this->rootPath . '/', '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            // Skip vendor/lib
            if (strpos($relativePath, 'vendor/') !== false || strpos($relativePath, 'lib/') !== false) continue;

            $content = @file_get_contents($file->getPathname());
            if (!$content) continue;

            $this->stats['total_files']++;
            $this->stats['js_files']++;

            // Extract exports
            $exports = [];
            if (preg_match_all('/export\s+(?:default\s+)?(?:const|let|var|function|class)\s+(\w+)/', $content, $matches)) {
                $exports = array_merge($exports, $matches[1]);
            }
            if (preg_match_all('/export\s*\{\s*([^}]+)\s*\}/', $content, $matches)) {
                foreach ($matches[1] as $exportList) {
                    $exports = array_merge($exports, array_map('trim', explode(',', $exportList)));
                }
            }

            // Extract imports
            $imports = [];
            if (preg_match_all('/import\s+(?:\{[^}]+\}|[\w*]+)\s+from\s+[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                $imports = $matches[1];
            }

            // Extract main functions/classes
            $definitions = [];
            if (preg_match_all('/(?:async\s+)?function\s+(\w+)\s*\(/', $content, $matches)) {
                $definitions = array_merge($definitions, array_map(fn($n) => "function $n()", $matches[1]));
            }
            if (preg_match_all('/class\s+(\w+)/', $content, $matches)) {
                $definitions = array_merge($definitions, array_map(fn($n) => "class $n", $matches[1]));
            }

            // Detect purpose from content
            $purpose = $this->detectJsPurpose($content, $relativePath);

            $modules[] = [
                'path' => $relativePath,
                'exports' => array_unique($exports),
                'imports' => array_unique($imports),
                'definitions' => array_slice(array_unique($definitions), 0, 15),
                'purpose' => $purpose,
                'lines' => substr_count($content, "\n") + 1
            ];
        }

        $this->index['js_modules'] = $modules;
        echo "    Found {$this->stats['js_files']} JS modules\n";
    }

    /**
     * Index SQL migration files
     */
    private function indexSqlMigrations(): void {
        echo "  Indexing SQL migrations...\n";

        $migrationPath = $this->rootPath . '/database/migrations';
        if (!is_dir($migrationPath)) {
            $this->errors[] = "Migration directory not found: $migrationPath";
            return;
        }

        $migrations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($migrationPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'sql') continue;

            $relativePath = str_replace($this->rootPath . '/', '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            $content = @file_get_contents($file->getPathname());
            if (!$content) continue;

            $this->stats['total_files']++;
            $this->stats['sql_files']++;

            // Extract database from path (e.g., database/migrations/tmi/ -> tmi)
            $parts = explode('/', $relativePath);
            $database = $parts[2] ?? 'unknown';

            // Extract operations
            $operations = [];
            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:\[?dbo\]?\.)?\[?(\w+)\]?/i', $content, $matches)) {
                foreach ($matches[1] as $table) {
                    $operations[] = "CREATE TABLE $table";
                }
            }
            if (preg_match_all('/ALTER\s+TABLE\s+(?:\[?dbo\]?\.)?\[?(\w+)\]?/i', $content, $matches)) {
                foreach ($matches[1] as $table) {
                    $operations[] = "ALTER TABLE $table";
                }
            }
            if (preg_match_all('/CREATE\s+(?:UNIQUE\s+)?(?:CLUSTERED\s+)?INDEX\s+\[?(\w+)\]?\s+ON\s+(?:\[?dbo\]?\.)?\[?(\w+)\]?/i', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $operations[] = "CREATE INDEX {$m[1]} ON {$m[2]}";
                }
            }
            if (preg_match_all('/CREATE\s+(?:OR\s+ALTER\s+)?PROCEDURE\s+(?:\[?dbo\]?\.)?\[?(\w+)\]?/i', $content, $matches)) {
                foreach ($matches[1] as $proc) {
                    $operations[] = "CREATE PROCEDURE $proc";
                }
            }
            if (preg_match_all('/CREATE\s+(?:OR\s+ALTER\s+)?VIEW\s+(?:\[?dbo\]?\.)?\[?(\w+)\]?/i', $content, $matches)) {
                foreach ($matches[1] as $view) {
                    $operations[] = "CREATE VIEW $view";
                }
            }
            if (preg_match_all('/INSERT\s+INTO\s+(?:\[?dbo\]?\.)?\[?(\w+)\]?/i', $content, $matches)) {
                $tables = array_unique($matches[1]);
                foreach ($tables as $table) {
                    $operations[] = "INSERT INTO $table";
                }
            }

            // Extract description from first comment
            $description = '';
            if (preg_match('/^--\s*(.+)/m', $content, $m)) {
                $description = trim($m[1]);
            }

            $migrations[] = [
                'path' => $relativePath,
                'database' => $database,
                'filename' => $file->getFilename(),
                'description' => $description,
                'operations' => array_unique($operations),
                'lines' => substr_count($content, "\n") + 1
            ];
        }

        // Group by database
        $grouped = [];
        foreach ($migrations as $mig) {
            $db = $mig['database'];
            if (!isset($grouped[$db])) {
                $grouped[$db] = [];
            }
            $grouped[$db][] = $mig;
        }

        $this->index['migrations'] = $grouped;
        echo "    Found {$this->stats['sql_files']} SQL migrations\n";
    }

    /**
     * Index configuration files
     */
    private function indexConfiguration(): void {
        echo "  Indexing configuration...\n";

        $configs = [];

        // Load folder
        $loadPath = $this->rootPath . '/load';
        if (is_dir($loadPath)) {
            $files = glob($loadPath . '/*.php');
            foreach ($files as $file) {
                $filename = basename($file);
                $content = @file_get_contents($file);
                if (!$content) continue;

                // Extract defined constants
                $constants = [];
                if (preg_match_all('/define\s*\(\s*[\'"](\w+)[\'"]/', $content, $matches)) {
                    $constants = $matches[1];
                }

                // Extract key functions
                $functions = [];
                if (preg_match_all('/function\s+(\w+)\s*\(/', $content, $matches)) {
                    $functions = $matches[1];
                }

                $configs[] = [
                    'path' => "load/$filename",
                    'type' => 'php',
                    'constants' => $constants,
                    'functions' => $functions,
                    'description' => $this->extractFileDescription($content)
                ];
            }
        }

        // JSON configs
        $jsonFiles = glob($this->rootPath . '/load/*.json');
        foreach ($jsonFiles as $file) {
            $filename = basename($file);
            $content = @file_get_contents($file);
            $data = @json_decode($content, true);

            $configs[] = [
                'path' => "load/$filename",
                'type' => 'json',
                'keys' => $data ? array_keys($data) : [],
                'description' => "JSON configuration file"
            ];
        }

        $this->index['configuration'] = $configs;
        echo "    Found " . count($configs) . " configuration files\n";
    }

    /**
     * Index main page routes (root PHP files)
     */
    private function indexPageRoutes(): void {
        echo "  Indexing page routes...\n";

        $pages = [];
        $files = glob($this->rootPath . '/*.php');

        foreach ($files as $file) {
            $filename = basename($file);
            $content = @file_get_contents($file);
            if (!$content) continue;

            // Skip utility files
            if (in_array($filename, ['healthcheck.php', 'phpinfo.php'])) continue;

            // Extract page title
            $title = '';
            if (preg_match('/<title>([^<]+)<\/title>/', $content, $m)) {
                $title = trim(strip_tags($m[1]));
            } elseif (preg_match('/\$pageTitle\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
                $title = $m[1];
            }

            // Check if it requires auth
            $requiresAuth = (bool)preg_match('/require.*header\.php|session_get|admin_check/', $content);

            // Extract main functionality
            $purpose = $this->extractFileDescription($content);

            $pages[] = [
                'path' => $filename,
                'url' => '/' . str_replace('.php', '', $filename),
                'title' => $title ?: str_replace(['.php', '_', '-'], ['', ' ', ' '], $filename),
                'requires_auth' => $requiresAuth,
                'purpose' => $purpose,
                'lines' => substr_count($content, "\n") + 1
            ];
        }

        $this->index['pages'] = $pages;
        echo "    Found " . count($pages) . " page routes\n";
    }

    /**
     * Index daemon/background processes
     */
    private function indexDaemons(): void {
        echo "  Indexing daemons and cron jobs...\n";

        $daemons = [];

        // Scripts folder
        $scriptFiles = glob($this->rootPath . '/scripts/*daemon*.php');
        $scriptFiles = array_merge($scriptFiles, glob($this->rootPath . '/scripts/*sync*.php'));

        foreach ($scriptFiles as $file) {
            $filename = basename($file);
            $content = @file_get_contents($file);
            if (!$content) continue;

            $daemons[] = [
                'path' => "scripts/$filename",
                'type' => strpos($filename, 'daemon') !== false ? 'daemon' : 'sync',
                'description' => $this->extractFileDescription($content),
                'databases' => $this->extractTablesFromContent($content)
            ];
        }

        // ADL daemons
        $adlDaemons = glob($this->rootPath . '/adl/php/*daemon*.php');
        foreach ($adlDaemons as $file) {
            $filename = basename($file);
            $content = @file_get_contents($file);
            if (!$content) continue;

            $daemons[] = [
                'path' => "adl/php/$filename",
                'type' => 'daemon',
                'description' => $this->extractFileDescription($content),
                'databases' => $this->extractTablesFromContent($content)
            ];
        }

        // Cron jobs
        $cronFiles = glob($this->rootPath . '/cron/*.php');
        foreach ($cronFiles as $file) {
            $filename = basename($file);
            $content = @file_get_contents($file);
            if (!$content) continue;

            $daemons[] = [
                'path' => "cron/$filename",
                'type' => 'cron',
                'description' => $this->extractFileDescription($content),
                'databases' => $this->extractTablesFromContent($content)
            ];
        }

        $this->index['daemons'] = $daemons;
        echo "    Found " . count($daemons) . " daemons/cron jobs\n";
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function extractTablesFromContent(string $content): array {
        $tables = [];

        // Match various SQL patterns
        $patterns = [
            '/FROM\s+(?:\[?dbo\]?\.)?\[?(\w+)\]?/i',
            '/JOIN\s+(?:\[?dbo\]?\.)?\[?(\w+)\]?/i',
            '/INTO\s+(?:\[?dbo\]?\.)?\[?(\w+)\]?/i',
            '/UPDATE\s+(?:\[?dbo\]?\.)?\[?(\w+)\]?/i',
            '/DELETE\s+FROM\s+(?:\[?dbo\]?\.)?\[?(\w+)\]?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                $tables = array_merge($tables, $matches[1]);
                $this->stats['database_queries'] += count($matches[1]);
            }
        }

        return array_values(array_unique(array_filter($tables, fn($t) =>
            strlen($t) > 2 && !in_array(strtolower($t), ['select', 'where', 'and', 'set'])
        )));
    }

    private function extractFunctionsFromContent(string $content): array {
        $functions = [];

        if (preg_match_all('/function\s+(\w+)\s*\(/', $content, $matches)) {
            $functions = $matches[1];
        }

        return array_values(array_unique($functions));
    }

    private function extractParametersFromContent(string $content): array {
        $params = [];

        // Match get_input, $_GET, $_POST patterns
        if (preg_match_all('/get_input\s*\(\s*[\'"](\w+)[\'"]/', $content, $matches)) {
            $params = array_merge($params, $matches[1]);
        }
        if (preg_match_all('/\$_(?:GET|POST)\s*\[\s*[\'"](\w+)[\'"]/', $content, $matches)) {
            $params = array_merge($params, $matches[1]);
        }

        return array_values(array_unique($params));
    }

    private function extractFileDescription(string $content): string {
        // Try docblock
        if (preg_match('/\/\*\*\s*\n\s*\*\s*([^\n]+)/', $content, $m)) {
            return trim($m[1]);
        }
        // Try single-line comment at top
        if (preg_match('/^<\?php\s*\n(?:\/\/\s*)?([^\n]+)/m', $content, $m)) {
            $line = trim($m[1]);
            if ($line && !preg_match('/^(require|include|namespace|use|declare)/', $line)) {
                return $line;
            }
        }
        return '';
    }

    private function detectJsPurpose(string $content, string $path): string {
        if (strpos($content, 'MapLibre') !== false || strpos($content, 'maplibregl') !== false) {
            return 'Map visualization (MapLibre)';
        }
        if (strpos($content, 'Chart') !== false || strpos($content, 'chart') !== false) {
            return 'Chart/data visualization';
        }
        if (strpos($content, 'fetch(') !== false || strpos($content, 'XMLHttpRequest') !== false) {
            return 'API client/data fetching';
        }
        if (strpos($content, 'addEventListener') !== false) {
            return 'UI event handling';
        }
        if (strpos($path, 'config/') !== false) {
            return 'Configuration constants';
        }
        return 'Utility module';
    }

    private function findLineNumber(string $content, string $needle): int {
        $pos = strpos($content, $needle);
        if ($pos === false) return 0;
        return substr_count(substr($content, 0, $pos), "\n") + 1;
    }

    /**
     * Generate markdown documentation from the index
     */
    public function generateMarkdown(): string {
        $md = "# PERTI Codebase Index\n\n";
        $md .= "**Generated:** {$this->stats['completed_at']}\n\n";
        $md .= "## Statistics\n\n";
        $md .= "| Metric | Count |\n";
        $md .= "|--------|-------|\n";
        $md .= "| Total Files | {$this->stats['total_files']} |\n";
        $md .= "| PHP Files | {$this->stats['php_files']} |\n";
        $md .= "| JS Modules | {$this->stats['js_files']} |\n";
        $md .= "| SQL Migrations | {$this->stats['sql_files']} |\n";
        $md .= "| API Endpoints | {$this->stats['api_endpoints']} |\n";
        $md .= "| Classes | {$this->stats['classes']} |\n";
        $md .= "| Functions | {$this->stats['functions']} |\n";
        $md .= "\n---\n\n";

        // API Endpoints by domain
        $md .= "## API Endpoints\n\n";
        if (isset($this->index['api_endpoints'])) {
            foreach ($this->index['api_endpoints'] as $domain => $endpoints) {
                $md .= "### /{$domain}/\n\n";
                $md .= "| Endpoint | Methods | Description | Auth | Tables |\n";
                $md .= "|----------|---------|-------------|------|--------|\n";
                foreach ($endpoints as $ep) {
                    $methods = implode(', ', $ep['methods']);
                    $auth = $ep['requires_auth'] ? '✓' : '';
                    $tables = implode(', ', array_slice($ep['tables'], 0, 3));
                    $desc = substr($ep['description'], 0, 50);
                    $md .= "| `{$ep['path']}` | {$methods} | {$desc} | {$auth} | {$tables} |\n";
                }
                $md .= "\n";
            }
        }

        // Page Routes
        $md .= "## Page Routes\n\n";
        $md .= "| Page | URL | Title | Auth |\n";
        $md .= "|------|-----|-------|------|\n";
        if (isset($this->index['pages'])) {
            foreach ($this->index['pages'] as $page) {
                $auth = $page['requires_auth'] ? '✓' : '';
                $md .= "| `{$page['path']}` | {$page['url']} | {$page['title']} | {$auth} |\n";
            }
        }
        $md .= "\n";

        // Classes
        $md .= "## PHP Classes\n\n";
        if (isset($this->index['classes'])) {
            foreach ($this->index['classes'] as $class) {
                $extends = $class['extends'] ? " extends {$class['extends']}" : '';
                $md .= "### {$class['name']}{$extends}\n\n";
                $md .= "**File:** `{$class['file']}:{$class['line']}`\n\n";
                if (!empty($class['methods'])) {
                    $md .= "**Methods:** ";
                    $methodNames = array_map(fn($m) => "`{$m['name']}()`", array_slice($class['methods'], 0, 10));
                    $md .= implode(', ', $methodNames) . "\n\n";
                }
            }
        }

        // JavaScript Modules
        $md .= "## JavaScript Modules\n\n";
        $md .= "| Module | Purpose | Exports |\n";
        $md .= "|--------|---------|--------|\n";
        if (isset($this->index['js_modules'])) {
            foreach ($this->index['js_modules'] as $mod) {
                $exports = implode(', ', array_slice($mod['exports'], 0, 5));
                $md .= "| `{$mod['path']}` | {$mod['purpose']} | {$exports} |\n";
            }
        }
        $md .= "\n";

        // Daemons
        $md .= "## Background Processes\n\n";
        $md .= "| Script | Type | Description | Tables |\n";
        $md .= "|--------|------|-------------|--------|\n";
        if (isset($this->index['daemons'])) {
            foreach ($this->index['daemons'] as $daemon) {
                $tables = implode(', ', array_slice($daemon['databases'], 0, 3));
                $desc = substr($daemon['description'], 0, 40);
                $md .= "| `{$daemon['path']}` | {$daemon['type']} | {$desc} | {$tables} |\n";
            }
        }
        $md .= "\n";

        // Configuration
        $md .= "## Configuration Files\n\n";
        if (isset($this->index['configuration'])) {
            foreach ($this->index['configuration'] as $config) {
                $md .= "### {$config['path']}\n\n";
                if (!empty($config['constants'])) {
                    $md .= "**Constants:** " . implode(', ', array_slice($config['constants'], 0, 10)) . "\n\n";
                }
                if (!empty($config['functions'])) {
                    $md .= "**Functions:** " . implode(', ', $config['functions']) . "\n\n";
                }
                if (!empty($config['keys'])) {
                    $md .= "**Keys:** " . implode(', ', array_slice($config['keys'], 0, 10)) . "\n\n";
                }
            }
        }

        // SQL Migrations by database
        $md .= "## SQL Migrations\n\n";
        if (isset($this->index['migrations'])) {
            foreach ($this->index['migrations'] as $db => $migrations) {
                $md .= "### {$db}\n\n";
                foreach ($migrations as $mig) {
                    $ops = implode(', ', array_slice($mig['operations'], 0, 5));
                    $md .= "- `{$mig['filename']}`: {$ops}\n";
                }
                $md .= "\n";
            }
        }

        return $md;
    }
}

// CLI execution
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    $rootPath = dirname(__DIR__, 2);
    $indexer = new CodebaseIndexer($rootPath);
    $result = $indexer->run();

    // Output JSON
    $outputPath = $rootPath . '/data/indexes';
    if (!is_dir($outputPath)) {
        mkdir($outputPath, 0755, true);
    }

    file_put_contents($outputPath . '/codebase_index.json', json_encode($result, JSON_PRETTY_PRINT));
    file_put_contents($outputPath . '/codebase_index.md', $indexer->generateMarkdown());

    echo "\nIndex saved to:\n";
    echo "  - {$outputPath}/codebase_index.json\n";
    echo "  - {$outputPath}/codebase_index.md\n";
}
