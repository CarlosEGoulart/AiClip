"""Contract validation for media processing contracts."""

from __future__ import annotations

import json
import math
import re
from pathlib import Path
from typing import Any

from jsonschema import Draft7Validator, SchemaError, ValidationError

from aiclip_worker.ranking import (
    CONFIGURATION_KEYS,
    SELECTORS,
    configuration_matches_profile,
)


class ContractSchemaUnavailable(RuntimeError):
    """The packaged rank_clips schema could not be loaded or compiled.

    This is an environment/packaging failure, never a property of the
    submitted request, so callers must report it as a runtime failure
    instead of misclassifying a possibly valid request as invalid input.
    """


_SCHEMA_PATH = Path(__file__).resolve().parent.parent / "contracts" / "media_processing_v1.json"

# Exact rank_clips request key set (spec.md "Strict worker protocol").
RANK_CLIPS_REQUEST_KEYS = ("version", "action", "media", "candidates", "configuration")
RANK_CLIPS_CANDIDATE_KEYS = (
    "index",
    "start_ms",
    "end_ms",
    "m4_rank",
    "m4_score",
    "transcript_text",
)
RANK_CLIPS_MEDIA_KEYS = ("duration_ms",)
RANK_CLIPS_VERSION = "1.0.0"
RANK_CLIPS_ACTION = "rank_clips"

# Render clips constants
RENDER_CLIPS_VERSION = "1.0.0"
RENDER_CLIPS_ACTION = "render_clips"
RENDER_CLIPS_REQUEST_KEYS = ("version", "action", "media", "recommendation", "candidate_index", "configuration", "source_media", "media_asset_id", "recommendation_id")
RENDER_CLIPS_MEDIA_KEYS = ("duration_ms",)
RENDER_CLIPS_RECOMMENDATION_KEYS = ("candidates", "candidate_index")
RENDER_CLIPS_CANDIDATE_KEYS = ("index", "start_ms", "end_ms", "semantic_rank", "semantic_score")
RENDER_CLIPS_CONFIG_KEYS = ("target_width", "target_height", "target_fps", "video_codec", "video_bitrate_kbps", "audio_codec", "audio_bitrate_kbps")
RENDER_CLIPS_SOURCE_MEDIA_KEYS = ("disk", "key", "width", "height", "video_codec", "audio_codec")

# Render clip (singular) constants
RENDER_CLIP_VERSION = "1.0.0"
RENDER_CLIP_ACTION = "render_clip"
RENDER_CLIP_REQUEST_KEYS = ("version", "action", "media", "candidate_index", "candidate", "configuration", "source_media", "output_storage")
RENDER_CLIP_MEDIA_KEYS = ("duration_ms",)
RENDER_CLIP_CANDIDATE_KEYS = ("start_ms", "end_ms")
RENDER_CLIP_CONFIG_KEYS = ("target_width", "target_height", "target_fps", "video_codec", "video_bitrate_kbps", "audio_codec", "audio_bitrate_kbps", "captions")
RENDER_CLIP_SOURCE_MEDIA_KEYS = ("disk", "key", "width", "height", "video_codec", "audio_codec")
RENDER_CLIP_OUTPUT_STORAGE_KEYS = ("disk", "key", "mime_type")

# Caption configuration constants
# Styling-only keys for configuration.captions (no segments)
CAPTION_KEYS = ("enabled", "font_file", "font_size", "font_color", "outline_color", "outline_width",
                "background_color", "background_opacity", "box_padding", "margin_bottom", "max_chars_per_line")

# Top-level captions object keys (optional, for render_clip action)
TOP_LEVEL_CAPTION_KEYS = ("enabled", "segments")
TOP_LEVEL_CAPTION_SEGMENT_KEYS = ("start_ms", "end_ms", "text")

# Hex color validation: 6-char lowercase hex without #
HEX_COLOR_REGEX = re.compile(r"^[0-9a-f]{6}$")

VALID_VIDEO_CODECS = ("libx264", "libx265", "h264_videotoolbox", "hevc_videotoolbox")
VALID_AUDIO_CODECS = ("aac", "libfdk_aac", "copy")

MAX_DURATION_MS = 2147483647
MAX_CANDIDATES = 1000
MAX_CANDIDATE_TEXT_BYTES = 16384
MAX_INPUT_BYTES = 8 * 1024 * 1024  # 8 MiB


def _load_schema() -> dict[str, Any]:
    """Load the v1.0.0 contract JSON schema."""
    with open(_SCHEMA_PATH) as f:
        return json.load(f)


