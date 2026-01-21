<?php
/**
 * GDT API Index
 * 
 * Lists available endpoints for the Ground Delay Tools API.
 * 
 * @version 1.0.0
 * @date 2026-01-21
 */

header('Content-Type: application/json; charset=utf-8');

$base_url = '/api/gdt';

$endpoints = [
    'programs' => [
        'create' => [
            'method' => 'POST',
            'path' => $base_url . '/programs/create.php',
            'description' => 'Create a new GS/GDP/AFP program'
        ],
        'list' => [
            'method' => 'GET',
            'path' => $base_url . '/programs/list.php',
            'description' => 'List programs with optional filtering'
        ],
        'get' => [
            'method' => 'GET',
            'path' => $base_url . '/programs/get.php?program_id={id}',
            'description' => 'Get single program with slots and counts'
        ],
        'simulate' => [
            'method' => 'POST',
            'path' => $base_url . '/programs/simulate.php',
            'description' => 'Generate slots and run RBS assignment'
        ],
        'activate' => [
            'method' => 'POST',
            'path' => $base_url . '/programs/activate.php',
            'description' => 'Activate a proposed/modeled program'
        ],
        'extend' => [
            'method' => 'POST',
            'path' => $base_url . '/programs/extend.php',
            'description' => 'Extend program end time'
        ],
        'purge' => [
            'method' => 'POST',
            'path' => $base_url . '/programs/purge.php',
            'description' => 'Cancel/purge a program'
        ],
        'transition' => [
            'method' => 'POST',
            'path' => $base_url . '/programs/transition.php',
            'description' => 'Transition GS to GDP'
        ]
    ],
    'flights' => [
        'list' => [
            'method' => 'GET',
            'path' => $base_url . '/flights/list.php?program_id={id}',
            'description' => 'List flights assigned to a program'
        ]
    ],
    'slots' => [
        'list' => [
            'method' => 'GET',
            'path' => $base_url . '/slots/list.php?program_id={id}',
            'description' => 'List slots for a program'
        ]
    ],
    'demand' => [
        'hourly' => [
            'method' => 'GET',
            'path' => $base_url . '/demand/hourly.php?program_id={id}',
            'description' => 'Get hourly demand/capacity data'
        ]
    ]
];

echo json_encode([
    'api' => 'GDT - Ground Delay Tools',
    'version' => '1.0.0',
    'database' => 'VATSIM_TMI',
    'endpoints' => $endpoints
], JSON_PRETTY_PRINT);
