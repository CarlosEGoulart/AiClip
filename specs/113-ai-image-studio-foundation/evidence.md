# Evidence: AI Image Studio Foundation — Issue #113

## Builder Implementation Report

Resumed after interruption. A previous Builder session had completed plan Steps 1–3 production code (provider seam, storage keys, data model, queue job) and had written the Step 4 endpoint/list/show RED tests plus the job tests. This session validated the pre-existing work, fixed one test-harness defect in it, and completed Steps 4–7 (API layer, frontend feature, tab wiring, Playwright spec, full verification).

### Pre-existing work validated (Steps 1–3, completed pre-interruption)

- `App\Contracts\ImageGenerationProvider`, `App\Services\ImageResult`, `App\Services\FakeImageGenerationProvider` (deterministic pure-PHP PNG synthesis, fixed 4-entry style catalog, container singleton binding in `AppServiceProvider::register`).
- `StorageKeyBuilder::imageGeneration()` + extended `tests/Unit/StorageKeyBuilderTest.php`.
- `images` migration, `Image` model, `ImageStatus` enum, `ImageFactory` + `tests/Feature/Models/ImageModelTest.php`.
- `GenerateImage` job (RenderMediaClip topology: `tries = 3`, `backoff = 5`, `uniqueId()`, ownership re-validation, non-pending idempotency, strict result validation, storage write, transactional persistence) + `tests/Feature/ImageStudio/GenerateImageJobTest.php`.
- RED tests for the API layer were already written (`GenerateImageEndpointTest`, `ImageListTest`, `ImageShowTest`); no API implementation existed.

Validation results for the pre-existing code (run now, at resume):

- `php artisan test tests/Unit/Services/FakeImageGenerationProviderTest.php tests/Unit/StorageKeyBuilderTest.php tests/Feature/Models/ImageModelTest.php --compact`
  → **35 tests, 35 passed, 76 assertions** (TC-BE-01, TC-BE-02).
- `php artisan test tests/Feature/ImageStudio/GenerateImageJobTest.php --compact`
  → **16 tests, 16 passed, 47 assertions** (TC-BE-08).

### RED/GREEN/REFACTOR cycles completed this session

#### Step 4 — API layer (TC-BE-03..07)

