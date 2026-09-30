<?php

namespace Tests\Feature\Jobs;

use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipRecommendation;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ClipRankingProfile;
use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Real worker integration tests - RenderMediaClip job with real FFmpeg
|--------------------------------------------------------------------------
*/

function createProbedAssetForRenderReal(array $overrides = []): MediaAsset
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

function createCompletedSceneAnalysisReal(MediaAsset $asset): MediaSceneAnalysis
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

function createCompletedClipAnalysisReal(MediaAsset $asset): MediaClipAnalysis
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

function createCompletedTranscriptReal(MediaAsset $asset, MediaSceneAnalysis $sceneAnalysis): MediaTranscript
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

function createCompletedRecommendationReal(MediaAsset $asset, MediaClipAnalysis $clipAnalysis, MediaTranscript $transcript): MediaClipRecommendation
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
| TC-RMR-01 through TC-RMR-07: RenderMediaClip with real worker
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| TC-RMR-01: Full job with real worker subprocess, FFmpeg fixture
|--------------------------------------------------------------------------
*/

it('renders clip with real worker subprocess and FFmpeg fixture', function () {
    // Skip if FFmpeg not available
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available in test environment');
    }

    $asset = createProbedAssetForRenderReal();
    $sceneAnalysis = createCompletedSceneAnalysisReal($asset);
    $clipAnalysis = createCompletedClipAnalysisReal($asset);
    $transcript = createCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

    // Create test fixture video if needed
    $fixturePath = base_path('services/worker/tests/fixtures/render_source.mp4');
    if (!file_exists($fixturePath)) {
        $this->markTestSkipped('FFmpeg fixture video not available');
    }

    // Copy fixture to storage for this test
    $storagePath = 'projects/1/assets/'.$asset->id.'/source.mp4';
    Storage::disk('media')->put($storagePath, file_get_contents($fixturePath));

    $action = app(ProcessMediaAction::class);
    $job = new \App\Jobs\RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect($result)->not->toBeNull();
    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
    expect($result->candidate_index)->toBe(0);
    expect($result->render_profile_version)->toBe('vertical_v1');
});

/*
|--------------------------------------------------------------------------
| TC-RMR-02: Output file exists on configured disk at expected key
|--------------------------------------------------------------------------
 */

it('output file exists on configured disk at expected key', function () {
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available in test environment');
    }

    $asset = createProbedAssetForRenderReal();
    $sceneAnalysis = createCompletedSceneAnalysisReal($asset);
    $clipAnalysis = createCompletedClipAnalysisReal($asset);
    $transcript = createCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

    $fixturePath = base_path('services/worker/tests/fixtures/render_source.mp4');
    if (!file_exists($fixturePath)) {
        $this->markTestSkipped('FFmpeg fixture video not available');
    }

    $storagePath = 'projects/1/assets/'.$asset->id.'/source.mp4';
    Storage::disk('media')->put($storagePath, file_get_contents($fixturePath));

    $action = app(ProcessMediaAction::class);
    $job = new \App\Jobs\RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect($result)->not->toBeNull();
    expect(Storage::disk('media')->exists($result->storage_key))->toBeTrue();
    expect($result->storage_key)->toMatch('/^projects\/\d+\/renders\/\d+\/0_\d{8}T\d{6}Z\.mp4$/');
});

/*
|--------------------------------------------------------------------------
| TC-RMR-03: DerivedAsset render_columns populated
|--------------------------------------------------------------------------
 */

it('DerivedAsset render_columns populated correctly', function () {
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available in test environment');
    }

    $asset = createProbedAssetForRenderReal();
    $sceneAnalysis = createCompletedSceneAnalysisReal($asset);
    $clipAnalysis = createCompletedClipAnalysisReal($asset);
    $transcript = createCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

    $fixturePath = base_path('services/worker/tests/fixtures/render_source.mp4');
    if (!file_exists($fixturePath)) {
        $this->markTestSkipped('FFmpeg fixture video not available');
    }

    $storagePath = 'projects/1/assets/'.$asset->id.'/source.mp4';
    Storage::disk('media')->put($storagePath, file_get_contents($fixturePath));

    $action = app(ProcessMediaAction::class);
    $job = new \App\Jobs\RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect($result->render_configuration)->toBeArray();
    expect($result->render_parameters)->toBeArray();
    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
    expect($result->render_started_at)->not->toBeNull();
    expect($result->render_completed_at)->not->toBeNull();
    expect($result->render_error)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| TC-RMR-04: DerivedAsset output metadata matches probe (±50ms)
|--------------------------------------------------------------------------
 */

it('DerivedAsset output metadata matches probe within ±50ms', function () {
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available in test environment');
    }

    $asset = createProbedAssetForRenderReal();
    $sceneAnalysis = createCompletedSceneAnalysisReal($asset);
    $clipAnalysis = createCompletedClipAnalysisReal($asset);
    $transcript = createCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

    $fixturePath = base_path('services/worker/tests/fixtures/render_source.mp4');
    if (!file_exists($fixturePath)) {
        $this->markTestSkipped('FFmpeg fixture video not available');
    }

    $storagePath = 'projects/1/assets/'.$asset->id.'/source.mp4';
    Storage::disk('media')->put($storagePath, file_get_contents($fixturePath));

    $action = app(ProcessMediaAction::class);
    $job = new \App\Jobs\RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    // Expected duration: 10000ms (0-10000ms)
    // Tolerance: ±50ms
    expect($result->duration_ms)->toBeGreaterThanOrEqual(9950)
        ->and($result->duration_ms)->toBeLessThanOrEqual(10050);
    expect($result->width)->toBe(1080);
    expect($result->height)->toBe(1920);
    expect($result->codec)->toContain('264'); // libx264 or h264
});

