"""Contract validation for the render_clips action.

Every fixture is hand-built from spec.md ("Strict worker protocol"): the
request shape, the pinned profile values and the fixed query are written out
in full instead of being derived from the production profile helpers, so a
defect in the production constants cannot validate its own request.

Coverage follows test-plan.md, "Worker unit, schema, action and CLI
coverage", item 1: the same strict request accepted by schema and runtime,
plus independent mutations for required and unknown fields, type mutants,
chronology, duration, index/rank permutations, semantic score type/value
and the configuration profile bounds.
"""

from __future__ import annotations

import copy
import math

from aiclip_worker.contracts import render_clips_schema_errors, validate_contract

# The pinned render configuration, written out independently of production code.
RENDER_CONFIGURATION = {
    "target_width": 1080,
    "target_height": 1920,
    "target_fps": 30,
    "video_codec": "libx264",
    "video_bitrate_kbps": 5000,
    "audio_codec": "aac",
    "audio_bitrate_kbps": 128,
}

# Valid source media info
VALID_SOURCE_MEDIA = {
    "disk": "media",
    "key": "projects/1/assets/1/source.mp4",
    "width": 1920,
    "height": 1080,
    "video_codec": "h264",
    "audio_codec": "aac",
}

# Valid recommendation with 2 candidates
VALID_RECOMMENDATION = {
    "candidates": [
        {
            "index": 0,
            "start_ms": 0,
            "end_ms": 10000,
            "semantic_rank": 1,
            "semantic_score": 0.95,
        },
        {
            "index": 1,
            "start_ms": 10000,
            "end_ms": 20000,
            "semantic_rank": 2,
            "semantic_score": 0.75,
        },
    ],
    "candidate_index": 0,
}


def valid_render_clips_contract(candidate_index: int = 0) -> dict:
    """The strict render_clips request. Returns a fresh independent object every call."""
    return {
        "version": "1.0.0",
        "action": "render_clips",
        "media": {"duration_ms": 30000},
        "recommendation": {
            "candidates": copy.deepcopy(VALID_RECOMMENDATION["candidates"]),
            "candidate_index": candidate_index,
        },
        "candidate_index": candidate_index,
        "configuration": copy.deepcopy(RENDER_CONFIGURATION),
        "source_media": copy.deepcopy(VALID_SOURCE_MEDIA),
        "media_asset_id": 1,
        "recommendation_id": 1,
    }


def k_candidates(count: int) -> list:
    """Chronological authoritative candidates 0..count-1 with unique semantic ranks."""
    width = 10000
    return [
        {
            "index": position,
            "start_ms": position * width,
            "end_ms": (position + 1) * width,
            "semantic_rank": position + 1,
            "semantic_score": 0.5,
        }
        for position in range(count)
    ]


def assert_rejected(contract) -> None:
    """The combined schema + runtime gate must refuse the request."""
    is_valid, reason = validate_contract(contract)
    assert is_valid is False, f"Invalid render_clips contract accepted: {reason!r}"
    assert reason, "A rejected contract must carry a reason"


def assert_schema_rejects(contract) -> None:
    """The packaged schema alone must refuse schema-visible violations."""
    errors = render_clips_schema_errors(contract)
    assert errors, "The packaged schema accepted a request it must reject"


# ---------------------------------------------------------------------------
# Acceptance controls
# ---------------------------------------------------------------------------


def test_validate_contract_accepts_valid_render_clips():
    """Valid render_clips contract must be accepted by both layers."""
    contract = valid_render_clips_contract()
    assert render_clips_schema_errors(contract) == []
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"Valid render_clips rejected: {error}"
    assert error == ""


def test_validate_contract_accepts_k_at_the_cap():
    """K=1000, the supported cap, must still validate."""
    contract = valid_render_clips_contract()
    contract["media"]["duration_ms"] = 1000 * 10000
    contract["recommendation"]["candidates"] = k_candidates(1000)
    contract["recommendation"]["candidate_index"] = 0
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"K=1000 rejected: {error}"


