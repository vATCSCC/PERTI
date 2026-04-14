# PERTI Unified Namespace Design

**Date:** 2026-02-02
**Module:** `assets/js/lib/perti.js`
**Purpose:** Centralized reference data for all PERTI application constants

## Overview

This module consolidates scattered data definitions into a single unified `PERTI` global namespace. All values use ALL_CAPS casing. Regional operational terms are preserved with ICAO mappings for internationalization.

**PERTI is the single source of truth.** Other modules (colors.js, facility-hierarchy.js, etc.) will consume from PERTI rather than defining their own constants.

## Namespace Hierarchy

```
PERTI
├── ATFM          - Air Traffic Flow Management
├── FACILITY      - Airports, aircraft, airlines
├── WEATHER       - Weather categories and phenomena
├── STATUS        - Operational statuses and levels
├── COORDINATION  - Communication and coordination
└── GEOGRAPHIC    - Regions, divisions, airspace types
```

---

## 1. ATFM Namespace

### TMI_TYPES (Traffic Management Initiatives)

```javascript
TMI_TYPES: {
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
},
```

### INITIATIVE_SCOPE

```javascript
INITIATIVE_SCOPE: {
    NATIONAL: { name: 'National', level: 1 },
    REGIONAL: { name: 'Regional', level: 2 },
    FACILITY: { name: 'Facility', level: 3 },
    AIRPORT: { name: 'Airport', level: 4 },
    FIX: { name: 'Fix/Waypoint', level: 5 },
    FLIGHT: { name: 'Individual Flight', level: 6 },
},
```

### DELAY_PROGRAMS

```javascript
DELAY_PROGRAMS: {
    GDP_RATE: { name: 'GDP by Arrival Rate', unit: 'aircraft/hour' },
    GDP_DELAY: { name: 'GDP by Delay', unit: 'minutes' },
    AFP_RATE: { name: 'AFP by Rate', unit: 'aircraft/hour' },
    AFP_MIT: { name: 'AFP with MIT', unit: 'nautical miles' },
    CTOP_RATE: { name: 'CTOP by Rate', unit: 'aircraft/hour' },
},
```

### SLOT_TYPES

```javascript
SLOT_TYPES: {
    ASSIGNED: { name: 'Assigned Slot', modifiable: false },
    SUBSTITUTION: { name: 'Slot Substitution', modifiable: true },
    COMPRESSION: { name: 'Compression Slot', modifiable: true },
    AADC: { name: 'Adaptive Algorithm Driven Compression', modifiable: true },
    BRIDGE: { name: 'Bridge Slot', modifiable: true },
},
```

### CDR_STATUS (Coded Departure Routes)

```javascript
CDR_STATUS: {
    OPEN: { name: 'Open', available: true },
    CLOSED: { name: 'Closed', available: false },
    CONDITIONAL: { name: 'Conditional', available: true, restricted: true },
},
```

---

## 2. FACILITY Namespace

### AIRCRAFT_CATEGORIES

```javascript
AIRCRAFT_CATEGORIES: {
    A: { name: 'Category A', wtc: 'L', approach: '<91 kt' },
    B: { name: 'Category B', wtc: 'L', approach: '91-120 kt' },
    C: { name: 'Category C', wtc: 'L/M', approach: '121-140 kt' },
    D: { name: 'Category D', wtc: 'M/H', approach: '141-165 kt' },
    E: { name: 'Category E', wtc: 'H', approach: '>165 kt' },
},
```

### WAKE_TURBULENCE

```javascript
WAKE_TURBULENCE: {
    L: { name: 'Light', maxWeight: 15500, icao: 'L' },
    M: { name: 'Medium', maxWeight: 300000, icao: 'M' },
    H: { name: 'Heavy', maxWeight: null, icao: 'H' },
    J: { name: 'Super', aircraft: ['A388', 'A225'], icao: 'J' },
},
```

### EQUIPMENT_SUFFIX

```javascript
EQUIPMENT_SUFFIX: {
    '/L': { name: 'RVSM with TCAS', rnav: false, rvsm: true },
    '/W': { name: 'RVSM without TCAS', rnav: false, rvsm: true },
    '/Z': { name: 'RVSM with RNAV/TCAS', rnav: true, rvsm: true },
    '/G': { name: 'GPS', rnav: true, rvsm: false },
    '/A': { name: 'DME/TACAN', rnav: false, rvsm: false },
    '/I': { name: 'INS/LORAN', rnav: true, rvsm: false },
},
```

