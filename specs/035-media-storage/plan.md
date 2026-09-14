# Implementation Plan: Media Storage (Issue #33)

## Overview

This plan implements project-scoped video upload and S3-compatible storage following
TDD (RED → GREEN → REFACTOR) for every feature task. Each task is ordered to minimize
integration risk and enable incremental testing.

## Phase 1: Infrastructure & Database (Backend)

### Task 1.1 — Add MinIO to docker-compose.yml

**Files:** `docker-compose.yml`

Add the MinIO service definition alongside the existing `postgres` service.
Create a named volume `miniodata`. Add a health check. No application code changes.

**TDD:** No tests needed. Verified by `docker compose up` and MinIO console at
`http://localhost:9001`.

---

### Task 1.2 — Configure S3-compatible filesystem disk

**Files:** `apps/api/config/filesystems.php`, `apps/api/.env.example`

Add the `media` disk configuration to `filesystems.php` using the `s3` driver with
env vars: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`,
`AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`.

Update `.env.example` to include:
```
MEDIA_DISK=media
MEDIA_MAX_UPLOAD_SIZE=104857600
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=aiclip-media
AWS_ENDPOINT=http://localhost:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

**TDD:**
- RED: Write a test that `config('filesystems.disks.media')` exists and has driver `s3`.
- GREEN: Add the disk config.
- REFACTOR: No refactoring needed.

---

### Task 1.3 — Create MediaAsset model and migration

**Files:**
- `apps/api/database/migrations/XXXX_create_media_assets_table.php`
- `apps/api/app/Models/MediaAsset.php`
- `apps/api/database/factories/MediaAssetFactory.php`
- `apps/api/app/Models/Project.php` (add `mediaAssets` relationship)

Migration creates `media_assets` table with columns:
`id`, `project_id` (FK CASCADE), `original_name`, `storage_disk`, `storage_key`
(unique), `mime_type`, `size_bytes`, `status` (default `stored`), `created_at`,
`updated_at`.

Model: `App\Models\MediaAsset` with `$fillable`, `casts`, and `project()` relationship.

Factory: `MediaAssetFactory` with realistic defaults, accepts `project_id` override.

Project model: add `mediaAssets(): HasMany` returning `MediaAsset::class`.

**TDD:**
- RED: Write Pest test asserting `MediaAsset::factory()->create()` succeeds and the
  database record exists with all expected columns.
- GREEN: Create migration, model, factory.
- REFACTOR: Verify factory is minimal and correct.

---

### Task 1.4 — Add MediaAsset relationship to User (transitive)

**Files:** `apps/api/app/Models/User.php`

No direct `mediaAssets` relationship on User is needed. Media access is always
through Project. Document this design decision in the model comment. No code change
required beyond Task 1.3.

**TDD:** Covered by ownership tests in Phase 2.

---

## Phase 2: Backend API (Controller, Validation, Routes)

### Task 2.1 — Create StoreMediaUploadRequest form request

**Files:** `apps/api/app/Http/Requests/StoreMediaUploadRequest.php`

Validation rules:
- `file` → required, file, mimetypes:video/mp4,video/quicktime,video/webm,
  max:{MEDIA_MAX_UPLOAD_SIZE in KB}
- `authorize()` → returns true (ownership check is in the controller, not the
  request, because we need the project from the route parameter)

**TDD:**
- RED: Write test that `POST /api/v1/projects/{id}/media/upload` with no file
  returns 422 with `file` validation error.
- GREEN: Create the form request.
- REFACTOR: Extract max size to a config value if needed.

---

### Task 2.2 — Create MediaAssetController

**Files:** `apps/api/app/Http/Controllers/Api/V1/MediaAssetController.php`

Methods:

#### `upload(Request $request, Project $project)`
1. `$this->authorizeOwnership($request, $project)` — reuse the same pattern as
   `ProjectController`.
2. Validate via `StoreMediaUploadRequest`.
3. Get the uploaded file: `$file = $request->file('file')`.
4. Determine MIME type via `finfo_file($file->getPathname())`.
5. Generate storage key: `{$project->id}/` . (string) Str::uuid() . `.` .
   `$file->getClientOriginalExtension()`.
