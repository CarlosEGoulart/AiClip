# Evidence: Issue #106 — M7 Slice 1 Clip Review Experience Foundation

## TDD: CLIP_REVIEW_FOUNDATION

### RED Phase
- [x] No ClipReview model exists
- [x] No ClipReview API endpoints exist
- [x] No ClipReview queue job exists
- [x] No React components for clip review exist
- [x] No video player component for review exists
- [x] No approve/reject/needs_changes actions exist
- [x] No feedback form component exists
- [x] No review list/page components exist
- [x] No Playwright E2E tests for review workflow exist

### GREEN Phase
- [x] ClipReview model with migration created and tested
- [x] API endpoints (list, show, update status, add feedback) implemented and tested
- [x] ProcessClipReview queue job implemented and tested
- [x] VideoPlayer component with keyboard shortcuts created
- [x] ReviewActions component (approve/reject/needs_changes) created
- [x] FeedbackForm component with character count created
- [x] ReviewList and ReviewPage components created
- [x] Frontend routes and navigation integrated (via tab in ProjectMediaSection)
- [x] Integration with DerivedAsset (clip_rendered) verified

### REFACTOR Phase
- [ ] Code style: Laravel Pint / oxlint pass
- [ ] Static analysis: PHPStan / TypeScript strict mode pass
- [ ] Test isolation: All tests pass independently
- [ ] Performance: Video loading optimized with signed URLs
- [ ] Accessibility: WCAG 2.1 AA compliance verified

## Test Results

> **Superseded scaffold note:** the tables below are the pre-Phase-6 scaffold. The authoritative test-result records for this issue are the "Phase 6" and "Phase 7" sections at the end of this file.

### Backend Tests (Pest)
| Test | Status | Notes |
|------|--------|-------|
| TC-BE-01: ClipReview model/migration | ☑ Pass (local) | All 15 `tests/Unit/{Models,Enums}/ClipReview*` tests pass — see "Phase 6" section below |
| TC-BE-02: API endpoints (CRUD + status update) | ☑ Pass (local) | All 63 `tests/Feature/ClipReview` tests pass — see "Phase 6" section below |
| TC-BE-03: ProcessClipReview queue job | ☑ Pass (local) | 9/9 job tests pass — see "Phase 6" section below |

### Frontend Tests (Vitest)
| Test | Status | Notes |
|------|--------|-------|
| TC-FE-01: VideoPlayer component | ☐ Pass / ☐ Fail | Created tests, awaiting run |
| TC-FE-02: ReviewActions component | ☐ Pass / ☐ Fail | Created tests, awaiting run |
| TC-FE-03: FeedbackForm component | ☐ Pass / ☐ Fail | Created tests, awaiting run |
| TC-FE-04: ReviewList/ReviewPage components | ☐ Pass / ☐ Fail | Created tests, awaiting run |

### E2E Tests (Playwright)
| Test | Status | Notes |
|------|--------|-------|
| TC-E2E-01: Happy path approve workflow | ☐ Pass / ☐ Fail | Created test file, awaiting run |
| TC-E2E-02: Reject workflow | ☐ Pass / ☐ Fail | Created test file, awaiting run |
| TC-E2E-03: Needs changes workflow | ☐ Pass / ☐ Fail | Created test file, awaiting run |
| TC-E2E-04: Keyboard navigation | ☐ Pass / ☐ Fail | Created test file, awaiting run |
| TC-E2E-05: Responsive viewports | ☐ Pass / ☐ Fail | Created test file, awaiting run |
| TC-E2E-06: Accessibility basics | ☐ Pass / ☐ Fail | Created test file, awaiting run |

### Integration Tests
| Test | Status | Notes |
|------|--------|-------|
| TC-INT-01: DerivedAsset → ClipReview linkage | ☐ Pass / ☐ Fail | Created test, awaiting run |
| TC-INT-02: Approved clip marked for publishing | ☐ Pass / ☐ Fail | Awaiting run |
| TC-INT-03: Rejected clip triggers re-render signal | ☐ Pass / ☐ Fail | Awaiting run |

## Scope Verification

### In Scope (Implemented)
- [x] ClipReview model with status enum (pending, approved, rejected, needs_changes)
- [x] ClipReview API (list, show, update status, add feedback)
- [x] ProcessClipReview queue job
- [x] VideoPlayer with keyboard shortcuts
- [x] ReviewActions (approve/reject/needs_changes)
- [x] FeedbackForm with character count
- [x] ReviewList and ReviewPage
- [x] Frontend routes for /projects/{project}/reviews (via tab)
- [x] Pest unit/feature tests
- [x] Vitest component tests
- [x] Playwright E2E tests

### Out of Scope (Not Modified)
- [ ] Social publishing integration (M10+)
- [ ] Batch review operations
- [ ] Advanced editing/timeline manipulation
- [ ] Mobile-specific review UI
- [ ] AI-assisted review suggestions
- [ ] Authentication/authorization (existing Sanctum)
- [ ] MediaAsset/DerivedAsset models (existing from M2/M6)

## Tester Independent Validation

### Validation Summary
Pending — awaiting test execution and Tester review.

### Acceptance Criteria Verification

| AC | Description | Status | Notes |
|----|-------------|--------|-------|
| AC-01 | ClipReview model with status enum | ✅ Implemented | Model, migration, enum, scopes |
| AC-02 | ClipReview API endpoints | ✅ Implemented | 5 endpoints with rate limiting, ownership |
| AC-03 | ProcessClipReview queue job | ✅ Implemented | Idempotent, ownership re-validation, event |
| AC-04 | VideoPlayer component | ✅ Implemented | Keyboard shortcuts, accessibility |
| AC-05 | ReviewActions component | ✅ Implemented | Confirmation dialogs, loading states |
| AC-06 | FeedbackForm component | ✅ Implemented | Char count, validation, localStorage drafts |
| AC-07 | ReviewList/ReviewPage components | ✅ Implemented | Grid layout, navigation, deep linking |
| AC-08 | Frontend routes integrated | ✅ Implemented | Tab in ProjectMediaSection |
| AC-09 | DerivedAsset integration | ✅ Implemented | Auto-create on render, scopes |
| AC-10 | Pest tests pass | ☐ Awaiting run | |
| AC-11 | Vitest/Playwright tests pass | ☐ Awaiting run | |
| AC-12 | Real integration verified | ☐ Awaiting run | |

### Decision

Superseded — no decision was ever recorded in this pre-Phase-6 scaffold section, and the earlier pre-Phase-6 Tester review left no live decision line in this file. The single, live Tester decision for PR #107 (Phase 6 delta + overall PR regression state) is recorded in the "Phase 6 — Independent Tester Validation" section at the end of this file.

## CI Status
- Backend tests: ☐
- Frontend tests: ☐
- E2E tests: ☐
- Governance: ☐

