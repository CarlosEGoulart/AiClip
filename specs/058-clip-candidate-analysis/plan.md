# Implementation Plan: Issue #58

## Planning inputs and scope guard

Read the live issue plus `spec.md` and `test-plan.md` before work. Planning inspected the required job, three upstream models, Laravel contract/action, worker CLI/schema, scene/transcription abstractions, worker contract/action implementations, API config, existing scene/transcript tests and migrations, #56 spec/test plan, PRD, architecture, roadmap, project-state, ADR 0002, and backend/frontend/E2E workflows. No real `.env`, application dependencies directory, shell command, or repository lifecycle operation was used by Planner.

Implement only deterministic M4 metadata analysis. Keep the documented AI `ClipRankingProvider` for M5; implement only `ClipCandidateAnalyzer` and its real baseline implementation now. Snapshot storage and whole-scene candidates are fixed planning decisions, not Builder options to redesign.

## Dependencies and human gates

- Orchestrator supplied the active issue, verified baseline, branch, and existing planning directory. #51/#53/#56 provide the required persistence/worker boundaries; no other implementation issue is authorized.
- Existing PHP/Composer and Python/pytest/jsonschema environments, PostgreSQL 16, queue execution, FFmpeg and real `scenedetect[opencv-headless]` integration dependencies must be available for regression tests. Analysis itself adds no package, model, GPU, network, S3 access, or external API dependency.
- Full verification also needs real MinIO with the existing test bucket, Node frontend toolchain, managed Laravel/Vite servers, Chromium, and available ports. Treat unavailable dependencies as a blocker, never valid RED or a reason to skip required suites.
- Migration execution against disposable test databases is verification. Production migration/deployment and production queue configuration changes require separate operator authorization; do not perform them here.
- Planner edits only its three planning documents. Builder writes application tests/implementation and its actual evidence. Tester independently reviews/executes and appends findings to the same evidence file. Orchestrator does not write tests or production code.
- Orchestrator owns authorized documentation reconciliation in `docs/project-state.md` and `docs/roadmap.md`, and any narrowly necessary architectural clarification. Record #56/PR #57 merged, #58 in progress. Preserve the six project-state headings. Do not mark M4 complete before merge; see conditional recommendation in the spec.
- Final-head Backend, Frontend, E2E, governance, and pr-enforcement checks and actual logs must be inspected. The lifecycle must stop at `CI_GREEN_WAITING_HUMAN_MERGE`. **Do not invoke any merge gate, merge, close the issue, or start another issue without new human authorization.**

## Expected application change surface (Builder only)

| Area | Expected changes |
|---|---|
| `services/worker/aiclip_worker/clip_analysis.py` | Typed input/configuration/candidate/result models, analyzer interface, real deterministic implementation, strict validators. |
| `services/worker/aiclip_worker/actions/analyze_clips.py` | Metadata action, validation, fixed privacy-safe error envelopes. No file/media/provider work. |
| `services/worker/aiclip_worker/cli.py`, `contracts.py` | New subcommand, safe bounded JSON handling, new branch validation without weakening legacy actions. |
| `services/worker/contracts/media_processing_v1.json` | Add discriminated metadata branch; preserve legacy envelope and scene-duration requirements. |
| `apps/api/app/Contracts/MediaProcessingContract.php` | Explicit metadata factory/serialization/preflight; omit legacy identifiers/storage for analysis only. |
| `apps/api/app/Services/ProcessMediaAction.php` | Bounded stdin-based `analyzeClips`; safe transport failure handling, no raw-output exceptions. |
| New PHP validator/service under `app/Services/` or existing contract conventions | Input projection, independent result/invariant validation, atomic row claim and analysis orchestration; keep job additions small. |
| New migration and `app/Models/MediaClipAnalysis.php`; `MediaAsset.php` | Unique cascade FK, snapshot fields/casts, relationship, validated lifecycle. |
| `apps/api/config/media.php` | Expanded duration/limit/weight defaults and timeout. No real `.env` edits. |
| `apps/api/app/Jobs/ProcessMediaAsset.php` | Clip stage after upstream resolution, `clipAnalysisResolved`, bounded not-ready handling, safe retry/probe reuse, extraction-failure finalization. |
| Worker and Laravel application tests/fixtures | New behavior coverage and necessary existing mock fixture updates, preserving assertions. |

