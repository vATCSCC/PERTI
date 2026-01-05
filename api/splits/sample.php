<?php
/**
 * Sample Sector Data
 * 
 * Provides demo sector boundaries for testing when CRC data is unavailable.
 * Returns simplified sector polygons for common ARTCCs.
 * 
 * Can be used standalone (outputs JSON) or included for the generateSampleSectors function.
 */

if (!defined('SPLITS_FACILITY_CENTERS')) {
    require_once __DIR__ . '/config.php';
}

// Only run as standalone if directly accessed
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'sample.php') {
    header('Content-Type: application/json');
    
    $facility = strtoupper($_GET['facility'] ?? '');
    
    if (!$facility) {
        echo json_encode(['error' => 'Missing facility']);
        exit;
    }
    
    // Generate sample sectors based on facility
    $sectors = generateSampleSectors($facility);
    $bounds = calculateSampleBounds($sectors);
    $center = SPLITS_FACILITY_CENTERS[$facility] ?? [-98, 39];
    
    echo json_encode([
        'success' => true,
        'facility' => $facility,
        'demo' => true,
        'sectors' => $sectors,
        'bounds' => $bounds,
        'center' => $center,
        'count' => count($sectors)
    ]);
    exit;
}

/**
 * Generate sample sector data
 * Creates a grid of sectors around the facility center
 */
