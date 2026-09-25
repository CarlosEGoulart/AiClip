# Implementation Plan: Issue #64 — In-place Corrective Recovery

## Authority and preservation

Read the live #64 issue, spec.md and test-plan.md. Human authorized replacing only these three planning files during this invocation. Existing dirty implementation/tests/evidence are preserved, not discarded or retrospectively approved. No Git lifecycle, production edits, tests, evidence changes, or environment changes are performed by Planner. M0–M4 complete; M5 active, unverified and not shipped.

This plan supersedes earlier post-GREEN instructions and false readiness assumptions. Historical original test-first execution is unestablished. New evidence is **corrective RED**, not reconstructed original RED. Source inspection is not test execution. No branch/reset/clean/stash/baseline rollback is needed or authorized.

## Gates and responsibilities

1. Orchestrator inventories/preserves existing dirty work before delegation and confirms the approved scope. Planner owns only spec/plan/test-plan; Builder owns corrective application tests/implementation; independent Tester verifies; evidence is maintained by executing roles, not Planner.
2. Evidence owner must explicitly supersede invalid old RED/GREEN claims while preserving history. Initialize a clearly labeled corrective cycle with `### RED`, `### GREEN`, `### REFACTOR`, recording unexecuted phases as pending, not success. No historical evidence rewrite or deletion.
3. Before database tests, operator supplies an explicitly disposable PostgreSQL 16 database, credentials through approved secret channels, required PHP driver and migration permission. Verify DB name/connection/driver and disposable authorization before RefreshDatabase/migrations; never use a production or previously assumed test database. SQLite is not a substitute. Provide isolated MinIO bucket and deletion permission for storage regression fixtures.
4. Required tools: approved worker test entry point, Python/jsonschema/pytest, existing FFmpeg/PySceneDetect integrations, PHP/Pest/PostgreSQL driver, Node/frontend toolchain, Chromium and free managed-server ports. Denied tools or missing dependencies block execution; no permission bypass, alternate interpreter trick, environment override or global configuration edit.
5. Real model preparation is operator-gated, outside mandatory CI: authorize downloading only public runtime/model artifacts in a separate provisioning environment, verify exact revision and file hashes, retain attribution, build a read-only cache plus transitive hash-locked runtime, then disable inference network access. No user content is sent during provisioning or inference. No production migration/deployment/queue changes are authorized here.
6. Exact real dependency constraints and CPU/token/batch/memory/time policies are in spec.md. Builder may create an optional ranking dependency manifest/lock; base worker and unrelated dependencies stay unchanged. Resolve the complete transitive closure and run consistency/security checks. Conflict or security concern returns to Planner, not an unreviewed version replacement. Source compatibility is verified; installation and resource performance are execution checks, not assumed facts.
7. Orchestrator later reconciles current project-state/roadmap/architecture references without editing historical #58/#60 artifacts. Existing queue timeout configuration must accommodate the bounded M5 stage; inadequate configuration is an operator blocker, not permission to redesign queue infrastructure.

## Expected corrective surface (Builder only after gates)

| Area | Work |
|---|---|
| Worker ranking.py | Python provider interface/criteria, truthful fake, pinned local-only adapter, no offline fallback, stable score units/ordering, strict output cardinality |
| Worker rank_clips action/CLI/contracts/schema | One strict stdin-only branch, shared strict validation, selected profile, raw-input digest, truthful output provenance, bounded sanitized transport |
| Optional worker ranking dependency manifest/lock | Exact direct constraints and hash-locked CPU-only closure, separate from mandatory CI install |
| Laravel MediaProcessingContract/ProcessMediaAction | Minimal text projection contract, expected configuration binding, bounded process/response, independent validation |
| ClipRecommendationValidator and model | Shared structural/reference/provenance validation, null unscored semantics, local outcomes, authoritative snapshot binding |
| New issue-local recommendation migration/MediaAsset relation | Unique cascading asset authority, M4 analysis FK, lifecycle/outcome/snapshots, no M4 migration changes |
| ProcessMediaAsset | Seven-state precedence, fresh locked projection, completed/unavailable reuse, version conflicts, safe claim/retry/exhaustion |
| Issue-local PHP ranking interface/fake/adapter/binding | Remove unnecessary duplicate abstraction and tests of mere wiring; replace with mandatory Python-fake subprocess and transport behavior coverage |
| Application tests | Corrective RED, contract/provider/job/DB/privacy/concurrency/integration tests; narrow updates to old explicit fixtures without weakening assertions |

