<?php
/**
 * PHP 8.2 Compatibility Migration Script
 *
 * This script automatically updates PHP files to use safe input handling
 * functions for PHP 8.2+ compatibility.
 *
 * Usage:
 *   php scripts/migrate_php82.php [--dry-run] [--path=api/]
 *
 * Options:
 *   --dry-run   Show what would be changed without making changes
 *   --path=X    Only process files in specified path (relative to project root)
 *   --verbose   Show detailed output for each file
 */

$projectRoot = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv);
$verbose = in_array('--verbose', $argv);

// Parse --path option
$targetPath = $projectRoot;
foreach ($argv as $arg) {
    if (strpos($arg, '--path=') === 0) {
        $targetPath = $projectRoot . '/' . substr($arg, 7);
    }
}

echo "=== PHP 8.2 Compatibility Migration ===\n";
echo "Project root: $projectRoot\n";
echo "Target path: $targetPath\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes)" : "LIVE (will modify files)") . "\n\n";

// Patterns to fix and their replacements
$patterns = [
    // Pattern 1: strip_tags($_GET['key']) -> get_input('key')
    [
        'pattern' => '/strip_tags\(\$_GET\[([\'"])([a-zA-Z_][a-zA-Z0-9_]*)\1\]\)/',
        'replacement' => 'get_input(\'$2\')',
        'description' => 'strip_tags($_GET[...]) -> get_input(...)'
    ],

    // Pattern 2: strip_tags($_POST['key']) -> post_input('key')
    [
        'pattern' => '/strip_tags\(\$_POST\[([\'"])([a-zA-Z_][a-zA-Z0-9_]*)\1\]\)/',
        'replacement' => 'post_input(\'$2\')',
        'description' => 'strip_tags($_POST[...]) -> post_input(...)'
    ],

    // Pattern 3: strip_tags($_COOKIE['key']) -> cookie_get('key')
    [
        'pattern' => '/strip_tags\(\$_COOKIE\[([\'"])([a-zA-Z_][a-zA-Z0-9_]*)\1\]\)/',
        'replacement' => 'cookie_get(\'$2\')',
        'description' => 'strip_tags($_COOKIE[...]) -> cookie_get(...)'
    ],

    // Pattern 3b: strip_tags($_SESSION['key']) -> session_get('key', '')
    [
        'pattern' => '/strip_tags\(\$_SESSION\[([\'"])([a-zA-Z_][a-zA-Z0-9_]*)\1\]\)/',
        'replacement' => 'session_get(\'$2\', \'\')',
        'description' => 'strip_tags($_SESSION[...]) -> session_get(...)'
    ],

    // Pattern 4: intval($_GET['key']) -> get_int('key')
    [
        'pattern' => '/intval\(\$_GET\[([\'"])([a-zA-Z_][a-zA-Z0-9_]*)\1\]\)/',
        'replacement' => 'get_int(\'$2\')',
        'description' => 'intval($_GET[...]) -> get_int(...)'
    ],

    // Pattern 5: intval($_POST['key']) -> post_int('key')
    [
        'pattern' => '/intval\(\$_POST\[([\'"])([a-zA-Z_][a-zA-Z0-9_]*)\1\]\)/',
        'replacement' => 'post_int(\'$2\')',
        'description' => 'intval($_POST[...]) -> post_int(...)'
    ],

    // Pattern 6: strtoupper(trim($_GET['key'])) -> get_upper('key')
    [
        'pattern' => '/strtoupper\(\s*trim\(\s*\$_GET\[([\'"])([a-zA-Z_][a-zA-Z0-9_]*)\1\]\s*\)\s*\)/',
        'replacement' => 'get_upper(\'$2\')',
        'description' => 'strtoupper(trim($_GET[...])) -> get_upper(...)'
    ],

    // Pattern 7: strtolower(trim($_GET['key'])) -> get_lower('key')
    [
        'pattern' => '/strtolower\(\s*trim\(\s*\$_GET\[([\'"])([a-zA-Z_][a-zA-Z0-9_]*)\1\]\s*\)\s*\)/',
        'replacement' => 'get_lower(\'$2\')',
        'description' => 'strtolower(trim($_GET[...])) -> get_lower(...)'
    ],

    // Pattern 8: $_SERVER['REQUEST_METHOD'] (standalone) -> ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    // Only match when it's a direct comparison, not already protected
    [
        'pattern' => '/\$_SERVER\[([\'"])REQUEST_METHOD\1\]\s*(!==|===|==|!=)\s*([\'"])([A-Z]+)\3/',
        'replacement' => '($_SERVER[\'REQUEST_METHOD\'] ?? \'GET\') $2 \'$4\'',
        'description' => '$_SERVER[REQUEST_METHOD] comparisons -> with ?? fallback'
    ],

    // Pattern 9: $method = $_SERVER['REQUEST_METHOD']; -> with fallback
    [
        'pattern' => '/\$method\s*=\s*\$_SERVER\[([\'"])REQUEST_METHOD\1\];/',
        'replacement' => '$method = $_SERVER[\'REQUEST_METHOD\'] ?? \'GET\';',
        'description' => '$method = $_SERVER[REQUEST_METHOD] -> with ?? fallback'
    ],

    // Pattern 10: $_GET['key'] ?? 'default' is already safe, skip
    // Pattern 11: isset($_GET['key']) ? ... is already safe, skip

    // Pattern 12: Direct $_POST['key'] in complex expressions - add ?? ''
    // This is tricky, so we'll handle specific common cases
    [
        'pattern' => '/str_replace\([^)]+,\s*\$_POST\[([\'"])([a-zA-Z_][a-zA-Z0-9_]*)\1\]\)/',
        'replacement' => 'str_replace("$0" => use $_POST[\'$2\'] ?? \'\')', // This needs manual review
        'description' => 'Complex $_POST expressions (needs manual review)'
    ],
];

