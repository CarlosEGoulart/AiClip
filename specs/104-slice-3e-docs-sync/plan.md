# Implementation Plan: M6.2 Slice 3E — Documentation Synchronization

## Overview

This is a **documentation-only** issue. No code changes are required. The plan updates 3 files to reflect the actual merged state of M6.2 Slice 3E (Issue #101 / PR #103, merge commit `34cc5e7`, merged 2026-10-13).

---

## Step 1: Update README.md

### 1.1 Development Status Table (line 15)

**File**: `README.md`

**Action**: Update the M6 row in the Development Status table.

**Current**:
```markdown
| M6 — Vertical Clip Rendering | M6.1 Complete (Issue #69, PR #73); M6.2 Slices 3A–3D Complete (Issues #81, #81B, #87, #89) |
```

**Required**:
```markdown
| M6 — Vertical Clip Rendering | M6.1 Complete (Issue #69, PR #73); M6.2 Slices 3A–3E Complete (Issues #81, #81B, #87, #89, #101) |
```

### 1.2 Architecture Section (line 63)

**File**: `README.md`

**Action**: Verify the Architecture section explicitly mentions Slice 3E E2E testing with 7 scenarios. The current text is already correct — ensure it remains intact.

**Current (should remain unchanged)**:
```markdown
M6.2 Slices 3A–3E add caption flow integration and end-to-end testing: `RenderMediaClip` job fetches completed transcripts, projects segments to clip-local coordinates via `CaptionProjection`, generates SRT via `SrtGenerator`, stores caption files via `StorageKeyBuilder::captionFile()`, and includes `caption_file` in the worker contract (`media_processing_v1.json`) when projection yields content. Caption files are optional and omitted when no transcript exists, transcript status is not completed, or projection yields no in-range segments. Slice 3E adds end-to-end integration testing of the complete caption flow pipeline with real infrastructure (MinIO, FFmpeg, Python worker, FFprobe), validating 7 E2E scenarios including happy path, missing transcript, pending transcript, empty projection, malformed segments, idempotency, and transcript change detection. Image generation remains a future capability.
```

---

## Step 2: Update docs/project-state.md

### 2.1 Completed Capabilities Section (lines 56-60)

**File**: `docs/project-state.md`

**Action**: Update the M6.2 Slices paragraph to include Slice 3E.

**Current**:
```markdown
M6.2 Slices 3A–3D — Caption Flow Integration (Issues #81, #81B, #87, #89 / PRs #83, #85, #88, #90 — all merged and closed):
- **Slice 3A (Issue #81 / PR #83)**: `CaptionProjection` — pure PHP segment projection matching Slice 2 algorithm; `SrtGenerator` — SRT format generation from projected segments.
- **Slice 3B (Issue #81B / PR #85)**: Dedicated `SrtGenerator` implementation with 6 unit tests covering single/multiple segments, empty text, multi-line text, timestamp format, and empty input.
- **Slice 3C (Issue #87 / PR #88)**: `StorageKeyBuilder::captionFile()` for deterministic S3 key generation; `MediaProcessingContract::renderClipRequest()` extended with optional `caption_file` parameter; worker schema `media_processing_v1.json` updated with `caption_file` in `render_clip_request`.
- **Slice 3D (Issue #89 / PR #90)**: `RenderMediaClip` job integrates caption flow — fetches `STATUS_COMPLETED` transcripts, projects segments, generates SRT, stores caption file, includes `caption_file` in contract only when projection yields content. Backward compatible: caption_file omitted when no transcript, status not completed, or empty projection.
```

**Required Replacement**:
```markdown
M6.2 Slices 3A–3E — Caption Flow Integration (Issues #81, #81B, #87, #89, #101 / PRs #83, #85, #88, #90, #103 — all merged and closed):
- **Slice 3A (Issue #81 / PR #83)**: `CaptionProjection` — pure PHP segment projection matching Slice 2 algorithm; `SrtGenerator` — SRT format generation from projected segments.
- **Slice 3B (Issue #81B / PR #85)**: Dedicated `SrtGenerator` implementation with 6 unit tests covering single/multiple segments, empty text, multi-line text, timestamp format, and empty input.
- **Slice 3C (Issue #87 / PR #88)**: `StorageKeyBuilder::captionFile()` for deterministic S3 key generation; `MediaProcessingContract::renderClipRequest()` extended with optional `caption_file` parameter; worker schema `media_processing_v1.json` updated with `caption_file` in `render_clip_request`.
- **Slice 3D (Issue #89 / PR #90)**: `RenderMediaClip` job integrates caption flow — fetches `STATUS_COMPLETED` transcripts, projects segments, generates SRT, stores caption file, includes `caption_file` in contract only when projection yields content. Backward compatible: caption_file omitted when no transcript, status not completed, or empty projection.
- **Slice 3E (Issue #101 / PR #103)**: End-to-end caption flow integration test — validates complete pipeline: upload → transcribe → detect scenes → recommend → render with captions → validate output → persist. Uses real MinIO, FFmpeg, Python worker, FFprobe. No mocks for storage, worker, or FFmpeg. 7 E2E test scenarios covering happy path, no transcript, pending transcript, empty projection, malformed segments, idempotency, and transcript change triggering new render.
```

### 2.2 Current Milestone Section (lines 90-98)

**File**: `docs/project-state.md`

**Action**: Verify the Current Milestone section already correctly shows Slice 3E as completed. No change needed.

**Current (should remain unchanged)**:
```markdown
M6.2 Slices 3A–3E — Caption Flow Integration (Issues #81, #81B, #87, #89, #101 / PRs #83, #85, #88, #90, #103 — all merged and closed):
- **Slice 3A (Issue #81 / PR #83)**: `CaptionProjection` — pure PHP segment projection matching Slice 2 algorithm; `SrtGenerator` — SRT format generation from projected segments.
- **Slice 3B (Issue #81B / PR #85)**: Dedicated `SrtGenerator` implementation with 6 unit tests covering single/multiple segments, empty text, multi-line text, timestamp format, and empty input.
- **Slice 3C (Issue #87 / PR #88)**: `StorageKeyBuilder::captionFile()` for deterministic S3 key generation; `MediaProcessingContract::renderClipRequest()` extended with optional `caption_file` parameter; worker schema `media_processing_v1.json` updated with `caption_file` in `render_clip_request`.
- **Slice 3D (Issue #89 / PR #90)**: `RenderMediaClip` job integrates caption flow — fetches `STATUS_COMPLETED` transcripts, projects segments, generates SRT, stores caption file, includes `caption_file` in contract only when projection yields content. Backward compatible: caption_file omitted when no transcript, status not completed, or empty projection.
- **Slice 3E (Issue #101 / PR #103)**: End-to-end caption flow integration test — validates complete pipeline: upload → transcribe → detect scenes → recommend → render with captions → validate output → persist. Uses real MinIO, FFmpeg, Python worker, FFprobe. No mocks for storage, worker, or FFmpeg.
```

### 2.3 REC-04 Entry (line 97)

**File**: `docs/project-state.md`

**Action**: Verify REC-04 already correctly states "Slice 3E implemented and tested". No change needed.

**Current (should remain unchanged)**:
```markdown
REC-04 (Issue #94): E2E caption flow integration test gap resolved; Slice 3E implemented and tested.
```

### 2.4 Next Architectural Goal Section (lines 103-108)

**File**: `docs/project-state.md`

**Action**: Verify Next Architectural Goal correctly omits Slice 3E. No change needed.

**Current (should remain unchanged)**:
```markdown
# Next Architectural Goal

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

### 3.1 M6 Section Header (line 68)

**File**: `docs/roadmap.md`

**Action**: Update the M6 header to show Slices 3A–3E completed.

**Current**:
```markdown
## M6 — Vertical Clip Rendering (M6.1 completed; M6.2 Slices 3A–3D completed)
```

**Required**:
```markdown
## M6 — Vertical Clip Rendering (M6.1 completed; M6.2 Slices 3A–3E completed)
```

### 3.2 M6 Completed Slices (lines 72-83)

**File**: `docs/roadmap.md`

**Action**: Verify the M6 section already correctly lists Slice 3E as completed with full description. No change needed.

**Current (should remain unchanged)**:
```markdown
- **Slice 3E (Issue #101, PR #103 — merged; issue closed)**: End-to-end caption flow integration test — validates complete pipeline: upload → transcribe → detect scenes → recommend → render with captions → validate output → persist. Uses real MinIO, FFmpeg, Python worker, FFprobe. No mocks for storage, worker, or FFmpeg. 7 E2E test scenarios covering happy path, no transcript, pending transcript, empty projection, malformed segments, idempotency, and transcript change triggering new render.
```

### 3.3 Planned Milestones Table (line 88)

**File**: `docs/roadmap.md`

**Action**: Verify the Planned Milestones table already correctly shows "M6.2 Slices 3A–3E completed". No change needed.

**Current (should remain unchanged)**:
```markdown
| M6 | Vertical Clip Rendering (M6.1 baseline complete; M6.2 Slices 3A–3E completed) |
```

---

## Step 4: Verification

### 4.1 Cross-Document Consistency Check

Verify all three primary documents agree:

| Aspect | README.md | project-state.md | roadmap.md | Consistent? |
|--------|-----------|------------------|------------|-------------|
| M6.1 status | Complete | Complete | Complete | ✅/❌ |
| M6.2 Slices 3A-3E | Complete | Completed, merged, closed | Completed | ✅/❌ |
| Issues referenced | #81, #81B, #87, #89, #101 | #81, #81B, #87, #89, #101 | #81, #81B, #87, #89, #101 | ✅/❌ |
| PRs referenced | #83, #85, #88, #90, #103 | #83, #85, #88, #90, #103 | #83, #85, #88, #90, #103 | ✅/❌ |

All must state: **M6.2 Slices 3A–3E completed/merged/closed**

### 4.2 Reference Verification

Verify all PR numbers, merge commits, dates, and GitHub URLs are correct:
- PR #103 / 34cc5e7 / 2026-10-13 / Issue #101

### 4.3 Markdown Lint

Run markdown lint if available:
```bash
# If markdownlint is available
npx markdownlint-cli2 README.md docs/project-state.md docs/roadmap.md
```

---

## Dependencies

None. This issue can be executed independently. The merge of PR #103 (Issue #101) is a factual prerequisite that has already occurred.

## Human-Gated Operations

None required. All updates are to documentation files within the repository.

## Out of Scope

- Code implementation changes
- New tests
- Evidence files (no evidence files exist for Issue #104)
- Governance changes
- Database migrations
- API endpoints
- Frontend/UI changes
- Slice 3E specification or implementation (already done in Issue #101)