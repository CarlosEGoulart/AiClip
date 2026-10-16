# Test Plan: Clip Review Experience Foundation — Issue #106

## Authority, Preconditions, and Evidence Rules

Active issue: [Issue #106](https://github.com/CarlosEGoulart/AiClip/issues/106). Branch: `@carlosegoulart/106/feat/clip-review-experience`. Verify `spec.md` acceptance criteria AC-01 through AC-12 and the ordered work in `plan.md`.

Before Builder corrections: Orchestrator confirms the planning/governance gate. Builder ensures Docker infrastructure is running (`docker compose up -d postgres minio minio-init` from repository root), database migrated, and baseline tests pass.

Initial database connectivity/PDO failures, unbound Laravel TestCase/facades, missing Faker, wrong CSRF helper, malformed fixtures, duplicate registration users, syntax errors, and no-test discovery are setup failures, not RED. Verify and fix these before meaningful behavior assertions. `X-XSRF-TOKEN` uses the wire cookie convention, not a decrypted token from `csrfToken(...)`. Backend storage assertions query `DerivedAsset.storage_key`, not public ID. Retain enforced CSRF in the SPA test base.

Historical working-tree evidence does not establish preimplementation RED for this new feature. For each behavior, write/strengthen a behavioral test, actually execute the relevant assertion failure, then implement GREEN and rerun after refactoring. Tests-written, connection errors, zero executed tests, and skips are never proof of success.

## 1. Backend Model, Migration, Scopes, and Enum (AC-01)

Use Pest tests under `apps/api/tests/Unit/Models/ClipReviewTest.php` and `apps/api/tests/Unit/Enums/ClipReviewStatusTest.php`. Tests using Eloquent require `Tests\TestCase`; database tests use isolated PostgreSQL data.

| Area | Required Assertions |
|---|---|
| Schema | `clip_reviews` table exists with columns: `id` (bigint PK), `derived_asset_id` (FK cascade), `reviewer_id` (FK users cascade), `status` (enum pending/approved/rejected/needs_changes default pending), `feedback` (text nullable), `created_at`, `updated_at`. Unique index on `[derived_asset_id, reviewer_id]`. Index on `[reviewer_id, status]`. |
| Factory | Creates valid ClipReview with DerivedAsset → MediaAsset → Project → User. All required fields populated. |
| Relationships | `clipReview->derivedAsset` returns DerivedAsset. `clipReview->reviewer` returns User. Inverse: `derivedAsset->clipReview` returns ClipReview. |
| Scopes | `ClipReview::forProject($projectId)` returns only reviews for that project (joins through derived_asset → media_asset → project). `ClipReview::pending()` returns only status=pending. Cross-project isolation verified. |
| Enum Cast | `clipReview->status` returns `ClipReviewStatus` enum instance. `ClipReviewStatus::PENDING->value === 'pending'`. Invalid status throws on model create. |
| Status Transitions | Model allows any enum value on create. Controller/Job enforce `pending → approved/rejected/needs_changes` only (not back to pending). |

### Commands

```bash
cd apps/api && php artisan test tests/Unit/Models/ClipReviewTest.php tests/Unit/Enums/ClipReviewStatusTest.php --compact
```

## 2. Backend API Endpoints (AC-02, AC-04)

Use Pest feature tests under `apps/api/tests/Feature/ClipReview/`. SPA API tests use `SpaTestCase` with real session/cookie/CSRF semantics.

Guest semantics (ratified): guest GET cases use an empty cookie jar (CSRF does not apply to reads). Guest PATCH/POST cases must call `$this->csrfCookies()` — the real `/sanctum/csrf-cookie` handshake with no login — so the write is CSRF-valid but unauthenticated. CSRF validation precedes `auth:sanctum` in the stateful pipeline, so a tokenless guest write returns 419 before authentication is evaluated and can never exercise the required 401; the dedicated missing-CSRF cases own the 419 contract. Guest write cases assert 401 with CSRF valid.

### 2.1 List Clip Reviews — `GET /api/v1/projects/{project}/clip-reviews`

| Scenario | Expected |
|---|---|
| Owner with reviews | 200, standard pagination envelope: `data.data` = reviews array (newest `created_at` first) plus `links`/`meta` |
| Owner no reviews | 200, pagination envelope with empty collection: `data.data === []` AND `meta.total === 0` (see §6 correction note, 2026-10-16) |
| Non-owner project | 404 (not 403) |
| Non-existent project | 404 |
| Guest (no auth) | 401 |
| Expired session | 401 |
| Rate limit (60/min) | 429 with `Retry-After` header on 61st request |

### 2.2 Show Clip Review — `GET /api/v1/clip-reviews/{clipReview}`

| Scenario | Expected |
|---|---|
| Owner | 200, `{"data": {...}}` with all fields including `video_url` (signed) |
| Non-owner | 404 |
| Non-existent | 404 |
| Guest | 401 |
| `video_url` format | Signed URL from named route `clip-review.video`: the URL string contains the route URI path `clip-reviews/{id}/video` plus `signature` and `expires` query parameters (expiration ~1 hour). Assert the URI path + `signature` + `expires`; Laravel signed URLs embed the route URI, never the dot-separated route name, so a literal `toContain('clip-review.video')` is unsatisfiable |

### 2.3 Update Status — `PATCH /api/v1/clip-reviews/{clipReview}/status`

| Scenario | Expected |
|---|---|
| Owner, valid status (approved) | 200, job dispatched, `ClipReview.status` updated to approved |
| Owner, valid status (rejected) | 200, job dispatched, status rejected |
| Owner, valid status (needs_changes) | 200, job dispatched, status needs_changes |
| Owner, invalid status (pending) | 422 validation error |
| Owner, invalid status (foo) | 422 validation error |
| Non-owner | 404 |
| Non-existent | 404 |
| Missing CSRF | 419 |
| Guest | 401 |
| Rate limit (30/min) | 429 on 31st request |

### 2.4 Submit Feedback — `POST /api/v1/clip-reviews/{clipReview}/feedback`

| Scenario | Expected |
|---|---|
| Owner, feedback 10-1000 chars | 200, feedback stored |
| Owner, feedback <10 chars (when rejected/needs_changes) | 422 validation error |
| Owner, feedback >1000 chars | 422 validation error |
| Owner, feedback when approved | 200 (feedback optional for approved) |
| Non-owner | 404 |
| Missing CSRF | 419 |
| Guest | 401 |

### 2.5 Signed Video URL — `GET /api/v1/clip-reviews/{clipReview}/video`

| Scenario | Expected |
|---|---|
| Owner | 302 redirect to MinIO presigned URL (or streamed response) |
| Guest (no auth, unsigned request) | 401 (dual-gate contract: unsigned requests fall through to ownership authorization; guest is rejected before any storage information) |
| Non-owner | 404 |
| Expired signature (>1 hour) | 403 |
| Tampered signature | 403 |
| Non-existent | 404 |

### Commands

```bash
cd apps/api && php artisan test tests/Feature/ClipReview/ClipReviewApiTest.php tests/Feature/ClipReview/ClipReviewOwnershipTest.php tests/Feature/ClipReview/ClipReviewRateLimitTest.php tests/Feature/ClipReview/ClipReviewVideoUrlTest.php --compact
```

## 3. Backend Queue Job (AC-03)

Use Pest tests under `apps/api/tests/Feature/ClipReview/ProcessClipReviewJobTest.php`.

| Scenario | Expected |
|---|---|
| Action: approve | ClipReview.status = approved; DerivedAsset.render_status unchanged (completed); ClipReviewCompleted event dispatched |
| Action: reject | ClipReview.status = rejected; DerivedAsset.render_status unchanged; event dispatched |
| Action: needs_changes | ClipReview.status = needs_changes; DerivedAsset.render_status unchanged; event dispatched |
| Idempotency: dispatch twice for same action | Second dispatch no-op (status already changed); no duplicate event |
| Non-pending review (already approved) | Job returns early, no state change, no event |
| Ownership re-validation fails | Job returns early, no state change, logs warning |
| Invalid action string | Throws InvalidArgumentException |
| Database transaction rollback on error | No partial state; exception propagated |

### Commands

```bash
cd apps/api && php artisan test tests/Feature/ClipReview/ProcessClipReviewJobTest.php --compact
```

## Backend ClipReview Harness Repair (AC-11) — TC-BE-04

CI on PR #107 fails the backend `tests` job: all 71 failures are `tests/Feature/ClipReview/` suites failing in `beforeEach` with `BindingResolutionException: Target class [config] does not exist` (unbooted container; diagnosis and fix steps in plan Phase 6). Verification of the repair:

| Check | Expected |
|---|---|
| File-scoped base binding | Each of the seven `tests/Feature/ClipReview/*` files declares `uses(<Base>::class, RefreshDatabase::class);` matching passing-suite conventions (`SpaTestCase` for the four SPA/API files; `TestCase` for the three job/integration files); no `->in('Feature/ClipReview')` declarations remain |
| Container boot | `php artisan test tests/Feature/ClipReview` completes with no `BindingResolutionException`; every suite reaches its behavioral assertions |
| Behavioral execution | All ClipReview assertions pass; any behavioral failure surfaced by the repair is resolved through normal TDD RED→GREEN, never by weakening or skipping assertions |
| No regressions | Full `php artisan test` passes; the 1424 previously passing backend tests remain green |

### Commands

```bash
cd apps/api && php artisan test tests/Feature/ClipReview --compact
cd apps/api && php artisan test --compact
```

Per the evidence rules above, container-bootstrap/setup failures of this kind are not valid RED; only post-repair assertion results count as behavioral evidence.

## 4. Frontend Component Tests — Vitest (AC-05 through AC-09)

Use Vitest + React Testing Library under `apps/web/src/features/clip-review/components/*.test.tsx`.

### TC-FE-01: VideoPlayer Component

| Test Case | Assertion |
|---|---|
| Renders video element with `src` prop | `<video>` present with correct `src` |
| Play/Pause button toggles playback | Click → `video.play()` called; click again → `pause()` |
| Space key toggles play/pause | `fireEvent.keyDown(video, {key: ' '})` toggles |
| ArrowLeft/ArrowRight seeks ±5s | `fireEvent.keyDown` → `video.currentTime` changes |
| ArrowUp/ArrowDown adjusts volume | Volume changes 0.1 per press, clamped 0-1 |
| M key toggles mute | Mute state toggles |
| F key toggles fullscreen | `requestFullscreen`/`exitFullscreen` called |
| Number keys 0-9 seek to 0%-90% | `currentTime = duration * (key/10)` |
| Loading state shows spinner | While `video.readyState < 2`, spinner visible |
| Error state shows retry button | On `error` event, retry button appears, click reloads |
| Accessibility: ARIA labels on all controls | `aria-label` present on play, mute, fullscreen, seek |
| Accessibility: visible focus on all buttons | `:focus-visible` styles verified via testing-library |
| Responsive: 390px, 768px, 1440px | Component renders without overflow at all widths |
| No console errors | `console.error` not called during test |

### TC-FE-02: ReviewActions Component

| Test Case | Assertion |
|---|---|
| Renders three buttons: Approve, Reject, Needs Changes | Three buttons with correct labels/icons |
| Click Approve opens confirmation dialog | Dialog opens with focus trap, ARIA modal |
| Confirm Approve calls `onStatusChange('approved')` | Mock function called with 'approved' |
| Confirm Reject opens feedback requirement notice | Dialog mentions feedback required |
| Confirm Needs Changes opens feedback requirement notice | Dialog mentions feedback required |
| Cancel dialog closes without API call | Mock not called |
| Disabled during mutation | Buttons `disabled={true}`, spinner visible |
| Success announces via aria-live | `aria-live="polite"` region updated |
| Error announces via aria-live | Error message in live region |
| Keyboard: Enter/Space activates button | `fireEvent.keyDown` triggers click |
| Keyboard: Escape closes dialog | `fireEvent.keyDown({key: 'Escape'})` closes |

### TC-FE-03: FeedbackForm Component

| Test Case | Assertion |
|---|---|
| Renders textarea with label | `<label>` associated with `<textarea>` |
| Character counter shows 0/1000 initially | Counter text present |
| Typing updates counter | Counter increments |
| Warning at 800 chars (yellow) | Counter color/style changes |
| Error at >1000 chars (red, submit disabled) | Counter red, submit `disabled` |
| Min 10 chars required for reject/needs_changes | Submit disabled until ≥10 chars |
| No min for approved | Submit enabled immediately |
| Draft saved to localStorage | `localStorage.setItem('clip-review-feedback-{id}', value)` on change |
| Draft loaded on mount | `localStorage.getItem` called, textarea pre-filled |
| Draft cleared on submit | `localStorage.removeItem` called |
| Accessible error announcement | `aria-live="polite"` for validation errors |
| Auto-expands textarea | `rows` increases with content |

### TC-FE-04: ReviewList Component

| Test Case | Assertion |
|---|---|
| Renders grid of clip items | Items in CSS grid, responsive columns |
| Each item shows thumbnail, duration, candidate index, status badge | All elements present |
| Status badge colors: pending=gray, approved=green, rejected=red, needs_changes=orange | Visual verification via class/styles |
| Click "Review" calls `onReview(clipReview)` | Mock called with correct item |
| Empty state shows illustration + message + upload link | Elements present when array empty |
| Loading state shows skeleton cards | Skeleton elements match item count |
| Keyboard: Tab navigates items | Focus moves through review buttons |
| Responsive: single column <768px, grid ≥768px | Layout verified at both widths |

### TC-FE-05: ReviewPage Component

The test must render `ReviewPage` WITHOUT any Router provider — the production tree has no Router, and a `MemoryRouter` wrapper masks the router-context crash fixed in plan Phase 7.5. URL assertions use `window.location`/`window.history` (jsdom). The selection, URL, and N/P rows are load-bearing for AC-09 because E2E cannot reach populated review states (see §6).

| Test Case | Assertion |
|---|---|
| Mounts without a Router provider (production topology) | Renders without crashing; no react-router context required |
| Header shows project name, back button, clip counter | Text content correct |
| Loads clip reviews via hook | `useClipReviews` called with projectId |
| Selects first pending clip on load | VideoPlayer shows first pending clip |
| VideoPlayer receives correct `video_url` | Prop matches selected clip |
| ReviewActions shows current clip status | Status prop passed correctly |
| FeedbackForm shows for reject/needs_changes | Conditional render verified |
| N key navigates to next clip | `fireEvent.keyDown({key: 'n'})` updates selection |
| P key navigates to previous clip | `fireEvent.keyDown({key: 'p'})` updates selection |
| N/P disabled at boundaries | Buttons disabled or no-op at first/last |
| URL updates with `clipReviewId` param | `window.location.search` contains `?clipReviewId=X` |
| Deep link to clipReviewId selects that clip | Mount with param → correct clip selected |
| Back button calls `onBack` | Mock called |

### Commands

```bash
cd apps/web && npm test -- --run src/features/clip-review/components
```

## 5. Frontend Lint, Build, and Type Check

```bash
cd apps/web && npm run lint && npm run build
```
Expect: Zero lint errors (including TS6133), TypeScript compiles, Vite production build succeeds.

## 6. Playwright E2E Tests (AC-09, AC-10, AC-11)

Run via `npm run test:e2e` from `apps/web`. Playwright config starts Laravel at 127.0.0.1:8000, Vite at 127.0.0.1:5173, `reuseExistingServer: false`. Laravel readiness at `/api/v1/health`. Real PostgreSQL is required. MinIO is NOT provisioned in the e2e environment (`FILESYSTEM_DISK=local`), and the e2e job has no Python worker/FFmpeg, so the render pipeline cannot produce `DerivedAsset` rows — the reachable clip-review UI state is the review section's empty state (see spec "Known Limitations and Coverage Boundary"). The spec file must live at `apps/web/e2e/clip-review.spec.ts` (inside Playwright's `testDir: './e2e'`); a spec at `apps/web/tests/e2e/` is never discovered and is forbidden.

