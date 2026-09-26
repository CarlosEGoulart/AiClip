"""Rank clips action with strict validation and privacy-safe transport.

Two strictly separated phases (spec.md "Strict worker protocol"):

* contract/selection problems - unknown or unset provider selection, wrong
  key sets, bounds, types, profile mismatches - are `invalid_contract`
  (exit 2) and no provider is ever constructed;
* provider execution problems - runtime absence, cache misses, dependency
  failures, malformed inference or a malformed provider result - are
  sanitized `ranking_failed` (exit 1) that never carry partial ranking data
  or library diagnostics.

The transport is stdin only. The digest echoed in the result is the SHA256 of
the exact raw stdin bytes, never a reserialized approximation.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import sys
from typing import Any

from aiclip_worker.contracts import ContractSchemaUnavailable, validate_contract
from aiclip_worker.ranking import (
    SCORE_UNITS,
    CrossEncoderRankingProvider,
    FakeRankingProvider,
    RankingInput,
    profile,
)


MAX_INPUT_BYTES = 8 * 1024 * 1024  # 8 MiB
MAX_OUTPUT_BYTES = 1024 * 1024  # 1 MiB
UNSCORED_REASON = "no_candidate_text"
_ALGORITHM_KEYS = ("algorithm", "algorithm_version")
_PARAMETER_IDENTITY_KEYS = ("provider_name", "inference_performed", "transcript_used")
_DIGEST_ALPHABET = frozenset("0123456789abcdef")


def error(code: str) -> dict[str, Any]:
    """Create standardized error envelope."""
    messages = {
        "invalid_contract": "Invalid ranking contract",
        "ranking_failed": "Ranking failed",
    }
    return {
        "status": "error",
        "code": code,
        "error": messages.get(code, "Ranking failed"),
        "stderr": "",
    }


def _is_digest(value: object) -> bool:
    return (
        isinstance(value, str)
        and len(value) == 64
        and all(character in _DIGEST_ALPHABET for character in value)
    )


def _select_provider(selector: str) -> Any:
    """Construct the explicitly selected trusted profile.

    The selector is the trusted `configuration.provider` sent in the
    validated request. There is no environment selection, no automatic
    detection and no fallback of any kind; an unknown selector is rejected
    by contract validation before this function runs.
    """
    if selector == "fake":
        return FakeRankingProvider()
    if selector == "cross_encoder":
        return CrossEncoderRankingProvider()
    raise ValueError("Unknown ranking provider selection")


def _is_integer(value: object) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def _validate_provider_output(
    output: Any, candidates: list, eligible: list, selector: str
) -> list:
    """Validate the provider result as a whole before any serialization.

    The identity of the result must equal the identity of the profile the
    request selected, so a result from another profile can never be passed
    off as the selected one. Every eligible candidate must appear exactly
    once with a finite in-range score, a contiguous semantic rank and an
    ordering that follows the quantized-unit rule. Any missing, extra,
    duplicate, unknown or malformed member rejects the whole result; nothing
    is partially emitted.
    """
    identity = profile(selector)

    if (
        output.model_id != identity["model_id"]
        or output.model_revision != identity["model_revision"]
        or output.provider_name != identity["provider_name"]
    ):
        raise ValueError("Provider identity does not match the selected profile")

    recommendations = list(output.recommendations)
    eligible_indexes = [candidate["index"] for candidate in eligible]
    if len(recommendations) != len(eligible_indexes):
        raise ValueError("Provider must return one recommendation per eligible candidate")

    by_index = {candidate["index"]: candidate for candidate in candidates}
    eligible_set = set(eligible_indexes)

    seen: set[int] = set()
    previous_units = None
    previous_m4_rank = None
    ordered: list[tuple[dict, float]] = []

    for position, recommendation in enumerate(recommendations):
        index = recommendation.m4_candidate_index
        if not _is_integer(index) or index not in eligible_set:
            raise ValueError("Provider returned an unknown candidate reference")
        if index in seen:
            raise ValueError("Provider returned a duplicate candidate reference")
        seen.add(index)

        score = recommendation.semantic_score
        if isinstance(score, bool) or not isinstance(score, (int, float)):
            raise ValueError("Provider score must be numeric")
        score = float(score)
        if not math.isfinite(score) or score < 0.0 or score > 1.0:
            raise ValueError("Provider score must be finite and inside [0,1]")

        semantic_rank = recommendation.combined_rank
        if not _is_integer(semantic_rank) or semantic_rank != position + 1:
            raise ValueError("Provider semantic ranks must be contiguous from 1")

        units = math.floor(score * SCORE_UNITS + 0.5)
        m4_rank = by_index[index]["m4_rank"]
        if previous_units is not None:
            if units > previous_units:
                raise ValueError("Provider output is not ordered by descending units")
            if units == previous_units and m4_rank <= previous_m4_rank:
                raise ValueError("Equal units must be ordered by ascending M4 rank")
        previous_units = units
        previous_m4_rank = m4_rank

        ordered.append((by_index[index], score))

    if seen != eligible_set:
        raise ValueError("Provider output does not cover every eligible candidate")

    return ordered


def _build_recommendations(candidates: list, ordered: list) -> list:
    """Build exactly K recommendation objects in the specified order.

    Scored entries come first in provider ranking order, then unscored
    entries ascending by candidate index. Every index occurs exactly once.
    """
    recommendations = []

    for position, (candidate, score) in enumerate(ordered):
        recommendations.append(
            {
                "m4_candidate_index": candidate["index"],
                "start_ms": candidate["start_ms"],
                "end_ms": candidate["end_ms"],
                "m4_rank": candidate["m4_rank"],
                "m4_score": candidate["m4_score"],
                "semantic_score": score,
                "semantic_rank": position + 1,
                "reason": None,
            }
        )

    for candidate in candidates:
        if candidate["transcript_text"] != "":
            continue
        recommendations.append(
            {
                "m4_candidate_index": candidate["index"],
                "start_ms": candidate["start_ms"],
                "end_ms": candidate["end_ms"],
                "m4_rank": candidate["m4_rank"],
                "m4_score": candidate["m4_score"],
                "semantic_score": None,
                "semantic_rank": None,
                "reason": UNSCORED_REASON,
            }
        )

    if len(recommendations) != len(candidates):
        raise ValueError("Recommendation count must equal the candidate count")

    return recommendations


def _build_parameters(configuration: dict, transcript_used: bool) -> dict:
    """Exactly the request configuration minus algorithm/algorithm_version,
    plus the profile identity and the two truthful flags."""
    parameters = {
        key: value
        for key, value in configuration.items()
        if key not in _ALGORITHM_KEYS
    }

    identity = profile(configuration["provider"])
    parameters["provider_name"] = identity["provider_name"]
    parameters["inference_performed"] = identity["inference_performed"]
    parameters["transcript_used"] = transcript_used

    expected_keys = [key for key in configuration if key not in _ALGORITHM_KEYS]
    expected_keys.extend(_PARAMETER_IDENTITY_KEYS)
    if set(parameters.keys()) != set(expected_keys):
        raise ValueError("Unexpected parameter key set")

    return parameters


def rank_clips(contract: dict, request_sha256: str) -> dict[str, Any]:
    """Rank clips from a validated contract.

    `request_sha256` is the digest of the exact raw transport bytes of this
    invocation; it is echoed verbatim so Laravel can bind the result to the
    request it sent.
    """
    # ------------------------------------------------------------------
    # Contract, selection and provider construction (invalid_contract)
    # ------------------------------------------------------------------
    try:
        if not isinstance(contract, dict):
            return error("invalid_contract")

        if contract.get("action") != "rank_clips":
            return error("invalid_contract")

        # Shared strict validation: packaged schema and runtime checks agree.
        try:
            is_valid, _reason = validate_contract(contract)
        except ContractSchemaUnavailable:
            # The packaged schema itself is unavailable: an environment
            # failure, never a malformed request.
            return error("ranking_failed")
        if not is_valid:
            return error("invalid_contract")

        if not _is_digest(request_sha256):
            return error("invalid_contract")

        configuration = contract["configuration"]
        candidates = contract["candidates"]
        provider = _select_provider(configuration["provider"])
    except Exception:
        # Phase 1 performs no provider execution: every failure here is a
        # contract, selection or construction problem, never a runtime
        # inference failure.
        return error("invalid_contract")

    # ------------------------------------------------------------------
    # Provider execution (ranking_failed)
    # ------------------------------------------------------------------
    try:
        eligible = [
            candidate for candidate in candidates if candidate["transcript_text"] != ""
        ]
        if not eligible:
            # Contract validation guarantees at least one usable candidate;
            # this keeps the boundary closed if that invariant ever drifts.
            return error("invalid_contract")

        ranking_input = RankingInput(
            candidates=eligible,
            prototype_query=configuration["prototype_query"],
        )

        output = provider.rank(ranking_input)
        ordered = _validate_provider_output(
            output, candidates, eligible, configuration["provider"]
        )
        recommendations = _build_recommendations(candidates, ordered)

        # transcript_used is derived from the validated request, not from the
        # provider, so Laravel rederives the same value independently.
        transcript_used = any(
            candidate["transcript_text"] != "" for candidate in candidates
        )

        parameters = _build_parameters(configuration, transcript_used)

        return {
            "status": "success",
            "ranking": {
                "algorithm": configuration["algorithm"],
                "algorithm_version": configuration["algorithm_version"],
                "parameters": parameters,
                "request_sha256": request_sha256,
                "recommendations": recommendations,
            },
        }
    except Exception:
        # Sanitized runtime/output failure: no ranking payload, no library
        # message, no fabricated fallback result.
        return error("ranking_failed")


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
        result = error("ranking_failed")
        output = json.dumps(result, allow_nan=False, separators=(",", ":"))

    sys.stdout.write(output)

    if result.get("status") == "success":
        return 0
    if result.get("code") == "invalid_contract":
        return 2
    return 1


def run_cli(argv: list[str]) -> int:
    """CLI entry point for the rank-clips subcommand.

    The transcript-bearing request is accepted from stdin only; argument-list
    and file transports are rejected without echoing their payload.
    """
    try:
        parser = _Parser(prog="aiclip_worker rank-clips", add_help=False)
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
        result = rank_clips(contract, request_sha256)
    except Exception:
        result = error("ranking_failed")

    return _emit(result)