function generateSampleSectors($facility) {
    $center = SPLITS_FACILITY_CENTERS[$facility] ?? [-98, 39];
    $sectors = [];
    
    // Sample sector configurations by facility
    // These are based on real ARTCC sector structures
    $configs = [
        'ZOB' => [
            // Cleveland sectors - grouped by area
            ['id' => 'ZOB11', 'name' => 'Lorain 11', 'freq' => '132.350', 'area' => 'LORAIN', 'offset' => [-0.8, 0.5]],
            ['id' => 'ZOB12', 'name' => 'Lorain 12', 'freq' => '132.350', 'area' => 'LORAIN', 'offset' => [-0.3, 0.6]],
            ['id' => 'ZOB13', 'name' => 'Lorain 13', 'freq' => '132.350', 'area' => 'LORAIN', 'offset' => [0.2, 0.5]],
            ['id' => 'ZOB14', 'name' => 'Mansfield 14', 'freq' => '134.100', 'area' => 'MANSFIELD', 'offset' => [-0.5, 0.0]],
            ['id' => 'ZOB28', 'name' => 'Erie 28', 'freq' => '135.725', 'area' => 'ERIE', 'offset' => [-0.2, 0.9]],
            ['id' => 'ZOB29', 'name' => 'Erie 29', 'freq' => '135.725', 'area' => 'ERIE', 'offset' => [0.3, 0.85]],
            ['id' => 'ZOB30', 'name' => 'Akron 30', 'freq' => '125.025', 'area' => 'AKRON', 'offset' => [0.1, 0.1]],
            ['id' => 'ZOB48', 'name' => 'Cleveland 48', 'freq' => '119.875', 'area' => 'CLEVELAND', 'offset' => [0.0, 0.4]],
            ['id' => 'ZOB66', 'name' => 'Briggs 66', 'freq' => '125.425', 'area' => 'BRIGGS', 'offset' => [0.5, 0.2]],
            ['id' => 'ZOB72', 'name' => 'Lorain 72', 'freq' => '132.350', 'area' => 'LORAIN', 'offset' => [-0.6, 0.3]],
            ['id' => 'ZOB80', 'name' => 'Dryer 80', 'freq' => '128.200', 'area' => 'DRYER', 'offset' => [0.6, -0.1]],
            ['id' => 'ZOB37', 'name' => 'Detroit 37', 'freq' => '127.850', 'area' => 'DETROIT', 'offset' => [-0.4, 0.7]],
            ['id' => 'ZOB49', 'name' => 'Toledo 49', 'freq' => '133.475', 'area' => 'TOLEDO', 'offset' => [-0.7, 0.2]],
        ],
        'ZDV' => [
            // Denver sectors - HIGH and LOW areas
            ['id' => 'ZDV17', 'name' => 'Denver High 17', 'freq' => '127.650', 'area' => 'DEN-H', 'offset' => [0.0, 0.3]],
            ['id' => 'ZDV25', 'name' => 'Hubbard High 25', 'freq' => '133.525', 'area' => 'HBU-H', 'offset' => [-0.6, -0.2]],
            ['id' => 'ZDV03', 'name' => 'Pueblo High 03', 'freq' => '127.650', 'area' => 'PUB-H', 'offset' => [0.0, -0.4]],
            ['id' => 'ZDV04', 'name' => 'Hubbard 04', 'freq' => '133.525', 'area' => 'HBU-H', 'offset' => [-0.5, 0.1]],
            ['id' => 'ZDV05', 'name' => 'Denver 05', 'freq' => '127.650', 'area' => 'DEN-H', 'offset' => [0.3, 0.5]],
            ['id' => 'ZDV06', 'name' => 'Cheyenne 06', 'freq' => '133.525', 'area' => 'CHE-H', 'offset' => [0.1, 0.7]],
            ['id' => 'ZDV02', 'name' => 'Hillrose 02', 'freq' => '127.650', 'area' => 'HLC-H', 'offset' => [0.4, 0.3]],
        ],
        'ZNY' => [
            // New York sectors
            ['id' => 'ZNY10', 'name' => 'Yardley 10', 'freq' => '128.300', 'area' => 'YARDLEY', 'offset' => [-0.4, -0.2]],
            ['id' => 'ZNY12', 'name' => 'Yardley 12', 'freq' => '128.300', 'area' => 'YARDLEY', 'offset' => [-0.2, -0.1]],
            ['id' => 'ZNY17', 'name' => 'Haays 17', 'freq' => '125.325', 'area' => 'HAAYS', 'offset' => [0.2, 0.3]],
            ['id' => 'ZNY56', 'name' => 'Hampton 56', 'freq' => '134.350', 'area' => 'HAMPTON', 'offset' => [0.5, 0.0]],
            ['id' => 'ZNY66', 'name' => 'Hampton 66', 'freq' => '132.775', 'area' => 'HAMPTON', 'offset' => [0.5, 0.1]],
            ['id' => 'ZNY68', 'name' => 'Hampton 68', 'freq' => '132.775', 'area' => 'HAMPTON', 'offset' => [0.7, 0.2]],
            ['id' => 'ZNY52', 'name' => 'Kennedy 52', 'freq' => '128.125', 'area' => 'KENNEDY', 'offset' => [0.3, -0.1]],
            ['id' => 'ZNY42', 'name' => 'Sparta 42', 'freq' => '127.850', 'area' => 'SPARTA', 'offset' => [-0.1, 0.5]],
        ],
        'ZID' => [
            // Indianapolis sectors - based on actual ZID structure
            ['id' => 'ZID17', 'name' => 'Evansville 17', 'freq' => '128.225', 'area' => 'EVV', 'offset' => [-0.6, -0.3]],
            ['id' => 'ZID18', 'name' => 'Abb 18', 'freq' => '120.625', 'area' => 'ABB', 'offset' => [-0.3, 0.1]],
            ['id' => 'ZID19', 'name' => 'Ewo 19', 'freq' => '135.175', 'area' => 'EWO', 'offset' => [-0.5, -0.5]],
            ['id' => 'ZID20', 'name' => 'Lexington 20', 'freq' => '128.125', 'area' => 'LEX', 'offset' => [0.0, 0.0]],
            ['id' => 'ZID21', 'name' => 'London 21', 'freq' => '134.575', 'area' => 'LOZ', 'offset' => [0.0, -0.4]],
            ['id' => 'ZID22', 'name' => 'Cincinnati 22', 'freq' => '126.875', 'area' => 'CVG', 'offset' => [0.2, 0.2]],
            ['id' => 'ZID24', 'name' => 'Parkersburg 24', 'freq' => '128.025', 'area' => 'PKB', 'offset' => [0.7, 0.1]],
            ['id' => 'ZID25', 'name' => 'Hazard 25', 'freq' => '133.775', 'area' => 'AZQ', 'offset' => [0.5, -0.3]],
            ['id' => 'ZID26', 'name' => 'Riverside 26', 'freq' => '128.625', 'area' => 'RIV', 'offset' => [0.4, -0.1]],
            ['id' => 'ZID30', 'name' => 'Columbus 30', 'freq' => '128.325', 'area' => 'CMH', 'offset' => [0.5, 0.4]],
            ['id' => 'ZID31', 'name' => 'Little 31', 'freq' => '135.825', 'area' => 'LTL', 'offset' => [0.3, 0.5]],
            ['id' => 'ZID32', 'name' => 'Rosewood 32', 'freq' => '134.475', 'area' => 'ROD', 'offset' => [0.2, 0.7]],
            ['id' => 'ZID33', 'name' => 'Muncie 33', 'freq' => '128.975', 'area' => 'MIE', 'offset' => [0.0, 0.5]],
            ['id' => 'ZID34', 'name' => 'Shelbyville 34', 'freq' => '132.175', 'area' => 'SHB', 'offset' => [-0.2, 0.3]],
            ['id' => 'ZID35', 'name' => 'Terre Haute 35', 'freq' => '133.325', 'area' => 'HUF', 'offset' => [-0.5, 0.2]],
            ['id' => 'ZID69', 'name' => 'Piketon 69', 'freq' => '133.125', 'area' => 'PIK', 'offset' => [0.4, 0.25]],
        ],
        'ZDC' => [
            // Washington sectors - based on actual ZDC areas
            ['id' => 'ZDC12', 'name' => 'Brooke 12', 'freq' => '126.875', 'area' => 'BROOKE', 'offset' => [-0.3, 0.4]],
            ['id' => 'ZDC15', 'name' => 'Brooke 15', 'freq' => '126.875', 'area' => 'BROOKE', 'offset' => [-0.1, 0.45]],
            ['id' => 'ZDC19', 'name' => 'Woodstown 19', 'freq' => '125.450', 'area' => 'WOODSTOWN', 'offset' => [0.2, 0.5]],
            ['id' => 'ZDC32', 'name' => 'Gordonsville 32', 'freq' => '133.725', 'area' => 'GORDONSVILLE', 'offset' => [-0.4, 0.0]],
            ['id' => 'ZDC33', 'name' => 'Gordonsville 33', 'freq' => '133.725', 'area' => 'GORDONSVILLE', 'offset' => [-0.35, -0.1]],
            ['id' => 'ZDC59', 'name' => 'Sea Isle 59', 'freq' => '133.125', 'area' => 'SEAISLE', 'offset' => [0.4, 0.3]],
            ['id' => 'ZDC04', 'name' => 'Tyson 04', 'freq' => '124.350', 'area' => 'TYSON', 'offset' => [0.0, 0.3]],
            ['id' => 'ZDC29', 'name' => 'OJAAY 29', 'freq' => '132.450', 'area' => 'OJAAY', 'offset' => [-0.2, 0.15]],
            ['id' => 'ZDC38', 'name' => 'DCAFR 38', 'freq' => '127.325', 'area' => 'DCAFR', 'offset' => [0.1, 0.2]],
            ['id' => 'ZDC46', 'name' => 'KRANT 46', 'freq' => '135.275', 'area' => 'KRANT', 'offset' => [0.15, 0.0]],
            ['id' => 'ZDC17', 'name' => 'LURAY 17', 'freq' => '128.175', 'area' => 'LURAY', 'offset' => [-0.25, -0.15]],
        ],
        'ZMA' => [
            // Miami sectors
            ['id' => 'ZMA06', 'name' => 'Miami 06', 'freq' => '133.950', 'area' => 'MIA', 'offset' => [0.0, 0.0]],
            ['id' => 'ZMA21', 'name' => 'Miami 21', 'freq' => '133.950', 'area' => 'MIA', 'offset' => [0.1, 0.1]],
            ['id' => 'ZMA41', 'name' => 'Miami 41', 'freq' => '128.425', 'area' => 'MIA', 'offset' => [0.0, -0.3]],
            ['id' => 'ZMA46', 'name' => 'Miami 46', 'freq' => '128.425', 'area' => 'MIA', 'offset' => [0.1, -0.2]],
            ['id' => 'ZMA15', 'name' => 'Palm 15', 'freq' => '127.850', 'area' => 'PALM', 'offset' => [0.4, 0.3]],
            ['id' => 'ZMA18', 'name' => 'Tampa 18', 'freq' => '126.400', 'area' => 'TPA', 'offset' => [-0.5, 0.2]],
            ['id' => 'ZMA72', 'name' => 'Oceanic 72', 'freq' => '132.150', 'area' => 'OCEANIC', 'offset' => [0.2, -0.5]],
        ],
        'ZTL' => [
            // Atlanta sectors
            ['id' => 'ZTL17', 'name' => 'Atlanta 17', 'freq' => '127.950', 'area' => 'ATL', 'offset' => [0.0, 0.0]],
            ['id' => 'ZTL18', 'name' => 'Atlanta 18', 'freq' => '127.950', 'area' => 'ATL', 'offset' => [0.1, 0.1]],
            ['id' => 'ZTL33', 'name' => 'Macon 33', 'freq' => '125.075', 'area' => 'MCN', 'offset' => [0.0, -0.4]],
            ['id' => 'ZTL49', 'name' => 'Birmingham 49', 'freq' => '132.575', 'area' => 'BHM', 'offset' => [-0.5, -0.1]],
            ['id' => 'ZTL28', 'name' => 'Chattanooga 28', 'freq' => '133.950', 'area' => 'CHA', 'offset' => [-0.2, 0.4]],
            ['id' => 'ZTL51', 'name' => 'Greenville 51', 'freq' => '128.325', 'area' => 'GSP', 'offset' => [0.3, 0.3]],
        ],
    ];
    
    // Get config or generate default
    $sectorConfig = $configs[$facility] ?? generateDefaultConfig($facility);
    
    foreach ($sectorConfig as $cfg) {
        $sectorCenter = [
            $center[0] + $cfg['offset'][0] * 2,
            $center[1] + $cfg['offset'][1] * 2
        ];
        
        // Generate irregular polygon around center
        $geometry = generateSectorPolygon($sectorCenter, 0.5 + (rand(0, 100) / 200));
        
        $sectors[] = [
            'id' => $cfg['id'],
            'name' => $cfg['name'],
            'freq' => $cfg['freq'],
            'area' => $cfg['area'] ?? null,
            'tags' => ['SECTOR', 'HIGH'],
            'geometry' => $geometry,
            'centroid' => $sectorCenter
        ];
    }
    
    return $sectors;
}

