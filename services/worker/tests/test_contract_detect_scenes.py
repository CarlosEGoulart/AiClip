"""Tests for contract validation with detect_scenes action."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import pytest

from aiclip_worker.contracts import validate_contract


FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"


@pytest.fixture
def valid_contract_detect_scenes() -> dict[str, Any]:
    """Valid v1.0.0 contract with detect_scenes action."""
    return {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": str(FIXTURES_DIR / "valid_sample.mp4"),
            "mime_type": "video/mp4",
        },
        "media": {
            "duration_ms": 6000
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "detect_scenes",
    }


@pytest.fixture
def invalid_action_contract() -> dict[str, Any]:
    """Contract with unknown action."""
    return {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": str(FIXTURES_DIR / "valid_sample.mp4"),
            "mime_type": "video/mp4",
        },
        "media": {
            "duration_ms": 6000
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "unknown_action",
    }


class TestContractDetectScenesValidation:
    """Test contract validation with detect_scenes action."""

    def test_contract_detect_scenes_action_valid(
        self, valid_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Contract with detect_scenes action validates."""
        is_valid, error_msg = validate_contract(valid_contract_detect_scenes)
        assert is_valid is True
        assert error_msg == ""

    def test_contract_detect_scenes_action_invalid_action(
        self, invalid_action_contract: dict[str, Any]
    ) -> None:
        """Contract with unknown action fails validation."""
        is_valid, error_msg = validate_contract(invalid_action_contract)
        assert is_valid is False
        assert "action" in error_msg.lower() or "enum" in error_msg.lower()

    def test_contract_detect_scenes_action_missing_fields(self) -> None:
        """Contract with detect_scenes action and missing required fields fails validation."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(FIXTURES_DIR / "valid_sample.mp4"),
                "mime_type": "video/mp4",
            },
            "media": {
                "duration_ms": 6000
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            # action is missing — should default to "probe" per schema, but still valid
        }
        is_valid, error_msg = validate_contract(contract)
        # This should still be valid as action defaults to "probe"
        assert is_valid is True

    def test_contract_valid_with_action_probe(
        self, valid_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Existing probe contract still validates."""
        contract = valid_contract_detect_scenes.copy()
        contract["action"] = "probe"
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is True

    def test_contract_valid_with_action_extract_audio(
        self, valid_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Existing extract_audio contract still validates."""
        contract = valid_contract_detect_scenes.copy()
        contract["action"] = "extract_audio"
        contract["output_storage"] = {
            "disk": "media",
            "key": "output/audio.wav",
            "mime_type": "audio/wav",
        }
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is True

    def test_contract_valid_with_action_transcribe(
        self, valid_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Existing transcribe contract still validates."""
        contract = valid_contract_detect_scenes.copy()
        contract["action"] = "transcribe"
        contract["derived_asset_id"] = 1
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is True

    def test_contract_detect_scenes_no_extra_fields_required(
        self, valid_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Contract with detect_scenes action requires no extra fields."""
        is_valid, error_msg = validate_contract(valid_contract_detect_scenes)
        assert is_valid is True
        assert error_msg == ""

    # RED tests for Blocker 1: JSON Schema conditional requiring media.duration_ms for detect_scenes
    def test_contract_detect_scenes_missing_media_invalid(self) -> None:
        """detect_scenes contract without media object is INVALID."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(FIXTURES_DIR / "valid_sample.mp4"),
                "mime_type": "video/mp4",
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "detect_scenes",
        }
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is False
        assert "media" in error_msg.lower() or "duration" in error_msg.lower()

    def test_contract_detect_scenes_media_empty_object_invalid(self) -> None:
        """detect_scenes contract with empty media object is INVALID."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(FIXTURES_DIR / "valid_sample.mp4"),
                "mime_type": "video/mp4",
            },
            "media": {},
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "detect_scenes",
        }
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is False
        assert "duration" in error_msg.lower()

    def test_contract_detect_scenes_missing_duration_ms_invalid(self) -> None:
        """detect_scenes contract with media but missing duration_ms is INVALID."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(FIXTURES_DIR / "valid_sample.mp4"),
                "mime_type": "video/mp4",
            },
            "media": {
                "some_other_field": "value"
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "detect_scenes",
        }
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is False
        assert "duration" in error_msg.lower()

    def test_contract_detect_scenes_duration_zero_invalid(self) -> None:
        """detect_scenes contract with duration_ms = 0 is INVALID."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(FIXTURES_DIR / "valid_sample.mp4"),
                "mime_type": "video/mp4",
            },
            "media": {
                "duration_ms": 0
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "detect_scenes",
        }
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is False
        assert "duration" in error_msg.lower() or "minimum" in error_msg.lower()

    def test_contract_detect_scenes_duration_negative_invalid(self) -> None:
        """detect_scenes contract with duration_ms = -1 is INVALID."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(FIXTURES_DIR / "valid_sample.mp4"),
                "mime_type": "video/mp4",
            },
            "media": {
                "duration_ms": -1
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "detect_scenes",
        }
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is False
        assert "duration" in error_msg.lower() or "minimum" in error_msg.lower()

    def test_contract_detect_scenes_duration_non_integer_invalid(self) -> None:
        """detect_scenes contract with non-integer duration_ms is INVALID."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(FIXTURES_DIR / "valid_sample.mp4"),
                "mime_type": "video/mp4",
            },
            "media": {
                "duration_ms": 6000.5
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "detect_scenes",
        }
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is False
        assert "duration" in error_msg.lower() or "integer" in error_msg.lower() or "type" in error_msg.lower()

    def test_contract_probe_without_media_valid(self) -> None:
        """probe contract without media object is VALID."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(FIXTURES_DIR / "valid_sample.mp4"),
                "mime_type": "video/mp4",
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "probe",
        }
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is True

    def test_contract_extract_audio_without_media_valid(self) -> None:
        """extract_audio contract without media object is VALID."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(FIXTURES_DIR / "valid_sample.mp4"),
                "mime_type": "video/mp4",
            },
            "output_storage": {
                "disk": "media",
                "key": "output/audio.wav",
                "mime_type": "audio/wav",
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "extract_audio",
        }
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is True

    def test_contract_transcribe_without_media_valid(self) -> None:
        """transcribe contract without media object is VALID."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(FIXTURES_DIR / "valid_sample.mp4"),
                "mime_type": "video/mp4",
            },
            "derived_asset_id": 1,
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "transcribe",
        }
        is_valid, error_msg = validate_contract(contract)
        assert is_valid is True
