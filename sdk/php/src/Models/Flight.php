<?php

declare(strict_types=1);

namespace VatSim\Swim\Models;

/**
 * Flight model for SWIM API
 *
 * Represents a flight with all CDM/FIXM-compliant fields.
 */
class Flight
{
    // Identity
    public ?string $gufi = null;
    public ?string $flightKey = null;
    public string $callsign;
    public ?int $cid = null;
    public ?string $pilotName = null;

    // Airports
    public string $deptIcao;
    public string $destIcao;
    public ?string $altIcao = null;
    public ?string $diversionAerodrome = null;

    // Aircraft
    public ?string $aircraftType = null;
    public ?string $airlineIcao = null;
    public ?string $airlineName = null;
    public ?string $registration = null;
    public ?string $wakeCategory = null;

    // Route
    public ?string $route = null;
    public ?int $cruiseAltitude = null;
    public ?int $cruiseSpeed = null;
    public ?string $sid = null;
    public ?string $star = null;

    // Position
    public ?float $latitude = null;
    public ?float $longitude = null;
    public ?int $altitude = null;
    public ?int $heading = null;
    public ?int $groundspeed = null;
    public ?int $verticalRate = null;

    // OOOI Times (ISO 8601 format)
    public ?string $outUtc = null;
    public ?string $offUtc = null;
    public ?string $onUtc = null;
    public ?string $inUtc = null;

    // Estimated Times
    public ?string $etaUtc = null;
    public ?string $etdUtc = null;

    // CDM T1-T4 Predictions
    public ?string $lrtdUtc = null;  // Last Runway Time of Departure
    public ?string $lrtaUtc = null;  // Last Runway Time of Arrival
    public ?string $lgtdUtc = null;  // Last Gate Time of Departure
    public ?string $lgtaUtc = null;  // Last Gate Time of Arrival

    // Controlled Times
    public ?string $edctUtc = null;
    public ?string $ctdUtc = null;
    public ?string $ctaUtc = null;
    public ?string $slotTimeUtc = null;

    // TMI Control
    public ?string $ctlType = null;
    public ?bool $gsHeld = null;
    public ?int $delayMinutes = null;
    public ?string $programId = null;
    public ?bool $isExempt = null;
    public ?string $exemptReason = null;

    // Status
    public ?string $phase = null;
    public bool $isActive = true;
    public ?float $pctComplete = null;

    // Gates/Runways
    public ?string $departureGate = null;
    public ?string $arrivalGate = null;
    public ?string $departureRunway = null;
    public ?string $arrivalRunway = null;

    /**
     * Create Flight from array
     */
    public function __construct(array $data = [])
    {
        $this->fromArray($data);
    }

    /**
     * Populate from array
     */
    public function fromArray(array $data): self
    {
        // Map snake_case keys to camelCase properties
        $map = [
            'gufi' => 'gufi',
            'flight_key' => 'flightKey',
            'callsign' => 'callsign',
            'cid' => 'cid',
            'pilot_name' => 'pilotName',
            'dept_icao' => 'deptIcao',
            'dest_icao' => 'destIcao',
            'alt_icao' => 'altIcao',
            'diversion_aerodrome' => 'diversionAerodrome',
            'aircraft_type' => 'aircraftType',
            'airline_icao' => 'airlineIcao',
            'airline_name' => 'airlineName',
            'registration' => 'registration',
            'wake_category' => 'wakeCategory',
            'route' => 'route',
            'cruise_altitude' => 'cruiseAltitude',
            'cruise_speed' => 'cruiseSpeed',
            'sid' => 'sid',
            'star' => 'star',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'altitude' => 'altitude',
            'altitude_ft' => 'altitude',
            'heading' => 'heading',
            'heading_deg' => 'heading',
            'groundspeed' => 'groundspeed',
            'groundspeed_kts' => 'groundspeed',
            'vertical_rate' => 'verticalRate',
            'vertical_rate_fpm' => 'verticalRate',
            'out_utc' => 'outUtc',
            'off_utc' => 'offUtc',
            'on_utc' => 'onUtc',
            'in_utc' => 'inUtc',
            'eta_utc' => 'etaUtc',
            'etd_utc' => 'etdUtc',
            'lrtd_utc' => 'lrtdUtc',
            'lrta_utc' => 'lrtaUtc',
            'lgtd_utc' => 'lgtdUtc',
            'lgta_utc' => 'lgtaUtc',
            'edct_utc' => 'edctUtc',
            'ctd_utc' => 'ctdUtc',
            'cta_utc' => 'ctaUtc',
            'slot_time_utc' => 'slotTimeUtc',
            'ctl_type' => 'ctlType',
            'gs_held' => 'gsHeld',
            'delay_minutes' => 'delayMinutes',
            'program_id' => 'programId',
            'is_exempt' => 'isExempt',
            'exempt_reason' => 'exemptReason',
            'phase' => 'phase',
            'is_active' => 'isActive',
            'pct_complete' => 'pctComplete',
            'departure_gate' => 'departureGate',
            'arrival_gate' => 'arrivalGate',
            'departure_runway' => 'departureRunway',
            'arrival_runway' => 'arrivalRunway',
        ];

        foreach ($map as $key => $property) {
            if (isset($data[$key])) {
                $this->$property = $data[$key];
            }
        }

        // Also accept camelCase directly
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }

