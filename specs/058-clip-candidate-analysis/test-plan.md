# Test Plan: Issue #58

## Strategy and evidence ownership

This plan specifies expected verification, not results. Builder first receives a **test-only** assignment and demonstrates behavior/assertion RED at existing entry points; implementation requires subsequent Orchestrator authorization. Worker `validate_contract()` must reject the newly valid analysis contract on the baseline, and existing Laravel job orchestration must fail the assertion that analysis was invoked for ready inputs. Dependency/import/syntax/migration failures are not valid RED. Include passing legacy controls.

Use pytest for worker models/algorithm/contract/action/CLI, Pest for Laravel unit/feature/database/process/job behavior, real PostgreSQL for concurrency, the existing real MinIO and PySceneDetect regressions, and Playwright for existing running-app workflows. New fakes belong only to tests/process/provider boundaries; the actual analysis algorithm is used in runtime and CI.

Exactly one `specs/058-clip-candidate-analysis/evidence.md` is maintained by executing Builder/Tester, never Planner, with `### RED`, `### GREEN`, and `### REFACTOR` headings. Record actual commands, exit codes, named assertion failures and reasons, totals/assertions/skips/risky counts, integration execution, independent verdict, and final-head CI references/log findings. Sanitize evidence; no raw timing contracts, transcript/media content, secrets, signed URLs, or personal data from real users. Synthetic golden values in tests are allowed.

## Deterministic fixtures and oracles

- Synthetic integer timing fixtures, no media binaries required for new analysis tests. Use positive persisted probe duration consistent with each fixture.
- Empty scenes; one eligible scene; one too-short/too-long scene; multiple adjacent scenes; gaps; one source scene spanning the full duration; mixed eligible/ineligible scenes; more candidates than the cap.
- Include the exact golden example from `spec.md` and expected normalized criteria/scores/ranks. Test expectations are hand-derived constants or independent simple rational calculations, not calls to the production scorer.
- Add transcript fixtures: omitted, present empty, aligned endpoints, partial overlap, interval spanning two scene boundaries, gaps, zero-length intervals, full coverage, and out-of-range/misaligned invalid timing. For text/privacy tests, mutate synthetic persisted text without changing timing.
- Use synthetic malformed payload sentinels in text, URL, secret, nested unknown field, stdout, stderr, exception messages, and error envelopes. Assert they never escape into logs/exceptions/persistence/error evidence.
- Configure alternate values for **each** duration/limit/weight field. Prove every fixed policy/version/limit and effective weight is represented and validated, not merely that a `parameters` key exists.

## Worker coverage

Suggested files: `test_clip_analysis.py`, `test_contract_analyze_clips.py`, `test_analyze_clips.py`, `test_cli_analyze_clips.py`. Follow existing fixture conventions without adding model/network dependencies.

### W1 — Strict input/configuration/result models

| Cases | Expected assertions |
|---|---|
| Valid scene-only, timing-enriched, empty-scene and present-empty-transcript models | Exact accepted typed values and correct availability mode. |
| Missing/null/scalar/list where an object is required; object where list is required; missing required fields | Controlled invalid result, never inferred success/default. |
| Integer fields: negative, float including `1.0`, string, boolean, null | Reject invalid types/values, including Python boolean-as-integer. |
| Duration zero/negative/missing/conflicting/overflow; empty scenes with invalid duration | Reject before any empty-list shortcut. |
| Scenes: index gaps/nonzero start/duplicates, reverse order, negative start, zero/negative interval, end beyond duration, overlap | Reject; allow adjacency, gaps, final end exactly duration and sequential indexes. |
| Transcripts: missing timing, negative/reversed/overflow/fractional endpoints, unsorted/overlapping intervals | Reject; allow ordered zero-length segments and endpoints exactly at media boundaries. |
| Configuration: missing field/weight, unknown field, min/target/max ordering violations, invalid cap, invalid weight/all-zero effective denominator | Reject; allow min=target=max, zero optional weights, positive duration weight. |
| Candidate fields: invalid index/time/rank/score/criteria/source ref or list/object shape | Reject all invariants, not only per-item type. |
| NaN, positive/negative Infinity, numeric strings, booleans, out-of-range score/criteria, unrecognized criterion | Reject in typed models and action serialization. |
| Algorithm/version/parameters missing/blank/unsupported, configuration/policies/provenance mismatch | Reject, including on empty candidates. |
| Input/list/cap limit exact boundary and one beyond | Accept valid boundary, reject overflow without truncation or invocation. |

