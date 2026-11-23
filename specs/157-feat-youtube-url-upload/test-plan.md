# Issue #157: YouTube URL Upload Test Plan

## 1. Test Purpose

Verify that YouTube URL import works correctly alongside file upload in the
AiClip media flow, including back-end endpoint, queue job, provider contract,
frontend form, and E2E behavior — all following TDD discipline.

## 2. Test Scope

**In Scope**:
- `YouTubeImportProvider` contract shape and `FakeYouTubeImportProvider` behavior
- `YouTubeImportJob` ownership re-validation, idempotency, result validation, failure marking
- `POST /api/v1/projects/{project}/media/import/youtube` endpoint (202, 401, 404, 422, 429)
- Rate limiter 20/min per user
- Frontend `MediaUploadForm` URL input, validation, loading/error states, keyboard navigation
- Hook `uploadYouTubeUrl` success/failure/uploading/null-project behavior
- API client `uploadMediaAssetByUrl` status code handling
- Playwright E2E T.6/T.7 at 3 viewports (390x844, 768x1024, 1440x900)

**Out of Scope**:
- OAuth / authenticated YouTube API access
- Private video import
- Bulk / playlist import
- Server-side video download / transcoding
- Existing file-upload flow (unchanged, no regression testing beyond existing tests)

## 3. Verification Scenarios

### 3.1 Backend Unit Tests (Pest)

| Scenario | ID | Method | Expected Result |
|----------|-----|--------|-----------------|
| Contract interface has `validateUrl` and `extractMetadata` | UC-01 | Reflection on `YouTubeImportProvider` | Both methods exist with correct signatures |
| Fake provider validates YouTube patterns | UC-02 | `validateUrl()` with valid/invalid URLs | Returns true for youtube.com/watch?v= and youtu.be/, false otherwise |
| Fake provider extracts deterministic metadata | UC-03 | `extractMetadata()` with valid URL | Returns fixed fixture array with video_id, title, duration_seconds, thumbnail_url |
| Fake provider rejects non-YouTube URLs | UC-04 | `validateUrl()` with Vimeo/other | Returns false |

### 3.2 Backend Job Tests (Pest)

| Scenario | ID | Method | Expected Result |
|----------|-----|--------|-----------------|
| Job validates ownership from persisted rows | UJ-01 | `YouTubeImportJob::handle()` with other-user asset | Skips asset, leaves queued |
| Job idempotency on terminal asset | UJ-02 | Call job twice on completed/failed asset | Returns early, no provider calls |
| Job marks failed on provider exception | UJ-03 | Provider throws exception | Asset status=failed, processing_status=failed |
| Job sanitizes logs (no URL content) | UJ-04 | Provider throws, check log output | No raw URL or provider internals in log entries |
| Job throws on missing media asset | UJ-05 | Job with non-existent asset id | Throws `InvalidInputException` |
| Job runs inline through sync queue | UJ-06 | Queue connection=sync in tests | Job executes synchronously, observable |

### 3.3 Backend Endpoint Tests (Pest)

| Scenario | ID | Method | Expected Result |
|----------|-----|--------|-----------------|
| Valid YouTube URL enqueues job | UE-01 | POST with valid URL, authenticated | 202, job dispatched, MediaAsset row created |
| Guest request rejected | UE-02 | POST without auth | 401 |
| Non-owned project returns 404 | UE-03 | POST to other user's project | 404 JSON |
| Non-existent project returns 404 | UE-04 | POST to project id 999999999 | 404 JSON (identical body to UE-03) |
| Invalid URL returns 422 | UE-05 | POST with empty/Vimeo/non-string URL | 422, no row created, job not dispatched |
| Missing url field returns 422 | UE-06 | POST without url field | 422 |
| Rate limit 20/min per user | UE-07 | 21 requests in one minute | 21st returns 429, Retry-After header |
| Rate limit per-project sharing | UE-08 | 20 requests on project A, 1 on project B | Project B's 21st returns 429 |
| Rate limit per-user independence | UE-09 | 20 requests by user A, 1 by user B | User B's request returns 202 |
| CSRF tampered token no mutation | UE-10 | POST with tampered XSRF token | 202 (matches existing pattern), no row created |

### 3.4 Backend Rate Limit Tests (Pest)

