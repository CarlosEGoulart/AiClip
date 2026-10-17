# Implementation Plan: Clip Review Status Workflow — Issue #110

## Active Scope and Working-Tree Baseline

Issue: [Issue #110](https://github.com/CarlosEGoulart/AiClip/issues/110). `spec.md` is the behavior contract; `test-plan.md` defines required verification. All steps follow Laravel 13, React 19, TypeScript, Pest, Vitest, Playwright conventions.

Planning inspected the active issue, architecture, `docs/project-state.md`, the Slice 1 implementation (`ClipReview` model/API/job, `ReviewActions`/`ReviewPage`/`FeedbackForm`/`ReviewList`/`VideoPlayer`, hooks, api client, types), the E2E topology (`App → AuthenticatedShell → ProjectMediaSection → "Review Clips" tab → embedded ReviewPage`; native History API URL sync; no client-side router), and the e2e environment constraints (real Laravel + PostgreSQL, `FILESYSTEM_DISK=local`, `QUEUE_CONNECTION=database`, no MinIO, no render worker, no queue worker).

Observed current state:
- `ClipReview` model, migration, `ClipReviewStatus` enum, factory, `ProcessClipReview` job, `ClipReviewCompleted` event, controller, resources, requests all exist (Slice 1).
- API routes registered: list, show, PATCH status, POST feedback, video (`clip-review.video`).
- `ClipReviewController::updateStatus` dispatches `ProcessClipReview::dispatch()` then returns `$clipReview->refresh()`. With a synchronous queue the response reflects the new status; with `QUEUE_CONNECTION=database` it does not.
- Frontend: `ReviewActions`, `FeedbackForm`, `ReviewList`, `ReviewPage`, `VideoPlayer` exist; `ReviewPage` uses native History API (no router); `useUpdateClipReviewStatus` mutation invalidates queries on success.
- E2E: `apps/web/e2e/clip-review.spec.ts` covers the reachable empty state (discoverability, non-interactivity, responsive viewports, console/network cleanliness) at three viewports. Full approve/reject/needs_changes E2E is not yet covered (Slice 1 limitation).
- No test-seeding route exists.
- phpunit (test env): `QUEUE_CONNECTION=sync`, `APP_ENV=testing`, sqlite `:memory:`.
- `.env` (local/e2e): `QUEUE_CONNECTION=database`, `FILESYSTEM_DISK=local`, `SESSION_DRIVER=database`, `DB_CONNECTION=pgsql`.

No `ClipReview` behavior change is required beyond enforcing the one-way transition contract and making the transition observable in the PATCH response. The E2E fixture problem is solved by a testing-environment-only seed route.

## Step 0 — Repository Setup and Dependency Verification

1. Verify the active issue #110 is the only implementation issue (NO_ACTIVE_ISSUE → Issue #110 active).
2. Verify `composer.json` requires no new packages (uses existing Laravel queue, validation, Sanctum).
3. Verify `package.json` requires no new packages (uses existing React, React Query, Testing Library, Playwright).
4. Run `docker compose up -d postgres` from repository root; verify PostgreSQL health.
5. Run `php artisan migrate:fresh --seed` in `apps/api` to establish a clean database.
6. Run `php artisan test` in `apps/api` and `npm test` in `apps/web` to confirm baseline passes.
7. Confirm no `.env` secrets are read or inspected.

## Step 1 — Backend: One-Way Status Transition Contract (RED → GREEN → REFACTOR)

### 1.1 RED — Write the transition-matrix feature test

File: `apps/api/tests/Feature/ClipReview/ClipReviewTransitionTest.php` (new).
Base: `uses(SpaTestCase::class, RefreshDatabase::class);` (matches passing-suite convention; see Slice 1 Phase 6 harness repair).

Test scenarios (each RED before implementation):
- Owner, `pending → approved`: 200, `data.status === 'approved'`, job dispatched, `ClipReview.refresh()->status === APPROVED`.
- Owner, `pending → rejected`: 200, status `rejected`.
- Owner, `pending → needs_changes`: 200, status `needs_changes`.
- Owner, `pending → pending`: 422 (cannot revert to pending).
- Owner, `pending → foo`: 422 (invalid status).
- Owner, `approved → rejected` (already terminal): 422 (no further transition).
- Owner, `rejected → approved` (already terminal): 422.
- Owner, `needs_changes → approved` (already terminal): 422.
- Non-owner: 404.
- Non-existent: 404.
- Missing CSRF: 419.
- Guest (valid CSRF, no session): 401.
- Rate limit: 429 on 31st update request.

### 1.2 GREEN — Implement the transition contract

- `ClipReviewStatusUpdateRequest`: keep `status` required, `in:approved,rejected,needs_changes` (already excludes `pending`). Add an `after()` or controller guard so a transition *from* a non-pending `ClipReview` returns 422 (terminal-state guard).
- `ClipReviewController::updateStatus`: keep dispatch + refresh; ensure the returned resource reflects the new status. With sync queue (test/e2e) the job runs inline during `dispatch()`, so `refresh()` returns the new status.
- No production behavior change to the job itself (already handles all 3 actions, idempotent, ownership re-validation, event).

### 1.3 REFACTOR

- Rerun `php artisan test tests/Feature/ClipReview/ClipReviewTransitionTest.php`; all green.
- Rerun the full ClipReview suite; no regressions.
- `vendor/bin/pint --dirty --format agent` in `apps/api`.

## Step 2 — Backend: Test-Seeding Route for E2E Fixtures (RED → GREEN → REFACTOR)

### 2.1 RED — Write the seed-route feature test

File: `apps/api/tests/Feature/ClipReview/ClipReviewSeedTest.php` (new).

Test scenarios:
- `testing` environment only: authenticated user seeds their own project → 200, returns `ClipReviewResource` with `status === 'pending'`, `video_url` present (URI path + `signature` + `expensires`); database now has a pending `ClipReview` for a rendered `DerivedAsset`.
- Non-owner project: 404.
- Non-existent project: 404.
- Guest: 401.
- Missing CSRF: 419.
- Outside `testing` (`APP_ENV !== testing`): 404 (route not registered / aborts 404).

### 2.2 GREEN — Implement the seed route

- Create a controller method (e.g. `ClipReviewController::seed`) or a dedicated `ClipReviewSeedController`.
- Register `Route::post('/test/clip-reviews/seed', ...)->middleware('auth:sanctum', 'throttle:...')` inside the `auth:sanctum` group, guarded so it only exists in `testing` (e.g. `if (app()->environment('testing')) { Route::post(...) }` or a middleware that aborts 404 unless `app()->environment('testing')`).
- Validation: `project_id` required, exists, belongs to `auth()->user()` (else 404).
- Create `MediaAsset` (video/mp4, stored, local disk), `DerivedAsset` (`type=clip_rendered`, `render_status=completed`, candidate index, render profile version, duration/width/height), `ClipReview` (`status=pending`, `reviewer=auth user`).
- **Write a real, browser-loadable minimal media object to the configured media disk at the created `DerivedAsset.storage_key`** (testing environment only, inside the seed path). Content may be a minimal fixture (tiny valid video bytes); the point is that the signed `video_url` resolves with HTTP 2xx so the E2E `<video>` element loads it instead of a storage 404. No storage internals (`storage_disk`, `storage_key`, bucket) are exposed in the response.
- Return `ClipReviewResource`.

### 2.3 REFACTOR

- Rerun seed test + full ClipReview suite; green.
- Pint.
- Verify the route is absent/404 when `APP_ENV=local` (grep/route-list check in test).

## Step 3 — Backend: Queue Observability Configuration for Test/E2E

- Confirm `QUEUE_CONNECTION=sync` in `apps/api/phpunit.xml` (already set) so Pest feature tests observe transitions immediately.
- Document (and verify in the e2e environment) that the e2e `php artisan serve` process uses a synchronous queue (`QUEUE_CONNECTION=sync` or equivalent) so the PATCH response reflects the new status. This is an environment prerequisite for AC-09, not a production behavior change.

## Step 4 — Frontend: ReviewActions Handlers + Status Reflection (RED → GREEN → REFACTOR)

### 4.1 RED — Vitest tests

Files (extend existing `apps/web/src/features/clip-review/components/*.test.tsx`):
- `ReviewActions.test.tsx`: add cases — confirm Approve calls `onStatusChange('approved')`; confirm Reject/Needs Changes calls `onStatusChange` and triggers feedback requirement notice; cancel does not call the handler; buttons disabled during `disabled`/`loading` with spinner; success announcement via `aria-live="polite"`; Escape closes dialog; button already-applied (e.g. Approve when `currentStatus === 'approved'`) is disabled.
- `ReviewPage.test.tsx`: add case — after a successful `updateStatusMutation` mock, the selected clip's status badge updates (green/red/orange) and `refetch()` is called; `N`/`P` navigation and `clipReviewId` URL remain consistent; feedback form shows for reject/needs_changes only.

### 4.2 GREEN — Implement frontend behavior

- `ReviewActions`: confirm handlers already call `onStatusChange`; ensure the announcement and disabled/loading states are correct (already largely implemented per Slice 1; verify against tests).
- `ReviewPage`: `handleStatusChange` already calls `updateStatusMutation.mutateAsync` then `refetch()`; verify status badge and list badge re-render. No code change expected unless a test reveals a gap.
- `hooks.ts`: `useUpdateClipReviewStatus` already invalidates `['clipReview', id]` and `['clipReviews']` on success.

### 4.3 REFACTOR

- Rerun `npm test -- --run src/features/clip-review`; all green.
- `npm run lint` and `npm run build` in `apps/web`.

## Step 5 — E2E: Full Approve/Reject/Needs-Changes Flow (RED → GREEN → REFACTOR)

### 5.1 RED — Write Playwright scenarios

File: `apps/web/e2e/clip-review.spec.ts` (extend the existing Slice 1 spec). Add scenarios:

- **TC-E2E-05: Approve happy path** — register unique user via UI, create project via UI (capture id from 201), seed a pending review via `POST /api/v1/test/clip-reviews/seed` (real API request, no interception), open project, click "Review Clips" tab, select the clip, verify pending badge, click Approve → confirm dialog → verify status badge green (`Approved`), verify review-list badge green.
- **TC-E2E-06: Reject happy path** — same setup; select clip; click Reject → confirm; feedback form appears (feedback required, min 10 chars); enter feedback → submit → verify status badge red (`Rejected`).
- **TC-E2E-07: Needs Changes happy path** — same setup; select clip; click Needs Changes → confirm; feedback form appears; enter feedback → submit → verify status badge orange (`Needs Changes`).
- **TC-E2E-08: Transition not allowed after terminal** — after approve, clicking Reject/Needs Changes is disabled (`aria-pressed="true"` / disabled) and no further PATCH occurs.

All scenarios:
- Run on the three viewport projects (mobile 390x844, tablet 768x1024, desktop 1440x900) configured in `playwright.config.ts`; viewport sizes never set manually.
- Follow the established data pattern: unique user via UI, unique project via UI, capture id from real 201 response. No magic seed users (`test@example.com` does not exist). No API interception for the review flow (the seed request is a real API call; the review UI requests run against the real Laravel server).
- Enforce console/network cleanliness (TC-E2E-04 pattern): no console errors/warnings, no uncaught exceptions, no unexpected 4xx/5xx (except the ratified anonymous `GET /api/v1/auth/me` 401 session probe), no tokens/credentials in browser storage.
- Clean up: delete the project via the UI delete flow.

### 5.2 GREEN — Implement and run

- The backend (Steps 1–3) and frontend (Step 4) must be GREEN before E2E can pass.
- Run `cd apps/web && npx playwright test e2e/clip-review.spec.ts --list` (discovery, non-empty).
- Run `cd apps/web && npm run test:e2e` (full run across the three viewport projects).

### 5.3 REFACTOR

- Rerun full E2E; all green across viewports.
- Verify no console errors, no unexpected HTTP, screenshots captured on failure only.
- `npm run lint && npm run build` remain green.

## Step 6 — Full Test Suite and Verification

| Working Directory | Command / Required Result |
|---|---|
| Repository root | `docker compose up -d postgres` — verify health |
| `apps/api` | `php artisan migrate:fresh --seed` — clean database |
| `apps/api` | `php artisan test --exclude-group=real-integration` — fast feedback; zero failures |
| `apps/api` | `php artisan test tests/Feature/ClipReview` — all ClipReview suites pass (transitions + seed + existing) |
| `apps/api` | `vendor/bin/pint --dirty --format agent` — formatting |
| `apps/web` | `npm test` — Vitest suite pass |
| `apps/web` | `npm run lint` — lint pass |
| `apps/web` | `npm run build` — TypeScript + Vite build pass |
| `apps/web` | `npm run test:e2e` — Playwright all viewport projects; clip-review approve/reject/needs_changes scenarios pass at 3 viewports |
| Repository root | `python -m unittest discover -s tests/governance -p 'test_*.py' -v` — governance pass |

## Verification Checklist

- [ ] One-way status transition contract: `pending → approved/rejected/needs_changes` allowed; revert-to-pending and terminal-state transitions rejected (422)
- [ ] `PATCH /api/v1/clip-reviews/{clipReview}/status`: 200 + updated resource; 422 invalid/forbidden status; 404 non-owner/non-existent; 419 missing CSRF; 401 guest; 429 rate limit
- [ ] `ProcessClipReview` job: all 3 actions; idempotent; ownership re-validation; `ClipReviewCompleted` event; observable via synchronous queue in test/e2e
- [ ] Test-seeding route `POST /api/v1/test/clip-reviews/seed`: `testing` only (404 elsewhere); sanctum-auth; project ownership enforced; returns `ClipReviewResource`; creates MediaAsset + rendered DerivedAsset + pending ClipReview **and persists a real minimal media object at `DerivedAsset.storage_key` on the configured disk so `video_url` resolves (2xx)**
- [ ] `ReviewActions`: three actions; confirmation dialog; confirm → PATCH; disabled during loading + spinner; `aria-live="polite"` announcement; badge updates
- [ ] `ReviewPage`: status reflection without reload; `N`/`P` navigation + `clipReviewId` deep-link consistent; feedback form for reject/needs_changes only
- [ ] Pest backend tests: full transition matrix; no skipped tests
- [ ] Vitest frontend tests: `ReviewActions` + `ReviewPage`; no skipped tests
- [ ] Playwright E2E: approve/reject/needs_changes happy paths at 390x844 / 768x1024 / 1440x900; console/network clean; no secrets in browser
- [ ] Full backend/frontend/E2E suites pass; lint, build, type check pass; governance passes
- [ ] Test-seeding route never registered outside `testing`; no storage internals exposed

## Remaining Gates (Not Claimed Complete)

- Independent Tester review: runs all backend/frontend/E2E tests, operates the running app, inspects screenshots at 3 viewports, checks console/network/API, validates accessibility, security, ownership, CSRF, secret exposure.
- Orchestrator: commits, pushes, creates PR, monitors CI, merges after Tester approval and CI green.
- Issue closure after verified merge.

---

SPEC_READY