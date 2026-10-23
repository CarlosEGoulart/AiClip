<?php

namespace App\Contracts;

class ProviderUserProfile
{
    public function __construct(
        public readonly string $providerUserId,
        public readonly string $displayName,
        public readonly ?string $avatarUrl,
        public readonly ?string $channelUrl,
    ) {}
}
