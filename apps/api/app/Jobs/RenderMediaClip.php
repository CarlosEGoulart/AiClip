<?php

namespace App\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\InvalidCandidateIndexException;
use App\Exceptions\InvalidInputException;
use App\Exceptions\ProcessMediaException;
use App\Exceptions\RenderAbortedException;
use App\Exceptions\RenderBusyException;
use App\Exceptions\RenderFailedException;
use App\Exceptions\RenderVersionConflictException;
use App\Exceptions\UpstreamRecommendationFailedException;
use App\Exceptions\UpstreamRecommendationMissingException;
use App\Exceptions\UpstreamRecommendationUnavailableException;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipRecommendation;
use App\Services\ProcessMediaAction;
use App\Services\RenderProfile;
use App\Services\RenderValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RenderMediaClip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying.
     */
    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $mediaAssetId,
        public int $recommendationId,
        public int $candidateIndex,
        protected ?ProcessMediaAction $processMediaAction = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): DerivedAsset
    {
        $asset = MediaAsset::find($this->mediaAssetId);

        if ($asset === null) {
            throw new InvalidInputException('MediaAsset not found');
        }

        $recommendation = MediaClipRecommendation::find($this->recommendationId);

        if ($recommendation === null) {
            throw new UpstreamRecommendationMissingException('MediaClipRecommendation not found');
        }

        // Verify ownership/project chain
        if ($recommendation->media_asset_id !== $asset->id) {
            throw new InvalidInputException('Recommendation does not belong to this asset');
        }

        // Verify M5 status
        if (! $recommendation->isTerminal()) {
            throw new UpstreamRecommendationUnavailableException('M5 recommendation not ready');
        }

        if ($recommendation->status === MediaClipRecommendation::STATUS_FAILED) {
            throw new UpstreamRecommendationFailedException('M5 recommendation failed');
        }

        if ($recommendation->status === MediaClipRecommendation::STATUS_UNAVAILABLE) {
            throw new UpstreamRecommendationUnavailableException('M5 recommendation unavailable');
        }

        // Must be completed and ranked
        if ($recommendation->status !== MediaClipRecommendation::STATUS_COMPLETED
            || $recommendation->outcome !== MediaClipRecommendation::OUTCOME_RANKED) {
            throw new UpstreamRecommendationUnavailableException('M5 recommendation not ranked');
        }

        // Verify candidate_index is valid
        $recommendations = $recommendation->recommendations ?? [];
        if (! isset($recommendations[$this->candidateIndex])) {
            throw new InvalidCandidateIndexException('candidate_index out of bounds');
        }

        $selectedCandidate = $recommendations[$this->candidateIndex];
        if ($selectedCandidate['semantic_score'] === null) {
            throw new InvalidCandidateIndexException('selected candidate has null semantic_score');
        }

        // Verify source media probe exists and storage accessible
        $probeData = $asset->probe_result ?? [];
        $durationMs = $asset->duration_ms ?? 0;

        if (empty($probeData['video_codec']) || ($probeData['width'] ?? 0) <= 0 || ($probeData['height'] ?? 0) <= 0 || $durationMs <= 0) {
            throw new InvalidInputException('Invalid or missing probe data');
        }

        // Check existing DerivedAsset for idempotency (Corrected: uses render_profile_version = 'vertical_v1')
        $existingRender = DerivedAsset::where('media_asset_id', $asset->id)
            ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
            ->where('candidate_index', $this->candidateIndex)
            ->where('render_profile_version', RenderProfile::RENDER_PROFILE_VERSION)
            ->first();

        if ($existingRender !== null) {
            // If completed, reuse (idempotent) - unique constraint ensures same profile version
            if ($existingRender->render_status === DerivedAsset::RENDER_STATUS_COMPLETED) {
                Log::info('RenderMediaClip: reusing existing completed render', [
                    'media_asset_id' => $asset->id,
                    'candidate_index' => $this->candidateIndex,
                    'derived_asset_id' => $existingRender->id,
                ]);
                return $existingRender;
            }

            // If failed, we allow retry by continuing to claim transaction
            // But check for version conflict first
            if ($existingRender->render_status === DerivedAsset::RENDER_STATUS_FAILED) {
                // Check if M5 authority differs (version conflict)
                if ($this->hasVersionConflict($existingRender, $recommendation)) {
                    throw new RenderVersionConflictException('M5 recommendation authority differs from existing render');
                }
            }
        }

        $action = $this->processMediaAction ?? app(ProcessMediaAction::class);
        $renderConfiguration = RenderProfile::configuration();
        $renderTimeoutSeconds = RenderProfile::timeoutSeconds();
        $renderLockWaitSeconds = RenderProfile::lockWaitSeconds();  // timeout + 10s
        $executionParameters = [
            'timeout_seconds' => $renderTimeoutSeconds,
            'lock_wait_seconds' => $renderLockWaitSeconds,
        ];

        // Build source media info from probe
        $sourceMedia = [
            'disk' => $asset->storage_disk,
            'key' => $asset->storage_key,
            'width' => $probeData['width'] ?? 0,
            'height' => $probeData['height'] ?? 0,
            'video_codec' => $probeData['video_codec'] ?? '',
            'audio_codec' => $probeData['audio_codec'] ?? null,
        ];

        // Precompute output key (Corrected: Laravel precomputes, not worker)
        $projectId = $asset->project_id;
        $timestamp = now()->utc()->format('Ymd\THis\Z');
        $outputKey = "projects/{$projectId}/renders/{$asset->id}/{$this->candidateIndex}_{$timestamp}.mp4";

        // Atomic claim transaction
        try {
            DB::transaction(function () use (
                $asset,
                $recommendation,
                $action,
                $renderConfiguration,
                $executionParameters,
                $renderLockWaitSeconds,
                $durationMs,
                $sourceMedia,
                $outputKey,
            ) {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::select('SELECT set_config(\'lock_timeout\', ?, true)', [$renderLockWaitSeconds.'s']);
                }

                // Conflict-safe first insert with storage_disk and storage_key (NOT NULL constraints)
                DB::table('derived_assets')->insertOrIgnore([
                    'media_asset_id' => $asset->id,
                    'type' => DerivedAsset::TYPE_RENDERED_CLIP,
                    'candidate_index' => $this->candidateIndex,
                    'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
                    'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
                    'storage_disk' => $asset->storage_disk,
                    'storage_key' => $outputKey,
                    'mime_type' => 'video/mp4',
                    'size_bytes' => 0,
                    'duration_ms' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $locked = DerivedAsset::where('media_asset_id', $asset->id)
                    ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
                    ->where('render_profile_version', RenderProfile::RENDER_PROFILE_VERSION)
                    ->where('candidate_index', $this->candidateIndex)
                    ->lockForUpdate()
                    ->first();

                // Guard: if a terminal row already exists, preserve it
                if ($locked !== null && in_array($locked->render_status, [DerivedAsset::RENDER_STATUS_COMPLETED, DerivedAsset::RENDER_STATUS_FAILED], true)) {
                    if ($locked->render_status === DerivedAsset::RENDER_STATUS_COMPLETED) {
                        // This will be handled by the outer logic returning the existing render
                        return;
                    }
                    // If failed, check version conflict before retry
                    if ($this->hasVersionConflict($locked, $recommendation)) {
                        throw new RenderVersionConflictException('M5 recommendation authority differs from existing render');
                    }
                    // If failed, we continue to retry
                }

                if ($locked === null) {
                    return;
                }

                // Transition to rendering
                $locked->render_status = DerivedAsset::RENDER_STATUS_RENDERING;
                $locked->render_error = null;
                $locked->render_started_at = now();
                $locked->save();

                // Capture input snapshot
                $inputSnapshot = [
                    'duration_ms' => $durationMs,
                    'recommendation' => [
                        'candidates' => $recommendation->recommendations ?? [],
                        'candidate_index' => $this->candidateIndex,
                    ],
                    'configuration' => $renderConfiguration,
                    'source_media' => $sourceMedia,
                ];

                try {
                    // Build render contract (Corrected: NO database identifiers, precomputed output_key)
                    $renderContract = MediaProcessingContract::renderClipRequest(
                        $asset->id,
                        $durationMs,
                        $recommendation->toArray(),
                        $this->candidateIndex,
                        $sourceMedia,
                        $outputKey
                    );

                    $renderContractObj = MediaProcessingContract::fromArray($renderContract);

                    // Invoke the worker (singular action)
                    $result = $action->renderClip($renderContractObj);

                    // Validate result
                    $requestSha256 = hash('sha256', json_encode($renderContract));
                    RenderValidator::validateCompletion([
                        'algorithm' => $result['render']['algorithm'],
                        'algorithm_version' => $result['render']['algorithm_version'],
                        'parameters' => $result['render']['parameters'],
                        'clips' => $result['render']['clips'],
                        'execution_parameters' => $executionParameters,
                    ]);

                    // Mark completed
                    $clip = $result['render']['clips'][0];
                    $locked->render_status = DerivedAsset::RENDER_STATUS_COMPLETED;
                    $locked->render_completed_at = now();
                    $locked->storage_disk = $clip['output']['disk'];
                    $locked->storage_key = $clip['output']['key'];
                    $locked->mime_type = 'video/mp4';
                    $locked->size_bytes = $clip['output']['size_bytes'];
                    $locked->duration_ms = $clip['output']['duration_ms'];
                    $locked->width = $clip['output']['width'];
                    $locked->height = $clip['output']['height'];
                    $locked->codec = $clip['output']['video_codec'];
                    $locked->candidate_index = $clip['candidate_index'];
                    $locked->render_profile_version = RenderProfile::RENDER_PROFILE_VERSION;
                    $locked->render_configuration = $renderConfiguration;
                    $locked->render_parameters = $result['render']['parameters'];
                    $locked->render_error = null;
                    $locked->save();
                } catch (ProcessMediaException $e) {
                    if ($e->getMessage() === 'clip_render_aborted' && $e->getPrevious() === null) {
                        throw new RenderAbortedException();
                    }

                    if ($e->getMessage() === 'invalid_configuration') {
                        throw $e;
                    }

                    if ($e->getMessage() === 'invalid_input') {
                        $locked->render_status = DerivedAsset::RENDER_STATUS_FAILED;
                        $locked->render_completed_at = now();
                        $locked->render_error = 'invalid_input';
                        $locked->save();

                        return;
                    }

                    // Expected worker/validation failure: sanitized failed render only
                    $locked->render_status = DerivedAsset::RENDER_STATUS_FAILED;
                    $locked->render_completed_at = now();
                    $locked->render_error = 'render_failed';
                    $locked->save();
                }
            });
        } catch (\Throwable $exception) {
            if ($this->isLockTimeout($exception)) {
                throw new RenderBusyException();
            }

            if ($exception instanceof ProcessMediaException
                && in_array($exception->getMessage(), ['clip_render_aborted', 'invalid_configuration'], true)
                && $exception->getPrevious() === null) {
                throw $exception;
            }

            if ($exception instanceof RenderAbortedException) {
                throw $exception;
            }

            if ($exception instanceof RenderBusyException) {
                throw $exception;
            }

            Log::error('RenderMediaClip: render aborted without resolution', [
                'media_asset_id' => $asset->id,
            ]);

            throw new RenderAbortedException();
        }

        // Fresh reread after transaction
        $completedRender = DerivedAsset::where('media_asset_id', $asset->id)
            ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
            ->where('render_profile_version', RenderProfile::RENDER_PROFILE_VERSION)
            ->where('candidate_index', $this->candidateIndex)
            ->first();

        if ($completedRender === null) {
            throw new RenderFailedException('Render completed but DerivedAsset not found');
        }

        if ($completedRender->render_status === DerivedAsset::RENDER_STATUS_FAILED) {
            throw new RenderFailedException('Render failed: '.$completedRender->render_error);
        }

        return $completedRender;
    }

    /**
     * Check if the existing render has a version conflict with current M5 authority.
     */
    private function hasVersionConflict(DerivedAsset $existingRender, MediaClipRecommendation $recommendation): bool
    {
        // Compare M5 authority: recommendation ID, candidate bounds, configuration
        // If any differs, it's a version conflict
        $existingParams = $existingRender->render_parameters ?? [];
        $existingConfig = $existingParams['configuration'] ?? [];
        $currentConfig = RenderProfile::configuration();

        if ($existingConfig !== $currentConfig) {
            return true;
        }

        // Could also check recommendation ID, but spec says recommendation_id NOT in unique key
        // Version conflict is about different M5 authority bounds or configuration
        return false;
    }

    /**
     * Whether the throwable chain carries a PostgreSQL lock timeout (55P03).
     */
    private function isLockTimeout(\Throwable $exception): bool
    {
        $current = $exception;
        while ($current !== null) {
            if ($current instanceof QueryException && (string) $current->getCode() === '55P03') {
                return true;
            }
            $current = $current->getPrevious();
        }

        return false;
    }

    /**
     * The unique ID of the job (used for idempotent dispatch).
     */
    public function uniqueId(): string
    {
        return "render-media-clip:{$this->mediaAssetId}:{$this->recommendationId}:{$this->candidateIndex}";
    }
}