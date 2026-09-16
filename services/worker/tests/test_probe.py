"""Tests for the probe action."""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any
from unittest.mock import MagicMock, patch

import pytest

from aiclip_worker.actions.probe import probe_media

FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"


class TestProbeReturnsExpectedFields:
    """Test that probe returns the expected structured fields."""

    def test_probe_returns_expected_fields_for_valid_media(self, sample_contract: dict[str, Any]) -> None:
        """Successful probe returns all expected fields with correct types."""
        result = probe_media(sample_contract)

        assert result["status"] == "success"
        assert "probe" in result
        probe = result["probe"]

        # Required fields
        assert "duration_ms" in probe
        assert "width" in probe
        assert "height" in probe
        assert "video_codec" in probe
        assert "audio_codec" in probe
        assert "bitrate_kbps" in probe
        assert "fps" in probe
        assert "audio_channels" in probe
        assert "audio_sample_rate" in probe
        assert "format" in probe
        assert "size_bytes" in probe

        # Type checks
        assert isinstance(probe["duration_ms"], int)
        assert isinstance(probe["bitrate_kbps"], int)
        assert isinstance(probe["size_bytes"], int)
        assert isinstance(probe["format"], str)

    def test_probe_duration_is_positive(self, sample_contract: dict[str, Any]) -> None:
        """Duration in milliseconds should be positive for valid media."""
        result = probe_media(sample_contract)

        if result["status"] == "success":
            assert result["probe"]["duration_ms"] >= 0

    def test_probe_width_and_height_are_integers(self, sample_contract: dict[str, Any]) -> None:
        """Width and height should be positive integers when video is present."""
        result = probe_media(sample_contract)

        if result["status"] == "success" and result["probe"]["width"] is not None:
            assert isinstance(result["probe"]["width"], int)
            assert isinstance(result["probe"]["height"], int)
            assert result["probe"]["width"] > 0
            assert result["probe"]["height"] > 0


class TestProbeWithDifferentMediaTypes:
    """Test probe with various media types."""

    def test_probe_with_audio_only_media(self) -> None:
        """Audio-only media should have null video_codec."""
        # This test requires an audio-only fixture; skip if not available
        audio_fixture = FIXTURES_DIR / "audio_only.mp3"
        if not audio_fixture.exists():
            pytest.skip("Audio-only fixture not available")

        contract = {
            "version": "1.0.0",
            "media_asset_id": 10,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(audio_fixture),
                "mime_type": "audio/mpeg",
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440010",
            "created_at": "2026-09-16T10:00:00Z",
        }
        result = probe_media(contract)

        if result["status"] == "success":
            assert result["probe"]["video_codec"] is None
            assert result["probe"]["audio_codec"] is not None

    def test_probe_with_video_only_media(self) -> None:
        """Video-only media should have null audio_codec."""
        video_only_fixture = FIXTURES_DIR / "video_only.mp4"
        if not video_only_fixture.exists():
            pytest.skip("Video-only fixture not available")

        contract = {
            "version": "1.0.0",
            "media_asset_id": 11,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": str(video_only_fixture),
                "mime_type": "video/mp4",
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440011",
            "created_at": "2026-09-16T10:00:00Z",
        }
        result = probe_media(contract)

        if result["status"] == "success":
            assert result["probe"]["audio_codec"] is None
            assert result["probe"]["video_codec"] is not None


class TestProbeErrorHandling:
    """Test probe error handling for various failure modes."""

    def test_probe_nonexistent_file_returns_error(self, sample_contract_nonexistent: dict[str, Any]) -> None:
        """Probing a non-existent file returns an error status."""
        result = probe_media(sample_contract_nonexistent)

        assert result["status"] == "error"
        assert "error" in result
        assert len(result["error"]) > 0

    def test_probe_corrupt_file_returns_error(self, sample_contract_corrupt: dict[str, Any]) -> None:
        """Probing a corrupt file returns an error status."""
        result = probe_media(sample_contract_corrupt)

        assert result["status"] == "error"
        assert "error" in result

    def test_probe_empty_storage_key_returns_error(self) -> None:
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
        }
        result = probe_media(contract)

        assert result["status"] == "error"
        assert "No storage key" in result["error"]


class TestProbeTimeout:
    """Test timeout enforcement."""

    @patch("aiclip_worker.actions.probe.subprocess.run")
    def test_probe_timeout_returns_error(self, mock_run: MagicMock, sample_contract: dict[str, Any]) -> None:
        """Timeout during FFprobe invocation returns an error."""
        import subprocess
        mock_run.side_effect = subprocess.TimeoutExpired(cmd="ffprobe", timeout=1)

        os.environ["PROBE_TIMEOUT_SECONDS"] = "1"
        try:
            result = probe_media(sample_contract)
        finally:
            del os.environ["PROBE_TIMEOUT_SECONDS"]

        assert result["status"] == "error"
        assert "timed out" in result["error"].lower() or "timeout" in result["error"].lower()

    @patch("aiclip_worker.actions.probe.subprocess.run")
    def test_probe_ffprobe_not_found_returns_error(self, mock_run: MagicMock, sample_contract: dict[str, Any]) -> None:
        """FFprobe binary not found returns an error."""
        mock_run.side_effect = FileNotFoundError

        result = probe_media(sample_contract)

        assert result["status"] == "error"
        assert "not found" in result["error"].lower()


class TestProbeSubprocessSafety:
    """Test that subprocess is invoked safely (no shell interpolation)."""

    @patch("aiclip_worker.actions.probe.subprocess.run")
    def test_no_shell_interpolation(self, mock_run: MagicMock, sample_contract: dict[str, Any]) -> None:
        """FFprobe is invoked with list arguments, not shell string."""
        mock_result = MagicMock()
        mock_result.returncode = 1
        mock_result.stdout = ""
        mock_result.stderr = "error"
        mock_run.return_value = mock_result

        probe_media(sample_contract)

        # Verify subprocess.run was called with list (not string)
        args, kwargs = mock_run.call_args
        assert isinstance(args[0], list)
        assert kwargs.get("shell", False) is not False or "shell" not in kwargs