### FLIGHT_RULES

```javascript
FLIGHT_RULES: {
    IFR: { name: 'Instrument Flight Rules', icao: 'I' },
    VFR: { name: 'Visual Flight Rules', icao: 'V' },
    SVFR: { name: 'Special VFR', icao: 'S' },
    DVFR: { name: 'Defense VFR', icao: 'D' },
    YFR: { name: 'IFR then VFR', icao: 'Y' },
    ZFR: { name: 'VFR then IFR', icao: 'Z' },
},
```

### AIRLINE_TYPES

```javascript
AIRLINE_TYPES: {
    MAINLINE: { name: 'Mainline Carrier' },
    REGIONAL: { name: 'Regional Carrier' },
    CARGO: { name: 'Cargo Carrier' },
    CHARTER: { name: 'Charter Operator' },
    LCC: { name: 'Low Cost Carrier' },
    ULCC: { name: 'Ultra Low Cost Carrier' },
    GA: { name: 'General Aviation' },
    MIL: { name: 'Military' },
    GOV: { name: 'Government' },
},
```

### REGIONAL_CARRIERS

```javascript
REGIONAL_CARRIERS: ['SKW', 'RPA', 'ENY', 'PDT', 'PSA', 'ASQ', 'GJS', 'CPZ', 'EDV', 'QXE', 'ASH', 'OO', 'AIP', 'MES', 'JIA', 'SCX'],
```

### AIRPORT_HUB_TYPES

```javascript
AIRPORT_HUB_TYPES: {
    LARGE: { name: 'Large Hub', paxShare: '>1%' },
    MEDIUM: { name: 'Medium Hub', paxShare: '0.25-1%' },
    SMALL: { name: 'Small Hub', paxShare: '0.05-0.25%' },
    NONHUB: { name: 'Non-Hub Primary', paxShare: '<0.05%' },
    RELIEVER: { name: 'Reliever', paxShare: null },
    GA: { name: 'General Aviation', paxShare: null },
},
```

### SPECIAL_FLIGHTS

```javascript
SPECIAL_FLIGHTS: {
    LIFEGUARD: { name: 'Lifeguard', priority: true, callsignPrefix: 'LIFEGUARD' },
    MEDEVAC: { name: 'Medical Evacuation', priority: true, callsignPrefix: 'MEDEVAC' },
    AIR_AMBULANCE: { name: 'Air Ambulance', priority: true, callsignPrefix: null },
    AIR_EVAC: { name: 'Air Evacuation', priority: true, callsignPrefix: 'AIR EVAC' },
    HOSP: { name: 'Hospital', priority: true, callsignPrefix: 'HOSP' },
    SAR: { name: 'Search and Rescue', priority: true, callsignPrefix: 'RESCUE' },
    FLIGHT_CHECK: { name: 'Flight Check', priority: false, callsignPrefix: 'FLIGHT CHECK' },
    HAZMAT: { name: 'Hazardous Materials', priority: false, callsignPrefix: null },
},
```

---

## 3. WEATHER Namespace

### CATEGORIES

```javascript
CATEGORIES: {
    VMC: { name: 'Visual Meteorological Conditions', color: '#28a745', ceiling: '>3000', visibility: '>5' },
    MVMC: { name: 'Marginal VMC', color: '#ffc107', ceiling: '1000-3000', visibility: '3-5' },
    IMC: { name: 'Instrument Meteorological Conditions', color: '#dc3545', ceiling: '<1000', visibility: '<3' },
    LIMC: { name: 'Low IMC', color: '#6f42c1', ceiling: '<500', visibility: '<1' },
    VLIMC: { name: 'Very Low IMC', color: '#343a40', ceiling: '<200', visibility: '<0.5' },
},
```

### SIGMET_TYPES