| Scenario | ID | Method | Expected Result |
|----------|-----|--------|-----------------|
| 20 requests allowed | RL-01 | 20 POST requests | All return 202 |
| 21st request rate limited | RL-02 | 21st POST request | Returns 429 |
| Per-project sharing | RL-03 | 20 on project A, 1 on project B | Project B's request returns 429 |
| Per-user independence | RL-04 | 20 by user A, 1 by user B | User B's request returns 202 |

### 3.5 Frontend Component Tests (Vitest + RTL)

| Scenario | ID | Method | Expected Result |
|----------|-----|--------|-----------------|
| Renders file input with accept attribute | FC-01 | Render form, query by label | Input has accept="video/mp4,video/quicktime,video/webm" |
| Renders YouTube URL input with label | FC-02 | Render form, query by label | URL input present in document |
| Rejects non-YouTube URLs with client error | FC-03 | Type invalid URL, click Import | Error alert shows, API not called |
| Clears YouTube error when user types | FC-04 | Show error, type new value | Error cleared on input change |
| Import disabled when URL empty | FC-05 | Empty URL, click Import | Import button disabled |
| Import disabled while uploading | FC-06 | uploading=true | Both buttons disabled |
| Successful URL import clears input | FC-07 | Valid URL, click Import, API returns true | URL input cleared |
| Both submission paths work independently | FC-08 | Submit file then URL (or vice versa) | Each path calls correct handler |
| Keyboard navigation reaches both buttons | FC-09 | Tab navigation | Both buttons focusable |

### 3.6 Frontend Hook Tests (Vitest + RTL)

| Scenario | ID | Method | Expected Result |
|----------|-----|--------|-----------------|
| `uploadYouTubeUrl` success | FH-01 | Mock API returns true | Asset added with source='url', error=null |
| `uploadYouTubeUrl` failure | FH-02 | Mock API throws | error set, uploading=false |
| `uploading` flag during import | FH-03 | Mock API deferred | uploading=true during call, false after |
| Null project handling | FH-04 | projectId=null | Graceful handling, no crash |

### 3.7 Frontend API Client Tests (Vitest)

| Scenario | ID | Method | Expected Result |
|----------|-----|--------|-----------------|
| Returns true on 202 | AC-01 | Mock fetch returns 202 | Resolves true |
| Returns false on 422 | AC-02 | Mock fetch returns 422 | Resolves false, error type='validation' |
| Returns false on 429 | AC-03 | Mock fetch returns 429 | Resolves false, error type='throttle' |
| Returns false on 401 | AC-04 | Mock fetch returns 401 | Resolves false, error type='unauthorized' |
| Returns false on network error | AC-05 | Mock fetch throws | Resolves false, error type='network' |

### 3.8 E2E Tests (Playwright)

| Scenario | ID | Viewports | Expected Result |
|----------|-----|-----------|-----------------|
| Valid URL import | T.6 | 390x844, 768x1024, 1440x900 | 202 enqueue, asset appears in list, no errors |
| Invalid URL error | T.7 | 390x844, 768x1024, 1440x900 | Error alert shown, no asset created |

## 4. Pass/Fail Criteria

- All Pest backend tests must pass (73 YouTube tests + no new failures in full suite)
- All Vitest frontend tests must pass (477 tests)
- Frontend lint must pass (no new errors in issue files)
- Frontend build must pass (TypeScript + Vite)
- Playwright E2E must pass at all 3 viewports (T.6, T.7)
- Merge gate `--check` must return `MERGE_READY` (all CI checks green)

## 5. Execution Method

- Backend: `cd apps/api && php artisan test --filter=YouTube`
- Backend full: `cd apps/api && php artisan test --compact`
- Frontend: `cd apps/web && npm test`
- Frontend lint: `cd apps/web && npm run lint`
- Frontend build: `cd apps/web && npm run build`
- E2E: `cd apps/web && npm run test:e2e`

## 6. TDD Evidence

### RED
Tests written before implementation for each increment (provider, job, endpoint, rate limiter, frontend component, hook, API client, E2E). Each test verified to fail because the required behavior was missing.

### GREEN
Implementation made all tests pass. Backend YouTube: 73/73. Frontend: 477/477. Lint and build green.

### REFACTOR
FormRequest simplified (authorization moved to Controller), `it.each` → `foreach`, `submitterName` bug fixed via `useRef`, CSRF test aligned with existing pattern, `Queue::assertPushedTimes` removed, sanitized log assertions fixed.
