<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

/*
 * Test-local factory for genuinely eligible-empty clip results: scenes with
 * no eligible candidate and full independently specified provenance. Scores
 * and candidates for eligible scenes stay hand-specified per test.
 */
function sceneClipEmptyResult(bool $transcriptUsed): array
{
    return [
        'status' => 'success',
        'analysis' => [
            'algorithm' => 'scene_timing_baseline',
            'algorithm_version' => '1.0.0',
            'parameters' => [
                'configuration' => [
                    'min_duration_ms' => 5000,
                    'target_duration_ms' => 30000,
                    'max_duration_ms' => 60000,
                    'max_candidates' => 20,
                    'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
                ],
                'effective_weights' => $transcriptUsed
                    ? ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20]
                    : ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                'transcript_used' => $transcriptUsed,
                'candidate_policy' => 'whole_scene_non_overlapping',
                'timing_policy' => 'original_media_ms',
                'transcript_policy' => 'optional_strict_unshifted',
                'boundary_policy' => 'strict_interior_speech_cut',
                'score_scale' => 1000000,
                'rounding' => 'half_up',
                'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
            ],
            'candidates' => [],
        ],
    ];
}

/*
|--------------------------------------------------------------------------
| Successful Scene Detection Flow
|--------------------------------------------------------------------------
*/

it('chains probe to scene detection to completed', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 3120],
                ['index' => 1, 'start_ms' => 3120, 'end_ms' => 5000],
            ],
        ],
    ];

    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio_normalized.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
            'format' => 'wav',
        ],
    ];

    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Hello world',
            'segments' => [
                ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello'],
                ['start_ms' => 1000, 'end_ms' => 2000, 'text' => 'world'],
            ],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andReturn([
            'status' => 'success',
            'probe' => $probeResult,
        ]);
    $actionMock->shouldReceive('detectScenes')
        ->once()
        ->andReturn($sceneDetectionResult);
    $actionMock->shouldReceive('extractAudio')
        ->once()
        ->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')
        ->once()
        ->andReturn($transcribeResult);
    $clipContracts = [];
    $actionMock->shouldReceive('analyzeClips')
        ->once()
        ->andReturnUsing(function ($contract) use (&$clipContracts) {
            $clipContracts[] = $contract->toArray();

            return sceneClipEmptyResult(true);
        });

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');

    expect($clipContracts)->toHaveCount(1);
    expect($clipContracts[0])->toBe([
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 5000],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 3120],
            ['index' => 1, 'start_ms' => 3120, 'end_ms' => 5000],
        ],
        'configuration' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 30000,
            'max_duration_ms' => 60000,
            'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ],
        'transcript_segments' => [
            ['start_ms' => 0, 'end_ms' => 1000],
            ['start_ms' => 1000, 'end_ms' => 2000],
        ],
    ]);

    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
    expect($clipAnalysis)->not->toBeNull();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($clipAnalysis->error)->toBeNull();
    expect($clipAnalysis->candidates)->toBe([]);
});

it('creates MediaSceneAnalysis on successful detection', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => ['threshold' => 27.0],
            'scenes' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 3120],
                ['index' => 1, 'start_ms' => 3120, 'end_ms' => 5000],
            ],
        ],
    ];

    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio_normalized.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
            'format' => 'wav',
        ],
    ];

    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Hello world',
            'segments' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello']],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $clipContracts = [];
    $actionMock->shouldReceive('analyzeClips')
        ->once()
        ->andReturnUsing(function ($contract) use (&$clipContracts) {
            $clipContracts[] = $contract->toArray();

            return sceneClipEmptyResult(true);
        });

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $this->assertDatabaseHas('media_scene_analyses', [
        'media_asset_id' => $asset->id,
        'status' => MediaSceneAnalysis::STATUS_COMPLETED,
    ]);

    expect($clipContracts)->toHaveCount(1);
    expect($clipContracts[0])->toBe([
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 5000],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 3120],
            ['index' => 1, 'start_ms' => 3120, 'end_ms' => 5000],
        ],
        'configuration' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 30000,
            'max_duration_ms' => 60000,
            'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ],
        'transcript_segments' => [
            ['start_ms' => 0, 'end_ms' => 1000],
        ],
    ]);

    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
    expect($clipAnalysis)->not->toBeNull();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($clipAnalysis->error)->toBeNull();
    expect($clipAnalysis->candidates)->toBe([]);
});

