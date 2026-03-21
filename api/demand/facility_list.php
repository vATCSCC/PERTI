<?php

// api/demand/facility_list.php
// Returns available TRACONs, ARTCCs, FIRs, and groups for facility demand dropdown
// Read-only config endpoint - no database queries needed

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . "/../../load/config.php");
require_once(__DIR__ . "/../../load/input.php");
require_once(__DIR__ . "/../../load/cache.php");

// Check APCu cache (300s TTL)
$cacheKey = 'facility_list_v1';
$cached = apcu_cache_get($cacheKey);
if ($cached !== null) {
    header('X-Cache: HIT');
    echo json_encode($cached);
    exit;
}

// Build response from JSON config files
$response = [
    "success" => true,
    "timestamp" => gmdate("Y-m-d\\TH:i:s\\Z"),
    "tracons" => [],
    "artccs" => [],
    "firs" => [],
    "groups" => [
        "regional" => [],
        "byIcaoPrefix" => [],
        "global" => []
    ]
];

// Read TRACON tiers - organized by region (us/canada/caribbean/global)
$traconFile = __DIR__ . "/../../assets/data/tracon_tiers.json";
if (file_exists($traconFile)) {
    $traconData = json_decode(file_get_contents($traconFile), true);
    if ($traconData && is_array($traconData)) {
        // Extract regional sections (us, canada, caribbean, global)
        foreach (['us', 'canada', 'caribbean', 'global'] as $region) {
            if (isset($traconData[$region]) && is_array($traconData[$region])) {
                $response['tracons'][$region] = $traconData[$region];
            } else {
                $response['tracons'][$region] = [];
            }
        }
    }
}

// Read ARTCC tiers - extract ARTCC codes from byFacility keys
$artccFile = __DIR__ . "/../../assets/data/artcc_tiers.json";
if (file_exists($artccFile)) {
    $artccData = json_decode(file_get_contents($artccFile), true);
    if ($artccData && isset($artccData['byFacility']) && is_array($artccData['byFacility'])) {
        $artccs = array_keys($artccData['byFacility']);
        sort($artccs); // Alphabetical order
        $response['artccs'] = $artccs;
    }
}

// Read FIR tiers - extract Canadian FIRs, organize groups
$firFile = __DIR__ . "/../../assets/data/fir_tiers.json";
if (file_exists($firFile)) {
    $firData = json_decode(file_get_contents($firFile), true);
    if ($firData && is_array($firData)) {
        // Extract individual Canadian FIRs from regional section
        $canadianFirs = [];
        if (isset($firData['regional']['CANE']['members']) && is_array($firData['regional']['CANE']['members'])) {
            $canadianFirs = array_merge($canadianFirs, $firData['regional']['CANE']['members']);
        }
        if (isset($firData['regional']['CANW']['members']) && is_array($firData['regional']['CANW']['members'])) {
            $canadianFirs = array_merge($canadianFirs, $firData['regional']['CANW']['members']);
        }
        sort($canadianFirs); // Alphabetical order
        $response['firs'] = $canadianFirs;

        // Process regional groups (skip Manual and alias entries)
        if (isset($firData['regional']) && is_array($firData['regional'])) {
            foreach ($firData['regional'] as $code => $group) {
                if ($code === 'Manual' || (isset($group['alias']) && $group['alias'])) {
                    continue; // Skip Manual and alias entries
                }
                $response['groups']['regional'][] = [
                    'code' => $code,
                    'label' => $group['label'] ?? $code,
                    'description' => $group['description'] ?? '',
                    'patterns' => $group['patterns'] ?? [],
                    'members' => $group['members'] ?? []
                ];
            }
        }

        // Process byIcaoPrefix groups (skip alias entries)
        if (isset($firData['byIcaoPrefix']) && is_array($firData['byIcaoPrefix'])) {
            foreach ($firData['byIcaoPrefix'] as $code => $group) {
                if ($code === '_comment') {
                    continue; // Skip comment key
                }
                if (isset($group['alias']) && $group['alias']) {
                    continue; // Skip alias entries
                }
                $response['groups']['byIcaoPrefix'][] = [
                    'code' => $code,
                    'label' => $group['label'] ?? $code,
                    'country' => $group['country'] ?? null,
                    'patterns' => $group['patterns'] ?? [],
                    'exclude' => $group['exclude'] ?? []
                ];
            }
        }

        // Process global groups (skip Manual and alias entries)
        if (isset($firData['global']) && is_array($firData['global'])) {
            foreach ($firData['global'] as $code => $group) {
                if ($code === 'Manual' || (isset($group['alias']) && $group['alias'])) {
                    continue; // Skip Manual and alias entries
                }
                $response['groups']['global'][] = [
                    'code' => $code,
                    'label' => $group['label'] ?? $code,
                    'description' => $group['description'] ?? '',
                    'patterns' => $group['patterns'] ?? []
                ];
            }
        }
    }
}

// Cache response (300s TTL)
apcu_cache_set($cacheKey, $response, 300);
header('X-Cache: MISS');

echo json_encode($response);
