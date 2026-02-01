/**
 * AircraftModel - Core flight physics simulation
 *
 * Handles:
 * - Position updates based on heading/speed
 * - Altitude changes (climb/descent)
 * - Speed changes (acceleration/deceleration)
 * - Heading changes (turns)
 * - FMS waypoint following
 *
 * Adapted from openScope for headless operation
 */

const {
    FLIGHT_PHASE,
    ALTITUDE_THRESHOLDS,
    SPEED_RESTRICTIONS,
    STANDARD_RATE_TURN_DEG_PER_SEC,
    WAYPOINT_CAPTURE_NM,
    ALTITUDE_CAPTURE_FT,
    HEADING_CAPTURE_DEG,
    SPEED_CAPTURE_KTS,
    NM_TO_FEET,
} = require('../constants/flightConstants');

const {
    distanceNm,
    bearingTo,
    destinationPoint,
    normalizeHeading,
    headingDifference,
    iasToTas,
    tasToIas,
    calculateGroundSpeed,
    clamp,
} = require('../math/flightMath');

class AircraftModel {
    constructor(init) {
        // Identity
        this.callsign = init.callsign;
        this.aircraftType = init.aircraftType;
        this.origin = init.origin;
        this.destination = init.destination;

        // Current state
        this.lat = init.lat;
        this.lon = init.lon;
        this.altitude = init.altitude;
        this.heading = normalizeHeading(init.heading);
        this.speed = init.speed;
        this.verticalSpeed = 0;
        this.groundSpeed = init.speed;

        // Target state
        this.targetAltitude = init.altitude;
        this.targetHeading = init.heading;
        this.targetSpeed = init.speed;

        // Mode flags
        this.headingMode = 'FMS';
        this.altitudeMode = 'FMS';
        this.speedMode = 'FMS';

        // Performance limits
        this.performance = init.performance || this._defaultPerformance();

        // Flight plan / FMS
        this.flightPlan = init.flightPlan || [];
        this.currentWaypointIndex = 0;

        // Flight phase
        this.phase = this._determinePhase();

        // Wind
        this.windSpeed = 0;
        this.windDirection = 0;

        // State tracking
        this.distanceFlown = 0;
        this.timeElapsed = 0;
        this.isActive = true;

        // Events log
        this.events = [];
    }

    tick(deltaTime) {
        if (!this.isActive) {return;}

        this._updateHeading(deltaTime);
        this._updateSpeed(deltaTime);
        this._updateAltitude(deltaTime);

        const { groundSpeed } = calculateGroundSpeed(
            iasToTas(this.speed, this.altitude),
            this.heading,
            this.windSpeed,
            this.windDirection,
        );
        this.groundSpeed = groundSpeed;

        this._updatePosition(deltaTime);

        if (this.headingMode === 'FMS') {
            this._checkWaypointCapture();
        }

        this.phase = this._determinePhase();
        this.timeElapsed += deltaTime;
    }

    // Command methods
    flyHeading(heading) {
        this.targetHeading = normalizeHeading(heading);
        this.headingMode = 'HDG';
        this._logEvent('CMD', `Fly heading ${Math.round(heading)}`);
    }

    turnLeftHeading(heading) {
        this.targetHeading = normalizeHeading(heading);
        this.headingMode = 'HDG';
        this._logEvent('CMD', `Turn left heading ${Math.round(heading)}`);
    }

    turnRightHeading(heading) {
        this.targetHeading = normalizeHeading(heading);
        this.headingMode = 'HDG';
        this._logEvent('CMD', `Turn right heading ${Math.round(heading)}`);
    }

    climbMaintain(altitude) {
        this.targetAltitude = altitude;
        this.altitudeMode = 'ALT';
        this._logEvent('CMD', `Climb and maintain ${altitude}`);
    }

    descendMaintain(altitude) {
        this.targetAltitude = altitude;
        this.altitudeMode = 'ALT';
        this._logEvent('CMD', `Descend and maintain ${altitude}`);
    }

    maintainSpeed(speed) {
        this.targetSpeed = clamp(speed, this.performance.minSpeed, this.performance.maxSpeed);
        this.speedMode = 'SPD';
        this._logEvent('CMD', `Maintain ${speed} knots`);
    }

