"""Configuration validation tests for render_clips action."""

from __future__ import annotations

import pytest

from aiclip_worker.rendering import RenderConfiguration, RenderFailed, InvalidCandidateIndex
from aiclip_worker.contracts import validate_contract


# ---------------------------------------------------------------------------
# Configuration validation tests (TC-WCF-01 through TC-WCF-05)
# ---------------------------------------------------------------------------


def test_configuration_defaults_match_spec():
    """All defaults applied when configuration partial match spec table."""
    config = RenderConfiguration()

    assert config.target_width == 1080
    assert config.target_height == 1920
    assert config.target_fps == 30
    assert config.video_codec == "libx264"
    assert config.video_bitrate_kbps == 5000
    assert config.audio_codec == "aac"
    assert config.audio_bitrate_kbps == 128


def test_explicit_valid_configuration_accepted():
    """Explicit valid configuration accepted."""
    config = RenderConfiguration(
        target_width=720,
        target_height=1280,
        target_fps=60,
        video_codec="libx265",
        video_bitrate_kbps=8000,
        audio_codec="libfdk_aac",
        audio_bitrate_kbps=256,
    )

    assert config.target_width == 720
    assert config.target_height == 1280
    assert config.target_fps == 60
    assert config.video_codec == "libx265"
    assert config.video_bitrate_kbps == 8000
    assert config.audio_codec == "libfdk_aac"
    assert config.audio_bitrate_kbps == 256


def test_boundary_values_accepted():
    """Each field at boundary (min/max) accepted."""
    # target_width: 2 and 4096 (must be even)
    config = RenderConfiguration(target_width=2)
    assert config.target_width == 2

    config = RenderConfiguration(target_width=4096)
    assert config.target_width == 4096

    # target_height: 2 and 4096 (must be even)
    config = RenderConfiguration(target_height=2)
    assert config.target_height == 2

    config = RenderConfiguration(target_height=4096)
    assert config.target_height == 4096

    # target_fps: 1 and 120
    config = RenderConfiguration(target_fps=1)
    assert config.target_fps == 1

    config = RenderConfiguration(target_fps=120)
    assert config.target_fps == 120

    # video_bitrate_kbps: 500 and 50000
    config = RenderConfiguration(video_bitrate_kbps=500)
    assert config.video_bitrate_kbps == 500

    config = RenderConfiguration(video_bitrate_kbps=50000)
    assert config.video_bitrate_kbps == 50000

    # audio_bitrate_kbps: 32 and 320
    config = RenderConfiguration(audio_bitrate_kbps=32)
    assert config.audio_bitrate_kbps == 32

    config = RenderConfiguration(audio_bitrate_kbps=320)
    assert config.audio_bitrate_kbps == 320


def test_just_outside_boundary_rejected():
    """Each field just outside boundary rejected."""
    # target_width: 0, 1 (odd), 4097, 4098 (even but > 4096)
    for value in (0, 1, 4097, 4098):
        with pytest.raises(ValueError, match="target_width"):
            RenderConfiguration(target_width=value)

    # target_height: 0, 1 (odd), 4097, 4098
    for value in (0, 1, 4097, 4098):
        with pytest.raises(ValueError, match="target_height"):
            RenderConfiguration(target_height=value)

    # target_fps: 0, 121
    for value in (0, 121):
        with pytest.raises(ValueError, match="target_fps"):
            RenderConfiguration(target_fps=value)

    # video_bitrate_kbps: 499, 50001
    for value in (499, 50001):
        with pytest.raises(ValueError, match="video_bitrate_kbps"):
            RenderConfiguration(video_bitrate_kbps=value)

    # audio_bitrate_kbps: 31, 321
    for value in (31, 321):
        with pytest.raises(ValueError, match="audio_bitrate_kbps"):
            RenderConfiguration(audio_bitrate_kbps=value)


def test_enum_fields_accept_only_defined_values():
    """Enum fields accept only defined values; others rejected."""
    # Valid video codecs
    for codec in ("libx264", "libx265", "h264_videotoolbox", "hevc_videotoolbox"):
        config = RenderConfiguration(video_codec=codec)
        assert config.video_codec == codec

    # Invalid video codec
    with pytest.raises(ValueError, match="video_codec"):
        RenderConfiguration(video_codec="invalid_codec")

    # Valid audio codecs
    for codec in ("aac", "libfdk_aac", "copy"):
        config = RenderConfiguration(audio_codec=codec)
        assert config.audio_codec == codec

    # Invalid audio codec
    with pytest.raises(ValueError, match="audio_codec"):
        RenderConfiguration(audio_codec="invalid_codec")


