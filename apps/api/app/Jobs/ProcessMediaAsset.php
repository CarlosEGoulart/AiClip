<?php

namespace App\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaClipRecommendation;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ClipRankingProfile;
use App\Services\ClipRecommendationProjection;
use App\Services\ClipRecommendationReadiness;
use App\Services\ClipRecommendationValidator;
use App\Services\ProcessMediaAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMediaAsset implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying not-ready upstream stages.
     */
    public int $backoff = 5;

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

        // =====================================================================
        // Scene Detection Stage (independent lifecycle)
        // Runs when video_codec key exists in probe result (even if null)
        // =====================================================================
        $sceneDetectionResolved = false;
        $sceneAnalysis = null;

        if (array_key_exists('video_codec', $probeData)) {
            $existingSceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();

            if ($existingSceneAnalysis !== null && $existingSceneAnalysis->status === MediaSceneAnalysis::STATUS_COMPLETED) {
                // Idempotent: skip scene detection
                $sceneDetectionResolved = true;
                $sceneAnalysis = $existingSceneAnalysis;

                Log::info('ProcessMediaAsset: scene detection already completed, skipping', [
                    'media_asset_id' => $asset->id,
                ]);
            } elseif ($existingSceneAnalysis !== null && in_array($existingSceneAnalysis->status, [
                MediaSceneAnalysis::STATUS_PENDING,
                MediaSceneAnalysis::STATUS_DETECTING,
            ], true)) {
                // Scene detection in progress — do not re-run, leave unresolved for clip analysis to handle
                $sceneAnalysis = $existingSceneAnalysis;
                $sceneDetectionResolved = false;

                Log::info('ProcessMediaAsset: scene detection in progress, deferring to clip analysis', [
                    'media_asset_id' => $asset->id,
                    'scene_status' => $existingSceneAnalysis->status,
                ]);
            } else {
                // Create or retry scene analysis (null or FAILED)
                if ($existingSceneAnalysis === null) {
                    $sceneAnalysis = MediaSceneAnalysis::create([
                        'media_asset_id' => $asset->id,
                        'status' => MediaSceneAnalysis::STATUS_PENDING,
                    ]);
                } else {
                    $sceneAnalysis = $existingSceneAnalysis;
                }

                $sceneAnalysis->markDetecting();

                $sceneContract = MediaProcessingContract::fromMediaAsset($asset, $this->idempotencyKey, 'detect_scenes');
                $sceneContract->durationMs = $durationMs;

                try {
                    $sceneResult = $action->detectScenes($sceneContract);

                    $sceneDetection = $sceneResult['scene_detection'] ?? null;

                    if (! is_array($sceneDetection)) {
                        throw new ProcessMediaException(
                            'Worker returned success but scene_detection data is missing or malformed',
                            1,
                            json_encode($sceneResult),
                        );
                    }

                    // Validate required fields explicitly - no fallback defaults
                    $detector = $sceneDetection['detector'] ?? null;
                    $detectorVersion = $sceneDetection['detector_version'] ?? null;
                    $parameters = $sceneDetection['parameters'] ?? null;
                    $scenes = $sceneDetection['scenes'] ?? null;

                    if (! is_string($detector) || $detector === '') {
                        throw new ProcessMediaException(
                            'Worker returned success but detector is missing or empty',
                            1,
                            json_encode($sceneResult),
                        );
                    }
                    if (! is_string($detectorVersion) || $detectorVersion === '') {
                        throw new ProcessMediaException(
                            'Worker returned success but detector_version is missing or empty',
                            1,
                            json_encode($sceneResult),
                        );
                    }
                    if (! is_array($parameters)) {
                        throw new ProcessMediaException(
                            'Worker returned success but parameters is not an array',
                            1,
                            json_encode($sceneResult),
                        );
                    }
                    if (! is_array($scenes)) {
                        throw new ProcessMediaException(
                            'Worker returned success but scenes is not an array',
                            1,
                            json_encode($sceneResult),
                        );
                    }

                    // Validate scene format
                    MediaSceneAnalysis::validateScenes($scenes, $durationMs);

                    $sceneAnalysis->markCompleted(
                        $detector,
                        $detectorVersion,
                        $parameters,
                        $scenes,
                        $durationMs,
                    );

                    $sceneDetectionResolved = true;

                    Log::info('ProcessMediaAsset: scene detection succeeded', [
                        'media_asset_id' => $asset->id,
                        'scenes_count' => count($scenes),
                    ]);
                } catch (\Throwable $e) {
                    $sceneAnalysis->markFailed($e->getMessage());
                    $sceneDetectionResolved = true;

                    Log::error('ProcessMediaAsset: scene detection failed', [
                        'media_asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } else {
            // No video_codec key — scene detection not applicable
            $sceneDetectionResolved = true;
        }

        // =====================================================================
        // Audio Path (independent lifecycle, only if audio available)
        // =====================================================================
        $audioCodec = $probeData['audio_codec'] ?? null;
        $audioPathResolved = false;

        // Authoritative audio extraction outcome. A generic asset failure
        // caused by another stage never sets this: M5 must not read it as an
        // extraction failure.
        $audioExtractionFailed = false;
        $transcriptionInFlight = false;

        if ($audioCodec === null) {
            // No audio stream, skip audio path entirely
            $audioPathResolved = true;

            Log::info('ProcessMediaAsset: no audio stream, skipping audio path', [
                'media_asset_id' => $asset->id,
            ]);
        } else {
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
                    // Audio extraction failed - mark asset as failed but continue to clip analysis
                    // for scene-only assets (spec: removal of early return "only as needed to resolve clips")
                    $asset->markFailed($e->getMessage());
                    $audioPathResolved = true; // Audio path is resolved (as failed)
                    $audioExtractionFailed = true;

                    Log::error('ProcessMediaAsset: audio extraction failed, continuing to clip analysis', [
                        'media_asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);

                    // Do NOT return here - continue to clip analysis stage for scene-only assets
                }
            } else {
                Log::info('ProcessMediaAsset: audio already extracted, skipping', [
                    'media_asset_id' => $asset->id,
                ]);
            }

            // If audio extraction failed, no DerivedAsset exists - skip transcription
            if ($asset->processing_status === MediaAsset::PROCESSING_FAILED) {
                $audioPathResolved = true;

                Log::info('ProcessMediaAsset: audio extraction failed, skipping transcription', [
                    'media_asset_id' => $asset->id,
                ]);
            } else {
                // --- Transcription Stage ---
                // Check for existing MediaTranscript (idempotency)
                $existingTranscript = MediaTranscript::where('media_asset_id', $asset->id)->first();

                if ($existingTranscript !== null && $existingTranscript->status === MediaTranscript::STATUS_COMPLETED) {
                    // Already transcribed, skip
                    $audioPathResolved = true;

                    Log::info('ProcessMediaAsset: transcription already completed, skipping', [
                        'media_asset_id' => $asset->id,
                    ]);
                } else {
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
                    $transcriptionInFlight = true;

                    try {
                        // Invoke transcribe
                        $transcribeResult = $action->transcribe($transcribeContract);

                        // Extract and validate transcription data
                        $transcription = $transcribeResult['transcription'] ?? null;

                        if (! is_array($transcription)) {
                            throw new ProcessMediaException(
                                'Worker returned success but transcription data is missing or malformed',
                                1,
                                json_encode($transcribeResult),
                            );
                        }

                        $language = $transcription['language'] ?? null;
                        $fullText = $transcription['full_text'] ?? null;
                        $segments = $transcription['segments'] ?? null;
                        $engine = $transcription['engine'] ?? null;
                        $model = $transcription['model'] ?? null;

                        // Validate required fields
                        $missingFields = [];
                        if (! is_string($language) || $language === '') {
                            $missingFields[] = 'language';
                        }
                        if (! is_string($fullText)) {
                            $missingFields[] = 'full_text';
                        }
                        if (! is_array($segments)) {
                            $missingFields[] = 'segments';
                        }
                        if (! is_string($engine) || $engine === '') {
                            $missingFields[] = 'engine';
                        }
                        if (! is_string($model) || $model === '') {
                            $missingFields[] = 'model';
                        }

                        if (count($missingFields) > 0) {
                            throw new ProcessMediaException(
                                'Worker returned success but transcription is missing required fields: '
                                .implode(', ', $missingFields),
                                1,
                                json_encode($transcribeResult),
                            );
                        }

                        // Validate segments
                        foreach ($segments as $idx => $seg) {
                            if (! is_array($seg) || ! isset($seg['start_ms'], $seg['end_ms'], $seg['text'])) {
                                throw new ProcessMediaException(
                                    "Worker returned success but segment {$idx} is malformed",
                                    1,
                                    json_encode($seg),
                                );
                            }
                            if (! is_int($seg['start_ms']) || $seg['start_ms'] < 0) {
                                throw new ProcessMediaException(
                                    "Worker returned success but segment {$idx} has invalid start_ms",
                                    1,
                                    json_encode($seg),
                                );
                            }
                            if (! is_int($seg['end_ms']) || $seg['end_ms'] < $seg['start_ms']) {
                                throw new ProcessMediaException(
                                    "Worker returned success but segment {$idx} has invalid end_ms",
                                    1,
                                    json_encode($seg),
                                );
                            }
                            if (! is_string($seg['text']) || trim($seg['text']) === '') {
                                throw new ProcessMediaException(
                                    "Worker returned success but segment {$idx} has empty text",
                                    1,
                                    json_encode($seg),
                                );
                            }
                        }

                        // Cross-segment ordering and overlap validation
                        $prevEndMs = 0;
                        foreach ($segments as $idx => $seg) {
                            if ($idx > 0 && $seg['start_ms'] < $segments[$idx - 1]['start_ms']) {
                                throw new ProcessMediaException(
                                    "Worker returned success but segments are not ordered: segment {$idx} start_ms {$seg['start_ms']} < segment ".($idx - 1)." start_ms {$segments[$idx - 1]['start_ms']}",
                                    1,
                                    json_encode(['segment_index' => $idx]),
                                );
                            }
                            if ($idx > 0 && $seg['start_ms'] < $prevEndMs) {
                                throw new ProcessMediaException(
                                    "Worker returned success but segments overlap: segment {$idx} start_ms {$seg['start_ms']} < previous end_ms {$prevEndMs}",
                                    1,
                                    json_encode(['segment_index' => $idx, 'segment' => $seg, 'prev_end_ms' => $prevEndMs])
                                );
                            }
                            $prevEndMs = $seg['end_ms'];
                        }

                        // Mark transcript as completed
                        $transcript->markCompleted(
                            $language,
                            $fullText,
                            $segments,
                            $engine,
                            $model,
                        );

                        $audioPathResolved = true;

                        Log::info('ProcessMediaAsset: transcription succeeded', [
                            'media_asset_id' => $asset->id,
                        ]);
                    } catch (\Throwable $e) {
                        // Mark transcript as failed
                        $transcript->markFailed($e->getMessage());
                        $audioPathResolved = true;

                        Log::error('ProcessMediaAsset: transcription failed', [
                            'media_asset_id' => $asset->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // =====================================================================
        // Clip Analysis Stage (metadata-only, after upstream resolution) - M4
        // =====================================================================
        $clipAnalysisResolved = false;

        $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();

        // Check if clip analysis is already completed (terminal reuse)
        if ($clipAnalysis !== null && $clipAnalysis->status === MediaClipAnalysis::STATUS_COMPLETED) {
            $clipAnalysisResolved = true;

            Log::info('ProcessMediaAsset: clip analysis already completed, skipping', [
                'media_asset_id' => $asset->id,
            ]);
        } else {
            // Determine if upstream inputs are ready for clip analysis
            $scenesReady = false;
            $scenes = [];
            $transcriptReady = false;
            $transcriptSegments = null;

            // Check scene readiness
            if ($sceneAnalysis !== null && $sceneAnalysis->status === MediaSceneAnalysis::STATUS_COMPLETED) {
                $scenesReady = true;
                $scenes = $sceneAnalysis->scenes ?? [];
            } elseif ($sceneAnalysis !== null && $sceneAnalysis->status === MediaSceneAnalysis::STATUS_FAILED) {
                // Scene detection failed — claim analysis attempt, mark failed
                if ($clipAnalysis === null) {
                    $clipAnalysis = MediaClipAnalysis::create([
                        'media_asset_id' => $asset->id,
                        'status' => MediaClipAnalysis::STATUS_PENDING,
                    ]);
                }
                $clipAnalysis->markAnalyzing();
                $clipAnalysis->markFailed('upstream_scene_failed');

                $clipAnalysisResolved = true;

                Log::error('ProcessMediaAsset: clip analysis failed due to scene detection failure', [
                    'media_asset_id' => $asset->id,
                ]);
            } else {
                if ($sceneAnalysis === null) {
                    // Scene stage resolved as not applicable (no video) — claim attempt, mark failed
                    if ($clipAnalysis === null) {
                        $clipAnalysis = MediaClipAnalysis::create([
                            'media_asset_id' => $asset->id,
                            'status' => MediaClipAnalysis::STATUS_PENDING,
                        ]);
                    }
                    $clipAnalysis->markAnalyzing();
                    $clipAnalysis->markFailed('upstream_scene_missing');

                    $clipAnalysisResolved = true;

                    Log::error('ProcessMediaAsset: clip analysis failed due to missing scene analysis', [
                        'media_asset_id' => $asset->id,
                    ]);
                } else {
                    // Scene detection pending/detecting — not ready
                    // Create clip analysis row if needed and throw to trigger bounded retry (max 3 attempts)
                    if ($clipAnalysis === null) {
                        $clipAnalysis = MediaClipAnalysis::create([
                            'media_asset_id' => $asset->id,
                            'status' => MediaClipAnalysis::STATUS_PENDING,
                        ]);
                    }
                    if ($clipAnalysis->status !== MediaClipAnalysis::STATUS_ANALYZING) {
                        $clipAnalysis->markAnalyzing();
                    }

                    Log::info('ProcessMediaAsset: clip analysis not ready, scene detection pending, releasing for retry', [
                        'media_asset_id' => $asset->id,
                        'attempt' => $this->attempts(),
                    ]);

                    throw new ProcessMediaException('upstream_not_ready', 1);
                }
            }

            // Check transcript readiness (only if audio path resolved and transcript exists)
            if ($audioPathResolved) {
                $existingTranscriptCheck = MediaTranscript::where('media_asset_id', $asset->id)->first();
                if ($existingTranscriptCheck !== null && $existingTranscriptCheck->status === MediaTranscript::STATUS_COMPLETED) {
                    $transcriptReady = true;
                    // Project only timing segments
                    $transcriptSegments = [];
                    foreach (($existingTranscriptCheck->segments ?? []) as $seg) {
                        $transcriptSegments[] = [
                            'start_ms' => $seg['start_ms'],
                            'end_ms' => $seg['end_ms'],
                        ];
                    }
                }
            }

            // If scenes are ready, attempt clip analysis
            if ($scenesReady && ! $clipAnalysisResolved) {
                // Build the metadata-only contract for clip analysis
                $clipContract = MediaProcessingContract::fromMediaAsset($asset, $this->idempotencyKey, 'analyze_clips');
                $clipContract->durationMs = $durationMs;
                $clipContract->scenes = $scenes;
                $clipContract->transcriptSegments = $transcriptSegments;
                $clipContract->configuration = $this->buildClipAnalysisConfiguration();

                // Single validated operational source: strict integer seconds.
                $clipTimeoutSeconds = config('media.clip_analysis_timeout_seconds', 30);
                if (! is_int($clipTimeoutSeconds) || $clipTimeoutSeconds < 1 || $clipTimeoutSeconds > 120) {
                    throw new ProcessMediaException('invalid_configuration');
                }
                $clipLockWaitSeconds = $clipTimeoutSeconds + 5;

                // Atomic claim: first insert, contention waits and the row
                // lock all happen inside one bounded transaction, so an abort
                // rolls everything back and never leaves a placeholder.
                try {
                    DB::transaction(function () use ($asset, $clipContract, $action, $clipTimeoutSeconds, $clipLockWaitSeconds) {
                        if (DB::connection()->getDriverName() === 'pgsql') {
                            // Bound every contended statement in this claim,
                            // including the insert/unique-conflict wait below.
                            // Other drivers have no lock_timeout semantics.
                            DB::select("SELECT set_config('lock_timeout', ?, true)", [$clipLockWaitSeconds.'s']);
                        }

                        // Conflict-safe first insert: concurrent creators
                        // arbitrate on the unique constraint; the loser waits
                        // within the bound, then rereads the winner below.
                        DB::table('media_clip_analyses')->insertOrIgnore([
                            'media_asset_id' => $asset->id,
                            'status' => MediaClipAnalysis::STATUS_PENDING,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // Fresh locked reread of the unique winner.
                        $locked = MediaClipAnalysis::where('media_asset_id', $asset->id)
                            ->lockForUpdate()
                            ->first();

                        if ($locked === null) {
                            return;
                        }

                        // Re-read status after locking
                        if ($locked->status === MediaClipAnalysis::STATUS_COMPLETED) {
                            return;
                        }

                        // Transition to analyzing
                        $locked->markAnalyzing();

                        // Capture input snapshot
                        $inputSnapshot = [
                            'duration_ms' => $clipContract->durationMs,
                            'scenes' => $clipContract->scenes,
                            'configuration' => $clipContract->configuration,
                        ];
                        if ($clipContract->transcriptSegments !== null) {
                            $inputSnapshot['transcript_segments'] = $clipContract->transcriptSegments;
                        }

                        $executionParams = [
                            'timeout_seconds' => $clipTimeoutSeconds,
                            'lock_wait_seconds' => $clipLockWaitSeconds,
                        ];

                        try {
                            // Invoke the worker
                            $result = $action->analyzeClips($clipContract);

                            $analysis = $result['analysis'] ?? null;

                            if (! is_array($analysis)) {
                                throw new ProcessMediaException(
                                    'Worker returned success but analysis data is missing or malformed',
                                    1,
                                );
                            }

                            // Validate required fields
                            $algorithm = $analysis['algorithm'] ?? null;
                            $algorithmVersion = $analysis['algorithm_version'] ?? null;
                            $parameters = $analysis['parameters'] ?? null;
                            $candidates = $analysis['candidates'] ?? null;

                            if (! is_string($algorithm) || $algorithm === '') {
                                throw new ProcessMediaException('analysis_missing_algorithm', 1);
                            }
                            if (! is_string($algorithmVersion) || $algorithmVersion === '') {
                                throw new ProcessMediaException('analysis_missing_algorithm_version', 1);
                            }
                            if (! is_array($parameters)) {
                                throw new ProcessMediaException('analysis_missing_parameters', 1);
                            }
                            if (! is_array($candidates)) {
                                throw new ProcessMediaException('analysis_missing_candidates', 1);
                            }

                            // Complete the analysis
                            $locked->markCompleted(
                                $algorithm,
                                $algorithmVersion,
                                $parameters,
                                $candidates,
                                $inputSnapshot,
                                $executionParams,
                            );
                        } catch (ProcessMediaException $e) {
                            if ($e->getMessage() === 'clip_analysis_aborted' && $e->getPrevious() === null) {
                                // Sanitized abort: roll back, never convert to analysis_failed.
                                throw $e;
                            }
                            // Expected worker/validation failure: sanitized
                            // failed attempt. Anything else propagates so the
                            // transaction rolls back and durable state
                            // survives for a later claim.
                            $locked->markFailed('analysis_failed');
                        }
                    });

                    // Fresh reread: no row may have existed before the claim.
                    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();

                    if ($clipAnalysis === null) {
                        // Deleted/no-op: safe return without resolution.
                        return;
                    }

                    $clipAnalysisResolved = true;

                    if ($clipAnalysis->status === MediaClipAnalysis::STATUS_COMPLETED) {
                        Log::info('ProcessMediaAsset: clip analysis succeeded', [
                            'media_asset_id' => $asset->id,
                            'candidates_count' => count($clipAnalysis->candidates ?? []),
                        ]);
                    } else {
                        Log::error('ProcessMediaAsset: clip analysis failed', [
                            'media_asset_id' => $asset->id,
                            'error' => $clipAnalysis->error,
                        ]);
                    }
                } catch (\Throwable $e) {
                    if ($this->isLockTimeout($e)) {
                        // Bounded contention: sanitized busy. No worker ran
                        // and neither the owner's row nor the asset is
                        // mutated or finalized by the contender.
                        Log::warning('ProcessMediaAsset: clip analysis contended', [
                            'media_asset_id' => $asset->id,
                        ]);

                        return;
                    }

                    // Unexpected abort: the transaction already rolled back,
                    // so prior durable state survives for a later claim, and
                    // an aborted first-create leaves absence atomically.
                    // Signal a sanitized retryable abort to the caller/queue.
                    if ($e instanceof ProcessMediaException
                        && $e->getMessage() === 'clip_analysis_aborted'
                        && $e->getPrevious() === null
                    ) {
                        throw $e;
                    }

                    Log::error('ProcessMediaAsset: clip analysis aborted without resolution', [
                        'media_asset_id' => $asset->id,
                    ]);

                    throw new ProcessMediaException('clip_analysis_aborted', 1, '');
                }
            }
        }

        // =====================================================================
        // Clip Recommendation Stage (metadata-only, after M4 resolution) - M5
        // =====================================================================
        $clipRecommendationResolved = false;

        $clipRecommendation = MediaClipRecommendation::where('media_asset_id', $asset->id)->first();

        // The trusted selection is validated before any contended write.
        $rankingConfiguration = ClipRankingProfile::configuration();
        $rankingTimeoutSeconds = ClipRankingProfile::timeoutSeconds();
        $rankingLockWaitSeconds = ClipRankingProfile::lockWaitSeconds();

        // Terminal reuse: an identical recorded selection reuses the row with
        // zero worker calls and unchanged snapshots and timestamps, even if the
        // transcript completed later or the operational timeout changed.
        $terminalReuse = $clipRecommendation !== null
            && $clipRecommendation->isTerminal()
            && $clipRecommendation->matchesSelection(
                $clipRecommendation->m4_analysis_id,
                $rankingConfiguration,
            );

        // A terminal row recorded under a different M4 authority or semantic
        // selection is never overwritten, never re-inferred and never reported
        // as resolved. This is a fixed non-retryable caller error: the
        // transaction rolls back, the terminal row and its snapshots stay
        // exactly as recorded, and only a nonterminal asset may fail through
        // the safe failure path.
        $versionConflict = $clipRecommendation !== null
            && $clipRecommendation->isTerminal()
            && ! $terminalReuse;

        if ($terminalReuse) {
            $clipRecommendationResolved = true;

            Log::info('ProcessMediaAsset: clip recommendation already resolved, skipping', [
                'media_asset_id' => $asset->id,
                'status' => $clipRecommendation->status,
            ]);
        } else {
            // Determine if M4 clip analysis is ready for recommendation
            $m4Ready = false;
            $m4Candidates = [];

            if ($versionConflict) {
                Log::error('ProcessMediaAsset: clip recommendation version conflict', [
                    'media_asset_id' => $asset->id,
                ]);

                throw new ProcessMediaException('recommendation_version_conflict', 1, '');
            }

            if ($clipAnalysis !== null && $clipAnalysis->status === MediaClipAnalysis::STATUS_COMPLETED) {
                $m4Ready = true;
                $m4Candidates = $clipAnalysis->candidates ?? [];
            } elseif ($clipAnalysis !== null && $clipAnalysis->status === MediaClipAnalysis::STATUS_FAILED) {
                // M4 failed — claim recommendation attempt, mark failed
                if ($clipRecommendation === null) {
                    $clipRecommendation = MediaClipRecommendation::create([
                        'media_asset_id' => $asset->id,
                        'status' => MediaClipRecommendation::STATUS_PENDING,
                    ]);
                }
                $clipRecommendation->markRanking();
                $clipRecommendation->markFailed(MediaClipRecommendation::ERROR_UPSTREAM_M4_UNAVAILABLE);

                $clipRecommendationResolved = true;

                Log::error('ProcessMediaAsset: clip recommendation failed due to M4 clip analysis failure', [
                    'media_asset_id' => $asset->id,
                ]);
            } else {
                if ($clipAnalysis === null) {
                    // M4 stage resolved as not applicable — claim attempt, mark failed
                    if ($clipRecommendation === null) {
                        $clipRecommendation = MediaClipRecommendation::create([
                            'media_asset_id' => $asset->id,
                            'status' => MediaClipRecommendation::STATUS_PENDING,
                        ]);
                    }
                    $clipRecommendation->markRanking();
                    $clipRecommendation->markFailed(MediaClipRecommendation::ERROR_UPSTREAM_M4_UNAVAILABLE);

                    $clipRecommendationResolved = true;

                    Log::error('ProcessMediaAsset: clip recommendation failed due to missing M4 clip analysis', [
                        'media_asset_id' => $asset->id,
                    ]);
                } else {
                    // M4 clip analysis pending/analyzing — not ready
                    // Create clip recommendation row if needed and throw to trigger bounded retry (max 3 attempts)
                    if ($clipRecommendation === null) {
                        $clipRecommendation = MediaClipRecommendation::create([
                            'media_asset_id' => $asset->id,
                            'status' => MediaClipRecommendation::STATUS_PENDING,
                        ]);
                    }
                    if ($clipRecommendation->status !== MediaClipRecommendation::STATUS_RANKING) {
                        $clipRecommendation->markRanking();
                    }

                    Log::info('ProcessMediaAsset: clip recommendation not ready, M4 clip analysis pending, releasing for retry', [
                        'media_asset_id' => $asset->id,
                        'attempt' => $this->attempts(),
                    ]);

                    throw new ProcessMediaException('upstream_not_ready', 1);
                }
            }

            // If M4 is ready, determine transcript availability for M5
            if ($m4Ready && ! $clipRecommendationResolved) {
                $executionParameters = [
                    'timeout_seconds' => $rankingTimeoutSeconds,
                    'lock_wait_seconds' => $rankingLockWaitSeconds,
                ];

                $candidateIndexes = array_map(
                    static fn (array $candidate): int => (int) $candidate['index'],
                    $m4Candidates
                );

                // K=0 after a validated completed M4 is a terminal local
                // completion. It takes precedence over transcript readiness:
                // no transcript text is read, no worker or model is invoked and
                // no inference is claimed.
                if ($m4Candidates === []) {
                    $completion = MediaClipRecommendation::localCompletion(
                        $rankingConfiguration,
                        (int) $clipAnalysis->id,
                        [],
                        (int) $durationMs,
                        ClipRecommendationReadiness::COMPLETED_EMPTY,
                        [],
                        ClipRecommendationValidator::noCandidateRecommendations(),
                        $executionParameters,
                    );

                    $committed = $this->commitClipRecommendation(
                        $asset,
                        $completion,
                        MediaClipRecommendation::OUTCOME_NO_CANDIDATES,
                        null,
                    );

                    // A bounded busy (55P03) rolled this claim back, so no
                    // outcome was persisted and the stage must stay unresolved.
                    $clipRecommendationResolved = $committed && $this->recommendationOutcomeResolved($asset);

                    if ($clipRecommendationResolved) {
                        Log::info('ProcessMediaAsset: clip recommendation completed with no candidates', [
                            'media_asset_id' => $asset->id,
                        ]);
                    }
                } else {
                    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();

                    $hasAudioStream = $audioCodec !== null;
                    $upstreamActive = $transcriptionInFlight
                        || ($transcript !== null
                            && in_array($transcript->status, [
                                MediaTranscript::STATUS_PENDING,
                                MediaTranscript::STATUS_TRANSCRIBING,
                            ], true));

                    $transcriptState = ClipRecommendationReadiness::classify(
                        $hasAudioStream,
                        $audioExtractionFailed,
                        $transcript?->status,
                        $upstreamActive,
                        $transcript?->segments,
                        (int) $durationMs,
                    );

                    $m4AnalysisId = (int) $clipAnalysis->id;

                    if ($transcriptState === ClipRecommendationReadiness::NOT_READY) {
                        // Not ready commits no ranking state at all: the row is
                        // left absent or pending/failed and the attempt is
                        // released for a bounded retry.
                        Log::info('ProcessMediaAsset: clip recommendation not ready, releasing for retry', [
                            'media_asset_id' => $asset->id,
                            'transcript_status' => $transcript?->status,
                            'attempt' => $this->attempts(),
                        ]);

                        throw new ProcessMediaException('upstream_not_ready', 1);
                    }

                    $textHashes = ClipRecommendationProjection::emptyTextHashes($candidateIndexes);
                    $canonicalTexts = array_fill(0, count($m4Candidates), '');

                    if ($transcriptState === ClipRecommendationReadiness::COMPLETED_VALID) {
                        $validatedSegments = ClipRecommendationProjection::validateSegments(
                            $transcript?->segments,
                            (int) $durationMs,
                        );

                        $projection = ClipRecommendationProjection::project(
                            array_map(
                                static fn (array $candidate): array => [
                                    'index' => (int) $candidate['index'],
                                    'start_ms' => (int) $candidate['start_ms'],
                                    'end_ms' => (int) $candidate['end_ms'],
                                ],
                                $m4Candidates
                            ),
                            $validatedSegments,
                        );

                        $canonicalTexts = $projection['texts'];
                        $textHashes = $projection['text_hashes'];
                    }

                    // At least one candidate with usable text is what licenses
                    // a worker request. A mixed set still invokes the provider
                    // for the nonempty candidates only; the remaining entries
                    // are recorded unscored.
                    $usableCandidates = 0;
                    foreach ($canonicalTexts as $canonicalText) {
                        if ($canonicalText !== '') {
                            $usableCandidates++;
                        }
                    }

                    $hasUsableText = $usableCandidates > 0;

                    // Every local outcome: K exact references, null semantic
                    // fields, no inference claim, no worker digest and
                    // empty-string text hashes.
                    $unavailableReason = match ($transcriptState) {
                        ClipRecommendationReadiness::COMPLETED_EMPTY => 'completed_empty',
                        ClipRecommendationReadiness::NO_AUDIO => 'no_audio',
                        ClipRecommendationReadiness::EXTRACTION_FAILED => 'extraction_failed',
                        ClipRecommendationReadiness::TRANSCRIPTION_FAILED => 'transcription_failed',
                        ClipRecommendationReadiness::MISSING => 'missing',
                        default => $hasUsableText ? null : 'no_candidate_text',
                    };

                    if ($unavailableReason !== null || ! $hasUsableText) {
                        $reason = $unavailableReason ?? ClipRecommendationReadiness::NO_CANDIDATE_TEXT;

                        // A local outcome is validated local state, never a
                        // worker request: the K exact references come straight
                        // from the authoritative M4 list, so an outcome that
                        // legitimately has no usable text is still completed
                        // without a process, a digest or an inference claim.
                        $completion = MediaClipRecommendation::localCompletion(
                            $rankingConfiguration,
                            $m4AnalysisId,
                            $m4Candidates,
                            (int) $durationMs,
                            ClipRecommendationReadiness::snapshotState($transcriptState, $reason),
                            $textHashes,
                            ClipRecommendationValidator::localUnavailableRecommendations($m4Candidates, $reason),
                            $executionParameters,
                        );

                        $committed = $this->commitClipRecommendation($asset, $completion, null, $reason);

                        // A bounded busy (55P03) rolled this claim back, so no
                        // outcome was persisted and the stage must stay unresolved.
                        $clipRecommendationResolved = $committed && $this->recommendationOutcomeResolved($asset);

                        if ($clipRecommendationResolved) {
                            Log::info('ProcessMediaAsset: clip recommendation unavailable', [
                                'media_asset_id' => $asset->id,
                                'reason' => $reason,
                            ]);
                        }
                    } else {
                        $recommendationRequest = MediaProcessingContract::rankClipsRequest(
                            (int) $durationMs,
                            $m4Candidates,
                            $canonicalTexts,
                        );

                        $recommendationContract = MediaProcessingContract::fromArray($recommendationRequest);

                        // Atomic claim: first insert, contention waits and the
                        // row lock all happen inside one bounded transaction, so
                        // an abort rolls everything back and never leaves a
                        // placeholder.
                        $claimed = $this->runClipRecommendationClaim(
                            $asset,
                            $recommendationContract,
                            $action,
                            $rankingConfiguration,
                            $executionParameters,
                            $m4AnalysisId,
                            $m4Candidates,
                            (int) $durationMs,
                            ClipRecommendationReadiness::COMPLETED_VALID,
                            $textHashes,
                            $rankingLockWaitSeconds,
                        );

                        // The contender resolves only when its claim committed AND
                        // the durable row really records a resolved outcome. A busy
                        // (55P03) rolls the whole claim back, so a still pending or
                        // absent row stays unresolved and never finalizes the owner
                        // or the asset.
                        $clipRecommendationResolved = $claimed && $this->recommendationOutcomeResolved($asset);
                    }
                }
            }
        }

        // =====================================================================
        // Mark asset as completed when ALL applicable stages have resolved
        // =====================================================================
        if ($sceneDetectionResolved && $audioPathResolved && $clipAnalysisResolved && $clipRecommendationResolved) {
            $asset->markCompleted();
        }
    }

    /**
     * Whether the durable M5 row already records a persisted resolved outcome.
     *
     * `clipRecommendationResolved` is true only for a persisted
     * completed/unavailable/failed outcome or a valid terminal reuse. Busy,
     * not-ready, deleted, aborted and version-conflict paths leave the row
     * absent or nonterminal (pending/ranking) and must never report
     * resolution, because resolution is what allows the asset to be finalized.
     */
    private function recommendationOutcomeResolved(MediaAsset $asset): bool
    {
        $status = MediaClipRecommendation::where('media_asset_id', $asset->id)->value('status');

        return in_array((string) $status, [
            MediaClipRecommendation::STATUS_COMPLETED,
            MediaClipRecommendation::STATUS_UNAVAILABLE,
            MediaClipRecommendation::STATUS_FAILED,
        ], true);
    }

    /**
     * Commit a validated local M5 completion inside its own bounded claim.
     *
     * The shared completion contract runs before any write, so a rejected
     * payload leaves no partial result and no partial status write.
     *
     * @param  array<string, mixed>  $completion
     * @return bool True when the claim transaction committed; false when
     *              SQLSTATE 55P03 rolled the claim back after contention, so
     *              the caller must not report the stage as resolved.
     */
    private function commitClipRecommendation(
        MediaAsset $asset,
        array $completion,
        ?string $outcome,
        ?string $reason,
    ): bool {
        $rankingLockWaitSeconds = ClipRankingProfile::lockWaitSeconds();

        try {
            DB::transaction(function () use ($asset, $completion, $outcome, $reason, $rankingLockWaitSeconds) {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::select('SELECT set_config(\'lock_timeout\', ?, true)', [$rankingLockWaitSeconds.'s']);
                }

                DB::table('media_clip_recommendations')->insertOrIgnore([
                    'media_asset_id' => $asset->id,
                    'status' => MediaClipRecommendation::STATUS_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $locked = MediaClipRecommendation::where('media_asset_id', $asset->id)
                    ->lockForUpdate()
                    ->first();

                // Guard: once a terminal status is recorded, no other process may
                // overwrite it. The owner's committed write must remain authoritative.
                if ($locked !== null && $locked->isTerminal()) {
                    return;
                }

                if ($locked === null) {
                    return;
                }

                $locked->m4_analysis_id = $completion['input_snapshot']['m4_analysis_id'];
                $locked->save();

                $locked->markRanking();

                if ($reason !== null) {
                    $locked->markUnavailable($reason, $completion);

                    return;
                }

                $locked->markCompleted((string) $outcome, $completion);
            });

            return true;
        } catch (\Throwable $exception) {
            if ($this->isLockTimeout($exception)) {
                Log::warning('ProcessMediaAsset: clip recommendation contended', [
                    'media_asset_id' => $asset->id,
                ]);

                return false;
            }

            if ($exception instanceof ProcessMediaException
                && $exception->getMessage() === 'clip_ranking_aborted'
                && $exception->getPrevious() === null) {
                throw $exception;
            }

            Log::error('ProcessMediaAsset: clip recommendation aborted without resolution', [
                'media_asset_id' => $asset->id,
            ]);

            throw new ProcessMediaException('clip_ranking_aborted', 1, '');
        }
    }

    /**
     * Run the bounded M5 claim: one transaction, one metadata subprocess at
     * most, independent validation and one final write.
     *
     * @param  array<string, mixed>  $rankingConfiguration
     * @param  array<string, int>  $executionParameters
     * @param  list<array{index: int, start_ms: int, end_ms: int, rank: int, score: float|int}>  $m4Candidates
     * @param  list<array{index: int, sha256: string}>  $textHashes
     * @return bool True when the claim transaction committed; false when
     *              SQLSTATE 55P03 rolled the claim back after contention, so
     *              the contender must not report the stage as resolved.
     */
    private function runClipRecommendationClaim(
        MediaAsset $asset,
        MediaProcessingContract $recommendationContract,
        ProcessMediaAction $action,
        array $rankingConfiguration,
        array $executionParameters,
        int $m4AnalysisId,
        array $m4Candidates,
        int $durationMs,
        string $transcriptState,
        array $textHashes,
        int $rankingLockWaitSeconds,
    ): bool {
        try {
            DB::transaction(function () use (
                $asset,
                $recommendationContract,
                $action,
                $rankingConfiguration,
                $executionParameters,
                $m4AnalysisId,
                $m4Candidates,
                $durationMs,
                $transcriptState,
                $textHashes,
                $rankingLockWaitSeconds
            ) {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    // Bound every contended statement in this claim, including
                    // the insert/unique-conflict wait below.
                    DB::select('SELECT set_config(\'lock_timeout\', ?, true)', [$rankingLockWaitSeconds.'s']);
                }

                // Conflict-safe first insert: concurrent creators arbitrate on
                // the unique constraint; the loser waits within the bound.
                DB::table('media_clip_recommendations')->insertOrIgnore([
                    'media_asset_id' => $asset->id,
                    'status' => MediaClipRecommendation::STATUS_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $locked = MediaClipRecommendation::where('media_asset_id', $asset->id)
                    ->lockForUpdate()
                    ->first();

                // Guard: if a terminal row already exists (e.g. from a prior owner
                // commit), do not overwrite it. The owner's completed result is
                // authoritative and must remain unchanged.
                if ($locked !== null && $locked->isTerminal()) {
                    return;
                }

                if ($locked === null) {
                    return;
                }

                $locked->m4_analysis_id = $m4AnalysisId;
                $locked->save();
                $locked->markRanking();

                try {
                    $result = $action->rankClips($recommendationContract);

                    $ranking = $result['ranking'] ?? null;
                    if (! is_array($ranking)) {
                        throw new ProcessMediaException('Ranking validation failed', 1, '');
                    }

                    $completion = [
                        'algorithm' => $ranking['algorithm'],
                        'algorithm_version' => $ranking['algorithm_version'],
                        'parameters' => $ranking['parameters'],
                        'recommendations' => $ranking['recommendations'],
                        'input_snapshot' => [
                            'm4_analysis_id' => $m4AnalysisId,
                            'm4_algorithm' => ClipRecommendationValidator::M4_ALGORITHM,
                            'm4_algorithm_version' => ClipRecommendationValidator::M4_ALGORITHM_VERSION,
                            'm4_candidates' => $m4Candidates,
                            'duration_ms' => $durationMs,
                            'transcript_state' => $transcriptState,
                            'projection_version' => $rankingConfiguration['projection_version'],
                            'text_hashes' => $textHashes,
                            'request_sha256' => $ranking['request_sha256'] ?? null,
                        ],
                        'execution_parameters' => $executionParameters,
                    ];

                    $locked->markCompleted(MediaClipRecommendation::OUTCOME_RANKED, $completion);
                } catch (ProcessMediaException $exception) {
                    if ($exception->getMessage() === 'clip_ranking_aborted' && $exception->getPrevious() === null) {
                        throw $exception;
                    }

                    if ($exception->getMessage() === 'invalid_configuration') {
                        throw $exception;
                    }

                    if ($exception->getMessage() === 'invalid_input') {
                        $locked->markFailed(MediaClipRecommendation::ERROR_INVALID_INPUT);

                        return;
                    }

                    // Expected worker/validation failure: sanitized failed M5
                    // only, with no partial result.
                    $locked->markFailed(MediaClipRecommendation::ERROR_RANKING_FAILED);
                }
            });

            return true;
        } catch (\Throwable $exception) {
            if ($this->isLockTimeout($exception)) {
                // Bounded contention: no worker ran and neither the owner's
                // row nor the asset is mutated or finalized by the contender.
                Log::warning('ProcessMediaAsset: clip recommendation contended', [
                    'media_asset_id' => $asset->id,
                ]);

                return false;
            }

            if ($exception instanceof ProcessMediaException
                && in_array($exception->getMessage(), ['clip_ranking_aborted', 'invalid_configuration'], true)
                && $exception->getPrevious() === null) {
                throw $exception;
            }

            Log::error('ProcessMediaAsset: clip recommendation aborted without resolution', [
                'media_asset_id' => $asset->id,
            ]);

            throw new ProcessMediaException('clip_ranking_aborted', 1, '');
        }
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
     * Build default clip analysis configuration.
     *
     * @return array{min_duration_ms: int, target_duration_ms: int, max_duration_ms: int, max_candidates: int, weights: array{duration_fit: int, speech_coverage: int, boundary_alignment: int}}
     */
    private function buildClipAnalysisConfiguration(): array
    {
        return [
            'min_duration_ms' => config('media.clip_analysis_min_duration_ms', 5000),
            'target_duration_ms' => config('media.clip_analysis_target_duration_ms', 30000),
            'max_duration_ms' => config('media.clip_analysis_max_duration_ms', 60000),
            'max_candidates' => config('media.clip_analysis_max_candidates', 20),
            'weights' => [
                'duration_fit' => config('media.clip_analysis_weight_duration_fit', 50),
                'speech_coverage' => config('media.clip_analysis_weight_speech_coverage', 30),
                'boundary_alignment' => config('media.clip_analysis_weight_boundary_alignment', 20),
            ],
        ];
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

        if ($error === 'upstream_not_ready') {
            $this->handleExhaustionFailure($asset, $error);
        } else {
            $asset->markFailed($error);
        }

        Log::error('ProcessMediaAsset: job failed', [
            'media_asset_id' => $asset->id,
            'error' => $error,
        ]);
    }

    /**
     * Handle exhaustion failure due to upstream not ready as a bounded M5 claim.
     */
    private function handleExhaustionFailure(MediaAsset $asset, string $error): void
    {
        $clipAnalysis = null;
        $clipRecommendation = null;
        $finalizedRecommendation = false;

        DB::transaction(function () use ($asset, &$clipAnalysis, &$clipRecommendation, &$finalizedRecommendation) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                // Use the same lock timeout as M5 contended statements
                $lockWaitSeconds = ClipRankingProfile::lockWaitSeconds();
                DB::select("SELECT set_config('lock_timeout', ?, true)", [$lockWaitSeconds.'s']);
            }

            // Handle clip analysis (M4) - mark as failed if exists or create and fail
            $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
            if ($clipAnalysis === null) {
                $clipAnalysis = MediaClipAnalysis::create([
                    'media_asset_id' => $asset->id,
                    'status' => MediaClipAnalysis::STATUS_PENDING,
                ]);
            }
            // Transition through analyzing to failed (PENDING -> ANALYZING -> FAILED)
            if ($clipAnalysis->status === MediaClipAnalysis::STATUS_PENDING) {
                $clipAnalysis->markAnalyzing();
            }
            if ($clipAnalysis->status === MediaClipAnalysis::STATUS_ANALYZING) {
                $clipAnalysis->markFailed('upstream_not_ready');
            }

            // Handle clip recommendation (M5) - bounded claim
            // Conflict-safe first insert
            DB::table('media_clip_recommendations')->insertOrIgnore([
                'media_asset_id' => $asset->id,
                'status' => MediaClipRecommendation::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $locked = MediaClipRecommendation::where('media_asset_id', $asset->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            // Guard: if a terminal row already exists, preserve it
            if ($locked->isTerminal()) {
                return;
            }

            // For nonterminal row, resolve to failed/upstream_not_ready with no intermediate ranking
            if ($locked->status === MediaClipRecommendation::STATUS_PENDING ||
                $locked->status === MediaClipRecommendation::STATUS_FAILED) {
                $locked->status = MediaClipRecommendation::STATUS_FAILED;
                $locked->error = 'upstream_not_ready';
                $locked->reason = null;
                $locked->outcome = null;
                $locked->recommendations = null;
                $locked->save();
                $finalizedRecommendation = true;
            }
        });

        // Fresh reread after transaction (optional, for logging)
        $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        $clipRecommendation = MediaClipRecommendation::where('media_asset_id', $asset->id)->first();

        // Mark asset as failed if we finalized a nonterminal recommendation and the asset is still nonterminal
        if ($finalizedRecommendation) {
            $freshAsset = $asset->fresh();
            if ($freshAsset !== null) {
                $freshAsset->markFailed('upstream_not_ready');
            }
        }
    }

    /**
     * The unique ID of the job (used for idempotent dispatch).
     */
    public function uniqueId(): string
    {
        return $this->idempotencyKey;
    }
}
