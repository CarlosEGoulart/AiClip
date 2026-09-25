# Specification: Issue #64 — Semantic Clip Recommendation

## Authority, recovery, and scope

Issue: https://github.com/CarlosEGoulart/AiClip/issues/64. Branch supplied by Orchestrator: `@carlosegoulart/64/feat/semantic-clip-recommendation`; baseline `2a4da5f3dd3d130ef22e80b4dcbfee379610a9f6`. M0–M4 are completed; M5 is active, not shipped.

Human authorized in-place planning recovery on 2026-09-25. This specification replaces the contradictory earlier planning and post-GREEN clarifications, not historical execution evidence. Preserve existing dirty production files, tests, and evidence. Historical original test-first RED cannot be established from the available evidence. New executed tests can establish **corrective RED only**. Source inspection, unavailable imports, denied commands, and failed environment setup are not RED. No GREEN or Tester approval is asserted here.

Add one internal M5 recommendation stage. Preserve M4 `scene_timing_baseline` v1.0.0 candidates, boundaries, scores, ranks, criteria, provenance, lifecycle, snapshots, and timestamps. Laravel queue -> `ProcessMediaAsset` -> `ProcessMediaAction` -> Python CLI -> independent Laravel validation -> Laravel PostgreSQL remains authoritative. Upstream media/ML work never runs in HTTP requests; Python neither consumes the Laravel queue nor writes PostgreSQL.

Excluded without exception: recommendation UI/API, editor, rendering, captions, reframing, visual embeddings, training, publishing, social OAuth, queue/deployment redesign, unrelated upgrades/refactors, governance changes, and historical #58/#60 edits. No remote inference or user-content transmission is authorized. Existing user workflows must remain unchanged.

Architecture sources: `docs/architecture.md` current topology and provider boundaries, ADR-0002, `docs/prd.md` Clip Ranking, `docs/roadmap.md` M5, and #58/#60 specifications. Existing durable descriptions of M5 as inactive need Orchestrator reconciliation, not historical rewrites. The architecture's target `getRankingCriteria()` is implemented as static provider criteria metadata; model identity is additional metadata. Python is the required abstraction. PHP is transport/orchestration, not a second ranking implementation.

## Provider and model decision

Python `ClipRankingProvider` exposes `rank(input)` and static identity/criteria metadata. Implement `FakeRankingProvider` and `CrossEncoderRankingProvider`. No domain imports of the ML runtime; lazy-load the real adapter only when selected and semantic input exists. Remove the unnecessary issue-local PHP ranking interface/fake/adapter/container wiring during authorized corrective implementation, with replacement behavioral tests; keep the single `ProcessMediaAction::rankClips` subprocess boundary. Do not remove unrelated providers.

Trusted server configuration selects `fake` or `cross_encoder` explicitly; unknown/unset selection fails `invalid_configuration` on a new attempt. There is no automatic environment detection or fallback. Mandatory tests and CI explicitly configure `fake`; normal runtime explicitly configures `cross_encoder`. Pass the selected identifier in the validated request; no user endpoint may choose it. Fake responses must retain fake identity end-to-end. Runtime/model/cache/dependency failures are sanitized M5 failures, never timing scores masquerading as model output.

### Pinned real profile `minilm_cpu_v1`

| Property | Decision |
|---|---|
| Model | `cross-encoder/ms-marco-MiniLM-L6-v2` (canonical spelling) |
| Immutable revision | `233902d25c440f23af6f7d6e94d2946bac0bee0a` |
| License | Publisher declares Apache-2.0; preserve license/model-card attribution with provisioned artifacts |
| Intended use | English query–passage relevance proxy; not a trained virality predictor or multilingual quality guarantee |
| Runtime | Python 3.12; CPU PyTorch; no CUDA/GPU requirement |
| Direct runtime constraints | `sentence-transformers==3.4.1`, CPU-wheel `torch==2.7.1+cpu`, `transformers==4.53.3`, `tokenizers==0.21.2`, `huggingface-hub==0.33.4`, `safetensors==0.5.3`, `numpy==1.26.4`, `scipy==1.12.0`, `scikit-learn==1.6.1`, `Pillow==11.3.0`, `tqdm==4.67.1` |
| Artifact policy | Safetensors only; tokenizer/config and weight artifacts from the exact revision; no pickle fallback, remote code, revision fallback, or runtime download |
| Loading | Configured operator-owned read-only local cache, exact model ID/revision, `local_files_only=True`, `trust_remote_code=False`, `device='cpu'`, `use_safetensors=True`; verify artifact manifest before use |
| Execution | FP32, evaluation/no-grad, eager attention, one inference process, two intra-op threads, one inter-op thread, batch size 8, tokenizer workers 0, progress disabled |
| Input policy | Maximum 512 tokens including pair special tokens; fixed query below; right-side `longest_first` tokenizer truncation, as implemented by the pinned CrossEncoder; no text outside candidate windows |
| Time/memory | Default worker timeout 60 seconds, configurable strict integer 1..120; lock wait = captured timeout + 5. Operator test envelope: 2 CPU cores, 2 GiB RAM limit, 4 GiB disposable disk. Resource exhaustion is failure, not fallback |

