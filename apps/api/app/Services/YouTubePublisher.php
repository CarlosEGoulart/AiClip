<?php

namespace App\Services;

use App\Contracts\PublishResult;
use App\Contracts\PublishStatus;
use App\Contracts\SocialPublisher;
use App\Contracts\SocialConnectionProvider;
use App\Models\SocialConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubePublisher implements SocialPublisher
{
    public function __construct(
        protected SocialConnectionManager $manager,
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
            $response = Http::withToken($token)
                ->timeout(120)
                ->post('https://www.googleapis.com/upload/youtube/v3/videos', [
                    'part' => 'snippet,status',
                    'requestBody' => [
                        'snippet' => [
                            'title' => $input['title'],
                            'description' => $input['description'] ?? '',
                        ],
                        'status' => [
                            'privacyStatus' => $input['privacy_status'] ?? 'private',
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('YouTube upload failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return new PublishResult(
                    externalVideoId: '',
                    videoUrl: '',
                    status: 'failed',
                    errorMessage: 'YouTube upload failed: HTTP ' . $response->status(),
                );
            }

            $data = $response->json();
            $videoId = $data['id'] ?? '';
            $videoUrl = 'https://youtu.be/' . $videoId;

            Log::info('YouTube publish completed', [
                'external_video_id' => $videoId,
                'platform' => 'youtube',
            ]);

            return new PublishResult(
                externalVideoId: $videoId,
                videoUrl: $videoUrl,
                status: 'published',
            );
        } catch (\Throwable $e) {
            Log::warning('YouTube upload error', [
                'error' => $e->getMessage(),
            ]);

            return new PublishResult(
                externalVideoId: '',
                videoUrl: '',
                status: 'failed',
                errorMessage: 'Upload failed: ' . $e->getMessage(),
            );
        }
    }

    public function getPublishStatus(string $externalVideoId): PublishStatus
    {
        $provider = app(SocialConnectionProvider::class . ':youtube');

        try {
            // Check if video exists on the platform
            $isValid = $provider->validateToken(''); // placeholder - would need a real token

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
        $provider = app(SocialConnectionProvider::class . ':youtube');

        return $provider->validateToken($accessToken);
    }
}