Use parameterized independent mutations for every required field, including nested configuration/weights/criteria. Assert controlled validation, not incidental attribute errors or traceback text.

### W2 — Algorithm, exact arithmetic, selection, provenance

1. Exact golden scene-only and enriched outputs, including indexes/ranks/source lists and score values `1`, `0.5`, `0.75`, `0.6` where applicable.
2. Zero/one/many scenes; all-short/all-long => executed zero candidates with full provenance. Equality at min/target/max is eligible. No extension/trim/merge/split/fallback or candidates in scene gaps.
3. Duration fit symmetric ratio, extreme valid integers, half-up exact-half and near-half rounding, six-decimal representation. Independently verify integer weighted score rounding after criterion quantization.
4. Coverage only includes intersection length; multiple non-overlapping segments sum once; zero-length segments add nothing; full coverage is exactly 1.
5. Boundaries exactly at transcript endpoints are safe; strict interior cuts contribute 0 for that boundary; one/two safe boundaries yield 0.5/1. Test segments crossing scene boundaries without moving candidate endpoints.
6. Unavailable transcript has zero optional criteria/effective weights and `transcript_used=false`; score uses only duration. Present empty transcript has `transcript_used=true`, speech 0, boundary 1, and configured weights retained. No fake text needed.
7. Modify each weight independently in fixtures with different criteria; check numerical impact, ranking impact where expected, and exact configured/effective provenance. Scaling all weights by the same integer factor preserves normalized scores. Zero optional weights and positive duration weight work.
8. Modify min/target/max/max_candidates individually; check boundary inclusion/exclusion, score changes, selection cap, and complete recorded configuration. Do not assume every arbitrary fixture changes under every parameter.
9. Ties resolve by specified keys; top-K before chronological serialization; candidate indexes `0..K-1` may differ from source indexes and rank. Ranks are `1..K`, unique/contiguous. Include a higher-scoring later scene so list order differs from rank order.
10. Repeat identical inputs/configuration within and across processes/hash seeds; same analysis JSON semantics every time. Changing request transport order or irrelevant local asset ID/path/text must not change analysis. No mutation of input objects.
11. Assert all fixed parameter identifiers, scale, rounding, limits, algorithm/version, configuration and effective weights; wrong/missing provenance fails result validation. Test a plausible but incorrectly scored/ranked/selected result rather than only obviously malformed values.

### W3 — Schema/action/CLI and privacy

- Minimal metadata request has no legacy identifiers/storage and is valid. Every forbidden/unknown top-level and nested field fails; representative `text`, `full_text`, user/project/asset IDs, credentials, URLs, language, output storage, and derived asset metadata are rejected.
- `scenes` missing/null is invalid; empty scenes valid only with all other required fields. Pending/failed statuses cannot be smuggled into this ready-input branch. Wrong/missing action for `analyze-clips` fails.
- Version missing, empty, non-string, malformed, `2.0.0`, and unsupported `1.x` do not crash and fail closed; exact `1.0.0` succeeds. Legacy probe default and all four existing actions continue to validate under their old requirements, including detect-scenes duration.
- CLI stdin and retained JSON/file input modes: exit 0/strict success, exit 2/structured invalid-contract error, exit 1/structured action/result failure. Test missing input, invalid JSON, scalar/list/null JSON, non-finite literals/overflow, unknown fields, excessive byte size, and unavailable input file without leaking paths. No traceback, progress output, partial candidate list, or payload-reflecting diagnostics.
- Patch network/socket/database/model/media-file/process entry points to fail if called by analysis; valid analysis still succeeds. Reading its input stream/contract file and packaged schema is permitted. No FFprobe/FFmpeg/scene/transcription/model calls or downloads.
- Capture stdout/stderr/logging; stdout is one protocol envelope, stderr/logs do not contain test sentinels or raw payloads. Reject malformed analyzer results before serialization; `allow_nan`-style fallback must not emit invalid JSON.

