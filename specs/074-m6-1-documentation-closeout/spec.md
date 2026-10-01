# Specification: Issue #74 — M6.1 Documentation Closeout

## Overview

This issue reconciles three durable documentation files to reflect the M6.1 vertical rendering baseline delivered by Issue #69 / PR #73. This is a documentation-only issue; no application code changes.

### Active Issue

- Issue: #74
- Title: `docs: reconcile M6.1 documentation closeout`
- Type: `docs`

### Authorization

- Authorized by explicit user request.
- No implementation work; only documentation updates.

---

## Target State for Each File

### 1. `docs/project-state.md`

#### Current Milestone Section (lines 68–75)

**Before:**
```markdown
# Current Milestone

M0–M5 completed. Issue #64 (M5 first slice, model-backed semantic clip
recommendation) is closed as completed following the merge of PR #66. PR #65
was superseded without merge, closed unmerged, and remains as history with
both Issue #64 branches preserved. No implementation issue is active. M6 —
Vertical Clip Rendering is the next milestone but is not active, not
authorized, and not implemented.
```

**After:**
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

#### Next Architectural Goal Section (lines 77–82)

**Before:**
```markdown
# Next Architectural Goal

M6 — Vertical Clip Rendering is the next architectural goal. It is not active,
not authorized, and not implemented; planning and implementing it require
separate explicit authorization. A standalone queue-consuming Python service
remains a future architectural evolution, not the current CLI execution
topology.
```

**After:**
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

#### Important Decisions Section (lines 41–49)

**Add at end of section:**
```markdown
M6.1 baseline vertical clip rendering (`ffmpeg_vertical_baseline` v1.0.0,
profile `vertical_v1`) is the first M6 slice; it is explicitly invoked via
dedicated render job, not an automatic stage in `ProcessMediaAsset`. Render
output is a persisted `DerivedAsset` with full metadata and status tracking.
```

---

### 2. `docs/roadmap.md`

#### M6 Milestone Table (lines 70–72)

**Before:**
```markdown
| Milestone | Description |
|-----------|-------------|
| M6 | Vertical Clip Rendering |
```

**After:**
```markdown
| Milestone | Description |
|-----------|-------------|
| M6 | Vertical Clip Rendering (M6.1 baseline complete; M6.2+ planned) |
```

#### M6 Completed Slices Section (insert after M5 section, before "Planned Milestones")

**Add new section:**
```markdown
## M6 — Vertical Clip Rendering (M6.1 completed)

Completed slices:

- Durable baseline vertical clip render pipeline (Issue #69, PR #73 — merged; issue closed): `ffmpeg_vertical_baseline` v1.0.0, render profile `vertical_v1`, dedicated `RenderMediaClip` queue job, `DerivedAsset` persistence with `type=clip_rendered`, explicit candidate selection, render status/error/timestamp tracking. No automatic render stage in `ProcessMediaAsset`. Captions, batch rendering, and additional profiles are out of scope for M6.1.
```

#### Remove Stale Claim (lines 82–84)

**Before:**
```markdown
M6 — Vertical Clip Rendering is the next milestone. It is future, not active,
not authorized, and not implemented; starting it requires separate explicit
authorization.
```

**After:** Remove these three lines entirely. The M6 section above now accurately reflects M6.1 completion.

---

### 3. `README.md`

#### Development Status Table (lines 7–15)

**Before:**
```markdown
| Milestone | Status |
|-----------|--------|
| M0 — Engineering Governance | Completed |
| M1 — Application Foundation | Completed |
| M2 — Media Storage | Completed |
| M3 — Asynchronous Media Processing | Completed |
| M4 — Video Understanding | Completed |
| M5 — AI Clip Recommendation | Completed (Issue #64, PR #66) |
| M6 — Vertical Clip Rendering | Next (not active, not authorized) |
```

**After:**
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

#### Architecture Section (lines 33–58)

**Before (lines 43–48):**
```text
Python Media Worker (services/worker; CLI subprocess)
├── FFmpeg / FFprobe
├── Transcription
├── Scene detection
├── Deterministic clip candidate analysis
└── Semantic clip ranking
```

**After:**
```text
Python Media Worker (services/worker; CLI subprocess)
├── FFmpeg / FFprobe
├── Transcription
├── Scene detection
├── Deterministic clip candidate analysis
├── Semantic clip ranking
└── Vertical clip rendering (ffmpeg_vertical_baseline v1.0.0)
```

**Before (lines 53–58):**
```markdown
Current execution: Laravel `ProcessMediaAsset` queue job → `ProcessMediaAction`
→ Python CLI subprocess → strict result validation → Laravel PostgreSQL
persistence. Python does not consume Laravel queue jobs or write application
rows directly. A standalone queue-consuming Python worker is a future target,
not the current topology. Rendering and image generation remain future
capabilities.
```

**After:**
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

---

## Acceptance Criteria (Observable)

1. **project-state.md**
   - [ ] "Current Milestone" section states M6.1 is completed and merged (Issue #69, PR #73)
   - [ ] "Current Milestone" section mentions `ffmpeg_vertical_baseline` v1.0.0, profile `vertical_v1`, `DerivedAsset` with `type=clip_rendered`
   - [ ] "Next Architectural Goal" section references M6.2+ and M7, not "M6 — Vertical Clip Rendering"
   - [ ] "Important Decisions" section includes the M6.1 process rule about explicit render job

2. **roadmap.md**
   - [ ] M6 table row description updated to "(M6.1 baseline complete; M6.2+ planned)"
   - [ ] New "M6 — Vertical Clip Rendering (M6.1 completed)" section with completed slice entry for Issue #69/PR #73
   - [ ] Stale "M6 — Vertical Clip Rendering is the next milestone. It is future, not active, not authorized, and not implemented" paragraph removed

3. **README.md**
   - [ ] Development Status table shows "M6.1 Complete (Issue #69, PR #73)" for M6
   - [ ] Architecture diagram includes "Vertical clip rendering (ffmpeg_vertical_baseline v1.0.0)" in Python Media Worker
   - [ ] Architecture narrative mentions M6.1 baseline vertical clip rendering via dedicated `RenderMediaClip` job and `DerivedAsset` persistence

4. **Governance**
   - [ ] Only the three target files are modified (`git diff --name-only` shows exactly these three)
   - [ ] No application code changes
   - [ ] No test files modified
   - [ ] Conventional Commits format used for commits

---

## Out of Scope

- Any application code changes
- Any test modifications
- Any new features or behavior
- M6.2+ planning or implementation
- PRD or architecture.md updates (not requested)

---

## Dependencies

- Issue #69 / PR #73 must be merged and closed (verified: yes)
- No other dependencies

---

## Security Considerations

- None. Documentation-only change.

---

## UX Considerations

- None. No user-facing interface changes.