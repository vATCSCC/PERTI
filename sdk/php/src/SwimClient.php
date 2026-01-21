<?php

declare(strict_types=1);

namespace VatSim\Swim;

use VatSim\Swim\Models\Flight;
use VatSim\Swim\Models\Track;
use VatSim\Swim\Models\IngestResult;
use VatSim\Swim\Models\FlightsResponse;

/**
 * VATSWIM API Client
 *
 * Main client class for interacting with the VATSWIM API.
 *
 * @example
 * ```php
 * $client = new SwimClient('swim_par_your_api_key', [
 *     'source_id' => 'phpvms',
 *     'base_url' => 'https://perti.vatcscc.org/api/swim/v1'
 * ]);
 *
 * // Get active flights
 * $flights = $client->getFlights(['dest_icao' => 'KJFK']);
 *
 * // Ingest flight data
 * $result = $client->ingestFlights([
 *     new Flight([
 *         'callsign' => 'UAL123',
 *         'dept_icao' => 'KLAX',
 *         'dest_icao' => 'KJFK',
 *         // ...
 *     ])
 * ]);
 * ```
 */
class SwimClient
{
    private RestClient $rest;
    private string $sourceId;

    /**
     * Create a new SWIM client
     *
     * @param string $apiKey API key (e.g., swim_par_xxx)
     * @param array{
     *     base_url?: string,
     *     source_id?: string,
     *     timeout?: int,
     *     verify_ssl?: bool
     * } $options Client options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        $this->sourceId = $options['source_id'] ?? 'php_client';
        $this->rest = new RestClient($apiKey, $options);
    }

    /**
     * Get the REST client instance
     */
    public function getRestClient(): RestClient
    {
        return $this->rest;
    }

    // =========================================================================
    // Flight Queries
    // =========================================================================

    /**
     * Get active flights
     *
     * @param array{
     *     status?: string,
     *     dept_icao?: string,
     *     dest_icao?: string,
     *     artcc?: string,
     *     callsign?: string,
     *     tmi_controlled?: bool,
     *     phase?: string,
     *     format?: string,
     *     page?: int,
     *     per_page?: int
     * } $filters Query filters
     * @return FlightsResponse
     */
    public function getFlights(array $filters = []): FlightsResponse
    {
        $response = $this->rest->get('/flights', $filters);
        return FlightsResponse::fromArray($response);
    }

    /**
     * Get a single flight by GUFI or flight key
     *
     * @param string $identifier GUFI or flight_key
     * @param string $format Response format (json, fixm)
     * @return Flight|null
     */
    public function getFlight(string $identifier, string $format = 'json'): ?Flight
    {
        try {
            $response = $this->rest->get('/flight', [
                'gufi' => $identifier,
                'format' => $format
            ]);

            if (isset($response['data'])) {
                return Flight::fromArray($response['data']);
            }
        } catch (\Exception $e) {
            // Flight not found
        }

        return null;
    }

    /**
     * Get positions as GeoJSON
     *
     * @param array{
     *     dept_icao?: string,
     *     dest_icao?: string,
     *     artcc?: string,
     *     bbox?: string
     * } $filters Query filters
     * @return array GeoJSON FeatureCollection
     */
    public function getPositions(array $filters = []): array
    {
        $filters['format'] = 'geojson';
        return $this->rest->get('/positions', $filters);
    }

    // =========================================================================
    // TMI Queries
    // =========================================================================

    /**
     * Get active TMI programs (Ground Stops, GDPs)
     *
     * @param string|null $type Filter by type (GS, GDP, AFP)
     * @return array
     */
    public function getTmiPrograms(?string $type = null): array
    {
        $params = $type ? ['type' => $type] : [];
        return $this->rest->get('/tmi/programs', $params);
    }

    /**
     * Get TMI-controlled flights
     *
     * @param array $filters Query filters
     * @return array
     */
    public function getTmiControlledFlights(array $filters = []): array
    {
        return $this->rest->get('/tmi/controlled', $filters);
    }

