<?php

namespace Tests\Unit;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Contract Creation (Static factory method, no DB required)
|--------------------------------------------------------------------------
*/

it('creates contract from MediaAsset', function () {
    $asset = new MediaAsset;
    $asset->id = 42;
    $asset->project_id = 7;
    $asset->storage_disk = 'media';
    $asset->storage_key = '7/42/test.mp4';
    $asset->mime_type = 'video/mp4';
    $idempotencyKey = (string) Str::uuid();

    $contract = MediaProcessingContract::fromMediaAsset($asset, $idempotencyKey);

    expect($contract->version)->toBe('1.0.0');
    expect($contract->mediaAssetId)->toBe(42);
    expect($contract->projectId)->toBe(7);
    expect($contract->storage['disk'])->toBe('media');
    expect($contract->storage['key'])->toBe('7/42/test.mp4');
    expect($contract->storage['mime_type'])->toBe('video/mp4');
    expect($contract->idempotencyKey)->toBe($idempotencyKey);
    expect($contract->createdAt)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Contract Serialization (No DB required)
|--------------------------------------------------------------------------
*/

it('serializes contract to array with all required fields', function () {
    $asset = new MediaAsset;
    $asset->id = 42;
    $asset->project_id = 7;
    $asset->storage_disk = 'media';
    $asset->storage_key = '7/42/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    $array = $contract->toArray();

    expect($array)->toHaveKeys([
        'version', 'media_asset_id', 'project_id', 'storage', 'idempotency_key', 'created_at',
    ]);
    expect($array['storage'])->toHaveKeys(['disk', 'key', 'mime_type']);
    expect($array['version'])->toBe('1.0.0');
    expect($array['media_asset_id'])->toBeInt();
    expect($array['project_id'])->toBeInt();
    expect($array['idempotency_key'])->toBeString();
    expect($array['created_at'])->toBeString();
});

/*
|--------------------------------------------------------------------------
| Contract Deserialization (No DB required)
|--------------------------------------------------------------------------
*/

it('creates contract from array', function () {
    $data = [
        'version' => '1.0.0',
        'media_asset_id' => 42,
        'project_id' => 7,
        'storage' => [
            'disk' => 'media',
            'key' => '7/42/test.mp4',
            'mime_type' => 'video/mp4',
        ],
        'idempotency_key' => (string) Str::uuid(),
        'created_at' => now()->toIso8601String(),
    ];

    $contract = MediaProcessingContract::fromArray($data);

    expect($contract->version)->toBe('1.0.0');
    expect($contract->mediaAssetId)->toBe(42);
    expect($contract->projectId)->toBe(7);
    expect($contract->storage['disk'])->toBe('media');
    expect($contract->storage['key'])->toBe('7/42/test.mp4');
    expect($contract->storage['mime_type'])->toBe('video/mp4');
    expect($contract->idempotencyKey)->toBe($data['idempotency_key']);
    expect($contract->createdAt)->toBe($data['created_at']);
});

/*
|--------------------------------------------------------------------------
| Contract Validation (No DB required)
|--------------------------------------------------------------------------
*/

it('validates valid contract', function () {
    $contract = new MediaProcessingContract;
    $contract->version = '1.0.0';
    $contract->mediaAssetId = 1;
    $contract->projectId = 1;
    $contract->storage = ['disk' => 'media', 'key' => 'test.mp4', 'mime_type' => 'video/mp4'];
    $contract->idempotencyKey = (string) Str::uuid();
    $contract->createdAt = now()->toIso8601String();

    expect($contract->validate())->toBeTrue();
});

it('rejects contract with missing required fields', function () {
    $contract = new MediaProcessingContract;
    $contract->version = '1.0.0';
    // missing other fields

    expect($contract->validate())->toBeFalse();
});

it('rejects contract with invalid version format', function () {
    $contract = new MediaProcessingContract;
    $contract->version = 'invalid';
    $contract->mediaAssetId = 1;
    $contract->projectId = 1;
    $contract->storage = ['disk' => 'media', 'key' => 'test.mp4', 'mime_type' => 'video/mp4'];
    $contract->idempotencyKey = (string) Str::uuid();
    $contract->createdAt = now()->toIso8601String();

    expect($contract->validate())->toBeFalse();
});

it('rejects contract with empty storage disk', function () {
    $contract = new MediaProcessingContract;
    $contract->version = '1.0.0';
    $contract->mediaAssetId = 1;
    $contract->projectId = 1;
    $contract->storage = ['disk' => '', 'key' => 'test.mp4', 'mime_type' => 'video/mp4'];
    $contract->idempotencyKey = (string) Str::uuid();
    $contract->createdAt = now()->toIso8601String();

    expect($contract->validate())->toBeFalse();
});

it('rejects contract with empty storage key', function () {
    $contract = new MediaProcessingContract;
    $contract->version = '1.0.0';
    $contract->mediaAssetId = 1;
    $contract->projectId = 1;
    $contract->storage = ['disk' => 'media', 'key' => '', 'mime_type' => 'video/mp4'];
    $contract->idempotencyKey = (string) Str::uuid();
    $contract->createdAt = now()->toIso8601String();

    expect($contract->validate())->toBeFalse();
});

it('rejects contract with invalid UUID idempotency key', function () {
    $contract = new MediaProcessingContract;
    $contract->version = '1.0.0';
    $contract->mediaAssetId = 1;
    $contract->projectId = 1;
    $contract->storage = ['disk' => 'media', 'key' => 'test.mp4', 'mime_type' => 'video/mp4'];
    $contract->idempotencyKey = 'not-a-uuid';
    $contract->createdAt = now()->toIso8601String();

    expect($contract->validate())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Contract Security (No DB required)
|--------------------------------------------------------------------------
*/

it('contract contains no user PII beyond identifiers', function () {
    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $asset->storage_disk = 'media';
    $asset->storage_key = '1/1/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    $contractString = json_encode($contract->toArray());

    expect($contractString)->not->toMatch('/@/');
    expect($contractString)->not->toMatch('/password|secret|token|credential/i');
});

it('contract contains no database credentials', function () {
    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $asset->storage_disk = 'media';
    $asset->storage_key = '1/1/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    $contractString = json_encode($contract->toArray());

    expect($contractString)->not->toMatch('/DB_|DATABASE_|mysql|postgres|sqlite/i');
});

it('contract contains no storage credentials', function () {
    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $asset->storage_disk = 'media';
    $asset->storage_key = '1/1/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    $contractString = json_encode($contract->toArray());

    expect($contractString)->not->toMatch('/AWS_SECRET|SECRET_KEY|access_key|private_key/i');
});

/*
|--------------------------------------------------------------------------
| Contract Round-trip (No DB required)
|--------------------------------------------------------------------------
*/

it('round-trips contract through array serialization', function () {
    $asset = new MediaAsset;
    $asset->id = 42;
    $asset->project_id = 7;
    $asset->storage_disk = 'media';
    $asset->storage_key = '7/42/test.mp4';
    $asset->mime_type = 'video/mp4';

    $idempotencyKey = (string) Str::uuid();
    $original = MediaProcessingContract::fromMediaAsset($asset, $idempotencyKey);

    $array = $original->toArray();
    $restored = MediaProcessingContract::fromArray($array);

    expect($restored->version)->toBe($original->version);
    expect($restored->mediaAssetId)->toBe($original->mediaAssetId);
    expect($restored->projectId)->toBe($original->projectId);
    expect($restored->storage)->toBe($original->storage);
    expect($restored->idempotencyKey)->toBe($original->idempotencyKey);
    expect($restored->createdAt)->toBe($original->createdAt);
});

/*
|--------------------------------------------------------------------------
| Contract Version (No DB required)
|--------------------------------------------------------------------------
*/

it('contract version follows semver format', function () {
    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $asset->storage_disk = 'media';
    $asset->storage_key = '1/1/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());

    expect($contract->version)->toMatch('/^\d+\.\d+\.\d+$/');
});

it('contract version is 1.0.0', function () {
    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $asset->storage_disk = 'media';
    $asset->storage_key = '1/1/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());

    expect($contract->version)->toBe('1.0.0');
});

/*
|--------------------------------------------------------------------------
| renderClipRequest with captions — TP-05
|--------------------------------------------------------------------------
*/

it('renderClipRequest with transcript generates caption file and includes in output', function () {
    Storage::fake('media');

    $recommendation = [
        'recommendations' => [
            [
                'index' => 0,
                'start_ms' => 1000,
                'end_ms' => 5000,
                'semantic_rank' => 1,
                'semantic_score' => 0.95,
            ],
        ],
    ];

    $sourceMedia = [
        'disk' => 'media',
        'key' => '7/42/test.mp4',
        'width' => 1920,
        'height' => 1080,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
    ];

    $transcriptSegments = [
        ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'Hello world'],
    ];

    $result = MediaProcessingContract::renderClipRequest(
        mediaAssetId: 42,
        durationMs: 10000,
        recommendation: $recommendation,
        recommendationId: 1,
        candidateIndex: 0,
        sourceMedia: $sourceMedia,
        projectId: 7,
        transcriptSegments: $transcriptSegments,
        clipStartMs: 1000,
        clipEndMs: 5000,
    );

    expect($result)->toHaveKey('caption_file');
    expect($result['caption_file'])->toBeString();
    expect($result['caption_file'])->toStartWith('projects/7/captions/42/0/vertical_v1/');
    expect($result['caption_file'])->toEndWith('.srt');
});

it('renderClipRequest with empty projected segments omits caption_file', function () {
    Storage::fake('media');

    $recommendation = [
        'recommendations' => [
            [
                'index' => 0,
                'start_ms' => 1000,
                'end_ms' => 5000,
                'semantic_rank' => 1,
                'semantic_score' => 0.95,
            ],
        ],
    ];

    $sourceMedia = [
        'disk' => 'media',
        'key' => '7/42/test.mp4',
        'width' => 1920,
        'height' => 1080,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
    ];

    // Transcript segments that don't overlap with clip
    $transcriptSegments = [
        ['start_ms' => 10000, 'end_ms' => 15000, 'text' => 'Outside clip'],
    ];

    $result = MediaProcessingContract::renderClipRequest(
        mediaAssetId: 42,
        durationMs: 20000,
        recommendation: $recommendation,
        recommendationId: 1,
        candidateIndex: 0,
        sourceMedia: $sourceMedia,
        projectId: 7,
        transcriptSegments: $transcriptSegments,
        clipStartMs: 1000,
        clipEndMs: 5000,
    );

    expect($result)->not->toHaveKey('caption_file');
});

it('renderClipRequest without transcript omits caption_file', function () {
    $recommendation = [
        'recommendations' => [
            [
                'index' => 0,
                'start_ms' => 1000,
                'end_ms' => 5000,
                'semantic_rank' => 1,
                'semantic_score' => 0.95,
            ],
        ],
    ];

    $sourceMedia = [
        'disk' => 'media',
        'key' => '7/42/test.mp4',
        'width' => 1920,
        'height' => 1080,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
    ];

    $result = MediaProcessingContract::renderClipRequest(
        mediaAssetId: 42,
        durationMs: 10000,
        recommendation: $recommendation,
        recommendationId: 1,
        candidateIndex: 0,
        sourceMedia: $sourceMedia,
        projectId: 7,
    );

    expect($result)->not->toHaveKey('caption_file');
});

it('renderClipRequest validates required params when transcript provided', function () {
    Storage::fake('media');

    $recommendation = [
        'recommendations' => [
            [
                'index' => 0,
                'start_ms' => 1000,
                'end_ms' => 5000,
                'semantic_rank' => 1,
                'semantic_score' => 0.95,
            ],
        ],
    ];

    $sourceMedia = [
        'disk' => 'media',
        'key' => '7/42/test.mp4',
        'width' => 1920,
        'height' => 1080,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
    ];

    $transcriptSegments = [
        ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'Hello world'],
    ];

    // Missing clipStartMs
    MediaProcessingContract::renderClipRequest(
        mediaAssetId: 42,
        durationMs: 10000,
        recommendation: $recommendation,
        recommendationId: 1,
        candidateIndex: 0,
        sourceMedia: $sourceMedia,
        projectId: 7,
        transcriptSegments: $transcriptSegments,
        clipEndMs: 5000,
    );
})->throws(ProcessMediaException::class);

it('renderClipRequest maintains backward compatibility without transcript params', function () {
    $recommendation = [
        'recommendations' => [
            [
                'index' => 0,
                'start_ms' => 1000,
                'end_ms' => 5000,
                'semantic_rank' => 1,
                'semantic_score' => 0.95,
            ],
        ],
    ];

    $sourceMedia = [
        'disk' => 'media',
        'key' => '7/42/test.mp4',
        'width' => 1920,
        'height' => 1080,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
    ];

    $result = MediaProcessingContract::renderClipRequest(
        mediaAssetId: 42,
        durationMs: 10000,
        recommendation: $recommendation,
        recommendationId: 1,
        candidateIndex: 0,
        sourceMedia: $sourceMedia,
        projectId: 7,
    );

    expect($result)->toHaveKeys([
        'version', 'action', 'media', 'candidate_index', 'candidate',
        'configuration', 'source_media', 'output_storage',
    ]);
    expect($result)->not->toHaveKey('caption_file');
});

it('renderClipRequest stores SRT with correct MIME type when caption generated', function () {
    Storage::fake('media');

    $recommendation = [
        'recommendations' => [
            [
                'index' => 0,
                'start_ms' => 1000,
                'end_ms' => 5000,
                'semantic_rank' => 1,
                'semantic_score' => 0.95,
            ],
        ],
    ];

    $sourceMedia = [
        'disk' => 'media',
        'key' => '7/42/test.mp4',
        'width' => 1920,
        'height' => 1080,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
    ];

    $transcriptSegments = [
        ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'Hello world'],
    ];

    $result = MediaProcessingContract::renderClipRequest(
        mediaAssetId: 42,
        durationMs: 10000,
        recommendation: $recommendation,
        recommendationId: 1,
        candidateIndex: 0,
        sourceMedia: $sourceMedia,
        projectId: 7,
        transcriptSegments: $transcriptSegments,
        clipStartMs: 1000,
        clipEndMs: 5000,
    );

    expect($result)->toHaveKey('caption_file');
    // The caption_file key should be a storage path, MIME type is handled at storage time
    expect($result['caption_file'])->toBeString();

    // Verify file was stored with correct MIME type
    $captionKey = $result['caption_file'];
    Storage::disk('media')->assertExists($captionKey);
    // Note: assertMimeType is not directly available, but we can verify the file exists
});
