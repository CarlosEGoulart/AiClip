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

it('validates root schema required fields and action semantics', function () {
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';

    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $schema = json_decode(file_get_contents($schemaPath), true);

    expect($schema)->toHaveKeys(['$schema', 'title', 'type', 'required', 'properties', 'allOf']);
    expect($schema['required'])->toContain('version');
    expect($schema['required'])->not->toContain('action');
    expect($schema['required'])->not->toContain('media_asset_id');
    expect($schema['required'])->not->toContain('project_id');
    expect($schema['required'])->not->toContain('storage');
    expect($schema['required'])->not->toContain('idempotency_key');
    expect($schema['required'])->not->toContain('created_at');

    // Action has default "probe" and enum includes "render_clip"
    $actionProp = $schema['properties']['action'];
    expect($actionProp['default'])->toBe('probe');
    expect($actionProp['enum'])->toContain('render_clip');
});

it('validates missing-action compatibility allOf requires legacy envelope', function () {
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';

    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $schema = json_decode(file_get_contents($schemaPath), true);

    // Find the if.not.required["action"] condition
    $missingActionCondition = null;
    foreach ($schema['allOf'] as $condition) {
        if (isset($condition['if']['not']['required']) && in_array('action', $condition['if']['not']['required'])) {
            $missingActionCondition = $condition;
            break;
        }
    }

    expect($missingActionCondition)->not->toBeNull();
    expect($missingActionCondition['then']['required'])->toContain('project_id');
    expect($missingActionCondition['then']['required'])->toContain('storage');
    expect($missingActionCondition['then']['required'])->toContain('idempotency_key');
    expect($missingActionCondition['then']['required'])->toContain('created_at');
    expect($missingActionCondition['then']['required'])->not->toContain('media_asset_id');
});

it('validates probe action requires legacy envelope only (no media_asset_id)', function () {
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';

    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $schema = json_decode(file_get_contents($schemaPath), true);

    $probeCondition = null;
    foreach ($schema['allOf'] as $condition) {
        if (isset($condition['if']['properties']['action']['const']) && $condition['if']['properties']['action']['const'] === 'probe') {
            $probeCondition = $condition;
            break;
        }
    }

    expect($probeCondition)->not->toBeNull();
    expect($probeCondition['then']['required'])->toContain('project_id');
    expect($probeCondition['then']['required'])->toContain('storage');
    expect($probeCondition['then']['required'])->toContain('idempotency_key');
    expect($probeCondition['then']['required'])->toContain('created_at');
    expect($probeCondition['then']['required'])->not->toContain('media_asset_id');
    expect($probeCondition['then']['required'])->not->toContain('media');
});

it('validates extract_audio action requires legacy envelope only (no media_asset_id)', function () {
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';

    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $schema = json_decode(file_get_contents($schemaPath), true);

    $extractAudioCondition = null;
    foreach ($schema['allOf'] as $condition) {
        if (isset($condition['if']['properties']['action']['const']) && $condition['if']['properties']['action']['const'] === 'extract_audio') {
            $extractAudioCondition = $condition;
            break;
        }
    }

    expect($extractAudioCondition)->not->toBeNull();
    expect($extractAudioCondition['then']['required'])->toContain('project_id');
    expect($extractAudioCondition['then']['required'])->toContain('storage');
    expect($extractAudioCondition['then']['required'])->toContain('idempotency_key');
    expect($extractAudioCondition['then']['required'])->toContain('created_at');
    expect($extractAudioCondition['then']['required'])->not->toContain('media_asset_id');
    expect($extractAudioCondition['then']['required'])->not->toContain('media');
});

it('validates transcribe action requires legacy envelope only (no media_asset_id)', function () {
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';

    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $schema = json_decode(file_get_contents($schemaPath), true);

    $transcribeCondition = null;
    foreach ($schema['allOf'] as $condition) {
        if (isset($condition['if']['properties']['action']['const']) && $condition['if']['properties']['action']['const'] === 'transcribe') {
            $transcribeCondition = $condition;
            break;
        }
    }

    expect($transcribeCondition)->not->toBeNull();
    expect($transcribeCondition['then']['required'])->toContain('project_id');
    expect($transcribeCondition['then']['required'])->toContain('storage');
    expect($transcribeCondition['then']['required'])->toContain('idempotency_key');
    expect($transcribeCondition['then']['required'])->toContain('created_at');
    expect($transcribeCondition['then']['required'])->not->toContain('media_asset_id');
    expect($transcribeCondition['then']['required'])->not->toContain('media');
});

