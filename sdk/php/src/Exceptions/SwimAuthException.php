<?php

declare(strict_types=1);

namespace VatSim\Swim\Exceptions;

/**
 * Exception for authentication/authorization errors
 */
class SwimAuthException extends SwimApiException
{
    public function __construct(string $message = 'Authentication failed', int $httpStatusCode = 401, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatusCode, $previous);
    }
}
