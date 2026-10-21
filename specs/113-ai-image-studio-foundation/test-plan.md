# Test Plan: AI Image Studio Foundation — Issue #113

## Authority, Preconditions, and Evidence Rules

Active issue: [Issue #113](https://github.com/CarlosEGoulart/AiClip/issues/113). Verify `spec.md` acceptance criteria AC-01 through AC-12 and the ordered work in `plan.md`. The CI Round 3 correction adds AC-13 through AC-15 and section 11 below; AC-01 through AC-12 remain intact and in force.

Before Builder corrections: Orchestrator confirms the planning/governance gate. Builder ensures Docker infrastructure is running (`docker compose up -d postgres` from repository root), database migrated, and baseline tests pass.

Initial database connectivity/PDO failures, unbound Laravel TestCase/facades, missing Faker, wrong CSRF helper, malformed fixtures, duplicate registration users, syntax errors, class-not-found autoload errors, and no-test discovery are setup failures, not RED. Verify and fix these before meaningful behavior assertions (add minimal stubs so failures are behavioral). `X-XSRF-TOKEN` uses the wire cookie convention, not a decrypted token. Backend storage assertions use `Storage::fake(config('media.disk'))` and query `image->storage_key`, never a public ID. The test/e2e environments run `QUEUE_CONNECTION=sync` (phpunit already does; the e2e server must follow the Issue #110 requirement). Retain enforced CSRF in the SPA test base.

Historical working-tree evidence does not establish preimplementation RED for this new feature. For each behavior, write/strengthen a behavioral test, actually execute the relevant assertion failure, then implement GREEN and rerun after refactoring. Tests-written, connection errors, zero executed tests, and skips are never proof of success.

## 1. Backend: Provider, Storage Keys, and Data Model (AC-01, AC-02, AC-03)

### TC-BE-01: FakeImageGenerationProvider determinism + style presets

File: `apps/api/tests/Unit/Services/FakeImageGenerationProviderTest.php` (new). Plain Pest unit test — no HTTP, no database.

| Test Case | Assertion |
|-----------|-----------|
| Style preset catalog | `getStylePresets()` returns exactly `[photorealistic, illustration, cinematic, digital_art]` with labels, in catalog order |
| Result shape | `generate()` returns an `ImageResult` with `mimeType === 'image/png'` and the requested `width`/`height` |
| Determinism | Two calls with identical `(prompt, stylePreset, width, height)` → equal sha256 of `data` |
| Prompt sensitivity | Different prompt → different sha256 |
| Style sensitivity | Different style preset → different sha256 |
| Dimension sensitivity | Different width/height → different sha256 |
| Valid PNG artifact | `data` starts with `\x89PNG\r\n\x1a\n`; IHDR encodes the exact requested width/height big-endian |
| Artifact non-empty | `sizeBytes() > 0` and `sizeBytes() === strlen(data)` |
| Interface compliance | Instance of `App\Contracts\ImageGenerationProvider` |
| CI safety | No network/subprocess/model access (pure PHP; verified by behavior: fast, hermetic execution in the suite) |

### TC-BE-02: StorageKeyBuilder::imageGeneration + data model integrity

Files: extend `apps/api/tests/Unit/StorageKeyBuilderTest.php`; new `apps/api/tests/Feature/Models/ImageModelTest.php`.

| Test Case | Assertion |
|-----------|-----------|
| Key format | `imageGeneration(7)` matches `\Aprojects/7/images/[0-9a-f-]{36}\.png\z` |
| Invalid project id | `imageGeneration(0)` and `imageGeneration(-1)` throw `InvalidArgumentException` |
| Unique keys | Two calls produce different keys (unique uuid segment) |
| Factory default state | Row persists with `status = pending`, `storage_disk = config('media.disk')`, unique `storage_key`, `parameters` cast to array |
| Enum + constants | `status` casts to `App\Enums\ImageStatus`; `STATUS_PENDING`/`STATUS_GENERATED`/`STATUS_FAILED` equal `pending`/`generated`/`failed` |
| Factory states | `generated` and `failed` states persist the corresponding status |
| Relations | `image->project` and `image->user` resolve to the owning rows |
| Unique constraint | Two factory rows never share `storage_key` |
| Fillable boundary | Only whitelisted attributes are mass-assignable |

### Commands

```bash
cd apps/api && php artisan test tests/Unit/Services/FakeImageGenerationProviderTest.php --compact
cd apps/api && php artisan test tests/Unit/StorageKeyBuilderTest.php --compact
cd apps/api && php artisan test tests/Feature/Models/ImageModelTest.php --compact
```

## 2. Backend: Generate Endpoint (AC-05, AC-08)

Use Pest feature tests under `apps/api/tests/Feature/ImageStudio/` (new directory). SPA API tests use `SpaTestCase` with real session/cookie/CSRF semantics. Base binding: `uses(SpaTestCase::class, RefreshDatabase::class);`.

### TC-BE-03: Generate 202 + pending row + job dispatched

| Test Case | Assertion |
|-----------|-----------|
| Owner + valid request | 202; `data.status === 'pending'`; `data` contains only whitelisted fields (`id`, `project_id`, `prompt`, `parameters`, `mime_type`, `size_bytes`, `width`, `height`, `status`, `created_at`, `updated_at`) |
| No storage internals | Response has no `storage_disk`, `storage_key`, `user_id`, bucket fields |
| Pending row persisted | DB row exists with `status = pending`, `parameters = {style_preset, width, height}`, `storage_disk` set, `storage_key` matching `projects/{id}/images/{uuid}.png`, `size_bytes = 0` |
| Job dispatched | With `Queue::fake()`: `GenerateImage` pushed exactly once with the new row id |
| Enqueue-only contract | Without `Queue::fake()` (sync queue): DB row is terminal after dispatch, yet the 202 body still reports `status === 'pending'` (resource serialized from the created row, no refresh) |
| Defaults | Omitted style → `parameters.style_preset === 'photorealistic'`; omitted dimensions → `width/height = 1024/1024` |
| Valid overrides | `style_preset = 'cinematic'`, `1080x1920` → persisted parameters/dimensions match |

### TC-BE-04: Non-owner 404 / non-existent 404

| Test Case | Assertion |
|-----------|-----------|
| Non-owner project | Authenticated user B posts generate with user A's project id → 404; no row created |
| Non-existent project | Unknown project id → 404; no row created |
| No information leak | 404 body identical for non-owner and non-existent cases |

### TC-BE-05: Invalid prompt 422 / guest 401 / CSRF 419 / rate limit 429

| Test Case | Assertion |
|-----------|-----------|
| Prompt missing | 422 |
| Prompt empty string | 422 |
| Prompt 1001 chars | 422 |
| Prompt exactly 1000 chars | 202 |
| Prompt non-string | 422 |
| Invalid `style_preset` | 422 |
| Only `width` (no `height`) | 422 |
| Only `height` (no `width`) | 422 |
| Non-allowed pair (e.g. 1024x768) | 422 |
| Guest (valid CSRF, no session) | 401 |
| Missing/mismatched CSRF | 419 (before auth; no row created) |
| Rate limit | 21st generate request within the minute → 429 with `Retry-After` |

### Commands

```bash
cd apps/api && php artisan test tests/Feature/ImageStudio/GenerateImageEndpointTest.php --compact
```

## 3. Backend: List and Show (AC-06, AC-07, AC-08)

### TC-BE-06: List pagination + project filter + other-user hidden

| Test Case | Assertion |
|-----------|-----------|
| Pagination envelope | 200; top-level `data` wraps `data`/`links`/`meta`; `per_page` defaults to 15; cap 100 |
| Newest first | Items ordered by `id` descending |
| User scope | Only the authenticated user's images; another user's rows never appear |
| `project_id` filter | Only that project's images returned |
| Non-owned filter | `project_id` of another user's project → 404 |
| Non-existent filter | Unknown `project_id` → 404 |
| Whitelist | Every item exposes only whitelisted fields; no `storage_disk`/`storage_key`/`user_id` |

### TC-BE-07: Show owner 200 / non-owner 404

| Test Case | Assertion |
|-----------|-----------|
| Owner | 200; whitelisted fields only |
| Non-owner | Another user's image id → 404 |
| Non-existent | Unknown image id → 404 |
| No storage internals | No `storage_disk`/`storage_key`/`user_id` in any response |

### Commands

```bash
cd apps/api && php artisan test tests/Feature/ImageStudio/ImageListTest.php --compact
cd apps/api && php artisan test tests/Feature/ImageStudio/ImageShowTest.php --compact
```

## 4. Backend: GenerateImage Queue Job (AC-04)

### TC-BE-08: Job re-validates ownership + idempotent on non-pending + marks generated/failed

File: `apps/api/tests/Feature/ImageStudio/GenerateImageJobTest.php`. Use `Storage::fake(config('media.disk'))` for artifact assertions against `image->storage_key`; inject spy/stub providers via the job's optional constructor argument.

| Test Case | Assertion |
|-----------|-----------|
| Marks generated | Pending image + job → `status = generated`; artifact exists at `image->storage_key` with the PNG signature; persisted `size_bytes === strlen(artifact)`; `width`/`height` as requested |
| Idempotent on non-pending | Second dispatch on a terminal row: provider never called (spy), status/size unchanged, no second storage write |
| Non-pending early return | `generated`/`failed` factory rows: provider never called, no state change |
| Ownership re-validation | Row whose `user_id` mismatches the project owner: warning logged, status stays pending, no storage write |
| Missing image | Job with a non-existent id throws `InvalidInputException` |
| Provider throws | Stub provider throwing → image marked `failed`; no storage object |
| Invalid result — empty data | Stub returning empty `data` → `failed`; no storage object |
| Invalid result — bad signature | Stub returning non-PNG bytes → `failed`; no storage object |
| Invalid result — dimension mismatch | Stub returning wrong `width`/`height` → `failed`; no storage object |
| Unexpected storage failure | Write exception propagates (queue retry); row remains pending — no false success |
| Sync observability | `GenerateImage::dispatch($id)` runs inline in the test environment; row terminal immediately after dispatch |
| Log hygiene | Failure logs contain sanitized reasons; no raw prompt content or storage internals beyond the key context |

### Commands

```bash
cd apps/api && php artisan test tests/Feature/ImageStudio/GenerateImageJobTest.php --compact
```

## 5. Frontend Component Tests — Vitest (AC-09, AC-10)

Use Vitest + React Testing Library under `apps/web/src/features/image-studio/components/*.test.tsx`, mirroring the clip-review component conventions (mock hooks/handlers; QueryClientProvider wrapper; no router provider — production topology).

### TC-FE-01: ImageStudio renders form, selects, button, gallery

| Test Case | Assertion |
|-----------|-----------|
| Heading + composition | "Image Studio" heading rendered; form and gallery regions present |
| Prompt textarea | Labeled textarea with `maxLength` 1000 and live `0/1000` counter |
| Style select | Four catalog options with Photorealistic selected by default |
| Dimension select | Square / Vertical 9:16 / Landscape 16:9 with Square default |
| Generate button | Present with an accessible name |
| Gallery region | Renders (empty state) without the server having images |

### TC-FE-02: Submit calls onGenerate + disabled while loading

| Test Case | Assertion |
|-----------|-----------|
| Valid submit | `onGenerate` called once with `{prompt, stylePreset, width, height}` reflecting current values |
| Preset selection | Changing style/dimension selects changes the submitted values |
| Loading disable | `generating === true` → button disabled with spinner and `aria-busy`; no submit fires |
| Whitespace guard | Whitespace-only prompt → no call |

### TC-FE-03: Empty prompt validation + error state

| Test Case | Assertion |
|-----------|-----------|
| Empty prompt | Submit → `onGenerate` not called; polite live region announces the validation error |
| Max length | 1001-character input truncated to 1000; counter shows `1000/1000` |
| Server error | Rejected generate (422/429) → readable message in the live region |
| Error clears | Typing a valid prompt clears the validation error |

### TC-FE-04: Empty state + images after generation

| Test Case | Assertion |
|-----------|-----------|
| Empty state | "No images yet" rendered when the list is empty |
| Pending entry visible | After generate resolves with a pending item, gallery shows the entry with a gray Pending badge (local pending entry) |
| Transition to generated | When the query returns the item as `generated`, the badge flips to green Generated with dimensions and non-zero size |
| Failed badge | A `failed` item shows the red Failed badge |
| Loading state | Query loading renders a stable loading indicator |
| Error + retry | Query error renders an error message; Retry calls refetch |

### TC-FE-05: Keyboard nav / focus / 44px targets

| Test Case | Assertion |
|-----------|-----------|
| Tab order | textarea → style select → dimension select → Generate reachable in logical order |
| Enter submits | Enter in the form submits via the Generate button |
| Accessible names | All interactive controls programmatically labeled |
| Live regions | `aria-live="polite"` regions exist for validation/status/error announcements |
| Touch targets | Interactive controls carry the ≥ 44px min-height classes (visual verification completed in Playwright) |
| Visible focus | Focus indicator classes present on all focusable controls |

### Commands

```bash
cd apps/web && npm test -- --run src/features/image-studio
```

## 6. Frontend Lint, Build, and Type Check

```bash
cd apps/web && npm run lint && npm run build
```

Expect: zero lint errors (including TS6133), TypeScript compiles, Vite production build succeeds.

## 7. Playwright E2E Tests (AC-09, AC-12)

Run via `npm run test:e2e` from `apps/web`. Playwright config starts Laravel at 127.0.0.1:8000, Vite at 127.0.0.1:5173, `reuseExistingServer: false`. Laravel readiness at `/api/v1/health`. Real PostgreSQL required. `FILESYSTEM_DISK=local`; the e2e server runs `QUEUE_CONNECTION=sync` (Issue #110 requirement) so generation completes during the POST and the next gallery refetch shows the terminal state. No MinIO and no Python worker needed (deterministic pure-PHP provider; the gallery fetches no image bytes). Authoritative venue: CI e2e job.

The spec file lives at `apps/web/e2e/image-studio.spec.ts` (inside `testDir: './e2e'`). A spec at `apps/web/tests/e2e/` is never discovered and is forbidden.

### Reachable UI and Verified Topology

All scenarios run against the real embedded UI: `App → AuthenticatedShell → project list → Open {project} → ProjectMediaSection → "Image Studio" tab → ImageStudio`. There is no `/images` browser route; no client-side router exists; navigating to a non-existent route is forbidden.

Data setup follows `apps/web/e2e/media.spec.ts`: register a unique user via the UI (no magic seed users — `test@example.com` does not exist), create the project via the UI, capture the project id from the real 201 response. No API interception; every generate/list request runs against the real Laravel server and must return 2xx. No test-seeding route is needed — the generate endpoint creates real fixtures through the real API.

**Console/network cleanliness is mandatory for TC-E2E-01 through TC-E2E-03**: no console errors or warnings and no unexpected 4xx/5xx anywhere (the only ratified exception is the anonymous `GET /api/v1/auth/me` 401 session probe). This applies at all three viewports.

### TC-E2E-01: Generate-image flow

1. Register a unique user via the UI; create a project via the UI (capture id from the 201 response)
2. Click `Open {project name}`; click the "Image Studio" tab; assert the empty gallery state ("No images yet")
3. Enter a prompt (≥ 10 characters, e.g. "A serene mountain lake at sunrise"); select style "Cinematic"; select dimension "Vertical 9:16"
4. Click Generate; assert `POST /api/v1/images/generate` → 202 with `body.data.status === 'pending'`; assert `body.data` has no `storage_disk`/`storage_key`/`user_id`
5. Assert the gallery immediately shows the entry with a gray Pending badge (local pending entry)
6. Assert the gallery refetch (`GET /api/v1/images?project_id=...` → 200) replaces the entry with the Generated badge (green), dimensions "1080 × 1920", and a non-zero size; with the synchronous e2e queue the transition is observed on the first refetch
7. Assert the prompt excerpt is visible in the gallery entry
8. Clean up: delete the project via the UI delete flow

### TC-E2E-02: Responsive layout at three viewports

Runs on each configured Playwright viewport project (mobile 390x844, tablet 768x1024, desktop 1440x900 — supplied by config; viewport sizes never set manually). At each viewport, run the TC-E2E-01 flow and verify:

- No horizontal overflow
- Single column below 768px (form above gallery); form and gallery side-by-side at ≥ 1024px
- Header, textarea with counter, selects, Generate button, gallery entries readable; no clipping
- Interactive controls touch-target sized (≥ 44px) with visible focus indicators
- Tab navigation to the "Image Studio" tab works; keyboard reaches all controls

### TC-E2E-03: Console and network cleanliness

During TC-E2E-01 and TC-E2E-02:

- **Browser Console**: no errors, no warnings, no uncaught exceptions
- **Network**: `GET /sanctum/csrf-cookie` → 204; `POST /api/v1/images/generate` → 202; `GET /api/v1/images` → 200; project/auth requests → 2xx; no unexpected 4xx/5xx (except the ratified anonymous `GET /api/v1/auth/me` 401 session probe); no storage/bucket requests at all (no byte-delivery URL in this slice)
- **API Responses**: image items contain only whitelisted fields; no `storage_disk`, `storage_key`, `user_id`, bucket names
- **No secrets in browser**: no tokens/credentials in request bodies, console output, or storage; `localStorage`/`sessionStorage` contain no tokens

### Commands

```bash
# Discovery: the spec must be selected by Playwright (non-empty)
cd apps/web && npx playwright test e2e/image-studio.spec.ts --list

# Full run across the three viewport projects
cd apps/web && npm run test:e2e
```

## 8. Cross-Cutting: Security, Accessibility, Responsive

### Security Matrix

| Check | Method | Pass Criteria |
|-------|--------|---------------|
| IDOR prevention — generate | Post with another user's project id | 404; no row created |
| IDOR prevention — list/show | Access another user's images via API | Never returned / 404 |
| CSRF enforcement | Mutate without `X-XSRF-TOKEN` | 419 |
| Rate limiting | Exceed 20/min on generate | 429 with `Retry-After` |
| No internal fields in API | Inspect all image responses | No `storage_disk`, `storage_key`, `user_id`, bucket |
| No secrets in browser | Inspect Network, Console, storage | No tokens/credentials |
| Queue job re-validates ownership | Job on a mismatched row | No state change, no storage write |
| Untrusted prompt | 1001-char and non-string prompts | 422; stored prompts escaped on render |
| Mass assignment | POST with extra fields (e.g. `status`, `storage_key`) | Ignored; not persisted |

### Accessibility Matrix (WCAG 2.1 AA)

| Check | Method | Pass Criteria |
|-------|--------|---------------|
| Keyboard navigation | Tab through textarea, selects, Generate, tab bar | All reachable, logical order, focus visible |
| Form submission | Enter in the form | Submits via the Generate button |
| Live regions | Trigger validation errors, status transitions | `aria-live="polite"` announcements |
| Labels | Inspect form controls | All programmatically labeled |
| Error announcements | Submit empty prompt; trigger server error | Announced in live region |
| Touch targets | All controls at 3 viewports | ≥ 44px |
| Color contrast | Badges (gray/green/red), text | 4.5:1 minimum for text |

### Responsive Matrix

| Viewport | Layout Checks |
|----------|---------------|
| 390x844 | Single column, form above gallery, touch targets ≥ 44px, no horizontal scroll |
| 768x1024 | Stacked or two-column; form and gallery readable |
| 1440x900 | Form and gallery side-by-side; comfortable line lengths |

## 9. Required Commands, Discovery, and CI

| Working Directory | Command / Required Result |
|---|---|
| Repository root | `docker compose up -d postgres` — verify health |
| `apps/api` | `php artisan migrate:fresh --seed` — clean database |
| `apps/api` | `php artisan test --exclude-group=real-integration` — fast feedback; zero failures |
| `apps/api` | `php artisan test tests/Feature/ImageStudio tests/Feature/Models/ImageModelTest.php tests/Unit/StorageKeyBuilderTest.php tests/Unit/Services/FakeImageGenerationProviderTest.php --compact` — all image-studio suites pass (TC-BE-01..08) |
| `apps/api` | `vendor/bin/pint --dirty --format agent` — formatting |
| `apps/web` | `npm test` — Vitest suite pass (TC-FE-01..05 included) |
| `apps/web` | `npm run lint` — lint pass |
| `apps/web` | `npm run build` — TypeScript + Vite build pass |
| `apps/web` | `npx playwright test e2e/image-studio.spec.ts --list` — non-empty discovery |
| `apps/web` | `npm run test:e2e` — Playwright all viewport projects; TC-E2E-01..03 pass at 3 viewports; console/network clean |
| Repository root | `python -m unittest discover -s tests/governance -p 'test_*.py' -v` — governance pass |

Verify installed runner support for fail-closed options during setup. The standalone E2E run must explicitly show the image-studio spec's tests selected (positive counts) and all passing across the three viewport projects.

## 10. Test Case to Acceptance Criteria Mapping

| Test Case | AC Covered |
|-----------|------------|
| TC-BE-01: Fake provider determinism + style presets | AC-01 |
| TC-BE-02: Storage key format/invalid project/unique keys + model integrity | AC-02, AC-03 |
| TC-BE-03: Generate 202 + pending row + job dispatched | AC-05, AC-08 |
| TC-BE-04: Non-owner 404 / non-existent 404 | AC-05 |
| TC-BE-05: Invalid prompt 422 / guest 401 / CSRF 419 / rate limit 429 | AC-05 |
| TC-BE-06: List pagination + project filter + other-user hidden | AC-06, AC-08 |
| TC-BE-07: Show owner 200 / non-owner 404 | AC-07, AC-08 |
| TC-BE-08: Job ownership re-validation + idempotency + generated/failed marking | AC-04 |
| TC-FE-01: ImageStudio renders form/selects/button/gallery | AC-09 |
| TC-FE-02: Submit calls onGenerate + disabled while loading | AC-09, AC-10 |
| TC-FE-03: Empty prompt validation + error state | AC-09 |
| TC-FE-04: Empty state + images after generation | AC-09, AC-10 |
| TC-FE-05: Keyboard nav / focus / 44px targets | AC-09 |
| TC-E2E-01: Generate-image flow | AC-09, AC-10, AC-12 |
| TC-E2E-02: Responsive layout at three viewports | AC-09, AC-12 |
| TC-E2E-03: Console and network cleanliness | AC-08, AC-12 |
| Cross-cutting Security | AC-05, AC-06, AC-07, AC-08 |
| Cross-cutting Accessibility | AC-09 |
| Full backend/frontend/E2E suites + lint/build/governance | AC-11, AC-12 |
| Correction Fix A — TC-E2E-02 overflow assertion at 3 viewports (CI Round 3) | AC-13 |
| Correction Fix B — TC-E2E-02 keyboard focus walk, five targets at 3 viewports (CI Round 3) | AC-14 |
| Correction no-regression — TC-E2E-01, TC-E2E-03, all local suites, full CI green (CI Round 3) | AC-15 |

## 11. CI Round 3 Correction — Verification Addendum (Issue #113 clarification)

This addendum verifies the two corrections defined in `spec.md` ("CI Round 3 Correction": Fix A overflow, Fix B focus-walk interaction) and `plan.md` Step C. It supplements — and does not replace — sections 1–10: AC-01 through AC-12 and every test case above remain in force, and previously passing suites must stay green.

### 11.1 RED evidence (already obtained — do not re-litigate or manufacture)

The RED for both defects is the CI Round 3 run on commit `2113c87` (PR #114), `npm run test:e2e` → **99 passed, 3 failed**, all in `apps/web/e2e/image-studio.spec.ts` TC-E2E-02:

```
[mobile]  TC-E2E-02 line 338: expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth)
          → Expected `<= 390`, Received `408`
[tablet]  TC-E2E-02 line 338: expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth)
          → Expected `<= 768`, Received `786`
[desktop] TC-E2E-02 line 442: image-studio-tab visible focus indicator
          → expect(focusVisible['image-studio-tab']).toBe(true) received `false`
```

This is valid RED: real behavioral assertion failures on a run where 99 other tests executed green (not environment, not setup, not discovery). Builder records this output verbatim in `evidence.md` under the `### RED` heading (`evidence.md` is Builder/Tester-owned; the Planner does not edit it). TC-E2E-01 passed at all three viewports and governance/pr-enforcement/frontend `test`/backend `tests` were green in the same run — those states are the no-regression baseline.

### 11.2 Local verification (required on the fix commit before push)

| Working Directory | Command / Required Result |
|---|---|
| `apps/web` | `npm test` — full Vitest suite green (Fix A touches only CSS and Fix B only the e2e file; any component-test failure signals scope creep) |
| `apps/web` | `npm run lint` — exit 0 |
| `apps/web` | `npm run build` — TypeScript + Vite build pass (the build must contain the corrected `.image-studio-form` rule) |
| `apps/web` | `npx playwright test e2e/image-studio.spec.ts --list` — non-empty discovery: **6 tests in 1 file** (TC-E2E-01 + TC-E2E-02 × mobile/tablet/desktop) |
| `apps/api` | `php artisan test --exclude-group=real-integration` — green; this correction touches no PHP, so any backend delta is out of scope |
| `apps/api` | `vendor/bin/pint --dirty --format agent` — pass (expected no-op; nothing PHP changed) |
| Repository root | governance suite where locally executable; otherwise CI `governance` is authoritative |

Also re-run statically: confirm the CSS diff is confined to the `.image-studio-form` fix (canonically the single `box-sizing: border-box` declaration; per `spec.md` an equivalently correct fix is acceptable only if it stays within that rule and removes no other), the e2e diff touches only the TC-E2E-02 focus interaction, and `IMAGE_STUDIO_FOCUS_TARGETS` still lists all five targets with unchanged selectors.

### 11.3 CI-authoritative checks (cannot run in this sandbox)

`npm run test:e2e` **cannot execute locally**: the sandbox PHP lacks `pdo_pgsql` and `apps/api/.env` does not exist, so the Playwright webServer's Laravel stack returns 500 at the auth step — the ratified **Issue #110 precedent**, independently proven and recorded in `evidence.md`. CI provisions PostgreSQL 16 + `pdo_pgsql`; **CI is the e2e venue**. No local workaround, interception, reduced viewport set, or reduced spec selection may be substituted, and the e2e check may never be skipped or downgraded.

### 11.4 Exact CI pass condition

On the fix commit, **all** of the following must be green:

1. `e2e` (`npm run test:e2e`): **zero failures**; TC-E2E-01 **and** TC-E2E-02 pass at **390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)** — including line 338 `scrollWidth <= innerWidth` at all three, the layout/clipping/touch-target checks at all three, and line 442 `focusVisible[key] === true` for all five targets at all three; TC-E2E-03 console/network cleanliness intact.
2. `governance` — green.
3. `pr-enforcement` — green (evidence TDD headings + single trailing `Decision:` line).
4. `test` (frontend Vitest) — green.
5. `tests` (backend, full tree on CI PostgreSQL) — green.

`scripts/merge_gate.py` is fail-closed on these checks; 99-passed/3-failed, partial passes, or a green run with any skipped image-studio test do **not** satisfy the condition.

### 11.5 TDD and evidence rules for this correction

- **RED**: the CI Round 3 output above, quoted verbatim in `evidence.md` under `### RED`.
- **GREEN**: (a) CI `e2e` green on the fix commit per §11.4 **and** (b) every local suite in §11.2 green after the fix. GREEN is not claimed from `--list` discovery, from code inspection alone, or from any run in which a required test was skipped.
- **REFACTOR**: any cleanup after GREEN must keep all suites green with no behavior change.
- `evidence.md` structure is mandatory: the literal `### RED`, `### GREEN`, `### REFACTOR` headings must be preserved (the `pr-enforcement` gate requires them), correction evidence is appended without deleting prior rounds, and the file must end with **exactly one** `Decision:` line — that line is **Tester-owned** (Builder appends evidence before it; Tester re-issues the final verdict).

### 11.6 Test-integrity prohibitions (binding on Builder)

Forbidden while producing GREEN: removing, relaxing, skipping, or subset-asserting any check (in this spec or any other); removing `image-studio-tab` from `IMAGE_STUDIO_FOCUS_TARGETS` or changing its selector; hardcoding `true` for any focus target (AGENTS.md §24); replacing computed-style measurement with class presence; deleting or bypassing the overflow assertion; reducing the Playwright project/viewport set; disabling console/network collectors; touching `clip-review.spec.ts` (or any other spec's) assertions; weakening any component or backend test. Fix B changes **how focus is established**, never **what is asserted**.

### 11.7 Test case ↔ correction acceptance criteria mapping

| Test Case / Check | AC Covered |
|-----------|------------|
| TC-E2E-02 line 338 overflow at 390/768/1440 (CI) | AC-13 |
| TC-E2E-02 keyboard focus walk — 5 targets × 3 viewports (CI) | AC-14 |
| TC-E2E-01 flow at 3 viewports + TC-E2E-03 cleanliness (CI, no regression) | AC-15 |
| Local suites: Vitest, lint, build, `--list`, backend, Pint (no regression) | AC-15 |
| CI `governance` + `pr-enforcement` (evidence structure, single `Decision:`) | AC-15, AC-12 |

---

SPEC_READY
