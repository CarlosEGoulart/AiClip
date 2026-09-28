"""Contract validation for the rank_clips action.

Every fixture is hand-built from spec.md ("Strict worker protocol"): the
request shape, the pinned profile values and the fixed query are written out
in full instead of being derived from the production profile helpers, so a
defect in the production constants cannot validate its own request.

Coverage follows test-plan.md, "Worker unit, schema, action and CLI
coverage", item 1: the same strict request accepted by schema and runtime,
plus independent mutations for required and unknown fields, type mutants,
chronology, duration, index/rank permutations, M4 score type/value and the
configuration profile bounds.
"""

from __future__ import annotations

import math

from aiclip_worker.contracts import rank_clips_schema_errors, validate_contract

PROTOTYPE_QUERY = (
    "Engaging, self-contained short-form video clip highlight with a clear "
    "narrative or punchline."
)

# The two pinned profiles, written out independently of production code.
FAKE_CONFIGURATION = {
    "provider": "fake",
    "algorithm": "transcript_semantic_recommendation",
    "algorithm_version": "1.0.0",
    "projection_version": "1.0.0",
    "query_version": "1.0.0",
    "prototype_query": PROTOTYPE_QUERY,
    "model_id": "fake-ranking-v1",
    "model_revision": "1.0.0",
    "runtime_profile": "fake_v1",
    "normalization": "fixture_units_6",
    "max_tokens": 0,
    "batch_size": 0,
    "truncation": "none",
}

REAL_CONFIGURATION = {
    "provider": "cross_encoder",
    "algorithm": "transcript_semantic_recommendation",
    "algorithm_version": "1.0.0",
    "projection_version": "1.0.0",
    "query_version": "1.0.0",
    "prototype_query": PROTOTYPE_QUERY,
    "model_id": "cross-encoder/ms-marco-MiniLM-L6-v2",
    "model_revision": "233902d25c440f23af6f7d6e94d2946bac0bee0a",
    "runtime_profile": "minilm_cpu_v1",
    "normalization": "stable_sigmoid_half_up_6",
    "max_tokens": 512,
    "batch_size": 8,
    "truncation": "right_longest_first_512",
}


def configuration(provider: str = "fake") -> dict:
    """A deep copy of the pinned configuration of the selected profile."""
    source = FAKE_CONFIGURATION if provider == "fake" else REAL_CONFIGURATION
    return {key: value for key, value in source.items()}


def valid_rank_clips_contract(provider: str = "fake") -> dict:
    """The strict rank_clips request: K=2, both candidates usable."""
    return {
        "version": "1.0.0",
        "action": "rank_clips",
        "media": {"duration_ms": 40000},
        "candidates": [
            {
                "index": 0,
                "start_ms": 0,
                "end_ms": 10000,
                "m4_rank": 1,
                "m4_score": 0.91,
                "transcript_text": "engaging content",
            },
            {
                "index": 1,
                "start_ms": 10000,
                "end_ms": 20000,
                "m4_rank": 2,
                "m4_score": 0.72,
                "transcript_text": "second passage",
            },
        ],
        "configuration": configuration(provider),
    }


def k_candidates(count: int, first_text: str = "candidate zero") -> list:
    """Chronological authoritative candidates 0..count-1 with unique M4 ranks."""
    width = 1000
    return [
        {
            "index": position,
            "start_ms": position * width,
            "end_ms": (position + 1) * width,
            "m4_rank": position + 1,
            "m4_score": 0.5,
            "transcript_text": first_text if position == 0 else "",
        }
        for position in range(count)
    ]


def assert_rejected(contract) -> None:
    """The combined schema + runtime gate must refuse the request."""
    is_valid, reason = validate_contract(contract)
    assert is_valid is False, f"Invalid rank_clips contract accepted: {reason!r}"
    assert reason, "A rejected contract must carry a reason"


def assert_schema_rejects(contract) -> None:
    """The packaged schema alone must refuse schema-visible violations."""
    errors = rank_clips_schema_errors(contract)
    assert errors, "The packaged schema accepted a request it must reject"


# ---------------------------------------------------------------------------
# Acceptance controls
# ---------------------------------------------------------------------------


def test_validate_contract_accepts_valid_rank_clips():
    """Valid rank_clips contract must be accepted by both layers."""
    contract = valid_rank_clips_contract()
    assert rank_clips_schema_errors(contract) == []
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"Valid rank_clips rejected: {error}"
    assert error == ""


def test_validate_contract_accepts_real_profile_selection():
    """The pinned cross_encoder profile is accepted unchanged."""
    contract = valid_rank_clips_contract(provider="cross_encoder")
    assert rank_clips_schema_errors(contract) == []
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"Pinned real profile rejected: {error}"


