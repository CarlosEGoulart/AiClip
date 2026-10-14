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

### 2.1 List Clip Reviews — `GET /api/v1/projects/{project}/clip-reviews`

| Scenario | Expected |
|---|---|
| Owner with reviews | 200, `{"data": [...]}`, newest `created_at` first, pagination |
| Owner no reviews | 200, `{"data": []}` |
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
| `video_url` format | Contains signed route `clip-review.video` with expiration ~1 hour |

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

| Test Case | Assertion |
|---|---|
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

## 6. Playwright E2E Tests (AC-10, AC-11)

Run via `npm run test:e2e` from `apps/web`. Playwright config starts Laravel at 127.0.0.1:8000, Vite at 127.0.0.1:5173, `reuseExistingServer: false`. Laravel readiness at `/api/v1/health`. Real PostgreSQL and MinIO required.

### TC-E2E-01: Happy Path — Approve Clip

1. Register/login unique user
2. Create project, upload video (real MP4 fixture)
3. Wait for render pipeline to produce `DerivedAsset` type=clip_rendered (or seed via test)
4. Navigate to `/projects/{id}/review` (ReviewPage)
5. Verify ReviewList shows clip with "Pending" badge
6. Click "Review" → ReviewPage with VideoPlayer
7. Play video (Space), verify playback
8. Click "Approve" → confirm dialog → confirm
9. Verify status badge changes to "Approved" (green)
10. Verify API response 200, ClipReview status=approved
11. Reload page → status persists

### TC-E2E-02: Reject Workflow

1. Same setup as TC-E2E-01
2. Click "Reject" → confirm dialog
3. FeedbackForm appears, enter feedback (≥10 chars)
4. Submit feedback
5. Verify status badge "Rejected" (red)
6. Verify feedback stored in ClipReview.feedback
7. Reload → persists

### TC-E2E-03: Needs Changes Workflow

1. Same setup
2. Click "Needs Changes" → confirm
3. Enter feedback, submit
4. Verify status "Needs Changes" (orange)
5. Verify feedback stored

### TC-E2E-04: Keyboard Navigation

1. In ReviewPage with video loaded
2. Space → play/pause
3. ArrowLeft/Right → seek ±5s
4. ArrowUp/Down → volume
5. M → mute toggle
6. F → fullscreen
7. N → next clip (if multiple)
8. P → previous clip
9. Verify all work without mouse

### TC-E2E-05: Responsive Viewports

Run TC-E2E-01 through TC-E2E-04 at each viewport:
- **390x844** (mobile): single column, VideoPlayer top, panel bottom, touch-friendly
- **768x1024** (tablet): side-by-side or stacked, readable
- **1440x900** (desktop): side-by-side, comfortable spacing

At each viewport:
- No horizontal overflow
- Text readable (no clipping)
- Buttons touch-target sized (≥44px)
- Focus visible on all interactive elements

### TC-E2E-06: Signed URL Security

1. Copy `video_url` from API response
2. Open in new incognito tab → 403/404 (not accessible without auth)
3. Wait >1 hour (or manipulate expiry) → 403
4. Tamper signature → 403
5. Owner in authenticated session → video plays

### Console, Network, and Security Inspection

During all E2E tests, Tester MUST inspect:
- **Browser Console**: No unexpected errors (React warnings allowed, no uncaught exceptions)
- **Network Tab**: All API calls return expected status; no 4xx/5xx except intentional test cases; `video_url` request returns 200/206; no credentials in request bodies
- **API Responses**: Verify `ClipReviewResource` fields match spec; no `storage_disk`, `storage_key`, internal IDs, bucket names
- **Screenshots**: Capture empty, populated, playing, confirmation dialog, error states at all 3 viewports
- **Accessibility**: Tab order logical, focus visible, ARIA labels present, live regions announce status changes

### Commands

```bash
cd apps/web && npm run test:e2e
```

## 7. Real Integration Test (AC-12)

### 7.1 Backend Real Integration

Location: `apps/api/tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php` (group: `real-integration`)

| Test Case | Assertion |
|---|---|
| Real MinIO signed URL | `ClipReviewController::video()` returns redirect to MinIO presigned URL; `GET` that URL returns 200 video bytes |
| Real database transactions | Status update wrapped in transaction; rollback on error verified |
| Real queue (sync driver) | `ProcessClipReview` job executes synchronously in test; state changes visible immediately |
| Real ownership chain | `user → project → media_asset → derived_asset → clip_review` all validated |
| No mocks for storage/queue | `Storage::fake()` not used; `Queue::fake()` not used (sync driver is real execution) |

### 7.2 Infrastructure Verification

Before integration/E2E tests, verify:
- `docker compose ps` shows postgres, minio, minio-init healthy
- MinIO healthcheck: `curl -f http://127.0.0.1:9000/minio/health/ready`
- MinIO bucket exists and is private: `mc ls local/ai-clip` shows bucket
- PostgreSQL: `php artisan migrate:status` shows all migrations ran
- Laravel health: `GET /api/v1/health` returns `{"status":"ok","database":"connected"}`

### Commands

```bash
# Backend real integration (mandatory, fail-closed)
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
| `apps/web` | `npm run test:e2e` — Playwright all viewport projects, real-storage workflow |
| Repository root | `python -m unittest discover -s tests/governance -p 'test_*.py' -v` — governance pass |

Verify installed runner support for fail-closed options during setup. An unknown option is a setup failure, not RED. The standalone integration output must explicitly show the file/group and all required cases actually executed, with positive test/assertion counts and zero skips.

Validate `.github/workflows/e2e.yml` against GitHub Actions schema. Explicit Compose startup/readiness/init steps required. Require deterministic migrations/database health, pinned MinIO health and private bucket completion before mandatory integration invocation and Playwright. Ensure PHP limits and S3/DB environment reach browser-managed Laravel process. CI must fail for either integration or E2E failure, missing infrastructure, empty selection, or skipped required tests.

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
| TC-FE-01: VideoPlayer | AC-05 |
| TC-FE-02: ReviewActions | AC-06 |
| TC-FE-03: FeedbackForm | AC-07 |
| TC-FE-04: ReviewList | AC-08 |
| TC-FE-05: ReviewPage | AC-09 |
| TC-E2E-01: Approve Workflow | AC-02, AC-03, AC-05, AC-06, AC-10, AC-11 |
| TC-E2E-02: Reject Workflow | AC-02, AC-03, AC-06, AC-07, AC-10, AC-11 |
| TC-E2E-03: Needs Changes Workflow | AC-02, AC-03, AC-06, AC-07, AC-10, AC-11 |
| TC-E2E-04: Keyboard Navigation | AC-05, AC-10 |
| TC-E2E-05: Responsive | AC-10 |
| TC-E2E-06: Signed URL Security | AC-04 |
| Cross-cutting Security | AC-02, AC-04 |
| Cross-cutting Accessibility | AC-05, AC-06, AC-07, AC-10 |
| Real Integration | AC-12 |