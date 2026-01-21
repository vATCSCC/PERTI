<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VATSWIM Integration Settings
    |--------------------------------------------------------------------------
    |
    | Configure the VATSWIM API connection for your virtual airline.
    |
    */

    // Enable/disable VATSWIM integration
    'enabled' => env('VATSWIM_ENABLED', false),

    // VATSWIM API key (obtain from VATSWIM portal or VATSIM OAuth)
    'api_key' => env('VATSWIM_API_KEY', ''),

    // API base URL
    'api_base_url' => env('VATSWIM_API_URL', 'https://perti.vatcscc.org/api/swim/v1'),

    // Virtual airline identifier (your VA's ICAO code)
    'airline_icao' => env('VATSWIM_AIRLINE_ICAO', ''),

    /*
    |--------------------------------------------------------------------------
    | Data Sync Settings
    |--------------------------------------------------------------------------
    */

    // Sync PIREPs to VATSWIM on filing
    'sync_pirep_filed' => env('VATSWIM_SYNC_FILED', true),

    // Sync PIREPs to VATSWIM on acceptance
    'sync_pirep_accepted' => env('VATSWIM_SYNC_ACCEPTED', true),

    // Include pilot VATSIM CID in submissions
    'include_pilot_cid' => env('VATSWIM_INCLUDE_CID', true),

    // Include aircraft registration
    'include_registration' => env('VATSWIM_INCLUDE_REG', true),

    /*
    |--------------------------------------------------------------------------
    | CDM Time Settings
    |--------------------------------------------------------------------------
    |
    | Configure which CDM milestones to submit
    |
    */

    // Submit T1-T4 predictions (airline estimates)
    'submit_predictions' => env('VATSWIM_SUBMIT_PREDICTIONS', true),

    // Submit T11-T14 actuals (OOOI times from ACARS)
    'submit_actuals' => env('VATSWIM_SUBMIT_ACTUALS', true),

    // Submit schedule times (STD/STA from flight schedules)
    'submit_schedule' => env('VATSWIM_SUBMIT_SCHEDULE', true),

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    */

    // Queue VATSWIM API calls (recommended for production)
    'use_queue' => env('VATSWIM_USE_QUEUE', true),

    // Queue name for VATSWIM jobs
    'queue_name' => env('VATSWIM_QUEUE', 'vatswim'),

    // Retry failed jobs
    'retry_failed' => env('VATSWIM_RETRY_FAILED', true),

    // Max retry attempts
    'max_retries' => env('VATSWIM_MAX_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    // Enable verbose logging
    'verbose_logging' => env('VATSWIM_VERBOSE', false),

    // Log channel
    'log_channel' => env('VATSWIM_LOG_CHANNEL', 'stack'),
];
