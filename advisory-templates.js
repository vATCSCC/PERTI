/**
 * vATCSCC Advisory Templates - FAA TFMS Compliant
 * Based on: Advisories and General Messages v1.3 (CSC/TFMM-10/1077)
 * 
 * This module provides template generation functions for creating
 * FAA-specification-compliant advisory text.
 * 
 * @version 1.0
 * @date 2026-01-05
 */

const AdvisoryTemplates = (function() {
    'use strict';

    // ========================================
    // Constants
    // ========================================
    
    const ADVISORY_TYPES = {
        ROUTE: 'ROUTE',
        PLAYBOOK: 'PLAYBOOK',
        CDR: 'CDR',
        SPECIAL_OPERATIONS: 'SPECIAL OPERATIONS',
        OPERATIONS_PLAN: 'OPERATIONS PLAN',
        NRP_SUSPENSIONS: 'NRP SUSPENSIONS',
        VS: 'VS',
        NAT: 'NAT',
        SHUTTLE_ACTIVITY: 'SHUTTLE ACTIVITY',
        FCA: 'FCA',
        FEA: 'FEA',
        INFORMATIONAL: 'INFORMATIONAL',
        MISCELLANEOUS: 'MISCELLANEOUS'
    };

    const ADVISORY_ACTIONS = {
        RQD: 'RQD',    // Required
        RMD: 'RMD',    // Recommended
        PLN: 'PLN',    // Planned
        FYI: 'FYI'     // For Your Information
    };

    const IMPACTING_CONDITIONS = {
        WEATHER: 'WEATHER',
        VOLUME: 'VOLUME',
        RUNWAY: 'RUNWAY',
        EQUIPMENT: 'EQUIPMENT',
        OTHER: 'OTHER'
    };

    const PROBABILITY_EXTENSION = {
        NONE: 'NONE',
        LOW: 'LOW',
        MEDIUM: 'MEDIUM',
        HIGH: 'HIGH'
    };

    const DELAY_ASSIGNMENT_MODES = {
        DAS: 'DAS',    // Delay Assignment System
        GAAP: 'GAAP',  // Ground-Airline-Airport Partnership
        UDP: 'UDP'     // User-Defined Parameter
    };

    const MAX_LINE_LENGTH = 68;

    // ========================================
    // Utility Functions
    // ========================================

    /**
     * Format date as mm/dd/yyyy
     */
    function formatDateMMDDYYYY(date) {
        const d = date instanceof Date ? date : new Date(date);
        const month = String(d.getUTCMonth() + 1).padStart(2, '0');
        const day = String(d.getUTCDate()).padStart(2, '0');
        const year = d.getUTCFullYear();
        return `${month}/${day}/${year}`;
    }

    /**
     * Format time as ddhhmm
     */
    function formatTimeDDHHMM(date) {
        const d = date instanceof Date ? date : new Date(date);
        const day = String(d.getUTCDate()).padStart(2, '0');
        const hours = String(d.getUTCHours()).padStart(2, '0');
        const mins = String(d.getUTCMinutes()).padStart(2, '0');
        return `${day}${hours}${mins}`;
    }

    /**
     * Format time as dd/hhmmZ
     */
    function formatProgramTime(date) {
        const d = date instanceof Date ? date : new Date(date);
        const day = String(d.getUTCDate()).padStart(2, '0');
        const hours = String(d.getUTCHours()).padStart(2, '0');
        const mins = String(d.getUTCMinutes()).padStart(2, '0');
        return `${day}/${hours}${mins}Z`;
    }

    /**
     * Format time as hhmmZ
     */
    function formatADLTime(date) {
        const d = date instanceof Date ? date : new Date(date);
        const hours = String(d.getUTCHours()).padStart(2, '0');
        const mins = String(d.getUTCMinutes()).padStart(2, '0');
        return `${hours}${mins}Z`;
    }

    /**
     * Format signature timestamp as yy/mm/dd hh:mm
     */
    function formatSignatureTime(date) {
        const d = date instanceof Date ? date : new Date(date);
        const year = String(d.getUTCFullYear()).slice(-2);
        const month = String(d.getUTCMonth() + 1).padStart(2, '0');
        const day = String(d.getUTCDate()).padStart(2, '0');
        const hours = String(d.getUTCHours()).padStart(2, '0');
        const mins = String(d.getUTCMinutes()).padStart(2, '0');
        return `${year}/${month}/${day} ${hours}:${mins}`;
    }

    /**
     * Generate TMI ID
     * Format: RR + 3-char facility + 3-digit number
     */
    function generateTMIId(facility, number) {
        const fac = facility.toUpperCase().padEnd(3, 'X').slice(0, 3);
        const num = String(number).padStart(3, '0');
        return `RR${fac}${num}`;
    }

    /**
     * Pad advisory number to 3 digits
     */
    function padAdvisoryNumber(number) {
        return String(number).padStart(3, '0');
    }

    /**
     * Word-wrap text to max line length
     */
    function wordWrap(text, maxLength = MAX_LINE_LENGTH) {
        if (!text) return '';
        const words = text.split(' ');
        const lines = [];
        let currentLine = '';

        for (const word of words) {
            if (currentLine.length + word.length + 1 <= maxLength) {
                currentLine += (currentLine ? ' ' : '') + word;
            } else {
                if (currentLine) lines.push(currentLine);
                currentLine = word;
            }
        }
        if (currentLine) lines.push(currentLine);
        return lines.join('\n');
    }

    /**
     * Format facilities list with slashes
     * Input: ['ZNY', 'ZOB', 'ZDC']
     * Output: '/ZNY/ZOB/ZDC'
     */
    function formatFacilitiesSlash(facilities) {
        if (!facilities || !facilities.length) return '';
        return '/' + facilities.join('/');
    }

    /**
     * Format facilities list with spaces
     * Input: ['ZNY', 'ZOB', 'ZDC']
     * Output: 'ZNY ZOB ZDC'
     */
    function formatFacilitiesSpace(facilities) {
        if (!facilities || !facilities.length) return '';
        return facilities.join(' ');
    }

    // ========================================
    // Route Table Generator
    // ========================================

    /**
     * Generate formatted route table
     * @param {Array} routes - Array of route objects {orig, dest, route}
     * @param {string} format - 'standard' or 'simple'
     */
    function generateRouteTable(routes, format = 'standard') {
        if (!routes || !routes.length) return '';

        let output = '';
        
        if (format === 'standard') {
            output += 'ORIG DEST ASSIGNED ROUTE\n';
            output += '---- ---- --------------\n';
        } else {
            output += 'ORIG    DEST    ROUTE\n';
            output += '----    ----    -----\n';
        }

        for (const r of routes) {
            const orig = (r.orig || '').padEnd(format === 'standard' ? 5 : 8);
            const dest = (r.dest || '').padEnd(format === 'standard' ? 5 : 8);
            output += `${orig}${dest}${r.route || ''}\n`;
        }

        return output.trim();
    }

    // ========================================
    // Advisory Template Functions
    // ========================================

    /**
     * Generate Reroute Advisory (ROUTE/PLAYBOOK/CDR)
     * Per FAA Order 7210.3T, Section 16
     */
    function generateRerouteAdvisory(params) {
        const {
            advisoryNumber,
            facility = 'DCC',
            issueDate = new Date(),
            type = ADVISORY_TYPES.ROUTE,
            action = ADVISORY_ACTIONS.RQD,
            hasFlightList = false,
            impactedArea,
            reason,
            includeTraffic,
            validStart,
            validEnd,
            facilitiesIncluded = [],
            probExtension = PROBABILITY_EXTENSION.LOW,
            remarks = '',
            associatedRestrictions = '',
            modifications = '',
            routes = [],
            tmiId = null
        } = params;

        const headerDate = formatDateMMDDYYYY(issueDate);
        const flSuffix = hasFlightList ? '/FL' : '';
        const advNum = padAdvisoryNumber(advisoryNumber);
        const startTime = formatTimeDDHHMM(validStart);
        const endTime = formatTimeDDHHMM(validEnd);
        const sigTime = formatSignatureTime(issueDate);
        const generatedTmiId = tmiId || generateTMIId(facility, advisoryNumber);

        let output = `vATCSCC ADVZY ${advNum} ${facility} ${headerDate} ${type} - ${action}${flSuffix}\n`;
        output += `IMPACTED AREA: ${impactedArea || ''}\n`;
        output += `REASON: ${reason || ''}\n`;
        output += `INCLUDE TRAFFIC: ${includeTraffic || ''}\n`;
        output += `VALID TIMES: ETD START: ${startTime} END: ${endTime}\n`;
        output += `FACILITIES INCLUDED: ${formatFacilitiesSlash(facilitiesIncluded)}\n`;
        output += `PROBABILITY OF EXTENSION: ${probExtension}\n`;
        output += `REMARKS: ${remarks}\n`;
        output += `ASSOCIATED RESTRICTIONS: ${associatedRestrictions}\n`;
        output += `MODIFICATIONS: ${modifications}\n`;
        output += `ROUTE:\n`;
        output += generateRouteTable(routes, 'standard') + '\n';
        output += `\n`;
        output += `TMI ID: ${generatedTmiId}\n`;
        output += `${startTime}-${endTime}\n`;
        output += sigTime;

        return output;
    }

    /**
     * Generate Ground Stop Advisory - Actual
     */
    function generateGroundStopAdvisory(params) {
        const {
            advisoryNumber,
            airport,
            artcc,
            issueDate = new Date(),
            adlTime = new Date(),
            gsStart,
            gsEnd,
            depFacilitiesIncluded = [],
            depFacilitiesKeyword = null,
            previousDelays = { total: 0, max: 0, avg: 0 },
            newDelays = { total: 0, max: 0, avg: 0 },
            probExtension = PROBABILITY_EXTENSION.MEDIUM,
            impactingCondition = IMPACTING_CONDITIONS.VOLUME,
            impactingText = '',
            comments = ''
        } = params;

        const headerDate = formatDateMMDDYYYY(issueDate);
        const advNum = padAdvisoryNumber(advisoryNumber);
        const adlTimeStr = formatADLTime(adlTime);
        const gsStartStr = formatProgramTime(gsStart);
        const gsEndStr = formatProgramTime(gsEnd);
        const validStart = formatTimeDDHHMM(gsStart);
        const validEnd = formatTimeDDHHMM(gsEnd);
        const sigTime = formatSignatureTime(issueDate);

        const depFacStr = depFacilitiesKeyword 
            ? `(${depFacilitiesKeyword}) ${formatFacilitiesSpace(depFacilitiesIncluded)}`
            : formatFacilitiesSpace(depFacilitiesIncluded);

        const impactStr = impactingText 
            ? `${impactingCondition} ${impactingText}`
            : impactingCondition;

        let output = `vATCSCC ADVZY ${advNum} ${airport}/${artcc} ${headerDate} CDM GROUND STOP\n`;
        output += `CTL ELEMENT: ${airport}\n`;
        output += `ELEMENT TYPE: APT\n`;
        output += `ADL TIME: ${adlTimeStr}\n`;
        output += `GROUND STOP PERIOD: ${gsStartStr} – ${gsEndStr}\n`;
        output += `DEP FACILITIES INCLUDED: ${depFacStr}\n`;
        output += `PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: ${previousDelays.total} / ${previousDelays.max} / ${previousDelays.avg}\n`;
        output += `NEW TOTAL, MAXIMUM, AVERAGE DELAYS: ${newDelays.total} / ${newDelays.max} / ${newDelays.avg}\n`;
        output += `PROBABILITY OF EXTENSION: ${probExtension}\n`;
        output += `IMPACTING CONDITION: ${impactStr}\n`;
        output += `COMMENTS: ${comments}\n`;
        output += `${validStart}-${validEnd}\n`;
        output += sigTime;

        return output;
    }

    /**
     * Generate Ground Stop Cancel Advisory
     */
    function generateGroundStopCancelAdvisory(params) {
        const {
            advisoryNumber,
            airport,
            artcc,
            issueDate = new Date(),
            adlTime = new Date(),
            cnxStart,
            cnxEnd,
            hasActiveAFP = false,
            comments = ''
        } = params;

        const headerDate = formatDateMMDDYYYY(issueDate);
        const advNum = padAdvisoryNumber(advisoryNumber);
        const adlTimeStr = formatADLTime(adlTime);
        const cnxStartStr = formatProgramTime(cnxStart);
        const cnxEndStr = formatProgramTime(cnxEnd);
        const validStart = formatTimeDDHHMM(cnxStart);
        const validEnd = formatTimeDDHHMM(cnxEnd);
        const sigTime = formatSignatureTime(issueDate);

        let output = `vATCSCC ADVZY ${advNum} ${airport}/${artcc} ${headerDate} CDM GS CNX\n`;
        output += `CTL ELEMENT: ${airport}\n`;
        output += `ELEMENT TYPE: APT\n`;
        output += `ADL TIME: ${adlTimeStr}\n`;
        output += `GS CNX PERIOD: ${cnxStartStr} – ${cnxEndStr}\n`;
        if (hasActiveAFP) {
            output += `FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP\n`;
        }
        output += `COMMENTS: ${comments}\n`;
        output += `${validStart}-${validEnd}\n`;
        output += sigTime;

        return output;
    }

    /**
     * Generate Ground Delay Program Advisory - Actual
     */
    function generateGDPAdvisory(params) {
        const {
            advisoryNumber,
            airport,
            artcc,
            issueDate = new Date(),
            adlTime = new Date(),
            delayMode = DELAY_ASSIGNMENT_MODES.DAS,
            arrivalsStart,
            arrivalsEnd,
            programStart,
            programEnd,
            programRates = [],
            popUpFactors = [],
            flightInclusion = 'ALL CONTIGUOUS US DEP',
            depScope,
            additionalDepFacilities = [],
            exemptDepFacilities = [],
            maxDelay = 0,
            avgDelay = 0,
            impactingCondition = IMPACTING_CONDITIONS.WEATHER,
            impactingText = '',
            comments = ''
        } = params;

        const headerDate = formatDateMMDDYYYY(issueDate);
        const advNum = padAdvisoryNumber(advisoryNumber);
        const adlTimeStr = formatADLTime(adlTime);
        const arrStartStr = formatProgramTime(arrivalsStart);
        const arrEndStr = formatProgramTime(arrivalsEnd);
        const progStartStr = formatProgramTime(programStart);
        const progEndStr = formatProgramTime(programEnd);
        const validStart = formatTimeDDHHMM(programStart);
        const validEnd = formatTimeDDHHMM(programEnd);
        const sigTime = formatSignatureTime(issueDate);

        const impactStr = impactingText 
            ? `${impactingCondition} ${impactingText}`
            : impactingCondition;

        let output = `vATCSCC ADVZY ${advNum} ${airport}/${artcc} ${headerDate} CDM GROUND DELAY PROGRAM\n`;
        output += `CTL ELEMENT: ${airport}\n`;
        output += `ELEMENT TYPE: APT\n`;
        output += `ADL TIME: ${adlTimeStr}\n`;
        output += `DELAY ASSIGNMENT MODE: ${delayMode}\n`;
        output += `ARRIVALS ESTIMATED FOR: ${arrStartStr} – ${arrEndStr}\n`;
        output += `CUMULATIVE PROGRAM PERIOD: ${progStartStr} – ${progEndStr}\n`;
        output += `PROGRAM RATE: ${programRates.join('/')}\n`;
        if (popUpFactors.length > 0) {
            output += `POP-UP FACTOR: ${popUpFactors.join('/')}\n`;
        }
        output += `FLT INCL: ${flightInclusion}\n`;
        output += `DEP SCOPE: ${depScope}\n`;
        if (additionalDepFacilities.length > 0) {
            output += `ADDITIONAL DEP FACILITIES INCLUDED: ${formatFacilitiesSpace(additionalDepFacilities)}\n`;
        }
        if (exemptDepFacilities.length > 0) {
            output += `EXEMPT DEP FACILITIES: ${formatFacilitiesSpace(exemptDepFacilities)}\n`;
        }
        output += `MAXIMUM DELAY: ${maxDelay}\n`;
        output += `AVERAGE DELAY: ${avgDelay}\n`;
        output += `IMPACTING CONDITION: ${impactStr}\n`;
        output += `COMMENTS: ${comments}\n`;
        output += `${validStart}-${validEnd}\n`;
        output += sigTime;

        return output;
    }

    /**
     * Generate GDP Cancel Advisory
     */
    function generateGDPCancelAdvisory(params) {
        const {
            advisoryNumber,
            airport,
            artcc,
            issueDate = new Date(),
            adlTime = new Date(),
            cnxStart,
            cnxEnd,
            hasActiveAFP = false,
            comments = ''
        } = params;

        const headerDate = formatDateMMDDYYYY(issueDate);
        const advNum = padAdvisoryNumber(advisoryNumber);
        const adlTimeStr = formatADLTime(adlTime);
        const cnxStartStr = formatProgramTime(cnxStart);
        const cnxEndStr = formatProgramTime(cnxEnd);
        const validStart = formatTimeDDHHMM(cnxStart);
        const validEnd = formatTimeDDHHMM(cnxEnd);
        const sigTime = formatSignatureTime(issueDate);

        let output = `vATCSCC ADVZY ${advNum} ${airport}/${artcc} ${headerDate} CDM GROUND DELAY PROGRAM CNX\n`;
        output += `CTL ELEMENT: ${airport}\n`;
        output += `ELEMENT TYPE: APT\n`;
        output += `ADL TIME: ${adlTimeStr}\n`;
        output += `GDP CNX PERIOD: ${cnxStartStr} – ${cnxEndStr}\n`;
        output += `DISREGARD EDCTS FOR DEST ${airport}\n`;
        if (hasActiveAFP) {
            output += `FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP\n`;
        }
        output += `COMMENTS: ${comments}\n`;
        output += `${validStart}-${validEnd}\n`;
        output += sigTime;

        return output;
    }

    /**
     * Generate Hotline Advisory (vATCSCC Custom)
     */
    function generateHotlineAdvisory(params) {
        const {
            advisoryNumber,
            facility = 'DCC',
            issueDate = new Date(),
            hotlineName,
            validStart,
            validEnd,
            constrainedFacilities = [],
            isActivation = true,
            location,
            password = null,
            participation = 'RECOMMENDED',
            participationFacilities = [],
            contactPerson = ''
        } = params;

        const headerDate = formatDateMMDDYYYY(issueDate);
        const advNum = padAdvisoryNumber(advisoryNumber);
        const startTime = formatTimeDDHHMM(validStart);
        const endTime = formatTimeDDHHMM(validEnd);
        const sigTime = formatSignatureTime(issueDate);

        // Format valid time in dd/hhmm format
        const startDay = String(new Date(validStart).getUTCDate()).padStart(2, '0');
        const startHHMM = formatTimeDDHHMM(validStart).slice(2);
        const endDay = String(new Date(validEnd).getUTCDate()).padStart(2, '0');
        const endHHMM = formatTimeDDHHMM(validEnd).slice(2);

        let output = `vATCSCC ADVZY ${advNum} ${facility} ${headerDate} ${hotlineName} - FYI\n`;
        output += `VALID FOR ${startTime} THROUGH ${endTime}\n`;
        output += `CONSTRAINED FACILITIES: ${constrainedFacilities.join('/')}\n`;

        if (isActivation) {
            output += `THE ${hotlineName.replace('_FYI', '').replace(/_/g, ' ')} IS BEING ACTIVATED TO ADDRESS `;
            output += `${constrainedFacilities.length > 0 ? constrainedFacilities.join('/') : 'OPERATIONS'}.\n`;
            output += `THE LOCATION IS ${location}`;
            if (password) {
                output += `, PASSWORD ${password}`;
            }
            output += `.\n`;
            output += `PARTICIPATION IS ${participation} FOR ${formatFacilitiesSpace(participationFacilities)}.\n`;
            output += `ALL OTHER PARTICIPANTS ARE WELCOME TO JOIN.\n`;
            if (contactPerson) {
                output += `PLEASE MESSAGE ${contactPerson} IF YOU HAVE ISSUES OR QUESTIONS.\n`;
            }
        } else {
            output += `THE ${hotlineName.replace('_FYI', '').replace(/_/g, ' ')} IS NOW TERMINATED.\n`;
        }

        output += `\n`;
        output += `${startDay}/${startHHMM}-${endDay}/${endHHMM}\n`;
        output += sigTime;

        return output;
    }

    /**
     * Generate Informational Advisory
     */
    function generateInformationalAdvisory(params) {
        const {
            advisoryNumber,
            facility = 'DCC',
            issueDate = new Date(),
            validStart,
            validEnd,
            bodyText = ''
        } = params;

        const headerDate = formatDateMMDDYYYY(issueDate);
        const advNum = padAdvisoryNumber(advisoryNumber);
        const startTime = formatTimeDDHHMM(validStart);
        const endTime = formatTimeDDHHMM(validEnd);
        const sigTime = formatSignatureTime(issueDate);

        // Word-wrap the body text
        const wrappedBody = wordWrap(bodyText, MAX_LINE_LENGTH);

        let output = `vATCSCC ADVZY ${advNum} ${facility} ${headerDate} INFORMATIONAL\n`;
        output += `VALID FOR ${startTime} THROUGH ${endTime}\n`;
        output += `\n`;
        output += `${wrappedBody}\n`;
        output += `\n`;
        output += `${startTime}-${endTime}\n`;
        output += sigTime;

        return output;
    }

    /**
     * Generate SWAP Advisory (Severe Weather Avoidance Plan)
     */
    function generateSWAPAdvisory(params) {
        const {
            advisoryNumber,
            facility = 'DCC',
            issueDate = new Date(),
            swapName,
            validStart,
            validEnd,
            constrainedFacilities = [],
            swapStatement = '',
            impactAreas = [],
            gateImpacts = [],
            alternateRoutes = {
                departures: 'AS NECESSARY',
                arrivals: 'AS NECESSARY',
                overflights: 'AS NECESSARY'
            },
            hotlineInfo = null
        } = params;

        const headerDate = formatDateMMDDYYYY(issueDate);
        const advNum = padAdvisoryNumber(advisoryNumber);
        const startTime = formatTimeDDHHMM(validStart);
        const endTime = formatTimeDDHHMM(validEnd);
        const sigTime = formatSignatureTime(issueDate);

        let output = `vATCSCC ADVZY ${advNum} ${facility} ${headerDate} ${swapName} - FYI\n`;
        output += `VALID FOR ${startTime} THROUGH ${endTime}\n`;
        output += `CONSTRAINED FACILITIES: ${constrainedFacilities.join('/')}\n`;
        output += `\n`;
        output += `THIS ADVISORY IS FOR PLANNING PURPOSES ONLY. CUSTOMERS ARE\n`;
        output += `ENCOURAGED TO COMPLY WITH ALL vATCSCC ROUTE ADVISORIES. IF NO vATCSCC\n`;
        output += `ROUTE ADVISORIES ARE IN EFFECT, CUSTOMERS ARE ENCOURAGED TO FILE\n`;
        output += `PUBLISHED CDR'S AND NRP PROCEDURES AROUND KNOWN OR FORECASTED\n`;
        output += `WEATHER.\n`;
        output += `\n`;

        if (swapStatement) {
            output += `SWAP STATEMENT: ${wordWrap(swapStatement, MAX_LINE_LENGTH)}\n`;
            output += `\n`;
        }

        if (impactAreas.length > 0) {
            output += `EXPECTED IMPACT AREA(S): ${impactAreas.join(', ')}\n`;
            output += `\n`;
        }

        if (gateImpacts.length > 0) {
            for (const gi of gateImpacts) {
                output += `${gi.gate}: IMPACTS ARE: ${gi.impact}\n`;
            }
            output += `\n`;
        }

        output += `PLANNED ALTERNATE DEPARTURE ROUTES:\n`;
        output += `${alternateRoutes.departures}\n`;
        output += `\n`;
        output += `PLANNED ALTERNATE ARRIVAL ROUTES:\n`;
        output += `${alternateRoutes.arrivals}\n`;
        output += `\n`;
        output += `PLANNED OVERFLIGHT ROUTES:\n`;
        output += `${alternateRoutes.overflights}\n`;

        if (hotlineInfo) {
            output += `\n`;
            output += `${hotlineInfo.name} EXPECTED AFT ${hotlineInfo.time}: ${hotlineInfo.location}`;
            if (hotlineInfo.password) {
                output += ` (PASSWORD ${hotlineInfo.password})`;
            }
            output += `\n`;
        }

        output += `\n`;
        output += `${startTime}-${endTime}\n`;
        output += sigTime;

        return output;
    }

    // ========================================
    // Public API
    // ========================================

    return {
        // Constants
        TYPES: ADVISORY_TYPES,
        ACTIONS: ADVISORY_ACTIONS,
        IMPACTING_CONDITIONS: IMPACTING_CONDITIONS,
        PROBABILITY_EXTENSION: PROBABILITY_EXTENSION,
        DELAY_MODES: DELAY_ASSIGNMENT_MODES,
        MAX_LINE_LENGTH: MAX_LINE_LENGTH,

        // Utility Functions
        formatDateMMDDYYYY,
        formatTimeDDHHMM,
        formatProgramTime,
        formatADLTime,
        formatSignatureTime,
        generateTMIId,
        padAdvisoryNumber,
        wordWrap,
        formatFacilitiesSlash,
        formatFacilitiesSpace,
        generateRouteTable,

        // Advisory Generators
        generateRerouteAdvisory,
        generateGroundStopAdvisory,
        generateGroundStopCancelAdvisory,
        generateGDPAdvisory,
        generateGDPCancelAdvisory,
        generateHotlineAdvisory,
        generateInformationalAdvisory,
        generateSWAPAdvisory
    };
})();

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AdvisoryTemplates;
}
