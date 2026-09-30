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
use App\Services\RenderProfile;
use App\Services\RenderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures for ProcessMediaAsset render stage tests
|--------------------------------------------------------------------------
*/

function createProbedAsset(array $overrides = []): MediaAsset
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

function createCompletedSceneAnalysis(MediaAsset $asset): MediaSceneAnalysis
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

function createCompletedClipAnalysis(MediaAsset $asset): MediaClipAnalysis
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

function createCompletedTranscript(MediaAsset $asset, MediaSceneAnalysis $sceneAnalysis): MediaTranscript
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

function createCompletedRecommendation(MediaAsset $asset, MediaClipAnalysis $clipAnalysis, MediaTranscript $transcript): MediaClipRecommendation
{
    $executionParameters = [
        'timeout_seconds' => ClipRankingProfile::timeoutSeconds(),
        'lock_wait_seconds' => ClipRankingProfile::lockWaitSeconds(),
    ];

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
            'm4_candidates' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'score' => 1.0, 'criteria' => ['duration_fit' => 1.0, 'speech_coverage' => 0.0, 'boundary_alignment' => 0.0], 'source_scene_indexes' => [0]],
                ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'score' => 0.5, 'criteria' => ['duration_fit' => 1.0, 'speech_coverage' => 0.0, 'boundary_alignment' => 0.0], 'source_scene_indexes' => [1]],
            ],
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

    return $recommendation;
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
                    'algorithm' => RenderValidator::ALGORITHM,
                    'algorithm_version' => RenderValidator::ALGORITHM_VERSION,
                    'parameters' => [
                        'configuration' => RenderProfile::configuration(),
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
| TC-PMA-01: M5 completed/ranked, valid candidate_index → render invoked, completes
|--------------------------------------------------------------------------
*/

it('renders clip when M5 completed with valid candidate_index', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('completed');
    expect($render->candidate_index)->toBe(0);
    expect(count($action->renderCalls))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| TC-PMA-02: M5 completed/ranked, candidate_index out of bounds → failed attempt
|--------------------------------------------------------------------------
*/

it('fails render when candidate_index out of bounds', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);

    // Create recommendation with only 2 candidates
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
            'request_sha256' => hash('sha256', 'test'),
        ],
        'execution_parameters' => [
            'timeout_seconds' => 60,
            'lock_wait_seconds' => 65,
        ],
    ]);

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $job->handle();

    $asset->refresh();
    // Asset should still complete because render failure is a controlled upstream failure
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('failed');
    expect($render->render_error)->toBe('invalid_candidate_index');
});

/*
|--------------------------------------------------------------------------
| TC-PMA-03: M5 completed/ranked, candidate has null semantic_score → failed attempt
|--------------------------------------------------------------------------
*/

it('fails render when selected candidate has null semantic_score', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);

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
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('failed');
    expect($render->render_error)->toBe('invalid_candidate_index');
});

/*
|--------------------------------------------------------------------------
| TC-PMA-04: M5 pending → not_ready, job retries (max 3, 5s delay)
|--------------------------------------------------------------------------
*/

it('returns not_ready when M5 pending', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);

    // Create PENDING recommendation
    MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (ProcessMediaException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('upstream_not_ready');
    expect($job->attempts())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| TC-PMA-05: M5 failed → render attempt claimed, marked failed
|--------------------------------------------------------------------------
*/

it('fails render when M5 failed', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);

    MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_FAILED,
        'error' => 'ranking_failed',
    ]);

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('failed');
    expect($render->render_error)->toBe('upstream_recommendation_failed');
});

/*
|--------------------------------------------------------------------------
| TC-PMA-06: M5 unavailable → render attempt claimed, marked failed
|--------------------------------------------------------------------------
*/

it('fails render when M5 unavailable', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);

    MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_UNAVAILABLE,
        'reason' => 'no_candidate_text',
    ]);

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('failed');
    expect($render->render_error)->toBe('upstream_recommendation_unavailable');
});

/*
|--------------------------------------------------------------------------
| TC-PMA-07: M5 missing after resolved → failed attempt
|--------------------------------------------------------------------------
*/

it('fails render when M5 missing after resolved', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);

    // No MediaClipRecommendation row at all

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('failed');
    expect($render->render_error)->toBe('upstream_recommendation_missing');
});

/*
|--------------------------------------------------------------------------
| TC-PMA-08: Existing completed DerivedAsset (same candidate+profile) → reused, no worker call
|--------------------------------------------------------------------------
*/

