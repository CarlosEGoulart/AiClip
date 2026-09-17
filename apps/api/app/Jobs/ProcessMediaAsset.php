<?php

namespace App\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaTranscript;
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

        if ($existingDerived === null) {
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
                $existingDerived = DerivedAsset::create([
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

                Log::info('ProcessMediaAsset: audio extraction succeeded', [
                    'media_asset_id' => $asset->id,
                ]);
            } catch (\Throwable $e) {
                $asset->markFailed($e->getMessage());
                Log::error('ProcessMediaAsset: audio extraction failed', [
                    'media_asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);

                return;
            }
        } else {
            Log::info('ProcessMediaAsset: audio already extracted, skipping', [
                'media_asset_id' => $asset->id,
            ]);
        }

        // --- Transcription Stage ---
        // Check for existing MediaTranscript (idempotency)
        $existingTranscript = MediaTranscript::where('media_asset_id', $asset->id)->first();

        if ($existingTranscript !== null && $existingTranscript->status === MediaTranscript::STATUS_COMPLETED) {
            // Already transcribed, mark as completed and return
            $asset->markCompleted();
            Log::info('ProcessMediaAsset: transcription already completed, skipping', [
                'media_asset_id' => $asset->id,
            ]);

            return;
        }

        // Create or update MediaTranscript
        if ($existingTranscript === null) {
            $transcript = MediaTranscript::create([
                'media_asset_id' => $asset->id,
                'derived_asset_id' => $existingDerived->id,
                'status' => MediaTranscript::STATUS_PENDING,
            ]);
        } else {
            $transcript = $existingTranscript;
        }

        // Build transcribe contract
        $transcribeContract = MediaProcessingContract::fromMediaAsset($asset, $this->idempotencyKey, 'transcribe');
        $transcribeContract->derivedAssetId = $existingDerived->id;
        $transcribeContract->storage = [
            'disk' => $existingDerived->storage_disk,
            'key' => $existingDerived->storage_key,
            'mime_type' => $existingDerived->mime_type,
        ];

        // Mark transcript as transcribing
        $transcript->markTranscribing();

        try {
            // Invoke transcribe
            $transcribeResult = $action->transcribe($transcribeContract);

            // Extract transcription data
            $transcription = $transcribeResult['transcription'] ?? [];

            // Mark transcript as completed
            $transcript->markCompleted(
                $transcription['language'] ?? 'en',
                $transcription['full_text'] ?? '',
                $transcription['segments'] ?? [],
                $transcription['engine'] ?? 'unknown',
                $transcription['model'] ?? 'unknown',
            );

            // Mark asset as completed
            $asset->markCompleted();

            Log::info('ProcessMediaAsset: transcription succeeded', [
                'media_asset_id' => $asset->id,
            ]);
        } catch (\Throwable $e) {
            // Mark transcript as failed
            $transcript->markFailed($e->getMessage());

            // Mark asset as completed (audio extraction succeeded, transcription failed)
            $asset->markCompleted();

            Log::error('ProcessMediaAsset: transcription failed', [
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