```javascript
SIGMET_TYPES: {
    CONVECTIVE: { name: 'Convective SIGMET', icao: 'WS', hazard: 'thunderstorm' },
    TURB: { name: 'Turbulence SIGMET', icao: 'WS', hazard: 'turbulence' },
    ICE: { name: 'Icing SIGMET', icao: 'WS', hazard: 'icing' },
    MTN_OBSCUR: { name: 'Mountain Obscuration', icao: 'WS', hazard: 'visibility' },
    IFR: { name: 'IFR Conditions', icao: 'WA', hazard: 'visibility' },
    ASH: { name: 'Volcanic Ash', icao: 'WV', hazard: 'volcanic' },
    TROPICAL: { name: 'Tropical Cyclone', icao: 'WT', hazard: 'tropical' },
},
```

### AIRMET_TYPES

```javascript
AIRMET_TYPES: {
    SIERRA: { name: 'AIRMET Sierra', hazard: 'IFR/mountain obscuration', icao: 'WA' },
    TANGO: { name: 'AIRMET Tango', hazard: 'turbulence/sustained winds/LLWS', icao: 'WA' },
    ZULU: { name: 'AIRMET Zulu', hazard: 'icing/freezing level', icao: 'WA' },
},
```

### PIREP_TYPES

```javascript
PIREP_TYPES: {
    UA: { name: 'Routine PIREP', priority: 'routine' },
    UUA: { name: 'Urgent PIREP', priority: 'urgent' },
},
```

### TURBULENCE_INTENSITY

```javascript
TURBULENCE_INTENSITY: {
    NEG: { name: 'Negative (None)', code: 0 },
    SMTH_LGT: { name: 'Smooth to Light', code: 1 },
    LGT: { name: 'Light', code: 2 },
    LGT_MOD: { name: 'Light to Moderate', code: 3 },
    MOD: { name: 'Moderate', code: 4 },
    MOD_SEV: { name: 'Moderate to Severe', code: 5 },
    SEV: { name: 'Severe', code: 6 },
    SEV_EXTM: { name: 'Severe to Extreme', code: 7 },
    EXTM: { name: 'Extreme', code: 8 },
},
```

### ICING_INTENSITY

```javascript
ICING_INTENSITY: {
    NEG: { name: 'Negative (None)', code: 0 },
    TRACE: { name: 'Trace', code: 1 },
    TRACE_LGT: { name: 'Trace to Light', code: 2 },
    LGT: { name: 'Light', code: 3 },
    LGT_MOD: { name: 'Light to Moderate', code: 4 },
    MOD: { name: 'Moderate', code: 5 },
    MOD_SEV: { name: 'Moderate to Severe', code: 6 },
    SEV: { name: 'Severe', code: 7 },
},
```

### CLOUD_TYPES

```javascript
CLOUD_TYPES: {
    SKC: { name: 'Sky Clear', coverage: 0 },
    CLR: { name: 'Clear Below 12000', coverage: 0 },
    FEW: { name: 'Few', coverage: '1-2/8' },
    SCT: { name: 'Scattered', coverage: '3-4/8' },
    BKN: { name: 'Broken', coverage: '5-7/8' },
    OVC: { name: 'Overcast', coverage: '8/8' },
    VV: { name: 'Vertical Visibility', coverage: 'obscured' },
},
```

### VISIBILITY_PHENOMENA

```javascript
VISIBILITY_PHENOMENA: {
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
},
```

### PRECIPITATION_TYPES

```javascript
PRECIPITATION_TYPES: {
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
},
```

---

## 4. STATUS Namespace

### OPERATIONAL_STATUS

```javascript
OPERATIONAL_STATUS: {
    NORMAL: { name: 'Normal Operations', color: '#28a745', level: 0 },
    MONITOR: { name: 'Monitoring', color: '#17a2b8', level: 1 },
    ADVISORY: { name: 'Advisory', color: '#ffc107', level: 2 },
    CAUTION: { name: 'Caution', color: '#fd7e14', level: 3 },
    ALERT: { name: 'Alert', color: '#dc3545', level: 4 },
    CRITICAL: { name: 'Critical', color: '#6f42c1', level: 5 },
},
```

### TMU_OPS_LEVEL

```javascript
TMU_OPS_LEVEL: {
    GREEN: { name: 'Normal', color: '#28a745', description: 'Normal operations' },
    YELLOW: { name: 'Moderate', color: '#ffc107', description: 'Moderate delays/constraints' },
    RED: { name: 'Severe', color: '#dc3545', description: 'Severe delays/constraints' },
},
```

