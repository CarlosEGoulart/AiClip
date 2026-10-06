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


def test_render_clip_cli_with_caption_burn_in_binds_result_to_request(tmp_path):
    """Real FFmpeg caption burn-in through run_cli: digest binding + configuration echo.

    Covers the worker side of TC-E2E-CAP-04 (drawtext in filter_graph) and
    TC-E2E-CAP-07 (parameters.configuration.captions matches the request).
    """
    import hashlib
    from io import BytesIO, StringIO
    from unittest.mock import MagicMock, patch

    from aiclip_worker.actions.render_clip import run_cli
    from aiclip_worker.contracts import validate_contract

    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    contract["configuration"]["captions"] = {
        "enabled": True,
        "font_file": "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
        "font_size": 72,
        "font_color": "ffffff",
        "outline_color": "000000",
        "outline_width": 3,
        "background_color": "000000",
        "background_opacity": 0.5,
        "box_padding": 10,
        "margin_bottom": 100,
        "max_chars_per_line": 32,
    }
    start_ms = contract["candidate"]["start_ms"]
    end_ms = contract["candidate"]["end_ms"]
    contract["captions"] = {
        "enabled": True,
        "segments": [
            {
                "start_ms": start_ms + (end_ms - start_ms) // 4,
                "end_ms": start_ms + 3 * (end_ms - start_ms) // 4,
                "text": "Burned caption",
            }
        ],
    }

    payload = json.dumps(contract).encode()
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    def stdin_for(data: bytes) -> MagicMock:
        stream = MagicMock()
        stream.buffer = BytesIO(data)
        stream.isatty.return_value = False
        return stream

    with patch("sys.stdin", new=stdin_for(payload)):
        with patch("sys.stdout", new_callable=StringIO) as output:
            exit_code = run_cli([])

    assert exit_code == 0, output.getvalue()
    result = json.loads(output.getvalue())
    assert result["status"] == "success", output.getvalue()

    parameters = result["render"]["parameters"]
    assert parameters["request_sha256"] == hashlib.sha256(payload).hexdigest()
    assert parameters["configuration"] == contract["configuration"]
    assert parameters["configuration"]["captions"] == contract["configuration"]["captions"]
    assert "drawtext" in parameters["filter_graph"]

    clip = result["render"]["clips"][0]
    rendered_path = Path(clip["output"]["key"])
    assert rendered_path.exists()
    assert rendered_path.stat().st_size > 0


# ---------------------------------------------------------------------------
# M6.2 Caption burn-in integration tests (TC-E2E-CAP-01 through TC-E2E-CAP-08)
# ---------------------------------------------------------------------------

CAPTION_STYLING = {
    "enabled": True,
    "font_file": "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    "font_size": 72,
    "font_color": "ffffff",
    "outline_color": "000000",
    "outline_width": 3,
    "background_color": "000000",
    "background_opacity": 0.5,
    "box_padding": 10,
    "margin_bottom": 100,
    "max_chars_per_line": 32,
}


def caption_segments(candidate_start_ms: int, candidate_end_ms: int) -> list[dict]:
    """Create caption segments within the candidate bounds."""
    start_ms = candidate_start_ms + (candidate_end_ms - candidate_start_ms) // 4
    end_ms = candidate_start_ms + 3 * (candidate_end_ms - candidate_start_ms) // 4
    return [
        {
            "start_ms": start_ms,
            "end_ms": end_ms,
            "text": "Burned caption",
        }
    ]


def test_e2e_cap_01_real_ffmpeg_caption_burn_in_function(tmp_path):
    """TC-E2E-CAP-01: Real FFmpeg on fixture with caption segments via function call."""
    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    contract["configuration"]["captions"] = CAPTION_STYLING
    candidate_start = contract["candidate"]["start_ms"]
    candidate_end = contract["candidate"]["end_ms"]
    contract["captions"] = {
        "enabled": True,
        "segments": caption_segments(candidate_start, candidate_end),
    }

    from aiclip_worker.contracts import validate_contract
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    result = render_clip(contract)

    assert result.get("status") == "success"
    render_result = result["render"]
    assert render_result.get("algorithm") == "ffmpeg_vertical_baseline"
    clips = render_result["clips"]
    assert len(clips) == 1
    clip = clips[0]
    assert clip["output"]["width"] == 1080
    assert clip["output"]["height"] == 1920
    assert clip["output"]["size_bytes"] > 0


def test_e2e_cap_02_output_duration_matches_candidate_bounds_with_captions(tmp_path):
    """TC-E2E-CAP-02: Output duration matches candidate bounds (±50ms) with captions."""
    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    contract["configuration"]["captions"] = CAPTION_STYLING
    candidate_start = contract["candidate"]["start_ms"]
    candidate_end = contract["candidate"]["end_ms"]
    contract["captions"] = {
        "enabled": True,
        "segments": caption_segments(candidate_start, candidate_end),
    }

    from aiclip_worker.contracts import validate_contract
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    result = render_clip(contract)

    assert result.get("status") == "success"
    clip = result["render"]["clips"][0]
    expected_duration_ms = candidate_end - candidate_start
    actual_duration_ms = clip["duration_ms"]
    assert abs(actual_duration_ms - expected_duration_ms) <= 50