def test_odd_dimensions_rejected():
    """Odd dimensions rejected (FFmpeg requirement)."""
    for value in (1081, 1921, 721, 1281):
        with pytest.raises(ValueError):
            RenderConfiguration(target_width=value)

    for value in (1081, 1921, 721, 1281):
        with pytest.raises(ValueError):
            RenderConfiguration(target_height=value)


def test_from_dict_applies_defaults():
    """from_dict applies defaults for missing keys."""
    data = {"target_width": 720, "target_fps": 60}
    config = RenderConfiguration.from_dict(data)

    assert config.target_width == 720
    assert config.target_height == 1920  # default
    assert config.target_fps == 60
    assert config.video_codec == "libx264"  # default
    assert config.video_bitrate_kbps == 5000  # default
    assert config.audio_codec == "aac"  # default
    assert config.audio_bitrate_kbps == 128  # default


def test_to_dict_returns_all_fields():
    """to_dict returns all configuration fields."""
    config = RenderConfiguration(target_width=720, target_fps=60)
    data = config.to_dict()

    assert set(data.keys()) == {
        "target_width", "target_height", "target_fps",
        "video_codec", "video_bitrate_kbps",
        "audio_codec", "audio_bitrate_kbps",
    }

    assert data["target_width"] == 720
    assert data["target_height"] == 1920
    assert data["target_fps"] == 60


def test_contract_validation_configuration_bounds():
    """Contract validation rejects invalid configuration bounds."""
    base_contract = {
        "version": "1.0.0",
        "action": "render_clips",
        "media": {"duration_ms": 30000},
        "recommendation": {
            "candidates": [
                {"index": 0, "start_ms": 0, "end_ms": 10000, "semantic_rank": 1, "semantic_score": 0.95},
                {"index": 1, "start_ms": 10000, "end_ms": 20000, "semantic_rank": 2, "semantic_score": 0.75},
            ],
            "candidate_index": 0,
        },
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
            "key": "projects/1/assets/1/source.mp4",
            "width": 1920,
            "height": 1080,
            "video_codec": "h264",
            "audio_codec": "aac",
        },
        "media_asset_id": 1,
        "recommendation_id": 1,
    }

    # Test each boundary via contract validation
    for field, invalid_value in [
        ("target_width", 0),
        ("target_width", 1),
        ("target_width", 4097),
        ("target_height", 0),
        ("target_height", 1),
        ("target_height", 4097),
        ("target_fps", 0),
        ("target_fps", 121),
        ("video_bitrate_kbps", 499),
        ("video_bitrate_kbps", 50001),
        ("audio_bitrate_kbps", 31),
        ("audio_bitrate_kbps", 321),
    ]:
        contract = base_contract.copy()
        contract["configuration"] = base_contract["configuration"].copy()
        contract["configuration"][field] = invalid_value

        is_valid, reason = validate_contract(contract)
        assert is_valid is False, f"{field}={invalid_value} should be rejected: {reason}"


def test_contract_validation_accepts_valid_configuration():
    """Contract validation accepts valid configuration."""
    contract = {
        "version": "1.0.0",
        "action": "render_clips",
        "media": {"duration_ms": 30000},
        "recommendation": {
            "candidates": [
                {"index": 0, "start_ms": 0, "end_ms": 10000, "semantic_rank": 1, "semantic_score": 0.95},
                {"index": 1, "start_ms": 10000, "end_ms": 20000, "semantic_rank": 2, "semantic_score": 0.75},
            ],
            "candidate_index": 0,
        },
        "configuration": {
            "target_width": 720,
            "target_height": 1280,
            "target_fps": 60,
            "video_codec": "libx265",
            "video_bitrate_kbps": 8000,
            "audio_codec": "libfdk_aac",
            "audio_bitrate_kbps": 256,
        },
        "source_media": {
            "disk": "media",
            "key": "projects/1/assets/1/source.mp4",
            "width": 1920,
            "height": 1080,
            "video_codec": "h264",
            "audio_codec": "aac",
        },
        "media_asset_id": 1,
        "recommendation_id": 1,
    }

    is_valid, reason = validate_contract(contract)
    assert is_valid is True, f"Valid configuration rejected: {reason}"


