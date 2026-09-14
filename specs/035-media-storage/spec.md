# feat(media): add project-scoped video upload and S3-compatible storage

## Description

Implement the foundational media storage capability for AiClip: an authenticated user
who owns a project can upload a video file, which is validated, stored in a private
S3-compatible object store, and tracked via metadata in PostgreSQL. The user can then
list the media assets belonging to their project and delete any they own. When a
project is deleted, all associated media objects are cleaned from object storage.

This issue covers **storage only**. No transcription, FFmpeg processing, queue
dispatching, or Python worker integration is included.

## User Stories

1. **As an authenticated user**, I can upload a video file to one of my projects so
   that it is stored safely for later processing.
2. **As an authenticated user**, I can see a list of media assets I have uploaded to
   a project so that I can manage my content.
3. **As an authenticated user**, I can delete a media asset I own so that I can remove
   unwanted uploads.
4. **As an authenticated user**, when I delete a project, all its media files are
   removed from object storage and the database.

## MediaAsset Model

### Table: `media_assets`

| Column          | Type         | Constraints                                   | Description                              |
|-----------------|--------------|-----------------------------------------------|------------------------------------------|
| id              | bigint       | PK, auto-increment                            | Primary key                              |
| project_id      | bigint       | FK → projects.id, ON DELETE CASCADE           | Owning project                           |
| original_name   | varchar(255) | NOT NULL                                      | User-provided filename (sanitized)       |
| storage_disk    | varchar(64)  | NOT NULL, default from config                 | Filesystem disk name (e.g. `media`)      |
| storage_key     | varchar(512) | NOT NULL, UNIQUE                              | Object key (uuid-based, private)         |
| mime_type       | varchar(128) | NOT NULL                                      | Detected MIME type                       |
| size_bytes      | bigint       | NOT NULL                                      | File size in bytes                       |
| status          | varchar(32)  | NOT NULL, default `stored`                    | Current lifecycle state                  |
| created_at      | timestamp    | NOT NULL                                      | Creation timestamp                       |
| updated_at      | timestamp    | NOT NULL                                      | Last update timestamp                    |

### Status Values

| Value     | Meaning                                                      |
|-----------|--------------------------------------------------------------|
| stored    | Binary is in object storage, metadata is in DB. Ready.       |
| failed    | Upload or storage failed. Error info on the record.          |

> The `processing` and `completed` statuses are reserved for future media-pipeline
> issues. This issue only implements `stored` and `failed`.

### Eloquent Model: `App\Models\MediaAsset`

```php
class MediaAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'original_name',
        'storage_disk',
        'storage_key',
        'mime_type',
        'size_bytes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
```

### Relationships

- `Project` model gains `mediaAssets(): HasMany` relationship.
- `User` model transitively accesses media through `projects`.

## API Contract

All endpoints require `auth:sanctum` and CSRF protection.

### POST `/api/v1/projects/{project}/media/upload`

**Purpose:** Upload a video file to a project.

**Request:**
- Content-Type: `multipart/form-data`
- Field: `file` (required, binary)
- No JSON body.

**Validation:**
- `file` required, file upload
- MIME type must be one of: `video/mp4`, `video/quicktime`, `video/webm`
- File size must not exceed `MEDIA_MAX_UPLOAD_SIZE` (default: 104857600 = 100 MB)
- Project must exist and be owned by the authenticated user (404 if not)

**Response 201:**
```json
{
  "data": {
    "id": 1,
    "project_id": 1,
    "original_name": "my-video.mp4",
    "storage_disk": "media",
    "storage_key": "a1b2c3d4-e5f6-7890-abcd-ef1234567890.mp4",
    "mime_type": "video/mp4",
    "size_bytes": 52428800,
    "status": "stored",
    "created_at": "2026-09-14T12:00:00.000000Z",
    "updated_at": "2026-09-14T12:00:00.000000Z"
  }
}
```

**Response 404:** Project not found or not owned by user.
**Response 413:** File exceeds `MEDIA_MAX_UPLOAD_SIZE`.
**Response 422:** Validation failed (wrong MIME type, missing file).
**Response 429:** Rate limited.

### GET `/api/v1/projects/{project}/media`

**Purpose:** List all media assets for a project.

