<?php

namespace App\Contracts;

interface SocialPublisher
{
    /**
     * Unique platform identifier (e.g., 'youtube').
     */
    public function getPlatform(): string;

    /**
     * Human-readable platform label for UI display.
     */
    public function getPlatformLabel(): string;

    /**
     * Publish a video to the platform.
     * Returns publishing result without persisting it.
     *
     * @param  array{local_path: string, title: string, description?: string, privacy_status?: string}  $input
     * @return PublishResult
     */
    public function publishVideo(array $input): PublishResult;

    /**
     * Check the status of a previously published video on the platform.
     *
     * @return PublishStatus
     */
    public function getPublishStatus(string $externalVideoId): PublishStatus;

    /**
     * Validate that the connection has publishing permission for this platform.
     */
    public function hasPublishPermission(string $accessToken): bool;
}
