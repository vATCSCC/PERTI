/**
 * Flight math utilities for navigation and physics
 * Great circle calculations, bearings, wind corrections
 */

const {
    EARTH_RADIUS_NM,
    DEG_TO_RAD,
    RAD_TO_DEG,
    NM_TO_FEET,
    FEET_TO_NM,
} = require('../constants/flightConstants');

/**
 * Calculate great circle distance between two points (Haversine formula)
 * @param {number} lat1 - Start latitude (degrees)
 * @param {number} lon1 - Start longitude (degrees)
 * @param {number} lat2 - End latitude (degrees)
 * @param {number} lon2 - End longitude (degrees)
 * @returns {number} Distance in nautical miles
 */
function distanceNm(lat1, lon1, lat2, lon2) {
    const φ1 = lat1 * DEG_TO_RAD;
    const φ2 = lat2 * DEG_TO_RAD;
    const Δφ = (lat2 - lat1) * DEG_TO_RAD;
    const Δλ = (lon2 - lon1) * DEG_TO_RAD;

    const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
              Math.cos(φ1) * Math.cos(φ2) *
              Math.sin(Δλ / 2) * Math.sin(Δλ / 2);

    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

    return EARTH_RADIUS_NM * c;
}

/**
 * Calculate initial bearing from point 1 to point 2
 * @param {number} lat1 - Start latitude (degrees)
 * @param {number} lon1 - Start longitude (degrees)
 * @param {number} lat2 - End latitude (degrees)
 * @param {number} lon2 - End longitude (degrees)
 * @returns {number} Bearing in degrees (0-360)
 */
function bearingTo(lat1, lon1, lat2, lon2) {
    const φ1 = lat1 * DEG_TO_RAD;
    const φ2 = lat2 * DEG_TO_RAD;
    const Δλ = (lon2 - lon1) * DEG_TO_RAD;

    const y = Math.sin(Δλ) * Math.cos(φ2);
    const x = Math.cos(φ1) * Math.sin(φ2) -
              Math.sin(φ1) * Math.cos(φ2) * Math.cos(Δλ);

    const θ = Math.atan2(y, x);

    return (θ * RAD_TO_DEG + 360) % 360;
}

/**
 * Calculate destination point given start, bearing, and distance
 * @param {number} lat - Start latitude (degrees)
 * @param {number} lon - Start longitude (degrees)
 * @param {number} bearing - Bearing in degrees
 * @param {number} distanceNm - Distance in nautical miles
 * @returns {{lat: number, lon: number}} Destination coordinates
 */
function destinationPoint(lat, lon, bearing, distance) {
    const φ1 = lat * DEG_TO_RAD;
    const λ1 = lon * DEG_TO_RAD;
    const θ = bearing * DEG_TO_RAD;
    const δ = distance / EARTH_RADIUS_NM;

    const φ2 = Math.asin(
        Math.sin(φ1) * Math.cos(δ) +
        Math.cos(φ1) * Math.sin(δ) * Math.cos(θ),
    );

    const λ2 = λ1 + Math.atan2(
        Math.sin(θ) * Math.sin(δ) * Math.cos(φ1),
        Math.cos(δ) - Math.sin(φ1) * Math.sin(φ2),
    );

    return {
        lat: φ2 * RAD_TO_DEG,
        lon: ((λ2 * RAD_TO_DEG) + 540) % 360 - 180,  // Normalize to -180..+180
    };
}

/**
 * Normalize heading to 0-360 range
 * @param {number} heading - Heading in degrees
 * @returns {number} Normalized heading (0-360)
 */
function normalizeHeading(heading) {
    return ((heading % 360) + 360) % 360;
}

/**
 * Calculate the shortest turn direction between two headings
 * @param {number} currentHeading - Current heading (degrees)
 * @param {number} targetHeading - Target heading (degrees)
 * @returns {number} Signed difference (negative = turn left, positive = turn right)
 */
function headingDifference(currentHeading, targetHeading) {
    let diff = normalizeHeading(targetHeading) - normalizeHeading(currentHeading);

    if (diff > 180) {
        diff -= 360;
    } else if (diff < -180) {
        diff += 360;
    }

    return diff;
}

/**
 * Calculate true airspeed from indicated airspeed and altitude
 * Simplified formula: TAS ≈ IAS * (1 + altitude_ft / 50000)
 * @param {number} ias - Indicated airspeed (knots)
 * @param {number} altitude - Altitude (feet)
 * @returns {number} True airspeed (knots)
 */