### FACILITY_STATUS

```javascript
FACILITY_STATUS: {
    OPEN: { name: 'Open', operational: true },
    CLOSED: { name: 'Closed', operational: false },
    ATC_ZERO: { name: 'ATC Zero', operational: false, emergency: true },
    LIMITED: { name: 'Limited Operations', operational: true, degraded: true },
    EVENT: { name: 'Event/Exercise', operational: true, special: true },
},
```

### RUNWAY_STATUS

```javascript
RUNWAY_STATUS: {
    OPEN: { name: 'Open', available: true },
    CLOSED: { name: 'Closed', available: false },
    LAHSO: { name: 'LAHSO in Effect', available: true, restriction: 'LAHSO' },
    NOISE: { name: 'Noise Restriction', available: true, restriction: 'noise' },
    CONSTRUCTION: { name: 'Construction', available: false, temporary: true },
},
```

### NOTAM_PRIORITY

```javascript
NOTAM_PRIORITY: {
    FDC: { name: 'Flight Data Center', priority: 1 },
    NOTAM_D: { name: 'NOTAM (D)', priority: 2 },
    POINTER: { name: 'Pointer NOTAM', priority: 3 },
    SAA: { name: 'Special Activity Airspace', priority: 4 },
    MIL: { name: 'Military NOTAM', priority: 5 },
},
```

### FLIGHT_STATUS

```javascript
FLIGHT_STATUS: {
    SCHEDULED: { name: 'Scheduled', active: false, airborne: false },
    FILED: { name: 'Filed', active: true, airborne: false },
    PROPOSED: { name: 'Proposed', active: true, airborne: false },
    ACTIVE: { name: 'Active', active: true, airborne: false },
    DEPARTED: { name: 'Departed', active: true, airborne: true },
    ENROUTE: { name: 'En Route', active: true, airborne: true },
    ARRIVED: { name: 'Arrived', active: false, airborne: false },
    CANCELLED: { name: 'Cancelled', active: false, airborne: false },
    DIVERTED: { name: 'Diverted', active: true, airborne: true },
},
```

---

## 5. COORDINATION Namespace

### HOTLINES

```javascript
HOTLINES: {
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
        artccs: ['CZYZ', 'CZUL', 'CZZV', 'CZQM', 'CZQX', 'CZQO', 'CZWG', 'CZEG', 'CZVR'],
    },
},
```

### COORDINATION_TYPES

```javascript
COORDINATION_TYPES: {
    HANDOFF: { name: 'Handoff', direction: 'lateral' },
    POINTOUT: { name: 'Point Out', direction: 'lateral' },
    APREQ: { name: 'Approval Request', direction: 'vertical' },
    RELEASE: { name: 'Release', direction: 'vertical' },
    TRAFFIC: { name: 'Traffic Advisory', direction: 'advisory' },
    ALTITUDE: { name: 'Altitude Request', direction: 'vertical' },
    ROUTE: { name: 'Route Amendment', direction: 'lateral' },
},
```

### COMMUNICATION_TYPES

```javascript
COMMUNICATION_TYPES: {
    VOICE: { name: 'Voice', method: 'radio' },
    CPDLC: { name: 'Controller-Pilot Data Link', method: 'datalink' },
    ACARS: { name: 'Aircraft Communications Addressing and Reporting System', method: 'datalink' },
    ATIS: { name: 'Automatic Terminal Information Service', method: 'broadcast' },
    VOLMET: { name: 'Volume Meteorological', method: 'broadcast' },
    PDC: { name: 'Pre-Departure Clearance', method: 'datalink' },
    DCL: { name: 'Departure Clearance', method: 'datalink' },
},
```

---

## 6. GEOGRAPHIC Namespace

### DCC_REGIONS

```javascript
DCC_REGIONS: {
    WEST: {
        name: 'DCC West',
        color: '#dc3545',
        artccs: ['ZAK', 'ZAN', 'ZHN', 'ZLA', 'ZLC', 'ZOA', 'ZSE'],
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
        artccs: ['ZID', 'ZJX', 'ZMA', 'ZMO', 'ZTL'],
    },
    NORTHEAST: {
        name: 'DCC Northeast',
        color: '#007bff',
        artccs: ['ZBW', 'ZDC', 'ZNY', 'ZOB', 'ZWY'],
    },
    CANADA: {
        name: 'Canada',
        color: '#6f42c1',
        artccs: ['CZYZ', 'CZUL', 'CZZV', 'CZQM', 'CZQX', 'CZQO', 'CZWG', 'CZEG', 'CZVR'],
    },
    OTHER: {
        name: 'Other',
        color: '#6c757d',
        artccs: [],
    },
},
```

