"""ExtractAudio action: invokes FFmpeg to extract and normalize audio."""

from __future__ import annotations

import os
import subprocess
import tempfile
from pathlib import Path
from typing import Any


def extract_audio(contract: dict[str, Any]) -> dict[str, Any]:
    """Extract and normalize audio from a media file using FFmpeg.

    Args:
        contract: Media processing contract dict with storage info and output_storage.

    Returns:
        Dict with 'status' = 'success' and 'extraction' data on success,
        or 'status' = 'error' with error details on failure.
    """
    # Validate that the contract includes probe data (requires PROBED state from Issue #47)
    probe_data = contract.get("probe_data")
    if not probe_data or not isinstance(probe_data, dict) or not probe_data.get("audio_codec"):
        return {
            "status": "error",
            "error": "Contract missing probe data; asset must be in PROBED state before extraction",
            "stderr": "",
        }

    storage = contract.get("storage", {})
    file_path = storage.get("key", "")

    if not file_path:
        return {
            "status": "error",
            "error": "No storage key provided",
            "stderr": "",
        }

    output_storage = contract.get("output_storage", {})
    output_key = output_storage.get("key", "")

    if not output_key:
        return {
            "status": "error",
            "error": "No output storage key provided",
            "stderr": "",
        }

    timeout = int(os.environ.get("EXTRACT_AUDIO_TIMEOUT_SECONDS", "120"))

    # Create output directory if it doesn't exist
    output_path = Path(output_key)
    output_path.parent.mkdir(parents=True, exist_ok=True)

    # Create temporary file for intermediate output
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
        tmp_path = tmp.name

    try:
        cmd = [
            "ffmpeg",
            "-y",
            "-i", file_path,
            "-vn",
            "-acodec", "pcm_s16le",
            "-ar", "16000",
            "-ac", "1",
            tmp_path,
        ]

        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=timeout,
        )
    except FileNotFoundError:
        _cleanup_file(tmp_path)
        return {
            "status": "error",
            "error": "ffmpeg not found in PATH",
            "stderr": "",
        }
    except subprocess.TimeoutExpired:
        _cleanup_file(tmp_path)
        return {
            "status": "error",
            "error": f"FFmpeg timed out after {timeout}s",
            "stderr": "",
        }

    if result.returncode != 0:
        _cleanup_file(tmp_path)
        return {
            "status": "error",
            "error": f"FFmpeg failed with exit code {result.returncode}",
            "stderr": result.stderr,
        }

    # Move temporary file to final output path
    try:
        os.replace(tmp_path, output_key)
    except OSError as e:
        _cleanup_file(tmp_path)
        return {
            "status": "error",
            "error": f"Failed to move output file: {e}",
            "stderr": "",
        }

    # Get output file metadata
    try:
        output_size = os.path.getsize(output_key)
    except OSError:
        output_size = 0

    # Probe the output to get duration
    duration_ms = _probe_duration(output_key)

    return {
        "status": "success",
        "extraction": {
            "output_path": output_key,
            "output_size_bytes": output_size,
            "duration_ms": duration_ms,
            "sample_rate": 16000,
            "channels": 1,
            "codec": "pcm_s16le",
            "format": "wav",
        },
    }


def _cleanup_file(path: str) -> None:
    """Remove a file if it exists."""
    try:
        os.unlink(path)
    except OSError:
        pass


def _probe_duration(file_path: str) -> int:
    """Probe the duration of a media file in milliseconds."""
    try:
        cmd = [
            "ffprobe",
            "-v", "quiet",
            "-print_format", "json",
            "-show_format",
            file_path,
        ]
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=30,
        )
        if result.returncode == 0:
            import json
            data = json.loads(result.stdout)
            duration_s = float(data.get("format", {}).get("duration", "0"))
            return int(duration_s * 1000)
    except (subprocess.TimeoutExpired, FileNotFoundError, json.JSONDecodeError, ValueError):
        pass
    return 0
