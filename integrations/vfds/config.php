<?php
/**
 * VATSWIM vFDS Integration Configuration
 *
 * @package VATSWIM
 * @subpackage vFDS Integration
 */

return [
    // vFDS API configuration
    'vfds' => [
        'base_url' => getenv('VFDS_BASE_URL') ?: 'https://vfds.example.com/api/v1',
        'api_key' => getenv('VFDS_API_KEY') ?: 'your_vfds_api_key',
        'facility_id' => getenv('VFDS_FACILITY_ID') ?: 'ZNY'
    ],

    // VATSWIM API configuration
    'vatswim' => [
        'api_key' => getenv('VATSWIM_API_KEY') ?: 'your_vatswim_api_key',
        'base_url' => getenv('VATSWIM_BASE_URL') ?: 'https://perti.vatcscc.org/api/swim/v1'
    ],

    // Sync configuration
    'sync' => [
        // How often to sync (seconds)
        'interval' => 60,

        // Enable bidirectional sync
        'bidirectional' => true,

        // Sync departures
        'sync_departures' => true,

        // Sync arrivals
        'sync_arrivals' => true,

        // Sync TMI data
        'sync_tmi' => true,

        // Calculate and sync departure sequences
        'sync_sequences' => true
    ],

    // Sequencing configuration
    'sequencing' => [
        // Runway configuration type
        'runway_config' => 'single',  // single, parallel_close, parallel_far, intersecting

        // Maximum lookahead for sequencing (seconds)
        'max_lookahead' => 7200,  // 2 hours

        // Default taxi time if not known (minutes)
        'default_taxi_time' => 15
    ],

    // Airport filtering
    'airports' => [
        // Only process these airports (empty = all)
        'include' => [],

        // Exclude these airports
        'exclude' => []
    ],

    // Webhook configuration
    'webhook' => [
        // Enable webhook receiver
        'enabled' => true,

        // Webhook secret for verification
        'secret' => getenv('VFDS_WEBHOOK_SECRET') ?: 'your_webhook_secret',

        // Events to subscribe to
        'events' => [
            'departure.updated',
            'arrival.updated',
            'edct.assigned',
            'edct.cancelled',
            'tmi.activated',
            'tmi.updated',
            'tmi.cancelled'
        ]
    ],

    // Logging
    'logging' => [
        'verbose' => getenv('VATSWIM_VERBOSE') === 'true',
        'log_file' => getenv('VATSWIM_LOG_FILE') ?: '/var/log/vatswim-vfds.log'
    ]
];