### ARTCC_TO_DCC

Inverse mapping from ARTCC code to DCC region.

```javascript
ARTCC_TO_DCC: {
    // WEST
    ZAK: 'WEST', ZAN: 'WEST', ZHN: 'WEST', ZLA: 'WEST',
    ZLC: 'WEST', ZOA: 'WEST', ZSE: 'WEST',
    // SOUTH_CENTRAL
    ZAB: 'SOUTH_CENTRAL', ZFW: 'SOUTH_CENTRAL', ZHO: 'SOUTH_CENTRAL',
    ZHU: 'SOUTH_CENTRAL', ZME: 'SOUTH_CENTRAL',
    // MIDWEST
    ZAU: 'MIDWEST', ZDV: 'MIDWEST', ZKC: 'MIDWEST', ZMP: 'MIDWEST',
    // SOUTHEAST
    ZID: 'SOUTHEAST', ZJX: 'SOUTHEAST', ZMA: 'SOUTHEAST',
    ZMO: 'SOUTHEAST', ZTL: 'SOUTHEAST',
    // NORTHEAST
    ZBW: 'NORTHEAST', ZDC: 'NORTHEAST', ZNY: 'NORTHEAST',
    ZOB: 'NORTHEAST', ZWY: 'NORTHEAST',
    // CANADA
    CZYZ: 'CANADA', CZUL: 'CANADA', CZZV: 'CANADA', CZQM: 'CANADA',
    CZQX: 'CANADA', CZQO: 'CANADA', CZWG: 'CANADA', CZEG: 'CANADA', CZVR: 'CANADA',
},
```

### VATSIM_REGIONS

```javascript
VATSIM_REGIONS: {
    AMAS: { name: 'Americas', divisions: ['VATUSA', 'VATCAN', 'VATMEX', 'VATCAR', 'VATCA', 'VATSAM'] },
    EMEA: { name: 'Europe, Middle East & Africa', divisions: ['VATUK', 'VATEIR', 'VATEUR', 'VATSPA', 'VATITA', 'VATSCA', 'VATEUD', 'VATGRE', 'VATTUR', 'VATRUS', 'VATMENA', 'VATSSA'] },
    APAC: { name: 'Asia Pacific', divisions: ['VATPAC', 'VATJPN', 'VATSEA', 'VATKOR', 'VATPRC', 'VATHK', 'VATTWN', 'VATIND', 'VATPAK'] },
},
```

### VATSIM_DIVISIONS