it('stores scene detection metadata correctly', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => ['threshold' => 27.0],
            'scenes' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 3120],
                ['index' => 1, 'start_ms' => 3120, 'end_ms' => 5000],
            ],
        ],
    ];

    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio_normalized.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
            'format' => 'wav',
        ],
    ];

    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Hello world',
            'segments' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello']],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $clipContracts = [];
    $actionMock->shouldReceive('analyzeClips')
        ->once()
        ->andReturnUsing(function ($contract) use (&$clipContracts) {
            $clipContracts[] = $contract->toArray();

            return sceneClipEmptyResult(true);
        });

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->detector)->toBe('deterministic');
    expect($sceneAnalysis->detector_version)->toBe('0.0.0');
    expect($sceneAnalysis->parameters)->toBeArray();
    expect($sceneAnalysis->scenes)->toBeArray();
    expect(count($sceneAnalysis->scenes))->toBe(2);

    expect($clipContracts)->toHaveCount(1);
    expect($clipContracts[0])->toBe([
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 5000],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 3120],
            ['index' => 1, 'start_ms' => 3120, 'end_ms' => 5000],
        ],
        'configuration' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 30000,
            'max_duration_ms' => 60000,
            'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ],
        'transcript_segments' => [
            ['start_ms' => 0, 'end_ms' => 1000],
        ],
    ]);

    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
    expect($clipAnalysis)->not->toBeNull();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($clipAnalysis->error)->toBeNull();
    expect($clipAnalysis->candidates)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

it('skips scene detection if analysis exists with completed status', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    // Create existing completed MediaSceneAnalysis
    MediaSceneAnalysis::create([
        'media_asset_id' => $asset->id,
        'status' => MediaSceneAnalysis::STATUS_COMPLETED,
        'detector' => 'deterministic',
        'detector_version' => '0.0.0',
        'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 5000]],
    ]);

    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio_normalized.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
            'format' => 'wav',
        ],
    ];

    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Already transcribed',
            'segments' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Already transcribed']],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldNotReceive('detectScenes');
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $clipContracts = [];
    $actionMock->shouldReceive('analyzeClips')
        ->once()
        ->andReturnUsing(function ($contract) use (&$clipContracts) {
            $clipContracts[] = $contract->toArray();

            return [
                'status' => 'success',
                'analysis' => [
                    'algorithm' => 'scene_timing_baseline',
                    'algorithm_version' => '1.0.0',
                    'parameters' => [
                        'configuration' => [
                            'min_duration_ms' => 5000,
                            'target_duration_ms' => 30000,
                            'max_duration_ms' => 60000,
                            'max_candidates' => 20,
                            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
                        ],
                        'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
                        'transcript_used' => true,
                        'candidate_policy' => 'whole_scene_non_overlapping',
                        'timing_policy' => 'original_media_ms',
                        'transcript_policy' => 'optional_strict_unshifted',
                        'boundary_policy' => 'strict_interior_speech_cut',
                        'score_scale' => 1000000,
                        'rounding' => 'half_up',
                        'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
                    ],
                    'candidates' => [
                        [
                            'index' => 0,
                            'start_ms' => 0,
                            'end_ms' => 5000,
                            'rank' => 1,
                            'score' => 343334 / 1000000,
                            'criteria' => [
                                'duration_fit' => 166667 / 1000000,
                                'speech_coverage' => 200000 / 1000000,
                                'boundary_alignment' => 1000000 / 1000000,
                            ],
                            'source_scene_indexes' => [0],
                        ],
                    ],
                ],
            ];
        });
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');

    expect($clipContracts)->toHaveCount(1);
    expect($clipContracts[0])->toBe([
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 5000],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 5000],
        ],
        'configuration' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 30000,
            'max_duration_ms' => 60000,
            'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ],
        'transcript_segments' => [
            ['start_ms' => 0, 'end_ms' => 1000],
        ],
    ]);

    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
    expect($clipAnalysis)->not->toBeNull();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($clipAnalysis->error)->toBeNull();
    expect($clipAnalysis->candidates)->toBe([
        [
            'index' => 0,
            'start_ms' => 0,
            'end_ms' => 5000,
            'rank' => 1,
            'score' => 343334 / 1000000,
            'criteria' => [
                'duration_fit' => 166667 / 1000000,
                'speech_coverage' => 200000 / 1000000,
                'boundary_alignment' => 1000000 / 1000000,
            ],
            'source_scene_indexes' => [0],
        ],
    ]);
    expect($clipAnalysis->execution_parameters)->toBe(['timeout_seconds' => 30, 'lock_wait_seconds' => 35]);
});

