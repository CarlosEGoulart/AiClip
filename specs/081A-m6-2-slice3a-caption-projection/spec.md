# M6.2 Slice 3A: CaptionProjection — Pure Segment Projection Function

## Issue
**#81A** — feat(captions): implement CaptionProjection pure function

## Branch
`@carlosegoulart/81A/feat/caption-projection`

## Summary
Implement a pure PHP `CaptionProjection::project()` function in Laravel that exactly matches the Slice 2 Python `project_segments_to_clip_local()` algorithm. This is a standalone, stateless transformation with no side effects, no database access, no FFmpeg calls, and no external dependencies.

## Architecture Context
- **Slice 1 (PR #78, spec 076)**: Worker supports optional `caption_file` in `render_clip` contract; FFmpeg burns captions via `subtitles=` filter.
- **Slice 2 (PR #80, spec 079)**: Pure Python `project_segments_to_clip_local()` in worker; projects absolute transcript timestamps to clip-local time.
- **Slice 3A (This Spec)**: Laravel-side pure projection function matching Slice 2 algorithm exactly. No integration, no storage, no worker contract.

## Scope

### In Scope
1. **CaptionProjection service**: New file `app/Services/CaptionProjection.php`
2. **Pure projection function**: `CaptionProjection::project(array $segments, int $clipStartMs, int $clipEndMs): array`
3. **Algorithm** (must match `project_segments_to_clip_local` exactly):
   - `$clipDurationMs = $clipEndMs - $clipStartMs`
   - If `$clipDurationMs <= 0` return `[]`
   - For each segment: `$localStart = max(0, $segment['start_ms'] - $clipStartMs)`
   - `$localEnd = min($clipDurationMs, $segment['end_ms'] - $clipStartMs)`
   - Discard if `$localEnd <= $localStart`
   - Return `[local_start_ms => $localStart, local_end_ms => $localEnd, text => $segment['text']]`
4. **Integer arithmetic only** — no floating point
5. **Deterministic** — same inputs → same outputs
6. **Unit tests**: `tests/Unit/Services/CaptionProjectionTest.php` covering all TP-02 scenarios

### Out of Scope
- SRT generation (Slice 3B)
- SRT storage (Slice 3C)
- Worker contract integration (Slice 3C)
- RenderMediaClip job wiring (Slice 3D)
- End-to-end integration (Slice 3E)
- Database schema changes
- API endpoints
- UI changes
- Multiple caption tracks/languages
- Caption styling/customization

## Data Flow

```
MediaTranscript.segments (absolute timestamps)
         │
         ▼
CaptionProjection::project(segments, clipStartMs, clipEndMs)
         │
         ▼
Projected segments (local timestamps) → consumed by Slice 3B SrtGenerator
```

## Acceptance Criteria

### AC-01: Pure Function Signature
- Static method `CaptionProjection::project(array $segments, int $clipStartMs, int $clipEndMs): array`
- Input: `$segments` = `array<int, array{start_ms: int, end_ms: int, text: string}>`
- Output: `array<int, array{local_start_ms: int, local_end_ms: int, text: string}>`
- No side effects, no I/O, no database, no FFmpeg

### AC-02: Algorithm Correctness (matches Slice 2 exactly)
- Empty input → empty output
- Segment completely before clip → excluded
- Segment completely after clip → excluded
- Partial overlap at clip start → `local_start_ms` clamped to 0
- Partial overlap at clip end → `local_end_ms` clamped to `clipDurationMs`
- Segment exactly at bounds → excluded (zero duration after clamping)
- Multiple segments with mixed overlap → correct filtering and clamping
- Deterministic — repeated calls with same inputs produce identical output
- Integer arithmetic only — no floating point operations

### AC-03: Edge Cases
- Zero-duration clip (`clipStartMs === clipEndMs`) → empty output
- Empty text segments preserved in output
- Large timestamp values handled correctly (int range)

## Security Considerations
- Pure function with no I/O — no security boundary concerns
- No user input directly processed — inputs come from persisted MediaTranscript

## UX Considerations
- None — this is an internal transformation function

## Test Scenarios

### TP-02: Segment Projection (matching Slice 2 TP-01 through TP-08)
| ID | Scenario | Input Segments | Clip Range | Expected Output |
|----|----------|----------------|------------|-----------------|
| TP-02a | Normal projection | `[{11200, 12600, "Hello"}]` | 10000-15000 | `[{1200, 2600, "Hello"}]` |
| TP-02b | Segment before clip | `[{5000, 6000, "Before"}]` | 10000-15000 | `[]` |
| TP-02c | Segment after clip | `[{20000, 21000, "After"}]` | 10000-15000 | `[]` |
| TP-02d | Partial overlap start | `[{9000, 11000, "Start"}]` | 10000-15000 | `[{0, 1000, "Start"}]` |
| TP-02e | Partial overlap end | `[{14000, 20000, "End"}]` | 10000-15000 | `[{4000, 5000, "End"}]` |
| TP-02f | Empty input | `[]` | 10000-15000 | `[]` |
| TP-02g | Determinism | Same as TP-02h | Same | 3 identical calls → identical output |
| TP-02h | Mixed segments | 5 segments (before, start-overlap, inside, end-overlap, after) | 10000-15000 | 3 segments (clamped start, normal, clamped end) |
| TP-02i | Zero-duration clip | `[{10000, 11000, "X"}]` | 10000-10000 | `[]` |
| TP-02j | Segment exactly at bounds | `[{10000, 10000, "X"}, {15000, 15000, "Y"}]` | 10000-15000 | `[]` |
| TP-02k | Empty text preserved | `[{11000, 12000, ""}]` | 10000-15000 | `[{1000, 2000, ""}]` |
| TP-02l | Integer arithmetic only | `[{10001, 10003, "X"}]` | 10000-15000 | `[{1, 3, "X"}]` (ints) |

## Dependencies
- Slice 2 (PR #80): Projection function — **COMPLETED/MERGED** (algorithm reference)
- PHP 8.2+ (already available)
- No new external dependencies

## Human-Gated Operations
- None required (no schema changes, no external API changes, no infrastructure changes)

---

# Evidence: Issue #81A — CaptionProjection Pure Function

## TDD: CAPTION_PROJECTION

### RED Phase

- [ ] **CaptionProjection tests**: Created `tests/Unit/Services/CaptionProjectionTest.php` — all 12 TP-02 tests fail (function not implemented)

### GREEN Phase

- [ ] **CaptionProjection**: Implemented `app/Services/CaptionProjection.php` — all 12 TP-02 tests pass

### REFACTOR Phase

- [ ] Code style: `vendor/bin/pint --dirty --format agent` — clean
- [ ] Static analysis: `phpstan analyse --level=5 app/Services/CaptionProjection.php` — clean
- [ ] No behavior-changing refactors needed

## Test Results

### Unit Tests (TP-02)

| Test Class | Tests | Pass | Fail | Notes |
|------------|-------|------|------|-------|
| CaptionProjectionTest | 12 | 0 | 0 | PENDING |

## Scope Verification

### In Scope (Implemented)
- [ ] `app/Services/CaptionProjection.php` — pure PHP projection matching Slice 2 algorithm
- [ ] `tests/Unit/Services/CaptionProjectionTest.php` — 12 test cases

### Out of Scope (Not Modified)
- [ ] SRT generation (Slice 3B)
- [ ] SRT storage (Slice 3C)
- [ ] Worker contract (Slice 3C)
- [ ] RenderMediaClip job (Slice 3D)
- [ ] End-to-end integration (Slice 3E)
- [ ] Worker rendering logic
- [ ] Database schema
- [ ] API endpoints
- [ ] Frontend/UI

## Tester Decision

Decision: PENDING

### Justification

[To be filled by Tester after independent validation]

## CI

Status: PENDING

GitHub Actions workflow run: [URL]

Required checks:
- [ ] `php artisan test --filter=CaptionProjectionTest` — PENDING
- [ ] `vendor/bin/pint --dirty --format agent` — PENDING
- [ ] `phpstan analyse --level=5` — PENDING

## Lifecycle State

`NO_ACTIVE_ISSUE` → `ISSUE_CREATED` → `BRANCH_CREATED` → `SPEC_READY` → `RED_VERIFIED` → `GREEN_VERIFIED` → `TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

Current: `SPEC_READY`