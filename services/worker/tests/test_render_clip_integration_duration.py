"""Integration tests for the singular render_clip action focusing on duration accuracy."""

from __future__ import annotations

import json
import subprocess
import tempfile
from pathlib import Path

import pytest

from aiclip_worker.actions.render_clip import render_clip, run_cli
from aiclip_worker.contracts import validate_contract


def check_ffmpeg_available() -> bool:
    """Check if ffmpeg and ffprobe are available."""
    try:
        subprocess.run(["ffmpeg", "-version"], capture_output=True, timeout=5)
        subprocess.run(["ffprobe", "-version"], capture_output=True, timeout=5)
        return True
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return False


if not check_ffmpeg_available():
    pytest.skip("FFmpeg or ffprobe not available", allow_module_level=True)


def valid_render_clip_contract(fixture_path: Path, output_key: str | None = None) -> dict:
    """Create a valid render_clip contract using the given fixture."""
    # Probe the fixture to get source media info
    # We'll use the same probe logic as in the renderer, but for simplicity we can use a helper
    # Since we cannot import the renderer in the test (to avoid circular import in early stages),
    # we'll duplicate the probe logic here or use a shared function.
    # However, for the test we can use the fixture's known properties? Better to probe.
    
    # Let's use a simple probe with ffprobe to get duration and streams
    try:
        result = subprocess.run(
            [
                "ffprobe", "-v", "quiet", "-print_format", "json",
                "-show_streams", "-show_format", str(fixture_path)
            ],
            capture_output=True,
            text=True,
            timeout=30,
        )
        if result.returncode != 0:
            raise RuntimeError("ffprobe failed")
        probe_data = json.loads(result.stdout)
    except Exception as e:
        raise RuntimeError(f"Failed to probe fixture: {e}")
    
    # Extract video and audio stream info
    video_stream = None
    audio_stream = None
    for stream in probe_data.get("streams", []):
        if stream.get("codec_type") == "video" and video_stream is None:
            video_stream = stream
        elif stream.get("codec_type") == "audio" and audio_stream is None:
            audio_stream = stream
    
    width = video_stream.get("width", 0) if video_stream else 0
    height = video_stream.get("height", 0) if video_stream else 0
    video_codec = video_stream.get("codec_name", "") if video_stream else ""
    audio_codec = audio_stream.get("codec_name", "") if audio_stream else None
    
    # Get duration from format
    duration_ms = int(float(probe_data.get("format", {}).get("duration", 0)) * 1000)
    
    # Choose a subclip that is safely within the fixture
    # We'll use a fixed start of 100ms and target duration of 800ms, but not exceed the fixture
    start_ms = 100
    target_duration_ms = 800
    end_ms = min(start_ms + target_duration_ms, duration_ms)
    # Ensure we have at least 100ms duration
    if end_ms - start_ms < 100:
        # If fixture too short, just use first half
        start_ms = 0
        end_ms = duration_ms // 2
    
    if output_key is None:
        output_key = "projects/1/assets/1/renders/vertical/550e8400-e29b-41d4-a716-446655440000.mp4"
    
    return {
        "version": "1.0.0",
        "action": "render_clip",
        "media": {"duration_ms": duration_ms},
        "candidate_index": 0,
        "candidate": {"start_ms": start_ms, "end_ms": end_ms},
        "configuration": {
            "target_width": 1080,
            "target_height": 1920,
            "target_fps": 30,
            "video_codec": "libx264",
            "video_bitrate_kbps": 5000,
            "audio_codec": "aac",
            "audio_bitrate_kbps": 128,
        },
        "source_media": {
            "disk": "media",
            "key": str(fixture_path),  # In test we use absolute path
            "width": width,
            "height": height,
            "video_codec": video_codec,
            "audio_codec": audio_codec,
        },
        "output_storage": {
            "disk": "media",
            "key": output_key,
            "mime_type": "video/mp4",
        },
    }


