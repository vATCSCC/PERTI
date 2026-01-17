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
            'GET /api/swim/v1/tmi/controlled' => 'Flights under TMI control',
            'GET /api/swim/v1/tmi/reroutes' => 'TMI reroute definitions',
            'GET|POST /api/swim/v1/tmi/gs' => 'Ground Stop programs',
            'GET /api/swim/v1/tmi/gs/{id}' => 'Ground Stop details',
            'GET /api/swim/v1/tmi/gs/{id}/flights' => 'Flights affected by GS',
            'POST /api/swim/v1/tmi/gs/{id}/model' => 'Model GS impact',
            'POST /api/swim/v1/tmi/gs/{id}/activate' => 'Activate GS program',
            'GET|POST /api/swim/v1/tmi/gdp' => 'Ground Delay Programs (EBSA slot allocation)',
            'GET /api/swim/v1/tmi/gdp/{id}/flights' => 'Flights in GDP',
            'GET /api/swim/v1/tmi/gdp/{id}/slots' => 'GDP slot allocation',
            'GET /api/swim/v1/tmi/mit' => 'Miles-In-Trail restrictions',
            'GET /api/swim/v1/tmi/minit' => 'Minutes-In-Trail restrictions',
            'GET /api/swim/v1/tmi/afp' => 'Airspace Flow Programs'
        ],
        'metering' => [
            'GET /api/swim/v1/metering/{airport}' => 'Metering data for airport arrivals (FIXM/TBFM)',
            'GET /api/swim/v1/metering/{airport}/sequence' => 'Arrival sequence list for datablocks'
        ],
        'ingest' => [
            'POST /api/swim/v1/ingest/adl' => 'Ingest ADL flight data',
            'POST /api/swim/v1/ingest/track' => 'Ingest position data (batch)',
            'POST /api/swim/v1/ingest/metering' => 'Ingest TBFM metering data (SimTraffic)'
        ],
        'jatoc' => [
            'GET /api/swim/v1/jatoc/incidents' => 'JATOC incident records'
        ],
        'configuration' => [
            'GET /api/swim/v1/splits/presets' => 'Runway configuration presets',
            'GET /api/swim/v1/fea' => 'Flow Evaluation Areas'
        ]
    ],
    'authentication' => [
        'type' => 'Bearer token',
        'header' => 'Authorization: Bearer {api_key}',
        'tiers' => [
            'system' => '30,000 req/min, full write',
            'partner' => '3,000 req/min, limited write',
            'developer' => '300 req/min, read-only',
            'public' => '100 req/min, read-only'
        ]
    ],
    'contact' => ['email' => 'dev@vatcscc.org', 'discord' => 'vATCSCC Server']
], ['server_time' => gmdate('c'), 'api_prefix' => SWIM_API_PREFIX]);
