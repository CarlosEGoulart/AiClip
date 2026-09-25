# Test Plan: Issue #64 — Corrective Verification

## Evidence rules and test boundaries

This is a test specification, not test results. Preserve all existing dirty production/tests/evidence. Earlier original test-first claims are unestablished; new failures prove **corrective RED only** against the preserved implementation. Tests must execute to behavior assertions, not fail from missing imports/dependencies/migrations/drivers/permissions. Passing cases are controls, not RED. No automatic Builder implementation authorization follows SPEC_READY: the RED-only checkpoint must be reviewed first.

Use deterministic Python fake for mandatory end-to-end ranking and injectable lightweight model/loader stubs for real-adapter unit behavior. Neither installs/imports heavyweight ML in mandatory CI. Separate real-weight smoke verifies actual runtime; stub logits are not a real-model quality oracle. Laravel independently validates structure/authority/provenance, not neural score values.

Evidence belongs to executing agents in the existing evidence.md. Preserve history but supersede false conclusions explicitly; record corrective `### RED`, `### GREEN`, `### REFACTOR`, exact commands/exits/named assertions/observed-vs-expected values, passing controls, actual counts/skips/risky status, environment blockers, independent Tester decision and CI references. No raw transcript/prompt/model output/credentials/private paths/provider exceptions.

## Preflight before database assertions

Operator must authorize the named disposable PostgreSQL 16 database and isolated MinIO bucket; verify PostgreSQL driver/connectivity and test migration safety before running RefreshDatabase. Never use SQLite as concurrency evidence. Fixture setup must finish and legacy DB controls pass. Use committed fixtures visible to independent connections, explicit barriers/recording boundaries and bounded teardown; an outer test transaction hiding fixtures is invalid. Permission denial blocks execution, not evidence; no bypass.

## Initial RED-only assignment: eight exact behavioral tests

Suggested worker files are under `services/worker/tests/`; Laravel files under `apps/api/tests/`. These tests use existing entry points; add test-only doubles/helpers as necessary, not production scaffolding. Expected failures below are audit-based predictions until executed.

| Test | Fixture and exact behavior assertion |
|---|---|
| `test_runtime_unavailable_never_returns_semantic_success` (`test_rank_clips.py`) | Valid two-text-candidate request, select real profile; patch existing loader to raise ImportError with synthetic private sentinel. Action must return exact ranking_failed envelope, no ranking/recommendations and no sentinel in output/logs. Existing fake fallback should fail the assertion. |
| `test_fake_action_preserves_fake_provenance` (`test_rank_clips.py`) | Explicit fake selection, valid two candidates. Assert provider=fake, provider_name=fake_ranking_provider, model_id=fake-ranking-v1, model_revision=1.0.0, normalization=fixture_units_6, inference_performed=false, and exact fake score units. No CrossEncoder identity anywhere. Existing action hardcoding should fail. |
| `test_loader_is_pinned_local_only_and_cpu` (`test_ranking.py`) | Inject recording lightweight torch/numpy/Sentence Transformers loader modules; invoke existing loader without real downloads. Assert exact canonical model/revision, local_files_only=true, trust_remote_code=false, CPU, safetensors-only, Identity activation and configured cache; recorded parameters, not class existence, are the oracle. Missing runtime modules must not be the failure. |
| `test_quantized_ties_use_m4_rank` (`test_ranking.py`) | Existing provider with model stub logits [0.0000004,0.0] for candidate indexes [0,1], M4 ranks [2,1]. Both quantize to 500000 units; assert reference order [1,0], score [0.5,0.5], semantic ranks [1,2]. During RED helper may observe existing combined_rank field rather than fail on a renamed field. Existing pre-round sort should fail order. |
| `test_wrong_logit_count_rejects_entire_result` (`test_rank_clips.py`) | Two usable candidates; injected model returns [0.0], then [0.0,1.0,2.0]. Each must yield exact ranking_failed with no partial success; no zip truncation accepted. |
| `test_no_audio_and_extraction_failure_ignore_stale_transcript` (`Feature/Jobs/ProcessMediaAssetClipRecommendationTest.php`) | Data rows: authoritative no-audio, authoritative extraction failure, each with stale completed sentinel transcript and valid completed M4 K=2. Execute job with recording process boundary. Assert zero rankClips calls, durable unavailable reason matching row, two null-scored references, no sentinel in output/snapshots/logs, unchanged M4; extraction-failed asset stays failed. Observe call count before asserting new lifecycle fields so failure exposes current stale-text behavior. |
| `test_completed_snapshot_binds_candidate_text_hashes` (same Laravel suite) | Completed M4 and valid completed transcript; recording action double returns internally valid current success (not an invalid fixture). Execute job, then assert persisted text_hashes equal independently computed SHA256 of canonical candidate text, raw text absent, M4 unchanged. Initial RED isolates absent binding rather than new contract field mismatch; adapt fixture to final protocol in GREEN while preserving hash assertion. |
| `test_not_ready_does_not_commit_ranking_or_finalize_owner` (`Feature/Jobs/ProcessMediaAssetClipRecommendationConcurrencyTest.php`) | Committed completed M4 + pending/transcribing transcript; call job and observe DB from independent connection after controlled not-ready. Assert no worker, no committed ranking and no asset completion. With another connection holding recommendation lock, contender/exhaustion cannot write owner state. Verify actual 3-attempt/5s policy and serialized exhaustion only after release. Existing unlocked transition should expose behavioral failure. |

