<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
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

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');
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

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $this->assertDatabaseHas('media_scene_analyses', [
        'media_asset_id' => $asset->id,
        'status' => MediaSceneAnalysis::STATUS_COMPLETED,
    ]);
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

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $sceneAnalysis = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first();
    expect($sceneAnalysis)->not->toBeNull();
    expect($sceneAnalysis->detector)->toBe('deterministic');
    expect($sceneAnalysis->detector_version)->toBe('0.0.0');
    expect($sceneAnalysis->parameters)->toBeArray();
    expect($sceneAnalysis->scenes)->toBeArray();
    expect(count($sceneAnalysis->scenes))->toBe(2);
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
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');
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

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('detectScenes')
        ->once()
        ->andThrow(new ProcessMediaException(
            'Scene detection engine failed',
            1,
            'Engine error',
        ));

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

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');
    expect($asset->probe_result)->toBeArray();
    expect($asset->probe_result['duration_ms'])->toBe(5000);
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

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    // Transcription should still work
    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_COMPLETED);
    expect($transcript->language)->toBe('en');
    expect($transcript->full_text)->toBe('Hello world');
});
