"""Real FFmpeg integration test for render_clip action (SINGULAR, corrected design).

This test requires:
- FFmpeg 6.x+ installed
- Fixture video at tests/fixtures/render_source.mp4
  (1920x1080, 30 seconds, H.264/AAC, horizontal content suitable for center-crop)
"""

from __future__ import annotations

import json
import tempfile
import shutil
from pathlib import Path

import pytest

from aiclip_worker.rendering import (
    FFmpegVerticalClipRenderer,
    RenderConfiguration,
    RenderInput,
    RenderCandidate,
    SourceMediaInfo,
)
from aiclip_worker.actions.render_clip import run_cli
from aiclip_worker.contracts import validate_contract


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


FIXTURE_PATH = Path(__file__).parent / "fixtures" / "render_source.mp4"


def valid_render_clip_contract(candidate_index: int = 0) -> dict:
    """A valid render_clip contract (SINGULAR action)."""
    return {
        "version": "1.0.0",
        "action": "render_clip",
        "media": {"duration_ms": 30000},
        "recommendation": {
            "candidates": [
                {
                    "index": 0,
                    "start_ms": 0,
                    "end_ms": 10000,
                    "semantic_rank": 1,
                    "semantic_score": 0.95,
                },
                {
                    "index": 1,
                    "start_ms": 10000,
                    "end_ms": 20000,
                    "semantic_rank": 2,
                    "semantic_score": 0.75,
                },
            ],
            # NO candidate_index inside recommendation
        },
        "candidate_index": candidate_index,
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
            "key": str(FIXTURE_PATH),
            "width": 1920,
            "height": 1080,
            "video_codec": "h264",
            "audio_codec": "aac",
        },
        "output_key": f"projects/1/renders/1/{candidate_index}_20260101T000000Z.mp4",
        # Legacy media_asset_id at root preserved for backward compatibility
        "media_asset_id": 1,
    }


# ---------------------------------------------------------------------------
# Test fixtures and setup
# ---------------------------------------------------------------------------


def has_ffmpeg() -> bool:
    """Check if FFmpeg is available."""
    try:
        import subprocess
        result = subprocess.run(["ffmpeg", "-version"], capture_output=True, timeout=5)
        return result.returncode == 0
    except Exception:
        return False


def has_fixture() -> bool:
    """Check if test fixture exists."""
    return FIXTURE_PATH.exists()


# Skip all tests in this module if requirements not met
pytestmark = [
    pytest.mark.skipif(not has_ffmpeg(), reason="FFmpeg not available"),
    pytest.mark.skipif(not has_fixture(), reason="FFmpeg fixture video not available"),
]


# ---------------------------------------------------------------------------
# TC-E2E-01 through TC-E2E-10 (Corrected for SINGULAR action)
# ---------------------------------------------------------------------------


def test_real_ffmpeg_on_fixture_horizontal_video():
    """TC-E2E-01: Real FFmpeg on fixture horizontal video (1920x1080, 30s, audio) -> Output 1080x1920 MP4."""
    contract = valid_render_clip_contract(candidate_index=0)
    config = RenderConfiguration.from_dict(contract["configuration"])

    # Validate contract first
    is_valid, reason = validate_contract(contract)
    assert is_valid, f"Contract validation failed: {reason}"

    # Create renderer with default timeout
    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)

    # Create render input (NOTE: no media_asset_id, recommendation_id in RenderInput per corrected design)
    input_data = RenderInput(
        duration_ms=contract["media"]["duration_ms"],
        recommendation=contract["recommendation"],
        candidate_index=contract["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract["source_media"]["disk"],
            key=contract["source_media"]["key"],
            width=contract["source_media"]["width"],
            height=contract["source_media"]["height"],
            video_codec=contract["source_media"]["video_codec"],
            audio_codec=contract["source_media"]["audio_codec"],
        ),
        output_key=contract["output_key"],
    )

    # Run render
    result = renderer.render(input_data, config)

    # Verify result structure
    assert result.algorithm == "vertical"  # CORRECTED: "vertical" not "ffmpeg_vertical_baseline"
    assert result.algorithm_version == "vertical_v1"  # CORRECTED: "vertical_v1" not "1.0.0"
    assert result.parameters is not None
    assert len(result.clips) == 1

    clip = result.clips[0]
    assert clip.candidate_index == 0
    assert clip.semantic_rank == 1
    assert clip.semantic_score == 0.95
    assert clip.start_ms == 0
    assert clip.end_ms == 10000
    assert clip.duration_ms == 10000

    # Verify output metadata
    output = clip.output
    assert output["disk"] == "media"
    assert output["key"] == contract["output_key"]  # Worker uses precomputed output_key
    assert output["size_bytes"] > 0
    assert output["width"] == 1080
    assert output["height"] == 1920
    assert output["video_codec"] in ("libx264", "h264")
    assert output["audio_codec"] == "aac"
    assert output["video_bitrate_kbps"] > 0
    assert output["audio_bitrate_kbps"] > 0


