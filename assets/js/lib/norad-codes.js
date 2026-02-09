/**
 * PERTI NORAD/AMIS Codes
 * =======================
 *
 * Centralized reference data for NORAD Alert and AMIS message handling:
 * - NORAD regions and ROCC/SOCC mappings
 * - Alert status levels
 * - Military facilities
 * - Flight plan categories
 * - Beacon codes (NBCAP)
 * - NOTAM Q-codes
 *
 * @package PERTI
 * @subpackage Assets/JS/Lib
 * @version 1.0.0
 * @date 2026-02-02
 */

(function(global) {
    'use strict';

    // ===========================================
    // NORAD REGIONS & DESIGNATORS
    // Single letter codes used in NORAD Alert messages
    // ===========================================

    const NORAD_REGIONS = {
        'C': { rocc: 'CONR', name: 'Continental NORAD Region HQ', location: 'Tyndall AFB, FL' },
        'B': { rocc: 'EADS', name: 'Eastern Air Defense Sector', location: 'Griffiss AFB, NY' },
        'R': { rocc: 'WADS', name: 'Western Air Defense Sector', location: 'McChord AFB, WA' },
        'S': { rocc: 'CANE', name: 'Canada East', location: 'North Bay, ON' },
        'W': { rocc: 'CANW', name: 'Canada West', location: 'North Bay, ON' },
        'N': { rocc: 'CANR', name: 'Canadian NORAD Region HQ', location: 'CFB Winnipeg, MB' },
        'A': { rocc: 'ANR', name: 'Alaskan NORAD Region', location: 'JB Elmendorf-Richardson, AK' },
    };

    // ===========================================
    // ROCC/SOCC (Regional/Sector Operations Control Centers)
    // ===========================================

    const ROCC_SOCC = {
        'CONR': { name: 'Continental NORAD Region', parent: null, color: '#6c757d' },
        'EADS': { name: 'Eastern Air Defense Sector', parent: 'CONR', color: '#dc3545' },
        'WADS': { name: 'Western Air Defense Sector', parent: 'CONR', color: '#007bff' },
        'CANR': { name: 'Canadian NORAD Region', parent: null, color: '#ffc107' },
        'CANE': { name: 'Canada East', parent: 'CANR', color: '#ffc107' },
        'CANW': { name: 'Canada West', parent: 'CANR', color: '#17a2b8' },
        'ANR':  { name: 'Alaskan NORAD Region', parent: null, color: '#28a745' },
    };

    // ===========================================
    // ALERT STATUS LEVELS
    // 0-4 = escalation, 5 = de-escalation
    // ===========================================

    const ALERT_STATUS = {
        0: { name: 'Standby', color: '#6c757d', textColor: '#ffffff', bold: false },
        1: { name: 'Suit Up', color: '#dc3545', textColor: '#ffffff', bold: false },
        2: { name: 'Battle Stations', color: '#fd7e14', textColor: '#000000', bold: false },
        3: { name: 'Runway Alert', color: '#ffc107', textColor: '#000000', bold: false },
        4: { name: 'Active Air Scramble', color: '#e83e8c', textColor: '#ffffff', bold: true },
        5: { name: 'Stand Down', color: '#28a745', textColor: '#ffffff', bold: true },
    };

    // ===========================================
    // MILITARY FACILITIES
    // Air defense bases mapped to ROCC/SOCC
    // ===========================================

    const FACILITIES = {
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
    };

    // ===========================================
    // FLIGHT PLAN CATEGORIES
    // ===========================================

    const FLIGHT_PLAN_CATEGORIES = {
        'F':  'Point-to-point flight',
        'B':  'ACC Tactical Flight',
        'S':  'NORAD SIF',
        'PF': 'PF PADRA',
        'PB': 'PB PADRA',
        'PS': 'PS PADRA',
    };

    // ===========================================
    // FLIGHT SIZE ENCODING
    // A=1, B=2, ... Z=26, 0=27, 1=28, ... 4=31, &=N/A
    // ===========================================

    const FLIGHT_SIZE_DECODE = {
        'A': 1,  'B': 2,  'C': 3,  'D': 4,  'E': 5,  'F': 6,  'G': 7,
        'H': 8,  'I': 9,  'J': 10, 'K': 11, 'L': 12, 'M': 13, 'N': 14,
        'O': 15, 'P': 16, 'Q': 17, 'R': 18, 'S': 19, 'T': 20, 'U': 21,
        'V': 22, 'W': 23, 'X': 24, 'Y': 25, 'Z': 26,
        '0': 27, '1': 28, '2': 29, '3': 30, '4': 31,
        '&': null,
    };

    // Reverse mapping for encoding
    const FLIGHT_SIZE_ENCODE = {};
    for (const [char, num] of Object.entries(FLIGHT_SIZE_DECODE)) {
        if (num !== null) {
            FLIGHT_SIZE_ENCODE[num] = char;
        }
    }

    // ===========================================
    // DELAY POINT INDICATORS
    // ===========================================

    const DELAY_POINT_INDICATORS = {
        0: 'Point of activation',
        1: 'First check point',
        2: 'Second check point',
        3: 'Third check point',
        4: 'Fourth check point',
    };

    // ===========================================
    // SCATANA PRIORITY
    // Security Control of Air Traffic and Navigation Aids
    // ===========================================

    const SCATANA_PRIORITY = {
        'P01': 'Presidential / COOP',
        'P02': 'Defense / Homeland Security',
        'P03': 'Law Enforcement',
        'P04': 'Medical Emergency',
        'P05': 'Disaster Relief',
        'P06': 'News Media',
        'P07': 'Commercial',
        'P08': 'All Else',
    };

    // ===========================================
    // NBCAP - National Beacon Code Allocation Plan
    // Transponder squawk codes
    // ===========================================

    const NBCAP = {
        // VFR codes
        '1000': { desc: 'ADS-B Aircraft with Mode 3A transmission inhibited', category: 'ADS-B' },
        '1200': { desc: 'VFR', category: 'VFR' },
        '1201': { desc: 'VFR INVOF LAX IAW FAR 93.95', category: 'VFR' },
        '1202': { desc: 'VFR gliders NOT in contact with ATC', category: 'VFR' },
        '1205': { desc: 'VFR Helicopters within the LA region/VFR aircraft departing the DC SFRA fringe airports IAW FAR 93.345', category: 'VFR' },
        '1206': { desc: 'VFR Law Enforcement, First Responder, Military/Public Service helicopters within the LA region', category: 'VFR' },
        '1234': { desc: 'VFR pattern work at DC SFRA airports IAW FAR 93.339', category: 'VFR' },
        '1255': { desc: 'Firefighting aircraft', category: 'VFR' },
        '1273': { desc: 'Calibration Performance Monitoring Equipment (CPME), MRSM, and PARROT transponders', category: 'CALIBRATION' },
        '1274': { desc: 'Calibration Performance Monitoring Equipment (CPME), MRSM, and PARROT transponders', category: 'CALIBRATION' },
        '1275': { desc: 'Calibration Performance Monitoring Equipment (CPME), MRSM, and PARROT transponders', category: 'CALIBRATION' },
        '1276': { desc: 'ADIZ penetration when unable to establish communication with ATC', category: 'ADIZ' },
        '1277': { desc: 'Designated SAR aircraft', category: 'SAR' },

        // Special operations above FL600
        '4400': { desc: 'SR-71, F-12, U-2, B-57, pressure suit flights, and aircraft operations above FL600 IAW FAAJO 7110.65, 5-2-10', category: 'HIGH_ALT' },
        '4434': { desc: 'Weather reconnaissance', category: 'WX_RECON' },
        '4435': { desc: 'Weather reconnaissance', category: 'WX_RECON' },
        '4436': { desc: 'Weather reconnaissance', category: 'WX_RECON' },
        '4437': { desc: 'Weather reconnaissance', category: 'WX_RECON' },
        '4453': { desc: 'High balloon operations - National Scientific Balloon Facility, Palestine, TX, and other providers, some international', category: 'BALLOON' },

        // Emergency codes
        '7400': { desc: 'Unmanned aircraft experiencing a lost link situation', category: 'EMERGENCY' },
        '7500': { desc: 'Hijack IAW FAAJO 7610.4', category: 'EMERGENCY' },
        '7600': { desc: 'Radio Failure IAW 7110.65, 5-2-8', category: 'EMERGENCY' },
        '7700': { desc: 'Emergency IAW FAAJO 7110.65, 5-2-7', category: 'EMERGENCY' },
        '7777': { desc: 'DOD interceptor aircraft on active air defense mission and operating without ATC clearance IAW FAAJO 7610.4', category: 'MILITARY' },
    };

    // NBCAP code ranges for DOD/NORAD use
    const NBCAP_RANGES = [
        { start: 4401, end: 4407, desc: 'Special Aircraft IAW FAAJO 7110.67', category: 'SPECIAL' },
        { start: 4410, end: 4417, desc: 'Special Aircraft IAW FAAJO 7110.67', category: 'SPECIAL' },
        { start: 4420, end: 4427, desc: 'Special Aircraft IAW FAAJO 7110.67', category: 'SPECIAL' },
        { start: 4430, end: 4433, desc: 'Special Aircraft IAW FAAJO 7110.67', category: 'SPECIAL' },
        { start: 4440, end: 4447, desc: 'Operations above FL600 for Lockheed/NASA', category: 'HIGH_ALT' },
        { start: 4450, end: 4452, desc: 'Support for special flight activities IAW FAAJO 7110.67', category: 'SPECIAL' },
        { start: 4454, end: 4465, desc: 'Air Force operations above FL600 IAW FAAJO 7610.4', category: 'HIGH_ALT' },
        { start: 4466, end: 4477, desc: 'Special Aircraft IAW FAAJO 7110.67', category: 'SPECIAL' },
        { start: 5000, end: 5057, desc: 'DOD/HQ NORAD Use', category: 'NORAD' },
        { start: 5060, end: 5062, desc: 'PCT DC SFRA/FRZ Use', category: 'SFRA' },
        { start: 5063, end: 5077, desc: 'DOD/HQ NORAD Use', category: 'NORAD' },
        { start: 5100, end: 5177, desc: 'DOD aircraft beyond radar coverage but inside US controlled airspace', category: 'DOD' },
        { start: 5200, end: 5277, desc: 'DOD aircraft beyond radar coverage but inside US controlled airspace', category: 'DOD' },
        { start: 5300, end: 5300, desc: 'DOD aircraft beyond radar coverage but inside US controlled airspace', category: 'DOD' },
        { start: 5400, end: 5400, desc: 'DOD/HQ NORAD Use', category: 'NORAD' },
        { start: 6100, end: 6100, desc: 'DOD/HQ NORAD Use', category: 'NORAD' },
        { start: 6400, end: 6400, desc: 'DOD/HQ NORAD Use', category: 'NORAD' },
        { start: 7501, end: 7577, desc: 'DOD/HQ NORAD Use', category: 'NORAD' },
        { start: 7601, end: 7607, desc: 'Law Enforcement Agency special use', category: 'LEO' },
        { start: 7701, end: 7707, desc: 'Law Enforcement Agency special use', category: 'LEO' },
    ];

    // ===========================================
    // QCODE23 - NOTAM Subject/Facility Codes
    // What the NOTAM is about
    // ===========================================

    const QCODE23 = {
        'AA': 'Minimum Altitude',
        'AC': 'Class B, C, D or E Surface Area (ICAO-Control Zone)',
        'AD': 'ADIZ',
        'AE': 'Control Area',
        'AF': 'Flight Information Region (FIR)',
        'AG': 'General Facility',
        'AH': 'Upper Control Area',
        'AL': 'Minimum Usable Flight Level',
        'AN': 'Air Navigation Route',
        'AO': 'Oceanic Control Zone (OCA)',
        'AP': 'Reporting Point',
        'AR': 'ATS Route',
        'AT': 'Terminal Control Area (TMA)',
        'AU': 'Upper Flight Information Region',
        'AV': 'Upper Advisory Area',
        'AX': 'Intersection',
        'AZ': 'Aerodrome Traffic Zone (ATZ)',
        'CA': 'Air/Ground Facility',
        'CB': 'Automatic Dependent Surveillance - Broadcast',
        'CC': 'Automatic Dependent Surveillance - Contract',
        'CD': 'Controller-Pilot Data Link Communications',
        'CE': 'Enroute Surveillance Radar',
        'CG': 'Ground Controlled Approach System (GCA)',
        'CL': 'Selective Calling System',
        'CM': 'Surface Movement Radar',
        'CP': 'PAR',
        'CR': 'Surveillance Radar Element of PAR System',
        'CS': 'Secondary Surveillance Radar',
        'CT': 'Terminal Area Surveillance Radar',
        'FA': 'Aerodrome',
        'FB': 'Braking Action Measurement Equipment',
        'FC': 'Ceiling Measurement Equipment',
        'FD': 'Docking System',
        'FE': 'Oxygen',
        'FF': 'Fire Fighting and Rescue',
        'FG': 'Ground Movement Control',
        'FH': 'Helicopter Alighting Area/Platform',
        'FI': 'Aircraft De-icing',
        'FJ': 'Oils',
        'FL': 'Landing Direction Indicator',
        'FM': 'Meteorological Service',
        'FO': 'Fog Dispersal System',
        'FP': 'Heliport',
        'FS': 'Snow Removal Equipment',
        'FT': 'Transmissometer',
        'FU': 'Fuel Availability',
        'FW': 'Wind Direction Indicator',
        'FZ': 'Customs',
        'GA': 'Military Unknown',
        'GB': 'Optical Landing System',
        'GC': 'Transient Maintenance',
        'GD': 'Starter Unit',
        'GE': 'Soap',
        'GF': 'Demineralized Water',
        'GG': 'Oxygen',
        'GH': 'Oil',
        'GI': 'Drag Chutes',
        'GJ': 'ASR',
        'GK': 'Precision Approach Landing System',
        'GL': 'FACSFAC',
        'GM': 'Firing Range',
        'GN': 'Night Vision Goggle (NVG) Operations',
        'GO': 'Warning Area',
        'GP': 'Arresting Gear Markers (AGM)',
        'GQ': 'Pulsating/Steady Visual Approach Slope Indicator',
        'GR': 'Diverse Departure',
        'GS': 'Nitrogen',
        'GT': 'IFR Take-off Minimums and Departure Procedures',
        'GU': 'De-ice',
        'GV': 'Clear Zone',
        'GW': 'Military Unknown',
        'GX': 'Runway Distance Remaining (RDR) Signs',
        'GY': 'Helo Pad',
        'GZ': 'Base Operations',
        'IC': 'ILS',
        'ID': 'DME Associated with ILS',
        'IG': 'Glide Path (ILS)',
        'II': 'Inner Marker (ILS)',
        'IL': 'Localizer (ILS)',
        'IM': 'Middle Marker (ILS)',
        'IN': 'Localizer',
        'IO': 'Outer Marker (ILS)',
        'IS': 'ILS Category I',
        'IT': 'ILS Category II',
        'IU': 'ILS Category III',
        'IW': 'MLS',
        'IX': 'Locator, Outer, (ILS)',
        'IY': 'Locator, Middle (ILS)',
        'LA': 'Approach Lighting System',
        'LB': 'Aerodrome Beacon',
        'LC': 'Runway Centerline Lights',
        'LD': 'Landing Direction Indicator Lights',
        'LE': 'Runway Edge Lights',
        'LF': 'Sequenced Flashing Lights',
        'LG': 'Pilot-Controlled Lighting',
        'LH': 'High Intensity Runway Lights',
        'LI': 'Runway End Identifier Lights',
        'LJ': 'Runway Alignment Indicator Lights',
        'LK': 'Category II Components of Approach Lighting System',
        'LL': 'Low Intensity Runway Lights',
        'LM': 'Medium Intensity Runway Lights',
        'LP': 'Precision Approach Path Indicator',
        'LR': 'All Landing Area Lighting Facilities',
        'LS': 'Stopway Lights',
        'LT': 'Threshold Lights',
        'LU': 'Helicopter Approach Path Indicator',
        'LV': 'Visual Approach Slope Indicator',
        'LW': 'Heliport Lighting',
        'LX': 'Taxiway Center Line Lights',
        'LY': 'Taxiway Edge Lights',
        'LZ': 'Runway Touch Down Zone Lights',
        'MA': 'Movement Area',
        'MB': 'Bearing Strength',
        'MC': 'Clearway',
        'MD': 'Declared Distances',
        'MG': 'Taxiing Guidance System',
        'MH': 'Runway Arresting Gear',
        'MK': 'Parking Area',
        'MM': 'Daylight Markings',
        'MN': 'Apron',
        'MO': 'Stop Bar',
        'MP': 'Aircraft Stands',
        'MR': 'Runway',
        'MS': 'Stopway',
        'MT': 'Threshold',
        'MU': 'Runway Turning Bay',
        'MW': 'Strip',
        'MX': 'Taxiway',
        'MY': 'Rapid Exit Taxiway',
        'NA': 'All Radio Navigation Facilities',
        'NB': 'NDB',
        'NC': 'DECCA',
        'ND': 'DME',
        'NF': 'Fan Marker',
        'NL': 'Locator',
        'NM': 'VOR/DME',
        'NN': 'TACAN',
        'NT': 'VORTAC',
        'NV': 'VOR',
        'NX': 'Direction Finding Station',
        'OA': 'Aeronautical Information Service',
        'OB': 'Obstacle',
        'OE': 'Aircraft Entry Requirements',
        'OL': 'Obstacle Lights',
        'OR': 'Rescue Coordination Center',
        'PA': 'Standard Instrument Arrival (STAR)',
        'PB': 'Standard VFR Arrival',
        'PC': 'Contingency Procedures',
        'PD': 'Standard Instrument Departure (SID)',
        'PE': 'Standard VFR Departure',
        'PF': 'Flow Control Procedures',
        'PH': 'Holding Procedures',
        'PI': 'Instrument Approach Procedure',
        'PK': 'VFR Approach Procedure',
        'PL': 'Obstacle Clearance Limit',
        'PM': 'Aerodrome Operating Minima',
        'PN': 'Noise Operating Restrictions',
        'PO': 'Obstacle Clearance Altitude',
        'PP': 'Obstacle Clearance Height',
        'PR': 'Radio Failure Procedure',
        'PT': 'Transition Altitude',
        'PU': 'Missed Approach Procedure',
        'PX': 'Minimum Holding Altitude',
        'PZ': 'ADIZ Procedure',
        'RA': 'Airspace Reservation',
        'RD': 'Danger Area',
        'RM': 'Airspace Restriction Unknown',
        'RO': 'Overflying Of',
        'RP': 'Prohibited Area',
        'RR': 'Restricted Area',
        'RT': 'Temporary Restricted Area',
        'SA': 'Automatic Terminal Information Service (ATIS)',
        'SB': 'ATS Report Office',
        'SC': 'Area Control Center',
        'SE': 'Flight Information Service',
        'SF': 'Aerodrome Flight Information Service (AFIS)',
        'SL': 'Flow Control Center',
        'SO': 'Oceanic Area Control Center',
        'SP': 'Approach Control',
        'SS': 'Flight Service Station',
        'ST': 'Aerodrome Control Tower',
        'SU': 'Upper Area Control Center',
        'SV': 'VOLMET Broadcast',
        'SY': 'Upper Advisory Service',
        'TT': 'MIJI',
        'WA': 'Air Display',
        'WB': 'Aerobatics',
        'WC': 'Captive Balloon or Kite',
        'WD': 'Demolition of Explosives',
        'WE': 'Exercises',
        'WF': 'Air Refueling',
        'WG': 'Glider Flying',
        'WH': 'Blasting',
        'WJ': 'Banner/Target Towing',
        'WL': 'Ascent of Free Balloon',
        'WM': 'Missile, Gun or Rocket Firing',
        'WP': 'Parachute Jumping Exercise',
        'WR': 'Radioactive Materials or Toxic Chemicals',
        'WS': 'Burning or Blowing Gas',
        'WT': 'Mass Movement of Aircraft',
        'WU': 'Unmanned Aircraft',
        'WV': 'Formation Flight',
        'WW': 'Significant Volcanic Activity',
        'WY': 'Aerial Survey',
        'WZ': 'Model Flying',
        'XX': 'Other',
    };

    // ===========================================
    // QCODE45 - NOTAM Condition/Status Codes
    // Operational status
    // ===========================================

    const QCODE45 = {
        'AC': 'Withdrawn for Maintenance',
        'AD': 'Available for Daylight Operations',
        'AF': 'Flight Checked and Found Reliable',
        'AG': 'Operating but Ground Checked Only, Awaiting Flight Check',
        'AH': 'Hours of Service Are',
        'AK': 'Resumed Normal Operations',
        'AM': 'Military Operations Only',
        'AN': 'Available for Night Operations',
        'AO': 'Operational',
        'AP': 'Prior Permission Required',
        'AQ': 'Completely Withdrawn',
        'AR': 'Available, Prior Permission Required',
        'AS': 'Unserviceable',
        'AU': 'Not Available',
        'AW': 'Completely Withdrawn',
        'AX': 'Previously Promulgated Shutdown Has Been Cancelled',
        'CA': 'Activated',
        'CC': 'Completed',
        'CD': 'Deactivated',
        'CE': 'Erected',
        'CF': 'Frequency Changed To',
        'CG': 'Downgraded To',
        'CH': 'Changed',
        'CI': 'Identification or Radio Call Sign Changed To',
        'CL': 'Realigned',
        'CM': 'Displaced',
        'CO': 'Operating',
        'CP': 'Operating on Reduced Power',
        'CR': 'Temporarily Replaced By',
        'CS': 'Installed',
        'CT': 'On Test, Do Not Use',
        'GA': 'Not Coincidental with ILS/PAR',
        'GB': 'In Raised Position',
        'GC': 'Tail Hook Only',
        'GD': 'Official Business Only',
        'GE': 'Expect Landing Delay',
        'GF': 'Extensive Service Delay',
        'GG': 'Unusable Beyond',
        'GH': 'Unusable',
        'GI': 'Unmonitored',
        'GJ': 'In Progress',
        'GK': 'Moderate',
        'GL': 'Severe',
        'GM': 'Not Illuminated',
        'GN': 'Frequency Not Available',
        'GO': 'Is Wet',
        'GV': 'Not Authorized',
        'HA': 'Braking Action Is',
        'HB': 'Braking Coefficient Is',
        'HC': 'Covered by Compacted Snow to a Depth Of',
        'HD': 'Covered by Dry Snow to a Depth Of',
        'HE': 'Covered by Water to a Depth Of',
        'HF': 'Totally Free of Snow and Ice',
        'HG': 'Grass Cutting in Progress',
        'HH': 'Hazard Due To',
        'HI': 'Covered by Ice',
        'HJ': 'Launch Planned',
        'HK': 'Migration in Progress',
        'HL': 'Snow Clearance Completed',
        'HM': 'Marked By',
        'HN': 'Covered by Wet Snow or Slush to a Depth Of',
        'HO': 'Obscured by Snow',
        'HP': 'Snow Clearance in Progress',
        'HQ': 'Operations Cancelled',
        'HR': 'Standing Water',
        'HS': 'Sanding',
        'HT': 'Approach According to Signal Area Only',
        'HU': 'Launch in Progress',
        'HV': 'Work Completed',
        'HW': 'Work in Progress',
        'HX': 'Concentration of Birds',
        'HY': 'Snow Banks Exist',
        'HZ': 'Covered by Frozen Ruts and Ridges',
        'KK': 'Volcanic Activity',
        'LA': 'Operating on Auxiliary Power Supply',
        'LB': 'Reserved for Aircraft Based Therein',
        'LC': 'Closed',
        'LD': 'Unsafe',
        'LE': 'Operating Without Auxiliary Power Supply',
        'LF': 'Interference From',
        'LG': 'Operating Without Identification',
        'LH': 'Unserviceable for Aircraft Heavier Than',
        'LI': 'Closed to IFR Operations',
        'LK': 'Operating as a Fixed Light',
        'LL': 'Usable for Length Of and Width Of',
        'LN': 'Closed to All Night Operations',
        'LP': 'Prohibited To',
        'LR': 'Aircraft Restricted to Runways and Taxiways',
        'LS': 'Subject to Interruption',
        'LT': 'Limited To',
        'LV': 'Closed to VFR Operations',
        'LW': 'Will Take Place',
        'LX': 'Operating but Caution Advised Due To',
        'LY': 'Effective',
        'TT': 'Hazard',
        'XX': 'Other',
    };

    // ===========================================
    // HELPER FUNCTIONS
    // ===========================================

    /**
     * Get ROCC/SOCC for a facility code
     * @param {string} facilityCode - Facility code (e.g., 'ADW')
     * @returns {string|null} ROCC code or null
     */
    function getROCCForFacility(facilityCode) {
        const facility = FACILITIES[facilityCode];
        return facility ? facility.rocc : null;
    }

    /**
     * Get ROCC/SOCC color
     * @param {string} roccCode - ROCC code (e.g., 'EADS')
     * @returns {string} Hex color
     */
    function getROCCColor(roccCode) {
        const rocc = ROCC_SOCC[roccCode];
        return rocc ? rocc.color : '#6c757d';
    }

    /**
     * Get facility info
     * @param {string} facilityCode - Facility code
     * @returns {object|null} Facility object or null
     */
    function getFacilityInfo(facilityCode) {
        return FACILITIES[facilityCode] || null;
    }

    /**
     * Get region info for a NORAD designator letter
     * @param {string} letter - Single letter designator
     * @returns {object|null} Region object or null
     */
    function getRegionForDesignator(letter) {
        return NORAD_REGIONS[letter] || null;
    }

    /**
     * Get alert status info
     * @param {number} level - Alert level (0-5)
     * @returns {object|null} Alert status object or null
     */
    function getAlertStatus(level) {
        return ALERT_STATUS[level] || null;
    }

    /**
     * Get alert status color
     * @param {number} level - Alert level (0-5)
     * @returns {string} Hex color
     */
    function getAlertColor(level) {
        const status = ALERT_STATUS[level];
        return status ? status.color : '#6c757d';
    }

    /**
     * Encode flight size number to character
     * @param {number} size - Flight size (1-31)
     * @returns {string|null} Encoded character or null
     */
    function encodeFlightSize(size) {
        return FLIGHT_SIZE_ENCODE[size] || null;
    }

    /**
     * Decode flight size character to number
     * @param {string} char - Encoded character
     * @returns {number|null} Flight size or null
     */
    function decodeFlightSize(char) {
        return FLIGHT_SIZE_DECODE[char] !== undefined ? FLIGHT_SIZE_DECODE[char] : null;
    }

    /**
     * Get beacon code info (checks individual codes and ranges)
     * @param {string} squawk - 4-digit squawk code
     * @returns {object|null} Beacon code info or null
     */
    function getBeaconCodeInfo(squawk) {
        // Check individual codes first
        if (NBCAP[squawk]) {
            return NBCAP[squawk];
        }

        // Check ranges
        const code = parseInt(squawk, 10);
        if (isNaN(code)) {return null;}

        for (const range of NBCAP_RANGES) {
            if (code >= range.start && code <= range.end) {
                return { desc: range.desc, category: range.category };
            }
        }

        return null;
    }

    /**
     * Check if squawk is an emergency code
     * @param {string} squawk - 4-digit squawk code
     * @returns {boolean}
     */
    function isEmergencySquawk(squawk) {
        const info = getBeaconCodeInfo(squawk);
        return info && info.category === 'EMERGENCY';
    }

    /**
     * Get beacon code category
     * @param {string} squawk - 4-digit squawk code
     * @returns {string|null} Category or null
     */
    function getBeaconCategory(squawk) {
        const info = getBeaconCodeInfo(squawk);
        return info ? info.category : null;
    }

    /**
     * Get QCODE23 description
     * @param {string} code - 2-letter Q-code
     * @returns {string|null} Description or null
     */
    function getQCode23Desc(code) {
        return QCODE23[code] || null;
    }

    /**
     * Get QCODE45 description
     * @param {string} code - 2-letter Q-code
     * @returns {string|null} Description or null
     */
    function getQCode45Desc(code) {
        return QCODE45[code] || null;
    }

    /**
     * Get all facilities for a ROCC/SOCC
     * @param {string} roccCode - ROCC code (e.g., 'EADS')
     * @returns {object} Object of facilities
     */
    function getFacilitiesForROCC(roccCode) {
        const result = {};
        for (const [code, facility] of Object.entries(FACILITIES)) {
            if (facility.rocc === roccCode) {
                result[code] = facility;
            }
        }
        return result;
    }

    // ===========================================
    // EXPORT
    // ===========================================

    global.PERTINoradCodes = {
        // Data tables
        NORAD_REGIONS,
        ROCC_SOCC,
        ALERT_STATUS,
        FACILITIES,
        FLIGHT_PLAN_CATEGORIES,
        FLIGHT_SIZE_DECODE,
        FLIGHT_SIZE_ENCODE,
        DELAY_POINT_INDICATORS,
        SCATANA_PRIORITY,
        NBCAP,
        NBCAP_RANGES,
        QCODE23,
        QCODE45,

        // Helper functions
        getROCCForFacility,
        getROCCColor,
        getFacilityInfo,
        getRegionForDesignator,
        getAlertStatus,
        getAlertColor,
        encodeFlightSize,
        decodeFlightSize,
        getBeaconCodeInfo,
        isEmergencySquawk,
        getBeaconCategory,
        getQCode23Desc,
        getQCode45Desc,
        getFacilitiesForROCC,
    };

})(typeof window !== 'undefined' ? window : this);

// Export for ES modules if available
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PERTINoradCodes;
}
