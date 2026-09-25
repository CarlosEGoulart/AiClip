"""Contract validation for rank_clips action."""

from __future__ import annotations

import pytest

from aiclip_worker.contracts import validate_contract


def valid_rank_clips_contract():
    """Valid rank_clips contract with all required fields."""
    return {
        "version": "1.0.0",
        "action": "rank_clips",
        "media": {"duration_ms": 40000},
        "candidates": [
            {"index": 0, "start_ms": 0, "end_ms": 10000, "rank": 1, "transcript_text": "engaging content"},
            {"index": 1, "start_ms": 10000, "end_ms": 20000, "rank": 2, "transcript_text": ""},
        ],
        "configuration": {
            "prototype_query": "Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline."
        },
    }


def test_validate_contract_accepts_valid_rank_clips():
    """Valid rank_clips contract must be accepted (currently fails - RED)."""
    contract = valid_rank_clips_contract()
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"Valid rank_clips rejected: {error}"
    assert error == ""


def test_validate_contract_rejects_unknown_fields_in_rank_clips():
    """Unknown fields in rank_clips must be rejected (currently may pass - RED)."""
    contract = valid_rank_clips_contract()
    contract["unknown_field"] = "PRIVATE_SENTINEL"
    is_valid, error = validate_contract(contract)
    assert is_valid is False
    assert "unknown_field" in error or "additional" in error.lower()


def test_validate_contract_rejects_missing_candidates():
    """Missing candidates must be rejected."""
    contract = valid_rank_clips_contract()
    del contract["candidates"]
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_missing_configuration():
    """Missing configuration must be rejected."""
    contract = valid_rank_clips_contract()
    del contract["configuration"]
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_missing_prototype_query():
    """Missing prototype_query in configuration must be rejected."""
    contract = valid_rank_clips_contract()
    del contract["configuration"]["prototype_query"]
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_wrong_action():
    """Wrong action for rank-clips must be rejected."""
    contract = valid_rank_clips_contract()
    contract["action"] = "analyze_clips"
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_wrong_version():
    """Wrong version must be rejected."""
    contract = valid_rank_clips_contract()
    contract["version"] = "2.0.0"
    is_valid, error = validate_contract(contract)
    assert is_valid is False


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


def test_validate_contract_rejects_invalid_candidate_types():
    """Invalid candidate field types must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["index"] = True  # boolean, not int
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_negative_rank():
    """Negative rank must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["rank"] = -1
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_float_index():
    """Float index must be rejected (bool is int subclass in Python)."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["index"] = 1.0
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_non_string_transcript_text():
    """Non-string transcript_text must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["transcript_text"] = 123
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_accepts_empty_transcript_text():
    """Empty string transcript_text must be accepted."""
    contract = valid_rank_clips_contract()
    contract["candidates"][0]["transcript_text"] = ""
    is_valid, error = validate_contract(contract)
    assert is_valid is True, f"Empty transcript_text rejected: {error}"


def test_validate_contract_rejects_missing_transcript_text():
    """Missing transcript_text must be rejected (required field)."""
    contract = valid_rank_clips_contract()
    del contract["candidates"][0]["transcript_text"]
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_duration_zero_or_negative():
    """Zero or negative duration must be rejected."""
    contract = valid_rank_clips_contract()
    contract["media"]["duration_ms"] = 0
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_candidate_index_gaps():
    """Candidate index gaps must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["index"] = 5  # gap
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_duplicate_candidate_index():
    """Duplicate candidate index must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["index"] = 0
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_candidate_end_beyond_duration():
    """Candidate end_ms beyond media duration must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["end_ms"] = 50000
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_rank_not_1_to_k():
    """Rank not in 1..K must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["rank"] = 5  # K=2
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_duplicate_ranks():
    """Duplicate ranks must be rejected."""
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["rank"] = 1
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_empty_configuration_object():
    """Empty configuration object must be rejected."""
    contract = valid_rank_clips_contract()
    contract["configuration"] = {}
    is_valid, error = validate_contract(contract)
    assert is_valid is False


def test_validate_contract_rejects_unknown_fields_in_configuration():
    """Unknown fields in configuration must be rejected."""
    contract = valid_rank_clips_contract()
    contract["configuration"]["unknown"] = "value"
    is_valid, error = validate_contract(contract)
    assert is_valid is False