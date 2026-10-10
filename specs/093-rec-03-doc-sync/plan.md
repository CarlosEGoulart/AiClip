# Implementation Plan: REC-03 — Documentation Synchronization for M6.2 Slices 3A–3D

## Overview

This is a **documentation-only** issue. No code changes are required. The plan updates 7 files to reflect the actual merged state of M6.2 Slices 3A–3D (Issues #81, #81B, #87, #89 / PRs #83, #85, #88, #90).

---

## Step 1: Update README.md

### 1.1 Development Status Table (lines ~49-58)

**File**: `README.md`

**Action**: Update the M6 row in the Development Status table.

**Current**:
```markdown
| M6 — Vertical Clip Rendering | M6.1 Complete (Issue #69, PR #73) |
```

**Required**:
```markdown
| M6 — Vertical Clip Rendering | M6.1 Complete (Issue #69, PR #73); M6.2 Slices 3A–3D Complete (Issues #81, #81B, #87, #89) |
```

### 1.2 Architecture Section (after line ~86)

**File**: `README.md`

**Action**: Append caption flow integration description to the Architecture section.

**Required addition**:
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

## Step 2: Update docs/project-state.md

### 2.1 Completed Capabilities Section (before "Current Milestone")

**File**: `docs/project-state.md`

**Action**: Add new paragraph documenting M6.2 Slices 3A-3D completion.

**Required addition** (insert before `# Current Milestone`):
```markdown
M6.2 Slices 3A–3D — Caption Flow Integration (Issues #81, #81B, #87, #89 / PRs #83, #85, #88, #90 — all merged and closed):
- **Slice 3A (Issue #81 / PR #83)**: `CaptionProjection` — pure PHP segment projection matching Slice 2 algorithm; `SrtGenerator` — SRT format generation from projected segments.
- **Slice 3B (Issue #81B / PR #85)**: Dedicated `SrtGenerator` implementation with 6 unit tests covering single/multiple segments, empty text, multi-line text, timestamp format, and empty input.
- **Slice 3C (Issue #87 / PR #88)**: `StorageKeyBuilder::captionFile()` for deterministic S3 key generation; `MediaProcessingContract::renderClipRequest()` extended with optional `caption_file` parameter; worker schema `media_processing_v1.json` updated with `caption_file` in `render_clip_request`.
- **Slice 3D (Issue #89 / PR #90)**: `RenderMediaClip` job integrates caption flow — fetches `STATUS_COMPLETED` transcripts, projects segments, generates SRT, stores caption file, includes `caption_file` in contract only when projection yields content. Backward compatible: caption_file omitted when no transcript, status not completed, or empty projection.
```

### 2.2 Current Milestone Section (lines ~73-83)

**File**: `docs/project-state.md`

**Action**: Replace the entire Current Milestone section.

**Required replacement**:
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

### 2.3 Next Architectural Goal Section (lines ~85-93)

**File**: `docs/project-state.md`

**Action**: Replace the Next Architectural Goal section.

**Required replacement**:
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

## Step 3: Update docs/roadmap.md

### 3.1 M6 Section (lines ~68-72)

**File**: `docs/roadmap.md`

**Action**: Replace the M6 section entirely.

**Required replacement**:
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

## Step 4: Update Evidence Files (4 files)

For each evidence file, append a "Final Lifecycle State" section after the existing "Lifecycle State" section. Preserve all historical content including the `TESTER_APPROVED` decision.

### 4.1 specs/081-m6-2-slice3-caption-flow/evidence.md

**Append after existing "Lifecycle State" section**:
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

### 4.2 specs/081B-m6-2-slice3b-srt-generator/evidence.md

**Append after existing "Lifecycle State" section**:
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

### 4.3 specs/087-m6-2-slice3c-storage-contract/evidence.md

**Append after existing "Lifecycle State" section**:
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

### 4.4 specs/089-m6-2-slice3d-render-caption-integration/evidence.md

**Append after existing "Lifecycle State" section**:
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

## Step 5: Verification

### 5.1 Cross-Document Consistency Check

Verify all three primary documents agree:
- README.md Development Status table
- docs/project-state.md Current Milestone
- docs/roadmap.md M6 section

All must state: **M6.2 Slices 3A–3D completed/merged/closed**

### 5.2 Link/Reference Verification

Verify all PR numbers, merge commits, dates, and GitHub URLs are correct:
- PR #83 / b71e11e / 2026-10-08
- PR #85 / ea23565 / 2026-10-09
- PR #88 / 743cb85 / 2026-10-10
- PR #90 / 3b54f31 / 2026-10-10

### 5.3 Markdown Lint

Run markdown lint if available:
```bash
# If markdownlint is available
npx markdownlint-cli2 README.md docs/project-state.md docs/roadmap.md specs/081-m6-2-slice3-caption-flow/evidence.md specs/081B-m6-2-slice3b-srt-generator/evidence.md specs/087-m6-2-slice3c-storage-contract/evidence.md specs/089-m6-2-slice3d-render-caption-integration/evidence.md
```

---

## Dependencies

None. This issue can be executed independently.

## Human-Gated Operations

None required. All updates are to documentation and evidence files within the repository.

## Out of Scope

- Code implementation changes
- New tests
- Slice 3E specification or implementation
- Governance changes
- Database migrations
- API endpoints
- Frontend/UI changes