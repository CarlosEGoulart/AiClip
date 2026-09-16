<?php

namespace Tests\Unit;

use App\Contracts\MediaProcessingContract;
use App\Models\MediaAsset;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Contract Schema Validation
|--------------------------------------------------------------------------
*/

it('validates contract against JSON schema when schema exists', function () {
    // Schema lives in the monorepo root, not in the Laravel app
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';

    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $asset->storage_disk = 'media';
    $asset->storage_key = '1/1/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    $schema = json_decode(file_get_contents($schemaPath), true);

    expect($schema)->toHaveKeys(['$schema', 'title', 'type', 'required', 'properties']);
    expect($schema['required'])->toContain('version');
    expect($schema['required'])->toContain('media_asset_id');
    expect($schema['required'])->toContain('project_id');
    expect($schema['required'])->toContain('storage');
    expect($schema['required'])->toContain('idempotency_key');
    expect($schema['required'])->toContain('created_at');
});

it('contract array matches schema required fields', function () {
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';
    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $asset->storage_disk = 'media';
    $asset->storage_key = '1/1/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    $array = $contract->toArray();
    $schema = json_decode(file_get_contents($schemaPath), true);

    foreach ($schema['required'] as $field) {
        expect($array)->toHaveKey($field);
    }
});

/*
|--------------------------------------------------------------------------
| Worker Boundary: No Direct Database Access
|--------------------------------------------------------------------------
*/

it('contract contains no database connection info', function () {
    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $asset->storage_disk = 'media';
    $asset->storage_key = '1/1/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    $contractString = json_encode($contract->toArray());

    expect($contractString)->not->toMatch('/DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD/i');
    expect($contractString)->not->toMatch('/mysql|postgres|sqlite/i');
});

it('contract contains no application secrets', function () {
    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $asset->storage_disk = 'media';
    $asset->storage_key = '1/1/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    $contractString = json_encode($contract->toArray());

    expect($contractString)->not->toMatch('/APP_KEY|APP_SECRET|SECRET_KEY/i');
});

it('contract contains no OAuth tokens', function () {
    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $asset->storage_disk = 'media';
    $asset->storage_key = '1/1/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    $contractString = json_encode($contract->toArray());

    expect($contractString)->not->toMatch('/oauth|access_token|refresh_token|bearer/i');
});

it('contract contains no user PII', function () {
    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $asset->storage_disk = 'media';
    $asset->storage_key = '1/1/test.mp4';
    $asset->mime_type = 'video/mp4';

    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    $contractString = json_encode($contract->toArray());

    expect($contractString)->not->toMatch('/@/');
    expect($contractString)->not->toMatch('/\+\d{10}/');
    expect($contractString)->not->toMatch('/password|ssn|social.security/i');
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
