"""Contract validation for media processing contracts."""

from __future__ import annotations

import json
import math
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