def test_unknown_configuration_fields_rejected():
    """Unknown fields in configuration rejected."""
    contract = {
        "version": "1.0.0",
        "action": "render_clips",
        "media": {"duration_ms": 30000},
        "recommendation": {
            "candidates": [
                {"index": 0, "start_ms": 0, "end_ms": 10000, "semantic_rank": 1, "semantic_score": 0.95},
            ],
            "candidate_index": 0,
        },
        "configuration": {
            "target_width": 1080,
            "target_height": 1920,
            "target_fps": 30,
            "video_codec": "libx264",
            "video_bitrate_kbps": 5000,
            "audio_codec": "aac",
            "audio_bitrate_kbps": 128,
            "unknown_field": "value",
        },
        "source_media": {
            "disk": "media",
            "key": "projects/1/assets/1/source.mp4",
            "width": 1920,
            "height": 1080,
            "video_codec": "h264",
            "audio_codec": "aac",
        },
        "media_asset_id": 1,
        "recommendation_id": 1,
    }

    is_valid, reason = validate_contract(contract)
    assert is_valid is False, f"Unknown field should be rejected: {reason}"


def test_missing_configuration_fields_rejected():
    """Missing configuration fields rejected."""
    for field in ("target_width", "target_height", "target_fps", "video_codec", "video_bitrate_kbps", "audio_codec", "audio_bitrate_kbps"):
        contract = {
            "version": "1.0.0",
            "action": "render_clips",
            "media": {"duration_ms": 30000},
            "recommendation": {
                "candidates": [
                    {"index": 0, "start_ms": 0, "end_ms": 10000, "semantic_rank": 1, "semantic_score": 0.95},
                ],
                "candidate_index": 0,
            },
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
                "key": "projects/1/assets/1/source.mp4",
                "width": 1920,
                "height": 1080,
                "video_codec": "h264",
                "audio_codec": "aac",
            },
            "media_asset_id": 1,
            "recommendation_id": 1,
        }
        del contract["configuration"][field]

        is_valid, reason = validate_contract(contract)
        assert is_valid is False, f"Missing {field} should be rejected: {reason}"


def test_fixed_limits_constants():
    """Fixed limits constants match spec."""
    from aiclip_worker.rendering import MAX_RECOMMENDATIONS, MAX_INPUT_BYTES, MAX_DURATION_MS

    assert MAX_RECOMMENDATIONS == 1000
    assert MAX_INPUT_BYTES == 8 * 1024 * 1024  # 8 MiB
    assert MAX_DURATION_MS == 2147483647


def test_default_ffmpeg_timeout():
    """Default FFmpeg timeout is 300 seconds."""
    from aiclip_worker.rendering import DEFAULT_FFMPEG_TIMEOUT, FFMPEG_TIMEOUT_MIN, FFMPEG_TIMEOUT_MAX

    assert DEFAULT_FFMPEG_TIMEOUT == 300
    assert FFMPEG_TIMEOUT_MIN == 30
    assert FFMPEG_TIMEOUT_MAX == 1800


def test_renderer_timeout_validation():
    """Renderer validates timeout bounds."""
    from aiclip_worker.rendering import FFmpegVerticalClipRenderer

    # Valid timeouts
    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=30)
    assert renderer.ffmpeg_timeout == 30

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=300)
    assert renderer.ffmpeg_timeout == 300

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=1800)
    assert renderer.ffmpeg_timeout == 1800

    # Invalid timeouts
    with pytest.raises(ValueError, match="ffmpeg_timeout"):
        FFmpegVerticalClipRenderer(ffmpeg_timeout=29)

    with pytest.raises(ValueError, match="ffmpeg_timeout"):
        FFmpegVerticalClipRenderer(ffmpeg_timeout=1801)

    with pytest.raises(ValueError, match="ffmpeg_timeout"):
        FFmpegVerticalClipRenderer(ffmpeg_timeout=300.5)