# Specification: Deterministic Clip Candidate Analysis

## Authority and status

- Active issue: [#58](https://github.com/CarlosEGoulart/AiClip/issues/58), `feat(clips): add deterministic clip candidate analysis stage`.
- Authorized branch: `@carlosegoulart/58/feat/clip-candidate-analysis`.
- Baseline supplied by Orchestrator: `909d37f4042ae7170193e68496094b705dfefba0`.
- This is an M4 foundation specification, not a claim of implemented behavior or completed milestone. The issue body was read directly during planning.
- Planner owns only this file, `plan.md`, and `test-plan.md`. Execution evidence, documentation reconciliation, implementation, testing, and lifecycle operations belong to their assigned agents.

## Goal and architectural reconciliation

Produce reproducible, validated candidate metadata from persisted scene boundaries and optional persisted transcription timing. Laravel owns input selection, authorization context, orchestration, validation, and PostgreSQL persistence. Python computes structured metadata outside HTTP requests and never accesses PostgreSQL.

| Source inspected | Interpretation for this issue |
|---|---|
| `docs/prd.md`, Clip Creation Workflow and MVP Feature List | AI relevance, interestingness, review, and rendering are eventual product capabilities. Timing scores do not satisfy the AI recommendation feature or the 80% relevance target. |
| `docs/roadmap.md`, M4/M5/M6/M7 | M4 is Video Understanding; M5 is AI Clip Recommendation; M6/M7 own rendering/review. This issue supplies candidate-generation infrastructure for those later milestones. |
| `docs/architecture.md`, worker boundaries and Media Processing Flow | Keep the existing Laravel job-to-Python CLI completion contract. No replacement queue consumer, object-storage reads, or binary processing is needed for this metadata action. |
| `docs/architecture.md`, `ClipRankingProvider` | The documented `rank(Segments, Context)`/`getRankingCriteria()` interface and ML/fake implementations describe appeal-based product recommendation. Reserve that concept for M5; do not instantiate, rename, or duplicate it in M4. |
| `docs/adr/0002-mvp-product-and-architecture.md`, decisions 1–5 | Preserve Laravel persistence/business validation and worker computational boundaries. No external-service integration is introduced. The real baseline algorithm is deterministic in runtime and CI; it is not a production fake. |
| `docs/project-state.md`, Current Milestone/Next Architectural Goal | “Most interesting” is directional wording, not authorization for semantic ranking. Its #56 in-progress line is stale: Orchestrator will reconcile #56/PR #57 as merged and #58 as in progress. |
| Existing #51/#53 implementation and #56 planning bundle | Preserve transcript/scene independence, JSON analysis snapshots, retry conventions, positive duration validation even for empty scenes, and mandatory real PySceneDetect coverage. |

**Abstraction decision:** introduce one Python `ClipCandidateAnalyzer` interface with `analyze(validated_input, configuration) -> ClipAnalysisResult` and one real `DeterministicClipCandidateAnalyzer`. Its baseline score is part of candidate construction, not a second recommendation-provider hierarchy. Laravel's independent validator is a trust-boundary validator, not another provider. M5 may consume these candidates through the documented `ClipRankingProvider`; that integration is not implemented here.

No material documentation conflict remains under these boundaries. Broad future architecture descriptions are not current implementation mandates. Orchestrator-owned documentation should distinguish baseline structural scoring from appeal-based recommendation; no rewriting of the PRD's product promises is needed.

### Conditional milestone recommendation

Upon #58 merge and verified closure, the **currently intended M4 foundation** can reasonably be recorded as complete: transcription (#51), scene detection (#53), hardening (#56), and validated candidate metadata (#58) establish the pre-recommendation understanding pipeline. The roadmap explicitly assigns AI recommendation to M5 and rendering/review to M6/M7, while project-state names analysis as M4's next slice. This is not completion of semantic understanding, AI recommendations, or the whole clipping MVP. Do not advance any milestone before merge or initiate M5 without explicit authorization.

## Scope and exclusions

Included: metadata-only worker action, deterministic algorithm and typed result validation, additive v1 contract extension, configuration/provenance, one-per-asset persistence, retry/terminal semantics, concurrency protection, orchestration integration, tests, and authorized factual documentation reconciliation by Orchestrator.

Excluded: AI/ML/LLM models, model/provider selection, external APIs or downloads for this action, semantic quality, virality/engagement/popularity predictions, rendered `Clip` entities, rendering, reframing, captions, face tracking, social features, candidate endpoints, frontend changes, general queue redesign, unrelated refactors, and governance/control-plane changes.

## Authoritative inputs and readiness

Inputs are read afresh by Laravel from the same `MediaAsset`, never supplied by a public request or taken directly from an unpersisted worker response. The asset must belong to its existing project/owner chain. No new ownership field or bypass is introduced.

1. Authoritative duration is the persisted `MediaAsset.duration_ms`, an integer in `1..2147483647`. Require a persisted probe result and consistency with its integer `duration_ms`; missing/invalid/conflicting values fail analysis. No `?? 0`, rounding, or fallback probe duration.
2. A persisted `MediaSceneAnalysis` with `status=completed` is required. Independently revalidate its scene list before projection: actual list; each object has integer `index`, `start_ms`, `end_ms`; indexes equal positions `0..n-1`; `0 <= start_ms < end_ms <= duration`; chronological, non-overlapping intervals. Gaps and an empty list are valid. Project only these three fields, never detector metadata or raw rows.
3. Transcription is optional. After the audio path has resolved, use a completed transcript's timing when audio is present and extraction has not failed. Revalidate the existing transcript segment structure (including its required nonblank text locally) and timing, then project **only** `start_ms`/`end_ms`. Require ordered, non-overlapping intervals with integer `0 <= start_ms <= end_ms <= duration`. Zero-length segments remain permitted by the existing transcript contract and contribute zero coverage; no clipping, shifting, or scaling of timestamps. Timing is on the original media millisecond axis, as normalized audio retains that timebase.
4. A completed transcript with malformed timing is `invalid_input`, not a silent scene-only success. Do not alter its completed status or content. This stricter downstream boundary does not broaden #58 into transcription hardening.
5. No audio, failed extraction, absent transcript, or failed transcript means omit `transcript_segments` entirely. No-audio/failed-extraction takes precedence over a stale completed transcript record. Completed valid empty transcription with an available audio path means include `transcript_segments: []`; it is distinct from unavailable transcription. A pending/transcribing transcript while the audio path is still active is not ready, not “absent.”

| Upstream situation at the clip-stage boundary | Required outcome |
|---|---|
| Scene completed, transcript completed/valid | Analyze using scene boundaries plus projected timing. |
| Scene completed, no audio / absent / failed transcript | Analyze scenes only. |
| Scene completed with `scenes=[]` and valid duration/configuration | Execute the analyzer; complete with `candidates=[]` and full provenance. |
| Scene pending/detecting, or transcript still active | Controlled `not_ready`; do not invoke analyzer or mark clip analysis completed. |
| Scene failed | Claim an analysis attempt, transition to failed with `upstream_scene_failed`; no worker invocation or invented scene fallback. |
| Scene missing after scene stage resolved, including no video applicability | Failed attempt with `upstream_scene_missing`; no fabricated whole-media candidate. |
| Invalid persisted duration/scenes/completed transcript/configuration | Failed attempt with `invalid_input` or `invalid_configuration`; preserve upstream records. |

The metadata worker accepts ready inputs only; upstream statuses and database identifiers are not sent to it. Missing `scenes` is invalid; `scenes=[]` is a deliberate valid input.

## Deterministic algorithm: `scene_timing_baseline`, version `1.0.0`

### Configuration and fixed policies

Laravel supplies a fully expanded `configuration` object; worker code must not consult hidden environment/model settings or infer missing configuration values. No client-controlled overrides are added.

| Configurable field | Default | Validation |
|---|---:|---|
| `min_duration_ms` | 5000 | Strict positive integer. |
| `target_duration_ms` | 30000 | Strict integer, `min <= target <= max`. |
| `max_duration_ms` | 60000 | Strict integer, `max <= 2147483647`. |
| `max_candidates` | 20 | Strict integer, `1..1000`. |
| `weights.duration_fit` | 50 | Integer, `1..10000`, ensuring scene-only scoring has a positive denominator. |
| `weights.speech_coverage` | 30 | Integer, `0..10000`. |
| `weights.boundary_alignment` | 20 | Integer, `0..10000`. |

These are **analysis configuration defaults**, not platform limits, promised clip lengths, or validated product-quality settings. `min=target=max` is allowed. Configuration durations need not fit within a particular asset; that can legitimately produce no candidates.

Fixed versioned limits/policies: at most 10000 scenes, 50000 transcript segments, 8388608 UTF-8 input bytes, and duration at most 2147483647 ms; score scale `S=1000000`; half-up rational rounding; candidate policy `whole_scene_non_overlapping`; timing policy `original_media_ms`; transcript policy `optional_strict_unshifted`; boundary policy `strict_interior_speech_cut`. Exceeding a limit fails closed; inputs are never silently truncated. These constants are recorded in parameters, not undisclosed heuristics.

### A. Candidate generation (chosen over overlapping alternatives)

For every validated scene, let `L=end_ms-start_ms`. Keep exactly that whole scene as one eligible candidate iff `min_duration_ms <= L <= max_duration_ms`. Do not extend, trim, combine, split, or snap it to transcription. Short/long scenes are omitted, not repaired. The resulting candidates cannot overlap because their source scenes cannot overlap. Each candidate has precisely one source index: `[scene.index]`. Duplicate scene indexes/intervals are invalid inputs; no probabilistic suppression or repeated alternative windows are needed.

Rationale: whole-scene candidates are a narrow, explainable structural foundation. Combining short scenes or making overlapping alternatives requires new selection policies that are not necessary for #58. A real video may produce zero eligible candidates; this limitation is explicit, not fake completion.

### B. Criteria and normalized score

Use exact integer/rational operations for calculations, not platform-dependent floating-point rounding. For nonnegative integers `a` and positive `b`, define:

`Q(a,b) = floor((2*a*S + b) / (2*b))`.

`Q` returns integer score units; the serialized numeric value is units divided by `S` (at most six decimal places). It is round-half-up, including exact halves. No NaN, Infinity, boolean-as-number, or numeric-string coercion is permitted.

For candidate `[s,e)` of length `L` and target `T`:

- `duration_fit_units = Q(min(L,T), max(L,T))`.
- If transcription is available, calculate `C = sum(max(0, min(e, segment.end_ms) - max(s, segment.start_ms)))`. Ordered non-overlapping segments ensure `0 <= C <= L`; zero-length segments add zero. `speech_coverage_units = Q(C,L)`.
- A boundary `b` cuts speech iff any segment has `segment.start_ms < b < segment.end_ms`. Equality with a segment endpoint is safe. With transcription available, let `B` be the number of safe boundaries among `s,e` (0, 1, or 2); `boundary_alignment_units = Q(B,2)`. This is timing alignment, not sentence completeness or semantic coherence.
- With unavailable transcription, `speech_coverage_units=0` and `boundary_alignment_units=0`, and their effective weights are zero. These zero criteria mean “not used,” not “no speech.” `transcript_used=false` disambiguates them.
- With present empty transcription, `transcript_used=true`, speech coverage is zero and both boundaries are safe. All configured weights still apply.
- `effective_weights` equal configured weights when transcription is present, otherwise `{duration_fit: configured duration weight, speech_coverage: 0, boundary_alignment: 0}`.
- Calculate `score_units = floor((2*sum(effective_weight_i * criterion_units_i) + sum(effective_weights)) / (2*sum(effective_weights)))`. This weights the already-quantized criteria and rounds once more, half-up. Serialize `score=score_units/S`.

Every criterion and score must be finite and in `[0,1]`. No free-text explanation, semantic label, or model confidence is generated. Scores describe these timing preferences only and are not audience outcomes or calibrated comparisons between transcript-available and scene-only modes.

### C. Selection, indexes, and ranks

1. Compute criteria/scores for all eligible scenes.
2. Sort by descending integer `score_units`, then ascending `start_ms`, `end_ms`, and source scene index. The tie-break is exhaustive and stable.
3. Retain the first `max_candidates` candidates. Assign their ordinal `rank` as `1..K` in that ranking order, with no ties or gaps.
4. Serialize retained candidates in chronological order `(start_ms,end_ms,source index)` and assign `index=0..K-1` in serialization order. Index is not rank and need not equal the original scene index.

Identical validated timing/configuration/algorithm version produces identical parameters, candidates, criteria, scores, and ranks. Asset IDs, file names/paths, wall clock, locale, process hash seeds, transcript text/language, and storage location cannot influence results.

### Golden example

Use `min=5000,target=10000,max=20000,max_candidates=2`, default weights, duration `40000`, and scenes `(0,0,10000)`, `(1,10000,20000)`, `(2,20000,40000)`.

- Scene only: eligible scores are `1,1,0.5`; keep source scenes 0 and 1, output indexes 0 and 1, ranks 1 and 2.
- With transcript timings `[5000,15000]` and `[25000,35000]`: eligible criteria `(duration_fit,speech_coverage,boundary_alignment)` are `(1,0.5,0.5)`, `(1,0.5,0.5)`, `(0.5,0.5,1)`; scores are `0.75,0.75,0.6`. Keep the same two candidates with ranks 1 and 2.
- Empty scenes, or no scene within configured duration bounds: complete with an empty candidate list after actual execution and validation.

## Additive v1 worker boundary

Keep contract version exactly `1.0.0`, matching the current schema. Add action `analyze_clips` and CLI subcommand `analyze-clips`; do not introduce a gratuitous v2 or silently accept unsupported minor versions.

The new action is a discriminated metadata-only branch in `media_processing_v1.json`. Preserve the legacy required envelope and validations for `probe`, `extract_audio`, `transcribe`, and `detect_scenes`, including absent-action probe compatibility. Conditional branches must require `action` to prevent vacuous matching.

### Request: exact fields

- `version`: `"1.0.0"`.
- `action`: `"analyze_clips"`.
- `media`: object with only required `duration_ms`.
- `scenes`: required list of objects with only `index`, `start_ms`, `end_ms`.
- `transcript_segments`: optional list of objects with only `start_ms`, `end_ms`; null is invalid.
- `configuration`: required object with exactly the seven configurable values above (four duration/limit fields plus the three fields nested in `weights`).

No asset/project/user IDs, idempotency key, creation timestamp, storage fields, URLs, file names, transcript text, language, provider metadata, derived-asset information, or secrets are needed in this branch. Keep those identifiers local to Laravel. A dedicated factory/serialization path on `MediaProcessingContract` must not accidentally emit its legacy storage/project envelope.

Reject unknown fields recursively for this action, including otherwise legitimate legacy fields, rather than accepting privacy-sensitive extras. Both boundaries enforce actual integer types (not booleans, floats, or strings), list/object distinctions, finite values, structural limits, and relational timing/configuration rules; JSON Schema alone is insufficient.

### Success: exact shape

Top-level object has only `status: "success"` and required `analysis` object:

- `algorithm`: nonblank string, exactly `scene_timing_baseline` for this implementation.
- `algorithm_version`: nonblank string, exactly `1.0.0`.
- `parameters`: required object with exactly:
  - `configuration`: complete validated request configuration, including every configured weight;
  - `effective_weights`: all three effective integer weights;
  - `transcript_used`: boolean reflecting presence of valid transcript timing;
  - `candidate_policy`, `timing_policy`, `transcript_policy`, `boundary_policy`: the fixed identifiers above;
  - `score_scale`: 1000000; `rounding`: `half_up`;
  - `limits`: `{max_scenes: 10000, max_transcript_segments: 50000, max_input_bytes: 8388608, max_duration_ms: 2147483647}`.
- `candidates`: required list (empty is valid), each object containing exactly:
  - `index`, `start_ms`, `end_ms`: strict integers with the invariants above;
  - `rank`: strict integer in `1..K`, unique and contiguous;
  - `score`: finite JSON number in `[0,1]` matching the formula;
  - `criteria`: object with exactly finite numeric `duration_fit`, `speech_coverage`, `boundary_alignment`, each in `[0,1]` and matching the formula;
  - `source_scene_indexes`: list containing exactly the valid integer index of that candidate's source scene.

No timestamps, request IDs, prose, storage information, or unknown output fields are accepted. Required success fields never receive `[]`, `unknown`, `0`, or other fallback defaults. PHP decoding must preserve object/list distinctions until validation; notably `{}` is not an empty candidate list.

### Errors, execution, and privacy

- CLI success emits exactly one strict JSON envelope to stdout, exit 0. Invalid JSON/contract/action/version/configuration/input emits `{status:"error",code:"invalid_contract",error:"Invalid clip analysis contract",stderr:""}`, exit 2. Runtime/result-validation failure emits the same shape with `code:"analysis_failed"` and `error:"Clip analysis failed"`, exit 1. Do not echo rejected values or schema-library exception messages. No partial success.
- Strict JSON rejects NaN/Infinity on input and output, including overflow to infinity. Validate top-level scalar/list/null inputs safely, without tracebacks. Output serialization uses the language's non-finite-rejecting mode.
- Laravel launches an argument-list process for `analyze-clips`, with the contract on **stdin**, not the command line. Existing CLI input mechanisms may remain for manual/tests; apply the same size/validation/privacy rules. No shell interpolation.
- New action performs no media-file access, FFprobe/FFmpeg calls, decoding, model loads, downloads, database access, or network requests. Reading its contract stream and packaged schema is allowed. It needs no child-worker supervisor; Laravel bounds the single worker process.
- `media.clip_analysis_timeout_seconds` defaults to 30, integer `1..120`. Lock wait bound is timeout plus 5 seconds. Persist both as Laravel-owned `execution_parameters` for the attempt; they are operational settings, not score inputs or worker configuration. Process timeout/nonzero exit/invalid JSON is a sanitized clip-stage failure.
- Do not forward raw stdout, stderr, payloads, transcript/media content, or arbitrary exception messages into exceptions, queue failure records, database errors, or logs for this stage. Use fixed error codes/messages, even for malicious worker error output. The stdout success contract is protocol output, not a log.
- Logs may contain only existing local asset ID, stage, fixed error code, elapsed time, and counts. No ranking text, criteria dump, or contract dump. Do not modify unrelated stage logging in this issue.

## Persistence, validation, and ownership

### Storage decision

Add `MediaClipAnalysis` / `media_clip_analyses`, unique `media_asset_id` foreign key with cascade delete, `MediaAsset::clipAnalysis()` has-one and inverse belongs-to.

Use one bounded JSON snapshot per asset, consistent with `MediaSceneAnalysis`: `status`, nullable `algorithm`, `algorithm_version`, JSON `parameters`, JSON `candidates`, JSON `input_snapshot`, JSON `execution_parameters`, nullable sanitized `error`, and timestamps. `input_snapshot` stores only the exact duration/scenes/optional timing/configuration used, without a raw legacy contract or transcript text. Result fields are null until successful completion. JSONB is acceptable on PostgreSQL; use repository-compatible migration/casts.

Analysis-plus-candidate rows would support future per-candidate editing, approval, and querying but introduce row identity/update semantics that M5/M6/M7 have not defined. This bounded terminal snapshot is sufficient for M4, atomically replaceable on a failed attempt and reproducible from its timing snapshot. A future migration can materialize `(analysis_id,index)` into review/render entities. No rendered `Clip`, candidate CRUD, or permanent cross-version candidate identity is promised here.

### Independent Laravel success validation

Validate in PHP even when a test double or compromised worker reports `status=success`. Before any result/status write, validate the exact envelope/object shapes, algorithm/version, all parameter fields and equality with the locally selected configuration/policies, candidates as a list, all types/finiteness/bounds, source existence and exact matching scene boundaries, duration eligibility, uniqueness/no overlap, sequential indexes, ranks, selection limit, and zero-candidate legitimacy.

Independently rederive the expected eligible scene set, quantized criteria/score units, top-K selection, tie-break, chronological indexes, and ranks from the captured authoritative inputs. Compare numeric values to their expected six-decimal values (integer score-unit comparisons with only representation-level tolerance at most `1e-9` in normalized values; never rounding an arbitrary worker score into validity). An empty response when eligible candidates exist is malformed, not valid fallback. Missing algorithm/parameters/candidates must fail even for zero scenes. Reject unsupported algorithms/versions rather than persisting misleading provenance.

Perform the same invariant checks at the model's completion boundary so another caller cannot bypass the service validator. Share PHP validation code between service/model, not worker implementation code. Do not mutate successful scene/transcript rows when rejecting results.

### Lifecycle and concurrent claim

Legal transitions only: `pending -> analyzing -> completed`, `analyzing -> failed`, `failed -> analyzing`. Starting a retry clears stale error and any incomplete result fields. `completed` is terminal and reused unchanged, including after configuration changes or a later successful transcript retry. Invalid transitions do not mutate stored data; do not silently report a new successful analysis.

Use PostgreSQL transaction-scoped exclusive row locking, not merely `firstOrCreate`, an in-memory flag, or a unique index:

1. Safely establish the unique pending row (insert-on-conflict/reread); handle concurrent first creation and deletion without duplicate rows.
2. Begin a transaction, lock that row with `SELECT ... FOR UPDATE`, then re-read status and current upstream state. The row lock is the exclusive claim. A completed row is returned without worker invocation or timestamp/result changes.
3. For a ready attempt or controlled upstream failure, transition pending/failed to analyzing, clear error, capture inputs/configuration, invoke at most one bounded metadata process if eligible to run, independently validate, and atomically complete or fail **inside the same transaction**. Catch expected worker/validation errors inside the transaction so the sanitized failure commits. Do not hold this lock during probe, scene detection, extraction, or transcription.
4. A second invocation cannot enter the analyzer until the first transaction releases its claim. After completion it must reuse the result; after failure a retry may run serially. A lock-timeout contender returns `busy`, never changes the owning attempt or claims success. The owning job remains responsible for finalization.
5. An unhandled exception/process crash rolls back the transaction, including analyzing state and partial JSON. The prior pending/failed state remains recoverable by the existing queue retry policy. No committed unowned analyzing row is created by this design. The queue exhaustion/failure path must resolve an unclaimed pending/failed clip attempt with a sanitized failure (via analyzing) and resolve the asset to a terminal state; it must not overwrite an independently completed/actively locked attempt.

The intentional trade-off is a database transaction held for at most one short metadata computation plus bounded validation/finalization (default worker timeout 30 seconds), avoiding a new distributed lease/recovery subsystem. `analyzing` is visible to the owning transaction; outside readers see the previous pending/failed state until commit. There is no new progress UI/API depending on committed analyzing state. Use ordered timing sweeps rather than a scene-by-segment Cartesian scan in both implementations so input limits also bound practical validation work. This is not permission to hold locks around heavyweight existing media stages. Test the claim with separate PostgreSQL connections/processes, not sequential Eloquent objects alone.

## `ProcessMediaAsset` integration and final completion

Preserve current order/independence: probe, scene path, audio/extraction/transcript path, **then** clip analysis, then asset finalization. Scene failure must still allow audio/transcription; no-audio media must still receive scenes. Analysis reads persisted results only after applicable paths resolve.

Introduce explicit `clipAnalysisResolved`:

- True only for persisted completed analysis (including executed/validated empty output), persisted failed attempt, or terminal completed reuse.
- False for not-ready inputs or another invocation's active claim. No skipped stage may masquerade as completed analysis.
- Normal `probed -> completed` asset transition requires `sceneDetectionResolved && audioPathResolved && clipAnalysisResolved`.
- Controlled scene/clip/transcription failures are resolved stages: asset can complete while the failed child analysis records its reason, consistent with existing scene/transcript semantics. “Asset completed” means processing resolved, not all analyses succeeded.

Remove the extraction-failure early return only as needed to resolve clips: retain existing asset `failed` outcome on audio extraction failure, skip transcription, mark the audio path resolved-unavailable, and allow scene-only clip analysis if scenes completed. Successful scenes/transcripts must not be overwritten. The asset stays failed; do not reopen its terminal lifecycle.

On rerun, reuse valid persisted probe metadata for already-probed/terminal assets rather than invoking a fresh probe whose data cannot legally persist. A failed clip attempt may retry without resetting the asset's terminal state; completed clips never reexecute. Existing failed scene/transcript retries remain supported, but later enrichment does not rewrite completed clip snapshots. Assets lacking a valid persisted probe must not be used to fabricate analysis input.

For genuinely active upstream pending/detecting/transcribing states at clip evaluation, return not-ready and release/retry the existing job using its bounded three-attempt policy (5-second delay when applicable). Do not complete the asset. Exhausted unresolved upstream processing produces a sanitized failed clip attempt (`upstream_not_ready`) and fails a nonterminal asset, preserving upstream data rather than leaving it processing indefinitely. Already-terminal asset states remain unchanged. Busy duplicate delivery may return without finalizing; the owning job and its queue retry/failure handling own progress. Missing/deleted assets are safe no-ops, never recreated. Late failure callbacks must not corrupt completed snapshots.

## Observable acceptance criteria

1. The real deterministic analyzer and metadata-only `analyze_clips` v1 action implement the exact algorithm, parameters, errors, and privacy boundary above.
2. Required scenes and optional transcription timing follow every readiness case; empty/filtered scenes produce a genuine executed zero-candidate result only when valid.
3. Python and Laravel independently reject malformed inputs/results and all candidate/index/rank/source/score invariants; no required success field defaults.
4. A unique, owner-scoped, cascade-deleted snapshot has legal retry/error-clearing/terminal semantics; concurrent claims cannot run competing analyses or corrupt results.
5. Pipeline independence, reexecution, extraction-failure behavior, and explicit final resolution are tested without altering upstream successes.
6. No production AI/fake recommendation, rendering, frontend/API addition, credentials, network, model download, media decoding, or unsafe logging is introduced.
7. Authentic behavior-assertion RED precedes implementation, all new/retained tests pass without weakening/skips, full regression baseline is preserved, and independent Tester approves running behavior.
8. Exactly one executing-agent evidence file has actual `### RED`, `### GREEN`, and `### REFACTOR` sections. No fabricated counts or Planner execution claims.
9. Orchestrator reconciles #56/PR #57 as merged and #58 as in progress; required final-head CI logs are reviewed. Stop at `CI_GREEN_WAITING_HUMAN_MERGE`; no merge-gate invocation, merge, issue closure, or next issue is authorized.

## Security and UX implications

Timing metadata remains private owner-scoped application data, not harmless public telemetry. Enforce minimal projection, strict schemas, local ownership, bounded inputs/processes, sanitized errors, and no payload logging. No new authentication surface or secret provisioning is required.

There is no new UI or candidate API. Existing upload/list/delete/auth/project workflows must remain visually and functionally unchanged. Tester still exercises the running application and inspects console/network/API behavior. Do not present baseline timing scores as recommendations or outcomes.
