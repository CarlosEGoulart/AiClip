"""Contract validation for media processing contracts."""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any

from jsonschema import Draft7Validator, ValidationError


_SCHEMA_PATH = Path(__file__).resolve().parent.parent / "contracts" / "media_processing_v1.json"


def _load_schema() -> dict[str, Any]:
    """Load the v1.0.0 contract JSON schema."""
    with open(_SCHEMA_PATH) as f:
        return json.load(f)


def validate_contract(contract: dict[str, Any]) -> tuple[bool, str]:
    """Validate a contract against the JSON schema.

    Args:
        contract: The contract dict to validate.

    Returns:
        Tuple of (is_valid, error_message). If valid, error_message is empty.
    """
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