def _rank_clips_schema() -> dict[str, Any]:
    """The packaged JSON schema of the rank_clips request.

    The legacy root schema requires the media-processing envelope that a
    transcript-bearing ranking request deliberately does not carry, so the
    additive action validates against its own packaged definition instead of
    bypassing schema validation altogether.
    """
    schema = _load_schema()
    definitions = schema.get("definitions")
    if not isinstance(definitions, dict) or "rank_clips_request" not in definitions:
        raise FileNotFoundError("rank_clips request schema is not packaged")

    return {
        "$ref": "#/definitions/rank_clips_request",
        "definitions": definitions,
    }


def rank_clips_schema_errors(contract: object) -> list[str]:
    """Schema-level errors of the rank_clips request (empty when conformant).

    Raises :class:`ContractSchemaUnavailable` when the packaged schema itself
    cannot be loaded or compiled: that condition says nothing about the
    submitted request and must stay an environment failure.
    """
    try:
        schema = _rank_clips_schema()
    except (OSError, json.JSONDecodeError) as exc:
        raise ContractSchemaUnavailable("Contract schema unavailable") from exc

    try:
        validator = Draft7Validator(schema)
        return [error.message for error in validator.iter_errors(contract)]
    except SchemaError as exc:
        raise ContractSchemaUnavailable("Contract schema unavailable") from exc


def _render_clips_schema() -> dict[str, Any]:
    """The packaged JSON schema of the render_clips request."""
    schema = _load_schema()
    definitions = schema.get("definitions")
    if not isinstance(definitions, dict) or "render_clips_request" not in definitions:
        raise FileNotFoundError("render_clips request schema is not packaged")

    return {
        "$ref": "#/definitions/render_clips_request",
        "definitions": definitions,
    }


def _render_clip_schema() -> dict[str, Any]:
    """The packaged JSON schema of the render_clip request."""
    schema = _load_schema()
    definitions = schema.get("definitions")
    if not isinstance(definitions, dict) or "render_clip_request" not in definitions:
        raise FileNotFoundError("render_clip request schema is not packaged")

    return {
        "$ref": "#/definitions/render_clip_request",
        "definitions": definitions,
    }


def render_clips_schema_errors(contract: object) -> list[str]:
    """Schema-level errors of the render_clips request (empty when conformant)."""
    try:
        schema = _render_clips_schema()
    except (OSError, json.JSONDecodeError) as exc:
        raise ContractSchemaUnavailable("Contract schema unavailable") from exc

    try:
        validator = Draft7Validator(schema)
        return [error.message for error in validator.iter_errors(contract)]
    except SchemaError as exc:
        raise ContractSchemaUnavailable("Contract schema unavailable") from exc


def render_clip_schema_errors(contract: object) -> list[str]:
    """Schema-level errors of the render_clip request (empty when conformant)."""
    try:
        schema = _render_clip_schema()
    except (OSError, json.JSONDecodeError) as exc:
        raise ContractSchemaUnavailable("Contract schema unavailable") from exc

    try:
        validator = Draft7Validator(schema)
        return [error.message for error in validator.iter_errors(contract)]
    except SchemaError as exc:
        raise ContractSchemaUnavailable("Contract schema unavailable") from exc


def _exact_keys(value: object, expected: tuple[str, ...]) -> bool:
    if not isinstance(value, dict):
        return False
    return set(value.keys()) == set(expected)


def _is_integer(value: object) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def _is_number(value: object) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def _validate_configuration(configuration: object) -> str:
    """Return an error message unless the configuration is the pinned profile."""
    if not isinstance(configuration, dict):
        return "configuration must be an object"
    if set(configuration.keys()) != set(CONFIGURATION_KEYS):
        missing = sorted(set(CONFIGURATION_KEYS) - set(configuration.keys()))
        unknown = sorted(set(configuration.keys()) - set(CONFIGURATION_KEYS))
        if unknown:
            return f"configuration contains unknown fields: {unknown}"
        return f"configuration missing required fields: {missing}"

    selector = configuration["provider"]
    if not isinstance(selector, str) or selector not in SELECTORS:
        return "configuration.provider must be 'fake' or 'cross_encoder'"

    if not configuration_matches_profile(configuration, selector):
        return "configuration does not equal the selected pinned profile"

    return ""


