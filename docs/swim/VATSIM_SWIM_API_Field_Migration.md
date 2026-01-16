# VATSIM SWIM API Field Migration
## FIXM/TFMS-Compliant Field Names

**Version:** 1.0  
**Date:** 2026-01-16  
**Scope:** SWIM API JSON response field names only (not internal database columns)

---

## Overview

This document maps the **current SWIM API response field names** to **FIXM/TFMS-compliant names** for standardization. The underlying database columns (ADL and swim_flights) remain unchanged - only the API output layer transforms field names.

### Naming Conventions

| Layer | Convention | Example |
|-------|------------|---------|
| **FIXM** | camelCase | `actualOffBlockTime` |
| **TFMS** | UPPERCASE | `AOBT` |
| **vATCSCC Extension** | camelCase with `vATCSCC:` prefix | `vATCSCC:pilotCid` |
| **SWIM API (JSON)** | snake_case | `actual_off_block_time` |

---

## Section 1: Root Level Fields

| Current | New (FIXM-aligned) | FIXM Reference | Notes |
|---------|-------------------|----------------|-------|
| `gufi` | `gufi` | `gufi` | ✅ Keep |
| `flight_uid` | `flight_uid` | — | ✅ Keep (internal) |
| `flight_key` | `flight_key` | — | ✅ Keep (internal) |
| `_source` | `data_source` | `vATCSCC:dataSource` | 🔄 Rename |
| `_first_seen` | `first_tracked_time` | `vATCSCC:firstTrackedTime` | 🔄 Rename |
| `_last_seen` | `position_time` | `positionTime` | 🔄 Rename |
| `_logon_time` | `logon_time` | `vATCSCC:logonTime` | 🔄 Rename |
| `_last_sync` | `last_sync_time` | `vATCSCC:lastSyncTime` | 🔄 Rename |

---

## Section 2: Identity Block

| Current | New (FIXM-aligned) | FIXM Reference | Notes |
|---------|-------------------|----------------|-------|
| `identity.callsign` | `identity.aircraft_identification` | `aircraftIdentification` | 🔄 Rename |
| `identity.cid` | `identity.pilot_cid` | `vATCSCC:pilotCid` | 🔄 Rename |
| `identity.aircraft_type` | `identity.aircraft_type` | `aircraftType` | ✅ Keep |
| `identity.aircraft_icao` | `identity.aircraft_type_icao` | `aircraftType` | 🔄 Clarify |
| `identity.aircraft_faa` | `identity.aircraft_type_faa` | `otherAircraftType` | 🔄 Clarify |
| `identity.weight_class` | `identity.weight_class` | `nas:weightClass` | ✅ Keep |
| `identity.wake_category` | `identity.wake_turbulence` | `wakeTurbulence` | 🔄 Rename |
| `identity.airline_icao` | `identity.operator_icao` | `operatorIcaoDesignator` | 🔄 Rename |
| `identity.airline_name` | `identity.operator_name` | `operatorName` | 🔄 Rename |

---

## Section 3: Flight Plan Block

