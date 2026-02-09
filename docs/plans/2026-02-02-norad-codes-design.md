# NORAD/AMIS Codes Module Design

**Date:** 2026-02-02
**Module:** `assets/js/lib/norad-codes.js`
**Purpose:** Centralized reference data for NORAD Alert and AMIS message creation/interpretation

## Overview

This module provides lookup tables and helper functions for:
- NORAD Alert messages
- AMIS (Airspace Management Information System) messages
- NOTAM Q-codes
- National Beacon Code Allocation Plan (NBCAP)

## Data Structures

### 1. NORAD Regions & ROCC/SOCC

```javascript
NORAD_REGIONS: {
  'C': { rocc: 'CONR', name: 'Continental NORAD Region HQ', location: 'Tyndall AFB, FL' },
  'B': { rocc: 'EADS', name: 'Eastern Air Defense Sector', location: 'Griffiss AFB, NY' },
  'R': { rocc: 'WADS', name: 'Western Air Defense Sector', location: 'McChord AFB, WA' },
  'S': { rocc: 'CANE', name: 'Canada East', location: 'North Bay, ON' },
  'W': { rocc: 'CANW', name: 'Canada West', location: 'North Bay, ON' },
  'N': { rocc: 'CANR', name: 'Canadian NORAD Region HQ', location: 'CFB Winnipeg, MB' },
  'A': { rocc: 'ANR', name: 'Alaskan NORAD Region', location: 'JB Elmendorf-Richardson, AK' },
}

ROCC_SOCC: {
  'CONR': { name: 'Continental NORAD Region', parent: null, color: '#6c757d' },
  'EADS': { name: 'Eastern Air Defense Sector', parent: 'CONR', color: '#dc3545' },
  'WADS': { name: 'Western Air Defense Sector', parent: 'CONR', color: '#007bff' },
  'CANR': { name: 'Canadian NORAD Region', parent: null, color: '#ffc107' },
  'CANE': { name: 'Canada East', parent: 'CANR', color: '#ffc107' },
  'CANW': { name: 'Canada West', parent: 'CANR', color: '#17a2b8' },
  'ANR':  { name: 'Alaskan NORAD Region', parent: null, color: '#28a745' },
}
```

### 2. Alert Status Levels

```javascript
ALERT_STATUS: {
  0: { name: 'Standby', color: '#6c757d' },           // Gray - Normal readiness
  1: { name: 'Suit Up', color: '#dc3545' },           // Red - Pilots prepare
  2: { name: 'Battle Stations', color: '#fd7e14' },   // Orange - Proceed to aircraft
  3: { name: 'Runway Alert', color: '#ffc107' },      // Yellow - Engines running
  4: { name: 'Active Air Scramble', color: '#e83e8c' }, // Magenta - Launch/airborne
  5: { name: 'Stand Down', color: '#28a745' },        // Green - Return to normal
}
```

### 3. Military Facilities