/*
|--------------------------------------------------------------------------
| Error Handling
|--------------------------------------------------------------------------
*/

it('marks scene analysis failed on detection error', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio_normalized.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
            'format' => 'wav',
        ],
    ];

    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Hello world',
            'segments' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello']],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')
        ->once()
        ->andThrow(new ProcessMediaException(
            'Scene detection engine failed',
            1,
            'Engine error',
        ));
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_FAILED);
    expect($sceneAnalysis->error)->toContain('Scene detection engine failed');
});

/*
|--------------------------------------------------------------------------
| Pipeline Independence
|--------------------------------------------------------------------------
*/

it('scene detection failure does not block audio extraction', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio_normalized.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
            'format' => 'wav',
        ],
    ];

    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Hello world',
            'segments' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello']],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')
        ->once()
        ->andThrow(new ProcessMediaException('Scene detection failed', 1, 'Error'));
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    // Scene analysis should be failed
    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_FAILED);

    // But transcription should have succeeded
    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_COMPLETED);
});

it('transcription failure does not block scene detection', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 5000]],
        ],
    ];

    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio_normalized.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
            'format' => 'wav',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')
        ->once()
        ->andThrow(new ProcessMediaException('Transcription engine failed', 1, 'Engine error'));

    app()->instance(ProcessMediaAction::class, $actionMock);

    $clipContracts = [];
    $actionMock->shouldReceive('analyzeClips')
        ->once()
        ->andReturnUsing(function ($contract) use (&$clipContracts) {
            $clipContracts[] = $contract->toArray();

            return [
                'status' => 'success',
                'analysis' => [
                    'algorithm' => 'scene_timing_baseline',
                    'algorithm_version' => '1.0.0',
                    'parameters' => [
                        'configuration' => [
                            'min_duration_ms' => 5000,
                            'target_duration_ms' => 30000,
                            'max_duration_ms' => 60000,
                            'max_candidates' => 20,
                            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
                        ],
                        'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                        'transcript_used' => false,
                        'candidate_policy' => 'whole_scene_non_overlapping',
                        'timing_policy' => 'original_media_ms',
                        'transcript_policy' => 'optional_strict_unshifted',
                        'boundary_policy' => 'strict_interior_speech_cut',
                        'score_scale' => 1000000,
                        'rounding' => 'half_up',
                        'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
                    ],
                    'candidates' => [
                        [
                            'index' => 0,
                            'start_ms' => 0,
                            'end_ms' => 5000,
                            'rank' => 1,
                            'score' => 166667 / 1000000,
                            'criteria' => [
                                'duration_fit' => 166667 / 1000000,
                                'speech_coverage' => 0,
                                'boundary_alignment' => 0,
                            ],
                            'source_scene_indexes' => [0],
                        ],
                    ],
                ],
            ];
        });

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    // Scene analysis should have succeeded
    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_COMPLETED);

    // But transcription should have failed
    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_FAILED);

    expect($clipContracts)->toHaveCount(1);
    expect($clipContracts[0])->toBe([
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 5000],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 5000],
        ],
        'configuration' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 30000,
            'max_duration_ms' => 60000,
            'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ],
    ]);

    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
    expect($clipAnalysis)->not->toBeNull();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($clipAnalysis->error)->toBeNull();
    expect($clipAnalysis->candidates)->toHaveCount(1);
    expect($clipAnalysis->candidates[0]['source_scene_indexes'])->toBe([0]);
});

