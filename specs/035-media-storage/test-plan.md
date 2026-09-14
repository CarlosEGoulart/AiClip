# Test Plan: Media Storage (Issue #33)

## Overview

This test plan covers all verification required for the media storage feature.
Tests are organized by layer (backend, frontend, E2E) and follow the TDD cycle:
RED → GREEN → REFACTOR for each feature task.

Every feature task in `plan.md` has a corresponding test-first counterpart below.

---

## 1. Backend Unit Tests (Pest)

Location: `apps/api/tests/Unit/`

### 1.1 MediaAsset Model Test

File: `apps/api/tests/Unit/MediaAssetTest.php`

Tests:
- `MediaAsset` can be created via factory with all expected attributes.
- `MediaAsset` belongs to a `Project`.
- `MediaAsset` default status is `stored`.
- `size_bytes` is cast to integer.
- `storage_key` is unique (duplicate insert throws).

### 1.2 MediaAsset Factory Test

File: `apps/api/tests/Unit/MediaAssetFactoryTest.php`

Tests:
- Factory generates valid attributes with no overrides.
- Factory accepts `project_id` override.
- Factory generates realistic `original_name`, `mime_type`, `size_bytes`.

---

## 2. Backend Feature Tests (Pest)

Location: `apps/api/tests/Feature/Media/`

### 2.1 Upload Endpoint Tests

File: `apps/api/tests/Feature/Media/MediaUploadTest.php`

Tests (using `SpaTestCase` + `RefreshDatabase`):

#### Authentication
- `POST /api/v1/projects/{id}/media/upload` returns 401 for guests.
- `POST /api/v1/projects/{id}/media/upload` returns 201 for authenticated owner.

#### Ownership
- Returns 404 when uploading to a project owned by another user.
- Returns 404 when uploading to a non-existent project.

#### Validation
- Returns 422 when `file` field is missing.
- Returns 422 when file MIME type is not in the accepted list (e.g., `text/plain`).
- Returns 422 when file extension does not match MIME type.
- Returns 413 when file exceeds `MEDIA_MAX_UPLOAD_SIZE`.

#### Storage
- Creates a `MediaAsset` record in the database with correct attributes.
- Stores the file on the configured `media` disk.
- Object key is UUID-based and does not contain the original filename.
- Object key starts with `{project_id}/`.
- `original_name` preserves the user's original filename.
- `mime_type` is detected via `finfo_file`, not user-supplied.
- `size_bytes` matches the uploaded file size.
- `status` is `stored` on success.

#### Response Structure
- Response 201 body matches the API contract (all fields present, correct types).
- `user_id` is not present in the response.

### 2.2 List Endpoint Tests

File: `apps/api/tests/Feature/Media/MediaIndexTest.php`

Tests:

#### Authentication
- `GET /api/v1/projects/{id}/media` returns 401 for guests.

#### Ownership
- Returns 404 when listing media for a project owned by another user.
- Returns 404 for a non-existent project.

#### Behavior
- Returns empty array when project has no media.
- Returns all media assets for the authenticated user's project, ordered by
  `created_at` descending.
- Does not include media from other users' projects.
- Response matches the API contract structure.

### 2.3 Delete Endpoint Tests

File: `apps/api/tests/Feature/Media/MediaDeleteTest.php`

Tests:

#### Authentication
- `DELETE /api/v1/media/{id}` returns 401 for guests.

#### Ownership
- Returns 404 when deleting media owned by another user.
- Returns 404 for a non-existent media ID.

#### Behavior
- Returns 204 on successful deletion.
- Removes the `MediaAsset` record from the database.
- Removes the file from the configured storage disk.
- Deleting one media asset does not affect other assets in the same project.

#### Storage Failure Handling
- When storage deletion throws, the database record is still deleted.
- The storage exception is logged (verify via `Log::shouldReceive('warning')`).

### 2.4 Ownership Isolation Tests

File: `apps/api/tests/Feature/Media/MediaOwnershipTest.php`

Tests:
- User A uploads media to Project A.
- User B cannot list User A's media via Project A.
- User B cannot delete User A's media.
- User B cannot upload to User A's project.
- Creating a media asset with an injected `project_id` belonging to another user
  is rejected (ownership check uses the route parameter, not the payload).

### 2.5 Rate Limiting Tests

File: `apps/api/tests/Feature/Media/MediaThrottleTest.php`

Tests:
- After 10 uploads in one minute, the 11th returns 429.
- Rate limit resets after the window expires (use `Carbon::setTestNow()` to
  advance time).
- The `index` and `destroy` endpoints are NOT rate-limited by `media-upload`.

### 2.6 Cascade Cleanup Tests

File: `apps/api/tests/Feature/Media/MediaCascadeTest.php`

Tests:
- Creating a project with media, then deleting the project:
  - All `MediaAsset` records are removed from the database.
  - All stored objects are removed from the storage disk.
- Deleting a project with no media succeeds without errors.
- If one storage deletion fails during project deletion, remaining media
  objects are still attempted and the project record is still deleted.

