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
use App\Models\MediaTranscript;
use App\Services\ProcessMediaAction;
use App\Services\RenderProfile;
use App\Services\RenderValidator;
use App\Services\StorageKeyBuilder;
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
            throw new InvalidInputException('invalid_input');
        }

        $recommendation = MediaClipRecommendation::find($this->recommendationId);

        if ($recommendation === null) {
            throw new UpstreamRecommendationMissingException('upstream_recommendation_missing');
        }

        // Verify ownership/project chain
        if ($recommendation->media_asset_id !== $asset->id) {
            throw new InvalidInputException('invalid_input');
        }

        // Verify M5 status - check specific statuses first
        if ($recommendation->status === MediaClipRecommendation::STATUS_FAILED) {
            throw new UpstreamRecommendationFailedException('upstream_recommendation_failed');
        }

        if ($recommendation->status === MediaClipRecommendation::STATUS_UNAVAILABLE) {
            throw new UpstreamRecommendationUnavailableException('upstream_recommendation_unavailable');
        }

        // Must be completed and ranked
        if ($recommendation->status !== MediaClipRecommendation::STATUS_COMPLETED
            || $recommendation->outcome !== MediaClipRecommendation::OUTCOME_RANKED) {
            throw new UpstreamRecommendationUnavailableException('upstream_recommendation_unavailable');
        }

        // Verify candidate_index is valid
        $recommendations = $recommendation->recommendations ?? [];
        if (! isset($recommendations[$this->candidateIndex])) {
            throw new InvalidCandidateIndexException('invalid_candidate_index');
        }

        $selectedCandidate = $recommendations[$this->candidateIndex];
        if ($selectedCandidate['semantic_score'] === null) {
            throw new InvalidCandidateIndexException('invalid_candidate_index');
        }

        // Verify source media probe exists and storage accessible
        $probeData = $asset->probe_result ?? [];
        $durationMs = $asset->duration_ms ?? 0;

        if (empty($probeData['video_codec']) || ($probeData['width'] ?? 0) <= 0 || ($probeData['height'] ?? 0) <= 0 || $durationMs <= 0) {
            throw new InvalidInputException('invalid_input');
        }

        // Load transcript for caption projection and authority snapshot
        $transcript = MediaTranscript::where('media_asset_id', $asset->id)
            ->where('status', MediaTranscript::STATUS_COMPLETED)
            ->first();

        $transcriptState = $transcript !== null ? 'completed' : 'absent';
        $transcriptContentHash = $transcript !== null && ! empty($transcript->segments)
            ? hash('sha256', json_encode($transcript->segments, JSON_THROW_ON_ERROR))
            : null;

        // Preflight: conflict with different render_profile_version for same media/type/candidate
        $conflictRender = DerivedAsset::where('media_asset_id', $asset->id)
            ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
            ->where('candidate_index', $this->candidateIndex)
            ->where('render_profile_version', '!=', RenderProfile::RENDER_PROFILE_VERSION)
            ->whereNotNull('render_profile_version')
            ->first();

        if ($conflictRender !== null) {
            throw new RenderVersionConflictException('render_version_conflict');
        }

        // Check existing DerivedAsset for idempotency (current profile version only)
        $existingRender = DerivedAsset::where('media_asset_id', $asset->id)
            ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
            ->where('candidate_index', $this->candidateIndex)
            ->where('render_profile_version', RenderProfile::RENDER_PROFILE_VERSION)
            ->first();

        if ($existingRender !== null) {
            // If completed, verify authority snapshot matches (including transcript)
            if ($existingRender->render_status === DerivedAsset::RENDER_STATUS_COMPLETED) {
                $existingSnapshot = $existingRender->render_parameters['input_snapshot'] ?? [];
                $existingTranscriptState = $existingSnapshot['transcript_state'] ?? 'absent';
                $existingTranscriptHash = $existingSnapshot['transcript_content_hash'] ?? null;

                if ($existingTranscriptState !== $transcriptState || $existingTranscriptHash !== $transcriptContentHash) {
                    throw new RenderVersionConflictException('render_version_conflict');
                }

                Log::info('RenderMediaClip: reusing existing completed render', [
                    'media_asset_id' => $asset->id,
                    'candidate_index' => $this->candidateIndex,
                    'derived_asset_id' => $existingRender->id,
                ]);

                return $existingRender;
            }

            // If failed, we allow retry by continuing to claim transaction
        }

        $outputStorageKey = $existingRender?->storage_key
            ?? StorageKeyBuilder::renderClip(
                $asset->project_id,
                $asset->id,
                $this->candidateIndex,
                RenderProfile::RENDER_PROFILE_VERSION,
            );

        $action = $this->processMediaAction ?? app(ProcessMediaAction::class);
        $renderConfiguration = RenderProfile::configuration();
        $renderTimeoutSeconds = RenderProfile::timeoutSeconds();
        $renderLockWaitSeconds = RenderProfile::lockWaitSeconds();
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

        // Atomic claim transaction
        try {
            DB::transaction(function () use (
                $asset,
                $recommendation,
                $action,
                $renderConfiguration,
                $renderLockWaitSeconds,
                $durationMs,
                $sourceMedia,
                $outputStorageKey,
            ) {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::select('SELECT set_config(\'lock_timeout\', ?, true)', [$renderLockWaitSeconds.'s']);
                }

                // Conflict-safe first insert
                DB::table('derived_assets')->insertOrIgnore([
                    'media_asset_id' => $asset->id,
                    'type' => DerivedAsset::TYPE_RENDERED_CLIP,
                    'candidate_index' => $this->candidateIndex,
                    'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
                    'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
                    'storage_disk' => $asset->storage_disk,
                    'storage_key' => $outputStorageKey,
                    'mime_type' => 'video/mp4',
                    'size_bytes' => 0,
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
                    // If failed, we continue to retry
                }

                if ($locked === null) {
                    return;
                }

                // Transition to rendering
                $locked->render_status = DerivedAsset::RENDER_STATUS_RENDERING;
                $locked->render_started_at = now();
                $locked->render_completed_at = null;
                $locked->render_error = null;
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
                    'transcript_state' => $transcriptState,
                    'transcript_content_hash' => $transcriptContentHash,
                ];

                try {
                    // Build render contract (singular format)
                    $renderContract = MediaProcessingContract::renderClipRequest(
                        $asset->id,
                        $durationMs,
                        $recommendation->toArray(),
                        $recommendation->id,
                        $this->candidateIndex,
                        $sourceMedia,
                        $asset->project_id
                    );

                    $renderContractObj = MediaProcessingContract::fromArray($renderContract);

                    // Invoke the worker
                    $result = $action->renderClips($renderContractObj);

                    // Validate result
                    $requestArray = $renderContractObj->toRenderClipMetadataArray();
                    $requestSha256 = hash('sha256', json_encode($requestArray, JSON_THROW_ON_ERROR));
                    RenderValidator::result($result, $requestArray, $requestSha256);

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

                    // Merge input_snapshot into render_parameters for idempotency verification
                    $renderParameters = $result['render']['parameters'];
                    $renderParameters['input_snapshot'] = $inputSnapshot;
                    $locked->render_parameters = $renderParameters;

                    $locked->render_error = null;
                    $locked->save();
                } catch (\Throwable $e) {
                    // Handle ProcessMediaException with specific logic
                    if ($e instanceof ProcessMediaException) {
                        if ($e->getMessage() === 'clip_render_aborted' && $e->getPrevious() === null) {
                            throw new RenderAbortedException;
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
                    }

                    // All other exceptions (including validation failures, JsonException, etc.)
                    // are treated as worker/validation failures - mark as failed and commit
                    $locked->render_status = DerivedAsset::RENDER_STATUS_FAILED;
                    $locked->render_completed_at = now();
                    $locked->render_error = 'render_failed';
                    $locked->save();
                }
            });
        } catch (\Throwable $exception) {
            if ($this->isLockTimeout($exception)) {
                throw new RenderBusyException;
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

            throw new RenderAbortedException;
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
