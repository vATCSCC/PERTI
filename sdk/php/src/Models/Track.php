<?php

declare(strict_types=1);

namespace VatSim\Swim\Models;

/**
 * Track (position update) model for SWIM API
 */
class Track
{
    public string $callsign;
    public float $latitude;
    public float $longitude;
    public ?int $altitudeFt = null;
    public ?int $groundSpeedKts = null;
    public ?int $headingDeg = null;
    public ?int $verticalRateFpm = null;
    public ?string $squawk = null;
    public ?string $trackSource = null;
    public ?string $timestamp = null;

    /**
     * Create Track from array
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
        $this->callsign = $data['callsign'] ?? '';
        $this->latitude = (float) ($data['latitude'] ?? 0);
        $this->longitude = (float) ($data['longitude'] ?? 0);
        $this->altitudeFt = isset($data['altitude_ft']) ? (int) $data['altitude_ft'] : null;
        $this->groundSpeedKts = isset($data['ground_speed_kts']) ? (int) $data['ground_speed_kts'] : null;
        $this->headingDeg = isset($data['heading_deg']) ? (int) $data['heading_deg'] : null;
        $this->verticalRateFpm = isset($data['vertical_rate_fpm']) ? (int) $data['vertical_rate_fpm'] : null;
        $this->squawk = $data['squawk'] ?? null;
        $this->trackSource = $data['track_source'] ?? null;
        $this->timestamp = $data['timestamp'] ?? null;

        return $this;
    }

    /**
     * Convert to array for API requests
     */
    public function toArray(): array
    {
        $data = [
            'callsign' => $this->callsign,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];

        if ($this->altitudeFt !== null) $data['altitude_ft'] = $this->altitudeFt;
        if ($this->groundSpeedKts !== null) $data['ground_speed_kts'] = $this->groundSpeedKts;
        if ($this->headingDeg !== null) $data['heading_deg'] = $this->headingDeg;
        if ($this->verticalRateFpm !== null) $data['vertical_rate_fpm'] = $this->verticalRateFpm;
        if ($this->squawk !== null) $data['squawk'] = $this->squawk;
        if ($this->trackSource !== null) $data['track_source'] = $this->trackSource;
        if ($this->timestamp !== null) $data['timestamp'] = $this->timestamp;

        return $data;
    }

    /**
     * Check if position is valid
     */
    public function isValid(): bool
    {
        return !empty($this->callsign)
            && $this->latitude >= -90 && $this->latitude <= 90
            && $this->longitude >= -180 && $this->longitude <= 180;
    }
}
