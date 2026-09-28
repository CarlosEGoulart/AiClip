"""Shared fixtures for AiClip worker tests."""

from __future__ import annotations

import json
import os
import struct
import subprocess
import sys
import tempfile
import wave
from pathlib import Path
from typing import Any, Callable, Generator

import pytest

# Ensure aiclip_worker package is importable when running from source directory
_PROJECT_ROOT = Path(__file__).resolve().parent.parent
if str(_PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(_PROJECT_ROOT))


FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"


def _ffmpeg_available() -> bool:
    """Check if ffmpeg and ffprobe are available."""
    try:
        subprocess.run(["ffmpeg", "-version"], capture_output=True, timeout=5, check=True)
        subprocess.run(["ffprobe", "-version"], capture_output=True, timeout=5, check=True)
        return True
    except (FileNotFoundError, subprocess.CalledProcessError, subprocess.TimeoutExpired):
        return False


def _create_valid_wav(path: Path, duration_s: float = 1.0, sample_rate: int = 16000, channels: int = 1) -> bool:
    """Create a valid WAV file with actual PCM silence data."""
    try:
        num_samples = int(duration_s * sample_rate)
        with wave.open(str(path), "wb") as wav:
            wav.setnchannels(channels)
            wav.setsampwidth(2)  # 16-bit
            wav.setframerate(sample_rate)
            # Write silence (zeros)
            silence = struct.pack(f"<{num_samples * channels}h", *([0] * (num_samples * channels)))
            wav.writeframes(silence)
        return True
    except Exception:
        return False


def _create_valid_mp4(path: Path, duration_s: float = 1.0, width: int = 640, height: int = 480, has_audio: bool = True) -> bool:
    """Create a minimal but valid MP4 using ffmpeg. Requires ffmpeg to be available."""
    if not _ffmpeg_available():
        return False
    try:
        if has_audio:
            cmd = [
                "ffmpeg", "-y",
                "-f", "lavfi", "-i", f"color=c=black:s={width}x{height}:d={duration_s}",
                "-f", "lavfi", "-i", f"sine=frequency=440:duration={duration_s}",
                "-c:v", "libx264", "-c:a", "aac",
                "-shortest",
                "-movflags", "+faststart",
                str(path),
            ]
        else:
            cmd = [
                "ffmpeg", "-y",
                "-f", "lavfi", "-i", f"color=c=black:s={width}x{height}:d={duration_s}",
                "-c:v", "libx264",
                "-an",
                "-movflags", "+faststart",
                str(path),
            ]
        result = subprocess.run(cmd, capture_output=True, timeout=30, check=True)
        return result.returncode == 0 and path.exists() and path.stat().st_size > 1000
    except (subprocess.CalledProcessError, FileNotFoundError, subprocess.TimeoutExpired, Exception):
        return False


def _create_valid_mp3(path: Path, duration_s: float = 1.0) -> bool:
    """Create a minimal but valid MP3 using ffmpeg. Requires ffmpeg to be available."""
    if not _ffmpeg_available():
        return False
    try:
        cmd = [
            "ffmpeg", "-y",
            "-f", "lavfi", "-i", f"sine=frequency=440:duration={duration_s}",
            "-c:a", "libmp3lame",
            "-q:a", "9",
            str(path),
        ]
        result = subprocess.run(cmd, capture_output=True, timeout=30, check=True)
        return result.returncode == 0 and path.exists() and path.stat().st_size > 1000
    except (subprocess.CalledProcessError, FileNotFoundError, subprocess.TimeoutExpired, Exception):
        return False


def _probe_works(file_path: Path) -> bool:
    """Check if ffprobe can successfully parse the file."""
    if not _ffmpeg_available():
        return False
    try:
        result = subprocess.run(
            ["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", "-show_streams", str(file_path)],
            capture_output=True,
            timeout=10,
        )
        return result.returncode == 0
    except (FileNotFoundError, subprocess.TimeoutExpired, Exception):
        return False


@pytest.fixture
def sample_contract() -> dict[str, Any]:
    """Valid v1.0.0 media processing contract pointing to a fixture file."""
    return {
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
    }


@pytest.fixture
def sample_contract_corrupt() -> dict[str, Any]:
    """Contract pointing to a corrupt media file."""
    return {
        "version": "1.0.0",
        "media_asset_id": 2,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": str(FIXTURES_DIR / "corrupt_sample.mp4"),
            "mime_type": "video/mp4",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440001",
        "created_at": "2026-09-16T10:00:00Z",
    }


@pytest.fixture
def sample_contract_nonexistent() -> dict[str, Any]:
    """Contract pointing to a non-existent file."""
    return {
        "version": "1.0.0",
        "media_asset_id": 3,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": "/nonexistent/path/file.mp4",
            "mime_type": "video/mp4",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440002",
        "created_at": "2026-09-16T10:00:00Z",
    }


@pytest.fixture
def sample_contract_invalid() -> dict[str, Any]:
    """Contract with missing required fields."""
    return {
        "version": "1.0.0",
    }


@pytest.fixture
def sample_contract_unknown_version() -> dict[str, Any]:
    """Contract with unknown major version."""
    return {
        "version": "2.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": str(FIXTURES_DIR / "valid_sample.mp4"),
            "mime_type": "video/mp4",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-16T10:00:00Z",
    }


@pytest.fixture
def sample_contract_extract_audio() -> dict[str, Any]:
    """Valid extract_audio contract with output_storage and probe_data."""
    return {
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
            "key": "output/audio_normalized.wav",
            "mime_type": "audio/wav",
        },
        "probe_data": {
            "duration_ms": 1000,
            "audio_codec": "aac",
            "audio_channels": 2,
            "audio_sample_rate": 48000,
        },
    }


