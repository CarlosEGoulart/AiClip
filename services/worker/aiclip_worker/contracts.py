"""Contract validation for media processing contracts."""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any

from jsonschema import Draft7Validator, ValidationError
from jsonschema import Draft7Validator, ValidationError


_SCHEMA_PATH = Path(__file__).resolve().parent.parent / "contracts" / "media_processing_v1.json"


def _load_schema() -> dict[str, Any]:
    """Load the v1.0.0 contract JSON schema."""
    with open(_SCHEMA_PATH) as f:
        return json.load(f)


def _validate_rank_clips(contract: dict[str, Any]) -> tuple[bool, str]:
    """Validate rank_clips contract with strict checks."""
    # Version must be exactly 1.0.0
    if contract.get("version") != "1.0.0":
        return False, f"Unsupported contract version: {contract.get('version')!r}. Expected '1.0.0'"

    # Check required fields
    if contract.get("action") != "rank_clips":
        return False, "Invalid action for rank_clips"

    # Media with duration_ms
    media = contract.get("media")
    if not isinstance(media, dict) or "duration_ms" not in media:
        return False, "Missing or invalid media.duration_ms"
    duration_ms = media["duration_ms"]
    if not isinstance(duration_ms, int) or duration_ms <= 0:
        return False, "media.duration_ms must be a positive integer"

    # Candidates array
    candidates = contract.get("candidates")
    if not isinstance(candidates, list):
        return False, "candidates must be a list"

    k = len(candidates)
    seen_indices = set()
    seen_ranks = set()

    for i, candidate in enumerate(candidates):
        if not isinstance(candidate, dict):
            return False, f"candidate {i} must be an object"

        # Required fields with strict types (no bool for int fields)
        for field in ("index", "start_ms", "end_ms", "rank", "transcript_text"):
            if field not in candidate:
                return False, f"candidate {i} missing required field: {field}"
            value = candidate[field]
            if field == "transcript_text":
                if not isinstance(value, str):
                    return False, f"candidate {i}.transcript_text must be a string"
            else:
                if isinstance(value, bool) or not isinstance(value, int):
                    return False, f"candidate {i}.{field} must be an integer"

        index = candidate["index"]
        start_ms = candidate["start_ms"]
        end_ms = candidate["end_ms"]
        rank = candidate["rank"]

        # Index validation
        if index < 0 or index >= k:
            return False, f"candidate {i}.index out of range: {index}"
        if index in seen_indices:
            return False, f"duplicate candidate index: {index}"
        if index != i:
            return False, f"candidate index must be sequential 0..K-1, got {index} at position {i}"
        seen_indices.add(index)

        # Timing validation
        if start_ms < 0 or start_ms > duration_ms:
            return False, f"candidate {i}.start_ms out of range: {start_ms}"
        if end_ms <= start_ms or end_ms > duration_ms:
            return False, f"candidate {i}.end_ms invalid: {end_ms}"

        # Rank validation
        if rank < 1 or rank > k:
            return False, f"candidate {i}.rank out of range 1..{k}: {rank}"
        if rank in seen_ranks:
            return False, f"duplicate candidate rank: {rank}"
        seen_ranks.add(rank)

    # Configuration with prototype_query
    configuration = contract.get("configuration")
    if not isinstance(configuration, dict):
        return False, "configuration must be an object"

    prototype_query = configuration.get("prototype_query")
    if not isinstance(prototype_query, str) or not prototype_query:
        return False, "configuration.prototype_query must be a non-empty string"

    # Reject unknown fields in configuration
    allowed_config_fields = {"prototype_query"}
    unknown_config = set(configuration.keys()) - allowed_config_fields
    if unknown_config:
        return False, f"configuration contains unknown fields: {sorted(unknown_config)}"

    # Reject unknown top-level fields
    allowed_top_level = {"version", "action", "media", "candidates", "configuration"}
    unknown_top = set(contract.keys()) - allowed_top_level
    if unknown_top:
        return False, f"unknown fields: {sorted(unknown_top)}"

    return True, ""


def validate_contract(contract: dict[str, Any]) -> tuple[bool, str]:
    """Validate a contract against the JSON schema.

    Args:
        contract: The contract dict to validate.

    Returns:
        Tuple of (is_valid, error_message). If valid, error_message is empty.
    """
    if isinstance(contract, dict) and contract.get("action") == "analyze_clips":
        from aiclip_worker.clip_analysis import ClipAnalysisInput, ClipValidationError

        try:
            ClipAnalysisInput.from_contract(contract)
        except (ClipValidationError, ValueError, TypeError, RecursionError):
            return False, "Invalid clip analysis contract"
        return True, ""

    if isinstance(contract, dict) and contract.get("action") == "rank_clips":
        return _validate_rank_clips(contract)

    # Check that version is present and is 1.x
    version = contract.get("version", "")
    if not version:
        return False, "Missing required field: version"

    major = version.split(".")[0]
    if major != "1":
        return False, f"Unsupported contract version major: {major}. Only 1.x is supported."

    # Validate against JSON schema
    try:
        schema = _load_schema()
        validator = Draft7Validator(schema)
        errors = list(validator.iter_errors(contract))
        if errors:
            error_messages = [e.message for e in errors]
            return False, "; ".join(error_messages)
    except FileNotFoundError:
        return False, "Contract schema file not found"

    return True, ""