Keep existing successful legacy probe/analyze_clips contract and M4 snapshot tests as controls. A new malformed-input rejection already passing is not RED. For initial provider defects, use the existing accepted request shape and existing provider-selection test hook to reach inference/action serialization; a new final-contract rejection before the loader is reached does not prove the fallback/loader defect. Recording spies must demonstrate the intended boundary was reached. Update those fixture shapes to the final strict contract after authorization while retaining the same behavioral assertions. If an initial fixture cannot reach its behavior assertion because of another existing defect, report that actual defect and refine only the test fixture/helper; never claim import/setup failure as the intended RED.

**STOP after execution and report to Orchestrator.** No GREEN code until explicit approval of valid corrective failures. Tests may be staged to isolate behavior on the existing protocol before the new exact protocol, with the transition documented; assertions on business behavior must remain.

## Deterministic fixtures and score oracles

- Hand-built valid M4 candidates K=0,1,2,1000; cap+1 fails. Full M4 algorithm/version/criteria/source-scene/snapshot validity retained. No rederived modified boundaries.
- Raw logits [2,0,-1] -> units [880797,500000,268941]; [1000,-1000] -> [1000000,0] without overflow. Bool/string/nonfinite/wrong-shape/wrong-count rejected before normalization. Explicit Identity activation must prevent double sigmoid (0 must yield 0.5, not sigmoid(0.5)).
- Quantized ties fixture above; exact equal scores; raw unequal scores quantizing equal; no epsilon rule. Ranking uses score units, M4 rank, stable index. Input lists are not mutated.
- Fake scores: M4 ranks [2,1,3] -> chronological units [900000,1000000,800000], ranked indexes [1,0,2]. More than 11 ranks exercises zero-score ties; never label fake output real.
- Mixed candidates: three references, text only for indexes 0 and 2. Model called with two pairs only; two contiguous semantic ranks and index 1 unscored with null fields. Count always three.
- Canonicalization golden: segments containing ASCII tabs/newlines/multiple spaces, leading/trailing spaces, non-ASCII punctuation, combining code points, Unicode whitespace-only text. Expected exact UTF-8 bytes and hashes computed independently in tests. Preserve Unicode normalization/case. Reject other C0 controls/DEL/invalid UTF-8.
- Half-open windows: touching endpoints excluded, positive overlap included, crossing segment appears in both overlapping candidate projections; unrelated segments excluded. No fabricated word clipping. Exactly 16384 bytes accepted, 16385 rejected; 8 MiB request and 1 MiB response boundaries; 50000 transcript segments and one beyond.

## Worker unit, schema, action and CLI coverage

1. Same strict request accepted by schema/runtime; independent mutations for every required/unknown field recursively. Type mutants include Python bool-as-int, integral float for integer fields, numeric strings, null, object/list confusion, nonfinite numbers and duplicate JSON keys. Chronology, duration, index/rank permutations, M4 score type/value and configuration profile bound before provider invocation.
2. Provider selection fake/real/unknown/unset; no implicit environment fallback. Fake identity agreement between provider metadata, rank return and action envelope. Real runtime failure is ranking_failed even when fake is available. No fake/provider switch after input failure.
3. Loader spy verifies revision propagated to config/tokenizer/model, local-only/no remote code/safetensors/CPU/eager policy. Exact manifest mismatch/missing artifact fails closed. Model stubs prove finite raw logit validation, sigmoid once, quantization before sorting, one output per eligible candidate and no partial output. Runtime import/loader errors sanitized.
4. Direct worker requests require K>0 and at least one usable text. Zero/no-text local outcomes belong to Laravel; worker rejects them rather than pretending inference. Full output K exact references, eligible null/score distinctions, no omissions/duplicates/bounds/M4 score/rank mutation. Wrong provider result type, missing fields, ordering, provenance and nonfinite fields reject entire response.
5. CLI reads stdin only; argv JSON/file options rejected without echo. Success 0, invalid-contract 2, runtime 1, exact envelopes and one strict JSON object. Digest matches exact input bytes, not reserialized approximation. Reject overflow/trailing data/duplicate keys/unknown fields. Bounded stdout/stderr; no traceback/progress/private sentinel.
6. Patch socket/network/telemetry/media/FFmpeg/DB entry points to fail if used; fake path succeeds without heavyweight imports. Real loader stub permits only schema/cache reads. No raw input/model data in logging or exceptions. Capture stdout/stderr and chained exceptions with sentinels.
7. Preserve all old CLI/action/contract tests and real FFmpeg/PySceneDetect integration. No weakening legacy schema to accept new action.

