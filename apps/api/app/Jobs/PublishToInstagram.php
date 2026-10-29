<?php

namespace App\Jobs;

use App\Contracts\SocialPublisher;
use App\Enums\SocialPublishStatus;
use App\Models\SocialPublish;
use App\Services\SocialConnectionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PublishToInstagram implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $publishId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $publish = SocialPublish::with(['user', 'socialConnection', 'clipReview.derivedAsset'])
            ->find($this->publishId);

        if ($publish === null) {
            Log::warning('PublishToInstagram: Publish not found', [
                'publish_id' => $this->publishId,
            ]);

            return;
        }

        // Idempotency: skip if not pending
        if ($publish->status !== SocialPublishStatus::Pending) {
            Log::info('PublishToInstagram: Publish already processed, skipping', [
                'publish_id' => $this->publishId,
                'status' => $publish->status,
            ]);

            return;
        }

        // Ownership re-validation: user must own clip review and connection
        if ($publish->user_id !== $publish->socialConnection->user_id) {
            Log::warning('PublishToInstagram: Ownership validation failed', [
                'publish_id' => $this->publishId,
                'publish_user_id' => $publish->user_id,
                'connection_user_id' => $publish->socialConnection->user_id,
            ]);

            $this->markFailed($publish, 'Ownership validation failed');

            return;
        }

        if ($publish->socialConnection->platform !== 'instagram') {
            Log::warning('PublishToInstagram: Connection is not Instagram', [
                'publish_id' => $this->publishId,
                'platform' => $publish->socialConnection->platform,
            ]);

            $this->markFailed($publish, 'Connection is not an Instagram connection');

            return;
        }

        // Transition to uploading
        DB::transaction(function () use ($publish) {
            $publish->update(['status' => SocialPublishStatus::Uploading->value]);
        });

        // Get valid access token (refreshes if needed)
        $token = app(SocialConnectionManager::class)
            ->getValidAccessToken($publish->socialConnection);

        if (! $token) {
            $this->markFailed($publish, 'No valid access token available');

            return;
        }

        // Get rendered clip local path
        $derivedAsset = $publish->clipReview->derivedAsset;
        if (! $derivedAsset || $derivedAsset->render_status !== 'completed') {
            $this->markFailed($publish, 'No completed rendered clip found');

            return;
        }

        // Resolve publisher and publish
        try {
            $publisher = app(SocialPublisher::class.':instagram');

            $localPath = Storage::disk($derivedAsset->storage_disk)->path($derivedAsset->storage_key);

            $result = $publisher->publishVideo([
                'connection' => $publish->socialConnection,
                'local_path' => $localPath,
                'title' => $publish->title,
                'description' => $publish->description,
            ]);

            if ($result->status === 'failed') {
                $this->markFailed($publish, $result->errorMessage ?? 'Publish failed');

                return;
            }

            DB::transaction(function () use ($publish, $result) {
                $publish->update([
                    'status' => SocialPublishStatus::Published->value,
                    'external_video_id' => $result->externalVideoId,
                    'video_url' => $result->videoUrl,
                    'error_message' => null,
                ]);
            });

            Log::info('PublishToInstagram: Publish completed', [
                'publish_id' => $this->publishId,
                'external_video_id' => $result->externalVideoId,
                'platform' => 'instagram',
            ]);
        } catch (\Throwable $e) {
            $this->markFailed($publish, 'Publish failed: '.$e->getMessage());
        }
    }

    /**
     * Mark the publish as failed with a sanitized error message (no tokens).
     */
    protected function markFailed(SocialPublish $publish, string $errorMessage): void
    {
        DB::transaction(function () use ($publish, $errorMessage) {
            $publish->update([
                'status' => SocialPublishStatus::Failed->value,
                'error_message' => $errorMessage,
            ]);
        });

        Log::warning('PublishToInstagram: Publish failed', [
            'publish_id' => $this->publishId,
            'error' => $errorMessage,
        ]);
    }

    /**
     * The unique ID of the job (used for idempotent dispatch).
     */
    public function uniqueId(): string
    {
        return "social-publish:{$this->publishId}";
    }
}