These versions satisfy the reviewed upstream declared constraints: Sentence Transformers requires Python >=3.9, Transformers >=4.41,<5 and Torch >=1.11; Transformers 4.53.3 declares Torch >=2.1, tokenizers >=0.21,<0.22, Hub >=0.30,<1 and safetensors >=0.4.3. Its optional SciPy constraint is also satisfied. This is source-level compatibility, **not an executed installation or security certification**. Builder must resolve and hash-lock the transitive closure in the optional ranking runtime without changing the base worker dependencies; operator runs dependency consistency and smoke checks before real use. Target Linux x86_64/Python 3.12; provision the exact CPU wheel from the official `https://download.pytorch.org/whl/cpu` distribution, not a CUDA wheel or ambiguous extra-index replacement. Other packages use their verified public distribution artifacts. No training extras, accelerate, torchvision, torchaudio, ONNX, or CUDA packages are needed. A resolver conflict or security finding returns to Planner; never silently substitute versions.

Approximately 22.7M parameters imply about 91 MB FP32 weights; full dependencies/memory are larger. The envelope above is a bounded starting allocation, not measured performance. Do not retain prior 500 MB / 50–150 ms CPU claims. The publisher benchmark uses a V100 GPU. Separately measure cold load, inference time and peak memory with synthetic data; no latency or relevance percentage is acceptance here.

Official sources reviewed on 2026-09-25:

- https://huggingface.co/api/models/cross-encoder/ms-marco-MiniLM-L6-v2 (identity/revision/parameters)
- https://huggingface.co/cross-encoder/ms-marco-MiniLM-L6-v2/blob/233902d25c440f23af6f7d6e94d2946bac0bee0a/README.md (license, English MS MARCO purpose, benchmark)
- https://huggingface.co/cross-encoder/ms-marco-MiniLM-L6-v2/blob/233902d25c440f23af6f7d6e94d2946bac0bee0a/config.json (512 positions, single logit, Identity activation)
- https://raw.githubusercontent.com/UKPLab/sentence-transformers/v3.4.1/pyproject.toml (requirements)
- https://raw.githubusercontent.com/UKPLab/sentence-transformers/v3.4.1/sentence_transformers/cross_encoder/CrossEncoder.py (local-only/revision/activation/truncation APIs)
- https://raw.githubusercontent.com/huggingface/transformers/v4.53.3/src/transformers/dependency_versions_table.py (compatibility constraints)
- https://pytorch.org/get-started/previous-versions/ and https://pypi.org/pypi/torch/2.7.1/json (release/CPU distribution)

### Versioned scoring configuration

Algorithm `transcript_semantic_recommendation`, algorithm version `1.0.0`, projection version `1.0.0`, query version `1.0.0`. Fixed query:

`Engaging, self-contained short-form video clip highlight with a clear narrative or punchline.`

The query is an experimental relevance proxy, not proof of entertainment quality. No user-controlled prompt. Scores are internal metadata, not calibrated probabilities or proof of the PRD 80% target.

Real provider identity: `cross_encoder_ranking_provider`, model/revision above, runtime profile `minilm_cpu_v1`, normalization `stable_sigmoid_half_up_6`. Fake identity: provider `fake_ranking_provider`, model `fake-ranking-v1`, revision `1.0.0`, runtime profile `fake_v1`, normalization `fixture_units_6`. Both expose criteria `query_passage_relevance`, `quantized_score_desc`, `m4_rank_asc`, `candidate_index_asc`. Fake uses score units `max(0, 1000000 - (m4_rank - 1) * 100000)` for eligible candidates; it is deliberately not semantic inference. Its metadata must never name the real model.

## Authoritative input and projection