## Laravel unit/feature coverage

### Authority, transcript matrix, and local outcomes

Exercise all seven spec states plus no-audio/extraction-failure with stale completed and malformed transcripts. Generic asset failure without extraction failure must not suppress valid text. Missing transcript while upstream active is not-ready, not terminal missing. Invalid completed transcript fails invalid_input before invocation; all selected segments validated, not just overlapping ones. Completed valid with no candidate overlap is unavailable/no_candidate_text. Whitespace-only projected text has the same outcome, distinct from completed_empty.

K=0 with validated completed M4 completes no_candidates locally, no worker/model/text reads, even with pending transcript; invalid duration/M4/config still fails. All terminal local outcomes retain exact K references (zero only for K=0), null semantic fields, correct reason, no inference flags, request_sha256=null, exact empty text hashes and unchanged upstream rows. Asset completion obeys four explicit resolution flags; failed extraction remains failed. No-audio/failed transcript do not corrupt M4.

### Transport and independent trust boundaries

Record actual argument-list command, stdin bytes, timeout at run() (not createProcess()), output cap and request digest. Fixed configuration validation precedes process creation. Full snapshot binds local M4 authority, transcript classification, projection version, canonical hashes and exact request digest. Raw transcript is absent from new snapshots, logging, exceptions, queued failure data and APIs; existing upstream transcript storage is not deleted.

Mutation datasets at both service validation and model completion: missing/extra keys, list/object mismatch, algorithm/version/provider/model/revision/runtime/query/projection changes, misleading inference flag, digest mismatch, missing/extra/duplicate/index-gap references, altered start/end/M4 score/rank, wrong semantic rank/order/tie, numeric strings/bools/nonfinite/out-of-range/extra-precision scores, null score on eligible input, score on ineligible input, K mismatch and fabricated empty list. Any malformed member rejects whole result, no partial persistence. Caller-forged snapshot fails fresh-authority comparison. Structurally valid alternative in-range neural scores are accepted; no PHP golden-score recomputation.

Test deliberate score oracle mutation at provider-stub boundary (e.g. double sigmoid/reversed mapping) to prove tests detect plausible wrong scores. Do not claim Laravel can detect all compromised-worker in-range fabrications; it enforces the documented trust boundary.

### Persistence, ownership, reuse and version conflicts

Disposable migration up/down; unique asset FK, M4 FK same-asset invariant, JSONB/casts/relations, owner isolation, cascade through asset/project/M4 deletion. Pending/result null defaults; legal transitions inside claim; failed retry clears only M5 data; expected failures commit, invalid transitions do not mutate. Result/model completion cannot bypass shared validation.

Completed and unavailable terminal reuse: zero worker calls, exact original fields/timestamps/error/selection preserved after rerun and later transcript change. Model/provider/algorithm/query/projection/runtime/scoring configuration change or M4 authority change -> version_conflict, no rewrite/new row/inference/success for new selection. Timeout-only change reuses old terminal result. Failed retry captures new valid selection. No new reanalysis endpoint or public recommendation serialization.

## PostgreSQL concurrency, fencing and failure matrix

Independent connections/processes and committed observations are mandatory, not sequential refreshes or SQLite. Use deterministic barriers, not sleeps alone. Assert worker calls and persisted raw data, not only row count.