@pytest.fixture
def sample_contract_extract_audio_no_output() -> dict[str, Any]:
    """extract_audio contract missing output_storage."""
    return {
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
    }


@pytest.fixture
def sample_contract_video_no_audio() -> dict[str, Any]:
    """Contract pointing to video-only fixture (no audio stream)."""
    return {
        "version": "1.0.0",
        "media_asset_id": 4,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": str(FIXTURES_DIR / "video_only.mp4"),
            "mime_type": "video/mp4",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440004",
        "created_at": "2026-09-16T10:00:00Z",
        "action": "extract_audio",
        "output_storage": {
            "disk": "media",
            "key": "output/audio_normalized.wav",
            "mime_type": "audio/wav",
        },
        "probe_data": {
            "duration_ms": 1000,
            "audio_codec": None,
            "audio_channels": 0,
            "audio_sample_rate": 0,
        },
    }


@pytest.fixture
def sample_contract_transcribe() -> dict[str, Any]:
    """Valid transcribe contract with derived_asset_id."""
    return {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": str(FIXTURES_DIR / "normalized_audio.wav"),
            "mime_type": "audio/wav",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "transcribe",
        "derived_asset_id": 1,
    }


@pytest.fixture
def sample_contract_transcribe_no_derived_asset() -> dict[str, Any]:
    """Transcribe contract missing derived_asset_id."""
    return {
        "version": "1.0.0",
        "media_asset_id": 1,
        "project_id": 1,
        "storage": {
            "disk": "media",
            "key": str(FIXTURES_DIR / "normalized_audio.wav"),
            "mime_type": "audio/wav",
        },
        "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
        "created_at": "2026-09-17T10:00:00Z",
        "action": "transcribe",
    }


@pytest.fixture(scope="session")
def fixture_validity() -> dict[str, bool]:
    """Return a dict mapping fixture names to whether they are valid for ffprobe."""
    # This will be populated by create_test_fixtures (autouse session fixture)
    return FIXTURE_VALIDITY


@pytest.fixture
def require_valid_fixture(fixture_validity: dict[str, bool]) -> Callable[[str], None]:
    """Return a function that skips the test if the given fixture is not valid for ffprobe."""
    def _check(fixture_name: str) -> None:
        if not fixture_validity.get(fixture_name, False):
            if not _ffmpeg_available():
                pytest.skip(f"Fixture {fixture_name} requires ffmpeg/ffprobe which is not available in this environment")
            else:
                pytest.skip(f"Fixture {fixture_name} is not valid for ffprobe (creation may have failed)")
    return _check


@pytest.fixture(scope="session", autouse=True)
def create_test_fixtures() -> Generator[None, None, None]:
    """Create test fixture media files if they do not exist or are invalid.

    This fixture requires ffmpeg/ffprobe to be available for MP4/MP3 fixtures.
    WAV fixtures can be created without ffmpeg.
    """
    fixtures_dir = FIXTURES_DIR
    fixtures_dir.mkdir(parents=True, exist_ok=True)

    valid_path = fixtures_dir / "valid_sample.mp4"
    corrupt_path = fixtures_dir / "corrupt_sample.mp4"
    video_only_path = fixtures_dir / "video_only.mp4"
    audio_only_path = fixtures_dir / "audio_only.mp3"
    normalized_audio_path = fixtures_dir / "normalized_audio.wav"

    # Track which fixtures are valid for ffprobe
    fixture_validity = {}

    ffmpeg_is_available = _ffmpeg_available()

    # Create valid MP4 with audio+video (requires ffmpeg)
    if ffmpeg_is_available:
        if not valid_path.exists() or not _probe_works(valid_path):
            _create_valid_mp4(valid_path, has_audio=True)
    else:
        # Remove any existing invalid fallback files
        if valid_path.exists() and valid_path.stat().st_size < 1000:
            valid_path.unlink(missing_ok=True)

    # Create corrupt file (doesn't need ffmpeg)
    if not corrupt_path.exists():
        corrupt_path.write_bytes(b"\x00\x01\x02\x03corrupt media data here")

    # Create video-only MP4 (requires ffmpeg)
    if ffmpeg_is_available:
        if not video_only_path.exists() or not _probe_works(video_only_path):
            _create_valid_mp4(video_only_path, has_audio=False)
    else:
        if video_only_path.exists() and video_only_path.stat().st_size < 1000:
            video_only_path.unlink(missing_ok=True)

    # Create audio-only MP3 (requires ffmpeg)
    if ffmpeg_is_available:
        if not audio_only_path.exists() or not _probe_works(audio_only_path):
            _create_valid_mp3(audio_only_path)
    else:
        if audio_only_path.exists() and audio_only_path.stat().st_size < 1000:
            audio_only_path.unlink(missing_ok=True)

    # Create normalized audio WAV (always create valid WAV since we can do it without ffmpeg)
    if not normalized_audio_path.exists() or not _probe_works(normalized_audio_path):
        _create_valid_wav(normalized_audio_path, duration_s=1.0, sample_rate=16000, channels=1)

    # Validate all fixtures after creation
    for name, path in [
        ("valid_sample.mp4", valid_path),
        ("video_only.mp4", video_only_path),
        ("audio_only.mp3", audio_only_path),
        ("normalized_audio.wav", normalized_audio_path),
    ]:
        fixture_validity[name] = _probe_works(path)

    # Store validity for tests to check
    global FIXTURE_VALIDITY
    FIXTURE_VALIDITY = fixture_validity

    # Print fixture status for debugging
    for name, valid in fixture_validity.items():
        status = "VALID" if valid else "INVALID (ffmpeg not available or creation failed)"
        print(f"Fixture {name}: {status}")

    yield


# Make FIXTURE_VALIDITY available at module level
FIXTURE_VALIDITY = {}