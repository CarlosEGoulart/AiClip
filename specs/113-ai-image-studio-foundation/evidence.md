# Evidence: AI Image Studio Foundation — Issue #113

## Builder Implementation Report

Resumed after interruption. A first Builder session had completed plan Steps 1–3 production code (provider seam, storage keys, data model, queue job) and had written the Step 4 endpoint/list/show RED tests plus the job tests. The resumed session validated the pre-existing work, fixed one test-harness defect in it, and completed Steps 4–7 (API layer, frontend feature, tab wiring, Playwright spec, full verification). After CI Round 1 ran on commit `5c7570f`, a further Builder session (interrupted by a server restart) applied the two e2e locator fixes CI had surfaced, and the present session restructured this evidence file for the `pr-enforcement` TDD-heading requirement and re-verified the spec locally (all recorded under "CI Round 1" below).

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

### RED

**Cycles completed pre-interruption.** The provider seam, storage keys, data model, and queue job (plan Steps 1–3) went through their RED cycles in the pre-interruption session; those original RED states were not re-run at resume. Their GREEN state was re-validated before any new implementation (35/35 and 16/16 — outputs under GREEN below and in Pre-existing work above). The Step 4 API RED tests were likewise inherited already written, with no API implementation existing at resume (see Pre-existing work above).

#### API layer (Step 4, TC-BE-03..07)

**RED (after fixing one inherited test-harness defect).** The inherited `GenerateImageEndpointTest` helper `generateRequest()` called `spaRequest()` (whose `$cookies` parameter is by-reference) through Pest's `test()` helper. `test()` returns a `HigherOrderTapProxy` that forwards methods via `__call`, which cannot pass by-reference arguments — 17 of 20 tests errored with `spaRequest(): Argument #3 ($cookies) must be passed by reference, value given`. Per the test plan's setup-failure rule, that is not RED. Fixed the helper to resolve the running TestCase directly (`TestSuite::getInstance()->test`, the same public accessor Pest's `test()` uses); zero call sites changed.

After the fix, valid behavioral RED — every failure is "route missing → 404 instead of the required status":

```
php artisan test tests/Feature/ImageStudio/GenerateImageEndpointTest.php tests/Feature/ImageStudio/ImageListTest.php tests/Feature/ImageStudio/ImageShowTest.php --compact
→ 33 tests, 7 passed, 26 failed, 140 assertions
→ failures: "Expected response status code [202|422|200|401|419] but received 404"
   (the 7 passing tests are the 404-expecting ownership/filter cases, vacuously green pre-implementation)
```

#### Frontend feature (Step 5, TC-FE-01..05)

**RED.** Wrote `GenerateImageForm.test.tsx` (16), `ImageGallery.test.tsx` (10), `ImageStudio.test.tsx` (11) mirroring the clip-review component-test conventions (pure components; `vi.mock('../hooks')` + QueryClientProvider wrapper for the composition; no Router provider). Minimal null-render stubs were created alongside so module resolution succeeds and every failure is behavioral:

```
npm test -- --run src/features/image-studio
→ 37 tests, 37 failed — all "Unable to find a label/role/text" assertions
   against the null-rendering stubs (missing behavior, not setup errors)
```

### GREEN

#### API layer (Step 4, TC-BE-03..07)

**GREEN.** Implemented `GenerateImageRequest` (project_id required integer; prompt required string 1–1000; style_preset `in:` the fixed catalog; width/height `sometimes` integers + pair rule: both-or-neither and one of (1024,1024)/(1080,1920)/(1920,1080)), `ImageController` (generate: owned-project resolve → pending row with `StorageKeyBuilder::imageGeneration()` → `GenerateImage::dispatch` → 202 with the enqueue-time resource, no refresh after dispatch; index: user-scoped, `orderByDesc('id')`, owned `project_id` filter 404, per_page default 15 cap 100; show: route-model binding + owner 404), `ImageResource` (exact 11-field whitelist; `status` serialized as string), `ImageCollection` (standard pagination envelope under a top-level `data` key, ClipReview convention), routes under the `auth:sanctum` group with `throttle:image-generate|image-list|image-show`, and the three rate limiters in `AppServiceProvider::boot` (20/min for image-generate, 60/min for list/show, keyed by user id with ip fallback):

```
php artisan test tests/Feature/ImageStudio --compact
→ 49 tests, 49 passed, 273 assertions
```

#### Pre-interruption units re-verified at resume (provider / keys / model / job)

Before any new implementation, the inherited suites were re-validated green: `FakeImageGenerationProviderTest` + `StorageKeyBuilderTest` + `ImageModelTest` → **35 tests, 35 passed, 76 assertions** (TC-BE-01, TC-BE-02); `GenerateImageJobTest` → **16 tests, 16 passed, 47 assertions** (TC-BE-08). Combined with the new API layer, the issue-scoped backend total after Step 4:

```
php artisan test tests/Feature/ImageStudio tests/Feature/Models/ImageModelTest.php tests/Unit/StorageKeyBuilderTest.php tests/Unit/Services/FakeImageGenerationProviderTest.php --compact
→ 84 tests, 84 passed, 349 assertions (TC-BE-01..08)
```

#### Frontend feature (Step 5, TC-FE-01..05)

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

With the feature green, `npm run lint` exited 0 and `npm run build` passed (details under REFACTOR below); the complete plan §7 command matrix is in the Full-suite verification table below.

### REFACTOR

- **API.** `vendor/bin/pint --dirty --format agent` fixed one auto-formatting issue (`method_chaining_indentation` in `ImageListTest`); rerun **passed** (no further fixes). Route sanity: `php artisan route:list --path=api/v1/images` shows the 3 routes under `api/v1`.
- **Frontend.** `npm run lint` (oxlint): **exit 0 — zero errors**; warnings only, all pre-existing except two `react(set-state-in-effect)` warnings in the new components — the same warning class the pre-existing clip-review components already carry (ReviewPage has identical warnings). `npm run build` (tsc -b && vite build): **passed** (93 modules, 316.53 kB / gzip 92.82 kB).
- **Post-refactor stability.** After each refactor pass the affected suites were re-run and remained green: backend issue-scoped 84/84, frontend feature 37/37, full frontend suite 303/303.

### Playwright E2E spec (Step 6 — TC-E2E-01..03)

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

### CI Round 1 — e2e locator fixes (2026-10-20)

CI ran the full pipeline on commit `5c7570f`:

| CI check | Result |
|---|---|
| `governance` job (governance validator suite) | **PASS** |
| Frontend tests (Vitest) | **PASS** |
| Backend tests (full tree on CI PostgreSQL) | **PASS** |
| e2e (`npm run test:e2e`) | **96 passed / 6 failed** — all 6 failures are this slice's new image-studio tests |
| `pr-enforcement` | **FAILED** — evidence TDD-heading structure (fixed by this restructure; see below) |

**e2e: 6 failures, all locator defects in the new spec — not application defects.** All six failures are Playwright strict-mode violations (a locator resolving to 2 elements). The application flow itself passed in CI: the failing runs that progressed through the generate flow verified the full gallery convergence — the green Generated badge, `1080 × 1920`, and the non-zero KB size — before the failing locator line, proving the 202 → refetch → generated pipeline ran end-to-end on the CI stack. The two defects and fixes:

- **Panel-heading ambiguity (fixed).** `getByRole('heading', { name: 'Image Studio' })` resolved to two headings: the studio panel heading and the opened project's own title heading ("Image Studio Project" in TC-E2E-01) — role-name matching is substring-based, so both matched and strict mode rejected the locator. Fix: `getByRole('heading', { name: 'Image Studio', exact: true })` — `apps/web/e2e/image-studio.spec.ts:212`, with the explanatory comment at lines 209–211.
- **Prompt-text ambiguity (fixed).** Unscoped `getByText(PROMPT)` resolved to two elements: the gallery entry's prompt excerpt and the filled textarea's value. Fix: scope the assertion to the gallery list — `page.getByRole('list', { name: 'Generated images' }).getByText(PROMPT)` — `apps/web/e2e/image-studio.spec.ts:282–284`, with the explanatory comment at lines 280–281.

Both fixes were applied by the prior Builder session (interrupted by a server restart) and are present in the working tree; they change locator precision only — no assertion target, flow step, or application code was modified.

**`pr-enforcement` failure (fixed by this restructure).** The gate rejected this evidence file's TDD structure: the TDD narrative used `#### Step N` headings and bold `**RED.**`-style paragraph markers, while `validate_tdd_sections` requires the literal `### RED`, `### GREEN`, `### REFACTOR` headings (the gate's error message quotes them verbatim). The RED/GREEN/REFACTOR sections above are the direct fix; no evidence content was removed — the same facts and outputs were reorganized under the required headings, and this CI Round 1 section was added.

