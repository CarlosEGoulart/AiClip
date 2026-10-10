# Test Plan: Issue #87 — SRT Storage Keys and Worker Contract Extension (Slice 3C)

## Authority
Verifies `spec.md` AC-01..AC-04 for issue [#87](https://github.com/CarlosEGoulart/AiClip/issues/87). Pure PHP unit scope: no database, FFmpeg, MinIO, browser, or external services.

## 1. TP-04 — StorageKeyBuilder::captionFile() (AC-01)
Location: `apps/api/tests/Unit/StorageKeyBuilderTest.php`
- TP-04a basic generation matches `projects/{pid}/captions/{aid}/{idx}/{ver}/{uuid}.srt`
- TP-04b UUID uniqueness: identical inputs produce different keys
- TP-04c project id present
- TP-04d media asset id present
- TP-04e candidate index segment for 0 and >0
- TP-04f render profile version segment
- TP-04g extension `.srt`
- TP-04h large integers intact
- TP-04i invalid inputs throw as in `renderClip()`
Regression: existing `renderClip()` tests keep passing.

## 2. TP-05 — MediaProcessingContract::renderClipRequest() (AC-02)
Location: `apps/api/tests/Unit/MediaProcessingContractTest.php`
- TP-05a transcript in range: `caption_file` present, SRT written to `Storage::fake()` disk
- TP-05b no transcript: key absent
- TP-05c empty projection: key absent
- TP-05d `toRenderClipMetadataArray()` consistency
- TP-05e non-empty string type
- TP-05f backward compatibility
Regression: existing 15 cases keep passing.

## 3. Worker Schema (AC-03)
Valid JSON; `render_clip_request.properties.caption_file.type == ["string","null"]`; not in `required`; `render_clips_request` unchanged.

## 4. Quality Gates (AC-04)
| Check | Command | Required |
|-------|---------|----------|
| Focused unit tests | `cd apps/api && php artisan test --filter=StorageKeyBuilderTest` | pass (12) |
| Focused unit tests | `cd apps/api && php artisan test --filter=MediaProcessingContractTest` | pass (21) |
| Full backend suite | `cd apps/api && php artisan test` | pass (986) |
| Code style | `cd apps/api && vendor/bin/pint --dirty --format agent` | clean |
| Governance suite | `python -m unittest discover -s tests/governance -p 'test_*.py'` | pass |
| PR enforcement | `python tests/governance/pr_enforcement.py` (CI) | pass |

## 5. Scope Verification Checklist
- [ ] Only 5 implementation files changed (2 PHP source, 1 JSON schema, 2 PHP tests).
- [ ] No migrations, routes, controllers, frontend, or worker rendering logic changes.
- [ ] No test weakening or skips.

## 6. Independent Tester Requirements
Execute the commands above, record actual output, confirm the 15 new tests fail before implementation (RED) and pass after (GREEN), confirm zero regression, inspect the diff for scope, record `Decision: APPROVE` or `Decision: REJECT` in `evidence.md`.

## 7. Release Gate
TP-04 + TP-05 green, full suite green, Pint clean, governance green, Tester APPROVE, CI green, merge via `scripts/merge_gate.py`.