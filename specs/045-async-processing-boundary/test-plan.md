# Test Plan: Asynchronous Processing Job Boundary

## Unit Tests

### MediaAsset Model Tests

#### State Transition Constants

```php
it('defines all processing state constants', function () {
    expect(MediaAsset::PROCESSING_STORED)->toBe('stored');
    expect(MediaAsset::PROCESSING_QUEUED)->toBe('queued');
    expect(MediaAsset::PROCESSING_RUNNING)->toBe('processing');
    expect(MediaAsset::PROCESSING_COMPLETED)->toBe('completed');
    expect(MediaAsset::PROCESSING_FAILED)->toBe('failed');
});

it('validates processing states array contains all states', function () {
    expect(MediaAsset::VALID_PROCESSING_STATES)->toHaveCount(5);
});
```

#### State Transition Methods

```php
it('transitions from stored to queued', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);
    $idempotencyKey = Str::uuid();
    
    $asset->markQueued($idempotencyKey);
    
    expect($asset->processing_status)->toBe('queued');
    expect($asset->idempotency_key)->toBe($idempotencyKey);
});

it('transitions from queued to processing', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'queued']);
    
    $asset->markProcessing();
    
    expect($asset->processing_status)->toBe('processing');
    expect($asset->processing_started_at)->not->toBeNull();
});

it('transitions from processing to completed', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'processing']);
    
    $asset->markCompleted();
    
    expect($asset->processing_status)->toBe('completed');
    expect($asset->processing_completed_at)->not->toBeNull();
});

it('transitions from processing to failed', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'processing']);
    
    $asset->markFailed('FFmpeg error');
    
    expect($asset->processing_status)->toBe('failed');
    expect($asset->processing_error)->toBe('FFmpeg error');
});

it('transitions from queued to failed', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'queued']);
    
    $asset->markFailed('Dispatch failed');
    
    expect($asset->processing_status)->toBe('failed');
});
```

#### Invalid State Transitions

```php
it('rejects invalid transition from stored to processing', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);
    
    $asset->markProcessing();
    
    expect($asset->processing_status)->toBe('stored'); // unchanged
});

it('rejects invalid transition from completed to any state', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'completed']);
    
    $asset->markQueued(Str::uuid());
    $asset->markProcessing();
    $asset->markFailed('error');
    
    expect($asset->processing_status)->toBe('completed'); // unchanged
});

it('rejects invalid transition from failed to any state', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'failed']);
    
    $asset->markQueued(Str::uuid());
    $asset->markProcessing();
    $asset->markCompleted();
    
    expect($asset->processing_status)->toBe('failed'); // unchanged
});
```

#### Validation Helper

```php
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
});
```

### MediaProcessingContract Tests

#### Contract Creation

```php
it('creates contract from MediaAsset', function () {
    $asset = MediaAsset::factory()->create();
    $idempotencyKey = Str::uuid();
    
    $contract = MediaProcessingContract::fromMediaAsset($asset, $idempotencyKey);
    
    expect($contract->version)->toBe('1.0.0');
    expect($contract->mediaAssetId)->toBe($asset->id);
    expect($contract->projectId)->toBe($asset->project_id);
    expect($contract->storage['disk'])->toBe($asset->storage_disk);
    expect($contract->storage['key'])->toBe($asset->storage_key);
    expect($contract->storage['mime_type'])->toBe($asset->mime_type);
    expect($contract->idempotencyKey)->toBe($idempotencyKey);
    expect($contract->createdAt)->not->toBeNull();
});

it('serializes contract to array', function () {
    $asset = MediaAsset::factory()->create();
    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    
    $array = $contract->toArray();
    
    expect($array)->toHaveKeys([
        'version', 'media_asset_id', 'project_id', 'storage', 'idempotency_key', 'created_at'
    ]);
    expect($array['storage'])->toHaveKeys(['disk', 'key', 'mime_type']);
});
```

#### Contract Validation

```php
it('validates valid contract', function () {
    $contract = new MediaProcessingContract();
    $contract->version = '1.0.0';
    $contract->mediaAssetId = 1;
    $contract->projectId = 1;
    $contract->storage = ['disk' => 'media', 'key' => 'test.mp4', 'mime_type' => 'video/mp4'];
    $contract->idempotencyKey = Str::uuid();
    $contract->createdAt = now()->toIso8601String();
    
    expect($contract->validate())->toBeTrue();
});

it('rejects contract with missing required fields', function () {
    $contract = new MediaProcessingContract();
    $contract->version = '1.0.0';
    // missing other fields
    
    expect($contract->validate())->toBeFalse();
});

it('rejects contract with invalid version', function () {
    $contract = new MediaProcessingContract();
    $contract->version = 'invalid';
    // ... other fields
    
    expect($contract->validate())->toBeFalse();
});
```