def test_output_duration_matches_candidate_bounds_pm_50ms():
    """TC-E2E-02: Output duration matches candidate bounds (±50ms). CORRECTED: NOT 5%."""
    contract = valid_render_clip_contract(candidate_index=0)
    config = RenderConfiguration.from_dict(contract["configuration"])

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)

    input_data = RenderInput(
        duration_ms=contract["media"]["duration_ms"],
        recommendation=contract["recommendation"],
        candidate_index=contract["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract["source_media"]["disk"],
            key=contract["source_media"]["key"],
            width=contract["source_media"]["width"],
            height=contract["source_media"]["height"],
            video_codec=contract["source_media"]["video_codec"],
            audio_codec=contract["source_media"]["audio_codec"],
        ),
        output_key=contract["output_key"],
    )

    result = renderer.render(input_data, config)

    clip = result.clips[0]
    expected_duration = 10000  # 10 seconds
    tolerance = 50  # CORRECTED: ±50ms not 5%

    # Output duration within ±50ms of expected
    actual_duration = clip.output["duration_ms"]
    assert abs(actual_duration - expected_duration) <= tolerance, \
        f"Duration {actual_duration}ms not within ±50ms of {expected_duration}ms"


def test_output_has_correct_codecs():
    """TC-E2E-03: Output has video codec libx264, audio codec aac."""
    contract = valid_render_clip_contract(candidate_index=0)
    config = RenderConfiguration.from_dict(contract["configuration"])

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)

    input_data = RenderInput(
        duration_ms=contract["media"]["duration_ms"],
        recommendation=contract["recommendation"],
        candidate_index=contract["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract["source_media"]["disk"],
            key=contract["source_media"]["key"],
            width=contract["source_media"]["width"],
            height=contract["source_media"]["height"],
            video_codec=contract["source_media"]["video_codec"],
            audio_codec=contract["source_media"]["audio_codec"],
        ),
        output_key=contract["output_key"],
    )

    result = renderer.render(input_data, config)

    clip = result.clips[0]
    # Video codec should be libx264 (or h264 if FFmpeg uses that name)
    assert clip.output["video_codec"] in ("libx264", "h264")
    assert clip.output["audio_codec"] == "aac"


def test_output_bitrate_matches_configuration():
    """TC-E2E-04: Output bitrate matches configuration (±10%)."""
    contract = valid_render_clip_contract(candidate_index=0)
    config = RenderConfiguration.from_dict(contract["configuration"])

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)

    input_data = RenderInput(
        duration_ms=contract["media"]["duration_ms"],
        recommendation=contract["recommendation"],
        candidate_index=contract["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract["source_media"]["disk"],
            key=contract["source_media"]["key"],
            width=contract["source_media"]["width"],
            height=contract["source_media"]["height"],
            video_codec=contract["source_media"]["video_codec"],
            audio_codec=contract["source_media"]["audio_codec"],
        ),
        output_key=contract["output_key"],
    )

    result = renderer.render(input_data, config)

    clip = result.clips[0]
    expected_video_bitrate = config.video_bitrate_kbps
    expected_audio_bitrate = config.audio_bitrate_kbps

    # Video bitrate within 10%
    video_tolerance = expected_video_bitrate * 0.1
    actual_video_bitrate = clip.output["video_bitrate_kbps"]
    assert abs(actual_video_bitrate - expected_video_bitrate) <= video_tolerance, \
        f"Video bitrate {actual_video_bitrate}kbps not within 10% of {expected_video_bitrate}kbps"

    # Audio bitrate within 10%
    audio_tolerance = expected_audio_bitrate * 0.1
    actual_audio_bitrate = clip.output["audio_bitrate_kbps"]
    assert abs(actual_audio_bitrate - expected_audio_bitrate) <= audio_tolerance, \
        f"Audio bitrate {actual_audio_bitrate}kbps not within 10% of {expected_audio_bitrate}kbps"