function iasToTas(ias, altitude) {
    const densityFactor = 1 + (altitude / 50000);
    return ias * densityFactor;
}

/**
 * Calculate indicated airspeed from true airspeed and altitude
 * @param {number} tas - True airspeed (knots)
 * @param {number} altitude - Altitude (feet)
 * @returns {number} Indicated airspeed (knots)
 */
function tasToIas(tas, altitude) {
    const densityFactor = 1 + (altitude / 50000);
    return tas / densityFactor;
}

/**
 * Calculate Mach number from TAS and altitude
 * @param {number} tas - True airspeed (knots)
 * @param {number} altitude - Altitude (feet)
 * @returns {number} Mach number
 */
function tasToMach(tas, altitude) {
    const tempK = getIsaTemp(altitude) + 273.15;
    const speedOfSound = 38.967854 * Math.sqrt(tempK);
    return tas / speedOfSound;
}

/**
 * Calculate TAS from Mach number and altitude
 * @param {number} mach - Mach number
 * @param {number} altitude - Altitude (feet)
 * @returns {number} True airspeed (knots)
 */
function machToTas(mach, altitude) {
    const tempK = getIsaTemp(altitude) + 273.15;
    const speedOfSound = 38.967854 * Math.sqrt(tempK);
    return mach * speedOfSound;
}

/**
 * Get ISA temperature at altitude
 * @param {number} altitude - Altitude (feet)
 * @returns {number} Temperature in Celsius
 */
function getIsaTemp(altitude) {
    if (altitude <= 36089) {
        return 15 - (altitude * 0.001981);
    }
    return -56.5;
}

/**
 * Calculate ground speed given TAS, heading, wind speed and direction
 * @param {number} tas - True airspeed (knots)
 * @param {number} heading - Aircraft heading (degrees true)
 * @param {number} windSpeed - Wind speed (knots)
 * @param {number} windDir - Wind direction (degrees, from which wind blows)
 * @returns {{groundSpeed: number, windCorrectionAngle: number}}
 */
function calculateGroundSpeed(tas, heading, windSpeed, windDir) {
    if (windSpeed === 0) {
        return { groundSpeed: tas, windCorrectionAngle: 0 };
    }

    const headingRad = heading * DEG_TO_RAD;
    const windDirRad = windDir * DEG_TO_RAD;

    const windX = -windSpeed * Math.sin(windDirRad);
    const windY = -windSpeed * Math.cos(windDirRad);

    const tasX = tas * Math.sin(headingRad);
    const tasY = tas * Math.cos(headingRad);

    const gsX = tasX + windX;
    const gsY = tasY + windY;

    const groundSpeed = Math.sqrt(gsX * gsX + gsY * gsY);

    const trackRad = Math.atan2(gsX, gsY);
    const wca = (trackRad * RAD_TO_DEG - heading + 360) % 360;

    return {
        groundSpeed,
        windCorrectionAngle: wca > 180 ? wca - 360 : wca,
    };
}

/**
 * Calculate vertical speed needed to reach target altitude at given distance
 */
function requiredVerticalSpeed(currentAlt, targetAlt, distanceToTarget, groundSpeed) {
    if (distanceToTarget <= 0 || groundSpeed <= 0) {
        return 0;
    }

    const altChange = targetAlt - currentAlt;
    const timeMinutes = (distanceToTarget / groundSpeed) * 60;

    return altChange / timeMinutes;
}

/**
 * Calculate top of descent distance
 */
function topOfDescentDistance(cruiseAlt, targetAlt, descentRate, groundSpeed) {
    const altChange = cruiseAlt - targetAlt;
    const descentTimeMin = altChange / descentRate;
    return (groundSpeed / 60) * descentTimeMin;
}

/**
 * Linear interpolation
 */
function lerp(a, b, t) {
    return a + (b - a) * Math.max(0, Math.min(1, t));
}

/**
 * Clamp value between min and max
 */
function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

module.exports = {
    distanceNm,
    bearingTo,
    destinationPoint,
    normalizeHeading,
    headingDifference,
    iasToTas,
    tasToIas,
    tasToMach,
    machToTas,
    getIsaTemp,
    calculateGroundSpeed,
    requiredVerticalSpeed,
    topOfDescentDistance,
    lerp,
    clamp,
};
