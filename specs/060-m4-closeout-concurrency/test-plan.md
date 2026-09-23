# Test Plan: Issue #60

## Execution prerequisites and evidence

This is a verification contract, not executed evidence. Apply `plan.md`'s free-model/runtime, permission and explicitly authorized disposable PostgreSQL/storage gates first. Before destructive setup or test execution verify `pdo_pgsql`, actual driver, `SELECT 1`, and `current_database()` against the approved #60 target in parent and child processes. #58 database authorization does not transfer. Missing infrastructure or mandatory skips block approval; no SQLite fallback or phpunit.xml edit.

Builder's test-only pass returns authentic executed behavior RED to Orchestrator before production changes. Tests that already pass are controls, not fabricated RED. Environment/syntax/import failures and missing test harness features are not valid production RED. Executing Builder/Tester maintain only one #60 `evidence.md` with `### RED`, `### GREEN`, `### REFACTOR`, exact commands/exits, assertion names and expected/observed values, test/assertion/skip/risky totals, repeat identifiers, safe environment identity and verdict. No raw credentials/contracts/process output or arbitrary exception text in evidence.

## Harness requirements

- Dedicated Pest Feature suite is discovered by existing backend CI, suggested `apps/api/tests/Feature/Jobs/ProcessMediaAssetClipAnalysisConcurrencyTest.php`, with test-only support scripts. Explicitly use real PostgreSQL and fail prerequisites rather than skipping.
- Do **not** attach `RefreshDatabase` or `DatabaseTransactions` outer transactions to this suite. Existing per-file conventions are unsafe for subprocess visibility. Prepare committed users/projects/assets/upstream snapshots on the authorized DB; assert transaction level zero and verify children can read fixture IDs before racing. Migrate only the approved disposable target; never manually commit framework-owned transactions. Clean this run's fixtures in finally/teardown after releasing barriers and joining processes; no concurrent broad-suite migration/reset.
- Use independent PHP processes with fresh connections, distinct PostgreSQL backend PIDs and a separate observing connection. Explicitly bind scenario action doubles rather than inheriting TestCase's generic probe mock. Production job/claim/model/validator logic is exercised unchanged by the test harness; worker doubles control only process/provider boundary responses.
- Synchronize with explicit IPC messages/barriers and bounded acknowledgments (unique per-run IDs). Record worker entry/exit/call count on an independent committed channel so rollback cannot erase invocation evidence. Do not let a counter lock serialize the application race accidentally. Observe actual blocking/lock conflict on known test PIDs before releasing owner. A contender must enter the production claim while the owner is held, not wait outside it until owner completion.
- PostgreSQL session advisory locks may support test synchronization with explicit unlock; transaction advisory locks release at transaction end and have no separate transaction-unlock operation. IPC is preferable where DB synchronization could mask contention. No invented PostgreSQL functions. Polling may have backoff and monotonic deadlines, but sleeps alone are not synchronization.
- Every wait/process has a deadline and finally cleanup. For timeout=1, expect lock bound=6 seconds; allow at most 5 seconds scheduling tolerance, with owner held until the contender outcome and an outer watchdog longer than 11 seconds. SQLSTATE/observed wait is essential, not timing alone. Other scenarios use bounded barriers and valid configurable timeouts sufficient for coordination. Kill only owned test processes/connections; any server-side termination requires the authorized test role's permission, not escalated production access.
- Use synthetic valid #58 golden fixtures and independently specified expected results. Persisted upstream raw values and timestamps are captured for preservation assertions. Record exactly one total worker invocation, not just maximum concurrent count, where completed reuse requires it.
- Builder and independent Tester each run the **entire C1–C9 suite plus E1/E2 three consecutive times** with fresh fixtures and processes. Report each run separately; any flaky/failing/skipped run fails acceptance and requires correction plus a new consecutive series. Full-suite totals do not substitute for these runs.

## Dedicated required scenarios

### C1 — First creation, real unique race and completed reuse

Commit ready asset/scenes with no analysis. Start two production job callers on independent processes; test instrumentation pauses both after they have observed absence and before either creates the row. Assert both absence observations, then release the first toward insert/worker; let the second attempt its insert **while the owner transaction is still held**. Observe the real unique-index conflict wait before permitting owner completion. Do not serialize callers at an external mutex. Assert one row, exactly one total worker invocation, correct complete metadata, loser locked reread/completed reuse, and unchanged result/error/timestamps on an additional reuse call. Confirm the real unique constraint remains present. Job handle may return void: persisted result plus per-process event/call records prove reuse, not an invented HTTP response.

