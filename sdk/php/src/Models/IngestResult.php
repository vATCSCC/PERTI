<?php

declare(strict_types=1);

namespace VatSim\Swim\Models;

/**
 * Result of an ingest API call
 */
class IngestResult
{
    public bool $success;
    public int $processed = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $errors = 0;
    public array $errorDetails = [];
    public ?string $timestamp = null;

    /**
     * Create from API response array
     */
    public static function fromArray(array $response): self
    {
        $result = new self();
        $result->success = $response['success'] ?? false;

        if (isset($response['data'])) {
            $data = $response['data'];
            $result->processed = $data['processed'] ?? 0;
            $result->created = $data['created'] ?? 0;
            $result->updated = $data['updated'] ?? 0;
            $result->errors = $data['errors'] ?? 0;
            $result->errorDetails = $data['error_details'] ?? [];
        }

        $result->timestamp = $response['timestamp'] ?? null;

        return $result;
    }

    /**
     * Check if ingest was fully successful (no errors)
     */
    public function isFullySuccessful(): bool
    {
        return $this->success && $this->errors === 0;
    }

    /**
     * Check if any records were processed
     */
    public function hasProcessedRecords(): bool
    {
        return $this->processed > 0;
    }

    /**
     * Get summary string
     */
    public function getSummary(): string
    {
        return sprintf(
            'Processed: %d, Created: %d, Updated: %d, Errors: %d',
            $this->processed,
            $this->created,
            $this->updated,
            $this->errors
        );
    }
}