def _validate_candidates(candidates: object, duration_ms: int) -> str:
    """Return an error message unless the candidate list is authoritative."""
    if not isinstance(candidates, list):
        return "candidates must be a list"

    k = len(candidates)
    if k < 1:
        return "rank_clips requires at least one candidate"
    if k > MAX_CANDIDATES:
        return f"rank_clips supports at most {MAX_CANDIDATES} candidates"

    seen_ranks: set[int] = set()
    usable_text = False

    for position, candidate in enumerate(candidates):
        if not isinstance(candidate, dict):
            return f"candidate {position} must be an object"
        if set(candidate.keys()) != set(RANK_CLIPS_CANDIDATE_KEYS):
            unknown = sorted(set(candidate.keys()) - set(RANK_CLIPS_CANDIDATE_KEYS))
            if unknown:
                return f"candidate {position} contains unknown fields: {unknown}"
            missing = sorted(set(RANK_CLIPS_CANDIDATE_KEYS) - set(candidate.keys()))
            return f"candidate {position} missing required fields: {missing}"

        index = candidate["index"]
        if not _is_integer(index):
            return f"candidate {position}.index must be an integer"
        if index != position:
            return f"candidate index must be sequential 0..K-1, got {index} at position {position}"

        start_ms = candidate["start_ms"]
        end_ms = candidate["end_ms"]
        if not _is_integer(start_ms) or not _is_integer(end_ms):
            return f"candidate {position} bounds must be integers"
        if start_ms < 0 or start_ms > duration_ms:
            return f"candidate {position}.start_ms out of range: {start_ms}"
        if end_ms <= start_ms or end_ms > duration_ms:
            return f"candidate {position}.end_ms invalid: {end_ms}"

        m4_rank = candidate["m4_rank"]
        if not _is_integer(m4_rank):
            return f"candidate {position}.m4_rank must be an integer"
        if m4_rank < 1 or m4_rank > k:
            return f"candidate {position}.m4_rank out of range 1..{k}: {m4_rank}"
        if m4_rank in seen_ranks:
            return f"duplicate candidate m4_rank: {m4_rank}"
        seen_ranks.add(m4_rank)

        m4_score = candidate["m4_score"]
        if not _is_number(m4_score):
            return f"candidate {position}.m4_score must be a number"
        if not math.isfinite(float(m4_score)):
            return f"candidate {position}.m4_score must be finite"
        if m4_score < 0 or m4_score > 1:
            return f"candidate {position}.m4_score must stay inside [0,1]"

        transcript_text = candidate["transcript_text"]
        if not isinstance(transcript_text, str):
            return f"candidate {position}.transcript_text must be a string"
        if len(transcript_text.encode("utf-8")) > MAX_CANDIDATE_TEXT_BYTES:
            return f"candidate {position}.transcript_text exceeds the canonical byte bound"
        if transcript_text != "":
            usable_text = True

    if not usable_text:
        return "rank_clips requires at least one nonempty candidate text"

    return ""


def validate_rank_clips_contract(contract: object) -> tuple[bool, str]:
    """Validate a rank_clips request at both the schema and runtime levels.

    The packaged schema and the strict runtime validation must agree: the
    request is accepted only when both accept it, so there is no weaker
    bypass branch.
    """
    if not isinstance(contract, dict):
        return False, "rank_clips contract must be an object"

    schema_errors = rank_clips_schema_errors(contract)
    if schema_errors:
        return False, "; ".join(schema_errors)

    if set(contract.keys()) != set(RANK_CLIPS_REQUEST_KEYS):
        unknown = sorted(set(contract.keys()) - set(RANK_CLIPS_REQUEST_KEYS))
        if unknown:
            return False, f"unknown fields: {unknown}"
        missing = sorted(set(RANK_CLIPS_REQUEST_KEYS) - set(contract.keys()))
        return False, f"missing required fields: {missing}"

    if contract.get("version") != RANK_CLIPS_VERSION:
        return False, f"Unsupported contract version: {contract.get('version')!r}"

    if contract.get("action") != RANK_CLIPS_ACTION:
        return False, "Invalid action for rank_clips"

    media = contract.get("media")
    if not isinstance(media, dict) or set(media.keys()) != set(RANK_CLIPS_MEDIA_KEYS):
        return False, "media must contain exactly duration_ms"
    duration_ms = media["duration_ms"]
    if not _is_integer(duration_ms) or duration_ms < 1 or duration_ms > MAX_DURATION_MS:
        return False, "media.duration_ms must be a strict positive bounded integer"

    candidates_error = _validate_candidates(contract.get("candidates"), duration_ms)
    if candidates_error:
        return False, candidates_error

    configuration_error = _validate_configuration(contract.get("configuration"))
    if configuration_error:
        return False, configuration_error

    return True, ""


