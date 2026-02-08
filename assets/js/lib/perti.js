/**
 * PERTI Unified Namespace
 * =======================
 *
 * The single source of truth for all PERTI application constants.
 * Other modules (colors.js, facility-hierarchy.js, etc.) consume from
 * this module rather than defining their own constants.
 *
 * Namespaces:
 * - ATFM: Air Traffic Flow Management (TMI types, delay programs, etc.)
 * - FACILITY: Airports, aircraft, airlines, facility lists
 * - WEATHER: Weather categories and phenomena
 * - STATUS: Operational statuses and levels
 * - COORDINATION: Communication, coordination, advisories, roles
 * - GEOGRAPHIC: Regions, divisions, airspace types, ARTCC data
 * - UI: Visualization colors and palettes
 * - SUA: Special Use Airspace display types and groups
 * - ROUTE: Route parsing, expansion, and format constants
 * - CODING: Pattern matching regex for aviation identifiers
 *
 * @module lib/perti
 * @version 1.5.0
 * @date 2026-02-06
 */

(function(global) {
    'use strict';

    // ===========================================
    // 1. ATFM - Air Traffic Flow Management
    // ===========================================

    const TMI_TYPES = Object.freeze({
        GDP: { name: 'Ground Delay Program', scope: 'airport', icao: 'ATFM delay' },
        GS: { name: 'Ground Stop', scope: 'airport', icao: 'ATFM stop' },
        AFP: { name: 'Airspace Flow Program', scope: 'airspace', icao: 'ATFM regulation' },
        MIT: { name: 'Miles-in-Trail', scope: 'fix', icao: 'longitudinal separation' },
        MINIT: { name: 'Minutes-in-Trail', scope: 'fix', icao: 'time separation' },
        STOP: { name: 'Full Ground Stop', scope: 'airport', icao: 'ATFM stop' },
        SWAP: { name: 'Severe Weather Avoidance Plan', scope: 'region', icao: 'weather routing' },
        EDCT: { name: 'Expect Departure Clearance Time', scope: 'flight', icao: 'CTOT' },
        APREQ: { name: 'Approval Request', scope: 'flight', icao: 'slot request' },
        DSP: { name: 'Departure Sequencing Program', scope: 'airport', icao: 'departure management' },
        TBFM: { name: 'Time Based Flow Management', scope: 'facility', icao: 'arrival management' },
        TFMS: { name: 'Traffic Flow Management System', scope: 'national', icao: 'ATFM system' },
        CFR: { name: 'Call For Release', scope: 'flight', icao: 'departure clearance' },
        CTOP: { name: 'Collaborative Trajectory Options Program', scope: 'airspace', icao: 'trajectory options' },
        UDP: { name: 'Unified Delay Program', scope: 'national', icao: 'unified delay' },
        REROUTE: { name: 'Reroute Advisory', scope: 'route', icao: 'route amendment' },
        PLAYBOOK: { name: 'Coded Departure Route', scope: 'route', icao: 'standard routing' },
    });

    const INITIATIVE_SCOPE = Object.freeze({
        NATIONAL: { name: 'National', level: 1 },
        REGIONAL: { name: 'Regional', level: 2 },
        FACILITY: { name: 'Facility', level: 3 },
        AIRPORT: { name: 'Airport', level: 4 },
        FIX: { name: 'Fix/Waypoint', level: 5 },
        FLIGHT: { name: 'Individual Flight', level: 6 },
    });

    const DELAY_PROGRAMS = Object.freeze({
        GDP_RATE: { name: 'GDP by Arrival Rate', unit: 'aircraft/hour' },
        GDP_DELAY: { name: 'GDP by Delay', unit: 'minutes' },
        AFP_RATE: { name: 'AFP by Rate', unit: 'aircraft/hour' },
        AFP_MIT: { name: 'AFP with MIT', unit: 'nautical miles' },
        CTOP_RATE: { name: 'CTOP by Rate', unit: 'aircraft/hour' },
    });

    const SLOT_TYPES = Object.freeze({
        ASSIGNED: { name: 'Assigned Slot', modifiable: false },
        SUBSTITUTION: { name: 'Slot Substitution', modifiable: true },
        COMPRESSION: { name: 'Compression Slot', modifiable: true },
        AADC: { name: 'Adaptive Algorithm Driven Compression', modifiable: true },
        BRIDGE: { name: 'Bridge Slot', modifiable: true },
    });

    const CDR_STATUS = Object.freeze({
        OPEN: { name: 'Open', available: true },
        CLOSED: { name: 'Closed', available: false },
        CONDITIONAL: { name: 'Conditional', available: true, restricted: true },
    });

    // TMI types displayed in UI dropdowns/timelines
    const TMI_UI_TYPES = Object.freeze([
        'GS', 'GDP', 'MIT', 'MINIT', 'CFR', 'APREQ', 'Reroute', 'AFP',
        'FEA', 'FCA', 'CTOP', 'ICR', 'TBO', 'Metering', 'TBM', 'TBFM', 'Other',
    ]);

    // TMI types that require external coordination before publishing
    const COORDINATION_REQUIRED_TYPES = Object.freeze([
        'MIT', 'MINIT', 'APREQ', 'CFR', 'TBM', 'TBFM', 'STOP',
    ]);

    // Constraint types for terminal/enroute initiatives
    const CONSTRAINT_TYPES = Object.freeze([
        'Weather', 'Volume', 'Runway', 'Equipment', 'Construction',
        'Staffing', 'Military', 'TFR', 'Airspace', 'Other',
    ]);

    // VIP movement types
    const VIP_TYPES = Object.freeze([
        'VIP Arrival', 'VIP Departure', 'VIP Overflight', 'TFR',
    ]);

    // Space operation types
    const SPACE_TYPES = Object.freeze([
        'Rocket Launch', 'Reentry', 'Launch Window', 'Hazard Area',
    ]);

    // Extended NTML Qualifiers - matching OPSNET/ASPM terminology
    const NTML_QUALIFIERS = Object.freeze({
        spacing: Object.freeze([
            { code: 'AS ONE', label: 'AS ONE', desc: 'Combined traffic as one stream' },
            { code: 'PER STREAM', label: 'PER STREAM', desc: 'Spacing per traffic stream' },
            { code: 'PER AIRPORT', label: 'PER AIRPORT', desc: 'Spacing per departure airport' },
            { code: 'PER FIX', label: 'PER FIX', desc: 'Spacing per arrival fix' },
            { code: 'PER ROUTE', label: 'PER ROUTE', desc: 'Spacing per route' },
            { code: 'EACH', label: 'EACH', desc: 'Each aircraft separately' },
            { code: 'EVERY OTHER', label: 'EVERY OTHER', desc: 'Every other aircraft' },
            { code: 'PER STRAT', label: 'PER STRAT', desc: 'Per stratum/altitude band' },
            { code: 'SINGLE STREAM', label: 'SINGLE STREAM', desc: 'Single traffic stream' },
            { code: 'NO STACKS', label: 'NO STACKS', desc: 'No altitude stacking' },
        ]),
        aircraft: Object.freeze([
            { code: 'JET', label: 'JET', desc: 'Jet aircraft only' },
            { code: 'PROP', label: 'PROP', desc: 'Propeller aircraft only' },
            { code: 'TURBOJET', label: 'TURBOJET', desc: 'Turbojet aircraft only' },
            { code: 'TURBOPROP', label: 'TURBOPROP', desc: 'Turboprop aircraft only' },
            { code: 'B757', label: 'B757', desc: 'B757 aircraft only' },
            { code: 'SUPER', label: 'SUPER', desc: 'Super aircraft (A380, AN-225)' },
        ]),
        weight: Object.freeze([
            { code: 'HEAVY', label: 'HEAVY', desc: 'Heavy aircraft (>255,000 lbs)' },
            { code: 'LARGE', label: 'LARGE', desc: 'Large aircraft (41,000-255,000 lbs)' },
            { code: 'SMALL', label: 'SMALL', desc: 'Small aircraft (<41,000 lbs)' },
            { code: 'SUPER', label: 'SUPER', desc: 'Superheavy aircraft (A380, AN-225)' },
        ]),
        equipment: Object.freeze([
            { code: 'RNAV', label: 'RNAV', desc: 'RNAV-equipped aircraft only' },
            { code: 'NON-RNAV', label: 'NON-RNAV', desc: 'Non-RNAV aircraft only' },
            { code: 'RNP', label: 'RNP', desc: 'RNP-capable aircraft only' },
            { code: 'RVSM', label: 'RVSM', desc: 'RVSM-compliant only' },
            { code: 'NON-RVSM', label: 'NON-RVSM', desc: 'Non-RVSM aircraft only' },
        ]),
        flow: Object.freeze([
            { code: 'ARR', label: 'ARR', desc: 'Arrival traffic only' },
            { code: 'DEP', label: 'DEP', desc: 'Departure traffic only' },
            { code: 'OVFLT', label: 'OVFLT', desc: 'Overflight traffic only' },
        ]),
        operator: Object.freeze([
            { code: 'AIR CARRIER', label: 'AIR CARRIER', desc: 'Air carrier operations' },
            { code: 'AIR TAXI', label: 'AIR TAXI', desc: 'Air taxi operations' },
            { code: 'GA', label: 'GA', desc: 'General aviation' },
            { code: 'CARGO', label: 'CARGO', desc: 'Cargo operations' },
            { code: 'MIL', label: 'MIL', desc: 'Military operations' },
            { code: 'MAJOR', label: 'MAJOR', desc: 'Major carrier operations' },
            { code: 'REGIONAL', label: 'REGIONAL', desc: 'Regional carrier operations' },
        ]),
        altitude: Object.freeze([
            { code: 'AOB', label: 'At or Below', desc: 'At or below specified altitude (e.g., AOB240)' },
            { code: 'AOA', label: 'At or Above', desc: 'At or above specified altitude (e.g., AOA330)' },
            { code: 'BETWEEN', label: 'Between', desc: 'Between two altitudes (e.g., 170B190)' },
        ]),
    });

    // Reason categories (broad) per OPSNET
    const REASON_CATEGORIES = Object.freeze([
        { code: 'VOLUME', label: 'Volume' },
        { code: 'WEATHER', label: 'Weather' },
        { code: 'RUNWAY', label: 'Runway' },
        { code: 'EQUIPMENT', label: 'Equipment' },
        { code: 'OTHER', label: 'Other' },
    ]);

    // Cause codes (specific) per OPSNET/ASPM, grouped by category
    const REASON_CAUSES = Object.freeze({
        VOLUME: Object.freeze([
            { code: 'VOLUME', label: 'Volume' },
            { code: 'COMPACTED DEMAND', label: 'Compacted Demand' },
            { code: 'MULTI-TAXI', label: 'Multi-Taxi' },
            { code: 'AIRSPACE', label: 'Airspace' },
        ]),
        WEATHER: Object.freeze([
            { code: 'WEATHER', label: 'Weather' },
            { code: 'THUNDERSTORMS', label: 'Thunderstorms' },
            { code: 'LOW CEILINGS', label: 'Low Ceilings' },
            { code: 'LOW VISIBILITY', label: 'Low Visibility' },
            { code: 'FOG', label: 'Fog' },
            { code: 'WIND', label: 'Wind' },
            { code: 'SNOW/ICE', label: 'Snow/Ice' },
        ]),
        RUNWAY: Object.freeze([
            { code: 'RUNWAY', label: 'Runway' },
            { code: 'RUNWAY CONFIGURATION', label: 'Runway Configuration' },
            { code: 'RUNWAY CONSTRUCTION', label: 'Runway Construction' },
            { code: 'RUNWAY CLOSURE', label: 'Runway Closure' },
        ]),
        EQUIPMENT: Object.freeze([
            { code: 'EQUIPMENT', label: 'Equipment' },
            { code: 'VATSIM EQUIPMENT', label: 'VATSIM Equipment' },
            { code: 'NON-VATSIM EQUIPMENT', label: 'Non-VATSIM Equipment' },
        ]),
        OTHER: Object.freeze([
            { code: 'OTHER', label: 'Other' },
            { code: 'STAFFING', label: 'Staffing' },
            { code: 'AIR SHOW', label: 'Air Show' },
            { code: 'VIP MOVEMENT', label: 'VIP Movement' },
            { code: 'SPECIAL EVENT', label: 'Special Event' },
            { code: 'SECURITY', label: 'Security' },
        ]),
    });

    const ATFM = Object.freeze({
        TMI_TYPES,
        TMI_UI_TYPES,
        COORDINATION_REQUIRED_TYPES,
        INITIATIVE_SCOPE,
        DELAY_PROGRAMS,
        SLOT_TYPES,
        CDR_STATUS,
        CONSTRAINT_TYPES,
        VIP_TYPES,
        SPACE_TYPES,
        NTML_QUALIFIERS,
        REASON_CATEGORIES,
        REASON_CAUSES,
    });

    // ===========================================
    // 2. FACILITY - Airports, Aircraft, Airlines
    // ===========================================

    // Aircraft approach categories per 14 CFR 97.3
    const AIRCRAFT_CATEGORIES = Object.freeze({
        A: { name: 'Category A', speed: '<91 kt', desc: 'Vref < 91 knots' },
        B: { name: 'Category B', speed: '91-120 kt', desc: 'Vref 91 to < 121 knots' },
        C: { name: 'Category C', speed: '121-140 kt', desc: 'Vref 121 to < 141 knots' },
        D: { name: 'Category D', speed: '141-165 kt', desc: 'Vref 141 to < 166 knots' },
        E: { name: 'Category E', speed: '≥166 kt', desc: 'Vref 166 knots or more' },
    });

    // FAA weight classes per ASPM / JO 7110.65 / JO 7360.1K
    const WAKE_TURBULENCE_FAA = Object.freeze({
        S: { name: 'Small', maxWeight: 41000, desc: 'MTOW ≤ 41,000 lbs' },
        L: { name: 'Large', maxWeight: 255000, desc: 'MTOW 41,001-255,000 lbs' },
        B757: { name: 'B757', maxWeight: 255000, desc: 'Boeing 757 (all series)' },
        H: { name: 'Heavy', maxWeight: null, desc: 'MTOW > 255,000 lbs' },
        J: { name: 'Super', aircraft: ['A388', 'A225'], desc: 'A380, AN-225' },
    });

    // ICAO wake turbulence categories (appear on VATSIM flight plans)
    const WAKE_TURBULENCE_ICAO = Object.freeze({
        L: { name: 'Light', maxWeight: 15500, desc: 'MTOW ≤ 15,500 lbs (7,000 kg)' },
        M: { name: 'Medium', maxWeight: 300000, desc: 'MTOW 15,501-300,000 lbs (136,000 kg)' },
        H: { name: 'Heavy', maxWeight: null, desc: 'MTOW > 300,000 lbs (136,000 kg)' },
        J: { name: 'Super', aircraft: ['A388', 'A225'], desc: 'A380, AN-225' },
    });

    // Equipment suffixes per FAA JO 7110.65BB Appendix E, Table 5-2-5 (18 suffixes)
    const EQUIPMENT_SUFFIX = Object.freeze({
        // RVSM-capable (3 suffixes)
        '/W': { nav: 'No GNSS, No RNAV', transponder: 'Mode C', rvsm: true },
        '/Z': { nav: 'RNAV, No GNSS', transponder: 'Mode C', rvsm: true },
        '/L': { nav: 'GNSS', transponder: 'Mode C', rvsm: true },
        // No DME (3 suffixes)
        '/X': { nav: 'No DME', transponder: 'None', rvsm: false },
        '/T': { nav: 'No DME', transponder: 'No Mode C', rvsm: false },
        '/U': { nav: 'No DME', transponder: 'Mode C', rvsm: false },
        // DME (3 suffixes)
        '/D': { nav: 'DME', transponder: 'None', rvsm: false },
        '/B': { nav: 'DME', transponder: 'No Mode C', rvsm: false },
        '/A': { nav: 'DME', transponder: 'Mode C', rvsm: false },
        // TACAN (3 suffixes)
        '/M': { nav: 'TACAN', transponder: 'None', rvsm: false },
        '/N': { nav: 'TACAN', transponder: 'No Mode C', rvsm: false },
        '/P': { nav: 'TACAN', transponder: 'Mode C', rvsm: false },
        // RNAV, No GNSS (3 suffixes)
        '/Y': { nav: 'RNAV, No GNSS', transponder: 'None', rvsm: false },
        '/C': { nav: 'RNAV, No GNSS', transponder: 'No Mode C', rvsm: false },
        '/I': { nav: 'RNAV, No GNSS', transponder: 'Mode C', rvsm: false },
        // GNSS (3 suffixes)
        '/V': { nav: 'GNSS', transponder: 'None', rvsm: false },
        '/S': { nav: 'GNSS', transponder: 'No Mode C', rvsm: false },
        '/G': { nav: 'GNSS', transponder: 'Mode C', rvsm: false },
    });

    const FLIGHT_RULES = Object.freeze({
        IFR: { name: 'Instrument Flight Rules', icao: 'I' },
        VFR: { name: 'Visual Flight Rules', icao: 'V' },
        SVFR: { name: 'Special VFR', icao: 'S' },
        DVFR: { name: 'Defense VFR', icao: 'D' },
        YFR: { name: 'IFR then VFR', icao: 'Y' },
        ZFR: { name: 'VFR then IFR', icao: 'Z' },
    });

    const AIRLINE_TYPES = Object.freeze({
        MAINLINE: { name: 'Mainline Carrier' },
        REGIONAL: { name: 'Regional Carrier' },
        CARGO: { name: 'Cargo Carrier' },
        CHARTER: { name: 'Charter Operator' },
        LCC: { name: 'Low Cost Carrier' },
        ULCC: { name: 'Ultra Low Cost Carrier' },
        GA: { name: 'General Aviation' },
        MIL: { name: 'Military' },
        GOV: { name: 'Government' },
    });

    const REGIONAL_CARRIERS = Object.freeze([
        'SKW', 'RPA', 'ENY', 'PDT', 'PSA', 'ASQ', 'GJS', 'CPZ',
        'EDV', 'QXE', 'ASH', 'OO', 'AIP', 'MES', 'JIA', 'SCX',
    ]);

    const AIRPORT_HUB_TYPES = Object.freeze({
        LARGE: { name: 'Large Hub', paxShare: '>1%' },
        MEDIUM: { name: 'Medium Hub', paxShare: '0.25-1%' },
        SMALL: { name: 'Small Hub', paxShare: '0.05-0.25%' },
        NONHUB: { name: 'Non-Hub Primary', paxShare: '<0.05%' },
        RELIEVER: { name: 'Reliever', paxShare: null },
        GA: { name: 'General Aviation', paxShare: null },
    });

    const SPECIAL_FLIGHTS = Object.freeze({
        LIFEGUARD: { name: 'Lifeguard', priority: true, callsignPrefix: 'LIFEGUARD' },
        MEDEVAC: { name: 'Medical Evacuation', priority: true, callsignPrefix: 'MEDEVAC' },
        AIR_AMBULANCE: { name: 'Air Ambulance', priority: true, callsignPrefix: null },
        AIR_EVAC: { name: 'Air Evacuation', priority: true, callsignPrefix: 'AIR EVAC' },
        HOSP: { name: 'Hospital', priority: true, callsignPrefix: 'HOSP' },
        SAR: { name: 'Search and Rescue', priority: true, callsignPrefix: 'RESCUE' },
        FLIGHT_CHECK: { name: 'Flight Check', priority: false, callsignPrefix: 'FLIGHT CHECK' },
        HAZMAT: { name: 'Hazardous Materials', priority: false, callsignPrefix: null },
    });

    // Canonical facility lists
    const FACILITY_LISTS = Object.freeze({
        // US domestic ARTCCs (22 centers: 20 CONUS + ZAN + ZHN)
        ARTCC_CONUS: Object.freeze([
            'ZAB', 'ZAN', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHN', 'ZHU', 'ZID', 'ZJX', 'ZKC',
            'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL',
        ]),
        // All US ARTCCs (CONUS + non-CONUS domestic + oceanic/territory)
        ARTCC_ALL: Object.freeze([
            // CONUS (20)
            'ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
            'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL',
            // Non-CONUS domestic
            'ZAN', 'ZHN', 'ZSU',
            // Oceanic/Territory
            'ZAK', 'ZAP', 'ZHO', 'ZMO', 'ZUA', 'ZWY',
        ]),
        // All 25 FAA TRACONs (per FAA TRACON facility list)
        TRACON: Object.freeze([
            'A11', 'A80', 'A90', 'C90', 'D01', 'D10', 'D21', 'F11', 'I90', 'L30',
            'M03', 'M98', 'N90', 'NCT', 'P31', 'P50', 'P80', 'PCT', 'R90',
            'S46', 'S56', 'SCT', 'T75', 'U90', 'Y90',
        ]),
        // ASPM 82 airport towers (per FAA ASPM facility list)
        ATCT: Object.freeze([
            'KABQ', 'PANC', 'KAPA', 'KASE', 'KATL', 'KAUS', 'KBDL', 'KBHM', 'KBJC', 'KBNA',
            'KBOI', 'KBOS', 'KBUF', 'KBUR', 'KBWI', 'KCLE', 'KCLT', 'KCMH', 'KCVG', 'KDAL',
            'KDAY', 'KDCA', 'KDEN', 'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KGYY', 'PHNL', 'KHOU',
            'KHPN', 'KIAD', 'KIAH', 'KIND', 'KISP', 'KJAX', 'KJFK', 'KLAS', 'KLAX', 'KLGA',
            'KLGB', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMHT', 'KMIA', 'KMKE', 'KMSP', 'KMSY',
            'KOAK', 'PHOG', 'KOMA', 'KONT', 'KORD', 'KOXR', 'KPBI', 'KPDX', 'KPHL', 'KPHX',
            'KPIT', 'KPSP', 'KPVD', 'KRDU', 'KRFD', 'KRSW', 'KSAN', 'KSAT', 'KSDF', 'KSEA',
            'KSFO', 'KSJC', 'TJSJ', 'KSLC', 'KSMF', 'KSNA', 'KSTL', 'KSWF', 'KTEB', 'KTPA',
            'KTUS', 'KVNY',
        ]),
        // Canadian FIRs (7 domestic ACCs + 1 oceanic)
        FIR_CANADA: Object.freeze([
            'CZEG', 'CZQM', 'CZQO', 'CZQX', 'CZUL', 'CZVR', 'CZWG', 'CZYZ',
        ]),
        // European FIRs (commonly referenced)
        FIR_EUROPE: Object.freeze([
            'EGPX', 'EGTT', 'EISN', 'LFFF', 'LFBB', 'LFEE', 'LFMM', 'LFRR',
            'EDGG', 'EDMM', 'EDUU', 'EDWW', 'EHAA', 'EBBU', 'LSAS', 'LOVV',
            'LIBB', 'LIMM', 'LIPP', 'LIRR', 'LECM', 'LECB', 'LECS', 'LPPC',
            'EKDK', 'ENOR', 'ESAA', 'EFIN', 'BIRD', 'BICC',
        ]),
        // Global FIRs (comprehensive list for dropdowns)
        FIR_GLOBAL: Object.freeze([
            // Canada
            'CZEG', 'CZQM', 'CZQO', 'CZQX', 'CZUL', 'CZVR', 'CZWG', 'CZYZ',
            // Europe
            'EGPX', 'EGTT', 'EISN', 'LFFF', 'LFBB', 'LFEE', 'LFMM', 'LFRR',
            'EDGG', 'EDMM', 'EDUU', 'EDWW', 'EHAA', 'EBBU', 'LSAS', 'LOVV',
            'LIBB', 'LIMM', 'LIPP', 'LIRR', 'LECM', 'LECB', 'LECS', 'LPPC',
            'EKDK', 'ENOR', 'ESAA', 'EFIN', 'BIRD', 'BICC',
            // Eastern Europe / Russia
            'UUUU', 'UMMV', 'UKBV', 'UKDV', 'UKLV', 'UKOV',
            // Middle East
            'LLLL', 'OJAC', 'OSTT', 'ORBB', 'OIIX', 'OAKX', 'OPKR', 'OPLR',
            // South Asia
            'VABF', 'VECF', 'VIDF', 'VOMF', 'VCCF', 'VRMF', 'VTBB', 'VVTS',
            // Southeast Asia / East Asia
            'WMFC', 'WSJC', 'WAAF', 'WIIF', 'RPHI', 'VHHK', 'ZGZU', 'ZBPE',
            'ZSHA', 'ZLHW', 'ZWUQ', 'RJJJ', 'RKRR', 'RCAA',
            // Pacific / Oceania
            'YMMM', 'YBBB', 'NZZO', 'NFFF', 'AGGG',
            // Africa
            'FAJS', 'FACA', 'FCCC', 'FNAN', 'FQBE', 'HTDC', 'HKNA', 'HUEC', 'HRYR',
            'DGAC', 'DRRR', 'DNKK', 'GOOO', 'GMMM', 'DTTC', 'HLLL', 'HECC',
            // South America
            'SCEZ', 'SCEL', 'SUEO', 'SABE', 'SBBS', 'SBCW', 'SBRE', 'SBAZ',
            'SVZM', 'SKED', 'SKEC', 'SPIM', 'SLLF', 'SEGU',
            // Central America / Caribbean / Mexico
            'MHTG', 'MGGT', 'MMMX', 'MMFR', 'MMTY', 'MMZT', 'TJZS', 'MKJK', 'MDCS', 'TTZP',
        ]),
    });

    // ICAO to IATA airline code mappings (for BTS data correlation)
    const AIRLINE_CODES = Object.freeze({
        // Mainline carriers
        'AAL': 'AA', 'DAL': 'DL', 'UAL': 'UA', 'SWA': 'WN',
        'JBU': 'B6', 'ASA': 'AS', 'HAL': 'HA',
        // ULCC
        'AAY': 'G4', 'FFT': 'F9', 'NKS': 'NK',
        // Regional carriers
        'ENY': 'MQ', 'ASQ': 'EV', 'EDV': '9E', 'SKW': 'OO',
        'ASH': 'YV', 'RPA': 'YX',
        // Cargo
        'FDX': 'FX', 'UPS': '5X',
        // Reverse mappings (IATA -> IATA)
        'AA': 'AA', 'DL': 'DL', 'UA': 'UA', 'WN': 'WN',
        'B6': 'B6', 'AS': 'AS', 'HA': 'HA',
        'G4': 'G4', 'F9': 'F9', 'NK': 'NK',
        'MQ': 'MQ', 'EV': 'EV', '9E': '9E', 'OO': 'OO',
        'YV': 'YV', 'YX': 'YX',
        'FX': 'FX', '5X': '5X',
    });

    // Facility code → human-readable name mapping (ARTCCs, TRACONs, FIRs)
    const FACILITY_NAME_MAP = Object.freeze({
        // US ARTCCs — CONUS (20)
        'ZAB': 'Albuquerque Center', 'ZAU': 'Chicago Center', 'ZBW': 'Boston Center',
        'ZDC': 'Washington Center', 'ZDV': 'Denver Center', 'ZFW': 'Fort Worth Center',
        'ZHU': 'Houston Center', 'ZID': 'Indianapolis Center', 'ZJX': 'Jacksonville Center',
        'ZKC': 'Kansas City Center', 'ZLA': 'Los Angeles Center', 'ZLC': 'Salt Lake Center',
        'ZMA': 'Miami Center', 'ZME': 'Memphis Center', 'ZMP': 'Minneapolis Center',
        'ZNY': 'New York Center', 'ZOA': 'Oakland Center', 'ZOB': 'Cleveland Center',
        'ZSE': 'Seattle Center', 'ZTL': 'Atlanta Center',
        // US ARTCCs — Non-CONUS domestic
        'ZAN': 'Anchorage Center', 'ZHN': 'Honolulu Center', 'ZSU': 'San Juan CERAP',
        // US ARTCCs — Oceanic/Territory
        'ZAK': 'Oakland Oceanic', 'ZAP': 'Anchorage Oceanic', 'ZHO': 'Houston Oceanic',
        'ZMO': 'Miami Oceanic', 'ZUA': 'Guam CERAP', 'ZWY': 'New York Oceanic',
        // US TRACONs (all 25 FAA TRACONs)
        'A11': 'Anchorage TRACON', 'A80': 'Atlanta TRACON', 'A90': 'Boston TRACON',
        'C90': 'Chicago TRACON', 'D01': 'Denver TRACON', 'D10': 'Dallas/Fort Worth TRACON',
        'D21': 'Detroit TRACON', 'F11': 'Central Florida TRACON', 'I90': 'Houston TRACON',
        'L30': 'Las Vegas TRACON', 'M03': 'Memphis TRACON', 'M98': 'Minneapolis TRACON',
        'N90': 'New York TRACON', 'NCT': 'NorCal TRACON', 'P31': 'Pensacola TRACON',
        'P50': 'Phoenix TRACON', 'P80': 'Portland TRACON', 'PCT': 'Potomac TRACON',
        'R90': 'Omaha TRACON', 'S46': 'Seattle TRACON', 'S56': 'Salt Lake TRACON',
        'SCT': 'SoCal TRACON', 'T75': 'St. Louis TRACON', 'U90': 'Tucson TRACON',
        'Y90': 'Yankee TRACON',
        // Pacific combined facilities
        'HCF': 'Honolulu CF',
        // Canadian FIRs
        'CZEG': 'Edmonton FIR', 'CZQM': 'Moncton FIR', 'CZQO': 'Gander Oceanic FIR',
        'CZQX': 'Gander FIR', 'CZUL': 'Montreal FIR', 'CZVR': 'Vancouver FIR',
        'CZWG': 'Winnipeg FIR', 'CZYZ': 'Toronto FIR',
        // Caribbean
        'TJSJ': 'San Juan CERAP', 'MUFH': 'Havana FIR', 'MKJK': 'Kingston FIR',
        'TNCF': 'Curacao FIR', 'TTPP': 'Piarco FIR',
        // Mexico
        'MMEX': 'Mexico City ACC', 'MMTY': 'Monterrey ACC', 'MMZT': 'Mazatlan ACC',
        'MMUN': 'Cancun ACC', 'MMMD': 'Merida ACC',
    });

    // Facilities that participate in cross-border coordination
    const CROSS_BORDER_FACILITIES = Object.freeze([
        'ZBW', 'ZMP', 'ZSE', 'ZLC', 'ZOB', 'CZYZ', 'CZWG', 'CZVR', 'CZEG',
    ]);

    const FACILITY = Object.freeze({
        AIRCRAFT_CATEGORIES,
        WAKE_TURBULENCE_FAA,
        WAKE_TURBULENCE_ICAO,
        EQUIPMENT_SUFFIX,
        FLIGHT_RULES,
        AIRLINE_TYPES,
        REGIONAL_CARRIERS,
        AIRPORT_HUB_TYPES,
        SPECIAL_FLIGHTS,
        FACILITY_LISTS,
        AIRLINE_CODES,
        FACILITY_NAME_MAP,
        CROSS_BORDER_FACILITIES,
    });

    // ===========================================
    // 3. WEATHER - Weather Categories & Phenomena
    // ===========================================

    const WEATHER_CATEGORIES = Object.freeze({
        VMC:   { name: 'Visual Meteorological Conditions', color: '#22c55e', ceiling: '>3000', visibility: '>5' },
        LVMC:  { name: 'Low VMC', color: '#eab308', ceiling: '1000-3000', visibility: '3-5' },
        IMC:   { name: 'Instrument Meteorological Conditions', color: '#f97316', ceiling: '<1000', visibility: '<3' },
        LIMC:  { name: 'Low IMC', color: '#ef4444', ceiling: '<500', visibility: '<1' },
        VLIMC: { name: 'Very Low IMC', color: '#dc2626', ceiling: '<200', visibility: '<0.5' },
    });

    const SIGMET_TYPES = Object.freeze({
        CONVECTIVE: { name: 'Convective SIGMET', icao: 'WS', hazard: 'thunderstorm' },
        TURB: { name: 'Turbulence SIGMET', icao: 'WS', hazard: 'turbulence' },
        ICE: { name: 'Icing SIGMET', icao: 'WS', hazard: 'icing' },
        MTN_OBSCUR: { name: 'Mountain Obscuration', icao: 'WS', hazard: 'visibility' },
        IFR: { name: 'IFR Conditions', icao: 'WA', hazard: 'visibility' },
        ASH: { name: 'Volcanic Ash', icao: 'WV', hazard: 'volcanic' },
        TROPICAL: { name: 'Tropical Cyclone', icao: 'WT', hazard: 'tropical' },
    });

    const AIRMET_TYPES = Object.freeze({
        SIERRA: { name: 'AIRMET Sierra', hazard: 'IFR/mountain obscuration', icao: 'WA' },
        TANGO: { name: 'AIRMET Tango', hazard: 'turbulence/sustained winds/LLWS', icao: 'WA' },
        ZULU: { name: 'AIRMET Zulu', hazard: 'icing/freezing level', icao: 'WA' },
    });

    const PIREP_TYPES = Object.freeze({
        UA: { name: 'Routine PIREP', priority: 'routine' },
        UUA: { name: 'Urgent PIREP', priority: 'urgent' },
    });

    const TURBULENCE_INTENSITY = Object.freeze({
        NEG: { name: 'Negative (None)', code: 0 },
        SMTH_LGT: { name: 'Smooth to Light', code: 1 },
        LGT: { name: 'Light', code: 2 },
        LGT_MOD: { name: 'Light to Moderate', code: 3 },
        MOD: { name: 'Moderate', code: 4 },
        MOD_SEV: { name: 'Moderate to Severe', code: 5 },
        SEV: { name: 'Severe', code: 6 },
        SEV_EXTM: { name: 'Severe to Extreme', code: 7 },
        EXTM: { name: 'Extreme', code: 8 },
    });

    const ICING_INTENSITY = Object.freeze({
        NEG: { name: 'Negative (None)', code: 0 },
        TRACE: { name: 'Trace', code: 1 },
        TRACE_LGT: { name: 'Trace to Light', code: 2 },
        LGT: { name: 'Light', code: 3 },
        LGT_MOD: { name: 'Light to Moderate', code: 4 },
        MOD: { name: 'Moderate', code: 5 },
        MOD_SEV: { name: 'Moderate to Severe', code: 6 },
        SEV: { name: 'Severe', code: 7 },
    });

    const CLOUD_TYPES = Object.freeze({
        SKC: { name: 'Sky Clear', coverage: 0 },
        CLR: { name: 'Clear Below 12000', coverage: 0 },
        FEW: { name: 'Few', coverage: '1-2/8' },
        SCT: { name: 'Scattered', coverage: '3-4/8' },
        BKN: { name: 'Broken', coverage: '5-7/8' },
        OVC: { name: 'Overcast', coverage: '8/8' },
        VV: { name: 'Vertical Visibility', coverage: 'obscured' },
    });

    const VISIBILITY_PHENOMENA = Object.freeze({
        FG: { name: 'Fog', visibility: '<1/4 SM' },
        MIFG: { name: 'Shallow Fog', visibility: 'variable' },
        BCFG: { name: 'Patchy Fog', visibility: 'variable' },
        PRFG: { name: 'Partial Fog', visibility: 'variable' },
        BR: { name: 'Mist', visibility: '5/8-6 SM' },
        HZ: { name: 'Haze', visibility: 'variable' },
        FU: { name: 'Smoke', visibility: 'variable' },
        DU: { name: 'Dust', visibility: 'variable' },
        SA: { name: 'Sand', visibility: 'variable' },
        VA: { name: 'Volcanic Ash', visibility: 'variable' },
        BLSN: { name: 'Blowing Snow', visibility: 'variable' },
        BLDU: { name: 'Blowing Dust', visibility: 'variable' },
        BLSA: { name: 'Blowing Sand', visibility: 'variable' },
    });

    const PRECIPITATION_TYPES = Object.freeze({
        RA: { name: 'Rain', frozen: false },
        DZ: { name: 'Drizzle', frozen: false },
        SN: { name: 'Snow', frozen: true },
        SG: { name: 'Snow Grains', frozen: true },
        IC: { name: 'Ice Crystals', frozen: true },
        PL: { name: 'Ice Pellets', frozen: true },
        GR: { name: 'Hail', frozen: true },
        GS: { name: 'Small Hail/Snow Pellets', frozen: true },
        UP: { name: 'Unknown Precipitation', frozen: null },
        FZRA: { name: 'Freezing Rain', frozen: true },
        FZDZ: { name: 'Freezing Drizzle', frozen: true },
        TSRA: { name: 'Thunderstorm with Rain', frozen: false },
        TSSN: { name: 'Thunderstorm with Snow', frozen: true },
        TSGR: { name: 'Thunderstorm with Hail', frozen: true },
    });

    const WEATHER = Object.freeze({
        CATEGORIES: WEATHER_CATEGORIES,
        SIGMET_TYPES,
        AIRMET_TYPES,
        PIREP_TYPES,
        TURBULENCE_INTENSITY,
        ICING_INTENSITY,
        CLOUD_TYPES,
        VISIBILITY_PHENOMENA,
        PRECIPITATION_TYPES,
    });

    // ===========================================
    // 4. STATUS - Operational Statuses & Levels
    // ===========================================

    const OPERATIONAL_STATUS = Object.freeze({
        NORMAL: { name: 'Normal Operations', color: '#28a745', level: 0 },
        MONITOR: { name: 'Monitoring', color: '#17a2b8', level: 1 },
        ADVISORY: { name: 'Advisory', color: '#ffc107', level: 2 },
        CAUTION: { name: 'Caution', color: '#fd7e14', level: 3 },
        ALERT: { name: 'Alert', color: '#dc3545', level: 4 },
        CRITICAL: { name: 'Critical', color: '#6f42c1', level: 5 },
    });

    // TMU Operating Levels per VATUSA 7210.35C §2.5
    const TMU_OPS_LEVEL = Object.freeze({
        1: { name: 'Steady State', color: '#28a745', description: 'No significant events or impacts requiring TMU coordination' },
        2: { name: 'Localized Impact', color: '#ffc107', description: 'Event or state affecting two or fewer facilities' },
        3: { name: 'Regional Impact', color: '#fd7e14', description: 'Event or state affecting three or more facilities' },
        4: { name: 'NAS-wide Impact', color: '#dc3545', description: 'Significant NAS impact, high-complexity, or extended duration' },
    });

    const FACILITY_STATUS = Object.freeze({
        OPEN: { name: 'Open', operational: true },
        CLOSED: { name: 'Closed', operational: false },
        ATC_ZERO: { name: 'ATC Zero', operational: false, emergency: true },
        LIMITED: { name: 'Limited Operations', operational: true, degraded: true },
        EVENT: { name: 'Event/Exercise', operational: true, special: true },
    });

    const RUNWAY_STATUS = Object.freeze({
        OPEN: { name: 'Open', available: true },
        CLOSED: { name: 'Closed', available: false },
        LAHSO: { name: 'LAHSO in Effect', available: true, restriction: 'LAHSO' },
        NOISE: { name: 'Noise Restriction', available: true, restriction: 'noise' },
        CONSTRUCTION: { name: 'Construction', available: false, temporary: true },
    });

    const NOTAM_PRIORITY = Object.freeze({
        FDC: { name: 'Flight Data Center', priority: 1 },
        NOTAM_D: { name: 'NOTAM (D)', priority: 2 },
        POINTER: { name: 'Pointer NOTAM', priority: 3 },
        SAA: { name: 'Special Activity Airspace', priority: 4 },
        MIL: { name: 'Military NOTAM', priority: 5 },
    });

    const FLIGHT_STATUS = Object.freeze({
        SCHEDULED: { name: 'Scheduled', active: false, airborne: false },
        FILED: { name: 'Filed', active: true, airborne: false },
        PROPOSED: { name: 'Proposed', active: true, airborne: false },
        ACTIVE: { name: 'Active', active: true, airborne: false },
        DEPARTED: { name: 'Departed', active: true, airborne: true },
        ENROUTE: { name: 'En Route', active: true, airborne: true },
        ARRIVED: { name: 'Arrived', active: false, airborne: false },
        CANCELLED: { name: 'Cancelled', active: false, airborne: false },
        DIVERTED: { name: 'Diverted', active: true, airborne: true },
    });

    const STATUS = Object.freeze({
        OPERATIONAL_STATUS,
        TMU_OPS_LEVEL,
        FACILITY_STATUS,
        RUNWAY_STATUS,
        NOTAM_PRIORITY,
        FLIGHT_STATUS,
    });

    // ===========================================
    // 5. COORDINATION - Communication & Coordination
    // ===========================================

    const HOTLINES = Object.freeze({
        COMMAND_CENTER: {
            name: 'VATSIM Command Center',
            phone: null,
            discord: 'https://vats.im/CommandCenter',
            dccRegion: null,
        },
        WEST: {
            name: 'DCC West',
            phone: null,
            discord: null,
            dccRegion: 'WEST',
            artccs: ['ZAK', 'ZAN', 'ZHN', 'ZLA', 'ZLC', 'ZOA', 'ZSE'],
        },
        SOUTH_CENTRAL: {
            name: 'DCC South Central',
            phone: null,
            discord: null,
            dccRegion: 'SOUTH_CENTRAL',
            artccs: ['ZAB', 'ZFW', 'ZHO', 'ZHU', 'ZME'],
        },
        MIDWEST: {
            name: 'DCC Midwest',
            phone: null,
            discord: null,
            dccRegion: 'MIDWEST',
            artccs: ['ZAU', 'ZDV', 'ZKC', 'ZMP'],
        },
        SOUTHEAST: {
            name: 'DCC Southeast',
            phone: null,
            discord: null,
            dccRegion: 'SOUTHEAST',
            artccs: ['ZID', 'ZJX', 'ZMA', 'ZMO', 'ZTL'],
        },
        NORTHEAST: {
            name: 'DCC Northeast',
            phone: null,
            discord: null,
            dccRegion: 'NORTHEAST',
            artccs: ['ZBW', 'ZDC', 'ZNY', 'ZOB', 'ZWY'],
        },
        CANADA: {
            name: 'VATCAN Operations',
            phone: null,
            discord: null,
            dccRegion: 'CANADA',
            artccs: ['CZEG', 'CZQM', 'CZQO', 'CZQX', 'CZUL', 'CZVR', 'CZWG', 'CZYZ'],
        },
    });

    const COORDINATION_TYPES = Object.freeze({
        HANDOFF: { name: 'Handoff', direction: 'lateral' },
        POINTOUT: { name: 'Point Out', direction: 'lateral' },
        APREQ: { name: 'Approval Request', direction: 'vertical' },
        RELEASE: { name: 'Release', direction: 'vertical' },
        TRAFFIC: { name: 'Traffic Advisory', direction: 'advisory' },
        ALTITUDE: { name: 'Altitude Request', direction: 'vertical' },
        ROUTE: { name: 'Route Amendment', direction: 'lateral' },
    });

    const COMMUNICATION_TYPES = Object.freeze({
        VOICE: { name: 'Voice', method: 'radio' },
        CPDLC: { name: 'Controller-Pilot Data Link', method: 'datalink' },
        ACARS: { name: 'Aircraft Communications Addressing and Reporting System', method: 'datalink' },
        ATIS: { name: 'Automatic Terminal Information Service', method: 'broadcast' },
        VOLMET: { name: 'Volume Meteorological', method: 'broadcast' },
        PDC: { name: 'Pre-Departure Clearance', method: 'datalink' },
        DCL: { name: 'Departure Clearance', method: 'datalink' },
    });

    // Advisory Types (FAA TFMS + TMI program types)
    const ADVISORY_TYPES = Object.freeze({
        // TMI Program Types
        GS: 'GS',
        GDP: 'GDP',
        AFP: 'AFP',
        ICR: 'ICR',
        CTOP: 'CTOP',
        // Route Advisories
        ROUTE: 'ROUTE',
        PLAYBOOK: 'PLAYBOOK',
        CDR: 'CDR',
        REROUTE: 'REROUTE',
        // Operational
        SPECIAL_OPERATIONS: 'SPECIAL OPERATIONS',
        OPERATIONS_PLAN: 'OPERATIONS PLAN',
        NRP_SUSPENSIONS: 'NRP SUSPENSIONS',
        // Scope
        VS: 'VS',
        NAT: 'NAT',
        FCA: 'FCA',
        FEA: 'FEA',
        SHUTTLE_ACTIVITY: 'SHUTTLE ACTIVITY',
        // General
        INFORMATIONAL: 'INFORMATIONAL',
        MISCELLANEOUS: 'MISCELLANEOUS',
    });

    // Advisory action codes
    const ADVISORY_ACTIONS = Object.freeze({
        RQD: { code: 'RQD', name: 'Required' },
        RMD: { code: 'RMD', name: 'Recommended' },
        PLN: { code: 'PLN', name: 'Planned' },
        FYI: { code: 'FYI', name: 'For Your Information' },
    });

    // Impacting conditions for constraints
    const IMPACTING_CONDITIONS = Object.freeze({
        WEATHER: 'WEATHER',
        VOLUME: 'VOLUME',
        RUNWAY: 'RUNWAY',
        EQUIPMENT: 'EQUIPMENT',
        OTHER: 'OTHER',
    });

    // Delay assignment modes
    const DELAY_ASSIGNMENT_MODES = Object.freeze({
        DAS: { code: 'DAS', name: 'Delay Assignment System' },
        GAAP: { code: 'GAAP', name: 'Ground-Airline-Airport Partnership' },
        UDP: { code: 'UDP', name: 'User-Defined Parameter' },
    });

    // International organizations for coordination
    const INTL_ORGS = Object.freeze({
        VATCAN: { name: 'Canada', region: 'CANADA' },
        VATMEX: { name: 'Mexico', region: 'MEXICO' },
        VATCAR: { name: 'Caribbean', region: 'CARIBBEAN' },
        ECFMP: { name: 'Europe/N. Africa', region: 'EMEA' },
    });

    // Role definitions for various operations
    const ROLES = Object.freeze({
        DCC: Object.freeze([
            { code: 'OP', name: 'Operations Planner' },
            { code: 'NOM', name: 'National Operations Manager' },
            { code: 'NTMO', name: 'National Traffic Management Officer' },
            { code: 'NTMS', name: 'National Traffic Management Specialist' },
            { code: 'OTHER', name: 'Other' },
        ]),
        ECFMP: Object.freeze([
            { code: 'LEAD', name: 'Leadership' },
            { code: 'NMT', name: 'Network Management Team' },
            { code: 'SFM', name: 'Senior Flow Manager' },
            { code: 'FM', name: 'Flow Manager' },
            { code: 'EVENT', name: 'Event Staff' },
            { code: 'ATC', name: 'Air Traffic Controller' },
            { code: 'OTHER', name: 'Other' },
        ]),
        CTP: Object.freeze([
            { code: 'LEAD', name: 'Leadership' },
            { code: 'COORD', name: 'Coordination' },
            { code: 'PLAN', name: 'Planning' },
            { code: 'RTE', name: 'Routes' },
            { code: 'FLOW', name: 'Flow' },
            { code: 'OCN', name: 'Oceanic' },
            { code: 'OTHER', name: 'Other' },
        ]),
        WF: Object.freeze([
            { code: 'LEAD', name: 'Leadership' },
            { code: 'AFF', name: 'Affiliate' },
            { code: 'TEAM', name: 'Team Member' },
            { code: 'SM', name: 'Social Media' },
            { code: 'OTHER', name: 'Other' },
        ]),
        FACILITY: Object.freeze([
            { code: 'STMC', name: 'Supervisory TMC' },
            { code: 'TMC', name: 'Traffic Management Coordinator' },
            { code: 'TMU', name: 'Traffic Management Unit' },
            { code: 'DEP', name: 'Departure Coordinator' },
            { code: 'ENR', name: 'En Route Coordinator' },
            { code: 'ARR', name: 'Arrival Coordinator' },
            { code: 'PIT', name: 'ZNY PIT' },
            { code: 'OTHER', name: 'Other' },
        ]),
    });

    const COORDINATION = Object.freeze({
        HOTLINES,
        COORDINATION_TYPES,
        COMMUNICATION_TYPES,
        ADVISORY_TYPES,
        ADVISORY_ACTIONS,
        IMPACTING_CONDITIONS,
        DELAY_ASSIGNMENT_MODES,
        INTL_ORGS,
        ROLES,
    });

    // ===========================================
    // 6. GEOGRAPHIC - Regions, Divisions, Airspace
    // ===========================================

    const DCC_REGIONS = Object.freeze({
        WEST: {
            name: 'DCC West',
            color: '#dc3545',
            artccs: ['ZAK', 'ZAN', 'ZAP', 'ZHN', 'ZLA', 'ZLC', 'ZOA', 'ZSE', 'ZUA'],
        },
        SOUTH_CENTRAL: {
            name: 'DCC South Central',
            color: '#fd7e14',
            artccs: ['ZAB', 'ZFW', 'ZHO', 'ZHU', 'ZME'],
        },
        MIDWEST: {
            name: 'DCC Midwest',
            color: '#28a745',
            artccs: ['ZAU', 'ZDV', 'ZKC', 'ZMP'],
        },
        SOUTHEAST: {
            name: 'DCC Southeast',
            color: '#ffc107',
            artccs: ['ZID', 'ZJX', 'ZMA', 'ZMO', 'ZSU', 'ZTL'],
        },
        NORTHEAST: {
            name: 'DCC Northeast',
            color: '#007bff',
            artccs: ['ZBW', 'ZDC', 'ZNY', 'ZOB', 'ZWY'],
        },
        CANADA: {
            name: 'Canada',
            color: '#6f42c1',
            artccs: ['CZEG', 'CZQM', 'CZQO', 'CZQX', 'CZUL', 'CZVR', 'CZWG', 'CZYZ'],
        },
        OTHER: {
            name: 'Other',
            color: '#6c757d',
            artccs: [],
        },
    });

    // Build inverse mapping from ARTCC to DCC region
    const ARTCC_TO_DCC = Object.freeze(
        Object.entries(DCC_REGIONS).reduce((acc, [region, data]) => {
            if (data.artccs) {
                data.artccs.forEach(artcc => {
                    acc[artcc] = region;
                });
            }
            return acc;
        }, {})
    );

    const VATSIM_REGIONS = Object.freeze({
        AMAS: { name: 'Americas', divisions: ['VATUSA', 'VATCAN', 'VATMEX', 'VATCAR', 'VATCA', 'VATSAM'] },
        EMEA: { name: 'Europe, Middle East & Africa', divisions: ['VATUK', 'VATEIR', 'VATEUR', 'VATSPA', 'VATITA', 'VATSCA', 'VATEUD', 'VATGRE', 'VATTUR', 'VATRUS', 'VATMENA', 'VATSSA'] },
        APAC: { name: 'Asia Pacific', divisions: ['VATPAC', 'VATJPN', 'VATSEA', 'VATKOR', 'VATPRC', 'VATHK', 'VATTWN', 'VATIND', 'VATPAK'] },
    });

    const VATSIM_DIVISIONS = Object.freeze({
        // AMAS - Americas
        VATUSA: { name: 'VATSIM USA', region: 'AMAS', icaoPrefixes: ['K', 'PA', 'PH', 'PG', 'TJ', 'TI'], hasDCC: true },
        VATCAN: { name: 'VATSIM Canada', region: 'AMAS', icaoPrefixes: ['C'], hasDCC: true },
        VATMEX: { name: 'VATSIM Mexico', region: 'AMAS', icaoPrefixes: ['MM'], hasDCC: true },
        VATCAR: { name: 'VATSIM Caribbean', region: 'AMAS', icaoPrefixes: ['TJ', 'TT', 'TF', 'TG', 'TI', 'TK', 'TL', 'TN', 'TQ', 'TR', 'TU', 'TV', 'TX', 'MK', 'MD', 'MH', 'MN', 'MP', 'MT', 'MW', 'MY'], hasDCC: true },
        VATCA: { name: 'VATSIM Central America', region: 'AMAS', icaoPrefixes: ['MG', 'MR', 'MS', 'MZ'], hasDCC: false },
        VATSAM: { name: 'VATSIM South America', region: 'AMAS', icaoPrefixes: ['SA', 'SB', 'SC', 'SE', 'SK', 'SL', 'SM', 'SO', 'SP', 'SU', 'SV', 'SY'], hasDCC: false },

        // EMEA - Europe, Middle East & Africa
        VATUK: { name: 'VATSIM United Kingdom', region: 'EMEA', icaoPrefixes: ['EG'], hasDCC: false },
        VATEIR: { name: 'VATSIM Ireland', region: 'EMEA', icaoPrefixes: ['EI'], hasDCC: false },
        VATEUR: { name: 'VATSIM Eurocore', region: 'EMEA', icaoPrefixes: ['EB', 'ED', 'EH', 'EL', 'ET', 'LF'], hasDCC: false },
        VATSPA: { name: 'VATSIM Spain', region: 'EMEA', icaoPrefixes: ['LE', 'GC', 'GE'], hasDCC: false },
        VATITA: { name: 'VATSIM Italy', region: 'EMEA', icaoPrefixes: ['LI', 'LM'], hasDCC: false },
        VATSCA: { name: 'VATSIM Scandinavia', region: 'EMEA', icaoPrefixes: ['BI', 'EF', 'EK', 'EN', 'ES'], hasDCC: false },
        VATEUD: { name: 'VATSIM Europe Division', region: 'EMEA', icaoPrefixes: ['EA', 'EE', 'EP', 'EV', 'EY', 'LA', 'LC', 'LD', 'LG', 'LH', 'LJ', 'LK', 'LO', 'LP', 'LQ', 'LR', 'LS', 'LT', 'LU', 'LW', 'LX', 'LY', 'LZ'], hasDCC: false },
        VATGRE: { name: 'VATSIM Greece', region: 'EMEA', icaoPrefixes: ['LG'], hasDCC: false },
        VATTUR: { name: 'VATSIM Turkey', region: 'EMEA', icaoPrefixes: ['LT'], hasDCC: false },
        VATRUS: { name: 'VATSIM Russia', region: 'EMEA', icaoPrefixes: ['U'], hasDCC: false },
        VATMENA: { name: 'VATSIM Middle East & North Africa', region: 'EMEA', icaoPrefixes: ['DA', 'DT', 'GM', 'HE', 'HL', 'LL', 'LN', 'OB', 'OE', 'OI', 'OJ', 'OK', 'OL', 'OM', 'OO', 'OR', 'OS', 'OT', 'OY'], hasDCC: false },
        VATSSA: { name: 'VATSIM Sub-Saharan Africa', region: 'EMEA', icaoPrefixes: ['DN', 'DR', 'DX', 'FA', 'FB', 'FC', 'FD', 'FE', 'FG', 'FH', 'FI', 'FJ', 'FK', 'FL', 'FM', 'FN', 'FO', 'FP', 'FQ', 'FS', 'FT', 'FV', 'FW', 'FX', 'FY', 'FZ', 'GA', 'GB', 'GF', 'GG', 'GL', 'GO', 'GQ', 'GU', 'GV', 'HA', 'HB', 'HC', 'HD', 'HH', 'HK', 'HR', 'HS', 'HT', 'HU'], hasDCC: false },

        // APAC - Asia Pacific
        VATPAC: { name: 'VATSIM Pacific', region: 'APAC', icaoPrefixes: ['AG', 'AN', 'AY', 'NF', 'NG', 'NI', 'NL', 'NS', 'NT', 'NV', 'NW', 'NZ', 'PG', 'PH', 'PK', 'PL', 'PM', 'PO', 'PP', 'PT', 'PW', 'WS', 'Y'], hasDCC: false },
        VATJPN: { name: 'VATSIM Japan', region: 'APAC', icaoPrefixes: ['RJ', 'RO'], hasDCC: false },
        VATSEA: { name: 'VATSIM Southeast Asia', region: 'APAC', icaoPrefixes: ['VD', 'VG', 'VL', 'VM', 'VN', 'VR', 'VT', 'VV', 'VY', 'WA', 'WB', 'WI', 'WM', 'WP', 'WQ', 'WR'], hasDCC: false },
        VATKOR: { name: 'VATSIM Korea', region: 'APAC', icaoPrefixes: ['RK'], hasDCC: false },
        VATPRC: { name: 'VATSIM China', region: 'APAC', icaoPrefixes: ['Z'], hasDCC: false },
        VATHK: { name: 'VATSIM Hong Kong', region: 'APAC', icaoPrefixes: ['VH'], hasDCC: false },
        VATTWN: { name: 'VATSIM Taiwan', region: 'APAC', icaoPrefixes: ['RC'], hasDCC: false },
        VATIND: { name: 'VATSIM India', region: 'APAC', icaoPrefixes: ['VA', 'VE', 'VI', 'VO'], hasDCC: false },
        VATPAK: { name: 'VATSIM Pakistan', region: 'APAC', icaoPrefixes: ['OP'], hasDCC: false },
    });

    const AIRSPACE_TYPES = Object.freeze({
        // ICAO Control Units
        FIR: { name: 'Flight Information Region', icao: true },
        UIR: { name: 'Upper Information Region', icao: true },
        ACC: { name: 'Area Control Center', icao: true },
        APP: { name: 'Approach Control', icao: true },
        TWR: { name: 'Tower Control', icao: true },
        CTR: { name: 'Control Zone', icao: true },
        TMA: { name: 'Terminal Maneuvering Area', icao: true },
        ATZ: { name: 'Aerodrome Traffic Zone', icao: true },

        // US Control Units
        ARTCC: { name: 'Air Route Traffic Control Center', icao: false, equivalent: 'ACC' },
        TRACON: { name: 'Terminal Radar Approach Control', icao: false, equivalent: 'APP' },
        ATCT: { name: 'Air Traffic Control Tower', icao: false, equivalent: 'TWR' },
        RAPCON: { name: 'Radar Approach Control (Military)', icao: false, equivalent: 'APP' },
        RATCF: { name: 'Radar Air Traffic Control Facility', icao: false, equivalent: 'APP' },

        // Oceanic/Remote
        OCA: { name: 'Oceanic Control Area', icao: true },
        CTA: { name: 'Control Area', icao: true },
        OTS: { name: 'Organized Track System', icao: true },

        // ICAO Airspace Classes
        CLASS_A: { name: 'Class A Airspace', controlled: true, ifr: true, vfr: false },
        CLASS_B: { name: 'Class B Airspace', controlled: true, ifr: true, vfr: true },
        CLASS_C: { name: 'Class C Airspace', controlled: true, ifr: true, vfr: true },
        CLASS_D: { name: 'Class D Airspace', controlled: true, ifr: true, vfr: true },
        CLASS_E: { name: 'Class E Airspace', controlled: true, ifr: true, vfr: true },
        CLASS_F: { name: 'Class F Airspace', controlled: false, ifr: true, vfr: true },
        CLASS_G: { name: 'Class G Airspace', controlled: false, ifr: false, vfr: true },

        // Special Use Airspace (SUA)
        MOA: { name: 'Military Operations Area', sua: true },
        RESTRICTED: { name: 'Restricted Area', sua: true },
        PROHIBITED: { name: 'Prohibited Area', sua: true },
        WARNING: { name: 'Warning Area', sua: true },
        ALERT: { name: 'Alert Area', sua: true },
        CFA: { name: 'Controlled Firing Area', sua: true },
        NSA: { name: 'National Security Area', sua: true },
        MTR: { name: 'Military Training Route', sua: true },
        IR: { name: 'IFR Military Training Route', sua: true, parent: 'MTR' },
        VR: { name: 'VFR Military Training Route', sua: true, parent: 'MTR' },
        SR: { name: 'Slow Speed Training Route', sua: true, parent: 'MTR' },
        ATCAA: { name: 'ATC Assigned Airspace', sua: true },
        ALTRV: { name: 'Altitude Reservation', sua: true },
        REFUEL: { name: 'Aerial Refueling Track/Anchor', sua: true },
        LATN: { name: 'Low Altitude Tactical Navigation', sua: true },
        ROA: { name: 'Restricted Operations Area', sua: true },
        SOA: { name: 'Special Operations Area', sua: true },
        BMGZ: { name: 'Ballistic Missile Ground Zone', sua: true },
        AAA: { name: 'Anti-Aircraft Artillery Range', sua: true },
        SAM: { name: 'Surface-to-Air Missile Site', sua: true },
        UAS: { name: 'UAS Operating Area', sua: true },
        DRONE: { name: 'Drone Corridor/Area', sua: true },

        // International SUA equivalents
        DANGER: { name: 'Danger Area', sua: true, icao: true },
        TRA: { name: 'Temporary Reserved Airspace', sua: true, icao: true },
        TSA: { name: 'Temporary Segregated Area', sua: true, icao: true },
        CBA: { name: 'Cross Border Area', sua: true, icao: true },

        // Canadian Specific
        CYA: { name: 'Canadian Advisory Area', sua: true, country: 'CA' },
        CYR: { name: 'Canadian Restricted Area', sua: true, country: 'CA' },
        CYD: { name: 'Canadian Danger Area', sua: true, country: 'CA' },

        // Other Special Areas
        PJA: { name: 'Parachute Jump Area', sua: true },
        DEMO: { name: 'Aerial Demonstration Area', sua: true },
        GLIDER: { name: 'Glider Operations Area', sua: true },
        SPACE: { name: 'Space Launch/Reentry Corridor', sua: true },
        WILDLIFE: { name: 'Wildlife Refuge/Bird Sanctuary', sua: true },

        // Defense/Security
        ADIZ: { name: 'Air Defense Identification Zone', security: true },
        CADIZ: { name: 'Canadian ADIZ', security: true },
        DEWIZ: { name: 'Distant Early Warning Identification Zone', security: true },

        // TFR Types (FAR 91 references)
        TFR: { name: 'Temporary Flight Restriction', temporary: true },
        TFR_DISASTER: { name: 'Disaster/Hazard Area TFR', temporary: true, far: '91.137' },
        TFR_VIP: { name: 'Presidential/VIP TFR', temporary: true, far: '91.141' },
        TFR_SECURITY: { name: 'Security TFR', temporary: true, far: '99.7' },
        TFR_EMERGENCY: { name: 'Emergency TFR', temporary: true, far: '91.139' },
        TFR_SPACE: { name: 'Space Operations TFR', temporary: true, far: '91.143' },
        TFR_EVENT: { name: 'Sporting Event/Airshow TFR', temporary: true, far: '91.145' },
        TFR_STADIUM: { name: 'Stadium TFR', temporary: true, far: '91.145' },
        TFR_FIRE: { name: 'Wildfire/Fire Suppression TFR', temporary: true, far: '91.137' },
        TFR_HAZMAT: { name: 'Hazardous Materials TFR', temporary: true, far: '91.137' },
        TFR_VOLCANIC: { name: 'Volcanic Ash TFR', temporary: true, far: '91.137' },

        // Oceanic/High Altitude Performance
        RVSM: { name: 'Reduced Vertical Separation Minimum Airspace', performance: true },
        MNPS: { name: 'Minimum Navigation Performance Specification Airspace', performance: true },
        NAT_HLA: { name: 'North Atlantic High Level Airspace', performance: true },
        PBN: { name: 'Performance Based Navigation Airspace', performance: true },
        RNP_AR: { name: 'RNP Authorization Required Airspace', performance: true },

        // Flow Control/ATFM
        FEA: { name: 'Flow Evaluation Area', atfm: true },
        FCA: { name: 'Flow Constrained Area', atfm: true },
        AFP_AREA: { name: 'Airspace Flow Program Area', atfm: true },

        // Legacy/Historical (still referenced)
        TRSA: { name: 'Terminal Radar Service Area', legacy: true },
        ARSA: { name: 'Airport Radar Service Area', legacy: true },
        TCA: { name: 'Terminal Control Area', legacy: true, replaced: 'CLASS_B' },
    });

    const EARTH = Object.freeze({
        RADIUS_KM: 6371,
        RADIUS_NM: 3440.065,
        RADIUS_MI: 3958.8,
    });

    const DISTANCE = Object.freeze({
        NM_TO_KM: 1.852,
        NM_TO_MI: 1.15078,
        KM_TO_NM: 0.539957,
        MI_TO_NM: 0.868976,
    });

    const BOUNDS = Object.freeze({
        CONUS: { north: 49.0, south: 24.5, east: -66.9, west: -124.7 },
        CANADA: { north: 83.0, south: 41.7, east: -52.6, west: -141.0 },
        NORTH_AMERICA: { north: 83.0, south: 14.5, east: -52.6, west: -168.0 },
    });

    // ARTCC center coordinates for map zoom [lng, lat]
    const ARTCC_CENTERS = Object.freeze({
        'ZAB': [-109.5, 33.5],
        'ZAK': [-155, 58],
        'ZAN': [-150, 64],
        'ZAU': [-88, 42],
        'ZBW': [-71, 42.5],
        'ZDC': [-77, 39],
        'ZDV': [-105, 40],
        'ZFW': [-97, 33],
        'ZHN': [-157, 21],
        'ZHU': [-95, 30],
        'ZID': [-86, 40],
        'ZJX': [-82, 30],
        'ZKC': [-95, 39],
        'ZLA': [-118, 34],
        'ZLC': [-112, 42],
        'ZMA': [-80, 26],
        'ZME': [-90, 35],
        'ZMP': [-94, 45],
        'ZNY': [-74, 41],
        'ZOA': [-122, 38],
        'ZOB': [-82, 41],
        'ZSE': [-122, 47],
        'ZSU': [-66, 18],
        'ZTL': [-84, 34],
    });

    // ARTCC Tier 1 neighbors (direct boundary adjacency)
    const ARTCC_TOPOLOGY = Object.freeze({
        'ZAB': ['ZLA', 'ZDV', 'ZKC', 'ZFW', 'ZHU'],
        'ZAU': ['ZMP', 'ZKC', 'ZID', 'ZOB'],
        'ZBW': ['ZDC', 'ZNY', 'ZOB'],
        'ZDC': ['ZBW', 'ZNY', 'ZOB', 'ZID', 'ZTL', 'ZJX'],
        'ZDV': ['ZLC', 'ZLA', 'ZAB', 'ZMP', 'ZKC'],
        'ZFW': ['ZME', 'ZKC', 'ZAB', 'ZHU'],
        'ZHU': ['ZAB', 'ZFW', 'ZME', 'ZTL', 'ZJX', 'ZMA'],
        'ZID': ['ZAU', 'ZOB', 'ZDC', 'ZME', 'ZTL', 'ZKC'],
        'ZJX': ['ZMA', 'ZHU', 'ZTL', 'ZDC'],
        'ZKC': ['ZMP', 'ZAU', 'ZID', 'ZME', 'ZFW', 'ZAB', 'ZDV'],
        'ZLA': ['ZLC', 'ZOA', 'ZDV', 'ZAB'],
        'ZLC': ['ZDV', 'ZLA', 'ZMP', 'ZOA', 'ZSE'],
        'ZMA': ['ZJX', 'ZHU'],
        'ZME': ['ZTL', 'ZID', 'ZKC', 'ZFW', 'ZHU'],
        'ZMP': ['ZAU', 'ZOB', 'ZKC', 'ZDV', 'ZLC'],
        'ZNY': ['ZBW', 'ZDC', 'ZOB'],
        'ZOA': ['ZLA', 'ZSE', 'ZLC'],
        'ZOB': ['ZAU', 'ZMP', 'ZID', 'ZDC', 'ZNY', 'ZBW'],
        'ZSE': ['ZOA', 'ZLC'],
        'ZTL': ['ZID', 'ZDC', 'ZJX', 'ZME', 'ZHU'],
    });

    // DCC Region display order (for legend/UI sorting)
    const DCC_REGION_ORDER = Object.freeze({
        'NORTHEAST': 1,
        'SOUTHEAST': 2,
        'SOUTH_CENTRAL': 3,
        'MIDWEST': 4,
        'WEST': 5,
        'CANADA': 6,
        'MEXICO': 7,
        'CARIBBEAN': 8,
        'OTHER': 99,
    });

    // Airport → overlying ARTCC(s) for multi-ARTCC border airports
    // Values are arrays because some airports (JFK, PHL) border multiple ARTCCs
    const AIRPORT_ARTCC_OVERLAP = Object.freeze({
        'KJFK': Object.freeze(['ZNY', 'ZBW']), 'KEWR': Object.freeze(['ZNY']), 'KLGA': Object.freeze(['ZNY']),
        'KATL': Object.freeze(['ZTL']), 'KORD': Object.freeze(['ZAU']), 'KDEN': Object.freeze(['ZDV']),
        'KDFW': Object.freeze(['ZFW']), 'KLAX': Object.freeze(['ZLA']), 'KSFO': Object.freeze(['ZOA']),
        'KMIA': Object.freeze(['ZMA']), 'KBOS': Object.freeze(['ZBW']), 'KPHL': Object.freeze(['ZNY', 'ZDC']),
        'KIAD': Object.freeze(['ZDC']), 'KDCA': Object.freeze(['ZDC']), 'KBWI': Object.freeze(['ZDC']),
        'KMSP': Object.freeze(['ZMP']), 'KDTW': Object.freeze(['ZOB']), 'KCLT': Object.freeze(['ZTL']),
        'KPHX': Object.freeze(['ZAB']), 'KLAS': Object.freeze(['ZLA']), 'KIAH': Object.freeze(['ZHU']),
        'KHOU': Object.freeze(['ZHU']), 'KMCO': Object.freeze(['ZJX']), 'KSEA': Object.freeze(['ZSE']),
    });

    const GEOGRAPHIC = Object.freeze({
        DCC_REGIONS,
        DCC_REGION_ORDER,
        ARTCC_TO_DCC,
        ARTCC_CENTERS,
        ARTCC_TOPOLOGY,
        AIRPORT_ARTCC_OVERLAP,
        VATSIM_REGIONS,
        VATSIM_DIVISIONS,
        AIRSPACE_TYPES,
        EARTH,
        DISTANCE,
        BOUNDS,
    });

    // ===========================================
    // Airport Code Normalization Data
    // ===========================================

    // US airports with Y-prefix (would otherwise be misidentified as Canadian)
    const US_Y_AIRPORTS = new Set(['YIP', 'YNG', 'YUM', 'YKM', 'YKN']);

    // IATA 3-letter → ICAO 4-letter for non-K-prefix US airports/territories
    const IATA_TO_ICAO = Object.freeze({
        // Alaska (PA prefix)
        'ANC': 'PANC', 'FAI': 'PAFA', 'JNU': 'PAJN', 'BET': 'PABE', 'OME': 'PAOM',
        'OTZ': 'PAOT', 'SCC': 'PASC', 'ADQ': 'PADQ', 'DLG': 'PADL', 'CDV': 'PACV',
        'AKN': 'PAKN', 'BRW': 'PABR', 'CDB': 'PACD', 'ENA': 'PAEN', 'GST': 'PAGS',
        'HNS': 'PAHN', 'HOM': 'PAHO', 'KTN': 'PAKT', 'SIT': 'PASI', 'VDZ': 'PAVD',
        'WRG': 'PAWG', 'YAK': 'PAYA', 'SGY': 'PAGY', 'PSG': 'PAPG',
        // Hawaii (PH prefix)
        'HNL': 'PHNL', 'OGG': 'PHOG', 'LIH': 'PHLI', 'KOA': 'PHKO', 'ITO': 'PHTO',
        'MKK': 'PHMK', 'LNY': 'PHNY', 'JHM': 'PHJH', 'HNM': 'PHHN',
        // Pacific territories (PG prefix)
        'GUM': 'PGUM', 'SPN': 'PGSN', 'ROP': 'PGRO', 'TIQ': 'PGWT',
        // Puerto Rico (TJ prefix)
        'SJU': 'TJSJ', 'BQN': 'TJBQ', 'PSE': 'TJPS', 'RVR': 'TJRV',
        'MAZ': 'TJMZ', 'VQS': 'TJVQ', 'CPX': 'TJCP',
        // US Virgin Islands (TI prefix)
        'STT': 'TIST', 'STX': 'TISX',
    });

    // Reverse lookup: ICAO 4-letter → IATA 3-letter
    const ICAO_TO_IATA = {};
    Object.keys(IATA_TO_ICAO).forEach(function(iata) {
        ICAO_TO_IATA[IATA_TO_ICAO[iata]] = iata;
    });
    Object.freeze(ICAO_TO_IATA);

    // ===========================================
    // ARTCC/FIR Alias Normalization Data
    // ===========================================

    // Maps informal/short ARTCC/FIR codes to their canonical ICAO form.
    // Covers: Canadian FIRs, US Oceanic, Mexican FIRs, European pseudo-codes.
    var ARTCC_ALIASES = {};

    // Canadian FIRs: 3-letter FAA form + 3-letter short form → ICAO
    var CANADIAN_FIRS = {
        'CZEG': ['ZEG', 'CZE'],    // Edmonton
        'CZVR': ['ZVR', 'CZV'],    // Vancouver
        'CZWG': ['ZWG', 'CZW'],    // Winnipeg
        'CZYZ': ['ZYZ', 'CZY'],    // Toronto
        'CZQM': ['ZQM', 'CZM'],    // Moncton
        'CZQX': ['ZQX', 'CZX'],    // Gander Domestic
        'CZQO': ['ZQO', 'CZO'],    // Gander Oceanic
        'CZUL': ['ZUL', 'CZU'],    // Montreal
    };
    Object.keys(CANADIAN_FIRS).forEach(function(canonical) {
        ARTCC_ALIASES[canonical] = canonical;
        CANADIAN_FIRS[canonical].forEach(function(alias) {
            ARTCC_ALIASES[alias] = canonical;
        });
    });

    // US Oceanic/Territory ARTCCs: ICAO form → canonical FAA form
    ARTCC_ALIASES['KZAK'] = 'ZAK';   // Oakland Oceanic
    ARTCC_ALIASES['KZWY'] = 'ZWY';   // New York Oceanic
    ARTCC_ALIASES['PGZU'] = 'ZUA';   // Guam CERAP
    ARTCC_ALIASES['PAZA'] = 'ZAN';   // Anchorage ARTCC
    ARTCC_ALIASES['PAZN'] = 'ZAP';   // Anchorage Oceanic
    ARTCC_ALIASES['PHZH'] = 'ZHN';   // Honolulu

    // Mexican FIRs: informal short codes → ICAO
    ARTCC_ALIASES['ZMX']  = 'MMMX';  // Mexico City
    ARTCC_ALIASES['ZMZ']  = 'MMZT';  // Mazatlan
    ARTCC_ALIASES['ZMR']  = 'MMMD';  // Merida
    ARTCC_ALIASES['ZMT']  = 'MMTY';  // Monterrey
    ARTCC_ALIASES['MMFR'] = 'MMFR';  // Mexico FIR (already canonical)
    ARTCC_ALIASES['MMFO'] = 'MMFO';  // Mazatlan Oceanic FIR (already canonical)

    // European pseudo-code
    ARTCC_ALIASES['ZEU']  = 'EGTT';  // "Europe" → London FIR (primary reference)

    // Caribbean pseudo-code
    ARTCC_ALIASES['CAR']  = 'TJZS';  // "Caribbean" → San Juan FIR (primary reference)

    // Pacific Combined Facilities
    ARTCC_ALIASES['HCF']  = 'ZHN';   // Honolulu Combined Facility → ZHN
    ARTCC_ALIASES['PCF']  = 'ZHN';   // Pacific Combined Facility → ZHN (primary hub)

    Object.freeze(ARTCC_ALIASES);

    // ===========================================
    // 7. UI - Visualization Colors & Palettes
    // ===========================================

    // Traffic demand severity colors (Google Maps style)
    const DEMAND_COLORS = Object.freeze({
        GREEN:  { hex: '#28a745', name: 'Low', description: 'Free flow' },
        YELLOW: { hex: '#ffc107', name: 'Moderate', description: 'Building' },
        ORANGE: { hex: '#fd7e14', name: 'High', description: 'Congested' },
        RED:    { hex: '#dc3545', name: 'Critical', description: 'Severe' },
        GRAY:   { hex: '#6c757d', name: 'No Data', description: 'Unavailable' },
    });

    // Weather impact badge colors (for flight impact display)
    const WEATHER_IMPACT_COLORS = Object.freeze({
        DIRECT_CONVECTIVE: { bg: '#FF0000', text: '#FFFFFF', icon: '⚡', severity: 'critical' },
        DIRECT_TURB:       { bg: '#FF6600', text: '#FFFFFF', icon: '≋', severity: 'high' },
        DIRECT_ICE:        { bg: '#00BFFF', text: '#000000', icon: '❄', severity: 'high' },
        DIRECT:            { bg: '#FF4444', text: '#FFFFFF', icon: '⚠', severity: 'high' },
        NEAR_CONVECTIVE:   { bg: '#FF6666', text: '#000000', icon: '⚡', severity: 'moderate' },
        NEAR_TURB:         { bg: '#FFA500', text: '#000000', icon: '≋', severity: 'moderate' },
        NEAR_ICE:          { bg: '#87CEEB', text: '#000000', icon: '❄', severity: 'moderate' },
        NEAR:              { bg: '#FFAA44', text: '#000000', icon: '⚠', severity: 'low' },
    });

    // Status/alert level colors
    const STATUS_COLORS = Object.freeze({
        SUCCESS:  '#28a745',
        WARNING:  '#ffc107',
        DANGER:   '#dc3545',
        INFO:     '#17a2b8',
        PRIMARY:  '#007bff',
        SECONDARY: '#6c757d',
        DARK:     '#343a40',
        LIGHT:    '#f8f9fa',
    });

    // Carrier display colors - airline ICAO code → brand hex color
    const CARRIER_COLORS = Object.freeze({
        // US Majors
        'AAL': '#0078d2', 'UAL': '#0033a0', 'DAL': '#e01933',
        'SWA': '#f9b612', 'JBU': '#003876', 'ASA': '#00a8e0',
        'FFT': '#2b8542', 'NKS': '#ffd200', 'HAL': '#5b2e91',
        // Canadian Majors
        'ACA': '#f01428', 'WJA': '#00a4e4', 'TSC': '#e31837',
        'ROU': '#e4002b', 'WEN': '#00a4e4', 'POE': '#0033a0',
        'SWG': '#00a4e4', 'FLE': '#00a651',
        // Cargo
        'FDX': '#ff6600', 'UPS': '#351c15', 'GTI': '#002d72', 'ABX': '#cc0000',
        // US Regionals
        'SKW': '#1e90ff', 'RPA': '#4169e1', 'ENY': '#87ceeb',
        'PDT': '#0078d2', 'JIA': '#0033a0',
        // European
        'BAW': '#075aaa', 'DLH': '#00205b', 'AFR': '#002157',
        'KLM': '#00a1e4', 'VIR': '#e01933', 'IBE': '#d4a900',
        'AZA': '#006643', 'SAS': '#000066', 'TAP': '#00a651',
        'THY': '#c8102e', 'SWR': '#c8102e', 'AUA': '#c8102e',
        'BEL': '#002157', 'EIN': '#00a651', 'FIN': '#0033a0',
        'NAX': '#d81939', 'RYR': '#0033a0', 'EZY': '#ff6600', 'VLG': '#f0006f',
        // Middle East / Africa
        'UAE': '#d71921', 'QTR': '#5c0632', 'ETD': '#bd8b13',
        'SAA': '#006847', 'ETH': '#006341', 'MSR': '#00205b',
        'RJA': '#c8102e', 'GFA': '#c4a000', 'SVA': '#006747',
        // Asian
        'SIA': '#005f6a', 'CPA': '#006747', 'JAL': '#c8102e',
        'ANA': '#002a6b', 'KAL': '#005a9c', 'AAR': '#e30613',
        'EVA': '#00a651', 'CAL': '#e4007f', 'CES': '#e30613',
        'CSN': '#e30613', 'CCA': '#c8102e', 'HDA': '#f57c00',
        'THA': '#5c2e91', 'MAS': '#e30613', 'GIA': '#00599d',
        'VNA': '#e30613', 'AIC': '#ff6600',
        // Oceania
        'QFA': '#e31937', 'ANZ': '#00838f',
        // Latin America
        'AVA': '#e30613', 'LAN': '#002a5c', 'GLO': '#ff6600',
        'AZU': '#005eb8', 'AMX': '#00205b', 'CMP': '#0033a0',
        // Business/Charter
        'EJA': '#8b4513', 'XOJ': '#4a4a4a', 'LEJ': '#1a1a1a',
        // Fallbacks
        'OTHER': '#6c757d', 'UNKNOWN': '#adb5bd',
    });

    // ARTCC/FIR display colors — aligned with DCC region color families
    const ARTCC_COLORS = Object.freeze({
        // Northeast (blues) — DCC_REGIONS.NORTHEAST.color = '#007bff'
        'ZBW': '#1a73e8', 'ZNY': '#4285f4', 'ZDC': '#5b9bd5', 'ZOB': '#2196f3',
        'ZWY': '#90caf9',
        // Southeast (yellows/ambers) — DCC_REGIONS.SOUTHEAST.color = '#ffc107'
        'ZID': '#ffb300', 'ZJX': '#ff8f00', 'ZTL': '#ffc107', 'ZMA': '#ffd54f',
        'ZMO': '#ffe082',
        // South Central (oranges) — DCC_REGIONS.SOUTH_CENTRAL.color = '#fd7e14'
        'ZAB': '#e65100', 'ZFW': '#ff6d00', 'ZHU': '#ff9100', 'ZME': '#fd7e14',
        'ZHO': '#ffab40',
        // Midwest (greens) — DCC_REGIONS.MIDWEST.color = '#28a745'
        'ZAU': '#2e7d32', 'ZDV': '#43a047', 'ZKC': '#66bb6a', 'ZMP': '#81c784',
        // West (reds) — DCC_REGIONS.WEST.color = '#dc3545'
        'ZLA': '#c62828', 'ZOA': '#e53935', 'ZSE': '#ef5350', 'ZLC': '#ef9a9a',
        'ZAN': '#b71c1c', 'ZHN': '#d32f2f',
        'ZAK': '#ff8a80', 'ZAP': '#ff5252', 'ZUA': '#ff1744',
        // Canadian FIRs (purples) — DCC_REGIONS.CANADA.color = '#6f42c1'
        'CZYZ': '#9b59b6', 'CZUL': '#8e44ad', 'CZQM': '#6c3483',
        'CZQX': '#5b2c6f', 'CZQO': '#4a235a',
        'CZWG': '#ff69b4', 'CZEG': '#ff1493', 'CZVR': '#db7093',
        // Fallbacks
        'OTHER': '#6c757d', 'UNKNOWN': '#adb5bd',
    });

    // ARTCC/FIR human-readable labels
    const ARTCC_LABELS = Object.freeze({
        // US ARTCCs — CONUS
        'ZBW': 'Boston', 'ZNY': 'New York', 'ZDC': 'Washington',
        'ZJX': 'Jacksonville', 'ZMA': 'Miami', 'ZTL': 'Atlanta',
        'ZID': 'Indianapolis', 'ZOB': 'Cleveland', 'ZAU': 'Chicago',
        'ZMP': 'Minneapolis', 'ZKC': 'Kansas City', 'ZME': 'Memphis',
        'ZFW': 'Fort Worth', 'ZHU': 'Houston', 'ZAB': 'Albuquerque',
        'ZDV': 'Denver', 'ZLC': 'Salt Lake', 'ZLA': 'Los Angeles',
        'ZOA': 'Oakland', 'ZSE': 'Seattle',
        // US ARTCCs — Non-CONUS / Oceanic / Territory
        'ZAN': 'Anchorage', 'ZHN': 'Honolulu', 'ZSU': 'San Juan',
        'ZAK': 'Oakland Oceanic', 'ZAP': 'Anchorage Oceanic', 'ZUA': 'Guam',
        'ZHO': 'Houston Oceanic', 'ZMO': 'Miami Oceanic', 'ZWY': 'NY Oceanic',
        // Canadian FIRs
        'CZYZ': 'Toronto', 'CZUL': 'Montreal',
        'CZQM': 'Moncton', 'CZQX': 'Gander', 'CZQO': 'Gander Oceanic',
        'CZWG': 'Winnipeg', 'CZEG': 'Edmonton', 'CZVR': 'Vancouver',
    });

    const UI = Object.freeze({
        DEMAND_COLORS,
        WEATHER_IMPACT_COLORS,
        STATUS_COLORS,
        CARRIER_COLORS,
        ARTCC_COLORS,
        ARTCC_LABELS,
    });

    // ===========================================
    // 8. SUA - Special Use Airspace Display
    // ===========================================

    // SUA/TFR type display names
    const SUA_TYPE_NAMES = Object.freeze({
        'P': 'Prohibited Area',
        'R': 'Restricted Area',
        'W': 'Warning Area',
        'A': 'Alert Area',
        'MOA': 'Military Operations Area',
        'NSA': 'National Security Area',
        'ATCAA': 'ATC Assigned Airspace',
        'IR': 'IR Route',
        'VR': 'VR Route',
        'SR': 'SR Route',
        'AR': 'Aerial Refueling',
        'TFR': 'Temporary Flight Restriction',
        'ALTRV': 'Altitude Reservation',
        'OPAREA': 'Operating Area',
        'AW': 'AWACS Orbit',
        'USN': 'US Navy',
        'DZ': 'Drop Zone',
        'ADIZ': 'Air Defense Identification Zone',
        'OSARA': 'Offshore Airspace Restricted Area',
        'WSRP': 'Weather Surveillance Radar Program',
        'SS': 'Supersonic',
        'USArmy': 'US Army',
        'LASER': 'Laser',
        'USAF': 'US Air Force',
        'ANG': 'Air National Guard',
        'NUCLEAR': 'Nuclear',
        'NORAD': 'NORAD',
        'NOAA': 'NOAA',
        'NASA': 'NASA',
        'MODEC': 'Mode C Veil',
        'FRZ': 'Flight Restricted Zone',
        'SFRA': 'Special Flight Rules Area',
        'PROHIBITED': 'Prohibited Area',
        'RESTRICTED': 'Restricted Area',
        'WARNING': 'Warning Area',
        'ALERT': 'Alert Area',
        'OTHER': 'Other',
        'Unknown': 'Other',
        '120': 'DC Speed Restriction',
        '180': 'DC Special Flight Rules Area',
    });

    // SUA group display names
    const SUA_GROUPS = Object.freeze({
        'REGULATORY': 'Regulatory',
        'MILITARY': 'Military',
        'ROUTES': 'Routes',
        'SPECIAL': 'Special',
        'DC_AREA': 'DC NCR',
        'SURFACE_OPS': 'Surface Ops',
        'AWACS': 'AWACS',
        'OTHER': 'Other',
    });

    // Types that render as lines (routes, tracks) - NOT converted to polygons
    const SUA_LINE_TYPES = Object.freeze([
        'AR',      // Air Refueling tracks
        'ALTRV',   // Altitude Reservations
        'IR',      // IFR Routes
        'VR',      // VFR Routes
        'SR',      // Slow Routes
        'SS',      // Supersonic corridors
        'MTR',     // Military Training Routes
        'ANG',     // Air National Guard routes
        'WSRP',    // Weather routes
        'AW',      // AWACS orbits (oval/racetrack)
        'AWACS',   // AWACS orbits
    ]);

    // Map SUA types to layer groups
    const SUA_LAYER_GROUPS = Object.freeze({
        'PROHIBITED': 'REGULATORY', 'RESTRICTED': 'REGULATORY', 'WARNING': 'REGULATORY',
        'ALERT': 'REGULATORY', 'P': 'REGULATORY', 'R': 'REGULATORY', 'W': 'REGULATORY',
        'A': 'REGULATORY', 'NSA': 'REGULATORY',
        'MOA': 'MILITARY', 'ATCAA': 'MILITARY', 'ALTRV': 'MILITARY', 'USAF': 'MILITARY',
        'USArmy': 'MILITARY', 'ANG': 'MILITARY', 'USN': 'MILITARY', 'NORAD': 'MILITARY',
        'OPAREA': 'MILITARY',
        'AR': 'ROUTES', 'IR': 'ROUTES', 'VR': 'ROUTES', 'SR': 'ROUTES', 'MTR': 'ROUTES',
        'OSARA': 'ROUTES',
        'TFR': 'SPECIAL', 'DZ': 'SPECIAL', 'SS': 'SPECIAL', 'LASER': 'SPECIAL',
        'NUCLEAR': 'SPECIAL',
        'SFRA': 'DC_AREA', 'FRZ': 'DC_AREA', 'ADIZ': 'DC_AREA', '120': 'DC_AREA',
        '180': 'DC_AREA',
        'AW': 'AWACS',
        'NOAA': 'OTHER', 'NASA': 'OTHER', 'MODEC': 'OTHER', 'WSRP': 'OTHER',
        'SUA': 'OTHER', 'Unknown': 'OTHER',
    });

    const SUA = Object.freeze({
        TYPE_NAMES: SUA_TYPE_NAMES,
        GROUPS: SUA_GROUPS,
        LINE_TYPES: SUA_LINE_TYPES,
        LAYER_GROUPS: SUA_LAYER_GROUPS,
    });

    // ===========================================
    // 9. ROUTE - Route Parsing & Expansion
    // ===========================================

    // Route token types (what each element in a route string represents)
    const ROUTE_TOKEN_TYPES = Object.freeze({
        AIRPORT: 'AIRPORT',         // KJFK, KLAX
        AIRWAY: 'AIRWAY',           // J48, V1, Q100
        FIX: 'FIX',                 // MERIT, WAVEY (5-letter)
        NAVAID: 'NAVAID',           // LAX, JFK (3-letter VOR/NDB)
        SID: 'SID',                 // SKORR5, KAYLN3
        STAR: 'STAR',               // CAMRN4, WYNDE3
        LATLON: 'LATLON',           // 4030N07421W
        LATLON_PACKED: 'LATLON_PACKED',  // K0403/07421
        RADIAL_DME: 'RADIAL_DME',   // LAX180020
        DCT: 'DCT',                 // Direct (DCT, DIRECT, ..)
        RUNWAY: 'RUNWAY',           // 27L, 09R
        ALTITUDE: 'ALTITUDE',       // FL350, A050
        SPEED: 'SPEED',             // N0450, M082
        UNKNOWN: 'UNKNOWN',
    });

    // Route segment types (connection between two points)
    const ROUTE_SEGMENT_TYPES = Object.freeze({
        DIRECT: 'DIRECT',           // Point-to-point (DCT)
        AIRWAY: 'AIRWAY',           // Via published airway
        DEPARTURE: 'DEPARTURE',     // SID/DP procedure
        ARRIVAL: 'ARRIVAL',         // STAR procedure
        APPROACH: 'APPROACH',       // Instrument approach
        OCEANIC: 'OCEANIC',         // Oceanic track
        RANDOM: 'RANDOM',           // Random route
    });

    // Oceanic track systems
    const OCEANIC_TRACKS = Object.freeze({
        NAT: { name: 'North Atlantic Tracks', prefix: 'NAT', direction: 'EW' },
        PACOTS: { name: 'Pacific Organized Track System', prefix: 'PACOT', direction: 'EW' },
        NOPAC: { name: 'North Pacific Routes', prefix: 'NOPAC', direction: 'EW' },
        POLAR: { name: 'Polar Routes', prefix: 'POLAR', direction: 'NS' },
    });

    // Common route format keywords
    const ROUTE_KEYWORDS = Object.freeze({
        DIRECT: ['DCT', 'DIRECT', '..'],
        THEN: ['/'],
        CRUISE_CLIMB: ['C/'],
        SPEED_CHANGE: ['N', 'M', 'K'],
        ALTITUDE_CHANGE: ['F', 'A', 'S', 'VFR'],
    });

    // Playbook route patterns (FAA Coded Departure Routes)
    const PLAYBOOK_FORMAT = Object.freeze({
        // Format: PLAY_NAME.ORIGIN.DEST or PLAY_NAME.ORIGIN-DEST
        SEPARATOR: '.',
        ORIGIN_DEST_SEPARATOR: '-',
        WILDCARD: '*',
    });

    // DP/STAR procedure format
    const PROCEDURE_FORMAT = Object.freeze({
        // DP format: {NAME}{VERSION#}.{TRANSITION} e.g., KAYLN3.SMUUV
        // STAR format: {TRANSITION}.{NAME}{VERSION#} e.g., SMUUV.WYNDE3
        DP_TRANSITION_SEPARATOR: '.',
        STAR_TRANSITION_SEPARATOR: '.',
        VERSION_PATTERN: /^([A-Z]+)(\d+)$/,  // Extract root and version
    });

    // ICAO flight plan field formats
    const ICAO_ROUTE_FIELD = Object.freeze({
        // Field 15 (Route) element patterns
        SID_PATTERN: /^[A-Z]{3,}\d[A-Z]?$/,              // FAA: SKORR5, KAYLN3
        SID_INTL_PATTERN: /^[A-Z]{3,}\d[A-Z]$/,          // ICAO: UTIRA1A, SALIS2B
        STAR_PATTERN: /^[A-Z]{3,}\d[A-Z]?$/,             // FAA: CAMRN4
        STAR_INTL_PATTERN: /^[A-Z]{3,}\d[A-Z]$/,         // ICAO: STAR5B
        AIRWAY_PATTERN: /^[JVQTYLMABGRHWNUP]\d+$/i,      // J48, V1, H1, W5, etc.
        FIX_PATTERN: /^[A-Z]{2,5}$/,                     // MERIT
        LATLON_PATTERN: /^\d{2,4}[NS]\d{3,5}[EW]$/,      // 4030N07421W
        LATLON_DECIMAL: /^[NS]?\d{1,3}\.\d+\/?[EW]?\d{1,3}\.\d+$/i, // N40.50/W074.35
        LATLON_PACKED: /^\d{2}[NS]\d{3}[EW]$/,           // 40N074W (ocean waypoints)
        SPEED_LEVEL: /^[NMK]\d{4}[FSAM]\d{3,4}$/,        // N0450F350
    });

    // Route expansion states
    const ROUTE_EXPANSION_STATUS = Object.freeze({
        SUCCESS: 'SUCCESS',
        PARTIAL: 'PARTIAL',         // Some elements couldn't be resolved
        AIRWAY_NOT_FOUND: 'AIRWAY_NOT_FOUND',
        FIX_NOT_ON_AIRWAY: 'FIX_NOT_ON_AIRWAY',
        PROCEDURE_NOT_FOUND: 'PROCEDURE_NOT_FOUND',
        UNKNOWN_ELEMENT: 'UNKNOWN_ELEMENT',
    });

    const ROUTE = Object.freeze({
        TOKEN_TYPES: ROUTE_TOKEN_TYPES,
        SEGMENT_TYPES: ROUTE_SEGMENT_TYPES,
        OCEANIC_TRACKS,
        KEYWORDS: ROUTE_KEYWORDS,
        PLAYBOOK_FORMAT,
        PROCEDURE_FORMAT,
        ICAO_ROUTE_FIELD,
        EXPANSION_STATUS: ROUTE_EXPANSION_STATUS,
    });

    // ===========================================
    // 10. CODING - Pattern Matching & Regex
    // ===========================================

    // Aviation identifier patterns (regex strings for construction)
    const IDENTIFIER_PATTERNS = Object.freeze({
        // Facility patterns
        ARTCC: '^Z[A-Z]{2}$',                                    // ZAB, ZNY, ZDC
        TRACON: '^[A-Z][0-9]{2}$|^(NCT|PCT|SCT|A11|A80|A90|C90|D01|D10|D21|F11|I90|L30|M03|M98|N90|P31|P50|P80|R90|S46|S56|T75|U90|Y90)$',
        AIRPORT_ICAO: '^[A-Z]{4}$',                              // KJFK, KLAX
        AIRPORT_FAA: '^[A-Z0-9]{3}$',                            // JFK, LAX, 1O1
        FIR_ICAO: '^[A-Z]{4}$',                                  // CZEG, EGTT

        // Airway/Route patterns (incl. international prefixes H, W, N, U, P)
        AIRWAY: '^[JVQTYLMABGRHWNUP]\\d+$',                     // J48, V1, Q100, T280, H1, W5
        AIRWAY_HIGH: '^J\\d+$',                                  // J48 (Jet routes)
        AIRWAY_LOW: '^V\\d+$',                                   // V1 (Victor routes)
        AIRWAY_RNAV_HIGH: '^Q\\d+$',                             // Q100 (RNAV high)
        AIRWAY_RNAV_LOW: '^T\\d+$',                              // T280 (RNAV low)
        AIRWAY_OCEANIC: '^[ABLMGRHWNUP]\\d+$',                   // L888, M711, A1, H1

        // Procedure patterns
        SID: '^[A-Z]{3,}\\d[A-Z]?$',                             // SKORR5, ANJLL4
        STAR: '^[A-Z]{3,}\\d[A-Z]?$',                            // CAMRN4, TRUDE2
        APPROACH: '^(ILS|RNAV|VOR|LOC|NDB|GPS|LDA|SDF|RNP).*$',

        // Fix/Navaid patterns
        NAVAID_VOR: '^[A-Z]{3}$',                                // LAX, JFK (3-letter)
        NAVAID_NDB: '^[A-Z]{2,3}$',                              // LC, ABC
        FIX_5CHAR: '^[A-Z]{5}$',                                 // MERIT, WAVEY
        FIX_NAMED: '^[A-Z]{2,5}$',                               // Named fixes
        FIX_LATLON: '^\\d{2,4}[NS]\\d{3,5}[EW]$',               // 4030N07421W
        FIX_LATLON_PACKED: '^\\d{2}[NS]\\d{3}[EW]$',            // 40N074W (ocean waypoints)
        FIX_RADIAL_DME: '^[A-Z]{3}\\d{3}\\d{3}$',               // LAX180020 (VOR/radial/DME)

        // Military patterns per FAA JO 7110.65 §2-4-20
        MILITARY_SERIAL: '^\\d{5}$',                              // Generic 5-digit serial suffix
        MILITARY_USAF_CS: '^[A-Z]{3,6}\\d{1,5}$',               // USAF: REACH12345, DARK21
        MILITARY_NAVY_CS: '^NAVY\\s?[A-Z]{2}\\d{2,3}$',         // Navy: NAVY GA201
        MILITARY_MARINE_CS: '^MARINE\\s?[A-Z]{2}\\d{2,3}$',     // Marine: MARINE DC102
        MILITARY_ARMY_CS: '^ARMY\\s?\\d{5}$',                    // Army: ARMY 32176
        MILITARY_CG_CS: '^(COAST\\s?GUARD|CG)\\s?\\d{4,5}$',   // Coast Guard: CG 6579
        MILITARY_SAM: '^SAM\\s?\\d{3,5}$',                      // Special Air Mission
        MILITARY_EVAC: '^(AIR\\s?EVAC|EVAC)\\s?\\d{3,5}$',     // Air Evacuation
        MILITARY_REACH: '^REACH\\s?\\d{3,5}$',                  // Air Mobility Command

        // Canadian patterns
        CANADIAN_FIR: '^CZ[A-Z]{2}$',                            // CZEG, CZUL
        CANADIAN_AIRPORT: '^C[A-Z]{3}$',                         // CYYZ, CYVR
        CANADIAN_ARTCC_STYLE: '^Y[A-Z]{2}$',                     // YUL, YYZ (short form)
    });

    // Pre-compiled regex objects for common patterns
    const PATTERNS = Object.freeze({
        ARTCC: /^Z[A-Z]{2}$/,
        TRACON: /^[A-Z][0-9]{2}$|^(NCT|PCT|SCT|A11|A80|A90|C90|D01|D10|D21|F11|I90|L30|M03|M98|N90|P31|P50|P80|R90|S46|S56|T75|U90|Y90)$/i,
        AIRPORT_ICAO: /^[A-Z]{4}$/,
        AIRPORT_FAA: /^[A-Z0-9]{3}$/,
        AIRWAY: /^[JVQTYLMABGRHWNUP]\d+$/i,
        AIRWAY_HIGH: /^J\d+$/i,
        AIRWAY_LOW: /^V\d+$/i,
        AIRWAY_RNAV: /^[QT]\d+$/i,
        AIRWAY_OCEANIC: /^[ABLMGRHWNUP]\d+$/i,
        SID_STAR: /^[A-Z]{3,}\d[A-Z]?$/,
        FIX_5CHAR: /^[A-Z]{5}$/,
        FIX_LATLON: /^\d{2,4}[NS]\d{3,5}[EW]$/,
        FIX_LATLON_PACKED: /^\d{2}[NS]\d{3}[EW]$/,
        FIX_RADIAL_DME: /^[A-Z]{3}\d{3}\d{3}$/,
        MILITARY_TAIL: /^(ARMY|NAVY|CG|AF|REACH|SAM)\s?\d{3,6}$/i,
        MILITARY_CALLSIGN: /^[A-Z]{3,6}\d{1,5}$/,
        CANADIAN_AIRPORT: /^C[A-Z]{3}$/,
    });

    // Aircraft type patterns for manufacturer/config identification
    const AIRCRAFT_TYPE_PATTERNS = Object.freeze({
        JET: /^(B7|A3|A2|B73|B74|B75|B76|B77|B78|A31|A32|A33|A34|A35|A38|CRJ|E1|E2|E7|E9|MD|DC|GLF|C5|C17|CL|LJ|H25|F9|FA|GALX|G[1-6])/i,
        TURBOPROP: /^(AT[4-7]|DH8|B19|SF3|E12|PC12|C208|PAY|SW[234]|J31|J41|BE[19]|DHC|D328)/i,
        PROP: /^(C1[2-8]|C20|C21|PA|BE[2-6]|M20|SR2|DA[24]|P28|AA[15]|C17[02]|C18[02]|C206|C210)/i,
        HELICOPTER: /^(A1|AS|B0|B2|B4|B6|BK|EC|H1|H5|H6|MD5|R22|R44|S76|S92|UH)/i,
        SUPERSONIC: /^CONC|^T144|^TU144/i,
    });

    // Route segment parsing patterns
    const ROUTE_PATTERNS = Object.freeze({
        // Segment format: "FIX AIRWAY FIX" (e.g., "LANNA J48 MOL")
        AIRWAY_SEGMENT: /^([A-Z]{2,5})\s+([JVQTYLMABGRHWNUP]\d+)\s+([A-Z]{2,5})$/i,

        // Via pattern: "arrivals/departures via X" (e.g., "KBOS arrivals via MERIT")
        VIA_PATTERN: /^(\w+)\s+(arr(?:ivals?)?|dep(?:artures?)?)\s+via\s+(\w+)$/i,

        // Two-fix segment: "FIX FIX" (e.g., "MERIT WAVEY")
        TWO_FIX_SEGMENT: /^([A-Z]{2,5})\s+([A-Z]{2,5})$/,

        // STAR/SID with runway: "CAMRN4.KSFO", "KSFO.SAHEY5"
        PROCEDURE_RUNWAY: /^([A-Z]{3,}\d[A-Z]?)\.([A-Z]{3,4})$/,

        // Lat/lon waypoint: 4030N07421W
        LATLON_WAYPOINT: /^(\d{2})(\d{2})([NS])(\d{3})(\d{2})([EW])$/,
    });

    // Weight class filter patterns
    const WEIGHT_CLASS_CODES = Object.freeze([
        'SUPER', 'HEAVY', 'LARGE', 'SMALL',  // Standard WTC
        'J', 'H', 'L', 'S',                   // RECAT-EU abbreviations
    ]);

    const CODING = Object.freeze({
        IDENTIFIER_PATTERNS,
        PATTERNS,
        AIRCRAFT_TYPE_PATTERNS,
        ROUTE_PATTERNS,
        WEIGHT_CLASS_CODES,
    });

    // ===========================================
    // Helper Functions
    // ===========================================

    /**
     * Get DCC region for an ARTCC/FIR code
     * @param {string} artcc - ARTCC or FIR code
     * @returns {string} DCC region key (e.g., 'NORTHEAST') or 'OTHER'
     */
    function getDCCRegion(artcc) {
        if (!artcc) return 'OTHER';
        return ARTCC_TO_DCC[artcc.toUpperCase()] || 'OTHER';
    }

    /**
     * Get color for a DCC region
     * @param {string} region - DCC region key
     * @returns {string} Hex color code
     */
    function getDCCColor(region) {
        if (!region) return DCC_REGIONS.OTHER.color;
        return DCC_REGIONS[region]?.color || DCC_REGIONS.OTHER.color;
    }

    /**
     * Get all ARTCCs for a DCC region
     * @param {string} region - DCC region key
     * @returns {string[]} Array of ARTCC codes
     */
    function getARTCCsForRegion(region) {
        if (!region) return [];
        return DCC_REGIONS[region]?.artccs || [];
    }

    /**
     * Get VATSIM division for an ICAO prefix
     * @param {string} icaoPrefix - One or two character ICAO prefix
     * @returns {string|null} Division code or null
     */
    function getVATSIMDivision(icaoPrefix) {
        if (!icaoPrefix) return null;
        const prefix = icaoPrefix.toUpperCase();

        for (const [divCode, divData] of Object.entries(VATSIM_DIVISIONS)) {
            if (divData.icaoPrefixes.includes(prefix)) {
                return divCode;
            }
            // Try two-char match
            if (prefix.length >= 2) {
                const twoChar = prefix.substring(0, 2);
                if (divData.icaoPrefixes.includes(twoChar)) {
                    return divCode;
                }
            }
            // Try single-char match
            if (divData.icaoPrefixes.includes(prefix.charAt(0))) {
                return divCode;
            }
        }
        return null;
    }

    /**
     * Get VATSIM region for a division
     * @param {string} division - Division code
     * @returns {string|null} Region code or null
     */
    function getVATSIMRegion(division) {
        if (!division) return null;
        return VATSIM_DIVISIONS[division]?.region || null;
    }

    /**
     * Check if a division has DCC regions
     * @param {string} division - Division code
     * @returns {boolean}
     */
    function hasDCCRegions(division) {
        if (!division) return false;
        return VATSIM_DIVISIONS[division]?.hasDCC === true;
    }

    /**
     * Get TMI type information
     * @param {string} code - TMI type code
     * @returns {Object|null} TMI type info or null
     */
    function getTMIType(code) {
        if (!code) return null;
        return TMI_TYPES[code.toUpperCase()] || null;
    }

    /**
     * Get TMI scope
     * @param {string} code - TMI type code
     * @returns {string|null} Scope string or null
     */
    function getTMIScope(code) {
        const tmi = getTMIType(code);
        return tmi?.scope || null;
    }

    /**
     * Get weather category based on ceiling and visibility
     * @param {number} ceiling - Ceiling in feet AGL
     * @param {number} visibility - Visibility in statute miles
     * @returns {string} Weather category key (VMC, LVMC, IMC, LIMC, VLIMC)
     */
    function getWeatherCategory(ceiling, visibility) {
        // VLIMC: <200 ceiling or <0.5 visibility
        if (ceiling < 200 || visibility < 0.5) return 'VLIMC';
        // LIMC: <500 ceiling or <1 visibility
        if (ceiling < 500 || visibility < 1) return 'LIMC';
        // IMC: <1000 ceiling or <3 visibility
        if (ceiling < 1000 || visibility < 3) return 'IMC';
        // LVMC: 1000-3000 ceiling or 3-5 visibility
        if (ceiling < 3000 || visibility < 5) return 'LVMC';
        // VMC: >=3000 ceiling and >=5 visibility
        return 'VMC';
    }

    /**
     * Get SIGMET type information
     * @param {string} code - SIGMET type code
     * @returns {Object|null} SIGMET type info or null
     */
    function getSigmetType(code) {
        if (!code) return null;
        return SIGMET_TYPES[code.toUpperCase()] || null;
    }

    /**
     * Get AIRMET type information
     * @param {string} code - AIRMET type code
     * @returns {Object|null} AIRMET type info or null
     */
    function getAirmetType(code) {
        if (!code) return null;
        return AIRMET_TYPES[code.toUpperCase()] || null;
    }

    /**
     * Get flight status information
     * @param {string} code - Flight status code
     * @returns {Object|null} Flight status info or null
     */
    function getFlightStatus(code) {
        if (!code) return null;
        return FLIGHT_STATUS[code.toUpperCase()] || null;
    }

    /**
     * Check if flight is active
     * @param {string} code - Flight status code
     * @returns {boolean}
     */
    function isFlightActive(code) {
        const status = getFlightStatus(code);
        return status?.active === true;
    }

    /**
     * Get airspace type information
     * @param {string} code - Airspace type code
     * @returns {Object|null} Airspace type info or null
     */
    function getAirspaceType(code) {
        if (!code) return null;
        return AIRSPACE_TYPES[code.toUpperCase()] || null;
    }

    /**
     * Check if airspace type is SUA
     * @param {string} code - Airspace type code
     * @returns {boolean}
     */
    function isSUA(code) {
        const type = getAirspaceType(code);
        return type?.sua === true;
    }

    /**
     * Check if airspace type is a TFR
     * @param {string} code - Airspace type code
     * @returns {boolean}
     */
    function isTFR(code) {
        if (!code) return false;
        const upper = code.toUpperCase();
        return upper === 'TFR' || upper.startsWith('TFR_');
    }

    /**
     * Get ARTCC center coordinates
     * @param {string} artcc - ARTCC code
     * @returns {number[]|null} [lng, lat] or null if not found
     */
    function getARTCCCenter(artcc) {
        if (!artcc) return null;
        return ARTCC_CENTERS[artcc.toUpperCase()] || null;
    }

    /**
     * Get ARTCC tier 1 neighbors
     * @param {string} artcc - ARTCC code
     * @returns {string[]} Array of neighbor ARTCC codes
     */
    function getARTCCNeighbors(artcc) {
        if (!artcc) return [];
        return ARTCC_TOPOLOGY[artcc.toUpperCase()] || [];
    }

    /**
     * Get IATA airline code from ICAO code
     * @param {string} icao - ICAO airline code (3-letter)
     * @returns {string|null} IATA code or null
     */
    function getAirlineCode(icao) {
        if (!icao) return null;
        return AIRLINE_CODES[icao.toUpperCase()] || null;
    }

    /**
     * Get carrier display color
     * @param {string} icao - Airline ICAO code
     * @returns {string} Hex color or default gray
     */
    function getCarrierColor(icao) {
        if (!icao) return CARRIER_COLORS.OTHER;
        return CARRIER_COLORS[icao.toUpperCase()] || CARRIER_COLORS.OTHER;
    }

    /**
     * Get ARTCC/FIR display color
     * @param {string} code - ARTCC or FIR code
     * @returns {string} Hex color or default gray
     */
    function getARTCCColor(code) {
        if (!code) return ARTCC_COLORS.OTHER;
        return ARTCC_COLORS[code.toUpperCase()] || ARTCC_COLORS.OTHER;
    }

    /**
     * Get ARTCC/FIR display label
     * @param {string} code - ARTCC or FIR code
     * @returns {string} Human-readable label or the code itself
     */
    function getARTCCLabel(code) {
        if (!code) return '';
        return ARTCC_LABELS[code.toUpperCase()] || code;
    }

    /**
     * Check if a TMI type requires external coordination
     * @param {string} tmiType - TMI type code
     * @returns {boolean}
     */
    function isCoordinationRequired(tmiType) {
        if (!tmiType) return false;
        return COORDINATION_REQUIRED_TYPES.includes(tmiType.toUpperCase());
    }

    /**
     * Get DCC region display order
     * @param {string} region - DCC region key
     * @returns {number} Display order (lower = first)
     */
    function getRegionOrder(region) {
        if (!region) return DCC_REGION_ORDER.OTHER;
        return DCC_REGION_ORDER[region.toUpperCase()] || DCC_REGION_ORDER.OTHER;
    }

    /**
     * Get all CONUS ARTCCs
     * @returns {string[]} Array of CONUS ARTCC codes
     */
    function getCONUSARTCCs() {
        return [...FACILITY_LISTS.ARTCC_CONUS];
    }

    /**
     * Get all ARTCCs (including AK, HI, GU, PR)
     * @returns {string[]} Array of all US ARTCC codes
     */
    function getAllARTCCs() {
        return [...FACILITY_LISTS.ARTCC_ALL];
    }

    // ===========================================
    // Airport/ARTCC Code Normalization Functions
    // ===========================================

    /**
     * Normalize airport code to ICAO 4-letter format.
     * Region-aware: handles US CONUS, Canada, Alaska, Hawaii, Pacific, PR, USVI.
     *
     * @param {string} code - Airport code (3-letter IATA/FAA or 4-letter ICAO)
     * @returns {string} 4-letter ICAO code (e.g., JFK→KJFK, YYZ→CYYZ, ANC→PANC)
     */
    function normalizeIcao(code) {
        if (!code) return code;
        var upper = String(code).toUpperCase().trim();

        // Already 4+ characters — return as-is
        if (upper.length >= 4) return upper;
        // Too short to normalize
        if (upper.length < 3) return upper;

        // 3-character codes — determine regional ICAO prefix
        if (upper.length === 3) {
            // Explicit IATA→ICAO lookup (Alaska, Hawaii, Pacific, PR, USVI)
            if (IATA_TO_ICAO[upper]) return IATA_TO_ICAO[upper];

            // Y-prefix: Canadian unless known US airport
            if (/^Y[A-Z]{2}$/.test(upper)) {
                return US_Y_AIRPORTS.has(upper) ? 'K' + upper : 'C' + upper;
            }

            // Default: CONUS K-prefix (includes alphanumeric FAA LIDs like 1O1, F05)
            return 'K' + upper;
        }

        return upper;
    }

    /**
     * Denormalize ICAO 4-letter code back to 3-letter IATA/FAA format.
     * Region-aware reverse of normalizeIcao().
     *
     * @param {string} icao - 4-letter ICAO code
     * @returns {string} 3-letter IATA/FAA code (e.g., KJFK→JFK, CYYZ→YYZ, PANC→ANC)
     */
    function denormalizeIcao(icao) {
        if (!icao) return icao;
        var upper = String(icao).toUpperCase().trim();

        // Must be exactly 4 characters
        if (upper.length !== 4) return upper;

        // Explicit ICAO→IATA lookup (Alaska, Hawaii, Pacific, PR, USVI)
        if (ICAO_TO_IATA[upper]) return ICAO_TO_IATA[upper];

        // K-prefix CONUS: KJFK → JFK
        if (upper.charAt(0) === 'K') return upper.slice(1);

        // Canadian airports: CYYZ → YYZ (CY**/CZ** prefix stripped)
        if (upper.charAt(0) === 'C' && (upper.charAt(1) === 'Y' || upper.charAt(1) === 'Z')) {
            return upper.slice(1);
        }

        return upper;
    }

    /**
     * Normalize ARTCC/FIR facility code to canonical form (without K-prefix).
     * GIS data sometimes uses K-prefixed ARTCC codes (KZBW); this strips the K.
     * Also resolves aliases: ZYZ→CZYZ, ZMX→MMMX, ZEU→EGTT, KZAK→ZAK, etc.
     * International FIR codes (CZYZ, EGTT) are returned unchanged.
     *
     * @param {string} code - ARTCC/FIR code or alias (ZBW, KZBW, CZYZ, ZYZ, ZMX, ZEU, etc.)
     * @returns {string} Canonical ARTCC/FIR code (ZBW, CZYZ, MMMX, EGTT, etc.)
     */
    function normalizeArtcc(code) {
        if (!code) return code;
        var upper = String(code).toUpperCase().trim();

        // Check alias table first (covers Canadian, Mexican, European, Oceanic aliases)
        if (ARTCC_ALIASES[upper]) {
            return ARTCC_ALIASES[upper];
        }

        // 4-char code starting with K followed by Z: KZBW → ZBW (US ARTCC with K-prefix)
        if (upper.length === 4 && upper.charAt(0) === 'K' && upper.charAt(1) === 'Z') {
            return upper.slice(1);
        }

        return upper;
    }

    /**
     * Resolve an ARTCC/FIR alias to its canonical code.
     * Unlike normalizeArtcc, this ONLY resolves aliases — it does NOT strip K-prefixes.
     * Use this when you need alias resolution without normalization side effects.
     *
     * @param {string} code - ARTCC/FIR code or alias
     * @returns {string} Canonical code, or input unchanged if not an alias
     */
    function resolveArtcc(code) {
        if (!code) return code;
        var upper = String(code).toUpperCase().trim();
        return ARTCC_ALIASES[upper] || upper;
    }

    // ===========================================
    // CODING Helper Functions
    // ===========================================

    /**
     * Test if a string is an airway identifier
     * @param {string} str - String to test
     * @returns {boolean}
     */
    function isAirway(str) {
        if (!str) return false;
        return PATTERNS.AIRWAY.test(str.toUpperCase());
    }

    /**
     * Test if a string is an ICAO airport code
     * @param {string} str - String to test
     * @returns {boolean}
     */
    function isAirportICAO(str) {
        if (!str) return false;
        return PATTERNS.AIRPORT_ICAO.test(str.toUpperCase());
    }

    /**
     * Test if a string is a 5-letter fix
     * @param {string} str - String to test
     * @returns {boolean}
     */
    function isFix(str) {
        if (!str) return false;
        return PATTERNS.FIX_5CHAR.test(str.toUpperCase());
    }

    /**
     * Test if a string is an ARTCC code
     * @param {string} str - String to test
     * @returns {boolean}
     */
    function isARTCC(str) {
        if (!str) return false;
        return PATTERNS.ARTCC.test(str.toUpperCase());
    }

    /**
     * Test if a string is a TRACON code
     * @param {string} str - String to test
     * @returns {boolean}
     */
    function isTRACON(str) {
        if (!str) return false;
        return PATTERNS.TRACON.test(str.toUpperCase());
    }

    /**
     * Test if a string is a SID/STAR procedure name
     * @param {string} str - String to test
     * @returns {boolean}
     */
    function isProcedure(str) {
        if (!str) return false;
        return PATTERNS.SID_STAR.test(str.toUpperCase());
    }

    /**
     * Test if a string is a military callsign/tail number
     * @param {string} str - String to test
     * @returns {boolean}
     */
    function isMilitaryCallsign(str) {
        if (!str) return false;
        const upper = str.toUpperCase();
        return PATTERNS.MILITARY_TAIL.test(upper) || PATTERNS.MILITARY_CALLSIGN.test(upper);
    }

    /**
     * Classify an aircraft type code
     * @param {string} typeCode - Aircraft type code
     * @returns {string|null} 'JET', 'TURBOPROP', 'PROP', 'HELICOPTER', 'SUPERSONIC', or null
     */
    function classifyAircraftType(typeCode) {
        if (!typeCode) return null;
        const upper = typeCode.toUpperCase();
        for (const [category, pattern] of Object.entries(AIRCRAFT_TYPE_PATTERNS)) {
            if (pattern.test(upper)) return category;
        }
        return null;
    }

    /**
     * Parse a route segment string into components
     * @param {string} segment - Route segment (e.g., "LANNA J48 MOL")
     * @returns {Object|null} Parsed segment or null
     */
    function parseRouteSegment(segment) {
        if (!segment) return null;
        const upper = segment.trim().toUpperCase();

        // Try airway segment: "FIX AIRWAY FIX"
        const airwayMatch = upper.match(ROUTE_PATTERNS.AIRWAY_SEGMENT);
        if (airwayMatch) {
            return {
                type: 'airway_segment',
                from: airwayMatch[1],
                airway: airwayMatch[2],
                to: airwayMatch[3],
            };
        }

        // Try two-fix segment: "FIX FIX"
        const twoFixMatch = upper.match(ROUTE_PATTERNS.TWO_FIX_SEGMENT);
        if (twoFixMatch && !PATTERNS.AIRWAY.test(twoFixMatch[1]) && !PATTERNS.AIRWAY.test(twoFixMatch[2])) {
            return {
                type: 'direct',
                from: twoFixMatch[1],
                to: twoFixMatch[2],
            };
        }

        // Single airway
        if (PATTERNS.AIRWAY.test(upper)) {
            return { type: 'airway', airway: upper };
        }

        // Single fix
        if (PATTERNS.FIX_5CHAR.test(upper)) {
            return { type: 'fix', fix: upper };
        }

        return null;
    }

    /**
     * Get the layer group for an SUA type
     * @param {string} suaType - SUA type code
     * @returns {string} Layer group name
     */
    function getSUALayerGroup(suaType) {
        if (!suaType) return 'OTHER';
        return SUA_LAYER_GROUPS[suaType.toUpperCase()] || SUA_LAYER_GROUPS[suaType] || 'OTHER';
    }

    /**
     * Get the display name for an SUA type
     * @param {string} suaType - SUA type code
     * @returns {string} Display name
     */
    function getSUATypeName(suaType) {
        if (!suaType) return 'Other';
        return SUA_TYPE_NAMES[suaType.toUpperCase()] || SUA_TYPE_NAMES[suaType] || 'Other';
    }

    /**
     * Check if SUA type should render as a line
     * @param {string} suaType - SUA type code
     * @returns {boolean}
     */
    function isSUALineType(suaType) {
        if (!suaType) return false;
        return SUA_LINE_TYPES.includes(suaType.toUpperCase()) || SUA_LINE_TYPES.includes(suaType);
    }

    // ===========================================
    // Export to Global Namespace
    // ===========================================

    global.PERTI = Object.freeze({
        // Namespaces
        ATFM,
        FACILITY,
        WEATHER,
        STATUS,
        COORDINATION,
        GEOGRAPHIC,
        UI,
        SUA,
        ROUTE,
        CODING,

        // Helper Functions - Geographic
        getDCCRegion,
        getDCCColor,
        getARTCCsForRegion,
        getARTCCCenter,
        getARTCCNeighbors,
        getRegionOrder,
        getCONUSARTCCs,
        getAllARTCCs,

        // Helper Functions - VATSIM
        getVATSIMDivision,
        getVATSIMRegion,
        hasDCCRegions,

        // Helper Functions - ATFM
        getTMIType,
        getTMIScope,
        isCoordinationRequired,

        // Helper Functions - Weather
        getWeatherCategory,
        getSigmetType,
        getAirmetType,

        // Helper Functions - Status
        getFlightStatus,
        isFlightActive,

        // Helper Functions - Airspace
        getAirspaceType,
        isSUA,
        isTFR,

        // Helper Functions - Facility
        getAirlineCode,
        getCarrierColor,
        getARTCCColor,
        getARTCCLabel,

        // Helper Functions - SUA Display
        getSUALayerGroup,
        getSUATypeName,
        isSUALineType,

        // Helper Functions - Airport/ARTCC Normalization
        normalizeIcao,
        denormalizeIcao,
        normalizeArtcc,
        resolveArtcc,
        ARTCC_ALIASES,
        IATA_TO_ICAO,
        ICAO_TO_IATA,

        // Helper Functions - Coding/Pattern Matching
        isAirway,
        isAirportICAO,
        isFix,
        isARTCC,
        isTRACON,
        isProcedure,
        isMilitaryCallsign,
        classifyAircraftType,
        parseRouteSegment,

        // Version
        VERSION: '1.6.0',
    });

})(typeof window !== 'undefined' ? window : typeof global !== 'undefined' ? global : this);

// Export for ES modules / Node.js if available
if (typeof module !== 'undefined' && module.exports) {
    module.exports = typeof PERTI !== 'undefined' ? PERTI : this.PERTI;
}