| Current | New (FIXM-aligned) | FIXM Reference | Notes |
|---------|-------------------|----------------|-------|
| `flight_plan.departure` | `flight_plan.departure_aerodrome` | `departureAerodrome` | 🔄 Rename |
| `flight_plan.destination` | `flight_plan.arrival_aerodrome` | `arrivalAerodrome` | 🔄 Rename |
| `flight_plan.alternate` | `flight_plan.alternate_aerodrome` | `alternateAerodrome` | 🔄 Rename |
| `flight_plan.cruise_altitude` | `flight_plan.cruising_level` | `cruisingLevel` | 🔄 Rename |
| `flight_plan.cruise_speed` | `flight_plan.cruising_speed` | `cruisingSpeed` | 🔄 Rename |
| `flight_plan.route` | `flight_plan.route_text` | `routeText` | 🔄 Rename |
| `flight_plan.remarks` | `flight_plan.remarks` | `remarks` | ✅ Keep |
| `flight_plan.flight_rules` | `flight_plan.flight_rules_category` | `flightRulesCategory` | 🔄 Rename |
| `flight_plan.departure_artcc` | `flight_plan.departure_airspace` | `departureAirspace` | 🔄 Rename |
| `flight_plan.destination_artcc` | `flight_plan.arrival_airspace` | `arrivalAirspace` | 🔄 Rename |
| `flight_plan.departure_tracon` | `flight_plan.departure_tracon` | `vATCSCC:departureTracon` | ✅ Keep |
| `flight_plan.destination_tracon` | `flight_plan.arrival_tracon` | `vATCSCC:arrivalTracon` | 🔄 Rename |
| `flight_plan.departure_fix` | `flight_plan.departure_point` | `departurePoint` | 🔄 Rename |
| `flight_plan.departure_procedure` | `flight_plan.sid` | `standardInstrumentDeparture` | 🔄 Rename |
| `flight_plan.arrival_fix` | `flight_plan.arrival_point` | `arrivalPoint` | 🔄 Rename |
| `flight_plan.arrival_procedure` | `flight_plan.star` | `standardInstrumentArrival` | 🔄 Rename |
| `flight_plan.departure_runway` | `flight_plan.departure_runway` | `departureRunway` | ✅ Keep |
| `flight_plan.arrival_runway` | `flight_plan.arrival_runway` | `arrivalRunway` | ✅ Keep |

---

## Section 4: Position Block

| Current | New (FIXM-aligned) | FIXM Reference | Notes |
|---------|-------------------|----------------|-------|
| `position.latitude` | `position.latitude` | `position/latitude` | ✅ Keep |
| `position.longitude` | `position.longitude` | `position/longitude` | ✅ Keep |
| `position.altitude_ft` | `position.altitude` | `altitude` | 🔄 Simplify |
| `position.heading` | `position.track` | `track` | 🔄 Rename |
| `position.ground_speed_kts` | `position.ground_speed` | `groundSpeed` | 🔄 Remove unit suffix |
| `position.true_airspeed_kts` | `position.true_airspeed` | `trueAirspeed` | 🔄 Remove unit suffix |
| `position.vertical_rate_fpm` | `position.vertical_rate` | `verticalRate` | 🔄 Remove unit suffix |
| `position.current_artcc` | `position.current_airspace` | `currentAirspace` | 🔄 Rename |
| `position.current_tracon` | `position.current_tracon` | `vATCSCC:currentTracon` | ✅ Keep |
| `position.current_zone` | `position.current_airport_zone` | `vATCSCC:currentAirportZone` | 🔄 Rename |

---

## Section 5: Progress Block

| Current | New (FIXM-aligned) | FIXM Reference | Notes |
|---------|-------------------|----------------|-------|
| `progress.phase` | `progress.flight_status` | `flightStatus` | 🔄 Rename |
| `progress.is_active` | `progress.is_active` | `vATCSCC:isActive` | ✅ Keep |
| `progress.distance_remaining_nm` | `progress.distance_to_destination` | `distanceToDestination` | 🔄 Rename |
| `progress.distance_flown_nm` | `progress.distance_flown` | `distanceFlown` | 🔄 Rename |
| `progress.gcd_nm` | `progress.great_circle_distance` | `greatCircleDistance` | 🔄 Rename |
| `progress.route_total_nm` | `progress.total_flight_distance` | `totalFlightDistance` | 🔄 Rename |
| `progress.pct_complete` | `progress.percent_complete` | `vATCSCC:percentComplete` | 🔄 Rename |
| `progress.time_to_dest_min` | `progress.time_to_destination` | `vATCSCC:timeToDestination` | 🔄 Rename |

---

## Section 6: Times Block