def _validate_rank_clips(contract: dict[str, Any]) -> tuple[bool, str]:
    """Validate rank_clips contract with strict checks."""
    return validate_rank_clips_contract(contract)


def _validate_render_clips_configuration(configuration: object) -> str:
    """Return an error message unless the render configuration is valid."""
    if not isinstance(configuration, dict):
        return "configuration must be an object"
    if set(configuration.keys()) != set(RENDER_CLIPS_CONFIG_KEYS):
        missing = sorted(set(RENDER_CLIPS_CONFIG_KEYS) - set(configuration.keys()))
        unknown = sorted(set(configuration.keys()) - set(RENDER_CLIPS_CONFIG_KEYS))
        if unknown:
            return f"configuration contains unknown fields: {unknown}"
        return f"configuration missing required fields: {missing}"

    target_width = configuration["target_width"]
    target_height = configuration["target_height"]
    target_fps = configuration["target_fps"]
    video_codec = configuration["video_codec"]
    video_bitrate_kbps = configuration["video_bitrate_kbps"]
    audio_codec = configuration["audio_codec"]
    audio_bitrate_kbps = configuration["audio_bitrate_kbps"]

    if not _is_integer(target_width) or target_width < 1 or target_width > 4096 or target_width % 2 != 0:
        return "target_width must be an even integer between 1 and 4096"
    if not _is_integer(target_height) or target_height < 1 or target_height > 4096 or target_height % 2 != 0:
        return "target_height must be an even integer between 1 and 4096"
    if not _is_integer(target_fps) or target_fps < 1 or target_fps > 120:
        return "target_fps must be an integer between 1 and 120"
    if not isinstance(video_codec, str) or video_codec not in VALID_VIDEO_CODECS:
        return f"video_codec must be one of: {', '.join(VALID_VIDEO_CODECS)}"
    if not _is_integer(video_bitrate_kbps) or video_bitrate_kbps < 500 or video_bitrate_kbps > 50000:
        return "video_bitrate_kbps must be an integer between 500 and 50000"
    if not isinstance(audio_codec, str) or audio_codec not in VALID_AUDIO_CODECS:
        return f"audio_codec must be one of: {', '.join(VALID_AUDIO_CODECS)}"
    if not _is_integer(audio_bitrate_kbps) or audio_bitrate_kbps < 32 or audio_bitrate_kbps > 320:
        return "audio_bitrate_kbps must be an integer between 32 and 320"

    return ""