    directTo(fixName) {
        const idx = this.flightPlan.findIndex(wp =>
            wp.name.toUpperCase() === fixName.toUpperCase(),
        );

        if (idx !== -1) {
            this.currentWaypointIndex = idx;
            this.headingMode = 'FMS';
            this._logEvent('CMD', `Direct to ${fixName}`);
            return true;
        }

        return false;
    }

    resumeNav() {
        this.headingMode = 'FMS';
        this._logEvent('CMD', 'Resume own navigation');
    }

    setWind(speed, direction) {
        this.windSpeed = speed;
        this.windDirection = direction;
    }

    addWaypoint(waypoint, index = -1) {
        if (index < 0 || index >= this.flightPlan.length) {
            this.flightPlan.push(waypoint);
        } else {
            this.flightPlan.splice(index, 0, waypoint);
        }
    }

    remove() {
        this.isActive = false;
        this._logEvent('SYS', 'Aircraft removed from simulation');
    }

    // State getters
    getPosition() {
        return { lat: this.lat, lon: this.lon, altitude: this.altitude };
    }

    getCurrentWaypoint() {
        if (this.currentWaypointIndex < this.flightPlan.length) {
            return this.flightPlan[this.currentWaypointIndex];
        }
        return null;
    }

    getDistanceToWaypoint() {
        const wp = this.getCurrentWaypoint();
        if (!wp) {return Infinity;}
        return distanceNm(this.lat, this.lon, wp.lat, wp.lon);
    }

    getDistanceToDestination() {
        if (this.flightPlan.length === 0) {return Infinity;}
        const dest = this.flightPlan[this.flightPlan.length - 1];
        return distanceNm(this.lat, this.lon, dest.lat, dest.lon);
    }

    getEtaSeconds() {
        const dist = this.getDistanceToDestination();
        if (dist === Infinity || this.groundSpeed <= 0) {return Infinity;}
        return (dist / this.groundSpeed) * 3600;
    }

    toStateObject() {
        return {
            callsign: this.callsign,
            aircraftType: this.aircraftType,
            origin: this.origin,
            destination: this.destination,
            lat: Math.round(this.lat * 1000000) / 1000000,
            lon: Math.round(this.lon * 1000000) / 1000000,
            altitude: Math.round(this.altitude),
            heading: Math.round(this.heading),
            speed: Math.round(this.speed),
            groundSpeed: Math.round(this.groundSpeed),
            verticalSpeed: Math.round(this.verticalSpeed),
            phase: this.phase,
            targetAltitude: this.targetAltitude,
            targetHeading: Math.round(this.targetHeading),
            targetSpeed: this.targetSpeed,
            headingMode: this.headingMode,
            altitudeMode: this.altitudeMode,
            currentWaypoint: this.getCurrentWaypoint()?.name || null,
            distanceToWaypoint: Math.round(this.getDistanceToWaypoint() * 10) / 10,
            distanceToDestination: Math.round(this.getDistanceToDestination() * 10) / 10,
            etaSeconds: Math.round(this.getEtaSeconds()),
            distanceFlown: Math.round(this.distanceFlown * 10) / 10,
            timeElapsed: Math.round(this.timeElapsed),
            isActive: this.isActive,
        };
    }

    // Private update methods
    _updateHeading(deltaTime) {
        let targetHdg = this.targetHeading;

        if (this.headingMode === 'FMS') {
            const wp = this.getCurrentWaypoint();
            if (wp) {
                targetHdg = bearingTo(this.lat, this.lon, wp.lat, wp.lon);
            }
        }

        const diff = headingDifference(this.heading, targetHdg);

        if (Math.abs(diff) < HEADING_CAPTURE_DEG) {
            this.heading = targetHdg;
            return;
        }

        const turnRate = STANDARD_RATE_TURN_DEG_PER_SEC;
        const maxTurn = turnRate * deltaTime;

        if (diff > 0) {
            this.heading = normalizeHeading(this.heading + Math.min(diff, maxTurn));
        } else {
            this.heading = normalizeHeading(this.heading + Math.max(diff, -maxTurn));
        }
    }

