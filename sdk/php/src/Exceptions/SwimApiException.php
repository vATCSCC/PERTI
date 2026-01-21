<?php

declare(strict_types=1);

namespace VatSim\Swim\Exceptions;

/**
 * Base exception for SWIM API errors
 */
class SwimApiException extends \Exception
{
    protected int $httpStatusCode;

    public function __construct(string $message = '', int $httpStatusCode = 0, ?\Throwable $previous = null)
    {
        $this->httpStatusCode = $httpStatusCode;
        parent::__construct($message, $httpStatusCode, $previous);
    }

    /**
     * Get HTTP status code
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