def test_validate_contract_accepts_k_at_the_cap():
    """K=1000, the supported cap, must still validate."""
    contract = valid_rank_clips_contract()
    contract["media"]["duration_ms"] = 1000 * 1000
    contract["candidates"] = k_candidates(1000)
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"K=1000 rejected: {error}"


def test_validate_contract_accepts_exactly_16384_text_bytes():
    """16384 UTF-8 bytes is the inclusive canonical bound."""
    contract = valid_rank_clips_contract()
    contract["media"]["duration_ms"] = 40000
    contract["candidates"][0]["transcript_text"] = "a" * 16384
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"16384-byte text rejected: {error}"


def test_validate_contract_accepts_empty_transcript_text():
    """An empty string is allowed as long as another candidate is usable."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["transcript_text"] = ""
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"Empty transcript_text rejected: {error}"


# ---------------------------------------------------------------------------
# Unknown and missing fields
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_unknown_fields_in_rank_clips():
    """Unknown top-level fields must be rejected by the packaged schema."""
    contract = valid_rank_clips_contract()
    contract["unknown_field"] = "PRIVATE_SENTINEL"
    assert_schema_rejects(contract)
    is_valid, error = validate_contract(contract)
    assert is_valid is False
    assert "unknown_field" in error or "additional" in error.lower()


def test_validate_contract_rejects_missing_candidates():
    """Missing candidates must be rejected."""
    contract = valid_rank_clips_contract()
    del contract["candidates"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_missing_media():
    """Missing media must be rejected."""
    contract = valid_rank_clips_contract()
    del contract["media"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_missing_configuration():
    """Missing configuration must be rejected."""
    contract = valid_rank_clips_contract()
    del contract["configuration"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_missing_prototype_query():
    """Missing prototype_query in configuration must be rejected."""
    contract = valid_rank_clips_contract()
    del contract["configuration"]["prototype_query"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_missing_configuration_key():
    """Every configuration key is required; one missing key rejects."""
    for key in list(FAKE_CONFIGURATION):
        contract = valid_rank_clips_contract()
        del contract["configuration"][key]
        assert_schema_rejects(contract)
        assert_rejected(contract)


def test_validate_contract_rejects_missing_candidate_field():
    """Every candidate field is required; one missing key rejects."""
    for key in ("index", "start_ms", "end_ms", "m4_rank", "m4_score", "transcript_text"):
        contract = valid_rank_clips_contract()
        del contract["candidates"][0][key]
        assert_schema_rejects(contract)
        assert_rejected(contract)


def test_validate_contract_rejects_missing_transcript_text():
    """Missing transcript_text must be rejected (required field)."""
    contract = valid_rank_clips_contract()
    del contract["candidates"][0]["transcript_text"]
    assert_rejected(contract)


def test_validate_contract_rejects_wrong_action():
    """Wrong action for rank-clips must be rejected."""
    contract = valid_rank_clips_contract()
    contract["action"] = "analyze_clips"
    assert_rejected(contract)


def test_validate_contract_rejects_wrong_version():
    """Wrong version must be rejected."""
    contract = valid_rank_clips_contract()
    contract["version"] = "2.0.0"
    assert_schema_rejects(contract)
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# Type mutants
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_invalid_candidate_types():
    """A Python bool is not an integer index."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["index"] = True
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_float_index():
    """Integral floats must not pass for integer fields."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["index"] = 1.0
    assert_rejected(contract)


def test_validate_contract_rejects_numeric_strings():
    """Numeric strings must not substitute for numbers."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["end_ms"] = "10000"
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_rank_clips_contract()
    contract["candidates"][0]["m4_score"] = "0.91"
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_rank_clips_contract()
    contract["media"]["duration_ms"] = "40000"
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_null_values():
    """null is not a default: required typed fields reject it."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["transcript_text"] = None
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_rank_clips_contract()
    contract["media"]["duration_ms"] = None
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_rank_clips_contract()
    contract["candidates"][0]["m4_rank"] = None
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_object_list_confusion():
    """Object/list substitutions reject at every level."""
    contract = valid_rank_clips_contract()
    contract["candidates"] = {"index": 0}
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_rank_clips_contract()
    contract["candidates"][0] = ["index", 0]
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_rank_clips_contract()
    contract["media"] = [40000]
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_rank_clips_contract()
    contract["configuration"] = [1, 2, 3]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_nonfinite_m4_score():
    """NaN and Infinity never survive JSON or runtime validation."""
    for value in (math.nan, math.inf, -math.inf):
        contract = valid_rank_clips_contract()
        contract["candidates"][0]["m4_score"] = value
        assert_rejected(contract)


def test_validate_contract_rejects_bool_m4_score():
    """A bool is not a numeric M4 score."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["m4_score"] = True
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# Chronology, duration, index and rank permutations
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_duration_zero_or_negative():
    """Zero or negative duration must be rejected."""
    for value in (0, -1):
        contract = valid_rank_clips_contract()
        contract["media"]["duration_ms"] = value
        assert_schema_rejects(contract)
        assert_rejected(contract)


