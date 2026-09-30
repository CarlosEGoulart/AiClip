<?php

namespace App\Exceptions;

use RuntimeException;

class RenderAbortedException extends RuntimeException
{
    public function __construct(string $message = 'Render aborted', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}