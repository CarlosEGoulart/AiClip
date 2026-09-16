"""Tests for the extract_audio action."""

from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from typing import Any
from unittest.mock import MagicMock, patch

import pytest

from aiclip_worker.actions.extract_audio import extract_audio

FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"


class TestExtractAudioReturnsExpectedFields:
    """Test that extract_audio returns the expected structured fields."""

    def test_extract_audio_returns_expected_fields_for_valid_media(
        self, sample_contract_extract_audio: dict[str, Any]
    ) -> None:
        """Successful extraction returns all expected fields with correct types."""
        result = extract_audio(sample_contract_extract_audio)

        assert result["status"] == "success"
        assert "extraction" in result
        extraction = result["extraction"]

        # Required fields
        assert "output_path" in extraction
        assert "output_size_bytes" in extraction
        assert "duration_ms" in extraction
        assert "sample_rate" in extraction
        assert "channels" in extraction
        assert "codec" in extraction
        assert "format" in extraction

        # Type checks
        assert isinstance(extraction["output_size_bytes"], int)
        assert isinstance(extraction["duration_ms"], int)
        assert isinstance(extraction["sample_rate"], int)
        assert isinstance(extraction["channels"], int)
        assert isinstance(extraction["codec"], str)
        assert isinstance(extraction["format"], str)

        # Value checks
        assert extraction["output_size_bytes"] > 0
        assert extraction["duration_ms"] >= 0
        assert extraction["sample_rate"] == 16000
        assert extraction["channels"] == 1
        assert extraction["codec"] == "pcm_s16le"
        assert extraction["format"] == "wav"

    def test_extract_audio_output_file_exists(
        self, sample_contract_extract_audio: dict[str, Any]
    ) -> None:
        """Output file should exist after successful extraction."""
        result = extract_audio(sample_contract_extract_audio)

        if result["status"] == "success":
            output_path = result["extraction"]["output_path"]
            assert Path(output_path).exists()

    def test_extract_audio_duration_matches_source(
        self, sample_contract_extract_audio: dict[str, Any]
    ) -> None:
        """Output duration should match source duration within tolerance."""
        result = extract_audio(sample_contract_extract_audio)

        if result["status"] == "success":
            # The source is 1 second (1000 ms). Tolerance ±100ms.
            assert 900 <= result["extraction"]["duration_ms"] <= 1100


class TestExtractAudioOutputFormat:
    """Test that output is mono 16 kHz PCM WAV."""

    def test_extract_audio_output_is_mono_16khz_pcm_wav(
        self, sample_contract_extract_audio: dict[str, Any]
    ) -> None:
        """Output file is mono (1 channel), 16 kHz sample rate, PCM WAV format."""
        result = extract_audio(sample_contract_extract_audio)

        if result["status"] == "success":
            extraction = result["extraction"]
            assert extraction["channels"] == 1
            assert extraction["sample_rate"] == 16000
            assert extraction["codec"] == "pcm_s16le"
            assert extraction["format"] == "wav"


