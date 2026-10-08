# M6.2 Slice 3: Connect Laravel Clip Context to Worker for Caption Flow — SUPERSEDED

## Issue
**#81** — feat(captions): connect Laravel clip context to worker

## Status: SUPERSEDED (Coordination Document)

This specification has been decomposed into atomic slices. Do not implement against this spec. Implement against the atomic slice specs instead.

## Sub-Slice Status Table

| Sub-Slice | Issue | Spec Path | Status | Branch |
|-----------|-------|-----------|--------|--------|
| Slice 3A | #81A | `specs/081A-m6-2-slice3a-caption-projection/` | **SPEC_READY** | `@carlosegoulart/81A/feat/caption-projection` |
| Slice 3B | #81B | `specs/081B-m6-2-slice3b-srt-generator/` | **PLANNED** | `@carlosegoulart/81B/feat/srt-generator` |
| Slice 3C | #81C | `specs/081C-m6-2-slice3c-storage-contract/` | **PLANNED** | `@carlosegoulart/81C/feat/storage-contract-caption` |
| Slice 3D | #81D | `specs/081D-m6-2-slice3d-render-job-integration/` | **PLANNED** | `@carlosegoulart/81D/feat/render-job-caption-integration` |
| Slice 3E | #81E | `specs/081E-m6-2-slice3e-e2e-integration/` | **PLANNED** | `@carlosegoulart/81E/test/caption-flow-e2e` |

**Status Definitions:**
- `SPEC_READY` — Specification complete, ready for Builder authorization
- `PLANNED` — Specification exists but not yet authorized for implementation
- This table reflects REAL state after each sub-slice checkpoint (not pre-filled)

## Branch
`@carlosegoulart/81/feat/laravel-worker-caption-flow`

## Coordination Notes
- Slice 3A is the only slice currently authorizable for implementation
- Subsequent slices (3B-3E) will be authorized sequentially after prior slice reaches `MERGED` + `ISSUE_CLOSED`
- This document is updated by Planner at each sub-slice checkpoint (SPEC_READY, RED_VERIFIED, GREEN_VERIFIED, TESTER_APPROVED, MERGED, ISSUE_CLOSED)

## Summary
This slice connects the Laravel application layer to the worker's caption rendering capability. Laravel reads transcript segments from `MediaTranscript.segments` for the source media asset, projects them to clip-local time using the same algorithm as Slice 2 (`project_segments_to_clip_local`), generates an SRT caption file, stores it on the storage disk, and passes the `caption_file` path to the worker's `render_clip` contract. The worker (Slice 1) burns captions via FFmpeg's subtitles filter.

