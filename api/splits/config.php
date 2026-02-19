<?php
/**
 * Airspace Splits Configuration
 * 
 * Adjust these settings for your environment.
 */

// CRC Extraction Path (for VideoMaps GeoJSON files)
// This is where the full CRC pack has been extracted
define('SPLITS_CRC_BASE_PATH', '/mnt/data/CRC_extracted');

// ARTCC JSON Metadata Path
// These files contain videoMap listings (included in wwwroot/data/ARTCCs/)
define('SPLITS_CRC_ARTCC_PATH', __DIR__ . '/../../data/ARTCCs');

// CRC Index SQLite Database Path (optional, for faster lookups)
define('SPLITS_CRC_INDEX_PATH', '/mnt/data/crc_index.sqlite');

// Fallback GeoJSON directory for custom sector data
define('SPLITS_FALLBACK_PATH', __DIR__ . '/../../assets/geojson/sectors');

// Enable sample/demo data when CRC VideoMaps are not available
define('SPLITS_ENABLE_DEMO', true);

// ARTCC List (US)
define('SPLITS_ARTCC_LIST', [
    'ZAB' => 'Albuquerque',
    'ZAN' => 'Anchorage',
    'ZAU' => 'Chicago',
    'ZBW' => 'Boston',
    'ZDC' => 'Washington',
    'ZDV' => 'Denver',
    'ZFW' => 'Fort Worth',
    'ZHN' => 'Honolulu',
    'ZHU' => 'Houston',
    'ZID' => 'Indianapolis',
    'ZJX' => 'Jacksonville',
    'ZKC' => 'Kansas City',
    'ZLA' => 'Los Angeles',
    'ZLC' => 'Salt Lake City',
    'ZMA' => 'Miami',
    'ZME' => 'Memphis',
    'ZMP' => 'Minneapolis',
    'ZNY' => 'New York',
    'ZOA' => 'Oakland',
    'ZOB' => 'Cleveland',
    'ZSE' => 'Seattle',
    'ZTL' => 'Atlanta',
    'ZUA' => 'Guam'
]);

// FIR List (International)
define('SPLITS_FIR_LIST', [
    // Canada
    'CZEG' => 'Edmonton',
    'CZUL' => 'Montreal',
    'CZWG' => 'Winnipeg',
    'CZVR' => 'Vancouver',
    'CZYZ' => 'Toronto',
    // Mexico
    'MMFR' => 'Mexico (North)',
    'MMID' => 'Mazatlán',
    'MMFO' => 'Mexico (South)',
    // Caribbean
    'TJZS' => 'San Juan',
    'MDCS' => 'Santo Domingo',
    'MKJK' => 'Kingston',
    'MUFH' => 'Havana',
    'TTZP' => 'Piarco'
]);

// Facility visibility centers (for initial map positioning)
define('SPLITS_FACILITY_CENTERS', [
    'ZAB' => [-106.52, 33.59],
    'ZAN' => [-149.99, 61.17],
    'ZAU' => [-88.03, 41.70],
    'ZBW' => [-71.00, 42.36],
    'ZDC' => [-77.44, 38.85],
    'ZDV' => [-104.67, 39.58],
    'ZFW' => [-97.04, 32.90],
    'ZHN' => [-157.92, 21.32],
    'ZHU' => [-95.34, 29.98],
    'ZID' => [-86.29, 39.72],
    'ZJX' => [-81.69, 30.49],
    'ZKC' => [-94.72, 39.30],
    'ZLA' => [-118.41, 33.94],
    'ZLC' => [-111.97, 40.79],
    'ZMA' => [-80.29, 25.79],
    'ZME' => [-89.98, 35.04],
    'ZMP' => [-93.22, 44.88],
    'ZNY' => [-73.78, 40.64],
    'ZOA' => [-122.22, 37.62],
    'ZOB' => [-81.85, 41.41],
    'ZSE' => [-122.31, 47.45],
    'ZTL' => [-84.43, 33.64],
    'ZUA' => [144.80, 13.48],
    // Caribbean FIRs
    'TJZS' => [-66.00, 18.43],
]);