Read persisted asset/M4/transcript state under the M5 claim, not an earlier worker response or public request. Require a valid completed M4 row of the expected algorithm/version and revalidate its full candidate snapshot through existing M4 validation without modifying it. Validate chronological nonoverlapping candidates, indexes 0..K-1, unique M4 ranks 1..K, source-scene references and unchanged bounds/scores/criteria. K <=1000, matching M4's supported cap. Duration is a strict positive integer <=2147483647 ms; intervals lie in duration. An unsupported/invalid upstream snapshot fails M5 only.

### Seven transcript states and precedence

First use authoritative probe/extraction outcomes: no audio wins over a stale transcript; recorded extraction failure wins over a stale transcript. Do not infer extraction failure solely from a generic asset failure caused by another stage. Otherwise classify the transcript below. No-audio and extraction failure ignore even malformed stale transcript text. All rows preserve M4 byte-for-byte.

| State | Input/text and invocation | Durable M5 outcome and retry | Asset effect |
|---|---|---|---|
| `completed_valid` | Completed, strictly valid nonempty segments; project text per candidate. Worker once only if at least one candidate has usable text | `completed` after validated worker result; candidates with no text have null score/rank | Existing normal completion allowed after all stages resolved |
| `completed_empty` | Completed valid `segments=[]`; no worker/model | `unavailable`, reason `completed_empty`, K unscored references; terminal reuse | Normal completion allowed |
| `no_audio` | Probe has no audio; discard stale text; no worker/model | `unavailable`, reason `no_audio`; terminal reuse | Existing no-audio completion preserved |
| `extraction_failed` | Authoritative audio extraction failed; discard stale text; no worker/model | `unavailable`, reason `extraction_failed`; terminal reuse | Asset remains failed |
| `transcription_failed` | Failed transcript; no text or worker/model | `unavailable`, reason `transcription_failed`; terminal reuse | Normal completion allowed |
| `missing` | No row and upstream audio/transcription attempt has resolved; no worker/model | `unavailable`, reason `missing`; terminal reuse | Normal completion allowed |
| `not_ready` | Pending/transcribing, or missing while upstream audio/transcription still active; no worker/model | Keep absent/pending/failed durable state; release for retry, maximum 3 attempts, 5-second delay; exhaustion serialized `failed/upstream_not_ready` | No premature completion; exhaustion fails nonterminal asset only |

Invalid completed segments are `failed/invalid_input`, no worker, never reclassified as missing/empty. M4 pending/missing while active is not-ready; resolved M4 failure/missing is `failed/upstream_m4_unavailable`, no invented candidates. These resolved failures permit normal asset completion only when other stages permit it.

K=0 after validated completed M4 is a terminal `completed/no_candidates` outcome with `recommendations=[]`, no worker/provider/model invocation, and `inference_performed=false`. It takes precedence over transcript readiness; do not read unnecessary transcript text. This is local validation completion, never claimed as executed model success. Invalid M4/duration/configuration still fails before this shortcut.

For K>0 and completed valid transcript, project each window. If none has text, terminal `unavailable/no_candidate_text` without worker. Mixed input invokes provider for only nonempty candidates; every candidate still gets one result entry. Unscored entries have `semantic_score=null`, `semantic_rank=null`, reason `no_candidate_text`; they are not assigned zero or a timing-based semantic rank.

### Exact text rules and durable binding

Validate the entire selected completed transcript before projection: list of <=50000 segments, required integer start/end within duration, positive intervals, chronological nonoverlapping order, required valid UTF-8 string text. Reject malformed types/times; do not default missing fields. Known transcript metadata remains local and is not forwarded. Use segment-level text because word timing is not guaranteed. Include a segment iff `segment.start_ms < candidate.end_ms && segment.end_ms > candidate.start_ms` (half-open overlap). A boundary-crossing segment may occur in both candidate projections; do not invent word-level cropping. Never include unrelated segments.

Canonicalization version 1: replace runs of ASCII HT/LF/VT/FF/CR/space (`U+0009..U+000D`, `U+0020`) with one ASCII space, trim these characters, omit resulting empty segments, join remaining segment texts by one ASCII space in original order. Preserve case, punctuation, non-ASCII code points and Unicode normalization form; reject other C0 controls, DEL and invalid UTF-8. A candidate is usable iff its resulting text contains at least one character outside Unicode White_Space (pin the Unicode 15.0 White_Space code-point set in both languages). Non-usable text becomes exactly empty string. Maximum canonical text per candidate: 16384 UTF-8 bytes; maximum request: 8 MiB; maximum response: 1 MiB. Exceeding a bound fails the entire attempt, never drops candidates. Token truncation occurs only inside the pinned real adapter and is recorded as policy, not raw tokens/results. SHA256 is over the full canonical UTF-8 text **before token truncation**, lowercase 64-character hex, including SHA256(empty) for unscored entries.

