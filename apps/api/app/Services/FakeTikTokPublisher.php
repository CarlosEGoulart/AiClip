<?php

namespace App\Services;

use App\Contracts\PublishResult;
use App\Contracts\PublishStatus;
use App\Contracts\SocialPublisher;

class FakeTikTokPublisher implements SocialPublisher
{
    public function __construct(
        protected bool $success = true,
        protected string $externalVideoId = 'tiktok_video_12345',
        protected string $videoUrl = 'https://www.tiktok.com/@testaccount/video/tiktok_video_12345',
    ) {}

    public function getPlatform(): string
    {
        return 'tiktok';
    }

    public function getPlatformLabel(): string
    {
        return 'TikTok';
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
                errorMessage: 'Simulated TikTok publish failure',
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