### C2 — Concurrent failed retries

Commit a legally failed analysis and ready upstream data. Owner locks/transitions; test boundary observes analyzing with cleared error and no partial prior output. Contender enters actual claim and blocks, then owner completes. Assert same row identity, one worker call/no overlap, unchanged completed snapshot on contender and later retries. Outside readers never see newly committed analyzing. Include a serial retry after an owner failure in E1.

### C3 — Real lock timeout with zero contender mutation

Run two variants: preexisting pending row locked FOR UPDATE, and initially absent row with owner uncommitted insert producing a unique-conflict wait. Contender uses timeout=1 and locally derived 6-second bound. Hold owner via barrier until contender hits actual PostgreSQL SQLSTATE 55P03; record database-observed blocking plus elapsed bound (6 seconds with <=5 seconds scheduling allowance). Do not inject exceptions or mock lock calls. Contender returns internal busy/void-without-finalization with fixed sanitized diagnostic. Assert zero contender worker calls and no changes to owner's analysis, asset status/error/timestamps or upstream rows. Then release owner and verify successful complete result. Verify connection is usable after rollback; no “current transaction is aborted” recovery leak. Busy is not a persisted owner failure or successful resolution.

### C4 — Abort and connection termination

Dataset: prior pending, prior failed, and absent row. Use a test-only callback outside expected-worker-error conversion to raise an unexpected exception after analyzing transition/before commit. Separately terminate the owning PHP process/its PostgreSQL connection at the barrier; verify actual session disappearance and rollback. Outside observer sees no committed analyzing/candidates/input_snapshot; pending/failed raw durable state is exactly preserved (absent remains absent). A new queue delivery/claim then completes successfully. Worker count evidence survives rollback; no orphan child process. Also exercise failed finalization/exhaustion after an abandoned attempt with C5. A mocked exception caught as ordinary worker failure is E1, not crash proof.

#### C4 clarification checks and fixture regression

The inspected C4 currently injects `RuntimeException('synthetic_crash_after_transition')` from an analyzeClips override and checks pending status, prior failed error and absent row. This correctly distinguishes an unexpected PHP abort from a classified worker failure, but is not real crash/termination proof. Preserve those assertions; add full raw before/after snapshots (including timestamps and all result/input fields), absence of asset completion, observed analyzing/transaction entry at injection, and sanitized caller exception for each of the three calls. Catch/assert each exception locally so persistence assertions actually run. Verify the same outcomes for an unexpected throwable through the **real action's process boundary**, not only an overridden analyzeClips method; include Error and missing-interaction coverage without depending on raw Mockery messages.

Keep separate E1/E2 controls for actual Symfony timeout, nonzero exit, malformed JSON and declared contract/result validation failures: these commit sanitized FAILED, not abort. A generic RuntimeException is not a substitute timeout. Verify unexpected persistence/DB errors escape the claim rather than being converted inside it. Observe rollback before recovery queries. For both action/job aborts, synthetic sensitive sentinels must be absent from persisted fields, logs and exception/previous-chain/queue failure data.

Exercise actual queue redelivery after abort, successful subsequent claim, and bounded exhaustion without returning normal success on the aborted attempt. Exhaustion is a separate legal sanitized finalization, not evidence that the aborted transaction itself committed FAILED; verify completed/locked owner protection and terminal asset preservation. Retain separate real process/connection termination, external observation and new-row absence proof: best-effort placeholder cleanup after caught exception cannot satisfy a killed-process case.

For the six exact scene tests and four additional ready contexts listed in plan.md, explicit valid analyzeClips fixtures must preserve every existing assertion and upstream call expectation. Assert once-only invocation, projected context and completed analysis. Empty outputs must be genuinely eligible-empty with full provenance; one/two eligible-scene cases need correct candidates, criteria and ranks. Failed scene controls must not invoke clip worker. These fixture changes do not demonstrate production RED and must not be recorded as such.

