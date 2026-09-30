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
| Real worker integration tests - REGRESSION: ProcessMediaAsset should NOT auto-render
|--------------------------------------------------------------------------
*/

function pmaCreateProbedAssetForRenderReal(array $overrides = []): MediaAsset
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

function pmaCreateCompletedSceneAnalysisReal(MediaAsset $asset): MediaSceneAnalysis
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

function pmaCreateCompletedClipAnalysisReal(MediaAsset $asset): MediaClipAnalysis
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

function pmaCreateCompletedTranscriptReal(MediaAsset $asset, MediaSceneAnalysis $sceneAnalysis): MediaTranscript
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

function pmaCreateCompletedRecommendationReal(MediaAsset $asset, MediaClipAnalysis $clipAnalysis, MediaTranscript $transcript): MediaClipRecommendation
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
| REGRESSION: ProcessMediaAsset should complete WITHOUT auto-render
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| TC-PMR-REG-01: ProcessMediaAsset completes with real worker but NO auto-render
|--------------------------------------------------------------------------
*/

it('completes asset with real worker but does NOT auto-render', function () {
    // Skip if FFmpeg not available
    $ffmpegCheck = exec('which ffmpeg');
    if (empty($ffmpegCheck)) {
        $this->markTestSkipped('FFmpeg not available in test environment');
    }

    $asset = pmaCreateProbedAssetForRenderReal();
    $sceneAnalysis = pmaCreateCompletedSceneAnalysisReal($asset);
    $clipAnalysis = pmaCreateCompletedClipAnalysisReal($asset);
    $transcript = pmaCreateCompletedTranscriptReal($asset, $sceneAnalysis);
    $recommendation = pmaCreateCompletedRecommendationReal($asset, $clipAnalysis, $transcript);

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
    // Asset should complete WITHOUT render
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    // NO render should exist
    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->toBeNull();
});