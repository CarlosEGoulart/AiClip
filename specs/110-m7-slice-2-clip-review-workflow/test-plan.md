# Test Plan: Clip Review Status Workflow — Issue #110

## Authority, Preconditions, and Evidence Rules

Active issue: [Issue #110](https://github.com/CarlosEGoulart/AiClip/issues/110). Verify `spec.md` acceptance criteria AC-01 through AC-10 and the ordered work in `plan.md`.

Before Builder corrections: Orchestrator confirms the planning/governance gate. Builder ensures Docker infrastructure is running (`docker compose up -d postgres` from repository root), database migrated, and baseline tests pass.

Initial database connectivity/PDO failures, unbound Laravel TestCase/facades, missing Faker, wrong CSRF helper, malformed fixtures, duplicate registration users, syntax errors, and no-test discovery are setup failures, not RED. Verify and fix these before meaningful behavior assertions. `X-XSRF-TOKEN` uses the wire cookie convention, not a decrypted token from `csrfToken(...)`. Backend storage assertions query `DerivedAsset.storage_key`, not public ID. Retain enforced CSRF in the SPA test base.

Historical working-tree evidence does not establish preimplementation RED for this new feature. For each behavior, write/strengthen a behavioral test, actually execute the relevant assertion failure, then implement GREEN and rerun after refactoring. Tests-written, connection errors, zero executed tests, and skips are never proof of success.

## 1. Backend Status Transitions (AC-01, AC-02)

Use Pest feature tests under `apps/api/tests/Feature/ClipReview/ClipReviewTransitionTest.php` (new). SPA API tests use `SpaTestCase` with real session/cookie/CSRF semantics. Base binding: `uses(SpaTestCase::class, RefreshDatabase::class);` (file-scoped, matching passing-suite convention; see Slice 1 plan Phase 6).

### 1.1 Valid one-way transitions

| Scenario | Expected |
|----------|----------|
| Owner, `pending → approved` | 200, `data.status === 'approved'`, job dispatched, `refresh()->status === APPROVED` |
| Owner, `pending → rejected` | 200, `data.status === 'rejected'` |
| Owner, `pending → needs_changes` | 200, `data.status === 'needs_changes'` |
| Transition observable in PATCH response | `data.status` in response equals the requested target status |

### 1.2 Invalid / forbidden transitions (422)

| Scenario | Expected |
|----------|----------|
| Owner, `pending → pending` (revert) | 422 validation error |
| Owner, `pending → foo` (invalid) | 422 validation error |
| Owner, `approved → rejected` (terminal) | 422 (no further transition) |
| Owner, `rejected → approved` (terminal) | 422 |
| Owner, `needs_changes → approved` (terminal) | 422 |

### 1.3 Ownership, auth, CSRF, rate limit

| Scenario | Expected |
|----------|----------|
| Non-owner | 404 |
| Non-existent clip review | 404 |
| Missing CSRF | 419 |
| Guest (valid CSRF, no session) | 401 |
| Expired session | 401 |
| Rate limit (30/min) | 429 with `Retry-After` on 31st request |

### Commands

```bash
cd apps/api && php artisan test tests/Feature/ClipReview/ClipReviewTransitionTest.php --compact
```

## 2. Test-Seeding Route (AC-04)

Use Pest feature tests under `apps/api/tests/Feature/ClipReview/ClipReviewSeedTest.php` (new). The route is registered only in the `testing` environment; it aborts 404 elsewhere.

| Scenario | Expected |
|----------|----------|
| Authenticated user, own project | 200, `ClipReviewResource` with `status === 'pending'`, `video_url` contains URI path `clip-reviews/{id}/video` + `signature` + `expires`; DB has pending `ClipReview` for rendered `DerivedAsset` |
| After seed: storage object exists | A real media object exists on the configured media disk at `DerivedAsset.storage_key` (`Storage::disk(configured disk)->exists($derivedAsset->storage_key)` is true); content is the minimal fixture, non-empty |
| After seed: `video_url` resolves | Fetching/inspecting the signed `video_url` (or the storage key it points to) yields an existing object — no 404/403; resolution succeeds (HTTP 2xx when fetched) so the browser player can load it |
| Non-owner project | 404 |
| Non-existent project | 404 |
| Guest | 401 |
| Missing CSRF | 419 |
| Outside `testing` (`APP_ENV=local`) | 404 (route not available) |
| No storage internals in response | No `storage_disk`, `storage_key`, `bucket` fields |

### Commands

```bash
cd apps/api && php artisan test tests/Feature/ClipReview/ClipReviewSeedTest.php --compact
```

## 3. Backend Queue Job (AC-03)

Use Pest tests under `apps/api/tests/Feature/ClipReview/ProcessClipReviewJobTest.php` (existing; extend if needed).

| Scenario | Expected |
|----------|----------|
| Action: approve | `ClipReview.status = approved`; `DerivedAsset.render_status` unchanged (completed); `ClipReviewCompleted` event dispatched |
| Action: reject | `ClipReview.status = rejected`; event dispatched |
| Action: needs_changes | `ClipReview.status = needs_changes`; event dispatched |
| Idempotency: second dispatch same action | No-op (status already changed); no duplicate event |
| Non-pending review | Job returns early, no state change, no event |
| Ownership re-validation fails | Job returns early, no state change, logs warning |
| Invalid action string | Throws `InvalidArgumentException` |
| Queue synchronous in test/e2e | Transition observable immediately after `dispatch()` (`QUEUE_CONNECTION=sync`) |

### Commands

```bash
cd apps/api && php artisan test tests/Feature/ClipReview/ProcessClipReviewJobTest.php --compact
```

## 4. Frontend Component Tests — Vitest (AC-05, AC-06)

Use Vitest + React Testing Library under `apps/web/src/features/clip-review/components/*.test.tsx`.

### TC-FE-06: ReviewActions handlers

| Test Case | Assertion |
|-----------|-----------|
| Renders three buttons: Approve, Reject, Needs Changes | Three buttons with correct labels/icons |
| Click Approve opens confirmation dialog | Dialog opens, `role="alertdialog"`, `aria-modal="true"`, focus trap |
| Confirm Approve calls `onStatusChange('approved')` | Mock called with `'approved'` |
| Confirm Reject / Needs Changes calls `onStatusChange` | Mock called with `'rejected'` / `'needs_changes'` |
| Dialog notes feedback required for Reject/Needs Changes | Text present in dialog |
| Cancel closes dialog without API call | Mock not called |
| Buttons disabled during `disabled`/`loading` | `disabled={true}`, spinner visible |
| Success announces via `aria-live="polite"` | Live region updated after confirm |
| Error announces via `aria-live="polite"` | Error message in live region |
| Button already applied (e.g. Approve when `currentStatus==='approved'`) | Disabled, `aria-pressed="true"` |
| Keyboard: Enter/Space activates; Escape closes | `fireEvent.keyDown` triggers click / closes dialog |
| 44px touch targets; visible focus | Accessibility fundamentals |

### TC-FE-07: ReviewPage status reflection

| Test Case | Assertion |
|-----------|-----------|
| Renders without a Router provider (production topology) | Mounts without crashing |
| After successful `updateStatusMutation`, selected clip badge updates | Badge color/text reflects new status (green/red/orange) |
| `refetch()` called after successful status change | Review list reloaded |
| `N`/`P` navigation updates selection and `clipReviewId` URL | `window.location.search` contains `?clipReviewId=X` |
| Feedback form shows for `rejected`/`needs_changes` only | Conditional render verified |
| Feedback form hidden for `approved`/`pending` | Not rendered |
| Deep-link `clipReviewId` selects that clip on mount | Correct clip selected |

### Commands

```bash
cd apps/web && npm test -- --run src/features/clip-review/components
```

## 5. Frontend Lint, Build, and Type Check

```bash
cd apps/web && npm run lint && npm run build
```
Expect: Zero lint errors (including TS6133), TypeScript compiles, Vite production build succeeds.

## 6. Playwright E2E Tests (AC-09)

Run via `npm run test:e2e` from `apps/web`. Playwright config starts Laravel at 127.0.0.1:8000, Vite at 127.0.0.1:5173, `reuseExistingServer: false`. Laravel readiness at `/api/v1/health`. Real PostgreSQL required. MinIO NOT provisioned (`FILESYSTEM_DISK=local`); no Python worker/FFmpeg; no queue worker by default — Slice 2 requires the e2e `php artisan serve` to process jobs synchronously (`QUEUE_CONNECTION=sync` or equivalent) so the PATCH status transition is observable. Authoritative venue: CI e2e job.

The spec file lives at `apps/web/e2e/clip-review.spec.ts` (inside `testDir: './e2e'`). A spec at `apps/web/tests/e2e/` is never discovered and is forbidden.

### Reachable UI and Verified Topology

All scenarios run against the real embedded UI: `App → AuthenticatedShell → project list → Open {project} → ProjectMediaSection → "Review Clips" tab → embedded ReviewPage`. There is no `/projects/{id}/review` route; navigating to one is forbidden.

Data setup follows the established pattern in `apps/web/e2e/media.spec.ts`: register a unique user via the UI (no magic seed users — `test@example.com` does not exist), create the project via the UI, capture the project id from the real 201 response.

**E2E fixture setup (new for Slice 2):** After creating the project, seed a pending review with a real API request:
`POST /api/v1/test/clip-reviews/seed` with `{ project_id }` (real request, no interception; authenticated via the session established by UI registration/login). This creates a MediaAsset + rendered DerivedAsset + pending ClipReview so the review UI has data to act on, **and persists a real minimal media object at `DerivedAsset.storage_key` so the signed `video_url` resolves (HTTP 2xx) and the `<video>` element loads without a storage 404**. No API interception of the review flow itself; every `GET /api/v1/projects/{id}/clip-reviews` and `PATCH /api/v1/clip-reviews/{id}/status` runs against the real Laravel server and must return 2xx.

**Console/network cleanliness is mandatory for TC-E2E-05 through TC-E2E-08** (and TC-E2E-09/10): no console errors and no 4xx/5xx anywhere, including media playback requests — a `video_url` request 404 (missing backing storage object) is a failure, not an acceptable warning. This applies at all three viewports.

### TC-E2E-05: Approve happy path

1. Register unique user via UI; create project via UI (capture id from 201)
2. Seed a pending review via `POST /api/v1/test/clip-reviews/seed` (real request); assert 200 and `status === 'pending'`
3. Click `Open {project name}`; click "Review Clips" tab; assert `GET /api/v1/projects/{id}/clip-reviews` → 200 with standard pagination envelope (`body.data.data` non-empty; `meta.total === 1`)
4. Select the clip in the "Clips to Review" list; verify VideoPlayer mounts and status badge is gray (`Pending`)
5. Click Approve → confirmation dialog opens → click Confirm
6. Verify PATCH `/api/v1/clip-reviews/{id}/status` → 200 with `status === 'approved'`
7. Verify status badge is green (`Approved`); review-list badge green
8. Verify `clipReviewId` deep-link param present in URL
9. Clean up: delete project via UI

### TC-E2E-06: Reject happy path (with feedback)

1. Same setup as TC-E2E-05 (fresh user/project/seed)
2. Select the clip; click Reject → confirmation dialog opens
3. Dialog notes feedback required (min 10 characters); click Confirm without feedback → feedback form appears / submission blocked (min 10 chars)
4. Enter feedback (≥10 chars) → Confirm
5. Verify PATCH → 200 with `status === 'rejected'`; feedback stored
6. Verify status badge is red (`Rejected`); feedback form visible for rejected
7. Clean up: delete project via UI

### TC-E2E-07: Needs Changes happy path (with feedback)

1. Same setup as TC-E2E-05 (fresh user/project/seed)
2. Select the clip; click Needs Changes → confirmation dialog opens → Confirm
3. Enter feedback (≥10 chars) → submit
4. Verify PATCH → 200 with `status === 'needs_changes'`; feedback stored
5. Verify status badge is orange (`Needs Changes`); feedback form visible
6. Clean up: delete project via UI

### TC-E2E-08: Terminal state — no further transition

1. Same setup; approve the clip (TC-E2E-05 steps 1–7)
2. With status `approved`, click Reject and Needs Changes: buttons are disabled (`aria-pressed="true"` / disabled); no further PATCH occurs; status remains `approved`
3. Clean up: delete project via UI

### TC-E2E-09: Responsive viewports — full workflow

Runs on each configured Playwright viewport project (mobile 390x844, tablet 768x1024, desktop 1440x900 — supplied by config; viewport sizes never set manually). At each viewport:
- Seed a pending review; open review tab; select clip; run the approve flow (TC-E2E-05)
- No horizontal overflow
- Header, badges, buttons, feedback form readable; no clipping
- Interactive controls touch-target sized (≥44px) with visible focus
- Layout reflows without overlap (single column < 1024px; side-by-side ≥ 1024px)
- Network: `GET /api/v1/projects/{id}/clip-reviews` → 200 standard pagination envelope; `PATCH /api/v1/clip-reviews/{id}/status` → 200

### TC-E2E-10: Console and network cleanliness (full workflow)

During TC-E2E-05 through TC-E2E-09:
- **Browser Console**: no errors, no warnings, no uncaught exceptions
- **Network**: `GET /sanctum/csf-cookie` → 204; review requests → 200; PATCH → 200; signed `video_url` media request → 2xx (real backing object exists); no unexpected 4xx/5xx anywhere (except the ratified anonymous `GET /api/v1/auth/me` 401 session probe)
- **API Responses**: `data.data` (review items), `data.links`, `data.meta` only; no `storage_disk`, `storage_key`, internal IDs, bucket names
- **No secrets in browser**: no tokens/credentials in request bodies, console output, or storage
- **Browser storage**: localStorage/sessionStorage contain no tokens or credentials (feedback draft cleared on submit)

### Commands

```bash
# Discovery: the spec must be selected by Playwright (non-empty)
cd apps/web && npx playwright test e2e/clip-review.spec.ts --list

# Full run across the three viewport projects
cd apps/web && npm run test:e2e
```

## 7. Cross-Cutting: Security, Accessibility, Responsive

### Security Matrix

| Check | Method | Pass Criteria |
|-------|--------|---------------|
| IDOR prevention | Access other user's clip review via API | 404 for all endpoints |
| CSRF enforcement | Mutate without `X-XSRF-TOKEN` | 419 |
| Rate limiting | Exceed 30/min on update endpoint | 429 with headers |
| One-way transition | `pending → pending`, terminal → any | 422 |
| Test-seeding route outside testing | `APP_ENV=local` POST to seed route | 404 |
| Test-seeding ownership | Seed another user's project | 404 |
| No internal fields in API | Inspect all API responses | No `storage_disk`, `storage_key`, `bucket`, internal IDs |
| No secrets in browser | Inspect Network, Console, React DevTools | No tokens, keys, credentials |
| Queue job re-validates ownership | Job payload with wrong user_id | Job returns early, no state change |

### Accessibility Matrix (WCAG 2.1 AA)

| Check | Method | Pass Criteria |
|-------|--------|---------------|
| Keyboard navigation | Tab through all interactive elements | All reachable, focus visible, logical order |
| Confirmation dialog | `role="alertdialog"`, `aria-modal="true"` | Focus trap; Escape closes; Enter/Space confirms |
| Live regions | Trigger status changes | `aria-live="polite"` announces success/error |
| Focus indicators | Tab through UI | Visible outline/ring on all focusable |
| Error announcements | Submit invalid feedback / short feedback | `aria-live` announces error |
| Touch targets | All buttons ≥ 44px | Pass at all three viewports |
| Color contrast | Badge colors (green/red/orange/gray) | 4.5:1 minimum for text |

### Responsive Matrix

| Viewport | Layout Checks |
|----------|---------------|
| 390x844 | Single column, VideoPlayer full-width, panel below, touch targets ≥44px, no horizontal scroll |
| 768x1024 | Stacked or two-column, VideoPlayer max ~60% width, panel readable |
| 1440x900 | Side-by-side, VideoPlayer ~60%, panel ~40%, comfortable line lengths |

## 8. Required Commands, Discovery, and CI

| Working Directory | Command / Required Result |
|---|---|
| Repository root | `docker compose up -d postgres` — verify health |
| `apps/api` | `php artisan migrate:fresh --seed` — clean database |
| `apps/api` | `php artisan test --exclude-group=real-integration` — fast feedback; zero failures |
| `apps/api` | `php artisan test tests/Feature/ClipReview` — all ClipReview suites pass (transitions, seed, job, API, ownership, rate limit, video URL) |
| `apps/api` | `vendor/bin/pint --dirty --format agent` — formatting |
| `apps/web` | `npm test` — Vitest suite pass |
| `apps/web` | `npm run lint` — lint pass |
| `apps/web` | `npm run build` — TypeScript + Vite build pass |
| `apps/web` | `npx playwright test e2e/clip-review.spec.ts --list` — non-empty discovery |
| `apps/web` | `npm run test:e2e` — Playwright all viewport projects; TC-E2E-05..10 pass at 3 viewports; console/network clean |
| Repository root | `python -m unittest discover -s tests/governance -p 'test_*.py' -v` — governance pass |

Verify installed runner support for fail-closed options during setup. The standalone E2E run must explicitly show the clip-review spec's tests selected (positive counts) and all passing across the three viewport projects.

## 9. Test Case to Acceptance Criteria Mapping

| Test Case | AC Covered |
|-----------|------------|
| TC-BE-01: Valid transitions | AC-01, AC-02 |
| TC-BE-02: Invalid/forbidden transitions | AC-01 |
| TC-BE-03: Ownership/auth/CSRF/rate limit | AC-02 |
| TC-BE-04: Test-seeding route | AC-04 |
| TC-BE-05: Queue job | AC-03 |
| TC-FE-06: ReviewActions handlers | AC-05 |
| TC-FE-07: ReviewPage status reflection | AC-06 |
| TC-E2E-05: Approve happy path | AC-09 |
| TC-E2E-06: Reject happy path | AC-09 |
| TC-E2E-07: Needs Changes happy path | AC-09 |
| TC-E2E-08: Terminal state no further transition | AC-01, AC-09 |
| TC-E2E-09: Responsive viewports — full workflow | AC-09 |
| TC-E2E-10: Console and network cleanliness | AC-09 |
| Cross-cutting Security | AC-01, AC-02 |
| Cross-cutting Accessibility | AC-05, AC-06 |
| Full backend/frontend/E2E suites | AC-07, AC-08, AC-09, AC-10 |

---

SPEC_READY