it('validates detect_scenes action with nested allOf for media.duration_ms', function () {
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';

    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $schema = json_decode(file_get_contents($schemaPath), true);

    $detectScenesCondition = null;
    foreach ($schema['allOf'] as $condition) {
        if (isset($condition['if']['properties']['action']['const']) && $condition['if']['properties']['action']['const'] === 'detect_scenes') {
            $detectScenesCondition = $condition;
            break;
        }
    }

    expect($detectScenesCondition)->not->toBeNull();

    // then.allOf[0] required fields
    expect($detectScenesCondition['then']['allOf'][0]['required'])->toContain('project_id');
    expect($detectScenesCondition['then']['allOf'][0]['required'])->toContain('storage');
    expect($detectScenesCondition['then']['allOf'][0]['required'])->toContain('idempotency_key');
    expect($detectScenesCondition['then']['allOf'][0]['required'])->toContain('created_at');
    expect($detectScenesCondition['then']['allOf'][0]['required'])->toContain('media');
    expect($detectScenesCondition['then']['allOf'][0]['required'])->not->toContain('media_asset_id');

    // then.allOf[1] properties.media.required.duration_ms
    $mediaProp = $detectScenesCondition['then']['allOf'][1]['properties']['media'];
    expect($mediaProp['required'])->toContain('duration_ms');
});

it('validates analyze_clips action requires envelope + media/scenes/configuration', function () {
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';

    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $schema = json_decode(file_get_contents($schemaPath), true);

    $analyzeClipsCondition = null;
    foreach ($schema['allOf'] as $condition) {
        if (isset($condition['if']['properties']['action']['const']) && $condition['if']['properties']['action']['const'] === 'analyze_clips') {
            $analyzeClipsCondition = $condition;
            break;
        }
    }

    expect($analyzeClipsCondition)->not->toBeNull();
    expect($analyzeClipsCondition['then']['required'])->toContain('project_id');
    expect($analyzeClipsCondition['then']['required'])->toContain('storage');
    expect($analyzeClipsCondition['then']['required'])->toContain('idempotency_key');
    expect($analyzeClipsCondition['then']['required'])->toContain('created_at');
    expect($analyzeClipsCondition['then']['required'])->toContain('media');
    expect($analyzeClipsCondition['then']['required'])->toContain('scenes');
    expect($analyzeClipsCondition['then']['required'])->toContain('configuration');
    expect($analyzeClipsCondition['then']['required'])->not->toContain('media_asset_id');
});

it('validates rank_clips action requires only action-specific fields (no legacy envelope)', function () {
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';

    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $schema = json_decode(file_get_contents($schemaPath), true);

    $rankClipsCondition = null;
    foreach ($schema['allOf'] as $condition) {
        if (isset($condition['if']['properties']['action']['const']) && $condition['if']['properties']['action']['const'] === 'rank_clips') {
            $rankClipsCondition = $condition;
            break;
        }
    }

    expect($rankClipsCondition)->not->toBeNull();
    expect($rankClipsCondition['then']['required'])->toContain('media');
    expect($rankClipsCondition['then']['required'])->toContain('candidates');
    expect($rankClipsCondition['then']['required'])->toContain('configuration');
    expect($rankClipsCondition['then']['required'])->not->toContain('project_id');
    expect($rankClipsCondition['then']['required'])->not->toContain('storage');
    expect($rankClipsCondition['then']['required'])->not->toContain('idempotency_key');
    expect($rankClipsCondition['then']['required'])->not->toContain('created_at');
    expect($rankClipsCondition['then']['required'])->not->toContain('media_asset_id');
    expect($rankClipsCondition['then']['required'])->not->toContain('recommendation_id');
});

