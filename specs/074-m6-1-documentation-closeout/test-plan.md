# Test Plan: Issue #74 — M6.1 Documentation Closeout

## Overview

This test plan validates that the three documentation files have been correctly updated to reflect M6.1 completion per the specification. Since this is a documentation-only issue, testing consists of content verification via grep/read checks and governance validation.

---

## Test Scenarios

### TC-DOC-01: project-state.md — Current Milestone Updated

**Procedure:**
```bash
grep -A 10 "^# Current Milestone$" docs/project-state.md
```

**Expected:**
- Contains "M6.1 — Durable Baseline Vertical Clip Render Pipeline (Issue #69, PR #73) is completed and merged"
- Contains "`ffmpeg_vertical_baseline` v1.0.0"
- Contains "render profile `vertical_v1`"
- Contains "`DerivedAsset` records with `type=clip_rendered`"
- Contains "candidate index"
- Contains "render timestamps"
- Contains "error capture"
- Contains "M6.2+ remain future, not active, not authorized, and not implemented"

**Pass Criteria:** All expected strings present.

---

### TC-DOC-02: project-state.md — Next Architectural Goal Updated

**Procedure:**
```bash
grep -A 8 "^# Next Architectural Goal$" docs/project-state.md
```

**Expected:**
- Contains "M6.2+" (not "M6 — Vertical Clip Rendering")
- Contains "M7 — Clip Review Experience"
- Does NOT contain "M6 — Vertical Clip Rendering is the next architectural goal"
- Contains "not active, not authorized, and not implemented"

**Pass Criteria:** All expected conditions met.

---

### TC-DOC-03: project-state.md — Important Decisions Includes M6.1 Rule

**Procedure:**
```bash
grep -A 15 "^# Important Decisions$" docs/project-state.md
```

**Expected:**
- Contains "M6.1 baseline vertical clip rendering"
- Contains "`ffmpeg_vertical_baseline` v1.0.0"
- Contains "profile `vertical_v1`"
- Contains "explicitly invoked via dedicated render job"
- Contains "not an automatic stage in `ProcessMediaAsset`"
- Contains "`DerivedAsset` with full metadata and status tracking"

**Pass Criteria:** All expected strings present in the Important Decisions section.

---

### TC-DOC-04: roadmap.md — M6 Milestone Table Updated

**Procedure:**
```bash
grep "^| M6 |" docs/roadmap.md
```

**Expected:**
- Line shows: `| M6 | Vertical Clip Rendering (M6.1 baseline complete; M6.2+ planned) |`

**Pass Criteria:** Exact match.

---

### TC-DOC-05: roadmap.md — M6 Completed Slices Section Exists

**Procedure:**
```bash
grep -A 5 "^## M6 — Vertical Clip Rendering (M6.1 completed)$" docs/roadmap.md
```

**Expected:**
- Section header exists
- Contains "Completed slices:"
- Contains "Durable baseline vertical clip render pipeline (Issue #69, PR #73 — merged; issue closed)"
- Contains "`ffmpeg_vertical_baseline` v1.0.0"
- Contains "render profile `vertical_v1`"
- Contains "dedicated `RenderMediaClip` queue job"
- Contains "`DerivedAsset` persistence with `type=clip_rendered`"
- Contains "explicit candidate selection"
- Contains "render status/error/timestamp tracking"
- Contains "No automatic render stage in `ProcessMediaAsset`"
- Contains "Captions, batch rendering, and additional profiles are out of scope for M6.1"

**Pass Criteria:** All expected strings present.

---

### TC-DOC-06: roadmap.md — Stale Claim Removed

**Procedure:**
```bash
grep "M6 — Vertical Clip Rendering is the next milestone. It is future, not active, not authorized, and not implemented" docs/roadmap.md
```

**Expected:** No matches (exit code 1, empty output).

**Pass Criteria:** String not found.

---

### TC-DOC-07: README.md — Development Status Table Updated

**Procedure:**
```bash
grep "^| M6 " README.md
```

**Expected:**
- Line shows: `| M6 — Vertical Clip Rendering | M6.1 Complete (Issue #69, PR #73) |`

**Pass Criteria:** Exact match.

---

### TC-DOC-08: README.md — Architecture Diagram Updated

**Procedure:**
```bash
grep -A 12 "Python Media Worker" README.md
```

**Expected:**
- Contains "Vertical clip rendering (ffmpeg_vertical_baseline v1.0.0)" as last item in the list

**Pass Criteria:** String present in the Python Media Worker section.

---

### TC-DOC-09: README.md — Architecture Narrative Updated

**Procedure:**
```bash
grep -A 8 "Current execution:" README.md
```

**Expected:**
- Contains "M6.1 baseline vertical clip rendering is implemented"
- Contains "dedicated `RenderMediaClip` queue job"
- Contains "`ffmpeg_vertical_baseline` v1.0.0"
- Contains "profile `vertical_v1`"
- Contains "persisted `DerivedAsset` records with `type=clip_rendered`"
- Does NOT contain "Rendering and image generation remain future capabilities" (that sentence replaced)

**Pass Criteria:** All expected conditions met.

---

### TC-GOV-01: Governance — Only Three Target Files Modified

**Procedure:**
```bash
git diff --name-only
```

**Expected Output (exactly these three, order may vary):**
```
docs/project-state.md
docs/roadmap.md
README.md
```

**Pass Criteria:** Output matches exactly these three files, no others.

---

### TC-GOV-02: Governance — No Application Code Changes

**Procedure:**
```bash
git diff --name-only | grep -E "^(apps/|services/|tests/|\.github/)" || echo "NO_APP_CHANGES"
```

**Expected:** Output is `NO_APP_CHANGES` (grep finds nothing).

**Pass Criteria:** No application, service, test, or CI files in diff.

---

### TC-GOV-03: Governance — Conventional Commits Format

**Procedure:**
```bash
git log --oneline -3
```

**Expected:** Each commit subject matches `<type>(<scope>): <subject>` with allowed types (feat, fix, chore, refactor, docs, test, perf, ci). For this issue, type should be `docs`.

**Pass Criteria:** All recent commits follow Conventional Commits; type is `docs`.

---

### TC-GOV-04: Governance — Issue Reference in Commits

**Procedure:**
```bash
git log --oneline --grep="#74" -3
```

**Expected:** At least one commit references `#74` (in body or footer).

**Pass Criteria:** Found.

---

## Execution Order

1. Run TC-GOV-01 first (fast fail if wrong files touched)
2. Run TC-GOV-02 (fast fail if app code changed)
3. Run TC-DOC-01 through TC-DOC-09 (content verification)
4. Run TC-GOV-03 and TC-GOV-04 (commit hygiene)

---

## Notes

- No automated test suite execution required (documentation-only)
- No Playwright/visual validation required (no UI changes)
- No backend/frontend/E2E test commands needed
- All checks are read-only grep/git operations
- Tester validates by executing the above commands and confirming expected output