## Lifecycle State
`NO_ACTIVE_ISSUE` → `ISSUE_CREATED` (#106) → `BRANCH_CREATED` (@carlosegoulart/106/feat/clip-review-experience) → `SPEC_READY` → `RED_VERIFIED` → `GREEN_VERIFIED` → `TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

Current: `GREEN_VERIFIED` (implementation complete, tests created, awaiting test run)

---

## Phase 6 — Backend ClipReview Harness Repair (TC-BE-04, AC-11 backend)

Builder-executed, all results below are real runs from the working tree at branch `@carlosegoulart/106/feat/clip-review-experience`.

### 6.1 RED — reproduction (before any fix)

Command:

```bash
cd apps/api && php artisan test tests/Feature/ClipReview
```

Result: `63 tests, 0 passed, 63 errors`. Every test failed in `beforeEach` at the first `User::factory()->create()` with the unbooted-container error (local Pest variant of CI's `BindingResolutionException: Target class [config] does not exist`):

```
A facade root has not been set.
  at vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php:361
  → database/factories/UserFactory.php:31 → tests/Feature/ClipReview/<suite>.php:<beforeEach line>
```

Additional reproduction (not in the original CI diagnosis):

```bash
cd apps/api && php artisan test tests/Unit/Models/ClipReviewTest.php tests/Unit/Enums/ClipReviewStatusTest.php
```

Result: `15 tests, 7 passed, 8 errors`. The 8 database-dependent tests of `Unit/Models/ClipReviewTest.php` failed with the identical unbooted-container error; the pure-enum tests passed without a booted container. This reconciles CI's `71 failed` exactly: **71 = 63 (7 Feature/ClipReview files) + 8 (DB-dependent tests in `Unit/Models/ClipReviewTest.php`)**. CI's "8 skipped" are unrelated real-infrastructure skips elsewhere in the suite.

### 6.2 Root cause (verified in Pest v3 source, `vendor/pestphp/pest/src/PendingCalls/UsesCall.php` + `src/Repositories/TestRepository.php`)

All nine ClipReview test files declared directory-scoped bindings from inside the target directory (e.g. `uses(SpaTestCase::class)->in('Feature/ClipReview');`). `UsesCall::in()` resolves targets relative to the declaring file's own directory and **replaces** the original `[$filename]` target with the glob result. From inside `tests/Feature/ClipReview/`, the target becomes the non-existent doubled path `tests/Feature/ClipReview/Feature/ClipReview` → glob matches nothing → `TestRepository::use()` receives an empty path list → the declaration binds **zero files, including the declaring file**. The files therefore ran on Pest's bare PHPUnit base with no booted application.

### 6.3 Harness fix (GREEN for the harness, no test-logic changes)

Replaced the self-referential `->in()` declarations with the file-scoped form used by every passing suite, and added the `RefreshDatabase` import where missing:

| File | New declaration |
|---|---|
| `tests/Feature/ClipReview/ClipReviewApiTest.php` | `uses(SpaTestCase::class, RefreshDatabase::class);` |
| `tests/Feature/ClipReview/ClipReviewOwnershipTest.php` | `uses(SpaTestCase::class, RefreshDatabase::class);` |
| `tests/Feature/ClipReview/ClipReviewRateLimitTest.php` | `uses(SpaTestCase::class, RefreshDatabase::class);` |
| `tests/Feature/ClipReview/ClipReviewVideoUrlTest.php` | `uses(SpaTestCase::class, RefreshDatabase::class);` |
| `tests/Feature/ClipReview/ClipReviewIntegrationTest.php` | `uses(TestCase::class, RefreshDatabase::class);` |
| `tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php` | `uses(TestCase::class, RefreshDatabase::class);` |
| `tests/Feature/ClipReview/ProcessClipReviewJobTest.php` | `uses(TestCase::class, RefreshDatabase::class);` |
| `tests/Unit/Models/ClipReviewTest.php` | `uses(TestCase::class, RefreshDatabase::class);` |
| `tests/Unit/Enums/ClipReviewStatusTest.php` | `uses(TestCase::class);` |

### 6.4 Post-harness behavioral RED → GREEN (production corrections; test-plan/spec-contracted)

The repaired harness exposed real gaps. First causal error after 6.3: `Call to undefined method App\Models\DerivedAsset::factory()` — then, iteratively, 16 distinct failures. Each was diagnosed from its first causal error and fixed minimally:

1. **Missing `HasFactory`** on `App\Models\DerivedAsset` and `App\Models\ClipReview` (both factories existed; only the model-side trait was absent — used solely by issue #106 tests, which is why other suites never failed). Added `use HasFactory;` to both models (matches `Project`/`MediaAsset`/`User`).
2. **`DerivedAssetFactory::renderedClip()` was never executable**: it referenced the non-existent constant `DerivedAsset::STATUS_PENDING` and the non-existent column `status`. Fixed to `'render_status' => DerivedAsset::RENDER_STATUS_COMPLETED` on the real column — matching test-plan §3 ("DerivedAsset.render_status unchanged (completed)") and `ProcessClipReview`'s own contract comment.
3. **`ClipReviewStatusUpdateRequest::authorize()` / `ClipReviewFeedbackRequest::authorize()` returned 403 for non-owners**, contradicting test-plan §2.2/§2.4 ("Non-owner | 404"). Removed the overrides (Laravel's default `passesAuthorization()` returns `true` when `authorize()` is absent — verified in vendor); the controller's `authorizeOwnership()` already implements the 401/404 contract. Fixes 4 tests.
4. **`ClipReviewController::video()` declared `: Response` but returned a `RedirectResponse`** (TypeError → 500). Spec §Public API: "returns 302 redirect to storage temporary URL". Return type corrected to `RedirectResponse`. (Verified `Storage::fake()` supports `temporaryUrl` — vendor `Storage::fake()` installs a `buildTemporaryUrlsUsing` callback.)
5. **`video_url` was unsigned** (`route('clip-review.video', ...)`). Spec §Data Model/§"Signed URLs" mandates `URL::temporarySignedRoute('clip-review.video', now()->addHour(), ['clipReview' => id])`. Implemented in `ClipReviewResource`.
6. **Signed-URL 403 contract unreachable**: the video route sat inside `auth:sanctum`, so the unauthenticated expired/tampered-signature tests got 401 before any signature check, while the same route must also serve session owners a 302 **without** a signature (test-plan §2.5). Moved the route out of the `auth:sanctum` group and implemented the dual gate in `video()`: signature-bearing requests are validated with `abort_unless($request->hasValidSignature(), 403)`; signature-less requests go through `authorizeOwnership()` (401 guest / 404 non-owner). This matches the spec's security model ("time-limited (1 hour) signed routes; no direct storage exposure").

### 6.5 Test-side corrections (minimal, intent-preserving; require Planner/Tester confirmation)

1. **Guest write tests sent `$guestCookies = []`** (`ClipReviewApiTest` PATCH-status and POST-feedback "returns 401 for guest"). With Sanctum's stateful pipeline (`config/sanctum.php` `validate_csrf_token`), CSRF runs **before** `auth:sanctum`, so tokenless writes always get 419 — mandated by the same suite's passing "419 without CSRF" tests; no production change can yield 401 there. Corrected the setup to `$this->csrfCookies()` (guest session + valid CSRF pair — still a guest), the established passing convention (`ProjectCrudTest` guest POST → 401, `MediaUploadTest`, `MediaDeleteTest`). **Assertions unchanged (401).**
2. **`video_url` substring assertion `toContain('clip-review.video')`** (`ClipReviewApiTest` show-200, `ClipReviewVideoUrlTest` resource test) was unsatisfiable: Laravel signed URLs are `route($name, params + signature)` and never embed the route name (verified in `vendor/.../Routing/UrlGenerator.php::signedRoute()`), and the route URI is pinned by spec (`GET /api/v1/clip-reviews/{clipReview}/video`). Replaced with a strictly stronger set of checks of the actual signed form: `clip-reviews/{id}/video` + `signature` + `expires`. **Planning contradiction resolved in favor of the spec's pinned route; flagged for Planner ratification.**
3. **"real queue sync driver executes job synchronously"** used `Queue::fake()` + a manual `$job->handle()` + `Queue::assertPushed()` — unsatisfiable (manual handle never pushes; a faked queue never executes) and self-contradictory with test-plan §7.1 ("`Queue::fake()` not used (sync driver is real execution)"). Rewritten to the contract: `ProcessClipReview::dispatch(...)` under `QUEUE_CONNECTION=sync` (phpunit.xml) with immediate state verification.

### 6.6 Verification (real runs)

```bash
cd apps/api && php artisan test tests/Feature/ClipReview
# → 63 tests, 63 passed, 335 assertions (0 failed, 0 skipped)

cd apps/api && php artisan test tests/Unit/Models/ClipReviewTest.php tests/Unit/Enums/ClipReviewStatusTest.php
# → 15 tests, 15 passed, 39 assertions

cd apps/api && php artisan test tests/Feature/ClipReview tests/Unit/Models/ClipReviewTest.php tests/Unit/Enums/ClipReviewStatusTest.php   # post-Pint rerun
# → 78 tests, 78 passed, 374 assertions

cd apps/api && php artisan test --compact
# → 1503 tests, 1453 passed, 4 failed, 34 errors, 12 skipped, 5962 assertions
```

**Full-suite accounting (all 50 non-passing are pre-existing local-environment artifacts in files this issue did not touch):**

- Local total 1503 = CI total (71 + 8 + 1424). Reconciliation: 1424 (CI-passing) − 42 (tests that pass on CI's Postgres+worker environment but fail/skip on the local sqlite runner) + 71 (repaired) = 1453 local passed. Exact.
- 2 × `SessionAuthenticationTest` + 1 × `HealthTest` failures assert `DB::getDriverName() === 'pgsql'` (CI runs Postgres via env overrides; the local runner defaults to sqlite per phpunit.xml).
- 1 × `DerivedAssetRenderedClipTest::test_postgresql_rejects_unknown_render_status` expects the PostgreSQL CHECK constraint to throw (raw insert of `'unknown'`, not via the factory); sqlite has no CHECK constraints.
- 2 × `RealPhpToPythonRankClipsTest` errors: real Python worker CLI unavailable locally.
- 30 errors are `Issue60DbGuard`/`Issue64RecoveryFixture` refusals ("unauthorized database target") — self-guarded destructive tests that only run against a disposable `aiclip_test*` PostgreSQL database (CI provides `aiclip_test_issue64`).
- 1 × `ProcessMediaAssetRenderTest` storage_key collision — **proven pre-existing by an isolated run** (`php artisan test tests/Feature/Jobs/ProcessMediaAssetRenderTest.php` fails identically with none of this issue's tests present): its fixture's post-create key `projects/1/assets/1/source.mp4` equals the next fixture's transient insert key. Recorded for Planner; not fixed (out of scope).
- 12 skips are infrastructure-conditional (`WorkerBoundaryTest` pending schema, MinIO/FFmpeg/real-worker probes). The repaired ClipReview suites contain **zero skips**: all 78 tests execute and pass.
- A CI-equivalent local Postgres run was not possible: the sandbox denies environment-prefixed commands (`DB_CONNECTION=pgsql ...` rejected), so the local verification uses the phpunit.xml sqlite target. Final CI confirmation remains with the Orchestrator's PR re-run.

```bash
cd apps/api && vendor/bin/pint --dirty --format agent
# 1st run: fixed 14 dirty files (style normalization + removal of a pre-existing unused import in ClipReviewController)
# 2nd run: passed (clean)
```

### 6.7 Files changed in Phase 6

Tests (harness + the three corrections in 6.5): the 7 `tests/Feature/ClipReview/*` files, `tests/Unit/Models/ClipReviewTest.php`, `tests/Unit/Enums/ClipReviewStatusTest.php`.
Production: `app/Models/DerivedAsset.php`, `app/Models/ClipReview.php`, `database/factories/DerivedAssetFactory.php`, `app/Http/Requests/ClipReviewStatusUpdateRequest.php`, `app/Http/Requests/ClipReviewFeedbackRequest.php`, `app/Http/Controllers/Api/V1/ClipReviewController.php`, `app/Http/Resources/ClipReviewResource.php`, `routes/api.php`.

No governance files, planner-owned files, `.opencode/**`, or unrelated suites were modified.

---

## Phase 6 — Independent Tester Validation (fresh review of the Phase 6 delta + overall PR regression state)

Executed 2026-10-15 by the independent Tester on the working tree at branch `@carlosegoulart/106/feat/clip-review-experience`, HEAD `011b273`, plus the 21 uncommitted modified files. Every command below was executed fresh by the Tester; Builder claims were re-executed, not trusted. This section supersedes the earlier pre-Phase-6 Tester review; the single live decision for PR #107 is the last line of this file.

### T1. Scope verification — PASS

`git status --porcelain`: exactly the 21 expected modified files, nothing untracked:

- Production (8): `apps/api/app/Http/Controllers/Api/V1/ClipReviewController.php`, `app/Http/Requests/ClipReviewFeedbackRequest.php`, `app/Http/Requests/ClipReviewStatusUpdateRequest.php`, `app/Http/Resources/ClipReviewResource.php`, `app/Models/ClipReview.php`, `app/Models/DerivedAsset.php`, `database/factories/DerivedAssetFactory.php`, `routes/api.php`
- Tests (9): the 7 `tests/Feature/ClipReview/*` files, `tests/Unit/Models/ClipReviewTest.php`, `tests/Unit/Enums/ClipReviewStatusTest.php`
- Planner-owned bundle files (4): this directory's `evidence.md`, `plan.md`, `spec.md`, `test-plan.md`

No `tests/governance/**`, `scripts/merge_gate.py`, `.opencode/**`, `.github/workflows/**`, or unrelated files touched. PASS.

### T2. Backend targeted run — PASS (claim reproduced exactly)

Command: `php artisan test tests/Feature/ClipReview tests/Unit/Models/ClipReviewTest.php tests/Unit/Enums/ClipReviewStatusTest.php`

Result: **78 tests, 78 passed, 374 assertions — 0 failed, 0 errors, 0 skipped.** Matches Builder §6.6 exactly. The repaired ClipReview suites execute fully with no skips.

### T3. Full backend suite — PASS with independent scrutiny of all 50 non-passing tests

Command: `php artisan test --compact`

Result: **1503 tests, 1453 passed, 4 failed, 34 errors, 12 skipped, 5962 assertions** — identical to Builder §6.6. Every non-passing test was individually scrutinized and confirmed pre-existing/environmental in files untouched by this issue (none of the 21 modified files). Local runner context: `phpunit.xml` pins `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`; CI's backend job (`.github/workflows/backend.yml`) runs Postgres `aiclip_test_issue64` + MinIO + bucket init + the real Python worker.

| Count | Tests | Category / mechanism (independently verified) |
|---|---|---|
| 3 failed | `SessionAuthenticationTest` (registration, login), `HealthTest` | Assert `DB::getDriverName() === 'pgsql'` (and `session.driver === 'database'`); local sqlite/array target cannot satisfy. CI runs `DB_CONNECTION=pgsql`. |
| 1 failed | `DerivedAssetRenderedClipTest::test_postgresql_rejects_unknown_render_status` | Expects `QueryException` from the PostgreSQL CHECK constraint on a direct `DerivedAsset::create` with `render_status => 'unknown'` (verified: not via the modified factory state); sqlite enforces no CHECK constraints. |
| 2 errors | `RealPhpToPythonRankClipsTest` (2 cases) | "Ranking failed" — real Python worker CLI required; CI installs `services/worker` + FFmpeg; unavailable in the Tester sandbox. |
| 31 errors | `Issue60DbGuard`/`Issue64RecoveryFixture` refusals across `ProcessMediaAssetClipAbortBoundaryTest` (4), `ProcessMediaAssetClipAnalysisConcurrencyTest` (1), `ProcessMediaAssetClipAtomicCreateTest` (2), `ProcessMediaAssetClipRecommendationRecoveryTest` (9), `ProcessMediaAssetConcurrencyMatrixTest` (12), `MediaClipAnalysisEmptyPersistenceTest` (3) | Guards fail closed unless `config('database.connections.pgsql.database')` is a disposable `aiclip_test*` database (guard source verified); CI provisions `aiclip_test_issue64`. |
| 1 error | `ProcessMediaAssetRenderTest` (storage_key collision) | Proven pre-existing by Tester-isolated run: `php artisan test tests/Feature/Jobs/ProcessMediaAssetRenderTest.php --compact` → 4 tests, 3 passed, 1 error — identical `UNIQUE constraint failed: media_assets.storage_key` with zero ClipReview tests in the process. Mechanism: fixture inserts a transient key `projects/1/assets/1/source.mp4`, then updates to `projects/{project}/assets/{id}/source.mp4`; on local sqlite the first asset is project 1/asset 1 (rowids reset after per-test transaction rollback), so its final key equals the next fixture's transient insert key. On CI Postgres, sequences survive transaction rollback (IDs > 1 by the time the file runs), so no collision — consistent with this test passing in CI's prior green run at HEAD. |
| 12 skipped | `RenderMediaClipCaptionE2ETest`, `MinIOIntegrationTest`, `ProcessMediaAssetRenderRealWorkerTest` | All 12 skips come from these three real-infrastructure probe files: run together → 12 tests, 0 passed, 12 skipped (MinIO/FFmpeg unavailable locally; `docker compose` cannot pull images in this sandbox). Zero skips in ClipReview suites. |

Corrections to Builder evidence prose (non-blocking, category unaffected):
- The guard-refusal count is 31, not 30 (31 + 2 worker + 1 fixture-collision = 34 errors).
- Skip attribution: `WorkerBoundaryTest` passes 17/17 locally and contributes zero skips; the Builder's "WorkerBoundaryTest pending schema" attribution is inaccurate. Verified by running `WorkerBoundaryTest` + the three probe files together (29 tests, 17 passed, 12 skipped).

### T4. Pint (check-only) — PASS

`vendor/bin/pint --test --dirty` → passed (clean). No auto-formatting was performed.

### T5. No test weakening — PASS

Diff-review of all 9 changed test files against HEAD:

- Harness declarations match plan 6.3's table exactly (file-scoped `uses(...)`, `RefreshDatabase` added where needed; no `->in('Feature/ClipReview')` remains).
- Ratified correction 1 (signed-URL assertions, `ClipReviewApiTest` + `ClipReviewVideoUrlTest`): replaced unsatisfiable `toContain('clip-review.video')` with the URI path `clip-reviews/{id}/video` + `signature` + `expires` — strictly stronger; matches the ratified test-plan §2.2 row.
- Ratified correction 2 (guest writes, `ClipReviewApiTest` PATCH/POST): `$guestCookies = []` → `$this->csrfCookies()` in exactly 2 cases; 401 assertions unchanged; dedicated 419-without-CSRF cases intact; guest GET cases keep the empty cookie jar. Matches the ratified §2 guest-semantics paragraph exactly.
- Ratified correction 3 (queue sync, `ClipReviewRealIntegrationTest`): `Queue::fake()` + manual `handle()` + `assertPushed()` → real `ProcessClipReview::dispatch(...)` with immediate `refresh()` + status assertion under pinned `QUEUE_CONNECTION=sync`. Matches §7.1's ratified contract.
- All remaining hunks are Pint style normalization (imports, `fn ()`, concat spacing, EOF newline). No assertion was deleted, skipped, or loosened anywhere.

### T6. Security review — dual-gate video endpoint — PASS

`ClipReviewController::video()` + `routes/api.php` + `config/auth.php` + `bootstrap/app.php` inspected, and behavior verified through the passing HTTP-level tests:

- Gate order: signature-bearing requests → `abort_unless($request->hasValidSignature(), 403)` before any ownership/storage work; signature-less requests → `authorizeOwnership()` → 401 guest / 404 non-owner. Verified by `ClipReviewVideoUrlTest`: owner session → 302; non-owner session → 404; expired signature → 403; tampered signature (extra query param) → 403; non-existent ID → 404 via route-model binding.
- No bypass: stripping the `signature` param falls into the ownership branch (401/404); signatures are HMAC-app-keyed over the full URI including the review ID (cannot be transplanted across reviews); `hasValidSignature()` enforces `expires`. A valid signature cannot be forged client-side and is only minted by `ClipReviewResource` behind ownership-checked endpoints.
- No ownership info leak: non-owners get 404 (not 403) on the unsigned path. Minor inherent surface, accepted as part of the ratified design: with a tampered signature an existing ID yields 403 while a non-existent ID yields 404 (binding), permitting existence enumeration — inherent to signature-before-ownership ordering, no ownership data exposed.
- `video_url` in the resource is `URL::temporarySignedRoute('clip-review.video', now()->addHour(), ...)` — signed, 1-hour limited (spec §Signed Video URLs), asserted at HTTP level in both `ClipReviewApiTest` and `ClipReviewVideoUrlTest`.
- The 302 target is a 1-hour presigned storage URL; the route's session/ownership path resolves correctly because `statefulApi()` is enabled and the default guard is `web` (session) — empirically proven by the passing owner-302 test on the route moved outside `auth:sanctum`.
- Guest semantics verified: guest GETs (empty cookie jar) → 401; guest writes (`csrfCookies()`) → 401; dedicated 419-without-CSRF cases pass. CSRF-before-auth ordering matches the spec.

### T7. Frontend regression — PASS

- `npm test` → **16 files, 250 tests, 250 passed** (matches expected 250; zero failures/skips).
- `npm run lint` → exit 0; 14 warnings, all pre-existing in HEAD-committed clip-review components (`VideoPlayer.tsx`, `FeedbackForm.tsx`, `ReviewPage.tsx`). No frontend file is part of the working-tree delta, so CI's green frontend job at HEAD remains applicable.

### T8. Governance — only the known human-gated blocker

`python -m unittest discover -s tests/governance -p 'test_*.py'` → **Ran 173 tests, FAILED (failures=1)**. The single failure is `test_governance.TestGovernanceContract.test_g5_six_skills_parse` (expects 6 skills; 8 exist per AGENTS.md §16) — exactly the known human-gated control-plane item recorded in plan Phase 6.6; not counted against the implementation. (The argparse usage strings in the output belong to passing merge-gate CLI tests, not failures.) No other governance failures.

### T9. E2E — mandatory verification blocked by a configuration defect (not merely the sandbox)

- The required clip-review E2E suite (`apps/web/tests/e2e/clip-review.spec.ts`, TC-E2E-01..06) is **not discovered by the configured runner**: `playwright.config.ts` sets `testDir: './e2e'`, and `apps/web/e2e/` contains only `auth.spec.ts`, `health.spec.ts`, `projects.spec.ts`, `media.spec.ts`. `npm run test:e2e` = `playwright test` → runs zero clip-review tests. Verified: none of the 4 active specs references clip-review/video flows (grep).
- CI's green e2e job at HEAD therefore covered only auth/health/projects/media; it never exercised any clip-review endpoint — including the video/signed-URL flows changed by Phase 6. It cannot cover this delta.
- Local execution is additionally infeasible in this sandbox: `docker compose up -d postgres minio minio-init` fails — registry pull denied ("pull access denied for minio/minio…", "No such image: postgres:16-alpine"), so the Playwright-managed Laravel (readiness `/api/v1/health` with DB) cannot start.
- Assessment: AC-11 explicitly requires Playwright E2E for the approve/reject/needs_changes workflows and AC-10 requires viewport/keyboard/no-console verification; test-plan §6 mandates TC-E2E-01..06 via `npm run test:e2e`. As configured, these run zero tests everywhere (local and CI alike). This is a blocked mandatory verification, not an N/A case.
- Note for the fix loop: as written, TC-E2E-06 expects an unauthenticated open of `video_url` to land on a 403/404/login URL — this contradicts the ratified signed-URL contract (a valid signed URL opened without a session returns 302 → presigned storage URL). When wiring the spec into the runner, align TC-E2E-06 with the dual gate (unsigned → 401/404; tampered/expired → 403).

### T10. Mandatory real-integration gate (test-plan §7/§9; plan Phase 6.5) — FAIL

Exact command from test-plan §9:

`php artisan test tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php --group=real-integration --fail-on-empty-test-suite --fail-on-skipped --fail-on-incomplete --fail-on-risky`

Result: **FAILED — "No tests found.", exit code 1.** Root cause: no test in the repository declares the `real-integration` group (repo-wide grep: only the spec documents mention it; the only `->group(...)` declarations in `apps/api/tests` are `minio-integration` in `MinIOIntegrationTest.php`; `phpunit.xml` has no `<groups>`). The file's 5 tests pass only when run unfiltered (included in the 78/78 of T2). plan.md Phase 6.5 states this mandatory run "is unchanged and must pass with the repaired base binding" — it cannot pass in any environment (local or CI), because `--fail-on-empty-test-suite` hard-fails on the empty group selection.

### T11. §7.1 sub-case gaps — Tester assessment (duty-mandated)

The two Planner-recorded observations were assessed against THIS issue's blocking acceptance criteria:

- **(a) Real-MinIO signed-URL case** (`ClipReviewRealIntegrationTest::real MinIO signed URL returns redirect to presigned URL`): uses `Storage::fake('media')` and asserts only 302 + Location-present. Test-plan §7.1 row 1 (unamended by the ratified diff) requires "redirect to MinIO presigned URL; `GET` that URL returns 200 video bytes", and row 5 requires "`Storage::fake()` not used"; spec AC-12 requires "no mocks for storage or worker in integration tests". Assessment: **this violates a blocking acceptance criterion of THIS issue**, not a future-evaluation gap — the file was authored by this issue (commit `011b273`, verified via `git log --follow`), the Planner recorded the gap but left the §7.1 rows unamended, and the fake masks precisely the layer AC-12 requires to be real. The repository already has the pattern for this: `MinIOIntegrationTest` (group declaration + availability guard with `markTestSkipped`), and backend CI provisions MinIO + a private `aiclip-media` bucket, so the case is CI-executable.
- **(b) Rollback-on-error**: §7.1 row 2 requires "rollback on error verified". Only happy-path transaction coverage exists (`real database transaction wraps status update`); the job test's error case throws in `findOrFail` before any write. Production code inspection confirms `ProcessClipReview::handle()` wraps the status update in `DB::transaction`, but no test verifies rollback on a mid-transaction error. Assessment: **blocking per §7.1's fail-closed verification contract** (a small error-injection case satisfies it), secondary to (a).

Both fixes are small and in-scope: the file is already among the 9 Phase-6-modified test files.

### T12. Claims vs. evidence

All re-executed Builder Phase 6 numbers reproduced exactly (78/78 + 374 assertions; 1503/1453/4/34/12 + 5962 assertions; Pint clean). Two minor evidence-prose corrections recorded in T3 (guard count 31 vs 30; skip attribution). The expectation that CI's backend `tests` job turns green after push is consistent with this analysis (all 50 local non-passing are environment-specific and the 71 CI failures are repaired), with the caveat that the ClipReview suites have never yet executed on CI's Postgres — the Orchestrator must watch the pushed run.

### Blocking reasons

1. **Mandatory fail-closed real-integration command fails deterministically** (T10): the `real-integration` group is declared nowhere in the test code, so the §7/§9 command exits 1 with "No tests found" in every environment. Required by plan Phase 6.5 ("must pass with the repaired base binding"). Fix: declare the group on `ClipReviewRealIntegrationTest` (e.g., `->group('real-integration')` on its tests or the file).
2. **AC-12 violated by the integration suite itself** (T11a): the designated real-integration MinIO case uses `Storage::fake('media')` and never GETs the presigned URL for 200 video bytes, contradicting AC-12 ("no mocks for storage or worker in integration tests") and the unamended §7.1 rows 1 and 5.
3. **§7.1 row 2 (rollback-on-error) unverified** (T11b): no test exercises a mid-transaction failure; only the happy path is covered.
4. **Clip-review E2E is undiscoverable by the configured runner** (T9): `apps/web/tests/e2e/clip-review.spec.ts` is outside Playwright's `testDir` (`./e2e`), so `npm run test:e2e` and CI's e2e job run zero clip-review tests; AC-10/AC-11's E2E components are unverifiable anywhere. Local execution is additionally blocked in this sandbox (docker registry pull denied → no PostgreSQL/MinIO for the managed Laravel).

Everything else passed: targeted 78/78; full-suite parity with every non-passing item proven pre-existing/environmental in untouched files; Pint clean; no test weakening (3 ratified corrections implemented exactly); dual-gate security review clean; Vitest 250/250; lint exit 0; governance shows only the known human-gated skills-count failure; scope exactly the 21 expected files.

**Superseded decision (historical record only): the Phase 6 review concluded REJECT for the four blocking reasons above.** This decision line was rewritten to prose by the Phase 7 re-review so that this file carries exactly one live Tester decision; the four defects were remediated in Phase 7 and independently re-reviewed in the "Phase 7 — Independent Tester Re-review" section at the end of this file, which records the single live decision for PR #107.

---

## Phase 7 — Tester REJECT Remediation (plan Phase 7.0–7.9)

Builder-executed (a continuation Builder session completing an interrupted session's Phase 7 work). All results below are real runs from the working tree at branch `@carlosegoulart/106/feat/clip-review-experience` (HEAD `011b273` plus uncommitted Phase 6/7 changes). Every command below was executed by the Builder in this session; outputs are quoted from the actual runs. Items whose authoritative venue is CI are explicitly marked PENDING CI.

### 7.1 Defect 1 — `real-integration` group declared on every test + availability guard

Changes to `apps/api/tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php`: the file docblock states the group; all six `it(...)` tests declare `->group('real-integration')` (per-test, mirroring `MinIOIntegrationTest`); `beforeEach` gained the availability guard mirroring `MinIOIntegrationTest::beforeEach` — `markTestSkipped` when the media disk driver is not `s3`, and when a lightweight real connectivity probe (put/delete of a probe object) fails.

Verification (local):

- `it(` count: 6 (lines 76, 120, 129, 172, 180, 201); `group('real-integration')` count: 6 (lines 118, 127, 170, 178, 199, 210) — every test grouped, no ungrouped test.
- Grep for `Storage::fake|Queue::fake|Event::fake|Http::fake` in the file: 5 matches, all inside comments (no executable fakes anywhere in the file).

RED basis: Tester T10 above already recorded the pre-fix gated run — `--fail-on-empty-test-suite` exit 1, "No tests found." After the fix the same gated command selects the file:

```bash
cd apps/api && php artisan test tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php --group=real-integration --fail-on-empty-test-suite --fail-on-skipped --fail-on-incomplete --fail-on-risky
# Result: 6 tests selected, 0 passed, 0 assertions, 6 skipped — exited with code 1
```

Locally the availability guard skips all six tests (no MinIO in this sandbox; Docker registry pulls are denied), and `--fail-on-skipped` converts the skips into the fail-closed exit 1 — the plan-defined correct local behavior, not a RED/GREEN result. The gated execution with real MinIO is **PENDING CI** (authoritative venue: CI backend `tests` job, which provisions PostgreSQL + MinIO and runs the full suite with no group exclusions).

### 7.2 Defect 2 — real MinIO presigned round trip (no `Storage::fake`)

Rewrote the presigned case (AC-12): `Storage::fake('media')` removed; a real object is put to the real media disk at the review's `derived_asset->storage_key` (unique per run); `ClipReviewController::video()` is invoked for the owned review and the test asserts:

1. 302;
2. `Location` host and port equal the configured S3 endpoint (`config('filesystems.disks.media.endpoint')`);
3. the URL path contains `/{bucket}/{storage_key}` (path-style endpoint per `AWS_USE_PATH_STYLE_ENDPOINT`);
4. the query contains `X-Amz-Signature` and `X-Amz-Expires`;
5. a real HTTP GET of the `Location` (un-faked `Http` facade) returns 200 with the body equal to the bytes written;
6. cleanup deletes the object in a `finally` block, so it happens even when an assertion fails.

The old case asserted only "302 + Location is a string" under a fake disk — strictly weaker on every axis; nothing was deleted or skipped. Execution against real MinIO is **PENDING CI** (same gated venue as 7.1).

### 7.3 Defect 3 — real-database rollback-on-error case

Added `rolls back the status update and dispatches no event when the transaction fails mid-way`:

- Failure injection is real infrastructure only: `DB::unprepared` creates a PostgreSQL trigger function that `RAISE EXCEPTION`s on any `clip_reviews` UPDATE plus a `BEFORE UPDATE` trigger on the table. DDL is transactional in PostgreSQL and the test runs inside `RefreshDatabase`'s outer transaction, so the trigger cannot leak beyond the test.
- The job runs through the real dispatch path (`ProcessClipReview::dispatch(...)` under pinned `QUEUE_CONNECTION=sync`; exception propagation through the sync queue verified in vendor source `Illuminate\Queue\SyncQueue::handleException` → `throw $e`).
- Assertions: `QueryException` with the injected message propagates to the caller; after the failure `status` is still `pending`; `feedback` unchanged; `DerivedAsset.render_status` unchanged; the `ClipReviewCompleted` event is not dispatched. Event non-dispatch is verified with a real listener registered on the real dispatcher via `Event::listen` (a recording closure — `Event::fake` is a test double and is forbidden in this file).

Execution against real PostgreSQL is **PENDING CI** (same gated venue).

### 7.4 Defect 4 — `apps/web/e2e/clip-review.spec.ts` (fictional spec removed, real spec created)

- The fictional `apps/web/tests/e2e/clip-review.spec.ts` is deleted (verified in `git status`: `D apps/web/tests/e2e/clip-review.spec.ts`; the `apps/web/tests/` tree is gone — `ls apps/web` shows no `tests/` directory).
- Created `apps/web/e2e/clip-review.spec.ts` (inside `testDir: './e2e'`) implementing the rewritten test-plan §6 TC-E2E-01..04 against the real embedded UI. Data setup follows `apps/web/e2e/media.spec.ts`: a unique user is registered via the UI, the project is created via the UI with the id captured from the real 201 response, the project opens via `Open {name}`, and the Review Clips tab mounts the embedded `ReviewPage`. No API interception, no seed users, no `/projects/{id}/review` navigation anywhere in the spec.
  - **TC-E2E-01**: Review Clips tab visible after opening the project; the tab click issues the real pair `GET /sanctum/csrf-cookie` → 204 and `GET /api/v1/projects/{id}/clip-reviews` → 200 with body asserted `toEqual({ data: [] })` (envelope-only — no storage_disk/storage_key/internal IDs/bucket); empty-state assertions (`.review-header` contains the project name, "No clips", "Select a clip to review", "Clips to Review" heading, empty `Clip review list`, zero `listitem`s); no video player (`Clip video`), no `Review actions` group, no Approve/Reject/Needs Changes buttons, no `Feedback form`; cleanup deletes the project via the UI flow (`Delete {name}` → `Confirm delete {name}` → "No projects yet").
  - **TC-E2E-02**: no action buttons in the DOM; pressing `n`/`N`/`p`/`P` leaves the URL unchanged (no `clipReviewId` parameter) and the empty state unchanged; switching Media ⇄ Review Clips re-renders the same empty state with a second real clip-reviews 200 `{"data": []}`; the review section's "Back to project" control returns to the projects list.
  - **TC-E2E-03** (runs on each of the three configured viewport projects — no manual viewport setting): no horizontal overflow (`documentElement.scrollWidth <= innerWidth`); header/sidebar/empty-state not clipped by the viewport; interactive controls (`Back to projects`, both tabs, `Back to project`, logout) ≥ 44px tall; grid reflow asserted (1 column below 1024px, 2 columns at ≥ 1024px); keyboard navigation (Shift+Tab backwards, Tab forwards) reaches all five named controls with visible focus indicators (`outlineWidth > 0` via computed styles).
  - **TC-E2E-04**: per its "During TC-E2E-01 through TC-E2E-03" definition, each of the three tests tracks and asserts: no console errors/warnings, no uncaught page errors, no unexpected 4xx/5xx responses (the anonymous `GET /api/v1/auth/me` → 401 session probe is the single expected 4xx and is whitelisted), and empty localStorage/sessionStorage (no tokens/credentials persisted).

Discovery verification (local, per plan 7.8):

```bash
cd apps/web && npx playwright test e2e/clip-review.spec.ts --list
# 9 tests in 1 file:
#   [mobile|tablet|desktop] × { TC-E2E-01, TC-E2E-02, TC-E2E-03 }
```

The full run across the three viewport projects is **PENDING CI** (authoritative venue: CI e2e job; local full e2e is infeasible in this sandbox — the Playwright-managed Laravel requires PostgreSQL and Docker registry pulls are denied, per Tester T9).

### 7.5 Defect 5 — embedded `ReviewPage` crash without Router/QueryClient (RED → GREEN)

The interrupted session implemented the fix (native History API URL sync in `ReviewPage.tsx`; `MemoryRouter` removed from `ReviewPage.test.tsx`; `ReviewPage.test.tsx` rewritten per TC-FE-05; new production-topology mount test `src/__tests__/ClipReviewSection.test.tsx`; root `QueryClientProvider` in `App.tsx`) but its RED capture was not recorded in evidence. This session verified the implementation and recreated the RED honestly from the actual pre-fix code (HEAD `011b273`): both pre-fix files were reconstructed and verified via `git diff HEAD` (App.tsx: empty diff — byte-identical; ReviewPage.tsx: hunk-identical, the only delta being a standard trailing newline at EOF that HEAD lacks — behaviorally irrelevant).

**RED 1 — router-context crash (the plan's RED anchor).** Pre-fix `ReviewPage.tsx` + pre-fix `App.tsx`, new tests:

```bash
cd apps/web && npm test -- --run src/features/clip-review src/__tests__/ClipReviewSection.test.tsx
# Test Files  2 failed | 4 passed (6)
# Tests  15 failed | 58 passed (73)
# Error: useLocation() may be used only in the context of a <Router> component.
#   at invariant node_modules/react-router/dist/development/chunk-OB3PAWPO.mjs:207:11
#   at useLocation node_modules/react-router/dist/development/chunk-OB3PAWPO.mjs:5959:3
#   at useSearchParams node_modules/react-router/dist/development/chunk-OB3PAWPO.mjs:10938:18
#   at ReviewPage src/features/clip-review/components/ReviewPage.tsx:34:43
#     34|   const [searchParams, setSearchParams] = useSearchParams();
```

All 14 `ReviewPage.test.tsx` tests fail with this error (the router-context crash), plus the production-topology `ClipReviewSection.test.tsx` mount test.

**RED 2 — provider-less React Query crash (same defect class; justifies the App root `QueryClientProvider`).** Fixed `ReviewPage.tsx`, pre-fix `App.tsx`:

```bash
cd apps/web && npm test -- --run src/__tests__/ClipReviewSection.test.tsx
# Tests  1 failed (1)
# Error: No QueryClient set, use QueryClientProvider to set one
#   at useQueryClient node_modules/@tanstack/react-query/src/QueryClientProvider.tsx:29:11
#   at useQuery src/features/clip-review/hooks.ts:11:10
#   at ReviewPage src/features/clip-review/components/ReviewPage.tsx:59:61
```

**GREEN.** Fixed versions restored (working tree):

```bash
cd apps/web && npm test -- --run src/features/clip-review src/__tests__/ClipReviewSection.test.tsx
# Test Files  6 passed (6)
# Tests  73 passed (73)
```

The fix preserves the deep-linking contract: `clipReviewId` is read from `window.location.search` on mount and written with `window.history.replaceState` on selection (`replace`, not `push`, so the browser Back button keeps leaving the project). The URL-sync and router-topology tests are the RED anchors; the remaining TC-FE-05 rows (first-pending selection, `video_url` prop, ReviewActions status, FeedbackForm conditional render, N/P navigation with boundaries, deep-link on mount, empty state, onBack) are covered by the 14-test suite. No component behavior other than the URL-sync mechanism changed.

**Justification for the root `QueryClientProvider` in `App.tsx`:** RED 2 demonstrates the crash — the clip-review hooks are the application's first React Query consumers, every such hook throws without a client, and the tree has no error boundary, so the pre-fix app crashed exactly when the review section mounted. Same provider-less-hook defect class as 7.5.

**Justification for the `staleTime` removal in `hooks.ts`:** with the new persistent root query cache, the old `staleTime: 30000` on `useClipReviews` would suppress the refetch when the review tab remounts, contradicting TC-E2E-02 step 4 ("the clip-reviews request succeeds again" after switching tabs back). React Query defaults (staleTime 0 + refetchOnMount) restore fresh-on-mount semantics; no other behavior changed.

**Justification for the `index.css` additions:** the review-section styles (skip link, loading, header, layout, empty state, sidebar) replace the equivalent utility-class styling of the same markup, and the touch-target rules (`min-height: 44px` + `:focus-visible` for `.logout-button`, `.back-button`, `.tab-navigation [role="tab"]`, `.review-back-button`) are required to satisfy the ratified TC-E2E-03 ("Interactive controls (tabs, back, logout) touch-target sized (≥44px) with visible focus") — without them the reachable review surface could not pass its own ratified scenario.

**Location convention for `src/__tests__/ClipReviewSection.test.tsx`:** matches the existing repository convention for App-level tests (`src/__tests__/Auth.test.tsx`, `src/__tests__/HealthCheck.test.tsx`; vitest `include: ['src/**/*.{test,spec}.{ts,tsx}']`).

### 7.6 Dead-code removal — `ClipReviewPage.tsx` deleted; `react-router-dom` removed

- `apps/web/src/pages/ClipReviewPage.tsx` deletion verified (`git status`: `D apps/web/src/pages/ClipReviewPage.tsx`; nothing imports it). The now-empty `src/pages/` directory could not be removed by this sandbox (`rmdir` is permission-denied); git does not track empty directories, so this local-filesystem artifact has no effect on the PR or CI.
- Repo grep for `react-router` across `apps/web` source, e2e, and configs: zero imports remain — only two prose comment mentions (`ReviewPage.tsx`/`ReviewPage.test.tsx` explaining why the native History API is used). Per plan 7.6 the dependency was therefore removed: `react-router-dom` dropped from `apps/web/package.json` devDependencies, lockfile regenerated:

```bash
cd apps/web && npm install
# removed 4 packages, and audited 116 packages in 1s
```

`grep react-router apps/web/package-lock.json` → no matches (lockfile in sync; CI's `npm ci` will not fail on staleness).

```bash
cd apps/web && npm run lint   # oxlint: exit 0, 14 warnings
cd apps/web && npm run build  # tsc -b && vite build: ✓ 87 modules transformed
```

The 14 lint warnings are the identical pre-existing profile recorded by the Tester in T7 (VideoPlayer.tsx, FeedbackForm.tsx, ReviewPage.tsx — all HEAD-committed patterns; no new warnings from Phase 7 changes).

### 7.7 Full local regression runs (infra-free venue)

```bash
cd apps/api && php artisan test tests/Feature/ClipReview tests/Unit/Models/ClipReviewTest.php tests/Unit/Enums/ClipReviewStatusTest.php --compact
# 79 tests, 73 passed, 368 assertions, 6 skipped — passed (exit 0)

cd apps/api && php artisan test --compact
# 1504 tests, 1448 passed, 4 failed, 34 errors, 18 skipped, 5956 assertions — exit 2
```

**Full-suite parity reconciliation (vs the 1503/1453/4/34/12/5962 baseline of Phase 6/Tester T3):**

- Total 1503 → 1504: +1 test (the new rollback case).
- Passed 1453 → 1448 and skipped 12 → 18: the five real-integration tests that previously "passed" locally (via `Storage::fake` + sqlite) now skip via the availability guard, plus the new sixth test also skips — exactly the plan's designed behavior ("the guard keeps full-suite runs graceful in environments without MinIO"). Their real execution moves to the CI backend `tests` job.
- Assertions 5962 → 5956: −6, the local assertions of those six now-skipping tests.
- The 4 failed and 34 errors are the identical pre-existing local-environment items itemized in Tester T3's table (pgsql-assertions ×3, sqlite-CHECK ×1, real-worker ×2, db-guards ×31, storage_key collision ×1) — zero new failures; no file outside this issue's scope is affected.

```bash
cd apps/api && vendor/bin/pint --test --dirty
# passed (clean)

cd apps/web && npm test
# Test Files  17 passed (17)
# Tests  260 passed (260)
```

Vitest reconciliation: 250 (baseline) − 5 (old ReviewPage tests) + 14 (new TC-FE-05 suite) + 1 (ClipReviewSection) = 260; 17 files = 16 + 1.

```bash
cd apps/web && npx playwright test e2e/clip-review.spec.ts --list
# Total: 9 tests in 1 file (3 scenarios × mobile/tablet/desktop)
```

### 7.8 CI-pending items (authoritative venues, per plan 7.8)

1. Gated real-integration run — CI backend `tests` job (real PostgreSQL + MinIO): all six tests must execute with zero skips (defects 1–3).
2. Full Playwright run of `e2e/clip-review.spec.ts` across the three viewport projects — CI e2e job (defect 4).
3. Backend full suite on CI Postgres/MinIO/worker environment (per T3 analysis, the 4+34 local environment failures pass on CI).

### 7.9 Files changed in Phase 7 (Builder)

- `apps/api/tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php` — group + guard + real presigned case + rollback case + strengthened no-fakes case (7.1–7.3; this session)
- `apps/web/e2e/clip-review.spec.ts` — new e2e spec, TC-E2E-01..04 (7.4; this session)
- `apps/web/src/features/clip-review/components/ReviewPage.tsx`, `ReviewPage.test.tsx` — native History API URL sync + router-free TC-FE-05 suite (7.5; interrupted session — verified, RED re-captured this session)
- `apps/web/src/__tests__/ClipReviewSection.test.tsx` — new production-topology mount test (7.5; interrupted session — verified)
- `apps/web/src/App.tsx` — root `QueryClientProvider` (7.5 defect class; interrupted session — verified, RED re-captured)
- `apps/web/src/features/clip-review/hooks.ts` — `staleTime` removal (interrupted session — verified; justified in 7.5)
- `apps/web/src/index.css` — review-section styles + ≥44px touch targets + `:focus-visible` (interrupted session — verified; justified in 7.5)
- `apps/web/package.json`, `apps/web/package-lock.json` — `react-router-dom` removed from devDependencies + lockfile regenerated (7.6; this session)
- Deleted (interrupted session — verified): `apps/web/src/pages/ClipReviewPage.tsx`, `apps/web/tests/e2e/clip-review.spec.ts`
- `specs/106-clip-review-foundation/evidence.md` — this section

No governance files, planner-owned bundle files (spec/plan/test-plan), `.opencode/**`, or unrelated suites were modified. No assertion was weakened, skipped, or deleted anywhere; the only production behavior change is defect 5's URL-sync mechanism plus the root React Query provider, both preserving the spec's contracts.

Pre-existing, out-of-scope items recorded for the Planner (not addressed, per scope discipline): the `npm audit` high-severity advisory in `source-map-js` (transitive dependency via vite/rollup, unrelated to this issue's changes); the local-only empty `src/pages/` directory noted in 7.6.

---

## Phase 7 — Independent Tester Re-review (post-remediation)

Executed 2026-10-15 by the independent Tester on the working tree at branch `@carlosegoulart/106/feat/clip-review-experience` (HEAD `011b273` plus uncommitted Phase 6/7 changes). This section supersedes the Phase 6 review decision (rewritten to prose above). Every command below was executed fresh by the Tester; Builder Phase 7 claims were re-executed or independently verified against the actual code and diffs, not trusted. This section holds the single live Tester decision for PR #107 (file-final line).

### R1. Per-defect verification (1–5) — all defects genuinely fixed

**Defect 1 (group + guard) — FIXED, verified.** `ClipReviewRealIntegrationTest.php`: docblock states the group; grep counts match — 6 `it(...)` tests (lines 76, 120, 129, 172, 180, 201) and 6 `->group('real-integration')` declarations (lines 118, 127, 170, 178, 199, 210); no ungrouped test. The `beforeEach` availability guard is line-for-line the established `MinIOIntegrationTest::beforeEach` pattern (driver-is-not-s3 → `markTestSkipped`; failed put/delete connectivity probe → `markTestSkipped`), compared side by side. The gated §9/§7 command now selects the file (see R2) instead of "No tests found", and fails closed locally via `--fail-on-skipped` (exit 1) — the plan-defined correct local behavior.

**Defect 2 (no `Storage::fake`; real presigned round trip) — FIXED, verified.** Grep for `Storage::fake|Queue::fake|Event::fake|Http::fake` in the file: 5 matches, all inside comments/docblocks (lines 30–31, 131, 202, 204); zero executable test doubles (AC-12). Diff review vs HEAD: the old case (`Storage::fake('media')`, asserting only 302 + Location-is-string) was replaced by a strictly stronger real round trip — real `put` at the review's `storage_key` (UUID-suffixed, collision-proof), 302 assert, `Location` host/port equal to the configured S3 endpoint, path contains `/{bucket}/{storage_key}` (path-style per `AWS_USE_PATH_STYLE_ENDPOINT`), query carries `X-Amz-Signature` + `X-Amz-Expires`, un-faked `Http::timeout(30)->get($location)` returns 200 with the body equal to the written bytes, and `finally`-block cleanup. Every §7.1 row-1 sub-assertion is present.

**Defect 3 (rollback-on-error) — FIXED, verified.** New case "rolls back the status update and dispatches no event when the transaction fails mid-way": failure injection is real infrastructure only (a plpgsql function `RAISE EXCEPTION` + `BEFORE UPDATE` trigger on `clip_reviews` via `DB::unprepared`; DDL is transactional in PostgreSQL and the test runs inside `RefreshDatabase`'s outer transaction); the job runs through the real dispatch path (`ProcessClipReview::dispatch` under pinned `QUEUE_CONNECTION=sync` — vendor source independently checked: `Illuminate\Queue\SyncQueue::handleException` ends `throw $e`, so the exception genuinely propagates); event non-dispatch is verified with a real listener on the real dispatcher via `Event::listen` (no `Event::fake`). Assertions cover the full §7.1 row-2 contract: `QueryException` with the injected message propagates; `status` remains `pending`; `feedback` unchanged; `DerivedAsset.render_status` unchanged; zero events recorded. The unmodified `ProcessClipReview::handle()` (HEAD, not in the working-tree delta) was inspected: `DB::transaction` wraps the update and `event(new ClipReviewCompleted)` fires only after it — the rollback mechanics require no production change, correctly none was made.

**Defect 4 (E2E undiscoverable/fictional) — FIXED, verified.** The fictional `apps/web/tests/e2e/clip-review.spec.ts` is deleted and the `apps/web/tests/` tree is gone (`ls apps/web` shows no `tests/`). The new `apps/web/e2e/clip-review.spec.ts` lives inside `testDir: './e2e'`. Its three scenarios map assertion-by-assertion to the rewritten test-plan §6 TC-E2E-01..04 (including TC-E2E-04's per-test console/pageerror/unexpected-4xx collectors with the anonymous `GET /api/v1/auth/me` 401 as the single whitelisted 4xx, and empty localStorage/sessionStorage). Data setup mirrors the established `apps/web/e2e/media.spec.ts` flow verbatim (unique UI registration, `Create one`/`Create Account`, project created via UI with id captured from the real 201 response, `Open {name}`, `Delete {name}` → `Confirm delete {name}` → "No projects yet") — verified by direct comparison; zero route interception, zero seed users, zero `/projects/{id}/review` navigation. Every selector the spec targets was cross-checked against the real markup: `#media-tab`/`#review-tab`/`.tab-navigation` (pre-existing `ProjectMediaSection.tsx`), `.logout-button` (`AuthenticatedShell.tsx`), `.review-header`/`.review-layout`/`.review-empty`/`.review-sidebar`/"No clips"/"Select a clip to review"/"Clips to Review"/`aria-label="Back to project"` (`ReviewPage.tsx`); the TC-E2E-03 touch-target set is covered by the new CSS (`min-height: 44px` on `.back-button`, tabs, `.review-back-button`, `.logout-button`) and the grid reflow assertion matches the CSS breakpoint exactly (1 column → `2fr 1fr` at `@media (min-width: 1024px)`). `npx playwright test e2e/clip-review.spec.ts --list` → **9 tests** (TC-E2E-01..03 × mobile/tablet/desktop), reproduced by the Tester. Full-run execution: locally blocked (R6), CI-authoritative per ratified plan 7.8.

**Defect 5 (Router/QueryClient crash) — FIXED, verified.** `ReviewPage.tsx`: no react-router import; deep-linking implemented exactly per plan 7.5 — `readClipReviewIdParam()` reads `clipReviewId` from `window.location.search` on mount (selection effect), `writeClipReviewIdParam()` uses `window.history.replaceState` on selection (replace, not push — Back keeps leaving the project; plan-mandated, and HEAD's N/P/list-click paths used push semantics, so the plan explicitly supersedes). `git diff HEAD` confirms HEAD's crash source verbatim: `useSearchParams()` at old line 34 — the exact line and call the Builder's RED output cites (`ReviewPage.tsx:34:43`), corroborating the RED recreation against real pre-fix code. RED honesty was further cross-verified structurally: HEAD's production tree contains no Router anywhere (App/main.tsx inspected; `MemoryRouter` existed only inside the old test wrapper) and no `QueryClientProvider` (the old App.tsx renders `AuthProvider > AppContent` only, per the diff), so both recorded RED errors — the `<Router>` context invariant and `No QueryClient set` — are the mechanically certain failures for the verified pre-fix topology, and the RED-2 stack (`hooks.ts:11` → `ReviewPage.tsx:59`) matches the fixed file's actual line 59. The rewritten `ReviewPage.test.tsx` renders with QueryClient context only (no Router), per ratified TC-FE-05.

**7.6 dead-code removal — verified.** `apps/web/src/pages/ClipReviewPage.tsx` deleted; repo grep for `ClipReviewPage` → zero references. Grep for `react-router` across `apps/web` → exactly 2 matches, both prose comments in `ReviewPage.tsx`/`ReviewPage.test.tsx` explaining the native-History-API rationale; zero imports, zero references in `package.json`/`package-lock.json` (lockfile regenerated and in sync for CI's `npm ci`).

### R2. Local re-runs — all Builder Phase 7 numbers reproduced exactly

```bash
cd apps/api && php artisan test tests/Feature/ClipReview tests/Unit/Models/ClipReviewTest.php tests/Unit/Enums/ClipReviewStatusTest.php --compact
# → 79 tests, 73 passed, 368 assertions, 6 skipped — exit 0 (matches Builder 7.7)

cd apps/api && php artisan test tests/Feature/ClipReview/ClipReviewRealIntegrationTest.php --group=real-integration --fail-on-empty-test-suite --fail-on-skipped --fail-on-incomplete --fail-on-risky
# → 6 tests selected, 0 passed, 0 assertions, 6 skipped — exit code 1 (fail-closed, matches 7.1)

cd apps/api && php artisan test --compact
# → 1504 tests, 1448 passed, 4 failed, 34 errors, 18 skipped, 5956 assertions — exit 2 (matches 7.7)

cd apps/api && vendor/bin/pint --test --dirty
# → passed (clean)

cd apps/web && npm test
# → Test Files 17 passed (17); Tests 260 passed (260) (matches 7.7)

cd apps/web && npm run lint
# → oxlint exit 0; 14 warnings — identical pre-existing profile (VideoPlayer.tsx, FeedbackForm.tsx, ReviewPage.tsx HEAD patterns; matches T7/7.6)

cd apps/web && npm run build
# → tsc -b && vite build: 87 modules transformed — green

cd apps/web && npx playwright test e2e/clip-review.spec.ts --list
# → Total: 9 tests in 1 file ([mobile|tablet|desktop] × TC-E2E-01..03)
```

### R3. Full-suite parity reconciliation — exact, independently recomputed

Versus the Tester T3 baseline (1503/1453/4/34/12/5962): total +1 (new rollback case); passed −5 and skipped +6 (the six real-integration tests now skip locally via the availability guard — the five that previously "passed" via `Storage::fake`+sqlite plus the new sixth — exactly the plan-designed "graceful without MinIO" behavior); assertions −6 (the five previously-executing local tests' assertion counts 2+1+1+1+1, recomputed by the Tester from the HEAD diff). The 4 failed and 34 errors are the identical pre-existing local-environment items itemized in T3's table (3 pgsql-driver assertions, 1 sqlite-CHECK expectation, 2 real-Python-worker, 31 db-guard refusals, 1 storage_key collision — all in files untouched by this issue); zero new failures; zero ClipReview failures. Vitest: 250 − 5 (old ReviewPage suite) + 14 (new TC-FE-05 suite) + 1 (ClipReviewSection) = 260 across 16 + 1 = 17 files — recomputed and confirmed.

### R4. No test weakening — PASS (full diff review, Phases 6 + 7 combined)

- `ReviewPage.test.tsx` vs HEAD: all 5 pre-existing tests preserved with equal-or-stronger assertions (loading state unchanged; header test upgraded from `getByText` to `getByRole('heading', …)` plus clip counter; hook-call test unchanged; empty-state test extended with heading + absent video/actions/feedback + URL-untouched; onBack test unchanged), plus 9 new tests covering the remaining ratified TC-FE-05 rows. The only deletion is the `MemoryRouter` wrapper — the defect itself.
- `ClipReviewRealIntegrationTest.php` vs HEAD: every old assertion is preserved or strengthened (fake-disk 302/Location-string case → real round trip; `Queue::fake`+manual-handle+`assertPushed` → real dispatch with immediate state verification, the Phase 6 ratified correction 3; "no mocks" case extended with `AwsS3V3Adapter` instance + pinned sync-driver checks). One test added (rollback). None deleted, skipped, or loosened.
- The other 8 changed backend test files diff byte-identically to the Phase 6 state the Tester already ratified in T5 (file-scoped `uses(...)` + `RefreshDatabase`, signed-URL URI-path assertions, `csrfCookies()` guest writes, Pint normalization) — no post-T5 drift.
- The new `src/__tests__/ClipReviewSection.test.tsx` stubs only the network transport (`fetch`) against the full real App tree with real hooks — consistent with the existing App-level test convention (`Auth.test.tsx`, `HealthCheck.test.tsx` verified present in `src/__tests__/`).

### R5. Scope discipline — PASS (with the four non-obvious changes each ratified-requirement-justified)

`git status`: exactly the `apps/**` delta (23 modified/deleted paths) + 2 new untracked files (`apps/web/e2e/clip-review.spec.ts`, `apps/web/src/__tests__/ClipReviewSection.test.tsx`) + the 4 Planner-owned bundle files. Zero changes to `tests/governance/**`, `scripts/merge_gate.py`, `.github/workflows/**`, `.opencode/**`, Docker, or unrelated suites. Scrutiny of the four non-obvious production changes:

1. **Root `QueryClientProvider` (`App.tsx`)** — required for the feature to function at all: the clip-review hooks are the application's first React Query consumers, HEAD had no provider anywhere (verified: old App.tsx renders `AuthProvider > AppContent` only; `main.tsx` has none; repo grep confirms), there is no error boundary, and the ratified plan 7.9 exit criteria (TC-FE-05 mount tests, TC-E2E-01..03 reaching the review section) are unachievable with a provider-less crash. Same mount-crash defect class as 7.5, proven by RED 2. In scope.
2. **`staleTime` removal (`hooks.ts`)** — load-bearing for ratified TC-E2E-02 step 4 ("the clip-reviews request succeeds again" after the Media→Review tab switch): with the new persistent root cache, `staleTime: 30000` would suppress the remount refetch and the scenario's awaited request would never fire. In-code comment documents it. In scope.
3. **`index.css` additions** — the ≥44px touch-target and `:focus-visible` rules are required by ratified TC-E2E-03 (the pre-existing controls otherwise measure below 44px — e.g. `.logout-button` was `padding: 10px 24px` only), and target pre-existing, otherwise-unmodified markup (`ProjectMediaSection.tsx`, `AuthenticatedShell.tsx`) plus this issue's own `ReviewPage` classes, replacing the component's previous utility-class styling of the identical markup. In scope.
4. **`src/__tests__/` location** — matches the existing App-level convention (verified `Auth.test.tsx`, `HealthCheck.test.tsx` there; vitest include covers `src/**`). In scope.

### R6. Full Playwright run — locally blocked with a precise, newly-diagnosed cause; CI-authoritative (pre-merge obligation)

The Tester attempted full local execution beyond the previous T9 assessment and established the exact state:

- Installed the Playwright chromium browser and OS dependencies (normal verification tooling): the local run then starts both managed webServers successfully (Laravel readiness at `/api/v1/health` passes — the sandbox's PostgreSQL is real and reachable; the Tester applied the one pending migration `2026_10_14_000000_create_clip_reviews_table` to the local dev database, which is this issue's own committed migration) and launches all 9 tests in real browsers.
- All 9 then fail at `waitForAuthReady`: the served Laravel 500s on the session probe because the sandbox provides **no APP_KEY** (`storage/logs/laravel.log`: `MissingAppKeyException: No application encryption key has been specified`, 18 entries timestamped to this run) — there is no `.env` locally (only `.env.example`) and the devcontainer environment lacks the key that `phpunit.xml` supplies for Pest. Every agent-available injection mechanism is denied (env-prefixed command, `env` utility, and `.env` creation were each attempted and permission-denied). This is a deterministic local-infrastructure blocker, not an implementation defect: the failure snapshot shows the app mounting and rendering its graceful "Server error: 500 / Retry session" state — positive real-browser corroboration that the defect-5 fix holds (the App tree mounts with no Router and no crash).
- The authoritative venue per ratified plan 7.8 is the CI e2e job, and the Tester verified `.github/workflows/e2e.yml` provisions precisely what the local run lacks: `cp .env.example .env` + `php artisan key:generate`, `php artisan migrate --force` against its PostgreSQL service, `npx playwright install --with-deps chromium`, then `npm run test:e2e`. The obligation is genuinely executable and remains **PENDING CI** — results are not fabricated here. Residual static risk-check for CI: the app source contains zero `console.*` calls (the new no-console-warnings assertion has no in-app source of violations), and the data-setup patterns are the same ones the CI-green media/auth/projects specs already exercise.

### R7. Governance and decision-contract — PASS (no new failures)

`python -m unittest discover -s tests/governance -p 'test_*.py'` → **Ran 173 tests, FAILED (failures=1)**; the single failure is `test_governance.py::test_g5_six_skills_parse` (expects 6 skills; 8 exist) — the identical, pre-existing human-gated control-plane item recorded in plan 6.6; no new failures from the Phase 7 delta. Decision-contract mechanics were verified against the actual validators before editing: `tests/governance/validators.py` matches the literal substrings and fails on any REJECT literal coexisting with an APPROVE; `scripts/merge_gate.py::DECISION_RE` matches line-anchored decision lines and currently implements last-wins (its exactly-one correction is the known human-gated item). Accordingly, the superseded Phase 6 decision line above was rewritten to prose (historical facts preserved, no live-decision pattern remains), and this file now ends with exactly one live decision line, satisfying both the current gate and the human-corrected exactly-one contract.

### R8. Pre-merge CI obligations (CI-authoritative per ratified plan 7.8; merge gate + AGENTS.md require CI green before merge)

1. **CI backend `tests` job** (real PostgreSQL + MinIO): the gated §7/§9 command must select all six `real-integration` tests and execute them with zero skips (defects 1–3), and the full backend suite must pass on the CI environment (per the T3/R3 analysis, the 4+34 local-environment items pass there).
2. **CI e2e job** (APP_KEY + migrated PostgreSQL + chromium provisioned): `apps/web/e2e/clip-review.spec.ts` must pass all 9 tests across mobile/tablet/desktop — reachable embedded review experience, `GET /api/v1/projects/{id}/clip-reviews` → 200 `{"data": []}`, no console errors.
3. **Human-gated governance corrections** (plan 6.6, unchanged): skills-count test (6→8) and `merge_gate.py` exactly-one-decision contract; `governance` and `pr-enforcement` must be green before merge.

The Orchestrator must watch the pushed CI run; these venues have never yet executed this delta (the ClipReview suites and the new e2e spec have not run on CI's environment to date).

### R9. Non-blocking observations (recorded, no action in this issue)

- `spec.md`'s "Governance Validation Requirements" section retains the pre-6.6 filename for the skills test (`tests/governance/test_governance_g5_skills.py`); the actual location is `tests/governance/test_governance.py::test_g5_six_skills_parse`, already correctly stated in plan 6.6. Planner-owned prose inconsistency only.
- The local sandbox lacks an APP_KEY for the served app (no `.env`); future local E2E runners need `cp .env.example .env && php artisan key:generate` first — environmental, CI provisions it, no repository change implied.
- The empty `src/pages/` directory remains on this machine only (`rmdir` permission-denied; git does not track empty directories — no PR/CI impact), as recorded by the Builder in 7.6.

### R10. Verdict on the remediation

All four Tester defects plus the Planner-discovered defect 5 are genuinely fixed — none papered over: the group now exists and is fail-closed; the real-integration file is free of test doubles with a strictly stronger real presigned round trip and a real-database rollback case; the E2E suite is discovered by the configured runner and grounded in the verified application topology with real flows only; the embedded review section mounts in the production topology (component tests plus real-browser boot evidence) with the deep-linking contract preserved via the native History API. No assertion was weakened, skipped, or deleted anywhere in the Phase 6+7 delta. Scope is exactly `apps/**` plus the Planner-owned bundle, with every non-obvious change carrying a ratified-requirement justification. Every locally-executable mandatory verification passes and reproduces the Builder's numbers exactly; the two CI-authoritative executions (gated real-integration with real MinIO; full Playwright run) are recorded as binding pre-merge CI obligations per the ratified plan 7.8 venue matrix, enforced by the merge lifecycle.

**Superseded decision (historical record only): the 2026-10-15 Phase 7 re-review concluded APPROVE for the remediation, with the full Playwright run recorded as a binding pre-merge CI obligation.** That obligation's first CI execution then failed all nine clip-review tests on the stale `{ "data": [] }` envelope expectation while the 75 pre-existing e2e tests passed — root-caused in the Builder correction section below and independently re-verified in the Tester focused re-verification at the end of this file, which records the single live decision for PR #107. This line was rewritten to prose on 2026-10-16 so that the file carries exactly one live Tester decision.

## Builder test-expectation correction (2026-10-16) — e2e clip-reviews envelope aligned to CI ground truth

### C1. Root cause — one stale assertion, nine failing CI tests

The first CI e2e run of `apps/web/e2e/clip-review.spec.ts` (PR #107) executed the nine clip-review tests (TC-E2E-01/02/03 × mobile/tablet/desktop); all nine failed on one shared stale assertion — `expect(await response.json()).toEqual({ data: [] })` for `GET /api/v1/projects/{id}/clip-reviews` on an empty project — while the 75 pre-existing e2e tests passed. The CI output is ground truth: the real endpoint returns the `ClipReviewCollection` paginated resource — the standard Laravel pagination envelope wrapped in `data`: `{ "data": { "data": [], "links": { "first", "last", "next": null, "prev": null }, "meta": { "current_page": 1, "from": null, "last_page": 1, "path", "per_page": 20, "to": null, "total": 0 } } }`. Verified in source (read-only): `ClipReviewController::index` returns `response()->json(['data' => new ClipReviewCollection(...)])` and `ClipReviewCollection::toArray` serializes exactly `data`, `links`, `meta`. The same contract is enforced by the green CI backend `tests` job (`ClipReviewApiTest`) and consumed by the frontend `useClipReviews` hook.

### C2. Planner-ratified correction

Test-plan §6 "Correction (2026-10-16): clip-reviews network envelope — CI ground truth, not a weakening" (TC-E2E-01..04 rows corrected) and plan.md Phase 7.4/7.8 literals. Envelope-shape fix only: empty-collection semantics and the no-leak intent are preserved — NOT a weakening; no backend/production change is warranted (reshaping the verified API contract would be unjustified scope).

### C3. Diff applied — `apps/web/e2e/clip-review.spec.ts` only

The three scenario assertion sites (four physical occurrences: TC-E2E-01; TC-E2E-02's first and second requests; TC-E2E-03) were corrected to the ratified envelope criteria, each response body parsed exactly once (`firstBody`/`secondBody` in TC-E2E-02):

```ts
const body = await clipReviewsResponse.json();
expect(Object.keys(body.data).sort()).toEqual(['data', 'links', 'meta']);
expect(body.data.data).toEqual([]);
expect(body.data.meta.total).toBe(0);
expect(body.data.meta.last_page).toBe(1);
expect(body.data.links.next).toBeNull();
expect(body.data.links.prev).toBeNull();
```

The `Object.keys(body.data)` check mechanizes the ratified TC-E2E-04 no-leak clause ("`data.data` (review items), `data.links`, `data.meta` only; no `storage_disk`, `storage_key`, internal IDs, bucket names"), replacing the leak protection the old exact-equality assertion provided. Two comments describing the envelope were updated to the pagination shape with the no-leak wording kept (TC-E2E-01: "envelope contains only the data array" → "standard pagination shape only — data.data, data.links, data.meta"; the flow comment's `(200, {"data": []})` → `(200, standard pagination envelope)`). No scenario logic, selectors, waits, or other assertions were touched; no other file was modified by the Builder (`git status` shows only this spec plus the Planner's own pre-existing ratified `plan.md`/`test-plan.md` literals in the working tree).

### C4. Local verification (2026-10-16, this session)

- `cd apps/web && npx playwright test --list` → Total: 84 tests in 5 files; all 9 clip-review tests still discovered (TC-E2E-01/02/03 at clip-review.spec.ts:176/227/291 × mobile/tablet/desktop). Filtered `npx playwright test e2e/clip-review.spec.ts --list` → Total: 9 tests in 1 file.
- `npm test` (Vitest) → Test Files 17 passed (17), Tests 260 passed (260) — unchanged, as expected: the e2e spec is outside the Vitest suite.
- `npm run lint` (oxlint) → green; only the pre-existing React Compiler warnings in `src/features/clip-review/components/` (VideoPlayer, FeedbackForm, ReviewPage); zero diagnostics for the changed spec.
- `npm run build` (`tsc -b && vite build`) → green (87 modules transformed; built in 280ms).

### C5. Remaining authoritative verification

Full e2e execution remains CI-authoritative (plan 7.8 venue matrix; R6/R8: the local sandbox cannot serve the app — no APP_KEY): the CI e2e re-run on PR #107 must pass all 9 clip-review tests alongside the 75 pre-existing e2e tests. Since the corrected expectations state the same contract the green backend `tests` job already enforces, the re-run is the binding confirmation, not a new unknown.

### C6. Supersession of historical entries (record not rewritten)

The historical `{ data: [] }` / `{"data": []}` expectations recorded above — §7.4's TC-E2E-01/TC-E2E-02 bullets (lines ~404–405) and R8 pre-merge obligation item 2 (line ~626) — are superseded by this correction and by the Planner's test-plan §6 correction note. The binding envelope criteria are now: `data.data === []` AND `meta.total === 0`, plus `meta.last_page === 1`, `links.next`/`links.prev === null`, and the `data.data`/`data.links`/`data.meta`-only no-leak composition. The historical record is intentionally left as written.

## Phase 7 — Tester Focused Re-verification (2026-10-16): CI-failure correction of the clip-reviews e2e envelope

Executed 2026-10-16 by the independent Tester on the working tree at branch `@carlosegoulart/106/feat/clip-review-experience` (HEAD `52931f9` plus uncommitted changes). Scope: the Planner-ratified correction of the clip-reviews network-envelope expectation (test-plan §6 "Correction (2026-10-16)" note and TC-E2E-01..04 rows; plan 7.4/7.8 literals) and the Builder's C1–C6 application. Every command below was executed fresh by the Tester this session; Builder C-section claims were re-executed or verified against the actual diff and backend source, not trusted. This section supersedes the Phase 7 re-review decision (rewritten to prose above) and holds the single live Tester decision for PR #107 (file-final line). A previous Tester attempt at this focused re-verification was interrupted by a server restart before any evidence was recorded; this section is the complete re-verification.

### V1. Ratified contract vs. applied diff — exact match at all four sites

`git diff apps/web/e2e/clip-review.spec.ts` (complete diff: +33/−7, four hunks, verified via `git diff --numstat`) contains exactly the four physical assertion sites the ratification covers and nothing else: TC-E2E-01 (spec lines 199–205, `body`), TC-E2E-02's first request (240–246, `firstBody`), TC-E2E-02's second request (274–280, `secondBody`), and TC-E2E-03 (304–310, `body`); test declarations at 176/227/291. Each site parses its response body exactly once and asserts precisely the ratified criteria: `Object.keys(body.data).sort()` equals `['data', 'links', 'meta']`; `body.data.data` `toEqual([])` (exact empty array); `meta.total === 0`; `meta.last_page === 1`; `links.next` and `links.prev` both null — the complete TC-E2E-01 step-5 criteria verbatim, applied identically at the TC-E2E-02 and TC-E2E-03 sites (covering their ratified rows, which require "the same pagination envelope — empty `data.data`, `meta.total === 0`"). Targeted grep confirms zero residual `toEqual({ data: [] })` in the spec.

The `Object.keys` composition check is the Builder-disclosed judgment call mechanizing the ratified TC-E2E-04 API-Responses clause ("body is the standard pagination envelope — `data.data` (review items), `data.links`, `data.meta` only; no `storage_disk`, `storage_key`, internal IDs, bucket names"), replacing the leak protection the old exact-equality assertion carried. Verified against backend source truth (read-only): `ClipReviewController::index` returns `response()->json(['data' => new ClipReviewCollection(...)])` — the top level is constructed as exactly one `data` key — and `ClipReviewCollection::toArray` serializes exactly `data` (transformed `ClipReviewResource` items), `links` (`first`/`last`/`prev`/`next`), and `meta` (`current_page`/`from`/`last_page`/`path`/`per_page`/`to`/`total`). The asserted key set is therefore source-exact, and with `data.data` exactly `[]` there are no review items that could carry leaked fields. The same contract is enforced by the CI-green backend `tests` job (`ClipReviewApiTest`: envelope structure including `links`/`meta`; empty case `data.data` count 0) and consumed by the frontend (`ClipReviewListResponse` = `data`/`links`/`meta` behind `fetchClipReviews`).

### V2. No weakening, no collateral change

- Nothing was weakened relative to the ratified contract: the empty collection is enforced three ways (exact `[]` equality on `data.data`, `meta.total === 0`, `last_page === 1` with null `prev`/`next` links), and no-leak is enforced by the exact key composition of the envelope that carries the items. The old exact-equality assertion was not stronger protection — it asserted a body shape the real API never produces (all nine CI failures prove it) — and the test-plan §6 note explicitly ratifies the composition mechanization as "NOT a weakening".
- No collateral change: the complete diff contains only the four assertion blocks plus two envelope comments (TC-E2E-01's flow comment and envelope comment, no-leak wording preserved). All scenario logic, selectors, waits, helpers, and the TC-E2E-04 collectors (per-test console/pageerror/unexpected-4xx with the anonymous `GET /api/v1/auth/me` 401 as the single whitelisted 4xx; empty localStorage/sessionStorage) are byte-identical to the state ratified in R1/R4 — verified by full diff read and targeted grep.
- The Planner-owned bundle diffs (`plan.md` 7.4/7.8 literals, +2/−2; `test-plan.md` §2 rows, §6 correction note, TC-E2E-01..04 rows, +23/−6) contain exactly the ratified 2026-10-16 correction literals and nothing else — verified by full diff read; no Builder fingerprints in Planner-owned files.

### V3. Local re-runs — all Builder C4 numbers reproduced exactly

```bash
cd apps/web && npx playwright test --list
# → Total: 84 tests in 5 files; all 9 clip-review tests discovered
#   (clip-review.spec.ts:176/227/291 × [mobile|tablet|desktop])

cd apps/web && npx playwright test e2e/clip-review.spec.ts --list
# → Total: 9 tests in 1 file

cd apps/web && npm test
# → Test Files 17 passed (17); Tests 260 passed (260) — unchanged, as expected (e2e spec is outside Vitest)

cd apps/web && npm run lint
# → oxlint exit 0; 14 warnings — the identical pre-existing profile
#   (VideoPlayer.tsx ×3, FeedbackForm.tsx ×7, ReviewPage.tsx ×4); zero diagnostics for the changed spec

cd apps/web && npm run build
# → tsc -b && vite build green; 87 modules transformed (built in 684ms)
```

### V4. Scope and decision-contract

`git status --porcelain`: exactly the four expected paths — `apps/web/e2e/clip-review.spec.ts` (Builder correction), `specs/106-clip-review-foundation/evidence.md` (Builder C1–C6 plus this Tester section), and the Planner-owned `plan.md`/`test-plan.md` ratified corrections. Zero changes to `apps/api/**`, `apps/web/src/**`, `tests/governance/**`, `scripts/merge_gate.py`, `.github/workflows/**`, Docker, or `.opencode/**`; no branch, commit, push, or PR/issue operations. Decision-contract mechanics were re-verified against the actual validators before editing: `validators.py` defines the two decision forms as plain substring constants (`DECISION_APPROVE` / `DECISION_REJECT`, lines 30–31), and `validate_evidence_decision` plus `validate_merge_approval` — the latter invoked by `pr_enforcement.py` on this very file, located via the branch's issue number — fail when both forms or neither appears anywhere in the content; `scripts/merge_gate.py::DECISION_RE` is instead line-anchored and case-insensitive (last-wins; its exactly-one correction is the known human-gated item). The superseded Phase 7 decision above was therefore rewritten to prose embedding neither form (same convention as the Phase 6 supersession), and this Tester section documents the mechanisms by constant name only, so the file ends with exactly one live decision line. Post-edit verification: grep confirms exactly one occurrence of the approve-form constant (the file-final line) and zero occurrences of the reject-form constant anywhere in the file; the governance suite re-run (`python -m unittest discover -s tests/governance -p 'test_*.py'`) shows zero new failures — only the identical pre-existing human-gated skills-count item (`test_governance.py::test_g5_six_skills_parse`, plan 6.6), matching R7's baseline.

### V5. Binding pre-merge CI obligations (unchanged venue; re-run now a confirmation, not an unknown)

Full e2e execution remains CI-authoritative per the ratified plan 7.8 venue matrix; the local sandbox still cannot serve the app (R6 diagnosis unchanged: no APP_KEY — `MissingAppKeyException` on the served session probe; every agent-available injection mechanism was permission-denied), and no local e2e result is fabricated here. The binding obligations for the Orchestrator before merge:

1. **CI e2e job re-run on PR #107**: all 9 clip-review tests green across mobile/tablet/desktop alongside the 75 pre-existing e2e tests. The corrected expectations state the same envelope contract the green CI backend `tests` job already enforces (`ClipReviewApiTest`), so this re-run is the binding confirmation of a verified contract.
2. **CI backend `tests` job**: the unchanged R8 obligation — all six `real-integration` tests selected and executed with zero skips against real PostgreSQL + MinIO, full backend suite green on the CI environment.
3. **Human-gated governance corrections** (plan 6.6, unchanged): the skills-count test (6→8) and the `merge_gate.py` exactly-one-decision contract; `governance` and `pr-enforcement` checks green before merge.

The previous CI failure demonstrates the lifecycle guard works: the failed run blocked the merge, the Planner ratified the ground-truth correction, and this re-verification closes the loop. The Orchestrator must watch the pushed CI re-run.

### V6. Verdict on the correction

The root cause was one stale expectation asserting a body shape the real API never produces; the Planner ratified the ground-truth correction (test-plan §6 note, TC-E2E-01..04 rows; plan 7.4/7.8), and the Builder applied it exactly: four sites, one body parse per response, comments updated with the no-leak wording preserved, zero collateral change, zero weakening of empty-collection or no-leak semantics — corroborated by backend source truth, the CI-green backend job's own assertions, and the frontend consumer type. Every locally-executable mandatory verification passes and reproduces the Builder's numbers exactly; the CI e2e re-run remains the binding pre-merge obligation per the ratified venue matrix, enforced by the merge lifecycle.

## Builder production-transport fix (2026-10-16) — clip-review list unwrap aligned to CI ground truth

Second CI e2e run on PR #107 (HEAD `00ee618`, after the envelope-expectation correction re-verified in the section above): the pagination-envelope network assertions passed on all nine clip-review tests — `GET /api/v1/projects/{id}/clip-reviews` returned 200 with the real body `{ "data": { "data": [], "links": { "first", "last", "next": null, "prev": null }, "meta": { ..., "total": 0 } } }` — yet all nine tests still failed one step later at `expectEmptyReviewSection` (`apps/web/e2e/clip-review.spec.ts:167`, `locator('.review-header')` not found), while the 75 pre-existing e2e tests passed. The API call succeeded and the real envelope arrived; the defect was in the UI render path. This section records the Builder's TDD diagnose-and-fix of that production defect (RED → GREEN → refactor evaluated), executed 2026-10-16 on branch `@carlosegoulart/106/feat/clip-review-experience`.

### D1. Root-cause chain (verified in source, exact references)

- Backend (read-only): `ClipReviewController::index` (`apps/api/app/Http/Controllers/Api/V1/ClipReviewController.php:32-34`) returns `response()->json(['data' => new ClipReviewCollection($clipReviews)])` — the pagination collection wrapped in a top-level `data` key. The three single-item endpoints (`show` line 44, `updateStatus` line 67, `submitClipReviewFeedback` line 80) return `['data' => new ClipReviewResource(...)]`, which matches the frontend's `{ data: ClipReview }` contracts — only the LIST endpoint is double-wrapped, so only it needed a fix.
- Frontend type: `apps/web/src/features/clip-review/types.ts:19-36` declares `ClipReviewListResponse = { data: ClipReview[]; links; meta }` — the pagination object at top level, one nesting level short of the wire format.
- Transport (pre-fix): `apps/web/src/features/clip-review/api.ts:49-51` returned the raw JSON typed as `ClipReviewListResponse`, so `useClipReviews().data` (`hooks.ts:10-19`, queryFn at line 13) was the raw envelope and `clipReviewsResponse.data` was the pagination OBJECT, not the array.
- Consumer crash: `ReviewPage.tsx:60` (`const clipReviews = clipReviewsResponse?.data || [];`) bound that object; the first throw in the reachable empty-state scenario is `ReviewPage.tsx:91` — `TypeError: clipReviews.find is not a function` — during render. The deep-link `clipReviews.some(...)` at line 67 is the same defect class (array method invoked on the pagination object) but is never reached pre-fix: the render at line 91 throws first, before the effect at lines 64-76 can run. With no error boundary anywhere in the production tree (`main.tsx → App → AuthProvider/QueryClientProvider → AuthenticatedShell → ProjectMediaSection → ReviewPage`), React unmounts the whole tree, so `.review-header` never renders — exactly the CI symptom.
- Why Vitest stayed green while CI failed: the only transport-level stub, `apps/web/src/__tests__/ClipReviewSection.test.tsx:36` (pre-fix), stubbed the list body as the divergent unwrapped `{ "data": [] }`, so `clipReviewsResponse.data` was an array and the component never saw the real envelope. `ReviewPage.test.tsx` mocks the hooks module (`vi.mock('../hooks')` line 25; `mockLoaded` lines 102-108) with `data: { data: clips, links: {}, meta: {} }` — precisely the POST-fix hook contract (`ClipReviewListResponse`) — so it exercises the component but never the transport; it was checked per instruction and correctly requires no change (wrapping its mock would bypass the fixed transport and fail permanently). No clip-review hooks test exists (repo grep: `fetchClipReviews` is referenced only by `hooks.ts` and its definition in `api.ts`), so no other stub site exists.

### D2. RED — transport stub aligned to the real contract

Only the stub body and two comments changed in `apps/web/src/__tests__/ClipReviewSection.test.tsx`; every assertion is byte-identical (no weakening, no new tests — the suite count stays 260). The list stub now returns the real envelope, mirroring the CI-authoritative body:

```ts
if (target === '/api/v1/projects/1/clip-reviews') {
  return json(200, {
    data: {
      data: [],
      links: { first: 'http://localhost/api/v1/projects/1/clip-reviews?page=1',
               last: 'http://localhost/api/v1/projects/1/clip-reviews?page=1',
               prev: null, next: null },
      meta: { current_page: 1, from: null, last_page: 1,
              path: 'http://localhost/api/v1/projects/1/clip-reviews',
              per_page: 20, to: null, total: 0 },
    },
  });
}
```

Run: `cd apps/web && npx vitest run src/__tests__/ClipReviewSection.test.tsx src/features/clip-review` → **Test Files 1 failed | 5 passed (6); Tests 1 failed | 72 passed (73); Errors 1 error; exit code 1.** The failing test is the production-topology mount test, failing exactly like CI: `TestingLibraryElementError: Unable to find an element with the text: Select a clip to review` with the tree unmounted (`<body><div /></body>`), React reporting "An error occurred in the <ReviewPage> component. Consider adding an error boundary to your tree", plus the uncaught production exception `TypeError: clipReviews.find is not a function` at `src/features/clip-review/components/ReviewPage.tsx:91:42`. Valid assertion-based RED: the failure is the missing production behavior (transport unwrap), not an environment error. The other 72 tests — including `ReviewPage.test.tsx` — passed, confirming the defect is isolated to the transport layer.

### D3. GREEN — minimal production fix

`apps/web/src/features/clip-review/api.ts`, `fetchClipReviews` only — unwrap the top-level wrapper, keep `ClipReviewListResponse` so every consumer keeps working (`ReviewPage` keeps reading `clipReviewsResponse.data` as the array):

```ts
export async function fetchClipReviews(projectId: number): Promise<ClipReviewListResponse> {
  const json = await fetchWithCsrf<{ data: ClipReviewListResponse }>(
    `${API_BASE}/projects/${projectId}/clip-reviews`
  );
  return json.data;
}
```

No component, hook, type, backend, or e2e-spec change; the single-item API functions are untouched. Re-run of the RED command → **Test Files 6 passed (6); Tests 73 passed (73)**, zero unhandled errors.

### D4. Refactor — evaluated, no change needed

The GREEN implementation is already minimal (one function, one unwrap, one contract comment citing the controller wrap); it introduces no duplication or quality debt, and `ClipReviewListResponse`/consumers are intentionally unchanged per the authorized minimal scope. The full suite remained green on the post-fix evaluation run (the 260/260 result below).

### D5. Local verification (2026-10-16, this session)

- `npx vitest run src/__tests__/ClipReviewSection.test.tsx src/features/clip-review`: 6 files / 73 tests passed (D3 GREEN).
- `npm test`: **Test Files 17 passed (17); Tests 260 passed (260)** — identical count to the pre-fix baseline.
- `npm run lint` (oxlint): exit 0; 14 warnings — the identical pre-existing profile (VideoPlayer.tsx ×3, FeedbackForm.tsx ×7, ReviewPage.tsx ×4); zero diagnostics for the two changed files.
- `npm run build` (`tsc -b && vite build`): green — 87 modules transformed, built in 822ms.
- `npx playwright test --list`: Total 84 tests in 5 files; all 9 clip-review tests still discovered (TC-E2E-01/02/03 at clip-review.spec.ts:176/227/291 × mobile/tablet/desktop). Focused `npx playwright test e2e/clip-review.spec.ts --list`: Total 9 tests in 1 file. The e2e spec itself is untouched — its assertions already state the real contract.
- Scope check (`git status`): exactly two application paths changed — `apps/web/src/features/clip-review/api.ts` and `apps/web/src/__tests__/ClipReviewSection.test.tsx` (plus this evidence file). `docs/project-state.md` was already dirty in the working tree before this session and was not touched.

### D6. Remaining authoritative verification and decision-line scoping

Full e2e execution remains CI-authoritative (unchanged venue; the local sandbox still cannot serve the app — no APP_KEY, the R6/V5 diagnosis): the CI e2e job on PR #107 must now pass all 9 clip-review tests (the empty review section rendering after the real envelope arrives) alongside the 75 pre-existing e2e tests; the CI backend `tests` job obligations are unchanged. The file-final decision line below is the Phase 7 Tester re-verification's recorded approval of the e2e envelope-expectation correction at HEAD `00ee618`; it predates this production-transport fix and does not cover it — this fix requires fresh independent Tester verification before merge. Recorded for future Planner consideration (out of this issue's authorized scope, not implemented): no transport-level test covers a populated clip-review list — the e2e environment cannot produce ClipReview rows (the documented "Known Limitations and Coverage Boundary") and the vitest transport test mirrors the reachable empty state — so populated-list rendering is covered only at the hook boundary in `ReviewPage.test.tsx`.

**Superseded decision (historical record only): the 2026-10-16 Phase 7 focused re-verification of the e2e envelope-expectation correction recorded an approval at HEAD `00ee618`.** As D6 itself records, that approval covered the envelope-expectation correction only and predates the production-transport fix (D1–D6) — it never covered this fix. It was rewritten to prose on 2026-10-16 by the Tester section below so that this file carries exactly one live Tester decision, which is the file-final line of that section.

---

## Phase 7 — Tester Focused Re-verification (2026-10-16): production list-transport unwrap (second CI e2e failure)

Executed 2026-10-16 by the independent Tester on the working tree at branch `@carlosegoulart/106/feat/clip-review-experience` (HEAD `00ee618` plus uncommitted changes). Scope: the Builder's TDD fix (D1–D6) for the production defect the second CI e2e run exposed — all nine clip-review tests failing at `expectEmptyReviewSection` (`locator('.review-header')` not found) although the pagination-envelope network assertions had passed on all nine, because the real list envelope crashed the frontend render path. Every command below was executed fresh by the Tester this session; Builder D-section claims were re-executed or verified against the actual diffs and backend source, not trusted. This section supersedes the prior approval (rewritten to prose above) and holds the single live Tester decision for PR #107 (file-final line).

### W1. Fix contract vs. CI ground truth — exact match, minimally scoped

The complete diff of `apps/web/src/features/clip-review/api.ts` is one hunk changing only `fetchClipReviews`: the raw `return fetchWithCsrf<ClipReviewListResponse>(...)` becomes `const json = await fetchWithCsrf<{ data: ClipReviewListResponse }>(...)` → `return json.data`, plus the contract comment. Verified against backend source truth (read-only): `ClipReviewController::index` (lines 32–34) returns `response()->json(['data' => new ClipReviewCollection(...)])` — the top-level `data` wrap the second CI run observed on the wire — and `ClipReviewCollection::toArray` (lines 12–31) serializes exactly `data` (transformed `ClipReviewResource` items), `links` (`first`/`last`/`prev`/`next`), and `meta` (`current_page`/`from`/`last_page`/`path`/`per_page`/`to`/`total`). Post-fix, `fetchClipReviews` returns the pagination object `{ data: ClipReview[], links, meta }` — exactly the unchanged `ClipReviewListResponse` (`types.ts:19–36`) — so every consumer keeps its contract: `useClipReviews`' queryFn (`hooks.ts:13`) and `ReviewPage.tsx:60` (`clipReviewsResponse?.data || []`) now genuinely receive the items array at `.data`.

Single-item endpoints verified untouched and still source-correct: controller `show` (lines 43–45), `updateStatus` (66–68), and `storeFeedback` (79–81) all return `['data' => new ClipReviewResource(...)]`, matching the unchanged frontend `{ data: ClipReview }` contracts of `fetchClipReview` / `updateClipReviewStatus` / `submitClipReviewFeedback` (api.ts lines 60–82, context-identical in the diff). Only the LIST endpoint is double-wrapped, so only it needed the unwrap — no over-fix, no under-fix.

### W2. Test-side delta — stub only, assertions byte-identical

The complete diff of `apps/web/src/__tests__/ClipReviewSection.test.tsx` contains exactly two hunks: the list-stub body (the divergent unwrapped `{ "data": [] }` replaced by the real wrapped envelope — `data.data: []`, `links` with `first`/`last`/`prev: null`/`next: null`, `meta` with all seven pagination fields — mirroring the CI-observed body field-for-field) and a two-line comment update describing the envelope. Every `expect(...)` line appears as unchanged context; the only removed lines are the old one-line stub and the two old comment lines — zero assertion edits, and the suite count stays 260. The transport-level test now states the same CI-ground-truth contract the e2e asserts on the wire and the CI-green backend job enforces (`ClipReviewApiTest`), so the divergent-stub blind spot that kept Vitest green while CI crashed is closed. `ReviewPage.test.tsx` was correctly left alone: it mocks the hooks module with precisely the post-fix hook contract (`data: { data: clips, links: {}, meta: {} }`) and never exercises the transport — wrapping its mock would bypass the fixed transport and fail permanently. No component, hook, type, backend, or e2e-spec file is in the working-tree delta.

### W3. RED honesty — failure class independently confirmed (structural reproduction)

The stub change is the only test delta (W2), so the RED basis is exact. Reverting the api.ts fix (per the sanctioned structural route — the Tester does not modify production code) restores the raw return typed one nesting level short; with the new stub, `useClipReviews().data` is the raw envelope `{ data: { data: [], links, meta } }`; `ReviewPage.tsx:60` then binds `clipReviews = clipReviewsResponse?.data || []` → the pagination object (truthy, so the `|| []` fallback never fires); `ReviewPage.tsx:91` (`clipReviews.find(...)`) sits in the render body and executes during render — before the effect at lines 64–76 whose `clipReviews.some` at line 67 is the same defect class — and throws `TypeError: clipReviews.find is not a function`. With no error boundary in the production tree, React unmounts the whole tree, `.review-header` never renders, and e2e `expectEmptyReviewSection` (spec line 167) fails at its first locator — the exact CI symptom on all nine tests. The recorded RED (1 failed / 72 passed; the production-topology mount test failing with the unmounted `<body><div /></body>`, React's error-boundary advice, and the uncaught TypeError at `ReviewPage.tsx:91:42` — the line verified verbatim in the working tree) is therefore the honest production crash: an assertion-based RED for the missing production unwrap, not an environment failure. The GREEN reproduction below closes the loop.

### W4. Local re-runs — every Builder D5 number reproduced exactly

```bash
cd apps/web && npx vitest run src/__tests__/ClipReviewSection.test.tsx src/features/clip-review
# → Test Files 6 passed (6); Tests 73 passed (73)

cd apps/web && npm test
# → Test Files 17 passed (17); Tests 260 passed (260) — identical to the pre-fix baseline

cd apps/web && npm run lint
# → oxlint exit 0; 14 warnings — the identical pre-existing profile
#   (VideoPlayer.tsx ×3, FeedbackForm.tsx ×7, ReviewPage.tsx ×4); zero diagnostics for the changed files

cd apps/web && npm run build
# → tsc -b && vite build green; 87 modules transformed

cd apps/web && npx playwright test --list
# → Total: 84 tests in 5 files; all 9 clip-review tests discovered
#   (clip-review.spec.ts:176/227/291 × [mobile|tablet|desktop]); 84 − 9 = 75 pre-existing

cd apps/web && npx playwright test e2e/clip-review.spec.ts --list
# → Total: 9 tests in 1 file
```

### W5. Scope, governance, and decision contract

- `git status --porcelain`: exactly the four expected paths — `apps/web/src/features/clip-review/api.ts`, `apps/web/src/__tests__/ClipReviewSection.test.tsx`, `specs/106-clip-review-foundation/evidence.md`, and `docs/project-state.md`. The last was verified read-only to be the Orchestrator's documentation update (M7 Slice 1 decisions/capabilities/limitations prose; no code, no Builder fingerprints) — a pre-existing dirty file outside this review's scope. Zero changes to `apps/api/**`, other `apps/web/src/**` files, the e2e spec, `tests/governance/**`, `scripts/merge_gate.py`, `.github/workflows/**`, Docker, or `.opencode/**`; no branch/commit/push/PR/issue operations.
- Governance re-run: `python -m unittest discover -s tests/governance -p 'test_*.py'` → **Ran 173 tests, FAILED (failures=1)** — the single failure is the known human-gated `test_governance.py::test_g5_six_skills_parse` (expects 6 skills; 8 exist per AGENTS.md §16), matching the R7/V4 baseline exactly; the argparse usage strings in the output belong to passing merge-gate CLI tests. Zero new failures from this delta.
- Decision-contract mechanics re-verified before editing: `validators.py` (lines 30–31) defines the two decision forms as plain substring constants (referenced here by constant name only, as in V4), and `validate_evidence_decision` / `validate_merge_approval` fail on both-or-neither; `scripts/merge_gate.py::DECISION_RE` is line-anchored, last-wins. Pre-edit grep confirmed exactly one decision literal in this file (the former line 805 — the approval that D6 records as predating this transport fix). That line was rewritten to prose embedding neither constant (the established convention), so this file ends with exactly one live decision line.

### W6. Binding pre-merge CI obligations (unchanged venue matrix; the re-run is a confirmation, not an unknown)

Full e2e execution remains CI-authoritative per the ratified plan 7.8 venue matrix; the local sandbox still cannot serve the app (the unchanged R6/V5 diagnosis — no APP_KEY for the served Laravel, every agent-available injection mechanism permission-denied), and no local e2e result is fabricated here. The binding obligations for the Orchestrator before merge:

1. **CI e2e job re-run on PR #107**: all 9 clip-review tests green across mobile/tablet/desktop alongside the 75 pre-existing e2e tests. The second CI run already proved the pagination envelope arrives on the wire and the e2e network assertions pass on all nine tests; this fix makes the frontend parse exactly that envelope, so the re-run confirms a contract now verified end-to-end (backend source → wire → transport → hook → render).
2. **CI backend `tests` job**: the unchanged R8/V5 obligation — all six `real-integration` tests selected and executed with zero skips against real PostgreSQL + MinIO; full backend suite green on the CI environment.
3. **Human-gated governance corrections** (plan 6.6, unchanged): the skills-count test (6→8) and the `merge_gate.py` exactly-one-decision contract; `governance` and `pr-enforcement` green before merge.

The lifecycle guard has now caught two real CI failures; each was root-caused, Planner-ratified where the plan itself was wrong, fixed with TDD, and independently re-verified. The Orchestrator must watch the pushed CI re-run.

### W7. Verdict on the fix

The CI-exposed production defect is genuinely fixed, not papered over: the root cause (the frontend transport typed one nesting level short of the real list envelope) is corrected at the exact layer where it lived, scoped to the single divergent endpoint, with the single-item contracts verified untouched against the backend source; the transport-level test now states the CI-ground-truth envelope, so the stub/production divergence that masked the defect cannot silently recur; no assertion was weakened anywhere; every locally-executable mandatory verification passes and reproduces the Builder's numbers exactly; the full Playwright run remains the binding pre-merge CI obligation per the ratified venue matrix, enforced by the merge lifecycle.

**Superseded decision (historical record only): the 2026-10-16 Phase 7 focused re-verification of the production list-transport unwrap (second CI e2e failure) recorded an approval covering that fix at HEAD `00ee618` and the overall PR regression state, with the full Playwright run as a binding pre-merge CI obligation.** As §E7 of the Builder section below records, that approval predates the e2e spec-conformance correction applied after the third CI e2e run and does not cover that delta. It was rewritten to prose on 2026-10-16 by the Tester section at the end of this file so that the file carries exactly one live Tester decision, which is the file-final line of that section.

---

## Builder e2e spec-conformance correction (2026-10-16) — empty-list attachment, exact back-control locator, console-mirror whitelist

Third CI e2e run on PR #107 (HEAD `82dda6f`, after the transport fix verified above): the frontend transport fix WORKED — on all nine clip-review tests the pagination-envelope network assertions passed AND the review section rendered, so `.review-header` containing the project name, "No clips", "Select a clip to review", and the "Clips to Review" heading all passed (the run-2 failure class is gone). All nine tests then failed at exactly one remaining assertion — pre-edit `apps/web/e2e/clip-review.spec.ts:171`, `getByRole('list', { name: 'Clip review list' })` expected visible:

```
14 × locator resolved to <div role="list" class="space-y-3" aria-label="Clip review list"></div>
- unexpected value "hidden"
```

Because Playwright aborts each test at its first failed assertion, every downstream assertion in all three scenarios had never executed. This section records the conformance fix for that assertion and the static + empirical audit of every downstream assertion, executed 2026-10-16 on branch `@carlosegoulart/106/feat/clip-review-experience`. Only `apps/web/e2e/clip-review.spec.ts` (plus this evidence file) changed; the component, production, backend, and all governance/planner files are untouched.

### E1. Root cause — zero-size empty list vs the Playwright visibility definition; the ratified wording

`ReviewPage.tsx:250` renders `<div className="space-y-3" role="list" aria-label="Clip review list">`; with zero clip reviews the div renders EMPTY — an empty bounding box. Playwright visibility is defined as a non-empty bounding box, so `toBeVisible()` correctly reported the attached element as hidden. The component behaves exactly as the ratified contract specifies.

The ratified test-plan TC-E2E-01 step 4 reads: "sidebar heading 'Clips to Review' renders **with an empty list**" — it requires the empty list to RENDER (be attached) with zero items, not to satisfy Playwright's visibility definition. The e2e `toBeVisible()` over-interpreted the ratified wording. `toBeAttached()` (available in @playwright/test 1.63.0, the installed version per `npm ls`) is the assertion matching "renders with an empty list". No component change is warranted; the assertion was conformed to the already-ratified contract:

```ts
// TC-E2E-01 step 4 (ratified): the sidebar heading "Clips to Review"
// renders "with an empty list" — the list must RENDER with zero items.
// A zero-item list renders as an attached but empty div with an empty
// bounding box, so Playwright visibility (non-empty bounding box) does
// not apply to this element; attachment plus zero listitems is the
// rendered-empty-list contract.
await expect(page.getByRole('list', { name: 'Clip review list' })).toBeAttached();
await expect(page.getByRole('listitem')).toHaveCount(0);
```

`grep "Clip review list"` confirms a single occurrence in the spec (the helper, now line 195) — the pattern does not repeat elsewhere. The zero-items companion assertion is byte-identical to before.

### E2. Downstream audit — two more latent failures found and fixed with the same conformance rationale

The static cross-check of every remaining assertion against the real markup/CSS (E5 table) plus an empirical probe of three Playwright 1.63 behaviors (E3) found exactly two more failures-in-waiting that the aborted CI runs could never reach. Both are fixed in the same spec, with no weakening of any assertion's semantics.

**(a) Strict-mode ambiguity: 'Back to project' vs 'Back to projects' (clicks, pre-edit lines 218/285).** While the review tab is active, both controls coexist in the DOM: `ProjectMediaSection.tsx:35` (`aria-label="Back to projects"`, the shell-level back control) and `ReviewPage.tsx:165` (`aria-label="Back to project"`, the review section's back control). Playwright role-name matching is case-insensitive substring by default, so `getByRole('button', { name: 'Back to project' })` resolves to BOTH buttons ("Back to project" is a substring of "Back to projects") and `.click()` throws a strict mode violation — probe-verified (E3, PROBE2: ambiguous count 2, click rejected with "strict mode violation … resolved to 2 elements"; `exact: true` count 1). Both click sites now pass `exact: true`, which targets the same ratified control the test plan names ("Click the review section's 'Back to project' control", TC-E2E-02 step 5) — same element, same action, same downstream assertions; only the locator's over-broad default matching is narrowed. The unit suite's `getByRole('button', { name: 'Back to project' })` (`ReviewPage.test.tsx:142/164/291`) never hits this because RTL name matching is whole-string by default; the e2e locator now encodes the same intent.

**(b) Console mirror of the whitelisted anonymous 401 (`expectClean`, all nine tests).** Every test starts signed out at `/`, and `useAuth` calls `getMe()` on mount (`apps/web/src/features/auth/hooks/index.tsx:64`) → `GET /api/v1/auth/me` → 401, the single whitelisted 4xx of the flow (response whitelist, spec lines 96–107). Chromium mirrors every non-2xx response into the console as a browser-generated network log line, and Playwright delivers it through `page.on('console')` — probe-verified (E3, PROBE1): `{"type":"error","text":"Failed to load resource: the server responded with a status of 401 (Unauthorized)","url":"http://127.0.0.1:PORT/api/v1/auth/me"}`. Without a filter, `expectClean`'s `consoleProblems` assertion would fail all nine tests on the very event the ratified whitelist expects. The console collector now skips exactly that mirror — a message must be type `error`, start with `Failed to load resource: the server responded with a status of 401`, and have a `location().url` ending in `/api/v1/auth/me` to be skipped. This is conformance to the ratified whitelist, not a weakening: the console line is the browser's own log of the same whitelisted response; the response-side whitelist still fails the run if this URL is anything but a GET 401, and every other console error or warning remains fatal (the filter cannot mask any non-whitelisted condition).

### E3. Empirical probe — three load-bearing Playwright 1.63 behaviors, verified against the installed runner

The audit's two uncertain behaviors (name-matching semantics, console delivery of network errors) and the E1 root cause were verified empirically rather than assumed, using a throwaway three-test Playwright probe (self-contained `node:http` fixture + `setContent` markup mirroring the app's real DOM) run against the repo-installed `@playwright/test@1.63.0` and the locally cached `chromium-1243`. The probe lived in a scratch directory, was executed once, and was then removed from the tree (verified absent; it is not part of the working tree). Key output, verbatim:

```
PROBE1 console messages: [{"type":"error","text":"Failed to load resource: the server
  responded with a status of 401 (Unauthorized)","url":"http://127.0.0.1:45477/api/v1/auth/me"}]
PROBE2 ambiguous count: 2          # getByRole('button', { name: 'Back to project' })
PROBE2 reverse count: 1            # getByRole('button', { name: 'Back to projects' })
PROBE2 exact count: 1              # ... { name: 'Back to project', exact: true }
PROBE2 click rejected: true        # "strict mode violation ... resolved to 2 elements"
PROBE3 list count: 1               # empty <div role="list" aria-label="Clip review list">
PROBE3 toBeVisible rejected: true  # error contains 'hidden'
PROBE3 toBeAttached resolved: true
3 passed (14.5s)
```

### E4. Not changed, and why

- No component change: `ReviewPage` conforms to the ratified contract (the empty list renders with zero items; `toBeVisible` on a zero-size element is a Playwright-definition mismatch, not a product defect). The historical evidence description of TC-E2E-01's empty-list step as a visibility assertion (e.g., "empty `Clip review list`" in the Phase 7 evidence above) is superseded by this section's attachment-based reading of the ratified wording; the historical text is intentionally left as written.
- No change to the envelope assertions (lines 213–229, 258–266, 302–310): CI run 3 proved them passing on all nine tests.
- No change to the response whitelist, the pageerror collector, or the storage assertions: audited safe (E5 rows 15–17).
- No change to the five `FOCUS_TARGETS` CSS selectors (spec lines 39–45): attribute/ID selectors are exact by construction and all five exist verbatim in the markup.

### E5. Full downstream audit table (every assertion after the former line-171 failure, current line numbers)

| # | Assertion (spec line) | Markup/CSS evidence | Verdict |
|---|---|---|---|
| 1 | Empty list attached + zero listitems (195–196) | `ReviewPage.tsx:250` `role="list" aria-label="Clip review list"`; probe 3 (attached ✓, visible ✗ by definition) | Fixed (E1) — OK |
| 2 | `getByRole('listitem')` count 0 (196) | Only `role="listitem"` in the app: `MediaListItem.tsx:30` (media panel, unmounted while review tab active — `ProjectMediaSection.tsx:74/90` conditional panels) and `ReviewPage.tsx:270` sidebar items (render only with reviews). `ReviewList.tsx` items are unreachable (imported only by its own test + barrel export) | OK |
| 3 | Absence: `Clip video`, `Review actions` group, `Approve clip`/`Reject clip`/`Needs Changes clip`, `Feedback form` group (234–239, 278–281) | `VideoPlayer.tsx:223` `aria-label="Clip video"`; `ReviewActions.tsx:122` `role="group" aria-label="Review actions"`, `:144` `aria-label={`${label} clip`}`; `FeedbackForm.tsx:117` `role="group" aria-label="Feedback form"`; all render only when a clip is selected (`ReviewPage.tsx:185–214` ternary) — locators match how the components really announce themselves, so the count-0 assertions cannot silently rot | OK |
| 4 | `Back to project` click (246, 314) | Both back controls coexist (`ProjectMediaSection.tsx:35`, `ReviewPage.tsx:165`); probe 2: default name matching is substring → 2 elements → strict mode violation; `exact: true` → 1 | Fixed (E2a) — OK |
| 5 | `Open ${name}` visible (247, 315) | `ProjectCard.tsx:38` `aria-label={`Open ${project.name}`}`; unique on the projects list; the identical locator already passed upstream in run 3 (line 171) | OK |
| 6 | Delete flow: `Delete ${name}` → `Confirm delete ${name}` → `/No projects yet/` (248–250) | `ProjectCard.tsx:67/:50` labels; Delete and Confirm/Cancel never coexist (`ProjectCard.tsx:43–71` ternary), so the substring overlap cannot strict-violate at resolution time; `ProjectList.tsx:23` "No projects yet. Create your first project above." | OK |
| 7 | N/P keys leave URL unchanged, no `clipReviewId` (285–291) | Empty list → `selectedIndex` −1, length 0 → guards `-1 < length-1` and `selectedIndex > 0` both false (`ReviewPage.tsx:112–126`) → `writeClipReviewIdParam` unreachable; deep-link effect takes the no-param/length-0 path (`ReviewPage.tsx:64–76`); production `history.replaceState` exists only in `ReviewPage.tsx:28` (repo grep) | OK |
| 8 | Media⇄Review tab round-trip (296–299) | `ProjectMediaSection.tsx:47/:61` `#media-tab`/`#review-tab` (role `tab`, names "Media"/"Review Clips" — no substring collisions); `MediaList.tsx:22` exact text "No media assets yet. Upload a video to get started." | OK |
| 9 | No horizontal overflow (344–347) | No fixed-width review rules; `.review-title` `overflow-wrap: anywhere` (`index.css:724`); `.review-main` max-width 80rem + 16px padding (733–737); grid `1fr` + gap (739–743); header inner max-width 80rem (672–676) | OK |
| 10 | No clipped elements (351–364) | `.review-header` full-width block (`index.css:664–670`, sticky); `.review-empty` (751–757) and `.review-sidebar` (765–770) sit inside 1fr grid columns ≥ 0 and ≤ container; all three exist attached in the empty state (`ReviewPage.tsx:158/241/248`) | OK |
| 11 | Touch targets ≥ 44px (368–379) | `min-height: 44px` on `.back-button` (`index.css:540`), `.tab-navigation [role="tab"]` (578), `.review-back-button` (698), `.logout-button` (292); all five queried selectors exist verbatim in markup | OK |
| 12 | Grid reflow 1↔2 columns (385–392) | `index.css:741` `grid-template-columns: 1fr`; `:745–748` `@media (min-width: 1024px)` → `2fr 1fr`; assertion `viewportWidth < 1024 ? 1 : 2` matches viewports 390/768/1440 exactly (breakpoints identical); computed-style token count is preserved when fr resolves to px | OK |
| 13 | Focus walk + visible indicators (396–423) | DOM/tab order: back-to-projects (`ProjectMediaSection.tsx:35`) → media-tab (47) → review-tab (61) → skip link (`ReviewPage.tsx:155`, focusable, `:focus` reveal `index.css:617–635`) → review-back-button (162–170) → logout (`AuthenticatedShell.tsx:81`, rendered AFTER the media section → last focusable): 2 Shift+Tab + ≤5 Tab presses, within the 10-press budgets from either start point; `:focus-visible` `outline: 2px solid var(--accent)` for `.back-button` (558), tabs (597), `.review-back-button` (714), `.logout-button` (308) → computed `outlineWidth` 2px / `outlineStyle` solid satisfies the evaluate check | OK |
| 14 | Empty localStorage/sessionStorage (117–124) | Storage written only by `FeedbackForm.tsx` (44/52/98, draft keys) — unmounted in the empty state (`ReviewPage.tsx:199–207`); zero `sessionStorage` in src (grep); auth uses cookies, not web storage | OK |
| 15 | No console problems (113) | Zero `console.*` in `apps/web/src` (grep); React/Vite dev logs are info-level; `/favicon.svg` declared (`index.html:5`) and present (`public/favicon.svg`) → no 404 console/network line; the single console error of the flow is the whitelisted 401's mirror | Fixed (E2b) — OK |
| 16 | No uncaught page errors (114) | Run 3 reached the final assertion on all nine tests — the tree renders cleanly (a pageerror would have unmounted it, as in run 2); no throwing path in the empty-review flow | OK |
| 17 | No unexpected 4xx/5xx (115) | Only the whitelisted anonymous `GET /api/v1/auth/me` 401 (`hooks/index.tsx:64`, mounted at signed-out start); all other flow requests are 2xx/204 (register 201, csrf-cookie 204, projects 201/200, media-assets 200, clip-reviews 200, delete 2xx) | OK |
| 18 | Every other `toBeVisible` target has text/size | 'No clips' (`p.review-counter`, `ReviewPage.tsx:173–175`), 'Select a clip to review' (`p.review-empty-text`, 242), 'Clips to Review' (`h2`, 249), `/No projects yet/`, media empty text, `Open` buttons, plus all upstream CI-proven assertions (auth headings/forms/tabs, project create/open) — no other zero-size visibility candidate exists in the empty-review state | OK |

### E6. Local verification (2026-10-16, this session)

```bash
cd apps/web && npx playwright test --list
# → Total: 84 tests in 5 files; all 9 clip-review tests discovered
#   (clip-review.spec.ts:200/255/320 × [mobile|tablet|desktop]); 84 − 9 = 75 pre-existing

cd apps/web && npm test
# → Test Files 17 passed (17); Tests 260 passed (260) — unchanged baseline;
#   the e2e spec is outside Vitest scope, so unit/component results are untouched

cd apps/web && npm run lint
# → oxlint exit 0; 14 warnings — the identical pre-existing profile
#   (VideoPlayer.tsx ×3, FeedbackForm.tsx ×7, ReviewPage.tsx ×4); zero diagnostics
#   for the changed spec

cd apps/web && npm run build
# → tsc -b && vite build green; 87 modules transformed (built in 502ms)
```

### E7. Scope, supersession, and the decision contract

- `git status`: exactly one application path changed — `apps/web/e2e/clip-review.spec.ts` (plus this evidence file). Zero changes to `apps/web/src/**`, `apps/api/**`, `tests/governance/**`, `scripts/merge_gate.py`, `.github/workflows/**`, Docker, or `.opencode/**`; no branch/commit/push/PR/issue operations. The throwaway probe was removed after execution and is not part of the tree.
- Supersession (historical record, not rewritten): the earlier evidence sections describe TC-E2E-01's empty-list step with visibility phrasing ("empty `Clip review list`" asserted visible). The ratified wording ("renders with an empty list") requires attachment with zero items; the spec now asserts exactly that. Historical sections are intentionally left as written; this section supersedes their interpretation. No component behavior changed — this correction is spec-side only.
- Decision contract: this section embeds no decision literal. The file's single recorded approval (file-final line of the previous section) covers the production list-transport unwrap at HEAD `00ee618` and PREDATES this e2e spec-conformance correction — it does not cover this delta. Fresh independent Tester verification of this correction is required before merge (same mechanics the previous Builder section recorded for its own delta). The exactly-one-decision contract is preserved: the previous section's approval remains the file's single live decision line.
- Residual risks the static + probe audit cannot fully exclude, for the Tester's attention: (1) rendered layout metrics at the three CI viewports — the overflow, clipping, touch-target, grid-token, and focus-indicator assertions are verified against the CSS rules and the DOM order here, but the review section's rendered metrics are only exercised by a real browser run, which remains CI-authoritative (the unchanged local-sandbox limitation: the app cannot be served locally); (2) the CI e2e environment's dev-server specifics are unchanged from the 75 passing pre-existing tests, so no new risk is introduced there. The binding obligation is unchanged: the next CI e2e run on PR #107 must pass all 9 clip-review tests across mobile/tablet/desktop alongside the 75 pre-existing e2e tests.

---

## Phase 7 — Tester Focused Re-verification (2026-10-16): e2e spec-conformance correction (third CI e2e failure)

Executed 2026-10-16 by the independent Tester on the working tree at branch `@carlosegoulart/106/feat/clip-review-experience` (HEAD `82dda6f` plus uncommitted changes). Scope: the Builder's spec-side conformance correction (§E1–§E7) applied after the third CI e2e run — the empty-list attachment assertion, the `exact: true` disambiguation of the two "Back to project" click sites, and the console-mirror whitelist for the ratified anonymous session probe — with no production, component, backend, or governance change. Every command below was executed fresh by the Tester this session; Builder §E claims were re-executed or verified against the actual diff, the spec source, the production markup, and the governance validators, not trusted. This section supersedes the prior approval (rewritten to prose above) and holds the single live Tester decision for PR #107 (file-final line).

### F1. Scope and tree hygiene — PASS

- `git status --porcelain --untracked-files=all`: exactly two paths — `apps/web/e2e/clip-review.spec.ts` (the conformance delta) and `specs/106-clip-review-foundation/evidence.md` (Builder §E1–§E7 plus this Tester section). Zero untracked entries anywhere in the tree.
- The throwaway probe is verified absent: no untracked path exists, and the only probe-named repository paths are pre-existing, unmodified production files (`services/worker/aiclip_worker/actions/probe.py`, `services/worker/tests/test_probe.py`, and a committed media-assets migration).
- The Builder's own evidence delta is a clean single-hunk append after the prior decision line — no earlier section was rewritten by the Builder (full `git diff` of this file reviewed by the Tester).
- Zero changes to `apps/web/src/**`, `apps/api/**`, `tests/governance/**`, `scripts/merge_gate.py`, `.github/workflows/**`, Docker, or `.opencode/**`; no branch/commit/push/PR/issue operations. `docs/project-state.md`, dirty during the previous review, is now committed at HEAD `82dda6f`.

### F2. Empty-list assertion — conforms to the ratified TC-E2E-01 step 4; not a weakening — VERIFIED

- Ratified wording (test-plan §6, TC-E2E-01 step 4, verbatim): "sidebar heading 'Clips to Review' renders with an empty list" — the contract is that the list RENDERS with zero items.
- Source truth: `ReviewPage.tsx:250` renders `<div className="space-y-3" role="list" aria-label="Clip review list">` whose only children are `clipReviews.map(...)` buttons (`role="listitem"`, line 270); with zero reviews the div is attached and empty.
- CI run 3 ground truth (recorded verbatim in §E1): on all nine tests the locator resolved to the attached empty `<div role="list">` with "unexpected value 'hidden'" — Playwright visibility requires a non-empty bounding box, which a zero-item list can never produce. The pre-edit `toBeVisible()` was therefore unsatisfiable against the ratified component behavior; conforming the assertion to the ratified wording is the only resolution that leaves the component's contract-true behavior untouched.
- Current spec (lines 185–196): the three text-bearing assertions ('No clips', 'Select a clip to review', the 'Clips to Review' heading) remain visible-asserted; the list is asserted `toBeAttached()`; the zero-items companion `await expect(page.getByRole('listitem')).toHaveCount(0)` is byte-identical diff context. The empty-list contract is enforced by two independent assertions — attachment fails if the list is absent from the DOM, the count fails if any item renders — so `toBeAttached()` is the strongest satisfiable form here and nothing was loosened. A single-occurrence grep confirms the 'Clip review list' locator exists only in this helper.

### F3. `exact: true` on both "Back to project" clicks — disambiguation to the ratified control; shell control untouched — VERIFIED

- Ratified wording (TC-E2E-02 step 5, verbatim): "Click the review section's 'Back to project' control: returns to the projects list" — the named control is the review section's.
- DOM coexistence verified in source: `ProjectMediaSection.tsx:35` (`aria-label="Back to projects"`) renders unconditionally in the section header, and while the review tab is active the panel ternary (lines 74/90) also mounts `ReviewPage`, whose own control is `ReviewPage.tsx:165` (`aria-label="Back to project"`). Playwright role-name matching is case-insensitive substring by default, so the unqualified locator resolves to both buttons and `.click()` strict-mode-violates; `exact: true` restores whole-string matching and resolves to the review section's control only — the exact control the ratified step names. Same element, same action, same downstream assertions: a locator narrowing, not a behavioral change.
- The shell control is untouched: the `FOCUS_TARGETS` entry `button[aria-label="Back to projects"]` (attribute selector, exact by construction) and `openProject`'s `getByRole('button', { name: 'Back to projects' })` are byte-identical diff context, as are the touch-target measurements for both controls (exact attribute selectors, spec lines 372/375).
- No other latent ambiguity site: every remaining `getByRole` locator in the spec was checked — 'Back to projects' (line 172) cannot substring-match the review control (the query is longer than that accessible name), the tab names 'Media'/'Review Clips' are not substrings of one another, and `Delete ${name}` / `Confirm delete ${name}` never coexist (ProjectCard ternary). The two fixed click sites are the only substring-collision sites in the spec.

### F4. Console-mirror whitelist — cannot mask a real defect — VERIFIED

- Filter predicate (spec lines 79–89, verified in the diff): a console message is skipped only when its type is exactly `error`, its text starts with the exact Chromium network-mirror prefix `Failed to load resource: the server responded with a status of 401`, and its `location().url` ends with `/api/v1/auth/me`. Every warning remains fatal, and every other error remains fatal — including 401 mirrors from every other URL (a 401 on any other endpoint fails both this collector and the response collector).
- The response-side 4xx whitelist (spec lines 96–107) is byte-identical diff context and still enforces the single ratified event: any response with status ≥ 400 that is not exactly `GET /api/v1/auth/me` → 401 is pushed to `unexpectedHttp` and fails `expectClean`. A non-GET 401 on auth/me, a 403/419/500 on auth/me, or a 401/4xx/5xx anywhere else therefore fails the run on the response side regardless of the console filter.
- No spoof vector: repo grep confirms zero `console.*` calls in `apps/web/src`, so no application-originated message can carry the mirror's shape; the only skippable messages are the browser's own mirrors of the response event the ratified whitelist already tolerates — the console filter's acceptance set is a subset of the response whitelist's.
- Fail-closed property: if the mirror's real shape on CI differed from the predicate (different text form, different location URL), the mirror would land in `consoleProblems` and fail the run — the filter cannot manufacture a false green; its worst case is a loud CI failure with a precise diagnosis.
- Residual shared blind spot (not introduced by this delta): a `GET /api/v1/auth/me` 401 occurring at an unexpected moment is tolerated by both collectors, but that tolerance is inherited from the response whitelist ratified with the original collector design (this delta introduces no new acceptance), and any such session break would fail the flow's downstream assertions (registration awaits "Signed in as", project creation asserts 201, the clip-reviews request asserts 200, and the review section must render).

### F5. No collateral change — VERIFIED

`git diff --numstat apps/web/e2e/clip-review.spec.ts` → +35/−6; the complete diff is exactly the four hunks reviewed above (console collector, `expectEmptyReviewSection`, and the two click sites, each with its rationale comment). A full-file read confirms everything else is byte-identical to the state ratified in R1/V2/W2: the envelope assertions at all four sites, the response whitelist, the pageerror collector, the storage assertions, `FOCUS_TARGETS`, all waits, and all other selectors and scenario logic. Zero production files in the delta; zero changes to any test other than the e2e spec.

### F6. Builder probe evidence — probe removed; its load-bearing claims independently corroborated

The probe is verified absent from the tree (F1), and the installed runner version was re-verified (`npm ls @playwright/test` → 1.63.0). Its three behavioral claims do not rest on the probe alone: (i) the empty-list visibility failure is CI run 3's verbatim ground truth (§E1); (ii) the strict-mode violation follows mechanically from the DOM coexistence verified in F3 plus Playwright's documented substring matching — the probe's count-2/click-rejected output is the only outcome that structure permits; (iii) the console mirror is Chromium's documented behavior for non-2xx resources, and the filter is fail-closed (F4): if the probe's recorded message shape were wrong, the CI re-run would fail loudly at the cleanliness assertion rather than pass silently. The probe-only residual risk is therefore bounded to a loud, precisely diagnosable CI failure under the unchanged venue matrix.

### F7. Local re-runs — every §E6 number reproduced exactly

```bash
cd apps/web && npx playwright test --list
# → Total: 84 tests in 5 files; all 9 clip-review tests discovered
#   (clip-review.spec.ts:200/255/320 × [mobile|tablet|desktop]); 84 − 9 = 75 pre-existing

cd apps/web && npx playwright test e2e/clip-review.spec.ts --list
# → Total: 9 tests in 1 file

cd apps/web && npm test
# → Test Files 17 passed (17); Tests 260 passed (260)

cd apps/web && npm run lint
# → oxlint exit 0; 14 warnings — the identical pre-existing profile
#   (VideoPlayer.tsx ×3, FeedbackForm.tsx ×7, ReviewPage.tsx ×4); zero diagnostics
#   for the changed spec

cd apps/web && npm run build
# → tsc -b && vite build green; 87 modules transformed (built in 292ms)
```

### F8. Governance and the decision contract

`python -m unittest discover -s tests/governance -p 'test_*.py'` → **Ran 173 tests, FAILED (failures=1)** — the single failure is the identical pre-existing human-gated item (`test_governance.py::test_g5_six_skills_parse`, expects 6 skills while 8 exist), matching the R7/V4/W5 baseline; the argparse usage strings in the output belong to passing merge-gate CLI tests; zero new failures from this delta. Decision-contract mechanics were re-verified against the actual validators before editing: `validators.py` (lines 30–31) defines the two decision forms as plain substring constants (referenced here by constant name only), `validate_evidence_decision` / `validate_merge_approval` fail on both-or-neither, and `scripts/merge_gate.py::DECISION_RE` is line-anchored last-wins. Pre-edit grep confirmed exactly one decision literal in this file — the prior section's file-final approval, which §E7 correctly records as covering the transport fix at HEAD `00ee618` and predating this conformance delta. That line was rewritten to prose embedding neither constant (the established supersession convention), and this section documents the mechanisms by constant name only. Post-edit verification: grep confirms exactly one occurrence of the approve-form constant in this file (the file-final line, now line 1072) and zero occurrences of the reject-form constant anywhere; the governance suite re-run after the edit shows the identical result — 173 tests, failures=1, the same human-gated skills-count item only.

### F9. Local e2e venue — structurally re-verified as still blocked; CI remains authoritative

The local full-e2e blocker is unchanged and re-verified structurally: `apps/api` contains no `.env` (only `.env.example` — existence checked without inspecting any secret), so the served Laravel still lacks the APP_KEY that `phpunit.xml` supplies to Pest alone; the R6/V5/W6 diagnosis (`MissingAppKeyException` on the served session probe; every agent-available injection mechanism previously permission-denied) is unaffected by this spec-side delta. No local e2e result is fabricated here; discovery-level verification (`--list`) is the locally executable portion and passes (F7).

### F10. Binding pre-merge CI obligations (unchanged venue matrix)

1. **CI e2e job re-run on PR #107**: all 9 clip-review tests green across mobile/tablet/desktop alongside the 75 pre-existing e2e tests. Run 3's ground truth proves every assertion upstream of the former line-171 failure now passes on all nine tests; the three corrected sites (the empty-list attachment, the two exact back-control clicks, the console-mirror filter) are the remaining unknowns, each bounded by F4's fail-closed property to a loud failure rather than a silent pass.
2. **CI backend `tests` job**: the unchanged R8/V5/W6 obligation — all six `real-integration` tests selected and executed with zero skips against real PostgreSQL + MinIO; full backend suite green on the CI environment.
3. **Human-gated governance corrections** (plan 6.6, unchanged): the skills-count test (6→8) and the `merge_gate.py` exactly-one-decision contract; `governance` and `pr-enforcement` green before merge.

The Orchestrator must watch the pushed CI re-run; the lifecycle guard has now caught and corrected three real CI failures in sequence, each root-caused, ratified where the plan itself was wrong, fixed, and independently re-verified.

### F11. Verdict on the conformance delta

The third CI run's single remaining failure was an assertion over-interpreting the already-ratified contract ("renders with an empty list") against a component that behaves exactly as ratified; the Builder conformed the assertion rather than the component, and the mandated downstream audit converted two latent CI failures-in-waiting (a mechanically certain strict-mode violation on the back-control click, and the browser's console mirror of the very event the ratified whitelist expects) into precise, narrowly scoped spec corrections. No assertion was weakened: the empty-list contract is enforced by attachment plus a zero-item count where the pre-edit form was unsatisfiable rather than stronger; the exact flag targets the same ratified control the test plan names; the console filter's acceptance set is a subset of the already-ratified response whitelist and is fail-closed. Every locally-executable mandatory verification passes and reproduces the Builder's §E6 numbers exactly; governance shows only the known human-gated item; the tree carries exactly the spec plus this evidence file, with the throwaway probe verified removed. The full Playwright run remains the binding pre-merge CI obligation per the ratified plan 7.8 venue matrix, enforced by the merge lifecycle.

Decision: APPROVE