**RED (after fixing one inherited test-harness defect).** The inherited `GenerateImageEndpointTest` helper `generateRequest()` called `spaRequest()` (whose `$cookies` parameter is by-reference) through Pest's `test()` helper. `test()` returns a `HigherOrderTapProxy` that forwards methods via `__call`, which cannot pass by-reference arguments — 17 of 20 tests errored with `spaRequest(): Argument #3 ($cookies) must be passed by reference, value given`. Per the test plan's setup-failure rule, that is not RED. Fixed the helper to resolve the running TestCase directly (`TestSuite::getInstance()->test`, the same public accessor Pest's `test()` uses); zero call sites changed.

After the fix, valid behavioral RED — every failure is "route missing → 404 instead of the required status":

```
php artisan test tests/Feature/ImageStudio/GenerateImageEndpointTest.php tests/Feature/ImageStudio/ImageListTest.php tests/Feature/ImageStudio/ImageShowTest.php --compact
→ 33 tests, 7 passed, 26 failed, 140 assertions
→ failures: "Expected response status code [202|422|200|401|419] but received 404"
   (the 7 passing tests are the 404-expecting ownership/filter cases, vacuously green pre-implementation)
```

**GREEN.** Implemented `GenerateImageRequest` (project_id required integer; prompt required string 1–1000; style_preset `in:` the fixed catalog; width/height `sometimes` integers + pair rule: both-or-neither and one of (1024,1024)/(1080,1920)/(1920,1080)), `ImageController` (generate: owned-project resolve → pending row with `StorageKeyBuilder::imageGeneration()` → `GenerateImage::dispatch` → 202 with the enqueue-time resource, no refresh after dispatch; index: user-scoped, `orderByDesc('id')`, owned `project_id` filter 404, per_page default 15 cap 100; show: route-model binding + owner 404), `ImageResource` (exact 11-field whitelist; `status` serialized as string), `ImageCollection` (standard pagination envelope under a top-level `data` key, ClipReview convention), routes under the `auth:sanctum` group with `throttle:image-generate|image-list|image-show`, and the three rate limiters in `AppServiceProvider::boot` (20/min for image-generate, 60/min for list/show, keyed by user id with ip fallback):

```
php artisan test tests/Feature/ImageStudio --compact
→ 49 tests, 49 passed, 273 assertions
```

**REFACTOR.** `vendor/bin/pint --dirty --format agent` fixed one auto-formatting issue (`method_chaining_indentation` in `ImageListTest`); rerun **passed** (no further fixes). Route sanity: `php artisan route:list --path=api/v1/images` shows the 3 routes under `api/v1`.

#### Step 5 — Frontend feature (TC-FE-01..05)

**RED.** Wrote `GenerateImageForm.test.tsx` (16), `ImageGallery.test.tsx` (10), `ImageStudio.test.tsx` (11) mirroring the clip-review component-test conventions (pure components; `vi.mock('../hooks')` + QueryClientProvider wrapper for the composition; no Router provider). Minimal null-render stubs were created alongside so module resolution succeeds and every failure is behavioral:

```
npm test -- --run src/features/image-studio
→ 37 tests, 37 failed — all "Unable to find a label/role/text" assertions
   against the null-rendering stubs (missing behavior, not setup errors)
```

**GREEN.** Implemented `types.ts` (types + `STYLE_PRESETS`/`DIMENSION_PRESETS` constants mirroring the backend catalog), `api.ts` (`generateImage` POST, `fetchImages` GET with the top-level `data` envelope unwrapped; `fetchWithCsrf` wire-cookie pattern), `hooks.ts` (`useImages(projectId, localPendingCount)` with `refetchInterval` 2000 while the server list or a local pending entry is pending, false otherwise; `useGenerateImage` invalidating `['images']` on success), `GenerateImageForm` (labeled textarea maxLength 1000 with live `N/1000` counter and paste truncation, style/dimension preset selects, Generate button disabled while empty/whitespace or generating with spinner + `aria-busy`, polite live region for validation/server errors with typing dismissal), `ImageGallery` (always-mounted polite live region; loading/empty/error-with-retry states; entries with prompt excerpt, `width × height`, formatted size, and Pending-gray/Generated-green/Failed-red badges), `ImageStudio` (heading + description; local pending entries seeded from the 202 response prepended to the server list; server-truth merge; `grid-cols-1 lg:grid-cols-2` layout), and the "Image Studio" tab in `ProjectMediaSection` (`role="tab"`/`aria-selected`/`aria-controls`, ids `image-studio-tab`/`image-studio-panel`, M7 Slice 1 pattern; no router library).

First GREEN run: 33/37. The 4 failures produced two implementation fixes and two test fixes:

- Implementation defect fixed: a rejected generate promise escaped unhandled (`Unhandled Rejection` caught by Vitest; would surface as a browser `pageerror` and violate e2e console cleanliness). Fixed in `ImageStudio.handleGenerate` (catch; the mutation error state still surfaces in the live region) and defensively in `GenerateImageForm.handleSubmit` (`Promise.resolve(...).catch(...)`).
- Accessibility defect fixed: the gallery live region only mounted when images existed, so it could not reliably announce the first pending entry (live regions must pre-exist their content changes). Restructured `ImageGallery` to always mount the polite live region.
- Test defects fixed (assertion intent unchanged): counter arithmetic (`'A quiet harbor'` is 14 characters → `14/1000`); tab-order test now types a valid prompt first (disabled controls are correctly skipped by Tab); the mutation-error test models React Query's state transition via a stateful mock + rerender.

```
npm test -- --run src/features/image-studio
→ 3 files, 37 tests, 37 passed

npm test (full suite)
→ 20 files, 303 tests, 303 passed
   (266 pre-existing + 37 new; ProjectMediaSection change verified regression-free,
   including the production-topology ClipReviewSection mount test)
```

**REFACTOR.** `npm run lint` (oxlint): **exit 0 — zero errors**; warnings only, all pre-existing except two `react(set-state-in-effect)` warnings in the new components — the same warning class the pre-existing clip-review components already carry (ReviewPage has identical warnings). `npm run build` (tsc -b && vite build): **passed** (93 modules, 316.53 kB / gzip 92.82 kB).

#### Step 6 — Playwright E2E (TC-E2E-01..03)

Wrote `apps/web/e2e/image-studio.spec.ts` (inside `testDir: './e2e'`): TC-E2E-01 generate flow and TC-E2E-02 responsive/touch-target/focus scenario, both with TC-E2E-03 cleanliness enforced inside (console/pageerror collectors with the ratified anonymous `auth/me` 401 probe mirror whitelisted, 4xx/5xx whitelist, storage/bucket request collector, empty localStorage/sessionStorage), reusing the clip-review `trackCleanliness`/`expectClean` helper pattern and the media.spec data setup (register unique user via UI, create project via UI, capture id from the real 201). Flow assertions: 202 with `body.data.status === 'pending'` and the exact 11-field whitelist; best-effort pending-badge snapshot (documented in the spec: the sync-queue window is one refetch round trip; the deterministic local-pending behavior is covered by TC-FE-04); refetch 200 with pagination envelope; convergence to exactly one entry with the green Generated badge, `1080 × 1920`, non-zero KB size, and the prompt excerpt. TC-E2E-02 adds: no horizontal overflow; 1 column with form above gallery below 1024px and side-by-side from 1024px; no viewport clipping; ≥44px touch targets (including the Image Studio tab via the `.tab-navigation [role="tab"]` 44px global rule); keyboard walk over tab → prompt → style → dimensions → Generate with visible focus indicators (outline or ring).

**Discovery (required, no browser needed):**

```
npx playwright test e2e/image-studio.spec.ts --list
→ 6 tests in 1 file:
   [mobile|tablet|desktop] × {TC-E2E-01 flow, TC-E2E-02 responsive}
```

**Execution — deferred to CI with the exact environment reason.** A real local attempt was made:

```
npx playwright test e2e/image-studio.spec.ts
→ 6 failed — every test blocked at waitForAuthReady; the browser rendered the
   app's session-probe error state: `alert: "Server error: 500"` + "Retry session"
```

Root cause (proven, not assumed): the sandbox PHP CLI lacks the `pdo_pgsql` extension. The CI-parity Pest run against the disposable `aiclip_test_issue64` PostgreSQL database failed with `could not find driver (Connection: pgsql, Host: 127.0.0.1, Port: 5432, ...)`, and the e2e Laravel server (which runs against the pgsql `.env` database with `SESSION_DRIVER=database`) therefore returns 500 for every DB-backed request, including the auth/session flow. The e2e harness itself works locally (Chromium is installed; Vite served the app; Playwright executed all 6 tests). This matches the Issue #110 precedent (this sandbox cannot host the e2e stack; CI is the authoritative e2e venue). CI's e2e workflow provisions PostgreSQL 16 + `pdo_pgsql` and runs `npm run test:e2e`; it was green (96/96) on the previous slice and will discover and run this spec.

### Full-suite verification (plan §7)

| Command | Result |
|---|---|
| `docker compose up -d --wait postgres` | Container `aiclip-postgres` **Healthy** |
| `php artisan migrate:fresh --seed` | **Cancelled by Laravel's production guard** ("APPLICATION IN PRODUCTION"; the local `.env` is production-flagged). Not forced — a destructive fresh migrate on a production-marked local DB is outside safe Builder behavior. The `images` migration is exercised by RefreshDatabase on every Pest run, and CI migrates the disposable pgsql test database in the backend job. |
| `php artisan test --compact` (default sqlite test config, full tree) | **1599 tests: 1543 passed, 4 failed, 34 errors, 18 skipped, 6418 assertions.** Every failure/error is environmental and pre-existing, none in this slice's scope or files: 2× SessionAuthentication + 1× Health expecting the `pgsql` driver under the CI env; 1× pgsql-specific DerivedAsset enum rejection; 24× Issue60DbGuard/Issue64RecoveryFixture refusals (disposable-`aiclip_test*` guard refuses the sqlite target); 2× RealPhpToPythonRankClips (Python worker unavailable); 1× ProcessMediaAssetRenderTest (sqlite fixture quirk); skips are the self-skipping MinIO/real-integration groups. All of this slice's suites pass inside this run. |
| CI-parity run (`DB_CONNECTION=pgsql` + `aiclip_test_issue64` via a temporary phpunit config, `--exclude-group=real-integration`) | **Blocked**: `could not find driver (Connection: pgsql ...)` — the sandbox PHP lacks `pdo_pgsql`. The authoritative full-suite venue is the backend CI job (which runs `php artisan test` green on pgsql + MinIO + worker). The temporary config file was neutralized and must be deleted before staging (see Artifacts note). |
| `php artisan test tests/Feature/ImageStudio tests/Feature/Models/ImageModelTest.php tests/Unit/StorageKeyBuilderTest.php tests/Unit/Services/FakeImageGenerationProviderTest.php --compact` | **84 tests, 84 passed, 349 assertions** (TC-BE-01..08) |
| `vendor/bin/pint --dirty --format agent` | **passed** (no fixes needed after the earlier `ImageListTest` formatting fix) |
| `npm test` | **20 files, 303 tests, 303 passed** (TC-FE-01..05 included) |
| `npm run lint` | **exit 0**, warnings only (see REFACTOR above) |
| `npm run build` | **passed** (tsc -b + vite build) |
| `npx playwright test e2e/image-studio.spec.ts --list` | **6 tests in 1 file** (non-empty discovery, 3 viewport projects) |
| `npm run test:e2e` | **Deferred to CI** — exact reason: missing local `pdo_pgsql` (evidence above); the local attempt's 6 failures are all `Server error: 500` at the auth step, before any image-studio assertion runs |
| Governance suite | Not executed by Builder: the sandbox permission system denies `python` commands; governance is enforced in CI and remains the gate owner's responsibility |

### Security / scope notes

- `ImageResource` and every image response expose only the 11 whitelisted fields; `storage_disk`, `storage_key`, `user_id`, and bucket names are asserted absent (endpoint, list, show, and e2e body greps). Failure logs in the job carry sanitized reasons only (asserted by TC-BE-08 log-hygiene tests).
- CSRF-before-auth (419 before 401), guest 401, non-owner/non-existent 404 with identical bodies, mass-assignment rejection, and the 20/min `image-generate` limiter (21st request → 429 + `Retry-After`) are all covered by the passing endpoint suite.
- No new composer/npm packages. No TODO/FIXME placeholders in production code; `FakeImageGenerationProvider` is the spec'd provider boundary, not a placeholder.
- No `.env` secrets were read or inspected; the CI-parity DB credentials used were the repository's compose/CI defaults against the disposable `aiclip_test*` database.

### Artifacts requiring Orchestrator action before staging

The sandbox permission system denies `rm`, so two inert temporary local-verification files could not be deleted and are currently untracked:

- `apps/api/phpunit-ci-parity.xml` (comment-only stub; the original CI-parity config was used for the blocked pgsql run)
- `apps/api/env-probe-113.php` (comment-only stub; the probe itself was never executable)

Both must be **deleted** (not committed). Everything else in the working tree is issue-scoped implementation and tests.

### Remaining gates (not claimed complete)

- Independent Tester review and CI (backend, frontend, e2e, governance) remain the approval authority. The e2e suite requires CI execution per the evidence above.