| Current | New (FIXM-aligned) | FIXM Reference | TFMS | Notes |
|---------|-------------------|----------------|------|-------|
| `times.etd` | `times.estimated_off_block_time` | `estimatedOffBlockTime` | `EOBT` | 🔄 Rename |
| `times.etd_runway` | `times.estimated_time_of_departure` | `estimatedTimeOfDeparture` | `ETD` | 🔄 Rename |
| `times.eta` | `times.estimated_time_of_arrival` | `estimatedTimeOfArrival` | `ETA` | 🔄 Rename |
| `times.eta_runway` | `times.estimated_runway_arrival` | `vATCSCC:estimatedRunwayArrival` | — | 🔄 Rename |
| `times.eta_source` | `times.eta_source` | `vATCSCC:etaSource` | — | ✅ Keep |
| `times.eta_method` | `times.eta_method` | `vATCSCC:etaMethod` | — | ✅ Keep |
| `times.ete_minutes` | `times.estimated_elapsed_time` | `estimatedElapsedTime` | `ETE` | 🔄 Rename |
| `times.out` | `times.actual_off_block_time` | `actualOffBlockTime` | `AOBT` | 🔄 Rename |
| `times.off` | `times.actual_time_of_departure` | `actualTimeOfDeparture` | `ATOT` | 🔄 Rename |
| `times.on` | `times.actual_landing_time` | `actualLandingTime` | `ALDT` | 🔄 Rename |
| `times.in` | `times.actual_in_block_time` | `actualInBlockTime` | `AIBT` | 🔄 Rename |
| `times.ctd` | `times.controlled_time_of_departure` | `controlledTimeOfDeparture` | `CTD` | 🔄 Rename |
| `times.cta` | `times.controlled_time_of_arrival` | `controlledTimeOfArrival` | `CTA` | 🔄 Rename |
| `times.edct` | `times.edct` | `expectedDepartureClearanceTime` | `EDCT` | ✅ Keep (standard) |

---

## Section 7: TMI Block

| Current | New (FIXM-aligned) | FIXM Reference | Notes |
|---------|-------------------|----------------|-------|
| `tmi.is_controlled` | `tmi.is_controlled` | `vATCSCC:isControlled` | ✅ Keep |
| `tmi.ground_stop_held` | `tmi.ground_stop_held` | `groundStopHeld` | ✅ Keep |
| `tmi.gs_release` | `tmi.ground_stop_release_time` | `vATCSCC:groundStopReleaseTime` | 🔄 Rename |
| `tmi.control_type` | `tmi.control_type` | `controlType` | ✅ Keep |
| `tmi.control_program` | `tmi.program_name` | `programName` | 🔄 Rename |
| `tmi.control_element` | `tmi.control_element` | `controlElement` | ✅ Keep |
| `tmi.is_exempt` | `tmi.exempt_indicator` | `exemptIndicator` | 🔄 Rename |
| `tmi.exempt_reason` | `tmi.exempt_reason` | `exemptReason` | ✅ Keep |
| `tmi.delay_minutes` | `tmi.delay_value` | `delayValue` | 🔄 Rename |
| `tmi.delay_status` | `tmi.delay_status` | `delayStatus` | ✅ Keep |
| `tmi.slot_time` | `tmi.slot_time` | `slotTime` | ✅ Keep |
| `tmi.program_id` | `tmi.program_id` | `vATCSCC:programId` | ✅ Keep |
| `tmi.slot_id` | `tmi.slot_id` | `vATCSCC:slotId` | ✅ Keep |

---

## Migration Summary

| Section | Total Fields | Keep | Rename | Remove |
|---------|--------------|------|--------|--------|
| Root | 7 | 3 | 4 | 0 |
| Identity | 9 | 3 | 6 | 0 |
| Flight Plan | 18 | 6 | 12 | 0 |
| Position | 10 | 4 | 6 | 0 |
| Progress | 8 | 1 | 7 | 0 |
| Times | 14 | 4 | 10 | 0 |
| TMI | 13 | 8 | 5 | 0 |
| **TOTAL** | **79** | **29** | **50** | **0** |

---

## PHP Implementation

Update `formatFlightRecord()` in `api/swim/v1/flights.php`:

```php
function formatFlightRecord($row, $use_swim_db = false) {
    $gufi = $row['gufi'] ?? swim_generate_gufi($row['callsign'], $row['fp_dept_icao'], $row['fp_dest_icao']);
    
    // Calculate time to destination
    $time_to_dest = null;
    if ($row['groundspeed_kts'] > 50 && $row['dist_to_dest_nm'] > 0) {
        $time_to_dest = round(($row['dist_to_dest_nm'] / $row['groundspeed_kts']) * 60, 1);
    } elseif ($row['ete_minutes']) {
        $time_to_dest = $row['ete_minutes'];
    }
    
    $result = [
        // Root level - FIXM aligned
        'gufi' => $gufi,
        'flight_uid' => $row['flight_uid'],
        'flight_key' => $row['flight_key'],
        
        // Identity - FIXM aligned
        'identity' => [
            'aircraft_identification' => $row['callsign'],       // was: callsign
            'pilot_cid' => $row['cid'],                          // was: cid
            'aircraft_type' => $row['aircraft_type'],
            'aircraft_type_icao' => $row['aircraft_icao'],       // was: aircraft_icao
            'aircraft_type_faa' => $row['aircraft_faa'],         // was: aircraft_faa
            'weight_class' => $row['weight_class'],
            'wake_turbulence' => $row['wake_category'],          // was: wake_category
            'operator_icao' => $row['airline_icao'],             // was: airline_icao
            'operator_name' => $row['airline_name']              // was: airline_name
        ],
        
        // Flight Plan - FIXM aligned
        'flight_plan' => [
            'departure_aerodrome' => trim($row['fp_dept_icao'] ?? ''),   // was: departure
            'arrival_aerodrome' => trim($row['fp_dest_icao'] ?? ''),     // was: destination
            'alternate_aerodrome' => trim($row['fp_alt_icao'] ?? ''),    // was: alternate
            'cruising_level' => $row['fp_altitude_ft'],                  // was: cruise_altitude
            'cruising_speed' => $row['fp_tas_kts'],                      // was: cruise_speed
            'route_text' => $row['fp_route'],                            // was: route
            'remarks' => $row['fp_remarks'],
            'flight_rules_category' => $row['fp_rule'],                  // was: flight_rules
            'departure_airspace' => $row['fp_dept_artcc'],               // was: departure_artcc
            'arrival_airspace' => $row['fp_dest_artcc'],                 // was: destination_artcc
            'departure_tracon' => $row['fp_dept_tracon'],
            'arrival_tracon' => $row['fp_dest_tracon'],                  // was: destination_tracon
            'departure_point' => $row['dfix'],                           // was: departure_fix
            'sid' => $row['dp_name'],                                    // was: departure_procedure
            'arrival_point' => $row['afix'],                             // was: arrival_fix
            'star' => $row['star_name'],                                 // was: arrival_procedure
            'departure_runway' => $row['dep_runway'],
            'arrival_runway' => $row['arr_runway']
        ],
        
        // Position - FIXM aligned
        'position' => [
            'latitude' => $row['lat'] !== null ? floatval($row['lat']) : null,
            'longitude' => $row['lon'] !== null ? floatval($row['lon']) : null,
            'altitude' => $row['altitude_ft'],                           // was: altitude_ft
            'track' => $row['heading_deg'],                              // was: heading
            'ground_speed' => $row['groundspeed_kts'],                   // was: ground_speed_kts
            'true_airspeed' => $row['true_airspeed_kts'] ?? null,        // was: true_airspeed_kts
            'vertical_rate' => $row['vertical_rate_fpm'],                // was: vertical_rate_fpm
            'current_airspace' => $row['current_artcc'],                 // was: current_artcc
            'current_tracon' => $row['current_tracon'],
            'current_airport_zone' => $row['current_zone']               // was: current_zone
        ],
        
        // Progress - FIXM aligned
        'progress' => [
            'flight_status' => $row['phase'],                            // was: phase
            'is_active' => (bool)$row['is_active'],
            'distance_to_destination' => $row['dist_to_dest_nm'] !== null ? floatval($row['dist_to_dest_nm']) : null,  // was: distance_remaining_nm
            'distance_flown' => $row['dist_flown_nm'] !== null ? floatval($row['dist_flown_nm']) : null,
            'great_circle_distance' => $row['gcd_nm'] !== null ? floatval($row['gcd_nm']) : null,  // was: gcd_nm
            'total_flight_distance' => $row['route_total_nm'] !== null ? floatval($row['route_total_nm']) : null,  // was: route_total_nm
            'percent_complete' => $row['pct_complete'] !== null ? floatval($row['pct_complete']) : null,  // was: pct_complete
            'time_to_destination' => $time_to_dest                       // was: time_to_dest_min
        ],
        
        // Times - FIXM aligned (OOOI terminology)
        'times' => [
            'estimated_off_block_time' => formatDT($row['etd_utc']),     // was: etd
            'estimated_time_of_departure' => formatDT($row['etd_runway_utc'] ?? null),  // was: etd_runway
            'estimated_time_of_arrival' => formatDT($row['eta_utc']),    // was: eta
            'estimated_runway_arrival' => formatDT($row['eta_runway_utc']),  // was: eta_runway
            'eta_source' => $row['eta_source'],
            'eta_method' => $row['eta_method'],
            'estimated_elapsed_time' => $row['ete_minutes'],             // was: ete_minutes
            'actual_off_block_time' => formatDT($row['out_utc']),        // was: out (AOBT)
            'actual_time_of_departure' => formatDT($row['off_utc']),     // was: off (ATOT)
            'actual_landing_time' => formatDT($row['on_utc']),           // was: on (ALDT)
            'actual_in_block_time' => formatDT($row['in_utc']),          // was: in (AIBT)
            'controlled_time_of_departure' => formatDT($row['ctd_utc']), // was: ctd
            'controlled_time_of_arrival' => formatDT($row['cta_utc']),   // was: cta
            'edct' => formatDT($row['edct_utc'])
        ],
        
        // TMI - FIXM aligned
        'tmi' => [
            'is_controlled' => ($row['gs_held'] == 1 || $row['ctl_type'] !== null),
            'ground_stop_held' => $row['gs_held'] == 1,
            'ground_stop_release_time' => formatDT($row['gs_release_utc']),  // was: gs_release
            'control_type' => $row['ctl_type'],
            'program_name' => $row['ctl_prgm'],                          // was: control_program
            'control_element' => $row['ctl_element'],
            'exempt_indicator' => (bool)$row['is_exempt'],               // was: is_exempt
            'exempt_reason' => $row['exempt_reason'],
            'delay_value' => $row['delay_minutes'],                      // was: delay_minutes
            'delay_status' => $row['delay_status'],
            'slot_time' => formatDT($row['slot_time_utc']),
            'program_id' => $row['program_id'],
            'slot_id' => $row['slot_id']
        ],
        
        // Metadata - FIXM aligned
        'data_source' => 'vatcscc',                                      // was: _source
        'first_tracked_time' => formatDT($row['first_seen_utc']),        // was: _first_seen
        'position_time' => formatDT($row['last_seen_utc']),              // was: _last_seen
        'logon_time' => formatDT($row['logon_time_utc'])                 // was: _logon_time
    ];
    
    if ($use_swim_db && isset($row['last_sync_utc'])) {
        $result['last_sync_time'] = formatDT($row['last_sync_utc']);     // was: _last_sync
    }
    
    return $result;
}
```