def _validate_render_clips_recommendation(recommendation: object, duration_ms: int) -> str:
    """Return an error message unless the recommendation is valid."""
    if not isinstance(recommendation, dict):
        return "recommendation must be an object"
    if set(recommendation.keys()) != set(RENDER_CLIPS_RECOMMENDATION_KEYS):
        missing = sorted(set(RENDER_CLIPS_RECOMMENDATION_KEYS) - set(recommendation.keys()))
        unknown = sorted(set(recommendation.keys()) - set(RENDER_CLIPS_RECOMMENDATION_KEYS))
        if unknown:
            return f"recommendation contains unknown fields: {unknown}"
        return f"recommendation missing required fields: {missing}"

    candidates = recommendation.get("candidates")
    candidate_index = recommendation.get("candidate_index")

    if not isinstance(candidates, list):
        return "recommendation.candidates must be a list"
    if len(candidates) < 1:
        return "recommendation requires at least one candidate"
    if len(candidates) > MAX_CANDIDATES:
        return f"recommendation supports at most {MAX_CANDIDATES} candidates"

    if not _is_integer(candidate_index):
        return "candidate_index must be an integer"
    if candidate_index < 0 or candidate_index >= len(candidates):
        return "candidate_index out of bounds"

    # Validate each candidate
    seen_semantic_ranks: set[int] = set()
    for position, candidate in enumerate(candidates):
        if not isinstance(candidate, dict):
            return f"candidate {position} must be an object"
        if set(candidate.keys()) != set(RENDER_CLIPS_CANDIDATE_KEYS):
            unknown = sorted(set(candidate.keys()) - set(RENDER_CLIPS_CANDIDATE_KEYS))
            if unknown:
                return f"candidate {position} contains unknown fields: {unknown}"
            missing = sorted(set(RENDER_CLIPS_CANDIDATE_KEYS) - set(candidate.keys()))
            return f"candidate {position} missing required fields: {missing}"

        index = candidate["index"]
        if not _is_integer(index):
            return f"candidate {position}.index must be an integer"
        if index != position:
            return f"candidate index must be sequential 0..K-1, got {index} at position {position}"

        start_ms = candidate["start_ms"]
        end_ms = candidate["end_ms"]
        if not _is_integer(start_ms) or not _is_integer(end_ms):
            return f"candidate {position} bounds must be integers"
        if start_ms < 0 or start_ms > duration_ms:
            return f"candidate {position}.start_ms out of range: {start_ms}"
        if end_ms <= start_ms or end_ms > duration_ms:
            return f"candidate {position}.end_ms invalid: {end_ms}"

        semantic_rank = candidate["semantic_rank"]
        if not _is_integer(semantic_rank):
            return f"candidate {position}.semantic_rank must be an integer"
        if semantic_rank < 1:
            return f"candidate {position}.semantic_rank must be >= 1"
        k = len(candidates)
        if semantic_rank > k:
            return f"candidate {position}.semantic_rank out of range 1..{k}: {semantic_rank}"
        if semantic_rank in seen_semantic_ranks:
            return f"duplicate candidate semantic_rank: {semantic_rank}"
        seen_semantic_ranks.add(semantic_rank)

        semantic_score = candidate["semantic_score"]
        if semantic_score is not None:
            if not _is_number(semantic_score):
                return f"candidate {position}.semantic_score must be a number or null"
            if not math.isfinite(float(semantic_score)):
                return f"candidate {position}.semantic_score must be finite"
            if semantic_score < 0 or semantic_score > 1:
                return f"candidate {position}.semantic_score must stay inside [0,1]"

    # Selected candidate must have non-null semantic_score
    selected_candidate = candidates[candidate_index]
    if selected_candidate["semantic_score"] is None:
        return "selected candidate must have non-null semantic_score"

    return ""


def _validate_render_clips_source_media(source_media: object) -> str:
    """Return an error message unless the source media info is valid."""
    if not isinstance(source_media, dict):
        return "source_media must be an object"
    if set(source_media.keys()) != set(RENDER_CLIPS_SOURCE_MEDIA_KEYS):
        missing = sorted(set(RENDER_CLIPS_SOURCE_MEDIA_KEYS) - set(source_media.keys()))
        unknown = sorted(set(source_media.keys()) - set(RENDER_CLIPS_SOURCE_MEDIA_KEYS))
        if unknown:
            return f"source_media contains unknown fields: {unknown}"
        return f"source_media missing required fields: {missing}"

    disk = source_media["disk"]
    key = source_media["key"]
    width = source_media["width"]
    height = source_media["height"]
    video_codec = source_media["video_codec"]
    audio_codec = source_media["audio_codec"]

    if not isinstance(disk, str) or not disk:
        return "source_media.disk must be a non-empty string"
    if not isinstance(key, str) or not key:
        return "source_media.key must be a non-empty string"
    if not _is_integer(width) or width < 1:
        return "source_media.width must be a positive integer"
    if not _is_integer(height) or height < 1:
        return "source_media.height must be a positive integer"
    if not isinstance(video_codec, str) or not video_codec:
        return "source_media.video_codec must be a non-empty string"
    if audio_codec is not None and (not isinstance(audio_codec, str) or not audio_codec):
        return "source_media.audio_codec must be a non-empty string or null"

    return ""


