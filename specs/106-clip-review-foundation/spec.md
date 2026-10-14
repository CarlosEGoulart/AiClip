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
- [ ] Write Playwright E2E tests for review workflow (approve, reject, needs_changes)
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
- Controller validates ownership, returns 302 redirect to storage temporary URL (MinIO presigned) or streams via Laravel
- Thumbnail URL follows same pattern if thumbnail exists

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
- URL updates with clip review ID for deep linking
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
- [ ] **AC-11**: Backend Pest tests pass (model, API, queue job); Frontend Vitest tests pass (components); Playwright E2E passes (approve, reject, needs_changes workflows)
- [ ] **AC-12**: Real integration with MinIO, database, and queue works in CI; no mocks for storage or worker in integration tests

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

- None required for this slice. All operations are within existing authorized infrastructure.

---

SPEC_READY