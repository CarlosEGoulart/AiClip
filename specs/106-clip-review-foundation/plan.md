# Implementation Plan: Clip Review Experience Foundation — Issue #106

## Active Scope and Working-Tree Baseline

Issue: [Issue #106](https://github.com/CarlosEGoulart/AiClip/issues/106). Branch: `@carlosegoulart/106/feat/clip-review-experience`. `spec.md` is the behavior contract; `test-plan.md` defines required verification. All steps follow Laravel 13, React 19, TypeScript, Pest, Vitest, Playwright conventions.

Planning inspected the active issue, architecture, PRD, roadmap, project state, ADR-0002, existing `DerivedAsset` model (M6.2 complete), `MediaAsset` model, `Project` model, Sanctum SPA auth, media storage, API routes, frontend media components (`ProjectMediaSection`, `MediaList`, `MediaUploadForm`), and test patterns. No real `.env` secrets were read.

Observed current state:
- `DerivedAsset::TYPE_RENDERED_CLIP = 'clip_rendered'` exists with `render_status` enum and scopes
- `MediaAsset` has project ownership; `Project` has user ownership
- API routes under `/api/v1` with `auth:sanctum` middleware and `throttle` limiters
- Frontend uses `useMediaAssets` hook, `ProjectMediaSection` for project-scoped media UI
- Pest tests in `apps/api/tests/` (Unit, Feature); Vitest in `apps/web/src/`; Playwright in `apps/web/`
- No `ClipReview` model, migration, API, queue job, or review UI exists yet

## Step 0 — Repository Setup and Dependency Verification

1. Verify branch `@carlosegoulart/106/feat/clip-review-experience` exists and is checked out.
2. Verify `composer.json` has no new requirements for this slice (uses existing Laravel queue, no new packages).
3. Verify `package.json` has no new requirements (uses existing React, video player is native HTML5).
4. Run `docker compose up -d postgres minio minio-init` from repository root; verify health and bucket readiness.
5. Run `php artisan migrate:fresh --seed` in `apps/api` to establish clean database.
6. Run `php artisan test` in `apps/api` and `npm test` in `apps/web` to confirm baseline passes (zero tests for new feature expected).

## Step 1 — Backend: ClipReview Model, Migration, and Scopes

### 1.1 Create Migration

```bash
php artisan make:migration create_clip_reviews_table --create=clip_reviews
```

Migration content (see `spec.md` Data Model):
- `id` (bigint, PK)
- `derived_asset_id` (foreignId, constrained, cascadeOnDelete)
- `reviewer_id` (foreignId, constrained users, cascadeOnDelete)
- `status` (enum: pending, approved, rejected, needs_changes, default pending)
- `feedback` (text, nullable)
- `timestamps`
- Unique index on `[derived_asset_id, reviewer_id]`
- Index on `[reviewer_id, status]`

### 1.2 Create ClipReview Model

```bash
php artisan make:model ClipReview
```

Implement:
- `$fillable = ['derived_asset_id', 'reviewer_id', 'status', 'feedback']`
- `$casts = ['status' => ClipReviewStatus::class]` (enum class)
- `derivedAsset()` → BelongsTo
- `reviewer()` → BelongsTo
- `scopeForProject(Builder $query, int $projectId)` — joins through derived_asset → media_asset → project
- `scopePending(Builder $query)` — where status = pending

### 1.3 Create ClipReviewStatus Enum

```bash
php artisan make:enum ClipReviewStatus
```

Values: `PENDING`, `APPROVED`, `REJECTED`, `NEEDS_CHANGES`. BackedEnum with string values.

### 1.4 Extend DerivedAsset Model

Add to `DerivedAsset`:
```php
public function clipReview(): HasOne
{
    return $this->hasOne(ClipReview::class);
}

public function scopeWithReviewStatus(Builder $query): Builder
{
    return $query->with(['clipReview' => fn($q) => $q->with('reviewer')]);
}
```

### 1.5 Run Migration and Verify

```bash
php artisan migrate
php artisan test --filter=ClipReview
```
Expect: Model tests pass (schema, relationships, scopes, enum cast).

## Step 2 — Backend: API Routes, Controller, Resources, and Requests

### 2.1 Create Form Requests

```bash
php artisan make:request ClipReviewStatusUpdateRequest
php artisan make:request ClipReviewFeedbackRequest
```

**ClipReviewStatusUpdateRequest**:
- `status` required, in: `pending,approved,rejected,needs_changes`
- Forbidden values: `pending` (cannot set back to pending via this endpoint)
- Authorize: verify ownership via `ClipReview::forProject()->findOrFail()`

**ClipReviewFeedbackRequest**:
- `feedback` required, string, max:1000, min:10 (when status is rejected/needs_changes)
- Authorize: same ownership check

### 2.2 Create API Resource

```bash
php artisan make:resource ClipReviewResource
php artisan make:resource ClipReviewCollection
```

**ClipReviewResource** fields (see `spec.md` Public API):
- Include `video_url` via signed route `clip-review.video`
- Include `thumbnail_url` if thumbnail exists (out of scope for generation, but field present)
- Transform `derived_asset` relation for metadata (duration_ms, width, height, candidate_index, render_profile_version)

### 2.3 Create Controller

```bash
php artisan make:controller Api/V1/ClipReviewController --api --model=ClipReview
```

Implement methods:
- `index(Request $request, Project $project)` — `ClipReview::forProject($project->id)->latest()->paginate(20)` → `ClipReviewCollection`
- `show(ClipReview $clipReview)` — authorize ownership, return `ClipReviewResource`
- `updateStatus(ClipReviewStatusUpdateRequest $request, ClipReview $clipReview)` — dispatch `ProcessClipReview` job, return updated resource
- `storeFeedback(ClipReviewFeedbackRequest $request, ClipReview $clipReview)` — update feedback, return updated resource
- `video(ClipReview $clipReview)` — named route `clip-review.video`; authorize; return redirect to storage temporary URL (1 hour expiry)

### 2.4 Register Routes

In `routes/api.php` inside `auth:sanctum` group:
```php
Route::get('/projects/{project}/clip-reviews', [ClipReviewController::class, 'index'])
    ->middleware('throttle:clip-review-list');
Route::get('/clip-reviews/{clipReview}', [ClipReviewController::class, 'show'])
    ->middleware('throttle:clip-review-show');
Route::patch('/clip-reviews/{clipReview}/status', [ClipReviewController::class, 'updateStatus'])
    ->middleware('throttle:clip-review-update');
Route::post('/clip-reviews/{clipReview}/feedback', [ClipReviewController::class, 'storeFeedback'])
    ->middleware('throttle:clip-review-update');
Route::get('/clip-reviews/{clipReview}/video', [ClipReviewController::class, 'video'])
    ->name('clip-review.video');
```

### 2.5 Register Rate Limiters

In `AppServiceProvider::boot()`:
```php
RateLimiter::for('clip-review-list', fn(Request $request) => Limit::perMinute(60)->by($request->user()->id));
RateLimiter::for('clip-review-show', fn(Request $request) => Limit::perMinute(120)->by($request->user()->id));
RateLimiter::for('clip-review-update', fn(Request $request) => Limit::perMinute(30)->by($request->user()->id));
```

### 2.6 Verify API

```bash
php artisan test --filter=ClipReviewController
```
Expect: Feature tests pass (list, show, status update, feedback, video URL, ownership, rate limiting).

## Step 3 — Backend: ProcessClipReview Queue Job

### 3.1 Create Job

```bash
php artisan make:job ProcessClipReview --queued
```

Implement `handle()`:
```php
public function handle(): void
{
    $clipReview = ClipReview::with(['derivedAsset.mediaAsset.project'])->findOrFail($this->clipReviewId);

    // Re-validate ownership
    if ($clipReview->derivedAsset->mediaAsset->project->user_id !== $clipReview->reviewer_id) {
        return; // Silent fail for security; log warning
    }

    // Idempotency: only process if pending
    if ($clipReview->status !== ClipReviewStatus::PENDING) {
        return;
    }

    $action = $this->action; // 'approve', 'reject', 'needs_changes'
    $newStatus = match($action) {
        'approve' => ClipReviewStatus::APPROVED,
        'reject' => ClipReviewStatus::REJECTED,
        'needs_changes' => ClipReviewStatus::NEEDS_CHANGES,
        default => throw new \InvalidArgumentException("Invalid action: {$action}"),
    };

    DB::transaction(function () use ($clipReview, $newStatus) {
        $clipReview->update(['status' => $newStatus]);

        // DerivedAsset render_status: approved clips are already 'completed' from render
        // Rejected/needs_changes keep 'completed' (clip exists but not approved for publish)
        // Future: needs_changes could trigger re-render job (out of scope)
    });

    event(new ClipReviewCompleted($clipReview));
}
```

### 3.2 Create Event

```bash
php artisan make:event ClipReviewCompleted
```

Implement with `ClipReview` payload; `ShouldBroadcast` not needed yet (notifications out of scope).

### 3.3 Verify Job

```bash
php artisan test --filter=ProcessClipReview
```
Expect: Unit/feature tests pass (status transitions, idempotency, ownership re-validation, event dispatch).

## Step 4 — Backend: Pest Tests (Unit & Feature)

### 4.1 Unit Tests

Location: `apps/api/tests/Unit/Models/ClipReviewTest.php`
- Schema: required fields, foreign keys, indexes, enum constraint
- Factory: creates valid ClipReview with DerivedAsset, MediaAsset, Project, User
- Relationships: derivedAsset, reviewer
- Scopes: forProject filters correctly, pending filters correctly
- Enum cast: status returns ClipReviewStatus enum

### 4.2 Feature Tests

Location: `apps/api/tests/Feature/ClipReview/`
- `ClipReviewApiTest.php`: list, show, status update, feedback, video URL
- `ClipReviewOwnershipTest.php`: cross-user 404, non-existent 404, CSRF 419, auth 401
- `ClipReviewRateLimitTest.php`: update limiter at 30/min
- `ProcessClipReviewJobTest.php`: each action, idempotency, ownership re-check, event
- `ClipReviewVideoUrlTest.php`: signed URL generation, expiry, access control

### 4.3 Run Backend Tests

```bash
php artisan test --filter=ClipReview
```
Expect: All pass. No skipped tests.

## Step 5 — Frontend: TypeScript Types and API Client

### 5.1 Create Types

File: `apps/web/src/features/clip-review/types.ts`
```typescript
export type ClipReviewStatus = 'pending' | 'approved' | 'rejected' | 'needs_changes';

export interface ClipReview {
  id: number;
  derived_asset_id: number;
  status: ClipReviewStatus;
  feedback: string | null;
  video_url: string;
  thumbnail_url: string | null;
  duration_ms: number;
  width: number;
  height: number;
  candidate_index: number;
  render_profile_version: string;
  created_at: string;
  updated_at: string;
}

export interface ClipReviewListResponse {
  data: ClipReview[];
  // pagination fields
}
```

### 5.2 Create API Client

File: `apps/web/src/features/clip-review/api.ts`
- `fetchClipReviews(projectId: number): Promise<ClipReviewListResponse>`
- `fetchClipReview(clipReviewId: number): Promise<ClipReview>`
- `updateClipReviewStatus(clipReviewId: number, status: Exclude<ClipReviewStatus, 'pending'>): Promise<ClipReview>`
- `submitClipReviewFeedback(clipReviewId: number, feedback: string): Promise<ClipReview>`
- All use `fetch` with `credentials: 'include'`, CSRF header from cookie

### 5.3 Create Hooks

File: `apps/web/src/features/clip-review/hooks.ts`
- `useClipReviews(projectId: number)` — React Query for list
- `useClipReview(clipReviewId: number)` — React Query for single
- `useUpdateClipReviewStatus()` — mutation with invalidation
- `useSubmitClipReviewFeedback()` — mutation with invalidation

## Step 6 — Frontend: React Components

### 6.1 VideoPlayer Component

File: `apps/web/src/features/clip-review/components/VideoPlayer.tsx`
- Props: `videoUrl: string`, `poster?: string`, `onError?: () => void`, `onLoad?: () => void`
- State: playing, loading, error, volume, muted, fullscreen
- Keyboard handlers (see `spec.md` UX)
- Custom controls: play/pause, seek bar, volume, fullscreen, time display
- Accessibility: ARIA labels, role="region", aria-live for status
- Responsive: `width: 100%`, `aspect-ratio: 9/16`, `object-fit: contain`

### 6.2 ReviewActions Component

File: `apps/web/src/features/clip-review/components/ReviewActions.tsx`
- Props: `clipReviewId: number`, `currentStatus: ClipReviewStatus`, `onStatusChange: (status) => void`
- Three buttons with icons: Approve (check), Reject (x), Needs Changes (refresh-cw)
- Each opens confirmation dialog (accessible: focus trap, Escape to close, ARIA modal)
- Disabled during mutation; shows spinner
- Success/error toast via `aria-live="polite"`

### 6.3 FeedbackForm Component

File: `apps/web/src/features/clip-review/components/FeedbackForm.tsx`
- Props: `clipReviewId: number`, `status: ClipReviewStatus`, `initialFeedback?: string`, `onSubmit: (feedback) => void`
- Textarea with label, character count (0/1000), warning at 800, error at >1000
- Required when status is `rejected` or `needs_changes` (min 10 chars)
- Auto-expand textarea
- Draft saved to localStorage key `clip-review-feedback-{clipReviewId}`; cleared on submit

### 6.4 ReviewList Component

File: `apps/web/src/features/clip-review/components/ReviewList.tsx`
- Props: `clipReviews: ClipReview[]`, `loading: boolean`, `onReview: (clipReview) => void`
- Grid: `grid-template-columns: repeat(auto-fill, minmax(280px, 1fr))`; single column < 768px
- Item: thumbnail (from video_url poster), duration badge, candidate index, status badge, "Review" button
- Status badge colors: pending=gray, approved=green, rejected=red, needs_changes=orange
- Empty state: illustration + "No clips to review" + link to upload media
- Loading: skeleton cards

### 6.5 ReviewPage Component

File: `apps/web/src/features/clip-review/components/ReviewPage.tsx`
- Props: `projectId: number`, `projectName: string`, `onBack: () => void`
- Uses `useClipReviews(projectId)` for list
- State: `selectedClipReviewId`, `clipReviews` from query
- Layout: header (project name, back, counter), main (VideoPlayer + side panel)
- Side panel: ReviewActions, FeedbackForm, metadata (duration, candidate index, render profile)
- Keyboard: `N` next, `P` previous (disabled at boundaries)
- URL sync: `useSearchParams` with `clipReviewId` param for deep linking
- Effect: when list loads, select first pending or first item

### 6.6 ClipReviewFeature Export

File: `apps/web/src/features/clip-review/index.ts`
- Export all components, hooks, types, api

## Step 7 — Frontend: Vitest Component Tests

### 7.1 Test Files

Location: `apps/web/src/features/clip-review/components/*.test.tsx`
- `VideoPlayer.test.tsx`: renders, plays/pauses, seeks, keyboard shortcuts, error/loading states, accessibility (ARIA, focus)
- `ReviewActions.test.tsx`: renders three actions, opens confirm dialog, calls API on confirm, disables during loading, announces result
- `FeedbackForm.test.tsx`: validates min chars for reject/needs_changes, char count, draft localStorage, accessible errors
- `ReviewList.test.tsx`: renders items, status badges, empty/loading states, click navigation
- `ReviewPage.test.tsx`: integrates components, navigates with N/P, URL updates, selects first pending

### 7.2 Run Frontend Tests

```bash
cd apps/web && npm test
```
Expect: All pass. No skipped tests.

## Step 8 — Frontend: Lint and Build

```bash
cd apps/web && npm run lint && npm run build
```
Expect: Zero lint errors (including TS6133), TypeScript compiles, Vite builds.

## Step 9 — Integration: Connect DerivedAsset to ClipReview

### 9.1 Ensure DerivedAsset Query Includes Review

In `ClipReviewController::index()`, eager load `derivedAsset.clipReview` to avoid N+1.

### 9.2 Auto-Create ClipReview on Render Completion

In `RenderMediaClip` job (M6.2 Slice 3D), after `markRenderCompleted()`:
```php
ClipReview::firstOrCreate([
    'derived_asset_id' => $derivedAsset->id,
    'reviewer_id' => $derivedAsset->mediaAsset->project->user_id,
], [
    'status' => ClipReviewStatus::PENDING,
]);
```
This ensures every rendered clip gets a pending review for the project owner.

### 9.3 Verify Integration

```bash
php artisan test --filter="ClipReview|RenderMediaClip"
```
Expect: Render creates ClipReview; API lists it; review flow works end-to-end.

## Step 10 — E2E: Playwright Tests

### 10.1 Test File

Location: `apps/web/tests/e2e/clip-review.spec.ts`

Scenarios (see `test-plan.md`):
- `TC-E2E-01`: Happy path — login → project → review list → select clip → play video → approve → verify status badge green
- `TC-E2E-02`: Reject workflow — select clip → play → reject → confirm → enter feedback → submit → verify status badge red
- `TC-E2E-03`: Needs Changes workflow — select clip → needs_changes → confirm → feedback → submit → verify orange badge
- `TC-E2E-04`: Keyboard navigation — Space play/pause, arrows seek, N/P next/prev, F fullscreen
- `TC-E2E-05`: Responsive — verify layout at 390x844, 768x1024, 1440x900
- `TC-E2E-06`: Signed URL — video plays, URL expires (simulated), non-owner 403

### 10.2 Run E2E Tests

```bash
cd apps/web && npm run test:e2e
```
Expect: All pass at all three viewports. Screenshots captured. No console errors.

## Step 11 — Real Integration Verification

### 11.1 Backend Real Integration

```bash
cd apps/api && php artisan test tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php --group=real-integration
```
Tests use real MinIO (signed URL → storage), real database, real queue (sync driver for test).

### 11.2 Full Test Suite

```bash
# Backend
cd apps/api && php artisan test

# Frontend
cd apps/web && npm test && npm run lint && npm run build && npm run test:e2e
```
Expect: All pass. CI configuration updated to include new test groups.

## Step 12 — Verification Checklist

- [ ] Migration creates `clip_reviews` table with correct schema
- [ ] `ClipReview` model has relationships, scopes, enum cast
- [ ] `ClipReviewStatus` enum exists
- [ ] `DerivedAsset` has `clipReview()` and `scopeWithReviewStatus()`
- [ ] API routes registered with correct middleware and rate limiters
- [ ] `ClipReviewController` implements all 5 endpoints
- [ ] `ClipReviewResource` returns correct fields including signed `video_url`
- [ ] `ProcessClipReview` job handles all 3 actions, idempotent, re-validates ownership, emits event
- [ ] `ClipReviewCompleted` event exists
- [ ] Pest tests: unit (model, scopes, enum), feature (API, ownership, rate limit, job, video URL)
- [ ] TypeScript types match API contracts
- [ ] API client and hooks work with React Query
- [ ] `VideoPlayer`: keyboard shortcuts, loading/error, accessibility, responsive
- [ ] `ReviewActions`: three actions, confirmation, loading, accessible
- [ ] `FeedbackForm`: validation, char count, draft save, accessibility
- [ ] `ReviewList`: grid, badges, empty/loading, navigation
- [ ] `ReviewPage`: integrates all, N/P navigation, URL sync, selects first pending
- [ ] Vitest component tests pass
- [ ] `npm run lint` and `npm run build` pass
- [ ] Playwright E2E tests pass at 3 viewports
- [ ] Real integration test passes (MinIO, DB, queue)
- [ ] No console errors in browser during E2E
- [ ] No secrets/internal fields in API responses or browser

## Remaining Gates (Not Claimed Complete)

- Independent Tester review: runs all backend/frontend/E2E tests, operates running app, inspects screenshots at 3 viewports, checks console/network/API, validates accessibility, security, ownership, CSRF, secret exposure.
- Orchestrator: commits, pushes, creates PR, monitors CI, merges after Tester approval and CI green.
- Issue closure after verified merge.

---

**Note**: This plan follows strict TDD. For each backend step (1–4), write test first (RED), implement (GREEN), refactor (tests still GREEN). For frontend steps (5–7), write Vitest component test first (RED), implement component (GREEN), refactor.