<?php

namespace Tests\Unit;

use App\Models\MediaAsset;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Processing State Constants
|--------------------------------------------------------------------------
*/

it('defines all processing state constants', function () {
    expect(MediaAsset::PROCESSING_STORED)->toBe('stored');
    expect(MediaAsset::PROCESSING_QUEUED)->toBe('queued');
    expect(MediaAsset::PROCESSING_RUNNING)->toBe('processing');
    expect(MediaAsset::PROCESSING_COMPLETED)->toBe('completed');
    expect(MediaAsset::PROCESSING_FAILED)->toBe('failed');
});

it('validates processing states array contains all states', function () {
    expect(MediaAsset::VALID_PROCESSING_STATES)->toHaveCount(5);
    expect(MediaAsset::VALID_PROCESSING_STATES)->toContain('stored');
    expect(MediaAsset::VALID_PROCESSING_STATES)->toContain('queued');
    expect(MediaAsset::VALID_PROCESSING_STATES)->toContain('processing');
    expect(MediaAsset::VALID_PROCESSING_STATES)->toContain('completed');
    expect(MediaAsset::VALID_PROCESSING_STATES)->toContain('failed');
});

/*
|--------------------------------------------------------------------------
| Validation Helper (Static method, no DB required)
|--------------------------------------------------------------------------
*/

it('validates correct transitions', function () {
    expect(MediaAsset::isValidTransition('stored', 'queued'))->toBeTrue();
    expect(MediaAsset::isValidTransition('queued', 'processing'))->toBeTrue();
    expect(MediaAsset::isValidTransition('processing', 'completed'))->toBeTrue();
    expect(MediaAsset::isValidTransition('processing', 'failed'))->toBeTrue();
    expect(MediaAsset::isValidTransition('queued', 'failed'))->toBeTrue();
});

it('rejects invalid transitions', function () {
    expect(MediaAsset::isValidTransition('stored', 'processing'))->toBeFalse();
    expect(MediaAsset::isValidTransition('stored', 'completed'))->toBeFalse();
    expect(MediaAsset::isValidTransition('completed', 'stored'))->toBeFalse();
    expect(MediaAsset::isValidTransition('failed', 'queued'))->toBeFalse();
    expect(MediaAsset::isValidTransition('completed', 'queued'))->toBeFalse();
    expect(MediaAsset::isValidTransition('failed', 'processing'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Model Fillable (No DB required)
|--------------------------------------------------------------------------
*/

it('has processing fields in fillable', function () {
    $fillable = (new MediaAsset)->getFillable();

    expect($fillable)->toContain('processing_status');
    expect($fillable)->toContain('idempotency_key');
    expect($fillable)->toContain('processing_started_at');
    expect($fillable)->toContain('processing_completed_at');
    expect($fillable)->toContain('processing_error');
});
