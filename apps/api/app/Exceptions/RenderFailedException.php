<?php

namespace App\Exceptions;

use RuntimeException;

class RenderFailedException extends RuntimeException
{
    public function __construct(string $message = 'Render failed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}