**Post-fix local re-verification (this session, 2026-10-20):**

```
npx playwright test e2e/image-studio.spec.ts --list
→ Listing tests:
  [mobile]  › image-studio.spec.ts:297 › Image Studio (embedded) › TC-E2E-01 generate-image flow: 202 pending, refetch to generated, whitelist
  [mobile]  › image-studio.spec.ts:317 › Image Studio (embedded) › TC-E2E-02 responsive layout, touch targets, and focus at the configured viewport
  [tablet]  › image-studio.spec.ts:297 › Image Studio (embedded) › TC-E2E-01 generate-image flow: 202 pending, refetch to generated, whitelist
  [tablet]  › image-studio.spec.ts:317 › Image Studio (embedded) › TC-E2E-02 responsive layout, touch targets, and focus at the configured viewport
  [desktop] › image-studio.spec.ts:297 › Image Studio (embedded) › TC-E2E-01 generate-image flow: 202 pending, refetch to generated, whitelist
  [desktop] › image-studio.spec.ts:317 › Image Studio (embedded) › TC-E2E-02 responsive layout, touch targets, and focus at the configured viewport
  Total: 6 tests in 1 file
```

```
npm test -- --run src/features/image-studio
→ Test Files  3 passed (3)
   Tests       37 passed (37)
```

The Vitest re-run confirms the locator fixes do not affect the component suites (they are e2e-file-only changes). Local e2e execution remains impossible in this sandbox (missing `pdo_pgsql` — see the Playwright E2E section above); the authoritative validation of these fixes is the next CI e2e run.

### CI Round 2 — real CSS for image-studio (2026-10-20)

CI ran the full pipeline on commit `a8d3641` (run `39452428016`):

| CI check | Result |
|---|---|
| `governance` job (governance validator suite) | **PASS** |
| Frontend tests (Vitest) | **PASS** |
| Backend tests (full tree on CI PostgreSQL) | **PASS** |
| `pr-enforcement` | **PASS** (the restructured `### RED`/`### GREEN`/`### REFACTOR` headings above were accepted) |
| e2e (`npm run test:e2e`) | **99 passed / 3 failed** — all 3 failures are TC-E2E-02 |

**e2e: 3 failures, all real-geometry styling defects in this slice — TC-E2E-01 now fully passes.** After the Round 1 locator fixes, all six image-studio tests ran to their assertions. TC-E2E-01 (all three viewports) passed the complete 202 → refetch → generated convergence. TC-E2E-02 failed on measured geometry: at **mobile and tablet** the style-preset touch target was **19px < 44px**, and at **desktop** the layout computed **`gridTemplateColumns` = 1 instead of 2**.