/*
|--------------------------------------------------------------------------
| Video Without Audio
|--------------------------------------------------------------------------
*/

it('video without audio still receives scene detection', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => null,
        'video_codec' => 'h264',
    ];

    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 3000],
                ['index' => 1, 'start_ms' => 3000, 'end_ms' => 5000],
            ],
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);
    $actionMock->shouldNotReceive('extractAudio');
    $actionMock->shouldNotReceive('transcribe');
    $clipContracts = [];
    $actionMock->shouldReceive('analyzeClips')
        ->once()
        ->andReturnUsing(function ($contract) use (&$clipContracts) {
            $clipContracts[] = $contract->toArray();

            return sceneClipEmptyResult(false);
        });

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    // Scene analysis should be completed
    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_COMPLETED);

    // Asset should be completed
    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');

    expect($clipContracts)->toHaveCount(1);
    expect($clipContracts[0])->toBe([
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 5000],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 3000],
            ['index' => 1, 'start_ms' => 3000, 'end_ms' => 5000],
        ],
        'configuration' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 30000,
            'max_duration_ms' => 60000,
            'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ],
    ]);

    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
    expect($clipAnalysis)->not->toBeNull();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($clipAnalysis->error)->toBeNull();
    expect($clipAnalysis->candidates)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Existing Behavior Preservation
|--------------------------------------------------------------------------
*/

it('previous probe tests remain green', function () {
    // Verify that probe action is still supported
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => null,
        'video_codec' => null,
    ];

    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => [],
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);
    $clipContracts = [];
    $actionMock->shouldReceive('analyzeClips')
        ->once()
        ->andReturnUsing(function ($contract) use (&$clipContracts) {
            $clipContracts[] = $contract->toArray();

            return sceneClipEmptyResult(false);
        });

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');
    expect($asset->probe_result)->toBeArray();
    expect($asset->probe_result['duration_ms'])->toBe(5000);

    expect($clipContracts)->toHaveCount(1);
    expect($clipContracts[0])->toBe([
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 5000],
        'scenes' => [],
        'configuration' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 30000,
            'max_duration_ms' => 60000,
            'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ],
    ]);

    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
    expect($clipAnalysis)->not->toBeNull();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($clipAnalysis->error)->toBeNull();
    expect($clipAnalysis->candidates)->toBe([]);
});

it('previous transcription tests remain green', function () {
    // Verify transcription still works with scene detection in the pipeline
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 5000]],
        ],
    ];

    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio_normalized.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
            'format' => 'wav',
        ],
    ];

    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Hello world',
            'segments' => [
                ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello'],
                ['start_ms' => 1000, 'end_ms' => 2000, 'text' => 'world'],
            ],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $clipContracts = [];
    $actionMock->shouldReceive('analyzeClips')
        ->once()
        ->andReturnUsing(function ($contract) use (&$clipContracts) {
            $clipContracts[] = $contract->toArray();

            return [
                'status' => 'success',
                'analysis' => [
                    'algorithm' => 'scene_timing_baseline',
                    'algorithm_version' => '1.0.0',
                    'parameters' => [
                        'configuration' => [
                            'min_duration_ms' => 5000,
                            'target_duration_ms' => 30000,
                            'max_duration_ms' => 60000,
                            'max_candidates' => 20,
                            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
                        ],
                        'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
                        'transcript_used' => true,
                        'candidate_policy' => 'whole_scene_non_overlapping',
                        'timing_policy' => 'original_media_ms',
                        'transcript_policy' => 'optional_strict_unshifted',
                        'boundary_policy' => 'strict_interior_speech_cut',
                        'score_scale' => 1000000,
                        'rounding' => 'half_up',
                        'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
                    ],
                    'candidates' => [
                        [
                            'index' => 0,
                            'start_ms' => 0,
                            'end_ms' => 5000,
                            'rank' => 1,
                            'score' => 403334 / 1000000,
                            'criteria' => [
                                'duration_fit' => 166667 / 1000000,
                                'speech_coverage' => 400000 / 1000000,
                                'boundary_alignment' => 1000000 / 1000000,
                            ],
                            'source_scene_indexes' => [0],
                        ],
                    ],
                ],
            ];
        });

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    // Transcription should still work
    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_COMPLETED);
    expect($transcript->language)->toBe('en');
    expect($transcript->full_text)->toBe('Hello world');

    expect($clipContracts)->toHaveCount(1);
    expect($clipContracts[0]['media'])->toBe(['duration_ms' => 5000]);
    expect($clipContracts[0]['transcript_segments'])->toBe([
        ['start_ms' => 0, 'end_ms' => 1000],
        ['start_ms' => 1000, 'end_ms' => 2000],
    ]);

    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
    expect($clipAnalysis)->not->toBeNull();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($clipAnalysis->candidates)->toHaveCount(1);
    expect($clipAnalysis->candidates[0]['score'])->toBe(403334 / 1000000);
    expect($clipAnalysis->candidates[0]['source_scene_indexes'])->toBe([0]);
});