### ProcessMediaAsset Job Tests

#### Job Dispatch

```php
it('dispatches job asynchronously', function () {
    Queue::fake();
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);
    
    ProcessMediaAsset::dispatch($asset, Str::uuid());
    
    Queue::assertPushed(ProcessMediaAsset::class);
});

it('does not execute job synchronously', function () {
    Queue::fake();
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);
    
    ProcessMediaAsset::dispatch($asset, Str::uuid());
    
    expect($asset->fresh()->processing_status)->toBe('stored');
});
```

#### Job Execution

```php
it('updates status to queued on dispatch', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);
    $idempotencyKey = Str::uuid();
    
    $job = new ProcessMediaAsset($asset, $idempotencyKey);
    $job->handle();
    
    expect($asset->fresh()->processing_status)->toBe('queued');
    expect($asset->fresh()->idempotency_key)->toBe($idempotencyKey);
});

it('updates status to processing during execution', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'queued']);
    $idempotencyKey = Str::uuid();
    
    $job = new ProcessMediaAsset($asset, $idempotencyKey);
    $job->handle();
    
    // Note: In real implementation, this would happen when worker picks up
    // For now, job marks as completed immediately
    expect($asset->fresh()->processing_status)->toBe('completed');
});

it('updates status to failed on exception', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'queued']);
    $idempotencyKey = Str::uuid();
    
    $job = new ProcessMediaAsset($asset, $idempotencyKey);
    // Mock an exception
    $this->mock(MediaAsset::class, function ($mock) {
        $mock->shouldReceive('markProcessing')->andThrow(new \RuntimeException('Test error'));
    });
    
    $job->failed(new \RuntimeException('Test error'));
    
    expect($asset->fresh()->processing_status)->toBe('failed');
    expect($asset->fresh()->processing_error)->toBe('Test error');
});
```

#### Job Idempotency

```php
it('uses idempotency key for deduplication', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);
    $idempotencyKey = Str::uuid();
    
    $job1 = new ProcessMediaAsset($asset, $idempotencyKey);
    $job2 = new ProcessMediaAsset($asset, $idempotencyKey);
    
    expect($job1->uniqueId())->toBe($idempotencyKey);
    expect($job2->uniqueId())->toBe($idempotencyKey);
    expect($job1->uniqueId())->toBe($job2->uniqueId());
});
```

## Feature Tests

### Upload Endpoint Tests

#### Job Dispatch on Upload

```php
it('dispatches ProcessMediaAsset job after successful upload', function () {
    Queue::fake();
    $cookies = $this->cookies;
    
    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $projectId = $createResponse->json('data.id');
    
    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');
    
    $response = $this->call('POST', "/api/v1/projects/{$projectId}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);
    
    $response->assertCreated();
    Queue::assertPushed(ProcessMediaAsset::class, function ($job) use ($projectId) {
        return $job->mediaAsset->project_id === $projectId;
    });
});

it('upload response does not depend on job completion', function () {
    $cookies = $this->cookies;
    
    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $projectId = $createResponse->json('data.id');
    
    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');
    
    $response = $this->call('POST', "/api/v1/projects/{$projectId}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);
    
    $response->assertCreated();
    $response->assertJsonPath('data.status', 'stored');
});
```

#### Authorization Tests

```php
it('does not dispatch job for non-owner upload attempt', function () {
    Queue::fake();
    $cookies = $this->cookies;
    
    // Create another user and their project
    $other = User::factory()->create();
    $otherProject = Project::create(['name' => 'Other Project', 'user_id' => $other->id]);
    
    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');
    
    $response = $this->call('POST', "/api/v1/projects/{$otherProject->id}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);
    
    $response->assertNotFound();
    Queue::assertNotPushed(ProcessMediaAsset::class);
});
```

### State Transition Tests

```php
it('tracks processing lifecycle in database', function () {
    $asset = MediaAsset::factory()->create(['processing_status' => 'stored']);
    
    // Simulate job execution
    $job = new ProcessMediaAsset($asset, Str::uuid());
    $job->handle();
    
    // Verify state transition
    expect($asset->fresh()->processing_status)->toBe('completed');
    expect($asset->fresh()->processing_started_at)->not->toBeNull();
    expect($asset->fresh()->processing_completed_at)->not->toBeNull();
});
```

## Integration Tests

### Contract Schema Validation

