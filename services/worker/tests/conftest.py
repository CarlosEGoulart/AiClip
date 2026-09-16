"""Shared fixtures for AiClip worker tests."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path
from typing import Any, Generator

import pytest


FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"


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


@pytest.fixture(scope="session", autouse=True)
def create_test_fixtures() -> Generator[None, None, None]:
    """Create test fixture media files if they do not exist."""
    fixtures_dir = FIXTURES_DIR
    fixtures_dir.mkdir(parents=True, exist_ok=True)

    valid_path = fixtures_dir / "valid_sample.mp4"
    corrupt_path = fixtures_dir / "corrupt_sample.mp4"
    video_only_path = fixtures_dir / "video_only.mp4"

    if not valid_path.exists():
        # Create a minimal valid MP4 using ffmpeg
        try:
            subprocess.run(
                [
                    "ffmpeg", "-y",
                    "-f", "lavfi", "-i", "color=c=black:s=640x480:d=1",
                    "-f", "lavfi", "-i", "sine=frequency=440:duration=1",
                    "-c:v", "libx264", "-c:a", "aac",
                    "-shortest",
                    str(valid_path),
                ],
                capture_output=True,
                timeout=30,
                check=True,
            )
        except (subprocess.CalledProcessError, FileNotFoundError, subprocess.TimeoutExpired):
            # If ffmpeg is not available, create a minimal valid MP4 header
            # This is a minimal ftyp box for MP4
            valid_path.write_bytes(
                b'\x00\x00\x00\x1c\x66\x74\x79\x70\x69\x73\x6f\x6d'
                b'\x00\x00\x02\x00\x69\x73\x6f\x6d\x69\x73\x6f\x32'
                b'\x6d\x70\x34\x31'
            )

    if not corrupt_path.exists():
        # Create a corrupt file with random-ish bytes
        corrupt_path.write_bytes(b'\x00\x01\x02\x03corrupt media data here')

    if not video_only_path.exists():
        # Create a video-only MP4 (no audio stream)
        try:
            subprocess.run(
                [
                    "ffmpeg", "-y",
                    "-f", "lavfi", "-i", "color=c=black:s=640x480:d=1",
                    "-c:v", "libx264",
                    "-an",  # no audio
                    str(video_only_path),
                ],
                capture_output=True,
                timeout=30,
                check=True,
            )
        except (subprocess.CalledProcessError, FileNotFoundError, subprocess.TimeoutExpired):
            # If ffmpeg is not available, create a minimal valid MP4 header
            # This is a minimal ftyp box for MP4
            video_only_path.write_bytes(
                b'\x00\x00\x00\x1c\x66\x74\x79\x70\x69\x73\x6f\x6d'
                b'\x00\x00\x02\x00\x69\x73\x6f\x6d\x69\x73\x6f\x32'
                b'\x6d\x70\x34\x31'
            )

    yield

    # Cleanup is optional; fixtures are committed or regenerated