def test_output_file_size_positive():
    """TC-E2E-05: Output file size > 0."""
    contract = valid_render_clip_contract(candidate_index=0)
    config = RenderConfiguration.from_dict(contract["configuration"])

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)

    input_data = RenderInput(
        duration_ms=contract["media"]["duration_ms"],
        recommendation=contract["recommendation"],
        candidate_index=contract["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract["source_media"]["disk"],
            key=contract["source_media"]["key"],
            width=contract["source_media"]["width"],
            height=contract["source_media"]["height"],
            video_codec=contract["source_media"]["video_codec"],
            audio_codec=contract["source_media"]["audio_codec"],
        ),
        output_key=contract["output_key"],
    )

    result = renderer.render(input_data, config)

    clip = result.clips[0]
    assert clip.output["size_bytes"] > 0


def test_filter_graph_recorded_in_parameters():
    """TC-E2E-06: Filter graph recorded in result parameters."""
    contract = valid_render_clip_contract(candidate_index=0)
    config = RenderConfiguration.from_dict(contract["configuration"])

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)

    input_data = RenderInput(
        duration_ms=contract["media"]["duration_ms"],
        recommendation=contract["recommendation"],
        candidate_index=contract["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract["source_media"]["disk"],
            key=contract["source_media"]["key"],
            width=contract["source_media"]["width"],
            height=contract["source_media"]["height"],
            video_codec=contract["source_media"]["video_codec"],
            audio_codec=contract["source_media"]["audio_codec"],
        ),
        output_key=contract["output_key"],
    )

    result = renderer.render(input_data, config)

    filter_graph = result.parameters.filter_graph
    assert isinstance(filter_graph, str)
    assert filter_graph != ""
    assert "crop=" in filter_graph
    assert "scale=" in filter_graph
    assert "pad=" in filter_graph
    assert "fps=" in filter_graph


def test_ffmpeg_version_recorded():
    """TC-E2E-07: FFmpeg version recorded."""
    contract = valid_render_clip_contract(candidate_index=0)
    config = RenderConfiguration.from_dict(contract["configuration"])

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)

    input_data = RenderInput(
        duration_ms=contract["media"]["duration_ms"],
        recommendation=contract["recommendation"],
        candidate_index=contract["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract["source_media"]["disk"],
            key=contract["source_media"]["key"],
            width=contract["source_media"]["width"],
            height=contract["source_media"]["height"],
            video_codec=contract["source_media"]["video_codec"],
            audio_codec=contract["source_media"]["audio_codec"],
        ),
        output_key=contract["output_key"],
    )

    result = renderer.render(input_data, config)

    ffmpeg_version = result.parameters.ffmpeg_version
    assert isinstance(ffmpeg_version, str)
    assert ffmpeg_version != ""
    assert "ffmpeg version" in ffmpeg_version.lower()


def test_source_media_metadata_in_parameters():
    """TC-E2E-08: Source media metadata in parameters."""
    contract = valid_render_clip_contract(candidate_index=0)
    config = RenderConfiguration.from_dict(contract["configuration"])

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)

    input_data = RenderInput(
        duration_ms=contract["media"]["duration_ms"],
        recommendation=contract["recommendation"],
        candidate_index=contract["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract["source_media"]["disk"],
            key=contract["source_media"]["key"],
            width=contract["source_media"]["width"],
            height=contract["source_media"]["height"],
            video_codec=contract["source_media"]["video_codec"],
            audio_codec=contract["source_media"]["audio_codec"],
        ),
        output_key=contract["output_key"],
    )

    result = renderer.render(input_data, config)

    source_media = result.parameters.source_media
    assert source_media["disk"] == "media"
    assert source_media["key"] == str(FIXTURE_PATH)
    assert source_media["duration_ms"] == 30000
    assert source_media["width"] == 1920
    assert source_media["height"] == 1080
    assert source_media["video_codec"] == "h264"
    assert source_media["audio_codec"] == "aac"


def test_limits_object_present():
    """TC-E2E-09: Limits object present with correct values."""
    contract = valid_render_clip_contract(candidate_index=0)
    config = RenderConfiguration.from_dict(contract["configuration"])

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)

    input_data = RenderInput(
        duration_ms=contract["media"]["duration_ms"],
        recommendation=contract["recommendation"],
        candidate_index=contract["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract["source_media"]["disk"],
            key=contract["source_media"]["key"],
            width=contract["source_media"]["width"],
            height=contract["source_media"]["height"],
            video_codec=contract["source_media"]["video_codec"],
            audio_codec=contract["source_media"]["audio_codec"],
        ),
        output_key=contract["output_key"],
    )

    result = renderer.render(input_data, config)

    limits = result.parameters.limits
    assert limits["max_recommendations"] == 1000
    assert limits["max_input_bytes"] == 8388608
    assert limits["max_duration_ms"] == 2147483647