6. Store file: `$file->storeAs('/', $key, config('media.disk'))`.
7. Create `MediaAsset` record.
8. Return 201 with `MediaAssetResource`.

#### `index(Request $request, Project $project)`
1. `$this->authorizeOwnership($request, $project)`.
2. Return `MediaAssetResource::collection($project->mediaAssets()->orderByDesc('created_at')->get())`.

#### `destroy(Request $request, MediaAsset $media)`
1. Load the parent project via `$media->project`.
2. `$this->authorizeOwnership($request, $project)`.
3. Attempt `Storage::disk($media->storage_disk)->delete($media->storage_key)`.
4. `$media->delete()`.
5. Return 204.

**TDD:**
- RED: Write Pest tests for each endpoint covering success, ownership, validation,
  and error cases (see test-plan.md for full list).
- GREEN: Implement controller.
- REFACTOR: Extract ownership helper if duplication remains.

---

### Task 2.3 — Create MediaAssetResource

**Files:** `apps/api/app/Http/Resources/MediaAssetResource.php`

JSON structure matching the API contract in spec.md. No `user_id` exposed.

**TDD:** Covered by controller tests that assert JSON structure.

---

### Task 2.4 — Register routes

**Files:** `apps/api/routes/api.php`

Add inside the `auth:sanctum` middleware group:

```php
Route::post('/projects/{project}/media/upload', [MediaAssetController::class, 'upload'])
    ->middleware('throttle:media-upload');
Route::get('/projects/{project}/media', [MediaAssetController::class, 'index']);
Route::delete('/media/{media}', [MediaAssetController::class, 'destroy']);
```

Register the rate limiter in `AppServiceProvider`:

```php
RateLimiter::for('media-upload', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
});
```

**TDD:**
- RED: Write test that `DELETE /api/v1/media/{id}` returns 401 for guests.
- GREEN: Register routes and rate limiter.
- REFACTOR: No refactoring needed.

---

### Task 2.5 — Update Project model for cascade cleanup

**Files:** `apps/api/app/Models/Project.php`

Add a `booted()` method with a `deleting` observer that iterates
`$this->mediaAssets` and deletes each from storage before the project
record is removed.

```php
protected static function booted(): void
{
    static::deleting(function (Project $project) {
        foreach ($project->mediaAssets as $media) {
            try {
                Storage::disk($media->storage_disk)->delete($media->storage_key);
            } catch (\Exception $e) {
                \Log::warning("Failed to delete media object: {$media->storage_key}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    });
}
```

**TDD:**
- RED: Write test that deleting a project with media removes both the DB records
  and the storage objects.
- GREEN: Add the observer.
- REFACTOR: No refactoring needed.

---

## Phase 3: Frontend

### Task 3.1 — Define TypeScript types for MediaAsset

**Files:** `apps/web/src/features/media/types/index.ts`

```typescript
export interface MediaAsset {
  id: number;
  project_id: number;
  original_name: string;
  storage_disk: string;
  storage_key: string;
  mime_type: string;
  size_bytes: number;
  status: 'stored' | 'failed';
  created_at: string;
  updated_at: string;
}

export interface MediaAssetsResponse {
  data: MediaAsset[];
}

export interface MediaAssetResponse {
  data: MediaAsset;
}

export type MediaErrorType =
  | 'validation'
  | 'unauthorized'
  | 'throttle'
  | 'csrf'
  | 'network'
  | 'server'
  | 'file-too-large';

export interface MediaError {
  type: MediaErrorType;
  errors?: Record<string, string[]>;
  message?: string;
}
```

**TDD:** Type definitions — no runtime tests needed.

---

### Task 3.2 — Create media API client

**Files:** `apps/web/src/features/media/api/index.ts`

Functions:
- `getMediaAssets(projectId: number): Promise<MediaAssetsResponse>`
- `uploadMediaAsset(projectId: number, file: File): Promise<MediaAssetResponse>`
- `deleteMediaAsset(mediaId: number): Promise<void>`

The upload function must:
- Initialize CSRF cookie first.
- Use `FormData` with `Content-Type: multipart/form-data` (do NOT set the header
  manually — let the browser set the boundary).
