<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\ProcessMediaException;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipRecommendation;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ProcessMediaAction;
use App\Services\ClipRankingProfile;
use App\Jobs\RenderMediaClip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures for RenderMediaClip job tests
|--------------------------------------------------------------------------
*/

function createProbedAssetForRender(array $overrides = []): MediaAsset
{
    return MediaAsset::factory()->create(array_merge([
        'processing_status' => MediaAsset::PROCESSING_PROBED,
        'duration_ms' => 30000,
        'probe_result' => [
            'duration_ms' => 30000,
            'width' => 1920,
            'height' => 1080,
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
        ],
        'storage_disk' => 'media',
        'storage_key' => 'projects/1/assets/1/source.mp4',
    ], $overrides));
}

function createCompletedSceneAnalysisForRender(MediaAsset $asset): MediaSceneAnalysis
{
    return MediaSceneAnalysis::create([
        'media_asset_id' => $asset->id,
        'status' => MediaSceneAnalysis::STATUS_COMPLETED,
        'detector' => 'pyscenedetect',
        'detector_version' => '1.0.0',
        'parameters' => [],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 15000],
            ['index' => 1, 'start_ms' => 15000, 'end_ms' => 30000],
        ],
    ]);
}

function createCompletedClipAnalysisForRender(MediaAsset $asset): MediaClipAnalysis
{
    return MediaClipAnalysis::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipAnalysis::STATUS_COMPLETED,
        'algorithm' => 'scene_timing_baseline',
        'algorithm_version' => '1.0.0',
        'parameters' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 30000,
            'max_duration_ms' => 60000,
            'max_candidates' => 20,
            'weights' => [
                'duration_fit' => 50,
                'speech_coverage' => 30,
                'boundary_alignment' => 20,
            ],
        ],
        'candidates' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'score' => 1.0, 'criteria' => ['duration_fit' => 1.0, 'speech_coverage' => 0.0, 'boundary_alignment' => 0.0], 'source_scene_indexes' => [0]],
            ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'score' => 0.5, 'criteria' => ['duration_fit' => 1.0, 'speech_coverage' => 0.0, 'boundary_alignment' => 0.0], 'source_scene_indexes' => [1]],
        ],
    ]);
}

function createCompletedTranscriptForRender(MediaAsset $asset, MediaSceneAnalysis $sceneAnalysis): MediaTranscript
{
    $derivedAsset = \App\Models\DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => \App\Models\DerivedAsset::TYPE_AUDIO_NORMALIZED,
        'storage_disk' => 'media',
        'storage_key' => 'projects/1/assets/1/derivatives/audio/test.wav',
        'mime_type' => 'audio/wav',
        'size_bytes' => 1024000,
        'duration_ms' => 30000,
        'sample_rate' => 16000,
        'channels' => 1,
        'codec' => 'pcm_s16le',
    ]);

    return MediaTranscript::create([
        'media_asset_id' => $asset->id,
        'derived_asset_id' => $derivedAsset->id,
        'status' => MediaTranscript::STATUS_COMPLETED,
        'language' => 'en',
        'full_text' => 'Test transcript',
        'segments' => [
            ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'First segment'],
            ['start_ms' => 10000, 'end_ms' => 20000, 'text' => 'Second segment'],
        ],
        'engine' => 'whisper',
        'model' => 'base',
    ]);
}

