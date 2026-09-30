<?php

namespace App\Exceptions;

use RuntimeException;

class RenderBusyException extends RuntimeException
{
    public function __construct(string $message = 'Render busy - another job holds the lock', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}