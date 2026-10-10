# Specification: REC-03 — Documentation Synchronization for M6.2 Slices 3A–3D

## Issue Reference

- **Issue**: #93
- **Title**: docs: synchronize documentation and evidence files with M6.2 Slices 3A–3D merged state
- **Type**: docs / chore
- **Priority**: High (documentation drift from actual merged state)

---

## Problem Statement

The project documentation and evidence files do not reflect the actual merged state of M6.2 Slices 3A–3D (caption flow integration). While all four Issues (#81, #81B, #87, #89) are **CLOSED** and their PRs (#83, #85, #88, #90) are **MERGED**, the documentation and evidence files still describe them as in-progress or future work.

### Current State (from GitHub)

| Issue | Title | PR | State | Merge Commit | Merge Date |
|-------|-------|----|-------|--------------|------------|
| #81 | feat(captions): connect Laravel clip context to worker | #83 | CLOSED | b71e11e (part of merge) | 2026-10-08 |
| #81B | feat(captions): implement SrtGenerator SRT format generation | #85 | CLOSED | ea23565 (part of merge) | 2026-10-09 |
| #87 | M6.2 Slice 3C: SRT Storage & Worker Contract | #88 | CLOSED | 743cb85 (part of merge) | 2026-10-10 |
| #89 | feat(render): integrate caption flow into RenderMediaClip job (M6.2 Slice 3D) | #90 | CLOSED | 3b54f31 (part of merge) | 2026-10-10 |

### Documentation Gaps

| File | Current State | Required Update |
|------|---------------|-----------------|
| `README.md` | M6.1 Complete only | Add M6.2 Slices 3A–3D as completed |
| `docs/project-state.md` | M6.2+ future, not active | Update to reflect M6.2 Slices 3A-3D completed |
| `docs/roadmap.md` | M6.2+ planned | Add completed Slices 3A-3D to M6 |
| `specs/081/evidence.md` | Current: `TESTER_APPROVED` | Add MERGED/ISSUE_CLOSED state |
| `specs/081B/evidence.md` | Current: `TESTER_APPROVED` | Add MERGED/ISSUE_CLOSED state |
| `specs/087/evidence.md` | Current: `TESTER_APPROVED` | Add MERGED/ISSUE_CLOSED state |
| `specs/089/evidence.md` | Current: `TESTER_APPROVED` | Add MERGED/ISSUE_CLOSED state |

---

## Required Behavior

### 1. README.md Updates

#### 1.1 Development Status Table

Update the Development Status table to show M6.2 Slices 3A–3D completed.

**Current:**
```markdown
| Milestone | Status |
|-----------|--------|
| M0 — Engineering Governance | Completed |
| M1 — Application Foundation | Completed |
| M2 — Media Storage | Completed |
| M3 — Asynchronous Media Processing | Completed |
| M4 — Video Understanding | Completed |
| M5 — AI Clip Recommendation | Completed (Issue #64, PR #66) |
| M6 — Vertical Clip Rendering | M6.1 Complete (Issue #69, PR #73) |
```

**Required:**
```markdown
| Milestone | Status |
|-----------|--------|
| M0 — Engineering Governance | Completed |
| M1 — Application Foundation | Completed |
| M2 — Media Storage | Completed |
| M3 — Asynchronous Media Processing | Completed |
| M4 — Video Understanding | Completed |
| M5 — AI Clip Recommendation | Completed (Issue #64, PR #66) |
| M6 — Vertical Clip Rendering | M6.1 Complete (Issue #69, PR #73); M6.2 Slices 3A–3D Complete (Issues #81, #81B, #87, #89) |
```

#### 1.2 Architecture Section

Add mention of caption flow integration in the Architecture section.

**Current (lines 54-61):**
```markdown
Current execution: Laravel `ProcessMediaAsset` queue job → `ProcessMediaAction`
→ Python CLI subprocess → strict result validation → Laravel PostgreSQL
persistence. Python does not consume Laravel queue jobs or write application
rows directly. A standalone queue-consuming Python worker is a future target,
not the current topology. M6.1 baseline vertical clip rendering is implemented
via dedicated `RenderMediaClip` queue job invoking `ffmpeg_vertical_baseline`
v1.0.0 profile `vertical_v1`, producing persisted `DerivedAsset` records with
`type=clip_rendered`. Image generation remains a future capability.
```

**Required:** Append to the Architecture section:
```markdown
M6.2 Slices 3A–3D add caption flow integration: `RenderMediaClip` job fetches
completed transcripts, projects segments to clip-local coordinates via
`CaptionProjection`, generates SRT via `SrtGenerator`, stores caption files
via `StorageKeyBuilder::captionFile()`, and includes `caption_file` in the
worker contract (`media_processing_v1.json`) when projection yields content.
Caption files are optional and omitted when no transcript exists, transcript
status is not completed, or projection yields no in-range segments.
```

---

### 2. docs/project-state.md Updates

#### 2.1 Completed Capabilities

Update the "Completed Capabilities" section to include M6.2 Slices 3A-3D.

**Current (lines 73-83):**
```markdown
# Current Milestone

M0–M5 completed. Issue #64 (M5 first slice, model-backed semantic clip
recommendation) is closed as completed following the merge of PR #66. PR #65
was superseded without merge, closed unmerged, and remains as history with
both Issue #64 branches preserved. M6.1 — Durable Baseline Vertical Clip
Render Pipeline (Issue #69, PR #73) is completed and merged. M6.1 delivers
`ffmpeg_vertical_baseline` v1.0.0 render profile `vertical_v1` producing
persisted `DerivedAsset` records with `type=clip_rendered`, candidate index,
render timestamps, and error capture. No implementation issue is active.
M6.2+ remain future, not active, not authorized, and not implemented.
```

**Required:** Add a new paragraph before "Current Milestone" in the "Completed Capabilities" section:
```markdown
M6.2 Slices 3A–3D — Caption Flow Integration (Issues #81, #81B, #87, #89 / PRs #83, #85, #88, #90 — all merged and closed):
- **Slice 3A (Issue #81 / PR #83)**: `CaptionProjection` — pure PHP segment projection matching Slice 2 algorithm; `SrtGenerator` — SRT format generation from projected segments.
- **Slice 3B (Issue #81B / PR #85)**: Dedicated `SrtGenerator` implementation with 6 unit tests covering single/multiple segments, empty text, multi-line text, timestamp format, and empty input.
- **Slice 3C (Issue #87 / PR #88)**: `StorageKeyBuilder::captionFile()` for deterministic S3 key generation; `MediaProcessingContract::renderClipRequest()` extended with optional `caption_file` parameter; worker schema `media_processing_v1.json` updated with `caption_file` in `render_clip_request`.
- **Slice 3D (Issue #89 / PR #90)**: `RenderMediaClip` job integrates caption flow — fetches `STATUS_COMPLETED` transcripts, projects segments, generates SRT, stores caption file, includes `caption_file` in contract only when projection yields content. Backward compatible: caption_file omitted when no transcript, status not completed, or empty projection.
```

#### 2.2 Current Milestone

Update "Current Milestone" to reflect M6.2 Slices 3A-3D completed.

**Required replacement for lines 73-83:**
```markdown
# Current Milestone

M0–M5 completed. Issue #64 (M5 first slice, model-backed semantic clip
recommendation) is closed as completed following the merge of PR #66. PR #65
was superseded without merge, closed unmerged, and remains as history with
both Issue #64 branches preserved. M6.1 — Durable Baseline Vertical Clip
Render Pipeline (Issue #69, PR #73) is completed and merged. M6.1 delivers
`ffmpeg_vertical_baseline` v1.0.0 render profile `vertical_v1` producing
persisted `DerivedAsset` records with `type=clip_rendered`, candidate index,
render timestamps, and error capture.

M6.2 Slices 3A–3D — Caption Flow Integration (Issues #81, #81B, #87, #89 / PRs #83, #85, #88, #90) are completed, merged, and closed. No implementation issue is active. Slice 3E (end-to-end integration test) remains planned but not authorized.
```

#### 2.3 Next Architectural Goal

Update "Next Architectural Goal" to note Slice 3E as future but not authorized.

**Current (lines 85-93):**
```markdown
# Next Architectural Goal

M6.2+ — Vertical Clip Rendering enhancements (captions, multi-candidate batch
rendering, render profiles beyond `vertical_v1`) and M7 — Clip Review
Experience are the next architectural goals. They are not active, not
authorized, and not implemented; planning and implementing them require
separate explicit authorization. A standalone queue-consuming Python service
remains a future architectural evolution, not the current CLI execution
topology.
```

**Required:**
```markdown
# Next Architectural Goal

M6.2 Slice 3E — End-to-end caption flow integration test (planned, not authorized).
M6.2+ remaining — Vertical Clip Rendering enhancements (multi-candidate batch
rendering, render profiles beyond `vertical_v1`) and M7 — Clip Review
Experience are the next architectural goals. They are not active, not
authorized, and not implemented; planning and implementing them require
separate explicit authorization. A standalone queue-consuming Python service
remains a future architectural evolution, not the current CLI execution
topology.
```

---

### 3. docs/roadmap.md Updates

#### 3.1 M6 Section

Update the M6 section to list completed Slices 3A-3D with Issue/PR refs, and keep Slice 3E as planned but not authorized.

**Current (lines 68-72):**
```markdown
## M6 — Vertical Clip Rendering (M6.1 completed)

Completed slices:

- Durable baseline vertical clip render pipeline (Issue #69, PR #73 — merged; issue closed): `ffmpeg_vertical_baseline` v1.0.0, render profile `vertical_v1`, dedicated `RenderMediaClip` queue job, `DerivedAsset` persistence with `type=clip_rendered`, explicit candidate selection, render status/error/timestamp tracking. No automatic render stage in `ProcessMediaAsset`. Captions, batch rendering, and additional profiles are out of scope for M6.1.
```

**Required:**
```markdown
## M6 — Vertical Clip Rendering (M6.1 completed; M6.2 Slices 3A–3D completed)

Completed slices:

- Durable baseline vertical clip render pipeline (Issue #69, PR #73 — merged; issue closed): `ffmpeg_vertical_baseline` v1.0.0, render profile `vertical_v1`, dedicated `RenderMediaClip` queue job, `DerivedAsset` persistence with `type=clip_rendered`, explicit candidate selection, render status/error/timestamp tracking. No automatic render stage in `ProcessMediaAsset`. Captions, batch rendering, and additional profiles are out of scope for M6.1.

- **Slice 3A (Issue #81, PR #83 — merged; issue closed)**: Caption projection (`CaptionProjection`) and SRT generation (`SrtGenerator`) services. Pure PHP, deterministic, integer-only functions matching Slice 2 algorithm and SRT specification. 18 unit tests (12 CaptionProjection + 6 SrtGenerator).

- **Slice 3B (Issue #81B, PR #85 — merged; issue closed)**: Dedicated `SrtGenerator` implementation extracted from Slice 3A scope for atomic delivery. 6 unit tests covering all SRT format requirements.

- **Slice 3C (Issue #87, PR #88 — merged; issue closed)**: `StorageKeyBuilder::captionFile()` for deterministic S3 key generation; `MediaProcessingContract::renderClipRequest()` extended with optional `caption_file`; worker schema `media_processing_v1.json` updated with `caption_file` in `render_clip_request`. 15 new unit tests, zero regression in 986 existing tests.

- **Slice 3D (Issue #89, PR #90 — merged; issue closed)**: `RenderMediaClip` job integrates caption flow — fetches completed transcripts, projects segments via `CaptionProjection`, generates SRT via `SrtGenerator`, stores via `StorageKeyBuilder::captionFile()`, includes `caption_file` in worker contract only when projection non-empty. 6 new integration tests, zero regression in 14 existing RenderMediaClip tests.

Planned slices (not authorized, not implemented):

- **Slice 3E**: End-to-end caption flow integration test (full pipeline: upload → transcribe → detect scenes → recommend → render with captions).
```

---

### 4. Evidence Files Updates

For each of the four evidence files, append a "Final Lifecycle State" section that:
- Preserves the historical `TESTER_APPROVED` decision
- Adds the final lifecycle state: `MERGED` and `ISSUE_CLOSED`
- Includes PR number, merge commit, merge date
- References the actual merge commit and PR
- Does NOT rewrite or delete historical `TESTER_APPROVED` decisions

#### 4.1 specs/081-m6-2-slice3-caption-flow/evidence.md

Append after the existing "Lifecycle State" section:

```markdown
## Final Lifecycle State

The issue was merged and closed following Tester approval.

- **PR**: #83
- **Merge Commit**: b71e11e
- **Merge Date**: 2026-10-08
- **GitHub PR URL**: https://github.com/CarlosEGoulart/AiClip/pull/83
- **GitHub Issue URL**: https://github.com/CarlosEGoulart/AiClip/issues/81

### Lifecycle Transition

`TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

### Historical Preservation

The original `TESTER_APPROVED` decision (2026-10-08) for Slices 3A and 3B is preserved above. This section records the final GitHub lifecycle completion without altering historical evidence.
```

#### 4.2 specs/081B-m6-2-slice3b-srt-generator/evidence.md

Append after the existing "Lifecycle State" section:

```markdown
## Final Lifecycle State

The issue was merged and closed following Tester approval.

- **PR**: #85
- **Merge Commit**: ea23565
- **Merge Date**: 2026-10-09
- **GitHub PR URL**: https://github.com/CarlosEGoulart/AiClip/pull/85
- **GitHub Issue URL**: https://github.com/CarlosEGoulart/AiClip/issues/81B

### Lifecycle Transition

`TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

### Historical Preservation

The original `TESTER_APPROVED` decision (2026-10-08) is preserved above. This section records the final GitHub lifecycle completion without altering historical evidence.
```

#### 4.3 specs/087-m6-2-slice3c-storage-contract/evidence.md

Append after the existing "Lifecycle State" section:

```markdown
## Final Lifecycle State

The issue was merged and closed following Tester approval.

- **PR**: #88
- **Merge Commit**: 743cb85
- **Merge Date**: 2026-10-10
- **GitHub PR URL**: https://github.com/CarlosEGoulart/AiClip/pull/88
- **GitHub Issue URL**: https://github.com/CarlosEGoulart/AiClip/issues/87

### Lifecycle Transition

`TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

### Historical Preservation

The original `TESTER_APPROVED` decision is preserved above. This section records the final GitHub lifecycle completion without altering historical evidence.
```

#### 4.4 specs/089-m6-2-slice3d-render-caption-integration/evidence.md

Append after the existing "Lifecycle State" section:

```markdown
## Final Lifecycle State

The issue was merged and closed following Tester approval.

- **PR**: #90
- **Merge Commit**: 3b54f31
- **Merge Date**: 2026-10-10
- **GitHub PR URL**: https://github.com/CarlosEGoulart/AiClip/pull/90
- **GitHub Issue URL**: https://github.com/CarlosEGoulart/AiClip/issues/89

### Lifecycle Transition

`TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

### Historical Preservation

The original `TESTER_APPROVED` decision is preserved above. This section records the final GitHub lifecycle completion without altering historical evidence.
```

---

## Acceptance Criteria

- [ ] **AC-01**: README.md Development Status table shows M6.2 Slices 3A-3D completed
- [ ] **AC-02**: README.md Architecture section mentions caption flow integration
- [ ] **AC-03**: docs/project-state.md Completed Capabilities includes M6.2 Slices 3A-3D
- [ ] **AC-04**: docs/project-state.md Current Milestone reflects M6.2 Slices 3A-3D completed
- [ ] **AC-05**: docs/project-state.md Next Architectural Goal mentions Slice 3E as future/unauthorized
- [ ] **AC-06**: docs/roadmap.md M6 section lists completed Slices 3A-3D with Issue/PR refs
- [ ] **AC-07**: docs/roadmap.md Slice 3E listed as planned but not authorized
- [ ] **AC-08**: specs/081/evidence.md has final lifecycle state with PR #83, merge commit, date
- [ ] **AC-09**: specs/081B/evidence.md has final lifecycle state with PR #85, merge commit, date
- [ ] **AC-10**: specs/087/evidence.md has final lifecycle state with PR #88, merge commit, date
- [ ] **AC-11**: specs/089/evidence.md has final lifecycle state with PR #90, merge commit, date
- [ ] **AC-12**: Historical `TESTER_APPROVED` decisions preserved in all evidence files
- [ ] **AC-13**: Cross-document consistency verified (README, project-state, roadmap align)

---

## Scope

### In Scope
- `README.md`
- `docs/project-state.md`
- `docs/roadmap.md`
- `specs/081-m6-2-slice3-caption-flow/evidence.md`
- `specs/081B-m6-2-slice3b-srt-generator/evidence.md`
- `specs/087-m6-2-slice3c-storage-contract/evidence.md`
- `specs/089-m6-2-slice3d-render-caption-integration/evidence.md`

### Out of Scope
- Code implementation changes
- New tests
- Slice 3E specification or implementation
- Governance changes
- Database migrations
- API endpoints
- Frontend/UI changes

---

## Security Considerations

None. This is a documentation-only change. No secrets, credentials, or sensitive data are exposed.

---

## UX Considerations

None. This is a documentation-only change with no user-facing interface modifications.

---

## Dependencies

None. This issue can be executed independently once authorized.

---

## Human-Gated Operations

None required. All updates are to documentation and evidence files within the repository.

---

## Test Plan

This is a documentation synchronization issue. No code tests are required.

Verification is manual:
1. Verify all 7 target files are updated per the required behavior above
2. Verify cross-document consistency (README, project-state, roadmap align on M6.2 Slices 3A-3D status)
3. Verify historical `TESTER_APPROVED` decisions are preserved in all 4 evidence files
4. Verify new "Final Lifecycle State" sections contain correct PR numbers, merge commits, and dates

---

## Specification Status

`SPEC_READY`