function createCompletedRecommendationForRender(MediaAsset $asset, MediaClipAnalysis $clipAnalysis, MediaTranscript $transcript): MediaClipRecommendation
{
    $executionParameters = [
        'timeout_seconds' => ClipRankingProfile::timeoutSeconds(),
        'lock_wait_seconds' => ClipRankingProfile::lockWaitSeconds(),
    ];

    return MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
        'outcome' => MediaClipRecommendation::OUTCOME_RANKED,
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'parameters' => ClipRankingProfile::parameters(ClipRankingProfile::configuration(), false, true),
        'recommendations' => [
            [
                'm4_candidate_index' => 0,
                'start_ms' => 0,
                'end_ms' => 10000,
                'm4_rank' => 1,
                'm4_score' => 1.0,
                'semantic_score' => 0.95,
                'semantic_rank' => 1,
                'reason' => null,
            ],
            [
                'm4_candidate_index' => 1,
                'start_ms' => 10000,
                'end_ms' => 20000,
                'm4_rank' => 2,
                'm4_score' => 0.5,
                'semantic_score' => 0.75,
                'semantic_rank' => 2,
                'reason' => null,
            ],
        ],
        'input_snapshot' => [
            'm4_analysis_id' => $clipAnalysis->id,
            'm4_algorithm' => 'scene_timing_baseline',
            'm4_algorithm_version' => '1.0.0',
            'm4_candidates' => $clipAnalysis->candidates,
            'duration_ms' => 30000,
            'transcript_state' => 'completed_valid',
            'projection_version' => '1.0.0',
            'text_hashes' => [
                ['index' => 0, 'sha256' => hash('sha256', 'First segment')],
                ['index' => 1, 'sha256' => hash('sha256', 'Second segment')],
            ],
            'request_sha256' => hash('sha256', json_encode([
                'version' => '1.0.0',
                'action' => 'rank_clips',
                'media' => ['duration_ms' => 30000],
                'candidates' => [
                    ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1, 'm4_score' => 1.0, 'transcript_text' => 'First segment'],
                    ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2, 'm4_score' => 0.5, 'transcript_text' => 'Second segment'],
                ],
                'configuration' => ClipRankingProfile::configuration(),
            ], JSON_THROW_ON_ERROR)),
        ],
        'execution_parameters' => $executionParameters,
    ]);
}

/*
|--------------------------------------------------------------------------
| Mock worker action for recording render calls
|--------------------------------------------------------------------------
*/

class RecordingRenderAction extends ProcessMediaAction
{
    public array $renderCalls = [];
    public array $renderResults = [];
    public bool $shouldFail = false;
    public string $failCode = 'render_failed';

    public function __construct(array $renderResults = [])
    {
        $this->renderResults = $renderResults;
    }

    public function renderClips(\App\Contracts\MediaProcessingContract $contract): array
    {
        $request = $contract->toRenderClipsMetadataArray();
        $this->renderCalls[] = $request;

        if ($this->shouldFail) {
            throw new ProcessMediaException($this->failCode, 1, '');
        }

        if (empty($this->renderResults)) {
            // Return default success
            $requestSha256 = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
            return [
                'status' => 'success',
                'render' => [
                    'algorithm' => \App\Services\RenderValidator::ALGORITHM,
                    'algorithm_version' => \App\Services\RenderValidator::ALGORITHM_VERSION,
                    'parameters' => [
                        'configuration' => \App\Services\RenderProfile::configuration(),
                        'source_media' => [
                            'disk' => 'media',
                            'key' => 'projects/1/assets/1/source.mp4',
                            'duration_ms' => 30000,
                            'width' => 1920,
                            'height' => 1080,
                            'video_codec' => 'h264',
                            'audio_codec' => 'aac',
                        ],
                        'ffmpeg_version' => 'ffmpeg version 6.0',
                        'filter_graph' => 'crop=ih*9/16:ih:(iw-ih*9/16)/2:0,scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,fps=30',
                        'limits' => [
                            'max_recommendations' => 1000,
                            'max_input_bytes' => 8388608,
                            'max_duration_ms' => 2147483647,
                        ],
                        'request_sha256' => $requestSha256,
                    ],
                    'clips' => [
                        [
                            'candidate_index' => $request['candidate_index'],
                            'semantic_rank' => $request['recommendation']['candidates'][$request['candidate_index']]['semantic_rank'],
                            'semantic_score' => $request['recommendation']['candidates'][$request['candidate_index']]['semantic_score'],
                            'start_ms' => $request['recommendation']['candidates'][$request['candidate_index']]['start_ms'],
                            'end_ms' => $request['recommendation']['candidates'][$request['candidate_index']]['end_ms'],
                            'duration_ms' => $request['recommendation']['candidates'][$request['candidate_index']]['end_ms'] - $request['recommendation']['candidates'][$request['candidate_index']]['start_ms'],
                            'output' => [
                                'disk' => 'media',
                                'key' => 'renders/1/1/'.$request['candidate_index'].'_20260101T000000Z.mp4',
                                'size_bytes' => 1024000,
                                'duration_ms' => $request['recommendation']['candidates'][$request['candidate_index']]['end_ms'] - $request['recommendation']['candidates'][$request['candidate_index']]['start_ms'],
                                'width' => 1080,
                                'height' => 1920,
                                'video_codec' => 'libx264',
                                'audio_codec' => 'aac',
                                'video_bitrate_kbps' => 5000,
                                'audio_bitrate_kbps' => 128,
                            ],
                        ],
                    ],
                ],
            ];
        }

        return array_shift($this->renderResults);
    }
}