Laravel captures `input_snapshot` with exactly `m4_analysis_id`, `m4_algorithm`, `m4_algorithm_version`, `m4_candidates` (full validated authoritative list), `duration_ms`, `transcript_state`, `projection_version`, `text_hashes` (chronological list of `{index,sha256}`), `request_sha256` (nullable for local outcomes). It never persists projected raw text. Existing upstream transcript persistence remains unchanged. Hashes are private potentially guessable identifiers, not anonymization; retain owner isolation/cascade protections.

## Strict worker protocol

Keep legacy actions unchanged. Additive `rank_clips` action, exact contract version `1.0.0`, argument-list subprocess with stdin only for this action. Do not accept JSON argv/file modes for transcript-bearing ranking. Input duplicate keys, unknown fields recursively, NaN/Infinity, trailing data, malformed UTF-8, object/list substitutions, numeric strings/bools as numbers and incompatible versions fail closed. Python schema and runtime validation must agree; no bypass branch with weaker validation.

Request exact keys:

- `version`: `1.0.0`; `action`: `rank_clips`.
- `media`: exactly `{duration_ms}`.
- `candidates`: chronological list of exactly K objects `{index,start_ms,end_ms,m4_rank,m4_score,transcript_text}`. Preserve the original M4 numeric score exactly; do not normalize it. No criteria/scene/transcript/provider metadata beyond this minimal projection crosses the worker boundary.
- `configuration`: exactly `{provider,algorithm,algorithm_version,projection_version,query_version,prototype_query,model_id,model_revision,runtime_profile,normalization,max_tokens,batch_size,truncation}`. Values must equal the selected profile in this specification; max_tokens=512, batch_size=8, truncation=`right_longest_first_512` for real; fake uses max_tokens=0, batch_size=0, truncation=`none`.

No user/asset/project IDs, URLs, secrets, paths, storage, language or unrelated metadata. Model identity is allowlisted configuration, not user identity. Python hashes the exact raw stdin bytes; Laravel computes the same hash before launch. Providers receive typed candidate inputs and fixed query, not raw transport/credentials.

Worker requests are only for K>0 with at least one nonempty candidate text. Local zero/unavailable outcomes never forge a CLI success. Direct worker requests lacking usable text fail `invalid_contract`.

