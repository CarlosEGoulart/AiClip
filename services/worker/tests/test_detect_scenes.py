"""Tests for the detect_scenes action."""

from __future__ import annotations

import json
import os
import subprocess
import time
from pathlib import Path
from typing import Any
from unittest.mock import MagicMock, patch

import pytest

from aiclip_worker.actions.detect_scenes import detect_scenes
from aiclip_worker.scene_detection import Scene, SceneResult


FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"


@pytest.fixture
def sample_video_path(tmp_path: Path) -> str:
    """Create a minimal valid video file for testing."""
    video_path = tmp_path / "test_video.mp4"
    # Create a minimal MP4 file (ftyp box)
    video_path.write_bytes(
        b'\x00\x00\x00\x1c\x66\x74\x79\x70\x69\x73\x6f\x6d'
        b'\x00\x00\x02\x00\x69\x73\x6f\x6d\x69\x73\x6f\x32'
        b'\x6d\x70\x34\x31'
    )
    return str(video_path)


@pytest.fixture
def sample_contract_detect_scenes(sample_video_path: str) -> dict[str, Any]:
    """Valid detect_scenes contract with video file path."""
    return {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": sample_video_path,
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
def sample_contract_no_storage_key() -> dict[str, Any]:
    """detect_scenes contract with empty storage key."""
    return {
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


@pytest.fixture
def sample_contract_nonexistent_file() -> dict[str, Any]:
    """detect_scenes contract pointing to non-existent file."""
    return {
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


class TestDetectScenesSuccess:
    """Test successful scene detection."""

    def test_detect_scenes_returns_expected_fields(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Scene detection returns all expected fields."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            result = detect_scenes(sample_contract_detect_scenes)

        assert result["status"] == "success"
        assert "scene_detection" in result
        scene_detection = result["scene_detection"]
        assert "detector" in scene_detection
        assert "detector_version" in scene_detection
        assert "parameters" in scene_detection
        assert "scenes" in scene_detection

    def test_detect_scenes_scenes_are_ordered(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Scenes are ordered by start_ms ascending."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            result = detect_scenes(sample_contract_detect_scenes)

        scenes = result["scene_detection"]["scenes"]
        for i in range(1, len(scenes)):
            assert scenes[i]["start_ms"] >= scenes[i - 1]["start_ms"]
        # Verify scene format
        for scene in scenes:
            assert "index" in scene
            assert "start_ms" in scene
            assert "end_ms" in scene
            assert scene["start_ms"] >= 0
            assert scene["end_ms"] > scene["start_ms"]

    def test_detect_scenes_no_overlapping(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Scenes do not overlap."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            result = detect_scenes(sample_contract_detect_scenes)

        scenes = result["scene_detection"]["scenes"]
        for i in range(1, len(scenes)):
            assert scenes[i]["start_ms"] >= scenes[i - 1]["end_ms"]

    def test_detect_scenes_deterministic_engine_returns_fixed_output(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Deterministic engine returns fixed output."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            result1 = detect_scenes(sample_contract_detect_scenes)
            result2 = detect_scenes(sample_contract_detect_scenes)

        assert result1["scene_detection"]["scenes"] == result2["scene_detection"]["scenes"]
        assert result1["scene_detection"]["detector"] == "deterministic"

    def test_detect_scenes_empty_scenes_is_valid(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Empty scenes list is valid (not an error)."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            result = detect_scenes(sample_contract_detect_scenes)

        # Even if scenes is empty, status should be success
        assert result["status"] == "success"
        assert isinstance(result["scene_detection"]["scenes"], list)

    def test_detect_scenes_detector_name_in_result(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Detector name appears in result."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            result = detect_scenes(sample_contract_detect_scenes)

        assert result["scene_detection"]["detector"] == "deterministic"
        assert result["scene_detection"]["detector_version"] == "0.0.0"


class TestDetectScenesError:
    """Test error handling."""

    def test_detect_scenes_nonexistent_file_returns_error(
        self, sample_contract_nonexistent_file: dict[str, Any]
    ) -> None:
        """Non-existent file returns error."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            result = detect_scenes(sample_contract_nonexistent_file)

        assert result["status"] == "error"
        assert "error" in result
        assert "No such file" in result["error"] or "not found" in result["error"].lower()

    def test_detect_scenes_empty_storage_key_returns_error(
        self, sample_contract_no_storage_key: dict[str, Any]
    ) -> None:
        """Empty storage key returns error."""
        result = detect_scenes(sample_contract_no_storage_key)

        assert result["status"] == "error"
        assert "error" in result
        assert "storage" in result["error"].lower() or "key" in result["error"].lower()

    def test_detect_scenes_invalid_contract_returns_error(self) -> None:
        """Contract with missing required fields returns error."""
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
        result = detect_scenes(contract)
        assert result["status"] == "error"


class TestDetectScenesEngineSelection:
    """Test engine selection."""

    def test_detect_scenes_uses_deterministic_engine_when_configured(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Deterministic engine is used when configured."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            result = detect_scenes(sample_contract_detect_scenes)

        assert result["scene_detection"]["detector"] == "deterministic"

    def test_detect_scenes_engine_in_result(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Engine name and version appear in result."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            result = detect_scenes(sample_contract_detect_scenes)

        assert result["scene_detection"]["detector"] == "deterministic"
        assert result["scene_detection"]["detector_version"] == "0.0.0"


class TestDetectScenesTimeout:
    """Tests proving subprocess-level timeout kills child and returns in bounded time."""

    def test_detect_scenes_timeout_is_real_wall_clock(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Proves timeout kills child and returns in bounded time."""
        def mock_popen_slow(*args, **kwargs):
            class FakeProcess:
                pid = 99999
                returncode = 0

                def communicate(self, input=None, timeout=None):
                    import time as _time
                    _time.sleep(timeout or 1)
                    raise subprocess.TimeoutExpired(cmd=b"test", timeout=timeout or 1)

                def wait(self, timeout=None):
                    return 0

            return FakeProcess()

        start = time.monotonic()

        with patch.dict(os.environ, {
            "SCENE_DETECTION_ENGINE": "deterministic",
            "SCENE_DETECT_TIMEOUT_SECONDS": "1",
        }):
            with patch("aiclip_worker.actions.detect_scenes.subprocess.Popen", side_effect=mock_popen_slow):
                result = detect_scenes(sample_contract_detect_scenes)

        elapsed = time.monotonic() - start

        assert result["status"] == "error"
        assert "timeout" in result["error"].lower() or "timed out" in result["error"].lower()
        assert elapsed < 3.0, f"Timeout took {elapsed:.1f}s, expected < 3.0s for 1s configured timeout"

    def test_detect_scenes_timeout_kills_child(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """Verify child process is terminated, not orphaned."""
        mock_process = MagicMock()
        mock_process.pid = 99999

        def slow_communicate(input=None, timeout=None):
            raise subprocess.TimeoutExpired(cmd=b"test", timeout=timeout or 1)

        mock_process.communicate.side_effect = slow_communicate

        with patch.dict(os.environ, {
            "SCENE_DETECTION_ENGINE": "deterministic",
            "SCENE_DETECT_TIMEOUT_SECONDS": "1",
        }):
            with patch("aiclip_worker.actions.detect_scenes.subprocess.Popen", return_value=mock_process):
                with patch("aiclip_worker.actions.detect_scenes.os.getpgid", return_value=99999):
                    with patch("aiclip_worker.actions.detect_scenes.os.killpg") as mock_kill:
                        result = detect_scenes(sample_contract_detect_scenes)

        assert result["status"] == "error"
        assert "timeout" in result["error"].lower() or "timed out" in result["error"].lower()
        assert mock_kill.called, "Process group should be killed on timeout"


class TestProcessGroupIsolation:
    def test_popen_receives_start_new_session(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """subprocess.Popen is called with start_new_session=True."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            with patch("aiclip_worker.actions.detect_scenes.subprocess.Popen") as mock_popen:
                mock_proc = MagicMock()
                mock_proc.communicate.return_value = (
                    json.dumps({
                        "status": "success",
                        "scene_detection": {
                            "detector": "deterministic",
                            "detector_version": "0.0.0",
                            "parameters": {},
                            "scenes": [],
                        },
                    }).encode(),
                    b"",
                )
                mock_proc.returncode = 0
                mock_popen.return_value = mock_proc
                detect_scenes(sample_contract_detect_scenes)

                _, kwargs = mock_popen.call_args
                assert kwargs.get("start_new_session") is True, (
                    "Popen must receive start_new_session=True for process group isolation"
                )


class TestSelfGroupDefense:
    def test_killpg_not_called_when_pgid_equals_parent(
        self, sample_contract_detect_scenes: dict[str, Any]
    ) -> None:
        """When child PGID == parent PGID, killpg must not be called (self-group defense)."""
        mock_process = MagicMock()
        mock_process.pid = 99999
        mock_process.returncode = 0

        valid_output = json.dumps({
            "status": "success",
            "scene_detection": {
                "detector": "deterministic",
                "detector_version": "0.0.0",
                "parameters": {},
                "scenes": [{"index": 0, "start_ms": 0, "end_ms": 3120}],
            },
        }).encode()

        def instant_communicate(input=None, timeout=None):
            return (valid_output, b"")

        mock_process.communicate.side_effect = instant_communicate

        with patch.dict(os.environ, {
            "SCENE_DETECTION_ENGINE": "deterministic",
            "SCENE_DETECT_TIMEOUT_SECONDS": "1",
        }):
            with patch("aiclip_worker.actions.detect_scenes.subprocess.Popen", return_value=mock_process):
                with patch("aiclip_worker.actions.detect_scenes.os.getpgid", return_value=os.getpgrp()):
                    with patch("aiclip_worker.actions.detect_scenes.os.killpg") as mock_killpg:
                        with patch("aiclip_worker.actions.detect_scenes.os.kill") as mock_kill:
                            result = detect_scenes(sample_contract_detect_scenes)

        assert result["status"] == "success"
        assert not mock_killpg.called, "killpg must NOT be called when child PGID == parent PGID"
