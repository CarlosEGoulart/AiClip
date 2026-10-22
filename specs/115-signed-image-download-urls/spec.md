# Specification: Signed Image Download URLs and Gallery Previews — Issue #115

## Authority and Description

Active issue: [Issue #115](https://github.com/CarlosEGoulart/AiClip/issues/115).
Branch: `@carlosegoulart/115/feat/image-download-urls`.

M0–M7 are complete. M8 Slice 1 (Issue #113 / PR #114) delivered the AI Image
Studio foundation: the `ImageGenerationProvider` seam with the deterministic
`FakeImageGenerationProvider`, the `GenerateImage` queue job, the
generate/list/show API triad with an 11-field `ImageResource` whitelist, and the
React `ImageStudio` gallery. The gallery is metadata + status only — no endpoint
delivers image bytes, so a user can generate an image but never see it (an
explicit Out of Scope item of `specs/113-ai-image-studio-foundation/spec.md`).

This slice (M8 Slice 2) closes that gap with one cohesive increment:

1. A dual-gate time-limited signed download endpoint
   `GET /api/v1/images/{image}/download` (named `image.download`) mirroring the
   proven M7 precedent `ClipReviewController::video` / `clip-review.video`
   (`specs/106-clip-review-foundation/`, `specs/110-.../`): the API route 302
   redirects to a `temporaryUrl` for the image's storage key — presigned S3/MinIO
   in production, the framework's first-party signed local serve route in the
   e2e environment (`MEDIA_DISK=local`, `local` disk `serve => true`).
2. A `download_url` field on `ImageResource` (11 → 12 whitelisted fields): a
   1-hour signed API URL for `generated` images, `null` otherwise.
3. Real image previews plus a "Download image" link in the `ImageGallery`,
   with an error fallback, responsive constraints, 44px touch targets, and
   visible focus.

No provider, queue job, model, migration, or dependency changes. Heavy work
never enters HTTP request processes (AGENTS.md §20); byte delivery is a
read-only redirect, not inference (AGENTS.md §21 is untouched: CI still uses the
deterministic fake provider and downloads no models).

Both Phase 1 escalations were resolved by the human and are **ratified scope**:
(1) the TC-E2E-03 cleanliness predicate refinement (AC-07) is accepted as an
intent-preserving environment-hygiene amendment — external storage tokens remain
fatal unconditionally, only a `storage` path on the e2e API origin is exempted;
(2) increment ordering is accepted — the real model-backed provider is Slice 3.

Read this specification with `plan.md` and `test-plan.md` in this directory. The
active issue and the ratified escalations define this increment.
`specs/113-ai-image-studio-foundation/` and `specs/106-clip-review-foundation/`
provide precedent and context, not authorization for their broader workflows.

## Technical Tasks

- [ ] Add the `image.download` named route `GET /api/v1/images/{image}/download`
      outside the `auth:sanctum` group (the `clip-review.video` dual-gate
      topology), with a controller `download` action (no throttle middleware,
      mirroring `clip-review.video`)
- [ ] Implement the dual gate: valid signature → proceed; invalid/expired/
      tampered signature → 403 before any ownership or storage information;
      unsigned request → session ownership check (guest 401, non-owner 404,
      non-existent 404)
- [ ] Guard on artifact existence: `status !== generated` (pending/failed) → 404;
      generated → `temporaryUrl(storage_key, +1h)` → `redirect()->away()` (302);
      storage exception → sanitized log (image id only, no prompt content) → 500
- [ ] Extend `ImageResource` with `download_url`: 1-hour
      `URL::temporarySignedRoute('image.download', ...)` for `generated` images,
      `null` otherwise; update the whitelist documentation from 11 to 12 fields;
      `storage_disk`, `storage_key`, `user_id`, bucket names remain never exposed
- [ ] Authorize and apply the 11 → 12 whitelist constant updates in the three
      existing backend tests (`WHITELISTED_FIELDS`, `SHOW_WHITELISTED_FIELDS`,
      `LIST_WHITELISTED_FIELDS`) and the e2e `IMAGE_FIELD_WHITELIST` — an
      additive field, explicitly authorized, not a test weakening
- [ ] Extend `ImageItem` (frontend types) with required
      `download_url: string | null`; update the two `makeImage` fixture builders
- [ ] Render a real `<img>` preview in `ImageGallery` for generated images with a
      non-null `download_url` (exact alt text contract, `loading="lazy"`,
      width/height attributes from the image dimensions) plus a "Download image"
      anchor (`target="_blank"`, `rel="noopener noreferrer"`)
- [ ] Add a preview `onError` fallback state ("Preview unavailable" with metadata
      retained, download link kept) for expired/failed loads; no auto-refetch
      loop; preview remounts on a refreshed `download_url` (keyed by it)
- [ ] Add `index.css` rules for `.image-gallery-card-preview`,
      `.image-gallery-preview-fallback`, and `.image-gallery-download`
      (+ `:focus-visible`): per-rule `box-sizing: border-box` (project
      convention), no horizontal overflow at any required viewport, ≥44px touch
      target, visible focus indicator
- [ ] Backend Pest feature tests: `download_url` presence/absence by status on
      generate/show/list; 302 issuance via owner signature and owner session
      paths; 403 expired; 403 tampered; 404 non-owner; 404 non-existent; 401
      guest unsigned; 404 pending/failed download; storage-failure 500; 12-field
      whitelist on every image response
- [ ] Backend `real-integration` group test (MinIO, CI backend job): write real
      PNG bytes, follow `download_url` semantics → 302 to a MinIO-hosted
      presigned URL → GET returns the exact stored bytes (mirror
      `ClipReviewRealIntegrationTest`)
- [ ] Vitest component tests for the gallery preview, download link, null-url
      and pending/failed cases, and the `onError` fallback; all existing image
      studio suites stay green
- [ ] Extend Playwright `e2e/image-studio.spec.ts`: after the generate flow the
      generated card renders a loaded preview (`naturalWidth > 0` — the chain
      proof for API 302 → signed local serve route → 200 PNG bytes), the
      download link href carries `signature`/`expires`; update
      `IMAGE_FIELD_WHITELIST` to 12
      fields; add the download link to `IMAGE_STUDIO_FOCUS_TARGETS` (length
      assertion 6, all `=== true`, at all three viewports), to the touch-target
      map, and to the clipping list; refine the TC-E2E-03 storage-request
      predicate per AC-07
- [ ] Update the stale "no byte delivery" comments in
      `apps/web/e2e/image-studio.spec.ts` and `ImageGallery.tsx`
- [ ] Verify zero regression: all existing Pest, Vitest, Playwright, lint,
      build, Pint, and governance checks stay green

## Scope and Out of Scope

### In Scope

1. **Download endpoint**: `ImageController::download` with the dual gate, the
   artifact guard, and the storage redirect described below.
2. **Resource field**: `ImageResource::downloadUrl()`; the 12-field whitelist.
3. **Frontend**: `ImageItem.download_url`, `ImagePreview` behavior inside
   `ImageGallery`, the three CSS rules, focus/touch/overflow integration.
4. **E2E**: TC-E2E-01/02/03 extensions and the precisely specified cleanliness
   predicate refinement.
5. **Tests**: Pest feature (new `ImageDownloadTest.php`), Pest
   `real-integration` (new `ImageDownloadRealIntegrationTest.php`), extended
   Vitest suites, extended Playwright spec.
6. **Comment consistency**: remove the now-false "no byte delivery" claims in
   the two files listed above (comments only).

### Out of Scope (explicitly deferred)

- Real model-backed image generation provider (Flux/SDXL, worker CLI stage) —
  **deferred to M8 Slice 3** (ratified ordering)
- Image deletion, batch generation, image editing/cropping/regeneration/versioning
- Thumbnails or any derived miniaturization (the preview is the full artifact)
- Streaming image bytes through Laravel in production (redirect-only; the
  framework local-disk serve route is used solely where the disk is `local`,
  i.e. the e2e environment)
- WebSockets; configurable expiry TTL (1 hour is the fixed M7-mirroring constant)
- A style-presets endpoint; test-seeding routes; any rate limiter on the
  download route (mirrors `clip-review.video`, which has none)
- Provider/queue/job/migration/model changes; dependency additions
- `docs/roadmap.md` / `docs/project-state.md` / `docs/prd.md` edits (the
  established post-merge docs practice; this spec bundle is the issue's
  documentation)
- Any edit to `specs/113-ai-image-studio-foundation/**` (history) ,
  `tests/governance/**`, `.opencode/**`, `scripts/merge_gate.py`,
  `playwright.config.ts`, CI workflows, or other e2e specs' assertions

## Backend Contract: Download Endpoint

Route registration (`apps/api/routes/api.php`), mirroring `clip-review.video`
(lines 77–81) — inside the `Route::prefix('v1')` group, **outside** the
`auth:sanctum` group (required: inside the group, unauthenticated
expired/tampered-signature requests would get 401 before the signature check,
breaking the 403 contract — the M7 Issue #106 lesson):

```php
Route::get('/images/{image}/download', [ImageController::class, 'download'])
    ->name('image.download');
```

Full URI: `api/v1/images/{image}/download`. No `throttle:` middleware (the
`clip-review.video` precedent; signed URLs are time-limited, server-minted
tokens and issuance happens on the already-throttled list/show endpoints at
60/min).

Controller behavior, in order (`ImageController::download`, return type
`Illuminate\Http\RedirectResponse` — never a wider `Response` type; M7 Issue
#106 hit a TypeError from a mismatched return type):

1. **Dual gate.**
   - If `signature` is present:
     `abort_unless($request->hasValidSignature(), 403)` — invalid, expired, or
     tampered signature fails here, before any ownership, status, or storage
     work.
   - If absent: session ownership — no authenticated user → **401**;
     `image.user_id !== user id` → **404** (non-owner and non-existent are
     indistinguishable to unauthorized callers).
2. **Route-model binding** resolves first: a non-existent id → **404** before
   the controller runs (accepted M7 surface: a tampered signature on an existing
   id yields 403 while a non-existent id yields 404 — inherent to
   signature-before-ownership ordering; no ownership data is exposed).
3. **Artifact guard**: `$image->status !== ImageStatus::GENERATED` → **404**
   (pending and failed rows have no stored artifact). Applies to both gates.
4. **Redirect**: `Storage::disk($image->storage_disk)->temporaryUrl($image->storage_key, now()->addHour())`
   → `return redirect()->away($url)` → **302**. Two 1-hour layers: the API
   signed URL at issuance and the storage temporary URL at click time.
5. **Failure**: any storage exception → `Log::error` with `image_id` and the
   exception message only (no prompt content, no credentials, no bucket config)
   → **500** (M7 fail-closed behavior).

Environment behavior of step 4:

| Disk configuration | `temporaryUrl()` result |
|---|---|
| `media` (s3/MinIO, production + CI backend job) | Presigned S3 URL (local computation, no network at mint time) |
| `local` with `serve => true` (e2e, `playwright.config.ts` sets `MEDIA_DISK: 'local'`) | Absolute signed first-party URL `http://127.0.0.1:8000/storage/{path}?expires&signature`, served by the framework route `GET /storage/{path}` (name `storage.{disk}`, signature validated by `ServeFile`) |
| `local` without `serve`, or `media`/s3 unreachable in ways that throw | RuntimeException → 500 (fail closed) |
| `Storage::fake(...)` in Pest | `buildTemporaryUrlsUsing` callback → 302 (vendor behavior, proven by `ClipReviewVideoUrlTest`) |

## Data/API Delta: ImageResource (11 → 12 fields)

`ImageResource::toArray` gains exactly one key:

```php
'download_url' => $this->downloadUrl(),
```

```php
private function downloadUrl(): ?string
{
    $status = $this->status instanceof ImageStatus ? $this->status->value : $this->status;
    if ($status !== ImageStatus::GENERATED->value) {
        return null;
    }
    return URL::temporarySignedRoute('image.download', now()->addHour(), ['image' => $this->id]);
}
```

- Present with a value on **every** image response (list, show, and the 202
  generate body): `string` for `generated`, `null` for `pending`/`failed`.
- The URL embeds the route **URI path** `images/{id}/download` plus `signature`
  and `expires` query parameters. Laravel signed URLs never embed the
  dot-separated route name — assertions must target URI path + `signature` +
  `expires`, never `image.download` (M7 Issue #106 ratified lesson).
- The ratified 12-field sorted whitelist (e2e `IMAGE_FIELD_WHITELIST` and the
  three backend constants): `created_at`, `download_url`, `height`, `id`,
  `mime_type`, `parameters`, `project_id`, `prompt`, `size_bytes`, `status`,
  `updated_at`, `width`.
- Never exposed, unchanged: `storage_disk`, `storage_key`, `user_id`, bucket
  names, provider internals. The API-level URL contains only the image id; the
  storage key appears only in the time-limited 302 `Location` target (the M7
  accepted presigned-URL precedent).

**Authorized test amendment:** the exact key-set assertions in
`GenerateImageEndpointTest` (`WHITELISTED_FIELDS`), `ImageShowTest`
(`SHOW_WHITELISTED_FIELDS`), `ImageListTest` (`LIST_WHITELISTED_FIELDS`), and
the e2e `IMAGE_FIELD_WHITELIST` gain `download_url`. This is an additive,
spec-authorized field change — not a weakening, not a relaxation, not a subset.

## Frontend Contract

### Types

`ImageItem` in `apps/web/src/features/image-studio/types.ts` gains a required
`download_url: string | null` (the API always sends the key). The two fixture
builders (`ImageGallery.test.tsx`, `ImageStudio.test.tsx` — `makeImage`) add
`download_url: null` unless overridden. The 202 pending resource supplies
`download_url: null`, so local pending entries type-check unchanged.

### ImageGallery

For each entry where `status === 'generated'` **and** `download_url` is a
non-null string, render inside the card (after the meta row):

```tsx
<img
  className="image-gallery-card-preview"
  src={image.download_url}
  alt={`Generated image: ${excerpt(image.prompt)}`}
  width={image.width}
  height={image.height}
  loading="lazy"
  onError={...}
/>
<a
  className="image-gallery-download"
  href={image.download_url}
  target="_blank"
  rel="noopener noreferrer"
>Download image</a>
```

- **Alt text contract (exact):** `Generated image: ` + the existing 60-char
  prompt excerpt (`excerpt()` already in the file).
- Preview is held by a small internal component (e.g. `ImagePreview`) with
  `useState` error; on `onError` render
  `<p className="image-gallery-preview-fallback">Preview unavailable</p>`
  **instead of the img**; the prompt, badge, meta, and the download link remain
  rendered. No automatic refetch loop.
- The preview element is keyed by `` `${image.id}-${image.download_url}` `` so a
  refreshed signed URL (any later refetch mints a fresh 1-hour signature)
  remounts the preview and retries the load.
- `pending` and `failed` entries, and `generated` with `download_url === null`,
  render **no img and no download link** — the current card content only.
- DOM order inside the card: head (prompt + badge) → meta → hint (pending) →
  preview/fallback → download link. The download link is the last focusable
  inside the gallery panel, i.e. the next stop after the Generate button.

### CSS (`apps/web/src/index.css`, inside the existing image-studio block)

- `.image-gallery-card-preview`: `display: block; width: 100%; max-width: 100%;
  height: auto; box-sizing: border-box; object-fit: contain;` plus a subtle
  background/border-radius consistent with the card styling. The intrinsic
  `width`/`height` attributes preserve the aspect ratio; the preview must never
  widen its card (no horizontal overflow at 390/768/1440 — the M8 Slice 1
  AC-13 lesson; per-rule `box-sizing`, no global reset).
- `.image-gallery-preview-fallback`: readable muted text; ≥4.5:1 contrast.
- `.image-gallery-download`: ≥44px min-height, inline-flex centering, padding,
  link color meeting 4.5:1 contrast, and a `:focus-visible` indicator matching
  the established box-shadow/outline patterns (e.g.
  `.image-studio-submit-button:focus-visible`).
- No changes to any other rule; no global resets; no new stylesheet.

## E2E Contract (`apps/web/e2e/image-studio.spec.ts`)

Environment (unchanged): Playwright webServer runs Laravel with `APP_ENV=
testing`, `QUEUE_CONNECTION=sync`, `MEDIA_DISK=local`; the `local` disk has
`serve => true`, so the whole chain
`download_url` (`http://127.0.0.1:8000/api/v1/images/{id}/download?...`) → 302 →
`http://127.0.0.1:8000/storage/projects/...png?...` → 200 PNG bytes executes
inside the required CI path with **no MinIO, no GPU, no model downloads**.

Deltas versus `specs/113` (all additive or the ratified refinement — nothing
else in the file changes):

1. `IMAGE_FIELD_WHITELIST` → the 12 ratified values above.
2. `runGenerateFlow` additions after the existing Generated-badge assertions:
   - refetch body: the generated item's `download_url` is a string containing
     `images/{id}/download`, `signature`, and `expires`; the 202 body's
     `download_url` is `null` (assert both);
   - `getByRole('img', { name: /^Generated image:/ })` visible, then poll until
     `img.naturalWidth > 0` (bounded ≤10s) — this is the issue's chain proof:
     a decoded image can only exist if the browser completed the API 302 →
     signed local serve route → 200 PNG byte delivery (a broken chain yields
     `naturalWidth === 0` and/or an unexpected 4xx/5xx caught by the existing
     response collector);
   - `getByRole('link', { name: 'Download image' })` href matches
     `/\/api\/v1\/images\/\d+\/download/`, `/signature=/`, `/expires=/`.
3. **Focus walk**: `IMAGE_STUDIO_FOCUS_TARGETS` gains
   `{ key: 'download', selector: '.image-gallery-download' }` → 6 targets.
   The TC-E2E-02 walk runs after `runGenerateFlow`, so the link exists. The
   forward walk extends to 5 Tab presses (prompt → style → dimensions →
   generate → download) after the tab re-establishment presses; if the real DOM
   interposes an additional focusable, a bounded walk (cap 8 presses) recording
   only matched targets is acceptable. The assertions keep their full strength:
   `expect(Object.keys(focusVisible)).toHaveLength(IMAGE_STUDIO_FOCUS_TARGETS.length)`
   (= 6) and `focusVisible[key] === true` for **all six** targets, at all three
   viewports. No target may be removed, subsetted, or hardcoded (AGENTS.md §24).
4. **Touch targets**: the `touchTargets` map gains
   `'download': height('.image-gallery-download')` (≥44 asserted by the
   existing loop).
5. **Clipping**: the `clipped` elements list gains
   `document.querySelector('.image-gallery-card-preview')` (the existing
   `toBe 0` assertion covers it).
6. **Cleanliness predicate (AC-07 — the ratified refinement):**

   Current (Slice 1, the baseline the Tester diffs against):

   ```ts
   page.on('request', (request) => {
     const url = request.url();
     if (/storage|amazonaws|bucket|:9000/.test(url)) {
       cleanliness.storageRequests.push(`${request.method()} ${url}`);
     }
   });
   ```

   Authorized end state (normative):

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

   Rules:
   - The predicate keeps the **exact Slice 1 token** (`storage` tested against
     the full request URL) and adds **exactly one exemption**: host
     `127.0.0.1:8000`. For every request the suite can observe this is identical
     to AC-07's description ("a request whose path contains `/storage` ... unless
     its host is the e2e API origin") — the token is never narrowed, and the
     host exemption is the single ratified change.
   - `amazonaws`, `bucket`, and `:9000` remain fatal **unconditionally**, on
     every host including the API origin.
   - The `storage` token is exempt **only** when the request host is exactly
     `127.0.0.1:8000` (the e2e API origin where Laravel's first-party signed
     serve route delivers bytes). Any `storage`-matching request to any other
     host (including `127.0.0.1:5173`) remains fatal.
   - `expect(cleanliness.storageRequests).toEqual([])` in `expectClean` is
     **unchanged**.
   - The header comment block (lines 1–27) and the collector comment
     (lines 73–77) are updated to the new intent: byte delivery via the
     first-party signed serve route is expected; external storage backends are
     forbidden.
   - **Forbidden:** removing the `storage` token; exempting `storage` on any
     host other than `127.0.0.1:8000`; exempting `amazonaws`/`bucket`/`:9000`
     anywhere; deleting the `storageRequests` assertion or collector; touching
     the console, `pageerror`, 4xx/5xx, `localStorage`/`sessionStorage`
     collectors or the ratified anonymous `/api/v1/auth/me` 401 exception; any
     other change to `trackCleanliness`/`expectClean`.

## Authentication, Ownership, and Request Ordering

- The download route requires **no** CSRF (GET, no mutation) and no middleware
  auth — the dual gate is the authorization (mirrors `clip-review.video`).
- Signed path: possession of a server-minted, 1-hour, HMAC (APP_KEY) signature
  over the image id authorizes the redirect; signatures cannot be forged,
  transplanted across images, or extended client-side.
- Unsigned path: `session user → image` ownership, server-controlled;
  non-owner and non-existent both 404; guest 401.
- `download_url` is minted only on the already owner-scoped list/show/generate
  responses — a non-owner can never receive another user's signed URL.
- No mass assignment, no state change, no new tokens in browser storage.

## Security Considerations

- **Dual gate first**: invalid/expired/tampered signatures are rejected (403)
  before any ownership, status, or storage information; unsigned requests fall
  back to session ownership (401/404). Proven pattern: `ClipReviewVideoUrlTest`.
- **Two 1-hour expiries**: API signature at issuance; storage `temporaryUrl` at
  click time. Expired artifacts degrade to the UI fallback until a refetch
  mints fresh URLs.
- **Artifact guard**: pending/failed rows 404 — no presigning for rows with no
  stored bytes.
- **No storage internals in API responses**: the 12-field whitelist is asserted
  by exact key set on generate (202), show, and every list item; the response
  bodies never contain `storage_disk`, `storage_key`, `user_id`, or bucket
  names. The storage key appears only inside the time-limited 302 `Location`
  (M7 accepted precedent — presigned URLs inherently contain the object key).
- **Sanitized 500 logging**: `image_id` + exception message only; never raw
  prompt content or credentials.
- **Accepted enumeration surface** (documented, inherited from M7): with a
  tampered signature an existing id yields 403 while a non-existent id yields
  404 via binding. No ownership information leaks; this is inherent to
  signature-before-ownership ordering and was accepted for `clip-review.video`.
- **No new rate limiter**: issuance rides the 60/min list/show limiters; the
  redirect route mirrors `clip-review.video` (unthrottled). No security
  weakening relative to the ratified M7 posture.
- **CI safety**: no model, GPU, or external network in the required path; the
  `real-integration` MinIO round trip runs only in the CI backend job (which
  already provisions MinIO); e2e byte delivery uses the framework local serve
  route.

## UX and Accessibility

- Generated cards show the actual image above the meta row, preserving the
  existing badge/prompt hierarchy; pending/failed cards are visually unchanged
  except they never render a preview.
- Alt text is programmatically meaningful (`Generated image: {prompt excerpt}`);
  the preview `<img>` is exposed with role `img`.
- The download link is keyboard reachable in DOM order after the Generate
  button, has a visible `:focus-visible` indicator, and a ≥44px touch target.
- Preview load failure shows a readable fallback instead of a broken-image
  glyph; metadata and the download link remain available.
- Layout: intrinsic dimensions + constrained CSS prevent layout shift and
  horizontal overflow; the desktop two-column layout, single column < 1024px,
  and all existing touch/focus checks remain unchanged (AC-05).
- Responsive viewports: **390x844, 768x1024, 1440x900** (unchanged).

## Acceptance Criteria

- [ ] **AC-01**: `GET /api/v1/images/{image}/download` exists (named
      `image.download`) with the dual gate: valid signature → 302 redirect to a
      `temporaryUrl` for the image's storage key (1-hour expiry); invalid,
      expired, or tampered signature → 403 before any ownership or storage
      information is exposed; unsigned → guest 401, non-owner 404, non-existent
      404.
- [ ] **AC-02**: Downloading a pending or failed image (owner, session path)
      returns 404; only `generated` images redirect. A storage exception returns
      500 with a sanitized log entry containing the image id and no prompt
      content or storage internals.
- [ ] **AC-03**: `ImageResource` (and therefore show and list responses)
      exposes exactly 12 whitelisted fields including `download_url`: a string
      containing `/api/v1/images/{id}/download`, `signature`, and `expires` for
      generated images, and `null` for pending/failed. `storage_disk`,
      `storage_key`, `user_id`, and bucket names never appear in any response,
      log, or the browser.
- [ ] **AC-04**: The gallery renders a real `<img>` preview (alt text from the
      prompt excerpt, correct intrinsic dimensions) and a keyboard-reachable
      "Download image" link (visible focus, ≥44px target) for generated images;
      no preview for pending/failed or null-url entries; a failed image load
      shows the fallback state without breaking the card.
- [ ] **AC-05**: Responsive integrity: no horizontal document overflow at
      390x844, 768x1024, 1440x900; the preview scales within its card
      (`scrollWidth <= innerWidth` at all viewports); layout, touch targets, and
      focus indicators of the existing form/gallery remain unchanged.
- [ ] **AC-06**: Playwright E2E (all three viewports) verifies end-to-end byte
      delivery in the required CI path: after generation, the preview `<img>`
      reports `naturalWidth > 0` (browser followed API 302 → signed local serve
      route → 200 PNG bytes), the download link href carries `signature` and
      `expires`, the e2e field whitelist assertion covers the 12 fields, and the
      focus walk reaches all six targets with `focusVisible === true`.
- [ ] **AC-07**: TC-E2E-03 cleanliness is intent-preservingly refined: requests
      matching `amazonaws`, `bucket`, or `:9000` remain fatal; a request whose
      path contains `/storage` remains fatal unless its host is the e2e API
      origin (`127.0.0.1:8000`, where the local disk's first-party signed serve
      route delivers bytes); console cleanliness, uncaught-exception cleanliness,
      4xx/5xx cleanliness (only the ratified anonymous `/api/v1/auth/me` 401 is
      exempt), and the empty browser-storage assertions are unchanged and still
      pass.
- [ ] **AC-08**: A `real-integration` group test proves the production topology
      against real MinIO (CI backend job): the signed API URL 302-redirects to
      a MinIO-hosted presigned URL whose HTTP GET returns the exact stored PNG
      bytes.
- [ ] **AC-09**: Pest backend suites pass with no skipped tests outside the
      group-gated real-integration cases; Vitest suites pass; lint, build, type
      check, Pint, and governance pass.
- [ ] **AC-10**: No regression: all previously passing suites and e2e scenarios
      (generate flow, focus walk, responsive checks, cleanliness) remain green;
      no provider, queue job, model, migration, or dependency changes.

## Known Limitations and Coverage Boundary

1. **Production byte delivery requires S3-compatible storage.** With the
   `media` disk (s3 driver, `throw => true`) and unreachable/absent MinIO,
   `temporaryUrl()`/presign paths throw → 500, fail closed (the M7 recorded
   limitation, unchanged). The real presigned round trip is verified in the
   `real-integration` group of the CI backend job; feature tests use
   `Storage::fake` (permitted outside real-integration).
2. **E2E serves bytes through the framework local route.** The e2e environment
   runs `MEDIA_DISK=local`; `ServeFile` validates the relative signature and
   streams the stored PNG with `Cache-Control: no-store`. No MinIO exists in
   e2e, and none is needed. This is the exact reason for the AC-07 predicate
   refinement — the Slice 1 predicate ("no byte delivery at all") must track the
   new intended behavior.
3. **E2E is CI-authoritative.** Per the ratified Issue #110/#113 precedent the
   local sandbox cannot run Playwright (no usable `.env`/`pdo_pgsql`);
   `npm run test:e2e` runs in the CI `e2e` job only. Local verification covers
   Pest, Vitest, lint, build, Pint, and Playwright `--list` discovery.
4. **Signed URLs expire during long-idle sessions.** A preview whose 1-hour
   signature lapses degrades to the fallback state until the gallery is
   refetched (which mints fresh URLs); no background refresh is in scope.
5. **Cross-origin `download` attribute.** Chromium ignores `download` for
   cross-origin URLs, so "Download image" opens the PNG in a new tab rather
   than forcing a save dialog. Accepted affordance; Tester reviews visually.
6. **No throttling on the redirect route** (M7 parity). Not a regression: the
   Slice 1 image endpoints' limiters are unchanged and issuance is bounded by
   them.
7. **Polling remains the only status mechanism**; WebSockets, deletion, batch,
   editing, thumbnails, and the model-backed provider are deferred as listed in
   Out of Scope.

## Dependencies

- **M8 Slice 1 complete** (Issue #113 / PR #114): `Image` model + factory,
  generate/list/show API, `ImageResource`, `ImageStudio` UI, e2e generate flow,
  the 11-field whitelist tests being amended.
- **M7 Slice 1/2 signed-URL precedent** (Issues #106/#110): dual-gate topology,
  `temporaryUrl` redirect pattern, `Storage::fake` temporary-URL callback,
  `real-integration` MinIO round-trip pattern, CI e2e venue matrix.
- Existing stack only: Laravel signed routes/URLs, Flysystem `temporaryUrl`,
  Pest, Vitest, React Testing Library, Playwright. **No new composer or npm
  dependencies.**
- Environment prerequisites (already configured): `playwright.config.ts` sets
  `APP_ENV=testing`, `QUEUE_CONNECTION=sync`, `MEDIA_DISK=local`; the `local`
  disk has `serve => true` (`config/filesystems.php:36`); the CI backend job
  provisions PostgreSQL + MinIO; `phpunit.xml` pins `QUEUE_CONNECTION=sync`.

## Human-Gated Operations

- None outstanding. Both Phase 1 escalations were resolved and ratified by the
  Orchestrator/human: (1) the AC-07 cleanliness predicate refinement; (2) Slice
  2 ordering (model-backed provider deferred to Slice 3).
- `tests/governance/**`, `.opencode/**`, and `scripts/merge_gate.py` remain
  denied to all agents. Issue/branch/PR lifecycle remains Orchestrator-owned.

---

SPEC_READY
