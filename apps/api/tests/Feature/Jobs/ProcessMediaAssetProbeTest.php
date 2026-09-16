<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
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
| Successful Probe Flow
|--------------------------------------------------------------------------
*/

it('invokes ProcessMediaAction on success', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 120000,
        'width' => 1920,
        'height' => 1080,
        'video_codec' => 'h264',
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andReturn([
            'status' => 'success',
            'probe' => $probeResult,
        ]);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');
});

it('stores probe result in database', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeData = [
        'duration_ms' => 120000,
        'width' => 1920,
        'height' => 1080,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
        'bitrate_kbps' => 5000,
        'fps' => 29.97,
        'audio_channels' => 2,
        'audio_sample_rate' => 48000,
        'format' => 'mp4',
        'size_bytes' => 1024000,
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andReturn([
            'status' => 'success',
            'probe' => $probeData,
        ]);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->probe_result)->toBeArray();
    expect($asset->probe_result['duration_ms'])->toBe(120000);
    expect($asset->probe_result['video_codec'])->toBe('h264');
});

it('stores duration in database', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andReturn([
            'status' => 'success',
            'probe' => ['duration_ms' => 120000],
        ]);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->duration_ms)->toBe(120000);
});

/*
|--------------------------------------------------------------------------
| State Transitions
|--------------------------------------------------------------------------
*/

it('transitions through stored to completed when no audio stream', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andReturn([
            'status' => 'success',
            'probe' => ['duration_ms' => 5000],
        ]);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');
    expect($asset->processing_started_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Error Handling
|--------------------------------------------------------------------------
*/

it('handle throws ProcessMediaException on worker error', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andThrow(new ProcessMediaException(
            'FFprobe failed with exit code 1',
            1,
            'Invalid data found',
        ));

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');

    $this->expectException(ProcessMediaException::class);
    $job->handle();
});

it('handle throws ProcessMediaException on timeout', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andThrow(new ProcessMediaException(
            'Worker process timed out after 30s',
            1,
            '',
        ));

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');

    $this->expectException(ProcessMediaException::class);
    $job->handle();
});

it('failure handler marks asset as failed', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'processing',
    ]);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $exception = new \RuntimeException('Something went wrong');
    $job->failed($exception);

    $asset->refresh();
    expect($asset->processing_status)->toBe('failed');
    expect($asset->processing_error)->toBe('Something went wrong');
});

it('failure handler marks asset as failed with ProcessMediaException', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'processing',
    ]);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $exception = new ProcessMediaException(
        'FFprobe failed with exit code 1',
        1,
        'Invalid data',
    );
    $job->failed($exception);

    $asset->refresh();
    expect($asset->processing_status)->toBe('failed');
    expect($asset->processing_error)->toContain('FFprobe failed');
});

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

it('is idempotent with same idempotency key', function () {
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
        'idempotency_key' => $idempotencyKey,
    ]);

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andReturn([
            'status' => 'success',
            'probe' => ['duration_ms' => 1000],
        ]);

    app()->instance(ProcessMediaAction::class, $actionMock);

    // Execute with idempotency key
    $job = new ProcessMediaAsset($asset, $idempotencyKey);
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');
    expect($asset->probe_result)->toBeArray();
    expect($asset->probe_result['duration_ms'])->toBe(1000);
});
