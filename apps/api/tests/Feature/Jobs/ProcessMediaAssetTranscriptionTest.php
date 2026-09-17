<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
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
| Successful Transcription Flow
|--------------------------------------------------------------------------
*/

it('chains probe to audio extraction to transcription', function () {
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

it('creates MediaTranscript on successful transcription', function () {
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
    $actionMock->shouldReceive('extractAudio')
        ->once()
        ->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')
        ->once()
        ->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $this->assertDatabaseHas('media_transcripts', [
        'media_asset_id' => $asset->id,
        'status' => MediaTranscript::STATUS_COMPLETED,
    ]);
});

it('marks MediaTranscript completed with correct data', function () {
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
    $actionMock->shouldReceive('extractAudio')
        ->once()
        ->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')
        ->once()
        ->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->language)->toBe('en');
    expect($transcript->full_text)->toBe('Hello world');
    expect($transcript->engine)->toBe('deterministic');
    expect($transcript->model)->toBe('deterministic');
    expect($transcript->segments)->toBeArray();
    expect(count($transcript->segments))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

it('skips transcription if MediaTranscript exists with completed status', function () {
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

    // Create existing DerivedAsset
    $derivedAsset = DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
        'storage_disk' => 'media',
        'storage_key' => '/tmp/audio_normalized.wav',
        'mime_type' => 'audio/wav',
        'size_bytes' => 160000,
    ]);

    // Create existing completed MediaTranscript
    MediaTranscript::create([
        'media_asset_id' => $asset->id,
        'derived_asset_id' => $derivedAsset->id,
        'status' => MediaTranscript::STATUS_COMPLETED,
        'language' => 'en',
        'full_text' => 'Already transcribed',
        'segments' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Already transcribed']],
        'engine' => 'deterministic',
        'model' => 'deterministic',
    ]);

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->once()
        ->andReturn([
            'status' => 'success',
            'probe' => $probeResult,
        ]);
    $actionMock->shouldNotReceive('extractAudio');
    $actionMock->shouldNotReceive('transcribe');

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

it('marks MediaTranscript failed on transcription error', function () {
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
        ->andThrow(new ProcessMediaException(
            'Transcription engine failed',
            1,
            'Engine error',
        ));

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_FAILED);
    expect($transcript->error)->toContain('Transcription engine failed');

    // Asset should still be completed (audio extraction succeeded)
    $asset->refresh();
    expect($asset->processing_status)->toBe('completed');
});

it('retries failed transcription on rerun', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $probeResult = [
        'duration_ms' => 5000,
        'audio_codec' => 'aac',
    ];

    // Create existing DerivedAsset
    $derivedAsset = DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
        'storage_disk' => 'media',
        'storage_key' => '/tmp/audio_normalized.wav',
        'mime_type' => 'audio/wav',
        'size_bytes' => 160000,
    ]);

    // Create existing FAILED MediaTranscript
    $transcript = MediaTranscript::create([
        'media_asset_id' => $asset->id,
        'derived_asset_id' => $derivedAsset->id,
        'status' => MediaTranscript::STATUS_FAILED,
        'error' => 'Previous transcription failed',
    ]);

    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Retry succeeded',
            'segments' => [
                ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Retry'],
                ['start_ms' => 1000, 'end_ms' => 2000, 'text' => 'succeeded'],
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
    $actionMock->shouldNotReceive('extractAudio');
    $actionMock->shouldReceive('transcribe')
        ->once()
        ->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $transcript->refresh();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_COMPLETED);
    expect($transcript->full_text)->toBe('Retry succeeded');
    expect($transcript->language)->toBe('en');
    expect($transcript->engine)->toBe('deterministic');
    expect($transcript->model)->toBe('deterministic');
});

it('does not create MediaTranscript when audio extraction fails', function () {
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
    $actionMock->shouldNotReceive('transcribe');

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? '550e8400-e29b-41d4-a716-446655440000');
    $job->handle();

    $this->assertDatabaseMissing('media_transcripts', [
        'media_asset_id' => $asset->id,
    ]);

    $asset->refresh();
    expect($asset->processing_status)->toBe('failed');
});

