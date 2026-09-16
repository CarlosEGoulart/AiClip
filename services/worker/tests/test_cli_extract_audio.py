"""Tests for the worker CLI extract-audio subcommand."""

from __future__ import annotations

import json
import tempfile
from pathlib import Path
from typing import Any
from unittest.mock import patch

import pytest

from aiclip_worker.cli import main

FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"


class TestCLIExtractAudioValidContract:
    """Test CLI with valid extract_audio contracts."""

    def test_cli_extract_audio_valid_contract_via_file(
        self, sample_contract_extract_audio: dict[str, Any]
    ) -> None:
        """CLI with --contract-file returns exit code 0 on success."""
        with tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False) as f:
            json.dump(sample_contract_extract_audio, f)
            f.flush()
            tmp_path = f.name

        try:
            exit_code = main(["extract-audio", "--contract-file", tmp_path])
            assert exit_code == 0
        finally:
            Path(tmp_path).unlink(missing_ok=True)

    def test_cli_extract_audio_valid_contract_via_stdin(
        self, sample_contract_extract_audio: dict[str, Any], capsys: pytest.CaptureFixture
    ) -> None:
        """CLI with --contract-json returns valid JSON output."""
        contract_json = json.dumps(sample_contract_extract_audio)
        exit_code = main(["extract-audio", "--contract-json", contract_json])
        assert exit_code == 0

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "success"


class TestCLIExtractAudioInvalidContract:
    """Test CLI with invalid extract_audio contracts."""

    def test_cli_extract_audio_invalid_contract_missing_output_storage(self) -> None:
        """CLI with missing output_storage returns exit code 2."""
        invalid_contract = json.dumps({
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(FIXTURES_DIR / "valid_sample.mp4"),
                "mime_type": "video/mp4",
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-16T10:00:00Z",
            "action": "extract_audio",
        })
        exit_code = main(["extract-audio", "--contract-json", invalid_contract])
        assert exit_code == 2

    def test_cli_extract_audio_probe_action_mismatch(self, sample_contract: dict[str, Any]) -> None:
        """CLI with probe action on extract-audio subcommand returns exit code 2."""
        contract = sample_contract.copy()
        contract["action"] = "probe"
        contract_json = json.dumps(contract)
        exit_code = main(["extract-audio", "--contract-json", contract_json])
        assert exit_code == 2

    def test_cli_extract_audio_no_contract_provided(self) -> None:
        """CLI with no contract provided returns exit code 2."""
        with patch("sys.stdin.isatty", return_value=True):
            exit_code = main(["extract-audio"])
        assert exit_code == 2


class TestCLIExtractAudioExitCodes:
    """Test CLI exit code behavior for extract-audio."""

    def test_cli_extract_audio_exit_code_success(
        self, sample_contract_extract_audio: dict[str, Any]
    ) -> None:
        """Exit code 0 on successful extraction."""
        contract_json = json.dumps(sample_contract_extract_audio)
        exit_code = main(["extract-audio", "--contract-json", contract_json])
        assert exit_code == 0

    def test_cli_extract_audio_exit_code_processing_error(
        self, sample_contract_corrupt: dict[str, Any]
    ) -> None:
        """Exit code 1 on processing error (corrupt file)."""
        contract = sample_contract_corrupt.copy()
        contract["action"] = "extract_audio"
        contract["output_storage"] = {
            "disk": "media",
            "key": "output/audio.wav",
            "mime_type": "audio/wav",
        }
        contract["probe_data"] = {
            "duration_ms": 1000,
            "audio_codec": "aac",
        }
        contract_json = json.dumps(contract)
        exit_code = main(["extract-audio", "--contract-json", contract_json])
        assert exit_code == 1

    def test_cli_extract_audio_exit_code_invalid_input(self) -> None:
        """Exit code 2 on invalid input."""
        exit_code = main(["extract-audio", "--contract-json", "not valid json"])
        assert exit_code == 2

    def test_cli_extract_audio_exit_code_no_audio_stream(
        self, sample_contract_video_no_audio: dict[str, Any]
    ) -> None:
        """Exit code 1 when video has no audio stream."""
        contract_json = json.dumps(sample_contract_video_no_audio)
        exit_code = main(["extract-audio", "--contract-json", contract_json])
        assert exit_code == 1


class TestCLIExtractAudioOutput:
    """Test CLI output format for extract-audio."""

    def test_cli_extract_audio_success_output_is_valid_json(
        self, sample_contract_extract_audio: dict[str, Any], capsys: pytest.CaptureFixture
    ) -> None:
        """Successful output is valid JSON with expected structure."""
        contract_json = json.dumps(sample_contract_extract_audio)
        main(["extract-audio", "--contract-json", contract_json])

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert "status" in output
        assert output["status"] == "success"
        assert "extraction" in output

    def test_cli_extract_audio_error_output_is_valid_json(
        self, sample_contract_corrupt: dict[str, Any], capsys: pytest.CaptureFixture
    ) -> None:
        """Error output is valid JSON with expected structure."""
        contract = sample_contract_corrupt.copy()
        contract["action"] = "extract_audio"
        contract["output_storage"] = {
            "disk": "media",
            "key": "output/audio.wav",
            "mime_type": "audio/wav",
        }
        contract["probe_data"] = {
            "duration_ms": 1000,
            "audio_codec": "aac",
        }
        contract_json = json.dumps(contract)
        main(["extract-audio", "--contract-json", contract_json])

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "error"
        assert "error" in output