## Laravel coverage

Suggested suites: `Feature/Models/MediaClipAnalysisTest.php`, `Feature/Jobs/ProcessMediaAssetClipAnalysisTest.php`, dedicated contract/validator/action tests, and `Feature` PostgreSQL concurrency/integration tests. Retain existing assertions and explicit action mocks when extending old fixtures.

### L1 — Persistence, lifecycle, cascade, ownership

- Migration up/down on disposable databases, unique media FK, casts, inverse/has-one relationships. Only one analysis per asset; separate owners/assets stay isolated.
- Pending fields have no completed result defaults. Pending -> analyzing -> completed; pending -> analyzing -> failed; failed -> analyzing clears error; subsequent validated success; illegal transitions cannot mutate persisted state.
- Exact candidate/parameters/input/operational snapshot persistence; no text/legacy envelope/user identity/URL copied. Zero-candidate completed row contains actual `[]`, full valid metadata, and evidence of actual worker invocation.
- Completed snapshot reuse does not invoke worker or change timestamps/result/error after repeated calls, configuration changes, or later transcript completion.
- Asset/project deletion cascades analysis. Missing asset or delete-during-processing cannot resurrect a row or leak another owner's data. Failure paths preserve existing scene/transcript values byte-for-byte as appropriate.

### L2 — Contract and transport

- Explicit metadata factory projects only required fields; legacy factories remain compatible. Request has persisted duration/scenes and only completed timing; unavailable timing omitted, completed empty timing present.
- Independent PHP request preflight rejects malformed input/configuration, all strict types/unknown privacy fields, incompatible version, count/byte limits, and invalid duration before `createProcess()` runs.
- Command is argument-list `analyze-clips`, no shell; contract travels via stdin, not argv. Test timeout setting/default/invalid ranges and recorded operational settings/derived lock wait.
- Successful strict output parsed with object/list distinction preserved. Nonzero exit, timeout, malformed/non-finite JSON, wrong status, trailing non-JSON output, malicious error text/stderr are sanitized. Assert no raw stdout/stderr/contract in exceptions, chained exceptions, logs, DB errors, or queued failure data.
- One real PHP-to-Python CLI call with synthetic timing exercises exact request, CLI result, PHP validation, and PostgreSQL persisted success. This complements, not replaces, the unit process fakes. No media/network credentials needed.

### L3 — Independent malformed-success rejection

For each mutation return worker exit 0 and `status=success`; assert analysis fails closed, no completed or partial candidates are persisted, sanitized error is stored, and upstream successes are preserved. Also call the model completion boundary directly to prove the validator cannot be bypassed.