// Files to skip
$skipFiles = [
    'migrate_php82.php',
    'input.php',
    'vendor/',
    'node_modules/',
    '.git/',
];

// Find all PHP files
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($targetPath, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;

    $path = $file->getPathname();
    $relativePath = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $path);

    // Skip excluded files/directories
    $skip = false;
    foreach ($skipFiles as $skipPattern) {
        if (strpos($relativePath, $skipPattern) !== false) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;

    $files[] = $path;
}

echo "Found " . count($files) . " PHP files to process\n\n";

$totalChanges = 0;
$filesChanged = 0;
$changesByPattern = [];

foreach ($files as $filePath) {
    $relativePath = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath);
    $content = file_get_contents($filePath);
    $originalContent = $content;
    $fileChanges = [];

    // Apply safe patterns (skip the complex one that needs manual review)
    $safePatterns = array_slice($patterns, 0, 10); // First 10 patterns are safe auto-replacements

    foreach ($safePatterns as $patternInfo) {
        $count = 0;
        $newContent = preg_replace(
            $patternInfo['pattern'],
            $patternInfo['replacement'],
            $content,
            -1,
            $count
        );

        if ($count > 0) {
            $content = $newContent;
            $fileChanges[] = "{$count}x {$patternInfo['description']}";
            $totalChanges += $count;

            if (!isset($changesByPattern[$patternInfo['description']])) {
                $changesByPattern[$patternInfo['description']] = 0;
            }
            $changesByPattern[$patternInfo['description']] += $count;
        }
    }

    if ($content !== $originalContent) {
        $filesChanged++;

        if ($verbose || $dryRun) {
            echo "[$relativePath]\n";
            foreach ($fileChanges as $change) {
                echo "  - $change\n";
            }
        }

        if (!$dryRun) {
            file_put_contents($filePath, $content);
        }
    }
}

echo "\n=== Summary ===\n";
echo "Files processed: " . count($files) . "\n";
echo "Files " . ($dryRun ? "would be " : "") . "modified: $filesChanged\n";
echo "Total replacements" . ($dryRun ? " (would be made)" : "") . ": $totalChanges\n";

if (!empty($changesByPattern)) {
    echo "\nChanges by pattern:\n";
    foreach ($changesByPattern as $pattern => $count) {
        echo "  $count x $pattern\n";
    }
}

if ($dryRun) {
    echo "\n*** DRY RUN - No files were modified ***\n";
    echo "Run without --dry-run to apply changes.\n";
}

echo "\nDone!\n";
