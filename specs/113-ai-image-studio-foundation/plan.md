# Implementation Plan: AI Image Studio Foundation — Issue #113

## Active Scope and Working-Tree Baseline

Issue: [Issue #113](https://github.com/CarlosEGoulart/AiClip/issues/113). `spec.md` is the behavior contract; `test-plan.md` defines required verification. All steps follow Laravel 13, React 19, TypeScript, Pest, Vitest, Playwright conventions.

Planning inspected the active issue, architecture, `docs/project-state.md`, the M6/M7 implementations (`RenderMediaClip`, `ProcessMediaAction`, `StorageKeyBuilder`, `ClipReview` model/API/job, clip-review frontend), the API route/limiter conventions (`routes/api.php`, `AppServiceProvider`), and the frontend topology (`App → AuthenticatedShell → ProjectMediaSection → tabs`; native History API only; no client-side router library).

Observed current state:

- No image-related code exists: no `Image` model/migration/factory, no image routes, no `image-studio` feature directory in `apps/web`.
- `StorageKeyBuilder` (final class, `App\Services`) exposes `renderClip` and `captionFile` with validated identity inputs (`$id < 1` throws `InvalidArgumentException`) and `Str::uuid()` suffixes; `tests/Unit/StorageKeyBuilderTest.php` exists to extend.
- Job topology: `RenderMediaClip` (queue job, optional action injection, `tries = 3`/`backoff = 5`, `uniqueId()`, `DB::transaction` persistence, classified-failure marking); `ProcessMediaAction` runs the Python CLI subprocess; results are strictly validated in Laravel before persistence.
- Routes: `routes/api.php` uses a `v1` prefix, an `auth:sanctum` group, and per-endpoint `throttle:` middleware; rate limiters are registered in `AppServiceProvider` via `RateLimiter::for` (per user id with ip fallback); a testing-only seed route pattern exists (Issue #110).
- Controllers/resources live in `App\Http\Controllers\Api\V1` and `App\Http\Resources`; single items wrap in `{"data": {...}}`; list endpoints wrap the standard pagination envelope in a top-level `data` key (`ClipReviewController::index` convention).
- Frontend features: `auth`, `clip-review`, `media`, `projects`. The clip-review feature provides the conventions to mirror: `api.ts` (`API_BASE = '/api/v1'`, `fetchWithCsrf` with the wire-cookie `X-XSRF-TOKEN`), `hooks.ts` (React Query), `types.ts`, `index.ts`, `components/*.test.tsx`. `ProjectMediaSection` currently has two tabs (`media` | `review`) with the `role="tablist"` pattern.
- E2E: `apps/web/e2e/*.spec.ts` (auth, clip-review, health, media, projects); the data pattern in `media.spec.ts` (register unique user via UI, create project via UI, capture the id from the real 201 response); Issue #110 established that the e2e `php artisan serve` process runs `QUEUE_CONNECTION=sync`.
- phpunit (test env): `QUEUE_CONNECTION=sync`, sqlite `:memory:`. `.env` (local/e2e): `QUEUE_CONNECTION=database`, `FILESYSTEM_DISK=local`, `SESSION_DRIVER=database`, `DB_CONNECTION=pgsql`.
- `config('media.disk')` (default `media`) is the configured disk for asset writes; the e2e environment resolves it to local storage.

No existing behavior changes are required. The slice is purely additive: provider seam, data model, job, endpoints, feature UI, tests, and one new tab.

## Step 0 — Repository Setup and Dependency Verification

1. Verify the active issue #113 is the only implementation issue (NO_ACTIVE_ISSUE → Issue #113 active) and the branch `@carlosegoulart/113/feat/ai-image-studio-foundation` is checked out.
2. Verify `composer.json` requires no new packages: PNG synthesis is pure PHP (zlib); the slice uses existing Laravel queue, validation, filesystem, and Sanctum abstractions.
3. Verify `package.json` requires no new packages: existing React, React Query, Testing Library, and Playwright.
4. Run `docker compose up -d postgres` from the repository root; verify PostgreSQL health.
5. Run `php artisan migrate:fresh --seed` in `apps/api` to establish a clean database.
6. Run `php artisan test --compact` in `apps/api` and `npm test` in `apps/web` to confirm the baseline passes.
7. Confirm no `.env` secrets are read or inspected.

## Step 1 — Backend: Provider Abstraction + Deterministic Fake (RED → GREEN → REFACTOR)

### 1.1 RED — Unit tests for the fake provider

File: `apps/api/tests/Unit/Services/FakeImageGenerationProviderTest.php` (new).

Test scenarios (each RED before implementation):

- `getStylePresets()` returns exactly the four catalog entries (`photorealistic`, `illustration`, `cinematic`, `digital_art`) with their labels, in catalog order.
- `generate()` returns an `ImageResult` with `mimeType === 'image/png'` and the requested `width`/`height`.
- Determinism: two calls with identical `(prompt, stylePreset, width, height)` produce byte-identical `data` (equal sha256).
- Sensitivity: a different prompt, style, or dimensions each produce different output bytes (different sha256).
- Valid PNG: `data` starts with the 8-byte PNG signature; the IHDR chunk encodes the exact requested width and height (big-endian at the fixed offset).
- Artifact non-empty: `sizeBytes() > 0` and equals `strlen(data)`.
- Interface compliance: the fake is an instance of `App\Contracts\ImageGenerationProvider`.

If the run errors with "class not found" instead of an assertion failure, add interface/ method stubs returning wrong values so the RED failure is behavioral (missing behavior), not a setup failure.

### 1.2 GREEN — Implement the provider seam

- `apps/api/app/Contracts/ImageGenerationProvider.php`: interface with `getStylePresets(): array` and `generate(string $prompt, string $stylePreset, int $width, int $height): ImageResult` (per `spec.md`).
- `apps/api/app/Services/ImageResult.php`: `final readonly` DTO with `data`, `mimeType`, `width`, `height` and `sizeBytes(): int`.
- `apps/api/app/Services/FakeImageGenerationProvider.php`: implements the interface; fixed style catalog constant; deterministic pure-PHP PNG synthesis (IHDR + zlib-deflated IDAT + IEND) with pixel bytes seeded from a hash of `(prompt, stylePreset, width, height)`; no network, subprocess, or model access.
- Bind in `AppServiceProvider`: `$this->app->singleton(ImageGenerationProvider::class, FakeImageGenerationProvider::class)`.

### 1.3 REFACTOR

- Rerun `php artisan test tests/Unit/Services/FakeImageGenerationProviderTest.php --compact`; all green.
- `vendor/bin/pint --dirty --format agent` in `apps/api`.

## Step 2 — Backend: Storage Key Builder + Image Model/Migration/Factory (RED → GREEN → REFACTOR)

### 2.1 RED — Unit/feature tests for keys and model

- Extend `apps/api/tests/Unit/StorageKeyBuilderTest.php`:
  - `imageGeneration(7)` matches `\Aprojects/7/images/[0-9a-f-]{36}\.png\z`.
  - `imageGeneration(0)` and `imageGeneration(-1)` throw `InvalidArgumentException`.
  - Two calls produce different keys (unique uuid segment).
- New `apps/api/tests/Feature/Models/ImageModelTest.php`:
  - Factory default state persists a row with `status = pending`, `storage_disk = config('media.disk')`, a unique `storage_key`, and `parameters` cast to array.
  - `status` casts to `App\Enums\ImageStatus`; constants `STATUS_PENDING`/`STATUS_GENERATED`/`STATUS_FAILED` equal the enum values.
  - `generated` and `failed` factory states persist the corresponding status.
  - `project()` and `user()` relations resolve.
  - Two factory rows never share a `storage_key` (unique index honored).

If the key-builder method is missing, add a stub returning `''` so the RED failure is the behavioral assertion.

### 2.2 GREEN — Implement keys, migration, model, factory

- `StorageKeyBuilder::imageGeneration(int $projectId): string`: throw `InvalidArgumentException('Invalid image storage identity.')` when `$projectId < 1`; return `sprintf('projects/%d/images/%s.png', $projectId, Str::uuid()->toString())` (mirrors `renderClip`/`captionFile`).
- Migration `create_images_table` with the exact columns and indexes from `spec.md` (unique `storage_key`; index `[user_id, status]`; index `project_id`).
- `App\Enums\ImageStatus`: backed string enum `PENDING`/`GENERATED`/`FAILED`.
- `App\Models\Image`: casts (`parameters` array, `status` enum), `STATUS_*` constants, `project()`/`user()` relations, `$fillable` limited to `user_id`, `project_id`, `prompt`, `parameters`, `storage_disk`, `storage_key`, `mime_type`, `size_bytes`, `width`, `height`, `status`.
- `database/factories/ImageFactory.php`: default `pending`; `generated`/`failed` states; `storage_key` via `StorageKeyBuilder::imageGeneration()`.

### 2.3 REFACTOR

- `php artisan migrate:fresh --seed`; rerun the Step 2 suites; green.
- `vendor/bin/pint --dirty --format agent`.

## Step 3 — Backend: GenerateImage Queue Job (RED → GREEN → REFACTOR)

### 3.1 RED — Job feature test

File: `apps/api/tests/Feature/ImageStudio/GenerateImageJobTest.php` (new). Use `Storage::fake(config('media.disk'))` for artifact assertions against `image->storage_key`.

Test scenarios:

- Pending image (factory) + run job → `status = generated`; `Storage::disk(...)->exists($image->storage_key)` is true; stored bytes start with the PNG signature; persisted `size_bytes === strlen(stored bytes)`; `width`/`height` persisted as requested.
- Idempotency: run the job twice → the second run does not invoke the provider (spy provider) and leaves status/size/mtime unchanged.
- Non-pending image (`generated` factory state) → job returns early; provider never called; no state change.
- Ownership mismatch (row whose `user_id` differs from the project owner) → job logs a warning and returns; status stays pending; no storage write.
- Missing image id → throws `App\Exceptions\InvalidInputException`.
- Throwing provider (stub) → image marked `failed`; no storage object written.
- Invalid provider result (wrong width, empty data, or bad PNG signature via stub provider) → image marked `failed`; no storage object written.
- Sync observability: `GenerateImage::dispatch($image->id)` executes inline in the test environment (`QUEUE_CONNECTION=sync`) so the row is already terminal after dispatch.

### 3.2 GREEN — Implement the job

- `apps/api/app/Jobs/GenerateImage.php` per `spec.md`: `tries = 3`, `backoff = 5`, `uniqueId() = "generate-image:{imageId}"`, optional provider injection; behavior order: load → ownership re-validation → non-pending idempotency → provider call → strict `ImageResult` validation → storage write → transactional persistence; classified provider/validation failures mark `failed` without writing an object; unexpected storage exceptions propagate for queue retry.

### 3.3 REFACTOR

- Rerun `php artisan test tests/Feature/ImageStudio/GenerateImageJobTest.php --compact`; green.
- `vendor/bin/pint --dirty --format agent`.

## Step 4 — Backend: API Controller + Routes + Rate Limiters + ImageResource (RED → GREEN → REFACTOR)

### 4.1 RED — Endpoint feature tests

Files (new, under `apps/api/tests/Feature/ImageStudio/`): `GenerateImageEndpointTest.php`, `ImageListTest.php`, `ImageShowTest.php`. SPA tests use `SpaTestCase` with real session/cookie/CSRF semantics (`uses(SpaTestCase::class, RefreshDatabase::class);`).

`GenerateImageEndpointTest` scenarios:

- Owner + valid request → 202; `data.status === 'pending'`; `data` contains only whitelisted fields (no `storage_disk`/`storage_key`/`user_id`); DB row exists with `status = pending`, correct `parameters` JSON, `storage_disk`/`storage_key` set; `GenerateImage` dispatched with the row id (`Queue::fake()` + `Queue::assertPushed`).
- Sync-mode contract: without `Queue::fake()`, dispatch runs inline yet the 202 body still reports `pending` (resource serialized from the created row, no refresh), while the DB row is terminal — assert both.
- Non-owner project → 404; non-existent project → 404.
- Prompt: missing → 422; empty string → 422; 1001 chars → 422; exactly 1000 chars → 202; non-string → 422.
- `style_preset`: invalid value → 422; valid value → 202 with `parameters.style_preset` persisted; omitted → defaults to `photorealistic`.
- Dimensions: only `width` → 422; only `height` → 422; non-allowed pair (e.g. 1024x768) → 422; allowed pair 1080x1920 → 202; both omitted → defaults 1024x1024.
- Guest (valid CSRF, no session) → 401; missing CSRF → 419 (before auth).
- Rate limit: 21st generate request within the minute → 429 with `Retry-After`.

`ImageListTest` scenarios:

- Pagination envelope: top-level `data` wrapping `data`/`links`/`meta`; `per_page` default 15 and cap 100; newest first.
- User scope: another user's images never appear in the response.
- `project_id` filter returns only that project's images; filter with a non-owned/non-existent project → 404.
- No storage internals in any item.

`ImageShowTest` scenarios:

- Owner → 200 with whitelisted fields only.
- Non-owner → 404; non-existent → 404.

### 4.2 GREEN — Implement the API layer

- `GenerateImageRequest` (or equivalent inline validation) with the exact rules from `spec.md`.
- `App\Http\Controllers\Api\V1\ImageController` with `generate`/`index`/`show` implementing the ordered behavior (validation → owned project → create pending row with `StorageKeyBuilder::imageGeneration()` → dispatch → 202 with the created row's resource; no refresh after dispatch).
- `App\Http\Resources\ImageResource` with the exact whitelist (`id`, `project_id`, `prompt`, `parameters`, `mime_type`, `size_bytes`, `width`, `height`, `status`, `created_at`, `updated_at`).
- Routes in `routes/api.php` inside the `auth:sanctum` group, registered in order: `POST /images/generate` (throttle `image-generate`), `GET /images` (throttle `image-list`), `GET /images/{image}` (throttle `image-show`).
- `AppServiceProvider`: `RateLimiter::for('image-generate', ...)` at 20/min and `image-list`/`image-show` at 60/min, keyed by user id with ip fallback (existing pattern).

### 4.3 REFACTOR

- Rerun `php artisan test tests/Feature/ImageStudio --compact`; green.
- `php artisan route:list` sanity check (image routes present under `v1`).
- `vendor/bin/pint --dirty --format agent`.

## Step 5 — Frontend: Hooks + ImageStudio Components (RED → GREEN → REFACTOR)

### 5.1 RED — Vitest tests

Files (new, `apps/web/src/features/image-studio/components/*.test.tsx`), mirroring the clip-review component test conventions (mock hooks/handlers; QueryClientProvider wrapper; no router):

- **TC-FE-01 (ImageStudio composition)**: renders the "Image Studio" heading; renders `GenerateImageForm` (labeled textarea, `0/1000` counter, style select with the four catalog options and Photorealistic default, dimension select with the three presets and Square default, Generate button) and the `ImageGallery` region.
- **TC-FE-02 (submit + loading)**: valid prompt + submit calls `onGenerate` once with `{prompt, stylePreset, width, height}` reflecting the current select values; while `generating` is true the button is disabled with a spinner and `aria-busy`; no submit fires while disabled.
- **TC-FE-03 (validation + error state)**: empty or whitespace-only prompt → `onGenerate` not called and a polite live region announces the validation error; the textarea enforces `maxLength` 1000 (1001-char input truncated, counter shows `1000/1000`); a rejected generate (422/429) surfaces a readable error message in the live region.
- **TC-FE-04 (gallery states)**: empty list → "No images yet"; after a generate resolves with a pending item, the gallery shows that entry with a Pending badge (local pending entry); once the images query returns the item as `generated`, the badge flips to Generated with dimensions and a non-zero size; a `failed` item shows the Failed badge; query loading and error states render (error state includes a retry that calls refetch).
- **TC-FE-05 (keyboard/focus/targets)**: tab order reaches textarea → style select → dimension select → Generate; Enter submits the form; all controls have accessible names; `aria-live` regions exist for announcements; interactive controls carry the ≥ 44px min-height classes (visual verification completed in Playwright).

### 5.2 GREEN — Implement the feature

- `apps/web/src/features/image-studio/types.ts`: `ImageStatus`/`ImageItem`/`ImageListResponse` types + `STYLE_PRESETS` and `DIMENSION_PRESETS` constants mirroring the backend catalog.
- `apps/web/src/features/image-studio/api.ts`: `generateImage({projectId, prompt, stylePreset, width, height})` → POST `/api/v1/images/generate` and `fetchImages({projectId, page})` → GET `/api/v1/images`, both via the `fetchWithCsrf` wire-cookie pattern (`API_BASE = '/api/v1'`); `fetchImages` unwraps the top-level `data` envelope to the standard pagination object (clip-review convention).
- `apps/web/src/features/image-studio/hooks.ts`: `useImages(projectId)` (query key `['images', projectId]`; `refetchInterval` 2000 while the server list or a local pending entry is pending, false otherwise) and `useGenerateImage()` (mutation; on success invalidates `['images']`).
- `apps/web/src/features/image-studio/components/ImageStudio.tsx`: composition; holds local pending entries seeded from the 202 response; display list = local pending entries (ids not yet in the server list) prepended to the server list; renders loading/empty/error states.
- `apps/web/src/features/image-studio/components/GenerateImageForm.tsx`: labeled textarea with counter, style/dimension selects, Generate button (disabled while empty/whitespace prompt or generating; spinner; form submit on Enter); polite live region for validation/server errors.
- `apps/web/src/features/image-studio/components/ImageGallery.tsx`: entries with prompt excerpt, dimensions, size, status badges (Pending gray, Generated green, Failed red); empty/loading/error states; polite live region for status announcements.
- `apps/web/src/features/image-studio/index.ts`: public exports.
- `apps/web/src/features/media/components/ProjectMediaSection.tsx`: add the "Image Studio" tab (`Tab` type extended; `role="tab"`/`aria-selected`/`aria-controls`/ids `image-studio-tab`/`image-studio-panel` following the existing pattern) rendering `<ImageStudio projectId={projectId} />`.

### 5.3 REFACTOR

- Rerun `npm test -- --run src/features/image-studio`; all green; existing suites unaffected (rerun full `npm test`).
- `npm run lint` and `npm run build` in `apps/web`.

## Step 6 — E2E: Generate-Image Playwright Flow (RED → GREEN → REFACTOR)

### 6.1 RED — Write the Playwright spec

File: `apps/web/e2e/image-studio.spec.ts` (new; inside `testDir: './e2e'` — a spec under `apps/web/tests/e2e/` is never discovered and is forbidden).

All scenarios:

- Run against the real UI topology `App → AuthenticatedShell → project list → Open {project} → ProjectMediaSection → "Image Studio" tab → ImageStudio`. No client-side routes; no API interception — every request runs against the real Laravel server.
- Data setup follows `media.spec.ts`: register a unique user via UI (no magic seed users), create the project via UI, capture the project id from the real 201 response.
- Environment: real Laravel + PostgreSQL, `FILESYSTEM_DISK=local`, `QUEUE_CONNECTION=sync` on the e2e server (Issue #110 requirement) so generation completes during the POST and the next gallery refetch shows the terminal state; no MinIO and no Python worker required (deterministic pure-PHP provider).
- Viewports come from the configured Playwright projects (390x844, 768x1024, 1440x900); viewport sizes are never set manually.
- Console/network cleanliness enforced (no errors/warnings, no uncaught exceptions, no unexpected 4xx/5xx except the ratified anonymous `GET /api/v1/auth/me` 401 session probe, no tokens in browser storage).
- Scenarios: TC-E2E-01 generate flow (empty state → prompt + presets → Generate → POST 202 with `status === 'pending'` and no storage fields → pending badge → poll/refetch → Generated badge with dimensions and non-zero size → prompt excerpt visible); TC-E2E-02 responsive layout at the three viewports (single column < 768px, side-by-side ≥ 1024px, no horizontal overflow, ≥ 44px targets, visible focus); TC-E2E-03 console/network cleanliness.
- Clean up: delete the project via the UI delete flow.

### 6.2 GREEN — Run

- Backend (Steps 1–4) and frontend (Step 5) must be GREEN before E2E can pass.
- `cd apps/web && npx playwright test e2e/image-studio.spec.ts --list` (discovery, non-empty).
- `cd apps/web && npm run test:e2e` (full run across the three viewport projects).

### 6.3 REFACTOR

- Rerun full E2E; all green across viewports; screenshots captured on failure only.
- `npm run lint && npm run build` remain green.

## Step 7 — Full Test Suite and Verification

| Working Directory | Command / Required Result |
|---|---|
| Repository root | `docker compose up -d postgres` — verify health |
| `apps/api` | `php artisan migrate:fresh --seed` — clean database |
| `apps/api` | `php artisan test --exclude-group=real-integration` — fast feedback; zero failures |
| `apps/api` | `php artisan test tests/Feature/ImageStudio tests/Feature/Models/ImageModelTest.php tests/Unit/StorageKeyBuilderTest.php tests/Unit/Services/FakeImageGenerationProviderTest.php --compact` — all image-studio suites pass |
| `apps/api` | `vendor/bin/pint --dirty --format agent` — formatting |
| `apps/web` | `npm test` — Vitest suite pass |
| `apps/web` | `npm run lint` — lint pass |
| `apps/web` | `npm run build` — TypeScript + Vite build pass |
| `apps/web` | `npx playwright test e2e/image-studio.spec.ts --list` — non-empty discovery |
| `apps/web` | `npm run test:e2e` — Playwright all viewport projects; image-studio scenarios pass at 3 viewports; console/network clean |
| Repository root | `python -m unittest discover -s tests/governance -p 'test_*.py' -v` — governance pass |

## Verification Checklist

- [ ] Provider seam: interface + `ImageResult` + deterministic fake bound in the container; fixed style catalog; byte-identical output for identical inputs; valid PNG with exact dimensions; no network/subprocess/model download
- [ ] `StorageKeyBuilder::imageGeneration`: `projects/{id}/images/{uuid}.png`; `$projectId < 1` throws; unique keys
- [ ] Data model: `images` migration/model/enum/factory with specified columns, casts, indexes, and states; `status` defaults to `pending`; unique `storage_key`
- [ ] `GenerateImage` job: queue-only; ownership re-validation; non-pending idempotency; strict result validation; `generated` with a real stored artifact or `failed`; unexpected storage failures leave the row pending for retry
- [ ] `POST /api/v1/images/generate`: 202 + pending resource (enqueue only, no refresh after dispatch); 422 prompt/preset/dimension; 404 non-owned/non-existent project; 401 guest; 419 missing CSRF; 429 over 20/min
- [ ] `GET /api/v1/images`: user-scoped, newest first, standard pagination envelope, owned `project_id` filter (404 otherwise), other users' images hidden
- [ ] `GET /api/v1/images/{image}`: owner 200; non-owner/non-existent 404
- [ ] `ImageResource` whitelist: no `storage_disk`/`storage_key`/`user_id`/bucket in any response
- [ ] `ImageStudio`: form with counter/presets/disabled-while-loading button; gallery with badges and loading/empty/error states; pending 202 entry immediately visible; polling flips it to terminal state
- [ ] Tab wiring: "Image Studio" tab in `ProjectMediaSection` per M7 Slice 1 conventions; no router library
- [ ] Pest unit + feature suites pass; no skipped tests
- [ ] Vitest component suites pass; no skipped tests
- [ ] Playwright E2E passes at 390x844 / 768x1024 / 1440x900; console/network clean; no secrets in browser
- [ ] Full backend/frontend/E2E suites pass; lint, build, type check pass; governance passes

## Remaining Gates (Not Claimed Complete)

- Independent Tester review: runs all backend/frontend/E2E tests, operates the running app, inspects screenshots at 3 viewports, checks console/network/API, validates accessibility, security, ownership, CSRF, secret exposure.
- Orchestrator: commits, pushes, creates PR, monitors CI, merges after Tester approval and CI green (via `scripts/merge_gate.py`).
- Issue closure after verified merge.

---

SPEC_READY