```javascript
FACILITIES: {
  // EADS - Eastern Air Defense Sector (14 facilities)
  'ACY': { name: 'Atlantic City ANGB, NJ', units: '119 FS', rocc: 'EADS' },
  'ADW': { name: 'JB Andrews, MD', units: '121 FS', rocc: 'EADS' },
  'BAF': { name: 'Barnes ANGB, MA', units: '131 FS', rocc: 'EADS' },
  'BTV': { name: 'Burlington ANGB, VT', units: '134 FS', rocc: 'EADS' },
  'DLH': { name: 'Duluth ANGB, MN', units: '179 FS', rocc: 'EADS' },
  'HST': { name: 'Homestead ARB, FL', units: '159 FS D1', rocc: 'EADS' },
  'JAX': { name: 'Jacksonville ANGB, FL', units: '159 FS', rocc: 'EADS' },
  'LFI': { name: 'Langley AFB, VA', units: '149 FS/134 FS D1', rocc: 'EADS' },
  'MGM': { name: 'Montgomery ANGB, AL', units: '100 FS', rocc: 'EADS' },
  'MMT': { name: 'McEntire JNGB, SC', units: '157 FS', rocc: 'EADS' },
  'MSN': { name: 'Truax Field ANGB, WI', units: '176 FS', rocc: 'EADS' },
  'NBG': { name: 'NASJRB New Orleans, LA', units: '122 FS', rocc: 'EADS' },
  'PAM': { name: 'Tyndall AFB, FL', units: '179 FS DQ', rocc: 'EADS' },
  'TOL': { name: 'Toledo ANGB, OH', units: '112 FS', rocc: 'EADS' },

  // WADS - Western Air Defense Sector (11 facilities)
  'BKF': { name: 'Buckley AFB, CO', units: '120 FS', rocc: 'WADS' },
  'DMA': { name: 'Davis-Monthan AFB, AZ', units: '152 FS D1/162 FW D1', rocc: 'WADS' },
  'EFD': { name: 'Ellington Field JRB, TX', units: '125 FS D1', rocc: 'WADS' },
  'FAT': { name: 'Fresno ANGB, CA', units: '194 FS', rocc: 'WADS' },
  'FSD': { name: 'Joe Joss Field ANGS, SD', units: '175 FS', rocc: 'WADS' },
  'LMT': { name: 'Kingsley Field ANGB, OR', units: '114 FS', rocc: 'WADS' },
  'PDX': { name: 'Portland ANGB, OR', units: '123 FS', rocc: 'WADS' },
  'RIV': { name: 'March ARB, CA', units: '194 FS D1', rocc: 'WADS' },
  'SKF': { name: 'Kelly Field Annex (Lackland AFB), TX', units: '182 FS', rocc: 'WADS' },
  'TUL': { name: 'Tulsa ANGB, OK', units: '125 FS', rocc: 'WADS' },
  'TUS': { name: 'Tucson ANGB, AZ', units: '148 FS/195 FS/152 FS', rocc: 'WADS' },

  // ANR - Alaskan NORAD Region (3 facilities)
  'CAFS': { name: 'Clear AFS, AK', units: '213 SWS', rocc: 'ANR' },
  'PAED': { name: 'JB Elmendorf-Richardson, AK', units: '176 ADS', rocc: 'ANR' },
  'PAEI': { name: 'Eielson AFB, AK', units: '213 SWS', rocc: 'ANR' },

  // CANE - Canada East (4 facilities)
  'CYBG': { name: 'CFB Bagotville, QC', units: '425 TFS/433 TFS', rocc: 'CANE' },
  'CYQX': { name: 'CFB Gander, NL', units: '226 AC&WS', rocc: 'CANE' },
  'CYYR': { name: 'CFB Goose Bay, NL', units: 'FOLGB', rocc: 'CANE' },
  'CYZX': { name: 'CFB Greenwood, NS', units: 'FOL Greenwood', rocc: 'CANE' },

  // CANW - Canada West (2 facilities)
  'CYOD': { name: 'CFB Cold Lake, AB', units: '401 TFS/409 TFS', rocc: 'CANW' },
  'CYQQ': { name: 'CFB Comox, BC', units: 'FOL Comox', rocc: 'CANW' },
}
```

### 4. Flight Plan Categories

```javascript
FLIGHT_PLAN_CATEGORIES: {
  'F':  'Point-to-point flight',
  'B':  'ACC Tactical Flight',
  'S':  'NORAD SIF',
  'PF': 'PF PADRA',
  'PB': 'PB PADRA',
  'PS': 'PS PADRA',
}
```

### 5. Flight Size Encoding

```javascript
// A=1, B=2, ... Z=26, 0=27, 1=28, ... 4=31, &=N/A
FLIGHT_SIZE: {
  'A': 1,  'B': 2,  'C': 3,  'D': 4,  'E': 5,  'F': 6,  'G': 7,
  'H': 8,  'I': 9,  'J': 10, 'K': 11, 'L': 12, 'M': 13, 'N': 14,
  'O': 15, 'P': 16, 'Q': 17, 'R': 18, 'S': 19, 'T': 20, 'U': 21,
  'V': 22, 'W': 23, 'X': 24, 'Y': 25, 'Z': 26,
  '0': 27, '1': 28, '2': 29, '3': 30, '4': 31,
  '&': null, // Not required / N/A
}
```

### 6. Delay Point Indicators

```javascript
DELAY_POINT_INDICATORS: {
  0: 'Point of activation',
  1: 'First check point',
  2: 'Second check point',
  3: 'Third check point',
  4: 'Fourth check point',
}
```

### 7. SCATANA Priority

