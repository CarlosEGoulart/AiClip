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

## Phase 8: CI MinIO Infrastructure

### Objective

Ensure Backend CI provides a real MinIO-compatible object-storage service so that `MinIOIntegrationTest` executes rather than skipping. This is a CI invariant per spec invariant #11.

### Workflow changes (`.github/workflows/backend.yml`)

1. **Add MinIO service container** alongside the existing PostgreSQL service:

```yaml
services:
  postgres:
    # ... existing config (unchanged) ...

  minio:
    image: quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z
    ports:
      - 9000:9000
    env:
      MINIO_ROOT_USER: minioadmin
      MINIO_ROOT_PASSWORD: minioadmin
    options: >-
      --health-cmd "curl -f --silent --show-error http://127.0.0.1:9000/minio/health/ready"
      --health-interval 10s
      --health-timeout 5s
      --health-retries 5
```

2. **Add bucket initialization step** after "Prepare Environment" and before "Run Migrations":

```yaml
- name: Initialize MinIO Bucket
  run: |
    # Install mc client
    curl -sSL https://dl.min.io/client/mc/release/linux-amd64/mc -o /usr/local/bin/mc
    chmod +x /usr/local/bin/mc
    # Configure alias
    mc alias set aiclip http://127.0.0.1:9000 minioadmin minioadmin
    # Create bucket (idempotent)
    mc mb --ignore-existing aiclip/aiclip-media
    # Set private access
    mc anonymous set private aiclip/aiclip-media
    echo "MinIO bucket initialized successfully"
```

3. **Add media disk environment variables** to both "Run Migrations" and "Run Tests" steps:

```yaml
env:
  # ... existing DB vars ...
  AWS_ACCESS_KEY_ID: minioadmin
  AWS_SECRET_ACCESS_KEY: minioadmin
  AWS_DEFAULT_REGION: us-east-1
  AWS_BUCKET: aiclip-media
  AWS_ENDPOINT: http://127.0.0.1:9000
  AWS_USE_PATH_STYLE_ENDPOINT: "true"
```

### Why not use docker-compose.yml MinIO service directly

GitHub Actions `services` containers are the native mechanism for service dependencies in CI. They support health checks, automatic readiness gating, and port mapping. The `docker-compose.yml` MinIO configuration (with `minio-init` bucket setup) is preserved for local development. CI uses its own health-check-gated MinIO service and a shell-based `mc` bucket init step, which is simpler and does not depend on docker-compose.

### Failure behavior

- If MinIO container fails health check, the step will not proceed (GitHub Actions service health gating).
- If `mc mb` or `mc anonymous set` fails, the step exits non-zero and CI fails.
- If MinIO is unreachable during the test, `MinIOIntegrationTest::beforeEach` will throw, causing a test failure rather than a skip (per the test's own design: "Missing infrastructure is a failed prerequisite, not a skipped pass").

## Implementation Order

1. Create migration and run it
2. Update MediaAsset model with constants and methods
3. Create MediaProcessingContract class
4. Create ProcessMediaAsset job
5. Update MediaAssetController to dispatch job
6. Create worker contract schema
7. **Update `.github/workflows/backend.yml` with MinIO service, bucket init, and media disk env vars (Phase 8)**
8. Write unit tests
9. Write feature tests
10. Write integration tests
11. Verify all existing tests pass including MinIOIntegrationTest (no skips)
12. Run Pint for code style
13. Update documentation

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

5. **MinIO image version pinning**: CI MinIO image is pinned to a specific release. Upgrades should be deliberate to avoid test flakiness from API changes.

## Success Criteria

- All acceptance criteria from spec.md are met
- All existing tests pass unchanged
- New tests provide adequate coverage
- Code follows Laravel best practices
- No security regressions
- Documentation is updated