def test_e2e_cap_03_output_has_correct_codecs_with_captions(tmp_path):
    """TC-E2E-CAP-03: Output has video codec libx264, audio codec aac with captions."""
    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    contract["configuration"]["captions"] = CAPTION_STYLING
    candidate_start = contract["candidate"]["start_ms"]
    candidate_end = contract["candidate"]["end_ms"]
    contract["captions"] = {
        "enabled": True,
        "segments": caption_segments(candidate_start, candidate_end),
    }

    from aiclip_worker.contracts import validate_contract
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    result = render_clip(contract)

    assert result.get("status") == "success"
    clip = result["render"]["clips"][0]
    assert clip["output"]["video_codec"] == "libx264"
    assert clip["output"]["audio_codec"] == "aac"


def test_e2e_cap_04_filter_graph_contains_drawtext(tmp_path):
    """TC-E2E-CAP-04: Filter graph in result parameters contains drawtext."""
    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    contract["configuration"]["captions"] = CAPTION_STYLING
    candidate_start = contract["candidate"]["start_ms"]
    candidate_end = contract["candidate"]["end_ms"]
    contract["captions"] = {
        "enabled": True,
        "segments": caption_segments(candidate_start, candidate_end),
    }

    from aiclip_worker.contracts import validate_contract
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    result = render_clip(contract)

    assert result.get("status") == "success"
    parameters = result["render"]["parameters"]
    assert "drawtext" in parameters["filter_graph"]


def test_e2e_cap_05_ffmpeg_version_recorded(tmp_path):
    """TC-E2E-CAP-05: FFmpeg version recorded."""
    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    contract["configuration"]["captions"] = CAPTION_STYLING
    candidate_start = contract["candidate"]["start_ms"]
    candidate_end = contract["candidate"]["end_ms"]
    contract["captions"] = {
        "enabled": True,
        "segments": caption_segments(candidate_start, candidate_end),
    }

    from aiclip_worker.contracts import validate_contract
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    result = render_clip(contract)

    assert result.get("status") == "success"
    parameters = result["render"]["parameters"]
    assert "ffmpeg_version" in parameters
    assert parameters["ffmpeg_version"] != ""


def test_e2e_cap_06_source_media_metadata_in_parameters(tmp_path):
    """TC-E2E-CAP-06: Source media metadata in parameters."""
    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    contract["configuration"]["captions"] = CAPTION_STYLING
    candidate_start = contract["candidate"]["start_ms"]
    candidate_end = contract["candidate"]["end_ms"]
    contract["captions"] = {
        "enabled": True,
        "segments": caption_segments(candidate_start, candidate_end),
    }

    from aiclip_worker.contracts import validate_contract
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    result = render_clip(contract)

    assert result.get("status") == "success"
    parameters = result["render"]["parameters"]
    assert "source_media" in parameters
    source_media = parameters["source_media"]
    assert source_media["disk"] == "media"
    assert source_media["width"] == contract["source_media"]["width"]
    assert source_media["height"] == contract["source_media"]["height"]
    assert source_media["video_codec"] == contract["source_media"]["video_codec"]
    assert source_media["audio_codec"] == contract["source_media"]["audio_codec"]


def test_e2e_cap_07_caption_styling_configuration_in_parameters(tmp_path):
    """TC-E2E-CAP-07: Caption styling configuration in parameters.configuration.captions."""
    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    contract["configuration"]["captions"] = CAPTION_STYLING
    candidate_start = contract["candidate"]["start_ms"]
    candidate_end = contract["candidate"]["end_ms"]
    contract["captions"] = {
        "enabled": True,
        "segments": caption_segments(candidate_start, candidate_end),
    }

    from aiclip_worker.contracts import validate_contract
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    result = render_clip(contract)

    assert result.get("status") == "success"
    parameters = result["render"]["parameters"]
    assert "configuration" in parameters
    assert parameters["configuration"]["captions"] == CAPTION_STYLING


def test_e2e_cap_08_without_transcript_output_matches_m6_1(tmp_path):
    """TC-E2E-CAP-08: Without transcript: output matches M6.1 (no drawtext)."""
    fixture_path = Path(__file__).parent / "fixtures" / "valid_sample.mp4"
    assert fixture_path.exists(), f"Fixture not found: {fixture_path}"
    output_key = tmp_path / "rendered.mp4"

    contract = valid_render_clip_contract(fixture_path, str(output_key))
    # No captions in configuration, no top-level captions object

    from aiclip_worker.contracts import validate_contract
    valid, reason = validate_contract(contract)
    assert valid, f"Contract validation failed: {reason}"

    result = render_clip(contract)

    assert result.get("status") == "success"
    parameters = result["render"]["parameters"]
    assert "drawtext" not in parameters["filter_graph"]
    # Configuration should not have captions key (or empty)
    assert "captions" not in parameters["configuration"] or parameters["configuration"]["captions"] == {}