---

## API Response Example (After Migration)

```json
{
  "gufi": "VAT-20260116-UAL123-KJFK-KLAX",
  "flight_uid": 12345,
  "flight_key": "UAL123-KJFK-KLAX-20260116",
  
  "identity": {
    "aircraft_identification": "UAL123",
    "pilot_cid": 1234567,
    "aircraft_type": "B738",
    "aircraft_type_icao": "B738",
    "aircraft_type_faa": "B738/L",
    "weight_class": "L",
    "wake_turbulence": "M",
    "operator_icao": "UAL",
    "operator_name": "United Airlines"
  },
  
  "flight_plan": {
    "departure_aerodrome": "KJFK",
    "arrival_aerodrome": "KLAX",
    "alternate_aerodrome": "KONT",
    "cruising_level": 35000,
    "cruising_speed": 460,
    "route_text": "DEEZZ5 DEEZZ J60 PSB J584 BJARR ANJLL4",
    "remarks": "PBN/A1B1C1D1S1S2 DOF/260116",
    "flight_rules_category": "I",
    "departure_airspace": "ZNY",
    "arrival_airspace": "ZLA",
    "departure_tracon": "N90",
    "arrival_tracon": "SCT",
    "departure_point": "DEEZZ",
    "sid": "DEEZZ5",
    "arrival_point": "BJARR",
    "star": "ANJLL4",
    "departure_runway": "31L",
    "arrival_runway": "25L"
  },
  
  "position": {
    "latitude": 39.8561,
    "longitude": -104.6737,
    "altitude": 35000,
    "track": 268,
    "ground_speed": 487,
    "true_airspeed": 462,
    "vertical_rate": 0,
    "current_airspace": "ZDV",
    "current_tracon": null,
    "current_airport_zone": null
  },
  
  "progress": {
    "flight_status": "ENROUTE",
    "is_active": true,
    "distance_to_destination": 985.3,
    "distance_flown": 1489.7,
    "great_circle_distance": 2150.0,
    "total_flight_distance": 2475.0,
    "percent_complete": 60.2,
    "time_to_destination": 121.5
  },
  
  "times": {
    "estimated_off_block_time": "2026-01-16T14:30:00Z",
    "estimated_time_of_departure": "2026-01-16T14:45:00Z",
    "estimated_time_of_arrival": "2026-01-16T19:15:00Z",
    "estimated_runway_arrival": "2026-01-16T19:12:00Z",
    "eta_source": "trajectory",
    "eta_method": "route_distance",
    "estimated_elapsed_time": 285,
    "actual_off_block_time": "2026-01-16T14:28:00Z",
    "actual_time_of_departure": "2026-01-16T14:43:00Z",
    "actual_landing_time": null,
    "actual_in_block_time": null,
    "controlled_time_of_departure": null,
    "controlled_time_of_arrival": null,
    "edct": null
  },
  
  "tmi": {
    "is_controlled": false,
    "ground_stop_held": false,
    "ground_stop_release_time": null,
    "control_type": null,
    "program_name": null,
    "control_element": null,
    "exempt_indicator": false,
    "exempt_reason": null,
    "delay_value": null,
    "delay_status": null,
    "slot_time": null,
    "program_id": null,
    "slot_id": null
  },
  
  "data_source": "vatcscc",
  "first_tracked_time": "2026-01-16T14:25:00Z",
  "position_time": "2026-01-16T16:45:00Z",
  "logon_time": "2026-01-16T14:20:00Z",
  "last_sync_time": "2026-01-16T16:45:15Z"
}
```

---

## Backward Compatibility

To support existing API consumers during transition, consider:

1. **Version header**: `Accept: application/vnd.vatcscc.swim.v2+json` for new format
2. **Query parameter**: `?format=fixm` to opt-in to new field names
3. **Deprecation period**: Return both old and new field names temporarily

### Example with format parameter:

```php
$format = swim_get_param('format', 'legacy');  // legacy | fixm

if ($format === 'fixm') {
    return formatFlightRecordFIXM($row, $use_swim_db);
} else {
    return formatFlightRecordLegacy($row, $use_swim_db);
}
```

---

## Files to Update

| File | Changes |
|------|---------|
| `api/swim/v1/flights.php` | `formatFlightRecord()` function |
| `api/swim/v1/flight.php` | Single flight response format |
| `api/swim/v1/positions.php` | GeoJSON properties |
| `api/swim/v1/tmi/controlled.php` | TMI response format |
| `scripts/swim_sync.php` | No changes (internal) |
| `docs/swim/openapi.yaml` | Update field definitions |
| `docs/swim/VATSIM_SWIM_API.postman_collection.json` | Update examples |

---

*End of SWIM API Field Migration Document*
