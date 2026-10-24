<?php

namespace App\Services;

use App\Models\SocialConnection;

class HandleCallbackResult
{
    public function __construct(
        public readonly SocialConnection $connection,
    ) {}
}