Required resumption order: six tests individually/narrowly selected; entire `ProcessMediaAssetSceneDetectionTest.php`; `ProcessMediaAssetClipAnalysisTest.php` and action failure tests; full #60 C1 and C2–C9/E1/E2/V matrix; validator/completion tests; complete ProcessMediaAsset regression; Pint with post-format reruns. Then execute the existing full regression matrix and required three consecutive dedicated-suite runs, Orchestrator documentation reconciliation and independent Tester lifecycle. Record exact commands/counts and newly exposed failures rather than repeating historical 77/83 or 12/12 as current results. Runtime/permission failure is a blocker, not a reason to suppress aborts or skip required tests.

### C5 — Actual bounded queue attempts and exhaustion

Use the existing PostgreSQL-backed Laravel database queue on a unique test queue, committed serialized job and real queue worker execution, not Queue::fake/sync or a loop manually calling `failed()`. Explicit test config overrides select database queue without global config edits. Test worker bindings supply synthetic upstream/process boundaries without serializing mocks in the production job.

Keep upstream scenes pending/detecting; also cover active transcription readiness without rerunning it into fake readiness. Observe three actual attempts and 5-second release/backoff scheduling from real job/events/availability data; assert no fourth processing attempt, bounded worker termination and no infinite requeue. Attempts before exhaustion make no clip worker call or premature asset completion, and leave no committed unowned analyzing. Exhaustion commits `pending -> analyzing -> failed`, or `failed -> analyzing -> failed`, with `upstream_not_ready`, no partial result. Observe transitions via test instrumentation, not only final status. Nonterminal asset becomes failed; datasets for already-completed and already-failed assets preserve exact terminal state/error/timestamps. Upstream rows remain unchanged. Include rollback-before-retry/exhaustion and competing completed/locked-owner cases so finalization never overwrites the winner. A direct callback unit test complements but cannot replace this proof.

### C6 — Late failure callback and stale caller

Hold an obsolete job/callback instance with stale nonterminal asset attributes, complete a newer attempt, then invoke the actual old `failed()` path (upstream_not_ready and sanitized generic failure variants). Assert complete snapshot including provenance/error/timestamps AND asset complete status/error/timestamps are unchanged. Repeat with callback competing at the claim barrier so it must reread the winner after lock acquisition. Stale normal clip evaluation reuses completed result without worker. No direct raw-model update masquerades as testing the application callback.

### C7 — Deletion and empty reread

Cover deletion winning before claim, deletion requested during owner analysis, and conflict followed by deletion before reread. While owner holds claim, deletion may legitimately wait for PostgreSQL locks; do not demand impossible simultaneous cascade. Release owner, allow deletion commit, and verify no asset/analysis/orphan remains. A stale caller/callback cannot recreate them or report successful new completion. Force a missing locked reread with test-query synchronization and assert explicit deleted/no-op, no refresh-on-null and no unbounded recreate loop. For FK violation races verify rollback/savepoint recovery before existence checks and sanitized no-op only when deletion is confirmed. No raw SQL/constraint exception escapes.

### C8 — Clip transaction excludes upstream work

Use fixtures that actually execute probe, scene detection, extraction and transcription at their real job boundaries with valid test process responses. At each boundary record transaction level and have another connection acquire/release the seeded clip row lock with bounded NOWAIT; there must be no clip claim transaction/lock yet. Record stage ordering. At clip worker entry verify an active transaction and conflicting independent FOR UPDATE attempt. This verifies database behavior, not just query-string ordering or absence of a helper call. Assert existing independence/extraction-failure behavior remains intact.

### C9 — Transaction-local setting restoration

On owner connection capture its prior `lock_timeout` (include a nondefault test-session value, restored in teardown). Inside actual claim assert `current_setting('lock_timeout')` equals derived milliseconds; compare normalized units. After successful commit assert restoration and same value in a later unrelated transaction. Repeat after forced rollback and real lock-timeout rollback; connection remains usable. Independent live connection retains its own preexisting setting throughout. Assert no production SET SESSION/global change. Combine with C3 to prove setting applied **before insert conflict**, not merely before SELECT FOR UPDATE. A statement spy alone is insufficient.

## Additional retained failure and validation cases

### E1 — Malformed/error worker output and serial retry