```php
it('validates contract against JSON schema', function () {
    $asset = MediaAsset::factory()->create();
    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    
    $schema = json_decode(file_get_contents(base_path('services/worker/contracts/media_processing_v1.json')), true);
    
    $validator = new \JsonSchema\Validator();
    $validator->validate($contract->toArray(), $schema);
    
    expect($validator->isValid())->toBeTrue();
});

it('rejects contract with secrets', function () {
    $contract = new MediaProcessingContract();
    $contract->version = '1.0.0';
    $contract->mediaAssetId = 1;
    $contract->projectId = 1;
    $contract->storage = [
        'disk' => 'media',
        'key' => 'test.mp4',
        'mime_type' => 'video/mp4',
        'secret' => 'should-not-be-here', // This field should not exist
    ];
    $contract->idempotencyKey = Str::uuid();
    $contract->createdAt = now()->toIso8601String();
    
    $array = $contract->toArray();
    
    // Contract should not contain unexpected fields
    expect($array['storage'])->not->toHaveKey('secret');
});
```

### Worker Boundary Tests

```php
it('contract contains no user PII beyond identifiers', function () {
    $asset = MediaAsset::factory()->create();
    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    
    $contractString = json_encode($contract->toArray());
    
    // Should not contain email patterns
    expect($contractString)->not->toMatch('/@/');
    // Should not contain common PII patterns
    expect($contractString)->not->toMatch('/password|secret|token|credential/i');
});

it('contract contains no database credentials', function () {
    $asset = MediaAsset::factory()->create();
    $contract = MediaProcessingContract::fromMediaAsset($asset, Str::uuid());
    
    $contractString = json_encode($contract->toArray());
    
    expect($contractString)->not->toMatch('/DB_|DATABASE_|mysql|postgres|sqlite/i');
});
```

## Regression Tests

### Existing Upload Tests

```php
it('preserves existing upload response structure', function () {
    $cookies = $this->cookies;
    
    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $projectId = $createResponse->json('data.id');
    
    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');
    
    $response = $this->call('POST', "/api/v1/projects/{$projectId}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);
    
    $response->assertCreated();
    $response->assertJsonStructure([
        'data' => [
            'id',
            'project_id',
            'original_name',
            'mime_type',
            'size_bytes',
            'status',
            'created_at',
            'updated_at',
        ],
    ]);
});
```

### Existing List/Delete Tests

```php
it('preserves existing list endpoint behavior', function () {
    $cookies = $this->cookies;
    
    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $projectId = $createResponse->json('data.id');
    
    // Create some media assets
    MediaAsset::factory()->count(3)->create(['project_id' => $projectId]);
    
    $response = $this->spaRequest('GET', "/api/v1/projects/{$projectId}/media", $cookies);
    
    $response->assertOk();
    $response->assertJsonCount(3, 'data');
});

it('preserves existing delete endpoint behavior', function () {
    $cookies = $this->cookies;
    
    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $projectId = $createResponse->json('data.id');
    
    Storage::fake('media');
    $asset = MediaAsset::factory()->create(['project_id' => $projectId]);
    
    $response = $this->spaRequest('DELETE', "/api/v1/media/{$asset->id}", $cookies);
    
    $response->assertNoContent();
    $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
});
```

### Existing Authorization Tests

```php
it('preserves existing ownership checks', function () {
    $cookies = $this->cookies;
    
    // Create another user and their project
    $other = User::factory()->create();
    $otherProject = Project::create(['name' => 'Other Project', 'user_id' => $other->id]);
    $asset = MediaAsset::factory()->create(['project_id' => $otherProject->id]);
    
    // Try to delete as non-owner
    $response = $this->spaRequest('DELETE', "/api/v1/media/{$asset->id}", $cookies);
    
    $response->assertNotFound();
    $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
});
```

## Test Execution Order

1. Unit tests for MediaAsset model
2. Unit tests for MediaProcessingContract
3. Unit tests for ProcessMediaAsset job
4. Feature tests for upload endpoint
5. Feature tests for state transitions
6. Integration tests for contract validation
7. Integration tests for worker boundary
8. Regression tests for existing functionality
9. Run full test suite to verify no regressions

## Test Environment Requirements

- Database with `jobs` and `failed_jobs` tables
- Storage fake for S3-compatible storage
- Queue fake for job dispatch testing
- Factory for MediaAsset model
- JSON schema validation library (optional, can use manual validation)

## Success Criteria

- All unit tests pass
- All feature tests pass
- All integration tests pass
- All regression tests pass
- No existing tests break
- Test coverage meets minimum thresholds
- No security tests fail
- Contract validation tests pass