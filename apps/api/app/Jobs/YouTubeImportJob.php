<?php

namespace App\Jobs;

use App\Contracts\YouTubeImportProvider;
use App\Exceptions\InvalidInputException;
use App\Models\MediaAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queue job that imports a YouTube video for a queued MediaAsset row.
 *
 * Follows the GenerateImage topology: the queue job is the boundary that
 * invokes the provider seam (heavy download/processing never runs inside
 * HTTP request processes, AGENTS.md §20), Laravel strictly validates the
 * provider result, and owns PostgreSQL persistence inside a database
 * transaction. The job payload carries only the media asset id, URL,
 * owning user id, and idempotency key; ownership is re-validated from
 * persisted rows.
 */
class YouTubeImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retry.
     */
    public int $backoff = 5;

    /**
     * Maximum seconds the job may run before the worker stops it.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $mediaAssetId,
        public string $url,
        public string $idempotencyKey,
        public int $userId,
        protected ?YouTubeImportProvider $provider = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if ($asset === null) {
            throw new InvalidInputException('invalid_input');
        }

        // Re-validate ownership from persisted rows: the asset's project
        // must exist and be owned by the user who created the asset. On
        // mismatch, skip without any state change, storage write, or
        // provider call.
        $project = $asset->project()->first();

        if ($project === null) {
            Log::warning('YouTubeImportJob: media asset project no longer exists; skipping.', [
                'media_asset_id' => $asset->id,
            ]);

            return;
        }

        if ($project->user_id !== $this->userId) {
            Log::warning('YouTubeImportJob: media asset ownership mismatch; skipping.', [
                'media_asset_id' => $asset->id,
            ]);

            return;
        }

        // Idempotency: only queued rows are imported; terminal rows return early.
        if ($asset->processing_status !== MediaAsset::PROCESSING_QUEUED) {
            return;
        }

        $provider = $this->provider ?? app(YouTubeImportProvider::class);

        try {
            // Validate the URL before any metadata extraction. Any provider
            // failure (validation or extraction) is classified and sanitized.
            if (! $provider->validateUrl($this->url)) {
                $this->markFailed($asset, 'invalid_url');

                return;
            }

            $metadata = $provider->extractMetadata($this->url);
            $this->validateResult($metadata);
        } catch (\Throwable $exception) {
            // Classified provider/validation failure: sanitized reason only —
            // no provider internals and no raw URL content in log entries.
            Log::warning('YouTubeImportJob: import failed.', [
                'media_asset_id' => $asset->id,
                'reason' => $exception instanceof InvalidInputException ? 'invalid_result' : 'import_failed',
            ]);

            DB::transaction(function () use ($asset): void {
                $asset->status = MediaAsset::PROCESSING_FAILED;
                $asset->processing_status = MediaAsset::PROCESSING_FAILED;
                $asset->processing_error = 'import_failed';
                $asset->save();
            });

            return;
        }

        DB::transaction(function () use ($asset): void {
            $asset->status = MediaAsset::PROCESSING_COMPLETED;
            $asset->processing_status = MediaAsset::PROCESSING_COMPLETED;
            $asset->processing_completed_at = now();
            $asset->save();
        });
    }

    /**
     * Strict validation of the provider result before marking the asset
     * completed: video_id and title must be non-empty strings.
     */
    private function validateResult(array $result): void
    {
        $videoId = $result['video_id'] ?? null;
        $title = $result['title'] ?? null;

        if (! is_string($videoId) || $videoId === '') {
            throw new InvalidInputException('invalid_input');
        }

        if (! is_string($title) || $title === '') {
            throw new InvalidInputException('invalid_input');
        }
    }

    /**
     * Mark the asset as failed with a sanitized error.
     */
    private function markFailed(MediaAsset $asset, string $error): void
    {
        DB::transaction(function () use ($asset, $error): void {
            $asset->status = MediaAsset::PROCESSING_FAILED;
            $asset->processing_status = MediaAsset::PROCESSING_FAILED;
            $asset->processing_error = $error;
            $asset->save();
        });
    }

    /**
     * The unique ID of the job (used for idempotent dispatch).
     */
    public function uniqueId(): string
    {
        return "youtube-import:{$this->mediaAssetId}";
    }
}