def validate_render_clips_contract(contract: object) -> tuple[bool, str]:
    """Validate a render_clips request at both the schema and runtime levels."""
    if not isinstance(contract, dict):
        return False, "render_clips contract must be an object"

    schema_errors = render_clips_schema_errors(contract)
    if schema_errors:
        return False, "; ".join(schema_errors)

    if set(contract.keys()) != set(RENDER_CLIPS_REQUEST_KEYS):
        unknown = sorted(set(contract.keys()) - set(RENDER_CLIPS_REQUEST_KEYS))
        if unknown:
            return False, f"unknown fields: {unknown}"
        missing = sorted(set(RENDER_CLIPS_REQUEST_KEYS) - set(contract.keys()))
        return False, f"missing required fields: {missing}"

    if contract.get("version") != RENDER_CLIPS_VERSION:
        return False, f"Unsupported contract version: {contract.get('version')!r}"

    if contract.get("action") != RENDER_CLIPS_ACTION:
        return False, "Invalid action for render_clips"

    media = contract.get("media")
    if not isinstance(media, dict) or set(media.keys()) != set(RENDER_CLIPS_MEDIA_KEYS):
        return False, "media must contain exactly duration_ms"
    duration_ms = media["duration_ms"]
    if not _is_integer(duration_ms) or duration_ms < 1 or duration_ms > MAX_DURATION_MS:
        return False, "media.duration_ms must be a strict positive bounded integer"

    # Validate media_asset_id
    media_asset_id = contract.get("media_asset_id")
    if not _is_integer(media_asset_id) or media_asset_id < 1:
        return False, "media_asset_id must be a positive integer"

    # Validate recommendation_id
    recommendation_id = contract.get("recommendation_id")
    if not _is_integer(recommendation_id) or recommendation_id < 1:
        return False, "recommendation_id must be a positive integer"

    recommendation_error = _validate_render_clips_recommendation(contract.get("recommendation"), duration_ms)
    if recommendation_error:
        return False, recommendation_error

    configuration_error = _validate_render_clips_configuration(contract.get("configuration"))
    if configuration_error:
        return False, configuration_error

    source_media_error = _validate_render_clips_source_media(contract.get("source_media"))
    if source_media_error:
        return False, source_media_error

    return True, ""


def _validate_render_clips(contract: dict[str, Any]) -> tuple[bool, str]:
    """Validate render_clips contract with strict checks."""
    return validate_render_clips_contract(contract)


def _validate_hex_color(value: object, field_name: str) -> str:
    """Validate a 6-char lowercase hex color without # prefix."""
    if not isinstance(value, str):
        return f"{field_name} must be a string"
    if not HEX_COLOR_REGEX.match(value):
        return f"{field_name} must be a 6-character lowercase hex color (e.g., 'ffffff')"
    return ""


def _validate_captions(captions: object, duration_ms: int) -> str:
    """Return an error message unless the captions styling configuration is valid.

    This validates the styling-only captions object from configuration.captions.
    Segments are NOT validated here; they are validated separately in the top-level
    captions object if present.

    Captions are optional; None or missing is valid.
    """
    if captions is None:
        return ""
    if not isinstance(captions, dict):
        return "captions must be an object or null"
    if set(captions.keys()) != set(CAPTION_KEYS):
        missing = sorted(set(CAPTION_KEYS) - set(captions.keys()))
        unknown = sorted(set(captions.keys()) - set(CAPTION_KEYS))
        if unknown:
            return f"captions contains unknown fields: {unknown}"
        return f"captions missing required fields: {missing}"

    # Validate enabled (required boolean)
    enabled = captions.get("enabled")
    if not isinstance(enabled, bool):
        return "captions.enabled must be a boolean"

    # If disabled, remaining fields are still required by key set but values can be defaults
    if not enabled:
        return ""

    # Validate font_file (required string)
    font_file = captions.get("font_file")
    if not isinstance(font_file, str) or not font_file:
        return "captions.font_file must be a non-empty string"

    # Validate font_size (required positive integer)
    font_size = captions.get("font_size")
    if not _is_integer(font_size) or font_size < 1:
        return "captions.font_size must be a positive integer"

    # Validate font_color (required string, 6-char lowercase hex without #)
    error = _validate_hex_color(captions.get("font_color"), "captions.font_color")
    if error:
        return error

    # Validate outline_color (required string, 6-char lowercase hex without #)
    error = _validate_hex_color(captions.get("outline_color"), "captions.outline_color")
    if error:
        return error

    # Validate outline_width (required non-negative integer)
    outline_width = captions.get("outline_width")
    if not _is_integer(outline_width) or outline_width < 0:
        return "captions.outline_width must be a non-negative integer"

    # Validate background_color (required string, 6-char lowercase hex without #)
    error = _validate_hex_color(captions.get("background_color"), "captions.background_color")
    if error:
        return error

    # Validate background_opacity (required float 0.0-1.0)
    background_opacity = captions.get("background_opacity")
    if not _is_number(background_opacity) or background_opacity < 0.0 or background_opacity > 1.0:
        return "captions.background_opacity must be a number between 0.0 and 1.0"

    # Validate box_padding (required non-negative integer)
    box_padding = captions.get("box_padding")
    if not _is_integer(box_padding) or box_padding < 0:
        return "captions.box_padding must be a non-negative integer"

    # Validate margin_bottom (required non-negative integer)
    margin_bottom = captions.get("margin_bottom")
    if not _is_integer(margin_bottom) or margin_bottom < 0:
        return "captions.margin_bottom must be a non-negative integer"

    # Validate max_chars_per_line (required positive integer)
    max_chars_per_line = captions.get("max_chars_per_line")
    if not _is_integer(max_chars_per_line) or max_chars_per_line < 1:
        return "captions.max_chars_per_line must be a positive integer"

    return ""


