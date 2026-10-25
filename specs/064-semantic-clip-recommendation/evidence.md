# Evidence: Issue #64 — Model-Backed Semantic Clip Recommendation (M5)

## TDD: SEMANTIC_CLIP_RECOMMENDATION

### RED Phase

- [x] **Worker RED (operator-executed, authoritative)**: 6 tests collected, 0 passed, 6 failed, exit 1
  - `test_runtime_unavailable_never_returns_semantic_success` — BEHAVIORAL_RED
  - `test_fake_action_preserves_fake_provenance` — BEHAVIORAL_RED
  - `test_loader_is_pinned_local_only_and_cpu` — BEHAVIORAL_RED
  - `test_quantized_ties_use_m4_rank` — BEHAVIORAL_RED
  - `test_wrong_logit_count_rejects_entire_result[short]` — BEHAVIORAL_RED
  - `test_wrong_logit_count_rejects_entire_result[long]` — BEHAVIORAL_RED
  - Command: `~/issue64-tools/venv/bin/python -m pytest -q --tb=short tests/test_rank_clips.py::... tests/test_ranking.py::...`
- [x] **Laravel RED (corrective recovery)**: 8 cases, 7 BEHAVIORAL_RED, 1 PASS, 27 assertions, 67.348s
  - `test_no_audio_and_extraction_failure_ignore_stale_transcript` / `no_audio` — BEHAVIORAL_RED
  - `test_no_audio_and_extraction_failure_ignore_stale_transcript` / `extraction_failed` — BEHAVIORAL_RED
  - `test_completed_snapshot_binds_candidate_text_hashes` — BEHAVIORAL_RED
  - `test_not_ready_does_not_commit_ranking_or_finalize_owner` / `pending` — BEHAVIORAL_RED
  - `test_not_ready_does_not_commit_ranking_or_finalize_owner` / `transcribing` — BEHAVIORAL_RED
  - `test_not_ready_does_not_commit_ranking_or_finalize_owner` / `lock_contender` — PASS
  - `test_not_ready_does_not_commit_ranking_or_finalize_owner` / `exhaustion_owner_wins` — BEHAVIORAL_RED
  - `test_not_ready_does_not_commit_ranking_or_finalize_owner` / `exhaustion_unclaimed` — BEHAVIORAL_RED
  - Database preflight verified: environment `testing`, connection `pgsql`, database `aiclip_test_issue64`, PDO driver `pgsql`, SELECT 1 = 1

### GREEN Phase

- [x] **Laravel transport GREEN (partial)**: `ProcessMediaAction::rankClips` — streaming output bound (1 MiB), child termination on overflow, stderr discarded, no raw diagnostics propagated
  - `php artisan test --compact --filter=ProcessMediaActionRankClipsTest`: before 4 failed/24 assertions, after 0 failed/30 assertions, exit 0
  - Unit suite regression: before 4 failed, after 0 failed (1 passed unchanged)
- [x] `vendor/bin/pint --dirty --format agent` — Clean after refactor (comment fix in `ProcessMediaAction.php`)

### REFACTOR Phase

- [x] `ProcessMediaAction.php` comment updated: captured stderr no longer claimed as preserved; states sanitized message and exit code preserved with no raw cause chained
- [x] Reruns after refactor: unit suite identical (476 warnings, 1 passed, 1538 assertions); Feature suite identical (160 failed, 145 warnings, 482 assertions)

## Test Results

### Worker Tests (Blocked — Python/pytest denied by shell policy)

| Suite | Status | Notes |
|-------|--------|-------|
| `test_rank_clips.py` + `test_ranking.py` (6 cases) | 6 failed (RED) | Operator-executed; not re-runnable in this session |
| Full worker suite | BLOCKED | `python*` invocation denied; pytest/jsonschema not provisioned |

### Laravel Tests

| Suite | Before GREEN | After GREEN | Notes |
|-------|-------------|-------------|-------|
| `ProcessMediaActionRankClipsTest` | 4 failed, 24 assertions | 0 failed, 30 assertions | Four rank-clips cases fixed |
| Unit suite | 4 failed, 1 passed, 1532 assertions | 0 failed, 1 passed, 1538 assertions | Regression clean |
| Full suite | 164 failed, 1 passed, 2014 assertions | 160 failed, 1 passed, 2020 assertions | Remaining failures are environment-caused (missing `.env`, `APP_KEY`, unauthorized DB target) |

### Environment Blockers (Not Product Failures)

| Blocker | Impact |
|---------|--------|
| `apps/api/.env` absent; no `APP_KEY` | 113 `MissingAppKeyException` failures; bootstrap warning masks passing tests |
| `DB_CONNECTION=sqlite` in `phpunit.xml`; guards refuse target | 22 unauthorized DB + 8 setup blocker + 1 HealthTest failures |
| Python/pytest not provisioned; `python*` denied | All worker-side verification blocked |
| MinIO bucket not available | 4 skipped, unverified |

## Scope Verification

### In Scope (Implemented)

- [x] `apps/api/app/Services/ProcessMediaAction.php` — `rankClips`: 1 MiB output bound, streaming callback, child termination, stderr discard, fixed `Ranking failed` exception

### Out of Scope / Not Completed (Blocked)

- [ ] Worker `ranking.py`, `actions/rank_clips.py`, `contracts.py`, `media_processing_v1.json` — cannot verify without Python/pytest
- [ ] `config/media.php` `clip_ranking_prototype_query` — superseded query text not corrected
- [ ] `ClipRecommendationValidator` / `MediaClipRecommendation` model — missing `unavailable` status, `m4_analysis_id`, `outcome`, `reason`
- [ ] `ProcessMediaAsset` seven-state transcript precedence, K=0, local unavailable outcomes — requires disposable PostgreSQL 16
- [ ] M5 migration `2026_09_24_100000_create_media_clip_recommendations_table.php` — lacks `m4_analysis_id`, `outcome`, `reason`
- [ ] Real `ProcessMediaAction` → Python CLI → `FakeRankingProvider` → PHP validation → PostgreSQL persistence integration
- [ ] PostgreSQL concurrency/fencing matrix; #60 C1–C9 regressions
- [ ] Trust-boundary mutation datasets, canonicalization golden vectors, privacy assertions, real-model smoke, MinIO, frontend, Playwright, governance, pr-enforcement

## Tester Decision

Decision: PENDING

### Justification

[To be filled by Tester after independent validation]

## CI

Status: PENDING (GitHub Actions not yet executed for this branch)

GitHub Actions workflow run: [URL pending]

Required checks:
- [ ] Worker pytest (6 named corrective cases) — PENDING (blocked: Python/pytest unavailable)
- [ ] Laravel `ProcessMediaActionRankClipsTest` — PENDING (green locally, env warnings only)
- [ ] Full Laravel suite — PENDING (env-caused failures, not product defects)
- [ ] `vendor/bin/pint --dirty --format agent` — PENDING (clean locally)
- [ ] Disposable PostgreSQL 16 preflight — PENDING (not yet provisioned)

## Lifecycle State

`NO_ACTIVE_ISSUE` → `ISSUE_CREATED` → `BRANCH_CREATED` → `SPEC_READY` → `RED_VERIFIED` → `GREEN_VERIFIED` → `TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

Current: `RED_VERIFIED` (corrective recovery RED only; GREEN not authorized for full M5 scope)

## Bundle Completeness

The SDD bundle for Issue #64 is now complete with the 4 required files:

- `spec.md` — specification
- `plan.md` — implementation plan
- `test-plan.md` — test plan
- `evidence.md` — implementation evidence (this file)
