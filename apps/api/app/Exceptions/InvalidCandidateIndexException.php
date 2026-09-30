<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidCandidateIndexException extends RuntimeException
{
    public function __construct(string $message = 'invalid_candidate_index', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}