@pytest.mark.skipif(not check_ffmpeg_available(), reason="FFmpeg or ffprobe not available (BLOCKED_ENV)")
def test_render_clip_duration_accuracy_via_function(tmp_path):
    """Test that the rendered clip duration is within ±50ms of expected via direct function call."""
    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    
    # Validate contract
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    # Call the render_clip function (execution seam)
    result = render_clip(contract)
    
    # Check that the result is a success
    assert result.get("status") == "success", f"Expected success, got: {result}"
    assert "render" in result
    render_result = result["render"]
    assert render_result.get("algorithm") == "ffmpeg_vertical_baseline"
    assert "clips" in render_result
    clips = render_result["clips"]
    assert len(clips) == 1
    clip = clips[0]
    assert clip["candidate_index"] == 0
    assert clip["start_ms"] == contract["candidate"]["start_ms"]
    assert clip["end_ms"] == contract["candidate"]["end_ms"]
    expected_duration_ms = contract["candidate"]["end_ms"] - contract["candidate"]["start_ms"]
    actual_duration_ms = clip["duration_ms"]
    # Check that the actual duration is within ±50ms of expected
    assert abs(actual_duration_ms - expected_duration_ms) <= 50, \
        f"Duration mismatch: expected {expected_duration_ms}ms, got {actual_duration_ms}ms"
    assert "output" in clip
    output = clip["output"]
    assert output["disk"] == "media"
    assert output["key"] == str(output_key)
    assert output["mime_type"] == "video/mp4"
    assert output["width"] == 1080
    assert output["height"] == 1920
    assert output["video_codec"] == "libx264"
    assert output["audio_codec"] == "aac"
    assert output["size_bytes"] > 0
    assert output["duration_ms"] > 0
    # Output duration should also be within ±50ms (it's the same as clip.duration_ms)
    assert abs(output["duration_ms"] - expected_duration_ms) <= 50


@pytest.mark.skipif(not check_ffmpeg_available(), reason="FFmpeg or ffprobe not available (BLOCKED_ENV)")
def test_render_clip_duration_accuracy_via_cli(tmp_path):
    """Test that the rendered clip duration is within ±50ms of expected via CLI."""
    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    
    # Validate contract
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    # Prepare stdin with the contract JSON
    contract_json = json.dumps(contract)
    
    def stdin_for(payload: bytes):
        import io
        from unittest.mock import MagicMock
        stream = MagicMock()
        stream.buffer = io.BytesIO(payload)
        stream.isatty.return_value = False
        return stream

    # We need to patch sys.stdin and sys.stdout
    import sys
    from io import StringIO
    from unittest.mock import patch

    with patch("sys.stdin", new=stdin_for(contract_json.encode())):
        with patch("sys.stdout", new_callable=StringIO) as output:
            exit_code = run_cli([])
    
    # Check exit code
    assert exit_code == 0, f"Expected exit code 0, got: {exit_code}"
    
    # Parse output
    result = json.loads(output.getvalue())
    assert result.get("status") == "success", f"Expected success, got: {result}"
    assert "render" in result
    render_result = result["render"]
    assert render_result.get("algorithm") == "ffmpeg_vertical_baseline"
    assert "clips" in render_result
    clips = render_result["clips"]
    assert len(clips) == 1
    clip = clips[0]
    assert clip["candidate_index"] == 0
    assert clip["start_ms"] == contract["candidate"]["start_ms"]
    assert clip["end_ms"] == contract["candidate"]["end_ms"]
    expected_duration_ms = contract["candidate"]["end_ms"] - contract["candidate"]["start_ms"]
    actual_duration_ms = clip["duration_ms"]
    # Check that the actual duration is within ±50ms of expected
    assert abs(actual_duration_ms - expected_duration_ms) <= 50, \
        f"Duration mismatch: expected {expected_duration_ms}ms, got {actual_duration_ms}ms"
    assert "output" in clip
    output = clip["output"]
    assert output["disk"] == "media"
    assert output["key"] == str(output_key)
    assert output["mime_type"] == "video/mp4"
    assert output["width"] == 1080
    assert output["height"] == 1920
    assert output["video_codec"] == "libx264"
    assert output["audio_codec"] == "aac"
    assert output["size_bytes"] > 0
    assert output["duration_ms"] > 0
    # Output duration should also be within ±50ms (it's the same as clip.duration_ms)
    assert abs(output["duration_ms"] - expected_duration_ms) <= 50