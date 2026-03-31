<?php
/**
 * VATSWIM API v1 - Index/Router
 * @version 1.0.0
 */

require_once __DIR__ . '/auth.php';

SwimResponse::handlePreflight();

SwimResponse::success([
    'name' => 'VATSWIM API',
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
        'reference' => [
            'GET /api/swim/v1/reference/taxi-times' => 'Airport unimpeded taxi-out reference times (FAA ASPM methodology)',
            'GET /api/swim/v1/reference/taxi-times/{airport}' => 'Taxi-out reference with dimensional breakdown (weight class, carrier, engine config)'
        ],
        'splits' => [
            'GET /api/swim/v1/splits/active' => 'Active split configurations (all facilities)',
            'GET /api/swim/v1/splits/facility' => 'Active splits for a specific facility (?facility=ZNY)',
            'GET /api/swim/v1/splits/configs' => 'All saved configurations (any status)',
            'GET /api/swim/v1/splits/presets' => 'Reusable preset templates',
            'GET /api/swim/v1/splits/areas' => 'Predefined sector area groupings',
            'GET /api/swim/v1/splits/history' => 'Recent split state transitions',
            'POST /api/swim/v1/splits/ingest' => 'Push split configuration from external tool'
        ],
        'configuration' => [
            'GET /api/swim/v1/fea' => 'Flow Evaluation Areas'
        ],
        'routes' => [
            'POST /api/swim/v1/routes/query' => 'Unified route query — ranked suggestions from playbook, CDR, and historical data',
            'GET /api/swim/v1/routes/query' => 'Simple city-pair route lookup (shorthand)',
            'GET /api/swim/v1/routes/cdrs' => 'Coded departure routes (CDR) catalog',
            'GET /api/swim/v1/routes/resolve' => 'Route string resolution via PostGIS (waypoints, geometry)',
            'POST /api/swim/v1/routes/resolve' => 'Batch route resolution (up to 50 routes)',
        ],
        'playbook' => [
            'GET /api/swim/v1/playbook/plays' => 'Playbook plays and routes (with optional geometry)',
            'GET /api/swim/v1/playbook/analysis' => 'Route analysis (distance, traversal, timing)',
            'GET /api/swim/v1/playbook/traversal' => 'Route traversal data',
            'GET /api/swim/v1/playbook/throughput' => 'Route throughput metrics (CTP)',
            'GET /api/swim/v1/playbook/facility-counts' => 'Aggregated facility route statistics',
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
