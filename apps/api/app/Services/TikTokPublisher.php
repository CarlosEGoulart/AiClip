<?php

namespace App\Services;

use App\Contracts\PublishResult;
use App\Contracts\PublishStatus;
use App\Contracts\SocialPublisher;
use App\Contracts\SocialConnectionProvider;
use App\Models\SocialConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokPublisher implements SocialPublisher
{
    public function __construct(
        protected SocialConnectionManager $manager,
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
     * @param  array{local_path: string, title: string, description?: string, privacy_status?: string, connection?: SocialConnection}  $input
     * @return PublishResult
     */
    public function publishVideo(array $input): PublishResult
    {
        $connection = $input['connection'] ?? null;

        if (! $connection instanceof SocialConnection) {
            return new PublishResult(
                externalVideoId: '',
                videoUrl: '',
                status: 'failed',
                errorMessage: 'No social connection provided',
            );
        }

        $token = $this->manager->getValidAccessToken($connection);

        if (! $token) {
            return new PublishResult(
                externalVideoId: '',
                videoUrl: '',
                status: 'failed',
                errorMessage: 'No valid access token available',
            );
        }

        try {
            // Step 1: Initialize video upload with TikTok Video Upload API
            $initResponse = Http::withToken($token)
                ->timeout(120)
                ->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
                    'post_info' => [
                        'title' => $input['title'],
                        'description' => $input['description'] ?? '',
                        'privacy_level' => $input['privacy_status'] ?? 'SELF_ONLY',
                        'disable_duet' => false,
                        'disable_comment' => false,
                        'disable_stitch' => false,
                        'video_cover_timestamp_ms' => 1000,
                    ],
                    'source_info' => [
                        'source' => 'PULL_FROM_URL',
                        'video_url' => $input['local_path'] ?? '',
                    ],
                ]);

            if (! $initResponse->successful()) {
                Log::warning('TikTok video upload init failed', [
                    'status' => $initResponse->status(),
                    'platform' => 'tiktok',
                ]);

                return new PublishResult(
                    externalVideoId: '',
                    videoUrl: '',
                    status: 'failed',
                    errorMessage: 'TikTok upload init failed: HTTP ' . $initResponse->status(),
                );
            }

            $initData = $initResponse->json();
            $publishId = $initData['data']['publish_id'] ?? '';

            if (empty($publishId)) {
                Log::warning('TikTok video upload init returned no publish ID', [
                    'platform' => 'tiktok',
                ]);

                return new PublishResult(
                    externalVideoId: '',
                    videoUrl: '',
                    status: 'failed',
                    errorMessage: 'TikTok upload init returned no publish ID',
                );
            }

            // Step 2: Check publish status
            $statusResponse = Http::withToken($token)
                ->timeout(120)
                ->post('https://open.tiktokapis.com/v2/post/publish/video/fetch/', [
                    'publish_id' => $publishId,
                ]);

            if (! $statusResponse->successful()) {
                Log::warning('TikTok publish status check failed', [
                    'status' => $statusResponse->status(),
                    'publish_id' => $publishId,
                    'platform' => 'tiktok',
                ]);

                return new PublishResult(
                    externalVideoId: $publishId,
                    videoUrl: '',
                    status: 'failed',
                    errorMessage: 'TikTok publish status check failed: HTTP ' . $statusResponse->status(),
                );
            }

            $statusData = $statusResponse->json();
            $videoId = $statusData['data']['video_id'] ?? $publishId;
            $videoUrl = 'https://www.tiktok.com/@' . ($connection->display_name ?? 'user') . '/video/' . $videoId;

            Log::info('TikTok publish completed', [
                'external_video_id' => $videoId,
                'platform' => 'tiktok',
            ]);

            return new PublishResult(
                externalVideoId: $videoId,
                videoUrl: $videoUrl,
                status: 'published',
            );
        } catch (\Throwable $e) {
            Log::warning('TikTok publish error', [
                'error' => $e->getMessage(),
                'platform' => 'tiktok',
            ]);

            return new PublishResult(
                externalVideoId: '',
                videoUrl: '',
                status: 'failed',
                errorMessage: 'Publish failed',
            );
        }
    }

    public function getPublishStatus(string $externalVideoId): PublishStatus
    {
        // No access token is available in this scope, so the post status
        // cannot be verified against the platform API. Report not_found
        // rather than validating an empty token.
        return new PublishStatus(
            externalVideoId: $externalVideoId,
            status: 'not_found',
        );
    }

    public function hasPublishPermission(string $accessToken): bool
    {
        $provider = app(SocialConnectionProvider::class . ':tiktok');

        return $provider->validateToken($accessToken);
    }
}
