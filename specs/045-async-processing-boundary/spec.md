# Specification: Asynchronous Processing Job Boundary

## Goal

Introduce the first M3 slice: a deterministic asynchronous media-processing boundary between the Laravel application and a future Python media worker, without implementing real transcoding, transcription, scene detection, or ranking yet.

## Context

The current upload flow stores media synchronously and returns immediately with status `'stored'`. There is no mechanism to dispatch processing work to a worker. This issue establishes:

1. Explicit processing lifecycle states
2. A versioned worker-boundary contract
3. A Laravel job that dispatches processing work
4. Deterministic state transitions testable without real worker execution

## Processing Lifecycle States

### State Definitions

| State | Description |
|-------|-------------|
| `stored` | Asset uploaded and stored in S3-compatible storage |
| `queued` | Processing job dispatched to queue, awaiting worker pickup |
| `processing` | Worker has picked up the job and started processing |
| `completed` | Worker completed all processing steps |
| `failed` | Processing failed after retries exhausted or unrecoverable error |

### State Transitions

```
stored → queued      (dispatch processing job)
queued → processing  (worker acknowledges job pickup)
processing → completed (worker reports success)
processing → failed    (worker reports failure)
queued → failed        (dispatch failed or worker rejected)
```

### Invalid Transitions

Invalid transitions MUST NOT silently succeed:
- `completed` → any other state (terminal)
- `failed` → any other state (terminal without manual intervention)
- `stored` → `processing` (must go through `queued`)
- `stored` → `completed` (cannot skip processing)

### Deletion Behavior

- Assets in `stored` state: safe to delete
- Assets in `queued` state: safe to delete (job will become stale)
- Assets in `processing` state: safe to delete (worker result will be orphaned)
- Assets in `completed` state: safe to delete
- Assets in `failed` state: safe to delete

Deletion does NOT require state checks. The worker must handle missing assets gracefully.

## Worker Contract

### Contract Structure

```json
{
  "version": "1.0.0",
  "media_asset_id": 123,
  "project_id": 456,
  "storage": {
    "disk": "media",
    "key": "1/2/uuid.mp4",
    "mime_type": "video/mp4"
  },
  "idempotency_key": "uuid-v4",
  "created_at": "2026-09-16T10:00:00Z"
}
```

### Contract Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `version` | string | yes | Semantic version of contract format |
| `media_asset_id` | integer | yes | Database ID of the media asset |
| `project_id` | integer | yes | Database ID of the parent project |
| `storage.disk` | string | yes | Storage disk name |
| `storage.key` | string | yes | Storage object key |
| `storage.mime_type` | string | yes | MIME type of the media |
| `idempotency_key` | string | yes | UUID v4 for deduplication |
| `created_at` | string | yes | ISO 8601 timestamp of contract creation |

### Contract Versioning

- Version format: `MAJOR.MINOR.PATCH`
- MAJOR: Breaking changes to contract structure
- MINOR: New optional fields
- PATCH: Documentation/correction changes
- Worker MUST reject contracts with unknown MAJOR versions

### Security Boundaries

- Contract contains NO secrets, tokens, or credentials
- Contract contains NO user PII beyond identifiers
- Contract contains NO direct database connection info
- Worker MUST NOT write to PostgreSQL directly
- Worker MUST communicate results back via HTTP callback or polling

## API Changes

### Upload Response

The upload response already includes `status` field. The status will now transition through the lifecycle:

```json
{
  "data": {
    "id": 123,
    "project_id": 456,
    "original_name": "video.mp4",
    "mime_type": "video/mp4",
    "size_bytes": 1024000,
    "status": "stored",
    "created_at": "2026-09-16T10:00:00Z",
    "updated_at": "2026-09-16T10:00:00Z"
  }
}
```

After job dispatch, status transitions to `queued`. The upload endpoint remains synchronous for storage but dispatches processing asynchronously.

### No New Endpoints

This issue does NOT introduce:
- New API endpoints
- Status polling endpoints
- Webhook endpoints
- Worker callback endpoints

These will be introduced in subsequent M3 slices.

## Architectural Invariants

1. Laravel remains authoritative for auth, authorization, ownership, PostgreSQL state, job dispatch, processing lifecycle
2. Python worker MUST NOT write PostgreSQL directly
3. Binary media remains in S3-compatible object storage
4. HTTP upload must NOT execute heavy media processing synchronously
5. Upload success must not wait for worker processing
6. Original uploaded media must never be overwritten
7. Retry/repeated dispatch must be deterministic and idempotent
8. CI must use deterministic fakes/fixtures
9. No external AI provider or model download is allowed
10. Secrets remain outside committed files
11. **Backend CI must provide a real MinIO-compatible object-storage service and must not report success merely because MinIO integration tests were skipped.**

## Acceptance Criteria

1. MediaAsset model defines explicit processing states as constants
2. MediaAsset model provides state transition methods with validation
3. A Laravel job `ProcessMediaAsset` exists and implements the worker contract
4. The job dispatches asynchronously after upload (not synchronously)
5. Upload endpoint returns immediately with status `'stored'`, then dispatches job
6. Job dispatch transitions status from `stored` to `queued`
7. Worker contract is versioned and contains required fields only
8. Job serialization/deserialization is deterministic
9. Duplicate job dispatch is idempotent (same idempotency_key)
10. Failed job dispatch sets status to `failed`
11. Existing upload, list, delete endpoints continue to work unchanged
12. Existing tests pass without modification
13. New tests cover state transitions, job dispatch, idempotency, and failure handling
14. Backend CI workflow starts a MinIO service container with health check before running tests
15. CI provides `media` disk env vars (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`) pointing to the CI MinIO instance
16. CI initializes the `aiclip-media` bucket via `mc` CLI before running tests
17. MinIO health/readiness failure causes the CI job to fail immediately
18. `MinIOIntegrationTest` executes (not skipped) when MinIO is available in CI

## Out of Scope

- FFmpeg transcoding
- FFprobe extraction beyond deterministic fixtures
- Whisper/transcription
- Scene detection
- OpenCV/MediaPipe
- Ranking
- Captions
- Rendering
- AI inference
- Redis/RabbitMQ/Kafka/Celery (unless explicitly justified)
- Worker implementation (Python)
- Worker callback/polling endpoints
- Status polling API
- Real worker execution in CI
- MinIO in CI is explicitly IN SCOPE (see acceptance criteria 14-18 and invariant 11)

## Test Scenarios

1. Upload schedules processing job asynchronously
2. Upload response does not depend on processing completion
3. Correct processing state transition: stored → queued
4. Duplicate dispatch uses same idempotency_key (idempotent)
5. Failed job dispatch sets status to failed
6. Worker contract contains all required fields
7. Worker contract excludes secrets and PII
8. Job serialization is deterministic
9. Existing upload/list/delete endpoints work unchanged
10. Non-owner cannot trigger processing for other user's assets
