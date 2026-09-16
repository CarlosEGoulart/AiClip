"""ProbesMedia action: invokes FFprobe to extract media metadata."""

from __future__ import annotations

import json
import os
import subprocess
from typing import Any


def probe_media(contract: dict[str, Any]) -> dict[str, Any]:
    """Probe a media file using FFprobe and return structured metadata.

    Args:
        contract: Media processing contract dict with storage info.

    Returns:
        Dict with 'status' = 'success' and 'probe' data on success,
        or 'status' = 'error' with error details on failure.
    """
    storage = contract.get("storage", {})
    file_path = storage.get("key", "")

    if not file_path:
        return {
            "status": "error",
            "error": "No storage key provided",
            "stderr": "",
        }

    timeout = int(os.environ.get("PROBE_TIMEOUT_SECONDS", "30"))

    cmd = [
        "ffprobe",
        "-v", "quiet",
        "-print_format", "json",
        "-show_format",
        "-show_streams",
        file_path,
    ]

    try:
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=timeout,
        )
    except FileNotFoundError:
        return {
            "status": "error",
            "error": "ffprobe not found in PATH",
            "stderr": "",
        }
    except subprocess.TimeoutExpired:
        return {
            "status": "error",
            "error": f"FFprobe timed out after {timeout}s",
            "stderr": "",
        }

    if result.returncode != 0:
        return {
            "status": "error",
            "error": f"FFprobe failed with exit code {result.returncode}",
            "stderr": result.stderr,
        }

    try:
        ffprobe_output = json.loads(result.stdout)
    except json.JSONDecodeError:
        return {
            "status": "error",
            "error": "Invalid JSON output from FFprobe",
            "stderr": result.stderr,
        }

    return _extract_probe_data(ffprobe_output)


def _extract_probe_data(ffprobe_output: dict[str, Any]) -> dict[str, Any]:
    """Extract structured probe data from FFprobe JSON output."""
    fmt = ffprobe_output.get("format", {})
    streams = ffprobe_output.get("streams", [])

    # Find video and audio streams
    video_stream: dict[str, Any] | None = None
    audio_stream: dict[str, Any] | None = None
    for stream in streams:
        codec_type = stream.get("codec_type", "")
        if codec_type == "video" and video_stream is None:
            video_stream = stream
        elif codec_type == "audio" and audio_stream is None:
            audio_stream = stream

    # Duration in milliseconds
    duration_s = float(fmt.get("duration", "0"))
    duration_ms = int(duration_s * 1000)

    # Video fields
    width = int(video_stream.get("width", 0)) if video_stream else None
    height = int(video_stream.get("height", 0)) if video_stream else None
    video_codec = video_stream.get("codec_name") if video_stream else None

    # FPS from r_frame_rate (e.g., "30/1" or "30000/1001")
    fps = None
    if video_stream:
        r_frame_rate = video_stream.get("r_frame_rate", "0/1")
        try:
            num, den = r_frame_rate.split("/")
            fps = round(int(num) / int(den), 2) if int(den) > 0 else 0.0
        except (ValueError, ZeroDivisionError):
            fps = 0.0

    # Audio fields
    audio_codec = audio_stream.get("codec_name") if audio_stream else None
    audio_channels = int(audio_stream.get("channels", 0)) if audio_stream else None
    audio_sample_rate = int(audio_stream.get("sample_rate", 0)) if audio_stream else None

    # Bitrate in kbps
    bitrate_bps = int(fmt.get("bit_rate", "0"))
    bitrate_kbps = bitrate_bps // 1000

    # Format and size
    format_name = fmt.get("format_name", "")
    size_bytes = int(fmt.get("size", "0"))

    probe = {
        "duration_ms": duration_ms,
        "width": width,
        "height": height,
        "video_codec": video_codec,
        "audio_codec": audio_codec,
        "bitrate_kbps": bitrate_kbps,
        "fps": fps,
        "audio_channels": audio_channels,
        "audio_sample_rate": audio_sample_rate,
        "format": format_name,
        "size_bytes": size_bytes,
    }

    return {
        "status": "success",
        "probe": probe,
    }