---

## 3. Frontend Tests (Vitest + React Testing Library)

Location: `apps/web/src/features/media/`

### 3.1 API Client Tests

File: `apps/web/src/features/media/api/index.test.ts`

Tests (mock `fetch`):

#### getMediaAssets
- Calls `GET /api/v1/projects/{id}/media` with correct headers.
- Returns parsed response on 200.
- Throws `{ type: 'network' }` on network failure.
- Throws `{ type: 'unauthorized' }` on 401.
- Throws `{ type: 'throttle' }` on 429.

#### uploadMediaAsset
- Sends `POST /api/v1/projects/{id}/media/upload` with `FormData`.
- Initializes CSRF cookie before upload.
- Returns parsed response on 201.
- Throws `{ type: 'validation', errors }` on 422.
- Throws `{ type: 'file-too-large' }` on 413.
- Throws `{ type: 'server' }` on 500.

#### deleteMediaAsset
- Sends `DELETE /api/v1/media/{id}` with correct headers.
- Returns `undefined` on 204.
- Throws `{ type: 'unauthorized' }` on 401.

### 3.2 useMediaAssets Hook Tests

File: `apps/web/src/features/media/hooks/index.test.tsx`

Tests (using `renderHook` from RTL):

#### fetchMedia
- Sets `loading` to true during fetch, false after.
- Populates `mediaAssets` on success.
- Sets `error` on failure.
- Does not fetch if `projectId` is null.

#### uploadFile
- Sets `uploading` to true during upload, false after.
- Adds the new media asset to `mediaAssets` on success.
- Sets `error` on failure.
- Returns `true` on success, `false` on failure.

#### deleteMedia
- Removes the media asset from `mediaAssets` on success.
- Sets `error` on failure.
- Returns `true` on success, `false` on failure.

#### clearErrors
- Resets `error` to null.

### 3.3 MediaUploadForm Tests

File: `apps/web/src/features/media/components/MediaUploadForm.test.tsx`

Tests:
- Renders a file input with `accept="video/mp4,video/quicktime,video/webm"`.
- Renders an upload button.
- Shows "Uploading..." text while `uploading` is true.
- Disables the file input and button during upload.
- Shows error message when `error` prop is set.
- Calls `onUpload` with the selected File when form is submitted.
- Resets the file input after successful upload.
- File input has accessible label.

### 3.4 MediaList Tests

File: `apps/web/src/features/media/components/MediaList.test.tsx`

Tests:
- Renders loading state when `loading` is true.
- Renders empty state when `mediaAssets` is empty and not loading.
- Renders a list of media items.
- Each item shows the original filename.
- Each item shows the formatted file size.
- Each item shows the upload date.
- Each item has a delete button.
- Clicking delete triggers the `onDelete` callback with the correct ID.

### 3.5 MediaListItem Tests

File: `apps/web/src/features/media/components/MediaListItem.test.tsx`

Tests:
- Renders the filename.
- Formats bytes to human-readable size (KB for <1MB, MB for <1GB, GB for ≥1GB).
- Shows status badge.
- Shows formatted date.
- Delete button shows confirmation state on first click.
- Confirmation state has "Yes, delete" and "Cancel" buttons.
- Confirming calls `onDelete`.
- Cancel returns to normal state.
- Disables buttons during deletion.

---

## 4. E2E Tests (Playwright)

Location: `apps/web/e2e/media.spec.ts`

### 4.1 Full Upload → List → Delete Flow

```typescript
test.describe('Media Upload and Management', () => {
  test('upload video → appears in list → delete removes it', async ({ page }) => {
    // 1. Register/login
    // 2. Create a project
    // 3. Navigate to project (select it)
    // 4. Upload a valid MP4 file (use a fixture)
    // 5. Assert media list shows the file with correct name
    // 6. Click delete, confirm
    // 7. Assert media list is empty
    // 8. Delete the project
    // 9. Assert project is gone
  });
});
```

### 4.2 Upload Rejection

```typescript
test('rejects non-video file upload', async ({ page }) => {
  // 1. Register/login, create project
  // 2. Try to upload a .txt file
  // 3. Assert error message is displayed
  // 4. Assert no media item appears in the list
});

test('rejects file exceeding size limit', async ({ page }) => {
  // 1. Register/login, create project
  // 2. Try to upload a file > 100MB
  // 3. Assert error message about file size
});
```

### 4.3 Project Deletion Cascades Media

```typescript
test('deleting project removes associated media', async ({ page }) => {
  // 1. Register/login, create project
  // 2. Upload a video
  // 3. Assert media exists in list
  // 4. Delete the project (with confirmation)
  // 5. Assert project is gone from project list
  // 6. (Verify via API that media records are also gone)
});
```

### 4.4 Ownership Isolation

```typescript
test('user cannot access another user media via URL', async ({ page }) => {
  // 1. User A registers, creates project, uploads video
  // 2. User A logs out
  // 3. User B registers
  // 4. User B tries to navigate to User A project media
  // 5. Assert 404 / empty / access denied
});
```