/*
|--------------------------------------------------------------------------
| TC-RMR-05: Filter graph in parameters is non-empty
|--------------------------------------------------------------------------
 */

it('filter graph in parameters is non-empty', function () {
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available in test environment');
    }

    $asset = createProbedAssetForRenderReal();
    $sceneAnalysis = createCompletedSceneAnalysisReal($asset);
    $clipAnalysis = createCompletedClipAnalysisReal($asset);
    $transcript = createCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

    $fixturePath = base_path('services/worker/tests/fixtures/render_source.mp4');
    if (!file_exists($fixturePath)) {
        $this->markTestSkipped('FFmpeg fixture video not available');
    }

    $storagePath = 'projects/1/assets/'.$asset->id.'/source.mp4';
    Storage::disk('media')->put($storagePath, file_get_contents($fixturePath));

    $action = app(ProcessMediaAction::class);
    $job = new \App\Jobs\RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    $filterGraph = $result->render_parameters['filter_graph'] ?? '';
    expect($filterGraph)->toBeString()
        ->and($filterGraph)->not->toBeEmpty()
        ->and($filterGraph)->toContain('crop=')
        ->and($filterGraph)->toContain('scale=')
        ->and($filterGraph)->toContain('pad=')
        ->and($filterGraph)->toContain('fps=');
});

/*
|--------------------------------------------------------------------------
| TC-RMR-06: Different candidate_index produces distinct DerivedAsset row
|--------------------------------------------------------------------------
 */

it('different candidate_index produces distinct DerivedAsset row', function () {
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available in test environment');
    }

    $asset = createProbedAssetForRenderReal();
    $sceneAnalysis = createCompletedSceneAnalysisReal($asset);
    $clipAnalysis = createCompletedClipAnalysisReal($asset);
    $transcript = createCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

    $fixturePath = base_path('services/worker/tests/fixtures/render_source.mp4');
    if (!file_exists($fixturePath)) {
        $this->markTestSkipped('FFmpeg fixture video not available');
    }

    $storagePath = 'projects/1/assets/'.$asset->id.'/source.mp4';
    Storage::disk('media')->put($storagePath, file_get_contents($fixturePath));

    $action = app(ProcessMediaAction::class);

    // Render candidate 0
    $job1 = new \App\Jobs\RenderMediaClip($asset->id, $recommendation->id, 0, $action);
    $result1 = $job1->handle();

    // Render candidate 1
    $job2 = new \App\Jobs\RenderMediaClip($asset->id, $recommendation->id, 1, $action);
    $result2 = $job2->handle();

    expect($result1->id)->not->toBe($result2->id);
    expect($result1->candidate_index)->toBe(0);
    expect($result2->candidate_index)->toBe(1);
    expect($result1->storage_key)->toContain('0_');
    expect($result2->storage_key)->toContain('1_');
});

/*
|--------------------------------------------------------------------------
| TC-RMR-07: Worker response algorithm="vertical", algorithm_version="vertical_v1"
|--------------------------------------------------------------------------
 */

it('worker response algorithm is vertical and algorithm_version is vertical_v1', function () {
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available in test environment');
    }

    $asset = createProbedAssetForRenderReal();
    $sceneAnalysis = createCompletedSceneAnalysisReal($asset);
    $clipAnalysis = createCompletedClipAnalysisReal($asset);
    $transcript = createCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

    $fixturePath = base_path('services/worker/tests/fixtures/render_source.mp4');
    if (!file_exists($fixturePath)) {
        $this->markTestSkipped('FFmpeg fixture video not available');
    }

    $storagePath = 'projects/1/assets/'.$asset->id.'/source.mp4';
    Storage::disk('media')->put($storagePath, file_get_contents($fixturePath));

    $action = app(ProcessMediaAction::class);
    $job = new \App\Jobs\RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    $params = $result->render_parameters;
    expect($params['algorithm'])->toBe('vertical');
    expect($params['algorithm_version'])->toBe('vertical_v1');
});