**Request:** No body.

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "project_id": 1,
      "original_name": "my-video.mp4",
      "storage_disk": "media",
      "storage_key": "a1b2c3d4-e5f6-7890-abcd-ef1234567890.mp4",
      "mime_type": "video/mp4",
      "size_bytes": 52428800,
      "status": "stored",
      "created_at": "2026-09-14T12:00:00.000000Z",
      "updated_at": "2026-09-14T12:00:00.000000Z"
    }
  ]
}
```

**Response 404:** Project not found or not owned by user.

### DELETE `/api/v1/media/{media}`

**Purpose:** Delete a media asset and its stored binary.

**Request:** No body.

**Response 204:** No content.

**Deletion strategy:**
1. Load the `MediaAsset` record.
2. Verify the authenticated user owns the parent project (404 if not).
3. Attempt to delete the object from storage.
4. Delete the database record.
5. If storage deletion fails, still delete the database record but log the error.
   The orphaned storage object will be cleaned by a future lifecycle policy or
   manual audit.

**Response 404:** Media asset not found or not owned by user.

## Ownership Model

All ownership checks are **transitive from the session**:

1. Auth user → Project: `$project->user_id === $request->user()->id`
2. Project → MediaAsset: `$mediaAsset->project_id === $project->id`

The frontend **never** supplies `user_id` or `project_id` in request payloads for
ownership determination. The server resolves the project from the URL parameter and
the media asset from its database record. A user can never access another user's media.

## Storage Configuration

### Environment Variables

| Variable                      | Default          | Description                              |
|-------------------------------|------------------|------------------------------------------|
| `MEDIA_DISK`                  | `media`          | Filesystem disk for media storage        |
| `MEDIA_MAX_UPLOAD_SIZE`       | `104857600`      | Max upload size in bytes (100 MB)        |
| `AWS_ACCESS_KEY_ID`           | —                | S3-compatible access key                 |
| `AWS_SECRET_ACCESS_KEY`       | —                | S3-compatible secret key                 |
| `AWS_DEFAULT_REGION`          | `us-east-1`      | S3-compatible region                     |
| `AWS_BUCKET`                  | —                | S3-compatible bucket name                |
| `AWS_ENDPOINT`                | —                | S3-compatible endpoint URL               |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` (dev)     | Use path-style for MinIO compatibility   |

### Filesystem Disk

A new `media` disk is added to `config/filesystems.php`:

```php
'media' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', true),
    'throw' => false,
],
```

The `throw => false` ensures storage failures are reported as exceptions
that the controller can catch and handle gracefully.

### Object Key Strategy

Every uploaded file gets a new UUID-based object key:

```
{project_id}/{uuid}.{extension}
```

Example: `42/a1b2c3d4-e5f6-7890-abcd-ef1234567890.mp4`

- The original filename is **never** used in the storage path.
- The UUID guarantees global uniqueness.
- The directory prefix is the project ID, enabling prefix-based listing and
  cleanup during project deletion.
- The extension is derived from the validated MIME type, not the user-provided
  filename.

### MinIO Configuration (Development)

MinIO runs as a Docker service in `docker-compose.yml`:

```yaml
minio:
  image: minio/minio:latest
  container_name: aiclip-minio
  ports:
    - "9000:9000"
    - "9001:9001"
  environment:
    MINIO_ROOT_USER: minioadmin
    MINIO_ROOT_PASSWORD: minioadmin
  volumes:
    - miniodata:/data
  command: server /data --console-address ":9001"
  healthcheck:
    test: ["CMD", "mc", "ready", "local"]
    interval: 10s
    timeout: 5s
    retries: 5
```

The `media` bucket must be created on first run or via an init script.
The `.env.example` for the API must include all MinIO-compatible env vars.

## Video Validation

Accepted MIME types (server-side, from `finfo_file` / upload MIME detection):

| MIME Type         | Extension | Accepted |
|-------------------|-----------|----------|
| video/mp4         | .mp4      | Yes      |
| video/quicktime   | .mov      | Yes      |
| video/webm        | .webm     | Yes      |

The frontend should also enforce the same accept list via the `accept` attribute
on the file input, but the server is authoritative.

### File Size Limit

Default: **100 MB** (104,857,600 bytes).

This is enforced via:
1. `php.ini` `upload_max_filesize` and `post_max_size` (must be ≥ MEDIA_MAX_UPLOAD_SIZE).
2. `StoreMediaUploadRequest` validation rule.
3. The controller catches `UploadedFileTooLargeException` for a 413 response.

This limit is a **known limitation**. It is sufficient for MVP clip generation
from typical short-form source videos. Larger files will be supported when
chunked/resumable uploads are implemented in a future milestone.

## Failure Handling

### Storage succeeds, DB insert fails

If the binary is written to object storage but the database insert throws
(e.g. constraint violation), the controller catches the exception, attempts
to delete the orphaned object from storage, and re-throws the original error.

