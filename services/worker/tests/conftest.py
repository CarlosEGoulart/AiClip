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


@pytest.fixture(scope="session", autouse=True)
def create_test_fixtures() -> Generator[None, None, None]:
    """Create test fixture media files if they do not exist."""
    fixtures_dir = FIXTURES_DIR
    fixtures_dir.mkdir(parents=True, exist_ok=True)

    valid_path = fixtures_dir / "valid_sample.mp4"
    corrupt_path = fixtures_dir / "corrupt_sample.mp4"

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

    yield

    # Cleanup is optional; fixtures are committed or regenerated
