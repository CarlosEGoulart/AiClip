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
                                    json_encode(['segment_index' => $idx, 'segment' => $seg, 'prev_end_ms' => $prevEndMs]),
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
        }  // Close outer else (audio_codec !== null)

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

        // Check if clip recommendation is already completed (terminal reuse)
        if ($clipRecommendation !== null && $clipRecommendation->status === MediaClipRecommendation::STATUS_COMPLETED) {
            $clipRecommendationResolved = true;

            Log::info('ProcessMediaAsset: clip recommendation already completed, skipping', [
                'media_asset_id' => $asset->id,
            ]);
        } else {
            // Determine if M4 clip analysis is ready for recommendation
            $m4Ready = false;
            $m4Candidates = [];

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
                $clipRecommendation->markFailed('upstream_m4_failed');

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
                    $clipRecommendation->markFailed('upstream_m4_missing');

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

            // If M4 is ready, determine transcript availability for M5 (seven cases)
            if ($m4Ready && ! $clipRecommendationResolved) {
                // Build candidate objects for rank_clips (index, start_ms, end_ms, rank, transcript_text)
                // Extract transcript text per candidate from MediaTranscript segments
                $rankClipsCandidates = [];

                // Determine transcript availability (seven cases)
                $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
                $transcriptUsed = false;
                $candidateTranscriptTexts = [];

                if ($transcript !== null && $transcript->status === MediaTranscript::STATUS_COMPLETED) {
                    // Case 1: Completed valid transcript, Case 2: Completed empty transcript
                    $segments = $transcript->segments ?? [];
                    if (! empty($segments)) {
                        $transcriptUsed = true;
                    }

                    // Extract candidate-relevant transcript text
                    foreach ($m4Candidates as $candidate) {
                        $text = '';
                        foreach ($segments as $seg) {
                            $segStart = $seg['start_ms'] ?? 0;
                            $segEnd = $seg['end_ms'] ?? 0;
                            $segText = $seg['text'] ?? '';

                            // Check if segment overlaps with candidate
                            if ($segStart < $candidate['end_ms'] && $segEnd > $candidate['start_ms']) {
                                if ($text !== '') {
                                    $text .= ' ';
                                }
                                $text .= $segText;
                            }
                        }
                        $candidateTranscriptTexts[$candidate['index']] = $text;
                    }
                } elseif ($transcript !== null && $transcript->status === MediaTranscript::STATUS_FAILED) {
                    // Case 5: Failed transcription - no transcript used, all candidates get empty text
                    foreach ($m4Candidates as $candidate) {
                        $candidateTranscriptTexts[$candidate['index']] = '';
                    }
                } elseif ($audioCodec === null) {
                    // Case 3: No audio - no transcript used, all candidates get empty text
                    foreach ($m4Candidates as $candidate) {
                        $candidateTranscriptTexts[$candidate['index']] = '';
                    }
                } elseif ($asset->processing_status === MediaAsset::PROCESSING_FAILED) {
                    // Case 4: Failed extraction - no transcript used even if stale transcript exists
                    foreach ($m4Candidates as $candidate) {
                        $candidateTranscriptTexts[$candidate['index']] = '';
                    }
                } elseif ($transcript === null) {
                    // Case 6: Missing transcript - no transcript used, all candidates get empty text
                    foreach ($m4Candidates as $candidate) {
                        $candidateTranscriptTexts[$candidate['index']] = '';
                    }
                } elseif (in_array($transcript->status, [MediaTranscript::STATUS_PENDING, MediaTranscript::STATUS_TRANSCRIBING], true)) {
                    // Case 7: Not ready (pending/transcribing) - bounded not-ready
                    // Create clip recommendation row if needed and throw to trigger bounded retry
                    if ($clipRecommendation === null) {
                        $clipRecommendation = MediaClipRecommendation::create([
                            'media_asset_id' => $asset->id,
                            'status' => MediaClipRecommendation::STATUS_PENDING,
                        ]);
                    }
                    if ($clipRecommendation->status !== MediaClipRecommendation::STATUS_RANKING) {
                        $clipRecommendation->markRanking();
                    }

                    Log::info('ProcessMediaAsset: clip recommendation not ready, transcript pending/transcribing, releasing for retry', [
                        'media_asset_id' => $asset->id,
                        'transcript_status' => $transcript->status,
                        'attempt' => $this->attempts(),
                    ]);

                    throw new ProcessMediaException('upstream_not_ready', 1);
                } else {
                    // Invalid completed transcript timing (malformed) - will fail preflight
                    foreach ($m4Candidates as $candidate) {
                        $candidateTranscriptTexts[$candidate['index']] = '';
                    }
                }

                // Build rank_clips candidates with transcript text
                foreach ($m4Candidates as $candidate) {
                    $rankClipsCandidates[] = [
                        'index' => $candidate['index'],
                        'start_ms' => $candidate['start_ms'],
                        'end_ms' => $candidate['end_ms'],
                        'rank' => $candidate['rank'],
                        'transcript_text' => $candidateTranscriptTexts[$candidate['index']] ?? '',
                    ];
                }

                // Build the metadata-only contract for clip recommendation
                $recommendationContract = MediaProcessingContract::fromMediaAsset($asset, $this->idempotencyKey, 'rank_clips');
                $recommendationContract->durationMs = $durationMs;
                $recommendationContract->candidates = $rankClipsCandidates;
                $recommendationContract->configuration = [
                    'prototype_query' => config('media.clip_ranking_prototype_query'),
                ];

                // Scenes are re-read afresh (M4 terminal reuse may leave earlier locals unset).
                $rankSceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
                $recommendationContract->scenes = ($rankSceneAnalysis !== null
                    && $rankSceneAnalysis->status === MediaSceneAnalysis::STATUS_COMPLETED)
                    ? ($rankSceneAnalysis->scenes ?? [])
                    : [];

                // Timing-only transcript projection for toArray()/metadata shape.
                // Text is never included here; raw text lives only in candidates for worker transport.
                if ($transcript !== null && $transcript->status === MediaTranscript::STATUS_COMPLETED) {
                    $recommendationContract->transcriptSegments = array_map(
                        static fn (array $seg): array => [
                            'start_ms' => $seg['start_ms'],
                            'end_ms' => $seg['end_ms'],
                        ],
                        $transcript->segments ?? [],
                    );
                } else {
                    $recommendationContract->transcriptSegments = null;
                }

                // Single validated operational source: strict integer seconds.
                $rankingTimeoutSeconds = config('media.clip_ranking_timeout_seconds', 60);
                if (! is_int($rankingTimeoutSeconds) || $rankingTimeoutSeconds < 1 || $rankingTimeoutSeconds > 120) {
                    throw new ProcessMediaException('invalid_configuration');
                }
                $rankingLockWaitSeconds = $rankingTimeoutSeconds + 5;

                // Atomic claim: first insert, contention waits and the row
                // lock all happen inside one bounded transaction, so an abort
                // rolls everything back and never leaves a placeholder.
                try {
                    DB::transaction(function () use ($asset, $recommendationContract, $action, $rankingTimeoutSeconds, $rankingLockWaitSeconds, $transcriptUsed) {
                        if (DB::connection()->getDriverName() === 'pgsql') {
                            // Bound every contended statement in this claim,
                            // including the insert/unique-conflict wait below.
                            // Other drivers have no lock_timeout semantics.
                            DB::select("SELECT set_config('lock_timeout', ?, true)", [$rankingLockWaitSeconds.'s']);
                        }

                        // Conflict-safe first insert: concurrent creators
                        // arbitrate on the unique constraint; the loser waits
                        // within the bound, then rereads the winner below.
                        DB::table('media_clip_recommendations')->insertOrIgnore([
                            'media_asset_id' => $asset->id,
                            'status' => MediaClipRecommendation::STATUS_PENDING,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // Fresh locked reread of the unique winner.
                        $locked = MediaClipRecommendation::where('media_asset_id', $asset->id)
                            ->lockForUpdate()
                            ->first();

                        if ($locked === null) {
                            return;
                        }

                        // Re-read status after locking
                        if ($locked->status === MediaClipRecommendation::STATUS_COMPLETED) {
                            return;
                        }

                        // Transition to ranking
                        $locked->markRanking();

                        // Capture input snapshot (with transcript text hashes for privacy)
                        $inputSnapshot = [
                            'duration_ms' => $recommendationContract->durationMs,
                            'candidates' => array_map(function ($c) {
                                return [
                                    'index' => $c['index'],
                                    'start_ms' => $c['start_ms'],
                                    'end_ms' => $c['end_ms'],
                                    'rank' => $c['rank'],
                                ];
                            }, $recommendationContract->candidates),
                            'transcript_used' => $transcriptUsed,
                            'prototype_query' => $recommendationContract->configuration['prototype_query'],
                        ];

                        $executionParams = [
                            'timeout_seconds' => $rankingTimeoutSeconds,
                            'lock_wait_seconds' => $rankingLockWaitSeconds,
                        ];

                        try {
                            // Invoke the worker
                            $result = $action->rankClips($recommendationContract);

                            $ranking = $result['ranking'] ?? null;

                            if (! is_array($ranking)) {
                                throw new ProcessMediaException(
                                    'Worker returned success but ranking data is missing or malformed',
                                    1,
                                );
                            }

                            // Validate required fields
                            $algorithm = $ranking['algorithm'] ?? null;
                            $algorithmVersion = $ranking['algorithm_version'] ?? null;
                            $parameters = $ranking['parameters'] ?? null;
                            $recommendations = $ranking['recommendations'] ?? null;

                            if (! is_string($algorithm) || $algorithm === '') {
                                throw new ProcessMediaException('ranking_missing_algorithm', 1);
                            }
                            if (! is_string($algorithmVersion) || $algorithmVersion === '') {
                                throw new ProcessMediaException('ranking_missing_algorithm_version', 1);
                            }
                            if (! is_array($parameters)) {
                                throw new ProcessMediaException('ranking_missing_parameters', 1);
                            }
                            if (! is_array($recommendations)) {
                                throw new ProcessMediaException('ranking_missing_recommendations', 1);
                            }

                            // Complete the recommendation
                            $locked->markCompleted(
                                $algorithm,
                                $algorithmVersion,
                                $parameters,
                                $recommendations,
                                $inputSnapshot,
                                $executionParams,
                            );
                        } catch (ProcessMediaException $e) {
                            if ($e->getMessage() === 'clip_ranking_aborted' && $e->getPrevious() === null) {
                                // Sanitized abort: roll back, never convert to ranking_failed.
                                throw $e;
                            }
                            // Expected worker/validation failure: sanitized
                            // failed attempt. Anything else propagates so the
                            // transaction rolls back and durable state
                            // survives for a later claim.
                            $locked->markFailed('ranking_failed');
                        }
                    });

                    // Fresh reread: no row may have existed before the claim.
                    $clipRecommendation = MediaClipRecommendation::where('media_asset_id', $asset->id)->first();

                    if ($clipRecommendation === null) {
                        // Deleted/no-op: safe return without resolution.
                        return;
                    }

                    $clipRecommendationResolved = true;

                    if ($clipRecommendation->status === MediaClipRecommendation::STATUS_COMPLETED) {
                        Log::info('ProcessMediaAsset: clip recommendation succeeded', [
                            'media_asset_id' => $asset->id,
                            'recommendations_count' => count($clipRecommendation->recommendations ?? []),
                        ]);
                    } else {
                        Log::error('ProcessMediaAsset: clip recommendation failed', [
                            'media_asset_id' => $asset->id,
                            'error' => $clipRecommendation->error,
                        ]);
                    }
                } catch (\Throwable $e) {
                    if ($this->isLockTimeout($e)) {
                        // Bounded contention: sanitized busy. No worker ran
                        // and neither the owner's row nor the asset is
                        // mutated or finalized by the contender.
                        Log::warning('ProcessMediaAsset: clip recommendation contended', [
                            'media_asset_id' => $asset->id,
                        ]);

                        return;
                    }

                    // Unexpected abort: the transaction already rolled back,
                    // so prior durable state survives for a later claim, and
                    // an aborted first-create leaves absence atomically.
                    // Signal a sanitized retryable abort to the caller/queue.
                    if ($e instanceof ProcessMediaException
                        && $e->getMessage() === 'clip_ranking_aborted'
                        && $e->getPrevious() === null
                    ) {
                        throw $e;
                    }

                    Log::error('ProcessMediaAsset: clip recommendation aborted without resolution', [
                        'media_asset_id' => $asset->id,
                    ]);

                    throw new ProcessMediaException('clip_ranking_aborted', 1, '');
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

        $asset->markFailed($error);

        // If exhaustion due to upstream not ready, mark clip analysis and clip recommendation as failed
        if ($error === 'upstream_not_ready') {
            $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
            if ($clipAnalysis === null) {
                // Create clip analysis row on exhaustion if it doesn't exist
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

            // Also handle clip recommendation
            $clipRecommendation = MediaClipRecommendation::where('media_asset_id', $asset->id)->first();
            if ($clipRecommendation === null) {
                $clipRecommendation = MediaClipRecommendation::create([
                    'media_asset_id' => $asset->id,
                    'status' => MediaClipRecommendation::STATUS_PENDING,
                ]);
            }
            if ($clipRecommendation->status === MediaClipRecommendation::STATUS_PENDING) {
                $clipRecommendation->markRanking();
            }
            if ($clipRecommendation->status === MediaClipRecommendation::STATUS_RANKING) {
                $clipRecommendation->markFailed('upstream_not_ready');
            }
        }

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
