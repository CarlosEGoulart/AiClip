<?php

namespace App\Exceptions;

use RuntimeException;

class UpstreamRecommendationUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Upstream recommendation unavailable', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}