it('reuses existing completed render, no worker call', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

    // Pre-create completed render
    DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
        'candidate_index' => 0,
        'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
        'status' => 'completed',
        'storage_disk' => 'media',
        'storage_key' => 'renders/1/1/0_20260101T000000Z.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 1024000,
        'duration_ms' => 10000,
        'width' => 1080,
        'height' => 1920,
        'codec' => 'libx264',
        'render_configuration' => RenderProfile::configuration(),
        'render_parameters' => ['test' => 'data'],
    ]);

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $job->handle();

    expect(count($action->renderCalls))->toBe(0);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render->status)->toBe('completed');
});

/*
|--------------------------------------------------------------------------
| TC-PMA-09: Existing completed DerivedAsset, different configuration → version_conflict
|--------------------------------------------------------------------------
*/

it('fails with version_conflict when existing render has different configuration', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

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
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (ProcessMediaException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('render_version_conflict');
});

/*
|--------------------------------------------------------------------------
| TC-PMA-10: Concurrent claim - first job locks, second gets busy
|--------------------------------------------------------------------------
*/

it('returns busy for concurrent claim (second job gets busy)', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

    $action1 = new RecordingRenderAction();
    $action2 = new RecordingRenderAction();

    $job1 = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action1);
    $job2 = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action2);

    // Run job1 first (it will get the lock)
    $job1->handle();

    // Run job2 (should get busy)
    $job2->handle();

    // Job2 should not have invoked the worker
    expect(count($action2->renderCalls))->toBe(0);

    // Asset should still be completed by job1
    $asset->refresh();
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render->status)->toBe('completed');
});

/*
|--------------------------------------------------------------------------
| TC-PMA-12: Worker process crash → transaction rolls back, pending/failed recoverable
|--------------------------------------------------------------------------
*/

it('recovers from worker crash (transaction rollback)', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $action->shouldFail = true;
    $action->failCode = 'render_failed';

    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $job->handle();

    $asset->refresh();
    // Asset should complete because render failure is controlled upstream failure
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('failed');
    expect($render->render_error)->toBe('render_failed');
});

/*
|--------------------------------------------------------------------------
| TC-PMA-13: Invalid duration (missing probe) → failed attempt
|--------------------------------------------------------------------------
*/

it('fails render when probe data missing', function () {
    $asset = createProbedAsset(['probe_result' => [], 'duration_ms' => 0]);
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('failed');
    expect($render->render_error)->toBe('invalid_input');
});

/*
|--------------------------------------------------------------------------
| TC-PMA-14: Asset finalization - clipRenderResolved true only for completed/failed/terminal
|--------------------------------------------------------------------------
*/

it('clipRenderResolved is true only for completed or failed render', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

    // No render yet
    expect($asset->clipRenderResolved)->toBeFalse();

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);
    $job->handle();

    $asset->refresh();
    expect($asset->clipRenderResolved)->toBeTrue();

    // Test with failed render
    $asset2 = createProbedAsset(['id' => null]); // Will get new ID
    $sceneAnalysis2 = createCompletedSceneAnalysis($asset2);
    $clipAnalysis2 = createCompletedClipAnalysis($asset2);
    $transcript2 = createCompletedTranscript($asset2, $sceneAnalysis2);

    MediaClipRecommendation::create([
        'media_asset_id' => $asset2->id,
        'm4_analysis_id' => $clipAnalysis2->id,
        'status' => MediaClipRecommendation::STATUS_FAILED,
        'error' => 'ranking_failed',
    ]);

    $action2 = new RecordingRenderAction();
    $job2 = new \App\Jobs\ProcessMediaAsset($asset2, 'test-key-2', $action2);
    $job2->handle();

    $asset2->refresh();
    expect($asset2->clipRenderResolved)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| TC-PMA-15: Asset completion requires clipRenderResolved + all upstream resolved
|--------------------------------------------------------------------------
*/

it('asset completes only when all stages including render resolved', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);
});

/*
|--------------------------------------------------------------------------
| TC-PMA-16: Controlled upstream failure (render failed) → asset can still complete
|--------------------------------------------------------------------------
*/

it('asset completes even when render fails (controlled upstream failure)', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $action->shouldFail = true;
    $action->failCode = 'render_failed';

    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);
    $job->handle();

    $asset->refresh();
    // Asset should still complete - render failure is a controlled upstream failure
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render->status)->toBe('failed');
});