```javascript
VATSIM_DIVISIONS: {
    // AMAS - Americas
    VATUSA: {
        name: 'VATSIM USA',
        region: 'AMAS',
        icaoPrefixes: ['K'],
        hasDCC: true,
    },
    VATCAN: {
        name: 'VATSIM Canada',
        region: 'AMAS',
        icaoPrefixes: ['C'],
        hasDCC: true,
    },
    VATMEX: {
        name: 'VATSIM Mexico',
        region: 'AMAS',
        icaoPrefixes: ['MM'],
        hasDCC: true,
    },
    VATCAR: {
        name: 'VATSIM Caribbean',
        region: 'AMAS',
        icaoPrefixes: ['TJ', 'TT', 'TF', 'TG', 'TI', 'TK', 'TL', 'TN', 'TQ', 'TR', 'TU', 'TV', 'TX', 'MK', 'MD', 'MH', 'MN', 'MP', 'MT', 'MW', 'MY'],
        hasDCC: true,
    },
    VATCA: {
        name: 'VATSIM Central America',
        region: 'AMAS',
        icaoPrefixes: ['MG', 'MR', 'MS', 'MZ'],
        hasDCC: false,
    },
    VATSAM: {
        name: 'VATSIM South America',
        region: 'AMAS',
        icaoPrefixes: ['SA', 'SB', 'SC', 'SE', 'SK', 'SL', 'SM', 'SO', 'SP', 'SU', 'SV', 'SY'],
        hasDCC: false,
    },

    // EMEA - Europe, Middle East & Africa
    VATUK: {
        name: 'VATSIM United Kingdom',
        region: 'EMEA',
        icaoPrefixes: ['EG'],
        hasDCC: false,
    },
    VATEIR: {
        name: 'VATSIM Ireland',
        region: 'EMEA',
        icaoPrefixes: ['EI'],
        hasDCC: false,
    },
    VATEUR: {
        name: 'VATSIM Eurocore',
        region: 'EMEA',
        icaoPrefixes: ['EB', 'ED', 'EH', 'EL', 'ET', 'LF'],
        hasDCC: false,
    },
    VATSPA: {
        name: 'VATSIM Spain',
        region: 'EMEA',
        icaoPrefixes: ['LE', 'GC', 'GE'],
        hasDCC: false,
    },
    VATITA: {
        name: 'VATSIM Italy',
        region: 'EMEA',
        icaoPrefixes: ['LI', 'LM'],
        hasDCC: false,
    },
    VATSCA: {
        name: 'VATSIM Scandinavia',
        region: 'EMEA',
        icaoPrefixes: ['BI', 'EF', 'EK', 'EN', 'ES'],
        hasDCC: false,
    },
    VATEUD: {
        name: 'VATSIM Europe Division',
        region: 'EMEA',
        icaoPrefixes: ['EA', 'EE', 'EP', 'EV', 'EY', 'LA', 'LC', 'LD', 'LG', 'LH', 'LJ', 'LK', 'LO', 'LP', 'LQ', 'LR', 'LS', 'LT', 'LU', 'LW', 'LX', 'LY', 'LZ'],
        hasDCC: false,
    },
    VATGRE: {
        name: 'VATSIM Greece',
        region: 'EMEA',
        icaoPrefixes: ['LG'],
        hasDCC: false,
    },
    VATTUR: {
        name: 'VATSIM Turkey',
        region: 'EMEA',
        icaoPrefixes: ['LT'],
        hasDCC: false,
    },
    VATRUS: {
        name: 'VATSIM Russia',
        region: 'EMEA',
        icaoPrefixes: ['U'],
        hasDCC: false,
    },
    VATMENA: {
        name: 'VATSIM Middle East & North Africa',
        region: 'EMEA',
        icaoPrefixes: ['DA', 'DT', 'GM', 'HE', 'HL', 'LL', 'LN', 'OB', 'OE', 'OI', 'OJ', 'OK', 'OL', 'OM', 'OO', 'OR', 'OS', 'OT', 'OY'],
        hasDCC: false,
    },
    VATSSA: {
        name: 'VATSIM Sub-Saharan Africa',
        region: 'EMEA',
        icaoPrefixes: ['DN', 'DR', 'DX', 'FA', 'FB', 'FC', 'FD', 'FE', 'FG', 'FH', 'FI', 'FJ', 'FK', 'FL', 'FM', 'FN', 'FO', 'FP', 'FQ', 'FS', 'FT', 'FV', 'FW', 'FX', 'FY', 'FZ', 'GA', 'GB', 'GF', 'GG', 'GL', 'GO', 'GQ', 'GU', 'GV', 'HA', 'HB', 'HC', 'HD', 'HH', 'HK', 'HR', 'HS', 'HT', 'HU'],
        hasDCC: false,
    },

    // APAC - Asia Pacific
    VATPAC: {
        name: 'VATSIM Pacific',
        region: 'APAC',
        icaoPrefixes: ['AG', 'AN', 'AY', 'NF', 'NG', 'NI', 'NL', 'NS', 'NT', 'NV', 'NW', 'NZ', 'PG', 'PH', 'PK', 'PL', 'PM', 'PO', 'PP', 'PT', 'PW', 'WS', 'Y'],
        hasDCC: false,
    },
    VATJPN: {
        name: 'VATSIM Japan',
        region: 'APAC',
        icaoPrefixes: ['RJ', 'RO'],
        hasDCC: false,
    },
    VATSEA: {
        name: 'VATSIM Southeast Asia',
        region: 'APAC',
        icaoPrefixes: ['VD', 'VG', 'VL', 'VM', 'VN', 'VR', 'VT', 'VV', 'VY', 'WA', 'WB', 'WI', 'WM', 'WP', 'WQ', 'WR'],
        hasDCC: false,
    },
    VATKOR: {
        name: 'VATSIM Korea',
        region: 'APAC',
        icaoPrefixes: ['RK'],
        hasDCC: false,
    },
    VATPRC: {
        name: 'VATSIM China',
        region: 'APAC',
        icaoPrefixes: ['Z'],
        hasDCC: false,
    },
    VATHK: {
        name: 'VATSIM Hong Kong',
        region: 'APAC',
        icaoPrefixes: ['VH'],
        hasDCC: false,
    },
    VATTWN: {
        name: 'VATSIM Taiwan',
        region: 'APAC',
        icaoPrefixes: ['RC'],
        hasDCC: false,
    },
    VATIND: {
        name: 'VATSIM India',
        region: 'APAC',
        icaoPrefixes: ['VA', 'VE', 'VI', 'VO'],
        hasDCC: false,
    },
    VATPAK: {
        name: 'VATSIM Pakistan',
        region: 'APAC',
        icaoPrefixes: ['OP'],
        hasDCC: false,
    },
},
```

