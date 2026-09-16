# Implementation Plan: Asynchronous Processing Job Boundary

## Phase 1: Database Migration

### Add processing state tracking columns

Create a new migration to add columns for processing lifecycle:

```php
// database/migrations/2026_09_16_100000_add_processing_columns_to_media_assets_table.php

Schema::table('media_assets', function (Blueprint $table) {
    $table->string('processing_status', 32)->default('stored')->after('status');
    $table->string('idempotency_key', 36)->nullable()->after('processing_status');
    $table->timestamp('processing_started_at')->nullable()->after('idempotency_key');
    $table->timestamp('processing_completed_at')->nullable()->after('processing_started_at');
    $table->text('processing_error')->nullable()->after('processing_completed_at');
});
```

### Migration considerations

- `status` column remains for backward compatibility
- `processing_status` tracks the async lifecycle
- `idempotency_key` is nullable initially (set on job dispatch)
- Index on `processing_status` for worker polling queries
- Index on `idempotency_key` for deduplication checks

## Phase 2: Model Updates

### MediaAsset model changes

1. Add processing state constants:

```php
const PROCESSING_STORED = 'stored';
const PROCESSING_QUEUED = 'queued';
const PROCESSING_RUNNING = 'processing';
const PROCESSING_COMPLETED = 'completed';
const PROCESSING_FAILED = 'failed';

const VALID_PROCESSING_STATES = [
    self::PROCESSING_STORED,
    self::PROCESSING_QUEUED,
    self::PROCESSING_RUNNING,
    self::PROCESSING_COMPLETED,
    self::PROCESSING_FAILED,
];
```

2. Add fillable fields: `processing_status`, `idempotency_key`, `processing_started_at`, `processing_completed_at`, `processing_error`

3. Add casts: `processing_started_at` => `datetime`, `processing_completed_at` => `datetime`

4. Add state transition methods:

```php
public function markQueued(string $idempotencyKey): void
public function markProcessing(): void
public function markCompleted(): void
public function markFailed(string $error): void
```

5. Add validation helper:

```php
public function isValidTransition(string $from, string $to): bool
```

## Phase 3: Worker Contract

### Contract class

Create `app/Contracts/MediaProcessingContract.php`:

```php
class MediaProcessingContract
{
    public string $version = '1.0.0';
    public int $mediaAssetId;
    public int $projectId;
    public array $storage;
    public string $idempotencyKey;
    public string $createdAt;

    public static function fromMediaAsset(MediaAsset $asset, string $idempotencyKey): self
    public function toArray(): array
    public static function fromArray(array $data): self
    public function validate(): bool
}
```

### Contract validation

- Version must be parseable semver
- Required fields must be present
- Storage disk/key must be non-empty strings
- Idempotency key must be valid UUID v4

## Phase 4: Laravel Job

### ProcessMediaAsset job

Create `app/Jobs/ProcessMediaAsset.php`:

```php
class ProcessMediaAsset implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public MediaAsset $mediaAsset,
        public string $idempotencyKey,
    ) {}

    public function handle(): void
    {
        // 1. Reload media asset to check current state
        // 2. Validate idempotency key matches
        // 3. Mark as processing
        // 4. Build contract
        // 5. Log contract (placeholder for future worker dispatch)
        // 6. Mark as completed (placeholder - real implementation later)
    }

    public function failed(\Throwable $exception): void
    {
        // Mark asset as failed with error message
    }
}
```

### Job characteristics

- Implements `ShouldQueue` for async execution
- Uses `SerializesModels` for deterministic serialization
- Has retry logic (3 attempts by default)
- Uses `uniqueVia` with idempotency key for deduplication
- Logs contract for future worker integration

## Phase 5: Controller Integration

### MediaAssetController changes

Modify the `upload` method to:

1. After successful DB insert, dispatch the job:

```php
// After MediaAsset::create() succeeds
$processingJob = ProcessMediaAsset::dispatch($mediaAsset, Str::uuid());
```

2. The upload response remains synchronous (returns immediately)

3. Status in response is `'stored'` (job dispatch happens after response is prepared)

### Dispatch timing

- Job is dispatched after DB insert succeeds
- Job dispatch is non-blocking (async queue)
- Upload response does not wait for job completion
- If job dispatch fails, log error but don't fail upload

## Phase 6: Worker Boundary

### Worker contract structure

Create `services/worker/` directory structure:

```
services/worker/
├── README.md
├── contracts/
│   └── media_processing_v1.json
└── examples/
    └── sample_contract.json
```

### Contract schema file

Create `services/worker/contracts/media_processing_v1.json`:

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "Media Processing Contract v1.0.0",
  "type": "object",
  "required": ["version", "media_asset_id", "project_id", "storage", "idempotency_key", "created_at"],
  "properties": {
    "version": { "type": "string", "const": "1.0.0" },
    "media_asset_id": { "type": "integer", "minimum": 1 },
    "project_id": { "type": "integer", "minimum": 1 },
    "storage": {
      "type": "object",
      "required": ["disk", "key", "mime_type"],
      "properties": {
        "disk": { "type": "string", "minLength": 1 },
        "key": { "type": "string", "minLength": 1 },
        "mime_type": { "type": "string", "minLength": 1 }
      }
    },
    "idempotency_key": { "type": "string", "format": "uuid" },
    "created_at": { "type": "string", "format": "date-time" }
  }
}
```

## Phase 7: Tests

### Unit tests

1. **MediaAsset state transitions**: Test all valid/invalid transitions
2. **MediaAsset contract generation**: Test contract creation from model
3. **ProcessMediaAsset job**: Test job dispatch, serialization, failure handling
4. **MediaProcessingContract**: Test validation, serialization, deserialization

### Feature tests

1. **Upload dispatches job**: Verify job is dispatched after upload
2. **Upload response unchanged**: Verify response structure remains same
3. **Job idempotency**: Test duplicate dispatch with same key
4. **Job failure handling**: Test failed job updates status
5. **Non-owner cannot trigger processing**: Verify authorization

### Integration tests

1. **Contract schema validation**: Test contract against JSON schema
2. **Worker contract security**: Verify no secrets/PII in contract
3. **Database state consistency**: Test state transitions persist correctly

### Regression tests

1. **Existing upload tests pass**: Verify no breaking changes
2. **Existing list/delete tests pass**: Verify existing endpoints work
3. **Existing authorization tests pass**: Verify security not weakened

## Implementation Order

1. Create migration and run it
2. Update MediaAsset model with constants and methods
3. Create MediaProcessingContract class
4. Create ProcessMediaAsset job
5. Update MediaAssetController to dispatch job
6. Create worker contract schema
7. Write unit tests
8. Write feature tests
9. Write integration tests
10. Verify all existing tests pass
11. Run Pint for code style
12. Update documentation

## Dependencies

- Existing MediaAsset model and migration
- Existing upload endpoint and tests
- Laravel queue system (database driver)
- Pest testing framework
- Storage fake for testing

## Risks

1. **Queue driver**: Using database queue may have performance implications in production. Redis would be better for high-throughput scenarios. This is acceptable for M3 slice 1.

2. **Job retry logic**: Default 3 retries may not be sufficient for all failure scenarios. Can be adjusted in future slices.

3. **Contract versioning**: Future contract changes may require worker updates. Version negotiation will be important.

4. **State consistency**: If job dispatch fails after DB insert, asset remains in `stored` state. This is acceptable - manual retry or re-upload is possible.

## Success Criteria

- All acceptance criteria from spec.md are met
- All existing tests pass unchanged
- New tests provide adequate coverage
- Code follows Laravel best practices
- No security regressions
- Documentation is updated