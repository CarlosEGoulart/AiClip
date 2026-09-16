"""Tests for worker error handling edge cases."""

from __future__ import annotations

import os
from typing import Any
from unittest.mock import MagicMock, patch

import pytest

from aiclip_worker.actions.probe import probe_media


class TestStderrCapture:
    """Test that stderr is captured on failures."""

    @patch("aiclip_worker.actions.probe.subprocess.run")
    def test_capture_stderr_on_ffprobe_failure(
        self, mock_run: MagicMock, sample_contract_corrupt: dict[str, Any]
    ) -> None:
        """Stderr is captured and included in error response on FFprobe failure."""
        mock_result = MagicMock()
        mock_result.returncode = 1
        mock_result.stdout = ""
        mock_result.stderr = "Invalid data found when processing input"
        mock_run.return_value = mock_result

        result = probe_media(sample_contract_corrupt)

        assert result["status"] == "error"
        assert result["stderr"] == "Invalid data found when processing input"

    @patch("aiclip_worker.actions.probe.subprocess.run")
    def test_empty_stderr_on_timeout(
        self, mock_run: MagicMock, sample_contract: dict[str, Any]
    ) -> None:
        """Timeout error includes appropriate message."""
        import subprocess
        mock_run.side_effect = subprocess.TimeoutExpired(cmd="ffprobe", timeout=1)

        os.environ["PROBE_TIMEOUT_SECONDS"] = "1"
        try:
            result = probe_media(sample_contract)
        finally:
            del os.environ["PROBE_TIMEOUT_SECONDS"]

        assert result["status"] == "error"
        assert "timed out" in result["error"].lower() or "timeout" in result["error"].lower()


class TestTimeoutEnforcement:
    """Test that timeout is properly enforced."""

    @patch("aiclip_worker.actions.probe.subprocess.run")
    def test_enforce_timeout_on_ffprobe_invocation(
        self, mock_run: MagicMock, sample_contract: dict[str, Any]
    ) -> None:
        """Timeout parameter is passed to subprocess.run."""
        import subprocess
        mock_run.side_effect = subprocess.TimeoutExpired(cmd="ffprobe", timeout=5)

        os.environ["PROBE_TIMEOUT_SECONDS"] = "5"
        try:
            result = probe_media(sample_contract)
        finally:
            del os.environ["PROBE_TIMEOUT_SECONDS"]

        # Verify timeout was passed to subprocess.run
        _, kwargs = mock_run.call_args
        assert kwargs.get("timeout") == 5


class TestShellSafety:
    """Test that no shell interpolation occurs."""

    @patch("aiclip_worker.actions.probe.subprocess.run")
    def test_no_shell_expansion_with_metacharacters(
        self, mock_run: MagicMock
    ) -> None:
        """Storage key with shell metacharacters is not expanded."""
        mock_result = MagicMock()
        mock_result.returncode = 1
        mock_result.stdout = ""
        mock_result.stderr = "No such file"
        mock_run.return_value = mock_result

        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": "/path/with $(command) and `backticks`/file.mp4",
                "mime_type": "video/mp4",
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-16T10:00:00Z",
        }

        result = probe_media(contract)

        # Verify subprocess.run was called with list, not shell string
        args, kwargs = mock_run.call_args
        assert isinstance(args[0], list)
        assert args[0][0] == "ffprobe"
        # The metacharacters should be passed literally
        assert "$(command)" in args[0][-1] or "command" in args[0][-1]

    @patch("aiclip_worker.actions.probe.subprocess.run")
    def test_shell_kwarg_not_set(
        self, mock_run: MagicMock, sample_contract: dict[str, Any]
    ) -> None:
        """Shell parameter is not set to True."""
        mock_result = MagicMock()
        mock_result.returncode = 1
        mock_result.stdout = ""
        mock_result.stderr = ""
        mock_run.return_value = mock_result

        probe_media(sample_contract)

        _, kwargs = mock_run.call_args
        assert kwargs.get("shell") is not True


class TestInvalidJsonOutput:
    """Test handling of invalid JSON from FFprobe."""

    @patch("aiclip_worker.actions.probe.subprocess.run")
    def test_invalid_json_output_from_ffprobe(
        self, mock_run: MagicMock, sample_contract: dict[str, Any]
    ) -> None:
        """Invalid JSON from FFprobe returns an error."""
        mock_result = MagicMock()
        mock_result.returncode = 0
        mock_result.stdout = "not valid json {{{"
        mock_result.stderr = ""
        mock_run.return_value = mock_result

        result = probe_media(sample_contract)

        assert result["status"] == "error"
        assert "Invalid JSON" in result["error"] or "invalid" in result["error"].lower()
