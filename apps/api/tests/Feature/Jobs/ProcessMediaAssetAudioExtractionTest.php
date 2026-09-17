<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
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
| Successful Audio Extraction Flow
|--------------------------------------------------------------------------
*/

it('chains probe to audio extraction on success', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
        'audio_channels' => 2,
        'audio_sample_rate' => 48000,
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
    $actionMock->shouldReceive('probe')
        ->once()
        ->andReturn([
            'status' => 'success',
            'probe' => $probeResult,
        ]);
    $actionMock->shouldReceive('extractAudio')
        ->once()
        ->andReturn($extractionResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');
});

it('creates DerivedAsset on successful extraction', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
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
            'segments' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello world']],
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
    $actionMock->shouldReceive('extractAudio')
        ->once()
        ->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')
        ->once()
        ->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $this->assertDatabaseHas('derived_assets', [
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
        'size_bytes' => 160000,
    ]);
});

it('stores extraction metadata in DerivedAsset', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
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
            'segments' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello world']],
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
    $actionMock->shouldReceive('extractAudio')
        ->once()
        ->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')
        ->once()
        ->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $derivedAsset = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_AUDIO_NORMALIZED)
        ->first();

    expect($derivedAsset)->not->toBeNull();
    expect($derivedAsset->duration_ms)->toBe(5000);
    expect($derivedAsset->sample_rate)->toBe(16000);
    expect($derivedAsset->channels)->toBe(1);
    expect($derivedAsset->codec)->toBe('pcm_s16le');
});

/*
|--------------------------------------------------------------------------
| No Audio Stream
|--------------------------------------------------------------------------
*/

it('marks completed when no audio stream exists', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => null,
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andReturn([
            'status' => 'success',
            'probe' => $probeResult,
        ]);
    $actionMock->shouldNotReceive('extractAudio');

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');
    $this->assertDatabaseMissing('derived_assets', [
        'media_asset_id' => $asset->id,
    ]);
});

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

it('skips extraction if DerivedAsset already exists', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    // Create existing DerivedAsset
    DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
        'storage_disk' => 'media',
        'storage_key' => 'existing/audio.wav',
        'mime_type' => 'audio/wav',
        'size_bytes' => 1024,
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
    ];

    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Hello world',
            'segments' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello world']],
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
    $actionMock->shouldNotReceive('extractAudio');
    $actionMock->shouldReceive('transcribe')
        ->once()
        ->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');
    $this->assertDatabaseCount('derived_assets', 1);
});

/*
|--------------------------------------------------------------------------
| Error Handling
|--------------------------------------------------------------------------
*/

it('marks failed on extraction error', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andReturn([
            'status' => 'success',
            'probe' => $probeResult,
        ]);
    $actionMock->shouldReceive('extractAudio')
        ->once()
        ->andThrow(new ProcessMediaException(
            'FFmpeg failed with exit code 1',
            1,
            'No audio stream found',
        ));

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('failed');
    expect($asset->processing_error)->toContain('FFmpeg failed');
});

it('marks failed on probe error', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andThrow(new ProcessMediaException(
            'FFprobe failed with exit code 1',
            1,
            'Invalid data',
        ));
    $actionMock->shouldNotReceive('extractAudio');

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');

    try {
        $job->handle();
    } catch (\Throwable $e) {
        $job->failed($e);
    }

    $asset->refresh();
    expect($asset->processing_status)->toBe('failed');
});

it('failure handler marks failed', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'processing',
    ]);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $exception = new \RuntimeException('Extraction failed');
    $job->failed($exception);

    $asset->refresh();
    expect($asset->processing_status)->toBe('failed');
    expect($asset->processing_error)->toBe('Extraction failed');
});

/*
|--------------------------------------------------------------------------
| State Transitions
|--------------------------------------------------------------------------
*/

it('valid transition probed to completed on extraction success', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'probed',
        'probe_result' => [
            'duration_ms' => 5000,
            'audio_codec' => 'aac',
        ],
        'duration_ms' => 5000,
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
            'full_text' => 'Hello world',
            'segments' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello world']],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldNotReceive('probe');
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
    expect($asset->processing_completed_at)->not->toBeNull();
});

it('valid transition probed to failed on extraction failure', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'probed',
        'probe_result' => [
            'duration_ms' => 5000,
            'audio_codec' => 'aac',
        ],
        'duration_ms' => 5000,
    ]);

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldNotReceive('probe');
    $actionMock->shouldReceive('extractAudio')
        ->once()
        ->andThrow(new ProcessMediaException(
            'FFmpeg failed',
            1,
            'Error output',
        ));

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('failed');
    expect($asset->processing_error)->toContain('FFmpeg failed');
});

it('invalid transition stored to completed', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $asset->markCompleted();

    $asset->refresh();
    expect($asset->processing_status)->toBe('stored');
});