Exact helper filenames may follow repository conventions; their responsibilities and boundaries may not change silently. Do not create candidate CRUD, rendered entities, worker database clients, duplicate ranking providers, a generic workflow engine, or a new queue infrastructure layer. No frontend changes are expected. No `.opencode/**`, `tests/governance/**`, `scripts/merge_gate.py`, or governance workflow edits. Existing workflows already discover the new worker/backend tests; no CI changes are expected.

## Phase 1 — Test-only Builder pass; verify genuine RED

Orchestrator first delegates **tests only**. No production schema/model/configuration scaffolding is authorized in this pass.

Use existing entry points so missing future modules/tables are not the alleged RED:

1. Worker: call existing `validate_contract()` with a valid privacy-minimal `analyze_clips` request. Assert acceptance; current implementation rejects it because the action/schema is missing. Also retain representative valid legacy contracts as positive controls.
2. Laravel: exercise existing `ProcessMediaAsset::handle()` with persisted valid probe/completed scene data, no audio, and a non-production ProcessMediaAction double. Assert the new worker invocation/result behavior using a recording test double and expected golden metadata. Current job never requests analysis: a real call-count/output assertion fails. Do not depend on a nonexistent model/table to establish RED. A test-only subclass may define the future boundary method to record calls without changing production.
3. Add a real CLI black-box expectation if useful, but an argparse/import error alone is not the required RED. The contract and orchestration assertion failures above provide the behavioral evidence.
4. Execute the targeted tests, record exact commands, failing assertion names, observed/expected values, and why failure is missing feature behavior. Passing controls prove the environment is usable.
5. Stop and report to Orchestrator. Orchestrator reviews RED and explicitly authorizes implementation only after genuine assertion-based failures are verified. If failures are syntax/import/dependency/database-setup errors, repair only the test environment/tests and rerun; do not proceed on false RED.

Additional unit/model/concurrency tests are written before their corresponding production behavior as the boundaries become available. Missing imports/tables/methods are never substituted for a demonstrated behavior failure. Keep one issue evidence file at `specs/058-clip-candidate-analysis/evidence.md`, written by the executing Builder/Tester with exactly the required phase headings and actual results.

## Phase 2 — Worker contract and deterministic algorithm

1. Write tables/datasets for strict schema/model validation and the golden examples. Implement the additive action branch with `action` discrimination; all old contracts retain their existing validation behavior. Prevent validators from leaking values through JSON Schema messages in the new action.
2. Build one reusable typed input/result model layer. Enforce strict integer types explicitly; Python `bool` is an `int` subclass and Draft-07 may accept integral floats. Reject them at this boundary. Independently enforce ordering, source references, duration bounds, and score/rank invariants.
3. Implement the exact whole-scene eligibility, integer half-up quantization, duration/speech/boundary formulas, effective weights, deterministic top-K/tie-break, chronological indexes, and full versioned parameters. No path-hash “deterministic fake” is appropriate for production analysis.
4. Implement CLI metadata dispatch and action. Validate before invocation and before success serialization; input/output strict JSON, byte/count bounds, fixed error codes/messages, stdout protocol only. Laravel uses stdin so large timing lists and private metadata are not embedded in process arguments.
5. Do not instantiate transcribers/detectors or import heavyweight libraries as part of analysis. Regression tests must prove legacy scene/transcription behavior still works.
6. Verify worker tests, including different seeds/process runs and text/identifier independence. Preserve all 208 baseline worker cases and the real PySceneDetect tests.

## Phase 3 — Laravel trust boundary and snapshot persistence

1. Write migration/model/relation/lifecycle and strict validator tests first. Generate Laravel artifacts using framework conventions. Add one unique cascading media foreign key, no separate ownership or rendered Clip table.
2. Capture the authoritative scene/timing/configuration projection with no raw contract or text. Store it as a bounded private input snapshot; store operational timeout/lock settings separately. Validate upstream timing even when marked completed.
3. Implement a PHP validator shared by service and model completion. Test missing/unknown shapes/fields, all candidate invariants, exact parameter agreement, independent formula recomputation/top-K/ranking, empty-result legitimacy, and rollback on malformed success. Preserve object versus list information across JSON decode.
4. Extend `MediaProcessingContract` through an explicit metadata branch; do not initialize/populate the legacy storage/project fields for it. Preflight must occur before process creation. Incompatible versions and extra nested privacy fields fail closed.
5. Add `ProcessMediaAction::analyzeClips` with configured timeout and stdin JSON. Do not use the current raw `fromWorkerOutput()` exception path for untrusted analysis text; use fixed failure messages/codes with empty stderr and no unsafe previous exception attached.
6. Enforce lifecycle through the model/completion service; clear error when starting a failed retry, atomically complete all result fields, and leave completed snapshots untouched on every rerun/configuration change.