def test_validate_contract_rejects_duration_above_bound():
    """Duration above 2147483647 ms must be rejected."""
    contract = valid_rank_clips_contract()
    contract["media"]["duration_ms"] = 2147483648
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_candidate_index_gaps():
    """Candidate index gaps must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["index"] = 5
    assert_rejected(contract)


def test_validate_contract_rejects_duplicate_candidate_index():
    """Duplicate candidate index must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["index"] = 0
    assert_rejected(contract)


def test_validate_contract_rejects_non_chronological_candidates():
    """Candidates must be listed chronologically, index == position."""
    contract = valid_rank_clips_contract()
    first, second = contract["candidates"]
    contract["candidates"] = [second, first]
    assert_rejected(contract)


def test_validate_contract_rejects_negative_rank():
    """A negative M4 rank must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["m4_rank"] = -1
    assert_rejected(contract)


def test_validate_contract_rejects_rank_not_1_to_k():
    """Rank not in 1..K must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["m4_rank"] = 5
    assert_rejected(contract)


def test_validate_contract_rejects_duplicate_ranks():
    """Duplicate M4 ranks must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["m4_rank"] = 1
    assert_rejected(contract)


def test_validate_contract_rejects_candidate_end_beyond_duration():
    """Candidate end_ms beyond media duration must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["end_ms"] = 50000
    assert_rejected(contract)


def test_validate_contract_rejects_zero_length_candidate():
    """end_ms must stay strictly greater than start_ms."""
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["end_ms"] = 10000
    assert_rejected(contract)


