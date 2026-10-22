# Evidence: Signed Image Download URLs + Gallery Previews — Issue #115

## Builder Implementation Report

Implemented on branch `@carlosegoulart/115/feat/image-download-urls` against the
ratified planning bundle in this directory. The issue spans three venues:
backend (download endpoint + 12-field resource), frontend (gallery preview +
download link + CSS), and the e2e spec deltas (including the AC-07 cleanliness
predicate refinement). One environment constraint governs this report and is
stated up front, then again in each affected section: the sandbox PHP 8.3 CLI
has **no `pdo_sqlite`/`sqlite3`/`pdo_pgsql` extensions** (the extension
directory `/usr/lib/php/20230831/` contains none of the three `.so` files), so
every database-backed Pest run — RED and GREEN — fails at `TestCase` bootstrap
with `could not find driver (Connection: sqlite, Database: :memory:)`. That is
an **environment failure, not behavioral RED and not GREEN** (Issue #113
ratified precedent: backend Pest is CI-authoritative in this sandbox). The
backend RED/GREEN evidence below is therefore structural (tests authored
before implementation, all 67 discovered by the runner) plus route-level
verification through permitted `php artisan` surfaces; the authoritative
execution venues are the CI backend `tests` job (PostgreSQL + MinIO) and the
CI `e2e` job. Frontend RED/GREEN was executed for real, locally, and is
recorded with exact outputs.

### RED

#### Backend (plan Steps 1–3 — TC-BE-01/02)

Tests were authored before any implementation existed. Edits to the three
existing suites (spec-authorized additive amendment, 11 → 12 fields):

- `GenerateImageEndpointTest`: `WHITELISTED_FIELDS` gains `download_url`; the
  202 contract test now asserts `$data['download_url']` is `null`.