def test_different_candidate_index_different_output_key():
    """TC-E2E-10: Different candidate_index produces different output key."""
    # Test candidate 0
    contract_0 = valid_render_clip_contract(candidate_index=0)
    config = RenderConfiguration.from_dict(contract_0["configuration"])

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)

    input_data_0 = RenderInput(
        duration_ms=contract_0["media"]["duration_ms"],
        recommendation=contract_0["recommendation"],
        candidate_index=contract_0["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract_0["source_media"]["disk"],
            key=contract_0["source_media"]["key"],
            width=contract_0["source_media"]["width"],
            height=contract_0["source_media"]["height"],
            video_codec=contract_0["source_media"]["video_codec"],
            audio_codec=contract_0["source_media"]["audio_codec"],
        ),
        output_key=contract_0["output_key"],
    )

    result_0 = renderer.render(input_data_0, config)

    # Test candidate 1
    contract_1 = valid_render_clip_contract(candidate_index=1)
    input_data_1 = RenderInput(
        duration_ms=contract_1["media"]["duration_ms"],
        recommendation=contract_1["recommendation"],
        candidate_index=contract_1["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract_1["source_media"]["disk"],
            key=contract_1["source_media"]["key"],
            width=contract_1["source_media"]["width"],
            height=contract_1["source_media"]["height"],
            video_codec=contract_1["source_media"]["video_codec"],
            audio_codec=contract_1["source_media"]["audio_codec"],
        ),
        output_key=contract_1["output_key"],
    )

    result_1 = renderer.render(input_data_1, config)

    # Output keys should differ by candidate_index (precomputed by Laravel)
    key_0 = result_0.clips[0].output["key"]
    key_1 = result_1.clips[0].output["key"]

    assert key_0 != key_1
    assert "0_" in key_0
    assert "1_" in key_1


# ---------------------------------------------------------------------------
# CLI integration test
# ---------------------------------------------------------------------------


def test_cli_render_clip_integration():
    """Full CLI integration with real FFmpeg for SINGULAR render-clip action."""
    contract = valid_render_clip_contract(candidate_index=0)

    # Validate contract
    is_valid, reason = validate_contract(contract)
    assert is_valid, f"Contract validation failed: {reason}"

    # Run CLI
    import sys
    from io import BytesIO

    stdin_data = json.dumps(contract).encode()

    with patch("sys.stdin.buffer", new_callable=lambda: BytesIO(stdin_data)):
        with patch("sys.stdin.isatty", return_value=False):
            with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
                exit_code = run_cli([])

    assert exit_code == 0, f"CLI exited with code {exit_code}"

    output = mock_stdout.getvalue().decode()
    result = json.loads(output)

    assert result["status"] == "success"
    assert "render" in result
    assert result["render"]["algorithm"] == "vertical"  # CORRECTED
    assert result["render"]["algorithm_version"] == "vertical_v1"  # CORRECTED
    assert len(result["render"]["clips"]) == 1

    clip = result["render"]["clips"][0]
    assert clip["candidate_index"] == 0
    assert clip["output"]["width"] == 1080
    assert clip["output"]["height"] == 1920
    assert clip["output"]["size_bytes"] > 0


# ---------------------------------------------------------------------------
# Test with different candidate indices
# ---------------------------------------------------------------------------


@pytest.mark.parametrize("candidate_index", [0, 1])
def test_render_different_candidates(candidate_index: int):
    """Test rendering different candidate indices."""
    contract = valid_render_clip_contract(candidate_index=candidate_index)
    config = RenderConfiguration.from_dict(contract["configuration"])

    is_valid, reason = validate_contract(contract)
    assert is_valid, f"Contract validation failed: {reason}"

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)

    input_data = RenderInput(
        duration_ms=contract["media"]["duration_ms"],
        recommendation=contract["recommendation"],
        candidate_index=contract["candidate_index"],
        source_media=SourceMediaInfo(
            disk=contract["source_media"]["disk"],
            key=contract["source_media"]["key"],
            width=contract["source_media"]["width"],
            height=contract["source_media"]["height"],
            video_codec=contract["source_media"]["video_codec"],
            audio_codec=contract["source_media"]["audio_codec"],
        ),
        output_key=contract["output_key"],
    )

    result = renderer.render(input_data, config)

    assert result.clips[0].candidate_index == candidate_index
    assert result.clips[0].output["width"] == 1080
    assert result.clips[0].output["height"] == 1920
    assert result.clips[0].output["size_bytes"] > 0