### Reachable UI and Verified Topology

All scenarios run against the real embedded UI: `App` → `AuthenticatedShell` → project list → `Open {project name}` button → `ProjectMediaSection` → "Review Clips" tab → embedded `ReviewPage`. There is no `/projects/{id}/review` route; navigating to one is forbidden in these scenarios. Data setup follows the established pattern in `apps/web/e2e/media.spec.ts`: register a unique user via the UI (no magic seed users — `test@example.com` does not exist), create the project via the UI, capture the project id from the 201 response. No API interception in these scenarios: every request, including `GET /api/v1/projects/{id}/clip-reviews`, runs against the real Laravel server and must return 2xx.

Where full workflow coverage lives (ratified): approve/reject/needs_changes API behavior — TC-BE-02/TC-BE-03 backend feature tests; real presigned video redirect — §7.1 real-integration group executed with real MinIO in the CI backend `tests` job; component-level workflows (player shortcuts, confirmations, feedback validation, selection, URL sync) — TC-FE-01 through TC-FE-05. Full-workflow E2E is unreachable in the current e2e environment (recorded limitation).

### Correction (2026-10-16): clip-reviews network envelope — CI ground truth, not a weakening

The first CI e2e run of this spec (PR #107) executed the nine clip-review tests; all nine failed on one shared stale assertion — the expected body `toEqual({ "data": [] })` for `GET /api/v1/projects/{id}/clip-reviews` on an empty project (assertion sites in TC-E2E-01, TC-E2E-02, and TC-E2E-03) — while the 75 pre-existing e2e tests passed. The CI output is ground truth for the real contract: the endpoint returns the `ClipReviewCollection` paginated resource — the standard Laravel pagination envelope wrapped in `data`:

```json
{
  "data": {
    "data": [],
    "links": { "first": "...?page=1", "last": "...?page=1", "next": null, "prev": null },
    "meta": { "current_page": 1, "from": null, "last_page": 1, "path": "...", "per_page": 20, "to": null, "total": 0 }
  }
}
```

This is the same contract verified by the green CI backend `tests` job (`ClipReviewApiTest`) and consumed by the frontend `useClipReviews` hook. The corrected rows fix only the envelope shape — this is NOT a weakening: empty-collection semantics (no reviews exist; none render) and the no-leak intent (no `storage_disk`, `storage_key`, internal IDs, or bucket names anywhere in the response) are preserved. The review collection is empty when `body.data.data` is an empty array AND `body.data.meta.total === 0`. Builder updates the three assertion sites in `apps/web/e2e/clip-review.spec.ts` to this contract; no backend/production change is warranted — the paginated envelope is the verified contract of the green backend job and of the frontend hooks, and reshaping the API now would be unjustified scope.

### TC-E2E-01: Review Section Discoverability and Empty State

1. Register a unique user via the UI; create a project via the UI (unique name; capture id from the 201 response)
2. Click `Open {project name}`; verify `ProjectMediaSection` renders with the "Review Clips" tab
3. Click the "Review Clips" tab
4. Verify the embedded review section renders: header shows the project name and "No clips"; main panel shows "Select a clip to review"; sidebar heading "Clips to Review" renders with an empty list
5. Verify the network: `GET /sanctum/csrf-cookie` → 204 and `GET /api/v1/projects/{id}/clip-reviews` → 200 with the standard pagination envelope (see correction note above): `body.data.data` is an empty array AND `body.data.meta.total === 0`; `meta.last_page === 1`; `links.next` and `links.prev` are both null
6. Verify no video player, review action buttons, or feedback form are rendered
7. Clean up: delete the project via the UI delete flow (established pattern in `media.spec.ts`)

### TC-E2E-02: Empty Section Non-Interactivity and Navigation

1. Same setup as TC-E2E-01 (fresh user/project), review tab active
2. Verify no review action buttons (Approve/Reject/Needs Changes) exist in the DOM
3. Press `N` and `P` (clip navigation keys): no URL change (no `clipReviewId` parameter appears), no error, no state change
4. Switch to the "Media" tab and back to "Review Clips": the review section re-renders the same empty state; the clip-reviews request succeeds again with the same pagination envelope (empty `data.data`, `meta.total === 0`)
5. Click the review section's "Back to project" control: returns to the projects list

### TC-E2E-03: Responsive Viewports — Real Review Section

Runs on each configured Playwright viewport project (mobile 390x844, tablet 768x1024, desktop 1440x900 — supplied by the config's `projects`; do not set viewport sizes manually). At each viewport:

- No horizontal overflow
- Header, "Clips to Review" sidebar, and empty-state text readable; no clipping
- Interactive controls (tabs, back, logout) touch-target sized (≥44px) with visible focus
- Layout reflows without overlap at all three widths
- Network (asserted at this site in the implemented spec): `GET /api/v1/projects/{id}/clip-reviews` → 200 with the standard pagination envelope; empty collection: `body.data.data` is an empty array AND `body.data.meta.total === 0`

### TC-E2E-04: Console and Network Cleanliness

During TC-E2E-01 through TC-E2E-03:

- **Browser Console**: no errors, no warnings, no uncaught exceptions (the empty review section must mount cleanly — load-bearing for the router-context defect fix in plan Phase 7.5)
- **Network**: `GET /sanctum/csrf-cookie` → 204; `GET /api/v1/projects/{id}/clip-reviews` → 200 with the standard pagination envelope — empty collection: `body.data.data` is an empty array AND `body.data.meta.total === 0` (see correction note above); no unexpected 4xx/5xx anywhere
- **API Responses**: body is the standard pagination envelope — `data.data` (review items), `data.links`, `data.meta` only; no `storage_disk`, `storage_key`, internal IDs, bucket names
- **No secrets in browser**: no tokens or credentials in request bodies, console output, or storage

### Unreachable in E2E — Re-Scoped Coverage (Ratified)

The following former scenario content is unreachable in the e2e environment and must not be re-added as E2E:

- Approve/reject/needs_changes UI workflows (former TC-E2E-01..03): require a `ClipReview` row, which requires the render pipeline → covered by TC-BE-02/TC-BE-03 and TC-FE-01..05
- VideoPlayer keyboard shortcuts and playback (former TC-E2E-04): require a loaded video → covered by TC-FE-01
- Signed URL security — the dual gate of unsigned → 401/404 and tampered/expired → 403 (former TC-E2E-06): covered by §2.5 backend feature tests; the real presigned redirect + 200 byte fetch by §7.1 (CI backend job)

### Tester Inspection Requirements (All Scenarios)

- **Screenshots**: empty review section at all three viewports; tab navigation states; loading state when observable (transient spinner labeled "Loading reviews" — do not assert the stability of a transient state)
- **Accessibility**: tab order logical (projects → open → tabs → review controls), focus visible on all interactive elements, ARIA labels present (tabs expose `role="tab"`/`aria-selected`; the back control is labeled "Back to project")

### Commands

```bash
# Discovery: the spec must be selected by Playwright (non-empty)
cd apps/web && npx playwright test e2e/clip-review.spec.ts --list

# Full run across the three viewport projects
cd apps/web && npm run test:e2e
```

## 7. Real Integration Test (AC-12)

### 7.1 Backend Real Integration

Location: `apps/api/tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php` (group: `real-integration` — every test in the file declares `->group('real-integration')`, mirroring the per-test `->group('minio-integration')` declarations in `tests/Feature/Media/MinIOIntegrationTest.php`; the file docblock states the group)

An availability guard runs in `beforeEach`, mirroring `MinIOIntegrationTest::beforeEach`: skip via `markTestSkipped` when the media disk driver is not `s3` or when a lightweight real connectivity probe (`put`/`delete` of a probe object) fails. The guard keeps full-suite runs graceful in environments without MinIO; the gated command in §9 stays fail-closed because `--fail-on-skipped` converts a skip into a failure where infrastructure is missing. Authoritative venue: CI backend `tests` job (real PostgreSQL + MinIO).

| Test Case | Assertion |
|---|---|
| Group membership | The gated command selects the file's tests (positive test/assertion counts) and executes all of them with zero skips in CI |
| Real MinIO presigned URL | A real object is written to the real media disk at the review's `derived_asset->storage_key`; `ClipReviewController::video()` returns 302 whose `Location` is a presigned URL on the configured MinIO endpoint (endpoint host, bucket + storage-key path, `X-Amz-Signature`/`X-Amz-Expires` query); a real HTTP GET of the `Location` returns 200 with a body equal to the written bytes |
| Real database rollback on error | A genuine mid-transaction failure (e.g. a real PostgreSQL trigger raising an exception on `clip_reviews` UPDATE — real database objects, not mocks) rolls the job's transaction back: the exception propagates, `status` remains `pending`, no partial state persists, no event dispatched. Failure injection via mocks/fakes is forbidden |
| Real queue (sync driver) | Verify via real dispatch: `ProcessClipReview::dispatch(...)` followed by immediate state verification (`refresh()` + status assertion). `phpunit.xml` pins `QUEUE_CONNECTION=sync`, so the sync driver executes the job inline and the state change is visible when `dispatch()` returns. Do NOT combine `Queue::fake()`, manual `->handle()`, and `assertPushed()` in this case: a faked queue never executes the job (contradicting immediate state verification) and manual `->handle()` bypasses the queue entirely |
| Real ownership chain | `user → project → media_asset → derived_asset → clip_review` all validated |
| No mocks for storage/queue | `Storage::fake()` and `Queue::fake()` absent from the file (grep-verifiable); storage is the real MinIO disk; the sync queue driver is real inline execution |

### 7.2 Infrastructure Verification

Before local integration runs (where Docker is available), verify:
- `docker compose ps` shows postgres, minio, minio-init healthy
- MinIO healthcheck: `curl -f http://127.0.0.1:9000/minio/health/ready`
- MinIO bucket exists and is private: `mc ls local/ai-clip` shows bucket
- PostgreSQL: `php artisan migrate:status` shows all migrations ran
- Laravel health: `GET /api/v1/health` returns `{"status":"ok","database":"connected"}`

Venue note: the authoritative venue for the real-integration gate is the CI backend `tests` job, which provisions PostgreSQL and MinIO as services (S3 environment, private bucket) and runs the full backend suite including the `real-integration` group. The CI e2e job provisions PostgreSQL only and uses the local filesystem disk by design (see Known Limitations). The current local sandbox cannot provision Docker infrastructure (registry pulls denied); there, local gated runs fail closed via the availability guard — correct behavior, not a RED/GREEN result.

### Commands

```bash
# Backend real integration (mandatory, fail-closed)
# Authoritative venue: CI backend `tests` job (real MinIO). A local run without
# MinIO fails closed via the availability guard — correct behavior, not evidence.
cd apps/api && php artisan test tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php --group=real-integration --fail-on-empty-test-suite --fail-on-skipped --fail-on-incomplete --fail-on-risky

# Full backend suite
cd apps/api && php artisan test

# Full frontend suite
cd apps/web && npm test && npm run lint && npm run build && npm run test:e2e

# Governance (Orchestrator/Tester)
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

## 8. Cross-Cutting: Security, Accessibility, Responsive

### Security Matrix

| Check | Method | Pass Criteria |
|---|---|---|
| IDOR prevention | Attempt access to other user's clip review via API | 404 for all endpoints |
| CSRF enforcement | Mutate without `X-XSRF-TOKEN` | 419 |
| Rate limiting | Exceed 30/min on update endpoints | 429 with headers |
| Signed URL expiry | Use URL after 1 hour | 403 |
| Signed URL tampering | Modify signature/query params | 403 |
| No internal fields in API | Inspect all API responses | No `storage_disk`, `storage_key`, `bucket`, internal IDs |
| No secrets in browser | Inspect Network, Console, React DevTools | No tokens, keys, credentials |
| Queue job re-validates ownership | Job payload with wrong user_id | Job returns early, no state change |

### Accessibility Matrix (WCAG 2.1 AA)

| Check | Method | Pass Criteria |
|---|---|---|
| Keyboard navigation | Tab through all interactive elements | All reachable, focus visible, logical order |
| Screen reader labels | Inspect ARIA attributes | `aria-label`/`aria-labelledby` on all controls |
| Live regions | Trigger status changes | `aria-live="polite"` announces changes |
| Color contrast | Verify badge colors | 4.5:1 minimum for text |
| Focus indicators | Tab through UI | Visible outline/ring on all focusable |
| Error announcements | Submit invalid feedback | `aria-live` announces error |
| Video captions | VideoPlayer with caption track | `track kind="captions"` present (if available) |

### Responsive Matrix

| Viewport | Layout Checks |
|---|---|
| 390x844 | Single column, VideoPlayer full-width, panel below, touch targets ≥44px, no horizontal scroll |
| 768x1024 | Two-column or stacked, VideoPlayer max 60% width, panel readable, grid 2-col for list |
| 1440x900 | Side-by-side, VideoPlayer ~60%, panel ~40%, comfortable line lengths, grid 3-4 col |

## 9. Required Commands, Discovery, and CI

All shell commands belong to the appropriately authorized execution role. Use stated repository-relative working directories.

| Working Directory | Command / Required Result |
|---|---|
| Repository root | `docker compose up -d postgres minio minio-init` — combined startup; verify health and private bucket init |
| `apps/api` | `php artisan migrate:fresh --seed` — clean database |
| `apps/api` | `php artisan test --exclude-group=real-integration` — fast feedback only |
| `apps/api` | `php artisan test tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php --group=real-integration --fail-on-empty-test-suite --fail-on-skipped --fail-on-incomplete --fail-on-risky` — mandatory real integration |
| `apps/api` | `php artisan test` — mandatory full backend run; nonzero executed tests, zero required skips/failures |
| `apps/api` | `vendor/bin/pint --dirty --format agent` — formatting |
| `apps/web` | `npm test` — Vitest suite pass |
| `apps/web` | `npm run lint` — lint pass |
| `apps/web` | `npm run build` — TypeScript + Vite build pass |
| `apps/web` | `npm run test:e2e` — Playwright all viewport projects; `apps/web/e2e/clip-review.spec.ts` discovered and passing (reachable embedded review experience; no MinIO in the e2e job by design) |
| Repository root | `python -m unittest discover -s tests/governance -p 'test_*.py' -v` — governance pass |

Verify installed runner support for fail-closed options during setup. An unknown option is a setup failure, not RED. The standalone integration output must explicitly show the file/group and all required cases actually executed, with positive test/assertion counts and zero skips.

Validate `.github/workflows/e2e.yml` against the GitHub Actions schema. Required for the e2e job: the PostgreSQL service with health options, deterministic migrations against it, and the Playwright `webServer` readiness chain (`/api/v1/health` for Laravel, the Vite URL) — the browser-managed Laravel process must share the job's database environment. The e2e job intentionally provisions neither MinIO nor the render pipeline; `FILESYSTEM_DISK=local` is the ratified e2e configuration (see spec "Known Limitations and Coverage Boundary"). CI must fail on E2E failure or on empty Playwright selection. The mandatory real-integration invocation is hosted by the backend `tests` job (`.github/workflows/backend.yml`), which provisions PostgreSQL and MinIO (S3 environment, private bucket) and runs the full backend suite including the `real-integration` group; that job must fail on integration failure, missing infrastructure, empty selection, or skipped required tests.

---

## Governance Validation Test Cases

These test cases verify the CI governance prerequisites documented in `spec.md` "Governance Validation Requirements (CI Prerequisites)". They must pass before PR #107 can merge.

Execution of TC-GOV-04 through TC-GOV-08 is human-gated: `scripts/merge_gate.py` and `tests/governance/**` are denied to every agent by explicit repository permission rules. The corrections specified in plan Phase 6.6 — including the actual skills-test location, `tests/governance/test_governance.py::test_g5_six_skills_parse` — are applied by the human only; agents report and verify CI outcomes. TC-GOV-01 through TC-GOV-03 already pass in the verified local governance run (173 tests, single failure: the skills count test).

### TC-GOV-01: SDD Bundle 081A Completeness

| Check | Expected |
|---|---|
| `specs/081A-m6-2-slice3a-caption-projection/spec.md` | Exists (already present) |
| `specs/081A-m6-2-slice3a-caption-projection/plan.md` | Exists (to be created) |
| `specs/081A-m6-2-slice3a-caption-projection/test-plan.md` | Exists (to be created) |
| `specs/081A-m6-2-slice3a-caption-projection/evidence.md` | Exists (to be created) |
| All 4 files non-empty, valid markdown | Pass |

**Validator**: `tests/governance/validators.py::ValidationRule.SDD_BUNDLE_COMPLETE`

### TC-GOV-02: SDD Bundle 081B Completeness

| Check | Expected |
|---|---|
| `specs/081B-m6-2-slice3b-srt-generator/spec.md` | Exists (to be created) |
| `specs/081B-m6-2-slice3b-srt-generator/plan.md` | Exists (to be created) |
| `specs/081B-m6-2-slice3b-srt-generator/test-plan.md` | Exists (to be created) |
| `specs/081B-m6-2-slice3b-srt-generator/evidence.md` | Exists (already present) |
| All 4 files non-empty, valid markdown | Pass |

**Validator**: `tests/governance/validators.py::ValidationRule.SDD_BUNDLE_COMPLETE`

### TC-GOV-03: SDD Bundle Reference (089) Remains Complete

| Check | Expected |
|---|---|
| `specs/089-m6-2-slice3d-render-caption-integration/` | All 4 files present, unchanged |

**Validator**: Regression check — bundle 089 must not be modified.

### TC-GOV-04: Merge Gate — Single APPROVE Decision Passes

| Input | Expected |
|---|---|
| `decisions = [TesterDecision(decision="APPROVE", ...)]` | `validate_tester_approval() → True` |

### TC-GOV-05: Merge Gate — Single REJECT Decision Fails

| Input | Expected |
|---|---|
| `decisions = [TesterDecision(decision="REJECT", ...)]` | `validate_tester_approval() → False` |

### TC-GOV-06: Merge Gate — Conflicting Decisions (REJECT then APPROVE) Fails

| Input | Expected |
|---|---|
| `decisions = [TesterDecision(decision="REJECT", ...), TesterDecision(decision="APPROVE", ...)]` | `validate_tester_approval() → False` (exactly one decision required) |

### TC-GOV-07: Merge Gate — Duplicate APPROVE Decisions Fails

| Input | Expected |
|---|---|
| `decisions = [TesterDecision(decision="APPROVE", ...), TesterDecision(decision="APPROVE", ...)]` | `validate_tester_approval() → False` (exactly one decision required) |

### TC-GOV-08: Skills Count — 8 Skills in `.opencode/skills/`

| Check | Expected |
|---|---|
| `test_g5_eight_skills_parse` (renamed from `test_g5_six_skills_parse`) | Passes with `expected_count = 8` |
| `.opencode/skills/` directory count | 8 directories |
| Skill names | `issue-linearity`, `tdd-enforcer`, `token-efficient-context`, `playwright-visual-qa`, `media-pipeline`, `social-publishing`, `design-taste-frontend`, `web-design-guidelines` |

### Commands

```bash
# Full governance suite (all TC-GOV cases covered)
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Expect: All governance tests pass (G1–G5). Zero failures, zero skips, zero errors.

---

## 10. Release Gate

Acceptance requires:
- AC-01 through AC-12 satisfied
- Meaningful fresh RED/GREEN/refactor where work remains
- Backend/frontend/integration/E2E/governance passes
- Valid required CI (all jobs green)
- Independent Tester visual/accessibility/API/security/failure approval
- Human Orchestrator commits, PR, merge, issue closure

After verified merge, Orchestrator reconciles `docs/project-state.md` and closes this issue; no next issue/milestone starts without separate authorization.

---

## Test Case to Acceptance Criteria Mapping

| Test Case | AC Covered |
|---|---|
| TC-BE-01: Model/Migration | AC-01 |
| TC-BE-02: API Endpoints | AC-02, AC-04 |
| TC-BE-03: Queue Job | AC-03 |
| TC-BE-04: ClipReview Harness Repair | AC-11 |
| TC-FE-01: VideoPlayer | AC-05 |
| TC-FE-02: ReviewActions | AC-06 |
| TC-FE-03: FeedbackForm | AC-07 |
| TC-FE-04: ReviewList | AC-08 |
| TC-FE-05: ReviewPage | AC-09 |
| TC-E2E-01: Review Section Discoverability and Empty State | AC-09, AC-10, AC-11 |
| TC-E2E-02: Empty Section Non-Interactivity and Navigation | AC-09, AC-10, AC-11 |
| TC-E2E-03: Responsive Viewports — Real Review Section | AC-10, AC-11 |
| TC-E2E-04: Console and Network Cleanliness | AC-10, AC-11 |
| Full approve/reject/needs_changes and video workflows (unreachable in e2e; §6 coverage routing) | AC-02, AC-03, AC-04, AC-05, AC-06, AC-07, AC-12 — covered by TC-BE-02, TC-BE-03, §2.5, §7.1, TC-FE-01..05 |
| Cross-cutting Security | AC-02, AC-04 |
| Cross-cutting Accessibility | AC-05, AC-06, AC-07, AC-10 |
| Real Integration | AC-12 |