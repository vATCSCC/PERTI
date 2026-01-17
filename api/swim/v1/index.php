<?php
/**
 * VATSIM SWIM API v1 - Index/Router
 * @version 1.0.0
 */

require_once __DIR__ . '/auth.php';

SwimResponse::handlePreflight();

SwimResponse::success([
    'name' => 'VATSIM SWIM API',
    'version' => SWIM_API_VERSION,
    'description' => 'System Wide Information Management for VATSIM',
    'documentation' => 'https://perti.vatcscc.org/docs/swim/',
    'endpoints' => [
        'flights' => [
            'GET /api/swim/v1/flights' => 'List flights with filters',
            'GET /api/swim/v1/flight' => 'Get single flight by GUFI or flight_key'
        ],
        'positions' => [
            'GET /api/swim/v1/positions' => 'Bulk flight positions (GeoJSON)'
        ],
        'tmi' => [
            'GET /api/swim/v1/tmi/programs' => 'Active TMI programs (GS/GDP)',
            'GET /api/swim/v1/tmi/controlled' => 'Flights under TMI control'
        ],
        'metering' => [
            'GET /api/swim/v1/metering/{airport}' => 'Metering data for airport arrivals (FIXM/TBFM)',
            'GET /api/swim/v1/metering/{airport}/sequence' => 'Arrival sequence list for datablocks'
        ],
        'ingest' => [
            'POST /api/swim/v1/ingest/adl' => 'Ingest ADL flight data',
            'POST /api/swim/v1/ingest/track' => 'Ingest position data (batch)',
            'POST /api/swim/v1/ingest/metering' => 'Ingest TBFM metering data (SimTraffic)'
        ]
    ],
    'authentication' => [
        'type' => 'Bearer token',
        'header' => 'Authorization: Bearer {api_key}',
        'tiers' => [
            'system' => '10,000 req/min, full write',
            'partner' => '1,000 req/min, limited write',
            'developer' => '100 req/min, read-only',
            'public' => '30 req/min, read-only'
        ]
    ],
    'contact' => ['email' => 'dev@vatcscc.org', 'discord' => 'vATCSCC Server']
], ['server_time' => gmdate('c'), 'api_prefix' => SWIM_API_PREFIX]);