## Phase 4 — Database claim and concurrency

1. Write PostgreSQL-backed concurrent first-create and concurrent retry tests using independent connections/processes and an explicit barrier/recording worker boundary. An in-memory mutex or sequential model refresh is insufficient evidence.
2. Insert/reread the unique pending row, then acquire its transaction-scoped `FOR UPDATE` lock. Re-read status after locking. Wrap only the bounded clip stage, not upstream media work. Configure the local lock wait to worker timeout plus 5 seconds; prevent settings leaking to unrelated transactions.
3. Keep analyzing transition, worker invocation, validation, and completion/failure within that transaction. Commit expected sanitized failures; rollback unexpected aborts. Ensure failed retries are serial, not two workers racing for the same result.
4. On completed-row acquisition return terminal reuse. On lock contention timeout return busy without modifying state. A failed transaction cannot leave committed analyzing or partial snapshot data.
5. Exercise worker timeout, caller exception, simulated killed/rolled-back connection, cascade/delete races, and queue exhaustion. Verify existing queue retry/failure handling resolves the durable pending/failed row; never create a late competing result or overwrite a completed analysis.

No lease token/recovery scheduler is introduced: the lock itself is the claim and its release/rollback is owned by PostgreSQL. If implementation cannot preserve this transaction-and-timeout design, return to Planner rather than silently switching concurrency models.

## Phase 5 — Job integration

1. Add orchestration tests covering every readiness/failure row in the spec before job changes. Update only necessary existing test doubles to expect the added action using valid explicit results; do not turn mocks into permissive success defaults.
2. Read persisted scene/transcript results after the independent upstream stages. Use completed timing when available; absent/failed/no-audio transcription is omitted, not simulated. Failed extraction also makes timing unavailable, even if a stale transcript row exists. Invalid completed timing selected for enrichment fails the clip stage.
3. Add `clipAnalysisResolved` and make final normal asset completion require all three resolved flags. Only actual completed/failed child state resolves analysis. Control not-ready and busy separately from success.
4. Preserve audio extraction's asset-failed outcome while allowing scene-only clip analysis before return. Preserve scene-failure independence from transcription. Never alter completed upstream rows because clip analysis failed.
5. Reuse authoritative persisted probe on retries/terminal asset reruns; no unpersistable re-probe data. Retry failed analysis without resetting completed/failed asset states. Reuse completed snapshots even when configuration/transcription changes.
6. Retain three-attempt bounded queue behavior for upstream not-ready inputs; ensure exhaustion has sanitized terminal failure and no stranded processing. Failure callbacks must respect row ownership and completed terminal data. A busy duplicate leaves the owning job in charge.
7. Run targeted pipeline tests and the whole Laravel suite, zero risky. Run at least one real PHP-to-Python metadata action test with a golden input and PostgreSQL persistence, in addition to process fakes.

## Phase 6 — GREEN, REFACTOR, independent review

Builder runs all targeted/new tests and full baseline suites, Pint, frontend lint/build, real MinIO and PySceneDetect integration, and all E2E/governance regressions. Record exact totals, assertions, skips/risky status, environment, and failures honestly. No expected count is a substitute for execution.

Refactor only within the issue: small dedicated validators/orchestration helpers are preferable to expanding the existing job with another large inline parser. Rerun affected and full verification after refactor. Do not opportunistically alter older stage contracts or logging.

Tester independently reviews the spec-to-code mapping, concurrency under PostgreSQL, actual CLI/process behavior, privacy/error paths, all regressions, running UI/API/browser behavior, and out-of-scope changes. Reject and route defects to Builder; Tester does not repair production code. UI addition is N/A, but running-app regression review is not N/A.

## Handoff and final gates

Return implementation/testing status to Orchestrator with exact files, tests, unresolved risks, and the single evidence location. Orchestrator separately performs the issue's authorized docs and lifecycle coordination. Do not record merge/closure evidence in advance. M4 foundation completion is a post-merge recommendation only.

Before final human stop: verify issue/spec/plan/test-plan/documentation agree; exact `### RED`, `### GREEN`, `### REFACTOR` evidence exists; Independent Tester approved; final-head checks and logs are green; required real integrations executed; no protected control-plane modifications; no skipped/weakened required assertions. Stop without any merge-gate invocation or merge.
