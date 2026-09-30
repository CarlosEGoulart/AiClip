<?php

namespace App\Exceptions;

use RuntimeException;

class RenderVersionConflictException extends RuntimeException
{
    public function __construct(string $message = 'Render version conflict - existing render has different authority or configuration', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}