def _validate_top_level_captions(captions: object, duration_ms: int) -> str:
    """Return an error message unless the top-level captions object is valid.

    This validates the optional top-level captions object in render_clip request.
    It contains "enabled" (bool) and "segments" (array of timed text segments).

    If present and enabled=True, segments must be non-empty and valid.
    If disabled or not present, no further validation needed.
    """
    if captions is None:
        return ""
    if not isinstance(captions, dict):
        return "captions must be an object or null"
    if set(captions.keys()) != set(TOP_LEVEL_CAPTION_KEYS):
        missing = sorted(set(TOP_LEVEL_CAPTION_KEYS) - set(captions.keys()))
        unknown = sorted(set(captions.keys()) - set(TOP_LEVEL_CAPTION_KEYS))
        if unknown:
            return f"captions contains unknown fields: {unknown}"
        return f"captions missing required fields: {missing}"

    # Validate enabled (required boolean)
    enabled = captions.get("enabled")
    if not isinstance(enabled, bool):
        return "captions.enabled must be a boolean"

    # If disabled, no further validation needed
    if not enabled:
        return ""

    # Validate segments (required non-empty list when enabled)
    segments = captions.get("segments")
    if not isinstance(segments, list):
        return "captions.segments must be a list"
    if len(segments) == 0:
        return "captions.segments must contain at least one segment when enabled"

    prev_end_ms = 0
    for i, segment in enumerate(segments):
        if not isinstance(segment, dict):
            return f"captions.segments[{i}] must be an object"
        if set(segment.keys()) != set(TOP_LEVEL_CAPTION_SEGMENT_KEYS):
            missing = sorted(set(TOP_LEVEL_CAPTION_SEGMENT_KEYS) - set(segment.keys()))
            unknown = sorted(set(segment.keys()) - set(TOP_LEVEL_CAPTION_SEGMENT_KEYS))
            if unknown:
                return f"captions.segments[{i}] contains unknown fields: {unknown}"
            return f"captions.segments[{i}] missing required fields: {missing}"

        start_ms = segment.get("start_ms")
        end_ms = segment.get("end_ms")
        text = segment.get("text")

        if not _is_integer(start_ms) or start_ms < 0 or start_ms > duration_ms:
            return f"captions.segments[{i}].start_ms out of range: {start_ms}"
        if not _is_integer(end_ms) or end_ms <= start_ms or end_ms > duration_ms:
            return f"captions.segments[{i}].end_ms invalid: {end_ms}"
        if start_ms < prev_end_ms:
            return f"captions.segments[{i}].start_ms must not be before previous segment end_ms"
        prev_end_ms = end_ms

        if not isinstance(text, str) or not text:
            return f"captions.segments[{i}].text must be a non-empty string"

    return ""