**Root cause (defect in this slice's frontend, not in the spec).** The image-studio components were written with Tailwind utility classes (`min-h-11`, `grid-cols-1 lg:grid-cols-2`, `focus-visible:ring-2`, …), but this project has no Tailwind config or import — `apps/web/src/index.css` is hand-written plain CSS, and the M7 clip-review reference components use semantic class names styled there. The utility tokens were therefore inert: the layout div rendered as plain `display: block` (one column at every viewport) and the preset selects fell back to the browser-default 19px height. TC-E2E-01 passed because it is functional; TC-E2E-02 measures real rendered geometry, which is exactly what it caught. The e2e spec was correct and remains untouched.

**The fix (styling-only, no behavior change).** A semantic **"Image Studio (issue #113)"** block was added to `index.css` (~lines 783–1150): `.image-studio-layout` as `display: grid; grid-template-columns: 1fr` with `@media (min-width: 1024px) { 1fr 1fr }`; `.image-studio-field-control`, `.image-studio-submit-button`, and `.image-gallery-retry-button` with `min-height: 44px` and the project's input/button/focus patterns (`:focus` border + ring on fields, `:focus-visible` outline on buttons); badge classes kept as real classes the e2e asserts on (`.image-gallery-badge` + `.bg-gray-100`/`.bg-green-100`/`.bg-red-100` defined in the block, with `--success`/`--success-bg` added to `:root` and dark mode); skeleton, state, and visually-hidden live-region styles. The three components were refactored from the inert utilities to these semantic classes — ids, aria attributes, roles, and texts unchanged, no logic touched. The component tests' assertions on inert utility tokens were updated to assert the real classes instead — same intent (44px touch targets, visible focus), now verified through classes that actually carry the styles: `GenerateImageForm.test.tsx` (fields → `image-studio-field-control`, Generate → `image-studio-submit-button`, spinner → `.image-studio-submit-spinner`), `ImageStudio.test.tsx` (same pattern for the composed controls), `ImageGallery.test.tsx` (retry → `image-gallery-retry-button`; the inert `focus-visible:ring-2` regex dropped — the focus indicator is provided by the stylesheet).

**Post-fix local re-verification (this session, 2026-10-20):**

```
npm test
→ Test Files  20 passed (20)
   Tests       303 passed (303)

npx vitest run src/features/image-studio
→ Test Files  3 passed (3)
   Tests       37 passed (37)

npm run lint (oxlint)
→ exit 0 — warnings only, all pre-existing (clip-review + the two
  set-state-in-effect warnings already recorded under REFACTOR)

npm run build (tsc -b && vite build)
→ ✓ 93 modules transformed
  dist/assets/index-*.css  15.88 kB │ gzip:  3.26 kB
  dist/assets/index-*.js   314.46 kB │ gzip: 92.56 kB

npx playwright test e2e/image-studio.spec.ts --list
→ Total: 6 tests in 1 file
  ([mobile|tablet|desktop] × {TC-E2E-01 flow, TC-E2E-02 responsive})
```

CSS-hook confirmation (`apps/web/src/index.css`):

```
grep '@media (min-width: 1024px)' + '.image-studio-layout' (image-studio block)
→ 807:  .image-studio-layout {
→ 814:  @media (min-width: 1024px) {
→ 815:    .image-studio-layout { grid-template-columns: 1fr 1fr; }

grep '.image-gallery-retry-button:focus-visible'
→ 1133: .image-gallery-retry-button:focus-visible { (outline: 2px solid var(--accent)) }

(min-height: 44px confirmed at .image-studio-field-control:848,
 .image-studio-submit-button:902, .image-gallery-retry-button:1110)
```

A whole-feature scan for the inert utility tokens (`min-h-11`, `grid-cols-`, `lg:`, `dark:`, `text-2xl`, `animate-spin`, `focus-visible:ring`, `rounded-lg`, plus a broader utility sweep) now finds only the intentionally kept badge classes `bg-gray-100`/`bg-green-100`/`bg-red-100` (real classes defined in the block, asserted by the e2e). Local e2e execution remains impossible in this sandbox (missing `pdo_pgsql`, unchanged from Round 1); the authoritative validation of this fix is the next CI e2e run.

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
| `npm run test:e2e` | **Deferred to CI** — exact reason: missing local `pdo_pgsql` (evidence above); the local attempt's 6 failures are all `Server error: 500` at the auth step, before any image-studio assertion runs. CI Round 1 then executed the spec in CI (96 passed / 6 locator-defect failures, since fixed — see "CI Round 1" above); the next CI run is the authoritative re-validation. |
| Governance suite | Not executed by Builder: the sandbox permission system denies `python` commands; governance is enforced in CI and remains the gate owner's responsibility |

### Security / scope notes

- `ImageResource` and every image response expose only the 11 whitelisted fields; `storage_disk`, `storage_key`, `user_id`, and bucket names are asserted absent (endpoint, list, show, and e2e body greps). Failure logs in the job carry sanitized reasons only (asserted by TC-BE-08 log-hygiene tests).
- CSRF-before-auth (419 before 401), guest 401, non-owner/non-existent 404 with identical bodies, mass-assignment rejection, and the 20/min `image-generate` limiter (21st request → 429 + `Retry-After`) are all covered by the passing endpoint suite.
- No new composer/npm packages. No TODO/FIXME placeholders in production code; `FakeImageGenerationProvider` is the spec'd provider boundary, not a placeholder.
- No `.env` secrets were read or inspected; the CI-parity DB credentials used were the repository's compose/CI defaults against the disposable `aiclip_test*` database.
- CI Round 1 (post-implementation) touched only `apps/web/e2e/image-studio.spec.ts` — two locator expressions; no application code and no assertion targets changed — plus this evidence file's structure. No production code was modified after the GREEN/REFACTOR states recorded above.

### Artifacts requiring Orchestrator action before staging

The sandbox permission system denies `rm`, so two inert temporary local-verification files could not be deleted and are currently untracked:

- `apps/api/phpunit-ci-parity.xml` (comment-only stub; the original CI-parity config was used for the blocked pgsql run)
- `apps/api/env-probe-113.php` (comment-only stub; the probe itself was never executable)

Both must be **deleted** (not committed). Everything else in the working tree is issue-scoped implementation and tests.

### Remaining gates (not claimed complete)

- Independent Tester review and CI (backend, frontend, e2e, governance) remain the approval authority. The e2e suite requires CI execution per the evidence above — including the CI re-run that must confirm the six image-studio tests pass after the Round 1 locator fixes and that `pr-enforcement` accepts the restructured evidence headings.

---

## Independent Tester Review

Independent validation performed 2026-10-20 by the Tester agent on branch `@carlosegoulart/113/feat/ai-image-studio-foundation` (planning commit `048af21`, implementation commit `5c7570f`, plus the uncommitted working-tree e2e locator fixes and this evidence restructure). Every command below was executed directly by the Tester in the sandbox; outputs are real, not quoted from Builder claims.

### Verification executed (real outputs)

| Command (working dir) | Result |
|---|---|
| `php artisan test tests/Unit/Services/FakeImageGenerationProviderTest.php tests/Unit/StorageKeyBuilderTest.php tests/Feature/Models/ImageModelTest.php --compact` (apps/api) | **35 tests, 35 passed, 76 assertions** — matches Builder |
| `php artisan test tests/Feature/ImageStudio --compact` (apps/api) | **49 tests, 49 passed, 273 assertions** — matches Builder |
| `php artisan test tests/Feature/ImageStudio/GenerateImageJobTest.php --compact` (apps/api) | **16 tests, 16 passed, 47 assertions** — matches Builder |
| `php artisan test tests/Feature/ImageStudio tests/Feature/Models/ImageModelTest.php tests/Unit/StorageKeyBuilderTest.php tests/Unit/Services/FakeImageGenerationProviderTest.php --compact` (apps/api) | **84 tests, 84 passed, 349 assertions** — matches Builder (TC-BE-01..08) |
| `npm test -- --run src/features/image-studio` (apps/web) | **3 files, 37/37 passed** (TC-FE-01..05) — matches Builder |
| `npm test` (apps/web) | **20 files, 303/303 passed** (266 pre-existing + 37 new) — matches Builder |
| `npm run lint` (apps/web) | **exit 0**, warnings only; the two new `react(set-state-in-effect)` warnings in the image-studio components are the same warning class the pre-existing clip-review components already carry — matches Builder |
| `npm run build` (apps/web) | **passed**: `tsc -b` + vite build, 93 modules, 316.53 kB / gzip 92.82 kB — matches Builder |
| `vendor/bin/pint --test --dirty --format agent` (apps/api) | **passed** (non-mutating formatting check run by the Tester; no file modified) |
| `npx playwright test e2e/image-studio.spec.ts --list` (apps/web) | **6 tests in 1 file**: TC-E2E-01 + TC-E2E-02 across the mobile 390x844 / tablet 768x1024 / desktop 1440x900 projects — matches Builder |
| `python -m unittest discover -s tests/governance -p 'test_*.py' -v` (repo root) | **173 tests, all OK** (the Builder session could not run python; the Tester environment permits it) |
| `gh pr checks 114` | `governance` PASS, `test` (frontend) PASS, `tests` (backend, full tree on CI PostgreSQL) PASS, `e2e` FAIL, `pr-enforcement` FAIL — exactly the reported CI Round 1 state for commit `5c7570f` |
| `gh run view 39422193188 --log-failed` (e2e job log) | **96 passed / 6 failed**; all 6 failures are strict-mode violations inside `image-studio.spec.ts` only (3× TC-E2E-01 heading ambiguity, 3× TC-E2E-02 prompt-text ambiguity). The TC-E2E-02 runs (whose project title does not collide with the heading) completed the entire generate flow before the failing line: the green `Generated` badge, `1080 × 1920`, and the non-zero `/KB/` size were all asserted and passed at every viewport — the 202 → sync generation → refetch → generated pipeline is proven end-to-end on the CI stack. Playwright's own error output names the exact fixed locators as the disambiguated elements (`getByRole('heading', { name: 'Image Studio', exact: true })` and `getByRole('list', { name: 'Generated images' }).getByText(...)`) |
| `gh run view 39422193001 --log-failed` (pr-enforcement job log) | The two reported gate errors, verbatim: the evidence file lacked the approval line, and it lacked the literal `### RED` / `### GREEN` / `### REFACTOR` headings. The restructured Builder sections satisfy the heading requirement; this review supplies the approval line |
| `git status --porcelain` / `git diff --stat 048af21 5c7570f` / `git diff apps/web/e2e/image-studio.spec.ts` | Scope and working-tree audit (see Scope review below) |
| Full code review of every implementation file | All 35 files of commit `5c7570f` plus the working tree read and checked against spec.md / test-plan.md (see AC table below) |

### Sandbox N/A items (with explicit reasons)

1. **Local browser e2e execution (`npm run test:e2e`) — N/A locally, CI-authoritative.** Independently proven blocker, stronger than the recorded one: there is **no `.env` file at all** in `apps/api` (directory listing shows only `.env.example`), so the Playwright webServer's `php artisan serve` (which overrides only `APP_ENV`, `QUEUE_CONNECTION`, `MEDIA_DISK` per `playwright.config.ts`) boots on Laravel framework defaults — environment production, sqlite database, database session/queue, and **no `APP_KEY`**, which makes encrypted session cookies throw and every session-backed request return 500 at the auth step — exactly the failure mode the Builder recorded. Additionally the sandbox PHP CLI lacks `pdo_pgsql` (proven by the CI-parity `could not find driver (Connection: pgsql ...)` failure and by historical Tester probes in this repository, e.g. Issue #64's `PDO::getAvailableDrivers() === ['sqlite']`; Issues #35/#58/#60/#69 document the same). This matches the ratified Issue #110 precedent (CI is the authoritative e2e venue for this sandbox), and the deferral is fail-closed: `scripts/merge_gate.py` lists `E2E CI / e2e` in `REQUIRED_CHECKS`, so the merge cannot proceed until the next CI e2e run is green on the committed locator fixes. *Documentation note (non-blocking):* the Builder evidence attributes the local e2e 500s to "the pgsql `.env` database" — no `.env` exists; the operative facts (local e2e impossible; CI authoritative) are unaffected, and the missing-APP_KEY / production-default state independently proves the same conclusion.
2. **Full-tree Pest on CI-parity PostgreSQL — N/A locally, CI-authoritative.** `pdo_pgsql` absent (above). The backend CI `tests` check passed the full tree on CI PostgreSQL for commit `5c7570f` (verified via `gh pr checks 114`). The local sqlite full-tree run's pre-existing environmental failures are outside this slice's files; the Builder's classification of them was reviewed and accepted.
3. **`php artisan migrate:fresh --seed` — N/A (not executed by the Tester).** The local environment resolves to production-flagged framework defaults (verified via `php artisan about`: Environment production, Database sqlite, Session/Queue database); a destructive fresh migrate there is outside safe Tester behavior — the same production guard that correctly stopped the Builder. The `images` migration was instead exercised by `RefreshDatabase` on every Pest run executed above, `php artisan migrate:status` independently confirms it is present and applied, and CI's backend job migrates the disposable test database green.
4. **Direct PHP CLI probes (`php -m`, `php artisan tinker`) — N/A (permission-blocked).** The sandbox permission system denies raw `php` / `which` invocations; extension and environment facts were corroborated through permitted `php artisan` surfaces plus the historical repository evidence cited above.
5. **Browser-only visual inspection (screenshots at the three viewports) — N/A locally, CI-authoritative.** Consequence of item 1. Compensating evidence: the component suites assert every structural/label/touch-target/focus requirement; the CI e2e run already executed the three viewport projects through the full flow; the CSS rules the assertions depend on (`.tab-navigation [role="tab"]` min-height 44px with `:focus-visible` outline; `min-h-11` on all studio controls; `grid-cols-1 lg:grid-cols-2` layout) are verified present in code.

### Acceptance criteria verification

| AC | Verdict | Evidence |
|---|---|---|
| AC-01 | PASS | Interface + readonly DTO + deterministic fake verified in code (real PNG synthesis: 8-byte signature, IHDR with exact big-endian dimensions, zlib-deflated IDAT, IEND, per-chunk CRC32; SHA-256-seeded scanlines; fixed compression level); singleton binding in `AppServiceProvider::register`; fixed 4-preset catalog; 9 provider unit tests pass (determinism, prompt/style/dimension sensitivity, PNG signature + IHDR decode, non-empty size, interface compliance); pure PHP — no network, subprocess, or model download |
| AC-02 | PASS | `StorageKeyBuilder::imageGeneration` returns `projects/{id}/images/{uuid}.png`, throws `InvalidArgumentException` for `$projectId < 1` (0 and −1 datasets), unique uuid per call; tests pass |
| AC-03 | PASS | Migration columns/indexes match the spec table exactly (unique `storage_key`, `[user_id, status]` and `project_id` indexes, FKs cascade, status default `pending`); model casts (`parameters` array, `status` enum) + `STATUS_*` constants + `project()`/`user()` relations; enum values; factory default `pending` + `generated`/`failed` states with StorageKeyBuilder-unique keys; 10 model tests pass including the unique-constraint and mass-assignment-whitelist checks |
| AC-04 | PASS | Job verified in code and tests: `tries=3`/`backoff=5`/`uniqueId()`; ownership re-validation from persisted rows (mismatch → warning, stays pending, no provider call, no storage write); non-pending idempotency (spy proves no re-invocation, no second write, unchanged mtime); strict result validation (non-`ImageResult`, empty data, wrong mime, bad PNG signature, dimension mismatch → `failed` with no storage object); `generated` marking with a real stored PNG artifact and persisted metadata inside a DB transaction; unexpected storage failure propagates leaving the row pending (no false success); enqueue-only controller — the 202 reports pending even though the sync queue completes the row inline (resource serialized from the created row, no refresh after dispatch); 16 job tests pass |
| AC-05 | PASS | 202 + pending resource + `Queue::assertPushed` exactly once with the row id; defaults (`photorealistic`, 1024×1024) and `cinematic`/1080×1920 overrides persisted; 422 × 8 (missing/empty/1001-char/non-string prompt, invalid preset, single-sided dimensions, non-allowed pair) + 1000-char boundary 202; non-owner and non-existent project → 404 with byte-identical bodies and no rows created; guest with valid CSRF → 401; missing/tampered CSRF → 419 before auth without mutation; 21st request/min → 429 with `Retry-After`; all pass |
| AC-06 | PASS | User-scoped, `orderByDesc('id')`, top-level `data` envelope wrapping `data`/`links`/`meta`, `per_page` default 15 cap 100, owned `project_id` filter with 404 for non-owned/non-existent filters, other users' images never visible, per-item whitelist; 9 tests pass |
| AC-07 | PASS | Owner 200 whitelisted; non-owner 404; non-existent 404; guest 401; 4 tests pass |
| AC-08 | PASS | `ImageResource` exposes exactly the 11 whitelisted fields; endpoint/list/show tests assert the exact key sets and the absence of `storage_disk`/`storage_key`/`user_id`; the e2e spec additionally greps the 202 and refetch bodies for those plus `bucket` (CI executed and passed those assertions inside the TC-E2E-02 runs); no internal fields in any response path |
| AC-09 | PASS | Heading + one-line description; labeled textarea `maxLength` 1000 with live `N/1000` counter and paste truncation; style/dimension selects with spec defaults; Generate disabled while empty/whitespace/generating with spinner + `aria-busy`; two polite live regions (form + always-mounted gallery); Pending-gray/Generated-green/Failed-red badges with the pending in-progress hint; loading/empty ("No images yet")/error-with-retry states; `min-h-11` (44px) on every studio control plus the `.tab-navigation [role="tab"]` 44px rule; `focus-visible` indicators on all focusables; `grid-cols-1 lg:grid-cols-2` (single column below 1024px including mobile/tablet, side-by-side at ≥1024px); 37 component tests pass |
| AC-10 | PASS | `useGenerateImage` invalidates `['images']` on success; `useImages` refetches every 2000 ms only while the server list or a local pending entry is pending; the 202 pending resource seeds an immediate local pending gallery entry and server truth replaces it (exactly one `listitem` after the flip — component-verified); "Image Studio" tab wired into `ProjectMediaSection` with the exact M7 tablist/tab/tabpanel/aria-selected/id pattern; no client-side router library (grep-verified) |
| AC-11 | PASS | All issue-scoped Pest suites 84/84 (349 assertions) and Vitest 303/303 re-executed by the Tester with results identical to the Builder claims; zero skipped tests in this slice's suites (grep for skip/`markTestSkipped` finds only pre-existing self-skipping real-integration groups outside the slice); CI backend `tests` green on the full PostgreSQL tree |
| AC-12 | PASS (CI-completion gated) | Playwright discovery: 6 tests × 3 viewport projects re-executed; CI Round 1 executed the spec end-to-end — 96 passed, and the 6 failures are the two locator defects fixed in the working tree (fixes reviewed below); lint exit 0, build/type check pass, governance 173/173 — all re-executed by the Tester; the next CI run is the authoritative full-green confirmation, enforced fail-closed by the merge gate before any merge can occur |

### e2e locator-fix review (working tree, `git diff apps/web/e2e/image-studio.spec.ts`)

1. **Panel heading** — `getByRole('heading', { name: 'Image Studio', exact: true })`: correct and assertion-preserving. CI proved the unfixed locator resolved to 2 headings (the project title "Image Studio Project" + the studio panel heading) because role-name matching is substring-based; the CI error output itself names `getByRole('heading', { name: 'Image Studio', exact: true })` as the resolved second element. `exact: true` keeps the identical visibility assertion on the same intended element.
2. **Prompt text** — `getByRole('list', { name: 'Generated images' }).getByText(PROMPT)`: correct and strictly stronger. CI proved the unscoped `getByText(PROMPT)` resolved to 2 elements (the filled textarea + the gallery entry's excerpt). Scoping to the gallery list asserts precisely what TC-E2E-01 step 7 requires (the prompt excerpt visible in the gallery entry); nothing was removed and no threshold loosened — the flow steps, cleanliness collectors, whitelist greps, and all downstream assertions are untouched.
3. The diff contains only these two hunks; no application code, no assertion target, and no other test changed. Vitest after the fixes: 37/37 (component suites unaffected, as expected for an e2e-file-only change).

### Security review

- **IDOR / ownership**: server-controlled `user → project → image` on every path; generate resolves an owned project before creating the row; list is user-scoped with an owned-filter 404; show is owner-gated; non-owner and non-existent both return 404 with byte-identical bodies (tested) — no ownership information leak.
- **CSRF before auth**: global `validateCsrfTokens` under `statefulApi` (only the pre-existing media-upload exception); the SPA test base enforces real CSRF middleware; 419 without a token (before auth, no mutation) and 401 with a valid token as a guest are both tested and pass.
- **Rate limiting**: `image-generate` 20/min per user (21st → 429 + `Retry-After`, tested); `image-list`/`image-show` 60/min; keyed by user id with ip fallback (existing convention).
- **Mass assignment**: requests accept only the five input fields; injected `user_id`/`status`/`storage_key`/`size_bytes` are ignored (tested); the model `$fillable` matches the whitelist.
- **Secrets / internal fields**: `ImageResource` exposes the 11-field whitelist only; `storage_disk`/`storage_key`/`user_id`/bucket never appear in responses (endpoint/list/show key-set assertions + CI-executed e2e body greps); job failure logs carry sanitized reasons only — no prompt content, no provider internals (log-hygiene test); the frontend holds no tokens/bearer/localStorage/sessionStorage (grep-verified) and the e2e asserts empty browser storage.
- **Queue job authorization**: the payload carries only the image id; ownership is re-validated from persisted rows (tested with an intruder row) — the payload is never trusted.
- **Untrusted prompt content**: 1–1000 chars enforced at the boundary; rendered escaped (React default); `parameters` is a server-authored snapshot, never echoed client JSON.
- **Provider boundary**: deterministic and side-effect-free; the container singleton is the single seam where the future model-backed provider lands without domain changes.

### Scope review

- Commit `5c7570f` touches only issue-scoped paths: `apps/api` contracts/enums/models/jobs/services (including the `StorageKeyBuilder`, `AppServiceProvider`, and `routes/api.php` extensions), HTTP layer, migration/factory, and tests; `apps/web` image-studio feature, `ProjectMediaSection` tab wiring, and the e2e spec; `specs/113` evidence. No `.opencode/**`, no `tests/governance/**`, no `scripts/**`, no CI/Docker changes, no new dependencies (no composer/package manifests in the commit).
- Working tree: only the two reviewed e2e locator hunks and this evidence file are modified.
- The two untracked stubs (`apps/api/env-probe-113.php`, `apps/api/phpunit-ci-parity.xml`) are confirmed comment-only and inert, untracked (`??` in `git status --porcelain`), and present in no commit; both are flagged for deletion by the Orchestrator before staging (untracked files cannot enter a pushed commit, but they must never be staged).
- Branch/commit/PR format: branch `@carlosegoulart/113/feat/ai-image-studio-foundation` and both commit subjects match the governance conventions; the pr-enforcement structural checks other than the evidence file already passed in CI (PR body sections, branch/issue cross-reference, commit format).

### TDD evidence review

- **RED quality**: the API RED (33 tests, 26 failures, all "expected 202/422/200/401/419 but received 404") is genuinely behavioral — missing routes, not environment errors; the 7 vacuously-green 404 cases are honestly disclosed. The frontend RED (37/37 failing against null-render stubs on label/role/text assertions) is behavioral. The initial `spaRequest()` by-reference harness error was correctly classified as a setup failure per the test plan and fixed before any RED was claimed.
- **GREEN/REFACTOR fidelity**: every claimed output matches the Tester's independent re-execution exactly (84/84/349; 49/49/273; 16/16/47; 35/35/76; 37/37; 303/303; lint exit 0; build pass; pint pass; discovery 6).
- **Disclosed caveat (accepted)**: the Steps 1–3 RED cycles predate the session interruption and were not re-run; the evidence states this plainly and re-validated their GREEN state at resume rather than fabricating RED history. Nothing in the current suites is skipped, disabled, or weakened (grep-verified; assertion strength reviewed in source).
- **Minor wording imprecision (non-blocking)**: the local-e2e root-cause paragraph references a "pgsql `.env` database"; no `.env` exists (framework defaults apply). The Tester's independent probe (missing `APP_KEY` on production-flagged defaults; `pdo_pgsql` absent per the CI-parity failure and historical probes) proves the same conclusion more strongly; the CI-authoritative deferral is unaffected.

### FakeImageGenerationProvider — §24 judgment

Legitimate provider implementation, not a placeholder. It synthesizes a real, decodable PNG (8-byte signature, IHDR encoding the exact requested dimensions, zlib-deflated IDAT, IEND, CRC32 per chunk), deterministically (SHA-256-seeded scanline bytes, fixed compression level), with no network, subprocess, or model download — exactly what AGENTS.md §21 requires for CI and what spec.md "In Scope" designates as this slice's bound production implementation. Its artifacts are genuinely validated downstream: the job's strict checks (signature, dimensions, mime, size) mark rows failed whose stub providers return malformed bytes, proving the pipeline does not accept fake success states. The model-backed provider remains an explicitly authorized future slice behind the same interface.

### Residual conditions (enforced by the gate, not waived by this review)

- The next CI run on the committed working-tree state must confirm: the six image-studio e2e tests pass across all three viewport projects, and pr-enforcement accepts this evidence file (TDD headings + this approval line). `scripts/merge_gate.py` lists both `E2E CI / e2e` and `governance / pr-enforcement` in `REQUIRED_CHECKS` and is fail-closed, so no merge can occur before that confirmation. The two inert untracked stubs must be deleted, never staged.
- **Working-tree note for the Orchestrator**: executing the required governance verification (`python -m unittest discover -s tests/governance`) regenerated three tracked Python bytecode cache files (`scripts/__pycache__/merge_gate.cpython-314.pyc`, `tests/governance/__pycache__/test_governance.cpython-314.pyc`, `tests/governance/__pycache__/test_merge_gate.cpython-314.pyc`) with this sandbox's Python 3.14 bytecode. This is an unavoidable side effect of running the mandated suite locally, not a source modification; the sandbox denies `git restore` to the Tester, so these three files must be excluded from staging (never committed) and reverted by the Orchestrator before or after staging.

All blocking acceptance criteria are satisfied by direct inspection and independent execution, with the browser-only e2e gate deferred to CI under the ratified Issue #110 precedent and protected fail-closed by the merge gate.

Verdict recorded: approval of commit 5c7570f's functional scope — superseded by the re-review below after CI Round 2 exposed real-geometry styling defects that this approval had not caught.

---

## Independent Tester Re-Review — CI Round 2 real-CSS fix

Re-review performed 2026-10-21 by the Tester agent on branch `@carlosegoulart/113/feat/ai-image-studio-foundation`, against the working-tree fix for the CI Round 2 e2e failures (CI run `39452428016` on commit `a8d3641`, the commit that carried the round-1 approval). Every command and inspection below was executed directly by the Tester in the sandbox; outputs are real, not quoted from Builder claims.

### What the round-1 review missed, and why

The round-1 review (the section above) verified that the studio components carried `min-h-11`, `grid grid-cols-1 lg:grid-cols-2`, and `focus-visible:ring-2`, and recorded those tokens as evidence for AC-09 and the touch-target/responsive checks (see its "Sandbox N/A items", point 5). That was a check of **class-name presence in JSX, not of styling effect**: this project has no Tailwind — `apps/web/src/index.css` is hand-written plain CSS, with no `tailwind*` config file, no `tailwindcss` dependency, and no `@import`/`@source`/`@tailwind` directive anywhere — so those utility tokens were inert strings with zero effect. The unit layer compounded the blind spot: the component tests asserted on the same inert tokens (`toHaveClass('min-h-11')`), so no local suite could catch it. The clip-review reference components the feature was modeled on use semantic class names that *are* defined in `index.css`; the round-1 review should have resolved every geometry-bearing class of the new components against the stylesheet exactly as it did for `.tab-navigation [role="tab"]`. It did not, and it approved a state in which TC-E2E-02's ≥44px and 2-column assertions had no real styling basis. CI then measured the real geometry and failed — independently re-confirmed by the Tester from the run's failed-job log (`gh run view 39452428016 --log-failed`: **99 passed / 3 failed**, all three failures TC-E2E-02; `[mobile]`/`[tablet]` `style-preset touch-target height` expected ≥44 received **19** at spec line 407, `[desktop]` `layout grid column count` expected **2** received **1** at line 366; TC-E2E-01 passed at every viewport, so the functional flow is proven on the CI stack and the defect was styling-only). The e2e spec was correct and is untouched by the fix.

### 1. Stylesheet reality (re-verified from source, not from the Builder report)

Read directly in `apps/web/src/index.css` ("Image Studio (issue #113)" block, lines 783–1147). `git diff HEAD -- apps/web/src/index.css` is **370 insertions, 0 deletions** — purely additive; no pre-existing rule is touched.

- `.image-studio-layout` (807–812): `display: grid; grid-template-columns: 1fr; gap: 32px; align-items: start`, plus `@media (min-width: 1024px) { .image-studio-layout { grid-template-columns: 1fr 1fr } }` (814–818) — the same 1-column-below / 2-columns-at-1024 mechanism as the CI-proven `.review-layout` (clip-review TC-E2E-03 responsive passed at all three viewports in the same CI run).
- `.image-studio-field-control` (846–859): `min-height: 44px` (848), `box-sizing: border-box`, project input look via `var(--border)`/`var(--bg)`; `:focus` (865–869) sets an accent border + 3px box-shadow ring; `:disabled` (871–874).
- `.image-studio-submit-button` (896–915): `min-height: 44px` (902); `:focus-visible` 2px accent outline (930–933); hover/active/disabled states.
- `.image-gallery-retry-button` (1106–1123): `min-height: 44px` (1110); `:focus-visible` outline (1133–1136).
- Badge classes are real definitions, not utilities: `.bg-gray-100` (1024), `.bg-green-100` (1029), `.bg-red-100` (1034), colored with the project palette; the required `--success`/`--success-bg` variables are added to `:root` (12–13) and the dark-mode block (45–46), and every variable the block consumes (`--border`, `--bg`, `--accent`, `--accent-bg`, `--error`, `--error-bg`, `--code-bg`) exists in both.
- Tailwind absence proven three ways: glob `tailwind*` under `apps/web` → no files; `package.json` contains no `tailwindcss`/PostCSS tooling; grep `@import|@source|@tailwind` in `index.css` → no matches. `index.css` (imported by `main.tsx:3`) is therefore the only styling mechanism, and the new semantic classes are the sole carriers of the asserted geometry.
- The rules actually ship: the `npm run build` output `dist/assets/index-ENR2KrwU.css` contains the block (CSS-only tokens `image-studio-spin`/`image-studio-pulse`, which exist nowhere in JSX, verified present in the built bundle).

### 2. e2e selector cross-check (every selector resolves to real backing)

| e2e selector (`e2e/image-studio.spec.ts`) | Component backing | Stylesheet backing |
|---|---|---|
| `.image-studio-layout` (343) | `ImageStudio.tsx:79` — wraps exactly the two element children the spec destructures as `[form, gallery]` | grid `1fr`, `@media 1024px` → `1fr 1fr` |
| `.image-studio header` (377) | `ImageStudio.tsx:72` `header.image-studio-header` inside `section.image-studio` | `.image-studio-header` + global `h2` rule |
| `#image-prompt` (57, 379, 399) | `GenerateImageForm.tsx:102` textarea, `className="image-studio-field-control"` | `min-height: 44px` + `:focus` ring |
| `#image-style-preset` (58, 380, 400) | `GenerateImageForm.tsx:120` select, same class | same |
| `#image-dimensions` (59, 381, 401) | `GenerateImageForm.tsx:136` select, same class | same |
| `.image-studio button[type="submit"]` (60, 381, 402) | `GenerateImageForm.tsx:150–160` `type="submit"`, `className="image-studio-submit-button"` | `min-height: 44px` + `:focus-visible` outline |
| `.image-studio [role="list"]` (382) | `ImageGallery.tsx:103` `role="list"` `aria-label="Generated images"`, `className="image-gallery-list"` | `.image-gallery-list` |
| `#image-studio-tab` (56, 398) | `ProjectMediaSection.tsx:76` `role="tab"` | `.tab-navigation [role="tab"]` `min-height: 44px` (581–595) + `:focus-visible` (601–604) — pre-existing, unchanged |
| `button[aria-label="Back to projects"]` (403) | `ProjectMediaSection.tsx:36` `className="back-button"` | `min-height: 44px` (543–556) — pre-existing |
| badges `bg-gray-100`/`bg-green-100` (256, 275) | `ImageGallery.tsx:16–20` `statusBadgeClasses` — tokens preserved exactly | real classes (1024 / 1029) |

The focus-visibility check (spec 412–443) is satisfied per mechanism: fields are styled on `:focus` (box-shadow — satisfies its `boxShadow !== 'none'` branch), the Generate button and the tab on `:focus-visible` (outline — satisfies its `outlineWidth > 0 && outlineStyle !== 'none'` branch).

### 3. Styling-only confirmation (vs HEAD = `a8d3641`)

- `git diff HEAD --stat`: exactly 8 files — the 3 components, their 3 test files, `index.css`, and this evidence file. `git diff HEAD -- apps/web/e2e/image-studio.spec.ts` is **empty** and the spec is absent from `git status` — the spec was correct and is untouched.
- The three component diffs read line by line: **only `className` strings change** (plus removal of the style-only `selectClassName` constant in `GenerateImageForm` and one explanatory comment in `ImageGallery`). Every id, `htmlFor`, `aria-*` attribute, role, visible text, placeholder, `rows`/`maxLength`, disabled logic, and all behavior code is identical to `a8d3641`; the badge tokens `bg-gray-100`/`bg-green-100`/`bg-red-100` are preserved (inert `dark:` variants dropped).
- The component test diffs update assertions from the inert tokens to the real classes (`image-studio-field-control`, `image-studio-submit-button`, `.image-studio-submit-spinner`, `image-gallery-retry-button`) — same intent (44px touch targets, visible focus), now asserted through classes that actually carry the styles.
- A whole-feature grep for every inert-token family the round-1 components used (`min-h-`, `grid-cols`, `lg:`/`sm:`/`dark:`, `text-2xl`, `animate-spin`, `focus-visible:ring`, `rounded-`, `px-`/`py-`, `space-y`, `bg-white`/`bg-blue`, `sr-only`, `tabular`, `shrink-`) finds **zero matches** — only the three badge class names remain, and they are real classes now.

### 4. Local re-execution (real outputs, 2026-10-21)

```
npm test (apps/web)
→ Test Files  20 passed (20)
   Tests       303 passed (303)

npx vitest run src/features/image-studio (apps/web)
→ Test Files  3 passed (3)
   Tests       37 passed (37)

npm run lint (apps/web)
→ exit 0 — warnings only; the same advisory set as the approved baseline
  (clip-review warnings plus the two set-state-in-effect warnings on
  unchanged image-studio lines; none reference the new classes)

npm run build (apps/web)
→ ✓ 93 modules transformed
  dist/assets/index-ENR2KrwU.css  15.88 kB │ gzip:  3.26 kB
  dist/assets/index-oFSJoOBh.js   314.46 kB │ gzip:  92.56 kB

npx playwright test e2e/image-studio.spec.ts --list (apps/web)
→ Total: 6 tests in 1 file
  [mobile|tablet|desktop] × {TC-E2E-01 flow, TC-E2E-02 responsive}
```

CI state independently corroborated: `gh pr checks 114` (for `a8d3641`) — e2e **FAIL** (run 39452428016); governance, pr-enforcement, test (frontend), tests (backend) **PASS**; the failed-job log reproduces the three TC-E2E-02 failures and the 99-passed total quoted above.

Sandbox limits, honestly scoped: the full e2e remains impossible locally (missing `pdo_pgsql` plus no `apps/api/.env` — the ratified Issue #110 precedent; CI is the authoritative e2e venue). The Tester additionally attempted a real-browser static-DOM geometry measurement against the built CSS outside the repo tree; the sandbox permission system denied every avenue (scratch-file creation, `node -e`, raw `php`/`sed` probes), so **no geometry numbers are claimed from this sandbox** — the measured-geometry verdict is deliberately left to CI. The governance python suite was not re-run this round, for three reviewed reasons: running it regenerates tracked `__pycache__` bytecode (the round-1 working-tree note), this round's diff touches no governance-owned file, and CI ran governance + pr-enforcement green on `a8d3641`; the evidence-file rules were instead verified directly against `tests/governance/validators.py` and `scripts/merge_gate.py` (exactly one `Decision:` line required; the `### RED`/`### GREEN`/`### REFACTOR` headings are preserved by this append-only edit).

### 5. Scope re-check

Working tree vs `a8d3641`: only `apps/web/src/**` (`index.css` + the image-studio feature) and this evidence file. The two untracked stubs (`apps/api/env-probe-113.php`, `apps/api/phpunit-ci-parity.xml`) are re-confirmed comment-only and inert, remain untracked/unstaged, and are still flagged for Orchestrator deletion. No `.opencode/**`, `tests/governance/**`, CI, Docker, or dependency changes; no new packages.

### Non-blocking observations

- `ProjectMediaSection.tsx` tab buttons (including the Image Studio tab added by this issue) still carry inert color utility strings (`border-blue-600`, `dark:*`, …). The visible selected state is carried by the real `.tab-navigation [role="tab"][aria-selected="true"]` rule and no e2e assertion depends on those tokens; recorded for future Planner consideration, not a defect against this issue's acceptance criteria.
- The corrected component tests assert class names rather than computed geometry; genuine ≥44px measurement lives in the e2e layer. That is the established project split and is acceptable because CI exercises the geometry fail-closed.

### Verdict

No blocking defect. The previously unbacked geometry assertions now have a verified real stylesheet basis at three levels — source CSS, built production bundle, and component-to-selector resolution — the change is styling-only with the e2e spec untouched, and all mandated local gates pass with the expected counts. **This approval is explicitly conditional on the next CI e2e run executing TC-E2E-01 and TC-E2E-02 green at all three configured viewports (390x844, 768x1024, 1440x900); that run is the authoritative final geometry verdict for this fix.** The condition is enforced fail-closed: `scripts/merge_gate.py` lists `E2E CI / e2e` and `governance / pr-enforcement` in `REQUIRED_CHECKS`, so no merge can occur before that CI confirmation.

Decision: APPROVE
