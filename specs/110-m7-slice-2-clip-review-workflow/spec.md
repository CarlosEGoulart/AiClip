# Specification: Clip Review Status Workflow — Issue #110

## Authority and Description

Active issue: [Issue #110](https://github.com/CarlosEGoulart/AiClip/issues/110).

M7 Slice 1 (Issue #106, PR #107) established the Clip Review Experience foundation: the `ClipReview` model (status enum `pending`, `approved`, `rejected`, `needs_changes`), the review API (project-scoped paginated list, show, status update, feedback, dual-gate signed video URL), the `ProcessClipReview` queue job, automatic `ClipReview` creation on render completion, and the React UI (`VideoPlayer`, `ReviewActions`, `FeedbackForm`, `ReviewList`, `ReviewPage`).

Slice 1 explicitly deferred full approve/reject/needs_changes E2E coverage: the e2e CI environment provisions no MinIO and no Python worker/FFmpeg render pipeline, so no `ClipReview` row can exist there; the reachable review state is the embedded review section's empty state.

This slice (M7 Slice 2) delivers the **full approve/reject/needs_changes status workflow with E2E coverage**. It defines:

1. The `ClipReview` status transition contract: `pending → approved | rejected | needs_changes` (one-way; terminal states cannot transition further; cannot revert to `pending`).
2. The `PATCH /api/v1/clip-reviews/{clipReview}/status` endpoint contract: validates and applies a one-way transition, returns the updated `ClipReview` resource reflecting the new status, and dispatches `ProcessClipReview` (idempotent, re-validates ownership, fires `ClipReviewCompleted`).
3. A **test-seeding route** (testing environment only) that creates a rendered `DerivedAsset` + pending `ClipReview` fixture so the E2E environment can reach populated review states without the render pipeline.
4. `ReviewActions` button handlers (approve / reject / needs_changes): confirmation dialog → PATCH → status badge update + announcement; disabled during mutation.
5. Full E2E approve/reject/needs_changes flow at the three required viewports (390x844, 768x1024, 1440x900).

Read this specification with `plan.md` and `test-plan.md` in this directory. The active issue and explicit human corrections define this increment. `docs/architecture.md`, `docs/prd.md`, `docs/roadmap.md`, `docs/project-state.md`, and ADR-0002 provide context, not authorization for their broader future workflows.

## Technical Tasks

- [ ] Define the `ClipReview` one-way status transition contract (`pending → approved/rejected/needs_changes`; terminal states; no revert to `pending`)
- [ ] Define the `PATCH /api/v1/clip-reviews/{clipReview}/status` endpoint contract (validated transition, updated resource returned, job dispatched)
- [ ] Define the test-seeding route (`POST /api/v1/test/clip-reviews/seed`, testing environment only) for E2E fixtures
- [ ] Define `ReviewActions` button handlers (confirmation → PATCH → badge/announcement, disabled during loading)
- [ ] Define the full E2E approve/reject/needs_changes flow at 390x844 / 768x1024 / 1440x900
- [ ] Define Pest backend tests for the transition matrix (all valid + all invalid transitions)
- [ ] Define Vitest frontend tests for `ReviewActions` handlers and status reflection
- [ ] Define Playwright E2E tests for approve/reject/needs_changes happy paths at three viewports
- [ ] Define security, ownership, CSRF, rate-limit, and accessibility requirements

## Scope and Out of Scope

### In Scope

1. **Status transition contract**: one-way `pending → approved | rejected | needs_changes`; terminal states (`approved`, `rejected`, `needs_changes`) reject any further transition; reverting to `pending` is rejected.
2. **PATCH status endpoint**: `PATCH /api/v1/clip-reviews/{clipReview}/status` with body `{ "status": "approved" | "rejected" | "needs_changes" }`; returns 200 with the updated `ClipReviewResource`; 422 for invalid/forbidden status; 404 for non-owner/non-existent; 419 missing CSRF; 401 guest; rate-limited at 30/min.
3. **Test-seeding route**: `POST /api/v1/test/clip-reviews/seed`, registered **only** in the `testing` environment, sanctum-authenticated, creates a `MediaAsset` + rendered `DerivedAsset` (`type=clip_rendered`, `render_status=completed`) + pending `ClipReview` for the authenticated user's project; returns the `ClipReviewResource`. Aborts 404 outside `testing`. Never exposed in production/staging.
4. **Queue job**: `ProcessClipReview` persists the transition (idempotent; re-validates ownership; no-op on non-pending; fires `ClipReviewCompleted`); in test/e2e the queue runs synchronously so the transition is observable in the PATCH response.
5. **`ReviewActions` handlers**: each of Approve / Reject / Needs Changes opens an accessible confirmation dialog; on confirm calls the PATCH endpoint; on success updates the status badge, announces via `aria-live="polite"`, disables buttons during mutation with a spinner.
6. **Frontend status reflection**: after a successful PATCH, the selected clip's status badge and the review-list badge update without a full-page reload; `N`/`P` navigation and deep-link `clipReviewId` remain consistent.
7. **Full E2E approve/reject/needs_changes flow**: seed a pending review via the test-seeding route, open the project's "Review Clips" tab, select the clip, play the video, click each action, confirm, and verify the status badge transitions (green/red/orange) plus feedback form behavior for reject/needs_changes.
8. **Pest backend tests**: full transition matrix (valid transitions, invalid status `pending`/`foo`, revert-from-terminal, ownership, CSRF, auth, rate limit).
9. **Vitest frontend tests**: `ReviewActions` confirm/cancel/disabled/announcement; `ReviewPage` status update after PATCH mock.
10. **Playwright E2E tests**: approve happy path, reject happy path (with feedback), needs_changes happy path (with feedback), at all three viewports, with console/network cleanliness.

### Out of Scope

- Reverting a review back to `pending`
- Transitioning from a terminal state (`approved`/`rejected`/`needs_changes`) to another state
- Batch review operations
- Review analytics/dashboard
- Email/notification on review completion (the `ClipReviewCompleted` event exists; consumers are future work)
- Multiple reviewers per clip
- Clip versioning/history
- Re-render triggered by `needs_changes` (out of scope per Slice 1)
- Social publishing (M9–M12)
- Test-seeding route in any environment other than `testing`

## Authentication, Ownership, and Request Ordering

- Existing `auth:sanctum` SPA cookie/session authentication. State-changing requests retain CSRF validation using the CSRF-cookie handshake and `X-XSRF-TOKEN`. No bearer-token flow or credentials in localStorage.
- Ownership is server-controlled: `session user → project → media_asset → derived_asset → clip_review`. Resolve an owned project before any clip-review operation. Nonexistent and non-owned resources both return 404 (no ownership information leak).
- Missing/expired authentication returns JSON 401. Missing/mismatched CSRF on mutations returns 419 without mutation.
- On state-changing requests, CSRF validation runs before authentication: a write missing both session and CSRF token fails CSRF first and returns 419; a write with a valid CSRF token but no authenticated session reaches the auth guard and returns 401.
- Do not mass-assign client data. Status updates accept only `status` and optional `feedback`; client-supplied IDs cannot influence ownership.
- Apply `clip-review-update` rate limiter at 30 requests per minute per authenticated user, shared across projects.
- The test-seeding route is sanctum-authenticated and restricted to the `testing` environment; it accepts only `project_id` and enforces that the project belongs to the authenticated user.

## Data Model (unchanged from Slice 1)

Reference the Slice 1 `ClipReview` migration, model, `ClipReviewStatus` enum, and `DerivedAsset` integration:

- `clip_reviews` table: `id`, `derived_asset_id` (FK cascade), `reviewer_id` (FK users cascade), `status` enum (`pending`, `approved`, `rejected`, `needs_changes`, default `pending`), `feedback` (text nullable), timestamps; unique index on `[derived_asset_id, reviewer_id]`; index on `[reviewer_id, status]`.
- `ClipReviewStatus` backed enum: `PENDING`, `APPROVED`, `REJECTED`, `NEEDS_CHANGES`.
- `DerivedAsset::clipReview()` → `HasOne`; `scopeWithReviewStatus()`.

No schema changes are introduced by this slice.

## Status Transition Contract

| From | To | Allowed | Notes |
|------|-----|---------|-------|
| `pending` | `approved` | Yes | One-way approval |
| `pending` | `rejected` | Yes | One-way rejection |
| `pending` | `needs_changes` | Yes | One-way request for changes |
| `pending` | `pending` | No | PATCH `status=pending` is rejected (422) |
| `approved` | any | No | Terminal state |
| `rejected` | any | No | Terminal state |
| `needs_changes` | any | No | Terminal state |
| non-existent | any | No | 404 |

The PATCH endpoint accepts only `approved`, `rejected`, `needs_changes`. `pending` and any other value return 422.

## Public API

| Endpoint | Method | Success | Description |
|----------|--------|---------|-------------|
| `/api/v1/projects/{project}/clip-reviews` | GET | 200 `{"data": {...}}` | List clip reviews for project (newest first) — standard pagination envelope |
| `/api/v1/clip-reviews/{clipReview}` | GET | 200 `{"data": {...}}` | Get single clip review with derived asset + signed video URL |
| `/api/v1/clip-reviews/{clipReview}/status` | PATCH | 200 `{"data": {...}}` | **One-way status transition** (`pending → approved/rejected/needs_changes`) |
| `/api/v1/clip-reviews/{clipReview}/feedback` | POST | 200 `{"data": {...}}` | Submit feedback text (for rejected/needs_changes) |
| `/api/v1/clip-reviews/{clipReview}/video` | GET | 302 | Signed video URL (time-limited, 1 hour) |
| `/api/v1/test/clip-reviews/seed` | POST | 200 `{"data": {...}}` | **Testing only**: seed MediaAsset + rendered DerivedAsset + pending ClipReview |

### Test-Seeding Route (testing environment only)

`POST /api/v1/test/clip-reviews/seed`

- Registered **only** when `app.environment('testing')`; aborts 404 in any other environment.
- Requires sanctum authentication.
- Body: `{ "project_id": <int> }`; validates the project exists and belongs to the authenticated user (else 404).
- Creates:
  - `MediaAsset` (`mime_type=video/mp4`, status stored, local disk)
  - `DerivedAsset` (`type=clip_rendered`, `render_status=completed`, `candidate_index`, `render_profile_version`, `duration_ms`, `width`, `height`)
  - `ClipReview` (`status=pending`, `reviewer=authenticated user`, `feedback=null`)
- Returns `ClipReviewResource` (with signed `video_url`).
- Purpose: E2E fixture setup only. Never part of production behavior.

## Queue Job: ProcessClipReview (unchanged behavior from Slice 1)

Job signature:
```php
class ProcessClipReview implements ShouldQueue
{
    public function __construct(
        public int $clipReviewId,
        public string $action, // 'approve', 'reject', 'needs_changes'
    ) {}
    public function handle(): void { ... }
}
```

Behavior:
1. Load `ClipReview` with `derivedAsset.mediaAsset.project` and `reviewer`.
2. Re-validate ownership (`reviewer_id` === project `user_id`); on mismatch, log warning and return (no state change, no event).
3. Idempotency: if `status !== pending`, return early (no state change, no event).
4. Map action → `ClipReviewStatus` (`approve`→`APPROVED`, `reject`→`REJECTED`, `needs_changes`→`NEEDS_CHANGES`); invalid action throws `InvalidArgumentException`.
5. `DB::transaction`: update `ClipReview.status`. `DerivedAsset.render_status` stays `completed` (already completed from render).
6. Dispatch `ClipReviewCompleted` event.
7. In test/e2e the queue runs synchronously (`QUEUE_CONNECTION=sync`) so the transition is observable immediately after `dispatch()`.

## UX and Accessibility

### ReviewActions Component

- Three buttons: Approve (green), Reject (red), Needs Changes (orange).
- Each button disabled when `currentStatus` equals that action (already applied).
- Clicking a button opens an accessible confirmation dialog (`role="alertdialog"`, `aria-modal="true"`, focus trap, Escape cancels, Enter/Space confirms).
- The dialog notes that feedback is required (minimum 10 characters) for Reject/Needs Changes.
- Confirm → call `onStatusChange(status)` (the PATCH handler); while pending, buttons disabled and a spinner shows.
- On success: announce via `aria-live="polite"` (e.g. "Clip approved"); update local status so the badge reflects the new state; close dialog and restore focus.
- On error: announce via `aria-live="polite"`.
- Keyboard accessible: Enter/Space activates buttons; Escape closes dialog.
- 44px touch targets; visible focus indicators.

### ReviewPage

- After a successful PATCH, `refetch()` reloads the clip review list; the selected clip's status badge updates (green/red/orange) and the review-list badge updates accordingly.
- `N`/`P` navigation and deep-link `clipReviewId` (native History API, `window.history.replaceState`) remain consistent after a transition.
- Feedback form conditionally renders for `rejected`/`needs_changes`; hidden for `approved`/`pending`.
- Responsive: single column < 768px, side-by-side ≥ 768px; VideoPlayer `aspect-ratio 9/16`.

## Responsive Viewports

Test at: **390x844** (mobile), **768x1024** (tablet), **1440x900** (desktop).

## Acceptance Criteria

- [ ] **AC-01**: Status transition contract enforced: `pending → approved/rejected/needs_changes` allowed; `pending` revert and any transition from a terminal state rejected (422); non-owner/non-existent 404.
- [ ] **AC-02**: `PATCH /api/v1/clip-reviews/{clipReview}/status` returns 200 with the updated `ClipReviewResource` reflecting the new status; dispatches `ProcessClipReview`; validates `status` ∈ {approved, rejected, needs_changes}; 419 missing CSRF; 401 guest; rate-limited at 30/min.
- [ ] **AC-03**: `ProcessClipReview` job handles all three actions; idempotent (no-op on non-pending); re-validates ownership; fires `ClipReviewCompleted`; observable in test/e2e via synchronous queue.
- [ ] **AC-04**: Test-seeding route `POST /api/v1/test/clip-reviews/seed` exists only in `testing`, is sanctum-authenticated, enforces project ownership, creates MediaAsset + rendered DerivedAsset + pending ClipReview, returns the resource; aborts 404 outside `testing`.
- [ ] **AC-05**: `ReviewActions` renders Approve/Reject/Needs Changes; each confirms via accessible dialog; confirm calls the PATCH endpoint; buttons disabled during mutation with spinner; success announced via `aria-live="polite"`; status badge updates after transition.
- [ ] **AC-06**: `ReviewPage` reflects a status transition without reload (badge + list badge update); `N`/`P` navigation and deep-link `clipReviewId` remain consistent; feedback form shows for reject/needs_changes only.
- [ ] **AC-07**: Pest backend tests pass for the full transition matrix (unit + feature); no skipped tests.
- [ ] **AC-08**: Vitest frontend tests pass for `ReviewActions` handlers and `ReviewPage` status reflection; no skipped tests.
- [ ] **AC-09**: Playwright E2E tests pass for the full approve/reject/needs_changes happy paths at 390x844, 768x1024, and 1440x900; console/network cleanliness; no secrets in browser.
- [ ] **AC-10**: Full backend/frontend/E2E suites pass; lint, build, and type check pass; governance passes.

## Known Limitations and Coverage Boundary

1. **E2E fixture data requires the test-seeding route.** The e2e CI environment runs real Laravel + PostgreSQL with `FILESYSTEM_DISK=local` and provisions neither MinIO nor the Python worker/FFmpeg render pipeline, so a `ClipReview` cannot be produced through the real render pipeline. The test-seeding route (testing environment only) creates the fixture DerivedAsset + ClipReview so E2E can exercise the full approve/reject/needs_changes UI workflow against the real running app. This route is a test boundary: it is registered only in `testing` and never exposed in production/staging.

2. **Queue observability in E2E.** The e2e server runs with `QUEUE_CONNECTION=database` (per `.env`) and no queue worker, so a dispatched `ProcessClipReview` job would not run inline and the status transition would not be observable after the PATCH returns. Slice 2 requires the e2e server to process jobs synchronously (`QUEUE_CONNECTION=sync` for the e2e `php artisan serve` process, or an equivalent mechanism) so the PATCH response reflects the new status. This is an environment configuration requirement, not a production behavior change.

3. **No revert / terminal transitions.** Once a review is `approved`, `rejected`, or `needs_changes`, it cannot transition again. Re-render triggered by `needs_changes` remains out of scope (future slice).

## Security Considerations

- **Scoped Access**: All endpoints verify `user → project → media_asset → derived_asset → clip_review`. No IDOR; non-owner and non-existent both return 404.
- **Test-seeding route**: `testing`-environment-only (404 elsewhere); sanctum-authenticated; project-ownership enforced; never registered outside test environments.
- **CSRF Protection**: All mutations (PATCH, POST feedback, seed) require valid `X-XSRF-TOKEN` from the encrypted cookie; CSRF precedes `auth:sanctum` (419 before 401).
- **Rate Limiting**: `clip-review-update` at 30 req/min per user.
- **Signed URLs**: Video playback uses time-limited (1 hour) signed routes; invalid/expired/tampered signatures return 403 before any ownership check.
- **Input Validation**: Status must be one of `approved|rejected|needs_changes`; feedback max 1000 chars; sanitized on output.
- **No Secrets in Frontend**: Storage keys, disks, internal IDs never exposed to browser; test-seeding route returns no storage internals.
- **Queue Job Authorization**: Job re-validates ownership before processing; no trust on job payload alone.

## Dependencies

- **M7 Slice 1 complete** (Issue #106, PR #107): `ClipReview` model/API/job/frontend, `DerivedAsset` with `type=clip_rendered`, Sanctum SPA auth, media storage, API routes.
- **Laravel 13, PHP 8.3, PostgreSQL 16**: Existing stack.
- **React 19, TypeScript, Vite 8, Vitest, Playwright**: Existing frontend stack.
- **Testing environment**: `QUEUE_CONNECTION=sync` (or equivalent synchronous job processing) for the e2e server so transitions are observable.
- **Laravel Queues**: For `ProcessClipReview` job.

## Human-Gated Operations

- None specific to this slice beyond standard issue lifecycle. `tests/governance/**` and `scripts/merge_gate.py` remain denied to all agents.

---

SPEC_READY