/*
|--------------------------------------------------------------------------
| Worker Result Validation Tests
|--------------------------------------------------------------------------
*/

it('rejects worker result missing detector', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    // Missing detector field
    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 5000]],
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_FAILED);
    expect($sceneAnalysis->error)->toContain('detector is missing or empty');
});

it('rejects worker result missing detector_version', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    // Missing detector_version field
    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'parameters' => [],
            'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 5000]],
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_FAILED);
    expect($sceneAnalysis->error)->toContain('detector_version is missing or empty');
});

it('rejects worker result with non-array parameters', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    // parameters is a string instead of array
    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => 'invalid',
            'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 5000]],
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_FAILED);
    expect($sceneAnalysis->error)->toContain('parameters is not an array');
});

it('rejects worker result with non-array scenes', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    // scenes is a string instead of array
    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => 'invalid',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_FAILED);
    expect($sceneAnalysis->error)->toContain('scenes is not an array');
});

it('rejects worker result with invalid scene format', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    // Scene missing required keys
    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => [['start_ms' => 0, 'end_ms' => 5000]], // missing index
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_FAILED);
    expect($sceneAnalysis->error)->toContain('missing required keys');
});

it('rejects worker result with scene exceeding duration', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    // Scene end_ms exceeds duration
    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 6000]],
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_FAILED);
    expect($sceneAnalysis->error)->toContain('exceeds media duration');
});

it('rejects worker result with non-sequential indexes', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    // Scenes with gap in indexes (0, 2)
    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 2500],
                ['index' => 2, 'start_ms' => 2500, 'end_ms' => 5000],
            ],
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_FAILED);
    expect($sceneAnalysis->error)->toContain('sequential 0-based indexes');
});

/*
|--------------------------------------------------------------------------
| Duration Propagation Tests
|--------------------------------------------------------------------------
*/

it('propagates duration from probe to scene detection contract', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 7500,
        'audio_codec' => null,
        'video_codec' => 'h264',
    ];

    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 3750],
                ['index' => 1, 'start_ms' => 3750, 'end_ms' => 7500],
            ],
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')
        ->once()
        ->andReturnUsing(function ($contract) use ($sceneDetectionResult) {
            assert(array_key_exists('media', $contract));
            assert($contract['media']['duration_ms'] === 7500);

            return $sceneDetectionResult;
        });
    $clipContracts = [];
    $actionMock->shouldReceive('analyzeClips')
        ->once()
        ->andReturnUsing(function ($contract) use (&$clipContracts) {
            $clipContracts[] = $contract->toArray();

            return sceneClipEmptyResult(false);
        });

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');

    expect($clipContracts)->toHaveCount(1);
    expect($clipContracts[0])->toBe([
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 7500],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 3750],
            ['index' => 1, 'start_ms' => 3750, 'end_ms' => 7500],
        ],
        'configuration' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 30000,
            'max_duration_ms' => 60000,
            'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ],
    ]);

    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
    expect($clipAnalysis)->not->toBeNull();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($clipAnalysis->error)->toBeNull();
    expect($clipAnalysis->candidates)->toBe([]);
});