def validate_render_clip_contract(contract: object) -> tuple[bool, str]:
    """Validate a render_clip request at both the schema and runtime levels."""
    if not isinstance(contract, dict):
        return False, "render_clip contract must be an object"

    schema_errors = render_clip_schema_errors(contract)
    if schema_errors:
        return False, "; ".join(schema_errors)

    # Allow required keys plus optional "captions" at top level
    allowed_keys = set(RENDER_CLIP_REQUEST_KEYS) | {"captions"}
    if not set(contract.keys()).issubset(allowed_keys):
        unknown = sorted(set(contract.keys()) - allowed_keys)
        return False, f"unknown fields: {unknown}"
    missing = sorted(set(RENDER_CLIP_REQUEST_KEYS) - set(contract.keys()))
    if missing:
        return False, f"missing required fields: {missing}"

    if contract.get("version") != RENDER_CLIP_VERSION:
        return False, f"Unsupported contract version: {contract.get('version')!r}"

    if contract.get("action") != RENDER_CLIP_ACTION:
        return False, "Invalid action for render_clip"

    media = contract.get("media")
    if not isinstance(media, dict) or set(media.keys()) != set(RENDER_CLIP_MEDIA_KEYS):
        return False, "media must contain exactly duration_ms"
    duration_ms = media["duration_ms"]
    if not _is_integer(duration_ms) or duration_ms < 1 or duration_ms > MAX_DURATION_MS:
        return False, "media.duration_ms must be a strict positive bounded integer"

    candidate_index = contract.get("candidate_index")
    if not _is_integer(candidate_index) or candidate_index < 0:
        return False, "candidate_index must be a non-negative integer"

    candidate = contract.get("candidate")
    if not isinstance(candidate, dict) or set(candidate.keys()) != set(RENDER_CLIP_CANDIDATE_KEYS):
        return False, "candidate must contain exactly start_ms and end_ms"
    start_ms = candidate["start_ms"]
    end_ms = candidate["end_ms"]
    if not _is_integer(start_ms) or not _is_integer(end_ms):
        return False, "candidate bounds must be integers"
    if start_ms < 0 or end_ms <= start_ms or end_ms > duration_ms:
        return False, "candidate bounds invalid: require 0 <= start_ms < end_ms <= duration_ms"

    configuration_error = _validate_render_clips_configuration(contract.get("configuration"))
    if configuration_error:
        return False, configuration_error

    # Validate configuration.captions (styling only)
    config_captions = contract.get("configuration", {}).get("captions")
    captions_error = _validate_captions(config_captions, duration_ms)
    if captions_error:
        return False, captions_error

    # Validate top-level captions object if present (optional)
    top_level_captions = contract.get("captions")
    top_captions_error = _validate_top_level_captions(top_level_captions, duration_ms)
    if top_captions_error:
        return False, top_captions_error

    source_media_error = _validate_render_clips_source_media(contract.get("source_media"))
    if source_media_error:
        return False, source_media_error

    output_storage = contract.get("output_storage")
    if not isinstance(output_storage, dict) or set(output_storage.keys()) != set(RENDER_CLIP_OUTPUT_STORAGE_KEYS):
        missing = sorted(set(RENDER_CLIP_OUTPUT_STORAGE_KEYS) - set(output_storage.keys()))
        unknown = sorted(set(output_storage.keys()) - set(RENDER_CLIP_OUTPUT_STORAGE_KEYS))
        if unknown:
            return False, f"output_storage contains unknown fields: {unknown}"
        return False, f"output_storage missing required fields: {missing}"

    disk = output_storage["disk"]
    key = output_storage["key"]
    mime_type = output_storage["mime_type"]
    if not isinstance(disk, str) or not disk:
        return False, "output_storage.disk must be a non-empty string"
    if not isinstance(key, str) or not key:
        return False, "output_storage.key must be a non-empty string"
    if not isinstance(mime_type, str) or mime_type != "video/mp4":
        return False, 'output_storage.mime_type must be "video/mp4"'

    return True, ""


def _validate_render_clip(contract: dict[str, Any]) -> tuple[bool, str]:
    """Validate render_clip contract with strict checks."""
    return validate_render_clip_contract(contract)


def validate_contract(contract: dict[str, Any]) -> tuple[bool, str]:
    """Validate a contract against the JSON schema.

    Args:
        contract: The contract dict to validate.

    Returns:
        Tuple of (is_valid, error_message). If valid, error_message is empty.
    """
    if isinstance(contract, dict) and contract.get("action") == "analyze_clips":
        from aiclip_worker.clip_analysis import ClipAnalysisInput, ClipValidationError

        try:
            ClipAnalysisInput.from_contract(contract)
        except (ClipValidationError, ValueError, TypeError, RecursionError):
            return False, "Invalid clip analysis contract"
        return True, ""

    if isinstance(contract, dict) and contract.get("action") == "rank_clips":
        return _validate_rank_clips(contract)

    if isinstance(contract, dict) and contract.get("action") == "render_clips":
        return _validate_render_clips(contract)

    if isinstance(contract, dict) and contract.get("action") == RENDER_CLIP_ACTION:
        return _validate_render_clip(contract)

    # Check that version is present and is 1.x
    version = contract.get("version", "")
    if not version:
        return False, "Missing required field: version"

    major = version.split(".")[0]
    if major != "1":
        return False, f"Unsupported contract version major: {major}. Only 1.x is supported."

    # Validate against JSON schema
    try:
        schema = _load_schema()
        validator = Draft7Validator(schema)
        errors = list(validator.iter_errors(contract))
        if errors:
            error_messages = [e.message for e in errors]
            return False, "; ".join(error_messages)
    except FileNotFoundError:
        return False, "Contract schema file not found"

    return True, ""
