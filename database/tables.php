<?php

/**
 * FORMAT:
 * [
 *  row_name:type:length (optional),
 * ]
 */

$tables = [
    [
        "name" => "users",
        "columns" => [
            "cid:int:12",
            "first_name:varchar:64",
            "last_name:varchar:64",
            "last_session_ip:varchar:120",
            "last_selfcookie:varchar:120"
        ],
    ],
    [
        "name" => "config_data",
        "columns" => [
            "airport:varchar:4",
            "arr:varchar:32",
            "dep:varchar:32",
            "vmc_aar:varchar:3",
            "lvmc_aar:varchar:3",
            "imc_aar:varchar:3",
            "limc_aar:varchar:3",
            "vmc_adr:varchar:3",
            "imc_adr:varchar:3"
        ],
    ],
    [
        "name" => "p_plans",
        "columns" => [
            "event_name:varchar:128",
            "event_date:varchar:12",
            "event_start:varchar:4",
            "event_banner:varchar:512",
            "oplevel:int:1",
            "hotline:varchar:64",
        ],
    ],
    [
        "name" => "p_dcc_staffing",
        "columns" => [
            "p_id:int:12",
            "position_name:varchar:64",
            "position_facility:varchar:16",
            "personnel_name:varchar:128",
            "personnel_ois:varchar:2"
        ],
    ],
    [
        "name" => "p_forecast",
        "columns" => [
            "p_id:int:12",
            "date:varchar:16",
            "summary:text",
            "image_url:varchar:512",
        ],
    ],
    [
        "name" => "p_historical",
        "columns" => [
            "p_id:int:12",
            "title:varchar:64",
            "date:varchar:12",
            "summary:text",
            "image_url:varchar:512",
            "source_url:varchar:512",
        ],
    ],
    [
        "name" => "p_configs",
        "columns" => [
            "p_id:int:12",
            "airport:varchar:4",
            "weather:int:1", // 0 = Unknown, 1 = VMC, 2 = LVMC, 3 = IMC, 4 = LIMC
            "arrive:varchar:16",
            "depart:varchar:16",
            "aar:varchar:3",
            "adr:varchar:3",
            "comments:varchar:64"
        ],
    ],
    [
        "name" => "p_terminal_init",
        "columns" => [
            "p_id:int:12",
            "title:varchar:256",
            "context:varchar:64",
        ],
    ],
    [
        "name" => "p_terminal_init_times",
        "columns" => [
            "init_id:int:12",
            "time:varchar:4",
            "probability:int:1" // 0 = CDW, 1 = Possible, 2 = Probable, 3 = Expected, 4 = Actual
        ],
    ],
    [
        "name" => "p_terminal_staffing",
        "columns" => [
            "p_id:int:12",
            "facility_name:varchar:64",
            "staffing_status:int:1", // 0 = Unknown, 1 = Top Down, 2 = Yes, 3 = Understaffed, 4 = No
            "staffing_quantity:int:2",
            "comments:varchar:64",
        ],
    ],
    [
        "name" => "p_terminal_planning",
        "columns" => [
            "p_id:int:12",
            "facility_name:varchar:64",
            "comments:text",
        ],
    ],
    [
        "name" => "p_terminal_constraints",
        "columns" => [
            "p_id:int:12",
            "location:varchar:64",
            "context:varchar:64",
            "date:varchar:64",
            "impact:varchar:64",
        ],
    ],
    [
        "name" => "p_enroute_init",
        "columns" => [
            "p_id:int:12",
            "title:varchar:256",
            "context:varchar:64",
        ],
    ],
    [
        "name" => "p_enroute_init_times",
        "columns" => [
            "init_id:int:12",
            "time:varchar:4",
            "probability:int:1" // 0 = CDW, 1 = Possible, 2 = Probable, 3 = Expected, 4 = Actual
        ],
    ],
    [
        "name" => "p_enroute_staffing",
        "columns" => [
            "p_id:int:12",
            "facility_name:varchar:64",
            "staffing_status:int:1", // 0 = Unknown, 1 = Yes, 2 = Understaffed, 3 = No
            "staffing_quantity:int:2",
            "comments:varchar:64",
        ],
    ],
    [
        "name" => "p_enroute_planning",
        "columns" => [
            "p_id:int:12",
            "facility_name:varchar:64",
            "comments:text",
        ],
    ],
    [
        "name" => "p_enroute_constraints",
        "columns" => [
            "p_id:int:12",
            "location:varchar:64",
            "context:varchar:64",
            "date:varchar:64",
            "impact:varchar:64",
        ],
    ],
    [
        "name" => "p_group_flights",
        "columns" => [
            "p_id:int:12",
            "entity:varchar:64",
            "dep:varchar:4",
            "arr:varchar:4",
            "etd:varchar:4",
            "eta:varchar:4",
            "pilot_quantity:int:3",
            "route:varchar:512",
        ],
    ],
    [
        "name" => "p_op_goals",
        "columns" => [
            "p_id:int:12",
            "comments:text",
        ],
    ],
    [
        "name" => "r_scores",
        "columns" => [
            "p_id:int:12",
            "staffing:int:1",
            "tactical:int:1",
            "other:int:1",
            "perti:int:1",
            "ntml:int:1",
            "tmi:int:1",
            "ace:int:1"
        ], 
    ],
    [
        "name" => "r_comments",
        "columns" => [
            "p_id:int:12",
            "staffing:text",
            "tactical:text",
            "other:text",
            "perti:text",
            "ntml:text",
            "tmi:text",
            "ace:text"
        ], 
    ],
    [
        "name" => "r_data",
        "columns" => [
            "p_id:int:12",
            "summary:text",
            "image_url:varchar:512",
            "source_url:varchar:512",
        ],
    ],   
    [
        "name" => "assigned",
        "columns" => [
            "e_id:int:12",
            "e_title:varchar:256",
            "e_date:varchar:16",
            "p_cid:int:12",
            "e_cid:int:12",
            "r_cid:int:12",
            "t_cid:int:12",
            "i_cid:int:12",
        ]
    ] 
];