| Scenario | Assertions |
|---|---|
| Two first creations | Both observe absence; unique arbitration; one durable row, one total worker invocation; loser rereads identical completed result |
| Two failed retries | Error cleared by lock owner only, no overlapping workers; first successful retry terminal; loser reuses |
| Competing different selection | After owner completes, contender gets version_conflict; cannot silently reuse under new provenance or overwrite |
| Existing-row and insert-conflict waits | Actual PostgreSQL SQLSTATE 55P03; bounded busy after rollback; owner/asset unchanged; no contender worker/finalization |
| Expected worker timeout/malformed output | Child terminated/bounded, atomic failed M5, no partial JSON, unchanged M4, serial retry succeeds |
| Unexpected exception and actual connection termination | Rollback leaves old pending/failed state exactly or no new row; sanitized retryable abort; no committed ranking; later retry works |
| Not-ready retries/exhaustion | Three attempts/5s, zero worker, no ranking commit; serialized exhaustion only for unclaimed pending/failed; no overwrite of locked/completed/unavailable/deleted state |
| Late callback/stale model instance | Callback acquires lock/rereads; terminal snapshot and terminal asset unchanged; no stale completion outside transaction |
| Delete before/during claim and empty reread | Safe no-op or serialized cascade; no orphan/resurrection/late successful write; query only after rollback on DB errors |
| Fresh projection | Change committed upstream state before claim acquisition; owner uses fresh authority, not pre-lock stale contract |
| Lock scope | Upstream probe/scenes/audio/transcription/M4 execute without M5 row lock; only bounded metadata stage holds it |
| Settings isolation | Derived lock_timeout set before all contended statements, correct actual timeout snapshot, restored after commit and rollback, other connections unaffected |

Run existing #60 C1–C9 regressions unchanged as well. M5 failures and callbacks may never alter M4 fields/timestamps. Do not use a compensating delete to simulate atomic rollback.

## Mandatory fake subprocess integration

Actual ProcessMediaAction -> Python CLI using explicitly configured Python fake -> PHP validation -> PostgreSQL model completion. Assert exact fake scores/reference order, fake metadata, no real inference flag, exact stdin digest and canonical text hashes. Include mixed input and malformed output controls via separate process-double tests. Spy/deny network and heavyweight imports. Missing CLI/dependency/DB is a failure/blocker, never skip. No PHP fake can replace this boundary and no real-adapter fallback may satisfy it.

## Separate real-model smoke (operator prepared, not mandatory CI)

Require the exact pinned local profile, CPU-only dependency closure/consistency check and immutable artifact manifest. Operator supplies isolated 2-core/2-GiB/4-GiB envelope with disabled inference network; no production data. Actual CrossEncoder loads verified safetensors/config/tokenizer and executes synthetic candidate pairs. Assert real identity/revision, inference_performed=true, finite bounded scores, exact references/ranks, deterministic sorting for repeated observed logits, and changed scores for meaningfully different synthetic passages. Do not assert an unmeasured audience relevance target or exact cross-hardware golden logits.

Record package versions, manifest digest, cold-load/inference durations and peak memory as measured facts, not prior estimates. Test absent/corrupt artifacts/runtime -> sanitized failure with no network/fake fallback. Resource/timeout failure is honest failure; no lowering candidate coverage silently. No raw input/prompt/results in evidence. If environment is unavailable, mark real smoke blocked; mandatory fake CI cannot establish real verification.

## Full regression and independent review

Run all baseline tests plus new tests; previous historical counts are not evidence and may differ. Required integrations cannot skip. Execute through approved entry points:

| Suite | Requirement |
|---|---|
| Worker | `python -m pytest tests/ -v` in services/worker, including existing real FFmpeg/PySceneDetect integration, no heavyweight ranking dependencies |
| Backend | `php artisan test --compact` in apps/api with approved disposable PostgreSQL; zero risky; all new DB/concurrency tests |
| MinIO | All existing real storage integration cases against authorized isolated bucket, actual execution |
| PHP style | `vendor/bin/pint --dirty --format agent`; rerun tests if formatting changes files |
| Frontend | `npm run test -- --maxWorkers=1`, `npm run lint`, `npm run build` in apps/web |
| Playwright | `npm run test:e2e` with managed servers/PostgreSQL/Chromium |
| Governance | Existing approved `python -m unittest discover -s tests/governance` by authorized role, unchanged; not a merge-gate invocation |

Tester independently exercises CLI/DB/malformed-success/timeouts/retries/unavailable/empty/concurrency outcomes, verifies scope/privacy/owner isolation and current docs. Running-app auth/projects/media upload/list/delete at 390x844, 768x1024, 1440x900 remains mandatory: screenshots/layout/overflow, loading/empty/error/success/disabled states, keyboard/focus/accessibility, console/network/API/responses/resources/redirects. Unexpected console errors or unexpected 4xx/5xx reject. No new recommendation screen exists; new-screen visual review alone is N/A, not the regression workflow.

Independent Tester approval and real integration evidence precede lifecycle completion. Final-head Backend CI, Frontend CI, E2E CI, governance, pr-enforcement and their actual logs must pass. Stop `CI_GREEN_WAITING_HUMAN_MERGE`; no merge gate/merge/closure/next issue authorized.

SPEC_READY
