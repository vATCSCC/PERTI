<?php

declare(strict_types=1);

namespace VatSim\Swim\Exceptions;

/**
 * Exception for rate limit errors
 */
class SwimRateLimitException extends SwimApiException
{
    protected ?int $retryAfter;

    public function __construct(string $message = 'Rate limit exceeded', int $httpStatusCode = 429, ?int $retryAfter = null, ?\Throwable $previous = null)
    {
        $this->retryAfter = $retryAfter;
        parent::__construct($message, $httpStatusCode, $previous);
    }

    /**
     * Get retry-after seconds (if provided by API)
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Check if retry-after is specified
     */
    public function hasRetryAfter(): bool
    {
        return $this->retryAfter !== null;
    }
}