- `ImageShowTest`: `SHOW_WHITELISTED_FIELDS` gains `download_url`; the show
  contract test asserts `null`; **3 new tests** — signed URL for a generated
  image (string, contains `images/{id}/download`, `signature`, `expires`, and
  NOT the route name `image.download` — the M7 Issue #106 ratified lesson),
  `null` for a failed image, and the URL is minted for the owning image id
  only (id-isolation).
- `ImageListTest`: `LIST_WHITELISTED_FIELDS` gains `download_url`; **1 new
  test** — per-status `download_url` in one list response (generated → signed
  string with the three tokens; pending and failed → `null`).

New suites:

- `tests/Feature/ImageStudio/ImageDownloadTest.php` — **12 tests** (TC-BE-01):
  owner valid signature → 302 with non-empty `Location`; owner session
  unsigned → 302; expired signature → 403; tampered signature → 403; non-owner
  unsigned → 404; non-existent unsigned → 404; guest unsigned (valid CSRF) →
  401; signed-pending → 404; session-pending → 404; session-failed → 404;
  storage-failure → 500 with a body leaking no storage key / "bucket" / prompt
  text; route registration outside `auth:sanctum` with no throttle middleware.
- `tests/Feature/ImageStudio/ImageDownloadRealIntegrationTest.php` — **2
  group-gated tests** (TC-BE-03, `->group('real-integration')`, AC-08):
  real presigned MinIO round trip serving byte-exact PNG content, plus a
  grep-verifiable no-mocks test. Availability-guarded with
  `markTestSkipped` when the media disk is not S3 or MinIO is unreachable —
  a local skip is environmental, never RED and never GREEN (test-plan §3).

**RED run attempt (recorded honestly as an environment failure, not
behavioral RED):**

```
php artisan test tests/Feature/ImageStudio --compact   (apps/api)
→ {"tool":"pest","result":"failed","tests":67,"passed":0,"assertions":0,
   "errors":67}
→ every one of the 67 tests errors identically at bootstrap:
  "could not find driver (Connection: sqlite, Database: :memory:,
   SQL: select exists (select 1 from \"main\".sqlite_master ...))"
  at vendor/.../Connector.php:66 ← tests/TestCase.php:13 ← SpaTestCase.php:16
```

The runner did discover the full issue-scoped set (67 = the pre-existing 49
plus 12 `ImageDownloadTest` + 3 `ImageShowTest` + 1 `ImageListTest` + 2
real-integration), so the suites parse and register; only execution is
blocked. Per the test-plan's setup-failure rule and the Issue #113 precedent,
no behavioral RED is claimed for the backend — the tests were authored before
the implementation, and the authoritative RED→GREEN execution is the CI
backend `tests` job.

#### Frontend (plan Step 4.1 — TC-FE-01/02)

Fixture-only `download_url: null` additions to both `makeImage` builders
(`ImageGallery.test.tsx`, `ImageStudio.test.tsx`), then **6 new component
tests** (TC-FE-01 × 3: preview alt/dimensions/lazy, download-link attrs,
onError fallback keeping prompt/badge/meta/link; TC-FE-02 × 3: no img and no
link for pending, failed, and generated-with-null-url). With the component
still rendering no img/link, the run produced valid behavioral RED:

```
npm test -- --run src/features/image-studio   (apps/web)
→ Test Files  1 failed | 2 passed (3)
   Tests  3 failed | 40 passed (43)
→ failures (all three TC-FE-01 required-behavior tests):
  "TestingLibraryElementError: Unable to find an accessible element with the
   role \"img\" and name \"Generated image: A serene mountain lake at sunrise\""
  "Unable to find an accessible element with the role \"link\" and name
   \"Download image\""
→ the rendered DOM in the failure dump shows the card with prompt/badge/meta
   only — the failure is missing behavior, not a setup or environment error.
```

(The three TC-FE-02 tests pass vacuously pre-implementation — the component
rendered no img/link for any status — and remain as the defensive-branch
guard; this is disclosed rather than presented as RED.)

### GREEN

#### Backend (plan Steps 1–2 — TC-BE-01/02)

Implementation, exactly per the backend contract:

- `routes/api.php`: `GET /images/{image}/download` named `image.download`,
  registered inside the `Route::prefix('v1')` group and **outside** the
  `auth:sanctum` group, adjacent to the `clip-review.video` precedent, with
  no `throttle:` middleware (M7 Issue #106 lesson: inside the group, an
  unauthenticated tampered-signature request would 401 before the signature
  check).
- `ImageController::download(Request, Image): RedirectResponse` — the dual
  gate first (`signature` present → `abort_unless($request->hasValidSignature(),
  403)`; absent → no user → 401, `image.user_id !== user->id` → 404), then the
  artifact guard (`status !== generated` → 404 for both gates), then
  `Storage::disk($image->storage_disk)->temporaryUrl($image->storage_key,
  now()->addHour())` → `redirect()->away($url)` (302), with the exception
  path logging `image_id` + exception message only (no prompt content, no
  bucket config) and aborting 500 (fail closed).
- `ImageResource::downloadUrl()` — `URL::temporarySignedRoute('image.download',
  now()->addHour(), ['image' => $this->id])` for generated images, `null`
  otherwise; `download_url` added to `toArray` (the 12th field). Status is
  normalized whether it arrives as the `ImageStatus` enum or its string value.

**Route-level verification (real, permitted artisan output):**

```
php artisan route:list --name=image
→ GET|HEAD api/v1/images/{image}/download image.download › Api\V1\Image…
   Showing [1] routes

php artisan route:list --name=image.download --json
→ [{"method":"GET|HEAD","uri":"api/v1/images/{image}/download",
    "name":"image.download",
    "action":"App\\Http\\Controllers\\Api\\V1\\ImageController@download",
    "middleware":["api"], ...}]
→ middleware is exactly ["api"]: no auth:sanctum, no throttle entries —
  the route-registration test's core assertions, verified live.
```

**GREEN run attempt (same environment blocker, recorded honestly — no
observed backend GREEN is claimed locally):**

```
php artisan test tests/Feature/ImageStudio --compact
→ same 67/67 bootstrap errors ("could not find driver … sqlite :memory:").
```

The authoritative GREEN venue is the CI backend `tests` job, which provisions
PostgreSQL + `pdo_pgsql` + MinIO and runs the full tree plus the
`real-integration` group (Issue #113 CI precedent: the identical suite family
ran green there on the previous slice). `vendor/bin/pint --dirty` confirms
every changed PHP file parses cleanly.

#### Frontend (plan Step 4.2 — TC-FE-01/02)

- `types.ts`: required `download_url: string | null` on `ImageItem` (the API
  always sends the key).
- `ImageGallery.tsx`: internal `ImagePreview` component with `useState` error
  state; for `status === 'generated'` **and** a non-null `download_url`, the
  card renders (after head → meta → pending hint) the keyed preview
  (`key={${image.id}-${downloadUrl}}` so a refreshed signature remounts and
  retries) — `<img className="image-gallery-card-preview">` with `src`,
  exact alt `Generated image: {60-char excerpt}`, `width`/`height`
  attributes from the image dimensions, `loading="lazy"`, `onError` →
  `<p className="image-gallery-preview-fallback">Preview unavailable</p>`
  instead of the img (no refetch loop) — followed by
  `<a className="image-gallery-download" href target="_blank"
  rel="noopener noreferrer">Download image</a>`, the last focusable inside
  the panel. Pending/failed/null-url entries render no img and no link. The
  stale "No image bytes are fetched in this slice" comment was replaced with
  the signed-preview description.
- `index.css`: `.image-gallery-card-preview` (`display:block; width:100%;
  max-width:100%; height:auto; box-sizing:border-box` per-rule — no global
  reset; `object-fit:contain`; `border-radius:8px`; `background:var(--code-bg)`),
  `.image-gallery-preview-fallback` (body `--text` color: 5.7:1 on light,
  7.7:1 on dark — both above 4.5:1), `.image-gallery-download`
  (`inline-flex`, `min-height:44px`, `--accent` link color: 5.2:1 light /
  7.7:1 dark) and its `:focus-visible` outline. No other rule touched.

**GREEN run (executed for real, locally):**

```
npm test -- --run src/features/image-studio
→ Test Files  3 passed (3)
   Tests  43 passed (43)     ← the 3 RED tests now pass; 40 still green

npm test -- --run            (full suite)
→ Test Files  20 passed (20)
   Tests  309 passed (309)   ← 303 pre-existing + 6 new; zero regressions
```

### REFACTOR

- **API.** `vendor/bin/pint --dirty --format agent` run after the backend
  implementation and again after the final state: **passed** both times (no
  fixes needed). No structural refactor was required — the controller mirrors
  the proven `ClipReviewController::video` dual-gate shape and the resource
  helper stays a single-purpose private method.
- **Frontend.** `npm run lint` (oxlint): **exit 0 — zero errors**; warnings
  only, all pre-existing (clip-review components plus the two
  `react(set-state-in-effect)` warnings Issue #113 already recorded);
  `ImageGallery.tsx`, the test files, and the e2e spec produce no warnings.
  `npm run build` (`tsc -b && vite build`): **passed** — 93 modules,
  `index-*.css` 16.56 kB (gzip 3.37), `index-*.js` 315.07 kB (gzip 92.75).
  The required `download_url` field type-checks in both fixture builders and
  the production tree.
- **Post-refactor stability.** The affected suites were re-run after the
  e2e-spec deltas landed and remained green: full Vitest **309/309**;
  feature **43/43**; Pint passed; build passed.

## Playwright E2E spec (plan Step 5 — TC-E2E-01..03 extensions)

`apps/web/e2e/image-studio.spec.ts` is the only e2e file touched. Exactly the
ratified deltas from spec.md "E2E Contract" were applied:

1. `IMAGE_FIELD_WHITELIST` 11 → **12** (adds `download_url`).
2. `runGenerateFlow`: 202 body asserts `download_url === null`; after the
   existing Generated-badge convergence, the refetch body's generated item
   `download_url` is asserted to be a string containing
   `images/{id}/download`, `signature=`, and `expires=`; the preview
   `getByRole('img', { name: /^Generated image:/ })` must be visible and then
   report `naturalWidth > 0` (bounded `expect.poll` timeout 10000 ms — the
   chain proof: API 302 → signed local serve route → 200 PNG bytes); the
   `Download image` link href matches the three required regexes.
3. **Focus walk**: `IMAGE_STUDIO_FOCUS_TARGETS` gains
   `{ key: 'download', selector: '.image-gallery-download' }` (6 targets); the
   forward walk is the explicitly permitted bounded form (cap 8 presses,
   recording matched targets only) because the assertions keep full strength —
   `toHaveLength(IMAGE_STUDIO_FOCUS_TARGETS.length)` (6) and `=== true` for
   **all six** at all three viewports.
4. **Touch map** gains `download: height('.image-gallery-download')` (≥44
   asserted by the existing loop); **clipping list** gains
   `.image-gallery-card-preview` (still `toBe 0`).
5. **AC-07 predicate (the single change inside `trackCleanliness`)**, matching
   the authorized end state verbatim: `amazonaws|bucket|:9000` remain fatal
   unconditionally; the Slice 1 `storage` token is now fatal only off the API
   origin (`new URL(url).host === '127.0.0.1:8000'` exemption); the
   `expect(cleanliness.storageRequests).toEqual([])` assertion and every
   other collector (console mirror exception, pageerror, 4xx/5xx, browser
   storage) are byte-identical to Slice 1.
6. Stale "no byte delivery" comments updated (file header + collector
   comment); no other comment or code touched.

**Discovery (required local check, no browser needed):**

```
npx playwright test e2e/image-studio.spec.ts --list
→ Listing tests:
  [mobile]  › image-studio.spec.ts:336 › … TC-E2E-01 generate-image flow…
  [mobile]  › image-studio.spec.ts:356 › … TC-E2E-02 responsive layout…
  [tablet]  › (same two)
  [desktop] › (same two)
  Total: 6 tests in 1 file
```

**Execution — deferred to CI with the exact environment reason** (Issue
#110/#113 ratified precedent): the Playwright webServer boots Laravel, whose
database layer fails on every DB-backed route because the sandbox PHP lacks
`pdo_sqlite` (webServer config uses the default sqlite testing database) and
`pdo_pgsql`; no `.env` exists for `apps/api`, so framework defaults apply.
The authoritative venue is the CI `e2e` job (PostgreSQL provisioned,
`MEDIA_DISK=local` with `serve => true` — the exact chain the new
`naturalWidth > 0` poll proves). Local verification is `--list` only, exactly
as the test-plan prescribes.

## Real-integration group (plan Step 3 — AC-08)

`ImageDownloadRealIntegrationTest` was authored in the RED phase (before the
endpoint existed), mirrors `ClipReviewRealIntegrationTest` structure
(`uses(TestCase::class, RefreshDatabase::class)`,
`->group('real-integration')`, availability guards), and contains **no
`Storage::fake`, no `Queue::fake`, no `Http::fake`** (grep-verified — the only
occurrences of those strings are the comments documenting their absence). It
stores real PNG bytes, mints the API signed URL semantics, follows the 302 to
the MinIO-hosted presigned URL, and asserts the HTTP GET returns the exact
stored bytes. Local run: not executed — skip is environmental (MinIO not
configured as the default disk in this sandbox's testing env), never RED and
never GREEN. CI backend `tests` job is the fail-closed venue.

## Full-suite verification (plan §7)

| Command (working dir) | Result |
|---|---|
| Baseline before implementation: `php artisan test --exclude-group=real-integration --compact` (apps/api) | **1593 tests: 1040 passed, 4 failed** — every failure/error environmental and pre-existing (HealthTest 503 on DB env; the remainder are the driver-blocked DB suites described above). None in this issue's files. |
| `docker compose up -d postgres` (repo root) | Container started (HealthTest failures unchanged — still driver/env-bound) |
| `php artisan test tests/Feature/ImageStudio --compact` (apps/api) | **67 tests, 67 environment errors** — all `could not find driver (Connection: sqlite, Database: :memory:)` at `TestCase` bootstrap. All 67 discovered. Not behavioral RED, not GREEN; CI-authoritative (Issue #113 precedent). |
| `php artisan route:list --name=image.download --json` (apps/api) | Route present: `GET|HEAD api/v1/images/{image}/download`, `ImageController@download`, middleware exactly `["api"]` (no sanctum, no throttle) |
| `vendor/bin/pint --dirty --format agent` (apps/api) | **passed** (after implementation and at final state) |
| `npm test -- --run src/features/image-studio` (apps/web) | **3 files, 43/43 passed** (TC-FE-01/02 + all pre-existing; RED→GREEN cycle recorded above) |
| `npm test -- --run` (apps/web, full) | **20 files, 309/309 passed** (303 pre-existing + 6 new) |
| `npm run lint` (apps/web) | **exit 0** — zero errors; warnings only, all pre-existing |
| `npm run build` (apps/web) | **passed** (`tsc -b` + vite build, 93 modules) |
| `npx playwright test e2e/image-studio.spec.ts --list` (apps/web) | **6 tests in 1 file** (non-empty discovery, 3 viewport projects) |
| `npm run test:e2e` | **Deferred to CI** — exact reason above (sandbox PHP lacks all DB drivers; webServer cannot boot the DB-backed app). CI is the authoritative venue; the merge gate is fail-closed on the `e2e` check. |
| `real-integration` group | Authored, group-gated, no-mocks (grep-verified); local skip is environmental; CI backend `tests` job executes it. |
| Governance suite (`python -m unittest discover -s tests/governance`) | **Not executable by Builder** (sandbox permission system denies `python`); governance is enforced in CI. |

## Acceptance criteria coverage

| AC | Status | Evidence |
|---|---|---|
| AC-01 | Implemented; execution CI-gated | Route + dual gate + 302 redirect in code; `route:list` proves name/URI/middleware; `ImageDownloadTest` covers valid-signature 302, session 302, 403 expired, 403 tampered, 404 non-owner, 404 non-existent, 401 guest |
| AC-02 | Implemented; execution CI-gated | Artifact guard 404 (signed + session pending, session failed) and sanitized-500 storage-failure test (body leaks no key/bucket/prompt) |
| AC-03 | Implemented; execution CI-gated | 12-field resource with `downloadUrl()`; exact key-set constants updated in all three suites + e2e whitelist; per-status assertions on 202/show/list; no-leak assertions retained |
| AC-04 | **Locally verified green** (Vitest 43/43) | Preview img (exact alt, intrinsic dimensions, lazy), download link (href/target/rel), onError fallback keeping card contents, no preview for pending/failed/null-url |
| AC-05 | Implemented; e2e CI-gated | Per-rule `box-sizing`, `width:100%/height:auto/object-fit:contain` preview CSS; preview in clipping list; touch map ≥44; existing layout/focus checks unchanged (full Vitest suite green) |
| AC-06 | Implemented; e2e CI-authoritative | `naturalWidth > 0` poll (≤10s) chain proof, href regexes, 12-field whitelist, 6-target focus walk with full-strength length assertions — all in the spec; `--list` confirms discovery |
| AC-07 | Implemented; e2e CI-authoritative | Predicate matches the authorized end state verbatim; only the request collector changed inside `trackCleanliness`; all other collectors/assertions byte-identical |
| AC-08 | Implemented; CI-gated | `ImageDownloadRealIntegrationTest` (MinIO presigned round trip, byte-exact GET, no mocks); group-gated + availability-guarded |
| AC-09 | Partial locally (environment) | Vitest 309/309, lint exit 0, build pass, Pint pass — executed. Backend Pest and governance — sandbox-blocked, CI-authoritative. No skipped tests outside the group-gated real-integration cases |
| AC-10 | Implemented; no scope creep | Full Vitest suite green (303 pre-existing all pass); `git status` shows only the 12 authorized files + 2 new test files; no provider/job/migration/dependency changes |

## Security / scope notes

- **Dual gate first**: invalid/expired/tampered signatures reject 403 before
  any ownership or storage work; unsigned falls back to session ownership
  (401/404). Mirrors the proven `clip-review.video` pattern.
- **Two 1-hour expiries**: API signature at issuance; storage `temporaryUrl`
  at click time. Expired artifacts degrade to the UI fallback until a refetch
  mints fresh URLs (keyed remount in `ImagePreview`).
- **No storage internals in any response**: the 12-field whitelist is
  asserted by exact key set on 202, show, and list; `storage_disk`,
  `storage_key`, `user_id`, and bucket names remain absent everywhere. The
  302 `Location` target is the only place a storage key appears (M7 accepted
  presigned-URL precedent).
- **Sanitized failure log**: `image_id` + exception message only — no prompt
  content, no credentials, no bucket config.
- **Route outside `auth:sanctum`, no throttle** — verified live via
  `route:list --json` (`middleware: ["api"]`).
- No `.env` secrets were read or inspected. No new composer or npm
  dependencies. No TODO/FIXME/placeholder code. Working tree (12 modified +
  2 new files) is entirely within the issue's authorized surface; no
  Planner-owned files, no `.opencode/**`, no `tests/governance/**`, no
  `scripts/merge_gate.py`, no CI workflows, no `playwright.config.ts`, no
  `docs/roadmap.md`/`docs/project-state.md`.

## Remaining gates (not claimed complete)

- Independent Tester review and CI (backend `tests` incl. the
  `real-integration` group, frontend, e2e across all three viewports,
  governance) remain the approval authority. Backend Pest execution and
  Playwright execution are CI-authoritative for the environment reasons
  recorded above; the merge gate is fail-closed on those checks.
- The Tester appends the explicit evidence decision; the Builder makes no
  decision claim.

Builder: IMPLEMENTED

---

## Tester Review

Independent Tester quality-gate review of Issue #115 on branch
`@carlosegoulart/115/feat/image-download-urls` (uncommitted working tree vs
master @ `11798ed`, the PR #114 merge). AC-01..AC-10 verified against the
normative `spec.md`/`test-plan.md`, the actual diff, and freshly executed
local commands. The Builder's self-report was treated as untrusted and every
locally executable claim was re-executed; all matched (discrepancies: none).

### Commands run (Tester, observed outputs)

| Command (cwd) | Observed result |
|---|---|
| `npm test -- --run` (apps/web) | `Test Files 20 passed (20)` / `Tests 309 passed (309)` — matches Builder claim |
| `npm test -- --run src/features/image-studio` (apps/web) | `Test Files 3 passed (3)` / `Tests 43 passed (43)` — matches |
| `npm run lint` (apps/web) | exit 0; warnings only, all pre-existing (clip-review components + the two Issue #113-recorded `react(set-state-in-effect)` warnings); no warnings in `ImageGallery.tsx`, the image-studio tests, or the e2e spec |
| `npm run build` (apps/web) | passed; 93 modules, `index-*.css` 16.56 kB (gzip 3.37), `index-*.js` 315.07 kB (gzip 92.75) — byte-for-byte the Builder-claimed output |
| `php artisan route:list --name=image.download --json` (apps/api) | `[{"method":"GET|HEAD","uri":"api\/v1\/images\/{image}\/download","name":"image.download","action":"...ImageController@download","middleware":["api"]}]` — exactly `["api"]`: no `auth:sanctum`, no `throttle` |
| `vendor/bin/pint --dirty --format agent` (apps/api) | `{"tool":"pint","result":"passed"}` |
| `npx playwright test e2e/image-studio.spec.ts --list` (apps/web) | `Total: 6 tests in 1 file` (2 tests × mobile/tablet/desktop) |
| `php artisan test tests/Feature/ImageStudio --compact` (apps/api) | `{"tests":67,...,"errors":67}` — every test errors identically at bootstrap: `could not find driver (Connection: sqlite, Database: :memory:)` at `Connector.php:66 ← tests/TestCase.php:13 ← SpaTestCase.php:16`. Environment failure, not RED, not GREEN; reproduces the Builder-reported blocker exactly. CI-authoritative per Issue #113/#110 ratified precedent. |

### Backend contract verification (code + registration, execution CI-gated)

- **Route topology (AC-01)**: `routes/api.php:87-88` registers
  `GET /images/{image}/download` named `image.download` inside the
  `Route::prefix('v1')` group, outside the `auth:sanctum` group, adjacent to
  the `clip-review.video` precedent (lines 80-81), with no `throttle:`
  middleware — confirmed both in source and live via `route:list --json`
  (`middleware: ["api"]`).
- **Dual gate order**: `ImageController::download` (lines 132-171) executes
  signature-present → `abort_unless($request->hasValidSignature(), 403)` first;
  signature-absent → `! $user` → 401, `user_id` mismatch → 404; then the
  artifact guard `status !== ImageStatus::GENERATED` → 404 for both gates;
  then `Storage::disk($image->storage_disk)->temporaryUrl($image->storage_key,
  now()->addHour())` → `redirect()->away()` (302); `\Exception` catch →
  `Log::error` with `image_id` + exception message only → `abort(500)`.
  Return type is `RedirectResponse` (M7 TypeError lesson respected). Structurally
  identical in ordering to `ClipReviewController::video` (lines 156-193).
- **`ImageResource` (AC-03)**: exactly 12 keys emitted (id, project_id, prompt,
  parameters, mime_type, size_bytes, width, height, status, `download_url`,
  created_at, updated_at); `downloadUrl()` uses
  `URL::temporarySignedRoute('image.download', now()->addHour(), ['image' => $this->id])`
  for generated, `null` otherwise, with enum/string status normalization. No
  `storage_disk`/`storage_key`/`user_id`/bucket key exists in the resource.
- **Whitelist amendments**: the three backend constants and the e2e
  `IMAGE_FIELD_WHITELIST` gained `download_url` additively (verified in diff);
  no assertion was removed, relaxed, or subsetted anywhere in the diff.
- **`ImageDownloadTest` (12 tests)** covers every TC-BE-02 row: owner-valid
  signature 302, owner-session 302, expired 403, tampered 403, non-owner 404,
  non-existent 404, guest 401 (valid CSRF), signed-pending 404, session-pending
  404, session-failed 404, storage-failure 500 (asserts the body leaks no
  `storage_key`, no "bucket", no prompt text), and route registration outside
  sanctum with no throttle (via `gatherMiddleware()`).

### AC-07 / TC-E2E-03 precision check (the sensitive area)

Diffed `apps/web/e2e/image-studio.spec.ts` against the Slice 1 baseline
embedded at master (PR #114 merge — the diff IS the slice-to-slice delta):

1. **Request-collector predicate**: the file at lines 131-139 matches the
   spec's "Authorized end state" code block **verbatim**:
   `amazonaws|bucket|:9000` fatal unconditionally; `storage` fatal only when
   `new URL(url).host !== '127.0.0.1:8000'`. No token narrowed or widened.
2. **`expectClean`**: zero hunks in the diff — `expect(cleanliness.storageRequests).toEqual([])`
   and all four assertions byte-identical to Slice 1.
3. **Other collectors**: zero hunks on the console mirror + `/api/v1/auth/me`
   401 exception, `pageerror`, 4xx/5xx, or `localStorage`/`sessionStorage`
   blocks. No collector added, removed, or modified.
4. **Authorized comments**: header block and the collector comment (lines
   76-83) updated to the new intent — comments only, as authorized.
5. **TC-E2E-03 delta checklist = exactly the four items**: (1) predicate
   replaced; (2) whitelist 11 → 12; (3) stale "no byte delivery" comments
   updated; (4) structure/all other predicates byte-identical. Nothing else in
   the cleanliness section changed.

### Remaining e2e deltas (TC-E2E-01/02, plan Step 5.1 — verified within budget)

- `IMAGE_FIELD_WHITELIST` = 12 sorted values including `download_url`.
- `runGenerateFlow`: 202 asserts `download_url === null`; refetch asserts
  string containing `images/{id}/download`, `signature=`, `expires=`;
  `getByRole('img', { name: /^Generated image:/ })` visible then bounded
  `expect.poll` (timeout 10000) on `naturalWidth > 0`; download-link href
  matches the three required regexes. All additive.
- `IMAGE_STUDIO_FOCUS_TARGETS` = 6 entries adding
  `{ key: 'download', selector: '.image-gallery-download' }`; forward walk is
  the explicitly permitted bounded form (cap 8, early exit when all matched);
  assertions keep full strength —
  `toHaveLength(IMAGE_STUDIO_FOCUS_TARGETS.length)` (not hardcoded) and
  `focusVisible[key] === true` for all six.
- Touch map gains `download` (≥44 asserted by the existing loop, unchanged);
  clipping list gains `.image-gallery-card-preview` (still `toBe 0`).

### Frontend contract (locally executed green)

- `types.ts`: required `download_url: string | null`; both `makeImage`
  fixtures add `download_url: null` (spec-authorized).
- `ImageGallery.tsx`: `ImagePreview` internal component with `useState`
  error; renders `<img className="image-gallery-card-preview">` with exact alt
  `Generated image: {60-char excerpt}` (`PROMPT_EXCERPT_LENGTH = 60`
  pre-existing), `width`/`height` from the image, `loading="lazy"`, `onError`
  → `<p className="image-gallery-preview-fallback">Preview unavailable</p>`
  instead of the img (no refetch loop); keyed `` `${image.id}-${downloadUrl}` ``;
  download `<a className="image-gallery-download" target="_blank"
  rel="noopener noreferrer">Download image</a>` after the preview. Rendered
  ONLY for `status === 'generated' && downloadUrl !== null`. DOM order
  head → meta → pending hint → preview → link. Stale comment replaced.
- `index.css`: purely additive (49 insertions, 0 deletions).
  `.image-gallery-card-preview` carries per-rule `box-sizing: border-box`
  plus `width:100%`, `max-width:100%`, `height:auto`, `object-fit:contain`;
  no global reset added (the pre-existing `*` selector at line 328 is the
  reduced-motion rule, untouched). `.image-gallery-download` has
  `min-height:44px` and a `:focus-visible` outline. No other rule touched.
- Six new Vitest cases (TC-FE-01 × 3, TC-FE-02 × 3) cover preview attrs,
  link attrs, onError fallback retaining prompt/badge/meta/link, and
  no-preview for pending/failed/null-url. Full suite 309/309 executed.

### Real-integration group (AC-08, code-verified; execution CI-gated)

- `ImageDownloadRealIntegrationTest` is `->group('real-integration')` on both
  tests, availability-guarded (S3 driver check + live put/delete probe →
  `markTestSkipped`), and mirrors `ClipReviewRealIntegrationTest` structure
  (`TestCase` + `RefreshDatabase`, unique uuid-suffixed storage key, `finally`
  cleanup).
- Grep-verified no-mocks: the only `Storage::fake`/`Queue::fake` occurrences
  in the file are comments documenting their absence; no `Http::fake` at all.
- Asserts: direct controller call with owner user resolver → 302; Location
  host/port equal the configured media endpoint; path contains
  `/{bucket}/{storage_key}`; query has `X-Amz-Signature` + `X-Amz-Expires`;
  `Http::timeout(30)->get($location)` → 200 with byte-exact body equal to the
  written PNG-signature-prefixed bytes.

### Scope audit

`git status --porcelain` shows exactly the 12 modified files authorized by
plan Step 5.1/the spec, the 2 new test files, and this untracked
`evidence.md`. NO changes to: `.opencode/**`, `tests/governance/**`,
`scripts/merge_gate.py`, `docs/roadmap.md`, `docs/project-state.md`,
`specs/113-.../**` or any other Planner-owned spec/plan/test-plan file,
`composer.json`, `package.json`, `playwright.config.ts`, CI workflows,
providers, queue jobs, models, migrations, or config. Grep found no
TODO/FIXME/placeholder in any touched production or test file. No assertions
deleted, relaxed, skipped, or hardcoded; `markTestSkipped` appears only in
the real-integration availability guard.

### Evidence accuracy

Every Builder claim that could be independently re-executed locally reproduced
exactly (Vitest 309/309 and 43/43, lint exit 0 with only pre-existing
warnings, build module count and asset sizes, Pint pass, `route:list --json`
middleware shape, Playwright `--list` 6 tests, 67 discovered backend tests
with the identical bootstrap driver error). No discrepancy found between
`evidence.md` and the actual diff.

### AC verdicts

| AC | Verdict |
|---|---|
| AC-01 | SATISFIED (code + live route registration verified; HTTP execution CI-gated) |
| AC-02 | SATISFIED (artifact guard both gates + sanitized 500; execution CI-gated) |
| AC-03 | SATISFIED (12-field resource + additive constant amendments; execution CI-gated) |
| AC-04 | SATISFIED (Vitest 43/43 executed locally, preview/link/fallback/no-preview all covered) |
| AC-05 | SATISFIED (per-rule box-sizing, clipping/touch integration; viewport execution CI-gated) |
| AC-06 | SATISFIED (spec deltas present at full strength; Playwright execution CI-authoritative) |
| AC-07 | SATISFIED (predicate verbatim vs Authorized end state; all other collectors byte-identical; delta = exactly four items) |
| AC-08 | SATISFIED (group-gated, availability-guarded, no-mocks grep-verified, byte-exact round trip; CI backend job authoritative) |
| AC-09 | SATISFIED (local: Vitest/lint/build/Pint green; backend Pest environment-blocked — ratified CI-authoritative venue; no skips outside real-integration guard) |
| AC-10 | SATISFIED (scope audit clean; full Vitest green; no provider/job/migration/dependency changes) |

### CI-authoritative venues (explicitly the remaining gate)

Per the Issue #110/#113 ratified precedent, the following remain CI-gated and
are correctly identified as such by the Builder and the plan: full backend
Pest execution (this sandbox's PHP lacks `pdo_sqlite`/`pdo_pgsql` — reproduced
identically), the `real-integration` MinIO group, Playwright e2e execution
(including visual QA at 390x844 / 768x1024 / 1440x900, console/network/API
inspection, and the running-app byte-chain proof), and governance. Visual
browser-facing behavior was reviewed by reading `ImageGallery.tsx`, the CSS,
and the e2e code carefully; live Playwright interaction is CI-authoritative.
The merge gate (`scripts/merge_gate.py`) is fail-closed on `e2e`,
`governance`, `pr-enforcement`, `test`, and `tests`.

Decision: APPROVE

---

## CI Round 1 Correction

CI backend `tests` job (run 39767567074) failed with **two real
test-assertion defects**, both in
`apps/api/tests/Feature/ImageStudio/ImageDownloadTest.php`. Both were
**test bugs, not production defects** — the controller and route were
correct and are **unchanged** by this correction.

### Defect 1 — non-owner 404 test requested the wrong image

The test logged in as `$this->otherUser` and then requested
`$this->otherUserImage`, which `beforeEach` creates with
`'user_id' => $this->otherUser->id`. The session user WAS the owner of the
requested image, so the controller's ownership check
(`$image->user_id !== $user->id`) was false, the artifact guard passed
(`generated()` status), and the request correctly returned **302** —
contradicting the test's own `assertNotFound()`. The test exercised the
OWNER path, not the non-owner path it claimed.

**Fix:** Removed the redundant re-login as `otherUser`, so the session
user remains `$this->user` (authenticated in `beforeEach`), who is a
genuine non-owner of `$this->otherUserImage`. The request now hits the
ownership check, which returns 404 as intended. The assertion remains the
strict `assertNotFound()` — not weakened.

### Defect 2 — `methods()` assertion was factually wrong

The route-registration test asserted
`$route->methods())->toBe(['GET'])`. Laravel's `Route::get()` helper
automatically registers both `GET` and `HEAD` (HEAD is auto-added for
every GET route), so the actual value is `['GET', 'HEAD']`. CI reported
`Expected ['GET'] but received ['GET', 'HEAD']`.

**Fix:** Corrected the expected value to the actual Laravel contract:
`->and($route->methods())->toBe(['GET', 'HEAD'])`. The strict `toBe`
assertion is retained (not loosened to `toContain`, not removed).

### Scope of the correction

- **Touched:** only
  `apps/api/tests/Feature/ImageStudio/ImageDownloadTest.php` (the two
  hunks above).
- **Unchanged:** the production controller
  (`ImageController::download`), the route registration
  (`routes/api.php`), and every other file.
- `vendor/bin/pint --dirty --format agent` (apps/api): **passed**.
- No assertion was weakened, skipped, or disabled; both fixes make the
  tests assert the CORRECT behavior they always intended to cover.

---

## Tester Re-review — CI Round 1

This section records the Tester's independent re-review of the CI Round 1
Correction only (the two-file uncommitted working-tree diff). It appends to,
and does not rewrite, any prior section of this file.

### Re-review verdict

**APPROVE — re-affirmed.** The merge gate requires exactly one decision line
in this file: `scripts/merge_gate.py` collects every `DECISION_RE` match over
the whole file and blocks with `TESTER_CONFLICTING_DECISIONS` when more than
one exists (verified in the gate source: `len(decisions) > 1`). The
pre-existing decision at line 540 (`Decision: APPROVE`, the standing Tester
verdict from the original review) is therefore kept untouched, and this
re-review's verdict is recorded in prose here instead of as a second decision
line. Result: exactly one verdict total exists in this file, it is the APPROVE
verdict, it is also the file's final (and only) decision line, and it matches
this re-review's current verdict. A search of this file confirms no other
decision line of any kind was introduced and no rejecting verdict exists
anywhere in it.

### Scope of the diff

`git status --porcelain` (uncommitted working tree) reports exactly two
modified files and nothing else — no staged changes, no untracked files:

- `apps/api/tests/Feature/ImageStudio/ImageDownloadTest.php`
- `specs/115-signed-image-download-urls/evidence.md`

Production files are untouched: `ImageController.php`, `routes/api.php`, and
`ImageResource.php` do not appear in `git status` or `git diff`. No
`specs/115-.../plan.md`, `spec.md`, or `test-plan.md`; no `docs/**`; no
`tests/governance/**`; no `.opencode/**`; no `scripts/merge_gate.py`; no CI
workflow, Docker, or other configuration files. The Builder's correction
section (`## CI Round 1 Correction`) states this same scope, and it matches
the actual diff.

### Diff review — Defect 1 (non-owner 404 test)

First hunk: the redundant re-login as `$this->otherUser` was removed and a
clarifying comment added. Verified against `beforeEach` (lines 27-47 of the
test file):

- the session user remains `$this->user`, authenticated at lines 43-46;
- the requested image is `$this->otherUserImage`, created at lines 38-40 as
  `Image::factory()->generated()` with `'user_id' => $this->otherUser->id`.

The corrected test therefore sends an unsigned request as a genuine non-owner
for an image whose status is `generated`. Because the artifact is already
generated, the artifact guard cannot produce the 404 — only the ownership
check can — so the test now exercises exactly the non-owner branch it claims
to cover. The strict `$response->assertNotFound()` assertion is retained
(line 111); it was not weakened, loosened, skipped, or disabled. The test
count is unchanged at 12 `it(...)` cases, and no `skip`, `markTestSkipped`,
or `todo` marker exists anywhere in the file.

### Diff review — Defect 2 (methods() assertion)

Second hunk: only the expected value of a strict assertion changed:
`->toBe(['GET'])` became `->toBe(['GET', 'HEAD'])`. Verified:

- `toBe` remains strict — not relaxed to `toContain`, not removed;
- Laravel's `Route::get()` registers `GET` together with the auto-added `HEAD`
  method, so `['GET', 'HEAD']` is the correct actual contract for this route;
- the CI log for run 39767567074 shows the received array literally as
  `0 => 'GET'` plus `1 => 'HEAD'` at the old line 213 — the corrected
  expectation now asserts precisely that observed value.

The remaining assertions in that test (route URI, absence of `auth:sanctum`,
absence of `throttle` middleware) are untouched.

### CI evidence cross-check

Independently fetched `gh run view 39767567074 --log-failed`. The run's
failures are exactly the two defects described above and nothing else:

- `Expected response status code [404] but received 302` at
  `ImageDownloadTest.php:114` (the non-owner test);
- `Failed asserting that two arrays are identical`, received
  `0 => 'GET', + 1 => 'HEAD'`, at `ImageDownloadTest.php:213` (the route
  test);
- final tally `Tests: 2 failed, 8 skipped, 1607 passed`, where the 8 skips are
  the pre-existing MinIO real-integration availability guards.

Both failures were test-assertion bugs in the test file. Neither indicates a
defect in the production controller, route, or resource. The Builder's
correction section documents both defects, explicitly labels them test bugs
rather than production defects, and confirms production files are unchanged —
all three claims verified against the actual diff.

### Assertion and scope integrity

- No assertion was weakened, relaxed, skipped, disabled, or removed.
- No test was deleted, renamed, or marked incomplete; the file still contains
  the same 12 cases.
- No production implementation file was modified by this correction.
- No Planner-owned artifact, governance file, or CI configuration was
  modified.

### Local execution — CI-authoritative note

- `vendor/bin/pint --dirty --format agent` (apps/api): **passed**.
- `vendor/bin/pest tests/Feature/ImageStudio/ImageDownloadTest.php`: the
  runner **discovered all 12 tests**, confirming the corrected file is
  structurally and syntactically sound (no parse or collection error), but
  every test then errors at bootstrap with the previously documented
  environment failure `could not find driver (Connection: sqlite, Database:
  :memory:)` raised from `tests/TestCase.php:13` — this sandbox lacks
  `pdo_sqlite`/`pdo_pgsql`.

Consequently this re-review claims **no local backend GREEN**: backend Pest
execution remains CI-authoritative, exactly as recorded in the original
review. Final approval of the corrected commit is contingent on the CI backend
`tests` job (and the other required checks) passing green.

### Conclusion

The correction is minimal, correctly scoped, and precisely targeted at the two
CI assertion failures; production files are unchanged; no assertion was
weakened; and the evidence file retains exactly one decision line at line 540
(`Decision: APPROVE`), which is also the file's final decision line and
matches this re-review's verdict. The Tester re-affirms **APPROVE** for the
CI Round 1 Correction, with CI remaining the authoritative verification
venue.