        return $this;
    }

    /**
     * Convert to array for API requests
     */
    public function toArray(): array
    {
        $data = [];

        // Required fields
        $data['callsign'] = $this->callsign;
        $data['dept_icao'] = $this->deptIcao;
        $data['dest_icao'] = $this->destIcao;

        // Optional fields - only include if set
        if ($this->cid !== null) $data['cid'] = $this->cid;
        if ($this->aircraftType !== null) $data['aircraft_type'] = $this->aircraftType;
        if ($this->route !== null) $data['route'] = $this->route;
        if ($this->cruiseAltitude !== null) $data['cruise_altitude'] = $this->cruiseAltitude;
        if ($this->cruiseSpeed !== null) $data['cruise_speed'] = $this->cruiseSpeed;

        // Position
        if ($this->latitude !== null) $data['latitude'] = $this->latitude;
        if ($this->longitude !== null) $data['longitude'] = $this->longitude;
        if ($this->altitude !== null) $data['altitude'] = $this->altitude;
        if ($this->heading !== null) $data['heading'] = $this->heading;
        if ($this->groundspeed !== null) $data['groundspeed'] = $this->groundspeed;
        if ($this->verticalRate !== null) $data['vertical_rate_fpm'] = $this->verticalRate;

        // OOOI times
        if ($this->outUtc !== null) $data['out_utc'] = $this->outUtc;
        if ($this->offUtc !== null) $data['off_utc'] = $this->offUtc;
        if ($this->onUtc !== null) $data['on_utc'] = $this->onUtc;
        if ($this->inUtc !== null) $data['in_utc'] = $this->inUtc;

        // Estimated times
        if ($this->etaUtc !== null) $data['eta_utc'] = $this->etaUtc;
        if ($this->etdUtc !== null) $data['etd_utc'] = $this->etdUtc;

        // CDM predictions
        if ($this->lrtdUtc !== null) $data['lrtd_utc'] = $this->lrtdUtc;
        if ($this->lrtaUtc !== null) $data['lrta_utc'] = $this->lrtaUtc;
        if ($this->lgtdUtc !== null) $data['lgtd_utc'] = $this->lgtdUtc;
        if ($this->lgtaUtc !== null) $data['lgta_utc'] = $this->lgtaUtc;

        // TMI control
        if ($this->ctlType !== null || $this->gsHeld !== null || $this->delayMinutes !== null) {
            $tmi = [];
            if ($this->ctlType !== null) $tmi['ctl_type'] = $this->ctlType;
            if ($this->gsHeld !== null) $tmi['gs_held'] = $this->gsHeld;
            if ($this->slotTimeUtc !== null) $tmi['slot_time_utc'] = $this->slotTimeUtc;
            if ($this->delayMinutes !== null) $tmi['delay_minutes'] = $this->delayMinutes;
            if ($this->programId !== null) $tmi['program_id'] = $this->programId;
            $data['tmi'] = $tmi;
        }

        // Status
        if ($this->phase !== null) $data['phase'] = $this->phase;
        $data['is_active'] = $this->isActive;

        // Gates/Runways
        if ($this->departureGate !== null) $data['departure_gate'] = $this->departureGate;
        if ($this->arrivalGate !== null) $data['arrival_gate'] = $this->arrivalGate;
        if ($this->departureRunway !== null) $data['departure_runway'] = $this->departureRunway;
        if ($this->arrivalRunway !== null) $data['arrival_runway'] = $this->arrivalRunway;

        return $data;
    }

    /**
     * Create from API response array
     */
    public static function fromApiResponse(array $data): self
    {
        return new self($data);
    }

    /**
     * Check if flight is complete (all OOOI times set)
     */
    public function isComplete(): bool
    {
        return $this->outUtc !== null
            && $this->offUtc !== null
            && $this->onUtc !== null
            && $this->inUtc !== null;
    }

    /**
     * Check if flight is airborne
     */
    public function isAirborne(): bool
    {
        return $this->offUtc !== null && $this->onUtc === null;
    }
}
