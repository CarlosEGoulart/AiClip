<?php

namespace App\Services;

class InitiateConnectionResult
{
    public function __construct(
        public readonly string $authorizationUrl,
        public readonly string $state,
        public readonly int $connectionId,
    ) {}
}
