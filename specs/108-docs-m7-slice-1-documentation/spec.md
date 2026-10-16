# Specification: Issue #108 — M7 Slice 1 Documentation Completion

## Overview

This issue reconciles four durable documentation files to reflect the M7 Slice 1 Clip Review Experience foundation delivered by Issue #106 / PR #107 (merged manually via GitHub UI, merge commit e8d9e56). This is a documentation-only issue; no application code changes.

### Active Issue

- Issue: #108
- Title: docs: complete M7 Slice 1 documentation and record PR #107 merge decision
- Type: docs

### Authorization

- Authorized by explicit user request.
- No implementation work; only documentation updates.

---

## Target State for Each File

### 1. README.md

#### 1.1 Development Status Table (line 15)

**Before:**
```markdown
| M6 — Vertical Clip Rendering | M6.1 Complete (Issue #69, PR #73); M6.2 Slices 3A–3E Complete (Issues #81, #81B, #87, #89, #101) |
```

**After:** Append M7 row after the M6 row:
```markdown
| M7 — Clip Review Experience | Slice 1 Complete (Issue #106, PR #107) |
```

#### 1.2 Architecture Section

**Add** one sentence to the Architecture section narrative noting that M7 Slice 1 Clip Review Experience (Issue #106 / PR #107) delivered the ClipReview model, project-scoped paginated review API, signed video URL playback, and embedded review UI inside ProjectMediaSection.

---

### 2. docs/architecture.md

#### 2.1 Target Endpoint Catalog — Add Clip Review Section

**Add** a new "Clip Review" subsection after "Clips":

**Clip Review**:
- `GET /api/v1/projects/{id}/clip-reviews` — project-scoped paginated list of clip reviews
- `GET /api/v1/clip-reviews/{id}` — single review with signed video URL
- `PATCH /api/v1/clip-reviews/{id}/status` — idempotent status update (approved/rejected/needs_changes)
- `POST /api/v1/clip-reviews/{id}/feedback` — feedback submission
- `GET /api/v1/clip-reviews/{id}/video` — signed video playback (named `clip-review.video`, 1-hour expiry)

#### 2.2 Database Entity Overview — Add ClipReview Entity

**Add** ClipReview entity after "Clip":

**ClipReview**:
- Derived from DerivedAsset
- Reviewer relationship (User)
- Status enum: pending, approved, rejected, needs_changes
- Feedback text (nullable)
- Foreign keys to DerivedAsset and User
- Unique constraint on [derived_asset_id, reviewer_id]

#### 2.3 Security Architecture — Add Signed URL Notes

**Add** note that clip-review video playback uses time-limited (1 hour) signed routes; invalid, expired, or tampered signatures return 403 before any ownership check; unsigned requests fall through to ownership 401/404; non-owners always receive 404 so no ownership information leaks.

---

### 3. docs/roadmap.md

#### 3.1 Planned Milestones Table

**Before (line 89):**
```markdown
| M7 | Clip Review Experience |
```

**After:**
```markdown
| M7 | Clip Review Experience (Slice 1 Complete — Issue #106, PR #107) |
```

#### 3.2 Add M7 Completed Section

**Add** after the M6 section:

```markdown
## M7 — Clip Review Experience (Slice 1 completed)

Completed slices:

- **Slice 1: Clip Review Experience foundation (Issue #106, PR #107 — merged)**: `ClipReview` model with status enum (`pending`, `approved`, `rejected`, `needs_changes`), review API (project-scoped paginated list, show, idempotent status update, feedback submission, dual-gate signed video URL), `ProcessClipReview` queue job with event dispatch, automatic `ClipReview` creation when a rendered `DerivedAsset` completes, and per-endpoint rate limiting. Frontend delivers the embedded review experience inside `ProjectMediaSection`: `VideoPlayer`, `ReviewActions`, `FeedbackForm`, `ReviewList`, and `ReviewPage` with native History API URL sync (no client-side router library), a root React Query provider, 44px touch targets, and visible focus indicators. Merged manually via GitHub UI (PR #107); governance decision recorded in `docs/project-state.md`.
```

---

### 4. docs/project-state.md

#### 4.1 M7 Slice 1 Content Verification

**Verify** the M7 Slice 1 content in "Completed Capabilities" (lines 115-128) against `specs/106-clip-review-foundation/spec.md`. Confirm:

- ClipReview model, migration, status enum, relationships, scopes
- API endpoints (list, show, status update, feedback, signed video URL)
- `ProcessClipReview` queue job with event dispatch and idempotency
- Automatic `ClipReview` creation when a rendered `DerivedAsset` completes
- Frontend components (VideoPlayer, ReviewActions, FeedbackForm, ReviewList, ReviewPage)
- Signed video URL flow (1-hour expiry, 403 for invalid/expired/tampered, 404 for non-owners)
- Real integration coverage (MinIO presigned round trips, real-database rollback)
- Three-viewport Playwright coverage for reachable review states

No change required if content is already complete and consistent.

#### 4.2 Important Decisions — Add Manual Merge Governance Decision

**Add at end of section:**
```markdown
PR #107 (Issue #106, M7 Slice 1) was merged manually via GitHub UI (merge commit e8d9e56), not via scripts/merge_gate.py as required by AGENTS.md §4.1. This governance deviation is recorded here; future merges must use the merge gate process.
```

---

## Acceptance Criteria (Observable)

1. **README.md**
   - [ ] Development Status table includes M7 row: "Slice 1 Complete (Issue #106, PR #107)"
   - [ ] Architecture section references M7 Slice 1 Clip Review Experience

2. **docs/architecture.md**
   - [ ] Target Endpoint Catalog includes Clip Review section with all 5 endpoints
   - [ ] Database Entity Overview includes ClipReview entity with status enum and foreign keys
   - [ ] Security Architecture notes signed video URL behavior (403 before ownership check, 404 for non-owners)

3. **docs/roadmap.md**
   - [ ] Planned Milestones table M7 row shows "Slice 1 Complete — Issue #106, PR #107"
   - [ ] New "M7 — Clip Review Experience (Slice 1 completed)" section with Slice 1 entry

4. **docs/project-state.md**
   - [ ] M7 Slice 1 content (Completed Capabilities lines 115-128) verified complete against spec 106
   - [ ] Important Decisions records PR #107 manual merge via GitHub UI

5. **Cross-Document Consistency**
   - [ ] README, architecture, roadmap, project-state all align on M7 Slice 1 status (Issue #106, PR #107)
   - [ ] Only the four target files are modified (`git diff --name-only` shows exactly these four)
   - [ ] No application code changes
   - [ ] No test file modifications

---

## Out of Scope

- Any application code changes
- Any test modifications
- Any new features or behavior
- Implementing M7 Slice 2+ (approval-driven downstream actions, editor integrations, publishing hooks)
- Creating new ADR files (governance decision recorded in project-state.md Important Decisions)
- Correcting how PR #107 was merged (historical fact recorded, not changed)

---

## Security Considerations

- None. Documentation-only change. No secrets, credentials, or sensitive data exposed.
- Documentation does not expose storage keys, signing keys, or internal IDs.

---

## UX Considerations

- None. Documentation-only change with no user-facing interface modifications.

---

## Dependencies

- Issue #106 / PR #107 merged and closed (verified: yes)
- docs/project-state.md already contains M7 Slice 1 content (lines 115-128) — verify completeness
- AGENTS.md §4.1 referenced for the governance decision record (merge gate requirement)

---
SPEC_READY