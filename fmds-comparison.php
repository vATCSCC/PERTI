<?php
/**
 * FMDS vs PERTI Comparison - Markdown Document Viewer
 *
 * Renders the FMDS comparison document as a styled HTML page.
 * Follows the swim-doc.php pattern for markdown rendering.
 *
 * @package PERTI
 */

/**
 * OPTIMIZED: Public page - no session handler or DB needed
 */
include("load/config.php");

$file_path = __DIR__ . '/docs/fmds-comparison.md';

if (!file_exists($file_path)) {
    $error_message = "FMDS comparison document not found.";
    $markdown_content = "";
} else {
    $markdown_content = file_get_contents($file_path);
    $error_message = null;
}

// Use Parsedown if available, otherwise use basic conversion
function render_markdown($text) {
    $parsedown_path = __DIR__ . '/vendor/parsedown/Parsedown.php';
    if (file_exists($parsedown_path)) {
        require_once $parsedown_path;
        $parsedown = new Parsedown();
        $parsedown->setSafeMode(true);
        return $parsedown->text($text);
    }

    // Basic markdown to HTML conversion (fallback)
    $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Code blocks (fenced)
    $html = preg_replace_callback('/```(\w*)\n(.*?)```/s', function($m) {
        $lang = $m[1] ? ' class="language-' . $m[1] . '"' : '';
        return '<pre><code' . $lang . '>' . $m[2] . '</code></pre>';
    }, $html);

    // Inline code
    $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

    // Headers
    $html = preg_replace('/^######\s+(.*)$/m', '<h6>$1</h6>', $html);
    $html = preg_replace('/^#####\s+(.*)$/m', '<h5>$1</h5>', $html);
    $html = preg_replace('/^####\s+(.*)$/m', '<h4>$1</h4>', $html);
    $html = preg_replace('/^###\s+(.*)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^##\s+(.*)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^#\s+(.*)$/m', '<h1>$1</h1>', $html);

    // Bold and italic
    $html = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);

    // Links
    $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $html);

    // Horizontal rules
    $html = preg_replace('/^---+$/m', '<hr>', $html);

    // Tables (basic support)
    $html = preg_replace_callback('/^\|(.+)\|$/m', function($m) {
        $cells = explode('|', trim($m[1]));
        $row = '<tr>';
        foreach ($cells as $cell) {
            $cell = trim($cell);
            if (preg_match('/^[-:]+$/', $cell)) {
                return '';
            }
            $row .= '<td>' . $cell . '</td>';
        }
        $row .= '</tr>';
        return $row;
    }, $html);

    // Wrap table rows
    $html = preg_replace('/(<tr>.*?<\/tr>\s*)+/s', '<table class="doc-table">$0</table>', $html);

    // Lists (unordered)
    $html = preg_replace('/^[\*\-]\s+(.*)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/(<li>.*<\/li>\s*)+/s', '<ul>$0</ul>', $html);

    // Paragraphs
    $lines = explode("\n", $html);
    $result = [];
    $in_pre = false;
    foreach ($lines as $line) {
        if (strpos($line, '<pre>') !== false) $in_pre = true;
        if (strpos($line, '</pre>') !== false) $in_pre = false;

        if (!$in_pre && trim($line) !== '' &&
            !preg_match('/^<(h[1-6]|ul|ol|li|table|tr|td|th|hr|pre|code|blockquote)/', trim($line))) {
            $line = '<p>' . $line . '</p>';
        }
        $result[] = $line;
    }
    $html = implode("\n", $result);

    // Clean up empty paragraphs
    $html = preg_replace('/<p>\s*<\/p>/', '', $html);

    return $html;
}

$rendered_html = $error_message ? '' : render_markdown($markdown_content);
?>

