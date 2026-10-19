# Specification: AI Image Studio Foundation — Issue #113

## Authority and Description

Active issue: [Issue #113](https://github.com/CarlosEGoulart/AiClip/issues/113).

M0–M7 are complete: governance, Sanctum SPA auth, project management, media storage, queued processing, transcription, scene detection, clip recommendation, vertical rendering with captions, and the clip review experience. The roadmap defines M8 as the AI Image Studio; the PRD requires generating images from text prompts and organizing them in projects.

This slice (M8 Slice 1) delivers the **AI Image Studio foundation**: provider abstraction, generate API, and UI entry point. It defines:

1. The `ImageGenerationProvider` PHP interface (`generate`, `getStylePresets`) with a deterministic `FakeImageGenerationProvider` as the bound implementation — no model download, CI-safe (AGENTS.md §21).
2. `StorageKeyBuilder::imageGeneration()` for project-scoped image storage keys, following the existing `renderClip`/`captionFile` validation pattern.
3. The `Image` Eloquent model, `images` migration, `ImageStatus` enum, and `ImageFactory`.
4. The `GenerateImage` queue job following the `RenderMediaClip` topology: Laravel queue → provider boundary (the future model-backed provider invokes `ProcessMediaAction` → Python CLI subprocess) → strict Laravel result validation → Laravel-owned PostgreSQL persistence. Heavy generation never runs inside HTTP request processes (AGENTS.md §20).
5. The public API: `POST /api/v1/images/generate` (validate + ownership + enqueue only → 202), `GET /api/v1/images` (paginated, user-scoped), `GET /api/v1/images/{image}` (owner only).
6. The frontend: `ImageStudio` page embedded as a ProjectMediaSection tab, `useGenerateImage`/`useImages` React Query hooks, metadata gallery with status badges, no client-side router library.

Read this specification with `plan.md` and `test-plan.md` in this directory. The active issue and explicit human corrections define this increment. `docs/architecture.md`, `docs/prd.md`, `docs/roadmap.md`, `docs/project-state.md`, and the M6/M7 slice specifications provide context, not authorization for their broader future workflows.

## Technical Tasks

- [ ] Define the `ImageGenerationProvider` interface (`generate`, `getStylePresets`) and the `ImageResult` DTO
- [ ] Define the deterministic `FakeImageGenerationProvider` (valid PNG synthesis, fixed style preset catalog, no network/subprocess/model download) and its container binding
- [ ] Define `StorageKeyBuilder::imageGeneration(int $projectId): string` with input validation
- [ ] Define the `images` migration, `Image` model, `ImageStatus` enum, and `ImageFactory` (pending/generated/failed states)
- [ ] Define the `GenerateImage` queue job (ownership re-validation, non-pending idempotency, strict result validation, generated/failed marking, storage write + Laravel persistence)
- [ ] Define `POST /api/v1/images/generate` (validate + ownership + enqueue only → 202 with pending `ImageResource`; 422/404/401/419/429)
- [ ] Define `GET /api/v1/images` (paginated, authenticated user's images, optional owned `project_id` filter) and `GET /api/v1/images/{image}` (owner only)
- [ ] Define the `ImageResource` field whitelist (never storage internals)
- [ ] Define the `image-generate` (20/min), `image-list`, and `image-show` rate limiters
- [ ] Define the frontend `image-studio` feature: types, api client, `useGenerateImage`/`useImages` hooks, `ImageStudio`/`GenerateImageForm`/`ImageGallery`
- [ ] Define the `ProjectMediaSection` "Image Studio" tab wiring (M7 Slice 1 conventions; no client-side router library)
- [ ] Define Pest backend tests (provider, storage keys, model, job, endpoints)
- [ ] Define Vitest component tests for `ImageStudio`
- [ ] Define Playwright E2E generate-image flow at 390x844 / 768x1024 / 1440x900 with console/network cleanliness
- [ ] Define security, ownership, CSRF, rate-limit, and accessibility requirements

## Scope and Out of Scope

### In Scope

1. **Provider abstraction**: `App\Contracts\ImageGenerationProvider` interface + `App\Services\ImageResult` DTO + `App\Services\FakeImageGenerationProvider` bound as a container singleton. Determinism contract: identical inputs produce byte-identical output; no model download, no network, no subprocess (CI-safe per AGENTS.md §21).
2. **Storage keys**: `StorageKeyBuilder::imageGeneration(int $projectId): string` returning `projects/{project_id}/images/{uuid}.png`; `$projectId < 1` throws `InvalidArgumentException` (existing pattern).
3. **Data model**: `images` migration with the specified columns and indexes; `Image` model with casts, relations, and status constants; `ImageStatus` backed enum (`pending`, `generated`, `failed`); `ImageFactory` with `pending` (default), `generated`, and `failed` states.
4. **Queue job**: `GenerateImage` (queue-only execution, ownership re-validation, non-pending idempotency, strict Laravel-side validation of the provider result, real storage artifact write, Laravel-owned persistence, `generated`/`failed` marking).
5. **Generate endpoint**: `POST /api/v1/images/generate` with `project_id`, `prompt` (1–1000 chars), optional `style_preset`, optional `width`/`height` pair; validates, resolves an owned project (else 404), creates the pending row, dispatches the job, and returns **202 Accepted** with the pending `ImageResource`. Enqueue only — never generates inline.
6. **List/show endpoints**: `GET /api/v1/images` (paginated, authenticated user's images only, newest first, optional owned `project_id` filter, standard pagination envelope wrapped in a top-level `data` key); `GET /api/v1/images/{image}` (owner 200; non-owner/non-existent 404).
7. **Resource whitelist**: `ImageResource` exposes only `id`, `project_id`, `prompt`, `parameters`, `mime_type`, `size_bytes`, `width`, `height`, `status`, `created_at`, `updated_at`. `storage_disk`, `storage_key`, `user_id`, and bucket names never appear in any response.
8. **Frontend**: `ImageStudio` page (prompt textarea with 1000-char counter, style preset select, dimension preset select, generate button disabled while loading, metadata gallery with status badges, loading/empty/error states, 44px touch targets, visible focus, keyboard navigable, `aria-live` announcements, responsive single column < 768px) + `useGenerateImage`/`useImages` React Query hooks + "Image Studio" tab in `ProjectMediaSection`.
9. **Backend tests**: Pest unit (provider determinism + style presets; storage key format/validation/uniqueness; model/factory integrity) and feature (generate 202/pending/dispatch, ownership 404s, prompt/preset/dimension 422s, guest 401, CSRF 419, rate limit 429, list pagination/filter/isolation, show ownership, job ownership/idempotency/failure marking).
10. **Frontend/E2E tests**: Vitest component suites for the `image-studio` feature; Playwright E2E generate-image flow at the three required viewports with console/network cleanliness (`QUEUE_CONNECTION=sync` makes generation observable in e2e).

### Out of Scope

- Real image generation provider (Flux/SDXL or any model-backed worker stage) — future slice
- Heavy ML inference in HTTP request processes (forbidden by AGENTS.md §20; never implemented)
- Social publishing (M9–M12)
- Image editing, cropping, regeneration, and versioning
- Batch generation
- Presigned download URLs or any image byte delivery endpoint (the gallery is metadata + status only)
- WebSockets (polling is the accepted status mechanism)
- Image deletion endpoints/UI
- A style-presets API endpoint (the frontend mirrors the fixed catalog as constants)
- Test-seeding routes (the generate endpoint itself produces E2E fixtures through the real API)

## Authentication, Ownership, and Request Ordering

- Existing `auth:sanctum` SPA cookie/session authentication. State-changing requests retain CSRF validation using the CSRF-cookie handshake and `X-XSRF-TOKEN`. No bearer-token flow or credentials in localStorage.
- Ownership is server-controlled: `session user → project → image`. The generate endpoint resolves an owned project before creating the image row; list/show are user-scoped. Non-owned and non-existent resources both return 404 (no ownership information leak).
- Missing/expired authentication returns JSON 401. Missing/mismatched CSRF on mutations returns 419 without mutation.
- On state-changing requests, CSRF validation runs before authentication: a write missing both session and CSRF token fails CSRF first and returns 419; a write with a valid CSRF token but no authenticated session reaches the auth guard and returns 401.
- Do not mass-assign client data. Requests accept only `project_id`, `prompt`, `style_preset`, `width`, `height`; client-supplied IDs cannot influence ownership.
- Apply `image-generate` rate limiter at 20 requests per minute per authenticated user, shared across projects. `image-list` and `image-show` run at 60/min (existing per-endpoint convention).

## Data Model (images)

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigIncrements | PK |
| `user_id` | unsignedBigInteger | FK `users` cascade delete; not null |
| `project_id` | unsignedBigInteger | FK `projects` cascade delete; not null |
| `prompt` | text | not null; 1–1000 chars enforced at the API boundary |
| `parameters` | json | nullable; server-authored request snapshot |
| `storage_disk` | string | not null |
| `storage_key` | string | not null; **unique** |
| `mime_type` | string | not null; default `image/png` |
| `size_bytes` | unsignedBigInteger | not null; default 0 |
| `width` | unsignedInteger | not null |
| `height` | unsignedInteger | not null |
| `status` | string | not null; default `pending`; values `pending`/`generated`/`failed` |
| `created_at`, `updated_at` | timestamps | not null |

Indexes: unique on `storage_key`; index on `[user_id, status]`; index on `project_id`.

`parameters` JSON content (authored by the API layer at creation):

```json
{ "style_preset": "cinematic", "width": 1080, "height": 1920 }
```

- `App\Enums\ImageStatus`: backed string enum `PENDING = 'pending'`, `GENERATED = 'generated'`, `FAILED = 'failed'`; the `Image` model exposes `STATUS_*` constants mirroring the values (existing `DerivedAsset`/`ClipReview` convention).
- `Image` model: casts `parameters` → array, `status` → `ImageStatus`; `project()` and `user()` `belongsTo` relations.
- `ImageFactory`: default state `pending`; `generated` and `failed` states set status accordingly; `storage_disk = config('media.disk')`; `storage_key` via `StorageKeyBuilder::imageGeneration(projectId)` so every factory row has a unique key.

## Provider Abstraction Contract

AGENTS.md §21 requires provider abstractions so application logic never couples to individual models, and CI must use deterministic fakes instead of downloading multi-gigabyte models.

Interface `App\Contracts\ImageGenerationProvider`:

```php
interface ImageGenerationProvider
{
    /**
     * Fixed style preset catalog.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function getStylePresets(): array;

    /**
     * Generate an image artifact for the given request.
     */
    public function generate(string $prompt, string $stylePreset, int $width, int $height): ImageResult;
}
```

Result DTO `App\Services\ImageResult`:

```php
final readonly class ImageResult
{
    public function __construct(
        public string $data,      // raw image bytes
        public string $mimeType, // 'image/png'
        public int $width,
        public int $height,
    ) {}

    public function sizeBytes(): int // strlen($this->data)
}
```

**Style preset catalog (fixed slice constant):**

| id | label |
|----|-------|
| `photorealistic` | Photorealistic (default) |
| `illustration` | Illustration |
| `cinematic` | Cinematic |
| `digital_art` | Digital Art |

**Dimension presets (application-level, validated in Laravel):**

| width | height | Label |
|-------|--------|-------|
| 1024 | 1024 | Square (default) |
| 1080 | 1920 | Vertical 9:16 |
| 1920 | 1080 | Landscape 16:9 |

`FakeImageGenerationProvider` (`App\Services`):

- The slice's bound production implementation (container singleton in `AppServiceProvider`): `ImageGenerationProvider` → `FakeImageGenerationProvider`.
- Deterministic and model-free: no network access, no subprocess, no model download — CI-safe (§21). No new composer packages; PNG synthesis is pure PHP (zlib).
- **Not a metadata-only stub**: `generate` synthesizes a real, decodable PNG artifact (8-byte signature, IHDR with the exact requested dimensions, IDAT, IEND), with pixel bytes derived deterministically from a hash of `(prompt, style_preset, width, height)`.
- Determinism contract: identical inputs → byte-identical output; any differing input (prompt, style, or dimensions) → differing output bytes.
- `getStylePresets()` returns the fixed catalog above.

Note on AGENTS.md §24: the fake provider is a real functional implementation of the provider contract — it produces valid artifacts and durable storage objects, not a hardcoded fake success or empty implementation. The model-backed provider (Flux/SDXL via the Python worker CLI) is an explicitly authorized future slice; application-domain behavior (job, API, UI) depends only on the interface.

## Queue Job: GenerateImage

Job signature (follows `RenderMediaClip` topology and conventions):

```php
class GenerateImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public int $imageId,
        protected ?ImageGenerationProvider $provider = null,
    ) {}

    public function handle(): void { ... }

    public function uniqueId(): string // "generate-image:{imageId}"
}
```

Behavior:

1. Load the `Image`; a missing row throws `InvalidInputException('invalid_input')` (`RenderMediaClip` convention).
2. Re-validate ownership: the image's project must exist and `project.user_id === image.user_id`; on mismatch, log a warning and return (no state change, no storage write, no provider call).
3. Idempotency: if `status !== pending`, return early (no provider call, no storage write, no state change).
4. Resolve the provider from the container (`$this->provider ?? app(ImageGenerationProvider::class)`); the optional constructor injection mirrors `RenderMediaClip`'s testability pattern.
5. Call `generate(prompt, style_preset, width, height)` from the persisted row (`prompt`/`width`/`height` columns; `style_preset` from `parameters`).
6. Strict Laravel-side validation of the `ImageResult` before any persistence: `data` non-empty; PNG signature (`\x89PNG\r\n\x1a\n`) for `image/png`; `width`/`height` positive integers equal to the requested values; `sizeBytes() > 0`. A validation failure or provider exception marks the image `failed` (no storage object written) and logs a sanitized reason — no provider internals or raw prompt content in log entries.
7. Write the artifact: `Storage::disk($image->storage_disk)->put($image->storage_key, $result->data)`. An unexpected storage exception propagates for queue retry (the row remains pending — no false success; `tries = 3`, `backoff = 5`).
8. Persist within a database transaction: `status = generated`, `mime_type`, `size_bytes`, `width`, `height` from the validated result. Laravel owns PostgreSQL persistence; the provider never writes rows.
9. Topology: generation executes only inside the queue job. Production uses `QUEUE_CONNECTION=database`; testing/e2e run `QUEUE_CONNECTION=sync` so the transition is observable (the environment configuration established by Issue #110).

## Public API

| Endpoint | Method | Success | Description |
|----------|--------|---------|-------------|
| `/api/v1/images/generate` | POST | 202 `{"data": {...}}` | Validate + ownership + create pending `Image` + enqueue `GenerateImage` (enqueue only — never generates inline) |
| `/api/v1/images` | GET | 200 `{"data": {data, links, meta}}` | Paginated list of the authenticated user's images, newest first; optional owned `project_id` filter |
| `/api/v1/images/{image}` | GET | 200 `{"data": {...}}` | Single image, owner only |

### Generate Endpoint Contract

Request body:

```json
{
  "project_id": 12,
  "prompt": "A neon-lit city skyline at dusk",
  "style_preset": "cinematic",
  "width": 1080,
  "height": 1920
}
```

Validation rules:

- `project_id`: required, integer (existence + ownership resolved in the controller → 404 when non-owned/non-existent).
- `prompt`: required, string, min 1, max 1000.
- `style_preset`: optional, string, one of the fixed catalog ids; defaults to `photorealistic`.
- `width`/`height`: optional integers; both must be absent or both present, and the pair must be one of `(1024,1024)`, `(1080,1920)`, `(1920,1080)`; defaults to `1024x1024`. Single-sided or non-allowed pairs return 422.

Controller behavior (ordered):

1. CSRF middleware → 2. `auth:sanctum` → 3. validation → 4. resolve the project owned by the authenticated user (else 404) → 5. create the pending row: `user_id` (auth user), `project_id`, `prompt`, `parameters = {style_preset, width, height}`, `storage_disk = config('media.disk')`, `storage_key = StorageKeyBuilder::imageGeneration(project_id)`, `mime_type = 'image/png'`, `size_bytes = 0`, `width`/`height` as requested, `status = pending` → 6. `GenerateImage::dispatch($image->id)` → 7. return **202 Accepted** with the `ImageResource` serialized from the created pending row — **no refresh after dispatch**, so the response always reflects the enqueue-time pending state; terminal status arrives via subsequent GETs (polling).

Responses and errors:

| Case | Code |
|------|------|
| Valid request | 202 + `ImageResource` with `status = "pending"` |
| `prompt` missing/empty/over 1000 chars/non-string | 422 |
| `style_preset` not in the catalog | 422 |
| `width`/`height` single-sided or non-allowed pair | 422 |
| Project non-existent or non-owned | 404 |
| Guest (valid CSRF, no session) | 401 |
| Missing/mismatched CSRF | 419 (before auth) |
| More than 20 generate requests/min | 429 with `Retry-After` |

### List Endpoint Contract

- Query parameters: `page`, `per_page` (default 15, max 100), optional `project_id` (must exist and belong to the authenticated user, else 404).
- Scope: `images.user_id = authenticated user`; newest first (`id` desc).
- Envelope: top-level `data` wrapping the standard Laravel pagination object (`data`/`links`/`meta`) — the `ClipReviewController::index` convention.

### Show Endpoint Contract

- Route-model binding `{image}`; `$image->user_id === auth user id` else 404; non-existent 404.

### ImageResource Field Whitelist

Exposed: `id`, `project_id`, `prompt`, `parameters`, `mime_type`, `size_bytes`, `width`, `height`, `status`, `created_at`, `updated_at`.

Never exposed in any image response: `storage_disk`, `storage_key`, `user_id`, bucket names, provider internals. `status` serializes as its string value.

## UX and Accessibility

### ImageStudio Page

- Header: "Image Studio" with a one-line description.
- `GenerateImageForm`: labeled prompt textarea (`maxLength` 1000 with a live `N/1000` counter), style preset select (fixed catalog, default Photorealistic), dimension preset select (Square / Vertical 9:16 / Landscape 16:9, default Square), and a Generate button. The button is disabled when the prompt is empty/whitespace-only or a generation is in flight; a spinner shows while loading; Enter submits.
- Submit calls the `useGenerateImage` mutation with `{projectId, prompt, stylePreset, width, height}`. Client-side validation errors and server 422/429 errors are announced via `aria-live="polite"` as readable messages.
- `ImageGallery`: entries show a prompt excerpt, dimensions, file size, and a status badge — Pending (gray with an in-progress hint), Generated (green), Failed (red). Empty state ("No images yet"), loading state, and load-error state with retry are all rendered.
- Pending observability: the 202 response's pending resource is merged into the gallery immediately (a local pending entry), so the request is visible at once; polling replaces it with server truth and flips the badge when generation completes.
- Polling: `useImages` refetches every 2 seconds while any displayed image is `pending` (server list or local pending entry); stops otherwise (WebSockets out of scope).
- All controls: ≥ 44px touch targets, visible focus indicators, keyboard navigable, programmatically labeled (`label`/`for` or `aria-label`), 4.5:1 text contrast.

### Tab Integration

`ProjectMediaSection` gains an "Image Studio" tab following the existing M7 Slice 1 tab pattern (`role="tablist"`/`role="tab"`/`role="tabpanel"`, `aria-selected`, ids `image-studio-tab`/`image-studio-panel`), rendering `ImageStudio` with the active project id. No client-side router library; no new browser routes; no changes to the AuthenticatedShell topology (`App → AuthenticatedShell → ProjectMediaSection → tab`).

## Responsive Viewports

Test at: **390x844** (mobile), **768x1024** (tablet), **1440x900** (desktop). Single column < 768px (form above gallery); form and gallery side-by-side ≥ 1024px; no horizontal overflow; controls remain ≥ 44px at all viewports.

## Acceptance Criteria

- [ ] **AC-01**: The provider abstraction exists: `ImageGenerationProvider` interface + `ImageResult` DTO + deterministic `FakeImageGenerationProvider` bound in the container; `getStylePresets()` returns the fixed four-preset catalog; identical inputs produce byte-identical valid PNGs; no model download, network, or subprocess.
- [ ] **AC-02**: `StorageKeyBuilder::imageGeneration($projectId)` returns `projects/{project_id}/images/{uuid}.png`; `$projectId < 1` throws `InvalidArgumentException`; successive calls produce unique keys.
- [ ] **AC-03**: The `images` migration, `Image` model, `ImageStatus` enum, and `ImageFactory` exist with the specified columns, casts, indexes, and states; `status` defaults to `pending`; `storage_key` is unique.
- [ ] **AC-04**: `GenerateImage` executes generation only on the queue; re-validates ownership (no state change on mismatch); is idempotent on non-pending status; strictly validates the provider result before persistence; marks `generated` (with a real stored artifact and persisted metadata) or `failed`; unexpected storage failures leave the row pending for retry; heavy work never runs in HTTP request processes.
- [ ] **AC-05**: `POST /api/v1/images/generate` validates, resolves an owned project, enqueues only, and returns 202 with a pending `ImageResource`; prompt/preset/dimension violations return 422; non-owned/non-existent project returns 404; guest returns 401; missing CSRF returns 419; the 21st request/min returns 429.
- [ ] **AC-06**: `GET /api/v1/images` returns only the authenticated user's images, newest first, in the standard pagination envelope, with the optional owned `project_id` filter (404 otherwise); other users' images are never visible.
- [ ] **AC-07**: `GET /api/v1/images/{image}` returns 200 with the resource for the owner and 404 for non-owner/non-existent images.
- [ ] **AC-08**: `ImageResource` and every image response expose only the whitelisted fields; `storage_disk`, `storage_key`, `user_id`, and bucket names never appear in responses, logs, or the browser.
- [ ] **AC-09**: `ImageStudio` renders the prompt form (counter, style/dimension presets, disabled-while-loading generate button), the metadata gallery with status badges, loading/empty/error states; 44px touch targets, visible focus, keyboard navigability, and `aria-live` announcements; responsive single column < 768px and no overflow at any required viewport.
- [ ] **AC-10**: `useGenerateImage`/`useImages` follow React Query conventions (invalidation, pending-aware 2s polling); the pending 202 resource is immediately visible in the gallery; the "Image Studio" tab is wired into `ProjectMediaSection` per M7 Slice 1 conventions with no client-side router library.
- [ ] **AC-11**: Pest unit + feature suites and Vitest component suites pass with no skipped tests.
- [ ] **AC-12**: Playwright E2E generate-image flow passes at 390x844, 768x1024, and 1440x900 with console/network cleanliness; lint, build, type check, and governance pass.

## Known Limitations and Coverage Boundary

1. **The fake provider is the only bound implementation in this slice.** It produces real PNG artifacts deterministically; the model-backed provider (Flux/SDXL via the Python worker CLI subprocess through `ProcessMediaAction`) is an explicitly deferred future slice. Application-domain behavior (job, API, UI) depends only on the interface, so the future provider swap requires no domain changes.
2. **No image byte delivery.** There is no public, download, or presigned URL in this slice; the gallery renders metadata + status only. This also keeps the e2e environment free of storage fetches (no MinIO needed; `FILESYSTEM_DISK=local` suffices).
3. **Queue observability.** `QUEUE_CONNECTION=sync` is a testing/e2e configuration (established by Issue #110); production remains `QUEUE_CONNECTION=database`, so generation never executes in HTTP request processes.
4. **Polling only.** The 2-second pending-aware poll is the only status mechanism; WebSockets are out of scope.
5. **Queue attempt exhaustion.** If all queue attempts are exhausted after unexpected storage failures, the row remains `pending` (no false success); regeneration is out of scope (future slice).
6. **E2E fixtures need no seeding route.** The generate endpoint itself creates real fixture rows through the real API with the deterministic provider, so no test-seeding route is introduced for this slice.

## Security Considerations

- **Scoped access**: `user → project → image` verified on every operation; non-owned and non-existent resources both return 404 (no ownership leak).
- **CSRF before auth on mutations** (419 before 401); no bearer tokens; no credentials in browser storage.
- **No mass assignment**: request classes whitelist the five input fields only.
- **No secrets/internal fields**: `storage_disk`, `storage_key`, `user_id`, bucket names, and provider internals never appear in API responses, logs, screenshots, or the browser bundle.
- **Untrusted prompt content**: the server enforces 1–1000 characters; prompts are stored as text and rendered escaped (React default); the `parameters` JSON is a server-authored snapshot, never echoed client JSON.
- **Queue job authorization**: the job payload carries only the image id; the job re-validates ownership from persisted rows and never trusts the payload.
- **Rate limiting**: `image-generate` at 20/min per user (generation cost control), `image-list`/`image-show` at 60/min.
- **Provider boundary**: the fake provider is deterministic and side-effect-free; the container binding is the single seam where the future model-backed provider replaces it without domain changes.

## Dependencies

- **M7 complete** (Issues #106/#110): Sanctum SPA auth, projects, media storage conventions, queue topology, `ProjectMediaSection` tab shell, root React Query provider, Playwright harness at three viewports.
- **Laravel 13, PHP 8.3, PostgreSQL 16**: existing stack; existing queue, validation, and filesystem abstractions; no new composer packages (pure-PHP PNG synthesis via zlib).
- **React 19, TypeScript, Vite 8, Vitest, React Testing Library, Playwright**: existing frontend stack; no new npm packages.
- **Testing environment**: `QUEUE_CONNECTION=sync` (phpunit already sets this; the e2e server must run it per the Issue #110 requirement) so generation is observable in tests and e2e.

## Human-Gated Operations

- None specific to this slice beyond the standard issue lifecycle. `tests/governance/**` and `scripts/merge_gate.py` remain denied to all agents.

---

SPEC_READY