- NOT set `X-XSRF-TOKEN` header for multipart uploads (the CSRF token is sent via
  cookie for SPA mode). Actually, per the existing pattern, CSRF is sent via the
  `X-XSRF-TOKEN` header. For file uploads, the CSRF token should still be sent
  in the header. The `Content-Type` is set by the browser with the correct boundary.

**TDD:**
- RED: Write Vitest tests mocking `fetch` for success, validation error, file-too-large,
  and network error cases.
- GREEN: Implement the API client.
- REFACTOR: Extract shared CSRF logic if not already shared with projects API.

---

### Task 3.3 — Create useMediaAssets hook

**Files:** `apps/web/src/features/media/hooks/index.ts`

```typescript
export function useMediaAssets(projectId: number | null): UseMediaAssetsReturn
```

State: `mediaAssets`, `loading`, `error`, `uploading`.
Methods: `fetchMedia`, `uploadFile`, `deleteMedia`, `clearErrors`.

Follow the same pattern as `useProjects` hook.

**TDD:**
- RED: Write Vitest tests for the hook using `renderHook` from RTL:
  - fetchMedia populates mediaAssets
  - uploadFile adds to list
  - deleteMedia removes from list
  - error states are handled
- GREEN: Implement the hook.
- REFACTOR: No refactoring needed.

---

### Task 3.4 — Create MediaUploadForm component

**Files:** `apps/web/src/features/media/components/MediaUploadForm.tsx`

A file input with:
- `accept="video/mp4,video/quicktime,video/webm"`
- Upload button
- Loading state while uploading
- Error display for validation/file-size/server errors
- Success feedback (file appears in the list)

Accessibility: label associated with input, aria-live for status messages.

**TDD:**
- RED: Write Vitest tests:
  - renders file input with correct accept attribute
  - shows uploading state
  - shows error message on failure
  - calls onSubmit with the selected file
- GREEN: Implement the component.
- REFACTOR: Ensure consistent styling with existing project components.

---

### Task 3.5 — Create MediaList component

**Files:** `apps/web/src/features/media/components/MediaList.tsx`

Displays a list of `MediaAsset` items with:
- Original filename
- File size (formatted)
- Upload date
- Status badge
- Delete button with confirmation

States: loading, empty, list.

**TDD:**
- RED: Write Vitest tests:
  - renders loading state
  - renders empty state
  - renders list of media items
  - delete button triggers callback
- GREEN: Implement the component.
- REFACTOR: No refactoring needed.

---

### Task 3.6 — Create MediaListItem component

**Files:** `apps/web/src/features/media/components/MediaListItem.tsx`

Individual media item display with:
- File name
- Human-readable size (KB/MB/GB)
- Relative or absolute date
- Status indicator
- Delete with confirmation (same pattern as ProjectCard)

**TDD:**
- RED: Write Vitest tests for rendering and delete confirmation flow.
- GREEN: Implement the component.
- REFACTOR: No refactoring needed.

---

### Task 3.7 — Create media feature barrel export

**Files:** `apps/web/src/features/media/index.ts`

Re-export all public types, hooks, and components.

**TDD:** No runtime tests needed.

---

### Task 3.8 — Integrate media into AuthenticatedShell

**Files:** `apps/web/src/features/auth/components/AuthenticatedShell.tsx`

For the first iteration, the media upload UI is shown per-project. Since we don't
have a project detail page yet, add a collapsible media section below each
`ProjectCard` when the user expands it. Alternatively, create a simple
`ProjectDetailView` that is shown when a project is selected.

**Decision:** Create a lightweight `ProjectMediaSection` component that is rendered
inside `AuthenticatedShell` when a project is selected. This avoids the need for
routing (which is out of scope).

Flow:
1. User clicks a project card → project is "selected" (state in AuthenticatedShell).
2. `ProjectMediaSection` renders below the project list with:
   - Upload form
   - Media list for the selected project
   - Back button to deselect
3. User can upload, view, and delete media for that project.

**TDD:**
- RED: Write Vitest tests:
  - selecting a project shows the media section
  - deselecting hides it
  - upload and delete work end-to-end through the hook
