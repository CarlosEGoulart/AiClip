<?php

namespace App\Exceptions;

use RuntimeException;

class UpstreamRecommendationMissingException extends RuntimeException
{
    public function __construct(string $message = 'upstream_recommendation_missing', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}