    _updateSpeed(deltaTime) {
        let targetSpd = this.targetSpeed;

        if (this.altitude < 10000) {
            targetSpd = Math.min(targetSpd, SPEED_RESTRICTIONS.BELOW_10000_KTS);
        }

        targetSpd = clamp(targetSpd, this.performance.minSpeed, this.performance.maxSpeed);

        const diff = targetSpd - this.speed;

        if (Math.abs(diff) < SPEED_CAPTURE_KTS) {
            this.speed = targetSpd;
            return;
        }

        if (diff > 0) {
            const accel = this.performance.accelRate * deltaTime;
            this.speed = Math.min(this.speed + accel, targetSpd);
        } else {
            const decel = this.performance.decelRate * deltaTime;
            this.speed = Math.max(this.speed - decel, targetSpd);
        }
    }

    _updateAltitude(deltaTime) {
        const diff = this.targetAltitude - this.altitude;

        if (Math.abs(diff) < ALTITUDE_CAPTURE_FT) {
            this.altitude = this.targetAltitude;
            this.verticalSpeed = 0;
            return;
        }

        if (diff > 0) {
            this.verticalSpeed = Math.min(this.performance.climbRate, diff * 60 / deltaTime);
        } else {
            this.verticalSpeed = Math.max(-this.performance.descentRate, diff * 60 / deltaTime);
        }

        const altChange = (this.verticalSpeed / 60) * deltaTime;
        this.altitude = clamp(this.altitude + altChange, 0, this.performance.ceiling);
    }

    _updatePosition(deltaTime) {
        const distance = (this.groundSpeed / 3600) * deltaTime;
        const newPos = destinationPoint(this.lat, this.lon, this.heading, distance);

        this.lat = newPos.lat;
        this.lon = newPos.lon;
        this.distanceFlown += distance;
    }

    _checkWaypointCapture() {
        const wp = this.getCurrentWaypoint();
        if (!wp) {return;}

        const dist = distanceNm(this.lat, this.lon, wp.lat, wp.lon);

        if (dist < WAYPOINT_CAPTURE_NM) {
            this._logEvent('NAV', `Passing ${wp.name}`);
            this.currentWaypointIndex++;

            const nextWp = this.getCurrentWaypoint();
            if (nextWp) {
                if (nextWp.altitude && this.altitudeMode === 'FMS') {
                    this.targetAltitude = nextWp.altitude;
                }
                if (nextWp.speed && this.speedMode === 'FMS') {
                    this.targetSpeed = nextWp.speed;
                }
            }
        }
    }

    _determinePhase() {
        if (!this.isActive) {return FLIGHT_PHASE.ARRIVED;}

        const distToDest = this.getDistanceToDestination();

        if (this.altitude < ALTITUDE_THRESHOLDS.GROUND) {
            return this.distanceFlown < 1 ? FLIGHT_PHASE.TAXI_OUT : FLIGHT_PHASE.TAXI_IN;
        }

        if (distToDest < 30 && this.targetAltitude < this.altitude) {
            return FLIGHT_PHASE.APPROACH;
        }

        if (this.verticalSpeed > 500) {
            return this.altitude < ALTITUDE_THRESHOLDS.DEPARTURE_TOP
                ? FLIGHT_PHASE.DEPARTURE
                : FLIGHT_PHASE.CLIMB;
        }

        if (this.verticalSpeed < -500) {
            return FLIGHT_PHASE.DESCENT;
        }

        return FLIGHT_PHASE.CRUISE;
    }

    _logEvent(type, message) {
        this.events.push({ time: this.timeElapsed, type, message });
        if (this.events.length > 100) {
            this.events.shift();
        }
    }

    _defaultPerformance() {
        return {
            ceiling: 41000,
            climbRate: 2500,
            descentRate: 2500,
            accelRate: 3,
            decelRate: 4,
            minSpeed: 130,
            maxSpeed: 340,
            cruiseSpeed: 280,
            cruiseMach: 0.78,
        };
    }
}

module.exports = AircraftModel;
