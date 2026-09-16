<?php

namespace App\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Services\ProcessMediaAction;
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
        protected ?ProcessMediaAction $processMediaAction = null,
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

        $action = $this->processMediaAction ?? app(ProcessMediaAction::class);

        // If already probed, reuse existing probe data; otherwise invoke probe
        if ($asset->processing_status === MediaAsset::PROCESSING_PROBED) {
            $probeData = $asset->probe_result ?? [];
            $durationMs = $asset->duration_ms ?? 0;

            Log::info('ProcessMediaAsset: already probed, skipping probe', [
                'media_asset_id' => $asset->id,
            ]);
        } else {
            // Mark as processing
            $asset->markProcessing();

            // Invoke the worker to probe media
            $result = $action->probe($contract);

            // Store probe result
            $probeData = $result['probe'] ?? [];
            $durationMs = $probeData['duration_ms'] ?? 0;

            $asset->markProbed($probeData, $durationMs);

            Log::info('ProcessMediaAsset: probe succeeded', [
                'media_asset_id' => $asset->id,
                'duration_ms' => $durationMs,
            ]);
        }

        // Check if audio stream exists in probe result
        $audioCodec = $probeData['audio_codec'] ?? null;

        if ($audioCodec === null) {
            // No audio stream, mark as completed
            $asset->markCompleted();
            Log::info('ProcessMediaAsset: no audio stream, marking completed', [
                'media_asset_id' => $asset->id,
            ]);

            return;
        }

        // Check for existing audio_normalized DerivedAsset (idempotency)
        $existingDerived = DerivedAsset::where('media_asset_id', $asset->id)
            ->where('type', DerivedAsset::TYPE_AUDIO_NORMALIZED)
            ->first();

        if ($existingDerived !== null) {
            // Already extracted, mark as completed
            $asset->markCompleted();
            Log::info('ProcessMediaAsset: audio already extracted, skipping', [
                'media_asset_id' => $asset->id,
            ]);

            return;
        }

        // Build extract_audio contract
        $extractContract = MediaProcessingContract::fromMediaAsset($asset, $this->idempotencyKey, 'extract_audio');
        $extractContract->outputStorage = [
            'disk' => $asset->storage_disk,
            'key' => $this->buildOutputKey($asset),
            'mime_type' => 'audio/wav',
        ];

        try {
            // Invoke extractAudio
            $extractResult = $action->extractAudio($extractContract);

            // Create DerivedAsset record
            $extraction = $extractResult['extraction'] ?? [];
            DerivedAsset::create([
                'media_asset_id' => $asset->id,
                'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
                'storage_disk' => $asset->storage_disk,
                'storage_key' => $extraction['output_path'] ?? '',
                'mime_type' => 'audio/wav',
                'size_bytes' => $extraction['output_size_bytes'] ?? 0,
                'duration_ms' => $extraction['duration_ms'] ?? null,
                'sample_rate' => $extraction['sample_rate'] ?? null,
                'channels' => $extraction['channels'] ?? null,
                'codec' => $extraction['codec'] ?? null,
            ]);

            // Mark as completed
            $asset->markCompleted();

            Log::info('ProcessMediaAsset: audio extraction succeeded', [
                'media_asset_id' => $asset->id,
            ]);
        } catch (\Throwable $e) {
            $asset->markFailed($e->getMessage());
            Log::error('ProcessMediaAsset: audio extraction failed', [
                'media_asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the output storage key for extracted audio.
     *
     * Format: projects/{project_id}/assets/{asset_id}/derivatives/audio/{hash}.wav
     * The hash is deterministic, based on the media_asset_id and normalization parameters.
     */
    private function buildOutputKey(MediaAsset $asset): string
    {
        $hash = hash('sha256', $asset->id.':mono:16000:pcm_s16le');

        return "projects/{$asset->project_id}/assets/{$asset->id}/derivatives/audio/{$hash}.wav";
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

        $error = $exception instanceof ProcessMediaException
            ? $exception->getMessage()
            : $exception->getMessage();

        $asset->markFailed($error);

        Log::error('ProcessMediaAsset: job failed', [
            'media_asset_id' => $asset->id,
            'error' => $error,
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
