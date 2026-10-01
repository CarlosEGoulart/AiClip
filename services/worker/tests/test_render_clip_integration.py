"""Integration tests for the singular render_clip action with real FFmpeg execution."""

from __future__ import annotations

import json
import tempfile
from pathlib import Path

import pytest

from aiclip_worker.actions.render_clip import render_clip
from aiclip_worker.rendering import FFmpegVerticalClipRenderer, RenderConfiguration, SourceMediaInfo


def valid_render_clip_contract(fixture_path: Path, output_key: str | None = None) -> dict:
    """Create a valid render_clip contract using the given fixture."""
    # Probe the fixture to get source media info
    renderer = FFmpegVerticalClipRenderer()
    probe_data = renderer._probe_source_media(str(fixture_path))
    
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
    
    if output_key is None:
        output_key = "projects/1/assets/1/renders/vertical/550e8400-e29b-41d4-a716-446655440000.mp4"
    
    return {
        "version": "1.0.0",
        "action": "render_clip",
        "media": {"duration_ms": duration_ms},
        "candidate_index": 0,
        "candidate": {"start_ms": 0, "end_ms": min(5000, duration_ms)},  # first 5 seconds or less
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
            "key": str(fixture_path),  # In real usage, this would be a storage key, but for test we use absolute path
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


def test_render_clip_function_with_real_ffmpeg(tmp_path):
    """Test the render_clip function with a real fixture and FFmpeg."""
    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    
    # Validate contract
    from aiclip_worker.contracts import validate_contract
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
    assert clip["start_ms"] == 0
    assert clip["end_ms"] == min(5000, contract["media"]["duration_ms"])
    assert clip["duration_ms"] > 0
    assert "output" in clip
    output = clip["output"]
    assert output["disk"] == "media"
    assert output["key"] == str(output_key)
    assert output["mime_type"] == "video/mp4"  # from output_storage mime_type
    assert output["width"] == 1080
    assert output["height"] == 1920
    assert output["video_codec"] == "libx264"
    assert output["audio_codec"] == "aac"
    assert output["size_bytes"] > 0
    assert output["duration_ms"] > 0


def test_render_clip_cli_with_real_ffmpeg(tmp_path):
    """Test the render_clip CLI with a real fixture and FFmpeg."""
    from aiclip_worker.actions.render_clip import run_cli
    from io import BytesIO, StringIO
    from unittest.mock import MagicMock, patch
    import sys

    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    
    # Validate contract
    from aiclip_worker.contracts import validate_contract
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    # Prepare stdin with the contract JSON
    contract_json = json.dumps(contract)
    
    def stdin_for(payload: bytes) -> MagicMock:
        stream = MagicMock()
        stream.buffer = BytesIO(payload)
        stream.isatty.return_value = False
        return stream

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
    assert clip["start_ms"] == 0
    assert clip["end_ms"] == min(5000, contract["media"]["duration_ms"])
    assert clip["duration_ms"] > 0
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