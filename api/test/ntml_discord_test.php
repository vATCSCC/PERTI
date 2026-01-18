<?php
/**
 * NTML/Advisory Discord Format Test Endpoint v2.0
 *
 * Comprehensive format validation testing ALL message types against
 * real-world patterns from NTML_2020.txt and ADVZY_2020.txt
 *
 * Usage: 
 *   GET https://perti.vatcscc.org/api/test/ntml_discord_test.php?key=perti-ntml-test-2026
 *   GET https://perti.vatcscc.org/api/test/ntml_discord_test.php?key=perti-ntml-test-2026&type=ntml
 *   GET https://perti.vatcscc.org/api/test/ntml_discord_test.php?key=perti-ntml-test-2026&type=advisory
 *   GET https://perti.vatcscc.org/api/test/ntml_discord_test.php?key=perti-ntml-test-2026&format=text
 *
 * @version 2.0.0
 * @author HP/Claude - Jan 2026
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

define('TEST_VERSION', '2.0.0');
define('TEST_API_KEY', 'perti-ntml-test-2026');

// If just checking version
if (isset($_GET['version'])) {
    echo json_encode(['version' => TEST_VERSION, 'time' => gmdate('Y-m-d H:i:s')]);
    exit;
}

// Validate API key
$providedKey = $_SERVER['HTTP_X_TEST_KEY'] ?? $_GET['key'] ?? '';
if ($providedKey !== TEST_API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing API key. Use ?key=perti-ntml-test-2026']);
    exit;
}

// Load main config (contains Discord credentials)
require_once __DIR__ . '/../../load/config.php';

// Load Discord classes
require_once __DIR__ . '/../../load/discord/DiscordAPI.php';
require_once __DIR__ . '/../../load/discord/TMIDiscord.php';

// Initialize Discord
$discord = new DiscordAPI();
$tmi = new TMIDiscord($discord);

if (!$discord->isConfigured()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Discord not configured']);
    exit;
}

// Get test type and output format from query
$testType = $_GET['type'] ?? 'all';
$outputFormat = $_GET['format'] ?? 'discord';  // 'discord' or 'text'

$results = [];
$passed = 0;
$failed = 0;
$pause = 1;  // seconds between messages

// ============================================
// NTML TEST CASES - Based on NTML_2020.txt patterns
// ============================================
$ntmlTests = [
    // -----------------------------------------
    // MIT ENTRIES
    // Real example: "18/0011    BOS via MERIT 15MIT VOLUME:VOLUME EXCL:NONE 2345-0000 ZBW:ZNY"
    // -----------------------------------------
    [
        'name' => '01. MIT via single fix (standard)',
        'data' => [
            'entry_type' => 'MIT',
            'airport' => 'BOS',
            'fix' => 'MERIT',
            'restriction_value' => '15',
            'flow_type' => 'arrivals',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+2 hours')),
            'requesting_facility' => 'ZBW',
            'providing_facility' => 'ZNY'
        ],
        'expected_pattern' => '/^\d{2}\/\d{4}\s+BOS.*via MERIT.*15MIT.*VOLUME:VOLUME.*EXCL:NONE.*\d{4}-\d{4}.*ZBW:ZNY$/'
    ],
    // Real: "21/2325   PHX via SCOLE, HOGGZ 30 MIT PER STREAM EXCL:PROPS VOLUME:VOLUME 0030-0400 ZAB:ZLA"
    [
        'name' => '02. MIT with PER STREAM qualifier',
        'data' => [
            'entry_type' => 'MIT',
            'airport' => 'PHX',
            'fix' => 'SCOLE, HOGGZ',
            'restriction_value' => '30',
            'flow_type' => 'arrivals',
            'qualifiers' => 'PER_STREAM',
            'reason_code' => 'VOLUME',
            'exclusions' => 'PROPS',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+4 hours')),
            'requesting_facility' => 'ZAB',
            'providing_facility' => 'ZLA'
        ]
    ],
    // Real: "22/1445   BOS via ROBUC 25 MIT AS ONE EXCL:NONE VOLUME:VOLUME 1500-2000 ZBW:N90"
    [
        'name' => '03. MIT with AS ONE qualifier',
        'data' => [
            'entry_type' => 'MIT',
            'airport' => 'BOS',
            'fix' => 'MERIT',
            'restriction_value' => '35',
            'flow_type' => 'arrivals',
            'qualifiers' => 'AS_ONE',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+5 hours')),
            'requesting_facility' => 'ZBW',
            'providing_facility' => 'N90'
        ]
    ],
    // Real: "24/2313    MIA 30MIT PER AIRPORT VOLUME:VOLUME EXCL:NONE 2300-0400 ZNY:N90,PHL,EWR,JFK,LGA,ISP"
    [
        'name' => '04. MIT PER AIRPORT multi-provider',
        'data' => [
            'entry_type' => 'MIT',
            'airport' => 'MIA',
            'restriction_value' => '30',
            'flow_type' => 'arrivals',
            'qualifiers' => 'PER_AIRPORT',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+5 hours')),
            'requesting_facility' => 'ZNY',
            'providing_facility' => 'N90,PHL,EWR,JFK,LGA,ISP'
        ]
    ],
    // Real: "22/0128   PHX via HOMRR 15 MIT AFTER DAL125   VOLUME:VOLUME   0128-0400   P50:ZAB"
    [
        'name' => '05. MIT AFTER specific callsign',
        'data' => [
            'entry_type' => 'MIT',
            'airport' => 'PHX',
            'fix' => 'HOMRR',
            'restriction_value' => '15',
            'flow_type' => 'arrivals',
            'qualifiers' => 'AFTER_DAL125',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+3 hours')),
            'requesting_facility' => 'P50',
            'providing_facility' => 'ZAB'
        ]
    ],
    // Real: "18/2355    ATL via JAX DEPARTURES  ALT:AOB300 VOLUME:VOLUME EXCL:NONE 0000-0400 ZTL:ZJX"
    [
        'name' => '06. MIT DEPARTURES with altitude',
        'data' => [
            'entry_type' => 'MIT',
            'airport' => 'ATL',
            'fix' => 'JAX',
            'restriction_value' => '30',
            'flow_type' => 'departures',
            'altitude' => 'FL300',
            'alt_type' => 'AOB',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+4 hours')),
            'requesting_facility' => 'ZTL',
            'providing_facility' => 'ZJX'
        ]
    ],
    
    // -----------------------------------------
    // MINIT ENTRIES
    // Real: "17/2350    BOS 8MINIT VOLUME:VOLUME EXCL:NONE 2330-0300 ZBW:CZY"
    // -----------------------------------------
    [
        'name' => '07. MINIT basic',
        'data' => [
            'entry_type' => 'MINIT',
            'airport' => 'BOS',
            'restriction_value' => '8',
            'flow_type' => 'arrivals',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+3 hours')),
            'requesting_facility' => 'ZBW',
            'providing_facility' => 'CZY'
        ]
    ],
    // Real: "22/1502    EWR via MACOR 3 MINIT EXCL:NONE VOLUME:VOLUME 1500-2000 ZWY:ZSU"
    [
        'name' => '08. MINIT via fix small value',
        'data' => [
            'entry_type' => 'MINIT',
            'airport' => 'EWR',
            'fix' => 'MACOR',
            'restriction_value' => '3',
            'flow_type' => 'arrivals',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+5 hours')),
            'requesting_facility' => 'ZWY',
            'providing_facility' => 'ZSU'
        ]
    ],
    
    // -----------------------------------------
    // STOP ENTRIES
    // Real: "17/2349    BOS STOP VOLUME:VOLUME EXCL:NONE 2345-0015 ZNY:PHL"
    // -----------------------------------------
    [
        'name' => '09. STOP basic',
        'data' => [
            'entry_type' => 'STOP',
            'airport' => 'EWR',
            'flow_type' => 'arrivals',
            'reason_code' => 'WEATHER',
            'reason_detail' => 'THUNDERSTORMS',
            'exclusions' => 'LIFEGUARD,MEDEVAC',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+30 minutes')),
            'requesting_facility' => 'ZNY',
            'providing_facility' => 'N90'
        ]
    ],
    // Real: "25/0114    MIA STOP RUNWAY:CONFIG CHG EXCL:NONE 0115-0130 ZMA:ZJX"
    [
        'name' => '10. STOP runway config change',
        'data' => [
            'entry_type' => 'STOP',
            'airport' => 'MIA',
            'flow_type' => 'arrivals',
            'reason_code' => 'RUNWAY',
            'reason_detail' => 'CONFIG CHG',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+15 minutes')),
            'requesting_facility' => 'ZMA',
            'providing_facility' => 'ZJX'
        ]
    ],
    // Real: "22/1519   CLE via TRYBE STOP VOLUME:VOLUME 2200-0400 ZOB:ZYZ"
    [
        'name' => '11. STOP via specific fix',
        'data' => [
            'entry_type' => 'STOP',
            'airport' => 'CLE',
            'fix' => 'TRYBE',
            'flow_type' => 'arrivals',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+6 hours')),
            'requesting_facility' => 'ZOB',
            'providing_facility' => 'ZYZ'
        ]
    ],
    
    // -----------------------------------------
    // DELAY ENTRIES (D/D, E/D, A/D)
    // -----------------------------------------
    // Real: "18/0010     D/D from JFK, +45/0010 VOLUME:VOLUME"
    [
        'name' => '12. D/D Departure Delay increasing',
        'data' => [
            'entry_type' => 'DELAY',
            'delay_type' => 'D/D',
            'delay_facility' => 'JFK',
            'longest_delay' => '45',
            'delay_trend' => 'increasing',
            'report_time' => gmdate('Hi'),
            'flights_delayed' => '8',
            'reason_code' => 'VOLUME'
        ]
    ],
    // Real: "18/0108     D/D from JFK, -30/0108 VOLUME:VOLUME"
    [
        'name' => '13. D/D Departure Delay decreasing',
        'data' => [
            'entry_type' => 'DELAY',
            'delay_type' => 'D/D',
            'delay_facility' => 'JFK',
            'longest_delay' => '30',
            'delay_trend' => 'decreasing',
            'report_time' => gmdate('Hi'),
            'flights_delayed' => '5',
            'reason_code' => 'VOLUME'
        ]
    ],
    // Real: "21/0151   D/D from SOCAL +30/0151   VOLUME:VOLUME"
    [
        'name' => '14. D/D from region',
        'data' => [
            'entry_type' => 'DELAY',
            'delay_type' => 'D/D',
            'delay_facility' => 'SOCAL',
            'longest_delay' => '30',
            'delay_trend' => 'increasing',
            'report_time' => gmdate('Hi'),
            'flights_delayed' => '12',
            'reason_code' => 'VOLUME'
        ]
    ],
    // Real: "18/0019    ZDC E/D for BOS, +30/0019/13 ACFT VOLUME:VOLUME"
    [
        'name' => '15. E/D En Route Delay',
        'data' => [
            'entry_type' => 'DELAY',
            'delay_type' => 'E/D',
            'reporting_facility' => 'ZDC',
            'delay_facility' => 'BOS',
            'longest_delay' => '30',
            'delay_trend' => 'increasing',
            'report_time' => gmdate('Hi'),
            'flights_delayed' => '13',
            'reason_code' => 'VOLUME'
        ]
    ],
    // Real: "18/0042    ZDC E/D for BOS, -Holding/0042/13 ACFT VOLUME:VOLUME"
    [
        'name' => '16. E/D with Holding',
        'data' => [
            'entry_type' => 'DELAY',
            'delay_type' => 'E/D',
            'reporting_facility' => 'ZDC',
            'delay_facility' => 'BOS',
            'holding' => 'yes',
            'delay_trend' => 'decreasing',
            'report_time' => gmdate('Hi'),
            'flights_delayed' => '13',
            'reason_code' => 'VOLUME'
        ]
    ],
    // Real: "25/0059    ZJX66 A/D to MIA, +Holding/0058 NAVAID:OMN STREAM VOLUME:VOLUME"
    [
        'name' => '17. A/D Arrival Delay',
        'data' => [
            'entry_type' => 'DELAY',
            'delay_type' => 'A/D',
            'reporting_facility' => 'ZJX66',
            'delay_facility' => 'MIA',
            'holding' => 'yes_initiating',
            'delay_trend' => 'initiating',
            'report_time' => gmdate('Hi'),
            'flights_delayed' => '0',
            'fix' => 'OMN',
            'stream' => 'STREAM',
            'reason_code' => 'VOLUME'
        ]
    ],
    
    // -----------------------------------------
    // CONFIG ENTRIES
    // Real: "18/2221    ATL    VMC    ARR:26R/27L/28 DEP:26L/27R    AAR(Strat):132    ADR:70"
    // -----------------------------------------
    [
        'name' => '18. CONFIG VMC full',
        'data' => [
            'entry_type' => 'CONFIG',
            'airport' => 'ATL',
            'weather' => 'VMC',
            'arr_runways' => '26R/27L/28',
            'dep_runways' => '26L/27R',
            'aar' => '132',
            'aar_type' => 'Strat',
            'adr' => '70'
        ]
    ],
    // Real: "24/1851    MIA    VMC    ARR:09/12 DEP:08L/08R    AAR(Dyn):66 AAR Adjustment:OTHER    ADR:72"
    [
        'name' => '19. CONFIG with AAR adjustment',
        'data' => [
            'entry_type' => 'CONFIG',
            'airport' => 'MIA',
            'weather' => 'VMC',
            'arr_runways' => '09/12',
            'dep_runways' => '08L/08R',
            'aar' => '66',
            'aar_type' => 'Dyn',
            'aar_adjustment' => 'OTHER',
            'adr' => '72'
        ]
    ],
    // Real: "25/0052    MIA    LVMC    ARR:26R/30 DEP:26L/27    AAR(Strat):60    ADR:72"
    [
        'name' => '20. CONFIG LVMC',
        'data' => [
            'entry_type' => 'CONFIG',
            'airport' => 'MIA',
            'weather' => 'LVMC',
            'arr_runways' => '26R/30',
            'dep_runways' => '26L/27',
            'aar' => '60',
            'aar_type' => 'Strat',
            'adr' => '72'
        ]
    ],
    // Real: "22/1594    BOS    VMC    ARR:27+32    DEP:33L    AAR:40 ADR:40"
    [
        'name' => '21. CONFIG with plus notation',
        'data' => [
            'entry_type' => 'CONFIG',
            'airport' => 'BOS',
            'weather' => 'VMC',
            'arr_runways' => '27+32',
            'dep_runways' => '33L',
            'aar' => '40',
            'adr' => '40'
        ]
    ],
    
    // -----------------------------------------
    // OTHER ENTRY TYPES (CFR, TBM, APREQ, DSP)
    // -----------------------------------------
    // Real: "24/2140    CFR MIA,FLL,RSW departures  TYPE:ALL VOLUME:VOLUME EXCL:NONE 2100-0400 ZMA:F11"
    [
        'name' => '22. CFR Multi-airport',
        'data' => [
            'entry_type' => 'CFR',
            'airport' => 'MIA,FLL,RSW',
            'flow_type' => 'departures',
            'aircraft_type' => 'ALL',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+7 hours')),
            'requesting_facility' => 'ZMA',
            'providing_facility' => 'F11'
        ]
    ],
    // Real: "18/2206    ATL TBM 3_WEST VOLUME:VOLUME EXCL:NONE 2230-0400 ZTL:ZJX,ZME,ZID,ZHU"
    [
        'name' => '23. TBM Time-Based Metering',
        'data' => [
            'entry_type' => 'TBM',
            'airport' => 'ATL',
            'tbm_zone' => '3_WEST',
            'flow_type' => 'arrivals',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+6 hours')),
            'requesting_facility' => 'ZTL',
            'providing_facility' => 'ZJX,ZME,ZID,ZHU'
        ]
    ],
    // Real: "18/2338    APREQ ATL departures via BOBZY VOLUME:VOLUME EXCL:NONE 2330-0100 ZTL:CLT"
    [
        'name' => '24. APREQ Approval Request',
        'data' => [
            'entry_type' => 'APREQ',
            'airport' => 'ATL',
            'fix' => 'BOBZY',
            'flow_type' => 'departures',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+2 hours')),
            'requesting_facility' => 'ZTL',
            'providing_facility' => 'CLT'
        ]
    ],
    // Real: "18/0040    CFR BOS departures  VOLUME:VOLUME EXCL:NONE 0045-0300 ZNY:N90,JFK,EWR,LGA,PHL"
    [
        'name' => '25. CFR departures',
        'data' => [
            'entry_type' => 'CFR',
            'airport' => 'BOS',
            'flow_type' => 'departures',
            'reason_code' => 'VOLUME',
            'exclusions' => 'NONE',
            'valid_from' => gmdate('Hi'),
            'valid_until' => gmdate('Hi', strtotime('+3 hours')),
            'requesting_facility' => 'ZNY',
            'providing_facility' => 'N90,JFK,EWR,LGA,PHL'
        ]
    ],
];

// ============================================
// ADVISORY TEST CASES - Based on ADVZY_2020.txt patterns
// ============================================
$advisoryTests = [
    // -----------------------------------------
    // GROUND STOP
    // Real: vATCSCC ADVZY 003 EWR/ZNY 11/03/2025 CDM GROUND STOP
    // -----------------------------------------
    [
        'name' => '01. Ground Stop Advisory (2025 format)',
        'method' => 'postGroundStopAdvisory',
        'data' => [
            'advisory_number' => '001',
            'ctl_element' => 'JFK',
            'artcc' => 'ZNY',
            'adl_time' => gmdate('Hi'),
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+2 hours')),
            'flt_incl' => 'ZNY DEPARTURES TO JFK',
            'dep_facilities' => '(Tier1) ZBW ZDC ZOB',
            'curr_total_delay' => '0',
            'curr_max_delay' => '0',
            'curr_avg_delay' => '0',
            'prev_total_delay' => '0',
            'prev_max_delay' => '0',
            'prev_avg_delay' => '0',
            'new_total_delay' => '90',
            'new_max_delay' => '45',
            'new_avg_delay' => '15',
            'prob_extension' => 'MEDIUM',
            'impacting_condition' => 'VOLUME',
            'condition_text' => 'DEMAND/CAPACITY IMBALANCE',
            'comments' => 'ALTERNATES RECOMMENDED: LGA'
        ]
    ],
    
    // -----------------------------------------
    // GROUND STOP CANCELLATION
    // -----------------------------------------
    [
        'name' => '02. Ground Stop Cancellation',
        'method' => 'postGroundStopCancellation',
        'data' => [
            'advisory_number' => '002',
            'ctl_element' => 'JFK',
            'artcc' => 'ZNY',
            'adl_time' => gmdate('Hi'),
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+2 hours')),
            'comments' => 'WEATHER IMPROVED'
        ]
    ],
    
    // -----------------------------------------
    // GDP
    // -----------------------------------------
    [
        'name' => '03. GDP Advisory (DAS mode)',
        'method' => 'postGDPAdvisory',
        'data' => [
            'advisory_number' => '003',
            'ctl_element' => 'EWR',
            'artcc' => 'ZNY',
            'adl_time' => gmdate('Hi'),
            'delay_mode' => 'DAS',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+5 hours')),
            'program_rate' => '30/28/26/24/22/20',
            'flt_incl' => 'ALL CONTIGUOUS US DEP',
            'dep_scope' => '300NM',
            'delay_limit' => '180',
            'max_delay' => '65',
            'avg_delay' => '32',
            'impacting_condition' => 'VOLUME',
            'condition_text' => 'DEMAND/CAPACITY IMBALANCE',
            'comments' => 'MONITOR FOR EXTENSION'
        ]
    ],
    
    // -----------------------------------------
    // GDP CANCELLATION
    // -----------------------------------------
    [
        'name' => '04. GDP Cancellation',
        'method' => 'postGDPCancellation',
        'data' => [
            'advisory_number' => '004',
            'ctl_element' => 'EWR',
            'artcc' => 'ZNY',
            'adl_time' => gmdate('Hi'),
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+5 hours')),
            'comments' => 'CAPACITY RESTORED'
        ]
    ],
    
    // -----------------------------------------
    // ROUTE RQD (2025 format)
    // -----------------------------------------
    [
        'name' => '05. Route RQD Advisory (2025 format)',
        'method' => 'postRerouteAdvisory',
        'data' => [
            'advisory_number' => '005',
            'facility' => 'DCC',
            'action' => 'RQD',
            'route_type' => 'ROUTE',
            'route_name' => 'N90_TO_BOS',
            'constrained_area' => 'ZBW',
            'reason' => 'VOLUME/VOLUME',
            'include_traffic' => 'KJFK, KLGA, KEWR DEPARTURES TO KBOS',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+4 hours')),
            'valid_type' => 'ETD',
            'facilities' => ['ZNY', 'ZBW'],
            'flight_status' => 'ALL_FLIGHTS',
            'prob_extension' => 'LOW',
            'remarks' => 'NONE',
            'associated_restrictions' => '',
            'modifications' => '',
            'routes' => [
                ['origin' => 'N90', 'dest' => 'BOS', 'route' => '>GAYEL J95 STOMP HNK PONCT< JFUND2']
            ]
        ]
    ],
    
    // -----------------------------------------
    // ROUTE RMD (Recommended)
    // -----------------------------------------
    [
        'name' => '06. Route RMD Advisory (Recommended)',
        'method' => 'postRerouteAdvisory',
        'data' => [
            'advisory_number' => '006',
            'facility' => 'DCC',
            'action' => 'RMD',
            'route_type' => 'ROUTE',
            'route_name' => 'DEN_METROPLEX_ARRIVALS',
            'constrained_area' => 'ZDV',
            'reason' => 'OTHER/OTHER',
            'include_traffic' => 'ZLA/ZAB/ZMP/ZLC/ZKC DEPARTURES TO KDEN',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+5 hours')),
            'valid_type' => 'ETD',
            'facilities' => ['ZAB', 'ZDV', 'ZKC', 'ZLA', 'ZLC', 'ZMP'],
            'flight_status' => 'ALL_FLIGHTS',
            'prob_extension' => 'LOW',
            'remarks' => 'ROUTES FOR METROPLEX IMPLEMENTATION',
            'associated_restrictions' => '',
            'modifications' => '',
            'routes' => [
                ['origin' => 'LAX', 'dest' => 'DEN', 'route' => 'DOTSS2 >CLEEE EED J236 TBC BUMMP< SKKII1'],
                ['origin' => 'SLC', 'dest' => 'DEN', 'route' => 'RUGGD1 >PERTY KAMPR< LONGZ1'],
                ['origin' => 'MCI', 'dest' => 'DEN', 'route' => 'WLDCT5 >SLN J24 OATHE< CLASH1']
            ]
        ]
    ],
    
    // -----------------------------------------
    // ROUTE with /FL (Flight List)
    // -----------------------------------------
    [
        'name' => '07. Route RQD with Flight List',
        'method' => 'postRerouteAdvisory',
        'data' => [
            'advisory_number' => '007',
            'facility' => 'DCC',
            'action' => 'RQD',
            'route_type' => 'ROUTE',
            'has_flight_list' => true,
            'route_name' => 'ZNY_TO_PIT',
            'constrained_area' => 'ZNY',
            'reason' => 'VOLUME',
            'include_traffic' => 'KJFK/KEWR/KLGA/KPHL DEPARTURES TO KPIT',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+4 hours')),
            'valid_type' => 'ETD',
            'facilities' => ['ZNY', 'ZOB'],
            'flight_status' => 'ALL_FLIGHTS',
            'prob_extension' => 'MEDIUM',
            'remarks' => '',
            'associated_restrictions' => 'ZNY REQUESTS AOB FL300',
            'modifications' => '',
            'routes' => [
                ['origin' => 'JFK', 'dest' => 'PIT', 'route' => 'DEEZZ5 >CANDR J60 PSB< HAYNZ6'],
                ['origin' => 'EWR LGA', 'dest' => 'PIT', 'route' => '>NEWEL J60 PSB< HAYNZ6'],
                ['origin' => 'PHL', 'dest' => 'PIT', 'route' => '>PTW SARAA DANNR J60 PSB< HAYNZ6']
            ]
        ]
    ],
    
    // -----------------------------------------
    // REROUTE CANCELLATION
    // -----------------------------------------
    [
        'name' => '08. Reroute Cancellation',
        'method' => 'postRerouteCancellation',
        'data' => [
            'advisory_number' => '008',
            'facility' => 'DCC',
            'route_name' => 'ZNY_TO_PIT',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+4 hours')),
            'cancel_text' => 'ZNY_TO_PIT HAS BEEN CANCELLED'
        ]
    ],
    
    // -----------------------------------------
    // FCA (Flow Constrained Area)
    // -----------------------------------------
    [
        'name' => '09. FCA RQD Advisory',
        'method' => 'postFCAAdvisory',
        'data' => [
            'advisory_number' => '009',
            'facility' => 'DCC',
            'action' => 'RQD',
            'fca_id' => '001',
            'fca_name' => 'SINGLE_STREAM_VIA_FIPEK_JUELE',
            'constrained_area' => 'ZJX',
            'reason' => 'VOLUME',
            'include_traffic' => 'TRAFFIC TRANSITING THE FCA',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+4 hours')),
            'facilities' => ['ZJX', 'ZMA', 'ZTL'],
            'flight_status' => 'ALL_FLIGHTS',
            'prob_extension' => 'LOW',
            'remarks' => '',
            'associated_restrictions' => 'SEE NTML FOR RESTRICTIONS',
            'modifications' => '',
            'routes' => [
                ['origin' => 'ALL', 'dest' => 'MIA', 'route' => 'VIA FCA ENTRY POINTS']
            ]
        ]
    ],
    
    // -----------------------------------------
    // FEA (Flow Evaluation Area) - Similar to FCA
    // -----------------------------------------
    [
        'name' => '10. FEA RMD Advisory',
        'method' => 'postFCAAdvisory',
        'data' => [
            'advisory_number' => '010',
            'facility' => 'DCC',
            'action' => 'RMD',
            'fca_id' => 'WIG',
            'fca_name' => 'NO_WIGOL_ATL',
            'constrained_area' => 'ZDC',
            'reason' => 'VOLUME/VOLUME',
            'include_traffic' => 'KATL DEPARTURES TO KIAD',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+5 hours')),
            'facilities' => ['ZDC', 'ZID', 'ZTL'],
            'flight_status' => 'ALL_FLIGHTS',
            'prob_extension' => 'LOW',
            'remarks' => 'THIS FEA (FLOW EVALUATION AREA) IS AN AREA FOR DCC TO EVALUATE THE TMU IMPACT OF THIS PARTICULAR RESTRICTION',
            'associated_restrictions' => 'SEE NTML FOR RESTRICTIONS',
            'modifications' => '',
            'routes' => [
                ['origin' => 'ATL', 'dest' => 'IAD', 'route' => 'UPT RTE: ZDC REQUEST THAT NO TFC BE ROUTED OVER ZID OR WIGOL']
            ]
        ]
    ],
    
    // -----------------------------------------
    // OPERATIONS PLAN
    // -----------------------------------------
    [
        'name' => '11. Operations Plan',
        'method' => 'postOperationsPlan',
        'data' => [
            'advisory_number' => '011',
            'facility' => 'DCC',
            'event_time' => gmdate('d/Hi') . ' - AND LATER',
            'summary' => 'CROSS THE POND EASTBOUND NON-EVENT OPERATIONS PLAN',
            'staffing_triggers' => ['TMU POSITIONS: OCEANIC, DOMESTIC WEST, DOMESTIC EAST'],
            'terminal_constraints' => null,
            'terminal_active' => null,
            'terminal_planned' => null,
            'enroute_constraints' => ['HIGH ALTITUDE TRAFFIC FLOW ACROSS ATLANTIC'],
            'enroute_active' => null,
            'enroute_planned' => ['TATL ICR IF NEEDED'],
            'cdrs_swap' => null,
            'afp_active' => null,
            'afp_planned' => null,
            'next_webinar' => 'TBD',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+8 hours'))
        ]
    ],
    
    // -----------------------------------------
    // HOTLINE ACTIVATION
    // -----------------------------------------
    [
        'name' => '12. Hotline Activation',
        'method' => 'postHotlineAdvisory',
        'data' => [
            'advisory_number' => '012',
            'facility' => 'DCC',
            'hotline_name' => 'EAST COAST',
            'event_time' => gmdate('d/Hi') . ' - ' . gmdate('d/Hi', strtotime('+4 hours')),
            'constrained_facilities' => 'ZNY/ZDC/ZOB/ZTL',
            'reason' => 'VOLUME IN ZNY/ZDC AREA',
            'location' => 'THE VATUSA TEAMSPEAK, EAST COAST HOTLINE',
            'password' => '',
            'contact' => 'TIMOTHY MAKAROV',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+4 hours'))
        ]
    ],
    
    // -----------------------------------------
    // HOTLINE TERMINATION
    // -----------------------------------------
    [
        'name' => '13. Hotline Termination',
        'method' => 'postHotlineAdvisory',
        'data' => [
            'advisory_number' => '013',
            'facility' => 'DCC',
            'hotline_name' => 'EAST COAST',
            'event_time' => gmdate('d/Hi') . ' - ' . gmdate('d/Hi', strtotime('+4 hours')),
            'constrained_facilities' => 'ZNY/ZDC/ZOB/ZTL',
            'terminated' => true,
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+4 hours'))
        ]
    ],
    
    // -----------------------------------------
    // INFORMATIONAL
    // -----------------------------------------
    [
        'name' => '14. Informational Advisory',
        'method' => 'postInformationalAdvisory',
        'data' => [
            'advisory_number' => '014',
            'facility' => 'DCC',
            'advisory_type' => 'INFORMATIONAL',
            'text' => 'ALL FACILITIES ARE REMINDED TO CHECK NTML FOR ACTIVE TMI RESTRICTIONS BEFORE DEPARTING AIRCRAFT TO CONGESTED AIRPORTS. CONTACT DCC NOM WITH QUESTIONS.',
            'start_utc' => gmdate('Y-m-d H:i:s'),
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime('+12 hours'))
        ]
    ],
];

// ============================================
// RUN TESTS
// ============================================

if ($outputFormat === 'text') {
    header('Content-Type: text/plain');
    
    echo "=== TMI MESSAGE FORMAT TEST ===\n";
    echo "Version: " . TEST_VERSION . "\n";
    echo "Time: " . gmdate('Y-m-d H:i:s') . " UTC\n";
    echo str_repeat('=', 68) . "\n\n";
    
    if ($testType === 'all' || $testType === 'ntml') {
        echo "=== NTML ENTRIES ===\n\n";
        foreach ($ntmlTests as $test) {
            echo "--- {$test['name']} ---\n";
            echo "Data: " . json_encode($test['data'], JSON_PRETTY_PRINT) . "\n\n";
        }
    }
    
    if ($testType === 'all' || $testType === 'advisory') {
        echo "\n=== ADVISORY MESSAGES ===\n\n";
        foreach ($advisoryTests as $test) {
            echo "--- {$test['name']} ---\n";
            echo "Method: {$test['method']}\n";
            echo "Data: " . json_encode($test['data'], JSON_PRETTY_PRINT) . "\n\n";
        }
    }
    
    exit;
}

// Post header to Discord
$headerResult = $discord->createMessage('ntml_staging', [
    'content' => "```\n=== TMI FORMAT TEST v" . TEST_VERSION . " ===\n" .
                 "Time: " . gmdate('Y-m-d H:i:s') . " UTC\n" .
                 "Type: {$testType}\n" .
                 "Triggered via API test endpoint\n```"
]);

if (!$headerResult) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Failed to post to Discord: ' . $discord->getLastError(),
        'http_code' => $discord->getLastHttpCode()
    ]);
    exit;
}

$results['header'] = ['success' => true, 'message_id' => $headerResult['id'] ?? null];
sleep($pause);

// Run NTML tests
if ($testType === 'all' || $testType === 'ntml') {
    foreach ($ntmlTests as $test) {
        $result = $tmi->postNtmlEntry($test['data'], 'ntml_staging');
        
        $testResult = [
            'name' => $test['name'],
            'success' => ($result && isset($result['id'])),
            'message_id' => $result['id'] ?? null,
            'error' => $result ? null : $discord->getLastError()
        ];
        
        if ($testResult['success']) {
            $passed++;
        } else {
            $failed++;
        }
        
        $results['ntml'][] = $testResult;
        sleep($pause);
    }
}

// Run Advisory tests
if ($testType === 'all' || $testType === 'advisory') {
    foreach ($advisoryTests as $test) {
        $method = $test['method'];
        $result = $tmi->$method($test['data'], 'advzy_staging');
        
        $testResult = [
            'name' => $test['name'],
            'method' => $method,
            'success' => ($result && isset($result['id'])),
            'message_id' => $result['id'] ?? null,
            'error' => $result ? null : $discord->getLastError()
        ];
        
        if ($testResult['success']) {
            $passed++;
        } else {
            $failed++;
        }
        
        $results['advisory'][] = $testResult;
        sleep($pause);
    }
}

// Return results
echo json_encode([
    'success' => $failed === 0,
    'version' => TEST_VERSION,
    'summary' => [
        'passed' => $passed,
        'failed' => $failed,
        'total' => $passed + $failed
    ],
    'results' => $results,
    'timestamp' => gmdate('Y-m-d H:i:s') . ' UTC',
    'channels' => [
        'ntml' => '#ntml-staging',
        'advisory' => '#advzy-staging'
    ]
], JSON_PRETTY_PRINT);
