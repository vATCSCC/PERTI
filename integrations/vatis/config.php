<?php
/**
 * VATSWIM vATIS Integration Configuration
 *
 * @package VATSWIM
 * @subpackage vATIS Integration
 */

return [
    // VATSWIM API configuration
    'vatswim' => [
        'api_key' => getenv('VATSWIM_API_KEY') ?: 'your_vatswim_api_key',
        'base_url' => getenv('VATSWIM_BASE_URL') ?: 'https://perti.vatcscc.org/api/swim/v1'
    ],

    // Polling configuration
    'polling' => [
        // How often to refresh ATIS data (seconds)
        'atis_refresh' => 60,

        // How often to sync correlated flights (seconds)
        'sync_interval' => 60,

        // Maximum flights to process per sync cycle
        'batch_size' => 100
    ],

    // Airport filtering
    'airports' => [
        // Only process these airports (empty = all)
        'include' => [],

        // Exclude these airports
        'exclude' => [],

        // Only process US airports
        'us_only' => false,

        // Minimum traffic threshold to process
        'min_traffic' => 0
    ],

    // Weather thresholds
    'weather' => [
        // Maximum acceptable crosswind (knots) for runway selection
        'max_crosswind' => 20,

        // Crosswind limit for reporting warnings
        'crosswind_warning' => 15
    ],

    // Logging
    'logging' => [
        'verbose' => getenv('VATSWIM_VERBOSE') === 'true',
        'log_file' => getenv('VATSWIM_LOG_FILE') ?: '/var/log/vatswim-vatis.log'
    ]
];