### AIRSPACE_TYPES

```javascript
AIRSPACE_TYPES: {
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
    AFP: { name: 'Airspace Flow Program Area', atfm: true },

    // Legacy/Historical (still referenced)
    TRSA: { name: 'Terminal Radar Service Area', legacy: true },
    ARSA: { name: 'Airport Radar Service Area', legacy: true },
    TCA: { name: 'Terminal Control Area', legacy: true, replaced: 'CLASS_B' },
},
```

### EARTH

```javascript
EARTH: {
    RADIUS_KM: 6371,
    RADIUS_NM: 3440.065,
    RADIUS_MI: 3958.8,
},
```

### DISTANCE

```javascript
DISTANCE: {
    NM_TO_KM: 1.852,
    NM_TO_MI: 1.15078,
    KM_TO_NM: 0.539957,
    MI_TO_NM: 0.868976,
},
```

### BOUNDS

```javascript
BOUNDS: {
    CONUS: { north: 49.0, south: 24.5, east: -66.9, west: -124.7 },
    CANADA: { north: 83.0, south: 41.7, east: -52.6, west: -141.0 },
    NORTH_AMERICA: { north: 83.0, south: 14.5, east: -52.6, west: -168.0 },
},
```

---

## Helper Functions

```javascript
// DCC Region lookups
getDCCRegion(artcc)              // 'ZBW' → 'NORTHEAST'
getDCCColor(region)              // 'NORTHEAST' → '#007bff'
getARTCCsForRegion(region)       // 'NORTHEAST' → ['ZBW', 'ZDC', 'ZNY', 'ZOB', 'ZWY']

// VATSIM lookups
getVATSIMDivision(icaoPrefix)    // 'K' → 'VATUSA', 'EG' → 'VATUK'
getVATSIMRegion(division)        // 'VATUSA' → 'AMAS'
hasDCCRegions(division)          // 'VATUSA' → true, 'VATUK' → false

// TMI lookups
getTMIType(code)                 // 'GDP' → { name: 'Ground Delay Program', ... }
getTMIScope(code)                // 'GDP' → 'airport'

// Weather lookups
getWeatherCategory(ceiling, visibility)  // Auto-determine VMC/MVMC/IMC/etc.
getSigmetType(code)              // 'WS' → { name: 'Convective SIGMET', ... }
getAirmetType(code)              // 'SIERRA' → { name: 'AIRMET Sierra', ... }

// Status lookups
getFlightStatus(code)            // 'ENROUTE' → { name: 'En Route', active: true, airborne: true }
isFlightActive(code)             // 'ENROUTE' → true

// Airspace lookups
getAirspaceType(code)            // 'MOA' → { name: 'Military Operations Area', sua: true }
isSUA(code)                      // 'MOA' → true
isTFR(code)                      // 'TFR_VIP' → true
```

---

## Implementation Notes

- Module follows same IIFE pattern as existing `lib/*.js` files
- Exports `PERTI` global object
- All data is static/read-only (Object.freeze in strict mode)
- PERTI is the single source of truth - other modules consume from it
- ICAO mappings enable international compatibility while preserving US operational terminology
