# Issue #157: YouTube URL Upload Implementation Plan

## 1. Overview

This issue adds YouTube URL import alongside file upload in the AiClip front-end
media flow, with back-end endpoint, queue job, provider contract, and tests —
following SDD/TDD and AGENTS.md rules. No new Composer/npm dependencies are
introduced (build decision from Phase 0 research).

## 2. Backend Implementation (apps/api)

### 2.1 Provider Contract (`app/Contracts/YouTubeImportProvider.php`)
Interface with `validateUrl(string $url): bool` and
`extractMetadata(string $url): array`. Decouples application from any specific
YouTube library per AGENTS.md §21.

### 2.2 Fake Provider (`app/Services/FakeYouTubeImportProvider.php`)
Deterministic, model-free implementation. Recognizes
`youtube.com/watch?v=VIDEO_ID` and `youtu.be/VIDEO_ID` patterns. Returns fixed
fixture metadata. No network, no API credentials, CI-safe.

### 2.3 Queue Job (`app/Jobs/YouTubeImportJob.php`)
Follows GenerateImage topology: ownership re-validation, non-pending
idempotency, strict result validation, completed/failed marking, sanitized
logging. Runs inline through sync queue in tests/e2e.

### 2.4 FormRequest (`app/Http/Requests/StoreYouTubeImportRequest.php`)
URL validation only (required string with YouTube regex pattern).
Authorization moved to Controller (returns 404 JSON directly).

### 2.5 Controller (`app/Http/Controllers/Api/V1/YouTubeImportController.php`)
Creates `MediaAsset` (status=queued, processing_status=queued,
storage_key `{user_id}/{project_id}/{uuid}.mp4`), dispatches job, returns 202.
Ownership 404 check for non-owner/non-existent project.

### 2.6 Route (`routes/api.php` line 93-94)
`POST /api/v1/projects/{project}/media/import/youtube` inside `auth:sanctum`
group with `throttle:youtube-import` + `VerifyCsrfToken`.

### 2.7 Rate Limiter (`AppServiceProvider.php`)
`youtube-import` limiter: 20/min per user (or IP for guests).

### 2.8 Provider Binding (`AppServiceProvider.php`)
`YouTubeImportProvider` → `FakeYouTubeImportProvider` singleton in `register()`.

### 2.9 MediaAsset Model
Added `PROCESSING_COMPLETED` to valid transitions from `PROCESSING_QUEUED`.

## 3. Frontend Implementation (apps/web)

### 3.1 Types (`src/features/media/types/index.ts`)
Added `source?: 'file' | 'url'` to `MediaAsset`; `status` now `'stored' | 'queued'`.

### 3.2 API Client (`src/features/media/api/index.ts`)
Added `uploadMediaAssetByUrl(projectId, url)` — JSON POST `{ url }` with
`credentials: 'include'` + `X-XSRF-TOKEN`.

### 3.3 Hook (`src/features/media/hooks/index.ts`)
Added `uploadYouTubeUrl(url)` to `UseMediaAssetsReturn` — calls API, adds asset
with `source: 'url'`, sets error on failure.

### 3.4 Form Component (`MediaUploadForm.tsx`)
Added YouTube URL input alongside file input, client-side URL validation,
controlled state, loading state, clear-on-type errors, keyboard navigation via
`useRef` submitter tracking (reset between submissions).

### 3.5 Section Wiring (`ProjectMediaSection.tsx`)
Passes `uploadYouTubeUrl` to `MediaUploadForm`.

### 3.6 Barrel Export (`src/features/media/index.ts`)
Exports `uploadMediaAssetByUrl`.

## 4. TDD Cycle

### RED
- Write Pest unit/feature tests for provider, job, endpoint, rate limiter
- Write Vitest component/API/hook tests
- Write Playwright E2E T.6/T.7
- Verify tests fail because behavior is missing

### GREEN
- Implement provider contract + fake provider
- Implement job + controller + request + route + rate limiter + binding
- Implement frontend type + API client + hook + form + section wiring
- Verify all tests pass

### REFACTOR
- Simplify FormRequest (authorization moved to Controller)
- Replace `it.each` with `foreach` loops (Pest compatibility)
- Fix `submitterName` state bug via `useRef` (synchronous read/reset)
- Align CSRF test with existing `GenerateImageEndpointTest` pattern (202)
- Remove `Queue::assertPushedTimes` (SyncQueue incompatibility)
- Fix sanitized log assertions

## 5. Test Files

### Backend (Pest)
- `tests/Unit/Contracts/YouTubeImportProviderTest.php`
- `tests/Unit/Services/FakeYouTubeImportProviderTest.php`
- `tests/Feature/Jobs/YouTubeImportJobTest.php`
- `tests/Feature/Media/YouTubeImportEndpointTest.php`
- `tests/Feature/Media/YouTubeImportRateLimitTest.php`

### Frontend (Vitest)
- `src/features/media/components/MediaUploadForm.test.tsx`
- `src/features/media/hooks/index.test.tsx`
- `src/features/media/api/index.test.ts`

### E2E (Playwright)
- `e2e/media.spec.ts` — T.6 (valid URL import), T.7 (invalid URL error)

## 6. Verification Checklist

- [ ] Branch naming valid: `@carlosegoulart/157/feat/youtube-url-upload`
- [ ] Backend YouTube tests: 73/73 passed
- [ ] Full backend suite: no new failures (3 pre-existing, unrelated)
- [ ] Frontend tests: 477/477 passed
- [ ] Frontend lint: passed (pre-existing warnings only)
- [ ] Frontend build: passed
- [ ] Commit: Conventional Commits with `Refs #157`
- [ ] PR references issue: `Closes #157`
- [ ] `evidence.md` with `Decision: APPROVE`
- [ ] CI green: Backend CI, Frontend CI, E2E CI, governance, pr-enforcement

## 7. Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| CSRF 419 behavior differs from expected | Medium | Medium | Align test with existing `GenerateImageEndpointTest` pattern (202 no mutation) |
| Rate limiter cache driver in test env | Low | Low | Array cache driver works for test isolation |
| E2E Playwright not yet run | Medium | High | Run at 3 viewports before merge |

## 8. Definition of Done (SPEC_READY)

- [ ] `spec.md` complete with all sections per AGENTS.md §8
- [ ] `plan.md` complete with all tasks and verification checklist
- [ ] `test-plan.md` complete with all test scenarios
- [ ] `evidence.md` with `Decision: APPROVE`
- [ ] All files non-empty