def test_validate_contract_rejects_negative_candidate_bounds():
    """Negative candidate boundaries must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["start_ms"] = -1
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# M4 score value bounds
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_m4_score_out_of_range():
    """M4 scores must stay inside the inclusive [0,1] range."""
    for value in (-0.1, 1.1, 1000):
        contract = valid_rank_clips_contract()
        contract["candidates"][0]["m4_score"] = value
        assert_schema_rejects(contract)
        assert_rejected(contract)


def test_validate_contract_accepts_m4_score_endpoints():
    """0 and 1 are valid M4 scores."""
    for value in (0, 1):
        contract = valid_rank_clips_contract()
        contract["candidates"][0]["m4_score"] = value
        is_valid, error = validate_contract(contract)
        assert is_valid is True, f"Endpoint M4 score {value} rejected: {error}"


# ---------------------------------------------------------------------------
# Candidate count and usable text
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_empty_candidates():
    """K=0 is a local outcome, never a worker request."""
    contract = valid_rank_clips_contract()
    contract["candidates"] = []
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_k_above_the_cap():
    """K=1001 exceeds the supported cap of 1000."""
    contract = valid_rank_clips_contract()
    contract["media"]["duration_ms"] = 1001 * 1000
    contract["candidates"] = k_candidates(1001)
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_all_candidates_without_text():
    """A request with no usable candidate text must be rejected."""
    contract = valid_rank_clips_contract()
    for candidate in contract["candidates"]:
        candidate["transcript_text"] = ""
    assert_rejected(contract)


def test_validate_contract_rejects_text_above_16384_bytes():
    """16385 UTF-8 bytes must be rejected as a whole."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["transcript_text"] = "a" * 16385
    assert_schema_rejects(contract)
    assert_rejected(contract)

    # Two-byte characters: the byte bound, not the character count, decides.
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["transcript_text"] = "é" * 8193  # 16386 bytes
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# Configuration profile bounds
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_empty_configuration_object():
    """Empty configuration object must be rejected."""
    contract = valid_rank_clips_contract()
    contract["configuration"] = {}
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_unknown_fields_in_configuration():
    """Unknown fields in configuration must be rejected."""
    contract = valid_rank_clips_contract()
    contract["configuration"]["unknown"] = "value"
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_unselected_provider():
    """An unset provider selection is invalid, never an implicit default."""
    contract = valid_rank_clips_contract()
    del contract["configuration"]["provider"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_unknown_provider_selection():
    """An unknown selection fails closed instead of guessing a profile."""
    contract = valid_rank_clips_contract()
    contract["configuration"]["provider"] = "auto"
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_profile_value_mutation():
    """Every profile value must equal the pinned profile of the selection."""
    mutations = {
        "algorithm": "cross_encoder_reranker",
        "algorithm_version": "1.0.1",
        "projection_version": "1.0.1",
        "query_version": "1.0.1",
        "prototype_query": "A different query entirely.",
        "model_id": "cross-encoder/other-model",
        "model_revision": "main",
        "runtime_profile": "gpu_v1",
        "normalization": "sigmoid",
        "max_tokens": 511,
        "batch_size": 9,
        "truncation": "left",
    }
    for key, value in mutations.items():
        contract = valid_rank_clips_contract()
        contract["configuration"][key] = value
        is_valid, reason = validate_contract(contract)
        assert is_valid is False, f"configuration mutation {key}={value!r} accepted: {reason}"


def test_validate_contract_rejects_cross_profile_identity_mix():
    """Real identity values with the fake selector (and vice versa) reject."""
    real_identity = ("model_id", "model_revision", "runtime_profile", "normalization")

    for key in real_identity:
        contract = valid_rank_clips_contract(provider="fake")
        contract["configuration"][key] = REAL_CONFIGURATION[key]
        is_valid, reason = validate_contract(contract)
        assert is_valid is False, f"fake profile carrying {key} accepted: {reason}"

        contract = valid_rank_clips_contract(provider="cross_encoder")
        contract["configuration"][key] = FAKE_CONFIGURATION[key]
        is_valid, reason = validate_contract(contract)
        assert is_valid is False, f"real profile carrying {key} accepted: {reason}"


def test_validate_contract_rejects_wrong_fixed_query():
    """The fixed query is not user controlled."""
    contract = valid_rank_clips_contract()
    contract["configuration"]["prototype_query"] = (
        "Engaging, self-contained, viral-worthy short-form video clip "
        "highlight with clear narrative or punchline."
    )
    assert_schema_rejects(contract)
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# Legacy controls (unchanged actions must keep validating)
# ---------------------------------------------------------------------------


def test_validate_contract_accepts_legacy_probe():
    """Legacy probe contract must still validate (passing control)."""
    contract = {
        "version": "1.0.0",
        "action": "probe",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {"disk": "media", "key": "test.mp4", "mime_type": "video/mp4"},
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-16T10:00:00Z",
    }
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"Valid probe rejected: {error}"
    assert error == ""


def test_validate_contract_accepts_legacy_analyze_clips():
    """Legacy analyze_clips contract must still validate (passing control)."""
    contract = {
        "version": "1.0.0",
        "action": "analyze_clips",
        "media": {"duration_ms": 40000},
        "scenes": [
            {"index": 0, "start_ms": 0, "end_ms": 10000},
            {"index": 1, "start_ms": 10000, "end_ms": 20000},
        ],
        "configuration": {
            "min_duration_ms": 5000,
            "target_duration_ms": 10000,
            "max_duration_ms": 20000,
            "max_candidates": 20,
            "weights": {"duration_fit": 50, "speech_coverage": 30, "boundary_alignment": 20},
        },
    }
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"Valid analyze_clips rejected: {error}"
    assert error == ""


def test_legacy_schema_still_rejects_rank_clips_as_analyze_clips():
    """The additive action must not weaken the legacy schema branch."""
    contract = valid_rank_clips_contract()
    contract["action"] = "analyze_clips"
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# Schema/runtime agreement
# ---------------------------------------------------------------------------


def test_schema_and_runtime_accept_the_same_strict_request():
    """Both layers accept the strict request; neither accepts alone."""
    contract = valid_rank_clips_contract(provider="cross_encoder")
    assert rank_clips_schema_errors(contract) == []
    is_valid, _reason = validate_contract(contract)
    assert is_valid is True


def test_no_bypass_branch_accepts_what_the_schema_refuses():
    """The combined gate must keep every packaged-schema rejection."""
    mutants = []

    unknown_top = valid_rank_clips_contract()
    unknown_top["extra"] = 1
    mutants.append(unknown_top)

    unknown_config = valid_rank_clips_contract()
    unknown_config["configuration"]["extra"] = 1
    mutants.append(unknown_config)

    missing_required = valid_rank_clips_contract()
    del missing_required["media"]
    mutants.append(missing_required)

    wrong_const = valid_rank_clips_contract()
    wrong_const["version"] = "1.0.1"
    mutants.append(wrong_const)

    not_an_object = valid_rank_clips_contract()
    not_an_object["candidates"] = "not a list"
    mutants.append(not_an_object)

    empty_k = valid_rank_clips_contract()
    empty_k["candidates"] = []
    mutants.append(empty_k)

    for mutant in mutants:
        assert rank_clips_schema_errors(mutant), "fixture must be refused by the schema"
        is_valid, reason = validate_contract(mutant)
        assert is_valid is False, f"the combined gate accepted a schema rejection: {reason}"