1. Missing/null/wrong-type/unknown envelope or `analysis`; algorithm and version missing/blank/unsupported; parameters missing/null/list/empty object; candidates missing/null/object/string.
2. Each configuration field/weight and each provenance field absent, wrong type, changed, unknown, or inconsistent with input/transcript availability; limits/scale/policy/version mismatch.
3. Candidate missing/unknown keys; index boolean/float/string/negative/duplicate/noncontiguous/out-of-order; start negative/noninteger; end <= start or beyond persisted duration; incorrect whole-scene boundary; duration ineligible.
4. Score and each criterion missing/non-numeric/boolean/non-finite/outside `[0,1]`; fabricated score/criterion within `[0,1]` but inconsistent with formula; more than six-decimal meaningful precision. Inject nonfinite PHP values through a test double as well as invalid JSON transport cases.
5. Rank zero/negative/fractional/string/boolean, duplicate, gaps, above K, or wrong order/tie-break despite being a permutation of `1..K`.
6. Source list missing/empty/object, duplicate/multiple/noninteger/unknown index, or valid index pointing to another scene; duplicate whole-scene candidates, overlap, chronological list violation.
7. Too many candidates, missing eligible result when under cap, selecting wrong top-K, or `[]` despite eligible scenes. Legitimate empty input/filter results with complete provenance pass.
8. Scene/duration/transcript invalid in persisted input despite completed status: never trust flags alone. Capture precise unchanged upstream row data after failure.

### L4 — Claim/concurrency and crash recovery

Use real PostgreSQL separate connections/processes with a deterministic barrier and a recording worker double. No timing-only sleeps as the sole synchronization and no SQLite-only proof.

| Scenario | Required assertions |
|---|---|
| Two first creations for one asset | One DB row; unique constraint exercised/handled; one active worker; second rereads completed result. |
| Two retries of the same failed analysis | Retry error cleared by owner; no overlap of worker invocations; completed first retry reused. |
| First owner returns malformed/error output | Atomic failed result, no partial data; a subsequent retry may run serially and succeed. |
| Contender sees lock wait timeout | Busy/no success claim; no mutation or failure of owner's analysis/asset. |
| Worker timeout | Process bounded/terminated; owner commits sanitized failed analysis; upstream successes remain. |
| Unhandled exception/connection termination before commit | No committed analyzing/partial JSON; previous pending/failed row survives; queue retry can claim it. |
| Queue exhausts after rollback or upstream-not-ready | Unclaimed analysis resolves failed through legal lifecycle and asset becomes terminal, not processing forever. |
| Late failure callback / stale caller after completed result | Completed result unchanged; cannot overwrite it with failure. |
| Asset deletion/race | Cascade/no orphan; no recreated asset/analysis; no competing late write. |

Also test row locks are not held during probe, scene detection, extraction, or transcription, and local lock-timeout settings do not leak to later transactions. Assert outcome/call counts, not merely row uniqueness.

### L5 — Pipeline matrix and reexecution

| Scene state | Audio/transcript state | Expected clip outcome and asset behavior |
|---|---|---|
| Completed valid | Completed valid transcript | Timing-enriched completed snapshot after upstream resolution; asset completes. |
| Completed valid | No audio | Scene-only execution; no extraction/transcription; asset completes. |
| Completed valid | Absent or failed transcript after audio resolution | Scene-only execution; failed transcript preserved; asset completes unless extraction failed. |
| Completed empty or all ineligible | Optional transcript resolved | Analyzer invoked once, completed empty result, asset completes. |
| Pending/detecting | Otherwise resolved | Not-ready; no clip success/worker; bounded retry, exhaustion terminal failure. |
| Completed valid | Pending/transcribing and audio active | Not-ready, not scene-only premature snapshot; bounded retry. |
| Failed | Audio/transcription succeeds or fails | Failed clip attempt without worker; transcript path remains independent; resolved asset completes. |
| Missing after scene stage resolved/no-video applicability | Audio resolved | Failed `upstream_scene_missing`, not invented candidates; resolved asset completes. |
| Completed valid | Extraction fails | Scene-only clip attempt still runs; asset retains failed state; no transcription attempted. |
| Completed but invalid scenes/duration | Any resolved | Clip fails preflight, no worker, no corrupt upstream overwrite. |
| Completed valid | Completed malformed/out-of-bounds timing | Clip fails preflight, not silent downgrade; original transcript unchanged. |
| Completed valid | No audio with stale completed transcript | Scene-only according to no-audio rule; stale text never sent. |
| Completed valid | Extraction failed with stale completed transcript | Scene-only according to extraction-failure rule; asset stays failed, stale timing/text not used. |

