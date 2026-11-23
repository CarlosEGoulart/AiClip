# Issue #157: YouTube URL Upload Alongside File Upload

## Description

Add YouTube URL import as an alternative to file upload in the AiClip media flow.
Users can paste a YouTube video URL into the media upload form; the back-end
enqueues a `YouTubeImportJob` (202 enqueue-only) that imports the video through
the `YouTubeImportProvider` contract (backed by `FakeYouTubeImportProvider` in
CI). No new Composer/npm dependencies are introduced.

## Technical Tasks

- [ ] Create `YouTubeImportProvider` contract interface
- [ ] Create `FakeYouTubeImportProvider` deterministic implementation
- [ ] Create `YouTubeImportJob` queue job
- [ ] Create `StoreYouTubeImportRequest` FormRequest (URL validation only)
- [ ] Create `YouTubeImportController` with ownership 404 check
- [ ] Add `POST /api/v1/projects/{project}/media/import/youtube` route (202,
    throttle:youtube-import 20/min, VerifyCsrfToken)
- [ ] Bind `YouTubeImportProvider` → `FakeYouTubeImportProvider` in
    `AppServiceProvider`
- [ ] Add `youtube-import` rate limiter (20/min per user)
- [ ] Add `source?: 'file' | 'url'` to MediaAsset type; status `'stored' | 'queued'`
- [ ] Add `uploadMediaAssetByUrl` API client method
- [ ] Add `uploadYouTubeUrl` hook method
- [ ] Add YouTube URL input alongside file input in `MediaUploadForm`
- [ ] Wire `uploadYouTubeUrl` into `ProjectMediaSection`
- [ ] Write backend unit + feature tests (Pest)
- [ ] Write frontend component + hook + API client tests (Vitest)
- [ ] Write Playwright E2E T.6/T.7 at 3 viewports (390x844, 768x1024, 1440x900)

## Acceptance Criteria

- [ ] `POST /api/v1/projects/{project}/media/import/youtube` returns 202 on
    successful enqueue, creates no file bytes in HTTP process
- [ ] Returns 401 for unauthenticated guests
- [ ] Returns 404 for non-owned and non-existent projects (identical body)
- [ ] Returns 422 for invalid YouTube URLs (client-side + server-side validation)
- [ ] Returns 429 after 20 requests/minute per user (rate limiter)
- [ ] `YouTubeImportJob` re-validates ownership, skips terminal assets,
    marks completed/failed, sanitizes logs (no URL content)
- [ ] `FakeYouTubeImportProvider` validates youtube.com/watch?v= and youtu.be/
    patterns deterministically
- [ ] Frontend renders YouTube URL input alongside file input
- [ ] Frontend shows client-side validation error for non-YouTube URLs
- [ ] Frontend shows loading/error states during URL import
- [ ] Frontend keyboard navigation works for both submission paths
- [ ] Backend YouTube tests: 73/73 passed
- [ ] Frontend tests: 477/477 passed
- [ ] Lint and build pass

## Out of Scope

- OAuth / authenticated YouTube API access
- Private video import
- Bulk / playlist import
- Server-side video download or transcoding (handled by queue job + worker,
  future slice)

## Security Considerations

- Ownership re-validated in Controller (404 JSON for non-owner/non-existent)
- No OAuth/refresh tokens exposed in frontend
- Provider result strictly validated before persistence
- Log entries sanitized — no raw URL content or provider internals logged
- CSRF protection via `VerifyCsrfToken` middleware
- Rate limiter 20/min per user prevents abuse

## UX Considerations

- YouTube URL input placed alongside file input in same form
- Client-side validation gives immediate feedback without server round-trip
- Loading states ("Importing...") prevent duplicate submissions
- Error alerts use `role="alert"` for screen reader announcements
- Both buttons disabled while uploading to prevent race conditions
- Keyboard navigation: Tab reaches both buttons; Enter/Space activates

## Test Scenarios

- TC-BE-01: Valid YouTube URL → 202, job dispatched, MediaAsset row created
- TC-BE-02: Guest request → 401
- TC-BE-03: Non-owned project → 404 (identical body to non-existent)
- TC-BE-04: Non-existent project → 404
- TC-BE-05: Invalid URL (empty, Vimeo, non-string) → 422, no row created
- TC-BE-06: Rate limit 20/min → 21st request returns 429
- TC-BE-07: Job ownership re-validation skips other-user assets
- TC-BE-08: Job idempotency — non-pending assets return early
- TC-BE-09: Job marks failed asset when provider throws, sanitized logs
- TC-FE-01: URL input renders with correct label and placeholder
- TC-FE-02: Client-side validation rejects non-YouTube URLs
- TC-FE-03: Import button disabled when URL empty or uploading
- TC-FE-04: Successful import clears URL input, shows no error
- TC-FE-05: Failed import shows error alert, input preserved
- TC-FE-06: Both submission paths (file + URL) work independently
- TC-E2E-06: Valid URL import at 390x844, 768x1024, 1440x900 viewports
- TC-E2E-07: Invalid URL error at 390x844, 768x1024, 1440x900 viewports

## Dependencies

- None (no new Composer/npm dependencies)
- Existing `MediaAsset` model with `processing_status` state machine
- Existing `Project` model with `user_id` ownership
- Existing Sanctum authentication + CSRF cookie flow
