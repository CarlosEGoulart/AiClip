<?php

namespace App\Services;

use App\Contracts\PublishResult;
use App\Contracts\PublishStatus;
use App\Contracts\SocialPublisher;
use App\Contracts\SocialConnectionProvider;
use App\Models\SocialConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramPublisher implements SocialPublisher
{
    public function __construct(
        protected SocialConnectionManager $manager,
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
            // Step 1: Create media container via Instagram Graph API
            $containerResponse = Http::withToken($token)
                ->timeout(120)
                ->post(
                    sprintf('https://graph.facebook.com/v18.0/%s/media', $connection->provider_user_id),
                    [
                        'video_url' => $input['local_path'] ?? '',
                        'caption' => $input['description'] ?? '',
                        'media_type' => 'REELS',
                    ],
                );

            if (! $containerResponse->successful()) {
                Log::warning('Instagram media container creation failed', [
                    'status' => $containerResponse->status(),
                    'platform' => 'instagram',
                ]);

                return new PublishResult(
                    externalVideoId: '',
                    videoUrl: '',
                    status: 'failed',
                    errorMessage: 'Instagram container creation failed: HTTP ' . $containerResponse->status(),
                );
            }

            $containerData = $containerResponse->json();
            $creationId = $containerData['id'] ?? '';

            if (empty($creationId)) {
                Log::warning('Instagram media container returned no ID', [
                    'platform' => 'instagram',
                ]);

                return new PublishResult(
                    externalVideoId: '',
                    videoUrl: '',
                    status: 'failed',
                    errorMessage: 'Instagram container creation returned no ID',
                );
            }

            // Step 2: Publish the media container
            $publishResponse = Http::withToken($token)
                ->timeout(120)
                ->post(
                    sprintf('https://graph.facebook.com/v18.0/%s/media_publish', $connection->provider_user_id),
                    [
                        'creation_id' => $creationId,
                    ],
                );

            if (! $publishResponse->successful()) {
                Log::warning('Instagram media publish failed', [
                    'status' => $publishResponse->status(),
                    'creation_id' => $creationId,
                    'platform' => 'instagram',
                ]);

                return new PublishResult(
                    externalVideoId: '',
                    videoUrl: '',
                    status: 'failed',
                    errorMessage: 'Instagram publish failed: HTTP ' . $publishResponse->status(),
                );
            }

            $publishData = $publishResponse->json();
            $postId = $publishData['id'] ?? $creationId;
            $postUrl = 'https://instagram.com/p/' . $postId;

            Log::info('Instagram publish completed', [
                'external_post_id' => $postId,
                'platform' => 'instagram',
            ]);

            return new PublishResult(
                externalVideoId: $postId,
                videoUrl: $postUrl,
                status: 'published',
            );
        } catch (\Throwable $e) {
            Log::warning('Instagram publish error', [
                'error' => $e->getMessage(),
                'platform' => 'instagram',
            ]);

            return new PublishResult(
                externalVideoId: '',
                videoUrl: '',
                status: 'failed',
                errorMessage: 'Publish failed: ' . $e->getMessage(),
            );
        }
    }

    public function getPublishStatus(string $externalVideoId): PublishStatus
    {
        $provider = app(SocialConnectionProvider::class . ':instagram');

        try {
            $isValid = $provider->validateToken('');

            return new PublishStatus(
                externalVideoId: $externalVideoId,
                status: $isValid ? 'published' : 'not_found',
            );
        } catch (\Throwable $e) {
            return new PublishStatus(
                externalVideoId: $externalVideoId,
                status: 'failed',
                errorMessage: $e->getMessage(),
            );
        }
    }

    public function hasPublishPermission(string $accessToken): bool
    {
        $provider = app(SocialConnectionProvider::class . ':instagram');

        return $provider->validateToken($accessToken);
    }
}