```javascript
SCATANA_PRIORITY: {
  'P01': 'Presidential / COOP',
  'P02': 'Defense / Homeland Security',
  'P03': 'Law Enforcement',
  'P04': 'Medical Emergency',
  'P05': 'Disaster Relief',
  'P06': 'News Media',
  'P07': 'Commercial',
  'P08': 'All Else',
}
```

### 8. NBCAP Beacon Codes

Key categories with all ~270 codes included:

```javascript
NBCAP: {
  // VFR
  '1200': { desc: 'VFR', category: 'VFR' },
  '1201': { desc: 'VFR INVOF LAX IAW FAR 93.95', category: 'VFR' },
  // ... all VFR codes

  // Emergency
  '7400': { desc: 'Unmanned aircraft lost link', category: 'EMERGENCY' },
  '7500': { desc: 'Hijack', category: 'EMERGENCY' },
  '7600': { desc: 'Radio Failure', category: 'EMERGENCY' },
  '7700': { desc: 'Emergency', category: 'EMERGENCY' },
  '7777': { desc: 'DOD interceptor on active air defense mission', category: 'MILITARY' },

  // DOD/NORAD (5000-5077, 5100-5277, etc.)
  // ... all codes

  // Special operations (4400-4477)
  // ... all codes
}
```

### 9. QCODE23 (NOTAM Subject Codes)

~120 codes for NOTAM subject/facility types.

### 10. QCODE45 (NOTAM Condition Codes)

~90 codes for NOTAM operational status.

## Helper Functions

```javascript
// NORAD/Facility lookups
getROCCForFacility(facilityCode)     // 'ADW' → 'EADS'
getROCCColor(roccCode)               // 'EADS' → '#dc3545'
getFacilityInfo(facilityCode)        // Full facility object
getRegionForDesignator(letter)       // 'B' → { rocc: 'EADS', ... }

// Alert status
getAlertStatus(level)                // 0 → { name: 'Standby', color: '#6c757d' }
getAlertColor(level)                 // 0 → '#6c757d'

// Flight size encoding/decoding
encodeFlightSize(number)             // 1 → 'A', 28 → '1'
decodeFlightSize(char)               // 'A' → 1, '&' → null

// Beacon code lookup
getBeaconCodeInfo(squawk)            // '7700' → { desc: 'Emergency', category: 'EMERGENCY' }
isEmergencySquawk(squawk)            // '7700' → true
getBeaconCategory(squawk)            // '7700' → 'EMERGENCY'

// QCODE lookup
getQCode23Desc(code)                 // 'FA' → 'Aerodrome'
getQCode45Desc(code)                 // 'LC' → 'Closed'
```

## AMIS Message Format Reference

23-field structured message:

| Field | Name | Example |
|-------|------|---------|
| 1 | NORAD Facility Designator & Message Number | B |
| 2 | Activation Symbol | - |
| 3 | Flight Plan Category | F |
| 4 | Aircraft Callsign | RUDE13 |
| 5 | ARTCC AMIS Designator | N |
| 6 | Message Type | I |
| 7 | Type of Aircraft | F35 |
| 8 | Flight Size | 01 |
| 9 | Magnetic Heading | & |
| 10 | Altitude | 240 |
| 11 | Estimated Ground Speed (KTS) | 075 |
| 12 | Time of Activation | 23:15 UTC |
| 13 | Point of Activation | KIAD |
| 14 | First Check Point | KBOS |
| 15 | Second Check Point | KPHL |
| 16 | Third Check Point | |
| 17 | Fourth Check Point | |
| 18 | Delay Point Indicator | 1 |
| 19 | Delay Time | 060 |
| 20 | SCATANA Priority | P08 |
| 21 | SIF Code | 5635 |
| 22 | Inactivation Symbol | # |
| 23 | Remarks | ETD 2310Z/RMK ... |

## NORAD Alert Message Format Reference

```
DD/HHMM  NORAD Alert
ALERT   <status> - <action>
REG     <region_letter>/<rocc>
FAC     <facility>
AUTH    <auth_code>
```

Example:
```
03/0212  NORAD Alert
ALERT   0 - DAY - Dayton TRACON
REG     B/EADS
FAC     ADW
AUTH    EK
```

## Implementation Notes

- Module follows same IIFE pattern as `aircraft.js` and `colors.js`
- Exports `PERTINoradCodes` global object
- All data is static/read-only
- Colors match existing PERTI color palette where applicable
