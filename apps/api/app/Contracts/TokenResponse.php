<?php

namespace App\Contracts;

class TokenResponse
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly int $expiresIn, // seconds until access token expires
        public readonly array $scopes,
    ) {}
}