    // =========================================================================
    // Metering Queries
    // =========================================================================

    /**
     * Get metering data for an airport
     *
     * @param string $airport Airport ICAO code
     * @param array $filters Additional filters
     * @return array
     */
    public function getMetering(string $airport, array $filters = []): array
    {
        return $this->rest->get("/metering/{$airport}", $filters);
    }

    /**
     * Get arrival sequence for an airport
     *
     * @param string $airport Airport ICAO code
     * @return array
     */
    public function getArrivalSequence(string $airport): array
    {
        return $this->rest->get("/metering/{$airport}/sequence");
    }

    // =========================================================================
    // Data Ingest
    // =========================================================================

    /**
     * Ingest flight data (ADL)
     *
     * @param Flight[] $flights Array of Flight objects
     * @return IngestResult
     */
    public function ingestFlights(array $flights): IngestResult
    {
        $data = [
            'flights' => array_map(fn(Flight $f) => $f->toArray(), $flights)
        ];

        $response = $this->rest->post('/ingest/adl', $data);
        return IngestResult::fromArray($response);
    }

    /**
     * Ingest track updates (positions)
     *
     * @param Track[] $tracks Array of Track objects
     * @return IngestResult
     */
    public function ingestTracks(array $tracks): IngestResult
    {
        $data = [
            'tracks' => array_map(fn(Track $t) => $t->toArray(), $tracks)
        ];

        $response = $this->rest->post('/ingest/track', $data);
        return IngestResult::fromArray($response);
    }

    /**
     * Ingest metering data
     *
     * @param string $airport Destination airport
     * @param array $metering Array of metering records
     * @return IngestResult
     */
    public function ingestMetering(string $airport, array $metering): IngestResult
    {
        $data = [
            'airport' => $airport,
            'metering' => $metering
        ];

        $response = $this->rest->post('/ingest/metering', $data);
        return IngestResult::fromArray($response);
    }

    // =========================================================================
    // Convenience Methods
    // =========================================================================

    /**
     * Quick ingest of a single flight with OOOI times
     *
     * @param string $callsign Flight callsign
     * @param string $deptIcao Departure airport
     * @param string $destIcao Destination airport
     * @param array{
     *     out_utc?: string,
     *     off_utc?: string,
     *     on_utc?: string,
     *     in_utc?: string,
     *     eta_utc?: string,
     *     latitude?: float,
     *     longitude?: float,
     *     altitude_ft?: int
     * } $data Additional data
     * @return IngestResult
     */
    public function updateFlight(
        string $callsign,
        string $deptIcao,
        string $destIcao,
        array $data = []
    ): IngestResult {
        $flight = new Flight(array_merge([
            'callsign' => $callsign,
            'dept_icao' => $deptIcao,
            'dest_icao' => $destIcao,
            'is_active' => true
        ], $data));

        return $this->ingestFlights([$flight]);
    }

    /**
     * Send a single position update
     *
     * @param string $callsign Flight callsign
     * @param float $latitude Latitude
     * @param float $longitude Longitude
     * @param int|null $altitude Altitude in feet
     * @param int|null $groundspeed Ground speed in knots
     * @param int|null $heading Heading in degrees
     * @param int|null $verticalRate Vertical rate in fpm
     * @return IngestResult
     */
    public function updatePosition(
        string $callsign,
        float $latitude,
        float $longitude,
        ?int $altitude = null,
        ?int $groundspeed = null,
        ?int $heading = null,
        ?int $verticalRate = null
    ): IngestResult {
        $track = new Track([
            'callsign' => $callsign,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'altitude_ft' => $altitude,
            'ground_speed_kts' => $groundspeed,
            'heading_deg' => $heading,
            'vertical_rate_fpm' => $verticalRate,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
        ]);

        return $this->ingestTracks([$track]);
    }
}
