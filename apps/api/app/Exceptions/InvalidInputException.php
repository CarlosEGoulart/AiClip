<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidInputException extends RuntimeException
{
    public function __construct(string $message = 'invalid_input', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}