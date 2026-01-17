<?php
/**
 * TMI API Index / Info
 * 
 * GET /api/tmi/ - Returns API information and available endpoints
 * 
 * @package PERTI
 * @subpackage TMI
 */

require_once __DIR__ . '/helpers.php';

// No auth required for info endpoint
tmi_init(false);

$method = tmi_method();

if ($method !== 'GET') {
    TmiResponse::error('Method not allowed', 405);
}

TmiResponse::success([
    'name' => 'VATSIM TMI API',
    'version' => '1.0.0',
    'description' => 'Traffic Management Initiative API for PERTI',
    'documentation' => 'https://perti.vatcscc.org/docs/api/tmi',
    'endpoints' => [
        'entries' => [
            'path' => '/api/tmi/entries.php',
            'description' => 'NTML log entries (MIT, MINIT, DELAY, etc.)',
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ],
        'programs' => [
            'path' => '/api/tmi/programs.php',
            'description' => 'GDT programs (Ground Stop, Ground Delay)',
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ],
        'advisories' => [
            'path' => '/api/tmi/advisories.php',
            'description' => 'Formal advisories',
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ],
        'public-routes' => [
            'path' => '/api/tmi/public-routes.php',
            'description' => 'Public route display for map',
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ],
        'reroutes' => [
            'path' => '/api/tmi/reroutes.php',
            'description' => 'Reroute definitions and flight assignments',
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ],
        'active' => [
            'path' => '/api/tmi/active.php',
            'description' => 'All currently active TMI data',
            'methods' => ['GET']
        ]
    ],
    'database' => [
        'server' => 'vatsim.database.windows.net',
        'database' => 'VATSIM_TMI',
        'status' => 'connected'
    ]
]);