Verify normal asset completion requires all three flags, not just original scene/audio flags. Existing probed/terminal assets reuse valid persisted probe; a failed clip can retry without resetting terminal asset state, including when an upstream-not-ready retry exhausts. Completed clip analysis is terminal even if a failed transcript later succeeds. Probe failure remains an asset failure with no fabricated input; deleted asset/idempotency mismatch remains a safe no-op. Validate sanitized failures do not strand the asset in processing or mark clips completed without running.

## Full verification baseline — preserve, do not assume totals

Execute all existing cases plus new tests. Known baseline counts are references, not anticipated evidence; record actual new totals. Required existing tests may not be removed, skipped, weakened, or replaced with mock-only approximations.

| Suite | Baseline to preserve | Execution guidance |
|---|---|---|
| Worker | 208 passed, 0 skipped | `services/worker`: `python -m pytest tests/ -v`; existing real FFmpeg-generated PySceneDetect integration must execute, not optional-skip. |
| Laravel | 316 tests / 1762 assertions / 0 risky | `apps/api`: `php artisan test --compact`, matching PostgreSQL CI environment; execute all new coverage too. |
| Real MinIO | 4/4 | Existing real storage integration tests must actually run against MinIO; verify names/results in full backend output. |
| PHP style | Clean | `apps/api`: `vendor/bin/pint --dirty --format agent`, then rerun relevant tests if files changed. |
| Frontend | 187 tests | `apps/web`: `npm run test -- --maxWorkers=1`, `npm run lint`, `npm run build`. |
| Playwright E2E | 75 tests | `apps/web`: `npm run test:e2e` with managed servers/PostgreSQL/Chromium. |
| Governance | 170 tests | Execute the existing documented governance test command unchanged; this is regression testing, **not** invocation of a merge gate. |

If a suite or environment fails, record it as a blocker and fix only authorized scope through Builder/Orchestrator. No assertion-free tests, permissive success mocks, missing-real-dependency skips, or expected-count-only claims. No governance/control-plane edits.

## Independent running-application review

Tester independently executes new CLI/metadata/database behavior and inspects actual persisted results using synthetic owned assets. Exercise no-audio, transcript-enriched, malformed-success, timeout, retry, empty-result, and concurrency/failure paths; API review confirms existing ownership/response behavior is unchanged and no analysis payload leaks into media responses.

No new candidate UI exists, so candidate-screen visual tests and recommendation-quality tests are N/A with that explicit reason. Existing application interaction is mandatory: auth/projects/media upload/list/delete at `390x844`, `768x1024`, `1440x900`. Inspect screenshots, layout/overflow, success/loading/empty/error/disabled states, focus/keyboard fundamentals, media sizing, browser console, network requests/API responses, uncaught errors, resources, and redirects. Unexpected console errors or unexpected 4xx/5xx fail review. Existing UI screenshots must not contain secrets or real user content.

## Final acceptance and CI gate

Map W1–W3 to algorithm/contract/privacy requirements; L1–L3 to ownership/persistence/independent validation; L4 to concurrency/recovery; L5 to pipeline semantics. Every spec acceptance criterion needs executable evidence or an explicit scope-review finding.

Independent Tester approves only after actual artifacts and running behavior are reviewed. Orchestrator then verifies the final PR head's Backend CI/tests (including real integrations), Frontend CI/test with lint/build steps, E2E CI/e2e, governance, and pr-enforcement checks and reads their actual logs. No stale-head result is sufficient. Confirm docs still mark #58 in progress and make only a conditional post-merge M4 recommendation.

Stop at `CI_GREEN_WAITING_HUMAN_MERGE`. **No merge-gate invocation, merge, issue closure, or next lifecycle is authorized.**