No recommendation endpoint/UI, generic workflow engine, lease scheduler, queue topology change, protected governance/control-plane edits, or unrelated refactor. Test-only helpers may follow existing conventions. Existing scope-owned code is repaired in place; filenames do not freeze defective behavior.

## Phase 1 — RED-only checkpoint

Delegate tests only. Do not change production behavior, schemas, migrations or configuration to force collection or make tests pass. Existing M5 entry points are available. Prepare the eight named tests in test-plan.md, with passing legacy controls and real disposable-PostgreSQL preflight before DB-backed tests.

Execute against preserved defective implementation, recording command, exit status, exact assertion/observed value, environment and scope. Failures must expose required behavior, not import/syntax/setup/permission failures. A test already passing is a useful control, not fabricated RED. Repair only test harness mistakes in this phase. Model/library calls are intercepted using dependency stubs, not downloaded models. PostgreSQL test setup must complete before an assertion is accepted.

**STOP after corrective RED results.** Return to Orchestrator. GREEN implementation requires separate explicit authorization after valid failures are reviewed. If tests cannot execute, report blocked and stop; no source-inspection substitute and no assumption CI will establish prior RED later.

## Phase 2 — Worker correction (after RED acceptance)

1. Write additional strict contract/CLI/provider tests before each correction. Reject recursive unknown fields, wrong shapes/types, bounds and malformed partial inference at both schema and action boundaries.
2. Keep Python as the provider abstraction. Add metadata/criteria API; explicit trusted configuration; real and fake profiles per spec. Remove fallback and hardcoded action provenance. Fake returns fixture scores with fake identity; mandatory tests never load real ML.
3. Implement adapter loader with exact model revision/read-only verified cache, local-only, no remote code, safetensors, CPU/eager/eval/no-grad settings, explicit Identity activation. Inject loader/model doubles in CI; no heavyweight imports until real selection with usable text.
4. Validate exactly N raw finite logits before stable sigmoid once; quantize half-up to units before sorting; tie by M4 rank then candidate index. Build all K references, null unscored entries, strict result validation. Never let zip silently truncate.
5. CLI accepts stdin only, captures exact-byte SHA256, reads <=8 MiB and writes <=1 MiB, sanitizes failure categories, no logs/progress/tracebacks/raw exception data. Permit only schema/cache reads; deny network/media/storage/DB activity.
6. Preserve all legacy action behavior and tests. Run targeted worker tests, including real legacy FFmpeg/PySceneDetect integration. Do not claim provider stubs prove real-model quality or installation.

## Phase 3 — Laravel authority, projection, lifecycle

1. Add tests for each seven-state row/precedence and exact canonicalization before implementation. No-audio/extraction-failure must beat stale completed transcript; do not classify generic asset failure as extraction failure. Validate selected completed transcript strictly without default values.
2. Implement ASCII whitespace canonicalization, explicit Unicode White_Space eligibility, half-open segment overlap, no extra context, byte/count limits and per-candidate SHA256. Retain hashes of pre-token-truncation text only. Preserve full M4 authoritative snapshot locally.
3. Use only `ProcessMediaAction::rankClips` for Python invocation. Request selected configuration and expect exact matching response, fake identity included. Remove issue-local redundant PHP provider plumbing without dropping behavior coverage.
4. Define completed/no_candidates and unavailable local outcomes without invoking worker/model or claiming inference. Mixed candidates produce K entries, scored N ranks and null unscored references. Implement shared service/model validation for both local and worker outcomes; no PHP neural score recomputation.
5. Adjust only the unshipped issue-local migration/model to the specified fields/statuses/FKs; never rewrite merged M4 migrations. If any dirty migration has already run against a non-disposable environment, stop for operator migration strategy; do not silently alter deployed schema. Test migration up/down in disposable DB only.
6. Bind terminal snapshots to original M4 authority and configuration. Identical terminal selection reuses unchanged; changed semantic selection returns version conflict without overwriting. Transcript changes alone do not trigger reanalysis. Failed retries capture current selection and clear only M5 state.

