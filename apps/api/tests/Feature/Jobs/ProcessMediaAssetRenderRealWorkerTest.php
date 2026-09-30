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
| Real worker integration tests
| Requires FFmpeg fixture and real worker subprocess
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
| TC-PMR-01: Full job with real worker subprocess, FFmpeg fixture
|--------------------------------------------------------------------------
*/

it('completes full job with real worker and FFmpeg fixture', function () {
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
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key-real-worker', $action);

    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('completed');
});

/*
|--------------------------------------------------------------------------
| TC-PMR-02: Output file exists on configured disk at expected key
|--------------------------------------------------------------------------
*/

it('output file exists in storage at expected key', function () {
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available');
    }

    $fixturePath = base_path('services/worker/tests/fixtures/render_source.mp4');
    if (!file_exists($fixturePath)) {
        $this->markTestSkipped('FFmpeg fixture video not available');
    }

    $asset = createProbedAssetForRenderReal();
    $sceneAnalysis = createCompletedSceneAnalysisReal($asset);
    $clipAnalysis = createCompletedClipAnalysisReal($asset);
    $transcript = createCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

    $storagePath = 'projects/1/assets/'.$asset->id.'/source.mp4';
    Storage::disk('media')->put($storagePath, file_get_contents($fixturePath));

    $action = app(ProcessMediaAction::class);
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key-storage', $action);

    $job->handle();

    $asset->refresh();
    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('completed');

    // Check output file exists
    expect(Storage::disk($render->storage_disk)->exists($render->storage_key))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| TC-PMR-03: DerivedAsset render_columns populated (configuration, parameters)
|--------------------------------------------------------------------------
*/

it('DerivedAsset render columns populated with configuration and parameters', function () {
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available');
    }

    $fixturePath = base_path('services/worker/tests/fixtures/render_source.mp4');
    if (!file_exists($fixturePath)) {
        $this->markTestSkipped('FFmpeg fixture video not available');
    }

    $asset = createProbedAssetForRenderReal();
    $sceneAnalysis = createCompletedSceneAnalysisReal($asset);
    $clipAnalysis = createCompletedClipAnalysisReal($asset);
    $transcript = createCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

    $storagePath = 'projects/1/assets/'.$asset->id.'/source.mp4';
    Storage::disk('media')->put($storagePath, file_get_contents($fixturePath));

    $action = app(ProcessMediaAction::class);
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key-columns', $action);

    $job->handle();

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();

    // Check render_configuration is populated
    expect($render->render_configuration)->toBeArray()
        ->and($render->render_configuration)->toHaveKeys([
            'target_width', 'target_height', 'target_fps',
            'video_codec', 'video_bitrate_kbps',
            'audio_codec', 'audio_bitrate_kbps',
        ]);

    // Check render_parameters is populated with all required keys
    expect($render->render_parameters)->toBeArray()
        ->and($render->render_parameters)->toHaveKeys([
            'configuration', 'source_media', 'ffmpeg_version',
            'filter_graph', 'limits',
        ]);

    // Verify configuration matches profile
    expect($render->render_configuration)->toBe(\App\Services\RenderProfile::configuration());
});

/*
|--------------------------------------------------------------------------
| TC-PMR-04: DerivedAsset output metadata matches probe (duration, resolution, codecs)
|--------------------------------------------------------------------------
 */

it('DerivedAsset output metadata matches probe within tolerance', function () {
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available');
    }

    $fixturePath = base_path('services/worker/tests/fixtures/render_source.mp4');
    if (!file_exists($fixturePath)) {
        $this->markTestSkipped('FFmpeg fixture video not available');
    }

    $asset = createProbedAssetForRenderReal();
    $sceneAnalysis = createCompletedSceneAnalysisReal($asset);
    $clipAnalysis = createCompletedClipAnalysisReal($asset);
    $transcript = createCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

    $storagePath = 'projects/1/assets/'.$asset->id.'/source.mp4';
    Storage::disk('media')->put($storagePath, file_get_contents($fixturePath));

    $action = app(ProcessMediaAction::class);
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key-metadata', $action);

    $job->handle();

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->status)->toBe('completed');

    // Resolution matches configuration
    expect($render->width)->toBe(1080);
    expect($render->height)->toBe(1920);

    // Duration within 5% of expected (10000ms)
    $expectedDuration = 10000;
    $tolerance = $expectedDuration * 0.05; // 5%
    expect(abs($render->duration_ms - $expectedDuration))->toBeLessThanOrEqual($tolerance);

    // Codecs
    expect($render->codec)->toBe('libx264');

    // Size > 0
    expect($render->size_bytes)->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| TC-PMR-05: Filter graph in parameters is non-empty
|--------------------------------------------------------------------------
 */

it('filter_graph in parameters is non-empty', function () {
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available');
    }

    $fixturePath = base_path('services/worker/tests/fixtures/render_source.mp4');
    if (!file_exists($fixturePath)) {
        $this->markTestSkipped('FFmpeg fixture video not available');
    }

    $asset = createProbedAssetForRenderReal();
    $sceneAnalysis = createCompletedSceneAnalysisReal($asset);
    $clipAnalysis = createCompletedClipAnalysisReal($asset);
    $transcript = createCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

    $storagePath = 'projects/1/assets/'.$asset->id.'/source.mp4';
    Storage::disk('media')->put($storagePath, file_get_contents($fixturePath));

    $action = app(ProcessMediaAction::class);
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key-filtergraph', $action);

    $job->handle();

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->not->toBeNull();

    $filterGraph = $render->render_parameters['filter_graph'] ?? '';
    expect($filterGraph)->toBeString()
        ->and($filterGraph)->not->toBeEmpty()
        ->and($filterGraph)->toContain('crop=')
        ->and($filterGraph)->toContain('scale=')
        ->and($filterGraph)->toContain('pad=')
        ->and($filterGraph)->toContain('fps=');
});