Follow-up sanitization regression: in `ClipAnalysisResultTest.php`, retain all five existing inputs. Malformed JSON, trailing output, nonfinite JSON and overflow must each assert exactly `Clip analysis failed`; the renamed unexpected process-boundary RuntimeException case must assert exactly `clip_analysis_aborted`. All five still require ProcessMediaException, null previous exception and empty stderr. The unexpected case must retain its sensitive sentinel injection and must not be turned into an expected timeout fixture. An explicit per-case expected category or separate strict test is authorized, not a permissive either-message assertion. Rerun the full file, not just the formerly failing case. Preserve actual nonzero-exit, malicious worker envelope, invalid contract/result and real Symfony timeout controls in E1/E2, plus job-level rollback/outer-signal tests; these prove different outcomes and cannot substitute for one another. No broad RuntimeException exemption or new production correction follows from the dataset name.

Through real job plus process-boundary double, cover malformed JSON/success shape, malicious error envelope, nonzero exit and expected validation failure. Owner commits sanitized failed attempt with no partial candidates/parameters/input snapshot; upstream successes unchanged. Check failure state **before** retry, then allow a second caller to retry and complete serially. Capture logs/exceptions/queue failure data to ensure synthetic sensitive sentinels do not leak. Preserve full #58 malformed-success/strict contract regression matrix; no scoring changes.

### E2 — Real bounded worker timeout

Run a real test child process via production ProcessMediaAction transport (override only test executable/process creation), acknowledging startup then waiting beyond timeout=1. Assert actual Symfony process timeout termination, no live child afterward, sanitized atomic failed analysis and preserved upstream results. No sleeping in-memory action double passed off as a process timeout. Ordinary resolved clip failure permits asset completion if other paths resolved; existing extraction-failed asset remains failed. Retain real PHP-to-Python metadata success coverage as a separate integration control.

### V — Operational source and completion validation

- Default 30/35, minimum 1/6, nondefault 7/12 and maximum 120/125 have matching actual process timeout, observed PG setting and persisted execution metadata; exercise representative integrations plus boundary unit tests without waiting 125 seconds unnecessarily.
- Reject timeout 0, -1, 121, null, boolean, float, numeric config string, malformed environment strings and overflow. Environment canonical decimal integer text may be parsed intentionally; test malformed decimal/trailing text cannot be truncated into validity. Assert rejection before unsafe SQL/claim/process operations, no worker or accidental success/mutation. Direct action preflight and job configuration boundary both covered.
- Completion rejects missing/wrong-type/out-of-range timeout and wrong/missing/noninteger lock wait, including off-by-one; accepts derived pair even if an obsolete config key is artificially set inconsistently in the test. Completed reuse preserves earlier valid operational metadata after config changes.
- Active `config/media.php` no longer exposes the independent key; no active application implementation reads it or its environment variable. Check example configuration only if relevant. **Do not assert zero repository-wide references**: #58 history and this planning explanation intentionally retain names. Do not edit history.
- Keep exact input/result validation, privacy projection, golden scoring, legitimate empty completion, terminal asset/probe reuse and all #58 pipeline regression cases. No new model download/network/binary access for metadata analysis.

### V-empty — Strict eligible-empty completion preservation

Before the validator correction, add direct `MediaClipAnalysis::markCompleted()` tests using the existing recording-update convention and independently specified inputs/results. Execute and record RED for valid empty scenes and valid nonempty-but-all-ineligible scenes; exercise transcript omitted, present empty and present valid timing with matching provenance. Include below-minimum and above-maximum filtering without changing production defaults or the six scene fixtures. A valid completion performs exactly one complete update with status completed, candidates=[], full parameters/input/execution snapshots and null error. Add action `result()`/real-action parity controls on the same valid metadata, not expected outputs generated by the production rederivation helper.

Negative guards on the eligible-empty path must reject **before any model update**: invalid/nonpositive duration; invalid scene ordering/bounds/types; invalid completed transcript timing or explicit null; invalid configuration; missing/null/non-list candidates; missing/unknown/mismatched algorithm/version or parameter provenance (configuration, effective weights, transcript_used, policies, scale/rounding/limits); missing/extra/private snapshot fields; invalid timeout or derived lock wait. Missing values must not default into a valid empty completion. Preserve exact sanitized failure/no-sensitive-chain assertions. At the transport entry preserve JSON object-versus-list rejection ({} is not []); direct validator shape tests cover shapes excluded by the model's typed arguments.

Keep the existing completion `empty` negative dataset and action `fabricated empty result` test unchanged in meaning: both have eligible candidates and must reject []. Preserve nonempty score/criteria/source/rank/top-K/timing and privacy regression guards. An empty-path fix cannot skip full provenance or remaining snapshot/execution validation. No early return or unconditional acceptance based solely on `candidates === []` is sufficient.

