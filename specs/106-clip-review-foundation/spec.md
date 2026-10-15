# Specification: Clip Review Experience Foundation — Issue #106

## Authority and Description

Active issue: [Issue #106](https://github.com/CarlosEGoulart/AiClip/issues/106). Existing branch: `@carlosegoulart/106/feat/clip-review-experience`.

Users need to review AI-generated clips before publishing. M6.2 Slices 3A–3E completed: `DerivedAsset` with `type=clip_rendered` exists and contains rendered vertical clips with caption burn-in. This slice establishes the foundational Clip Review Experience — the ClipReview model, API endpoints, queue job, React UI components, and video player for reviewing clips.

Read this specification with `plan.md` and `test-plan.md` in this directory. The active issue and explicit human corrections define this increment. `docs/architecture.md`, `docs/prd.md`, `docs/roadmap.md`, `docs/project-state.md`, and ADR-0002 provide context, not authorization for their broader future workflows.

## Technical Tasks

- [ ] Create `ClipReview` model and migration with status enum (`pending`, `approved`, `rejected`, `needs_changes`), feedback text, and foreign keys to `DerivedAsset` and `User`
- [ ] Create API routes and controller: `GET /api/v1/projects/{project}/clip-reviews`, `GET /api/v1/clip-reviews/{clipReview}`, `PATCH /api/v1/clip-reviews/{clipReview}/status`, `POST /api/v1/clip-reviews/{clipReview}/feedback`
- [ ] Create `ProcessClipReview` queue job that updates `DerivedAsset` status based on review outcome
- [ ] Implement React components: `VideoPlayer`, `ReviewActions`, `FeedbackForm`, `ReviewList`, `ReviewPage`
- [ ] Integrate ClipReview with existing `DerivedAsset` query scopes and project ownership
- [ ] Write Pest unit/feature tests for backend model, API, and queue job
- [ ] Write Vitest component tests for frontend components
- [ ] Write Playwright E2E tests for the reachable embedded review experience (discoverability, empty state, non-interactivity, responsive viewports, console/network cleanliness); full approve/reject/needs_changes workflow coverage lives in backend feature/integration tests and Vitest component tests (see "Known Limitations and Coverage Boundary")
- [ ] Verify real integration with MinIO, FFmpeg, and database

## Scope and Out of Scope

### In Scope

1. **ClipReview Model & Migration**: `clip_reviews` table with `id`, `derived_asset_id`, `reviewer_id`, `status` enum, `feedback` text, timestamps
2. **API Endpoints**: List clip reviews for a project, get single review, update status, submit feedback
3. **Queue Job**: `ProcessClipReview` — processes review decision, updates `DerivedAsset` render status, emits events
4. **React Components**:
   - `VideoPlayer` — HTML5 video with keyboard shortcuts (space=play/pause, arrows=seek, f=fullscreen)
   - `ReviewActions` — Approve/Reject/Needs Changes buttons with confirmation
   - `FeedbackForm` — Textarea for rejection/needs_changes feedback with character count
   - `ReviewList` — Grid/list of clips with status badges, thumbnails
   - `ReviewPage` — Full review page with video player, actions, feedback, navigation
5. **Integration**: Connect `DerivedAsset::scopeRenderedClips()` to ClipReview queries; enforce project ownership
6. **Signed URLs**: Secure video playback via Laravel signed routes with expiration

### UI Topology (Ratified)

The clip-review UI is embedded, not routed: `App` renders `AuthenticatedShell` → `ProjectMediaSection` (features/media; "Review Clips" tab) → `ReviewPage` (features/clip-review) with the selected project's id. The application has no client-side router; no `/projects/{id}/review` route exists, and a routed review page is out of scope. `ReviewPage` deep-linking (the `clipReviewId` URL parameter) uses the native History API: read the parameter from `window.location.search` on mount and update via `window.history.replaceState` on selection. react-router hooks are prohibited in `ReviewPage` — no Router context exists in the production tree, and react-router hooks throw outside a Router, which crashes the embedded review section on mount.

### Out of Scope

- Social publishing (M9–M12)
- Advanced clip editing (trimming, re-rendering)
- Batch review operations
- Review analytics/dashboard
- Email/notification on review completion
- Multiple reviewers per clip
- Clip versioning/history
- AI-assisted review suggestions
- Mobile app
- Multi-language support
- Team collaboration

## Authentication, Ownership, and Request Ordering

- Use existing `auth:sanctum` SPA cookie/session authentication. State-changing requests retain CSRF validation using the CSRF-cookie handshake and `X-XSRF-TOKEN`. No bearer-token flow or credentials in localStorage.
- Ownership is server-controlled: `session user -> project -> media asset -> derived asset -> clip review`. Resolve an owned project before any clip review operation. Nonexistent and non-owned resources both return 404.
- Missing/expired authentication returns JSON 401. Missing/mismatched CSRF on mutations returns 419 without mutation.
- On state-changing requests, CSRF validation runs before authentication: a write missing both session and CSRF token fails CSRF first and returns 419; a write with a valid CSRF token but no authenticated session reaches the auth guard and returns 401.
- Do not mass-assign client data. Status updates accept only `status` and optional `feedback`; client-supplied IDs cannot influence ownership.
- Apply `clip-review-update` rate limiter at 30 requests per minute per authenticated user, shared across projects.

## Data Model

### ClipReview Migration

```php
Schema::create('clip_reviews', function (Blueprint $table) {
    $table->id();
    $table->foreignId('derived_asset_id')->constrained()->cascadeOnDelete();
    $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
    $table->enum('status', ['pending', 'approved', 'rejected', 'needs_changes'])
          ->default('pending');
    $table->text('feedback')->nullable();
    $table->timestamps();

    $table->unique(['derived_asset_id', 'reviewer_id']); // one review per user per clip
    $table->index(['reviewer_id', 'status']);
});
```

### ClipReview Model

```php
class ClipReview extends Model
{
    protected $fillable = ['derived_asset_id', 'reviewer_id', 'status', 'feedback'];

    protected $casts = [
        'status' => ClipReviewStatus::class,
    ];

    public function derivedAsset(): BelongsTo { ... }
    public function reviewer(): BelongsTo { ... }

    public function scopeForProject($query, int $projectId): Builder { ... }
    public function scopePending($query): Builder { ... }
}
```

### DerivedAsset Integration

Add to `DerivedAsset`:
```php
public function clipReview(): HasOne { ... }
public function scopeWithReviewStatus($query): Builder { ... }
```

## Public API

| Endpoint | Method | Success | Description |
|---|---|---|---|
| `/api/v1/projects/{project}/clip-reviews` | GET | 200 `{"data": [...]}` | List clip reviews for project (newest first) |
| `/api/v1/clip-reviews/{clipReview}` | GET | 200 `{"data": {...}}` | Get single clip review with derived asset + signed video URL |
| `/api/v1/clip-reviews/{clipReview}/status` | PATCH | 200 `{"data": {...}}` | Update status (approved/rejected/needs_changes) |
| `/api/v1/clip-reviews/{clipReview}/feedback` | POST | 200 `{"data": {...}}` | Submit feedback text (for rejected/needs_changes) |

### Response Envelope

All endpoints return `{"data": ...}` on success. Errors use standard Laravel JSON format.

### Clip Review Resource Fields

| Field | Type | Description |
|---|---|---|
| `id` | integer | ClipReview ID |
| `derived_asset_id` | integer | DerivedAsset ID |
| `status` | string | `pending` \| `approved` \| `rejected` \| `needs_changes` |
| `feedback` | string \| null | Reviewer feedback |
| `video_url` | string | Signed URL for video playback (expires in 1 hour) |
| `thumbnail_url` | string \| null | Signed URL for thumbnail (if generated) |
| `duration_ms` | integer | Clip duration in milliseconds |
| `width` | integer | Video width |
| `height` | integer | Video height |
| `candidate_index` | integer | Original candidate index |
| `render_profile_version` | string | Render profile used |
| `created_at` | string (ISO8601) | Review creation timestamp |
| `updated_at` | string (ISO8601) | Review last update timestamp |

### Status Update Request

```json
PATCH /api/v1/clip-reviews/{clipReview}/status
{
  "status": "approved" // or "rejected", "needs_changes"
}
```

### Feedback Request

```json
POST /api/v1/clip-reviews/{clipReview}/feedback
{
  "feedback": "Caption timing is off at 0:15"
}
```

## Signed Video URLs

- Generate via `URL::temporarySignedRoute('clip-review.video', now()->addHour(), ['clipReview' => $clipReview->id])`
- Route: `GET /api/v1/clip-reviews/{clipReview}/video` (named `clip-review.video`)
- Signed URLs embed the route URI, never the route name: the `video_url` string contains the URI path `clip-reviews/{id}/video` plus `expires` and `signature` query parameters. `clip-review.video` is a server-side routing key only; observable assertions target the URI path plus `signature` and `expires`.
- Controller validates ownership, returns 302 redirect to storage temporary URL (MinIO presigned) or streams via Laravel
- Thumbnail URL follows same pattern if thumbnail exists
- Presigned redirects require an S3-compatible disk (MinIO/S3). Under the `local` filesystem driver, `temporaryUrl()` is unsupported and the endpoint fails closed with 500 (logged; no storage details leak). Production and the CI backend job configure MinIO; see "Known Limitations and Coverage Boundary"

## Queue Job: ProcessClipReview

### Job Signature

```php
class ProcessClipReview implements ShouldQueue
{
    public function __construct(
        public int $clipReviewId,
        public string $action, // 'approve', 'reject', 'needs_changes'
    ) {}

    public function handle(): void { ... }
}
```

### Behavior

1. Load `ClipReview` with `derivedAsset` and `mediaAsset` relationships
2. Verify ownership and valid status transition (`pending` → target)
3. Update `ClipReview.status` and `feedback`
4. Update `DerivedAsset.render_status`:
   - `approved` → `completed` (already completed from render; no change)
   - `rejected` → keep `completed` but mark review as rejected (clip exists but not approved)
   - `needs_changes` → keep `completed` (re-render triggered separately, out of scope)
5. Dispatch `ClipReviewCompleted` event for future notifications
6. Idempotent: re-dispatching same action on non-pending review is no-op

## UX and Accessibility

### VideoPlayer Component

- Native `<video>` element with custom controls overlay
- Keyboard shortcuts:
  - `Space` / `K` — Play/Pause
  - `ArrowLeft` / `J` — Seek backward 5s
  - `ArrowRight` / `L` — Seek forward 5s
  - `ArrowUp` — Volume up
  - `ArrowDown` — Volume down
  - `M` — Mute toggle
  - `F` — Fullscreen toggle
  - `0-9` — Seek to 0%-90%
- Visible focus indicators on all controls
- ARIA labels on all buttons
- Loading spinner while metadata loads
- Error state with retry button
- Responsive: fills container, max-width 100%, aspect-ratio 9:16 (vertical)

### ReviewActions Component

- Three buttons: Approve (green), Reject (red), Needs Changes (orange)
- Each requires confirmation dialog (accessible modal with focus trap)
- Disabled while pending API response
- Loading spinner in button during request
- Keyboard accessible: Enter/Space activates, Escape cancels dialog

### FeedbackForm Component

- Textarea with label "Feedback (required for Reject/Needs Changes)"
- Character counter: 0/1000, warns at 800, error at >1000
- Auto-expands vertically
- Submit button disabled until min 10 characters for reject/needs_changes
- Accessible error announcement via `aria-live="polite"`

### ReviewList Component

- Grid on desktop (≥768px), single column on mobile (390px)
- Each item: thumbnail, duration, candidate index, status badge, "Review" button
- Status badges: Pending (gray), Approved (green), Rejected (red), Needs Changes (orange)
- Empty state: "No clips to review" with upload CTA
- Loading skeleton while fetching

### ReviewPage Component

- Header: project name, back link, clip counter (e.g., "Clip 3 of 7")
- VideoPlayer on left (desktop) / top (mobile)
- Right/bottom panel: ReviewActions, FeedbackForm, clip metadata
- Keyboard navigation: `N` / `P` for next/previous clip
- URL updates with clip review ID for deep linking via the native History API (`window.history.replaceState`; the application has no client-side router)
- Auto-save draft feedback to localStorage (cleared on submit)

### Responsive Viewports

Test at: **390x844** (mobile), **768x1024** (tablet), **1440x900** (desktop)

## Acceptance Criteria

- [ ] **AC-01**: `ClipReview` model and migration exist with correct schema, relationships, scopes, and enum cast
- [ ] **AC-02**: API endpoints return correct envelopes, enforce ownership (404 for non-owned), validate status transitions, rate limit at 30/min
- [ ] **AC-03**: `ProcessClipReview` job updates `ClipReview` and `DerivedAsset` correctly for each action; idempotent; emits event
- [ ] **AC-04**: Signed video URLs work, expire in 1 hour, return 404/403 for non-owned, stream video correctly
- [ ] **AC-05**: `VideoPlayer` component plays video, responds to all keyboard shortcuts, shows loading/error states, accessible
- [ ] **AC-06**: `ReviewActions` component shows three actions, confirms each, disables during request, announces result
- [ ] **AC-07**: `FeedbackForm` validates min 10 chars for reject/needs_changes, counts chars, accessible errors
- [ ] **AC-08**: `ReviewList` displays clips with thumbnails, status badges, navigation; empty/loading states work
- [ ] **AC-09**: `ReviewPage` integrates all components, navigates between clips, updates URL, saves draft feedback
- [ ] **AC-10**: All three responsive viewports render correctly; keyboard navigation works; focus visible; no console errors
- [ ] **AC-11**: Backend Pest tests pass (model, API, queue job); Frontend Vitest tests pass (components); Playwright E2E passes for the reachable embedded review experience (discoverability, empty state, non-interactivity, responsive viewports, console/network cleanliness). Full approve/reject/needs_changes workflow behavior is covered by backend feature tests (including the `real-integration` group executed against real MinIO in the CI backend job) and Vitest component tests; full-workflow E2E in the current e2e environment is a recorded limitation (see "Known Limitations and Coverage Boundary")
- [ ] **AC-12**: Real integration with MinIO, database, and queue works in CI; no mocks for storage or worker in integration tests

## Known Limitations and Coverage Boundary

Ratified during Phase 7 (Tester REJECT remediation). These are verified facts about the running application and the e2e CI environment, not aspirations. Tests written outside this boundary are fictional and will be rejected.

1. **Presigned video redirect requires S3-compatible storage.** `ClipReviewController::video()` redirects to `Storage::temporaryUrl()`. The `local` filesystem driver cannot presign, so outside S3/MinIO deployments the endpoint fails closed with 500. The real presigned flow is verified where MinIO is real: the `real-integration` group in the CI backend `tests` job. Backend feature tests cover the redirect flow with storage fakes (permitted there; AC-12's no-mock rule binds only real-integration tests). A possible local-disk streaming fallback for non-S3 deployments is recorded for post-issue Planner evaluation and is out of scope here.

2. **E2E reaches only the empty review state.** The e2e CI environment runs real Laravel + PostgreSQL + Vite with `FILESYSTEM_DISK=local` and provisions neither MinIO nor the Python worker/FFmpeg render pipeline. A `ClipReview` row requires a rendered `DerivedAsset` produced by that pipeline, so no clip-review data can exist in e2e. The reachable clip-review UI state is the embedded review section's empty state. Full approve/reject/needs_changes and video-playback workflows are covered by backend feature tests, the real-integration group (CI backend job), and Vitest component tests. Full-workflow E2E is deferred until the e2e environment can run the render pipeline — recorded for post-issue Planner evaluation, out of scope here.

## Security Considerations

- **Scoped Access**: All endpoints verify `user -> project -> media_asset -> derived_asset -> clip_review` chain. No IDOR.
- **CSRF Protection**: All mutations require valid `X-XSRF-TOKEN` from encrypted cookie.
- **Rate Limiting**: `clip-review-update` at 30 req/min per user prevents abuse.
- **Signed URLs**: Video playback uses time-limited (1 hour) signed routes; no direct storage exposure.
- **Input Validation**: Status must be valid enum; feedback max 1000 chars; sanitized on output.
- **No Secrets in Frontend**: Storage keys, disks, internal IDs never exposed to browser.
- **Queue Job Authorization**: Job re-validates ownership before processing; no trust on job payload alone.

## Dependencies

- **M6.2 Slices 3A–3E complete**: `DerivedAsset` with `type=clip_rendered` exists and is populated by `RenderMediaClip` job
- **Laravel 13, PHP 8.3, PostgreSQL 16**: Existing stack
- **React 19, TypeScript, Vite 8, Vitest, Playwright**: Existing frontend stack
- **MinIO / S3-compatible storage**: For video streaming via signed URLs
- **Laravel Queues**: For `ProcessClipReview` job (database or Redis driver)

## Human-Gated Operations

- Governance control-plane corrections required for CI green on PR #107, applied by the human only (`tests/governance/**` and `scripts/merge_gate.py` are denied to all agents by explicit permission rules; agents report, never edit):
  - `tests/governance/test_governance.py::test_g5_six_skills_parse` — extend the expected skills list from 6 to 8 per AGENTS.md §16 (add `design-taste-frontend`, `web-design-guidelines`) and rename to `test_g5_eight_skills_parse`.
  - `scripts/merge_gate.py::validate_tester_approval()` — replace "last decision wins" with the exactly-one-decision contract of `tests/governance/validators.py`.
  - Re-run PR #107 CI afterward; `governance` and `pr-enforcement` must both be green.
  - See `plan.md` Phase 6.6 for the full escalation and verification procedure.

## Governance Validation Requirements (CI Prerequisites)

The following CI governance failures must be resolved before this PR can merge. These are pre-existing repository-level issues blocking the governance check, not new work introduced by this slice.

### 1. Historical SDD Bundle Completeness

Two incomplete SDD bundles in `specs/` lack required files. The governance validator `tests/governance/validators.py` requires every `specs/NNN-*` directory to contain `spec.md`, `plan.md`, `test-plan.md`, and `evidence.md`.

- **`specs/081A-m6-2-slice3a-caption-projection/`** — Has `spec.md`; **missing** `plan.md`, `test-plan.md`, `evidence.md` (3 files)
- **`specs/081B-m6-2-slice3b-srt-generator/`** — Has `evidence.md`; **missing** `spec.md`, `plan.md`, `test-plan.md` (3 files)

Reference complete bundle: `specs/089-m6-2-slice3d-render-caption-integration/` (has all 4 files).

### 2. Merge Gate Validation Consistency

`scripts/merge_gate.py::validate_tester_approval()` implements "last decision wins" logic, allowing a REJECT followed by an APPROVE to pass. However, `tests/governance/validators.py` contract (`ValidationRule.TESTER_APPROVAL_REQUIRED`) requires **exactly one decision** — either APPROVE or REJECT, not both, not neither. The merge gate must reject if multiple conflicting decisions exist.

### 3. Skills Count Test Update

`tests/governance/test_governance_g5_skills.py::test_g5_six_skills_parse` expects 6 skills in `.opencode/skills/`. The directory now contains 8 skills (added `design-taste-frontend` and `web-design-guidelines`). The test must be updated to expect 8 skills and renamed to `test_g5_eight_skills_parse`.

---

SPEC_READY