Success exact keys: `{status:'success',ranking}`. Ranking exact keys: `{algorithm,algorithm_version,parameters,request_sha256,recommendations}`. Parameters are exactly the request configuration excluding algorithm/algorithm_version, plus `provider_name` (the selected profile's full provider identity), `inference_performed` (true real, false fake) and `transcript_used` (true for these worker requests). Thus `provider` is the selector `fake` or `cross_encoder`, distinct from `provider_name`. No runtime paths, raw logits, text, free-form prose or exception details.

Recommendations contain exactly K objects with keys `{m4_candidate_index,start_ms,end_ms,m4_rank,m4_score,semantic_score,semantic_rank,reason}`. Reference/index/boundaries/M4 score/rank equal authoritative request values exactly. Scored entries have finite score and contiguous semantic ranks 1..N, reason null; unscored entries have both semantic fields null and reason `no_candidate_text`. Order: scored entries first by ranking rule, unscored entries ascending candidate index. All-equal scores still require K entries, not empty output. Every index occurs exactly once. Reject any missing/extra/duplicate/unknown/mutated reference or partial malformed result as a whole.

Real inference must return a 1-D vector of exactly N finite scalar raw logits, one per nonempty candidate in chronological order. Reject booleans, strings, wrong shape/count and any nonfinite logit before normalization. Force Identity activation explicitly at construction/predict, softmax false; apply sigmoid exactly once. For x>=0 use `1/(1+exp(-x))`; for x<0 use `exp(x)/(1+exp(x))`. Quantize binary64 result to integer units `floor(score*1000000 + 0.5)` then divide by 1000000. Bounds inclusive [0,1]; underflow/saturation to endpoints permitted for finite logits. Sort **quantized units**, descending, then ascending original M4 rank, then stable candidate index. No epsilon ties. Identical provider logits yield identical output semantics; no unsupported promise of bit-identical ML results across different hardware/runtime versions.

Invalid contract: exit 2, exact `{status:'error',code:'invalid_contract',error:'Invalid ranking contract',stderr:''}`. Runtime/output failure: exit 1, same keys, code `ranking_failed`, error `Ranking failed`. Success exit 0, one strict JSON object only. Provider failure, partial output and malformed inference are runtime failures, not input errors. Suppress library progress/logging/tracebacks. Python never accesses media/object storage/DB; only packaged schema, provisioned model/tokenizer files and stdin are allowed. No network during inference, including telemetry.

## Laravel validation and persistence

`ProcessMediaAction` validates request before process creation and independently validates response against captured expected input/configuration, regardless of exit-0/success claims. Validate exact envelopes, types, sizes, provenance, SHA256 of sent bytes, all candidate references/bounds/M4 score/rank equality, score units (finite numeric, <=6 meaningful decimal digits), ordering after quantization, null eligibility, count and contiguous ranks. Reject misleading real/fake identity or inference flag. Do not recompute neural scores or hardcode fixture scores in Laravel. A structurally valid different in-range neural score is acceptable; correctness of inference is tested at the provider boundary. A hash echo binds a response to an invocation but is not cryptographic attestation of honest inference.

Share invariant validation at service and model completion boundaries. `markCompleted` must not accept a caller-invented snapshot: under claim, compare to freshly authoritative M4/configuration and locally constructed text hashes. No partial result/status writes on rejection. Completed reuse compares its recorded authority/selection without rerunning projection/inference.

Separate `media_clip_recommendations` row, unique `media_asset_id` cascading FK; belongs to asset, has-one relationship. Asset ownership is inherited; no public API. Also store nullable `m4_analysis_id` FK to the authoritative analysis with cascade and verify same asset; it is required for completed/unavailable outcomes but may be null for pending/failed upstream-missing attempts. One recommendation owner per asset/M4 authority; no version-history/reanalysis feature. Store status, outcome, reason, algorithm/version, parameters, recommendations JSONB, input_snapshot JSONB, execution_parameters JSONB, nullable sanitized error, timestamps. Statuses: pending, ranking, completed, unavailable, failed. Result fields null until terminal local outcome or validated worker success; failed attempts retain no partial result. Attempt settings may be retained separately as execution metadata. Completed outcome is `ranked` for worker results or `no_candidates` for local empty completion; unavailable outcome is `unavailable` with the specified reason. Failed uses a fixed error code and null result/outcome/reason fields.

Legal transitions: pending/failed -> ranking -> completed/unavailable/failed, only inside the locked transaction. Not-ready does not commit ranking. Starting failed retry clears only M5 error/incomplete results. Completed and unavailable are terminal. Their snapshots/timestamps remain unchanged, even if a transcript later completes. Check stored M4 analysis ID/version/full candidate authority and selected configuration against current intended authority before reuse. Identical selection reuses with zero calls; changed model/provider/algorithm/query/projection/runtime/scoring configuration or M4 authority produces sanitized `recommendation_version_conflict`, no mutation/no new inference/no success claim for the new selection. Operational timeout changes alone do not invalidate terminal reuse. Explicit future versioned reanalysis requires another authorized scope; no silent overwrite or hidden second row. Failed rows may retry with current validated configuration, captured afresh, since no successful result exists.

Local unavailable outcomes store K exact candidate references with semantic fields null, reason equal to the outcome reason, parameters with exactly the same keys/profile values as worker parameters but inference_performed=false and transcript_used=false; input_snapshot.request_sha256=null. Zero-candidate completion stores empty list and the same non-inference flags. Worker request_sha256 is copied into input_snapshot for worker completions, not an extra database column. These are validated local outcomes, not worker responses or real inference claims. Snapshot text hashes are empty-string hashes for unavailable candidates; no raw stale transcript is accessed.

### Claim, fencing, and recovery (#60 principles)

1. Validate operational configuration before any potentially contended write: strict timeout integer 1..120, default 60; derive lock wait timeout+5 once. Queue outer execution allowance must exceed the bounded M5 lock/process window plus upstream work; operator verifies existing worker configuration without redesigning queues.
2. Begin a M5-only transaction. Set PostgreSQL transaction-local lock_timeout before insert/unique/FK waits. Insert-on-conflict unique asset row and locked fresh reread. Do not create or transition recommendation rows outside this claim, including not-ready and exhaustion branches.
3. Read fresh upstream authority/readiness under the claim, capture immutable attempt input/configuration, and keep transition, at most one bounded process, validation and final write in that transaction. Upstream probe/scenes/audio/transcription/M4 work stays outside it. The lock, not an application mutex, is exclusive ownership. No detached asynchronous result writer is allowed.
4. All completion/failure callers, including stale callbacks, must acquire the same lock and reread status. Never save an earlier Eloquent instance. Terminal rows are immutable; a stale callback cannot downgrade asset or child. This transaction-only completion discipline is the attempt fence; no persisted lease or scheduler is introduced.
5. Expected contract/model/timeout failures commit sanitized failed M5 only. Unexpected PHP/programming/DB/connection failures escape the transaction and roll back; signal sanitized retryable `clip_ranking_aborted`, empty stderr, no previous exception. New-row rollback leaves absence; existing pending/failed snapshots survive exactly.
6. SQLSTATE 55P03 is busy only after rollback: contender makes no worker call and cannot finalize owner/asset. Other DB errors are not ordinary model failures. A deletion-winning race or missing locked row is deleted/no-op, no resurrection. Recheck deletion on a usable connection after rollback.
7. Not-ready: rollback/no changes, release 5 seconds, maximum 3 attempts. Actual exhaustion uses a new bounded serialized claim and resolves only unclaimed pending/failed work to failed/upstream_not_ready or failed/clip_ranking_aborted as appropriate. Locked/terminal/deleted rows and terminal assets remain unchanged. Busy duplicate returns unresolved without finalizing.
8. Local lock settings restore after commit/rollback; settings are not global. Failed retries serialize; first successful completion is reused by contenders with identical selection. No overlapping workers or stale write after lock release. Deletion cascades both asset and M4-dependent recommendation safely.

Pipeline stage is after M4 resolution. Explicit `clipRecommendationResolved` is true only for persisted completed/unavailable/failed outcome or valid terminal reuse, not busy/not-ready/deleted/aborted/version-conflict. Normal completion requires scene, audio, M4 and M5 resolution. Preserve extraction-failed asset status and all terminal asset states on reruns. Resolved M5 failure need not invalidate successful upstream processing. Version conflict is a fixed non-retryable caller error, leaves terminal rows unchanged, and may fail only a nonterminal asset through the existing safe failure path.

## Privacy, integration, and acceptance

No raw transcript, prompt, model result, request/response payload, model exception, SQL/bindings, credentials or private path in logs/errors/evidence. Fixed diagnostic categories, counts and elapsed time only; existing trusted local asset ID may identify a stage log but never enters worker/provider input. Bound stdout/stderr capture and discard unsafe content. No unsafe chained exceptions. Synthetic test data only. Snapshot hashes remain private owner-scoped data, and existing media responses must not expose recommendation internals.

Acceptance requires all rules above plus:

1. Corrective behavioral RED executed against preserved defective implementation, followed by explicit authorization before GREEN; no retroactive original-TDD claim.
2. Truthful fake CI and stubbed adapter unit tests, real PHP->Python fake subprocess plus real PostgreSQL persistence, all seven states, malformed whole-result rejection, owner isolation and #60 concurrency/failure proof.
3. Separate operator-prepared real model smoke before claiming real runtime verified: pinned offline artifacts/dependencies, synthetic input, network disabled, CPU envelope, actual model identity, finite scores, text sensitivity and repeat-run ordering within recorded environment. Never substituted by fake or import-only tests.
4. All existing worker/backend/PostgreSQL/MinIO/frontend/lint/build/Playwright/governance regressions executed, no mandatory skips or weakened assertions, independent Tester approval. No new UI does not exempt running-app regression review.
5. Evidence owner preserves history and records corrective RED/GREEN/REFACTOR and blockers honestly; Orchestrator reconciles current docs as M5 active/not shipped. Planner edits only this bundle.
6. Five final-head checks: Backend CI, Frontend CI, E2E CI, governance, pr-enforcement. Stop at `CI_GREEN_WAITING_HUMAN_MERGE`. No merge gate, merge, closure or next issue without human authorization.

## Planning readiness

Planning contradictions are resolved by this replacement specification. Execution prerequisites (approved disposable DB/storage, permitted commands, hash-locked optional runtime, provisioned model smoke environment) are explicit human/operator gates in plan.md, not claimed available or executed. If a required environment cannot be supplied, completion remains blocked rather than silently changing acceptance.

SPEC_READY