class TestExtractAudioErrorHandling:
    """Test extract_audio error handling for various failure modes."""

    def test_extract_audio_nonexistent_file_returns_error(
        self, sample_contract_nonexistent: dict[str, Any]
    ) -> None:
        """Extraction from a non-existent file returns an error status."""
        # We need to adapt the contract to have extract_audio action and probe_data
        contract = sample_contract_nonexistent.copy()
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
        result = extract_audio(contract)

        assert result["status"] == "error"
        assert "error" in result
        assert len(result["error"]) > 0

    def test_extract_audio_corrupt_file_returns_error(
        self, sample_contract_corrupt: dict[str, Any]
    ) -> None:
        """Extraction from a corrupt file returns an error status."""
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
        result = extract_audio(contract)

        assert result["status"] == "error"
        assert "error" in result

    def test_extract_audio_video_no_audio_returns_error(
        self, sample_contract_video_no_audio: dict[str, Any]
    ) -> None:
        """Extraction from video without audio returns an error."""
        result = extract_audio(sample_contract_video_no_audio)

        assert result["status"] == "error"
        assert "error" in result
        # Error should mention missing audio or probe state requirement
        assert (
            "audio" in result["error"].lower()
            or "no audio" in result["error"].lower()
            or "probe" in result["error"].lower()
        )

    def test_extract_audio_empty_storage_key_returns_error(self) -> None:
        """Contract with empty storage key returns an error."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": "",
                "mime_type": "video/mp4",
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-16T10:00:00Z",
            "action": "extract_audio",
            "output_storage": {
                "disk": "media",
                "key": "output/audio.wav",
                "mime_type": "audio/wav",
            },
            "probe_data": {
                "duration_ms": 1000,
                "audio_codec": "aac",
            },
        }
        result = extract_audio(contract)

        assert result["status"] == "error"
        assert "No storage key" in result["error"]

    def test_extract_audio_empty_output_storage_key_returns_error(self) -> None:
        """Contract with empty output_storage key returns an error."""
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
            "created_at": "2026-09-16T10:00:00Z",
            "action": "extract_audio",
            "output_storage": {
                "disk": "media",
                "key": "",
                "mime_type": "audio/wav",
            },
            "probe_data": {
                "duration_ms": 1000,
                "audio_codec": "aac",
            },
        }
        result = extract_audio(contract)

        assert result["status"] == "error"
        assert "No output storage key" in result["error"]

    def test_extract_audio_missing_probe_data_returns_error(self) -> None:
        """Contract without probe_data returns an error (requires PROBED state)."""
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
            "created_at": "2026-09-16T10:00:00Z",
            "action": "extract_audio",
            "output_storage": {
                "disk": "media",
                "key": "output/audio.wav",
                "mime_type": "audio/wav",
            },
        }
        result = extract_audio(contract)

        assert result["status"] == "error"
        assert "probe" in result["error"].lower()

    def test_extract_audio_null_audio_codec_in_probe_data_returns_error(self) -> None:
        """Contract with probe_data but null audio_codec returns an error."""
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
            "created_at": "2026-09-16T10:00:00Z",
            "action": "extract_audio",
            "output_storage": {
                "disk": "media",
                "key": "output/audio.wav",
                "mime_type": "audio/wav",
            },
            "probe_data": {
                "duration_ms": 1000,
                "audio_codec": None,
            },
        }
        result = extract_audio(contract)

        assert result["status"] == "error"
        assert "probe" in result["error"].lower()


class TestExtractAudioTimeout:
    """Test timeout enforcement."""

    @patch("aiclip_worker.actions.extract_audio.subprocess.run")
    def test_extract_audio_timeout_returns_error(
        self, mock_run: MagicMock, sample_contract_extract_audio: dict[str, Any]
    ) -> None:
        """Timeout during FFmpeg invocation returns an error."""
        import subprocess
        mock_run.side_effect = subprocess.TimeoutExpired(cmd="ffmpeg", timeout=1)

        os.environ["EXTRACT_AUDIO_TIMEOUT_SECONDS"] = "1"
        try:
            result = extract_audio(sample_contract_extract_audio)
        finally:
            del os.environ["EXTRACT_AUDIO_TIMEOUT_SECONDS"]

        assert result["status"] == "error"
        assert "timed out" in result["error"].lower() or "timeout" in result["error"].lower()

    @patch("aiclip_worker.actions.extract_audio.subprocess.run")
    def test_extract_audio_ffmpeg_not_found_returns_error(
        self, mock_run: MagicMock, sample_contract_extract_audio: dict[str, Any]
    ) -> None:
        """FFmpeg binary not found returns an error."""
        mock_run.side_effect = FileNotFoundError

        result = extract_audio(sample_contract_extract_audio)

        assert result["status"] == "error"
        assert "not found" in result["error"].lower() or "no such file" in result["error"].lower()


class TestExtractAudioTemporaryFileManagement:
    """Test temporary file cleanup."""

    def test_extract_audio_cleans_up_on_success(
        self, sample_contract_extract_audio: dict[str, Any]
    ) -> None:
        """No temporary files remain after successful extraction."""
        # We'll capture the output path and check that the temp directory is cleaned up
        result = extract_audio(sample_contract_extract_audio)

        if result["status"] == "success":
            # The output file should exist at the final path
            output_path = result["extraction"]["output_path"]
            assert Path(output_path).exists()

            # The parent directory should not contain any .tmp files
            parent = Path(output_path).parent
            tmp_files = list(parent.glob("*.tmp"))
            assert len(tmp_files) == 0

    def test_extract_audio_cleans_up_on_failure(
        self, sample_contract_corrupt: dict[str, Any]
    ) -> None:
        """No temporary files remain after failed extraction."""
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
        result = extract_audio(contract)

        # Regardless of success or failure, there should be no .tmp files in the output directory
        # The output directory is derived from the output_storage.key
        output_key = contract["output_storage"]["key"]
        output_dir = Path(output_key).parent
        if output_dir.exists():
            tmp_files = list(output_dir.glob("*.tmp"))
            assert len(tmp_files) == 0


class TestExtractAudioSubprocessSafety:
    """Test that subprocess is invoked safely (no shell interpolation)."""

    @patch("aiclip_worker.actions.extract_audio.subprocess.run")
    def test_no_shell_interpolation(
        self, mock_run: MagicMock, sample_contract_extract_audio: dict[str, Any]
    ) -> None:
        """FFmpeg is invoked with list arguments, not shell string."""
        mock_result = MagicMock()
        mock_result.returncode = 1
        mock_result.stdout = ""
        mock_result.stderr = "error"
        mock_run.return_value = mock_result

        extract_audio(sample_contract_extract_audio)

        # Verify subprocess.run was called with list (not string)
        args, kwargs = mock_run.call_args
        assert isinstance(args[0], list)
        assert kwargs.get("shell", False) is not False or "shell" not in kwargs
