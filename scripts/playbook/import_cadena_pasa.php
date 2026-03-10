<?php
/**
 * CADENA PASA Route Import
 *
 * Imports CADENA (CANSO Air Traffic Flow Management Data Exchange Network for
 * the Americas) PASA (Planned Airway System Alternative) routes into the
 * playbook system. 16 plays with 171 routes covering FIR avoidance scenarios
 * across the Caribbean, Central/South America, and Gulf of Mexico.
 *
 * Source: CADENA Planned Airway System Alternative - PASA Routes (02/12/2026)
 *
 * Idempotent: deletes existing CADENA plays before re-importing.
 * Uses batch INSERT for performance.
 *
 * Usage: Upload to Azure via VFS API, hit via public URL, then delete.
 */

set_time_limit(60);
header('Content-Type: text/plain; charset=utf-8');

$host = "vatcscc-perti.mysql.database.azure.com";
$db   = "perti_site";
$user = "jpeterson";
$pass = "Jhp21012";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ]);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage() . "\n");
}

function normPlay($n) { return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $n)); }

/**
 * Normalize ARTCC codes:
 * - US ICAO K-prefix stripping: KZNY->ZNY, KZMA->ZMA, etc.
 * - Canadian FAA 3-letter to ICAO 4-letter: CZE->CZEG, CZU->CZUL, etc.
 * Applied to ARTCC CSV fields only (NOT route strings — CZM is Cozumel VOR, not Moncton ARTCC).
 */
function normalizeCanadianArtcc($code) {
    static $map = [
        'CZE' => 'CZEG', 'CZU' => 'CZUL', 'CZV' => 'CZVR',
        'CZW' => 'CZWG', 'CZY' => 'CZYZ', 'CZM' => 'CZQM',
        'CZQ' => 'CZQX', 'CZO' => 'CZQO',
        'PAZA' => 'ZAN',
    ];
    $code = strtoupper(trim($code));
    if (preg_match('/^KZ[A-Z]{2}$/', $code)) $code = substr($code, 1);
    return $map[$code] ?? $code;
}

function normalizeCanadianArtccCsv($csv) {
    if (trim($csv) === '') return $csv;
    return implode(',', array_map('normalizeCanadianArtcc', explode(',', $csv)));
}

// ============================================================================
// PLAY DEFINITIONS
// ============================================================================
$plays = [
    'PASA AVOID MUFH' => [
        'display_name' => 'CADENA PASA - Avoid Havana FIR',
        'description' => 'Pre-coordinated PASA reroutes to avoid MUFH (Havana FIR / Cuba). Includes WEST (via Yucatan/Gulf of Mexico), EAST (via Jamaica/Haiti corridors), and ZMA-avoidance variants.',
        'impacted_area' => 'MUFH/ZMA/ZTL/ZJX/ZHU',
        'facilities_involved' => 'MUFH,MKJK,MMFR,TNCF,MDCS,ZMA,ZTL,ZJX,ZHU,ZNY',
        'remarks' => 'Variants: WEST (Yucatan corridor), EAST (Jamaica/Haiti corridor), ZMA-WEST (bypasses both MUFH and ZMA). Some routes coordinated with JCAA, OFNAC, SENEAM.',
    ],
    'PASA AVOID MKJK' => [
        'display_name' => 'CADENA PASA - Avoid Kingston FIR',
        'description' => 'Pre-coordinated PASA reroutes to avoid MKJK (Kingston FIR / Jamaica). Includes WEST (via Panama/Colombia direct routing) and EAST (via Haiti/DR corridor) variants. Also includes Port-au-Prince DCT segments.',
        'impacted_area' => 'MKJK/MTEG/ZMA/ZTL/ZJX/ZHU/ZFW',
        'facilities_involved' => 'MKJK,MTEG,SKEC,MPZL,MUFH,ZMA,ZTL,ZJX,ZHU,ZFW',
        'remarks' => 'Port-au-Prince DCT segments (LENOM-DEPSI, LENOM-MODIT, ETBOD-URLAM) apply to various O/D pairs through MTEG. Some routes include DC-ANSP data and ZMA feedback.',
    ],
    'PASA GTK OOS' => [
        'display_name' => 'CADENA PASA - Grand Turk Radar Out of Service',
        'description' => 'Route from Punta Cana to US East Coast when Grand Turk radar is out of service.',
        'impacted_area' => 'MDCS/TJZS/ZNY/ZBW',
        'facilities_involved' => 'MDCS,TJZS,ZNY,ZBW,ZDC',
        'remarks' => 'For MDPC and DR origins to KJFK, KEWR, KBOS, CYYZ, and other east coast destinations.',
    ],
    'PASA AVOID MMFR' => [
        'display_name' => 'CADENA PASA - Avoid Merida FIR / Gulf of Mexico',
        'description' => 'Pre-coordinated PASA reroutes to avoid MMFR (Merida FIR) and Gulf of Mexico crossings. Includes WEST (Pacific coast of Mexico), EAST (via ZMA/Caribbean), CENTRAL (Bay of Campeche), and Western Gulf variants.',
        'impacted_area' => 'MMFR/ZHU/ZFW/ZMA/ZTL/ZJX',
        'facilities_involved' => 'MMFR,MKJK,MHCC,MPZL,SKEC,ZHU,ZFW,ZMA,ZTL,ZJX,ZAB',
        'remarks' => 'Multiple variants for different Gulf crossing strategies. Some routes coordinated with SENEAM.',
    ],
    'PASA AVOID MHCC' => [
        'display_name' => 'CADENA PASA - Avoid Central America FIR',
        'description' => 'Pre-coordinated PASA reroutes to avoid MHCC (Central America/Tegucigalpa FIR). All routes use EAST variant via Caribbean corridor.',
        'impacted_area' => 'MHCC/MKJK/MMFR/ZMA/ZTL/ZFW/ZHU',
        'facilities_involved' => 'MHCC,MKJK,MMFR,MPZL,ZMA,ZTL,ZFW,ZHU',
        'remarks' => null,
    ],
    'PASA AVOID TNCF' => [
        'display_name' => 'CADENA PASA - Avoid Curacao FIR',
        'description' => 'Pre-coordinated PASA reroutes to avoid TNCF (Curacao FIR). Includes WEST (via Colombia/Venezuela inland corridor) and EAST (via Lesser Antilles/Trinidad) variants. Brazil routes comply with CGNA requirements.',
        'impacted_area' => 'TNCF/SKEC/SVZM/TTZP/ZMA/ZTL/ZNY/ZHU',
        'facilities_involved' => 'TNCF,SKEC,SVZM,TTZP,MKJK,ZMA,ZTL,ZNY,ZBW,ZHU',
        'remarks' => 'Brazil origin routes comply with CGNA (Brazilian ATC) requirements.',
    ],
    'PASA AVOID MDCS' => [
        'display_name' => 'CADENA PASA - Avoid Santo Domingo FIR',
        'description' => 'Pre-coordinated PASA reroutes to avoid MDCS (Santo Domingo FIR / Dominican Republic). Includes EAST (via Lesser Antilles), WEST (via Caribbean/Colombia corridor), and NORTH+EAST variants.',
        'impacted_area' => 'MDCS/TJZS/TNCF/TTZP/ZMA/ZNY/ZHU',
        'facilities_involved' => 'MDCS,TJZS,TNCF,TTZP,SVZM,ZMA,ZNY,ZHU,ZTL',
        'remarks' => null,
    ],
    'PASA AVOID TJZS' => [
        'display_name' => 'CADENA PASA - Avoid San Juan FIR',
        'description' => 'Pre-coordinated PASA reroutes to avoid TJZS (San Juan FIR / Puerto Rico). Includes EAST (oceanic via Bermuda/Azores corridor) and SOUTHWEST (via Lesser Antilles/Venezuela) variants. Brazil routes comply with CGNA requirements.',
        'impacted_area' => 'TJZS/MDCS/TNCF/SVZM/ZNY/ZMA',
        'facilities_involved' => 'TJZS,MDCS,TNCF,SVZM,ZNY,ZMA,ZBW',
        'remarks' => null,
    ],
    'PASA AVOID MTEG' => [
        'display_name' => 'CADENA PASA - Avoid Port-au-Prince FIR',
        'description' => 'Pre-coordinated PASA reroutes to avoid MTEG (Port-au-Prince FIR / Haiti). Includes EAST (via Lesser Antilles) and WEST (via Caribbean/Colombia corridor) variants.',
        'impacted_area' => 'MTEG/MDCS/MKJK/TNCF/ZMA/ZNY/ZTL',
        'facilities_involved' => 'MTEG,MDCS,MKJK,TNCF,SVZM,ZMA,ZNY,ZTL',
        'remarks' => null,
    ],
    'PASA AVOID TTZP' => [
        'display_name' => 'CADENA PASA - Avoid Piarco FIR',
        'description' => 'Pre-coordinated PASA reroutes to avoid TTZP (Piarco FIR / Trinidad and Tobago). All routes use WEST variant via Colombia/Venezuela inland corridor.',
        'impacted_area' => 'TTZP/TNCF/SVZM/ZMA/ZTL',
        'facilities_involved' => 'TTZP,TNCF,SVZM,SKEC,ZMA,ZTL',
        'remarks' => 'Brazil NB route complies with CGNA requirements and includes ZMA feedback.',
    ],
    'PASA AVOID SVZM' => [
        'display_name' => 'CADENA PASA - Avoid Maiquetia FIR',
        'description' => 'Pre-coordinated PASA reroutes to avoid SVZM (Maiquetia FIR / Venezuela). Includes EAST (via Lesser Antilles) and WEST (via Colombia/Caribbean corridor) variants.',
        'impacted_area' => 'SVZM/TNCF/TTZP/SKEC/ZMA/ZNY/ZTL',
        'facilities_involved' => 'SVZM,TNCF,TTZP,SKEC,MDCS,ZMA,ZNY,ZTL',
        'remarks' => null,
    ],
    'PASA AVOID SKEC' => [
        'display_name' => 'CADENA PASA - Avoid Barranquilla FIR',
        'description' => 'Pre-coordinated PASA reroute to avoid SKEC (Barranquilla FIR / Colombia). Route uses EAST variant via Trinidad/Venezuela/Lesser Antilles corridor.',
        'impacted_area' => 'SKEC/SVZM/TNCF/ZHU',
        'facilities_involved' => 'SKEC,SVZM,TNCF,ZHU',
        'remarks' => 'Complies with CGNA and SENEAM requirements.',
    ],
    'PASA AVOID SPIM' => [
        'display_name' => 'CADENA PASA - Avoid Lima FIR',
        'description' => 'Pre-coordinated PASA reroutes to avoid SPIM (Lima FIR / Peru). All routes use EAST variant via Brazil/Colombia inland corridor.',
        'impacted_area' => 'SPIM/SKEC/SVZM/ZMA',
        'facilities_involved' => 'SPIM,SKEC,SVZM,SBBS,SCEZ,ZMA',
        'remarks' => null,
    ],
    'PASA AVOID KZHU' => [
        'display_name' => 'CADENA PASA - Avoid Houston Center',
        'description' => 'Pre-coordinated PASA reroutes to avoid KZHU (Houston Center) airspace. Includes EAST (via ZMA/Florida Straits) and WEST (via ZAB/west Texas/northern Mexico) variants.',
        'impacted_area' => 'KZHU/ZMA/ZAB/MMFR',
        'facilities_involved' => 'ZHU,ZMA,ZAB,ZFW,MMFR,MHCC',
        'remarks' => 'AXOMU-CANOA and CTM-FIS routes are bidirectional. Some routes include ZMA and SENEAM coordination.',
    ],
    'PASA AVOID KZMA' => [
        'display_name' => 'CADENA PASA - Avoid Miami Center',
        'description' => 'Pre-coordinated PASA reroutes to avoid KZMA (Miami Center) airspace. Includes routes from Dominican Republic to US East Coast bypassing ZMA, and westbound Santo Domingo routing.',
        'impacted_area' => 'KZMA/MDCS/TJZS/ZNY',
        'facilities_involved' => 'ZMA,MDCS,TJZS,ZNY,ZBW,ZDC',
        'remarks' => null,
    ],
    'PASA GTK RADAR OOS' => [
        'display_name' => 'CADENA PASA - Grand Turk Radar Out of Service (Full)',
        'description' => 'Comprehensive rerouting and airway closures when Grand Turk radar is out of service. Defines alternate routing segments for NB/SB traffic through the GTK area. Affects DR, Caribbean, South American, and NY Oceanic traffic.',
        'impacted_area' => 'MDCS/TJZS/ZNY/ZMA',
        'facilities_involved' => 'MDCS,TJZS,ZNY,ZMA,ZBW,ZDC',
        'remarks' => 'Airway closures when active: M596 POKEG-WATRS, L464 LERED-CERDA, A554/L450 SEKAR-GTK, L463/BR2L JUELE-PVN, Y304 SEKAR-RUTOC, Y397 SEKAR-RENAH, Y306 POKEG-CHASO, Y585 ELMUC-RENAH, L451/G431 CERDA-LETON NB, L452 LNHOM-GTK, M594/M596/B891 FULL, Y185 DONQU-RENAH, Y280 SAPPO-CHASO, Y421 HARBG-SUMAC, Y308 FEKKO-FODED, Y399 SAPPO-CADGE, Y290 CALTO-BITAC, Y330 HARBG-FODED, Y396 MALVN-RUMFO, Y261 MALVN-MADIZ',
    ],
];

