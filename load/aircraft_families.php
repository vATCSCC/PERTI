<?php
/**
 * Aircraft Family Definitions
 *
 * Maps family keys to arrays of ICAO type designators.
 * Family display names are in assets/locales/ i18n files under aircraft.families.<key>
 */

$AIRCRAFT_FAMILIES = [
    // Airbus narrowbody
    'a220'  => ['BCS1', 'BCS3', 'A220'],
    'a318'  => ['A318'],
    'a319'  => ['A319', 'A19N'],
    'a320'  => ['A320', 'A20N', 'A320N', 'A32N'],
    'a321'  => ['A321', 'A21N'],
    // Airbus widebody
    'a300'  => ['A30B', 'A306', 'A300', 'A30F'],
    'a310'  => ['A310'],
    'a330'  => ['A332', 'A333', 'A337', 'A338', 'A339', 'A330'],
    'a340'  => ['A342', 'A343', 'A345', 'A346', 'A340'],
    'a350'  => ['A359', 'A35K', 'A350'],
    'a380'  => ['A388', 'A380'],
    // Boeing narrowbody
    'b717'  => ['B712', 'B717'],
    'b727'  => ['B721', 'B722', 'B727'],
    'b737'  => ['B731', 'B732', 'B733', 'B734', 'B735', 'B736', 'B737', 'B738', 'B739'],
    'b737max' => ['B37M', 'B38M', 'B39M', 'B3XM', 'B7M8', 'B7M9'],
    'b757'  => ['B752', 'B753', 'B757'],
    // Boeing widebody
    'b747'  => ['B741', 'B742', 'B743', 'B744', 'B748', 'B747'],
    'b767'  => ['B762', 'B763', 'B764', 'B767'],
    'b777'  => ['B772', 'B77L', 'B77W', 'B773', 'B778', 'B779', 'B777'],
    'b787'  => ['B788', 'B789', 'B78X', 'B787'],
    // Douglas / McDonnell Douglas
    'dc10'  => ['DC10', 'D10'],
    'md11'  => ['MD11'],
    'md80'  => ['MD81', 'MD82', 'MD83', 'MD87', 'MD88', 'MD80'],
    'md90'  => ['MD90'],
    // Regional jets
    'crj'   => ['CRJ1', 'CRJ2', 'CRJ7', 'CRJ9', 'CRJX', 'CRJ'],
    'erj'   => ['E135', 'E145', 'E170', 'E175', 'E190', 'E195', 'E75L', 'E75S', 'E290', 'E295'],
    // Turboprops
    'atr'   => ['AT43', 'AT45', 'AT72', 'AT76', 'ATR'],
    'dash8' => ['DH8A', 'DH8B', 'DH8C', 'DH8D', 'DHC8'],
    // Business jets
    'gulfstream' => ['G150', 'G200', 'G280', 'GLF4', 'GLF5', 'GLF6', 'GLEX', 'G500', 'G550', 'G600', 'G650', 'G700', 'G800'],
    'citation' => ['C25A', 'C25B', 'C25C', 'C25M', 'C510', 'C525', 'C550', 'C551', 'C560', 'C56X', 'C650', 'C680', 'C68A', 'C700', 'C750'],
    'challenger' => ['CL30', 'CL35', 'CL60', 'CL61', 'CL3T', 'CL64'],
    'global' => ['GL5T', 'GL7T', 'GLEX'],
    'learjet' => ['LJ23', 'LJ24', 'LJ25', 'LJ28', 'LJ31', 'LJ35', 'LJ40', 'LJ45', 'LJ55', 'LJ60', 'LJ70', 'LJ75'],
    'phenom' => ['E50P', 'E55P'],
    // Mil / cargo
    'c130'  => ['C130', 'C30J', 'L100'],
    'c17'   => ['C17'],
];

/**
 * Build reverse lookup: ICAO code -> family key
 */
function getIcaoToFamilyMap(): array {
    global $AIRCRAFT_FAMILIES;
    $map = [];
    foreach ($AIRCRAFT_FAMILIES as $family => $codes) {
        foreach ($codes as $code) {
            $map[$code] = $family;
        }
    }
    return $map;
}
