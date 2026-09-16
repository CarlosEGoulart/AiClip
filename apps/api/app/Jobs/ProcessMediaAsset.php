<?php

namespace App\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Models\MediaAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMediaAsset implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public MediaAsset $mediaAsset,
        public string $idempotencyKey,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Reload the media asset to check current state
        $asset = $this->mediaAsset->fresh();

        if ($asset === null) {
            Log::warning('ProcessMediaAsset: media asset no longer exists', [
                'media_asset_id' => $this->mediaAsset->id,
            ]);

            return;
        }

        // Validate idempotency key matches
        if ($asset->idempotency_key !== null && $asset->idempotency_key !== $this->idempotencyKey) {
            Log::warning('ProcessMediaAsset: idempotency key mismatch', [
                'media_asset_id' => $asset->id,
                'expected' => $asset->idempotency_key,
                'received' => $this->idempotencyKey,
            ]);

            return;
        }

        // Mark as queued (idempotent — if already queued, this is a no-op)
        $asset->markQueued($this->idempotencyKey);

        // Build the worker contract
        $contract = MediaProcessingContract::fromMediaAsset($asset, $this->idempotencyKey);

        // Log the contract (placeholder for future worker dispatch)
        Log::info('ProcessMediaAsset: dispatching to worker', [
            'media_asset_id' => $asset->id,
            'contract' => $contract->toArray(),
        ]);

        // Mark as processing
        $asset->markProcessing();

        // Placeholder: real processing logic will be implemented in future slices
        // For now, mark as completed immediately
        $asset->markCompleted();
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = $this->mediaAsset->fresh();

        if ($asset === null) {
            return;
        }

        $asset->markFailed($exception->getMessage());

        Log::error('ProcessMediaAsset: job failed', [
            'media_asset_id' => $asset->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * The unique ID of the job (used for idempotent dispatch).
     */
    public function uniqueId(): string
    {
        return $this->idempotencyKey;
    }
}
