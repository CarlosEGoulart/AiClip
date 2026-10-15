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
- URL sync: `clipReviewId` param for deep linking via the native History API (superseded by Phase 7.5: `useSearchParams` is prohibited — no Router context exists in the production tree; react-router hooks throw outside a Router and crash the embedded review section on mount)
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

**SUPERSEDED by Phase 7.4** — the location and scenarios below were written against a routed review page (`/projects/{id}/review`) that does not exist in the running application, and the e2e environment cannot produce clip-review data. Phase 7.4 and the revised test-plan §6 are authoritative: the spec lives at `apps/web/e2e/clip-review.spec.ts` (inside Playwright's `testDir: './e2e'`) and covers the reachable embedded review experience (discoverability, empty state, non-interactivity, responsive viewports, console/network cleanliness).

### 10.1 Test File

Location: ~~`apps/web/tests/e2e/clip-review.spec.ts`~~ `apps/web/e2e/clip-review.spec.ts` (per Phase 7.4; the old path is outside `testDir`, so CI ran zero clip-review E2E)

Scenarios: see the revised test-plan §6 (TC-E2E-01 through TC-E2E-04). The original workflow scenarios below are unreachable in e2e; their coverage lives in backend feature/integration tests and Vitest component tests (see spec "Known Limitations and Coverage Boundary").

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

## Phase 5 — Governance Validation Fixes (CI Prerequisites)

These steps resolve pre-existing repository governance failures blocking CI. They are not new feature work but must complete before PR #107 can merge.

### 5.1 Complete Historical SDD Bundles (081A, 081B)

Create missing files for two incomplete SDD bundles, referencing the complete bundle at `specs/089-m6-2-slice3d-render-caption-integration/` as template.

**Bundle 081A — `specs/081A-m6-2-slice3a-caption-projection/`** (has `spec.md`; create 3 files):
- `plan.md` — Implementation plan for caption projection slice
- `test-plan.md` — Test scenarios for caption projection
- `evidence.md` — TDD evidence (RED/GREEN/REFACTOR) — Orchestrator populates after implementation

**Bundle 081B — `specs/081B-m6-2-slice3b-srt-generator/`** (has `evidence.md`; create 3 files):
- `spec.md` — Specification for SRT generator slice
- `plan.md` — Implementation plan for SRT generator
- `test-plan.md` — Test scenarios for SRT generator

Each new file must follow the structure and conventions of `specs/089-m6-2-slice3d-render-caption-integration/`.

### 5.2 Fix `merge_gate.py::validate_tester_approval()` to Match `validators.py` Contract

File: `scripts/merge_gate.py`

Current behavior: "last decision wins" — iterates decisions, keeps last non-None, allows REJECT then APPROVE to pass.

Required behavior (per `tests/governance/validators.py::ValidationRule.TESTER_APPROVAL_REQUIRED`):
- Exactly one decision must exist: either APPROVE or REJECT
- Multiple decisions (APPROVE + REJECT, or duplicate APPROVE) → FAIL
- Zero decisions → FAIL
- Single REJECT → FAIL (gate requires APPROVE)
- Single APPROVE → PASS

Implementation:
```python
def validate_tester_approval(decisions: list[TesterDecision]) -> bool:
    if not decisions:
        return False
    if len(decisions) != 1:
        return False  # Conflicting or duplicate decisions
    return decisions[0].decision == "APPROVE"
```

### 5.3 Update Skills Count Test (6 → 8 Skills)

File: `tests/governance/test_governance_g5_skills.py`

- Rename `test_g5_six_skills_parse` → `test_g5_eight_skills_parse`
- Update assertion from `expected_count = 6` to `expected_count = 8`
- Verify `.opencode/skills/` contains 8 directories: `issue-linearity`, `tdd-enforcer`, `token-efficient-context`, `playwright-visual-qa`, `media-pipeline`, `social-publishing`, `design-taste-frontend`, `web-design-guidelines`

### 5.4 Validation Commands

After completing 5.1–5.3, run full governance suite to verify all pass:

```bash
# From repository root
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Expect: All governance tests pass (G1–G5). No failures, no skips.

## Phase 6 — Backend ClipReview Feature Test Repair (CI `tests` Job)

Ordered Builder work. CI on PR #107 fails the backend `tests` job with `71 failed, 8 skipped, 1424 passed`; every failure is a `tests/Feature/ClipReview/` suite and all other backend tests pass. This phase repairs the test harness of the already-implemented Steps 1–4 behavior; it adds no production behavior. Phase 5.1 is satisfied for gate purposes (see 6.6); Phase 5.2–5.3 execution is human-gated (see 6.6) and proceeds in parallel under human control.

### 6.1 Reproduce

With Step 0 infrastructure running:

```bash
cd apps/api && php artisan test tests/Feature/ClipReview
```

Expected current result: every suite fails in `beforeEach` at the first `User::factory()->create()` with the first causal error:

```
BindingResolutionException: Target class [config] does not exist
  at vendor/laravel/framework/src/Illuminate/Container/Container.php:1147
```

First observed at `tests/Feature/ClipReview/ClipReviewVideoUrlTest.php:17`; identical in the other suites (e.g. `ProcessClipReviewJobTest.php:19`).

### 6.2 Diagnose from the First Causal Error

1. `config` is a core container alias registered only while `Illuminate\Foundation\Application` bootstraps. `Target class [config] does not exist` proves the resolving container is a fresh, unbooted container: the failing tests do not run under a booted Laravel `TestCase`, so no application exists when `beforeEach` executes. This is a harness defect; production code is not implicated.
2. Verified discrepancy: every passing suite binds its base test case file-scoped — `uses(SpaTestCase::class, RefreshDatabase::class);` (Auth/Media/Project suites) or `uses(TestCase::class, RefreshDatabase::class);` (Jobs/Models suites). All seven `tests/Feature/ClipReview/*` files instead declare directory-scoped bindings from inside the target directory, with conflicting bases (`SpaTestCase` in `ClipReviewApiTest.php`, `ClipReviewOwnershipTest.php`, `ClipReviewRateLimitTest.php`, `ClipReviewVideoUrlTest.php`; `TestCase` in `ProcessClipReviewJobTest.php`, `ClipReviewIntegrationTest.php`, `ClipReviewRealIntegrationTest.php`) and no `tests/Pest.php` supplying a directory mapping. The directory-scoped form does not bind a base test case to these files, so they run on Pest's bare PHPUnit base with no booted application; the first Eloquent factory call asks the unbooted container for `config` and all 71 tests fail identically.
3. `SpaTestCase` and `Tests\TestCase` are proven working (all 1424 passing tests use them). Only the `uses()` declaration form in the ClipReview files is defective.

### 6.3 Fix — Minimal, Convention-Consistent (Builder, In Scope)

In each of the seven files, replace only the `uses()` declaration with the file-scoped form used by the passing suites, and add the missing `use Illuminate\Foundation\Testing\RefreshDatabase;` import:

| File | Replace | With |
|---|---|---|
| `ClipReviewApiTest.php` | `uses(SpaTestCase::class)->in('Feature/ClipReview');` | `uses(SpaTestCase::class, RefreshDatabase::class);` |
| `ClipReviewOwnershipTest.php` | `uses(SpaTestCase::class)->in('Feature/ClipReview');` | `uses(SpaTestCase::class, RefreshDatabase::class);` |
| `ClipReviewRateLimitTest.php` | `uses(SpaTestCase::class)->in('Feature/ClipReview');` | `uses(SpaTestCase::class, RefreshDatabase::class);` |
| `ClipReviewVideoUrlTest.php` | `uses(SpaTestCase::class)->in('Feature/ClipReview');` | `uses(SpaTestCase::class, RefreshDatabase::class);` |
| `ProcessClipReviewJobTest.php` | `uses(TestCase::class)->in('Feature/ClipReview');` | `uses(TestCase::class, RefreshDatabase::class);` |
| `ClipReviewIntegrationTest.php` | `uses(TestCase::class)->in('Feature/ClipReview');` | `uses(TestCase::class, RefreshDatabase::class);` |
| `ClipReviewRealIntegrationTest.php` | `uses(TestCase::class)->in('Feature/ClipReview');` | `uses(TestCase::class, RefreshDatabase::class);` |

Constraints:

- Change only the `uses()` line and the missing import; do not alter test logic, assertions, datasets, or production code in this step.
- Do not create `tests/Pest.php`, rename files, or reorganize directories; the file-scoped declaration is the proven repository convention.

### 6.4 Harness Repair Is Not GREEN

The 71 failures are harness misconfiguration masking behavior; per test-plan evidence rules, setup failures are not valid RED. After 6.3 the ClipReview assertions execute for the first time:

1. If all pass: record GREEN evidence.
2. If any still fail: those are genuine behavioral RED failures against the implemented feature. Handle each as a normal TDD cycle — confirm the assertion failure is behavioral, implement the minimal GREEN correction per the Step 1–4 contracts, refactor, rerun. Behavioral corrections are in-scope Builder work. Never weaken, skip, or delete assertions to force green.

Planner-ratified test corrections (Phase 6): the following three harness corrections were flagged by the Builder, resolved in favor of the spec, and ratified by the Planner. They adjust harness mechanics only; no assertion was weakened, skipped, or deleted.

1. **Signed-URL assertions** (`ClipReviewApiTest.php`, `ClipReviewVideoUrlTest.php`): assert the route URI path (`clip-reviews/{id}/video`) plus `signature` and `expires` query parameters instead of the literal route name `clip-review.video`. Laravel `UrlGenerator::signedRoute` builds the URL from the route URI; the dot-separated route name never appears in the URL string, so the original assertion was unsatisfiable. The replacement is strictly stronger evidence of a signed URL. The spec's pinned route (name `clip-review.video`, URI `GET /api/v1/clip-reviews/{clipReview}/video`) is unchanged and authoritative.
2. **Guest write setup** (`ClipReviewApiTest.php` guest PATCH/POST cases): use `$this->csrfCookies()` (real `/sanctum/csrf-cookie` handshake, no login) instead of an empty cookie jar. CSRF validation precedes `auth:sanctum` in the stateful pipeline, so a tokenless write returns 419 before the 401 assertion can be exercised. The 401 assertions are unchanged and now actually executed; the 419 contract remains covered by the dedicated missing-CSRF cases. Guest GET cases correctly keep the empty cookie jar.
3. **Real queue sync test** (`ClipReviewRealIntegrationTest.php`): dispatch via `ProcessClipReview::dispatch(...)` under `QUEUE_CONNECTION=sync` (pinned by `phpunit.xml`) and verify state immediately after dispatch returns. The previous combination of `Queue::fake()` + manual `handle()` + `assertPushed()` was self-contradictory (a faked queue never executes the job) and violated test-plan §7.1's no-`Queue::fake()` requirement for this case.

### 6.5 Verify

```bash
cd apps/api && php artisan test tests/Feature/ClipReview   # targeted: zero failures
cd apps/api && php artisan test                             # full backend: zero failures; the 1424 passing tests stay green
cd apps/api && vendor/bin/pint --dirty --format agent
```

The mandatory real-integration run (test-plan §7) is unchanged and must pass with the repaired base binding.

### 6.6 Human-Gated Governance Items (Blocked for All Agents)

Verified governance status for this branch (local run): 173 tests, exactly one failure — `test_g5_six_skills_parse`. Every SDD-bundle completeness check passes, so the CI gate no longer demands Phase 5.1 work; it is satisfied for gate purposes. The two remaining corrections target control-plane files that explicit repository permission rules deny to every agent (Orchestrator, Planner, Builder, Tester): `tests/governance/**` and `scripts/merge_gate.py`. This section supersedes the executor assignment in Phase 5.2–5.3: their specified end states remain required, but execution is human-only. Agents report these items to the Orchestrator, which escalates to the human; no agent may edit these files or work around the gates.

Scope separation:

- **(a) Builder, in scope**: Phase 6.1–6.5 only.
- **(b) Human intervention required**, applied by the human after Orchestrator escalation:
  1. **Skills count correction.** Actual location (correcting Phase 5.3's filename): `tests/governance/test_governance.py::test_g5_six_skills_parse` (line ~532). The test asserts an expected list of 6 skill names; `.opencode/skills/` now contains 8 (AGENTS.md §16 requires `design-taste-frontend` and `web-design-guidelines`). Human fix: add both names to the expected list and rename the method to `test_g5_eight_skills_parse`. The test also validates each listed skill's `SKILL.md` frontmatter (name, description 1–1024 chars, substantive body); both added skills must satisfy these checks.
  2. **Merge gate correction.** `scripts/merge_gate.py::validate_tester_approval()` implements "last decision wins" (a REJECT followed by APPROVE passes), diverging from the exactly-one-decision contract in `tests/governance/validators.py`. Human fix per Phase 5.2: a single APPROVE passes; zero decisions, multiple decisions, or a single REJECT fail.
  3. **CI re-verification.** The `pr-enforcement` CI failure has no agent-executable remediation in this plan; treat it as human-gated alongside the corrections above. After the human applies them, the Orchestrator re-runs PR #107 CI and confirms `governance` and `pr-enforcement` are green; any remaining failure is escalated back to the human.

## Phase 7 — Tester REJECT Remediation (Post-Phase-6 Review)

The independent Tester REJECTED the Phase 6 result with four defects. Phase 7 is the single authoritative fix list for Builder. Defects 1–3 ratify backend real-integration corrections; defect 4 reconciles the E2E suite with the verified application topology and e2e CI environment; defect 5 (Planner-discovered during this reconciliation) removes a mount-time crash that would otherwise make every rewritten E2E scenario fail. All fixes are in-scope remediation of this issue's own artifacts. No assertion may be weakened, skipped, or deleted; the only production behavior change is defect 5's URL-sync mechanism, which preserves the spec's deep-linking contract via the native History API.

Verified environment facts governing venue decisions:

- The CI backend `tests` job (`.github/workflows/backend.yml`) provisions real PostgreSQL and real MinIO (S3 env, private bucket) and runs the full backend suite with no group exclusions — it is the authoritative venue for the `real-integration` group.
- The CI e2e job (`.github/workflows/e2e.yml`) provisions real Laravel (`php artisan serve`, booted by the Playwright `webServer`) + PostgreSQL + Vite, with no MinIO (`FILESYSTEM_DISK=local`) and no Python worker/FFmpeg — no `ClipReview` data can exist there; the reachable UI state is the embedded review section's empty state.
- The local sandbox cannot provision this infrastructure (Docker registry pulls denied; env-prefixed shell commands denied). A fail-closed local gate result (availability-guard skip → gate failure) is correct behavior, not RED/GREEN evidence; CI is authoritative for gated infrastructure runs.

### 7.1 Defect 1 — `real-integration` group does not exist (mandatory gate exits 1, "No tests found")

Fix (Builder, in scope): in `apps/api/tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php`:

1. Declare `->group('real-integration')` on every test in the file (per-test declarations, mirroring `->group('minio-integration')` in `tests/Feature/Media/MinIOIntegrationTest.php`), and state the group in the file docblock.
2. Add the established availability guard in `beforeEach` (mirroring `MinIOIntegrationTest::beforeEach`): `markTestSkipped` when the media disk driver is not `s3`, and when a lightweight real connectivity probe (`put`/`delete` of a probe object) fails. The guard keeps full-suite runs graceful in environments without MinIO; the gated command below stays fail-closed because `--fail-on-skipped` converts a skip into a failure where infrastructure is missing.

Verification:

```bash
# Static discovery check (no infrastructure needed): every it(...) in the file
# declares ->group('real-integration'); positive count matches the test list
grep -c "group('real-integration')" apps/api/tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php

# Mandatory fail-closed gate — authoritative venue: CI backend `tests` job (real MinIO)
cd apps/api && php artisan test tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php --group=real-integration --fail-on-empty-test-suite --fail-on-skipped --fail-on-incomplete --fail-on-risky
```

Expected evidence: the gated run in CI selects the file's tests (positive test/assertion counts) and executes all of them with zero skips. Locally without MinIO, the gated run fails closed via the guard — acceptable and correct, not a defect.

### 7.2 Defect 2 — `Storage::fake('media')` in a real-integration test violates AC-12

Fix (Builder, in scope): rewrite the MinIO presigned case in `ClipReviewRealIntegrationTest.php` with real storage only:

1. Remove `Storage::fake('media')`. The file must contain no `Storage::fake` and no `Queue::fake` anywhere (grep-verifiable).
2. Write a real object to the real media disk at the review's `derived_asset->storage_key` (e.g. a small byte payload) — a real MinIO put.
3. Invoke `ClipReviewController::video()` for the owned review and assert a 302 whose `Location` is a real presigned URL on the configured MinIO endpoint: host matches the configured S3 endpoint, the path contains the bucket and the storage key, and the query includes presign parameters (`X-Amz-Signature`; `X-Amz-Expires`).
4. Perform a real HTTP GET of the `Location` URL and assert 200 with a response body equal to the bytes written in step 2 (Laravel's `Http` facade un-faked or Guzzle — a real HTTP client, not a double).
5. Delete the object afterwards (cleanup).

Verification: same gated command as 7.1, authoritative venue CI backend `tests` job. Expected evidence: the case executes (does not skip) with the presigned redirect and the 200-byte follow-up asserted.

### 7.3 Defect 3 — §7.1 rollback-on-error row unverified (only happy-path transaction coverage exists)

Fix (Builder, in scope): add a real-database rollback case to `ClipReviewRealIntegrationTest.php`:

1. Force a genuine mid-transaction failure while the job's `DB::transaction` runs. Recommended deterministic mechanism using real infrastructure only: create a real PostgreSQL trigger on `clip_reviews` whose function raises an exception on UPDATE (via `DB::unprepared`); the job's inner `update` then throws a real `QueryException` inside the transaction. DDL is transactional in PostgreSQL and the test runs inside `RefreshDatabase`'s outer transaction, so no schema leaks between tests. Any real-database failure mechanism is acceptable; mocks/fakes/doubles for this failure injection are not (AC-12 binds this file).
2. Assert: the exception propagates to the caller; after the failure, `$clipReview->refresh()->status` is still `pending`; no partial state persists (feedback unchanged, `DerivedAsset` unchanged); the `ClipReviewCompleted` event is not dispatched.

Verification: same gated command as 7.1, authoritative venue CI backend `tests` job. Expected evidence: the rollback case passes against real PostgreSQL with the review left `pending`.

### 7.4 Defect 4 — clip-review E2E is undiscoverable AND fictional

Fix (Builder, in scope):

1. Delete `apps/web/tests/e2e/clip-review.spec.ts` (outside Playwright's `testDir: './e2e'` — CI's e2e job has been running zero clip-review tests; its content targets a `/projects/{id}/review` route that does not exist, a magic `test@example.com` seed user that does not exist, and selectors for a page unreachable that way). Remove the now-empty `apps/web/tests/` tree.
2. Create `apps/web/e2e/clip-review.spec.ts` implementing the revised test-plan §6 scenarios TC-E2E-01..04 against the real embedded UI, following the established data pattern in `apps/web/e2e/media.spec.ts`: register a unique user via the UI, create the project via the UI (capture the id from the 201 response), open the project via its `Open {name}` button, click the "Review Clips" tab. No magic seed users, no route navigation, no API interception in these scenarios.
3. Depends on 7.5: the review section must mount without crashing before any of these scenarios can pass.

Verification:

```bash
# Discovery: the spec must be selected by Playwright (non-empty) — locally executable
cd apps/web && npx playwright test e2e/clip-review.spec.ts --list

# Full run across the three viewport projects — authoritative venue: CI e2e job
cd apps/web && npm run test:e2e
```

Expected evidence: all scenarios discovered and passing across the mobile/tablet/desktop projects; `GET /api/v1/projects/{id}/clip-reviews` returns 200 `{"data": []}`; no console errors.

### 7.5 Defect 5 (Planner-discovered) — embedded `ReviewPage` crashes without a Router

Diagnosis: `ReviewPage` calls `useSearchParams()` unconditionally, but the production tree (`index.html` → `main.tsx` → `App` → `AuthenticatedShell` → `ProjectMediaSection`) contains no Router — the only Router in the repository is the `MemoryRouter` wrapper inside `ReviewPage.test.tsx`, which masks the crash in component tests. react-router v7 hooks throw outside Router context, so in a real browser clicking "Review Clips" throws during mount and unmounts the entire application (no error boundary exists). This blocks every rewritten E2E scenario in 7.4, including the empty state.

Fix (Builder, in scope, TDD):

1. RED: update `ReviewPage.test.tsx` first — render `ReviewPage` without any Router provider (production topology; keep only the query-client wrapper) and assert it mounts and renders (currently fails by crashing). Also add the URL-sync assertions against `window.location` (deep-link param read on mount, URL update on selection, N/P updates URL) — these are genuinely RED today because `MemoryRouter` mutates its in-memory location, never `window.location`.
2. GREEN: replace `useSearchParams` in `ReviewPage.tsx` with native URL sync: read `clipReviewId` from `window.location.search` on mount; update the URL with `window.history.replaceState` on selection (replace, not push, so the browser Back button keeps leaving the project instead of stepping through clip selections). Behavior is otherwise unchanged.
3. Complete the pre-existing TC-FE-05 rows that this component owns (selection of first pending clip, VideoPlayer `video_url` prop, ReviewActions status prop, FeedbackForm conditional render, N/P navigation with boundaries, deep-link on mount). These rows are already required by the ratified test-plan §4; they are load-bearing for AC-09 because E2E cannot reach populated review states. Tests for already-working behavior may pass on first execution (coverage completion); the router-topology and URL-sync tests are the RED anchors.
4. REFACTOR: rerun the full Vitest suite; remove the `MemoryRouter` import and wrapper.

Verification:

```bash
cd apps/web && npm test -- --run src/features/clip-review
```

Expected evidence: RED (mount crash without Router + `window.location` never updating) recorded before the fix; GREEN afterwards; no other component behavior altered.

### 7.6 Dead-Code Removal — `ClipReviewPage.tsx` and the unused router dependency

Decision (recorded per the defect-4 reconciliation): REMOVE `apps/web/src/pages/ClipReviewPage.tsx`. It is unmounted dead code authored by this issue's commit 011b273, duplicates the embedded `ReviewPage`, and depends on a routed topology (`useParams`, `/projects/:id/review`) that does not exist in the running application. Removing it is in-scope cleanup of this issue's own artifact, not an opportunistic refactor. It is the only file in `apps/web/src/pages/` — remove the empty directory. Nothing imports it (verified: no references anywhere in `apps/web`).

After 7.5 and this removal, `react-router-dom` has no remaining usage in `apps/web/src` (the only usages were this file, `ReviewPage.tsx`, and the `ReviewPage.test.tsx` wrapper). Builder verifies with a repo grep and, if confirmed zero, removes `react-router-dom` from `apps/web/package.json` devDependencies and regenerates `package-lock.json` via `npm install` — CI jobs run `npm ci`, which fails on a stale lockfile. If any additional usage is found, leave the dependency in place and report the finding to the Orchestrator instead.

Verification:

```bash
# Zero remaining router usage in source
grep -rn "react-router" apps/web/src
# Lint + build + lockfile sync
cd apps/web && npm run lint && npm run build
```

### 7.7 Known Limitations recorded

The spec section "Known Limitations and Coverage Boundary" is now part of the contract (Planner-authored with this phase): (1) the presigned video redirect requires S3-compatible storage — the local disk fails closed with 500, and the real presigned flow is verified in the CI backend job; a local-disk streaming fallback is future work routed for post-issue Planner evaluation; (2) e2e reaches only the embedded review section's empty state — full-workflow E2E is deferred until the e2e environment can run the render pipeline, routed for post-issue Planner evaluation. No implementation work in this phase; no new scope is authorized by these limitations.

### 7.8 Phase 7 Verification Matrix

| Defect | Fix artifact | Verification command | Venue | Expected |
|---|---|---|---|---|
| 1 | `ClipReviewRealIntegrationTest.php` group declarations + availability guard | group-declaration grep; gated command of test-plan §7/§9 | grep: local; gate: CI backend `tests` job (authoritative) | Gated run selects all tests, executes them, zero skips |
| 2 | real presigned case (no `Storage::fake`) | same gated command | CI backend `tests` job | 302 → real presigned URL → real GET 200 with exact bytes |
| 3 | rollback-on-error case | same gated command | CI backend `tests` job | Exception propagates; review stays `pending`; no partial state; no event |
| 4 | `apps/web/e2e/clip-review.spec.ts` created; `apps/web/tests/e2e/` deleted | `npx playwright test e2e/clip-review.spec.ts --list`; `npm run test:e2e` | list: local; full run: CI e2e job (authoritative) | Spec discovered; all scenarios pass on all three viewport projects; clip-reviews GET 200 `{"data": []}` |
| 5 | `ReviewPage.tsx` native URL sync + `ReviewPage.test.tsx` without Router | `npm test -- --run src/features/clip-review` | Local + CI frontend job | RED crash recorded pre-fix; GREEN post-fix; TC-FE-05 rows complete |
| 6 | `pages/ClipReviewPage.tsx` removed; `react-router-dom` removed from devDependencies (if grep-confirmed unused) | `grep -rn "react-router" apps/web/src`; `npm run lint && npm run build` | Local + CI frontend job | Zero usages; lint/build green; lockfile regenerated |
| All | full regression | `cd apps/api && php artisan test`; `cd apps/web && npm test && npm run lint && npm run build && npm run test:e2e` | Local where infrastructure exists; CI authoritative | Zero failures; previously passing suites stay green |

### 7.9 Phase Exit Criteria

- The four Tester defects and defect 5 are fixed as specified; no assertion weakened, skipped, or deleted.
- The mandatory real-integration gate is green in the CI backend `tests` job.
- The e2e suite discovers and passes the rewritten clip-review spec in the CI e2e job.
- The planning bundle (spec/plan/test-plan) contains no scenario that requires unreachable UI states or data.
- Independent Tester re-review covers the four defects plus defect 5 and re-runs the visual/console/network checks on the reachable review section.

## Remaining Gates (Not Claimed Complete)

- Independent Tester review: runs all backend/frontend/E2E tests, operates running app, inspects screenshots at 3 viewports, checks console/network/API, validates accessibility, security, ownership, CSRF, secret exposure.
- Orchestrator: commits, pushes, creates PR, monitors CI, merges after Tester approval and CI green.
- Issue closure after verified merge.

---

**Note**: This plan follows strict TDD. For each backend step (1–4), write test first (RED), implement (GREEN), refactor (tests still GREEN). For frontend steps (5–7), write Vitest component test first (RED), implement component (GREEN), refactor.