# Test Plan: Signed Image Download URLs and Gallery Previews — Issue #115

## Authority, Preconditions, and Evidence Rules

Active issue: [Issue #115](https://github.com/CarlosEGoulart/AiClip/issues/115).
Verify `spec.md` acceptance criteria AC-01 through AC-10 and the ordered work in
`plan.md`. Both Phase 1 escalations are ratified scope: the AC-07 cleanliness
predicate refinement and the Slice 2 ordering.

Before Builder starts: Orchestrator confirms the planning/governance gate.
Builder ensures Docker PostgreSQL is running, the database is migrated, and the
baseline suites pass (`php artisan test --exclude-group=real-integration`,
`npm test`).

RED validity: initial PDO/database failures, unbound facades, missing symbols,
syntax errors, discovery failures, and the known local Playwright boot failure
(no `.env` / no `pdo_pgsql` — Issue #110/#113 precedent) are setup/environment
failures, **not** RED. Behavioral RED means: the written assertion executes and
fails because the required behavior is absent (missing `download_url` key,
expected 302 receiving 404, expected preview `img` absent, CI e2e assertion
failure). `X-XSRF-TOKEN` uses the wire cookie convention. Backend 302 tests use
`Storage::fake(config('media.disk'))` (vendor installs the temporary-URL
callback); `Storage::fake`/mocks are forbidden in the `real-integration` group.
Test/e2e environments run `QUEUE_CONNECTION=sync`. Retain enforced CSRF in the
SPA test base.

## 1. Backend: `download_url` Issuance + 12-Field Whitelist (AC-03)

### TC-BE-01: Resource field by status + exact key sets

Files: extend `apps/api/tests/Feature/ImageStudio/GenerateImageEndpointTest.php`
(`WHITELISTED_FIELDS`), `ImageShowTest.php` (`SHOW_WHITELISTED_FIELDS`),
`ImageListTest.php` (`LIST_WHITELISTED_FIELDS`). The constant updates are the
authorized 11 → 12 amendment (`spec.md` "Data/API Delta") — add `download_url`,
remove nothing, relax nothing.

Ratified sorted 12-field set:
`created_at, download_url, height, id, mime_type, parameters, project_id, prompt, size_bytes, status, updated_at, width`.

| Test Case | Assertion |
|-----------|-----------|
| Generate 202 key set | `array_keys(data).sort()` equals the 12-field set |
| Generate 202 value | `data.download_url === null` (enqueue-time pending row) |
| Show `generated` | key set equals 12; `download_url` is a string containing `images/{id}/download`, `signature`, `expires`; does **not** contain the literal route name `image.download` |
| Show `pending` | key set equals 12; `download_url === null` |
| Show `failed` | key set equals 12; `download_url === null` |
| List mixed statuses | every item's key set equals 12; generated items carry the signed string, pending/failed items `null` |
| No internals | no `storage_disk`, `storage_key`, `user_id`, or bucket token in any generate/show/list body (existing assertions, re-run) |
| Cross-image isolation | a signed `download_url` for image A contains only A's id (route-param binding) |

Commands:

```bash
cd apps/api && php artisan test tests/Feature/ImageStudio/GenerateImageEndpointTest.php --compact
cd apps/api && php artisan test tests/Feature/ImageStudio/ImageShowTest.php --compact
cd apps/api && php artisan test tests/Feature/ImageStudio/ImageListTest.php --compact
```

## 2. Backend: Download Endpoint Dual Gate (AC-01, AC-02)

### TC-BE-02: `GET /api/v1/images/{image}/download`

File: `apps/api/tests/Feature/ImageStudio/ImageDownloadTest.php` (new).
`uses(SpaTestCase::class, RefreshDatabase::class);` + `Storage::fake(config('media.disk'))`
in every 302-expecting case.

| Test Case | Request | Expected |
|-----------|---------|----------|
| Owner + valid signature | signed URL minted via `URL::temporarySignedRoute('image.download', now()->addHour(), ['image' => id])`, GET with session | 302; `Location` non-empty string |
| Owner session (unsigned) | GET, authenticated, no signature | 302; `Location` non-empty string |
| Expired signature | `URL::temporarySignedRoute(..., now()->subHour(), ...)` GET | 403 |
| Tampered signature | valid signed URL + `&tampered=true` | 403 |
| Non-owner unsigned | user B session, user A's image | 404 |
| Non-existent unsigned | id `999999`, owner session | 404 |
| Guest unsigned | valid CSRF, no session | 401 |
| Signed URL on pending image | manually minted signed URL for a `pending` row | 404 (artifact guard after signature gate) |
| Owner session on pending | GET, owner, pending row | 404 |
| Owner session on failed | GET, owner, failed row | 404 |
| Storage failure | generated row + disk reconfigured to a `local` driver **without** `serve` (+ `Storage::forgetDisk`) so `temporaryUrl()` throws | 500; body leaks no storage key, bucket, or prompt text |
| Route registration | `php artisan route:list` | `GET api/v1/images/{image}/download`, name `image.download`, not inside the `auth:sanctum` group (the expired-signature 403 proves ordering: inside the group it would be 401) |
| No throttle | route registration | no `throttle:` middleware (M7 `clip-review.video` parity) |

Signature assertions target the URI path `images/{id}/download` plus `signature`
and `expires` — never the dot-separated route name (M7 Issue #106 ratified
lesson; a literal `toContain('image.download')` is unsatisfiable and forbidden).

Accepted documented surface (assert only what the contract pins): a tampered
signature on an **existing** id → 403 while a **non-existent** id → 404 via
binding — inherent to signature-before-ownership; no ownership data exposed.

Commands:

```bash
cd apps/api && php artisan test tests/Feature/ImageStudio/ImageDownloadTest.php --compact
```

## 3. Backend: Real MinIO Byte Round Trip (AC-08)

### TC-BE-03: `real-integration` group

File: `apps/api/tests/Feature/ImageStudio/ImageDownloadRealIntegrationTest.php`
(new), `->group('real-integration')`. No `Storage::fake`, no `Queue::fake`, no
mocked adapters — mirror `ClipReviewRealIntegrationTest` (availability guard
with graceful `markTestSkipped`, unique key, `finally` cleanup).

| Test Case | Assertion |
|-----------|-----------|
| Presign shape | direct controller call with owner user resolver on a `generated` row with real MinIO bytes → 302; `Location` host/port equal the configured media endpoint; path contains `/{bucket}/{storage_key}`; query has `X-Amz-Signature` and `X-Amz-Expires` |
| Byte-exact fetch | `Http::timeout(30)->get($location)` → 200 and body **identical** to the written bytes (bytes start with the PNG signature) |
| No doubles | file contains no `Storage::fake`/`Queue::fake` (grep-verifiable); media disk is a real `AwsS3V3Adapter`; queue connection is the pinned `sync` |

Venue: CI backend `tests` job provisions PostgreSQL + MinIO and runs the group
fail-closed on skips. A local skip without MinIO is environmental — never RED,
never GREEN.

```bash
cd apps/api && php artisan test --group=real-integration --compact   # CI (or local with MinIO)
```

## 4. Frontend Component Tests — Vitest (AC-04)

File: extend `apps/web/src/features/image-studio/components/ImageGallery.test.tsx`.
Fixture update: `makeImage` gains `download_url: null` (required type — an
addition, not a weakening). `ImageStudio.test.tsx` gets the same fixture
addition.

### TC-FE-01: Preview + download link for generated images

| Test Case | Assertion |
|-----------|-----------|
| Preview rendered | generated + string `download_url` → `img` with `src` equal to the URL |
| Alt text contract | accessible name is exactly `` `Generated image: {60-char prompt excerpt}` `` |
| Intrinsic dimensions | `width`/`height` attributes equal `image.width`/`image.height`; `loading="lazy"` |
| Download link | role link named `Download image`; `href` equals `download_url`; `target="_blank"`; `rel` contains `noopener` and `noreferrer` |
| Fallback | fire `error` on the img → "Preview unavailable" visible; prompt excerpt, status badge, meta, and download link still rendered |
| Remount key | (implementation-observable) a changed `download_url` remounts the preview — covered by the fallback-then-new-src scenario if expressed, otherwise verified in e2e via fresh URL loads |

### TC-FE-02: No preview when not deliverable

| Test Case | Assertion |
|-----------|-----------|
| Pending | no `img`, no download link; badge + "Generating…" unchanged |
| Failed | no `img`, no download link; badge unchanged |
| Generated + `download_url: null` | no `img`, no download link |
| Empty/loading/error states | existing assertions unchanged and green |

Commands:

```bash
cd apps/web && npm test -- --run src/features/image-studio
```

## 5. Frontend Lint, Build, and Type Check

```bash
cd apps/web && npm run lint && npm run build
```

Expect: zero lint errors (including TS6133), TypeScript compiles (the required
`download_url` field is satisfied by API data and both fixture builders), Vite
production build succeeds.

## 6. Playwright E2E (AC-05, AC-06, AC-07)

Run via `npm run test:e2e` from `apps/web` — **CI-authoritative** (the local
sandbox cannot boot Laravel+pgsql; Issue #110/#113 ratified precedent). Local
verification is `--list` discovery only. Playwright webServer env (unchanged):
`APP_ENV=testing`, `QUEUE_CONNECTION=sync`, `MEDIA_DISK=local` → the `local`
disk (`serve => true`) serves bytes through the framework signed route; no
MinIO, no Python worker, no model download.

Spec file: `apps/web/e2e/image-studio.spec.ts` (inside `testDir: './e2e'`);
the only e2e file this issue may touch. Topology, data setup, and viewport
projects are unchanged from `specs/113` (register unique user via UI, create
project via UI, capture id from the real 201, open the embedded Image Studio
tab; viewports from config, never set manually).

### TC-E2E-01: Generate flow + preview + byte chain (extended)

Steps 1–8 of `specs/113` TC-E2E-01 remain with equal strength; add:

| # | Assertion |
|---|-----------|
| 4a | 202 body `download_url === null`; `IMAGE_FIELD_WHITELIST` now the sorted **12** values incl. `download_url`; no `storage_disk`/`storage_key`/`user_id`/bucket in the body (unchanged) |
| 6a | Refetch body: the generated item's `download_url` is a string containing `images/{id}/download`, `signature`, `expires` |
| 6b | `getByRole('img', { name: /^Generated image:/ })` visible |
| 6c | Preview loaded: poll `img.naturalWidth > 0` (bounded ≤10s) — the issue's chain proof: a decoded image requires API 302 → signed local serve route → 200 PNG bytes; a broken chain yields `naturalWidth === 0` and/or an unexpected 4xx/5xx caught by the existing response collector |
| 6d | `getByRole('link', { name: 'Download image' })` href matches `/\/api\/v1\/images\/\d+\/download/`, `/signature=/`, `/expires=/` |
| end | Existing cleanup + `expectClean` (incl. refined storage predicate) pass |

### TC-E2E-02: Responsive, touch targets, focus (extended)

All existing checks (overflow `scrollWidth <= innerWidth`, grid columns, form/
gallery order, clipping `toBe 0`, touch ≥44 loop, keyboard focus discipline,
cleanliness) remain with equal strength. Additions:

| Check | Assertion |
|-------|-----------|
| Focus targets | `IMAGE_STUDIO_FOCUS_TARGETS` = 6 entries: existing five + `{ key: 'download', selector: '.image-gallery-download' }`; forward walk extended (5 presses canonical after tab re-establishment; bounded cap 8 acceptable if the DOM interposes a focusable); `expect(Object.keys(focusVisible)).toHaveLength(IMAGE_STUDIO_FOCUS_TARGETS.length)` (6) and `focusVisible[key] === true` for **all six**, at all three viewports |
| Touch map | `download: height('.image-gallery-download')` ≥ 44 in the existing loop |
| Clipping | `.image-gallery-card-preview` added to the `clipped` elements array (still `toBe 0`) |

Forbidden (AGENTS.md §24): removing/subsetting any target or assertion;
hardcoding `true`; class-presence substitutions; changing other selectors;
weakening any existing check.

### TC-E2E-03: Console/network cleanliness (AC-07 — the ratified delta)

**Baseline (specs/113, what the Tester diffs against) — request collector:**

```ts
page.on('request', (request) => {
  const url = request.url();
  if (/storage|amazonaws|bucket|:9000/.test(url)) {
    cleanliness.storageRequests.push(`${request.method()} ${url}`);
  }
});
```

**Authorized end state (normative — implement exactly):**

```ts
page.on('request', (request) => {
  const url = request.url();
  const isApiOrigin = new URL(url).host === '127.0.0.1:8000'; // API_HOST
  const externalStorageToken = /amazonaws|bucket|:9000/.test(url);
  const storageTokenOffOrigin = /storage/.test(url) && !isApiOrigin;
  if (externalStorageToken || storageTokenOffOrigin) {
    cleanliness.storageRequests.push(`${request.method()} ${url}`);
  }
});
```

| Element | Status |
|---------|--------|
| `amazonaws`, `bucket`, `:9000` tokens | **unchanged, fatal unconditionally** on every host incl. `127.0.0.1:8000` |
| `storage` token on host `127.0.0.1:8000` (first-party signed serve route) | **the single authorized exemption** (AC-07) — implemented as the exact Slice 1 `storage` token on the full URL minus this host, never narrowed or widened |
| `storage` token on any other host (incl. `127.0.0.1:5173`) | fatal, unchanged |
| `expect(storageRequests).toEqual([])` in `expectClean` | unchanged |
| Console error/warning collector + `/api/v1/auth/me` 401 exception | byte-identical |
| `pageerror` collector; 4xx/5xx collector; `localStorage`/`sessionStorage` emptiness | byte-identical |
| All other collectors in `trackCleanliness` | byte-identical — **no collector is added, removed, or modified**; the predicate above is the only change |
| Header comments (lines 1–27) and collector comment (73–77) | updated to the new intent (comments only) |

**Tester delta checklist vs `specs/113` TC-E2E-03 — exactly these four items,
nothing else:** (1) the request-collector predicate above; (2)
`IMAGE_FIELD_WHITELIST` 11 → 12; (3) stale "no byte delivery" comments
updated; (4) `trackCleanliness`/`expectClean` structure, all other predicates,
and all other assertions byte-identical.

Commands:

```bash
cd apps/web && npx playwright test e2e/image-studio.spec.ts --list   # local: non-empty discovery
cd apps/web && npm run test:e2e                                      # CI-authoritative
```

## 7. Cross-Cutting: Security, Accessibility, Responsive

### Security Matrix

| Check | Method | Pass Criteria |
|-------|--------|---------------|
| Signature gate before anything | expired/tampered signed GET | 403; no ownership/storage info in body |
| Ownership fallback | unsigned GET as guest / non-owner / owner | 401 / 404 / 302 |
| Artifact guard | signed + session GET on pending/failed | 404 |
| IDOR via resource | another user's list/show responses | never another user's `download_url` (list/show owner-scoped) |
| No internal fields in API | inspect 202/show/list bodies | exactly 12 whitelisted keys; no `storage_disk`/`storage_key`/`user_id`/bucket |
| No secrets in browser | Network/Console/storage inspection | no tokens/credentials; browser storage empty (existing assertion) |
| Log hygiene | storage-failure 500 case | sanitized log (image id); response leaks no key/bucket/prompt |
| Mass assignment | unchanged generate POST with extra fields | ignored (existing test) |
| Dependency audit | `composer.json`/`package.json` diff | zero additions |

### Accessibility Matrix (WCAG 2.1 AA)

| Check | Method | Pass Criteria |
|-------|--------|---------------|
| Preview semantics | Vitest + e2e | meaningful alt (`Generated image: …`); role `img` |
| Keyboard reach | e2e focus walk (6 targets × 3 viewports) | all reachable; `focusVisible === true` for all six |
| Touch targets | e2e touch map (7 entries) | ≥ 44px each |
| Contrast | link/fallback/badges | ≥ 4.5:1 text (Tester visual review + CSS token inspection) |
| Live regions / labels | existing suites | unchanged, green |
| Error affordance | Vitest fallback case | readable "Preview unavailable", card structure intact |

### Responsive Matrix

| Viewport | Layout Checks (all existing + preview) |
|----------|------------------------------------------|
| 390x844 | single column; preview scales in card; no horizontal scroll |
| 768x1024 | stacked/two-column per existing thresholds; no clipping of preview |
| 1440x900 | side-by-side; preview contained; no overflow |

## 8. Required Commands, Discovery, and CI

| Venue | Command / Required Result |
|---|---|
| Repository root | `docker compose up -d postgres` — health |
| `apps/api` (local) | `php artisan migrate:fresh --seed` |
| `apps/api` (local) | `php artisan test --exclude-group=real-integration` — zero failures |
| `apps/api` (local) | `php artisan test tests/Feature/ImageStudio --compact` — TC-BE-01/02 green |
| `apps/api` (local) | `vendor/bin/pint --dirty --format agent` — clean |
| `apps/web` (local) | `npm test` — full Vitest green (TC-FE-01/02 + all pre-existing) |
| `apps/web` (local) | `npm run lint && npm run build` — pass |
| `apps/web` (local) | `npx playwright test e2e/image-studio.spec.ts --list` — non-empty discovery only |
| Repository root | governance suite where locally executable — pass |
| **CI `tests`** | full backend suite (PostgreSQL + MinIO) incl. `real-integration` green; fail-closed on skips |
| **CI `e2e`** | full Playwright run: image-studio tests green at 390x844 / 768x1024 / 1440x900 with the AC-07 cleanliness contract |
| **CI** | `governance`, `pr-enforcement`, `test` (frontend) green |

**Merge-gate condition:** `e2e`, `governance`, `pr-enforcement`, `test`,
`tests` all green on the final commit (`scripts/merge_gate.py`). No partial
passes; local e2e attempts never substitute for CI; `--list` discovery is never
GREEN evidence for e2e behavior.

## 9. Test Case to Acceptance Criteria Mapping

| Test Case | AC Covered |
|-----------|------------|
| TC-BE-01: `download_url` values + exact 12-field key sets (202/show/list) | AC-03 |
| TC-BE-02: dual gate 302/403/401/404, artifact guard 404, storage 500, registration | AC-01, AC-02 |
| TC-BE-03: real MinIO presigned 302 → byte-exact 200 (`real-integration`) | AC-08 |
| TC-FE-01: preview render, alt/dimensions/lazy, link attrs, error fallback | AC-04 |
| TC-FE-02: no preview for pending/failed/null-url; existing states intact | AC-04 |
| Lint/build/type check | AC-09, AC-10 |
| TC-E2E-01: preview `naturalWidth > 0` chain proof, href, 12-field whitelist | AC-06, AC-03 |
| TC-E2E-02: overflow/layout/clipping + preview containment at 3 viewports | AC-05 |
| TC-E2E-02: focus walk 6/6 + touch map 7/7 at 3 viewports | AC-04, AC-06 |
| TC-E2E-03: refined storage predicate + all collectors intact | AC-07 |
| Full backend/frontend suites, governance, CI matrix | AC-09, AC-10 |
| Scope/diff audit (no provider/job/migration/dependency changes) | AC-10 |

## 10. TDD, Evidence, and Test-Integrity Rules (binding on Builder)

- **RED**: written per `plan.md` step, actually executed, recorded verbatim in
  `evidence.md` under `### RED` — Step 1 key-set/value failures, Step 2 status
  failures, Step 4 Vitest failures, Step 5 CI e2e behavioral failure (local e2e
  execution is environmentally impossible and never counts as RED).
  Tests-written, connection errors, zero-executed tests, and skips are never
  proof of anything.
- **GREEN**: same tests pass post-implementation; recorded under `### GREEN`.
  GREEN is never claimed from `--list`, from skips, or from a CI run with any
  required test skipped.
- **REFACTOR**: suites stay green with no behavior change; `### REFACTOR`.
- `evidence.md` keeps the literal `### RED` / `### GREEN` / `### REFACTOR`
  headings and ends with exactly one Tester-owned `Decision:` line
  (pr-enforcement gate).

**Authorized test edits (everything else is forbidden):**

1. Adding `download_url` to the three backend whitelist constants and the e2e
   `IMAGE_FIELD_WHITELIST` (additive, spec-pinned).
2. Adding the new test files/cases specified here.
3. Replacing the e2e request-collector predicate with the exact AC-07 end state.
4. Adding `download_url: null` to the two `makeImage` fixture builders.

**Forbidden:** deleting, relaxing, skipping, subsetting, or hardcoding any
assertion (AGENTS.md §24); removing any focus target or weakening the length-6
or `=== true` assertions; exempting `amazonaws`/`bucket`/`:9000` or any
non-API-origin `storage` request; touching `clip-review.spec.ts` or any other
spec's assertions; disabling collectors; reducing the viewport/project set;
weakening the real-integration group's fail-closed CI invocation; edits to
`tests/governance/**`, `.opencode/**`, `scripts/merge_gate.py`,
`playwright.config.ts`, CI workflows, `docs/**`, or `specs/113-.../**`.

---

SPEC_READY