it('validates render_clips action requires action-specific fields (no legacy envelope) and packaged definition adds media_asset_id', function () {
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';

    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $schema = json_decode(file_get_contents($schemaPath), true);

    // Main allOf condition for render_clips
    $renderClipsCondition = null;
    foreach ($schema['allOf'] as $condition) {
        if (isset($condition['if']['properties']['action']['const']) && $condition['if']['properties']['action']['const'] === 'render_clips') {
            $renderClipsCondition = $condition;
            break;
        }
    }

    expect($renderClipsCondition)->not->toBeNull();
    expect($renderClipsCondition['then']['required'])->toContain('media');
    expect($renderClipsCondition['then']['required'])->toContain('recommendation');
    expect($renderClipsCondition['then']['required'])->toContain('candidate_index');
    expect($renderClipsCondition['then']['required'])->toContain('configuration');
    expect($renderClipsCondition['then']['required'])->toContain('source_media');
    expect($renderClipsCondition['then']['required'])->toContain('recommendation_id');
    expect($renderClipsCondition['then']['required'])->not->toContain('project_id');
    expect($renderClipsCondition['then']['required'])->not->toContain('storage');
    expect($renderClipsCondition['then']['required'])->not->toContain('idempotency_key');
    expect($renderClipsCondition['then']['required'])->not->toContain('created_at');
    expect($renderClipsCondition['then']['required'])->not->toContain('media_asset_id');

    // Packaged strict definition also requires media_asset_id
    $packagedDef = $schema['definitions']['render_clips_request'];
    expect($packagedDef['required'])->toContain('media_asset_id');
    expect($packagedDef['required'])->toContain('recommendation_id');
});

it('validates render_clip action requires only action-specific fields (no legacy envelope)', function () {
    $schemaPath = dirname(base_path(), 2).'/services/worker/contracts/media_processing_v1.json';

    if (! file_exists($schemaPath)) {
        $this->markTestSkipped('Worker contract schema not yet created');
    }

    $schema = json_decode(file_get_contents($schemaPath), true);

    $renderClipCondition = null;
    foreach ($schema['allOf'] as $condition) {
        if (isset($condition['if']['properties']['action']['const']) && $condition['if']['properties']['action']['const'] === 'render_clip') {
            $renderClipCondition = $condition;
            break;
        }
    }

    expect($renderClipCondition)->not->toBeNull();
    expect($renderClipCondition['then']['required'])->toContain('media');
    expect($renderClipCondition['then']['required'])->toContain('candidate_index');
    expect($renderClipCondition['then']['required'])->toContain('candidate');
    expect($renderClipCondition['then']['required'])->toContain('configuration');
    expect($renderClipCondition['then']['required'])->toContain('source_media');
    expect($renderClipCondition['then']['required'])->toContain('output_storage');
    expect($renderClipCondition['then']['required'])->not->toContain('project_id');
    expect($renderClipCondition['then']['required'])->not->toContain('storage');
    expect($renderClipCondition['then']['required'])->not->toContain('idempotency_key');
    expect($renderClipCondition['then']['required'])->not->toContain('created_at');
    expect($renderClipCondition['then']['required'])->not->toContain('media_asset_id');
    expect($renderClipCondition['then']['required'])->not->toContain('recommendation_id');

    // Packaged strict definition for render_clip
    $packagedDef = $schema['definitions']['render_clip_request'];
    expect($packagedDef['required'])->toContain('version');
    expect($packagedDef['required'])->toContain('action');
    expect($packagedDef['required'])->toContain('media');
    expect($packagedDef['required'])->toContain('candidate_index');
    expect($packagedDef['required'])->toContain('candidate');
    expect($packagedDef['required'])->toContain('configuration');
    expect($packagedDef['required'])->toContain('source_media');
    expect($packagedDef['required'])->toContain('output_storage');
    expect($packagedDef['required'])->not->toContain('media_asset_id');
    expect($packagedDef['required'])->not->toContain('project_id');
    expect($packagedDef['required'])->not->toContain('storage');
    expect($packagedDef['required'])->not->toContain('idempotency_key');
    expect($packagedDef['required'])->not->toContain('created_at');
});

it('contract array matches schema required fields for probe action (root + probe envelope)', function () {
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

    // Root required fields (only version)
    foreach ($schema['required'] as $field) {
        expect($array)->toHaveKey($field);
    }

    // Probe action required fields from allOf condition (legacy envelope WITHOUT media_asset_id)
    $probeRequired = ['project_id', 'storage', 'idempotency_key', 'created_at'];
    foreach ($probeRequired as $field) {
        expect($array)->toHaveKey($field);
    }

    // Schema root/probe requirements do not require media_asset_id (tested in earlier test cases).
    // The generated Laravel contract from MediaProcessingContract::fromMediaAsset() legitimately includes it.
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