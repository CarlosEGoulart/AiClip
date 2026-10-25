# M6.2 Slice 3D: Render Job Integration — Caption Flow in RenderMediaClip

## Issue
**#81D** — feat(captions): integrate caption flow into RenderMediaClip job

## Branch
`@carlosegoulart/81D/feat/render-job-caption-integration`

## Summary
Integrate the caption flow into the Laravel `RenderMediaClip` job: fetch completed `MediaTranscript` for the source `MediaAsset`, project segments to clip-local coordinates via `CaptionProjection`, generate SRT via `SrtGenerator`, store the caption file via `StorageKeyBuilder::captionFile()`, and include `caption_file` in the worker contract only when projection yields content. Backward compatible: `caption_file` omitted when no transcript, transcript status not completed, or empty projection.

## Architecture Context
- **Slice 1 (PR #78)**: Worker supports optional `caption_file` in `render_clip` contract; FFmpeg burns captions via `subtitles=` filter.
- **Slice 2 (PR #80)**: Pure Python `project_segments_to_clip_local()` in worker.
- **Slice 3A (PR #83)**: Laravel `CaptionProjection::project()`.
- **Slice 3B (PR #85)**: Laravel `SrtGenerator::generate()`.
- **Slice 3C (PR #88)**: `StorageKeyBuilder::captionFile()` + `MediaProcessingContract::renderClipRequest()` caption_file + worker schema.
- **Slice 3D (This Spec)**: `RenderMediaClip` job wiring the full caption flow.

## Scope

### In Scope
1. **RenderMediaClip job**: Fetch `MediaTranscript` for `MediaAsset` (via `media_asset_id`) when transcript exists and status is `completed`.
2. **Segment projection**: Project transcript segments to clip-local coordinates using `CaptionProjection::project()` matching Slice 2 algorithm (integer arithmetic, deterministic).
3. **SRT generation**: Convert projected segments to SRT via `SrtGenerator::generate()`.
4. **SRT storage**: Store caption file on same disk as source media using `StorageKeyBuilder::captionFile()` key pattern.
5. **Worker contract**: Extend `MediaProcessingContract::renderClipRequest()` to include `caption_file` only when projection yields non-empty in-range segments.
6. **Backward compatibility**: `caption_file` omitted when no transcript, status not completed, segments empty, or projection empty.

### Out of Scope
- Worker rendering logic (Python media worker, Slice 1 complete)
- Segment projection function (Slice 3A complete)
- SRT generation function (Slice 3B complete)
- Storage key builder / contract schema (Slice 3C complete)
- Database schema changes (MediaTranscript already exists)
- New API endpoints
- UI changes
- Multiple caption tracks/languages
- Caption styling/customization
- End-to-end integration tests (Slice 3E)

## Data Flow

```
RenderMediaClip Job
        │
        ▼
MediaTranscript::where('media_asset_id', $asset->id) (status completed)
        │
        ▼
CaptionProjection::project(segments, clipStartMs, clipEndMs)
        │
        ▼
SrtGenerator::generate(projectedSegments)
        │
        ▼
Storage::disk($asset->storage_disk)->put($srtKey, $srtContent)
        │
        ▼
MediaProcessingContract::renderClipRequest(..., caption_file=$srtKey)
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
- When `MediaTranscript` exists for `MediaAsset` with `status = 'completed'`, job reads its `segments` array.
- When no transcript exists or status is not `completed`, caption flow skipped (no `caption_file`).
- When transcript exists but `segments` empty, caption flow skipped.

### AC-02: Segment Projection
- Laravel projection matches `project_segments_to_clip_local` exactly:
  - `local_start = max(0, segment.start_ms - clip_start_ms)`
  - `local_end = min(clip_duration_ms, segment.end_ms - clip_start_ms)`
  - Discard if `local_end <= local_start`
- Pure integer arithmetic only.
- Deterministic: same inputs → same outputs.

### AC-03: SRT Generation
- Valid SRT format via `SrtGenerator::generate()`.
- Sequential indices starting at 1.
- Timestamps `HH:MM:SS,mmm`.

### AC-04: SRT Storage
- Stored on same disk as source media (`asset->storage_disk`).
- Key pattern: `projects/{project_id}/captions/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.srt`.
- Caption file persists (no automatic deletion).

### AC-05: Worker Contract Integration
- `caption_file` included in contract only when transcript completed and projected segments non-empty.
- `caption_file` value is storage key (string).
- When no caption data, `caption_file` omitted (not null).

### AC-06: End-to-End Render (job level)
- Job completes and passes `caption_file` to worker when applicable.
- Worker burns subtitles via FFmpeg `subtitles=` filter.

## Security Considerations
- SRT files contain transcript text (user content). Storage uses same disk/permissions as source media.
- No secrets in SRT files.
- Worker receives file path only.
- Path traversal: SRT key generated via UUID pattern, not user input.

## UX Considerations
- Captions appear automatically when transcript exists.
- No user action required.
- If transcript missing, clip renders without captions (graceful degradation).

## Test Scenarios

### TP-01: Transcript Fetching
| ID | Scenario | Expected |
|----|----------|----------|
| TP-01a | Completed transcript | segments fetched |
| TP-01b | No transcript | no caption_file |
| TP-01c | Status != completed | no caption_file |
| TP-01d | Empty segments | no caption_file |

### TP-02: Segment Projection
- Matches Slice 2 TP-01 through TP-08 scenarios (normal, before, after, partial overlap, empty, determinism, mixed).

### TP-04: SRT Storage
| ID | Scenario | Expected |
|----|----------|----------|
| TP-04a | File stored on correct disk | asset->storage_disk |
| TP-04b | Key matches expected pattern | projects/{pid}/captions/.../{uuid}.srt |
| TP-04c | MIME type | application/x-subrip |

### TP-05: Worker Contract
| ID | Scenario | Expected |
|----|----------|----------|
| TP-05a | caption_file when overlapping segments | present |
| TP-05b | caption_file omitted when no transcript | absent |
| TP-05c | caption_file omitted when no overlapping segments | absent |
| TP-05d | caption_file is storage key | string |

## Dependencies
- Slice 1 (PR #78): Worker caption burn-in — **COMPLETED/MERGED**
- Slice 2 (PR #80): Projection function — **COMPLETED/MERGED**
- Slice 3A (PR #83): CaptionProjection — **COMPLETED/MERGED**
- Slice 3B (PR #85): SrtGenerator — **COMPLETED/MERGED**
- Slice 3C (PR #88): StorageKeyBuilder + contract — **COMPLETED/MERGED**
- MediaTranscript model exists with segments array
- Storage disk configured (local/S3)

## Human-Gated Operations
- None required (no schema changes, no external API changes, no infrastructure changes)
