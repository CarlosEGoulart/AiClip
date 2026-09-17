"""Tests for the detect-scenes CLI subcommand."""

from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from typing import Any
from unittest.mock import MagicMock, patch

import pytest

from aiclip_worker.cli import main


FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"


@pytest.fixture
def valid_video_path(tmp_path: Path) -> str:
    """Create a minimal valid video file for testing."""
    video_path = tmp_path / "test_video.mp4"
    video_path.write_bytes(
        b'\x00\x00\x00\x1c\x66\x74\x79\x70\x69\x73\x6f\x6d'
        b'\x00\x00\x02\x00\x69\x73\x6f\x6d\x69\x73\x6f\x32'
        b'\x6d\x70\x34\x31'
    )
    return str(video_path)


@pytest.fixture
def valid_contract_json(valid_video_path: str) -> str:
    """Valid detect_scenes contract as JSON string."""
    contract = {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": valid_video_path,
            "mime_type": "video/mp4",
        },
        "media": {
            "duration_ms": 6000
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "detect_scenes",
    }
    return json.dumps(contract)


@pytest.fixture
def probe_action_contract_json(valid_video_path: str) -> str:
    """Contract with probe action (wrong action for detect-scenes subcommand)."""
    contract = {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": valid_video_path,
            "mime_type": "video/mp4",
        },
        "media": {
            "duration_ms": 6000
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "probe",
    }
    return json.dumps(contract)


class TestCLIDetectScenesValidContract:
    """Test CLI with valid detect_scenes contract."""

    def test_cli_detect_scenes_valid_contract(
        self, valid_contract_json: str, capsys: Any
    ) -> None:
        """CLI with valid detect_scenes contract via --contract-json."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            exit_code = main(["detect-scenes", "--contract-json", valid_contract_json])

        assert exit_code == 0

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "success"
        assert "scene_detection" in output

    def test_cli_detect_scenes_valid_contract_via_file(
        self, valid_video_path: str, capsys: Any, tmp_path: Path
    ) -> None:
        """CLI with valid detect_scenes contract via --contract-file."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": valid_video_path,
                "mime_type": "video/mp4",
            },
            "media": {
                "duration_ms": 6000
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "detect_scenes",
        }

        contract_file = tmp_path / "contract.json"
        contract_file.write_text(json.dumps(contract))

        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            exit_code = main(["detect-scenes", "--contract-file", str(contract_file)])

        assert exit_code == 0

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "success"


class TestCLIDetectScenesMissingAction:
    """Test CLI with missing or wrong action."""

    def test_cli_detect_scenes_probe_action_mismatch(
        self, probe_action_contract_json: str, capsys: Any
    ) -> None:
        """CLI with probe action on detect-scenes subcommand returns error."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            exit_code = main(["detect-scenes", "--contract-json", probe_action_contract_json])

        assert exit_code == 2

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "error"
        assert "Expected action 'detect_scenes'" in output["error"]

    def test_cli_detect_scenes_no_contract_provided(self, capsys: Any) -> None:
        """CLI with no contract provided returns error."""
        with patch("aiclip_worker.cli.sys.stdin") as mock_stdin:
            mock_stdin.isatty.return_value = True
            exit_code = main(["detect-scenes"])

        assert exit_code == 2

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "error"
        assert "Invalid or missing contract JSON" in output["error"]

    def test_cli_detect_scenes_invalid_json(self, capsys: Any) -> None:
        """CLI with invalid JSON returns error."""
        exit_code = main(["detect-scenes", "--contract-json", "not-valid-json"])

        assert exit_code == 2

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "error"


class TestCLIDetectScenesIncompatibleVersion:
    """Test CLI with incompatible contract version."""

    def test_cli_detect_scenes_incompatible_version(self, capsys: Any) -> None:
        """CLI with unknown major version returns error."""
        contract = {
            "version": "2.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": "/tmp/video.mp4",
                "mime_type": "video/mp4",
            },
            "media": {
                "duration_ms": 6000
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "detect_scenes",
        }

        exit_code = main(["detect-scenes", "--contract-json", json.dumps(contract)])

        assert exit_code == 2

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "error"


class TestCLIDetectScenesNoStorage:
    """Test CLI with missing storage."""

    def test_cli_detect_scenes_missing_storage(self, capsys: Any) -> None:
        """CLI with missing storage returns error."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "media": {
                "duration_ms": 6000
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "detect_scenes",
        }

        exit_code = main(["detect-scenes", "--contract-json", json.dumps(contract)])

        assert exit_code == 2

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "error"


class TestCLIDetectScenesOutputFormat:
    """Test CLI output format."""

    def test_cli_detect_scenes_success_output_is_valid_json(
        self, valid_contract_json: str, capsys: Any
    ) -> None:
        """Success output is valid JSON with expected fields."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            exit_code = main(["detect-scenes", "--contract-json", valid_contract_json])

        assert exit_code == 0

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "success"
        assert "scene_detection" in output
        scene_detection = output["scene_detection"]
        assert "detector" in scene_detection
        assert "detector_version" in scene_detection
        assert "parameters" in scene_detection
        assert "scenes" in scene_detection

    def test_cli_detect_scenes_error_output_is_valid_json(
        self, capsys: Any
    ) -> None:
        """Error output is valid JSON with error field."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": "",
                "mime_type": "video/mp4",
            },
            "media": {
                "duration_ms": 6000
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "detect_scenes",
        }

        exit_code = main(["detect-scenes", "--contract-json", json.dumps(contract)])

        assert exit_code == 2

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "error"


class TestCLIDetectScenesExitCodes:
    """Test CLI exit codes."""

    def test_cli_detect_scenes_exit_code_success(
        self, valid_contract_json: str, capsys: Any
    ) -> None:
        """Exit code 0 on success."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            exit_code = main(["detect-scenes", "--contract-json", valid_contract_json])

        assert exit_code == 0

    def test_cli_detect_scenes_exit_code_invalid_input(self, capsys: Any) -> None:
        """Exit code 2 on invalid input."""
        exit_code = main(["detect-scenes", "--contract-json", "not-valid-json"])

        assert exit_code == 2

    def test_cli_detect_scenes_exit_code_processing_error(self, capsys: Any) -> None:
        """Exit code 1 on processing error (non-existent file)."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": "/nonexistent/path/video.mp4",
                "mime_type": "video/mp4",
            },
            "media": {
                "duration_ms": 6000
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "detect_scenes",
        }

        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            exit_code = main(["detect-scenes", "--contract-json", json.dumps(contract)])

        assert exit_code == 1