<!DOCTYPE html>
<html>
<head>
    <?php
        $page_title = "FMDS vs PERTI Comparison - PERTI";
        include("load/header.php");
    ?>
    <style>
        /* Documentation Viewer Styling (matches swim-doc.php) */
        .doc-viewer {
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin: 20px 0;
        }

        .doc-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            padding: 25px 30px;
            border-radius: 6px 6px 0 0;
        }
        .doc-header h1 {
            margin: 0 0 8px 0;
            font-size: 1.5rem;
            font-weight: 600;
        }
        .doc-header p {
            margin: 0;
            color: #b0bec5;
            font-size: 0.9rem;
        }
        .doc-header .doc-actions {
            margin-top: 15px;
        }
        .doc-header .doc-actions a {
            color: #5dade2;
            font-size: 0.85rem;
            margin-right: 20px;
        }
        .doc-header .doc-actions a:hover {
            color: #fff;
        }
        .doc-header .doc-actions i {
            margin-right: 5px;
        }

        .doc-content {
            padding: 30px;
            font-size: 0.95rem;
            line-height: 1.7;
            color: #333;
        }

        /* Typography */
        .doc-content h1 {
            font-size: 1.8rem;
            font-weight: 600;
            color: #1a1a2e;
            margin: 30px 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #5dade2;
        }
        .doc-content h1:first-child {
            margin-top: 0;
        }
        .doc-content h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a1a2e;
            margin: 25px 0 12px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #dee2e6;
        }
        .doc-content h3 {
            font-size: 1.15rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 20px 0 10px 0;
        }
        .doc-content h4, .doc-content h5, .doc-content h6 {
            font-size: 1rem;
            font-weight: 600;
            color: #34495e;
            margin: 15px 0 8px 0;
        }

        .doc-content p {
            margin: 0 0 15px 0;
        }

        .doc-content a {
            color: #5dade2;
        }
        .doc-content a:hover {
            color: #3498db;
        }

        .doc-content ul, .doc-content ol {
            margin: 0 0 15px 0;
            padding-left: 25px;
        }
        .doc-content li {
            margin-bottom: 5px;
        }

        .doc-content hr {
            border: none;
            border-top: 1px solid #dee2e6;
            margin: 30px 0;
        }

        /* Code styling */
        .doc-content code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 0.85em;
            color: #c7254e;
        }
        .doc-content pre {
            background: #1a1a2e;
            color: #e0e0e0;
            padding: 15px 20px;
            border-radius: 4px;
            overflow-x: auto;
            margin: 15px 0;
        }
        .doc-content pre code {
            background: transparent;
            color: inherit;
            padding: 0;
            font-size: 0.85rem;
            line-height: 1.5;
        }

        /* Table styling */
        .doc-content table, .doc-content .doc-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 0.9rem;
        }
        .doc-content table th, .doc-content table td,
        .doc-content .doc-table th, .doc-content .doc-table td {
            border: 1px solid #dee2e6;
            padding: 10px 12px;
            text-align: left;
        }
        .doc-content table th, .doc-content .doc-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #1a1a2e;
        }
        .doc-content table tr:nth-child(even),
        .doc-content .doc-table tr:nth-child(even) {
            background: #fafafa;
        }
        .doc-content table code, .doc-content .doc-table code {
            background: #e9ecef;
            font-size: 0.8rem;
        }

        /* Blockquote */
        .doc-content blockquote {
            border-left: 4px solid #5dade2;
            background: #f8f9fa;
            padding: 15px 20px;
            margin: 15px 0;
            color: #555;
        }

        /* Back link */
        .doc-back {
            margin-bottom: 20px;
        }
        .doc-back a {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .doc-back a:hover {
            color: #5dade2;
        }
        .doc-back i {
            margin-right: 5px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .doc-content {
                padding: 20px;
            }
            .doc-content pre {
                padding: 10px;
            }
            .doc-content table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>

<body>

<?php include('load/nav_public.php'); ?>

<div class="container py-4">

    <div class="doc-back">
        <a href="transparency"><i class="fas fa-arrow-left"></i> Back to About</a>
    </div>

    <div class="doc-viewer">
        <div class="doc-header">
            <h1>FMDS vs PERTI: Functional Comparison</h1>
            <p>Mapping FAA Flow Management Data and Services (FMDS) capabilities against PERTI's existing functionality</p>
            <div class="doc-actions">
                <a href="docs/fmds-comparison.md" download>
                    <i class="fas fa-download"></i> Download Markdown
                </a>
                <a href="https://github.com/vATCSCC/PERTI/wiki/FMDS-Comparison" target="_blank">
                    <i class="fab fa-github"></i> View on Wiki
                </a>
            </div>
        </div>

        <div class="doc-content">
            <?php if ($error_message): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php else: ?>
                <?= $rendered_html ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include('load/footer.php'); ?>

</body>
</html>
