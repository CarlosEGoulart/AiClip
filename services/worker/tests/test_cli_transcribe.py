"""Tests for the CLI transcribe subcommand."""

from __future__ import annotations

import json
import tempfile
from pathlib import Path
from typing import Any
from unittest.mock import patch

import pytest

from aiclip_worker.cli import main


FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"


@pytest.fixture
def normalized_audio_cli_path(tmp_path: Path) -> str:
    """Create a minimal normalized WAV file for CLI testing."""
    wav_path = tmp_path / "normalized_audio.wav"
    with open(wav_path, "wb") as f:
        # Write a minimal valid WAV header
        f.write(b"RIFF")
        f.write((36).to_bytes(4, "little"))
        f.write(b"WAVE")
        f.write(b"fmt ")
        f.write((16).to_bytes(4, "little"))
        f.write((1).to_bytes(2, "little"))
        f.write((1).to_bytes(2, "little"))
        f.write((16000).to_bytes(4, "little"))
        f.write((32000).to_bytes(4, "little"))
        f.write((2).to_bytes(2, "little"))
        f.write((16).to_bytes(2, "little"))
        f.write(b"data")
        f.write((0).to_bytes(4, "little"))
    return str(wav_path)


@pytest.fixture
def valid_transcribe_contract(normalized_audio_cli_path: str) -> dict[str, Any]:
    """Valid transcribe contract."""
    return {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": normalized_audio_cli_path,
            "mime_type": "audio/wav",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "transcribe",
        "derived_asset_id": 1,
    }


@pytest.fixture
def contract_missing_derived_asset_id(normalized_audio_cli_path: str) -> dict[str, Any]:
    """Transcribe contract missing derived_asset_id."""
    return {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": normalized_audio_cli_path,
            "mime_type": "audio/wav",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "transcribe",
    }


@pytest.fixture
def contract_wrong_action(normalized_audio_cli_path: str) -> dict[str, Any]:
    """Contract with action 'probe' instead of 'transcribe'."""
    return {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": normalized_audio_cli_path,
            "mime_type": "audio/wav",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "probe",
    }


class TestCLITranscribeValidContract:
    """Test CLI with valid transcribe contracts."""

    def test_cli_transcribe_valid_contract_via_stdin(
        self, valid_transcribe_contract: dict[str, Any], capsys: pytest.CaptureFixture
    ) -> None:
        """CLI with --contract-json returns exit code 0 on success."""
        with patch.dict("os.environ", {"TRANSCRIPTION_ENGINE": "deterministic"}):
            contract_json = json.dumps(valid_transcribe_contract)
            exit_code = main(["transcribe", "--contract-json", contract_json])

        assert exit_code == 0

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "success"

    def test_cli_transcribe_valid_contract_via_file(
        self, valid_transcribe_contract: dict[str, Any]
    ) -> None:
        """CLI with --contract-file returns exit code 0 on success."""
        with tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False) as f:
            json.dump(valid_transcribe_contract, f)
            f.flush()
            tmp_path = f.name

        try:
            with patch.dict("os.environ", {"TRANSCRIPTION_ENGINE": "deterministic"}):
                exit_code = main(["transcribe", "--contract-file", tmp_path])
            assert exit_code == 0
        finally:
            Path(tmp_path).unlink(missing_ok=True)


class TestCLITranscribeInvalidContract:
    """Test CLI with invalid contracts."""

    def test_cli_transcribe_invalid_contract_missing_derived_asset_id(
        self, contract_missing_derived_asset_id: dict[str, Any]
    ) -> None:
        """CLI with missing derived_asset_id returns exit code 2."""
        contract_json = json.dumps(contract_missing_derived_asset_id)
        exit_code = main(["transcribe", "--contract-json", contract_json])
        assert exit_code == 2

    def test_cli_transcribe_probe_action_mismatch(
        self, contract_wrong_action: dict[str, Any]
    ) -> None:
        """CLI with wrong action returns exit code 2."""
        contract_json = json.dumps(contract_wrong_action)
        exit_code = main(["transcribe", "--contract-json", contract_json])
        assert exit_code == 2

    def test_cli_transcribe_no_contract_provided(self) -> None:
        """CLI with no contract returns exit code 2."""
        with patch("sys.stdin.isatty", return_value=True):
            exit_code = main(["transcribe"])
        assert exit_code == 2

    def test_cli_transcribe_invalid_json(self) -> None:
        """CLI with invalid JSON returns exit code 2."""
        exit_code = main(["transcribe", "--contract-json", "not valid json"])
        assert exit_code == 2


class TestCLITranscribeExitCodes:
    """Test CLI exit code behavior for transcribe."""

    def test_cli_transcribe_exit_code_success(
        self, valid_transcribe_contract: dict[str, Any]
    ) -> None:
        """Exit code 0 on successful transcription."""
        with patch.dict("os.environ", {"TRANSCRIPTION_ENGINE": "deterministic"}):
            contract_json = json.dumps(valid_transcribe_contract)
            exit_code = main(["transcribe", "--contract-json", contract_json])
        assert exit_code == 0

    def test_cli_transcribe_exit_code_invalid_input(self) -> None:
        """Exit code 2 on invalid input."""
        exit_code = main(["transcribe", "--contract-json", "not valid json"])
        assert exit_code == 2

    def test_cli_transcribe_exit_code_processing_error(
        self, contract_missing_derived_asset_id: dict[str, Any]
    ) -> None:
        """Exit code 2 on processing error (invalid contract)."""
        contract_json = json.dumps(contract_missing_derived_asset_id)
        exit_code = main(["transcribe", "--contract-json", contract_json])
        assert exit_code == 2


class TestCLITranscribeOutput:
    """Test CLI output format for transcribe."""

    def test_cli_transcribe_success_output_is_valid_json(
        self, valid_transcribe_contract: dict[str, Any], capsys: pytest.CaptureFixture
    ) -> None:
        """Successful output is valid JSON with expected structure."""
        with patch.dict("os.environ", {"TRANSCRIPTION_ENGINE": "deterministic"}):
            contract_json = json.dumps(valid_transcribe_contract)
            main(["transcribe", "--contract-json", contract_json])

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "success"
        assert "transcription" in output
        transcription = output["transcription"]
        assert "language" in transcription
        assert "full_text" in transcription
        assert "segments" in transcription
        assert "engine" in transcription
        assert "model" in transcription

    def test_cli_transcribe_error_output_is_valid_json(
        self, capsys: pytest.CaptureFixture
    ) -> None:
        """Error output is valid JSON with expected structure."""
        exit_code = main(["transcribe", "--contract-json", "not valid json"])
        assert exit_code == 2

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "error"
        assert "error" in output
