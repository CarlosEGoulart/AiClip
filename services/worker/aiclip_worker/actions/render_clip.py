"""Render clip action with strict validation and privacy-safe transport (SINGULAR, corrected design)."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import sys
from typing import Any

from aiclip_worker.contracts import ContractSchemaUnavailable, validate_contract
from aiclip_worker.rendering import (
    RenderConfiguration,
    RenderFailed,
    InvalidCandidateIndex,
    render_clip,
    DEFAULT_FFMPEG_TIMEOUT,
)


MAX_INPUT_BYTES = 8 * 1024 * 1024  # 8 MiB
MAX_OUTPUT_BYTES = 1024 * 1024  # 1 MiB
_DIGEST_ALPHABET = frozenset("0123456789abcdef")


def error(code: str) -> dict[str, Any]:
    """Create standardized error envelope."""
    messages = {
        "invalid_contract": "Invalid render contract",
        "render_failed": "Clip render failed",
    }
    return {
        "status": "error",
        "code": code,
        "error": messages.get(code, "Clip render failed"),
        "stderr": "",
    }


def _is_digest(value: object) -> bool:
    return (
        isinstance(value, str)
        and len(value) == 64
        and all(character in _DIGEST_ALPHABET for character in value)
    )


def _reject_constant(value):
    raise ValueError("JSON constant not allowed")


def _finite_float(value):
    result = float(value)
    if not math.isfinite(result):
        raise ValueError("Non-finite float not allowed")
    return result


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Duplicate key in JSON object")
        result[key] = value
    return result


class _Parser(argparse.ArgumentParser):
    def error(self, message):
        # Argument problems never echo the message: it may carry payload
        # fragments from a rejected argument-list transport.
        raise ValueError("Argument parsing error")


def _read_stdin() -> bytes:
    """Read the raw request bytes from stdin only."""
    stream = getattr(sys.stdin, "buffer", None)
    if stream is None:
        text = sys.stdin.read()
        return text.encode("utf-8") if isinstance(text, str) else bytes(text)

    if sys.stdin.isatty():
        raise ValueError("No contract input")

    return stream.read(MAX_INPUT_BYTES + 1)


def _emit(result: dict[str, Any]) -> int:
    """Write exactly one bounded strict JSON envelope and the exit code."""
    try:
        output = json.dumps(result, allow_nan=False, separators=(",", ":"))
        if len(output.encode("utf-8")) > MAX_OUTPUT_BYTES:
            raise ValueError("Output too large")
    except (ValueError, TypeError):
        result = error("render_failed")
        output = json.dumps(result, allow_nan=False, separators=(",", ":"))

    sys.stdout.write(output)

    if result.get("status") == "success":
        return 0
    if result.get("code") == "invalid_contract":
        return 2
    return 1


def run_cli(argv: list[str]) -> int:
    """CLI entry point for the render-clip subcommand (SINGULAR).

    The request is accepted from stdin only; argument-list
    and file transports are rejected without echoing their payload.
    """
    try:
        parser = _Parser(prog="aiclip_worker render-clip", add_help=False)
        parser.parse_args(argv)  # any argument is rejected without echo

        raw = _read_stdin()
        if len(raw) > MAX_INPUT_BYTES:
            raise ValueError("Input too large")

        contract = json.loads(
            raw.decode("utf-8"),
            parse_constant=_reject_constant,
            parse_float=_finite_float,
            object_pairs_hook=_unique_object,
        )
        request_sha256 = hashlib.sha256(raw).hexdigest()
    except (OSError, ValueError, TypeError, UnicodeError, RecursionError):
        return _emit(error("invalid_contract"))

    try:
        # Validate contract
        try:
            is_valid, _reason = validate_contract(contract)
        except ContractSchemaUnavailable:
            return _emit(error("render_failed"))
        if not is_valid:
            return _emit(error("invalid_contract"))

        if not _is_digest(request_sha256):
            return _emit(error("invalid_contract"))

        # Parse configuration
        configuration = RenderConfiguration.from_dict(contract["configuration"])

        # Get FFmpeg timeout from config (default 300s)
        ffmpeg_timeout = DEFAULT_FFMPEG_TIMEOUT

        result = render_clip(contract, configuration, ffmpeg_timeout)

        # Add request_sha256 to parameters for binding
        if result.get("status") == "success" and "render" in result:
            if "parameters" not in result["render"]:
                result["render"]["parameters"] = {}
            result["render"]["parameters"]["request_sha256"] = request_sha256

    except InvalidCandidateIndex:
        return _emit(error("invalid_contract"))
    except RenderFailed:
        return _emit(error("render_failed"))
    except Exception:
        return _emit(error("render_failed"))

    return _emit(result)