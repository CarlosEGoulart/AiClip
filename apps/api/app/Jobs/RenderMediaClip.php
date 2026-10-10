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

        // Fetch completed transcript for caption generation
        $transcript = MediaTranscript::where('media_asset_id', $asset->id)
            ->where('status', MediaTranscript::STATUS_COMPLETED)
            ->first();

        $clipStartMs = (int) $selectedCandidate['start_ms'];
        $clipEndMs = (int) $selectedCandidate['end_ms'];

        // Compute transcript hash for idempotency check
        $transcriptHash = $this->computeTranscriptHash($transcript, $clipStartMs, $clipEndMs);

        // Validate transcript segments early (before transaction) to avoid rollback on invalid_input
        $transcriptSegments = $transcript?->segments;
        if ($transcriptSegments !== null && ! empty($transcriptSegments)) {
            foreach ($transcriptSegments as $segment) {
                if (! isset($segment['start_ms'], $segment['end_ms'], $segment['text'])) {
                    throw new InvalidInputException('invalid_input');
                }
                if (! is_int($segment['start_ms']) || ! is_int($segment['end_ms']) || ! is_string($segment['text'])) {
                    throw new InvalidInputException('invalid_input');
                }
            }
        }

        // Verify source media probe exists and storage accessible
        $probeData = $asset->probe_result ?? [];
        $durationMs = $asset->duration_ms ?? 0;

        if (empty($probeData['video_codec']) || ($probeData['width'] ?? 0) <= 0 || ($probeData['height'] ?? 0) <= 0 || $durationMs <= 0) {
            throw new InvalidInputException('invalid_input');
        }

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

        // Check existing DerivedAsset for idempotency (includes transcript_hash)
        $existingRender = DerivedAsset::where('media_asset_id', $asset->id)
            ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
            ->where('candidate_index', $this->candidateIndex)
            ->where('render_profile_version', RenderProfile::RENDER_PROFILE_VERSION)
            ->where(function ($query) use ($transcriptHash) {
                if ($transcriptHash !== null) {
                    $query->where('transcript_hash', $transcriptHash);
                } else {
                    $query->whereNull('transcript_hash');
                }
            })
            ->first();

        if ($existingRender !== null) {
            // If completed, reuse (idempotent) - unique constraint ensures same profile version
            if ($existingRender->render_status === DerivedAsset::RENDER_STATUS_COMPLETED) {
                Log::info('RenderMediaClip: reusing existing completed render', [
                    'media_asset_id' => $asset->id,
                    'candidate_index' => $this->candidateIndex,
                    'derived_asset_id' => $existingRender->id,
                    'transcript_hash' => $transcriptHash,
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
                $transcript,
                $clipStartMs,
                $clipEndMs,
                $executionParameters,
                $transcriptHash,
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
                    'transcript_hash' => $transcriptHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $locked = DerivedAsset::where('media_asset_id', $asset->id)
                    ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
                    ->where('render_profile_version', RenderProfile::RENDER_PROFILE_VERSION)
                    ->where('candidate_index', $this->candidateIndex)
                    ->where(function ($query) use ($transcriptHash) {
                        if ($transcriptHash !== null) {
                            $query->where('transcript_hash', $transcriptHash);
                        } else {
                            $query->whereNull('transcript_hash');
                        }
                    })
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
                        $asset->project_id,
                        $transcript?->segments,        // transcriptSegments
                        $clipStartMs,                  // clipStartMs
                        $clipEndMs,                    // clipEndMs
                        $asset->storage_disk           // disk
                    );

                    $renderContractObj = MediaProcessingContract::fromArray($renderContract);

                    // Invoke the worker
                    $result = $action->renderClips($renderContractObj);

                    // Worker boundary validation - validate result immediately after worker returns
                    $requestMetadata = $renderContractObj->toRenderClipMetadataArray();
                    $requestSha256 = hash('sha256', json_encode($requestMetadata, JSON_THROW_ON_ERROR));

                    RenderValidator::result($result, $requestMetadata, $requestSha256);

                    // Model boundary validation - build completion payload from result
                    $completionPayload = [
                        'algorithm' => $result['render']['algorithm'],
                        'algorithm_version' => $result['render']['algorithm_version'],
                        'parameters' => $result['render']['parameters'],
                        'clips' => $result['render']['clips'],
                        'execution_parameters' => $executionParameters,
                    ];

                    RenderValidator::validateCompletion($completionPayload, $renderConfiguration);

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
                    $locked->transcript_hash = $transcriptHash;
                    $locked->save();
                } catch (\Throwable $e) {
                    // Wrap non-ProcessMediaException to ensure failed render is persisted
                    if (! $e instanceof ProcessMediaException) {
                        $e = new ProcessMediaException('render_failed', 1, $e);
                    }

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

                    // Expected worker/validation failure: sanitized failed render only
                    $locked->render_status = DerivedAsset::RENDER_STATUS_FAILED;
                    $locked->render_completed_at = now();

                    // Distinguish validation failure from render failure
                    if ($e instanceof ProcessMediaException && $e->getMessage() === 'Render validation failed') {
                        $locked->render_error = 'validation_failed';
                    } else {
                        $locked->render_error = 'render_failed';
                    }
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
            ->where(function ($query) use ($transcriptHash) {
                if ($transcriptHash !== null) {
                    $query->where('transcript_hash', $transcriptHash);
                } else {
                    $query->whereNull('transcript_hash');
                }
            })
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
     * Compute SHA-256 hash of transcript segments that project to the clip range.
     * Returns null if no transcript, no segments, or no in-range segments.
     */
    private function computeTranscriptHash(?MediaTranscript $transcript, int $clipStartMs, int $clipEndMs): ?string
    {
        if ($transcript === null) {
            return null;
        }

        $segments = $transcript->segments ?? [];
        if (empty($segments)) {
            return null;
        }

        // Filter segments that intersect with clip range
        $inRangeSegments = array_filter($segments, function (array $segment) use ($clipStartMs, $clipEndMs): bool {
            $segStart = $segment['start_ms'] ?? 0;
            $segEnd = $segment['end_ms'] ?? 0;

            return $segStart < $clipEndMs && $segEnd > $clipStartMs;
        });

        if (empty($inRangeSegments)) {
            return null;
        }

        // Normalize: sort by start_ms, then encode minimal content for hash
        $normalized = array_values(array_map(function (array $segment): array {
            return [
                's' => (int) ($segment['start_ms'] ?? 0),
                'e' => (int) ($segment['end_ms'] ?? 0),
                't' => (string) ($segment['text'] ?? ''),
            ];
        }, $inRangeSegments));

        usort($normalized, fn (array $a, array $b) => $a['s'] <=> $b['s']);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * The unique ID of the job (used for idempotent dispatch).
     */
    public function uniqueId(): string
    {
        return "render-media-clip:{$this->mediaAssetId}:{$this->recommendationId}:{$this->candidateIndex}";
    }
}