## Phase 4 — Transaction/queue integration

Follow #60's atomic claim discipline; adapt tests, never rewrite its historical specification/evidence. No committed ranking state, unlocked not-ready writes or snapshot capture before claim.

1. Strict operational timeout validation before contended work; transaction-local lock_timeout before insert/FK/unique waits. Conflict-safe creation then locked fresh reread.
2. Fresh authoritative upstream reads/projection under lock. Upstream processing itself outside M5 transaction. One bounded metadata subprocess at most; completion/validation/failure commit together.
3. Expected worker/validation errors commit failure; unexpected caller/DB errors roll back and propagate sanitized retryable abort. Busy/deleted outcomes remain separate, no owner mutation. All callers/failure callbacks lock and reread, not stale instance saves.
4. Not-ready leaves no durable ranking state; three attempts/5s retry, safe serialized exhaustion. Terminal/deleted/locked state is protected. Preserve extraction-failed and terminal asset statuses; require explicit four-stage resolution for normal completion.
5. Test independent PostgreSQL processes/connections with committed setup and barriers for first-create/retry/lock timeout/connection termination/deletion/stale callback/settings restoration. Observe persisted state externally while owner holds claim and after commit/rollback.

## Phase 5 — Integration and real smoke

Mandatory subprocess integration uses actual PHP -> actual Python CLI -> explicitly selected **Python FakeRankingProvider** -> independent PHP validation -> PostgreSQL persistence. No PHP fake shortcut, no implicit missing-library fallback, no model dependencies/downloads/GPU/network. Worker unavailable is a mandatory-test failure, never skip. Assert exact fixture scores, truthful identity, request/text-hash binding and privacy.

Separate required real-adapter smoke uses the operator-prepared CPU environment described in spec.md, not mandatory CI. Operator supplies verified exact model artifacts and the approved locked dependency environment. Run with inference egress disabled and synthetic inputs; record package versions/artifact revision/manifest digest, actual inference flags, finite result/reference/order invariants, repeat-run behavior, text sensitivity, cold-load/inference time and peak memory. Probe missing artifacts/runtime and show failure, not fake substitution. No raw prompts/results/transcripts in logs/evidence. No quality percentage claimed. If unavailable, real-runtime verification remains blocked and the issue is not complete.

## Phase 6 — GREEN, refactor, independent verification

Execute all targeted/new tests and every mandatory regression in test-plan.md. Record actual totals, assertion counts, skips/risky status and environments; no expected-count or source-review claims. Refactor only issue-local corrections, then rerun affected/full suites. No frozen-test argument permits retaining a wrong assertion or weakening an invariant; changes must trace to this specification.

Independent Tester validates actual worker/DB/pipeline and browser workflows, reviews privacy/ownership/API non-exposure and scope. No repair by Tester. Any defect routes through Orchestrator back to Builder. Evidence owner labels the cycle corrective and preserves historical uncertainty; Planner does not edit evidence.

## Final gates

Only after independent approval may Orchestrator perform separately authorized lifecycle operations. Five final-head checks and logs must be green: Backend CI, Frontend CI, E2E CI, governance, pr-enforcement. All required real integrations/smoke and running-app checks must have evidence, not merely CI totals. Stop at `CI_GREEN_WAITING_HUMAN_MERGE`; no merge-gate invocation, merge, issue closure, or next issue without human authorization.

SPEC_READY