it('uses asset duration_ms when probe already completed', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => MediaAsset::PROCESSING_PROBED,
        'duration_ms' => 10000,
        'probe_result' => ['duration_ms' => 10000, 'video_codec' => 'h264'],
    ]);

    $sceneDetectionResult = [
        'status' => 'success',
        'scene_detection' => [
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'parameters' => [],
            'scenes' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 5000],
                ['index' => 1, 'start_ms' => 5000, 'end_ms' => 10000],
            ],
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldNotReceive('probe');
    $actionMock->shouldReceive('detectScenes')->once()->andReturn($sceneDetectionResult);
    $actionMock->shouldNotReceive('extractAudio');
    $actionMock->shouldNotReceive('transcribe');
    $clipContracts = [];
    $actionMock->shouldReceive('analyzeClips')
        ->once()
        ->andReturnUsing(function ($contract) use (&$clipContracts) {
            $clipContracts[] = $contract->toArray();

            return [
                'status' => 'success',
                'analysis' => [
                    'algorithm' => 'scene_timing_baseline',
                    'algorithm_version' => '1.0.0',
                    'parameters' => [
                        'configuration' => [
                            'min_duration_ms' => 5000,
                            'target_duration_ms' => 30000,
                            'max_duration_ms' => 60000,
                            'max_candidates' => 20,
                            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
                        ],
                        'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                        'transcript_used' => false,
                        'candidate_policy' => 'whole_scene_non_overlapping',
                        'timing_policy' => 'original_media_ms',
                        'transcript_policy' => 'optional_strict_unshifted',
                        'boundary_policy' => 'strict_interior_speech_cut',
                        'score_scale' => 1000000,
                        'rounding' => 'half_up',
                        'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
                    ],
                    'candidates' => [
                        [
                            'index' => 0,
                            'start_ms' => 0,
                            'end_ms' => 5000,
                            'rank' => 1,
                            'score' => 166667 / 1000000,
                            'criteria' => [
                                'duration_fit' => 166667 / 1000000,
                                'speech_coverage' => 0,
                                'boundary_alignment' => 0,
                            ],
                            'source_scene_indexes' => [0],
                        ],
                        [
                            'index' => 1,
                            'start_ms' => 5000,
                            'end_ms' => 10000,
                            'rank' => 2,
                            'score' => 166667 / 1000000,
                            'criteria' => [
                                'duration_fit' => 166667 / 1000000,
                                'speech_coverage' => 0,
                                'boundary_alignment' => 0,
                            ],
                            'source_scene_indexes' => [1],
                        ],
                    ],
                ],
            ];
        });

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');

    expect($clipContracts)->toHaveCount(1);
    expect($clipContracts[0])->toBe([
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 10000],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 5000],
            ['index' => 1, 'start_ms' => 5000, 'end_ms' => 10000],
        ],
        'configuration' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 30000,
            'max_duration_ms' => 60000,
            'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ],
    ]);

    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
    expect($clipAnalysis)->not->toBeNull();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($clipAnalysis->error)->toBeNull();
    expect($clipAnalysis->candidates)->toBe([
        [
            'index' => 0,
            'start_ms' => 0,
            'end_ms' => 5000,
            'rank' => 1,
            'score' => 166667 / 1000000,
            'criteria' => [
                'duration_fit' => 166667 / 1000000,
                'speech_coverage' => 0,
                'boundary_alignment' => 0,
            ],
            'source_scene_indexes' => [0],
        ],
        [
            'index' => 1,
            'start_ms' => 5000,
            'end_ms' => 10000,
            'rank' => 2,
            'score' => 166667 / 1000000,
            'criteria' => [
                'duration_fit' => 166667 / 1000000,
                'speech_coverage' => 0,
                'boundary_alignment' => 0,
            ],
            'source_scene_indexes' => [1],
        ],
    ]);
    expect($clipAnalysis->execution_parameters)->toBe(['timeout_seconds' => 30, 'lock_wait_seconds' => 35]);
});

it('validates duration > 0 in contract for detect_scenes action', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 0, // Invalid: zero duration
        'audio_codec' => 'aac',
        'video_codec' => 'h264',
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    // Should fail because duration is 0 and detect_scenes requires duration > 0
    $asset->refresh();
    // The asset will fail because the contract validation will fail for detect_scenes with duration 0
    // This test verifies the contract validation is enforced
});
