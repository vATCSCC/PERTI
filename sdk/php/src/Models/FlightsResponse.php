<?php

declare(strict_types=1);

namespace VatSim\Swim\Models;

/**
 * Response from flights list endpoint
 */
class FlightsResponse
{
    /** @var Flight[] */
    public array $flights = [];

    public int $total = 0;
    public int $page = 1;
    public int $perPage = 50;
    public int $totalPages = 1;
    public bool $hasMore = false;
    public ?string $timestamp = null;

    /**
     * Create from API response array
     */
    public static function fromArray(array $response): self
    {
        $result = new self();

        // Parse flights data
        $data = $response['data'] ?? [];
        $result->flights = array_map(
            fn(array $f) => Flight::fromApiResponse($f),
            $data
        );

        // Parse pagination
        if (isset($response['pagination'])) {
            $pagination = $response['pagination'];
            $result->total = $pagination['total'] ?? 0;
            $result->page = $pagination['page'] ?? 1;
            $result->perPage = $pagination['per_page'] ?? 50;
            $result->totalPages = $pagination['total_pages'] ?? 1;
            $result->hasMore = $pagination['has_more'] ?? false;
        }

        $result->timestamp = $response['timestamp'] ?? null;

        return $result;
    }

    /**
     * Get count of flights in this response
     */
    public function count(): int
    {
        return count($this->flights);
    }

    /**
     * Check if there are more pages
     */
    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    /**
     * Get flights as array
     *
     * @return Flight[]
     */
    public function getFlights(): array
    {
        return $this->flights;
    }

    /**
     * Filter flights by destination
     *
     * @return Flight[]
     */
    public function filterByDestination(string $icao): array
    {
        return array_filter(
            $this->flights,
            fn(Flight $f) => strtoupper($f->destIcao) === strtoupper($icao)
        );
    }

    /**
     * Filter flights by departure
     *
     * @return Flight[]
     */
    public function filterByDeparture(string $icao): array
    {
        return array_filter(
            $this->flights,
            fn(Flight $f) => strtoupper($f->deptIcao) === strtoupper($icao)
        );
    }

    /**
     * Get airborne flights only
     *
     * @return Flight[]
     */
    public function getAirborne(): array
    {
        return array_filter(
            $this->flights,
            fn(Flight $f) => $f->isAirborne()
        );
    }
}