/**
 * Generate default sector config for facilities without custom data
 */
function generateDefaultConfig($facility) {
    $config = [];
    
    // Generate 8 sample sectors in a grid pattern
    $sectorNumbers = [10, 11, 12, 20, 21, 22, 30, 31];
    $offsets = [
        [-0.5, 0.3], [-0.1, 0.4], [0.3, 0.3],
        [-0.4, 0.0], [0.0, 0.0], [0.4, 0.0],
        [-0.3, -0.3], [0.1, -0.3]
    ];
    
    foreach ($sectorNumbers as $i => $num) {
        $config[] = [
            'id' => $facility . $num,
            'name' => $facility . ' Sector ' . $num,
            'freq' => sprintf('1%02d.%03d', rand(20, 35), rand(0, 999)),
            'offset' => $offsets[$i]
        ];
    }
    
    return $config;
}

/**
 * Generate an irregular polygon for a sector
 */
function generateSectorPolygon($center, $size) {
    $points = [];
    $numPoints = rand(5, 8);
    
    for ($i = 0; $i < $numPoints; $i++) {
        $angle = ($i / $numPoints) * 2 * M_PI;
        $radius = $size * (0.7 + (rand(0, 60) / 100));
        
        $points[] = [
            $center[0] + $radius * cos($angle),
            $center[1] + $radius * sin($angle) * 0.7 // Slightly squashed for realism
        ];
    }
    
    // Close the polygon
    $points[] = $points[0];
    
    return [
        'type' => 'Polygon',
        'coordinates' => [$points]
    ];
}

/**
 * Calculate bounds for sample data
 */
function calculateSampleBounds($sectors) {
    if (empty($sectors)) return null;
    
    $minLng = 180; $maxLng = -180;
    $minLat = 90; $maxLat = -90;
    
    foreach ($sectors as $sector) {
        if (!isset($sector['geometry']['coordinates'])) continue;
        
        foreach ($sector['geometry']['coordinates'][0] as $coord) {
            $minLng = min($minLng, $coord[0]);
            $maxLng = max($maxLng, $coord[0]);
            $minLat = min($minLat, $coord[1]);
            $maxLat = max($maxLat, $coord[1]);
        }
    }
    
    return [[$minLng, $minLat], [$maxLng, $maxLat]];
}
