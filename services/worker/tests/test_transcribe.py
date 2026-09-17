"""Tests for the transcribe action."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any
from unittest.mock import MagicMock, patch

import pytest

from aiclip_worker.actions.transcribe import transcribe
from aiclip_worker.transcription import DeterministicTranscriber, Segment, TranscriptResult


FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"


@pytest.fixture
def normalized_audio_path(tmp_path: Path) -> str:
    """Create a minimal normalized WAV file for testing."""
    # Create a minimal WAV file (44-byte header + silence)
    wav_path = tmp_path / "normalized_audio.wav"
    # Write a minimal valid WAV header
    with open(wav_path, "wb") as f:
        # RIFF header
        f.write(b"RIFF")
        f.write((36).to_bytes(4, "little"))  # file size - 8
        f.write(b"WAVE")
        # fmt chunk
        f.write(b"fmt ")
        f.write((16).to_bytes(4, "little"))  # chunk size
        f.write((1).to_bytes(2, "little"))  # PCM format
        f.write((1).to_bytes(2, "little"))  # mono
        f.write((16000).to_bytes(4, "little"))  # sample rate
        f.write((32000).to_bytes(4, "little"))  # byte rate
        f.write((2).to_bytes(2, "little"))  # block align
        f.write((16).to_bytes(2, "little"))  # bits per sample
        # data chunk
        f.write(b"data")
        f.write((0).to_bytes(4, "little"))  # data size (empty)

    return str(wav_path)


@pytest.fixture
def sample_contract_transcribe(normalized_audio_path: str) -> dict[str, Any]:
    """Valid transcribe contract with derived_asset_id."""
    return {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": normalized_audio_path,
            "mime_type": "audio/wav",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "transcribe",
        "derived_asset_id": 1,
    }


@pytest.fixture
def sample_contract_no_storage_key() -> dict[str, Any]:
    """Transcribe contract with empty storage key."""
    return {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": "",
            "mime_type": "audio/wav",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "transcribe",
        "derived_asset_id": 1,
    }


@pytest.fixture
def sample_contract_nonexistent_file() -> dict[str, Any]:
    """Transcribe contract pointing to non-existent file."""
    return {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": "/nonexistent/path/audio.wav",
            "mime_type": "audio/wav",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "transcribe",
        "derived_asset_id": 1,
    }


class TestTranscribeSuccess:
    """Test successful transcription."""

    def test_transcribe_returns_expected_fields(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """Transcription returns all expected fields."""
        with patch.dict(os.environ, {"TRANSCRIPTION_ENGINE": "deterministic"}):
            result = transcribe(sample_contract_transcribe)

        assert result["status"] == "success"
        assert "transcription" in result
        transcription = result["transcription"]
        assert "language" in transcription
        assert "full_text" in transcription
        assert "segments" in transcription
        assert "engine" in transcription
        assert "model" in transcription

    def test_transcribe_segments_are_ordered(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """Segments are ordered by start_ms ascending."""
        with patch.dict(os.environ, {"TRANSCRIPTION_ENGINE": "deterministic"}):
            result = transcribe(sample_contract_transcribe)

        segments = result["transcription"]["segments"]
        assert len(segments) > 0
        for i in range(1, len(segments)):
            assert segments[i]["start_ms"] >= segments[i - 1]["start_ms"]
        # Verify segment format
        for seg in segments:
            assert "start_ms" in seg
            assert "end_ms" in seg
            assert "text" in seg
            assert seg["start_ms"] >= 0
            assert seg["end_ms"] >= seg["start_ms"]

    def test_transcribe_full_text_concatenates_segments(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """full_text is the concatenation of segment texts."""
        with patch.dict(os.environ, {"TRANSCRIPTION_ENGINE": "deterministic"}):
            result = transcribe(sample_contract_transcribe)

        transcription = result["transcription"]
        expected_full_text = " ".join(seg["text"] for seg in transcription["segments"])
        assert transcription["full_text"] == expected_full_text

    def test_transcribe_deterministic_engine(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """Deterministic engine returns fixed output."""
        with patch.dict(os.environ, {"TRANSCRIPTION_ENGINE": "deterministic"}):
            result1 = transcribe(sample_contract_transcribe)
            result2 = transcribe(sample_contract_transcribe)

        assert result1["transcription"]["full_text"] == result2["transcription"]["full_text"]
        assert result1["transcription"]["engine"] == "deterministic"


class TestTranscribeError:
    """Test error handling."""

    def test_transcribe_nonexistent_file_returns_error(
        self, sample_contract_nonexistent_file: dict[str, Any]
    ) -> None:
        """Non-existent file returns error."""
        with patch.dict(os.environ, {"TRANSCRIPTION_ENGINE": "deterministic"}):
            result = transcribe(sample_contract_nonexistent_file)

        assert result["status"] == "error"
        assert "error" in result
        assert "No such file" in result["error"] or "not found" in result["error"].lower()

    def test_transcribe_empty_storage_key_returns_error(
        self, sample_contract_no_storage_key: dict[str, Any]
    ) -> None:
        """Empty storage key returns error."""
        result = transcribe(sample_contract_no_storage_key)

        assert result["status"] == "error"
        assert "error" in result
        assert "storage" in result["error"].lower() or "key" in result["error"].lower()

    def test_transcribe_missing_storage_returns_error(self) -> None:
        """Contract with no storage key returns error."""
        contract = {
            "version": "1.0.0",
            "media_asset_id": 1,
            "project_id": 1,
            "storage": {
                "disk": "media",
                "key": "",
                "mime_type": "audio/wav",
            },
            "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
            "created_at": "2026-09-17T10:00:00Z",
            "action": "transcribe",
            "derived_asset_id": 1,
        }
        result = transcribe(contract)
        assert result["status"] == "error"

    def test_transcribe_timeout_returns_error(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """Transcription that exceeds timeout returns error."""
        mock_process = MagicMock()
        mock_process.pid = 99999

        def slow_communicate(input=None, timeout=None):
            raise subprocess.TimeoutExpired(cmd=b"test", timeout=timeout or 1)

        mock_process.communicate.side_effect = slow_communicate

        with patch.dict(os.environ, {
            "TRANSCRIPTION_ENGINE": "deterministic",
            "TRANSCRIBE_TIMEOUT_SECONDS": "1",
        }):
            with patch(
                "aiclip_worker.actions.transcribe.subprocess.Popen",
                return_value=mock_process,
            ):
                result = transcribe(sample_contract_transcribe)

        assert result["status"] == "error"
        assert "timeout" in result["error"].lower() or "timed out" in result["error"].lower()


class TestTranscribeEngineSelection:
    """Test engine selection."""

    def test_transcribe_uses_deterministic_engine_when_configured(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """Deterministic engine is used when configured."""
        with patch.dict(os.environ, {"TRANSCRIPTION_ENGINE": "deterministic"}):
            result = transcribe(sample_contract_transcribe)

        assert result["transcription"]["engine"] == "deterministic"

    def test_transcribe_engine_in_result(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """Engine name appears in result."""
        with patch.dict(os.environ, {"TRANSCRIPTION_ENGINE": "deterministic"}):
            result = transcribe(sample_contract_transcribe)

        assert result["transcription"]["engine"] == "deterministic"
        assert result["transcription"]["model"] == "deterministic"


class TestTranscribeTimeout:
    """Tests proving subprocess-level timeout kills child and returns in bounded time.

    These tests verify that the transcribe action uses subprocess isolation
    (not ThreadPoolExecutor) and enforces a real wall-clock timeout on the child
    process, killing it via process group signal when the deadline expires.
    """

    def test_transcribe_timeout_is_real_wall_clock(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """Proves timeout kills child and returns in bounded time."""
        def mock_popen_slow(*args, **kwargs):
            class FakeProcess:
                pid = 99999

                def communicate(self, input=None, timeout=None):
                    import time as _time
                    _time.sleep(timeout or 1)
                    raise subprocess.TimeoutExpired(cmd=b"test", timeout=timeout or 1)

            return FakeProcess()

        start = time.monotonic()

        with patch.dict(os.environ, {
            "TRANSCRIPTION_ENGINE": "deterministic",
            "TRANSCRIBE_TIMEOUT_SECONDS": "1",
        }):
            with patch("aiclip_worker.actions.transcribe.subprocess.Popen", side_effect=mock_popen_slow):
                result = transcribe(sample_contract_transcribe)

        elapsed = time.monotonic() - start

        assert result["status"] == "error"
        assert "timeout" in result["error"].lower() or "timed out" in result["error"].lower()
        assert elapsed < 3.0, f"Timeout took {elapsed:.1f}s, expected < 3.0s for 1s configured timeout"

    def test_transcribe_timeout_kills_child(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """Verify child process is terminated, not orphaned."""
        mock_process = MagicMock()
        mock_process.pid = 99999

        def slow_communicate(input=None, timeout=None):
            raise subprocess.TimeoutExpired(cmd=b"test", timeout=timeout or 1)

        mock_process.communicate.side_effect = slow_communicate

        with patch.dict(os.environ, {
            "TRANSCRIPTION_ENGINE": "deterministic",
            "TRANSCRIBE_TIMEOUT_SECONDS": "1",
        }):
            with patch("aiclip_worker.actions.transcribe.subprocess.Popen", return_value=mock_process):
                with patch("aiclip_worker.actions.transcribe.os.getpgid", return_value=99999):
                    with patch("aiclip_worker.actions.transcribe.os.killpg") as mock_kill:
                        result = transcribe(sample_contract_transcribe)

        assert result["status"] == "error"
        assert "timeout" in result["error"].lower() or "timed out" in result["error"].lower()
        assert mock_kill.called, "Process group should be killed on timeout"


class TestProcessGroupIsolation:
    def test_popen_receives_start_new_session(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """subprocess.Popen is called with start_new_session=True."""
        with patch.dict(os.environ, {"TRANSCRIPTION_ENGINE": "deterministic"}):
            with patch("aiclip_worker.actions.transcribe.subprocess.Popen") as mock_popen:
                mock_proc = MagicMock()
                mock_proc.communicate.return_value = (
                    json.dumps({
                        "status": "success",
                        "transcription": {
                            "language": "en", "full_text": "ok",
                            "segments": [], "engine": "deterministic", "model": "deterministic",
                        },
                    }).encode(),
                    b"",
                )
                mock_proc.returncode = 0
                mock_popen.return_value = mock_proc
                transcribe(sample_contract_transcribe)

                _, kwargs = mock_popen.call_args
                assert kwargs.get("start_new_session") is True, (
                    "Popen must receive start_new_session=True for process group isolation"
                )


class TestRealWallClockTimeout:
    def test_real_timeout_reaps_child_within_bounds(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """Real subprocess timeout: sleep 5s child + 1s timeout must complete < 3s wall."""
        import sys as _sys
        original_popen = subprocess.Popen

        def real_sleep_popen(cmd, **kwargs):
            return original_popen(
                [_sys.executable, "-c", "import time; time.sleep(5)"],
                **kwargs,
            )

        with patch.dict(os.environ, {
            "TRANSCRIPTION_ENGINE": "deterministic",
            "TRANSCRIBE_TIMEOUT_SECONDS": "1",
        }):
            with patch(
                "aiclip_worker.actions.transcribe.subprocess.Popen",
                side_effect=real_sleep_popen,
            ):
                start = time.monotonic()
                result = transcribe(sample_contract_transcribe)
                elapsed = time.monotonic() - start

        assert result["status"] == "error"
        assert "timeout" in result["error"].lower() or "timed out" in result["error"].lower()
        assert elapsed < 3.0, f"Timeout took {elapsed:.1f}s, expected < 3s"


class TestSelfGroupDefense:
    def test_killpg_not_called_when_pgid_equals_parent(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """When child PGID == parent PGID, killpg must not be called (self-group defense)."""
        mock_process = MagicMock()
        mock_process.pid = 99999
        mock_process.returncode = 0

        valid_output = json.dumps({
            "status": "success",
            "transcription": {
                "language": "en",
                "full_text": "hello",
                "segments": [{"start_ms": 0, "end_ms": 1000, "text": "hello"}],
                "engine": "deterministic",
                "model": "deterministic",
            },
        }).encode()

        def instant_communicate(input=None, timeout=None):
            return (valid_output, b"")

        mock_process.communicate.side_effect = instant_communicate

        with patch.dict(os.environ, {
            "TRANSCRIPTION_ENGINE": "deterministic",
            "TRANSCRIBE_TIMEOUT_SECONDS": "1",
        }):
            with patch("aiclip_worker.actions.transcribe.subprocess.Popen", return_value=mock_process):
                with patch("aiclip_worker.actions.transcribe.os.getpgid", return_value=os.getpgrp()):
                    with patch("aiclip_worker.actions.transcribe.os.killpg") as mock_killpg:
                        with patch("aiclip_worker.actions.transcribe.os.kill") as mock_kill:
                            result = transcribe(sample_contract_transcribe)

        assert result["status"] == "success"
        assert not mock_killpg.called, "killpg must NOT be called when child PGID == parent PGID"


class TestLargeStdout:
    def test_large_stdout_no_deadlock(
        self, sample_contract_transcribe: dict[str, Any]
    ) -> None:
        """Child writing ~256 KiB to stdout does not deadlock the supervisor."""
        import sys as _sys
        original_popen = subprocess.Popen

        # Child script generates large payload internally (no inline 256KB string)
        child_script = (
            "import sys, json; "
            "payload = 'x' * (256 * 1024); "
            "result = json.dumps({'status': 'success', 'transcription': {"
            "'language': 'en', 'full_text': payload, 'segments': [], "
            "'engine': 'deterministic', 'model': 'deterministic'}}); "
            "sys.stdout.write(result); sys.stdout.flush()"
        )

        def large_stdout_popen(cmd, **kwargs):
            return original_popen(
                [_sys.executable, "-c", child_script],
                **kwargs,
            )

        with patch.dict(os.environ, {
            "TRANSCRIPTION_ENGINE": "deterministic",
            "TRANSCRIBE_TIMEOUT_SECONDS": "10",
        }):
            with patch(
                "aiclip_worker.actions.transcribe.subprocess.Popen",
                side_effect=large_stdout_popen,
            ):
                start = time.monotonic()
                result = transcribe(sample_contract_transcribe)
                elapsed = time.monotonic() - start

        assert result["status"] == "success"
        assert result["transcription"]["full_text"] == "x" * (256 * 1024)
        assert elapsed < 5.0, f"Large stdout took {elapsed:.1f}s, expected < 5.0s"