### DB record exists, storage delete fails on media deletion

When `DELETE /api/v1/media/{media}` is called:
1. The database record is always deleted.
2. If storage deletion throws, the error is logged but does not prevent
   the database record from being removed.
3. Orphaned objects are cleaned by future lifecycle policies.

### Project deletion cascades

When a project is deleted:
1. All `MediaAsset` records for the project are cascade-deleted (DB FK constraint).
2. The project's media objects are individually deleted from object storage.
3. If any storage deletion fails, the remaining objects are logged and cleaned later.
4. The project deletion is not rolled back due to storage failures.

## Rate Limiting

The upload endpoint applies per-user rate limiting:

```
throttle:media-upload
```

Configuration (in `RouteServiceProvider` or `AppServiceProvider`):
- 10 uploads per minute per user.
- Returns 429 with standard rate-limit headers.

## Security Requirements

1. **No tokens in logs or responses:** Storage credentials never appear in
   API responses, exception messages, or application logs.
2. **No original filenames in paths:** Object keys are UUID-based.
3. **Private storage:** All media objects are stored with private visibility.
   No public URLs are generated.
4. **Ownership validation:** Every endpoint validates project ownership via
   the session user, never trusting client-supplied identifiers.
5. **MIME type enforcement:** Server-side `finfo_file` detection, not
   user-provided Content-Type.
6. **File size enforcement:** Multiple layers (php.ini, validation, controller).
7. **CSRF protection:** All state-changing endpoints require CSRF token via
   `X-XSRF-TOKEN` header (SPA mode).
8. **Auth required:** Every media endpoint requires `auth:sanctum`.
9. **No directory traversal:** Storage keys are UUID-based, no user input
   flows into the key path except the validated extension.
10. **Isolation:** Users cannot access media belonging to other users' projects.

## Out of Scope

The following are explicitly **not** part of this issue:

- Transcription or audio extraction
- FFmpeg processing or transcoding
- Scene detection or clip analysis
- Queue jobs or async processing
- Python media worker integration
- Media status transitions beyond `stored` and `failed`
- Video thumbnail generation
- Streaming/presigned URL generation
- Chunked or resumable uploads
- Media download endpoint
- Media update/edit endpoint
- Bulk upload
- Drag-and-drop upload UI
- Upload progress bar (basic status text only)
- Metadata extraction (duration, resolution, codec)
- Redis or any external cache
- CDN integration
- Media versioning
- Clip or image entities

## Acceptance Criteria

- [ ] `MediaAsset` model exists with correct fields, casts, and relationships.
- [ ] `media_assets` migration creates the table with all constraints.
- [ ] `media` disk is configured in `filesystems.php` and reads `MEDIA_DISK` env var.
- [ ] MinIO service is added to `docker-compose.yml` and the app can connect.
- [ ] `.env.example` includes all required S3/MinIO environment variables.
- [ ] `POST /api/v1/projects/{project}/media/upload` stores a valid video and returns 201.
- [ ] `POST /api/v1/projects/{project}/media/upload` rejects non-video MIME types with 422.
- [ ] `POST /api/v1/projects/{project}/media/upload` rejects files over the size limit with 413.
- [ ] `POST /api/v1/projects/{project}/media/upload` returns 404 for non-owned projects.
- [ ] `POST /api/v1/projects/{project}/media/upload` returns 401 for unauthenticated requests.
- [ ] `GET /api/v1/projects/{project}/media` returns the list of media for an owned project.
- [ ] `GET /api/v1/projects/{project}/media` returns 404 for non-owned projects.
- [ ] `DELETE /api/v1/media/{media}` removes both the DB record and the storage object.
- [ ] `DELETE /api/v1/media/{media}` returns 404 for non-owned media.
- [ ] Deleting a project removes all its media from storage and the database.
- [ ] Rate limiting is applied to the upload endpoint (10/min per user).
- [ ] Object keys are UUID-based and never contain the original filename.
- [ ] All media endpoints require `auth:sanctum` and CSRF protection.
- [ ] Existing authentication, project, and health tests continue to pass.
- [ ] Unit tests cover all backend logic (ownership, validation, storage, cleanup).
- [ ] Vitest tests cover frontend components (upload form, media list).
- [ ] Playwright E2E covers the full upload → list → delete flow.
- [ ] Visual review at 390x844, 768x1024, 1440x900 viewports.
- [ ] All code, comments, tests, and documentation in English.

## Dependencies

- Issue #30 (project management) — completed, merged.
- Sanctum SPA auth — completed, merged.
- PostgreSQL database — running.
- MinIO Docker service — created by this issue.