### 4.5 Viewport Testing

All E2E tests run at three viewports:
- 390x844 (mobile)
- 768x1024 (tablet)
- 1440x900 (desktop)

Verify:
- File input is accessible and usable at all viewports.
- Media list renders correctly at all viewports.
- Delete confirmation dialog is properly positioned.
- Upload form layout is not broken at any viewport.

---

## 5. MinIO Integration Test

### 5.1 Verify MinIO Connectivity

File: `apps/api/tests/Feature/Media/MinIOIntegrationTest.php`

Tests (requires running MinIO):
- Can store a file on the `media` disk.
- Can retrieve the file contents.
- Can delete the file.
- File does not exist after deletion.

This test is skipped when `MEDIA_DISK` is not `media` or when MinIO is not running:

```php
beforeEach(function () {
    if (config('filesystems.default') !== 'media') {
        $this->markTestSkipped('MinIO not available');
    }
});
```

### 5.2 Verify Bucket Creation

Manual verification (documented in spec):
- MinIO bucket `aiclip-media` exists after `docker compose up`.
- If not, create via `mc mb local/aiclip-media` or add an init script.

---

## 6. Regression Tests

### 6.1 Existing Backend Tests

Run the full suite:
```sh
cd apps/api && php artisan test --compact
```

All existing tests must pass:
- `tests/Feature/Auth/AuthenticationTest.php`
- `tests/Feature/Auth/CsrfAuthenticationTest.php`
- `tests/Feature/Auth/SessionAuthenticationTest.php`
- `tests/Feature/Auth/ThrottleAuthenticationTest.php`
- `tests/Feature/Auth/ValidationAuthenticationTest.php`
- `tests/Feature/Project/ProjectCrudTest.php`
- `tests/Feature/HealthTest.php`
- `tests/Unit/ExampleTest.php`

### 6.2 Existing Frontend Tests

Run the full suite:
```sh
cd apps/web && npx vitest run
```

All existing tests must pass:
- `features/projects/__tests__/Projects.test.tsx`
- `features/projects/api/index.test.ts`
- `features/auth/hooks/index.test.tsx`
- `features/auth/api/index.test.ts`

### 6.3 Existing E2E Tests

Run the full suite:
```sh
cd apps/web && npx playwright test
```

All existing specs must pass:
- `e2e/health.spec.ts`
- `e2e/auth.spec.ts`
- `e2e/projects.spec.ts`

---

## 7. Security Checklist

| Check                                           | Status |
|------------------------------------------------|--------|
| All media endpoints require `auth:sanctum`      | [ ]    |
| CSRF token validated on upload and delete       | [ ]    |
| Ownership checked via session, not payload      | [ ]    |
| Non-owner gets 404 (not 403) for all operations | [ ]    |
| Original filename not in storage path           | [ ]    |
| MIME type detected server-side via finfo        | [ ]    |
| File size enforced at multiple layers           | [ ]    |
| Storage credentials not in logs or responses    | [ ]    |
| Object storage is private (no public URLs)      | [ ]    |
| No directory traversal possible in storage key  | [ ]    |
| Rate limiting on upload endpoint                | [ ]    |
| Project deletion cascades media cleanup         | [ ]    |
| Storage failure during delete does not crash    | [ ]    |
| `user_id` not exposed in API responses          | [ ]    |
| No injection possible via original_name         | [ ]    |

---

## 8. Test Execution Order

1. **Backend Unit Tests** — verify model and factory.
2. **Backend Feature Tests** — verify API endpoints (upload, list, delete, ownership,
   rate limiting, cascade).
3. **MinIO Integration Test** — verify storage connectivity.
4. **Frontend Unit Tests** — verify API client, hooks, components.
5. **E2E Tests** — verify full user flow across all viewports.
6. **Regression Tests** — verify no existing functionality is broken.
7. **Security Checklist** — manual verification of all security requirements.

---

## 9. Test Data

### Video Fixtures

Create a minimal valid MP4 file for tests:

```sh
# Generate a 1-second blank MP4 (requires ffmpeg)
ffmpeg -f lavfi -i color=c=black:s=320x240:d=1 -c:v libx264 -pix_fmt yuv420p \
  apps/api/tests/fixtures/test-video.mp4
```

Size: ~10-20 KB. Suitable for fast upload tests.

For the file-too-large test, create a mock file exceeding the limit rather than
a real file of that size.

### Test Users

Reuse the existing `User::factory()` and the `SpaTestCase` register/login pattern.
No new test user setup needed.

---

## 10. Known Test Limitations

- **MinIO tests require Docker:** The integration test is skipped when MinIO is not
  running. CI must start MinIO via docker-compose.
- **File size limit test:** The 413 test uses a mock exceeding the limit, not a real
  large file. This is standard practice.
- **Upload speed:** E2E upload tests use a small fixture. Network latency to MinIO
  is minimal in Docker.
- **Concurrency:** Rate limiting tests use `Carbon::setTestNow()` for deterministic
  time advancement. No concurrent test execution issues.