# ---------------------------------------------------------------------------
# Unknown and missing fields
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_unknown_fields_in_render_clips():
    """Unknown top-level fields must be rejected by the packaged schema."""
    contract = valid_render_clips_contract()
    contract["unknown_field"] = "PRIVATE_SENTINEL"
    assert_schema_rejects(contract)
    is_valid, error = validate_contract(contract)
    assert is_valid is False
    assert "unknown_field" in error or "additional" in error.lower()


def test_validate_contract_rejects_missing_version():
    """Missing version must be rejected."""
    contract = valid_render_clips_contract()
    del contract["version"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_missing_action():
    """Missing action must be rejected."""
    contract = valid_render_clips_contract()
    del contract["action"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_missing_media():
    """Missing media must be rejected."""
    contract = valid_render_clips_contract()
    del contract["media"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_missing_recommendation():
    """Missing recommendation must be rejected."""
    contract = valid_render_clips_contract()
    del contract["recommendation"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_missing_candidate_index():
    """Missing candidate_index must be rejected."""
    contract = valid_render_clips_contract()
    del contract["recommendation"]["candidate_index"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_missing_configuration():
    """Missing configuration must be rejected."""
    contract = valid_render_clips_contract()
    del contract["configuration"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_missing_source_media():
    """Missing source_media must be rejected."""
    contract = valid_render_clips_contract()
    del contract["source_media"]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_missing_configuration_key():
    """Every configuration key is required; one missing key rejects."""
    for key in list(RENDER_CONFIGURATION):
        contract = valid_render_clips_contract()
        del contract["configuration"][key]
        assert_schema_rejects(contract)
        assert_rejected(contract)


def test_validate_contract_rejects_missing_candidate_field():
    """Every candidate field is required; one missing key rejects."""
    for key in ("index", "start_ms", "end_ms", "semantic_rank", "semantic_score"):
        contract = valid_render_clips_contract()
        del contract["recommendation"]["candidates"][0][key]
        assert_schema_rejects(contract)
        assert_rejected(contract)


def test_validate_contract_rejects_missing_source_media_key():
    """Every source_media key is required; one missing key rejects."""
    for key in ("disk", "key", "width", "height", "video_codec", "audio_codec"):
        contract = valid_render_clips_contract()
        del contract["source_media"][key]
        assert_schema_rejects(contract)
        assert_rejected(contract)


def test_validate_contract_rejects_wrong_action():
    """Wrong action for render_clips must be rejected."""
    contract = valid_render_clips_contract()
    contract["action"] = "rank_clips"
    assert_rejected(contract)


def test_validate_contract_rejects_wrong_version():
    """Wrong version must be rejected."""
    contract = valid_render_clips_contract()
    contract["version"] = "2.0.0"
    assert_schema_rejects(contract)
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# Type mutants
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_invalid_candidate_types():
    """A Python bool is not an integer index."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][0]["index"] = True
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_float_index():
    """Integral floats must not pass for integer fields."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][0]["index"] = 1.0
    assert_rejected(contract)


def test_validate_contract_rejects_numeric_strings():
    """Numeric strings must not substitute for numbers."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][0]["end_ms"] = "10000"
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][0]["semantic_score"] = "0.95"
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_render_clips_contract()
    contract["media"]["duration_ms"] = "30000"
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_null_values():
    """null is not a default: required typed fields reject it."""
    contract = valid_render_clips_contract()
    contract["media"]["duration_ms"] = None
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][0]["semantic_rank"] = None
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_object_list_confusion():
    """Object/list substitutions reject at every level."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"] = {"index": 0}
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][0] = ["index", 0]
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_render_clips_contract()
    contract["media"] = [30000]
    assert_schema_rejects(contract)
    assert_rejected(contract)

    contract = valid_render_clips_contract()
    contract["configuration"] = [1, 2, 3]
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_nonfinite_semantic_score():
    """NaN and Infinity never survive JSON or runtime validation."""
    for value in (math.nan, math.inf, -math.inf):
        contract = valid_render_clips_contract()
        contract["recommendation"]["candidates"][0]["semantic_score"] = value
        assert_rejected(contract)


def test_validate_contract_rejects_bool_semantic_score():
    """A bool is not a numeric semantic_score."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][0]["semantic_score"] = True
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# Chronology, duration, index and rank permutations
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_duration_zero_or_negative():
    """Zero or negative duration must be rejected."""
    for value in (0, -1):
        contract = valid_render_clips_contract()
        contract["media"]["duration_ms"] = value
        assert_schema_rejects(contract)
        assert_rejected(contract)


def test_validate_contract_rejects_duration_above_bound():
    """Duration above 2147483647 ms must be rejected."""
    contract = valid_render_clips_contract()
    contract["media"]["duration_ms"] = 2147483648
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_candidate_index_gaps():
    """Candidate index gaps must be rejected."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][1]["index"] = 5
    assert_rejected(contract)


def test_validate_contract_rejects_duplicate_candidate_index():
    """Duplicate candidate index must be rejected."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][1]["index"] = 0
    assert_rejected(contract)


def test_validate_contract_rejects_non_chronological_candidates():
    """Candidates must be listed chronologically, index == position."""
    contract = valid_render_clips_contract()
    first, second = contract["recommendation"]["candidates"]
    contract["recommendation"]["candidates"] = [second, first]
    assert_rejected(contract)


def test_validate_contract_rejects_negative_semantic_rank():
    """A negative semantic rank must be rejected."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][0]["semantic_rank"] = -1
    assert_rejected(contract)


def test_validate_contract_rejects_semantic_rank_not_1_to_k():
    """Semantic rank not in 1..K must be rejected."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][1]["semantic_rank"] = 5
    assert_rejected(contract)


def test_validate_contract_rejects_duplicate_semantic_ranks():
    """Duplicate semantic ranks must be rejected."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][1]["semantic_rank"] = 1
    assert_rejected(contract)


def test_validate_contract_rejects_candidate_end_beyond_duration():
    """Candidate end_ms beyond media duration must be rejected."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][1]["end_ms"] = 50000
    assert_rejected(contract)


def test_validate_contract_rejects_zero_length_candidate():
    """end_ms must stay strictly greater than start_ms."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][1]["end_ms"] = 10000
    assert_rejected(contract)


def test_validate_contract_rejects_negative_candidate_bounds():
    """Negative candidate boundaries must be rejected."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][0]["start_ms"] = -1
    assert_rejected(contract)


def test_validate_contract_rejects_candidate_index_out_of_bounds_negative():
    """Negative candidate_index must be rejected."""
    contract = valid_render_clips_contract(candidate_index=-1)
    assert_rejected(contract)


def test_validate_contract_rejects_candidate_index_out_of_bounds_above_k():
    """candidate_index >= K must be rejected."""
    contract = valid_render_clips_contract(candidate_index=2)  # K=2
    assert_rejected(contract)


def test_validate_contract_rejects_selected_candidate_null_semantic_score():
    """Selected candidate must have non-null semantic_score."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"][0]["semantic_score"] = None
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# Semantic score value bounds
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_semantic_score_out_of_range():
    """Semantic scores must stay inside the inclusive [0,1] range."""
    for value in (-0.1, 1.1, 1000):
        contract = valid_render_clips_contract()
        contract["recommendation"]["candidates"][0]["semantic_score"] = value
        assert_schema_rejects(contract)
        assert_rejected(contract)


def test_validate_contract_accepts_semantic_score_endpoints():
    """0 and 1 are valid semantic scores."""
    for value in (0, 1):
        contract = valid_render_clips_contract()
        contract["recommendation"]["candidates"][0]["semantic_score"] = value
        is_valid, error = validate_contract(contract)
        assert is_valid is True, f"Endpoint semantic score {value} rejected: {error}"


# ---------------------------------------------------------------------------
# Candidate count
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_empty_candidates():
    """K=0 must be rejected for render_clips."""
    contract = valid_render_clips_contract()
    contract["recommendation"]["candidates"] = []
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_k_above_the_cap():
    """K=1001 exceeds the supported cap of 1000."""
    contract = valid_render_clips_contract()
    contract["media"]["duration_ms"] = 1001 * 1000
    contract["recommendation"]["candidates"] = k_candidates(1001)
    contract["recommendation"]["candidate_index"] = 0
    assert_schema_rejects(contract)
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# Configuration profile bounds
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_empty_configuration_object():
    """Empty configuration object must be rejected."""
    contract = valid_render_clips_contract()
    contract["configuration"] = {}
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_unknown_fields_in_configuration():
    """Unknown fields in configuration must be rejected."""
    contract = valid_render_clips_contract()
    contract["configuration"]["unknown"] = "value"
    assert_schema_rejects(contract)
    assert_rejected(contract)


def test_validate_contract_rejects_invalid_target_width():
    """target_width must be even integer 1..4096."""
    for value in (0, 1, 1081, 4097):
        contract = valid_render_clips_contract()
        contract["configuration"]["target_width"] = value
        assert_rejected(contract)


def test_validate_contract_accepts_target_width_bounds():
    """target_width accepts 2..4096 even."""
    for value in (2, 1080, 4096):
        contract = valid_render_clips_contract()
        contract["configuration"]["target_width"] = value
        is_valid, error = validate_contract(contract)
        assert is_valid is True, f"target_width {value} rejected: {error}"


def test_validate_contract_rejects_invalid_target_height():
    """target_height must be even integer 1..4096."""
    for value in (0, 1, 1921, 4097):
        contract = valid_render_clips_contract()
        contract["configuration"]["target_height"] = value
        assert_rejected(contract)


def test_validate_contract_accepts_target_height_bounds():
    """target_height accepts 2..4096 even."""
    for value in (2, 1920, 4096):
        contract = valid_render_clips_contract()
        contract["configuration"]["target_height"] = value
        is_valid, error = validate_contract(contract)
        assert is_valid is True, f"target_height {value} rejected: {error}"


def test_validate_contract_rejects_invalid_target_fps():
    """target_fps must be integer 1..120."""
    for value in (0, 121):
        contract = valid_render_clips_contract()
        contract["configuration"]["target_fps"] = value
        assert_rejected(contract)


def test_validate_contract_accepts_target_fps_bounds():
    """target_fps accepts 1..120."""
    for value in (1, 30, 60, 120):
        contract = valid_render_clips_contract()
        contract["configuration"]["target_fps"] = value
        is_valid, error = validate_contract(contract)
        assert is_valid is True, f"target_fps {value} rejected: {error}"


def test_validate_contract_rejects_invalid_video_codec():
    """video_codec must be one of the valid enum values."""
    contract = valid_render_clips_contract()
    contract["configuration"]["video_codec"] = "invalid_codec"
    assert_rejected(contract)


def test_validate_contract_accepts_valid_video_codecs():
    """All valid video codecs accepted."""
    for codec in ("libx264", "libx265", "h264_videotoolbox", "hevc_videotoolbox"):
        contract = valid_render_clips_contract()
        contract["configuration"]["video_codec"] = codec
        is_valid, error = validate_contract(contract)
        assert is_valid is True, f"video_codec {codec} rejected: {error}"


def test_validate_contract_rejects_invalid_video_bitrate():
    """video_bitrate_kbps must be integer 500..50000."""
    for value in (499, 50001):
        contract = valid_render_clips_contract()
        contract["configuration"]["video_bitrate_kbps"] = value
        assert_rejected(contract)


def test_validate_contract_accepts_video_bitrate_bounds():
    """video_bitrate_kbps accepts 500..50000."""
    for value in (500, 5000, 50000):
        contract = valid_render_clips_contract()
        contract["configuration"]["video_bitrate_kbps"] = value
        is_valid, error = validate_contract(contract)
        assert is_valid is True, f"video_bitrate_kbps {value} rejected: {error}"


def test_validate_contract_rejects_invalid_audio_codec():
    """audio_codec must be one of the valid enum values."""
    contract = valid_render_clips_contract()
    contract["configuration"]["audio_codec"] = "invalid_codec"
    assert_rejected(contract)


def test_validate_contract_accepts_valid_audio_codecs():
    """All valid audio codecs accepted."""
    for codec in ("aac", "libfdk_aac", "copy"):
        contract = valid_render_clips_contract()
        contract["configuration"]["audio_codec"] = codec
        is_valid, error = validate_contract(contract)
        assert is_valid is True, f"audio_codec {codec} rejected: {error}"


def test_validate_contract_rejects_invalid_audio_bitrate():
    """audio_bitrate_kbps must be integer 32..320."""
    for value in (31, 321):
        contract = valid_render_clips_contract()
        contract["configuration"]["audio_bitrate_kbps"] = value
        assert_rejected(contract)


def test_validate_contract_accepts_audio_bitrate_bounds():
    """audio_bitrate_kbps accepts 32..320."""
    for value in (32, 128, 320):
        contract = valid_render_clips_contract()
        contract["configuration"]["audio_bitrate_kbps"] = value
        is_valid, error = validate_contract(contract)
        assert is_valid is True, f"audio_bitrate_kbps {value} rejected: {error}"


# ---------------------------------------------------------------------------
# Source media validation
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_invalid_source_media_disk():
    """source_media.disk must be non-empty string."""
    contract = valid_render_clips_contract()
    contract["source_media"]["disk"] = ""
    assert_rejected(contract)

    contract = valid_render_clips_contract()
    contract["source_media"]["disk"] = 123
    assert_rejected(contract)


def test_validate_contract_rejects_invalid_source_media_key():
    """source_media.key must be non-empty string."""
    contract = valid_render_clips_contract()
    contract["source_media"]["key"] = ""
    assert_rejected(contract)


def test_validate_contract_rejects_invalid_source_media_dimensions():
    """source_media width/height must be positive integers."""
    for value in (0, -1, "1920"):
        contract = valid_render_clips_contract()
        contract["source_media"]["width"] = value
        assert_rejected(contract)

    for value in (0, -1, "1080"):
        contract = valid_render_clips_contract()
        contract["source_media"]["height"] = value
        assert_rejected(contract)


def test_validate_contract_rejects_invalid_source_media_video_codec():
    """source_media.video_codec must be non-empty string."""
    contract = valid_render_clips_contract()
    contract["source_media"]["video_codec"] = ""
    assert_rejected(contract)

    contract = valid_render_clips_contract()
    contract["source_media"]["video_codec"] = 123
    assert_rejected(contract)


def test_validate_contract_accepts_null_audio_codec():
    """source_media.audio_codec can be null for video-only files."""
    contract = valid_render_clips_contract()
    contract["source_media"]["audio_codec"] = None
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"null audio_codec rejected: {error}"


# ---------------------------------------------------------------------------
# Input size limits
# ---------------------------------------------------------------------------


def test_validate_contract_rejects_input_above_max_bytes():
    """Input size > 8MiB must be rejected."""
    contract = valid_render_clips_contract()
    contract["huge_field"] = "x" * (9 * 1024 * 1024)
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# Schema/runtime agreement
# ---------------------------------------------------------------------------


def test_schema_and_runtime_accept_the_same_strict_request():
    """Both layers accept the strict request; neither accepts alone."""
    contract = valid_render_clips_contract()
    assert render_clips_schema_errors(contract) == []
    is_valid, _reason = validate_contract(contract)
    assert is_valid is True


def test_no_bypass_branch_accepts_what_the_schema_refuses():
    """The combined gate must keep every packaged-schema rejection."""
    mutants = []

    unknown_top = valid_render_clips_contract()
    unknown_top["extra"] = 1
    mutants.append(unknown_top)

    unknown_config = valid_render_clips_contract()
    unknown_config["configuration"]["extra"] = 1
    mutants.append(unknown_config)

    missing_required = valid_render_clips_contract()
    del missing_required["media"]
    mutants.append(missing_required)

    wrong_const = valid_render_clips_contract()
    wrong_const["version"] = "1.0.1"
    mutants.append(wrong_const)

    not_an_object = valid_render_clips_contract()
    not_an_object["recommendation"]["candidates"] = "not a list"
    mutants.append(not_an_object)

    empty_k = valid_render_clips_contract()
    empty_k["recommendation"]["candidates"] = []
    mutants.append(empty_k)

    for mutant in mutants:
        assert render_clips_schema_errors(mutant), "fixture must be refused by the schema"
        is_valid, reason = validate_contract(mutant)
        assert is_valid is False, f"the combined gate accepted a schema rejection: {reason}"