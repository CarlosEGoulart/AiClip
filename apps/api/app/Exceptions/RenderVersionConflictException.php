<?php

namespace App\Exceptions;

use RuntimeException;

class RenderVersionConflictException extends RuntimeException
{
    public function __construct(string $message = 'render_version_conflict', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}