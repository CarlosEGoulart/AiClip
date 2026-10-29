<?php

namespace App\Services;

use App\Contracts\PublishResult;
use App\Contracts\PublishStatus;
use App\Contracts\SocialPublisher;

class FakeInstagramPublisher implements SocialPublisher
{
    public function __construct(
        protected bool $success = true,
        protected string $externalVideoId = 'instagram_post_12345',
        protected string $videoUrl = 'https://instagram.com/p/instagram_post_12345',
    ) {}

    public function getPlatform(): string
    {
        return 'instagram';
    }

    public function getPlatformLabel(): string
    {
        return 'Instagram';
    }

    /**
     * @param  array{local_path: string, title: string, description?: string, privacy_status?: string, connection?: \App\Models\SocialConnection}  $input
     * @return PublishResult
     */
    public function publishVideo(array $input): PublishResult
    {
        if (! $this->success) {
            return new PublishResult(
                externalVideoId: '',
                videoUrl: '',
                status: 'failed',
                errorMessage: 'Simulated Instagram publish failure',
            );
        }

        return new PublishResult(
            externalVideoId: $this->externalVideoId,
            videoUrl: $this->videoUrl,
            status: 'published',
        );
    }

    public function getPublishStatus(string $externalVideoId): PublishStatus
    {
        return new PublishStatus(
            externalVideoId: $externalVideoId,
            status: $this->success ? 'published' : 'failed',
            videoUrl: $this->success ? $this->videoUrl : null,
            errorMessage: $this->success ? null : 'Simulated failure',
        );
    }

    public function hasPublishPermission(string $accessToken): bool
    {
        return $this->success;
    }
}
