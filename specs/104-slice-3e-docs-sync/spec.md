# Specification: M6.2 Slice 3E — Documentation Synchronization

## Issue Reference

- **Issue**: #104
- **Title**: docs: synchronize M6.2 Slice 3E documentation status
- **Type**: docs / chore
- **Priority**: High (documentation drift from actual merged state)

---

## Problem Statement

The project documentation does not consistently reflect the actual merged state of M6.2 Slice 3E (end-to-end caption flow integration test). While Issue #101 is **CLOSED** and PR #103 is **MERGED** (merge commit `34cc5e7`, merged 2026-10-13), the documentation files contain contradictory and incomplete status information.

### Current State (from GitHub)

| Issue | Title | PR | State | Merge Commit | Merge Date |
|-------|-------|----|-------|--------------|------------|
| #101 | M6.2 Slice 3E: End-to-end caption flow integration test | #103 | CLOSED | 34cc5e7 | 2026-10-13 |

### Documentation Gaps

| File | Current State | Required Update |
|------|---------------|-----------------|
| `README.md` (Development Status table) | M6.2 Slices 3A–3D Complete | Add Slice 3E (Issue #101) |
| `README.md` (Architecture section) | Mentions Slice 3E but not explicit | Ensure explicit 7-scenario E2E test mention |
| `docs/project-state.md` (Completed Capabilities) | Lists 3A-3D only | Add Slice 3E with Issue #101/PR #103 |
| `docs/project-state.md` (Current Milestone) | Already shows 3E completed | No change needed |
| `docs/project-state.md` (REC-04) | Already says "implemented and tested" | No change needed |
| `docs/project-state.md` (Next Architectural Goal) | No 3E mention (correct) | No change needed |
| `docs/roadmap.md` (M6 header) | M6.2 Slices 3A–3D completed | Update to 3A–3E completed |
| `docs/roadmap.md` (M6 section) | Already shows 3E completed | No change needed |
| `docs/roadmap.md` (Planned Milestones table) | Already shows 3A-3E completed | No change needed |

---

## Required Behavior

### 1. README.md Updates

#### 1.1 Development Status Table

Update the Development Status table to show M6.2 Slices 3A–3E completed.

**Current (line 15):**
```markdown
| M6 — Vertical Clip Rendering | M6.1 Complete (Issue #69, PR #73); M6.2 Slices 3A–3D Complete (Issues #81, #81B, #87, #89) |
```

**Required:**
```markdown
| M6 — Vertical Clip Rendering | M6.1 Complete (Issue #69, PR #73); M6.2 Slices 3A–3E Complete (Issues #81, #81B, #87, #89, #101) |
```

#### 1.2 Architecture Section

Ensure the Architecture section explicitly mentions Slice 3E E2E testing with 7 scenarios.

**Current (lines 63):**
```markdown
M6.2 Slices 3A–3E add caption flow integration and end-to-end testing: `RenderMediaClip` job fetches completed transcripts, projects segments to clip-local coordinates via `CaptionProjection`, generates SRT via `SrtGenerator`, stores caption files via `StorageKeyBuilder::captionFile()`, and includes `caption_file` in the worker contract (`media_processing_v1.json`) when projection yields content. Caption files are optional and omitted when no transcript exists, transcript status is not completed, or projection yields no in-range segments. Slice 3E adds end-to-end integration testing of the complete caption flow pipeline with real infrastructure (MinIO, FFmpeg, Python worker, FFprobe), validating 7 E2E scenarios including happy path, missing transcript, pending transcript, empty projection, malformed segments, idempotency, and transcript change detection. Image generation remains a future capability.
```

**Required:** The current text is already correct and comprehensive. Verify it remains intact. No change needed unless the text has drifted.

---

### 2. docs/project-state.md Updates

#### 2.1 Completed Capabilities Section

Add Slice 3E to the M6.2 Slices paragraph in Completed Capabilities (before "Current Milestone").

**Current (lines 56-60):**
```markdown
M6.2 Slices 3A–3D — Caption Flow Integration (Issues #81, #81B, #87, #89 / PRs #83, #85, #88, #90 — all merged and closed):
- **Slice 3A (Issue #81 / PR #83)**: `CaptionProjection` — pure PHP segment projection matching Slice 2 algorithm; `SrtGenerator` — SRT format generation from projected segments.
- **Slice 3B (Issue #81B / PR #85)**: Dedicated `SrtGenerator` implementation with 6 unit tests covering single/multiple segments, empty text, multi-line text, timestamp format, and empty input.
- **Slice 3C (Issue #87 / PR #88)**: `StorageKeyBuilder::captionFile()` for deterministic S3 key generation; `MediaProcessingContract::renderClipRequest()` extended with optional `caption_file` parameter; worker schema `media_processing_v1.json` updated with `caption_file` in `render_clip_request`.
- **Slice 3D (Issue #89 / PR #90)**: `RenderMediaClip` job integrates caption flow — fetches `STATUS_COMPLETED` transcripts, projects segments, generates SRT, stores caption file, includes `caption_file` in contract only when projection yields content. Backward compatible: caption_file omitted when no transcript, status not completed, or empty projection.
```

**Required:** Update to include Slice 3E:
```markdown
M6.2 Slices 3A–3E — Caption Flow Integration (Issues #81, #81B, #87, #89, #101 / PRs #83, #85, #88, #90, #103 — all merged and closed):
- **Slice 3A (Issue #81 / PR #83)**: `CaptionProjection` — pure PHP segment projection matching Slice 2 algorithm; `SrtGenerator` — SRT format generation from projected segments.
- **Slice 3B (Issue #81B / PR #85)**: Dedicated `SrtGenerator` implementation with 6 unit tests covering single/multiple segments, empty text, multi-line text, timestamp format, and empty input.
- **Slice 3C (Issue #87 / PR #88)**: `StorageKeyBuilder::captionFile()` for deterministic S3 key generation; `MediaProcessingContract::renderClipRequest()` extended with optional `caption_file` parameter; worker schema `media_processing_v1.json` updated with `caption_file` in `render_clip_request`.
- **Slice 3D (Issue #89 / PR #90)**: `RenderMediaClip` job integrates caption flow — fetches `STATUS_COMPLETED` transcripts, projects segments, generates SRT, stores caption file, includes `caption_file` in contract only when projection yields content. Backward compatible: caption_file omitted when no transcript, status not completed, or empty projection.
- **Slice 3E (Issue #101 / PR #103)**: End-to-end caption flow integration test — validates complete pipeline: upload → transcribe → detect scenes → recommend → render with captions → validate output → persist. Uses real MinIO, FFmpeg, Python worker, FFprobe. No mocks for storage, worker, or FFmpeg. 7 E2E test scenarios covering happy path, no transcript, pending transcript, empty projection, malformed segments, idempotency, and transcript change triggering new render.
```

#### 2.2 Current Milestone Section

**Current (lines 90-98):** Already correctly shows Slice 3E as completed with Issue #101/PR #103. No change needed.

#### 2.3 REC-04 Entry

**Current (line 97):** `REC-04 (Issue #94): E2E caption flow integration test gap resolved; Slice 3E implemented and tested.` — Already correct. No change needed.

#### 2.4 Next Architectural Goal Section

**Current (lines 103-108):** Already correctly omits Slice 3E (shows M6.2+ remaining and M7). No change needed.

---

### 3. docs/roadmap.md Updates

#### 3.1 M6 Section Header

**Current (line 68):**
```markdown
## M6 — Vertical Clip Rendering (M6.1 completed; M6.2 Slices 3A–3D completed)
```

**Required:**
```markdown
## M6 — Vertical Clip Rendering (M6.1 completed; M6.2 Slices 3A–3E completed)
```

#### 3.2 M6 Completed Slices

**Current (lines 72-83):** Already correctly lists Slice 3E as completed with full description (Issue #101, PR #103, 7 E2E scenarios). No change needed.

#### 3.3 Planned Milestones Table

**Current (line 88):**
```markdown
| M6 | Vertical Clip Rendering (M6.1 baseline complete; M6.2 Slices 3A–3E completed) |
```
Already correct. No change needed.

---

## Acceptance Criteria

- [ ] **AC-01**: README.md Development Status table shows M6.2 Slices 3A–3E Complete (Issues #81, #81B, #87, #89, #101)
- [ ] **AC-02**: README.md Architecture section explicitly mentions Slice 3E E2E testing with 7 scenarios
- [ ] **AC-03**: docs/project-state.md Completed Capabilities includes Slice 3E (Issue #101, PR #103) with full description
- [ ] **AC-04**: docs/project-state.md Current Milestone correctly shows Slice 3E as merged and closed (already correct — verify unchanged)
- [ ] **AC-05**: docs/project-state.md REC-04 entry correctly states "Slice 3E implemented and tested" (already correct — verify unchanged)
- [ ] **AC-06**: docs/project-state.md Next Architectural Goal correctly omits Slice 3E (already correct — verify unchanged)
- [ ] **AC-07**: docs/roadmap.md M6 header shows "M6.2 Slices 3A–3E completed"
- [ ] **AC-08**: docs/roadmap.md M6 section correctly lists Slice 3E as completed (already correct — verify unchanged)
- [ ] **AC-09**: docs/roadmap.md Planned Milestones table shows "M6.2 Slices 3A–3E completed" (already correct — verify unchanged)
- [ ] **AC-10**: Cross-document consistency verified (README, project-state, roadmap all align on M6.2 Slices 3A–3E completed)

---

## Scope

### In Scope
- `README.md`
- `docs/project-state.md`
- `docs/roadmap.md`

### Out of Scope
- Code implementation changes
- New tests
- Evidence files (no evidence files exist for Issue #104 — this is the spec/plan creation)
- Governance changes
- Database migrations
- API endpoints
- Frontend/UI changes
- Slice 3E specification or implementation (already done in Issue #101)

---

## Security Considerations

None. This is a documentation-only change. No secrets, credentials, or sensitive data are exposed.

---

## UX Considerations

None. This is a documentation-only change with no user-facing interface modifications.

---

## Dependencies

None. This issue can be executed independently once authorized. The merge of PR #103 (Issue #101) is a factual prerequisite that has already occurred.

---

## Human-Gated Operations

None required. All updates are to documentation files within the repository.

---

## Test Plan

This is a documentation synchronization issue. No code tests are required.

Verification is manual:
1. Verify all 3 target files are updated per the required behavior above
2. Verify cross-document consistency (README, project-state, roadmap align on M6.2 Slices 3A–3E status)
3. Verify no regressions in already-correct sections (Current Milestone, REC-04, Next Architectural Goal, roadmap M6 section, Planned Milestones table)

---

## Specification Status

`SPEC_READY`