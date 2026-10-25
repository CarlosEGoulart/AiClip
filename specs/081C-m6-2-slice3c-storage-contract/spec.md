# M6.2 Slice 3C: Storage Contract — Caption File Storage Key & Worker Contract

## Issue
**#81C** — feat(captions): implement caption storage key builder and worker contract

## Branch
`@carlosegoulart/81C/feat/storage-contract-caption`

## Summary
Implement the storage and contract layer for caption files in the M6.2 caption flow. Adds `StorageKeyBuilder::captionFile()` for deterministic S3 key generation, extends `MediaProcessingContract::renderClipRequest()` with an optional `caption_file` field, and updates the worker schema `media_processing_v1.json` to include `caption_file` in `render_clip_request`. Stateless where possible; no database schema changes, no FFmpeg, no UI.

## Architecture Context
- **Slice 1 (PR #78)**: Worker supports optional `caption_file` in `render_clip` contract; FFmpeg burns captions via `subtitles=` filter.
- **Slice 2 (PR #80)**: Pure Python `project_segments_to_clip_local()` in worker.
- **Slice 3A (PR #83)**: Laravel `CaptionProjection::project()`.
- **Slice 3B (PR #85)**: Laravel `SrtGenerator::generate()`.
- **Slice 3C (This Spec)**: Storage key builder + worker contract integration for caption files.

## Scope

### In Scope
1. **StorageKeyBuilder::captionFile()**: New method returning deterministic S3 storage key for caption files
   - Key pattern: `projects/{project_id}/captions/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.srt`
   - Disk: same as source media (`asset->storage_disk`)
   - MIME type: `application/x-subrip`
2. **MediaProcessingContract::renderClipRequest()**: Extended to accept and return optional `caption_file`
3. **Worker schema `media_processing_v1.json`**: Add `caption_file` field to `render_clip_request` schema (optional)
4. **Unit tests** for `StorageKeyBuilder::captionFile()` and contract changes

### Out of Scope
- Segment projection (Slice 3A)
- SRT generation (Slice 3B)
- RenderMediaClip job wiring (Slice 3D)
- End-to-end integration (Slice 3E)
- Database schema changes
- API endpoints
- UI changes
- Worker rendering logic (Python)
- FFmpeg invocation
- Multiple caption tracks/languages
- Caption styling/customization

## Data Flow

```
SrtGenerator output (SRT string)
         │
         ▼
StorageKeyBuilder::captionFile() → deterministic S3 key
         │
         ▼
Storage::disk($asset->storage_disk)->put($key, $srtContent)
         │
         ▼
MediaProcessingContract::renderClipRequest(..., caption_file=$key)
         │
         ▼
Worker receives caption_file in render_clip_request
```

## Acceptance Criteria

### AC-01: Caption Storage Key
- `StorageKeyBuilder::captionFile()` returns string key
- Key pattern: `projects/{project_id}/captions/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.srt`
- UUID-based, not user input (no path traversal)
- Deterministic given same inputs (except UUID suffix)

### AC-02: Worker Contract
- `MediaProcessingContract::renderClipRequest()` returns `caption_file` key when caption data present
- `caption_file` value is the storage key (string)
- When no caption data, `caption_file` omitted from contract (not null)

### AC-03: Worker Schema
- `media_processing_v1.json` `render_clip_request` includes optional `caption_file` field
- Schema remains backward compatible (caption_file optional)

### AC-04: Unit Tests
- `StorageKeyBuilderTest` covers captionFile key pattern
- Contract tests cover caption_file presence/omission

## Security Considerations
- SRT files contain transcript text (user content). Storage uses same disk/permissions as source media.
- No secrets in SRT files.
- Worker receives file path only.
- Path traversal: SRT key generated via UUID pattern, not user input.

## UX Considerations
- Captions appear automatically when transcript exists.
- No user action required.
- If no transcript, clip renders without captions (graceful degradation).

## Test Scenarios

### TP-04: Caption Storage Key
| ID | Scenario | Expected |
|----|----------|----------|
| TP-04a | Key matches expected pattern | `projects/{pid}/captions/{aid}/{ci}/{rpv}/{uuid}.srt` |
| TP-04b | UUID-based, not user input | No path traversal characters |
| TP-04c | MIME type | application/x-subrip |

### TP-05: Worker Contract
| ID | Scenario | Expected |
|----|----------|----------|
| TP-05a | Contract includes caption_file when caption present | key present, string value |
| TP-05b | Contract omits caption_file when no caption | key absent |
| TP-05d | caption_file value is storage key | string |

## Dependencies
- Slice 3B (PR #85): SrtGenerator — **COMPLETED/MERGED**
- StorageKeyBuilder class exists
- MediaProcessingContract class exists
- media_processing_v1.json schema exists

## Human-Gated Operations
- None required (no schema changes, no external API changes, no infrastructure changes)