## Architecture Context
- **Slice 1 (PR #78, spec 076)**: Worker supports optional `caption_file` in `render_clip` contract; `FFmpegVerticalClipRenderer` adds `subtitles=` filter when provided.
- **Slice 2 (PR #80, spec 079)**: Pure Python `project_segments_to_clip_local()` function in worker; projects absolute transcript timestamps to clip-local time.
- **Slice 3 (This Spec)**: Laravel side integration — fetch transcript, project segments, generate SRT, store file, pass to worker.

## Scope

### In Scope
1. **Fetch transcript**: In `RenderMediaClip` job, load `MediaTranscript` for the `MediaAsset` (via `media_asset_id`).
2. **Project segments to clip-local**: Implement projection logic in Laravel matching Slice 2 algorithm:
   - Input: `segments[]` with `{start_ms, end_ms, text}`, `clip_start_ms`, `clip_end_ms`
   - Output: `segments[]` with `{local_start_ms, local_end_ms, text}` clamped to `[0, clip_duration_ms)`
   - Discard segments with zero/negative duration after clamping
   - Pure integer arithmetic, deterministic, no side effects
3. **Generate SRT**: Convert projected segments to valid SRT format:
   - Sequential indices (1, 2, 3...)
   - Timestamp format: `HH:MM:SS,mmm --> HH:MM:SS,mmm`
   - Text lines preserved as-is
   - Blank line between entries
4. **Store SRT file**: Save to storage disk using project-scoped key pattern:
   - Key: `projects/{project_id}/captions/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.srt`
   - Disk: same as source media (`asset->storage_disk`)
   - MIME type: `application/x-subrip`
5. **Pass to worker**: Extend `MediaProcessingContract::renderClipRequest()` to include optional `caption_file` field in returned array when transcript exists and has segments overlapping the clip.
6. **Worker contract**: `caption_file` is already defined as optional in `render_clip_request` schema (Slice 1).

### Out of Scope
- Changes to worker rendering logic (Slice 1 complete)
- Changes to projection function (Slice 2 complete)
- Database schema changes (MediaTranscript already exists)
- New API endpoints
- UI changes
- Multiple caption tracks/languages (single transcript per asset for now)
- Caption styling/customization (uses FFmpeg defaults)

## Data Flow

```
RenderMediaClip Job
       │
       ▼
MediaTranscript::where('media_asset_id', $asset->id)
       │
       ▼
Project segments to clip-local (candidate.start_ms, candidate.end_ms)
       │
       ▼
Generate SRT content from projected segments
       │
       ▼
Storage::disk($asset->storage_disk)->put($srtKey, $srtContent)
       │
       ▼
MediaProcessingContract::renderClipRequest(..., $captionFilePath)
       │
       ▼
ProcessMediaAction::renderClips() → Worker (stdin JSON)
       │
       ▼
Worker: FFmpegVerticalClipRenderer.render_singular(caption_file=...)
       │
       ▼
FFmpeg: -vf "...,subtitles=caption_file"
```

## Acceptance Criteria

### AC-01: Transcript Fetching
- When a `MediaTranscript` exists for the `MediaAsset` with `status = 'completed'`, the job reads its `segments` array.
- When no transcript exists or status is not `completed`, caption flow is skipped (no `caption_file` passed to worker).
- When transcript exists but `segments` is empty, caption flow is skipped.

### AC-02: Segment Projection
- Laravel implements projection logic matching `project_segments_to_clip_local` exactly:
  - `local_start = max(0, segment.start_ms - clip_start_ms)`
  - `local_end = min(clip_duration_ms, segment.end_ms - clip_start_ms)`
  - Discard if `local_end <= local_start`
- Pure integer arithmetic only (no floating point).
- Deterministic: same inputs → same outputs.

### AC-03: SRT Generation
- Valid SRT format with sequential indices starting at 1.
- Timestamps in `HH:MM:SS,mmm` format (comma for milliseconds).
- Each entry: index, timestamp line, text line(s), blank line.
- Handles multi-line text (preserves line breaks in segment text).
- Empty text segments produce valid SRT entry with blank text line.

### AC-04: SRT Storage
- SRT file stored on same disk as source media (`asset->storage_disk`).
- Key follows pattern: `projects/{project_id}/captions/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.srt`
- File is readable by worker (local path or accessible via Flysystem).
- Cleanup: SRT file persists (cheap, small) — no automatic deletion.

### AC-05: Worker Contract Integration
- `MediaProcessingContract::renderClipRequest()` returns `caption_file` key in output array when:
  - Transcript exists and is completed
  - Projected segments list is non-empty
- `caption_file` value is the storage key (path) — worker resolves to local path.
- When no caption data, `caption_file` is omitted from contract (not null).

### AC-06: End-to-End Render
- Worker receives `caption_file` in contract.
- Worker passes to `FFmpegVerticalClipRenderer.render_singular(caption_file=...)`.
- FFmpeg burns subtitles via `subtitles=` filter.
- Output video has captions rendered.

## Security Considerations
- SRT files contain transcript text (user content). Storage uses same disk/permissions as source media.
- No secrets in SRT files.
- Worker receives file path only — no transcript content crosses worker boundary beyond what's in SRT.
- Path traversal: SRT key generated via `StorageKeyBuilder` pattern (UUID), not user input.

## UX Considerations
- Captions appear automatically when transcript exists.
- No user action required to enable captions.
- If transcript missing, clip renders without captions (graceful degradation).

## Test Scenarios

### TP-01: Transcript Fetching
- **TP-01a**: MediaAsset has completed MediaTranscript → segments fetched
- **TP-01b**: MediaAsset has no MediaTranscript → no caption_file
- **TP-01c**: MediaTranscript status != completed → no caption_file
- **TP-01c**: MediaTranscript segments empty → no caption_file

### TP-02: Segment Projection (matching Slice 2 TP-01 through TP-08)
- **TP-02a**: Normal projection within clip bounds
- **TP-02b**: Segment completely before clip → excluded
- **TP-02c**: Segment completely after clip → excluded
- **TP-02d**: Partial overlap at clip start → clamped to 0
- **TP-02e**: Partial overlap at clip end → clamped to clip_duration
- **TP-02f**: Empty input → empty output
- **TP-02g**: Determinism — repeated calls produce identical output
- **TP-02h**: Multiple segments with mixed overlap scenarios

### TP-03: SRT Generation
- **TP-03a**: Single segment → valid SRT with 1 entry
- **TP-03b**: Multiple segments → sequential indices, correct timestamps
- **TP-03c**: Segment with empty text → valid SRT entry with blank text
- **TP-03d**: Multi-line text in segment → preserved in SRT
- **TP-03e**: Timestamp format: HH:MM:SS,mmm (comma, zero-padded)
- **TP-03f**: Zero-duration clip → no SRT generated

### TP-04: SRT Storage
- **TP-04a**: File stored on correct disk
- **TP-04b**: Key matches expected pattern
- **TP-04c**: File content matches generated SRT
- **TP-04d**: MIME type is application/x-subrip

### TP-05: Worker Contract
- **TP-05a**: Contract includes caption_file when transcript has overlapping segments
- **TP-05b**: Contract omits caption_file when no transcript
- **TP-05c**: Contract omits caption_file when transcript has no overlapping segments
- **TP-05d**: caption_file value is storage key (string)

### TP-06: End-to-End Integration
- **TP-06a**: RenderMediaClip job completes with captions burned in output
- **TP-06b**: Output video duration matches clip duration (±50ms)
- **TP-06c**: Worker filter_graph includes `subtitles=` filter when caption_file provided

## Dependencies
- Slice 1 (PR #78): Worker caption burn-in — **COMPLETED/MERGED**
- Slice 2 (PR #80): Projection function — **COMPLETED/MERGED**
- MediaTranscript model exists with segments array
- Storage disk configured (local/S3)

## Human-Gated Operations
- None required for this slice (no schema changes, no external API changes)