- GREEN: Implement the integration.
- REFACTOR: Ensure no prop drilling exceeds 2 levels.

---

## Phase 4: E2E Tests

### Task 4.1 — Playwright E2E: upload → list → delete flow

**Files:** `apps/web/e2e/media.spec.ts`

Test scenario:
1. Register a user (or reuse existing auth helper).
2. Create a project.
3. Upload a valid video file (use a small fixture, e.g. a 1-second MP4).
4. Assert the media list shows the uploaded file with correct name and size.
5. Delete the media asset.
6. Assert the media list is empty.
7. Delete the project.
8. Assert the project is gone.

Additional scenarios:
- Upload rejection for wrong MIME type (upload a .txt renamed to .mp4).
- Upload rejection for file exceeding size limit.
- Non-owner cannot see or delete another user's media.

**TDD:**
- RED: Write the Playwright spec.
- GREEN: The E2E test should pass once all previous tasks are complete.
- REFACTOR: Extract shared auth/project helpers if duplicated.

---

### Task 4.2 — MinIO integration verification

**Files:** Included in the E2E test above.

Verify that:
- The uploaded file exists in MinIO at the expected key.
- After deletion, the file no longer exists in MinIO.

This can be done via the MinIO `mc` CLI in a test helper or by checking the
`GET /api/v1/projects/{id}/media` response before and after deletion.

---

## Phase 5: Regression & Documentation

### Task 5.1 — Verify existing tests pass

Run the full backend test suite:
```sh
cd apps/api && php artisan test --compact
```

Run the full frontend test suite:
```sh
cd apps/web && npx vitest run
```

Run the full E2E suite:
```sh
cd apps/web && npx playwright test
```

All existing tests for auth, projects, and health must continue to pass.
Any regressions are blocking.

---

### Task 5.2 — Update project-state.md

**Files:** `docs/project-state.md`

Update the following sections:
- **Current Architecture:** Add MinIO to the service list.
- **Completed Capabilities:** Add M2 media storage entry.
- **Known Limitations:** Add 100 MB upload limit, no resumable uploads.
- **Current Milestone:** Update to reflect M2 progress.
- **Next Architectural Goal:** Point to M2 processing pipeline (transcoding, etc.).

---

### Task 5.3 — Run Pint and type checks

```sh
cd apps/api && vendor/bin/pint --dirty --format agent
```

Ensure no PHP code style violations in modified files.

---

## Task Dependency Graph

```
Phase 1: Infrastructure
  1.1 (MinIO docker)
  1.2 (filesystem config) — depends on 1.1 for local testing
  1.3 (model + migration) — depends on 1.2 for disk config
  1.4 (User relationship) — covered by 1.3

Phase 2: Backend API
  2.1 (Form request) — depends on 1.3
  2.2 (Controller) — depends on 2.1, 1.3
  2.3 (Resource) — depends on 1.3
  2.4 (Routes) — depends on 2.2, 2.3
  2.5 (Cascade cleanup) — depends on 1.3

Phase 3: Frontend
  3.1 (Types) — independent
  3.2 (API client) — depends on 3.1
  3.3 (Hook) — depends on 3.2
  3.4 (Upload form) — depends on 3.3
  3.5 (Media list) — depends on 3.3
  3.6 (Media list item) — depends on 3.5
  3.7 (Barrel export) — depends on 3.4, 3.5, 3.6
  3.8 (Shell integration) — depends on 3.7

Phase 4: E2E
  4.1 (Playwright spec) — depends on Phase 2 + Phase 3
  4.2 (MinIO verification) — part of 4.1

Phase 5: Regression
  5.1 (Full test run) — depends on all phases
  5.2 (Documentation) — after 5.1
  5.3 (Code style) — after 5.1
```

## Estimated Scope

- Backend: ~6 files (migration, model, factory, controller, request, resource)
- Frontend: ~8 files (types, api, hook, 3 components, barrel, shell update)
- Tests: ~6 test files (2 Pest, 3 Vitest, 1 Playwright)
- Config: 3 files (filesystems.php, docker-compose.yml, .env.example)
- Documentation: 1 file (project-state.md)

Total: ~24 files modified or created.