/*
|--------------------------------------------------------------------------
| Response Validation (BLOCKER 6)
|--------------------------------------------------------------------------
*/

it('rejects malformed worker success response missing transcription object', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);

    $probeResult = ['duration_ms' => 5000, 'audio_codec' => 'aac'];
    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
        ],
    ];
    // Missing transcription object entirely
    $transcribeResult = ['status' => 'success'];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn([
        'status' => 'success', 'probe' => $probeResult,
    ]);
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? 'test-key');
    $job->handle();

    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_FAILED);
    expect($transcript->error)->toContain('missing or malformed');
});

it('rejects worker success response with missing language', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);

    $probeResult = ['duration_ms' => 5000, 'audio_codec' => 'aac'];
    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
        ],
    ];
    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            // language missing
            'full_text' => 'Hello',
            'segments' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello']],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn([
        'status' => 'success', 'probe' => $probeResult,
    ]);
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? 'test-key');
    $job->handle();

    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_FAILED);
    expect($transcript->error)->toContain('language');
});

it('rejects worker success response with invalid segment timing', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);

    $probeResult = ['duration_ms' => 5000, 'audio_codec' => 'aac'];
    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
        ],
    ];
    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Hello',
            'segments' => [['start_ms' => -1, 'end_ms' => 1000, 'text' => 'Hello']],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn([
        'status' => 'success', 'probe' => $probeResult,
    ]);
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? 'test-key');
    $job->handle();

    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_FAILED);
    expect($transcript->error)->toContain('start_ms');
});

/*
|--------------------------------------------------------------------------
| Segment Text Validation Edge Cases
|--------------------------------------------------------------------------
*/

it('accepts segment text "0" as valid', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);

    $probeResult = ['duration_ms' => 5000, 'audio_codec' => 'aac'];
    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
        ],
    ];
    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Count: 0',
            'segments' => [
                ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Count:'],
                ['start_ms' => 1000, 'end_ms' => 2000, 'text' => '0'],
            ],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? 'test-key');
    $job->handle();

    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_COMPLETED);
    expect($transcript->segments[1]['text'])->toBe('0');
});

/*
|--------------------------------------------------------------------------
| Cross-Segment Validation (ordering and overlap)
|--------------------------------------------------------------------------
*/

it('rejects unordered segments from worker', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);

    $probeResult = ['duration_ms' => 5000, 'audio_codec' => 'aac'];
    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
        ],
    ];
    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Hello world',
            'segments' => [
                ['start_ms' => 1000, 'end_ms' => 2000, 'text' => 'world'],
                ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello'],
            ],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? 'test-key');
    $job->handle();

    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_FAILED);
    expect($transcript->error)->toContain('ordered');
});

it('rejects overlapping segments from worker', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);

    $probeResult = ['duration_ms' => 5000, 'audio_codec' => 'aac'];
    $extractionResult = [
        'status' => 'success',
        'extraction' => [
            'output_path' => '/tmp/audio.wav',
            'output_size_bytes' => 160000,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
        ],
    ];
    $transcribeResult = [
        'status' => 'success',
        'transcription' => [
            'language' => 'en',
            'full_text' => 'Hello world',
            'segments' => [
                ['start_ms' => 0, 'end_ms' => 1500, 'text' => 'Hello'],
                ['start_ms' => 1000, 'end_ms' => 2000, 'text' => 'world'],
            ],
            'engine' => 'deterministic',
            'model' => 'deterministic',
        ],
    ];

    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->once()->andReturn(['status' => 'success', 'probe' => $probeResult]);
    $actionMock->shouldReceive('extractAudio')->once()->andReturn($extractionResult);
    $actionMock->shouldReceive('transcribe')->once()->andReturn($transcribeResult);

    app()->instance(ProcessMediaAction::class, $actionMock);

    $job = new ProcessMediaAsset($asset, $asset->idempotency_key ?? 'test-key');
    $job->handle();

    $transcript = MediaTranscript::where('media_asset_id', $asset->id)->first();
    expect($transcript)->not->toBeNull();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_FAILED);
    expect($transcript->error)->toContain('overlap');
});
