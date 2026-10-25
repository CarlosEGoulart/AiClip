# Test Plan: Issue #81C — Storage Contract — Caption File Storage Key & Worker Contract (Slice 3C)

## Scope

Validate `StorageKeyBuilder::captionFile()` key generation, `MediaProcessingContract::renderClipRequest()` caption_file handling, and `media_processing_v1.json` schema update defined by `spec.md` and `plan.md`. No FFmpeg, database writes beyond key generation, worker rendering, schema migrations, UI, or contract worker execution are part of this slice.

**Production files:** `app/Support/StorageKeyBuilder.php` (captionFile method), `app/Contracts/MediaProcessingContract.php`, `resources/schemas/media_processing_v1.json` (or equivalent)
**Test files:** `tests/Unit/Support/StorageKeyBuilderTest.php`, `tests/Unit/Contracts/MediaProcessingContractTest.php`

## RED — before implementation

1. Create test files with Pest behavioral tests for `captionFile()` and contract caption_file handling per TP-04 and TP-05 scenarios.
2. Run targeted tests; expect failures because method/field/schema do not exist or behave incorrectly.
3. Confirm test failure is a behavior assertion, not an environment error.
4. **Do not** add implementation, stubs, or weaken assertions. RED must be authentic.
5. Existing test suites must still pass.

## GREEN — after implementation

Implement per `spec.md` algorithm. Then run:

```bash
cd apps/api && php artisan test --filter=StorageKeyBuilderTest
cd apps/api && php artisan test --filter=MediaProcessingContractTest
cd apps/api && php artisan test
```

### TP-04 — Caption Storage Key (3 scenarios)

| ID | Scenario | Expected |
|----|----------|----------|
| TP-04a | Key matches expected pattern | `projects/{pid}/captions/{aid}/{ci}/{rpv}/{uuid}.srt` |
| TP-04b | UUID-based, not user input | No path traversal characters |
| TP-04c | MIME type | application/x-subrip |

### TP-05 — Worker Contract (3 scenarios)

| ID | Scenario | Expected |
|----|----------|----------|
| TP-05a | Contract includes caption_file when caption present | key present, string value |
| TP-05b | Contract omits caption_file when no caption | key absent |
| TP-05d | caption_file value is storage key | string |

## REFACTOR

Re-run all GREEN commands after any implementation refactor. Assertions must remain unchanged in meaning.

## Completion gate for this slice

- All slice tests (TP-04a-c, TP-05a,b,d) pass.
- Full Laravel test suite passes (no regression).
- Pint formatting clean.
- PHPStan level 5 clean on changed files.
- No out-of-scope files modified (no projection, no SRT generation, no job wiring, no UI).
- Independent Tester validates the running behavior via test output.
- Builder work complete; Orchestrator handles commit, push, PR, CI, merge, and issue closure separately.

## Quality Gates

| Check | Command | Required |
|-------|---------|----------|
| Focused unit tests | `php artisan test --filter=StorageKeyBuilderTest` | pass |
| Focused unit tests | `php artisan test --filter=MediaProcessingContractTest` | pass |
| Full backend suite | `php artisan test` | pass |
| Code style | `vendor/bin/pint --dirty --format agent` | clean |
| Static analysis | `phpstan analyse --level=5` on changed files | clean |

## Scope Verification Checklist

- [ ] Only StorageKeyBuilder captionFile, MediaProcessingContract caption_file, and media_processing_v1.json schema are new/modified.
- [ ] No migrations, routes, controllers, frontend, worker rendering, or storage write changes beyond key generation.
- [ ] No test weakening or skips.
- [ ] Schema remains backward compatible (caption_file optional).

## Independent Tester Requirements

Execute the commands above, record actual output, confirm tests fail before implementation (RED) and pass after (GREEN), confirm zero regression, inspect the diff for scope, record `Decision: APPROVE` or `Decision: REJECT` in `evidence.md`.

## Release Gate

TP-04 and TP-05 green, full suite green, Pint clean, PHPStan clean, Tester APPROVE, CI green, merge via `scripts/merge_gate.py`.
