# Spec: Issue #87 — SRT Storage Keys and Worker Contract Extension (M6.2 Slice 3C)

## Issue
[#87](https://github.com/CarlosEGoulart/AiClip/issues/87) — feat(captions): SRT storage keys and worker contract extension

## Branch
`@carlosegoulart/87/feat/storage-contract-caption`

## Summary
Extend the Laravel storage layer and worker media-processing contract so an SRT caption file can be stored and referenced when requesting a clip render: `StorageKeyBuilder::captionFile()`, optional caption inputs on `MediaProcessingContract::renderClipRequest()` producing a `caption_file` payload key, and an optional `caption_file` field in the worker `render_clip_request` schema. No job wiring, no DB/API/UI changes, no worker rendering behavior changes.

## Architecture Context
- Slice 1 (PR #78): worker burns optional `caption_file` via FFmpeg `subtitles=` filter.
- Slice 2 (PR #80): Python `project_segments_to_clip_local()`.
- Slice 3A: `CaptionProjection::project()` — pure PHP projection.
- Slice 3B: `SrtGenerator::generate()` — pure PHP SRT generation.
- Slice 3C (this issue): storage key + contract + worker schema.
- Slice 3D (next): `RenderMediaClip` integration.
- Slice 3E (next): end-to-end coverage.

## Scope
### In Scope
1. `StorageKeyBuilder::captionFile(projectId, mediaAssetId, candidateIndex, renderProfileVersion)` returning `projects/{project_id}/captions/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.srt` with validation identical to `renderClip()`, fresh UUID per call, `.srt` extension.
2. `MediaProcessingContract::renderClipRequest()` optional params `$transcriptSegments`, `$clipStartMs`, `$clipEndMs`, `$disk`. When transcript provided and projection non-empty: project via `CaptionProjection::project()`, generate SRT via `SrtGenerator::generate()`, build key via `StorageKeyBuilder::captionFile()`, store via `Storage::disk($disk)`, include `caption_file` in payload. When absent or empty projection: omit the `caption_file` key entirely. Invalid inputs with transcript raise `ProcessMediaException`. Backward compatible.
3. `services/worker/contracts/media_processing_v1.json`: `render_clip_request.properties.caption_file` typed `["string","null"]`, optional.
4. Unit tests TP-04 (9 StorageKeyBuilder cases) and TP-05 (6 MediaProcessingContract cases).

### Out of Scope
Worker rendering behavior; database/API/UI; `RenderMediaClip` integration (Slice 3D); E2E (Slice 3E); caption styling/languages.

## Acceptance Criteria
- [x] AC-01: `captionFile()` returns the documented key pattern with `renderClip()`-identical validation.
- [x] AC-02: `caption_file` present only when projection non-empty; omitted otherwise.
- [x] AC-03: worker schema declares optional `caption_file` without version change.
- [x] AC-04: TP-04 (9) and TP-05 (6) pass; existing tests keep passing; full API suite passes.

## Security Considerations
Keys built from trusted identifiers plus UUID; no user-controlled path segments. SRT is transcript text stored with normal media permissions; no secrets. Worker receives only a path.

## UX Considerations
None — internal plumbing.

## Test Scenarios
TP-04: basic pattern, UUID uniqueness, project id, asset id, candidate index 0/>0, render profile version, `.srt` extension, large integers, validation parity.
TP-05: transcript in range (caption_file present, SRT stored), no transcript (absent), empty projection (absent), metadata consistency, non-empty string type, backward compatibility.

## Dependencies
Slices 3A and 3B merged; Laravel storage facade; existing StorageKeyBuilder/MediaProcessingContract.

## Human-Gated Operations
None.