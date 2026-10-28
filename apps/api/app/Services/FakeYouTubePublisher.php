<?php

namespace App\Services;

use App\Contracts\PublishResult;
use App\Contracts\PublishStatus;
use App\Contracts\SocialPublisher;

class FakeYouTubePublisher implements SocialPublisher
{
    public function __construct(
        protected bool $success = true,
        protected string $externalVideoId = 'fake_video_id_12345',
        protected string $videoUrl = 'https://youtu.be/fake_video_id_12345',
    ) {}

    public function getPlatform(): string
    {
        return 'youtube';
    }

    public function getPlatformLabel(): string
    {
        return 'YouTube';
    }

    /**
     * @param  array{local_path: string, title: string, description?: string, privacy_status?: string}  $input
     * @return PublishResult
     */
    public function publishVideo(array $input): PublishResult
    {
        if (! $this->success) {
            return new PublishResult(
                externalVideoId: '',
                videoUrl: '',
                status: 'failed',
                errorMessage: 'Simulated YouTube upload failure',
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
