# M6.2 Slice 3B: SrtGenerator — SRT Format Generation

## Issue
**#81B** — feat(captions): implement SrtGenerator SRT format generation

## Branch
`@carlosegoulart/81B/feat/srt-generator`

## Summary
Implement a pure PHP `SrtGenerator` service in Laravel that converts projected caption segments (from Slice 3A `CaptionProjection`) into valid SRT (SubRip Subtitle) format. Stateless, deterministic, no side effects, no database, no FFmpeg, no external dependencies.

## Architecture Context
- **Slice 1 (PR #78)**: Worker supports optional `caption_file` in `render_clip` contract; FFmpeg burns captions via `subtitles=` filter.
- **Slice 2 (PR #80)**: Pure Python `project_segments_to_clip_local()` in worker.
- **Slice 3A (PR #83)**: Laravel `CaptionProjection::project()` matching Slice 2 algorithm.
- **Slice 3B (This Spec)**: Laravel-side SRT generation from projected segments. No integration, no storage, no worker contract.

## Scope

### In Scope
1. **SrtGenerator service**: New file `app/Services/SrtGenerator.php`
2. **SRT generation function**: `SrtGenerator::generate(array $segments): string`
3. **SRT format**: sequential indices starting at 1, timestamps `HH:MM:SS,mmm`, text line(s), blank line between entries
4. **Integer arithmetic only** — no floating point
5. **Deterministic** — same inputs → same outputs
6. **Unit tests**: `tests/Unit/Services/SrtGeneratorTest.php` covering all TP-03 scenarios

### Out of Scope
- Segment projection (Slice 3A)
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
CaptionProjection::project() (local timestamps)
         │
         ▼
SrtGenerator::generate(projectedSegments)
         │
         ▼
SRT string → consumed by Slice 3C StorageKeyBuilder / Slice 3D RenderMediaClip
```

## Acceptance Criteria

### AC-01: Pure Function Signature
- Static method `SrtGenerator::generate(array $segments): string`
- Input: `array<int, array{local_start_ms: int, local_end_ms: int, text: string}>`
- Output: valid SRT string
- No side effects, no I/O, no database, no FFmpeg

### AC-02: SRT Format Correctness
- Empty input → empty string
- Single segment → 1 SRT entry with index 1
- Multiple segments → sequential indices 1, 2, 3...
- Timestamp format: `HH:MM:SS,mmm` (comma, zero-padded)
- Each entry: index line, timestamp line, text line(s), blank line
- Multi-line text preserved
- Empty text segments produce valid entry with blank text line

### AC-03: Edge Cases
- Zero-duration segments excluded
- Large timestamp values handled correctly (int range)
- Deterministic — repeated calls with same inputs produce identical output

## Security Considerations
- Pure function with no I/O — no security boundary concerns
- SRT content derives from persisted MediaTranscript (user content); no secrets

## UX Considerations
- None — this is an internal transformation function

## Test Scenarios

### TP-03: SRT Generation (6 scenarios)

| ID | Scenario | Input Segments | Expected Output |
|----|----------|----------------|-----------------|
| TP-03a | Single segment | `[{1200, 2600, "Hello"}]` | 1 entry, index 1, valid timestamps |
| TP-03b | Multiple segments | 3 segments | sequential indices 1,2,3 |
| TP-03c | Empty text | `[{1000, 2000, ""}]` | valid entry, blank text line |
| TP-03d | Multi-line text | `[{1000, 2000, "Line1\nLine2"}]` | line breaks preserved |
| TP-03e | Timestamp format | `[{61000, 62000, "X"}]` | `00:01:01,000 --> 00:01:02,000` |
| TP-03f | Empty input | `[]` | empty string |

## Dependencies
- Slice 3A (PR #83): CaptionProjection — **COMPLETED/MERGED**
- PHP 8.2+ (already available)
- No new external dependencies

## Human-Gated Operations
- None required (no schema changes, no external API changes, no infrastructure changes)
