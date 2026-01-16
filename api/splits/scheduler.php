<?php
/**
 * Splits Scheduler (Legacy Wrapper)
 *
 * This redirects to the unified scheduler at /api/scheduler.php
 * with type=splits filter for backwards compatibility.
 *
 * For new integrations, use /api/scheduler.php directly.
 */

// Pass through query parameters
$_GET['type'] = 'splits';

// Include the unified scheduler
include __DIR__ . '/../scheduler.php';
