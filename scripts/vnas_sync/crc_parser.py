"""
CRC ARTCC JSON parser for vNAS sync.

Reads a CRC ARTCC JSON file (from %LOCALAPPDATA%/CRC/ARTCCs/*.json)
and returns two dicts: (facilities_payload, restrictions_payload).
"""

import json
import copy


def parse_artcc_json(filepath):
    """Parse a CRC ARTCC JSON file and return (facilities_payload, restrictions_payload).

    Args:
        filepath: Path to a CRC ARTCC JSON file (e.g. ZDC.json).

    Returns:
        Tuple of (facilities_payload, restrictions_payload) dicts.
    """
    with open(filepath, encoding='utf-8') as f:
        data = json.load(f)

    artcc_code = data['facility']['id']
    source_updated_at = data.get('lastUpdatedAt')
    visibility_centers = data.get('visibilityCenters')
    aliases_updated_at = data.get('aliasesLastUpdatedAt')

    # Accumulators
    facilities = []
    positions = []
    stars_tcps = []
    stars_areas = []
    beacon_banks = []

    # Walk the facility hierarchy recursively
    _walk_facility(
        data['facility'],
        artcc_code=artcc_code,
        parent_facility_id=None,
        depth=0,
        source_updated_at=source_updated_at,
        visibility_centers=visibility_centers,
        aliases_updated_at=aliases_updated_at,
        facilities=facilities,
        positions=positions,
        stars_tcps=stars_tcps,
        stars_areas=stars_areas,
        beacon_banks=beacon_banks,
    )

    # Top-level transceivers
    transceivers = []
    for tx in data.get('transceivers', []):
        loc = tx.get('location', {})
        transceivers.append({
            'transceiver_id': tx.get('id'),
            'parent_artcc': artcc_code,
            'transceiver_name': tx.get('name'),
            'lat': loc.get('lat'),
            'lon': loc.get('lon'),
            'height_msl_meters': tx.get('heightMslMeters'),
            'height_agl_meters': tx.get('heightAglMeters'),
        })

    # Top-level video maps
    video_maps = []
    for vm in data.get('videoMaps', []):
        video_maps.append({
            'map_id': vm.get('id'),
            'parent_artcc': artcc_code,
            'map_name': vm.get('name'),
            'short_name': vm.get('shortName'),
            'stars_id': vm.get('starsId'),
            'tags_json': vm.get('tags', []),
            'source_file_name': vm.get('sourceFileName'),
            'stars_brightness_category': vm.get('starsBrightnessCategory'),
            'stars_always_visible': vm.get('starsAlwaysVisible', False),
            'tdm_only': vm.get('tdmOnly', False),
            'last_updated_at': vm.get('lastUpdatedAt'),
        })

    # Top-level airport groups
    airport_groups = []
    for ag in data.get('airportGroups', []):
        airport_groups.append({
            'group_id': ag.get('id'),
            'parent_artcc': artcc_code,
            'group_name': ag.get('name'),
            'airport_ids_json': ag.get('airportIds', []),
        })

    # Top-level common URLs
    common_urls = []
    for cu in data.get('commonUrls', []):
        common_urls.append({
            'url_id': cu.get('id'),
            'parent_artcc': artcc_code,
            'url_name': cu.get('name'),
            'url': cu.get('url'),
        })

    facilities_payload = {
        'artcc_code': artcc_code,
        'source_updated_at': source_updated_at,
        'facilities': facilities,
        'positions': positions,
        'stars_tcps': stars_tcps,
        'stars_areas': stars_areas,
        'beacon_banks': beacon_banks,
        'transceivers': transceivers,
        'video_maps': video_maps,
        'airport_groups': airport_groups,
        'common_urls': common_urls,
    }

    # Restrictions payload
    restrictions = []
    for r in data.get('restrictions', []):
        alt_r = r.get('altitudeRestriction') or {}
        spd_r = r.get('speedRestriction') or {}
        hdg_r = r.get('headingRestriction') or {}
        loc_r = r.get('locationRestriction') or {}

        restrictions.append({
            'restriction_id': r.get('id'),
            'parent_artcc': artcc_code,
            'owning_facility_id': r.get('owningFacilityId'),
            'owning_sector_ids': r.get('owningSectorIds', []),
            'requesting_facility_id': r.get('requestingFacilityId'),
            'requesting_sector_ids': r.get('requestingSectorIds', []),
            'route': r.get('route'),
            'applicable_airports': r.get('applicableAirports', []),
            'applicable_aircraft_types': r.get('applicableAircraftTypes', []),
            'flight_type': r.get('flightType'),
            'flow': r.get('flow'),
            'group_name': r.get('groupName'),
            'altitude_type': alt_r.get('type'),
            'altitude_values': alt_r.get('altitudes'),
            'speed_type': spd_r.get('type'),
            'speed_values': spd_r.get('speeds'),
            'speed_units': spd_r.get('units'),
            'heading_type': hdg_r.get('type'),
            'heading_values': hdg_r.get('headings'),
            'location_type': loc_r.get('type'),
            'location_value': loc_r.get('location'),
            'notes_json': r.get('notes', []),
            'display_order': r.get('displayOrder', 0),
        })

    # Auto ATC rules
    auto_atc_rules = []
    for rule in data.get('autoAtcRules', []):
        criteria = rule.get('criteria', {})

        # Descent restriction (line-based)
        dr = rule.get('descentRestriction')
        # Descent crossing restriction (fix-based)
        dcr = rule.get('descentCrossingRestriction')
        # Descend via
        dv = rule.get('descendVia')

        # descentRestriction fields
        dr_crossing_line = None
        dr_alt_value = None
        dr_alt_type = None
        dr_transition = None
        dr_is_lufl = None
        dr_lufl_station = None
        dr_altimeter_station = None
        dr_altimeter_name = None
        dr_speed_value = None
        dr_speed_is_mach = None
        dr_speed_type = None

        if dr is not None:
            dr_crossing_line = dr.get('crossingLine')
            alt_c = dr.get('altitudeConstraint') or {}
            dr_alt_value = alt_c.get('value')
            dr_alt_type = alt_c.get('constraintType')
            dr_transition = alt_c.get('transitionLevel')
            dr_is_lufl = alt_c.get('isLufl')
            dr_lufl_station = alt_c.get('luflStationId')
            alt_s = dr.get('altimeterStation') or {}
            dr_altimeter_station = alt_s.get('stationId')
            dr_altimeter_name = alt_s.get('stationName')
            spd_c = dr.get('speedConstraint') or {}
            dr_speed_value = spd_c.get('value')
            dr_speed_is_mach = spd_c.get('isMach')
            dr_speed_type = spd_c.get('constraintType')

        # descentCrossingRestriction fields
        cr_fix = None
        cr_fix_name = None
        cr_alt_value = None
        cr_alt_type = None
        cr_transition = None
        cr_is_lufl = None
        cr_altimeter_station = None
        cr_altimeter_name = None

        if dcr is not None:
            cr_fix = dcr.get('crossingFix')
            cr_fix_name = dcr.get('crossingFixName')
            alt_c = dcr.get('altitudeConstraint') or {}
            cr_alt_value = alt_c.get('value')
            cr_alt_type = alt_c.get('constraintType')
            cr_transition = alt_c.get('transitionLevel')
            cr_is_lufl = alt_c.get('isLufl')
            alt_s = dcr.get('altimeterStation') or {}
            cr_altimeter_station = alt_s.get('stationId')
            cr_altimeter_name = alt_s.get('stationName')

        # descendVia fields
        dv_star_name = None
        dv_crossing_line = None
        dv_altimeter_station = None
        dv_altimeter_name = None

        if dv is not None:
            dv_star_name = dv.get('starName')
            dv_crossing_line = dv.get('crossingLine')
            alt_s = dv.get('altimeterStation') or {}
            dv_altimeter_station = alt_s.get('stationId')
            dv_altimeter_name = alt_s.get('stationName')

        auto_atc_rules.append({
            'rule_id': rule.get('id'),
            'parent_artcc': artcc_code,
            'rule_name': rule.get('name'),
            'status': rule.get('status'),
            'position_ulid': rule.get('positionId'),
            'route_substrings': criteria.get('routeSubstrings', []),
            'exclude_route_substrings': criteria.get('excludeRouteSubstrings', []),
            'departure_airports': criteria.get('departures', []),
            'destination_airports': criteria.get('destinations', []),
            'min_altitude': criteria.get('minAltitude'),
            'max_altitude': criteria.get('maxAltitude'),
            'applicable_jets': criteria.get('applicableToJets', False),
            'applicable_turboprops': criteria.get('applicableToTurboprops', False),
            'applicable_props': criteria.get('applicableToProps', False),
            # descentRestriction fields
            'descent_crossing_line_json': dr_crossing_line,
            'descent_altitude_value': dr_alt_value,
            'descent_altitude_type': dr_alt_type,
            'descent_transition_level': dr_transition,
            'descent_is_lufl': dr_is_lufl,
            'descent_lufl_station_id': dr_lufl_station,
            'descent_altimeter_station': dr_altimeter_station,
            'descent_altimeter_name': dr_altimeter_name,
            'descent_speed_value': dr_speed_value,
            'descent_speed_is_mach': dr_speed_is_mach,
            'descent_speed_type': dr_speed_type,
            # descentCrossingRestriction fields
            'crossing_fix': cr_fix,
            'crossing_fix_name': cr_fix_name,
            'crossing_altitude_value': cr_alt_value,
            'crossing_altitude_type': cr_alt_type,
            'crossing_transition_level': cr_transition,
            'crossing_is_lufl': cr_is_lufl,
            'crossing_altimeter_station': cr_altimeter_station,
            'crossing_altimeter_name': cr_altimeter_name,
            # descendVia fields
            'descend_via_star_name': dv_star_name,
            'descend_via_crossing_line_json': dv_crossing_line,
            'descend_via_altimeter_station': dv_altimeter_station,
            'descend_via_altimeter_name': dv_altimeter_name,
            # Rule linkage
            'precursor_rule_ids': rule.get('precursorRules', []),
            'exclusionary_rule_ids': rule.get('exclusionaryRules', []),
        })

    restrictions_payload = {
        'artcc_code': artcc_code,
        'restrictions': restrictions,
        'auto_atc_rules': auto_atc_rules,
    }

    return facilities_payload, restrictions_payload


