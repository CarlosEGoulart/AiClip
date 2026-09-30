<?php

namespace App\Exceptions;

use RuntimeException;

class UpstreamRecommendationFailedException extends RuntimeException
{
    public function __construct(string $message = 'upstream_recommendation_failed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}