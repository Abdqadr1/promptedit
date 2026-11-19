<?php

namespace App\Services\Pixelforge\Exceptions;

use RuntimeException;

/**
 * Pixelforge refused the request permanently (validation, content policy, auth); retrying will not help.
 */
class RejectedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message, $status);
    }
}