def _walk_facility(fac, artcc_code, parent_facility_id, depth,
                   source_updated_at, visibility_centers, aliases_updated_at,
                   facilities, positions, stars_tcps, stars_areas, beacon_banks):
    """Recursively walk facility hierarchy, accumulating into output lists."""
    fac_id = fac.get('id')
    fac_type = fac.get('type')

    # Detect which config subsystems are present
    eram_config = fac.get('eramConfiguration')
    stars_config = fac.get('starsConfiguration')
    flight_strips_config = fac.get('flightStripsConfiguration')
    tower_cab_config = fac.get('towerCabConfiguration')
    asdex_config = fac.get('asdexConfiguration')
    tdls_config = fac.get('tdlsConfiguration')

    # Extract beacon banks from ERAM config
    if eram_config:
        for bank in eram_config.get('beaconCodeBanks', []):
            beacon_banks.append({
                'bank_id': bank.get('id'),
                'facility_id': fac_id,
                'parent_artcc': artcc_code,
                'source_system': 'ERAM',
                'category': bank.get('category'),
                'priority': bank.get('priority'),
                'subset': bank.get('subset'),
                'start_code': bank.get('start'),
                'end_code': bank.get('end'),
            })

    # Extract STARS sub-objects (tcps, areas, beacon banks)
    if stars_config:
        for tcp in stars_config.get('tcps', []):
            stars_tcps.append({
                'tcp_id': tcp.get('id'),
                'facility_id': fac_id,
                'parent_artcc': artcc_code,
                'subset': tcp.get('subset'),
                'sector_id': tcp.get('sectorId'),
                'parent_tcp_id': tcp.get('parentTcpId'),
                'terminal_sector': tcp.get('terminalSector'),
            })

        for area in stars_config.get('areas', []):
            vc = area.get('visibilityCenter', {})
            stars_areas.append({
                'area_id': area.get('id'),
                'facility_id': fac_id,
                'parent_artcc': artcc_code,
                'area_name': area.get('name'),
                'visibility_lat': vc.get('lat'),
                'visibility_lon': vc.get('lon'),
                'surveillance_range': area.get('surveillanceRange'),
                'ldb_beacon_codes_inhibited': area.get('ldbBeaconCodesInhibited', False),
                'pdb_ground_speed_inhibited': area.get('pdbGroundSpeedInhibited', False),
                'display_requested_alt_in_fdb': area.get('displayRequestedAltInFdb', False),
                'use_vfr_position_symbol': area.get('useVfrPositionSymbol', False),
                'show_dest_departures': area.get('showDestinationDepartures', False),
                'show_dest_satellite_arrivals': area.get('showDestinationSatelliteArrivals', False),
                'show_dest_primary_arrivals': area.get('showDestinationPrimaryArrivals', False),
                'underlying_airports_json': area.get('underlyingAirports', []),
                'ssa_airports_json': area.get('ssaAirports', []),
                'tower_list_configs_json': area.get('towerListConfigurations', []),
            })

        for bank in stars_config.get('beaconCodeBanks', []):
            beacon_banks.append({
                'bank_id': bank.get('id'),
                'facility_id': fac_id,
                'parent_artcc': artcc_code,
                'source_system': 'STARS',
                'category': bank.get('type'),  # STARS 'type' mapped to 'category'
                'priority': None,  # STARS has no priority
                'subset': bank.get('subset'),
                'start_code': bank.get('start'),
                'end_code': bank.get('end'),
            })

    # Build stripped config copies for storage on the facility row
    eram_config_stripped = None
    if eram_config:
        eram_config_stripped = copy.deepcopy(eram_config)
        eram_config_stripped.pop('beaconCodeBanks', None)

    stars_config_stripped = None
    if stars_config:
        stars_config_stripped = copy.deepcopy(stars_config)
        stars_config_stripped.pop('tcps', None)
        stars_config_stripped.pop('areas', None)
        stars_config_stripped.pop('beaconCodeBanks', None)

    # Build facility row
    fac_row = {
        'facility_id': fac_id,
        'facility_name': fac.get('name'),
        'facility_type': fac_type,
        'parent_artcc': artcc_code,
        'parent_facility_id': parent_facility_id,
        'hierarchy_depth': depth,
        'neighboring_facility_ids': fac.get('neighboringFacilityIds', []),
        'non_nas_facility_ids': fac.get('nonNasFacilityIds', []),
        'has_eram': eram_config is not None,
        'has_stars': stars_config is not None,
        'has_flight_strips': flight_strips_config is not None,
        'has_tower_cab': tower_cab_config is not None,
        'has_asdex': asdex_config is not None,
        'has_tdls': tdls_config is not None,
        'eram_config_json': eram_config_stripped,
        'stars_config_json': stars_config_stripped,
        'flight_strips_json': flight_strips_config,
        'tower_cab_json': tower_cab_config,
        'asdex_config_json': asdex_config,
        'tdls_config_json': tdls_config,
        'visibility_centers_json': visibility_centers if depth == 0 else None,
        'aliases_updated_at': aliases_updated_at if depth == 0 else None,
        'source_artcc': artcc_code,
        'source_updated_at': source_updated_at,
    }
    facilities.append(fac_row)

    # Extract positions
    for pos in fac.get('positions', []):
        eram_pos = pos.get('eramConfiguration') or {}
        stars_pos = pos.get('starsConfiguration') or {}

        positions.append({
            'position_ulid': pos.get('id'),
            'facility_id': fac_id,
            'parent_artcc': artcc_code,
            'position_name': pos.get('name'),
            'callsign': pos.get('callsign'),
            'radio_name': pos.get('radioName'),
            'frequency_hz': pos.get('frequency'),
            'starred': pos.get('starred', False),
            'eram_sector_id': eram_pos.get('sectorId'),
            'stars_area_id': stars_pos.get('areaId'),
            'stars_tcp_id': stars_pos.get('tcpId'),
            'stars_color_set': stars_pos.get('colorSet'),
            'transceiver_ids_json': pos.get('transceiverIds', []),
        })

    # Recurse into child facilities
    for child in fac.get('childFacilities', []):
        _walk_facility(
            child,
            artcc_code=artcc_code,
            parent_facility_id=fac_id,
            depth=depth + 1,
            source_updated_at=source_updated_at,
            visibility_centers=None,  # only ARTCC-level
            aliases_updated_at=None,  # only ARTCC-level
            facilities=facilities,
            positions=positions,
            stars_tcps=stars_tcps,
            stars_areas=stars_areas,
            beacon_banks=beacon_banks,
        )
