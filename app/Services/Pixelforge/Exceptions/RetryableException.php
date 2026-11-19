<?php

namespace App\Services\Pixelforge\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A transient Pixelforge failure (rate limit, concurrency limit, overload, network) that is safe to retry.
 */
class RetryableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
