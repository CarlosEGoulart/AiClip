<?php

namespace App\Contracts;

class PublishResult
{
    /**
     * @param  string  $externalVideoId  Platform-specific video ID
     * @param  string  $videoUrl  Public video URL
     * @param  string  $status  'published', 'processing', or 'failed'
     * @param  ?string  $errorMessage  Error message if failed, null otherwise
     */
    public function __construct(
        public readonly string $externalVideoId,
        public readonly string $videoUrl,
        public readonly string $status,
        public readonly ?string $errorMessage = null,
    ) {}
}