// ============================================================================
// ROUTE DEFINITIONS — [play_name, route_string, origin, dest, orig_apt, orig_artcc, dest_apt, dest_artcc, remarks]
// ============================================================================
$routes = [
    // --- PASA AVOID MUFH (36 routes) ---
    ['PASA AVOID MUFH', 'BOSOM GCM UR640 MAMBI UL577 ILUBA UL333 RAKAR UM219 MYDIA Y240 SHAQQ', 'MKJP', 'KMIA', 'MKJP', 'MKJK', 'KMIA', 'ZMA', 'NB WEST; JCAA UPDATE'],
    ['PASA AVOID MUFH', 'ENEKA KEBET UM594 MEDON UA315 JOSES', 'MKJP', 'KMIA', 'MKJP', 'MKJK', 'KMIA', 'ZMA', 'NB EAST'],
    ['PASA AVOID MUFH', 'CUN UJ52 ILUBA DCT PABEL UL471 IKBIX', 'MMUN', 'KMIA', 'MMUN', 'MMFR', 'KMIA', 'ZMA', 'NB EAST; MAXIM variant; JCAA UPDATE'],
    ['PASA AVOID MUFH', 'CUN UM219 MYDIA M219 SNAKR Y240 SHAQQ', 'MMUN', 'KMIA', 'MMUN', 'MMFR', 'KMIA', 'ZMA', 'NB WEST'],
    ['PASA AVOID MUFH', 'TUM UN420 ILUBA UL333 RAKAR UM219 MYDIA Y240 MARCI FROGZ4', 'MPTO', 'KMIA', 'MPTO', 'MPZL', 'KMIA', 'ZMA', 'NB WEST'],
    ['PASA AVOID MUFH', 'CUN UM219 MYDIA M219 KNOST KPASA DAWWN', 'MMUN', 'KATL', 'MMUN', 'MMFR', 'KATL', 'ZTL', 'NB WEST'],
    ['PASA AVOID MUFH', 'CUN UT27 OMVIP UT45 ALKIM KEHLI A770 LEV GCV PAYTN', 'MMUN', 'KATL', 'MMUN', 'MMFR', 'KATL', 'ZTL', 'NB WEST'],
    ['PASA AVOID MUFH', 'MLY UG633 KEBET UM594 MEDON UA315 JOSES Y398 ZIN Y298 VENDS Y185 MANLE Q89 SHRKS LAIRI', 'MKJP', 'KATL', 'MKJP', 'MKJK', 'KATL', 'ZTL', 'NB EAST; MANLE Q89 SHRKS: AOB FL370 per ZMA/ZJX LOA'],
    ['PASA AVOID MUFH', 'MLY UA511 SIA UG633 GCM UR640 MAMBI UL577 AVSEB UM782 CUN M219 KNOST CAMJO', 'MKJP', 'KATL', 'MKJP', 'MKJK', 'KATL', 'ZTL', 'NB WEST'],
    ['PASA AVOID MUFH', 'TUM TBG UA317 MGA UA502 TNT UA754 BTO UB881 CZM UB881 CUN UM219 MYDIA M219 CIGAR BLON PLYER Q104 HEVVN CAPPS DAWWN', 'MPTO', 'KATL', 'MPTO', 'MPZL', 'KATL', 'ZTL', 'NB WEST; JCAA UPDATE'],
    ['PASA AVOID MUFH', 'MIKUS UM542 SIA UR625 ENAMO Y307 CARPX G446 OLDEY SPIKY AR7 CHS HOTHH', 'MPTO', 'KATL', 'MPTO', 'MPZL', 'KATL', 'ZTL', 'NB WEST'],
    ['PASA AVOID MUFH', 'SEKMA ARNAL UL465 GCM UG448 IKBIX Y183 PEAKY Q87 VIYAP LAIRI', 'MPTO', 'KATL', 'MPTO', 'MPZL', 'KATL', 'ZTL', 'NB EAST'],
    ['PASA AVOID MUFH', 'VASIL UW36 DAKMO UM549 TBG UA321 ILUBA UL333 PISAD M215 KNOST AMORY', 'SKBO', 'KATL', 'SKBO', 'SKEC', 'KATL', 'ZTL', 'NB WEST'],
    ['PASA AVOID MUFH', 'ISREN UL780 GYV UM659 PARRI UR773 LIB UZ512 MGA UZ498 BTO UB881 CZM CUN UT27 PISAD M215 MINOW REMIS', 'SPJC', 'KATL', 'SPJC', 'SPIM', 'KATL', 'ZTL', 'NB WEST'],
    ['PASA AVOID MUFH', 'MIKUS UM542 SIA UR625 ENAMO Y307 CARPX G446 OLDEY SPIKY', 'MPTO', 'KATL', 'MPTO', 'MPZL', 'KATL', 'ZTL', 'NB WEST'],
    ['PASA AVOID MUFH', 'ABA UL468 PIGBI UA315 JOSES Y398 ZIN RUMFO', 'TNCA', 'KFLL', 'TNCA', 'TNCF', 'KFLL', 'ZMA', 'NB EAST'],
    ['PASA AVOID MUFH', 'CDO SGO UW39 MALVN Y396 RUMFO Y441 ZQA', 'MDSD', 'KFLL', 'MDSD', 'MDCS', 'KFLL', 'ZMA', 'NB EAST'],
    ['PASA AVOID MUFH', 'MINOW M215 PISAD UM215 NUDIS ITLOM UM782 CUN', 'KMIA', 'MMUN', 'KMIA', 'ZMA', 'MMUN', 'MMFR', 'SB WEST'],
    ['PASA AVOID MUFH', 'FUNDI UM335 ALVEK UM328 UCL UG448 GCM UR640 MAMBI UL577 CZM', 'KMIA', 'MMUN', 'KMIA', 'ZMA', 'MMUN', 'MMFR', 'SB SOUTH; CANOA variant'],
    ['PASA AVOID MUFH', 'FUNDI UM335 UDNET UG442 SIA UG633 KEMBO', 'KMIA', 'MKJP', 'KMIA', 'ZMA', 'MKJP', 'MKJK', 'SB EAST'],
    ['PASA AVOID MUFH', 'ENAMO UR625 NEFTU UA301 IMADI A301 SAVEM', 'KMIA', 'MKJP', 'KMIA', 'ZMA', 'MKJP', 'MKJK', 'SB WEST'],
    ['PASA AVOID MUFH', 'JAGOR JAYEE RYDEL ENAMO UR625 NEFTU UL417 BEMOL RADOK', 'KMIA', 'MKJP', 'KMIA', 'ZMA', 'MKJP', 'MKJK', 'SB WEST'],
    ['PASA AVOID MUFH', 'JOSES UQ301 LENOM EJA ILSEV', 'KMIA', 'SKBO', 'KMIA', 'ZMA', 'SKBO', 'SKEC', 'SB EAST; OFNAC INPUT'],
    ['PASA AVOID MUFH', 'LUCKK HONID BULZI NICKI KNOST M215 PISAD UM215 NUDIS ITLOM UM782 CUN', 'KATL', 'MMUN', 'KATL', 'ZTL', 'MMUN', 'MMFR', 'SB WEST'],
    ['PASA AVOID MUFH', 'GRGIA ARNNY SJI LEV A770 KEHLI UA770 BETAS VOMAR UM782 CUN', 'KATL', 'MMUN', 'KATL', 'ZTL', 'MMUN', 'MMFR', 'SB WEST; ZMA-WEST variant'],
    ['PASA AVOID MUFH', 'SAV TORRY AR5 CARPX Y307 NUCAR Y374 RUMFO ZIN Y398 JOSES UA315 MEDON UM594 KEBET UG633 MLY KEYNO2 MKJP', 'KATL', 'MKJP', 'KATL', 'ZTL', 'MKJP', 'MKJK', 'SB EAST'],
    ['PASA AVOID MUFH', 'GRGIA SJI HRV L333 PISAD UL333 RAKAR UL674 FUNKO UL465 GCM UR640 KEMBO', 'KATL', 'MKJP', 'KATL', 'ZTL', 'MKJP', 'MKJK', 'SB WEST; ZMA-WEST variant'],
    ['PASA AVOID MUFH', 'SJI HRV L333 PISAD UL333 RAKAR UL674 FUNKO UL465 GCM UR640 KEMBO', 'KATL', 'MKJP', 'KATL', 'ZTL', 'MKJP', 'MKJK', 'SB EAST'],
    ['PASA AVOID MUFH', 'GRGIA ARNNY SJI LEV A770 KEHLI UA770 MID UB753 BZE UR899 PZA UA552 SPP UA321 TBG', 'KATL', 'MPTO', 'KATL', 'ZTL', 'MPTO', 'MPZL', 'SB WEST'],
    ['PASA AVOID MUFH', 'LEV BODLO UL337 NEGON L337 ARMUR UL337 POS UA324 MINDA UL452 BSI UZ6 NIMKI UZ38 MOXEP', 'KIAH', 'SBGR', 'KIAH', 'ZHU', 'SBGR', 'SBBS', 'SB EAST'],
    ['PASA AVOID MUFH', 'LUCKK HONID BULZI NICKI KNOST M215 PISAD UL333 ILUBA UA321 PELRA STG TINPA UGUPI UL780 TRU UV1 ATATU', 'KATL', 'SPJC', 'KATL', 'ZTL', 'SPJC', 'SPIM', 'SB WEST; JCAA UPDATE'],
    ['PASA AVOID MUFH', 'VENDS Y298 ZIN Y398 JOSES UQ301 LENOM LIDOL UG444 EJA UZ012 BUV UW1 BOG UA550 GIR UL305 TAP UT228 ILROL ILROL4', 'KATL', 'SPJC', 'KATL', 'ZTL', 'SPJC', 'SPIM', 'SB EAST; OFNAC INPUT'],
    ['PASA AVOID MUFH', 'TOVAR Y297 URSUS UL780 TASNO UM221 COLBY UM542 MIKUS MPTO', 'KATL', 'MPTO', 'KATL', 'ZTL', 'MPTO', 'MPZL', 'SB WEST'],
    ['PASA AVOID MUFH', 'YUESS Q79 MCLAW Y442 FUNDI UM335 ARNAL UL465 TBG', 'KATL', 'MPTO', 'KATL', 'ZTL', 'MPTO', 'MPZL', 'SB EAST'],
    ['PASA AVOID MUFH', 'PLYER KNOST M219 SNAKR MYDIA ALPUK RAKAR ROTGI OMSUK CUN UL214 SIGMA UM205 SPP UG447 MQU W23 ABL', 'KATL', 'SKBO', 'KATL', 'ZTL', 'SKBO', 'SKEC', 'SB WEST'],
    ['PASA AVOID MUFH', 'JOSES UA315 PIGBI DUSAN TUGUL', 'KFLL', 'TNCA', 'KFLL', 'ZMA', 'TNCA', 'TNCF', 'SB EAST; OFNAC INPUT'],

    // --- PASA AVOID MKJK (24 routes) ---
    ['PASA AVOID MKJK', 'SPP FALLA SELEK UCL', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB WEST; Segment only'],
    ['PASA AVOID MKJK', 'KOMBO UW1 ENPUT UL342 MAGTA UA574 ABA UL468 PIGBI A315 HODGY', 'SKBO', 'KMIA', 'SKBO', 'SKEC', 'KMIA', 'ZMA', 'NB EAST; DC-ANSP DATA'],
    ['PASA AVOID MKJK', 'VASIL UQ112 ARORO UG447 SPP UA321 BONOS UZ403 SELEK UL345 IKBIX', 'SKBO', 'KMIA', 'SKBO', 'SKEC', 'KMIA', 'ZMA', 'NB WEST'],
    ['PASA AVOID MKJK', 'GIKPU UW10 EJA UG444 SELAN LIDOL UG444 LENOM JOSES Y398 ZIN Y353 SUMAC Y280 OCTAL Q77 ETORE SHRKS LAIRI', 'SKBO', 'KATL', 'SKBO', 'SKEC', 'KATL', 'ZTL', 'NB EAST; ZMA FEEDBACK'],
    ['PASA AVOID MKJK', 'VASIL UW36 DAKMO ITAGO TBG UA321 SPP UA552 PZA UR899 CTM UJ61 CPE UL207 ILOLI UT22 MAM SAT', 'SKBO', 'KDFW', 'SKBO', 'SKEC', 'KDFW', 'ZFW', 'NB WEST'],
    ['PASA AVOID MKJK', 'UM674 QIT UA550 BOKAN UQ113 ULQ UG438 RNG UW26 MRN UW3 MGN UL305 BAQ UW5 ERIKO UM597 LIDOL UG444 LENOM JOSES', 'SPJC', 'KATL', 'SPJC', 'SPIM', 'KATL', 'ZTL', 'NB EAST'],
    ['PASA AVOID MKJK', 'ISREN UM542 ATENO UL203 LIXAS UZ512 LIB UZ512 MGA UZ512 BZE UB753 MID UL208 DUTNA L208 ANKRR VUH CRIED KDFW', 'SPJC', 'KDFW', 'SPJC', 'SPIM', 'KDFW', 'ZFW', 'NB WEST'],
    ['PASA AVOID MKJK', 'SIMAN MORLI UA321 BONOS UZ403 SELEK UL345 IKBIX', 'MPTO', 'KMIA', 'MPTO', 'MPZL', 'KMIA', 'ZMA', 'NB WEST'],
    ['PASA AVOID MKJK', 'SIMAN SEKMA UG447 SPP UA321 BONOS UZ403 SELEK UL345 IKBIX', 'MPTO', 'KFLL', 'MPTO', 'MPZL', 'KFLL', 'ZMA', 'NB WEST'],
    ['PASA AVOID MKJK', 'LENOM DCT DEPSI', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB EAST; Via Port-au-Prince'],
    ['PASA AVOID MKJK', 'LENOM DCT MODIT', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB EAST; Via Port-au-Prince'],
    ['PASA AVOID MKJK', 'ETBOD DCT URLAM', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB EAST; Via Port-au-Prince'],
    ['PASA AVOID MKJK', 'DEPSI DCT LENOM', 'VARIOUS', 'VARIOUS', '', '', '', '', 'SB EAST; Via Port-au-Prince'],
    ['PASA AVOID MKJK', 'MODIT DCT LENOM', 'VARIOUS', 'VARIOUS', '', '', '', '', 'SB EAST; Via Port-au-Prince'],
    ['PASA AVOID MKJK', 'URLAM DCT ETBOD', 'VARIOUS', 'VARIOUS', '', '', '', '', 'SB EAST; Via Port-au-Prince'],
    ['PASA AVOID MKJK', 'UCL SELEK BONOS PELRA SPP', 'VARIOUS', 'VARIOUS', '', '', '', '', 'SB WEST; Segment only'],
    ['PASA AVOID MKJK', 'JOSES UA315 BINSI UG444 EJA ILSEV', 'KMIA', 'SKBO', 'KMIA', 'ZMA', 'SKBO', 'SKEC', 'SB EAST'],
    ['PASA AVOID MKJK', 'FUNDI UM335 ALVEK UM328 SELEK UZ403 BONOS UA321 SPP UG447 RNG ISVAT', 'KMIA', 'SKBO', 'KMIA', 'ZMA', 'SKBO', 'SKEC', 'SB WEST'],
    ['PASA AVOID MKJK', 'LEV OPT BODLO UL337 NEGON L337 ARMUR UL337 POS UA324 MINDA UL452 BSI UZ6 NIMKI UZ38 MOXEP', 'KIAH', 'SBGR', 'KIAH', 'ZHU', 'SBGR', 'SBBS', 'SB EAST'],
    ['PASA AVOID MKJK', 'BODLO UL337 NEGON L337 ARMUR UL337 POS UA324 MINDA UL452 BSI UZ6 NIMKI', 'KIAH', 'SBGR', 'KIAH', 'ZHU', 'SBGR', 'SBBS', 'SB EAST; N+E variant'],
    ['PASA AVOID MKJK', 'VENDS Y298 ZIN Y398 JOSES LENOM LIDOL SELAN UG444 EJA UQ119 ILSEV', 'KATL', 'SKBO', 'KATL', 'ZTL', 'SKBO', 'SKEC', 'SB EAST'],
    ['PASA AVOID MKJK', 'M215 PISAD UL333 URTOK UT29 SIGMA UM205 SPP', 'KATL', 'SKBO', 'KATL', 'ZTL', 'SKBO', 'SKEC', 'SB WEST'],
    ['PASA AVOID MKJK', 'VENDS Y298 ZIN Y398 JOSES LENOM LIDOL UG444 EJA UZ012 BUV UW1 BOG UA550 GIR UL305 TAP UT228 ILROL', 'KATL', 'SPJC', 'KATL', 'ZTL', 'SPJC', 'SPIM', 'SB EAST'],
    ['PASA AVOID MKJK', 'KNOST M215 PISAD UL333 DANUL UA321 PELRA STG TINPA UGUPI UL780 TRU UV1', 'KATL', 'SPJC', 'KATL', 'ZTL', 'SPJC', 'SPIM', 'SB WEST'],

    // --- PASA GTK OOS (1 route) ---
    ['PASA GTK OOS', 'CHUMA Y315 L455', 'MDPC', 'VARIOUS', 'MDPC', 'MDCS', '-KJFK -KEWR -KBOS -CYYZ', 'ZNY', 'NB; To east coast destinations'],

    // --- PASA AVOID MMFR (30 routes) ---
    ['PASA AVOID MMFR', 'CZA UJ84 CPE UJ9 VSA UJ12 VER UA552 TAM UJ53 REX MFE SAT', 'MMUN', 'KDFW', 'MMUN', 'MMFR', 'KDFW', 'ZFW', 'NB WEST'],
    ['PASA AVOID MMFR', 'CUN UT27 OMVIP UT45 IRDOV L214 LEV LSU AEX', 'MMUN', 'KDFW', 'MMUN', 'MMFR', 'KDFW', 'ZFW', 'NB WEST'],
    ['PASA AVOID MMFR', 'UDGUV UJ84 CZA UJ9 MID PERGO UT22 MAM CRP', 'MMUN', 'KIAH', 'MMUN', 'MMFR', 'KIAH', 'ZHU', 'NB CENTRAL; SENEAM DATA'],
    ['PASA AVOID MMFR', 'TBG UG440 LIB UG436 AUR UA552 TAM UJ19 MAM SAT', 'MPTO', 'KDFW', 'MPTO', 'MPZL', 'KDFW', 'ZFW', 'NB WEST'],
    ['PASA AVOID MMFR', 'SEKMA ARNAL UL465 GCM UG448 IKBIX Y183 PEAKY SRQ Y280 REDFN Q105 HRV', 'MPTO', 'KDFW', 'MPTO', 'MPZL', 'KDFW', 'ZFW', 'NB EAST'],
    ['PASA AVOID MMFR', 'SIMAN AROVI UM419 ASOKU UL655 NAU UJ34 MAM J25 CRP', 'MPTO', 'KIAH', 'MPTO', 'MPZL', 'KIAH', 'ZHU', 'NB WEST'],
    ['PASA AVOID MMFR', 'GIKPU UQ120 PADUD OPNIR OTAMO UA301 MLY UL417 BORDO', 'SKBO', 'KIAH', 'SKBO', 'SKEC', 'KIAH', 'ZHU', 'NB EAST'],
    ['PASA AVOID MMFR', 'VASIL UW36 DAKMO UM549 TBG UM419 ASOKU UL655 NAU UJ34 MAM J25 CRP', 'SKBO', 'KIAH', 'SKBO', 'SKEC', 'KIAH', 'ZHU', 'NB WEST'],
    ['PASA AVOID MMFR', 'GIKPU UQ120 PADUD XOGEN UM782 AGUJA UM782 ARNAL UM782 OMIRO UA321 DANUL UL333 PISAD L333 HRV J58 AEX', 'SKBO', 'KDFW', 'SKBO', 'SKEC', 'KDFW', 'ZFW', 'NB WEST'],
    ['PASA AVOID MMFR', 'VASIL UW36 DAKMO UM549 TBG UA321 SPP UA552 FALLA UM209 ANIKO UB881 CZM UA766 KEHLI A766 SBI', 'SKBO', 'KDFW', 'SKBO', 'SKEC', 'KDFW', 'ZFW', 'NB CENTRAL'],
    ['PASA AVOID MMFR', 'TAL UM542 ARNEL UL203 ALSAL UL318 IZT UJ34 MAM CRP', 'SPJC', 'KIAH', 'SPJC', 'SPIM', 'KIAH', 'ZHU', 'NB WEST'],
    ['PASA AVOID MMFR', 'TAL UM542 ATENO UL203 LIXAS UL203 ALSAL UL318 IZT UJ34 VER UA552 NAU TAJIN COPOS COAPA UJ34 ALUXU TULUN UJ34 MAM J25 SAT JEN KDFW', 'SPJC', 'KDFW', 'SPJC', 'SPIM', 'KDFW', 'ZFW', 'NB WEST'],
    ['PASA AVOID MMFR', 'LEV A770 KEHLI UA770 BETAS VOMAR UM782 CUN', 'KDFW', 'MMUN', 'KDFW', 'ZFW', 'MMUN', 'MMFR', 'SB WEST; SENEAM DATA'],
    ['PASA AVOID MMFR', 'CRP J25 MAM UT22 VESKO UT11 URTEL UT34 VOMAR UM782 CUN', 'KDFW', 'MMUN', 'KDFW', 'ZFW', 'MMUN', 'MMFR', 'SB CENTRAL; SENEAM DATA'],
    ['PASA AVOID MMFR', 'BRO VESKO UT11 URTEL UT34 VOMAR UM782 CUN', 'KIAH', 'MMUN', 'KIAH', 'ZHU', 'MMUN', 'MMFR', 'SB CENTRAL; SENEAM DATA'],
    ['PASA AVOID MMFR', 'LEV Y280 REMIS MCLAW Y442 FUNDI UM335 ALVEK UM328 UCL UG448 GCM ARNAL UL465 TBG BUXOS UL780 TRU UV1 ATATU', 'KDFW', 'SPJC', 'KDFW', 'ZFW', 'SPJC', 'SPIM', 'SB EAST'],
    ['PASA AVOID MMFR', 'SBI A766 KEHLI UL674 ATUVI UG448 GCM UL465 TBG', 'KDFW', 'MPTO', 'KDFW', 'ZFW', 'MPTO', 'MPZL', 'SB EAST'],
    ['PASA AVOID MMFR', 'CRP J25 BRO MAM UJ35 XOMLU UJ46 TAM UT46 CME MUVAP UH1 TIK UM787 KITIS UR899 PZA UA552 SPP UA321 TBG', 'KDFW', 'MPTO', 'KDFW', 'ZFW', 'MPTO', 'MPZL', 'SB CENTRAL; SENEAM DATA'],
    ['PASA AVOID MMFR', 'SAT J21 CVM UJ41 PCA UJ95 APN UJ39 PBC UL318 ALSAL UL200 LIB UG440 TBG', 'KDFW', 'MPTO', 'KDFW', 'ZFW', 'MPTO', 'MPZL', 'SB WEST'],
    ['PASA AVOID MMFR', 'BRO J25 MAM UJ34 NAU UL655 ASOKU UM419 AROVI VUMAN', 'KIAH', 'MPTO', 'KIAH', 'ZHU', 'MPTO', 'MPZL', 'SB WEST'],
    ['PASA AVOID MMFR', 'BRO J25 MAM UJ34 IZT UL423 ILTUR UQ122 TIRTO', 'KIAH', 'SKBO', 'KIAH', 'ZHU', 'SKBO', 'SKEC', 'SB WEST'],
    ['PASA AVOID MMFR', 'LEV BORDO UL210 PIBLO UR625 NEFTU UL417 MLY UA301 OTAMO UL542 BAQ LOKOV UG444 EJA UQ119 ILSEV', 'KIAH', 'SKBO', 'KIAH', 'ZHU', 'SKBO', 'SKEC', 'SB EAST'],
    ['PASA AVOID MMFR', 'FUNDI UM335 ALVEK UM328 UCL UG448 GCM UR640 MAMBI UL577 CZM', 'KMIA', 'MMUN', 'KMIA', 'ZMA', 'MMUN', 'MMFR', 'SB EAST'],
    ['PASA AVOID MMFR', 'HRV Q105 REDFN Y280 SRQ WULFF Q79 MCLAW Y442 FUNDI UM335 ALVEK UM328 UCL UG448 GCM UL465 ARNAL UM782 EJA UQ119 ILSEV', 'KDFW', 'SKBO', 'KDFW', 'ZFW', 'SKBO', 'SKEC', 'SB EAST'],
    ['PASA AVOID MMFR', 'SAT MAM UT22 PERGO UT23 CUN UM782 OMIRO UA321 TBG UM549 DAKMO UQ114 IVSAN UQ122 TIRTO', 'KDFW', 'SKBO', 'KDFW', 'ZFW', 'SKBO', 'SKEC', 'SB CENTRAL; Bay of Campeche variant'],
    ['PASA AVOID MMFR', 'SAT J21 CVM UJ41 PCA UJ95 APN UJ39 PBC UL318 ALSAL UL200 LIB UG440 TBG UM549 DAKMO UQ114 IVSAN UQ122 TIRTO', 'KDFW', 'SKBO', 'KDFW', 'ZFW', 'SKBO', 'SKEC', 'SB WEST'],
    ['PASA AVOID MMFR', 'HRV L333 PISAD UL333 DANUL UA321 SPP UB689 MQU UG430 PLG UT228 ILROL', 'KDFW', 'SPJC', 'KDFW', 'ZFW', 'SPJC', 'SPIM', 'SB WEST'],
    ['PASA AVOID MMFR', 'ACT CRP J25 BRO MAM UJ34 TULUN ALUXU UJ34 COAPA COPOS TAJIN NAU UA552 VER UJ34 IZT UL318 ALSAL UL203 LIXAS UL203 ARNEL UM542 TAL UV1 ATATU', 'KDFW', 'SPJC', 'KDFW', 'ZFW', 'SPJC', 'SPIM', 'SB WEST; SENEAM DATA'],
    ['PASA AVOID MMFR', 'NGP MAM UJ34 IZT UL318 ALSAL UL203 ARNEL UM542 TAL UV1 ATATU', 'KIAH', 'SPJC', 'KIAH', 'ZHU', 'SPJC', 'SPIM', 'SB WEST'],
    ['PASA AVOID MMFR', 'LEV BODLO UL337 NEGON L337 ARMUR UL337 POS UA324 MINDA UL452 BSI UZ6 NIMKI UZ38 MOXEP', 'KIAH', 'SBGR', 'KIAH', 'ZHU', 'SBGR', 'SBBS', 'SB EAST'],

    // --- PASA AVOID MHCC (5 routes) ---
    ['PASA AVOID MHCC', 'MIKUS UM542 SIA UR625 ENAMO Y307 CARPX G446 OLDEY SPIKY', 'MPTO', 'KATL', 'MPTO', 'MPZL', 'KATL', 'ZTL', 'NB EAST'],
    ['PASA AVOID MHCC', 'UV1 TRU UV5 TAP UT228 PLG UG444 EJA UL542 OTAMO UA301 MLY BIKOG UL674 KEHLI', 'SPJC', 'KIAH', 'SPJC', 'SPIM', 'KIAH', 'ZHU', 'NB EAST'],
    ['PASA AVOID MHCC', 'ISREN UL780 BUXOS TBG UL465 GCM UG448 ATUVI UL674 KEHLI A766 SBI', 'SPJC', 'KDFW', 'SPJC', 'SPIM', 'KDFW', 'ZFW', 'NB EAST'],
    ['PASA AVOID MHCC', 'SBI A766 KEHLI UL674 ATUVI UG448 GCM UL465 TBG', 'KDFW', 'MPTO', 'KDFW', 'ZFW', 'MPTO', 'MPZL', 'SB EAST'],
    ['PASA AVOID MHCC', 'MCLAW Y442 FUNDI UM335 ARNAL UL465 TBG', 'KATL', 'MPTO', 'KATL', 'ZTL', 'MPTO', 'MPZL', 'SB EAST'],

    // --- PASA AVOID TNCF (16 routes) ---
    ['PASA AVOID TNCF', 'UKBEV UZ26 BSI UZ26 BEL UA555 TRAPP UL454 ILURI A555 COY RTE4 BQN A636 KATOK UA636 PTA L463 JUELE', 'SBGR', 'KMIA', 'SBGR', 'SBBS', 'KMIA', 'ZMA', 'NB EAST; CGNA'],
    ['PASA AVOID TNCF', 'UKBEV UL201 ASTOB UL201 ISVOM UM656 BUVKA UM656 BNS UR640 MLY UL417 LENAX UL795 BEXEN UM347 ZEUSS', 'SBGR', 'KMIA', 'SBGR', 'SBBS', 'KMIA', 'ZMA', 'NB WEST; CGNA'],
    ['PASA AVOID TNCF', 'UKBEV UL201 ASTOB UM417 MOTVI UM549 MTU UM782 LONAX UL417 LENAX UL795 BEXEN UM347 ZEUSS Y217 OCTAL Q77 ETORE SHRKS LAIRI', 'SBGR', 'KATL', 'SBGR', 'SBBS', 'KATL', 'ZTL', 'NB WEST; CGNA'],
    ['PASA AVOID TNCF', 'UKBEV UL201 ASTOB ABIDE UM782 MTU UQ108 OTAMO UA301 MLY UL417 BORDO B760 ZBV RAMJT AR18 DIW', 'SBGR', 'KJFK', 'SBGR', 'SBBS', 'KJFK', 'ZNY', 'NB WEST; CGNA'],
    ['PASA AVOID TNCF', 'UKBEV UZ26 BSI UL452 ACARI UA312 LEPOD UG449 ANADA G449 DDP G431 ELMUC LAMER L453 PAEPR HOBOH SILLY', 'SBGR', 'KJFK', 'SBGR', 'SBBS', 'KJFK', 'ZNY', 'NB EAST; CGNA'],
    ['PASA AVOID TNCF', 'SCB UM415 EVNES ABIDE UM782 TAKUX DCT SUVUM UM782 KEHLI', 'SBGR', 'KIAH', 'SBGR', 'SBBS', 'KIAH', 'ZHU', 'NB WEST; CGNA'],
    ['PASA AVOID TNCF', 'KOMBO UW1 PIE UW34 LFA UW8 BRM UW14 BNA DAREK UA561 GND UA324 FOF UA312 ANU G633 COY RTE4 BQN A636 KATOK UA636 ALBBE', 'SKBO', 'KMIA', 'SKBO', 'SKEC', 'KMIA', 'ZMA', 'NB EAST'],
    ['PASA AVOID TNCF', 'GIKPU UQ120 PADUD DAGAN UL542 OTAMO UA301 MLY UL417 NEFTU UR625 ENAMO', 'SKBO', 'KMIA', 'SKBO', 'SKEC', 'KMIA', 'ZMA', 'NB WEST'],
    ['PASA AVOID TNCF', 'PIE UW34 LFA UW8 BRM PBL MIQ UDIMA MEGIR POS GND UA324 FOF UA312 ANU G633 COY RTE4 BQN A636 KATOK UA636 ALBBE', 'SKBO', 'KMIA', 'SKBO', 'SKEC', 'KMIA', 'ZMA', 'NB S+E'],
    ['PASA AVOID TNCF', 'ENAMO UR625 NEFTU UL417 MLY UA301 OTAMO UL542 BAQ UL542 EJA UQ119 ILSEV', 'KMIA', 'SKBO', 'KMIA', 'ZMA', 'SKBO', 'SKEC', 'SB WEST'],
    ['PASA AVOID TNCF', 'LEV BODLO UL337 NEGON L337 ARMUR UL337 POS UA324 MINDA UL452 BSI UZ6 NIMKI UZ38 MOXEP', 'KIAH', 'SBGR', 'KIAH', 'ZHU', 'SBGR', 'SBBS', 'SB EAST'],
    ['PASA AVOID TNCF', 'MUSYL L207 IPSEV UL207 LERIL UT11 CUN UM782 MTU UM549 VUBOM UL795 ETAXI UZ38 MOXEP', 'KIAH', 'SBGR', 'KIAH', 'ZHU', 'SBGR', 'SBBS', 'SB WEST'],
    ['PASA AVOID TNCF', 'DEBRL Q97 TOVAR Y297 URSUS UG430 BIBAT UL795 LENAX UL417 MLY UA301 OTAMO UQ108 MTU UL201 ARPAR UZ82 NEVKU UZ42 CPN', 'KATL', 'SBGR', 'KATL', 'ZTL', 'SBGR', 'SBBS', 'SB WEST'],
    ['PASA AVOID TNCF', 'BEANO G431 DDP G449 POS UA324 TIM UA312 ACARI UL452 BSI UZ6 NIMKI UZ38 MOXEP', 'KMIA', 'SBGR', 'KMIA', 'ZMA', 'SBGR', 'SBBS', 'SB EAST'],
    ['PASA AVOID TNCF', 'URSUS UG430 BIBAT UL795 LENAX UL417 MLY UA301 OTAMO UQ108 MTU ABIDE UM549 VUBOM UL795 ETAXI UZ38 MOXEP', 'KMIA', 'SBGR', 'KMIA', 'ZMA', 'SBGR', 'SBBS', 'SB WEST'],
    ['PASA AVOID TNCF', 'MCLAW Y442 FUNDI UM335 ARNAL UL465 TBG UL780 ISREN UL308 ATATU', 'KATL', 'SPJC', 'KATL', 'ZTL', 'SPJC', 'SPIM', 'SB WEST'],

    // --- PASA AVOID MDCS (8 routes) ---
    ['PASA AVOID MDCS', 'TTPP DCT POS UG449 ANADA DCT MEEGL Y421 HAGIT Y306 CHASO Y280 LEV NNCEE1 KIAH', 'TTPP', 'KIAH', 'TTPP', 'TTZP', 'KIAH', 'ZHU', 'NB N+E'],
    ['PASA AVOID MDCS', 'PIREX L462 ANU UA632 BGI UA555 TRAPP UL576 SIROS UL576 ISURO', 'KJFK', 'SBGR', 'KJFK', 'ZNY', 'SBGR', 'SBBS', 'SB EAST'],
    ['PASA AVOID MDCS', 'DIW OLDEY AR3 ZQA B503 ENAMO UR625 PIBLO UL210 AVADU UM218 DILAR UQ108 MTU UM549 ABIDE UL201 MABMA UZ8 EGONI UL795 ETAXI UZ38 MOXEP', 'KJFK', 'SBGR', 'KJFK', 'ZNY', 'SBGR', 'SBBS', 'SB WEST'],
    ['PASA AVOID MDCS', 'BEANO G431 DDP G449 POS UA324 TIM UA312 ACARI UL452 BSI UZ6 NIMKI', 'KMIA', 'SBGR', 'KMIA', 'ZMA', 'SBGR', 'SBBS', 'SB N+E'],
    ['PASA AVOID MDCS', 'FIPEK Y294 GESSO GEECE UL776 KORTO UG449 LEPOD UA312 ACARI UL452 BSI UZ6 NIMKI', 'KATL', 'SBGR', 'KATL', 'ZTL', 'SBGR', 'SBBS', 'SB N+E'],
    ['PASA AVOID MDCS', 'JAINS L451 ILIDO LNHOM L452 JORGG FIPEK Y294 GESSO GEECE UL776 KORTO UG449 LEPOD UA312 ACARI UL452 BSI UZ6 NIMKI UZ38 MOXEP', 'KATL', 'SBGR', 'KATL', 'ZTL', 'SBGR', 'SBBS', 'SB EAST'],
    ['PASA AVOID MDCS', 'DEBRL Q97 TOVAR Y297 URSUS G430 BIBAT UL795 ETAXI UZ38 MOXEP', 'KATL', 'SBGR', 'KATL', 'ZTL', 'SBGR', 'SBBS', 'SB WEST'],
    ['PASA AVOID MDCS', 'GUMBY3 LLA LEV Y290 HAGIT Y421 MEEGL ANADA UG449 PERGA ITRAK NAPKO LEXOR TALUS', 'KIAH', 'TTPP', 'KIAH', 'ZHU', 'TTPP', 'TTZP', 'SB N+E'],

    // --- PASA AVOID TJZS (4 routes) ---
    ['PASA AVOID TJZS', 'UKBEV UZ26 BSI UL452 ACARI UA312 DALGA BGI BNJEE ZABOR L462 PIREX BDA BOVIC L461 MARIG YAALE YETTI MOUGH DONAA OWENZ CAMRN KJFK', 'SBGR', 'KJFK', 'SBGR', 'SBBS', 'KJFK', 'ZNY', 'NB EAST; CGNA'],
    ['PASA AVOID TJZS', 'UKBEV UZ26 BSI UL452 ACARI UA312 LEPOD UG449 POS UL337 VUDAL UL337 GUTIM UA511 MOLOC UA567 BEROX L450 SEKAR', 'SBGR', 'KJFK', 'SBGR', 'SBBS', 'KJFK', 'ZNY', 'NB SW; CGNA'],
    ['PASA AVOID TJZS', 'UKBEV UZ26 BSI UL452 ACARI UA312 TIM UA324 POS UL337 VUDAL UL337 GUTIM UA511 MOLOC UA567 BEROX L450 SEKAR', 'SBGR', 'KJFK', 'SBGR', 'SBBS', 'KJFK', 'ZNY', 'NB SW; ICAO SAM DATA'],
    ['PASA AVOID TJZS', 'UKBEV UZ26 BSI UL452 ACARI UA312 LEPOD UG449 POS UL337 VUDAL UL337 GUTIM UA511 PENKO UA315 JOSES', 'SBGR', 'KMIA', 'SBGR', 'SBBS', 'KMIA', 'ZMA', 'NB SW; CGNA'],

    // --- PASA AVOID MTEG (6 routes) ---
    ['PASA AVOID MTEG', 'PIREX L462 ANU UA632 BGI UA555 TRAPP UL576 SIROS UL576 ISURO', 'KJFK', 'SBGR', 'KJFK', 'ZNY', 'SBGR', 'SBBS', 'SB EAST'],
    ['PASA AVOID MTEG', 'DIW OLDEY AR3 ZQA B503 ENAMO UR625 PIBLO UL210 AVADU UM218 DILAR UQ108 MTU UM549 ABIDE UL201 MABMA UZ8 EGONI UL795 ETAXI UZ38 MOXEP', 'KJFK', 'SBGR', 'KJFK', 'ZNY', 'SBGR', 'SBBS', 'SB WEST'],
    ['PASA AVOID MTEG', 'BEANO G431 DDP G449 POS UA324 TIM UA312 ACARI UL452 BSI UZ6 NIMKI', 'KMIA', 'SBGR', 'KMIA', 'ZMA', 'SBGR', 'SBBS', 'SB N+E'],
    ['PASA AVOID MTEG', 'FIPEK Y294 GESSO GEECE UL776 KORTO UG449 LEPOD UA312 ACARI UL452 BSI UZ6 NIMKI', 'KATL', 'SBGR', 'KATL', 'ZTL', 'SBGR', 'SBBS', 'SB N+E'],
    ['PASA AVOID MTEG', 'JAINS L451 ILIDO LNHOM L452 JORGG FIPEK Y294 GESSO GEECE UL776 KORTO UG449 LEPOD UA312 ACARI UL452 BSI UZ6 NIMKI UZ38 MOXEP', 'KATL', 'SBGR', 'KATL', 'ZTL', 'SBGR', 'SBBS', 'SB EAST'],
    ['PASA AVOID MTEG', 'DEBRL Q97 TOVAR Y297 URSUS G430 BIBAT UL795 ETAXI UZ38 MOXEP', 'KATL', 'SBGR', 'KATL', 'ZTL', 'SBGR', 'SBBS', 'SB WEST'],

    // --- PASA AVOID TTZP (2 routes) ---
    ['PASA AVOID TTZP', 'UKBEV UZ26 BSI UL452 GIGTI UM409 ILSUB UM417 MIQ UA315 JOSES Y398 ZIN Y353 SUMAC Y280 OCTAL Q77 ETORE SHRKS LAIRI', 'SBGR', 'KATL', 'SBGR', 'SBBS', 'KATL', 'ZTL', 'NB WEST; CGNA; ZMA FEEDBACK'],
    ['PASA AVOID TTZP', 'JOSES UL304 DAVBA UW27 GNA UM423 PAKON UM423 EGONI UL795 ETAXI UZ38 MOXEP', 'KMIA', 'SBGR', 'KMIA', 'ZMA', 'SBGR', 'SBBS', 'SB WEST'],

    // --- PASA AVOID SVZM (5 routes) ---
    ['PASA AVOID SVZM', 'UKBEV UZ26 BSI GIGTI UL452 ACARI UA312 LEPOD UG449 ANADA L452 HARBG Y421 HAGIT Y306 VENDS Y185 MANLE Q89 SHRKS LAIRI', 'SBGR', 'KATL', 'SBGR', 'SBBS', 'KATL', 'ZTL', 'NB EAST; CGNA; ZMA FEEDBACK'],
    ['PASA AVOID SVZM', 'DIW OLDEY AR3 ZQA B503 ENAMO UR625 PIBLO UL210 AVADU UM218 DILAR UQ108 MTU UM549 ABIDE UL201 MABMA UZ8 EGONI UL795 ETAXI UZ38 MOXEP', 'KJFK', 'SBGR', 'KJFK', 'ZNY', 'SBGR', 'SBBS', 'SB WEST'],
    ['PASA AVOID SVZM', 'PIREX L462 ANU UA632 BGI UA555 TRAPP UL576 SIROS UL576 ISURO UZ38 MOXEP', 'KJFK', 'SBGR', 'KJFK', 'ZNY', 'SBGR', 'SBBS', 'SB EAST'],
    ['PASA AVOID SVZM', 'JAINS L451 ILIDO LNHOM L452 JORGG FIPEK Y294 GESSO GEECE UL776 KORTO UG449 LEPOD UA312 ACARI UL452 BSI UZ6 NIMKI UZ38 MOXEP', 'KATL', 'SBGR', 'KATL', 'ZTL', 'SBGR', 'SBBS', 'SB EAST'],
    ['PASA AVOID SVZM', 'JOSES UL304 DAVBA UW27 GNA UM423 PAKON UM423 EGONI UL795 ETAXI UZ38 MOXEP', 'KMIA', 'SBGR', 'KMIA', 'ZMA', 'SBGR', 'SBBS', 'SB WEST'],

    // --- PASA AVOID SKEC (1 route) ---
    ['PASA AVOID SKEC', 'UKBEV UZ26 BSI POS UL337 GUTIM UA511 MLY BIKOG UL674 KEHLI', 'SBGR', 'KIAH', 'SBGR', 'SBBS', 'KIAH', 'ZHU', 'NB EAST; CGNA; SENEAM'],

    // --- PASA AVOID SPIM (4 routes) ---
    ['PASA AVOID SPIM', 'BIVAM UW8 PAR UL417 BORDO FOWEE', 'SAEZ', 'KMIA', 'SAEZ', 'SAEF', 'KMIA', 'ZMA', 'NB EAST'],
    ['PASA AVOID SPIM', 'ASIMO UL309 EMPEX UL309 GEKOR UL309 IROBO UL309 UT771 ELAMU UL309 RBR UL417 ARUXA UL417 PABON POVSO ISVAT KILER UCA URSUS', 'SCEL', 'KMIA', 'SCEL', 'SCEZ', 'KMIA', 'ZMA', 'NB EAST'],
    ['PASA AVOID SPIM', 'EONNS A509 URSUS UG430 BIBAT UL795 LENAX UL417 LOKOX UM784 SIS UL793 GUA UW65 PAGON', 'KMIA', 'SAEZ', 'KMIA', 'ZMA', 'SAEZ', 'SAEF', 'SB EAST'],
    ['PASA AVOID SPIM', 'EONNS A509 URSUS UG430 KILER UQ121 RNG DCT ISVAT POVSO PABON ARUXA UL417 PUBUM ALC UW22 TAR GAXOK UL322 SAL ASIMO', 'KMIA', 'SCEL', 'KMIA', 'ZMA', 'SCEL', 'SCEZ', 'SB EAST'],

    // --- PASA AVOID KZHU (8 routes) ---
    ['PASA AVOID KZHU', 'AXOMU UM346 CANOA', 'VARIOUS', 'VARIOUS', '', '', '', '', 'EB EAST; Bidirectional'],
    ['PASA AVOID KZHU', 'CTM ANIKO CZM UG765 FIS', 'VARIOUS', 'VARIOUS', '', '', '', '', 'EB EAST; Bidirectional'],
    ['PASA AVOID KZHU', 'MMUN NUDAL UV106 FRANT UG765 FIS', 'MMUN', 'VARIOUS', 'MMUN', 'MMFR', '', '', 'EB EAST'],
    ['PASA AVOID KZHU', 'CUN UJ18 URTOK UG765 MAXIM G765 FIS PLYER JAWJA DAWWN', 'MMUN', 'KATL', 'MMUN', 'MMFR', 'KATL', 'ZTL', 'EB EAST; ZMA FEEDBACK'],
    ['PASA AVOID KZHU', 'WALET YUESS OTK TEPEE BRDGE RSW KARTR Q81 TUNSL Y196 CANOA UB879 CUN', 'KATL', 'MMUN', 'KATL', 'ZTL', 'MMUN', 'MMFR', 'WB EAST; SENEAM DATA'],
    ['PASA AVOID KZHU', 'ENDEW Q81 TUNSL Y196 CANOA UM346 AXOMU UR522 PAZ UJ55 DATUL', 'VARIOUS', 'MMMX', '', '', 'MMMX', 'MMEX', 'WB EAST; From ZMA'],
    ['PASA AVOID KZHU', 'MOV MRF', 'VARIOUS', 'VARIOUS', '', '', '', '', 'WB WEST; Segment only'],
    ['PASA AVOID KZHU', 'CANOA ELUNI COFRE UM506 SESNO UJ15 MOV UJ2 CUU UJ47 CJS ELP', 'VARIOUS', 'VARIOUS', '', '', '', '', 'WB WEST; From ZMA to ZAB'],

    // --- PASA AVOID KZMA (3 routes) ---
    ['PASA AVOID KZMA', 'CHUMA KINCH L455', 'MDSD', 'KJFK', 'MDSD', 'MDCS', 'KJFK', 'ZNY', 'NB EAST'],
    ['PASA AVOID KZMA', 'CHUMA KINCH L455', 'MDST', 'KJFK', 'MDST', 'MDCS', 'KJFK', 'ZNY', 'NB EAST'],
    ['PASA AVOID KZMA', 'A636 RETAK ALBBE URLAM', 'VARIOUS', 'VARIOUS', '', '', '', '', 'WB SOUTH; Segment to destination'],

    // --- PASA GTK RADAR OOS (17 routes) ---
    ['PASA GTK RADAR OOS', 'ASIVO L453 LAMER', 'MDSD', 'VARIOUS', 'MDSD', 'MDCS', '', '', 'NB; From DR'],
    ['PASA GTK RADAR OOS', 'BETIR M597 JANMA', 'MDSD', 'VARIOUS', 'MDSD', 'MDCS', '', '', 'NB; From DR'],
    ['PASA GTK RADAR OOS', 'POKEG B891 MACKI L453 LAMER', 'MDSD', 'VARIOUS', 'MDSD', 'MDCS', '', '', 'NB; From DR'],
    ['PASA GTK RADAR OOS', 'ELMUC G431 CERDA L453 LAMER', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB; Caribbean/SAMER to NY'],
    ['PASA GTK RADAR OOS', 'DONQU L454 LUCTI', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB; Caribbean/SAMER to NY; or SAPPO'],
    ['PASA GTK RADAR OOS', 'R507 GTK A555 ZQA', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB; Caribbean/SAMER to NY'],
    ['PASA GTK RADAR OOS', 'ALBBE M348 KNSLY ZQA', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB; Caribbean/SAMER to NY'],
    ['PASA GTK RADAR OOS', 'LETON R763 GTK', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB; NY Oceanic'],
    ['PASA GTK RADAR OOS', 'ZFP BR1L GTK', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB; NY Oceanic'],
    ['PASA GTK RADAR OOS', 'BRRGO BR1L GTK', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB; NY Oceanic'],
    ['PASA GTK RADAR OOS', 'FIVZE M597 BETIR', 'VARIOUS', 'VARIOUS', '', '', '', '', 'NB; NY Oceanic'],
    ['PASA GTK RADAR OOS', 'NUCAR L463 BRRGO BR1L GTK', 'VARIOUS', 'VARIOUS', '', '', '', '', 'SB'],
    ['PASA GTK RADAR OOS', 'ZFP BR1L GTK', 'VARIOUS', 'VARIOUS', '', '', '', '', 'SB; or BITAC INDEE'],
    ['PASA GTK RADAR OOS', 'A555 GTK', 'VARIOUS', 'VARIOUS', '', '', '', '', 'SB'],
    ['PASA GTK RADAR OOS', 'ZBV A315 KNSLY', 'VARIOUS', 'VARIOUS', '', '', '', '', 'SB'],
    ['PASA GTK RADAR OOS', 'ZQA ALBBE M348 PTA', 'VARIOUS', 'VARIOUS', '', '', '-MDST -MDPP', 'MDCS', 'SB; To DR'],
    ['PASA GTK RADAR OOS', 'ALBBE M348 RETAK', 'VARIOUS', 'MDPC', '', '', 'MDPC', 'MDCS', 'SB; To DR; or ZQA A555 GTK A554 SEKAR'],
];

// ============================================================================
// IMPORT EXECUTION
// ============================================================================

$total_routes = count($routes);
$play_count = count($plays);
echo "CADENA PASA Import: $play_count plays, $total_routes routes\n";
flush();

// Check existing
$existing = (int)$pdo->query("SELECT COUNT(*) FROM playbook_plays WHERE source='CADENA' AND org_code='CADENA'")->fetchColumn();
$is_reimport = $existing > 0;
echo $is_reimport ? "Re-import ($existing existing CADENA plays)\n" : "First import\n";
flush();

$pdo->beginTransaction();

try {
    if ($is_reimport) {
        $pdo->exec("DELETE FROM playbook_changelog WHERE play_id IN (SELECT play_id FROM playbook_plays WHERE source='CADENA' AND org_code='CADENA')");
        $pdo->exec("DELETE FROM playbook_plays WHERE source='CADENA' AND org_code='CADENA'");
        echo "Deleted existing CADENA data\n";
        flush();
    }

    // Insert plays
    $play_ids = [];
    $play_stmt = $pdo->prepare("INSERT INTO playbook_plays
        (play_name, play_name_norm, display_name, description, category,
         impacted_area, remarks, facilities_involved, route_format,
         source, status, route_count, org_code, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,'standard','CADENA','active',?,?,'import',NOW())");

    foreach ($plays as $pn => $pd) {
        // Count routes for this play
        $rc = 0;
        foreach ($routes as $r) { if ($r[0] === $pn) $rc++; }

        $play_stmt->execute([
            $pn,
            normPlay($pn),
            $pd['display_name'],
            $pd['description'],
            'CADENA PASA',
            $pd['impacted_area'],
            $pd['remarks'],
            normalizeCanadianArtccCsv($pd['facilities_involved']),
            $rc,
            'CADENA',
        ]);
        $play_ids[$pn] = (int)$pdo->lastInsertId();
    }
    echo "Inserted $play_count plays\n";
    flush();

    // Insert routes in batches
    $route_batch = [];
    $sort_counters = [];

    foreach ($routes as $r) {
        $pn = $r[0];
        $pid = $play_ids[$pn];
        if (!isset($sort_counters[$pn])) $sort_counters[$pn] = 0;

        $route_batch[] = [
            $pid, $r[1], $r[2], $r[3],
            $r[4], normalizeCanadianArtccCsv($r[5]),
            $r[6], normalizeCanadianArtccCsv($r[7]),
            $r[8], $sort_counters[$pn]++
        ];

        if (count($route_batch) >= 100) {
            $vals = [];
            $params = [];
            foreach ($route_batch as $rb) {
                $vals[] = "(?,?,?,?,?,?,?,?,?,?)";
                $params = array_merge($params, $rb);
            }
            $pdo->prepare("INSERT INTO playbook_routes (play_id, route_string, origin, dest, origin_airports, origin_artccs, dest_airports, dest_artccs, remarks, sort_order) VALUES " . implode(',', $vals))->execute($params);
            $route_batch = [];
        }
    }

    // Flush remaining
    if (count($route_batch) > 0) {
        $vals = [];
        $params = [];
        foreach ($route_batch as $rb) {
            $vals[] = "(?,?,?,?,?,?,?,?,?,?)";
            $params = array_merge($params, $rb);
        }
        $pdo->prepare("INSERT INTO playbook_routes (play_id, route_string, origin, dest, origin_airports, origin_artccs, dest_airports, dest_artccs, remarks, sort_order) VALUES " . implode(',', $vals))->execute($params);
    }

    echo "Inserted $total_routes routes\n";
    flush();

    // Changelog entries
    $action = $is_reimport ? 'faa_reimport' : 'faa_import';
    $cl_vals = [];
    $cl_params = [];
    foreach ($play_ids as $pn => $pid) {
        $cl_vals[] = "(?,'$action','import',NOW())";
        $cl_params[] = $pid;
    }
    $pdo->prepare("INSERT INTO playbook_changelog (play_id, action, changed_by, changed_at) VALUES " . implode(',', $cl_vals))->execute($cl_params);

    $pdo->commit();
    echo "\nDone: $play_count plays, $total_routes routes, " . count($play_ids) . " changelog entries\n";

    // Verify
    $verify = $pdo->query("SELECT COUNT(*) AS plays FROM playbook_plays WHERE source='CADENA'")->fetch();
    $verify_r = $pdo->query("SELECT COUNT(*) AS routes FROM playbook_routes WHERE play_id IN (SELECT play_id FROM playbook_plays WHERE source='CADENA')")->fetch();
    echo "Verify: {$verify['plays']} plays, {$verify_r['routes']} routes\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "FAILED (rolled back): " . $e->getMessage() . "\n";
}
