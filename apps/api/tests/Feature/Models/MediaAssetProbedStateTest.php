<?php

namespace Tests\Unit;

use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| PROCESSING_PROBED Constant
|--------------------------------------------------------------------------
*/

it('defines PROCESSING_PROBED constant', function () {
    expect(MediaAsset::PROCESSING_PROBED)->toBe('probed');
});

/*
|--------------------------------------------------------------------------
| Valid Processing States
|--------------------------------------------------------------------------
*/

it('includes probed in valid processing states', function () {
    expect(MediaAsset::VALID_PROCESSING_STATES)->toContain('probed');
});

it('has 6 valid processing states total', function () {
    expect(MediaAsset::VALID_PROCESSING_STATES)->toHaveCount(6);
});

/*
|--------------------------------------------------------------------------
| Valid State Transitions
|--------------------------------------------------------------------------
*/

it('allows processing to probed transition', function () {
    expect(MediaAsset::isValidTransition('processing', 'probed'))->toBeTrue();
});

it('allows probed to completed transition', function () {
    expect(MediaAsset::isValidTransition('probed', 'completed'))->toBeTrue();
});

it('allows probed to failed transition', function () {
    expect(MediaAsset::isValidTransition('probed', 'failed'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Invalid State Transitions to Probed
|--------------------------------------------------------------------------
*/

it('rejects stored to probed transition', function () {
    expect(MediaAsset::isValidTransition('stored', 'probed'))->toBeFalse();
});

it('rejects queued to probed transition', function () {
    expect(MediaAsset::isValidTransition('queued', 'probed'))->toBeFalse();
});

it('rejects completed to probed transition', function () {
    expect(MediaAsset::isValidTransition('completed', 'probed'))->toBeFalse();
});

it('rejects failed to probed transition', function () {
    expect(MediaAsset::isValidTransition('failed', 'probed'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| markProbed Method
|--------------------------------------------------------------------------
*/

it('has markProbed method', function () {
    $asset = new MediaAsset;
    expect(method_exists($asset, 'markProbed'))->toBeTrue();
});

it('transitions to probed state via markProbed', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'processing',
    ]);

    $probeResult = [
        'duration_ms' => 120000,
        'width' => 1920,
        'height' => 1080,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
    ];

    $asset->markProbed($probeResult, 120000);

    expect($asset->fresh()->processing_status)->toBe('probed');
    expect($asset->fresh()->probe_result)->toBe($probeResult);
    expect($asset->fresh()->duration_ms)->toBe(120000);
});

it('does not transition to probed from invalid state', function () {
    $asset = MediaAsset::factory()->create([
        'processing_status' => 'stored',
    ]);

    $asset->markProbed(['duration_ms' => 0], 0);

    expect($asset->fresh()->processing_status)->toBe('stored');
});

/*
|--------------------------------------------------------------------------
| Fillable and Casts
|--------------------------------------------------------------------------
*/

it('has probe_result in fillable', function () {
    $fillable = (new MediaAsset)->getFillable();
    expect($fillable)->toContain('probe_result');
});

it('has duration_ms in fillable', function () {
    $fillable = (new MediaAsset)->getFillable();
    expect($fillable)->toContain('duration_ms');
});

it('casts probe_result to array', function () {
    $asset = MediaAsset::factory()->create([
        'probe_result' => ['duration_ms' => 1000],
    ]);

    expect($asset->probe_result)->toBeArray();
});

it('casts duration_ms to integer', function () {
    $asset = MediaAsset::factory()->create([
        'duration_ms' => 120000,
    ]);

    expect($asset->duration_ms)->toBeInt();
    expect($asset->duration_ms)->toBe(120000);
});
