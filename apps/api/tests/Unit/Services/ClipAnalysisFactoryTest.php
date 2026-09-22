<?php

namespace Tests\Unit\Services;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use Tests\TestCase;

uses(TestCase::class);

function metadataRequest(): array
{
    return [
        'version' => '1.0.0', 'action' => 'analyze_clips',
        'media' => ['duration_ms' => 30000], 'scenes' => [],
        'configuration' => [
            'min_duration_ms' => 5000, 'target_duration_ms' => 30000,
            'max_duration_ms' => 60000, 'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ],
    ];
}

it('rejects forbidden raw analysis fields instead of dropping them', function (string $key, mixed $value) {
    // Supply the old factory envelope so no missing-key warning is the RED.
    $raw = metadataRequest() + [
        'media_asset_id' => 1, 'project_id' => 1,
        'storage' => ['disk' => 'media', 'key' => 'PRIVATE_SENTINEL', 'mime_type' => 'video/mp4'],
        'idempotency_key' => '550e8400-e29b-41d4-a716-446655440000',
        'created_at' => '2026-09-22T00:00:00Z',
    ];
    $raw[$key] = $value;
    $rejected = false;
    try {
        $rejected = ! MediaProcessingContract::fromArray($raw)->validate();
    } catch (ProcessMediaException $exception) {
        $rejected = true;
        expect($exception->getPrevious())->toBeNull();
        expect($exception->getMessage())->not->toContain('PRIVATE_SENTINEL');
    }
    $this->assertTrue($rejected, 'Raw analyze_clips must reject extras, not silently project them away.');
})->with([
    'legacy envelope' => ['private', 'PRIVATE_SENTINEL'],
    'explicit transcript null' => ['transcript_segments', null],
    'raw float duration' => ['media', ['duration_ms' => 30000.0]],
    'raw string duration' => ['media', ['duration_ms' => '30000']],
    'raw media private field' => ['media', ['duration_ms' => 30000, 'text' => 'PRIVATE_SENTINEL']],
]);

it('creates a metadata-only contract without initializing legacy identity', function () {
    $failure = null;
    $contract = null;
    try {
        $contract = MediaProcessingContract::fromArray(metadataRequest());
    } catch (\Throwable $exception) {
        $failure = get_class($exception);
    }
    $this->assertNull($failure, 'The existing factory must accept a valid metadata-only request.');
    expect($contract->validate())->toBeTrue();
    expect($contract->toMetadataArray())->toBe(metadataRequest());
    expect(isset($contract->mediaAssetId), isset($contract->storage))->toBeFalse();
});