/*
|--------------------------------------------------------------------------
| RED PHASE TESTS - These should FAIL initially because RenderMediaClip job doesn't exist yet
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| TC-RMJ-01: M5 completed/ranked, valid candidate_index → job completes, DerivedAsset created
|--------------------------------------------------------------------------
*/

it('renders clip when explicitly dispatched with valid candidate_index', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('completed');
    expect($render->candidate_index)->toBe(0);
    expect(count($action->renderCalls))->toBe(1);
    expect($action->renderCalls[0]['candidate_index'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-02: M5 completed/ranked, candidate_index out of bounds → job fails
|--------------------------------------------------------------------------
*/

it('fails when candidate_index out of bounds', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $job = new RenderMediaClip($asset->id, $recommendation->id, 5, $action); // Out of bounds (only 2 candidates)

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('invalid_candidate_index');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-03: M5 completed/ranked, candidate has null semantic_score → job fails
|--------------------------------------------------------------------------
*/

it('fails when selected candidate has null semantic_score', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);

    // Create recommendation where candidate 0 has null semantic_score
    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
        'outcome' => MediaClipRecommendation::OUTCOME_RANKED,
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'parameters' => ClipRankingProfile::parameters(ClipRankingProfile::configuration(), false, true),
        'recommendations' => [
            [
                'm4_candidate_index' => 0,
                'start_ms' => 0,
                'end_ms' => 10000,
                'm4_rank' => 1,
                'm4_score' => 1.0,
                'semantic_score' => null, // NULL SCORE
                'semantic_rank' => 1,
                'reason' => 'no_candidate_text',
            ],
            [
                'm4_candidate_index' => 1,
                'start_ms' => 10000,
                'end_ms' => 20000,
                'm4_rank' => 2,
                'm4_score' => 0.5,
                'semantic_score' => 0.75,
                'semantic_rank' => 2,
                'reason' => null,
            ],
        ],
        'input_snapshot' => [
            'm4_analysis_id' => $clipAnalysis->id,
            'm4_algorithm' => 'scene_timing_baseline',
            'm4_algorithm_version' => '1.0.0',
            'm4_candidates' => $clipAnalysis->candidates,
            'duration_ms' => 30000,
            'transcript_state' => 'completed_valid',
            'projection_version' => '1.0.0',
            'text_hashes' => [
                ['index' => 0, 'sha256' => hash('sha256', 'First segment')],
                ['index' => 1, 'sha256' => hash('sha256', 'Second segment')],
            ],
            'request_sha256' => hash('sha256', 'test'),
        ],
        'execution_parameters' => [
            'timeout_seconds' => 60,
            'lock_wait_seconds' => 65,
        ],
    ]);

    $action = new RecordingRenderAction();
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('invalid_candidate_index');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-04: M5 pending/ranking/not_ready → throws UpstreamRecommendationUnavailableException
|--------------------------------------------------------------------------
*/

it('throws when M5 recommendation is pending', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    $action = new RecordingRenderAction();
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('upstream_recommendation_unavailable');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-05: M5 failed → throws UpstreamRecommendationFailedException
|--------------------------------------------------------------------------
*/

it('throws when M5 recommendation failed', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_FAILED,
        'error' => 'ranking_failed',
    ]);

    $action = new RecordingRenderAction();
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('upstream_recommendation_failed');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-06: M5 unavailable → throws UpstreamRecommendationUnavailableException
|--------------------------------------------------------------------------
*/

it('throws when M5 recommendation unavailable', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_UNAVAILABLE,
        'reason' => 'no_candidate_text',
    ]);

    $action = new RecordingRenderAction();
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('upstream_recommendation_unavailable');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-07: M5 missing after resolved → throws UpstreamRecommendationMissingException
|--------------------------------------------------------------------------
*/

it('throws when M5 recommendation missing', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);

    // No MediaClipRecommendation row at all
    $fakeRecommendationId = 99999;

    $action = new RecordingRenderAction();
    $job = new RenderMediaClip($asset->id, $fakeRecommendationId, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('upstream_recommendation_missing');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-08: Existing completed DerivedAsset (same candidate+profile) → reused, no worker call
|--------------------------------------------------------------------------
*/

it('reuses existing completed render, no worker call', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    // Pre-create completed render
    DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
        'candidate_index' => 0,
        'render_profile_version' => \App\Services\RenderProfile::RENDER_PROFILE_VERSION,
        'status' => 'completed',
        'storage_disk' => 'media',
        'storage_key' => 'renders/1/1/0_20260101T000000Z.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 1024000,
        'duration_ms' => 10000,
        'width' => 1080,
        'height' => 1920,
        'codec' => 'libx264',
        'render_configuration' => \App\Services\RenderProfile::configuration(),
        'render_parameters' => ['test' => 'data'],
    ]);

    $action = new RecordingRenderAction();
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect(count($action->renderCalls))->toBe(0);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->status)->toBe('completed');
    expect($result->id)->toBe($render->id);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-09: Existing completed DerivedAsset, different M5 authority/config → version_conflict
