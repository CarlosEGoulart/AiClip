<?php

namespace App\Contracts;

class PublishStatus
{
    /**
     * @param  string  $externalVideoId  Platform-specific video ID
     * @param  string  $status  'published', 'processing', 'failed', or 'not_found'
     * @param  ?string  $videoUrl  Public video URL, null if not available
     * @param  ?string  $errorMessage  Error message if failed, null otherwise
     */
    public function __construct(
        public readonly string $externalVideoId,
        public readonly string $status,
        public readonly ?string $videoUrl = null,
        public readonly ?string $errorMessage = null,
    ) {}
}
