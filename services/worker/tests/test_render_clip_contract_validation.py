"""Strict contract tests for the singular render_clip action."""

from __future__ import annotations

import copy

import pytest

from aiclip_worker.contracts import render_clip_schema_errors, validate_contract


VALID_CONFIGURATION = {
    "target_width": 1080,
    "target_height": 1920,
    "target_fps": 30,
    "video_codec": "libx264",
    "video_bitrate_kbps": 5000,
    "audio_codec": "aac",
    "audio_bitrate_kbps": 128,
}

VALID_SOURCE_MEDIA = {
    "disk": "media",
    "key": "projects/7/assets/42/source.mp4",
    "width": 1920,
    "height": 1080,
    "video_codec": "h264",
    "audio_codec": "aac",
}

VALID_OUTPUT_STORAGE = {
    "disk": "media",
    "key": "projects/7/renders/42/2/vertical_v1/550e8400-e29b-41d4-a716-446655440000.mp4",
    "mime_type": "video/mp4",
}


def valid_render_clip_contract() -> dict:
    return {
        "version": "1.0.0",
        "action": "render_clip",
        "media": {"duration_ms": 30000},
        "candidate_index": 2,
        "candidate": {"start_ms": 1000, "end_ms": 9000},
        "configuration": copy.deepcopy(VALID_CONFIGURATION),
        "source_media": copy.deepcopy(VALID_SOURCE_MEDIA),
        "output_storage": copy.deepcopy(VALID_OUTPUT_STORAGE),
    }


def assert_rejected(contract: dict) -> None:
    valid, reason = validate_contract(contract)
    assert valid is False
    assert reason


def test_valid_singular_contract_is_accepted_by_schema_and_runtime():
    contract = valid_render_clip_contract()
    assert render_clip_schema_errors(contract) == []
    valid, reason = validate_contract(contract)
    assert valid is True, reason


@pytest.mark.parametrize("field", ["version", "action", "media", "candidate_index", "candidate", "configuration", "source_media", "output_storage"])
def test_required_top_level_fields_are_required(field):
    contract = valid_render_clip_contract()
    del contract[field]
    assert render_clip_schema_errors(contract)
    assert_rejected(contract)


def test_wrong_action_is_rejected():
    contract = valid_render_clip_contract()
    contract["action"] = "render_clips"
    assert_rejected(contract)


@pytest.mark.parametrize("value", [-1, 1.5, "2", None, True])
def test_candidate_index_must_be_non_negative_integer(value):
    contract = valid_render_clip_contract()
    contract["candidate_index"] = value
    assert_rejected(contract)


def test_candidate_cannot_contain_second_index_authority():
    contract = valid_render_clip_contract()
    contract["candidate"]["index"] = 2
    assert render_clip_schema_errors(contract)
    assert_rejected(contract)


@pytest.mark.parametrize("field", ["recommendation", "recommendation_id", "project_id", "media_asset_id", "unexpected"])
def test_database_authority_and_unknown_fields_do_not_cross_worker_boundary(field):
    contract = valid_render_clip_contract()
    contract[field] = 123
    assert render_clip_schema_errors(contract)
    assert_rejected(contract)


@pytest.mark.parametrize(
    ("start_ms", "end_ms"),
    [
        (-1, 1000),
        (1000, 1000),
        (2000, 1000),
        (0, 30001),
    ],
)
def test_candidate_bounds_must_be_chronological_and_within_media(start_ms, end_ms):
    contract = valid_render_clip_contract()
    contract["candidate"] = {"start_ms": start_ms, "end_ms": end_ms}
    assert_rejected(contract)


def test_candidate_bounds_must_be_integers():
    contract = valid_render_clip_contract()
    contract["candidate"]["start_ms"] = 1.5
    assert_rejected(contract)


@pytest.mark.parametrize(
    ("field", "value"),
    [
        ("target_width", 1079),
        ("target_height", 1919),
        ("target_fps", 0),
        ("video_codec", "bogus"),
        ("video_bitrate_kbps", 100),
        ("audio_codec", "bogus"),
        ("audio_bitrate_kbps", 10),
    ],
)
def test_configuration_constraints_are_strict(field, value):
    contract = valid_render_clip_contract()
    contract["configuration"][field] = value
    assert_rejected(contract)


def test_configuration_rejects_unknown_field():
    contract = valid_render_clip_contract()
    contract["configuration"]["timeout"] = 300
    assert render_clip_schema_errors(contract)
    assert_rejected(contract)


def test_source_media_is_strict():
    contract = valid_render_clip_contract()
    contract["source_media"]["project_id"] = 7
    assert render_clip_schema_errors(contract)
    assert_rejected(contract)


def test_output_storage_is_strict_and_requires_mp4():
    contract = valid_render_clip_contract()
    contract["output_storage"]["mime_type"] = "video/webm"
    assert_rejected(contract)

    contract = valid_render_clip_contract()
    contract["output_storage"]["extra"] = "x"
    assert render_clip_schema_errors(contract)
    assert_rejected(contract)


# ---------------------------------------------------------------------------
# M6.2 caption styling in the singular request configuration
# ---------------------------------------------------------------------------

VALID_CAPTION_STYLING = {
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


def valid_render_clip_contract_with_captions() -> dict:
    contract = valid_render_clip_contract()
    contract["configuration"]["captions"] = copy.deepcopy(VALID_CAPTION_STYLING)
    contract["captions"] = {
        "enabled": True,
        "segments": [{"start_ms": 1500, "end_ms": 8000, "text": "Caption text"}],
    }
    return contract


def test_singular_contract_accepts_configuration_with_captions_styling():
    """The M6.2 request configuration carries the 8th key (captions) and must pass."""
    contract = valid_render_clip_contract_with_captions()
    assert render_clip_schema_errors(contract) == []
    valid, reason = validate_contract(contract)
    assert valid is True, reason


def test_singular_contract_still_rejects_unknown_configuration_field_with_captions_present():
    contract = valid_render_clip_contract_with_captions()
    contract["configuration"]["timeout"] = 300
    assert render_clip_schema_errors(contract)
    assert_rejected(contract)


def test_singular_contract_rejects_incomplete_caption_styling():
    contract = valid_render_clip_contract_with_captions()
    del contract["configuration"]["captions"]["font_size"]
    assert_rejected(contract)


def test_singular_contract_rejects_segments_inside_caption_styling():
    contract = valid_render_clip_contract_with_captions()
    contract["configuration"]["captions"]["segments"] = [
        {"start_ms": 1500, "end_ms": 8000, "text": "Caption text"}
    ]
    assert_rejected(contract)


def test_singular_contract_rejects_invalid_caption_color_format():
    contract = valid_render_clip_contract_with_captions()
    contract["configuration"]["captions"]["font_color"] = "#ffffff"
    assert_rejected(contract)