|--------------------------------------------------------------------------
*/

it('throws version_conflict when existing render has different M5 authority', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    // Pre-create completed render with DIFFERENT profile version
    DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
        'candidate_index' => 0,
        'render_profile_version' => 'ffmpeg_vertical_baseline:2.0.0', // Different version
        'status' => 'completed',
        'storage_disk' => 'media',
        'storage_key' => 'renders/1/1/0_20260101T000000Z.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 1024000,
        'duration_ms' => 10000,
        'width' => 1080,
        'height' => 1920,
        'codec' => 'libx264',
    ]);

    $action = new RecordingRenderAction();
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('render_version_conflict');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-10: Explicit candidate_index selection - different index produces different output
|--------------------------------------------------------------------------
*/

it('produces different output for different candidate_index', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();

    // Render candidate 0
    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);
    $job1->handle();

    // Render candidate 1
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 1, $action);
    $job2->handle();

    expect(count($action->renderCalls))->toBe(2);
    expect($action->renderCalls[0]['candidate_index'])->toBe(0);
    expect($action->renderCalls[1]['candidate_index'])->toBe(1);

    // Verify two separate DerivedAsset rows
    $render0 = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    $render1 = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 1)
        ->first();

    expect($render0)->not->toBeNull();
    expect($render1)->not->toBeNull();
    expect($render0->id)->not->toBe($render1->id);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-11: Invalid candidate_index (negative) → rejected
|--------------------------------------------------------------------------
*/

it('rejects negative candidate_index', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $job = new RenderMediaClip($asset->id, $recommendation->id, -1, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('invalid_candidate_index');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-12: Job idempotency - re-dispatch same params → returns same DerivedAsset
|--------------------------------------------------------------------------
*/

it('is idempotent - re-dispatch returns same DerivedAsset', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();

    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);
    $result1 = $job1->handle();

    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);
    $result2 = $job2->handle();

    expect($result1->id)->toBe($result2->id);
    expect(count($action->renderCalls))->toBe(1); // Only first call invoked worker
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-13: Failed attempt retry - re-dispatch after failure → new attempt
|--------------------------------------------------------------------------
*/

it('retries failed attempt - re-dispatch after failure clears error and re-attempts', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $action->shouldFail = true;
    $action->failCode = 'render_failed';

    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job1->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();

    // Check render is failed
    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->status)->toBe('failed');
    expect($render->render_error)->toBe('render_failed');

    // Now retry with success
    $action2 = new RecordingRenderAction();
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action2);
    $result2 = $job2->handle();

    $render->refresh();
    expect($render->status)->toBe('completed');
    expect($render->render_error)->toBeNull();
    expect(count($action2->renderCalls))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-14: Invalid duration (missing probe) → throws InvalidInputException
|--------------------------------------------------------------------------
*/

it('throws when asset has invalid probe data', function () {
    $asset = createProbedAssetForRender(['probe_result' => [], 'duration_ms' => 0]);
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('invalid_input');
});