All six current scene cases enumerated in the final plan clarification must retain once-only invocation, exact captured input and persisted COMPLETED/null-error/[] checks; add/inspect full persisted provenance where necessary. Assert later completed-empty reuse is unchanged with no worker rerun under the existing reuse contract. Run these tests plus the entire completion/result and scene suites before the standing concurrency/full-regression sequence. Existing passing totals are controls, not substitutes for executing these missing positive and negative cases. Report unrelated discoveries rather than broadening the validator correction.

## Documentation review (D1–D4)

Orchestrator edits after Builder GREEN/refactor; Tester reviews the final four-file changes before approval:

1. README: M0–M3 complete, M4 foundation implemented/completed, M5 next unimplemented; actual queues/CLI worker and deterministic analysis no longer merely planned; future image generation not implied implemented.
2. Roadmap: #58/PR #59 completed with transcription/scenes/hardening, no awaiting PR/CI/Tester or obsolete conditional closeout wording, no M3/M4 in future-only table, M5 still future.
3. Project-state: exactly the six mandated headings, M3/M4 capabilities, deterministic analysis exists while semantic recommendation/rendering/review/publishing do not, M5 separately human-authorized and not active. No copied stale totals or claim #60 already merged.
4. Architecture: actual Laravel queue/job/action/Python CLI/strict validation/Laravel PostgreSQL path, Laravel S3-compatible storage versus current Python filesystem-key handling (no invented automatic S3 staging), future-only direct Python Redis/SQS consumer, M4 vs M5 explicit, actual private MediaClipAnalysis snapshot/unique cascade relationship. New concurrency proof attributed to #60 only. No unrelated architecture redesign.

## Full regression matrix

References are merged baseline counts, not execution claims or substitutes for actual new totals. Preserve existing required assertions; no mandatory skips (including existing MinIO skip branches), no risky backend tests.

| Suite | Reference | Required verification |
|---|---|---|
| Worker | 298 tests | Existing `python -m pytest tests/ -v` in services/worker via permitted execution; real FFmpeg-generated video and real PySceneDetect integration execute. |
| Laravel/PostgreSQL | 612 tests / 2825 assertions | `php artisan test --compact` in apps/api with verified explicit PG environment; dedicated concurrency tests also executed separately three times per role. Record actual expanded counts. |
| MinIO | 4 real tests | Real Laravel/Flysystem/MinIO private-bucket cases execute, no fake/local fallback or skipped pass. Provision missing authorized infrastructure rather than weakening tests. |
| PHP style | Clean | `vendor/bin/pint --dirty --format agent`; Builder reruns tests if formatting changes files. Tester checks without repairing code. |
| Frontend | 187 tests | `npm run test -- --maxWorkers=1`, `npm run lint`, `npm run build` in apps/web. |
| E2E | 75 tests | `npm run test:e2e` with managed servers, authorized PostgreSQL and Chromium. |
| Governance | 170 tests | Existing `python -m unittest discover -s tests/governance` unchanged, executed by permitted role. This does not invoke a merge gate. |

Missing tooling/permissions must be resolved by Orchestrator/human within policy, never by bypass or skipped required test. No upgrade/CI/governance changes to make totals appear green. Failures outside scope are reported for clarification.

## Independent acceptance

Tester personally executes dedicated concurrency/recovery, inspects actual DB states/events/call counts and each repeat, and reviews all regression evidence; broad suite totals cannot establish C1–C9. Exercise existing auth/projects/media upload/list/delete UI/API at 390x844, 768x1024 and 1440x900, inspect screenshots/layout/overflow, loading/empty/error/success/disabled states, focus/keyboard, console/network/API responses and resources. Unexpected console errors or unexpected 4xx/5xx fail review. Candidate UI/recommendation-quality tests are N/A because absent, not a waiver of existing running-app review. No secrets/real user content in screenshots.

Map C1–C9, E1/E2, V and D1–D4 to spec requirements and return independent APPROVE/REJECT. No commit until APPROVE. Orchestrator then reviews final-head required CI/logs, including dedicated test discovery and real integrations. Stop at **CI_GREEN_WAITING_HUMAN_MERGE**; no merge-gate invocation, merge, issue closure or next issue.
