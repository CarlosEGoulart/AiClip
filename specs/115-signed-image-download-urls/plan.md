# Implementation Plan: Signed Image Download URLs and Gallery Previews — Issue #115

## Active Scope and Working-Tree Baseline

Issue: [Issue #115](https://github.com/CarlosEGoulart/AiClip/issues/115). Branch
`@carlosegoulart/115/feat/image-download-urls` checked out. `spec.md` is the
behavior contract; `test-plan.md` defines required verification. All steps
follow the existing Laravel 13 / React 19 / TypeScript / Pest / Vitest /
Playwright conventions.

Planning inspected the active issue, `docs/project-state.md`, the M8 Slice 1
bundle (`specs/113-ai-image-studio-foundation/{spec,plan,test-plan}.md`), the M7
signed-URL precedent (`specs/106-clip-review-foundation/` +
`ClipReviewController::video` + `ClipReviewVideoUrlTest` +
`ClipReviewRealIntegrationTest`), and the current implementation
(`ImageController`, `ImageResource`, `ImageCollection`, `routes/api.php`,
`config/filesystems.php`, `apps/web/src/features/image-studio/**`,
`apps/web/src/index.css` image-studio block, `apps/web/e2e/image-studio.spec.ts`,
`apps/web/playwright.config.ts`, vendor
`LocalFilesystemAdapter`/`FilesystemServiceProvider`/`ServeFile`).

Observed current state (the baseline this plan is purely additive to):

- `ImageController` has `generate`/`index`/`show`; no `download` action, no
  image download route; `clip-review.video` is the reference dual-gate
  implementation (`routes/api.php:77-81`, outside `auth:sanctum`, no throttle).
- `ImageResource` exposes 11 fields; three backend tests assert exact key sets
  against `WHITELISTED_FIELDS` / `SHOW_WHITELISTED_FIELDS` /
  `LIST_WHITELISTED_FIELDS`; the e2e asserts `IMAGE_FIELD_WHITELIST` (11).
- `local` disk has `'serve' => true` (`config/filesystems.php:36`) → the
  framework registers `GET /storage/{path}` (name `storage.local`) whose
  `ServeFile` controller validates a relative signature; local-disk
  `temporaryUrl()` therefore works and returns an absolute signed
  `http://.../storage/{path}?expires&signature` URL.
- `Storage::fake(...)` installs a `buildTemporaryUrlsUsing` callback, so 302
  issuance is testable offline (proven by `ClipReviewVideoUrlTest`).
- `ImageGallery` renders metadata only and carries a now-stale "No image bytes
  are fetched in this slice" comment; `types.ts` `ImageItem` lacks
  `download_url`; two `makeImage` fixture builders exist (gallery + studio
  tests).
- E2E: `IMAGE_FIELD_WHITELIST` (11 values), `trackCleanliness` with the Slice 1
  `/storage|amazonaws|bucket|:9000/` request predicate, `IMAGE_STUDIO_FOCUS_TARGETS`
  (5 targets), touch map (6 entries), clipping list (6 elements), focus walk of
  4 forward Tab presses after tab re-establishment.
- Environment: phpunit pins `QUEUE_CONNECTION=sync` + sqlite `:memory:`;
  `playwright.config.ts` sets `APP_ENV=testing`, `QUEUE_CONNECTION=sync`,
  `MEDIA_DISK=local`; CI `backend` job provisions PostgreSQL + MinIO + the
  `real-integration` group; local Playwright runs are impossible (Issue #110/
  #113 ratified precedent).

No existing behavior changes are required beyond the authorized amendments
enumerated in `spec.md`: the 11 → 12 whitelist constants (additive), the e2e
cleanliness predicate (AC-07 ratification), and stale-comment updates. No
provider, job, model, migration, config, or dependency change.

## Step 0 — Repository Setup and Dependency Verification

1. Verify issue #115 is the only active implementation issue and the branch
   `@carlosegoulart/115/feat/image-download-urls` is checked out.
2. Verify `composer.json` and `package.json` require **no new packages** (signed
   routes, `temporaryUrl`, preview rendering all use existing stack).
3. `docker compose up -d postgres` from the repository root; verify health.
4. `php artisan migrate:fresh --seed` in `apps/api`.
5. Baseline: `php artisan test --exclude-group=real-integration` in `apps/api`
   and `npm test` in `apps/web` green before any change.
6. Confirm no `.env` secrets are read or inspected.

## Step 1 — Backend: `download_url` on ImageResource + 12-Field Whitelist (RED → GREEN → REFACTOR)

### 1.1 RED — resource issuance + authorized whitelist amendment

Files:
- Extend `apps/api/tests/Feature/ImageStudio/ImageShowTest.php`
  (`SHOW_WHITELISTED_FIELDS` + value assertions),
  `ImageListTest.php` (`LIST_WHITELISTED_FIELDS` + per-status values),
  `GenerateImageEndpointTest.php` (`WHITELISTED_FIELDS` + pending-null
  assertion).

The RED is behavioral: with the tests updated to the ratified 12-field
contract, the exact key-set assertions fail because `ImageResource` does not
emit `download_url` yet, and the value assertions fail (`download_url` missing).
Add `download_url` to the three constants **first** (authorized by
`spec.md` "Data/API Delta"), run, and record the assertion failures. If a run
errors instead of failing assertions (missing symbol), add a minimal stub so
RED is behavioral — environment/syntax failures never count.

Scenarios (each RED before implementation):

- Show (owner, `pending` factory): `data.download_url === null`; key present;
  key set equals the sorted 12-field list.
- Show (owner, `generated` factory): `data.download_url` is a string containing
  `images/{id}/download`, `signature`, and `expires` — never the literal route
  name `image.download` (M7 lesson: signed URLs embed the URI, not the name).
- Show (`failed` factory): `download_url === null`.
- Generate 202 (pending row): `data.download_url === null`; key set equals the
  12-field list.
- List: mixed generated/pending/failed items — generated items carry the signed
  string, others `null`; every item's key set equals the 12-field list; no
  `storage_disk`/`storage_key`/`user_id`/bucket anywhere.

### 1.2 GREEN — implement the resource field

- `ImageResource`: add `'download_url' => $this->downloadUrl(),` and the
  private `downloadUrl(): ?string` exactly as pinned in `spec.md`
  (status check handling both enum and string, `URL::temporarySignedRoute('image.download', now()->addHour(), ['image' => $this->id])`).
- No other resource change; `ImageCollection` inherits it automatically.

### 1.3 REFACTOR

- `php artisan test tests/Feature/ImageStudio --compact`; green.
- `vendor/bin/pint --dirty --format agent`.

## Step 2 — Backend: Download Endpoint + Dual Gate (RED → GREEN → REFACTOR)

### 2.1 RED — endpoint feature tests

File: `apps/api/tests/Feature/ImageStudio/ImageDownloadTest.php` (new).
`uses(SpaTestCase::class, RefreshDatabase::class);` with the established
`csrfCookies()`/`spaRequest()` helpers. Every test that expects a 302 fakes the
disk first: `Storage::fake(config('media.disk', 'media'));` (the vendor
temporary-URL callback makes `temporaryUrl()` deterministic and offline).

Scenarios (each RED before implementation — a missing route yields 404/405
against an expected 302/403/401, which is a behavioral failure; do not proceed
on class-not-found or routing-engine errors):

- Owner + valid signature → 302; `Location` is a non-empty string.
- Owner session path (no signature) → 302.
- Expired signature (`URL::temporarySignedRoute('image.download', now()->subHour(), ['image' => id])`)
  → 403.
- Tampered signature (valid signed URL + `&tampered=true`) → 403.
- Non-owner session (no signature) → 404; non-existent id (no signature) → 404.
- Guest (valid CSRF, no session), unsigned → 401.
- Owner + signed URL minted for a `pending` image → 404 (artifact guard after
  the signature gate).
- Owner session download of `pending` → 404; `failed` → 404; `generated` → 302.
- Storage failure → 500: create a `generated` row, then
  `config()->set('filesystems.disks.' . config('media.disk'), ['driver' => 'local', 'root' => storage_path('app/test-download-500'), 'throw' => true]);`
  `Storage::forgetDisk(config('media.disk'));` → download → 500; response body
  contains no `storage_key` value, no bucket, no prompt text. (A local driver
  without `serve` throws from `temporaryUrl()` — offline, no mocks.)
- Route/registration sanity: `php artisan route:list` shows
  `GET api/v1/images/{image}/download` with name `image.download` **outside**
  the `auth:sanctum` middleware group (assert via `route:list --json` middleware
  column if exposed; otherwise the 403-expired test itself proves the ordering —
  inside the group it would return 401).

### 2.2 GREEN — implement route + controller

- `routes/api.php`: add the route exactly as pinned in `spec.md` — inside the
  `Route::prefix('v1')` group, after the `auth:sanctum` group, next to the
  `clip-review.video` route. **No** `throttle:` middleware (M7 parity).
- `ImageController::download(Request $request, Image $image): RedirectResponse`
  implementing the exact order from `spec.md`:
  1. dual gate (`signature` present → `abort_unless($request->hasValidSignature(), 403)`;
     absent → no user → 401, `user_id` mismatch → 404);
  2. artifact guard `status !== ImageStatus::GENERATED` → 404;
  3. `Storage::disk($image->storage_disk)->temporaryUrl($image->storage_key, now()->addHour())`
     → `redirect()->away($url)`;
  4. catch-all → `Log::error('Failed to generate signed image URL', ['image_id' => ..., 'error' => ...])`
     → `abort(500)`.
- Return type is `RedirectResponse` (never `Response` — M7 TypeError lesson).

### 2.3 REFACTOR

- `php artisan test tests/Feature/ImageStudio --compact`; green.
- `php artisan route:list` sanity check.
- `vendor/bin/pint --dirty --format agent`.

## Step 3 — Backend: Real MinIO Byte Round Trip (RED → CI-venue GREEN)

File: `apps/api/tests/Feature/ImageStudio/ImageDownloadRealIntegrationTest.php`
(new), `group('real-integration')`. Mirror `ClipReviewRealIntegrationTest`
structure exactly:

- `beforeEach` availability guard: media disk driver must be `s3`; probe
  put/delete on MinIO; `markTestSkipped` when absent (graceful full-suite runs;
  the CI backend invocation is fail-closed on skips).
- Create owner → project → `generated` `Image` factory row with a **unique**
  storage key (factory already unique via `StorageKeyBuilder`; add a uuid
  suffix to the key like the M7 test to avoid collisions with leftover objects).
- Test: write real PNG bytes (must start with `\x89PNG\r\n\x1a\n`) via
  `Storage::disk('media')->put(...)`; call the controller directly with a
  request whose user resolver returns the owner (M7 pattern) → assert 302;
  parse `Location`: host/port equal `config('filesystems.disks.media.endpoint')`
  host/port, path contains `/{bucket}/{storage_key}`, query has
  `X-Amz-Signature` and `X-Amz-Expires`; `Http::timeout(30)->get($location)` →
  200 with **byte-exact** body; `finally` deletes the object.
- No `Storage::fake`, no `Queue::fake`, no mocks (grep-verifiable).

**Venue note:** locally this group skips without MinIO. If MinIO is reachable
locally, run it for local RED/GREEN; otherwise the CI backend `tests` job is the
authoritative RED-then-GREEN venue (the test is written in the RED phase, so
the first CI run fails against the not-yet-existing behavior only if Steps 1–2
have not landed — Builder sequences Step 3's test authoring in the RED phase of
Steps 1–2 and records the group's execution evidence from the venue where it
actually ran; a skip is environmental, never RED and never GREEN).

## Step 4 — Frontend: Types, Gallery Preview, CSS (RED → GREEN → REFACTOR)

### 4.1 RED — Vitest

Files:
- Extend `apps/web/src/features/image-studio/components/ImageGallery.test.tsx`
  (new preview cases; add `download_url: null` to `makeImage` so the required
  type compiles — a fixture addition, not a weakening).
- Extend `apps/web/src/features/image-studio/components/ImageStudio.test.tsx`
  (`makeImage` fixture addition only, plus any new assertion the composition
  needs).

New/updated cases (RED first — component currently renders no img/link):

- Generated + `download_url` string → `img` present with `src` equal to the
  URL, exact alt `` `Generated image: ${prompt excerpt}` ``, `width`/`height`
  attributes equal to the image dimensions, `loading="lazy"`; link
  `getByRole('link', { name: 'Download image' })` with `href`, `target="_blank"`,
  `rel` containing `noopener` and `noreferrer`.
- `pending` → no img, no download link (badge/hint unchanged).
- `failed` → no img, no download link.
- Generated + `download_url: null` → no img, no link (defensive branch).
- Error fallback: fire `error` on the img (e.g.
  `fireEvent.error(img)`) → fallback text "Preview unavailable" appears; prompt
  excerpt, badge, meta, and the download link still rendered.
- Existing gallery/studio suites remain green unchanged (states, badges,
  polling, focus-class tests).

### 4.2 GREEN — implement

- `types.ts`: required `download_url: string | null` on `ImageItem`.
- `ImageGallery.tsx`: internal `ImagePreview` (or inline equivalent) with error
  state keyed `` `${image.id}-${image.download_url}` ``, the exact markup pinned
  in `spec.md` (alt text contract, width/height, lazy, onError → fallback),
  download anchor after the preview; update the stale "No image bytes are
  fetched in this slice" comment to describe the signed-preview behavior.
- `index.css`: add `.image-gallery-card-preview`, `.image-gallery-preview-fallback`,
  `.image-gallery-download` (+ `:focus-visible`) inside the image-studio block
  with the constraints from `spec.md` (per-rule `box-sizing: border-box`,
  `width: 100%`/`max-width: 100%`/`height: auto`/`object-fit: contain`,
  ≥44px, visible focus, ≥4.5:1 link contrast). Touch no other rule; no global
  reset.

### 4.3 REFACTOR

- `npm test -- --run src/features/image-studio` green; then full `npm test`.
- `npm run lint && npm run build` in `apps/web`.

## Step 5 — E2E: Preview, Byte Chain, Focus, Cleanliness (RED → GREEN → REFACTOR)

### 5.1 RED — extend the Playwright spec

File: `apps/web/e2e/image-studio.spec.ts` (the only e2e file touched; other
specs are read-only).

Apply **exactly** the deltas pinned in `spec.md` "E2E Contract":

1. `IMAGE_FIELD_WHITELIST` → 12 values (sorted, including `download_url`).
2. `runGenerateFlow`: 202 `download_url === null`; refetch generated item's
   `download_url` contains `images/{id}/download` + `signature` + `expires`;
   preview img role/alt assertion; bounded `naturalWidth > 0` poll (≤10s) —
   the chain proof: a decoded image requires API 302 → signed local serve
   route → 200 PNG bytes (a broken chain yields `naturalWidth === 0` and/or an
   unexpected 4xx/5xx caught by the existing response collector);
   download link href assertions.
3. Focus: add the `download` target (6 total); extend the forward walk (5
   presses canonical, bounded cap 8 acceptable); length assertion reads
   `IMAGE_STUDIO_FOCUS_TARGETS.length` (= 6); `=== true` for all six.
4. Touch map: add `download` (≥44 by the existing loop).
5. Clipping list: add `.image-gallery-card-preview`.
6. Storage-request predicate: replace with the normative AC-07 implementation
   from `spec.md` (verbatim intent: `amazonaws|bucket|:9000` unconditional;
   `storage` only on host `127.0.0.1:8000` exempt) and update the header/line
   73–77 comments to the new intent. **This is the only change inside
   `trackCleanliness`/`expectClean`** — no collector is added, removed, or
   otherwise modified.

Do **not** weaken any existing assertion (AGENTS.md §24): the empty-state,
pagination-envelope, no-storage-internals, overflow, layout, clipping,
touch-target, badge, and cleanliness assertions all remain with equal or
greater strength.

### 5.2 GREEN

- Requires Steps 1–4 GREEN. Local `npx playwright test e2e/image-studio.spec.ts
  --list` must show non-empty discovery (6 tests × 3 viewport projects).
- Full `npm run test:e2e` runs in **CI only** (Issue #110/#113 precedent: the
  sandbox cannot boot the Laravel+pgsql stack). CI `e2e` is the authoritative
  GREEN venue for this step.

### 5.3 REFACTOR

- Re-run lint/build/`--list`; CI e2e green on the final commit.

## Step 6 — Comment Consistency Pass

- `apps/web/e2e/image-studio.spec.ts` header (lines 1–27): replace "the gallery
  fetches no image bytes (no byte-delivery URL exists in this slice)" with the
  signed-preview/byte-chain reality; keep the topology/env statements accurate.
- `apps/web/src/features/image-studio/components/ImageGallery.tsx`: replace the
  "No image bytes are fetched in this slice" comment (Step 4.2 already covers
  it; verify no other stale "no byte delivery" claim remains in the two files
  above via grep). Comments only — no behavior.

## Step 7 — Full Test Suite and Verification (local vs CI split)

| Venue | Command / Required Result |
|---|---|
| Repository root | `docker compose up -d postgres` — health |
| `apps/api` (local) | `php artisan migrate:fresh --seed` — clean database |
| `apps/api` (local) | `php artisan test --exclude-group=real-integration` — zero failures |
| `apps/api` (local) | `php artisan test tests/Feature/ImageStudio --compact` — all download/resource/job/endpoint suites pass |
| `apps/api` (local) | `vendor/bin/pint --dirty --format agent` — clean |
| `apps/web` (local) | `npm test` — full Vitest green (incl. new preview cases) |
| `apps/web` (local) | `npm run lint && npm run build` — pass |
| `apps/web` (local) | `npx playwright test e2e/image-studio.spec.ts --list` — non-empty discovery only (execution is CI-authoritative) |
| Repository root | governance suite where locally executable — pass (CI `governance` authoritative where restricted) |
| **CI `tests`** | full backend suite on PostgreSQL + MinIO incl. `real-integration` — green, no skipped required tests |
| **CI `e2e`** | `npm run test:e2e` — all image-studio tests green at 390x844 / 768x1024 / 1440x900 with cleanliness (incl. AC-07 predicate) |
| **CI** | `governance`, `pr-enforcement`, `test` (frontend) — green |

**Exact merge-gate condition:** `e2e`, `governance`, `pr-enforcement`, `test`,
and `tests` all green on the final commit (`scripts/merge_gate.py`,
REQUIRED_CHECKS). Partial passes, skipped required tests, or a `real-integration`
skip inside the CI backend job do not satisfy the condition.

## Verification Checklist

- [ ] `GET /api/v1/images/{image}/download` (name `image.download`) outside
      `auth:sanctum`, no throttle; dual gate 302/403/401/404 in the pinned order
- [ ] Artifact guard: pending/failed → 404; generated → 302 to `temporaryUrl(+1h)`
- [ ] Storage exception → 500 with sanitized log (image id only)
- [ ] `ImageResource` = 12 fields incl. `download_url` (signed string for
      generated, null otherwise) on 202/show/list; no storage internals anywhere
- [ ] Backend whitelist constants and e2e `IMAGE_FIELD_WHITELIST` updated to the
      ratified 12 (authorized additive amendment)
- [ ] Gallery: preview img (exact alt, dimensions, lazy, keyed remount) +
      Download link (new tab, noopener) for generated only; fallback on error;
      ≥44px + visible focus; three CSS rules, per-rule border-box, no overflow
- [ ] E2E: preview `naturalWidth > 0` (chain proof: API 302 → signed local
      serve route → 200 PNG bytes), href signature/expires, focus
      walk 6/6 at 3 viewports, touch map 7/7 + clipping
      extended, AC-07 predicate exactly as ratified
- [ ] `real-integration`: byte-exact MinIO presigned round trip (CI backend job)
- [ ] Pest, Vitest, lint, build, Pint, governance green; CI `e2e` green
- [ ] Stale "no byte delivery" comments updated; no other comment/doc drift
- [ ] Diff audit: no changes to provider/job/model/migration/config/dependencies,
      other e2e specs, `tests/governance/**`, `.opencode/**`,
      `scripts/merge_gate.py`, `playwright.config.ts`, CI workflows, `docs/**`,
      `specs/113-.../**`

## TDD and Evidence Expectations

- **RED**: behavioral assertion failures per step (resource key-set/value
  failures in Step 1; endpoint status-code failures in Step 2; Vitest preview
  failures in Step 4; CI e2e behavioral failure for Step 5 where local execution
  is impossible). Setup/environment failures (missing symbols, discovery
  failures, no-`.env`/`pdo_pgsql` e2e boot failures) are never RED.
- **GREEN**: the same tests pass after implementation; no test may be deleted,
  relaxed, skipped, or subsetted to reach GREEN. The only authorized test edits
  are the additive `download_url` whitelist entries, the new cases, and the
  AC-07 predicate replacement — all pinned in `spec.md`.
- **REFACTOR**: behavior unchanged; all suites stay green; `pint --dirty`.
- `evidence.md` (Builder/Tester-owned; Planner does not edit it) keeps the
  literal `### RED` / `### GREEN` / `### REFACTOR` headings and ends with
  exactly one Tester-owned `Decision:` line (pr-enforcement gate).

## Remaining Gates (Not Claimed Complete)

- Independent Tester review: runs all suites, operates the running app (CI e2e),
  inspects screenshots at 3 viewports, verifies the AC-07 predicate delta
  against `specs/113`, checks console/network/API, validates accessibility,
  security, ownership, secret exposure.
- Orchestrator: commits, pushes, creates PR (with `Closes #115`), monitors CI,
  merges via `scripts/merge_gate.py`, closes the issue, updates project state
  (docs updates are the established post-merge practice, outside this issue).

---

SPEC_READY
