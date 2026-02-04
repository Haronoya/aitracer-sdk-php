<?php

declare(strict_types=1);

namespace AITracer\Exceptions;

use Exception;
use Throwable;

/**
 * Base exception for AITracer SDK.
 */
class AITracerException extends Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
